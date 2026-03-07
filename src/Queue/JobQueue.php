<?php

declare(strict_types=1);

namespace DrupalEvolver\Queue;

use DrupalEvolver\Storage\DatabaseApi;

final class JobQueue
{
    public function __construct(private DatabaseApi $api) {}

    #[\NoDiscard]
    public function enqueue(string $kind, array $payload, int $maxAttempts = 1): int
    {
        return $this->api->jobs()->create($kind, $payload, $maxAttempts);
    }

    #[\NoDiscard]
    public function claimNext(): ?array
    {
        return $this->api->jobs()->claimNext();
    }

    public function progress(int $jobId, int $current, ?int $total = null, ?string $label = null): int
    {
        return $this->api->jobs()->updateProgress($jobId, $current, $total, $label);
    }

    public function complete(int $jobId): int
    {
        return $this->api->jobs()->markCompleted($jobId);
    }

    public function fail(int $jobId, string $error): int
    {
        return $this->api->jobs()->markFailed($jobId, $error);
    }

    public function log(int $jobId, string $level, string $message): int
    {
        return $this->api->jobLogs()->append($jobId, $level, $message);
    }

    #[\NoDiscard]
    public function active(): array
    {
        return $this->api->jobs()->active();
    }

    #[\NoDiscard]
    public function recent(int $limit = 20): array
    {
        return $this->api->jobs()->recent($limit);
    }
}
