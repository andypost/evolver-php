<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Performance;

use DrupalEvolver\Indexer\CoreIndexer;
use DrupalEvolver\Storage\DatabaseApi;
use DrupalEvolver\TreeSitter\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

class IndexingPerformanceTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/perf_test_' . uniqid() . '.sqlite';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            @unlink($this->dbPath);
            @unlink($this->dbPath . '-wal');
            @unlink($this->dbPath . '-shm');
        }
    }

    /**
     * Ensures indexing speed doesn't regress significantly.
     */
    public function testIndexingSpeedRegression(): void
    {
        // Use evolver's own src directory as a test set
        $indexPath = dirname(__DIR__, 2) . '/src';
        $tag = '1.0.0';
        
        $api = new DatabaseApi($this->dbPath);
        $parser = new Parser();
        $indexer = new CoreIndexer($parser, $api);
        
        // Use 4 workers for performance testing
        $indexer->setWorkerCount(4);
        $indexer->setStoreAst(false);

        $start = hrtime(true);
        $indexer->index($indexPath, $tag, new NullOutput());
        $elapsed = (hrtime(true) - $start) / 1e9;

        $stats = $api->getStats();
        $fileCount = (int) ($stats['file_count'] ?? 0);
        
        $this->assertGreaterThan(0, $fileCount, "Should have indexed some files");
        
        $throughput = $fileCount / $elapsed;
        
        // Assert a reasonable minimum throughput. 
        // 200 files/sec is a safe floor for 4 workers on almost any environment.
        $this->assertGreaterThan(200, $throughput, sprintf(
            "Performance regression detected! Throughput was %.2f files/sec (Expected > 200)",
            $throughput
        ));
    }
}
