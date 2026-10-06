#!/usr/bin/env python3
"""Load-test POST /api/sftp/campaign-preview across sources, scenarios, parallel flag.

Usage:
  python3 tests/load_campaign_preview.py
  python3 tests/load_campaign_preview.py --api http://127.0.0.1:8080 --concurrency 8 --repeat 3
"""

from __future__ import annotations

import argparse
import json
import statistics
import time
import urllib.error
import urllib.request
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]

SEGMENT_DEFS = {
    "9001": [[{"type": "attributes", "operator": "text_attr_equals", "options": ["f30", "Mastercard"]}]],
    "9002": [[{"type": "attributes", "operator": "text_attr_equals", "options": ["f18", "Delhi"]}]],
    "9003": [[{"type": "attributes", "operator": "text_attr_equals", "options": ["f6", "ICICI"]}]],
    "9004": [[{"type": "attributes", "operator": "number_attr_gte", "options": ["f31", "40"]}]],
    "532": [[
        {"type": "attributes", "operator": "text_attr_equals", "options": ["f30", "Mastercard"]},
        {"type": "attributes", "operator": "number_attr_equals", "options": ["f31", "50"]},
        {"type": "attributes", "operator": "text_attr_equals", "options": ["f18", "Delhi"]},
    ]],
    "488": [[
        {"type": "attributes", "operator": "text_attr_equals", "options": ["f30", "Mastercard"]},
        {"type": "attributes", "operator": "number_attr_gt", "options": ["f31", "51"]},
    ]],
    "533": [[
        {"type": "attributes", "operator": "text_attr_equals", "options": ["f30", "Mastercard"]},
        {"type": "attributes", "operator": "number_attr_equals", "options": ["f31", "50"]},
        {"type": "attributes", "operator": "text_attr_equals", "options": ["f18", "Delhi"]},
        {"type": "attributes", "operator": "text_attr_equals", "options": ["f6", "ICICI"]},
    ]],
    "489": [[{"type": "attributes", "operator": "text_attr_equals", "options": ["f30", "Visa"]}]],
}

# Audience shapes exercised against every lake × parallel mode
SCENARIOS: list[dict[str, Any]] = [
    {
        "name": "active_only",
        "include_segments": [],
        "exclude_segments": [],
        "include_block_files": [],
        "exclude_block_files": [],
        "stats_not_sent_days": 0,
    },
    {
        "name": "seg_9001",
        "include_segments": [9001],
        "exclude_segments": [],
        "include_block_files": [],
        "exclude_block_files": [],
        "stats_not_sent_days": 0,
    },
    {
        "name": "seg_union_9001_9002",
        "include_segments": [9001, 9002],
        "exclude_segments": [],
        "include_block_files": [],
        "exclude_block_files": [],
        "stats_not_sent_days": 0,
    },
    {
        "name": "seg_include_exclude",
        "include_segments": [9001],
        "exclude_segments": [488],
        "include_block_files": [],
        "exclude_block_files": [],
        "stats_not_sent_days": 0,
    },
    {
        "name": "block_include_56",
        "include_segments": [],
        "exclude_segments": [],
        "include_block_files": [56],
        "exclude_block_files": [],
        "stats_not_sent_days": 0,
    },
    {
        "name": "seg_plus_blocks",
        "include_segments": [532],
        "exclude_segments": [],
        "include_block_files": [56],
        "exclude_block_files": [57],
        "stats_not_sent_days": 0,
    },
    {
        "name": "demo_532_full",
        "include_segments": [532],
        "exclude_segments": [],
        "include_block_files": [56],
        "exclude_block_files": [57],
        "stats_not_sent_days": 2,
    },
    {
        "name": "demo_532_488",
        "include_segments": [532, 488],
        "exclude_segments": [488],
        "include_block_files": [56],
        "exclude_block_files": [57],
        "stats_not_sent_days": 2,
    },
    {
        "name": "heavy_catalog",
        "include_segments": [9001],
        "exclude_segments": [],
        "include_block_files": [],
        "exclude_block_files": [],
        "stats_not_sent_days": 0,
        "catalog_all": True,
    },
]


def http_json(method: str, url: str, body: dict | None = None, timeout: float = 180) -> dict:
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
        try:
            payload = json.loads(raw)
        except Exception:
            raise RuntimeError(f"HTTP {e.code}: {raw[:500]}") from e
        raise RuntimeError(f"HTTP {e.code}: {payload.get('error') or payload}") from e
    if raw.lstrip().startswith("<"):
        raw = raw[raw.find("{") :]
    return json.loads(raw)


