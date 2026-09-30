# PHP Parquet + DuckDB

PHP port of the TempCodeShare Go backend. Durable state is only Parquet under `data/warehouse/`. DuckDB is the SQL engine (CLI). There is no MySQL database and no durable `.duckdb` file.

Reads merge snapshot and delta files and keep the latest row (`_ts`, `_seq`). `_op = 'delete'` drops the row. Compact rewrites the snapshot and removes the deltas that were merged.

## Layout

```
src/Warehouse/Paths.php     Hive paths (contact, campaign_sub, campaign, segment, dlr_event, api_logs)
src/Duck/Session.php        DuckDB CLI
src/Duck/Views.php          snapshot ∪ delta views
src/Schema/ContactAttrs.php wide contact columns
src/Filter/Sql.php          segment filter AST → SQL
src/Store/Query.php         contact, segment, campaign, api_logs reads
src/Bulk/Importer.php       CSV / Parquet → warehouse
src/Compact/Service.php     snapshot rewrite
src/Pipeline/Service.php    save segment, launch campaign, DLR → Parquet
src/Api/Http.php            same HTTP routes as the Go API
public/index.php
bin/server
```

```
data/warehouse/
  contact/snapshot/account_id={id}/*.parquet
  contact/delta/account_id={id}/delta_date={YYYY-MM-DD}/*.parquet
  campaign_sub/snapshot|delta/campaign_id={id}/...
  campaign/delta/account_id={id}/delta_date={YYYY-MM-DD}/*.parquet
  segment/account_id={id}/*.parquet
  campaign_activity/dt={YYYY-MM-DD}/*.parquet
  dlr_event/dt={YYYY-MM-DD}/*.parquet
  api_logs/dt={YYYY-MM-DD}/*.parquet
```

The Go service published `api_logs` through Kafka. This port writes each DLR straight to a new Parquet file so the API starts without Redpanda.

## Python DuckDB sidecar (recommended for prod reads)

Official **Python `duckdb`** client in a long-lived process. PHP calls it over HTTP — no third-party PHP extension required.

### Step 1 — Install Python deps

```bash
cd ~/Projects/php-parquet-duckdb
python3 -m pip install --user -r sidecar/requirements.txt
# verify
python3 -c "import duckdb; print(duckdb.__version__)"
```

### Step 2 — Start the sidecar (terminal 1)

```bash
./bin/sidecar
# listens on http://127.0.0.1:8090
```

Check:

```bash
curl -sS http://127.0.0.1:8090/health
```

### Step 3 — Point PHP at the sidecar (terminal 2)

```bash
export DUCKDB_SIDECAR_URL=http://127.0.0.1:8090
./bin/server
```

```bash
curl -sS http://127.0.0.1:8080/api/health
# expect: "driver":"python_sidecar","persistent":true
```

### Step 4 — Query Parquet through the API / Session

```bash
# direct sidecar (lake path)
curl -sS -X POST http://127.0.0.1:8090/query \
  -H 'Content-Type: application/json' \
  -d '{"sql":"SELECT count(*)::BIGINT AS cnt FROM read_parquet('\''/Users/lumegalabs/Downloads/temp-cursor/segmentation-poc/data/parquet_contacts_5cr/contact/**/*.parquet'\'', hive_partitioning=true, union_by_name=true) WHERE account_id=83"}'

# via PHP API (warehouse contacts, after import)
curl -sS 'http://127.0.0.1:8080/api/contacts?account_id=42&limit=10'
```

### Env

| Variable | Default | Meaning |
|----------|---------|---------|
| `DUCKDB_SIDECAR_URL` | _(unset)_ | If set, PHP uses sidecar (skips pdo/cli) |
| `SIDECAR_HOST` / `SIDECAR_PORT` | `127.0.0.1` / `8090` | Sidecar bind |
| `DUCKDB_THREADS` | `4` | Cores used **inside one query** |
| `DUCKDB_POOL_SIZE` | `4` | How many queries can run **at the same time** (connection pool) |
| `DUCKDB_POOL_WAIT` | `60` | Seconds a request waits for a free connection |
| `DUCKDB_MEMORY_LIMIT` | `4GB` | Cap **per** pool connection |
| `DUCKDB_SIDECAR_TIMEOUT` | `600` | PHP HTTP timeout (seconds) |

