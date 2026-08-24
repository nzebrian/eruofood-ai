#!/usr/bin/env bash
#
# M31 — negative controls for the platform-foundation validator.
#
# `verify_platform_foundation.sh` currently reports 21 passes. That is true for
# two indistinguishable reasons: the scaffolding is correct, or the validator
# checks nothing. M28 found a five-adapter test sweep that had been exercising
# one adapter five times while green throughout, and this repository has
# shipped a negative control with every gate since.
#
# Each control below breaks one specific thing in a throwaway copy and asserts
# the validator notices. Control 9 is the control on the controls: an untouched
# copy must pass, so a validator that rejects everything cannot masquerade as
# one that works.
#
# Nothing is ever modified in place. Every fixture lives inside `mktemp -d`,
# and the real tree is sha256'd before and after — verified, not asserted,
# because a control that damaged what it was protecting would otherwise still
# print a tidy pass.
set -uo pipefail

MOBILE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VALIDATOR="scripts/verify_platform_foundation.sh"

PASS=0
FAIL=0
ok()  { printf '  \033[32mPASS\033[0m  %s\n' "$1"; PASS=$((PASS + 1)); }
bad() { printf '  \033[31mFAIL\033[0m  %s\n' "$1"; FAIL=$((FAIL + 1)); }

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# Fingerprint the real tree before anything runs.
fingerprint() {
  ( cd "$MOBILE_DIR" && find android ios .metadata -type f 2>/dev/null | sort | xargs sha256sum 2>/dev/null | sha256sum )
}
BEFORE="$(fingerprint)"

# A pristine copy per control, initialised as its own git repository.
#
# The `git init` is not decoration. An early version of this file copied the
# tree without it, and the validator then reported the gitignored
# android/local.properties as committable — a false failure that control 8
# caught immediately. Fixtures have to behave like the real thing or the
# controls test a different program from the one that ships.
fixture() {
  local dir="$WORK/$1"
  mkdir -p "$dir"
  cp -a "$MOBILE_DIR/." "$dir/" 2>/dev/null || true
  rm -rf "$dir/build" "$dir/.dart_tool" "$dir/.git"
  git -C "$dir" init -q 2>/dev/null || true
  echo "$dir"
}

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
d="$(fixture c1)"; rm -rf "$d/android"
if rejects "$d" "android/ is missing"; then
  ok "1 · a missing Android host project is detected"
else
  bad "1 · a missing Android host project was NOT detected"
fi

# -- 2 --------------------------------------------------------------------
d="$(fixture c2)"; rm -rf "$d/ios"
if rejects "$d" "ios/ is missing"; then
  ok "2 · a missing iOS host project is detected"
else
  bad "2 · a missing iOS host project was NOT detected"
fi

# -- 3 --------------------------------------------------------------------
# The file PR #12's scaffolding omitted. Without it a later SDK cannot tell
# which platforms to migrate.
d="$(fixture c3)"; rm -f "$d/.metadata"
if rejects "$d" ".metadata is missing"; then
  ok "3 · a missing .metadata is detected"
else
  bad "3 · a missing .metadata was NOT detected"
fi

# -- 4 --------------------------------------------------------------------
# The failure mode where somebody runs a bare `flutter create .` and quietly
# adds four platforms nothing builds or certifies.
d="$(fixture c4)"; mkdir -p "$d/web"
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
  "$d/android/app/build.gradle.kts"
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
  "$d/android/app/src/main/AndroidManifest.xml"
if rejects "$d" "android launcher label is not"; then
  ok "6 · a reverted launcher label is detected"
else
  bad "6 · a reverted launcher label was NOT detected"
fi

# -- 7 --------------------------------------------------------------------
# `flutter create` runs an implicit resolve that rewrote six transitive pins
# the first time it ran. This control is why that cannot pass unnoticed.
d="$(fixture c7)"; printf '\n# drift\n' >> "$d/pubspec.lock"
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
sed -i '/^GoogleService-Info.plist$/d;/^google-services.json$/d' "$d/.gitignore"
printf '{}\n' > "$d/ios/Runner/GoogleService-Info.plist"
if rejects "$d" "forbidden file is neither ignored nor expected"; then
  ok "8 · without the ignore rule, a service-credential file is detected"
else
  bad "8 · a committable service-credential file was NOT detected"
fi

# -- 8b -------------------------------------------------------------------
# And with the rule in place the same file is correctly not flagged, so the
# control above is measuring the rule and not some unrelated failure.
d="$(fixture c8b)"; printf '{}\n' > "$d/ios/Runner/GoogleService-Info.plist"
if "$d/$VALIDATOR" >/dev/null 2>&1; then
  ok "8b · with the ignore rule, the same file is correctly not committable"
else
  bad "8b · the ignore rule does not actually cover the file"
fi

# -- 9 --------------------------------------------------------------------
# The control on the controls. Without it the eight above cannot distinguish
# a working validator from one that rejects whatever it is handed.
d="$(fixture c9)"
if "$d/$VALIDATOR" >/dev/null 2>&1; then
  ok "9 · an untouched copy passes — the validator is not rejecting everything"
else
  bad "9 · an untouched copy FAILED; controls 1-8 prove nothing"
fi

# -- 10 -------------------------------------------------------------------
AFTER="$(fingerprint)"
if [[ "$BEFORE" == "$AFTER" ]]; then
  ok "10 · the real apps/mobile tree is byte-identical after the run"
else
  bad "10 · the controls MODIFIED the tree they were protecting"
fi

echo
echo "========================================================================"
printf 'RESULT: %d passed, %d failed\n' "$PASS" "$FAIL"
echo "========================================================================"
[[ "$FAIL" -eq 0 ]] || exit 1
