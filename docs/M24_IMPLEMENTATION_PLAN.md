# Milestone 24 — KYC/KYB & Identity Verification · Implementation Plan

**Status:** awaiting approval · **Branch:** `claude/m24-kyc-kyb` (from `65feb36`, the approved M23 baseline)

**Protected:** M23 financial-integrity code is not rewritten or weakened. M24 *reuses*
its primitives (`TransactionManager`, `IdempotencyStore`, claim-first webhook
handling, append-only trigger pattern, optimistic `version` columns). The only
edits to M23-touched files are **additive**: three new permissions and one new
role in the Admin RBAC catalogue.

**Explicitly out of scope** (later milestones): Google Maps, dynamic delivery
pricing, dispatch, vehicles, Payment Orchestrator, provider splits, POD, dispute
engine, catalogue expansion, full Command Centre UI, revenue engine, mobile
release work. Marketplace and Commerce are **not** consolidated.

---

## A. Architecture

A new bounded context, **`modules/Verification`** (`EruoFood\Verification`),
following the established four-layer module shape.

**Why a new context rather than extending Identity.** Identity owns
authentication. Verification owns *assurance* — a different lifecycle, different
provider integrations, different retention and privacy rules, and a different
admin audience. Folding it into Identity would couple the login path to KYC and
spread regulated data across a context that every request touches. A separate
context also lets one model serve all three subject kinds (customer, rider,
business) instead of three near-duplicate implementations.

**One aggregate, three subjects.** `VerificationCase` is the aggregate root. It
carries a `subject` — `(SubjectType, subjectId)` — exactly the soft-reference
pattern the Reviews module already uses for its polymorphic subjects. Nothing in
Verification joins to another context's tables.

```
modules/Verification/src/
├── Domain/
│   ├── Case/            VerificationCase (aggregate), CaseRepository
│   ├── Attempt/         VerificationAttempt, AttemptRepository
│   ├── Business/        BusinessProfile, BusinessRepresentative, repositories
│   ├── Document/        DocumentMetadata (no raw files)
│   ├── Enum/            VerificationStatus, SubjectType, CaseType,
│   │                    VerificationLevel, RejectionReason, ProviderName
│   ├── Event/           SubjectVerified, SubjectRejected, SubjectExpired,
│   │                    ReverificationRequired
│   ├── Policy/          StepUpPolicy, RiskTrigger
│   └── Exception/       InvalidVerificationTransition, VerificationNotFound, …
├── Application/
│   ├── Port/            IdentityVerificationProvider, VerificationProviderRegistry,
│   │                    BusinessRegistryProvider, FieldEncryptor, VerificationNotifier
│   ├── DTO/             VerificationRequest, VerificationSession,
│   │                    VerificationDecision, WebhookNotification
│   └── Service/         VerificationService, WebhookService, ReviewService,
│                        EligibilityService, StepUpService, ReconciliationService
├── Contracts/           VerificationStatusQuery  ← the published read port
├── Infrastructure/
│   ├── Provider/Didit/  DiditProvider, DiditStatusMap, DiditSignatureVerifier
│   ├── Provider/Mock/   MockProvider (deterministic, offline)
│   ├── Provider/Manual/ ManualReviewProvider (CAC and other no-API registries)
│   ├── Registry/        ConfigProviderRegistry
│   ├── Security/        LaravelFieldEncryptor, RedactingLogger
│   ├── Persistence/     Eloquent repositories + migrations
│   └── Console/         VerifyReconcileCommand, VerificationPurgeCommand
└── Interface/Http/      Controllers, Middleware, routes.php
```

---

## B. Bounded-context integration

Verification never writes to another context, and no context reads Verification's
tables. Two integration primitives, both already used elsewhere in this codebase:

**1. Published read port** — `Verification\Contracts\VerificationStatusQuery`:

