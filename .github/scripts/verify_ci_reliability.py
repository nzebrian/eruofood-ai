#!/usr/bin/env python3
"""
EruoFood — CI reliability policy validator.

Every governance control in this repository before Phase 1 protected what a
gate SAYS. None protected what it COSTS. A gate can be perfectly fail-closed
and still be worthless if it never runs, and on 2026-09-04 that happened twice
in one morning: `Dependency audit` spent 7m01s inside a single `npm audit`
against a lockfile with zero advisories, and `CI · Workflow Integrity` was
cancelled at its cap mid-control, taking four later controls with it.

Neither was a security failure. Both were duration failures, and nothing was
looking at duration.

This reads `.github/governance/ci-reliability-policy.json` and checks:

  A. every job in every workflow declares a valid, in-band timeout-minutes
  B. the policy's job list and the workflows' job list agree exactly
  C. every governed dependency-audit command goes through its approved wrapper
  D. every tool download in a governed workflow goes through the download
     wrapper, and checksum verification is still present
  E. the approved wrappers still exist, are executable, emit all three
     verdicts, and fail closed on UNAVAILABLE
  F. both failure classifiers cover every status and token the policy lists
  G. npm install sites configure npm's retry behaviour explicitly
  H. the integrity job's worst-case duration, recomputed from the policy's own
     component numbers, fits inside its declared timeout
  I. no masking construct has appeared on any governed path

Usage:
    verify_ci_reliability.py [--repo-root DIR] [--json FILE]
"""

from __future__ import annotations

import argparse
import json
import pathlib
import re
import sys

try:
    import yaml
except ImportError:  # pragma: no cover
    print("PyYAML is required", file=sys.stderr)
    sys.exit(2)

MASKING = re.compile(r"\|\|\s*true\b|\|\|\s*:\s*$|\|\|\s*:\s|continue-on-error|set\s+\+e|\bexit\s+0\s*$")


def strip_comments(text: str) -> str:
    """Drop shell comments so a `run:` block's prose cannot satisfy or trip a
    check. M44's lesson, twice over: a validator that greps raw text is fooled
    by the comment explaining the thing it is looking for."""
    out = []
    for line in text.splitlines():
        stripped = line.strip()
        if stripped.startswith("#"):
            continue
        out.append(line.split(" #")[0] if " #" in line else line)
    return "\n".join(out)


class Report:
    def __init__(self) -> None:
        self.checks: list[tuple[str, bool, str]] = []

    def check(self, cid: str, ok: bool, msg: str) -> None:
        self.checks.append((cid, ok, msg))
        print(f"  {'PASS' if ok else 'FAIL'}  {cid}: {msg}")

    @property
    def failed(self) -> list[tuple[str, bool, str]]:
        return [c for c in self.checks if not c[1]]


def load_workflows(root: pathlib.Path) -> dict[str, dict]:
    wfs = {}
    for path in sorted((root / ".github/workflows").glob("*.yml")):
        wfs[path.name] = yaml.safe_load(path.read_text())
    return wfs