def set_source(api: str, ds_id: str) -> dict:
    mem = "4GB" if ds_id == "dummy_1cr" else "2GB"
    return http_json(
        "POST",
        f"{api}/api/sftp/config",
        {
            "dataSource": ds_id,
            "threads": 4,
            "memoryLimit": mem,
            "backend": "go_sidecar",
            "sidecarUrl": "http://127.0.0.1:8091",
        },
    )


def preview(api: str, scenario: dict[str, Any], parallel: bool) -> tuple[float, dict]:
    body: dict[str, Any] = {
        "account_id": 1133,
        "parallel": parallel,
        "include_segments": scenario.get("include_segments", []),
        "exclude_segments": scenario.get("exclude_segments", []),
        "include_block_files": scenario.get("include_block_files", []),
        "exclude_block_files": scenario.get("exclude_block_files", []),
        "stats_not_sent_days": scenario.get("stats_not_sent_days", 0),
        "segment_defs": SEGMENT_DEFS,
        "catalog_block_files": [56, 57],
    }
    if scenario.get("catalog_all"):
        body["catalog_segments"] = [int(k) for k in SEGMENT_DEFS]
    t0 = time.perf_counter()
    out = http_json("POST", f"{api}/api/sftp/campaign-preview", body)
    ms = (time.perf_counter() - t0) * 1000
    return ms, out


