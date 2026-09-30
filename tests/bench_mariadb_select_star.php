<?php

declare(strict_types=1);

/**
 * Heavy no-WHERE SELECT * via INTO OUTFILE (engine-side; fair vs DuckDB COPY).
 * Env: MYSQL_DSN MYSQL_USER MYSQL_PASS BENCH_OUT (basename under /var/lib/mysql-files)
 */
$dsn = getenv('MYSQL_DSN') ?: 'mysql:host=127.0.0.1;port=3307;dbname=bench;charset=utf8mb4';
$user = getenv('MYSQL_USER') ?: 'root';
$pass = getenv('MYSQL_PASS') ?: 'bench';
$base = basename(getenv('BENCH_OUT') ?: ('mariadb_star_' . getmypid() . '.csv'));
$outPath = '/var/lib/mysql-files/' . $base;
$hostPath = '/tmp/mariadb_files/' . $base;
@unlink($hostPath);

$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$sql = 'SELECT * FROM contacts INTO OUTFILE ' . $pdo->quote($outPath)
    . " FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '\"' LINES TERMINATED BY '\\n'";

$t0 = hrtime(true);
$pdo->exec($sql);
$ms = (hrtime(true) - $t0) / 1e6;

$size = is_file($hostPath) ? filesize($hostPath) : 0;
@unlink($hostPath);

echo json_encode([
    'ms' => round($ms, 2),
    'out_bytes' => $size,
    'driver' => 'mariadb',
], JSON_UNESCAPED_SLASHES), "\n";
