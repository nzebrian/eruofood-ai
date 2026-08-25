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
fingerprint() {
  {
    ( cd "$MOBILE_DIR" && find android ios .metadata -type f 2>/dev/null | sort | xargs sha256sum 2>/dev/null )
    ( cd "$MOBILE_DIR" && sha256sum pubspec.yaml pubspec.lock analysis_options.yaml .gitignore 2>/dev/null )
    sha256sum "$REPO_ROOT/$CERT_REL" 2>/dev/null
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
  git -C "$root" init -q 2>/dev/null || true
  echo "$root"
}

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

# -- 10 -------------------------------------------------------------------
# The control on the controls. Without it the twenty-one above cannot
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
