<?php

declare(strict_types=1);

namespace App\Bulk;

use App\Duck\Session;
use App\Schema\ContactAttrs;
use App\Warehouse\Paths;
use App\Write\Locks;

final class Importer
{
    private Session $duck;

    public function __construct(
        private readonly Paths $wh,
        private readonly Locks $locks,
        ?string $duckdbBin = null,
    ) {
        $this->duck = new Session($duckdbBin ?? (getenv('DUCKDB_BIN') ?: 'duckdb'));
    }

    /** @return array{table:string,rows:int,mode:string,output_path:string,source:string,elapsed_ms:int} */
    public function importContacts(string $sourcePath, int $accountId, string $mode): array
    {
        $started = (int) (microtime(true) * 1000);
        if ($mode === '') {
            $mode = 'delta';
        }
        $rel = $this->sourceRelation($sourcePath);
        $unlock = $this->locks->lock('contact:' . $accountId);
        try {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            if ($mode === 'snapshot') {
                $destDir = $this->wh->contactSnapshotDir($accountId);
                $prefix = 'snapshot-bulk';
            } else {
                $destDir = $this->wh->contactDeltaDir($accountId, $now);
                $prefix = 'contact-bulk';
            }
            if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
                throw new \RuntimeException('Cannot create ' . $destDir);
            }
            $dest = $destDir . '/' . $prefix . '-' . Paths::uuid() . '.parquet';
            $tmp = Paths::stagingPath($dest);
            $srcCols = $this->describeColumns($rel);
            $select = [];
            foreach (ContactAttrs::all() as $attr) {
                $name = $attr['name'];
                $src = isset($srcCols[strtolower($name)]) ? $name : 'NULL';
                $select[] = match ($name) {
                    'account_id' => 'CAST(' . $accountId . ' AS BIGINT) AS account_id',
                    'primary_key' => 'CAST(COALESCE(' . $this->colRef($srcCols, 'primary_key') . ', '
                        . $this->colRef($srcCols, 'email') . ', CAST(' . $this->colRef($srcCols, 'id')
                        . ' AS VARCHAR)) AS VARCHAR) AS primary_key',
                    'domain' => 'CAST(COALESCE(' . $this->colRef($srcCols, 'domain')
                        . ", NULLIF(split_part(CAST(" . $this->colRef($srcCols, 'email') . " AS VARCHAR), '@', 2), '')) AS VARCHAR) AS domain",
                    'created_at', 'updated_at', 'subscribed_on' => $this->timestampCoalesce($src, $name, true),
                    'last_emailed', 'last_sms', 'last_opened', 'last_clicked', 'email_suppressed_on', 'sms_suppressed_on'
                        => $this->timestampCoalesce($src, $name, false),
                    'email_status', 'sms_status' => 'CAST(COALESCE(TRY_CAST(' . $src . ' AS TINYINT), 1) AS TINYINT) AS ' . $name,
                    'is_deleted', 'is_preview', 'is_opened', 'is_clicked'
                        => 'CAST(COALESCE(TRY_CAST(' . $src . ' AS TINYINT), 0) AS TINYINT) AS ' . $name,
                    'is_contact', 'import_source' => 'CAST(COALESCE(TRY_CAST(' . $src . ' AS TINYINT), 1) AS TINYINT) AS ' . $name,
                    default => 'TRY_CAST(' . $src . ' AS ' . ContactAttrs::duckType($attr['type']) . ') AS ' . $name,
                };
            }
            $select[] = "CAST('upsert' AS VARCHAR) AS _op";
            $select[] = 'CAST(CURRENT_TIMESTAMP AS TIMESTAMP) AS _ts';
            $select[] = 'CAST(COALESCE(TRY_CAST(id AS BIGINT), 0) AS BIGINT) AS _seq';
            $sql = 'COPY (SELECT ' . implode(', ', $select) . ' FROM ' . $rel
                . ' AS src WHERE TRY_CAST(id AS BIGINT) IS NOT NULL) TO ' . Session::quote($tmp)
                . ' (FORMAT PARQUET, COMPRESSION ZSTD)';
            try {
                $this->duck->exec($sql);
                Paths::commitParquet($tmp, $dest);
            } catch (\Throwable $e) {
                Paths::abortParquet($tmp);
                throw $e;
            }
            if ($mode === 'snapshot') {
                $this->clearParquetDirExcept($destDir, basename($dest));
            }
            $rows = $this->countFile($dest);
            return $this->result('contact', $rows, $mode, $dest, $sourcePath, $started);
        } finally {
            $unlock();
        }
    }

    /** @return array{table:string,rows:int,mode:string,output_path:string,source:string,elapsed_ms:int} */
    public function importCampaignSub(string $sourcePath, int $campaignId, string $mode): array
    {
        $started = (int) (microtime(true) * 1000);
        if ($mode === '') {
            $mode = 'delta';
        }
        $rel = $this->sourceRelation($sourcePath);
        $unlock = $this->locks->lock('campaign_sub:' . $campaignId);
        try {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            if ($mode === 'snapshot') {
                $destDir = $this->wh->campaignSubSnapshotDir($campaignId);
                $prefix = 'snapshot-bulk';
            } else {
                $destDir = $this->wh->campaignSubDeltaDir($campaignId, $now);
                $prefix = 'campaign_sub-bulk';
            }
            if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
                throw new \RuntimeException('Cannot create ' . $destDir);
            }
            if ($mode === 'snapshot') {
                $this->clearParquetDirExcept($destDir, '');
            }
            $dest = $destDir . '/' . $prefix . '-' . Paths::uuid() . '.parquet';
            $sql = <<<SQL
COPY (
    SELECT
        CAST(COALESCE(TRY_CAST(campaign_id AS BIGINT), {$campaignId}) AS BIGINT) AS campaign_id,
        CAST(COALESCE(primary_key, recipient, CAST(contact_id AS VARCHAR)) AS VARCHAR) AS primary_key,
        CAST(COALESCE(TRY_CAST(version_id AS BIGINT), 1) AS BIGINT) AS version_id,
        CAST(contact_id AS BIGINT) AS contact_id,
        CAST(recipient AS VARCHAR) AS recipient,
        CAST(COALESCE(attributes, '{}') AS VARCHAR) AS attributes,
        CAST(COALESCE(TRY_CAST(message_size AS INTEGER), 0) AS INTEGER) AS message_size,
        TRY_CAST(sent_on AS TIMESTAMP) AS sent_on,
        CAST(COALESCE(TRY_CAST(priority AS TINYINT), 0) AS TINYINT) AS priority,
        TRY_CAST(queue_id AS INTEGER) AS queue_id,
        CAST(COALESCE(TRY_CAST(connection_id AS INTEGER), 0) AS INTEGER) AS connection_id,
        CAST(COALESCE(TRY_CAST(status AS TINYINT), 0) AS TINYINT) AS status,
        CAST(COALESCE(TRY_CAST(total_open AS TINYINT), 0) AS TINYINT) AS total_open,
        CAST(COALESCE(TRY_CAST(total_click AS TINYINT), 0) AS TINYINT) AS total_click,
        CAST(drop_reason AS VARCHAR) AS drop_reason,
        CAST(COALESCE(TRY_CAST(soft_bounce_retry AS TINYINT), 1) AS TINYINT) AS soft_bounce_retry,
        CAST(COALESCE(TRY_CAST(total_open_amp AS TINYINT), 0) AS TINYINT) AS total_open_amp,
        CAST(COALESCE(TRY_CAST(total_click_amp AS TINYINT), 0) AS TINYINT) AS total_click_amp,
        CAST(COALESCE(message_id, printf('msg-%d-%s', {$campaignId}, CAST(contact_id AS VARCHAR))) AS VARCHAR) AS message_id,
        CAST(COALESCE(channel, 'email') AS VARCHAR) AS channel,
        CAST(dlr_code AS VARCHAR) AS dlr_code,
        TRY_CAST(dlr_at AS TIMESTAMP) AS dlr_at,
        CAST('upsert' AS VARCHAR) AS _op,
        CAST(CURRENT_TIMESTAMP AS TIMESTAMP) AS _ts,
        CAST(ROW_NUMBER() OVER () AS BIGINT) AS _seq
    FROM {$rel}
    WHERE TRY_CAST(contact_id AS BIGINT) IS NOT NULL
) TO {$this->q($dest)} (FORMAT PARQUET, COMPRESSION ZSTD)
SQL;
            $this->duck->exec($sql);
            return $this->result('campaign_sub', $this->countFile($dest), $mode, $dest, $sourcePath, $started);
        } finally {
            $unlock();
        }
    }

    /** @return array{table:string,rows:int,mode:string,output_path:string,source:string,elapsed_ms:int} */
    public function importActivity(string $sourcePath, string $dt): array
    {
        $started = (int) (microtime(true) * 1000);
        $rel = $this->sourceRelation($sourcePath);
        if ($dt === '') {
            $dt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
        }
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $dt, new \DateTimeZone('UTC'));
        if ($day === false) {
            throw new \InvalidArgumentException('dt must be YYYY-MM-DD');
        }
        $destDir = $this->wh->campaignActivityDir($day);
        if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
            throw new \RuntimeException('Cannot create ' . $destDir);
        }
        $dest = $destDir . '/campaign_activity-bulk-' . Paths::uuid() . '.parquet';
        $quotedDt = Session::quote($dt);
        $sql = <<<SQL
