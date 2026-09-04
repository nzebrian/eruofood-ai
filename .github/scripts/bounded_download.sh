#!/usr/bin/env bash
# =============================================================================
# Download one file, with bounded retry and a mandatory checksum.
#
# ## Why the required integrity check needed this
#
# `CI · Workflow Integrity` provisions actionlint and shellcheck by fetching
# two release archives from github.com. Before Phase 1 both used a bare
#
#     curl -fsSL -o "$archive" "https://github.com/.../releases/download/..."
#
# with no --max-time, no --connect-timeout and no --retry, as steps 3 and 4 of
# a job with a hard cap. Two distinct failures follow from that. A FAILED
# download turns the required context red for a reason unrelated to the pull
# request — the same third-party-uptime coupling M48 removed from `npm audit`,
# against a different provider. A SLOW download is worse: it eats the job
# budget and the runner cancels mid-control, taking every later control with
# it and leaving no record that they were skipped.
#
# ## The checksum is not negotiable and is not retried
#
# Verification happens BEFORE extraction, so an archive that fails is never
# unpacked and nothing from it can run. That property came from M36 and is
# preserved here exactly.
#
# A checksum mismatch is classified PERMANENT and fails on the first attempt.
# That is a deliberate refusal, not an optimisation: a mismatched archive is
# the signature of a corrupted or substituted artefact, and "retry until one
# happens to match" is precisely the wrong instinct to encode into a security
# tool. Only the transport is retried. What arrived is judged once.
#
# Usage:
#   bounded_download.sh --url URL --output FILE --sha256 HEX
#
# Exit: 0 downloaded and verified · 3 unavailable / verification failed.
# There is no exit 1: this script has no "found something bad but carry on"
# state, and reusing 1 would blur it with the audit wrappers' VULNERABLE.
# =============================================================================

set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source-path=SCRIPTDIR
# shellcheck source=lib/reliability_classify.sh
. "$HERE/lib/reliability_classify.sh"

ATTEMPTS="${DOWNLOAD_ATTEMPTS:-3}"
CONNECT_TIMEOUT="${DOWNLOAD_CONNECT_TIMEOUT:-10}"
MAX_TIME="${DOWNLOAD_MAX_TIME:-45}"
BACKOFF_SECONDS="${DOWNLOAD_BACKOFF:-2 4}"

EXIT_OK=0
EXIT_UNAVAILABLE=3

url="" output="" sha256=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --url)    url="${2:?--url needs a value}";    shift 2 ;;
    --output) output="${2:?--output needs a value}"; shift 2 ;;
    --sha256) sha256="${2:?--sha256 needs a value}"; shift 2 ;;
    *) echo "unknown argument: $1" >&2; exit "$EXIT_UNAVAILABLE" ;;
  esac
done

if [[ -z "$url" || -z "$output" || -z "$sha256" ]]; then
  echo "usage: $(basename "$0") --url URL --output FILE --sha256 HEX" >&2
  echo "  all three are required; a download without a checksum is not a download this script performs." >&2
  exit "$EXIT_UNAVAILABLE"
fi

# A malformed digest would make `sha256sum --check` fail for the wrong reason
# and read in the log like a substituted artefact. Reject it up front.
if [[ ! "$sha256" =~ ^[0-9a-fA-F]{64}$ ]]; then
  echo "DOWNLOAD: FAILED — --sha256 is not a 64-character hex digest." >&2
  exit "$EXIT_UNAVAILABLE"
fi

say() { printf '%s\n' "$*"; }

attempt=1
last_output=""
last_code=0

while :; do
  rm -f "$output"

  # --fail    turn an HTTP >=400 into a non-zero exit instead of saving the
  #           error page as if it were the archive
  # --show-error with --silent: quiet progress, but keep the diagnosis
  output_text="$(curl --fail --silent --show-error --location \
      --connect-timeout "$CONNECT_TIMEOUT" \
      --max-time "$MAX_TIME" \
      --retry 0 \
      --write-out 'HTTPSTATUS:%{http_code}' \
      --output "$output" \
      "$url" 2>&1)"
  code=$?

  last_output="$output_text"
  last_code=$code

  if [[ "$code" -eq 0 ]]; then
    # ---- transport succeeded; now judge what arrived --------------------
    if [[ ! -s "$output" ]]; then
      say "DOWNLOAD: UNAVAILABLE — the transfer reported success but the file is empty."
      # Leave nothing behind. An unverified artefact on disk is something a
      # later step could extract, and "it was zero bytes" is not a reason to
      # relax that.
      rm -f "$output"
      exit "$EXIT_UNAVAILABLE"
    fi

    if echo "${sha256}  ${output}" | sha256sum --check --strict --status; then
      say "DOWNLOAD: OK — ${url}"
      say "  sha256 verified: ${sha256}"
      exit "$EXIT_OK"
    fi

    actual="$(sha256sum "$output" | cut -d' ' -f1)"
    say ""
    say "DOWNLOAD: FAILED — CHECKSUM MISMATCH. This is not retried."
    say "  url:      ${url}"
    say "  expected: ${sha256}"
    say "  actual:   ${actual}"
    say ""
    say "  The transfer completed, so this is not a network problem. The bytes that"
    say "  arrived are not the bytes this repository pinned. Nothing has been"
    say "  extracted and nothing from this archive has been executed."
    rm -f "$output"
    exit "$EXIT_UNAVAILABLE"
  fi

  # ---- transport failed; classify -----------------------------------------
  class="$(rl_classify "$output_text" "$code" "")"

  # curl's own exit codes carry more than its message does. 6 (host
  # resolution), 7 (connect), 28 (operation timeout), 52 (empty reply),
  # 56 (receive error) and 35 (TLS handshake) are all transport weather.
  case "$code" in
    6|7|28|35|52|55|56) class="transient" ;;
    22)
      # --fail's HTTP >= 400. Retry only the statuses policy calls retryable;
      # a 404 means the pinned version does not exist, which is a permanent
      # repository defect and must fail immediately rather than three times.
      if grep -qE 'HTTPSTATUS:(408|425|429|500|502|503|504)' <<<"$output_text" \
         || grep -qiE '\b(408|425|429|500|502|503|504)\b' <<<"$output_text"; then
        class="transient"
      else
        class="permanent"
      fi
      ;;
  esac

  if [[ "$class" == "transient" ]] && [[ "$attempt" -lt "$ATTEMPTS" ]]; then
    wait_for="$(rl_backoff "$attempt" "$BACKOFF_SECONDS")"
    say "download attempt ${attempt}/${ATTEMPTS} failed (curl exit ${code}); retrying in ${wait_for}s."
    say "  ${output_text}"
    sleep "$wait_for"
    attempt=$((attempt + 1))
    continue
  fi

  if [[ "$class" != "transient" ]]; then
    say ""
    say "DOWNLOAD: FAILED — permanent error, not retried (curl exit ${code})."
    say "  ${output_text}"
    say "  url: ${url}"
    rm -f "$output"
    exit "$EXIT_UNAVAILABLE"
  fi
  break
done

say ""
say "DOWNLOAD: UNAVAILABLE — ${url}"
say "  Could not be fetched after ${ATTEMPTS} attempts. Last curl exit ${last_code}."
say "  ${last_output}"
say "  No archive was written, so nothing unverified can be extracted."
rm -f "$output"
exit "$EXIT_UNAVAILABLE"
