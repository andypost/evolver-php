<?php

declare(strict_types=1);

namespace DrupalEvolver\Storage\Repository;

use DrupalEvolver\Storage\Database;

class VersionRepo
{
    public function __construct(private Database $db) {}

    #[\NoDiscard]
    public function save(string $tag, int $major, int $minor, int $patch): int
    {
        $weight = $this->buildWeight($major, $minor, $patch);

        $written = $this->db->execute(
            "INSERT INTO versions (tag, major, minor, patch, weight, indexed_at)
             VALUES (:tag, :major, :minor, :patch, :weight, datetime('now'))
             ON CONFLICT(tag) DO UPDATE SET
                 major = excluded.major,
                 minor = excluded.minor,
                 patch = excluded.patch,
                 weight = excluded.weight,
                 indexed_at = datetime('now')",
            ['tag' => $tag, 'major' => $major, 'minor' => $minor, 'patch' => $patch, 'weight' => $weight]
        );
        $version = $this->findByTag($tag);
        if ($version === null) {
            throw new \LogicException(sprintf('Failed to persist version "%s" (affected rows: %d).', $tag, $written));
        }

        return (int) $version['id'];
    }

    #[\NoDiscard]
    public function create(string $tag, int $major, int $minor, int $patch): int
    {
        return $this->save($tag, $major, $minor, $patch);
    }

    #[\NoDiscard]
    public function findByTag(string $tag): ?array
    {
        $row = $this->db->query('SELECT * FROM versions WHERE tag = :tag', ['tag' => $tag])->fetch();
        return $row ?: null;
    }

    public function updateCounts(int $id, int $fileCount, int $symbolCount): int
    {
        return $this->db->execute(
            'UPDATE versions SET file_count = :fc, symbol_count = :sc WHERE id = :id',
            ['fc' => $fileCount, 'sc' => $symbolCount, 'id' => $id]
        );
    }

    #[\NoDiscard]
    public function all(): array
    {
        return $this->db->query('SELECT * FROM versions ORDER BY weight, id')->fetchAll();
    }

    private function buildWeight(int $major, int $minor, int $patch): int
    {
        return ($major * 1000000) + ($minor * 1000) + $patch;
    }
}