def metric_fingerprint(metrics: dict) -> dict:
    keep = {
        "active_email",
        "segment_union",
        "include_base",
        "final_target",
        "excluded_from_include",
        "exclude_segments",
        "exclude_block_files",
        "include_block_files",
        "segment_parts",
        "block_file_parts",
    }
    return {k: metrics.get(k) for k in keep if k in metrics or metrics.get(k) is not None}


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--api", default="http://127.0.0.1:8080")
    ap.add_argument("--repeat", type=int, default=2, help="timed repeats per scenario after warm")
    ap.add_argument("--concurrency", type=int, default=6, help="parallel HTTP workers for burst test")
    ap.add_argument("--burst", type=int, default=12, help="total concurrent requests in burst")
    ap.add_argument(
        "--sources",
        default="dummy_1cr,dummy_base_delta,dummy_base_delta_10",
        help="comma-separated dataSource ids",
    )
    ap.add_argument("--out", type=Path, default=ROOT / "docs" / "load_campaign_preview.json")
    args = ap.parse_args()

    api = args.api.rstrip("/")
    sources = [s.strip() for s in args.sources.split(",") if s.strip()]

    health = http_json("GET", f"{api}/api/sftp/datasources")
    available = {s["id"] for s in health.get("datasources", [])}
    missing = [s for s in sources if s not in available]
    if missing:
        raise SystemExit(f"missing datasources: {missing}; available={sorted(available)}")

    print(f"API={api} sources={sources} scenarios={len(SCENARIOS)}", flush=True)

    report: dict[str, Any] = {
        "api": api,
        "sources": sources,
        "scenarios": [s["name"] for s in SCENARIOS],
        "load": {
            "timed_http_concurrency": 1,
            "timed_repeats": args.repeat,
            "burst_http_concurrency": args.concurrency,
            "burst_http_requests": args.burst,
            "note": (
                "parallel=true means DuckDB COUNTs inside one API call run concurrently; "
                "http_concurrency is how many campaign-preview requests hit the API at once."
            ),
        },
        "results": [],
        "correctness": [],
        "burst": [],
        "summary": {},
    }
    failures = 0

    for ds in sources:
        print(f"\n=== source {ds} ===", flush=True)
        set_source(api, ds)

        for sc in SCENARIOS:
            name = sc["name"]
            # Correctness: sequential vs parallel metrics must match
            try:
                _, seq = preview(api, sc, False)
                _, par = preview(api, sc, True)
            except Exception as e:
                failures += 1
                print(f"  FAIL {name}: {e}", flush=True)
                report["correctness"].append(
                    {"source": ds, "scenario": name, "ok": False, "error": str(e)}
                )
                continue

            seq_m = metric_fingerprint(seq.get("metrics") or {})
            par_m = metric_fingerprint(par.get("metrics") or {})
            match = seq_m == par_m
            if not match:
                failures += 1
            report["correctness"].append(
                {
                    "source": ds,
                    "scenario": name,
                    "ok": match,
                    "seq_final": seq_m.get("final_target"),
                    "par_final": par_m.get("final_target"),
                    "seq": seq_m,
                    "par": par_m,
                }
            )
            print(
                f"  correctness {name}: {'OK' if match else 'MISMATCH'} "
                f"final seq={seq_m.get('final_target')} par={par_m.get('final_target')}",
                flush=True,
            )

            # Timed runs (exclude warm — already did one each)
            for parallel in (False, True):
                times: list[float] = []
                last_final = None
                for i in range(args.repeat):
                    ms, out = preview(api, sc, parallel)
                    times.append(ms)
                    last_final = (out.get("metrics") or {}).get("final_target")
                    if out.get("parallel") is not parallel:
                        failures += 1
                        print(f"  WARN parallel flag echo mismatch", flush=True)
                row = {
                    "source": ds,
                    "scenario": name,
                    "parallel": parallel,
                    "http_concurrency": 1,
                    "http_requests": 1,
                    "repeats": len(times),
                    "n": len(times),
                    "ms_min": round(min(times), 1),
                    "ms_p50": round(statistics.median(times), 1),
                    "ms_avg": round(statistics.mean(times), 1),
                    "ms_max": round(max(times), 1),
                    "final_target": last_final,
                }
                report["results"].append(row)
                mode = "par" if parallel else "seq"
                print(
                    f"  {mode:3} {name:24} p50={row['ms_p50']:7.1f}ms "
                    f"avg={row['ms_avg']:7.1f} final={last_final}",
                    flush=True,
                )

        # Burst load on a representative scenario for this source
        burst_sc = next(s for s in SCENARIOS if s["name"] == "seg_9001")
        for parallel in (False, True):
            labels = []
            t0 = time.perf_counter()
            with ThreadPoolExecutor(max_workers=args.concurrency) as ex:
                futs = [
                    ex.submit(preview, api, burst_sc, parallel)
                    for _ in range(args.burst)
                ]
                ok = 0
                errs = 0
                finals = set()
                latencies = []
                for fut in as_completed(futs):
                    try:
                        ms, out = fut.result()
                        latencies.append(ms)
                        finals.add((out.get("metrics") or {}).get("final_target"))
                        ok += 1
                    except Exception as e:
                        errs += 1
                        labels.append(str(e))
            wall = (time.perf_counter() - t0) * 1000
            burst_row = {
                "source": ds,
                "scenario": burst_sc["name"],
                "parallel": parallel,
                "http_concurrency": args.concurrency,
                "http_requests": args.burst,
                "concurrency": args.concurrency,
                "requests": args.burst,
                "ok": ok,
                "errors": errs,
                "wall_ms": round(wall, 1),
                "ms_p50": round(statistics.median(latencies), 1) if latencies else None,
                "ms_avg": round(statistics.mean(latencies), 1) if latencies else None,
                "distinct_finals": sorted(x for x in finals if x is not None),
                "error_samples": labels[:3],
            }
            if errs:
                failures += 1
            if len(finals) > 1:
                failures += 1
            report["burst"].append(burst_row)
            mode = "par" if parallel else "seq"
            print(
                f"  burst {mode}: wall={burst_row['wall_ms']}ms ok={ok}/{args.burst} "
                f"p50={burst_row['ms_p50']} finals={burst_row['distinct_finals']}",
                flush=True,
            )

    # Summary speedup: avg(seq)/avg(par) where both exist for same source+scenario
    speedups = []
    by_key: dict[tuple[str, str], dict[str, float]] = {}
    for r in report["results"]:
        key = (r["source"], r["scenario"])
        by_key.setdefault(key, {})
        by_key[key]["par" if r["parallel"] else "seq"] = r["ms_avg"]
    for key, vals in by_key.items():
        if "seq" in vals and "par" in vals and vals["par"] > 0:
            speedups.append(vals["seq"] / vals["par"])

    report["summary"] = {
        "failures": failures,
        "correctness_ok": sum(1 for c in report["correctness"] if c.get("ok")),
        "correctness_total": len(report["correctness"]),
        "avg_speedup_seq_over_par": round(statistics.mean(speedups), 2) if speedups else None,
        "median_speedup_seq_over_par": round(statistics.median(speedups), 2) if speedups else None,
    }

    args.out.parent.mkdir(parents=True, exist_ok=True)
    args.out.write_text(json.dumps(report, indent=2) + "\n")
    print(
        f"\nDONE failures={failures} correctness="
        f"{report['summary']['correctness_ok']}/{report['summary']['correctness_total']} "
        f"median_speedup={report['summary']['median_speedup_seq_over_par']}x "
        f"wrote {args.out}",
        flush=True,
    )
    raise SystemExit(1 if failures else 0)


if __name__ == "__main__":
    main()
