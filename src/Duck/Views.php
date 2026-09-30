<?php

declare(strict_types=1);

namespace App\Duck;

use App\Schema\ContactAttrs;
use App\Warehouse\Paths;

/** Snapshot ∪ delta views. Latest row wins; deletes drop out. */
final class Views
{
    public static function registerSql(Paths $wh): string
    {
        $contactUnion = self::buildUnion($wh->contactSnapshotGlob(), $wh->contactDeltaGlob(), '_contact_empty');
        $subUnion = self::buildUnion($wh->campaignSubSnapshotGlob(), $wh->campaignSubDeltaGlob(), '_campaign_sub_empty');
        $campUnion = self::buildUnion($wh->campaignMetaSnapshotGlob(), $wh->campaignMetaDeltaGlob(), '_campaign_meta_empty');
        $activitySrc = self::hasParquet($wh->campaignActivityGlob())
            ? 'SELECT * FROM ' . self::readParquet($wh->campaignActivityGlob())
            : 'SELECT * FROM _campaign_activity_empty';
        $segSrc = self::hasParquet($wh->segmentGlob())
            ? 'SELECT * FROM ' . self::readParquet($wh->segmentGlob())
            : 'SELECT * FROM _segment_empty';
        $dlrSrc = self::hasParquet($wh->dlrEventGlob())
            ? 'SELECT * FROM ' . self::readParquet($wh->dlrEventGlob())
            : 'SELECT * FROM _dlr_empty';
        $apiSrc = self::hasParquet($wh->apiLogsGlob())
            ? 'SELECT * FROM ' . self::readParquet($wh->apiLogsGlob())
            : 'SELECT * FROM _api_logs_empty';

        $contactCols = implode(', ', array_map(static fn (array $a): string => $a['name'], ContactAttrs::all()));

        return self::emptyTables() . "\n" . <<<SQL
CREATE OR REPLACE VIEW contact AS
WITH all_rows AS ({$contactUnion}),
ranked AS (
    SELECT *, ROW_NUMBER() OVER (PARTITION BY id ORDER BY _ts DESC, _seq DESC) AS _rn
    FROM all_rows
)
SELECT {$contactCols} FROM ranked WHERE _rn = 1 AND _op <> 'delete';

CREATE OR REPLACE VIEW campaign_sub AS
WITH all_rows AS ({$subUnion}),
ranked AS (
    SELECT *, ROW_NUMBER() OVER (
        PARTITION BY campaign_id, message_id ORDER BY _ts DESC, _seq DESC
    ) AS _rn FROM all_rows
)
SELECT campaign_id, primary_key, version_id, contact_id, recipient, attributes,
    message_size, sent_on, priority, queue_id, connection_id, status,
    total_open, total_click, drop_reason, soft_bounce_retry,
    total_open_amp, total_click_amp, message_id, channel, dlr_code, dlr_at
FROM ranked WHERE _rn = 1 AND _op <> 'delete';

CREATE OR REPLACE VIEW campaign_activity AS {$activitySrc};

CREATE OR REPLACE VIEW campaign AS
WITH all_rows AS ({$campUnion}),
ranked AS (
    SELECT *, ROW_NUMBER() OVER (PARTITION BY id ORDER BY _ts DESC, _seq DESC) AS _rn
    FROM all_rows
)
SELECT id, account_id, name, channel, segment_id, status, created_at, launched_at, queued_cnt
FROM ranked WHERE _rn = 1 AND _op <> 'delete';

CREATE OR REPLACE VIEW segment AS {$segSrc};

CREATE OR REPLACE VIEW dlr_event AS {$dlrSrc};

CREATE OR REPLACE VIEW api_logs AS
WITH all_rows AS ({$apiSrc}),
ranked AS (
    SELECT *, ROW_NUMBER() OVER (
        PARTITION BY message_id ORDER BY updated_at DESC, _ts DESC, _seq DESC
    ) AS _rn FROM all_rows
)
SELECT
    channel_id, message_id, request_hour, sent_ip, connection_id, mta_route_id,
    type, status, recipient, opened, clicked, is_unsubscribed, is_spammed,
    updated_at, data, recipient_domain, sender_domain, template_id, unique_arguments, dt
FROM ranked WHERE _rn = 1 AND COALESCE(_op, 'upsert') <> 'delete';
SQL;
    }

