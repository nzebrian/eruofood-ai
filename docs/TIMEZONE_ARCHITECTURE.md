# EruoFood AI — Timezone Architecture

A locked platform rule: **UTC is authoritative, IANA identifiers are the only
timezone format, and the device clock is never trusted for server decisions.**

## 1. What was wrong before this change

| Fact | Consequence |
|---|---|
| `config/app.php` set `app.timezone` to `Africa/Lagos` | PHP's default timezone was Lagos, so `now()` returned Lagos wall-clock |
| All **167** timestamp columns are `timestamp` *without* time zone | Nothing in the schema recorded which zone a value was in |
| **0** columns used a database-side default (`useCurrent()`) | Every value was written by PHP, so every value was Lagos wall-clock |
| PostgreSQL's own `timezone` is `Etc/UTC` | The application and the database disagreed about what a stored timestamp meant |

Nothing was visibly broken, because every reader and writer shared the same
wrong assumption. It breaks the first time a worker, region or market does not —
and by then the incorrect values are historical.

## 2. The rules

1. **Storage is UTC.** `APP_TIMEZONE=UTC`, and
   `Shared\Infrastructure\Clock\SystemClock` names UTC explicitly rather than
   inheriting PHP's default. The guarantee is structural: a misconfigured
   container cannot change what a timestamp means.
2. **Timezones are IANA identifiers.** `Shared\Domain\Time\Timezone` accepts
   only values in the tz database. Offsets (`+01:00`), `GMT+1` and abbreviations
   (`WAT`) are rejected — an offset is correct until the rules change, a zone is
   correct afterwards too.
3. **Local time is a presentation and scheduling concern.** Convert at the edge
   with `Shared\Domain\Time\WallClock`; never store the result.
4. **The device clock is never authoritative.** Server decisions — offer
   deadlines, staleness windows, retention — use the server clock. A client
   timestamp is an input to validate, not a fact.

## 3. DST-safe conversion

`WallClock::resolve()` converts a local date and time to an instant, and reports
which of three cases it hit (`LocalResolution`):

| Case | When | Policy |
|---|---|---|
| `Unique` | Ordinary day | The single matching instant |
| `Gap` | Clocks sprang forward; the time does not exist | Move **forward** to the first valid instant |
| `Overlap` | Clocks fell back; the time happens twice | Take the **earlier** instant |

Both choices favour "sooner, and only once": a shop set to open at 01:30 on a
spring-forward morning opens when the clocks reach 02:00 rather than the
previous evening, and a 01:30 reminder on a fall-back night fires on the first
pass rather than appearing an hour late — and never twice.

Resolution asks the zone's transition table which offsets are actually in force
and keeps the candidates that render back to the requested reading. It does not
use `modify('-1 hour')` on a zoned object, which works in wall-clock terms and
therefore produces the wrong instant exactly across a transition. An earlier
implementation here did, and misclassified both DST cases as ordinary until the
tests caught it.

`Africa/Lagos` has never observed daylight saving, so neither case arises in the
platform's primary market. They arise the moment EruoFood serves a second one.

## 4. The cutover

Migration `2027_08_01_000002_backfill_timestamps_to_utc`:

- Discovers every timezone-naive timestamp column from the live schema rather
  than a hardcoded list — a column a hardcoded list missed would stay an hour
  wrong for ever, with nothing to indicate it. **262** columns were discovered on
  the post-M26 schema.
- Shifts each by the offset of the zone named in `shared.timezone.backfill_from`
  (`TIMEZONE_BACKFILL_FROM`, default `Africa/Lagos` = −1 hour). Because Lagos has
  no DST, the shift is exact for every row regardless of date. **A platform
  migrating out of a DST-observing zone could not do this** and would need a
  per-row conversion that is still ambiguous inside a fall-back overlap.
- Is **uniform**: every timestamp moves together, so every comparison, ordering
  and interval — M23 ledger entries, M24 case timelines, M25 route-cache expiry,
  M26 offer deadlines — is as true afterwards as before.
- **Runs once.** A `forward` row in `shared_timezone_backfill_log` blocks a
  second application. Double-shifting is the one mistake here that looks like
  data corruption rather than a failed migration.
- **Is reversible.** `down()` shifts back, records the reverse, and clears the
  guard.
- **Is counted**, per table and column, into `shared_timezone_backfill_log`.
- **Is a no-op on an empty database**, so `migrate:fresh` in CI sees nothing.

Verified round-trip on both engines: a row at `12:00` → rollback → `13:00` →
re-migrate → `12:00`, with per-column counts recorded.

### Deployment note

Set `TIMEZONE_BACKFILL_FROM` to the zone the target database was actually
written in before deploying. A deployment already storing UTC must set it to
`UTC`, which makes the migration correctly do nothing.

## 5. What is deliberately not done yet

Converting the 167 columns to `timestamptz` would be more correct long-term:
the column type would carry the meaning rather than a documented convention.
It rewrites every table in M23–M26 and belongs in its own change with its own
regression, not bundled into a cross-cutting foundation.

Per-record display timezones — a user's preferred zone, a merchant's operating
zone — are **not yet stored**. `Timezone` and `WallClock` exist so that when
those columns are added there is one correct way to use them, and
`Marketplace\Domain\ValueObject\BusinessHours` still takes a bare weekday and
`HH:MM` with no zone attached. Until a merchant timezone column exists, opening
hours are interpreted in server time.
