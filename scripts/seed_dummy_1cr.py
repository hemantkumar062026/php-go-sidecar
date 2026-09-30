#!/usr/bin/env python3
"""Generate 1 crore (10,000,000) dummy sftp_contact-shaped Parquet rows for load tests."""

from __future__ import annotations

import argparse
import time
from pathlib import Path

import duckdb

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_OUT = ROOT / "data" / "dummy_1cr"
ROWS = 10_000_000  # 1 crore
ACCOUNT_ID = 1133


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--rows", type=int, default=ROWS)
    ap.add_argument("--out", type=Path, default=DEFAULT_OUT)
    ap.add_argument("--threads", type=int, default=4)
    ap.add_argument("--memory", default="4GB")
    ap.add_argument("--chunk", type=int, default=1_000_000, help="rows per parquet part")
    args = ap.parse_args()

    out: Path = args.out
    contact_dir = out / "contact" / f"account_id={ACCOUNT_ID}"
    block_dir = out / "block_file_data"
    contact_dir.mkdir(parents=True, exist_ok=True)
    block_dir.mkdir(parents=True, exist_ok=True)

    # wipe previous parts for this account
    for p in contact_dir.glob("part-*.parquet"):
        p.unlink()
    for p in block_dir.glob("part-*.parquet"):
        p.unlink()

    con = duckdb.connect()
    con.execute(f"SET threads={int(args.threads)}")
    con.execute(f"SET memory_limit='{args.memory}'")
    con.execute("SET TimeZone='UTC'")

    rows = int(args.rows)
    chunk = max(100_000, int(args.chunk))
    parts = (rows + chunk - 1) // chunk
    print(f"generating {rows:,} contacts → {out} ({parts} parts, chunk={chunk:,})", flush=True)
    t0 = time.perf_counter()

    for part in range(parts):
        lo = part * chunk + 1
        hi = min((part + 1) * chunk, rows)
        dest = contact_dir / f"part-{part:04d}.parquet"
        sql = f"""
COPY (
  SELECT
    i::BIGINT AS id,
    {ACCOUNT_ID}::INTEGER AS account_id,
    printf('pk-%d', i) AS primary_key,
    printf('user%d@example.com', i) AS email,
    CASE WHEN i % 7 = 0 THEN NULL ELSE printf('9%09d', i % 1000000000) END AS mobile,
    CASE
      WHEN i % 1000 = 1 THEN 1
      WHEN i % 50 = 0 THEN 2
      WHEN i % 50 = 1 THEN 3
      WHEN i % 50 = 2 THEN 4
      WHEN i % 50 = 3 THEN 5
      WHEN i % 50 = 4 THEN 6
      ELSE 1
    END::TINYINT AS email_status,
    CASE WHEN i % 11 = 0 THEN 5 WHEN i % 9 = 0 THEN 0 ELSE 1 END::TINYINT AS sms_status,
    CASE WHEN i % 1000 = 1 THEN 0 WHEN i % 200 = 0 THEN 1 ELSE 0 END::TINYINT AS is_deleted,
    1::TINYINT AS is_contact,
    0::TINYINT AS is_preview,
    CASE WHEN i % 20 = 0 THEN (epoch(now())::BIGINT - (i % 10) * 86400) ELSE 0 END::INTEGER AS last_emailed,
    0::INTEGER AS last_sms,
    printf('ACC%08d', i) AS f2,
    -- Every 1000th row is a planted segment hit: Mastercard + age 50 + Delhi + ICICI
    CASE WHEN i % 1000 = 1 THEN 'ICICI'
         WHEN (i % 5) = 0 THEN 'ICICI'
         WHEN (i % 5) = 1 THEN 'HDFC'
         WHEN (i % 5) = 2 THEN 'OrgA'
         WHEN (i % 5) = 3 THEN 'OrgB'
         ELSE NULL
    END AS f6,
    CAST(DATE '2015-01-01' + INTERVAL (i % 3650) DAY AS VARCHAR) AS f7,
    CASE WHEN i % 1000 = 1 THEN 'Delhi'
         WHEN (i % 3) = 0 THEN 'Delhi'
         WHEN (i % 3) = 1 THEN 'Mumbai'
         ELSE 'Pune'
    END AS f18,
    CASE WHEN i % 1000 = 1 THEN 'Mastercard'
         WHEN (i % 4) = 0 THEN 'Mastercard'
         WHEN (i % 4) = 1 THEN 'Visa'
         WHEN (i % 4) = 2 THEN 'Amex'
         ELSE 'Rupay'
    END AS f30,
    CASE WHEN i % 1000 = 1 THEN 50 ELSE (25 + (i % 46)) END::TINYINT AS f31
  FROM generate_series({lo}, {hi}) AS t(i)
) TO '{dest}' (FORMAT PARQUET, COMPRESSION ZSTD)
"""
        t1 = time.perf_counter()
        con.execute(sql)
        dt = time.perf_counter() - t1
        sz = dest.stat().st_size
        print(f"  part {part+1}/{parts}: rows {lo:,}..{hi:,} → {dest.name} "
              f"({sz/1e6:.1f} MB, {dt:.1f}s)", flush=True)

    # Segment defs reference (campaign UI / API also send these in JSON)
    seg_path = out / "segment_defs.parquet"
    con.execute(
        f"""
COPY (
  SELECT * FROM (VALUES
    (9001::BIGINT, 'Mastercard (>1M)'::VARCHAR, '[{{"field":"f30","op":"=","value":"Mastercard"}}]'::VARCHAR),
    (9002, 'Delhi (>1M)', '[{{"field":"f18","op":"=","value":"Delhi"}}]'),
    (9003, 'ICICI (>1M)', '[{{"field":"f6","op":"=","value":"ICICI"}}]'),
    (9004, 'Age >= 40 (>1M)', '[{{"field":"f31","op":">=","value":40}}]'),
    (532, 'Mastercard+50+Delhi', 'planted'),
    (488, 'Mastercard age>51', 'planted'),
    (533, 'Mastercard+50+Delhi+ICICI', 'planted'),
    (489, 'Visa', 'planted')
  ) AS t(segment_id, name, conditions_json)
) TO '{seg_path}' (FORMAT PARQUET, COMPRESSION ZSTD)
"""
    )
    print(f"wrote segment_defs → {seg_path}", flush=True)

    # Block files: ~0.2% include (56), ~0.05% exclude (57) by f2
    block_path = block_dir / "part-0.parquet"
    con.execute(
        f"""
COPY (
  SELECT * FROM (
    SELECT 56::BIGINT AS block_file_id, printf('ACC%08d', i) AS unique_identifier
    FROM generate_series(1, {rows}) AS t(i)
    WHERE i % 500 = 0
    UNION ALL
    SELECT 57::BIGINT, printf('ACC%08d', i)
    FROM generate_series(1, {rows}) AS t(i)
    WHERE i % 2000 = 0
  )
) TO '{block_path}' (FORMAT PARQUET, COMPRESSION ZSTD)
"""
    )
    block_n = con.execute(
        f"SELECT count(*) FROM read_parquet('{block_path}')"
    ).fetchone()[0]
    print(f"wrote block_file_data rows={block_n:,} → {block_path}", flush=True)

    # Quick sanity counts for segment-like filter
    lake = str(contact_dir / "*.parquet").replace("'", "''")
    stats = con.execute(
        f"""
SELECT
  count(*)::BIGINT AS total,
  count(*) FILTER (WHERE is_deleted = 0)::BIGINT AS alive,
  count(*) FILTER (
    WHERE is_deleted = 0 AND email_status = 1
      AND f30 = 'Mastercard' AND f31 = 50 AND f18 = 'Delhi' AND f6 = 'ICICI'
  )::BIGINT AS segment_hit
FROM read_parquet('{lake}')
"""
    ).fetchone()
    elapsed = time.perf_counter() - t0
    print(
        f"done in {elapsed:.1f}s | total={stats[0]:,} alive={stats[1]:,} "
        f"segment(Mastercard+50+Delhi+ICICI)={stats[2]:,}",
        flush=True,
    )
    print(f"parquetPath={out}", flush=True)


if __name__ == "__main__":
    main()
