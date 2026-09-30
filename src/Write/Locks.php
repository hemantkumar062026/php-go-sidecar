<?php

declare(strict_types=1);

namespace App\Write;

/** Per-shard file locks so snapshot and delta writes do not interleave. */
final class Locks
{
    public function __construct(private readonly string $dir)
    {
        if (!is_dir($this->dir) && !mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('Cannot create lock dir: ' . $this->dir);
        }
    }

    /** @return \Closure():void */
    public function lock(string $key): \Closure
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $key) ?? 'lock';
        $path = $this->dir . '/' . $safe . '.lock';
        $handle = fopen($path, 'c');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open lock: ' . $path);
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new \RuntimeException('Cannot lock: ' . $key);
        }
        return static function () use ($handle): void {
            flock($handle, LOCK_UN);
            fclose($handle);
        };
    }
}
