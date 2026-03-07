<?php

declare(strict_types=1);

namespace DrupalEvolver\Project;

final class GitProjectManager
{
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

        $rootPath = rtrim((string) ($project['path'] ?? ''), '/');
        $remoteUrl = (string) ($project['remote_url'] ?? '');
        if ($rootPath === '' || $remoteUrl === '') {
            throw new \RuntimeException('Managed git project is missing path or remote URL.');
        }

        $repoDir = $rootPath . '/repo';
        $worktreeRoot = $rootPath . '/worktrees';
        $worktreePath = $worktreeRoot . '/' . $this->slugify($branchName);

        $this->ensureDirectory($rootPath);
        $this->ensureDirectory($worktreeRoot);

        if (!is_dir($repoDir . '/.git')) {
            if ($logger !== null) {
                $logger('info', sprintf('Cloning %s', $remoteUrl));
            }
            $this->run(['git', 'clone', '--no-checkout', $remoteUrl, $repoDir], null, $logger);
        }

        if ($logger !== null) {
            $logger('info', sprintf('Fetching origin for %s', $branchName));
        }
        $this->run(['git', '-C', $repoDir, 'fetch', '--prune', 'origin'], null, $logger);

        $remoteRef = 'refs/remotes/origin/' . $branchName;
        $commitSha = trim($this->run(['git', '-C', $repoDir, 'rev-parse', $remoteRef], null, $logger));
        if ($commitSha === '') {
            throw new \RuntimeException(sprintf('Branch not found on origin: %s', $branchName));
        }

        if (!is_dir($worktreePath . '/.git')) {
            if ($logger !== null) {
                $logger('info', sprintf('Creating worktree for %s', $branchName));
            }
            $this->run(['git', '-C', $repoDir, 'worktree', 'add', '--force', '--detach', $worktreePath, $commitSha], null, $logger);
        } else {
            if ($logger !== null) {
                $logger('info', sprintf('Refreshing worktree for %s', $branchName));
            }
            $this->run(['git', '-C', $worktreePath, 'checkout', '--detach', $commitSha], null, $logger);
            $this->run(['git', '-C', $worktreePath, 'reset', '--hard', $commitSha], null, $logger);
            $this->run(['git', '-C', $worktreePath, 'clean', '-fd'], null, $logger);
        }

        return [
            'source_path' => $worktreePath,
            'commit_sha' => $commitSha,
        ];
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new \RuntimeException(sprintf('Unable to create directory: %s', $path));
        }
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
