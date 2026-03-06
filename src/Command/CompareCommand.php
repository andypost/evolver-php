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
use DrupalEvolver\Storage\Database;
use DrupalEvolver\Storage\DatabaseApi;
use DrupalEvolver\TreeSitter\Parser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'compare', description: 'Directly compare two directories and detect changes')]
class CompareCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('path1', InputArgument::REQUIRED, 'First directory path')
            ->addArgument('path2', InputArgument::REQUIRED, 'Second directory path')
            ->addOption('tag1', null, InputOption::VALUE_OPTIONAL, 'Tag for first path', 'path1')
            ->addOption('tag2', null, InputOption::VALUE_OPTIONAL, 'Tag for second path', 'path2')
            ->addOption('db', null, InputOption::VALUE_OPTIONAL, 'Database file path', Database::defaultPath())
            ->addOption('workers', 'w', InputOption::VALUE_OPTIONAL, 'Number of parallel workers')
            ->addOption('no-renames', null, InputOption::VALUE_NONE, 'Skip expensive rename matching');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $startTime = microtime(true);
        $path1 = $input->getArgument('path1');
        $path2 = $input->getArgument('path2');
        $tag1 = $input->getOption('tag1');
        $tag2 = $input->getOption('tag2');
        $dbPath = $input->getOption('db');
        $workers = (int) ($input->getOption('workers') ?: 4);
        $noRenames = $input->getOption('no-renames');

        $api = new DatabaseApi($dbPath);
        $parser = new Parser();

        $indexer = new CoreIndexer($parser, $api);
        $indexer->setWorkerCount($workers);

        $output->writeln("<info>Indexing path 1: {$path1} as {$tag1}</info>");
        $indexer->index($path1, $tag1, $output);

        $output->writeln("\n<info>Indexing path 2: {$path2} as {$tag2}</info>");
        $indexer->index($path2, $tag2, $output);

        $differ = new VersionDiffer(
            $api,
            new SignatureDiffer(),
            new RenameMatcher(),
            new YAMLDiffer(),
            new FixTemplateGenerator(),
            new QueryGenerator(),
        );

        $differ->setWorkerCount($workers);
        if ($noRenames) {
            $differ->setSkipRenames(true);
        }

        $output->writeln("\n<info>Comparing {$tag1} and {$tag2}...</info>");
        $changes = $differ->diff($tag1, $tag2, $output);

        // Summary
        $bySeverity = [];
        foreach ($changes as $change) {
            $severity = $change['severity'] ?? 'unknown';
            $bySeverity[$severity] = ($bySeverity[$severity] ?? 0) + 1;
        }

        $output->writeln(sprintf("\nFound <info>%d</info> changes between paths:", count($changes)));
        foreach ($bySeverity as $severity => $count) {
            $output->writeln(sprintf('  %s: <info>%d</info>', $severity, $count));
        }

        $elapsed = microtime(true) - $startTime;
        $output->writeln(sprintf('Total time: <info>%.2f</info> seconds', $elapsed));

        return Command::SUCCESS;
    }
}
