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

# M50 Phase 2: the governed surface now extends past `.github/**`, so the
# integrity fingerprint does too. A mutation that escaped into the real
# Dockerfiles or compose files would otherwise leave no trace here.
PROTECTED=(".github/workflows" ".github/scripts" "$POLICY"
           "infra/docker/node/Dockerfile" "infra/docker/php/Dockerfile"
           "docker-compose.yml" "docker-compose.ci.yml" "docker-compose.override.yml"
           "packages/api-contracts/package-lock.json")
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

  # M50 Phase 2 widened the validator past `.github/**`: it now reads the
  # production Dockerfiles, the compose files and the governed lockfiles. A
  # fixture missing those would fail sections N, O and P for a reason that has
  # nothing to do with the property under test — and, worse, every control above
  # would then "pass" on a fixture that was already failing, which is how a
  # mutation suite quietly stops testing anything.
  mkdir -p "$root/infra/docker" "$root/packages/api-contracts" "$root/apps/mobile"
  cp -a "$REPO_ROOT/infra/docker/node" "$root/infra/docker/"
  cp -a "$REPO_ROOT/infra/docker/php"  "$root/infra/docker/"
  local f
  for f in docker-compose.yml docker-compose.ci.yml docker-compose.override.yml docker-compose.staging.yml; do
    [[ -f "$REPO_ROOT/$f" ]] && cp -a "$REPO_ROOT/$f" "$root/$f"
  done
  cp -a "$REPO_ROOT/packages/api-contracts/package-lock.json" "$root/packages/api-contracts/"
  cp -a "$REPO_ROOT/packages/api-contracts/package.json"      "$root/packages/api-contracts/"
  cp -a "$REPO_ROOT/apps/mobile/pubspec.lock"                 "$root/apps/mobile/"
  mkdir -p "$root/apps/web"
  cp -a "$REPO_ROOT/apps/web/package-lock.json" "$root/apps/web/"
  mkdir -p "$root/apps/api"
  cp -a "$REPO_ROOT/apps/api/composer.lock" "$root/apps/api/"
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
# Anchored on the job's own `timeout-minutes:` plus the `env:` that follows it,
# which is unique to `dependency-audit` (secret-scan has `steps:` there). It
# used to reach through the checkout line below; M50-01 pinned that line and the
# anchor guard caught it immediately. Narrowed, not weakened — it still mutates
# exactly the one timeout under test.
mutate "$r/$WF/security.yml" "    timeout-minutes: 10
    env:" "    timeout-minutes: 0
    env:"
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
# Anchored on the apps/web site specifically. M50-09 added a second
# `npm_audit_resilient.sh` invocation for packages/api-contracts, which made the
# bare command line ambiguous — the fatal-anchor guard caught it on the first
# run. The two `run:` lines are byte-identical, so the disambiguator is the step
# that FOLLOWS: only the web site is followed by the api-contracts step. (The
# api-contracts site is followed by `Setup PHP`, which is how 9 finds it.)
#
# It replaces the run line rather than adding a second `run:` key to the step:
# duplicate keys in a YAML mapping resolve to the last one, so an injected key
# would have been parsed away and the fixture would not have been broken at all.
mutate "$r/$WF/security.yml" \
  '        run: ${{ github.workspace }}/.github/scripts/npm_audit_resilient.sh npm audit --audit-level=high

      - name: npm audit (api-contracts)' \
  '        run: npm audit --audit-level=high

      - name: npm audit (api-contracts)'
control "7. an unwrapped npm audit reintroduced" "audit.governed" "$r"

# 8 — an ungoverned composer audit reintroduced.
r="$(fixture t8)"
mutate "$r/$WF/security.yml" \
  '        run: ${{ github.workspace }}/.github/scripts/composer_audit_resilient.sh composer audit --locked' \
  '        run: composer audit --locked'
control "8. an unwrapped composer audit reintroduced" "audit.governed" "$r"

# 9 — the threshold lowered while the wrapper stays in place: the quiet one.
r="$(fixture t9)"
# Narrowed for the same reason as 7: two sites now share the wrapper line, so
# the threshold is mutated at the api-contracts site, identified by the step
# that follows it.
mutate "$r/$WF/security.yml" \
  "npm_audit_resilient.sh npm audit --audit-level=high

      - name: Setup PHP" \
  "npm_audit_resilient.sh npm audit --audit-level=critical

      - name: Setup PHP"
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

