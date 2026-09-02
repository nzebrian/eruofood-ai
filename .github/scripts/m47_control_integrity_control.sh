#!/usr/bin/env bash
#
# M47 — can the M45 dependency-audit control still turn absent evidence green?
#
# ## The defect this pins
#
# `m45_dependency_audit_control.sh` has two halves. Part A reads YAML and proves
# the validator discriminates. Part B runs the real `npm audit` and
# `composer audit` against the pre- and post-M45 lockfiles, and it is the only
# half that proves anything about the audit itself.
#
# Part B had two defects, and they compounded:
#
#   1. The pre-M45 baseline resolved as `${M45_BASE_REF:-origin/main}`. That was
#      true exactly once — while M45 was an unmerged branch. After the merge,
#      `origin/main` became the POST-M45 state, so "before" and "after" were the
#      same lockfiles and "the pre-M45 lockfile must fail" was unsatisfiable.
#
#   2. The success condition was `live_ok + live_skipped == live_total`, which
#      is satisfied by `live_ok=0, live_skipped=4`. Every live control could
#      skip and the suite still exited 0.
#
# Together they were worse than either alone. CI checks out with
# `fetch-depth: 1`, so `origin/main` did not resolve there at all; extraction
# failed, all four controls were marked skipped, and the suite passed. Verified
# against PR #54's own log:
#
#     -- Part B: the real commands, against real lockfiles --
#       SKIPPED — could not extract the pre-M45 manifests from origin/main.
#     0/4 live audit controls confirmed (4 skipped: endpoint unreachable).
#
# A control written to prove a gate is not vacuous, itself vacuous in CI, inside
# a required context. That is the M44/M45 defect class reproduced in the control
# built to prevent it.
#
# ## What this file does
#
# Cases A, B, D and E are deterministic and need no network: they are the
# regression guard, and they are what must never silently come back. Case C
# needs the real advisory endpoints, and says so rather than assuming.
#
# Usage: .github/scripts/m47_control_integrity_control.sh

set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT" || exit 1

CONTROL=".github/scripts/m45_dependency_audit_control.sh"

passed=0
failed=0
declare -a failures=()

ok()  { printf '  PASS  %s\n' "$1"; passed=$((passed + 1)); }
bad() { printf '  FAIL  %s\n' "$1"; failed=$((failed + 1)); failures+=("$1"); }

fingerprint() { sha256sum "$CONTROL" | cut -d' ' -f1; }

echo "=============================================================================="
echo "M47 — M45 dependency audit control integrity"
echo "  subject: $CONTROL"
echo "=============================================================================="

before="$(fingerprint)"

# ---------------------------------------------------------------------------
# A. A baseline that cannot be resolved must FAIL, not skip.
# ---------------------------------------------------------------------------
printf '\nA) An unresolvable pre-M45 baseline\n'

a_out="$(M45_BASE_REF=0000000000000000000000000000000000000000 \
         COMPOSER_ALLOW_SUPERUSER=1 "$CONTROL" 2>&1)"
a_exit=$?

if [[ "$a_exit" -ne 0 ]]; then
  ok "an unresolvable baseline exits non-zero (got $a_exit)"
else
  bad "an unresolvable baseline exited 0 — absent evidence is passing again"
fi

if grep -q "BASELINE UNRESOLVABLE" <<<"$a_out"; then
  ok "it says the baseline is unresolvable, rather than 'skipped'"
else
  bad "the output does not name the baseline as the cause"
fi

if grep -q "PART B INCOMPLETE" <<<"$a_out"; then
  ok "it declares Part B incomplete"
else
  bad "Part B incompleteness is not declared"
fi

# ---------------------------------------------------------------------------
# B. Zero live evidence must not produce exit 0 — the false-green core.
#
# The fixture is a copy of everything the control reads with NO `.git`, which
# is the condition CI actually hit: the baseline cannot be extracted by any
# means, so all four live controls produce nothing. Part A still passes inside
# the fixture, so a failure here is attributable to Part B alone. No network is
# required, because the live commands are never reached.
# ---------------------------------------------------------------------------
printf '\nB) Four live controls with no evidence (the CI condition)\n'

b_fixture="$(mktemp -d "${TMPDIR:-/tmp}/m47-nolive-XXXXXXXX")"
mkdir -p "$b_fixture/.github/scripts" "$b_fixture/.github/workflows" \
         "$b_fixture/.github/governance" "$b_fixture/apps/web" "$b_fixture/apps/api"
cp "$CONTROL" .github/scripts/verify_dependency_audit_gate.py "$b_fixture/.github/scripts/"
cp .github/workflows/security.yml "$b_fixture/.github/workflows/"
cp .github/governance/required-checks.json "$b_fixture/.github/governance/"
cp apps/web/package.json apps/web/package-lock.json "$b_fixture/apps/web/"
cp apps/api/composer.json apps/api/composer.lock "$b_fixture/apps/api/"

b_out="$(cd "$b_fixture" && COMPOSER_ALLOW_SUPERUSER=1 \
         .github/scripts/m45_dependency_audit_control.sh 2>&1)"
