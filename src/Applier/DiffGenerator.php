<?php

declare(strict_types=1);

namespace DrupalEvolver\Applier;

class DiffGenerator
{
    public function generate(string $original, string $modified, string $filePath, int $lineOffset = 0): string
    {
        $oldLines = explode("\n", $original);
        $newLines = explode("\n", $modified);

        $output = "--- a/{$filePath}\n";
        $output .= "+++ b/{$filePath}\n";
        $output .= sprintf("@@ -%d,%d +%d,%d @@\n", $lineOffset, count($oldLines), $lineOffset, count($newLines));

        foreach ($oldLines as $line) {
            $output .= "-{$line}\n";
        }
        foreach ($newLines as $line) {
            $output .= "+{$line}\n";
        }

        return $output;
    }
}
