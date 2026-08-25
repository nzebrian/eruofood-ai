#!/usr/bin/env bash
# =============================================================================
# M35 — negative controls for the GA Docker Certification guard
#
# The guard currently passes. That is precisely the state in which a vacuous
# check is invisible: it passes because the thing it protects is intact, and it
# would pass just as happily if it protected nothing at all.
#
# So this breaks each invariant in turn and REQUIRES the guard to fail. A
# control that does not produce a failure is reported as a FALSE NEGATIVE.
#
# Two controls exist to stop the suite fooling itself:
#   * the POSITIVE control (10) — an untouched fixture must still PASS, so a
#     guard that rejects everything cannot masquerade as one that works;
#   * the FALSE-POSITIVE control (9) — a legitimate host-level `vendor/bin/pest`
#     (ci-api.yml, release.yml) must NOT be flagged. Without it, a guard that
#     banned Pest outright would score full marks while breaking the required
#     test gate.
#
# Every fixture is a throwaway copy under `mktemp -d`. The real working tree is
# never modified, and control 11 proves it with a sha256 fingerprint taken
# before and after the whole run.
#
# Usage:  .github/scripts/m35_docker_certification_negative_control.sh
# =============================================================================

set -uo pipefail

REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
GUARD="$REPO_ROOT/.github/scripts/m35_docker_certification_guard.sh"

if [[ ! -x "$GUARD" && ! -f "$GUARD" ]]; then
    echo "FATAL: guard not found at $GUARD" >&2
    exit 1
fi

if [[ -t 1 ]]; then
    GREEN=$'\033[32m'; RED=$'\033[31m'; DIM=$'\033[2m'; OFF=$'\033[0m'
else
    GREEN=''; RED=''; DIM=''; OFF=''
fi

passed=0
false_negatives=0

# Files the guard reads. Anything outside this set cannot affect its verdict.
FIXTURE_PATHS=(
    ".github/workflows"
    "docker-compose.yml"
    "docker-compose.ci.yml"
    "docker-compose.override.yml"
    "apps/api/phpunit.xml"
)

make_fixture() {
    local dir
    dir="$(mktemp -d)"
    local p
    for p in "${FIXTURE_PATHS[@]}"; do
        if [[ -e "$REPO_ROOT/$p" ]]; then
            mkdir -p "$dir/$(dirname "$p")"
            cp -R "$REPO_ROOT/$p" "$dir/$p"
        fi
    done
    printf '%s' "$dir"
}

# control NAME MUTATOR   -- the guard MUST fail after the mutation
control() {
    local name="$1" mutate="$2"
    local dir out exit_code
    dir="$(make_fixture)"

    ( cd "$dir" && eval "$mutate" ) >/dev/null 2>&1

    out="$(bash "$GUARD" "$dir" 2>&1)"
    exit_code=$?

    if (( exit_code != 0 )); then
        passed=$((passed + 1))
        printf '  %sPASS%s  %s\n' "$GREEN" "$OFF" "$name"
        printf '%s' "$out" | grep '  FAIL  ' | head -2 | while IFS= read -r l; do
            printf '        %s%s%s\n' "$DIM" "$(printf '%s' "$l" | sed 's/^ *//')" "$OFF"
        done
    else
        false_negatives=$((false_negatives + 1))
        printf '  %sFAIL%s  %s — FALSE NEGATIVE (guard still passed)\n' "$RED" "$OFF" "$name"
    fi

    rm -rf "$dir"
}

# expect_pass NAME MUTATOR -- the guard MUST still pass after the mutation
expect_pass() {
    local name="$1" mutate="$2"
    local dir out exit_code
    dir="$(make_fixture)"

    ( cd "$dir" && eval "$mutate" ) >/dev/null 2>&1

    out="$(bash "$GUARD" "$dir" 2>&1)"
    exit_code=$?

    if (( exit_code == 0 )); then
        passed=$((passed + 1))
        printf '  %sPASS%s  %s\n' "$GREEN" "$OFF" "$name"
    else
        false_negatives=$((false_negatives + 1))
        printf '  %sFAIL%s  %s — the guard rejected a legitimate configuration\n' "$RED" "$OFF" "$name"
        printf '%s' "$out" | grep '  FAIL  ' | head -3 | while IFS= read -r l; do
            printf '        %s%s%s\n' "$DIM" "$(printf '%s' "$l" | sed 's/^ *//')" "$OFF"
        done
    fi

    rm -rf "$dir"
}

fingerprint() {
    local p
    for p in "${FIXTURE_PATHS[@]}"; do
        if [[ -d "$REPO_ROOT/$p" ]]; then
            find "$REPO_ROOT/$p" -type f -print0 | sort -z | xargs -0 sha256sum
        elif [[ -f "$REPO_ROOT/$p" ]]; then
            sha256sum "$REPO_ROOT/$p"
        fi
    done | sha256sum | cut -d' ' -f1
}

before="$(fingerprint)"

printf '%s\n' "========================================================================"
printf '%s\n' "M35 — GA DOCKER CERTIFICATION NEGATIVE CONTROLS"
printf '  fixtures: throwaway copies under mktemp; the working tree is untouched\n'
printf '%s\n' "========================================================================"
printf '\n'

# -- Invariant 1: no test runner inside a production-environment container ----

