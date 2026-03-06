<?php

declare(strict_types=1);

namespace DrupalEvolver\Storage;

use PDO;
use PDOStatement;

class Database
{
    public const DEFAULT_PATH = '.data/evolver.sqlite';

    private PDO $pdo;
    private string $path;

    public function __construct(string $path = self::DEFAULT_PATH)
    {
        // Bare filenames (no directory separator) go into .data/
        if ($path !== ':memory:' && !str_contains($path, '/')) {
            $path = '.data/' . $path;
        }
        $this->path = $path;
        if ($path !== ':memory:') {
            $dir = dirname($path);
            if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
        }

        $this->pdo = new PDO('sqlite:' . $path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->pdo->exec('PRAGMA foreign_keys=ON');
        $this->pdo->exec('PRAGMA busy_timeout=30000');
        $this->pdo->exec('PRAGMA synchronous=NORMAL');
    }

    public static function defaultPath(): string
    {
        return $_ENV['EVOLVER_DB'] ?? getenv('EVOLVER_DB') ?: self::DEFAULT_PATH;
    }

    #[\NoDiscard]
    public function pdo(): PDO
    {
        return $this->pdo;
    }

    #[\NoDiscard]
    public function getPath(): string
    {
        return $this->path;
    }

    #[\NoDiscard]
    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    #[\NoDiscard]
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    #[\NoDiscard]
    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    #[\NoDiscard]
    public function transaction(callable $callback): mixed
    {
        $retries = 0;
        $maxRetries = 10;

        while (true) {
            try {
                $this->pdo->beginTransaction();
                try {
                    $result = $callback($this);
                    $this->pdo->commit();
                    return $result;
                } catch (\Throwable $e) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    throw $e;
                }
            } catch (\PDOException $e) {
                if ($retries < $maxRetries && (str_contains($e->getMessage(), 'database is locked') || $e->getCode() === 'HY000')) {
                    $retries++;
                    usleep(rand(200000, 1000000)); // 200ms - 1s
                    continue;
                }
                throw $e;
            }
        }
    }
}