# 35 — a deferred defect recorded for a mask that is not in the repository.
#
#      This used to mutate the other way: strip M50-13's `|| true` from
#      ci-docker.yml and prove the orphaned record failed. M50-13 is fixed, the
#      masks are gone and `deferred_defects` is empty, so that anchor no longer
#      exists — but the property still has to hold for the next deferral, so the
#      control now approaches it from the empty side. A record that describes
#      nothing is a policy that has stopped describing the repository, which is
#      how a real mask later slips past a name nobody re-reads.
r="$(fixture t35)"
mutate "$r/$POLICY" \
  '    "deferred_defects": [],' \
  '    "deferred_defects": [{"workflow": "ci-docker.yml", "step": "Migrate from an empty database + seed bootstrap", "construct": "|| true", "finding": "M50-13", "blocked_by": "a mask that is no longer there"}],'
control "35. a deferred defect recorded for a mask the repository no longer has" \
  "masking.deferred_defect_present" "$r"

# 36 — the M50-13 masks restored elsewhere, where no record covers them.
r="$(fixture t36)"
mutate "$r/$WF/ci-web.yml" \
  "        run: npm run build" \
  "        run: npm run build || true"
control "36. db:seed-style masking reintroduced somewhere unrecorded" "masking.workflows" "$r"

# 37 — the M50-13 mask put back on the very line it was removed from.
#
#      The seed now works, so there is no longer any excuse for masking it. This
#      is the control that makes the fix stick: re-adding `|| true` to the
#      certification seed must be rejected outright, not quietly tolerated
#      because that is how the line used to look for two milestones.
r="$(fixture t37)"
mutate "$r/$WF/ci-docker.yml" \
  "          docker compose exec -T api php artisan db:seed --force" \
  "          docker compose exec -T api php artisan db:seed --force || true"
control "37. the db:seed mask restored on the certification seed itself" "masking.workflows" "$r"

# 38 — the same, in the GA certification gate, with its original `|| echo`.
r="$(fixture t38)"
mutate "$r/$WF/ga-docker-certification.yml" \
  "          docker compose exec -T api php artisan db:seed --force" \
  "          docker compose exec -T api php artisan db:seed --force || echo \"seed optional\""
control "38. the db:seed mask restored in the GA certification gate" "masking.workflows" "$r"

# ---- M50 Phase 2 -----------------------------------------------------------
#
# 39-48. Six findings, ten ways to break them. Each mutation reverses exactly
# one thing Phase 2 fixed and requires the check that owns it to fail.

# 39 — an action put back on a mutable tag. The whole point of M50-01: a tag is
#      a pointer its owner can move, and `@v4` is a promise to run whatever that
#      repository decides v4 means tomorrow.
r="$(fixture t39)"
mutate "$r/$WF/security.yml" \
  "        uses: gitleaks/gitleaks-action@ff98106e4c7b2bc287b24eaf42907196329070c7 # v2.3.9" \
  "        uses: gitleaks/gitleaks-action@v2"
control "39. an external action reverted to a mutable tag" "pinning.no_mutable_action" "$r"

# 40 — a partial bump: one site moved to a different commit while the other
#      twenty-six stayed. This is the property `sha_matches_policy` actually
#      bought, and M50-F1 kept it — the mutation is unchanged, only the check
#      that owns it is renamed, because the SHA is now derived from the
#      workflows rather than duplicated into the policy Dependabot cannot edit.
r="$(fixture t40)"
mutate "$r/$WF/workflow-integrity.yml" \
  "      - uses: actions/checkout@11d5960a326750d5838078e36cf38b85af677262 # v4.4.0" \
  "      - uses: actions/checkout@0000000000000000000000000000000000000000 # v4.4.0"
control "40. one site left behind on a different SHA" "pinning.sha_consistent" "$r"

# 41 — a new action introduced pinned but undeclared. Pinned is necessary and
#      not sufficient: the policy is the list a human is expected to have read.
r="$(fixture t41)"
mutate "$r/$WF/ci-docker.yml" \
  "      - uses: actions/checkout@11d5960a326750d5838078e36cf38b85af677262 # v4.4.0" \
  "      - uses: some/unreviewed-action@1111111111111111111111111111111111111111 # v1.0.0"
control "41. an action pinned but absent from policy" "pinning.policy_covers_all" "$r"

# 42 — the version comment silently disagreeing with its siblings, which is how
#      a bump reads as routine in a diff while pointing somewhere else.
r="$(fixture t42)"
mutate "$r/$WF/ci-web.yml" \
  "      - uses: actions/checkout@11d5960a326750d5838078e36cf38b85af677262 # v4.4.0" \
  "      - uses: actions/checkout@11d5960a326750d5838078e36cf38b85af677262 # v9.9.9"
