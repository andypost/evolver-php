<?php

declare(strict_types=1);

namespace DrupalEvolver\Daemon;

use DrupalEvolver\Differ\FixTemplateGenerator;
use DrupalEvolver\Differ\RenameMatcher;
use DrupalEvolver\Differ\SignatureDiffer;
use DrupalEvolver\Differ\VersionDiffer;
use DrupalEvolver\Differ\YAMLDiffer;
use DrupalEvolver\Indexer\CoreIndexer;
use DrupalEvolver\Pattern\QueryGenerator;
use DrupalEvolver\Scanner\MatchCollector;
use DrupalEvolver\Scanner\ProjectScanner;
use DrupalEvolver\Storage\DatabaseApi;
use DrupalEvolver\TreeSitter\Parser;

/**
 * Dispatches background tasks (index, diff, scan, apply) in forked processes.
 *
 * Uses pcntl_fork for process isolation — FFI-heavy work (tree-sitter parsing)
 * must run in a separate address space to avoid C-level memory corruption.
 */
class TaskRunner
{
    /** @var array<string, int> Running task PIDs */
    private array $runningPids = [];

    public function __construct(
        private TaskQueue $queue,
        private string $dbPath,
    ) {}

    /**
     * Execute a task. Forks a child process for CPU-bound work.
     */
    public function run(string $taskId): void
    {
        $task = $this->queue->get($taskId);
        if ($task === null || $task['status'] !== 'pending') {
            return;
        }

        $this->queue->start($taskId);

        if (!function_exists('pcntl_fork')) {
            $this->runInline($taskId, $task);
            return;
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->queue->fail($taskId, 'Failed to fork worker process');
            return;
        }

        if ($pid === 0) {
            // Child process
            \DrupalEvolver\TreeSitter\FFIBinding::reset();
            try {
                $this->executeTask($task);
                exit(0);
            } catch (\Throwable $e) {
                file_put_contents(
                    '/tmp/evolver_task_' . $taskId . '.error',
                    $e->getMessage()
                );
                exit(1);
            }
        }

        // Parent: track PID
        $this->runningPids[$taskId] = $pid;
    }

    /**
     * Check for completed forked tasks. Call periodically.
     */
    public function reap(): void
    {
        foreach ($this->runningPids as $taskId => $pid) {
            $result = pcntl_waitpid($pid, $status, WNOHANG);
            if ($result > 0) {
                unset($this->runningPids[$taskId]);
                $exitCode = pcntl_wexitstatus($status);

                if ($exitCode === 0) {
                    // Read result from temp file if available
                    $resultFile = '/tmp/evolver_task_' . $taskId . '.result';
                    $taskResult = null;
                    if (file_exists($resultFile)) {
                        $data = file_get_contents($resultFile);
                        if ($data !== false) {
                            $taskResult = \igbinary_unserialize($data);
                        }
                        @unlink($resultFile);
                    }
                    $this->queue->complete($taskId, $taskResult);
                } else {
                    $errorFile = '/tmp/evolver_task_' . $taskId . '.error';
                    $error = 'Task failed with exit code ' . $exitCode;
                    if (file_exists($errorFile)) {
                        $error = file_get_contents($errorFile) ?: $error;
                        @unlink($errorFile);
                    }
                    $this->queue->fail($taskId, $error);
                }
            }
        }
    }

    #[\NoDiscard]
    public function hasRunning(): bool
    {
        return !empty($this->runningPids);
    }

    private function runInline(string $taskId, array $task): void
    {
        try {
            $result = $this->executeTask($task);
            $this->queue->complete($taskId, $result);
        } catch (\Throwable $e) {
            $this->queue->fail($taskId, $e->getMessage());
        }
    }

    private function executeTask(array $task): mixed
    {
        $type = $task['type'];
        $params = $task['params'];
        $taskId = $task['id'];

        return match ($type) {
            'index' => $this->executeIndex($taskId, $params),
            'diff' => $this->executeDiff($taskId, $params),
            'scan' => $this->executeScan($taskId, $params),
            default => throw new \InvalidArgumentException("Unknown task type: {$type}"),
        };
    }

    private function executeIndex(string $taskId, array $params): array
    {
        $path = $params['path'] ?? '';
        $tag = $params['tag'] ?? '';
        $workers = (int) ($params['workers'] ?? 4);

        $api = new DatabaseApi($this->dbPath);
        $parser = new Parser();
        $indexer = new CoreIndexer($parser, $api);
        $indexer->setWorkerCount($workers);
        $indexer->setStoreAst(false);
        $indexer->index($path, $tag);

        $stats = $api->getStats();
        $result = ['symbols' => $stats['symbol_count'], 'versions' => count($stats['versions'])];

        file_put_contents('/tmp/evolver_task_' . $taskId . '.result', \igbinary_serialize($result));
        return $result;
    }

    private function executeDiff(string $taskId, array $params): array
    {
        $fromTag = $params['from'] ?? '';
        $toTag = $params['to'] ?? '';
        $workers = (int) ($params['workers'] ?? 4);

        $api = new DatabaseApi($this->dbPath);
        $differ = new VersionDiffer(
            $api,
            new SignatureDiffer(),
            new RenameMatcher(),
            new YAMLDiffer(),
            new FixTemplateGenerator(),
            new QueryGenerator(),
        );
        $differ->setWorkerCount($workers);
        $changes = $differ->diff($fromTag, $toTag);

        $result = ['changes' => count($changes)];
        file_put_contents('/tmp/evolver_task_' . $taskId . '.result', \igbinary_serialize($result));
        return $result;
    }

    private function executeScan(string $taskId, array $params): array
    {
        $path = $params['path'] ?? '';
        $target = $params['target'] ?? '';
        $from = $params['from'] ?? null;
        $workers = (int) ($params['workers'] ?? 4);

        $api = new DatabaseApi($this->dbPath);
        $parser = new Parser();
        $collector = new MatchCollector($parser->binding(), $parser->registry());
        $scanner = new ProjectScanner($parser, $api, $collector);
        $scanner->setWorkerCount($workers);
        $scanner->scan($path, $target, $from);

        $stats = $api->getStats();
        $result = ['matches' => $stats['match_count']];
        file_put_contents('/tmp/evolver_task_' . $taskId . '.result', \igbinary_serialize($result));
        return $result;
    }
}
