#!/usr/bin/env python3
"""Stress-test campaign-preview and report p50 / p95 / p99 latency.

Example:
  python3 tests/stress_campaign_preview.py \\
    --source dummy_base_delta_10 --parallel true \\
    --concurrency 1,4,8,16 --requests 40 --warmup 4
"""

from __future__ import annotations

import argparse
import json
import math
import time
import urllib.error
import urllib.request
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]

SEGMENT_DEFS = {
    "9001": [[{"type": "attributes", "operator": "text_attr_equals", "options": ["f30", "Mastercard"]}]],
    "488": [[
        {"type": "attributes", "operator": "text_attr_equals", "options": ["f30", "Mastercard"]},
        {"type": "attributes", "operator": "number_attr_gt", "options": ["f31", "51"]},
    ]],
    "532": [[
        {"type": "attributes", "operator": "text_attr_equals", "options": ["f30", "Mastercard"]},
        {"type": "attributes", "operator": "number_attr_equals", "options": ["f31", "50"]},
        {"type": "attributes", "operator": "text_attr_equals", "options": ["f18", "Delhi"]},
    ]],
}

SCENARIOS = {
    "seg_9001": {
        "include_segments": [9001],
        "exclude_segments": [],
        "include_block_files": [],
        "exclude_block_files": [],
        "stats_not_sent_days": 0,
    },
    "demo_532": {
        "include_segments": [532],
        "exclude_segments": [],
        "include_block_files": [56],
        "exclude_block_files": [57],
        "stats_not_sent_days": 2,
    },
    "seg_plus_exclude": {
        "include_segments": [9001],
        "exclude_segments": [488],
        "include_block_files": [],
        "exclude_block_files": [],
        "stats_not_sent_days": 0,
    },
}


def http_json(method: str, url: str, body: dict | None = None, timeout: float = 300) -> dict:
    data = None if body is None else json.dumps(body).encode()
    req = urllib.request.Request(
        url,
        data=data,
        method=method,
        headers={"Content-Type": "application/json", "Accept": "application/json"},
    )
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            raw = resp.read().decode()
    except urllib.error.HTTPError as e:
        raw = e.read().decode()
        raise RuntimeError(f"HTTP {e.code}: {raw[:400]}") from e
    if raw.lstrip().startswith("<"):
        raw = raw[raw.find("{") :]
    return json.loads(raw)


def percentile(sorted_xs: list[float], p: float) -> float:
    if not sorted_xs:
        return float("nan")
    if len(sorted_xs) == 1:
        return sorted_xs[0]
    k = (len(sorted_xs) - 1) * (p / 100.0)
    f = math.floor(k)
    c = math.ceil(k)
    if f == c:
        return sorted_xs[int(k)]
    return sorted_xs[f] * (c - k) + sorted_xs[c] * (k - f)


def stats(xs: list[float]) -> dict[str, float]:
    s = sorted(xs)
    return {
        "n": len(s),
        "min": round(s[0], 1) if s else None,
        "p50": round(percentile(s, 50), 1) if s else None,
        "p95": round(percentile(s, 95), 1) if s else None,
        "p99": round(percentile(s, 99), 1) if s else None,
        "max": round(s[-1], 1) if s else None,
        "avg": round(sum(s) / len(s), 1) if s else None,
    }


def set_source(api: str, ds: str) -> None:
    http_json(
        "POST",
        f"{api}/api/sftp/config",
        {"dataSource": ds, "threads": 2, "memoryLimit": "2GB"},
    )


def one_preview(api: str, scenario: dict, parallel: bool) -> tuple[float, dict]:
    body = {
        "account_id": 1133,
        "parallel": parallel,
        "segment_defs": SEGMENT_DEFS,
        "catalog_block_files": [56, 57],
        **scenario,
    }
    t0 = time.perf_counter()
    out = http_json("POST", f"{api}/api/sftp/campaign-preview", body)
    return (time.perf_counter() - t0) * 1000.0, out


