<?php

declare(strict_types=1);

namespace DrupalEvolver\Command;

use DrupalEvolver\Applier\TemplateApplier;
use DrupalEvolver\Project\GitProjectManager;
use DrupalEvolver\Storage\Database;
use DrupalEvolver\Storage\DatabaseApi;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

#[AsCommand(name: 'apply', description: 'Apply template-based fixes to scanned matches')]
class ApplyCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('project', 'p', InputOption::VALUE_REQUIRED, 'Project name')
            ->addOption('run', 'r', InputOption::VALUE_REQUIRED, 'Scan run id')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show diffs only, write nothing')
            ->addOption('interactive', 'i', InputOption::VALUE_NONE, 'Ask before each change')
            ->addOption('db', null, InputOption::VALUE_OPTIONAL, 'Database file path', Database::defaultPath());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectName = $input->getOption('project');
        $runId = $input->getOption('run');
        $dryRun = (bool) $input->getOption('dry-run');
        $interactive = (bool) $input->getOption('interactive');
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
        } else {
            $project = $api->projects()->findByName((string) $projectName);
            if ($project !== null) {
                $scanRun = $api->scanRuns()->findLatestByProject((int) $project['id'], 'completed');
            }
        }

        if (!$project) {
            $output->writeln(sprintf('<error>Project not found: %s</error>', (string) $projectName));
            return Command::FAILURE;
        }

        $confirm = null;
        if ($interactive) {
            $helper = $this->getHelper('question');
            $confirm = function () use ($helper, $input, $output) {
                $question = new ConfirmationQuestion('Apply this change? [y/N] ', false);
                return $helper->ask($input, $output, $question);
            };
        }

        $applier = new TemplateApplier($api);
        $projectPath = (string) (($scanRun['source_path'] ?? null) ?: $project['path']);
        if (
            ($project['source_type'] ?? 'local_path') === 'git_remote'
            && $scanRun !== null
            && !is_dir($projectPath)
        ) {
            $output->writeln('<comment>Materializing cached source for remote scan run...</comment>');
            $materialized = (new GitProjectManager())->materializeBranchForRun(
                $project,
                (string) ($scanRun['branch_name'] ?? ''),
                (int) $scanRun['id'],
                isset($scanRun['commit_sha']) && $scanRun['commit_sha'] !== '' ? (string) $scanRun['commit_sha'] : null,
            );
            $projectPath = $materialized['source_path'];
        }

        $stats = $applier->applyWithStats(
            (int) $project['id'],
            $projectPath,
            $scanRun !== null ? (int) $scanRun['id'] : null,
            $dryRun,
            $interactive,
            $output,
            $confirm,
        );

        if ($dryRun) {
            $output->writeln(sprintf(
                '<comment>%d fixes would apply (skipped: %d, failed: %d, conflicts: %d)</comment>',
                (int) $stats['would_apply'],
                (int) $stats['skipped'],
                (int) $stats['failed'],
                (int) $stats['conflicts'],
            ));
            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            '<info>%d fixes applied</info> (files changed: %d, skipped: %d, failed: %d, conflicts: %d)',
            (int) $stats['applied'],
            (int) $stats['files_changed'],
            (int) $stats['skipped'],
            (int) $stats['failed'],
            (int) $stats['conflicts'],
        ));

        return Command::SUCCESS;
    }
}
