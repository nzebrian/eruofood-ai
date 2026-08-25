#!/usr/bin/env bash
# =============================================================================
# M35 — GA Docker Certification guard
#
# Protects two invariants that are invisible while everything is green, and
# whose violation is silent rather than loud.
#
# INVARIANT 1 — no test runner inside a production-environment container.
#
#   `GA Docker Certification` certifies the PRODUCTION artifact.
#   infra/docker/php/Dockerfile builds it with `composer install --no-dev`, so
#   Pest (a require-dev package) is absent by design. Re-adding dev
#   dependencies at certification time means the thing under test is no longer
#   the thing that ships.
#
#   It also cannot work. docker-compose.ci.yml injects APP_ENV=production as a
#   real environment variable, and PHPUnit does NOT override a variable that
#   already exists unless the declaration carries force="true" — see
#   vendor/phpunit/phpunit/src/TextUI/Configuration/PhpHandler.php:
#
#       if ($force || getenv($name) === false) { putenv("{$name}={$value}"); }
#
#   apps/api/phpunit.xml sets APP_ENV=testing WITHOUT force, so inside that
#   container the suite boots as `production`. There, ConfirmableTrait blocks
#   the un-`--force`d `migrate:fresh` that RefreshDatabase depends on, the
#   schema is never prepared, and the suite exits 2. Every run of that workflow
#   between 2026-08-04 and 2026-08-23 failed exactly this way; it never once
#   passed.
#
# INVARIANT 2 — phpunit.xml must not force the database/cache selectors.
#
#   The mirror image, and the more dangerous of the two. Adding force="true"
#   to phpunit.xml would "fix" invariant 1 by making phpunit.xml win — and
#   would SILENTLY DOWNGRADE the repository's most important required check.
#   ci-api.yml's `Lint · Analyse · Test` job supplies DB_CONNECTION=pgsql,
#   DB_DATABASE=eruofood_test and CACHE_STORE=redis as real environment
#   variables and RELIES on them beating phpunit.xml's sqlite/:memory:/array.
#   Force those declarations and the financial-integrity gate quietly moves off
#   PostgreSQL and back onto in-memory SQLite, still reporting green.
#
#   Section C asserts that dependency explicitly, so invariant 2 cannot become
#   vacuous if that job is ever rewritten.
#
# Usage:  .github/scripts/m35_docker_certification_guard.sh [REPO_ROOT]
#
# REPO_ROOT defaults to the enclosing git repository. The negative controls
# pass a throwaway fixture directory instead, so the real working tree is
# never modified.
# =============================================================================

set -uo pipefail

REPO_ROOT="${1:-}"
if [[ -z "$REPO_ROOT" ]]; then
    REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
fi
REPO_ROOT="$(cd "$REPO_ROOT" && pwd)"

WORKFLOW_DIR="$REPO_ROOT/.github/workflows"
PHPUNIT_XML="$REPO_ROOT/apps/api/phpunit.xml"

if [[ -t 1 ]]; then
    GREEN=$'\033[32m'; RED=$'\033[31m'; DIM=$'\033[2m'; OFF=$'\033[0m'
else
    GREEN=''; RED=''; DIM=''; OFF=''
fi

passed=0
failed=0

ok()   { printf '  %sPASS%s  %s\n' "$GREEN" "$OFF" "$1"; passed=$((passed + 1)); }
bad()  { printf '  %sFAIL%s  %s\n' "$RED"   "$OFF" "$1"; failed=$((failed + 1)); }
head_() { printf '\n%s\n' "$1"; }

printf '%s\n' "========================================================================"
printf '%s\n' "M35 — GA DOCKER CERTIFICATION GUARD"
printf '  repo root: %s\n' "$REPO_ROOT"
printf '%s\n' "========================================================================"

# -----------------------------------------------------------------------------
# A) No test runner executes inside a container whose environment is production
# -----------------------------------------------------------------------------
head_ "A) No test runner inside a production-environment container"

if [[ ! -d "$WORKFLOW_DIR" ]]; then
    bad "no .github/workflows directory at $WORKFLOW_DIR"
else
    a_report="$(python3 - "$WORKFLOW_DIR" "$REPO_ROOT" <<'PY'
import os, re, sys, yaml

wf_dir, repo_root = sys.argv[1], sys.argv[2]

# Flags that consume the following token, so the service name is not mistaken
# for one of their values.
VALUE_FLAGS = {'-e', '--env', '-u', '--user', '-w', '--workdir', '--index', '-l', '--label'}

# A test runner invoked through the container runtime. Deliberately narrow: a
# host-level `vendor/bin/pest` (ci-api.yml, release.yml) is legitimate and must
# never match.
RUNNER = re.compile(r'(vendor/bin/pest|vendor/bin/phpunit|artisan\s+test)')
DOCKER_EXEC = re.compile(r'\bdocker\b[^\n]*\b(exec|run)\b')


