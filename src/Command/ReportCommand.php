<?php

declare(strict_types=1);

namespace DrupalEvolver\Command;

use DrupalEvolver\Storage\Database;
use DrupalEvolver\Storage\DatabaseApi;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'report', description: 'Show scan results and upgrade readiness')]
class ReportCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('project', 'p', InputOption::VALUE_REQUIRED, 'Project name')
            ->addOption('format', 'f', InputOption::VALUE_OPTIONAL, 'Output format (table|json)', 'table')
            ->addOption('db', null, InputOption::VALUE_OPTIONAL, 'Database file path', Database::defaultPath());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectName = $input->getOption('project');
        $format = $input->getOption('format');
        $dbPath = $input->getOption('db');

        if (!$projectName) {
            $output->writeln('<error>--project is required</error>');
            return Command::FAILURE;
        }

        $api = new DatabaseApi($dbPath);

        $project = $api->projects()->findByName($projectName);
        if (!$project) {
            $output->writeln("<error>Project not found: {$projectName}</error>");
            return Command::FAILURE;
        }

        $matches = $api->findMatchesWithChanges((int) $project['id']);

        if ($format === 'json') {
            $output->writeln(json_encode($matches, JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        }

        // Table format
        $table = new Table($output);
        $table->setHeaders(['File', 'Line', 'Change', 'Severity', 'Fix', 'Status']);

        foreach ($matches as $match) {
            $table->addRow([
                $match['file_path'],
                $match['line_start'],
                $match['change_type'] . ($match['old_fqn'] ? " ({$match['old_fqn']})" : ''),
                $match['severity'],
                $match['fix_method'] ?? 'manual',
                $match['status'],
            ]);
        }

        $table->render();

        // Summary
        $bySeverity = [];
        $autoFixable = 0;
        foreach ($matches as $match) {
            $s = $match['severity'];
            $bySeverity[$s] = ($bySeverity[$s] ?? 0) + 1;
            if ($match['fix_method'] === 'template') {
                $autoFixable++;
            }
        }

        $parts = [];
        foreach ($bySeverity as $s => $c) {
            $parts[] = "{$c} {$s}";
        }
        $parts[] = "{$autoFixable} auto-fixable";
        $output->writeln('Summary: ' . implode(', ', $parts));

        return Command::SUCCESS;
    }
}
