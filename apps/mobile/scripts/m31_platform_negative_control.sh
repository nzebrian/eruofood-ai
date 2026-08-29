#!/usr/bin/env bash
#
# M31/M32 — negative controls for the platform-foundation validator.
#
# `verify_platform_foundation.sh` currently reports 36 passes. That is true for
# two indistinguishable reasons: the scaffolding is correct, or the validator
# checks nothing. M28 found a five-adapter test sweep that had been exercising
# one adapter five times while green throughout, and this repository has
# shipped a negative control with every gate since.
#
# Each control below breaks one specific thing in a throwaway copy and asserts
# the validator notices. Control 10 is the control on the controls: an untouched
# copy must pass, so a validator that rejects everything cannot masquerade as
# one that works.
#
# Nothing is ever modified in place. Every fixture lives inside `mktemp -d`,
# and the real tree is sha256'd before and after — verified, not asserted,
# because a control that damaged what it was protecting would otherwise still
# print a tidy pass.
set -uo pipefail

MOBILE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VALIDATOR="apps/mobile/scripts/verify_platform_foundation.sh"

REPO_ROOT="$MOBILE_DIR"
while [[ "$REPO_ROOT" != "/" && ! -d "$REPO_ROOT/.github/workflows" ]]; do
  REPO_ROOT="$(dirname "$REPO_ROOT")"
done
CERT_REL=".github/workflows/ga-flutter-certification.yml"

PASS=0
FAIL=0
ok()  { printf '  \033[32mPASS\033[0m  %s\n' "$1"; PASS=$((PASS + 1)); }
bad() { printf '  \033[31mFAIL\033[0m  %s\n' "$1"; FAIL=$((FAIL + 1)); }

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# Fingerprint everything the controls could plausibly damage, before they run.
#
# M32 widened this. The suite now edits the certification workflow inside its
# fixtures, and it rewrites pubspec.lock and .gitignore in others — so covering
# only the platform directories would have left the two files most likely to be
# corrupted by a stray absolute path outside the check.
# M33 widened it again. The suite now edits required-checks.json and both
# ruleset files inside fixtures, and those three are the artefacts that decide
# what branch protection enforces — the last place where a stray absolute path
# should be allowed to go unnoticed.
fingerprint() {
  {
    ( cd "$MOBILE_DIR" && find android ios .metadata -type f 2>/dev/null | sort | xargs sha256sum 2>/dev/null )
    ( cd "$MOBILE_DIR" && sha256sum pubspec.yaml pubspec.lock analysis_options.yaml .gitignore 2>/dev/null )
    sha256sum "$REPO_ROOT/$CERT_REL" 2>/dev/null
    ( cd "$REPO_ROOT/.github/governance" && sha256sum required-checks.json main-ruleset.json main-ruleset.sole-owner.json 2>/dev/null )
  } | sha256sum
}
BEFORE="$(fingerprint)"

# A pristine copy per control, initialised as its own git repository.
#
# The `git init` is not decoration. An early version copied the tree without
# it, and the validator then reported the gitignored android/local.properties
# as committable — a false failure the control-on-the-controls caught at once.
# Fixtures have to behave like the real thing or the controls test a different
# program from the one that ships.
#
# For the same reason a fixture is a miniature repository rather than a bare
# copy of apps/mobile: the validator resolves the certification workflow by
# walking up to the repository root, so a fixture without one would fail
# section G for the wrong reason and make every control below meaningless.
fixture() {
  local root="$WORK/$1"
  mkdir -p "$root/apps/mobile" "$root/.github/workflows"
  cp -a "$MOBILE_DIR/." "$root/apps/mobile/" 2>/dev/null || true
  rm -rf "$root/apps/mobile/build" "$root/apps/mobile/.dart_tool" "$root/apps/mobile/.git"
  cp "$REPO_ROOT/$CERT_REL" "$root/$CERT_REL"
  # M33: section G4 reads the governance artefacts, so a fixture without them
  # would fail for the wrong reason and make every control below meaningless —
  # the same trap the certification workflow copy above already avoids.
  mkdir -p "$root/.github/governance"
  cp "$REPO_ROOT/.github/governance/required-checks.json" \
     "$REPO_ROOT/.github/governance/main-ruleset.json" \
     "$REPO_ROOT/.github/governance/main-ruleset.sole-owner.json" \
     "$root/.github/governance/" 2>/dev/null || true
  git -C "$root" init -q 2>/dev/null || true
  echo "$root"
}

