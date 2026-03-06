<?php

declare(strict_types=1);

$dumpFile = '/home/andy/www/drupal/DrupalEvolver/.data/profiles/memprof_dump.json';
if (!file_exists($dumpFile)) {
    echo "Dump file not found: $dumpFile\n";
    exit(1);
}

$data = json_decode(file_get_contents($dumpFile), true);
if (!$data) {
    echo "Failed to decode JSON\n";
    exit(1);
}

$results = [];

function analyze($name, $info, &$results) {
    $results[] = [
        'name' => $name,
        'inclusive' => $info['memory_size_inclusive'] ?? 0,
        'exclusive' => $info['memory_size'] ?? 0,
        'calls' => $info['calls'] ?? 0,
    ];
    
    if (isset($info['called_functions'])) {
        foreach ($info['called_functions'] as $childName => $childInfo) {
            analyze($childName, $childInfo, $results);
        }
    }
}

foreach ($data as $name => $info) {
    analyze($name, $info, $results);
}

usort($results, fn($a, $b) => $b['inclusive'] <=> $a['inclusive']);

echo str_pad("Function", 80) . " | " . str_pad("Inclusive", 15) . " | " . str_pad("Exclusive", 15) . " | " . str_pad("Calls", 10) . "\n";
echo str_repeat("-", 130) . "\n";

$seen = [];
foreach ($results as $res) {
    if ($res['inclusive'] < 1024 * 1024) continue; // Skip < 1MB
    $key = $res['name'] . '|' . $res['inclusive'];
    if (isset($seen[$key])) continue;
    $seen[$key] = true;
    
    echo str_pad($res['name'], 80) . " | " . str_pad((string)$res['inclusive'], 15) . " | " . str_pad((string)$res['exclusive'], 15) . " | " . str_pad((string)$res['calls'], 10) . "\n";
}
