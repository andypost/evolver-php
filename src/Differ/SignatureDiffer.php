<?php

declare(strict_types=1);

namespace DrupalEvolver\Differ;

class SignatureDiffer
{
    public function diff(?string $oldJson, ?string $newJson): array
    {
        $old = $oldJson ? json_decode($oldJson, true) : [];
        $new = $newJson ? json_decode($newJson, true) : [];
        $changes = [];

        // Compare parameters
        $oldParams = $old['params'] ?? [];
        $newParams = $new['params'] ?? [];

        $maxLen = max(count($oldParams), count($newParams));
        for ($i = 0; $i < $maxLen; $i++) {
            $oldParam = $oldParams[$i] ?? null;
            $newParam = $newParams[$i] ?? null;

            if ($oldParam === null && $newParam !== null) {
                $changes[] = [
                    'type' => 'parameter_added',
                    'position' => $i,
                    'param' => $newParam,
                ];
            } elseif ($oldParam !== null && $newParam === null) {
                $changes[] = [
                    'type' => 'parameter_removed',
                    'position' => $i,
                    'param' => $oldParam,
                ];
            } elseif ($oldParam && $newParam) {
                if (($oldParam['type'] ?? null) !== ($newParam['type'] ?? null)) {
                    $changes[] = [
                        'type' => 'parameter_type_changed',
                        'position' => $i,
                        'old_type' => $oldParam['type'] ?? null,
                        'new_type' => $newParam['type'] ?? null,
                    ];
                }
                if (($oldParam['name'] ?? '') !== ($newParam['name'] ?? '')) {
                    $changes[] = [
                        'type' => 'parameter_renamed',
                        'position' => $i,
                        'old_name' => $oldParam['name'] ?? '',
                        'new_name' => $newParam['name'] ?? '',
                    ];
                }
            }
        }

        // Compare return type
        $oldReturn = $old['return_type'] ?? null;
        $newReturn = $new['return_type'] ?? null;
        if ($oldReturn !== $newReturn) {
            $changes[] = [
                'type' => 'return_type_changed',
                'old_type' => $oldReturn,
                'new_type' => $newReturn,
            ];
        }

        return $changes;
    }
}
