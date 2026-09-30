<?php

declare(strict_types=1);

namespace App\Compact;

use App\Duck\Session;
use App\Schema\ContactAttrs;
use App\Warehouse\Paths;
use App\Write\Locks;

final class Service
{
    private Session $duck;

    public function __construct(
        private readonly Paths $wh,
        private readonly Locks $locks,
        ?string $duckdbBin = null,
    ) {
        $this->duck = new Session($duckdbBin ?? (getenv('DUCKDB_BIN') ?: 'duckdb'));
    }

    /** @return array{rows_written:int,deltas_removed:int,snapshot_path:?string} */
    public function compactContact(int $accountId): array
    {
        $unlock = $this->locks->lock('contact:' . $accountId);
        try {
            $snapFiles = $this->listParquet($this->wh->contactSnapshotGlob($accountId));
            $deltaFiles = $this->listParquet($this->wh->contactDeltaGlob($accountId));
            if ($snapFiles === [] && $deltaFiles === []) {
                return ['rows_written' => 0, 'deltas_removed' => 0, 'snapshot_path' => null];
            }
            $cols = array_map(static fn (array $a): string => $a['name'], ContactAttrs::all());
            $cols[] = '_op';
            $cols[] = '_ts';
            $cols[] = '_seq';
            return $this->compact(
                $snapFiles,
                $deltaFiles,
                implode(', ', $cols),
                'id',
                $this->wh->contactSnapshotDir($accountId),
                $this->wh->root . '/contact/delta/account_id=' . $accountId,
            );
        } finally {
            $unlock();
        }
    }

    /** @return array{rows_written:int,deltas_removed:int,snapshot_path:?string} */
    public function compactCampaignSub(int $campaignId): array
    {
        $unlock = $this->locks->lock('campaign_sub:' . $campaignId);
        try {
            $snapFiles = $this->listParquet($this->wh->campaignSubSnapshotGlob($campaignId));
            $deltaFiles = $this->listParquet($this->wh->campaignSubDeltaGlob($campaignId));
            if ($snapFiles === [] && $deltaFiles === []) {
                return ['rows_written' => 0, 'deltas_removed' => 0, 'snapshot_path' => null];
            }
            $cols = 'campaign_id, primary_key, version_id, contact_id, recipient, attributes, message_size, sent_on, priority, queue_id, connection_id, status, total_open, total_click, drop_reason, soft_bounce_retry, total_open_amp, total_click_amp, message_id, channel, dlr_code, dlr_at, _op, _ts, _seq';
            return $this->compact(
                $snapFiles,
                $deltaFiles,
                $cols,
                'campaign_id, primary_key',
                $this->wh->campaignSubSnapshotDir($campaignId),
                $this->wh->root . '/campaign_sub/delta/campaign_id=' . $campaignId,
            );
        } finally {
            $unlock();
        }
    }

    /** @return array<string, int|string> */
    public function warehouseStats(): array
    {
        return [
            'root' => $this->wh->root,
            'contact_snapshot_files' => $this->count($this->wh->contactSnapshotGlob()),
            'contact_delta_files' => $this->count($this->wh->contactDeltaGlob()),
            'campaign_sub_snapshot_files' => $this->count($this->wh->campaignSubSnapshotGlob()),
            'campaign_sub_delta_files' => $this->count($this->wh->campaignSubDeltaGlob()),
            'campaign_activity_files' => $this->count($this->wh->campaignActivityGlob()),
            'campaign_meta_files' => $this->count($this->wh->campaignMetaSnapshotGlob()) + $this->count($this->wh->campaignMetaDeltaGlob()),
            'segment_files' => $this->count($this->wh->segmentGlob()),
            'dlr_event_files' => $this->count($this->wh->dlrEventGlob()),
            'api_logs_files' => $this->count($this->wh->apiLogsGlob()),
        ];
    }

