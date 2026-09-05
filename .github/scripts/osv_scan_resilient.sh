#!/usr/bin/env bash
# =============================================================================
# OSV-Scanner over a lockfile, with bounded retry on transient failure.
#
# The Dart half of the protocol M48 built for npm and M49 gave to composer.
# Same three answers, same refusal to call an absent scan a clean one:
#
#   PASS         the scanner queried OSV and found nothing.          exit 0
#   VULNERABLE   the scanner queried OSV and found advisories.       exit 1
#   UNAVAILABLE  no trustworthy evidence could be obtained.          exit 3
#
# ## Why Dart needed this at all (M50-10)
#
# `npm audit` and `composer audit` both fail the required `Dependency audit`
# context on a HIGH advisory. Dart had nothing: `flutter pub get` resolves,
# `flutter analyze` lints, `flutter test` tests, and not one of them looks at
# whether a package in `pubspec.lock` has a known vulnerability. The mobile app
# shipped third-party code that no gate had ever examined, while the web and API
# trees were gated on every pull request. That asymmetry is the finding.
#
# `dart pub outdated` is NOT a substitute and is deliberately not used here: it
# reports version currency, not advisories. A dependency can be perfectly
# up to date and still carry a CVE, and a scanner that cannot tell you that is
# theatre.
#
# ## Exit codes, and the two that are not verdicts
#
# osv-scanner returns 0 for clean and 1 for "vulnerabilities found". Everything
# else is an error, and two of those errors are traps:
#
#   128  no packages were found to scan. A zero-finding result over zero
#        packages is not a clean bill of health — it is the composer
#        "No packages - skipping audit." defect in another ecosystem, and it is
#        classified UNAVAILABLE rather than PASS.
#   127  a general failure, which includes the OSV API being unreachable. Never
#        a verdict; retried while it looks transient, UNAVAILABLE if it persists.
#
# ## Ordering
#
# The verdict is matched BEFORE any outage pattern, via the shared classifier.
# A run that hit a 503, retried, and then found real advisories must report the
# finding, not the outage. `rl_classify` enforces that order so this script does
# not have to remember it.
#
# Usage:
#   osv_scan_resilient.sh --lockfile PATH
#
# Environment: OSV_SCAN_ATTEMPTS, OSV_SCAN_TIMEOUT, OSV_SCAN_BACKOFF, OSV_SCANNER_BIN.
# =============================================================================

set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source-path=SCRIPTDIR
# shellcheck source=lib/reliability_classify.sh
. "$HERE/lib/reliability_classify.sh"

ATTEMPTS="${OSV_SCAN_ATTEMPTS:-2}"
ATTEMPT_TIMEOUT="${OSV_SCAN_TIMEOUT:-25}"
BACKOFF_SECONDS="${OSV_SCAN_BACKOFF:-4}"
SCANNER="${OSV_SCANNER_BIN:-osv-scanner}"

EXIT_PASS=0
EXIT_VULNERABLE=1
EXIT_UNAVAILABLE=3

say() { printf '%s\n' "$*" >&2; }

LOCKFILE=""
case "${1:-}" in
  --lockfile)   LOCKFILE="${2:-}" ;;
  --lockfile=*) LOCKFILE="${1#--lockfile=}" ;;
esac

if [[ -z "$LOCKFILE" ]]; then
  say "SECURITY AUDIT: UNAVAILABLE — no --lockfile given, so nothing was scanned."
  exit "$EXIT_UNAVAILABLE"
fi

if [[ ! -f "$LOCKFILE" ]]; then
  say "SECURITY AUDIT: UNAVAILABLE — lockfile '$LOCKFILE' does not exist."
  say "  A scan that cannot find its input has produced no evidence, and absent"
  say "  evidence is not clean evidence."
  exit "$EXIT_UNAVAILABLE"
fi

