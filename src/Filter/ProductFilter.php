<?php

declare(strict_types=1);

namespace App\Filter;

use App\Schema\SftpContact;

/**
 * Converts ESP product filter format into QueryParser AST.
 *
 * Shape (OR of AND groups):
 *   [[{type, operator, options}, ...], [...]]
 *
 * Supported types:
 *   status | attributes | segments | block_files | stats
 */
final class ProductFilter
{
    /**
     * @param list<mixed>|array<string,mixed> $groups
     * @param array<string, mixed> $ctx keys: segment_defs, block_file_glob, block_match_field, dialect
     * @return array<string, mixed>
     */
    public static function toAst(array $groups, array $ctx = []): array
    {
        if ($groups === []) {
            return ['op' => 'and', 'children' => []];
        }

        // Already an AST?
        if (isset($groups['op']) && is_string($groups['op'])) {
            return $groups;
        }

        if (!array_is_list($groups)) {
            throw new \InvalidArgumentException('product filter must be a list of AND-groups');
        }

        $orChildren = [];
        foreach ($groups as $gi => $group) {
            if (!is_array($group) || !array_is_list($group)) {
                throw new \InvalidArgumentException('product filter group[' . $gi . '] must be a list of rules');
            }
            $andChildren = [];
            foreach ($group as $ri => $rule) {
                if (!is_array($rule)) {
                    throw new \InvalidArgumentException('product filter group[' . $gi . '][' . $ri . '] must be an object');
                }
                $andChildren[] = self::ruleToAst($rule, $ctx);
            }
            $orChildren[] = ['op' => 'and', 'children' => $andChildren];
        }

        if (count($orChildren) === 1) {
            return $orChildren[0];
        }
        return ['op' => 'or', 'children' => $orChildren];
    }

    public static function isProductShape(mixed $filter): bool
    {
        if (!is_array($filter) || $filter === []) {
            return false;
        }
        if (isset($filter['op'])) {
            return false;
        }
        if (!array_is_list($filter)) {
            return false;
        }
        $first = $filter[0] ?? null;
        if (!is_array($first)) {
            return false;
        }
        // [[rules...]] or [{type,operator,...}] (single group as list of rules)
        if (array_is_list($first) || isset($first['type'], $first['operator'])) {
            return true;
        }
        return false;
    }

