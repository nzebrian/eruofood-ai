#!/usr/bin/env python3
"""Assert that the Governance Advisory workflow can still actually fail.

M37 Phase 4A found that `m36_ci_enforcement_control.sh` hardcodes
`workflow-integrity.yml`, so it would not notice a `continue-on-error: true`, a
`|| true` or a forced `exit 0` added to an enforcement step in any *other*
workflow. Phase 4B adds a workflow with exactly that shape, so it needs its own
control or it ships with the protection M36 was built to provide missing.

Every finding has a stable code. The negative controls in
`m37_governance_advisory_control.sh` assert findings BY CODE, because a control
that only knows "it exited non-zero" cannot tell whether it caught the defect it
planted or an unrelated one — which is how M28 found a five-adapter sweep that
had been testing one adapter five times.

Usage:  governance_advisory_wiring.py [--repo-root=<path>] [--json=<path>]
Exit:   0 clean · 1 findings · 2 could not run
"""

from __future__ import annotations

import argparse
import json
import os
import re
import sys

try:
    import yaml
except ImportError:  # pragma: no cover - environment problem, not a finding
    print("ERROR  PyYAML is required", file=sys.stderr)
    raise SystemExit(2)

ADVISORY_WORKFLOW = ".github/workflows/governance-advisory.yml"
INTEGRITY_WORKFLOW = ".github/workflows/workflow-integrity.yml"
REQUIRED_CHECKS = ".github/governance/required-checks.json"

ADVISORY_JOB_NAME = "Governance Advisory"
INTEGRITY_JOB_NAME = "CI · Workflow Integrity"

# The nine contexts `required-checks.json` records for the `main` ruleset. The
# advisory job's name must not be any of them: a collision would silently attach
# a required rule to this job, turning an advisory check into a merge gate by
# accident.
#
# This is a RATCHET, not a mirror. It is deliberately a hand-maintained copy, so
# that quietly dropping a context from `required-checks.json` shows up here as a
# finding rather than as agreement between a file and itself. Adding to it is
# therefore a deliberate act: M33 added "Mobile Certification" in the same change
# that added the aggregator job and the ruleset entry.
REQUIRED_CONTEXTS = [
    "Lint spec · Generate types",
    "Lint · Typecheck · Test · Build",
    "Tests · SQLite",
    "Secret scanning",
    "Dependency audit",
    "Build · Boot · Migrate · Healthcheck",
    "CI · Workflow Integrity",
    "Lint · Analyse · Test",
    "Mobile Certification",
]

# What makes a step critical: it is the step that fetches the evidence, the one
# that judges it, or the one that decides the verdict. Matched on what the step
# actually runs, not on its display name, so renaming a step cannot smuggle it
# out of scope.
CRITICAL = {
    "fetch": "api.github.com/repos/",
    "validator": "verify_repository_governance.php",
    "ratchet": "governance_ratchet.php",
}

# Shell that turns a failing command into a passing step.
MASKING = [
    (r"\|\|\s*true", "|| true"),
    (r"\|\|\s*:", "|| :"),
    (r"set\s+\+e\b", "set +e"),
    (r"\|\|\s*exit\s+0", "|| exit 0"),
]


class Findings:
    def __init__(self) -> None:
        self.items: list[dict] = []

    def add(self, code: str, detail: str) -> None:
        self.items.append({"code": code, "detail": detail})

    def __bool__(self) -> bool:
        return bool(self.items)


def load_yaml(path: str):
    with open(path, encoding="utf-8") as fh:
        return yaml.safe_load(fh)


def triggers_of(workflow: dict) -> dict:
    """YAML 1.1 parses the key `on` as the boolean True. Accept both."""
    for key in ("on", True):
        if key in workflow:
            value = workflow[key]
            return value if isinstance(value, dict) else {}
    return {}


def run_text(step: dict) -> str:
    return str(step.get("run") or "")


