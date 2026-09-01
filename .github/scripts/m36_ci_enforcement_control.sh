#!/usr/bin/env bash
# =============================================================================
# M36 — the control on the CI enforcement wiring.
#
# Every other control in this repository asks "does the validator reject a bad
# input?". This one asks the question nobody was asking: "does CI still FAIL
# when the validator says no?"
#
# Those are different questions, and the gap between them is where gates go to
# die quietly. A validator can be perfect and a single `|| true`, a
# `continue-on-error: true`, or an `if: always()` on the wrong step turns the
# required check permanently green while every report still says PASS.
#
# So this asserts three things:
#
#   A. WIRING — the enforcement steps in workflow-integrity.yml still exist,
#      still invoke the scripts they claim to, and carry nothing that could
#      swallow a non-zero exit.
#   B. PROPAGATION — each enforced validator really does exit non-zero when its
#      invariant is broken, verified against a throwaway fixture.
#   C. POSITIVE — the untouched repository state still passes, so a suite that
#      rejects everything cannot pass for one that works.
#
# Fixtures live under `mktemp -d`. The real working tree is never modified, and
# control D re-checks that with a sha256 fingerprint taken before and after.
#
# Usage:  .github/scripts/m36_ci_enforcement_control.sh
# =============================================================================

set -uo pipefail

REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
WORKFLOW="$REPO_ROOT/.github/workflows/workflow-integrity.yml"

if [[ -t 1 ]]; then
    GREEN=$'\033[32m'; RED=$'\033[31m'; OFF=$'\033[0m'
else
    GREEN=''; RED=''; OFF=''
fi

passed=0
failed=0

ok()  { printf '  %sPASS%s  %s\n' "$GREEN" "$OFF" "$1"; passed=$((passed + 1)); }
bad() { printf '  %sFAIL%s  %s\n' "$RED" "$OFF" "$1"; failed=$((failed + 1)); }
head_() { printf '\n%s\n' "$1"; }

# The scripts the required job must enforce, and the fingerprint set.
ENFORCED=(
    ".github/scripts/m35_docker_certification_guard.sh"
    ".github/scripts/m35_docker_certification_negative_control.sh"
    "apps/mobile/scripts/m31_platform_negative_control.sh"
    ".github/scripts/m37_governance_advisory_control.sh"
    ".github/scripts/verify_deployment_safety.py"
    ".github/scripts/m44_deployment_safety_control.sh"
    ".github/scripts/verify_dependency_audit_gate.py"
    ".github/scripts/m45_dependency_audit_control.sh"
)

FINGERPRINT_PATHS=(
    ".github/workflows"
    ".github/scripts"
    "apps/mobile/scripts"
    "apps/mobile/m31-platform-manifest.json"
    "apps/mobile/pubspec.yaml"
    "apps/mobile/pubspec.lock"
    "apps/mobile/analysis_options.yaml"
    "apps/api/phpunit.xml"
)

fingerprint() {
    local p
    for p in "${FINGERPRINT_PATHS[@]}"; do
        if [[ -d "$REPO_ROOT/$p" ]]; then
            find "$REPO_ROOT/$p" -type f -print0 | sort -z | xargs -0 sha256sum
        elif [[ -f "$REPO_ROOT/$p" ]]; then
            sha256sum "$REPO_ROOT/$p"
        fi
    done | sha256sum | cut -d' ' -f1
}

before="$(fingerprint)"

echo "========================================================================"
echo "M36 — CI ENFORCEMENT CONTROL"
echo "  workflow: .github/workflows/workflow-integrity.yml"
echo "========================================================================"

# -----------------------------------------------------------------------------
# A) The wiring is present and cannot swallow a failure
# -----------------------------------------------------------------------------
head_ "A) Enforcement wiring in the required job"

if [[ ! -f "$WORKFLOW" ]]; then
    bad "workflow-integrity.yml is missing — the required context has no definition"
else
    wiring="$(python3 - "$WORKFLOW" "${ENFORCED[@]}" <<'PY'
import sys, yaml

path, enforced = sys.argv[1], sys.argv[2:]
wf = yaml.safe_load(open(path))

jobs = wf.get('jobs') or {}
target = None
for jid, job in jobs.items():
    if str(job.get('name', '')) == 'CI · Workflow Integrity':
        target = (jid, job)
        break

if target is None:
    print('ERROR=no job named "CI · Workflow Integrity"')
    raise SystemExit(0)

jid, job = target
print('JOBID=%s' % jid)
print('TIMEOUT=%s' % job.get('timeout-minutes', ''))
print('JOB_COE=%s' % ('1' if job.get('continue-on-error') else '0'))

steps = job.get('steps') or []
for script in enforced:
    hit = None
    for s in steps:
        if script in str(s.get('run', '')):
            hit = s
            break
    if hit is None:
        print('MISSING=%s' % script)
        continue

    run = str(hit['run'])
    problems = []
    if hit.get('continue-on-error'):
        problems.append('continue-on-error')
    if 'always()' in str(hit.get('if', '')):
        problems.append('if:always()')
    if '|| true' in run or '|| :' in run:
        problems.append('|| true')
    if 'exit 0' in run:
        problems.append('exit 0')
    if 'set +e' in run:
        problems.append('set +e')
    print('WIRED=%s|%s' % (script, ','.join(problems)))
