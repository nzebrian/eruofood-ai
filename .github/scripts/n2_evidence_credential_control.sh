#!/usr/bin/env bash
#
# N-2 — is the App credential that reads `bypass_actors` actually isolated?
#
# The advisory job could not see `bypass_actors` because `github.token` holds
# `contents: read` and GitHub returns that field only to `Administration: read`.
# N-2 fixes that by minting an installation token for App 4902397 — the same
# App that holds tag-creation bypass. So the fix hands this workflow the most
# privileged credential in the repository, in a workflow that runs on EVERY
# pull request, including from forks.
#
# That is the whole risk, and it is a real one. Actions scopes secrets to a JOB,
# not a step, so the credential is either in a job that also runs third-party
# code or it is not. The evidence job therefore has no `uses:` entries at all
# and checks nothing out; this suite exists to keep it that way, because the
# natural future edit — "just add actions/checkout, we need a script" — reads
# as harmless and is not.
#
# The second thing it guards is quieter. If the App grant is missing, GitHub
# answers 200 and omits `bypass_actors`, and the correct outcome is the
# validator's EXTERNAL. A workflow that "helpfully" supplied a default would
# convert an unanswered question into a PASS, which is the M37 Phase 4B defect
# rebuilt one layer upstream, where no validator control can see it.
#
# The `adopt_app_evidence` controls EXTRACT the live function out of the
# workflow and run it, rather than restating it here. A copy would keep passing
# after the workflow regressed — the failure mode M50-09 found again.
#
# Read-only. No token is minted, no key is read, no network call is made.
#
# Run: .github/scripts/n2_evidence_credential_control.sh

set -uo pipefail

# Single quotes throughout are load-bearing: this suite compares LITERAL
# workflow text, so `${{ secrets.X }}` and `${token}` must not expand. SC2016
# would otherwise fire on every assertion in the file.
# shellcheck disable=SC2016

REPO_ROOT="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
WORKFLOW="${REPO_ROOT}/.github/workflows/governance-advisory.yml"

passed=0
failed=0

ok()  { passed=$((passed + 1)); printf '  \xe2\x9c\x94  %s\n' "$1"; }
bad() { failed=$((failed + 1)); printf '  \xe2\x9c\x98  %s\n' "$1"; }

printf '%s\n' "========================================================================"
printf '%s\n' "N-2 — GOVERNANCE EVIDENCE CREDENTIAL CONTROLS"
printf '  workflow: %s\n' "${WORKFLOW}"
printf '%s\n' "========================================================================"

if [[ ! -f "${WORKFLOW}" ]]; then
    bad "the workflow does not exist — there is no evidence path to check"
    printf '\nRESULT: 0 passed, 1 FAILED.\n'
    exit 1
fi

# The two `run:` blocks, and the structure around them, as the runner sees them.
read_part() {   # $1 = python expression over `d`
    python3 - "${WORKFLOW}" "$1" <<'PY'
import sys, json, yaml
d = yaml.safe_load(open(sys.argv[1]))
v = eval(sys.argv[2], {"d": d, "json": json})  # noqa: S307 — fixed expressions below
sys.stdout.write(v if isinstance(v, str) else json.dumps(v))
PY
}

EVIDENCE_RUN="$(read_part 'd["jobs"]["evidence"]["steps"][0]["run"]')"
GOVERNANCE_RUN="$(read_part '"".join(s.get("run","") for s in d["jobs"]["governance"]["steps"])')"
EVIDENCE_JOB="$(read_part 'json.dumps(d["jobs"]["evidence"])')"
WORKFLOW_TEXT="$(cat "${WORKFLOW}")"

# Executable lines only. M44 wrote this lesson down and the N-1 control
# relearned it the hard way: a textual assertion that reads comments can be
# failed by an accurate comment and, far worse, SATISFIED by one quoting the
# fix. Every assertion below runs against the comment-stripped text.
strip_comments() { sed 's/[[:space:]]*#.*$//'; }

