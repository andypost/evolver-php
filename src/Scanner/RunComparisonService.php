<?php

declare(strict_types=1);

namespace DrupalEvolver\Scanner;

use DrupalEvolver\Storage\DatabaseApi;

final class RunComparisonService
{
    public function __construct(private DatabaseApi $api) {}

    /**
     * @return array{
     *   base_run: array<string, mixed>,
     *   head_run: array<string, mixed>,
     *   introduced: array<int, array<string, mixed>>,
     *   resolved: array<int, array<string, mixed>>,
     *   persisting: array<int, array<string, mixed>>,
     *   summary: array<string, mixed>
     * }
     */
    #[\NoDiscard]
    public function compare(int $baseRunId, int $headRunId): array
    {
        $baseRun = $this->api->scanRuns()->findById($baseRunId);
        $headRun = $this->api->scanRuns()->findById($headRunId);

        if ($baseRun === null || $headRun === null) {
            throw new \InvalidArgumentException('Both scan runs must exist.');
        }

        if ((int) $baseRun['project_id'] !== (int) $headRun['project_id']) {
            throw new \InvalidArgumentException('Only runs from the same project can be compared.');
        }

        if (($baseRun['from_core_version'] ?? null) !== ($headRun['from_core_version'] ?? null)
            || ($baseRun['target_core_version'] ?? null) !== ($headRun['target_core_version'] ?? null)) {
            throw new \InvalidArgumentException('Only runs with the same upgrade path can be compared.');
        }

        $baseMatches = $this->indexMatches($this->api->findMatchesWithChangesForRun($baseRunId));
        $headMatches = $this->indexMatches($this->api->findMatchesWithChangesForRun($headRunId));

        $introduced = [];
        $resolved = [];
        $persisting = [];

        foreach ($headMatches as $key => $match) {
            if (!isset($baseMatches[$key])) {
                $introduced[] = $match;
                continue;
            }

            $persisting[] = $match;
        }

        foreach ($baseMatches as $key => $match) {
            if (!isset($headMatches[$key])) {
                $resolved[] = $match;
            }
        }

        return [
            'base_run' => $baseRun,
            'head_run' => $headRun,
            'introduced' => $introduced,
            'resolved' => $resolved,
            'persisting' => $persisting,
            'summary' => [
                'introduced' => $this->summarize($introduced),
                'resolved' => $this->summarize($resolved),
                'persisting' => $this->summarize($persisting),
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $matches
     * @return array<string, array<string, mixed>>
     */
    private function indexMatches(array $matches): array
    {
        $indexed = [];

        foreach ($matches as $match) {
            $changeId = (int) ($match['change_id'] ?? 0);
            $filePath = (string) ($match['file_path'] ?? '');
            if ($changeId <= 0 || $filePath === '') {
                continue;
            }

            $indexed[$changeId . '|' . $filePath] = $match;
        }

        return $indexed;
    }

    /**
     * @param array<int, array<string, mixed>> $matches
     * @return array{count: int, by_severity: array<string, int>, by_change_type: array<string, int>}
     */
    private function summarize(array $matches): array
    {
        $summary = [
            'count' => count($matches),
            'by_severity' => [],
            'by_change_type' => [],
        ];

        foreach ($matches as $match) {
            $severity = (string) ($match['severity'] ?? 'unknown');
            $changeType = (string) ($match['change_type'] ?? 'unknown');
            $summary['by_severity'][$severity] = ($summary['by_severity'][$severity] ?? 0) + 1;
            $summary['by_change_type'][$changeType] = ($summary['by_change_type'][$changeType] ?? 0) + 1;
        }

        return $summary;
    }
}
