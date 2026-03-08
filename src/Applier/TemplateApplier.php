<?php

declare(strict_types=1);

namespace DrupalEvolver\Applier;

use DrupalEvolver\Storage\DatabaseApi;
use Symfony\Component\Console\Output\OutputInterface;

class TemplateApplier
{
    private FixTemplate $fixTemplate;
    private PharboristTransformer $pharboristTransformer;

    public function __construct(
        private DatabaseApi $api,
    ) {
        $this->fixTemplate = new FixTemplate();
        $this->pharboristTransformer = new PharboristTransformer();
    }

    #[\NoDiscard]
    public function apply(
        int $projectId,
        string $projectPath,
        ?int $scanRunId = null,
        bool $dryRun = false,
        bool $interactive = false,
        ?OutputInterface $output = null,
        ?callable $confirm = null,
    ): int {
        $stats = $this->applyWithStats($projectId, $projectPath, $scanRunId, $dryRun, $interactive, $output, $confirm);
        return $dryRun ? (int) $stats['would_apply'] : (int) $stats['applied'];
    }

    #[\NoDiscard]
    public function applyWithStats(
        int $projectId,
        string $projectPath,
        ?int $scanRunId = null,
        bool $dryRun = false,
        bool $interactive = false,
        ?OutputInterface $output = null,
        ?callable $confirm = null,
    ): array {
        $matches = $scanRunId !== null
            ? $this->api->findPendingFixesWithTemplatesForRun($scanRunId)
            : $this->api->findPendingFixesWithTemplates($projectId);

        $stats = [
            'total' => count($matches),
            'applied' => 0,
            'would_apply' => 0,
            'skipped' => 0,
            'failed' => 0,
            'conflicts' => 0,
            'files_changed' => 0,
        ];

        if (empty($matches)) {
            $output?->writeln('No pending fixes with templates found.');
            return $stats;
        }

        // Group matches by file (apply bottom-up within each file)
        $byFile = [];
        foreach ($matches as $match) {
            $byFile[$match['file_path']][] = $match;
        }

        $matchRepo = $this->api->matches();
        $diffGenerator = new DiffGenerator();

        foreach ($byFile as $relPath => $fileMatches) {
            $fullPath = rtrim($projectPath, '/') . '/' . $relPath;
            if (!file_exists($fullPath)) {
                $stats['failed'] += count($fileMatches);
                if (!$dryRun) {
                    foreach ($fileMatches as $match) {
                        $matchRepo->updateStatus((int) $match['id'], 'failed');
                    }
                }
                continue;
            }

            $source = file_get_contents($fullPath);
            if ($source === false) {
                $stats['failed'] += count($fileMatches);
                if (!$dryRun) {
                    foreach ($fileMatches as $match) {
                        $matchRepo->updateStatus((int) $match['id'], 'failed');
                    }
                }
                continue;
            }
            $modified = $source;
            $reservedRanges = [];
            $appliedMatchIds = [];

            // Prefer byte offsets when available, fallback to line order.
            usort($fileMatches, function (array $a, array $b): int {
                $aByte = $a['byte_start'] ?? null;
                $bByte = $b['byte_start'] ?? null;
                if ($aByte !== null && $bByte !== null) {
                    return ((int) $bByte) <=> ((int) $aByte);
                }
                if ($aByte !== null) {
                    return -1;
                }
                if ($bByte !== null) {
                    return 1;
                }
                return ((int) ($b['line_start'] ?? 0)) <=> ((int) ($a['line_start'] ?? 0));
            });

            foreach ($fileMatches as $match) {
                $matchId = (int) $match['id'];
                $matchedSource = (string) ($match['matched_source'] ?? '');
                $fixMethod = (string) ($match['fix_method'] ?? 'template');
                $diffBase = $matchedSource;

                if ($fixMethod === 'pharborist') {
                    if ($this->isOverlappingReservedRange($match, $reservedRanges)) {
                        $stats['failed']++;
                        $stats['conflicts']++;
                        if (!$dryRun) {
                            $matchRepo->updateStatus($matchId, 'failed');
                        }
                        $output?->writeln(
                            sprintf(
                                '<comment>Skipped overlapping match in %s at line %d</comment>',
                                $relPath,
                                (int) ($match['line_start'] ?? 0)
                            )
                        );
                        continue;
                    }

                    $diffBase = $modified;
                    $script = json_decode((string) ($match['fix_template'] ?? ''), true);
                    $fixed = is_array($script)
                        ? $this->pharboristTransformer->transform($modified, $script)
                        : null;
                } else {
                    $fixed = $this->fixTemplate->apply($matchedSource, (string) $match['fix_template']);
                }

                if ($fixed === null) {
                    $stats['failed']++;
                    if (!$dryRun) {
                        $matchRepo->updateStatus($matchId, 'failed');
                    }
                    continue;
                }

                if ($fixed === $diffBase) {
                    $stats['skipped']++;
                    if (!$dryRun) {
                        $matchRepo->updateStatus($matchId, 'skipped');
                    }
                    continue;
                }

                if ($fixMethod !== 'pharborist' && $this->isOverlappingReservedRange($match, $reservedRanges)) {
                    $stats['failed']++;
                    $stats['conflicts']++;
                    if (!$dryRun) {
                        $matchRepo->updateStatus($matchId, 'failed');
                    }
                    $output?->writeln(
                        sprintf(
                            '<comment>Skipped overlapping match in %s at line %d</comment>',
                            $relPath,
                            (int) ($match['line_start'] ?? 0)
                        )
                    );
                    continue;
                }

                if ($dryRun || $interactive) {
                    $previewBase = $fixMethod === 'pharborist'
                        ? $diffBase
                        : ($this->sliceFromOffsets($modified, $match) ?? $matchedSource);
                    $diff = $diffGenerator->generate($previewBase, $fixed, $relPath, (int) ($match['line_start'] ?? 0));
                    $output?->writeln($diff);
                }

                if ($interactive && $confirm) {
                    if (!$confirm()) {
                        $stats['skipped']++;
                        if (!$dryRun) {
                            $matchRepo->updateStatus($matchId, 'skipped');
                        }
                        continue;
                    }
                }

                if (!$dryRun) {
                    if ($fixMethod === 'pharborist') {
                        $modified = $fixed;
                        $appliedMatchIds[] = $matchId;
                        $stats['applied']++;
                        $this->reserveWholeFile($reservedRanges, strlen($modified));
                        continue;
                    }

                    // Preferred path: apply at scanner-provided byte offsets.
                    $updated = $this->applyAtOffsets($modified, $match, $fixed, $matchedSource);
                    if ($updated !== null) {
                        $modified = $updated;
                        $appliedMatchIds[] = $matchId;
                        $stats['applied']++;
                        $this->reserveRange($match, $reservedRanges);
                        continue;
                    }

                    // Backward-compatible fallback for old matches without offsets.
                    if ($matchedSource !== '') {
                        $occurrences = substr_count($modified, $matchedSource);
                        if ($occurrences === 1) {
                            $pos = strpos($modified, $matchedSource);
                            if ($pos !== false) {
                                $modified = substr($modified, 0, $pos) . $fixed . substr($modified, $pos + strlen($matchedSource));
                                $appliedMatchIds[] = $matchId;
                                $stats['applied']++;
                                continue;
                            }
                        } elseif ($occurrences > 1) {
                            $output?->writeln(
                                sprintf(
                                    '<comment>Ambiguous legacy match in %s at line %d (multiple occurrences)</comment>',
                                    $relPath,
                                    (int) ($match['line_start'] ?? 0)
                                )
                            );
                        }
                    }

                    $matchRepo->updateStatus($matchId, 'failed');
                    $stats['failed']++;
                } else {
                    $stats['would_apply']++;
                    if ($fixMethod === 'pharborist') {
                        $this->reserveWholeFile($reservedRanges, strlen($fixed));
                    } else {
                        $this->reserveRange($match, $reservedRanges);
                    }
                }
            }

            if (!$dryRun && $modified !== $source) {
                $bytesWritten = @file_put_contents($fullPath, $modified);
                if ($bytesWritten === false) {
                    $failedApplied = count($appliedMatchIds);
                    if ($failedApplied > 0) {
                        $stats['applied'] -= $failedApplied;
                        $stats['failed'] += $failedApplied;
                        foreach ($appliedMatchIds as $matchId) {
                            $matchRepo->updateStatus($matchId, 'failed');
                        }
                    }
                    $output?->writeln(sprintf('<error>Failed to write %s</error>', $relPath));
                    continue;
                }

                if (!empty($appliedMatchIds)) {
                    foreach ($appliedMatchIds as $matchId) {
                        $matchRepo->updateStatus($matchId, 'applied');
                    }
                    $stats['files_changed']++;
                }
            } elseif (!$dryRun && !empty($appliedMatchIds)) {
                foreach ($appliedMatchIds as $matchId) {
                    $matchRepo->updateStatus($matchId, 'applied');
                }
            }
        }

        return $stats;
    }

