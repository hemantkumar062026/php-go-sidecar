# Campaign preview load test

Results from `tests/load_campaign_preview.py` against `POST /api/sftp/campaign-preview`.

## Summary

- **Failures:** 0
- **Correctness (seq ≡ par metrics):** 27/27
- **Median speedup (seq/par):** 2.03×
- **Average speedup (seq/par):** 1.99×

### Two different “parallel” knobs

| Knob | Meaning |
|------|---------|
| **Query `parallel`** | DuckDB COUNT queries inside one `/campaign-preview` call run concurrently |
| **HTTP load** | How many concurrent API requests hit the server |

## Load profile

| Test type | HTTP concurrency (workers) | HTTP requests fired | Query `parallel` |
|-----------|---------------------------:|--------------------:|------------------|
| Timed scenarios | **1** | 1 per timed sample (×2 repeats) | false / true |
| Burst | **6** | **12** | false / true |

> parallel=true means DuckDB COUNTs inside one API call run concurrently; http_concurrency is how many campaign-preview requests hit the API at once.

## Summary by source

| Source | Correct | Median speedup | Burst wall seq → par | Burst p50 seq → par | HTTP concurrency | HTTP requests |
|--------|---------|----------------|----------------------|---------------------|-----------------:|--------------:|
| Flat hive (1 crore) | 9/9 | 2.03× | 22.1s → 10.8s | 11.0s → 5.4s | 6 | 12 |
| Base + 45 deltas | 9/9 | 1.98× | 56.0s → 27.4s | 27.9s → 13.4s | 6 | 12 |
| Compacted 10 deltas | 9/9 | 2.12× | 35.1s → 16.8s | 17.5s → 8.3s | 6 | 12 |

## Burst load detail

| Source | Query parallel | HTTP concurrency | HTTP requests | OK | Wall (ms) | p50 latency (ms) | Final |
|--------|----------------|-----------------:|--------------:|---:|----------:|-----------------:|------:|
| Flat hive (1 crore) | seq | 6 | 12 | 12/12 | 22064.3 | 10972.9 | 2210000 |
| Flat hive (1 crore) | par | 6 | 12 | 12/12 | 10787.4 | 5372.8 | 2210000 |
| Base + 45 deltas | seq | 6 | 12 | 12/12 | 55994.1 | 27867.0 | 2113919 |
| Base + 45 deltas | par | 6 | 12 | 12/12 | 27412.9 | 13401.9 | 2113919 |
| Compacted 10 deltas | seq | 6 | 12 | 12/12 | 35109.2 | 17540.4 | 2113919 |
| Compacted 10 deltas | par | 6 | 12 | 12/12 | 16803.2 | 8327.3 | 2113919 |

## Timed scenarios (HTTP concurrency = 1)

| Source | Scenario | Final target | HTTP conc. | HTTP reqs | Repeats | Seq p50 (ms) | Par p50 (ms) | Speedup | Correct |
|--------|----------|-------------:|-----------:|----------:|--------:|-------------:|-------------:|--------:|---------|
| Flat hive (1 crore) | `active_only` | 9,010,000 | 1 | 1 | 2 | 1975.4 | 982.1 | 2.01× | yes |
| Flat hive (1 crore) | `block_include_56` | 20,000 | 1 | 1 | 2 | 2188.7 | 987.8 | 2.22× | yes |
| Flat hive (1 crore) | `demo_532_488` | 30,000 | 1 | 1 | 2 | 2955.5 | 1534.6 | 1.93× | yes |
| Flat hive (1 crore) | `demo_532_full` | 30,000 | 1 | 1 | 2 | 2803.6 | 1620.0 | 1.73× | yes |
| Flat hive (1 crore) | `heavy_catalog` | 2,210,000 | 1 | 1 | 2 | 1827.8 | 907.7 | 2.01× | yes |
| Flat hive (1 crore) | `seg_9001` | 2,210,000 | 1 | 1 | 2 | 1801.3 | 835.3 | 2.16× | yes |
| Flat hive (1 crore) | `seg_include_exclude` | 1,349,129 | 1 | 1 | 2 | 1891.7 | 845.3 | 2.24× | yes |
| Flat hive (1 crore) | `seg_plus_blocks` | 30,000 | 1 | 1 | 2 | 2800.1 | 1380.9 | 2.03× | yes |
| Flat hive (1 crore) | `seg_union_9001_9002` | 4,476,666 | 1 | 1 | 2 | 2062.8 | 915.2 | 2.25× | yes |
| Base + 45 deltas | `active_only` | 8,526,244 | 1 | 1 | 2 | 4768.6 | 2330.5 | 2.05× | yes |
| Base + 45 deltas | `block_include_56` | 18,058 | 1 | 1 | 2 | 5057.5 | 2470.2 | 2.05× | yes |
| Base + 45 deltas | `demo_532_488` | 25,769 | 1 | 1 | 2 | 6544.7 | 3373.7 | 1.94× | yes |
| Base + 45 deltas | `demo_532_full` | 25,769 | 1 | 1 | 2 | 6325.0 | 3476.5 | 1.82× | yes |
| Base + 45 deltas | `heavy_catalog` | 2,113,919 | 1 | 1 | 2 | 4651.3 | 2282.1 | 2.04× | yes |
| Base + 45 deltas | `seg_9001` | 2,113,919 | 1 | 1 | 2 | 4670.4 | 2739.3 | 1.70× | yes |
| Base + 45 deltas | `seg_include_exclude` | 1,272,710 | 1 | 1 | 2 | 4902.4 | 2456.5 | 2.00× | yes |
| Base + 45 deltas | `seg_plus_blocks` | 27,086 | 1 | 1 | 2 | 6445.8 | 3257.1 | 1.98× | yes |
| Base + 45 deltas | `seg_union_9001_9002` | 4,251,361 | 1 | 1 | 2 | 5033.5 | 5858.1 | 0.86× | yes |
| Compacted 10 deltas | `active_only` | 8,526,244 | 1 | 1 | 2 | 3105.6 | 1471.2 | 2.11× | yes |
| Compacted 10 deltas | `block_include_56` | 18,058 | 1 | 1 | 2 | 3377.0 | 1569.5 | 2.15× | yes |
| Compacted 10 deltas | `demo_532_488` | 25,769 | 1 | 1 | 2 | 4455.6 | 2245.9 | 1.98× | yes |
| Compacted 10 deltas | `demo_532_full` | 25,769 | 1 | 1 | 2 | 4253.0 | 2278.1 | 1.87× | yes |
| Compacted 10 deltas | `heavy_catalog` | 2,113,919 | 1 | 1 | 2 | 2939.0 | 1383.5 | 2.12× | yes |
| Compacted 10 deltas | `seg_9001` | 2,113,919 | 1 | 1 | 2 | 2936.8 | 1364.7 | 2.15× | yes |
| Compacted 10 deltas | `seg_include_exclude` | 1,272,710 | 1 | 1 | 2 | 3064.3 | 1410.5 | 2.17× | yes |
| Compacted 10 deltas | `seg_plus_blocks` | 27,086 | 1 | 1 | 2 | 4374.2 | 2179.0 | 2.01× | yes |
| Compacted 10 deltas | `seg_union_9001_9002` | 4,251,361 | 1 | 1 | 2 | 3245.8 | 1488.4 | 2.18× | yes |

## How to re-run

```bash
python3 tests/load_campaign_preview.py --repeat 2 --concurrency 6 --burst 12
```

Raw JSON: [`load_campaign_preview.json`](./load_campaign_preview.json)
