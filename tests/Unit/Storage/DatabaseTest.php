<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Storage;

use DrupalEvolver\Storage\Database;
use PHPUnit\Framework\TestCase;

class DatabaseTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = new Database(':memory:');
    }

    public function testQueryAndExecute(): void
    {
        $this->db->pdo()->exec('CREATE TABLE test (id INTEGER PRIMARY KEY, name TEXT)');
        $affected = $this->db->execute('INSERT INTO test (name) VALUES (:name)', ['name' => 'foo']);
        $this->assertSame(1, $affected);

        $row = $this->db->query('SELECT * FROM test WHERE name = :name', ['name' => 'foo'])->fetch();
        $this->assertSame('foo', $row['name']);
    }

    public function testLastInsertId(): void
    {
        $this->db->pdo()->exec('CREATE TABLE test (id INTEGER PRIMARY KEY, name TEXT)');
        $affected = $this->db->execute('INSERT INTO test (name) VALUES (:name)', ['name' => 'bar']);
        $this->assertSame(1, $affected);
        $this->assertSame(1, $this->db->lastInsertId());
    }

    public function testTransaction(): void
    {
        $this->db->pdo()->exec('CREATE TABLE test (id INTEGER PRIMARY KEY, name TEXT)');

        $result = $this->db->transaction(function (Database $db) {
            $affected = $db->execute('INSERT INTO test (name) VALUES (:name)', ['name' => 'tx']);
            $this->assertSame(1, $affected);
            return $db->lastInsertId();
        });

        $this->assertSame(1, $result);
        $row = $this->db->query('SELECT * FROM test WHERE id = 1')->fetch();
        $this->assertSame('tx', $row['name']);
    }

    public function testTransactionRollback(): void
    {
        $this->db->pdo()->exec('CREATE TABLE test (id INTEGER PRIMARY KEY, name TEXT)');

        try {
            (void) $this->db->transaction(function (Database $db) {
                $affected = $db->execute('INSERT INTO test (name) VALUES (:name)', ['name' => 'fail']);
                $this->assertSame(1, $affected);
                throw new \RuntimeException('oops');
            });
        } catch (\RuntimeException) {
        }

        $count = (int) $this->db->query('SELECT COUNT(*) as cnt FROM test')->fetch()['cnt'];
        $this->assertSame(0, $count);
    }
}
