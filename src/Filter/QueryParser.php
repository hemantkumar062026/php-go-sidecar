<?php

declare(strict_types=1);

namespace App\Filter;

use App\Schema\SftpContact;

/**
 * Builds safe COUNT / SELECT SQL for sftp_contact_{account_id} from values or a filter AST.
 *
 * Input shapes:
 *
 *  A) Flat values (all ANDed as eq):
 *     ['account_id' => 42, 'values' => ['email_status' => 1, 'f18' => 'Mumbai']]
 *
 *  B) Filter AST (and/or/not + leaf ops):
 *     ['account_id' => 42, 'filter' => ['op'=>'and','children'=>[...]]]
 *
 *  C) Product ESP filter (OR of AND groups):
 *     ['account_id' => 42, 'filter' => [[{type,operator,options}, ...]]]
 *     Optional: segment_defs, block_file_glob, block_match_field
 *
 * Optional: select, limit, offset, order_by, order_dir, dialect (mariadb|duckdb),
 *           from (override table/parquet expr), include_deleted (bool, default false).
 */
final class QueryParser
{
    private const MAX_SELECT = 40;
    private const MAX_LIMIT = 10000;

    /**
     * @param array<string, mixed> $input
     * @return array{
     *   account_id:int,
     *   table:string,
     *   where:string,
     *   select_sql:string,
     *   count_sql:string,
     *   columns:list<string>,
     *   dialect:string,
     *   filter:array<string,mixed>
     * }
     */
    public static function parse(array $input): array
    {
        $accountId = (int) ($input['account_id'] ?? 0);
        if ($accountId <= 0) {
            throw new \InvalidArgumentException('account_id is required and must be > 0');
        }

        $dialect = strtolower(trim((string) ($input['dialect'] ?? 'mariadb')));
        if (!in_array($dialect, ['mariadb', 'duckdb'], true)) {
            throw new \InvalidArgumentException('dialect must be mariadb or duckdb');
        }

        $filter = self::normalizeFilter($input);
        self::validateFilter($filter);

        $whereParts = [
            self::quoteIdent('account_id', $dialect) . ' = ' . $accountId,
        ];
        if (!($input['include_deleted'] ?? false)) {
            $whereParts[] = self::quoteIdent('is_deleted', $dialect) . ' = 0';
        }
        $userWhere = self::filterToSql($filter, $dialect);
        if ($userWhere !== '1=1') {
            $whereParts[] = '(' . $userWhere . ')';
        }
        $where = implode(' AND ', $whereParts);

        $columns = self::resolveSelect($input['select'] ?? null);
        $from = self::resolveFrom($accountId, $input, $dialect);
        $order = self::resolveOrder($input, $dialect);
        $limit = self::resolveLimit($input);

        $colSql = implode(', ', array_map(
            static fn (string $c): string => self::quoteIdent($c, $dialect),
            $columns
        ));

        $selectSql = 'SELECT ' . $colSql . ' FROM ' . $from . ' WHERE ' . $where;
        if ($order !== '') {
            $selectSql .= ' ' . $order;
        }
        if ($limit['limit'] !== null) {
            $selectSql .= ' LIMIT ' . $limit['limit'];
            if ($limit['offset'] > 0) {
                $selectSql .= ' OFFSET ' . $limit['offset'];
            }
        }

        $countSql = 'SELECT COUNT(*) AS cnt FROM ' . $from . ' WHERE ' . $where;

        $baseWhereParts = [
            self::quoteIdent('account_id', $dialect) . ' = ' . $accountId,
        ];
        if (!($input['include_deleted'] ?? false)) {
            $baseWhereParts[] = self::quoteIdent('is_deleted', $dialect) . ' = 0';
        }
        $baseWhere = implode(' AND ', $baseWhereParts);

        return [
            'account_id' => $accountId,
            'table' => SftpContact::tableName($accountId),
            'from' => $from,
            'base_where' => $baseWhere,
            'user_where' => $userWhere,
            'where' => $where,
            'select_sql' => $selectSql,
            'count_sql' => $countSql,
            'columns' => $columns,
            'dialect' => $dialect,
            'filter' => $filter,
        ];
    }

