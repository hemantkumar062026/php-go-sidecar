<?php

declare(strict_types=1);

namespace App\Filter;

use App\Schema\ContactAttrs;

/** Turns a segment filter AST into a DuckDB WHERE clause. */
final class Sql
{
    /** @param array<string, mixed> $node */
    public static function toSql(array $node): string
    {
        $op = strtolower(trim((string) ($node['op'] ?? '')));
        if ($op === '') {
            return '1=1';
        }
        return match ($op) {
            'and', 'or' => self::combine($op, $node),
            'not' => self::negate($node),
            'eq', 'neq', 'gt', 'gte', 'lt', 'lte',
            'contains', 'not_contains', 'starts_with', 'ends_with',
            'in', 'not_in', 'is_null', 'not_null', 'between', 'not_between' => self::leaf($node),
            default => throw new \InvalidArgumentException('unsupported op "' . $op . '"'),
        };
    }

    /** @param array<string, mixed> $node */
    public static function validate(array $node): void
    {
        $op = strtolower(trim((string) ($node['op'] ?? '')));
        if ($op === '') {
            throw new \InvalidArgumentException('empty op');
        }
        if ($op === 'and' || $op === 'or') {
            foreach (self::children($node) as $i => $child) {
                try {
                    self::validate($child);
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
            self::validate($children[0]);
            return;
        }
        self::leaf($node);
    }

    /** @param array<string, mixed> $node */
    private static function combine(string $op, array $node): string
    {
        $children = self::children($node);
        if ($children === []) {
            return '1=1';
        }
        $parts = [];
        foreach ($children as $child) {
            $parts[] = '(' . self::toSql($child) . ')';
        }
        return implode(' ' . strtoupper($op) . ' ', $parts);
    }

    /** @param array<string, mixed> $node */
    private static function negate(array $node): string
    {
        $children = self::children($node);
        if (count($children) !== 1) {
            throw new \InvalidArgumentException('not requires exactly 1 child');
        }
        return 'NOT (' . self::toSql($children[0]) . ')';
    }

    /** @param array<string, mixed> $node */
    private static function leaf(array $node): string
    {
        $field = (string) ($node['field'] ?? '');
        $attr = ContactAttrs::byName($field);
        if ($attr === null || !$attr['filterable']) {
            throw new \InvalidArgumentException('field "' . $field . '" is not filterable');
        }
        $col = $attr['name'];
        $op = strtolower((string) ($node['op'] ?? ''));
        $type = $attr['type'];

        return match ($op) {
            'is_null' => $col . ' IS NULL',
            'not_null' => $col . ' IS NOT NULL',
            'contains' => 'CAST(' . $col . ' AS VARCHAR) ILIKE ' . self::quote('%' . self::escapeLike(self::asString($node['value'] ?? null)) . '%') . " ESCAPE '\\'",
            'not_contains' => '(CAST(' . $col . ' AS VARCHAR) NOT ILIKE ' . self::quote('%' . self::escapeLike(self::asString($node['value'] ?? null)) . '%') . " ESCAPE '\\' OR " . $col . ' IS NULL)',
            'starts_with' => 'CAST(' . $col . ' AS VARCHAR) ILIKE ' . self::quote(self::escapeLike(self::asString($node['value'] ?? null)) . '%') . " ESCAPE '\\'",
            'ends_with' => 'CAST(' . $col . ' AS VARCHAR) ILIKE ' . self::quote('%' . self::escapeLike(self::asString($node['value'] ?? null))) . " ESCAPE '\\'",
            'in' => self::inList($col, $type, self::inValues($node), false),
            'not_in' => self::inList($col, $type, self::inValues($node), true),
            'between', 'not_between' => self::between($col, $type, self::inValues($node), $op === 'not_between'),
            'eq', 'neq', 'gt', 'gte', 'lt', 'lte' => self::compare($col, $type, $op, $node['value'] ?? null),
            default => throw new \InvalidArgumentException('unsupported leaf op "' . $op . '"'),
        };
    }

    /** @param list<mixed> $vals */
    private static function inList(string $col, string $type, array $vals, bool $negate): string
    {
        if ($vals === []) {
            return $negate ? '1=1' : '1=0';
        }
        $parts = array_map(static fn (mixed $v): string => self::literal($type, $v), $vals);
        $list = implode(', ', $parts);
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

    /** @param array<string, mixed> $node
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

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
