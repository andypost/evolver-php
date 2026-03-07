<?php

declare(strict_types=1);

namespace DrupalEvolver\Command;

use DrupalEvolver\Differ\FixTemplateGenerator;
use DrupalEvolver\Differ\RenameMatcher;
use DrupalEvolver\Differ\SignatureDiffer;
use DrupalEvolver\Differ\VersionDiffer;
use DrupalEvolver\Differ\YAMLDiffer;
use DrupalEvolver\Indexer\CoreIndexer;
use DrupalEvolver\Pattern\QueryGenerator;
use DrupalEvolver\Project\GitProjectManager;
use DrupalEvolver\Queue\JobQueue;
use DrupalEvolver\Scanner\MatchCollector;
use DrupalEvolver\Scanner\ProjectScanner;
use DrupalEvolver\Scanner\ScanRunService;
use DrupalEvolver\Storage\Database;
use DrupalEvolver\Storage\DatabaseApi;
use DrupalEvolver\TreeSitter\Parser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'queue:work', description: 'Process persisted Evolver jobs')]
final class QueueWorkCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('once', null, InputOption::VALUE_NONE, 'Process at most one queued job')
            ->addOption('sleep', null, InputOption::VALUE_OPTIONAL, 'Idle sleep in seconds', '1')
            ->addOption('db', null, InputOption::VALUE_OPTIONAL, 'Database file path', Database::defaultPath());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dbPath = (string) $input->getOption('db');
        $sleepSeconds = max(1, (int) $input->getOption('sleep'));
        $once = (bool) $input->getOption('once');

        $api = new DatabaseApi($dbPath);
        $parser = new Parser();
        $collector = new MatchCollector($parser->binding(), $parser->registry());
        $scanner = new ProjectScanner($parser, $api, $collector);
        $queue = new JobQueue($api);
        $scanRuns = new ScanRunService($api, $scanner, $queue, new GitProjectManager());

        do {
            $job = $queue->claimNext();
            if ($job === null) {
                if ($once) {
                    return Command::SUCCESS;
                }

                sleep($sleepSeconds);
                continue;
            }

            $jobId = (int) $job['id'];
            $kind = (string) $job['kind'];
            $output->writeln(sprintf('<info>Processing job %d</info> (%s)', $jobId, $kind));

            try {
                match ($kind) {
                    'scan_branch' => $scanRuns->executeQueuedJob($job, $output),
                    'index_core' => $this->executeIndex($api, $parser, $queue, $job, $output),
                    'diff_versions' => $this->executeDiff($api, $queue, $job, $output),
                    default => throw new \RuntimeException(sprintf('Unknown job kind: %s', $kind)),
                };
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            }

            if ($once) {
                return Command::SUCCESS;
            }
        } while (true);
    }

    private function executeIndex(DatabaseApi $api, Parser $parser, JobQueue $queue, array $job, OutputInterface $output): void
    {
        $jobId = (int) $job['id'];
        $payload = $job['payload'] ?? [];
        $path = (string) ($payload['path'] ?? '');
        $tag = (string) ($payload['tag'] ?? '');
        $workers = max(1, (int) ($payload['workers'] ?? 4));

        if ($path === '' || $tag === '') {
            $queue->fail($jobId, 'Path and tag are required.');
            return;
        }

        if (!is_dir($path)) {
            $queue->fail($jobId, sprintf('Directory not found: %s', $path));
            return;
        }

        try {
            $queue->log($jobId, 'info', sprintf('Indexing %s as %s with %d workers', $path, $tag, $workers));
            $indexer = new CoreIndexer($parser, $api);
            $indexer->setStoreAst(false);
            $indexer->setWorkerCount($workers);
            $indexer->index($path, $tag, $output);
            $queue->log($jobId, 'info', 'Indexing completed');
            $queue->complete($jobId);
        } catch (\Throwable $e) {
            $queue->log($jobId, 'error', $e->getMessage());
            $queue->fail($jobId, $e->getMessage());
        }
    }

    private function executeDiff(DatabaseApi $api, JobQueue $queue, array $job, OutputInterface $output): void
    {
        $jobId = (int) $job['id'];
        $payload = $job['payload'] ?? [];
        $from = (string) ($payload['from'] ?? '');
        $to = (string) ($payload['to'] ?? '');
        $workers = max(1, (int) ($payload['workers'] ?? 4));

        if ($from === '' || $to === '') {
            $queue->fail($jobId, 'Both from and to versions are required.');
            return;
        }

        try {
            $queue->log($jobId, 'info', sprintf('Diffing %s → %s with %d workers', $from, $to, $workers));
            $differ = new VersionDiffer(
                $api,
                new SignatureDiffer(),
                new RenameMatcher(),
                new YAMLDiffer(),
                new FixTemplateGenerator(),
                new QueryGenerator(),
            );
            $differ->setWorkerCount($workers);
            $changes = $differ->diff($from, $to, $output);
            $queue->log($jobId, 'info', sprintf('Found %d changes', count($changes)));
            $queue->complete($jobId);
        } catch (\Throwable $e) {
            $queue->log($jobId, 'error', $e->getMessage());
            $queue->fail($jobId, $e->getMessage());
        }
    }
}