control "42. a version comment that disagrees with its siblings" "pinning.version_comment" "$r"

# 43 — `:latest` restored in the compose file both certification gates load.
r="$(fixture t43)"
mutate "$r/docker-compose.yml" \
  "    image: minio/minio:RELEASE.2025-09-07T16-13-09Z" \
  "    image: minio/minio:latest"
control "43. :latest restored in a governed compose file" "image_tag.no_mutable" "$r"

# 44 — the M50-02 fallback put back. This is the control that makes that fix
#      stick: the production web image must not be able to resolve a dependency
#      graph the required audit never saw.
r="$(fixture t44)"
mutate "$r/infra/docker/node/Dockerfile" \
  "    npm ci" \
  "    npm ci || npm install"
control "44. the unlocked npm fallback restored in the production image" \
  "build_file.no_unlocked_fallback" "$r"

# 45 — a build-file exemption that stops describing the file. Same staleness
#      rule the workflow masking exemptions are held to.
r="$(fixture t45)"
mutate "$r/infra/docker/php/Dockerfile" \
  "RUN addgroup -g \${GID} app 2>/dev/null || true \\" \
  "RUN addgroup -g \${GID} app \\"
control "45. a build-file exemption matching nothing" "build_file.no_stale_exemption" "$r"

# 46 — the api-contracts lockfile deleted, putting the package back to floating
#      resolution behind a required drift check.
r="$(fixture t46)"
rm -f "$r/packages/api-contracts/package-lock.json"
control "46. a governed lockfile deleted" "lockfile.present" "$r"

# 47 — the contracts install reverted to `npm install`.
r="$(fixture t47)"
mutate "$r/$WF/contracts.yml" \
  "        run: npm ci --no-fund" \
  "        run: npm install --no-audit --no-fund"
control "47. a governed package reverted to a range-resolving install" \
  "lockfile.deterministic_install" "$r"

# 48 — the Dart scan deleted. Section C can only catch an UNGOVERNED
#      invocation, and nothing here names osv-scanner directly, so without this
#      the step could be removed and every other check would still pass.
r="$(fixture t48)"
mutate "$r/$WF/security.yml" \
  "        run: \${{ github.workspace }}/.github/scripts/osv_scan_resilient.sh --lockfile apps/mobile/pubspec.lock" \
  "        run: echo skipped"
control "48. the Dart advisory scan removed entirely" "audit.dart_covered" "$r"

# ---- M50-09 ----------------------------------------------------------------
#
# 49-53. The Redocly 2.x migration, and the five ways to undo it.

# 49 — the api-contracts audit step deleted outright. `audit.governed` cannot
#      catch this: it only finds UNGOVERNED invocations, and a deleted step has
#      nothing to find. That is why the sites are listed positively.
r="$(fixture t49)"
mutate "$r/$WF/security.yml" \
  "      - name: npm audit (api-contracts)
        working-directory: packages/api-contracts" \
  "      - name: npm audit (api-contracts) — REMOVED
        working-directory: nowhere"
control "49. the api-contracts audit site deleted" "audit.site_present" "$r"

# 50 — the audit kept but unwrapped, losing the three-verdict contract: a
#      registry outage would then read as a vulnerability, or worse, as clean.
r="$(fixture t50)"
mutate "$r/$WF/security.yml" \
  "        run: \${{ github.workspace }}/.github/scripts/npm_audit_resilient.sh npm audit --audit-level=high

      - name: Setup PHP" \
  "        run: npm audit --audit-level=high

      - name: Setup PHP"
control "50. the api-contracts audit stripped of its wrapper" "audit.site_wrapped" "$r"

# 51 — one npx pin left behind on the vulnerable major while the lockfile moves
#      on. This is the exact shape of the next Dependabot Redocly bump, and the
#      reason the check exists: Dependabot cannot see an npx invocation.
r="$(fixture t51)"
mutate "$r/$WF/release.yml" \
  "      - run: npx --yes @redocly/cli@2.51.2 lint openapi.yaml" \
  "      - run: npx --yes @redocly/cli@1 lint openapi.yaml"
control "51. an npx Redocly pin left on the vulnerable major" \
  "pinning.redocly_npx_matches_lock" "$r"