# The certification workflow and the required-checks document inside a fixture.
cert() { echo "$1/$CERT_REL"; }
checks() { echo "$1/.github/governance/required-checks.json"; }

# Where apps/mobile lives inside a fixture.
m() { echo "$1/apps/mobile"; }

# Runs the validator in a fixture and reports whether it failed.
rejects() {
  local dir="$1" needle="$2"
  local out rc
  out="$("$dir/$VALIDATOR" 2>&1)"
  rc=$?
  if [[ $rc -eq 0 ]]; then
    return 1
  fi
  # Matched on the specific failure text, not merely "it failed" — a fixture
  # that breaks for an unrelated reason is not proof.
  printf '%s' "$out" | sed 's/\x1b\[[0-9;]*m//g' | grep -q "$needle"
}

echo "========================================================================"
echo "M31 — PLATFORM FOUNDATION NEGATIVE CONTROLS"
echo "========================================================================"
echo

# -- 1 --------------------------------------------------------------------
d="$(fixture c1)"; rm -rf "$(m "$d")/android"
if rejects "$d" "android/ is missing"; then
  ok "1 · a missing Android host project is detected"
else
  bad "1 · a missing Android host project was NOT detected"
fi

# -- 2 --------------------------------------------------------------------
d="$(fixture c2)"; rm -rf "$(m "$d")/ios"
if rejects "$d" "ios/ is missing"; then
  ok "2 · a missing iOS host project is detected"
else
  bad "2 · a missing iOS host project was NOT detected"
fi

# -- 3 --------------------------------------------------------------------
# The file PR #12's scaffolding omitted. Without it a later SDK cannot tell
# which platforms to migrate.
d="$(fixture c3)"; rm -f "$(m "$d")/.metadata"
if rejects "$d" ".metadata is missing"; then
  ok "3 · a missing .metadata is detected"
else
  bad "3 · a missing .metadata was NOT detected"
fi

# -- 4 --------------------------------------------------------------------
# The failure mode where somebody runs a bare `flutter create .` and quietly
# adds four platforms nothing builds or certifies.
d="$(fixture c4)"; mkdir -p "$(m "$d")/web"
if rejects "$d" "web/ exists but M31 does not scaffold"; then
  ok "4 · an unscaffolded extra platform is detected"
else
  bad "4 · an unscaffolded extra platform was NOT detected"
fi

# -- 5 --------------------------------------------------------------------
# Identifier drift between the two platforms: obvious in a report, invisible
# in a diff.
d="$(fixture c5)"
sed -i 's/applicationId = "ai.eruofood.eruofood"/applicationId = "com.example.eruofood"/' \
  "$(m "$d")/android/app/build.gradle.kts"
if rejects "$d" "android applicationId is not"; then
  ok "5 · a wrong Android applicationId is detected"
else
  bad "5 · a wrong Android applicationId was NOT detected"
fi

# -- 6 --------------------------------------------------------------------
# Regenerating on a later SDK reverts the launcher label to "eruofood". This
# is the control that makes that visible instead of shipping it.
d="$(fixture c6)"
sed -i 's/android:label="EruoFood AI"/android:label="eruofood"/' \
  "$(m "$d")/android/app/src/main/AndroidManifest.xml"
if rejects "$d" "android launcher label is not"; then
  ok "6 · a reverted launcher label is detected"
else
  bad "6 · a reverted launcher label was NOT detected"
fi

# -- 7 --------------------------------------------------------------------
# `flutter create` runs an implicit resolve that rewrote six transitive pins
# the first time it ran. This control is why that cannot pass unnoticed.
d="$(fixture c7)"; printf '\n# drift\n' >> "$(m "$d")/pubspec.lock"
if rejects "$d" "pubspec.lock changed"; then
  ok "7 · a modified pubspec.lock is detected"
else
  bad "7 · a modified pubspec.lock was NOT detected"
fi

# -- 8 --------------------------------------------------------------------
# A Google service file carries real project credentials, and before M31
# nothing ignored it — not the repo's .gitignore, not the generated
# android/.gitignore. The rule that now covers it is only worth having if it
# is load-bearing, so this control removes it, plants the file, and asserts
# the validator objects. Planting the file *with* the rule in place proves
# nothing: it would be correctly ignored, and the control would pass while
# testing the rule's absence rather than its presence.
d="$(fixture c8)"
sed -i '/^GoogleService-Info.plist$/d;/^google-services.json$/d' "$(m "$d")/.gitignore"
printf '{}\n' > "$(m "$d")/ios/Runner/GoogleService-Info.plist"
if rejects "$d" "forbidden file is neither ignored nor expected"; then
  ok "8 · without the ignore rule, a service-credential file is detected"
