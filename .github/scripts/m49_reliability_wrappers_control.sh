#!/usr/bin/env bash
# =============================================================================
# The controls on the Phase 1 reliability wrappers.
#
# Three wrappers stand between this repository's gates and three third parties:
# `composer_audit_resilient.sh` (Packagist), `bounded_download.sh` (GitHub
# Releases) and the retried baseline fetch inside the M45 control (GitHub git).
# Each of them retries. Each of them therefore points the same way when it is
# wrong: towards passing. "Retry until the service answers" and "retry until it
# stops objecting" are one edit apart and both print green.
#
# So this proves the answers stay distinguishable, driving STUBS rather than
# the real services. That is not a convenience. A control that needs Packagist
# to be up cannot describe what happens when Packagist is down, which is the
# only question these wrappers exist to answer — and M45's Part B spent two
# whole milestones reporting nothing because it depended on live endpoints.
#
# Usage: .github/scripts/m49_reliability_wrappers_control.sh
# =============================================================================

set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT" || exit 1

COMPOSER_WRAPPER=".github/scripts/composer_audit_resilient.sh"
DOWNLOAD_WRAPPER=".github/scripts/bounded_download.sh"
RUNNER=".github/scripts/run_control.sh"
MANIFEST_VERIFIER=".github/scripts/verify_control_manifest.py"
OSV_WRAPPER=".github/scripts/osv_scan_resilient.sh"

EXIT_PASS=0
EXIT_VULNERABLE=1
EXIT_UNAVAILABLE=3

passed=0
failed=0
declare -a failures=()
ok()  { printf '  PASS  %s\n' "$1"; passed=$((passed + 1)); }
bad() { printf '  FAIL  %s\n' "$1"; failed=$((failed + 1)); failures+=("$1"); }

fingerprint() {
  { sha256sum "$COMPOSER_WRAPPER" "$DOWNLOAD_WRAPPER" "$RUNNER" "$OSV_WRAPPER" \
      ".github/scripts/lib/reliability_classify.sh"; } | sha256sum | cut -d' ' -f1
}
before="$(fingerprint)"

sandbox="$(mktemp -d "${TMPDIR:-/tmp}/m49-wrappers-XXXXXXXX")"
trap 'rm -rf "$sandbox"' EXIT
mkdir -p "$sandbox/bin" "$sandbox/project"

# ---------------------------------------------------------------------------
# A stub `composer`, replaying one line of a scenario file per invocation and
# counting how many times it was called. The count is what proves "does not
# retry a real finding": a wrapper that retried a vulnerability would
# eventually be tempted to give up and call it unavailable.
# ---------------------------------------------------------------------------
cat > "$sandbox/bin/composer" <<'STUB'
#!/usr/bin/env bash
count_file="$STUB_COUNT"
n=$(( $(cat "$count_file" 2>/dev/null || echo 0) + 1 ))
echo "$n" > "$count_file"
line="$(sed -n "${n}p" "$STUB_SCENARIO")"
[[ -z "$line" ]] && line="$(tail -n 1 "$STUB_SCENARIO")"
code="${line%%|*}"; text="${line#*|}"
if [[ "$code" == "SLEEP" ]]; then sleep "$text"; exit 0; fi
printf '%b\n' "$text"
exit "$code"
STUB
chmod +x "$sandbox/bin/composer"

run_composer() {
  printf '%s\n' "$1" > "$sandbox/scenario"
  : > "$sandbox/count"
  PATH="$sandbox/bin:$PATH" \
  STUB_SCENARIO="$sandbox/scenario" STUB_COUNT="$sandbox/count" \
  COMPOSER_AUDIT_ATTEMPTS=3 COMPOSER_AUDIT_TIMEOUT=2 COMPOSER_AUDIT_BACKOFF="0 0" \
    "$REPO_ROOT/$COMPOSER_WRAPPER" --dir "$sandbox/project" composer audit --locked 2>&1
}
attempts_made() { cat "$sandbox/count" 2>/dev/null || echo 0; }

expect_composer() {
  local label="$1" want_exit="$2" want_text="$3" want_attempts="${4:-}" scenario="$5"
  local out code problems=""
  out="$(run_composer "$scenario")"; code=$?
  [[ "$code" -eq "$want_exit" ]] || problems+="exit $code, wanted $want_exit; "
  grep -qF "$want_text" <<<"$out" || problems+="output lacked '$want_text'; "
  if [[ -n "$want_attempts" ]]; then
    local made; made="$(attempts_made)"
    [[ "$made" -eq "$want_attempts" ]] || problems+="composer called $made times, wanted $want_attempts; "
  fi
  if [[ -z "$problems" ]]; then ok "$label"; else bad "$label — ${problems}"; fi
}

