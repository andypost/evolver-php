<?php

declare(strict_types=1);

namespace DrupalEvolver\Differ;

use DrupalEvolver\Symbol\SymbolType;

class YAMLDiffer
{
    /**
     * @param array<int, array<string, mixed>> $removed
     * @return array<int, YamlChange>
     */
    public function findRemovedChanges(array $removed): array
    {
        $changes = [];

        foreach ($removed as $symbol) {
            if (($symbol['language'] ?? null) !== 'yaml') {
                continue;
            }

            $symbolType = SymbolType::fromSymbol($symbol);
            if ($symbolType === null) {
                continue;
            }

            $changeType = $this->removalChangeType($symbolType);
            if ($changeType === null) {
                continue;
            }

            $changes[] = YamlChange::breaking($changeType, $symbol);
        }

        return $changes;
    }

    /**
     * @param array<int, array<string, mixed>> $removed
     * @param array<int, array<string, mixed>> $added
     * @return array<int, YamlChange>
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

            $symbolType = SymbolType::fromSymbol($oldSymbol);
            $changes[] = YamlChange::breaking(
                $symbolType === null ? 'symbol_renamed' : $this->renameChangeType($symbolType),
                $oldSymbol,
                $best,
                null,
                $bestScore
            );
        }

        return $changes;
    }

    /**
     * @param array<int, array{old: array<string, mixed>, new: array<string, mixed>}> $changed
     * @return array<int, YamlChange>
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
            $changes[] = YamlChange::breaking($changeType, $old, $new, $details);
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

        if (SymbolType::valueFromSymbol($old) !== SymbolType::valueFromSymbol($new)) {
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

    private function removalChangeType(SymbolType $symbolType): ?string
    {
        return match ($symbolType) {
            SymbolType::Service => 'service_removed',
            SymbolType::Route, SymbolType::DrupalRoute => 'route_removed',
            SymbolType::Permission, SymbolType::DrupalPermission => 'permission_removed',
            SymbolType::ConfigSchema => 'config_key_removed',
            SymbolType::ModuleInfo => 'module_info_removed',
            SymbolType::ThemeInfo => 'theme_info_removed',
            SymbolType::ProfileInfo => 'profile_info_removed',
            SymbolType::ThemeEngineInfo => 'theme_engine_info_removed',
            SymbolType::LinkMenu => 'link_menu_removed',
            SymbolType::LinkTask => 'link_task_removed',
            SymbolType::LinkAction => 'link_action_removed',
            SymbolType::LinkContextual => 'link_contextual_removed',
            SymbolType::ConfigExport => 'config_object_removed',
            SymbolType::RecipeManifest => 'recipe_removed',
            default => null,
        };
    }

    private function renameChangeType(SymbolType $symbolType): string
    {
        return match ($symbolType) {
            SymbolType::Service => 'service_renamed',
            SymbolType::ConfigSchema => 'config_key_renamed',
            SymbolType::ConfigExport => 'config_object_renamed',
            SymbolType::RecipeManifest => 'recipe_renamed',
            default => $symbolType->value . '_renamed',
        };
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     */
    private function changedChangeType(array $old, array $new): ?string
    {
        $symbolType = SymbolType::fromSymbol($old);
        if ($symbolType === null) {
            return null;
        }

        return match ($symbolType) {
            SymbolType::Service => $this->serviceChangeType($old, $new),
            SymbolType::Route, SymbolType::DrupalRoute => 'route_changed',
            SymbolType::Library, SymbolType::DrupalLibrary => 'library_changed',
            SymbolType::ModuleInfo => $this->infoChangeType($symbolType, $old, $new),
            SymbolType::ProfileInfo => $this->infoChangeType($symbolType, $old, $new),
            SymbolType::ThemeInfo => $this->infoChangeType($symbolType, $old, $new),
            SymbolType::ThemeEngineInfo => 'theme_engine_info_changed',
            SymbolType::LinkMenu => 'link_menu_changed',
            SymbolType::LinkTask => 'link_task_changed',
            SymbolType::LinkAction => 'link_action_changed',
            SymbolType::LinkContextual => 'link_contextual_changed',
            SymbolType::ConfigExport => 'config_object_changed',
            SymbolType::RecipeManifest => $this->recipeChangeType($old, $new),
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
        $symbolType = SymbolType::fromSymbol($old);
        $oldSig = $this->decodeSignature($old);
        $newSig = $this->decodeSignature($new);

        $details = match ($symbolType) {
            SymbolType::Service => YamlDiffDetails::service(
                $oldSig['class'] ?? null,
                $newSig['class'] ?? null,
                $oldSig['arguments'] ?? null,
                $newSig['arguments'] ?? null,
                $oldSig['tags'] ?? null,
                $newSig['tags'] ?? null,
            ),
            SymbolType::Route, SymbolType::DrupalRoute => YamlDiffDetails::route(
                $oldSig['path'] ?? null,
                $newSig['path'] ?? null,
                $oldSig['controller'] ?? null,
                $newSig['controller'] ?? null,
            ),
            SymbolType::ModuleInfo, SymbolType::ProfileInfo, SymbolType::ThemeInfo, SymbolType::ThemeEngineInfo => 
                $this->buildInfoDiffDetails($oldSig, $newSig),
            SymbolType::LinkMenu, SymbolType::LinkTask, SymbolType::LinkAction, SymbolType::LinkContextual => 
                $this->buildStructuredDiffDetails($oldSig, $newSig),
            SymbolType::ConfigExport => $this->buildConfigDiffDetails($oldSig, $newSig),
            SymbolType::RecipeManifest => $this->buildRecipeDiffDetails($oldSig, $newSig),
            default => new YamlDiffDetails([
                'old_signature' => $oldSig,
                'new_signature' => $newSig,
            ]),
        };

        return $details->toArray();
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     */
    private function infoChangeType(SymbolType $symbolType, array $old, array $new): string
    {
        $oldSig = $this->decodeSignature($old);
        $newSig = $this->decodeSignature($new);
        $oldDeps = $this->normalizeStringList($oldSig['dependencies'] ?? []);
        $newDeps = $this->normalizeStringList($newSig['dependencies'] ?? []);

        if ($oldDeps !== $newDeps) {
            return match ($symbolType) {
                SymbolType::ModuleInfo => 'module_dependencies_changed',
                SymbolType::ProfileInfo => 'profile_dependencies_changed',
                default => $symbolType->value . '_changed',
            };
        }

        if ($symbolType === SymbolType::ThemeInfo && ($oldSig['base theme'] ?? null) !== ($newSig['base theme'] ?? null)) {
            return 'theme_base_changed';
        }

        return $symbolType->value . '_changed';
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     */
    private function recipeChangeType(array $old, array $new): string
    {
        $oldSig = $this->decodeSignature($old);
        $newSig = $this->decodeSignature($new);

        if ($this->normalizeStringList($oldSig['install'] ?? []) !== $this->normalizeStringList($newSig['install'] ?? [])) {
            return 'recipe_install_changed';
        }

        return 'recipe_changed';
    }

    /**
     * @param array<string, mixed> $oldSig
     * @param array<string, mixed> $newSig
     */
    private function buildInfoDiffDetails(array $oldSig, array $newSig): YamlDiffDetails
    {
        $oldDeps = $this->normalizeStringList($oldSig['dependencies'] ?? []);
        $newDeps = $this->normalizeStringList($newSig['dependencies'] ?? []);

        return YamlDiffDetails::info(
            $this->changedTopLevelKeys($oldSig, $newSig),
            $oldDeps,
            $newDeps,
            array_values(array_diff($newDeps, $oldDeps)),
            array_values(array_diff($oldDeps, $newDeps)),
            $oldSig['configure'] ?? null,
            $newSig['configure'] ?? null,
            $oldSig['base theme'] ?? null,
            $newSig['base theme'] ?? null,
        );
    }

    /**
     * @param array<string, mixed> $oldSig
     * @param array<string, mixed> $newSig
     */
    private function buildStructuredDiffDetails(array $oldSig, array $newSig): YamlDiffDetails
    {
        return new YamlDiffDetails([
            'changed_keys' => $this->changedTopLevelKeys($oldSig, $newSig),
            'old_signature' => $oldSig,
            'new_signature' => $newSig,
        ]);
    }

    /**
     * @param array<string, mixed> $oldSig
     * @param array<string, mixed> $newSig
     */
    private function buildConfigDiffDetails(array $oldSig, array $newSig): YamlDiffDetails
    {
        $oldDeps = is_array($oldSig['dependencies'] ?? null) ? $oldSig['dependencies'] : [];
        $newDeps = is_array($newSig['dependencies'] ?? null) ? $newSig['dependencies'] : [];

        return YamlDiffDetails::config(
            array_values(array_diff(array_keys($newSig), array_keys($oldSig))),
            array_values(array_diff(array_keys($oldSig), array_keys($newSig))),
            $this->changedTopLevelKeys($oldSig, $newSig),
            $oldDeps,
            $newDeps,
        );
    }

    /**
     * @param array<string, mixed> $oldSig
     * @param array<string, mixed> $newSig
     */
    private function buildRecipeDiffDetails(array $oldSig, array $newSig): YamlDiffDetails
    {
        $oldInstall = $this->normalizeStringList($oldSig['install'] ?? []);
        $newInstall = $this->normalizeStringList($newSig['install'] ?? []);
        $oldRecipes = $this->normalizeStringList($oldSig['recipes'] ?? []);
        $newRecipes = $this->normalizeStringList($newSig['recipes'] ?? []);

        return YamlDiffDetails::recipe(
            $this->changedTopLevelKeys($oldSig, $newSig),
            array_values(array_diff($newInstall, $oldInstall)),
            array_values(array_diff($oldInstall, $newInstall)),
            array_values(array_diff($newRecipes, $oldRecipes)),
            array_values(array_diff($oldRecipes, $newRecipes)),
        );
    }

    /**
     * @param array<string, mixed> $oldSig
     * @param array<string, mixed> $newSig
     * @return array<int, string>
     */
    private function changedTopLevelKeys(array $oldSig, array $newSig): array
    {
        $keys = array_values(array_unique(array_merge(array_keys($oldSig), array_keys($newSig))));
        sort($keys);

        $changed = [];
        foreach ($keys as $key) {
            if (($oldSig[$key] ?? null) !== ($newSig[$key] ?? null)) {
                $changed[] = $key;
            }
        }

        return $changed;
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (!is_scalar($item) && $item !== null) {
                continue;
            }

            $string = trim((string) $item);
            if ($string !== '') {
                $items[] = $string;
            }
        }

        $items = array_values(array_unique($items));
        sort($items);

        return $items;
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