else
  bad "8 · a committable service-credential file was NOT detected"
fi

# -- 8b -------------------------------------------------------------------
# And with the rule in place the same file is correctly not flagged, so the
# control above is measuring the rule and not some unrelated failure.
d="$(fixture c8b)"; printf '{}\n' > "$(m "$d")/ios/Runner/GoogleService-Info.plist"
if "$d/$VALIDATOR" >/dev/null 2>&1; then
  ok "8b · with the ignore rule, the same file is correctly not committable"
else
  bad "8b · the ignore rule does not actually cover the file"
fi

# -- 9a -------------------------------------------------------------------
# The cheapest way to make a red certification green is to delete the step
# that was failing. These four controls exist so that route is closed: the
# milestone had to make the builds *possible*, not optional.
d="$(fixture c9a)"
sed -i '/flutter build apk --release/d' "$d/$CERT_REL"
if rejects "$d" "Android APK build command is missing"; then
  ok "9a · deleting the Android build command is detected"
else
  bad "9a · a deleted Android build command was NOT detected"
fi

# -- 9b -------------------------------------------------------------------
d="$(fixture c9b)"
sed -i '/flutter build ios --release --no-codesign/d' "$d/$CERT_REL"
if rejects "$d" "iOS build command is missing"; then
  ok "9b · deleting the iOS build command is detected"
else
  bad "9b · a deleted iOS build command was NOT detected"
fi

# -- 9c -------------------------------------------------------------------
# A build step allowed to fail without failing the job is not a gate.
d="$(fixture c9c)"
sed -i 's|        run: flutter build apk --release|        continue-on-error: true\n        run: flutter build apk --release|' "$d/$CERT_REL"
if rejects "$d" "marked continue-on-error"; then
  ok "9c · a build step marked continue-on-error is detected"
else
  bad "9c · continue-on-error was NOT detected"
fi

# -- 9d -------------------------------------------------------------------
# Without a mandatory artifact, a build that produced nothing still uploads
# an empty archive and reports success.
d="$(fixture c9d)"
sed -i 's/if-no-files-found: error/if-no-files-found: warn/' "$d/$CERT_REL"
if rejects "$d" "APK artifact is no longer mandatory"; then
  ok "9d · a non-mandatory APK artifact is detected"
else
  bad "9d · a non-mandatory APK artifact was NOT detected"
fi

# -- 9e -------------------------------------------------------------------
# Relaxing analyze is the other quiet way to make a gate stop biting.
d="$(fixture c9e)"
sed -i 's/ --fatal-infos --fatal-warnings//' "$d/$CERT_REL"
if rejects "$d" "analyze is no longer strict"; then
  ok "9e · a relaxed analyze step is detected"
else
  bad "9e · a relaxed analyze step was NOT detected"
fi

# -- 9f -------------------------------------------------------------------
# M32's controls. Section G proves the build steps exist; these prove they
# actually run before a merge. The gate was in exactly this state for twenty
# days — steps present, never executed on a pull request — so "the commands
# are there" is demonstrably not the same claim as "the gate works".
#
# Each fixture edits the trigger block with Python rather than sed: `on:` is a
# YAML boolean in disguise, and a nested `paths:` cannot be added or removed
# reliably by line matching.
retrigger() {  # retrigger <fixture> <python-expression-on-`on`>
  python3 - "$1/$CERT_REL" <<PY
import sys,yaml
p=sys.argv[1]
d=yaml.safe_load(open(p))
key='on' if 'on' in d else True
on=d[key]
$2
d[key]=on
yaml.safe_dump(d,open(p,'w'),sort_keys=False,default_flow_style=False)
PY
}

d="$(fixture c9f)"; retrigger "$d" "on.pop('pull_request',None)"
if rejects "$d" "NO pull_request trigger"; then
  ok "9f · removing the pull_request trigger is detected"
else
  bad "9f · a removed pull_request trigger was NOT detected"
fi

# -- 9g -------------------------------------------------------------------
# The deadlock-maker. A path filter here looks like a harmless optimisation
# and is the exact defect M29-A removed from four other workflows.
d="$(fixture c9g)"; retrigger "$d" "on['pull_request']={'paths':['apps/mobile/**']}"
if rejects "$d" "pull_request has a paths filter"; then
  ok "9g · a paths filter under pull_request is detected"