echo "=============================================================================="
echo "Phase 1 — reliability wrapper outage simulations"
echo "  subjects: $COMPOSER_WRAPPER"
echo "            $DOWNLOAD_WRAPPER"
echo "            $RUNNER + $MANIFEST_VERIFIER"
echo "=============================================================================="

echo
echo "A) composer audit — PASS=0, VULNERABLE=1, UNAVAILABLE=3"

CLEAN='0|No security vulnerability advisories found.'
VULN='1|Found 2 security vulnerability advisories affecting 2 packages.\nlaminas/laminas-diactoros  CVE-2023-29197'
E429='100|curl error 429 while downloading https://packagist.org/api/security-advisories/: Too Many Requests'
E500='100|curl error 500 while downloading https://packagist.org/api/security-advisories/: Internal Server Error'
E502='100|curl error 502 while downloading https://packagist.org/api/security-advisories/: Bad Gateway'
E503='100|curl error 503 while downloading https://packagist.org/api/security-advisories/: Service Unavailable'
E504='100|curl error 504 while downloading https://packagist.org/api/security-advisories/: Gateway Timeout'
ETIMEOUT='100|curl error 28 while downloading https://packagist.org/api/security-advisories/: Connection timed out after 30001 milliseconds'
EDNS='100|curl error 6 while downloading https://packagist.org/api/security-advisories/: Could not resolve host: packagist.org'
MALFORMED='100|[Composer\\Json\\JsonValidationException] "https://packagist.org/api/security-advisories/" does not contain valid JSON\nParse error on line 1'
NOPACKAGES='0|No packages - skipping audit.'

expect_composer "1. a clean audit is PASS and exits 0" \
  "$EXIT_PASS" "SECURITY AUDIT: PASS" 1 "$CLEAN"
expect_composer "2. an advisory is VULNERABLE and exits non-zero" \
  "$EXIT_VULNERABLE" "SECURITY AUDIT: VULNERABLE" 1 "$VULN"
expect_composer "3. HTTP 429 then success retries and ends PASS" \
  "$EXIT_PASS" "SECURITY AUDIT: PASS" 2 "$E429
$CLEAN"
expect_composer "4a. HTTP 500 then success is PASS" "$EXIT_PASS" "SECURITY AUDIT: PASS" 2 "$E500
$CLEAN"
expect_composer "4b. HTTP 502 then success is PASS" "$EXIT_PASS" "SECURITY AUDIT: PASS" 2 "$E502
$CLEAN"
expect_composer "4c. HTTP 503 then success is PASS" "$EXIT_PASS" "SECURITY AUDIT: PASS" 2 "$E503
$CLEAN"
expect_composer "4d. HTTP 504 then success is PASS" "$EXIT_PASS" "SECURITY AUDIT: PASS" 2 "$E504
$CLEAN"
expect_composer "5. a connection timeout then success is PASS" \
  "$EXIT_PASS" "SECURITY AUDIT: PASS" 2 "$ETIMEOUT
$CLEAN"
expect_composer "6. a DNS failure then success is PASS" \
  "$EXIT_PASS" "SECURITY AUDIT: PASS" 2 "$EDNS
$CLEAN"
expect_composer "7. a persistent outage is UNAVAILABLE, never PASS" \
  "$EXIT_UNAVAILABLE" "SECURITY AUDIT: UNAVAILABLE" 3 "$E503
$E503
$E503"
expect_composer "8. a malformed advisory response fails closed without retrying" \
  "$EXIT_UNAVAILABLE" "malformed" 1 "$MALFORMED"
expect_composer "9. a hung request is killed and reported UNAVAILABLE" \
  "$EXIT_UNAVAILABLE" "SECURITY AUDIT: UNAVAILABLE" 3 "SLEEP|5
SLEEP|5
SLEEP|5"
expect_composer "10. an unknown non-zero exit fails closed" \
  "$EXIT_UNAVAILABLE" "does not recognise" 1 '2|something nobody has seen before'
expect_composer "11. a finding is never downgraded to UNAVAILABLE" \
  "$EXIT_VULNERABLE" "SECURITY AUDIT: VULNERABLE" 1 "$VULN"
