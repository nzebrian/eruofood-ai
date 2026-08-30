#!/usr/bin/env bash
#
# M43 — does SearchCandidateRecallTest actually detect the recall defect?
#
# ## The property under test
#
# `EloquentSearchIndexRepository::search()` counts the match set with one query
# and fetches the rows to rank with another. Before this fix the fetch was
# ordered by `embedding_vec <=> …`, which — where pgvector is installed — is
# served by an approximate ivfflat index that probes one list out of `lists`.
# Applied on top of a selective `WHERE`, that scan returned NO rows for a query
# whose count said 1. Search answered "nothing found" for a dish in the
# catalogue.
#
# Two independent locks now prevent it:
#
#   1. approximate ordering is used only when `total > window` — i.e. only when
#      it is actually deciding which of too many matches to score; and
#   2. an empty candidate set with a positive total re-fetches exactly.
#
# They are deliberately redundant, so removing EITHER alone is caught by the
# other. That is why the mutation below removes BOTH: it reconstructs the
# original defect exactly, and requires the regression tests to fail on it.
#
# ## Why this one mutates in place and restores
#
# `apps/api` carries a 4.7 GB `vendor/`, so the M42 pattern of copying the tree
# per control is not affordable here; this follows the older in-repo precedent
# of `m27_negative_control_audit.php`. The pristine bytes are copied to a
# `mktemp` file first and restored from it, the restore runs from an EXIT trap
# so it survives an error or a SIGTERM as well as a normal return, and the file
# is sha256-verified afterwards. The run fails if a single byte differs.
#
# ## Where it runs
#
# The defect is only reachable on PostgreSQL WITH pgvector — that is the whole
# reason it survived so long. On any other database the controls cannot
# discriminate, so the script says so and skips rather than reporting a pass it
# has not earned.
#
# Usage: DB_CONNECTION=pgsql … bash apps/api/scripts/m43_search_recall_control.sh

set -euo pipefail

API_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$API_ROOT"

TARGET="modules/Search/src/Infrastructure/Persistence/Eloquent/EloquentSearchIndexRepository.php"
SUITE="modules/Search/tests/Feature/SearchCandidateRecallTest.php"

if [[ "${DB_CONNECTION:-}" != "pgsql" ]]; then
  echo "M43 search recall controls: SKIPPED — needs DB_CONNECTION=pgsql with pgvector."
  echo "The defect exists only on the vector-enabled path; anywhere else these"
  echo "controls would report a pass they did not earn."
  exit 0
fi

# shellcheck disable=SC2016  # the PHP snippet's $vars must reach PHP, not bash
if ! php -r '
$dsn = "pgsql:host=".(getenv("DB_HOST") ?: "127.0.0.1").";dbname=".getenv("DB_DATABASE");
try { $p = new PDO($dsn, getenv("DB_USERNAME") ?: null, getenv("DB_PASSWORD") ?: null); }
catch (Throwable $e) { exit(1); }
exit((int) $p->query("select count(*) from pg_available_extensions where name = \x27vector\x27")->fetchColumn() > 0 ? 0 : 1);
' 2>/dev/null; then
  echo "M43 search recall controls: SKIPPED — pgvector is not available on this database."
  exit 0
fi

BACKUP="$(mktemp "${TMPDIR:-/tmp}/m43-search-pristine-XXXXXXXX.php")"
cp "$TARGET" "$BACKUP"
BEFORE="$(sha256sum "$TARGET" | cut -d' ' -f1)"

# Restore on ANY exit — normal, error, or signal. `finally` semantics that a
# fatal error cannot skip is the entire point; the M37 audit was written about
# what happens when a control dies mid-mutation.
restore() { cp "$BACKUP" "$TARGET"; }
trap 'restore; rm -f "$BACKUP"' EXIT
trap 'exit 1' INT TERM

mutate() {
  BACKUP="$BACKUP" TARGET="$TARGET" python3 - <<'PY'
import os, sys

path = os.environ['TARGET']
with open(path, encoding='utf-8') as handle:
    source = handle.read()

edits = [
    # Lock 1 removed: approximate ordering applied unconditionally, as before.
    ('$approximateOrderingHelps = $total > $window;',
     '$approximateOrderingHelps = true;'),
    # Lock 2 removed: no exact re-fetch when the approximate scan comes back empty.
    ('        if ($rows === [] && $total > 0) {\n'
     '            $rows = $this->fetchCandidates($predicate(), $window, null);\n'
     '        }',
     '        if (false) {\n'
     '            $rows = $this->fetchCandidates($predicate(), $window, null);\n'
     '        }'),
]

for find, replace in edits:
    if source.count(find) != 1:
        sys.exit(f"mutation target not found exactly once: {find[:70]!r}")
    source = source.replace(find, replace, 1)

with open(path, 'w', encoding='utf-8') as handle:
    handle.write(source)
PY
}

# A test that must go red once the locks are gone.
expect_failure() {
  local label="$1" test_name="$2"
  printf '%-64s' "${label:0:64}"

  local output
  output="$(./vendor/bin/pest "$SUITE" --filter="$test_name" 2>&1 || true)"

  if grep -Fq "$test_name" <<<"$output" && grep -q '✓' <<<"$output"; then
    echo " FALSE POSITIVE"
    return 1
  fi

  if ! grep -q '⨯\|FAILED' <<<"$output"; then
    echo " INCONCLUSIVE"
    printf '%s\n' "$output" | tail -12
    return 1
  fi

  echo " ok"
}

echo "EruoFood — M43 search candidate-recall negative controls"
echo "=============================================================================="
echo "Both recall locks are removed, reconstructing the original defect; the"
echo "regression tests must then fail."
echo
echo "Pristine fingerprint (before): $BEFORE"
echo

failures=0

printf '%-64s' "0. positive control: the untouched repository passes"
if ./vendor/bin/pest "$SUITE" >/dev/null 2>&1; then
  echo " ok"
else
  echo " FAILED"
  failures=$((failures + 1))
fi

mutate

expect_failure "1. both recall locks removed — the needle is lost" \
  "it returns the one matching document rather than an empty page" || failures=$((failures + 1))

expect_failure "2. both recall locks removed — total and page disagree" \
  "it never reports a total it cannot show a first page for" || failures=$((failures + 1))

restore

printf '%-64s' "3. sha256 integrity: the source is byte-identical again"
AFTER="$(sha256sum "$TARGET" | cut -d' ' -f1)"
if [[ "$BEFORE" == "$AFTER" ]]; then
  echo " ok"
else
  echo " FAILED"
  failures=$((failures + 1))
fi

printf '%-64s' "4. the restored repository still passes"
if ./vendor/bin/pest "$SUITE" >/dev/null 2>&1; then
  echo " ok"
else
  echo " FAILED"
  failures=$((failures + 1))
fi

echo
echo "Pristine fingerprint (after):  $AFTER"
echo
echo "=============================================================================="

if [[ "$failures" -eq 0 ]]; then
  echo "The recall regression tests detect the defect, and the source is untouched."
  exit 0
fi

echo "M43 search recall negative controls FAILED ($failures problem(s))."
exit 1