else
  bad "9g · a paths filter under pull_request was NOT detected"
fi

# -- 9h -------------------------------------------------------------------
# Same deadlock, different spelling — and the one a grep for "paths:" would
# miss if it only looked for the positive form.
d="$(fixture c9h)"; retrigger "$d" "on['pull_request']={'paths-ignore':['docs/**']}"
if rejects "$d" "paths-ignore filter"; then
  ok "9h · a paths-ignore filter under pull_request is detected"
else
  bad "9h · a paths-ignore filter under pull_request was NOT detected"
fi

# -- 9i -------------------------------------------------------------------
d="$(fixture c9i)"; retrigger "$d" "on.pop('workflow_dispatch',None)"
if rejects "$d" "workflow_dispatch was removed"; then
  ok "9i · removing workflow_dispatch is detected"
else
  bad "9i · a removed workflow_dispatch was NOT detected"
fi

# -- 9j -------------------------------------------------------------------
# Not decorative: ga-release-certification.yml consumes this workflow through
# workflow_call, so losing it silently breaks the consolidated GA gate.
d="$(fixture c9j)"; retrigger "$d" "on.pop('workflow_call',None)"
if rejects "$d" "workflow_call was removed"; then
  ok "9j · removing workflow_call is detected"
else
  bad "9j · a removed workflow_call was NOT detected"
fi

# -- 9k -------------------------------------------------------------------
# Dropping push would stop certifying main after a merge — the opposite
# failure from 9f, and just as quiet.
d="$(fixture c9k)"; retrigger "$d" "on.pop('push',None)"
if rejects "$d" "push trigger was removed"; then
  ok "9k · removing the push trigger is detected"
else
  bad "9k · a removed push trigger was NOT detected"
fi

# -- 9l -------------------------------------------------------------------
# Widening push past main/develop, or losing its path filter, are the two ways
# post-merge certification quietly changes shape.
d="$(fixture c9l)"; retrigger "$d" "on['push']['branches']=['main','develop','feature/*']"
if rejects "$d" "no longer exactly"; then
  ok "9l · widened push branches are detected"
else
  bad "9l · widened push branches were NOT detected"
fi

d="$(fixture c9m)"; retrigger "$d" "on['push'].pop('paths',None)"
if rejects "$d" "lost its mobile/workflow path filtering"; then
  ok "9m · removing the push path filter is detected"
else
  bad "9m · a removed push path filter was NOT detected"
fi

# =========================================================================
# M36 — the mobile dependency baseline
#
# M31 hash-pinned pubspec.yaml and pubspec.lock outright, which stopped
# accidental regeneration drift and also stopped every deliberate dependency
# bump — five open pull requests, failing for doing their job. M36 replaced
# the pin with a refreshable baseline plus consistency checks that run whether
# or not the hashes match.
#
# The risk that swap introduces is obvious and is what 12–17 exist to close:
# a refreshable baseline is only worth having if refreshing it is the ONLY way
# through, and if an incoherent pair still fails after a refresh.
# =========================================================================

# -- 12 -------------------------------------------------------------------
# The one that would be easy to get wrong: a real dependency bump, refreshed
# through the documented command, must PASS. Without this the whole M36 change
# could ship as "pin everything harder" and nobody would notice.
d="$(fixture c12)"
sed -i 's|^  get_it: \^8\.0\.0$|  get_it: ^9.0.0|' "$(m "$d")/pubspec.yaml"
python3 - "$(m "$d")/pubspec.lock" <<'PY'
import re, sys
p = sys.argv[1]
s = open(p).read()
s = re.sub(r'(  get_it:\n(?:    .*\n)*?    version: ")8\.3\.0(")', r'\g<1>9.2.1\g<2>', s)
open(p, 'w').write(s)
PY
env -u CI "$(m "$d")/scripts/refresh_mobile_dependency_baseline.sh" >/dev/null 2>&1
if "$d/$VALIDATOR" >/dev/null 2>&1; then
  ok "12 · a deliberate dependency bump refreshed through the command PASSES"
else
  bad "12 · a legitimate refreshed dependency bump was rejected — the path does not work"
fi

# -- 13 -------------------------------------------------------------------
d="$(fixture c13)"; printf '\n# drift\n' >> "$(m "$d")/pubspec.yaml"
if rejects "$d" "pubspec.yaml changed without a baseline refresh"; then
  ok "13 · pubspec.yaml changed without refreshing the baseline is detected"
