#!/usr/bin/env python3
"""
EruoFood — control completion manifest verifier.

Answers the question a job conclusion cannot: WHICH controls ran?

`CI · Workflow Integrity` was cancelled at its cap on 2026-09-04 with four
controls still pending. The job went red, so nothing was wrongly reported as
passing — but there was no artefact anywhere saying which controls had produced
evidence and which had simply never started. This closes that: every enforced
control writes a record via `run_control.sh`, and this compares the recorded
set against the mandatory set in `.github/governance/ci-reliability-policy.json`.

The four states a mandatory control can be in:

    PASS          it ran and exited 0
    FAIL          it ran and exited non-zero (not 3)
    UNAVAILABLE   it ran and exited 3 — evidence could not be obtained
    NOT_RUN       no record exists

Only PASS is acceptable. NOT_RUN fails, which is the whole point: absent
evidence is not evidence of absence of a problem.

## What "forged" means here, and what it does not

This is not a cryptographic attestation and does not claim to be. Anything with
write access to the workspace can write a file into the manifest directory.
What the checks below establish is INTERNAL CONSISTENCY — that each record
looks like something `run_control.sh` produced by actually running a control:

  * `verdict` must agree with `exit_code` (PASS requires exit 0). A record
    claiming PASS on a non-zero exit is the obvious forgery and is rejected.
  * `seq` must form a gapless 1..N with no duplicates. A record appended after
    the fact cannot occupy a free slot, and one that reuses a slot collides.
  * the recorded set must equal the mandatory set exactly — no missing entries
    and no unexpected ones.

Usage:
    verify_control_manifest.py [--manifest-dir DIR] [--policy FILE]
"""

from __future__ import annotations

import argparse
import json
import pathlib
import sys

REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]
DEFAULT_POLICY = REPO_ROOT / ".github/governance/ci-reliability-policy.json"
DEFAULT_DIR = REPO_ROOT / ".ci-control-manifest"

VALID_VERDICTS = {"PASS", "FAIL", "UNAVAILABLE"}
REQUIRED_FIELDS = ("control", "seq", "exit_code", "verdict", "started_at", "finished_at")


class Report:
    def __init__(self) -> None:
        self.passed = 0
        self.failures: list[str] = []

    def ok(self, msg: str) -> None:
        print(f"  PASS  {msg}")
        self.passed += 1

    def bad(self, msg: str) -> None:
        print(f"  FAIL  {msg}")
        self.failures.append(msg)


def load_records(manifest_dir: pathlib.Path, rep: Report) -> dict[str, dict]:
    records: dict[str, dict] = {}
    if not manifest_dir.is_dir():
        rep.bad(f"manifest directory {manifest_dir} does not exist — no control recorded running at all")
        return records

    for path in sorted(manifest_dir.glob("*.json")):
        try:
            data = json.loads(path.read_text())
        except (json.JSONDecodeError, OSError) as exc:
            rep.bad(f"{path.name}: unreadable or not valid JSON ({exc})")
            continue

        missing = [f for f in REQUIRED_FIELDS if f not in data]
        if missing:
            rep.bad(f"{path.name}: incomplete record, missing {', '.join(missing)}")
            continue

        name = data["control"]
        if name != path.stem:
            rep.bad(f"{path.name}: record names control '{name}' but the file is '{path.stem}'")
            continue
        if name in records:
            rep.bad(f"duplicate record for control '{name}'")
            continue
        records[name] = data
    return records


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--manifest-dir", default=str(DEFAULT_DIR))
    ap.add_argument("--policy", default=str(DEFAULT_POLICY))
    args = ap.parse_args()

    manifest_dir = pathlib.Path(args.manifest_dir)
    policy_path = pathlib.Path(args.policy)

    print("EruoFood — control completion manifest")
    print("=" * 78)

    rep = Report()

    try:
        policy = json.loads(policy_path.read_text())
    except (json.JSONDecodeError, OSError) as exc:
        print(f"  FAIL  cannot read reliability policy {policy_path}: {exc}")
        print("\nControl manifest verification FAILED.")
        return 1

    mandatory = list(policy.get("control_manifest", {}).get("mandatory_controls") or [])
    if not mandatory:
        print("  FAIL  the policy declares no mandatory controls — nothing would be enforced")
        print("\nControl manifest verification FAILED.")
        return 1

    records = load_records(manifest_dir, rep)

    # --- 1. every mandatory control has a record ----------------------------
    print(f"\nMandatory controls ({len(mandatory)})")
    for name in mandatory:
        rec = records.get(name)
        if rec is None:
            rep.bad(f"{name}: NOT_RUN — no completion record")
            continue
        verdict = rec.get("verdict")
        exit_code = rec.get("exit_code")
        if verdict == "PASS" and exit_code == 0:
            rep.ok(f"{name}: PASS")
        elif verdict not in VALID_VERDICTS:
            rep.bad(f"{name}: unknown verdict {verdict!r}")
        else:
            rep.bad(f"{name}: {verdict} (exit {exit_code})")

    # --- 2. no verdict contradicts its own exit code ------------------------
    print("\nRecord consistency")
    inconsistent = []
    for name, rec in sorted(records.items()):
        code, verdict = rec.get("exit_code"), rec.get("verdict")
        expected = "PASS" if code == 0 else ("UNAVAILABLE" if code == 3 else "FAIL")
        if verdict != expected:
            inconsistent.append(f"{name} claims {verdict} on exit {code} (should be {expected})")
    if inconsistent:
        for line in inconsistent:
            rep.bad(f"verdict contradicts exit code: {line}")
    else:
        rep.ok("every verdict agrees with its own exit code")

    # --- 3. sequence numbers are a gapless 1..N -----------------------------
    seqs = sorted(r.get("seq") for r in records.values() if isinstance(r.get("seq"), int))
    if len(seqs) != len(records):
        rep.bad("at least one record has a non-integer sequence number")
    elif not seqs:
        rep.bad("no sequence numbers recorded")
    elif seqs != list(range(1, len(seqs) + 1)):
        dupes = {s for s in seqs if seqs.count(s) > 1}
        detail = f"duplicated {sorted(dupes)}" if dupes else f"got {seqs}"
        rep.bad(f"sequence numbers are not a gapless 1..{len(seqs)} — {detail}")
    else:
        rep.ok(f"sequence numbers form a gapless 1..{len(seqs)}")

    # --- 4. the recorded set is exactly the mandatory set --------------------
    unexpected = sorted(set(records) - set(mandatory))
    if unexpected:
        for name in unexpected:
            rep.bad(f"unexpected control recorded, not in the policy's mandatory set: {name}")
    else:
        rep.ok("no control ran that the policy does not declare")

    missing = sorted(set(mandatory) - set(records))

    print()
    print("=" * 78)
    print(f"controls declared mandatory : {len(mandatory)}")
    print(f"controls with a record      : {len(records)}")
    print(f"controls NOT_RUN            : {len(missing)}")
    if missing:
        for name in missing:
            print(f"    NOT_RUN  {name}")

    if rep.failures:
        print()
        print(f"{len(rep.failures)} problem(s):")
        for f in rep.failures:
            print(f"  - {f}")
        print()
        print("Control manifest verification FAILED.")
        print("A mandatory control that did not run is not a mandatory control that passed.")
        return 1

    print()
    print(f"{rep.passed} checks passed. Every mandatory control ran to completion and passed.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
