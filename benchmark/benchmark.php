#!/usr/bin/env php
<?php

/**
 * PHPVector Benchmark
 *
 * Measures index build throughput, serial QPS, P99 tail latency, Recall@k, and
 * persistence speed following the VectorDBBench methodology.
 *
 * Usage:
 *   php benchmark/benchmark.php [options]
 *
 * Options:
 *   --scenarios=<list>        Comma-separated: xs,small,medium,large,highdim  (default: xs,small)
 *   --k=<n>                   Nearest neighbours to retrieve                   (default: 10)
 *   --queries=<n>             Search queries per scenario                       (default: 200)
 *   --recall-samples=<n>      Ground-truth samples for recall computation       (default: 50)
 *   --ef-search=<n>           HNSW efSearch                                    (default: 50)
 *   --ef-construction=<n>     HNSW efConstruction                              (default: 200)
 *   --m=<n>                   HNSW M parameter                                 (default: 16)
 *   --seed=<n>                Random seed for reproducibility                  (default: 42)
 *   --output=<file>           Write Markdown report to file (default: stdout)
 *   --no-save                 Skip persistence benchmarks (save / open)
 *   --no-recall               Skip recall computation
 *   --help, -h                Show this help
 *
 * Examples:
 *   php benchmark/benchmark.php
 *   php benchmark/benchmark.php --scenarios=small,medium,large --queries=500
 *   php benchmark/benchmark.php --scenarios=large --no-recall --output=report.md
 */

declare(strict_types=1);

// ── Bootstrap ─────────────────────────────────────────────────────────────────

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "Error: vendor/autoload.php not found — run 'composer install' first.\n");
    exit(1);
}
require $autoload;

ini_set('memory_limit', '2G');

use PHPVector\Benchmark\BruteForce;
use PHPVector\Benchmark\Stats;
use PHPVector\Benchmark\Report;
use PHPVector\BM25\Config as BM25Config;
use PHPVector\BM25\SimpleTokenizer;
use PHPVector\Document;
use PHPVector\HNSW\Config as HNSWConfig;
use PHPVector\VectorDatabase;

// ── Built-in scenario definitions ─────────────────────────────────────────────
//
// Each scenario generates N random unit-normalised vectors of the given
// dimension — matching the SIFT / Cohere dimension ranges used by VectorDBBench.

const SCENARIOS = [
    'xs'      => ['n' =>   1_000, 'dims' =>  128, 'label' => 'XS',  'desc' => '1 K × 128d'],
    'small'   => ['n' =>  10_000, 'dims' =>  128, 'label' => 'S',   'desc' => '10 K × 128d'],
    'medium'  => ['n' =>  50_000, 'dims' =>  128, 'label' => 'M',   'desc' => '50 K × 128d'],
    'large'   => ['n' => 100_000, 'dims' =>  128, 'label' => 'L',   'desc' => '100 K × 128d'],
    'highdim' => ['n' =>  10_000, 'dims' =>  768, 'label' => 'HD',  'desc' => '10 K × 768d'],
];

// ── CLI argument parsing ───────────────────────────────────────────────────────

$opts = getopt('h', [
    'scenarios:', 'k:', 'queries:', 'recall-samples:',
    'ef-search:', 'ef-construction:', 'm:', 'seed:',
    'output:', 'no-save', 'no-persist', 'no-recall', 'help', 'h',
]);

if (isset($opts['help']) || isset($opts['h'])) {
    // Extract and print the file's opening docblock as usage text.
    $src = file_get_contents(__FILE__);
    if (preg_match('#/\*\*(.*?)\*/#s', $src, $m)) {
        echo preg_replace('/^\s*\* ?/m', '', trim($m[1]));
        echo "\n";
    }
    exit(0);
}

$scenarioKeys = array_filter(
    explode(',', $opts['scenarios'] ?? 'xs,small'),
    static fn(string $k): bool => isset(SCENARIOS[$k]),
);
if (empty($scenarioKeys)) {
    fwrite(STDERR, "Error: no valid scenarios specified. Choose from: " . implode(', ', array_keys(SCENARIOS)) . "\n");
    exit(1);
}

$k             = max(1, (int) ($opts['k'] ?? 10));
$queries       = max(1, (int) ($opts['queries'] ?? 200));
$recallSamples = max(1, (int) ($opts['recall-samples'] ?? 50));
$efSearch      = max(1, (int) ($opts['ef-search'] ?? 50));
$efConstr      = max(1, (int) ($opts['ef-construction'] ?? 200));
$m             = max(2, (int) ($opts['m'] ?? 16));
$seed          = (int) ($opts['seed'] ?? 42);
$outputFile    = $opts['output'] ?? null;
$noPersist     = isset($opts['no-save']) || isset($opts['no-persist']);
$noRecall      = isset($opts['no-recall']);

