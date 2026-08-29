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

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MOBILE_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
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

# How many checks sections A–G2 must execute between them. Section H asserts
# this, so a section that short-circuits — missing tool, unreadable file, absent
# git history — fails loudly instead of vanishing from the total. Update it
# deliberately when adding or removing a check; the M36 controls verify that a
# wrong value is itself caught.
EXPECTED_CHECKS=49

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

# -- E. Analysis options ------------------------------------------------------
#
# `flutter analyze` and `flutter pub get` rewrite analysis_options.yaml
# unconditionally once platform directories exist. Nothing in normal
# development should change it, so it stays hash-pinned outright.

head_ "E) Analysis options unchanged"

f=analysis_options.yaml
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

# -- E2. Mobile dependency baseline -------------------------------------------
#
# M31 hash-pinned pubspec.yaml and pubspec.lock the same way, which was right
# for what it was guarding — `flutter create` runs an implicit resolve and
# rewrote six transitive pins the first time it ran here — and wrong as a
# permanent rule. It made every legitimate dependency bump unmergeable: five
# open Dependabot pull requests fail it today for no reason other than doing
# their job.
#
# The invariant that actually matters is not "these bytes never change". It is:
#
#     a mobile dependency change must be DELIBERATE, INTERNALLY CONSISTENT,
#     and must not be able to masquerade as accidental regeneration drift.
#
# So the baseline is still pinned, but it is refreshable — by one explicit,
# deterministic command, `scripts/refresh_mobile_dependency_baseline.sh`, whose
# output lands in the same commit as the dependency change and is reviewed with
# it. Refreshing hashes alone buys nothing: E2c–E2e re-derive the dependency
# sets from the files themselves and compare them in both directions, and they
# run whether or not the hashes match. A hand-edited hash with an inconsistent
# lockfile still fails.
#
# Accidental drift still fails, which was the original point: a stray
# `flutter pub get` rewrites pubspec.lock without touching pubspec.yaml and
# without refreshing the manifest, so E2b fails immediately.

head_ "E2) Mobile dependency baseline is deliberate and consistent"

dep_report="$(PYTHONDONTWRITEBYTECODE=1 python3 - "$MANIFEST" "$MOBILE_DIR" "$SCRIPT_DIR" <<'PY'
import json, os, sys

manifest_path, mobile, script_dir = sys.argv[1], sys.argv[2], sys.argv[3]
sys.path.insert(0, script_dir)

try:
    from mobile_dependency_lib import (
        lock_direct_versions, sdk_provided, sha256_of, yaml_direct_deps,
    )
except ImportError as exc:
    print('ERROR=mobile_dependency_lib.py is missing or unreadable (%s)' % exc)
    raise SystemExit(0)

man = json.load(open(manifest_path))
base = man.get('dependency_baseline')

if base is None:
    print('ERROR=the manifest has no dependency_baseline section')
    raise SystemExit(0)

yaml_path = os.path.join(mobile, 'pubspec.yaml')
lock_path = os.path.join(mobile, 'pubspec.lock')

for p in (yaml_path, lock_path):
    if not os.path.isfile(p):
        print('ERROR=%s is missing' % os.path.basename(p))
        raise SystemExit(0)

print('YAML_SHA=%s' % sha256_of(yaml_path))
print('LOCK_SHA=%s' % sha256_of(lock_path))
print('YAML_SHA_WANT=%s' % base.get('pubspec_yaml_sha256', ''))
print('LOCK_SHA_WANT=%s' % base.get('pubspec_lock_sha256', ''))

deps, dev = yaml_direct_deps(yaml_path)
locked = lock_direct_versions(lock_path)

declared = set(deps) | set(dev)
comparable = declared - sdk_provided(deps, dev)

print('MISSING_FROM_LOCK=%s' % ','.join(sorted(comparable - set(locked))))
print('MISSING_FROM_YAML=%s' % ','.join(sorted(set(locked) - declared)))

recorded = {**(base.get('direct_dependencies') or {}),
            **(base.get('direct_dev_dependencies') or {})}
actual = {**deps, **dev}
print('MANIFEST_MATCHES_YAML=%s' % ('1' if recorded == actual else '0'))
if recorded != actual:
    print('MANIFEST_DELTA=only_in_manifest:%s|only_in_pubspec:%s|constraint_changed:%s' % (
        ','.join(sorted(set(recorded) - set(actual))),
        ','.join(sorted(set(actual) - set(recorded))),
        ','.join(sorted(k for k in set(recorded) & set(actual) if recorded[k] != actual[k])),
    ))
PY
)"