    private function applyAtOffsets(string $source, array $match, string $replacement, string $expected): ?string
    {
        if (!isset($match['byte_start'], $match['byte_end'])) {
            return null;
        }

        $start = (int) $match['byte_start'];
        $end = (int) $match['byte_end'];
        if ($start < 0 || $end < $start || $end > strlen($source)) {
            return null;
        }

        $current = substr($source, $start, $end - $start);
        if ($expected !== '' && $current !== $expected) {
            return null;
        }

        return substr($source, 0, $start) . $replacement . substr($source, $end);
    }

    private function sliceFromOffsets(string $source, array $match): ?string
    {
        if (!isset($match['byte_start'], $match['byte_end'])) {
            return null;
        }

        $start = (int) $match['byte_start'];
        $end = (int) $match['byte_end'];
        if ($start < 0 || $end < $start || $end > strlen($source)) {
            return null;
        }

        return substr($source, $start, $end - $start);
    }

    private function reserveRange(array $match, array &$reservedRanges): void
    {
        if (!isset($match['byte_start'], $match['byte_end'])) {
            return;
        }

        $start = (int) $match['byte_start'];
        $end = (int) $match['byte_end'];
        if ($start < 0 || $end < $start) {
            return;
        }

        $reservedRanges[] = ['start' => $start, 'end' => $end];
    }

    private function reserveWholeFile(array &$reservedRanges, int $byteLength): void
    {
        $reservedRanges[] = ['start' => 0, 'end' => max($byteLength, 1), 'full_file' => true];
    }

    private function isOverlappingReservedRange(array $match, array $reservedRanges): bool
    {
        foreach ($reservedRanges as $range) {
            if (($range['full_file'] ?? false) === true) {
                return true;
            }
        }

        if (!isset($match['byte_start'], $match['byte_end'])) {
            return false;
        }

        $start = (int) $match['byte_start'];
        $end = (int) $match['byte_end'];
        if ($start < 0 || $end < $start) {
            return false;
        }

        foreach ($reservedRanges as $range) {
            if ($start < $range['end'] && $end > $range['start']) {
                return true;
            }
        }

        return false;
    }
}
