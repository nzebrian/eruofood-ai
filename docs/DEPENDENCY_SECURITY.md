# EruoFood AI — Dependency Security

What the dependency audit gates on, what it found, what was fixed, and what to
do if a release has to be reversed. Written by M45, which is the milestone that
made the gate able to fail.

## 1. The gate

`security.yml`'s `Dependency audit` job — a **required** status check on `main`.

| Ecosystem | Command | Fails on |
| --- | --- | --- |
| npm (`apps/web`) | `npm audit --audit-level=high` | HIGH, CRITICAL |
| Composer (`apps/api`) | `composer audit --locked` | any severity |

Neither command is masked. A non-zero exit fails a required check, which is the
entire point.

**The npm threshold is unchanged from before M45.** `--audit-level=high` is what
`security.yml` always specified and what `release.yml` still specifies; M45
removed the `|| true` after it, nothing else. It was not lowered to make
anything pass — `npm audit` reports **zero** vulnerabilities at every severity as
of M45, so the threshold is not currently load-bearing in either direction.

**Composer has no threshold, deliberately.** `release.yml` and
`ga-release-certification.yml` both run a bare `composer audit`, so a
pull-request gate with a severity filter would be *laxer* than the gates that
decide whether a production tag can be cut — and the difference would surface at
tag time, which is the worst moment to learn it. Matching them makes the
pull-request result a faithful preview. Composer's advisory volume is low enough
that this is affordable; if a LOW advisory with no fix ever blocks the repository
for a sustained period, `composer audit --locked --ignore-severity=low` is the
documented escape hatch, and taking it is a policy decision to record here, not a
quiet edit.

### 1.1 Why `--locked` is load-bearing

The `Dependency audit` job does not run `composer install`. Without a `vendor/`
directory, a bare `composer audit` prints

```
No packages - skipping audit.
```

and **exits 0**. So the mask was not the only defect: removing `|| true` alone
would have left a step that audits nothing and passes for it — a green tick
reporting on work that never happened, which is the M44 `staging-deploy.yml`
pattern reproduced inside the security gate.

`--locked` audits `composer.lock` directly and needs no install. `npm audit`
already reads `package-lock.json` without one, which is why that step needs no
equivalent flag and no `npm ci`.

Both were verified empirically, not assumed: against the pre-M45 lockfiles with
no `node_modules` and no `vendor/` present, both commands exit 1.

### 1.2 What enforces all of the above

- `.github/scripts/verify_dependency_audit_gate.py` — 12 checks: both commands
  present and unmasked, no job- or step-level `continue-on-error`, no `set +e` /
  `|| echo` / forced `exit 0`, the npm threshold still HIGH, `composer audit`
  still reading the lockfile, the job unconditional (a skipped required check
  reports as *pending*, not failed), and `Dependency audit` still listed in
  `required-checks.json`.
- `.github/scripts/m45_dependency_audit_control.sh` — 12 mutations that must each
  fail the check that owns it, a positive control, an sha256 integrity check, and
  **four live controls** that run the real audit commands against the pre- and
  post-M45 lockfiles and require exit 1 then exit 0. Everything else in the suite
  reads YAML; YAML cannot tell you whether a command gates on anything.
- `.github/scripts/m48_npm_audit_resilience_control.sh` — 18 checks on the
  bounded-retry wrapper the npm audit now runs through, so that retrying a
  third-party outage cannot become retrying until the gate stops objecting. See
  §1.4.
- `.github/scripts/verify_ci_reliability.py` + `m49_ci_reliability_control.sh`
  + `m49_reliability_wrappers_control.sh` — the Phase 1 reliability gate. It
  asserts that **every** dependency-audit call site routes through an approved
  wrapper, not just this one: `release.yml` and `ga-release-certification.yml`
  were still running unwrapped audits after M48, and nothing noticed because the
  validator above only ever read `security.yml`. See `docs/CI_RELIABILITY.md`.

All of them run inside the required `CI · Workflow Integrity` context.

### 1.3 Part B was vacuous in CI until M47

The paragraph above describes what the four live controls were *meant* to do.
For the whole of M45 and M46 they did not do it, and the suite reported green
anyway. Two defects compounded.

