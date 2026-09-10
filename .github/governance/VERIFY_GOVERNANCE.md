# Verifying Repository Governance

Run this after `APPLY_GOVERNANCE.md`, and again on a schedule. Configuration
drifts, and a protection nobody re-checks is a protection nobody knows the state
of.

The split below matters more than any individual check:

- **Repository-side** — provable from files in this repository. Automated by
  `apps/api/scripts/verify_repository_governance.php`.
- **GitHub-side** — provable only by asking GitHub. Needs a credential that can
  read rulesets. **A JSON artifact in this repository is never evidence that
  GitHub is enforcing anything.**

That distinction is the entire lesson of the M29-A audit. Before it,
`.github/CODEOWNERS` had named six teams for months. The file existed, it looked
configured, and every single owner was unresolvable.

---

## 1. Repository-side

```bash
php apps/api/scripts/verify_repository_governance.php
```

Exit code `0` means every repository-side check passed. Non-zero means at least
one failed — **not** that GitHub is enforcing anything.

It checks:

- governance artifacts exist and parse as JSON
- `main-ruleset.json` encodes the intended policy: deletion, non-fast-forward,
  pull request, approvals, stale dismissal, last-push approval, code-owner
  review, strict status checks, and an empty `bypass_actors`
- the **prepared** tag rulesets restrict creation, deletion and updates on
  `refs/tags/v*` — a claim about the committed JSON, not about GitHub. What is
  actually deployed is answered separately by the five live tag checks
  (`github.tag_rulesets_active` plus the four `github.tag_ruleset_*`), which
  read `GET /rulesets` and `GET /rulesets/{id}` and report EXTERNAL when that
  evidence is missing
- every required check context in `required-checks.json` exists as a job `name:`
  in the workflow it claims
- every workflow owning a required check reports on **every** pull request
  (no `paths:` filter on `pull_request`)
- neither `GA Docker Certification` nor anything from `release.yml` has been
  added to the required list
- `.github/CODEOWNERS` contains no unresolvable owner handle
- `BREAK_GLASS.md` documents every required field

Anything depending on GitHub state is reported as **EXTERNAL / ADMIN REQUIRED**
and never as PASS.

### 1.0 Ownership mode (M29-I)

Both validators print the mode first. Read it before anything else — it decides
what the rest of the output means:

```
SOLE_OWNER MODE
Automated controls:      ACTIVE
Independent human review: DEFERRED
CODEOWNERS enforcement:   DEFERRED
Finance four-eyes review: DEFERRED
Reason: repository currently has one real human owner
```

A DEFERRED line is not a passed check and is not a failure. It is a control that
is off, recorded as off, with a stated route back. The validator also asserts
that `main-ruleset.sole-owner.json` differs from `main-ruleset.json` only in the
three review parameters — so the mode cannot be used to drop a status check or
open a bypass.

### 1.1 Identity and activation readiness (M29-B)

```bash
php apps/api/scripts/verify_governance_identities.php
```

Reports `UNCONFIGURED`, `INCOMPLETE`, or `READY FOR ACTIVATION`. There is no
state meaning *active*, and `READY FOR ACTIVATION` is a statement about this
repository only.

It checks that every role has a resolvable identity, that the release actor is a
numeric actor id rather than a handle, that no placeholder or example value has
survived into an active configuration, and that no active CODEOWNERS rule names
an owner that cannot resolve. The `identities.example.json` template is rejected
outright if used as the live file.

An absent `identities.json` exits 0. Nobody has claimed an owner, so nothing is
claiming falsely.

### Optional: feed it live GitHub data

```bash
gh api /repos/nzebrian/eruofood-ai/rulesets          > /tmp/rulesets.json
gh api /repos/nzebrian/eruofood-ai/codeowners/errors > /tmp/codeowners-errors.json

# One detail call per ruleset, ids taken from the list — never hard-coded.
jq -r '.[].id' /tmp/rulesets.json \
  | xargs -I{} gh api /repos/nzebrian/eruofood-ai/rulesets/{} \
  | jq -s '.' > /tmp/ruleset-details.json

php apps/api/scripts/verify_repository_governance.php \
  --rulesets=/tmp/rulesets.json \
  --ruleset-details=/tmp/ruleset-details.json \
  --codeowners-errors=/tmp/codeowners-errors.json
```

