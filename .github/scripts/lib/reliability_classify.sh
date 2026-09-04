#!/usr/bin/env bash
# =============================================================================
# The shared failure classifier for governed network operations.
#
# Three answers, and the order they are asked in is the security property:
#
#   verdict     the tool did its job and is telling us the answer
#   malformed   the exchange was structurally wrong; retrying cannot repair it
#   transient   the service is there but not answering right now
#   unknown     none of the above — treated as failure, never as success
#
# VERDICT IS CHECKED FIRST, ALWAYS. A run that hit a 503, retried, and then
# found real advisories prints both the outage banner and the finding. Asking
# "was there an outage?" before "was there a finding?" downgrades a genuine
# vulnerability to "the service was down", which is the one misclassification
# that would turn this whole layer into a security regression rather than a
# reliability improvement. `rl_classify` enforces the order so that no caller
# has to remember it.
#
# This file is sourced, never executed. It defines patterns and one function
# and touches no global state beyond RL_* names.
#
# ## Why npm_audit_resilient.sh does not source this
#
# It predates this file, ships its own equivalent patterns, and is covered by
# eighteen passing adversarial tests that were run against real GitHub CI. The
# right amount of refactoring for a working, mutation-tested security control
# is none. Instead `verify_ci_reliability.py` asserts that BOTH classifiers
# cover every HTTP status and network token in
# `.github/governance/ci-reliability-policy.json`, so the two cannot drift
# apart silently — which is stronger than sharing code, because it makes the
# drift detectable rather than merely unlikely.
# =============================================================================

# Transient: retryable. Keep in sync with policy.retry.retryable_http_status
# and policy.retry.retryable_network_error_tokens — verify_ci_reliability.py
# fails if a policy entry is not represented here.
RL_TRANSIENT='(^|[^0-9])(408|425|429|500|502|503|504)([^0-9]|$)|Service Unavailable|Bad Gateway|Gateway Time-?out|Too Many Requests|Request Timeout|Internal Server Error|ETIMEDOUT|ECONNRESET|ECONNREFUSED|EAI_AGAIN|ENOTFOUND|ERR_SOCKET_TIMEOUT|socket timeout|network timeout|Connection timed out|Connection reset by peer|Could not resolve host|Operation timed out|Empty reply from server|Resolving timed out|Failed to connect|Connection refused|curl: \([0-9]+\)|temporarily unavailable|server is currently unable'

# Malformed / permanent: fails closed immediately, no retry. A checksum
# mismatch belongs here and not merely because retrying is pointless — a
# mismatched archive is the signature of a corrupted or substituted artefact,
# and retrying until one happens to match is precisely the wrong instinct.
RL_MALFORMED='Invalid package tree|Unexpected token|Unexpected end of JSON|Invalid response body|ENOLOCK|EUSAGE|EJSONPARSE|checksum|does not match|Malformed|not valid JSON|Parse error|invalid or corrupt|unexpected end of file'

# rl_classify <output> <exit_code> <verdict_pattern>
#
# Echoes exactly one of: verdict | malformed | transient | unknown
# The verdict pattern is supplied by the caller because "the tool answered"
# looks different for `composer audit` than for a file download.
rl_classify() {
  local output="$1" exit_code="$2" verdict_pattern="${3:-}"

  if [[ -n "$verdict_pattern" ]] && grep -qiE "$verdict_pattern" <<<"$output"; then
    echo "verdict"; return 0
  fi
  if grep -qiE "$RL_MALFORMED" <<<"$output"; then
    echo "malformed"; return 0
  fi
  # 124 is what `timeout` reports when it kills an attempt, which is the same
  # class of event as a socket timeout and is retried alongside it.
  if [[ "$exit_code" -eq 124 ]] || grep -qiE "$RL_TRANSIENT" <<<"$output"; then
    echo "transient"; return 0
  fi
  echo "unknown"
}

# rl_backoff <attempt_number> <space-separated schedule>
# Returns the delay before the given attempt, reusing the last entry if the
# schedule is shorter than the attempt count.
rl_backoff() {
  local n="$1"; shift
  local -a schedule
  read -r -a schedule <<<"$*"
  [[ ${#schedule[@]} -eq 0 ]] && { echo 5; return 0; }
  local idx=$((n - 1))
  if [[ "$idx" -lt "${#schedule[@]}" ]]; then
    echo "${schedule[$idx]}"
  else
    echo "${schedule[-1]}"
  fi
}