**The baseline moved.** Part B resolved the pre-M45 lockfiles as
`${M45_BASE_REF:-origin/main}`. That was correct exactly once — while M45 was an
unmerged branch. The moment M45 merged, `origin/main` *became* the post-M45
state, so "before" and "after" were the same lockfiles and "the pre-M45 lockfile
must fail the audit" was unsatisfiable. The control invalidated itself by
succeeding.

**Absent evidence counted as evidence.** The success condition was
`live_ok + live_skipped == live_total`, which `live_ok=0, live_skipped=4`
satisfies. Every live control could skip and the suite still exited 0.

Together they were worse than either alone, because CI checks out at
`fetch-depth: 1` and `origin/main` does not exist in that clone at all. From
PR #54's own `CI · Workflow Integrity` log:

```
-- Part B: the real commands, against real lockfiles --
  SKIPPED — could not extract the pre-M45 manifests from origin/main.
0/4 live audit controls confirmed (4 skipped: endpoint unreachable).
```

The message was wrong twice over: the cause was a missing git ref, not an
unreachable endpoint, and the outcome was not a skip — it was a required check
passing with no evidence behind it. A control written to prove a gate is not
vacuous, itself vacuous, inside the required context.

**What M47 changed.**

| Was | Now |
| --- | --- |
| baseline `${M45_BASE_REF:-origin/main}` | pinned to the immutable commit `f840e1c`; `M45_BASE_REF` remains an explicit override, with **no fallback** |
| baseline missing → `SKIPPED`, suite green | `BASELINE UNRESOLVABLE` → **fails** |
| endpoint unreachable → `SKIPPED`, suite green | `UNAVAILABLE (evidence missing)` → **fails** |
| success if `live_ok + live_skipped == live_total` | success requires `live_ok == live_total` **and** `live_unavailable == 0` |

Part B needs a commit that a shallow clone does not have, and M47 does **not**
deepen the checkout for it. The control fetches that one commit itself
(`git fetch --depth=1 origin <sha>`), which is narrower than deepening the clone
and keeps the repair inside the script rather than in a workflow fourteen other
things depend on. Verified against a real `--depth=1` clone: the fetch succeeds,
the lockfiles read back, and all four live controls run.

`.github/scripts/m47_control_integrity_control.sh` is the guard on that repair —
14 checks. Cases A, B, D and E are deterministic and need no network: an
unresolvable baseline must fail; four evidenceless live controls must not exit 0
(with Part A still passing inside the fixture, so the failure is attributable to
Part B alone); the success condition must still require complete evidence; and
the pinned baseline must be an immutable 40-character commit with no
`origin/main` fallback. Case C needs the real advisory endpoints and is reported
as **unproven, not passed**, when they are unreachable.

### 1.4 npm's advisory service is a third party, and it goes down (M48)

On 2026-09-04 the required `Dependency audit` context failed like this:

```
npm warn audit 503 Service Unavailable -
    POST https://registry.npmjs.org/-/npm/v1/security/audits/quick
npm error audit endpoint returned an error
Process completed with exit code 1
```

The lockfile had zero advisories. npm's advisory service had a bad ten minutes.

This is a real problem and not a cosmetic one, because the obvious remedies are
both wrong. Leaving it makes a required security gate flap on a third party's
availability, which trains people to re-run red checks until they go green —
which is how a genuine advisory eventually gets clicked past. Suppressing it
with `|| true` or `continue-on-error` is the *exact defect M45 was created to
remove*, and would silently reintroduce it under cover of a reliability fix.

**What M48 changed.** `apps/web`'s audit now runs through
`.github/scripts/npm_audit_resilient.sh`, which distinguishes three answers
where npm offers two:

| Answer | Meaning | Exit |
| --- | --- | --- |
| `PASS` | npm audited the tree and found nothing at or above the threshold | 0 |
| `VULNERABLE` | npm audited the tree and found advisories | 1 |
| `UNAVAILABLE` | npm could not produce trustworthy evidence | 3 |

