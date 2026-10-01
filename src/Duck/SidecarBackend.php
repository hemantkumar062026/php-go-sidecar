<?php

declare(strict_types=1);

namespace App\Duck;

/** HTTP client for the DuckDB sidecar (Python or Go). */
final class SidecarBackend
{
    private readonly string $baseUrl;

    public function __construct(string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * @param array{threads?:int,memory_limit?:string} $opts
     * @return list<array<string, mixed>>
     */
    public function query(string $sql, array $opts = []): array
    {
        $payload = $this->post('/query', $this->payload($sql, $opts));
        $rows = $payload['rows'] ?? [];
        if (!is_array($rows)) {
            throw new \RuntimeException('Sidecar returned invalid rows');
        }
        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }

    /**
     * Run many /query calls concurrently via curl_multi (falls back to sequential).
     *
     * @param array<string, string> $namedSql map of name → SQL
     * @param array{threads?:int,memory_limit?:string} $opts
     * @return array<string, list<array<string, mixed>>>
     */
    public function queryMany(array $namedSql, array $opts = []): array
    {
        if ($namedSql === []) {
            return [];
        }
        if (!function_exists('curl_multi_init') || count($namedSql) === 1) {
            $out = [];
            foreach ($namedSql as $name => $sql) {
                $out[$name] = $this->query($sql, $opts);
            }
            return $out;
        }

        // Cap fan-out so we don't OOM the DuckDB pool under parallel=true.
        $maxConcurrent = (int) (getenv('DUCKDB_PARALLEL_MAX') ?: 8);
        if ($maxConcurrent < 1) {
            $maxConcurrent = 1;
        }

        $out = [];
        $chunk = [];
        $n = 0;
        foreach ($namedSql as $name => $sql) {
            $chunk[$name] = $sql;
            $n++;
            if ($n >= $maxConcurrent) {
                $out += $this->queryManyBatch($chunk, $opts);
                $chunk = [];
                $n = 0;
            }
        }
        if ($chunk !== []) {
            $out += $this->queryManyBatch($chunk, $opts);
        }

        $ordered = [];
        foreach ($namedSql as $name => $_) {
            $ordered[$name] = $out[$name] ?? [];
        }
        return $ordered;
    }

    /**
     * @param array<string, string> $namedSql
     * @param array{threads?:int,memory_limit?:string} $opts
     * @return array<string, list<array<string, mixed>>>
     */
    private function queryManyBatch(array $namedSql, array $opts): array
    {
        $url = $this->baseUrl . '/query';
        $timeout = (float) (getenv('DUCKDB_SIDECAR_TIMEOUT') ?: 600);
        $mh = curl_multi_init();
        if ($mh === false) {
            throw new \RuntimeException('curl_multi_init failed');
        }

        /** @var list<array{name:string, ch:\CurlHandle}> $handles */
        $handles = [];
        foreach ($namedSql as $name => $sql) {
            $json = json_encode($this->payload($sql, $opts), JSON_THROW_ON_ERROR);
            $ch = curl_init($url);
            if ($ch === false) {
                throw new \RuntimeException('curl_init failed');
            }
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => (int) ceil($timeout),
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[] = ['name' => (string) $name, 'ch' => $ch];
        }

        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $out = [];
        $errors = [];
        foreach ($handles as $item) {
            $ch = $item['ch'];
            $name = $item['name'];
            $raw = curl_multi_getcontent($ch);
            $errno = curl_errno($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $ch);

            if ($errno !== 0 || !is_string($raw) || $raw === '') {
                $errors[] = $name . ': ' . ($errno ? curl_strerror($errno) : 'empty response');
                continue;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                $errors[] = $name . ': non-JSON response';
                continue;
            }
            if ($http >= 400) {
                $errors[] = $name . ': ' . (string) ($decoded['error'] ?? $raw);
                continue;
            }
            $rows = $decoded['rows'] ?? [];
            if (!is_array($rows)) {
                $errors[] = $name . ': invalid rows';
                continue;
            }
            /** @var list<array<string, mixed>> $rows */
            $out[$name] = $rows;
        }
        curl_multi_close($mh);

        if ($errors !== []) {
            throw new \RuntimeException('Sidecar parallel query failed: ' . implode('; ', $errors));
        }
        return $out;
    }

    /** @param array{threads?:int,memory_limit?:string} $opts */
    public function exec(string $sql, array $opts = []): void
    {
        $this->post('/exec', $this->payload($sql, $opts));
    }

    /**
     * @param array{threads?:int,memory_limit?:string} $opts
     * @return array<string, mixed>
     */
    private function payload(string $sql, array $opts): array
    {
        $body = ['sql' => $sql];
        if (isset($opts['threads'])) {
            $body['threads'] = (int) $opts['threads'];
        }
        if (isset($opts['memory_limit']) && $opts['memory_limit'] !== '') {
            $body['memory_limit'] = (string) $opts['memory_limit'];
        }
        return $body;
    }

    /** @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function post(string $path, array $body): array
    {
        $url = $this->baseUrl . $path;
        $json = json_encode($body, JSON_THROW_ON_ERROR);
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => $json,
                'timeout' => (float) (getenv('DUCKDB_SIDECAR_TIMEOUT') ?: 600),
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            throw new \RuntimeException('DuckDB sidecar unreachable at ' . $url . '. Start it with: ./bin/go-sidecar');
        }
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Sidecar non-JSON response: ' . $raw);
        }
        if ($status >= 400) {
            throw new \RuntimeException('Sidecar error: ' . (string) ($decoded['error'] ?? $raw));
        }
        return $decoded;
    }
}
