<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Project;

use DrupalEvolver\Project\GitProjectManager;
use PHPUnit\Framework\TestCase;

final class GitProjectManagerTest extends TestCase
{
    public function testMaterializeBranchForRunUsesEphemeralRunSourceAndCleanupRemovesIt(): void
    {
        $tmpDir = $this->createTempDir('evolver-git-project-manager-');
        $remotePath = $tmpDir . '/example-remote.git';
        $projectRoot = $tmpDir . '/cache/projects/example-repo';

        try {
            $this->createRemoteRepository($remotePath, ['main']);

            $manager = new GitProjectManager();
            $project = [
                'path' => $projectRoot,
                'remote_url' => $remotePath,
                'source_type' => 'git_remote',
            ];

            $materialized = $manager->materializeBranchForRun($project, 'main', 42);

            $this->assertSame($projectRoot . '/runs/42/source', $materialized['source_path']);
            $this->assertDirectoryExists($projectRoot . '/repo/.git');
            $this->assertDirectoryExists($materialized['source_path']);
            $this->assertFileExists($materialized['source_path'] . '/README.md');
            $this->assertNotSame('', (string) ($materialized['commit_sha'] ?? ''));

            $manager->cleanupRunSource($project, 42);

            $this->assertDirectoryDoesNotExist($projectRoot . '/runs/42/source');
            $this->assertDirectoryDoesNotExist($projectRoot . '/runs/42');
            $this->assertDirectoryExists($projectRoot . '/repo/.git');
        } finally {
            $this->removeDir($tmpDir);
        }
    }

    private function createTempDir(string $prefix): string
    {
        $base = rtrim(sys_get_temp_dir(), '/');
        $dir = $base . '/' . $prefix . uniqid('', true);
        mkdir($dir, 0777, true);

        return $dir;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($dir);
    }

    /**
     * @param list<string> $branches
     */
    private function createRemoteRepository(string $remotePath, array $branches): void
    {
        $workspace = dirname($remotePath) . '/workspace-' . bin2hex(random_bytes(4));
        mkdir($workspace, 0777, true);

        $this->runCommand(['git', 'init', '--bare', $remotePath]);
        $this->runCommand(['git', 'init', $workspace]);
        $this->runCommand(['git', '-C', $workspace, 'config', 'user.email', 'tests@example.com']);
        $this->runCommand(['git', '-C', $workspace, 'config', 'user.name', 'Tests']);

        file_put_contents($workspace . '/README.md', "# Test Repo\n");
        $this->runCommand(['git', '-C', $workspace, 'add', 'README.md']);
        $this->runCommand(['git', '-C', $workspace, 'commit', '-m', 'Initial commit']);
        $this->runCommand(['git', '-C', $workspace, 'remote', 'add', 'origin', $remotePath]);

        $primaryBranch = $branches[0] ?? 'main';
        $this->runCommand(['git', '-C', $workspace, 'branch', '-M', $primaryBranch]);
        $this->runCommand(['git', '-C', $workspace, 'push', '-u', 'origin', $primaryBranch]);

        foreach (array_slice($branches, 1) as $branch) {
            $this->runCommand(['git', '-C', $workspace, 'checkout', '-b', $branch]);
            file_put_contents($workspace . '/README.md', "# {$branch}\n");
            $this->runCommand(['git', '-C', $workspace, 'commit', '-am', "Branch {$branch}"]);
            $this->runCommand(['git', '-C', $workspace, 'push', '-u', 'origin', $branch]);
        }

        $this->removeDir($workspace);
    }

    /**
     * @param list<string> $command
     */
    private function runCommand(array $command): void
    {
        $descriptorSpec = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            $this->fail('Failed to start command: ' . implode(' ', $command));
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            $this->fail(sprintf(
                'Command failed (%d): %s%s%s',
                $exitCode,
                implode(' ', $command),
                $stdout ? "\nSTDOUT: " . trim($stdout) : '',
                $stderr ? "\nSTDERR: " . trim($stderr) : ''
            ));
        }
    }
}
