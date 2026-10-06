# Campaign preview stress benchmark

Scenario: `seg_9001` (Mastercard segment) · **32 requests/cell** · warmup 3  
Sidecar after optimisations: **pool=8**, **threads=2**, **memory=2GB**  
PHP `parallel=true` fan-out capped at **`DUCKDB_PARALLEL_MAX=6`** (batches) to avoid OOM.

**Errors: 0** across all cells.

## Sidecar optimisations applied

| Change | Why |
|--------|-----|
| Skip no-op `SET threads` / `SET memory_limit` when request matches pool defaults | Every preview fans out many `/query` calls; avoids redundant Exec |
| `SET preserve_insertion_order=false` on pool init | Faster aggregations / scans |
| HTTP `IdleTimeout` + no write deadline | Long DuckDB queries must not be killed by `WriteTimeout` |
| Default pool **8** × 2GB (was 8×4GB / attempted 16) | Stable under parallel 1cr scans (16-slot pool OOMed) |
| PHP `queryMany` **batched** concurrency (`DUCKDB_PARALLEL_MAX`, default 6) | Prevents all COUNT queries hitting the pool at once |
| Health: `avg_pool_wait_ms`, `queries_total` | Observe pool contention under load |

## Latency under load (ms)

| Source | Query parallel | HTTP concurrency | HTTP requests | OK | Errors | p50 | p95 | p99 | max | avg | RPS | Wall (ms) |
|--------|----------------|-----------------:|--------------:|---:|-------:|----:|----:|----:|----:|----:|----:|----------:|
| Flat 1cr | seq | 1 | 32 | 32 | 0 | 2795.9 | 2826.7 | 2830.7 | 2832.3 | 2795.8 | 0.36 | 89466.9 |
| Flat 1cr | seq | 4 | 32 | 32 | 0 | 11081.9 | 11138.4 | 11155.2 | 11159.4 | 10558.6 | 0.36 | 88609.2 |
| Flat 1cr | seq | 8 | 32 | 32 | 0 | 22104.0 | 22131.7 | 22142.9 | 22145.3 | 19693.7 | 0.36 | 88427.6 |
| Flat 1cr | **par** | 1 | 32 | 32 | 0 | **1265.3** | **1316.5** | **1358.0** | 1372.0 | 1270.1 | **0.79** | 40643.4 |
| Flat 1cr | par | 4 | 32 | 32 | 0 | 5130.4 | 5177.0 | 5181.6 | 5181.7 | 4889.5 | 0.78 | 41064.4 |
| Flat 1cr | par | 8 | 32 | 32 | 0 | 10422.6 | 10496.8 | 10497.3 | 10497.6 | 9284.1 | 0.77 | 41695.3 |
| Base+45 | seq | 1 | 32 | 32 | 0 | 6011.8 | 6040.4 | 6045.6 | 6047.0 | 6011.4 | 0.17 | 192366.9 |
| Base+45 | seq | 4 | 32 | 32 | 0 | 23898.2 | 24101.5 | 24121.4 | 24130.4 | 22820.2 | 0.17 | 191546.6 |
| Base+45 | seq | 8 | 32 | 32 | 0 | 47444.1 | 47681.0 | 47696.8 | 47699.7 | 42333.9 | 0.17 | 190059.7 |
| Base+45 | **par** | 1 | 32 | 32 | 0 | **2315.6** | **2402.2** | **2490.8** | 2526.5 | 2319.3 | **0.43** | 74218.1 |
| Base+45 | par | 4 | 32 | 32 | 0 | 9220.3 | 9404.0 | 9415.4 | 9419.5 | 8829.8 | 0.43 | 74109.7 |
| Base+45 | par | 8 | 32 | 32 | 0 | 18302.9 | 18373.4 | 18410.2 | 18426.5 | 16322.9 | 0.44 | 73302.3 |
| Compact 10 | seq | 1 | 32 | 32 | 0 | 3942.5 | 3974.6 | 3977.0 | 3977.1 | 3943.6 | 0.25 | 126197.6 |
| Compact 10 | seq | 4 | 32 | 32 | 0 | 15765.0 | 15867.7 | 15879.8 | 15884.4 | 15034.3 | 0.25 | 126183.6 |
| Compact 10 | seq | 8 | 32 | 32 | 0 | 31554.2 | 31596.8 | 31599.5 | 31600.2 | 28118.2 | 0.25 | 126290.6 |
| Compact 10 | **par** | 1 | 32 | 32 | 0 | **1687.1** | **1738.4** | **1768.0** | 1774.9 | 1686.4 | **0.59** | 53964.9 |
| Compact 10 | par | 4 | 32 | 32 | 0 | 6902.9 | 7026.4 | 7035.4 | 7039.1 | 6591.3 | 0.58 | 55350.8 |
| Compact 10 | par | 8 | 32 | 32 | 0 | 13605.1 | 13812.2 | 13833.3 | 13834.1 | 12182.1 | 0.59 | 54653.9 |

## Takeaways

- **`parallel=true` ≈ 2.1–2.6× faster** at HTTP concurrency 1 (p50) vs sequential on the same lake.
- Under HTTP concurrency 4/8, latency scales ~linearly (pool saturated); **throughput (RPS) stays flat** — more clients share the same DuckDB pool, they don’t add capacity.
- **Compacted 10 deltas** is clearly faster than **45 deltas** at every concurrency (p50 par c=1: 1.7s vs 2.3s).
- **Flat 1cr** remains the fastest lake for this filter (p50 par c=1: **1.27s**, p99 **1.36s**).

## How to re-run

```bash
# restart optimised sidecar
DUCKDB_POOL_SIZE=8 DUCKDB_THREADS=2 DUCKDB_MEMORY_LIMIT=2GB ./bin/go-sidecar

python3 tests/stress_campaign_preview.py --source all --parallel both \
  --concurrency 1,4,8 --requests 32 --warmup 3
```

Raw JSON: [`stress_campaign_preview.json`](./stress_campaign_preview.json)
