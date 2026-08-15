# M26 — Dispatch, Vehicles & Rider Assignment — Completion Report

**Branch:** `claude/m26-dispatch-vehicles` (from `beb5c098` — the certified M25 baseline)
**Status:** implementation and validation complete; **not committed, not pushed, not merged**
**Engine state:** `dispatch.engine.enabled=false` — ships OFF

---

## A. Files changed

| File | Change |
|---|---|
| `apps/api/bootstrap/app.php` | Registered 7 Dispatch error codes in the HTTP status map |
| `apps/api/bootstrap/providers.php` | Registered `DispatchServiceProvider` |
| `apps/api/composer.json` | `EruoFood\Dispatch\` PSR-4 autoload entry (one line) |
| `apps/api/tests/Pest.php` | Dispatch feature suite boots the framework |
| `apps/api/.env.example` | 39 documented `DISPATCH_*` variables |
| `apps/api/modules/Admin/src/Domain/Rbac/Permission.php` | `dispatch.read` / `dispatch.manage` + role grants |
| `apps/api/modules/Marketplace/src/Domain/Enum/DeliveryStatus.php` | 4 new states, 2 legacy aliases retained |
| `apps/api/modules/Marketplace/src/Domain/Delivery/Delivery.php` | Ordinal table → explicit transition table; `acceptedByRider()`, `markOffered()`, `canAdvanceTo()` |
| `apps/api/modules/Marketplace/…/EloquentDeliveryRepository.php` | Optimistic version save |
| `apps/api/modules/Marketplace/…/Model/DeliveryModel.php` | `version` property annotation |
| `packages/api-contracts/openapi.yaml` | 20 Dispatch paths, 8 schemas, 2 tags (+796 lines) |

**Nothing in M23, M24 or M25 was rewritten or weakened.** The Marketplace changes are additive; the one behavioural risk is documented in §R.

## B. New files

**119 PHP files** under `apps/api/modules/Dispatch/`, plus:

- `apps/api/config/dispatch.php` — every operational lever
- `apps/api/scripts/dispatch_concurrency_validation.php` + `_worker.php`
- `packages/api-contracts/generated/ts/schema.d.ts` — regenerated types

Structure: `Domain/` (aggregates, 8 enums, 11 eligibility rules, 8 exceptions, 6 events, scoring) · `Application/` (9 services, 7 ports) · `Infrastructure/` (persistence, adapters to Geo/Reviews/Marketplace, notification subscriber, provider) · `Interface/` (3 controllers, presenter, routes, 2 console commands) · `tests/` (10 files).

## C. Database migrations

| # | Migration | What it adds |
|---|---|---|
| 1 | `create_dispatch_vehicles_table` | 6 CHECK constraints; partial unique on active registration and one-primary-per-rider |
| 2 | `create_dispatch_vehicle_backfill_log_table` | The backfill's receipt and undo list |
| 3 | `backfill_vehicles_from_marketplace_riders` | Reversible, counted, auditable, non-destructive |
| 4 | `create_dispatch_requests_table` | **One live search per delivery** (partial unique) + 6 CHECKs |
| 5 | `create_dispatch_attempts_table` | Append-only trigger (PostgreSQL), jsonb rejection breakdown |
| 6 | `create_dispatch_offers_table` | One live offer per rider; one offer per rider per request |
| 7 | `create_dispatch_assignments_table` | **One active assignment per delivery; one per rider** |
| 8 | `add_version_to_marketplace_deliveries` | Optimistic locking for the second writer M26 introduces |

`migrate → rollback (8) → re-migrate (8)` clean on PostgreSQL 16; 0 pending after.

**Backfill counts against the dev database (219 riders):** 216 vehicles created (all `pending_verification`, never auto-approved), 3 left dispatch-ineligible (`foot`, unrecognised, empty), 5 flagged for manual review, **0 rider rows deleted or modified**.

## D. Delivery lifecycle

```
OFFERED → ACCEPTED → EN_ROUTE_PICKUP → ARRIVED_PICKUP
        → PICKED_UP → IN_TRANSIT → DELIVERED   (terminal)
