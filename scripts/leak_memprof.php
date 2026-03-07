<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use DrupalEvolver\Indexer\CoreIndexer;
use DrupalEvolver\Storage\DatabaseApi;
use DrupalEvolver\TreeSitter\Parser;

if (!extension_loaded('memprof')) {
    echo "memprof extension not loaded\n";
    exit(1);
}

$parser = new Parser();
$dbPath = '/app/.data/profiles/leak_test_memprof.sqlite';
if (file_exists($dbPath)) unlink($dbPath);
$api = new DatabaseApi($dbPath);
$indexer = new CoreIndexer($parser, $api);

$path = '/drupal/core/lib/Drupal/Core';
$tag = '11.0.0';

// memprof_enable() removed to use env var instead

for ($i = 0; $i < 5; $i++) {
    $indexer->index($path, $tag . '.' . $i, null);
    echo "Iteration $i\n";
}

$data = memprof_dump_array();
file_put_contents('/app/.data/profiles/memprof_dump.json', json_encode($data));
echo "Dumped memprof to /app/.data/profiles/memprof_dump.json\n";