EVIDENCE_CODE="$(printf '%s\n' "${EVIDENCE_RUN}" | strip_comments)"
GOVERNANCE_CODE="$(printf '%s\n' "${GOVERNANCE_RUN}" | strip_comments)"

# Every `curl` invocation as ONE line, backslash continuations folded in. The
# first draft of this suite asked whether the strings `-X POST` and `rulesets`
# both appeared somewhere in the job, which they do — in two different calls —
# and reported a ruleset mutation that does not exist. A method belongs to a
# command, not to a file.
CURL_COMMANDS="$(printf '%s\n' "${EVIDENCE_CODE}" \
    | sed -e ':a' -e '/\\$/{N;s/\\\n//;ba}' \
    | grep -E 'curl[[:space:]]' \
    | tr -s ' \t' ' ')"

# --------------------------------------------------------- A. credential source
#
# Where the App identity comes from, and where it must never come from.

printf '\nA) The credential comes from the expected secrets and nowhere else\n'

if [[ "${WORKFLOW_TEXT}" == *'APP_ID: ${{ secrets.RELEASE_GOVERNOR_APP_ID }}'* ]]; then
    ok "the App ID is read from secrets.RELEASE_GOVERNOR_APP_ID"
else
    bad "the App ID is NOT read from secrets.RELEASE_GOVERNOR_APP_ID"
fi

if [[ "${WORKFLOW_TEXT}" == *'APP_PRIVATE_KEY: ${{ secrets.RELEASE_GOVERNOR_PRIVATE_KEY }}'* ]]; then
    ok "the private key is read from secrets.RELEASE_GOVERNOR_PRIVATE_KEY"
else
    bad "the private key is NOT read from secrets.RELEASE_GOVERNOR_PRIVATE_KEY"
fi

# A personal access token is the shortcut this milestone must not take: it would
# read bypass_actors on the first try, and bind release-path evidence to one
# human's account with no expiry anybody tracks.
declared_secrets="$(printf '%s\n' "${WORKFLOW_TEXT}" \
    | grep -oE 'secrets\.[A-Za-z0-9_]+' | sort -u)"
unexpected="$(printf '%s\n' "${declared_secrets}" \
    | grep -vE '^secrets\.(RELEASE_GOVERNOR_APP_ID|RELEASE_GOVERNOR_PRIVATE_KEY)$' | grep -v '^$')"

if [[ -z "${unexpected}" ]]; then
    ok "no other secret is referenced anywhere in the workflow (no PAT, no token secret)"
else
    bad "unexpected secret reference(s): $(printf '%s' "${unexpected}" | tr '\n' ' ')"
fi

for forbidden in GH_PAT PERSONAL_ACCESS_TOKEN ADMIN_TOKEN GITHUB_PAT; do
    if [[ "${WORKFLOW_TEXT}" != *"${forbidden}"* ]]; then
        ok "no reference to ${forbidden}"
    else
        bad "the workflow references ${forbidden} — a personal credential is forbidden here"
    fi
done

# --------------------------------------------------------------- B. the key --
#
# The key must reach openssl and nothing else.

printf '\nB) The private key is never rendered anywhere it could be read\n'

if [[ "${EVIDENCE_CODE}" != *'echo "${APP_PRIVATE_KEY}'* \
   && "${EVIDENCE_CODE}" != *'echo $APP_PRIVATE_KEY'* \
   && "${EVIDENCE_CODE}" != *'cat "${keyfile}"'* \
   && "${EVIDENCE_CODE}" != *'cat ${keyfile}'* ]]; then
    ok "the private key is never echoed and the keyfile is never cat'd"
else
    bad "the private key or its file reaches a printing command"
fi

if [[ "${EVIDENCE_CODE}" == *'umask 077'* ]]; then
    ok "the keyfile is created under umask 077"
else
    bad "no umask 077 before the keyfile is written"
fi

if [[ "${EVIDENCE_CODE}" == *"trap 'rm -f \"\${keyfile}\"' EXIT"* ]]; then
    ok "the keyfile is removed by an EXIT trap"