else
  bad "13 · an unrefreshed pubspec.yaml change was NOT detected"
fi

# -- 14 -------------------------------------------------------------------
# The heart of it: refreshing the hashes must not launder an incoherent pair.
# Here pubspec.yaml gains a dependency the lockfile has never heard of, and the
# baseline is refreshed anyway. The consistency checks run regardless of the
# hashes, so this must still fail.
d="$(fixture c14)"
sed -i 's|^  get_it: \^8\.0\.0$|  get_it: ^8.0.0\n  http: ^1.2.0|' "$(m "$d")/pubspec.yaml"
python3 - "$(m "$d")/m31-platform-manifest.json" "$(m "$d")" "$(m "$d")/scripts" <<'PY'
import hashlib, json, sys
manifest, mobile, script_dir = sys.argv[1], sys.argv[2], sys.argv[3]
sys.path.insert(0, script_dir)
from mobile_dependency_lib import yaml_direct_deps
d = json.load(open(manifest))
b = d['dependency_baseline']
b['pubspec_yaml_sha256'] = hashlib.sha256(open(mobile + '/pubspec.yaml', 'rb').read()).hexdigest()
deps, dev = yaml_direct_deps(mobile + '/pubspec.yaml')
b['direct_dependencies'], b['direct_dev_dependencies'] = deps, dev
json.dump(d, open(manifest, 'w'), indent=2)
PY
if rejects "$d" "absent from pubspec.lock"; then
  ok "14 · a hash refresh cannot launder a yaml/lock pair that disagrees"
else
  bad "14 · an inconsistent yaml/lock pair PASSED after a hash refresh"
fi

# -- 15 -------------------------------------------------------------------
# The reverse direction: the lockfile carries a direct package pubspec.yaml
# does not declare.
d="$(fixture c15)"
sed -i 's|^  dartz: \^0\.10\.1$||' "$(m "$d")/pubspec.yaml"
env -u CI "$(m "$d")/scripts/refresh_mobile_dependency_baseline.sh" >/dev/null 2>&1 || true
if rejects "$d" "not declared in pubspec.yaml"; then
  ok "15 · a lockfile direct package missing from pubspec.yaml is detected"
else
  bad "15 · an undeclared locked direct package was NOT detected"
fi

# -- 16 -------------------------------------------------------------------
# Hand-edited hashes with the recorded dependency set left stale. This is the
# "just make CI green" move, and it must not work.
d="$(fixture c16)"
sed -i 's|^  get_it: \^8\.0\.0$|  get_it: ^9.0.0|' "$(m "$d")/pubspec.yaml"
python3 - "$(m "$d")/m31-platform-manifest.json" "$(m "$d")" <<'PY'
import hashlib, json, sys
manifest, mobile = sys.argv[1], sys.argv[2]
d = json.load(open(manifest))
d['dependency_baseline']['pubspec_yaml_sha256'] = hashlib.sha256(
    open(mobile + '/pubspec.yaml', 'rb').read()).hexdigest()
json.dump(d, open(manifest, 'w'), indent=2)
PY
if rejects "$d" "recorded dependencies disagree with pubspec.yaml"; then
  ok "16 · editing only the hash, leaving the recorded set stale, is detected"
else
  bad "16 · a hash-only edit PASSED — the baseline is a rubber stamp"
fi

# -- 17 -------------------------------------------------------------------
d="$(fixture c17)"; printf '\n# drift\n' >> "$(m "$d")/analysis_options.yaml"
if rejects "$d" "analysis_options.yaml changed"; then
  ok "17 · analysis_options.yaml drift is still detected"
else
  bad "17 · analysis_options.yaml drift was NOT detected"
fi

# -- 18 -------------------------------------------------------------------
# The defect M36 removed, asserted as an invariant so it cannot come back.
# Section H used to demand that every changed file sat under apps/mobile/;
# simulated against the real open pull requests it failed 13 of 18, including
# every Dependabot workflow bump. Unrelated backend, web and workflow work
# must pass this validator, because none of it is a mobile-platform concern.
d="$(fixture c18)"
mkdir -p "$d/apps/api" "$d/apps/web" "$d/.github/workflows"
printf 'x\n' > "$d/apps/api/composer.json"
printf 'x\n' > "$d/apps/web/package.json"
printf 'name: x\non: push\njobs:\n  a:\n    runs-on: ubuntu-latest\n    steps:\n      - run: "true"\n' \
  > "$d/.github/workflows/release.yml"
