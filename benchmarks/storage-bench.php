#!/usr/bin/env php
<?php
/**
 * Benchmark: JSON vs igbinary vs msgpack for signature_json storage
 * Also benchmarks SQLite JSON functions vs PHP-side decode.
 *
 * Run inside Docker:
 *   docker compose run --rm evolver php benchmarks/storage-bench.php
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function banner(string $title): void
{
    echo "\n" . str_repeat('=', 60) . "\n";
    echo "  {$title}\n";
    echo str_repeat('=', 60) . "\n";
}

function bench(string $label, int $iterations, callable $fn): array
{
    // Warmup
    for ($i = 0; $i < min(100, $iterations); $i++) {
        $fn();
    }

    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $fn();
    }
    $elapsed = (hrtime(true) - $start) / 1e6; // ms

    $perOp = $elapsed / $iterations;
    printf("  %-40s %8.2f ms total  %8.4f ms/op  (%d ops)\n", $label, $elapsed, $perOp, $iterations);

    return ['label' => $label, 'total_ms' => $elapsed, 'per_op_ms' => $perOp];
}

function formatBytes(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    return round($bytes / 1024, 1) . ' KB';
}

// ---------------------------------------------------------------------------
// Environment info
// ---------------------------------------------------------------------------

banner('Environment');
echo "  PHP:     " . PHP_VERSION . "\n";
echo "  OS:      " . php_uname('s') . ' ' . php_uname('r') . "\n";

$pdo = new PDO('sqlite::memory:');
$sqliteVer = $pdo->query('SELECT sqlite_version()')->fetchColumn();
echo "  SQLite:  {$sqliteVer}\n";

$hasIgbinary = extension_loaded('igbinary');
$hasMsgpack  = extension_loaded('msgpack');
echo "  igbinary: " . ($hasIgbinary ? 'YES (' . phpversion('igbinary') . ')' : 'NO') . "\n";
echo "  msgpack:  " . ($hasMsgpack ? 'YES (' . phpversion('msgpack') . ')' : 'NO') . "\n";

// Check SQLite JSON support
$hasJsonExtract = false;
try {
    $pdo->query("SELECT json_extract('{\"a\":1}', '$.a')");
    $hasJsonExtract = true;
} catch (\Throwable) {}
echo "  SQLite JSON1: " . ($hasJsonExtract ? 'YES' : 'NO') . "\n";

// ---------------------------------------------------------------------------
// Generate realistic test data
// ---------------------------------------------------------------------------

banner('Generating test data');

// Realistic signature_json payloads (from PHPExtractor)
function generateSignature(int $paramCount): array
{
    $params = [];
    for ($i = 0; $i < $paramCount; $i++) {
        $params[] = [
            'name' => '$param' . $i,
            'type' => match ($i % 5) {
                0 => 'string',
                1 => 'int',
                2 => 'array',
                3 => '?bool',
                4 => 'EntityInterface|null',
            },
            'default' => $i > $paramCount / 2 ? 'null' : null,
        ];
    }
    return ['params' => $params, 'return_type' => $paramCount % 3 === 0 ? 'void' : 'string'];
}

// Realistic diff_json payloads
function generateDiff(): array
{
    return [
        'old' => generateSignature(rand(2, 6)),
        'new' => generateSignature(rand(2, 8)),
        'changes' => [
            ['type' => 'parameter_added', 'position' => 3, 'name' => '$newParam'],
            ['type' => 'return_type_changed', 'old' => 'string', 'new' => 'void'],
        ],
    ];
}

// Realistic fix_template payloads
function generateFixTemplate(): array
{
    return match (rand(0, 3)) {
        0 => ['type' => 'function_rename', 'old' => 'drupal_render', 'new' => '\\Drupal::service(\'renderer\')->render', 'arg_map' => [0]],
        1 => ['type' => 'parameter_insert', 'function' => 'some_function', 'position' => 2, 'value' => 'NULL'],
        2 => ['type' => 'string_replace', 'context' => 'service_reference', 'old' => 'old.service.name', 'new' => 'new.service.name'],
        3 => ['type' => 'namespace_move', 'old_namespace' => 'Drupal\\Core\\Old\\Namespace', 'new_namespace' => 'Drupal\\Core\\New\\Namespace', 'class' => 'SomeClass'],
    };
}

$sigSmall = generateSignature(2);  // ~100 bytes JSON
$sigMedium = generateSignature(5); // ~300 bytes JSON
$sigLarge = generateSignature(10); // ~600 bytes JSON
$diffPayload = generateDiff();      // ~500 bytes JSON
$fixPayload = generateFixTemplate(); // ~150 bytes JSON

$jsonSmall  = json_encode($sigSmall);
$jsonMedium = json_encode($sigMedium);
$jsonLarge  = json_encode($sigLarge);
$jsonDiff   = json_encode($diffPayload);
$jsonFix    = json_encode($fixPayload);

echo "  signature_json (2 params):  " . strlen($jsonSmall) . " bytes\n";
echo "  signature_json (5 params):  " . strlen($jsonMedium) . " bytes\n";
echo "  signature_json (10 params): " . strlen($jsonLarge) . " bytes\n";
echo "  diff_json:                  " . strlen($jsonDiff) . " bytes\n";
echo "  fix_template:               " . strlen($jsonFix) . " bytes\n";

// ---------------------------------------------------------------------------
// Benchmark 1: Encode/Decode speed
// ---------------------------------------------------------------------------

$iterations = 50_000;

banner("Encode speed ({$iterations} iterations)");

$results = [];

$results[] = bench('json_encode (small)', $iterations, fn() => json_encode($sigSmall));
$results[] = bench('json_encode (medium)', $iterations, fn() => json_encode($sigMedium));
$results[] = bench('json_encode (large)', $iterations, fn() => json_encode($sigLarge));
$results[] = bench('json_encode (diff)', $iterations, fn() => json_encode($diffPayload));

if ($hasIgbinary) {
    $results[] = bench('igbinary_serialize (small)', $iterations, fn() => igbinary_serialize($sigSmall));
    $results[] = bench('igbinary_serialize (medium)', $iterations, fn() => igbinary_serialize($sigMedium));
    $results[] = bench('igbinary_serialize (large)', $iterations, fn() => igbinary_serialize($sigLarge));
    $results[] = bench('igbinary_serialize (diff)', $iterations, fn() => igbinary_serialize($diffPayload));
}

if ($hasMsgpack) {
    $results[] = bench('msgpack_pack (small)', $iterations, fn() => msgpack_pack($sigSmall));
    $results[] = bench('msgpack_pack (medium)', $iterations, fn() => msgpack_pack($sigMedium));
    $results[] = bench('msgpack_pack (large)', $iterations, fn() => msgpack_pack($sigLarge));
    $results[] = bench('msgpack_pack (diff)', $iterations, fn() => msgpack_pack($diffPayload));
}

$results[] = bench('serialize (small)', $iterations, fn() => serialize($sigSmall));
$results[] = bench('serialize (medium)', $iterations, fn() => serialize($sigMedium));
$results[] = bench('serialize (large)', $iterations, fn() => serialize($sigLarge));

banner("Decode speed ({$iterations} iterations)");

$results[] = bench('json_decode (small)', $iterations, fn() => json_decode($jsonSmall, true));
$results[] = bench('json_decode (medium)', $iterations, fn() => json_decode($jsonMedium, true));
$results[] = bench('json_decode (large)', $iterations, fn() => json_decode($jsonLarge, true));
$results[] = bench('json_decode (diff)', $iterations, fn() => json_decode($jsonDiff, true));

if ($hasIgbinary) {
    $igSmall  = igbinary_serialize($sigSmall);
    $igMedium = igbinary_serialize($sigMedium);
    $igLarge  = igbinary_serialize($sigLarge);
    $igDiff   = igbinary_serialize($diffPayload);

    $results[] = bench('igbinary_unserialize (small)', $iterations, fn() => igbinary_unserialize($igSmall));
    $results[] = bench('igbinary_unserialize (medium)', $iterations, fn() => igbinary_unserialize($igMedium));
    $results[] = bench('igbinary_unserialize (large)', $iterations, fn() => igbinary_unserialize($igLarge));
    $results[] = bench('igbinary_unserialize (diff)', $iterations, fn() => igbinary_unserialize($igDiff));
}

if ($hasMsgpack) {
    $mpSmall  = msgpack_pack($sigSmall);
    $mpMedium = msgpack_pack($sigMedium);
    $mpLarge  = msgpack_pack($sigLarge);
    $mpDiff   = msgpack_pack($diffPayload);

    $results[] = bench('msgpack_unpack (small)', $iterations, fn() => msgpack_unpack($mpSmall));
    $results[] = bench('msgpack_unpack (medium)', $iterations, fn() => msgpack_unpack($mpMedium));
    $results[] = bench('msgpack_unpack (large)', $iterations, fn() => msgpack_unpack($mpLarge));
    $results[] = bench('msgpack_unpack (diff)', $iterations, fn() => msgpack_unpack($mpDiff));
}

$serSmall  = serialize($sigSmall);
$serMedium = serialize($sigMedium);
$serLarge  = serialize($sigLarge);

$results[] = bench('unserialize (small)', $iterations, fn() => unserialize($serSmall));
$results[] = bench('unserialize (medium)', $iterations, fn() => unserialize($serMedium));
$results[] = bench('unserialize (large)', $iterations, fn() => unserialize($serLarge));

// ---------------------------------------------------------------------------
// Benchmark 2: Storage size comparison
// ---------------------------------------------------------------------------

banner('Storage size comparison');

$payloads = [
    'signature (2p)' => $sigSmall,
    'signature (5p)' => $sigMedium,
    'signature (10p)' => $sigLarge,
    'diff_json' => $diffPayload,
    'fix_template' => $fixPayload,
];

printf("  %-20s %10s %10s %10s %10s\n", 'Payload', 'JSON', 'igbinary', 'msgpack', 'serialize');
printf("  %-20s %10s %10s %10s %10s\n", str_repeat('-', 20), str_repeat('-', 10), str_repeat('-', 10), str_repeat('-', 10), str_repeat('-', 10));

foreach ($payloads as $name => $data) {
    $jsonLen = strlen(json_encode($data));
    $igLen   = $hasIgbinary ? strlen(igbinary_serialize($data)) : 0;
    $mpLen   = $hasMsgpack ? strlen(msgpack_pack($data)) : 0;
    $serLen  = strlen(serialize($data));

    printf("  %-20s %10s %10s %10s %10s\n",
        $name,
        formatBytes($jsonLen),
        $hasIgbinary ? formatBytes($igLen) : 'N/A',
        $hasMsgpack ? formatBytes($mpLen) : 'N/A',
        formatBytes($serLen)
    );
}

// ---------------------------------------------------------------------------
// Benchmark 3: SQLite json_extract vs PHP json_decode
// ---------------------------------------------------------------------------

if ($hasJsonExtract) {
    banner('SQLite json_extract() vs PHP json_decode()');

    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA synchronous=NORMAL');

    $db->exec('CREATE TABLE bench_symbols (
        id INTEGER PRIMARY KEY,
        fqn TEXT NOT NULL,
        signature_json TEXT,
        signature_hash TEXT
    )');
    $db->exec('CREATE INDEX idx_bench_fqn ON bench_symbols(fqn)');

    // Insert realistic data (~15K symbols = one Drupal version)
    $symbolCount = 15_000;
    echo "  Inserting {$symbolCount} symbols...\n";

    $db->beginTransaction();
    $stmt = $db->prepare('INSERT INTO bench_symbols (fqn, signature_json, signature_hash) VALUES (?, ?, ?)');
    for ($i = 0; $i < $symbolCount; $i++) {
        $paramCount = rand(0, 8);
        $sig = generateSignature($paramCount);
        $json = json_encode($sig);
        $hash = hash('sha256', "function|Drupal\\Module{$i}\\Class::method{$i}|{$json}");
        $stmt->execute(["Drupal\\Module" . ($i % 500) . "\\Class" . ($i % 100) . "::method{$i}", $json, $hash]);
    }
    $db->commit();
    echo "  Done.\n";

    $queryIterations = 100;

    // Approach A: SELECT *, then json_decode in PHP
    bench("PHP-side: SELECT * + json_decode ({$queryIterations}x)", $queryIterations, function () use ($db) {
        $rows = $db->query('SELECT * FROM bench_symbols LIMIT 1000')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $sig = json_decode($row['signature_json'], true);
            $params = $sig['params'] ?? [];
            $returnType = $sig['return_type'] ?? null;
        }
    });

    // Approach B: SQLite json_extract in query
    bench("SQLite-side: json_extract() ({$queryIterations}x)", $queryIterations, function () use ($db) {
        $rows = $db->query("SELECT id, fqn, json_extract(signature_json, '$.return_type') as rt,
            json_array_length(signature_json, '$.params') as param_count
            FROM bench_symbols LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $rt = $row['rt'];
            $pc = $row['param_count'];
        }
    });

    // Approach C: Compute param count in SQLite for filtering
    bench("SQLite filter: WHERE param_count > N ({$queryIterations}x)", $queryIterations, function () use ($db) {
        $rows = $db->query("SELECT id, fqn, signature_json FROM bench_symbols
            WHERE json_array_length(signature_json, '$.params') > 3 LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
    });

    // Approach D: Same filter but fetch all + PHP filter
    bench("PHP filter: fetchAll + json_decode + filter ({$queryIterations}x)", $queryIterations, function () use ($db) {
        $rows = $db->query('SELECT id, fqn, signature_json FROM bench_symbols LIMIT 2000')->fetchAll(PDO::FETCH_ASSOC);
        $filtered = [];
        foreach ($rows as $row) {
            $sig = json_decode($row['signature_json'], true);
            if (count($sig['params'] ?? []) > 3) {
                $filtered[] = $row;
            }
        }
    });

    // ---------------------------------------------------------------------------
    // Benchmark 4: The actual hot path — diff query performance
    // ---------------------------------------------------------------------------

    banner('Diff query simulation (the real bottleneck)');

    // Create a second "version" with ~80% overlap, ~10% changed, ~10% removed/added
    $db->exec('ALTER TABLE bench_symbols ADD COLUMN version_id INTEGER DEFAULT 1');
    $db->exec('UPDATE bench_symbols SET version_id = 1');
    $db->exec('CREATE INDEX idx_bench_ver_fqn ON bench_symbols(version_id, fqn)');
    $db->exec('CREATE INDEX idx_bench_ver_hash ON bench_symbols(version_id, signature_hash)');

    echo "  Creating version 2 (~15K symbols with 20% drift)...\n";
    $db->beginTransaction();
    $allV1 = $db->query('SELECT fqn, signature_json, signature_hash FROM bench_symbols WHERE version_id = 1')->fetchAll(PDO::FETCH_ASSOC);

    $stmtV2 = $db->prepare('INSERT INTO bench_symbols (version_id, fqn, signature_json, signature_hash) VALUES (2, ?, ?, ?)');
    $changed = 0;
    $removed = 0;
    foreach ($allV1 as $i => $row) {
        $roll = rand(1, 100);
        if ($roll <= 10) {
            $removed++;
            continue; // removed in v2
        }
        if ($roll <= 20) {
            // changed signature
            $sig = generateSignature(rand(1, 10));
            $json = json_encode($sig);
            $hash = hash('sha256', "function|{$row['fqn']}|{$json}");
            $stmtV2->execute([$row['fqn'], $json, $hash]);
            $changed++;
        } else {
            // unchanged
            $stmtV2->execute([$row['fqn'], $row['signature_json'], $row['signature_hash']]);
        }
    }
    // Add some new symbols
    for ($i = 0; $i < 500; $i++) {
        $sig = generateSignature(rand(1, 6));
        $json = json_encode($sig);
        $hash = hash('sha256', "function|Drupal\\NewModule\\NewClass::newMethod{$i}|{$json}");
        $stmtV2->execute(["Drupal\\NewModule\\NewClass::newMethod{$i}", $json, $hash]);
    }
    $db->commit();
    echo "  Version 2: removed={$removed}, changed={$changed}, added=500\n";

    $diffIterations = 20;

    // The actual SymbolDiffer queries
    bench("SQL NOT EXISTS (findRemoved) ({$diffIterations}x)", $diffIterations, function () use ($db) {
        $db->query("SELECT o.* FROM bench_symbols o
            WHERE o.version_id = 1
            AND NOT EXISTS (
                SELECT 1 FROM bench_symbols n
                WHERE n.version_id = 2 AND n.fqn = o.fqn
            )")->fetchAll(PDO::FETCH_ASSOC);
    });

    bench("SQL NOT IN (findRemoved alt) ({$diffIterations}x)", $diffIterations, function () use ($db) {
        $db->query("SELECT o.* FROM bench_symbols o
            WHERE o.version_id = 1
            AND o.fqn NOT IN (
                SELECT n.fqn FROM bench_symbols n WHERE n.version_id = 2
            )")->fetchAll(PDO::FETCH_ASSOC);
    });

    bench("SQL LEFT JOIN IS NULL (findRemoved alt) ({$diffIterations}x)", $diffIterations, function () use ($db) {
        $db->query("SELECT o.* FROM bench_symbols o
            LEFT JOIN bench_symbols n ON n.version_id = 2 AND n.fqn = o.fqn
            WHERE o.version_id = 1 AND n.id IS NULL")->fetchAll(PDO::FETCH_ASSOC);
    });

    bench("SQL EXCEPT (findRemoved alt) ({$diffIterations}x)", $diffIterations, function () use ($db) {
        $db->query("SELECT fqn FROM bench_symbols WHERE version_id = 1
            EXCEPT
            SELECT fqn FROM bench_symbols WHERE version_id = 2")->fetchAll(PDO::FETCH_ASSOC);
    });

    bench("SQL JOIN hash != (findChanged) ({$diffIterations}x)", $diffIterations, function () use ($db) {
        $db->query("SELECT o.id, o.fqn, o.signature_json, n.id as new_id, n.signature_json as new_sig
            FROM bench_symbols o
            JOIN bench_symbols n ON o.fqn = n.fqn
            WHERE o.version_id = 1 AND n.version_id = 2
            AND o.signature_hash != n.signature_hash")->fetchAll(PDO::FETCH_ASSOC);
    });

    // PHP in-memory approach
    bench("PHP hash-map diff (findRemoved) ({$diffIterations}x)", $diffIterations, function () use ($db) {
        $v1 = $db->query("SELECT fqn, signature_hash FROM bench_symbols WHERE version_id = 1")->fetchAll(PDO::FETCH_KEY_PAIR);
        $v2 = $db->query("SELECT fqn, signature_hash FROM bench_symbols WHERE version_id = 2")->fetchAll(PDO::FETCH_KEY_PAIR);
        $removed = array_diff_key($v1, $v2);
    });

    bench("PHP hash-map diff (findChanged) ({$diffIterations}x)", $diffIterations, function () use ($db) {
        $v1 = $db->query("SELECT fqn, signature_hash FROM bench_symbols WHERE version_id = 1")->fetchAll(PDO::FETCH_KEY_PAIR);
        $v2 = $db->query("SELECT fqn, signature_hash FROM bench_symbols WHERE version_id = 2")->fetchAll(PDO::FETCH_KEY_PAIR);
        $common = array_intersect_key($v1, $v2);
        $changed = array_filter($common, fn($hash, $fqn) => $hash !== $v2[$fqn], ARRAY_FILTER_USE_BOTH);
    });

    // Benchmark the json_decode cost in the diff pipeline
    bench("Decode changed sigs ({$diffIterations}x)", $diffIterations, function () use ($db) {
        $rows = $db->query("SELECT o.fqn, o.signature_json as old_sig, n.signature_json as new_sig
            FROM bench_symbols o
            JOIN bench_symbols n ON o.fqn = n.fqn
            WHERE o.version_id = 1 AND n.version_id = 2
            AND o.signature_hash != n.signature_hash")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $old = json_decode($row['old_sig'], true);
            $new = json_decode($row['new_sig'], true);
        }
    });
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

banner('Key Takeaways');
echo "  Run this inside Docker to see real numbers for your environment.\n";
echo "  Compare: json_decode vs igbinary_unserialize for the ~30K decode calls during diff.\n";
echo "  Compare: SQL NOT EXISTS vs PHP hash-map for set-difference on 15K symbols.\n";
echo "  Compare: SQLite json_extract() vs PHP-side filtering.\n";