else
    bad "the keyfile is not removed by an EXIT trap"
fi

# `${{ secrets.X }}` inside a `run:` block is substituted into the SHELL SOURCE
# before bash parses it. Through `env:` it is data. This is the same correction
# M44 and M46 applied to staging-deploy.yml and release.yml.
if [[ "${EVIDENCE_RUN}" != *'${{ secrets.'* ]]; then
    ok "no secret is interpolated into the run script — both arrive through env:"
else
    bad "a secret is rendered directly into the run script"
fi

if [[ "${EVIDENCE_CODE}" == *'::add-mask::${token}'* ]]; then
    ok "the installation token is masked"
else
    bad "the installation token is NOT masked"
fi

if [[ "${EVIDENCE_CODE}" == *'::add-mask::${jwt}'* ]]; then
    ok "the signed JWT is masked"
else
    bad "the signed JWT is NOT masked"
fi

if [[ "${EVIDENCE_CODE}" != *'echo "${token}'* && "${EVIDENCE_CODE}" != *'echo "${jwt}'* ]]; then
    ok "neither the token nor the JWT is echoed"
else
    bad "the token or the JWT is echoed"
fi

# ------------------------------------------------------------ C. read-only ---
#
# One POST exists, and it is the documented mint. Everything touching
# repository data is a GET.

printf '\nC) Evidence acquisition is read-only\n'

non_get="$(printf '%s\n' "${CURL_COMMANDS}" | grep -v -- '-X GET' | grep -c 'curl ')"
if [[ "${non_get}" -eq 1 ]]; then
    ok "exactly one of the evidence job's curl calls is not a GET"
else
    bad "expected exactly 1 non-GET curl invocation, found ${non_get}"
fi

mutating="$(printf '%s\n' "${CURL_COMMANDS}" \
    | grep -E -- '-X (POST|PUT|PATCH|DELETE)' \
    | grep -vc 'access_tokens')"
if [[ "${mutating}" -eq 0 ]]; then
    ok "every non-GET call targets the installation token mint and nothing else"
else
    bad "${mutating} non-GET call(s) target something other than /access_tokens"
fi

for verb in PUT PATCH DELETE; do
    if ! printf '%s\n' "${CURL_COMMANDS}" | grep -q -- "-X ${verb}"; then
        ok "no curl call uses -X ${verb}"
    else
        bad "a curl call uses ${verb}"
    fi
done

get_calls="$(printf '%s\n' "${CURL_COMMANDS}" | grep -c -- '-X GET')"
if [[ "${get_calls}" -ge 1 ]]; then
    ok "the shared API helper pins the method to GET (${get_calls} GET call site(s))"
else
    bad "no curl call pins -X GET"
fi

# The two mutation families this repository cares about most. A tag write here
# would bypass release-tag.yml's validation entirely; a ruleset write would let
# the thing being audited edit its own auditor.
if [[ "${EVIDENCE_CODE}" != *'git/refs'* ]]; then
    ok "no tag or ref mutation endpoint is referenced"
else
    bad "the evidence job references git/refs — it must never create or move a ref"
fi

if ! printf '%s\n' "${CURL_COMMANDS}" | grep -E -- '-X (POST|PUT|PATCH|DELETE)' | grep -q 'rulesets'; then
    ok "no ruleset endpoint is reached by a non-GET call"
else
    bad "the evidence job issues a non-GET call against a rulesets endpoint"
fi

# ------------------------------------------------------- D. fork isolation ---

printf '\nD) The key is unreachable from untrusted pull request code\n'

# Structural, not textual. The first draft grepped the file and failed on the
# COMMENT in the evidence job explaining why pull_request_target is forbidden —
# the M44 trap, sprung by a control written to guard against it. The parsed
# trigger list cannot be satisfied or broken by prose.
triggers="$(read_part 'json.dumps(sorted((d.get(True) or d.get("on")).keys()))')"
if [[ "${triggers}" != *'pull_request_target'* ]]; then
    ok "the workflow's triggers do not include pull_request_target (${triggers})"
