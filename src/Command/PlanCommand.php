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

#[AsCommand(name: 'report:plan', description: 'Show the topologically sorted upgrade plan based on extension dependencies')]
class PlanCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('project', 'p', InputOption::VALUE_REQUIRED, 'Project name')
            ->addOption('run', 'r', InputOption::VALUE_REQUIRED, 'Scan run id')
            ->addOption('db', null, InputOption::VALUE_OPTIONAL, 'Database file path', Database::defaultPath());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectName = $input->getOption('project');
        $runId = $input->getOption('run');
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
            if (!$project) {
                $output->writeln("<error>Project not found: {$projectName}</error>");
                return Command::FAILURE;
            }
            $scanRun = $api->scanRuns()->findLatestByProject((int) $project['id'], 'completed');
        }

        if ($scanRun === null) {
            $output->writeln('<error>No completed scan run found for this project</error>');
            return Command::FAILURE;
        }

        $plan = $api->getProjectUpgradePlan((int) $scanRun['id'], (int) $project['id']);

        if ($plan === []) {
            $output->writeln('<info>No custom extensions found in this project.</info>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            "Upgrade Plan for <info>%s</info> (Run #%d: %s -> %s)",
            $project['name'],
            $scanRun['id'],
            $scanRun['from_core_version'],
            $scanRun['target_core_version']
        ));
        $output->writeln("Sorted by dependencies. Independent modules are listed first.\n");

        foreach ($plan as $index => $item) {
            $num = $index + 1;
            
            $header = sprintf(
                "[<info>%d</info>] <comment>%s</comment> (%s)",
                $num,
                $item['machine_name'],
                $item['type']
            );
            $output->writeln($header);
            
            if (!empty($item['dependencies'])) {
                $output->writeln("    Depends on: " . implode(', ', $item['dependencies']));
            }
            
            if ($item['match_count'] === 0) {
                $output->writeln("    <info>✅ Clean (Ready for upgrade)</info>");
            } else {
                $summary = [];
                if (($item['by_severity']['breaking'] ?? 0) > 0) {
                    $summary[] = sprintf("<fg=red>%d breaking</>", $item['by_severity']['breaking']);
                }
                if (($item['by_severity']['warning'] ?? 0) > 0) {
                    $summary[] = sprintf("<fg=yellow>%d warnings</>", $item['by_severity']['warning']);
                }
                if (($item['by_severity']['deprecation'] ?? 0) > 0) {
                    $summary[] = sprintf("<fg=yellow>%d deprecations</>", $item['by_severity']['deprecation']);
                }
                
                $output->writeln(sprintf(
                    "    <fg=red>✗</> %d total issues (%s)",
                    $item['match_count'],
                    implode(', ', $summary)
                ));
            }
            $output->writeln("");
        }

        return Command::SUCCESS;
    }
}
