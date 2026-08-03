# Validation Status

---

## Milestone 16 — Public API, SDK & Developer Platform (2026-08-02)

Honest classification of what was actually run for this milestone.

### ✅ Executed and Passed

| Check | Tool | Result |
|---|---|---|
| PHP syntax — all modules | `php -l` | **1482/1482** files clean |
| Cross-module reference resolution | ref-check script | **1482/1482** resolve; no dead refs |
| Composer manifest | `composer validate` | valid |
| PublicApi domain/security logic | pure-PHP sanity harness | **34 checks passed / 34** (scopes, scope-intersection never widens, key hash/verify, key usability, HMAC sign/verify + replay, webhook backoff/idempotency, envelope pagination) |
| OpenAPI spec | `@redocly/cli lint` | **0 errors** (392 style warnings — pre-existing operationId/4xx set) |
| OpenAPI structure | YAML + `$ref` audit | 0 duplicate schemas, 0 unresolved refs, 0 identical path templates; `apiKeyAuth` scheme defined |
| Web type-check | `tsc --noEmit` | exit 0 |
| Web lint | `eslint src` | exit 0 |
| Web unit tests | `vitest run` | **51 passed / 51** (15 files, incl. new `developerApi` suite) |
| Web production build | `vite build` | exit 0 |

The sanity harness genuinely executes the security-critical logic that the Pest
feature suite would otherwise cover (key hashing, scope enforcement, HMAC
signing + replay window, webhook retry/backoff, idempotency).

### 🟡 Static Validation Only

| Check | Method | Result |
|---|---|---|
| PublicApi Pest unit + feature tests | `php -l` + reference resolution | Syntax-clean; symbols resolve. **Not executed** (see below). |
| Middleware/route wiring, DI bindings | code review against Laravel conventions | Consistent with existing modules; not runtime-verified. |
| PHP SDK (`packages/sdk-php`) | `php -l` | Clean; not executed against a live API. |
| Dart SDK (`packages/sdk-dart`) | code review vs `package:http` API | Structurally sound; **no Dart toolchain** to compile/test. |
| TypeScript SDK (`packages/sdk-typescript`) | reviewed; compiled via app `tsc` context | Standalone package has no its own build run here. |

### ⚪ Not Validated (environment cannot execute)

| Check | Why |
|---|---|
| **PHP Pest suites** (PublicApi unit + feature, incl. api-key auth / scope / rate-limit / webhook signature / idempotency tests) | `composer install` cannot finalize in this sandbox (authenticated GitHub package downloads fail); no `vendor/bin/pest`. Tests are written and syntax-clean but **not run**. No pass is claimed. |
| **Dart SDK tests** | Flutter/Dart toolchain absent. |

To execute in a capable environment:

```bash
cd apps/api && composer install && vendor/bin/pest        # PHP
cd packages/sdk-dart && dart pub get && dart analyze       # Dart SDK
```

---

# Validation Status (Cleanup)

Date: 2026-08-02
Scope: Technical Debt & Validation Cleanup (no new business features).

This document records **exactly what was run and what was not**, and why. Checks
are classified as:

- ✅ **Executed and Passed** — a real tool ran to completion with a passing result.
- ❌ **Executed and Failed** — a real tool ran and reported failures.
- 🟡 **Static Validation Only** — verified by inspection/scripts/linters that do
  not execute the application or its test suites.
- ⚪ **Not Validated** — could not be checked in this environment.

Environment limitations are stated plainly; nothing is claimed to have "passed"
unless it actually executed.

---

## ✅ Executed and Passed

| Check | Tool | Result |
|---|---|---|
| OpenAPI specification validity | `@redocly/cli@1.25.0 lint` | **0 errors** (363 style warnings — see TECHNICAL_DEBT.md) |
| OpenAPI generated TS client | `openapi-typescript@7.4.1` | Generated cleanly; `MessagingConversation`/`MessagingMessage` present |
| Web type-check | `tsc --noEmit` | Exit 0 |
| Web lint | `eslint src` | Exit 0 |
| Web unit tests | `vitest run` | **46 passed / 46** (14 files) |
| Web production build | `vite build` | Exit 0 |
| PHP syntax (all module files) | `php -l` | **1390/1390** clean |
| Composer manifest | `composer validate` | `composer.json is valid` |
| Domain logic — Loyalty | pure-PHP sanity harness | **52 checks passed / 52** |
| Domain logic — Reviews | pure-PHP sanity harness | **40 checks passed / 40** |

The two PHP "sanity harnesses" `require` the domain classes directly (no
framework) and exercise the trickiest algorithms — points ledger math, tier
resolution, redemption/referral lifecycles (Loyalty); rating projection,
moderation transitions, content filter (Reviews). They are a genuine, executed
substitute for the parts of the Pest suite that cannot run here (see below).

## 🟡 Static Validation Only

