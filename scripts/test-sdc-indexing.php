<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use DrupalEvolver\Indexer\WorkerPayloadBuilder;
use DrupalEvolver\TreeSitter\Parser;

// Setup a dummy SDC directory structure
$tempDir = sys_get_temp_dir() . '/sdc_test_' . uniqid();
mkdir($tempDir, 0777, true);
$componentDir = $tempDir . '/my_module/components/my-button';
mkdir($componentDir, 0777, true);

file_put_contents($tempDir . '/my_module/my_module.info.yml', "name: My Module\ntype: module\ncore_version_requirement: ^10\n");
file_put_contents($componentDir . '/my-button.component.yml', "name: My Button\ndescription: A simple button component\n");

// Twig with various SDC call styles
$twigContent = <<<'TWIG'
{% include 'other-file.twig' %}
{% include 'my_module:my-button' %}
{% component 'my_module:card' with { prop: 'val' } %}{% endcomponent %}
{{ component('my_theme:hero', { title: 'Hello' }) }}
<button class="my-button">{{ label }}</button>
TWIG;

file_put_contents($componentDir . '/my-button.twig', $twigContent);
file_put_contents($componentDir . '/my-button.css', ".my-button { color: red; }\n");
file_put_contents($componentDir . '/my-button.js', "function initButton() { console.log('init'); }\n");

$files = [
    $tempDir . '/my_module/my_module.info.yml',
    $componentDir . '/my-button.component.yml',
    $componentDir . '/my-button.twig',
    $componentDir . '/my-button.css',
    $componentDir . '/my-button.js',
];

echo "Indexing temporary SDC structure at $tempDir...\n";

$parser = new Parser();
$payload = WorkerPayloadBuilder::buildWithParser($tempDir, $files, [], false, $parser);

foreach ($payload['entries'] as $entry) {
    echo "\nFile: {$entry['file']['file_path']} ({$entry['file']['language']})\n";
    foreach ($entry['symbols'] as $symbol) {
        $metadata = json_decode($symbol['metadata_json'] ?? '{}', true);
        $sdc = $metadata['sdc_component'] ?? 'NONE';
        echo "  - Symbol: {$symbol['fqn']} [Type: {$symbol['symbol_type']}] [SDC: $sdc]\n";
    }
}

// Cleanup
exec("rm -rf " . escapeshellarg($tempDir));