**Parallelism:** `POOL_SIZE` concurrent users × `THREADS` cores per query.  
Example: `POOL_SIZE=4`, `THREADS=4` → up to 4 queries in parallel; each may use up to 4 threads.

Restart sidecar after changing pool env:

```bash
DUCKDB_POOL_SIZE=8 DUCKDB_THREADS=4 ./bin/sidecar
curl -sS http://127.0.0.1:8090/health   # shows pool_size / pool_available
```

Driver priority: **sidecar → pdo_duckdb → CLI**.

## Persistent DuckDB (pdo_duckdb) — optional PHP-only path


Default code path uses **`pdo_duckdb` + `PDO::ATTR_PERSISTENT`** when the extension is loaded. Otherwise it falls back to the DuckDB CLI.

### 1. Install extension (macOS Apple Silicon + Homebrew PHP 8.5)

```bash
./scripts/install-pdo-duckdb.sh
php -m | grep pdo_duckdb
```

Linux / other PHP versions: download the matching zip from  
https://github.com/iliaal/pdo_duckdb/releases  
or `pie install iliaal/pdo_duckdb`, then `extension=pdo_duckdb` in `php.ini`.

### 2. Verify

```bash
php examples/persistent_pdo_read.php
# lake:
LAKE_GLOB='/Users/lumegalabs/Downloads/temp-cursor/segmentation-poc/data/parquet_contacts_5cr/contact/**/*.parquet' \
  php examples/persistent_pdo_read.php
```

### 3. Run API

```bash
./bin/server
curl -sS http://127.0.0.1:8080/api/health
# expect: "driver":"pdo_duckdb","persistent":true
```

Env: `DUCKDB_THREADS` (default 4), `DUCKDB_MEMORY_LIMIT` (default 4GB).

## Requirements


- PHP 8.2+
- [DuckDB CLI](https://duckdb.org/docs/installation/) on `PATH` (`DUCKDB_BIN` overrides the binary)

## Run

```bash
chmod +x bin/server
./bin/server
# WAREHOUSE_ROOT=data/warehouse ADDR=:8080
```

Open `http://127.0.0.1:8080/api/health`.

## Import and query

```bash
curl -sS -X POST http://127.0.0.1:8080/api/bulk/import \
  -H 'Content-Type: application/json' \
  -d '{"table":"contact","path":"demo/samples/contacts_sample.csv","account_id":42,"mode":"snapshot"}'

curl -sS 'http://127.0.0.1:8080/api/contacts?account_id=42&q=IN&limit=20'

curl -sS -X POST http://127.0.0.1:8080/api/segments/preview \
  -H 'Content-Type: application/json' \
  -d '{"account_id":42,"filter":{"op":"eq","field":"country","value":"IN"}}'
```

`mode=snapshot` replaces the account snapshot. `mode=delta` appends an upsert file.

## API

| Method | Path |
|--------|------|
| GET | `/api/health` |
| GET | `/api/schema/contacts` |
| GET | `/api/contacts` |
| GET/POST | `/api/segments`, `/api/segments/preview` |
| GET/POST | `/api/campaigns`, `/api/campaigns/{id}`, `.../launch`, `.../stats`, `.../subscribers`, `.../simulate-dlr` |
| POST | `/api/webhooks/dlr/{email\|sms}` |
| GET | `/api/api-logs` |
| GET | `/api/engine/stats` |
| GET/POST | `/api/admin/warehouse`, `compact/contact`, `compact/campaign-sub`, `reset` |
| GET | `/api/bulk/demo-files` |
| POST | `/api/bulk/import`, `/api/bulk/upload` |

Filter ops: `and`, `or`, `not`, `eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `in`, `not_in`, `contains`, `not_contains`, `starts_with`, `ends_with`, `is_null`, `not_null`, `between`, `not_between`.

## Env

| Variable | Default |
|----------|---------|
| `WAREHOUSE_ROOT` | `data/warehouse` |
| `ADDR` | `:8080` |
| `DUCKDB_BIN` | `duckdb` |
