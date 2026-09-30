# DuckDB read benchmarks — simple guide

This report compares **four ways** your PHP app can read the same Parquet contact lake with DuckDB.

**What we tested on**
- **5 crore (50 million)** contact rows
- About **1 GB** of Parquet files on disk
- **43 columns** in the full table

**How we measured**
- **Latency** = how long the query took (wall clock)
- **RAM** = how much memory the engine used while running

**DuckDB versions**
- Python sidecar and Go sidecar (current): **DuckDB 1.4.5** (same engine)
---

## The four options (in plain words)

| Option | What it is | Good to know |
|--------|------------|--------------|
| **Python sidecar** | A small Python service that keeps DuckDB open. PHP talks to it over HTTP (localhost). | Official DuckDB Python client. Slight edge in our fair 1.4.5 runs. |
| **Go sidecar** | Same idea as Python, but written in Go. PHP still uses HTTP. | Now on **DuckDB 1.4.5** (`duckdb-go` v2.5.6) — roughly tied with Python (~5%). |
| **satur.io** | DuckDB runs **inside** each PHP process (FFI library). | Simple to call from PHP, but every PHP process brings its own DuckDB → more RAM when many requests run together. |
| **CLI** | PHP starts the `duckdb` command for every query, then exits. | Easy to approve / demo. Not ideal when many users hit it at once (starts a new process each time). |

Sidecar pool settings used in these runs: **8 connections**, **2 threads** each, **4 GB** memory limit per connection.

---

## Part 1 — Heavy work: read *everything*

### What this means

Imagine you ask: “Give me **all columns** for **all 5 crore rows**” (no filter).

That is the worst-case read. We did it by writing the full result out as compressed Parquet (`COPY … SELECT *`), so the engine truly touches every cell — without trying to dump 50M rows into PHP memory as JSON.

### 4 users at once (same full `SELECT *`)

| Option | Total time for 4 parallel jobs | Memory at peak | Takeaway |
|--------|-------------------------------:|---------------:|----------|
| **Python sidecar** (1.4.5) | **~18 seconds** | ~6.1 GB | Fastest shared engine |
| **Go sidecar** (1.4.5) | **~19 seconds** | ~6.2 GB | ~5% behind Python — same DuckDB |
| satur.io | ~36 seconds | **~9 GB** | Slower; 4 separate DuckDBs in PHP |
| **CLI** | **~36 seconds** | **~9.5 GB** | Same class as satur.io — new `duckdb` process per job |

*(Python/Go row: fair re-run in `docs/bench_python_vs_go_145.json`. satur.io/CLI: earlier four-way heavy run.)*

**In short:** With the **same DuckDB 1.4.5**, Python and Go sidecars are in the same class for huge full-table reads.  
**CLI** and **satur.io** stay slower and use more RAM because each parallel job starts its **own** DuckDB.

---

## Part 2 — Real app work: count + a few columns

### What this means

Most product queries are *not* “read the whole lake”. They look more like:

- **Count** how many contacts match a segment (filters on a handful of fields)
- Optionally **return ~10 columns** for a small page (`LIMIT 50`)

We used filters on **9 fields** (still under the “max ~10 columns” idea): account, email status, deleted/contact/preview flags, domain, import source, campaign sent count, and a custom field `f6`.

All drivers returned the **same count: 4,762**.

### Segment count — one request vs 20 at once

| Option | One request (typical) | 20 requests together | Memory (1 req / idle→peak) | Memory (20 together) |
|--------|----------------------:|---------------------:|---------------------------:|---------------------:|
| **Python sidecar** (1.4.5) | **~0.49 s** | **~2.5 s** |  **~504 MB** | **~504 MB** (shared) |
| **Go sidecar** (1.4.5) | **~0.51 s** | **~2.5 s** | **~611 MB** | **~611 MB** (shared) |
| CLI | ~0.6 s | ~3.1 s | ~80 MB each | **~1.8 GB** total |
| satur.io | ~0.6 s | ~3.1 s | ~115 MB each | **~2.2 GB** total |

**How to read the RAM column**
- **Sidecars** share one long-running process → memory stays in one place.
- **CLI / satur.io** start (or embed) DuckDB per request → under 20 parallel users, memory **adds up**.

### Same filters, but return 10 columns (page of 50 rows)

| Option | One request | 20 together | Memory (20 together) |
|--------|------------:|------------:|---------------------:|
| **Python sidecar** | **~0.6 s** | **~2.9 s** | ~870 MB |
| satur.io | ~0.7 s | ~3.2 s | ~2.4 GB total |
| CLI | ~0.7 s | ~3.5 s | ~1.8 GB total |


### Fair Python vs Go (DuckDB 1.4.5 only)

| Workload | Python | Go | Go vs Python |
|----------|-------:|---:|-------------:|
| Segment count (p50, 1 req) | 486 ms | 513 ms | **1.06×** |
| Segment count (20 parallel, batch) | 2.45 s | 2.53 s | **1.03×** |
| Heavy `SELECT *` ×4 (batch) | 18.4 s | 19.4 s | **1.06×** |


---

## What should you use?

| Your situation | Best choice | Why |
|----------------|-------------|-----|
| Production reads from Parquet (normal + heavy) | **Python or Go sidecar** (1.4.5) | Same engine class; Python ~5% faster in fair runs; both share one pool |
| Prefer official client / slightly lower latency | Python sidecar | Official DuckDB Python binding |
| Prefer a single Go binary + HTTP | Go sidecar | Same DuckDB 1.4.5; pool + low process count |
| Quick demo or “no extra service” experiment | CLI | Simple, but weak under many parallel users |
| PHP-only embed, low traffic | satur.io | Convenient, but watch RAM when traffic spikes |

### Bottom line

For read-heavy Parquet, **run a DuckDB sidecar (Python or Go on 1.4.5) and call it from PHP over HTTP**.

Use the CLI only for demos or admin scripts. Avoid starting a new DuckDB (CLI or satur.io) on every concurrent user if you care about memory.
