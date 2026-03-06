<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Applier;

use DrupalEvolver\Applier\DiffGenerator;
use PHPUnit\Framework\TestCase;

class DiffGeneratorTest extends TestCase
{
    public function testGenerateDiff(): void
    {
        $generator = new DiffGenerator();
        $diff = $generator->generate('old_code()', 'new_code()', 'src/MyService.php', 42);

        $this->assertStringContainsString('--- a/src/MyService.php', $diff);
        $this->assertStringContainsString('+++ b/src/MyService.php', $diff);
        $this->assertStringContainsString('-old_code()', $diff);
        $this->assertStringContainsString('+new_code()', $diff);
    }
}
