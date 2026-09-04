#!/usr/bin/env bash
# =============================================================================
# The control on `verify_ci_reliability.py`.
#
# That validator prints a long column of PASS lines, and a long column of PASS
# lines is true for two indistinguishable reasons: the policy holds, or the
# validator checks nothing. M36 found a validator wired into CI that enforced
# nothing. M37 found a bypass check that examined nothing and reported it
# clean. M47 found M45's own live controls silently skipping for two whole
# milestones while the suite exited 0. This repository has shipped a negative
# control with every gate since, and the reliability gate is no exception.
#
# Each control below breaks exactly one reliability property inside a
# disposable copy of the repository and asserts the validator fails on that
# property's own check — not merely that it fails, which any broken fixture
# would do, but that the failure is attributable to the thing that was broken.
#
# Nothing is ever modified in place. Every fixture lives under `mktemp -d`, and
# the real tree is sha256'd before and after — verified, not asserted, because
# a control that damaged what it was protecting would otherwise still print a
# tidy pass.
#
# Usage: .github/scripts/m49_ci_reliability_control.sh
# =============================================================================

# shellcheck disable=SC2016  # anchors are literal workflow text; ${{ }} and
# $VAR must NOT expand here — they are the bytes being matched.
set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT" || exit 1

VALIDATOR=".github/scripts/verify_ci_reliability.py"
POLICY=".github/governance/ci-reliability-policy.json"

passed=0
failed=0
declare -a failures=()
ok()  { printf '  %-68s ok\n' "${1:0:68}"; passed=$((passed + 1)); }
bad() { printf '  %-68s FAILED\n' "${1:0:68}"; failed=$((failed + 1)); failures+=("$1"); }

PROTECTED=(".github/workflows" ".github/scripts" "$POLICY")
fingerprint() {
  local p
  for p in "${PROTECTED[@]}"; do
    if [[ -d "$p" ]]; then
      find "$p" -type f -print0 | sort -z | xargs -0 sha256sum
    elif [[ -f "$p" ]]; then
      sha256sum "$p"
    fi
  done | sha256sum | cut -d' ' -f1
}

WORK="$(mktemp -d "${TMPDIR:-/tmp}/m49-policy-XXXXXXXX")"
trap 'rm -rf "$WORK"' EXIT

# A fixture is a miniature repository, not a bare copy: the validator resolves
# paths from a repo root and reads both the workflows and the scripts, so a
# fixture missing either would fail for a reason that has nothing to do with
# the property under test — which is exactly how a negative control turns into
# noise that everybody learns to ignore.
fixture() {
  local root="$WORK/$1"
  rm -rf "$root"
  mkdir -p "$root/.github"
  cp -a "$REPO_ROOT/.github/workflows"   "$root/.github/"
  cp -a "$REPO_ROOT/.github/scripts"     "$root/.github/"
  cp -a "$REPO_ROOT/.github/governance"  "$root/.github/"
  echo "$root"
}

# Replace an exact string in a fixture file. Refuses on 0 or >1 matches: an
# anchor that has drifted must break the control loudly rather than silently
# mutate nothing and let the test "pass" against an unmodified fixture.
mutate() {
  local file="$1" find="$2" replace="$3"
  # A failed anchor is FATAL, not a warning. A drifted anchor would leave
  # the fixture unmutated, the validator would correctly pass it, and the
  # control would blame the validator for the test's own defect. That is the
  # difference between a mutation test and a decoration.
  if ! python3 - "$file" "$find" "$replace" <<'PY'
import sys
path, needle, replacement = sys.argv[1], sys.argv[2], sys.argv[3]
text = open(path, encoding="utf-8").read()
n = text.count(needle)
if n != 1:
    sys.stderr.write(f"ANCHOR ERROR: {n} matches for {needle!r} in {path}\n")
    raise SystemExit(9)
open(path, "w", encoding="utf-8").write(text.replace(needle, replacement))
PY
  then
    echo "  ABORT: mutation anchor no longer matches in ${file}" >&2
    echo "  Every control below would be meaningless. Fix the anchor." >&2
    exit 2
  fi
}

