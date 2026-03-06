<?php

declare(strict_types=1);

namespace DrupalEvolver\Differ;

class RenameMatcher
{
    /**
     * @param array<int, array<string, mixed>> $removed
     * @param array<int, array<string, mixed>> $added
     * @return array<int, array<string, mixed>>
     */
    public function match(array $removed, array $added): array
    {
        $matches = [];
        $usedAdded = [];

        // Pre-group by language
        $addedByLang = [];
        foreach ($added as $newSymbol) {
            $lang = (string) ($newSymbol['language'] ?? 'unknown');
            $addedByLang[$lang][] = $newSymbol;
        }

        $removedByLang = [];
        foreach ($removed as $oldSymbol) {
            $lang = (string) ($oldSymbol['language'] ?? 'unknown');
            $removedByLang[$lang][] = $oldSymbol;
        }

        foreach ($removedByLang as $lang => $langRemoved) {
            $langAdded = $addedByLang[$lang] ?? [];
            if (empty($langAdded)) continue;

            $matches = array_merge($matches, $this->matchInLanguage($langRemoved, $langAdded, $usedAdded));
        }

        return $matches;
    }

    private function matchInLanguage(array $removed, array $added, array &$usedAdded): array
    {
        $matches = [];
        
        // Pre-group added symbols by type, signature and name
        $addedByType = [];
        $addedBySignature = [];
        $addedByName = [];
        
        foreach ($added as $newSymbol) {
            $type = (string) ($newSymbol['symbol_type'] ?? 'unknown');
            $addedByType[$type][] = $newSymbol;
            
            $sig = (string) ($newSymbol['signature_json'] ?? '');
            if ($sig !== '') {
                $addedBySignature[$type][$sig][] = $newSymbol;
            }
            
            $name = $this->shortName($newSymbol);
            if ($name !== '') {
                $addedByName[$type][$name][] = $newSymbol;
            }
        }

        foreach ($removed as $oldSymbol) {
            $type = (string) ($oldSymbol['symbol_type'] ?? 'unknown');
            $oldSig = (string) ($oldSymbol['signature_json'] ?? '');
            $oldName = $this->shortName($oldSymbol);
            
            $best = null;
            $bestScore = 0.0;

            // Strategy 1: Exact signature match (High priority, very fast)
            if ($oldSig !== '' && isset($addedBySignature[$type][$oldSig])) {
                foreach ($addedBySignature[$type][$oldSig] as $newSymbol) {
                    $newId = (int) ($newSymbol['id'] ?? 0);
                    if ($newId > 0 && isset($usedAdded[$newId])) continue;
                    
                    // Same signature? High probability.
                    $score = $this->score($oldSymbol, $newSymbol);
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $best = $newSymbol;
                    }
                    if ($bestScore >= 0.95) break; 
                }
            }

            // Strategy 2: Exact name match (e.g. namespace move)
            if ($bestScore < 0.9 && $oldName !== '' && isset($addedByName[$type][$oldName])) {
                foreach ($addedByName[$type][$oldName] as $newSymbol) {
                    $newId = (int) ($newSymbol['id'] ?? 0);
                    if ($newId > 0 && isset($usedAdded[$newId])) continue;
                    
                    $score = $this->score($oldSymbol, $newSymbol);
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $best = $newSymbol;
                    }
                    if ($bestScore >= 0.9) break;
                }
            }