    /**
     * Flat map → AND of eq leaves; or pass-through AST under filter.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private static function normalizeFilter(array $input): array
    {
        if (isset($input['filter']) && is_array($input['filter'])) {
            $filter = $input['filter'];
            if (ProductFilter::isProductShape($filter)) {
                $ctx = [
                    'segment_defs' => $input['segment_defs'] ?? [],
                    'block_file_glob' => (string) ($input['block_file_glob'] ?? ''),
                    'block_table_glob' => (string) ($input['block_table_glob'] ?? ''),
                    'block_match_field' => (string) ($input['block_match_field'] ?? 'f2'),
                    'dialect' => strtolower(trim((string) ($input['dialect'] ?? 'mariadb'))),
                ];
                return ProductFilter::toAst(ProductFilter::normalizeGroups($filter), $ctx);
            }
            return $filter;
        }

        $values = $input['values'] ?? null;
        if (!is_array($values)) {
            return ['op' => 'and', 'children' => []];
        }

        $children = [];
        foreach ($values as $field => $value) {
            $field = (string) $field;
            if ($field === 'account_id') {
                continue; // always applied from top-level account_id
            }
            if (is_array($value) && array_is_list($value)) {
                $children[] = ['op' => 'in', 'field' => $field, 'values' => $value];
                continue;
            }
            if (is_array($value) && isset($value['op'])) {
                // allow ['op'=>'gte','value'=>10] per field
                $leaf = $value;
                $leaf['field'] = $field;
                $children[] = $leaf;
                continue;
            }
            $children[] = ['op' => 'eq', 'field' => $field, 'value' => $value];
        }

        return ['op' => 'and', 'children' => $children];
    }

    /** @param array<string, mixed> $node */
    private static function validateFilter(array $node): void
    {
        $op = strtolower(trim((string) ($node['op'] ?? '')));
        if ($op === '') {
            throw new \InvalidArgumentException('empty op');
        }
        if ($op === 'and' || $op === 'or') {
            foreach (self::children($node) as $i => $child) {
                try {
                    self::validateFilter($child);
                } catch (\InvalidArgumentException $e) {
                    throw new \InvalidArgumentException($op . '[' . $i . ']: ' . $e->getMessage(), 0, $e);
                }
            }
            return;
        }
        if ($op === 'not') {
            $children = self::children($node);
            if (count($children) !== 1) {
                throw new \InvalidArgumentException('not requires exactly 1 child');
            }
            self::validateFilter($children[0]);
            return;
        }
        if ($op === 'raw') {
            $sql = trim((string) ($node['sql'] ?? ''));
            if ($sql === '') {
                throw new \InvalidArgumentException('raw op requires non-empty sql');
            }
            return;
        }
        self::leafSql($node, 'mariadb'); // validates field/op/values
    }

    /** @param array<string, mixed> $node */
    private static function filterToSql(array $node, string $dialect): string
    {
        $op = strtolower(trim((string) ($node['op'] ?? '')));
        return match ($op) {
            'and', 'or' => self::combine($op, $node, $dialect),
            'not' => 'NOT (' . self::filterToSql(self::children($node)[0], $dialect) . ')',
            'raw' => '(' . trim((string) ($node['sql'] ?? '')) . ')',
            'eq', 'neq', 'gt', 'gte', 'lt', 'lte',
            'contains', 'not_contains', 'starts_with', 'ends_with',
            'in', 'not_in', 'is_null', 'not_null', 'between', 'not_between' => self::leafSql($node, $dialect),
            '' => '1=1',
            default => throw new \InvalidArgumentException('unsupported op "' . $op . '"'),
        };
    }

    /** @param array<string, mixed> $node */
    private static function combine(string $op, array $node, string $dialect): string
    {
        $children = self::children($node);
        if ($children === []) {
            return '1=1';
        }
        $parts = [];
        foreach ($children as $child) {
            $parts[] = '(' . self::filterToSql($child, $dialect) . ')';
        }
        return implode(' ' . strtoupper($op) . ' ', $parts);
    }

