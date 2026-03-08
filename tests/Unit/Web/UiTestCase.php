<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Web;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

abstract class UiTestCase extends TestCase
{
    protected Environment $twig;

    protected function setUp(): void
    {
        $templateDir = dirname(__DIR__, 3) . '/templates';
        $loader = new FilesystemLoader($templateDir);
        $this->twig = new Environment($loader, ['strict_variables' => false]);
        $this->twig->addFilter(new TwigFilter('json_decode', static function (string $json): array {
            return json_decode($json, true) ?: [];
        }));
    }

    protected function renderTemplate(string $template, array $context = []): string
    {
        return $this->twig->render($template, $context);
    }

    protected function renderRunDetail(array $extraMatches = []): string
    {
        $baseMatch = $this->makeMatch([
            'id' => 1,
            'change_type' => 'function_removed',
            'severity' => 'breaking',
            'old_fqn' => 'drupal_render',
            'migration_hint' => 'Use renderer service.',
        ]);

        $matches = [$baseMatch, ...$extraMatches];

        return $this->renderTemplate('run-detail.twig', $this->makeRunDetailContext($matches));
    }

    protected function makeRunDetailContext(array $matches, array $overrides = []): array
    {
        $count = count($matches);

        return array_replace([
            'project' => ['id' => 1, 'name' => 'test-project'],
            'run' => $this->makeRun(),
            'summary' => $this->makeSummary(['total' => $count]),
            'matches' => $matches,
            'by_extension' => $matches === [] ? [] : [
                'modules/mymodule' => [
                    'count' => $count,
                    'by_severity' => ['breaking' => $count],
                    'matches' => $matches,
                ],
            ],
            'by_category' => $matches === [] ? [] : [
                'Removals' => [
                    'count' => $count,
                    'matches' => $matches,
                ],
            ],
            'logs' => [],
        ], $overrides);
    }

    protected function renderExtensionDetail(?array $matches = null): string
    {
        $matches ??= [$this->makeMatch([
            'id' => 1,
            'change_type' => 'function_removed',
            'severity' => 'breaking',
            'old_fqn' => 'drupal_render',
        ])];

        return $this->renderTemplate('extension-detail.twig', $this->makeExtensionDetailContext($matches));
    }

    protected function makeExtensionDetailContext(array $matches, array $overrides = []): array
    {
        return array_replace([
            'run' => ['id' => 1],
            'project' => ['id' => 1, 'name' => 'test'],
            'extension_path' => 'modules/mymodule',
            'matches' => $matches,
            'summary' => $this->makeSummary(['total' => count($matches)]),
        ], $overrides);
    }

    protected function renderMatchItem(array $overrides = []): string
    {
        return $this->renderTemplate('fragments/match-item.twig', [
            'match' => $this->makeMatch($overrides),
        ]);
    }

    protected function makeMatch(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'file_path' => 'src/Controller/MyController.php',
            'line_start' => 42,
            'line_end' => 42,
            'change_type' => 'function_removed',
            'severity' => 'breaking',
            'old_fqn' => 'some_function',
            'migration_hint' => null,
            'fix_method' => 'manual',
            'diff_json' => null,
            'matched_source' => 'some_function()',
        ], $overrides);
    }

    protected function makeRun(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'branch_name' => 'main',
            'from_core_version' => '10.2.0',
            'target_core_version' => '11.0.0',
            'status' => 'completed',
            'commit_sha' => 'abc1234',
            'scanned_file_count' => 50,
            'file_count' => 50,
            'match_count' => 3,
            'job_id' => null,
        ], $overrides);
    }

    protected function makeSummary(array $overrides = []): array
    {
        return array_merge([
            'total' => 1,
            'auto_fixable' => 0,
            'by_severity' => ['breaking' => 1],
            'by_category' => ['Removals' => 1],
        ], $overrides);
    }

    protected function assertHtmlNotEmpty(string $html): void
    {
        $this->assertNotSame('', trim($html), 'Rendered HTML should not be empty');
    }

    protected function assertBalancedTag(string $html, string $tag): void
    {
        $openTags = substr_count($html, '<' . $tag);
        $closeTags = substr_count($html, '</' . $tag . '>');

        $this->assertSame($openTags, $closeTags, "Unbalanced <{$tag}> tags: {$openTags} open vs {$closeTags} close");
    }
}