```php
interface VerificationStatusQuery
{
    public function statusFor(string $subjectType, string $subjectId): string;
    public function isVerified(string $subjectType, string $subjectId): bool;
    public function levelFor(string $userId): string;   // progressive level
}
```

Mirrors `Payments\Contracts\PaymentInitiator`. Consumers depend on the interface,
never on Verification's classes.

**2. Domain events → local projections.** Verification publishes
`SubjectVerified` / `SubjectRejected` / `SubjectExpired`; each consuming context
subscribes and updates a **column on its own table**. This is exactly how Search
maintains its index today (`config/search.php` `index_events`).

| Consumer | Projection column | Effect |
|---|---|---|
| Marketplace | `marketplace_vendors.kyb_status` | gates `Vendor::canTrade()` |
| Marketplace | `marketplace_riders.kyc_status` | gates delivery assignment |
| Commerce | `commerce_stores.kyb_status` | gates a new `Store::canTrade()` |
| Identity | `identity_users.verification_level`, `phone_verified_at` | progressive level |

Projections keep the eligibility check local and fast (no cross-context call in
the checkout hot path) while the source of truth stays in Verification. Each
consumer's migration lives in **that consumer's module**, since it owns the table.

---

## C. Database schema

All migrations reversible; all additive. New tables are owned by Verification
except the four projection columns noted above.

### `verification_cases` — the aggregate
| Column | Notes |
|---|---|
| `id` uuid pk | |
| `subject_type` | `customer` \| `rider` \| `business` |
| `subject_id` uuid | soft ref |
| `case_type` | `identity` \| `business` |
| `country_code` char(2) | drives provider + requirement selection |
| `status` | the state machine (§H) |
| `requested_level` | target `VerificationLevel` |
| `provider` / `provider_reference` | current session; `provider_reference` unique |
| `decision_reason_code` | classified rejection reason |
| `verified_at`, `expires_at` | nullable |
| `open_key` | **nullable unique**, set to `"{subject_type}:{subject_id}"` while open, `NULL` once closed — guarantees **at most one open case per subject** while preserving full history. Portable across PostgreSQL and SQLite (unlike a partial index). |
| `version` | optimistic lock, M23 pattern |
| indexes | `(subject_type, subject_id)`, `(status)`, `(expires_at)` |

### `verification_attempts` — one row per provider session
`id`, `case_id`, `provider`, `provider_reference` **unique**, `status`,
`raw_provider_status`, `reason_code`, `started_at`, `decided_at`.
Index `(case_id, started_at)`.

### `verification_events` — append-only audit
`id`, `case_id`, `from_status`, `to_status`, `actor_type`
(`system`\|`provider`\|`admin`), `actor_id`, `reason_code`, `note`, `occurred_at`.
**Protected by the same PostgreSQL `BEFORE UPDATE OR DELETE` trigger pattern M23
introduced for the ledger** — an identity audit trail deserves the same
tamper-evidence as a financial one.

### `verification_documents` — metadata only
`id`, `case_id`, `document_type`, `issuing_country`, `document_number_last4`
(encrypted), `expires_on`, `provider_reference`, `created_at`.
**No file path, no blob, no image column** — the schema makes raw-document
storage structurally impossible, not merely discouraged.

### `verification_business_profiles`
`id`, `business_id` (soft ref), `business_kind` (`restaurant`\|`grocery`),
`country_code`, `registered_name`, `trading_name`, `business_type`,
`registration_number` (**encrypted**), `registration_authority` (`CAC` for NG),
`address` jsonb, `latitude`, `longitude`, `payout_account_case_id` (nullable hook
for M27), `status`, timestamps.
Unique `(business_kind, business_id)`; index `(country_code, status)`.

### `verification_business_representatives`
`id`, `business_profile_id`, `user_id`, `full_name`, `role`, `is_primary`,
`identity_case_id` (FK-by-convention to a `verification_cases` row),
`ownership_percentage` (nullable — populated only where law requires).
Unique `(business_profile_id, user_id)`.