else
    bad "pull_request_target is a trigger — fork code would run with secrets in scope"
fi

uses_count="$(printf '%s\n' "${EVIDENCE_JOB}" | grep -o '"uses"' | wc -l | tr -d ' ')"
if [[ "${uses_count}" -eq 0 ]]; then
    ok "the evidence job runs no action at all (uses: count = 0)"
else
    bad "the evidence job references ${uses_count} action(s) — the key must not sit beside third-party code"
fi

if [[ "${EVIDENCE_JOB}" != *'actions/checkout'* ]]; then
    ok "the evidence job checks out no repository code"
else
    bad "the evidence job checks out the repository — PR code would run beside the key"
fi

if [[ "${EVIDENCE_JOB}" == *'"permissions": {}'* ]]; then
    ok "the evidence job declares permissions: {} — the built-in token is unusable there"
else
    bad "the evidence job does not declare an empty permissions block"
fi

# Word-anchored. `pip` as a bare substring matches `set -euo pipefail`, which is
# the correct line in every one of these run blocks — a control that fires on it
# is one a maintainer learns to ignore.
for banned in composer npm yarn pip python php make; do
    if ! printf '%s\n' "${EVIDENCE_CODE}" | grep -qE "(^|[[:space:];&|(])${banned}([[:space:]]|$)"; then
        ok "the evidence job does not invoke '${banned}'"
    else
        bad "the evidence job invokes '${banned}' — repository code must not run beside the key"
    fi
done

# ----------------------------------------------- E. the evidence job is soft --
#
# `governance` declares `needs: evidence`, and a failed dependency SKIPS the
# dependent job. A hard failure here would delete the advisory verdict instead
# of reporting one.

printf '\nE) An evidence failure degrades the run; it never deletes the verdict\n'

if [[ "$(read_part 'json.dumps(d["jobs"]["governance"].get("needs"))')" == '"evidence"' ]]; then
    ok "the governance job declares needs: evidence"
else
    bad "the governance job does not depend on the evidence job"
fi

if [[ "${EVIDENCE_CODE}" != *'exit 1'* ]]; then
    ok "no path in the evidence job exits non-zero"
else
    bad "the evidence job can exit non-zero, which would skip the governance job"
fi

for mode in '"fallback"' '"unavailable"' '"app"'; do
    if [[ "${EVIDENCE_CODE}" == *"emit ${mode}"* ]]; then
        ok "the evidence job can report mode=${mode//\"/}"
    else
        bad "the evidence job never reports mode=${mode//\"/}"
    fi
done

# The distinction the whole design turns on: a fork PR with no secret is
# routine and must stay green; a credential that was present and did not work
# is a defect and must go red. They are different modes for that reason.
if [[ "${GOVERNANCE_CODE}" == *'unavailable'*'ruleset_details_app'* \
   || "${GOVERNANCE_CODE}" == *'ruleset_details_app'*'unavailable'* ]]; then
    ok "mode=unavailable is recorded as an endpoint failure (ratchet -> INCOMPLETE)"
else
    bad "mode=unavailable is not recorded as an endpoint failure — a broken credential would be silent"
fi

# ------------------------------------------ F. missing bypass_actors stays out --

printf '\nF) A missing bypass_actors is never manufactured into evidence\n'

if [[ "${EVIDENCE_CODE}" != *'bypass_actors":'* && "${EVIDENCE_CODE}" != *'bypass_actors ='* \
   && "${EVIDENCE_CODE}" != *'bypass_actors: []'* ]]; then
    ok "the evidence job never writes a bypass_actors value into a payload"
else
    bad "the evidence job synthesises a bypass_actors value — a missing field would become a PASS"
fi

if [[ "${GOVERNANCE_CODE}" != *'bypass_actors'* ]]; then
    ok "the fallback path never writes a bypass_actors value either"
else
    bad "the fallback path touches bypass_actors — missing must stay missing"
fi

if [[ "${EVIDENCE_CODE}" == *'has("bypass_actors")'* ]]; then
    ok "the field is counted for reporting, which is the only thing done with it"
