<?php

declare(strict_types=1);

namespace PHPVector;

use PHPVector\BM25\Config as BM25Config;
use PHPVector\BM25\Index as BM25Index;
use PHPVector\BM25\TokenizerInterface;
use PHPVector\BM25\SimpleTokenizer;
use PHPVector\HNSW\Config as HNSWConfig;
use PHPVector\HNSW\Index as HNSWIndex;
use PHPVector\Persistence\DocumentStore;
use PHPVector\Persistence\IndexSerializer;

/**
 * High-level façade combining HNSW vector search with BM25 full-text search.
 *
 * Supports three retrieval modes:
 *  1. **Vector search** — approximate nearest-neighbour via HNSW.
 *  2. **Text search**   — BM25 ranked full-text search.
 *  3. **Hybrid search** — fuse both result sets with either
 *                         Reciprocal Rank Fusion (RRF) or a weighted linear combination.
 *
 * ### Persistence (folder-based)
 *
 * Pass a `$path` to the constructor to enable on-disk persistence.
 * The folder layout created by `save()` is:
 *
 * ```
 * {path}/
 *   meta.json      — distance code, dimension, nextId, docIdToNodeId
 *   hnsw.bin       — HNSW graph (nodes: vectors + connections)
 *   bm25.bin       — BM25 inverted index
 *   docs/
 *     0.bin        — one file per document (id, text, metadata)
 *     1.bin
 *     …
 * ```
 *
 * Document files are **lazy-loaded**: only the HNSW graph and BM25 index are
 * loaded into memory by `open()`; individual `docs/{n}.bin` files are read on
 * demand when search results are hydrated.
 *
 * Individual document files are written **asynchronously** (via `pcntl_fork`)
 * on each `addDocument()` call when the extension is available.  `save()`
 * waits for all pending writes before flushing the index files.
 *
 * Quick start
 * -----------
 * ```php
 * // In-memory (no persistence):
 * $db = new VectorDatabase();
 * $db->addDocument(new Document(id: 1, vector: [0.1, 0.9, ...], text: 'PHP vector database'));
 *
 * // With persistence:
 * $db = new VectorDatabase(path: '/var/data/mydb');
 * $db->addDocument(new Document(vector: [0.1, 0.9, ...])); // id auto-generated as UUID
 * $db->save();
 *
 * // Load later:
 * $db = VectorDatabase::open('/var/data/mydb');
 * $results = $db->hybridSearch(vector: $queryVec, text: 'vector search php', k: 5);
 * ```
 */
final class VectorDatabase
{
    private readonly HNSWIndex $hnswIndex;
    private readonly BM25Index $bm25Index;
    private readonly HNSWConfig $hnswConfig;

    /**
     * Internal sequence counter.
     * Both HNSWIndex and BM25Index use this integer as node-ID.
     */
    private int $nextId = 0;

    /**
     * Lazy document cache: nodeId → fully-loaded Document.
     *
     * Documents added in the current session are always in this map.
     * After `open()`, this starts empty and is populated on demand.
     *
     * @var array<int, Document>
     */
    private array $nodeIdToDoc = [];

    /** @var array<string|int, int> user document ID → nodeId */
    private array $docIdToNodeId = [];

    /**
     * DocumentStore instance, created lazily when $path is set.
     */
    private ?DocumentStore $documentStore = null;

    public function __construct(
        HNSWConfig $hnswConfig = new HNSWConfig(),
        BM25Config $bm25Config = new BM25Config(),
        TokenizerInterface $tokenizer = new SimpleTokenizer(),
        private readonly ?string $path = null,
    ) {
        $this->hnswConfig = $hnswConfig;
        $this->hnswIndex  = new HNSWIndex($hnswConfig);
        $this->bm25Index  = new BM25Index($bm25Config, $tokenizer);
    }

    // ------------------------------------------------------------------
    // Indexing
    // ------------------------------------------------------------------

