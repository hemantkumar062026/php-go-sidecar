<?php

declare(strict_types=1);

namespace App\Duck;

/** HTTP client for the official Python DuckDB sidecar. */
final class SidecarBackend
{
    private readonly string $baseUrl;

    public function __construct(string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /** @return list<array<string, mixed>> */
    public function query(string $sql): array
    {
        $payload = $this->post('/query', ['sql' => $sql]);
        $rows = $payload['rows'] ?? [];
        if (!is_array($rows)) {
            throw new \RuntimeException('Sidecar returned invalid rows');
        }
        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }

    public function exec(string $sql): void
    {
        $this->post('/exec', ['sql' => $sql]);
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
            throw new \RuntimeException('DuckDB sidecar unreachable at ' . $url . '. Start it with: ./bin/sidecar');
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
