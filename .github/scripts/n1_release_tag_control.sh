#!/usr/bin/env bash
#
# N-1 — does the release-tag workflow actually refuse what it claims to refuse?
#
# `release-tag.yml` is the only thing that may create a production release tag
# once the creation ruleset is applied. It holds `permissions: {}` and acts as
# GitHub App 4902397; everything it decides is decided in one `run:` block, so
# nothing else in the repository can check it.
#
# The four defects this suite exists because of, all found in the PR #74 review:
#
#   M-1  `grep -Eq '^…$'` matches LINE BY LINE, so `v1.2.3\n<anything>` passed
#        both input validators. It could not forge a tag — GitHub rejects a ref
#        name containing a newline — but the residue reached an `echo`, and a
#        `::` line there is read as a workflow command.
#   M-2  The workflow trusted RELEASE_GOVERNOR_APP_ID and only PRINTED the
#        app_id it got back, so a secret naming a different App with
#        Contents: write would have cut the tag as the wrong identity.
#   L-1  Every 422 was announced as "already exists". GitHub also returns 422
#        for a ref name it will not accept, so the message named the wrong
#        cause and the obvious next move — cut a different tag — fixes nothing.
#   L-2  `--retry` covers transient problems irrespective of method, so a
#        creation POST whose 201 was lost could be REPLAYED, the second attempt
#        reporting 422 for a ref the first had already created.
#
# The input controls EXTRACT the live predicate out of the workflow and run it,
# rather than restating it here. A copy would keep passing after the workflow
# regressed, which is the failure mode M44 wrote a guard for and M50-09 found
# again: a control that can only see what is still there.
#
# Read-only. Nothing is dispatched, no token is minted, no tag is created.
#
# Run: .github/scripts/n1_release_tag_control.sh

set -uo pipefail

REPO_ROOT="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
WORKFLOW="${REPO_ROOT}/.github/workflows/release-tag.yml"

passed=0
failed=0

ok()  { passed=$((passed + 1)); printf '  \xe2\x9c\x94  %s\n' "$1"; }
bad() { failed=$((failed + 1)); printf '  \xe2\x9c\x98  %s\n' "$1"; }

printf '%s\n' "========================================================================"
printf '%s\n' "N-1 — RELEASE TAG WORKFLOW CONTROLS"
printf '  workflow: %s\n' "${WORKFLOW}"
printf '%s\n' "========================================================================"

if [[ ! -f "${WORKFLOW}" ]]; then
    bad "the workflow does not exist — the release path has no mechanism at all"
    printf '\nRESULT: 0 passed, 1 FAILED.\n'
    exit 1
fi

# The `run:` block, as the runner would receive it.
RUN_SCRIPT="$(python3 - "${WORKFLOW}" <<'PY'
import sys, yaml
d = yaml.safe_load(open(sys.argv[1]))
print(d["jobs"]["create"]["steps"][0]["run"], end="")
PY
)"

# Executable lines only. M44 wrote this lesson down and this control promptly
# relearned it: the first run of the M-1 check went red because the comment
# EXPLAINING the grep defect contains the string `grep -Eq`. A textual control
# that reads comments can be failed by an accurate one — and, far worse, can be
# SATISFIED by a comment quoting the fix.
CODE_ONLY="$(printf '%s\n' "${RUN_SCRIPT}" | sed 's/[[:space:]]*#.*$//')"

# ---------------------------------------------------------------- A. inputs --
#
# The two `[[ =~ ]]` tests are lifted out of the live script and evaluated
# against a table. If somebody reverts them to `grep`, the multi-line rows below
# start passing validation and these controls go red.

printf '\nA) Input validation binds the WHOLE value, not one line of it\n'

extract_pattern() {   # $1 = variable name -> its =~ pattern
    printf '%s\n' "${CODE_ONLY}" \
        | sed -n "s/.*\\[\\[ ! \"\\\${$1}\" =~ \\(.*\\) \\]\\].*/\\1/p" \
        | head -n1
}

TAG_PATTERN="$(extract_pattern RELEASE_TAG)"
SHA_PATTERN="$(extract_pattern TARGET_SHA)"

if [[ -n "${TAG_PATTERN}" && -n "${SHA_PATTERN}" ]]; then
    ok "both validators are bash [[ =~ ]] tests, and their patterns were extracted"
