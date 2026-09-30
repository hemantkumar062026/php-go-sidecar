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
        $campaign = self::buildCampaignProduct($body);
        $segmentDefs = $body['segment_defs'] ?? ($baseInput['segment_defs'] ?? []);
        if (!is_array($segmentDefs)) {
            $segmentDefs = [];
        }

        $includeSeg = self::intList($body['include_segments'] ?? []);
        $excludeSeg = self::intList($body['exclude_segments'] ?? []);
        $includeBf = self::intList($body['include_block_files'] ?? []);
        $excludeBf = self::intList($body['exclude_block_files'] ?? []);
        $excludeBt = self::intList($body['exclude_block_table_ids'] ?? []);

        self::assertSegmentDefs(array_merge($includeSeg, $excludeSeg), $segmentDefs);

        $sqlMap = [];

        $sqlMap['active_email'] = self::distinctCountSql($baseInput, [
            [['type' => 'status', 'operator' => 'status_active', 'options' => ['email']]],
        ], $segmentDefs);

        if ($includeSeg !== []) {
            $sqlMap['segment_union'] = self::distinctCountSql($baseInput, [[
                ['type' => 'status', 'operator' => 'status_active', 'options' => ['email']],
                ['type' => 'segments', 'operator' => 'in_any_segments', 'options' => $includeSeg],
            ]], $segmentDefs);
            foreach ($includeSeg as $sid) {
                $sqlMap['segment_part_' . $sid] = self::distinctCountSql($baseInput, [[
                    ['type' => 'status', 'operator' => 'status_active', 'options' => ['email']],
                    ['type' => 'segments', 'operator' => 'in_any_segments', 'options' => [$sid]],
                ]], $segmentDefs);
            }
        }

        if ($excludeSeg !== []) {
            $sqlMap['exclude_segments'] = self::distinctCountSql($baseInput, [[
                ['type' => 'status', 'operator' => 'status_active', 'options' => ['email']],
                ['type' => 'segments', 'operator' => 'in_any_segments', 'options' => $excludeSeg],
            ]], $segmentDefs);
        }

        if ($excludeBf !== []) {
            $sqlMap['exclude_block_files'] = self::distinctCountSql($baseInput, [[
                ['type' => 'status', 'operator' => 'status_active', 'options' => ['email']],
                ['type' => 'block_files', 'operator' => 'in_blockfiles', 'options' => $excludeBf],
            ]], $segmentDefs);
        }

        if ($excludeBt !== []) {
            $sqlMap['exclude_block_table'] = self::distinctCountSql($baseInput, [[
                ['type' => 'status', 'operator' => 'status_active', 'options' => ['email']],
                ['type' => 'block_table', 'operator' => 'in_block_table', 'options' => $excludeBt],
            ]], $segmentDefs);
        }

        $includeBaseGroups = self::buildIncludeBaseProduct($body);
        $sqlMap['include_base'] = self::distinctCountSql($baseInput, $includeBaseGroups, $segmentDefs);
        $sqlMap['final_target'] = self::distinctCountSql($baseInput, $campaign, $segmentDefs);

        $metrics = [];
        foreach ($sqlMap as $name => $sql) {
            $rows = $duck->query($sql);
            $metrics[$name] = (int) ($rows[0]['cnt'] ?? 0);
        }

        $segmentParts = [];
        foreach ($includeSeg as $sid) {
            $key = 'segment_part_' . $sid;
            $segmentParts[(string) $sid] = (int) ($metrics[$key] ?? 0);
            unset($metrics[$key]);
            unset($sqlMap[$key]);
        }

        $includeBase = (int) ($metrics['include_base'] ?? 0);
        $final = (int) ($metrics['final_target'] ?? 0);
        $metrics['excluded_from_include'] = max(0, $includeBase - $final);
        $metrics['segment_parts'] = $segmentParts;

        return [
            'campaign' => $campaign,
            'metrics' => $metrics,
            'sql' => $sqlMap,
        ];
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
}
