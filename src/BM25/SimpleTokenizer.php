<?php

declare(strict_types=1);

namespace PHPVector\BM25;

use PHPVector\BM25\StopWords\EnglishStopWords;
use PHPVector\BM25\StopWords\StopWordsProviderInterface;

/**
 * A lightweight, language-agnostic tokenizer.
 *
 * Pipeline:
 *  1. Lower-case the input (Unicode-aware via `mb_strtolower`).
 *  2. Split on any run of non-alphanumeric characters.
 *  3. Optionally remove a configurable stop-word list.
 *  4. Drop tokens shorter than `$minTokenLength`.
 */
final class SimpleTokenizer implements TokenizerInterface
{
    /** @var array<string, true> */
    private readonly array $stopWords;

    /**
     * @param StopWordsProviderInterface|string[] $stopWords Stop words provider or array of words.
     * @param int $minTokenLength Minimum token length to keep (default: 2).
     */
    public function __construct(
        StopWordsProviderInterface|array $stopWords = new EnglishStopWords(),
        private readonly int $minTokenLength = 2,
    ) {
        $words = $stopWords instanceof StopWordsProviderInterface
            ? $stopWords->getStopWords()
            : $stopWords;

        $this->stopWords = array_fill_keys(
            array_map('mb_strtolower', $words),
            true,
        );
    }

    /** {@inheritdoc} */
    public function tokenize(string $text): array
    {
        $text   = mb_strtolower($text, 'UTF-8');
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $result = [];
        foreach ($tokens as $token) {
            if (
                mb_strlen($token, 'UTF-8') >= $this->minTokenLength
                && !isset($this->stopWords[$token])
            ) {
                $result[] = $token;
            }
        }
        return $result;
    }
}