def load(path):
    try:
        with open(path) as fh:
            return yaml.safe_load(fh)
    except Exception:
        return None


def service_from(cmd):
    """Service name in a `docker compose exec [flags] <service> <cmd>` line."""
    tokens = cmd.split()
    try:
        i = tokens.index('exec')
    except ValueError:
        try:
            i = tokens.index('run')
        except ValueError:
            return None
    i += 1
    while i < len(tokens):
        t = tokens[i]
        if t in VALUE_FLAGS:
            i += 2
            continue
        if t.startswith('-'):
            i += 1
            continue
        return t
    return None


def env_of_service(compose_files, service):
    """Merge a service's `environment:` across compose files, later wins."""
    env = {}
    for rel in compose_files:
        path = os.path.join(repo_root, rel)
        doc = load(path)
        if not isinstance(doc, dict):
            continue
        svc = (doc.get('services') or {}).get(service)
        if not isinstance(svc, dict):
            continue
        raw = svc.get('environment')
        if isinstance(raw, dict):
            for k, v in raw.items():
                env[str(k)] = '' if v is None else str(v)
        elif isinstance(raw, list):
            for item in raw:
                s = str(item)
                if '=' in s:
                    k, v = s.split('=', 1)
                    env[k.strip()] = v.strip()
    return env


def compose_files_for(workflow, job, run_text):
    """Which compose files are in effect for this step."""
    files = []
    # Explicit -f flags on the command line win outright.
    files += re.findall(r'-f\s+(\S+\.ya?ml)', run_text)
    if files:
        return files
    for scope in (job.get('env') or {}), (workflow.get('env') or {}):
        cf = scope.get('COMPOSE_FILE')
        if cf:
            return [p for p in str(cf).split(':') if p]
    # Compose's own default, including the dev override it auto-loads.
    return ['docker-compose.yml', 'docker-compose.override.yml']


findings = []
scanned = 0

for name in sorted(os.listdir(wf_dir)):
    if not name.endswith(('.yml', '.yaml')):
        continue
    wf = load(os.path.join(wf_dir, name))
    if not isinstance(wf, dict):
        continue
    for jid, job in (wf.get('jobs') or {}).items():
        if not isinstance(job, dict):
            continue
        for step in (job.get('steps') or []):
            if not isinstance(step, dict) or 'run' not in step:
                continue
            run_text = str(step['run'])
            for line in run_text.split('\n'):
                if not (DOCKER_EXEC.search(line) and RUNNER.search(line)):
                    continue
                scanned += 1
                svc = service_from(line)
                if svc is None:
                    findings.append((name, jid, step.get('name', '?'),
                                     'container service could not be determined'))
                    continue
                cfiles = compose_files_for(wf, job, run_text)
                env = env_of_service(cfiles, svc)
                app_env = env.get('APP_ENV', '')
                if app_env == 'production':
                    findings.append((
                        name, jid, step.get('name', '?'),
                        "service '%s' has APP_ENV=production via %s" % (svc, ':'.join(cfiles)),
                    ))

print('SCANNED=%d' % scanned)
for f in findings:
    print('FINDING=%s | job %s | step %r | %s' % f)
PY
)"
    a_status=$?

    if [[ $a_status -ne 0 ]]; then
        bad "the workflow scan could not run (python/yaml unavailable)"
    else
        a_scanned="$(printf '%s' "$a_report" | sed -n 's/^SCANNED=//p')"
        a_findings="$(printf '%s' "$a_report" | grep '^FINDING=' || true)"

        if [[ -n "$a_findings" ]]; then
            bad "a test runner executes inside a production-environment container"
            while IFS= read -r line; do
                printf '        %s%s%s\n' "$DIM" "${line#FINDING=}" "$OFF"
            done <<< "$a_findings"
        else
            ok "no workflow runs a test runner inside a production-env container"
        fi

        # The scan must actually have run. A silently-broken parser would
        # otherwise read as a permanent pass. That the scanner can still SEE a
        # violation is proved separately, by negative control A.
        if [[ -n "$a_scanned" ]]; then
            ok "the scanner parsed every workflow in .github/workflows"
        else
            bad "the scanner produced no scan count — it may not be running"
        fi
    fi
fi

# -----------------------------------------------------------------------------
# B) phpunit.xml does not force the externally-supplied selectors
# -----------------------------------------------------------------------------
head_ "B) phpunit.xml does not force the CI-supplied selectors"

if [[ ! -f "$PHPUNIT_XML" ]]; then
    bad "apps/api/phpunit.xml is missing"
else
    b_report="$(python3 - "$PHPUNIT_XML" <<'PY'
import sys, xml.etree.ElementTree as ET

# Forcing any of these would let phpunit.xml beat the real environment that
# ci-api.yml's required PostgreSQL job supplies, silently moving that gate back
# onto in-memory SQLite / the array cache while still reporting green.
PROTECTED = {
    'APP_ENV',
    'DB_CONNECTION', 'DB_DATABASE', 'DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD',
    'CACHE_STORE', 'QUEUE_CONNECTION', 'SESSION_DRIVER',
    'REDIS_HOST', 'REDIS_PORT', 'REDIS_PASSWORD', 'REDIS_CLIENT',
}

