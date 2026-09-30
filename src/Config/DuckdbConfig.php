<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Persisted DuckDB runtime settings used by /api/sftp/query.
 *
 * Keys match the ESP PHP config style:
 *   binary, parquetPath, threads, memoryLimit
 */
final class DuckdbConfig
{
    private string $path;

    public function __construct(?string $path = null)
    {
        $root = getenv('WAREHOUSE_ROOT') ?: 'data/warehouse';
        $this->path = $path ?? (rtrim($root, '/') . '/.duckdb_config.json');
    }

    /**
     * @return array{
     *   binary:?string,
     *   parquetPath:?string,
     *   threads:?int,
     *   memoryLimit:?string,
     *   updated_at:?string
     * }
     */
    public function get(): array
    {
        $defaults = self::defaults();
        if (!is_file($this->path)) {
            return $defaults;
        }
        $raw = file_get_contents($this->path);
        if ($raw === false || trim($raw) === '') {
            return $defaults;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $defaults;
        }
        return self::normalize(array_merge($defaults, $decoded));
    }

    /**
     * Replace or merge config. Pass only keys you want to change when $replace=false.
     *
     * @param array<string, mixed> $input
     * @return array{
     *   binary:?string,
     *   parquetPath:?string,
     *   threads:?int,
     *   memoryLimit:?string,
     *   updated_at:?string
     * }
     */
    public function set(array $input, bool $replace = false): array
    {
        $base = $replace ? self::defaults() : $this->get();
        $merged = self::normalize(array_merge($base, $input));
        $merged['updated_at'] = gmdate('c');

        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create config dir: ' . $dir);
        }
        $json = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('failed to encode duckdb config');
        }
        if (file_put_contents($this->path, $json . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('failed to write duckdb config: ' . $this->path);
        }
        return $merged;
    }

    /** @return array{binary:?string,parquetPath:?string,threads:?int,memoryLimit:?string,updated_at:?string} */
    public function reset(): array
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
        return self::defaults();
    }

    /**
     * Overlay request body keys on top of stored config (request wins).
     *
     * @param array<string, mixed> $body
     * @return array{binary:?string,parquetPath:?string,threads:?int,memoryLimit:?string}
     */
    public function resolve(array $body): array
    {
        $cfg = $this->get();
        $out = [
            'binary' => $cfg['binary'],
            'parquetPath' => $cfg['parquetPath'],
            'threads' => $cfg['threads'],
            'memoryLimit' => $cfg['memoryLimit'],
        ];
        if (array_key_exists('binary', $body) && $body['binary'] !== null && $body['binary'] !== '') {
            $out['binary'] = (string) $body['binary'];
        }
        $pp = $body['parquetPath'] ?? $body['parquet_path'] ?? null;
        if (is_string($pp) && $pp !== '') {
            $out['parquetPath'] = $pp;
        }
        if (isset($body['threads']) && is_numeric($body['threads'])) {
            $out['threads'] = (int) $body['threads'];
        }
        $mem = $body['memoryLimit'] ?? $body['memory_limit'] ?? null;
        if (is_string($mem) && $mem !== '') {
            $out['memoryLimit'] = $mem;
        }
        return $out;
    }

    /**
     * @return array{binary:?string,parquetPath:?string,threads:?int,memoryLimit:?string,updated_at:?string}
     */
    public static function defaults(): array
    {
        $repoRoot = dirname(__DIR__, 2);
        return [
            'binary' => getenv('DUCKDB_BIN') ?: null,
            'parquetPath' => getenv('PARQUET_PATH') ?: ($repoRoot . '/data/dummy'),
            'threads' => (int) (getenv('DUCKDB_THREADS') ?: 2),
            'memoryLimit' => getenv('DUCKDB_MEMORY_LIMIT') ?: '512MB',
            'updated_at' => null,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{binary:?string,parquetPath:?string,threads:?int,memoryLimit:?string,updated_at:?string}
     */
    private static function normalize(array $data): array
    {
        $binary = $data['binary'] ?? null;
        $parquet = $data['parquetPath'] ?? $data['parquet_path'] ?? null;
        $threads = $data['threads'] ?? null;
        $mem = $data['memoryLimit'] ?? $data['memory_limit'] ?? null;
        $updated = $data['updated_at'] ?? null;

        if ($threads !== null && $threads !== '') {
            $threads = (int) $threads;
            if ($threads < 1 || $threads > 256) {
                throw new \InvalidArgumentException('threads must be 1..256');
            }
        } else {
            $threads = null;
        }

        if (is_string($mem)) {
            $mem = trim($mem);
            if ($mem === '') {
                $mem = null;
            } elseif (!preg_match('/^\d+(\.\d+)?\s*(B|KB|MB|GB|TB)$/i', $mem)) {
                throw new \InvalidArgumentException('memoryLimit must look like 512MB or 4GB');
            }
        } else {
            $mem = null;
        }

        return [
            'binary' => is_string($binary) && $binary !== '' ? $binary : null,
            'parquetPath' => is_string($parquet) && $parquet !== '' ? $parquet : null,
            'threads' => $threads,
            'memoryLimit' => $mem,
            'updated_at' => is_string($updated) ? $updated : null,
        ];
    }
}
