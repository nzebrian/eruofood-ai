# EruoFood AI — Redis Resilience

Milestone 21. Investigates the observed Redis daemon cycling, documents the
application's behaviour during a Redis outage per subsystem, records the
fail-safe guarantees, and specifies production Redis HA.

## 1. Why Redis cycled (root cause)

The Redis outage observed during Milestones 20–21 was **environmental, not an
application fault**. The in-repo validation sandbox is an ephemeral container
whose background `redis-server` process does not persist across tool
invocations/session boundaries; when the container is reclaimed or a new shell is
spawned, the daemon is gone until restarted (`redis-server --daemonize yes`).
Evidence: the application code never starts, stops, or signals Redis; when Redis
was restarted, the full suite returned to **337/337** immediately with no code
change. This is a **resilience observation** about the test environment, not a
regression — but it is a useful forcing function to verify the app degrades
safely, which is what this document certifies.

Production does not share this failure mode: Redis is a managed, replicated
service (see §5), not a co-located best-effort daemon.

## 2. Behaviour during a Redis outage, per subsystem

| Subsystem | Backend | Behaviour when Redis is down | Safety posture |
|---|---|---|---|
| **Rate limiting** (`CacheRateLimiter`) | Redis counter | **Fails CLOSED** — denies the request with a deterministic reset window; the exception is caught and logged, never surfaced as a 500. Regression-tested (`RateLimiterResilienceTest`). | **Secure** — no bypass. Availability is protected by readiness gating + HA, not by weakening the limit. |
| **Quotas** (`QuotaService`) | Redis counters | Same fail-closed posture — a quota check that cannot read the counter denies rather than grants unmetered access. | Secure — no unmetered bypass. |
| **Idempotency keys** | Redis | On backend failure the operation is not marked processed; retried jobs are safe because side-effects (payments/orders) carry idempotency keys and are re-checked at the domain layer. | Safe — at-least-once, no double effect. |
| **Cache** (read-through, search results, config-derived reads) | Redis | Cache `get`/`put` depend on Redis; a hard outage surfaces as an error on cached paths. The `SearchCache` port supports a **null adapter** (caching disabled) as a degradation option. **Recommended follow-up:** wrap non-critical cache reads to fall through to the source of truth on backend error (a cache miss must never be a request failure). | Cache is a performance optimisation, never a correctness dependency. |
| **Queues** (`QUEUE_CONNECTION=redis`) | Redis lists | In-flight jobs survive a restart when **AOF** is enabled (`appendonly yes`, `everysec`); managed Redis replication + snapshot covers this. Jobs are idempotent, so at-least-once redelivery after recovery is safe. | Durable + safe. |
| **Sessions** (`SESSION_DRIVER=redis`) | Redis | Web sessions unavailable during an outage; API auth (JWT / API keys / OAuth tokens) is **stateless / DB-backed**, so the API surface is unaffected by session-store loss. | API unaffected; web sessions degrade. |

## 3. Connection, timeout and retry behaviour

- The Redis client (phpredis) uses a bounded connect timeout; a dead endpoint
  raises a connection exception rather than hanging the worker indefinitely.
- **Rate limiter / quotas:** the exception is caught and converted to a
  fail-closed decision (no retry storm inside the request path).
- **Queues / workers:** the queue driver's own reconnection handles transient
  blips; a worker that loses Redis exits non-zero and is restarted by the
  orchestrator (supervised process), reconnecting to the recovered/failed-over
  endpoint.
- **No silent fail-open** anywhere a security decision depends on Redis.

## 4. Health / readiness integration

- `GET /api/v1/ready` (added M20) probes Redis (and PostgreSQL). When Redis is
  unreachable the probe returns **503**, so Kubernetes/the load balancer pulls
  the pod out of rotation — traffic routes to pods with a healthy Redis path,
  rather than every request failing closed.
- `GET /api/v1/health` remains a pure liveness check (process up) so a Redis blip
  does not trigger a pod **restart** loop (only removal from the ready set).
- This is the primary availability mechanism: fail-closed at the limiter is the
  last-resort backstop; readiness gating keeps healthy capacity serving.

## 5. Production Redis HA (required)

| Control | Requirement |
|---|---|
| Topology | Managed Redis with a **primary + at least one replica** across AZs (e.g. ElastiCache Multi-AZ / Memorystore HA / Azure Cache Standard+); or self-managed **Redis Sentinel** (3 sentinels) / **Redis Cluster**. |
| Failover | Automatic primary failover; app connects via the managed endpoint (no client-side address change). |
| Persistence | **AOF** `everysec` + periodic RDB snapshot so queued jobs survive a restart. |
| Security | TLS in transit (`REDIS_SCHEME=tls`) + AUTH password (`REDIS_PASSWORD`) — see `infra/env/production.env.example`. |
| Isolation | Redis reachable only from the app/worker security group; not publicly exposed. |
| Capacity | `maxmemory` with an eviction policy (`allkeys-lru` for the cache DB; a **separate** logical DB or instance for rate-limit/quota counters with `noeviction` so security counters are never evicted). |
| Monitoring | Alert on Redis unreachable, high memory, high latency, replica lag, and evictions (see `docs/OBSERVABILITY.md`). |

## 6. Recovery

- On Redis recovery the rate limiter/quota counters resume from zero for the
  current window (fail-closed during the gap means no over-admission occurred).
- Workers reconnect automatically (supervised restart).
- Caches repopulate on demand.
- No manual data reconciliation is required (Redis holds only derived/ephemeral
  state — see `docs/BACKUP_RESTORE.md` §2).

## 7. Validation status

| Item | Verdict | Evidence |
|---|---|---|
| Rate limiter fails closed (no bypass) on backend outage | **EXECUTED — PASSED** | `RateLimiterResilienceTest` (1/1); full suite 338/338 |
| Redis primitives (rate limit, quota, idempotency, counters, recovery) | **EXECUTED — PASSED** | `scripts/redis_validation.php` 9/9 (incl. 2000/2000 concurrent atomic increments) |
| Readiness probe pulls a Redis-down pod from rotation | **EXECUTED — PASSED** | `/api/v1/ready` returns 503 when Redis unreachable (feature-tested) |
| Production Redis HA (Multi-AZ failover) | **NOT VALIDATED** | Requires managed Redis; spec above is deployment-ready |