`npm audit` exits 1 for a vulnerability *and* for a dead endpoint, and that
ambiguity is what made the incident take six minutes of log archaeology. All
three answers are now printed in words as well as returned as exit codes.

Only the transient class is retried — HTTP 429/500/502/503/504, connection
reset/refused/timeout, DNS failure — up to three attempts with a 3s then 9s
backoff. A malformed response or dependency tree is **not** retried: retrying
cannot repair it, so it fails closed immediately rather than burning the budget.
A finding is **never** retried, and the verdict patterns are matched *before* the
outage patterns so a real advisory can never be downgraded to "the service was
down". That precedence is the single misclassification that would turn this
script into a security regression, and it has its own test.

**`UNAVAILABLE` exits non-zero, deliberately.** Absent evidence is not clean
evidence. A gate that cannot see must not wave things through, so an audit that
could not be performed fails the check exactly as a finding would — it just says
which of the two happened.

**It is also faster than not having it.** npm's own retry behaviour
(`fetch-retries=2`, `fetch-retry-maxtimeout=60000`) is generous and serialises
across many requests. On 2026-09-04 it consumed nine minutes of
`CI · Workflow Integrity`'s ten-minute cap and the job was **cancelled**
mid-step, taking four later controls with it. The wrapper disables that internal
retry storm and imposes a hard per-attempt timeout, so the worst case is
predictable: 3 × 45s + 12s backoff = 147s. A healthy audit still returns in about
two seconds, because nothing sleeps on success. M45's Part B uses a tighter
budget still (2 × 40s + 5s = 85s per control) precisely because it runs inside
that ten-minute job.

The audit policy stays at the call site in `security.yml` —
`npm audit --audit-level=high`, passed through verbatim rather than
reconstructed inside the wrapper — so `verify_dependency_audit_gate.py` still
reads the threshold where it always did, M45's mutation tests still anchor on
it, and it cannot be lowered somewhere less visible. The threshold is unchanged:
HIGH and CRITICAL fail.

**Extended in Phase 1.** M48 wrapped one of five audit call sites. Phase 1
wrapped the rest — `composer audit` in `security.yml`, `release.yml` and
`ga-release-certification.yml`, and `npm audit` in `release.yml` — behind the
same three-verdict protocol, with `composer_audit_resilient.sh` as the composer
half. Composer needed it for a reason of its own: it reserves exit **100** for a
generic error, which is never a verdict but which "non-zero means bad" reports
as a vulnerability. A validator now fails if an ungoverned audit is reintroduced
anywhere. See `docs/CI_RELIABILITY.md`.

**What enforces it.** `.github/scripts/m48_npm_audit_resilience_control.sh`,
inside the required `CI · Workflow Integrity` context — 18 checks. A retry
wrapper around a security gate is a dangerous object, because every bug in one
points the same way: towards passing. So the control drives a **stub `npm` on
PATH** from a scenario file, never the real registry — a control that needs
npm's advisory service to be up cannot describe what happens when it is down —
and asserts the exit code, the verdict word, *and the number of npm invocations*
for each case: clean → PASS in one attempt; a finding → VULNERABLE in one
attempt; 503/429/500/502/504 then success → PASS in two; every attempt failing →
UNAVAILABLE in three; a persistent timeout → UNAVAILABLE; a malformed response →
fail closed in **one** attempt; a finding printed alongside a 503 banner → still
VULNERABLE; and exit 0 accompanied by an error banner → refused, not read as
clean. It also greps the security path for `|| true`, `|| :`, `set +e`,
`continue-on-error` and bare forced `exit 0`, and re-fingerprints the helper and
`security.yml` to prove the run changed neither.

That suite was itself checked against three deliberate regressions, each
reverted immediately afterwards with the file's sha256 confirmed restored:
making `UNAVAILABLE` exit 0 failed 4 checks, classifying a vulnerability as
transient failed 3 (including the invocation count — it retried a real finding),
and appending `|| true` to the helper failed the masking check.

## 2. What M45 found

Measured at `main` = `f840e1c`, before any change.

### npm — `apps/web`, 11 advisories (2 critical, 4 high, 5 moderate)

