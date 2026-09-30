<?php

declare(strict_types=1);

$glob = getenv('GLOB') ?: '';
$seg = getenv('SEG') ?: '';
$page = getenv('PAGE') ?: '';
$dsn = 'duckdb::memory:;threads=4;memory_limit=4GB';

$pdo = new PDO($dsn, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_PERSISTENT => true,
]);
$pdo->exec("SET TimeZone='UTC'");

$out = [];
foreach (['seg' => $seg, 'page' => $page] as $name => $sql) {
    $times = [];
    for ($i = 0; $i < 5; $i++) {
        $t0 = hrtime(true);
        $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $times[] = (hrtime(true) - $t0) / 1e6;
    }
    sort($times);
    $out[$name] = [
        'min' => round($times[0], 1),
        'med' => round($times[2], 1),
        'max' => round($times[4], 1),
        'runs' => array_map(static fn ($x) => round($x, 1), $times),
    ];
}

$times = [];
for ($i = 0; $i < 3; $i++) {
    $t0 = hrtime(true);
    $p = new PDO($dsn, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_PERSISTENT => false,
    ]);
    $p->exec("SET TimeZone='UTC'");
    $p->query($seg)->fetchAll(PDO::FETCH_ASSOC);
    $times[] = (hrtime(true) - $t0) / 1e6;
}
sort($times);
$out['seg_non_persistent_new_pdo'] = [
    'min' => round($times[0], 1),
    'med' => round($times[(int) floor(count($times) / 2)], 1),
    'max' => round($times[count($times) - 1], 1),
    'runs' => array_map(static fn ($x) => round($x, 1), $times),
];

echo json_encode($out, JSON_PRETTY_PRINT), "\n";
