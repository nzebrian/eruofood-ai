# Repository Governance

Prepared by **M29-A**. **Nothing here has been applied.**

Every file in this directory describes protection that GitHub is *not* currently
enforcing. They exist so that when an administrator with the right credential
and the missing identities sits down, the work is a transcription rather than a
design exercise.

## What the audit found

Read live from the GitHub API on 2026-08-21 against `main` at `cbdc2ab`:

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

Two consequences worth stating plainly, because they are easy to read past:

**Every check on PR #21 was advisory.** M28 merged with five green checks, and it
would have merged with five red ones. The gates that M27 and M28 spent their
effort building are, at this moment, decoration.

**A production release is one `git push --tags` away.** `release.yml` triggers on
`v*.*.*` and ends by promoting a container image. Nothing restricts who may
create that tag.

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
