<?php

declare(strict_types=1);

namespace DrupalEvolver\Tests\Unit\Indexer\Extractor;

use DrupalEvolver\Indexer\Extractor\AssetUsageExtractor;
use PHPUnit\Framework\TestCase;

class AssetUsageExtractorTest extends TestCase
{
    private AssetUsageExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new AssetUsageExtractor();
    }

    public function testExtractsAttachLibraryFromTwig(): void
    {
        $source = <<<'TWIG'
{# Template file #}
{{ attach_library('mymodule/my-library') }}
<div class="test">Content</div>
{{ attach_library('core/drupal.ajax') }}
TWIG;

        $symbols = $this->extractor->extract('templates/page.html.twig', $source);

        $this->assertCount(2, $symbols);
        $this->assertSame('library_usage', $symbols[0]['symbol_type']);
        $this->assertSame('mymodule/my-library', $symbols[0]['name']);
        $this->assertSame('library_usage:mymodule/my-library', $symbols[0]['fqn']);
        $this->assertSame('twig', $symbols[0]['language']);

        $this->assertSame('core/drupal.ajax', $symbols[1]['name']);
    }

    public function testExtractsAttachedLibraryFromPhp(): void
    {
        $source = <<<'PHP'
<?php
$build['#attached']['library'][] = 'mymodule/my-library';
$build['#attached']['library'][] = 'core/drupal';
PHP;

        $symbols = $this->extractor->extract('src/Controller/MyController.php', $source);

        $this->assertCount(2, $symbols);
        $this->assertSame('library_usage', $symbols[0]['symbol_type']);
        $this->assertSame('mymodule/my-library', $symbols[0]['name']);
        $this->assertSame('php', $symbols[0]['language']);
    }

    public function testExtractsFromRenderArray(): void
    {
        $source = <<<'PHP'
<?php
$build = [
    '#attached' => [
        'library' => [
            'mymodule/some-lib',
        ],
    ],
];
PHP;

        $symbols = $this->extractor->extract('src/Form/MyForm.php', $source);

        $this->assertCount(1, $symbols);
        $this->assertSame('mymodule/some-lib', $symbols[0]['name']);
    }

    public function testIgnoresNonPhpNonTwigFiles(): void
    {
        $symbols = $this->extractor->extract('config/services.yml', 'services: {}');
        $this->assertSame([], $symbols);
    }

    public function testExtractsFromModuleFiles(): void
    {
        $source = <<<'PHP'
<?php
function mymodule_page_attachments(array &$attachments) {
    $attachments['#attached']['library'][] = 'mymodule/global-styles';
}
PHP;

        $symbols = $this->extractor->extract('mymodule.module', $source);

        $this->assertCount(1, $symbols);
        $this->assertSame('mymodule/global-styles', $symbols[0]['name']);
    }

    public function testDeduplicatesSymbols(): void
    {
        // A file that might match both regex patterns for the same library
        $source = <<<'PHP'
<?php
$a['#attached']['library'][] = 'mymodule/lib';
$b['#attached']['library'][] = 'mymodule/lib';
PHP;

        $symbols = $this->extractor->extract('test.php', $source);

        // Should have 2 since they're at different byte offsets
        $this->assertCount(2, $symbols);
    }

    public function testMetadataContainsOwner(): void
    {
        $source = "{{ attach_library('core/drupal.ajax') }}";
        $symbols = $this->extractor->extract('test.html.twig', $source);

        $this->assertCount(1, $symbols);
        $metadata = json_decode($symbols[0]['metadata_json'], true);
        $this->assertSame('core', $metadata['owner']);
        $this->assertSame('twig_attach', $metadata['attachment_type']);
    }

    public function testLineNumbersAreCorrect(): void
    {
        $source = "line1\nline2\n{{ attach_library('mymodule/lib') }}\nline4";
        $symbols = $this->extractor->extract('test.html.twig', $source);

        $this->assertCount(1, $symbols);
        $this->assertSame(3, $symbols[0]['line_start']);
    }
}
