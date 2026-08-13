# M24 — KYC / KYB & Identity Verification

**Branch:** `claude/m24-kyc-kyb` (based on `65feb36` — the approved M23 baseline)
**Status:** implemented and validated; **not committed, not pushed, not merged**

---

## 1. What this milestone is

EruoFood needed to know who its riders are, that its merchants are real
businesses, and that a customer performing something sensitive is who they claim
to be — without any of that becoming a barrier to signing up or ordering.

Three decisions shape everything else:

**Verification is provider-abstracted, not Didit-shaped.** Didit is the initial
identity provider and Nigeria's CAC the initial business registry, but neither
name appears in the domain. A new provider or a new market is an adapter plus a
configuration entry.

**Recording verification and enforcing it are separate switches.** The platform
already has merchants and riders who have never been verified. A requirement
that takes effect on deploy would delist all of them at once. So enforcement
ships **off**, and can be switched on per population after that population has
had a chance to verify.

**Privileged access to identity data is visible, not merely permitted.** A
SuperAdmin keeps the highest permission — that is what the role means. What they
do not keep is the ability to look unobserved.

---

## 2. The verification state machine

```
NOT_STARTED ──▶ PENDING ──▶ PROCESSING ──▶ VERIFIED
                   │            │             │
                   ├────────────┴──▶ REQUIRES_REVIEW
                   │                      │
                   ├──▶ REJECTED ◀────────┤
                   └──▶ EXPIRED  ◀────────┴──▶ REVERIFICATION_REQUIRED
                                                        │
        REJECTED / EXPIRED / REVERIFICATION_REQUIRED ───┘──▶ PENDING (retry)
```

The transition table is asserted exhaustively rather than by example
(`VerificationStateMachineTest`), because an accidental widening is exactly the
change that would silently let a rejected rider become verified.

The property that matters most: **a verified case may only decay.** It can
expire or be sent back for reverification; it can never be rejected. A late or
replayed "declined" callback therefore cannot strip somebody's verification.

---

## 3. Provider architecture

Three distinct concepts, deliberately not collapsed into one:

| Concept | Port | Adapters |
|---|---|---|
| Identity verification | `IdentityVerificationProvider` | `DiditProvider`, `MockProvider`, `ManualReviewProvider` |
| Business registry | `BusinessRegistryProvider` | `CacRegistryProvider` (NG), `MockRegistryProvider` |
| Human fallback | `ManualReviewProvider` | routes to the review queue, decides nothing itself |

**CAC is a real registry adapter, not a manual-review placeholder.** It knows
Nigeria's three registration series (RC = company, BN = business name,
IT = incorporated trustees), and compares a claimed name against the registered
one with suffix normalisation, so "Mama Put Kitchen Ltd" on a signup form
matches "MAMA PUT KITCHEN LIMITED" on the certificate.

The adapter reports four outcomes separately — found, active, name-matched,
needs-review — because "no such company" and "a real company under a different
name" are materially different things to a reviewer.

