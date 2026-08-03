# Technical Debt Register

Date opened: 2026-08-02 (Technical Debt & Validation Cleanup)

This register records issues discovered during the repository-wide audit, what
was fixed in that cleanup, and what remains as accepted/known debt. Fixes were
deliberately the **smallest safe changes**; no working architecture was rewritten.

Validation evidence for every claim here lives in
[`VALIDATION_STATUS.md`](VALIDATION_STATUS.md).

---

## Fixed in this cleanup

### 1. Duplicate OpenAPI schemas `Conversation` and `ChatMessage`
- **Problem:** The AI module (food-assistant chat) and the Notifications module
  (messaging) both defined `Conversation` and `ChatMessage` component schemas.
  Because YAML uses last-definition-wins, the AI schemas were silently overridden
  by the Notifications shapes, and `@redocly/cli` could not even parse the file
  (`duplicated mapping key`), so the whole spec was effectively unvalidated.
- **Fix:** Renamed the **Notifications** pair to `MessagingConversation` and
  `MessagingMessage` and updated only its five endpoint `$ref`s. The AI schemas
  (`Conversation` = `allOf ConversationSummary + messages`, `ChatMessage`) keep
  their names, so the AI endpoints resolve correctly again. Neither module's
  behaviour changed.
- **Result:** 181 component schemas, **zero duplicates**; all `$ref`s resolve.

### 2. OpenAPI version mismatch (`3.1.0` declared, 3.0 syntax used)
- **Problem:** The spec declared `openapi: 3.1.0` but was authored in 3.0 style,
  using `nullable: true` (200 occurrences) — a keyword removed in 3.1. Under 3.1
  every one was a validation error (202 errors total).
- **Fix:** Changed the declared version to `openapi: 3.0.3` (one line). Verified
  the spec uses **no** 3.1-only constructs (`type` arrays, schema `examples`,
  `const`, `webhooks`, `jsonSchemaDialect`, numeric `exclusiveMinimum`), so the
  downgrade is loss-less. The generated TypeScript client still builds.
- **Result:** OpenAPI validates with **0 errors**.

### 3. Duplicate/identical OpenAPI path `/recipes/{slug}` vs `/recipes/{id}`
- **Problem:** Two path items that are the same template (`/recipes/{param}`) —
  invalid in OpenAPI (`no-identical-paths`). In Laravel these are one URL segment
  distinguished only by HTTP method, so the doc split them incorrectly.
- **Fix:** Merged the public `GET` (recipe detail) into the single
  `/recipes/{id}` path item, with the parameter documented as accepting a slug or
  id (matching the controller's actual resolution). No route change.

### 4. Duplicate route `GET /v1/admin/users` (Identity vs Admin)
- **Problem:** The Identity module's RBAC block (`Route::prefix('admin')`) and the
  Admin module (`Route::prefix('v1/admin')`) both registered
  `GET /v1/admin/users`. Identity registers first, so Admin's route silently
  shadowed Identity's list endpoint — a latent, undiagnosable collision. Admin's
  is the one documented in OpenAPI and used by the web client.
- **Fix:** Moved Identity's RBAC routes to the `admin/rbac` sub-namespace
  (`GET /v1/admin/rbac/users`, `POST|DELETE /v1/admin/rbac/users/{userId}/roles`).
  No OpenAPI/web/mobile/test referenced Identity's old URIs, so nothing breaks.
- **Related doc drift fixed:** the OpenAPI paths for these RBAC endpoints were
  missing the `v1` prefix (`/admin/users…`) and duplicated the "list users"
  operation. They were re-pointed to `/v1/admin/rbac/users…`, removing the
  doc-level duplication too.
- **Result:** route parser over 428 routes finds **zero** cross-module or
  within-file duplicate full paths.

### 5. Missing AI provider environment variables in `.env.example`
- **Problem:** `ANTHROPIC_API_KEY` / `OPENAI_API_KEY` / `GEMINI_API_KEY` are
  required by `config/ai.php` (no in-code default, correctly — they are secrets)
  but were absent from `.env.example`, so a fresh setup of AI features would fail
  with no documented cause.
- **Fix:** Added an **AI Engine** section to `apps/api/.env.example` documenting
  `AI_PROVIDER` and the provider key(s), plus a pointer to the defaulted
  module-tuning vars.

---

## Accepted / known debt (not changed — documented deliberately)

### A. OpenAPI style warnings (363)
`@redocly/cli` reports 363 **warnings** (0 errors): 192 `operation-4xx-response`
(operations without a documented 4xx), 169 `operation-operationId` (missing
`operationId`), 1 server-example, 1 license-url. These are spec-wide and
stylistic; adding an `operationId` and a 4xx to ~150 endpoints is broad churn
with no correctness impact. Recommend addressing incrementally (e.g. a shared
`4xx` response ref and per-operation ids) rather than in a debt sweep.

### B. Module tuning env vars not enumerated in `.env.example` (~144)
Every module exposes `env('MODULE_KEY', default)` knobs (e.g.
`LOYALTY_POINTS_EXPIRY_DAYS`, `REVIEWS_MODERATION`, `SUPPORT_*`, `SEARCH_*`,
`PAYMENTS_*`). **All have safe in-code defaults**, so the app boots without them;
they are documented in each `config/<module>.php`. `.env.example` now points to
this rather than listing all 144 (which would risk transcription drift). Optional
follow-up: generate a complete `.env.example` from the config files in CI.

### C. Mobile test coverage gap
`apps/mobile` has 3 Dart test files (auth, catalog, widget) and **none** for the
newer feature modules (loyalty, reviews, search, support, payments, etc.). The
feature code is statically sound (137/137 Dart imports resolve) but untested.
Recommend adding repository/model unit tests per feature. Cannot be executed here
(no Flutter SDK — see VALIDATION_STATUS.md).

### D. PHP test suites not executable in this environment
The Laravel dependencies cannot be installed in the current sandbox
(`composer install` fails at authenticated GitHub package downloads), so the Pest
unit/feature suites cannot run here. The config is production-ready and the tests
are syntax-clean; they must be executed in an environment with package access.
This is an **environment limitation**, not a code defect.

### E. Shared `v1/admin` namespace across Identity and Admin
After fix #4 there is no route collision, but both the Identity (RBAC, now under
`v1/admin/rbac`) and Admin (`v1/admin/*`) contexts publish under the `admin`
prefix. This is intentional (both are back-office surfaces) and now
non-overlapping, but future admin endpoints in either context should continue to
sub-namespace (e.g. `v1/admin/rbac`, `v1/admin/cms`) to avoid re-introducing
collisions.