expect_composer "12. a finding printed alongside a 503 banner is still VULNERABLE" \
  "$EXIT_VULNERABLE" "SECURITY AUDIT: VULNERABLE" 1 \
  '1|curl error 503 Service Unavailable\nFound 1 security vulnerability advisory affecting 1 package.'
expect_composer "13. exit 0 alongside an error banner is refused, not read as clean" \
  "$EXIT_UNAVAILABLE" "SECURITY AUDIT: UNAVAILABLE" 1 \
  '0|curl error 28 while downloading https://packagist.org/api/security-advisories/: Connection timed out'
# The M45 defect, in wrapper form: composer exits 0 having audited nothing.
expect_composer "14. 'No packages - skipping audit' is UNAVAILABLE, not PASS" \
  "$EXIT_UNAVAILABLE" "audited nothing" 1 "$NOPACKAGES"

# ---------------------------------------------------------------------------
echo
echo "B) bounded download — transport retried, checksum never retried"

# A stub `curl` on PATH. It honours --output and --write-out enough for the
# wrapper, and replays a scenario the same way.
cat > "$sandbox/bin/curl" <<'STUB'
#!/usr/bin/env bash
count_file="$STUB_COUNT"
n=$(( $(cat "$count_file" 2>/dev/null || echo 0) + 1 ))
echo "$n" > "$count_file"
out=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --output) out="$2"; shift 2 ;;
    *) shift ;;
  esac
done
line="$(sed -n "${n}p" "$STUB_SCENARIO")"
[[ -z "$line" ]] && line="$(tail -n 1 "$STUB_SCENARIO")"
code="${line%%|*}"; rest="${line#*|}"
body="${rest%%|*}"; msg="${rest#*|}"
if [[ "$code" == "0" ]]; then
  printf '%s' "$body" > "$out"
  printf 'HTTPSTATUS:200'
  exit 0
fi
printf '%s' "$msg"
exit "$code"
STUB
chmod +x "$sandbox/bin/curl"

GOOD_BODY="the-real-archive-bytes"
GOOD_SHA="$(printf '%s' "$GOOD_BODY" | sha256sum | cut -d' ' -f1)"
WRONG_SHA="0000000000000000000000000000000000000000000000000000000000000000"

run_download() {
  local scenario="$1" sha="$2"
  printf '%s\n' "$scenario" > "$sandbox/scenario"
  : > "$sandbox/count"
  PATH="$sandbox/bin:$PATH" \
  STUB_SCENARIO="$sandbox/scenario" STUB_COUNT="$sandbox/count" \
  DOWNLOAD_ATTEMPTS=3 DOWNLOAD_MAX_TIME=2 DOWNLOAD_BACKOFF="0 0" \
    "$REPO_ROOT/$DOWNLOAD_WRAPPER" \
      --url "https://example.invalid/archive.tar.gz" \
      --output "$sandbox/archive.bin" \
      --sha256 "$sha" 2>&1
}

expect_download() {
  local label="$1" want_exit="$2" want_text="$3" want_attempts="$4" scenario="$5" sha="$6"
  local out code problems=""
  out="$(run_download "$scenario" "$sha")"; code=$?
  [[ "$code" -eq "$want_exit" ]] || problems+="exit $code, wanted $want_exit; "
  grep -qF "$want_text" <<<"$out" || problems+="output lacked '$want_text'; "
  local made; made="$(attempts_made)"
  [[ "$made" -eq "$want_attempts" ]] || problems+="curl called $made times, wanted $want_attempts; "
  if [[ -z "$problems" ]]; then ok "$label"; else bad "$label — ${problems}"; fi
}

OK_LINE="0|${GOOD_BODY}|"
expect_download "15. a healthy download verifies and exits 0" \
  0 "DOWNLOAD: OK" 1 "$OK_LINE" "$GOOD_SHA"
expect_download "16. HTTP 429 then success retries and succeeds" \
  0 "DOWNLOAD: OK" 2 "22||HTTPSTATUS:429 Too Many Requests
