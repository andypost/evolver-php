<?php

declare(strict_types=1);

namespace DrupalEvolver\Command;

use DrupalEvolver\Applier\TemplateApplier;
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
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show diffs only, write nothing')
            ->addOption('interactive', 'i', InputOption::VALUE_NONE, 'Ask before each change')
            ->addOption('db', null, InputOption::VALUE_OPTIONAL, 'Database file path', Database::defaultPath());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectName = $input->getOption('project');
        $dryRun = (bool) $input->getOption('dry-run');
        $interactive = (bool) $input->getOption('interactive');
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

        $confirm = null;
        if ($interactive) {
            $helper = $this->getHelper('question');
            $confirm = function () use ($helper, $input, $output) {
                $question = new ConfirmationQuestion('Apply this change? [y/N] ', false);
                return $helper->ask($input, $output, $question);
            };
        }

        $applier = new TemplateApplier($api);
        $stats = $applier->applyWithStats(
            (int) $project['id'],
            $project['path'],
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
