#!/usr/bin/env python3
"""M45 — can the `Dependency audit` required context actually fail?

## The gap this closes

`security.yml`'s dependency-audit job carried two independent defects, and only
the first was visible.

1. Both audit commands ended in `|| true`. The step's exit status was the
   `true`'s, so the required `Dependency audit` context could not fail on a
   dependency vulnerability — the one thing it exists to catch. At M44 the tree
   it was silently passing held 11 npm advisories (2 critical, 4 high) and 7
   Composer advisories (3 high).

2. The sharper one: the job has no `composer install`, and without `vendor/` a
   bare `composer audit` prints "No packages - skipping audit." and exits 0.
   Removing the mask alone would have left a step that audits nothing and passes
   for it — a green tick reporting on work that never happened, which is the M44
   staging-deploy defect reproduced inside the security gate. `--locked` audits
   composer.lock directly and needs no install.

So this validator asserts three things that are easy to lose one at a time: the
commands are unmasked, the job cannot mask them by another route, and the
Composer command still reads the lockfile rather than an absent vendor tree.

## What it does not claim

Nothing here runs an audit or knows whether the dependency tree is clean. It
reads workflow YAML. Whether the lockfiles are free of advisories is answered by
the job itself, on every pull request — which is the entire point of unmasking
it.

Usage:
  .github/scripts/verify_dependency_audit_gate.py [--repo-root=DIR] [--json=FILE]

Exit codes: 0 all checks pass, 1 one or more failed, 3 misinvocation.
"""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path

try:
    import yaml
except ImportError:  # pragma: no cover - environment problem, not a safety failure
    print("PyYAML is required: pip install pyyaml", file=sys.stderr)
    raise SystemExit(3)

# Asserted at the end, so a check lost to an early return or a mistyped id fails
# the run instead of shrinking the suite silently.
EXPECTED_CHECKS = 12

WORKFLOW = ".github/workflows/security.yml"

# The REQUIRED status-check context. GitHub matches on the JOB name, not the
# workflow name; renaming this detaches the ruleset entry and the context goes
# permanently pending rather than red.
REQUIRED_JOB = "Dependency audit"

REQUIRED_CHECKS_JSON = ".github/governance/required-checks.json"

# Constructs that turn a failing command into a passing step. `|| echo` is here
# because M44 found it in staging-deploy.yml doing exactly that.
MASKS = (
    ("|| true", "`|| true`"),
    ("|| echo", "`|| echo`"),
    ("set +e", "`set +e`"),
    ("exit 0", "a forced `exit 0`"),
    ("--no-verify", "`--no-verify`"),
)


class Report:
    def __init__(self) -> None:
        self.results: list[dict[str, object]] = []

    def check(self, check_id: str, ok: bool, detail: str) -> None:
        self.results.append({"id": check_id, "ok": bool(ok), "detail": detail})

    @property
    def failures(self) -> list[dict[str, object]]:
        return [r for r in self.results if not r["ok"]]


def code(script: str) -> str:
    """Strip shell comments so documentation cannot trip or satisfy a check.

    M44's lesson, learned the hard way: a validator that greps raw `run:` text
    fails on a comment quoting `|| true`, and — the half that actually matters —
    would be *satisfied* by a comment quoting the command it wants to see.
    """
    return "\n".join(
        line for line in script.splitlines() if not line.lstrip().startswith("#")
    )


def find_job(workflow: dict, name: str) -> tuple[str, dict] | tuple[None, None]:
    for key, job in (workflow.get("jobs") or {}).items():
        if isinstance(job, dict) and job.get("name") == name:
            return key, job
    return None, None


