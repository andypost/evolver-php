<?php

declare(strict_types=1);

namespace DrupalEvolver\Command;

use DrupalEvolver\TreeSitter\LanguageRegistry;
use DrupalEvolver\TreeSitter\Parser;
use DrupalEvolver\TreeSitter\Query;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'query', description: 'Run a raw tree-sitter query against a file')]
class QueryCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('pattern', InputArgument::REQUIRED, 'Tree-sitter S-expression query')
            ->addArgument('file', InputArgument::REQUIRED, 'File to query');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pattern = $input->getArgument('pattern');
        $file = $input->getArgument('file');

        if (!file_exists($file)) {
            $output->writeln("<error>File not found: {$file}</error>");
            return Command::FAILURE;
        }

        $ext = pathinfo($file, PATHINFO_EXTENSION);
        $langMap = [
            'php' => 'php', 'module' => 'php', 'inc' => 'php',
            'install' => 'php', 'profile' => 'php', 'theme' => 'php',
            'yml' => 'yaml', 'yaml' => 'yaml',
        ];

        $language = $langMap[$ext] ?? null;
        if (!$language) {
            $output->writeln("<error>Unsupported file extension: .{$ext}</error>");
            return Command::FAILURE;
        }

        $source = file_get_contents($file);
        $parser = new Parser();
        $tree = $parser->parse($source, $language);

        $registry = new LanguageRegistry();
        $lang = $registry->loadLanguage($language);
        $query = new Query($parser->binding(), $pattern, $lang);

        $matches = iterator_to_array($query->matches($tree->rootNode(), $source));

        if (empty($matches)) {
            $output->writeln('No matches found.');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('Found <info>%d</info> matches:', count($matches)));
        foreach ($matches as $i => $captures) {
            $output->writeln(sprintf("\nMatch #%d:", $i + 1));
            foreach ($captures as $name => $node) {
                $point = $node->startPoint();
                $output->writeln(sprintf(
                    "  @%s [%d:%d] %s = %s\n    SEXP: %s",
                    $name,
                    $point['row'] + 1,
                    $point['column'],
                    $node->type(),
                    json_encode($node->text()),
                    $node->sexp()
                ));
            }
        }

        return Command::SUCCESS;
    }
}
