package main

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"os"
	"strconv"
	"strings"
	"sync/atomic"
	"time"

	_ "github.com/duckdb/duckdb-go/v2"
)

type pool struct {
	ch             chan *sql.DB
	defaultThreads int
	defaultMem     string
	waitNs         atomic.Int64
	queries        atomic.Int64
}

func newPool(size, threads int, mem string) (*pool, error) {
	p := &pool{
		ch:             make(chan *sql.DB, size),
		defaultThreads: threads,
		defaultMem:     mem,
	}
	for i := 0; i < size; i++ {
		db, err := sql.Open("duckdb", "")
		if err != nil {
			return nil, err
		}
		// One logical DuckDB connection per pool slot — no internal multiplexing.
		db.SetMaxOpenConns(1)
		db.SetMaxIdleConns(1)

		stmts := []string{
			fmt.Sprintf("SET threads=%d", threads),
			fmt.Sprintf("SET memory_limit='%s'", mem),
			"SET TimeZone='UTC'",
			// Aggregation/scan friendly: skip preserving insert order.
			"SET preserve_insertion_order=false",
		}
		for _, s := range stmts {
			if _, err := db.Exec(s); err != nil {
				// Older DuckDB builds may lack some settings — ignore unknown.
				if !strings.Contains(strings.ToLower(err.Error()), "unrecognized") &&
					!strings.Contains(strings.ToLower(err.Error()), "not found") {
					return nil, fmt.Errorf("%s: %w", s, err)
				}
			}
		}
		p.ch <- db
	}
	return p, nil
}

func (p *pool) withDB(fn func(*sql.DB) error) error {
	t0 := time.Now()
	db := <-p.ch
	p.waitNs.Add(time.Since(t0).Nanoseconds())
	p.queries.Add(1)
	defer func() { p.ch <- db }()
	return fn(db)
}

func (p *pool) available() int { return len(p.ch) }
func (p *pool) size() int      { return cap(p.ch) }

type reqBody struct {
	SQL         string `json:"sql"`
	Threads     *int   `json:"threads"`
	MemoryLimit string `json:"memory_limit"`
}

func (p *pool) applySessionSettings(db *sql.DB, body reqBody) error {
	// Skip no-op SETs — every campaign-preview fans out many /query calls with the same opts.
	if body.Threads != nil && *body.Threads != p.defaultThreads {
		if _, err := db.Exec(fmt.Sprintf("SET threads=%d", *body.Threads)); err != nil {
			return err
		}
	}
	if body.MemoryLimit != "" && !strings.EqualFold(body.MemoryLimit, p.defaultMem) {
		mem := strings.ReplaceAll(body.MemoryLimit, "'", "''")
		if _, err := db.Exec(fmt.Sprintf("SET memory_limit='%s'", mem)); err != nil {
			return err
		}
	}
	return nil
}

func main() {
	host := env("SIDECAR_HOST", "127.0.0.1")
	port := env("SIDECAR_PORT", "8091")
	threads, _ := strconv.Atoi(env("DUCKDB_THREADS", "4"))
	// Sized for parallel campaign-preview fan-out on ~12-core hosts without OOM.
	poolSize, _ := strconv.Atoi(env("DUCKDB_POOL_SIZE", "8"))
	mem := env("DUCKDB_MEMORY_LIMIT", "2GB")

	p, err := newPool(poolSize, threads, mem)
	if err != nil {
		log.Fatal(err)
	}

	mux := http.NewServeMux()
	mux.HandleFunc("/health", func(w http.ResponseWriter, r *http.Request) {
		q := p.queries.Load()
		var avgWaitMs float64
		if q > 0 {
			avgWaitMs = float64(p.waitNs.Load()) / float64(q) / 1e6
		}
		writeJSON(w, 200, map[string]any{
			"ok":             true,
			"engine":         "duckdb",
			"client":         "go",
			"threads":        threads,
			"memory_limit":   mem,
			"pool_size":      p.size(),
			"pool_available": p.available(),
			"queries_total":  q,
			"avg_pool_wait_ms": avgWaitMs,
			"parallelism": map[string]any{
				"intra_query_threads":     threads,
				"inter_query_connections": p.size(),
			},
		})
	})

	mux.HandleFunc("/query", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			http.Error(w, "POST only", 405)
			return
		}
		r.Body = http.MaxBytesReader(w, r.Body, 16<<20)
		var body reqBody
		if err := json.NewDecoder(r.Body).Decode(&body); err != nil || body.SQL == "" {
			writeJSON(w, 400, map[string]any{"error": "sql required"})
			return
		}
		t0 := time.Now()
		var rows []map[string]any
		err := p.withDB(func(db *sql.DB) error {
			if err := p.applySessionSettings(db, body); err != nil {
				return err
			}
			rs, err := db.Query(body.SQL)
			if err != nil {
				return err
			}
			defer rs.Close()
			cols, err := rs.Columns()
			if err != nil {
				return err
			}
			for rs.Next() {
				raw := make([]any, len(cols))
				ptrs := make([]any, len(cols))
				for i := range raw {
					ptrs[i] = &raw[i]
				}
				if err := rs.Scan(ptrs...); err != nil {
					return err
				}
				m := make(map[string]any, len(cols))
				for i, c := range cols {
					m[c] = normalize(raw[i])
				}
				rows = append(rows, m)
			}
			return rs.Err()
		})
		if err != nil {
			writeJSON(w, 500, map[string]any{"error": err.Error()})
			return
		}
		if rows == nil {
			rows = []map[string]any{}
		}
		writeJSON(w, 200, map[string]any{
			"rows": rows,
			"ms":   float64(time.Since(t0).Microseconds()) / 1000.0,
		})
	})

	mux.HandleFunc("/exec", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			http.Error(w, "POST only", 405)
			return
		}
		r.Body = http.MaxBytesReader(w, r.Body, 16<<20)
		var body reqBody
		if err := json.NewDecoder(r.Body).Decode(&body); err != nil || body.SQL == "" {
			writeJSON(w, 400, map[string]any{"error": "sql required"})
			return
		}
		t0 := time.Now()
		err := p.withDB(func(db *sql.DB) error {
			if err := p.applySessionSettings(db, body); err != nil {
				return err
			}
			_, err := db.Exec(body.SQL)
			return err
		})
		if err != nil {
			writeJSON(w, 500, map[string]any{"error": err.Error()})
			return
		}
		writeJSON(w, 200, map[string]any{
			"ok": true,
			"ms": float64(time.Since(t0).Microseconds()) / 1000.0,
		})
	})

	addr := host + ":" + port
	srv := &http.Server{
		Addr:              addr,
		Handler:           mux,
		ReadHeaderTimeout: 10 * time.Second,
		// Disable write deadline: DuckDB queries can run longer than a fixed write timeout.
		// IdleTimeout still reaps unused keep-alives.
		IdleTimeout:    120 * time.Second,
		MaxHeaderBytes: 1 << 20,
	}
	log.Printf("DuckDB Go sidecar on http://%s (pool=%d, threads/conn=%d, memory=%s)", addr, poolSize, threads, mem)
	log.Fatal(srv.ListenAndServe())
}

func normalize(v any) any {
	switch t := v.(type) {
	case nil:
		return nil
	case []byte:
		return string(t)
	case time.Time:
		return t.UTC().Format(time.RFC3339Nano)
	default:
		return t
	}
}

func writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	enc := json.NewEncoder(w)
	enc.SetEscapeHTML(false)
	_ = enc.Encode(v)
}

func env(k, def string) string {
	if v := os.Getenv(k); v != "" {
		return v
	}
	return def
}
