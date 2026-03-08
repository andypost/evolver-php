<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Project;

use DrupalEvolver\Project\ManagedProjectService;
use DrupalEvolver\Storage\DatabaseApi;
use PHPUnit\Framework\TestCase;

final class ManagedProjectServiceTest extends TestCase
{
    public function testRegisterRemoteProjectUsesConfiguredCacheRoot(): void
    {
        $tmpDir = $this->createTempDir('evolver-managed-project-');
        $dbPath = $tmpDir . '/state.sqlite';
        $remotePath = $tmpDir . '/example-remote.git';
        $cacheRoot = $tmpDir . '/cache/projects';
        $previousCacheDir = getenv('EVOLVER_PROJECT_CACHE_DIR');

        try {
            $this->setProjectCacheDir($cacheRoot);
            $this->createRemoteRepository($remotePath, ['11.x']);
            $api = new DatabaseApi($dbPath);
            $service = new ManagedProjectService($api);

            $projectId = $service->registerRemoteProject(
                'Example Repo',
                $remotePath,
                '11.x',
                'module'
            );

            $project = $api->projects()->findById($projectId);
            $this->assertNotNull($project);
            $this->assertSame('git_remote', $project['source_type']);
            $this->assertSame($remotePath, $project['remote_url']);
            $this->assertSame('11.x', $project['default_branch']);
            $this->assertSame($cacheRoot . '/example-repo', $project['path']);

            $defaultBranch = $api->projectBranches()->findDefaultForProject($projectId);
            $this->assertNotNull($defaultBranch);
            $this->assertSame('11.x', $defaultBranch['branch_name']);
            $this->assertSame(1, (int) $defaultBranch['is_default']);
        } finally {
            $this->restoreProjectCacheDir($previousCacheDir);
            $this->removeDir($tmpDir);
        }
    }

    public function testRegisterRemoteProjectDefaultsToDotCacheProjects(): void
    {
        $tmpDir = $this->createTempDir('evolver-managed-project-');
        $cwd = getcwd();
        $remotePath = $tmpDir . '/default-cache-remote.git';
        $previousCacheDir = getenv('EVOLVER_PROJECT_CACHE_DIR');

        try {
            $this->restoreProjectCacheDir(false);
            chdir($tmpDir);
            $this->createRemoteRepository($remotePath, ['main']);
            $api = new DatabaseApi('state.sqlite');
            $service = new ManagedProjectService($api);

            $projectId = $service->registerRemoteProject(
                'Default Cache Repo',
                $remotePath,
                'main',
                'module'
            );

            $project = $api->projects()->findById($projectId);
            $this->assertNotNull($project);
            $this->assertSame('.cache/projects/default-cache-repo', $project['path']);
        } finally {
            if (is_string($cwd) && $cwd !== '') {
                chdir($cwd);
            }
            $this->restoreProjectCacheDir($previousCacheDir);
            $this->removeDir($tmpDir);
        }
    }

    public function testAddBranchCanPromoteDefaultBranch(): void
    {
        $tmpDir = $this->createTempDir('evolver-managed-project-');
        $remotePath = $tmpDir . '/branch-demo.git';
        $cacheRoot = $tmpDir . '/cache/projects';
        $previousCacheDir = getenv('EVOLVER_PROJECT_CACHE_DIR');

        try {
            $this->setProjectCacheDir($cacheRoot);
            $this->createRemoteRepository($remotePath, ['main', '11.x']);
            $api = new DatabaseApi(':memory:');
            $service = new ManagedProjectService($api);

            $projectId = $service->registerRemoteProject(
                'Branch Demo',
                $remotePath,
                'main',
                'module'
            );

            $newBranchId = $service->addBranch($projectId, '11.x', true);
            $this->assertGreaterThan(0, $newBranchId);

            $project = $api->projects()->findById($projectId);
            $this->assertNotNull($project);
            $this->assertSame('11.x', $project['default_branch']);

            $branches = $api->projectBranches()->findByProject($projectId);
            $indexed = [];
            foreach ($branches as $branch) {
                $indexed[$branch['branch_name']] = (int) $branch['is_default'];
            }

            $this->assertSame(1, $indexed['11.x'] ?? 0);
            $this->assertSame(0, $indexed['main'] ?? 0);
        } finally {
            $this->restoreProjectCacheDir($previousCacheDir);
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

    private function setProjectCacheDir(string $cacheRoot): void
    {
        putenv('EVOLVER_PROJECT_CACHE_DIR=' . $cacheRoot);
        $_ENV['EVOLVER_PROJECT_CACHE_DIR'] = $cacheRoot;
    }

    private function restoreProjectCacheDir(string|false $previous): void
    {
        if ($previous === false) {
            putenv('EVOLVER_PROJECT_CACHE_DIR');
            unset($_ENV['EVOLVER_PROJECT_CACHE_DIR']);
            return;
        }

        putenv('EVOLVER_PROJECT_CACHE_DIR=' . $previous);
        $_ENV['EVOLVER_PROJECT_CACHE_DIR'] = $previous;
    }
}