git -C "$d" add -A >/dev/null 2>&1 || true
if "$d/$VALIDATOR" >/dev/null 2>&1; then
  ok "18 · unrelated backend/web/workflow files do NOT fail the mobile validator"
else
  bad "18 · milestone-scoped blocking logic is back — unrelated work fails the validator"
fi

# -- 19 -------------------------------------------------------------------
# The silent-skip defect. A section that does not run must reduce the executed
# count and fail loudly, never shrink the total and still exit 0.
d="$(fixture c19)"
sed -i 's/^EXPECTED_CHECKS=[0-9]*$/EXPECTED_CHECKS=999/' "$(m "$d")/scripts/verify_platform_foundation.sh"
if rejects "$d" "declared checks executed"; then
  ok "19 · a check that does not execute is detected, not silently dropped"
else
  bad "19 · under-reported coverage PASSED — a section could vanish unnoticed"
fi

# =========================================================================
# M33 — the Mobile Certification aggregator.
#
# The gate these controls protect is the one branch protection will actually
# require, and it can be made false-green two opposite ways: strip the
# always() so a failed platform SKIPS it (skipped reports no conclusion, which
# GitHub treats as pending, not failed), or keep always() and stop reading the
# results (green no matter what happened). Both are one-line edits, and every
# control below is one of them.
#
# `yq` is not a dependency here, so the mutations are done with python3 +
# PyYAML, which the validator already requires. Each writes the mutated
# workflow back into its own fixture; the real file is never touched, and
# control 11 proves that by hash.
# =========================================================================

# Rewrites the aggregator inside a fixture. $2 is python operating on `agg`,
# the aggregator job dict, and `jobs`, the whole jobs mapping.
mutate_agg() {
  local file="$1" code="$2"
  python3 - "$file" "$code" <<'PY'
import sys, yaml
path, code = sys.argv[1], sys.argv[2]
doc = yaml.safe_load(open(path))
jobs = doc['jobs']
agg_key = next((k for k, v in jobs.items() if (v or {}).get('name') == 'Mobile Certification'), None)
agg = jobs.get(agg_key) if agg_key else None
exec(code)
yaml.safe_dump(doc, open(path, 'w'), sort_keys=False, default_flow_style=False, allow_unicode=True)
PY
}

# -- 20 -------------------------------------------------------------------
d="$(fixture c20)"; mutate_agg "$(cert "$d")" "del jobs[agg_key]"
if rejects "$d" "no job is named 'Mobile Certification'"; then
  ok "20 · a removed aggregator job is detected"
else
  bad "20 · a removed aggregator job was NOT detected"
fi

# -- 21 -------------------------------------------------------------------
# A rename is indistinguishable from a deletion as far as the ruleset is
# concerned: the required context simply stops reporting.
d="$(fixture c21)"; mutate_agg "$(cert "$d")" "agg['name'] = 'Mobile Certification '"
if rejects "$d" "no job is named 'Mobile Certification'"; then
  ok "21 · a renamed aggregator (trailing space) is detected"
else
  bad "21 · a renamed aggregator was NOT detected"
fi

# -- 22 -------------------------------------------------------------------
d="$(fixture c22)"; mutate_agg "$(cert "$d")" "agg['needs'] = ['ios']"
if rejects "$d" "does not need the android job"; then
  ok "22 · a dropped android dependency is detected"
else
  bad "22 · a dropped android dependency was NOT detected"
fi

# -- 23 -------------------------------------------------------------------
d="$(fixture c23)"; mutate_agg "$(cert "$d")" "agg['needs'] = ['android']"
if rejects "$d" "does not need the ios job"; then
  ok "23 · a dropped ios dependency is detected"
else
  bad "23 · a dropped ios dependency was NOT detected"
fi

# -- 24 -------------------------------------------------------------------
# Without always(), a red Android SKIPS this job. A skipped required check
# never reports, and a required check that never reports blocks every pull
# request forever — the M29-A trap, reached from the other side.
d="$(fixture c24)"; mutate_agg "$(cert "$d")" "agg.pop('if', None)"
if rejects "$d" "lacks if: always()"; then
  ok "24 · a removed if: always() is detected"
else
  bad "24 · a removed if: always() was NOT detected"
fi

# -- 25 -------------------------------------------------------------------
# always() plus success() is the subtle version: it looks defensive and skips
# on exactly the failures that matter.
d="$(fixture c25)"; mutate_agg "$(cert "$d")" "agg['if'] = '\${{ success() }}'"
if rejects "$d" "lacks if: always()"; then
  ok "25 · if: always() replaced by success() is detected"
