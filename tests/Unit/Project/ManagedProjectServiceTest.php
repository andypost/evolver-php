<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Project;

use DrupalEvolver\Project\ManagedProjectService;
use DrupalEvolver\Storage\DatabaseApi;
use PHPUnit\Framework\TestCase;

final class ManagedProjectServiceTest extends TestCase
{
    public function testRegisterRemoteProjectUsesActiveDatabaseDirectory(): void
    {
        $tmpDir = $this->createTempDir('evolver-managed-project-');
        $dbPath = $tmpDir . '/state.sqlite';

        try {
            $api = new DatabaseApi($dbPath);
            $service = new ManagedProjectService($api);

            $projectId = $service->registerRemoteProject(
                'Example Repo',
                'git@github.com:andypost/evolver-php.git',
                '11.x',
                'module'
            );

            $project = $api->projects()->findById($projectId);
            $this->assertNotNull($project);
            $this->assertSame('git_remote', $project['source_type']);
            $this->assertSame('git@github.com:andypost/evolver-php.git', $project['remote_url']);
            $this->assertSame('11.x', $project['default_branch']);
            $this->assertSame($tmpDir . '/repos/example-repo', $project['path']);

            $defaultBranch = $api->projectBranches()->findDefaultForProject($projectId);
            $this->assertNotNull($defaultBranch);
            $this->assertSame('11.x', $defaultBranch['branch_name']);
            $this->assertSame(1, (int) $defaultBranch['is_default']);
        } finally {
            $this->removeDir($tmpDir);
        }
    }

    public function testAddBranchCanPromoteDefaultBranch(): void
    {
        $api = new DatabaseApi(':memory:');
        $service = new ManagedProjectService($api);

        $projectId = $service->registerRemoteProject(
            'Branch Demo',
            'git@example.com:branch-demo.git',
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
}
