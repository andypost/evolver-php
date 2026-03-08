<?php

declare(strict_types=1);

namespace DrupalEvolver\Project;

final class GitProjectManager
{
    /**
     * Detect available branches from a remote git repository.
     * @return list<string> List of branch names
     */
    public function detectRemoteBranches(string $remoteUrl, ?callable $logger = null): array
    {
        $tempDir = sys_get_temp_dir() . '/evolver_remote_detect_' . md5($remoteUrl . microtime(true));

        // Clean up any existing temp dir with this prefix
        if (is_dir($tempDir)) {
            $this->removeDirectory($tempDir);
        }

        $this->ensureDirectory($tempDir);

        try {
            if ($logger !== null) {
                $logger('info', sprintf('Detecting branches from %s', $remoteUrl));
            }

            // Initialize a temporary repo
            $this->run(['git', '-C', $tempDir, 'init'], null, $logger);
            $this->run(['git', '-C', $tempDir, 'remote', 'add', 'origin', $remoteUrl], null, $logger);

            // Fetch remote branches
            $this->run(['git', '-C', $tempDir, 'fetch', '--prune', 'origin'], null, $logger);

            // List remote branches
            $output = $this->run(['git', '-C', $tempDir, 'branch', '-r'], null, $logger);

            $branches = [];
            foreach (preg_split('/\R+/', $output) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_contains($line, 'HEAD ->')) {
                    continue;
                }
                // Extract branch name from "origin/main" format
                if (preg_match('#^origin/(\S+)$#', $line, $matches)) {
                    $branches[] = $matches[1];
                }
            }

            return array_values(array_unique($branches));
        } finally {
            // Clean up temp directory
            $this->removeDirectory($tempDir);
        }
    }

    /**
     * Detect project metadata from a local path.
     * @return array{type: ?string, branches: list<string>, current_version: ?string}
     */
    public function detectProjectMetadata(string $path, ?callable $logger = null): array
    {
        $typeDetector = new \DrupalEvolver\Scanner\ProjectTypeDetector();
        $versionDetector = new \DrupalEvolver\Scanner\VersionDetector();

        $type = $typeDetector->detect($path);
        $currentVersion = $versionDetector->detect($path);

        $branches = [];
        if (is_dir($path . '/.git')) {
            try {
                $output = $this->run(['git', '-C', $path, 'branch', '-r']);
                foreach (preg_split('/\R+/', $output) ?: [] as $line) {
                    $line = trim($line);
                    if ($line === '' || str_contains($line, 'HEAD ->')) {
                        continue;
                    }
                    if (preg_match('#^origin/(\S+)$#', $line, $matches)) {
                        $branches[] = $matches[1];
                    }
                }
            } catch (\Throwable) {
                // Not a git repo or git unavailable
            }
        }

        return [
            'type' => $type,
            'branches' => array_values(array_unique($branches)),
            'current_version' => $currentVersion,
        ];
    }

    /**
     * @return array{source_path: string, commit_sha: ?string}
     */
    #[\NoDiscard]
    public function materializeBranch(array $project, string $branchName, ?callable $logger = null): array
    {
        $sourceType = (string) ($project['source_type'] ?? 'local_path');

        if ($sourceType === 'local_path') {
            $path = (string) ($project['path'] ?? '');
            if ($path === '' || !is_dir($path)) {
                throw new \RuntimeException('Local project path is missing or unreadable.');
            }

            // If it's a git repo and the requested branch differs, check it out
            if (is_dir($path . '/.git')) {
                $currentBranch = trim($this->run(['git', '-C', $path, 'rev-parse', '--abbrev-ref', 'HEAD'], null, $logger));
                if ($currentBranch !== $branchName) {
                    if ($logger !== null) {
                        $logger('info', sprintf('Switching to branch %s', $branchName));
                    }
                    $this->run(['git', '-C', $path, 'checkout', $branchName], null, $logger);
                }
            }

            return [
                'source_path' => $path,
                'commit_sha' => $this->tryResolveHead($path),
            ];
        }

        return $this->materializeRemoteProjectSource($project, $branchName, 'manual-' . $this->slugify($branchName), null, $logger);
    }

    /**
     * @return array{source_path: string, commit_sha: ?string}
     */
    #[\NoDiscard]
    public function materializeBranchForRun(
        array $project,
        string $branchName,
        int $runId,
        ?string $commitSha = null,
        ?callable $logger = null,
    ): array {
        $sourceType = (string) ($project['source_type'] ?? 'local_path');

        if ($sourceType === 'local_path') {
            return $this->materializeBranch($project, $branchName, $logger);
        }

        return $this->materializeRemoteProjectSource($project, $branchName, (string) $runId, $commitSha, $logger);
    }

    public function cleanupRunSource(array $project, int $runId, ?callable $logger = null): void
    {
        $sourceType = (string) ($project['source_type'] ?? 'local_path');
        if ($sourceType === 'local_path') {
            return;
        }

        $rootPath = rtrim((string) ($project['path'] ?? ''), '/');
        if ($rootPath === '') {
            return;
        }

        $repoDir = $rootPath . '/repo';
        $runRoot = $rootPath . '/runs/' . $runId;
        $sourcePath = $runRoot . '/source';

        $this->removeMaterializedSource($repoDir, $sourcePath, $logger);

        if (is_dir($runRoot)) {
            $this->removeDirectory($runRoot);
        }
    }

    /**
     * @return array{source_path: string, commit_sha: ?string}
     */
    private function materializeRemoteProjectSource(
        array $project,
        string $branchName,
        string $runKey,
        ?string $requestedCommitSha,
        ?callable $logger = null,
    ): array {
        $rootPath = rtrim((string) ($project['path'] ?? ''), '/');
        $remoteUrl = (string) ($project['remote_url'] ?? '');
        if ($rootPath === '' || $remoteUrl === '') {
            throw new \RuntimeException('Managed git project is missing path or remote URL.');
        }

        $repoDir = $rootPath . '/repo';
        $runsRoot = $rootPath . '/runs';
        $runRoot = $runsRoot . '/' . $runKey;
        $sourcePath = $runRoot . '/source';

        $this->ensureDirectory($rootPath);
        $this->ensureDirectory($runsRoot);

        if (!is_dir($repoDir . '/.git')) {
            if ($logger !== null) {
                $logger('info', sprintf('Initializing repo from %s', $remoteUrl));
            }
            $this->ensureDirectory($repoDir);
            $this->run(['git', '-C', $repoDir, 'init'], null, $logger);
            $this->run(['git', '-C', $repoDir, 'remote', 'add', 'origin', $remoteUrl], null, $logger);
        }

        if ($logger !== null) {
            $logger('info', sprintf('Fetching origin for %s', $branchName));
        }
        $this->run(['git', '-C', $repoDir, 'fetch', '--prune', 'origin'], null, $logger);

        $commitSha = $requestedCommitSha !== null && $requestedCommitSha !== ''
            ? $this->resolveCommitSha($repoDir, $requestedCommitSha, $logger)
            : $this->resolveCommitSha($repoDir, 'refs/remotes/origin/' . $branchName, $logger);
        if ($commitSha === '') {
            throw new \RuntimeException(sprintf('Branch not found on origin: %s', $branchName));
        }

        $this->removeMaterializedSource($repoDir, $sourcePath, $logger);
        $this->ensureDirectory($runRoot);

        if ($logger !== null) {
            $logger('info', sprintf('Creating ephemeral worktree for %s', $branchName));
        }
        $this->run(['git', '-C', $repoDir, 'worktree', 'add', '--force', '--detach', $sourcePath, $commitSha], null, $logger);

        return [
            'source_path' => $sourcePath,
            'commit_sha' => $commitSha,
        ];
    }

    private function removeMaterializedSource(string $repoDir, string $sourcePath, ?callable $logger = null): void
    {
        if (is_dir($sourcePath . '/.git') || is_dir($sourcePath)) {
            try {
                $this->run(['git', '-C', $repoDir, 'worktree', 'remove', '--force', $sourcePath], null, $logger);
            } catch (\Throwable) {
                if (is_dir($sourcePath)) {
                    $this->removeDirectory($sourcePath);
                }
            }
        }
    }

    private function resolveCommitSha(string $repoDir, string $ref, ?callable $logger = null): string
    {
        return trim($this->run(['git', '-C', $repoDir, 'rev-parse', $ref . '^{commit}'], null, $logger));
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new \RuntimeException(sprintf('Unable to create directory: %s', $path));
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($path);
    }

    private function slugify(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?: 'branch';
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'branch';
    }

    private function tryResolveHead(string $path): ?string
    {
        try {
            $head = trim($this->run(['git', '-C', $path, 'rev-parse', 'HEAD']));
            return $head !== '' ? $head : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function run(array $command, ?string $cwd = null, ?callable $logger = null): string
    {
        $descriptorSpec = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, $cwd);
        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start process: ' . implode(' ', $command));
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        $stdout = is_string($stdout) ? trim($stdout) : '';
        $stderr = is_string($stderr) ? trim($stderr) : '';

        if ($stdout !== '') {
            foreach (preg_split('/\R+/', $stdout) ?: [] as $line) {
                if ($line !== '' && $logger !== null) {
                    $logger('debug', $line);
                }
            }
        }

        if ($stderr !== '') {
            foreach (preg_split('/\R+/', $stderr) ?: [] as $line) {
                if ($line !== '' && $logger !== null) {
                    $logger('warn', $line);
                }
            }
        }

        if ($exitCode !== 0) {
            throw new \RuntimeException(
                sprintf(
                    'Command failed (%d): %s%s',
                    $exitCode,
                    implode(' ', $command),
                    $stderr !== '' ? ' — ' . $stderr : ''
                )
            );
        }

        return $stdout;
    }
}
