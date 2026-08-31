#!/usr/bin/env python3
"""M44 — does the deployment pipeline fail closed?

## The distinction this holds

A CI check that cannot fail is worse than no check: it costs minutes and buys
a green tick that means nothing. The same is true one stage later. A deploy step
that swallows its own failure produces the most expensive outcome available —
a deployment reported as successful while the thing it was supposed to change
did not change.

`staging-deploy.yml` had four of them at once. `kubectl set image` was `|| true`
three times, so `web` and `worker` could stay on the old image while the job went
green. The migration step applied a manifest that did not exist and turned the
failure into an `echo`, so no deploy on that path had ever migrated anything.
The smoke test `exit 0`'d when `STAGING_URL` was unset, so an unverified deploy
looked exactly like a verified one. And `${{ inputs.ref }}` was spliced into a
script executed on the staging host.

None of that was reachable by the existing controls: M36 and M37 guard the
enforcement steps of the CI workflows, and nothing looked at the workflow that
deploys.

## What is checked, and what is deliberately not

This reads the deploy workflow as data — `yaml.safe_load`, then per-step
inspection — rather than grepping text, so a reformatting cannot silently drop
a check. Advisory steps are allowed to be advisory: the rule is not "no `||
true` anywhere", it is "no `|| true` on a step whose failure means the
deployment did not happen".

It asserts nothing about the cluster. Whether the manifests are correct for a
particular environment is not knowable from this repository and is not claimed.

Usage:
    .github/scripts/verify_deployment_safety.py
    .github/scripts/verify_deployment_safety.py --repo-root=<fixture> --json=<path>

Exit 0 when every check passes, 1 when a deployment-safety invariant is
violated, 3 when the validator could not run or was misinvoked.
"""

from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path
from typing import Any, Callable

try:
    import yaml
except ImportError:  # pragma: no cover - environment problem, not a finding
    print("ERROR  PyYAML is required to run this validator", file=sys.stderr)
    raise SystemExit(3)

EXIT_OK = 0
EXIT_FAIL = 1
EXIT_ERROR = 3

SUMMARY_SCHEMA = 1

# The number of checks this validator is expected to run. Without it, a refactor
# that stops registering half of them reports "all checks passed" and means
# nothing.
EXPECTED_CHECKS = 12

DEPLOY_WORKFLOW = ".github/workflows/staging-deploy.yml"
MIGRATE_JOB = "infra/k8s/jobs/migrate.yaml"

# Stable identifiers. The negative controls name these, so each control asserts
# that the SPECIFIC check it targeted failed rather than settling for a non-zero
# exit that any unrelated breakage would also produce.
CHECK_NO_EXPRESSION_IN_RUN = "deploy.no_expression_in_run"
CHECK_REF_PASSED_AS_ARGUMENT = "deploy.ref_passed_as_argument"
CHECK_NO_MASKED_DEPLOY_STEP = "deploy.no_masked_deploy_step"
CHECK_NO_CONTINUE_ON_ERROR = "deploy.no_continue_on_error"
CHECK_IMAGE_ROLL_UNMASKED = "deploy.image_roll_unmasked"
CHECK_ROLLOUT_COVERS_EVERY_DEPLOYMENT = "deploy.rollout_covers_every_deployment"
CHECK_MIGRATION_MANIFEST_EXISTS = "deploy.migration_manifest_exists"
CHECK_MIGRATION_UNMASKED = "deploy.migration_unmasked"
CHECK_MIGRATION_IS_WAITED_ON = "deploy.migration_is_waited_on"
CHECK_SMOKE_TEST_FAILS_CLOSED = "deploy.smoke_test_fails_closed"
CHECK_ROLLBACK_TARGET_RECORDED = "deploy.rollback_target_recorded"
CHECK_CHECK_COUNT = "deploy.check_count"

# Steps whose failure means the deployment did not happen. A `|| true` on any of
# these is the defect; a `|| true` while collecting diagnostics is not.
CRITICAL_STEP_NAMES = (
    "Select deploy backend",
    "Deploy via kubectl",
    "Deploy via docker compose over SSH",
    "Post-deploy smoke test",
)

MASKING_PATTERNS = (
    ("|| true", "|| true"),
    ("|| :", "|| :"),
    ("set +e", "set +e"),
    ("|| echo", "|| echo (a failure turned into a message)"),
)


