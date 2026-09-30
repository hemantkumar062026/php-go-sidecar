<?php

declare(strict_types=1);

/**
 * One-shot satur.io DuckDB query (used by parallel bench driver).
 * Usage: php tests/bench_saturio_one.php
 * Env: BENCH_SQL, DUCKDB_THREADS
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Saturio\DuckDB\DB\Configuration;
use Saturio\DuckDB\DuckDB;

$sql = getenv('BENCH_SQL') ?: 'SELECT 1 AS n';
$threads = getenv('DUCKDB_THREADS') ?: '2';

$t0 = hrtime(true);
$config = new Configuration();
// Configuration API may accept option array — try common pattern
try {
    $db = DuckDB::create(config: $config);
} catch (Throwable $e) {
    $db = DuckDB::create();
}
$db->query("SET threads=" . (int) $threads);
$db->query("SET TimeZone='UTC'");
$result = $db->query($sql);
$rows = [];
foreach ($result->rows(true) as $row) {
    $rows[] = $row;
}
$ms = (hrtime(true) - $t0) / 1e6;

echo json_encode([
    'ms' => round($ms, 2),
    'rows' => $rows,
    'driver' => 'satur.io/duckdb',
], JSON_UNESCAPED_SLASHES), "\n";
