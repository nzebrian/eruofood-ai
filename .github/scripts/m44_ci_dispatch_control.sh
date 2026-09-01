#!/usr/bin/env bash
#
# M44 — does the CI dispatchability validator actually discriminate?
#
# ## Why this exists
#
# `verify_ci_dispatchability.py` printed fourteen PASS lines the first time it
# ran. So would a validator whose every check was `True`. A green suite is
# evidence only if the same suite goes red when the property stops holding.
#
# Each control below breaks exactly one property the M44 trigger change depends
# on, inside a throwaway fixture, and requires the validator to fail **on the
# check that owns it**. A bare non-zero exit is not accepted: the validator
# exits 1 for any failure and 3 for a misinvocation, so a control asserting only
# "it failed" can pass while its mutation did nothing.
#
# ## Control 8 is a different shape, and is the point of the milestone
#
# Controls 1-7 prove the validator notices damage. Control 8 proves the change
# itself was inert: it reconstructs the PRE-M44 workflows by deleting the
# `workflow_dispatch` lines, extracts the job names from both versions, and
# requires them to be identical. Adding a trigger must not move a required
# context. If it did, four byte-exact strings in main's ruleset would detach
# from their jobs and every one of those contexts would go permanently pending.
#
# ## Why the real repository is never touched
#
# Every mutation happens inside a `mktemp` fixture holding copies of exactly the
# files the validator reads, pointed at with `--repo-root=`. The real tree is
# fingerprinted with sha256 before and after and the run fails if a byte moved.
#
# Usage: .github/scripts/m44_ci_dispatch_control.sh

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT"

VALIDATOR=".github/scripts/verify_ci_dispatchability.py"

SOURCES=(
  ".github/workflows/ci-api.yml"
  ".github/workflows/ci-web.yml"
  ".github/workflows/contracts.yml"
  ".github/governance/required-checks.json"
)

confirmed=0
declare -a false_positives=()
declare -a broken=()

fingerprint() {
  local path
  for path in "${SOURCES[@]}" "$VALIDATOR"; do
    if [[ -f "$path" ]]; then sha256sum "$path"; else echo "ABSENT  $path"; fi
  done | sort | sha256sum | cut -d' ' -f1
}

