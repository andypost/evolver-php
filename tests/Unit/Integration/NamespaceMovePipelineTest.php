<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Integration;

use DrupalEvolver\Applier\TemplateApplier;
use DrupalEvolver\Differ\FixTemplateGenerator;
use DrupalEvolver\Differ\RenameMatcher;
use DrupalEvolver\Differ\SignatureDiffer;
use DrupalEvolver\Differ\VersionDiffer;
use DrupalEvolver\Differ\YAMLDiffer;
use DrupalEvolver\Pattern\QueryGenerator;
use DrupalEvolver\Scanner\MatchCollector;
use DrupalEvolver\Scanner\ProjectScanner;
use DrupalEvolver\Storage\DatabaseApi;
use DrupalEvolver\TreeSitter\LanguageRegistry;
use DrupalEvolver\TreeSitter\Parser;
use PHPUnit\Framework\TestCase;

class NamespaceMovePipelineTest extends TestCase
{
    public function testDiffScanApplyPipelineRewritesNamespaceMoveReferences(): void
    {
        $api = new DatabaseApi(':memory:');

        $fromId = $api->versions()->create('10.2.0', 10, 2, 0);
        $toId = $api->versions()->create('10.3.0', 10, 3, 0);
        $oldFileId = $api->files()->create($fromId, 'core/lib/Old.php', 'php', 'old-hash', null, null, 1, 1);
        $newFileId = $api->files()->create($toId, 'core/lib/New.php', 'php', 'new-hash', null, null, 1, 1);

        $oldSymbolId = $api->symbols()->create([
            'version_id' => $fromId,
            'file_id' => $oldFileId,
            'language' => 'php',
            'symbol_type' => 'class',
            'fqn' => 'Drupal\\Core\\Old\\MovedClass',
            'name' => 'MovedClass',
            'signature_hash' => 'class-old-hash',
            'signature_json' => '{"parent":null,"interfaces":null}',
            'source_text' => 'class MovedClass {}',
        ]);
        $this->assertGreaterThan(0, $oldSymbolId);

        $newSymbolId = $api->symbols()->create([
            'version_id' => $toId,
            'file_id' => $newFileId,
            'language' => 'php',
            'symbol_type' => 'class',
            'fqn' => 'Drupal\\Core\\New\\MovedClass',
            'name' => 'MovedClass',
            'signature_hash' => 'class-new-hash',
            'signature_json' => '{"parent":null,"interfaces":null}',
            'source_text' => 'class MovedClass {}',
        ]);
        $this->assertGreaterThan(0, $newSymbolId);

        $differ = new VersionDiffer(
            $api,
            new SignatureDiffer(),
            new RenameMatcher(),
            new YAMLDiffer(),
            new FixTemplateGenerator(),
            new QueryGenerator(),
        );
        $changes = $differ->diff('10.2.0', '10.3.0');

        $classRename = $this->findChange($changes, 'class_renamed', 'Drupal\\Core\\Old\\MovedClass');
        $this->assertNotNull($classRename);
        $template = json_decode((string) ($classRename['fix_template'] ?? ''), true);
        $this->assertSame('namespace_move', $template['type'] ?? null);

        $parser = $this->createParserOrSkip();
        $binding = $parser->binding();
        if ($binding === null) {
            $this->markTestSkipped('FFI parser binding is unavailable.');
        }

        // Validate parser+grammar availability up-front so scanner failures are actionable.
        try {
            $validationTree = $parser->parse("<?php\nuse Drupal\\Core\\Old\\MovedClass;\n", 'php');
            $this->assertNotNull($validationTree);
            (new LanguageRegistry())->loadLanguage('php');
        } catch (\Throwable $e) {
            $this->markTestSkipped('Tree-sitter parser/grammar unavailable: ' . $e->getMessage());
        }

        $projectDir = $this->createTempDir('evolver-ns-move-');
        $projectFile = $projectDir . '/module.php';
        file_put_contents($projectFile, <<<'PHP'
<?php

use Drupal\Core\Old\MovedClass;

$fromUse = new MovedClass();
$fqn = \Drupal\Core\Old\MovedClass::class;
PHP);

        try {
            $scanner = new ProjectScanner(
                $parser,
                $api,
                new MatchCollector($binding, new LanguageRegistry()),
            );
            $scanner->scan($projectDir, '10.3.0', '10.2.0');

            $project = $api->projects()->findByPath($projectDir);
            $this->assertNotNull($project);
            $projectId = (int) $project['id'];

            $pending = $api->matches()->findPending($projectId);
            $this->assertNotEmpty($pending);
            $templatePending = array_values(array_filter(
                $pending,
                static fn(array $match): bool => ($match['fix_method'] ?? '') === 'template'
            ));
            $this->assertNotEmpty($templatePending);

            $applier = new TemplateApplier($api);
            $stats = $applier->applyWithStats($projectId, $projectDir, false, false);

            $this->assertGreaterThanOrEqual(1, $stats['applied']);

            $updated = file_get_contents($projectFile);
            $this->assertIsString($updated);
            $this->assertStringContainsString('use Drupal\\Core\\New\\MovedClass;', $updated);
            $this->assertStringContainsString('\\Drupal\\Core\\New\\MovedClass::class', $updated);
            $this->assertStringNotContainsString('Drupal\\Core\\Old\\MovedClass', $updated);
        } finally {
            $this->removeDir($projectDir);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $changes
     */
    private function findChange(array $changes, string $type, string $oldFqn): ?array
    {
        foreach ($changes as $change) {
            if (($change['change_type'] ?? null) === $type && ($change['old_fqn'] ?? null) === $oldFqn) {
                return $change;
            }
        }

        return null;
    }

    private function createParserOrSkip(): Parser
    {
        try {
            return new Parser();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Tree-sitter parser unavailable: ' . $e->getMessage());
        }
    }

    private function createTempDir(string $prefix): string
    {
        $base = rtrim(sys_get_temp_dir(), '/');
        $dir = $base . '/' . $prefix . uniqid('', true);
        mkdir($dir, 0777, true);
        return $dir;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
                continue;
            }
            @unlink($path);
        }

        @rmdir($dir);
    }
}
