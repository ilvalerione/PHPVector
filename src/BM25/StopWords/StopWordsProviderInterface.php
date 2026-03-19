<?php

declare(strict_types=1);

namespace PHPVector\BM25\StopWords;

/**
 * Provides a list of stop words to be filtered out during tokenization.
 *
 * Stop words are common words (articles, prepositions, pronouns, etc.) that
 * carry little semantic value and are typically excluded from full-text search
 * to improve relevance and reduce index size.
 *
 * Implement this interface to provide stop words from any source:
 * - Static lists (EnglishStopWords, ItalianStopWords)
 * - Files (FileStopWords)
 * - Databases, APIs, or other dynamic sources
 */
interface StopWordsProviderInterface
{
    /**
     * Return the list of stop words.
     *
     * Words should be lowercase. The tokenizer will lowercase input text
     * before comparing against this list.
     *
     * @return string[]
     */
    public function getStopWords(): array;
}
