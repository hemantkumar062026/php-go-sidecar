#!/usr/bin/env python3
"""Generate an SFTP contact lake as 1 base Parquet + N sparse delta Parquets.

Layout:
  data/dummy_base_delta/
    contact/base/account_id=1133/base.parquet
    contact/delta/account_id=1133/delta_id=NNN/part-0.parquet   (45 files)
    block_file_data/part-0.parquet
    datasource.json
"""

from __future__ import annotations

import argparse
import json
import shutil
import time
from pathlib import Path

import duckdb

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_OUT = ROOT / "data" / "dummy_base_delta"
ROWS = 10_000_000  # 1 crore
ACCOUNT_ID = 1133
DELTA_COUNT = 45
DEFAULT_PER_DELTA = 120_000

# Columns that deltas may overwrite (plus id / account_id / delta_seq / updated_at)
DELTA_FIELDS = (
    "email_status",
    "sms_status",
    "is_deleted",
    "is_contact",
    "last_emailed",
    "last_sms",
    "email_suppressed_on",
    "sms_suppressed_on",
    "email_bounce_count",
    "last_open",
    "last_click",
)


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--rows", type=int, default=ROWS)
    ap.add_argument("--deltas", type=int, default=DELTA_COUNT)
    ap.add_argument("--out", type=Path, default=DEFAULT_OUT)
    ap.add_argument("--threads", type=int, default=4)
    ap.add_argument("--memory", default="4GB")
    ap.add_argument("--per-delta", type=int, default=DEFAULT_PER_DELTA, help="rows written per delta file")
    args = ap.parse_args()

    out: Path = args.out
    if out.exists():
        shutil.rmtree(out)

    base_dir = out / "contact" / "base" / f"account_id={ACCOUNT_ID}"
    delta_root = out / "contact" / "delta" / f"account_id={ACCOUNT_ID}"
    block_dir = out / "block_file_data"
    base_dir.mkdir(parents=True, exist_ok=True)
    delta_root.mkdir(parents=True, exist_ok=True)
    block_dir.mkdir(parents=True, exist_ok=True)

    con = duckdb.connect()
    con.execute(f"SET threads={int(args.threads)}")
    con.execute(f"SET memory_limit='{args.memory}'")
    con.execute("SET TimeZone='UTC'")

    rows = int(args.rows)
    n_deltas = max(1, int(args.deltas))
    per_delta = max(100, int(args.per_delta))
    base_path = base_dir / "base.parquet"

    print(
        f"base+delta seed → {out} | base_rows={rows:,} deltas={n_deltas} "
        f"per_delta≈{per_delta:,}",
        flush=True,
    )
    t0 = time.perf_counter()

    # ---- base (full contact shape + delta-capable columns at baseline) ----
    con.execute(
        f"""
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
    0::INTEGER AS email_suppressed_on,
    0::INTEGER AS sms_suppressed_on,
    0::INTEGER AS email_bounce_count,
    0::INTEGER AS last_open,
    0::INTEGER AS last_click,
    printf('ACC%08d', i) AS f2,
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
  FROM generate_series(1, {rows}) AS t(i)
) TO '{base_path}' (FORMAT PARQUET, COMPRESSION ZSTD)
"""
    )
    base_sz = base_path.stat().st_size
    print(f"  base → {base_path.name} ({base_sz / 1e6:.1f} MB)", flush=True)

    # ---- deltas (sparse overrides; later delta_seq wins) ----
    for d in range(1, n_deltas + 1):
        ddir = delta_root / f"delta_id={d:03d}"
        ddir.mkdir(parents=True, exist_ok=True)
        dest = ddir / "part-0.parquet"
        # Stride so files overlap some ids (later seq wins) while covering the lake
        stride = max(3, rows // (per_delta * 2))
        offset = ((d - 1) * 97) % stride
        con.execute(
            f"""
COPY (
  SELECT
    i::BIGINT AS id,
    {ACCOUNT_ID}::INTEGER AS account_id,
    {d}::INTEGER AS delta_seq,
    (epoch(TIMESTAMP '2024-01-01')::BIGINT + {d} * 3600 + (i % 100))::INTEGER AS updated_at,
    -- flip status / suppressions for a subset
    CASE
      WHEN i % 11 = 0 THEN 2::TINYINT
      WHEN i % 13 = 0 THEN 5::TINYINT
      ELSE 1::TINYINT
    END AS email_status,
    CASE WHEN i % 17 = 0 THEN 5::TINYINT ELSE 1::TINYINT END AS sms_status,
    CASE WHEN i % 23 = 0 THEN 1::TINYINT ELSE 0::TINYINT END AS is_deleted,
    1::TINYINT AS is_contact,
    (epoch(now())::BIGINT - (i % 30) * 86400)::INTEGER AS last_emailed,
    CASE WHEN i % 19 = 0 THEN (epoch(now())::BIGINT - (i % 5) * 86400)::INTEGER ELSE 0 END AS last_sms,
    CASE WHEN i % 11 = 0 OR i % 13 = 0
         THEN (epoch(now())::BIGINT - (i % 14) * 86400)::INTEGER
         ELSE 0 END AS email_suppressed_on,
    CASE WHEN i % 17 = 0
         THEN (epoch(now())::BIGINT - (i % 9) * 86400)::INTEGER
         ELSE 0 END AS sms_suppressed_on,
    (i % 4)::INTEGER AS email_bounce_count,
    CASE WHEN i % 7 = 0 THEN (epoch(now())::BIGINT - (i % 20) * 3600)::INTEGER ELSE 0 END AS last_open,
    CASE WHEN i % 29 = 0 THEN (epoch(now())::BIGINT - (i % 15) * 3600)::INTEGER ELSE 0 END AS last_click
  FROM generate_series(1 + {offset}, {rows}, {stride}) AS t(i)
  LIMIT {per_delta}
) TO '{dest}' (FORMAT PARQUET, COMPRESSION ZSTD)
"""
        )
        n = con.execute(f"SELECT count(*) FROM read_parquet('{dest}')").fetchone()[0]
        if d == 1 or d == n_deltas or d % 10 == 0:
            print(f"  delta {d:03d}/{n_deltas}: rows={n:,} → {dest}", flush=True)

    # ---- block files (active-email friendly rem) ----
    block_path = block_dir / "part-0.parquet"
    con.execute(
        f"""
COPY (
  SELECT * FROM (
    SELECT 56::BIGINT AS block_file_id, printf('ACC%08d', i) AS unique_identifier
    FROM generate_series(1, {rows}) AS t(i)
    WHERE i % 500 = 7
    UNION ALL
    SELECT 57::BIGINT, printf('ACC%08d', i)
    FROM generate_series(1, {rows}) AS t(i)
    WHERE i % 2000 = 13
  )
) TO '{block_path}' (FORMAT PARQUET, COMPRESSION ZSTD)
"""
    )

    # ---- manifest for UI / API discovery ----
    manifest = {
        "id": "dummy_base_delta",
        "name": "Base + deltas (SFTP contact)",
        "layout": "base_delta",
        "account_id": ACCOUNT_ID,
        "base_rows": rows,
        "delta_files": n_deltas,
        "delta_columns": list(DELTA_FIELDS)
        + ["id", "account_id", "delta_seq", "updated_at"],
        "paths": {
            "base_glob": "contact/base/**/*.parquet",
            "delta_glob": "contact/delta/**/*.parquet",
            "block_file_glob": "block_file_data/*.parquet",
        },
    }
    (out / "datasource.json").write_text(json.dumps(manifest, indent=2) + "\n")

    # sanity: merged active count via same COALESCE pattern as API
    base_glob = str(base_dir / "*.parquet").replace("'", "''")
    delta_glob = str(delta_root / "**" / "*.parquet").replace("'", "''")
    merged = con.execute(
        f"""
SELECT
  count(*)::BIGINT AS total,
  count(*) FILTER (WHERE email_status = 1 AND is_deleted = 0)::BIGINT AS active
FROM (
  SELECT
    COALESCE(d.email_status, b.email_status) AS email_status,
    COALESCE(d.is_deleted, b.is_deleted) AS is_deleted
  FROM read_parquet('{base_glob}') b
  LEFT JOIN (
    SELECT * EXCLUDE (_rn) FROM (
      SELECT *, ROW_NUMBER() OVER (
        PARTITION BY id ORDER BY delta_seq DESC, updated_at DESC
      ) AS _rn
      FROM read_parquet('{delta_glob}', hive_partitioning=true, union_by_name=true)
    ) WHERE _rn = 1
  ) d ON b.id = d.id
)
"""
    ).fetchone()

    elapsed = time.perf_counter() - t0
    print(
        f"done in {elapsed:.1f}s | merged total={merged[0]:,} active={merged[1]:,} "
        f"| delta_fields={len(DELTA_FIELDS)}",
        flush=True,
    )
    print(f"parquetPath={out}", flush=True)


if __name__ == "__main__":
    main()
