<?php

declare(strict_types=1);

$dumpFile = '/home/andy/www/drupal/DrupalEvolver/.data/profiles/meminfo_dump.json';
if (!file_exists($dumpFile)) {
    echo "Dump file not found: $dumpFile\n";
    exit(1);
}

$data = json_decode(file_get_contents($dumpFile), true);
if (!$data) {
    echo "Failed to decode JSON\n";
    exit(1);
}

$counts = [];
$sizes = [];

foreach ($data['items'] as $id => $item) {
    $type = $item['type'];
    $class = $item['class'] ?? $type;
    
    if (!isset($counts[$class])) {
        $counts[$class] = 0;
        $sizes[$class] = 0;
    }
    
    $counts[$class]++;
    $sizes[$class] += $item['size'] ?? 0;
}

arsort($counts);

echo "Object counts by class:\n";
echo str_pad("Class", 60) . " | " . str_pad("Count", 10) . " | " . str_pad("Size", 15) . "\n";
echo str_repeat("-", 90) . "\n";

foreach ($counts as $class => $count) {
    if ($count < 10) continue;
    echo str_pad($class, 60) . " | " . str_pad((string)$count, 10) . " | " . str_pad((string)$sizes[$class], 15) . "\n";
}
