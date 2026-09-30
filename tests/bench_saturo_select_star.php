<?php

declare(strict_types=1);

/** Heavy no-WHERE SELECT * via COPY (avoids materializing 50M rows in PHP). */
require dirname(__DIR__) . '/vendor/autoload.php';

use Saturio\DuckDB\DuckDB;

$glob = getenv('LAKE_GLOB')
    ?: '/Users/lumegalabs/Downloads/temp-cursor/segmentation-poc/data/parquet_contacts_5cr/contact/**/*.parquet';
$out = getenv('BENCH_OUT') ?: ('/tmp/saturo_star_' . getmypid() . '.parquet');
$threads = (int) (getenv('DUCKDB_THREADS') ?: '2');

$sql = "COPY (SELECT * FROM read_parquet('" . str_replace("'", "''", $glob)
    . "', hive_partitioning=true, union_by_name=true)) TO '"
    . str_replace("'", "''", $out) . "' (FORMAT PARQUET, COMPRESSION ZSTD)";

$t0 = hrtime(true);
$db = DuckDB::create();
$db->query('SET threads=' . $threads);
$db->query("SET TimeZone='UTC'");
$db->query($sql);
$ms = (hrtime(true) - $t0) / 1e6;
$size = is_file($out) ? filesize($out) : 0;
@unlink($out);

echo json_encode([
    'ms' => round($ms, 2),
    'out_bytes' => $size,
    'driver' => 'satur.io/duckdb',
], JSON_UNESCAPED_SLASHES), "\n";