# Run the validator against a fixture and require it to fail ON THE NAMED
# CHECK. `--json` gives structured failures, so "it failed" and "it failed for
# the reason we broke" stay distinguishable.
control() {
  local label="$1" expect_id="$2" root="$3"
  local out code
  out="$(python3 "$REPO_ROOT/$VALIDATOR" --repo-root "$root" --json "$root/result.json" 2>&1)"
  code=$?

  if [[ "$code" -eq 0 ]]; then
    bad "$label (validator PASSED a broken fixture)"
    printf '%s\n' "$out" | tail -3 | sed 's/^/      /'
    return
  fi
  if ! python3 - "$root/result.json" "$expect_id" <<'PY'
import json, sys
data = json.load(open(sys.argv[1]))
wanted = sys.argv[2]
ids = [f["id"] for f in data.get("failures", [])]
raise SystemExit(0 if any(i == wanted or i.startswith(wanted) for i in ids) else 1)
PY
  then
    local got
    got="$(python3 -c 'import json,sys;print(",".join(f["id"] for f in json.load(open(sys.argv[1])).get("failures",[]))[:120])' "$root/result.json" 2>/dev/null)"
    bad "$label (failed, but on ${got:-<none>} not ${expect_id})"
    return
  fi
  ok "$label"
}

before="$(fingerprint)"

echo "=============================================================================="
echo "Phase 1 — CI reliability policy negative controls"
echo "  subject: $VALIDATOR"
echo "=============================================================================="
echo
echo "Protected-file fingerprint (before): $before"
echo
echo "-- Each mutation must fail the check that owns it ----------------------------"

WF=".github/workflows"

# 1 — the 360-minute default, reintroduced.
r="$(fixture t1)"
mutate "$r/$WF/security.yml" $'  dependency-audit:\n    name: Dependency audit\n    runs-on: ubuntu-latest\n    timeout-minutes: 10\n' \
                             $'  dependency-audit:\n    name: Dependency audit\n    runs-on: ubuntu-latest\n'
control "1. timeout-minutes removed from a required check" "timeout.security.yml:dependency-audit" "$r"

# 2 — zero is not a bound.
r="$(fixture t2)"
mutate "$r/$WF/security.yml" "    timeout-minutes: 10
    steps:
      - uses: actions/checkout@v4

      - name: Setup Node" "    timeout-minutes: 0
    steps:
      - uses: actions/checkout@v4

      - name: Setup Node"
control "2. timeout-minutes set to zero" "timeout.security.yml:dependency-audit" "$r"

# 3 — a non-integer bound.
r="$(fixture t3)"
mutate "$r/$WF/contracts.yml" "    timeout-minutes: 10" "    timeout-minutes: \"soon\""
control "3. timeout-minutes set to a non-integer" "timeout.contracts.yml:validate" "$r"

# 4 — a bound raised past its class ceiling.
r="$(fixture t4)"
mutate "$r/$WF/contracts.yml" "    timeout-minutes: 10" "    timeout-minutes: 300"
control "4. a timeout raised above its policy class ceiling" "timeout.contracts.yml:validate" "$r"

# 5 — a bound quietly set below the job's own measured duration.
r="$(fixture t5)"
mutate "$r/$WF/ci-api.yml" "    timeout-minutes: 30" "    timeout-minutes: 2"
control "5. a timeout set below the job's measured duration" "timeout.ci-api.yml:quality" "$r"

# 6 — a new job added with no policy entry: the route back to 360 minutes.
r="$(fixture t6)"
mutate "$r/$WF/contracts.yml" $'jobs:\n  validate:' \
  $'jobs:\n  smuggled:\n    name: Smuggled\n    runs-on: ubuntu-latest\n    steps:\n      - run: echo hi\n\n  validate:'
control "6. a new job added without a policy entry" "timeout.contracts.yml:smuggled" "$r"

# 7 — an ungoverned npm audit reintroduced.
r="$(fixture t7)"
mutate "$r/$WF/security.yml" \
  '        run: ${{ github.workspace }}/.github/scripts/npm_audit_resilient.sh npm audit --audit-level=high' \
  '        run: npm audit --audit-level=high'
