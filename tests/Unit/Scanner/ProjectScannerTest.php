<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Scanner;

use DrupalEvolver\Scanner\MatchCollector;
use DrupalEvolver\Scanner\ProjectScanner;
use DrupalEvolver\Storage\DatabaseApi;
use DrupalEvolver\TreeSitter\Parser;
use PHPUnit\Framework\TestCase;

final class ProjectScannerTest extends TestCase
{
    public function testModernizationSuggestions(): void
    {
        $api = new DatabaseApi(':memory:');
        $parser = $this->createParserOrSkip();
        $matchCollector = new MatchCollector($parser->binding(), $parser->registry());

        $scanner = new ProjectScanner($parser, $api, $matchCollector);
        
        $fromId = $api->versions()->create('11.4.0', 11, 4, 0);
        $toId = $api->versions()->create('12.0.0', 12, 0, 0);

        (void) $api->changes()->create([
            'from_version_id' => $fromId,
            'to_version_id' => $toId,
            'language' => 'php',
            'change_type' => 'function_removed',
            'old_fqn' => 'non_existent_dummy',
        ]);

        $tempDir = $this->createTempDir('scanner-modern-');
        $moduleDir = $tempDir . '/modules/my_module';
        mkdir($moduleDir, 0777, true);
        file_put_contents($moduleDir . '/my_module.module', "<?php\nfunction my_module_user_login() {}");
        mkdir($moduleDir . '/src/Plugin/Block', 0777, true);
        file_put_contents($moduleDir . '/src/Plugin/Block/MyBlock.php', "<?php\n/**\n * @Block(id=\"my_block\")\n */\nclass MyBlock {}");

        try {
            $projectId = $api->projects()->save('my_module', $tempDir, 'module', '11.4.0', 'local_path');
            $runId = $api->scanRuns()->create($projectId, 'my_module', null, $tempDir, '11.4.0', '12.0.0');

            (void) $scanner->scanIntoProject($projectId, $runId, $tempDir, '12.0.0', '11.4.0');

            $matches = $api->matches()->findByRun($runId);
            $this->assertCount(2, $matches);

            $types = array_column($matches, 'change_type');
            $this->assertContains('procedural_to_attribute', $types);
            $this->assertContains('annotation_to_attribute', $types);
        } finally {
            $this->removeDir($tempDir);
        }
    }

    private function createParserOrSkip(): Parser
    {
        if (!extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is not loaded');
        }
        putenv('EVOLVER_GRAMMAR_PATH=/usr/lib');
        return new Parser();
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
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
