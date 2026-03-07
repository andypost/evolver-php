<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Integration;

use DrupalEvolver\Differ\FixTemplateGenerator;
use DrupalEvolver\Differ\RenameMatcher;
use DrupalEvolver\Differ\SignatureDiffer;
use DrupalEvolver\Differ\VersionDiffer;
use DrupalEvolver\Differ\YAMLDiffer;
use DrupalEvolver\Indexer\CoreIndexer;
use DrupalEvolver\Pattern\QueryGenerator;
use DrupalEvolver\Project\GitProjectManager;
use DrupalEvolver\Project\ManagedProjectService;
use DrupalEvolver\Storage\DatabaseApi;
use DrupalEvolver\TreeSitter\Parser;
use PHPUnit\Framework\TestCase;

final class RemoteContactModulePipelineTest extends TestCase
{
    private const DEFAULT_REMOTE_URL = 'https://git.drupalcode.org/project/contact.git';
    private const DEFAULT_MODERN_BRANCH = '1.x';
    private const DEFAULT_LEGACY_BRANCH = '7.x-2.x';
    private const MODERN_TAG = '11.0.0-contact-remote-modern';
    private const LEGACY_TAG = '7.2.0-contact-remote-legacy';

    public function testRemoteContactModuleCanBeFetchedIndexedAndDiffed(): void
    {
        $this->requireNetworkTestEnabled();
        $remoteUrl = $this->remoteUrl();
        $this->assertRemoteReachableOrSkip($remoteUrl);

        $tmpDir = $this->createTempDir('evolver-remote-contact-');
        $dbPath = $tmpDir . '/state.sqlite';

        try {
            $api = new DatabaseApi($dbPath);
            $projects = new ManagedProjectService($api);
            $projectId = $projects->registerRemoteProject('Drupal Contact', $remoteUrl, $this->modernBranch(), 'module');
            (void) $projects->addBranch($projectId, $this->legacyBranch());

            $project = $api->projects()->findById($projectId);
            $this->assertNotNull($project);
            $this->assertSame('git_remote', $project['source_type']);

            $git = new GitProjectManager();
            $modern = $git->materializeBranch($project, $this->modernBranch());
            $legacy = $git->materializeBranch($project, $this->legacyBranch());

            $this->assertNotSame($modern['commit_sha'], $legacy['commit_sha']);
            $this->assertDirectoryExists($modern['source_path']);
            $this->assertDirectoryExists($legacy['source_path']);
            $this->assertFileExists($modern['source_path'] . '/contact.info.yml');
            $this->assertFileExists($modern['source_path'] . '/contact.services.yml');
            $this->assertFileExists($legacy['source_path'] . '/contact.info');
            $this->assertFileExists($legacy['source_path'] . '/contact.module');

            $parser = $this->createParserOrSkip();
            $indexer = new CoreIndexer($parser, $api);
            $indexer->setWorkerCount(1);
            $indexer->setStoreAst(false);
            $indexer->index($modern['source_path'], self::MODERN_TAG);
            $indexer->index($legacy['source_path'], self::LEGACY_TAG);

            $modernVersion = $api->versions()->findByTag(self::MODERN_TAG);
            $legacyVersion = $api->versions()->findByTag(self::LEGACY_TAG);
            $this->assertNotNull($modernVersion);
            $this->assertNotNull($legacyVersion);
            $this->assertGreaterThan(50, (int) $modernVersion['file_count']);
            $this->assertGreaterThan(100, (int) $modernVersion['symbol_count']);
            $this->assertGreaterThan(1, (int) $legacyVersion['file_count']);
            $this->assertGreaterThan(10, (int) $legacyVersion['symbol_count']);

            $differ = new VersionDiffer(
                $api,
                new SignatureDiffer(),
                new RenameMatcher(),
                new YAMLDiffer(),
                new FixTemplateGenerator(),
                new QueryGenerator(),
            );
            $differ->setWorkerCount(1);

            // Diff modern -> legacy so the current pipeline emits the richer removal-side YAML changes.
            $changes = $differ->diff(self::MODERN_TAG, self::LEGACY_TAG);
            $this->assertGreaterThan(50, count($changes));

            $changeTypes = array_values(array_unique(array_column($changes, 'change_type')));
            $this->assertContains('class_removed', $changeTypes);
            $this->assertContains('service_removed', $changeTypes);
            $this->assertContains('route_removed', $changeTypes);
            $this->assertContains('module_info_removed', $changeTypes);

            $storedChanges = $api->changes()->findByVersions((int) $modernVersion['id'], (int) $legacyVersion['id']);
            $this->assertCount(count($changes), $storedChanges);
        } finally {
            $this->removeDir($tmpDir);
        }
    }

    private function requireNetworkTestEnabled(): void
    {
        if (getenv('EVOLVER_RUN_NETWORK_TESTS') !== '1') {
            $this->markTestSkipped('Set EVOLVER_RUN_NETWORK_TESTS=1 to enable remote Git integration tests.');
        }
    }

    private function assertRemoteReachableOrSkip(string $remoteUrl): void
    {
        try {
            $output = $this->runCommand(['git', 'ls-remote', '--heads', $remoteUrl]);
            if ($output === '') {
                $this->markTestSkipped(sprintf('Remote did not return any refs: %s', $remoteUrl));
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped(sprintf('Remote Git endpoint is unreachable: %s', $e->getMessage()));
        }
    }

    private function remoteUrl(): string
    {
        $value = getenv('EVOLVER_REMOTE_CONTACT_URL');
        return is_string($value) && $value !== '' ? $value : self::DEFAULT_REMOTE_URL;
    }

    private function modernBranch(): string
    {
        $value = getenv('EVOLVER_REMOTE_CONTACT_MODERN_BRANCH');
        return is_string($value) && $value !== '' ? $value : self::DEFAULT_MODERN_BRANCH;
    }

    private function legacyBranch(): string
    {
        $value = getenv('EVOLVER_REMOTE_CONTACT_LEGACY_BRANCH');
        return is_string($value) && $value !== '' ? $value : self::DEFAULT_LEGACY_BRANCH;
    }

    private function createParserOrSkip(): Parser
    {
        try {
            return new Parser();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Tree-sitter parser unavailable: ' . $e->getMessage());
        }
    }

    /**
     * @param list<string> $command
     */
    private function runCommand(array $command): string
    {
        $descriptorSpec = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start process: ' . implode(' ', $command));
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        $stdout = is_string($stdout) ? trim($stdout) : '';
        $stderr = is_string($stderr) ? trim($stderr) : '';

        if ($exitCode !== 0) {
            throw new \RuntimeException(sprintf(
                'Command failed (%d): %s%s',
                $exitCode,
                implode(' ', $command),
                $stderr !== '' ? ' — ' . $stderr : ''
            ));
        }

        return $stdout;
    }

    private function createTempDir(string $prefix): string
    {
        $base = rtrim(sys_get_temp_dir(), '/');
        $dir = $base . '/' . $prefix . uniqid('', true);
        mkdir($dir, 0777, true);

        return $dir;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($dir);
    }
}
