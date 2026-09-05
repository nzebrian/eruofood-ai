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


def logical_lines(body: str) -> list[str]:
    """Join backslash continuations so a command that spans several lines is
    judged as one command. Without this, `curl -sS --max-time 30 \\` and the
    URL on the next line are two separate strings, and a rule that needs to see
    both at once silently fails to match either."""
    out, buf = [], ""
    for raw in body.splitlines():
        line = raw.rstrip()
        if line.endswith("\\"):
            buf += line[:-1] + " "
            continue
        out.append(buf + line)
        buf = ""
    if buf:
        out.append(buf)
    return out


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
                # Match the audit command as a command, not as a substring of a
                # path: `npm_audit_resilient.sh` must not count as `npm audit`.
                #
                # Two shapes since M50-10. `npm audit` and `composer audit` are a
                # verb and a subcommand; `osv-scanner` is a single binary, and
                # splitting on a subcommand that is not there used to raise
                # IndexError here — a validator crash, which is a fail-closed but
                # unhelpful way to report a one-word entry.
                parts = command.split()
                if len(parts) >= 2:
                    pattern = re.compile(rf"(?<![\w./-]){re.escape(parts[0])}\s+{re.escape(parts[1])}\b")
                else:
                    # A single-binary command must be matched in COMMAND
                    # POSITION — the start of a line or of a pipeline segment.
                    # `osv-scanner` also appears as an output filename
                    # (`--output osv-scanner`), inside a checksum line, and as an
                    # argument to chmod; treating those as audit call sites made
                    # the provisioning steps look like three ungoverned audits.
                    pattern = re.compile(
                        rf"(?:^|[|;&]\s*|\)\s*)\s*(?:\./|\$\{{[^}}]*\}}/)?{re.escape(parts[0])}\b")
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

    # M50 Phase 1: every workflow, not just the one that happened to have the
    # incident. A probe confirmed a bare `curl https://...` added to
    # security.yml was accepted by every validator while this was scoped to
    # workflow-integrity.yml alone.
    #
    # Not every curl is a download. Health probes against a locally-booted
    # stack fetch no artefact and have nothing to checksum, so they are
    # exempted BY NAME — workflow plus a required substring — rather than by a
    # blanket "curl is fine here" rule.
    exempt = dl.get("non_download_curl_exemptions") or []
    unbounded = []
    for fname in sorted(workflows):
        for jid, _i, sname, run in all_run_blocks(workflows[fname]):
            for line in logical_lines(strip_comments(run)):
                if not re.search(r"(?<![\w./-])curl\b", line):
                    continue
                if any(e.get("workflow") == fname and e.get("match", "") in line for e in exempt):
                    continue
                unbounded.append(f"{fname}:{jid} · {sname} · {line.strip()[:76]}")
    if unbounded:
        for u in unbounded:
            rep.check("download.no_bare_curl", False, f"ungoverned curl — {u}")
    else:
        rep.check("download.no_bare_curl", True,
                  f"no ungoverned curl in any of the {len(workflows)} workflows "
                  f"({len(exempt)} named non-download exemption(s))")

    for wf_rel in dl.get("workflows_requiring_governed_download") or []:
        fname = pathlib.Path(wf_rel).name
        wf = workflows.get(fname)
        if wf is None:
            rep.check("download.workflow_present", False, f"{wf_rel} is named in policy but absent")
            continue
        wrapped, checksums = 0, 0
        for jid, _i, sname, run in all_run_blocks(wf):
            for line in strip_comments(run).splitlines():
                if dl_wrapper and dl_wrapper in line:
                    wrapped += 1
                if "sha256sum" in line and "--check" in line:
                    checksums += 1
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

    # -------------------------------------------------------------------- J --
    print("\nJ) No unexplained masking in any workflow")

    mg = policy.get("masking_governance") or {}
    exemptions = mg.get("exemptions") or []
    deferred = mg.get("deferred_defects") or []

    def _matches(entry, fname, jid, sname, construct):
        if entry.get("workflow") != fname:
            return False
        if entry.get("construct") != construct:
            return False
        if "job" in entry:
            return entry["job"] == jid
        return entry.get("step") == sname

    RUN_MASKS = ("|| true", "|| :", "|| echo", "set +e")
    violations, used, used_deferred = [], set(), set()

    for fname in sorted(workflows):
        wf = workflows[fname]
        for jid, job in (wf.get("jobs") or {}).items():
            # Job-level `if: always()` — the aggregator case.
            if "always()" in str(job.get("if", "")):
                hit = ("if: always()", fname, jid, None)
                ex = next((e for e in exemptions if _matches(e, fname, jid, None, "if: always()")), None)
                if ex:
                    used.add(id(ex))
                else:
                    violations.append(f"{fname}: JOB {jid} carries if: always() with no exemption")

            for step in job.get("steps") or []:
                sname = str(step.get("name", "<unnamed>"))
                found = []
                if "always()" in str(step.get("if", "")):
                    found.append("if: always()")
                if step.get("continue-on-error"):
                    found.append("continue-on-error")
                for line in logical_lines(strip_comments(str(step.get("run") or ""))):
                    for m in RUN_MASKS:
                        if m in line and m not in found:
                            found.append(m)

                for construct in found:
                    ex = next((e for e in exemptions
                               if _matches(e, fname, jid, sname, construct)), None)
                    if ex:
                        used.add(id(ex))
                        continue
                    df = next((d for d in deferred
                               if _matches(d, fname, jid, sname, construct)), None)
                    if df:
                        used_deferred.add(id(df))
                        continue
                    violations.append(
                        f"{fname}:{jid} · {sname} · unexplained {construct}")

    if violations:
        for v in violations:
            rep.check("masking.workflows", False, v)
    else:
        rep.check("masking.workflows", True,
                  f"{len(workflows)} workflows scanned; every masking construct is either "
                  f"absent or covered by one of {len(exemptions)} named exemptions")

    # A stale exemption is a policy that has stopped describing the repository.
    stale = [f'{e.get("workflow")} · {e.get("step", e.get("job"))} · {e.get("construct")}'
             for e in exemptions if id(e) not in used]
    if stale:
        for s in stale:
            rep.check("masking.no_stale_exemption", False,
                      f"exemption matches nothing in the repository: {s}")
    else:
        rep.check("masking.no_stale_exemption", True,
                  f"all {len(exemptions)} exemptions correspond to a real construct")

    # Every exemption must carry a reason. An exemption without one is a
    # wildcard wearing a costume.
    unreasoned = [f'{e.get("workflow")} · {e.get("step", e.get("job"))}'
                  for e in exemptions if not str(e.get("reason", "")).strip()]
    if unreasoned:
        for u in unreasoned:
            rep.check("masking.exemption_has_reason", False, f"exemption with no reason: {u}")
    else:
        rep.check("masking.exemption_has_reason", True,
                  "every masking exemption states a written reason")

    # Deferred defects are NOT exemptions. They are reported every run, by name,
    # so they cannot quietly become the status quo.
    for d in deferred:
        if id(d) not in used_deferred:
            rep.check("masking.deferred_defect_present", False,
                      f"deferred defect no longer matches anything: "
                      f'{d.get("workflow")} · {d.get("step")} — remove the record or restore it')
        else:
            print(f"  NOTE  DEFERRED DEFECT ({d.get('finding')}) — "
                  f"{d.get('workflow')} · {d.get('step')} · {d.get('construct')}")
            print(f"        {str(d.get('blocked_by',''))[:150]}")
    # Emitted whether or not anything is deferred. An empty list is a real
    # result — it says every recorded defect has been fixed — and a check that
    # disappears when it has nothing to complain about is a check nobody
    # notices going missing.
    if not any(id(d) not in used_deferred for d in deferred):
        rep.check("masking.deferred_defect_present", True,
                  f"{len(deferred)} deferred defect(s) recorded, each still present and reported above"
                  if deferred else
                  "no deferred defects recorded — every masked defect has been fixed rather than tolerated")

    # -------------------------------------------------------------------- K --
    print("\nK) Non-npm network operations are bounded")

    nets = {k: v for k, v in (policy.get("network_policies") or {}).items()
            if not k.startswith("_")}

    for eco, spec in nets.items():
        mech = spec.get("mechanism")
        detect = spec.get("detect")
        detect_re = spec.get("detect_regex")
        sites, unbounded = 0, []

        for fname in sorted(workflows):
            wf = workflows[fname]
            for jid, job in (wf.get("jobs") or {}).items():
                env = {}
                env.update(wf.get("env") or {})
                env.update(job.get("env") or {})
                for i, step in enumerate(job.get("steps") or []):
                    body = strip_comments(str(step.get("run") or ""))
                    hit = (detect and detect in body) or (detect_re and re.search(detect_re, body))
                    if not hit:
                        continue
                    sites += 1
                    where = f"{fname}:{jid} step[{i}]"
                    if mech == "job_env":
                        for k, want in (spec.get("required_env") or {}).items():
                            if k not in env:
                                unbounded.append(f"{where} does not set {k}")
                            elif str(env[k]) != str(want):
                                unbounded.append(f"{where} sets {k}={env[k]}, policy requires {want}")
                    elif mech == "step_timeout_minutes":
                        cap = spec.get("required_step_timeout_minutes_max")
                        got = step.get("timeout-minutes")
                        if not isinstance(got, int) or got <= 0:
                            unbounded.append(f"{where} has no step timeout-minutes")
                        elif cap and got > cap:
                            unbounded.append(f"{where} timeout-minutes {got} exceeds the {cap} cap")
                    elif mech == "command_options":
                        for opt in spec.get("required_options") or []:
                            if opt not in body:
                                unbounded.append(f"{where} missing {opt}")
                    else:
                        unbounded.append(f"{where}: policy names unknown mechanism {mech!r}")

        if sites == 0:
            rep.check(f"network.{eco}", False,
                      "policy governs this operation but no call site was found — "
                      "the detector has drifted or the policy is stale")
        elif unbounded:
            for u in unbounded:
                rep.check(f"network.{eco}", False, f"unbounded — {u}")
        else:
            rep.check(f"network.{eco}", True,
                      f"all {sites} call site(s) bounded via {mech}")

    # -------------------------------------------------------------------- L --
    print("\nL) Every workflow declares its concurrency behaviour")

    cg = policy.get("concurrency_governance") or {}
    cancel_exempt = {e["workflow"]: e.get("reason", "")
                     for e in (cg.get("cancel_in_progress_exemptions") or [])}
    missing_group, wrong_cancel = [], []

    for fname in sorted(workflows):
        conc = workflows[fname].get("concurrency")
        if cg.get("group_required") and not (isinstance(conc, dict) and conc.get("group")):
            missing_group.append(fname)
            continue
        if not isinstance(conc, dict):
            continue
        cancels = bool(conc.get("cancel-in-progress"))
        if cancels:
            if fname in cancel_exempt:
                wrong_cancel.append(
                    f"{fname} is recorded as a cancel-in-progress exemption but cancels anyway")
        else:
            if fname not in cancel_exempt:
                wrong_cancel.append(
                    f"{fname} sets cancel-in-progress: false with no recorded reason")

    if missing_group:
        for m in missing_group:
            rep.check("concurrency.group", False, f"{m} declares no concurrency group")
    else:
        rep.check("concurrency.group", True,
                  f"all {len(workflows)} workflows declare a concurrency group")
    if wrong_cancel:
        for w in wrong_cancel:
            rep.check("concurrency.cancel_declared", False, w)
    else:
        rep.check("concurrency.cancel_declared", True,
                  f"cancel-in-progress accounted for everywhere "
                  f"({len(cancel_exempt)} justified exemption(s))")

    # -------------------------------------------------------------------- M --
    print("\nM) Every external action is pinned to an immutable commit")

    ap = policy.get("action_pinning") or {}
    pinned = ap.get("pinned_actions") or {}
    uses_re = re.compile(r'^\s*(?:-\s*)?uses:\s*([^\s#]+)\s*(?:#\s*(.*))?$')
    mutable, unlisted, wrong_sha, no_comment = [], [], [], []
    seen_actions, external_count, local_count = set(), 0, 0

    for fname in sorted(workflows):
        wf_text = (root / ".github/workflows" / fname).read_text()
        for i, raw in enumerate(wf_text.splitlines(), 1):
            m = uses_re.match(raw)
            if not m:
                continue
            ref, comment = m.group(1), (m.group(2) or "").strip()
            if ref.startswith("./"):
                # A local reusable-workflow call has no SHA to pin; it is this
                # repository's own file at this repository's own commit.
                local_count += 1
                if not (root / ref.removeprefix("./")).exists():
                    rep.check("pinning.local_call_resolves", False,
                              f"{fname}:{i} calls {ref}, which does not exist")
                continue
            external_count += 1
            if "@" not in ref:
                mutable.append(f"{fname}:{i} {ref} (no ref at all)")
                continue
            action, at = ref.rsplit("@", 1)
            seen_actions.add(action)
            if not re.fullmatch(r"[0-9a-f]{40}", at):
                mutable.append(f"{fname}:{i} {ref} — mutable ref, expected a 40-character commit SHA")
                continue
            if action not in pinned:
                unlisted.append(f"{fname}:{i} {action} is pinned but absent from action_pinning policy")
                continue
            if pinned[action]["sha"] != at:
                wrong_sha.append(f"{fname}:{i} {action}@{at} does not match policy {pinned[action]['sha']}")
            if comment != pinned[action]["version"]:
                no_comment.append(f"{fname}:{i} {action} comment is {comment!r}, policy says {pinned[action]['version']!r}")

    for bad, cid, label in ((mutable, "pinning.no_mutable_action", "mutable action reference"),
                            (unlisted, "pinning.policy_covers_all", "action missing from policy"),
                            (wrong_sha, "pinning.sha_matches_policy", "SHA disagrees with policy"),
                            (no_comment, "pinning.version_comment", "version comment drift")):
        if bad:
            for b in bad:
                rep.check(cid, False, f"{label} — {b}")
        else:
            ok = {
                "pinning.no_mutable_action":
                    f"all {external_count} external action reference(s) are pinned to a 40-character commit SHA",
                "pinning.policy_covers_all":
                    f"all {len(seen_actions)} distinct external action(s) are declared in policy",
                "pinning.sha_matches_policy":
                    "every pinned SHA matches the one the policy records",
                "pinning.version_comment":
                    "every pin carries the policy's version comment, so a bump is readable in the diff",
            }[cid]
            rep.check(cid, True, ok)

    # A policy entry for an action nobody uses is a policy that has stopped
    # describing the repository — the same staleness rule the masking exemptions
    # are held to.
    stale_pins = sorted(set(pinned) - seen_actions)
    rep.check("pinning.no_stale_entry", not stale_pins,
              "every policy entry corresponds to an action actually used"
              if not stale_pins else f"policy pins actions nothing uses: {', '.join(stale_pins)}")

    # -------------------------------------------------------------------- N --
    print("\nN) No mutable image tag in a governed Docker or Compose file")

    itp = policy.get("image_tag_policy") or {}
    forbidden_tags = itp.get("forbidden_tag_suffixes") or []
    bad_tags, scanned_files = [], 0

    for rel in itp.get("governed_files") or []:
        f = root / rel
        if not f.exists():
            rep.check("image_tag.file_exists", False, f"governed file {rel} does not exist")
            continue
        scanned_files += 1
        for i, raw in enumerate(f.read_text().splitlines(), 1):
            line = raw.split("#", 1)[0]
            if not re.search(r"^\s*(image:|FROM\s)", line):
                continue
            for suffix in forbidden_tags:
                if suffix in line:
                    bad_tags.append(f"{rel}:{i} {line.strip()[:80]} — {suffix} is not reproducible")

    if bad_tags:
        for b in bad_tags:
            rep.check("image_tag.no_mutable", False, b)
    else:
        rep.check("image_tag.no_mutable", True,
                  f"{scanned_files} governed file(s) scanned; none references "
                  f"{' or '.join(forbidden_tags)}")

    # -------------------------------------------------------------------- O --
    print("\nO) Production build files cannot fall back to an unlocked install")

    bfg = policy.get("build_file_governance") or {}
    build_violations, build_files = [], 0
    bf_exemptions = bfg.get("exemptions") or []
    used_bf: set[int] = set()

    for rel in bfg.get("governed_paths") or []:
        f = root / rel
        if not f.exists():
            rep.check("build_file.exists", False, f"governed build file {rel} does not exist")
            continue
        build_files += 1
        for i, raw in enumerate(f.read_text().splitlines(), 1):
            line = raw.split("#", 1)[0]
            if not line.strip():
                continue
            for rule in bfg.get("forbidden_constructs") or []:
                if not re.search(rule["pattern"], line):
                    continue
                # Named exemption, or nothing. Same shape as the workflow
                # masking rule: (file, construct, a substring identifying the
                # exact line, a written reason) or it does not exist. There is
                # deliberately no way to exempt a whole file or a whole
                # construct.
                ex = next((e for e in bf_exemptions
                           if e.get("file") == rel
                           and e.get("construct") == rule["pattern"]
                           and e.get("line_contains", "") in line), None)
                if ex is not None:
                    used_bf.add(id(ex))
                    continue
                build_violations.append(
                    f"{rel}:{i} {line.strip()[:70]} — {rule['reason']}")

    if build_violations:
        for b in build_violations:
            rep.check("build_file.no_unlocked_fallback", False, b)
    else:
        rep.check("build_file.no_unlocked_fallback", True,
                  f"{build_files} production build file(s) install deterministically; every "
                  f"remaining construct is one of {len(bf_exemptions)} named exemption(s)")

    stale_bf = [f'{e.get("file")} · {e.get("line_contains")}'
                for e in bf_exemptions if id(e) not in used_bf]
    if stale_bf:
        for s in stale_bf:
            rep.check("build_file.no_stale_exemption", False,
                      f"build-file exemption matches nothing: {s}")
    else:
        rep.check("build_file.no_stale_exemption", True,
                  f"all {len(bf_exemptions)} build-file exemption(s) correspond to a real line")

    unreasoned_bf = [f'{e.get("file")} · {e.get("line_contains")}'
                     for e in bf_exemptions if not str(e.get("reason", "")).strip()]
    if unreasoned_bf:
        for u in unreasoned_bf:
            rep.check("build_file.exemption_has_reason", False, f"exemption with no reason: {u}")
    else:
        rep.check("build_file.exemption_has_reason", True,
                  "every build-file exemption states a written reason")

    # -------------------------------------------------------------------- P --
    print("\nP) Every governed package installs from a committed lockfile")

    lg = policy.get("lockfile_governance") or {}
    missing_locks, wrong_install = [], []
    forbidden_installs = lg.get("forbidden_install_commands") or []

    for entry in lg.get("required_lockfiles") or []:
        lock = root / entry["lockfile"]
        if not lock.exists():
            missing_locks.append(f"{entry['directory']} has no {entry['lockfile']}")
            continue
        for wf_name in entry.get("workflows") or []:
            if wf_name not in workflows:
                continue
            for jid, _i, sname, run in all_run_blocks(workflows[wf_name]):
                body = strip_comments(run)
                for line in logical_lines(body):
                    for forbidden in forbidden_installs:
                        # `npm install --package-lock-only` writes a lockfile and
                        # installs nothing; it is how the lockfile is produced.
                        if re.search(rf"(?<![\w./-]){re.escape(forbidden)}\b", line) \
                                and "--package-lock-only" not in line:
                            wrong_install.append(
                                f"{wf_name}:{jid} · {sname} · {line.strip()[:70]} — "
                                f"use {entry['install_command']}")

    if missing_locks:
        for m in missing_locks:
            rep.check("lockfile.present", False, m)
    else:
        rep.check("lockfile.present", True,
                  f"all {len(lg.get('required_lockfiles') or [])} governed package(s) commit a lockfile")

    if wrong_install:
        for w in wrong_install:
            rep.check("lockfile.deterministic_install", False, w)
    else:
        rep.check("lockfile.deterministic_install", True,
                  "no governed workflow installs with a range-resolving command")

    # The Dart scan is asserted positively: the governed-command rule in section
    # C can only catch an UNGOVERNED invocation, and the workflow never names
    # osv-scanner directly — the wrapper does. Without this, deleting the step
    # entirely would pass every other check in this file.
    osv_wrapper = "osv_scan_resilient.sh"
    osv_sites = [f"{fn}:{jid} · {sname}"
                 for fn in sorted(workflows)
                 for jid, _i, sname, run in all_run_blocks(workflows[fn])
                 if osv_wrapper in strip_comments(run)
                 and "pubspec.lock" in strip_comments(run)]
    rep.check("audit.dart_covered", bool(osv_sites),
              f"apps/mobile/pubspec.lock is scanned through {osv_wrapper} ({len(osv_sites)} site)"
              if osv_sites else
              f"NO governed Dart advisory scan: no step routes pubspec.lock through {osv_wrapper}")

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
