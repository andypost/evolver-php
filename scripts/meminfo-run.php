#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

if (!extension_loaded('meminfo')) {
    fwrite(STDERR, "meminfo extension is not loaded. Use php85 -d extension=/usr/lib/php85/modules/meminfo.so.\n");
    exit(2);
}

if (!function_exists('meminfo_dump')) {
    fwrite(STDERR, "meminfo_dump() is unavailable in this extension build.\n");
    exit(2);
}

if ($argc < 3) {
    fwrite(STDERR, "Usage: php scripts/meminfo-run.php <report-path> <evolver-command...>\n");
    exit(1);
}

$reportPath = $argv[1];
$commandArgs = array_slice($argv, 2);
$reportDir = dirname($reportPath);

if (!is_dir($reportDir) && !mkdir($reportDir, 0775, true) && !is_dir($reportDir)) {
    fwrite(STDERR, "Unable to create report directory: {$reportDir}\n");
    exit(1);
}

$application = \DrupalEvolver\ConsoleApplicationFactory::create();
$application->setAutoExit(false);

$input = new ArgvInput(array_merge(['bin/evolver'], $commandArgs));
$exitCode = $application->run($input, new ConsoleOutput());

$stream = fopen($reportPath, 'w');
if ($stream === false) {
    fwrite(STDERR, "Unable to open meminfo report file: {$reportPath}\n");
    exit(1);
}

meminfo_dump($stream);
fflush($stream);
fclose($stream);
fwrite(STDERR, "meminfo report written: {$reportPath}\n");

exit($exitCode);
