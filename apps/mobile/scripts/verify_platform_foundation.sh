#!/usr/bin/env bash
#
# M31 — Mobile Platform Foundation validator.
#
# Answers one question the certification workflow cannot: is the thing that
# just went green actually the thing we meant to ship? `flutter build apk`
# proves a build succeeded. It does not prove the build came from generated
# scaffolding rather than hand-written files, that no fourth platform crept in,
# that the lockfile survived, or that nobody committed a keystore.
#
# Run from anywhere:
#     apps/mobile/scripts/verify_platform_foundation.sh
#
# Exit 0 = every check passed. Exit 1 = at least one failed, and the output
# names which. There is deliberately no "warning" level: a check that can be
# ignored is a check that will be.
set -uo pipefail

MOBILE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MANIFEST="$MOBILE_DIR/m31-platform-manifest.json"

# The certification workflow lives at the repository root. Resolved by walking
# up rather than by a fixed ../../ so the validator still works from a fixture
# copy, where it simply finds no workflow and says so instead of guessing.
REPO_ROOT="$MOBILE_DIR"
while [[ "$REPO_ROOT" != "/" && ! -d "$REPO_ROOT/.github/workflows" ]]; do
  REPO_ROOT="$(dirname "$REPO_ROOT")"
done
CERT_WORKFLOW="$REPO_ROOT/.github/workflows/ga-flutter-certification.yml"

PASS=0
FAIL=0

ok()   { printf '  \033[32mPASS\033[0m  %s\n' "$1"; PASS=$((PASS + 1)); }
bad()  { printf '  \033[31mFAIL\033[0m  %s\n' "$1"; FAIL=$((FAIL + 1)); }
head_() { printf '\n%s\n' "$1"; }

# jq is not a dependency of this repository, and adding one for a validator
# would be its own small supply-chain decision. Python 3 is already required by
# the governance scripts, so the manifest is read with that.
manifest_list() {
  python3 -c "
import json,sys
d=json.load(open('$MANIFEST'))
for p in d['expected'].get('$1', []):
    print(p)
"
}

manifest_value() {
  python3 -c "
import json
d=json.load(open('$MANIFEST'))
cur=d
for k in '$1'.split('.'):
    cur=cur[k]
print(cur)
"
}

echo "========================================================================"
echo "M31 — MOBILE PLATFORM FOUNDATION"
echo "  mobile dir: $MOBILE_DIR"
echo "========================================================================"

if [[ ! -f "$MANIFEST" ]]; then
  echo "  FAIL  the manifest is missing; there is nothing to validate against."
  exit 1
fi

# -- A. Platform foundation ---------------------------------------------------
#
# Existence, and just as importantly non-existence. M31 scaffolds android and
# ios because those are the two platforms the certification workflow builds.
# A stray web/ or macos/ directory would be ~40 files nothing verifies.

head_ "A) Platform foundation"

for d in android ios; do
  if [[ -d "$MOBILE_DIR/$d" ]]; then ok "$d/ exists"; else bad "$d/ is missing"; fi
done

if [[ -f "$MOBILE_DIR/.metadata" ]]; then
  ok ".metadata exists"
else
  # The file the stale PR #12 scaffolding omitted. Without it the Flutter tool
  # cannot tell which platforms this project claims, and `flutter create` on a
  # later SDK will not know what to migrate.
  bad ".metadata is missing — Flutter cannot record which platforms this project has"
fi

for d in $(manifest_value forbidden_platforms | tr -d "[],'\"" ); do
  if [[ -e "$MOBILE_DIR/$d" ]]; then
    bad "$d/ exists but M31 does not scaffold or certify it"
  else
    ok "no $d/ directory"
  fi
done

if [[ -f "$MOBILE_DIR/.metadata" ]]; then
  # Matched on the key rather than a fixed indent: the template's indentation
  # is not a contract, and pinning it would make this fail on a cosmetic
  # change while a genuinely extra platform slipped through.
  declared=$(grep -oE '^[[:space:]]*- platform: [a-z]+' "$MOBILE_DIR/.metadata" | awk '{print $3}' | sort | tr '\n' ' ')
  if [[ "$declared" == "android ios root " ]]; then
    ok ".metadata declares exactly root, android and ios"
  else
    bad ".metadata declares [${declared% }]; expected [android ios root]"
  fi
fi

# -- B. Expected-file manifest, both directions -------------------------------
#
# One direction catches a generation that did not finish. The other catches
# files that arrived from somewhere else — the stale PR #12 being the specific
# thing this milestone was told not to import.

