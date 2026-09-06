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

# ---- The three questions asked of every attempt, in this order --------------
#
# An exit code alone is not evidence. This wrapper first read the code and
# decided, and that was wrong in three ways at once: exit 0 beside an error
# banner became PASS, exit 0 with no output at all became PASS, and exit 1
# carrying nothing but a panic became VULNERABLE. All three were verdicts
# asserted about a scan that had not demonstrably happened, and two of them
# pointed at green.
#
# So a verdict now requires POSITIVE PROOF that the scan ran, and the proof is
# checked before the code is trusted.
#
# COMPLETED — osv-scanner prints this line on every real run and on no failed
# one. Without it there is no evidence any lockfile was read, so there is
# nothing to have a verdict about.
COMPLETED='Scanned .*(file|lockfile).* and found [0-9]+ packages'

# ERRORED — any of these voids the run whatever the exit code says. A scan that
# printed an error did not complete, and "it exited 0 anyway" is not a defence:
# that is the composer `No packages - skipping audit.` trap in another
# ecosystem, and composer_audit_resilient.sh has refused it since Phase 1.
ERRORED='Error during extraction|max retries exceeded|request failed|panic:|failed to (open|read|parse)|permission denied|no such file or directory|unable to determine'

# FOUND — a real finding. Advisory identifiers, or the summary line with a
# NON-ZERO count parsed out of it.
#
# The count is the point. `Total 0 packages affected by 0 known vulnerabilities`
# is printed on every run including failed ones, so matching the phrase matches
# the sentence that says there are none — which is exactly how an earlier draft
# of this file reported a blocked endpoint as VULNERABLE. Requiring `[1-9]`
# makes the summary line usable as evidence instead of a trap.
FOUND='GHSA-[0-9a-zA-Z]{4}|CVE-[0-9]{4}-|OSV-[0-9]{4}-|PUB-[0-9]{4}-|affected by [1-9][0-9]* known vulnerabilit'

has() { grep -qiE "$1" <<<"$2"; }

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

  # ---- An error banner voids the run, whatever the exit code claims -------
  if has "$ERRORED" "$output"; then
    say "SECURITY AUDIT: UNAVAILABLE — osv-scanner reported an error (exit ${code})."
    say "  A run that printed an error did not complete, so it has no verdict to"
    say "  give. Exiting 0 alongside an error banner does not make it clean."
    exit "$EXIT_UNAVAILABLE"
  fi

  # ---- No proof the scan ran means no verdict ------------------------------
  if [[ $code -eq 0 || $code -eq 1 ]] && ! has "$COMPLETED" "$output"; then
    say "SECURITY AUDIT: UNAVAILABLE — nothing in the output shows a lockfile was scanned."
    say "  Exit ${code} on its own is not evidence. Absent evidence is not clean"
    say "  evidence, and it is not a finding either."
    exit "$EXIT_UNAVAILABLE"
  fi

  # ---- 0: a completed scan with no finding is the only PASS ---------------
  if [[ $code -eq 0 ]]; then
    if has 'found 0 packages|no packages' "$output"; then
      say "SECURITY AUDIT: UNAVAILABLE — the scanner found no packages in '$LOCKFILE'."
      say "  Zero findings over zero packages is not a clean audit; it is an audit"
      say "  that did not happen. Treated as a failure, deliberately."
      exit "$EXIT_UNAVAILABLE"
    fi
    if has "$FOUND" "$output"; then
      # Contradictory — osv-scanner exits 1 for findings. Report the finding,
      # never the zero. Of the two ways to be wrong here, only one is safe.
      say "SECURITY AUDIT: VULNERABLE — advisory content present despite a zero exit."
      exit "$EXIT_VULNERABLE"
    fi
    say "SECURITY AUDIT: PASS — no known vulnerabilities in '$LOCKFILE'."
    exit "$EXIT_PASS"
  fi

  # ---- 1: a verdict only when the finding is actually there ---------------
  if [[ $code -eq 1 ]]; then
    if has "$FOUND" "$output"; then
      say "SECURITY AUDIT: VULNERABLE — osv-scanner reported known vulnerabilities."
      say "  This is a finding, not an outage. Fix or update the dependency."
      exit "$EXIT_VULNERABLE"
    fi
    say "SECURITY AUDIT: UNAVAILABLE — exit 1 with no advisory identifier and no"
    say "  non-zero vulnerability count. That is an unexplained failure, not a"
    say "  finding, and calling it one would be inventing a security result."
    exit "$EXIT_UNAVAILABLE"
  fi

  # ---- 128 means nothing was scanned --------------------------------------
  if [[ $code -eq 128 ]]; then
    say "SECURITY AUDIT: UNAVAILABLE — osv-scanner found nothing to scan (exit 128)."
    exit "$EXIT_UNAVAILABLE"
  fi

  # ---- everything else: transient or not, but never a verdict -------------
  #
  # The verdict argument is deliberately EMPTY. `rl_classify` skips the verdict
  # check when it is, and an exit code this script does not recognise cannot be
  # evidence of a vulnerability no matter what text accompanies it. Findings
  # arrive on exit 0 or 1, both handled above.
  class="$(rl_classify "$output" "$code" "")"

  case "$class" in
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
