<?php

declare(strict_types=1);

namespace App\Duck;

/**
 * DuckDB access for the app.
 *
 * Priority:
 *  1. Sidecar (DUCKDB_SIDECAR_URL) — Python or Go HTTP DuckDB service
 *  2. pdo_duckdb — in-process PHP (tertiary ext)
 *  3. DuckDB CLI — local fallback
 *
 * Set DUCKDB_SIDECAR_KIND=go|python to force the driver label, or let /health detect it.
 */
final class Session
{
    private static ?\PDO $sharedPdo = null;

    private static ?string $sidecarKind = null;

    private readonly SidecarBackend|CliBackend|\PDO $backend;

    /** @var array{threads?:int,memory_limit?:string} */
    private array $queryOpts = [];

    public function __construct(?string $duckdbBin = null)
    {
        $sidecar = getenv('DUCKDB_SIDECAR_URL') ?: '';
        if ($sidecar !== '') {
            $this->backend = new SidecarBackend($sidecar);
            return;
        }
        if (extension_loaded('pdo_duckdb')) {
            $this->backend = self::pdo();
            return;
        }
        $this->backend = new CliBackend($duckdbBin ?? (getenv('DUCKDB_BIN') ?: 'duckdb'));
    }

    /**
     * Per-request DuckDB settings (same connection for Go sidecar).
     *
     * @param array{threads?:int,memory_limit?:string} $opts
     */
    public function withOptions(array $opts): self
    {
        $this->queryOpts = $opts;
        return $this;
    }

    public static function driver(): string
    {
        if ((getenv('DUCKDB_SIDECAR_URL') ?: '') !== '') {
            return self::sidecarKind() === 'go' ? 'go_sidecar' : 'python_sidecar';
        }
        if (extension_loaded('pdo_duckdb')) {
            return 'pdo_duckdb';
        }
        return 'cli';
    }

    /** @return 'go'|'python' */
    public static function sidecarKind(): string
    {
        if (self::$sidecarKind !== null) {
            return self::$sidecarKind;
        }
        $forced = strtolower(trim((string) (getenv('DUCKDB_SIDECAR_KIND') ?: '')));
        if ($forced === 'go' || $forced === 'python') {
            return self::$sidecarKind = $forced;
        }
        $base = rtrim((string) (getenv('DUCKDB_SIDECAR_URL') ?: ''), '/');
        if ($base === '') {
            return self::$sidecarKind = 'python';
        }
        $raw = @file_get_contents($base . '/health', false, stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => 2, 'ignore_errors' => true],
        ]));
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $client = strtolower((string) ($decoded['client'] ?? ''));
            if ($client === 'go') {
                return self::$sidecarKind = 'go';
            }
            if ($client === 'python') {
                return self::$sidecarKind = 'python';
            }
        }
        if (str_contains($base, ':8091')) {
            return self::$sidecarKind = 'go';
        }
        return self::$sidecarKind = 'python';
    }

    public static function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    /** @return list<array<string, mixed>> */
    public function query(string $sql): array
    {
        if ($this->backend instanceof SidecarBackend) {
            return $this->backend->query($sql, $this->queryOpts);
        }
        if ($this->backend instanceof CliBackend) {
            return $this->backend->query($this->wrapCliSql($sql));
        }

        [$setup, $select] = self::splitSetupAndSelect($sql);
        if ($setup !== '') {
            $this->backend->exec($setup);
        }
        $stmt = $this->backend->query($select);
        if ($stmt === false) {
            $err = $this->backend->errorInfo();
            throw new \RuntimeException('DuckDB query failed: ' . ($err[2] ?? 'unknown'));
        }
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return $rows;
    }

    /**
     * Run named SQL statements. When $parallel and sidecar: concurrent curl_multi.
     * Otherwise sequential (CLI / PDO / parallel=false).
     *
     * @param array<string, string> $namedSql
     * @return array<string, list<array<string, mixed>>>
     */
    public function queryMany(array $namedSql, bool $parallel = false): array
    {
        if ($namedSql === []) {
            return [];
        }
        if ($parallel && $this->backend instanceof SidecarBackend) {
            return $this->backend->queryMany($namedSql, $this->queryOpts);
        }
        $out = [];
        foreach ($namedSql as $name => $sql) {
            $out[$name] = $this->query($sql);
        }
        return $out;
    }

    public function exec(string $sql): void
    {
        if ($this->backend instanceof SidecarBackend) {
            $this->backend->exec($sql, $this->queryOpts);
            return;
        }
        if ($this->backend instanceof CliBackend) {
            $this->backend->exec($this->wrapCliSql($sql));
            return;
        }
        $body = rtrim($sql);
        if ($body === '') {
            return;
        }
        if (!str_ends_with($body, ';')) {
            $body .= ';';
        }
        if ($this->backend->exec($body) === false) {
            $err = $this->backend->errorInfo();
            throw new \RuntimeException('DuckDB exec failed: ' . ($err[2] ?? 'unknown'));
        }
    }

    private function wrapCliSql(string $sql): string
    {
        $prefix = '';
        if (isset($this->queryOpts['threads'])) {
            $prefix .= 'SET threads=' . (int) $this->queryOpts['threads'] . ";\n";
        }
        if (isset($this->queryOpts['memory_limit']) && $this->queryOpts['memory_limit'] !== '') {
            $prefix .= 'SET memory_limit=' . self::quote((string) $this->queryOpts['memory_limit']) . ";\n";
        }
        return $prefix . $sql;
    }

    private static function pdo(): \PDO
    {
        if (self::$sharedPdo instanceof \PDO) {
            return self::$sharedPdo;
        }

        $threads = getenv('DUCKDB_THREADS') ?: '4';
        $mem = getenv('DUCKDB_MEMORY_LIMIT') ?: '4GB';
        $dsn = 'duckdb::memory:;threads=' . $threads . ';memory_limit=' . $mem;

        $pdo = new \PDO($dsn, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_PERSISTENT => true,
        ]);
        $pdo->exec("SET TimeZone='UTC'");
        self::$sharedPdo = $pdo;
        return $pdo;
    }

    /**
     * @return array{0:string,1:string}
     */
    private static function splitSetupAndSelect(string $sql): array
    {
        $sql = trim($sql);
        if ($sql === '') {
            return ['', 'SELECT 1 WHERE FALSE'];
        }
        if (!preg_match('/;\s*\S/s', $sql)) {
            return ['', rtrim($sql, " \t\n\r;")];
        }
        $parts = preg_split('/;\s*(?=(?:[^\'"]|\'[^\']*\'|"[^"]*")*$)/', $sql) ?: [];
        $stmts = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $stmts[] = $part;
            }
        }
        if ($stmts === []) {
            return ['', 'SELECT 1 WHERE FALSE'];
        }
        $select = array_pop($stmts);
        $setup = $stmts === [] ? '' : implode(";\n", $stmts) . ';';
        return [$setup, $select];
    }
}
