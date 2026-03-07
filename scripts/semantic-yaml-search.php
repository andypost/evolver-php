#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use DrupalEvolver\Storage\DatabaseApi;

$options = getopt('', ['db::', 'tag:', 'types::', 'limit::', 'help']);

if ($options === false || isset($options['help'])) {
    fwrite(STDERR, "Usage: php scripts/semantic-yaml-search.php --tag=<version> [--db=<path>] [--types=type1,type2] [--limit=50] <term>\n");
    exit(isset($options['help']) ? 0 : 2);
}

$term = $argv[count($argv) - 1] ?? '';
if ($term === '' || str_starts_with($term, '--')) {
    fwrite(STDERR, "Search term is required.\n");
    exit(2);
}

$tag = $options['tag'] ?? null;
if (!is_string($tag) || $tag === '') {
    fwrite(STDERR, "--tag is required.\n");
    exit(2);
}

$dbPath = $options['db'] ?? '';
$limit = isset($options['limit']) ? max(1, (int) $options['limit']) : 50;
$symbolTypes = [];
if (isset($options['types']) && is_string($options['types']) && $options['types'] !== '') {
    $symbolTypes = array_values(array_filter(array_map('trim', explode(',', $options['types']))));
}

$api = new DatabaseApi(is_string($dbPath) ? $dbPath : '');
$version = $api->versions()->findByTag($tag);

if ($version === null) {
    fwrite(STDERR, "Version not found: {$tag}\n");
    exit(1);
}

$rows = $api->searchSemanticYamlSymbols((int) $version['id'], $term, $symbolTypes, $limit);

$results = array_map(static function (array $row): array {
    $signatureJson = $row['signature_json'] ?? null;
    $metadataJson = $row['metadata_json'] ?? null;
    $signature = is_string($signatureJson) && $signatureJson !== '' ? json_decode($signatureJson, true) : null;
    $metadata = is_string($metadataJson) && $metadataJson !== '' ? json_decode($metadataJson, true) : null;

    return [
        'symbol_type' => $row['symbol_type'] ?? null,
        'fqn' => $row['fqn'] ?? null,
        'file_path' => $row['file_path'] ?? null,
        'resolved_class_fqn' => $row['resolved_class_fqn'] ?? null,
        'resolved_class_file_path' => $row['resolved_class_file_path'] ?? null,
        'signature' => is_array($signature) ? $signature : null,
        'metadata' => is_array($metadata) ? $metadata : null,
    ];
}, $rows);

echo json_encode([
    'tag' => $tag,
    'term' => $term,
    'types' => $symbolTypes,
    'count' => count($results),
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
