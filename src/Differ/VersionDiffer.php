<?php

declare(strict_types=1);

namespace DrupalEvolver\Differ;

use DrupalEvolver\Pattern\QueryGenerator;
use DrupalEvolver\Storage\DatabaseApi;
use DrupalEvolver\Symbol\SymbolType;

class VersionDiffer
{
    private int $workerCount = 4;
    private bool $skipRenames = false;
    private ?string $pathFilter = null;

    public function __construct(
        private DatabaseApi $api,
        private SignatureDiffer $signatureDiffer,
        private RenameMatcher $renameMatcher,
        private YAMLDiffer $yamlDiffer,
        private FixTemplateGenerator $fixTemplateGenerator,
        private QueryGenerator $queryGenerator,
        private ?LibraryDiffer $libraryDiffer = null,
    ) {
        $this->workerCount = $this->detectCpuCount();
        $this->libraryDiffer ??= new LibraryDiffer($api);
    }

    public function setWorkerCount(int $count): void
    {
        $this->workerCount = max(1, $count);
    }

    public function setSkipRenames(bool $skip): void
    {
        $this->skipRenames = $skip;
    }

    public function setPathFilter(?string $path): void
    {
        $this->pathFilter = $path;
    }

    public function diff(string $fromTag, string $toTag, ?\Symfony\Component\Console\Output\OutputInterface $output = null): array
    {
        $fromVersion = $this->api->versions()->findByTag($fromTag);
        $toVersion = $this->api->versions()->findByTag($toTag);

        if (!$fromVersion || !$toVersion) {
            throw new \InvalidArgumentException('Both versions must be indexed first');
        }

        $fromId = (int) $fromVersion['id'];
        $toId = (int) $toVersion['id'];

        $output?->writeln("<info>Calculating diff between {$fromTag} and {$toTag}...</info>");

        // Clear existing changes for this pair
        $deletedChanges = $this->api->deleteChangesForPair($fromId, $toId);
        if ($deletedChanges > 0) {
            $output?->writeln(sprintf("<comment>Cleared %d existing changes</comment>", $deletedChanges));
        }

        $changes = [];
        $consumedRemoved = [];

        // 1. Detect removed and added symbols (Generators)
        $output?->write("Finding removed/added symbols... ");
        $removedGen = $this->api->findRemovedSymbols($fromId, $toId, $this->pathFilter);
        $addedGen = $this->api->findAddedSymbols($fromId, $toId, $this->pathFilter);

        // We need them as arrays IF we do rename matching
        if (!$this->skipRenames) {
            $removed = iterator_to_array($removedGen);
            $added = iterator_to_array($addedGen);
            $output?->writeln(sprintf("<comment>%d removed, %d added</comment>", count($removed), count($added)));

            $output?->write("Matching renames... ");
            if (count($removed) > 100 && $this->workerCount > 1 && function_exists('pcntl_fork')) {
                $renameMatches = $this->matchRenamesParallel($removed, $added, $output);
            } else {
                $renameMatches = $this->renameMatcher->match($removed, $added);
            }
            $output?->writeln(sprintf("<comment>%d renames found</comment>", count($renameMatches)));

            foreach ($renameMatches as $rename) {
                $old = $rename['old'] ?? null;
                $new = $rename['new'] ?? null;
                if (!$old || !$new) continue;

                $oldId = $this->symbolId($old);
                if ($oldId !== null) $consumedRemoved[$oldId] = true;

                $oldType = SymbolType::valueFromSymbol($old, 'symbol');
                $changeType = (string) ($rename['change_type'] ?? ($oldType . '_renamed'));
                $migrationHint = sprintf('This %s was renamed to %s.', str_replace('_', ' ', $oldType), $new['fqn']);
                
                $changes[] = [
                    'from_version_id' => $fromId,
                    'to_version_id' => $toId,
                    'language' => $old['language'],
                    'change_type' => $changeType,
                    'severity' => 'breaking',
                    'old_symbol_id' => $old['id'] ?? null,
                    'new_symbol_id' => $new['id'] ?? null,
                    'old_fqn' => $old['fqn'] ?? null,
                    'new_fqn' => $new['fqn'] ?? null,
                    'confidence' => $rename['confidence'] ?? 0.8,
                    'migration_hint' => $migrationHint,
                    'ts_query' => $this->queryGenerator->generate($changeType, $old),
                    'fix_template' => $this->fixTemplateGenerator->generate($changeType, $old, $new),
                ];
            }

            // Re-assign for further use
            $removedPool = $removed;
        } else {
            $output?->writeln("<comment>streaming</comment>");
            $output?->writeln("Matching renames... <comment>skipped</comment>");
            $renameMatches = [];
            $removedPool = $removedGen;
        }

        // 1b. YAML rename detection (Requires array)
        if (!is_array($removedPool)) {
             $removedPool = iterator_to_array($removedPool);
             $addedPool = iterator_to_array($addedGen);
        } else {
             $addedPool = $added;
        }

        $output?->write("Checking YAML renames... ");
        $yamlRenames = $this->yamlDiffer->findRenameChanges($removedPool, $addedPool);
        $output?->writeln(sprintf("<comment>%d YAML renames found</comment>", count($yamlRenames)));

        foreach ($yamlRenames as $rename) {
            $old = $rename->oldSymbol ?? null;
            $new = $rename->newSymbol ?? null;
            if (!$old || !$new) continue;

            $oldId = $this->symbolId($old);
            if ($oldId !== null) $consumedRemoved[$oldId] = true;

            $changeType = $rename->changeType;
            $changes[] = [
                'from_version_id' => $fromId,
                'to_version_id' => $toId,
                'language' => $old['language'] ?? 'yaml',
                'change_type' => $changeType,
                'severity' => $rename->severity,
                'old_symbol_id' => $old['id'] ?? null,
                'new_symbol_id' => $new['id'] ?? null,
                'old_fqn' => $old['fqn'] ?? null,
                'new_fqn' => $new['fqn'] ?? null,
                'confidence' => $rename->confidence,
                'ts_query' => $this->queryGenerator->generate($changeType, $old),
                'fix_template' => $this->fixTemplateGenerator->generate($changeType, $old, $new),
            ];
        }

        // 1c. YAML removals.
        $yamlRemoved = $this->yamlDiffer->findRemovedChanges($removedPool);
        foreach ($yamlRemoved as $entry) {
            $old = $entry->oldSymbol ?? null;
            if (!$old) continue;

            $oldId = $this->symbolId($old);
            if ($oldId !== null && isset($consumedRemoved[$oldId])) continue;

            $changeType = $entry->changeType;
            $changes[] = [
                'from_version_id' => $fromId,
                'to_version_id' => $toId,
                'language' => $old['language'] ?? 'yaml',
                'change_type' => $changeType,
                'severity' => $entry->severity,
                'old_symbol_id' => $old['id'] ?? null,
                'old_fqn' => $old['fqn'] ?? null,
                'confidence' => $entry->confidence,
                'ts_query' => $this->queryGenerator->generate($changeType, $old),
            ];
        }

        // 1d. Remaining removals
        foreach ($removedPool as $sym) {
            if (($sym['language'] ?? null) === 'yaml') continue;

            $oldId = $this->symbolId($sym);
            if ($oldId !== null && isset($consumedRemoved[$oldId])) continue;
            if ((int) ($sym['is_deprecated'] ?? 0) === 1) continue;

            $symbolType = SymbolType::fromSymbol($sym);
            $changeType = $symbolType === SymbolType::DrupalEvent
                ? 'event_removed'
                : SymbolType::valueFromSymbol($sym, 'symbol') . '_removed';
            $migrationHint = $sym['deprecation_message'] ?? null;
            if (!$migrationHint && SymbolType::isHookLikeValue(SymbolType::valueFromSymbol($sym))) {
                $migrationHint = 'Procedural hooks are being deprecated. Consider migrating to #[Hook] or #[AsEventListener] attributes.';
            }

            $change = [
                'from_version_id' => $fromId,
                'to_version_id' => $toId,
                'language' => $sym['language'],
                'change_type' => $changeType,
                'severity' => 'breaking',
                'old_symbol_id' => $sym['id'],
                'old_fqn' => $sym['fqn'],
                'migration_hint' => $migrationHint,
                'ts_query' => $this->queryGenerator->generate($changeType, $sym),
            ];
            $changes[] = $change;
        }

        // 2. Find signature changes (Generator)
        $output?->write("Finding signature changes... ");
        $changedGen = $this->api->findChangedSignatures($fromId, $toId, $this->pathFilter);

        $signatureChangeCount = 0;
        $yamlChangedPairs = [];
        foreach ($changedGen as $pair) {
            if (($pair['old']['language'] ?? null) === 'yaml') {
                $yamlChangedPairs[] = $pair;
                continue;
            }

            $diffDetails = $this->signatureDiffer->diff($pair['old']['signature_json'], $pair['new']['signature_json']);
            if (empty($diffDetails)) continue;

            // Decode signature_json - needed for full params array in diff_json
            // The SQL json_extract() optimization helps with filtering, but full object still needs decode
            $oldSignature = json_decode((string) ($pair['old']['signature_json'] ?? '{}'), true);
            $newSignature = json_decode((string) ($pair['new']['signature_json'] ?? '{}'), true);
            $oldSignature = is_array($oldSignature) ? $oldSignature : [];
            $newSignature = is_array($newSignature) ? $newSignature : [];

            // Add param_count from SQL json_array_length() for downstream use
            if (isset($pair['old']['param_count'])) {
                $oldSignature['param_count'] = (int) $pair['old']['param_count'];
            }
            if (isset($pair['new']['param_count'])) {
                $newSignature['param_count'] = (int) $pair['new']['param_count'];
            }

            $changes[] = [
                'from_version_id' => $fromId,
                'to_version_id' => $toId,
                'language' => $pair['old']['language'],
                'change_type' => 'signature_changed',
                'severity' => 'breaking',
                'old_symbol_id' => $pair['old']['id'],
                'new_symbol_id' => $pair['new']['id'],
                'old_fqn' => $pair['old']['fqn'],
                'new_fqn' => $pair['new']['fqn'],
                'diff_json' => json_encode([
                    'changes' => $diffDetails,
                    'old' => $oldSignature,
                    'new' => $newSignature,
                ]),
                'ts_query' => $this->queryGenerator->generate('signature_changed', $pair['old']),
                'fix_template' => $this->fixTemplateGenerator->generate('signature_changed', $pair['old'], $pair['new'], $diffDetails),
            ];

            // 2c. Add inheritance impact for method signature changes
            if (SymbolType::fromSymbol($pair['old']) === SymbolType::Method && $pair['old']['parent_symbol']) {
                $changes[] = [
                    'from_version_id' => $fromId,
                    'to_version_id' => $toId,
                    'language' => $pair['old']['language'],
                    'change_type' => 'inheritance_impact',
                    'severity' => 'breaking',
                    'old_symbol_id' => $pair['old']['id'],
                    'new_symbol_id' => $pair['new']['id'],
                    'old_fqn' => $pair['old']['fqn'],
                    'new_fqn' => $pair['new']['fqn'],
                    'diff_json' => json_encode([
                        'changes' => $diffDetails,
                        'old' => $oldSignature,
                        'new' => $newSignature,
                    ]),
                    'ts_query' => $this->queryGenerator->generate('inheritance_impact', $pair['old']),
                ];
            }

            $signatureChangeCount++;
        }

        // 2b. YAML signature changes (service class changed, route changed, etc.)
        if (!empty($yamlChangedPairs)) {
            $yamlChanges = $this->yamlDiffer->findChangedChanges($yamlChangedPairs);
            foreach ($yamlChanges as $yamlChange) {
                $old = $yamlChange->oldSymbol ?? [];
                $new = $yamlChange->newSymbol ?? [];
                $changeType = $yamlChange->changeType;
                $changes[] = [
                    'from_version_id' => $fromId,
                    'to_version_id' => $toId,
                    'language' => 'yaml',
                    'change_type' => $changeType,
                    'severity' => $yamlChange->severity,
                    'old_symbol_id' => $old['id'] ?? null,
                    'new_symbol_id' => $new['id'] ?? null,
                    'old_fqn' => $old['fqn'] ?? null,
                    'new_fqn' => $new['fqn'] ?? null,
                    'diff_json' => $yamlChange->diffDetails !== null ? json_encode($yamlChange->diffDetails) : null,
                    'confidence' => $yamlChange->confidence,
                    'ts_query' => $this->queryGenerator->generate($changeType, $old),
                    'fix_template' => $this->fixTemplateGenerator->generate($changeType, $old, $new),
                ];
                $signatureChangeCount++;
            }
        }

        $output?->writeln(sprintf("<comment>%d changes</comment>", $signatureChangeCount));

        // 3. Track deprecation lifecycle.
        $output?->write("Tracking deprecations... ");
        $depChanges = $this->trackDeprecations($fromId, $toId);
        $output?->writeln(sprintf("<comment>%d deprecation changes</comment>", count($depChanges)));

        foreach ($depChanges as $dep) {
            $symbolId = $dep['new_symbol_id'] ?? $dep['old_symbol_id'];
            $sym = $this->api->findSymbolById((int) $symbolId);
            if ($sym) {
                $dep['ts_query'] = $this->queryGenerator->generate($dep['change_type'], $sym);
            }
            $changes[] = $dep;
        }

        // 4. Library diffing
        $output?->write("Diffing libraries... ");
        $libChanges = $this->libraryDiffer->diffLibraries($fromId, $toId);
        foreach ($libChanges as $libChange) {
                $libChange['ts_query'] = $this->queryGenerator->generate(
                    $libChange['change_type'],
                    [
                        'symbol_type' => SymbolType::DrupalLibrary,
                        'fqn' => $libChange['old_fqn'] ?? $libChange['new_fqn'] ?? '',
                        'name' => $libChange['old_fqn'] ?? $libChange['new_fqn'] ?? '',
                    ]
                );
            $changes[] = $libChange;
        }
        $output?->writeln(sprintf("<comment>%d library changes</comment>", count($libChanges)));

        // 5. Hook modernization detection
        $output?->write("Detecting hook modernization opportunities... ");
        $hookChanges = $this->detectHookModernization($fromId, $toId);
        array_push($changes, ...$hookChanges);
        $output?->writeln(sprintf("<comment>%d hook suggestions</comment>", count($hookChanges)));

        // Store all changes
        $output?->write("Storing changes... ");
        $storedChanges = $this->api->db()->transaction(function () use ($changes): int {
            return $this->api->changes()->createBatch($changes);
        });
        if ($storedChanges !== count($changes)) {
            throw new \RuntimeException(sprintf(
                'Expected to store %d changes, but persisted %d.',
                count($changes),
                $storedChanges
            ));
        }
        $output?->writeln(sprintf("<info>Done! Stored %d changes.</info>", $storedChanges));

        return $changes;
    }

