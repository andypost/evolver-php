<?php

declare(strict_types=1);

namespace DrupalEvolver\Indexer;

use DrupalEvolver\Adapter\DrupalCoreAdapter;
use DrupalEvolver\Storage\DatabaseApi;
use DrupalEvolver\Storage\Repository\SymbolRepo;
use DrupalEvolver\TreeSitter\Parser;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

class CoreIndexer
{
    private const MERGE_DELETE_CHUNK_SIZE = 100;
    private const MERGE_INSERT_CHUNK_SIZE = 100;
    private const FILE_ID_LOOKUP_CHUNK_SIZE = 200;

    private FileClassifier $classifier;
    private bool $storeAst = true;
    private int $workerCount = 1;

    public function __construct(
        private Parser $parser,
        private DatabaseApi $api,
    ) {
        $this->classifier = new FileClassifier(new DrupalCoreAdapter());
        $this->workerCount = $this->api->getPath() === ':memory:' ? 1 : $this->detectCpuCount();
    }

    public function setStoreAst(bool $storeAst): void
    {
        $this->storeAst = $storeAst;
    }

    public function setWorkerCount(int $count): void
    {
        $this->workerCount = max(1, $count);
    }

    public function index(string $path, string $tag, ?OutputInterface $output = null): void
    {
        $path = rtrim(realpath($path) ?: $path, '/');

        if (!preg_match('/^(\d+)\.(\d+)\.(\d+)/', $tag, $m)) {
            throw new \InvalidArgumentException("Invalid version tag: {$tag}");
        }

        $version = $this->api->versions()->findByTag($tag);
        if ($version) {
            $versionId = $this->api->versions()->save($tag, (int) $m[1], (int) $m[2], (int) $m[3]);
            $output?->writeln("<comment>Version {$tag} already exists, re-indexing...</comment>");
        } else {
            $versionId = $this->api->versions()->save($tag, (int) $m[1], (int) $m[2], (int) $m[3]);
        }

        $files = $this->collectFiles($path);
        $totalFiles = count($files);
        $effectiveWorkerCount = $this->effectiveWorkerCount();
        if ($this->workerCount > 1 && $effectiveWorkerCount === 1) {
            $output?->writeln('<comment>pcntl is unavailable; falling back to sequential indexing.</comment>');
        }
        $output?->writeln(sprintf('Found <info>%d</info> files to index using <info>%d</info> worker%s', $totalFiles, $effectiveWorkerCount, $effectiveWorkerCount === 1 ? '' : 's'));

        if ($totalFiles === 0) {
            return;
        }

        if ($this->canRunParallel()) {
            $this->indexParallel($path, $versionId, $files, $output);
        } else {
            $this->indexSequential($path, $versionId, $files, $output);
        }

        $this->updateFinalCounts($versionId);

        $output?->writeln('');
        $output?->writeln(sprintf('Indexing complete for version <info>%s</info>', $tag));
    }

    private function indexSequential(string $path, int $versionId, array $files, ?OutputInterface $output): void
    {
        $progress = $this->createProgressBar($output, count($files));
        $existingFileHashes = $this->loadExistingFileHashes($versionId);
        $payload = $this->buildLocalPayload(
            $path,
            $files,
            $existingFileHashes,
            static function () use ($progress): void {
                $progress?->advance();
            }
        );
        $this->persistWorkerPayloads($versionId, [$payload]);

        $progress?->finish();
    }

    private function indexParallel(string $path, int $versionId, array $files, ?OutputInterface $output): void
    {
        $chunks = array_chunk($files, (int) ceil(count($files) / $this->workerCount));
        $existingFileHashes = $this->loadExistingFileHashes($versionId);
        $pids = [];
        $payloadFiles = $this->allocatePayloadFiles(count($chunks));

        $output?->writeln('<info>Spawning workers...</info>');

        foreach ($chunks as $i => $chunk) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new \RuntimeException("Could not fork worker {$i}");
            }