    /**
     * Add a single document.
     *
     * If `$document->id` is null a random UUID v4 is assigned automatically.
     * When a folder path is configured the document is written to disk
     * asynchronously (pcntl_fork when available, synchronous otherwise).
     *
     * @throws \RuntimeException if a document with the same ID already exists.
     */
    public function addDocument(Document $document): void
    {
        // Assign UUID if no id supplied.
        if ($document->id === null) {
            $document = new Document(
                id:       $this->generateUuid(),
                vector:   $document->vector,
                text:     $document->text,
                metadata: $document->metadata,
            );
        }

        if (isset($this->docIdToNodeId[$document->id])) {
            throw new \RuntimeException(
                sprintf('Document with id "%s" already exists.', $document->id)
            );
        }

        $nodeId = $this->nextId++;
        $this->nodeIdToDoc[$nodeId]         = $document;
        $this->docIdToNodeId[$document->id] = $nodeId;

        $this->hnswIndex->insert($document);
        $this->bm25Index->addDocument($nodeId, $document);

        // Persist doc file asynchronously when a path is configured.
        if ($this->path !== null) {
            $this->ensureDocsDir();
            $this->getDocumentStore()->write(
                nodeId:   $nodeId,
                docId:    $document->id,
                text:     $document->text,
                metadata: $document->metadata,
                async:    true,
            );
        }
    }

    /**
     * Add multiple documents in one call.
     *
     * @param Document[] $documents
     */
    public function addDocuments(array $documents): void
    {
        foreach ($documents as $doc) {
            $this->addDocument($doc);
        }
    }

    // ------------------------------------------------------------------
    // Search
    // ------------------------------------------------------------------

    /**
     * Pure vector search via HNSW.
     *
     * @param float[]  $vector  Query embedding.
     * @param int      $k       Number of results.
     * @param int|null $ef      Candidate list size (≥ k; null = use index default).
     *
     * @return SearchResult[]
     */
    public function vectorSearch(array $vector, int $k = 10, ?int $ef = null): array
    {
        $raw = $this->hnswIndex->search($vector, $k, $ef);

        if ($this->path === null) {
            // Pure in-memory: documents are already fully populated in HNSW.
            return $raw;
        }

        // After open(), HNSW holds stub Documents (id + vector only).
        // Hydrate each result with the full Document (text + metadata from disk).
        return array_map(function (SearchResult $sr): SearchResult {
            $nodeId = $this->docIdToNodeId[$sr->document->id];
            return new SearchResult(
                document: $this->loadDocument($nodeId),
                score:    $sr->score,
                rank:     $sr->rank,
            );
        }, $raw);
    }

    /**
     * Pure BM25 full-text search.
     *
     * @return SearchResult[]
     */
    public function textSearch(string $query, int $k = 10): array
    {
        if ($this->path === null) {
            // Pure in-memory: delegate directly, BM25 holds full Documents.
            return $this->bm25Index->search($query, $k);
        }

        // After open(), use scoreAll() so we can lazy-load documents ourselves.
        $scores = $this->bm25Index->scoreAll($query);
        if (empty($scores)) {
            return [];
        }

        $topK = array_slice($scores, 0, $k, true);
        return $this->buildSearchResults($topK);
    }

    /**
     * Hybrid search: fuse vector similarity and BM25 results.
     *
     * @param float[]    $vector        Query embedding (used for HNSW leg).
     * @param string     $text          Query text (used for BM25 leg).
     * @param int        $k             Final number of results.
     * @param int        $fetchK        Number of candidates fetched from each leg before fusion.
     *                                  Higher values improve recall at the cost of latency.
     *                                  Defaults to max(k * 3, 50).
     * @param HybridMode $mode          Fusion strategy.
     * @param float      $vectorWeight  Weight for vector scores (Weighted mode only).
     * @param float      $textWeight    Weight for BM25 scores (Weighted mode only).
     * @param int        $rrfK          RRF constant k (RRF mode only). Typical value: 60.
     *
     * @return SearchResult[]
     */
    public function hybridSearch(
        array $vector,
        string $text,
        int $k = 10,
        ?int $fetchK = null,
        HybridMode $mode = HybridMode::RRF,
        float $vectorWeight = 0.5,
        float $textWeight = 0.5,
        int $rrfK = 60,
    ): array {
        $fetchK ??= max($k * 3, 50);

        $vectorResults = $this->hnswIndex->search($vector, $fetchK);
        $textScores    = $this->bm25Index->scoreAll($text);

        return match ($mode) {
            HybridMode::RRF      => $this->fuseRRF($vectorResults, $textScores, $k, $rrfK),
            HybridMode::Weighted => $this->fuseWeighted($vectorResults, $textScores, $k, $vectorWeight, $textWeight),
        };
    }

