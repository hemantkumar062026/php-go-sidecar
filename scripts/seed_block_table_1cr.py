#!/usr/bin/env python3
"""Build email-based block_table from dummy_1cr contacts (~1 lakh rows per block_id)."""

from __future__ import annotations

import argparse
import time
from pathlib import Path

import duckdb

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_CONTACT = ROOT / "data" / "dummy_1cr" / "contact"
DEFAULT_OUT = ROOT / "data" / "dummy_1cr" / "block_table"
CHUNK = 100_000


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--contact", type=Path, default=DEFAULT_CONTACT)
    ap.add_argument("--out", type=Path, default=DEFAULT_OUT)
    ap.add_argument("--chunk", type=int, default=CHUNK)
    ap.add_argument("--threads", type=int, default=4)
    ap.add_argument("--memory", default="4GB")
    args = ap.parse_args()

    contact_glob = str(args.contact / "**" / "*.parquet")
    out: Path = args.out
    out.mkdir(parents=True, exist_ok=True)
    for p in out.glob("**/*.parquet"):
        p.unlink()

    con = duckdb.connect()
    con.execute(f"SET threads={int(args.threads)}")
    con.execute(f"SET memory_limit='{args.memory}'")
    con.execute("SET TimeZone='UTC'")

    t0 = time.perf_counter()
    dest = out / "part-0.parquet"
    chunk = max(1, int(args.chunk))
    sql = f"""
COPY (
  SELECT
    account_id::INTEGER AS account_id,
    CAST(ceil(id::DOUBLE / {chunk}) AS INTEGER) AS block_id,
    email::VARCHAR AS email
  FROM read_parquet('{contact_glob.replace("'", "''")}', hive_partitioning=true, union_by_name=true)
  WHERE email IS NOT NULL AND NULLIF(TRIM(email), '') IS NOT NULL
) TO '{dest}' (FORMAT PARQUET, COMPRESSION ZSTD)
"""
    print(f"writing block_table from {contact_glob} → {dest}", flush=True)
    con.execute(sql)
    stats = con.execute(
        f"""
SELECT
  count(*)::BIGINT AS rows,
  count(DISTINCT block_id)::BIGINT AS blocks,
  min(block_id) AS min_b,
  max(block_id) AS max_b
FROM read_parquet('{dest}')
"""
    ).fetchone()
    print(
        f"done in {time.perf_counter()-t0:.1f}s | rows={stats[0]:,} blocks={stats[1]} "
        f"({stats[2]}..{stats[3]}) size={dest.stat().st_size/1e6:.1f}MB",
        flush=True,
    )


if __name__ == "__main__":
    main()