def all_run_blocks(wf: dict):
    """Yield (job_id, step_index, step_name, run_text) for every run: step."""
    for jid, job in (wf.get("jobs") or {}).items():
        for i, step in enumerate(job.get("steps") or []):
            run = step.get("run")
            if run:
                yield jid, i, step.get("name", f"step {i}"), str(run)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--repo-root", default=str(pathlib.Path(__file__).resolve().parents[2]))
    ap.add_argument("--json")
    args = ap.parse_args()

    root = pathlib.Path(args.repo_root)
    policy_path = root / ".github/governance/ci-reliability-policy.json"

    print("EruoFood — CI reliability policy")
    print("=" * 78)

    rep = Report()

    try:
        policy = json.loads(policy_path.read_text())
    except (json.JSONDecodeError, OSError) as exc:
        print(f"  FAIL  policy.readable: {policy_path} — {exc}")
        print("\nCI reliability validation FAILED.")
        return 1

    workflows = load_workflows(root)
    # Every governance artefact here carries `_note`/`_meta` keys explaining
    # itself. They are documentation, not entries.
    def entries(d):
        return {k: v for k, v in (d or {}).items() if not k.startswith("_")}

    classes = entries(policy.get("job_timeout_classes"))
    policy_jobs = entries(policy.get("jobs"))
    reusable = set((policy.get("reusable_workflow_calls") or {}).get("ids") or [])

    # ---------------------------------------------------------------- A + B --
    print("\nA) Every job declares a bounded, in-policy timeout")

    actual_jobs: dict[str, dict] = {}
    for fname, wf in workflows.items():
        for jid, job in (wf.get("jobs") or {}).items():
            actual_jobs[f"{fname}:{jid}"] = job

    for key in sorted(actual_jobs):
        job = actual_jobs[key]
        entry = policy_jobs.get(key)

        if key in reusable:
            if "uses" not in job:
                rep.check(f"timeout.{key}", False,
                          "declared a reusable-workflow call in policy but has its own steps")
            elif "timeout-minutes" in job:
                rep.check(f"timeout.{key}", False,
                          "is a reusable-workflow call and cannot carry timeout-minutes")
            else:
                rep.check(f"timeout.{key}", True, "reusable-workflow call; callee jobs are bounded")
            continue

        if entry is None:
            rep.check(f"timeout.{key}", False,
                      "job exists in the workflow but is absent from the reliability policy")
            continue

        cls = entry.get("class")
        if cls not in classes:
            rep.check(f"timeout.{key}", False, f"policy assigns unknown class {cls!r}")
            continue

        declared = job.get("timeout-minutes")
        ceiling = classes[cls]["max_minutes"]

        if declared is None:
            rep.check(f"timeout.{key}", False,
                      f"has no timeout-minutes; GitHub would allow 360 (class {cls} ceiling {ceiling})")
        elif not isinstance(declared, int) or isinstance(declared, bool):
            rep.check(f"timeout.{key}", False, f"timeout-minutes is {declared!r}, not an integer")
        elif declared <= 0:
            rep.check(f"timeout.{key}", False, f"timeout-minutes is {declared}, which is not a bound")
        elif declared > ceiling:
            rep.check(f"timeout.{key}", False,
                      f"timeout-minutes {declared} exceeds the {cls} ceiling of {ceiling}")
        else:
            measured = entry.get("measured_seconds")
            if isinstance(measured, int) and measured > declared * 60:
                rep.check(f"timeout.{key}", False,
                          f"timeout {declared}m is below the measured duration of {measured}s")
            else:
                note = f"measured {measured}s" if isinstance(measured, int) else "no measured run"
                rep.check(f"timeout.{key}", True, f"{declared}m (class {cls}, {note})")

    print("\nB) The policy's job list matches the workflows exactly")
    stale = sorted(set(policy_jobs) - set(actual_jobs))
    if stale:
        for key in stale:
            rep.check("timeout.policy_not_stale", False, f"policy lists a job that no longer exists: {key}")
    else:
        rep.check("timeout.policy_not_stale", True,
                  f"all {len(policy_jobs)} policy entries correspond to a real job")

    # -------------------------------------------------------------------- C --
    print("\nC) Every dependency audit runs through its approved wrapper")

    governed = (policy.get("audit_governance") or {}).get("governed_commands") or {}
    audit_sites = 0
    ungoverned: list[str] = []
    missing_args: list[str] = []

    for fname, wf in workflows.items():
        for jid, _i, sname, run in all_run_blocks(wf):
            body = strip_comments(run)
            for command, spec in governed.items():
                verb = command.split()[0]
                # Match the audit command as a command, not as a substring of a
                # path: `npm_audit_resilient.sh` must not count as `npm audit`.
                pattern = re.compile(rf"(?<![\w./-]){re.escape(verb)}\s+{re.escape(command.split()[1])}\b")
                for line in body.splitlines():
                    if not pattern.search(line):
                        continue
                    audit_sites += 1
                    wrapper = spec["wrapper"]
                    wrapper_base = pathlib.Path(wrapper).name
                    if wrapper_base not in line:
                        ungoverned.append(f"{fname}:{jid} · {sname} · {line.strip()[:88]}")
                        continue
                    for arg in spec.get("required_arguments") or []:
                        if arg not in line:
                            missing_args.append(f"{fname}:{jid} · {sname} · missing {arg}")

    if ungoverned:
        for u in ungoverned:
            rep.check("audit.governed", False, f"ungoverned dependency audit — {u}")
    else:
        rep.check("audit.governed", True,
                  f"all {audit_sites} dependency-audit invocation(s) route through an approved wrapper")

    if missing_args:
        for m in missing_args:
            rep.check("audit.threshold", False, f"audit threshold weakened — {m}")
    else:
        rep.check("audit.threshold", True, "every governed audit still states its required arguments")

    # -------------------------------------------------------------------- D --
    print("\nD) Tool downloads are bounded and still checksum-verified")

    dl = policy.get("download_governance") or {}
    dl_wrapper = pathlib.Path(dl.get("wrapper", "")).name
    for wf_rel in dl.get("workflows_requiring_governed_download") or []:
        fname = pathlib.Path(wf_rel).name
        wf = workflows.get(fname)
        if wf is None:
            rep.check("download.workflow_present", False, f"{wf_rel} is named in policy but absent")
            continue
        bare_curl, wrapped, checksums = [], 0, 0
        for jid, _i, sname, run in all_run_blocks(wf):
            body = strip_comments(run)
            for line in body.splitlines():
                if re.search(r"(?<![\w./-])curl\b", line):
                    bare_curl.append(f"{fname}:{jid} · {sname} · {line.strip()[:80]}")
                if dl_wrapper and dl_wrapper in line:
                    wrapped += 1
                if "sha256sum" in line and "--check" in line:
                    checksums += 1
        if bare_curl:
            for b in bare_curl:
                rep.check("download.no_bare_curl", False, f"unbounded curl — {b}")
        else:
            rep.check("download.no_bare_curl", True, f"{fname}: no bare curl remains")
        if wrapped:
            rep.check("download.wrapped", True, f"{fname}: {wrapped} governed download(s)")
        else:
            rep.check("download.wrapped", False, f"{fname}: no governed download found")
        if dl.get("checksum_verification_required"):
            if checksums >= wrapped and checksums > 0:
                rep.check("download.checksum_verified", True,
                          f"{fname}: {checksums} checksum verification(s) present")
            else:
                rep.check("download.checksum_verified", False,
                          f"{fname}: {checksums} checksum check(s) for {wrapped} download(s)")

    # -------------------------------------------------------------------- E --
    print("\nE) The approved wrappers exist and keep their three-state contract")

    vp = policy.get("verdict_protocol") or {}
    for wrapper_rel in (policy.get("audit_governance") or {}).get("approved_wrappers") or []:
        wpath = root / wrapper_rel
        name = pathlib.Path(wrapper_rel).name
        if not wpath.is_file():
            rep.check(f"wrapper.{name}", False, "does not exist")
            continue
        src = wpath.read_text()
        problems = []
        for verdict in ("PASS", "VULNERABLE", "UNAVAILABLE"):
            if f"SECURITY AUDIT: {verdict}" not in src:
                problems.append(f"never emits {verdict}")
        if vp.get("unavailable_must_be_non_zero"):
            if "EXIT_UNAVAILABLE=3" not in src:
                problems.append("does not define UNAVAILABLE as exit 3")
            if re.search(r"^EXIT_UNAVAILABLE=0\s*$", src, re.M):
                problems.append("defines UNAVAILABLE as exit 0")
        if not (wpath.stat().st_mode & 0o111):
            problems.append("is not executable")
        if problems:
            rep.check(f"wrapper.{name}", False, "; ".join(problems))
        else:
            rep.check(f"wrapper.{name}", True, "three verdicts, UNAVAILABLE non-zero, executable")

    # -------------------------------------------------------------------- F --
    print("\nF) Both failure classifiers cover everything the policy lists")

    retry = policy.get("retry") or {}
    classifier_files = [
        root / ".github/scripts/lib/reliability_classify.sh",
        root / ".github/scripts/npm_audit_resilient.sh",
    ]
    for cpath in classifier_files:
        if not cpath.is_file():
            rep.check(f"classifier.{cpath.name}", False, "missing")
            continue
        # Comments must not count as coverage. The first version of this check
        # read the raw file, and the sentence "A run that hit a 503, retried"
        # in the classifier's own header satisfied the search for "503" — so
        # deleting 503 from the actual pattern passed. That is M44's lesson
        # arriving in the validator written to prevent M44's lesson: a grep
        # over raw text is fooled by the prose explaining the thing it seeks.
        src = strip_comments(cpath.read_text())
        uncovered = []
        for status in retry.get("retryable_http_status") or []:
            if str(status) not in src:
                uncovered.append(str(status))
        for token in retry.get("retryable_network_error_tokens") or []:
            head = token.split()[0]
            if head not in src:
                uncovered.append(token)
        if uncovered:
            rep.check(f"classifier.{cpath.name}", False,
                      f"does not cover {', '.join(uncovered[:6])}")
        else:
            rep.check(f"classifier.{cpath.name}", True,
                      "covers every retryable status and network token in policy")

    # -------------------------------------------------------------------- G --
    print("\nG) npm installation sites configure npm's retry behaviour explicitly")

    npm_policy = policy.get("npm_network_policy") or {}
    env_names = npm_policy.get("env_var_names") or {}
    install_cfg = npm_policy.get("install") or {}
    for key in npm_policy.get("jobs_running_npm_install") or []:
        fname, jid = key.split(":", 1)
        wf = workflows.get(fname)
        job = (wf.get("jobs") or {}).get(jid) if wf else None
        if job is None:
            rep.check(f"npm_retry.{key}", False, "job named in npm policy does not exist")
            continue
        env = {}
        env.update(wf.get("env") or {})
        env.update(job.get("env") or {})
        missing = [
            env_names[k] for k in ("fetch_retries", "fetch_retry_maxtimeout_ms", "fetch_timeout_ms")
            if k in install_cfg and env_names.get(k) and env_names[k] not in env
        ]
        if missing:
            rep.check(f"npm_retry.{key}", False,
                      f"does not pin {', '.join(missing)} — npm's 60s-per-retry default applies")
        else:
            rep.check(f"npm_retry.{key}", True, "npm fetch retry/timeout pinned explicitly")

    # -------------------------------------------------------------------- H --
    print("\nH) The integrity job's worst case fits inside its own timeout")

    budget = policy.get("workflow_integrity_budget") or {}
    comps = budget.get("components_seconds") or {}
    total = 0
    for cname, c in comps.items():
        if "value" in c:
            total += int(c["value"])
        else:
            total += int(c.get("count", 0)) * int(c.get("worst_case_each", 0))

    declared_total = budget.get("computed_worst_case_seconds")
    if declared_total != total:
        rep.check("budget.arithmetic", False,
                  f"policy states worst case {declared_total}s but its components sum to {total}s")
    else:
        rep.check("budget.arithmetic", True, f"components sum to the declared {total}s")

    bkey = budget.get("job", "")
    bjob = actual_jobs.get(bkey)
    declared_timeout = (bjob or {}).get("timeout-minutes")
    if declared_timeout is None:
        rep.check("budget.fits", False, f"{bkey} has no timeout-minutes to compare against")
    else:
        cap = int(declared_timeout) * 60
        if total >= cap:
            rep.check("budget.fits", False,
                      f"worst case {total}s meets or exceeds the {declared_timeout}m ({cap}s) cap")
        else:
            rep.check("budget.fits", True,
                      f"worst case {total}s vs {declared_timeout}m cap — {cap - total}s headroom")
        if budget.get("timeout_minutes") != declared_timeout:
            rep.check("budget.declared_matches", False,
                      f"policy says {budget.get('timeout_minutes')}m, workflow says {declared_timeout}m")
        else:
            rep.check("budget.declared_matches", True,
                      f"policy and workflow agree on {declared_timeout}m")

    # -------------------------------------------------------------------- I --
    print("\nI) No masking construct on a governed path")

    governed_paths = [root / p for p in (
        ".github/scripts/npm_audit_resilient.sh",
        ".github/scripts/composer_audit_resilient.sh",
        ".github/scripts/bounded_download.sh",
        ".github/scripts/run_control.sh",
        ".github/scripts/lib/reliability_classify.sh",
    )]
    hits = []
    for gp in governed_paths:
        if not gp.is_file():
            hits.append(f"{gp.name}: missing")
            continue
        for n, line in enumerate(gp.read_text().splitlines(), 1):
            if line.lstrip().startswith("#"):
                continue
            if MASKING.search(line):
                hits.append(f"{gp.name}:{n}: {line.strip()[:70]}")
    if hits:
        for h in hits:
            rep.check("masking.governed_paths", False, h)
    else:
        rep.check("masking.governed_paths", True,
                  f"none of the {len(governed_paths)} governed scripts masks a failure")

    # Manifest wiring: the runner must propagate, never rewrite, the exit code.
    runner = root / ".github/scripts/run_control.sh"
    if runner.is_file():
        src = runner.read_text()
        if 'exit "$exit_code"' in src and "exit 0" not in re.sub(r"#.*", "", src):
            rep.check("manifest.runner_propagates", True,
                      "run_control.sh exits with the control's own status and never forces 0")
        else:
            rep.check("manifest.runner_propagates", False,
                      "run_control.sh does not provably propagate the control's exit code")
    else:
        rep.check("manifest.runner_propagates", False, "run_control.sh is missing")

    # -------------------------------------------------------------------------
    total_checks = len(rep.checks)
    failed = rep.failed

    if args.json:
        pathlib.Path(args.json).write_text(json.dumps({
            "passed": total_checks - len(failed),
            "failed": len(failed),
            "failures": [{"id": c[0], "message": c[2]} for c in failed],
        }, indent=2))

    print()
    print("=" * 78)
    if failed:
        print(f"{total_checks - len(failed)}/{total_checks} checks passed, {len(failed)} FAILED")
        print()
        print("CI reliability validation FAILED.")
        return 1
    print(f"{total_checks}/{total_checks} checks passed. Every CI operation is bounded, "
          f"every audit is governed, and the integrity budget provably fits.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
