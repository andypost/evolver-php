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

#[AsCommand(name: 'import', description: 'Import D7→D8+ upgrade patterns from DMU config')]
class ImportCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('source', null, InputOption::VALUE_OPTIONAL, 'Import source type', 'dmu')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Path to drupalmoduleupgrader directory')
            ->addOption('db', null, InputOption::VALUE_OPTIONAL, 'Database file path', Database::defaultPath());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $source = $input->getOption('source');
        $path = $input->getOption('path');
        $dbPath = $input->getOption('db');

        if ($source !== 'dmu') {
            $output->writeln("<error>Unknown source: {$source}. Only 'dmu' is supported.</error>");
            return Command::FAILURE;
        }

        if (!$path || !is_dir($path)) {
            $output->writeln('<error>--path must point to the drupalmoduleupgrader directory</error>');
            return Command::FAILURE;
        }

        $configDir = rtrim($path, '/') . '/config/install';
        if (!is_dir($configDir)) {
            $output->writeln("<error>Config directory not found: {$configDir}</error>");
            return Command::FAILURE;
        }

        $api = new DatabaseApi($dbPath);

        // Create synthetic D7 version pair
        $fromVersionId = $api->versions()->save('7.0.0', 7, 0, 0);
        $toVersionId = $api->versions()->save('8.0.0', 8, 0, 0);

        $output->writeln('<info>Importing DMU patterns...</info>');

        $changes = [];

        // 1. Import grep.yml — function call renames, globals, constants
        $grepFile = $configDir . '/drupalmoduleupgrader.grep.yml';
        if (file_exists($grepFile)) {
            $grep = $this->parseYaml($grepFile);

            // Function call renames
            $functionCalls = $grep['function_calls'] ?? [];
            foreach ($functionCalls as $oldFunc => $newFunc) {
                $changes[] = $this->buildFunctionCallRewrite(
                    $fromVersionId, $toVersionId,
                    (string) $oldFunc, (string) $newFunc
                );
            }
            $output->writeln(sprintf('  grep.yml function_calls: <info>%d</info> patterns', count($functionCalls)));

            // Global variables
            $globals = $grep['globals'] ?? [];
            foreach ($globals as $variable => $replacement) {
                $changes[] = $this->buildGlobalReplace(
                    $fromVersionId, $toVersionId,
                    (string) $variable, (string) $replacement
                );
            }
            $output->writeln(sprintf('  grep.yml globals: <info>%d</info> patterns', count($globals)));

            // Constants
            $constants = $grep['constants'] ?? [];
            foreach ($constants as $oldConst => $newConst) {
                $changes[] = $this->buildConstantReplace(
                    $fromVersionId, $toVersionId,
                    (string) $oldConst, (string) $newConst
                );
            }
            $output->writeln(sprintf('  grep.yml constants: <info>%d</info> patterns', count($constants)));
        }

        // 2. Import functions.yml — deprecated/removed functions
        $functionsFile = $configDir . '/drupalmoduleupgrader.functions.yml';
        if (file_exists($functionsFile)) {
            $functions = $this->parseYaml($functionsFile);
            $functionCount = 0;

            foreach ($functions as $groupName => $group) {
                $message = (string) ($group['message'] ?? '');
                $docs = $group['documentation'] ?? [];
                $docUrl = !empty($docs) ? (string) ($docs[0]['url'] ?? '') : '';
                $fixme = $group['fixme'] ?? null;
                $disable = (bool) ($group['disable'] ?? false);
                $functionNames = $group['functions'] ?? [$groupName];

                foreach ($functionNames as $funcName) {
                    $funcName = (string) $funcName;
                    $migrationHint = str_replace('@function', $funcName, $message);
                    if ($fixme) {
                        $migrationHint .= "\n" . str_replace('@function', $funcName, (string) $fixme);
                    }
                    if ($docUrl !== '') {
                        $migrationHint .= "\nSee: " . $docUrl;
                    }

                    // Check if we already have a function_call_rewrite for this
                    $hasRewrite = false;
                    foreach ($changes as $existing) {
                        if (($existing['old_fqn'] ?? '') === $funcName && $existing['change_type'] === 'function_call_rewrite') {
                            $hasRewrite = true;
                            break;
                        }
                    }

                    if (!$hasRewrite) {
                        $changes[] = [
                            'from_version_id' => $fromVersionId,
                            'to_version_id' => $toVersionId,
                            'language' => 'php',
                            'change_type' => $disable ? 'function_disabled' : 'function_removed',
                            'severity' => 'breaking',
                            'old_fqn' => $funcName,
                            'migration_hint' => $migrationHint,
                            'ts_query' => "(function_call_expression function: (name) @fn (#eq? @fn \"{$this->escape($funcName)}\"))",
                            'confidence' => 1.0,
                        ];
                    }
                    $functionCount++;
                }
            }
            $output->writeln(sprintf('  functions.yml: <info>%d</info> deprecated functions', $functionCount));
        }

        // 3. Import hooks.yml — removed hooks
        $hooksFile = $configDir . '/drupalmoduleupgrader.hooks.yml';
        if (file_exists($hooksFile)) {
            $hooks = $this->parseYaml($hooksFile);
            $hookCount = 0;

            foreach ($hooks as $groupName => $group) {
                $message = (string) ($group['message'] ?? '');
                $docs = $group['documentation'] ?? [];
                $docUrl = !empty($docs) ? (string) ($docs[0]['url'] ?? '') : '';
                $delete = (bool) ($group['delete'] ?? false);
                $hookNames = $group['hook'] ?? [$groupName];

                foreach ($hookNames as $hookName) {
                    $hookName = (string) $hookName;
                    $migrationHint = str_replace('@hook', "hook_{$hookName}", $message);
                    if ($docUrl !== '') {
                        $migrationHint .= "\nSee: " . $docUrl;
                    }

                    $changes[] = [
                        'from_version_id' => $fromVersionId,
                        'to_version_id' => $toVersionId,
                        'language' => 'php',
                        'change_type' => $delete ? 'hook_removed' : 'hook_deprecated',
                        'severity' => $delete ? 'breaking' : 'deprecation',
                        'old_fqn' => "hook_{$hookName}",
                        'migration_hint' => $migrationHint,
                        'ts_query' => $this->hookImplementationQuery($hookName),
                        'confidence' => 1.0,
                    ];
                    $hookCount++;
                }
            }
            $output->writeln(sprintf('  hooks.yml: <info>%d</info> deprecated hooks', $hookCount));
        }

        // 4. Import rewriters.yml — property access patterns
        $rewritersFile = $configDir . '/drupalmoduleupgrader.rewriters.yml';
        if (file_exists($rewritersFile)) {
            $rewriters = $this->parseYaml($rewritersFile);
            $propCount = 0;

            foreach ($rewriters as $entityType => $config) {
                $typeHint = (string) ($config['type_hint'] ?? '');
                $properties = $config['properties'] ?? [];

                foreach ($properties as $property => $mapping) {
                    $getter = (string) ($mapping['get'] ?? '');
                    $setter = (string) ($mapping['set'] ?? '');
                    if ($getter === '') {
                        continue;
                    }

                    $property = (string) $property;

                    $changes[] = [
                        'from_version_id' => $fromVersionId,
                        'to_version_id' => $toVersionId,
                        'language' => 'php',
                        'change_type' => 'variable_access_replaced',
                        'severity' => 'breaking',
                        'old_fqn' => "{$entityType}.{$property}",
                        'new_fqn' => $typeHint !== '' ? "{$typeHint}::{$getter}()" : $getter,
                        'migration_hint' => "\${$entityType}->{$property} → \${$entityType}->{$getter}()",
                        'ts_query' => "(member_access_expression name: (name) @prop (#eq? @prop \"{$this->escape($property)}\"))",
                        'fix_template' => json_encode([
                            'type' => 'variable_access',
                            'property' => $property,
                            'getter' => $getter,
                            'setter' => $setter,
                            'entity_type' => $entityType,
                            'type_hint' => $typeHint,
                        ], JSON_UNESCAPED_SLASHES),
                        'confidence' => 0.7,
                    ];
                    $propCount++;
                }
            }
            $output->writeln(sprintf('  rewriters.yml: <info>%d</info> property access patterns', $propCount));
        }

        // Store all changes
        $output->writeln(sprintf("\nStoring <info>%d</info> total changes...", count($changes)));

        $storedChanges = $api->db()->transaction(function () use ($api, $changes): int {
            return $api->changes()->createBatch($changes);
        });

        $output->writeln(sprintf('<info>Done! Stored %d changes.</info>', $storedChanges));

        // Summary
        $stats = $api->getStats();
        $output->writeln('');
        $output->writeln(sprintf('Database: %s', $api->getPath()));
        $output->writeln(sprintf('Total changes: <info>%d</info>', $stats['change_count']));

        return Command::SUCCESS;
    }

    private function buildFunctionCallRewrite(int $fromId, int $toId, string $oldFunc, string $newFunc): array
    {
        return [
            'from_version_id' => $fromId,
            'to_version_id' => $toId,
            'language' => 'php',
            'change_type' => 'function_call_rewrite',
            'severity' => 'breaking',
            'old_fqn' => $oldFunc,
            'new_fqn' => $newFunc,
            'migration_hint' => "{$oldFunc}() → {$newFunc}()",
            'ts_query' => "(function_call_expression function: (name) @fn (#eq? @fn \"{$this->escape($oldFunc)}\"))",
            'fix_template' => json_encode([
                'type' => 'function_call_rewrite',
                'old' => $oldFunc,
                'new' => $newFunc,
            ], JSON_UNESCAPED_SLASHES),
            'confidence' => 1.0,
        ];
    }

    private function buildGlobalReplace(int $fromId, int $toId, string $variable, string $replacement): array
    {
        return [
            'from_version_id' => $fromId,
            'to_version_id' => $toId,
            'language' => 'php',
            'change_type' => 'global_replaced',
            'severity' => 'breaking',
            'old_fqn' => "global.\${$variable}",
            'new_fqn' => $replacement,
            'migration_hint' => "global \${$variable} → {$replacement}",
            'ts_query' => "(global_declaration (variable_name) @var (#eq? @var \"\${$variable}\"))",
            'fix_template' => json_encode([
                'type' => 'global_replace',
                'variable' => $variable,
                'replacement' => $replacement,
            ], JSON_UNESCAPED_SLASHES),
            'confidence' => 0.9,
        ];
    }

    private function buildConstantReplace(int $fromId, int $toId, string $oldConst, string $newConst): array
    {
        return [
            'from_version_id' => $fromId,
            'to_version_id' => $toId,
            'language' => 'php',
            'change_type' => 'constant_replaced',
            'severity' => 'breaking',
            'old_fqn' => $oldConst,
            'new_fqn' => $newConst,
            'migration_hint' => "{$oldConst} → {$newConst}",
            'ts_query' => "(name) @const (#eq? @const \"{$this->escape($oldConst)}\")",
            'fix_template' => json_encode([
                'type' => 'constant_replace',
                'old' => $oldConst,
                'new' => $newConst,
            ], JSON_UNESCAPED_SLASHES),
            'confidence' => 0.95,
        ];
    }

    /**
     * Generate a tree-sitter query to find hook implementations.
     *
     * Matches function declarations like `function MODULENAME_hookname(`.
     * Uses regex since the module name is variable.
     */
    private function hookImplementationQuery(string $hookName): string
    {
        $escaped = $this->escape($hookName);
        return "(function_definition name: (name) @fn (#match? @fn \"__{$escaped}$\"))";
    }

    /**
     * Simple YAML parser for the flat DMU config files.
     * Avoids requiring symfony/yaml for these straightforward files.
     *
     * @return array<string, mixed>
     */
    private function parseYaml(string $filePath): array
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return [];
        }

        // Use symfony/yaml if available, otherwise fall back to simple parser
        if (class_exists(\Symfony\Component\Yaml\Yaml::class)) {
            return \Symfony\Component\Yaml\Yaml::parseFile($filePath);
        }

        // Minimal YAML parser for the specific DMU config format
        return $this->parseSimpleYaml($content);
    }

    /**
     * Minimal YAML parser sufficient for DMU's flat key-value configs.
     *
     * Handles: scalar mappings, lists, multi-level nesting, block scalars (|).
     * Does NOT handle: anchors, aliases, flow sequences, complex keys.
     *
     * @return array<string, mixed>
     */
    private function parseSimpleYaml(string $content): array
    {
        $result = [];
        $lines = explode("\n", $content);
        $stack = [&$result];
        $indentStack = [-1];
        $blockScalarKey = null;
        $blockScalarIndent = 0;
        $blockScalarLines = [];

        foreach ($lines as $line) {
            // Skip comments and empty lines (unless in block scalar)
            if ($blockScalarKey !== null) {
                $lineIndent = strlen($line) - strlen(ltrim($line));
                if ($lineIndent > $blockScalarIndent || trim($line) === '') {
                    $blockScalarLines[] = $line;
                    continue;
                }
                // Block scalar ended
                $text = implode("\n", $blockScalarLines);
                // Trim the common leading whitespace
                $current = &$stack[count($stack) - 1];
                $current[$blockScalarKey] = $text;
                $blockScalarKey = null;
                $blockScalarLines = [];
            }

            $trimmed = rtrim($line);
            if ($trimmed === '' || $trimmed[0] === '#') {
                continue;
            }

            $indent = strlen($line) - strlen(ltrim($line));

            // Pop stack back to current indent level
            while (count($indentStack) > 1 && $indent <= end($indentStack)) {
                array_pop($stack);
                array_pop($indentStack);
            }

            $stripped = ltrim($trimmed);

            // List item: "- value"
            if (str_starts_with($stripped, '- ')) {
                $value = trim(substr($stripped, 2));
                $current = &$stack[count($stack) - 1];
                if (!is_array($current) || (count($current) > 0 && !array_is_list($current))) {
                    // Find the last key and make it a list
                }
                $current[] = $this->parseYamlValue($value);
                continue;
            }

            // Key-value: "key: value"
            if (str_contains($stripped, ':')) {
                $colonPos = strpos($stripped, ':');
                $key = substr($stripped, 0, $colonPos);
                $rest = trim(substr($stripped, $colonPos + 1));

                $current = &$stack[count($stack) - 1];

                if ($rest === '') {
                    // Sub-mapping
                    $current[$key] = [];
                    $stack[] = &$current[$key];
                    $indentStack[] = $indent;
                } elseif ($rest === '|') {
                    // Block scalar
                    $blockScalarKey = $key;
                    $blockScalarIndent = $indent;
                    $blockScalarLines = [];
                } else {
                    $current[$key] = $this->parseYamlValue($rest);
                }
            }
        }

        // Handle trailing block scalar
        if ($blockScalarKey !== null) {
            $current = &$stack[count($stack) - 1];
            $current[$blockScalarKey] = implode("\n", $blockScalarLines);
        }

        return $result;
    }

    private function parseYamlValue(string $value): string|int|float|bool
    {
        // Remove quotes
        if ((str_starts_with($value, "'") && str_ends_with($value, "'")) ||
            (str_starts_with($value, '"') && str_ends_with($value, '"'))) {
            return substr($value, 1, -1);
        }

        if ($value === 'true') return true;
        if ($value === 'false') return false;
        if ($value === 'null') return '';
        if (ctype_digit($value)) return (int) $value;
        if (is_numeric($value)) return (float) $value;

        return $value;
    }

    private function escape(string $value): string
    {
        return addcslashes($value, "\\\"");
    }
}