def check_advisory(root: str, f: Findings) -> None:
    path = os.path.join(root, ADVISORY_WORKFLOW)

    if not os.path.isfile(path):
        f.add("ADVISORY_WORKFLOW_MISSING", f"{ADVISORY_WORKFLOW} does not exist")
        return

    try:
        wf = load_yaml(path)
    except Exception as exc:
        f.add("ADVISORY_WORKFLOW_UNPARSEABLE", f"{ADVISORY_WORKFLOW}: {exc}")
        return

    if not isinstance(wf, dict):
        f.add("ADVISORY_WORKFLOW_UNPARSEABLE", f"{ADVISORY_WORKFLOW} is not a mapping")
        return

    # -- permissions ---------------------------------------------------------
    perms = wf.get("permissions")
    if perms != {"contents": "read"}:
        f.add(
            "ADVISORY_PERMISSIONS",
            f"workflow permissions must be exactly {{contents: read}}, found {perms!r}",
        )

    jobs = wf.get("jobs") or {}
    if not isinstance(jobs, dict) or not jobs:
        f.add("ADVISORY_JOB_MISSING", "the workflow declares no jobs")
        return

    for job_id, job in jobs.items():
        if not isinstance(job, dict):
            continue
        job_perms = job.get("permissions")
        if job_perms is not None and job_perms != {"contents": "read"}:
            f.add(
                "ADVISORY_PERMISSIONS",
                f"job `{job_id}` overrides permissions with {job_perms!r}",
            )

    # -- the context name ----------------------------------------------------
    names = {str(j.get("name", jid)): jid for jid, j in jobs.items() if isinstance(j, dict)}

    # Collision is checked BEFORE the missing-name check, and deliberately so.
    # Renaming the advisory job to one of the eight required contexts is both
    # defects at once — the advisory job has vanished AND a required rule has
    # silently attached itself to whatever this job does. Reporting only the
    # first would hide the more dangerous half.
    for name in names:
        if name in REQUIRED_CONTEXTS:
            f.add(
                "ADVISORY_JOB_NAME_COLLIDES",
                f"job name {name!r} is one of the eight required contexts — "
                "GitHub would treat this job as that merge gate",
            )

    if ADVISORY_JOB_NAME not in names:
        f.add(
            "ADVISORY_JOB_NAME_WRONG",
            f"no job is named {ADVISORY_JOB_NAME!r}; found {sorted(names)!r}. "
            "GitHub matches a required status check on the JOB name.",
        )
        return

    job = jobs[names[ADVISORY_JOB_NAME]]

    # -- triggers ------------------------------------------------------------
    on = triggers_of(wf)

    if "pull_request" not in on:
        f.add("ADVISORY_TRIGGER_MISSING", "no `pull_request` trigger")
    else:
        pr = on.get("pull_request") or {}
        if isinstance(pr, dict) and ("paths" in pr or "paths-ignore" in pr):
            f.add(
                "ADVISORY_PR_PATH_FILTERED",
                "`pull_request` is path-filtered; a filtered check can never be "
                "required, because GitHub leaves a check that does not report "
                "PENDING rather than satisfied",
            )

    if "schedule" not in on:
        f.add(
            "ADVISORY_SCHEDULE_MISSING",
            "no `schedule` trigger; live ruleset drift happens outside commits "
            "and would only be noticed by coincidence",
        )

    # -- the steps -----------------------------------------------------------
    steps = job.get("steps") or []
    found: dict[str, dict] = {}

    for step in steps:
        if not isinstance(step, dict):
            continue
        text = run_text(step)
        for role, needle in CRITICAL.items():
            if needle in text and role not in found:
                found[role] = step

    for role, code in (
        ("fetch", "ADVISORY_FETCH_MISSING"),
        ("validator", "ADVISORY_VALIDATOR_MISSING"),
        ("ratchet", "ADVISORY_RATCHET_MISSING"),
    ):
        if role not in found:
            f.add(code, f"no step runs the {role} ({CRITICAL[role]})")

    for role, step in found.items():
        text = run_text(step)
        label = str(step.get("name", role))

        if step.get("continue-on-error") is True:
            f.add(
                "ADVISORY_CONTINUE_ON_ERROR",
                f"critical step {label!r} sets continue-on-error: true — the job "
                "would report success no matter what this step found",
            )

        if "if" in step:
            f.add(
                "ADVISORY_ALWAYS_MASKING",
                f"critical step {label!r} is conditional (`if: {step['if']}`); a "
                "critical step must run unconditionally",
            )

        for pattern, human in MASKING:
            if re.search(pattern, text):
                f.add(
                    "ADVISORY_SHELL_MASKING",
                    f"critical step {label!r} contains `{human}`",
                )

        if re.search(r"^\s*exit\s+0\s*$", text, re.MULTILINE):
            f.add(
                "ADVISORY_FORCED_EXIT_ZERO",
                f"critical step {label!r} forces `exit 0`",
            )

    # The verdict step is the one place where capturing an exit code would be
    # fatal: the ratchet's status IS the job's status.
    if "ratchet" in found:
        text = run_text(found["ratchet"])
        if re.search(r"\|\|\s*\w+=\$?\??", text) or "$?" in text:
            f.add(
                "ADVISORY_RATCHET_EXIT_CAPTURED",
                "the ratchet step captures its own exit status; that status is "
                "the job's verdict and must propagate unaltered",
            )

    # The validator legitimately captures 0/1/2 — the ratchet interprets them —
    # but only if it still hard-fails on 3, which means "produced no verdict".
    if "validator" in found:
        text = run_text(found["validator"])
        if "$?" in text and not re.search(r"exit\s+1", text):
            f.add(
                "ADVISORY_VALIDATOR_EXIT_SWALLOWED",
                "the validator step captures its exit status without any guard "
                "that fails the job when the validator could not run",
            )
        if "--json=" not in text:
            f.add(
                "ADVISORY_JSON_MISSING",
                "the validator is invoked without `--json=`; the ratchet and the "
                "job summary would have to scrape console text",
            )

    if "ratchet" in found and "--summary=" not in run_text(found["ratchet"]):
        f.add(
            "ADVISORY_JSON_MISSING",
            "the ratchet is invoked without `--summary=`",
        )