```

The pre-M26 `Delivery` used a `+1` ordinal table whose `en_route` sat *after* `picked_up` — the opposite of the obvious reading. It is now an explicit table, which cannot be wrong quietly.

**Ownership (decision 1, option b).** Marketplace's `Delivery` remains the operational aggregate. `DeliveryProgressService` is the single entry point and its order is fixed: **Marketplace advances first, Dispatch mirrors second**, both in one transaction. No code path moves the assignment without the delivery having agreed.

**Legacy names kept, not rewritten.** `assigned` and `en_route` are the pre-M26 names for `accepted` and `in_transit`. Existing rows hold those values and still advance normally — a migration that rewrote live delivery rows to tidy an enum would be changing operational records for cosmetics. The shipped vendor-assign endpoint still returns `assigned`.

## E. Vehicle architecture

BIKE / TRICYCLE / CAR / BUS. **No FOOT.** Three separate fields because they fail independently: `status` (usable now), `verification_state` (has a human checked), document expiry (still current). `isDispatchable()` evaluates expiry **against the clock**, so a policy lapsing at midnight removes the vehicle at midnight — not whenever a sweep next runs.

A rider may describe a vehicle; only an operator may approve it. Editing documents on a verified vehicle sends it back to pending, so a rider cannot extend their own insurance date.

## F. Dispatch architecture

`DispatchRequest` (the search) + `DispatchAttempt` (append-only record of each round) + `RiderOffer` + `Assignment`. `DispatchEngine` runs discover → score → offer; it never assigns. `dispatch.engine.enabled` ships false; manual vendor assignment keeps working either way, so rollback is a config change.

## G. Eligibility

11 rules, run **before** scoring so no ineligible rider costs a routing call. Three are **mandatory and cannot be switched off** — identity verification (M24's `blocksSubject`, not `isVerified`, so M24's own rollout switch is respected), vehicle verification, document currency. `EligibilityService` filters by `isMandatory()` first, so a config key naming one does nothing.

Each rejection is **named and counted once**, under the rider's first objection. "Eleven riders nearby: nine stale location, two expired insurance" is a next action; "no eligible riders" is not.

## H. Candidate discovery

`GeoCandidateSource` consumes M25 — `RiderLocationService::nearby()`, the bounding box, the haversine pass, the staleness cutoff. **Nothing is re-derived.** Four batched reads, never per-rider. Radius starts at 3 km and widens only while under the pool floor, with three independent stops (ceiling, floor, iteration guard) so a misconfigured expansion factor cannot loop.

## I. Scoring and fairness

Seven factors normalised 0–1, weights from config, **every score stored with its breakdown**. Routed ETA comes from M25's `DeliveryDistanceProvider`; on provider failure the ETA factor is dropped and its weight redistributed rather than scored zero — an outage degrades ranking, it does not stop dispatch. Missing rating scores **neutral (0.5), never zero**, so a new rider can get a first delivery.

**Fairness is bounded structurally.** `FairnessPolicy::boundedBy()` clamps `max_penalty + idle_boost` to the proximity weight. This was found by a test, not by review — see §Q.

## J. Assignment and concurrency guarantees

Four layers, four different jobs:

1. `SELECT … FOR UPDATE` on the request, **always locked first** (consistent order prevents deadlock)
2. Request state re-read under that lock
3. Optimistic `version` on offer, request, assignment, vehicle, delivery
4. **Partial unique indexes** — the last line, and the only one that holds when a refactor forgets the lock

**Eligibility is re-checked inside the lock** (requirement 5), using a narrower chain: mandatory rules + suspension + vehicle suitability. Fairness and availability are excluded — refusing a rider at the moment they tap Accept for a fairness reason would refuse them for something unrelated to them.

Acceptance is **idempotent** via `IdempotencyStore`, keyed on offer + rider.

## K. Reassignment

Opens a **new** request rather than reopening the old one — reopening would erase what was tried and break "one live search per delivery". The replacement **inherits what is left of the customer's original deadline**, not a fresh grant. Below 60 s remaining, no replacement is opened and the delivery is flagged for a human. Refused once the rider has the food.

## L. Notifications

Through M24's `NotificationService`, category `Delivery`. No second notification system. Five templates. **No coordinates and no address in any payload** — a notification fans out to channels the platform does not control. Failures never fail the operation; the guard is around construction *and* handling (§Q).

## M. Rider APIs

`/api/v1/dispatch/*` — 12 endpoints. **No endpoint accepts a rider id, a delivery to claim, or a coordinate.** Self-assignment is unexpressible rather than forbidden. Ownership is checked against the rider record, and refusals are identical whether a thing belongs to somebody else or does not exist.

## N. Control Centre APIs

`/api/v1/admin/dispatch/*` — 7 reads under `dispatch.read`, 6 writes under `dispatch.manage`. Backend only; **no UI**. Queue, active assignments, failures, per-request history with rejection breakdowns and score internals, availability, health, vehicle queue. Manual assign, cancel, force-reassign, vehicle approve/reject/suspend.

## O. RBAC and security

`dispatch.read` and `dispatch.manage` are separate. Support gets read only; operations gets both; vendor managers get read. **Every privileged action requires a stated reason and writes to the append-only audit log before the response returns.** No dispatch endpoint returns a coordinate.

## P. Testing and validation results

| Check | Result |
|---|---|
| Pest — SQLite | **980 passed**, 16 PG-only skipped |
| Pest — PostgreSQL 16 + Redis | **996 passed** (3,657 assertions) |
| M23 financial concurrency | **23/23 passed, 0 failed** |
| M26 dispatch concurrency ×4 | **23/23, 23/23, 23/23, 23/23 — 0 failed** |
| M23 / M24 / M25 regressions | included above, all green |
| Security / IDOR, RBAC, notifications, state machine | 30 / included / 5 / 13 — all pass |
| migrate → rollback → re-migrate | 8 down, 8 up, 0 pending |
| PHPStan L8 | **0 errors** |
| Pint | passed |
| Redocly lint | **0 errors** (477 → 503 warnings, all the pre-existing `operationId` style) |
| OpenAPI type generation | succeeded |

### M26 concurrency scenarios (all four runs identical)

```
1) Two riders accept simultaneously        1 succeeded, 1 refused, 1 assignment
2) Ten riders accept simultaneously        1 succeeded, 9 refused, 1 rider holds it
3) One rider taps five times               exactly 1 assignment (idempotent)
4) Rider exclusivity + raw-insert bypass   1 active assignment; DB stopped the bypass
5) Accept races the expiry sweep           one terminal state, truthful answer to rider
6) Two operators approve one vehicle       1 commit, version advanced exactly once
7) Two processes reassign one rider        1 live search, never two
```

Every loser exited as a domain refusal, never a crash. **0 worker errors across all runs.**

## Q. False-positive audit

Each protection removed one at a time; the relevant suite re-run.

| # | Protection removed | Result |
|---|---|---|
| 1 | one-active-assignment-per-delivery index | **1 failed** |
| 2 | one-active-assignment-per-rider index | **1 failed** |
| 3 | one-live-search-per-delivery index | **1 failed** |
| 4 | one-live-offer-per-rider index | **1 failed** |
| 5 | optimistic version check (vehicles) | **1 failed** |
| 6 | optimistic version check (requests) | **1 failed** |
| 7 | eligibility re-check inside the lock | **2 failed** |
| 8 | offer ownership check | **2 failed** |
| 9 | idempotency on accept | **1 failed** |
| 10 | rider identity verification (M24) | **2 failed** |
| 11 | vehicle verification requirement | **2 failed** |
| 12 | document expiry check on the clock | **3 failed** |
| 13 | illegal delivery transitions | **3 failed** |
| 14 | mandatory rules cannot be disabled | **1 failed** |
| 15 | `dispatch.manage` separated from `.read` | **1 failed** |
| 16 | admin RBAC gate | **6 failed** |
| 17 | audit trail on privileged actions | **1 failed** |
| 18 | rider-drivable validation rule (HTTP) | **3 failed** *(after fix)* |
| 18a | rider-drivable whitelist (domain) | **2 failed** *(after fix)* |
| 19 | no coordinates in notifications | **1 failed** |

### Three real defects found by tests during M26

**1. `markExpired()` produced a state PostgreSQL refuses.** It set `verification_state='expired'` but left `status='active'`, violating `dispatch_vehicles_active_requires_verified`. SQLite would have stored it; the vehicle would have read as *in service* in every operator list while dispatch silently refused it.

**2. Fairness could override distance entirely.** The docblock promised fairness "can never send a delivery to somebody twelve kilometres away when one is five hundred metres away". The test encoding that promise **failed**: with penalty 0.30 + boost 0.10 against a proximity weight of 0.30, the 12 km idle rider beat the 500 m busy one by 0.004. Fixed structurally via `FairnessPolicy::boundedBy()`, not by tuning numbers.

**3. Notification failures could fail an acceptance.** The try/catch was inside the handler, but the container resolves `NotificationService` at *construction* — outside it. A broken notifier would have rolled back a rider's acceptance. The guard now wraps construction too.

### One weak test found by the audit itself

Audit #18 initially **did not fail** with the protection removed. Investigation showed the test asserted only HTTP 422, and three independent layers all answer 422 — so it could not tell which was working. Removing *both* intended layers still passed, because Marketplace has no `cancelled` delivery status and refused for a third, incidental reason. The test now asserts `assertJsonValidationErrors('state')` (only the validation rule produces that), and a separate direct test asserts the domain whitelist excludes states the transition table *does* allow. Both layers now fail independently.

No test was weakened, skipped or deleted to obtain a green result.

## R. Risks and remaining limitations

1. **`marketplace_deliveries.save()` is now optimistically locked.** Any caller holding a stale delivery gets `CONCURRENCY_CONFLICT` (409) where it previously silently overwrote. Correct, but a live behaviour change on an existing path — the full Marketplace suite passes.
2. **`GeoServiceAreaCheck` fails open** (no zones drawn, or zone service error). Deliberate: it is an optional rule, and refusing every rider because nobody drew a polygon would take dispatch offline. No mandatory rule fails open.
3. **`completion_rate` is always null.** Dispatch does not yet track completion; the scoring factor is neutral. M29 owns the full performance engine.
4. **Zone affinity is a proximity proxy.** A real "zones this rider usually works" signal needs history M29 will own.
5. **Manual assignment does not re-check eligibility.** Deliberate — that is what makes it an override. The audit entry is the accountability.
6. **The engine has no worker/scheduler wiring.** `DispatchEngine::attempt()` is callable and tested; nothing schedules it, because the switch is off. Turning the engine on requires adding that.
7. **Backfilled `car`/`tricycle` vehicles have no registration number** and cannot pass verification until the rider supplies one. Flagged `needs_manual_review`.
8. **PostGIS remains deferred** (M25 decision, unchanged).

## S. M27 has not started

No payment orchestration, split settlement, Payment on Delivery, refunds, disputes or revenue engine. No M29 rating engine (Reviews is consumed read-only through its existing repository). No M30 AI, M31 catalogue, M32 Control Centre UI. No full live tracking — rider positions are read through M25 only, and no location-history table was created.

## T. Git status

Branch `claude/m26-dispatch-vehicles`. **Nothing committed, nothing pushed, nothing merged. `main` untouched.**

```
 M apps/api/.env.example
 M apps/api/bootstrap/app.php
 M apps/api/bootstrap/providers.php
 M apps/api/composer.json
 M apps/api/modules/Admin/src/Domain/Rbac/Permission.php
 M apps/api/modules/Marketplace/src/Domain/Delivery/Delivery.php
 M apps/api/modules/Marketplace/src/Domain/Enum/DeliveryStatus.php
 M apps/api/modules/Marketplace/…/EloquentDeliveryRepository.php
 M apps/api/modules/Marketplace/…/Model/DeliveryModel.php
 M apps/api/tests/Pest.php
 M packages/api-contracts/openapi.yaml
?? apps/api/config/dispatch.php
?? apps/api/modules/Dispatch/
?? apps/api/scripts/dispatch_concurrency_validation.php
?? apps/api/scripts/dispatch_concurrency_worker.php
?? packages/api-contracts/generated/ts/
```

**Awaiting explicit approval before any commit.**