    /**
     * Normalize so outer is always list of groups.
     *
     * @param list<mixed> $filter
     * @return list<list<array<string,mixed>>>
     */
    public static function normalizeGroups(array $filter): array
    {
        if ($filter === []) {
            return [];
        }
        $first = $filter[0] ?? null;
        if (is_array($first) && isset($first['type'], $first['operator'])) {
            // single AND group written flat: [{...},{...}]
            /** @var list<array<string,mixed>> $filter */
            return [$filter];
        }
        /** @var list<list<array<string,mixed>>> $filter */
        return $filter;
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    private static function ruleToAst(array $rule, array $ctx): array
    {
        $type = strtolower(trim((string) ($rule['type'] ?? '')));
        $operator = strtolower(trim((string) ($rule['operator'] ?? '')));
        $options = $rule['options'] ?? [];
        if (!is_array($options)) {
            $options = [$options];
        }
        $options = array_values($options);

        return match ($type) {
            'status' => self::statusRule($operator, $options),
            'attributes' => self::attributeRule($operator, $options),
            'segments' => self::segmentRule($operator, $options, $ctx),
            'block_files' => self::blockRule($operator, $options, $ctx),
            'stats' => self::statsRule($operator, $options),
            default => throw new \InvalidArgumentException('unsupported product filter type "' . $type . '"'),
        };
    }

    /** @param list<mixed> $options */
    private static function statusRule(string $operator, array $options): array
    {
        $channel = strtolower((string) ($options[0] ?? 'email'));
        $field = match ($channel) {
            'email' => 'email_status',
            'sms' => 'sms_status',
            default => throw new \InvalidArgumentException('status channel must be email or sms'),
        };
        $datatype = 'tinyint';

        $code = match ($operator) {
            'status_active' => 1,
            'status_unsubscribed' => 2,
            'status_bounced' => 3,
            'status_spam', 'status_marked_spam' => 4,
            'status_suppressed', 'status_manually_suppressed' => 5,
            'status_unconfirmed', 'status_unverified' => 6,
            'status_equals' => (int) ($options[1] ?? 1),
            default => throw new \InvalidArgumentException('unsupported status operator "' . $operator . '"'),
        };

        return [
            'datatype' => $datatype,
            'op' => 'eq',
            'field' => $field,
            'value' => $code,
        ];
    }

    /** @param list<mixed> $options */
    private static function attributeRule(string $operator, array $options): array
    {
        $field = (string) ($options[0] ?? '');
        if ($field === '' || SftpContact::byName($field) === null) {
            throw new \InvalidArgumentException('unknown attribute field "' . $field . '"');
        }
        $attr = SftpContact::byName($field);
        assert($attr !== null);

        return match ($operator) {
            'text_attr_equals' => self::leaf('varchar', 'eq', $field, $options[1] ?? null),
            'text_attr_not_equals' => self::leaf('varchar', 'neq', $field, $options[1] ?? null),
            'text_attr_contains' => self::leaf('varchar', 'contains', $field, $options[1] ?? null),
            'text_attr_not_contains' => self::leaf('varchar', 'not_contains', $field, $options[1] ?? null),
            'text_attr_starts_with' => self::leaf('varchar', 'starts_with', $field, $options[1] ?? null),
            'text_attr_ends_with' => self::leaf('varchar', 'ends_with', $field, $options[1] ?? null),
            'text_attr_is_empty', 'text_attr_is_null' => ['datatype' => 'varchar', 'op' => 'is_null', 'field' => $field],
            'text_attr_is_not_empty', 'text_attr_is_not_null' => ['datatype' => 'varchar', 'op' => 'not_null', 'field' => $field],
            'text_attr_in' => ['datatype' => 'varchar', 'op' => 'in', 'field' => $field, 'values' => array_slice($options, 1)],
            'text_attr_not_in' => ['datatype' => 'varchar', 'op' => 'not_in', 'field' => $field, 'values' => array_slice($options, 1)],

            'number_attr_equals' => self::leaf(self::numberType($attr['type']), 'eq', $field, $options[1] ?? null),
            'number_attr_not_equals' => self::leaf(self::numberType($attr['type']), 'neq', $field, $options[1] ?? null),
            'number_attr_gt' => self::leaf(self::numberType($attr['type']), 'gt', $field, $options[1] ?? null),
            'number_attr_gte' => self::leaf(self::numberType($attr['type']), 'gte', $field, $options[1] ?? null),
            'number_attr_lt' => self::leaf(self::numberType($attr['type']), 'lt', $field, $options[1] ?? null),
            'number_attr_lte' => self::leaf(self::numberType($attr['type']), 'lte', $field, $options[1] ?? null),
            'number_attr_between' => [
                'datatype' => self::numberType($attr['type']),
                'op' => 'between',
                'field' => $field,
                'values' => [($options[1] ?? null), ($options[2] ?? null)],
            ],
            'number_attr_not_between' => [
                'datatype' => self::numberType($attr['type']),
                'op' => 'not_between',
                'field' => $field,
                'values' => [($options[1] ?? null), ($options[2] ?? null)],
            ],

            'date_attr_equals' => self::leaf('date', 'eq', $field, $options[1] ?? null),
            'date_attr_gt' => self::leaf('date', 'gt', $field, $options[1] ?? null),
            'date_attr_gte' => self::leaf('date', 'gte', $field, $options[1] ?? null),
            'date_attr_lt' => self::leaf('date', 'lt', $field, $options[1] ?? null),
            'date_attr_lte' => self::leaf('date', 'lte', $field, $options[1] ?? null),
            'date_attr_between' => [
                'datatype' => 'date',
                'op' => 'between',
                'field' => $field,
                'values' => [($options[1] ?? null), ($options[2] ?? null)],
            ],

            default => throw new \InvalidArgumentException('unsupported attribute operator "' . $operator . '"'),
        };
    }

    /**
     * @param list<mixed> $options
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    private static function segmentRule(string $operator, array $options, array $ctx): array
    {
        $ids = array_map(static fn (mixed $v): string => (string) $v, $options);
        $defs = $ctx['segment_defs'] ?? [];
        if (!is_array($defs)) {
            throw new \InvalidArgumentException('segment_defs must be an object map of segment_id → product filter');
        }

        $parts = [];
        foreach ($ids as $id) {
            if (!isset($defs[$id]) && !isset($defs[(int) $id])) {
                throw new \InvalidArgumentException(
                    'segment_defs missing definition for segment_id ' . $id
                    . ' (required for in_any_segments / out_of_any_segments)'
                );
            }
            $raw = $defs[$id] ?? $defs[(int) $id];
            if (!is_array($raw)) {
                throw new \InvalidArgumentException('segment_defs[' . $id . '] must be a product filter array');
            }
            $parts[] = self::toAst(self::normalizeGroups($raw), $ctx);
        }

        $combined = count($parts) === 1
            ? $parts[0]
            : ['op' => 'or', 'children' => $parts];

        return match ($operator) {
            'in_any_segments', 'in_segments' => $combined,
            'in_all_segments' => ['op' => 'and', 'children' => $parts],
            'out_of_any_segments', 'not_in_any_segments' => ['op' => 'not', 'children' => [$combined]],
            'out_of_all_segments' => ['op' => 'not', 'children' => [['op' => 'and', 'children' => $parts]]],
            default => throw new \InvalidArgumentException('unsupported segments operator "' . $operator . '"'),
        };
    }

    /**
     * @param list<mixed> $options
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    private static function blockRule(string $operator, array $options, array $ctx): array
    {
        $ids = [];
        foreach ($options as $v) {
            if (!is_numeric($v)) {
                throw new \InvalidArgumentException('block_file id must be numeric');
            }
            $ids[] = (string) (int) $v;
        }
        if ($ids === []) {
            throw new \InvalidArgumentException('block_files options cannot be empty');
        }

        $glob = (string) ($ctx['block_file_glob'] ?? '');
        if ($glob === '') {
            throw new \InvalidArgumentException('block_file_glob is required when using block_files filters');
        }
        $matchField = (string) ($ctx['block_match_field'] ?? 'f2');
        if (SftpContact::byName($matchField) === null) {
            throw new \InvalidArgumentException('invalid block_match_field "' . $matchField . '"');
        }
        $dialect = (string) ($ctx['dialect'] ?? 'duckdb');
        $col = $dialect === 'duckdb'
            ? '"' . str_replace('"', '""', $matchField) . '"'
            : '`' . str_replace('`', '``', $matchField) . '`';
        $globSql = str_replace("'", "''", $glob);
        $list = implode(', ', $ids);
        $exists = 'EXISTS (SELECT 1 FROM read_parquet(\'' . $globSql . '\') AS bfd'
            . ' WHERE bfd.block_file_id IN (' . $list . ')'
            . ' AND bfd.unique_identifier = ' . $col . ')';

        return match ($operator) {
            'in_blockfiles', 'in_any_blockfiles' => ['op' => 'raw', 'sql' => $exists],
            'out_of_any_blockfiles', 'not_in_blockfiles', 'out_of_blockfiles' => ['op' => 'raw', 'sql' => 'NOT ' . $exists],
            default => throw new \InvalidArgumentException('unsupported block_files operator "' . $operator . '"'),
        };
    }

    /** @param list<mixed> $options */
    private static function statsRule(string $operator, array $options): array
    {
        $n = (int) ($options[0] ?? 0);
        $unit = strtolower((string) ($options[1] ?? 'days'));
        if ($n < 0) {
            throw new \InvalidArgumentException('stats window must be >= 0');
        }
        $seconds = match ($unit) {
            'day', 'days' => $n * 86400,
            'hour', 'hours' => $n * 3600,
            'minute', 'minutes' => $n * 60,
            default => throw new \InvalidArgumentException('stats unit must be days|hours|minutes'),
        };

        // last_emailed is unix epoch seconds; 0 / null = never emailed
        return match ($operator) {
            'stats_email_campaigns_not_sent' => [
                'op' => 'raw',
                'sql' => '("last_emailed" IS NULL OR "last_emailed" = 0 OR "last_emailed" < (epoch(now())::BIGINT - ' . $seconds . '))',
            ],
            'stats_email_campaigns_sent' => [
                'op' => 'raw',
                'sql' => '("last_emailed" IS NOT NULL AND "last_emailed" > 0 AND "last_emailed" >= (epoch(now())::BIGINT - ' . $seconds . '))',
            ],
            'stats_sms_campaigns_not_sent' => [
                'op' => 'raw',
                'sql' => '("last_sms" IS NULL OR "last_sms" = 0 OR "last_sms" < (epoch(now())::BIGINT - ' . $seconds . '))',
            ],
            'stats_sms_campaigns_sent' => [
                'op' => 'raw',
                'sql' => '("last_sms" IS NOT NULL AND "last_sms" > 0 AND "last_sms" >= (epoch(now())::BIGINT - ' . $seconds . '))',
            ],
            default => throw new \InvalidArgumentException('unsupported stats operator "' . $operator . '"'),
        };
    }

    private static function numberType(string $schemaType): string
    {
        return match ($schemaType) {
            'tinyint' => 'tinyint',
            'bigint' => 'bigint',
            'double' => 'double',
            default => 'integer',
        };
    }

    private static function leaf(string $datatype, string $op, string $field, mixed $value): array
    {
        return [
            'datatype' => $datatype,
            'op' => $op,
            'field' => $field,
            'value' => $value,
        ];
    }
}
