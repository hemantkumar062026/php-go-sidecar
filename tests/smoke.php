<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Bulk\Importer;
use App\Compact\Service as Compact;
use App\Pipeline\Service as Pipeline;
use App\Store\Query;
use App\Warehouse\Paths;
use App\Write\Locks;

$root = dirname(__DIR__) . '/data/warehouse';
if (is_dir($root)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
}

$wh = Paths::fromRoot($root);
$locks = new Locks($wh->root . '/.locks');
$store = new Query($wh);
$bulk = new Importer($wh, $locks);
$compact = new Compact($wh, $locks);
$pipe = new Pipeline($wh, $locks, $store);

$sample = dirname(__DIR__) . '/demo/samples/contacts_sample.csv';
function check(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$imported = $bulk->importContacts($sample, 42, 'snapshot');
check($imported['rows'] === 5, 'expected 5 imported rows, got ' . $imported['rows']);

$page = $store->listContacts(42, '', 10, 0, 0, ['id', 'email', 'country']);
check(count($page['rows']) === 5, 'expected 5 listed rows, got ' . count($page['rows']));

$preview = $store->previewSegment(42, ['op' => 'eq', 'field' => 'country', 'value' => 'IN'], 10);
check($preview['count'] === 2, 'expected 2 IN contacts, got ' . $preview['count']);

$seg = $pipe->saveSegment(42, 'India', ['op' => 'eq', 'field' => 'country', 'value' => 'IN']);
$camp = $pipe->createCampaign(42, 'Hello', 'email', $seg['id']);
$launch = $pipe->launchCampaign(42, (int) $camp['id']);
check($launch['queued'] === 2, 'expected 2 queued, got ' . $launch['queued']);

$subs = $store->listSubscribers((int) $camp['id'], '', null, 10, 0);
check(count($subs) === 2, 'expected 2 subscribers, got ' . count($subs));
$pipe->applyDlr('email', (string) $subs[0]['message_id'], (int) $camp['id'], 3, 'delivered', 'smoke');

$after = $store->getSubscriberByMessageId((string) $subs[0]['message_id']);
check($after !== null && (int) $after['status'] === 3, 'DLR did not update status');

$logs = $store->listApiLogs('', 0, null, '', 10, 0);
check(count($logs) === 1, 'expected 1 api log, got ' . count($logs));

$compacted = $compact->compactContact(42);
check($compacted['rows_written'] === 5, 'compact row count ' . $compacted['rows_written']);

$stats = $compact->warehouseStats();
check($stats['contact_snapshot_files'] >= 1, 'snapshot missing after compact');
check($stats['api_logs_files'] === 1, 'api log file missing');

echo "smoke ok\n";
echo json_encode([
    'imported' => $imported['rows'],
    'india' => $preview['count'],
    'queued' => $launch['queued'],
    'compacted' => $compacted['rows_written'],
    'stats' => $stats,
], JSON_PRETTY_PRINT), "\n";
