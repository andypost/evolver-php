<?php

declare(strict_types=1);

namespace DrupalEvolver\Storage\Repository;

use DrupalEvolver\Storage\Database;

final class ExtensionRepo
{
    public function __construct(private Database $db) {}

    #[\NoDiscard]
    public function findByVersion(int $versionId): array
    {
        return $this->db->query(
            'SELECT * FROM extensions WHERE version_id = :vid ORDER BY extension_type, machine_name',
            ['vid' => $versionId]
        )->fetchAll();
    }

    #[\NoDiscard]
    public function findByName(int $versionId, string $machineName): ?array
    {
        $row = $this->db->query(
            'SELECT * FROM extensions WHERE version_id = :vid AND machine_name = :name',
            ['vid' => $versionId, 'name' => $machineName]
        )->fetch();

        return $row ?: null;
    }

    /**
     * Get extensions that depend on the given extension.
     */
    #[\NoDiscard]
    public function findDependents(int $versionId, string $machineName): array
    {
        return $this->db->query(
            "SELECT e.* 
             FROM extensions e, json_each(e.dependencies)
             WHERE e.version_id = :vid 
               AND json_each.value = :name",
            ['vid' => $versionId, 'name' => $machineName]
        )->fetchAll();
    }
}
