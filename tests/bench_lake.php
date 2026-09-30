<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Duck\Session;

$glob = '/Users/lumegalabs/Downloads/temp-cursor/segmentation-poc/data/parquet_contacts_5cr/contact/**/*.parquet';
$duck = new Session();
$sql = "SELECT count(*)::BIGINT AS cnt FROM read_parquet('" . str_replace("'", "''", $glob)
    . "', hive_partitioning=true, union_by_name=true) WHERE account_id = 83 AND COALESCE(email_status,0)=1 AND CAST(f6 AS VARCHAR)='f6-382'";

$times = [];
$cnt = null;
for ($i = 0; $i < 3; $i++) {
    $t0 = hrtime(true);
    $rows = $duck->query($sql);
    $times[] = round((hrtime(true) - $t0) / 1e6, 1);
    $cnt = $rows[0]['cnt'] ?? null;
}

$pageSql = "SELECT id, email, f6 FROM read_parquet('" . str_replace("'", "''", $glob)
    . "', hive_partitioning=true, union_by_name=true) WHERE account_id = 83 ORDER BY id LIMIT 50";
$pageTimes = [];
for ($i = 0; $i < 3; $i++) {
    $t0 = hrtime(true);
    $duck->query($pageSql);
    $pageTimes[] = round((hrtime(true) - $t0) / 1e6, 1);
}

echo json_encode([
    'segment_count' => ['cnt' => $cnt, 'ms' => $times],
    'page_50' => ['ms' => $pageTimes],
], JSON_PRETTY_PRINT), "\n";
