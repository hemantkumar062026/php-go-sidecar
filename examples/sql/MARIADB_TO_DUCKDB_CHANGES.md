# MariaDB → DuckDB query port (segment + campaign)

Files:
- `examples/sql/segment_stats_duckdb.sql`
- `examples/sql/campaign_stats_duckdb.sql`

Logic / filters / metrics columns are the same. Only dialect / source syntax changed.

---

## What changed

| # | MariaDB | DuckDB | Why |
|---|---------|--------|-----|
| 1 | `` `email_status` `` backticks | bare `email_status` (or `"email_status"`) | DuckDB does **not** accept MySQL backticks |
| 2 | `!(...)` | `NOT (...)` | DuckDB has no `!` boolean NOT operator (`!` errors) |
| 3 | `FROM sftp_contact_1133 AS c` | `FROM read_parquet('.../contact/**/*.parquet', hive_partitioning=true, union_by_name=true) AS c` | No MySQL table in DuckDB; lake is Parquet. Keep `WHERE account_id = 1133` |
| 4 | `block_file_data` (MySQL table) | same name, but must exist as DuckDB **view/table/Parquet** | Subqueries unchanged; you must load/register block file data |
| 5 | Extra parentheses nesting | lightly cleaned for readability | Same boolean meaning |

### Kept as-is (already OK in DuckDB)

- `IF(condition, a, b)`
- `COALESCE`, `NULLIF`, `TRIM`
- `SUM(...)`, `COUNT(DISTINCT CASE WHEN ... END)`
- `EXISTS` / `NOT EXISTS`
- `IN (...)`, `IS NULL`, comparisons

---

## Segment filter (same meaning)

```text
account_id = 1133
AND is_deleted = 0
AND f30 = 'Mastercard'
AND f31 = 50
AND f18 = 'Delhi'
AND (f6 IS NULL OR f6 NOT IN ('ICICI','Mastercard'))
```

Metrics: `cnt`, `email_active`, `email_unsubscribed`, `email_bounced`, `email_marked_spam`, `email_manually_suppressed`, `email_unconfirmed`, `sms_active`, `sms_manually_suppressed`, `email_all_suppressed`.

---

## Campaign extras (same meaning)

- Include rules: Mastercard+age/city OR age>51+Mastercard, **or** include block file `56` on `f2`
- Exclude rules: ICICI rule / block file `57` on `f2` (via `NOT` + `NOT EXISTS`)
- Metrics: `cnt` (distinct emails), `duplicate_count`, `include_count`, `exclude_count`, plus same status breakdowns

---

## How to run (Go sidecar example)

```bash
# 1) empty block_file stand-in (if you don't have blocks yet)
curl -sS -X POST http://127.0.0.1:8091/exec -H 'Content-Type: application/json' \
  -d '{"sql":"CREATE OR REPLACE TEMP VIEW block_file_data AS SELECT CAST(NULL AS BIGINT) AS block_file_id, CAST(NULL AS VARCHAR) AS unique_identifier WHERE FALSE"}'

# 2) segment (paste SQL from segment_stats_duckdb.sql into "sql")
curl -sS -X POST http://127.0.0.1:8091/query -H 'Content-Type: application/json' \
  -d @- <<'EOF'
{"sql":"SELECT 1"}
EOF
```

Or with CLI:

```bash
duckdb < examples/sql/segment_stats_duckdb.sql
```

---

## Dummy data (loaded)

```text
data/dummy/contact/account_id=1133/part-0.parquet   # 15 contacts
data/dummy/block_file_data/part-0.parquet           # block 56 include, 57 exclude
```

Regenerate:

```bash
python3 scripts/seed_dummy_sftp.py
```

Run (from repo root):

```bash
duckdb -c ".read examples/sql/segment_stats_duckdb.sql"
duckdb -c ".read examples/sql/campaign_stats_duckdb.sql"
```

Sample results on dummy: segment `cnt=7`; campaign `cnt=5`, `duplicate_count=1`.

---

## Important data note

1. Point `read_parquet(...)` at `data/dummy/...` for local demos, or at your real SFTP export for production numbers.
2. Register `block_file_data` from Parquet/CSV the same way (already done in the example SQL files).
