<?php

declare(strict_types=1);

namespace DrupalEvolver\Project;

use DrupalEvolver\Storage\DatabaseApi;
use DrupalEvolver\Storage\Database;

final class ManagedProjectService
{
    public function __construct(private DatabaseApi $api) {}

    #[\NoDiscard]
    public function registerLocalProject(
        string $name,
        string $path,
        string $defaultBranch = 'main',
        ?string $type = 'module',
    ): int {
        if (!is_dir($path)) {
            throw new \InvalidArgumentException(sprintf('Path does not exist: %s', $path));
        }

        $projectId = $this->api->projects()->save(
            $name,
            realpath($path) ?: $path,
            $type,
            null,
            'local_path',
            null,
            $defaultBranch
        );

        (void) $this->api->projectBranches()->save($projectId, $defaultBranch, true);

        return $projectId;
    }

    #[\NoDiscard]
    public function registerRemoteProject(
        string $name,
        string $remoteUrl,
        string $defaultBranch = 'main',
        ?string $type = 'module',
    ): int {
        $slug = $this->slugify($name);
        $dbPath = $this->api->getPath();
        $rootBase = $dbPath === ':memory:' ? dirname(Database::defaultPath()) : dirname($dbPath);
        $rootPath = rtrim($rootBase, '/') . '/repos/' . $slug;

        $projectId = $this->api->projects()->save(
            $name,
            $rootPath,
            $type,
            null,
            'git_remote',
            $remoteUrl,
            $defaultBranch
        );

        (void) $this->api->projectBranches()->save($projectId, $defaultBranch, true);

        return $projectId;
    }

    #[\NoDiscard]
    public function addBranch(int $projectId, string $branchName, bool $isDefault = false): int
    {
        $branchId = $this->api->projectBranches()->save($projectId, $branchName, $isDefault);
        if ($isDefault) {
            $this->api->projects()->updateDefaultBranch($projectId, $branchName);
        }

        return $branchId;
    }

    private function slugify(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?: 'project';
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'project';
    }
}
