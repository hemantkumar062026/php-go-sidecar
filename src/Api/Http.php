<?php

declare(strict_types=1);

namespace App\Api;

use App\Bulk\Importer;
use App\Compact\Service as CompactService;
use App\Config\DuckdbConfig;
use App\Duck\Session;
use App\Filter\CampaignPreview;
use App\Filter\QueryParser;
use App\Pipeline\Service as Pipeline;
use App\Schema\ContactAttrs;
use App\Schema\SftpContact;
use App\Store\Query;
use App\Warehouse\Paths;
use App\Write\Locks;

final class Http
{
    private Paths $wh;
    private Query $store;
    private Importer $bulk;
    private CompactService $compact;
    private Pipeline $pipeline;
    private DuckdbConfig $duckCfg;

    public function __construct()
    {
        $root = getenv('WAREHOUSE_ROOT') ?: 'data/warehouse';
        $this->wh = Paths::fromRoot($root);
        $locks = new Locks($this->wh->root . '/.locks');
        $this->store = new Query($this->wh);
        $this->bulk = new Importer($this->wh, $locks);
        $this->compact = new CompactService($this->wh, $locks);
        $this->pipeline = new Pipeline($this->wh, $locks, $this->store);
        $this->duckCfg = new DuckdbConfig($this->wh->root . '/.duckdb_config.json');
    }

    public function handle(): void
    {
        $this->cors();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            http_response_code(204);
            return;
        }
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $path = rtrim($path, '/') ?: '/';

