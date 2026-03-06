<?php

declare(strict_types=1);

namespace DrupalEvolver\Command;

use DrupalEvolver\Indexer\CoreIndexer;
use DrupalEvolver\Storage\Database;
use DrupalEvolver\Storage\DatabaseApi;
use DrupalEvolver\TreeSitter\Parser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'index', description: 'Index a Drupal core version')]
class IndexCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::REQUIRED, 'Path to Drupal core checkout')
            ->addOption('tag', 't', InputOption::VALUE_REQUIRED, 'Version tag (e.g. 10.3.0)')
            ->addOption('db', null, InputOption::VALUE_OPTIONAL, 'Database file path', Database::defaultPath())
            ->addOption('no-ast', null, InputOption::VALUE_NONE, 'Skip storing AST s-expression (faster)')
            ->addOption('workers', 'w', InputOption::VALUE_OPTIONAL, 'Number of parallel workers');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $startTime = microtime(true);
        $path = $input->getArgument('path');
        $tag = $input->getOption('tag');
        $dbPath = $input->getOption('db');
        $storeAst = !$input->getOption('no-ast');
        $workers = $input->getOption('workers');

        if (!$tag) {
            $output->writeln('<error>--tag is required</error>');
            return Command::FAILURE;
        }

        if (!is_dir($path)) {
            $output->writeln("<error>Directory not found: {$path}</error>");
            return Command::FAILURE;
        }

        $api = new DatabaseApi($dbPath);
        $parser = new Parser();

        $indexer = new CoreIndexer($parser, $api);
        $indexer->setStoreAst($storeAst);
        if ($workers !== null) {
            $indexer->setWorkerCount((int) $workers);
        }
        $indexer->index($path, $tag, $output);

        $elapsed = microtime(true) - $startTime;
        $output->writeln(sprintf('Total time: <info>%.2f</info> seconds', $elapsed));

        return Command::SUCCESS;
    }
}
