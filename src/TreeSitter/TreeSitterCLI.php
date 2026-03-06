<?php

declare(strict_types=1);

namespace DrupalEvolver\TreeSitter;

use RuntimeException;

class TreeSitterCLI
{
    private string $treeSitterBin;
    private string $configPath;

    public function __construct(?string $treeSitterBin = null, ?string $configPath = null)
    {
        $this->treeSitterBin = $treeSitterBin ?? ($this->findTreeSitter() ?? 'tree-sitter');
        $this->configPath = $configPath
            ?? ($_ENV['EVOLVER_CONFIG_PATH'] ?? '/app/tree-sitter-config.json');
    }

    public function parse(string $source, string $language): array
    {
        // Write source to temp file
        $tmpFile = tempnam(sys_get_temp_dir(), 'evolver-');
        // Add .php extension to help tree-sitter recognize the language
        $ext = match ($language) {
            'php' => '.php',
            'yaml' => '.yml',
            default => '',
        };
        $tmpFileWithExt = $tmpFile . $ext;
        file_put_contents($tmpFileWithExt, $source);

        try {
            // Run tree-sitter CLI
            $cmd = [
                $this->treeSitterBin,
                'parse',
                $tmpFileWithExt,
                '-j',
                '--config-path',
                $this->configPath,
            ];

            $output = $this->exec($cmd);
            $data = json_decode($output, true);

            if ($data === null) {
                throw new RuntimeException("Failed to decode tree-sitter output: " . json_last_error_msg() . "\nOutput was: " . $output);
            }

            return $data;
        } finally {
            @unlink($tmpFile);
            @unlink($tmpFileWithExt);
        }
    }

    public function query(string $source, string $language, string $pattern): array
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'evolver-');
        $ext = match ($language) {
            'php' => '.php',
            'yaml' => '.yml',
            default => '',
        };
        $tmpFileWithExt = $tmpFile . $ext;
        file_put_contents($tmpFileWithExt, $source);

        // Also write query to temp file
        $queryFile = tempnam(sys_get_temp_dir(), 'evolver-query-');
        file_put_contents($queryFile, $pattern);

        try {
            $cmd = [
                $this->treeSitterBin,
                'query',
                $queryFile,
                $tmpFileWithExt,
                '--config-path',
                $this->configPath,
            ];

            $output = $this->exec($cmd);
            // NOTE: Current tree-sitter CLI doesn't output JSON for queries.
            // This method might need to be rewritten to parse the text output
            // or we need a different CLI version.
            return ['output' => $output];
        } finally {
            @unlink($tmpFile);
            @unlink($tmpFileWithExt);
            @unlink($queryFile);
        }
    }

    public function getVersion(): string
    {
        $output = $this->exec([$this->treeSitterBin, '--version']);
        return trim($output);
    }

    private function exec(array $command): string
    {
        $descriptorspec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorspec, $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException("Failed to execute tree-sitter");
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new RuntimeException(
                "tree-sitter exited with code {$exitCode}\n" .
                "STDERR: {$stderr}\n" .
                "STDOUT: {$stdout}"
            );
        }

        return $stdout;
    }

    private function findTreeSitter(): ?string
    {
        $paths = [
            '/usr/bin/tree-sitter',
            '/usr/local/bin/tree-sitter',
            'tree-sitter',
        ];

        foreach ($paths as $path) {
            if (is_executable($path) || ($this->isCommandAvailable($path) && $this->commandExists($path))) {
                return $path;
            }
        }

        return null;
    }

    private function isCommandAvailable(string $command): bool
    {
        $test = @proc_open(
            ['which', $command],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        if (is_resource($test)) {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($test);
            return true;
        }

        return false;
    }

    private function commandExists(string $command): bool
    {
        return is_executable($command);
    }
}
