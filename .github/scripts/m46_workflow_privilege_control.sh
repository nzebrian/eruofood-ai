#!/usr/bin/env bash
#
# M46 — does the workflow-privilege validator actually discriminate?
#
# ## Why this exists
#
# `verify_workflow_privilege.py` printed forty-seven PASS lines the first time
# it ran. So would a validator whose every check was `True`. A green suite is
# evidence only if the same suite goes red when the property stops holding.
#
# Each control below breaks exactly one property inside a throwaway fixture and
# requires the validator to fail **on the check that owns it**. A bare non-zero
# exit is not accepted: the validator exits 1 for any failure and 3 for a
# misinvocation, so a control asserting only "it failed" can pass while its
# mutation did nothing.
#
# ## Two properties are easy to get wrong, so they are tested twice
#
# The privilege rule is per workflow, so a validator that checked only the
# first file would still pass eleven of the controls here. Controls 1 and 5
# remove `permissions:` from *different* workflows for that reason.
#
# The injection rule has three distinct tainted shapes — a ref name, a dispatch
# input, and a branch expression — and a validator matching one literal string
# would catch a third of them. Controls 6, 7 and 8 use all three.
#
# ## Why the real repository is never touched
#
# Every mutation happens inside a `mktemp` fixture holding copies of the whole
# workflow directory, pointed at with `--repo-root=`. The real tree is
# fingerprinted with sha256 before and after and the run fails if a byte moved.
#
# Usage: .github/scripts/m46_workflow_privilege_control.sh

# shellcheck disable=SC2016
# The mutation literals below are workflow text, not shell to expand: `${{ }}`
# must reach the fixture exactly as written. Single quotes are the point.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT"

VALIDATOR=".github/scripts/verify_workflow_privilege.py"
WORKFLOW_DIR=".github/workflows"

confirmed=0
declare -a false_positives=()
declare -a broken=()

fingerprint() {
  { find "$WORKFLOW_DIR" -type f -name '*.yml' -print0 | sort -z | xargs -0 sha256sum
    sha256sum "$VALIDATOR"
  } | sha256sum | cut -d' ' -f1
}

make_fixture() {
  local fixture
  fixture="$(mktemp -d "${TMPDIR:-/tmp}/m46-privilege-XXXXXXXX")"
  mkdir -p "$fixture/$WORKFLOW_DIR"

  local path
  while IFS= read -r -d '' path; do
    if [[ -L "$path" ]]; then
      # A symlink copied into a fixture would let a mutation reach through it
      # into the real repository — the exact escape this design prevents.
      echo "refusing to copy a symlink into a fixture: $path" >&2
      return 1
    fi
    cp "$path" "$fixture/$WORKFLOW_DIR/"
  done < <(find "$WORKFLOW_DIR" -maxdepth 1 -type f -name '*.yml' -print0)

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

path = os.path.join(os.environ['FIXTURE'], '.github/workflows', os.environ['FILE'])
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

# control <name> <expected check id> [file find replace]...
control() {
  local name="$1" expect="$2"
  shift 2

  printf '%-70s' "${name:0:70}"

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
    if ! mutate "$fixture" "$file" "$find" "$replace" 2>/tmp/m46-mutate-err; then
      broken+=("$name: $(cat /tmp/m46-mutate-err)")
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

# Same as `control`, but the mutation is a file operation rather than a text
# substitution — used for the set-membership controls.
control_files() {
  local name="$1" expect="$2" action="$3" arg="${4:-}"

  printf '%-70s' "${name:0:70}"

  local fixture
  if ! fixture="$(make_fixture)"; then
    broken+=("$name: fixture could not be built")
    echo " BROKEN"
    return 0
  fi

  case "$action" in
    delete) rm -f "$fixture/$WORKFLOW_DIR/$arg" ;;
    add)    printf 'name: Unreviewed\non:\n  push:\njobs:\n  x:\n    runs-on: ubuntu-latest\n    steps:\n      - run: echo hi\n' > "$fixture/$WORKFLOW_DIR/$arg" ;;
    rename) mv "$fixture/$WORKFLOW_DIR/$arg" "$fixture/$WORKFLOW_DIR/renamed-${arg}" ;;
    *)      broken+=("$name: unknown action $action"); echo " BROKEN"; rm -rf "$fixture"; return 0 ;;
  esac

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

echo "EruoFood — M46 workflow privilege & injection negative controls"
echo "=============================================================================="
echo "Each control breaks one property inside a disposable fixture; the validator"
echo "must then fail on that property's own check."
echo

before="$(fingerprint)"
echo "Workflow-tree fingerprint (before): $before"
echo

# ---------------------------------------------------------------------------
# 1-5. Privilege.
# ---------------------------------------------------------------------------

control "1. permissions removed from ci-web (the pre-M46 state)" \
  "privilege.ci-web.declares_permissions" \
  "ci-web.yml" \
  'permissions:
  contents: read

