#!/usr/bin/env bash
# =============================================================================
# M36 — refresh the approved mobile dependency baseline.
#
# WHEN YOU ARE ALLOWED TO RUN THIS
#
# Exactly one situation: you are DELIBERATELY changing apps/mobile/pubspec.yaml
# and apps/mobile/pubspec.lock — a dependency bump, an addition, a removal —
# and you are committing the refreshed manifest IN THE SAME COMMIT so a reviewer
# sees the dependency change and the baseline move together.
#
# WHEN YOU MUST NOT RUN IT
#
#   * To make a failing `verify_platform_foundation.sh` go green. If section E2
#     is failing and you did not intend to change a dependency, something
#     rewrote the lockfile behind you — a stray `flutter pub get`, an implicit
#     resolve from `flutter create`. That is the drift the check exists to
#     catch. Find out what wrote it; do not stamp it as approved.
#   * In CI. This script refuses to run when CI is set, because a baseline that
#     refreshes itself automatically is not a baseline.
#
# WHAT IT DOES, AND ONLY THIS
#
# Recomputes `dependency_baseline` in m31-platform-manifest.json from the files
# already on disk: the two sha256 hashes, and the direct dependency names and
# constraints read out of pubspec.yaml. It does not run `flutter pub get`, does
# not resolve anything, does not touch the network, and does not modify any
# other part of the manifest. Deterministic: running it twice on the same tree
# produces the same bytes.
#
# It does not make the change trustworthy. Recording the names and constraints
# rather than opaque hashes is precisely so a human reviewing the diff can see
# `get_it: ^8.3.0 -> ^9.2.1` and decide. The validator's consistency checks run
# regardless of the hashes and will still reject an incoherent pair.
#
# Usage:  apps/mobile/scripts/refresh_mobile_dependency_baseline.sh [--check]
#
#   --check   report what would change and exit 1 if anything would; write
#             nothing. Safe to run anywhere, including CI.
# =============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MOBILE_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
MANIFEST="$MOBILE_DIR/m31-platform-manifest.json"

MODE="write"
if [[ "${1:-}" == "--check" ]]; then
    MODE="check"
elif [[ $# -gt 0 ]]; then
    echo "usage: $(basename "$0") [--check]" >&2
    exit 2
fi

if [[ "$MODE" == "write" && -n "${CI:-}" ]]; then
    echo "REFUSED: \$CI is set." >&2
    echo "  This command records a human decision about dependencies. Running it" >&2
    echo "  in CI would let the baseline approve its own drift. Run it locally," >&2
    echo "  in the same commit as the dependency change." >&2
    echo "  Use --check for a read-only comparison." >&2
    exit 2
fi

for f in "$MANIFEST" "$MOBILE_DIR/pubspec.yaml" "$MOBILE_DIR/pubspec.lock"; do
    [[ -f "$f" ]] || { echo "FATAL: $f is missing." >&2; exit 1; }
done

PYTHONDONTWRITEBYTECODE=1 python3 - "$MANIFEST" "$MOBILE_DIR" "$SCRIPT_DIR" "$MODE" <<'PY'
import json
import os
import sys

manifest_path, mobile, script_dir, mode = sys.argv[1:5]
sys.path.insert(0, script_dir)

from mobile_dependency_lib import (  # noqa: E402
    lock_direct_versions, sdk_provided, sha256_of, yaml_direct_deps,
)

yaml_path = os.path.join(mobile, 'pubspec.yaml')
lock_path = os.path.join(mobile, 'pubspec.lock')

deps, dev = yaml_direct_deps(yaml_path)
locked = lock_direct_versions(lock_path)

# Refuse to record an incoherent pair. The validator would reject it anyway;
# failing here means the person doing the bump finds out now rather than in CI,
# and the manifest never contains a state that cannot pass.
declared = set(deps) | set(dev)
comparable = declared - sdk_provided(deps, dev)
missing_lock = sorted(comparable - set(locked))
missing_yaml = sorted(set(locked) - declared)

if missing_lock or missing_yaml:
    print('REFUSED: pubspec.yaml and pubspec.lock disagree.', file=sys.stderr)
    if missing_lock:
        print('  declared but not locked : %s' % ', '.join(missing_lock), file=sys.stderr)
    if missing_yaml:
        print('  locked but not declared : %s' % ', '.join(missing_yaml), file=sys.stderr)
    print('  Run `flutter pub get` so the lockfile matches, then re-run this.', file=sys.stderr)
    raise SystemExit(1)

new_baseline = {
    "_note": (
        "Refreshed only by apps/mobile/scripts/refresh_mobile_dependency_baseline.sh, "
        "in the same commit as the dependency change it describes. The names and "
        "constraints are recorded rather than hashes alone so a reviewer can see what "
        "moved; verify_platform_foundation.sh section E2 re-derives them from the files "
        "and rejects an inconsistent pair whether or not the hashes were updated."
    ),
    "pubspec_yaml_sha256": sha256_of(yaml_path),
    "pubspec_lock_sha256": sha256_of(lock_path),
    "direct_dependencies": dict(sorted(deps.items())),
    "direct_dev_dependencies": dict(sorted(dev.items())),
    "locked_direct_versions": dict(sorted(locked.items())),
}

man = json.load(open(manifest_path))
old = man.get('dependency_baseline')

if old == new_baseline:
    print('Baseline already up to date — nothing to change.')
    raise SystemExit(0)

# Report the delta in the terms a reviewer cares about.
def summarise(o, n, label):
    o = o or {}
    n = n or {}
    for k in sorted(set(o) | set(n)):
        if o.get(k) != n.get(k):
            print('  %-22s %-22s %s -> %s' % (label, k, o.get(k, '(absent)'), n.get(k, '(absent)')))

print('dependency_baseline changes:')
if old is None:
    print('  (creating the section for the first time)')
else:
    for key in ('pubspec_yaml_sha256', 'pubspec_lock_sha256'):
        if old.get(key) != new_baseline[key]:
            print('  %-22s %s… -> %s…' % (key, str(old.get(key))[:12], new_baseline[key][:12]))
    summarise(old.get('direct_dependencies'), new_baseline['direct_dependencies'], 'dependency')
    summarise(old.get('direct_dev_dependencies'), new_baseline['direct_dev_dependencies'], 'dev dependency')
    summarise(old.get('locked_direct_versions'), new_baseline['locked_direct_versions'], 'locked version')

if mode == 'check':
    print('\n--check: the baseline is STALE. Nothing was written.')
    raise SystemExit(1)

man['dependency_baseline'] = new_baseline
with open(manifest_path, 'w') as fh:
    json.dump(man, fh, indent=2, ensure_ascii=False)
    fh.write('\n')

print('\nWrote %s' % os.path.relpath(manifest_path, os.path.dirname(mobile)))
print('Commit it together with pubspec.yaml and pubspec.lock.')
PY
