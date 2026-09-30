<?php

declare(strict_types=1);

namespace App\Pipeline;

use App\Duck\Session;
use App\Duck\Views;
use App\Filter\Sql;
use App\Store\Query;
use App\Warehouse\Paths;
use App\Write\Locks;

/**
 * Segment save, campaign launch, and DLR ingest.
 * DLRs are written straight to Parquet (the Go service used Kafka for api_logs).
 */
final class Service
{
    private Session $duck;

    public function __construct(
        private readonly Paths $wh,
        private readonly Locks $locks,
        private readonly Query $store,
        ?string $duckdbBin = null,
    ) {
        $this->duck = new Session($duckdbBin ?? (getenv('DUCKDB_BIN') ?: 'duckdb'));
    }

    /** @param array<string, mixed> $filter
     * @return array<string, mixed>
     */
    public function saveSegment(int $accountId, string $name, array $filter): array
    {
        Sql::validate($filter);
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $seg = [
            'id' => Paths::uuid(),
            'account_id' => $accountId,
            'name' => $name,
            'filter_json' => json_encode($filter, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $dir = $this->wh->segmentDir($accountId);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create ' . $dir);
        }
        $dest = $dir . '/' . Paths::newParquetName('segment');
        $sql = 'COPY (SELECT '
            . 'CAST(' . Session::quote($seg['id']) . ' AS VARCHAR) AS id, '
            . 'CAST(' . $accountId . ' AS BIGINT) AS account_id, '
            . 'CAST(' . Session::quote($name) . ' AS VARCHAR) AS name, '
            . 'CAST(' . Session::quote($seg['filter_json']) . ' AS VARCHAR) AS filter_json, '
            . 'CAST(' . Session::quote($now) . ' AS TIMESTAMP) AS created_at, '
            . 'CAST(' . Session::quote($now) . ' AS TIMESTAMP) AS updated_at'
            . ') TO ' . Session::quote($dest) . ' (FORMAT PARQUET, COMPRESSION ZSTD)';
        $this->duck->exec($sql);
        return $seg;
    }

    /** @return array<string, mixed> */
    public function createCampaign(int $accountId, string $name, string $channel, string $segmentId): array
    {
        if ($channel !== 'email' && $channel !== 'sms') {
            throw new \InvalidArgumentException('channel must be email or sms');
        }
        $id = $this->store->nextCampaignId($accountId);
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $campaign = [
            'id' => $id,
            'account_id' => $accountId,
            'name' => $name,
            'channel' => $channel,
            'segment_id' => $segmentId,
            'status' => 'draft',
            'created_at' => $now,
            'launched_at' => null,
            'queued_cnt' => 0,
        ];
        $this->writeCampaignMeta($campaign, 'upsert');
        return $campaign;
    }

    /** @return array{campaign:array<string,mixed>,queued:int} */
    public function launchCampaign(int $accountId, int $campaignId): array
    {
        $camp = $this->store->getCampaign($accountId, $campaignId);
        if ($camp === null) {
            throw new \InvalidArgumentException('campaign not found');
        }
        $seg = $this->store->getSegment($accountId, (string) $camp['segment_id']);
        if ($seg === null) {
            throw new \InvalidArgumentException('segment not found');
        }
        $filter = json_decode((string) $seg['filter_json'], true);
        if (!is_array($filter)) {
            throw new \InvalidArgumentException('segment filter is invalid');
        }
        $where = Sql::toSql($filter);
        $unlock = $this->locks->lock('campaign_sub:' . $campaignId);
        try {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $destDir = $this->wh->campaignSubDeltaDir($campaignId, $now);
            if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
                throw new \RuntimeException('Cannot create ' . $destDir);
            }
            $dest = $destDir . '/' . Paths::newParquetName('launch');
            $tmp = Paths::stagingPath($dest);
            $recipient = $camp['channel'] === 'sms' ? 'mobile' : 'email';
            $channel = (string) $camp['channel'];
            $sql = 'COPY (SELECT '
                . 'CAST(' . $campaignId . ' AS BIGINT) AS campaign_id, '
                . 'CAST(COALESCE(primary_key, email, CAST(id AS VARCHAR)) AS VARCHAR) AS primary_key, '
                . 'CAST(1 AS BIGINT) AS version_id, '
                . 'CAST(id AS BIGINT) AS contact_id, '
                . 'CAST(' . $recipient . ' AS VARCHAR) AS recipient, '
                . "CAST('{}' AS VARCHAR) AS attributes, "
                . 'CAST(0 AS INTEGER) AS message_size, '
                . 'CAST(NULL AS TIMESTAMP) AS sent_on, '
                . 'CAST(0 AS TINYINT) AS priority, '
                . 'CAST(1 AS INTEGER) AS queue_id, '
                . 'CAST(0 AS INTEGER) AS connection_id, '
                . 'CAST(0 AS TINYINT) AS status, '
                . 'CAST(0 AS TINYINT) AS total_open, '
                . 'CAST(0 AS TINYINT) AS total_click, '
                . 'CAST(NULL AS VARCHAR) AS drop_reason, '
                . 'CAST(1 AS TINYINT) AS soft_bounce_retry, '
                . 'CAST(0 AS TINYINT) AS total_open_amp, '
                . 'CAST(0 AS TINYINT) AS total_click_amp, '
                . "CAST(printf('%s-%d-%d', " . Session::quote($channel) . ', ' . $campaignId . ', id) AS VARCHAR) AS message_id, '
                . 'CAST(' . Session::quote($channel) . ' AS VARCHAR) AS channel, '
                . 'CAST(NULL AS VARCHAR) AS dlr_code, '
                . 'CAST(NULL AS TIMESTAMP) AS dlr_at, '
                . "CAST('upsert' AS VARCHAR) AS _op, "
                . 'CAST(CURRENT_TIMESTAMP AS TIMESTAMP) AS _ts, '
                . 'CAST(ROW_NUMBER() OVER () AS BIGINT) AS _seq '
                . 'FROM contact WHERE account_id = ' . $accountId . ' AND (' . $where . ') AND COALESCE(is_deleted, 0) = 0'
                . ') TO ' . Session::quote($tmp) . ' (FORMAT PARQUET, COMPRESSION ZSTD)';
            try {
                $this->duck->exec(Views::registerSql($this->wh) . "\n" . $sql);
                Paths::commitParquet($tmp, $dest);
            } catch (\Throwable $e) {
                Paths::abortParquet($tmp);
                throw $e;
            }
            $queued = (int) ($this->duck->query('SELECT count(*)::BIGINT AS cnt FROM read_parquet(' . Session::quote($dest) . ')')[0]['cnt'] ?? 0);
            $camp['status'] = 'running';
            $camp['queued_cnt'] = $queued;
            $camp['launched_at'] = $now->format('Y-m-d H:i:s');
            $this->writeCampaignMeta($camp, 'upsert');
            return ['campaign' => $camp, 'queued' => $queued];
        } finally {
            $unlock();
        }
    }

