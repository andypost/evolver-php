<?php

declare(strict_types=1);

namespace DrupalEvolver;

use DrupalEvolver\Command\ApplyCommand;
use DrupalEvolver\Command\CompareCommand;
use DrupalEvolver\Command\DiffCommand;
use DrupalEvolver\Command\ImportCommand;
use DrupalEvolver\Command\IndexCommand;
use DrupalEvolver\Command\PlanCommand;
use DrupalEvolver\Command\QueueWorkCommand;
use DrupalEvolver\Command\QueryCommand;
use DrupalEvolver\Command\ReportCommand;
use DrupalEvolver\Command\ScanCommand;
use DrupalEvolver\Command\ServeCommand;
use DrupalEvolver\Command\StatusCommand;
use Symfony\Component\Console\Application;

final class ConsoleApplicationFactory
{
    public static function create(): Application
    {
        // Safe Swoole initialization for commands/daemons that might use coroutines
        SwooleConfig::configure();

        $application = new Application('evolver', '0.1.0');
        $application->addCommands([
            new IndexCommand(),
            new DiffCommand(),
            new ScanCommand(),
            new ApplyCommand(),
            new ReportCommand(),
            new PlanCommand(),
            new StatusCommand(),
            new QueryCommand(),
            new CompareCommand(),
            new ImportCommand(),
            new ServeCommand(),
            new QueueWorkCommand(),
        ]);

        return $application;
    }
}
