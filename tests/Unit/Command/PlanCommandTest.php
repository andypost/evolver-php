<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Command;

use DrupalEvolver\ConsoleApplicationFactory;
use DrupalEvolver\Storage\DatabaseApi;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class PlanCommandTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = tempnam(sys_get_temp_dir(), 'evolver-plan-');
        if ($this->dbPath === false) {
            self::fail('Failed to create temporary database file.');
        }
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testPlanCommandPrintsTopologicalUpgradeOrder(): void
    {
        $api = new DatabaseApi($this->dbPath);

        $projectId = $api->projects()->save('custom_proj', '/app/web', 'drupal-site', '10.0.0');
        $runId = $api->scanRuns()->create($projectId, 'main', null, '/app/web', '10.0.0', '11.0.0', 'completed');

        $api->projectExtensions()->save(
            $projectId,
            'custom_core_api',
            'module',
            'Core API',
            [],
            'modules/custom/custom_core_api'
        );
        $api->projectExtensions()->save(
            $projectId,
            'custom_ecommerce',
            'module',
            'Ecommerce',
            ['custom_core_api'],
            'modules/custom/custom_ecommerce'
        );
        $api->projectExtensions()->save(
            $projectId,
            'custom_theme',
            'theme',
            'Custom Theme',
            ['custom_ecommerce', 'commerce'],
            'themes/custom/custom_theme'
        );

        for ($i = 0; $i < 2; $i++) {
            $_ = $api->db()->execute(
                'INSERT INTO code_matches (project_id, scan_run_id, scope_key, file_path, byte_start, byte_end, severity)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$projectId, $runId, "run:{$runId}", "modules/custom/custom_core_api/src/Foo{$i}.php", $i, $i + 10, 'breaking']
            );
        }

        $_ = $api->db()->execute(
            'INSERT INTO code_matches (project_id, scan_run_id, scope_key, file_path, byte_start, byte_end, severity)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$projectId, $runId, "run:{$runId}", 'modules/custom/custom_ecommerce/src/Bar.php', 0, 10, 'warning']
        );

        $command = ConsoleApplicationFactory::create()->find('report:plan');
        $tester = new CommandTester($command);
        $tester->execute(['--run' => (string) $runId, '--db' => $this->dbPath]);

        $this->assertSame(0, $tester->getStatusCode());

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Upgrade Plan for', $output);
        $this->assertStringContainsString('custom_core_api', $output);
        $this->assertStringContainsString('custom_ecommerce', $output);
        $this->assertStringContainsString('custom_theme', $output);
        $this->assertStringContainsString('2 total issues', $output);
        $this->assertStringContainsString('1 total issues', $output);
        $this->assertStringContainsString('Clean (Ready for upgrade)', $output);

        $corePos = strpos($output, 'custom_core_api');
        $commercePos = strpos($output, 'custom_ecommerce');
        $themePos = strpos($output, 'custom_theme');

        $this->assertNotFalse($corePos);
        $this->assertNotFalse($commercePos);
        $this->assertNotFalse($themePos);
        $this->assertTrue($corePos < $commercePos && $commercePos < $themePos, 'Extensions should appear in topological order.');
    }

    public function testPlanCommandByProjectUsesLatestCompletedRun(): void
    {
        $api = new DatabaseApi($this->dbPath);

        $projectId = $api->projects()->save('custom_proj', '/app/web', 'drupal-site', '10.0.0');
        $completedRunId = $api->scanRuns()->create($projectId, 'main', null, '/app/web', '10.0.0', '11.0.0', 'completed');
        $runningRunId = $api->scanRuns()->create($projectId, 'main', null, '/app/web', '10.0.0', '12.0.0', 'running');
        $this->assertGreaterThan($completedRunId, $runningRunId);

        $api->projectExtensions()->save(
            $projectId,
            'custom_core_api',
            'module',
            'Core API',
            [],
            'modules/custom/custom_core_api'
        );

        $_ = $api->db()->execute(
            'INSERT INTO code_matches (project_id, scan_run_id, scope_key, file_path, byte_start, byte_end, severity)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$projectId, $completedRunId, "run:{$completedRunId}", 'modules/custom/custom_core_api/src/Foo.php', 0, 10, 'breaking']
        );

        $command = ConsoleApplicationFactory::create()->find('report:plan');
        $tester = new CommandTester($command);
        $tester->execute(['--project' => 'custom_proj', '--db' => $this->dbPath]);

        $this->assertSame(0, $tester->getStatusCode());

        $output = $tester->getDisplay();
        $this->assertStringContainsString(sprintf('Run #%d', $completedRunId), $output);
        $this->assertStringContainsString('10.0.0 -> 11.0.0', $output);
        $this->assertStringNotContainsString('12.0.0', $output);
    }
}
