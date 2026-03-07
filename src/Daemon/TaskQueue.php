<?php

declare(strict_types=1);

namespace DrupalEvolver\Daemon;

/**
 * In-memory task queue with progress tracking.
 *
 * Tasks represent background operations (index, diff, scan, apply).
 * Progress events are pushed to listeners (WebSocket clients, CLI).
 */
class TaskQueue
{
    /** @var array<string, array{id: string, type: string, params: array, status: string, progress: int, message: string, result: mixed, created_at: float, started_at: ?float, completed_at: ?float}> */
    private array $tasks = [];

    /** @var list<callable(string, array): void> */
    private array $listeners = [];

    #[\NoDiscard]
    public function submit(string $type, array $params = []): string
    {
        $id = bin2hex(random_bytes(8));
        $this->tasks[$id] = [
            'id' => $id,
            'type' => $type,
            'params' => $params,
            'status' => 'pending',
            'progress' => 0,
            'message' => '',
            'result' => null,
            'created_at' => microtime(true),
            'started_at' => null,
            'completed_at' => null,
        ];

        $this->emit($id, ['type' => 'submitted', 'task_type' => $type]);
        return $id;
    }

    public function start(string $id): void
    {
        if (!isset($this->tasks[$id])) return;
        $this->tasks[$id]['status'] = 'running';
        $this->tasks[$id]['started_at'] = microtime(true);
        $this->emit($id, ['type' => 'started']);
    }

    public function progress(string $id, int $pct, string $message = ''): void
    {
        if (!isset($this->tasks[$id])) return;
        $this->tasks[$id]['progress'] = $pct;
        $this->tasks[$id]['message'] = $message;
        $this->emit($id, ['type' => 'progress', 'pct' => $pct, 'msg' => $message]);
    }

    public function complete(string $id, mixed $result = null): void
    {
        if (!isset($this->tasks[$id])) return;
        $this->tasks[$id]['status'] = 'completed';
        $this->tasks[$id]['progress'] = 100;
        $this->tasks[$id]['result'] = $result;
        $this->tasks[$id]['completed_at'] = microtime(true);
        $this->emit($id, ['type' => 'complete', 'result' => $result]);
    }

    public function fail(string $id, string $error): void
    {
        if (!isset($this->tasks[$id])) return;
        $this->tasks[$id]['status'] = 'failed';
        $this->tasks[$id]['result'] = ['error' => $error];
        $this->tasks[$id]['completed_at'] = microtime(true);
        $this->emit($id, ['type' => 'error', 'error' => $error]);
    }

    public function cancel(string $id): bool
    {
        if (!isset($this->tasks[$id])) return false;
        if ($this->tasks[$id]['status'] !== 'pending') return false;
        $this->tasks[$id]['status'] = 'cancelled';
        $this->tasks[$id]['completed_at'] = microtime(true);
        $this->emit($id, ['type' => 'cancelled']);
        return true;
    }

    #[\NoDiscard]
    public function get(string $id): ?array
    {
        return $this->tasks[$id] ?? null;
    }

    #[\NoDiscard]
    public function all(): array
    {
        return array_values($this->tasks);
    }

    #[\NoDiscard]
    public function active(): array
    {
        return array_values(array_filter(
            $this->tasks,
            static fn(array $t): bool => in_array($t['status'], ['pending', 'running'], true)
        ));
    }

    #[\NoDiscard]
    public function recent(int $limit = 20): array
    {
        $sorted = $this->tasks;
        usort($sorted, static fn(array $a, array $b): int => (int) (($b['created_at'] - $a['created_at']) * 1000));
        return array_slice($sorted, 0, $limit);
    }

    public function onEvent(callable $listener): void
    {
        $this->listeners[] = $listener;
    }

    public function removeListener(callable $listener): void
    {
        $this->listeners = array_values(array_filter(
            $this->listeners,
            static fn(callable $l): bool => $l !== $listener
        ));
    }

    private function emit(string $taskId, array $event): void
    {
        $event['task_id'] = $taskId;
        foreach ($this->listeners as $listener) {
            try {
                $listener($taskId, $event);
            } catch (\Throwable) {
                // Don't let listener errors break the queue
            }
        }
    }
}