        try {
            $this->dispatch($method, $path);
        } catch (\InvalidArgumentException $e) {
            $this->error(400, $e->getMessage());
        } catch (\Throwable $e) {
            $this->error(500, $e->getMessage());
        }
    }

    private function dispatch(string $method, string $path): void
    {
        if ($method === 'GET' && $path === '/api/health') {
            $this->json(200, [
                'ok' => true,
                'warehouse' => $this->wh->root,
                'engine' => 'duckdb',
                'driver' => \App\Duck\Session::driver(),
                'sidecar' => getenv('DUCKDB_SIDECAR_URL') ?: null,
                'persistent' => in_array(\App\Duck\Session::driver(), ['pdo_duckdb', 'python_sidecar', 'go_sidecar'], true),
            ]);
            return;
        }
        if ($method === 'GET' && ($path === '/campaign' || $path === '/campaign.html')) {
            $file = dirname(__DIR__, 2) . '/public/campaign.html';
            if (!is_file($file)) {
                $this->error(404, 'campaign UI not found');
                return;
            }
            http_response_code(200);
            header('Content-Type: text/html; charset=utf-8');
            readfile($file);
            return;
        }
        if ($method === 'GET' && $path === '/api/schema/contacts') {
            $this->json(200, [
                'columns' => ContactAttrs::all(),
                'filterable' => ContactAttrs::filterable(),
                'profile' => ContactAttrs::profile(),
            ]);
            return;
        }
        if ($method === 'GET' && $path === '/api/schema/sftp-contact') {
            $this->json(200, [
                'table' => 'sftp_contact_{account_id}',
                'columns' => SftpContact::columns(),
            ]);
            return;
        }
        // DuckDB runtime config (binary / parquetPath / threads / memoryLimit)
        if ($method === 'GET' && $path === '/api/sftp/config') {
            $this->json(200, [
                'ok' => true,
                'config' => $this->duckCfg->get(),
            ]);
            return;
        }
        if (($method === 'POST' || $method === 'PUT') && $path === '/api/sftp/config') {
            $body = $this->body();
            $replace = (bool) ($body['replace'] ?? false);
            $cfg = $this->duckCfg->set($body, $replace);
            $this->json(200, [
                'ok' => true,
                'config' => $cfg,
            ]);
            return;
        }
        if ($method === 'DELETE' && $path === '/api/sftp/config') {
            $this->json(200, [
                'ok' => true,
                'config' => $this->duckCfg->reset(),
            ]);
            return;
        }
        // Parse values → SQL only (no execute)
        if ($method === 'POST' && $path === '/api/sftp/parse') {
            $body = $this->body();
            $cfg = $this->duckCfg->resolve($body);
            $parsed = QueryParser::parse($this->sftpParseInput($body, $cfg));
            $this->json(200, ['duckdb' => $cfg, 'parsed' => $parsed]);
            return;
        }
        // Parse + execute via DuckDB sidecar (point DUCKDB_SIDECAR_URL at Go :8091)
        if ($method === 'POST' && $path === '/api/sftp/query') {
            $body = $this->body();
            $mode = strtolower((string) ($body['mode'] ?? 'count'));
            $cfg = $this->duckCfg->resolve($body);
            $input = $this->sftpParseInput($body, $cfg);
            $parsed = QueryParser::parse($input);
            $bin = (string) ($cfg['binary'] ?? '');
            $duck = new Session($bin !== '' ? $bin : null);
            $opts = [];
            if ($cfg['threads'] !== null) {
                $opts['threads'] = (int) $cfg['threads'];
            }
            if ($cfg['memoryLimit'] !== null && $cfg['memoryLimit'] !== '') {
                $opts['memory_limit'] = (string) $cfg['memoryLimit'];
            }
            if ($opts !== []) {
                $duck->withOptions($opts);
            }
            $out = [
                'driver' => Session::driver(),
                'sidecar' => getenv('DUCKDB_SIDECAR_URL') ?: null,
                'kind' => $input['kind'] ?? null,
                'duckdb' => [
                    'binary' => $cfg['binary'],
                    'parquetPath' => $cfg['parquetPath'],
                    'threads' => $cfg['threads'],
                    'memoryLimit' => $cfg['memoryLimit'],
                ],
                'parsed' => $parsed,
            ];
            if ($mode === 'count' || $mode === 'both') {
                $out['count'] = $duck->query($parsed['count_sql']);
            }
            if ($mode === 'select' || $mode === 'both') {
                $out['rows'] = $duck->query($parsed['select_sql']);
            }
            $this->json(200, $out);
            return;
        }
        // Campaign audience breakdown (counts only)
        if ($method === 'POST' && $path === '/api/sftp/campaign-preview') {
            $body = $this->body();
            $cfg = $this->duckCfg->resolve($body);
            $input = $this->sftpParseInput(array_merge($body, [
                'kind' => 'campaign',
                'account_id' => (int) ($body['account_id'] ?? 0),
            ]), $cfg);
            if ((int) ($input['account_id'] ?? 0) <= 0) {
                throw new \InvalidArgumentException('account_id is required and must be > 0');
            }
            if (isset($body['segment_defs']) && is_array($body['segment_defs'])) {
                $input['segment_defs'] = $body['segment_defs'];
            }
            $bin = (string) ($cfg['binary'] ?? '');
            $duck = new Session($bin !== '' ? $bin : null);
            $opts = [];
            if ($cfg['threads'] !== null) {
                $opts['threads'] = (int) $cfg['threads'];
            }
            if ($cfg['memoryLimit'] !== null && $cfg['memoryLimit'] !== '') {
                $opts['memory_limit'] = (string) $cfg['memoryLimit'];
            }
            if ($opts !== []) {
                $duck->withOptions($opts);
            }
            $result = CampaignPreview::run($body, $input, $duck);
            $this->json(200, [
                'ok' => true,
                'driver' => Session::driver(),
                'sidecar' => getenv('DUCKDB_SIDECAR_URL') ?: null,
                'duckdb' => [
                    'binary' => $cfg['binary'],
                    'parquetPath' => $cfg['parquetPath'],
                    'threads' => $cfg['threads'],
                    'memoryLimit' => $cfg['memoryLimit'],
                ],
                'campaign' => $result['campaign'],
                'metrics' => $result['metrics'],
                'sql' => $result['sql'],
            ]);
            return;
        }
        if ($method === 'GET' && $path === '/api/contacts') {
            $account = $this->queryInt('account_id', 42);
            $cols = [];
            $raw = (string) ($_GET['columns'] ?? '');
            if ($raw !== '') {
                $cols = explode(',', $raw);
            }
            $this->json(200, $this->store->listContacts(
                $account,
                (string) ($_GET['q'] ?? ''),
                (int) ($_GET['limit'] ?? 25),
                (int) ($_GET['after_id'] ?? 0),
                (int) ($_GET['offset'] ?? 0),
                $cols,
            ));
            return;
        }
        if ($method === 'GET' && $path === '/api/segments') {
            $this->json(200, $this->store->listSegments($this->queryInt('account_id', 42)));
            return;
        }
        if ($method === 'POST' && $path === '/api/segments/preview') {
            $body = $this->body();
            $account = (int) ($body['account_id'] ?? 42);
            $filter = is_array($body['filter'] ?? null) ? $body['filter'] : [];
            $this->json(200, $this->store->previewSegment($account, $filter, (int) ($body['limit'] ?? 20)));
            return;
        }
        if ($method === 'POST' && $path === '/api/segments') {
            $body = $this->body();
            $account = (int) ($body['account_id'] ?? 42);
            $name = trim((string) ($body['name'] ?? ''));
            if ($name === '') {
                $name = 'Untitled segment';
            }
            $filter = is_array($body['filter'] ?? null) ? $body['filter'] : ['op' => 'and', 'children' => []];
            $this->json(201, $this->pipeline->saveSegment($account, $name, $filter));
            return;
        }
        if ($method === 'GET' && $path === '/api/campaigns') {
            $this->json(200, $this->store->listCampaigns($this->queryInt('account_id', 42)));
            return;
        }
        if ($method === 'POST' && $path === '/api/campaigns') {
            $body = $this->body();
            $name = trim((string) ($body['name'] ?? ''));
            $segment = trim((string) ($body['segment_id'] ?? ''));
            if ($name === '' || $segment === '') {
                $this->error(400, 'name and segment_id required');
                return;
            }
            $this->json(201, $this->pipeline->createCampaign(
                (int) ($body['account_id'] ?? 42),
                $name,
                (string) ($body['channel'] ?? 'email'),
                $segment,
            ));
            return;
        }
        if (preg_match('#^/api/campaigns/(\d+)$#', $path, $m) === 1 && $method === 'GET') {
            $row = $this->store->getCampaign($this->queryInt('account_id', 42), (int) $m[1]);
            if ($row === null) {
                $this->error(404, 'not found');
                return;
            }
            $this->json(200, $row);
            return;
        }
        if (preg_match('#^/api/campaigns/(\d+)$#', $path, $m) === 1 && ($method === 'PATCH' || $method === 'PUT')) {
            $body = $this->body();
            $account = (int) ($body['account_id'] ?? $this->queryInt('account_id', 0));
            if ($account <= 0) {
                throw new \InvalidArgumentException('account_id required');
            }
            $updated = $this->pipeline->updateCampaign($account, (int) $m[1], $body);
            $this->json(200, $updated);
            return;
        }
        if (preg_match('#^/api/campaigns/(\d+)/launch$#', $path, $m) === 1 && $method === 'POST') {
            $this->json(200, $this->pipeline->launchCampaign($this->queryInt('account_id', 42), (int) $m[1]));
            return;
        }
        if (preg_match('#^/api/campaigns/(\d+)/stats$#', $path, $m) === 1 && $method === 'GET') {
            $this->json(200, $this->store->campaignStats((int) $m[1]));
            return;
        }
        if (preg_match('#^/api/campaigns/(\d+)/subscribers$#', $path, $m) === 1 && $method === 'GET') {
            $status = isset($_GET['status']) && $_GET['status'] !== '' ? (int) $_GET['status'] : null;
            $this->json(200, $this->store->listSubscribers(
                (int) $m[1],
                (string) ($_GET['q'] ?? ''),
                $status,
                (int) ($_GET['limit'] ?? 50),
                (int) ($_GET['offset'] ?? 0),
            ));
            return;
        }
        if (preg_match('#^/api/campaigns/(\d+)/simulate-dlr$#', $path, $m) === 1 && $method === 'POST') {
            $this->simulate((int) $m[1]);
            return;
        }
        if ($method === 'POST' && $path === '/api/webhooks/dlr/email') {
            $this->webhook('email');
            return;
        }
        if ($method === 'POST' && $path === '/api/webhooks/dlr/sms') {
            $this->webhook('sms');
            return;
        }
        if ($method === 'GET' && $path === '/api/engine/stats') {
            $this->json(200, [
                'api_logs_kafka' => false,
                'api_logs_mode' => 'parquet-direct',
                'note' => 'DLR rows are written to Parquet immediately. Kafka batching from the Go service is not running in this PHP port.',
            ]);
            return;
        }
        if ($method === 'GET' && $path === '/api/admin/warehouse') {
            $this->json(200, $this->compact->warehouseStats());
            return;
        }
        if ($method === 'POST' && $path === '/api/admin/compact/contact') {
            $body = $this->body();
            $account = (int) ($body['account_id'] ?? 42);
            if ($account === 0) {
                $account = 42;
            }
            $this->json(200, $this->compact->compactContact($account));
            return;
        }
        if ($method === 'POST' && $path === '/api/admin/compact/campaign-sub') {
            $body = $this->body();
            $id = (int) ($body['campaign_id'] ?? 0);
            if ($id === 0) {
                $this->error(400, 'campaign_id required');
                return;
            }
            $this->json(200, $this->compact->compactCampaignSub($id));
            return;
        }
        if ($method === 'POST' && $path === '/api/admin/reset') {
            $this->reset();
            return;
        }
        if ($method === 'GET' && $path === '/api/api-logs') {
            $status = isset($_GET['status']) && $_GET['status'] !== '' ? (int) $_GET['status'] : null;
            $this->json(200, $this->store->listApiLogs(
                (string) ($_GET['dt'] ?? ''),
                $this->queryInt('channel_id', 0),
                $status,
                (string) ($_GET['q'] ?? ''),
                (int) ($_GET['limit'] ?? 50),
                (int) ($_GET['offset'] ?? 0),
            ));
            return;
        }
        if ($method === 'GET' && $path === '/api/bulk/demo-files') {
            $this->json(200, $this->demoFiles());
            return;
        }
        if ($method === 'POST' && $path === '/api/bulk/import') {
            $body = $this->body();
            $table = (string) ($body['table'] ?? '');
            $pathValue = (string) ($body['path'] ?? '');
            if ($table === '' || $pathValue === '') {
                $this->error(400, 'table and path required');
                return;
            }
            $abs = realpath($pathValue);
            if ($abs === false) {
                $this->error(400, 'file not found: ' . $pathValue);
                return;
            }
            $this->json(200, $this->runBulk(
                $table,
                $abs,
                (int) ($body['account_id'] ?? 42) ?: 42,
                (int) ($body['campaign_id'] ?? 1001) ?: 1001,
                (string) ($body['mode'] ?? 'snapshot'),
                (string) ($body['dt'] ?? ''),
            ));
            return;
        }
        if ($method === 'POST' && $path === '/api/bulk/upload') {
            $this->upload();
            return;
        }
        $this->error(404, 'not found');
    }

    private function webhook(string $channel): void
    {
        $body = $this->body();
        $messageId = trim((string) ($body['message_id'] ?? ''));
        if ($messageId === '') {
            $this->error(400, 'message_id required');
            return;
        }
        $this->pipeline->applyDlr(
            $channel,
            $messageId,
            (int) ($body['campaign_id'] ?? 0),
            (int) ($body['status'] ?? 0),
            (string) ($body['code'] ?? ''),
            (string) ($body['reason'] ?? ''),
        );
        $this->json(200, ['ok' => true, 'message_id' => $messageId]);
    }

    private function simulate(int $campaignId): void
    {
        $body = $this->body();
        $account = (int) ($body['account_id'] ?? 42);
        $camp = $this->store->getCampaign($account, $campaignId);
        if ($camp === null) {
            $this->error(404, 'campaign not found');
            return;
        }
        $channel = (string) ($body['channel'] ?? $camp['channel'] ?? 'email');
        $delivered = $channel === 'sms' ? 10 : 3;
        $rows = $this->store->listSubscribers($campaignId, '', 0, 50, 0);
        $n = 0;
        foreach ($rows as $row) {
            $this->pipeline->applyDlr(
                $channel,
                (string) $row['message_id'],
                $campaignId,
                $delivered,
                $channel === 'sms' ? 'DELIVRD' : 'delivered',
                'sandbox',
            );
            $n++;
        }
        $this->json(200, ['submitted' => $n, 'status' => $delivered]);
    }

    private function upload(): void
    {
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->error(400, 'file required');
            return;
        }
        $tmpDir = sys_get_temp_dir() . '/php-parquet-bulk';
        if (!is_dir($tmpDir) && !mkdir($tmpDir, 0755, true) && !is_dir($tmpDir)) {
            throw new \RuntimeException('Cannot create upload dir');
        }
        $name = basename((string) ($file['name'] ?? 'upload.csv'));
        $dest = $tmpDir . '/' . time() . '-' . $name;
        if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
            $this->error(500, 'failed to store upload');
            return;
        }
        try {
            $this->json(200, $this->runBulk(
                (string) ($_POST['table'] ?? ''),
                $dest,
                (int) ($_POST['account_id'] ?? 42) ?: 42,
                (int) ($_POST['campaign_id'] ?? 1001) ?: 1001,
                (string) ($_POST['mode'] ?? 'snapshot'),
                (string) ($_POST['dt'] ?? ''),
            ));
        } finally {
            @unlink($dest);
        }
    }

    /** @return array{table:string,rows:int,mode:string,output_path:string,source:string,elapsed_ms:int} */
    private function runBulk(string $table, string $path, int $accountId, int $campaignId, string $mode, string $dt): array
    {
        return match (strtolower($table)) {
            'contact', 'contacts' => $this->bulk->importContacts($path, $accountId, $mode),
            'campaign_sub', 'subscribers' => $this->bulk->importCampaignSub($path, $campaignId, $mode),
            'campaign_activity', 'activity' => $this->bulk->importActivity($path, $dt),
            default => throw new \InvalidArgumentException('unknown table "' . $table . '" (contact|campaign_sub|campaign_activity)'),
        };
    }

    /** @return list<array{path:string,name:string,size:int}> */
    private function demoFiles(): array
    {
        $out = [];
        foreach (['data/demo', 'demo/samples'] as $root) {
            $abs = realpath($root);
            if ($abs === false || !is_dir($abs)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                    continue;
                }
                $lower = strtolower($file->getFilename());
                $ok = str_ends_with($lower, '.csv') || str_ends_with($lower, '.parquet')
                    || str_ends_with($lower, '.tsv') || str_ends_with($lower, '.csv.gz');
                if (!$ok) {
                    continue;
                }
                $cwd = getcwd() ?: '';
                $rel = $cwd !== '' ? ltrim(str_replace($cwd, '', $file->getPathname()), '/') : $file->getPathname();
                $out[] = ['path' => $rel, 'name' => $file->getFilename(), 'size' => $file->getSize()];
            }
        }
        return $out;
    }

    private function reset(): void
    {
        $root = $this->wh->root;
        if (is_dir($root)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $file) {
                if ($file->isDir()) {
                    @rmdir($file->getPathname());
                } else {
                    @unlink($file->getPathname());
                }
            }
        }
        if (!is_dir($root)) {
            mkdir($root, 0755, true);
        }
        $this->json(200, ['reset' => true, 'warehouse' => $root]);
    }

    private function cors(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Accept, Authorization, Content-Type');
    }

    /**
     * Query body focuses on filters; DuckDB settings come from /api/sftp/config
     * (request may still override a single call).
     *
     * @param array<string, mixed> $body
     * @param array{binary:?string,parquetPath:?string,threads:?int,memoryLimit:?string}|null $cfg
     * @return array<string, mixed>
     */
    private function sftpParseInput(array $body, ?array $cfg = null): array
    {
        $cfg ??= $this->duckCfg->resolve($body);
        $accountId = (int) ($body['account_id'] ?? 0);
        $dialect = strtolower((string) ($body['dialect'] ?? 'duckdb'));
        $kind = strtolower(trim((string) ($body['kind'] ?? '')));
        if ($kind === '' && isset($body['campaign'])) {
            $kind = 'campaign';
        }
        if ($kind === '' && isset($body['segment'])) {
            $kind = 'segment';
        }

        $input = [
            'account_id' => $accountId,
            'dialect' => $dialect,
            'kind' => $kind !== '' ? $kind : null,
            'select' => $body['select'] ?? null,
            'limit' => $body['limit'] ?? 50,
            'offset' => $body['offset'] ?? 0,
            'order_by' => $body['order_by'] ?? 'id',
            'order_dir' => $body['order_dir'] ?? 'ASC',
            'include_deleted' => (bool) ($body['include_deleted'] ?? false),
        ];

        if ($kind === 'campaign' && isset($body['campaign']) && is_array($body['campaign'])) {
            $input['filter'] = $body['campaign'];
        } elseif (($kind === 'segment' || $kind === '') && isset($body['segment']) && is_array($body['segment'])) {
            $input['filter'] = $body['segment'];
        } elseif (isset($body['filter']) && is_array($body['filter'])) {
            $input['filter'] = $body['filter'];
        } elseif (isset($body['values']) && is_array($body['values'])) {
            $input['values'] = $body['values'];
        } else {
            $input['values'] = [];
        }

        if (isset($body['segment_defs']) && is_array($body['segment_defs'])) {
            $input['segment_defs'] = $body['segment_defs'];
        }
        if (isset($body['block_match_field'])) {
            $input['block_match_field'] = (string) $body['block_match_field'];
        }

        if ($dialect === 'duckdb') {
            $repoRoot = dirname(__DIR__, 2);
            $parquetPath = (string) ($cfg['parquetPath'] ?? '');
            if ($parquetPath === '') {
                $oneCr = $repoRoot . '/data/dummy_1cr';
                $parquetPath = is_dir($oneCr) ? $oneCr : ($repoRoot . '/data/dummy');
            }
            $input['parquet_path'] = $parquetPath;

            $glob = (string) ($body['lake_glob'] ?? getenv('LAKE_GLOB') ?: '');
            if ($glob === '') {
                $glob = rtrim($parquetPath, '/') . '/contact/**/*.parquet';
            }
            $input['from'] = "read_parquet('" . str_replace("'", "''", $glob)
                . "', hive_partitioning=true, union_by_name=true)";

            $blockGlob = (string) (
                $body['block_file_glob']
                ?? getenv('BLOCK_FILE_GLOB')
                ?: ''
            );
            if ($blockGlob === '') {
                $blockGlob = rtrim($parquetPath, '/') . '/block_file_data/*.parquet';
            }
            $input['block_file_glob'] = $blockGlob;

            $blockTableGlob = (string) (
                $body['block_table_glob']
                ?? getenv('BLOCK_TABLE_GLOB')
                ?: ''
            );
            if ($blockTableGlob === '') {
                $blockTableGlob = rtrim($parquetPath, '/') . '/block_table/**/*.parquet';
            }
            $input['block_table_glob'] = $blockTableGlob;
        } elseif (isset($body['schema'])) {
            $input['schema'] = (string) $body['schema'];
        }
        return $input;
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('invalid json');
        }
        return $decoded;
    }

    private function queryInt(string $key, int $default): int
    {
        $value = $_GET[$key] ?? '';
        if ($value === '' || $value === null) {
            return $default;
        }
        return (int) $value;
    }

    /** @param array<string, mixed>|list<mixed> $payload */
    private function json(int $status, array $payload): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    }

    private function error(int $status, string $message): void
    {
        $this->json($status, ['error' => $message]);
    }
}
