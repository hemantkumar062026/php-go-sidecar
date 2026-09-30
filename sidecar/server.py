#!/usr/bin/env python3
"""Official DuckDB Python sidecar — connection pool for parallel Parquet reads.

Two kinds of parallelism:
  1) Intra-query: DUCKDB_THREADS (cores used inside one SELECT)
  2) Inter-query: DUCKDB_POOL_SIZE (concurrent user queries)

Endpoints:
  GET  /health
  POST /query   {"sql": "SELECT ..."}            -> {"rows":[...], "ms":...}
  POST /exec    {"sql": "COPY ... / CREATE ..."}  -> {"ok": true, "ms":...}
"""

from __future__ import annotations

import json
import os
import queue
import threading
import time
import traceback
from contextlib import contextmanager
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any, Iterator
from urllib.parse import urlparse

import duckdb

HOST = os.environ.get("SIDECAR_HOST", "127.0.0.1")
PORT = int(os.environ.get("SIDECAR_PORT", "8090"))
THREADS = int(os.environ.get("DUCKDB_THREADS", "4"))
MEMORY = os.environ.get("DUCKDB_MEMORY_LIMIT", "4GB")
POOL_SIZE = max(1, int(os.environ.get("DUCKDB_POOL_SIZE", "4")))
# How long a request waits for a free connection (seconds)
POOL_WAIT = float(os.environ.get("DUCKDB_POOL_WAIT", "60"))


class DuckPool:
    """Pool of independent DuckDB connections (safe concurrent reads on Parquet)."""

    def __init__(self, size: int) -> None:
        self.size = size
        self._q: queue.Queue[duckdb.DuckDBPyConnection] = queue.Queue(maxsize=size)
        self._write_lock = threading.Lock()  # serialize COPY / mutating exec
        for _ in range(size):
            self._q.put(self._new_con())

    def _new_con(self) -> duckdb.DuckDBPyConnection:
        # Separate in-memory DB per conn — fine for read_parquet / one-shot SQL.
        con = duckdb.connect(config={"threads": THREADS, "memory_limit": MEMORY})
        con.execute("SET TimeZone='UTC'")
        return con

    @contextmanager
    def acquire(self) -> Iterator[duckdb.DuckDBPyConnection]:
        try:
            con = self._q.get(timeout=POOL_WAIT)
        except queue.Empty as exc:
            raise TimeoutError(
                f"No free DuckDB connection in {POOL_WAIT}s (pool_size={self.size})"
            ) from exc
        try:
            yield con
        finally:
            self._q.put(con)

    @property
    def available(self) -> int:
        return self._q.qsize()


_pool: DuckPool | None = None


def pool() -> DuckPool:
    global _pool
    if _pool is None:
        _pool = DuckPool(POOL_SIZE)
    return _pool


def rows_from_relation(rel: Any) -> list[dict[str, Any]]:
    cols = [c[0] for c in rel.description] if rel.description else []
    out: list[dict[str, Any]] = []
    for tup in rel.fetchall():
        row: dict[str, Any] = {}
        for i, col in enumerate(cols):
            val = tup[i]
            if hasattr(val, "isoformat"):
                val = val.isoformat()
            row[col] = val
        out.append(row)
    return out


def run_query(sql: str) -> list[dict[str, Any]]:
    sql = sql.strip().rstrip(";")
    with pool().acquire() as con:
        res = con.execute(sql)
        if res is None:
            return []
        return rows_from_relation(res)


def run_exec(sql: str) -> None:
    """Run mutating SQL (e.g. COPY). Parallel OK when each request uses a distinct output path."""
    sql = sql.strip()
    if not sql.endswith(";"):
        sql += ";"
    # Optional: DUCKDB_SERIALIZE_EXEC=1 to force single-writer (safer if paths may collide).
    if os.environ.get("DUCKDB_SERIALIZE_EXEC", "0") in ("1", "true", "yes"):
        with pool()._write_lock:
            with pool().acquire() as con:
                con.execute(sql)
        return
    with pool().acquire() as con:
        con.execute(sql)


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, fmt: str, *args: Any) -> None:
        print(f"[sidecar] {self.address_string()} {fmt % args}")

    def _json(self, status: int, payload: dict[str, Any]) -> None:
        body = json.dumps(payload, default=str).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Connection", "close")
        self.end_headers()
        self.wfile.write(body)

    def _read_json(self) -> dict[str, Any]:
        length = int(self.headers.get("Content-Length") or 0)
        raw = self.rfile.read(length) if length else b"{}"
        data = json.loads(raw.decode("utf-8") or "{}")
        if not isinstance(data, dict):
            raise ValueError("JSON object required")
        return data

    def do_GET(self) -> None:  # noqa: N802
        path = urlparse(self.path).path
        if path == "/health":
            p = pool()
            self._json(
                200,
                {
                    "ok": True,
                    "engine": "duckdb",
                    "client": "python",
                    "version": duckdb.__version__,
                    "threads": THREADS,
                    "memory_limit": MEMORY,
                    "pool_size": p.size,
                    "pool_available": p.available,
                    "parallelism": {
                        "intra_query_threads": THREADS,
                        "inter_query_connections": p.size,
                    },
                },
            )
            return
        self._json(404, {"error": "not found"})

    def do_POST(self) -> None:  # noqa: N802
        path = urlparse(self.path).path
        try:
            body = self._read_json()
            sql = (body.get("sql") or "").strip()
            if not sql:
                self._json(400, {"error": "sql required"})
                return
            t0 = time.perf_counter()
            if path == "/query":
                rows = run_query(sql)
                ms = (time.perf_counter() - t0) * 1000
                self._json(200, {"rows": rows, "ms": round(ms, 2)})
                return
            if path == "/exec":
                run_exec(sql)
                ms = (time.perf_counter() - t0) * 1000
                self._json(200, {"ok": True, "ms": round(ms, 2)})
                return
            self._json(404, {"error": "not found"})
        except TimeoutError as exc:
            self._json(503, {"error": str(exc)})
        except Exception as exc:  # noqa: BLE001
            traceback.print_exc()
            self._json(500, {"error": str(exc)})


def main() -> None:
    p = pool()  # warm all connections
    httpd = ThreadingHTTPServer((HOST, PORT), Handler)
    print(
        f"DuckDB Python sidecar on http://{HOST}:{PORT} "
        f"(duckdb={duckdb.__version__}, pool={p.size}, "
        f"threads/conn={THREADS}, memory={MEMORY})",
        flush=True,
    )
    httpd.serve_forever()


if __name__ == "__main__":
    main()
