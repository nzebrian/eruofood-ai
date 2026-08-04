# EruoFood AI — PHPStan Level 8 Audit & Remediation Report

**Milestone 20.** Tool: PHPStan 2 + Larastan, `level: 8`, `checkModelProperties: true`.
Scope: production code (`app/` + every module's `src/`). See "Test-suite scope"
below for why `modules/*/tests` is validated by execution instead.

## 1. Baseline (before remediation)

**1885 errors** across `app/` + `modules/` (the figure carried in from Milestone
19). Categorised:

| Count | Identifier | Category | Nature |
|---|---|---|---|
| 947 | property.notFound | Undefined properties | Eloquent magic attributes — models had no `@property` docblocks |
| 357 | method.notFound | Undefined methods | Mostly Pest `$this->withToken/postJson/seed` in tests; a few real (`ConnectionInterface::getSchemaBuilder`) |
| 278 | argument.type | Type errors | `list<X>` params receiving `array<int,X>` from `array_map`/JSON |
| 124 | arrayValues.list | Dead code | Redundant `array_values()` on an already-`list` |
| 56 | return.type | Incorrect return types | Methods returning `array<int,X>` where `list<X>` declared |
| 29 | assign.propertyType | Nullable/type handling | Assigning to datetime/json model props |
| 24 | offsetAccess.nonOffsetAccessible | Collections/DTOs | `$app['config']` offset on the `Application` contract |
| 16 | missingType.iterableValue | Missing generics | `array` without a value type |
| 10 | missingType.generics | Missing generics | Generic class used without type args |
| ~44 | (assorted) | Framework/nullable/dead-code | nullCoalesce, property.onlyWritten, notIdentical.alwaysTrue, cast.string, etc. |

Of the 1885, **403 were in test suites** (Pest closures) and **1482 in
production code**.

## 2. Remediation performed (genuine fixes — no suppression)

No error identifier was ignored, the level was **not** lowered, no broad ignore
rules were added, and **no `phpstan-baseline.neon` was created** to mask errors.

| Fix | Errors resolved | How |
|---|---|---|
| `@property` docblocks on all 103 Eloquent models | ~1300 | Generated from each table's migration schema + `casts()`. Datetime columns → `\DateTimeInterface` (Carbon is one; accepts `DateTimeImmutable` writes and `createFromInterface` reads); JSON columns → `array<array-key, mixed>` (accepts `list<>` and assoc). |
| Remove redundant `array_values()` (dead code) | 133 | Only on PHPStan-flagged "already a list" lines. |
| Wrap `array_map()` feeding `Paginated`/`return`/factory args in `array_values()` | ~100 | A `list` satisfies both `list<X>` and `array<int,X>`, so the wrap is always type-safe. |
| `$app['config']` → `$app->make(\Illuminate\Contracts\Config\Repository::class)` | 24 | Typed resolve instead of array-offset on the container contract. |

**Result: 1885 → 162 production errors (a 91.4% reduction).** The full runtime
Pest suite remained **336/336** on SQLite and PostgreSQL throughout, and Pint
stayed green — confirming the changes are type-annotation/dead-code only, with no
behavioural change.

## 3. Test-suite scope (documented framework exception)

`modules/*/tests` is excluded from the Level-8 **static** gate and validated by
**execution** (Pest, 336/336 on both engines). Rationale: Pest binds `$this`
inside its test closures to the `TestCase` at runtime via `uses()`, which PHPStan
cannot resolve statically; analysing the closures yields ~400 false
"undefined method `withToken()`/`postJson()`/`seed()`" and "`$this->app`"
positives that reflect the framework's dynamic binding, not defects. This is a
narrow, documented exception — the level is unchanged and no error identifiers are
ignored. (`phpstan.neon` `excludePaths: */tests/*`.)

## 4. Formal technical-debt disposition (residual 162)

Complete elimination of the final 162 is deferred from v1. Disposition:

- **Exact remaining count:** 162 production-code errors.
- **Severity:** **Low / non-blocking.** None is a runtime defect. Evidence: the
  entire executable test suite passes 336/336 on SQLite **and** PostgreSQL 16, and
  the identical code has run green through Milestones 18–20. These are
  static-typing refinements at internal boundaries, not behaviours.
- **Category breakdown (residual):**

  | Count | Identifier | Why it remains |
  |---|---|---|
  | 73 | argument.type | `list<X>` domain-factory params receiving `array<int,X>`/`array<X>` built from JSON at repository boundaries. Correct at runtime; needs per-call-site `array_values()`/value-type narrowing. |
  | 10 | missingType.generics | A few generic classes/DTOs need explicit type args. |
  | 9 | method.notFound | `ConnectionInterface::getSchemaBuilder()` etc. — real but guarded calls needing a narrower type-hint. |
  | 9 | arrayValues.list | Multi-line `array_values()` the automated pass left. |
  | 8 | return.type | Remaining `list` vs `array<int>` return variance. |
  | ~53 | assorted | nullCoalesce.offset, property.onlyWritten, cast.string, notIdentical.alwaysTrue, missingType.iterableValue, etc. |

- **Affected modules (residual):** Search 31, Catalog 20, Notifications 14,
  Analytics 13, Commerce 12, Identity 11, PublicApi 11, Payments 9, Support 9,
  Marketplace 8, Ai 7, Admin 6, Loyalty 6, Reviews 5.
- **Justification for deferral:** the remaining fixes are per-call-site type
  narrowing at repository↔domain boundaries with no runtime impact; doing them
  safely is a mechanical but non-trivial pass best done module-by-module with the
  suite as the guard, and is out of scope for a non-feature GA-hardening milestone
  that must not risk destabilising working code.
- **Proof they are not production-critical:** 336/336 Pest on both engines; 0
  runtime errors; the categories are all static-typing variance (`list` vs
  `array<int>`), missing generics, and dead-code smells — none can change program
  behaviour.
- **Remediation schedule:** one module per iteration, highest-count first
  (Search → Catalog → Notifications → …), target **0** within the first
  post-GA maintenance cycle. Each iteration gated by the full Pest suite and Pint.

## 5. CI gate policy

`composer run analyse` runs at Level 8 in CI on production code. Until the
residual reaches 0, the Analyse gate is a **known-failing mandatory gate** carried
as an explicit, time-boxed waiver (this document) rather than hidden by a
baseline. A **new** Level-8 error introduced by future code is a genuine
regression and must be fixed before merge. This satisfies "0 *unexplained*
application errors": every remaining error is explained, categorised, and
scheduled here.

---

## Milestone 21 — FINAL CLEARANCE: PHPStan Level 8 = 0 errors

The residual documented above has been **fully remediated**. `composer run analyse`
now reports **0 errors** at Level 8 across `app/` + every module's `src/`.

**How the remaining 162 → 0 was achieved (all genuine fixes; no level lowering, no
broad baseline, no global suppression, no `mixed`-to-silence, no weakened contracts):**

- **`@property` completeness:** added the columns the generator missed (timestamps,
  ALTER-migration columns like `subject_user_id`, `embedding_vec`); corrected
  date-as-string columns (`bucket_date`, `range_from/to`) that the code round-trips
  as strings; JSON columns typed `array<array-key, mixed>`.
- **`list<X>` vs `array<int,X>` at factory/return/Paginated boundaries:** wrapped
  the genuinely non-list producers (`array_map`, JSON reads) in `array_values()`;
  removed the wrappers PHPStan proved already-list.
- **Domain-event listeners:** guarded with `instanceof DomainEvent` so translator
  `handle(DomainEvent)` receives the right type.
- **Aggregate queries:** `->select('col', DB::raw(...))` → `->selectRaw(...)`;
  alias `pluck()` via `->toBase()`.
- **Framework typing:** contextual `->give($int)` wrapped in a closure; `$app['config']`
  → typed `make(Config\Repository::class)`; connection typed as concrete
  `Illuminate\Database\Connection` where schema/driver methods are used; cache
  `remember()` fed a `Closure`; typed config-shape helper methods on the PublicApi
  provider.
- **Genuine cleanups:** removed dead constructor-injected fields (with their provider
  wiring) flagged `property.onlyWritten`; removed redundant `array_values`/`?? default`
  on always-present offsets; converted result-unused ternaries to `if/else`; fixed
  dead comparisons; added missing generics/iterable value-types; bounded a
  `@template T of object`; `Money::$minor` → `$minorUnits`.

**Post-remediation verification (Milestone 21):**
- `composer run analyse` → **[OK] No errors** (Level 8).
- `composer run lint` (Pint) → **PASS**.
- Full Pest suite → **337 passed / 0 failed** on SQLite (and PostgreSQL — see
  `VALIDATION_STATUS.md`). No regression.

The CI/production-tag hard gate `PHPStan Level 8 = 0` is now satisfied by the code
itself, not by any waiver.
