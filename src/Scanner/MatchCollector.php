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

    /** @var array<string, array{old_count:int, new_count:int}|null> */
    private array $signatureHintCache = [];

    public function __construct(
        private FFIBinding $binding,
        private LanguageRegistry $registry,
    ) {}

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function collectMatches(Node $root, string $source, string $language, array $changes): Generator
    {
        $lang = $this->loadLanguage($language);
        if ($lang === null) {
            return;
        }

        foreach ($changes as $change) {
            $tsQuery = (string) ($change['ts_query'] ?? '');
            if ($tsQuery === '') {
                continue;
            }

            // Fast text filter: if the old symbol name is not in source, skip query
            $oldFqn = (string) ($change['old_fqn'] ?? '');
            if ($oldFqn !== '') {
                // Get the short name from FQN
                $parts = preg_split('/[\\\\:]/', $oldFqn);
                $shortName = end($parts);
                if ($shortName !== '' && !str_contains($source, $shortName)) {
                    continue;
                }
            }

            $query = $this->resolveQuery($language, $tsQuery, $lang);
            if ($query === null) {
                continue;
            }

            $signatureHint = $this->resolveSignatureHint($change);

            try {
                foreach ($query->matches($root, $source) as $captures) {
                    if (!$this->passesPostProcessing($change, $captures, $signatureHint)) {
                        continue;
                    }

                    $matchNode = $this->selectMatchNode($change, $captures);
                    if (!$matchNode) {
                        continue;
                    }

                    yield [
                        'change_id' => $change['id'],
                        'line_start' => $matchNode->startPoint()['row'] + 1,
                        'line_end' => $matchNode->endPoint()['row'] + 1,
                        'byte_start' => $matchNode->startByte(),
                        'byte_end' => $matchNode->endByte(),
                        'matched_source' => $matchNode->text(),
                        'fix_method' => !empty($change['fix_template']) ? 'template' : 'manual',
                        'suggested_fix' => null,
                        'status' => 'pending',
                    ];
                }
            } catch (\Throwable) {
                continue;
            }
        }
    }

    /**
     * @param array<string, mixed> $change
     * @param array<string, Node> $captures
     */
    private function selectMatchNode(array $change, array $captures): ?Node
    {
        $changeType = (string) ($change['change_type'] ?? '');
        if ($changeType === 'signature_changed' && isset($captures['args']) && $captures['args'] instanceof Node) {
            return $captures['args'];
        }

        $first = reset($captures);
        return $first instanceof Node ? $first : null;
    }

    /**
     * @param array<string, mixed> $change
     * @param array<string, Node> $captures
     * @param array{old_count:int, new_count:int}|null $signatureHint
     */
    private function passesPostProcessing(array $change, array $captures, ?array $signatureHint): bool
    {
        $changeType = (string) ($change['change_type'] ?? '');
        if ($changeType !== 'signature_changed') {
            return true;
        }

        return $this->passesSignatureArgCountHeuristic($captures, $signatureHint);
    }

    /**
     * @param array<string, Node> $captures
     * @param array{old_count:int, new_count:int}|null $signatureHint
     */
    private function passesSignatureArgCountHeuristic(array $captures, ?array $signatureHint): bool
    {
        $argsNode = $captures['args'] ?? null;
        if (!$argsNode instanceof Node) {
            return true;
        }

        if ($signatureHint === null) {
            return true;
        }

        $oldCount = $signatureHint['old_count'];
        $newCount = $signatureHint['new_count'];
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
     * @param array<string, mixed> $change
     * @return array{old_count:int, new_count:int}|null
     */
    private function resolveSignatureHint(array $change): ?array
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

        $this->signatureHintCache[$cacheKey] = [
            'old_count' => count($oldParams),
            'new_count' => count($newParams),
        ];

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
