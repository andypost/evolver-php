<?php

declare(strict_types=1);

namespace DrupalEvolver\Scanner;

use DrupalEvolver\Indexer\FileClassifier;
use DrupalEvolver\Storage\Database;
use DrupalEvolver\Storage\DatabaseApi;
use DrupalEvolver\Storage\Repository\MatchRepo;
use DrupalEvolver\Storage\Schema;
use DrupalEvolver\TreeSitter\Parser;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectScanner
{
    private FileClassifier $classifier;
    private int $workerCount = 1;

    public function __construct(
        private Parser $parser,
        private DatabaseApi $api,
        private MatchCollector $matchCollector,
    ) {
        $this->classifier = new FileClassifier();
        $this->workerCount = $this->api->getPath() === ':memory:' ? 1 : $this->detectCpuCount();
    }

    public function setWorkerCount(int $count): void
    {
        $this->workerCount = max(1, $count);
    }

    public function scan(
        string $path,
        string $targetVersion,
        ?string $fromVersion = null,
        ?OutputInterface $output = null,
    ): void {
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

        $fromVer = $this->api->versions()->findByTag($fromVersion);
        $toVer = $this->api->versions()->findByTag($targetVersion);

        if (!$fromVer || !$toVer) {
            throw new \InvalidArgumentException('Both versions must be indexed first');
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

        if (empty($changes)) {
            $output?->writeln('No matching changes found for this upgrade path.');
            return;
        }

        $projectId = $this->api->projects()->save($projectName, $path, null, $fromVersion);

        $deleted = $this->api->matches()->deleteByProject($projectId);
        if ($deleted > 0) {
            $output?->writeln(sprintf('Cleared <comment>%d</comment> previous matches for project <info>%s</info>', $deleted, $projectName));
        }

        $changesByLanguage = [];
        foreach ($changes as $change) {
            $language = $change['language'] ?? null;
            if (!is_string($language) || $language === '') {
                continue;
            }
            $changesByLanguage[$language][] = $change;
        }

        $files = $this->collectFiles($path);
        $effectiveWorkerCount = $this->effectiveWorkerCount();
        if ($this->workerCount > 1 && $effectiveWorkerCount === 1) {
            $output?->writeln('<comment>pcntl is unavailable; falling back to sequential scanning.</comment>');
        }
        $output?->writeln(sprintf('Scanning <info>%d</info> files using <info>%d</info> worker%s', count($files), $effectiveWorkerCount, $effectiveWorkerCount === 1 ? '' : 's'));

        if ($this->canRunParallel()) {
            $this->scanParallel($files, $changesByLanguage, $projectId, $output);
        } else {
            $this->scanSequential($files, $changesByLanguage, $projectId, $output);
        }

        $this->api->projects()->updateLastScanned($projectId);

        $matchCount = $this->api->matches()->getTotalCountByProject($projectId);
        $output?->writeln('');
        $output?->writeln(sprintf('Found <info>%d</info> matches in project <info>%s</info>', $matchCount, $projectName));
    }

    private function scanSequential(array $files, array $changesByLanguage, int $projectId, ?OutputInterface $output): void
    {
        $progress = $output ? new ProgressBar($output, count($files)) : null;
        $progress?->start();

        $matchRepo = $this->api->matches();

        $scannedFiles = $this->api->db()->transaction(function () use ($files, $changesByLanguage, $projectId, $progress, $matchRepo): int {
            $scannedFiles = 0;

            foreach ($files as $file) {
                $this->scanFile($file, $changesByLanguage, $projectId, $this->parser, $this->matchCollector, $matchRepo);
                $progress?->advance();
                $scannedFiles++;
            }

            return $scannedFiles;
        });

        if ($scannedFiles !== count($files)) {
            throw new \RuntimeException('Sequential scan did not process the expected number of files.');
        }

        $progress?->finish();
    }

    private function scanParallel(array $files, array $changesByLanguage, int $projectId, ?OutputInterface $output): void
    {
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
                // Child: Private DB
                $db = new Database($workerDbPath);
                (new Schema($db))->createAll();

                $parser = new Parser();
                $matchRepo = new MatchRepo($db);
                $matchCollector = new MatchCollector($parser->binding(), $parser->registry());

                $scannedChunk = $db->transaction(function() use ($chunk, $changesByLanguage, $projectId, $parser, $matchCollector, $matchRepo): int {
                    $counter = 0;
                    foreach ($chunk as $file) {
                        $this->scanFile($file, $changesByLanguage, $projectId, $parser, $matchCollector, $matchRepo);
                        if (++$counter % 20 === 0) {
                            gc_collect_cycles();
                        }
                    }

                    return $counter;
                });

                if ($scannedChunk !== count($chunk)) {
                    throw new \RuntimeException('Parallel scan worker did not process the expected number of files.');
                }

                exit(0);
            } else {
                $pids[] = $pid;
            }
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
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
            $mainPdo->exec("INSERT INTO code_matches (project_id, change_id, file_path, line_start, line_end, byte_start, byte_end, matched_source, suggested_fix, fix_method, status, applied_at)
                            SELECT project_id, change_id, file_path, line_start, line_end, COALESCE(byte_start, -1), COALESCE(byte_end, -1), matched_source, suggested_fix, fix_method, status, applied_at FROM worker.code_matches
                            ON CONFLICT(project_id, change_id, file_path, byte_start, byte_end) DO UPDATE SET
                                line_start = excluded.line_start,
                                line_end = excluded.line_end,
                                matched_source = excluded.matched_source,
                                suggested_fix = excluded.suggested_fix,
                                fix_method = excluded.fix_method,
                                status = excluded.status,
                                applied_at = excluded.applied_at");
            $mainPdo->commit();
        } catch (\Throwable $e) {
            $mainPdo->rollBack();
            throw $e;
        } finally {
            $mainPdo->exec("DETACH DATABASE worker");
        }
    }

    private function scanFile(array $file, array $changesByLanguage, int $projectId, Parser $parser, MatchCollector $matchCollector, MatchRepo $matchRepo): int
    {
        $filePath = $file['path'];
        $relativePath = $file['relative_path'];
        $language = $file['language'];

        $relevantChanges = $changesByLanguage[$language] ?? [];
        if (empty($relevantChanges)) {
            return 0;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return 0;
        }

        try {
            $tree = $parser->parse($content, $language);
            $root = $tree->rootNode();
            $matches = [];

            foreach ($matchCollector->collectMatches($root, $content, $language, $relevantChanges) as $match) {
                $match['project_id'] = $projectId;
                $match['file_path'] = $relativePath;
                $matches[] = $match;
            }

            $persistedMatches = 0;
            if ($matches !== []) {
                $persistedMatches = $matchRepo->saveBatch($matches);
            }

            unset($tree, $root, $matches, $content);
            return $persistedMatches;
        } catch (\Throwable) {
            // Skip failed files
            return 0;
        }
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

        $iterator = new \RecursiveIteratorIterator(
            $filter,
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $filePath = $file->getPathname();
            $language = $this->classifier->classify($filePath);
            if ($language === null) {
                continue;
            }

            $files[] = [
                'path' => $filePath,
                'relative_path' => substr($filePath, strlen($path) + 1),
                'language' => $language,
            ];
        }

        usort(
            $files,
            static fn(array $a, array $b): int => strcmp($a['path'], $b['path'])
        );

        return $files;
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

    private function canRunParallel(): bool
    {
        return $this->workerCount > 1 && function_exists('pcntl_fork');
    }

    private function effectiveWorkerCount(): int
    {
        return $this->canRunParallel() ? $this->workerCount : 1;
    }
}
