#!/usr/bin/env python3
"""Compact a base+delta lake: fold older deltas into base, keep N newest delta files.

Default: data/dummy_base_delta (45 deltas) → data/dummy_base_delta_10 (10 deltas).
Merged audience should match the source lake (same COALESCE semantics).
"""

from __future__ import annotations

import argparse
import json
import shutil
import time
from pathlib import Path

import duckdb

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_SRC = ROOT / "data" / "dummy_base_delta"
DEFAULT_OUT = ROOT / "data" / "dummy_base_delta_10"
ACCOUNT_ID = 1133
KEEP_DELTAS = 10

DELTA_OVERLAY_COLS = (
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
    ap.add_argument("--src", type=Path, default=DEFAULT_SRC)
    ap.add_argument("--out", type=Path, default=DEFAULT_OUT)
    ap.add_argument("--keep", type=int, default=KEEP_DELTAS, help="delta files to keep after compact")
    ap.add_argument("--threads", type=int, default=4)
    ap.add_argument("--memory", default="4GB")
    args = ap.parse_args()

    src: Path = args.src
    out: Path = args.out
    keep = max(1, int(args.keep))

    src_base = src / "contact" / "base" / f"account_id={ACCOUNT_ID}"
    src_delta = src / "contact" / "delta" / f"account_id={ACCOUNT_ID}"
    if not src_base.is_dir() or not src_delta.is_dir():
        raise SystemExit(f"source lake missing base/delta under {src}")

    delta_dirs = sorted(
        [p for p in src_delta.iterdir() if p.is_dir() and p.name.startswith("delta_id=")],
        key=lambda p: p.name,
    )
    if not delta_dirs:
        raise SystemExit(f"no delta_id=* dirs in {src_delta}")
    if len(delta_dirs) < keep:
        raise SystemExit(f"need at least {keep} deltas, found {len(delta_dirs)}")

    fold_dirs = delta_dirs[:-keep]
    keep_dirs = delta_dirs[-keep:]

    if out.exists():
        shutil.rmtree(out)

    out_base_dir = out / "contact" / "base" / f"account_id={ACCOUNT_ID}"
    out_delta_root = out / "contact" / "delta" / f"account_id={ACCOUNT_ID}"
    out_base_dir.mkdir(parents=True, exist_ok=True)
    out_delta_root.mkdir(parents=True, exist_ok=True)

    # copy block files as-is
    src_block = src / "block_file_data"
    if src_block.is_dir():
        shutil.copytree(src_block, out / "block_file_data")

    con = duckdb.connect()
    con.execute(f"SET threads={int(args.threads)}")
    con.execute(f"SET memory_limit='{args.memory}'")
    con.execute("SET TimeZone='UTC'")

    base_glob = str(src_base / "*.parquet").replace("'", "''")
    out_base = out_base_dir / "base.parquet"
    t0 = time.perf_counter()

    print(
        f"compact {src.name} → {out.name}: fold {len(fold_dirs)} deltas into base, "
        f"keep {len(keep_dirs)} deltas",
        flush=True,
    )

    if fold_dirs:
        # Only fold older deltas (exclude the keep set) into base
        fold_globs = [
            str(d / "*.parquet").replace("'", "''") for d in fold_dirs
        ]
        # DuckDB list of globs via UNION ALL of read_parquet
        union_parts = " UNION ALL BY NAME ".join(
            f"SELECT * FROM read_parquet('{g}', hive_partitioning=true, union_by_name=true)"
            for g in fold_globs
        )
        coalesce_cols = ",\n    ".join(
            f"COALESCE(d.{c}, b.{c}) AS {c}" for c in DELTA_OVERLAY_COLS
        )
        sql = f"""
COPY (
  SELECT
    b.id,
    b.account_id,
    b.primary_key,
    b.email,
    b.mobile,
    {coalesce_cols},
    b.is_preview,
    b.f2, b.f6, b.f7, b.f18, b.f30, b.f31
  FROM read_parquet('{base_glob}', hive_partitioning=true, union_by_name=true) AS b
  LEFT JOIN (
    SELECT * EXCLUDE (_rn) FROM (
      SELECT *, ROW_NUMBER() OVER (
        PARTITION BY id ORDER BY delta_seq DESC, updated_at DESC
      ) AS _rn
      FROM ({union_parts})
    ) WHERE _rn = 1
  ) AS d ON b.id = d.id
) TO '{out_base}' (FORMAT PARQUET, COMPRESSION ZSTD)
"""
        con.execute(sql)
    else:
        shutil.copy2(next(src_base.glob("*.parquet")), out_base)

    print(f"  new base → {out_base} ({out_base.stat().st_size / 1e6:.1f} MB)", flush=True)

    # Copy remaining deltas, renumber 001..keep
    for i, ddir in enumerate(keep_dirs, start=1):
        dest_dir = out_delta_root / f"delta_id={i:03d}"
        dest_dir.mkdir(parents=True, exist_ok=True)
        src_part = next(ddir.glob("*.parquet"))
        # Rewrite delta_seq to match new id for clean ordering
        dest = dest_dir / "part-0.parquet"
        con.execute(
            f"""
COPY (
  SELECT * EXCLUDE (delta_seq), {i}::INTEGER AS delta_seq
  FROM read_parquet('{src_part}')
) TO '{dest}' (FORMAT PARQUET, COMPRESSION ZSTD)
"""
        )
        n = con.execute(f"SELECT count(*) FROM read_parquet('{dest}')").fetchone()[0]
        print(f"  keep delta {i:03d}: rows={n:,} (from {ddir.name})", flush=True)

    # Sanity: compacted merge vs source merge row counts / sample active
    src_delta_glob = str(src_delta / "**" / "*.parquet").replace("'", "''")
    out_delta_glob = str(out_delta_root / "**" / "*.parquet").replace("'", "''")
    out_base_g = str(out_base).replace("'", "''")

    def active_sql(base_g: str, delta_g: str) -> str:
        return f"""
SELECT
  count(*)::BIGINT AS total,
  count(*) FILTER (WHERE email_status=1 AND is_deleted=0)::BIGINT AS active
FROM (
  SELECT
    COALESCE(d.email_status, b.email_status) AS email_status,
    COALESCE(d.is_deleted, b.is_deleted) AS is_deleted
  FROM read_parquet('{base_g}', hive_partitioning=true, union_by_name=true) b
  LEFT JOIN (
    SELECT * EXCLUDE (_rn) FROM (
      SELECT *, ROW_NUMBER() OVER (
        PARTITION BY id ORDER BY delta_seq DESC, updated_at DESC
      ) AS _rn
      FROM read_parquet('{delta_g}', hive_partitioning=true, union_by_name=true)
    ) WHERE _rn = 1
  ) d ON b.id = d.id
)
"""

    src_stats = con.execute(active_sql(base_glob, src_delta_glob)).fetchone()
    out_stats = con.execute(active_sql(out_base_g, out_delta_glob)).fetchone()

    manifest = {
        "id": out.name,
        "name": "Base + deltas (compacted 10)",
        "layout": "base_delta",
        "account_id": ACCOUNT_ID,
        "base_rows": int(out_stats[0]),
        "delta_files": keep,
        "compacted_from": str(src),
        "folded_delta_files": len(fold_dirs),
        "delta_columns": list(DELTA_OVERLAY_COLS)
        + ["id", "account_id", "delta_seq", "updated_at"],
        "paths": {
            "base_glob": "contact/base/**/*.parquet",
            "delta_glob": "contact/delta/**/*.parquet",
            "block_file_glob": "block_file_data/*.parquet",
        },
    }
    (out / "datasource.json").write_text(json.dumps(manifest, indent=2) + "\n")

    elapsed = time.perf_counter() - t0
    print(
        f"done in {elapsed:.1f}s | src total={src_stats[0]:,} active={src_stats[1]:,} "
        f"| out total={out_stats[0]:,} active={out_stats[1]:,}",
        flush=True,
    )
    if src_stats[0] != out_stats[0] or src_stats[1] != out_stats[1]:
        print(
            "WARNING: compacted active/total differs from source "
            f"(src={src_stats} out={out_stats})",
            flush=True,
        )
    print(f"parquetPath={out}", flush=True)


if __name__ == "__main__":
    main()
