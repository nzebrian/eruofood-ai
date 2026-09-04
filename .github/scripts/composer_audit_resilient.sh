#!/usr/bin/env bash
# =============================================================================
# composer audit, with bounded retry on transient infrastructure failure.
#
# The composer half of the protocol M48 built for npm. Same three answers, same
# refusal to call an absent audit a clean one:
#
#   PASS         composer audited the lockfile and found nothing.      exit 0
#   VULNERABLE   composer audited the lockfile and found advisories.   exit 1
#   UNAVAILABLE  composer could not produce trustworthy evidence.      exit 3
#
# ## Why composer needs this as much as npm did
#
# `composer audit` fetches advisories from Packagist, and Packagist has bad
# days. Observed live while preparing this:
#
#     curl error 28 while downloading
#     https://packagist.org/api/security-advisories/: Connection timed out
#
# Composer's own exit codes make the same mistake npm's do, plus one of their
# own: 0 is clean, 1 is advisories found, and **100 is a generic error** — a
# code that is never a verdict but which a caller reading "non-zero means bad"
# will happily report as a vulnerability. It isn't one. It means nobody looked.
#
# ## COMPOSER_PROCESS_TIMEOUT
#
# `shivammathur/setup-php` exports COMPOSER_PROCESS_TIMEOUT=0 into the runner
# environment, and zero means UNLIMITED. That was verified verbatim in the
# `Dependency audit` and `Governance Advisory` job logs. A governed audit path
# must not inherit it, so this script overrides it explicitly AND wraps the
# call in `timeout` — belt and braces, because the env var only bounds
# composer's own child processes and says nothing about composer itself.
#
# ## What is deliberately NOT done here
#
# The audit policy — `--locked`, and any future severity flag — stays at the
# call site and is passed through verbatim rather than reconstructed. Burying
# `--locked` in here would move it somewhere it could be dropped without the
# M45 mutation tests noticing, and `--locked` is the load-bearing flag: without
# it, and without a vendor/ tree, `composer audit` prints "No packages -
# skipping audit." and exits 0. A green tick reporting on work that never
# happened is the exact defect this repository has spent nine milestones
# removing.
#
# Usage:
#   composer_audit_resilient.sh [--dir DIR] composer audit --locked
# =============================================================================

set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source-path=SCRIPTDIR
# shellcheck source=lib/reliability_classify.sh
. "$HERE/lib/reliability_classify.sh"

ATTEMPTS="${COMPOSER_AUDIT_ATTEMPTS:-3}"
ATTEMPT_TIMEOUT="${COMPOSER_AUDIT_TIMEOUT:-45}"
BACKOFF_SECONDS="${COMPOSER_AUDIT_BACKOFF:-3 9}"

EXIT_PASS=0
EXIT_VULNERABLE=1
EXIT_UNAVAILABLE=3

workdir="."
if [[ "${1:-}" == "--dir" ]]; then
  workdir="${2:?--dir needs a directory}"
  shift 2
fi

