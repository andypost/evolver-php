<?php

declare(strict_types=1);

namespace DrupalEvolver\Scanner;

use DrupalEvolver\TreeSitter\FFIBinding;
use DrupalEvolver\TreeSitter\LanguageRegistry;
use DrupalEvolver\TreeSitter\Node;
use DrupalEvolver\TreeSitter\Query;
use Generator;

class MatchCollector
{
    /** @var array<string, \FFI\CData> */
    private array $languageCache = [];

    /** @var array<string, Query> */
    private array $queryCache = [];

    /** @var array<string, bool> */
    private array $invalidQueryCache = [];

    /** @var array<string, SignatureHint|null> */
    private array $signatureHintCache = [];

    public function __construct(
        private FFIBinding $binding,
        private LanguageRegistry $registry,
    ) {}

    /**
     * Collect matches for all changes against the given source.
     *
     * @return Generator<int, MatchResult>
     */
    public function collectMatches(Node $root, string $source, string $language, array $changes): Generator
    {
        $lang = $this->loadLanguage($language);
        if ($lang === null) {
            return;
        }

        foreach ($changes as $change) {
            $context = new ScanContext($root, $source, $language, $change);
            
            if (!$this->shouldProcessChange($context)) {
                continue;
            }

            $query = $this->resolveQuery($language, $context->getQuery(), $lang);
            if ($query === null) {
                continue;
            }

            $signatureHint = $this->resolveSignatureHint($change);

            try {
                foreach ($query->matches($root, $source) as $captures) {
                    if (!$this->passesPostProcessing($context, $captures, $signatureHint)) {
                        continue;
                    }

                    $matchNode = $this->selectMatchNode($context, $captures);
                    if (!$matchNode) {
                        continue;
                    }

                    yield MatchResult::fromContext($context, $matchNode);
                }
            } catch (\Throwable) {
                continue;
            }
        }
    }

    /**
     * Check if a change should be processed for the given source.
     */
    private function shouldProcessChange(ScanContext $context): bool
    {
        // Must have a query
        if ($context->getQuery() === null) {
            return false;
        }

        // Fast text filter: if the old symbol name is not in source, skip
        return $context->containsSymbol();
    }

    /**
     * Select the node to use for the match based on change type.
     *
     * @param array<string, Node> $captures
     */
    private function selectMatchNode(ScanContext $context, array $captures): ?Node
    {
        // For signature changes, prefer the arguments node
        if ($context->getChangeType() === 'signature_changed' && isset($captures['args'])) {
            $argsNode = $captures['args'];
            if ($argsNode instanceof Node) {
                return $argsNode;
            }
        }

        // Otherwise use the first capture
        $first = reset($captures);
        return $first instanceof Node ? $first : null;
    }

    /**
     * @param array<string, Node> $captures
     * @param SignatureHint|null $signatureHint
     */
    private function passesPostProcessing(ScanContext $context, array $captures, ?SignatureHint $signatureHint): bool
    {
        // Only signature changes need post-processing
        if ($context->getChangeType() !== 'signature_changed') {
            return true;
        }

        return $this->passesSignatureArgCountHeuristic($captures, $signatureHint);
    }

    /**
     * @param array<string, Node> $captures
     * @param SignatureHint|null $signatureHint
     */
    private function passesSignatureArgCountHeuristic(array $captures, ?SignatureHint $signatureHint): bool
    {
        $argsNode = $captures['args'] ?? null;
        if (!$argsNode instanceof Node) {
            return true;
        }

        if ($signatureHint === null) {
            return true;
        }

        $oldCount = $signatureHint->oldCount;
        $newCount = $signatureHint->newCount;
        $callArgCount = $this->argumentCount($argsNode);

        if ($newCount > $oldCount) {
            return $callArgCount < $newCount;
        }

        if ($newCount < $oldCount) {
            return $callArgCount > $newCount;
        }

        return true;
    }

    private function argumentCount(Node $argsNode): int
    {
        return $argsNode->namedChildCount();
    }

    private function loadLanguage(string $language): ?\FFI\CData
    {
        if (isset($this->languageCache[$language])) {
            return $this->languageCache[$language];
        }

        try {
            $this->languageCache[$language] = $this->registry->loadLanguage($language);
            return $this->languageCache[$language];
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveQuery(string $language, string $tsQuery, \FFI\CData $lang): ?Query
    {
        $cacheKey = $language . "\0" . $tsQuery;

        if (isset($this->invalidQueryCache[$cacheKey])) {
            return null;
        }

        if (isset($this->queryCache[$cacheKey])) {
            return $this->queryCache[$cacheKey];
        }

        try {
            $this->queryCache[$cacheKey] = new Query($this->binding, $tsQuery, $lang);
            return $this->queryCache[$cacheKey];
        } catch (\Throwable) {
            $this->invalidQueryCache[$cacheKey] = true;
            return null;
        }
    }

    /**
     * Resolve the signature hint for a signature_changed context.
     */
    private function resolveSignatureHint(array $change): ?SignatureHint
    {
        if (($change['change_type'] ?? null) !== 'signature_changed') {
            return null;
        }

        $cacheKey = $this->signatureCacheKey($change);
        if (array_key_exists($cacheKey, $this->signatureHintCache)) {
            return $this->signatureHintCache[$cacheKey];
        }

        $decoded = json_decode((string) ($change['diff_json'] ?? ''), true);
        if (!is_array($decoded)) {
            $this->signatureHintCache[$cacheKey] = null;
            return null;
        }

        $old = $decoded['old'] ?? null;
        $new = $decoded['new'] ?? null;
        if (!is_array($old) || !is_array($new)) {
            $this->signatureHintCache[$cacheKey] = null;
            return null;
        }

        $oldParams = $old['params'] ?? null;
        $newParams = $new['params'] ?? null;
        if (!is_array($oldParams) || !is_array($newParams)) {
            $this->signatureHintCache[$cacheKey] = null;
            return null;
        }

        $this->signatureHintCache[$cacheKey] = SignatureHint::create(
            count($oldParams),
            count($newParams)
        );

        return $this->signatureHintCache[$cacheKey];
    }

    /**
     * @param array<string, mixed> $change
     */
    private function signatureCacheKey(array $change): string
    {
        if (isset($change['id'])) {
            return 'id:' . (string) $change['id'];
        }

        return 'hash:' . hash('sha256', (string) ($change['ts_query'] ?? '') . '|' . (string) ($change['diff_json'] ?? ''));
    }
}
