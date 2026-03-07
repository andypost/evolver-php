<?php

declare(strict_types=1);

namespace DrupalEvolver\Command;

use DrupalEvolver\Scanner\MatchCollector;
use DrupalEvolver\Scanner\ProjectScanner;
use DrupalEvolver\Storage\Database;
use DrupalEvolver\Storage\DatabaseApi;
use DrupalEvolver\TreeSitter\Parser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'scan', description: 'Scan a project against stored changes')]
class ScanCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::REQUIRED, 'Path to project')
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'Target Drupal version')
            ->addOption('from', null, InputOption::VALUE_OPTIONAL, 'Current version (auto-detected from composer.lock)')
            ->addOption('db', null, InputOption::VALUE_OPTIONAL, 'Database file path', Database::defaultPath())
            ->addOption('workers', 'w', InputOption::VALUE_OPTIONAL, 'Number of parallel workers');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $startTime = microtime(true);
        $path = $input->getArgument('path');
        $target = $input->getOption('target');
        $from = $input->getOption('from');
        $dbPath = $input->getOption('db');
        $workers = $input->getOption('workers');

        if (!$target) {
            $output->writeln('<error>--target is required</error>');
            return Command::FAILURE;
        }

        $api = new DatabaseApi($dbPath);
        $parser = new Parser();
        $matchCollector = new MatchCollector($parser->binding(), $parser->registry());

        $scanner = new ProjectScanner($parser, $api, $matchCollector);
        if ($workers !== null) {
            $scanner->setWorkerCount((int) $workers);
        }

        $runId = $scanner->scan($path, $target, $from, $output);
        $output->writeln(sprintf('Scan run: <info>%d</info>', $runId));

        $elapsed = microtime(true) - $startTime;
        $output->writeln(sprintf('Total time: <info>%.2f</info> seconds', $elapsed));

        return Command::SUCCESS;
    }
}
