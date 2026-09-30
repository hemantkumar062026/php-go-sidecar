#!/usr/bin/env python3
"""Compare Python sidecar vs satur.io/duckdb under 20 parallel requests on 5cr lake."""

from __future__ import annotations

import json
import os
import statistics
import subprocess
import time
import urllib.request
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
GLOB = "/Users/lumegalabs/Downloads/temp-cursor/segmentation-poc/data/parquet_contacts_5cr/contact/**/*.parquet"
SQL = (
    f"SELECT count(*)::BIGINT AS cnt FROM read_parquet('{GLOB}', "
    "hive_partitioning=true, union_by_name=true) "
    "WHERE account_id=83 AND COALESCE(email_status,0)=1 AND CAST(f6 AS VARCHAR)='f6-382'"
)
N = int(os.environ.get("BENCH_PARALLEL", "20"))
THREADS = os.environ.get("DUCKDB_THREADS", "2")
SIDECAR = os.environ.get("DUCKDB_SIDECAR_URL", "http://127.0.0.1:8090")


def sidecar_one() -> dict:
    payload = json.dumps({"sql": SQL}).encode()
    req = urllib.request.Request(
        f"{SIDECAR}/query",
        data=payload,
        headers={"Content-Type": "application/json"},
    )
    t0 = time.perf_counter()
    with urllib.request.urlopen(req, timeout=300) as resp:
        data = json.load(resp)
    wall = (time.perf_counter() - t0) * 1000
    return {
        "wall_ms": wall,
        "server_ms": data.get("ms"),
        "cnt": data["rows"][0]["cnt"] if data.get("rows") else None,
    }


def saturo_one() -> dict:
    env = os.environ.copy()
    env["BENCH_SQL"] = SQL
    env["DUCKDB_THREADS"] = THREADS
    t0 = time.perf_counter()
    p = subprocess.run(
        ["php", str(ROOT / "tests/bench_saturio_one.php")],
        env=env,
        capture_output=True,
        text=True,
        timeout=300,
    )
    wall = (time.perf_counter() - t0) * 1000
    if p.returncode != 0:
        raise RuntimeError(p.stderr or p.stdout)
    data = json.loads(p.stdout.strip().splitlines()[-1])
    return {
        "wall_ms": wall,
        "server_ms": data.get("ms"),
        "cnt": (data.get("rows") or [{}])[0].get("cnt"),
    }


def run_batch(name: str, fn, n: int) -> dict:
    # warm 1
    warm = fn()
    t0 = time.perf_counter()
    results = []
    errors = 0
    with ThreadPoolExecutor(max_workers=n) as ex:
        futs = [ex.submit(fn) for _ in range(n)]
        for f in as_completed(futs):
            try:
                results.append(f.result())
            except Exception as e:  # noqa: BLE001
                errors += 1
                results.append({"wall_ms": None, "error": str(e)})
    wall = (time.perf_counter() - t0) * 1000
    ok = [r["wall_ms"] for r in results if r.get("wall_ms") is not None]
    server = [r["server_ms"] for r in results if r.get("server_ms") is not None]
    cnts = {r.get("cnt") for r in results if r.get("cnt") is not None}

    def summ(xs):
        if not xs:
            return None
        xs = sorted(xs)
        return {
            "min": round(min(xs), 1),
            "p50": round(statistics.median(xs), 1),
            "p95": round(xs[max(0, int(len(xs) * 0.95) - 1)], 1),
            "max": round(max(xs), 1),
            "avg": round(sum(xs) / len(xs), 1),
        }

    return {
        "name": name,
        "parallel": n,
        "warm_ms": round(warm["wall_ms"], 1),
        "batch_wall_ms": round(wall, 1),
        "throughput_qps": round(n / (wall / 1000), 2) if wall > 0 else None,
        "per_request_wall_ms": summ(ok),
        "per_request_server_ms": summ(server),
        "errors": errors,
        "counts": list(cnts),
        "ok": len(ok),
    }


def main() -> None:
    health = json.load(urllib.request.urlopen(f"{SIDECAR}/health", timeout=5))
    print("sidecar health:", json.dumps(health), flush=True)

    print(f"\n=== {N} parallel: Python sidecar ===", flush=True)
    side = run_batch("python_sidecar", sidecar_one, N)
    print(json.dumps(side, indent=2), flush=True)

    print(f"\n=== {N} parallel: satur.io/duckdb (20 PHP processes) ===", flush=True)
    satu = run_batch("satur.io/duckdb", saturo_one, N)
    print(json.dumps(satu, indent=2), flush=True)

    # comparison
    cmp = {
        "dataset": "temp-cursor 5cr",
        "query": "segment count account_id=83 email_status=1 f6=f6-382 (cnt=7143)",
        "parallel_requests": N,
        "duckdb_threads_per_conn": int(THREADS),
        "sidecar_pool_size": health.get("pool_size"),
        "sidecar": side,
        "saturo": satu,
        "winner_batch_wall": "sidecar"
        if side["batch_wall_ms"] < satu["batch_wall_ms"]
        else "saturo",
        "winner_p50_latency": (
            "sidecar"
            if (side["per_request_wall_ms"] or {}).get("p50", 1e9)
            < (satu["per_request_wall_ms"] or {}).get("p50", 1e9)
            else "saturo"
        ),
        "winner_throughput": "sidecar"
        if (side.get("throughput_qps") or 0) > (satu.get("throughput_qps") or 0)
        else "saturo",
    }
    out = ROOT / "docs/bench_sidecar_vs_saturo_20.json"
    out.write_text(json.dumps(cmp, indent=2))
    print("\n=== COMPARISON ===", flush=True)
    print(json.dumps(cmp, indent=2), flush=True)
    print("wrote", out, flush=True)


if __name__ == "__main__":
    main()
