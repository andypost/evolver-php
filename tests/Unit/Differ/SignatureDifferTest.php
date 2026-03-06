<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Differ;

use DrupalEvolver\Differ\SignatureDiffer;
use PHPUnit\Framework\TestCase;

class SignatureDifferTest extends TestCase
{
    private SignatureDiffer $differ;

    protected function setUp(): void
    {
        $this->differ = new SignatureDiffer();
    }

    public function testNoChanges(): void
    {
        $json = json_encode(['params' => [['name' => '$a', 'type' => 'int']], 'return_type' => 'void']);
        $this->assertEmpty($this->differ->diff($json, $json));
    }

    public function testParameterAdded(): void
    {
        $old = json_encode(['params' => [['name' => '$a', 'type' => 'int']], 'return_type' => 'void']);
        $new = json_encode(['params' => [['name' => '$a', 'type' => 'int'], ['name' => '$b', 'type' => 'string']], 'return_type' => 'void']);

        $changes = $this->differ->diff($old, $new);
        $this->assertNotEmpty($changes);

        $added = array_filter($changes, fn($c) => $c['type'] === 'parameter_added');
        $this->assertCount(1, $added);
        $this->assertSame(1, reset($added)['position']);
    }

    public function testParameterRemoved(): void
    {
        $old = json_encode(['params' => [['name' => '$a'], ['name' => '$b']], 'return_type' => null]);
        $new = json_encode(['params' => [['name' => '$a']], 'return_type' => null]);

        $changes = $this->differ->diff($old, $new);
        $removed = array_filter($changes, fn($c) => $c['type'] === 'parameter_removed');
        $this->assertCount(1, $removed);
    }

    public function testParameterTypeChanged(): void
    {
        $old = json_encode(['params' => [['name' => '$a', 'type' => 'int']], 'return_type' => null]);
        $new = json_encode(['params' => [['name' => '$a', 'type' => 'string']], 'return_type' => null]);

        $changes = $this->differ->diff($old, $new);
        $typeChanged = array_filter($changes, fn($c) => $c['type'] === 'parameter_type_changed');
        $this->assertCount(1, $typeChanged);

        $change = reset($typeChanged);
        $this->assertSame('int', $change['old_type']);
        $this->assertSame('string', $change['new_type']);
    }

    public function testReturnTypeChanged(): void
    {
        $old = json_encode(['params' => [], 'return_type' => 'void']);
        $new = json_encode(['params' => [], 'return_type' => 'int']);

        $changes = $this->differ->diff($old, $new);
        $rtChanged = array_filter($changes, fn($c) => $c['type'] === 'return_type_changed');
        $this->assertCount(1, $rtChanged);
    }

    public function testNullInputs(): void
    {
        $this->assertEmpty($this->differ->diff(null, null));
    }
}