$OK_LINE" "$GOOD_SHA"
expect_download "17a. HTTP 500 then success succeeds" 0 "DOWNLOAD: OK" 2 "22||HTTPSTATUS:500 Internal Server Error
$OK_LINE" "$GOOD_SHA"
expect_download "17b. HTTP 502 then success succeeds" 0 "DOWNLOAD: OK" 2 "22||HTTPSTATUS:502 Bad Gateway
$OK_LINE" "$GOOD_SHA"
expect_download "17c. HTTP 503 then success succeeds" 0 "DOWNLOAD: OK" 2 "22||HTTPSTATUS:503 Service Unavailable
$OK_LINE" "$GOOD_SHA"
expect_download "17d. HTTP 504 then success succeeds" 0 "DOWNLOAD: OK" 2 "22||HTTPSTATUS:504 Gateway Timeout
$OK_LINE" "$GOOD_SHA"
expect_download "18. a connection timeout is retried then reported UNAVAILABLE" \
  3 "DOWNLOAD: UNAVAILABLE" 3 "28||Operation timed out after 45000 milliseconds
28||Operation timed out after 45000 milliseconds
28||Operation timed out after 45000 milliseconds" "$GOOD_SHA"
expect_download "19. a DNS failure is retried then reported UNAVAILABLE" \
  3 "DOWNLOAD: UNAVAILABLE" 3 "6||Could not resolve host: example.invalid
6||Could not resolve host: example.invalid
6||Could not resolve host: example.invalid" "$GOOD_SHA"
# A 404 is a permanent repository defect: the pinned version does not exist.
# Retrying it three times just spends the budget to be told the same thing.
expect_download "20. HTTP 404 fails immediately without retrying" \
  3 "permanent error" 1 "22||HTTPSTATUS:404 Not Found" "$GOOD_SHA"
expect_download "21. a corrupt archive fails the checksum and is NOT retried" \
  3 "CHECKSUM MISMATCH" 1 "0|corrupted-bytes|" "$GOOD_SHA"
expect_download "22. a checksum mismatch is a hard failure, never a retry loop" \
  3 "This is not retried" 1 "$OK_LINE" "$WRONG_SHA"
expect_download "23. an empty body that reports success is refused" \
  3 "the file is empty" 1 "0||" "$GOOD_SHA"

# The archive must not survive a failed verification: nothing unverified may
# be left on disk where a later step could extract it.
if [[ -e "$sandbox/archive.bin" ]]; then
  bad "24. a failed download left its archive on disk"
else
  ok "24. a failed download leaves no archive for a later step to extract"
fi

# ---------------------------------------------------------------------------
echo
echo "C) control manifest — NOT_RUN is not PASS"

MDIR="$sandbox/manifest"
mrun() {
  CONTROL_MANIFEST_DIR="$MDIR" "$REPO_ROOT/$RUNNER" "$1" -- "${@:2}"
}
mverify() {
  python3 "$REPO_ROOT/$MANIFEST_VERIFIER" \
    --manifest-dir "$MDIR" --policy "$sandbox/policy.json" 2>&1
}
# A miniature policy: two mandatory controls.
cat > "$sandbox/policy.json" <<'JSON'
{ "control_manifest": { "mandatory_controls": ["alpha", "beta"] } }
JSON

rm -rf "$MDIR"
mrun alpha true >/dev/null 2>&1
mrun beta true >/dev/null 2>&1
out="$(mverify)"; code=$?
if [[ "$code" -eq 0 ]]; then ok "25. both mandatory controls ran and passed -> exit 0"
else bad "25. a complete, passing manifest was rejected (exit $code): $out"; fi

# 26 — a control that never ran
rm -rf "$MDIR"; mrun alpha true >/dev/null 2>&1
out="$(mverify)"; code=$?
if [[ "$code" -ne 0 ]] && grep -q "NOT_RUN" <<<"$out"; then
  ok "26. a mandatory control that never ran is NOT_RUN and fails"
else bad "26. a missing mandatory control did not fail (exit $code)"; fi

# 27 — a control that ran and failed
rm -rf "$MDIR"; mrun alpha true >/dev/null 2>&1; mrun beta false >/dev/null 2>&1
out="$(mverify)"; code=$?
if [[ "$code" -ne 0 ]] && grep -q "beta: FAIL" <<<"$out"; then
  ok "27. a control that ran and failed is recorded FAIL and fails the manifest"
else bad "27. a failed control did not fail the manifest (exit $code)"; fi

# 28 — UNAVAILABLE (exit 3) is not PASS
rm -rf "$MDIR"; mrun alpha true >/dev/null 2>&1
mrun beta bash -c 'exit 3' >/dev/null 2>&1
out="$(mverify)"; code=$?
if [[ "$code" -ne 0 ]] && grep -q "beta: UNAVAILABLE" <<<"$out"; then
  ok "28. an UNAVAILABLE control is not accepted as PASS"