else
  bad "25 · a weakened job condition was NOT detected"
fi

# -- 26 -------------------------------------------------------------------
d="$(fixture c26)"
mutate_agg "$(cert "$d")" "agg['steps'][0]['env'].pop('ANDROID_RESULT', None)"
if rejects "$d" "never reads the Android result"; then
  ok "26 · a removed Android result check is detected"
else
  bad "26 · a removed Android result check was NOT detected"
fi

# -- 27 -------------------------------------------------------------------
d="$(fixture c27)"
mutate_agg "$(cert "$d")" "agg['steps'][0]['env'].pop('IOS_RESULT', None)"
if rejects "$d" "never reads the iOS result"; then
  ok "27 · a removed iOS result check is detected"
else
  bad "27 · a removed iOS result check was NOT detected"
fi

# -- 28 -------------------------------------------------------------------
# The false-green gate the milestone brief names explicitly: always() with an
# unconditional pass.
d="$(fixture c28)"
mutate_agg "$(cert "$d")" "agg['steps'][0]['run'] = 'echo ok'"
if rejects "$d" "no failing path"; then
  ok "28 · an aggregator with no failing path is detected"
else
  bad "28 · a false-green aggregator was NOT detected"
fi

# -- 29 -------------------------------------------------------------------
# `&&` instead of `||`: green whenever EITHER platform succeeded.
d="$(fixture c29)"
mutate_agg "$(cert "$d")" "agg['steps'][0]['run'] = agg['steps'][0]['run'].replace('||', '&&')"
if rejects "$d" "success condition was weakened"; then
  ok "29 · a success-only condition weakened to a conjunction is detected"
else
  bad "29 · a weakened success condition was NOT detected"
fi

# -- 30/31/32 -------------------------------------------------------------
# Treating a specific non-success result as acceptable. Each of these is the
# shape somebody reaches for when a flaky macOS runner is annoying them.
i=30
for verdict in failure cancelled skipped; do
  d="$(fixture "c$i")"
  mutate_agg "$(cert "$d")" \
    "agg['steps'][0]['run'] = agg['steps'][0]['run'].replace('\"\${ANDROID_RESULT}\" != \"success\"', '\"\${ANDROID_RESULT}\" != \"success\" && \"\${ANDROID_RESULT}\" != \"$verdict\"')"
  if rejects "$d" "success condition was weakened"; then
    ok "$i · '$verdict' treated as an acceptable Android result is detected"
  else
    bad "$i · '$verdict' treated as acceptable was NOT detected"
  fi
  i=$((i + 1))
done

# -- 33 -------------------------------------------------------------------
d="$(fixture c33)"
mutate_agg "$(cert "$d")" "agg['steps'][0]['continue-on-error'] = True"
if rejects "$d" "continue-on-error"; then
  ok "33 · continue-on-error on the aggregator is detected"
else
  bad "33 · continue-on-error was NOT detected"
fi

# -- 34/35 ----------------------------------------------------------------
# Requiring a platform job directly. It looks stricter and is strictly worse:
# it pins a second byte-exact context containing U+00B7 into the ruleset, so a
# later rename stops it reporting and wedges every pull request.
i=34
for job in "Android · doctor · analyze · test · build apk" "iOS · analyze · test · build (no codesign)"; do
  d="$(fixture "c$i")"
  python3 - "$(checks "$d")" "$job" <<'PY'
import sys, json
path, ctx = sys.argv[1], sys.argv[2]
d = json.load(open(path))
d['required'].append({'context': ctx, 'workflow': '.github/workflows/ga-flutter-certification.yml'})
json.dump(d, open(path, 'w'), indent=2, ensure_ascii=False)
PY
  if rejects "$d" "individually required context"; then
    ok "$i · requiring a platform job directly is detected"
  else
    bad "$i · an individually required platform job was NOT detected"
  fi
  i=$((i + 1))
done

# -- 36 -------------------------------------------------------------------
d="$(fixture c36)"
python3 - "$(checks "$d")" <<'PY'
import sys, json
path = sys.argv[1]
d = json.load(open(path))
d['required'] = [c for c in d['required'] if c.get('context') != 'Mobile Certification']
json.dump(d, open(path, 'w'), indent=2, ensure_ascii=False)
PY
if rejects "$d" "NOT in required-checks.json"; then
  ok "36 · removing Mobile Certification from required governance is detected"
