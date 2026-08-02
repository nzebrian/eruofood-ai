# ADR-0016: Public API, SDK & Developer Platform — a controlled façade, not exposed internals

- **Status:** Accepted
- **Date:** 2026-08-02
- **Deciders:** Engineering, Product, Platform, Security

## Context

Milestone 16 opens EruoFood to third-party developers: a public API, developer
accounts and applications, API keys, scopes, rate limits and quotas, webhooks, a
developer portal, SDK foundations, and documentation. The hard constraints:
**do not expose internal application APIs directly**; the public surface must be
a controlled external layer with strict authentication, authorization,
versioning, quotas, monitoring and docs; and **existing internal APIs and
bounded contexts must not break**.

## Decision

- **A dedicated `EruoFood\PublicApi` bounded context** owns the entire external
  surface. It is a **façade**, not a re-export: public controllers return their
  own transformer DTOs through a standard envelope, so the external contract is
  decoupled from internal representations and can evolve independently.
- **A separate route surface and auth model.** Public data lives under
  `/api/public/v1` and is authenticated by **API keys only** (never JWT), through
  a middleware stack: request-context → key auth → rate limit → quota → per-route
  scope. Developer management lives under `/api/v1/developer` and is **JWT only**.
  The two never mix, satisfying "internal APIs are not exposed."
- **Versioning that makes v2 additive.** The version is a path segment and a
  route group; v2 is a sibling group, so v1 is never mutated. Deprecated versions
  still serve but emit `Deprecation`/`Sunset` headers.
- **Credentials that never store plaintext.** An API key is `prefix.secret`; only
  the prefix (public lookup id) and a SHA-256 hash of the secret are stored. The
  secret is CSPRNG-generated and shown once. Keys carry scopes that are the
  intersection of the request and the application's grant (never widened), may
  expire, and support rotation and revocation.
- **Scopes as the authorization boundary.** A key receives only explicitly
  granted scopes; `EnforceScope` gates each route. The scope catalogue is config.
- **Redis-backed rate limits and quotas** with burst protection and standard
  `X-RateLimit-*` / `X-Quota-*` headers, degrading to the array store in tests.
- **An enterprise webhook system**: signed (HMAC-SHA256 over `ts.body`), replay-
  protected (timestamp tolerance), idempotent (unique per `(webhook, event)`),
  retried with exponential backoff to an attempt ceiling, with a delivery log and
  secret rotation. Internal domain events fan out to subscribers by name via a
  config map — no business module knows webhooks exist.
- **Data via a read-port façade.** The public data endpoints read through
  `CatalogReadPort`, implemented by an adapter over the Catalog context's
  published read repositories. This is the **one sanctioned cross-context seam**
  for the façade: application-layer, read-only, mapping to the Public API's own
  DTOs — it never touches another context's write model or tables.
- **Analytics via events.** Every request emits `publicapi.request_served`
  (route, status, latency, version); rate/quota breaches and webhook outcomes emit
  their own events. Analytics consumes them through the existing event bus — no
  direct coupling.
- **SDK foundations** (TypeScript, PHP, Dart): client + auth + config + typed
  errors + pagination helper. Deliberately minimal, not over-engineered.

## Consequences

- Third parties integrate against a stable, documented, versioned contract that
  can change independently of internal refactors.
- The blast radius of a leaked key is bounded by its scopes, expiry and the
  revoke/rotate controls; secrets are never recoverable from storage.
- Abuse is contained by per-client rate limits and quotas, and observable via
  events feeding Analytics.
- The modular monolith is preserved: one new bounded context, one read-port seam
  to Catalog, event-driven everywhere else. No microservice was introduced.

## Alternatives considered

- **Expose internal controllers behind an API-key guard.** Rejected — it couples
  the external contract to internal shapes and leaks internal endpoints, violating
  the core constraint.
- **Per-resource event-fed read models inside PublicApi** (like Search).
  Rejected for this milestone as over-engineering; the read-port façade over
  Catalog delivers working, decoupled endpoints now and the projection approach
  remains open later.
- **OAuth2 authorization-code flow for third parties.** Deferred — API keys with
  scopes meet the milestone; OAuth can layer on via the same scope model.
- **A separate API-gateway microservice.** Rejected — the milestone explicitly
  forbids unnecessary microservices; a bounded context + middleware stack gives
  the same controls in-process.
