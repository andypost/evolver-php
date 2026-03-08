<?php

declare(strict_types=1);

namespace DrupalEvolver\Storage\Repository;

use DrupalEvolver\Storage\Database;

final class ProjectExtensionRepo
{
    public function __construct(private Database $db) {}

    public function save(
        int $projectId,
        string $machineName,
        string $extensionType,
        ?string $label = null,
        array $dependencies = [],
        ?string $filePath = null
    ): void {
        $_ = $this->db->execute(
            'INSERT OR REPLACE INTO project_extensions (
                project_id, machine_name, extension_type, label, dependencies, file_path
             ) VALUES (:pid, :name, :type, :label, :deps, :path)',
            [
                'pid' => $projectId,
                'name' => $machineName,
                'type' => $extensionType,
                'label' => $label,
                'deps' => json_encode($dependencies, JSON_UNESCAPED_SLASHES),
                'path' => $filePath,
            ]
        );
    }

    #[\NoDiscard]
    public function findByProject(int $projectId): array
    {
        return $this->db->query(
            'SELECT * FROM project_extensions WHERE project_id = :pid ORDER BY extension_type, machine_name',
            ['pid' => $projectId]
        )->fetchAll();
    }

    #[\NoDiscard]
    public function findByName(int $projectId, string $machineName): ?array
    {
        $row = $this->db->query(
            'SELECT * FROM project_extensions WHERE project_id = :pid AND machine_name = :name',
            ['pid' => $projectId, 'name' => $machineName]
        )->fetch();

        return $row ?: null;
    }

    public function deleteAllForProject(int $projectId): void
    {
        $_ = $this->db->execute('DELETE FROM project_extensions WHERE project_id = :pid', ['pid' => $projectId]);
    }
}
