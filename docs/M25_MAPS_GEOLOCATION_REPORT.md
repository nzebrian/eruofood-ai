# M25 — Maps & Geolocation

Status: **implementation complete, awaiting approval**. Nothing is committed,
pushed or merged. Routed delivery pricing ships **off**.

---

## A. What this milestone is

The platform's geographic foundation: one canonical location record, one
distance formula, a provider-abstracted mapping integration, customer address
books, merchant trading locations, rider positions, delivery zones, and the
routing that makes a delivery fee honest.

It is deliberately *not* dispatch, rider assignment, vehicle matching, live
tracking, a dynamic pricing engine, payments, disputes, or a GCC user interface.
Those are later milestones and none of them is started here.

## B. The problem it was built to fix

An audit before implementation found four things worth naming:

1. **Two haversine implementations.** Marketplace used an Earth radius of
   6371.0; Search used 6371.0088. The same journey measured differently
   depending on which module was asked.
2. **Delivery fees billed on straight-line distance.** In Lagos the routed road
   distance commonly runs 1.3–1.6× the straight line. The fee was not merely
   imprecise — it understated the real journey, **in one direction, on every
   single order**.
3. **Rider coordinates with no timestamp.** `marketplace_riders` held latitude
   and longitude and nothing to say when. There was no way to tell a rider who
   moved five seconds ago from one who last reported five days ago.
4. **No customer address book.** Addresses were embedded as jsonb on each order,
   so a customer retyped theirs every time and the platform could not tell that
   two orders went to the same door.

## C. Architecture

`apps/api/modules/Geo/src/{Domain,Application,Infrastructure,Interface,Contracts}`,
following the platform's existing modular-monolith shape. Cross-context
communication is via the published `Contracts` namespace and domain events only.
There are no cross-context foreign keys — merchant tables carry a nullable
`primary_location_id` soft reference added by M25's own migration.

## D. PostGIS remains deferred

As approved. Coordinates are `decimal(10,7)` (~11 mm), with PostgreSQL CHECK
constraints on range and pairing. Proximity is a bounding-box prefilter over a
composite index plus an exact haversine pass in PHP — the pattern
`EloquentVendorRepository` already used successfully.

Every spatial read goes through a repository method (`withinRadius`, `nearby`,
`candidatesFor`). Adopting PostGIS later means rewriting those three methods and
nothing else: no domain object, service or controller would change.

## E. Provider abstraction

Google Maps is the initial provider and is **not** coupled to anything above the
infrastructure layer. Four capability ports — `GeocodingProvider`,
`RoutingProvider`, `DistanceMatrixProvider`, `PlacesProvider` — are resolved by
`ConfigGeoProviderRegistry` from a `capability → provider` table with
per-country overrides.

Swapping Google for a regional provider in one market is a configuration edit
plus one adapter class. Nothing above `Infrastructure/Provider/` names a mapping
vendor.

Factories are lazy closures, so configuring Google does not construct an HTTP
client and a deployment without credentials still boots.

## F. The Google adapter

`GoogleMapsProvider` implements all four ports over the Geocoding API and the
Routes API (`computeRoutes`, `computeRouteMatrix`) plus Places Autocomplete.

Two properties are security-relevant and both are tested:

- **The key never travels in a URL.** It goes in the `X-Goog-Api-Key` header.
  Query strings end up in access logs, proxy logs and exception traces.
- **No provider message is ever re-thrown.** Google's error bodies quote the
  request — and for a geocode the request *is* somebody's home address — and can
  name the quota project. Every failure becomes a platform exception carrying a
  normalised code and nothing else.

Cost control is built in: the field mask requests only distance, duration and
polyline, because the Routes API bills by response tier and legs/steps cost
materially more.

## G. No Google calls in the test suite

Enforced two ways, both asserted:

1. `config/geo.php` resolves every capability to `mock` when `APP_ENV=testing`,
   and a test asserts the registry returns `MockMapProvider` for all four.
2. A second test calls `Http::preventStrayRequests()` and asserts nothing is
   sent.

`.env.example` keeps every `GEO_*` and `GOOGLE_MAPS_*` line **commented out**,
because M24 established that an assignment there is an override, not a default —
a blank one silently defeated exactly this pattern and cost twelve CI failures.

## H. The mock provider