    public static function readParquet(string $glob): string
    {
        return 'read_parquet(' . Session::quote($glob) . ', hive_partitioning=false, union_by_name=true)';
    }

    public static function hasParquet(string $glob): bool
    {
        $matches = glob($glob);
        return is_array($matches) && $matches !== [];
    }

    private static function buildUnion(string $snapGlob, string $deltaGlob, string $emptyTable): string
    {
        $parts = [];
        if (self::hasParquet($snapGlob)) {
            $parts[] = 'SELECT * FROM ' . self::readParquet($snapGlob);
        }
        if (self::hasParquet($deltaGlob)) {
            $parts[] = 'SELECT * FROM ' . self::readParquet($deltaGlob);
        }
        if ($parts === []) {
            return 'SELECT * FROM ' . $emptyTable;
        }
        return implode(' UNION ALL BY NAME ', $parts);
    }

    private static function emptyTables(): string
    {
        $cols = [];
        foreach (ContactAttrs::all() as $attr) {
            $cols[] = 'CAST(NULL AS ' . ContactAttrs::duckType($attr['type']) . ') AS ' . $attr['name'];
        }
        $cols[] = 'CAST(NULL AS VARCHAR) AS _op';
        $cols[] = 'CAST(NULL AS TIMESTAMP) AS _ts';
        $cols[] = 'CAST(NULL AS BIGINT) AS _seq';
        $contact = 'CREATE OR REPLACE TEMP TABLE _contact_empty AS SELECT * FROM (SELECT '
            . implode(', ', $cols) . ' WHERE FALSE);';

        return $contact . "\n" . <<<'SQL'
CREATE OR REPLACE TEMP TABLE _campaign_sub_empty AS
SELECT * FROM (
    SELECT
        CAST(NULL AS BIGINT) AS campaign_id,
        CAST(NULL AS VARCHAR) AS primary_key,
        CAST(NULL AS BIGINT) AS version_id,
        CAST(NULL AS BIGINT) AS contact_id,
        CAST(NULL AS VARCHAR) AS recipient,
        CAST(NULL AS VARCHAR) AS attributes,
        CAST(NULL AS INTEGER) AS message_size,
        CAST(NULL AS TIMESTAMP) AS sent_on,
        CAST(NULL AS TINYINT) AS priority,
        CAST(NULL AS INTEGER) AS queue_id,
        CAST(NULL AS INTEGER) AS connection_id,
        CAST(NULL AS TINYINT) AS status,
        CAST(NULL AS TINYINT) AS total_open,
        CAST(NULL AS TINYINT) AS total_click,
        CAST(NULL AS VARCHAR) AS drop_reason,
        CAST(NULL AS TINYINT) AS soft_bounce_retry,
        CAST(NULL AS TINYINT) AS total_open_amp,
        CAST(NULL AS TINYINT) AS total_click_amp,
        CAST(NULL AS VARCHAR) AS message_id,
        CAST(NULL AS VARCHAR) AS channel,
        CAST(NULL AS VARCHAR) AS dlr_code,
        CAST(NULL AS TIMESTAMP) AS dlr_at,
        CAST(NULL AS VARCHAR) AS _op,
        CAST(NULL AS TIMESTAMP) AS _ts,
        CAST(NULL AS BIGINT) AS _seq
    WHERE FALSE
);

CREATE OR REPLACE TEMP TABLE _campaign_activity_empty AS
SELECT * FROM (
    SELECT
        CAST(NULL AS BIGINT) AS id,
        CAST(NULL AS BIGINT) AS version_id,
        CAST(NULL AS BIGINT) AS contact_id,
        CAST(NULL AS TINYINT) AS action,
        CAST(NULL AS TINYINT) AS link_id,
        CAST(NULL AS VARCHAR) AS user_agent,
        CAST(NULL AS VARCHAR) AS ip,
        CAST(NULL AS TIMESTAMP) AS created_at,
        CAST(NULL AS VARCHAR) AS device,
        CAST(NULL AS TINYINT) AS device_type,
        CAST(NULL AS VARCHAR) AS browser,
        CAST(NULL AS DATE) AS dt
    WHERE FALSE
);

CREATE OR REPLACE TEMP TABLE _campaign_meta_empty AS
SELECT * FROM (
    SELECT
        CAST(NULL AS BIGINT) AS id,
        CAST(NULL AS BIGINT) AS account_id,
        CAST(NULL AS VARCHAR) AS name,
        CAST(NULL AS VARCHAR) AS channel,
        CAST(NULL AS VARCHAR) AS segment_id,
        CAST(NULL AS VARCHAR) AS status,
        CAST(NULL AS TIMESTAMP) AS created_at,
        CAST(NULL AS TIMESTAMP) AS launched_at,
        CAST(NULL AS BIGINT) AS queued_cnt,
        CAST(NULL AS VARCHAR) AS _op,
        CAST(NULL AS TIMESTAMP) AS _ts,
        CAST(NULL AS BIGINT) AS _seq
    WHERE FALSE
);

CREATE OR REPLACE TEMP TABLE _segment_empty AS
SELECT * FROM (
    SELECT
        CAST(NULL AS VARCHAR) AS id,
        CAST(NULL AS BIGINT) AS account_id,
        CAST(NULL AS VARCHAR) AS name,
        CAST(NULL AS VARCHAR) AS filter_json,
        CAST(NULL AS TIMESTAMP) AS created_at,
        CAST(NULL AS TIMESTAMP) AS updated_at
    WHERE FALSE
);

CREATE OR REPLACE TEMP TABLE _dlr_empty AS
SELECT * FROM (
    SELECT
        CAST(NULL AS VARCHAR) AS id,
        CAST(NULL AS VARCHAR) AS message_id,
        CAST(NULL AS BIGINT) AS campaign_id,
        CAST(NULL AS VARCHAR) AS channel,
        CAST(NULL AS TINYINT) AS status,
        CAST(NULL AS VARCHAR) AS code,
        CAST(NULL AS VARCHAR) AS reason,
        CAST(NULL AS TIMESTAMP) AS created_at,
        CAST(NULL AS DATE) AS dt
    WHERE FALSE
);

CREATE OR REPLACE TEMP TABLE _api_logs_empty AS
SELECT * FROM (
    SELECT
        CAST(NULL AS INTEGER) AS channel_id,
        CAST(NULL AS VARCHAR) AS message_id,
        CAST(NULL AS TINYINT) AS request_hour,
        CAST(NULL AS VARCHAR) AS sent_ip,
        CAST(NULL AS INTEGER) AS connection_id,
        CAST(NULL AS INTEGER) AS mta_route_id,
        CAST(NULL AS TINYINT) AS type,
        CAST(NULL AS TINYINT) AS status,
        CAST(NULL AS VARCHAR) AS recipient,
        CAST(NULL AS TINYINT) AS opened,
        CAST(NULL AS TINYINT) AS clicked,
        CAST(NULL AS TINYINT) AS is_unsubscribed,
        CAST(NULL AS TINYINT) AS is_spammed,
        CAST(NULL AS INTEGER) AS updated_at,
        CAST(NULL AS VARCHAR) AS data,
        CAST(NULL AS VARCHAR) AS recipient_domain,
        CAST(NULL AS VARCHAR) AS sender_domain,
        CAST(NULL AS VARCHAR) AS template_id,
        CAST(NULL AS VARCHAR) AS unique_arguments,
        CAST(NULL AS DATE) AS dt,
        CAST(NULL AS VARCHAR) AS _op,
        CAST(NULL AS TIMESTAMP) AS _ts,
        CAST(NULL AS BIGINT) AS _seq
    WHERE FALSE
);
SQL;
    }
}
