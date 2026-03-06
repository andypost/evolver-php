<?php

declare(strict_types=1);

namespace DrupalEvolver\Differ;

use DrupalEvolver\Storage\Database;

class DeprecationTracker
{
    private ?string $pathFilter = null;

    public function __construct(private Database $db) {}

    public function setPathFilter(?string $path): void
    {
        $this->pathFilter = $path;
    }

    public function track(int $fromVersionId, int $toVersionId): array
    {
        $changes = [];

        // Newly deprecated: was not deprecated in old, is deprecated in new
        $sql = 'SELECT n.* FROM symbols n
                JOIN parsed_files f ON n.file_id = f.id
                JOIN symbols o ON o.fqn = n.fqn AND o.symbol_type = n.symbol_type
                WHERE n.version_id = :new AND o.version_id = :old
                  AND n.is_deprecated = 1 AND o.is_deprecated = 0';
        $params = ['old' => $fromVersionId, 'new' => $toVersionId];

        if ($this->pathFilter) {
            $sql .= ' AND f.file_path LIKE :path';
            $params['path'] = $this->pathFilter . '%';
        }

        $newlyDeprecated = $this->db->query($sql, $params)->fetchAll();

        foreach ($newlyDeprecated as $sym) {
            $changes[] = [
                'from_version_id' => $fromVersionId,
                'to_version_id' => $toVersionId,
                'language' => $sym['language'],
                'change_type' => 'deprecated_added',
                'severity' => 'deprecation',
                'new_symbol_id' => $sym['id'],
                'old_fqn' => $sym['fqn'],
                'new_fqn' => $sym['fqn'],
                'migration_hint' => $sym['deprecation_message'],
            ];
        }

        // Deprecated then removed: was deprecated in old, gone in new
        $sql = 'SELECT o.* FROM symbols o
                JOIN parsed_files f ON o.file_id = f.id
                WHERE o.version_id = :old AND o.is_deprecated = 1';
        $params = ['old' => $fromVersionId, 'new' => $toVersionId];

        if ($this->pathFilter) {
            $sql .= ' AND f.file_path LIKE :path';
            $params['path'] = $this->pathFilter . '%';
        }

        $sql .= ' AND NOT EXISTS (
                   SELECT 1 FROM symbols n
                   WHERE n.version_id = :new
                     AND n.symbol_type = o.symbol_type
                     AND n.fqn = o.fqn
               )';

        $deprecatedRemoved = $this->db->query($sql, $params)->fetchAll();

        foreach ($deprecatedRemoved as $sym) {
            $changes[] = [
                'from_version_id' => $fromVersionId,
                'to_version_id' => $toVersionId,
                'language' => $sym['language'],
                'change_type' => $sym['symbol_type'] . '_removed',
                'severity' => 'removal',
                'old_symbol_id' => $sym['id'],
                'old_fqn' => $sym['fqn'],
                'migration_hint' => $sym['deprecation_message'],
            ];
        }

        return $changes;
    }
}
