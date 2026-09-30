<?php

declare(strict_types=1);

namespace App\Duck;

/** CLI fallback when pdo_duckdb is not installed. */
final class CliBackend
{
    public function __construct(private readonly string $bin)
    {
    }

    /** @return list<array<string, mixed>> */
    public function query(string $sql): array
    {
        $raw = $this->run($sql, true);
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return [];
        }
        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('DuckDB CLI returned non-JSON: ' . $trimmed);
        }
        /** @var list<array<string, mixed>> $decoded */
        return $decoded;
    }

    public function exec(string $sql): void
    {
        $this->run($sql, false);
    }

    private function run(string $sql, bool $expectRows): string
    {
        $body = rtrim($sql);
        if (!str_ends_with($body, ';')) {
            $body .= ';';
        }
        $script = "SET TimeZone='UTC';\n" . $body . "\n";
        if (!$expectRows) {
            $script .= "SELECT 1 AS _ok;\n";
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open([$this->bin, '-json', '-bail'], $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start DuckDB CLI. Install pdo_duckdb or the duckdb binary.');
        }
        fwrite($pipes[0], $script);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) {
            $detail = trim($stderr !== false ? $stderr : '');
            if ($detail === '') {
                $detail = trim($stdout !== false ? $stdout : '');
            }
            throw new \RuntimeException('DuckDB CLI failed: ' . $detail);
        }
        return $stdout !== false ? $stdout : '';
    }
}