            if ($pid === 0) {
                try {
                    $payload = $this->buildWorkerPayload($path, $chunk, $existingFileHashes);
                    $written = file_put_contents($payloadFiles[$i], \igbinary_serialize($payload));
                    if ($written === false) {
                        throw new \RuntimeException("Worker {$i} could not write payload.");
                    }
                    exit(0);
                } catch (\Throwable $e) {
                    file_put_contents('/app/.data/profiles/indexing_errors.log', "Worker {$i} failed: " . $e->getMessage() . "\n", FILE_APPEND);
                    exit(1);
                }
            } else {
                $pids[$pid] = $i;
            }
        }

        foreach (array_keys($pids) as $pid) {
            pcntl_waitpid($pid, $status);
            if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                $workerIndex = $pids[$pid];
                $this->cleanupPayloadFiles($payloadFiles);
                throw new \RuntimeException("Parallel indexing worker {$workerIndex} failed.");
            }
        }

        $output?->write('<info>Merging worker payloads...</info> ');
        try {
            $this->persistWorkerPayloads($versionId, $this->readPayloadFiles($payloadFiles));
            $output?->writeln('<info>Done!</info>');
        } finally {
            $this->cleanupPayloadFiles($payloadFiles);
        }
    }

    private function buildWorkerPayload(string $path, array $files, array $existingFileHashes): array
    {
        return WorkerPayloadBuilder::buildWithFreshParser(
            $path,
            $files,
            $existingFileHashes,
            $this->storeAst,
        );
    }

    private function buildLocalPayload(
        string $path,
        array $files,
        array $existingFileHashes,
        ?callable $onProcessed = null
    ): array {
        return WorkerPayloadBuilder::buildWithParser(
            $path,
            $files,
            $existingFileHashes,
            $this->storeAst,
            $this->parser,
            $onProcessed,
        );
    }

    private function persistWorkerPayloads(int $versionId, iterable $payloads): void
    {
        $db = $this->api->db();
        $symbolRepo = $this->api->symbols();

        $_tx = $db->transaction(function () use ($payloads, $versionId, $db, $symbolRepo): int {
            $fileUpsert = $db->pdo()->prepare(
                'INSERT INTO parsed_files (version_id, file_path, language, file_hash, ast_sexp, ast_json, line_count, byte_size)
                 VALUES (:vid, :path, :lang, :hash, :sexp, :json, :lines, :bytes)
                 ON CONFLICT(version_id, file_path) DO UPDATE SET
                     language = excluded.language,
                     file_hash = excluded.file_hash,
                     ast_sexp = excluded.ast_sexp,
                     ast_json = excluded.ast_json,
                     line_count = excluded.line_count,
                     byte_size = excluded.byte_size,
                     parsed_at = datetime(\'now\')'
            );

            $persistedEntries = 0;

            foreach ($payloads as $payload) {
                $entries = $payload['entries'] ?? [];
                if (!is_array($entries) || $entries === []) {
                    continue;
                }

                $filePaths = [];
                $symbolsByPath = [];

                foreach ($entries as $entry) {
                    $fileData = $entry['file'] ?? null;
                    $symbols = $entry['symbols'] ?? null;
                    if (!is_array($fileData) || !is_array($symbols)) {
                        throw new \RuntimeException('Invalid worker payload entry.');
                    }

                    $path = (string) ($fileData['file_path'] ?? '');
                    if ($path === '') {
                        throw new \RuntimeException('Worker payload entry missing file path.');
                    }

                    $fileUpsert->execute([
                        'vid' => $versionId,
                        'path' => $path,
                        'lang' => (string) $fileData['language'],
                        'hash' => (string) $fileData['file_hash'],
                        'sexp' => $fileData['ast_sexp'] ?? null,
                        'json' => $fileData['ast_json'] ?? null,
                        'lines' => isset($fileData['line_count']) ? (int) $fileData['line_count'] : null,
                        'bytes' => isset($fileData['byte_size']) ? (int) $fileData['byte_size'] : null,
                    ]);

                    $filePaths[] = $path;
                    $symbolsByPath[$path] = $symbols;
                }

                $fileIdsByPath = $this->loadFileIdsForPaths($versionId, $filePaths);
                if (count($fileIdsByPath) !== count(array_unique($filePaths))) {
                    throw new \RuntimeException('Failed to resolve all persisted file IDs after payload merge.');
                }

                $fileIds = array_values($fileIdsByPath);
                $deletedSymbols = 0;
                foreach (array_chunk($fileIds, self::MERGE_DELETE_CHUNK_SIZE) as $fileIdChunk) {
                    $placeholders = implode(', ', array_fill(0, count($fileIdChunk), '?'));
                    $deletedSymbols += $db->execute("DELETE FROM symbols WHERE file_id IN ({$placeholders})", $fileIdChunk);
                }

                $symbolRows = [];
                foreach ($symbolsByPath as $path => $symbols) {
                    $fileId = $fileIdsByPath[$path] ?? null;
                    if (!is_int($fileId) || $fileId <= 0) {
                        throw new \RuntimeException(sprintf('Failed to resolve file ID for "%s".', $path));
                    }

                    foreach ($symbols as $symbolData) {
                        $symbolData['version_id'] = $versionId;
                        $symbolData['file_id'] = $fileId;
                        $symbolRows[] = $symbolData;
                    }
                }

                $insertedSymbols = 0;
                foreach (array_chunk($symbolRows, self::MERGE_INSERT_CHUNK_SIZE) as $symbolChunk) {
                    $insertedSymbols += $symbolRepo->insertBatch($symbolChunk);
                }

                if ($insertedSymbols !== count($symbolRows)) {
                    throw new \RuntimeException('Failed to persist all merged symbols.');
                }

                if ($deletedSymbols < 0) {
                    throw new \RuntimeException('Failed to replace merged symbols.');
                }

                $persistedEntries += count($entries);
            }

            return $persistedEntries;
        });
        unset($_tx);
    }

    private function updateFinalCounts(int $versionId): void
    {
        $stats = $this->api->db()->query(
            'SELECT COUNT(DISTINCT f.id) as files, COUNT(s.id) as symbols
             FROM parsed_files f
             LEFT JOIN symbols s ON f.id = s.file_id
             WHERE f.version_id = :vid',
            ['vid' => $versionId]
        )->fetch();

        $this->api->versions()->updateCounts($versionId, (int)$stats['files'], (int)$stats['symbols']);
    }

    private function createProgressBar(?OutputInterface $output, int $count): ?ProgressBar
    {
        if (!$output) return null;
        $progress = new ProgressBar($output, $count);
        $progress->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
        $progress->start();
        return $progress;
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
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $this->classifier->classify($file->getPathname())) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);
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

    private function allocatePayloadFiles(int $count): array
    {
        $payloadFiles = [];

        for ($i = 0; $i < $count; $i++) {
            $payloadFile = tempnam(sys_get_temp_dir(), "evolver_index_payload_{$i}_");
            if ($payloadFile === false) {
                $this->cleanupPayloadFiles($payloadFiles);
                throw new \RuntimeException("Could not allocate payload file for worker {$i}");
            }

            $payloadFiles[$i] = $payloadFile;
        }

        return $payloadFiles;
    }

    private function loadExistingFileHashes(int $versionId): array
    {
        $rows = $this->api->db()->query(
            'SELECT file_path, file_hash FROM parsed_files WHERE version_id = :vid',
            ['vid' => $versionId]
        )->fetchAll();

        $hashes = [];
        foreach ($rows as $row) {
            $path = (string) ($row['file_path'] ?? '');
            if ($path === '') {
                continue;
            }
            $hashes[$path] = (string) ($row['file_hash'] ?? '');
        }

        return $hashes;
    }

    private function cleanupPayloadFiles(array $payloadFiles): void
    {
        foreach ($payloadFiles as $payloadFile) {
            if (is_string($payloadFile) && file_exists($payloadFile)) {
                @unlink($payloadFile);
            }
        }
    }

    private function readPayloadFiles(array $payloadFiles): \Generator
    {
        foreach ($payloadFiles as $payloadFile) {
            $data = file_get_contents($payloadFile);
            if ($data === false) {
                throw new \RuntimeException("Could not read worker payload {$payloadFile}.");
            }

            $payload = \igbinary_unserialize($data);
            if (!is_array($payload)) {
                throw new \RuntimeException("Invalid worker payload in {$payloadFile}.");
            }

            yield $payload;
        }
    }

    private function loadFileIdsForPaths(int $versionId, array $filePaths): array
    {
        $uniquePaths = array_values(array_unique(array_filter($filePaths, static fn($path): bool => is_string($path) && $path !== '')));
        if ($uniquePaths === []) {
            return [];
        }

        $ids = [];
        foreach (array_chunk($uniquePaths, self::FILE_ID_LOOKUP_CHUNK_SIZE) as $pathChunk) {
            $placeholders = implode(', ', array_fill(0, count($pathChunk), '?'));
            $params = array_merge([$versionId], $pathChunk);
            $rows = $this->api->db()->query(
                "SELECT id, file_path FROM parsed_files WHERE version_id = ? AND file_path IN ({$placeholders})",
                $params
            )->fetchAll();

            foreach ($rows as $row) {
                $path = (string) ($row['file_path'] ?? '');
                if ($path === '') {
                    continue;
                }
                $ids[$path] = (int) ($row['id'] ?? 0);
            }
        }

        return $ids;
    }
}