else
    bad "could not extract a [[ =~ ]] pattern for RELEASE_TAG and TARGET_SHA — has one reverted to grep?"
fi

# Deliberately not `grep`. This suite exists because grep was the defect.
if printf '%s\n' "${CODE_ONLY}" | grep -q 'grep -Eq'; then
    bad "M-1 REGRESSION: a grep-based validator is back in the workflow"
else
    ok "no grep-based input validation remains (M-1)"
fi

NL="$(printf '\nevil')"
CMD="$(printf '\n::add-mask::x')"

check_input() {   # $1 label, $2 pattern, $3 value, $4 expect accept|reject
    local label="$1" pattern="$2" value="$3" expect="$4" actual
    if [[ "${value}" =~ ${pattern} ]]; then actual="accept"; else actual="reject"; fi
    if [[ "${actual}" == "${expect}" ]]; then
        ok "${label} → ${expect}"
    else
        bad "${label} → expected ${expect}, got ${actual}"
    fi
}

if [[ -n "${TAG_PATTERN}" ]]; then
    check_input "tag  v1.2.3"                    "${TAG_PATTERN}" "v1.2.3"              accept
    check_input "tag  v0.0.1-rc3"                "${TAG_PATTERN}" "v0.0.1-rc3"          accept
    check_input "tag  1.2.3 (no v)"              "${TAG_PATTERN}" "1.2.3"               reject
    check_input "tag  v1.2 (two components)"     "${TAG_PATTERN}" "v1.2"                reject
    check_input "tag  v1.2.3 + newline + text"   "${TAG_PATTERN}" "v1.2.3${NL}"         reject
    check_input "tag  v1.2.3 + workflow command" "${TAG_PATTERN}" "v1.2.3${CMD}"        reject
    check_input "tag  shell metacharacters"      "${TAG_PATTERN}" 'v1.0.0`id`'          reject
    check_input "tag  leading newline"           "${TAG_PATTERN}" "${NL}"               reject
fi

if [[ -n "${SHA_PATTERN}" ]]; then
    FULL="0123456789abcdef0123456789abcdef01234567"
    check_input "sha  40 hex"                    "${SHA_PATTERN}" "${FULL}"             accept
    check_input "sha  abbreviated"               "${SHA_PATTERN}" "0000000"             reject
    check_input "sha  uppercase"                 "${SHA_PATTERN}" "${FULL^^}"           reject
    check_input "sha  40 hex + newline + text"   "${SHA_PATTERN}" "${FULL}${NL}"        reject
    check_input "sha  40 hex + workflow command" "${SHA_PATTERN}" "${FULL}${CMD}"       reject
fi

# ------------------------------------------------------- B. acting identity --

printf '\nB) The workflow acts as the RECORDED release actor, or not at all\n'

RECORDED_ACTOR="$(python3 - "${REPO_ROOT}" <<'PY'
import json, sys
d = json.load(open(sys.argv[1] + "/.github/governance/identities.json"))
actors = d.get("release_actors") or []
print(actors[0]["actor_id"] if len(actors) == 1 else "")
PY
)"

if [[ -n "${RECORDED_ACTOR}" ]]; then
    ok "identities.json records exactly one release actor (${RECORDED_ACTOR})"
else
    bad "identities.json does not record exactly one release actor"
fi

if printf '%s\n' "${CODE_ONLY}" | grep -q 'authenticated_app_id="\$(printf .* jq -r .\.app_id.)"'; then
    ok "the workflow reads the app_id GitHub reports back (M-2)"
else
    bad "M-2 REGRESSION: the workflow no longer reads the authenticated app_id"
fi

if [[ -n "${RECORDED_ACTOR}" ]] \
   && printf '%s\n' "${CODE_ONLY}" | grep -q "\\[ \"\\\${authenticated_app_id}\" != \"${RECORDED_ACTOR}\" \\]"; then
    ok "and refuses to continue unless it equals ${RECORDED_ACTOR} (M-2)"
else
    bad "M-2 REGRESSION: the workflow does not compare the authenticated app_id against the recorded actor"
fi

# The installation id is a DIFFERENT number. It may mint a token; it may never
# stand in for the App ID.
if printf '%s\n' "${CODE_ONLY}" | grep -q 'app/installations/\${installation_id}/access_tokens'; then
    ok "the installation id is used only to mint a token"
else
    bad "the installation id is no longer used to mint the token — check what replaced it"
