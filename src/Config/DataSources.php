<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Discovers parquet lakes under data/ and builds DuckDB FROM SQL.
 *
 * Layouts:
 *   flat       — contact hive parts under contact/account_id=.../
 *   base_delta — contact/base plus latest row per id from contact/delta
 */
final class DataSources
{
    /**
     * @return list<array{
     *   id:string,
     *   name:string,
     *   path:string,
     *   layout:string,
     *   description:?string,
     *   meta:array<string,mixed>
     * }>
     */
    public static function list(?string $repoRoot = null): array
    {
        $root = $repoRoot ?? dirname(__DIR__, 2);
        $data = $root . '/data';
        $out = [];

        $known = [
            'dummy_1cr' => [
                'name' => 'Flat hive (1 crore)',
                'layout' => 'flat',
                'description' => '10M contacts in contact/account_id=*/part-*.parquet',
            ],
            'dummy_base_delta' => [
                'name' => 'Base + deltas (45)',
                'layout' => 'base_delta',
                'description' => '1 crore base parquet + 45 sparse delta files (status/suppressions/…)',
            ],
            'dummy_base_delta_10' => [
                'name' => 'Base + deltas (compacted 10)',
                'layout' => 'base_delta',
                'description' => 'Same lake as 45-delta source after folding older deltas into base; 10 delta files left',
            ],
            'dummy' => [
                'name' => 'Tiny demo',
                'layout' => 'flat',
                'description' => 'Small fixed fixture for local smoke tests',
            ],
        ];

        foreach ($known as $id => $info) {
            $path = $data . '/' . $id;
            if (!is_dir($path)) {
                continue;
            }
            $meta = [];
            $manifest = $path . '/datasource.json';
            if (is_file($manifest)) {
                $decoded = json_decode((string) file_get_contents($manifest), true);
                if (is_array($decoded)) {
                    $meta = $decoded;
                    if (isset($decoded['name']) && is_string($decoded['name'])) {
                        $info['name'] = $decoded['name'];
                    }
                    if (isset($decoded['layout']) && is_string($decoded['layout'])) {
                        $info['layout'] = $decoded['layout'];
                    }
                }
            } elseif (is_dir($path . '/contact/base') && is_dir($path . '/contact/delta')) {
                $info['layout'] = 'base_delta';
            }

            $bytes = self::dirSizeBytes($path);
            $out[] = [
                'id' => $id,
                'name' => $info['name'],
                'path' => $path,
                'layout' => $info['layout'],
                'description' => $info['description'] ?? null,
                'size_bytes' => $bytes,
                'size_human' => self::formatBytes($bytes),
                'meta' => $meta,
            ];
        }

        return $out;
    }