else
  bad "36 · a de-required gate was NOT detected"
fi

# -- 37 -------------------------------------------------------------------
# Renaming the context in governance while the job keeps its name. The two
# drift apart silently and the ruleset waits for a check nobody reports.
d="$(fixture c37)"
python3 - "$(checks "$d")" <<'PY'
import sys, json
path = sys.argv[1]
d = json.load(open(path))
for c in d['required']:
    if c.get('context') == 'Mobile Certification':
        c['context'] = 'Mobile certification'
json.dump(d, open(path, 'w'), indent=2, ensure_ascii=False)
PY
if rejects "$d" "NOT in required-checks.json"; then
  ok "37 · a renamed required context is detected"
else
  bad "37 · a renamed required context was NOT detected"
fi

# -- 38/39 ----------------------------------------------------------------
# M32's deadlock protection, re-asserted now that the context is genuinely
# about to be required. Both spellings fail the same way.
i=38
for filt in paths paths-ignore; do
  d="$(fixture "c$i")"
  python3 - "$(cert "$d")" "$filt" <<'PY'
import sys, yaml
path, filt = sys.argv[1], sys.argv[2]
doc = yaml.safe_load(open(path))
on = doc.get('on', doc.get(True))
on['pull_request'] = {filt: ['apps/mobile/**']}
doc.pop(True, None)
doc['on'] = on
yaml.safe_dump(doc, open(path, 'w'), sort_keys=False, default_flow_style=False, allow_unicode=True)
PY
  if rejects "$d" "$filt filter"; then
    ok "$i · a pull_request $filt filter is detected"
  else
    bad "$i · a pull_request $filt filter was NOT detected"
  fi
  i=$((i + 1))
done

# -- 40 -------------------------------------------------------------------
# FAIL-CLOSED MATRIX. Not a mutation: this extracts the aggregator's real
# script from the real workflow and runs it under every result pair, so the
# thing under test is the shipped logic rather than a paraphrase of it.
#
# The one green cell is success + success. `neutral` stands in for a result
# GitHub has not invented yet, and the empty string for a dependency that
# never reported at all.
matrix_script="$WORK/agg.sh"
python3 - "$REPO_ROOT/$CERT_REL" "$matrix_script" <<'PY'
import sys, yaml
doc = yaml.safe_load(open(sys.argv[1]))
agg = next(v for v in doc['jobs'].values() if (v or {}).get('name') == 'Mobile Certification')
open(sys.argv[2], 'w').write(agg['steps'][0]['run'])
PY

matrix_fail=0
matrix_cases=0
for a in success failure cancelled skipped neutral ""; do
  for o in success failure cancelled skipped neutral ""; do
    matrix_cases=$((matrix_cases + 1))
    if ANDROID_RESULT="$a" IOS_RESULT="$o" bash "$matrix_script" >/dev/null 2>&1; then
      # Exit 0. Legitimate only for the single all-success cell.
      [[ "$a" == "success" && "$o" == "success" ]] || matrix_fail=$((matrix_fail + 1))
    else
      # Exit non-zero. Wrong only if both were success.
      [[ "$a" == "success" && "$o" == "success" ]] && matrix_fail=$((matrix_fail + 1))
    fi
  done
done

if [[ "$matrix_fail" -eq 0 ]]; then
  ok "40 · fail-closed across all $matrix_cases result pairs (only success+success passes)"
else
  bad "40 · $matrix_fail of $matrix_cases result pairs produced the wrong verdict"
fi

# -- 10 -------------------------------------------------------------------
# The control on the controls. Without it the controls above cannot
# distinguish a working validator from one that rejects whatever it is handed.
d="$(fixture c10)"
if "$d/$VALIDATOR" >/dev/null 2>&1; then
  ok "10 · an untouched copy passes — the validator is not rejecting everything"
else
  bad "10 · an untouched copy FAILED; every control above proves nothing"
fi

# -- 11 -------------------------------------------------------------------
AFTER="$(fingerprint)"
if [[ "$BEFORE" == "$AFTER" ]]; then
  ok "11 · the workflow and apps/mobile tree are byte-identical after the run"
else
  bad "11 · the controls MODIFIED something they were protecting"
fi

echo
echo "========================================================================"
printf 'RESULT: %d passed, %d failed\n' "$PASS" "$FAIL"
echo "========================================================================"
[[ "$FAIL" -eq 0 ]] || exit 1
