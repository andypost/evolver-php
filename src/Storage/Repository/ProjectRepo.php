<?php

declare(strict_types=1);

namespace DrupalEvolver\Storage\Repository;

use DrupalEvolver\Storage\Database;

class ProjectRepo
{
    public function __construct(private Database $db) {}

    #[\NoDiscard]
    public function save(string $name, string $path, ?string $type, ?string $coreVersion): int
    {
        $inserted = $this->db->execute(
            'INSERT INTO projects (name, path, type, core_version)
             VALUES (:name, :path, :type, :cv)
             ON CONFLICT(path) DO UPDATE SET
                 name = excluded.name,
                 type = excluded.type,
                 core_version = excluded.core_version',
            ['name' => $name, 'path' => $path, 'type' => $type, 'cv' => $coreVersion]
        );

        $project = $this->findByPath($path);
        if ($project === null) {
            throw new \LogicException(sprintf('Failed to persist project "%s" (affected rows: %d).', $name, $inserted));
        }

        return (int) $project['id'];
    }

    #[\NoDiscard]
    public function create(string $name, string $path, ?string $type, ?string $coreVersion): int
    {
        return $this->save($name, $path, $type, $coreVersion);
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

    public function updateLastScanned(int $id): int
    {
        return $this->db->execute(
            "UPDATE projects SET last_scanned = datetime('now') WHERE id = :id",
            ['id' => $id]
        );
    }
}
