<?php

declare(strict_types=1);

namespace PHPVector\BM25\StopWords;

/**
 * English stop words provider.
 *
 * Includes articles, prepositions, pronouns, conjunctions, auxiliary verbs,
 * and other high-frequency words that carry little semantic value.
 */
final class EnglishStopWords implements StopWordsProviderInterface
{
    public function getStopWords(): array
    {
        return self::WORDS;
    }

    /**
     * Static access for use without instantiation.
     *
     * @return string[]
     */
    public static function words(): array
    {
        return self::WORDS;
    }

    private const WORDS = [
        // Articles
        'a', 'an', 'the',

        // Prepositions
        'of', 'at', 'by', 'for', 'with', 'about', 'against', 'between', 'into', 'through',
        'during', 'before', 'after', 'above', 'below', 'to', 'from', 'up', 'down',
        'in', 'out', 'on', 'off', 'over', 'under', 'upon', 'within', 'without',
        'along', 'among', 'around', 'across', 'behind', 'beyond', 'near', 'toward', 'towards',

        // Personal pronouns (subject)
        'i', 'you', 'he', 'she', 'it', 'we', 'they',

        // Personal pronouns (object)
        'me', 'him', 'her', 'us', 'them',

        // Possessive adjectives and pronouns
        'my', 'your', 'his', 'her', 'its', 'our', 'their',
        'mine', 'yours', 'hers', 'ours', 'theirs',

        // Reflexive pronouns
        'myself', 'yourself', 'yourselves', 'himself', 'herself', 'itself', 'ourselves', 'themselves',

        // Demonstrative pronouns
        'this', 'that', 'these', 'those',

        // Interrogative and relative pronouns
        'what', 'which', 'who', 'whom', 'whose',

        // Indefinite pronouns and determiners
        'all', 'any', 'both', 'each', 'every', 'few', 'many', 'more', 'most',
        'other', 'others', 'some', 'such', 'none', 'several',
        'anybody', 'anyone', 'anything', 'everybody', 'everyone', 'everything',
        'nobody', 'nothing', 'somebody', 'someone', 'something',

        // Coordinating conjunctions
        'and', 'but', 'or', 'nor', 'yet', 'so', 'for',

        // Subordinating conjunctions
        'if', 'because', 'as', 'until', 'while', 'although', 'though', 'unless',
        'since', 'when', 'where', 'whether', 'whereas', 'wherever', 'whenever',

        // Verb "to be" (all forms)
        'am', 'is', 'are', 'was', 'were', 'be', 'been', 'being',

        // Verb "to have" (all forms)
        'have', 'has', 'had', 'having',

        // Verb "to do" (all forms)
        'do', 'does', 'did', 'doing', 'done',

        // Modal verbs
        'can', 'could', 'will', 'would', 'shall', 'should', 'may', 'might', 'must',

        // Common auxiliary forms
        'get', 'gets', 'got', 'getting',
        'let', 'lets',

        // Adverbs of place
        'here', 'there', 'where', 'anywhere', 'everywhere', 'somewhere', 'nowhere',

        // Adverbs of time
        'now', 'then', 'when', 'always', 'never', 'often', 'sometimes', 'usually',
        'already', 'still', 'yet', 'again', 'once', 'ever',
        'before', 'after', 'soon', 'later', 'recently', 'today', 'yesterday', 'tomorrow',

        // Adverbs of degree
        'very', 'too', 'quite', 'rather', 'almost', 'enough', 'just', 'only',
        'even', 'also', 'well', 'much', 'more', 'most', 'less', 'least',

        // Other common adverbs
        'how', 'why', 'further', 'back',

        // Negation
        'no', 'not',

        // Other function words
        'own', 'same', 'than', 'like', 'per', 'via',

        // Contractions (tokenizer splits on apostrophes, leaving these fragments)
        's', 't', 'd', 'm', 've', 'll', 're',
        'don', 'doesn', 'didn', 'won', 'wouldn', 'shouldn', 'couldn', 'can',
        'hasn', 'haven', 'hadn', 'isn', 'aren', 'wasn', 'weren', 'ain',
    ];
}
