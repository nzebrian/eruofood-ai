# EruoFood AI — CI Reliability

How this repository bounds what CI *costs*, as distinct from what CI *checks*.

## 1. Why this document exists

Every governance control here before Phase 1 protected what a gate **says**.
None protected what it **costs**. Those turn out to be different properties, and
the gap between them is not theoretical — on 2026-09-04 it produced two red runs
in one morning, neither of which was a security failure:

| Run | What happened | Duration |
| --- | --- | --- |
| [`33863760589`](https://github.com/nzebrian/eruofood-ai/actions/runs/33863760589) `Dependency audit` | `npm error audit endpoint returned an error` — a 503 from npm's advisory service against a lockfile with **zero** advisories | **7m01s** inside one `npm audit` (11:07:48 → 11:14:49) |
| [`33863760460`](https://github.com/nzebrian/eruofood-ai/actions/runs/33863760460) `CI · Workflow Integrity` | **CANCELLED** at its 10-minute cap mid-control; steps 16-19 never ran | 9m16s in step 15 alone |

A gate can be perfectly fail-closed and still be worthless if it never runs. The
second run is the sharper lesson: four controls — including the one that proves
the enforcement wiring cannot mask a failure — produced no result at all, and
reconstructing *which* ones had run required reading step timestamps by hand out
of the API.

M48 fixed the npm half of the first row. Phase 1 generalises it and closes the
second row.

## 2. The verdict protocol

Every governed dependency audit answers with one of exactly three verdicts, in
words and in an exit code:

| Verdict | Meaning | Exit |
| --- | --- | --- |
| `PASS` | the tool audited and found nothing at or above the threshold | 0 |
| `VULNERABLE` | the tool audited and found advisories | 1 |
| `UNAVAILABLE` | trustworthy evidence could not be obtained | **3** |

Both `npm audit` and `composer audit` exit **1** for a vulnerability *and* for a
dead endpoint. That ambiguity is what made the 2026-09-04 incident take six
minutes of log archaeology, and separating the two is the entire point.

**`UNAVAILABLE` exits non-zero, deliberately.** Absent evidence is not clean
evidence. A gate that cannot see must not wave anything through, so an audit
that could not be performed fails the check exactly as a finding would — it just
says which of the two happened.

### The ordering that makes this safe

A verdict is matched **before** any outage pattern. A run that hit a 503,
retried, and then found real advisories prints both the outage banner and the
finding; asking "was there an outage?" first would downgrade a genuine
vulnerability to "the service was down". That is the single misclassification
that would turn this whole layer from a reliability improvement into a security
regression, and it has dedicated tests in both wrapper suites.

### What is retried, and what is not

| Class | Examples | Behaviour |
| --- | --- | --- |
| Transient | 408, 425, 429, 500, 502, 503, 504; ECONNRESET/REFUSED, ETIMEDOUT, DNS failure, socket timeout | retried, bounded |
| A real finding | advisory text, CVE identifiers | **never** retried |
| Malformed | invalid JSON, `Invalid package tree`, `ENOLOCK` | fails closed on the **first** attempt |
| Checksum mismatch | a downloaded archive that is not the pinned bytes | fails closed, **never** retried |
| Unknown | anything unrecognised | fails closed — conservative by design |

A checksum mismatch is not merely pointless to retry: a mismatched archive is
the signature of a corrupted or substituted artefact, and "retry until one
happens to match" is the wrong instinct to encode into a security tool. Only the
transport is retried. What arrived is judged once.

## 3. Job timeout policy

23 of 32 jobs had **no** `timeout-minutes`, including **7 of the 9 required
checks**. GitHub's default is 360 minutes. A hung required check does not fail —
it stays *pending*, which GitHub treats identically to one that never started,
leaving the pull request unmergeable with no error anywhere.

Every job now declares a bound, and
[`ci-reliability-policy.json`](../.github/governance/ci-reliability-policy.json)
assigns each to a class with a ceiling:

| Class | Ceiling | Slowest measured member |
| --- | --- | --- |
| `fast-validation` | 10m | `Lint spec · Generate types`, 26s |
| `application-tests` | 30m | `Lint · Analyse · Test`, **9m32s** |
| `governance-integrity` | 20m | `CI · Workflow Integrity`, 104s |
| `container-build` | 40m | `Build · Boot · Migrate · Healthcheck`, 3m15s |
| `mobile-certification` | 45m | Android, 5m11s |
| `release-certification` | 60m | never run on a PR |
| `deployment` | 30m | never run on a PR |
| `performance` | 240m | includes the optional 2h soak |

These are **bounds, not targets** — the point past which a job is presumed hung.
The policy records `measured_seconds` alongside each so the headroom is visible
and a future reader can tell a deliberate bound from a guess. The validator
rejects a timeout that is absent, zero, non-integer, above its class ceiling, or
*below the job's own measured duration*.

## 4. The integrity job's budget, computed rather than guessed

`CI · Workflow Integrity` runs more live external operations than any other job
in the repository and is the only one with a tight cap. Nobody had ever
multiplied them out; the 2026-09-04 cancellation was the arithmetic asserting
itself.

| Component | Count | Worst case each | Total |
| --- | --- | --- | --- |
| Fixed overhead (checkout, extraction, all non-network controls) | — | 150s | 150s |
| Governed downloads (actionlint, shellcheck) | 2 | 51s | 102s |
| Live `npm audit` | 4 | 54s | 216s |
| Live `composer audit` | 4 | 34s | 136s |
| Baseline `git fetch` | 2 | 34s | 68s |
| | | **Total** | **672s** |

Against a 20-minute (1200s) cap: **528s of headroom**. The counts are four and
not two because M47 case C re-executes the entire M45 control, so every live
operation in Part B happens twice per run.

`verify_ci_reliability.py` **recomputes this sum on every run** and fails if it
stops fitting — so the cancellation is now caught as a policy change, before any
job is ever killed. A healthy run is still ~104s; nothing sleeps on success.

### Why the live controls were not split into their own job

Splitting them out was evaluated and **rejected**. A new job is a new status
check context, and a new context is not in the ruleset — so the live audit
controls would silently stop being required. That is a bypass created in the
name of reliability. Bounding every operation and computing the total achieves
the same protection without detaching anything from the required context.

The timeout moved 10 → 20 minutes as part of this, and that is a change worth
naming rather than slipping in: it stays well inside the 1..30 band M36 already
enforces, and it is now the *only* timeout in the repository whose value is
derived from a computed worst case instead of estimated.

## 5. The control completion manifest

A job's conclusion tells you whether the job finished. It never tells you which
of its controls ran.

Each enforced control is now invoked through
[`run_control.sh`](../.github/scripts/run_control.sh), which records the
outcome and **exits with the control's own status, unchanged**. There is no
branch in which it exits 0 for a control that did not — adding one would make it
the most dangerous file in the repository.

Four states, of which only the first is acceptable:

```
PASS          ran, exited 0
FAIL          ran, exited non-zero
UNAVAILABLE   ran, exited 3 — evidence could not be obtained
NOT_RUN       no record exists
```

[`verify_control_manifest.py`](../.github/scripts/verify_control_manifest.py)
runs last and asserts the recorded set is *exactly* the mandatory set: nothing
missing, nothing unexpected, no duplicate, no verdict contradicting its own exit
code, and a gapless `1..N` sequence so a record cannot be slotted in after the
fact.

**What this is not.** It is not a cryptographic attestation. Anything with write
access to the workspace can write into the manifest directory. What it
establishes is *internal consistency* — that each record looks like something
`run_control.sh` produced by actually running a control.

**A known limit, stated rather than papered over.** The verification step
carries no `if: always()`, so on a *cancelled* job it does not run. That is
deliberate: M36 treats `if: always()` on an enforced step as failure-masking, and
introducing the very construct the repository forbids in order to observe the
controls would be a poor trade. A cancelled job is already red. What the manifest
catches is the quieter case — a control that silently stopped being wired at all
while everything else stayed green.

## 6. npm and composer network policy

`npm`'s defaults are `fetch-retries=2` with `fetch-retry-maxtimeout=60000`,
serialised across every request. That is how one `npm audit` burned 7m01s.

Installation and auditing get **different** budgets, because they are different
operations — an install legitimately makes hundreds of requests; an audit makes
one:

- **Install** (`npm ci`, `npm install`) — retry stays enabled, because a dropped
  request mid-install is common and cheap to retry. The per-retry ceiling drops
  from 60s to 20s, bounding the tail without changing behaviour on a healthy
  registry. Pinned as job-level `npm_config_*` env at all five install sites.
- **Audit** — npm's internal retry is disabled entirely
  (`npm_config_fetch_retries=0`) and replaced by the wrapper's own bounded,
  classified, observable retry. Two retry layers stacked would multiply, not add.
- **Publish** — no workflow publishes a package. Recorded in policy so that
  adding one is a deliberate act that must extend it.

For composer, `shivammathur/setup-php` exports **`COMPOSER_PROCESS_TIMEOUT=0`**
into the runner environment — zero meaning *unlimited*, verified verbatim in the
`Dependency audit` and `Governance Advisory` job logs. The wrapper overrides it
explicitly *and* imposes its own `timeout`, because the env var bounds composer's
child processes and says nothing about composer itself.

## 7. What enforces all of this

| Script | Role |
| --- | --- |
| `verify_ci_reliability.py` | 52 checks: timeouts, audit governance, download governance, wrapper contracts, classifier coverage, npm retry pinning, budget arithmetic, masking |
| `m49_ci_reliability_control.sh` | 23 mutations, each breaking one reliability property in a `mktemp -d` fixture and required to fail *that property's own check* |
| `m49_reliability_wrappers_control.sh` | 41 runtime simulations against **stubs** — composer, curl and the manifest, never the real services |
| `verify_control_manifest.py` | the mandatory-control set actually ran |

All four run inside the required `CI · Workflow Integrity` context and are in
M36's enforced set (which grew 12 → 16 in this phase — a deliberate ratchet).

A control that needs an external service to be **up** cannot describe what
happens when it is **down**. That is why every outage simulation drives a stub,
and it is the same reason M45's Part B spent two whole milestones reporting
nothing before M47 caught it.

### The classifiers are checked against each other

`npm_audit_resilient.sh` keeps its own classifier rather than sourcing
`lib/reliability_classify.sh`: it predates this phase, ships eighteen passing
adversarial tests, and the right amount of refactoring for a working,
mutation-tested security control is none. Instead the validator asserts that
**both** classifiers cover every HTTP status and network token the policy lists.
Two classifiers that drift apart are two different definitions of "outage", and
only one of them can be right — this makes the drift *detectable* rather than
merely unlikely.

## 8. Rollback

Every change in this phase is confined to `.github/**` plus this document and a
`.gitignore` line. Reverting the phase commit restores the previous behaviour
exactly; no application code, schema, API or dependency version is involved.

Reverting would reinstate: 23 unbounded jobs, four ungoverned audit call sites,
two unbounded tool downloads, an unretried baseline fetch, and no record of
which controls ran.