### `verification_webhook_events` — idempotency + replay
`id`, `provider`, `provider_event_id`, `signature_version`, `received_at`.
Unique `(provider, provider_event_id)` — the mutex, mirroring
`payments_webhook_events`.

### Consumer-owned additive columns
- `marketplace_vendors.kyb_status` (default `not_started`) + index
- `marketplace_riders.kyc_status` (default `not_started`) + index
- `commerce_stores.kyb_status` (default `not_started`) + index
- `identity_users.verification_level` (default `basic`), `identity_users.phone_verified_at`

---

## D. Provider abstraction

```php
interface IdentityVerificationProvider
{
    public function name(): ProviderName;
    public function supports(CaseType $type, string $countryCode): bool;
    public function createSession(VerificationRequest $request): VerificationSession;
    public function fetchDecision(string $providerReference): VerificationDecision;
    public function parseWebhook(string $rawBody, WebhookHeaders $headers): WebhookNotification;
}

interface VerificationProviderRegistry
{
    public function for(ProviderName $name): IdentityVerificationProvider;
    public function resolve(CaseType $type, string $countryCode): IdentityVerificationProvider;
}
```

Provider-neutral DTOs carry **no Didit vocabulary**: `VerificationRequest`
(subject, case type, country, requested checks, callback URL, redirect URL),
`VerificationSession` (provider reference, hosted URL, initial status),
`VerificationDecision` (status, reason code, document metadata, decided-at),
`WebhookNotification` (provider event id, provider reference, status, occurred-at).

Error mapping is provider-owned: each adapter maps its own failure vocabulary to
the shared `RejectionReason` enum (`document_expired`, `document_unreadable`,
`face_mismatch`, `liveness_failed`, `data_mismatch`, `duplicate_identity`,
`unsupported_document`, `provider_error`, `manual_rejection`).

**Adapters shipped in M24:** `DiditProvider`, `MockProvider` (deterministic and
offline, forced under `APP_ENV=testing` exactly as `config/payments.php` forces
the mock gateway), and `ManualReviewProvider` for jurisdictions where business
registry lookup has no API — including CAC today, which is a human-review flow.

A separate `BusinessRegistryProvider` port abstracts registry *lookup*
(`NigeriaCacProvider` as a manual/stub implementation) so a real CAC API — or a
Companies House equivalent elsewhere — drops in without touching business logic.

---

## E. Didit adapter design

Grounded in Didit's published v3 API. **Caveat stated plainly:** `docs.didit.me`
is blocked by this environment's egress policy, so the details below come from
Didit's public documentation surfaced via search. Field names beyond those
confirmed must be checked against live docs during implementation; the adapter is
deliberately a single file so that is a contained change.

| Concern | Design |
|---|---|
| Base URL | `https://verification.didit.me`, configurable |
| Auth | `x-api-key` header, from env |
| Create session | `POST /v3/session/` with `workflow_id`, `vendor_data`, `callback` |
| `vendor_data` | **the verification case id**, not the user id — an opaque UUID, so no internal user identifier leaves our system |
| Session response | `session_id` → `provider_reference`; `session_url` → hosted flow; `status` |
| Fetch decision | `GET /v3/session/{session_id}/decision/` |
| Workflows | one `workflow_id` per requirement set (rider ID+licence+liveness, representative ID), all env-configured |

**Status mapping** (Didit → EruoFood):

| Didit | EruoFood |
|---|---|
| `NOT_STARTED` | `PENDING` |
| `IN_PROGRESS` | `PROCESSING` |
| `IN_REVIEW` | `REQUIRES_REVIEW` |
| `APPROVED` | `VERIFIED` |
| `DECLINED` | `REJECTED` |
| `RESUBMITTED` | `REVERIFICATION_REQUIRED` |

