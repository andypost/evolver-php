<?php

declare(strict_types=1);

namespace DrupalEvolver\Command;

use DrupalEvolver\Project\GitProjectManager;
use DrupalEvolver\Project\ManagedProjectService;
use DrupalEvolver\Queue\JobQueue;
use DrupalEvolver\Scanner\MatchCollector;
use DrupalEvolver\Scanner\ProjectScanner;
use DrupalEvolver\Scanner\RunComparisonService;
use DrupalEvolver\Scanner\ScanRunService;
use DrupalEvolver\Storage\Database;
use DrupalEvolver\Storage\DatabaseApi;
use DrupalEvolver\TreeSitter\Parser;
use DrupalEvolver\Web\WebServer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'serve', description: 'Serve the Evolver web UI over Amp HTTP')]
final class ServeCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_OPTIONAL, 'Bind host', '0.0.0.0')
            ->addOption('port', null, InputOption::VALUE_OPTIONAL, 'Bind port', '8080')
            ->addOption('db', null, InputOption::VALUE_OPTIONAL, 'Database file path', Database::defaultPath());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dbPath = (string) $input->getOption('db');
        $host = (string) $input->getOption('host');
        $port = max(1, (int) $input->getOption('port'));

        $api = new DatabaseApi($dbPath);
        $parser = new Parser();
        $collector = new MatchCollector($parser->binding(), $parser->registry());
        $scanner = new ProjectScanner($parser, $api, $collector);
        $queue = new JobQueue($api);
        $webServer = new WebServer(
            $api,
            new ManagedProjectService($api),
            new ScanRunService($api, $scanner, $queue, new GitProjectManager()),
            new RunComparisonService($api),
            $queue,
        );

        $output->writeln(sprintf('Serving Evolver on <info>http://%s:%d</info>', $host, $port));
        $webServer->run($host, $port);

        return Command::SUCCESS;
    }
}