    // ------------------------------------------------------------------
    // Persistence
    // ------------------------------------------------------------------

    /**
     * Persist the database to its configured folder.
     *
     * Writes (in order):
     *  1. Waits for all outstanding async document writes.
     *  2. `meta.json`   — distance code, dimension, nextId, docIdToNodeId.
     *  3. `hnsw.bin`    — HNSW graph (vectors + connections).
     *  4. `bm25.bin`    — BM25 inverted index.
     *
     * Individual `docs/{n}.bin` files are written incrementally by `addDocument()`
     * and are NOT re-written by this method.
     *
     * @throws \RuntimeException if no path was configured or on I/O failure.
     */
    public function save(): void
    {
        if ($this->path === null) {
            throw new \RuntimeException(
                'Cannot save: no path configured. Pass $path to the constructor or use open().'
            );
        }

        // Ensure directory structure exists.
        if (!is_dir($this->path)) {
            mkdir($this->path, 0755, true);
        }
        $this->ensureDocsDir();

        // Wait for all async document writes before flushing index files.
        if ($this->documentStore !== null) {
            $this->documentStore->waitAll();
        }

        $hnswState = $this->hnswIndex->exportState();

        // meta.json
        $meta = [
            'distance'      => self::encodeDistance($this->hnswConfig->distance),
            'dimension'     => $hnswState['dimension'] ?? 0,
            'nextId'        => $this->nextId,
            'docIdToNodeId' => $this->docIdToNodeId,
            'entryPoint'    => $hnswState['entryPoint'],
            'maxLayer'      => $hnswState['maxLayer'],
        ];
        if (file_put_contents($this->path . '/meta.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false) {
            throw new \RuntimeException("Failed to write meta.json in: {$this->path}");
        }

        $serializer = new IndexSerializer();
        $serializer->writeHnsw($this->path . '/hnsw.bin', $hnswState);
        $serializer->writeBm25($this->path . '/bm25.bin', $this->bm25Index->exportState());
    }

    /**
     * Load a VectorDatabase from a previously saved folder.
     *
     * Only `meta.json`, `hnsw.bin`, and `bm25.bin` are loaded into memory.
     * Individual document files in `docs/` are read lazily when search results
     * are returned.
     *
     * The supplied `$hnswConfig` must use the same distance metric as when the
     * folder was written; a `RuntimeException` is thrown on mismatch.
     *
     * @throws \RuntimeException on I/O failure or distance metric mismatch.
     */
    public static function open(
        string $path,
        HNSWConfig $hnswConfig = new HNSWConfig(),
        BM25Config $bm25Config = new BM25Config(),
        TokenizerInterface $tokenizer = new SimpleTokenizer(),
    ): self {
        $metaPath = $path . '/meta.json';
        if (!file_exists($metaPath)) {
            throw new \RuntimeException("Not a PHPVector folder (meta.json missing): {$path}");
        }

        $meta = json_decode(file_get_contents($metaPath), true, 512, JSON_THROW_ON_ERROR);

        // Validate distance metric.
        $distCode = self::encodeDistance($hnswConfig->distance);
        if ($distCode !== (int) $meta['distance']) {
            throw new \RuntimeException(sprintf(
                'Distance mismatch: config uses %s (code %d) but folder was built with code %d.',
                $hnswConfig->distance->name,
                $distCode,
                (int) $meta['distance'],
            ));
        }

        $db = new self($hnswConfig, $bm25Config, $tokenizer, $path);
        $db->nextId        = (int) $meta['nextId'];
        $db->docIdToNodeId = $meta['docIdToNodeId'];

        // Build a nodeId → typed-docId map from the JSON-decoded docIdToNodeId.
        // JSON always produces string keys; restore integer type where appropriate.
        $nodeIdToDocId = [];
        foreach ($meta['docIdToNodeId'] as $rawDocId => $nodeId) {
            $typedDocId = is_numeric($rawDocId) && (string)(int)$rawDocId === (string)$rawDocId
                ? (int) $rawDocId
                : (string) $rawDocId;
            $nodeIdToDocId[(int) $nodeId] = $typedDocId;
        }

        $serializer = new IndexSerializer();

        // ── Restore HNSW graph ────────────────────────────────────────────
        $hnswData = $serializer->readHnsw($path . '/hnsw.bin');

        // Build stub Documents for HNSW (id + vector only; no text/metadata).
        // HNSW needs these in $documents[] to return SearchResult objects.
        $hnswState              = $hnswData;
        $hnswState['documents'] = [];
        foreach ($hnswData['nodes'] as $nodeId => $nodeData) {
            $docId = $nodeIdToDocId[$nodeId] ?? $nodeId;
            $hnswState['documents'][$nodeId] = [
                'id'       => $docId,
                'text'     => null,
                'metadata' => [],
            ];
        }
        $db->hnswIndex->importState($hnswState);

        // ── Restore BM25 index ────────────────────────────────────────────
        $bm25Data = $serializer->readBm25($path . '/bm25.bin');

        // Build BM25 stub Documents (id only) — needed for scoreAll()/search()
        // guards that check empty($this->documents).
        $bm25Stubs = [];
        foreach ($bm25Data['docLengths'] as $nodeId => $_len) {
            $docId              = $nodeIdToDocId[$nodeId] ?? $nodeId;
            $bm25Stubs[$nodeId] = new Document(id: $docId);
        }
        $db->bm25Index->importState($bm25Data, $bm25Stubs);

        // $db->nodeIdToDoc intentionally starts EMPTY — documents are lazy-loaded.

        return $db;
    }

    // ------------------------------------------------------------------
    // Utilities
    // ------------------------------------------------------------------

    /** Total number of documents stored. */
    public function count(): int
    {
        return $this->nextId;
    }

    // ------------------------------------------------------------------
    // Fusion strategies
    // ------------------------------------------------------------------

    /**
     * Reciprocal Rank Fusion.
     *
     *   RRF(d) = Σᵣ  1 / (k + rankᵣ(d))
     *
     * @param SearchResult[]       $vectorResults
     * @param array<int, float>    $textScores     nodeId → BM25 score (pre-sorted desc)
     * @param int                  $k
     * @param int                  $rrfK
     *
     * @return SearchResult[]
     */
    private function fuseRRF(
        array $vectorResults,
        array $textScores,
        int $k,
        int $rrfK,
    ): array {
        $fused = [];

        // Vector ranks (1-based).
        foreach ($vectorResults as $sr) {
            $nodeId = $this->docIdToNodeId[$sr->document->id];
            $fused[$nodeId] = ($fused[$nodeId] ?? 0.0) + 1.0 / ($rrfK + $sr->rank);
        }

        // BM25 ranks (1-based; $textScores is already sorted descending by scoreAll()).
        $bm25Rank = 1;
        foreach ($textScores as $nodeId => $score) {
            $fused[$nodeId] = ($fused[$nodeId] ?? 0.0) + 1.0 / ($rrfK + $bm25Rank);
            $bm25Rank++;
        }

        arsort($fused);

        return $this->buildSearchResults(array_slice($fused, 0, $k, true));
    }

    /**
     * Weighted linear combination of min-max normalised scores.
     *
     *   combined(d) = α · vecNorm(d) + β · bm25Norm(d)
     *
     * @param SearchResult[]    $vectorResults
     * @param array<int, float> $textScores
     *
     * @return SearchResult[]
     */
    private function fuseWeighted(
        array $vectorResults,
        array $textScores,
        int $k,
        float $vectorWeight,
        float $textWeight,
    ): array {
        // Normalise vector scores to [0, 1].
        $vecNorm = $this->minMaxNormalise(
            array_combine(
                array_map(fn($sr) => $this->docIdToNodeId[$sr->document->id], $vectorResults),
                array_column($vectorResults, 'score'),
            )
        );

        // Normalise BM25 scores to [0, 1].
        $bm25Norm = $this->minMaxNormalise($textScores);

        // Collect all candidate node IDs from both legs.
        $allIds = array_unique(array_merge(array_keys($vecNorm), array_keys($bm25Norm)));

        $fused = [];
        foreach ($allIds as $nodeId) {
            $fused[$nodeId] =
                $vectorWeight * ($vecNorm[$nodeId] ?? 0.0) +
                $textWeight   * ($bm25Norm[$nodeId] ?? 0.0);
        }

        arsort($fused);

        return $this->buildSearchResults(array_slice($fused, 0, $k, true));
    }

    /**
     * Min-max normalise a nodeId → score map to [0, 1].
     *
     * @param array<int, float> $scores
     * @return array<int, float>
     */
    private function minMaxNormalise(array $scores): array
    {
        if (empty($scores)) {
            return [];
        }

        $min = min($scores);
        $max = max($scores);

        if ($max === $min) {
            return array_fill_keys(array_keys($scores), 1.0);
        }

        $range = $max - $min;
        $out   = [];
        foreach ($scores as $nodeId => $score) {
            $out[$nodeId] = ($score - $min) / $range;
        }
        return $out;
    }

    /**
     * Convert a nodeId → fusedScore map into ranked SearchResult objects,
     * hydrating each Document lazily from disk when needed.
     *
     * @param array<int, float> $fused  Already sorted descending, sliced to k.
     * @return SearchResult[]
     */
    private function buildSearchResults(array $fused): array
    {
        $results = [];
        $rank    = 1;
        foreach ($fused as $nodeId => $score) {
            $results[] = new SearchResult(
                document: $this->loadDocument((int) $nodeId),
                score:    $score,
                rank:     $rank++,
            );
        }
        return $results;
    }

    // ------------------------------------------------------------------
    // Lazy document loading
    // ------------------------------------------------------------------

    /**
     * Return the fully-loaded Document for $nodeId.
     *
     * Returns from the in-memory cache when available.
     * Otherwise reads `docs/{nodeId}.bin`, combines with the vector from HNSW,
     * and caches the result.
     */
    private function loadDocument(int $nodeId): Document
    {
        if (isset($this->nodeIdToDoc[$nodeId])) {
            return $this->nodeIdToDoc[$nodeId];
        }

        // Load text + metadata from the doc file.
        [$docId, $text, $metadata] = $this->getDocumentStore()->read($nodeId);

        // Combine with the vector stored in the HNSW graph.
        $vector = $this->hnswIndex->getVector($nodeId);

        $doc = new Document(
            id:       $docId,
            vector:   $vector,
            text:     $text,
            metadata: $metadata,
        );
        $this->nodeIdToDoc[$nodeId] = $doc;
        return $doc;
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    /** Lazy accessor for the DocumentStore (only used when $path is set). */
    private function getDocumentStore(): DocumentStore
    {
        if ($this->documentStore === null) {
            $this->documentStore = new DocumentStore($this->path . '/docs');
        }
        return $this->documentStore;
    }

    /** Create the docs/ subdirectory if it doesn't exist yet. */
    private function ensureDocsDir(): void
    {
        $docsDir = $this->path . '/docs';
        if (!is_dir($docsDir)) {
            mkdir($docsDir, 0755, true);
        }
    }

    /**
     * Generate a random UUID v4 string.
     *
     * Uses cryptographically secure random bytes (random_bytes).
     */
    private function generateUuid(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant RFC 4122
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    // ------------------------------------------------------------------
    // Distance codec
    // ------------------------------------------------------------------

    private static function encodeDistance(Distance $d): int
    {
        return match ($d) {
            Distance::Cosine     => 0,
            Distance::Euclidean  => 1,
            Distance::DotProduct => 2,
            Distance::Manhattan  => 3,
        };
    }
}
