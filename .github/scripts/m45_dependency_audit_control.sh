#!/usr/bin/env bash
#
# M45 — does the dependency-audit gate actually discriminate?
#
# ## Why this exists
#
# `verify_dependency_audit_gate.py` printed twelve PASS lines the first time it
# ran. So would a validator whose every check was `True`. A green suite is
# evidence only if the same suite goes red when the property stops holding.
#
# Part A breaks one property at a time inside a throwaway fixture and requires
# the validator to fail **on the check that owns it**. A bare non-zero exit is
# not accepted: the validator exits 1 for any failure and 3 for a misinvocation,
# so a control asserting only "it failed" can pass while its mutation did
# nothing.
#
# ## Part B is the part that matters, and it is not a parse
#
# Everything in Part A reads YAML. A workflow can satisfy every one of those
# checks and still gate on nothing — that was the actual M45 defect, not a
# hypothetical: with no `composer install` in the job and no `--locked`, a bare
# `composer audit` prints "No packages - skipping audit." and exits 0. Unmasking
# it would have changed nothing.
#
# So Part B runs the real commands, exactly as `security.yml` runs them, against
# two sets of real lockfiles: the pre-M45 ones (which carried 11 npm and 7
# Composer advisories) and the post-M45 ones. It requires exit 1 on the first
# and exit 0 on the second. That is the whole claim of this milestone, tested
# rather than asserted.
#
# Part B needs the npm and Packagist advisory endpoints. Where they are
# unreachable — or reachable but unable to answer — it says so and counts the
# control as UNAVAILABLE, which makes Part B incomplete and fails this suite,
# rather than reporting a pass it has not earned.
#
# "Exactly as security.yml runs them" now includes the working directory. Part
# B originally ran `npm audit --prefix DIR`, and `--prefix` does not re-root
# the tree that `npm audit` builds — npm resolved the project from the caller's
# cwd instead. The malformed tree that produced was accepted by npm's legacy
# audit endpoint until 2026-09-04, at which point it started returning 400 and
# the defect surfaced: B3 could never pass, and B1 had been passing on an
# endpoint error rather than on the 11 advisories it claimed to measure. See
# the note in `live()`.
#
# ## Why the real repository is never touched
#
# Every mutation happens inside a `mktemp` fixture holding copies of exactly the
# files the validator reads, pointed at with `--repo-root=`. Part B's lockfiles
# are extracted with `git show` into a separate fixture. The real tree is
# fingerprinted with sha256 before and after and the run fails if a byte moved.
#
# Usage: .github/scripts/m45_dependency_audit_control.sh

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT"

VALIDATOR=".github/scripts/verify_dependency_audit_gate.py"

SOURCES=(
  ".github/workflows/security.yml"
  ".github/governance/required-checks.json"
)

confirmed=0
declare -a false_positives=()
declare -a broken=()

fingerprint() {
  local path
  for path in "${SOURCES[@]}" "$VALIDATOR" \
      "apps/web/package.json" "apps/web/package-lock.json" \
      "apps/api/composer.json" "apps/api/composer.lock"; do
    if [[ -f "$path" ]]; then sha256sum "$path"; else echo "ABSENT  $path"; fi
  done | sort | sha256sum | cut -d' ' -f1
}

make_fixture() {
  local fixture path
  fixture="$(mktemp -d "${TMPDIR:-/tmp}/m45-audit-XXXXXXXX")"

  for path in "${SOURCES[@]}"; do
    if [[ -L "$path" ]]; then
      # A symlink copied into a fixture would let a mutation reach through it
      # into the real repository — the exact escape this design prevents.
      echo "refusing to copy a symlink into a fixture: $path" >&2
      return 1
    fi
    mkdir -p "$fixture/$(dirname "$path")"
    cp "$path" "$fixture/$path"
  done

  echo "$fixture"
}

