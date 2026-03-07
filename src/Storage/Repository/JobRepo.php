<?php

declare(strict_types=1);

namespace DrupalEvolver\Storage\Repository;

use DrupalEvolver\Storage\Database;

final class JobRepo
{
    public function __construct(private Database $db) {}

    #[\NoDiscard]
    public function create(string $kind, array $payload, int $maxAttempts = 1): int
    {
        $written = $this->db->execute(
            'INSERT INTO jobs (kind, status, payload_json, max_attempts)
             VALUES (:kind, :status, :payload_json, :max_attempts)',
            [
                'kind' => $kind,
                'status' => 'queued',
                'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES),
                'max_attempts' => max(1, $maxAttempts),
            ]
        );

        if ($written !== 1) {
            throw new \LogicException('Failed to enqueue job.');
        }

        return $this->db->lastInsertId();
    }

    #[\NoDiscard]
    public function findById(int $id): ?array
    {
        $row = $this->db->query('SELECT * FROM jobs WHERE id = :id', ['id' => $id])->fetch();
        return $row ? $this->decodePayload($row) : null;
    }

    #[\NoDiscard]
    public function claimNext(): ?array
    {
        return $this->db->transaction(function (): ?array {
            $row = $this->db->query(
                "SELECT * FROM jobs
                 WHERE status = 'queued'
                 ORDER BY id
                 LIMIT 1"
            )->fetch();

            if (!$row) {
                return null;
            }

            $claimed = $this->db->execute(
                "UPDATE jobs
                 SET status = 'running',
                     attempts = attempts + 1,
                     reserved_at = datetime('now'),
                     started_at = COALESCE(started_at, datetime('now')),
                     updated_at = datetime('now')
                 WHERE id = :id AND status = 'queued'",
                ['id' => $row['id']]
            );

            if ($claimed !== 1) {
                return null;
            }

            $claimedRow = $this->findById((int) $row['id']);
            if ($claimedRow === null) {
                throw new \LogicException('Claimed job disappeared.');
            }

            return $claimedRow;
        });
    }

    public function updateProgress(int $id, int $current, ?int $total = null, ?string $label = null): int
    {
        return $this->db->execute(
            "UPDATE jobs
             SET progress_current = :progress_current,
                 progress_total = COALESCE(:progress_total, progress_total),
                 progress_label = COALESCE(:progress_label, progress_label),
                 updated_at = datetime('now')
             WHERE id = :id",
            [
                'id' => $id,
                'progress_current' => max(0, $current),
                'progress_total' => $total,
                'progress_label' => $label,
            ]
        );
    }

    public function markCompleted(int $id): int
    {
        return $this->db->execute(
            "UPDATE jobs
             SET status = 'completed',
                 finished_at = datetime('now'),
                 updated_at = datetime('now'),
                 error_message = NULL
             WHERE id = :id",
            ['id' => $id]
        );
    }

    public function markFailed(int $id, string $errorMessage): int
    {
        return $this->db->execute(
            "UPDATE jobs
             SET status = 'failed',
                 error_message = :error_message,
                 finished_at = datetime('now'),
                 updated_at = datetime('now')
             WHERE id = :id",
            ['id' => $id, 'error_message' => $errorMessage]
        );
    }

    #[\NoDiscard]
    public function active(): array
    {
        $rows = $this->db->query(
            "SELECT * FROM jobs
             WHERE status IN ('queued', 'running')
             ORDER BY id DESC"
        )->fetchAll();

        return array_map($this->decodePayload(...), $rows);
    }

    #[\NoDiscard]
    public function recent(int $limit = 20): array
    {
        $limit = max(1, $limit);
        $rows = $this->db->query(
            sprintf('SELECT * FROM jobs ORDER BY id DESC LIMIT %d', $limit)
        )->fetchAll();

        return array_map($this->decodePayload(...), $rows);
    }

    private function decodePayload(array $row): array
    {
        $payload = $row['payload_json'] ?? null;
        if (is_string($payload) && $payload !== '') {
            $row['payload'] = json_decode($payload, true) ?? [];
        } else {
            $row['payload'] = [];
        }

        return $row;
    }
}
