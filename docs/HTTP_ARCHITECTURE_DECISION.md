# HTTP Architecture Decision Record

## Date: 2026-03-07

## Context

DrupalEvolver needs an HTTP backend for:
- Project management (clone, index, scan)
- Search functionality
- Real-time progress tracking during indexing
- Git branch management

## Options Considered

### 1. Swoole (❌ Not Viable)

**Status:** Rejected due to Docker io_uring restrictions

**Findings:**
- Swoole 6.x requires io_uring syscalls
- Docker containers without privileged mode cannot access io_uring
- Error: `Iouring::Iouring(): Failed to initialize io_uring instance, Error: Operation not permitted`
- Would require `--cap-add SYS_ADMIN` or custom seccomp profile
- Even with privileges, FFI coroutine-safety is questionable

**Benchmark:** Not runnable in current Docker setup

---

### 2. AmpHP (⚠️ Working but Slow)

**Status:** Working but 2.6x slower than baseline

**Findings:**
- Pure PHP, no extensions required (only ext-posix for process management)
- Fiber-based async (Revolt Event Loop)
- Worker isolation via separate processes
- Good FFI compatibility (each worker has isolated memory space)

**Benchmark Results (4 workers):**
| Metric | Value |
|--------|-------|
| Avg Time | 0.279s |
| Throughput | 172.2 files/sec |
| Peak Memory | 6 MB |
| Speedup vs baseline | 0.38x (slower) |

**Pros:**
- No special Docker privileges required
- Clean async API with fibers
- Good for HTTP + WebSocket + SSE

**Cons:**
- **2.6x slower** than pcntl_fork baseline
- Higher memory overhead (+2 MB peak)
- More complex API (Tasks must be autoloadable)
- Requires ext-posix (added to Dockerfile)

---

### 3. pcntl_fork Baseline (✅ Fastest)

**Status:** Current implementation, best performance

**Benchmark Results (4 workers):**
| Metric | Value |
|--------|-------|
| Avg Time | 0.107s |
| Throughput | 449.4 files/sec |
| Peak Memory | 4 MB |
| Speedup vs baseline | 1.00x (reference) |

**Pros:**
- **Fastest** indexing performance
- Lowest memory footprint
- Simple, proven architecture
- Works perfectly with FFI + SQLite WAL

**Cons:**
- Blocking (no built-in HTTP server)
- Need separate HTTP frontend for UI

---

## Recommended Architecture

### Hybrid: RoadRunner + pcntl_fork Workers

```
┌─────────────────────────────────────────────────────────┐
│                    RoadRunner Server                      │
│  (Go binary, manages PHP workers)                         │
└────┬──────────────────────┬─────────────────────────────┘
     │                      │
┌────▼──────────────┐  ┌────▼────────────────────────────┐
│   HTTP Workers    │  │   Process Pool (background)     │
│   (PSR-7/PSR-15)  │  │                                 │
│                   │  │  ┌─────────────────────────────┐│
│  - Controllers    │  │  │  Symfony Console Commands   ││
│  - Twig templates │  │  │                             ││
│  - JSON API       │  │  │  - evolver index            ││
│  - SSE endpoint   │  │  │  - evolver diff             ││
│                   │  │  │  - git clone                ││
│                   │  │  └─────────────────────────────┘│
└────┬──────────────┘  └─────────────────────────────────┘
     │
┌────▼─────────────────────────────────────────────────────┐
│              DatabaseApi → SQLite (WAL)                  │
│              FFIBinding → tree-sitter                    │
│              CoreIndexer (with pcntl_fork)               │
└──────────────────────────────────────────────────────────┘
```

### Why This Architecture?

1. **Best Performance:** Uses pcntl_fork for indexing (449 files/sec)
2. **HTTP Frontend:** RoadRunner provides persistent HTTP server
3. **No io_uring Issues:** RoadRunner doesn't require special privileges
4. **FFI Safe:** Each worker/process has isolated memory
5. **SQLite WAL:** Works perfectly with multiple readers
6. **Background Jobs:** RoadRunner jobs queue for long-running tasks
7. **SSE Support:** Can stream indexing progress via Server-Sent Events

### Alternatives Considered

| Option | Performance | Complexity | Docker-Friendly | Verdict |
|--------|-------------|------------|-----------------|---------|
| Swoole | Unknown | Medium | ❌ (io_uring) | Rejected |
| AmpHP | 172 files/sec | High | ✅ | Too slow |
| Symfony Full-Stack | ~100 files/sec | High | ✅ | Overkill |
| ReactPHP | Unknown | Medium | ✅ | Complex IPC |
| **RoadRunner + pcntl_fork** | **449 files/sec** | **Low** | ✅ | **Recommended** |

---

## Implementation Plan

### Phase 1: RoadRunner Setup
1. Add `spiral/roadrunner-http` to composer.json
2. Create `.rr.yaml` configuration
3. Add HTTP worker bootstrap
4. Create basic controllers (Project, Search, Index)

### Phase 2: Background Jobs
1. Add `spiral/roadrunner-jobs` to composer.json
2. Create job wrappers for Symfony Console commands
3. Implement SSE endpoint for progress streaming
4. Add Redis/SQLite job queue

### Phase 3: Git Integration
1. Create Git wrapper for clone/checkout/fetch
2. Add project management UI
3. Implement branch selection
4. Add real-time progress tracking

### Phase 4: Search & UI
1. Implement full-text search (SQLite FTS5)
2. Create Vanilla JS frontend
3. Add Twig templates
4. Implement responsive design

---

## Performance Expectations

Based on benchmarks:

| Operation | Expected Performance |
|-----------|---------------------|
| HTTP Response Time | < 50ms (p99) |
| Indexing (src/ 48 files) | ~0.1s with 4 workers |
| Indexing (Drupal Core ~6000 files) | ~8-10s with 4 workers |
| Search Query | < 100ms |
| Git Clone | Network dependent |
| SSE Progress Updates | Real-time (100ms interval) |

---

## Risk Mitigation

| Risk | Mitigation |
|------|------------|
| RoadRunner worker crash | Auto-restart with `--retry` |
| SQLite WAL contention | Use WAL mode + busy_timeout |
| FFI memory leaks | Worker recycling + gc_collect_cycles() |
| Long-running indexing | Background jobs + progress SSE |
| Git SSH keys | Volume mount ~/.ssh |

---

## Conclusion

**Recommended:** RoadRunner HTTP frontend + pcntl_fork worker processes

This architecture provides:
- ✅ Best indexing performance (449 files/sec)
- ✅ Clean HTTP API with PSR-7/PSR-15
- ✅ Background job processing
- ✅ Real-time progress streaming
- ✅ No Docker privilege requirements
- ✅ FFI + SQLite compatibility

**Next Steps:**
1. Review and approve this architecture decision
2. Create implementation roadmap
3. Set up RoadRunner configuration
4. Implement MVP HTTP endpoints
