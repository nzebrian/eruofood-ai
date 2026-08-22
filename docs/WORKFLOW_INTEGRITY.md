# Workflow Integrity Gate

`CI · Workflow Integrity` (`.github/workflows/workflow-integrity.yml`) validates
the repository's GitHub Actions workflows with
[actionlint](https://github.com/rhysd/actionlint).

## Why it exists

Every other part of this repository is checked by CI. Until M29-E, the workflows
themselves were not — and it cost the project its production release gate.

`.github/workflows/release.yml` was invalid YAML from **2026-08-04 until M29-D**.
One line:

```yaml
env: { GITHUB_TOKEN: ${{ github.token }} }
```

Inside a YAML flow mapping the `{` that opens `${{` is read as the start of a
nested mapping, so the document is not valid YAML. GitHub could not parse the
file, could not load the workflow, and therefore could not evaluate its
triggers — which is why *branch* pushes produced runs at all when the only
trigger was `push: tags: ["v*.*.*"]`.

Thirty-odd runs, on `main` and on every dependabot branch, each finishing in the
same second with **zero jobs**.

The reason nobody noticed for two weeks is the shape of the symptom. In the
Actions tab, a workflow that cannot be parsed looks exactly like a gate that ran
and failed. The file described itself as a MANDATORY production gate the whole
time, and `.github/governance/production-tags-ruleset.json` repeated the claim.
Both were describing something that could not start.

**The lesson is not "quote your expressions". It is that a broken gate and a
failing gate are indistinguishable from the outside, so something has to ask
whether the gate can run at all.**

## What it validates

`actionlint` is run against **every** file in `.github/workflows`, not only the
ones a pull request touched. A change to one workflow can invalidate another —
a renamed job another workflow lists in `needs`, a changed reusable-workflow
signature — and the defect that prompted this had been sitting in an untouched
file for months.

It covers three classes of defect:

| Class | Example | Would a YAML parser catch it? |
|---|---|---|
| YAML syntax | the flow-mapping expression above | yes |
| Actions semantics | `needs: [job-that-does-not-exist]` | **no** |
| Expression contexts | `${{ github.no_such_property }}` | **no** |

It also runs `shellcheck` over `run:` blocks and validates action inputs, glob
patterns, `runs-on` labels and `if:` conditions.

## Triggers

| Event | Condition |
|---|---|
| `pull_request` | **every pull request** — no path filter (M29-I) |
| `push` to `main` | any change under `.github/workflows/**` |

### Why `pull_request` is not filtered

It was, originally. That was correct for a check nobody required and wrong the
moment anybody wanted to.

GitHub treats a required status check that never reports as **pending, not
satisfied**. A required, path-filtered check leaves every pull request that does
*not* touch `.github/workflows/**` waiting forever for a conclusion that is never
coming, with no error message anywhere. M29-A removed exactly these filters from
ci-api, ci-web, contracts and ci-docker for that reason; M29-I removed this one
for the same reason.

The job downloads one 2 MB binary and lints thirteen files — six seconds on the
run that merged it. Running it on every pull request costs almost nothing and
buys a check that can actually be required.

`push` stays filtered. Nothing waits on a post-merge run, so narrowing it is free.

**`CI · Workflow Integrity` is a required check** as of M29-I. It is listed in
`.github/governance/required-checks.json` alongside the other seven contexts.

The job name was aligned to the workflow name in the same change. GitHub matches
a required status check on the **job** name, so requiring the string
`CI · Workflow Integrity` while the job was called `Validate · actionlint` would
have produced a check that never reports — permanently pending on every pull
request. Once a ruleset requires it, that string is load-bearing: renaming the
job silently detaches the rule.

Required is still not enforced. No ruleset exists on this repository yet, so
every check remains advisory until an administrator applies one.

## Permissions

```yaml
permissions:
  contents: read
```

Read-only, at the workflow level, with no job-level widening. The job reads
files and runs a linter; it needs nothing else. A workflow-validation job
holding write scope would be a strange thing to hand a future contributor's
pull request, and `WorkflowIntegrityGuardTest` asserts that `packages:`,
`id-token:`, `contents: write` and `pull-requests: write` are all absent.

## Supply chain

`actionlint` is pinned to an exact version and its archive is checksum-verified
against the project's published `checksums.txt` **before extraction**, so an
archive that fails the check is never unpacked and nothing from it can run.

```yaml
ACTIONLINT_VERSION: "1.7.7"
ACTIONLINT_SHA256: "023070a287cd8cccd71515fedc843f1985bf96c436b7effaecce67290e7e0757"
```

A floating tag would mean the gate guarding the workflows was itself fetched
from a moving target.

### Upgrading

1. Read the release notes; new actionlint versions add rules, so a bump can turn
   a previously-green repository red. That is the tool working.
2. Fetch the official checksum:
   ```bash
   curl -sSL https://github.com/rhysd/actionlint/releases/download/vX.Y.Z/actionlint_X.Y.Z_checksums.txt \
     | grep linux_amd64
   ```
3. Update both `ACTIONLINT_VERSION` and `ACTIONLINT_SHA256` together. Never one
   without the other — a stale checksum fails closed, which is safe, but a
   removed one fails open, which is not.
4. Re-run the negative control.

## Negative control

```bash
.github/scripts/workflow_integrity_negative_control.sh
# or, with a local binary:
ACTIONLINT=/path/to/actionlint .github/scripts/workflow_integrity_negative_control.sh
```

It runs as the final step of the gate, and exists because a validator whose
subject is already clean passes for two indistinguishable reasons: it works, or
it checks nothing. M28 found a five-adapter test sweep that had been exercising
one adapter five times while green throughout.

Five controls:

| # | Fixture | Must be |
|---|---|---|
| 1 | the historical `release.yml` defect, verbatim | rejected `[syntax-check]` |
| 2 | `needs:` naming a job that does not exist | rejected `[job-needs]` |
| 3 | `${{ github.no_such_property }}` | rejected `[expression]` |
| 4 | a well-formed workflow | **accepted** |
| 5 | `.github/workflows` after the run | byte-identical |

Control 4 is the control on the controls — without it the suite cannot tell a
working validator from one that rejects everything handed to it. Control 5 is
verified by sha256 before and after, not asserted: a control that damaged what
it was protecting would otherwise still print a tidy pass.

Every fixture is written inside `mktemp -d`, never into `.github/workflows`, and
removed by an `EXIT` trap so an interrupted run leaves nothing behind.

Rejections are matched on the **rule name** rather than on "some failure", so a
fixture that breaks for an unrelated reason is not counted as proof.

## Expected failure behaviour

| Situation | Result |
|---|---|
| A workflow has invalid YAML | job fails; actionlint prints file, line, column and the offending source |
| A workflow is valid YAML but invalid Actions | job fails, with the rule name in brackets |
| actionlint's checksum does not match | job fails **before extraction**; nothing from the archive runs |
| The negative control is not rejected | job fails — the gate is not earning its place |
| The pull request touches no workflow | job still runs — there is no `pull_request` path filter, which is what makes it safe to require |

A failing run names the file and line. Reproduce locally with the same pinned
version rather than guessing from the log.

## Related tests

`apps/api/modules/Shared/tests/Feature/WorkflowIntegrityGuardTest.php` — 17
tests. These cover what actionlint cannot: that the gate is *configured* the way
it claims (triggers, minimum permissions, all-workflows rather than
changed-only, pinned and verified linter, negative control wired in), plus a
standing pure-PHP scan for the historical pattern that holds on a developer
machine with no actionlint installed — which is every developer machine in this
repository today.

That scan deliberately duplicates part of actionlint's job. This defect cost the
project its release gate for two weeks; one independent check that needs no
toolchain is cheap insurance.

It skips comment lines, because `release.yml` documents the defect by quoting
it, and a naive scan would fail on the very file that was fixed.

## What this gate does not do

- **It does not make `release.yml` a production gate.** Both image steps are
  `push: false`; the workflow validates a tag and promotes nothing. See
  `docs/PRODUCTION_DEPLOYMENT.md`.
- **It does not restrict who may create a release tag.** That is
  `.github/governance/production-tags-ruleset.json`, which is prepared and
  **not applied**.
- **It is not merge-blocking.** No branch protection or ruleset exists on this
  repository yet, so every check here — this one included — is advisory. It
  becomes merge-blocking only once live GitHub governance is activated per
  `.github/governance/APPLY_GOVERNANCE.md`, and only once it is added to
  `required-checks.json`. The path-filter blocker that previously stood in the
  way was removed in M29-I.