    public function applyDlr(string $channel, string $messageId, int $campaignId, int $status, string $code, string $reason): void
    {
        if ($messageId === '') {
            throw new \InvalidArgumentException('message_id required');
        }
        $sub = $this->store->getSubscriberByMessageId($messageId);
        if ($sub === null && $campaignId === 0) {
            throw new \InvalidArgumentException('message_id not found: ' . $messageId);
        }
        if ($campaignId === 0 && $sub !== null) {
            $campaignId = (int) $sub['campaign_id'];
        }
        if ($channel === '' && $sub !== null) {
            $channel = (string) $sub['channel'];
        }
        $recipient = (string) ($sub['recipient'] ?? '');
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $stamp = $now->format('Y-m-d H:i:s');
        $this->writeSubDelta($sub, $campaignId, $messageId, $channel, $recipient, $status, $code, $stamp);
        $this->writeDlrEvent($messageId, $campaignId, $channel, $status, $code, $reason, $stamp, $now);
        $this->writeApiLog($campaignId, $messageId, $channel, $recipient, $status, $stamp, $now);
    }

    /** @param array<string, mixed>|null $sub */
    private function writeSubDelta(?array $sub, int $campaignId, string $messageId, string $channel, string $recipient, int $status, string $code, string $stamp): void
    {
        $unlock = $this->locks->lock('campaign_sub:' . $campaignId);
        try {
            $dir = $this->wh->campaignSubDeltaDir($campaignId);
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException('Cannot create ' . $dir);
            }
            $dest = $dir . '/' . Paths::newParquetName('dlr');
            $primary = (string) ($sub['primary_key'] ?? $recipient);
            $contactId = (int) ($sub['contact_id'] ?? 0);
            $sql = 'COPY (SELECT '
                . 'CAST(' . $campaignId . ' AS BIGINT) AS campaign_id, '
                . 'CAST(' . Session::quote($primary) . ' AS VARCHAR) AS primary_key, '
                . 'CAST(' . (int) ($sub['version_id'] ?? 1) . ' AS BIGINT) AS version_id, '
                . 'CAST(' . $contactId . ' AS BIGINT) AS contact_id, '
                . 'CAST(' . Session::quote($recipient) . ' AS VARCHAR) AS recipient, '
                . "CAST('{}' AS VARCHAR) AS attributes, "
                . 'CAST(0 AS INTEGER) AS message_size, '
                . 'CAST(CURRENT_TIMESTAMP AS TIMESTAMP) AS sent_on, '
                . 'CAST(0 AS TINYINT) AS priority, '
                . 'CAST(1 AS INTEGER) AS queue_id, '
                . 'CAST(0 AS INTEGER) AS connection_id, '
                . 'CAST(' . $status . ' AS TINYINT) AS status, '
                . 'CAST(0 AS TINYINT) AS total_open, '
                . 'CAST(0 AS TINYINT) AS total_click, '
                . 'CAST(NULL AS VARCHAR) AS drop_reason, '
                . 'CAST(1 AS TINYINT) AS soft_bounce_retry, '
                . 'CAST(0 AS TINYINT) AS total_open_amp, '
                . 'CAST(0 AS TINYINT) AS total_click_amp, '
                . 'CAST(' . Session::quote($messageId) . ' AS VARCHAR) AS message_id, '
                . 'CAST(' . Session::quote($channel) . ' AS VARCHAR) AS channel, '
                . 'CAST(' . Session::quote($code) . ' AS VARCHAR) AS dlr_code, '
                . 'CAST(CURRENT_TIMESTAMP AS TIMESTAMP) AS dlr_at, '
                . "CAST('upsert' AS VARCHAR) AS _op, "
                . 'CAST(CURRENT_TIMESTAMP AS TIMESTAMP) AS _ts, '
                . 'CAST(' . (int) (microtime(true) * 1000000) . ' AS BIGINT) AS _seq'
                . ') TO ' . Session::quote($dest) . ' (FORMAT PARQUET, COMPRESSION ZSTD)';
            $this->duck->exec($sql);
        } finally {
            $unlock();
        }
    }

    private function writeDlrEvent(string $messageId, int $campaignId, string $channel, int $status, string $code, string $reason, string $stamp, \DateTimeImmutable $now): void
    {
        $dir = $this->wh->dlrEventDir($now);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create ' . $dir);
        }
        $dest = $dir . '/' . Paths::newParquetName('dlr');
        $sql = 'COPY (SELECT '
            . 'CAST(' . Session::quote(Paths::uuid()) . ' AS VARCHAR) AS id, '
            . 'CAST(' . Session::quote($messageId) . ' AS VARCHAR) AS message_id, '
            . 'CAST(' . $campaignId . ' AS BIGINT) AS campaign_id, '
            . 'CAST(' . Session::quote($channel) . ' AS VARCHAR) AS channel, '
            . 'CAST(' . $status . ' AS TINYINT) AS status, '
            . 'CAST(' . Session::quote($code) . ' AS VARCHAR) AS code, '
            . 'CAST(' . Session::quote($reason) . ' AS VARCHAR) AS reason, '
            . 'CAST(' . Session::quote($stamp) . ' AS TIMESTAMP) AS created_at, '
            . 'CAST(' . Session::quote($now->format('Y-m-d')) . ' AS DATE) AS dt'
            . ') TO ' . Session::quote($dest) . ' (FORMAT PARQUET, COMPRESSION ZSTD)';
        $this->duck->exec($sql);
    }

    private function writeApiLog(int $campaignId, string $messageId, string $channel, string $recipient, int $status, string $stamp, \DateTimeImmutable $now): void
    {
        $dir = $this->wh->apiLogsDir($now);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create ' . $dir);
        }
        $dest = $dir . '/' . Paths::newParquetName('api_logs');
        $domain = '';
        if (str_contains($recipient, '@')) {
            $domain = substr($recipient, (int) strpos($recipient, '@') + 1);
        }
        $updated = (int) $now->format('U');
        $hour = (int) $now->format('G');
        $type = $channel === 'sms' ? 2 : 1;
        $sql = 'COPY (SELECT '
            . 'CAST(' . $campaignId . ' AS INTEGER) AS channel_id, '
            . 'CAST(' . Session::quote($messageId) . ' AS VARCHAR) AS message_id, '
            . 'CAST(' . $hour . ' AS TINYINT) AS request_hour, '
            . "CAST('127.0.0.1' AS VARCHAR) AS sent_ip, "
            . 'CAST(0 AS INTEGER) AS connection_id, '
            . 'CAST(0 AS INTEGER) AS mta_route_id, '
            . 'CAST(' . $type . ' AS TINYINT) AS type, '
            . 'CAST(' . $status . ' AS TINYINT) AS status, '
            . 'CAST(' . Session::quote($recipient) . ' AS VARCHAR) AS recipient, '
            . 'CAST(0 AS TINYINT) AS opened, '
            . 'CAST(0 AS TINYINT) AS clicked, '
            . 'CAST(0 AS TINYINT) AS is_unsubscribed, '
            . 'CAST(0 AS TINYINT) AS is_spammed, '
            . 'CAST(' . $updated . ' AS INTEGER) AS updated_at, '
            . "CAST('{}' AS VARCHAR) AS data, "
            . 'CAST(' . Session::quote($domain) . ' AS VARCHAR) AS recipient_domain, '
            . "CAST('' AS VARCHAR) AS sender_domain, "
            . "CAST('' AS VARCHAR) AS template_id, "
            . "CAST('{}' AS VARCHAR) AS unique_arguments, "
            . 'CAST(' . Session::quote($now->format('Y-m-d')) . ' AS DATE) AS dt, '
            . "CAST('upsert' AS VARCHAR) AS _op, "
            . 'CAST(' . Session::quote($stamp) . ' AS TIMESTAMP) AS _ts, '
            . 'CAST(' . (int) (microtime(true) * 1000) . ' AS BIGINT) AS _seq'
            . ') TO ' . Session::quote($dest) . ' (FORMAT PARQUET, COMPRESSION ZSTD)';
        $this->duck->exec($sql);
    }

    /** @param array<string, mixed> $campaign */
    private function writeCampaignMeta(array $campaign, string $op): void
    {
        $accountId = (int) $campaign['account_id'];
        $unlock = $this->locks->lock('campaign_meta:' . $accountId);
        try {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $dir = $this->wh->campaignMetaDeltaDir($accountId, $now);
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException('Cannot create ' . $dir);
            }
            $dest = $dir . '/' . Paths::newParquetName('campaign');
            $tmp = Paths::stagingPath($dest);
            $launched = $campaign['launched_at'] ?? null;
            $launchedSql = $launched === null || $launched === ''
                ? 'CAST(NULL AS TIMESTAMP)'
                : 'CAST(' . Session::quote((string) $launched) . ' AS TIMESTAMP)';
            $sql = 'COPY (SELECT '
                . 'CAST(' . (int) $campaign['id'] . ' AS BIGINT) AS id, '
                . 'CAST(' . $accountId . ' AS BIGINT) AS account_id, '
                . 'CAST(' . Session::quote((string) $campaign['name']) . ' AS VARCHAR) AS name, '
                . 'CAST(' . Session::quote((string) $campaign['channel']) . ' AS VARCHAR) AS channel, '
                . 'CAST(' . Session::quote((string) $campaign['segment_id']) . ' AS VARCHAR) AS segment_id, '
                . 'CAST(' . Session::quote((string) $campaign['status']) . ' AS VARCHAR) AS status, '
                . 'CAST(' . Session::quote((string) $campaign['created_at']) . ' AS TIMESTAMP) AS created_at, '
                . $launchedSql . ' AS launched_at, '
                . 'CAST(' . (int) $campaign['queued_cnt'] . ' AS BIGINT) AS queued_cnt, '
                . 'CAST(' . Session::quote($op) . ' AS VARCHAR) AS _op, '
                . 'CAST(CURRENT_TIMESTAMP AS TIMESTAMP) AS _ts, '
                . 'CAST(' . (int) (microtime(true) * 1000000) . ' AS BIGINT) AS _seq'
                . ') TO ' . Session::quote($tmp) . ' (FORMAT PARQUET, COMPRESSION ZSTD)';
            try {
                $this->duck->exec($sql);
                Paths::commitParquet($tmp, $dest);
            } catch (\Throwable $e) {
                Paths::abortParquet($tmp);
                throw $e;
            }
        } finally {
            $unlock();
        }
    }
}
