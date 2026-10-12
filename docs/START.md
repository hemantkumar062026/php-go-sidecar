# How to start the project

Run commands from the repo root:

```bash
cd /path/to/php-parquet-duckdb
```

Start in this order: **sidecar → PHP API → DuckDB UI** (optional).

---

## 1. UI (campaign page)

| | |
|--|--|
| **Code** | `public/campaign.html` |
| **Served by** | PHP server (`public/` is the web root) |
| **URL** | http://127.0.0.1:8080/campaign.html |

No separate frontend build. Open the URL after the PHP API is running.

---

## 2. Backend

### Go DuckDB sidecar (query engine)

| | |
|--|--|
| **Code** | `sidecar/go/` |
| **Runner** | `bin/go-sidecar-run` |
| **URL** | http://127.0.0.1:8091 |

```bash
DUCKDB_POOL_SIZE=8 DUCKDB_THREADS=4 DUCKDB_MEMORY_LIMIT=2GB SIDECAR_PORT=8091 ./bin/go-sidecar-run
```

Health check:

```bash
curl http://127.0.0.1:8091/health
```

### PHP API

| | |
|--|--|
| **Code** | `src/` (routes in `src/Api/Http.php`, preview in `src/Filter/`) |
| **Entry** | `public/index.php` via `bin/server` |
| **URL** | http://127.0.0.1:8080 |

```bash
export DUCKDB_SIDECAR_URL=http://127.0.0.1:8091
export DUCKDB_SIDECAR_KIND=go
export WAREHOUSE_ROOT=data/warehouse
ADDR=:8080 ./bin/server
```

---

## 3. DuckDB web UI (optional)

| | |
|--|--|
| **Tool** | Official DuckDB CLI (`duckdb -ui` / `start_ui_server`) |
| **URL** | http://localhost:4213/ |
| **Views SQL** | e.g. `data/duckdb_ui_views.sql` (or init SQL with lake views) |

Keep the process alive (UI stops when the CLI exits):

```bash
# Simple
duckdb -ui

# Or: in-memory + views, keep running
duckdb -init /tmp/duckdb_ui_init.sql -c "SELECT sleep_ms(86400000);"
```

Init SQL should `INSTALL ui; LOAD ui;`, create lake views if needed, then `CALL start_ui_server();`.

---

## Full commands (3 terminals)

Use the same repo root in every terminal:

```bash
cd /Users/lumegalabs/Projects/php-parquet-duckdb
export PATH="/opt/homebrew/bin:$PATH"
```

### Terminal 1 — Go DuckDB sidecar

```bash
cd /Users/lumegalabs/Projects/php-parquet-duckdb
export PATH="/opt/homebrew/bin:$PATH"
DUCKDB_POOL_SIZE=8 DUCKDB_THREADS=4 DUCKDB_MEMORY_LIMIT=2GB SIDECAR_PORT=8091 ./bin/go-sidecar-run
```

Leave this running. Check: `curl http://127.0.0.1:8091/health`

### Terminal 2 — PHP API + campaign UI

```bash
cd /Users/lumegalabs/Projects/php-parquet-duckdb
export PATH="/opt/homebrew/bin:$PATH"
export DUCKDB_SIDECAR_URL=http://127.0.0.1:8091
export DUCKDB_SIDECAR_KIND=go
export WAREHOUSE_ROOT=data/warehouse
ADDR=:8080 ./bin/server
```

Leave this running. Open: http://127.0.0.1:8080/campaign.html

### Terminal 3 — DuckDB web UI (optional)

```bash
cd /Users/lumegalabs/Projects/php-parquet-duckdb
export PATH="/opt/homebrew/bin:$PATH"
duckdb -ui
```

Or keep it alive with views (in-memory):

```bash
cd /Users/lumegalabs/Projects/php-parquet-duckdb
export PATH="/opt/homebrew/bin:$PATH"
cat > /tmp/duckdb_ui_init.sql <<'EOF'
INSTALL ui;
LOAD ui;
CALL start_ui_server();
EOF
duckdb -init /tmp/duckdb_ui_init.sql -c "SELECT sleep_ms(86400000);"
```

Open: http://localhost:4213/

### Browser (optional)

```bash
open http://127.0.0.1:8080/campaign.html
open http://localhost:4213/
```
