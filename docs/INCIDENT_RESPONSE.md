# EruoFood AI — Incident Response Runbook

How the team detects, triages, mitigates, and learns from production incidents,
including the secret-rotation procedure.

## 1. Severity levels

| Sev | Definition | Examples | Response |
|---|---|---|---|
| **SEV1** | Critical outage or data/security breach | API down, data loss, payment failure, confirmed breach | Page immediately; all-hands; status page within 15 min |
| **SEV2** | Major degradation, no full outage | Elevated 5xx, a critical flow broken, one region degraded | Page on-call; mitigate within 1 hr |
| **SEV3** | Minor / partial | Non-critical endpoint errors, slow queue | Next business hours |
| **SEV4** | Cosmetic / low | UI glitch, log noise | Backlog |

## 2. Roles

- **Incident Commander (IC):** owns the incident, coordinates, makes the
  roll-back/roll-forward call. Not necessarily the person fixing.
- **Ops/Comms:** status page, stakeholder updates.
- **Subject-matter responders:** the engineers doing the work.

## 3. Lifecycle

1. **Detect** — alert (error-rate/latency/probe), customer report, or security
   signal.
2. **Declare** — open an incident channel, assign IC, set severity.
3. **Triage** — scope blast radius; identify the likely cause (recent deploy?
   dependency? infra?).
4. **Mitigate** — fastest safe path to stability:
   - Recent deploy suspected → `docs/ROLLBACK_PLAN.md`.
   - Dependency down → shed load / enable degradation path (§DR §6).
   - Infra/region → `docs/DISASTER_RECOVERY.md`.
5. **Resolve** — confirm recovery with health/readiness + smoke tests + metrics.
6. **Review** — blameless post-incident review within 5 business days; track
   action items to done.

## 4. Detection & alerting (thresholds)

- Error rate > 1% for 5 min → page.
- p95 latency beyond `PERFORMANCE_REPORT.md` threshold for 10 min → page.
- Readiness failing on > 1/3 of pods → page.
- Queue depth growing unbounded / scheduler heartbeat missing → page.
- Security: WAF/anomaly signals, auth-failure spikes, new IAM principals.

## 5. Secret rotation procedure

Triggered by: suspected exposure, employee offboarding, scheduled rotation, or a
SEV1 breach. Rotate **without downtime** using overlap where the platform allows.

| Secret | Procedure |
|---|---|
| `APP_KEY` | Add the new key to `APP_PREVIOUS_KEYS` (decrypt-only) before switching `APP_KEY`, so existing encrypted payloads still decrypt; deploy; later drop the old key. |
| `JWT_SECRET` | Rotate the signing secret; short `JWT_TTL` (15 min) means access tokens self-expire fast; refresh tokens are DB-backed and can be mass-revoked. Communicate forced re-auth if needed. |
| DB / Redis credentials | Create new credential in the managed service; update the secret manager; rolling-restart pods to pick it up; revoke the old credential. |
| OAuth client secrets | Rotate in the provider console + secret manager; deploy. |
| API keys / webhook signing secrets (tenant-facing) | Use the built-in rotation (secrets stored only as SHA-256 hashes); notify developers via the portal; support an overlap window. |
| Cloud/IAM keys | Prefer workload identity (IRSA/OIDC) over static keys; if static, rotate in the secret manager and revoke the old. |

After any rotation: verify auth, a webhook delivery, and a DB/Redis-backed request
succeed; record the rotation in the audit log.

## 6. Communication templates

- **Internal (declare):** `SEVn — <summary> — IC: <name> — channel: <link>`
- **Status page:** impact, scope, current action, next update time.
- **Resolution:** what happened, when restored, follow-ups.

## 7. Post-incident review (blameless)

- Timeline, root cause (5 whys), what worked, what didn't.
- Action items with owners + due dates; link to tracking issues.
- Feed systemic fixes back into the relevant runbook and CI gates.
