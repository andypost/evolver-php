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

if (!extension_loaded('xhprof')) {
    fwrite(STDERR, "xhprof extension is not loaded. Use -d extension=/usr/lib/phpXX/modules/xhprof.so.\n");
    exit(2);
}

if (!function_exists('xhprof_enable') || !function_exists('xhprof_disable')) {
    fwrite(STDERR, "xhprof APIs are unavailable in this extension build.\n");
    exit(2);
}

if ($argc < 3) {
    fwrite(STDERR, "Usage: php scripts/xhprof-run.php <report-path> <evolver-command...>\n");
    exit(1);
}

$reportPath = $argv[1];
$commandArgs = array_slice($argv, 2);
$reportDir = dirname($reportPath);

if (!is_dir($reportDir) && !mkdir($reportDir, 0775, true) && !is_dir($reportDir)) {
    fwrite(STDERR, "Unable to create report directory: {$reportDir}\n");
    exit(1);
}

$flags = 0;
if (defined('XHPROF_FLAGS_CPU')) {
    $flags |= XHPROF_FLAGS_CPU;
}
if (defined('XHPROF_FLAGS_MEMORY')) {
    $flags |= XHPROF_FLAGS_MEMORY;
}

xhprof_enable($flags);

$exitCode = 1;
try {
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
} finally {
    $profile = xhprof_disable();
    $json = json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($reportPath, $json) === false) {
        fwrite(STDERR, "Failed to write xhprof report: {$reportPath}\n");
        exit(1);
    }
    fwrite(STDERR, "xhprof report written: {$reportPath}\n");
}

exit($exitCode);