else bad "28. UNAVAILABLE was accepted (exit $code)"; fi

# 29 — a forged PASS: a record claiming PASS on a non-zero exit
rm -rf "$MDIR"; mrun alpha true >/dev/null 2>&1; mrun beta true >/dev/null 2>&1
python3 - "$MDIR/beta.json" <<'PY'
import json, sys
p = sys.argv[1]; d = json.load(open(p))
d["exit_code"] = 1              # it actually failed ...
d["verdict"] = "PASS"           # ... but the record claims otherwise
json.dump(d, open(p, "w"))
PY
out="$(mverify)"; code=$?
if [[ "$code" -ne 0 ]] && grep -q "contradicts" <<<"$out"; then
  ok "29. a forged PASS on a non-zero exit is rejected"
else bad "29. a forged PASS was accepted (exit $code)"; fi

# 30 — an incomplete record
rm -rf "$MDIR"; mrun alpha true >/dev/null 2>&1; mrun beta true >/dev/null 2>&1
python3 - "$MDIR/beta.json" <<'PY'
import json, sys
p = sys.argv[1]; d = json.load(open(p)); d.pop("exit_code")
json.dump(d, open(p, "w"))
PY
out="$(mverify)"; code=$?
if [[ "$code" -ne 0 ]] && grep -qi "incomplete record" <<<"$out"; then
  ok "30. an incomplete completion record is rejected"
else bad "30. an incomplete record was accepted (exit $code)"; fi

# 31 — a duplicate sequence number (a record slotted in after the fact)
rm -rf "$MDIR"; mrun alpha true >/dev/null 2>&1; mrun beta true >/dev/null 2>&1
python3 - "$MDIR/beta.json" <<'PY'
import json, sys
p = sys.argv[1]; d = json.load(open(p)); d["seq"] = 1
json.dump(d, open(p, "w"))
PY
out="$(mverify)"; code=$?
if [[ "$code" -ne 0 ]] && grep -qi "gapless" <<<"$out"; then
  ok "31. a duplicated sequence number is rejected"
else bad "31. a duplicated sequence was accepted (exit $code)"; fi

# 32 — a control nobody declared
rm -rf "$MDIR"; mrun alpha true >/dev/null 2>&1; mrun beta true >/dev/null 2>&1
mrun gamma true >/dev/null 2>&1
out="$(mverify)"; code=$?
if [[ "$code" -ne 0 ]] && grep -qi "unexpected control" <<<"$out"; then
  ok "32. a control the policy does not declare is rejected"
else bad "32. an undeclared control was accepted (exit $code)"; fi

# 33 — the runner must never turn a failure into a success
rm -rf "$MDIR"
CONTROL_MANIFEST_DIR="$MDIR" "$REPO_ROOT/$RUNNER" alpha -- bash -c 'exit 7' >/dev/null 2>&1
rc=$?
if [[ "$rc" -eq 7 ]]; then
  ok "33. run_control.sh propagates the control's own exit code unchanged"
else bad "33. run_control.sh returned $rc for a control that exited 7"; fi

# 34 — two steps claiming the same control id is a wiring mistake, not a
#      silent overwrite of whichever ran first
rm -rf "$MDIR"; mrun alpha true >/dev/null 2>&1
if CONTROL_MANIFEST_DIR="$MDIR" "$REPO_ROOT/$RUNNER" alpha -- true >/dev/null 2>&1; then
  bad "34. a duplicate control id silently overwrote the first record"
else
  ok "34. a duplicate control id is refused at record time"
fi

# ---------------------------------------------------------------------------
# M50-10 — the Dart scanner wrapper, driven by a stub, never the real OSV API.
#
# The same reason every outage simulation in this file drives a stub: a control
# that needs a service to be UP cannot describe what happens when it is DOWN,
# and "what happens when it is down" is the entire question.
# ---------------------------------------------------------------------------
echo
echo "C2) osv_scan_resilient.sh — three verdicts over a stubbed scanner"

cat > "$sandbox/bin/osv-scanner" <<'STUB'
#!/usr/bin/env bash
count_file="$STUB_COUNT"
n=$(( $(cat "$count_file" 2>/dev/null || echo 0) + 1 ))
echo "$n" > "$count_file"
line="$(sed -n "${n}p" "$STUB_SCENARIO")"
[[ -z "$line" ]] && line="$(tail -n 1 "$STUB_SCENARIO")"
code="${line%%|*}"; text="${line#*|}"
printf '%b\n' "$text"
exit "$code"
STUB
chmod +x "$sandbox/bin/osv-scanner"