    private function trackDeprecations(int $fromId, int $toId): array
    {
        $changes = [];

        $newlyDeprecated = $this->api->findNewlyDeprecated($fromId, $toId, $this->pathFilter);
        foreach ($newlyDeprecated as $sym) {
            $migrationHint = $sym['deprecation_message'] ?? null;
            if (!$migrationHint && SymbolType::isHookLikeValue(SymbolType::valueFromSymbol($sym))) {
                $migrationHint = 'This hook is newly deprecated. Consider migrating to #[Hook] or #[AsEventListener] attributes.';
            }

            $changes[] = [
                'from_version_id' => $fromId,
                'to_version_id' => $toId,
                'language' => $sym['language'],
                'change_type' => 'deprecated_added',
                'severity' => 'deprecation',
                'new_symbol_id' => $sym['id'],
                'old_fqn' => $sym['fqn'],
                'new_fqn' => $sym['fqn'],
                'migration_hint' => $migrationHint,
            ];
        }

        $deprecatedRemoved = $this->api->findDeprecatedThenRemoved($fromId, $toId, $this->pathFilter);
        foreach ($deprecatedRemoved as $sym) {
            $migrationHint = $sym['deprecation_message'] ?? null;
            if (!$migrationHint && SymbolType::isHookLikeValue(SymbolType::valueFromSymbol($sym))) {
                $migrationHint = 'This hook was removed. You must migrate to #[Hook] or #[AsEventListener] attributes.';
            }

            $changes[] = [
                'from_version_id' => $fromId,
                'to_version_id' => $toId,
                'language' => $sym['language'],
                'change_type' => SymbolType::valueFromSymbol($sym, 'symbol') . '_removed',
                'severity' => 'removal',
                'old_symbol_id' => $sym['id'],
                'old_fqn' => $sym['fqn'],
                'migration_hint' => $migrationHint,
            ];
        }

        return $changes;
    }