`MockMapProvider` is a complete offline provider, not a stub. It geocodes,
reverse-geocodes, routes, builds matrices, suggests places, and fails on demand:
"outage" makes it unavailable, "nowhere" not-found, "approximate" coarse, and
the ocean square unroutable.

Its road factor is **1.4, deliberately not 1.0** — a mock returning the straight
line would let a bug that bypasses routing entirely pass every test in the suite.

## I. Haversine is never a billing distance

One implementation, `Haversine`, with a documented radius of 6 371 008.8 m.
Marketplace's `GeoLocation` and Search's `GeoPoint` both delegate to it, and a
test asserts all three agree.

It is legitimate for bounding-box prefiltering, candidate selection, ranking,
and sanity-checking a routed result. It is **never** the distance a customer is
billed for. `RouteSource::Haversine->isBillable()` returns false, and
`Route::isBillable()` refuses it at any age, however fresh.

## J. The fallback chain

As approved, and implemented exactly:

```
fresh routed → stale cached routed → merchant flat fee → honest refusal
```

There is **no haversine rung**, at any position. Two standing tests fail if one
is ever added — a straight-line fee is available at every point in that chain
and is wrong at all of them, which is precisely why it must be unreachable.

The default when routing fails is refusal (`refuse_when_unavailable = true`).
The merchant flat-fee rung is only reachable when an operator turns that off,
and it charges the **lowest applicable published merchant zone fee** (approved),
so a customer is never charged more than an advertised price for a journey the
platform could not measure. **If the merchant has published no zone fee at all,
the quote is refused** rather than falling through to anything else.

No order is ever accepted at an uncertain price to reconcile later.

## K. Routed pricing is behind a switch, and it ships off

`config/delivery.php` → `routing_pricing.enabled`, default **false**.

`RoutedDeliveryFeeCalculator` *wraps* the pre-M25 `ZoneDeliveryFeeCalculator`
rather than replacing it. With the switch off it delegates every quote
unchanged, so deploying M25 changes nobody's bill. Rollback is a configuration
change: no deploy, no migration.

`shadow_mode` computes the routed distance alongside the old fee and records the
difference without charging it, so the size of the pricing change is knowable
from real orders before a single customer feels it.

Tests prove both modes, the rollback, and that the free-delivery threshold is
settled before any provider is consulted.

## L. Sanity ceiling on routed results

A routed distance more than `max_detour_ratio` (default 4×) times the straight
line is rejected and the chain continues. A provider returning a ferry route, a
wrong hemisphere or a mis-parsed field produces a number that is plausible in
type and absurd in value, and a bad number on a bill is worse than no number.

Journeys under 250 m are exempt: at that scale a one-way system legitimately
produces a 5× ratio.

This is haversine used *as a check on* a routed result — the opposite of billing
against it.

## M. Caching and cost control

Mapping APIs bill per request, so the failure mode of a looping client is an
invoice, not a crash — nobody notices until the month ends.

| Capability | TTL | Why |
|---|---|---|
| Geocode | 30 days | An address's coordinates do not change |
| Reverse geocode | 7 days | Same, with more input variation |
| Route | 24 hours | Roads change slowly |
| Route (traffic-aware) | **5 minutes** | Cached longer it stops being traffic-aware and becomes confidently wrong |
| Matrix | 1 hour | Asked about different points nearly every time |
| Autocomplete | 1 hour | The most expensive capability per useful outcome |

Coordinate rounding in cache keys is what makes a hit possible at all: two
orders from opposite ends of the same building share an answer.

`ProviderGuard` centralises quota → circuit breaker → timing → telemetry around
every billable call, in that order, so a new capability inherits all of it by
construction.

A cache failure is treated as a **miss**, never an error: the cache exists to
save money, and an unreachable Redis should make geocoding expensive, not break
checkout.

## N. Circuit breaker

Shared-cache state, so all web processes see one circuit — a per-process breaker
in a pool of twenty is twenty breakers.

A **not-found address does not count as a failure**. The provider worked; the
address does not exist. Counting it would let a run of typos open the circuit
and take geocoding down for everybody. Tested.

## O. Customer addresses

Full CRUD with geocoding on write, default management, and deactivation instead
of deletion (historical orders point at these rows).

