<?php

declare(strict_types=1);

namespace DrupalEvolver\Scanner;

use DrupalEvolver\Adapter\DrupalCoreAdapter;
use DrupalEvolver\Indexer\FileClassifier;
use DrupalEvolver\Storage\Database;
use DrupalEvolver\Storage\DatabaseApi;
use DrupalEvolver\Storage\Repository\MatchRepo;
use DrupalEvolver\Storage\Schema;
use DrupalEvolver\TreeSitter\Parser;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

final class ProjectScanner
{
    private FileClassifier $classifier;
    private ProjectTypeDetector $typeDetector;
    private int $workerCount = 1;

    public function __construct(
        private Parser $parser,
        private DatabaseApi $api,
        private MatchCollector $matchCollector,
    ) {
        $this->classifier = new FileClassifier(new DrupalCoreAdapter());
        $this->typeDetector = new ProjectTypeDetector();
        $this->workerCount = $this->api->getPath() === ':memory:' ? 1 : $this->detectCpuCount();
    }

    public function setWorkerCount(int $count): void
    {
        $this->workerCount = max(1, $count);
    }

    #[\NoDiscard]
    public function scan(
        string $path,
        string $targetVersion,
        ?string $fromVersion = null,
        ?OutputInterface $output = null,
        ?callable $onProgress = null,
    ): int {
        $path = rtrim(realpath($path) ?: $path, '/');
        $projectName = basename($path);

        // Detect metadata upfront
        $metadata = $this->typeDetector->detectMetadata($path);
        $projectId = $this->api->projects()->save(
            $projectName,
            $path,
            $metadata['type'],
            $fromVersion,
            'local_path',
            null,
            null,
            $metadata['package_name'],
            $metadata['root_name']
        );
        $scanRunId = $this->api->scanRuns()->create($projectId, $projectName, null, $path, $fromVersion, $targetVersion);

        try {
            $_ = $this->scanIntoProject($projectId, $scanRunId, $path, $targetVersion, $fromVersion, $output, $onProgress);
        } catch (\Throwable $e) {
            $this->api->scanRuns()->markFailed($scanRunId, $e->getMessage());
            throw $e;
        }

        return $scanRunId;
    }