class Validator:
    def __init__(self, repo_root: Path) -> None:
        self.repo_root = repo_root
        self.passed = 0
        self.failed = 0
        self.failures: list[dict[str, str]] = []

    def read(self, relative: str) -> str:
        path = self.repo_root / relative
        try:
            return path.read_text(encoding="utf-8")
        except OSError:
            # "The file is not there" is not evidence that deployment is unsafe.
            # Reporting it as a finding would let a broken fixture masquerade as
            # a real one.
            print(f"ERROR  cannot read required file: {path}", file=sys.stderr)
            raise SystemExit(EXIT_ERROR)

    def verify(self, identifier: str, description: str, check: Callable[[], tuple[bool, str]]) -> None:
        try:
            ok, detail = check()
        except Exception as exc:  # noqa: BLE001 - a throwing check is a failing check
            ok, detail = False, f"{type(exc).__name__}: {exc}"

        if ok:
            self.passed += 1
        else:
            self.failed += 1
            self.failures.append({"id": identifier, "check": description, "detail": detail})

        print(f"  {'PASS' if ok else 'FAIL'} {description}{'' if not detail else f'  ({detail})'}")


def code(run: str) -> str:
    """A step's script with its shell comments removed.

    Every text check below runs on this rather than the raw body. The first
    version did not, and immediately failed on its own documentation: a comment
    explaining why `|| true` was removed contains the string `|| true`. That is
    not a cosmetic annoyance — a validator that a comment can trip is also a
    validator that a comment can satisfy, and "the mask is only in a string"
    would become a way to hide one.

    Stripping starts at a `#` that begins a line or follows whitespace, so a `#`
    inside a URL or a quoted value survives.
    """
    stripped = []
    for line in run.splitlines():
        without_comment = re.sub(r"(^|\s)#.*$", "", line)
        if without_comment.strip():
            stripped.append(without_comment)
    return "\n".join(stripped)


def deploy_steps(document: dict[str, Any]) -> list[dict[str, Any]]:
    steps: list[dict[str, Any]] = []
    for job in (document.get("jobs") or {}).values():
        steps.extend(job.get("steps") or [])
    return steps


def named(steps: list[dict[str, Any]], name: str) -> dict[str, Any] | None:
    for step in steps:
        if str(step.get("name", "")) == name:
            return step
    return None


