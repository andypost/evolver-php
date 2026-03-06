#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use DrupalEvolver\Indexer\CoreIndexer;
use DrupalEvolver\Storage\Database;
use DrupalEvolver\Storage\Repository\FileRepo;
use DrupalEvolver\Storage\Repository\SymbolRepo;
use DrupalEvolver\Storage\Repository\VersionRepo;
use DrupalEvolver\Storage\Schema;
use DrupalEvolver\TreeSitter\Parser;

if (!extension_loaded('meminfo')) {
    fwrite(STDERR, "meminfo extension is not loaded. Use php85 -d extension=/usr/lib/php85/modules/meminfo.so.\n");
    exit(2);
}

if (!function_exists('meminfo_dump')) {
    fwrite(STDERR, "meminfo_dump() is unavailable in this extension build.\n");
    exit(2);
}

$outDir = $argv[1] ?? '/app/.data/profiles/meminfo-leak';
$sourcePath = $argv[2] ?? '/drupal/core/modules/user';
$iterations = isset($argv[3]) ? max(1, (int) $argv[3]) : 5;
$dumpEvery = isset($argv[4]) ? max(0, (int) $argv[4]) : 0;
$warmupIterations = isset($argv[5]) ? max(0, (int) $argv[5]) : 2;

if (!is_dir($sourcePath)) {
    fwrite(STDERR, "Source path not found: {$sourcePath}\n");
    exit(1);
}

if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Unable to create output directory: {$outDir}\n");
    exit(1);
}

gc_enable();

$parser = new Parser();
$samplesLogPath = rtrim($outDir, '/') . '/samples.ndjson';
$samplesLog = fopen($samplesLogPath, 'w');
if ($samplesLog === false) {
    fwrite(STDERR, "Unable to open samples log for writing: {$samplesLogPath}\n");
    exit(1);
}

$baselineReportPath = dumpMeminfoReport($outDir, 'baseline');
$baseline = captureRuntimeSample('baseline', $baselineReportPath);
$baseline['iteration'] = 0;
$baseline['elapsed_ms'] = 0.0;
$baseline['gc_collected_cycles'] = 0;
writeSampleLogLine($samplesLog, $baseline);

$baseMem = (int) $baseline['memory_usage_real'];
$baseMemNonReal = (int) $baseline['memory_usage'];
$maxMem = $baseMem;
$maxMemNonReal = $baseMemNonReal;
$endMem = $baseMem;
$endMemNonReal = $baseMemNonReal;
$finalSample = $baseline;
$warmupMem = $baseMem;
$warmupMemNonReal = $baseMemNonReal;
$warmupIterationUsed = 0;

echo "Baseline memory: {$baseline['memory_usage_real']} bytes\n";

for ($i = 1; $i <= $iterations; $i++) {
    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }

    $db = new Database(':memory:');
    (new Schema($db))->createAll();

    $versionRepo = new VersionRepo($db);
    $fileRepo = new FileRepo($db);
    $symbolRepo = new SymbolRepo($db);
    $indexer = new CoreIndexer($parser, $db, $versionRepo, $fileRepo, $symbolRepo);

    $tag = sprintf('99.9.%d', $i);
    $start = hrtime(true);
    $indexer->index($sourcePath, $tag, null);
    $elapsedMs = round((hrtime(true) - $start) / 1_000_000, 3);

    unset($indexer, $versionRepo, $fileRepo, $symbolRepo, $db);

    $collectedCycles = gc_collect_cycles();
    $iterReportPath = null;
    if ($dumpEvery > 0 && ($i % $dumpEvery) === 0) {
        $iterReportPath = dumpMeminfoReport($outDir, "iter_{$i}");
    }

    $sample = captureRuntimeSample("iter_{$i}", $iterReportPath);
    $sample['iteration'] = $i;
    $sample['elapsed_ms'] = $elapsedMs;
    $sample['gc_collected_cycles'] = $collectedCycles;
    writeSampleLogLine($samplesLog, $sample);

    printf(
        "iter=%d elapsed_ms=%.3f mem=%d peak=%d gc_cycles=%d\n",
        $i,
        $elapsedMs,
        $sample['memory_usage_real'],
        $sample['peak_memory_usage_real'],
        $collectedCycles
    );

    $mem = (int) ($sample['memory_usage_real'] ?? 0);
    if ($mem > $maxMem) {
        $maxMem = $mem;
    }
    $endMem = $mem;

    $memNonReal = (int) ($sample['memory_usage'] ?? 0);
    if ($memNonReal > $maxMemNonReal) {
        $maxMemNonReal = $memNonReal;
    }
    $endMemNonReal = $memNonReal;
    $finalSample = $sample;
    if ($warmupIterations > 0 && $i === $warmupIterations) {
        $warmupMem = $mem;
        $warmupMemNonReal = $memNonReal;
        $warmupIterationUsed = $i;
    }

    unset($sample);
}

fclose($samplesLog);

$finalReportPath = dumpMeminfoReport($outDir, 'final');
$finalDumpSample = captureRuntimeSample('final', $finalReportPath);