    #[\NoDiscard]
    public function scanIntoProject(
        int $projectId,
        ?int $scanRunId,
        string $path,
        string $targetVersion,
        ?string $fromVersion = null,
        ?OutputInterface $output = null,
        ?callable $onProgress = null,
    ): int {
        $path = rtrim(realpath($path) ?: $path, '/');
        $projectName = basename($path);

        if (!$fromVersion) {
            $detector = new VersionDetector();
            $fromVersion = $detector->detect($path);
            if ($fromVersion) {
                $output?->writeln("Detected current version: <info>{$fromVersion}</info>");
            }
        }

        if (!$fromVersion) {
            throw new \InvalidArgumentException('Could not detect current version. Use --from to specify.');
        }

        if (!$this->api->versions()->findByTag($fromVersion)) {
            $closest = $this->api->versions()->findClosest($fromVersion);
            if ($closest) {
                $output?->writeln(sprintf("<comment>Warning: Detected core version %s is not indexed. Using closest available version: %s</comment>", $fromVersion, $closest['tag']));
                $fromVersion = $closest['tag'];
            }
        }

        $fromVer = $this->api->versions()->findByTag($fromVersion);
        $toVer = $this->api->versions()->findByTag($targetVersion);
        if (!$fromVer || !$toVer) {
            throw new \InvalidArgumentException('Both versions must be indexed first');
        }

        // Auto-detect and update project metadata if not set
        $project = $this->api->projects()->findById($projectId);
        if ($project !== null && ($project['type'] ?? null) === null) {
            $metadata = $this->typeDetector->detectMetadata($path);
            if ($metadata['type'] !== null) {
                $this->api->projects()->updateMetadata(
                    $projectId,
                    $metadata['type'],
                    $metadata['package_name'],
                    $metadata['root_name']
                );
                $output?->writeln("Detected project type: <info>{$metadata['type']}</info>");
                if ($metadata['package_name'] !== null) {
                    $output?->writeln("Package name: <info>{$metadata['package_name']}</info>");
                }
                if ($metadata['root_name'] !== null) {
                    $output?->writeln("Root name: <info>{$metadata['root_name']}</info>");
                }
            }
        }

        if ($this->versionWeight($fromVer) > $this->versionWeight($toVer)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid version range: --from=%s is higher than --target=%s',
                $fromVersion,
                $targetVersion
            ));
        }

        $changes = $this->api->changes()->findForUpgradePath((int) $fromVer['id'], (int) $toVer['id']);
        $output?->writeln(sprintf('Loaded <info>%d</info> changes to scan for', count($changes)));

        $this->api->projects()->updateCoreVersion($projectId, $fromVersion);

        $files = $this->collectFiles($path);
        if ($files === []) {
            $output?->writeln('<comment>No files found to scan in ' . $path . '</comment>');
            if ($scanRunId !== null) {
                $this->api->scanRuns()->markCompleted($scanRunId, 0, 0, [
                    'total' => 0,
                    'auto_fixable' => 0,
                    'by_severity' => [],
                    'by_change_type' => [],
                ]);
            }
            return 0;
        }

        if ($scanRunId !== null) {
            $this->api->scanRuns()->markRunning($scanRunId, null, $path, $fromVersion, count($files));
        }

        $changesByLanguage = [];
        foreach ($changes as $change) {
            $language = $change['language'] ?? null;
            if (!is_string($language) || $language === '') {
                continue;
            }
            $changesByLanguage[$language][] = $change;
        }

        $effectiveWorkerCount = $this->effectiveWorkerCount();
        $output?->writeln(sprintf(
            'Scanning <info>%d</info> files using <info>%d</info> worker%s',
            count($files),
            $effectiveWorkerCount,
            $effectiveWorkerCount === 1 ? '' : 's'
        ));
        $onProgress?->__invoke(0, count($files), 'Queued scan');

        if ($this->canRunParallel()) {
            $this->scanParallel($files, $changesByLanguage, $projectId, $scanRunId, $output, $onProgress, $toVer);
        } else {
            $this->scanSequential($files, $changesByLanguage, $projectId, $scanRunId, $output, $onProgress, $toVer);
        }

        $this->api->projects()->updateLastScanned($projectId);

        $matchCount = $scanRunId !== null
            ? $this->api->matches()->getTotalCountByRun($scanRunId)
            : $this->api->matches()->getTotalCountByProject($projectId);

        if ($scanRunId !== null) {
            $summary = $this->api->summarizeScanRun($scanRunId);
            $this->api->scanRuns()->markCompleted(
                $scanRunId,
                (int) $summary['total'],
                (int) $summary['auto_fixable'],
                $summary
            );
        }

        $output?->writeln('');
        $output?->writeln(sprintf('Found <info>%d</info> matches in project <info>%s</info>', $matchCount, $projectName));

        return $matchCount;
    }

    private function scanSequential(
        array $files,
        array $changesByLanguage,
        int $projectId,
        ?int $scanRunId,
        ?OutputInterface $output,
        ?callable $onProgress,
        ?array $toVer = null
    ): void {
        $progress = $output ? new ProgressBar($output, count($files)) : null;
        $progress?->start();

        $matchRepo = $this->api->matches();

        $scannedFiles = $this->api->db()->transaction(function () use (
            $files,
            $changesByLanguage,
            $projectId,
            $scanRunId,
            $progress,
            $matchRepo,
            $onProgress,
            $toVer
        ): int {
            $scannedFiles = 0;

            foreach ($files as $file) {
                $this->scanFile($file, $changesByLanguage, $projectId, $scanRunId, $this->parser, $this->matchCollector, $matchRepo, $toVer);
                $progress?->advance();
                $scannedFiles++;
                $onProgress?->__invoke($scannedFiles, count($files), $file['relative_path']);
                if ($scanRunId !== null) {
                    $this->api->scanRuns()->updateProgress($scanRunId, $scannedFiles, count($files));
                }
            }

            return $scannedFiles;
        });

        if ($scannedFiles !== count($files)) {
            throw new \RuntimeException('Sequential scan did not process the expected number of files.');
        }

        $progress?->finish();
    }

    private function scanParallel(
        array $files,
        array $changesByLanguage,
        int $projectId,
        ?int $scanRunId,
        ?OutputInterface $output,
        ?callable $onProgress,
        ?array $toVer = null
    ): void {
        $chunks = array_chunk($files, (int) ceil(count($files) / $this->workerCount));
        $pids = [];
        $workerDbs = [];
        $mainDbPath = $this->api->getPath();

        $output?->writeln('<info>Spawning workers with private databases...</info>');

        foreach ($chunks as $i => $chunk) {
            $workerDbPath = $mainDbPath . ".scan_worker{$i}";
            if (file_exists($workerDbPath)) {
                unlink($workerDbPath);
            }
            $workerDbs[] = $workerDbPath;

            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new \RuntimeException("Could not fork worker {$i}");
            }

            if ($pid === 0) {
                \DrupalEvolver\TreeSitter\FFIBinding::reset();

                $db = new Database($workerDbPath);
                (new Schema($db))->createAll();

                $parser = new Parser();
                $matchRepo = new MatchRepo($db);
                $matchCollector = new MatchCollector($parser->binding(), $parser->registry());

                $scannedChunk = $db->transaction(function () use ($chunk, $changesByLanguage, $projectId, $scanRunId, $parser, $matchCollector, $matchRepo, $toVer): int {
                    $counter = 0;

                    foreach ($chunk as $file) {
                        $this->scanFile($file, $changesByLanguage, $projectId, $scanRunId, $parser, $matchCollector, $matchRepo, $toVer);
                        $counter++;
                        if (($counter % 20) === 0) {
                            gc_collect_cycles();
                        }
                    }

                    return $counter;
                });

                if ($scannedChunk !== count($chunk)) {
                    throw new \RuntimeException('Parallel scan worker did not process the expected number of files.');
                }

                exit(0);
            }

            $pids[$pid] = [
                'index' => $i,
                'count' => count($chunk),
                'db' => $workerDbPath,
            ];
        }

        $completedFiles = 0;
        foreach ($pids as $pid => $workerInfo) {
            pcntl_waitpid($pid, $status);
            if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                throw new \RuntimeException(sprintf('Parallel scan worker %d failed.', $workerInfo['index']));
            }

            $completedFiles += (int) $workerInfo['count'];
            $onProgress?->__invoke($completedFiles, count($files), sprintf('Worker %d finished', $workerInfo['index'] + 1));
            if ($scanRunId !== null) {
                $this->api->scanRuns()->updateProgress($scanRunId, $completedFiles, count($files));
            }
        }

        $output?->write('<info>Merging scan results...</info> ');
        foreach ($workerDbs as $workerDbPath) {
            $this->mergeWorkerDb($workerDbPath);
            unlink($workerDbPath);
        }
        $output?->writeln('<info>Done!</info>');
    }

    private function mergeWorkerDb(string $workerDbPath): void
    {
        $mainPdo = $this->api->db()->pdo();
        $mainPdo->exec("ATTACH DATABASE '{$workerDbPath}' AS worker");

        $mainPdo->beginTransaction();
        try {
            $mainPdo->exec("INSERT OR REPLACE INTO code_matches (
                                project_id, scan_run_id, scope_key, change_id, file_path,
                                line_start, line_end, byte_start, byte_end,
                                matched_source, suggested_fix, fix_method, status, applied_at,
                                change_type, severity, old_fqn, migration_hint
                            )
                            SELECT project_id, scan_run_id, scope_key, change_id, file_path,
                                   line_start, line_end, COALESCE(byte_start, -1), COALESCE(byte_end, -1),
                                   matched_source, suggested_fix, fix_method, status, applied_at,
                                   change_type, severity, old_fqn, migration_hint
                            FROM worker.code_matches");
            $mainPdo->commit();
        } catch (\Throwable $e) {
            $mainPdo->rollBack();
            throw $e;
        } finally {
            $mainPdo->exec('DETACH DATABASE worker');
        }
    }

    private function scanFile(
        array $file,
        array $changesByLanguage,
        int $projectId,
        ?int $scanRunId,
        Parser $parser,
        MatchCollector $matchCollector,
        MatchRepo $matchRepo,
        ?array $toVer = null
    ): int {
        $filePath = $file['path'];
        $relativePath = $file['relative_path'];
        $language = $file['language'];

        $relevantChanges = $changesByLanguage[$language] ?? [];
        $libraryChanges = $changesByLanguage['drupal_libraries'] ?? [];
        
        $content = file_get_contents($filePath);
        if ($content === false) {
            return 0;
        }

        try {
            $matches = [];

            // Phase 1.2: Check for library usage breakage (no TreeSitter needed)
            if (($language === 'php' || $language === 'twig' || $language === 'yaml') && $libraryChanges !== []) {
                $assetExtractor = new \DrupalEvolver\Indexer\Extractor\AssetUsageExtractor();
                $usages = $assetExtractor->extract($relativePath, $content);
                
                foreach ($usages as $usage) {
                    foreach ($libraryChanges as $libChange) {
                        if ($libChange['old_fqn'] === $usage['name']) {
                            $matches[] = [
                                'project_id' => $projectId,
                                'scan_run_id' => $scanRunId,
                                'file_path' => $relativePath,
                                'change_id' => ($libChange['id'] ?? 0) > 0 ? $libChange['id'] : null,
                                'line_start' => $usage['line_start'],
                                'line_end' => $usage['line_end'],
                                'byte_start' => $usage['byte_start'],
                                'byte_end' => $usage['byte_end'],
                                'matched_source' => $usage['source_text'],
                                'fix_method' => 'manual',
                                'suggested_fix' => null,
                                'status' => 'pending',
                                'change_type' => $libChange['change_type'] ?? null,
                                'severity' => $libChange['severity'] ?? null,
                                'old_fqn' => $libChange['old_fqn'] ?? null,
                                'migration_hint' => $libChange['migration_hint'] ?? null,
                            ];
                        }
                    }
                }
            }

            $tree = $parser->parse($content, $language);
            $root = $tree->rootNode();

            if ($relevantChanges !== []) {
                foreach ($matchCollector->collectMatches($root, $content, $language, $relevantChanges) as $match) {
                    $match['project_id'] = $projectId;
                    $match['scan_run_id'] = $scanRunId;
                    $match['file_path'] = $relativePath;
                    $matches[] = $match;
                }
            }

            // Phase 1.3: Add modernization suggestions (hooks -> attributes, etc.)
            if ($language === 'php' && $toVer && $this->versionWeight($toVer) >= 11001000) {
                $modernization = $this->findModernizationOpportunities($root, $content, $relativePath);
                foreach ($modernization as $m) {
                    $m['project_id'] = $projectId;
                    $m['scan_run_id'] = $scanRunId;
                    $matches[] = $m;
                }
            }

            $persistedMatches = 0;
            if ($matches !== []) {
                $persistedMatches = $matchRepo->saveBatch($matches);
            }

            unset($tree, $root, $matches, $content);
            return $persistedMatches;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function findModernizationOpportunities(\DrupalEvolver\TreeSitter\Node $root, string $source, string $filePath): array
    {
        $matches = [];
        $isHookFile = str_ends_with($filePath, '.module') || str_ends_with($filePath, '.inc') || str_ends_with($filePath, '.install');

        $root->walk(function (\DrupalEvolver\TreeSitter\Node $node) use (&$matches, $isHookFile, $filePath) {
            $type = $node->type();

            // 1. Suggest #[Hook] for procedural hooks
            if ($type === 'function_definition' && $isHookFile) {
                $nameNode = $node->childByFieldName('name');
                if ($nameNode) {
                    $name = $nameNode->text();
                    $parts = explode('_', $name);
                    if (count($parts) > 1) {
                        $matches[] = [
                            'file_path' => $filePath,
                            'line_start' => $node->startPoint()['row'] + 1,
                            'line_end' => $node->endPoint()['row'] + 1,
                            'byte_start' => $node->startByte(),
                            'byte_end' => $node->endByte(),
                            'matched_source' => $name,
                            'change_id' => null,
                            'change_type' => 'procedural_to_attribute',
                            'severity' => 'info',
                            'old_fqn' => $name,
                            'migration_hint' => "Consider migrating this procedural hook to the #[Hook] attribute.",
                        ];
                    }
                }
            }

            // 2. Suggest conversion for legacy docblock plugins
            if ($type === 'class_declaration') {
                $docblock = $this->findDocblock($node);
                if ($docblock && preg_match('/@(\w+)\s*\(/', $docblock, $m)) {
                    $annotation = $m[1];
                    if (in_array($annotation, ['Block', 'QueueWorker', 'MigrateSource', 'ContentEntityType', 'ConfigEntityType'], true)) {
                        $matches[] = [
                            'file_path' => $filePath,
                            'line_start' => $node->startPoint()['row'] + 1,
                            'line_end' => $node->endPoint()['row'] + 1,
                            'byte_start' => $node->startByte(),
                            'byte_end' => $node->endByte(),
                            'matched_source' => "@{$annotation}",
                            'change_id' => null,
                            'change_type' => 'annotation_to_attribute',
                            'severity' => 'info',
                            'old_fqn' => "@{$annotation}",
                            'migration_hint' => "Legacy @{$annotation} annotation detected. Drupal 10.2+ supports native #[{$annotation}] attributes.",
                        ];
                    }
                }
            }
        });

        return $matches;
    }

    private function findDocblock(\DrupalEvolver\TreeSitter\Node $node): ?string
    {
        $curr = $node->prevSibling();
        while ($curr) {
            if ($curr->type() === 'comment' && str_starts_with($curr->text(), '/**')) return $curr->text();
            if ($curr->isNamed()) break;
            $curr = $curr->prevSibling();
        }
        return null;
    }

    private function versionWeight(array $version): int
    {
        if (isset($version['weight']) && $version['weight'] !== null) {
            return (int) $version['weight'];
        }

        $major = (int) ($version['major'] ?? 0);
        $minor = (int) ($version['minor'] ?? 0);
        $patch = (int) ($version['patch'] ?? 0);

        return ($major * 1000000) + ($minor * 1000) + $patch;
    }

    private function collectFiles(string $path): array
    {
        $files = [];
        $excludedDirectories = ['vendor', 'node_modules', '.git', '.cache', '.data'];

        $directoryIterator = new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator(
            $directoryIterator,
            static function (\SplFileInfo $current) use ($excludedDirectories): bool {
                if ($current->isDir()) {
                    return !in_array($current->getFilename(), $excludedDirectories, true);
                }

                return true;
            }
        );

        $iterator = new \RecursiveIteratorIterator($filter, \RecursiveIteratorIterator::SELF_FIRST);

        foreach ($iterator as $file) {
            $pathname = $file->getPathname();
            $relativePath = substr($pathname, strlen($path) + 1);

            // fwrite(STDERR, "CHECKING: $pathname (relative: $relativePath)\n");

            if (str_starts_with($relativePath, 'core/') || str_starts_with($relativePath, 'vendor/')) {
                continue;
            }

            $language = $this->classifier->classify($pathname);
            if (!$file->isFile() || $language === null) {
                continue;
            }

            $files[] = [
                'path' => $pathname,
                'relative_path' => $relativePath,
                'language' => $language,
            ];
        }

        usort(
            $files,
            static fn(array $a, array $b): int => strcmp((string) $a['relative_path'], (string) $b['relative_path'])
        );

        return $files;
    }

    private function detectCpuCount(): int
    {
        if (is_readable('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            preg_match_all('/^processor/m', (string) $cpuinfo, $matches);
            return count($matches[0]);
        }

        return 4;
    }

    private function canRunParallel(): bool
    {
        return $this->workerCount > 1 && function_exists('pcntl_fork') && $this->api->getPath() !== ':memory:';
    }

    private function effectiveWorkerCount(): int
    {
        return $this->canRunParallel() ? $this->workerCount : 1;
    }
}