def main() -> int:
    parser = argparse.ArgumentParser(add_help=True)
    parser.add_argument("--repo-root", default=None)
    parser.add_argument("--json", dest="json_path", default=None)
    args = parser.parse_args()

    if args.repo_root is None:
        repo_root = Path(__file__).resolve().parents[2]
    else:
        repo_root = Path(args.repo_root).resolve()
        if not repo_root.is_dir():
            print(f"ERROR  --repo-root is not a readable directory: {args.repo_root}", file=sys.stderr)
            return EXIT_ERROR

    v = Validator(repo_root)
    source = v.read(DEPLOY_WORKFLOW)

    try:
        document = yaml.safe_load(source)
    except yaml.YAMLError as exc:
        print(f"ERROR  {DEPLOY_WORKFLOW} is not valid YAML: {exc}", file=sys.stderr)
        return EXIT_ERROR

    steps = deploy_steps(document)

    print("EruoFood — M44 deployment safety verification")
    print("=" * 78)
    print(f"Repository root: {repo_root}")

    # ------------------------------------------------------------------
    print("\n1. Untrusted input never reaches a shell as code")
    # ------------------------------------------------------------------

    def no_expression_in_run() -> tuple[bool, str]:
        # GitHub expands `${{ }}` when it renders the script, before any shell
        # sees it — so quoting cannot help and a quoted heredoc does not contain
        # it. Values must arrive through `env:`, where they are data.
        offenders = []
        for step in steps:
            run = str(step.get("run", ""))
            for expression in re.findall(r"\$\{\{[^}]+\}\}", run):
                offenders.append(f"{step.get('name', '(unnamed)')}: {expression.strip()}")
        return (not offenders, "; ".join(offenders))

    v.verify(CHECK_NO_EXPRESSION_IN_RUN, "no workflow expression is interpolated into a run: body", no_expression_in_run)

    def ref_passed_as_argument() -> tuple[bool, str]:
        step = named(steps, "Deploy via docker compose over SSH")
        if step is None:
            return False, "the SSH deploy step is missing"

        env = {str(k): str(val) for k, val in (step.get("env") or {}).items()}
        if not any("inputs.ref" in val for val in env.values()):
            return False, "the deploy ref does not reach the step through env:"

        run = code(str(step.get("run", "")))
        if "bash -s --" not in run:
            return False, "the remote script does not receive the ref as a positional argument"

        return True, ""

    v.verify(CHECK_REF_PASSED_AS_ARGUMENT, "the deploy ref reaches the remote host as an argument, not as code", ref_passed_as_argument)

    # ------------------------------------------------------------------
    print("\n2. A step whose failure means 'not deployed' cannot report success")
    # ------------------------------------------------------------------

    def no_masked_deploy_step() -> tuple[bool, str]:
        offenders = []
        for name in CRITICAL_STEP_NAMES:
            step = named(steps, name)
            if step is None:
                offenders.append(f"{name}: step missing")
                continue
            run = code(str(step.get("run", "")))
            for needle, label in MASKING_PATTERNS:
                if needle in run:
                    offenders.append(f"{name}: {label}")
        return (not offenders, "; ".join(offenders))

    v.verify(CHECK_NO_MASKED_DEPLOY_STEP, "no deployment-critical step masks a failure", no_masked_deploy_step)

    def no_continue_on_error() -> tuple[bool, str]:
        offenders = [
            str(step.get("name", "(unnamed)"))
            for step in steps
            if step.get("continue-on-error")
        ]
        for jid, job in (document.get("jobs") or {}).items():
            if job.get("continue-on-error"):
                offenders.append(f"job:{jid}")
        return (not offenders, "; ".join(offenders))

    v.verify(CHECK_NO_CONTINUE_ON_ERROR, "no deploy job or step is continue-on-error", no_continue_on_error)

    # ------------------------------------------------------------------
    print("\n3. Every rolled workload is actually rolled, and verified")
    # ------------------------------------------------------------------

    def rolled_deployments(run: str) -> list[str]:
        return re.findall(r"set image\s+deploy/(\S+)", run)

    def image_roll_unmasked() -> tuple[bool, str]:
        step = named(steps, "Deploy via kubectl")
        if step is None:
            return False, "the kubectl deploy step is missing"

        run = code(str(step.get("run", "")))
        rolled = rolled_deployments(run)
        if not rolled:
            return False, "no deployment image is rolled at all"

        offenders = [
            line.strip()
            for line in run.splitlines()
            if "set image" in line and any(needle in line for needle, _ in MASKING_PATTERNS)
        ]
        return (not offenders, "; ".join(offenders))

    v.verify(CHECK_IMAGE_ROLL_UNMASKED, "a failed image roll fails the deployment", image_roll_unmasked)

    def rollout_covers_every_deployment() -> tuple[bool, str]:
        # The defect this catches is subtler than a missing check: rolling three
        # deployments and verifying one reads as verified. Every workload whose
        # image changed must be waited on.
        step = named(steps, "Deploy via kubectl")
        if step is None:
            return False, "the kubectl deploy step is missing"

        run = code(str(step.get("run", "")))
        rolled = set(rolled_deployments(run))
        if not rolled:
            return False, "no deployment image is rolled at all"

        if "rollout status" not in run:
            return False, "no rollout status check at all"

        # Either each name appears literally, or a loop covers the same set.
        #
        # A loop only counts when `rollout status` is inside ITS OWN body. The
        # first version asked whether `rollout status` appeared anywhere after
        # the loop header, and the negative control caught it immediately: the
        # rollback-target loop above iterates the same three names, so reverting
        # to a single `rollout status deploy/api` still passed. The loop that
        # records what is being replaced is not the loop that verifies it landed.
        literal = set(re.findall(r"rollout status\s+\"?deploy/(\w+)", run))
        looped = set()
        for items, body in re.findall(r"for\s+\w+\s+in\s+([^\n;]+?)\s*;\s*do\n(.*?)\n\s*done", run, re.S):
            if "rollout status" in body:
                looped |= {token.strip().strip("\"'") for token in items.split()}

        uncovered = sorted(rolled - literal - looped)
        return (not uncovered, f"rolled but never verified: {', '.join(uncovered)}" if uncovered else "")

    v.verify(CHECK_ROLLOUT_COVERS_EVERY_DEPLOYMENT, "every deployment that is rolled is also waited on", rollout_covers_every_deployment)

    # ------------------------------------------------------------------
    print("\n4. Migrations run, and a failed migration fails the deploy")
    # ------------------------------------------------------------------

    def migration_manifest_exists() -> tuple[bool, str]:
        step = named(steps, "Deploy via kubectl")
        run = code(str(step.get("run", ""))) if step else ""
        if MIGRATE_JOB not in run:
            return False, f"the deploy step does not apply {MIGRATE_JOB}"

        path = repo_root / MIGRATE_JOB
        if not path.is_file():
            # The original defect exactly: applying a manifest that is not there.
            return False, f"{MIGRATE_JOB} is applied by the workflow but does not exist"

        try:
            manifest = yaml.safe_load(path.read_text(encoding="utf-8"))
        except yaml.YAMLError as exc:
            return False, f"{MIGRATE_JOB} is not valid YAML: {exc}"

        if not isinstance(manifest, dict) or manifest.get("kind") != "Job":
            return False, f"{MIGRATE_JOB} is not a Job manifest"

        return True, ""

    v.verify(CHECK_MIGRATION_MANIFEST_EXISTS, "the migration manifest the workflow applies exists and is a Job", migration_manifest_exists)

    def migration_unmasked() -> tuple[bool, str]:
        step = named(steps, "Deploy via kubectl")
        run = code(str(step.get("run", ""))) if step else ""
        offenders = [
            line.strip()
            for line in run.splitlines()
            if MIGRATE_JOB in line and any(needle in line for needle, _ in MASKING_PATTERNS)
        ]
        return (not offenders, "; ".join(offenders))

    v.verify(CHECK_MIGRATION_UNMASKED, "a failed migration apply fails the deployment", migration_unmasked)

    def migration_is_waited_on() -> tuple[bool, str]:
        # Applying a Job returns as soon as the object is created. Without a
        # wait, a migration that crashes on its first statement is invisible and
        # the new image serves traffic against an un-migrated schema.
        step = named(steps, "Deploy via kubectl")
        run = code(str(step.get("run", ""))) if step else ""
        if "wait --for=condition=complete" not in run:
            return False, "the migration Job is applied but never waited on"
        if "job/" not in run:
            return False, "the wait does not name the migration Job"
        return True, ""

    v.verify(CHECK_MIGRATION_IS_WAITED_ON, "the migration Job is waited on before the deploy is called done", migration_is_waited_on)

    # ------------------------------------------------------------------
    print("\n5. A deployment that cannot be verified is not a success")
    # ------------------------------------------------------------------

    def smoke_test_fails_closed() -> tuple[bool, str]:
        step = named(steps, "Post-deploy smoke test")
        if step is None:
            return False, "the smoke-test step is missing"

        run = code(str(step.get("run", "")))
        if "exit 0" in run:
            return False, "the smoke test can exit 0 without probing anything"
        if "::warning::" in run and "::error::" not in run:
            return False, "a missing STAGING_URL is only a warning"
        if "exit 1" not in run:
            return False, "the smoke test never fails on a missing precondition"
        if "curl -fsS" not in run:
            return False, "the smoke test does not actually probe the deployment"

        return True, ""

    v.verify(CHECK_SMOKE_TEST_FAILS_CLOSED, "an unverifiable deployment fails instead of passing quietly", smoke_test_fails_closed)

    # ------------------------------------------------------------------
    print("\n6. The documented rollback has an input")
    # ------------------------------------------------------------------

    def rollback_target_recorded() -> tuple[bool, str]:
        # ROLLBACK_PLAN §2 says to re-point at "the previous known-good digest,
        # kept in the deploy history". Something has to keep it.
        k8s = named(steps, "Deploy via kubectl")
        ssh = named(steps, "Deploy via docker compose over SSH")

        k8s_run = code(str(k8s.get("run", ""))) if k8s else ""
        ssh_run = code(str(ssh.get("run", ""))) if ssh else ""

        if "jsonpath" not in k8s_run or "containers[0].image" not in k8s_run:
            return False, "the kubectl path does not record the image tags it is replacing"
        if "rev-parse HEAD" not in ssh_run:
            return False, "the SSH path does not record the ref it is replacing"

        return True, ""

    v.verify(CHECK_ROLLBACK_TARGET_RECORDED, "each deploy path records what it is replacing", rollback_target_recorded)

    # ------------------------------------------------------------------
    print("\n7. The validator ran the checks it claims to run")
    # ------------------------------------------------------------------

    def check_count() -> tuple[bool, str]:
        total = v.passed + v.failed + 1  # +1 for this check, not yet counted
        return (total == EXPECTED_CHECKS, "" if total == EXPECTED_CHECKS else f"evaluated {total}, expected {EXPECTED_CHECKS}")

    v.verify(CHECK_CHECK_COUNT, f"exactly {EXPECTED_CHECKS} checks were evaluated", check_count)

    print("\n" + "=" * 78)
    print(f"{v.passed} passed, {v.failed} failed")

    if v.failures:
        print("\nFAILURES")
        for failure in v.failures:
            print(f"  [{failure['id']}] {failure['check']}\n      {failure['detail']}")

    exit_code = EXIT_OK if v.failed == 0 else EXIT_FAIL

    if args.json_path:
        summary = {
            "schema": SUMMARY_SCHEMA,
            "repo_root": str(repo_root),
            "passed": v.passed,
            "failed": v.failed,
            "expected_checks": EXPECTED_CHECKS,
            "exit_code": exit_code,
            "failures": v.failures,
        }
        try:
            Path(args.json_path).write_text(json.dumps(summary, indent=2) + "\n", encoding="utf-8")
        except OSError as exc:
            print(f"ERROR  could not write --json summary: {exc}", file=sys.stderr)
            return EXIT_ERROR

    print(
        "\nDeployment safety verified: every deploy-critical step fails closed."
        if exit_code == EXIT_OK
        else "\nDeployment safety FAILED."
    )

    return exit_code


if __name__ == "__main__":
    raise SystemExit(main())
