<?php

declare(strict_types=1);

namespace DrupalEvolver;

use DrupalEvolver\Command\ApplyCommand;
use DrupalEvolver\Command\CompareCommand;
use DrupalEvolver\Command\DiffCommand;
use DrupalEvolver\Command\IndexCommand;
use DrupalEvolver\Command\QueryCommand;
use DrupalEvolver\Command\ReportCommand;
use DrupalEvolver\Command\ScanCommand;
use DrupalEvolver\Command\StatusCommand;
use Symfony\Component\Console\Application;

final class ConsoleApplicationFactory
{
    public static function create(): Application
    {
        SwooleConfig::configure();

        $application = new Application('evolver', '0.1.0');
        $application->addCommands([
            new IndexCommand(),
            new DiffCommand(),
            new ScanCommand(),
            new ApplyCommand(),
            new ReportCommand(),
            new StatusCommand(),
            new QueryCommand(),
            new CompareCommand(),
        ]);

        return $application;
    }
}
