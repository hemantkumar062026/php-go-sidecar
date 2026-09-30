<?php

declare(strict_types=1);

/**
 * Minimal example: persistent DuckDB PDO → read Parquet.
 *
 * Run after: ./scripts/install-pdo-duckdb.sh
 *   php examples/persistent_pdo_read.php
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Duck\Session;

if (!extension_loaded('pdo_duckdb')) {
    fwrite(STDERR, "pdo_duckdb not loaded. Run: ./scripts/install-pdo-duckdb.sh\n");
    fwrite(STDERR, "Current driver fallback: " . Session::driver() . "\n");
    exit(1);
}

// Same DSN pattern Session uses — persistent across requests in PHP-FPM.
$threads = getenv('DUCKDB_THREADS') ?: '4';
$mem = getenv('DUCKDB_MEMORY_LIMIT') ?: '4GB';
$dsn = "duckdb::memory:;threads={$threads};memory_limit={$mem}";

$pdo = new PDO($dsn, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_PERSISTENT => true, // ← reuse connection in this PHP process
]);
$pdo->exec("SET TimeZone='UTC'");

$glob = getenv('LAKE_GLOB') ?: dirname(__DIR__) . '/demo/samples/contacts_sample.csv';
// CSV demo if no lake; for Parquet set LAKE_GLOB to **/*.parquet
if (str_ends_with($glob, '.csv')) {
    $from = "read_csv_auto(" . App\Duck\Session::quote($glob) . ", header=true)";
} else {
    $from = "read_parquet(" . App\Duck\Session::quote($glob) . ", hive_partitioning=true, union_by_name=true)";
}

$sql = "SELECT * FROM {$from} LIMIT 5";
$t0 = hrtime(true);
$rows = $pdo->query($sql)->fetchAll();
$ms = (hrtime(true) - $t0) / 1e6;

echo "driver=pdo_duckdb persistent=1 elapsed_ms=" . round($ms, 1) . "\n";
echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

// Second query reuses same PDO / DuckDB (no reconnect cost)
$t1 = hrtime(true);
$count = $pdo->query("SELECT count(*)::BIGINT AS cnt FROM {$from}")->fetch();
echo "second_query_ms=" . round((hrtime(true) - $t1) / 1e6, 1) . " count=" . ($count['cnt'] ?? '?') . "\n";