head_ "B) Expected-file manifest"

missing=0
for group in flutter_metadata android ios; do
  while IFS= read -r rel; do
    [[ -z "$rel" ]] && continue
    [[ -e "$MOBILE_DIR/$rel" ]] || { bad "expected file absent: $rel"; missing=$((missing + 1)); }
  done < <(manifest_list "$group")
done
[[ "$missing" -eq 0 ]] && ok "every file in the manifest is present"

# The reverse check only makes sense inside a git work tree.
if git -C "$MOBILE_DIR" rev-parse --git-dir >/dev/null 2>&1; then
  expected_tmp="$(mktemp)"
  actual_tmp="$(mktemp)"
  trap 'rm -f "$expected_tmp" "$actual_tmp"' EXIT

  { manifest_list flutter_metadata; manifest_list android; manifest_list ios; } | sort > "$expected_tmp"

  # Tracked *and* untracked-but-not-ignored, so a file staged for commit is
  # caught whether or not it has been added yet. Ignored paths are excluded
  # deliberately: ios/Flutter/Generated.xcconfig and its siblings hold absolute
  # machine paths and are regenerated per machine.
  {
    git -C "$MOBILE_DIR" ls-files android ios .metadata
    git -C "$MOBILE_DIR" ls-files --others --exclude-standard android ios .metadata
  } | sort -u > "$actual_tmp"

  if extra=$(comm -13 "$expected_tmp" "$actual_tmp") && [[ -z "$extra" ]]; then
    ok "no platform file outside the manifest"
  else
    bad "files present but not in the manifest:"
    while IFS= read -r unexpected; do
      [[ -n "$unexpected" ]] && printf '        %s\n' "$unexpected"
    done <<< "$extra"
  fi
fi

# -- C. Identity --------------------------------------------------------------
#
# Asserted by grep rather than read by a person. Three files have to agree, and
# a mismatch between the Android applicationId and the iOS bundle identifier is
# the kind of thing that is obvious in a report and invisible in a diff.

head_ "C) Identity"

want_ns=$(manifest_value identity.android_namespace)
want_app=$(manifest_value identity.android_application_id)
want_ios=$(manifest_value identity.ios_bundle_identifier)
gradle="$MOBILE_DIR/android/app/build.gradle.kts"
pbx="$MOBILE_DIR/ios/Runner.xcodeproj/project.pbxproj"

if [[ -f "$gradle" ]]; then
  if grep -q "namespace = \"$want_ns\"" "$gradle"; then
    ok "android namespace is $want_ns"
  else
    bad "android namespace is not $want_ns"
  fi
  if grep -q "applicationId = \"$want_app\"" "$gradle"; then
    ok "android applicationId is $want_app"
  else
    bad "android applicationId is not $want_app"
  fi
else
  bad "android/app/build.gradle.kts is missing"
fi

if [[ -f "$pbx" ]]; then
  # The app target's identifier. RunnerTests legitimately carries a suffixed
  # one, so an exact-match count on the bare identifier is what distinguishes
  # "the app is right" from "some target somewhere mentions it".
  if grep -q "PRODUCT_BUNDLE_IDENTIFIER = $want_ios;" "$pbx"; then
    ok "ios PRODUCT_BUNDLE_IDENTIFIER is $want_ios"
  else
    bad "ios PRODUCT_BUNDLE_IDENTIFIER is not $want_ios"
  fi
  if [[ "$want_app" == "$want_ios" ]]; then
    ok "android and ios identifiers agree"
  else
    bad "android ($want_app) and ios ($want_ios) identifiers disagree"
  fi
else
  bad "ios/Runner.xcodeproj/project.pbxproj is missing"
fi

# -- D. Branding --------------------------------------------------------------
#
# `flutter create` derives the launcher label from the project name, which
# yields "eruofood" and "Eruofood". Neither is the product's name. This is the
# one place M31 edits generated output, so it is the one place that needs a
# standing check: a regeneration on a later SDK silently reverts both.

head_ "D) Branding"

want_name=$(manifest_value identity.display_name)
manifest_xml="$MOBILE_DIR/android/app/src/main/AndroidManifest.xml"
plist="$MOBILE_DIR/ios/Runner/Info.plist"

if [[ -f "$manifest_xml" ]]; then
  if grep -q "android:label=\"$want_name\"" "$manifest_xml"; then
    ok "android launcher label is \"$want_name\""
  else
    bad "android launcher label is not \"$want_name\" (regeneration reverts this)"
  fi
else
  bad "AndroidManifest.xml is missing"
fi