else
    bad "the evidence job does not report whether bypass_actors was present"
fi

# ------------------------------------- G. the shape gate, executed not quoted --
#
# `adopt_app_evidence` is lifted out of the live workflow and run against a
# table. A restatement here would keep passing after the workflow regressed.

printf '\nG) adopt_app_evidence rejects everything that is not well-formed evidence\n'

adopt_fn="$(printf '%s\n' "${GOVERNANCE_RUN}" \
    | awk '/^adopt_app_evidence\(\) \{/,/^\}/')"

if [[ -z "${adopt_fn}" ]]; then
    bad "could not extract adopt_app_evidence from the workflow"
else
    ok "adopt_app_evidence was extracted from the live workflow"

    run_adopt() {   # $1 rulesets b64, $2 details b64 -> exit status
        (
            set +u
            EVIDENCE="$(mktemp -d)"
            APP_RULESETS_B64="$1"
            APP_DETAILS_B64="$2"
            export EVIDENCE APP_RULESETS_B64 APP_DETAILS_B64
            # shellcheck disable=SC1090
            eval "${adopt_fn}"
            adopt_app_evidence
            status=$?
            rm -rf "${EVIDENCE}"
            exit "${status}"
        ) >/dev/null 2>&1
    }

    b64() { printf '%s' "$1" | base64 -w0; }

    good_list='[{"id":1},{"id":2}]'
    good_details='[{"id":1,"rules":[],"bypass_actors":[]},{"id":2,"rules":[{"type":"creation"}],"bypass_actors":[{"actor_id":4902397,"actor_type":"Integration","bypass_mode":"always"}]}]'

    adopt_case() {   # $1 description, $2 rulesets b64, $3 details b64, $4 expect(0|1)
        run_adopt "$2" "$3"
        local got=$?
        if [[ "${got}" -eq "$4" ]]; then
            ok "$1"
        else
            bad "$1 (expected exit $4, got ${got})"
        fi
    }

    adopt_case "a well-formed App payload is adopted" \
        "$(b64 "${good_list}")" "$(b64 "${good_details}")" 0
    adopt_case "empty outputs are refused" "" "" 1
    adopt_case "non-base64 input is refused" "not!base64!" "$(b64 "${good_details}")" 1
    adopt_case "a ruleset list that is an object, not an array, is refused" \
        "$(b64 '{"id":1}')" "$(b64 "${good_details}")" 1
    adopt_case "a details payload that is not an array is refused" \
        "$(b64 "${good_list}")" "$(b64 '{"id":1,"rules":[]}')" 1
    adopt_case "a detail entry without \`rules\` is refused (the N-4b shape gate)" \
        "$(b64 "${good_list}")" "$(b64 '[{"id":1,"rules":[]},{"id":2}]')" 1
    adopt_case "an API error envelope is refused" \
        "$(b64 '{"message":"Not Found"}')" "$(b64 '{"message":"Not Found"}')" 1
    adopt_case "an HTML error page is refused" \
        "$(b64 '<html>502</html>')" "$(b64 "${good_details}")" 1

    # The one that matters most: a payload with NO bypass_actors must still be
    # adopted, because the validator's EXTERNAL is the correct answer for it.
    # Refusing it here would turn an unanswered question into a red job, and
    # "fix" it by making the workflow demand a field it cannot guarantee.
    adopt_case "a payload with no bypass_actors is still adopted (validator reports EXTERNAL)" \
        "$(b64 "${good_list}")" "$(b64 '[{"id":1,"rules":[]},{"id":2,"rules":[]}]')" 0
fi

printf '\n%s\n' "========================================================================"
if [[ "${failed}" -eq 0 ]]; then
    printf 'RESULT: %d passed, 0 failed — the evidence credential stays isolated.\n' "${passed}"
    printf '%s\n' "========================================================================"
    exit 0
fi

printf 'RESULT: %d passed, %d FAILED.\n' "${passed}" "${failed}"
printf '%s\n' "========================================================================"
exit 1
