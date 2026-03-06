<?php

declare(strict_types=1);

namespace DrupalEvolver\Command;

use DrupalEvolver\Differ\FixTemplateGenerator;
use DrupalEvolver\Differ\RenameMatcher;
use DrupalEvolver\Differ\SignatureDiffer;
use DrupalEvolver\Differ\VersionDiffer;
use DrupalEvolver\Differ\YAMLDiffer;
use DrupalEvolver\Pattern\QueryGenerator;
use DrupalEvolver\Storage\Database;
use DrupalEvolver\Storage\DatabaseApi;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'diff', description: 'Compare two indexed versions and detect changes')]
class DiffCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Source version tag')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Target version tag')
            ->addOption('db', null, InputOption::VALUE_OPTIONAL, 'Database file path', Database::defaultPath())
            ->addOption('workers', 'w', InputOption::VALUE_OPTIONAL, 'Number of parallel workers')
            ->addOption('no-renames', null, InputOption::VALUE_NONE, 'Skip expensive rename matching')
            ->addOption('path', 'p', InputOption::VALUE_OPTIONAL, 'Filter by relative file path (e.g. core/modules/views)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $startTime = microtime(true);
        $from = $input->getOption('from');
        $to = $input->getOption('to');
        $dbPath = $input->getOption('db');
        $workers = $input->getOption('workers');
        $noRenames = $input->getOption('no-renames');
        $pathFilter = $input->getOption('path');

        if (!$from || !$to) {
            $output->writeln('<error>Both --from and --to are required</error>');
            return Command::FAILURE;
        }

        $api = new DatabaseApi($dbPath);

        $differ = new VersionDiffer(
            $api,
            new SignatureDiffer(),
            new RenameMatcher(),
            new YAMLDiffer(),
            new FixTemplateGenerator(),
            new QueryGenerator(),
        );

        if ($workers !== null) {
            $differ->setWorkerCount((int) $workers);
        }

        if ($noRenames) {
            $differ->setSkipRenames(true);
        }

        if ($pathFilter) {
            $differ->setPathFilter($pathFilter);
        }

        $changes = $differ->diff($from, $to, $output);

        // Summary
        $bySeverity = [];
        foreach ($changes as $change) {
            $severity = $change['severity'] ?? 'unknown';
            $bySeverity[$severity] = ($bySeverity[$severity] ?? 0) + 1;
        }

        $output->writeln(sprintf('Found <info>%d</info> changes between %s and %s:', count($changes), $from, $to));
        foreach ($bySeverity as $severity => $count) {
            $output->writeln(sprintf('  %s: <info>%d</info>', $severity, $count));
        }

        $elapsed = microtime(true) - $startTime;
        $output->writeln(sprintf('Total time: <info>%.2f</info> seconds', $elapsed));

        return Command::SUCCESS;
    }
}
