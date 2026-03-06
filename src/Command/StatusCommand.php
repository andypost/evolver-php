<?php

declare(strict_types=1);

namespace DrupalEvolver\Command;

use DrupalEvolver\Storage\Database;
use DrupalEvolver\Storage\DatabaseApi;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'status', description: 'Show database stats')]
class StatusCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('db', null, InputOption::VALUE_OPTIONAL, 'Database file path', Database::defaultPath());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dbPath = $input->getOption('db');
        $api = new DatabaseApi($dbPath);
        $stats = $api->getStats();

        $output->writeln('<info>Evolver Status</info>');
        $output->writeln('');
        $output->writeln(sprintf('Indexed versions: <info>%d</info>', count($stats['versions'])));
        foreach ($stats['versions'] as $v) {
            $output->writeln(sprintf('  %s — %d files, %d symbols (indexed %s)', $v['tag'], $v['file_count'], $v['symbol_count'], $v['indexed_at'] ?? 'unknown'));
        }
        $output->writeln(sprintf('Total symbols:    <info>%d</info>', $stats['symbol_count']));
        $output->writeln(sprintf('Total changes:    <info>%d</info>', $stats['change_count']));
        $output->writeln(sprintf('Projects scanned: <info>%d</info>', $stats['project_count']));
        $output->writeln(sprintf('Code matches:     <info>%d</info>', $stats['match_count']));

        return Command::SUCCESS;
    }
}