fi

if printf '%s\n' "${CODE_ONLY}" | grep -q '\${installation_id}" != "4902397"'; then
    bad "the installation id is being compared against the App ID — those are different numbers"
else
    ok "the installation id is never substituted for the App ID"
fi

# -------------------------------------------------------- C. tag immutability --

printf '\nC) The workflow cannot move or replace an existing tag\n'

if printf '%s\n' "${CODE_ONLY}" | grep -Eq 'api (retry|noretry) (PATCH|DELETE) '; then
    bad "the workflow issues a PATCH or DELETE — it can move or remove a release tag"
else
    ok "no PATCH and no DELETE: a tag can be created, never moved or removed"
fi

if printf '%s\n' "${CODE_ONLY}" | grep -q 'api noretry POST "repos/\${REPO}/git/refs"'; then
    ok "tag creation is a POST, and is explicitly NOT retried (L-2)"
else
    bad "L-2 REGRESSION: the tag-creation POST is not on the no-retry path — a lost 201 could be replayed"
fi

if printf '%s\n' "${CODE_ONLY}" | grep -q 'message}" = "Reference already exists"'; then
    ok "422 is only reported as 'already exists' when GitHub says so (L-1)"
else
    bad "L-1 REGRESSION: 422 is being interpreted without reading GitHub's message"
fi

if printf '%s\n' "${CODE_ONLY}" | grep -q 'compare/main\.\.\.\${TARGET_SHA}'; then
    ok "the target commit is checked for containment in main"
else
    bad "nothing checks that the commit being tagged is contained in main"
fi

if printf '%s\n' "${CODE_ONLY}" | grep -Eq '"\$\{status\}" != "identical" \] && \[ "\$\{status\}" != "behind"'; then
    ok "and only 'identical' or 'behind' is accepted"
else
    bad "the containment check no longer restricts the compare status"
fi

# --------------------------------------------------------- D. least privilege --

printf '\nD) Privilege and secret handling\n'

PERMS="$(python3 - "${WORKFLOW}" <<'PY'
import sys, yaml, json
d = yaml.safe_load(open(sys.argv[1]))
print(json.dumps(d.get("permissions")))
PY
)"

if [[ "${PERMS}" == "{}" ]]; then
    ok "permissions: {} — GITHUB_TOKEN holds no scope at all"
else
    bad "the workflow declares permissions ${PERMS}, not {}"
fi

if printf '%s\n' "${CODE_ONLY}" | grep -q 'GITHUB_TOKEN'; then
    bad "GITHUB_TOKEN appears in the run block — it authenticates as the wrong Integration"
else
    ok "GITHUB_TOKEN is never used for tag creation"
fi

if printf '%s\n' "${CODE_ONLY}" | grep -q '::add-mask::\${token}'; then
    ok "the installation token is masked as soon as it is parsed"
else
    bad "the installation token is not masked"
fi

if printf '%s\n' "${CODE_ONLY}" | grep -Eq 'echo .*\$\{APP_PRIVATE_KEY\}|cat .*keyfile'; then
    bad "the private key is echoed or catted"
else
    ok "the private key is never printed"
fi

if printf '%s\n' "${CODE_ONLY}" | grep -q "trap 'rm -f \"\${keyfile}\"' EXIT"; then
    ok "the key file is removed on exit, by trap"
else
    bad "no EXIT trap removes the key file"
fi

if printf '%s\n' "${CODE_ONLY}" | grep -q 'set -euo pipefail'; then
    ok "the script is fail-closed (set -euo pipefail)"
else
    bad "the script does not set -euo pipefail"
fi

for construct in '|| true' '|| :' 'continue-on-error' 'set +e'; do
    if printf '%s\n' "${CODE_ONLY}" | grep -qF "${construct}"; then
        bad "masking construct present: ${construct}"
    else
        ok "no masking construct: ${construct}"
    fi
done

# ------------------------------------------------------------------ result --

printf '\n%s\n' "========================================================================"
if [[ ${failed} -eq 0 ]]; then
    printf 'RESULT: %d passed, 0 failed — the release-tag workflow refuses what it claims to.\n' "${passed}"
else
    printf 'RESULT: %d passed, %d FAILED.\n' "${passed}" "${failed}"
fi
printf '%s\n' "========================================================================"

exit $(( failed == 0 ? 0 : 1 ))
