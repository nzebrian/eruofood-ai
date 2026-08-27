#!/usr/bin/env python3
"""Render the Governance Advisory job summary from structured JSON only.

Nothing here parses console output. The validator and the ratchet both emit
machine-readable documents precisely so that the thing a human reads and the
thing CI decides on come from the same source; a summary built by scraping
stdout would drift from the verdict the moment somebody reworded a message.

Reads:
  --summary          the validator's --json output (schema 2+)
  --ratchet          the ratchet's --json output (schema 1)
  --evidence-status  what each endpoint fetch actually did
  --known-gaps       the record, for the human-readable reason behind each gap

Writes GitHub-flavoured Markdown to stdout. Never exits non-zero on a red
verdict: this reports, it does not judge. It exits non-zero only when a file it
was told to read is missing or unusable, because a summary that silently
renders nothing would hide the run rather than explain it.
"""

from __future__ import annotations

import argparse
import json
import sys

VERDICT_BADGE = {
    "match": ("&#9989;", "MATCH", "the observed failure set is exactly the recorded one"),
    "mismatch": ("&#10060;", "MISMATCH", "governance state differs from the record"),
    "incomplete": ("&#9888;&#65039;", "INCOMPLETE", "verification could not be completed"),
    "error": ("&#128165;", "ERROR", "the ratchet could not run"),
}


def load(label: str, path: str | None) -> dict:
    if not path:
        return {}
    try:
        with open(path, encoding="utf-8") as fh:
            data = json.load(fh)
    except (OSError, json.JSONDecodeError) as exc:
        print(f"could not read {label} ({path}): {exc}", file=sys.stderr)
        raise SystemExit(2) from exc
    if not isinstance(data, dict):
        print(f"{label} ({path}) is not a JSON object", file=sys.stderr)
        raise SystemExit(2)
    return data


def bullet_list(ids: list, empty: str = "_none_") -> str:
    if not ids:
        return empty
    return "\n".join(f"- `{i}`" for i in ids)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--summary", required=True)
    ap.add_argument("--ratchet", required=True)
    ap.add_argument("--evidence-status")
    ap.add_argument("--known-gaps")
    args = ap.parse_args()

    summary = load("validator summary", args.summary)
    ratchet = load("ratchet result", args.ratchet)
    evidence = load("evidence status", args.evidence_status)
    record = load("known-gaps record", args.known_gaps)

    verdict = str(ratchet.get("verdict", "error"))
    icon, label, gloss = VERDICT_BADGE.get(verdict, VERDICT_BADGE["error"])

    out: list[str] = []
    w = out.append

    w("## Governance Advisory")
    w("")
    w(f"### {icon} Ratchet verdict: **{label}**")
    w("")
    w(f"> {ratchet.get('reason', gloss)}")
    w("")
    w("This check is **advisory**: it is not in the `main` ruleset and blocks no")
    w("merge. It is still capable of failing, and a red run means something here")
    w("needs a human.")
    w("")

    # -- Where the numbers came from -----------------------------------------
    w("### Validator")
    w("")
    w("| | |")
    w("|---|---:|")
    w(f"| mode | `{summary.get('mode', '?')}` |")
    w(f"| checks total | {summary.get('total', '?')} |")
    w(f"| passed | {summary.get('passed', '?')} |")
    w(f"| **failed** | **{summary.get('failed', '?')}** |")
    w(f"| external / unverified | {summary.get('external_unverified', '?')} |")
    w(f"| skipped (policy) | {summary.get('skipped', '?')} |")
    w(f"| verification complete | `{summary.get('verification_complete', '?')}` |")
    w(f"| exit | `{summary.get('exit_code', '?')}` — `{summary.get('exit_reason', '?')}` |")
    w("")
    w("A non-zero validator exit is expected here: two failures are recorded and")
    w("accepted. The ratchet below is what decides whether this job is green.")
    w("")

    # -- The four sets the ratchet compared ----------------------------------
    w("### What the ratchet compared")
    w("")
    w("<table><tr><th>Expected failures (recorded)</th><th>Observed failures (live)</th></tr>")
    w("<tr><td>\n")
    w(bullet_list(ratchet.get("expected_failures", [])))
    w("\n</td><td>\n")
    w(bullet_list(ratchet.get("observed_failures", [])))
    w("\n</td></tr></table>")
    w("")

    unexpected = ratchet.get("unexpected_failures", [])
    stale = ratchet.get("stale_recorded_gaps", [])
    worse = ratchet.get("unexpected_unverified", [])

    if unexpected:
        w("#### &#10060; Unexpected failures — nobody has accepted these")
        w("")
        w(bullet_list(unexpected))
        w("")
        w("Either fix the governance problem, or record it deliberately in")
        w("`.github/governance/known-gaps.json` with a reason and an approver.")
        w("")

    if stale:
        w("#### &#10060; Stale recorded gaps — recorded, but no longer failing")
        w("")
        w(bullet_list(stale))
        w("")
        w("Good news that still needs an action: delete these entries from")
        w("`.github/governance/known-gaps.json`. A record of accepted risk that")
        w("no longer matches reality is the document somebody will trust later.")
        w("")

    if worse:
        w("#### &#9888;&#65039; Verification got worse")
        w("")
        w(bullet_list(worse))
        w("")
        w("These were expected to be answerable and were not answered on this")
        w("run. That is incomplete verification, **not** governance drift.")
        w("")

    # -- Evidence -------------------------------------------------------------
    endpoints = evidence.get("endpoints", {}) if isinstance(evidence, dict) else {}
    if endpoints:
        w("### Evidence fetched")
        w("")
        w("| endpoint | status | detail |")
        w("|---|---|---|")
        for name, detail in sorted(endpoints.items()):
            state = (detail or {}).get("status", "?")
            note = (detail or {}).get("detail", "")
            mark = "&#9989;" if state == "ok" else "&#9888;&#65039;"
            w(f"| `{name}` | {mark} `{state}` | {note} |")
        w("")
        if any((d or {}).get("status") != "ok" for d in endpoints.values()):
            w("> An endpoint that could not be read is reported as **incomplete")
            w("> verification**. It is never rendered as a governance failure —")
            w("> \"we could not find out\" and \"it is broken\" are different facts.")
            w("")

    # -- The accepted gaps, in words -----------------------------------------
    gaps = record.get("known_gaps", []) if isinstance(record, dict) else []
    if gaps:
        w("### Accepted gaps on record")
        w("")
        for gap in gaps:
            if not isinstance(gap, dict):
                continue
            w(f"<details><summary><code>{gap.get('id', '?')}</code> — {gap.get('summary', '')}</summary>")
            w("")
            w(f"- **observed** — {gap.get('observed_evidence', 'n/a')}")
            w(f"- **accepted by** — {gap.get('approved_by', 'n/a')} on {gap.get('recorded_on', 'n/a')}")
            w(f"- **why it is still open** — {gap.get('why_not_closed', 'n/a')}")
            w(f"- **risk while open** — {gap.get('material_risk', 'n/a')}")
            w(f"- **how to close it** — {gap.get('how_to_close', 'n/a')}")
            w("")
            w("</details>")
            w("")

    w("---")
    w("")
    w("Full validator report, ratchet result and the raw API payloads are")
    w("attached to this run as the `governance-evidence` artifact.")

    print("\n".join(out))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