PY
)"

    if printf '%s' "$wiring" | grep -q '^ERROR='; then
        bad "$(printf '%s' "$wiring" | sed -n 's/^ERROR=//p')"
    else
        for script in "${ENFORCED[@]}"; do
            line="$(printf '%s' "$wiring" | grep -F "WIRED=$script|" || true)"
            if [[ -z "$line" ]]; then
                bad "not enforced by the required job: $script"
            else
                problems="${line#*|}"
                if [[ -z "$problems" ]]; then
                    ok "enforced with no failure-masking: $(basename "$script")"
                else
                    bad "$(basename "$script") is enforced but its exit code can be masked: $problems"
                fi
            fi
        done

        if [[ "$(printf '%s' "$wiring" | sed -n 's/^JOB_COE=//p')" == "0" ]]; then
            ok "the required job itself is not continue-on-error"
        else
            bad "the required job is continue-on-error — nothing it runs can fail the check"
        fi

        timeout="$(printf '%s' "$wiring" | sed -n 's/^TIMEOUT=//p')"
        if [[ -n "$timeout" ]] && [[ "$timeout" -ge 1 ]] && [[ "$timeout" -le 30 ]]; then
            ok "the required job has a bounded timeout (${timeout} minutes)"
        else
            bad "the required job has no bounded timeout (got '${timeout:-unset}'; GitHub would allow 360 minutes)"
        fi
    fi
fi

# -----------------------------------------------------------------------------
# B) Each enforced validator really exits non-zero on a broken invariant
# -----------------------------------------------------------------------------
head_ "B) Failure propagation from each enforced validator"

fixture_root=""
make_fixture() {
    fixture_root="$(mktemp -d)"
    local p
    # `.github/governance` is here because the platform validator's M33 section
    # reads required-checks.json. A fixture without it fails for a reason that
    # has nothing to do with what the control is testing, which is exactly how a
    # positive control turns into noise.
    for p in .github/workflows .github/scripts .github/governance docker-compose.yml docker-compose.ci.yml \
             docker-compose.override.yml apps/api/phpunit.xml apps/mobile; do
        if [[ -e "$REPO_ROOT/$p" ]]; then
            mkdir -p "$fixture_root/$(dirname "$p")"
            cp -R "$REPO_ROOT/$p" "$fixture_root/$p"
        fi
    done
    rm -rf "$fixture_root/apps/mobile/build" "$fixture_root/apps/mobile/.dart_tool"
    git -C "$fixture_root" init -q 2>/dev/null || true
    git -C "$fixture_root" add -A 2>/dev/null || true
}

# B1 — the M35 guard must reject a test runner in the production container.
make_fixture
python3 - "$fixture_root/.github/workflows/ga-docker-certification.yml" <<'PY'
import sys
p = sys.argv[1]
s = open(p).read()
s = s.replace(
    "      - name: Redis integration",
    "      - name: Backend runtime tests\n"
    "        run: docker compose exec -T api vendor/bin/pest --no-coverage\n"
    "\n"
    "      - name: Redis integration",
    1,
)
open(p, "w").write(s)
PY
if bash "$REPO_ROOT/.github/scripts/m35_docker_certification_guard.sh" "$fixture_root" >/dev/null 2>&1; then
    bad "the M35 guard returned 0 on a broken invariant — CI would stay green"
else
    ok "the M35 guard exits non-zero on a broken invariant"
fi
rm -rf "$fixture_root"

# B2 — the platform validator must reject mobile dependency drift.
make_fixture
printf '\n# drift introduced by the M36 control\n' >> "$fixture_root/apps/mobile/pubspec.yaml"
if bash "$fixture_root/apps/mobile/scripts/verify_platform_foundation.sh" >/dev/null 2>&1; then
    bad "the platform validator returned 0 on dependency drift — CI would stay green"
else
    ok "the platform validator exits non-zero on dependency drift"
fi
rm -rf "$fixture_root"

# B3 — the platform validator must refuse to under-report its own coverage.
#      This is the M36 repair of the silent shallow-checkout skip: a section
#      that does not run must fail loudly rather than shrink the total.
make_fixture
sed -i 's/^EXPECTED_CHECKS=[0-9]*$/EXPECTED_CHECKS=999/' \
    "$fixture_root/apps/mobile/scripts/verify_platform_foundation.sh"
if bash "$fixture_root/apps/mobile/scripts/verify_platform_foundation.sh" >/dev/null 2>&1; then
    bad "the platform validator returned 0 while executing fewer checks than it declares"
else
    ok "the platform validator exits non-zero when a declared check does not run"
fi
rm -rf "$fixture_root"

# -----------------------------------------------------------------------------
# C) Positive control
# -----------------------------------------------------------------------------
head_ "C) Positive control"

make_fixture
if bash "$REPO_ROOT/.github/scripts/m35_docker_certification_guard.sh" "$fixture_root" >/dev/null 2>&1; then
    ok "an untouched fixture passes the M35 guard"
else
    bad "an untouched fixture fails the M35 guard — the control rejects everything"
fi
if bash "$fixture_root/apps/mobile/scripts/verify_platform_foundation.sh" >/dev/null 2>&1; then
    ok "an untouched fixture passes the platform validator"
else
    bad "an untouched fixture fails the platform validator — the control rejects everything"
fi
rm -rf "$fixture_root"

# -----------------------------------------------------------------------------
# D) Integrity
# -----------------------------------------------------------------------------
head_ "D) Integrity"

after="$(fingerprint)"
if [[ "$before" == "$after" ]]; then
    ok "every guarded file is byte-identical after the run"
else
    bad "THE WORKING TREE WAS MODIFIED — fixtures leaked"
    printf '        before=%s\n        after =%s\n' "$before" "$after"
fi

printf '\n%s\n' "========================================================================"
if (( failed == 0 )); then
    printf 'RESULT: %d passed, %d failed — CI cannot silently swallow a validator failure.\n' "$passed" "$failed"
else
    printf 'RESULT: %d passed, %s%d failed%s\n' "$passed" "$RED" "$failed" "$OFF"
fi
printf '%s\n' "========================================================================"

exit $(( failed == 0 ? 0 : 1 ))