control "7. an unwrapped npm audit reintroduced" "audit.governed" "$r"

# 8 — an ungoverned composer audit reintroduced.
r="$(fixture t8)"
mutate "$r/$WF/security.yml" \
  '        run: ${{ github.workspace }}/.github/scripts/composer_audit_resilient.sh composer audit --locked' \
  '        run: composer audit --locked'
control "8. an unwrapped composer audit reintroduced" "audit.governed" "$r"

# 9 — the threshold lowered while the wrapper stays in place: the quiet one.
r="$(fixture t9)"
mutate "$r/$WF/security.yml" "npm_audit_resilient.sh npm audit --audit-level=high" \
                             "npm_audit_resilient.sh npm audit --audit-level=critical"
control "9. the npm threshold lowered behind the wrapper" "audit.threshold" "$r"

# 10 — an unbounded curl back in the required integrity job.
r="$(fixture t10)"
mutate "$r/$WF/workflow-integrity.yml" \
'          .github/scripts/bounded_download.sh \
            --url "https://github.com/rhysd/actionlint/releases/download/v${ACTIONLINT_VERSION}/${archive}" \
            --output "$archive" \
            --sha256 "${ACTIONLINT_SHA256}"' \
'          curl -fsSL -o "$archive" \
            "https://github.com/rhysd/actionlint/releases/download/v${ACTIONLINT_VERSION}/${archive}"'
control "10. an untimed, unretried curl restored" "download.no_bare_curl" "$r"

# 11 — checksum verification removed. The archive still downloads; nothing
#      then asserts the bytes are the pinned ones.
r="$(fixture t11)"
mutate "$r/$WF/workflow-integrity.yml" \
  '          echo "${SHELLCHECK_SHA256}  ${archive}" | sha256sum --check --strict' \
  '          echo "skipping verification"'
control "11. checksum verification removed from a download" "download.checksum_verified" "$r"

# 12 — UNAVAILABLE redefined as success. The single most dangerous edit
#      available anywhere in this system.
r="$(fixture t12)"
mutate "$r/.github/scripts/composer_audit_resilient.sh" "EXIT_UNAVAILABLE=3" "EXIT_UNAVAILABLE=0"
control "12. a wrapper's UNAVAILABLE redefined as exit 0" "wrapper.composer_audit_resilient.sh" "$r"

# 13 — a wrapper that can no longer say VULNERABLE at all.
r="$(fixture t13)"
mutate "$r/.github/scripts/composer_audit_resilient.sh" \
  'say "SECURITY AUDIT: VULNERABLE — composer audited the lockfile and found advisories."' \
  'say "audit finished"'
control "13. a wrapper stops emitting one of the three verdicts" "wrapper.composer_audit_resilient.sh" "$r"

# 14 — the failure classifier weakened: 503 dropped, so a live outage stops
#      being retried and starts being reported as something else.
r="$(fixture t14)"
mutate "$r/.github/scripts/lib/reliability_classify.sh" \
  "(408|425|429|500|502|503|504)" \
  "(408|425|429|500|502|504)"
control "14. an HTTP status removed from the failure classifier" "classifier.reliability_classify.sh" "$r"

# 15 — npm's retry tail unpinned at an install site.
r="$(fixture t15)"
mutate "$r/$WF/ci-web.yml" '      npm_config_fetch_retry_maxtimeout: "20000"
' ''
control "15. npm's retry ceiling unpinned at an install site" "npm_retry.ci-web.yml:quality" "$r"

# 16 — masking reintroduced on a governed path.
r="$(fixture t16)"
mutate "$r/.github/scripts/bounded_download.sh" \
  'say "DOWNLOAD: OK — ${url}"' \
  'say "DOWNLOAD: OK — ${url}" || true'
control "16. '|| true' added to a governed reliability script" "masking.governed_paths" "$r"

# 17 — run_control.sh made incapable of failing. A wrapper that swallows its
#      control's exit code would make every completion record meaningless.
r="$(fixture t17)"
mutate "$r/.github/scripts/run_control.sh" 'exit "$exit_code"' 'exit 0'
control "17. the manifest runner forced to exit 0" "manifest.runner_propagates" "$r"

