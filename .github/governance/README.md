# Repository Governance

Prepared by **M29-A**, extended by **M29-B** and **M29-I**, and **applied** —
branch protection in M37, the two production tag rulesets on **2026-09-11**
(N-1). See *Deployed rulesets* below for what is live and what is still not.

Most of this directory is still a description rather than an enforcement: the
tag rulesets and the `main` ruleset are live, CODEOWNERS is not, and identity
governance is deliberately deferred. Which is which is the first thing to
establish before trusting any file here.

## Deployed rulesets

Applied by the repository administrator. The ids are recorded so a later
`GET /rulesets/<id>` can be run by hand, and so a ruleset that quietly
disappears can be told apart from one that was recreated.

| Ruleset | ID | Target | Applied |
|---|---|---|---|
| `main branch protection (sole owner)` | **21203909** | **branch** — `refs/heads/main` | M37 |
| `production release tags — restricted creation` | **22844673** | **tag** — `refs/tags/v*` | **2026-09-11** |
| `production release tags — immutable` | **22845720** | **tag** — `refs/tags/v*` | **2026-09-11** |

The first is branch protection and has nothing to do with tags. The other two
are the pair `production-tags-ruleset.json` describes, and they are two rather
than one on purpose: GitHub scopes `bypass_actors` to a whole ruleset, so an
actor allowed to *create* a release tag inside a combined ruleset would be
exempt from `deletion` as well.

- **22844673** carries `creation`, and its only bypass actor is
  `Integration#4902397` — the *EruoFood Release Governor* GitHub App. Bypass
  actor types are `Integration`, `OrganizationAdmin`, `RepositoryRole` and
  `Team`; there is no `User`, so no person holds this grant. Release tags are
  cut by dispatching `.github/workflows/release-tag.yml`, which acts as that App.
- **22845720** carries `deletion`, `non_fast_forward` and `update`, with
  `bypass_actors: []` — empty for everyone, permanently. All three rules matter:
  without `update` a tag can be **moved**, without `non_fast_forward` rewritten,
  without `deletion` removed and recreated.

