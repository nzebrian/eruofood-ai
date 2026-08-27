#!/usr/bin/env bash
# =============================================================================
# M37 Phase 4B — does the Governance Advisory wiring control actually bite?
#
# `governance_advisory_wiring.py` currently reports zero findings. That is the
# state in which a vacuous control is invisible: it passes because the wiring
# is fine, and it would also pass if it were checking nothing at all. So this
# breaks the wiring one way at a time, inside a throwaway fixture, and requires
# the matching finding BY CODE.
#
# By code, not by exit status. The analyser exits 1 for nineteen different
# reasons; a control that only knows "it went non-zero" cannot tell whether it
# caught the defect it planted or tripped over an unrelated one. M28 found a
# five-provider adapter sweep that had been testing one adapter five times and
# had been green throughout, which is what that mistake looks like in practice.
#
# Every mutation happens in a unique mktemp copy of `.github`. The real tree is
# sha256-fingerprinted before and after and never written to. Cleanup is
# best-effort via trap; the fingerprint is what actually proves safety, because
# a trap does not run on SIGKILL and `finally` does not run on a fatal error —
# the same reasoning that moved the M29 suites off the real repository in
# Phase 3.
#
# Run: .github/scripts/m37_governance_advisory_control.sh
# =============================================================================

set -uo pipefail

REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
ANALYSER="$REPO_ROOT/.github/scripts/governance_advisory_wiring.py"
ADVISORY_REL=".github/workflows/governance-advisory.yml"
INTEGRITY_REL=".github/workflows/workflow-integrity.yml"
CHECKS_REL=".github/governance/required-checks.json"

if [[ -t 1 ]]; then
    GREEN=$'\033[32m'; RED=$'\033[31m'; OFF=$'\033[0m'
else
    GREEN=''; RED=''; OFF=''
fi

passed=0
failed=0

ok()   { printf '  %s✔%s  %s\n' "$GREEN" "$OFF" "$1"; passed=$((passed + 1)); }
bad()  { printf '  %s✘%s  %s\n' "$RED" "$OFF" "$1"; failed=$((failed + 1)); }

# Everything the controls are forbidden to modify.
fingerprint() {
    find "$REPO_ROOT/.github" -type f -print0 \
        | sort -z \
        | xargs -0 sha256sum \
        | sha256sum \
        | cut -d' ' -f1
}

FIXTURES=()

# shellcheck disable=SC2317  # invoked by the EXIT trap below, not lexically
cleanup() {
    local d
    for d in "${FIXTURES[@]:-}"; do
        [[ -n "$d" && -d "$d" ]] && rm -rf "$d"
    done
}
trap cleanup EXIT

new_fixture() {
    local d
    d="$(mktemp -d "${TMPDIR:-/tmp}/m37-advisory-XXXXXXXXXXXX")"
    chmod 700 "$d"
    cp -r "$REPO_ROOT/.github" "$d/.github"
    FIXTURES+=("$d")
    printf '%s' "$d"
}

# Refuse to run the analyser against a fixture that can reach outside itself.
assert_contained() {
    local root="$1" link
    while IFS= read -r link; do
        printf 'fixture contains a symlink: %s\n' "$link" >&2
        return 1
    done < <(find "$root" -type l)
    return 0
}

# A control passes only when the SPECIFIC expected finding code is reported.
control() {
    local description="$1" expected="$2" mutate="$3"
    local fixture out code

    fixture="$(new_fixture)"

    if ! assert_contained "$fixture"; then
        bad "$description · fixture containment check refused this fixture"
        rm -rf "$fixture"
        return
    fi

    if ! ( cd "$fixture" && eval "$mutate" ); then
        bad "$description · the mutation itself failed to apply"
        rm -rf "$fixture"
        return
    fi

    out="$(python3 "$ANALYSER" --repo-root="$fixture" 2>&1)"
    code=$?

    if [[ $code -eq 0 ]]; then
        bad "$description · NOT DETECTED (analyser reported 0 findings — FALSE NEGATIVE)"
    elif grep -qE "^  FINDING ${expected}( |\$)" <<<"$out"; then
        ok "$description → ${expected}"
    else
        bad "$description · detected, but not as ${expected}:"
        grep -E '^  FINDING' <<<"$out" | sed 's/^/        /'
    fi

    rm -rf "$fixture"
}

# -- Targeted, comment-preserving edits ---------------------------------------
#
# Rewriting the YAML through a parser would strip every comment and reorder the
# document, so a mutation would differ from the real file in ways that have
# nothing to do with the defect being planted. These do a literal substring
# replacement and fail loudly if the anchor is not found exactly once.
# shellcheck disable=SC2317  # invoked from the eval'd mutation strings below
py_sub() {
    python3 - "$1" "$2" "$3" <<'PY'
import sys
path, old, new = sys.argv[1], sys.argv[2], sys.argv[3]
body = open(path, encoding='utf-8').read()
if body.count(old) != 1:
    sys.exit(f"anchor appears {body.count(old)} times, expected exactly 1: {old!r}")
open(path, 'w', encoding='utf-8').write(body.replace(old, new))
PY
}

