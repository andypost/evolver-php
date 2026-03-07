#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

if (!extension_loaded('memprof')) {
    fwrite(STDERR, "memprof extension is not loaded. Run with php84 -d extension=/usr/lib/php84/modules/memprof.so.\n");
    exit(2);
}

if (!function_exists('memprof_enable')) {
    fwrite(STDERR, "memprof_enable() is unavailable in this extension build.\n");
    exit(2);
}

if ($argc < 3) {
    fwrite(STDERR, "Usage: php scripts/memprof-run.php <report-path> <evolver-command...>\n");
    exit(1);
}

$reportPath = $argv[1];
$commandArgs = array_slice($argv, 2);
$reportDir = dirname($reportPath);

if (!is_dir($reportDir) && !mkdir($reportDir, 0775, true) && !is_dir($reportDir)) {
    fwrite(STDERR, "Unable to create report directory: {$reportDir}\n");
    exit(1);
}

$exitCode = 1;
$alreadyEnabled = function_exists('memprof_enabled') ? (bool) memprof_enabled() : false;
if (!$alreadyEnabled) {
    memprof_enable();
}
try {
    $application = \DrupalEvolver\ConsoleApplicationFactory::create();
    $application->setAutoExit(false);

    $input = new ArgvInput(array_merge(['bin/evolver'], $commandArgs));
    $exitCode = $application->run($input, new ConsoleOutput());
} finally {
    try {
        $writer = writeMemprofReport($reportPath);
        fwrite(STDERR, "memprof report written via {$writer}: {$reportPath}\n");
    } catch (Throwable $throwable) {
        fwrite(STDERR, "Failed to write memprof report: {$throwable->getMessage()}\n");
        $exitCode = 1;
    }

    if (!$alreadyEnabled && function_exists('memprof_disable')) {
        memprof_disable();
    }
}

exit($exitCode);

function writeMemprofReport(string $reportPath): string
{
    if (function_exists('memprof_dump_array')) {
        $dump = memprof_dump_array();
        $json = json_encode($dump, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($reportPath, $json) === false) {
            throw new RuntimeException("Unable to write JSON memprof dump to {$reportPath}");
        }
        return 'memprof_dump_array';
    }

    if (function_exists('memprof_dump_callgrind')) {
        $reflection = new ReflectionFunction('memprof_dump_callgrind');
        if ($reflection->getNumberOfRequiredParameters() === 0) {
            $dump = memprof_dump_callgrind();
            if (!is_string($dump) || file_put_contents($reportPath, $dump) === false) {
                throw new RuntimeException("Unable to write callgrind dump to {$reportPath}");
            }
            return 'memprof_dump_callgrind';
        }

        $result = memprof_dump_callgrind($reportPath);
        if ($result === false || !is_file($reportPath)) {
            throw new RuntimeException("memprof_dump_callgrind() failed for {$reportPath}");
        }
        return 'memprof_dump_callgrind';
    }

    throw new RuntimeException('No compatible memprof dump function found (expected memprof_dump_array or memprof_dump_callgrind)');
}