    /** Recursive byte size of a directory (files only). */
    public static function dirSizeBytes(string $path): int
    {
        $total = 0;
        if (!is_dir($path)) {
            return 0;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile()) {
                $total += (int) $file->getSize();
            }
        }
        return $total;
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $units = ['KB', 'MB', 'GB', 'TB'];
        $v = (float) $bytes;
        foreach ($units as $u) {
            $v /= 1024.0;
            if ($v < 1024.0 || $u === 'TB') {
                return ($v >= 10 ? number_format($v, 1) : number_format($v, 2)) . ' ' . $u;
            }
        }
        return $bytes . ' B';
    }

    /**
     * @param list<array{id:string,path:string,layout:string,...}> $sources
     * @return array{id:string,path:string,layout:string,...}|null
     */
    public static function findByPath(array $sources, string $parquetPath): ?array
    {
        $want = rtrim($parquetPath, '/');
        foreach ($sources as $s) {
            if (rtrim((string) $s['path'], '/') === $want) {
                return $s;
            }
        }
        return null;
    }

    public static function detectLayout(string $parquetPath): string
    {
        $root = rtrim($parquetPath, '/');
        if (is_dir($root . '/contact/base') && is_dir($root . '/contact/delta')) {
            return 'base_delta';
        }
        $manifest = $root . '/datasource.json';
        if (is_file($manifest)) {
            $decoded = json_decode((string) file_get_contents($manifest), true);
            if (is_array($decoded) && isset($decoded['layout']) && is_string($decoded['layout'])) {
                return $decoded['layout'];
            }
        }
        return 'flat';
    }

    /**
     * Build the DuckDB relation expression used as FROM (...).
     */
    public static function contactFromSql(string $parquetPath, ?string $layout = null): string
    {
        $root = rtrim($parquetPath, '/');
        $layout ??= self::detectLayout($root);
        $esc = static fn (string $p): string => str_replace("'", "''", $p);

        if ($layout === 'base_delta') {
            $baseGlob = $esc($root . '/contact/base/**/*.parquet');
            $deltaGlob = $esc($root . '/contact/delta/**/*.parquet');
            // Latest delta per id overlays mutable status/activity columns onto base.
            return '('
                . 'SELECT '
                . 'b.id, b.account_id, b.primary_key, b.email, b.mobile, '
                . 'COALESCE(d.email_status, b.email_status) AS email_status, '
                . 'COALESCE(d.sms_status, b.sms_status) AS sms_status, '
                . 'COALESCE(d.is_deleted, b.is_deleted) AS is_deleted, '
                . 'COALESCE(d.is_contact, b.is_contact) AS is_contact, '
                . 'b.is_preview, '
                . 'COALESCE(d.last_emailed, b.last_emailed) AS last_emailed, '
                . 'COALESCE(d.last_sms, b.last_sms) AS last_sms, '
                . 'COALESCE(d.email_suppressed_on, b.email_suppressed_on) AS email_suppressed_on, '
                . 'COALESCE(d.sms_suppressed_on, b.sms_suppressed_on) AS sms_suppressed_on, '
                . 'COALESCE(d.email_bounce_count, b.email_bounce_count) AS email_bounce_count, '
                . 'COALESCE(d.last_open, b.last_open) AS last_open, '
                . 'COALESCE(d.last_click, b.last_click) AS last_click, '
                . 'b.f2, b.f6, b.f7, b.f18, b.f30, b.f31 '
                . "FROM read_parquet('{$baseGlob}', hive_partitioning=true, union_by_name=true) AS b "
                . 'LEFT JOIN ('
                . 'SELECT * EXCLUDE (_rn) FROM ('
                . 'SELECT *, ROW_NUMBER() OVER ('
                . 'PARTITION BY id ORDER BY delta_seq DESC, updated_at DESC'
                . ') AS _rn '
                . "FROM read_parquet('{$deltaGlob}', hive_partitioning=true, union_by_name=true)"
                . ') WHERE _rn = 1'
                . ') AS d ON b.id = d.id'
                . ')';
        }

        $glob = $esc($root . '/contact/**/*.parquet');
        return "read_parquet('{$glob}', hive_partitioning=true, union_by_name=true)";
    }

    /**
     * Attach contact row counts (and delta row counts for base_delta lakes).
     *
     * @param list<array<string,mixed>> $sources
     * @return list<array<string,mixed>>
     */
    public static function enrichWithRowCounts(array $sources, \App\Duck\Session $duck): array
    {
        foreach ($sources as &$s) {
            $path = (string) ($s['path'] ?? '');
            $layout = (string) ($s['layout'] ?? 'flat');
            try {
                if ($layout === 'base_delta') {
                    $baseGlob = str_replace("'", "''", rtrim($path, '/') . '/contact/base/**/*.parquet');
                    $deltaGlob = str_replace("'", "''", rtrim($path, '/') . '/contact/delta/**/*.parquet');
                    $rows = $duck->query(
                        "SELECT count(*)::BIGINT AS n FROM read_parquet('{$baseGlob}', hive_partitioning=true, union_by_name=true)"
                    );
                    $s['row_count'] = (int) ($rows[0]['n'] ?? 0);
                    $drows = $duck->query(
                        "SELECT count(*)::BIGINT AS n FROM read_parquet('{$deltaGlob}', hive_partitioning=true, union_by_name=true)"
                    );
                    $s['delta_row_count'] = (int) ($drows[0]['n'] ?? 0);
                    $s['delta_file_count'] = (int) ($s['meta']['delta_files'] ?? 0);
                } else {
                    $from = self::contactFromSql($path, $layout);
                    $rows = $duck->query('SELECT count(*)::BIGINT AS n FROM ' . $from);
                    $s['row_count'] = (int) ($rows[0]['n'] ?? 0);
                }
                $s['row_count_human'] = number_format((int) ($s['row_count'] ?? 0));
            } catch (\Throwable $e) {
                $s['row_count'] = null;
                $s['row_count_human'] = null;
                $s['row_count_error'] = $e->getMessage();
            }
        }
        unset($s);
        return $sources;
    }
}
