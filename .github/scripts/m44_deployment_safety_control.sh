#!/usr/bin/env bash
#
# M44 — does the deployment-safety validator actually discriminate?
#
# ## Why this exists
#
# `verify_deployment_safety.py` printed twelve PASS lines the first time it ran
# cleanly. So would a validator whose checks were all `str_contains($x, '')`. A
# green control suite is evidence only if the same suite goes red when the
# property stops holding.
#
# So each control below reinstates exactly one of the defects M44 removed — the
# real historical text, not an invented one — and requires the validator to fail
# **on the check that owns that defect**. A bare non-zero exit is not accepted:
# the validator exits 1 for any safety failure and 3 for a misinvocation, so a
# control asserting only "it failed" can pass while its mutation did nothing.
#
# ## Why the real repository is never touched
#
# Every mutation happens inside a `mktemp` fixture holding copies of exactly the
# files the validator reads, pointed at with `--repo-root=`. The real tree is
# fingerprinted with sha256 before and after and the run fails if a byte moved.
# That is the M42/M43 pattern, and it is affordable here because the fixture is
# two files rather than a 4.7 GB vendor tree.
#
# Usage: .github/scripts/m44_deployment_safety_control.sh

# shellcheck disable=SC2016
# The mutation literals below are workflow text, not shell to expand: `${{ }}`,
# `$NS` and `$TAG` must reach the fixture exactly as written. Single quotes are
# the point, not an oversight.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT"

VALIDATOR=".github/scripts/verify_deployment_safety.py"

# Exactly the files the validator reads. Kept in one place so a fixture cannot
# be missing something the validator needs — that produces a failure about the
# missing file rather than about the mutation, and the control then proves
# nothing.
SOURCES=(
  ".github/workflows/staging-deploy.yml"
  "infra/k8s/jobs/migrate.yaml"
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
  fixture="$(mktemp -d "${TMPDIR:-/tmp}/m44-deploy-XXXXXXXX")"

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

# Replace exactly one occurrence of a literal, or fail loudly. A `find` string
# that is absent is a hard error rather than a skipped control: a mutation that
# silently did nothing would report the validator as discriminating when it
# never saw a change.
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

# Run the validator against a fixture; return exit code and the failed check ids.
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
    if ! mutate "$fixture" "$file" "$find" "$replace" 2>/tmp/m44-mutate-err; then
      broken+=("$name: $(cat /tmp/m44-mutate-err)")
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

echo "EruoFood — M44 deployment safety negative controls"
echo "=============================================================================="
echo "Each control reinstates one removed defect inside a disposable fixture; the"
echo "validator must then fail on that defect's own check."
echo

before="$(fingerprint)"
echo "Protected-file fingerprint (before): $before"
echo

# ---------------------------------------------------------------------------
# Each `find` below is current M44 text; each `replace` is the historical defect.
# ---------------------------------------------------------------------------

control "1. the deploy ref spliced back into the remote script" \
  "deploy.no_expression_in_run" \
  ".github/workflows/staging-deploy.yml" \
  '            git checkout --force "$ref"' \
  '            git checkout --force "${{ inputs.ref }}"'

control "2. the ref no longer passed as a positional argument" \
  "deploy.ref_passed_as_argument" \
  ".github/workflows/staging-deploy.yml" \
  '            bash -s -- "$DEPLOY_REF" <<'"'"'REMOTE'"'"'' \
  '            bash -s <<'"'"'REMOTE'"'"''

control "3. '|| true' restored on the api image roll" \
  "deploy.image_roll_unmasked" \
  ".github/workflows/staging-deploy.yml" \
  '          kubectl -n "$NS" set image deploy/api    api="${REGISTRY}/${IMAGE_PREFIX}/api:$TAG"' \
  '          kubectl -n "$NS" set image deploy/api    api="${REGISTRY}/${IMAGE_PREFIX}/api:$TAG" || true'

control "4. only deploy/api verified again, web and worker unchecked" \
  "deploy.rollout_covers_every_deployment" \
  ".github/workflows/staging-deploy.yml" \
  '          for d in api worker web; do
            kubectl -n "$NS" rollout status "deploy/$d" --timeout=180s
          done' \
  '          kubectl -n "$NS" rollout status deploy/api --timeout=180s'

control "5. the migration manifest deleted, as it was before M44" \
  "deploy.migration_manifest_exists" \
  "infra/k8s/jobs/migrate.yaml" \
  "kind: Job" \
  "kind: NotAJob"

control "6. the migration apply masked with '|| echo' again" \
  "deploy.migration_unmasked" \
  ".github/workflows/staging-deploy.yml" \
  '          envsubst < infra/k8s/jobs/migrate.yaml | kubectl -n "$NS" apply -f -' \
  '          kubectl -n "$NS" apply -f infra/k8s/jobs/migrate.yaml || echo "add it"'

control "7. the migration Job applied but never waited on" \
  "deploy.migration_is_waited_on" \
  ".github/workflows/staging-deploy.yml" \
  '          kubectl -n "$NS" wait --for=condition=complete job/eruofood-migrate --timeout=300s' \
  '          true'

control "8. the smoke test exits 0 when it cannot run" \
  "deploy.smoke_test_fails_closed" \
  ".github/workflows/staging-deploy.yml" \
  '            echo "::error::STAGING_URL is not set, so this deployment cannot be verified."
            echo "Set the staging Environment variable STAGING_URL (docs/STAGING_DEPLOYMENT.md §1)."
            exit 1' \
  '            echo "::warning::set STAGING_URL to enable smoke test"
            exit 0'

control "9. continue-on-error added to the deploy step" \
  "deploy.no_continue_on_error" \
  ".github/workflows/staging-deploy.yml" \
  '      - name: Deploy via kubectl
        if: steps.backend.outputs.mode == '"'"'k8s'"'"'' \
  '      - name: Deploy via kubectl
        continue-on-error: true
        if: steps.backend.outputs.mode == '"'"'k8s'"'"''

control "10. the kubectl path stops recording its rollback target" \
  "deploy.rollback_target_recorded" \
  ".github/workflows/staging-deploy.yml" \
  "              -o jsonpath='{.spec.template.spec.containers[0].image}')\"" \
  "              -o name)\""

control "11. 'set +e' hidden in the smoke test" \
  "deploy.no_masked_deploy_step" \
  ".github/workflows/staging-deploy.yml" \
  '        run: |
          set -euo pipefail

          # M44. This used to `exit 0`' \
  '        run: |
          set +e

          # M44. This used to `exit 0`'

# ---------------------------------------------------------------------------
# Control 12 — the positive control. Without it every control above is satisfied
# by a validator that fails on everything, including a correct repository.

printf '%-66s' "12. positive control: an unmutated fixture passes"
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

# Control 13 — integrity. Everything above mutated a copy.
printf '%-66s' "13. sha256 integrity: the real repository is unchanged"
after="$(fingerprint)"
integrity_ok=0
if [[ "$before" == "$after" ]]; then integrity_ok=1; echo " ok"; else echo " FAILED"; fi

echo
echo "Protected-file fingerprint (after):  $after"
echo
echo "=============================================================================="
printf '%d/11 reinstated defects confirmed by the check they targeted.\n' "$confirmed"

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

if [[ "$confirmed" -eq 11 && ${#broken[@]} -eq 0 && ${#false_positives[@]} -eq 0 && "$positive_ok" -eq 1 && "$integrity_ok" -eq 1 ]]; then
  echo
  echo "Every deployment safeguard discriminates, and the working tree is untouched."
  exit 0
fi

echo
echo "M44 deployment safety negative controls FAILED."
exit 1
