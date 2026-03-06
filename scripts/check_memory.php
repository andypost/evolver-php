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
    echo "meminfo extension not loaded\n";
    exit(1);
}

$parser = new Parser();
$dbPath = '/app/.data/profiles/leak_test.sqlite';
if (file_exists($dbPath)) unlink($dbPath);
$db = new Database($dbPath);
(new Schema($db))->createAll();

$versionRepo = new VersionRepo($db);
$fileRepo = new FileRepo($db);
$symbolRepo = new SymbolRepo($db);
$indexer = new CoreIndexer($parser, $db, $versionRepo, $fileRepo, $symbolRepo);
$indexer->setWorkerCount(4);

$path = '/drupal/core/lib/Drupal/Core';
$tag = '11.0.0';

echo "Baseline memory: " . memory_get_usage(true) . " bytes\n";

for ($i = 0; $i < 20; $i++) {
    $indexer->index($path, $tag . '.' . $i, null);
    echo "After iteration $i: " . memory_get_usage(true) . " bytes (logical: " . memory_get_usage(false) . ")\n";
    gc_collect_cycles();
}

echo "Final memory: " . memory_get_usage(true) . " bytes\n";

$file = fopen('/app/.data/profiles/meminfo_dump.json', 'w');
meminfo_dump($file);
fclose($file);

echo "Dumped meminfo to /app/.data/profiles/meminfo_dump.json\n";
