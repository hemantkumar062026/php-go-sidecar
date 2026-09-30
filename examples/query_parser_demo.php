<?php

declare(strict_types=1);

/**
 * Demo: values → SQL for sftp_contact_{account_id}
 *
 *   php examples/query_parser_demo.php
 */
require dirname(__DIR__) . '/src/bootstrap.php';

use App\Filter\QueryParser;

// --- A) Flat values (all ANDed) ---
$a = QueryParser::parse([
    'account_id' => 42,
    'dialect' => 'mariadb',
    'values' => [
        'email_status' => 1,
        'is_contact' => 1,
        'f18' => 'Mumbai',          // City
        'f5' => 'LOGO1',            // Logo
        'import_source' => [4, 5],  // list → IN (...)
        'f31' => ['op' => 'gte', 'value' => 25], // Age >= 25
    ],
    'select' => ['id', 'email', 'mobile', 'f18', 'f20', 'f31'],
    'order_by' => 'id',
    'limit' => 50,
]);

// --- B) Explicit filter AST ---
$b = QueryParser::parse([
    'account_id' => 42,
    'dialect' => 'mariadb',
    'filter' => [
        'op' => 'and',
        'children' => [
            ['op' => 'eq', 'field' => 'email_status', 'value' => 1],
            ['op' => 'eq', 'field' => 'is_deleted', 'value' => 0],
            [
                'op' => 'or',
                'children' => [
                    ['op' => 'eq', 'field' => 'f18', 'value' => 'Mumbai'],
                    ['op' => 'eq', 'field' => 'f18', 'value' => 'Pune'],
                ],
            ],
            ['op' => 'contains', 'field' => 'email', 'value' => '@gmail.com'],
        ],
    ],
    'select' => ['id', 'email', 'f18'],
    'limit' => 20,
]);

echo "=== A) flat values → count ===\n" . $a['count_sql'] . ";\n\n";
echo "=== A) flat values → select ===\n" . $a['select_sql'] . ";\n\n";
echo "=== B) AST filter → count ===\n" . $b['count_sql'] . ";\n\n";
echo "=== B) AST filter → select ===\n" . $b['select_sql'] . ";\n";