$hnswConfig = new HNSWConfig(
    M:               $m,
    efConstruction:  max($efConstr, $m),  // efConstruction must be ≥ M
    efSearch:        $efSearch,
);

// ── Helpers ────────────────────────────────────────────────────────────────────

function progress(string $msg): void
{
    fwrite(STDERR, $msg);
}

/**
 * Generate N + $queries random unit-normalised vectors deterministically.
 * The first N are used as the dataset; the remaining $queries as search queries.
 * Queries are kept disjoint from the dataset so they are never exact matches.
 *
 * @return array{float[][], float[][]}  [dataVectors, queryVectors]
 */
function generateData(int $n, int $dims, int $queries, int $seed): array
{
    mt_srand($seed);

    $total   = $n + $queries;
    $all     = [];

    for ($i = 0; $i < $total; $i++) {
        $v    = [];
        $norm = 0.0;
        for ($j = 0; $j < $dims; $j++) {
            $x     = (mt_rand() / mt_getrandmax()) * 2.0 - 1.0;
            $v[]   = $x;
            $norm += $x * $x;
        }
        $norm  = sqrt($norm) ?: 1.0;
        $all[] = array_map(static fn(float $x): float => $x / $norm, $v);
    }

    return [array_slice($all, 0, $n), array_slice($all, $n)];
}

/**
 * Compute Recall@k for multiple k values by comparing HNSW results against
 * exact brute-force results over the first $nRecall query vectors.
 *
 * @param float[][] $queryVectors
 * @return array<int, float>  k → recall in [0, 1]
 */
function computeRecall(
    VectorDatabase $db,
    BruteForce $bf,
    array $queryVectors,
    int $k,
    int $nRecall,
): array {
    // Always measure recall at 1, 5 (if k≥5), and k.
    $kValues = array_values(array_unique([1, min(5, $k), $k]));
    $totals  = array_fill_keys($kValues, 0.0);

    for ($i = 0; $i < $nRecall; $i++) {
        $q        = $queryVectors[$i];
        $bfIds    = $bf->search($q, $k);
        $hnswIds  = array_map(
            static fn($r) => $r->document->id,
            $db->vectorSearch($q, $k),
        );

        foreach ($kValues as $kv) {
            $bfSlice   = array_slice($bfIds, 0, $kv);
            $hnswSlice = array_slice($hnswIds, 0, $kv);
            $totals[$kv] += count(array_intersect($bfSlice, $hnswSlice)) / $kv;
        }
    }

    return array_map(static fn(float $t): float => $t / $nRecall, $totals);
}

/**
 * Recursively compute the total size of a directory in megabytes.
 */
function folderSizeMb(string $dir): float
{
    $bytes = 0;
    $iter  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iter as $file) {
        $bytes += $file->getSize();
    }
    return $bytes / (1024 * 1024);
}

/**
 * Recursively delete a directory and all its contents.
 */
function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach ((array) glob($dir . '/*') as $item) {
        is_dir((string) $item) ? rrmdir((string) $item) : unlink((string) $item);
    }
    rmdir($dir);
}

/**
 * Run a complete benchmark for one scenario and return the result array.
 */
