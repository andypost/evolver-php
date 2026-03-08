<?php

declare(strict_types=1);

namespace DrupalEvolver\Storage\Repository;

use DrupalEvolver\Storage\Database;

final class ProjectRepo
{
    public function __construct(private Database $db) {}

    #[\NoDiscard]
    public function save(
        string $name,
        string $path,
        ?string $type,
        ?string $coreVersion,
        string $sourceType = 'local_path',
        ?string $remoteUrl = null,
        ?string $defaultBranch = null,
        ?string $packageName = null,
        ?string $rootName = null,
    ): int {
        $written = $this->db->execute(
            'INSERT INTO projects (name, path, type, source_type, remote_url, default_branch, core_version, package_name, root_name)
             VALUES (:name, :path, :type, :source_type, :remote_url, :default_branch, :core_version, :package_name, :root_name)
             ON CONFLICT(path) DO UPDATE SET
                 name = excluded.name,
                 type = excluded.type,
                 source_type = excluded.source_type,
                 remote_url = excluded.remote_url,
                 default_branch = excluded.default_branch,
                 core_version = excluded.core_version,
                 package_name = excluded.package_name,
                 root_name = excluded.root_name',
            [
                'name' => $name,
                'path' => $path,
                'type' => $type,
                'source_type' => $sourceType,
                'remote_url' => $remoteUrl,
                'default_branch' => $defaultBranch,
                'core_version' => $coreVersion,
                'package_name' => $packageName,
                'root_name' => $rootName,
            ]
        );

        $project = $this->findByPath($path);
        if ($project === null) {
            throw new \LogicException(sprintf('Failed to persist project "%s" (affected rows: %d).', $name, $written));
        }

        return (int) $project['id'];
    }

    #[\NoDiscard]
    public function create(
        string $name,
        string $path,
        ?string $type,
        ?string $coreVersion,
        string $sourceType = 'local_path',
        ?string $remoteUrl = null,
        ?string $defaultBranch = null,
        ?string $packageName = null,
        ?string $rootName = null,
    ): int {
        return $this->save($name, $path, $type, $coreVersion, $sourceType, $remoteUrl, $defaultBranch, $packageName, $rootName);
    }

    #[\NoDiscard]
    public function all(): array
    {
        return $this->db->query('SELECT * FROM projects ORDER BY name, id')->fetchAll();
    }

    #[\NoDiscard]
    public function findById(int $id): ?array
    {
        $row = $this->db->query('SELECT * FROM projects WHERE id = :id', ['id' => $id])->fetch();
        return $row ?: null;
    }

    #[\NoDiscard]
    public function findByName(string $name): ?array
    {
        $row = $this->db->query('SELECT * FROM projects WHERE name = :name', ['name' => $name])->fetch();
        return $row ?: null;
    }

    #[\NoDiscard]
    public function findByPath(string $path): ?array
    {
        $row = $this->db->query('SELECT * FROM projects WHERE path = :path', ['path' => $path])->fetch();
        return $row ?: null;
    }

    public function updateCoreVersion(int $id, ?string $coreVersion): int
    {
        return $this->db->execute(
            'UPDATE projects SET core_version = :core_version WHERE id = :id',
            ['core_version' => $coreVersion, 'id' => $id]
        );
    }

    public function updateDefaultBranch(int $id, ?string $defaultBranch): int
    {
        return $this->db->execute(
            'UPDATE projects SET default_branch = :default_branch WHERE id = :id',
            ['default_branch' => $defaultBranch, 'id' => $id]
        );
    }

    public function updateLastScanned(int $id): int
    {
        return $this->db->execute(
            "UPDATE projects SET last_scanned = datetime('now') WHERE id = :id",
            ['id' => $id]
        );
    }

    public function updateType(int $id, ?string $type): int
    {
        return $this->db->execute(
            'UPDATE projects SET type = :type WHERE id = :id',
            ['type' => $type, 'id' => $id]
        );
    }

    public function updatePackageName(int $id, ?string $packageName): int
    {
        return $this->db->execute(
            'UPDATE projects SET package_name = :package_name WHERE id = :id',
            ['package_name' => $packageName, 'id' => $id]
        );
    }

    public function updateRootName(int $id, ?string $rootName): int
    {
        return $this->db->execute(
            'UPDATE projects SET root_name = :root_name WHERE id = :id',
            ['root_name' => $rootName, 'id' => $id]
        );
    }

    public function updateMetadata(int $id, ?string $type, ?string $packageName, ?string $rootName): int
    {
        return $this->db->execute(
            'UPDATE projects SET type = :type, package_name = :package_name, root_name = :root_name WHERE id = :id',
            ['type' => $type, 'package_name' => $packageName, 'root_name' => $rootName, 'id' => $id]
        );
    }
}
