<?php

declare(strict_types=1);

namespace DrupalEvolver\Project;

use DrupalEvolver\Storage\DatabaseApi;
use DrupalEvolver\Scanner\ProjectTypeDetector;

final class ManagedProjectService
{
    private ProjectTypeDetector $typeDetector;
    private GitProjectManager $gitManager;

    public function __construct(
        private DatabaseApi $api,
        ?ProjectTypeDetector $typeDetector = null,
    ) {
        $this->typeDetector = $typeDetector ?? new ProjectTypeDetector();
        $this->gitManager = new GitProjectManager();
    }

    /**
     * Detect project metadata from a local path.
     * @return array{type: ?string, branches: list<string>, current_version: ?string}
     */
    public function detectMetadata(string $path): array
    {
        return $this->gitManager->detectProjectMetadata($path);
    }

    /**
     * Detect available branches from a remote repository.
     * @return list<string>
     */
    public function detectRemoteBranches(string $remoteUrl): array
    {
        return $this->gitManager->detectRemoteBranches($remoteUrl);
    }

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

        $realPath = realpath($path) ?: $path;

        // Auto-detect type if not provided
        if ($type === null) {
            $type = $this->typeDetector->detect($realPath);
        }

        $projectId = $this->api->projects()->save(
            $name,
            $realPath,
            $type,
            null,
            'local_path',
            null,
            $defaultBranch
        );

        $_ = $this->api->projectBranches()->save($projectId, $defaultBranch, true);

        // Detect and store additional branches from git
        $metadata = $this->detectMetadata($realPath);
        foreach ($metadata['branches'] as $branch) {
            if ($branch !== $defaultBranch) {
                $_ = $this->api->projectBranches()->save($projectId, $branch, false);
            }
        }

        // Store detected current version if available
        if (!empty($metadata['current_version'])) {
            $this->api->projects()->updateCoreVersion($projectId, $metadata['current_version']);
        }

        return $projectId;
    }

    #[\NoDiscard]
    public function registerRemoteProject(
        string $name,
        string $remoteUrl,
        string $defaultBranch = 'main',
        ?string $type = 'module',
        ?callable $logger = null,
    ): int {
        $slug = $this->slugify($name);
        $rootPath = rtrim($this->projectCacheBasePath(), '/') . '/' . $slug;

        // Detect and store available branches from remote
        $remoteBranches = $this->detectRemoteBranches($remoteUrl);

        $projectId = $this->api->projects()->save(
            $name,
            $rootPath,
            $type,
            null,
            'git_remote',
            $remoteUrl,
            $defaultBranch
        );

        $_ = $this->api->projectBranches()->save($projectId, $defaultBranch, true);

        // Store all detected remote branches
        foreach ($remoteBranches as $branch) {
            if ($branch !== $defaultBranch) {
                $_ = $this->api->projectBranches()->save($projectId, $branch, false);
            }
        }

        if ($logger !== null) {
            $logger('info', sprintf('Detected %d branches from remote', count($remoteBranches)));
        }

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

    private function projectCacheBasePath(): string
    {
        $configured = $_ENV['EVOLVER_PROJECT_CACHE_DIR'] ?? getenv('EVOLVER_PROJECT_CACHE_DIR') ?: '.cache/projects';

        return $configured === '' ? '.cache/projects' : $configured;
    }
}
