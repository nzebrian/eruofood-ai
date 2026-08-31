#!/usr/bin/env python3
"""M44 — can the required CI contexts be made to report on `main` on demand?

## The gap this closes

`ci-api.yml`, `ci-web.yml` and `contracts.yml` each filter their `push` trigger
by path. That is deliberate and M29-A kept it on purpose: post-merge runs should
be narrow. The consequence only surfaced when M44's post-merge verification went
looking for evidence.

A merge whose file list touches none of a workflow's `push.paths` produces **no
run of that workflow at the merge commit**. The jobs it owns — `Tests · SQLite`,
`Lint · Analyse · Test`, `Lint · Typecheck · Test · Build`, `Lint spec ·
Generate types`, all four REQUIRED contexts — therefore have no conclusion on
`main` at that SHA. Not red. Absent. Merge protection is unaffected, because it
is evaluated against the pull request; what is affected is anybody who has to
*state* that the required contexts pass on `main` as it now stands, and whose
only alternatives were an empty-ish commit touching a filtered path, or
inferring the answer from a run against a different tree.

`workflow_dispatch` fixes that, and this validator asserts the fix stays fixed:
the trigger is present, it carries no inputs, and — the part that matters more —
that adding it changed nothing else. A dispatch trigger bought at the cost of a
widened `push` filter, a renamed required job, or a `pull_request` that has
quietly regained a path filter would be a net loss.

## What it does not claim

Nothing here proves a run happened, or that GitHub accepted the dispatch. It
reads files. Whether the four contexts are green on `main` is answered by the
Actions tab, not by this script.

Usage:
  .github/scripts/verify_ci_dispatchability.py [--repo-root=DIR] [--json=FILE]

Exit codes: 0 all checks pass, 1 one or more failed, 3 misinvocation.
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

try:
    import yaml
except ImportError:  # pragma: no cover - environment problem, not a safety failure
    print("PyYAML is required: pip install pyyaml", file=sys.stderr)
    raise SystemExit(3)

# Every check this file can emit. Asserted at the end, so a check that is
# accidentally dropped (an early `return`, a mistyped id) fails the run instead
# of shrinking the suite silently — the M42 lesson.
EXPECTED_CHECKS = 14

# The frozen expectation. Job names are the REQUIRED status-check contexts and
# are matched byte for byte by GitHub; five of the strings below contain U+00B7
# MIDDLE DOT. Copy them, never retype them. `push_paths` is recorded here so
# that widening or dropping a filter is a diff in this file too, not a silent
# behaviour change in a workflow.
WORKFLOWS: dict[str, dict[str, object]] = {
    ".github/workflows/ci-api.yml": {
        "jobs": ["Tests · SQLite", "Lint · Analyse · Test"],
        "push_paths": ["apps/api/**", ".github/workflows/ci-api.yml"],
    },
    ".github/workflows/ci-web.yml": {
        "jobs": ["Lint · Typecheck · Test · Build"],
        "push_paths": [
            "apps/web/**",
            "packages/api-contracts/**",
            ".github/workflows/ci-web.yml",
        ],
    },
    ".github/workflows/contracts.yml": {
        "jobs": ["Lint spec · Generate types"],
        "push_paths": ["packages/api-contracts/**", ".github/workflows/contracts.yml"],
    },
}

REQUIRED_CHECKS_JSON = ".github/governance/required-checks.json"


class Report:
    def __init__(self) -> None:
        self.results: list[dict[str, object]] = []

    def check(self, check_id: str, ok: bool, detail: str) -> None:
        self.results.append({"id": check_id, "ok": bool(ok), "detail": detail})

    @property
    def failures(self) -> list[dict[str, object]]:
        return [r for r in self.results if not r["ok"]]


def triggers_of(document: object, path: str) -> dict[str, object]:
    """Return the `on:` mapping.

    YAML 1.1 resolves the bare word `on` to the boolean True, so PyYAML keys
    this block under `True` rather than the string. A validator that looked only
    for `"on"` would find nothing and pass every workflow vacuously.
    """
    if not isinstance(document, dict):
        raise ValueError(f"{path}: top level is not a mapping")

    for key in ("on", True):
        if key in document:
            block = document[key]
            if not isinstance(block, dict):
                raise ValueError(f"{path}: `on:` is not a mapping of triggers")
            return block

    raise ValueError(f"{path}: no `on:` block")


def job_names(document: object, path: str) -> list[str]:
    jobs = document.get("jobs") if isinstance(document, dict) else None
    if not isinstance(jobs, dict):
        raise ValueError(f"{path}: no `jobs:` mapping")

    names = []
    for key, job in jobs.items():
        # A job with no `name:` reports under its key. Recording the fallback
        # rather than skipping it keeps the job-set comparison honest.
        names.append(job.get("name", key) if isinstance(job, dict) else key)
    return names


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
    observed_jobs: list[str] = []

    for rel, expected in WORKFLOWS.items():
        slug = Path(rel).stem
        path = repo_root / rel

        if not path.is_file():
            for suffix in ("dispatchable", "dispatch_has_no_inputs",
                           "push_paths_retained", "pull_request_unfiltered"):
                report.check(f"ci.{slug}.{suffix}", False, f"{rel} is missing")
            continue

        try:
            document = yaml.safe_load(path.read_text(encoding="utf-8"))
            triggers = triggers_of(document, rel)
            observed_jobs.extend(job_names(document, rel))
        except Exception as exc:  # noqa: BLE001 - any parse problem is a failure
            for suffix in ("dispatchable", "dispatch_has_no_inputs",
                           "push_paths_retained", "pull_request_unfiltered"):
                report.check(f"ci.{slug}.{suffix}", False, f"{rel}: {exc}")
            continue

        # 1. The trigger exists. `workflow_dispatch:` with an empty body parses
        #    to None, so presence of the KEY is the question, not truthiness.
        dispatchable = "workflow_dispatch" in triggers
        report.check(
            f"ci.{slug}.dispatchable",
            dispatchable,
            "workflow_dispatch present" if dispatchable
            else "no workflow_dispatch trigger: the required contexts in this "
                 "workflow cannot be made to report on main on demand",
        )

        # 2. And carries no inputs. An input reaching a `run:` block through
        #    `${{ }}` is the injection surface M44 spent its time removing from
        #    staging-deploy.yml; nothing here varies per run, so nothing here
        #    needs an input.
        dispatch_body = triggers.get("workflow_dispatch") if dispatchable else None
        inputs = dispatch_body.get("inputs") if isinstance(dispatch_body, dict) else None
        report.check(
            f"ci.{slug}.dispatch_has_no_inputs",
            not inputs,
            "no dispatch inputs" if not inputs
            else f"workflow_dispatch declares inputs {sorted(inputs)}; an input "
                 "spliced into a run: block is a shell-injection surface",
        )

        # 3. The push filter is untouched. The point of the change was to add a
        #    way to run these jobs on demand, NOT to widen what runs on every
        #    merge. Silently dropping `paths:` would do the latter.
        push = triggers.get("push")
        actual_paths = push.get("paths") if isinstance(push, dict) else None
        expected_paths = expected["push_paths"]
        report.check(
            f"ci.{slug}.push_paths_retained",
            actual_paths == expected_paths,
            "push paths unchanged" if actual_paths == expected_paths
            else f"push.paths is {actual_paths!r}, expected {expected_paths!r}",
        )

        # 4. And `pull_request` still has none. M29-A removed it because GitHub
        #    treats a required check that never reports as PENDING rather than
        #    satisfied; a path filter creeping back here would leave unrelated
        #    pull requests waiting forever for a conclusion never coming.
        pull_request = triggers.get("pull_request")
        unfiltered = (
            "pull_request" in triggers
            and (pull_request is None or "paths" not in pull_request)
        )
        report.check(
            f"ci.{slug}.pull_request_unfiltered",
            unfiltered,
            "pull_request unfiltered" if unfiltered
            else "pull_request is absent or has regained a paths filter (M29-A)",
        )

    # 5. The required job names still exist, byte for byte, in the workflow that
    #    owns each. GitHub matches a required status check on the JOB name; a
    #    rename detaches the rule from the job and the context goes permanently
    #    pending, with no error anywhere.
    expected_jobs = sorted(n for w in WORKFLOWS.values() for n in w["jobs"])
    same_set = sorted(observed_jobs) == expected_jobs
    report.check(
        "ci.required_job_set_unchanged",
        same_set,
        "the four required job names are exactly as before" if same_set
        else f"job set is {sorted(observed_jobs)!r}, expected {expected_jobs!r}",
    )

    # 6. And the ratchet agrees. `required-checks.json` is the hand-maintained
    #    record of what main's ruleset requires; if it and this file drift, one
    #    of the two is lying about which contexts matter.
    ratchet = repo_root / REQUIRED_CHECKS_JSON
    try:
        data = json.loads(ratchet.read_text(encoding="utf-8"))
        declared = sorted(
            entry["context"]
            for entry in data["required"]
            if entry.get("workflow") in WORKFLOWS
        )
        agrees = declared == expected_jobs
        detail = ("required-checks.json names the same four contexts" if agrees
                  else f"required-checks.json says {declared!r}, this file says "
                       f"{expected_jobs!r}")
    except Exception as exc:  # noqa: BLE001
        agrees, detail = False, f"{REQUIRED_CHECKS_JSON}: {exc}"
    report.check("ci.ratchet_agrees_with_required_checks", agrees, detail)

    print("EruoFood — M44 CI dispatchability")
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
                {
                    "checks": counted,
                    "failures": report.failures,
                    "results": report.results,
                },
                indent=2,
            ),
            encoding="utf-8",
        )

    if report.failures:
        print(f"{len(report.failures)}/{counted} checks FAILED.")
        return 1

    print(f"{counted}/{counted} checks passed. The required CI contexts are "
          "dispatchable, and nothing else moved.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