b_exit=$?
rm -rf "$b_fixture"

if [[ "$b_exit" -ne 0 ]]; then
  ok "no obtainable baseline exits non-zero (got $b_exit)"
else
  bad "exited 0 with no live evidence — this is the pre-M47 false green"
fi

if grep -qE "0/4 live audit controls confirmed" <<<"$b_out"; then
  ok "it reports 0/4 live controls confirmed"
else
  bad "the live-control tally is not reported as 0/4"
fi

# Part A must still have passed, or this case proves nothing about Part B.
if grep -q "12/12 broken properties confirmed" <<<"$b_out"; then
  ok "Part A still passed, so the failure is attributable to Part B"
else
  bad "Part A did not pass in the fixture — case B is not isolating Part B"
fi

# ---------------------------------------------------------------------------
# D. The success condition itself, read structurally.
#
# Cases A and B exercise behaviour. This asserts the shape of the rule, so the
# old arithmetic cannot return in a form that happens to pass today's inputs.
# Comments are stripped first: a validator a comment can trip is one a comment
# can also satisfy, and this file's own history is written in its comments.
# ---------------------------------------------------------------------------
printf '\nD) The success condition requires complete evidence\n'

code="$(grep -vE '^\s*#' "$CONTROL")"

if grep -qE 'live_ok.*-eq.*live_total' <<<"$code"; then
  ok "success requires live_ok == live_total"
else
  bad "success no longer requires every live control to have run"
fi

if grep -qE 'live_unavailable.*-eq.*0' <<<"$code"; then
  ok "success requires zero unavailable live controls"
else
  bad "success no longer requires zero unavailable controls"
fi

if grep -qE 'live_ok \+ live_skipped' <<<"$code"; then
  bad "the pre-M47 arithmetic (live_ok + live_skipped) is back"
else
  ok "the pre-M47 arithmetic is absent"
fi

# ---------------------------------------------------------------------------
# E. The baseline is pinned and has no silent fallback.
# ---------------------------------------------------------------------------
printf '\nE) The baseline is immutable and has no fallback\n'

pinned="$(grep -oE 'M45_BASELINE_COMMIT="[0-9a-f]{40}"' <<<"$code" | head -1)"
if [[ -n "$pinned" ]]; then
  ok "an immutable 40-character baseline commit is pinned"
else
  bad "no pinned 40-character baseline commit found"
fi

if grep -qE 'M45_BASE_REF:-origin/main' <<<"$code"; then
  bad "the baseline still falls back to origin/main, which moves with every merge"
else
  ok "there is no origin/main fallback"
fi

baseline_sha="$(sed -E 's/.*"([0-9a-f]{40})".*/\1/' <<<"$pinned")"
if [[ -n "$baseline_sha" ]] && git cat-file -e "${baseline_sha}^{commit}" 2>/dev/null; then
  if git show "${baseline_sha}:apps/api/composer.lock" >/dev/null 2>&1; then
    ok "the pinned baseline resolves and contains the pre-M45 lockfiles"
  else
    bad "the pinned baseline resolves but has no apps/api/composer.lock"
  fi
else
  bad "the pinned baseline ${baseline_sha:-<none>} does not resolve in this clone"
fi

# ---------------------------------------------------------------------------
# C. Complete evidence can still pass. Needs the real advisory endpoints, and
#    is reported as unproven rather than assumed when they are unreachable.
# ---------------------------------------------------------------------------
printf '\nC) A correct baseline with all four live audits executing\n'

c_out="$(COMPOSER_ALLOW_SUPERUSER=1 "$CONTROL" 2>&1)"
c_exit=$?

if grep -q "UNAVAILABLE (advisory endpoint unreachable" <<<"$c_out"; then
  printf '  UNPROVEN  the advisory endpoints were unreachable on this run.\n'
  printf '            Case C could not be evaluated; A, B, D and E above are\n'
  printf '            deterministic and did run. This is not a pass.\n'
  bad "case C could not be evaluated — live advisory evidence unavailable"
elif [[ "$c_exit" -eq 0 ]] && grep -q "4/4 live audit controls confirmed" <<<"$c_out"; then
  ok "with complete evidence the M45 control passes (4/4 live confirmed)"
else
  bad "complete evidence did not produce a pass (exit $c_exit)"
fi

# ---------------------------------------------------------------------------
printf '\nF) Integrity\n'
if [[ "$before" == "$(fingerprint)" ]]; then
  ok "the M45 control is byte-identical after this run"
else
  bad "the M45 control changed during this run"
fi

echo
echo "=============================================================================="
printf 'RESULT: %d passed, %d failed\n' "$passed" "$failed"
if [[ "$failed" -gt 0 ]]; then
  echo
  printf '  - %s\n' "${failures[@]}"
  echo
  echo "M47 control-integrity checks FAILED."
  exit 1
fi
echo "The M45 control cannot convert absent evidence into a passing required check."
echo "=============================================================================="
exit 0
