<?php

declare(strict_types=1);

namespace App\Filter;

/**
 * Builds distinct-email COUNT metrics for the campaign audience UI.
 */
final class CampaignPreview
{
    /**
     * @param array<string, mixed> $body UI request
     * @param array<string, mixed> $baseInput from Http::sftpParseInput (paths, dialect, segment_defs, …)
     * @return array{
     *   campaign:list<list<array<string,mixed>>>,
     *   metrics:array<string,int|array<string,int>>,
     *   sql:array<string,string>
     * }
     */
    public static function run(array $body, array $baseInput, \App\Duck\Session $duck): array
    {
        $segmentDefs = $body['segment_defs'] ?? ($baseInput['segment_defs'] ?? []);
        if (!is_array($segmentDefs)) {
            $segmentDefs = [];
        }

        $includeSeg = self::intList($body['include_segments'] ?? []);
        $excludeSeg = self::intList($body['exclude_segments'] ?? []);
        $includeBf = self::intList($body['include_block_files'] ?? []);
        $excludeBf = self::intList($body['exclude_block_files'] ?? []);
        $excludeBt = self::intList($body['exclude_block_table_ids'] ?? []);
        $catalogSeg = self::intList($body['catalog_segments'] ?? []);
        $catalogBf = self::intList($body['catalog_block_files'] ?? []);

        // Also catalog every id present in segment_defs so Available segments can show counts.
        foreach (array_keys($segmentDefs) as $defKey) {
            if (is_numeric($defKey)) {
                $catalogSeg[] = (int) $defKey;
            }
        }
        $catalogSeg = array_values(array_unique($catalogSeg));
        $catalogBf = array_values(array_unique(array_merge($catalogBf, $includeBf, $excludeBf)));

        self::assertSegmentDefs(array_merge($includeSeg, $excludeSeg, $catalogSeg), $segmentDefs);

        $singleQuery = self::boolFlag($body['single_query'] ?? false);
        $campaign = self::buildCampaignProduct($body);
        $metricProducts = self::buildMetricProducts(
            $body,
            $campaign,
            $includeSeg,
            $excludeSeg,
            $includeBf,
            $excludeBf,
            $excludeBt,
            $catalogSeg,
            $catalogBf,
        );

        $parallel = self::boolFlag($body['parallel'] ?? false);
        $metrics = [];
        $sqlMap = [];

        if ($singleQuery) {
            $merged = self::mergedDistinctCountSql($baseInput, $metricProducts, $segmentDefs);
            $rows = $duck->query($merged);
            $row = $rows[0] ?? [];
            foreach (array_keys($metricProducts) as $name) {
                $metrics[$name] = (int) ($row[$name] ?? 0);
            }
            $sqlMap['merged'] = $merged;
            $sqlMap['final_target'] = $merged;
            $parallel = false;
        } else {
            foreach ($metricProducts as $name => $product) {
                $sqlMap[$name] = self::distinctCountSql($baseInput, $product, $segmentDefs);
            }
            $rowSets = $duck->queryMany($sqlMap, $parallel);
            foreach ($sqlMap as $name => $sql) {
                $rows = $rowSets[$name] ?? [];
                $metrics[$name] = (int) ($rows[0]['cnt'] ?? 0);
            }
        }

        $segmentParts = [];
        foreach ($catalogSeg as $sid) {
            $key = 'segment_part_' . $sid;
            $segmentParts[(string) $sid] = (int) ($metrics[$key] ?? 0);
            unset($metrics[$key]);
            unset($sqlMap[$key]);
        }

        $blockParts = [];
        foreach ($catalogBf as $bid) {
            $key = 'block_part_' . $bid;
            $blockParts[(string) $bid] = (int) ($metrics[$key] ?? 0);
            unset($metrics[$key]);
            unset($sqlMap[$key]);
        }

        $includeBase = (int) ($metrics['include_base'] ?? 0);
        $final = (int) ($metrics['final_target'] ?? 0);
        $metrics['excluded_from_include'] = max(0, $includeBase - $final);
        $metrics['segment_parts'] = $segmentParts;
        $metrics['block_file_parts'] = $blockParts;

        return [
            'campaign' => $campaign,
            'metrics' => $metrics,
            'sql' => $sqlMap,
            'parallel' => $parallel,
            'single_query' => $singleQuery,
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @param list<list<array<string,mixed>>> $campaign
     * @param list<int> $includeSeg
     * @param list<int> $excludeSeg
     * @param list<int> $includeBf
     * @param list<int> $excludeBf
     * @param list<int> $excludeBt
     * @param list<int> $catalogSeg
     * @param list<int> $catalogBf
     * @return array<string, list<list<array<string,mixed>>>>
     */
    private static function buildMetricProducts(
        array $body,
        array $campaign,
        array $includeSeg,
        array $excludeSeg,
        array $includeBf,
        array $excludeBf,
        array $excludeBt,
        array $catalogSeg,
        array $catalogBf,
    ): array {
        $products = [];
        $products['active_email'] = [
            [['type' => 'status', 'operator' => 'status_active', 'options' => ['email']]],
        ];

        if ($includeSeg !== []) {
            $products['segment_union'] = [[
                ['type' => 'status', 'operator' => 'status_active', 'options' => ['email']],
                ['type' => 'segments', 'operator' => 'in_any_segments', 'options' => $includeSeg],
            ]];
        }

        foreach ($catalogSeg as $sid) {
            $products['segment_part_' . $sid] = [[
                ['type' => 'status', 'operator' => 'status_active', 'options' => ['email']],
                ['type' => 'segments', 'operator' => 'in_any_segments', 'options' => [$sid]],
            ]];
        }

        if ($excludeSeg !== []) {
            $products['exclude_segments'] = [[
                ['type' => 'status', 'operator' => 'status_active', 'options' => ['email']],
                ['type' => 'segments', 'operator' => 'in_any_segments', 'options' => $excludeSeg],
            ]];
        }

        if ($includeBf !== []) {
            $products['include_block_files'] = [[
                ['type' => 'status', 'operator' => 'status_active', 'options' => ['email']],
                ['type' => 'block_files', 'operator' => 'in_blockfiles', 'options' => $includeBf],
            ]];
        }

        foreach ($catalogBf as $bid) {
            $products['block_part_' . $bid] = [[
                ['type' => 'status', 'operator' => 'status_active', 'options' => ['email']],
                ['type' => 'block_files', 'operator' => 'in_blockfiles', 'options' => [$bid]],
            ]];
        }

        if ($excludeBf !== []) {
            $products['exclude_block_files'] = [[
                ['type' => 'status', 'operator' => 'status_active', 'options' => ['email']],
                ['type' => 'block_files', 'operator' => 'in_blockfiles', 'options' => $excludeBf],
            ]];
        }

        if ($excludeBt !== []) {
            $products['exclude_block_table'] = [[
                ['type' => 'status', 'operator' => 'status_active', 'options' => ['email']],
                ['type' => 'block_table', 'operator' => 'in_block_table', 'options' => $excludeBt],
            ]];
        }

        $products['include_base'] = self::buildIncludeBaseProduct($body);
        $products['final_target'] = $campaign;

        return $products;
    }

    /**
     * One lake scan: COUNT(DISTINCT email) per metric via FILTER / CASE WHEN.
     *
     * Optimizations vs naive merge:
     * - CTE of active contacts (all metrics already require email_status=1)
     * - Block-file / block-table parquet read once into CTEs; rewrite repeated EXISTS
     * - COUNT(DISTINCT …) FILTER (WHERE …) for cleaner plans
     *
     * @param array<string, mixed> $baseInput
     * @param array<string, list<list<array<string,mixed>>>> $metricProducts
     * @param array<string, mixed> $segmentDefs
     */
    private static function mergedDistinctCountSql(
        array $baseInput,
        array $metricProducts,
        array $segmentDefs,
    ): string {
        $selectParts = [];
        $from = null;
        $baseWhere = null;
        $whens = [];

        foreach ($metricProducts as $name => $product) {
            $input = $baseInput;
            $input['filter'] = $product;
            $input['segment_defs'] = $segmentDefs;
            $input['select'] = ['id', 'email'];
            $parsed = QueryParser::parse($input);
            if ($from === null) {
                $from = (string) $parsed['from'];
                $baseWhere = (string) $parsed['base_where'];
            }
            $userWhere = (string) ($parsed['user_where'] ?? '1=1');
            $whens[$name] = $userWhere;
        }

        if ($whens === [] || $from === null || $baseWhere === null) {
            throw new \RuntimeException('merged count SQL: no metrics');
        }

        $blockGlob = (string) ($baseInput['block_file_glob'] ?? '');
        $blockTableGlob = (string) ($baseInput['block_table_glob'] ?? '');
        $hoisted = self::hoistSharedLookups($whens, $blockGlob, $blockTableGlob);

        $ctes = [];
        $layout = (string) ($baseInput['layout'] ?? '');
        // Flag-join is great on flat lakes; on base+delta it can OOM under 2GB.
        $useFlagJoin = $layout !== 'base_delta';

        if ($hoisted['bf_ids'] !== []) {
            $globSql = str_replace("'", "''", $blockGlob);
            $idList = implode(', ', $hoisted['bf_ids']);
            $ctes[] = '_bf AS ('
                . "SELECT block_file_id, unique_identifier FROM read_parquet('{$globSql}')"
                . " WHERE block_file_id IN ({$idList})"
                . ')';
            if ($useFlagJoin) {
                $flagSelects = ['unique_identifier'];
                foreach ($hoisted['bf_ids'] as $bid) {
                    $flagSelects[] = 'BOOL_OR(block_file_id = ' . $bid . ') AS "bf_' . $bid . '"';
                }
                $ctes[] = '_bf_flags AS (SELECT ' . implode(', ', $flagSelects) . ' FROM _bf GROUP BY unique_identifier)';
            }
        }
        if ($hoisted['bt_ids'] !== []) {
            $globSql = str_replace("'", "''", $blockTableGlob);
            $idList = implode(', ', $hoisted['bt_ids']);
            $ctes[] = '_bt AS ('
                . "SELECT block_id, email FROM read_parquet('{$globSql}')"
                . " WHERE block_id IN ({$idList})"
                . ')';
            if ($useFlagJoin) {
                $flagSelects = ['email'];
                foreach ($hoisted['bt_ids'] as $bid) {
                    $flagSelects[] = 'BOOL_OR(block_id = ' . $bid . ') AS "bt_' . $bid . '"';
                }
                $ctes[] = '_bt_flags AS (SELECT ' . implode(', ', $flagSelects) . ' FROM _bt GROUP BY email)';
            }
        }

        $matchField = (string) ($baseInput['block_match_field'] ?? 'f2');
        $matchCol = '"' . str_replace('"', '""', $matchField) . '"';

        if ($useFlagJoin) {
            $metricWhens = self::rewriteLookupsToFlags(
                $hoisted['whens'],
                $hoisted['bf_ids'],
                $hoisted['bt_ids'],
                $matchCol
            );
        } else {
            $metricWhens = $hoisted['whens'];
        }

        $neededCols = ['email' => true, $matchField => true];
        foreach ($metricWhens as $when) {
            if (preg_match_all('/"([A-Za-z_][A-Za-z0-9_]*)"/', $when, $mm) !== false) {
                foreach ($mm[1] as $col) {
                    if (str_starts_with($col, 'bf_') || str_starts_with($col, 'bt_')) {
                        continue;
                    }
                    $neededCols[$col] = true;
                }
            }
        }

        $proj = [];
        foreach (array_keys($neededCols) as $col) {
            $q = '"' . str_replace('"', '""', $col) . '"';
            $proj[] = 'c.' . $q . ' AS ' . $q;
        }
        $cSelect = implode(', ', $proj);
        $cJoins = '';
        if ($useFlagJoin && $hoisted['bf_ids'] !== []) {
            foreach ($hoisted['bf_ids'] as $bid) {
                $cSelect .= ', COALESCE(bf."bf_' . $bid . '", FALSE) AS "bf_' . $bid . '"';
            }
            $cJoins .= ' LEFT JOIN _bf_flags bf ON c.' . $matchCol . ' = bf.unique_identifier';
        }
        if ($useFlagJoin && $hoisted['bt_ids'] !== []) {
            foreach ($hoisted['bt_ids'] as $bid) {
                $cSelect .= ', COALESCE(bt."bt_' . $bid . '", FALSE) AS "bt_' . $bid . '"';
            }
            $cJoins .= ' LEFT JOIN _bt_flags bt ON c."email" = bt.email';
        }

        $baseWhereC = preg_replace('/"([A-Za-z_][A-Za-z0-9_]*)"/', 'c."$1"', $baseWhere) ?? $baseWhere;

        $ctes[] = '_c AS ('
            . 'SELECT ' . $cSelect . ' FROM ' . $from . ' AS c'
            . $cJoins
            . ' WHERE ' . $baseWhereC
            . ' AND c."email_status" = 1'
            . " AND NULLIF(TRIM(c.\"email\"), '') IS NOT NULL"
            . ')';

        foreach ($metricWhens as $name => $when) {
            $when = preg_replace('/\(\s*"email_status"\s*=\s*1\s*\)/i', 'TRUE', $when) ?? $when;
            $when = trim($when);
            $cond = ($when === '1=1' || $when === 'TRUE' || $when === '') ? 'TRUE' : '(' . $when . ')';
            $alias = '"' . str_replace('"', '""', $name) . '"';
            $selectParts[] = 'COUNT(DISTINCT NULLIF(TRIM("email"), \'\')) FILTER (WHERE ' . $cond . ') AS ' . $alias;
        }

        return 'WITH ' . implode(', ', $ctes)
            . ' SELECT ' . implode(', ', $selectParts)
            . ' FROM _c';
    }

    /**
     * @param array<string, string> $whens
     * @param list<int> $bfIds
     * @param list<int> $btIds
     * @return array<string, string>
     */
    private static function rewriteLookupsToFlags(array $whens, array $bfIds, array $btIds, string $matchCol): array
    {
        $out = [];
        foreach ($whens as $name => $sql) {
            // ("f2" IN (SELECT unique_identifier FROM _bf WHERE block_file_id IN (56)))
            // → ("bf_56")  or OR of flags for multi-id lists
            $sql = preg_replace_callback(
                '/\(\s*("[^"]+"|`[^`]+`|[A-Za-z_][A-Za-z0-9_]*)\s+IN\s*\(\s*SELECT\s+unique_identifier\s+FROM\s+_bf\s+WHERE\s+block_file_id\s+IN\s*\(([^)]+)\)\s*\)\s*\)/i',
                static function (array $m): string {
                    $ids = [];
                    foreach (preg_split('/\s*,\s*/', trim($m[2])) ?: [] as $id) {
                        if (is_numeric($id)) {
                            $ids[] = (int) $id;
                        }
                    }
                    if ($ids === []) {
                        return 'FALSE';
                    }
                    $parts = array_map(static fn (int $id): string => '"bf_' . $id . '"', $ids);
                    return count($parts) === 1 ? '(' . $parts[0] . ')' : '(' . implode(' OR ', $parts) . ')';
                },
                $sql
            );
            if (!is_string($sql)) {
                $sql = $whens[$name];
            }

            $sql = preg_replace_callback(
                '/"email"\s+IN\s*\(\s*SELECT\s+email\s+FROM\s+_bt\s+WHERE\s+block_id\s+IN\s*\(([^)]+)\)\s*\)/i',
                static function (array $m): string {
                    $ids = [];
                    foreach (preg_split('/\s*,\s*/', trim($m[1])) ?: [] as $id) {
                        if (is_numeric($id)) {
                            $ids[] = (int) $id;
                        }
                    }
                    if ($ids === []) {
                        return 'FALSE';
                    }
                    $parts = array_map(static fn (int $id): string => '"bt_' . $id . '"', $ids);
                    return count($parts) === 1 ? '(' . $parts[0] . ')' : '(' . implode(' OR ', $parts) . ')';
                },
                $sql
            );
            if (!is_string($sql)) {
                $sql = $whens[$name];
            }

            $out[$name] = $sql;
        }
        return $out;
    }

    /**
     * Rewrite per-metric EXISTS(read_parquet(block…)) into shared CTE lookups.
     *
     * @param array<string, string> $whens
     * @return array{whens:array<string,string>,bf_ids:list<int>,bt_ids:list<int>}
     */
    private static function hoistSharedLookups(array $whens, string $blockGlob, string $blockTableGlob): array
    {
        $bfIds = [];
        $btIds = [];
        $out = [];

        foreach ($whens as $name => $sql) {
            $rewritten = preg_replace_callback(
                '/EXISTS\s*\(\s*SELECT\s+1\s+FROM\s+read_parquet\(\'([^\']*)\'\)\s+AS\s+bfd\s+'
                . 'WHERE\s+bfd\.block_file_id\s+IN\s*\(([^)]+)\)\s+'
                . 'AND\s+bfd\.unique_identifier\s*=\s*("[^"]+"|`[^`]+`|[A-Za-z_][A-Za-z0-9_]*)\s*\)/i',
                static function (array $m) use (&$bfIds): string {
                    foreach (preg_split('/\s*,\s*/', trim($m[2])) ?: [] as $id) {
                        if (is_numeric($id)) {
                            $bfIds[] = (int) $id;
                        }
                    }
                    $col = $m[3];
                    return '(' . $col . ' IN (SELECT unique_identifier FROM _bf WHERE block_file_id IN (' . $m[2] . ')))';
                },
                $sql
            );
            if (!is_string($rewritten)) {
                $rewritten = $sql;
            }

            $rewritten = preg_replace_callback(
                '/"email"\s+IN\s*\(\s*SELECT\s+bt\.email\s+FROM\s+read_parquet\(\'([^\']*)\'\)\s+AS\s+bt\s+'
                . 'WHERE\s+bt\.block_id\s+IN\s*\(([^)]+)\)\s*\)/i',
                static function (array $m) use (&$btIds): string {
                    foreach (preg_split('/\s*,\s*/', trim($m[2])) ?: [] as $id) {
                        if (is_numeric($id)) {
                            $btIds[] = (int) $id;
                        }
                    }
                    return '"email" IN (SELECT email FROM _bt WHERE block_id IN (' . $m[2] . '))';
                },
                $rewritten
            );
            if (!is_string($rewritten)) {
                $rewritten = $sql;
            }

            $out[$name] = $rewritten;
        }

        $bfIds = array_values(array_unique($bfIds));
        sort($bfIds);
        $btIds = array_values(array_unique($btIds));
        sort($btIds);

        if ($blockGlob === '') {
            $bfIds = [];
        }
        if ($blockTableGlob === '') {
            $btIds = [];
        }

        return ['whens' => $out, 'bf_ids' => $bfIds, 'bt_ids' => $btIds];
    }

    /**
     * Full campaign filter: OR of (segments path) and (include block_files path).
     *
     * @param array<string, mixed> $body
     * @return list<list<array<string,mixed>>>
     */
    public static function buildCampaignProduct(array $body): array
    {
        $includeSeg = self::intList($body['include_segments'] ?? []);
        $includeBf = self::intList($body['include_block_files'] ?? []);
        $common = self::commonExcludeRules($body);
        $status = ['type' => 'status', 'operator' => 'status_active', 'options' => ['email']];
        $groups = [];

        if ($includeSeg !== []) {
            $groups[] = array_merge(
                [$status, ['type' => 'segments', 'operator' => 'in_any_segments', 'options' => $includeSeg]],
                $common
            );
        }
        if ($includeBf !== []) {
            $groups[] = array_merge(
                [$status, ['type' => 'block_files', 'operator' => 'in_blockfiles', 'options' => $includeBf]],
                $common
            );
        }
        if ($groups === []) {
            $groups[] = array_merge([$status], $common);
        }
        return $groups;
    }

    /**
     * Include base = active ∩ (segment union ∪ include blocks) — no excludes.
     *
     * @param array<string, mixed> $body
     * @return list<list<array<string,mixed>>>
     */
    private static function buildIncludeBaseProduct(array $body): array
    {
        $includeSeg = self::intList($body['include_segments'] ?? []);
        $includeBf = self::intList($body['include_block_files'] ?? []);
        $status = ['type' => 'status', 'operator' => 'status_active', 'options' => ['email']];
        $groups = [];
        if ($includeSeg !== []) {
            $groups[] = [
                $status,
                ['type' => 'segments', 'operator' => 'in_any_segments', 'options' => $includeSeg],
            ];
        }
        if ($includeBf !== []) {
            $groups[] = [
                $status,
                ['type' => 'block_files', 'operator' => 'in_blockfiles', 'options' => $includeBf],
            ];
        }
        if ($groups === []) {
            $groups[] = [$status];
        }
        return $groups;
    }

    /**
     * @param array<string, mixed> $body
     * @return list<array<string,mixed>>
     */
    private static function commonExcludeRules(array $body): array
    {
        $rules = [];
        $excludeSeg = self::intList($body['exclude_segments'] ?? []);
        $excludeBf = self::intList($body['exclude_block_files'] ?? []);
        $excludeBt = self::intList($body['exclude_block_table_ids'] ?? []);
        $days = (int) ($body['stats_not_sent_days'] ?? 0);

        if ($excludeSeg !== []) {
            $rules[] = ['type' => 'segments', 'operator' => 'out_of_any_segments', 'options' => $excludeSeg];
        }
        if ($excludeBf !== []) {
            $rules[] = ['type' => 'block_files', 'operator' => 'out_of_any_blockfiles', 'options' => $excludeBf];
        }
        if ($excludeBt !== []) {
            $rules[] = ['type' => 'block_table', 'operator' => 'out_of_block_table', 'options' => $excludeBt];
        }
        if ($days > 0) {
            $rules[] = ['type' => 'stats', 'operator' => 'stats_email_campaigns_not_sent', 'options' => [$days, 'days']];
        }
        return $rules;
    }

    /**
     * @param array<string, mixed> $baseInput
     * @param list<list<array<string,mixed>>> $product
     * @param array<string, mixed> $segmentDefs
     */
    private static function distinctCountSql(array $baseInput, array $product, array $segmentDefs): string
    {
        $input = $baseInput;
        $input['filter'] = $product;
        $input['segment_defs'] = $segmentDefs;
        $input['select'] = ['id', 'email'];
        $input['limit'] = 1;
        $parsed = QueryParser::parse($input);
        $countSql = $parsed['count_sql'];
        $replaced = preg_replace(
            '/^SELECT COUNT\(\*\) AS cnt/i',
            'SELECT COUNT(DISTINCT NULLIF(TRIM("email"), \'\')) AS cnt',
            $countSql,
            1
        );
        return is_string($replaced) ? $replaced : $countSql;
    }

    /**
     * @param list<int> $segmentIds
     * @param array<string, mixed> $segmentDefs
     */
    private static function assertSegmentDefs(array $segmentIds, array $segmentDefs): void
    {
        $missing = [];
        foreach ($segmentIds as $id) {
            $key = (string) $id;
            if (!isset($segmentDefs[$key]) && !isset($segmentDefs[$id])) {
                $missing[] = $key;
            }
        }
        $missing = array_values(array_unique($missing));
        if ($missing === []) {
            return;
        }
        $known = array_keys($segmentDefs);
        sort($known);
        throw new \InvalidArgumentException(
            'segment_defs missing definition for segment_id '
            . implode(', ', $missing)
            . ' (required for in_any_segments / out_of_any_segments). '
            . 'Known segment_defs: '
            . ($known === [] ? '(none)' : implode(', ', $known))
            . '. Either add JSON under segment_defs["'
            . $missing[0]
            . '"] or remove that id from include_segments / exclude_segments.'
        );
    }

    /**
     * @param mixed $raw
     * @return list<int>
     */
    private static function intList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $v) {
            if (is_numeric($v)) {
                $out[] = (int) $v;
            }
        }
        return array_values(array_unique($out));
    }

    private static function boolFlag(mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_int($raw) || is_float($raw)) {
            return ((int) $raw) !== 0;
        }
        if (is_string($raw)) {
            $v = strtolower(trim($raw));
            return in_array($v, ['1', 'true', 'yes', 'on'], true);
        }
        return false;
    }
}