# Replace exactly one occurrence of a literal, or fail loudly. An absent `find`
# string is a hard error rather than a skipped control: a mutation that silently
# did nothing would report the validator as discriminating when it never saw a
# change.
mutate() {
  local fixture="$1" file="$2" find="$3" replace="$4"

  FIXTURE="$fixture" FILE="$file" FIND="$find" REPLACE="$replace" python3 - <<'PY'
import os, sys

path = os.path.join(os.environ['FIXTURE'], os.environ['FILE'])
find, replace = os.environ['FIND'], os.environ['REPLACE']

with open(path, encoding='utf-8') as handle:
    source = handle.read()

count = source.count(find)
if count == 0:
    sys.exit(f"mutation target not found in {os.environ['FILE']}: {find[:80]!r}")
if count > 1:
    sys.exit(f"mutation target is ambiguous ({count} matches) in {os.environ['FILE']}: {find[:80]!r}")

with open(path, 'w', encoding='utf-8') as handle:
    handle.write(source.replace(find, replace, 1))
PY
}

run_validator() {
  local fixture="$1" summary="$1.summary.json"

  set +e
  python3 "$REPO_ROOT/$VALIDATOR" --repo-root="$fixture" --json="$summary" >/dev/null 2>&1
  local exit_code=$?
  set -e

  local ids=""
  if [[ -f "$summary" ]]; then
    ids="$(python3 -c '
import json, sys
with open(sys.argv[1], encoding="utf-8") as handle:
    print(" ".join(f["id"] for f in json.load(handle).get("failures", [])))
' "$summary")"
    rm -f "$summary"
  fi

  echo "$exit_code|$ids"
}