if [[ $# -eq 0 ]]; then
  echo "usage: $(basename "$0") [--dir DIR] composer audit --locked" >&2
  exit "$EXIT_UNAVAILABLE"
fi

# What composer says when it actually audited and found something. Checked
# before any outage pattern — see lib/reliability_classify.sh for why that
# ordering is the security property and not a style choice.
VERDICT='found [0-9]+ security vulnerability|security vulnerability advisor|Found [0-9]+ security|abandoned package|CVE-[0-9]{4}-'

say() { printf '%s\n' "$*"; }

attempt=1
last_code=0
last_output=""

while :; do
  # No `set -e` anywhere in this script, deliberately: the entire job is to
  # read an exit code and classify it, which errexit would pre-empt.
  output="$(cd "$workdir" && \
    COMPOSER_PROCESS_TIMEOUT="${COMPOSER_AUDIT_PROCESS_TIMEOUT:-60}" \
    COMPOSER_NO_INTERACTION=1 \
    timeout "$ATTEMPT_TIMEOUT" "$@" 2>&1)"
  code=$?

  last_code=$code
  last_output="$output"
  printf '%s\n' "$output"

  # ---- clean --------------------------------------------------------------
  # Composer exits 0 only when it audited and found nothing. The guard is for
  # the pathological case of a zero exit alongside an error banner, and for
  # the "No packages - skipping audit." case, which is a zero exit describing
  # an audit that never happened.
  if [[ "$code" -eq 0 ]]; then
    if grep -qiE "$RL_TRANSIENT|$RL_MALFORMED" <<<"$output"; then
      say ""
      say "SECURITY AUDIT: UNAVAILABLE — composer exited 0 but reported an error."
      say "  Refusing to read that as a clean audit."
      exit "$EXIT_UNAVAILABLE"
    fi
    if grep -qiE "No packages( -|-) skipping audit" <<<"$output"; then
      say ""
      say "SECURITY AUDIT: UNAVAILABLE — composer audited nothing and exited 0."
      say "  This is the missing-vendor/--locked defect, not a clean lockfile."
      exit "$EXIT_UNAVAILABLE"
    fi
    say ""
    say "SECURITY AUDIT: PASS — no advisories against the audited lockfile."
    exit "$EXIT_PASS"
  fi

  # ---- exit 100 is never a verdict ----------------------------------------
  # Composer reserves 100 for a generic failure. It answers 0 or 1 whenever it
  # actually audited, so 100 means Packagist did not answer. Classified before
  # the verdict pattern because composer prints its own error text here and a
  # loose verdict match must not claim a finding from an error message.
  if [[ "$code" -eq 100 ]]; then
    class="$(rl_classify "$output" "$code" "")"
    if [[ "$class" == "malformed" ]]; then
      say ""
      say "SECURITY AUDIT: UNAVAILABLE — composer returned a malformed advisory response (exit 100)."
      say "  Failing closed without retrying; retrying cannot repair this."
      exit "$EXIT_UNAVAILABLE"
    fi
    if [[ "$attempt" -ge "$ATTEMPTS" ]]; then break; fi
    wait_for="$(rl_backoff "$attempt" "$BACKOFF_SECONDS")"
    say ""
    say "composer audit attempt ${attempt}/${ATTEMPTS} failed with exit 100 (generic error); retrying in ${wait_for}s."
    sleep "$wait_for"
    attempt=$((attempt + 1))
    continue
  fi

  class="$(rl_classify "$output" "$code" "$VERDICT")"

  case "$class" in
    verdict)
      say ""
      say "SECURITY AUDIT: VULNERABLE — composer audited the lockfile and found advisories."
      say "  This is a real finding. Update the dependency; do not retry."
      exit "$EXIT_VULNERABLE"
      ;;
    malformed)
      say ""
      say "SECURITY AUDIT: UNAVAILABLE — the advisory response or lockfile was malformed."
      say "  Failing closed without retrying; retrying cannot repair this."
      exit "$EXIT_UNAVAILABLE"
      ;;
    transient)
      if [[ "$attempt" -ge "$ATTEMPTS" ]]; then break; fi
      wait_for="$(rl_backoff "$attempt" "$BACKOFF_SECONDS")"
      say ""
      say "composer audit attempt ${attempt}/${ATTEMPTS} hit a transient failure; retrying in ${wait_for}s."
      sleep "$wait_for"
      attempt=$((attempt + 1))
      continue
      ;;
    *)
      # Conservative by design: an unrecognised non-zero exit is not evidence
      # of a clean lockfile, so it is not treated as one. `composer audit`
      # exiting 1 with no recognisable advisory text lands here rather than
      # being assumed to be a finding — the operator is told the truth, which
      # is that this script could not tell.
      say ""
      say "SECURITY AUDIT: UNAVAILABLE — composer audit failed in a way this script does not recognise (exit ${code})."
      say "  Failing closed rather than assuming the lockfile is clean."
      exit "$EXIT_UNAVAILABLE"
      ;;
  esac
done

say ""
say "SECURITY AUDIT: UNAVAILABLE — the advisory service could not be reached after ${ATTEMPTS} attempts."
say "  Last exit ${last_code}. No trustworthy security evidence was obtained, so this"
say "  is a failure, not a pass. Re-run once Packagist recovers."
grep -oiE "$RL_TRANSIENT" <<<"$last_output" | sort -u | head -5 | sed 's/^/  seen: /'
exit "$EXIT_UNAVAILABLE"
