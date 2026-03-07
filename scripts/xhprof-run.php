#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
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
    $application = \DrupalEvolver\ConsoleApplicationFactory::create();
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