def main(argv: list[str]) -> int:
    repo_root = Path.cwd()
    json_out: Path | None = None

    for arg in argv[1:]:
        if arg.startswith("--repo-root="):
            repo_root = Path(arg.split("=", 1)[1]).resolve()
        elif arg.startswith("--json="):
            json_out = Path(arg.split("=", 1)[1])
        else:
            print(f"unknown argument: {arg}", file=sys.stderr)
            return 3

    if not repo_root.is_dir():
        print(f"not a directory: {repo_root}", file=sys.stderr)
        return 3

    report = Report()
    ids = [
        "audit.job_exists", "audit.no_continue_on_error", "audit.npm_step_present",
        "audit.npm_unmasked", "audit.npm_threshold_preserved", "audit.composer_step_present",
        "audit.composer_unmasked", "audit.composer_reads_lockfile",
        "audit.no_masked_step", "audit.job_not_conditional",
        "audit.context_still_required", "audit.secret_scan_intact",
    ]

    path = repo_root / WORKFLOW
    if not path.is_file():
        for check_id in ids:
            report.check(check_id, False, f"{WORKFLOW} is missing")
        return emit(report, json_out)

    try:
        workflow = yaml.safe_load(path.read_text(encoding="utf-8"))
    except Exception as exc:  # noqa: BLE001 - any parse problem is a failure
        for check_id in ids:
            report.check(check_id, False, f"{WORKFLOW}: {exc}")
        return emit(report, json_out)

    _, job = find_job(workflow, REQUIRED_JOB)

    report.check(
        "audit.job_exists",
        job is not None,
        f"job {REQUIRED_JOB!r} present" if job is not None
        else f"no job named {REQUIRED_JOB!r} — the required context cannot report",
    )

    if job is None:
        for check_id in ids[1:]:
            report.check(check_id, False, "skipped: the audit job is absent")
        return emit(report, json_out)

    steps = [s for s in (job.get("steps") or []) if isinstance(s, dict)]

    # A job-level continue-on-error passes the whole job regardless of what any
    # step returns — a mask one level up from the one that was there.
    report.check(
        "audit.no_continue_on_error",
        not job.get("continue-on-error"),
        "no job-level continue-on-error" if not job.get("continue-on-error")
        else "the audit job carries continue-on-error, so no step inside it can fail it",
    )

    # `if: false`, or any condition, can stop the job running at all. GitHub
    # treats a skipped required check as pending, not failed — the M29-A trap.
    report.check(
        "audit.job_not_conditional",
        "if" not in job,
        "the job is unconditional" if "if" not in job
        else f"the audit job is gated on `if: {job['if']}`; a skipped required "
             "check reports as pending, not failed",
    )

    def step_with(pattern: str) -> dict | None:
        for step in steps:
            if re.search(pattern, code(str(step.get("run") or ""))):
                return step
        return None

    npm_step = step_with(r"\bnpm\s+audit\b")
    composer_step = step_with(r"\bcomposer\s+audit\b")

    report.check(
        "audit.npm_step_present",
        npm_step is not None,
        "npm audit runs" if npm_step is not None
        else "no `npm audit` command in the audit job",
    )
    report.check(
        "audit.composer_step_present",
        composer_step is not None,
        "composer audit runs" if composer_step is not None
        else "no `composer audit` command in the audit job",
    )

    def unmasked(step: dict | None, label: str, check_id: str) -> None:
        if step is None:
            report.check(check_id, False, f"skipped: the {label} step is absent")
            return
        body = code(str(step.get("run") or ""))
        hits = [human for token, human in MASKS if token in body]
        if step.get("continue-on-error"):
            hits.append("step-level `continue-on-error`")
        report.check(
            check_id,
            not hits,
            f"{label} audit fails closed" if not hits
            else f"{label} audit is masked by {', '.join(hits)}",
        )

    unmasked(npm_step, "npm", "audit.npm_unmasked")
    unmasked(composer_step, "composer", "audit.composer_unmasked")

    # The threshold is policy, not decoration. Silently dropping to
    # `--audit-level=critical` would leave HIGH advisories passing while the step
    # still looks unmasked, which is the quiet version of the defect above.
    npm_body = code(str(npm_step.get("run") or "")) if npm_step else ""
    threshold_ok = "--audit-level=high" in npm_body
    report.check(
        "audit.npm_threshold_preserved",
        threshold_ok,
        "npm threshold is HIGH (policy unchanged)" if threshold_ok
        else "npm audit no longer runs at --audit-level=high; HIGH advisories "
             "would stop failing this gate",
    )

    # `--locked` is what makes the Composer audit real. Without vendor/ present —
    # and this job has no `composer install` — a bare `composer audit` prints
    # "No packages - skipping audit." and exits 0.
    composer_body = code(str(composer_step.get("run") or "")) if composer_step else ""
    installs = bool(re.search(r"\bcomposer\s+install\b", composer_body)) or any(
        re.search(r"\bcomposer\s+install\b", code(str(s.get("run") or ""))) for s in steps
    )
    lockfile_ok = "--locked" in composer_body or installs
    report.check(
        "audit.composer_reads_lockfile",
        lockfile_ok,
        "composer audit reads the lockfile (--locked)" if "--locked" in composer_body
        else "composer audit runs after an install" if installs
        else "composer audit has neither --locked nor a preceding `composer "
             "install`; with no vendor/ it prints 'No packages - skipping "
             "audit.' and exits 0, auditing nothing",
    )

    # Every step in the job, not only the two audits: a mask added to the setup
    # steps is still a mask inside a required gate.
    masked = []
    for step in steps:
        body = code(str(step.get("run") or ""))
        for token, human in MASKS:
            if token in body:
                masked.append(f"{step.get('name') or step.get('uses') or '?'}: {human}")
        if step.get("continue-on-error"):
            masked.append(f"{step.get('name') or step.get('uses') or '?'}: continue-on-error")
    report.check(
        "audit.no_masked_step",
        not masked,
        "no step in the audit job masks a failure" if not masked
        else "masked step(s): " + "; ".join(masked),
    )

    # The gate is only worth anything while the ruleset still requires it.
    ratchet = repo_root / REQUIRED_CHECKS_JSON
    try:
        data = json.loads(ratchet.read_text(encoding="utf-8"))
        required = {e["context"] for e in data["required"]}
        still = REQUIRED_JOB in required
        detail = (f"{REQUIRED_JOB!r} is still a required context" if still
                  else f"{REQUIRED_JOB!r} is no longer listed in {REQUIRED_CHECKS_JSON}")
    except Exception as exc:  # noqa: BLE001
        still, detail = False, f"{REQUIRED_CHECKS_JSON}: {exc}"
        required = set()
    report.check("audit.context_still_required", still, detail)

    # Secret scanning shares this workflow and is required too. A change here
    # that dropped it would be invisible to every check above.
    _, secret_job = find_job(workflow, "Secret scanning")
    secret_ok = secret_job is not None and "Secret scanning" in required
    report.check(
        "audit.secret_scan_intact",
        secret_ok,
        "the Secret scanning job and context are intact" if secret_ok
        else "the required `Secret scanning` job or context is missing from this workflow",
    )

    return emit(report, json_out)


def emit(report: Report, json_out: Path | None) -> int:
    print("EruoFood — M45 dependency audit gate")
    print("=" * 78)
    for result in report.results:
        print(f"  {'PASS' if result['ok'] else 'FAIL'}  {result['id']}: {result['detail']}")

    print()
    counted = len(report.results)
    if counted != EXPECTED_CHECKS:
        print(f"CHECK COUNT MISMATCH: ran {counted}, expected {EXPECTED_CHECKS}.")
        print("A check was added or lost without updating EXPECTED_CHECKS.")
        return 1

    if json_out is not None:
        json_out.write_text(
            json.dumps(
                {"checks": counted, "failures": report.failures, "results": report.results},
                indent=2,
            ),
            encoding="utf-8",
        )

    if report.failures:
        print(f"{len(report.failures)}/{counted} checks FAILED.")
        return 1

    print(f"{counted}/{counted} checks passed. The dependency audit gate fails closed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
