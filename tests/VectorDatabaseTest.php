<?php

declare(strict_types=1);

namespace PHPVector\Tests;

use PHPUnit\Framework\TestCase;
use PHPVector\BM25\Config as BM25Config;
use PHPVector\BM25\SimpleTokenizer;
use PHPVector\Document;
use PHPVector\HNSW\Config as HNSWConfig;
use PHPVector\HybridMode;
use PHPVector\SearchResult;
use PHPVector\VectorDatabase;

final class VectorDatabaseTest extends TestCase
{
    private function makeDb(): VectorDatabase
    {
        return new VectorDatabase(
            hnswConfig: new HNSWConfig(M: 8, efConstruction: 50, efSearch: 20),
            bm25Config: new BM25Config(),
            tokenizer:  new SimpleTokenizer([]),
        );
    }

    // ------------------------------------------------------------------
    // Sanity / basic API
    // ------------------------------------------------------------------

    public function testCountIsAccurate(): void
    {
        $db = $this->makeDb();
        self::assertSame(0, $db->count());

        $db->addDocument(new Document(id: 1, vector: [0.1, 0.2], text: 'hello'));
        self::assertSame(1, $db->count());

        $db->addDocuments([
            new Document(id: 2, vector: [0.3, 0.4], text: 'world'),
            new Document(id: 3, vector: [0.5, 0.6], text: 'foo bar'),
        ]);
        self::assertSame(3, $db->count());
    }

    public function testDuplicateIdThrows(): void
    {
        $db = $this->makeDb();
        $db->addDocument(new Document(id: 'abc', vector: [1.0, 0.0]));

        $this->expectException(\RuntimeException::class);
        $db->addDocument(new Document(id: 'abc', vector: [0.0, 1.0]));
    }

    // ------------------------------------------------------------------
    // Vector search
    // ------------------------------------------------------------------

    public function testVectorSearchReturnsCorrectDocument(): void
    {
        $db = $this->makeDb();

        $db->addDocument(new Document(id: 1, vector: [1.0, 0.0], text: 'red'));
        $db->addDocument(new Document(id: 2, vector: [0.0, 1.0], text: 'blue'));

        $results = $db->vectorSearch([0.99, 0.01], 1);

        self::assertCount(1, $results);
        self::assertSame(1, $results[0]->document->id);
    }

    public function testVectorSearchResultsAreSearchResult(): void
    {
        $db = $this->makeDb();
        $db->addDocument(new Document(id: 'x', vector: [1.0, 0.0]));

        $results = $db->vectorSearch([1.0, 0.0], 1);

        self::assertInstanceOf(SearchResult::class, $results[0]);
        self::assertGreaterThan(0.0, $results[0]->score);
    }

    // ------------------------------------------------------------------
    // Text search
    // ------------------------------------------------------------------

    public function testTextSearchFindsRelevantDoc(): void
    {
        $db = $this->makeDb();

        $db->addDocument(new Document(id: 1, vector: [1.0, 0.0], text: 'php vector database hnsw'));
        $db->addDocument(new Document(id: 2, vector: [0.0, 1.0], text: 'baking bread sourdough'));

        $results = $db->textSearch('hnsw vector php', 5);

        self::assertNotEmpty($results);
        self::assertSame(1, $results[0]->document->id);
    }

    public function testTextSearchEmptyQueryReturnsNothing(): void
    {
        $db = $this->makeDb();
        $db->addDocument(new Document(id: 1, vector: [1.0], text: 'hello world'));

        self::assertSame([], $db->textSearch('', 5));
    }

    // ------------------------------------------------------------------
    // Hybrid RRF search
    // ------------------------------------------------------------------

    public function testHybridRRFReturnsSomeResults(): void
    {
        $db = $this->makeDb();

        $db->addDocument(new Document(id: 1, vector: [1.0, 0.0], text: 'apple orange'));
        $db->addDocument(new Document(id: 2, vector: [0.0, 1.0], text: 'banana mango'));
        $db->addDocument(new Document(id: 3, vector: [0.5, 0.5], text: 'apple mango hybrid'));

        $results = $db->hybridSearch(
            vector: [1.0, 0.0],
            text: 'apple',
            k: 3,
            mode: HybridMode::RRF,
        );

        self::assertNotEmpty($results);
    }

    public function testHybridRRFScoresAreDescending(): void
    {
        $db = $this->makeDb();

        for ($i = 0; $i < 10; $i++) {
            $db->addDocument(new Document(
                id: $i,
                vector: [(float) $i / 10.0, 1.0 - (float) $i / 10.0],
                text: "document number $i with keyword",
            ));
        }

        $results = $db->hybridSearch(
            vector: [0.5, 0.5],
            text: 'keyword',
            k: 5,
            mode: HybridMode::RRF,
        );

        for ($i = 1; $i < count($results); $i++) {
            self::assertGreaterThanOrEqual($results[$i]->score, $results[$i - 1]->score);
        }
    }

    // ------------------------------------------------------------------
    // Hybrid Weighted search
    // ------------------------------------------------------------------

    public function testHybridWeightedReturnsSomeResults(): void
    {
        $db = $this->makeDb();

        $db->addDocument(new Document(id: 1, vector: [1.0, 0.0], text: 'php vector search'));
        $db->addDocument(new Document(id: 2, vector: [0.0, 1.0], text: 'sql relational database'));

        $results = $db->hybridSearch(
            vector: [1.0, 0.0],
            text: 'php',
            k: 2,
            mode: HybridMode::Weighted,
            vectorWeight: 0.6,
            textWeight: 0.4,
        );

        self::assertNotEmpty($results);
    }

    public function testHybridWeightedScoresAreDescending(): void
    {
        $db = $this->makeDb();

        for ($i = 0; $i < 8; $i++) {
            $db->addDocument(new Document(
                id: $i,
                vector: [(float) $i * 0.1, 0.0],
                text: "item $i with common tag",
            ));
        }

        $results = $db->hybridSearch(
            vector: [0.4, 0.0],
            text: 'common tag',
            k: 5,
            mode: HybridMode::Weighted,
        );

        for ($i = 1; $i < count($results); $i++) {
            self::assertGreaterThanOrEqual($results[$i]->score, $results[$i - 1]->score);
        }
    }

    // ------------------------------------------------------------------
    // Edge cases
    // ------------------------------------------------------------------

    public function testSearchOnEmptyDbReturnsEmpty(): void
    {
        $db = $this->makeDb();

        self::assertSame([], $db->vectorSearch([1.0, 0.0], 5));
        self::assertSame([], $db->textSearch('hello', 5));
        self::assertSame([], $db->hybridSearch([1.0, 0.0], 'hello', 5));
    }

    public function testKExceedingIndexSizeIsHandled(): void
    {
        $db = $this->makeDb();
        $db->addDocument(new Document(id: 1, vector: [1.0, 0.0], text: 'one'));
        $db->addDocument(new Document(id: 2, vector: [0.0, 1.0], text: 'two'));

        $results = $db->vectorSearch([0.5, 0.5], 100);
        self::assertCount(2, $results);
    }

    public function testMetadataIsPreservedInResults(): void
    {
        $db  = $this->makeDb();
        $doc = new Document(
            id: 'meta-doc',
            vector: [1.0, 0.0],
            text: 'metadata test',
            metadata: ['color' => 'red', 'year' => 2024],
        );
        $db->addDocument($doc);

        $results = $db->vectorSearch([1.0, 0.0], 1);

        self::assertSame(['color' => 'red', 'year' => 2024], $results[0]->document->metadata);
    }
}