**IDOR protection**: no endpoint takes a user identifier — the owner comes from
the token and nowhere else. Ownership is re-checked on the loaded row, and a
mismatch is reported as **404, not 403**, because a 403 confirms the identifier
is real. Five parameterised tests cover read and every mutation.

**Device position is not an address.** `device_latitude`/`device_longitude` bias
autocomplete and are never stored, never returned as an address, and never
become a destination. A test asserts that calling autocomplete creates no
address rows. This is how dinner ends up at the office somebody was standing
outside, and it is the distinction the whole service is built around.

A dropped pin outranks the geocode: somebody who moved the marker onto their
gate knows something the geocoder does not.

## P. Merchant locations and the M24 KYB seam

**Trading address ≠ registered address.** M24 collects the registered address
for KYB — the CAC filing, frequently an accountant's office or the owner's home.
This milestone manages the trading address. Only the trading address is ever
published.

On KYB approval, `KybLocationSubscriber` geocodes the registered address and
attaches it to the verification profile, finally populating the
latitude/longitude columns M24 created and could not fill. That location is
**private** and never becomes a public map pin — passing KYB is not consent to
publish somebody's home. Tested explicitly.

The listener **never fails an approval**: every error is caught, and an
unresolvable address is kept as an unresolved record rather than discarded.

## Q. Public vs private precision

`GeoPresenter` is the single place that decides how much of a location leaves
the building — per-controller shaping is how a private field reaches a public
endpoint one refactor later, and an address once published cannot be
unpublished.

- **Owner**: full precision, plus delivery instructions.
- **Public**: area and a point rounded to 3 dp (~110 m), with
  `precision_metres` stated so a client draws an honest circle rather than a
  falsely precise pin.
- **Withheld entirely** when a location is ungeocoded or disputed: a pin in the
  wrong place is worse than no pin.

## R. Rider locations

**One row per rider, overwritten — no movement history.** A trail is what live
tracking will need later; collecting one now, with nothing that reads it, is
over-collection. When history arrives it should arrive with a retention policy
attached.

**A rider writes only their own position**, checked against the rider record
rather than trusted from the path. **Going offline is a real delete** — there is
nothing to audit in a position a rider is entitled to stop sharing.

`RiderLocationUpdated` carries the rider id and a timestamp and **no
coordinates**: an event fans out to subscribers that have no authorisation
check.

A timestamp in the future is pulled back to now, so a wrong device clock cannot
make a position look permanently fresh.

**There is no endpoint listing where riders are.** A test asserts the absence.

## S. Delivery zones

Radius and polygon (GeoJSON `[lon, lat]`, ray-casting containment that handles
concave shapes). Bounding boxes are derived on save and cached for an indexed
prefilter.

Zones are consulted **lowest priority number first**, and a restricted zone
containing the point wins outright. That ordering is the whole design: consulted
the other way, the broad inclusion matches first, the exclusion never fires, and
the platform promises a delivery it cannot make. Tested.

A point in no zone is **not** serviceable — defaulting to yes would have the
platform accepting deliveries anywhere on Earth.

## T. Global Command Centre read surfaces

Provider cost and health (volume, cache hit rate, failure rate, latency, daily
quota), the live pricing mode, geocoding coverage as a backlog, and location
confirm/dispute.

Built entirely from a telemetry table that **records no coordinates and no
address text** — this surface is exported and graphed by people who have no
business knowing where a customer lives. A test asserts the queried address does
not appear anywhere in the table.

