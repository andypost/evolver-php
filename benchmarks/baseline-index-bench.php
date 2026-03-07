#!/usr/bin/env php
<?php
/**
 * Baseline benchmark: Current pcntl_fork indexing pipeline.
 *
 * Measures wall time, peak memory, and RSS for indexing the evolver src/ codebase
 * using the existing pcntl_fork approach with 1, 2, and 4 workers.
 *
 * Run inside Docker:
 *   docker compose exec evolver php benchmarks/baseline-index-bench.php [path] [workers_csv] [output_json]
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use DrupalEvolver\Indexer\CoreIndexer;
use DrupalEvolver\Storage\DatabaseApi;
use DrupalEvolver\TreeSitter\Parser;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function banner(string $title): void
{
    echo "\n" . str_repeat('=', 70) . "\n";
    echo "  {$title}\n";
    echo str_repeat('=', 70) . "\n";
}

function getRssKb(): int
{
    if (is_readable('/proc/self/status')) {
        $status = file_get_contents('/proc/self/status');
        if (preg_match('/VmRSS:\s+(\d+)\s+kB/', $status, $m)) {
            return (int) $m[1];
        }
    }
    return 0;
}

function formatMem(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
}

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

$indexPath = $argv[1] ?? '/app/src';
$workerCounts = isset($argv[2]) ? array_map('intval', explode(',', $argv[2])) : [1, 2, 4];
$outputFile = $argv[3] ?? null;
$tag = '0.0.1';
$iterations = 3;

banner('Baseline Index Benchmark');
echo "  PHP:         " . PHP_VERSION . "\n";
echo "  Index path:  {$indexPath}\n";
echo "  Workers:     " . implode(', ', $workerCounts) . "\n";
echo "  Iterations:  {$iterations} per worker count\n";
echo "  pcntl:       " . (function_exists('pcntl_fork') ? 'YES' : 'NO') . "\n";

// Count files to index
$fileCount = 0;
$ri = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($indexPath, FilesystemIterator::SKIP_DOTS),
        fn(\SplFileInfo $f) => $f->isDir() ? !in_array($f->getFilename(), ['vendor', 'node_modules', '.git']) : true
    ),
    RecursiveIteratorIterator::LEAVES_ONLY
);
foreach ($ri as $f) {
    $ext = strtolower(pathinfo($f->getPathname(), PATHINFO_EXTENSION));
    if (in_array($ext, ['php', 'module', 'inc', 'install', 'profile', 'theme', 'yml', 'yaml', 'js', 'mjs', 'css'])) {
        $fileCount++;
    }
}
echo "  Files:       {$fileCount}\n";

// ---------------------------------------------------------------------------
// Benchmark runs
// ---------------------------------------------------------------------------

$results = [];

foreach ($workerCounts as $workers) {
    banner("pcntl_fork — {$workers} worker(s)");

    $timings = [];
    $peakMems = [];
    $rssValues = [];

    for ($i = 0; $i < $iterations; $i++) {
        // Fresh database each run
        $dbPath = "/tmp/bench_baseline_{$workers}_{$i}.sqlite";
        @unlink($dbPath);
        @unlink("{$dbPath}-wal");
        @unlink("{$dbPath}-shm");

        $rssBefore = getRssKb();
        $memBefore = memory_get_usage(true);

        $api = new DatabaseApi($dbPath);
        $parser = new Parser();
        $indexer = new CoreIndexer($parser, $api);
        $indexer->setWorkerCount($workers);
        $indexer->setStoreAst(false); // Skip AST storage for fair comparison

        $start = hrtime(true);
        $indexer->index($indexPath, $tag);
        $elapsed = (hrtime(true) - $start) / 1e9;

        $peak = memory_get_peak_usage(true);
        $rssAfter = getRssKb();

        $timings[] = $elapsed;
        $peakMems[] = $peak;
        $rssValues[] = $rssAfter;

        printf("  Run %d: %.3fs  peak=%s  rss=%d KB  rss_delta=%+d KB\n",
            $i + 1, $elapsed, formatMem($peak), $rssAfter, $rssAfter - $rssBefore);

        // Verify
        $stats = $api->getStats();
        $symCount = $stats['symbol_count'];
        printf("         symbols=%d  versions=%d\n", $symCount, count($stats['versions']));

        // Cleanup
        unset($api, $parser, $indexer);
        @unlink($dbPath);
        @unlink("{$dbPath}-wal");
        @unlink("{$dbPath}-shm");
        gc_collect_cycles();
    }

    $avgTime = array_sum($timings) / count($timings);
    $minTime = min($timings);
    $maxTime = max($timings);
    $avgRss = array_sum($rssValues) / count($rssValues);
    $maxPeak = max($peakMems);

    printf("\n  Summary (%d workers):\n", $workers);
    printf("    Time:  avg=%.3fs  min=%.3fs  max=%.3fs\n", $avgTime, $minTime, $maxTime);
    printf("    Peak:  %s\n", formatMem($maxPeak));
    printf("    RSS:   avg=%d KB\n", (int) $avgRss);
    printf("    Throughput: %.1f files/sec\n", $fileCount / $avgTime);

    $results[] = [
        'method' => 'pcntl_fork',
        'workers' => $workers,
        'avg_time' => $avgTime,
        'min_time' => $minTime,
        'max_time' => $maxTime,
        'peak_mem' => $maxPeak,
        'avg_rss' => (int) $avgRss,
        'throughput' => $fileCount / $avgTime,
    ];
}

// ---------------------------------------------------------------------------
// Summary table
// ---------------------------------------------------------------------------

banner('Results Summary');
printf("  %-15s %8s %10s %10s %10s %12s\n", 'Method', 'Workers', 'Avg Time', 'Peak Mem', 'Avg RSS', 'Files/sec');
printf("  %-15s %8s %10s %10s %10s %12s\n",
    str_repeat('-', 15), str_repeat('-', 8), str_repeat('-', 10),
    str_repeat('-', 10), str_repeat('-', 10), str_repeat('-', 12));

foreach ($results as $r) {
    printf("  %-15s %8d %9.3fs %10s %8d KB %10.1f\n",
        $r['method'], $r['workers'], $r['avg_time'],
        formatMem($r['peak_mem']), $r['avg_rss'], $r['throughput']);
}

if (is_string($outputFile) && $outputFile !== '') {
    file_put_contents($outputFile, json_encode($results, JSON_PRETTY_PRINT) . "\n");
    echo "\n  Results saved to: {$outputFile}\n";
}
