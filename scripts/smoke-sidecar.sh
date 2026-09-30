#!/usr/bin/env bash
# Smoke-test the Python DuckDB sidecar + PHP client.
set -euo pipefail
cd "$(dirname "$0")/.."
export PATH="/opt/homebrew/bin:$PATH"
export DUCKDB_SIDECAR_URL="${DUCKDB_SIDECAR_URL:-http://127.0.0.1:8090}"
GLOB="${LAKE_GLOB:-/Users/lumegalabs/Downloads/temp-cursor/segmentation-poc/data/parquet_contacts_5cr/contact/**/*.parquet}"

echo "== health =="
curl -fsS "$DUCKDB_SIDECAR_URL/health"
echo

echo "== query via sidecar =="
curl -fsS -X POST "$DUCKDB_SIDECAR_URL/query" \
  -H 'Content-Type: application/json' \
  -d "{\"sql\":\"SELECT count(*)::BIGINT AS cnt FROM read_parquet('${GLOB}', hive_partitioning=true, union_by_name=true) WHERE account_id=83\"}"
echo

echo "== PHP Session driver =="
php -r 'require "src/bootstrap.php"; echo App\Duck\Session::driver(), "\n";
$s=new App\Duck\Session();
$g=getenv("GLOB") ?: "'"$GLOB"'";
$rows=$s->query("SELECT count(*)::BIGINT AS cnt FROM read_parquet('\''$g'\'', hive_partitioning=true, union_by_name=true) WHERE account_id=83");
echo json_encode($rows), "\n";'
