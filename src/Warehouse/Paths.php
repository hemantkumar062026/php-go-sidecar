<?php

declare(strict_types=1);

namespace App\Warehouse;

/** Hive-style Parquet layout. Same folders as the Go TempCodeShare warehouse. */
final class Paths
{
    public function __construct(public readonly string $root)
    {
    }

    public static function fromRoot(string $root): self
    {
        $abs = realpath($root);
        if ($abs === false) {
            if (!is_dir($root) && !mkdir($root, 0755, true) && !is_dir($root)) {
                throw new \RuntimeException('Cannot create warehouse root: ' . $root);
            }
            $abs = realpath($root);
        }
        if ($abs === false) {
            throw new \RuntimeException('Cannot resolve warehouse root: ' . $root);
        }
        return new self($abs);
    }

    public function contactSnapshotDir(int $accountId): string
    {
        return $this->root . '/contact/snapshot/account_id=' . $accountId;
    }

    public function contactDeltaDir(int $accountId, ?\DateTimeInterface $day = null): string
    {
        $day ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $this->root . '/contact/delta/account_id=' . $accountId . '/delta_date=' . $day->format('Y-m-d');
    }

    public function contactSnapshotGlob(?int $accountId = null): string
    {
        if ($accountId === null) {
            return $this->root . '/contact/snapshot/account_id=*/*.parquet';
        }
        return $this->contactSnapshotDir($accountId) . '/*.parquet';
    }

    public function contactDeltaGlob(?int $accountId = null): string
    {
        if ($accountId === null) {
            return $this->root . '/contact/delta/account_id=*/delta_date=*/*.parquet';
        }
        return $this->root . '/contact/delta/account_id=' . $accountId . '/delta_date=*/*.parquet';
    }

    public function campaignSubSnapshotDir(int $campaignId): string
    {
        return $this->root . '/campaign_sub/snapshot/campaign_id=' . $campaignId;
    }

    public function campaignSubDeltaDir(int $campaignId, ?\DateTimeInterface $day = null): string
    {
        $day ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $this->root . '/campaign_sub/delta/campaign_id=' . $campaignId . '/delta_date=' . $day->format('Y-m-d');
    }

    public function campaignSubSnapshotGlob(?int $campaignId = null): string
    {
        if ($campaignId === null) {
            return $this->root . '/campaign_sub/snapshot/campaign_id=*/*.parquet';
        }
        return $this->campaignSubSnapshotDir($campaignId) . '/*.parquet';
    }

    public function campaignSubDeltaGlob(?int $campaignId = null): string
    {
        if ($campaignId === null) {
            return $this->root . '/campaign_sub/delta/campaign_id=*/delta_date=*/*.parquet';
        }
        return $this->root . '/campaign_sub/delta/campaign_id=' . $campaignId . '/delta_date=*/*.parquet';
    }

    public function campaignActivityDir(?\DateTimeInterface $day = null): string
    {
        $day ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $this->root . '/campaign_activity/dt=' . $day->format('Y-m-d');
    }

    public function campaignActivityGlob(): string
    {
        return $this->root . '/campaign_activity/dt=*/*.parquet';
    }

    public function segmentDir(int $accountId): string
    {
        return $this->root . '/segment/account_id=' . $accountId;
    }

    public function segmentGlob(): string
    {
        return $this->root . '/segment/account_id=*/*.parquet';
    }

    public function campaignMetaDir(int $accountId): string
    {
        return $this->root . '/campaign/snapshot/account_id=' . $accountId;
    }

    public function campaignMetaDeltaDir(int $accountId, ?\DateTimeInterface $day = null): string
    {
        $day ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $this->root . '/campaign/delta/account_id=' . $accountId . '/delta_date=' . $day->format('Y-m-d');
    }

    public function campaignMetaSnapshotGlob(): string
    {
        return $this->root . '/campaign/snapshot/account_id=*/*.parquet';
    }

    public function campaignMetaDeltaGlob(): string
    {
        return $this->root . '/campaign/delta/account_id=*/delta_date=*/*.parquet';
    }

    public function dlrEventDir(?\DateTimeInterface $day = null): string
    {
        $day ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $this->root . '/dlr_event/dt=' . $day->format('Y-m-d');
    }

    public function dlrEventGlob(): string
    {
        return $this->root . '/dlr_event/dt=*/*.parquet';
    }

    public function apiLogsDir(?\DateTimeInterface $day = null): string
    {
        $day ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $this->root . '/api_logs/dt=' . $day->format('Y-m-d');
    }

    public function apiLogsGlob(): string
    {
        return $this->root . '/api_logs/dt=*/*.parquet';
    }

    public static function newParquetName(string $prefix): string
    {
        return $prefix . '-' . self::uuid() . '.parquet';
    }

    public static function stagingPath(string $final): string
    {
        return $final . '.tmp';
    }

    public static function commitParquet(string $tmp, string $final): void
    {
        if (!is_file($tmp)) {
            if (is_file($final)) {
                return;
            }
            throw new \RuntimeException('Staging parquet missing: ' . $tmp);
        }
        if (!rename($tmp, $final)) {
            @unlink($tmp);
            throw new \RuntimeException('Failed to publish parquet: ' . $final);
        }
    }

    public static function abortParquet(string $tmp): void
    {
        if (is_file($tmp)) {
            @unlink($tmp);
        }
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    public function sweepStaleStaging(int $staleSeconds = 1800): void
    {
        if (!is_dir($this->root)) {
            return;
        }
        $cutoff = time() - $staleSeconds;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            if (!str_ends_with($file->getFilename(), '.parquet.tmp')) {
                continue;
            }
            if ($file->getMTime() < $cutoff) {
                @unlink($file->getPathname());
            }
        }
    }
}
