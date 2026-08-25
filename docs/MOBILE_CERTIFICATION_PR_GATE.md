# Mobile Certification PR Gate

`GA Flutter Certification` (`.github/workflows/ga-flutter-certification.yml`)
builds the Android and iOS applications. As of M32 it runs on **every pull
request**.

## What was wrong

The workflow had no `pull_request` trigger at all. Its only automatic trigger
was `push` to `main` and `develop`, so the platform builds validated `main`
*after* a merge and never once ran on a pull request — zero `pull_request` runs
in the workflow's entire history.

The cost of that was not theoretical. Both jobs were **red on `main` from
2026-08-04 to 2026-08-24** — three consecutive failed runs across twenty days —
without blocking a single merge, because nothing was waiting on them. M31 fixed
the underlying cause (the Android and iOS host projects did not exist). M32
fixes the reason nobody noticed for three weeks.

At the time of writing, six of the eighteen open pull requests modify
`apps/mobile/`, including dependency bumps that change `pubspec.yaml` **and**
`pubspec.lock`. None of them had a platform build against it.

## The trigger

```yaml
on:
  workflow_dispatch:
  pull_request:            # no paths, no paths-ignore — deliberately
  push:
    branches: [main, develop]
    paths:
      - "apps/mobile/**"
      - ".github/workflows/ga-flutter-certification.yml"
  workflow_call: {}
```

### Why `pull_request` is not filtered

It is the whole point, and it is not an oversight.

GitHub treats a required status check that never reports as **pending, not
satisfied**. A path-filtered trigger means a pull request touching no mobile
file never produces a conclusion — so the moment anybody required these
contexts, every such pull request would wait forever, with no error message
anywhere. Today that is **twelve of eighteen** open pull requests.

This is the same trap `.github/governance/required-checks.json` records from
M29-A, which removed exactly these filters from `ci-api`, `ci-web`, `contracts`
and `ci-docker`, and that M29-I removed from `workflow-integrity`. M32 is the
same fix applied to the last workflow that still had it.

Running unconditionally is what makes these checks *safe to require later*. The
cost is one Ubuntu job and one macOS job per pull request — roughly four and two
minutes respectively on the M31 runs.

`push` stays filtered. Nothing waits on a post-merge run, so narrowing it is
free.

### Permissions

```yaml
permissions:
  contents: read
```

Both jobs check out the repository, run a toolchain and build. They need
nothing else, and the scope matters more now that the workflow runs on every
pull request — a certification job holding write scope is a strange thing to
hand a future contributor's branch. Artifact upload is unaffected:
`actions/upload-artifact` authenticates with the runtime token, not the
`contents` scope.

## These checks are NOT required

**`Android · doctor · analyze · test · build apk` and `iOS · analyze · test ·
build (no codesign)` are advisory.** So is `Analyse · Test` from
`ci-mobile.yml`. None of them appears in `.github/governance/required-checks.json`
or in ruleset `21203909`, and M32 changed neither.

The required set remains the same eight contexts M29 activated. A pull request
can still merge with a red mobile build.

**Making them required is deliberately deferred to a future owner-approved
governance milestone**, for two reasons:

1. It edits a live ruleset, which is an owner action — the automation running
   this milestone holds `admin: false`.
2. It must not be done while any mobile trigger is path-filtered. `ci-mobile.yml`
   still filters its `pull_request` trigger by path, so requiring `Analyse ·
   Test` today would deadlock unrelated pull requests exactly as described
   above. That filter would have to go first.

Nothing in this document should be read as a claim that mobile certification
gates a merge. It does not. It now *runs*, which is the prerequisite.

## What the validator asserts

`apps/mobile/scripts/verify_platform_foundation.sh` section **G2** (36 checks
total, up from M31's 28):

| Assertion | Why |
|---|---|
| `pull_request` exists | without it the gate cannot run before a merge |
| `pull_request` has no `paths` | a filter here is the deadlock-maker |
| `pull_request` has no `paths-ignore` | same deadlock, different spelling |
| `push` exists | otherwise `main` stops being certified after merge |
| `push` is `[main, develop]` only | widening it changes what post-merge means |
| `push` keeps its path filter | keeps post-merge runs narrow |
| `workflow_dispatch` exists | manual runs against a branch |
| `workflow_call` exists | `ga-release-certification.yml` consumes this workflow |

The trigger block is parsed as YAML rather than grepped. `on:` is a YAML boolean
in disguise, and a `paths:` nested under `pull_request` is invisible to a
line-oriented search that only knows the word appears somewhere in the file.

Section G (from M31) continues to assert that both build commands survive, that
no step is `continue-on-error`, that the APK artifact stays mandatory, and that
analyze stays `--fatal-infos --fatal-warnings`.

## Negative controls

`apps/mobile/scripts/m31_platform_negative_control.sh` — 24 controls, up from
M31's 16. M32 adds eight:

| # | Breaks | Must be |
|---|---|---|
| 9f | removes `pull_request` | detected |
| 9g | adds `paths:` under `pull_request` | detected |
| 9h | adds `paths-ignore:` under `pull_request` | detected |
| 9i | removes `workflow_dispatch` | detected |
| 9j | removes `workflow_call` | detected |
| 9k | removes `push` | detected |
| 9l | widens `push` branches | detected |
| 9m | removes the `push` path filter | detected |

Control **10** is the control on the controls: an untouched copy must still
pass, so a validator that rejects everything cannot pass for one that works.
Control **11** sha256s the certification workflow *and* the platform tree *and*
the protected files before and after the run — widened in M32, because the
suite now edits the workflow inside its fixtures.

Every fixture is a throwaway miniature repository under `mktemp -d`. The real
working tree is never modified.

## The self-demonstrating test

M32's own pull request changes `.github/workflows/` and **not**
`apps/mobile/**`. Under the old trigger it would have produced no mobile checks
at all. Under the new one it must produce both — which makes the pull request
its own first proof that the gate works, and its own proof that an unrelated
pull request is not left waiting.