make_fixture() {
  local fixture path
  fixture="$(mktemp -d "${TMPDIR:-/tmp}/m44-dispatch-XXXXXXXX")"

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

  printf '%-66s' "${name:0:66}"

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
    if ! mutate "$fixture" "$file" "$find" "$replace" 2>/tmp/m44-dispatch-mutate-err; then
      broken+=("$name: $(cat /tmp/m44-dispatch-mutate-err)")
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

echo "EruoFood — M44 CI dispatchability negative controls"
echo "=============================================================================="
echo "Each control breaks one property inside a disposable fixture; the validator"
echo "must then fail on that property's own check."
echo

before="$(fingerprint)"
echo "Protected-file fingerprint (before): $before"
echo

# ---------------------------------------------------------------------------
# 1-3. The trigger is removed again — the pre-M44 state, one workflow at a time.
# ---------------------------------------------------------------------------

# NOTE (M46): the four mutations below used to span from `workflow_dispatch:`
# down to the `concurrency:` line. M46 inserted a `permissions:` block between
# the two, and every one of those anchors stopped matching — which the control
# reported as BROKEN rather than passing, correctly, because a mutation that
# silently does nothing proves nothing. The anchors now key on the trigger line
# alone, which is unique in each file and does not care what follows it. The
# properties tested are unchanged.
control "1. ci-api loses workflow_dispatch (the pre-M44 state)" \
  "ci.ci-api.dispatchable" \
  ".github/workflows/ci-api.yml" \
  '  workflow_dispatch:
' \
  ''

control "2. ci-web loses workflow_dispatch" \
  "ci.ci-web.dispatchable" \
  ".github/workflows/ci-web.yml" \
  '  workflow_dispatch:
' \
  ''

control "3. contracts loses workflow_dispatch" \
  "ci.contracts.dispatchable" \
  ".github/workflows/contracts.yml" \
  '  workflow_dispatch:
' \
  ''

# ---------------------------------------------------------------------------
# 4. A required job name is renamed. GitHub matches a required status check on
#    the job name; the rename detaches the ruleset entry and the context goes
#    permanently pending rather than red.
# ---------------------------------------------------------------------------

control "4. a required job is renamed (context detaches, never reports)" \
  "ci.required_job_set_unchanged" \
  ".github/workflows/ci-api.yml" \
  '    name: Lint · Analyse · Test' \
  '    name: Lint - Analyse - Test'

# A subtler relative of the same defect: the MIDDLE DOT replaced by a full stop,
# which is visually near-identical and just as fatal.
control "5. U+00B7 swapped for a full stop in a required job name" \
  "ci.required_job_set_unchanged" \
  ".github/workflows/contracts.yml" \
  '    name: Lint spec · Generate types' \
  '    name: Lint spec . Generate types'

# ---------------------------------------------------------------------------
# 6. The push path filter is dropped — the plausible-looking "simpler" way to
#    make a workflow run after every merge, which silently widens post-merge CI
#    instead of adding a way to run it on demand.
# ---------------------------------------------------------------------------

control "6. push.paths deleted from ci-web (post-merge CI silently widened)" \
  "ci.ci-web.push_paths_retained" \
  ".github/workflows/ci-web.yml" \
  '    paths: ["apps/web/**", "packages/api-contracts/**", ".github/workflows/ci-web.yml"]
' \
  ''

# ---------------------------------------------------------------------------
# 7-8. Two ways the change could have been made worse than not making it.
# ---------------------------------------------------------------------------

control "7. a dispatch input appears (a new shell-injection surface)" \
  "ci.ci-api.dispatch_has_no_inputs" \
  ".github/workflows/ci-api.yml" \
  '  workflow_dispatch:
' \
  '  workflow_dispatch:
    inputs:
      ref:
        description: "ref to test"
        required: false
        default: ""
'

control "8. pull_request regains a paths filter (the M29-A trap)" \
  "ci.contracts.pull_request_unfiltered" \
  ".github/workflows/contracts.yml" \
  '  pull_request:

  # Manually runnable (M44).' \
  '  pull_request:
    paths: ["packages/api-contracts/**"]

  # Manually runnable (M44).'

# ---------------------------------------------------------------------------
# Control 9 — the milestone's own claim, tested directly rather than by proxy.
#
# Every control above shows the validator noticing damage. This one shows the
# CHANGE did none: reconstruct the pre-M44 workflows by deleting the
# `workflow_dispatch` lines, extract the job names from both versions, and
# require them to be identical. If adding a trigger moved a job name, four
# byte-exact context strings in main's ruleset would detach from their jobs.
# ---------------------------------------------------------------------------

printf '%-66s' "9. adding workflow_dispatch left the required job set identical"
job_set_ok=0
if compare_fixture="$(make_fixture)"; then
  if FIXTURE="$compare_fixture" REPO="$REPO_ROOT" python3 - <<'PY'
import os, sys, yaml
from pathlib import Path

FILES = [
    ".github/workflows/ci-api.yml",
    ".github/workflows/ci-web.yml",
    ".github/workflows/contracts.yml",
]

def jobs(text, label):
    doc = yaml.safe_load(text)
    block = doc.get("jobs")
    if not isinstance(block, dict):
        sys.exit(f"{label}: no jobs mapping")
    return sorted(job.get("name", key) for key, job in block.items())

after, before = [], []
for rel in FILES:
    text = Path(os.environ["REPO"], rel).read_text(encoding="utf-8")
    after += jobs(text, f"post-M44 {rel}")

    # Reconstruct the pre-M44 file: drop the `workflow_dispatch:` key and the
    # comment block introducing it. Comments are not YAML, so only the key line
    # actually has to go for the parse to reflect the old trigger set.
    stripped = "\n".join(
        line for line in text.splitlines() if line != "  workflow_dispatch:"
    )
    if stripped == text:
        sys.exit(f"{rel}: no workflow_dispatch line to strip — control is stale")
    before += jobs(stripped, f"pre-M44 {rel}")

if sorted(before) != sorted(after):
    sys.exit(f"job set MOVED: pre-M44 {sorted(before)} != post-M44 {sorted(after)}")

expected = sorted([
    "Tests · SQLite",
    "Lint · Analyse · Test",
    "Lint · Typecheck · Test · Build",
    "Lint spec · Generate types",
])
if sorted(after) != expected:
    sys.exit(f"job set is {sorted(after)}, expected the four required contexts {expected}")
PY
  then
    job_set_ok=1
    echo " ok"
  else
    echo " FAILED"
  fi
  rm -rf "$compare_fixture"
else
  echo " BROKEN"
fi

# ---------------------------------------------------------------------------
# Control 10 — the positive control. Without it every control above is satisfied
# by a validator that fails on everything, including a correct repository.

printf '%-66s' "10. positive control: an unmutated fixture passes"
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

# Control 11 — integrity. Everything above mutated a copy.
printf '%-66s' "11. sha256 integrity: the real repository is unchanged"
after_print="$(fingerprint)"
integrity_ok=0
if [[ "$before" == "$after_print" ]]; then integrity_ok=1; echo " ok"; else echo " FAILED"; fi

echo
echo "Protected-file fingerprint (after):  $after_print"
echo
echo "=============================================================================="
printf '%d/8 broken properties confirmed by the check that owns them.\n' "$confirmed"

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

if [[ "$job_set_ok" -ne 1 ]]; then
  echo
  echo "JOB SET CHANGED — adding workflow_dispatch moved a required context."
fi

if [[ "$integrity_ok" -ne 1 ]]; then
  echo
  echo "INTEGRITY FAILURE — the real repository changed during this run."
fi

if [[ "$confirmed" -eq 8 && ${#broken[@]} -eq 0 && ${#false_positives[@]} -eq 0 \
      && "$job_set_ok" -eq 1 && "$positive_ok" -eq 1 && "$integrity_ok" -eq 1 ]]; then
  echo
  echo "The dispatch trigger is enforced, the required job set is untouched, and"
  echo "the working tree is unchanged."
  exit 0
fi

echo
echo "M44 CI dispatchability negative controls FAILED."
exit 1