try:
    root = ET.parse(sys.argv[1]).getroot()
except Exception as exc:
    print('PARSE_ERROR=%s' % exc)
    raise SystemExit(0)

total = 0
for env in root.iter('env'):
    total += 1
    name = env.get('name', '')
    force = (env.get('force') or '').strip().lower()
    if force in ('true', '1') and name in PROTECTED:
        print('FORCED=%s' % name)

print('ENVCOUNT=%d' % total)
PY
)"
    if printf '%s' "$b_report" | grep -q '^PARSE_ERROR='; then
        bad "apps/api/phpunit.xml does not parse as XML"
    else
        b_forced="$(printf '%s' "$b_report" | sed -n 's/^FORCED=//p')"
        b_count="$(printf '%s' "$b_report" | sed -n 's/^ENVCOUNT=//p')"

        if [[ -n "$b_forced" ]]; then
            bad "phpunit.xml forces a CI-supplied selector — the required PostgreSQL gate would silently fall back to SQLite"
            while IFS= read -r n; do
                printf '        %sforced: %s%s\n' "$DIM" "$n" "$OFF"
            done <<< "$b_forced"
        else
            ok "no protected <env> declaration carries force=\"true\""
        fi

        if [[ -n "$b_count" ]] && (( b_count > 0 )); then
            ok "phpunit.xml declares <env> entries the check can inspect ($b_count)"
        else
            bad "phpunit.xml declares no <env> entries — check B verified nothing"
        fi
    fi
fi

# -----------------------------------------------------------------------------
# C) The required PostgreSQL gate genuinely depends on the external environment
# -----------------------------------------------------------------------------
head_ "C) The required PostgreSQL gate still relies on the real environment"

CI_API="$WORKFLOW_DIR/ci-api.yml"
if [[ ! -f "$CI_API" ]]; then
    bad "ci-api.yml is missing — the gate protected by section B is gone"
else
    c_report="$(python3 - "$CI_API" <<'PY'
import sys, yaml

wf = yaml.safe_load(open(sys.argv[1]))
target = None
for jid, job in (wf.get('jobs') or {}).items():
    if str(job.get('name', '')) == 'Lint · Analyse · Test':
        target = (jid, job)
        break

if target is None:
    print('MISSING_JOB=1')
    raise SystemExit(0)

jid, job = target
env = {str(k): str(v) for k, v in (job.get('env') or {}).items()}
print('JOB=%s' % jid)
print('DB_CONNECTION=%s' % env.get('DB_CONNECTION', ''))
print('APP_ENV_SET=%s' % ('1' if 'APP_ENV' in env else '0'))

host_runner = any(
    'vendor/bin/pest' in str(s.get('run', '')) and 'docker' not in str(s.get('run', ''))
    for s in (job.get('steps') or []) if isinstance(s, dict)
)
print('HOST_RUNNER=%s' % ('1' if host_runner else '0'))
PY
)"
    if printf '%s' "$c_report" | grep -q '^MISSING_JOB='; then
        bad "no job named 'Lint · Analyse · Test' in ci-api.yml"
    else
        c_db="$(printf '%s' "$c_report" | sed -n 's/^DB_CONNECTION=//p')"
        c_appenv="$(printf '%s' "$c_report" | sed -n 's/^APP_ENV_SET=//p')"
        c_host="$(printf '%s' "$c_report" | sed -n 's/^HOST_RUNNER=//p')"

        if [[ "$c_db" == "pgsql" ]]; then
            ok "'Lint · Analyse · Test' supplies DB_CONNECTION=pgsql from the environment"
        else
            bad "'Lint · Analyse · Test' no longer supplies DB_CONNECTION=pgsql (got '${c_db:-unset}')"
        fi

        if [[ "$c_appenv" == "0" ]]; then
            ok "'Lint · Analyse · Test' does not set APP_ENV, so phpunit.xml's testing value applies"
        else
            bad "'Lint · Analyse · Test' now sets APP_ENV — RefreshDatabase would break as it does in the container"
        fi

        if [[ "$c_host" == "1" ]]; then
            ok "'Lint · Analyse · Test' runs Pest on the host, with dev dependencies"
        else
            bad "'Lint · Analyse · Test' no longer runs Pest on the host"
        fi
    fi
fi

printf '\n%s\n' "========================================================================"
if (( failed == 0 )); then
    printf 'RESULT: %d passed, %d failed\n' "$passed" "$failed"
else
    printf 'RESULT: %d passed, %s%d failed%s\n' "$passed" "$RED" "$failed" "$OFF"
fi
printf '%s\n' "========================================================================"

exit $(( failed == 0 ? 0 : 1 ))