    /** @param array<string, mixed> $node */
    private static function leafSql(array $node, string $dialect): string
    {
        $field = (string) ($node['field'] ?? '');
        $attr = SftpContact::byName($field);
        if ($attr === null || !$attr['filterable']) {
            throw new \InvalidArgumentException('field "' . $field . '" is not filterable on sftp_contact');
        }
        $col = self::quoteIdent($attr['name'], $dialect);
        $op = strtolower((string) ($node['op'] ?? ''));
        $type = self::resolveDatatype($node, $attr['type']);
        $like = $dialect === 'mariadb' ? 'LIKE' : 'ILIKE';
        $expr = self::typedExpr($col, $type);

        return match ($op) {
            'is_null' => $col . ' IS NULL',
            'not_null' => $col . ' IS NOT NULL',
            'contains' => 'CAST(' . $col . ' AS CHAR) ' . $like . ' ' . self::quote('%' . self::escapeLike(self::asString($node['value'] ?? null)) . '%'),
            'not_contains' => '(' . $col . ' IS NULL OR CAST(' . $col . ' AS CHAR) NOT ' . $like . ' ' . self::quote('%' . self::escapeLike(self::asString($node['value'] ?? null)) . '%') . ')',
            'starts_with' => 'CAST(' . $col . ' AS CHAR) ' . $like . ' ' . self::quote(self::escapeLike(self::asString($node['value'] ?? null)) . '%'),
            'ends_with' => 'CAST(' . $col . ' AS CHAR) ' . $like . ' ' . self::quote('%' . self::escapeLike(self::asString($node['value'] ?? null))),
            'in' => self::inList($expr, $type, self::inValues($node), false),
            'not_in' => self::inList($expr, $type, self::inValues($node), true),
            'between', 'not_between' => self::between($expr, $type, self::inValues($node), $op === 'not_between'),
            'eq', 'neq', 'gt', 'gte', 'lt', 'lte' => self::compare($expr, $type, $op, $node['value'] ?? null),
            default => throw new \InvalidArgumentException('unsupported leaf op "' . $op . '"'),
        };
    }

    /** Cast column when filtering as date/timestamp (varchar date fields like f7/f9). */
    private static function typedExpr(string $col, string $type): string
    {
        return match ($type) {
            'date' => 'CAST(' . $col . ' AS DATE)',
            'timestamp' => 'CAST(' . $col . ' AS TIMESTAMP)',
            default => $col,
        };
    }

    /**
     * Prefer explicit node "datatype" (or "type"); must be a known scalar type.
     *
     * @param array<string, mixed> $node
     */
    private static function resolveDatatype(array $node, string $schemaType): string
    {
        $raw = $node['datatype'] ?? $node['type'] ?? null;
        if ($raw === null || $raw === '') {
            throw new \InvalidArgumentException(
                'leaf filter requires "datatype" before "op" (field "' . (string) ($node['field'] ?? '') . '")'
            );
        }
        $type = strtolower(trim((string) $raw));
        // aliases
        if ($type === 'int' || $type === 'int32') {
            $type = 'integer';
        }
        if ($type === 'datetime') {
            $type = 'timestamp';
        }
        $allowed = ['tinyint', 'integer', 'bigint', 'double', 'varchar', 'date', 'timestamp', 'blob'];
        if (!in_array($type, $allowed, true)) {
            throw new \InvalidArgumentException('unsupported datatype "' . $type . '"');
        }
        if ($type === 'blob' || $schemaType === 'blob') {
            throw new \InvalidArgumentException('blob datatype is not allowed in filters');
        }
        return $type;
    }

    /** @param list<mixed> $vals */
    private static function inList(string $col, string $type, array $vals, bool $negate): string
    {
        if ($vals === []) {
            return $negate ? '1=1' : '1=0';
        }
        $list = implode(', ', array_map(static fn (mixed $v): string => self::literal($type, $v), $vals));
        if ($negate) {
            return '(' . $col . ' IS NULL OR ' . $col . ' NOT IN (' . $list . '))';
        }
        return $col . ' IN (' . $list . ')';
    }

    /** @param list<mixed> $vals */
    private static function between(string $col, string $type, array $vals, bool $negate): string
    {
        if (count($vals) !== 2) {
            throw new \InvalidArgumentException(($negate ? 'not_between' : 'between') . ' requires exactly 2 values');
        }
        $lo = self::literal($type, $vals[0]);
        $hi = self::literal($type, $vals[1]);
        if ($negate) {
            return '(' . $col . ' IS NULL OR ' . $col . ' NOT BETWEEN ' . $lo . ' AND ' . $hi . ')';
        }
        return $col . ' BETWEEN ' . $lo . ' AND ' . $hi;
    }