COPY (
    SELECT
        CAST(id AS BIGINT) AS id,
        CAST(COALESCE(TRY_CAST(version_id AS BIGINT), 1) AS BIGINT) AS version_id,
        CAST(contact_id AS BIGINT) AS contact_id,
        CAST(COALESCE(TRY_CAST(action AS TINYINT), 1) AS TINYINT) AS action,
        TRY_CAST(link_id AS TINYINT) AS link_id,
        CAST(user_agent AS VARCHAR) AS user_agent,
        CAST(ip AS VARCHAR) AS ip,
        COALESCE(TRY_CAST(created_at AS TIMESTAMP), CURRENT_TIMESTAMP) AS created_at,
        CAST(device AS VARCHAR) AS device,
        TRY_CAST(device_type AS TINYINT) AS device_type,
        CAST(browser AS VARCHAR) AS browser,
        CAST({$quotedDt} AS DATE) AS dt
    FROM {$rel}
    WHERE TRY_CAST(id AS BIGINT) IS NOT NULL
) TO {$this->q($dest)} (FORMAT PARQUET, COMPRESSION ZSTD)
SQL;
        $this->duck->exec($sql);
        return $this->result('campaign_activity', $this->countFile($dest), 'append', $dest, $sourcePath, $started);
    }

    private function sourceRelation(string $path): string
    {
        if (!file_exists($path)) {
            throw new \InvalidArgumentException('file not found: ' . $path);
        }
        if (is_dir($path)) {
            $glob = rtrim($path, '/') . '/**/*.parquet';
            return 'read_parquet(' . Session::quote($glob) . ', hive_partitioning=false, union_by_name=true)';
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $quoted = Session::quote($path);
        return match ($ext) {
            'parquet' => 'read_parquet(' . $quoted . ', union_by_name=true)',
            'csv', 'tsv' => 'read_csv_auto(' . $quoted . ', header=true, sample_size=-1)',
            'gz' => str_ends_with(strtolower($path), '.csv.gz')
                ? 'read_csv_auto(' . $quoted . ', header=true, sample_size=-1)'
                : throw new \InvalidArgumentException('unsupported gzipped type: ' . $path),
            default => throw new \InvalidArgumentException('unsupported file type .' . $ext),
        };
    }

    /** @return array<string, true> */
    private function describeColumns(string $relation): array
    {
        $rows = $this->duck->query('SELECT column_name FROM (DESCRIBE SELECT * FROM ' . $relation . ')');
        $out = [];
        foreach ($rows as $row) {
            $name = strtolower((string) ($row['column_name'] ?? ''));
            if ($name !== '') {
                $out[$name] = true;
            }
        }
        return $out;
    }

    /** @param array<string, true> $srcCols */
    private function colRef(array $srcCols, string $name): string
    {
        return isset($srcCols[strtolower($name)]) ? $name : 'NULL';
    }

    private function timestampCoalesce(string $src, string $name, bool $defaultNow): string
    {
        $fallback = $defaultNow ? ",\n                    CURRENT_TIMESTAMP" : '';
        return 'CAST(COALESCE(TRY_CAST(' . $src . ' AS TIMESTAMP), CASE WHEN TRY_CAST(' . $src
            . ' AS BIGINT) IS NOT NULL AND TRY_CAST(' . $src . ' AS BIGINT) > 100000 THEN to_timestamp(TRY_CAST('
            . $src . ' AS BIGINT)) END' . $fallback . ') AS TIMESTAMP) AS ' . $name;
    }

    private function clearParquetDirExcept(string $dir, string $keepBase): void
    {
        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $name) {
            if (!str_ends_with($name, '.parquet') || ($keepBase !== '' && $name === $keepBase)) {
                continue;
            }
            @unlink($dir . '/' . $name);
        }
    }

    private function countFile(string $path): int
    {
        $rows = $this->duck->query('SELECT count(*)::BIGINT AS cnt FROM read_parquet(' . Session::quote($path) . ')');
        return (int) ($rows[0]['cnt'] ?? 0);
    }

    private function q(string $value): string
    {
        return Session::quote($value);
    }

    /** @return array{table:string,rows:int,mode:string,output_path:string,source:string,elapsed_ms:int} */
    private function result(string $table, int $rows, string $mode, string $dest, string $source, int $started): array
    {
        return [
            'table' => $table,
            'rows' => $rows,
            'mode' => $mode,
            'output_path' => $dest,
            'source' => $source,
            'elapsed_ms' => ((int) (microtime(true) * 1000)) - $started,
        ];
    }
}
