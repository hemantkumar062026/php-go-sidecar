<?php

declare(strict_types=1);

/**
 * One-shot MariaDB query for parallel benches.
 * Env: BENCH_SQL, MYSQL_DSN, MYSQL_USER, MYSQL_PASS
 */
$dsn = getenv('MYSQL_DSN') ?: 'mysql:host=127.0.0.1;port=3307;dbname=bench;charset=utf8mb4';
$user = getenv('MYSQL_USER') ?: 'root';
$pass = getenv('MYSQL_PASS') ?: 'bench';
$sql = getenv('BENCH_SQL') ?: 'SELECT 1 AS n';

$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$t0 = hrtime(true);
$rows = $pdo->query($sql)->fetchAll();
$ms = (hrtime(true) - $t0) / 1e6;

echo json_encode([
    'ms' => round($ms, 2),
    'rows' => $rows,
    'driver' => 'mariadb',
], JSON_UNESCAPED_SLASHES), "\n";