Unknown values map to `REQUIRES_REVIEW` and raise an alert rather than silently
approving — fail-closed.

**Webhook signature.** Didit sends `X-Signature-V2` (preferred, survives JSON
re-encoding), with `X-Signature-Simple` and `X-Signature` as fallbacks. HMAC-SHA256
over the **raw request body** using the destination's `secret_shared_key`, compared
with `hash_equals`. The body is never parsed before the signature verifies. The
payload timestamp is checked against a configurable freshness window for replay
protection.

---

## F. Security & privacy model

| Control | Implementation |
|---|---|
| No raw documents | Schema has no blob/path column; only metadata + provider reference |
| Encryption at rest | `registration_number`, `document_number_last4` via a Verification-owned `FieldEncryptor` port (mirrors Payments') |
| No sensitive data in logs | `RedactingLogger` wrapper; webhook bodies logged as `sha256` digest + event id only, never contents |
| Secrets | env only; `.env.example` ships empty keys (respecting the Gitleaks allowlist precedent) |
| Webhook auth | signature required; unsigned/mismatched → 401, no state change |
| Replay protection | timestamp freshness window + unique `(provider, provider_event_id)` |
| Idempotency | claim-first inside one transaction — the M23 pattern, including the PostgreSQL savepoint fix |
| Rate limiting | per-route `throttle` on webhook and on session-creation endpoints |
| Access logging | every admin read of identity data writes an `AuditCategory::DataAccess` entry |
| Retention | `expires_at` + `verification:purge` command for metadata past its retention window |
| Never trust the client | no endpoint accepts a client-asserted verification result; status changes originate only from a signed webhook, a polled provider decision, or an audited admin action |

---

## G. Authorization model

**Three new Admin permissions**, deliberately separating *seeing that a case
exists* from *seeing the identity data inside it*:

| Permission | Grants |
|---|---|
| `verification.read` | queue, case status, reason codes, timestamps — **no identity fields** |
| `verification.review` | approve / reject / request reverification |
| `verification.pii` | the sensitive fields (name on document, last-4, DOB, registration number) |

**New role `ComplianceOfficer`** = `verification.read` + `verification.review` +
`verification.pii` + `audit.read`.

Existing roles change as follows — additive, nothing weakened:
- `Admin` gains **`verification.read` only**. A general administrator can see the
  queue and unblock operations but **cannot read identity documents** — this is
  the "do not automatically give every admin access" requirement.
- `OperationsManager` gains `verification.read`.
- `SuperAdmin` retains everything by definition. Access is not silently
  unlimited: every `verification.pii` read is audited regardless of role, so
  SuperAdmin access is visible rather than invisible.

**Subject-level access.** A customer, rider or merchant may read *their own* case
— status, reason code, what to do next — and never the stored identity fields or
anyone else's case. Enforced in the application service by a subject-ownership
assertion, the same shape as M23's `WalletService::assertOwner()`.

---

## H. Verification state machine

```
NOT_STARTED ─→ PENDING ─→ PROCESSING ─→ VERIFIED
                  │           │    │        │
                  │           │    │        ├─→ EXPIRED
                  │           │    │        └─→ REVERIFICATION_REQUIRED
                  │           │    └─→ REQUIRES_REVIEW ─→ VERIFIED | REJECTED
                  │           └─→ REJECTED
                  └─────────────────────────────┘
REJECTED / EXPIRED / REVERIFICATION_REQUIRED ─→ PENDING   (new attempt)
```

Enforced by an explicit transition table in `VerificationCase`; anything else
throws `InvalidVerificationTransition`. **Every** transition appends a
`verification_events` row with actor and reason — including provider-driven ones.
Transitions run inside `TransactionManager::atomic()` with the case row locked, so
a webhook and an admin decision cannot race.

---

## I. Merchant / rider activation rules

Backend-enforced at the point of action, never by a client flag:

| Subject | Rule | Enforcement point |
|---|---|---|
| Restaurant | unverified → cannot accept orders | `Vendor::canTrade()` also requires `kyb_status = verified`; already checked in `Marketplace\CheckoutService` |
| Grocery | unverified → cannot accept orders | **new** `Store::canTrade()`, enforced in `Commerce\CheckoutService::place()` |
| Rider | unverified → cannot be assigned | `DeliveryService::assignRider()` rejects; `RiderService::setStatus(Available)` rejects |
| Customer | step-up required → sensitive op blocked | `requires.verification:{level}` middleware + `StepUpService` |

> **Gap found during research, closed here.** `Commerce\CheckoutService` performs
> **no store-verification check at all** today — only *product publishing* checks
> it (`ProductService:41`). Because `StoreService::suspend()` merely flips
> `verified` to false while leaving products published, a suspended or unverified
> grocery can currently still receive orders. M24 closes this.

**Step-up triggers are configuration, not code** (`config/verification.php`):

```php
'step_up_triggers' => [
    'wallet.transfer'         => ['above_minor' => 5_000_00, 'level' => 'identity'],
    'payout.bank_details'     => ['always' => true,          'level' => 'identity'],
    'account.email_change'    => ['always' => true,          'level' => 'phone'],
    'account.password_change' => ['always' => true,          'level' => 'phone'],
    'risk.dispute_count'      => ['threshold' => 3,          'level' => 'identity'],
    'risk.suspicious_login'   => ['always' => true,          'level' => 'phone'],
],
```

Progressive levels: `basic` (email) → `phone` (email + phone) → `identity`
(full KYC). Ordinary registration reaches `basic` and is never forced further.

---

## J. Webhook architecture

`POST /api/v1/verification/webhooks/{provider}` — public by necessity, throttled,
and hardened:

1. Read the **raw** body; verify the signature before parsing anything.
2. Parse to a provider-neutral `WebhookNotification`.
3. Check timestamp freshness (replay window).
4. Open one transaction: **claim** `(provider, provider_event_id)` by unique
   insert → resolve `provider_reference` to an attempt and its case → apply the
   state transition under a row lock → append the audit event.
   A failure rolls the claim back so the provider's retry is honoured. This is
   the M23 exactly-once pattern verbatim, including the savepoint fix.
5. Return 200 with no detail — a webhook response never leaks case state.

**Reconciliation as a safety net.** `verification:reconcile` polls
`fetchDecision()` for cases stuck in `PROCESSING` beyond a configurable age, so a
dropped webhook cannot strand a rider or merchant indefinitely.

---

## K. Testing strategy

**Unit** — state-machine transitions (every legal and illegal pair), status
mapping per provider, rejection-reason mapping, step-up trigger evaluation,
eligibility rules, `BusinessProfile` invariants.

**Integration** — Didit adapter against a faked HTTP client (`Http::fake()`)
covering session creation, decision fetch, and each status; webhook end-to-end
via the mock provider; idempotency; reconciliation.

**Authorization** — customer cannot read another user's case; rider cannot read
another rider's KYC; merchant cannot read another merchant's KYB; `Admin` sees
the queue but is refused identity fields; `ComplianceOfficer` is permitted;
SuperAdmin permitted **and audited**; unauthenticated refused.

**Security** — forged signature rejected; duplicate webhook applied once;
replayed (stale timestamp) webhook rejected; unknown provider reference rejected
without state change; IDOR attempts across all three subject types; privilege
escalation via role/permission manipulation; **assertion that no sensitive field
appears in log output**; every invalid state transition rejected.

**Database** — unique constraints (`open_key` one-open-case rule, provider
reference, webhook event); index presence; append-only trigger on
`verification_events`; `migrate:fresh → rollback → migrate` on PostgreSQL.

**Regression (hard gates, unchanged from M23):** full suite green on SQLite
**and** PostgreSQL 16; PHPStan Level 8 at **0**; Pint clean; the M23 financial
concurrency script still 23/23; OpenAPI redocly 0 errors.

---

## L. Files and modules expected to change

**New** — the whole of `modules/Verification/` (~70 files), `config/verification.php`,
8 Verification migrations, 4 consumer projection migrations, `docs/adr/0017-identity-verification.md`,
`docs/M24_KYC_KYB_REPORT.md`, ~12 test files.

**Modified — additive only:**

| File | Change |
|---|---|
| `Admin/Domain/Rbac/Permission.php` | +3 permissions, role map entries |
| `Admin/Domain/Enum/AdminRole.php` | +`ComplianceOfficer` |
| `Marketplace/Domain/Vendor/Vendor.php` | `canTrade()` also requires KYB |
| `Marketplace/Domain/Rider/Rider.php` | verification status + eligibility |
| `Marketplace/Application/Service/{Delivery,Rider}Service.php` | assignment gate |
| `Commerce/Domain/Store/Store.php` | new `canTrade()` |
| `Commerce/Application/Service/CheckoutService.php` | **enforce store verification (gap fix)** |
| `Identity/Domain/User/User.php` | phone verification + level |
| `Identity/Interface/Http/routes.php` | phone verify/confirm endpoints |
| `bootstrap/providers.php` | register the module |
| `bootstrap/app.php` | new error-code → status mappings |
| `.env.example`, `openapi.yaml`, `tests/Pest.php` | documentation and wiring |

**Untouched:** every M23 financial file except by reuse. No Payments service,
repository, migration or test is modified.

---

## M. Risks and mitigations

| # | Risk | Mitigation |
|---|---|---|
| 1 | **Didit field names unverified** — docs egress-blocked here | Adapter isolated to one file; mapping table externalised to config where cheap; `MockProvider` means the suite never needs the real API; verify against live docs at implementation time and record any correction in the M24 report |
| 2 | **Turning on activation gates locks out existing merchants/riders** | Projection columns default to `not_started`, and enforcement ships behind a config flag (`verification.enforcement.enabled`, default **off**) so the schema and code deploy first and the gate is switched on deliberately after back-filling. This is the single highest-risk item in M24 |
| 3 | Regulated data in a new context | No raw documents by schema; encryption; redacting logger; audited access; retention purge |
| 4 | Lost webhook strands a subject | `verification:reconcile` polling command |
| 5 | Provider outage blocks onboarding | Cases sit in `PENDING` and retry; `ManualReviewProvider` fallback; no auto-approve on unknown status |
| 6 | Cross-context coupling creeping in | Only the published `VerificationStatusQuery` contract and domain events; projections owned by consumers; enforced by review |
| 7 | Touching M23 RBAC files | Additive only; the M23 escalation tests must stay green and are extended, not modified |
| 8 | SuperAdmin sees everything | Accepted (it is the definition of the role) but every PII read is audited, so access is visible |
| 9 | PostgreSQL-only behaviours (trigger, constraints) | Same approach M23 proved: driver-guarded, explicitly skipped on SQLite rather than faked, verified on the production engine |
| 10 | Scope creep into M27/M32 | Payout verification is a **hook column only**; admin work is backend endpoints only, no Command Centre UI |

---

## Proposed sequence

1. Verification module skeleton, enums, state machine, migrations
2. Provider abstraction + `MockProvider` + registry
3. `VerificationService`, case lifecycle, audit events
4. Didit adapter + webhook endpoint + reconciliation
5. Business KYB (profiles, representatives, CAC via manual provider)
6. Progressive customer verification + phone verification + step-up middleware
7. Consumer projections + activation gates (behind the enforcement flag)
8. Admin review endpoints + RBAC permissions
9. Full test suite, both engines, PHPStan, Pint, concurrency regression
10. `docs/M24_KYC_KYB_REPORT.md`

---

**Awaiting approval before any implementation.**