    private static function compare(string $col, string $type, string $op, mixed $value): string
    {
        if ($value === null && $op === 'eq') {
            return $col . ' IS NULL';
        }
        if ($value === null && $op === 'neq') {
            return $col . ' IS NOT NULL';
        }
        $sqlOp = ['eq' => '=', 'neq' => '<>', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='][$op];
        return $col . ' ' . $sqlOp . ' ' . self::literal($type, $value);
    }

    /**
     * @param array<string, mixed> $input
     * @return list<string>
     */
    private static function resolveSelect(mixed $select): array
    {
        if ($select === null || $select === '*' || $select === '') {
            return ['id', 'account_id', 'primary_key', 'email', 'mobile', 'email_status', 'domain'];
        }
        if (!is_array($select)) {
            throw new \InvalidArgumentException('select must be a list of column names');
        }
        if (count($select) > self::MAX_SELECT) {
            throw new \InvalidArgumentException('select supports at most ' . self::MAX_SELECT . ' columns');
        }
        $out = [];
        foreach ($select as $col) {
            $name = (string) $col;
            if (SftpContact::byName($name) === null) {
                throw new \InvalidArgumentException('unknown select column "' . $name . '"');
            }
            $out[] = $name;
        }
        if ($out === []) {
            throw new \InvalidArgumentException('select cannot be empty');
        }
        return $out;
    }

    /** @param array<string, mixed> $input */
    private static function resolveFrom(int $accountId, array $input, string $dialect): string
    {
        if (isset($input['from']) && is_string($input['from']) && trim($input['from']) !== '') {
            // trusted override (e.g. read_parquet(...)) — caller responsibility
            return trim($input['from']);
        }
        if ($dialect === 'duckdb') {
            // default lake-style view name; override with from= for parquet glob
            return 'contact';
        }
        $schema = (string) ($input['schema'] ?? 'data_db');
        return SftpContact::qualifiedTable($accountId, $schema);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{limit:?int,offset:int}
     */
    private static function resolveLimit(array $input): array
    {
        if (!array_key_exists('limit', $input) || $input['limit'] === null) {
            return ['limit' => null, 'offset' => 0];
        }
        $limit = (int) $input['limit'];
        if ($limit < 0 || $limit > self::MAX_LIMIT) {
            throw new \InvalidArgumentException('limit must be 0..' . self::MAX_LIMIT);
        }
        $offset = max(0, (int) ($input['offset'] ?? 0));
        return ['limit' => $limit, 'offset' => $offset];
    }

    /** @param array<string, mixed> $input */
    private static function resolveOrder(array $input, string $dialect): string
    {
        $by = $input['order_by'] ?? null;
        if ($by === null || $by === '') {
            return '';
        }
        $by = (string) $by;
        if (SftpContact::byName($by) === null) {
            throw new \InvalidArgumentException('invalid order_by "' . $by . '"');
        }
        $dir = strtoupper((string) ($input['order_dir'] ?? 'ASC'));
        if ($dir !== 'ASC' && $dir !== 'DESC') {
            throw new \InvalidArgumentException('order_dir must be ASC or DESC');
        }
        return 'ORDER BY ' . self::quoteIdent($by, $dialect) . ' ' . $dir;
    }

    /**
     * @param array<string, mixed> $node
     * @return list<array<string, mixed>>
     */
    private static function children(array $node): array
    {
        $children = $node['children'] ?? [];
        if (!is_array($children)) {
            return [];
        }
        $out = [];
        foreach ($children as $child) {
            if (is_array($child)) {
                $out[] = $child;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $node
     * @return list<mixed>
     */
    private static function inValues(array $node): array
    {
        if (isset($node['values']) && is_array($node['values']) && $node['values'] !== []) {
            return array_values($node['values']);
        }
        if (isset($node['value']) && is_array($node['value'])) {
            return array_values($node['value']);
        }
        return [];
    }

    private static function literal(string $type, mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if ($type === 'blob') {
            throw new \InvalidArgumentException('blob literals are not supported in filters');
        }
        if (in_array($type, ['tinyint', 'integer', 'bigint', 'double'], true)) {
            if (is_bool($value)) {
                return $value ? '1' : '0';
            }
            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
            $text = self::asString($value);
            if ($text === '' || !is_numeric($text)) {
                throw new \InvalidArgumentException('invalid number "' . $text . '"');
            }
            return $text;
        }
        if ($type === 'date') {
            $text = self::asString($value);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) !== 1) {
                throw new \InvalidArgumentException('date must be YYYY-MM-DD, got "' . $text . '"');
            }
            return 'DATE ' . self::quote($text);
        }
        if ($type === 'timestamp') {
            $text = self::asString($value);
            // accept YYYY-MM-DD or YYYY-MM-DD HH:MM:SS / ISO
            if (preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}:\d{2})?/', $text) !== 1) {
                throw new \InvalidArgumentException('invalid timestamp "' . $text . '"');
            }
            return 'TIMESTAMP ' . self::quote(str_replace('T', ' ', $text));
        }
        return self::quote(self::asString($value));
    }

    private static function asString(mixed $value): string
    {
        if ($value === null) {
            throw new \InvalidArgumentException('nil value');
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            return $value;
        }
        return (string) json_encode($value);
    }

    private static function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    private static function quoteIdent(string $name, string $dialect): string
    {
        if ($dialect === 'duckdb') {
            return '"' . str_replace('"', '""', $name) . '"';
        }
        return '`' . str_replace('`', '``', $name) . '`';
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
