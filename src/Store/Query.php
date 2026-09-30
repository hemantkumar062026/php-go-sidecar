<?php

declare(strict_types=1);

namespace App\Store;

use App\Duck\Session;
use App\Duck\Views;
use App\Filter\Sql;
use App\Schema\ContactAttrs;
use App\Warehouse\Paths;

final class Query
{
    private Session $duck;

    public function __construct(private readonly Paths $wh, ?string $duckdbBin = null)
    {
        $this->duck = new Session($duckdbBin ?? (getenv('DUCKDB_BIN') ?: 'duckdb'));
    }

    /** @param list<string> $columns
     * @return array{rows:list<array<string,mixed>>,limit:int,after_id:int,next_after_id:?int,has_more:bool}
     */
    public function listContacts(int $accountId, string $q, int $limit, int $afterId, int $offset, array $columns): array
    {
        if ($limit <= 0) {
            $limit = 25;
        }
        if ($limit > 100) {
            $limit = 100;
        }
        if ($columns === []) {
            $columns = ['id', 'email', 'mobile', 'first_name', 'last_name', 'city', 'country', 'company', 'lifecycle_stage', 'email_status', 'sms_status'];
        }
        $safe = [];
        $hasId = false;
        foreach ($columns as $column) {
            $column = trim($column);
            if (ContactAttrs::byName($column) !== null) {
                $safe[] = $column;
                if ($column === 'id') {
                    $hasId = true;
                }
            }
        }
        if ($safe === []) {
            $safe = ['id', 'email'];
            $hasId = true;
        }
        if (!$hasId) {
            array_unshift($safe, 'id');
        }

        $where = ['account_id = ' . $accountId];
        if ($afterId > 0) {
            $where[] = 'id > ' . $afterId;
        }
        if ($q !== '') {
            if (preg_match('/^-?\d+$/', trim($q)) === 1) {
                $where[] = 'id = ' . (int) $q;
            } else {
                $like = Session::quote('%' . $q . '%');
                $where[] = '(CAST(email AS VARCHAR) ILIKE ' . $like
                    . ' OR CAST(first_name AS VARCHAR) ILIKE ' . $like
                    . ' OR CAST(last_name AS VARCHAR) ILIKE ' . $like
                    . " OR CAST(COALESCE(company, '') AS VARCHAR) ILIKE " . $like
                    . " OR CAST(COALESCE(city, '') AS VARCHAR) ILIKE " . $like . ')';
            }
        }
        $fetch = $limit + 1;
        $sql = 'SELECT ' . implode(', ', $safe) . ' FROM ' . $this->contactFrom($accountId)
            . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY id LIMIT ' . $fetch;
        if ($afterId <= 0 && $offset > 0) {
            $sql .= ' OFFSET ' . $offset;
        }
        $rows = $this->warehouseQuery($sql);
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }
        $next = null;
        if ($rows !== []) {
            $last = $rows[array_key_last($rows)];
            if (isset($last['id'])) {
                $next = (int) $last['id'];
            }
        }
        return [
            'rows' => $rows,
            'limit' => $limit,
            'after_id' => $afterId,
            'next_after_id' => $next,
            'has_more' => $hasMore,
        ];
    }

    /** @param array<string, mixed> $filter
     * @return array{count:int,sample:list<array<string,mixed>>}
     */
    public function previewSegment(int $accountId, array $filter, int $sampleLimit): array
    {
        if ($sampleLimit <= 0) {
            $sampleLimit = 20;
        }
        $where = Sql::toSql($filter);
        $countRows = $this->warehouseQuery(
            'SELECT count(*)::BIGINT AS cnt FROM contact WHERE account_id = ' . $accountId . ' AND (' . $where . ')'
        );
        $cols = 'id, email, mobile, first_name, last_name, city, country, company, lifecycle_stage, loyalty_tier, email_status, sms_status';
        $sample = $this->warehouseQuery(
            'SELECT ' . $cols . ' FROM contact WHERE account_id = ' . $accountId
            . ' AND (' . $where . ') ORDER BY id LIMIT ' . $sampleLimit
        );
        return ['count' => (int) ($countRows[0]['cnt'] ?? 0), 'sample' => $sample];
    }

    /** @return list<array<string, mixed>> */
    public function listSegments(int $accountId): array
    {
        return $this->warehouseQuery(
            'SELECT id, account_id, name, filter_json, created_at, updated_at FROM segment WHERE account_id = '
            . $accountId . ' ORDER BY created_at DESC'
        );
    }

    /** @return array<string, mixed>|null */
    public function getSegment(int $accountId, string $id): ?array
    {
        $rows = $this->warehouseQuery(
            'SELECT id, account_id, name, filter_json, created_at, updated_at FROM segment WHERE account_id = '
            . $accountId . ' AND id = ' . Session::quote($id) . ' LIMIT 1'
        );
        return $rows[0] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function listCampaigns(int $accountId): array
    {
        return $this->warehouseQuery(
            'SELECT id, account_id, name, channel, segment_id, status, created_at, launched_at, queued_cnt FROM campaign WHERE account_id = '
            . $accountId . ' ORDER BY id DESC'
        );
    }

    /** @return array<string, mixed>|null */
    public function getCampaign(int $accountId, int $id): ?array
    {
        $rows = $this->warehouseQuery(
            'SELECT id, account_id, name, channel, segment_id, status, created_at, launched_at, queued_cnt FROM campaign WHERE account_id = '
            . $accountId . ' AND id = ' . $id . ' LIMIT 1'
        );
        return $rows[0] ?? null;
    }

    public function nextCampaignId(int $accountId): int
    {
        $rows = $this->warehouseQuery('SELECT max(id) AS max_id FROM campaign WHERE account_id = ' . $accountId);
        $max = $rows[0]['max_id'] ?? null;
        if ($max === null) {
            return 1;
        }
        return ((int) $max) + 1;
    }

    /** @return list<array<string, mixed>> */
    public function listSubscribers(int $campaignId, string $q, ?int $status, int $limit, int $offset): array
    {
        if ($limit <= 0) {
            $limit = 50;
        }
        $where = ['campaign_id = ' . $campaignId];
        if ($q !== '') {
            $like = Session::quote('%' . $q . '%');
            $where[] = '(recipient ILIKE ' . $like . ' OR primary_key ILIKE ' . $like . ' OR message_id ILIKE ' . $like . ')';
        }
        if ($status !== null) {
            $where[] = 'status = ' . $status;
        }
        return $this->warehouseQuery(
            'SELECT campaign_id, primary_key, version_id, contact_id, recipient, attributes, message_size, sent_on, priority, queue_id, connection_id, status, total_open, total_click, drop_reason, soft_bounce_retry, total_open_amp, total_click_amp, message_id, channel, dlr_code, dlr_at FROM campaign_sub WHERE '
            . implode(' AND ', $where) . ' ORDER BY contact_id LIMIT ' . $limit . ' OFFSET ' . max(0, $offset)
        );
    }

    /** @return array<string, mixed>|null */
    public function getSubscriberByMessageId(string $messageId): ?array
    {
        $rows = $this->warehouseQuery(
            'SELECT campaign_id, primary_key, version_id, contact_id, recipient, attributes, message_size, sent_on, priority, queue_id, connection_id, status, total_open, total_click, drop_reason, soft_bounce_retry, total_open_amp, total_click_amp, message_id, channel, dlr_code, dlr_at FROM campaign_sub WHERE message_id = '
            . Session::quote($messageId) . ' LIMIT 1'
        );
        return $rows[0] ?? null;
    }

    /** @return array{campaign_id:int,channel:string,total:int,by_status:array<string,int>} */
    public function campaignStats(int $campaignId): array
    {
        $channelRows = $this->warehouseQuery(
            'SELECT channel FROM campaign_sub WHERE campaign_id = ' . $campaignId . ' LIMIT 1'
        );
        $rows = $this->warehouseQuery(
            'SELECT status, count(*)::BIGINT AS cnt FROM campaign_sub WHERE campaign_id = ' . $campaignId . ' GROUP BY status'
        );
        $by = [];
        $total = 0;
        foreach ($rows as $row) {
            $cnt = (int) ($row['cnt'] ?? 0);
            $by[(string) ($row['status'] ?? '')] = $cnt;
            $total += $cnt;
        }
        return [
            'campaign_id' => $campaignId,
            'channel' => (string) ($channelRows[0]['channel'] ?? ''),
            'total' => $total,
            'by_status' => $by,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function listApiLogs(string $dt, int $channelId, ?int $status, string $q, int $limit, int $offset): array
    {
        if ($limit <= 0 || $limit > 500) {
            $limit = 50;
        }
        $where = ['1=1'];
        if ($dt !== '') {
            $where[] = 'CAST(dt AS VARCHAR) = ' . Session::quote($dt);
        }
        if ($channelId > 0) {
            $where[] = 'channel_id = ' . $channelId;
        }
        if ($status !== null) {
            $where[] = 'status = ' . $status;
        }
        if ($q !== '') {
            $like = Session::quote('%' . $q . '%');
            $where[] = '(message_id ILIKE ' . $like . " OR COALESCE(recipient, '') ILIKE " . $like . ')';
        }
        return $this->warehouseQuery(
            'SELECT channel_id, message_id, request_hour, sent_ip, connection_id, mta_route_id, type, status, recipient, opened, clicked, is_unsubscribed, is_spammed, updated_at, data, recipient_domain, sender_domain, template_id, unique_arguments, CAST(dt AS VARCHAR) AS dt FROM api_logs WHERE '
            . implode(' AND ', $where) . ' ORDER BY updated_at DESC LIMIT ' . $limit . ' OFFSET ' . max(0, $offset)
        );
    }

    public function duck(): Session
    {
        return $this->duck;
    }

    public function paths(): Paths
    {
        return $this->wh;
    }

    /** @return list<array<string, mixed>> */
    public function warehouseQuery(string $sql): array
    {
        $this->wh->sweepStaleStaging();
        return $this->duck->query(Views::registerSql($this->wh) . "\n" . $sql);
    }

    private function contactFrom(int $accountId): string
    {
        $snap = $this->wh->contactSnapshotGlob($accountId);
        $delta = $this->wh->contactDeltaGlob($accountId);
        if (Views::hasParquet($snap) && !Views::hasParquet($delta)) {
            return Views::readParquet($snap);
        }
        return 'contact';
    }
}