# Advisory IDENTIFIERS only — never prose.
#
# This first read `known vulnerabilit(y|ies)|...`, and the first live test
# against a blocked OSV endpoint reported VULNERABLE for an outage. The reason
# is worth keeping: osv-scanner prints a summary line on EVERY run, including
# failed ones —
#
#     Total 0 packages affected by 0 known vulnerabilities ...
#
# — so a pattern matching the phrase matched the sentence that says there are
# none. The failure was in the safe direction, but a gate that manufactures a
# security finding out of a network error is still lying about what it saw.
#
# An advisory identifier cannot appear in a connection error, so the identifiers
# are the pattern. osv-scanner's exit 1 already carries the normal finding path;
# this only catches a finding arriving with an unexpected code.
VERDICT='GHSA-[0-9a-zA-Z]{4}|CVE-[0-9]{4}-|OSV-[0-9]{4}-|PUB-[0-9]{4}-'

attempt=0
last_code=0
read -r -a backoffs <<< "$BACKOFF_SECONDS"

while :; do
  attempt=$((attempt + 1))

  # Captured, not piped: the exit code has to survive for classification, and a
  # pipeline would hand us the wrong one.
  output="$(timeout "$ATTEMPT_TIMEOUT" "$SCANNER" scan source --lockfile="$LOCKFILE" 2>&1)"
  code=$?
  last_code=$code

  printf '%s\n' "$output"

  # ---- 0 is clean, unless it scanned nothing ------------------------------
  if [[ $code -eq 0 ]]; then
    if printf '%s' "$output" | grep -qiE 'found 0 packages|no packages'; then
      say "SECURITY AUDIT: UNAVAILABLE — the scanner found no packages in '$LOCKFILE'."
      say "  Zero findings over zero packages is not a clean audit; it is an audit"
      say "  that did not happen. Treated as a failure, deliberately."
      exit "$EXIT_UNAVAILABLE"
    fi
    say "SECURITY AUDIT: PASS — no known vulnerabilities in '$LOCKFILE'."
    exit "$EXIT_PASS"
  fi

  # ---- 1 is the one real verdict ------------------------------------------
  if [[ $code -eq 1 ]]; then
    say "SECURITY AUDIT: VULNERABLE — osv-scanner reported known vulnerabilities."
    say "  This is a finding, not an outage. Fix or update the dependency."
    exit "$EXIT_VULNERABLE"
  fi

  # ---- 128 means nothing was scanned --------------------------------------
  if [[ $code -eq 128 ]]; then
    say "SECURITY AUDIT: UNAVAILABLE — osv-scanner found nothing to scan (exit 128)."
    exit "$EXIT_UNAVAILABLE"
  fi

  # ---- everything else goes through the shared classifier ------------------
  class="$(rl_classify "$output" "$code" "$VERDICT")"

  case "$class" in
    verdict)
      say "SECURITY AUDIT: VULNERABLE — advisory content present in the scanner output."
      exit "$EXIT_VULNERABLE"
      ;;
    malformed)
      say "SECURITY AUDIT: UNAVAILABLE — the OSV response was malformed (exit ${code})."
      say "  Retrying cannot repair a structurally wrong exchange, so this fails now."
      exit "$EXIT_UNAVAILABLE"
      ;;
    transient)
      if [[ $attempt -ge $ATTEMPTS ]]; then
        break
      fi
      wait_for="${backoffs[$((attempt - 1))]:-${backoffs[${#backoffs[@]} - 1]}}"
      say "osv-scanner attempt ${attempt}/${ATTEMPTS} hit a transient failure (exit ${code}); retrying in ${wait_for}s."
      sleep "$wait_for"
      ;;
    *)
      # Conservative by design: an unrecognised non-zero exit is not evidence
      # of anything, and guessing in a security tool is how a real finding
      # becomes a green tick.
      say "SECURITY AUDIT: UNAVAILABLE — osv-scanner failed in a way this script does not recognise (exit ${code})."
      exit "$EXIT_UNAVAILABLE"
      ;;
  esac
done

say "SECURITY AUDIT: UNAVAILABLE — OSV could not be reached after ${ATTEMPTS} attempts."
say "  Last exit ${last_code}. No trustworthy security evidence was obtained, so this"
say "  exits non-zero: a gate that cannot see must not wave anything through."
exit "$EXIT_UNAVAILABLE"
