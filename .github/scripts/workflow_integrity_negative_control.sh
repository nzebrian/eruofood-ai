#!/usr/bin/env bash
#
# M29-E — does the workflow integrity gate actually bite?
#
# ## Why this exists
#
# `.github/workflows/release.yml` was invalid YAML from 2026-08-04 until M29-D.
# Every push to every branch produced a zero-job startup failure — thirty-odd of
# them, on `main` and on every dependabot branch — and nobody noticed, because in
# the Actions tab a workflow that cannot load is indistinguishable from a gate
# that ran and failed. The file called itself a MANDATORY production gate the
# whole time.
#
# M29-D fixed that one file. This proves the check now standing guard would have
# caught it, and would catch its relatives.
#
# ## The trap being avoided
#
# A validator whose subject is already clean passes for two indistinguishable
# reasons: it works, or it checks nothing. M28 found a five-adapter test sweep
# that had been exercising one adapter five times while green throughout. So
# each case below breaks something specific and requires a rejection naming the
# rule that fired — and a POSITIVE control requires a clean workflow to be
# accepted, which is what separates "rejects bad input" from "rejects input".
#
# ## Safety
#
# Every fixture is written inside `mktemp -d`, never into `.github/workflows`,
# and the directory is removed by an EXIT trap so an interrupted run leaves
# nothing behind. The real workflow directory is counted before and after and
# the run fails if the count moved.
#
# Usage:  .github/scripts/workflow_integrity_negative_control.sh
#         ACTIONLINT=/path/to/actionlint .github/scripts/workflow_integrity_negative_control.sh

set -Eeuo pipefail

ACTIONLINT="${ACTIONLINT:-actionlint}"

if ! command -v "$ACTIONLINT" >/dev/null 2>&1 && [ ! -x "$ACTIONLINT" ]; then
    echo "FATAL: actionlint not found (looked for '${ACTIONLINT}')." >&2
    echo "       Set ACTIONLINT=/path/to/actionlint, or install it." >&2
    exit 127
fi

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
workflow_dir="${repo_root}/.github/workflows"

# The real workflow directory must be untouched by this script. Counted rather
# than trusted: a control that damaged what it was protecting would still print
# a tidy pass.
before_count="$(find "$workflow_dir" -maxdepth 1 -name '*.yml' | wc -l | tr -d ' ')"
before_hash="$(find "$workflow_dir" -maxdepth 1 -name '*.yml' -exec sha256sum {} + | sort | sha256sum)"

fixtures="$(mktemp -d)"
trap 'rm -rf "$fixtures"' EXIT

passed=0
failed=0

echo "EruoFood — M29-E workflow integrity negative controls"
echo "=============================================================================="
echo "actionlint: $("$ACTIONLINT" --version | head -1)"
echo "fixtures:   ${fixtures}  (temporary; never .github/workflows)"
echo

# reject <name> <expected-rule-tag> <<<yaml
#
# Writes the fixture, requires actionlint to exit non-zero, and requires the
# named rule to be the one that fired. Matching on the rule tag rather than on
# any failure keeps a fixture that breaks for an unrelated reason from being
# counted as proof.
reject() {
    local name="$1" expect="$2" file="${fixtures}/${1}.yml"
    cat >"$file"

    local out exit_code=0
    out="$("$ACTIONLINT" -no-color "$file" 2>&1)" || exit_code=$?

    if [ "$exit_code" -ne 0 ] && printf '%s' "$out" | grep -qF "$expect"; then
        passed=$((passed + 1))
        printf '  PASS  rejected: %s\n' "$name"
        # Drop the temp path and clip the line: actionlint prints the whole
        # context object type for an expression error, which is several hundred
        # characters and buries the finding it belongs to.
        printf '        %s\n' "$(printf '%s' "$out" | head -1 | sed 's|^.*/||' | cut -c1-140)"
    else
        failed=$((failed + 1))
        printf '  FAIL  NOT REJECTED: %s (exit=%d, expected %s)\n' "$name" "$exit_code" "$expect"
        printf '%s\n' "$out" | sed 's/^/        /'
    fi
}

# accept <name> <<<yaml
#
# The control on the controls. Without this the suite cannot tell a working
# validator from one that rejects everything it is handed.
accept() {
    local name="$1" file="${fixtures}/${1}.yml"
    cat >"$file"

    local out exit_code=0
    out="$("$ACTIONLINT" -no-color "$file" 2>&1)" || exit_code=$?

    if [ "$exit_code" -eq 0 ]; then
        passed=$((passed + 1))
        printf '  PASS  accepted: %s\n' "$name"
    else
        failed=$((failed + 1))
        printf '  FAIL  WRONGLY REJECTED: %s\n' "$name"
        printf '%s\n' "$out" | sed 's/^/        /'
    fi
}

# -- 1. The historical defect, verbatim ---------------------------------------

# This is release.yml line 135 as it stood on main until M29-D. Inside a flow
# mapping the `{` of `${{` opens a nested mapping, so the document is not valid
# YAML and GitHub cannot load the workflow at all.
reject 'flow-mapping-expression' '[syntax-check]' <<'YAML'
name: Historical Defect
on:
  push:
    tags: ["v*.*.*"]
jobs:
  security:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Gitleaks
        uses: gitleaks/gitleaks-action@v2
        env: { GITHUB_TOKEN: ${{ github.token }} }
YAML

# -- 2. Valid YAML, invalid Actions -------------------------------------------

# A YAML parser alone would pass this. The gate has to understand Actions
# semantics too, or half the ways to break a workflow go unnoticed.
reject 'undefined-job-dependency' '[job-needs]' <<'YAML'
name: Undefined Dependency
on:
  pull_request:
jobs:
  build:
    runs-on: ubuntu-latest
    needs: [job-that-does-not-exist]
    steps:
      - run: echo building
YAML

# -- 3. An expression that does not resolve -----------------------------------

# The same species as the historical defect — an expression nobody checked —
# but reached through the context rather than through the quoting.
reject 'undefined-expression-property' '[expression]' <<'YAML'
name: Undefined Property
on:
  pull_request:
jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - run: echo "${{ github.no_such_property }}"
YAML

# -- 4. Positive control ------------------------------------------------------

accept 'well-formed-workflow' <<'YAML'
name: Well Formed
on:
  pull_request:
    paths: [".github/workflows/**"]
permissions:
  contents: read
jobs:
  validate:
    name: Validate
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - run: echo "${{ github.repository }}"
YAML

# -- The real workflow directory is unchanged ---------------------------------

after_count="$(find "$workflow_dir" -maxdepth 1 -name '*.yml' | wc -l | tr -d ' ')"
after_hash="$(find "$workflow_dir" -maxdepth 1 -name '*.yml' -exec sha256sum {} + | sort | sha256sum)"

echo
if [ "$before_count" = "$after_count" ] && [ "$before_hash" = "$after_hash" ]; then
    passed=$((passed + 1))
    printf '  PASS  .github/workflows untouched (%s files, sha256 verified before and after)\n' "$after_count"
else
    failed=$((failed + 1))
    printf '  FAIL  .github/workflows CHANGED (%s -> %s files)\n' "$before_count" "$after_count"
fi

# -- Result -------------------------------------------------------------------

total=$((passed + failed))

echo
echo "=============================================================================="
printf 'RESULT: %d/%d controls confirmed' "$passed" "$total"
if [ "$failed" -eq 0 ]; then
    echo " — the gate rejects what it should and accepts what it should."
else
    printf ', %d FAILED.\n' "$failed"
fi

exit "$([ "$failed" -eq 0 ] && echo 0 || echo 1)"
