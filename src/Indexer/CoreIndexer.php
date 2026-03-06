<?php

declare(strict_types=1);

namespace DrupalEvolver\Indexer;

use DrupalEvolver\Indexer\Extractor\PHPExtractor;
use DrupalEvolver\Indexer\Extractor\YAMLExtractor;
use DrupalEvolver\Indexer\Extractor\JSExtractor;
use DrupalEvolver\Indexer\Extractor\CSSExtractor;
use DrupalEvolver\Indexer\Extractor\DrupalLibrariesExtractor;
use DrupalEvolver\Storage\Database;
use DrupalEvolver\Storage\DatabaseApi;
use DrupalEvolver\Storage\Repository\FileRepo;
use DrupalEvolver\Storage\Repository\SymbolRepo;
use DrupalEvolver\TreeSitter\Parser;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

class CoreIndexer
{
    private FileClassifier $classifier;
    private bool $storeAst = true;
    private int $workerCount = 1;

    public function __construct(
        private Parser $parser,
        private DatabaseApi $api,
    ) {
        $this->classifier = new FileClassifier();
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
        $output?->writeln(sprintf('Found <info>%d</info> files to index using <info>%d</info> workers', $totalFiles, $this->workerCount));

        if ($totalFiles === 0) {
            return;
        }

        if ($this->workerCount > 1 && function_exists('pcntl_fork')) {
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

        $phpExtractor = new PHPExtractor($this->parser->registry());
        $yamlExtractor = new YAMLExtractor($this->parser->registry());
        $jsExtractor = new JSExtractor($this->parser->registry());
        $cssExtractor = new CSSExtractor($this->parser->registry());
        $libExtractor = new DrupalLibrariesExtractor($this->parser->registry());

        $fileRepo = $this->api->files();
        $symbolRepo = $this->api->symbols();

        $processedFiles = $this->api->db()->transaction(function() use ($path, $versionId, $files, $progress, $phpExtractor, $yamlExtractor, $jsExtractor, $cssExtractor, $libExtractor, $fileRepo, $symbolRepo): int {
            $processedFiles = 0;

            foreach ($files as $filePath) {
                $this->indexFileWorker($path, $versionId, $filePath, $this->parser, $fileRepo, $symbolRepo, $phpExtractor, $yamlExtractor, $jsExtractor, $cssExtractor, $libExtractor);
                $progress?->advance();
                $processedFiles++;
            }

            return $processedFiles;
        });

        if ($processedFiles !== count($files)) {
            throw new \RuntimeException('Sequential indexing did not process the expected number of files.');
        }

        $progress?->finish();
    }

    private function indexParallel(string $path, int $versionId, array $files, ?OutputInterface $output): void
    {
        $chunks = array_chunk($files, (int) ceil(count($files) / $this->workerCount));
        $pids = [];

        $output?->writeln('<info>Spawning workers...</info>');

        foreach ($chunks as $i => $chunk) {
            // Small delay to prevent mass concurrent I/O at start
            usleep(10000);

            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new \RuntimeException("Could not fork worker {$i}");
            }

            if ($pid === 0) {
                // Get path before unsetting parent objects
                $dbPath = $this->api->getPath();

                // Free parent objects in child
                unset($this->parser, $this->api);

                // Reset FFI singleton in child
                \DrupalEvolver\TreeSitter\FFIBinding::reset();

                // Child process: New shared DB connection (WAL) and Parser
                $db = new Database($dbPath);
                $parser = new Parser();
                $fileRepo = new FileRepo($db);
                $symbolRepo = new SymbolRepo($db);

                $phpExtractor = new PHPExtractor($parser->registry());
                $yamlExtractor = new YAMLExtractor($parser->registry());
                $jsExtractor = new JSExtractor($parser->registry());
                $cssExtractor = new CSSExtractor($parser->registry());
                $libExtractor = new DrupalLibrariesExtractor($parser->registry());

                // Process in smaller sub-chunks to reduce lock time
                $subChunks = array_chunk($chunk, 20);
                foreach ($subChunks as $subChunk) {
                    $processedSubChunk = $db->transaction(function() use ($path, $versionId, $subChunk, $parser, $fileRepo, $symbolRepo, $phpExtractor, $yamlExtractor, $jsExtractor, $cssExtractor, $libExtractor): int {
                        $processedFiles = 0;

                        foreach ($subChunk as $filePath) {
                            $this->indexFileWorker($path, $versionId, $filePath, $parser, $fileRepo, $symbolRepo, $phpExtractor, $yamlExtractor, $jsExtractor, $cssExtractor, $libExtractor);
                            $processedFiles++;
                        }

                        // Check memory and dump if high
                        if (memory_get_usage(true) > 200 * 1024 * 1024 && extension_loaded('meminfo')) {
                            $dumpFile = fopen('/app/.data/profiles/worker_high_mem.json', 'w');
                            meminfo_dump($dumpFile);
                            fclose($dumpFile);
                        }

                        return $processedFiles;
                    });

                    if ($processedSubChunk !== count($subChunk)) {
                        throw new \RuntimeException('Parallel indexing worker did not process the expected number of files.');
                    }
                    gc_collect_cycles();
                }

                unset($parser, $db, $phpExtractor, $yamlExtractor);
                gc_collect_cycles();
                $peak = memory_get_peak_usage(true);
                file_put_contents('/app/.data/profiles/worker_mem.log', "Worker peak mem: $peak bytes\n", FILE_APPEND);
                exit(0);
            } else {
                $pids[] = $pid;
            }
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
    }

    private function indexFileWorker(
        string $path,
        int $versionId,
        string $filePath,
        Parser $parser,
        FileRepo $fileRepo,
        SymbolRepo $symbolRepo,
        PHPExtractor $phpExtractor,
        YAMLExtractor $yamlExtractor,
        JSExtractor $jsExtractor,
        CSSExtractor $cssExtractor,
        DrupalLibrariesExtractor $libExtractor
    ): void {
        $relativePath = substr($filePath, strlen($path) + 1);
        $language = $this->classifier->classify($filePath);
        if (!$language) return;

        $content = file_get_contents($filePath);
        if ($content === false) return;

        $fileHash = hash('sha256', $content);
        $existingFile = $fileRepo->findByPath($versionId, $relativePath);
        if ($existingFile !== null && ($existingFile['file_hash'] ?? null) === $fileHash) {
            return;
        }

        try {
            $tree = $parser->parse($content, $language);
            $root = $tree->rootNode();

            $extractor = match($language) {
                'php' => $phpExtractor,
                'yaml' => $yamlExtractor,
                'javascript' => $jsExtractor,
                'css' => $cssExtractor,
                'drupal_libraries' => $libExtractor,
                default => null,
            };

            if (!$extractor) return;

            $symbols = $extractor->extract($root, $content, $relativePath);
            $compressedSexp = $this->storeAst ? gzcompress($root->sexp()) : null;
            $fileId = $fileRepo->save(
                $versionId,
                $relativePath,
                $language,
                $fileHash,
                $compressedSexp,
                null,
                substr_count($content, "\n") + 1,
                strlen($content)
            );

            foreach ($symbols as &$symbolData) {
                $symbolData['version_id'] = $versionId;
                $symbolData['file_id'] = $fileId;
            }
            unset($symbolData);

            $insertedSymbols = $symbolRepo->replaceForFile($fileId, $symbols);
            if ($insertedSymbols !== count($symbols)) {
                throw new \RuntimeException(sprintf('Failed to persist all symbols for "%s".', $relativePath));
            }

            unset($tree, $root, $symbols, $content);
        } catch (\Throwable $e) {
            file_put_contents('/app/.data/profiles/indexing_errors.log', "Error indexing {$filePath}: " . $e->getMessage() . "\n", FILE_APPEND);
        }
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
}