dep_field() { printf '%s' "$dep_report" | sed -n "s/^$1=//p"; }

if [[ -n "$(dep_field ERROR)" ]]; then
  bad "the dependency baseline could not be read: $(dep_field ERROR)"
  bad "pubspec.yaml matches the declared dependency baseline"
  bad "pubspec.lock matches the declared dependency baseline"
  bad "every direct dependency in pubspec.yaml is present in pubspec.lock"
  bad "every direct package in pubspec.lock is declared in pubspec.yaml"
  bad "the manifest's recorded dependency set matches pubspec.yaml"
else
  # E2a/E2b — the baseline is pinned, and only the refresh command moves it.
  if [[ "$(dep_field YAML_SHA)" == "$(dep_field YAML_SHA_WANT)" ]]; then
    ok "pubspec.yaml matches the declared dependency baseline"
  else
    bad "pubspec.yaml changed without a baseline refresh (run scripts/refresh_mobile_dependency_baseline.sh in the same commit)"
  fi

  if [[ "$(dep_field LOCK_SHA)" == "$(dep_field LOCK_SHA_WANT)" ]]; then
    ok "pubspec.lock matches the declared dependency baseline"
  else
    bad "pubspec.lock changed without a baseline refresh — this is what accidental regeneration drift looks like"
  fi

  # E2c/E2d — consistency, checked in both directions and ALWAYS, so that
  # refreshing the hashes cannot by itself make an incoherent pair pass.
  if [[ -z "$(dep_field MISSING_FROM_LOCK)" ]]; then
    ok "every direct dependency in pubspec.yaml is present in pubspec.lock"
  else
    bad "declared in pubspec.yaml but absent from pubspec.lock: $(dep_field MISSING_FROM_LOCK)"
  fi

  if [[ -z "$(dep_field MISSING_FROM_YAML)" ]]; then
    ok "every direct package in pubspec.lock is declared in pubspec.yaml"
  else
    bad "locked as a direct dependency but not declared in pubspec.yaml: $(dep_field MISSING_FROM_YAML)"
  fi

  # E2e — the anti-rubber-stamp check. The manifest records the dependency
  # names and constraints, not only opaque hashes, so a reviewer sees what
  # changed and an edit that touches only the hashes fails here.
  if [[ "$(dep_field MANIFEST_MATCHES_YAML)" == "1" ]]; then
    ok "the manifest's recorded dependency set matches pubspec.yaml"
  else
    bad "the manifest's recorded dependencies disagree with pubspec.yaml — $(dep_field MANIFEST_DELTA)"
  fi
fi

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

# -- G3. The aggregator that branch protection actually requires --------------
#
# M32 made the platform jobs run on every pull request. M33 makes one context
# requirable. The distinction the ruleset cares about is not "did Android and
# iOS run" but "is there exactly one stable name that is green only when both
# of them are".
#
# Two failure modes are guarded here, and they pull in opposite directions.
#
#   Bare `needs:` SKIPS the aggregator when a dependency fails. GitHub reports a
#   skipped required check as PENDING, never as failed — so the gate would hang
#   every pull request whose Android job went red, which is the exact case it
#   exists to catch. Hence `if: always()`.
#
#   `always()` on its own is the mirror image: a job that reports success while
#   both platforms burn. Hence the explicit result comparison.
#
# Either one alone is a false-green gate. Both are asserted.

head_ "G3) Mobile Certification aggregator integrity"

# Read as YAML for the same reason section G2 does: job names, `needs:` and
# `if:` are structure, and a line-oriented grep cannot tell which job it is
# looking at.
gate_jobs() {
  python3 -c "
import yaml, json
d = yaml.safe_load(open('$CERT_WORKFLOW'))
jobs = d.get('jobs') or {}

agg_key, agg = None, None
for key, job in jobs.items():
    if (job or {}).get('name') == 'Mobile Certification':
        agg_key, agg = key, (job or {})
        break

# The aggregator's decision lives in its step bodies AND its env block: the
# results are mapped in via env: rather than interpolated into the script, so
# both halves have to be searched for 'needs.<job>.result'.
run = ''
if agg is not None:
    parts = []
    for step in (agg.get('steps') or []):
        step = step or {}
        parts.append(str(step.get('run', '')))
        parts.append(json.dumps(step.get('env') or {}))
    run = '\n'.join(parts)

print(json.dumps({
    'names': [(j or {}).get('name') for j in jobs.values()],
    'agg_key': agg_key,
    'needs': (agg.get('needs') if agg is not None else None),
    'if': (str(agg.get('if')) if agg is not None and 'if' in agg else None),
    'run': run,
}))
" 2>/dev/null
}