# 52 — the package itself reverted to Redocly 1.x, which is what reintroduces
#      the six HIGH advisories. The pins then disagree with the lockfile, so the
#      revert cannot be quiet.
r="$(fixture t52)"
mutate "$r/packages/api-contracts/package-lock.json" \
  '"node_modules/@redocly/cli": {
      "version": "2.51.2",' \
  '"node_modules/@redocly/cli": {
      "version": "1.34.19",'
control "52. the package reverted to a vulnerable Redocly 1.x" \
  "pinning.redocly_npx_matches_lock" "$r"

# 53 — the OTHER listed site deleted. `required_npm_audit_sites` names two, and
#      49 only exercises one arm of it; a check that happened to hard-code
#      packages/api-contracts would still pass 49. It also matters more than it
#      looks: since M50-09 there are two npm audit steps, so removing apps/web
#      leaves `audit.npm_step_present` in verify_dependency_audit_gate.py
#      satisfied by the survivor. This is the check that owns the web site now.
r="$(fixture t53)"
mutate "$r/$WF/security.yml" \
  "      - name: npm audit (web)
        working-directory: apps/web" \
  "      - name: npm audit (web) — REMOVED
        working-directory: nowhere"
control "53. the apps/web audit site deleted" "audit.site_present" "$r"

# 54-60. M50-F1. The SHA moved from the policy into the workflows, so the checks
#        that used to compare two copies now derive one. Deriving is only safe if
#        the derivation cannot be starved: every control below breaks a way the
#        old comparison would have caught something, and 60 breaks the scan itself.

# 54 — an abbreviated SHA. Git resolves it, humans read it as a pin, and it is
#      not one: a short prefix can later become ambiguous, and it is not what
#      Actions resolves. 40 characters or nothing.
r="$(fixture t54)"
mutate "$r/$WF/ci-mobile.yml" \
  "      - uses: actions/checkout@11d5960a326750d5838078e36cf38b85af677262 # v4.4.0" \
  "      - uses: actions/checkout@11d5960 # v4.4.0"
control "54. an abbreviated SHA accepted as a pin" "pinning.no_mutable_action" "$r"

# 55 — forty characters that are not hex. Length alone is not the property; a
#      check that only counted would take this.
r="$(fixture t55)"
mutate "$r/$WF/ci-mobile.yml" \
  "      - uses: actions/checkout@11d5960a326750d5838078e36cf38b85af677262 # v4.4.0" \
  "      - uses: actions/checkout@ZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZ # v4.4.0"
control "55. a 40-character non-hex ref" "pinning.no_mutable_action" "$r"

# 56 — the version comment removed. The SHA is still immutable, so nothing is
#      insecure; what is lost is the only thing that makes a bump readable in a
#      diff, and with it the sibling agreement that replaces the policy copy.
r="$(fixture t56)"
mutate "$r/$WF/contracts.yml" \
  "      - uses: actions/checkout@11d5960a326750d5838078e36cf38b85af677262 # v4.4.0" \
  "      - uses: actions/checkout@11d5960a326750d5838078e36cf38b85af677262"
control "56. a pin with no version comment at all" "pinning.version_comment" "$r"

# 57 — a comment that is prose rather than a version. Presence is not the
#      property either.
r="$(fixture t57)"
mutate "$r/$WF/contracts.yml" \
  "      - uses: actions/checkout@11d5960a326750d5838078e36cf38b85af677262 # v4.4.0" \
  "      - uses: actions/checkout@11d5960a326750d5838078e36cf38b85af677262 # see PR 123"
control "57. a version comment that is not a version" "pinning.version_comment" "$r"

# 58 — governance coverage removed. Deleting your own entry must not be a way to
#      stop being checked; the action is still in use, so the allowlist is now
#      the thing that is wrong.
r="$(fixture t58)"
python3 - "$r/$POLICY" <<'PY'
import json, sys
p = sys.argv[1]
d = json.load(open(p))
d["action_pinning"]["allowed_actions"].pop("gitleaks/gitleaks-action")
open(p, "w").write(json.dumps(d, indent=2) + "\n")
PY
control "58. an in-use action deleted from the allowlist" "pinning.policy_covers_all" "$r"

# 59 — a NEW external action ARRIVES, correctly pinned and correctly commented,
#      as an added step rather than a substitution. Under a derived model this is
#      the arrival that matters: everything about it is well-formed, and the only
#      thing wrong with it is that nobody reviewed the publisher.
r="$(fixture t59)"
mutate "$r/$WF/ci-web.yml" \
  "      - uses: actions/checkout@11d5960a326750d5838078e36cf38b85af677262 # v4.4.0" \
  "      - uses: actions/checkout@11d5960a326750d5838078e36cf38b85af677262 # v4.4.0
      - uses: attacker/helpful-action@2222222222222222222222222222222222222222 # v1.0.0"
control "59. a new, well-formed, unreviewed action added" "pinning.policy_covers_all" "$r"

# 60 — the scan itself blinded. Every check in section M is a statement about the
#      references the parser FOUND, so all of them pass vacuously against a parser
#      that finds none: "all 0 external action reference(s) are pinned" is a green
#      tick for work that never happened — M44's staging deploy and M37's bypass
#      check, reappearing inside the pinning control.
#
#      This one runs the FIXTURE'S OWN validator, because the thing under test is
#      the validator rather than the data. A mutable action is injected at the
#      same time, so a scanner that still worked would fail on that instead and
#      the control would not be provable either way.
r="$(fixture t60)"
mutate "$r/$WF/security.yml" \
  "        uses: gitleaks/gitleaks-action@ff98106e4c7b2bc287b24eaf42907196329070c7 # v2.3.9" \
  "        uses: gitleaks/gitleaks-action@v2"
mutate "$r/$VALIDATOR" \
  "    uses_re = re.compile(r'^\\s*(?:-\\s*)?uses:\\s*([^\\s#]+)\\s*(?:#\\s*(.*))?\$')" \
  "    uses_re = re.compile(r'^ZZ_NEVER_MATCHES\$')"
if out="$(python3 "$r/$VALIDATOR" --repo-root "$r" --json "$r/result.json" 2>&1)"; then
  bad "60. a blinded scanner reported the workflows clean"
  printf '%s\n' "$out" | tail -3 | sed 's/^/      /'
elif python3 -c '
import json,sys
ids=[f["id"] for f in json.load(open(sys.argv[1])).get("failures",[])]
raise SystemExit(0 if "pinning.scanner_complete" in ids else 1)' "$r/result.json"; then
  ok "60. a blinded scanner is caught by its own completeness check"
else
  got="$(python3 -c 'import json,sys;print(",".join(f["id"] for f in json.load(open(sys.argv[1])).get("failures",[]))[:120])' "$r/result.json" 2>/dev/null)"
  bad "60. blinded scanner failed on ${got:-<none>} not pinning.scanner_complete"
fi

# 61 — THE POINT OF M50-F1, as a positive control. A Dependabot Actions bump
#      rewrites every `uses:` line for one action — SHA and trailing comment
#      together — and touches nothing else. Before F1 that produced 54 failures
#      against the policy's duplicate copy and blocked five security updates. It
#      must now pass with no human editing any governance file.
#
#      This is the exact bump from PR #26: actions/checkout v4.4.0 -> v7.0.1.
r="$(fixture t61)"
if ! python3 - "$r/$WF" <<'PY'
import pathlib, sys
old = "11d5960a326750d5838078e36cf38b85af677262 # v4.4.0"
new = "3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1"
n = 0
for f in sorted(pathlib.Path(sys.argv[1]).glob("*.yml")):
    t = f.read_text()
    if old in t:
        n += t.count(old)
        f.write_text(t.replace(old, new))
# actions/setup-node also pins a v4.4.0; anchoring on the SHA AND the comment
# together is what keeps this bump to actions/checkout, exactly as Dependabot
# scopes it. A count that drifts means the anchor no longer describes the
# repository, and this fixture must break loudly rather than test less.
if n != 27:
    sys.stderr.write(f"expected 27 checkout sites, rewrote {n}\n")
    raise SystemExit(9)
PY
then
  bad "61. Dependabot bump fixture could not be built"
elif python3 "$REPO_ROOT/$VALIDATOR" --repo-root "$r" >/dev/null 2>&1; then
  ok "61. a Dependabot Actions bump passes with no governance edit"
else
  bad "61. a legitimate Dependabot Actions bump STILL fails — F1 is not fixed"
  python3 "$REPO_ROOT/$VALIDATOR" --repo-root "$r" 2>&1 | grep -E "^  FAIL" | head -8 | sed 's/^/      /'
fi

# 62 — positive control. Without it, a validator that rejects everything would
#      make all the controls above pass while enforcing nothing.
r="$(fixture t22)"
if python3 "$REPO_ROOT/$VALIDATOR" --repo-root "$r" >/dev/null 2>&1; then
  ok "62. positive control: an unmutated fixture passes"
else
  bad "62. positive control: an UNMUTATED fixture failed — every control above proves nothing"
  python3 "$REPO_ROOT/$VALIDATOR" --repo-root "$r" 2>&1 | grep -E "^  FAIL" | head -12 | sed 's/^/      /'
fi

# 63 — integrity.
echo
after="$(fingerprint)"
if [[ "$before" == "$after" ]]; then
  ok "63. sha256 integrity: the real repository is unchanged"
else
  bad "63. THE REAL REPOSITORY CHANGED during this run"
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
