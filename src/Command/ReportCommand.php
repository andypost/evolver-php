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
            ->addOption('run', 'r', InputOption::VALUE_REQUIRED, 'Scan run id')
            ->addOption('format', 'f', InputOption::VALUE_OPTIONAL, 'Output format (table|json)', 'table')
            ->addOption('db', null, InputOption::VALUE_OPTIONAL, 'Database file path', Database::defaultPath());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectName = $input->getOption('project');
        $runId = $input->getOption('run');
        $format = $input->getOption('format');
        $dbPath = $input->getOption('db');

        if (!$projectName && !$runId) {
            $output->writeln('<error>Either --project or --run is required</error>');
            return Command::FAILURE;
        }

        $api = new DatabaseApi($dbPath);
        $project = null;
        $scanRun = null;

        if ($runId !== null) {
            $scanRun = $api->scanRuns()->findById((int) $runId);
            if ($scanRun === null) {
                $output->writeln(sprintf('<error>Scan run not found: %s</error>', (string) $runId));
                return Command::FAILURE;
            }

            $project = $api->projects()->findById((int) $scanRun['project_id']);
            $matches = $api->findMatchesWithChangesForRun((int) $scanRun['id']);
        } else {
            $project = $api->projects()->findByName((string) $projectName);
            if (!$project) {
                $output->writeln("<error>Project not found: {$projectName}</error>");
                return Command::FAILURE;
            }

            $scanRun = $api->scanRuns()->findLatestByProject((int) $project['id'], 'completed');
            $matches = $scanRun !== null
                ? $api->findMatchesWithChangesForRun((int) $scanRun['id'])
                : $api->findMatchesWithChanges((int) $project['id']);
        }

        if ($format === 'json') {
            $output->writeln(json_encode($matches, JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        }

        if ($project !== null) {
            $output->writeln(sprintf('Project: <info>%s</info>', (string) $project['name']));
        }
        if ($scanRun !== null) {
            $output->writeln(sprintf(
                'Run: <info>%d</info> (%s -> %s, %s)',
                (int) $scanRun['id'],
                (string) ($scanRun['from_core_version'] ?? 'unknown'),
                (string) ($scanRun['target_core_version'] ?? 'unknown'),
                (string) ($scanRun['status'] ?? 'unknown')
            ));
            $output->writeln('');
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
