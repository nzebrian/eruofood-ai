#!/usr/bin/env bash
# =============================================================================
# npm audit, with bounded retry on transient infrastructure failure.
#
# ## Why this exists
#
# On 2026-09-04 the required `Dependency audit` context failed with
#
#     npm warn audit 503 Service Unavailable -
#         POST https://registry.npmjs.org/-/npm/v1/security/audits/quick
#     npm error audit endpoint returned an error
#     Process completed with exit code 1
#
# The lockfile was clean. npm's advisory service was not. A required security
# gate that goes red because a third party had a bad ten minutes trains people
# to re-run red checks until they turn green, which is how a real advisory
# eventually gets clicked past.
#
# The opposite fix — swallowing the failure — is worse, and is the exact defect
# M45 was created to remove. So this script does neither. It retries a
# *transient* failure a bounded number of times and, if the service still will
# not answer, reports UNAVAILABLE and exits non-zero.
#
# ## The three answers, and why UNAVAILABLE is not a fourth kind of pass
#
#   PASS         npm audited the tree and found nothing at or above the
#                threshold.                                       exit 0
#   VULNERABLE   npm audited the tree and found something.        exit 1
#   UNAVAILABLE  npm could not produce trustworthy evidence.      exit 3
#
# All three are reported in words as well as exit codes, because "exit 1" was
# what made the 2026-09-04 incident take six minutes of log archaeology to
# diagnose: a vulnerability and a dead endpoint were indistinguishable.
#
# UNAVAILABLE exits non-zero deliberately. Absent evidence is not clean
# evidence, and a gate that cannot see must not wave things through.
#
# ## Why it is also *faster* than not having it
#
# npm's own retry behaviour is generous — `fetch-retries=2` with
# `fetch-retry-maxtimeout=60000` — and serialised across many requests it can
# burn many minutes before giving up. That is not hypothetical: on 2026-09-04
# it consumed nine minutes of `CI · Workflow Integrity`'s ten-minute budget and
# the job was killed mid-step, taking four later controls with it.
#
# So this disables npm's internal retry storm and imposes its own hard
# per-attempt timeout, making the worst case predictable and bounded:
# 3 attempts x 45s + 3s + 9s backoff = 147s. A healthy audit still returns in
# about two seconds, because nothing sleeps on success.
#
# Usage:
#   npm_audit_resilient.sh [--dir DIR] npm audit --audit-level=high
#
# The npm command is passed through verbatim rather than reconstructed, so the
# audit policy stays visible at the call site — in `security.yml`, where
# `verify_dependency_audit_gate.py` reads it — instead of being buried here
# where a threshold could be quietly lowered out of sight.
# =============================================================================

set -uo pipefail

ATTEMPTS="${NPM_AUDIT_ATTEMPTS:-3}"
ATTEMPT_TIMEOUT="${NPM_AUDIT_TIMEOUT:-45}"
# Backoff before attempts 2 and 3. Short: a 503 either clears quickly or is an
# outage, and an outage should be reported, not waited out inside a CI job.
BACKOFF_SECONDS="${NPM_AUDIT_BACKOFF:-3 9}"

EXIT_PASS=0
EXIT_VULNERABLE=1
EXIT_UNAVAILABLE=3

workdir="."
if [[ "${1:-}" == "--dir" ]]; then
  workdir="${2:?--dir needs a directory}"
  shift 2
fi

