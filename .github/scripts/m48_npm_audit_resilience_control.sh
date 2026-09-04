#!/usr/bin/env bash
# =============================================================================
# The control on `npm_audit_resilient.sh`.
#
# A retry wrapper around a security gate is a dangerous thing to write, because
# every bug in it points the same way: towards passing. "Retry until it works"
# and "retry until it stops complaining" are one edit apart, and the second one
# still prints green.
#
# So this proves the three answers are actually distinguishable, using a stub
# `npm` on PATH and never the real registry — a control that needs npm's
# advisory service to be up cannot be trusted to describe what happens when it
# is down.
#
# The stub is driven by a scenario file: one line per attempt, `CODE|TEXT`. It
# also records how many times it was called, which is how "does not retry a
# real finding" is tested — a wrapper that retried a vulnerability would
# eventually be tempted to give up and call it unavailable.
#
# Usage: .github/scripts/m48_npm_audit_resilience_control.sh
# =============================================================================

set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT" || exit 1

HELPER=".github/scripts/npm_audit_resilient.sh"
SECURITY_WORKFLOW=".github/workflows/security.yml"

EXIT_PASS=0
EXIT_VULNERABLE=1
EXIT_UNAVAILABLE=3

passed=0
failed=0
declare -a failures=()

ok()  { printf '  PASS  %s\n' "$1"; passed=$((passed + 1)); }
bad() { printf '  FAIL  %s\n' "$1"; failed=$((failed + 1)); failures+=("$1"); }

fingerprint() {
  { sha256sum "$HELPER" "$SECURITY_WORKFLOW"; } | sha256sum | cut -d' ' -f1
}

sandbox="$(mktemp -d "${TMPDIR:-/tmp}/m48-audit-XXXXXXXX")"
trap 'rm -rf "$sandbox"' EXIT

mkdir -p "$sandbox/bin" "$sandbox/project"
cat > "$sandbox/bin/npm" <<'STUB'
#!/usr/bin/env bash
# Deterministic npm stub. Replays $STUB_SCENARIO one line per invocation.
count_file="$STUB_COUNT"
n=$(( $(cat "$count_file" 2>/dev/null || echo 0) + 1 ))
echo "$n" > "$count_file"

line="$(sed -n "${n}p" "$STUB_SCENARIO")"
[[ -z "$line" ]] && line="$(tail -n 1 "$STUB_SCENARIO")"

code="${line%%|*}"
text="${line#*|}"

if [[ "$code" == "SLEEP" ]]; then sleep "$text"; exit 0; fi

printf '%b\n' "$text"
exit "$code"
STUB
chmod +x "$sandbox/bin/npm"

# Run the helper against a scenario. Echoes output; returns the helper's exit.
run_scenario() {
  local scenario="$1"
  printf '%s\n' "$scenario" > "$sandbox/scenario"
  : > "$sandbox/count"
  PATH="$sandbox/bin:$PATH" \
  STUB_SCENARIO="$sandbox/scenario" STUB_COUNT="$sandbox/count" \
  NPM_AUDIT_ATTEMPTS=3 NPM_AUDIT_TIMEOUT=2 NPM_AUDIT_BACKOFF="0 0" \
    "$REPO_ROOT/$HELPER" --dir "$sandbox/project" npm audit --audit-level=high 2>&1
}

attempts_made() { cat "$sandbox/count" 2>/dev/null || echo 0; }

# Assert an exit code, a verdict word in the output, and optionally the number
# of npm invocations.
expect() {
  local label="$1" want_exit="$2" want_text="$3" want_attempts="${4:-}" scenario="$5"
  local out code

  out="$(run_scenario "$scenario")"
  code=$?

  local problems=""
  [[ "$code" -eq "$want_exit" ]] || problems+="exit $code, wanted $want_exit; "
  grep -qF "$want_text" <<<"$out" || problems+="output did not contain '$want_text'; "
  if [[ -n "$want_attempts" ]]; then
    local made; made="$(attempts_made)"
    [[ "$made" -eq "$want_attempts" ]] || problems+="npm called $made times, wanted $want_attempts; "
  fi

  if [[ -z "$problems" ]]; then
    ok "$label"
  else
    bad "$label — ${problems}"
  fi
}

before="$(fingerprint)"

echo "=============================================================================="
echo "M48 — npm audit resilience control"
echo "  subject: $HELPER"
echo "=============================================================================="
echo
echo "Result semantics (PASS=0, VULNERABLE=1, UNAVAILABLE=3)"

CLEAN='0|found 0 vulnerabilities'
VULN='1|nanoid  <3.3.18\nSeverity: high\n1 high severity vulnerability'
E503='1|npm warn audit 503 Service Unavailable - POST https://registry.npmjs.org/-/npm/v1/security/audits/quick\nnpm error audit endpoint returned an error'
E429='1|npm warn audit 429 Too Many Requests - POST https://registry.npmjs.org/-/npm/v1/security/audits/quick'
E500='1|npm warn audit 500 Internal Server Error - POST https://registry.npmjs.org/-/npm/v1/security/audits/quick'
E502='1|npm warn audit 502 Bad Gateway - POST https://registry.npmjs.org/-/npm/v1/security/audits/quick'
E504='1|npm warn audit 504 Gateway Timeout - POST https://registry.npmjs.org/-/npm/v1/security/audits/quick'
MALFORMED='1|npm warn audit 400 Bad Request\nmessage: Invalid package tree, run  npm install  to rebuild your package-lock.json'