printf '%s\n' "========================================================================"
printf '%s\n' "M37 PHASE 4B — GOVERNANCE ADVISORY WIRING NEGATIVE CONTROLS"
printf '  repo root: %s\n' "$REPO_ROOT"
printf '  fixtures:  unique mktemp copies of .github; the real tree is read-only\n'
printf '%s\n' "========================================================================"
printf '\n'

FINGERPRINT_BEFORE="$(fingerprint)"

printf 'A) The job stops being able to fail\n'

control 'continue-on-error is added to the ratchet step' \
    'ADVISORY_CONTINUE_ON_ERROR' \
    "py_sub '$ADVISORY_REL' '      - name: Known-gap ratchet' '      - name: Known-gap ratchet
        continue-on-error: true'"

control '|| true is appended to the ratchet command' \
    'ADVISORY_SHELL_MASKING' \
    "py_sub '$ADVISORY_REL' '            | tee \"\${EVIDENCE}/ratchet.txt\"' '            | tee \"\${EVIDENCE}/ratchet.txt\" || true'"

control 'the ratchet step is made to force exit 0' \
    'ADVISORY_FORCED_EXIT_ZERO' \
    "py_sub '$ADVISORY_REL' '            | tee \"\${EVIDENCE}/ratchet.txt\"' '            | tee \"\${EVIDENCE}/ratchet.txt\"
          exit 0'"

control 'the ratchet step captures its own exit status' \
    'ADVISORY_RATCHET_EXIT_CAPTURED' \
    "py_sub '$ADVISORY_REL' '            | tee \"\${EVIDENCE}/ratchet.txt\"' '            | tee \"\${EVIDENCE}/ratchet.txt\" || verdict=\$?'"

control 'the ratchet step is made conditional' \
    'ADVISORY_ALWAYS_MASKING' \
    "py_sub '$ADVISORY_REL' '      - name: Known-gap ratchet' '      - name: Known-gap ratchet
        if: always()'"