control() {
  local name="$1" expect="$2"
  shift 2

  printf '%-68s' "${name:0:68}"

  local fixture
  if ! fixture="$(make_fixture)"; then
    broken+=("$name: fixture could not be built")
    echo " BROKEN"
    return 0
  fi

  local file find replace
  while [[ $# -gt 0 ]]; do
    file="$1" find="$2" replace="$3"
    shift 3
    if ! mutate "$fixture" "$file" "$find" "$replace" 2>/tmp/m45-mutate-err; then
      broken+=("$name: $(cat /tmp/m45-mutate-err)")
      echo " BROKEN"
      rm -rf "$fixture"
      return 0
    fi
  done

  local result exit_code ids
  result="$(run_validator "$fixture")"
  exit_code="${result%%|*}"
  ids="${result#*|}"
  rm -rf "$fixture"

  if [[ "$exit_code" != "1" ]]; then
    false_positives+=("$name (validator exited $exit_code, expected 1)")
    echo " FALSE POSITIVE"
    return 0
  fi

  if ! grep -qw -- "$expect" <<<"$ids"; then
    false_positives+=("$name (failed on [${ids:-nothing}], expected [$expect])")
    echo " WRONG CHECK"
    return 0
  fi

  confirmed=$((confirmed + 1))
  echo " ok"
}

echo "EruoFood — M45 dependency audit negative controls"
echo "=============================================================================="
echo "Part A breaks one property per disposable fixture; the validator must fail on"
echo "that property's own check. Part B runs the real audits against real lockfiles."
echo

before="$(fingerprint)"
echo "Protected-file fingerprint (before): $before"
echo
echo "-- Part A: the validator discriminates ---------------------------------------"

# ---------------------------------------------------------------------------
# 1-2. The historical defect, restored: `|| true` on each audit in turn.
#
# The npm anchors below match the audit command alone, not the whole `run:`
# line. M48 put a bounded-retry wrapper in front of it, and an anchor that
# included the wrapper would have to be rewritten every time the invocation
# changes — which is how a mutation test quietly stops mutating anything. The
# command is what these tests are about, and it appears exactly once in the
# file, so `mutate` still refuses anything ambiguous.
# ---------------------------------------------------------------------------

control "1. '|| true' restored on npm audit (the pre-M45 state)" \
  "audit.npm_unmasked" \
  ".github/workflows/security.yml" \
  'npm audit --audit-level=high' \
  'npm audit --audit-level=high || true'

control "2. '|| true' restored on composer audit" \
  "audit.composer_unmasked" \
  ".github/workflows/security.yml" \
  '        run: composer audit --locked' \
  '        run: composer audit --locked || true'

# ---------------------------------------------------------------------------
# 3-4. The same masking one level up, where a step-level check would miss it.
# ---------------------------------------------------------------------------

control "3. continue-on-error added to the npm audit step" \
  "audit.npm_unmasked" \
  ".github/workflows/security.yml" \
  '      - name: npm audit (web)
        working-directory: apps/web' \
  '      - name: npm audit (web)
        continue-on-error: true
        working-directory: apps/web'

control "4. continue-on-error added to the whole audit job" \
  "audit.no_continue_on_error" \
  ".github/workflows/security.yml" \
  '  dependency-audit:
    name: Dependency audit' \
  '  dependency-audit:
    continue-on-error: true
    name: Dependency audit'

# ---------------------------------------------------------------------------
# 5. A forced exit 0 — the same defect wearing different clothes.
# ---------------------------------------------------------------------------

control "5. a forced 'exit 0' appended to the composer audit" \
  "audit.composer_unmasked" \
  ".github/workflows/security.yml" \
  '        run: composer audit --locked' \
  '        run: |
          composer audit --locked
          exit 0'

# ---------------------------------------------------------------------------
# 6. The quiet version: the step stays unmasked, the policy is lowered.
# ---------------------------------------------------------------------------

control "6. npm threshold quietly lowered to --audit-level=critical" \
  "audit.npm_threshold_preserved" \
  ".github/workflows/security.yml" \
  'npm audit --audit-level=high' \
  'npm audit --audit-level=critical'

# ---------------------------------------------------------------------------
# 7-8. The commands themselves removed.
# ---------------------------------------------------------------------------

control "7. the npm audit command removed entirely" \
  "audit.npm_step_present" \
  ".github/workflows/security.yml" \
  'npm audit --audit-level=high' \
  'echo "skipped"'

control "8. the composer audit command removed entirely" \
  "audit.composer_step_present" \
  ".github/workflows/security.yml" \
  '        run: composer audit --locked' \
  '        run: echo "skipped"'

# ---------------------------------------------------------------------------
# 9. `--locked` dropped: the vacuity defect. The step still looks unmasked and
#    still names composer audit; with no vendor/ it audits nothing and exits 0.
# ---------------------------------------------------------------------------

control "9. '--locked' dropped, so composer audits an absent vendor tree" \
  "audit.composer_reads_lockfile" \
  ".github/workflows/security.yml" \
  '        run: composer audit --locked' \
  '        run: composer audit'

# ---------------------------------------------------------------------------
# 10-12. The context itself removed, renamed, or skipped.
# ---------------------------------------------------------------------------

control "10. the required job renamed, detaching the ruleset entry" \
  "audit.job_exists" \
  ".github/workflows/security.yml" \
  '    name: Dependency audit' \
  '    name: Dependency audits'

control "11. 'Dependency audit' dropped from required-checks.json" \
  "audit.context_still_required" \
  ".github/governance/required-checks.json" \
  '      "context": "Dependency audit",' \
  '      "context": "Dependency audit (advisory)",'

control "12. the job gated on 'if: false', so it reports as pending not red" \
  "audit.job_not_conditional" \
  ".github/workflows/security.yml" \
  '  dependency-audit:
    name: Dependency audit' \
  '  dependency-audit:
    if: false
    name: Dependency audit'

# ---------------------------------------------------------------------------
# Control 13 — the positive control. Without it every control above is satisfied
# by a validator that fails on everything, including a correct repository.
# ---------------------------------------------------------------------------

printf '%-68s' "13. positive control: an unmutated fixture passes"
positive_ok=0
if positive_fixture="$(make_fixture)"; then
  positive_result="$(run_validator "$positive_fixture")"
  rm -rf "$positive_fixture"
  if [[ "${positive_result%%|*}" == "0" ]]; then
    positive_ok=1
    echo " ok"
  else
    echo " FAILED"
  fi
else
  echo " BROKEN"
fi

# ---------------------------------------------------------------------------
# Part B — the commands, not the YAML.
#
# `security.yml` runs both audits with no install step, so each is reproduced
# here against nothing but the manifests. Pre-M45 lockfiles must fail; post-M45
# lockfiles must pass. Anything else means the gate is not gating.
# ---------------------------------------------------------------------------

echo
echo "-- Part B: the real commands, against real lockfiles --------------------------"

live_ok=0
live_total=4
live_unavailable=0

live_fixture="$(mktemp -d "${TMPDIR:-/tmp}/m45-live-XXXXXXXX")"
mkdir -p "$live_fixture/before/web" "$live_fixture/before/api" \
         "$live_fixture/after/web"  "$live_fixture/after/api"

# The pre-M45 dependency state, pinned (M47).
#
# This was `${M45_BASE_REF:-origin/main}`, which was true exactly once: while
# M45 was an unmerged branch. The moment M45 merged, `origin/main` became the
# POST-M45 state, "before" and "after" resolved to the same lockfiles, and
# "the pre-M45 lockfile must fail the audit" became unsatisfiable — a control
# that invalidated itself by succeeding.
#
# The baseline is now an immutable commit. A commit hash cannot drift, cannot
# be re-pointed, and means the same thing in a shallow CI clone as it does
# here. `M45_BASE_REF` remains available as an explicit override for
# controlled testing, and there is deliberately no fallback: if the baseline
# cannot be resolved, this control fails. It does not skip.
M45_BASELINE_COMMIT="f840e1c0092dd0695ca0fd35bef0bb4bfacbf00d"
BASE="${M45_BASE_REF:-$M45_BASELINE_COMMIT}"

# CI checks out with `fetch-depth: 1`, so the baseline commit is not in the
# clone. That is why Part B silently skipped on every run before M47 — the
# `git show` failed, and a failed extraction was treated as "skipped".
#
# Fetching the one commit is cheaper and narrower than deepening the checkout,
# and keeps the repair inside this script rather than changing a workflow that
# fourteen other things depend on. `actions/checkout` leaves its credentials
# configured, so this needs no token of its own.
baseline_error=""
if ! git cat-file -e "${BASE}^{commit}" 2>/dev/null; then
  if ! git fetch --quiet --depth=1 origin "$BASE" 2>/dev/null \
     || ! git cat-file -e "${BASE}^{commit}" 2>/dev/null; then
    baseline_error="the pre-M45 baseline commit ${BASE} is not present and could not be fetched"
  fi
fi

extract_ok=1
if [[ -n "$baseline_error" ]]; then
  extract_ok=0
else
  for spec in \
    "apps/web/package.json:web/package.json" \
    "apps/web/package-lock.json:web/package-lock.json" \
    "apps/api/composer.json:api/composer.json" \
    "apps/api/composer.lock:api/composer.lock"; do
    src="${spec%%:*}" dst="${spec#*:}"
    if ! git show "$BASE:$src" > "$live_fixture/before/$dst" 2>/dev/null; then
      extract_ok=0
      baseline_error="${BASE} does not contain $src"
    fi
    cp "$src" "$live_fixture/after/$dst"
  done
fi

# Show what the audit actually said whenever the result is not a clean pass.
# Without this the only thing an unexpected result printed was its exit code,
# which is how a broken invocation went unnoticed: `npm audit` exiting 1 for a
# vulnerability and `npm audit` exiting 1 because the endpoint rejected the
# request look identical from the outside.
show_output() {
  local output="$1"
  [[ -z "$output" ]] && return 0
  echo "      --- command output (last 20 lines) ---"
  tail -n 20 <<<"$output" | sed 's/^/      /'
  echo "      --------------------------------------"
}

live() {
  local name="$1" expected="$2" workdir="$3"
  shift 3

  printf '%-68s' "${name:0:68}"

  set +e
  local output exit_code
  # Run from inside the fixture, which is how `security.yml` runs both audits
  # (`working-directory:`). This used to pass `npm audit --prefix DIR` instead,
  # and `--prefix` does not re-root the tree `npm audit` builds: npm resolved
  # the project from the caller's cwd — the repository root, which has no
  # package.json — and posted the resulting empty tree to the legacy
  # `/-/npm/v1/security/audits/quick` endpoint. That endpoint used to tolerate
  # it; on 2026-09-04 it began answering `400 Bad Request — Invalid package
  # tree`, so npm exited 1 with `audit endpoint returned an error`.
  #
  # B3 expects 0 and could therefore never pass. Worse, B1 expects 1 and had
  # been passing on that same error for the wrong reason — the pre-M45
  # lockfile's 11 advisories were never actually what it measured. `cd` gives
  # the real answers: 11 vulnerabilities (exit 1) before, 0 (exit 0) after.
  #
  # The `cd` runs inside a command substitution, so it is a subshell and cannot
  # move the caller. Composer keeps `--working-dir=`, which does re-root
  # correctly and which B2/B4 have always exercised properly.
  output="$(cd "$workdir" && "$@" 2>&1)"
  exit_code=$?
  set -e

  # A registry that cannot be reached is not evidence either way — and M47's
  # correction is that "not evidence either way" is not a pass. It is counted
  # as UNAVAILABLE, and any unavailable control makes Part B incomplete, which
  # fails this suite. Absent evidence must never satisfy a required check.
  #
  # The audit *infrastructure* failing is the same thing as the network
  # failing, and is now matched too. This is not leniency: without it, an
  # endpoint error makes every `must FAIL` control exit 1 and be recorded as
  # `ok`, which is precisely the false green found on 2026-09-04. Real
  # advisory output contains none of these strings, so a genuine finding
  # cannot be reclassified by it.
  # Composer reserves exit 100 for a generic failure. `composer audit` answers
  # 0 (clean) or 1 (advisories found) whenever it actually audited, so 100 is
  # never a verdict — it is Packagist not answering. Observed on 2026-09-04 in
  # a sandbox with flaky egress, where it was recorded as a FALSE POSITIVE.
  # Both labels fail this suite, so this changes only which one is true.
  #
  # When the command was `npm_audit_resilient.sh` (M48), none of that guesswork
  # applies: the wrapper has already separated "npm audited and answered" from
  # "npm could not answer" and says so in its exit code — 0 PASS, 1 VULNERABLE,
  # 3 UNAVAILABLE. Its verdict is used verbatim and the heuristic below is
  # skipped, because the heuristic would be *wrong* here: a run that saw a 503,
  # retried, and then found real advisories prints both the 503 banner and the
  # finding, and grepping that combined output would downgrade a genuine
  # vulnerability to "endpoint unreachable". The wrapper checks for a verdict
  # before it checks for an outage precisely so that cannot happen.
  local unavailable=0
  if grep -q "SECURITY AUDIT: " <<<"$output"; then
    # The wrapper answered. 0 and 1 are verdicts; anything else is not.
    [[ "$exit_code" != "0" && "$exit_code" != "1" ]] && unavailable=1
  else
    # A bare command (composer). Fall back to reading the wreckage.
    if [[ "$exit_code" == "100" ]] \
       || grep -qiE "ENOTFOUND|ECONNREFUSED|ETIMEDOUT|EAI_AGAIN|ERR_SOCKET_TIMEOUT|socket timeout|network|could not be fully loaded|SSL connection timeout|audit endpoint returned an error|Invalid package tree|audit (400|401|403|408|409|429|500|502|503|504) " <<<"$output"; then
      unavailable=1
    fi
  fi

  if [[ "$unavailable" == "1" ]]; then
    live_unavailable=$((live_unavailable + 1))
    # The wording is load-bearing: m47_control_integrity_control.sh greps for
    # "UNAVAILABLE (advisory endpoint unreachable" to recognise this state.
    # Rewording it here would silently detach that control's case C.
    echo " UNAVAILABLE (advisory endpoint unreachable — evidence missing)"
    show_output "$output"
    return 0
  fi

  if [[ "$exit_code" == "$expected" ]]; then
    live_ok=$((live_ok + 1))
    echo " ok"
  else
    false_positives+=("$name (exit $exit_code, expected $expected)")
    echo " FAILED (exit $exit_code, expected $expected)"
    show_output "$output"
  fi
}

if [[ "$extract_ok" -ne 1 ]]; then
  # M47: this printed "SKIPPED" and set live_skipped=live_total, which the old
  # success condition then accepted. A baseline that cannot be resolved is a
  # broken control, not an excused one.
  echo "  BASELINE UNRESOLVABLE — ${baseline_error}."
  echo "  Part B cannot run without the pre-M45 lockfiles; this is a failure."
  live_unavailable=$live_total
else
  # Both npm controls go through the same wrapper `security.yml` uses, so what
  # is measured here is the gate as deployed rather than a lookalike.
  #
  # The budget is deliberately tighter than the production gate's. This suite
  # runs as one step of `CI · Workflow Integrity`, a job with a ten-minute cap,
  # and on 2026-09-04 that cap was reached: npm's own retry behaviour spent nine
  # minutes on B3 alone and the job was killed mid-step, taking four later
  # controls with it. Two attempts at 40s plus one 5s backoff bounds each
  # control at 85s, so Part B cannot consume the job again. A healthy audit
  # still returns in a couple of seconds; nothing sleeps on success.
  live "B1. pre-M45 lockfile: npm audit --audit-level=high must FAIL" 1 \
    "$live_fixture/before/web" \
    env NPM_AUDIT_ATTEMPTS=2 NPM_AUDIT_TIMEOUT=40 NPM_AUDIT_BACKOFF=5 \
    "$REPO_ROOT/.github/scripts/npm_audit_resilient.sh" npm audit --audit-level=high
  live "B2. pre-M45 lockfile: composer audit --locked must FAIL" 1 \
    "$REPO_ROOT" \
    composer audit --locked --no-interaction --working-dir="$live_fixture/before/api"
  live "B3. post-M45 lockfile: npm audit --audit-level=high must PASS" 0 \
    "$live_fixture/after/web" \
    env NPM_AUDIT_ATTEMPTS=2 NPM_AUDIT_TIMEOUT=40 NPM_AUDIT_BACKOFF=5 \
    "$REPO_ROOT/.github/scripts/npm_audit_resilient.sh" npm audit --audit-level=high
  live "B4. post-M45 lockfile: composer audit --locked must PASS" 0 \
    "$REPO_ROOT" \
    composer audit --locked --no-interaction --working-dir="$live_fixture/after/api"
fi

rm -rf "$live_fixture"

# Control 14 — integrity. Everything above read or copied; nothing wrote.
echo
printf '%-68s' "14. sha256 integrity: the real repository is unchanged"
after_print="$(fingerprint)"
integrity_ok=0
if [[ "$before" == "$after_print" ]]; then integrity_ok=1; echo " ok"; else echo " FAILED"; fi

echo
echo "Protected-file fingerprint (after):  $after_print"
echo
echo "=============================================================================="
printf '%d/12 broken properties confirmed by the check that owns them.\n' "$confirmed"
printf '%d/%d live audit controls confirmed' "$live_ok" "$live_total"
if [[ "$live_unavailable" -gt 0 ]]; then
  printf ' (%d UNAVAILABLE — evidence missing)' "$live_unavailable"
fi
echo "."

if [[ ${#broken[@]} -gt 0 ]]; then
  echo
  echo "BROKEN CONTROLS (the control itself needs updating):"
  printf '  - %s\n' "${broken[@]}"
fi

if [[ ${#false_positives[@]} -gt 0 ]]; then
  echo
  echo "FALSE POSITIVES — the gate did not discriminate:"
  printf '  - %s\n' "${false_positives[@]}"
fi

if [[ "$integrity_ok" -ne 1 ]]; then
  echo
  echo "INTEGRITY FAILURE — the real repository changed during this run."
fi

if [[ "$live_unavailable" -gt 0 ]]; then
  echo
  echo "PART B INCOMPLETE — REQUIRED LIVE AUDIT EVIDENCE UNAVAILABLE"
  echo "  ${live_unavailable}/${live_total} live audit control(s) produced no evidence."
  echo "  Part A proves the validator can read YAML. Only Part B proves the gate"
  echo "  actually fails on a vulnerable lockfile and passes on a clean one, and"
  echo "  without it this suite is asserting nothing about the audit itself."
fi

# M47. This condition previously read `live_ok + live_skipped == live_total`,
# which is satisfied by live_ok=0, live_skipped=4 — every live control skipped,
# suite green. That is exactly the defect class the suite exists to catch: a
# green tick over work that never happened. Complete evidence is now required.
if [[ "$confirmed" -eq 12 && ${#broken[@]} -eq 0 && ${#false_positives[@]} -eq 0 \
      && "$positive_ok" -eq 1 && "$integrity_ok" -eq 1 \
      && "$live_ok" -eq "$live_total" && "$live_unavailable" -eq 0 ]]; then
  echo
  echo "The audit gate fails closed on the old lockfiles, passes on the new ones,"
  echo "and the working tree is unchanged."
  exit 0
fi

echo
echo "M45 dependency audit negative controls FAILED."
exit 1