| Package | Sev | Prod/dev | Direct? | Advisory | Fixed in |
| --- | --- | --- | --- | --- | --- |
| `vitest` | critical | dev | direct | UI server arbitrary file read/exec (GHSA-5xrq-8626-4rwp) | 3.2.6 |
| `@vitest/coverage-v8` | critical | dev | direct | inherited from `vitest` | 3.2.6 |
| `vite` | high | dev | direct | `server.fs.deny` bypass on Windows (GHSA-fx2h-pf6j-xcff) | 6.4.3 |
| `brace-expansion` | high | dev | transitive (eslint, glob) | DoS via unbounded expansion (GHSA-mh99-v99m-4gvg, GHSA-rgw5-rvv9-x895) | 1.1.18 / 2.1.4 / 5.0.9 |
| `js-yaml` | high | dev | transitive | quadratic CPU in `!!omap` (GHSA-5p4m-2wfm-xmqj) | 4.3.1 |
| `nanoid` | high | dev | transitive (vite) | infinite loop on zero size (GHSA-2v37-7h3g-55p8) | 3.3.18 |
| `react-router-dom` | moderate | **production** | direct | open redirect → XSS (GHSA-jjmj-jmhj-qwj2) | 6.30.5 |
| `react-router` | moderate | **production** | transitive | open redirect via backslash in `<Link>`/`useNavigate` (GHSA-wrjc-x8rr-h8h6); constructor injection in `deserializeErrors()` (GHSA-337j-9hxr-rhxg) | 7.18.0 |
| `esbuild` | moderate | dev | transitive (vite) | dev server responds to any origin (GHSA-67mh-4wv8-2f99) | 0.25.0 |
| `@vitest/mocker`, `vite-node` | moderate | dev | transitive | inherited from `vite` | with `vitest` 3 |

**Only two of the eleven were production dependencies**, both `react-router`.

### Composer — `apps/api`, 7 advisories (3 high, 3 medium, 1 low)

| Package | Sev | Prod/dev | Direct? | Advisory | Fixed in |
| --- | --- | --- | --- | --- | --- |
| `league/commonmark` | high ×3 | production | transitive (`laravel/framework ^2.8.1`) | colliding heading slugs (GHSA-mh25-x5hq-wrqp), duplicate footnote definitions (GHSA-jfm3-95jq-q3rf), adjacent inline attribute blocks (GHSA-g2gp-3wwq-f4ph), quadratic parse (CVE-2026-71488) | 2.9.0 |
| `league/commonmark` | medium ×2 | production | transitive | deeply nested XML output (GHSA-mj63-m3rc-8ppr); `AttributesExtension` unsafe-link bypass (CVE-2026-71478) | 2.9.0 |
| `firebase/php-jwt` | low | production | **direct** (`^6.10`) | weak encryption / no minimum key-size validation (CVE-2025-45769) | 7.0.0 |

## 3. What M45 changed

| Package | From | To | Manifest? | Why this version |
| --- | --- | --- | --- | --- |
| `league/commonmark` | 2.8.3 | 2.10.0 | lock only | Minimum fixed is 2.9.0; 2.10.0 is the current release inside Laravel's own `^2.8.1` constraint. Six advisories cleared with a six-line lockfile diff. |
| `firebase/php-jwt` | v6.11.1 | v7.1.0 | `^6.10` → `^7.0` | No 6.x fix exists. See §5.1. |
| `vite` | 5.4.21 | 6.4.3 | `^5.4.8` → `^6.4.3` | **Minimum safe fixed version.** The advisory range is `<=6.4.2`; 6.4.3 clears it. npm proposed 8.2.2 and Dependabot #6 proposes 8.2.0 — three majors, none of them needed. |
| `vitest` | 2.1.9 | 3.2.7 | `^2.1.2` → `^3.2.7` | Minimum safe is 3.2.6. npm and Dependabot #7 proposed 4.1.x; one major clears the critical. |
| `@vitest/coverage-v8` | 2.1.9 | 3.2.7 | `^2.1.2` → `^3.2.7` | Pinned to `vitest` exactly by its own peer range. |
| `react-router-dom` | 6.30.4 | 7.18.3 | `^6.27.0` → `^7.18.0` | The only production vulnerability, and the only one with no 6.x fix. See §5.2. |
| `brace-expansion`, `js-yaml`, `nanoid`, `@remix-run/router` | — | — | lock only | `npm audit fix`, no manifest change, no breaking change. |

