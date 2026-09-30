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
        $raw = file_get_contents($url, false, $ctx);
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
