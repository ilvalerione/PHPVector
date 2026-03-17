<?php

declare(strict_types=1);

namespace PHPVector\Persistence;

/**
 * Binary encoder / decoder for VectorDatabase index files.
 *
 * Manages two files inside a VectorDatabase folder:
 *
 *   hnsw.bin  — HNSW graph (nodes: nodeId, maxLayer, vector, connections).
 *               No document text / metadata — those live in docs/{nodeId}.bin.
 *
 *   bm25.bin  — BM25 inverted index (totalTokens, docLengths, invertedIndex).
 *               No Document objects.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * hnsw.bin layout
 * ────────────────────────────────────────────────────────────────────────────
 *   magic(4)  = 'PHPV'
 *   version(1)
 *   dimension(4N)  nodeCount(4N)  entryPoint(4N)  maxLayer(4N)
 *   per node:
 *     nodeId(4N)  nodeMaxLayer(4N)
 *     vector: dimension × float64(8)
 *     per layer l = 0 … nodeMaxLayer:
 *       connCount(4N)  connCount × nodeId(4N)
 *
 * ────────────────────────────────────────────────────────────────────────────
 * bm25.bin layout
 * ────────────────────────────────────────────────────────────────────────────
 *   magic(4)  = 'BM25'
 *   version(1)
 *   totalTokens(4N)
 *   docCount(4N)  per doc: nodeId(4N) + length(4N)
 *   termCount(4N) per term: termLen(2n) + termBytes + postCount(4N) + per posting: nodeId(4N) + tf(4N)
 */
final class IndexSerializer
{
    private const HNSW_MAGIC      = 'PHPV';
    private const BM25_MAGIC      = 'BM25';
    private const VERSION         = 1;
    private const NULL_ENTRY_POINT = 0xFFFFFFFF;

    // ======================================================================
    // HNSW
    // ======================================================================

    /**
     * Write the HNSW graph state to $path.
     *
     * @param array{
     *   entryPoint: int|null,
     *   maxLayer: int,
     *   dimension: int|null,
     *   nodes: array<int, array{maxLayer: int, vector: float[], connections: array<int, int[]>}>
     * } $state   From HNSW\Index::exportState() — the 'documents' key is ignored.
     */
    public function writeHnsw(string $path, array $state): void
    {
        $dim       = (int) ($state['dimension'] ?? 0);
        $nodes     = $state['nodes'];
        $nodeCount = count($nodes);
        $ep        = $state['entryPoint'] ?? self::NULL_ENTRY_POINT;

        $buf  = self::HNSW_MAGIC;
        $buf .= pack('C', self::VERSION);
        $buf .= pack('NNNN', $dim, $nodeCount, $ep, (int) $state['maxLayer']);

        foreach ($nodes as $nodeId => $node) {
            $buf .= pack('NN', $nodeId, $node['maxLayer']);
            if ($dim > 0) {
                $buf .= pack('d*', ...$node['vector']);
            }
            for ($l = 0; $l <= $node['maxLayer']; $l++) {
                $conns = $node['connections'][$l] ?? [];
                $cnt   = count($conns);
                $buf  .= pack('N', $cnt);
                if ($cnt > 0) {
                    $buf .= pack('N*', ...$conns);
                }
            }
        }

        if (file_put_contents($path, $buf) === false) {
            throw new \RuntimeException("Failed to write hnsw.bin: {$path}");
        }
    }

    /**
     * Read an hnsw.bin file.
     *
     * @return array{
     *   entryPoint: int|null,
     *   maxLayer: int,
     *   dimension: int|null,
     *   nodeCount: int,
     *   nodes: array<int, array{maxLayer: int, vector: float[], connections: array<int, int[]>}>
     * }
     */
    public function readHnsw(string $path): array
    {
        $data = @file_get_contents($path);
        if ($data === false) {
            throw new \RuntimeException("Failed to read hnsw.bin: {$path}");
        }

        $off = 0;

        // Magic + version
        if (substr($data, $off, 4) !== self::HNSW_MAGIC) {
            throw new \RuntimeException("Not a valid hnsw.bin file: {$path}");
        }
        $off += 4;

        $version = ord($data[$off]);
        $off    += 1;
        if ($version !== self::VERSION) {
            throw new \RuntimeException("Unsupported hnsw.bin version: {$version}");
        }

        // Header
        [$dim, $nodeCount, $epRaw, $maxLayer] = array_values(unpack('N4', $data, $off));
        $off       += 16;
        $entryPoint = ($epRaw === self::NULL_ENTRY_POINT) ? null : (int) $epRaw;

        // Nodes
        $nodes = [];
        for ($i = 0; $i < $nodeCount; $i++) {
            [$nodeId, $nodeMaxLayer] = array_values(unpack('N2', $data, $off));
            $off += 8;

            if ($dim > 0) {
                $vector = array_values(unpack('d' . $dim, $data, $off));
                $off   += $dim * 8;
            } else {
                $vector = [];
            }

            $connections = [];
            for ($l = 0; $l <= $nodeMaxLayer; $l++) {
                [$connCount] = array_values(unpack('N', $data, $off));
                $off         += 4;
                if ($connCount > 0) {
                    $connections[$l] = array_values(unpack('N' . $connCount, $data, $off));
                    $off            += $connCount * 4;
                } else {
                    $connections[$l] = [];
                }
            }

            $nodes[(int) $nodeId] = [
                'maxLayer'    => (int) $nodeMaxLayer,
                'vector'      => $vector,
                'connections' => $connections,
            ];
        }

        return [
            'entryPoint' => $entryPoint,
            'maxLayer'   => (int) $maxLayer,
            'dimension'  => $dim > 0 ? $dim : null,
            'nodeCount'  => (int) $nodeCount,
            'nodes'      => $nodes,
        ];
    }

