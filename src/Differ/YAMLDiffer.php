<?php

declare(strict_types=1);

namespace DrupalEvolver\Differ;

class YAMLDiffer
{
    /**
     * @param array<int, array<string, mixed>> $removed
     * @return array<int, array<string, mixed>>
     */
    public function findRemovedChanges(array $removed): array
    {
        $changes = [];

        foreach ($removed as $symbol) {
            if (($symbol['language'] ?? null) !== 'yaml') {
                continue;
            }

            $changeType = $this->removalChangeType((string) ($symbol['symbol_type'] ?? ''));
            if ($changeType === null) {
                continue;
            }

            $changes[] = [
                'old' => $symbol,
                'change_type' => $changeType,
                'severity' => 'breaking',
                'confidence' => 1.0,
            ];
        }

        return $changes;
    }

    /**
     * @param array<int, array<string, mixed>> $removed
     * @param array<int, array<string, mixed>> $added
     * @return array<int, array<string, mixed>>
     */
    public function findRenameChanges(array $removed, array $added): array
    {
        $changes = [];
        $usedAdded = [];

        foreach ($removed as $oldSymbol) {
            if (($oldSymbol['language'] ?? null) !== 'yaml') {
                continue;
            }

            $best = null;
            $bestKey = null;
            $bestScore = 0.0;

            foreach ($added as $index => $newSymbol) {
                if (!$this->isComparable($oldSymbol, $newSymbol)) {
                    continue;
                }

                $addedKey = $this->symbolKey($newSymbol, $index);
                if (isset($usedAdded[$addedKey])) {
                    continue;
                }

                $score = $this->renameScore($oldSymbol, $newSymbol);
                if ($score > $bestScore) {
                    $best = $newSymbol;
                    $bestKey = $addedKey;
                    $bestScore = $score;
                }
            }

            if ($best === null || $bestScore < 0.78) {
                continue;
            }

            if ($bestKey !== null) {
                $usedAdded[$bestKey] = true;
            }

            $changes[] = [
                'old' => $oldSymbol,
                'new' => $best,
                'change_type' => $this->renameChangeType((string) ($oldSymbol['symbol_type'] ?? '')),
                'severity' => 'breaking',
                'confidence' => $bestScore,
            ];
        }

        return $changes;
    }

    /**
     * @param array<int, array{old: array<string, mixed>, new: array<string, mixed>}> $changed
     * @return array<int, array<string, mixed>>
     */
    public function findChangedChanges(array $changed): array
    {
        $changes = [];

        foreach ($changed as $pair) {
            $old = $pair['old'] ?? [];
            $new = $pair['new'] ?? [];
            if (($old['language'] ?? null) !== 'yaml') {
                continue;
            }

            $changeType = $this->changedChangeType($old, $new);
            if ($changeType === null) {
                continue;
            }

            $details = $this->buildDiffDetails($old, $new);
            $changes[] = [
                'old' => $old,
                'new' => $new,
                'change_type' => $changeType,
                'severity' => 'breaking',
                'confidence' => 1.0,
                'diff_json' => empty($details) ? null : json_encode($details),
            ];
        }

        return $changes;
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     */
    private function isComparable(array $old, array $new): bool
    {
        if (($new['language'] ?? null) !== 'yaml') {
            return false;
        }

        if (($old['symbol_type'] ?? null) !== ($new['symbol_type'] ?? null)) {
            return false;
        }

        if (($old['fqn'] ?? null) === ($new['fqn'] ?? null)) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $symbol
     */
    private function symbolKey(array $symbol, int|string $fallbackIndex): string
    {
        if (isset($symbol['id'])) {
            return (string) $symbol['id'];
        }

        return (string) $fallbackIndex;
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     */
    private function renameScore(array $old, array $new): float
    {
        $score = 0.0;

        $oldSig = (string) ($old['signature_json'] ?? '');
        $newSig = (string) ($new['signature_json'] ?? '');
        if ($oldSig !== '' && $oldSig === $newSig) {
            $score += 0.65;
        }

        $nameSimilarity = $this->similarity((string) ($old['name'] ?? ''), (string) ($new['name'] ?? ''));
        $score += 0.20 * $nameSimilarity;

        $sourceSimilarity = $this->similarity(
            $this->normalizeSource((string) ($old['source_text'] ?? '')),
            $this->normalizeSource((string) ($new['source_text'] ?? ''))
        );
        $score += 0.15 * $sourceSimilarity;

        return min(1.0, $score);
    }

    private function normalizeSource(string $source): string
    {
        if ($source === '') {
            return '';
        }

        return preg_replace('/\s+/', ' ', trim($source)) ?? $source;
    }

    private function similarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }

        if ($a === $b) {
            return 1.0;
        }

        $maxLen = max(strlen($a), strlen($b));
        if ($maxLen === 0) {
            return 0.0;
        }

        $distance = levenshtein($a, $b);
        $normalized = 1.0 - ($distance / $maxLen);

        return max(0.0, min(1.0, $normalized));
    }

    private function removalChangeType(string $symbolType): ?string
    {
        return match ($symbolType) {
            'service' => 'service_removed',
            'route' => 'route_removed',
            'permission' => 'permission_removed',
            'config_schema' => 'config_key_removed',
            default => null,
        };
    }

    private function renameChangeType(string $symbolType): string
    {
        return match ($symbolType) {
            'service' => 'service_renamed',
            'config_schema' => 'config_key_renamed',
            default => $symbolType . '_renamed',
        };
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     */
    private function changedChangeType(array $old, array $new): ?string
    {
        $symbolType = (string) ($old['symbol_type'] ?? '');

        return match ($symbolType) {
            'service' => $this->serviceChangeType($old, $new),
            'route' => 'route_changed',
            'library' => 'library_changed',
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     */
    private function serviceChangeType(array $old, array $new): string
    {
        $oldSig = $this->decodeSignature($old);
        $newSig = $this->decodeSignature($new);
        $oldClass = $oldSig['class'] ?? null;
        $newClass = $newSig['class'] ?? null;

        if ($oldClass !== $newClass) {
            return 'service_class_changed';
        }

        return 'service_changed';
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     * @return array<string, mixed>
     */
    private function buildDiffDetails(array $old, array $new): array
    {
        $symbolType = (string) ($old['symbol_type'] ?? '');
        $oldSig = $this->decodeSignature($old);
        $newSig = $this->decodeSignature($new);

        return match ($symbolType) {
            'service' => [
                'old_class' => $oldSig['class'] ?? null,
                'new_class' => $newSig['class'] ?? null,
                'old_arguments' => $oldSig['arguments'] ?? null,
                'new_arguments' => $newSig['arguments'] ?? null,
                'old_tags' => $oldSig['tags'] ?? null,
                'new_tags' => $newSig['tags'] ?? null,
            ],
            'route' => [
                'old_path' => $oldSig['path'] ?? null,
                'new_path' => $newSig['path'] ?? null,
                'old_controller' => $oldSig['controller'] ?? null,
                'new_controller' => $newSig['controller'] ?? null,
            ],
            default => [
                'old_signature' => $oldSig,
                'new_signature' => $newSig,
            ],
        };
    }

    /**
     * @param array<string, mixed> $symbol
     * @return array<string, mixed>
     */
    private function decodeSignature(array $symbol): array
    {
        $json = $symbol['signature_json'] ?? null;
        if (!is_string($json) || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
