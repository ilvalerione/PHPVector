<?php

declare(strict_types=1);

namespace PHPVector\Benchmark;

use PHPVector\HNSW\Config as HNSWConfig;

/**
 * Builds a VectorDBBench-style Markdown report from benchmark results.
 */
final class Report
{
    /**
     * @param array[]   $results       One entry per scenario (keyed by scenario key).
     * @param HNSWConfig $hnswConfig   Config used for the run (for the header).
     * @param int        $k            k value used for search / recall.
     * @param int        $queries      Number of search queries per scenario.
     * @param int        $recallSamples Number of ground-truth samples used.
     */
    public static function generate(
        array $results,
        HNSWConfig $hnswConfig,
        int $k,
        int $queries,
        int $recallSamples,
    ): string {
        $lines = [];

        // ── Header ────────────────────────────────────────────────────────
        $lines[] = '# PHPVector Benchmark Report';
        $lines[] = '';
        $lines[] = sprintf('> **Generated:** %s  ', date('Y-m-d H:i:s'));
        $lines[] = sprintf('> **PHP:** %s  ', PHP_VERSION);
        $lines[] = sprintf('> **OS:** %s %s  ', PHP_OS_FAMILY, php_uname('m'));
        $lines[] = sprintf(
            '> **HNSW config:** M=%d · efConstruction=%d · efSearch=%d · distance=%s',
            $hnswConfig->M,
            $hnswConfig->efConstruction,
            $hnswConfig->efSearch,
            $hnswConfig->distance->name,
        );
        $lines[] = sprintf('> **Queries:** %s per scenario · top-%d · %s recall samples',
            number_format($queries), $k, number_format($recallSamples));
        $lines[] = '';

        // ── Summary table ─────────────────────────────────────────────────
        $lines[] = '## Summary';
        $lines[] = '';

        $hasRecall  = array_any($results, static fn($r) => $r['recall'] !== null);
        $hasPersist = array_any($results, static fn($r) => $r['persist'] !== null);

        $headers = ['Scenario', 'Vectors', 'Dims', 'Build time', 'Insert/s', 'QPS', 'P99 ms'];
        if ($hasRecall) {
            $headers[] = "Recall@{$k}";
        }

        $lines[] = '| ' . implode(' | ', $headers) . ' |';
        $lines[] = '|' . implode('|', array_fill(0, count($headers), '---')) . '|';

        foreach ($results as $result) {
            $s = $result['scenario'];
            $b = $result['build'];
            $q = $result['search'];

            $row = [
                "**{$s['label']}** — {$s['desc']}",
                number_format($s['n']),
                (string) $s['dims'],
                self::fmtTime($b['time_s']),
                number_format((int) $b['throughput']),
                number_format((int) $q['qps']),
                number_format($q['latency_ms']['p99'], 1),
            ];

            if ($hasRecall) {
                $row[] = $result['recall'] !== null
                    ? number_format($result['recall'][$k] * 100.0, 1) . '%'
                    : '—';
            }

            $lines[] = '| ' . implode(' | ', $row) . ' |';
        }

        $lines[] = '';

        // ── Per-scenario detail ───────────────────────────────────────────
        $lines[] = '---';
        $lines[] = '';

        foreach ($results as $result) {
            $s = $result['scenario'];
            $b = $result['build'];
            $q = $result['search'];

            $lines[] = "## {$s['label']} — {$s['desc']}";
            $lines[] = '';

            // Build
            $lines[] = '### Index build';
            $lines[] = '';
            $lines[] = '| Metric | Value |';
            $lines[] = '|--------|-------|';
            $lines[] = sprintf('| Vectors inserted | %s |', number_format($s['n']));
            $lines[] = sprintf('| Build time       | %s |', self::fmtTime($b['time_s']));
            $lines[] = sprintf('| Throughput       | %s doc/s |', number_format((int) $b['throughput']));
            $lines[] = sprintf('| Memory (RSS)     | %s |', self::fmtMb($b['memory_mb']));
            $lines[] = '';

            // Search latency
            $lines[] = sprintf('### Search latency (%s queries, k=%d)', number_format($q['queries']), $k);
            $lines[] = '';
            $lines[] = '| Metric | Value |';
            $lines[] = '|--------|-------|';
            $lines[] = sprintf('| QPS (serial)    | %s |', number_format((int) $q['qps']));
            $lines[] = sprintf('| Latency min     | %.2f ms |', $q['latency_ms']['min']);
            $lines[] = sprintf('| Latency mean    | %.2f ms |', $q['latency_ms']['mean']);
            $lines[] = sprintf('| Latency P50     | %.2f ms |', $q['latency_ms']['p50']);
            $lines[] = sprintf('| Latency P95     | %.2f ms |', $q['latency_ms']['p95']);
            $lines[] = sprintf('| Latency P99     | %.2f ms |', $q['latency_ms']['p99']);
            $lines[] = sprintf('| Latency max     | %.2f ms |', $q['latency_ms']['max']);
            $lines[] = '';

            // Recall
            if ($result['recall'] !== null) {
                $lines[] = sprintf('### Recall (%s ground-truth samples)', number_format($recallSamples));
                $lines[] = '';
                $lines[] = '| k | Recall |';
                $lines[] = '|---|--------|';
                foreach ($result['recall'] as $kv => $r) {
                    $lines[] = sprintf('| %d | %.1f%% |', $kv, $r * 100.0);
                }
                $lines[] = '';
            }

            // Persistence
            if ($result['persist'] !== null) {
                $p = $result['persist'];
                $lines[] = '### Persistence';
                $lines[] = '';
                $lines[] = '| Operation | Folder size | Time | Throughput |';
                $lines[] = '|-----------|-------------|------|------------|';
                $lines[] = sprintf('| `save()` | %s | %s | %.1f MB/s |',
                    self::fmtMb($p['folder_size_mb']),
                    self::fmtTime($p['save_s']),
                    $p['save_mb_s'],
                );
                $lines[] = sprintf('| `open()` | %s | %s | %.1f MB/s |',
                    self::fmtMb($p['folder_size_mb']),
                    self::fmtTime($p['open_s']),
                    $p['open_mb_s'],
                );
                $lines[] = '';
            }

            $lines[] = '---';
            $lines[] = '';
        }

        // ── Footer ────────────────────────────────────────────────────────
        $lines[] = '*Benchmark methodology follows [VectorDBBench](https://github.com/zilliztech/VectorDBBench): '
            . 'serial QPS, P99 tail latency, and Recall@k against brute-force ground truth on synthetic '
            . 'unit-normalised vectors (reproducible seed).*';
        $lines[] = '';

        return implode("\n", $lines);
    }

    // ── Formatters ────────────────────────────────────────────────────────

    private static function fmtTime(float $s): string
    {
        if ($s >= 60.0) {
            return sprintf('%dm %ds', (int) ($s / 60), (int) fmod($s, 60));
        }
        if ($s >= 1.0) {
            return number_format($s, 2) . ' s';
        }
        if ($s >= 0.001) {
            return number_format($s * 1_000, 0) . ' ms';
        }
        return number_format($s * 1_000_000, 0) . ' µs';
    }

    private static function fmtMb(float $mb): string
    {
        if ($mb >= 1_024.0) {
            return number_format($mb / 1_024.0, 2) . ' GB';
        }
        return number_format($mb, 1) . ' MB';
    }
}