# 18 — the budget arithmetic falsified. The policy claims a worst case its own
#      components do not sum to.
r="$(fixture t18)"
mutate "$r/$POLICY" '"computed_worst_case_seconds": 672' '"computed_worst_case_seconds": 120'
control "18. the declared worst case no longer matches its components" "budget.arithmetic" "$r"

# 19 — the integrity job's worst case grown past its own cap. This is the
#      2026-09-04 cancellation, expressed as a policy change instead of an
#      outage, and it is now caught before the job is ever cancelled.
r="$(fixture t19)"
mutate "$r/$POLICY" '"worst_case_each": 54' '"worst_case_each": 400'
mutate "$r/$POLICY" '"computed_worst_case_seconds": 672' '"computed_worst_case_seconds": 2056'
control "19. live audits grown until they no longer fit the job's timeout" "budget.fits" "$r"

# 20 — the policy and the workflow disagreeing about the same job's timeout.
r="$(fixture t20)"
mutate "$r/$POLICY" '"timeout_minutes": 20' '"timeout_minutes": 45'
control "20. policy and workflow disagree on the integrity job's timeout" "budget.declared_matches" "$r"

# 21 — a stale policy entry for a job that no longer exists.
r="$(fixture t21)"
mutate "$r/$POLICY" '    "ci-mobile.yml:quality": {' \
                    '    "ci-ghost.yml:phantom": {
      "class": "fast-validation",
      "measured_seconds": null
    },
    "ci-mobile.yml:quality": {'
control "21. the policy lists a job that does not exist" "timeout.policy_not_stale" "$r"

# ---------------------------------------------------------------------------
# M50 Phase 1 — the generalised rules. Each of these was ACCEPTED by every
# validator before this phase; the Phase 0 permanence probe confirmed it.
# ---------------------------------------------------------------------------

# 24 — masking on a GA certification command. Probe F in the Phase 0 audit.
r="$(fixture t24)"
mutate "$r/$WF/ga-release-certification.yml" \
  "        run: php scripts/redis_validation.php" \
  "        run: php scripts/redis_validation.php || true"
control "24. '|| true' added to a GA certification command" "masking.workflows" "$r"

# 25 — masking on the release path.
r="$(fixture t25)"
mutate "$r/$WF/release.yml" \
  "      - name: Analyzer — MANDATORY
        run: flutter analyze" \
  "      - name: Analyzer — MANDATORY
        run: flutter analyze || true"
control "25. masking added to release.yml" "masking.workflows" "$r"

# 26 — continue-on-error, the other spelling.
r="$(fixture t26)"
mutate "$r/$WF/contracts.yml" \
  "      - name: Lint OpenAPI specification" \
  "      - name: Lint OpenAPI specification
        continue-on-error: true"
control "26. continue-on-error added to a required check's step" "masking.workflows" "$r"

# 27 — an exemption that no longer describes the repository. A stale allowlist
#      entry is how a real mask later slips in under a name nobody re-reads.
r="$(fixture t27)"
mutate "$r/$POLICY" \
  '"step": "Upload coverage",' \
  '"step": "Upload coverage that no longer exists",'
control "27. a masking exemption that matches nothing" "masking.no_stale_exemption" "$r"

# 28 — an exemption with no written reason: a wildcard wearing a costume.
r="$(fixture t28)"
mutate "$r/$POLICY" \
  '"reason": "Artifact upload after the test gate. Runs so coverage is retrievable from a failed run; cannot change the job'"'"'s conclusion."' \
  '"reason": ""'
control "28. a masking exemption with an empty reason" "masking.exemption_has_reason" "$r"

# 29 — a bare download curl in a workflow that is not workflow-integrity.
#      Probe E in the Phase 0 audit: previously accepted.
r="$(fixture t29)"
mutate "$r/$WF/security.yml" \
  "      - name: Setup PHP" \
  "      - name: Fetch a thing
        run: curl -fsSL -o /tmp/x https://example.invalid/test
      - name: Setup PHP"
control "29. bare download curl added to security.yml" "download.no_bare_curl" "$r"

# 30 — the Composer bound removed.
r="$(fixture t30)"
mutate "$r/$WF/ci-api.yml" \
  '      COMPOSER_PROCESS_TIMEOUT: "300"
      # Explicit rather than inherited from phpunit.xml. An exported' \
  '      # Explicit rather than inherited from phpunit.xml. An exported'
control "30. COMPOSER_PROCESS_TIMEOUT removed from ci-api.yml" "network.composer_install" "$r"

# 31 — the Flutter step bound removed.
r="$(fixture t31)"
mutate "$r/$WF/ci-mobile.yml" \
  "        timeout-minutes: 10
        run: flutter pub get" \
  "        run: flutter pub get"
control "31. flutter pub get step timeout removed" "network.flutter_pub_get" "$r"

# 32 — the apt bounds removed.
r="$(fixture t32)"
mutate "$r/$WF/performance-certification.yml" \
  "          sudo apt-get -o Acquire::Retries=3 -o Acquire::http::Timeout=30 update \\
            && sudo apt-get -o Acquire::Retries=3 -o Acquire::http::Timeout=30 install -y k6
      - name: Run soak" \
  "          sudo apt-get update && sudo apt-get install -y k6
      - name: Run soak"
control "32. apt-get retry/timeout options removed" "network.apt_get_install" "$r"

# 33 — the ci-docker concurrency group removed.
r="$(fixture t33)"
mutate "$r/$WF/ci-docker.yml" \
  "concurrency:
  group: ci-docker-\${{ github.ref }}
  cancel-in-progress: true

" \
  ""
control "33. ci-docker.yml concurrency group removed" "concurrency.group" "$r"

# 34 — cancel-in-progress: false with no recorded reason.
r="$(fixture t34)"
mutate "$r/$WF/contracts.yml" \
  "  cancel-in-progress: true" \
  "  cancel-in-progress: false"
control "34. undeclared cancel-in-progress: false" "concurrency.cancel_declared" "$r"

# 35 — a deferred defect quietly deleted from the record while the mask stays.
#      The defect must keep being reported, not disappear because the entry did.
r="$(fixture t35)"
mutate "$r/$WF/ci-docker.yml" \
  "docker compose exec -T api php artisan db:seed --force || true" \
  "docker compose exec -T api php artisan db:seed --force"
control "35. a recorded deferred defect silently resolved without updating policy" \
  "masking.deferred_defect_present" "$r"

# 36 — the M50-13 masks restored elsewhere, where no record covers them.
r="$(fixture t36)"
mutate "$r/$WF/ci-web.yml" \
  "        run: npm run build" \
  "        run: npm run build || true"
control "36. db:seed-style masking reintroduced somewhere unrecorded" "masking.workflows" "$r"

# 37 — positive control. Without it, a validator that rejects everything would
#      make all twenty-one controls above pass while enforcing nothing.
r="$(fixture t22)"
if python3 "$REPO_ROOT/$VALIDATOR" --repo-root "$r" >/dev/null 2>&1; then
  ok "37. positive control: an unmutated fixture passes"
else
  bad "37. positive control: an UNMUTATED fixture failed — every control above proves nothing"
  python3 "$REPO_ROOT/$VALIDATOR" --repo-root "$r" 2>&1 | grep -E "^  FAIL" | head -12 | sed 's/^/      /'
fi

# 38 — integrity.
echo
after="$(fingerprint)"
if [[ "$before" == "$after" ]]; then
  ok "38. sha256 integrity: the real repository is unchanged"
else
  bad "38. THE REAL REPOSITORY CHANGED during this run"
fi
echo
echo "Protected-file fingerprint (after):  $after"

echo
echo "=============================================================================="
printf 'RESULT: %d passed, %d failed\n' "$passed" "$failed"
if [[ "$failed" -ne 0 ]]; then
  echo
  printf '  - %s\n' "${failures[@]}"
  echo
  echo "CI reliability negative controls FAILED."
  exit 1
fi
echo
echo "Every reliability property is enforced by a check that demonstrably fails"
echo "when that property is broken, and the working tree is untouched."
exit 0