---

## Audited and clean (no action needed)

- Duplicate PHP classes (FQCN): none.
- Broken imports / dead references: none (1390/1390 `EruoFood\…` symbols resolve).
- Migration table-name and timestamp-prefix collisions: none (94 tables, 41 files).
- Domain event-name collisions: none (42 events, 42 distinct names).
- Illegal cross-bounded-context imports: none — modules integrate only via
  `EruoFood\Shared` and the published `EruoFood\Ai\Contracts`.
- PSR-4 autoload prefix/path uniqueness: clean.
- Orphaned config files: none (all 15 module configs are referenced).
- Duplicate OpenAPI schemas / identical path templates / unresolved `$ref`s: none.

---

## Milestone 19 — newly measured technical debt

### TD-M19-1 — PHPStan level-8 static-analysis gate is red (1885 errors)

**Discovered:** `composer run analyse` (PHPStan 2 + Larastan, `level: 8`,
`checkModelProperties: true`) was authored into CI in M18 but executed for the
first time in M19. It reports **1885 errors** across `app/` and all 15 `modules/`.

**Nature (not runtime defects):** the full runtime Pest suite passes **336/336** on
SQLite and PostgreSQL, so these are static-typing gaps, not behavioural bugs.
Dominant categories:

| Count | Identifier | Meaning |
|---|---|---|
| 947 | `property.notFound` | Eloquent models lack `@property` docblocks (magic attributes) |
| 357 | `method.notFound` | Eloquent/builder magic methods not annotated |
| 278 | `argument.type` | Loose array/mixed passed where a narrower type is declared |
| 124 | `arrayValues.list` | `array` used where a `list<>`/shape is expected |
| 56 | `return.type` | Return type narrower/wider than annotation |
| others | assorted | generics, nullable offsets |

**Why not fixed in M19:** remediation means adding `@property`/`@method`
annotations to every Eloquent model and tightening array generics across all
modules — a large, feature-independent typing pass. A non-feature "GA blocker
remediation" milestone must not undertake that as an unscoped change, and the
honesty mandate forbids hiding it behind a `phpstan-baseline.neon` to fake a green
gate. It is reported as **EXECUTED — FAILED** and carried into the GA decision.

**Remediation options (for a dedicated milestone), in order of preference:**
1. Generate accurate model annotations (`@property`) — idegenerator or hand — and
   fix the residual `argument.type`/`list` issues per module; keep level 8.
2. If timeline-constrained, adopt a **reviewed** `phpstan-baseline.neon` that
   freezes the 1885 known errors so **new** code is still gated at level 8 — an
   explicit, signed-off decision, documented here, not a silent suppression.
3. Lower the gate to a level the codebase currently meets and ratchet upward.

Recommendation: option 1 (or 2 as an interim) before full-platform GA.

### TD-M19-2 — Codebase-wide Pint drift (resolved)

`composer run lint` (`pint --test`, psr12 preset) was red across ~200 files
(migrations missing `new class()` parens, import ordering, argument spacing).
**Resolved** this milestone via `lint:fix`; the gate is now green and the full
test suite remains **336/336** after the reformat. No behavioural change.

### TD-M19-3 — Environment-limited validations (carried)

Not defects; blocked by the build sandbox. Each has a ready runbook:
- **Docker clean-env boot** — image pulls 403 from the Docker CDN in-session;
  `docker compose config` validates all 9 services. Run in staging.
- **Flutter analyze/test** — toolchain absent; structure statically present.
- **k6 production performance baseline** — no k6 binary / scaled target.
- **redocly OpenAPI lint** — CLI install network-blocked in-session; spec parses;
  runs in CI and passed in M17/M18.
- **External penetration test** — external requirement; plan in
  `docs/PENETRATION_TEST_PLAN.md`.
- **Infrastructure egress enforcement** — provider-dependent; spec in
  `docs/INFRA_EGRESS_POLICY.md`.