if [[ -f "$CERT_WORKFLOW" ]]; then
  jobs_json="$(gate_jobs)"

  if [[ -z "$jobs_json" ]]; then
    bad "the certification workflow jobs could not be parsed as YAML"
  else
    jget() { printf '%s' "$jobs_json" | python3 -c "
import json,sys
print(json.dumps(json.load(sys.stdin).get('$1')))
"; }

    # A — the two supporting platform jobs still exist, by exact name. They are
    # NOT required contexts, but the aggregator is worthless without them.
    if printf '%s' "$jobs_json" | python3 -c "
import json,sys
sys.exit(0 if 'Android · doctor · analyze · test · build apk' in json.load(sys.stdin)['names'] else 1)
"; then
      ok "the Android certification job still exists under its exact name"
    else
      bad "the Android certification job is missing or was renamed"
    fi

    if printf '%s' "$jobs_json" | python3 -c "
import json,sys
sys.exit(0 if 'iOS · analyze · test · build (no codesign)' in json.load(sys.stdin)['names'] else 1)
"; then
      ok "the iOS certification job still exists under its exact name"
    else
      bad "the iOS certification job is missing or was renamed"
    fi

    # B — the aggregator exists under the exact required context name. Compared
    # literally: a context differing by one character never reports, and a
    # required check that never reports blocks every pull request forever.
    if [[ "$(jget agg_key)" == "null" ]]; then
      bad "no job is named 'Mobile Certification' — the required context cannot report"
    else
      ok "a job named exactly 'Mobile Certification' exists"

      # C/D — it must wait for both platforms. One missing dependency makes the
      # gate green while that platform is untested.
      if printf '%s' "$jobs_json" | python3 -c "
import json,sys
sys.exit(0 if 'android' in (json.load(sys.stdin).get('needs') or []) else 1)
"; then
        ok "the aggregator needs the android job"
      else
        bad "the aggregator does not need the android job — Android could fail unnoticed"
      fi

      if printf '%s' "$jobs_json" | python3 -c "
import json,sys
sys.exit(0 if 'ios' in (json.load(sys.stdin).get('needs') or []) else 1)
"; then
        ok "the aggregator needs the ios job"
      else
        bad "the aggregator does not need the ios job — iOS could fail unnoticed"
      fi

      # E — without always(), a failed dependency SKIPS this job, and a skipped
      # required check reports no conclusion at all.
      if printf '%s' "$jobs_json" | python3 -c "
import json,sys
v=(json.load(sys.stdin).get('if') or '').replace(' ','')
sys.exit(0 if v in ('\${{always()}}','always()') else 1)
"; then
        ok "the aggregator runs with if: always()"
      else
        bad "the aggregator lacks if: always() — a failed platform would skip it, not fail it"
      fi

      agg_run="$(printf '%s' "$jobs_json" | python3 -c "
import json,sys
print(json.load(sys.stdin)['run'])
")"

      # F/G — it must actually read both results. `always()` without this is a
      # gate that is green no matter what happened.
      if printf '%s' "$agg_run" | grep -q 'needs.android.result'; then
        ok "the aggregator inspects needs.android.result"
      else
        bad "the aggregator never reads the Android result — always() would make it always green"
      fi

      if printf '%s' "$agg_run" | grep -q 'needs.ios.result'; then
        ok "the aggregator inspects needs.ios.result"
      else
        bad "the aggregator never reads the iOS result — always() would make it always green"
      fi

      # H — and the comparison must be success-only. Anything that accepts a
      # set of "acceptable" results lets cancelled or skipped through.
      if printf '%s' "$agg_run" | grep -q 'exit 1'; then
        ok "the aggregator can fail (it contains a non-zero exit)"
      else
        bad "the aggregator has no failing path — it is a false-green gate"
      fi

      if printf '%s' "$agg_run" | python3 -c "
import sys, re
run = sys.stdin.read()

# Collect every literal either result is compared against. Asserting only that
# a '!= \"success\"' comparison EXISTS is not enough — it survives
#
#     [ \"\\\${ANDROID_RESULT}\" != \"success\" && \"\\\${ANDROID_RESULT}\" != \"failure\" ]
#
# which is exactly the escape hatch somebody adds when a flaky runner is
# annoying them. So the accepted set has to be exactly {'success'}: any second
# verdict, whatever it is, fails this check.
compared = set(re.findall(
    r'(?:ANDROID_RESULT|IOS_RESULT)\}?\"?\s*[!=]=\s*\"([^\"]*)\"', run))

android_ok = re.search(r'ANDROID_RESULT[^\n]*[!=]=[^\n]*\"success\"', run) is not None
ios_ok     = re.search(r'IOS_RESULT[^\n]*[!=]=[^\n]*\"success\"', run) is not None

# '||' because the guard is a disjunction: either result being wrong must fail.
sys.exit(0 if (android_ok and ios_ok and '||' in run and compared == {'success'}) else 1)
"; then
        ok "the aggregator fails unless BOTH results are exactly 'success'"
      else
        bad "the aggregator's success condition was weakened — non-success results could pass"
      fi
    fi
  fi
fi

# -- G4. The required context is the aggregator, and only the aggregator ------
#
# The repository-side half of the ruleset change. `verify_repository_governance`
# owns the cross-file agreement; this asserts the mobile-specific rule that the
# platform jobs must never become required contexts themselves.
#
# The naming trap this guards is real and already latent in the repository:
# `ci-mobile.yml` is the workflow "CI · Mobile (Flutter)" whose job is named
# "Analyse · Test", which is a different thing again from either certification
# job. Exact string matching only.

head_ "G4) Required-context wiring"

CHECKS_JSON="$REPO_ROOT/.github/governance/required-checks.json"

if [[ -f "$CHECKS_JSON" ]]; then
  if python3 -c "
import json,sys
d=json.load(open('$CHECKS_JSON'))
sys.exit(0 if 'Mobile Certification' in [c.get('context') for c in d.get('required',[])] else 1)
"; then
    ok "'Mobile Certification' is declared a required context"
  else
    bad "'Mobile Certification' is NOT in required-checks.json — the gate is not required"
  fi

  if python3 -c "
import json,sys
d=json.load(open('$CHECKS_JSON'))
ctx=[c.get('context') for c in d.get('required',[])]
forbidden={'Android · doctor · analyze · test · build apk',
           'iOS · analyze · test · build (no codesign)',
           'Analyse · Test'}
sys.exit(1 if forbidden & set(ctx) else 0)
"; then
    ok "the platform jobs are not individually required"
  else
    bad "a platform job was made an individually required context — rename risk reintroduced"
  fi
else
  bad "required-checks.json was not found"
fi

# -- H. Every declared check actually ran -------------------------------------
#
# What used to be here was M31's stale-import guard: "every changed file is
# under apps/mobile/ (plus two later additions), and none of PR #12's seven
# unrelated files arrived". Two things were wrong with it, and both were proved
# rather than suspected (M36 Phase 2).
#
# 1. It was MILESTONE SCOPE MASQUERADING AS A REPOSITORY INVARIANT. Simulated
#    against the real file list of every open pull request, it failed 13 of 18
#    — including every Dependabot workflow bump, every backend or web change,
#    and PR #34. `release.yml` and `ga-docker-certification.yml` were on its
#    forbidden list purely because they happened to appear in one old pull
#    request; M35 legitimately changed one of them. As a required check it
#    would have wedged the repository. It is gone, and it is not coming back in
#    another spelling: the mobile-platform drift it was loosely standing in for
#    is covered precisely by A (no fourth platform), B (tree matches the
#    manifest), C (identity), D (branding), E/E2 (analysis options and
#    dependency baseline) and F (no secret is committable).
#
# 2. It COULD SILENTLY DISAPPEAR. The whole block hung on
#    `merge-base HEAD origin/main`, and on failure took the `else` nobody
#    wrote — no `origin/main` ref under a shallow `actions/checkout` (default
#    fetch-depth: 1), no comparison, two checks quietly not run, and the script
#    still exiting 0 with "34 passed, 0 failed". A required validator reporting
#    success for checks it never executed is worse than no validator.
#
# So H is now the guard against that class of defect rather than an instance of
# it: the validator declares up front how many checks it must execute, and this
# asserts the count. Any future section that short-circuits — on missing git
# history, a missing tool, an unreadable file — reduces the count and fails
# here, loudly, by name. It depends on nothing outside this process.

head_ "H) Enforcement integrity"

executed=$((PASS + FAIL))
if [[ "$executed" -eq "$EXPECTED_CHECKS" ]]; then
  ok "all $EXPECTED_CHECKS declared checks executed"
else
  bad "only $executed of $EXPECTED_CHECKS declared checks executed — a section skipped silently"
fi

# -- Result -------------------------------------------------------------------

echo
echo "========================================================================"
printf 'RESULT: %d passed, %d failed\n' "$PASS" "$FAIL"
echo "========================================================================"
[[ "$FAIL" -eq 0 ]] || exit 1
