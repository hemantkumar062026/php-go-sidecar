<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Filter\QueryParser;

$fail = 0;

function assert_true(bool $cond, string $msg): void
{
    global $fail;
    if (!$cond) {
        echo "FAIL: $msg\n";
        $fail++;
        return;
    }
    echo "ok: $msg\n";
}

$r = QueryParser::parse([
    'account_id' => 99,
    'values' => ['email_status' => 1, 'f18' => "O'Brien"],
    'select' => ['id', 'email'],
    'limit' => 10,
]);

assert_true(str_contains($r['count_sql'], '`data_db`.`sftp_contact_99`'), 'table name');
assert_true(str_contains($r['where'], '`account_id` = 99'), 'account_id forced');
assert_true(str_contains($r['where'], '`is_deleted` = 0'), 'soft-delete default');
assert_true(str_contains($r['where'], "`f18` = 'O''Brien'"), 'string escape');
assert_true(str_contains($r['select_sql'], 'LIMIT 10'), 'limit');

try {
    QueryParser::parse(['account_id' => 1, 'values' => ['nope' => 1]]);
    assert_true(false, 'unknown field should throw');
} catch (InvalidArgumentException $e) {
    assert_true(true, 'unknown field rejected');
}

try {
    QueryParser::parse(['account_id' => 0, 'values' => []]);
    assert_true(false, 'bad account should throw');
} catch (InvalidArgumentException $e) {
    assert_true(true, 'bad account rejected');
}

$in = QueryParser::parse([
    'account_id' => 1,
    'values' => ['import_source' => [4, 5]],
]);
assert_true(str_contains($in['where'], '`import_source` IN (4, 5)'), 'IN list from array values');

exit($fail > 0 ? 1 : 0);
