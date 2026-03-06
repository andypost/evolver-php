#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = $argv[1] ?? '.data/profiles';
$root = rtrim($root, '/');

$definitions = [
    'memprof' => ['log' => 'memprof/run.log', 'json' => 'memprof/index.json'],
    'meminfo' => ['log' => 'meminfo/run.log', 'json' => 'meminfo/index.json'],
    'spx' => ['log' => 'spx/run.log', 'json' => 'spx/index.json'],
    'xhprof' => ['log' => 'xhprof/run.log', 'json' => 'xhprof/index.json'],
];

$rows = [];
foreach ($definitions as $name => $files) {
    $logPath = "{$root}/{$files['log']}";
    $jsonPath = "{$root}/{$files['json']}";

    $row = [
        'name' => $name,
        'log_path' => $logPath,
        'json_path' => $jsonPath,
        'real' => null,
        'user' => null,
        'sys' => null,
        'maxrss' => null,
        'coverage' => 'no',
        'detail' => 'missing',
    ];

    $log = is_file($logPath) ? (string) file_get_contents($logPath) : '';
    if (preg_match('/real=([0-9.]+)\s+user=([0-9.]+)\s+sys=([0-9.]+)\s+maxrss=([0-9]+)/', $log, $m)) {
        $row['real'] = (float) $m[1];
        $row['user'] = (float) $m[2];
        $row['sys'] = (float) $m[3];
        $row['maxrss'] = (int) $m[4];
    }

    $json = is_file($jsonPath) ? (string) file_get_contents($jsonPath) : '';

    switch ($name) {
        case 'memprof':
            $data = json_decode($json, true);
            if (is_array($data) && isset($data['memory_size_inclusive'])) {
                $row['coverage'] = 'yes';
                $row['detail'] = sprintf(
                    'incl_mem=%s blocks=%s',
                    (string) ($data['memory_size_inclusive'] ?? 'n/a'),
                    (string) ($data['blocks_count_inclusive'] ?? 'n/a')
                );
            } else {
                $row['detail'] = 'invalid memprof json';
            }
            break;

        case 'meminfo':
            $memoryUsage = extractMeminfoField($json, 'memory_usage');
            $peakUsage = extractMeminfoField($json, 'peak_memory_usage');
            if ($memoryUsage !== null || $peakUsage !== null) {
                $row['coverage'] = 'yes';
                $row['detail'] = sprintf(
                    'mem=%s peak=%s',
                    $memoryUsage !== null ? (string) $memoryUsage : 'n/a',
                    $peakUsage !== null ? (string) $peakUsage : 'n/a'
                );
            } else {
                $row['detail'] = 'meminfo header missing';
            }
            break;

        case 'spx':
            $data = json_decode($json, true);
            if (is_array($data)) {
                $enabled = (bool) ($data['profiling_enabled'] ?? false);
                $reportExists = (bool) ($data['full_report_json_exists'] ?? false);
                $key = (string) ($data['report_key'] ?? '');
                $row['coverage'] = ($enabled && $reportExists) ? 'yes' : 'partial';
                $row['detail'] = sprintf(
                    'enabled=%s key=%s report=%s',
                    $enabled ? '1' : '0',
                    $key !== '' ? $key : 'n/a',
                    $reportExists ? 'yes' : 'no'
                );
            } else {
                $row['detail'] = 'invalid spx json';
            }
            break;

        case 'xhprof':
            $data = json_decode($json, true);
            if (is_array($data) && isset($data['main()']) && is_array($data['main()'])) {
                $main = $data['main()'];
                $topEdge = topXhprofEdgeByWt($data);
                $row['coverage'] = 'yes';
                $row['detail'] = sprintf(
                    'main.wt=%s top=%s',
                    (string) ($main['wt'] ?? 'n/a'),
                    $topEdge
                );
            } else {
                $row['detail'] = 'xhprof main() missing';
            }
            break;
    }

    $rows[] = $row;
}

echo "# Profile Summary\n\n";
echo "Root: `{$root}`\n\n";
echo "| Profiler | real(s) | user(s) | sys(s) | maxrss(kB) | coverage | detail |\n";
echo "|---|---:|---:|---:|---:|---|---|\n";
foreach ($rows as $row) {
    echo sprintf(
        "| %s | %s | %s | %s | %s | %s | %s |\n",
        $row['name'],
        formatFloat($row['real']),
        formatFloat($row['user']),
        formatFloat($row['sys']),
        $row['maxrss'] !== null ? (string) $row['maxrss'] : 'n/a',
        $row['coverage'],
        $row['detail']
    );
}

$timed = array_values(array_filter($rows, static fn(array $r): bool => is_float($r['real']) || is_int($r['real'])));
if (!empty($timed)) {
    usort($timed, static fn(array $a, array $b): int => ($a['real'] <=> $b['real']));
    $baseline = $timed[0]['real'] > 0 ? $timed[0]['real'] : 1.0;

    echo "\n## Runtime Ranking\n\n";
    echo "| Rank | Profiler | real(s) | slowdown vs fastest |\n";
    echo "|---:|---|---:|---:|\n";

    $rank = 1;
    foreach ($timed as $row) {
        $slowdown = $row['real'] / $baseline;
        echo sprintf(
            "| %d | %s | %.2f | %.2fx |\n",
            $rank++,
            $row['name'],
            $row['real'],
            $slowdown
        );
    }
}

function extractMeminfoField(string $json, string $field): ?int
{
    if ($json === '') {
        return null;
    }

    if (!preg_match('/"' . preg_quote($field, '/') . '"\s*:\s*([0-9]+)/', $json, $m)) {
        return null;
    }

    return (int) $m[1];
}

function topXhprofEdgeByWt(array $data): string
{
    $topEdge = 'n/a';
    $topWt = -1;

    foreach ($data as $edge => $metrics) {
        if ($edge === 'main()' || !is_array($metrics)) {
            continue;
        }

        $wt = (int) ($metrics['wt'] ?? 0);
        if ($wt > $topWt) {
            $topWt = $wt;
            $topEdge = $edge;
        }
    }

    return $topEdge;
}

function formatFloat(float|int|null $value): string
{
    if ($value === null) {
        return 'n/a';
    }

    return number_format((float) $value, 2, '.', '');
}