def run_load(
    api: str,
    scenario: dict,
    parallel: bool,
    concurrency: int,
    requests: int,
) -> dict[str, Any]:
    latencies: list[float] = []
    errors = 0
    finals: set[Any] = set()
    t0 = time.perf_counter()
    with ThreadPoolExecutor(max_workers=concurrency) as ex:
        futs = [ex.submit(one_preview, api, scenario, parallel) for _ in range(requests)]
        for fut in as_completed(futs):
            try:
                ms, out = fut.result()
                latencies.append(ms)
                finals.add((out.get("metrics") or {}).get("final_target"))
            except Exception:
                errors += 1
    wall = (time.perf_counter() - t0) * 1000.0
    st = stats(latencies)
    return {
        "http_concurrency": concurrency,
        "http_requests": requests,
        "query_parallel": parallel,
        "ok": len(latencies),
        "errors": errors,
        "wall_ms": round(wall, 1),
        "throughput_rps": round(len(latencies) / (wall / 1000.0), 2) if wall > 0 else None,
        "latency_ms": st,
        "distinct_finals": sorted(x for x in finals if x is not None),
    }


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--api", default="http://127.0.0.1:8080")
    ap.add_argument(
        "--source",
        default="dummy_base_delta_10",
        help="dataSource id (or 'all')",
    )
    ap.add_argument("--scenario", default="seg_9001", choices=sorted(SCENARIOS))
    ap.add_argument("--parallel", default="both", choices=["true", "false", "both"])
    ap.add_argument("--concurrency", default="1,4,8,16")
    ap.add_argument("--requests", type=int, default=40)
    ap.add_argument("--warmup", type=int, default=4)
    ap.add_argument("--out", type=Path, default=ROOT / "docs" / "stress_campaign_preview.json")
    ap.add_argument("--md", type=Path, default=ROOT / "docs" / "STRESS_CAMPAIGN_PREVIEW.md")
    args = ap.parse_args()

    api = args.api.rstrip("/")
    concs = [int(x) for x in args.concurrency.split(",") if x.strip()]
    sources = (
        ["dummy_1cr", "dummy_base_delta", "dummy_base_delta_10"]
        if args.source == "all"
        else [args.source]
    )
    parallels = (
        [False, True]
        if args.parallel == "both"
        else [args.parallel == "true"]
    )
    scenario = SCENARIOS[args.scenario]

    health = http_json("GET", f"{api}/api/sftp/datasources")
    sidecar = None
    try:
        sidecar = http_json("GET", "http://127.0.0.1:8091/health")
    except Exception as e:
        sidecar = {"error": str(e)}

    report: dict[str, Any] = {
        "api": api,
        "scenario": args.scenario,
        "requests_per_cell": args.requests,
        "warmup": args.warmup,
        "sidecar_health": sidecar,
        "datasources": health.get("datasources"),
        "results": [],
    }

    print(
        f"stress scenario={args.scenario} sources={sources} "
        f"concurrency={concs} requests/cell={args.requests}",
        flush=True,
    )

    for ds in sources:
        print(f"\n=== {ds} ===", flush=True)
        set_source(api, ds)
        for i in range(args.warmup):
            try:
                one_preview(api, scenario, True)
            except Exception as e:
                print(f"  warmup {i+1} failed: {e}", flush=True)
                # try restart hint
                raise

        for parallel in parallels:
            for c in concs:
                # Don't over-ask: at least concurrency workers worth of requests
                nreq = max(args.requests, c)
                try:
                    row = run_load(api, scenario, parallel, c, nreq)
                except Exception as e:
                    row = {
                        "source": ds,
                        "scenario": args.scenario,
                        "http_concurrency": c,
                        "http_requests": nreq,
                        "query_parallel": parallel,
                        "ok": 0,
                        "errors": nreq,
                        "wall_ms": None,
                        "throughput_rps": None,
                        "latency_ms": {"n": 0, "min": None, "p50": None, "p95": None, "p99": None, "max": None, "avg": None},
                        "distinct_finals": [],
                        "error": str(e),
                    }
                    report["results"].append(row)
                    print(f"  FAIL c={c} parallel={parallel}: {e}", flush=True)
                    continue
                row["source"] = ds
                row["scenario"] = args.scenario
                report["results"].append(row)
                lat = row["latency_ms"]
                mode = "par" if parallel else "seq"
                print(
                    f"  {mode} c={c:2d} n={nreq:3d}  "
                    f"p50={lat['p50']:8.1f}  p95={lat['p95']:8.1f}  p99={lat['p99']:8.1f}  "
                    f"max={lat['max']:8.1f}  rps={row['throughput_rps']}  err={row['errors']}",
                    flush=True,
                )

    args.out.parent.mkdir(parents=True, exist_ok=True)
    args.out.write_text(json.dumps(report, indent=2) + "\n")

    # Markdown
    lines = [
        "# Campaign preview stress benchmark",
        "",
        f"Scenario: `{args.scenario}` · requests/cell: **{args.requests}** · warmup: {args.warmup}",
        "",
        "## Sidecar",
        "",
        "```json",
        json.dumps(sidecar, indent=2),
        "```",
        "",
        "## Latency under load (ms)",
        "",
        "| Source | Query parallel | HTTP concurrency | HTTP requests | OK | Errors | p50 | p95 | p99 | max | avg | RPS | Wall (ms) |",
        "|--------|----------------|-----------------:|--------------:|---:|-------:|----:|----:|----:|----:|----:|----:|----------:|",
    ]
    for r in report["results"]:
        lat = r["latency_ms"]
        lines.append(
            f"| `{r['source']}` | {str(r['query_parallel']).lower()} | "
            f"{r['http_concurrency']} | {r['http_requests']} | {r['ok']} | {r['errors']} | "
            f"{lat['p50']} | {lat['p95']} | {lat['p99']} | {lat['max']} | {lat['avg']} | "
            f"{r['throughput_rps']} | {r['wall_ms']} |"
        )
    lines += [
        "",
        "## How to re-run",
        "",
        "```bash",
        "python3 tests/stress_campaign_preview.py --source all --parallel both \\",
        "  --concurrency 1,4,8,16 --requests 40 --warmup 4",
        "```",
        "",
        f"Raw JSON: [`{args.out.name}`](./{args.out.name})",
        "",
    ]
    args.md.write_text("\n".join(lines))
    print(f"\nwrote {args.out}\nwrote {args.md}", flush=True)


if __name__ == "__main__":
    main()