def check_not_required(root: str, f: Findings) -> None:
    """The advisory context must not be in the recorded required set."""
    path = os.path.join(root, REQUIRED_CHECKS)

    if not os.path.isfile(path):
        f.add("REQUIRED_CHECKS_MISSING", f"{REQUIRED_CHECKS} does not exist")
        return

    try:
        with open(path, encoding="utf-8") as fh:
            doc = json.load(fh)
    except Exception as exc:
        f.add("REQUIRED_CHECKS_UNPARSEABLE", f"{REQUIRED_CHECKS}: {exc}")
        return

    required = [str(r.get("context", "")) for r in doc.get("required", []) if isinstance(r, dict)]

    if ADVISORY_JOB_NAME in required:
        f.add(
            "ADVISORY_CONTEXT_REQUIRED",
            f"{ADVISORY_JOB_NAME!r} appears in `required[]` — an advisory check "
            "must not be a merge gate",
        )

    if sorted(required) != sorted(REQUIRED_CONTEXTS):
        missing = sorted(set(REQUIRED_CONTEXTS) - set(required))
        extra = sorted(set(required) - set(REQUIRED_CONTEXTS))
        f.add(
            "REQUIRED_CONTEXTS_CHANGED",
            f"the recorded required set changed (missing={missing!r}, extra={extra!r})",
        )

    deliberate = [
        str(r.get("context", ""))
        for r in doc.get("deliberately_not_required", [])
        if isinstance(r, dict)
    ]

    if ADVISORY_JOB_NAME not in deliberate:
        f.add(
            "ADVISORY_NOT_RECORDED",
            f"{ADVISORY_JOB_NAME!r} is not recorded under "
            "`deliberately_not_required`; the decision must be written down, "
            "not inferred from its absence",
        )


def check_integrity_untouched(root: str, f: Findings) -> None:
    """Phase 4B must not have leaked into the required Workflow Integrity job."""
    path = os.path.join(root, INTEGRITY_WORKFLOW)

    if not os.path.isfile(path):
        f.add("INTEGRITY_WORKFLOW_MISSING", f"{INTEGRITY_WORKFLOW} does not exist")
        return

    try:
        wf = load_yaml(path)
    except Exception as exc:
        f.add("INTEGRITY_WORKFLOW_UNPARSEABLE", f"{INTEGRITY_WORKFLOW}: {exc}")
        return

    jobs = (wf or {}).get("jobs") or {}
    names = [str(j.get("name", "")) for j in jobs.values() if isinstance(j, dict)]

    if INTEGRITY_JOB_NAME not in names:
        f.add(
            "INTEGRITY_JOB_RENAMED",
            f"no job named {INTEGRITY_JOB_NAME!r} in {INTEGRITY_WORKFLOW}; "
            "renaming it detaches the required status check",
        )
        return

    body = "\n".join(
        run_text(s) for j in jobs.values() if isinstance(j, dict) for s in (j.get("steps") or [])
        if isinstance(s, dict)
    )

    for needle, code in (
        ("composer", "INTEGRITY_WORKFLOW_CHANGED"),
        ("api.github.com", "INTEGRITY_WORKFLOW_CHANGED"),
        ("verify_repository_governance.php", "INTEGRITY_WORKFLOW_CHANGED"),
        ("governance_ratchet.php", "INTEGRITY_WORKFLOW_CHANGED"),
    ):
        if needle in body:
            f.add(
                code,
                f"{INTEGRITY_WORKFLOW} now contains {needle!r}; Phase 4B must not "
                "add PHP, Composer, API fetching or advisory logic to the "
                "required integrity gate",
            )


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--repo-root", default=None)
    ap.add_argument("--json", default=None)
    args = ap.parse_args()

    root = args.repo_root or os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..")
    root = os.path.abspath(root)

    if not os.path.isdir(os.path.join(root, ".github")):
        print(f"ERROR  no .github directory under {root}", file=sys.stderr)
        return 2

    f = Findings()
    check_advisory(root, f)
    check_not_required(root, f)
    check_integrity_untouched(root, f)

    print(f"Governance Advisory wiring control — root: {root}")
    print("=" * 72)

    if not f:
        print("  PASS  the advisory job can still fail, and is still not required")
    for item in f.items:
        print(f"  FINDING {item['code']}  {item['detail']}")

    print("=" * 72)
    print(f"RESULT: {len(f.items)} finding(s)")

    if args.json:
        with open(args.json, "w", encoding="utf-8") as fh:
            json.dump({"root": root, "findings": f.items}, fh, indent=2)
            fh.write("\n")

    return 1 if f else 0


if __name__ == "__main__":
    raise SystemExit(main())
