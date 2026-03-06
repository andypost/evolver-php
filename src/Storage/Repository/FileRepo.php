<?php

declare(strict_types=1);

namespace DrupalEvolver\Storage\Repository;

use DrupalEvolver\Storage\Database;

class FileRepo
{
    public function __construct(private Database $db) {}

    #[\NoDiscard]
    public function save(
        int $versionId,
        string $filePath,
        string $language,
        string $fileHash,
        ?string $astSexp,
        ?string $astJson,
        ?int $lineCount,
        ?int $byteSize,
    ): int {
        $written = $this->db->execute(
            'INSERT INTO parsed_files (version_id, file_path, language, file_hash, ast_sexp, ast_json, line_count, byte_size)
             VALUES (:vid, :path, :lang, :hash, :sexp, :json, :lines, :bytes)
             ON CONFLICT(version_id, file_path) DO UPDATE SET
                 language = excluded.language,
                 file_hash = excluded.file_hash,
                 ast_sexp = excluded.ast_sexp,
                 ast_json = excluded.ast_json,
                 line_count = excluded.line_count,
                 byte_size = excluded.byte_size,
                 parsed_at = datetime(\'now\')',
            [
                'vid' => $versionId,
                'path' => $filePath,
                'lang' => $language,
                'hash' => $fileHash,
                'sexp' => $astSexp,
                'json' => $astJson,
                'lines' => $lineCount,
                'bytes' => $byteSize,
            ]
        );
        $file = $this->findByPath($versionId, $filePath);
        if ($file === null) {
            throw new \LogicException(sprintf('Failed to persist parsed file "%s" (affected rows: %d).', $filePath, $written));
        }

        return (int) $file['id'];
    }

    #[\NoDiscard]
    public function create(
        int $versionId,
        string $filePath,
        string $language,
        string $fileHash,
        ?string $astSexp,
        ?string $astJson,
        ?int $lineCount,
        ?int $byteSize,
    ): int {
        return $this->save($versionId, $filePath, $language, $fileHash, $astSexp, $astJson, $lineCount, $byteSize);
    }

    #[\NoDiscard]
    public function findByHash(int $versionId, string $fileHash): ?array
    {
        $row = $this->db->query(
            'SELECT * FROM parsed_files WHERE version_id = :vid AND file_hash = :hash',
            ['vid' => $versionId, 'hash' => $fileHash]
        )->fetch();
        return $row ?: null;
    }

    #[\NoDiscard]
    public function findByPath(int $versionId, string $filePath): ?array
    {
        $row = $this->db->query(
            'SELECT * FROM parsed_files WHERE version_id = :vid AND file_path = :path',
            ['vid' => $versionId, 'path' => $filePath]
        )->fetch();
        return $row ?: null;
    }
}