control 'set +e is added to the validator step' \
    'ADVISORY_SHELL_MASKING' \
    "py_sub '$ADVISORY_REL' '          set -euo pipefail
          args=(' '          set +e
          args=('"

printf '\n'
printf 'B) The job stops doing the work\n'

control 'the evidence fetch is removed' \
    'ADVISORY_FETCH_MISSING' \
    "py_sub '$ADVISORY_REL' 'https://api.github.com/repos/\${GITHUB_REPOSITORY}/\${path}' 'https://example.invalid/nothing'"

control 'the validator invocation is removed' \
    'ADVISORY_VALIDATOR_MISSING' \
    "py_sub '$ADVISORY_REL' 'php scripts/verify_repository_governance.php \"\${args[@]}\"' 'true \"\${args[@]}\"'"

control 'the ratchet invocation is removed' \
    'ADVISORY_RATCHET_MISSING' \
    "py_sub '$ADVISORY_REL' 'php scripts/governance_ratchet.php' 'php scripts/ratchet_disabled.php'"

control 'the validator loses --json, forcing console scraping' \
    'ADVISORY_JSON_MISSING' \
    "py_sub '$ADVISORY_REL' 'args=(--mode=advisory --json=\"\${EVIDENCE}/summary.json\")' 'args=(--mode=advisory)'"

printf '\n'
printf 'C) The context stops being what it claims to be\n'

control 'the advisory workflow is deleted' \
    'ADVISORY_WORKFLOW_MISSING' \
    "rm -f '$ADVISORY_REL'"

control 'the job is renamed' \
    'ADVISORY_JOB_NAME_WRONG' \
    "py_sub '$ADVISORY_REL' '    name: Governance Advisory' '    name: Governance Advisory (non-blocking)'"

control 'the job name collides with a required context' \
    'ADVISORY_JOB_NAME_COLLIDES' \
    "py_sub '$ADVISORY_REL' '    name: Governance Advisory' '    name: CI · Workflow Integrity'"

control 'the advisory context is added to the required set' \
    'ADVISORY_CONTEXT_REQUIRED' \
    "python3 -c \"
import json
p='$CHECKS_REL'
d=json.load(open(p))
d['required'].append({'context':'Governance Advisory','workflow':'$ADVISORY_REL'})
json.dump(d,open(p,'w'),indent=2)\""

control 'the deliberately-not-required record is deleted' \
    'ADVISORY_NOT_RECORDED' \
    "python3 -c \"
import json
p='$CHECKS_REL'
d=json.load(open(p))
d['deliberately_not_required']=[r for r in d['deliberately_not_required'] if r.get('context')!='Governance Advisory']
json.dump(d,open(p,'w'),indent=2)\""

control 'a required context is dropped from the record' \
    'REQUIRED_CONTEXTS_CHANGED' \
    "python3 -c \"
import json
p='$CHECKS_REL'
d=json.load(open(p))
d['required'].pop()
json.dump(d,open(p,'w'),indent=2)\""

printf '\n'
printf 'D) The triggers stop making the check requirable\n'

control 'pull_request is path-filtered' \
    'ADVISORY_PR_PATH_FILTERED' \
    "py_sub '$ADVISORY_REL' '  pull_request:

  push:' '  pull_request:
    paths: [\".github/governance/**\"]

  push:'"

control 'the drift schedule is removed' \
    'ADVISORY_SCHEDULE_MISSING' \
    "py_sub '$ADVISORY_REL' '  schedule:' '  _schedule_disabled:'"

printf '\n'
printf 'E) Blast radius onto the required gate\n'

control 'write permission is granted to the advisory job' \
    'ADVISORY_PERMISSIONS' \
    "py_sub '$ADVISORY_REL' 'permissions:
  contents: read' 'permissions:
  contents: write'"

control 'advisory logic leaks into CI · Workflow Integrity' \
    'INTEGRITY_WORKFLOW_CHANGED' \
    "py_sub '$INTEGRITY_REL' '      - name: Validate every workflow' '      - name: Install PHP dependencies
        run: composer install --no-dev

      - name: Validate every workflow'"

control 'the required integrity job is renamed' \
    'INTEGRITY_JOB_RENAMED' \
    "py_sub '$INTEGRITY_REL' '    name: CI · Workflow Integrity' '    name: Workflow Integrity'"

# -- The controls on the controls ---------------------------------------------

printf '\n'
printf 'F) Controls on the controls\n'

# Positive control. If an untouched fixture reported findings, every result
# above would be meaningless — they would all be "detecting" the copy itself.
positive_fixture="$(new_fixture)"
if python3 "$ANALYSER" --repo-root="$positive_fixture" >/dev/null 2>&1; then
    ok 'positive control · an unmutated fixture reports zero findings'
else
    bad 'positive control · an UNMUTATED fixture reported findings — every control above is suspect'
    python3 "$ANALYSER" --repo-root="$positive_fixture" 2>&1 | grep -E '^  FINDING' | sed 's/^/        /'
fi
rm -rf "$positive_fixture"

# Fixture-completeness control. A fixture missing the files under test would
# make some controls "pass" for the wrong reason.
incomplete_fixture="$(new_fixture)"
rm -rf "$incomplete_fixture/.github/governance"
# Captured before matching: with `pipefail` a pipeline reports the analyser's
# non-zero exit even when grep found what it was looking for, so the obvious
# `analyser | grep -q` spelling reads a successful match as a failure.
incomplete_out="$(python3 "$ANALYSER" --repo-root="$incomplete_fixture" 2>&1)"
if grep -q 'REQUIRED_CHECKS_MISSING' <<<"$incomplete_out"; then
    ok 'completeness control · a fixture missing required-checks.json fails loudly'
else
    bad 'completeness control · a fixture missing required-checks.json did NOT fail'
    printf '%s\n' "$incomplete_out" | sed 's/^/        /'
fi
rm -rf "$incomplete_fixture"

# Containment control. The whole design rests on mutations being unable to
# reach the real repository, so that assertion is itself tested.
symlink_fixture="$(new_fixture)"
ln -s "$REPO_ROOT/.github/governance/known-gaps.json" "$symlink_fixture/.github/escape.json"
if assert_contained "$symlink_fixture" >/dev/null 2>&1; then
    bad 'containment control · a symlink out of the fixture was NOT refused'
else
    ok 'containment control · a link pointing out of the fixture is refused'
fi
rm -rf "$symlink_fixture"

# -- Real-tree integrity ------------------------------------------------------

FINGERPRINT_AFTER="$(fingerprint)"

printf '\n'
if [[ "$FINGERPRINT_BEFORE" == "$FINGERPRINT_AFTER" ]]; then
    ok "integrity · .github is byte-identical after the suite (${FINGERPRINT_BEFORE:0:16}…)"
else
    bad 'integrity · THE REAL .github TREE CHANGED — a mutation escaped its fixture'
    printf '        before=%s\n        after =%s\n' "$FINGERPRINT_BEFORE" "$FINGERPRINT_AFTER"
fi

# -- Result -------------------------------------------------------------------

printf '\n%s\n' "========================================================================"
if [[ $failed -eq 0 ]]; then
    printf 'RESULT: %d passed, 0 failed — the advisory job cannot be quietly neutered.\n' "$passed"
else
    printf 'RESULT: %d passed, %d FAILED.\n' "$passed" "$failed"
fi
printf '%s\n' "========================================================================"

exit $(( failed == 0 ? 0 : 1 ))