if [[ -f "$plist" ]]; then
  if grep -A1 '<key>CFBundleDisplayName</key>' "$plist" | grep -q "<string>$want_name</string>"; then
    ok "ios CFBundleDisplayName is \"$want_name\""
  else
    bad "ios CFBundleDisplayName is not \"$want_name\" (regeneration reverts this)"
  fi
fi

# -- E. Protected files -------------------------------------------------------
#
# `flutter create` runs an implicit resolve and rewrote six transitive pins the
# first time it ran here. Restoring the lockfile and re-running `flutter pub get`
# left it byte-identical, which is the evidence that the committed lock is
# reproducible under this SDK rather than merely old.

head_ "E) Protected files unchanged"

for f in pubspec.yaml pubspec.lock analysis_options.yaml; do
  want=$(python3 -c "
import json
print(json.load(open('$MANIFEST'))['protected_unchanged']['$f'])
")
  if [[ -f "$MOBILE_DIR/$f" ]]; then
    got=$(sha256sum "$MOBILE_DIR/$f" | cut -d' ' -f1)
    if [[ "$got" == "$want" ]]; then
      ok "$f is byte-identical to the M31 baseline"
    else
      bad "$f changed (expected ${want:0:12}…, got ${got:0:12}…)"
    fi
  else
    bad "$f is missing"
  fi
done

# -- F. Secrets and machine-specific paths ------------------------------------
#
# Nothing here is theoretical. Generation produced three iOS files holding this
# machine's absolute paths; they are covered by the generated ios/.gitignore,
# and this check is what proves that stays true.

head_ "F) Secrets and machine-specific paths"

# "Committable" is the question, not "present". `flutter build` legitimately
# writes android/local.properties holding this machine's SDK path, and it is
# gitignored. Deciding by `git check-ignore` alone breaks outside a work tree —
# it reported that very file as committable in a throwaway fixture copy, which
# is what the negative controls caught. So the manifest is the primary
# authority (a forbidden path that is *expected* is a real failure) and git is
# consulted only as a second, stricter opinion when it is available.
expected_all="$(mktemp)"
{ manifest_list flutter_metadata; manifest_list android; manifest_list ios; } | sort > "$expected_all"
have_git=0
git -C "$MOBILE_DIR" rev-parse --git-dir >/dev/null 2>&1 && have_git=1

found_secret=0
while IFS= read -r pat; do
  [[ -z "$pat" ]] && continue
  while IFS= read -r hit; do
    [[ -z "$hit" ]] && continue
    if grep -Fxq "$hit" "$expected_all"; then
      bad "forbidden file is in the expected manifest: $hit"
      found_secret=1
      continue
    fi
    if [[ "$have_git" -eq 1 ]] && ! git -C "$MOBILE_DIR" check-ignore -q "$hit"; then
      bad "forbidden file is neither ignored nor expected: $hit"
      found_secret=1
    fi
  done < <(cd "$MOBILE_DIR" && find android ios -name "$pat" 2>/dev/null)
done < <(python3 -c "
import json
for p in json.load(open('$MANIFEST'))['forbidden_file_patterns']:
    print(p)
")
rm -f "$expected_all"
[[ "$found_secret" -eq 0 ]] && ok "no keystore, certificate, profile or service-account file is committable"

if git -C "$MOBILE_DIR" rev-parse --git-dir >/dev/null 2>&1; then
  leaked=0
  while IFS= read -r rel; do
    [[ -z "$rel" ]] && continue
    [[ -f "$MOBILE_DIR/$rel" ]] || continue
    # The Windows drive form is written as a bracket expression rather than an
    # escaped backslash: inside single quotes the escape is ambiguous to read
    # and to grep, and this says "a literal backslash" without argument.
    if grep -Iq -e '/home/' -e '/Users/' -e 'C:[\]' "$MOBILE_DIR/$rel" 2>/dev/null; then
      bad "absolute machine path inside a committable file: $rel"
      leaked=1
    fi
  done < <({ manifest_list flutter_metadata; manifest_list android; manifest_list ios; })
  [[ "$leaked" -eq 0 ]] && ok "no committable file contains an absolute machine path"
fi

# -- G. The certification gate still builds -----------------------------------
#
# Scaffolding makes the builds possible. Nothing so far stops somebody making
# the workflow green a second way — deleting the build steps, marking them
# continue-on-error, or dropping if-no-files-found so a missing APK passes
# quietly. Each of those turns a certification into a formality, and each is a
# one-line edit. So the gate's own shape is asserted here.
#
# This is not a workflow change. M31 adds no trigger, no step and no flag; it
# only refuses to let the existing ones disappear.

head_ "G) Certification gate integrity"

# Parsed as YAML rather than grepped. `on:` is a YAML boolean in disguise —
# PyYAML reads the bare key as True — and a `paths:` filter nested under
# `pull_request` is invisible to a line-oriented search that only knows the
# word appears somewhere in the file. The whole point of section G2 is to tell
# *which* trigger a filter belongs to, so it has to understand the structure.
gate_trigger() {
  python3 -c "
import yaml,json,sys
d=yaml.safe_load(open('$CERT_WORKFLOW'))
on=d.get('on', d.get(True)) or {}
print(json.dumps({'triggers':sorted(on.keys()),
                  'pull_request':on.get('pull_request'),
                  'push':on.get('push'),
                  'has_wc':'workflow_call' in on,
                  'has_wd':'workflow_dispatch' in on,
                  'permissions':d.get('permissions')}))
" 2>/dev/null
}

if [[ -f "$CERT_WORKFLOW" ]]; then
  if grep -q 'flutter build apk --release' "$CERT_WORKFLOW"; then
    ok "the Android APK build command is still in the certification workflow"
  else
    bad "the Android APK build command is missing from the certification workflow"
  fi

  if grep -q 'flutter build ios --release --no-codesign' "$CERT_WORKFLOW"; then
    ok "the iOS build command is still in the certification workflow"
  else
    bad "the iOS build command is missing from the certification workflow"
  fi

  # A build step that may fail without failing the job is a build step that is
  # not a gate. Checked across the whole file: there is no legitimate use of it
  # in this workflow, so any occurrence is the thing being guarded against.
  if grep -q 'continue-on-error' "$CERT_WORKFLOW"; then
    bad "a step in the certification workflow is marked continue-on-error"
  else
    ok "no certification step is marked continue-on-error"
  fi

  # Without this, a build that silently produced nothing still uploads an empty
  # artifact and reports success.
  if grep -q 'if-no-files-found: error' "$CERT_WORKFLOW"; then
    ok "a missing APK artifact fails the job"
  else
    bad "the APK artifact is no longer mandatory (if-no-files-found is not error)"
  fi

  # The analyze step is what proved M30-D's Dart clean under the strict flags.
  if grep -q -- '--fatal-infos --fatal-warnings' "$CERT_WORKFLOW"; then
    ok "analyze still runs with --fatal-infos --fatal-warnings"
  else
    bad "analyze is no longer strict"
  fi
else
  bad "the certification workflow was not found at .github/workflows/"
fi

# -- G2. The gate actually runs on pull requests ------------------------------
#
# Section G proves the build steps still exist. It says nothing about whether
# they ever execute before a merge, and for twenty days they did not: this
# workflow had no `pull_request` trigger at all, so Android and iOS validated
# main only after the fact and were red the whole time without blocking a
# single merge.
#
# The unfiltered trigger is the load-bearing part. A `paths:` filter here would
# recreate the M29-A trap — GitHub treats a check that never reports as
# pending, not satisfied — so the moment these contexts became required, every
# pull request touching no mobile file would hang. That is why C and D below
# are failures rather than preferences.

head_ "G2) Pull-request trigger integrity"

if [[ -f "$CERT_WORKFLOW" ]]; then
  trig="$(gate_trigger)"

  if [[ -z "$trig" ]]; then
    bad "the certification workflow could not be parsed as YAML"
  else
    tget() { printf '%s' "$trig" | python3 -c "
import json,sys
print(json.dumps(json.load(sys.stdin).get('$1')))
"; }

    # A — pull_request exists at all.
    if [[ "$(tget pull_request)" != "null" ]] || printf '%s' "$trig" | grep -q '"pull_request"'; then
      if printf '%s' "$trig" | python3 -c "
import json,sys
sys.exit(0 if 'pull_request' in json.load(sys.stdin)['triggers'] else 1)
"; then
        ok "the workflow has a pull_request trigger"
      else
        bad "the workflow has NO pull_request trigger — it cannot gate a pull request"
      fi
    fi

    pr="$(tget pull_request)"

    # B — no paths filter under pull_request.
    if printf '%s' "$pr" | grep -q '"paths"'; then
      bad "pull_request has a paths filter — unrelated pull requests would hang if required"
    else
      ok "pull_request has no paths filter"
    fi

    # C — and no paths-ignore either, which fails the same way.
    if printf '%s' "$pr" | grep -q '"paths-ignore"'; then
      bad "pull_request has a paths-ignore filter — same deadlock, different spelling"
    else
      ok "pull_request has no paths-ignore filter"
    fi

    # D/E/F — push survives, still narrowed. Nothing waits on a post-merge run,
    # so keeping this filtered is free; losing it would double every merge's CI.
    push="$(tget push)"
    if [[ "$push" == "null" ]]; then
      bad "the push trigger was removed — main would no longer be certified after merge"
    else
      ok "the push trigger is still present"

      if printf '%s' "$push" | python3 -c "
import json,sys
b=json.load(sys.stdin).get('branches') or []
sys.exit(0 if sorted(b)==['develop','main'] else 1)
"; then
        ok "push is still restricted to main and develop"
      else
        bad "push branches are no longer exactly [main, develop]"
      fi

      if printf '%s' "$push" | python3 -c "
import json,sys
p=json.load(sys.stdin).get('paths') or []
need={'apps/mobile/**','.github/workflows/ga-flutter-certification.yml'}
sys.exit(0 if need.issubset(set(p)) else 1)
"; then
        ok "push still filters on apps/mobile and this workflow"
      else
        bad "push lost its mobile/workflow path filtering"
      fi
    fi

    # G/H — the two non-automatic entry points. workflow_call is not decorative:
    # ga-release-certification.yml consumes this workflow through it.
    if printf '%s' "$trig" | python3 -c "
import json,sys
sys.exit(0 if json.load(sys.stdin)['has_wd'] else 1)
"; then
      ok "workflow_dispatch is still available"
    else
      bad "workflow_dispatch was removed — the workflow can no longer be run manually"
    fi

    if printf '%s' "$trig" | python3 -c "
import json,sys
sys.exit(0 if json.load(sys.stdin)['has_wc'] else 1)
"; then
      ok "workflow_call is still available"
    else
      bad "workflow_call was removed — ga-release-certification.yml calls this workflow"
    fi
  fi
fi

# -- H. Nothing arrived from the stale pull request ---------------------------
#
# PR #12 carries the same platform scaffolding plus seven unrelated files —
# composer and npm lockfiles, backend env, two workflows. M31 was told to read
# it and import nothing. The scaffolding itself is covered by the manifest in
# section B; this is the check on the other seven.

head_ "H) No import from the stale pull request"

if git -C "$REPO_ROOT" rev-parse --git-dir >/dev/null 2>&1; then
  base="$(git -C "$REPO_ROOT" merge-base HEAD origin/main 2>/dev/null || echo '')"
  if [[ -n "$base" ]]; then
    changed="$(git -C "$REPO_ROOT" diff --name-only "$base"...HEAD 2>/dev/null)"

    # The allowed set, not a single prefix.
    #
    # M31 asserted "everything under apps/mobile/", which was an accurate proxy
    # for that milestone's scope and became wrong the moment M32 changed the
    # certification workflow's trigger. Widening it to the paths actually in
    # scope keeps a real guard — a backend or web file still fails here — while
    # describing the repository as it is. The named PR #12 check below is
    # untouched and remains the precise stale-import guard.
    strays="$(printf '%s\n' "$changed" \
      | grep -vE '^apps/mobile/' \
      | grep -vE '^\.github/workflows/ga-flutter-certification\.yml$' \
      | grep -vE '^docs/' \
      | grep -v '^$' || true)"
    if [[ -z "$strays" ]]; then
      ok "every changed file is within the approved scope"
    else
      bad "changes outside the approved scope:"
      while IFS= read -r s; do
        [[ -n "$s" ]] && printf '        %s\n' "$s"
      done <<< "$strays"
    fi

    # Named explicitly rather than inferred from the prefix check above, so the
    # failure message says which stale file arrived instead of merely "not
    # under apps/mobile".
    pr12_unrelated=0
    for stale in \
      "apps/api/composer.json" "apps/api/composer.lock" "apps/api/.env.example" \
      "apps/web/package.json" "apps/web/package-lock.json" \
      ".github/workflows/ga-docker-certification.yml" ".github/workflows/release.yml"
    do
      if printf '%s\n' "$changed" | grep -Fxq "$stale"; then
        bad "a file from stale PR #12 was imported: $stale"
        pr12_unrelated=1
      fi
    done
    [[ "$pr12_unrelated" -eq 0 ]] && ok "none of PR #12's seven unrelated files were imported"
  fi
fi

# -- Result -------------------------------------------------------------------

echo
echo "========================================================================"
printf 'RESULT: %d passed, %d failed\n' "$PASS" "$FAIL"
echo "========================================================================"
[[ "$FAIL" -eq 0 ]] || exit 1