**Why two ruleset endpoints (M50-05 N-4b, generalised by N-1).** They are
different payloads and only one of them answers the content questions:

| endpoint | carries | answers |
| --- | --- | --- |
| `GET /rulesets` | `id`, `name`, `target`, `enforcement` | which rulesets exist and whether they are active |
| `GET /rulesets/{id}` | the above **plus `rules` and `bypass_actors`** | which status checks are required, which tag rules are enforced, and who may bypass them |

N-1 replaced a pinned `rulesets/21203909` with the walk above. The pin read
main's ruleset, so the tag rulesets — whose `rules` and `bypass_actors` live at
the very same endpoint — had no evidence source at all, and every question about
their contents was unanswerable by construction. Deriving the ids from the list
also means a recreated ruleset is simply picked up next run instead of silently
404-ing forever.

`--ruleset-detail=` (singular) still works and is treated as a one-element set.

The list has no `rules` array at all, so for as long as it was the only evidence
collected, nothing in this repository could compare what
`required-checks.json` declares against what GitHub enforces. That is exactly
how `Mobile Certification` came to be declared-but-unenforced without any
control noticing.

With those files present, the matching external checks are evaluated for real
rather than deferred. Everything still unanswered stays EXTERNAL — supplying one
file does not resolve the others.

Note what the CODEOWNERS check does with a clean result: zero errors is treated
as a **failure** while no rule is active, because a fully commented-out file also
returns zero. Passing on that would mean this validator confirming that review
routing works on a file which routes nothing — §2.7 below, made non-optional.

---

## 2. GitHub-side

### 2.1 Rules actually apply to `main`

```bash
gh api /repos/nzebrian/eruofood-ai/rules/branches/main | jq '[.[].type] | sort'
```

Expect at least: `["deletion","non_fast_forward","pull_request","required_status_checks"]`

An **empty array is the pre-M29-A state** — nothing is enforced.

### 2.2 The branch reports as protected

```bash
gh api /repos/nzebrian/eruofood-ai/branches/main | jq '.protected'   # true
```

### 2.3 Pull request rule

```bash
gh api /repos/nzebrian/eruofood-ai/rules/branches/main \
  | jq '.[] | select(.type=="pull_request") | .parameters'
```

Expect `required_approving_review_count` ≥ 1 (or the documented 0 per
`APPLY_GOVERNANCE.md` §0.2), `dismiss_stale_reviews_on_push: true`,
`require_code_owner_review: true`, `require_last_push_approval: true`.

### 2.4 Required checks

```bash
gh api /repos/nzebrian/eruofood-ai/rules/branches/main \
  | jq -r '.[] | select(.type=="required_status_checks")
           | .parameters.required_status_checks[].context'
```

Must equal the contexts in `required-checks.json` as a **set**, byte for byte —
including U+00B7 MIDDLE DOT. Not a count: "nine" is today's answer, not the
property, and a control asserting the number would keep passing after somebody
swapped one context for another.

**This is now checked automatically** — `github.required_checks_enforced` in
`verify_repository_governance.php`, fed by `--ruleset-details=`. Its three
outcomes are deliberate and it is worth knowing which you are looking at:

- **PASS** — live detail was retrieved, structurally valid, and
  `declared == live` exactly. Duplicates, malformed entries, an inactive
  ruleset, and a ruleset carrying no `required_status_checks` rule are all
  **FAIL**, not PASS.
- **FAIL** — the sets differ. The message names each context that is declared
  but not enforced, and each that is enforced but not declared.
- **EXTERNAL / ADMIN REQUIRED** — nobody could look. No detail evidence was
  supplied, or the payload arrived without `rules`, or more than one active
  branch ruleset exists (GitHub aggregates required checks across them, so a
  single detail payload cannot prove the whole set).