            // Strategy 3: Heuristic name similarity (Limited candidates for speed)
            if ($bestScore < 0.8 && isset($addedByType[$type])) {
                // If the set is huge, we can't do full heuristic scan.
                // Limit to 20 candidates per removed symbol.
                $candidatesCount = count($addedByType[$type]);
                $limit = $candidatesCount > 1000 ? 20 : 100;
                
                $count = 0;
                foreach ($addedByType[$type] as $newSymbol) {
                    $newId = (int) ($newSymbol['id'] ?? 0);
                    if ($newId > 0 && isset($usedAdded[$newId])) continue;

                    $newName = $this->shortName($newSymbol);
                    if (!$this->isNameSimilarEnough($oldName, $newName)) continue;

                    $score = $this->score($oldSymbol, $newSymbol, false);
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $best = $newSymbol;
                    }
                    
                    if (++$count > $limit) break;
                }
            }

            if ($best === null || $bestScore < 0.70) continue;

            $bestId = (int) ($best['id'] ?? 0);
            if ($bestId > 0) {
                $usedAdded[$bestId] = true;
            }

            $matches[] = [
                'old' => $oldSymbol,
                'new' => $best,
                'change_type' => ($oldSymbol['symbol_type'] ?? 'symbol') . '_renamed',
                'confidence' => $bestScore,
            ];
        }

        return $matches;
    }

    private function isNameSimilarEnough(string $a, string $b): bool
    {
        if ($a === $b) return true;

        $lenA = strlen($a);
        $lenB = strlen($b);
        if ($lenA < 3 || $lenB < 3) return false;
        if (abs($lenA - $lenB) > 5) return false;

        // Quick prefix check - if first 3 chars differ significantly, skip
        if ($lenA >= 3 && $lenB >= 3 && substr($a, 0, 3) !== substr($b, 0, 3)) {
            // Only expensive check if we have some similarity
            // This avoids levenshtein for completely different names
        }

        // Fast similarity check
        return $this->similarity($a, $b, $lenA, $lenB) > 0.7;
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     */
    private function score(array $old, array $new, bool $includeSource = true): float
    {
        if ($old['fqn'] === $new['fqn']) return 0.0;

        $score = 0.0;

        $oldSig = (string) ($old['signature_json'] ?? '');
        $newSig = (string) ($new['signature_json'] ?? '');
        if ($oldSig !== '' && $oldSig === $newSig) {
            $score += 0.50;
        }

        $oldName = $this->shortName($old);
        $newName = $this->shortName($new);
        $nameSimilarity = $this->similarity($oldName, $newName, strlen($oldName), strlen($newName));
        $score += 0.30 * $nameSimilarity;

        // Skip expensive source similarity if names are very different
        if ($includeSource && $score > 0.4 && $nameSimilarity > 0.5) {
            $oldNamespace = $this->namespace((string) ($old['fqn'] ?? ''));
            $newNamespace = $this->namespace((string) ($new['fqn'] ?? ''));
            if ($oldNamespace !== '' && $oldNamespace === $newNamespace) {
                $score += 0.10;
            }

            $sourceSimilarity = $this->sourceSimilarity($old, $new, $oldName, $newName);
            $score += 0.10 * $sourceSimilarity;
        }

        return min(1.0, $score);
    }

    /**
     * @param array<string, mixed> $symbol
     */
    private function shortName(array $symbol): string
    {
        $name = (string) ($symbol['name'] ?? '');
        if ($name !== '') return $name;

        $fqn = (string) ($symbol['fqn'] ?? '');
        if ($fqn === '') return '';

        $segments = preg_split('/[\\\\:]/', $fqn);
        return (string) end($segments);
    }

    private function namespace(string $fqn): string
    {
        if ($fqn === '') return '';
        if (str_contains($fqn, '::')) {
            $parts = explode('::', $fqn, 2);
            $fqn = $parts[0];
        }
        if (!str_contains($fqn, '\\')) return '';
        $parts = explode('\\', $fqn);
        array_pop($parts);
        return implode('\\', $parts);
    }

    private function similarity(string $a, string $b, ?int $lenA = null, ?int $lenB = null): float
    {
        if ($a === '' || $b === '') return 0.0;
        if ($a === $b) return 1.0;

        $lenA ??= strlen($a);
        $lenB ??= strlen($b);
        $maxLen = max($lenA, $lenB);
        if ($maxLen === 0) return 0.0;

        // For very short strings, exact match or nothing
        if ($maxLen < 4) {
            return $a === $b ? 1.0 : 0.0;
        }

        // For long strings, use similar_text (more accurate but slower)
        if ($maxLen > 255) {
            similar_text($a, $b, $percent);
            return $percent / 100.0;
        }

        $distance = levenshtein($a, $b);
        $normalized = 1.0 - ($distance / $maxLen);
        return max(0.0, min(1.0, $normalized));
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     */
    private function sourceSimilarity(array $old, array $new, string $oldName, string $newName): float
    {
        $oldSource = (string) ($old['source_text'] ?? '');
        $newSource = (string) ($new['source_text'] ?? '');
        if ($oldSource === '' || $newSource === '') return 0.0;
        if (strlen($oldSource) > 1000 || strlen($newSource) > 1000) return 0.1; 
        $normalizedOld = $this->normalizeSource($oldSource, $oldName);
        $normalizedNew = $this->normalizeSource($newSource, $newName);
        return $this->similarity($normalizedOld, $normalizedNew);
    }

    private function normalizeSource(string $source, string $name): string
    {
        if ($name === '') return preg_replace('/\s+/', ' ', trim($source)) ?? $source;
        $pattern = '/\b' . preg_quote($name, '/') . '\b/';
        $replaced = preg_replace($pattern, '__RENAMED__', $source) ?? $source;
        return preg_replace('/\s+/', ' ', trim($replaced)) ?? $replaced;
    }
}
