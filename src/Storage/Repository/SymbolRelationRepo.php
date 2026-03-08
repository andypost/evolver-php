<?php

declare(strict_types=1);

namespace DrupalEvolver\Storage\Repository;

use DrupalEvolver\Storage\Database;

final class SymbolRelationRepo
{
    public function __construct(private Database $db) {}

    #[\NoDiscard]
    public function create(
        int $versionId,
        int $fromSymbolId,
        int $toSymbolId,
        string $relationType,
        ?array $metadata = null,
    ): int {
        return $this->db->execute(
            'INSERT INTO symbol_relations (version_id, from_symbol_id, to_symbol_id, relation_type, metadata_json)
             VALUES (:vid, :from_sid, :to_sid, :rel_type, :meta)
             ON CONFLICT(version_id, from_symbol_id, to_symbol_id, relation_type) DO UPDATE SET
                metadata_json = excluded.metadata_json',
            [
                'vid' => $versionId,
                'from_sid' => $fromSymbolId,
                'to_sid' => $toSymbolId,
                'rel_type' => $relationType,
                'meta' => $metadata ? json_encode($metadata) : null,
            ]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[\NoDiscard]
    public function findByFromSymbol(int $fromSymbolId): array
    {
        return $this->db->query(
            'SELECT * FROM symbol_relations WHERE from_symbol_id = :from_sid',
            ['from_sid' => $fromSymbolId]
        )->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[\NoDiscard]
    public function findByToSymbol(int $toSymbolId): array
    {
        return $this->db->query(
            'SELECT * FROM symbol_relations WHERE to_symbol_id = :to_sid',
            ['to_sid' => $toSymbolId]
        )->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[\NoDiscard]
    public function findByVersion(int $versionId): array
    {
        return $this->db->query(
            'SELECT * FROM symbol_relations WHERE version_id = :vid',
            ['vid' => $versionId]
        )->fetchAll();
    }

    public const RELATION_SERVICE_CLASS = 'service_class';
    public const RELATION_CONTROLLER_ROUTE = 'controller_route';
    public const RELATION_PLUGIN_DEF = 'plugin_definition';
    public const RELATION_LIBRARY_ASSET = 'library_asset';
}