**EXTERNAL is never an approximation of PASS.** The validator does not fall back
to `required-checks.json` when GitHub does not answer: that file is one side of
the comparison, and using it as evidence for the other would mean this
repository grading itself against its own JSON and reporting a green tick for it.

If CI reports EXTERNAL here, read the `GET ruleset detail <id> -> HTTP <code>`
lines in the advisory job's evidence step. A 403 means the token lacks the
permission to read ruleset detail, and the honest state is unverified — not a
failure of governance, and not a pass either.

### 2.4a Tag-ruleset contents (N-1)

`github.tag_rulesets_active` counts tag rulesets. Four more checks read them,
all from `GET /rulesets/{id}` and all with the same three outcomes:

| check | PASS when |
| --- | --- |
| `github.tag_ruleset_split` | exactly two active tag rulesets, both including `refs/tags/v*`, dividing into one creation and one immutability ruleset |
| `github.tag_ruleset_rules` | the creation ruleset carries `creation`; the immutability ruleset carries exactly `deletion` + `non_fast_forward` + `update` |
| `github.tag_ruleset_bypass_empty` | the immutability ruleset's `bypass_actors` is **present and `[]`**, and no actor appears in both rulesets |
| `github.tag_ruleset_release_actors` | the creation ruleset grants exactly the actors `identities.json` records |

Two distinctions carry the weight:

- **Absent is not empty.** A payload with no `bypass_actors` key reports
  EXTERNAL, never PASS. This is the M37 Phase 4A defect, and it is the reason
  the detail endpoint matters: `GET /rulesets` omits the field on every ruleset.
- **The rulesets are classified by their rules, not their names.** A name is
  administrator-supplied free text and renaming one is not a security event; the
  rule types are what GitHub enforces.

They report EXTERNAL — not PASS — when no tag ruleset exists, when the detail
payloads do not cover every listed tag ruleset, or when `identities.json` is
absent. `production-tags-ruleset.json` is never consulted for any of them.

### 2.5 No bypass actors

```bash
gh api /repos/nzebrian/eruofood-ai/rulesets | jq -r '.[] | "\(.id) \(.name) \(.enforcement)"'
gh api /repos/nzebrian/eruofood-ai/rulesets/<ID> | jq '.bypass_actors'
```

`[]` for `main` and for tag-immutability. Only tag-creation carries actors.

Every entry there is a standing exemption. If one appears that nobody remembers
adding, treat it as an incident, not as configuration.

### 2.6 Tag protection

```bash
gh api /repos/nzebrian/eruofood-ai/rules/branches/v1.0.0 | jq '[.[].type]'
```

(The rules API evaluates a hypothetical ref; the tag need not exist.) Expect
`creation`, `deletion`, `non_fast_forward`, `update`.

### 2.7 CODEOWNERS resolves

```bash
gh api /repos/nzebrian/eruofood-ai/codeowners/errors | jq '.errors | length'   # 0
```

Zero errors is necessary but not sufficient — a commented-out file also returns
zero. Confirm at least one rule is active and that a pull request actually
requests the owner.

---

## 3. Behavioural verification

The API says what is configured. Only these say what happens. Re-run
`APPLY_GOVERNANCE.md` §6 after any ruleset change:

| Test | Expected |
|---|---|
| direct push to `main` | rejected |
| force-push to `main` | rejected |
| delete `main` | rejected |
| merge without approval | blocked |
| merge with a failing check | blocked |
| pull request touching Payments | finance owner requested |
| tag `v*` as a non-release actor | rejected |

---

## 4. Cadence

| When | What |
|---|---|
| After any ruleset change | §1, §2, §3 |
| After editing any workflow trigger | §1 — a re-added `paths:` filter silently breaks a required check |
| After changing identities.json | §1.1, then §2.7 once applied |
| After changing CODEOWNERS | §1.1, §2.7 and §3 |
| Monthly | §1 and §2 |
| After every break-glass | §1, §2, §3 in full, per `BREAK_GLASS.md` |
| Before enabling `settlement.execute` | everything, plus the M28 exit gate |

That last row is the point of the milestone. The financial protections built in
M27 and M28 are only as strong as the governance that stops someone removing
them — and until this is applied, that governance does not exist.