$growth = $endMem - $baseMem;
$growthPct = $baseMem > 0 ? round(($growth / $baseMem) * 100, 2) : 0.0;
$growthNonReal = $endMemNonReal - $baseMemNonReal;
$growthPctNonReal = $baseMemNonReal > 0 ? round(($growthNonReal / $baseMemNonReal) * 100, 2) : 0.0;
if ($warmupIterations > 0 && $warmupIterationUsed === 0) {
    $warmupMem = $endMem;
    $warmupMemNonReal = $endMemNonReal;
    $warmupIterationUsed = $iterations;
}
$postWarmupGrowth = $endMem - $warmupMem;
$postWarmupGrowthPct = $warmupMem > 0 ? round(($postWarmupGrowth / $warmupMem) * 100, 2) : 0.0;
$postWarmupGrowthNonReal = $endMemNonReal - $warmupMemNonReal;
$postWarmupGrowthPctNonReal = $warmupMemNonReal > 0 ? round(($postWarmupGrowthNonReal / $warmupMemNonReal) * 100, 2) : 0.0;

$summary = [
    'path' => realpath($sourcePath) ?: $sourcePath,
    'iterations' => $iterations,
    'baseline_memory_real' => $baseMem,
    'final_memory_real' => $endMem,
    'max_memory_real' => $maxMem,
    'growth_bytes' => $growth,
    'growth_percent' => $growthPct,
    'baseline_memory' => $baseMemNonReal,
    'final_memory' => $endMemNonReal,
    'max_memory' => $maxMemNonReal,
    'growth_bytes_memory' => $growthNonReal,
    'growth_percent_memory' => $growthPctNonReal,
    'warmup_iterations' => $warmupIterations,
    'warmup_iteration_used' => $warmupIterationUsed,
    'post_warmup_baseline_memory_real' => $warmupMem,
    'post_warmup_baseline_memory' => $warmupMemNonReal,
    'post_warmup_growth_bytes' => $postWarmupGrowth,
    'post_warmup_growth_percent' => $postWarmupGrowthPct,
    'post_warmup_growth_bytes_memory' => $postWarmupGrowthNonReal,
    'post_warmup_growth_percent_memory' => $postWarmupGrowthPctNonReal,
    'dump_every' => $dumpEvery,
    'baseline_report' => $baselineReportPath,
    'final_report' => $finalReportPath,
    'final_dump_sample' => $finalDumpSample,
    'baseline_sample' => $baseline,
    'final_sample' => $finalSample,
    'samples_log' => $samplesLogPath,
];

$summaryJson = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($summaryJson === false) {
    fwrite(STDERR, "Failed to encode meminfo leak summary.\n");
    exit(1);
}

$summaryPath = rtrim($outDir, '/') . '/summary.json';
if (file_put_contents($summaryPath, $summaryJson) === false) {
    fwrite(STDERR, "Failed to write summary: {$summaryPath}\n");
    exit(1);
}

echo "Summary written: {$summaryPath}\n";
echo sprintf(
    "Leak signal (real): baseline=%d final=%d growth=%d bytes (%.2f%%)\n",
    $baseMem,
    $endMem,
    $growth,
    $growthPct
);
echo sprintf(
    "Leak signal (logical): baseline=%d final=%d growth=%d bytes (%.2f%%)\n",
    $baseMemNonReal,
    $endMemNonReal,
    $growthNonReal,
    $growthPctNonReal
);
echo sprintf(
    "Leak signal after warmup[%d] (real): baseline=%d final=%d growth=%d bytes (%.2f%%)\n",
    $warmupIterationUsed,
    $warmupMem,
    $endMem,
    $postWarmupGrowth,
    $postWarmupGrowthPct
);
echo sprintf(
    "Leak signal after warmup[%d] (logical): baseline=%d final=%d growth=%d bytes (%.2f%%)\n",
    $warmupIterationUsed,
    $warmupMemNonReal,
    $endMemNonReal,
    $postWarmupGrowthNonReal,
    $postWarmupGrowthPctNonReal
);

/**
 * @return array<string, int|string|null>
 */
function captureRuntimeSample(string $name, ?string $reportPath): array
{
    return [
        'name' => $name,
        'report_path' => $reportPath,
        'memory_usage' => memory_get_usage(false),
        'memory_usage_real' => memory_get_usage(true),
        'peak_memory_usage' => memory_get_peak_usage(false),
        'peak_memory_usage_real' => memory_get_peak_usage(true),
    ];
}

function dumpMeminfoReport(string $outDir, string $name): string
{
    $reportPath = rtrim($outDir, '/') . '/' . $name . '.json';
    $stream = fopen($reportPath, 'w');
    if ($stream === false) {
        throw new RuntimeException("Unable to open meminfo report file: {$reportPath}");
    }
    meminfo_dump($stream);
    fflush($stream);
    fclose($stream);

    return $reportPath;
}

/**
 * @param resource $stream
 * @param array<string, int|float|string|null> $sample
 */
function writeSampleLogLine($stream, array $sample): void
{
    $line = json_encode($sample, JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        throw new RuntimeException('Failed to encode sample for NDJSON log.');
    }

    fwrite($stream, $line . PHP_EOL);
}
