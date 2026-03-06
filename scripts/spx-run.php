#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use DrupalEvolver\Command\ApplyCommand;
use DrupalEvolver\Command\DiffCommand;
use DrupalEvolver\Command\IndexCommand;
use DrupalEvolver\Command\QueryCommand;
use DrupalEvolver\Command\ReportCommand;
use DrupalEvolver\Command\ScanCommand;
use DrupalEvolver\Command\StatusCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

if (!extension_loaded('SPX') && !extension_loaded('spx')) {
    fwrite(STDERR, "SPX extension is not loaded. Use php85 -d extension=/usr/lib/php85/modules/spx.so.\n");
    exit(2);
}

if (!function_exists('spx_profiler_start') || !function_exists('spx_profiler_stop')) {
    fwrite(STDERR, "SPX profiler APIs are unavailable in this extension build.\n");
    exit(2);
}

if ($argc < 3) {
    fwrite(STDERR, "Usage: php scripts/spx-run.php <report-path> <evolver-command...>\n");
    exit(1);
}

$reportPath = $argv[1];
$commandArgs = array_slice($argv, 2);
$reportDir = dirname($reportPath);

if (!is_dir($reportDir) && !mkdir($reportDir, 0775, true) && !is_dir($reportDir)) {
    fwrite(STDERR, "Unable to create report directory: {$reportDir}\n");
    exit(1);
}

$errors = [];
set_error_handler(static function (int $severity, string $message) use (&$errors): bool {
    $errors[] = [
        'severity' => $severity,
        'message' => $message,
    ];
    return true;
});

$wallStart = hrtime(true);
$startReturn = spx_profiler_start();

$application = new Application('DrupalEvolver', '0.1.0');
$application->add(new IndexCommand());
$application->add(new DiffCommand());
$application->add(new ScanCommand());
$application->add(new ApplyCommand());
$application->add(new ReportCommand());
$application->add(new StatusCommand());
$application->add(new QueryCommand());
$application->setAutoExit(false);

$input = new ArgvInput(array_merge(['bin/evolver'], $commandArgs));
$exitCode = $application->run($input, new ConsoleOutput());

$stopReturn = spx_profiler_stop();
$wallEnd = hrtime(true);

restore_error_handler();

$spxDataDir = (string) ini_get('spx.data_dir');
$reportKey = is_string($stopReturn) ? $stopReturn : null;
$fullJsonPath = null;
$fullTxtGzPath = null;

if ($reportKey !== null && $spxDataDir !== '') {
    $base = rtrim($spxDataDir, '/');
    $fullJsonPath = $base . '/' . $reportKey . '.json';
    $fullTxtGzPath = $base . '/' . $reportKey . '.txt.gz';
}

$profilingEnabled = true;
foreach ($errors as $error) {
    $message = (string) ($error['message'] ?? '');
    if (str_contains($message, 'profiling is not enabled')) {
        $profilingEnabled = false;
        break;
    }
}

$report = [
    'profiler' => 'spx',
    'php_version' => PHP_VERSION,
    'wall_time_ms' => round(($wallEnd - $wallStart) / 1_000_000, 3),
    'memory_usage' => memory_get_usage(true),
    'peak_memory_usage' => memory_get_peak_usage(true),
    'spx_data_dir' => $spxDataDir,
    'profiling_enabled' => $profilingEnabled,
    'report_key' => $reportKey,
    'full_report_json' => $fullJsonPath,
    'full_report_json_exists' => $fullJsonPath !== null ? is_file($fullJsonPath) : false,
    'full_report_txt_gz' => $fullTxtGzPath,
    'full_report_txt_gz_exists' => $fullTxtGzPath !== null ? is_file($fullTxtGzPath) : false,
    'start_return_type' => get_debug_type($startReturn),
    'stop_return_type' => get_debug_type($stopReturn),
    'stop_return' => is_scalar($stopReturn) || $stopReturn === null ? $stopReturn : get_debug_type($stopReturn),
    'errors' => $errors,
    'command' => $commandArgs,
    'exit_code' => $exitCode,
];

$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($json === false || file_put_contents($reportPath, $json) === false) {
    fwrite(STDERR, "Failed to write SPX report: {$reportPath}\n");
    exit(1);
}

fwrite(STDERR, "spx report written: {$reportPath}\n");
exit($exitCode);