control '1 · Pest re-added to the production container (COMPOSE_FILE form) is detected' '
python3 - <<"PY"
p = ".github/workflows/ga-docker-certification.yml"
s = open(p).read()
s = s.replace(
    "      - name: Redis integration",
    "      - name: Install PHP dependencies\n"
    "        run: docker compose exec -T api composer install --no-interaction\n"
    "\n"
    "      - name: Backend runtime tests (inside the container, on PostgreSQL)\n"
    "        run: docker compose exec -T api vendor/bin/pest --no-coverage\n"
    "\n"
    "      - name: Redis integration",
    1,
)
open(p, "w").write(s)
PY
'

control '2 · the same pattern written with explicit -f flags is detected' '
python3 - <<"PY"
p = ".github/workflows/ga-docker-certification.yml"
s = open(p).read()
s = s.replace(
    "      - name: Redis integration",
    "      - name: Backend runtime tests\n"
    "        run: docker compose -f docker-compose.yml -f docker-compose.ci.yml exec -T api vendor/bin/pest\n"
    "\n"
    "      - name: Redis integration",
    1,
)
open(p, "w").write(s)
PY
'

control '3 · phpunit invoked in the container instead of pest is detected' '
python3 - <<"PY"
p = ".github/workflows/ga-docker-certification.yml"
s = open(p).read()
s = s.replace(
    "      - name: Redis integration",
    "      - name: Backend runtime tests\n"
    "        run: docker compose exec -T api vendor/bin/phpunit\n"
    "\n"
    "      - name: Redis integration",
    1,
)
open(p, "w").write(s)
PY
'

control '4 · an artisan test run in the production container is detected' '
python3 - <<"PY"
p = ".github/workflows/ga-docker-certification.yml"
s = open(p).read()
s = s.replace(
    "      - name: Redis integration",
    "      - name: Backend runtime tests\n"
    "        run: docker compose exec -T api php artisan test\n"
    "\n"
    "      - name: Redis integration",
    1,
)
open(p, "w").write(s)
PY
'

# -- Invariant 2: phpunit.xml must not force the CI-supplied selectors --------

control '5 · force="true" on DB_CONNECTION is detected' '
sed -i "s|<env name=\"DB_CONNECTION\" value=\"sqlite\"/>|<env name=\"DB_CONNECTION\" value=\"sqlite\" force=\"true\"/>|" apps/api/phpunit.xml
'

control '6 · force="true" on APP_ENV is detected' '
sed -i "s|<env name=\"APP_ENV\" value=\"testing\"/>|<env name=\"APP_ENV\" value=\"testing\" force=\"true\"/>|" apps/api/phpunit.xml
'

control '7 · force="true" on CACHE_STORE is detected' '
sed -i "s|<env name=\"CACHE_STORE\" value=\"array\"/>|<env name=\"CACHE_STORE\" value=\"array\" force=\"true\"/>|" apps/api/phpunit.xml
'

# -- Section C: the invariant-2 dependency must stay real ---------------------

control '8 · the required gate losing DB_CONNECTION=pgsql is detected' '
sed -i "s|DB_CONNECTION: pgsql|DB_CONNECTION: sqlite|" .github/workflows/ci-api.yml
'

control '8b · the required gate gaining APP_ENV is detected' '
python3 - <<"PY"
p = ".github/workflows/ci-api.yml"
s = open(p).read()
s = s.replace("      DB_CONNECTION: pgsql", "      APP_ENV: production\n      DB_CONNECTION: pgsql", 1)
open(p, "w").write(s)
PY
'

# -- 9. FALSE-POSITIVE control ------------------------------------------------
# A host-level Pest run is the legitimate, required pattern. A guard that
# flagged it would break ci-api.yml while scoring full marks above.

expect_pass '9 · a legitimate host-level vendor/bin/pest run is NOT flagged' '
python3 - <<"PY"
p = ".github/workflows/ci-api.yml"
s = open(p).read()
s = s.replace(
    "      - name: Tests (Pest) on PostgreSQL with coverage",
    "      - name: Extra host-level suite\n"
    "        run: vendor/bin/pest --no-coverage\n"
    "\n"
    "      - name: Tests (Pest) on PostgreSQL with coverage",
    1,
)
open(p, "w").write(s)
PY
'

# -- 10. POSITIVE control — the control on the controls -----------------------

expect_pass '10 · an untouched fixture still passes — the guard is not rejecting everything' 'true'

# -- 11. Integrity ------------------------------------------------------------

after="$(fingerprint)"
if [[ "$before" == "$after" ]]; then
    passed=$((passed + 1))
    printf '  %sPASS%s  %s\n' "$GREEN" "$OFF" '11 · every guarded file is byte-identical after the run'
else
    false_negatives=$((false_negatives + 1))
    printf '  %sFAIL%s  %s\n' "$RED" "$OFF" '11 · THE WORKING TREE WAS MODIFIED — fixtures leaked'
    printf '        before=%s\n        after =%s\n' "$before" "$after"
fi

total=$((passed + false_negatives))
printf '\n%s\n' "========================================================================"
if (( false_negatives == 0 )); then
    printf 'RESULT: %d/%d controls confirmed — the guard bites, and only where it should.\n' "$passed" "$total"
else
    printf 'RESULT: %d/%d confirmed, %s%d FALSE NEGATIVE(S)%s.\n' "$passed" "$total" "$RED" "$false_negatives" "$OFF"
fi
printf '%s\n' "========================================================================"

exit $(( false_negatives == 0 ? 0 : 1 ))