| Check | Method | Result |
|---|---|---|
| Cross-module reference resolution (PHP) | script resolving every `EruoFood\…` symbol against PSR-4 | **1390/1390** files resolve; no dead references |
| Duplicate PHP classes (FQCN) | AST-ish scan of `namespace`+`class/interface/trait/enum` | **None** |
| Duplicate routes (method + full path incl. prefixes) | route-file parser (428 routes) | **None** after fix (see TECHNICAL_DEBT.md #4) |
| Duplicate OpenAPI component schemas | YAML parse + key counting | **None** (181 schemas, all unique) |
| Identical OpenAPI path templates | param-normalized path comparison | **None** (243 paths) |
| Unresolved OpenAPI `$ref`s (schemas/responses/parameters) | regex vs component keys | **None** |
| Migration table-name / timestamp-prefix collisions | scan of `Schema::create` + filenames | **None** (94 tables, 41 files) |
| Domain event-name collisions | scan of `eventName()` return strings | **None** (42 classes / 42 distinct names) |
| Cross-bounded-context imports | scan for `use EruoFood\<Other>\…` outside `Shared`/`Contracts` | **None illegal** — only `EruoFood\Ai\Contracts` consumed |
| PSR-4 prefix/path uniqueness | composer.json inspection | **Unique** (19 prod, 17 dev) |
| Config files referenced | accessor scan (`config()`/`->get()`) | All 15 module configs referenced; none orphaned |
| Flutter dependency manifest | `pubspec.yaml` inspection | Valid (Dart SDK ≥3.5.0, Flutter ≥3.24.0; get_it/dio/dartz/bloc) |
| Flutter/Dart imports | static import-resolution scan | **137/137** Dart files resolve; no missing files or undeclared packages |
| composer.json PHP/Laravel/Pest versions | inspection | php ^8.4, laravel/framework ^12, pestphp/pest ^3.5, larastan ^3, pint ^1.18 — production-ready |
| Pest/PHPUnit config | `phpunit.xml`, `tests/Pest.php`, `tests/TestCase.php` inspection | Present and coherent (feature suite paths include all modules incl. Loyalty/Reviews) |

## ⚪ Not Validated (environment cannot execute)

| Check | Why | Evidence |
|---|---|---|
| **PHP Pest/PHPUnit test suites** (unit + feature) | `composer install` **cannot finalize** in this sandbox — several dist packages require GitHub authentication the proxy cannot provide, so the install aborts before generating the autoloader. `vendor/` remains a stub (`vendor/autoload.php` = 22-line placeholder, `vendor/bin/` absent, no `vendor/bin/pest`). | `composer install` reached 134/134 downloads then failed with `Could not authenticate against github.com` (AuthHelper.php:132), exit 100 |
| **Flutter/Dart tests** (`flutter test`) | Flutter and Dart toolchains are **not installed** (`which flutter dart` → not found). | Toolchain absent on PATH |

No claim is made that these suites pass. Their **static** structure was verified
(above): PHP test files are syntax-clean and reference resolvable symbols; Dart
test imports resolve. Executing them requires an environment with the Laravel
dependencies installed and the Flutter SDK present.

---

## How to execute the un-run suites (in a capable environment)

```bash
# PHP (needs composer deps installed — requires authenticated package downloads)
cd apps/api && composer install && vendor/bin/pest

# Flutter (needs the Flutter SDK)
cd apps/mobile && flutter pub get && flutter test
```

---

# Milestone 17 — Public API Completion & GA Hardening

Validation classification (honest): **Executed & Passed** / **Static Validation
Only** / **Not Validated**. No test is claimed as passed unless it was actually
run in this environment.

## Executed & Passed (run in this environment)

| Check | Command | Result |
|---|---|---|
| SSRF guard logic | `php scratchpad/ssrf_guard_sanity.php` | **25/25 passed** — blocks loopback/private/link-local/CGNAT/IPv6-ULA/mapped, credentials, bad scheme/port; allows public dsts. |
| BOLA/IDOR order authorization | `php scratchpad/bola_sanity.php` | **6/6 passed** — authenticated subject only reaches the Order domain; app-level credentials refused. |
| OAuth2 grant logic | `php scratchpad/oauth_sanity.php` | **18/18 passed** — PKCE verify, single-use codes, refresh rotation + revocation, client-credentials (no subject/no refresh), scope containment, introspection. |
| Public API read/order sanity | `php scratchpad/publicapi_sanity.php` | **34/34 passed** (M16 harness, still green). |
| PHP syntax (all new files) | `php -l` on every new/edited file | **No syntax errors.** |
| Cross-reference resolution | `check_refs2.py modules/PublicApi` | **OK — all EruoFood references resolve across 151 files.** |
| OpenAPI contract | `redocly lint openapi.yaml` | **Valid — 0 errors** (411 pre-existing style warnings). |
| TypeScript SDK | `tsc --noEmit --strict src/index.ts` | **Clean (exit 0).** |
| PHP SDK | `php -l sdk-php/src/Client.php` | **No syntax errors.** |

The Pest tests `WebhookSecurityTest` and `OAuthServiceTest` mirror the executed
standalone harnesses (same assertions, in-memory repositories) but were not run
through Pest here — see below.

## Static Validation Only (not executed as a suite)

- **Pest test suites** (`WebhookSecurityTest`, `OAuthServiceTest`, and the M16
  suites): `php -l` clean, logic proven by the standalone harnesses, but the
  Pest runner was not executed — `composer install` cannot finalize in this
  sandbox (GitHub auth failures on dist downloads; `vendor/` remains a stub).
- **BOLA/IDOR order authorization**: enforced by construction (subject user is
  derived only from the credential; the Commerce `OrderService` re-checks
  ownership) and covered by written tests; the full HTTP feature path needs the
  framework to run.
- **Dart SDK**: new methods added and eyeball-verified; the Dart toolchain is
  absent, so `dart analyze`/`dart test` were not run.

## Not Validated (require a runtime environment)

- **Redis** production behaviour: rate limiting, quotas, idempotency and
  distributed counters were **not** validated against a real Redis. No
  concurrency/load/soak/failure-recovery run was performed.
- **Performance**: p50/p95/p99 latency, throughput and error rate were **not
  measured**. Marked *Not Validated* — a load-test environment was unavailable.

These are recorded as GA blockers in `PUBLIC_API_GA_CHECKLIST.md`.
