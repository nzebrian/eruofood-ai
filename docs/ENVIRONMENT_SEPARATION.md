# EruoFood AI — Environment Separation

Milestone 28, Phase 2. What must be separate between development, staging and
production, how that separation is enforced rather than assumed, and what is
still enforced only by convention.

## 1. The defect this exists because of

`config/payments.php` selects the payment gateway like this:

```php
'default' => env('PAYMENTS_PROVIDER', env('APP_ENV') === 'testing' ? 'mock' : 'paystack'),
```

No environment template set `PAYMENTS_PROVIDER`. So `.env.example` — the file CI
copies and every developer copies — resolved to **Paystack, a live gateway**.
Not through misconfiguration: through nothing being configured at all.

This already caused one incident. M27's financial concurrency harness spent six
scenarios attempting genuine bank transfers in CI, and the resulting failures
read as concurrency defects for a day. The harness was fixed to refuse a live
provider. The *class* of defect — an environment that inherits a dangerous
default because nobody stated a safe one — was not, until now.

The principle that follows:

> A dangerous default that nobody has to opt into is not a default. It is a
> decision made by whoever wrote the config file, on behalf of everyone who
> never read it.

## 2. What must be separate

| Resource | Separated by | Enforced by |
|---|---|---|
| Database | Distinct `DB_DATABASE` per environment | `EnvironmentTemplateSeparationTest` |
| Redis keyspace | Explicit `REDIS_PREFIX` per environment | template test + `REDIS_PREFIX_MISSING` |
| Cache keyspace | Explicit `CACHE_PREFIX` per environment | template test |
| Queues | Redis keyspace above | as Redis |
| Secrets | `__SET__` in templates; injected from the secret manager | template test |
| Payment credentials | `PAYMENTS_PROVIDER` pinned per environment | `ops:verify-environment` |
| Object storage | Distinct `AWS_BUCKET` | template test |
| Logs | Per-environment collector; `LOG_LEVEL` never `debug` when deployed | `ops:verify-environment` |
| Backups | Separate account/project from production | `docs/BACKUP_RESTORE.md` §1 |
| Monitoring | Per-environment Prometheus labels | deployment-time |
| Access control | Least privilege; SuperAdmin-only for adjust/reverse | `SecretsAndAccessAuditTest` |

Development's database is `eruofood_local`, not `eruofood`. It shared production's
name until M28. The name is the last line of defence when somebody points a local
`DB_HOST` at the wrong host: a mismatch turns a catastrophe into a connection
error.

## 3. The rules, and why each one exists

`EnvironmentPolicy` is the single place these live. `ops:verify-environment`
evaluates them against a running deployment and exits non-zero on any error.

### Fail closed on an unrecognised environment

`APP_ENV=prod` is not production. Every production-only rule asks "is this
production?", so one typo relaxes all of them at once —
debug output, TLS enforcement, cookie flags, the payment provider. An
unrecognised value is `ENV_UNRECOGNISED`, an error, and the only finding
reported: there is nothing useful to say about a deployment that cannot name
itself.

### The payment provider is stated, never inherited

- `PAYMENTS_UNPINNED` — error in staging and production, warning elsewhere.
- `PAYMENTS_LIVE_OUTSIDE_PRODUCTION` — a live gateway anywhere but production.
  A non-production environment holding live credentials is a production payment
  system nobody is watching.
- `PAYMENTS_OFFLINE_IN_PRODUCTION` — the inverse, and just as bad: the offline
  provider reports success without transferring anything, so the platform would
  record payouts no bank ever made.

An **unknown** provider name is treated as live. Adding an adapter is then a
deliberate act rather than a silent widening.

### Sandboxes get their own identifier

`EnvironmentSnapshot::sandboxPaymentProviders()` is empty, and the seam matters
more than the contents. When staging is pointed at a provider sandbox, register
it as `paystack_sandbox` — not `paystack` with different keys. Distinguishing
sandbox from live by which credential happens to be mounted means the only thing
preventing a real transfer is an environment variable that looks identical
whether it is right or wrong.

### The settlement activation order is an invariant, not a convention

```
settlement.accrual → settlement.accrual_posting → settlement.compute → settlement.execute
```

Each stage produces what the next consumes. Enabling `settlement.execute` while
`settlement.compute` is off does not fail loudly — it means the thing paying
merchants is working from figures no stage produced. `SETTLEMENT_ORDER_VIOLATED`
fails the deploy.

Two combinations are called out separately because they are the ones that lose
money: `settlement.execute` on outside production against a live provider, and
`settlement.execute` on in production against the offline provider.

### Transport and storage

`APP_DEBUG` false in production; `APP_KEY` present when deployed; `LOG_LEVEL`
never `debug` when deployed (debug logging records provider payloads, which is
how account numbers reach a log aggregator); TLS to the database; an
authenticated Redis; a prefixed Redis keyspace; object storage rather than
container-local disk; never the `sync` queue in production.

## 4. Running it

```bash
php artisan ops:verify-environment          # human-readable, exits non-zero on error
php artisan ops:verify-environment --json   # for a deploy pipeline
```

It prints the resolved provider and every settlement flag on **every** run, pass
or fail. A gate that is silent when healthy teaches its readers that silence
means "checked" — and silence is also what a gate that never ran looks like.

It changes nothing. It cannot disable a flag, rotate a credential or pin a
provider: a validator that repairs what it finds hides the fact that a
deployment shipped broken.

CI runs it against the committed development template on every build.

## 5. Still enforced by convention

Named rather than implied:

- **Distinct hosts** for staging and production databases and Redis are a
  deployment-time property. The validator can see that keyspaces and database
  names differ; it cannot see that two `__SET__` hostnames resolve to the same
  cluster.
- **Secret manager separation** is likewise deployment-time. The templates prove
  no secret is committed; they cannot prove production's secrets are unreadable
  from staging's role.
- **Credential rotation** has no automation. The templates mark every secret as
  injected, which makes rotation possible; nothing schedules or verifies it.

These are the gaps to close before the first live payout, and they are
infrastructure work rather than application work.