function runScenario(
    array $scenario,
    int $k,
    int $queries,
    int $recallSamples,
    HNSWConfig $hnswConfig,
    int $seed,
    bool $noPersist,
    bool $noRecall,
): array {
    $n    = $scenario['n'];
    $dims = $scenario['dims'];

    // 1. Generate dataset ──────────────────────────────────────────────────
    progress("  Generating {$n} vectors ({$dims}d) …\n");
    [$dataVectors, $queryVectors] = generateData($n, $dims, $queries, $seed);

    // 2. Index build ───────────────────────────────────────────────────────
    progress("  Building HNSW index …\n");

    $buildStart = hrtime(true);

    $db = new VectorDatabase($hnswConfig, new BM25Config(), new SimpleTokenizer([]));
    for ($i = 0; $i < $n; $i++) {
        $db->addDocument(new Document(id: $i, vector: $dataVectors[$i]));
        if ($i > 0 && $i % 10_000 === 0) {
            progress("    {$i}/{$n} …\r");
        }
    }

    $buildTime = (hrtime(true) - $buildStart) / 1e9;
    // Report memory committed to the PHP process after the index is fully built.
    $memUsed   = memory_get_usage(true) / (1024 * 1024);

    progress("    done — {$n}/{$n}   \n");

    // 3. Warmup ────────────────────────────────────────────────────────────
    $warmup = min(20, $queries);
    for ($i = 0; $i < $warmup; $i++) {
        $db->vectorSearch($queryVectors[$i], $k);
    }

    // 4. Serial search latency ─────────────────────────────────────────────
    progress("  Running {$queries} search queries …\n");

    $latencies  = [];
    $searchStart = hrtime(true);

    for ($i = 0; $i < $queries; $i++) {
        $t0 = hrtime(true);
        $db->vectorSearch($queryVectors[$i], $k);
        $latencies[] = hrtime(true) - $t0;
    }

    $totalSearch = (hrtime(true) - $searchStart) / 1e9;
    $qps         = $queries / $totalSearch;
    $latStats    = Stats::latencyStats($latencies);

    // 5. Recall ────────────────────────────────────────────────────────────
    $recall = null;
    if (!$noRecall) {
        $nRecall = min($recallSamples, $queries);
        progress("  Computing Recall@{$k} over {$nRecall} ground-truth queries …\n");

        $bf = new BruteForce();
        for ($i = 0; $i < $n; $i++) {
            $bf->add($i, $dataVectors[$i]);
        }

        $recall = computeRecall($db, $bf, $queryVectors, $k, $nRecall);
        unset($bf);
    }

    // 6. Persistence ───────────────────────────────────────────────────────
    //
    // Build a fresh VectorDatabase with a temp folder path so document files
    // are written async during insert.  save() flushes the HNSW graph and
    // BM25 index (waiting for any outstanding async doc writes first).
    // open() reads only hnsw.bin + bm25.bin — document files are lazy.
    $persist = null;
    if (!$noPersist) {
        progress("  Benchmarking save / open …\n");

        $tmpDir = sys_get_temp_dir() . '/phpvbench_' . uniqid('', true);
        mkdir($tmpDir, 0755, true);

        $dbSave = new VectorDatabase($hnswConfig, new BM25Config(), new SimpleTokenizer([]), $tmpDir);
        for ($i = 0; $i < $n; $i++) {
            $dbSave->addDocument(new Document(id: $i, vector: $dataVectors[$i]));
        }

        $t0 = hrtime(true);
        $dbSave->save();
        $saveTime = (hrtime(true) - $t0) / 1e9;

        $folderSizeMb = folderSizeMb($tmpDir);

        $t0 = hrtime(true);
        VectorDatabase::open($tmpDir, $hnswConfig);
        $openTime = (hrtime(true) - $t0) / 1e9;

        rrmdir($tmpDir);

        $persist = [
            'folder_size_mb' => $folderSizeMb,
            'save_s'         => $saveTime,
            'save_mb_s'      => $saveTime > 0.0 ? $folderSizeMb / $saveTime : 0.0,
            'open_s'         => $openTime,
            'open_mb_s'      => $openTime > 0.0 ? $folderSizeMb / $openTime : 0.0,
        ];
    }

    return [
        'scenario' => $scenario,
        'build'    => [
            'time_s'     => $buildTime,
            'throughput' => $n / $buildTime,
            'memory_mb'  => $memUsed,
        ],
        'search' => [
            'queries'     => $queries,
            'qps'         => $qps,
            'latency_ms'  => $latStats,
        ],
        'recall'  => $recall,
        'persist' => $persist,
    ];
}

// ── Main loop ─────────────────────────────────────────────────────────────────

$results = [];

foreach ($scenarioKeys as $key) {
    $scenario = SCENARIOS[$key];
    progress(sprintf("\n[%s] %s\n", strtoupper($key), $scenario['desc']));

    $results[$key] = runScenario(
        scenario:      $scenario,
        k:             $k,
        queries:       $queries,
        recallSamples: $recallSamples,
        hnswConfig:    $hnswConfig,
        seed:          $seed,
        noPersist:     $noPersist,
        noRecall:      $noRecall,
    );

    progress("  Done.\n");
}

// ── Generate report ───────────────────────────────────────────────────────────

progress("\nGenerating report …\n");

$report = Report::generate($results, $hnswConfig, $k, $queries, $recallSamples);

if ($outputFile !== null) {
    if (file_put_contents($outputFile, $report) === false) {
        fwrite(STDERR, "Error: could not write to {$outputFile}\n");
        exit(1);
    }
    progress("Report written to: {$outputFile}\n\n");
} else {
    echo $report;
}