    /**
     * @param list<string> $snapFiles
     * @param list<string> $deltaFiles
     * @return array{rows_written:int,deltas_removed:int,snapshot_path:?string}
     */
    private function compact(array $snapFiles, array $deltaFiles, string $cols, string $partition, string $snapDir, string $deltaRoot): array
    {
        $parts = [];
        if ($snapFiles !== []) {
            $parts[] = 'SELECT * FROM read_parquet(' . $this->quoteList($snapFiles) . ', union_by_name=true)';
        }
        if ($deltaFiles !== []) {
            $parts[] = 'SELECT * FROM read_parquet(' . $this->quoteList($deltaFiles) . ', union_by_name=true)';
        }
        $union = implode(' UNION ALL BY NAME ', $parts);
        $tmpDir = sys_get_temp_dir() . '/php_compact_' . bin2hex(random_bytes(4));
        if (!mkdir($tmpDir, 0755, true) && !is_dir($tmpDir)) {
            throw new \RuntimeException('Cannot create compact temp dir');
        }
        try {
            $outFile = $tmpDir . '/' . Paths::newParquetName('snapshot');
            $sql = 'COPY (WITH all_rows AS (' . $union . '), ranked AS (SELECT *, ROW_NUMBER() OVER (PARTITION BY '
                . $partition . ' ORDER BY _ts DESC, _seq DESC) AS _rn FROM all_rows) SELECT ' . $cols
                . " FROM ranked WHERE _rn = 1 AND _op <> 'delete') TO " . Session::quote($outFile)
                . ' (FORMAT PARQUET, COMPRESSION ZSTD)';
            $this->duck->exec($sql);
            $count = $this->duck->query('SELECT count(*)::BIGINT AS cnt FROM read_parquet(' . Session::quote($outFile) . ')');
            $this->replaceSnapshot($tmpDir, $snapDir);
            foreach ($deltaFiles as $file) {
                @unlink($file);
            }
            $this->cleanEmptyDeltaDirs($deltaRoot);
            return [
                'rows_written' => (int) ($count[0]['cnt'] ?? 0),
                'deltas_removed' => count($deltaFiles),
                'snapshot_path' => $snapDir,
            ];
        } finally {
            $this->removeTree($tmpDir);
        }
    }

    /** @param list<string> $files */
    private function quoteList(array $files): string
    {
        $parts = array_map(static fn (string $f): string => Session::quote($f), $files);
        return '[' . implode(', ', $parts) . ']';
    }

    /** @return list<string> */
    private function listParquet(string $glob): array
    {
        $matches = glob($glob);
        if (!is_array($matches)) {
            return [];
        }
        return array_values(array_filter($matches, static fn (string $path): bool => str_ends_with($path, '.parquet')));
    }

    private function count(string $glob): int
    {
        return count($this->listParquet($glob));
    }

    private function replaceSnapshot(string $tmpDir, string $snapDir): void
    {
        if (!is_dir($snapDir) && !mkdir($snapDir, 0755, true) && !is_dir($snapDir)) {
            throw new \RuntimeException('Cannot create ' . $snapDir);
        }
        foreach (scandir($snapDir) ?: [] as $name) {
            if (str_ends_with($name, '.parquet')) {
                @unlink($snapDir . '/' . $name);
            }
        }
        foreach (scandir($tmpDir) ?: [] as $name) {
            if (!str_ends_with($name, '.parquet')) {
                continue;
            }
            if (!rename($tmpDir . '/' . $name, $snapDir . '/' . $name)) {
                throw new \RuntimeException('Failed to move compacted snapshot');
            }
        }
    }

    private function cleanEmptyDeltaDirs(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }
        foreach (scandir($root) ?: [] as $name) {
            if (!str_starts_with($name, 'delta_date=')) {
                continue;
            }
            $dir = $root . '/' . $name;
            $files = glob($dir . '/*.parquet');
            if (!is_array($files) || $files === []) {
                $this->removeTree($dir);
            }
        }
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($dir);
    }
}
