# HTTP Architecture Decision Record

## Date: 2026-03-07

## Decision

Evolver uses a hybrid architecture:
- **Amp HTTP server + Twig + SSE** for the local web UI
- **blocking Symfony console workers** for queued Git and scan jobs
- **existing `pcntl_fork` scanner/indexer paths** for CPU-heavy analysis

Amp is the HTTP control plane. It is not the runtime for indexing or scanning work.

## Why This Was Chosen

The product needs:
- a simple local dashboard
- normal HTML forms
- live progress updates
- no Node/Vite frontend
- no Docker privilege requirements
- no rewrite of the current high-performance scanner/indexer pipeline

Amp fit that shape because it gives:
- a native PHP HTTP server
- straightforward routing
- SSE support over normal HTTP
- no extra container privileges

At the same time, the analysis engine already performs best as blocking process-based work, so that part stays on the current CLI path.

## Rejected Alternatives

### Swoole

Rejected.

Reasons:
- unnecessary complexity for the current UI
- extra operational constraints in containers
- no advantage for a mostly HTML + SSE local app

### RoadRunner

Rejected for v1.

Reasons:
- more infrastructure than needed for a local/internal tool
- would still require separate job orchestration
- the app does not need PSR-7 worker management badly enough to justify the extra moving parts yet

### Full async Amp workers

Rejected.

Reasons:
- the real indexing/scanning workload is already tuned around `pcntl_fork`
- moving heavy analysis into async tasks would add complexity without improving throughput

## Resulting Architecture

```
Browser
  │
  ▼
Amp HTTP server
  │
  ├── Twig pages
  ├── form handlers
  └── SSE job/run updates
  │
  ▼
SQLite (projects, branches, scan_runs, jobs, job_logs, matches)
  ▲
  │
queue:work command
  │
  ├── Git materialization
  ├── source version detection
  └── ProjectScanner / TemplateApplier / existing services
```

## Operational Model

- `make web` starts the HTTP server on `http://localhost:8080`
- `make worker` processes queued jobs
- `make evr -- ... EXTRA_HOST_PATH=...` remains the path for one-off external CLI analysis
- scans are persisted as `scan_runs`; history is preserved and comparable

## Consequences

Benefits:
- simple local deployment
- minimal frontend stack
- live progress with SSE
- no rewrite of the analysis engine
- no special Docker seccomp/capability requirements

Tradeoffs:
- web and worker run as separate processes
- no bidirectional realtime protocol in v1
- no multi-node or multi-tenant story yet

## Next Follow-Up

- expand web coverage with handler tests
- add project-type aware UI flows for Drupal custom module analysis
- keep core indexing/diffing CLI-first until the project scan UX settles