: > "$sandbox/project/pubspec.lock"

run_osv() {
  printf '%s\n' "$1" > "$sandbox/scenario"
  : > "$sandbox/count"
  STUB_SCENARIO="$sandbox/scenario" STUB_COUNT="$sandbox/count" \
  OSV_SCANNER_BIN="$sandbox/bin/osv-scanner" \
  OSV_SCAN_ATTEMPTS=3 OSV_SCAN_TIMEOUT=2 OSV_SCAN_BACKOFF="0 0" \
    "$REPO_ROOT/$OSV_WRAPPER" --lockfile "$sandbox/project/pubspec.lock" 2>&1
}

# Same shape as expect_composer above: one helper, so an assertion cannot be
# written as `A && ok || bad`, where C runs when A is true.
expect_osv() {
  local label="$1" want_exit="$2" want_text="$3" want_attempts="${4:-}" scenario="$5"
  local out code problems=""
  out="$(run_osv "$scenario")"; code=$?
  [[ "$code" -eq "$want_exit" ]] || problems+="exit $code, wanted $want_exit; "
  grep -qF "$want_text" <<<"$out" || problems+="output lacked '$want_text'; "
  if [[ -n "$want_attempts" ]]; then
    local made; made="$(attempts_made)"
    [[ "$made" -eq "$want_attempts" ]] || problems+="scanner called $made times, wanted $want_attempts; "
  fi
  if [[ -z "$problems" ]]; then ok "$label"; else bad "$label — ${problems}"; fi
}

expect_osv "1. a clean scan is PASS and exits 0" \
  "$EXIT_PASS" "SECURITY AUDIT: PASS" 1 '0|Scanned file and found 100 packages\nNo issues found'
expect_osv "2. a finding is VULNERABLE, and is asked exactly once" \
  "$EXIT_VULNERABLE" "SECURITY AUDIT: VULNERABLE" 1 '1|GHSA-abcd-1234-wxyz in package foo'

# The one that matters most, and the one the first live run got wrong.
# osv-scanner prints "Total 0 packages affected by 0 known vulnerabilities" on
# EVERY run including failed ones, so a verdict pattern matching that phrase
# turned a blocked endpoint into a manufactured security finding.
expect_osv "3. a blocked OSV endpoint is UNAVAILABLE, not a manufactured finding" \
  "$EXIT_UNAVAILABLE" "SECURITY AUDIT: UNAVAILABLE" "" \
  '127|Error during extraction: request failed: Post "https://api.osv.dev/v1/querybatch": Forbidden\nTotal 0 packages affected by 0 known vulnerabilities (0 Critical, 0 High).'

expect_osv "4. zero findings over zero packages is UNAVAILABLE, never PASS" \
  "$EXIT_UNAVAILABLE" "SECURITY AUDIT: UNAVAILABLE" 1 '0|Scanned file and found 0 packages'
expect_osv "5. exit 128 (nothing scanned) is UNAVAILABLE" \
  "$EXIT_UNAVAILABLE" "SECURITY AUDIT: UNAVAILABLE" 1 '128|no packages found'
expect_osv "6. an exhausted transient failure ends UNAVAILABLE, retried to the bound" \
  "$EXIT_UNAVAILABLE" "SECURITY AUDIT: UNAVAILABLE" 3 '52|Connection reset by peer'

out="$("$REPO_ROOT/$OSV_WRAPPER" --lockfile "$sandbox/definitely-absent.lock" 2>&1)"; code=$?
if [[ $code -eq $EXIT_UNAVAILABLE ]] && grep -qF "UNAVAILABLE" <<<"$out"; then
  ok "7. a missing lockfile is UNAVAILABLE, not a clean scan"
else
  bad "7. a missing lockfile returned $code, expected $EXIT_UNAVAILABLE"
fi

# ---------------------------------------------------------------------------
echo
echo "D) Integrity"
if [[ "$before" == "$(fingerprint)" ]]; then
  ok "the wrappers are byte-identical after this run"
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
  echo "Reliability wrapper controls FAILED."
  exit 1
fi
echo
echo "Outages retry within a bound, findings never do, checksums are never retried,"
echo "and a mandatory control that did not run is never reported as one that passed."
exit 0