    private function matchRenamesParallel(array $removed, array $added, ?\Symfony\Component\Console\Output\OutputInterface $output): array
    {
        // Pre-group by language and type to send only relevant data to workers
        $removedByLangType = [];
        foreach ($removed as $sym) {
            $lang = (string) ($sym['language'] ?? 'unknown');
            $type = SymbolType::valueFromSymbol($sym, 'unknown');
            $removedByLangType[$lang][$type][] = $sym;
        }

        $addedByLangType = [];
        foreach ($added as $sym) {
            $lang = (string) ($sym['language'] ?? 'unknown');
            $type = SymbolType::valueFromSymbol($sym, 'unknown');
            $addedByLangType[$lang][$type][] = $sym;
        }

        $workItems = [];
        foreach ($removedByLangType as $lang => $types) {
            foreach ($types as $type => $symbols) {
                $addedPool = $addedByLangType[$lang][$type] ?? [];
                if (empty($addedPool)) continue;

                $chunks = array_chunk($symbols, 500);
                foreach ($chunks as $chunk) {
                    $workItems[] = [
                        'removed' => $chunk,
                        'added' => $addedPool
                    ];
                }
            }
        }

        $pids = [];
        $tempFiles = [];
        $activeWorkers = 0;

        foreach ($workItems as $i => $item) {
            while ($activeWorkers >= $this->workerCount) {
                $pid = pcntl_wait($status);
                if ($pid > 0) {
                    $activeWorkers--;
                }
            }

            $tempFile = tempnam(sys_get_temp_dir(), "rename_matches_{$i}");
            $tempFiles[] = $tempFile;

            $pid = pcntl_fork();
            if ($pid === -1) throw new \RuntimeException("Could not fork worker for rename matching");

            if ($pid === 0) {
                $matcher = new RenameMatcher();
                $matches = $matcher->match($item['removed'], $item['added']);
                file_put_contents($tempFile, \igbinary_serialize($matches));
                exit(0);
            } else {
                $pids[] = $pid;
                $activeWorkers++;
            }
        }

        while ($activeWorkers > 0) {
            $pid = pcntl_wait($status);
            if ($pid > 0) $activeWorkers--;
        }

        $allMatches = [];
        foreach ($tempFiles as $file) {
            if (file_exists($file)) {
                $content = file_get_contents($file);
                if ($content) {
                    $matches = \igbinary_unserialize($content);
                    if (is_array($matches)) $allMatches = array_merge($allMatches, $matches);
                }
                @unlink($file);
            }
        }

        return $allMatches;
    }