New permissions: `geo.read` (health and coverage) and `geo.manage` (changing a
location's verification status, which changes where riders are sent). Split
because they are different powers; a test asserts a role holding only `geo.read`
is refused the manage action.

## U. Search integration

Two forms of delegation:

1. **One distance formula** — `GeoPoint` and `GeoLocation` both delegate to
   `Haversine`.
2. **One source of coordinates** — `VendorSourceProvider` prefers the canonical
   `geo_locations` record over the legacy vendor columns, falling back when
   there is none. An ungeocoded or disputed record is skipped, because a result
   placed in the wrong street is worse than one with no distance.

Additive: a vendor predating M25 still indexes.

## V. Schema

Seven migrations: `geo_locations`, `geo_customer_addresses`,
`geo_rider_locations`, `geo_delivery_zones`, `geo_route_cache`,
`geo_provider_requests`, and additive nullable pointer columns on
`marketplace_vendors`, `commerce_stores` and `verification_business_profiles`.

PostgreSQL CHECK constraints enforce coordinate range, coordinate pairing
(half a coordinate is not a place), non-negative accuracy, and zone shape
presence. SQLite cannot add these via `ALTER TABLE`; those tests **skip with a
stated reason** rather than asserting a protection the test engine does not
provide.

## W. Error codes

Registered in `bootstrap/app.php`:

| Code | HTTP | Why |
|---|---|---|
| `GEO_ADDRESS_NOT_FOUND` | 404 | The caller's address to correct |
| `GEO_RESOURCE_NOT_FOUND` | 404 | Also returned for another user's resource |
| `GEO_NOT_AUTHORIZED` | 403 | |
| `GEO_INVALID_STATE`, `GEO_INVALID_COORDINATES` | 422 | |
| `GEO_QUOTA_EXCEEDED` | 429 | A throttle, not a fault — back off and retry |
| `GEO_PROVIDER_UNAVAILABLE` | 503 | Ours to absorb |
| `GEO_ROUTING_UNAVAILABLE` | 503 | The honest refusal — a 503, never a 200 with a guessed price |

## X. Defects found and fixed during implementation

**1. Route cache key exceeded its column (found by PostgreSQL only).**
`cache_key` was `varchar(64)` — exactly a sha256 — while the key carries a
`route:` prefix, making it 70 characters. SQLite truncates silently and every
test passed; PostgreSQL rejects the row. In production **every route store would
have failed**: a permanent 100% cache miss, a provider bill to match, and a
poisoned transaction for any request already inside one. Column widened;
regression test asserts the stored key round-trips at full length.

**2. The structure that hid it.** `RoutingService::attempt()` wrapped the cache
writes in the same `try` as the provider call, so the persistence failure was
caught and reported as "provider unavailable" — the bug impersonated a Google
outage. The `try` now covers only the provider call, so a persistence bug is
loud. Verified: with the narrow column restored, the failure surfaces at the
`store()` line instead of degrading into a plausible-looking outage.

**3. `owner_id` type mismatch (PostgreSQL only).** A test passed `'v1'` into a
`uuid` column; SQLite accepted it. Test corrected — the schema was right.

**4. Stale-by-wall-clock test.** A route-cache test used a fixed timestamp that
became stale after 3pm. Rewritten relative to now.

**5. Two PHPStan contract mismatches.** `DeliveryZone::polygon()` documented a
`list` while normalising any array (the normalisation was real and needed — a
gapped ring makes ray casting read past the end); the matrix port's
`array_values` was genuinely dead given its contract. Both resolved by
correcting the contract rather than the code, with a test for the gapped ring.

## Y. False-positive test audit

Following M24's lesson, the highest-stakes assertions were negative-controlled
by removing the protection and confirming the test fails:

| Protection removed | Result |
|---|---|
| Address ownership check | 5 tests fail |
| Rider ownership check | test fails |
| Routed-pricing default flipped to true | test fails |
| Route cache column narrowed to 64 | test fails |
| Constraint name changed in the assertion | test fails |
| API key moved into the URL | test fails |

Constraint tests assert the **specific constraint name** in the message rather
than a bare `QueryException`, which was M24's exact false-positive pattern.

One assertion is weaker by design and is flagged here rather than hidden: the
"no fleet map" test asserts a set of paths return 404/405. It proves those
routes do not exist; it cannot prove no such route could ever be added under a
different name.

## Z. Validation results

All run after Steps 6–12 were complete.

| Check | Result |
|---|---|
| Pest — SQLite | **736 passed**, 7 skipped (PostgreSQL-only constraints) |
| Pest — PostgreSQL 16 + Redis | **743 passed**, 0 skipped |
| M23 financial integrity (Payments, PostgreSQL) | **36 passed** |
| M24 regression (Verification, PostgreSQL) | **130 passed** |
| M25 Geo suite (PostgreSQL) | **208 passed** |
| M23 true-concurrency script (PostgreSQL 16, real OS processes) | **23/23 passed, 0 failed** |
| PHPStan level 8 | **No errors** (1694 files) |
| Pint | **Passed** |
| Redocly lint | **Valid — 0 errors** |
| OpenAPI type generation | **Succeeded** |
| Migrate → rollback → re-migrate (PG) | **130 → 7 → 7, clean** |

### The M23 concurrency validation

`apps/api/scripts/financial_concurrency_validation.php` and its worker
`financial_concurrency_worker.php` **do exist** — introduced in M23 commit
`65feb36`, and recorded as 23/23 in `docs/M23_FINANCIAL_INTEGRITY_REPORT.md`
and again in the M24 report.

**Correction to the previous M25 report.** It stated the script could not be
found. That was wrong: the search looked at the repository root rather than at
`apps/api/scripts/`. The script was there the whole time.

Executed against PostgreSQL 16.13 on a dedicated database with the full M25
schema migrated and all M25 code loaded. It launches real OS processes
synchronised on a shared start instant, so statements genuinely collide —
something the Pest suite structurally cannot do, because `RefreshDatabase`
wraps each test in a transaction that a second connection would never see.

```
1) Concurrent wallet debits            3/3   balance never negative
2) Concurrent transfers                3/3   total conserved at 20000
3) Concurrent refunds                  4/4   refunded exactly the capacity
4) Concurrent checkouts, one unit      3/3   exactly one order, no oversell
5) Concurrent reward redemptions       4/4   one voucher, no oversell
6) Duplicate webhook deliveries        3/3   applied exactly once
7) Shared idempotency key              2/2   guarded work ran once
8) Ledger integrity after all of it    1/1   double-entry still balances
                                      ─────
RESULT: 23 passed, 0 failed           (exit 0)
```

The script refuses to run against SQLite by design, because SQLite serialises
all writers and therefore cannot demonstrate row-level contention at all.

M25 introduces no financial code path, so this result is unchanged behaviour
rather than a new guarantee — but it confirms M25's schema additions and
service registration did not disturb M23's locking, and the M25
financial-integrity regression suite (36 passing) remains in place alongside it,
not replaced.

Redocly reports 477 warnings against a pre-existing baseline of 448. The 29
added are all `operation-operationId` and `operation-4xx-response`, matching the
convention used throughout the existing spec. Zero errors, which is what CI
gates on.

## AA. Known limitations

1. **`Commerce\Address` still defaults `country = 'NG'`.** Out of M25's scope;
   the Geo domain itself is country-neutral (`adminArea`/`subAdminArea`, optional
   postcode).
2. **Legacy vendor lat/lng columns remain.** Additive migration by design.
   Consolidation needs a backfill and belongs in its own change.
3. **`fee_minor`/`min_order_minor` on zones are stored but not authoritative.**
   M26 pricing will read them.
4. **The distance matrix has no consumer in M25.** Built because the Google
   adapter needed it and M26 dispatch will; currently exercised only by tests.
5. **Administrative zones match on address fields, not coordinates.** A point
   cannot tell you which LGA it is in.
6. **Rate limiting allows the request when the cache is unreachable.** Deliberate
   — the platform-wide daily quota is the backstop that still holds.
7. **No backfill job for existing merchant addresses.** They geocode when a
   merchant sets a trading address or their KYB is approved.

## AB. Activation procedure for routed pricing

1. Deploy with `DELIVERY_ROUTING_PRICING_ENABLED=false` (the default). Nothing
   changes for anybody.
2. Configure `GOOGLE_MAPS_SERVER_KEY` and set `GEO_*_PROVIDER=google`. Watch
   `/v1/geo/admin/provider-health` for cache hit rate and failure rate.
3. Set `DELIVERY_ROUTING_SHADOW_MODE=true`. Routed distances are measured and
   the fee difference is logged; customers still pay the old fee. Observe the
   real spread on real orders.
4. When the spread is understood and communicated, set
   `DELIVERY_ROUTING_PRICING_ENABLED=true`.
5. **Rollback**: set it back to false. No deploy, no migration, no data change.

Do not skip step 3. The change raises real prices.

## AC. What was deliberately not built

Dispatch engine · rider assignment · vehicle matching · full live tracking ·
movement history · dynamic pricing beyond routed-distance integration · Payment
Orchestrator · split settlement · Payment on Delivery · disputes/refunds ·
recipe or drinks expansion · GCC user interface · revenue engine · PostGIS.

M26 is not started.