    // ======================================================================
    // BM25
    // ======================================================================

    /**
     * Write the BM25 inverted index to $path.
     *
     * @param array{
     *   totalTokens: int,
     *   docLengths: array<int, int>,
     *   invertedIndex: array<string, array<int, int>>
     * } $state  From BM25\Index::exportState().
     */
    public function writeBm25(string $path, array $state): void
    {
        $buf  = self::BM25_MAGIC;
        $buf .= pack('C', self::VERSION);
        $buf .= pack('N', $state['totalTokens']);

        $docLengths = $state['docLengths'];
        $buf .= pack('N', count($docLengths));
        foreach ($docLengths as $nodeId => $length) {
            $buf .= pack('NN', $nodeId, $length);
        }

        $invertedIndex = $state['invertedIndex'];
        $buf .= pack('N', count($invertedIndex));
        foreach ($invertedIndex as $term => $postings) {
            $termBytes = (string) $term;
            $buf .= pack('n', strlen($termBytes)) . $termBytes;
            $buf .= pack('N', count($postings));
            foreach ($postings as $postNodeId => $tf) {
                $buf .= pack('NN', $postNodeId, $tf);
            }
        }

        if (file_put_contents($path, $buf) === false) {
            throw new \RuntimeException("Failed to write bm25.bin: {$path}");
        }
    }

    /**
     * Read a bm25.bin file.
     *
     * @return array{
     *   totalTokens: int,
     *   docLengths: array<int, int>,
     *   invertedIndex: array<string, array<int, int>>
     * }
     */
    public function readBm25(string $path): array
    {
        $data = @file_get_contents($path);
        if ($data === false) {
            throw new \RuntimeException("Failed to read bm25.bin: {$path}");
        }

        $off = 0;

        // Magic + version
        if (substr($data, $off, 4) !== self::BM25_MAGIC) {
            throw new \RuntimeException("Not a valid bm25.bin file: {$path}");
        }
        $off += 4;

        $version = ord($data[$off]);
        $off    += 1;
        if ($version !== self::VERSION) {
            throw new \RuntimeException("Unsupported bm25.bin version: {$version}");
        }

        // totalTokens
        [$totalTokens] = array_values(unpack('N', $data, $off));
        $off           += 4;

        // docLengths
        [$docLenCount] = array_values(unpack('N', $data, $off));
        $off           += 4;
        $docLengths     = [];
        for ($i = 0; $i < $docLenCount; $i++) {
            [$dlNodeId, $dlLen] = array_values(unpack('N2', $data, $off));
            $off               += 8;
            $docLengths[(int) $dlNodeId] = (int) $dlLen;
        }

        // invertedIndex
        [$termCount] = array_values(unpack('N', $data, $off));
        $off         += 4;
        $invertedIndex = [];
        for ($i = 0; $i < $termCount; $i++) {
            [$termLen] = array_values(unpack('n', $data, $off));
            $off       += 2;
            $term       = substr($data, $off, $termLen);
            $off       += $termLen;

            [$postCount] = array_values(unpack('N', $data, $off));
            $off         += 4;
            $postings     = [];
            for ($j = 0; $j < $postCount; $j++) {
                [$postNodeId, $tf] = array_values(unpack('N2', $data, $off));
                $off              += 8;
                $postings[(int) $postNodeId] = (int) $tf;
            }
            $invertedIndex[$term] = $postings;
        }

        return [
            'totalTokens'   => (int) $totalTokens,
            'docLengths'    => $docLengths,
            'invertedIndex' => $invertedIndex,
        ];
    }
}