Nothing else was upgraded. `@vitejs/plugin-react@4.7.0` already declares
`vite: ^4.2.0 || ^5.0.0 || ^6.0.0 || ^7.0.0`, so the open Dependabot bump to 6.0.5
(#4) is version currency, not a compatibility requirement, and was left alone.

**Result: `npm audit` reports 0 vulnerabilities at every severity;
`composer audit --locked` reports no advisories at all.**

## 4. What remains

Nothing, in the audited scope.

One coverage gap is worth naming because no audit will ever report it:
**`packages/api-contracts` has no `package-lock.json`**, so `npm ci` is impossible
there and `npm audit` has nothing to read. `security.yml` does not audit that
directory and cannot. Its two dev dependencies (`@redocly/cli`,
`openapi-typescript`) are build-time only and never ship, but the gap is real and
closing it means committing a lockfile for that package — out of M45's scope, and
tracked here rather than left to be rediscovered.

## 5. Compatibility considerations

### 5.1 `firebase/php-jwt` v6 → v7

The v7 breaking change is **minimum key-size validation**: keys shorter than the
algorithm's minimum are now rejected at encode/decode time rather than silently
accepted. That is the CVE being fixed, so it is the change, not a side effect.

- Usage is three symbols — `JWT::encode`, `JWT::decode`, `Key` — in one file,
  `modules/Identity/src/Infrastructure/Auth/JwtTokenIssuer.php`. No signature
  changed.
- `JWT_ALGO` is `HS256`, whose minimum key length is 256 bits (32 bytes).
- `config/identity.php` falls back to `APP_KEY` when `JWT_SECRET` is empty.
  Laravel's `APP_KEY` is `base64:` plus 32 random bytes, comfortably over.
- `infra/env/production.env.example` already specifies "256-bit random".

**Operational check before deploying:** any environment whose `JWT_SECRET` is
shorter than 32 bytes will start throwing on token issuance. That is a weak key
that should be rotated regardless, but rotate it *before* the deploy rather than
discovering it from a 500. The full Pest suite (1730 tests) and the 43 Identity
tests pass on v7.

### 5.2 `react-router-dom` v6 → v7

This is the only production major upgrade in M45, and it was taken because both
remaining advisories are open redirects and prototype/constructor injection in
`<Link>` and `useNavigate` — the app's own routing surface — with no 6.x fix.

What made it low-risk here:

- The app uses `createBrowserRouter` with plain `element` routes, plus `Link`,
  `Navigate`, `useNavigate`, `useParams`, `useSearchParams`, `MemoryRouter` and
  `RouterProvider`. **No loaders, no actions, no `json()`/`defer()`, no SSR, no
  splat routes** — that is the subset v7 changed least.
- v7's breaking changes are largely the v6 `future` flags becoming defaults. None
  were set, and the ones that now apply (`v7_relativeSplatPath`,
  `v7_partialHydration`, `v7_normalizeFormMethod`, `v7_fetcherPersist`,
  `v7_skipActionErrorRevalidation`) all govern features this app does not use.
- Peer requirements are `react >=18` / `react-dom >=18` and Node `>=20`. The repo
  is on React 18.3.1 and Node 22.

**One source change was genuinely required.** In v7 `useNavigate()` returns a
promise, so four bare `navigate(...)` calls became floating promises and ESLint's
`no-floating-promises` rejected them. Each was prefixed with `void`, matching the
idiom already used for form submits in the same files
(`onSubmit={(e) => void onSubmit(e)}`). Behaviour is unchanged: nothing awaited
the navigation before, and nothing does now.

- `src/features/auth/pages/LoginPage.tsx` (×2)
- `src/features/auth/pages/RegisterPage.tsx`
- `src/features/auth/pages/ResetPasswordPage.tsx`

### 5.3 `vite` 5 → 6 and `vitest` 2 → 3

Dev tooling only; nothing here reaches a production bundle beyond the build
output, which was rebuilt and compared. `vite.config.ts` uses `plugins`,
`resolve.alias`, `server`, `build` and `test` — all stable across both majors, and
`build.target` is set explicitly to `es2022`, so vite 6's changed default does not
apply. ESLint, `tsc --noEmit`, all 75 Vitest tests, `test:coverage` (the command
CI actually runs) and the production build all pass.

## 6. Relationship to the open Dependabot pull requests

M45 deliberately did **not** adopt the open Dependabot bumps, even where they
touch the same packages, because each proposes the newest major rather than the
minimum safe fixed version:

| PR | Proposes | M45 took | Why |
| --- | --- | --- | --- |
| #6 | `vite` 5.4.21 → 8.2.0 | 6.4.3 | Three majors where one clears the advisory. |
| #7 | `vitest` 2.1.9 → 4.1.10 | 3.2.7 | Two majors where one clears the critical. |
| #5 | `react-router-dom` 6.30.4 → 7.18.2 | 7.18.3 | Same major; M45 took the current patch. This PR is now redundant. |
| #4 | `@vitejs/plugin-react` 4.7.0 → 6.0.5 | not taken | 4.7.0 already supports vite 6. Version currency, not a fix. |

The remaining twelve open Dependabot PRs are Flutter, GitHub Actions and
`@redocly/cli` bumps with no advisory behind them. They are a separate backlog.

## 7. Rollback

The change is a dependency update plus an unmasking. Nothing here touches the
database, migrations, application behaviour beyond §5.2's four `void` keywords,
or any deployed configuration.

**If the gate is the problem** (a new advisory lands with no fix and the required
context blocks every pull request): do not re-add `|| true` — the validator and
its controls will fail the required `CI · Workflow Integrity` context, which is
the point. Either upgrade the package, or record the exception in
`.github/governance/known-gaps.json` and adjust the threshold *deliberately*,
documenting it in §1.

**If a dependency is the problem** (a runtime regression traced to one of the
upgrades):

```bash
# Revert the whole milestone (a merge commit; -m 1 selects pre-merge main)
git revert --no-edit -m 1 <merge-commit-sha>
```

or revert the single commit that carries the dependency change
(`fix(m45): remediate audited dependencies`) and leave the fail-closed gate in
place — at which point the gate correctly goes red, telling you the tree is
vulnerable again, which is true.

Partial rollbacks, cheapest first:

| Revert | Cost | Consequence |
| --- | --- | --- |
| `react-router-dom` to `^6.27.0` | must also revert the four `void navigate(...)` edits | two moderate production advisories return |
| `vite`/`vitest` to 5/2 | lockfile + manifest | one critical and one high dev advisory return |
| `firebase/php-jwt` to `^6.10` | lockfile + manifest | one low advisory returns; weak JWT keys silently accepted again |
| `league/commonmark` | lockfile only, 6 lines | three high advisories return |

**No database rollback is required in any case.** No schema, migration or data
change is part of M45.

## 8. Verification performed

| Check | Result |
| --- | --- |
| `npm audit` (all severities) | 0 vulnerabilities |
| `npm audit --audit-level=high` | exit 0 |
| `npm audit --omit=dev --audit-level=high` | exit 0 |
| `composer audit --locked` | no advisories |
| ESLint (`--max-warnings=0`) | clean |
| `tsc --noEmit` | clean |
| Vitest | 75 passed, 18 files |
| `npm run test:coverage` (CI's command) | passed |
| `npm run build` | built, vite 6.4.3 |
| Pest (full suite, SQLite) | 1730 passed, 18 skipped |
| PHPStan level 8 | no errors |
| Pint | passed |
| `actionlint` 1.7.7 over all workflows | clean |
| `verify_dependency_audit_gate.py` | 12/12 |
| `m45_dependency_audit_control.sh` | 12/12 mutations + 4/4 live + positive + integrity |

The one pre-existing failure encountered, `ReadinessEndpointTest`, was a Redis
daemon that was not running in the sandbox; it passes with Redis up and is
unrelated to any dependency change.
