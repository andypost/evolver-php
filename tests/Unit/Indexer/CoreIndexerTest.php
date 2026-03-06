<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Indexer;

use DrupalEvolver\Indexer\CoreIndexer;
use DrupalEvolver\Storage\DatabaseApi;
use DrupalEvolver\TreeSitter\Parser;
use PHPUnit\Framework\TestCase;

class CoreIndexerTest extends TestCase
{
    public function testReindexReplacesSymbolsForChangedFile(): void
    {
        $parser = $this->createParserOrSkip();
        $api = new DatabaseApi(':memory:');
        $indexer = new CoreIndexer($parser, $api);
        $indexer->setWorkerCount(1);
        $indexer->setStoreAst(false);

        $projectDir = $this->createTempDir('evolver-indexer-');
        $filePath = $projectDir . '/sample.php';
        file_put_contents($filePath, "<?php\nfunction old_func(): void {}\n");

        try {
            $indexer->index($projectDir, '10.2.0');

            file_put_contents($filePath, "<?php\nfunction new_func(): void {}\n");
            $indexer->index($projectDir, '10.2.0');

            $version = $api->versions()->findByTag('10.2.0');
            $this->assertNotNull($version);
            $versionId = (int) $version['id'];

            $this->assertNull($api->symbols()->findByFqn($versionId, 'old_func'));
            $this->assertNotNull($api->symbols()->findByFqn($versionId, 'new_func'));
            $this->assertSame(1, $api->symbols()->countByVersion($versionId));
        } finally {
            $this->removeDir($projectDir);
        }
    }

    public function testIndexKeepsDifferentPathsWithSameHash(): void
    {
        $parser = $this->createParserOrSkip();
        $api = new DatabaseApi(':memory:');
        $indexer = new CoreIndexer($parser, $api);
        $indexer->setWorkerCount(1);
        $indexer->setStoreAst(false);

        $projectDir = $this->createTempDir('evolver-indexer-hash-');
        $sharedContent = "<?php\n// identical file contents\n";
        file_put_contents($projectDir . '/alpha.php', $sharedContent);
        file_put_contents($projectDir . '/beta.php', $sharedContent);

        try {
            $indexer->index($projectDir, '10.2.0');

            $version = $api->versions()->findByTag('10.2.0');
            $this->assertNotNull($version);
            $versionId = (int) $version['id'];

            $this->assertNotNull($api->files()->findByPath($versionId, 'alpha.php'));
            $this->assertNotNull($api->files()->findByPath($versionId, 'beta.php'));

            $fileCount = (int) $api->db()->query(
                'SELECT COUNT(*) AS cnt FROM parsed_files WHERE version_id = :version_id',
                ['version_id' => $versionId]
            )->fetch()['cnt'];
            $this->assertSame(2, $fileCount);
        } finally {
            $this->removeDir($projectDir);
        }
    }

    private function createParserOrSkip(): Parser
    {
        if (!extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is not loaded');
        }

        putenv('EVOLVER_USE_CLI=0');
        putenv('EVOLVER_GRAMMAR_PATH=/usr/lib');

        try {
            return new Parser();
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('Tree-sitter parser unavailable: ' . $e->getMessage());
        }
    }

    private function createTempDir(string $prefix): string
    {
        $dir = rtrim(sys_get_temp_dir(), '/') . '/' . $prefix . uniqid('', true);
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