concurrency:
  group: ci-web-' \
  'concurrency:
  group: ci-web-'

control "2. contents:read quietly widened to contents:write" \
  "privilege.ci-api.least_privilege" \
  "ci-api.yml" \
  'permissions:
  contents: read

concurrency:
  group: ci-api-' \
  'permissions:
  contents: write

concurrency:
  group: ci-api-'

control "3. an unnecessary write scope added (pull-requests)" \
  "privilege.security.least_privilege" \
  "security.yml" \
  'permissions:
  contents: read

concurrency:
  group: security-' \
  'permissions:
  contents: read
  pull-requests: write

concurrency:
  group: security-'

control "4. broad write-all restored" \
  "privilege.contracts.least_privilege" \
  "contracts.yml" \
  'permissions:
  contents: read

concurrency:
  group: ci-contracts-' \
  'permissions: write-all

concurrency:
  group: ci-contracts-'

control "5. permissions removed from a SECOND workflow (ci-docker)" \
  "privilege.ci-docker.declares_permissions" \
  "ci-docker.yml" \
  'permissions:
  contents: read

jobs:' \
  'jobs:'

# ---------------------------------------------------------------------------
# 6-8. Injection, in all three tainted shapes.
# ---------------------------------------------------------------------------

control "6. github.ref_name spliced back into release.yml's run: block" \
  "privilege.no_tainted_interpolation" \
  "release.yml" \
  '        run: echo "OK — every mandatory gate is green for $RELEASE_REF."' \
  '        run: echo "OK — every mandatory gate is green for ${{ github.ref_name }}."'

control "7. a workflow_dispatch input spliced into a run: block" \
  "privilege.no_tainted_interpolation" \
  "ga-release-certification.yml" \
  '          [ "$STAGING_SMOKE_PASSED" = "true" ]' \
  '          [ "${{ inputs.staging_smoke_passed }}" = "true" ]'

control "8. a branch expression (github.head_ref) reaches shell source" \
  "privilege.no_tainted_interpolation" \
  "ci-web.yml" \
  '      - name: Install dependencies
        run: npm ci' \
  '      - name: Install dependencies
        run: |
          echo "branch ${{ github.head_ref }}"
          npm ci'

# ---------------------------------------------------------------------------
# 9-11. The expected set is a closed world.
# ---------------------------------------------------------------------------

control_files "9. an expected workflow deleted (a gate silently removed)" \
  "privilege.workflow_set_exact" delete "ci-mobile.yml"

control_files "10. an unexpected workflow appears, granted by nobody" \
  "privilege.workflow_set_exact" add "rogue.yml"

control_files "11. an expected workflow renamed (identity changed)" \
  "privilege.workflow_set_exact" rename "governance-advisory.yml"

# A job-level override is the way past a correct top-level policy, and the
# controls above would all still pass with it present.
control "11b. a job overrides the workflow policy and widens it" \
  "privilege.ci-web.no_job_widening" \
  "ci-web.yml" \
  '  quality:
    name: Lint · Typecheck · Test · Build' \
  '  quality:
    permissions:
      contents: write
    name: Lint · Typecheck · Test · Build'

# ---------------------------------------------------------------------------
# 12 — the positive control. Without it every control above is satisfied by a
# validator that fails on everything, including a correct repository.
# ---------------------------------------------------------------------------

printf '%-70s' "12. positive control: an unmutated fixture passes"
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

# 13 — integrity. Everything above mutated a copy.
printf '%-70s' "13. sha256 integrity: the real workflow tree is unchanged"
after_print="$(fingerprint)"
integrity_ok=0
if [[ "$before" == "$after_print" ]]; then integrity_ok=1; echo " ok"; else echo " FAILED"; fi

echo
echo "Workflow-tree fingerprint (after):  $after_print"
echo
echo "=============================================================================="
printf '%d/12 broken properties confirmed by the check that owns them.\n' "$confirmed"

if [[ ${#broken[@]} -gt 0 ]]; then
  echo
  echo "BROKEN CONTROLS (the control itself needs updating):"
  printf '  - %s\n' "${broken[@]}"
fi

if [[ ${#false_positives[@]} -gt 0 ]]; then
  echo
  echo "FALSE POSITIVES — the validator did not discriminate:"
  printf '  - %s\n' "${false_positives[@]}"
fi

if [[ "$integrity_ok" -ne 1 ]]; then
  echo
  echo "INTEGRITY FAILURE — the real repository changed during this run."
fi

if [[ "$confirmed" -eq 12 && ${#broken[@]} -eq 0 && ${#false_positives[@]} -eq 0 \
      && "$positive_ok" -eq 1 && "$integrity_ok" -eq 1 ]]; then
  echo
  echo "Every privilege and injection rule discriminates, and the working tree is"
  echo "untouched."
  exit 0
fi

echo
echo "M46 workflow privilege negative controls FAILED."
exit 1