**Nothing is ever assumed valid.** With no CAC API configured (today's state),
the adapter routes to human review. So does a registry timeout. Neither is
treated as a pass.

**No market inherits Nigeria's registry.** `forCountry('GH')` returns `null`,
which forces the caller to route to manual review rather than silently applying
CAC rules to a Ghanaian business.

### Didit integration

Everything Didit-specific lives in three files under
`Infrastructure/Provider/Didit/`. The domain sees only provider-neutral DTOs.

- Credentials, workflow ids, endpoints, timeouts, retries and the webhook secret
  all come from configuration. **No secret is hard-coded.**
- The contract was validated against Didit's own runnable reference
  implementation (`didit-protocol/didit-full-demo`), not guessed. Two points
  that reference leaves ambiguous (the hosted-URL field name, and the JSON
  separator style used in one signature scheme) are handled by accepting both
  forms, isolated inside the adapter and documented there.
- Signatures are verified **before** the body is trusted as a result. Three
  schemes are supported; the one that actually proved a payload is recorded,
  because a sudden shift in scheme is itself a signal.
- An unknown status maps to `REQUIRES_REVIEW`, never to `VERIFIED`.

---

## 4. Security and privacy

### The webhook endpoint

Unauthenticated by necessity — the provider's signature is the authentication.
Every property that keeps it safe is tested:

- a forged signature changes nothing;
- an unsigned callback is refused;
- a callback outside the replay-tolerance window is refused;
- a valid signature over an **unknown session reference** is refused (a
  signature proves origin, not that the session belongs to a case we opened);
- redelivery is a no-op — the event id is claimed before the decision is
  applied, so a provider retrying four times applies once;
- all four refusals return an **identical bare 401**, because an endpoint that
  explains why it rejected a forgery is a tool for refining the forgery;
- the payload never reaches the application log. A `body_sha256` digest is kept
  so repeat forgeries can be correlated without the payload itself being stored.

### What is stored

There is **no document image, file path or blob anywhere** in this context —
asserted against the schema so a later migration cannot reintroduce one quietly.
The provider holds the artefact; the platform holds a reference to their session.

Even the document number is reduced to its **last four characters**, encrypted at
rest. The full number never enters storage: `DocumentMetadata::lastFourOf()` is
the only way a number gets in.

### Who may see what

| Role | `verification.read` | `verification.review` | `verification.pii` |
|---|---|---|---|
| SuperAdmin | ✔ | ✔ | ✔ |
| ComplianceOfficer | ✔ | ✔ | ✔ |
| Admin | ✔ | ✔ | ✘ |
| OperationsManager | ✔ | ✘ | ✘ |

Seeing *that* a case is waiting and seeing *what is inside it* are different
powers. A general administrator can clear a verification backlog — the ordinary
daily job — without ever opening a rider's licence.

### The audit trail

Every read of regulated identity data writes an immutable audit event carrying
**actor, subject case, timestamp, permission, action, stated reason, result, and
the request correlation id**. This holds:

- for **granted and denied** attempts alike — auditing only successes would let
  someone probe the boundary silently;
- for **every role including SuperAdmin**;
- even if a future caller bypasses the route middleware, because the service
  audits the refusal it raises itself.

The trail records *that* somebody looked and on what authority. It never records
what they saw — copying the data into the audit log would simply create a second,
longer-lived copy of the thing being protected.

`admin_audit_log` is now **append-only in PostgreSQL**. A privileged reader who
could delete the row proving they looked has not been audited at all.

---

## 5. Enforcement — and why nobody gets locked out

`verification.enforcement` has a master switch plus per-population overrides:

```php
'enabled'      => false,   // master
'riders'       => null,    // null = follow master
'restaurants'  => null,
'groceries'    => null,
```

`blocksSubject()` returns true only when a subject is unverified **and**
enforcement applies to its population. Verification status accumulates and is
reviewable from the day the code ships; nothing is blocked until a switch is
deliberately thrown.

Once enabled, the gates are:

| Population | Gate | Where |
|---|---|---|
| Restaurants | order checkout | `Marketplace\CheckoutService` |
| Groceries | order checkout | `Commerce\CheckoutService` |
| Riders | going on-shift | `Marketplace\RiderService` |
| Riders | delivery assignment | `Marketplace\DeliveryService` |

Rider verification is checked **at dispatch**, not at onboarding, so a rider
whose verification lapses or is revoked stops receiving work without anyone
having to remember to unassign them. Going *offline* is never blocked — a rider
must not be trapped on-shift by a lapsed document.

Consuming modules read their own local projection column, updated by a domain
event, so no hot path queries across a context boundary.

> **Note:** Commerce checkout previously never consulted store standing at all —
> only product *publishing* did — so a suspended store kept taking orders. M24
> closes that pre-existing gap.

---

## 6. Progressive verification

A customer is **never** forced through KYC to register or to order. Every account
starts at `basic` and stays there until an operation needs more.

| Level | Earned by |
|---|---|
| `basic` | registration — nothing required |
| `phone` | confirming a number (seconds, free) |
| `identity` | a verified document check |

A stronger level satisfies any weaker requirement, so somebody who completed full
KYC is never asked to also confirm a phone.

**Phone confirmation.** The code is hashed before storage and compared by hash, so
a leaked row cannot complete somebody's verification. Attempts are counted on the
row rather than in a cache, because a rate limit that resets when the cache is
flushed is not a rate limit. Re-requesting replaces the outstanding code rather
than adding a second valid one.

**Step-up.** Which operations demand what lives in configuration, not code — a
transfer threshold is an operational judgement that changes with fraud patterns
and should not need a deploy. Payments asks the published `StepUpGuard` whether
an operation is gated; it does not decide. A refusal is a **403 carrying the
required level**, so a client can send the user to the right flow rather than
showing a dead end.

A trigger nobody configured demands nothing — a caller naming an unknown trigger
must not silently lock a customer out of an operation the platform never decided
to gate.

**Step-up also ships off** (`VERIFICATION_STEP_UP_ENABLED=false`), for the same
reason enforcement does: these triggers gate operations existing customers
already perform.

---

## 6a. Notification foundation

M24 also lays the platform's reusable notification foundation, because a
verification system that decides things silently is not usable: a rider whose
KYC was declined needs to be told, and told in a way that does not put their
identity data in an inbox.

**This extends the existing Notifications context rather than adding a second
one.** That context already owned the event-driven abstraction, templates,
preferences, delivery status, retry and quiet hours. What it did not have was an
email channel that actually sends anything, and the consent and provenance
machinery around it.

### The architecture

```
domain event ──▶ EventTranslator ──▶ NotificationService
                 (config map,          (consent, preferences,
                  field allow-list)     quiet hours, retry)
                                              │
                                     ChannelDispatcher
                                              │
                        ┌─────────────────────┼──────────────┐
                     Email                  In-app        SMS / push
                        │                                (ports exist,
                  EmailProvider                           not built)
                  (mailer | log)
                        │
                    Recipient
              (resolved from Identity)
```

The channel owns *what an email is* — addressing, layout, unsubscribe headers.
The provider beneath it owns only transmission. That split is what lets the ESP
change without touching notification logic, and lets the whole engine be
exercised in tests without sending anything.

Adding push or SMS later is an adapter behind `ChannelSender`; adding a second
ESP is an adapter behind `EmailProvider`. Neither reaches the domain.

### What was added

| Capability | How |
|---|---|
| Email provider abstraction | `EmailProvider` port; `MailerEmailProvider`, `LogEmailProvider` |
| Recipient resolution | `RecipientResolver` port; `IdentityRecipientResolver` (soft reference, explicit column list) |
| Centralised templates | `NotificationsSeeder` + `EmailBodyRenderer` (one layout, escaping at the single point of assembly) |
| Delivery status | existing status machine, now with `provider_message_id` |
| Retry handling | existing loop, now distinguishing transient from **permanent** failure |
| Correlation ID | `correlation_id` on every notification, set to the verification case id |
| Localisation readiness | templates keyed `(key, channel, locale)`; recipient carries a locale |
| Preferences | existing per-category channel preferences |
| Class: transactional / security / marketing | `NotificationClass`, derived from the category so nothing arrives unclassified |
| Marketing opt-in + unsubscribe | `marketing_opt_in` (default **false**), per-user unsubscribe token, public one-click endpoint, `List-Unsubscribe` on marketing only |
| Safe error handling | a failed send is recorded as a failed send; nothing escapes into the publishing operation |

### The KYC/KYB hooks

| Moment | Template | Channels |
|---|---|---|
| Verification submitted | `verification_submitted` | email, in-app |
| Verification processing | `verification_processing` | in-app only |
| Verification approved (customer) | `verification_approved` | email, in-app |
| Rider approved / activated | `rider_verification_approved` | email, in-app |
| Verification rejected | `verification_rejected` | email, in-app |
| Reverification required | `reverification_required` | email, in-app |
| Merchant KYB submitted | `kyb_submitted` | email, in-app |
| Merchant KYB approved / activated | `kyb_approved` | email, in-app |
| Merchant KYB rejected | `kyb_rejected` | email, in-app |

Rider and merchant *activation* are not separate messages: approval and
activation are the same moment for both, so each gets one message that says so
rather than two that overlap. One `subject_verified` event produces the right
message for each audience through conditional map entries.

A business case's subject is a vendor or store id, which nobody can be emailed
at. Cases therefore carry a `contact_user_id`, resolved once when the case opens
by asking Marketplace or Commerce who owns the business — no cross-context
lookup on the notification path, and no import either way.

### The privacy line

Three independent controls, because one would be a single point of failure:

1. **Events carry only safe fields.** The publisher decides what leaves the
   context, so nothing regulated is available to reflect over.
2. **Per-entry allow-lists.** Every KYC/KYB map entry declares `fields`; only
   those properties reach a template. A property added to an event later cannot
   start appearing in email.
3. **A standing deny-list.** Document numbers, registration numbers, provider
   references, dates of birth, phone numbers, tokens and secrets are stripped
   regardless of what the map says — the backstop behind the allow-list.

Emails carry status and a link. They never carry a document number, a rejection
reason code, a provider session reference, or a document type. Where somebody
needs to know *why*, the message sends them to the authenticated application —
an inbox may be shared, forwarded, or reached by whoever compromises the account
next, and a rejection reason is also a tip-off when the rejection was
fraud-related.

Verification mail is classified **security**, so it is never suppressed by a
marketing unsubscribe and never carries `List-Unsubscribe` headers. Somebody who
silenced campaigns has not asked to stop hearing that their verification failed.

No secret appears in any template, layout, or config default: ESP credentials
live in the `MAIL_*` environment, and the email driver defaults to `log` so a
local or CI environment cannot email a real customer.

### One behaviour change to existing functionality

A **promotional** admin broadcast now requires the recipient to have opted in.
Previously it went to everyone in the segment. The existing test asserting the
old behaviour was updated, and two tests added covering opt-in and unsubscribe.
Transactional and security broadcasts are unaffected.

---

## 7. Reliability

**Reconciliation.** Webhooks are the fast path, not a guarantee. Any case still
awaiting a decision past `VERIFICATION_RECONCILE_AFTER_MINUTES` is polled
directly. Both paths funnel through the same `applyDecision()`, so a reconciled
result cannot be interpreted differently from a pushed one. A provider outage is
counted as a failure, never read as a verdict in either direction.

**Expiry.** A sweep expires verifications whose validity has run out, and
consumers treat expiry exactly like a loss of verification — which is what makes
expiry real rather than a column nobody acts on.

Both run from `php artisan verification:reconcile`.

**Concurrency.** M24 reuses M23's primitives rather than introducing rivals: the
same `TransactionManager`, the same claim-first exactly-once webhook pattern, the
same optimistic `version` guard, the same locking reads. Decisions are applied
under a row lock, so a reviewer and an arriving webhook cannot overwrite each
other. External calls are never made inside a transaction.

---

## 8. Production activation procedure

Enforcement is off on deploy. Nothing below is required for the release to be
safe; it is the sequence for turning the gates on afterwards.

### Step 1 — Deploy (no behaviour change)

```bash
php artisan migrate --force
```

Applies 14 migrations. Existing rows are unaffected: every new status column
defaults to "not started", `identity_users.verification_level` defaults to
`basic` — which is exactly what ordinary registration already produced.

Verify nothing changed:

```bash
php artisan tinker --execute="echo config('verification.enforcement.enabled') ? 'ON' : 'OFF';"   # expect OFF
php artisan tinker --execute="echo config('verification.step_up.enabled') ? 'ON' : 'OFF';"       # expect OFF
```

At this point verification is *available* and *recorded*. Nothing is *required*.

### Step 2 — Configure the provider

Set `DIDIT_API_KEY`, `DIDIT_WEBHOOK_SECRET`, the workflow ids and
`DIDIT_CALLBACK_URL` in the deployment's secret store, then:

```
VERIFICATION_IDENTITY_PROVIDER=didit
DIDIT_ENABLED=true
```

A blank webhook secret makes every callback fail closed — the safe direction, but
it means callbacks will not work until the secret is set.

Register the callback URL with Didit and confirm one end-to-end verification in
staging before continuing.

### Step 3 — Observation window

Announce to merchants and riders, open the flows, and let the population verify.
Track coverage:

```sql
-- Riders not yet verified
SELECT COUNT(*) FROM marketplace_riders WHERE kyc_status IS DISTINCT FROM 'verified';

-- Restaurants not yet verified
SELECT COUNT(*) FROM marketplace_vendors WHERE kyb_status IS DISTINCT FROM 'verified';

-- Grocery stores not yet verified
SELECT COUNT(*) FROM commerce_stores WHERE kyb_status IS DISTINCT FROM 'verified';
```

Schedule reconciliation so lost webhooks do not strand anyone:

```
*/15 * * * *  php artisan verification:reconcile
```

Work the review queue at `/api/v1/verification/admin/queue` until it is clear —
manual approval exists precisely so a legitimate business held up by a registry
outage can be let through by a human.

### Step 4 — Enable enforcement, one population at a time

**Do not** flip the master switch first. Enable the smallest, best-covered
population and watch it.

```
# Riders first — the smallest population and the highest-risk one.
VERIFICATION_ENFORCE_RIDERS=true
```

Deploy the config change, then watch for riders unable to go on-shift and
deliveries failing to assign. Give it at least one full operating day.

```
# Then groceries, then restaurants.
VERIFICATION_ENFORCE_GROCERIES=true
VERIFICATION_ENFORCE_RESTAURANTS=true
```

Only once all three are on and stable:

```
VERIFICATION_ENFORCEMENT_ENABLED=true
```

(At that point the per-population overrides are redundant and may be cleared.)

### Step 5 — Step-up, separately and later

Step-up gates operations existing customers already perform, so it deserves its
own window after identity verification is widely held:

```
VERIFICATION_STEP_UP_ENABLED=true
STEP_UP_WALLET_TRANSFER_MINOR=500000
```

### Rollback

Every gate is configuration, not code. Setting the relevant flag back to `false`
and redeploying the config restores the previous behaviour immediately — no
migration is reversed and no data is lost. **This is the intended way to react to
an unexpected lockout.** Do not roll back migrations to disable enforcement.

---

## 9. Known limitations

1. **CAC has no API contract yet.** Until `CAC_API_BASE_URL` is set, every
   Nigerian KYB lookup routes to human review. This is correct behaviour rather
   than a defect, but it means KYB throughput is bounded by reviewer capacity
   until the integration exists.

2. **Two Didit response details are inferred**, both isolated in the adapter and
   documented at their site: the hosted-URL field name (`url` vs `session_url` —
   both accepted) and the JSON separator style in one signature scheme (both
   computed and compared). Confirm against Didit's live sandbox before
   production traffic.

3. **Several step-up triggers are configured but not yet wired.** Only
   `wallet.transfer` is enforced. The `account.*` triggers (email, password and
   phone change) belong to Identity and would demand phone confirmation from the
   entire existing user base at once; they are deliberately left declared and
   inactive rather than switched on as a side effect of this milestone.

4. **True concurrency is validated by script, not by the Pest suite.**
   `RefreshDatabase` wraps each test in a transaction, so a second connection
   cannot see the first's rows. The M23 concurrency script covers the shared
   primitives (23/23 passing with M24 wired in); M24's own locking is exercised
   through those same primitives.

5. **SMS delivery is a null sender.** `NullPhoneVerificationSender` logs rather
   than sends. A real gateway adapter is a single class implementing
   `PhoneVerificationSender`.

6. **Email transmits nothing until a driver is configured.** The notification
   email driver defaults to `log`, deliberately: an environment that started
   emailing real customers because a default pointed at SMTP would be a worse
   failure than one that sends nothing. Set `NOTIFY_EMAIL_DRIVER=mailer` and the
   `MAIL_*` variables to go live.

7. **Only the email channel is built.** Push, SMS and WhatsApp senders remain
   the pre-existing logging stubs. The `ChannelSender` port is the seam; M24
   deliberately did not build them.

8. **Templates are seeded, not versioned.** Copy changes go through the admin
   template API or a re-run of the seeder. A template-versioning and preview
   workflow is not in scope here.

---

## 10. Defects found and fixed during validation

Five real defects surfaced while testing, four of which only PostgreSQL could
reveal:

1. **Document numbers were stored in plaintext** while the read path expected
   ciphertext — so the PII endpoint failed outright *and* the data was
   unencrypted at rest. Encryption now sits at the repository boundary, matching
   the business-profile pattern.

2. **Every Verification error code was unmapped**, falling through to HTTP 400 —
   an authorization failure reported as a bad request. All eight codes now map
   to 401/403/404/409/422/503.

3. **The PII audit event had no consumer.** It was published to the event bus
   and nothing wrote it down, so Refinement 2 was structurally unmet. The Admin
   audit map now ingests it, and the translator was extended to carry a real
   actor and subject rather than discarding both.

4. **`verification_events.actor_id` was typed `uuid`**, but system actors are
   names — `reconciliation`, `expiry-sweep`, a provider name. SQLite accepted
   them; PostgreSQL rejected them, meaning webhook application, reconciliation
   and the expiry sweep would all have crashed in production.

5. **`NOT_STARTED` did not occupy the single-open-case slot**, so the unique
   index had nothing to catch at exactly the moment cases are created: two taps
   on "verify" produced two competing cases.

Additionally, `openCase` now reuses a retryable closed case rather than orphaning
it — without which the documented `REJECTED → PENDING` transition was unreachable
through the public API and a subject accumulated one row per attempt round.

Every one of the five was fixed **before** the final validation run, and each now
has a named regression test in `VerificationDefectRegressionTest` so a
reappearance says which one broke.

Building the notification foundation surfaced two more, both fixed:

6. **`startVerification` never published its events.** Approve and reject went
   through `announce()`; submission did not, so nothing downstream — projection,
   notification or otherwise — could observe that a verification had been
   submitted at all.

7. **Delivery permanence was not persisted.** The flag marking a failure as
   permanent lived only in memory, so the retry loop — which reads notifications
   back out of the database — would have retried a dead address every cycle until
   the cap. It is now a column, and the retry query excludes it.

---

## 11. Validation results

| Check | Result |
|---|---|
| Full suite (SQLite) | **531 passed**, 4 skipped, 2188 assertions |
| Full suite (PostgreSQL 16) | **535 passed**, 2195 assertions |
| PHPStan level 8 | **0 errors** |
| Laravel Pint | **passed** |
| Migrations (PostgreSQL) | 17 applied, rolled back, re-applied cleanly |
| M23 financial concurrency script | **23/23 passed** (unchanged) |

The four SQLite skips are the PostgreSQL-only append-only trigger tests, which
declare that plainly rather than asserting a protection the test engine does not
provide. They run and pass on PostgreSQL.
