#!/usr/bin/env python3
"""M46 — what can this repository's CI actually do, and with whose words?

## The two gaps this closes

**1. Implicit token permissions.** Nine of the fourteen workflows declared no
`permissions:` block at all. A workflow that declares none inherits the
repository's *default workflow permissions* — live GitHub state, invisible from
here, settable to "read and write" with one administrator toggle. Measured
during M46 discovery, that default is currently read-only:

    ##[group]GITHUB_TOKEN Permissions
    Contents: read
    Metadata: read
    Packages: read

taken from a non-Dependabot run of `ci-web.yml`, which declared nothing. So this
was never an active escalation — and that is exactly the problem. The protection
lived somewhere no file in this repository could state, no check could assert,
and one toggle could remove, at which point nine workflows that run `npm ci`,
`composer install`, `flutter pub get` and `docker compose build` on every pull
request would receive write-scoped tokens with nothing here to notice.

That is this repository's recurring shape: M29-A (a path filter that made a
required check unreportable), M37 (governance asserted in prose, never against
live state), M44 (guards on the workflows that *test*, none on the one that
*deploys*). Declared in the workflow, the scope cannot drift.

**2. Expression interpolation into `run:` blocks.** GitHub expands `${{ }}`
when it RENDERS the script, before any shell parses it, so an attacker-
influenced context reaches the shell as *source*, not as data. Quoting does not
help — the quotes are rendered too. `release.yml` carried
`${{ github.ref_name }}` inside a `run:` block in a job that also held
`packages: write`; git ref names legally contain `` ` ``, `$`, `;`, `&` and `|`.
M44 removed this class from `staging-deploy.yml` and wrote a guard that reads
only that file, which is why it survived three workflows away.

## What this validator does not claim

It reads workflow YAML. It cannot see, and never asserts, the repository's live
default-permission setting, its rulesets, or anything else GitHub holds — see
`docs/WORKFLOW_INTEGRITY.md` for that distinction. What it guarantees is that
every workflow states its own privilege, that none states more than it needs,
and that no attacker-influenced expression is compiled into a shell script.

Usage:
  .github/scripts/verify_workflow_privilege.py [--repo-root=DIR] [--json=FILE]

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

WORKFLOW_DIR = ".github/workflows"

# The complete expected set, with the exact privilege each workflow may hold.
#
# This is a closed world on purpose. A workflow that disappears and one that
# appears are both failures: the first means a gate was deleted, the second
# means code is running in CI that nobody granted a scope to. Neither should be
# discoverable only by reading a diff.
#
# `reason` is required for, and only for, any scope beyond `contents: read`. It
# lives here rather than in a workflow comment because a validator that reads
# comments can be satisfied by a comment.
BASELINE = {"contents": "read"}

EXPECTED_WORKFLOWS: dict[str, dict[str, object]] = {
    "ci-api.yml": {"permissions": BASELINE},
    "ci-docker.yml": {"permissions": BASELINE},
    "ci-mobile.yml": {"permissions": BASELINE},
    "ci-web.yml": {"permissions": BASELINE},
    "contracts.yml": {"permissions": BASELINE},
    "ga-docker-certification.yml": {"permissions": BASELINE},
    "ga-flutter-certification.yml": {"permissions": BASELINE},
    "ga-release-certification.yml": {"permissions": BASELINE},
    "governance-advisory.yml": {"permissions": BASELINE},
    "performance-certification.yml": {"permissions": BASELINE},
    "release.yml": {"permissions": BASELINE},
    "security.yml": {"permissions": BASELINE},
    "staging-deploy.yml": {
        "permissions": {"contents": "read", "packages": "write"},
        "reason": (
            "Publishes container images. `build-images` runs docker/login-action "
            "against the configured registry and two docker/build-push-action "
            "steps with push: true. This is the only workflow that pushes a "
            "package; release.yml builds with push: false and holds no such scope."
        ),
    },
    "workflow-integrity.yml": {"permissions": BASELINE},
}

EXPECTED_WORKFLOW_COUNT = 14

# Scopes that must never appear at write level unless EXPECTED_WORKFLOWS says so.
WRITE_SCOPES = (
    "contents", "packages", "actions", "issues", "pull-requests", "deployments",
    "id-token", "security-events", "statuses", "checks", "discussions", "pages",
    "repository-projects", "attestations",
)

# Contexts an outside party can influence. `matrix.*` is defined by the workflow
# itself, `github.workspace` is the runner's own path, and `secrets.*` is not
# attacker-supplied — none of those are this defect.
TAINTED_CONTEXTS = (
    "github.event.",
    "github.head_ref",
    "github.ref_name",
    "github.ref",
    "inputs.",
    "github.actor",
    "github.triggering_actor",
)

EXPRESSION = re.compile(r"\$\{\{\s*([^}]+?)\s*\}\}")

# Per workflow: declares_permissions, least_privilege, no_job_widening.
# Global: workflow_set_exact, expected_count, no_forbidden_scope,
#         no_tainted_interpolation, elevation_documented.
EXPECTED_CHECKS = EXPECTED_WORKFLOW_COUNT * 3 + 5


class Report:
    def __init__(self) -> None:
        self.results: list[dict[str, object]] = []

    def check(self, check_id: str, ok: bool, detail: str) -> None:
        self.results.append({"id": check_id, "ok": bool(ok), "detail": detail})

    @property
    def failures(self) -> list[dict[str, object]]:
        return [r for r in self.results if not r["ok"]]


def slug(name: str) -> str:
    return Path(name).stem


def code_of(run: str) -> str:
    """Executable lines only.

    M44's lesson, twice over: a validator that greps raw `run:` text fails on a
    comment quoting the defect, and — the half that matters — is *satisfied* by
    a comment quoting the fix.
    """
    return "\n".join(l for l in run.splitlines() if not l.lstrip().startswith("#"))


def normalise(perms: object) -> object:
    """`permissions: read-all` / `write-all` are strings, not mappings."""
    return perms


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
    wf_dir = repo_root / WORKFLOW_DIR

    present = sorted(p.name for p in wf_dir.glob("*.yml")) if wf_dir.is_dir() else []
    expected = sorted(EXPECTED_WORKFLOWS)

    missing = [n for n in expected if n not in present]
    unexpected = [n for n in present if n not in EXPECTED_WORKFLOWS]

    detail = "the workflow set is exactly as recorded"
    if missing or unexpected:
        parts = []
        if missing:
            parts.append(f"missing {missing}")
        if unexpected:
            parts.append(
                f"unexpected {unexpected} — a workflow nobody granted a scope to; "
                "add it to EXPECTED_WORKFLOWS with its minimum privilege"
            )
        detail = "; ".join(parts)
    report.check("privilege.workflow_set_exact", not (missing or unexpected), detail)

    report.check(
        "privilege.expected_count",
        len(present) == EXPECTED_WORKFLOW_COUNT,
        f"{len(present)} workflows present, {EXPECTED_WORKFLOW_COUNT} expected",
    )

    forbidden: list[str] = []
    tainted: list[str] = []
    undocumented: list[str] = []

    for name in expected:
        s = slug(name)
        path = wf_dir / name
        spec = EXPECTED_WORKFLOWS[name]
        want = spec["permissions"]

        if not path.is_file():
            for suffix in ("declares_permissions", "least_privilege", "no_job_widening"):
                report.check(f"privilege.{s}.{suffix}", False, f"{name} is missing")
            continue

        try:
            doc = yaml.safe_load(path.read_text(encoding="utf-8"))
            if not isinstance(doc, dict):
                raise ValueError("top level is not a mapping")
        except Exception as exc:  # noqa: BLE001 - any parse problem is a failure
            for suffix in ("declares_permissions", "least_privilege", "no_job_widening"):
                report.check(f"privilege.{s}.{suffix}", False, f"{name}: {exc}")
            continue

        # YAML 1.1 resolves the bare word `on` to the boolean True, so PyYAML
        # keys the trigger block under True. Nothing below needs it, but the
        # same trap would silently void a trigger check, so it is handled where
        # a future check would look for it.
        _ = doc.get("on", doc.get(True))

        perms = normalise(doc.get("permissions"))
        declared = "permissions" in doc
        report.check(
            f"privilege.{s}.declares_permissions",
            declared,
            "declares its own privilege" if declared
            else "no `permissions:` block — the token scope falls back to the "
                 "repository default, which is live GitHub state this repository "
                 "cannot see or assert",
        )

        matches = perms == want
        report.check(
            f"privilege.{s}.least_privilege",
            matches,
            f"privilege is exactly {want}" if matches
            else f"privilege is {perms!r}, expected {want!r}",
        )

        # A job-level block replaces the workflow-level one outright, so a job
        # can widen past a correct top-level policy.
        widened = []
        for jk, job in (doc.get("jobs") or {}).items():
            if not isinstance(job, dict):
                continue
            jp = job.get("permissions")
            if jp is None:
                continue
            if isinstance(jp, str) or jp != want:
                widened.append(f"{jk}={jp!r}")
        report.check(
            f"privilege.{s}.no_job_widening",
            not widened,
            "no job overrides the workflow privilege" if not widened
            else "job-level overrides: " + ", ".join(widened),
        )

        # --- global accumulators -------------------------------------------
        if isinstance(perms, str):
            if perms != "read-all":
                forbidden.append(f"{name}: `permissions: {perms}`")
        elif isinstance(perms, dict):
            for scope, level in perms.items():
                if level == "write" and (
                    not isinstance(want, dict) or want.get(scope) != "write"
                ):
                    forbidden.append(f"{name}: {scope}: write")

        if isinstance(want, dict) and any(v == "write" for v in want.values()):
            if not str(spec.get("reason", "")).strip():
                undocumented.append(name)

        for jk, job in (doc.get("jobs") or {}).items():
            if not isinstance(job, dict):
                continue
            for step in job.get("steps") or []:
                if not isinstance(step, dict):
                    continue
                run = step.get("run")
                if not run:
                    continue
                for m in EXPRESSION.finditer(code_of(str(run))):
                    expr = m.group(1)
                    if any(t in expr for t in TAINTED_CONTEXTS):
                        tainted.append(
                            f"{name}::{jk}::{step.get('name') or '(unnamed)'} -> "
                            f"${{{{ {expr} }}}}"
                        )

    report.check(
        "privilege.no_forbidden_scope",
        not forbidden,
        "no workflow holds a write scope it was not granted" if not forbidden
        else "; ".join(forbidden),
    )

    report.check(
        "privilege.elevation_documented",
        not undocumented,
        "every elevated privilege carries a recorded reason" if not undocumented
        else f"elevated with no reason recorded: {undocumented}",
    )

    report.check(
        "privilege.no_tainted_interpolation",
        not tainted,
        "no attacker-influenced expression reaches a run: block" if not tainted
        else "expression compiled into shell source — route it through `env:`: "
             + "; ".join(tainted),
    )

    print("EruoFood — M46 workflow privilege & injection")
    print("=" * 78)
    for r in report.results:
        print(f"  {'PASS' if r['ok'] else 'FAIL'}  {r['id']}: {r['detail']}")

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

    print(f"{counted}/{counted} checks passed. Every workflow states its own "
          "privilege, and no expression is compiled into a shell script.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
