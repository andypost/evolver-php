<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Differ;

use DrupalEvolver\Storage\DatabaseApi;
use PHPUnit\Framework\TestCase;

class SymbolDifferTest extends TestCase
{
    private DatabaseApi $api;
    private int $oldVersionId;
    private int $newVersionId;
    private int $oldFileId;
    private int $newFileId;

    protected function setUp(): void
    {
        $this->api = new DatabaseApi(':memory:');

        $this->oldVersionId = $this->api->versions()->create('10.2.0', 10, 2, 0);
        $this->newVersionId = $this->api->versions()->create('10.3.0', 10, 3, 0);
        $this->oldFileId = $this->api->files()->create($this->oldVersionId, 'old.php', 'php', 'h1', null, null, 10, 100);
        $this->newFileId = $this->api->files()->create($this->newVersionId, 'new.php', 'php', 'h2', null, null, 10, 100);
    }

    public function testSignatureChangesAreNotReportedAsRemovedOrAdded(): void
    {
        $this->createFunctionSymbol($this->oldVersionId, $this->oldFileId, 'Drupal\\test_func', 'old_hash', [
            'params' => [['name' => '$a', 'type' => 'int']],
            'return_type' => null,
        ]);

        $this->createFunctionSymbol($this->newVersionId, $this->newFileId, 'Drupal\\test_func', 'new_hash', [
            'params' => [['name' => '$a', 'type' => 'string']],
            'return_type' => null,
        ]);

        $removed = iterator_to_array($this->api->findRemovedSymbols($this->oldVersionId, $this->newVersionId));
        $added = iterator_to_array($this->api->findAddedSymbols($this->oldVersionId, $this->newVersionId));
        $changed = iterator_to_array($this->api->findChangedSignatures($this->oldVersionId, $this->newVersionId));

        $this->assertCount(0, $removed);
        $this->assertCount(0, $added);
        $this->assertCount(1, $changed);
        $this->assertSame('Drupal\\test_func', $changed[0]['old']['fqn']);
    }

    public function testRemovedStillReportsMissingSymbols(): void
    {
        $this->createFunctionSymbol($this->oldVersionId, $this->oldFileId, 'Drupal\\removed_func', 'removed_hash', [
            'params' => [],
            'return_type' => null,
        ]);

        $removed = iterator_to_array($this->api->findRemovedSymbols($this->oldVersionId, $this->newVersionId));

        $this->assertCount(1, $removed);
        $this->assertSame('Drupal\\removed_func', $removed[0]['fqn']);
    }

    /**
     * @param array<string, mixed> $signature
     */
    private function createFunctionSymbol(int $versionId, int $fileId, string $fqn, string $hash, array $signature): void
    {
        $nameParts = explode('\\', $fqn);
        $shortName = (string) end($nameParts);

        $symbolId = $this->api->symbols()->create([
            'version_id' => $versionId,
            'file_id' => $fileId,
            'language' => 'php',
            'symbol_type' => 'function',
            'fqn' => $fqn,
            'name' => $shortName,
            'signature_hash' => $hash,
            'signature_json' => json_encode($signature),
        ]);
        $this->assertGreaterThan(0, $symbolId);
    }
}