# 1
expect "1. a clean audit is PASS and exits 0" \
  "$EXIT_PASS" "SECURITY AUDIT: PASS" 1 "$CLEAN"

# 2
expect "2. a high/critical finding is VULNERABLE and exits non-zero" \
  "$EXIT_VULNERABLE" "SECURITY AUDIT: VULNERABLE" 1 "$VULN"

# 3
expect "3. 503 then a clean audit retries and ends PASS" \
  "$EXIT_PASS" "SECURITY AUDIT: PASS" 2 "$E503
$CLEAN"

# 4
expect "4. 503 on every attempt is UNAVAILABLE, never PASS" \
  "$EXIT_UNAVAILABLE" "SECURITY AUDIT: UNAVAILABLE" 3 "$E503
$E503
$E503"

# 5
expect "5. 429 then success is PASS" \
  "$EXIT_PASS" "SECURITY AUDIT: PASS" 2 "$E429
$CLEAN"

# 6
expect "6a. 500 then success is PASS" "$EXIT_PASS" "SECURITY AUDIT: PASS" 2 "$E500
$CLEAN"
expect "6b. 502 then success is PASS" "$EXIT_PASS" "SECURITY AUDIT: PASS" 2 "$E502
$CLEAN"
expect "6c. 504 then success is PASS" "$EXIT_PASS" "SECURITY AUDIT: PASS" 2 "$E504
$CLEAN"

# 7 — the stub outlives the per-attempt timeout, so `timeout` kills it (124).
expect "7. a persistent network timeout retries then reports UNAVAILABLE" \
  "$EXIT_UNAVAILABLE" "SECURITY AUDIT: UNAVAILABLE" 3 "SLEEP|5
SLEEP|5
SLEEP|5"

# 8 — and does not burn the retry budget on something retrying cannot fix.
expect "8. a malformed audit response fails closed without retrying" \
  "$EXIT_UNAVAILABLE" "malformed" 1 "$MALFORMED"

# 10 — the misclassification that would make this a security regression.
expect "10a. a finding is never reported as UNAVAILABLE" \
  "$EXIT_VULNERABLE" "SECURITY AUDIT: VULNERABLE" 1 "$VULN"
expect "10b. a finding alongside a 503 banner is still VULNERABLE" \
  "$EXIT_VULNERABLE" "SECURITY AUDIT: VULNERABLE" 1 \
  '1|npm warn audit 503 Service Unavailable\nnanoid  <3.3.18\nSeverity: high\n2 high severity vulnerabilities'

# A pass must never be silently produced by an errored run.
expect "11. exit 0 alongside an audit error is refused, not read as clean" \
  "$EXIT_UNAVAILABLE" "SECURITY AUDIT: UNAVAILABLE" 1 \
  '0|npm error audit endpoint returned an error'

# 9 — the security path itself carries no masking.
printf '\nThe security path masks nothing\n'
mask_hits="$(grep -nE '\|\| true|\|\| :|continue-on-error|set \+e|--no-verify' \
  "$HELPER" "$SECURITY_WORKFLOW" | grep -vE '^\S+:[0-9]+:\s*#' || true)"
if [[ -z "$mask_hits" ]]; then
  ok "9a. no '|| true', '|| :', continue-on-error or 'set +e' in the security path"
else
  bad "9a. masking construct present in the security path: ${mask_hits}"
fi

# `set +e` around the captured invocation is how the exit code is read at all;
# what must not exist is a forced success.
if grep -nE '^\s*exit 0\s*$' "$HELPER" >/dev/null 2>&1; then
  bad "9b. the helper contains a bare forced 'exit 0'"
else
  ok "9b. no bare forced 'exit 0' in the helper"
fi

# shellcheck disable=SC2016  # a literal grep pattern, not an expansion
if grep -q 'exit "$EXIT_UNAVAILABLE"' "$HELPER"; then
  ok "9c. UNAVAILABLE exits non-zero (fail closed)"
else
  bad "9c. UNAVAILABLE does not exit non-zero"
fi

# The threshold is stated at the call site, where the M45 validator reads it.
if grep -q 'npm audit --audit-level=high' "$SECURITY_WORKFLOW"; then
  ok "9d. the workflow still states 'npm audit --audit-level=high'"
else
  bad "9d. the workflow no longer states the HIGH threshold"
fi

printf '\nIntegrity\n'
if [[ "$before" == "$(fingerprint)" ]]; then
  ok "the helper and the security workflow are byte-identical after this run"
else
  bad "THE REAL TREE CHANGED during this run"
fi

echo
echo "=============================================================================="
printf 'RESULT: %d passed, %d failed\n' "$passed" "$failed"
if [[ "$failed" -ne 0 ]]; then
  echo
  printf '  - %s\n' "${failures[@]}"
  echo
  echo "npm audit resilience control FAILED."
  exit 1
fi
echo
echo "Clean audits pass, findings fail, transient outages retry, and an audit"
echo "that cannot be performed is never reported as one that succeeded."
exit 0
