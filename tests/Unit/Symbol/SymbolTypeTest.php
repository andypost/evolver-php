<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Symbol;

use DrupalEvolver\Symbol\SymbolType;
use PHPUnit\Framework\TestCase;

final class SymbolTypeTest extends TestCase
{
    public function testFromSymbolReturnsEnumCaseForKnownType(): void
    {
        $symbol = ['symbol_type' => 'service'];

        $this->assertSame(SymbolType::Service, SymbolType::fromSymbol($symbol));
        $this->assertSame('service', SymbolType::valueFromSymbol($symbol));
    }

    public function testFromSymbolAcceptsEnumCasePayloads(): void
    {
        $symbol = ['symbol_type' => SymbolType::Hook];

        $this->assertSame(SymbolType::Hook, SymbolType::fromSymbol($symbol));
        $this->assertSame('hook', SymbolType::valueFromSymbol($symbol));
    }

    public function testFromSymbolReturnsNullForUnknownType(): void
    {
        $symbol = ['symbol_type' => 'php_file'];

        $this->assertNull(SymbolType::fromSymbol($symbol));
        $this->assertSame('php_file', SymbolType::valueFromSymbol($symbol));
    }

    public function testHookLikeDetectionSupportsEnumAndRawStrings(): void
    {
        $this->assertTrue(SymbolType::isHookLikeValue(SymbolType::Hook));
        $this->assertTrue(SymbolType::isHookLikeValue('custom_hook'));
        $this->assertFalse(SymbolType::isHookLikeValue(SymbolType::Service));
    }
}