if [[ $# -eq 0 ]]; then
  echo "usage: $(basename "$0") [--dir DIR] npm audit --audit-level=high" >&2
  exit "$EXIT_UNAVAILABLE"
fi

# Transient: the service is there but not answering right now. Retryable.
TRANSIENT='audit (429|500|502|503|504) |Service Unavailable|Bad Gateway|Gateway Timeout|Too Many Requests|ETIMEDOUT|ECONNRESET|ECONNREFUSED|EAI_AGAIN|ENOTFOUND|ERR_SOCKET_TIMEOUT|socket timeout|network timeout|request to .* failed|audit endpoint returned an error'

# Malformed: npm reached the service and the exchange was structurally wrong.
# Retrying cannot fix this, so it fails closed immediately rather than burning
# the retry budget.
MALFORMED='Invalid package tree|Unexpected token|Unexpected end of JSON|Invalid response body|ENOLOCK|EUSAGE|EJSONPARSE'

# A genuine verdict. Checked BEFORE the transient patterns so that a real
# finding can never be downgraded to "the service was down" — the one
# misclassification that would make this script a security regression.
VERDICT='vulnerabilit(y|ies)|[0-9]+ (low|moderate|high|critical) severity'

say() { printf '%s\n' "$*"; }

attempt_number=1
last_output=""
last_code=0

while :; do
  # No `set -e` in this script, deliberately: the whole point is to read the
  # audit's exit code and classify it, which errexit would pre-empt.
  output="$(cd "$workdir" && \
    npm_config_fetch_retries=0 \
    npm_config_fetch_timeout=$((ATTEMPT_TIMEOUT * 1000)) \
    timeout "$ATTEMPT_TIMEOUT" "$@" 2>&1)"
  code=$?

  last_output="$output"
  last_code=$code

  printf '%s\n' "$output"

  # ---- clean ----------------------------------------------------------------
  # npm exits 0 only when it audited and found nothing at the threshold. The
  # extra guard is for the pathological case of a zero exit alongside an error
  # banner: uncertain evidence fails closed rather than passing.
  if [[ "$code" -eq 0 ]]; then
    if grep -qiE "$TRANSIENT|$MALFORMED" <<<"$output"; then
      say ""
      say "SECURITY AUDIT: UNAVAILABLE — npm exited 0 but reported an audit error."
      say "  Refusing to read that as a clean audit."
      exit "$EXIT_UNAVAILABLE"
    fi
    say ""
    say "SECURITY AUDIT: PASS — no advisories at or above the configured threshold."
    exit "$EXIT_PASS"
  fi

  # ---- a real finding -------------------------------------------------------
  if grep -qiE "$VERDICT" <<<"$output"; then
    say ""
    say "SECURITY AUDIT: VULNERABLE — npm audited the dependency tree and found advisories."
    say "  This is a real finding. Fix or upgrade the dependency; do not retry."
    exit "$EXIT_VULNERABLE"
  fi

  # ---- structurally wrong: no amount of retrying helps -----------------------
  if grep -qiE "$MALFORMED" <<<"$output"; then
    say ""
    say "SECURITY AUDIT: UNAVAILABLE — the audit response or dependency tree was malformed."
    say "  Failing closed without retrying; retrying cannot repair this."
    exit "$EXIT_UNAVAILABLE"
  fi

  # ---- transient: retry, up to the bound ------------------------------------
  # `timeout` reports 124 when it kills the attempt, which is the same class of
  # event as a socket timeout and is retried alongside it.
  if grep -qiE "$TRANSIENT" <<<"$output" || [[ "$code" -eq 124 ]]; then
    if [[ "$attempt_number" -ge "$ATTEMPTS" ]]; then
      break
    fi
    read -r -a backoff <<<"$BACKOFF_SECONDS"
    wait_for="${backoff[$((attempt_number - 1))]:-${backoff[-1]:-5}}"
    say ""
    say "npm audit attempt ${attempt_number}/${ATTEMPTS} hit a transient failure; retrying in ${wait_for}s."
    sleep "$wait_for"
    attempt_number=$((attempt_number + 1))
    continue
  fi

  # ---- anything else --------------------------------------------------------
  # Conservative by instruction: an unrecognised non-zero exit is not evidence
  # of a clean tree, so it is not treated as one.
  say ""
  say "SECURITY AUDIT: UNAVAILABLE — npm audit failed in a way this script does not recognise (exit ${code})."
  say "  Failing closed rather than assuming the dependency tree is clean."
  exit "$EXIT_UNAVAILABLE"
done

say ""
say "SECURITY AUDIT: UNAVAILABLE — audit service could not be reached after ${ATTEMPTS} attempts."
say "  Last exit ${last_code}. No trustworthy security evidence was obtained, so this"
say "  is a failure, not a pass. Re-run once npm's advisory service recovers."
grep -oiE "$TRANSIENT" <<<"$last_output" | sort -u | sed 's/^/  seen: /'
exit "$EXIT_UNAVAILABLE"
