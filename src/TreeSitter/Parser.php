<?php

declare(strict_types=1);

namespace DrupalEvolver\TreeSitter;

use RuntimeException;

class Parser
{
    private ?TreeSitterCLI $cli = null;
    private ?FFIBinding $ffi = null;
    private LanguageRegistry $registry;
    private bool $useCli;
    private int $ownerPid;

    public function __construct(
        ?bool $useCli = null,
        ?TreeSitterCLI $cli = null,
        ?LanguageRegistry $registry = null,
    ) {
        $this->ownerPid = getmypid() ?: 0;
        $this->useCli = $useCli ?? (($_ENV['EVOLVER_USE_CLI'] ?? getenv('EVOLVER_USE_CLI')) === '1');
        $this->cli = $cli ?? new TreeSitterCLI();
        $this->registry = $registry ?? new LanguageRegistry();

        if (!$this->useCli) {
            // Only init FFI if we're not using CLI
            $libPath = $this->resolveCoreLibraryPath($this->registry->grammarPath());
            $this->ffi = FFIBinding::create($libPath);
        }
    }

    #[\NoDiscard]
    public function parse(string $source, string $language): Tree
    {
        if ($this->useCli) {
            return $this->parseWithCli($source, $language);
        }

        $this->assertCurrentProcess();

        return $this->parseWithFFI($source, $language);
    }

    private function parseWithCli(string $source, string $language): Tree
    {
        $data = $this->cli->parse($source, $language);

        if (!isset($data['rootNode'])) {
            throw new RuntimeException("No root node in tree-sitter output");
        }

        return new Tree($data, $source);
    }

    private function parseWithFFI(string $source, string $language): Tree
    {
        $lang = $this->registry->loadLanguage($language);

        // Cast to TSLanguage* of our current FFI instance to avoid interop segfaults
        $lang = $this->ffi->ffi()->cast('const TSLanguage *', $lang);

        $parser = $this->ffi->ts_parser_new();
        if ($parser === null) {
            throw new RuntimeException('Failed to create parser');
        }

        if (!$this->ffi->ts_parser_set_language($parser, $lang)) {
            $this->ffi->ts_parser_delete($parser);
            throw new RuntimeException("Failed to set language: {$language}");
        }

        $tree = $this->ffi->ts_parser_parse_string($parser, null, $source, strlen($source));
        $this->ffi->ts_parser_delete($parser);

        if ($tree === null) {
            throw new RuntimeException('Failed to parse source');
        }

        return new Tree($tree, $source, $this->ffi);
    }

    public function binding(): ?FFIBinding
    {
        if (!$this->useCli) {
            $this->assertCurrentProcess();
        }

        return $this->ffi;
    }

    public function registry(): LanguageRegistry
    {
        if (!$this->useCli) {
            $this->assertCurrentProcess();
        }

        return $this->registry;
    }

    private function assertCurrentProcess(): void
    {
        $currentPid = getmypid() ?: 0;
        if ($currentPid !== $this->ownerPid) {
            throw new RuntimeException(sprintf(
                'Parser instances are process-local. Create a new Parser after forking instead of reusing one from PID %d in PID %d.',
                $this->ownerPid,
                $currentPid,
            ));
        }
    }

    private function resolveCoreLibraryPath(string $grammarPath): string
    {
        $base = rtrim($grammarPath, '/');
        $candidates = [
            "{$base}/libtree-sitter.so",
            "{$base}/libtree-sitter.so.0",
            '/usr/lib/libtree-sitter.so',
            '/usr/lib/libtree-sitter.so.0',
        ];

        $versioned = glob("{$base}/libtree-sitter.so.*") ?: [];
        if ($base !== '/usr/lib') {
            $versioned = array_merge($versioned, glob('/usr/lib/libtree-sitter.so.*') ?: []);
        }
        $candidates = array_merge($candidates, $versioned);

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException(
            'libtree-sitter.so not found. Checked: ' . implode(', ', $candidates)
        );
    }
}