    /**
     * Detect procedural hooks that could be migrated to #[Hook] attributes.
     *
     * Finds procedural hook implementations in the "from" version that have
     * corresponding #[Hook] attribute implementations in the "to" version,
     * indicating the hook system supports attribute-based registration.
     *
     * @return list<array<string, mixed>>
     */
    private function detectHookModernization(int $fromId, int $toId): array
    {
        $changes = [];

        // Get all hook symbols from both versions
        $fromHooks = $this->api->symbols()->findByTypeAndVersion($fromId, SymbolType::Hook);
        $toHooks = $this->api->symbols()->findByTypeAndVersion($toId, SymbolType::Hook);

        // Build sets of hook names by implementation style
        $fromProcedural = [];
        $toAttributeBased = [];

        foreach ($fromHooks as $hook) {
            $namespace = $hook['namespace'] ?? null;
            // Procedural hooks have no namespace (they're in .module files)
            if ($namespace === null || $namespace === '') {
                $fromProcedural[$hook['fqn']] = $hook;
            }
        }

        foreach ($toHooks as $hook) {
            $namespace = $hook['namespace'] ?? null;
            // Attribute-based hooks have a namespace (they're in classes)
            if ($namespace !== null && $namespace !== '') {
                $toAttributeBased[$hook['fqn']] = true;
            }
        }

        // For each procedural hook that now also has an attribute-based version,
        // generate a modernization suggestion
        foreach ($fromProcedural as $hookName => $hook) {
            if (isset($toAttributeBased[$hookName])) {
                $changes[] = [
                    'from_version_id' => $fromId,
                    'to_version_id' => $toId,
                    'language' => 'php',
                    'change_type' => 'hook_to_attribute',
                    'severity' => 'modernization',
                    'old_symbol_id' => $hook['id'],
                    'old_fqn' => $hookName,
                    'new_fqn' => $hookName,
                    'confidence' => 0.9,
                    'migration_hint' => "Hook '{$hookName}' can be converted from procedural implementation to #[Hook('{$hookName}')] attribute on a class method. This improves discoverability and follows modern Drupal conventions.",
                    'ts_query' => $this->queryGenerator->generate('hook_to_attribute', ['symbol_type' => SymbolType::FunctionSymbol, 'fqn' => $hookName, 'name' => $hookName]),
                ];
            }
        }

        // Also flag procedural hooks that were removed in the target version
        // (already handled by the main removal detection, but we add a more specific hint)

        return $changes;
    }

    private function symbolId(array $symbol): ?int
    {
        return isset($symbol['id']) ? (int) $symbol['id'] : null;
    }

    private function detectCpuCount(): int
    {
        if (is_readable('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            preg_match_all('/^processor/m', $cpuinfo, $matches);
            return count($matches[0]);
        }
        return 4;
    }
}
