#!/usr/bin/env bash
# =============================================================================
# Run one governance control and record that it ran.
#
# ## The problem this exists for
#
# On 2026-09-04 `CI · Workflow Integrity` was CANCELLED when its ten-minute cap
# fired mid-control. Steps 16-19 never executed. Four controls — including the
# one that proves the enforcement wiring cannot mask a failure — produced no
# result at all, and working out which ones had actually run required reading
# step timestamps by hand out of the API.
#
# The job conclusion was `cancelled`, so nothing was wrongly reported as
# passing. But "controls 1-15 passed and 16-20 never ran" and "all 20 controls
# passed" are the same colour once you stop reading conclusions, and a control
# that silently stops running is the failure mode this repository has spent
# nine milestones removing from every other direction.
#
# So each control now leaves evidence that it ran, and
# `verify_control_manifest.py` asserts the recorded set is exactly the
# mandatory set. A control that never ran is NOT_RUN, and NOT_RUN fails.
#
# ## Why the exit code is propagated unchanged
#
# This wrapper must be incapable of turning a failure into a success. It runs
# the control, writes a record, and exits with the control's own status. There
# is no branch in which it exits 0 for a control that did not. Adding one
# would make it the single most dangerous file in the repository.
#
# Usage:
#   run_control.sh <control-id> -- <command> [args...]
#
# Environment:
#   CONTROL_MANIFEST_DIR   where records are written (default .ci-control-manifest)
# =============================================================================

set -uo pipefail

MANIFEST_DIR="${CONTROL_MANIFEST_DIR:-.ci-control-manifest}"

control_id="${1:?usage: run_control.sh <control-id> -- <command...>}"
shift
if [[ "${1:-}" != "--" ]]; then
  echo "run_control.sh: expected '--' after the control id" >&2
  exit 2
fi
shift
if [[ $# -eq 0 ]]; then
  echo "run_control.sh: no command given for control '${control_id}'" >&2
  exit 2
fi

if [[ ! "$control_id" =~ ^[a-z0-9_]+$ ]]; then
  echo "run_control.sh: control id '${control_id}' must match ^[a-z0-9_]+$" >&2
  exit 2
fi

mkdir -p "$MANIFEST_DIR"

record="${MANIFEST_DIR}/${control_id}.json"
if [[ -e "$record" ]]; then
  # Two steps claiming the same control id is a wiring mistake, and silently
  # overwriting would hide whichever ran first. The verifier also rejects
  # duplicates; catching it here names the control instead of the symptom.
  echo "run_control.sh: control '${control_id}' already has a record at ${record}" >&2
  exit 2
fi

# A monotonic sequence, allocated before the control runs. Its purpose is to
# make "this record was written by a control that actually executed" checkable:
# the verifier requires the sequence numbers to be a gapless 1..N, so a record
# appended by hand after the fact cannot slot in without colliding.
seq_file="${MANIFEST_DIR}/.seq"
seq_n=$(( $(cat "$seq_file" 2>/dev/null || echo 0) + 1 ))
echo "$seq_n" > "$seq_file"

started="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
start_epoch="$(date -u +%s)"

printf '::group::control %s\n' "$control_id"
"$@"
exit_code=$?
printf '::endgroup::\n'

finished="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
duration=$(( $(date -u +%s) - start_epoch ))

# The verdict is DERIVED from the exit code, never supplied. 3 is the
# UNAVAILABLE code the audit wrappers use; everything else non-zero is a
# straightforward failure.
case "$exit_code" in
  0) verdict="PASS" ;;
  3) verdict="UNAVAILABLE" ;;
  *) verdict="FAIL" ;;
esac

# `command` is recorded for diagnosis only. The verifier never trusts it.
command_text="$*"

cat > "$record" <<JSON
{
  "control": "${control_id}",
  "seq": ${seq_n},
  "started_at": "${started}",
  "finished_at": "${finished}",
  "duration_seconds": ${duration},
  "exit_code": ${exit_code},
  "verdict": "${verdict}",
  "command": $(printf '%s' "$command_text" | python3 -c 'import json,sys; print(json.dumps(sys.stdin.read()))')
}
JSON

printf '  control %-42s %-11s (exit %d, %ds)\n' "$control_id" "$verdict" "$exit_code" "$duration"

exit "$exit_code"
