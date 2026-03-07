<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Command;

use DrupalEvolver\ConsoleApplicationFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class CommandRegistrationTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        $this->app = ConsoleApplicationFactory::create();
    }

    public function testAllCommandsRegistered(): void
    {
        $this->assertTrue($this->app->has('index'));
        $this->assertTrue($this->app->has('diff'));
        $this->assertTrue($this->app->has('scan'));
        $this->assertTrue($this->app->has('apply'));
        $this->assertTrue($this->app->has('report'));
        $this->assertTrue($this->app->has('status'));
        $this->assertTrue($this->app->has('query'));
        $this->assertTrue($this->app->has('compare'));
    }

    public function testStatusCommandRuns(): void
    {
        $command = $this->app->find('status');
        $tester = new CommandTester($command);
        $tester->execute(['--db' => ':memory:']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Evolver Status', $tester->getDisplay());
    }

    public function testIndexCommandRequiresTag(): void
    {
        $command = $this->app->find('index');
        $tester = new CommandTester($command);
        $tester->execute(['path' => '/nonexistent', '--db' => ':memory:']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('--tag is required', $tester->getDisplay());
    }

    public function testIndexCommandRequiresValidPath(): void
    {
        $command = $this->app->find('index');
        $tester = new CommandTester($command);
        $tester->execute(['path' => '/nonexistent', '--tag' => '10.3.0', '--db' => ':memory:']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Directory not found', $tester->getDisplay());
    }

    public function testDiffCommandRequiresVersions(): void
    {
        $command = $this->app->find('diff');
        $tester = new CommandTester($command);
        $tester->execute(['--db' => ':memory:']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('--from and --to are required', $tester->getDisplay());
    }

    public function testScanCommandRequiresTarget(): void
    {
        $command = $this->app->find('scan');
        $tester = new CommandTester($command);
        $tester->execute(['path' => '/tmp', '--db' => ':memory:']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('--target is required', $tester->getDisplay());
    }

    public function testApplyCommandRequiresProject(): void
    {
        $command = $this->app->find('apply');
        $tester = new CommandTester($command);
        $tester->execute(['--db' => ':memory:']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('--project is required', $tester->getDisplay());
    }

    public function testReportCommandRequiresProject(): void
    {
        $command = $this->app->find('report');
        $tester = new CommandTester($command);
        $tester->execute(['--db' => ':memory:']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('--project is required', $tester->getDisplay());
    }
}