First exercised end to end on 2026-09-11: run
[`34540481139`](https://github.com/nzebrian/eruofood-ai/actions/runs/34540481139)
created `v0.0.1-rc3` at `b3f5ad64034f9d7123d916a13185009099ddbc0f` as the App,
and `Release · Production Gates`
[#47](https://github.com/nzebrian/eruofood-ai/actions/runs/34540492686) passed
on it.

What is live is asserted on every `Governance Advisory` run against
`GET /rulesets` and `GET /rulesets/{id}` — never against the JSON in this
directory. Those checks are `github.tag_rulesets_active`,
`github.tag_ruleset_split`, `github.tag_ruleset_rules`,
`github.tag_ruleset_bypass_empty` and `github.tag_ruleset_release_actors`.

**Still not applied:** `.github/CODEOWNERS` remains inert with every rule
commented out, and reviewer identity is deferred under `SOLE_OWNER` — see
`known-gaps.json` and `identities.json`.

## What the original audit found

**Historical.** Read live from the GitHub API on **2026-08-21** against `main`
at `cbdc2ab`, before anything was applied. Rows below are the starting state,
not the current one — `Rulesets?` and `Tag creation restricted?` in particular
are superseded by *Deployed rulesets* above.

| Question | Answer | Evidence |
|---|---|---|
| Branch protection on `main`? | **None** | `GET /branches/main` → `"protected": false` |
| Rulesets? | **None** | `GET /rulesets` → `[]`, `GET /rules/branches/main` → `[]` |
| Direct push to `main`? | **Allowed** | no rule restricts updates |
| Force-push to `main`? | **Allowed** | no `non_fast_forward` rule |
| Delete `main`? | **Allowed** | no `deletion` rule |
| Pull request required? | **No** | no `pull_request` rule |
| Required approvals? | **0** | — |
| Required status checks? | **None** | `enforcement_level: "off"`, `contexts: []` |
| Are CI checks advisory or required? | **Advisory** | — |
| Merge queue? | **Not enabled** | requires a ruleset; none exists |
| Tag creation restricted? | **No** | `GET /tags/protection` → 404, no tag rulesets |
| Signed commits/tags required? | **No** | — |
| CODEOWNERS owners resolve? | **No — 8 errors** | `GET /codeowners/errors` |

Two consequences of that state, worth keeping because they are what the work
since has been for. **Both are now closed:**

**Every check on PR #21 was advisory.** M28 merged with five green checks, and it
would have merged with five red ones. The gates that M27 and M28 spent their
effort building were, at that moment, decoration. *Closed by ruleset 21203909:
nine required contexts, asserted live on every advisory run by
`github.required_checks_enforced`.*

**A production release was one `git push --tags` away.** `release.yml` triggers
on `v*.*.*`, and nothing restricted who could create that tag — demonstrated
rather than argued, since `v0.0.1-rc1` and `v0.0.1-rc2` were both cut by an
ordinary account with no approval and no required check. *Closed on 2026-09-11
by rulesets 22844673 and 22845720: creation is restricted to the release App,
and a release tag can no longer be moved, rewritten or deleted by anyone.*

## Why CODEOWNERS names nobody

`nzebrian/eruofood-ai` is owned by a **user account**, not an organization
(`owner.type: "User"`). GitHub teams exist only inside organizations, so the six
`@eruofood/*` handles the file used to name could not have been created even if
somebody had tried. All eight of them failed to resolve.

They are now commented out. A CODEOWNERS file full of unresolvable owners is
worse than an empty one: it looks configured, and enabling code-owner review
against it either blocks everything or enforces nothing, with no way to tell
which from reading the file. See `.github/CODEOWNERS`.

## Files

| File | What it is |
|---|---|
| `main-ruleset.json` | The `refs/heads/main` ruleset, as a `POST /rulesets` body |
| `production-tags-ruleset.json` | Two `refs/tags/v*` rulesets — restricted creation, and immutability |
| `required-checks.json` | The seven check contexts, and why the other three are excluded |
| `identities.example.json` | **M29-B.** The shape of the identity configuration. Names nobody |
| `ownership.json` | **M29-I.** How many humans govern this repository, and what is deferred as a result |
| `main-ruleset.sole-owner.json` | **M29-I.** The `main` ruleset for SOLE_OWNER mode |
| `APPLY_GOVERNANCE.md` | Step-by-step for an administrator, including how to test each protection |
| `VERIFY_GOVERNANCE.md` | How to confirm it is actually enforced afterwards |
| `BREAK_GLASS.md` | The auditable emergency procedure, and why there is no standing bypass |

Validate the repository-side half with:

```bash
php apps/api/scripts/verify_repository_governance.php
```

It reports **PASS** only for things it can genuinely check from the repository,
and **EXTERNAL / ADMIN REQUIRED** for everything that depends on GitHub state or
on identities nobody has supplied. It will not call a policy enforced because a
JSON file describing it exists.

## Ownership mode (M29-I)

`.github/governance/ownership.json` declares how many humans govern this
repository, because most of the rest only makes sense relative to that.

| Mode | Approvals | CODEOWNERS | Finance four-eyes | Ruleset applied |
|---|---|---|---|---|
| `SOLE_OWNER` | 0 | deferred | deferred | `main-ruleset.sole-owner.json` |
| `MULTI_PERSON` | 1 | required | required | `main-ruleset.json` |

**Current mode: `SOLE_OWNER`.** The repository has one human participant,
`nzebrian`. M29-A prepared its ruleset assuming several: one approving review,
code-owner review required, FINANCE forbidden from being the repository owner.
Applied unchanged here that policy does not produce strong governance — it
produces a repository nobody can merge into, because GitHub forbids approving
your own pull request, and a code-owner requirement pointed at a CODEOWNERS file
in which every rule is commented out.

**Deferred is not satisfied.** SOLE_OWNER switches off no automated control.
Lint, static analysis, tests, migrations, Redis, financial concurrency, secret
scanning, dependency audit and workflow integrity all still run and still gate a
merge, and `verify_repository_governance.php` asserts that the two rulesets are
byte-identical apart from three review parameters. What is deferred is the part
that needs a second human to exist, and both validators print it on every run:

```
SOLE_OWNER MODE
Automated controls:      ACTIVE
Independent human review: DEFERRED
CODEOWNERS enforcement:   DEFERRED
Finance four-eyes review: DEFERRED
Reason: repository currently has one real human owner
```

**No synthetic participants.** Claude and ChatGPT contributed a large share of
this governance. Neither is, or may be represented as, a collaborator, code
owner, reviewer, release actor or approver — and that is enforced, not merely
requested: `OWNERSHIP_PARTICIPANT_NOT_HUMAN` and `IDENTITY_NOT_HUMAN` reject
assistant handles wherever an identity is expected. A fabricated second reviewer
would satisfy every other check in this repository and provide none of the
review it simulates.

### Moving to MULTI_PERSON

1. Grant a second real human write access.
2. Set `mode` to `MULTI_PERSON` in `ownership.json` and add them to
   `human_participants`. Declaring it without a second person is a hard error.
3. Create `identities.json`; FINANCE must not be the repository owner.
4. `php apps/api/scripts/verify_governance_identities.php` → `READY FOR ACTIVATION`.
5. Apply `main-ruleset.json` instead of `main-ruleset.sole-owner.json`.

Organization migration remains supported and is explicitly deferred; teams
require one, and `owner.type` is currently `User`.

## The activation layer (M29-B)

M29-A stopped in the right place — CODEOWNERS inert, every owner an
`<OWNER:...>` token — but it left an obvious next failure. Somebody eventually
substitutes handles by hand, gets one wrong, uncomments the rules, and the
repository is back to a CODEOWNERS file that reads as configured and resolves to
nobody: the M29-A defect, restored by the act of fixing it.

So substitution has a gate:

```bash
php apps/api/scripts/verify_governance_identities.php
```

It reports one of three states, and there is deliberately no fourth:

| State | Meaning |
|---|---|
| `UNCONFIGURED` | No `identities.json`. **The correct state today** — exits 0 |
| `INCOMPLETE` | A configuration exists and cannot be used. Exits 1 |
| `READY FOR ACTIVATION` | Every identity resolves *locally*. GitHub has still not been asked |

There is no `ACTIVE`. Whether governance is active is a fact about GitHub, and
adding a state for it here would rebuild the original defect one layer up — with
the validator itself doing the asserting.

To supply identities: copy `identities.example.json` to `identities.json`, drop
the `_example` key, replace every `<EXAMPLE:...>` value, and run the script. It
refuses the example three separate ways (the marker, the placeholder prefix, and
the filename), because activating the shipped template by accident is the
mistake with the longest feedback loop — nothing breaks until a real pull request
needs a real reviewer.

Rendering a CODEOWNERS from it is possible and fenced:

```bash
php apps/api/scripts/verify_governance_identities.php \
    --identities=.github/governance/identities.json \
    --render-codeowners=/tmp/CODEOWNERS.proposed
```

Both paths must be explicit, the target may never be the live
`.github/CODEOWNERS`, an existing file is never overwritten, and there is no
`--force`. Read the diff and commit it deliberately.

## What is still missing

These are inputs, not tasks — nobody in this repository can derive them:

1. **Organization decision.** Teams need one. Under a personal account,
   CODEOWNERS may name individual usernames only.
2. **Real reviewer identities** for each `<OWNER:...>` token in `.github/CODEOWNERS`.
3. **A second account with write access.** `required_approving_review_count: 1`
   is unsatisfiable on a single-maintainer repository — GitHub does not allow
   self-approval, so this rule would block every pull request including the
   owner's own.
4. **The release actor(s)** permitted to create `v*.*.*` tags.
5. **An admin credential.** The M29-A session had `admin: false`; every
   governance write returned `403 Resource not accessible by integration`.

M29-B did not resolve any of these — it built the place they go and the gate
they pass through. Items 2 and 4 are now typed inputs with a validator attached
rather than prose in a header comment; they are still unsupplied.
