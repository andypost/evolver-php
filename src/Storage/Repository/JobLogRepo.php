<?php

declare(strict_types=1);

namespace DrupalEvolver\Storage\Repository;

use DrupalEvolver\Storage\Database;

final class JobLogRepo
{
    public function __construct(private Database $db) {}

    #[\NoDiscard]
    public function append(int $jobId, string $level, string $message): int
    {
        return $this->db->transaction(function () use ($jobId, $level, $message): int {
            $nextSeq = (int) ($this->db->query(
                'SELECT COALESCE(MAX(seq), 0) + 1 AS next_seq FROM job_logs WHERE job_id = :job_id',
                ['job_id' => $jobId]
            )->fetch()['next_seq'] ?? 1);

            $written = $this->db->execute(
                'INSERT INTO job_logs (job_id, seq, level, message)
                 VALUES (:job_id, :seq, :level, :message)',
                [
                    'job_id' => $jobId,
                    'seq' => $nextSeq,
                    'level' => $level,
                    'message' => $message,
                ]
            );

            if ($written !== 1) {
                throw new \LogicException('Failed to append job log entry.');
            }

            return $nextSeq;
        });
    }

    #[\NoDiscard]
    public function findByJob(int $jobId, int $limit = 200): array
    {
        $limit = max(1, $limit);
        return $this->db->query(
            sprintf('SELECT * FROM job_logs WHERE job_id = :job_id ORDER BY seq DESC LIMIT %d', $limit),
            ['job_id' => $jobId]
        )->fetchAll();
    }

    #[\NoDiscard]
    public function findAfterSeq(int $jobId, int $afterSeq = 0): array
    {
        return $this->db->query(
            'SELECT * FROM job_logs WHERE job_id = :job_id AND seq > :after_seq ORDER BY seq',
            ['job_id' => $jobId, 'after_seq' => $afterSeq]
        )->fetchAll();
    }
}
