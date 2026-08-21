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
- the tag rulesets restrict creation, deletion and updates on `refs/tags/v*`
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

### Optional: feed it live GitHub data

```bash
gh api /repos/nzebrian/eruofood-ai/rulesets > /tmp/rulesets.json
php apps/api/scripts/verify_repository_governance.php --rulesets=/tmp/rulesets.json
```

With that file present, the external checks are evaluated for real rather than
deferred.

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

Must equal the seven contexts in `required-checks.json`, byte for byte —
including U+00B7 MIDDLE DOT.

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
| After changing CODEOWNERS | §2.7 and §3 |
| Monthly | §1 and §2 |
| After every break-glass | §1, §2, §3 in full, per `BREAK_GLASS.md` |
| Before enabling `settlement.execute` | everything, plus the M28 exit gate |

That last row is the point of the milestone. The financial protections built in
M27 and M28 are only as strong as the governance that stops someone removing
them — and until this is applied, that governance does not exist.
