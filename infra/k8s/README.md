# infra/k8s — deployment-ready manifests (provider-neutral)

These are the Kubernetes manifests that can be applied once a hosting provider
and cluster exist. They are **READY TO EXECUTE**, not applied (no cluster in this
repo/sandbox).

## Files
- `networkpolicy-webhook-egress.yaml` — SSRF **infrastructure** egress control:
  deny internal ranges + cloud metadata for webhook workers; allow DNS + public
  443 only. Requires a CNI that enforces egress (Cilium/Calico).

## Still TODO at deploy time (create alongside these)
- `deploy-*.yaml` / Helm chart for `api`, `worker`, `scheduler`, `web`, `nginx`
  with liveness `GET /api/v1/health` and readiness `GET /api/v1/ready` probes,
  `maxUnavailable: 0` rolling strategy, and a single-replica scheduler.
- `jobs/migrate.yaml` — one-off expand-only migration Job (`php artisan migrate
  --force`) run before rollout.
- Secret delivery via External Secrets / sealed-secrets from the cloud secret
  manager (never plain `Secret` manifests in git).

## Provider-specific — configure AFTER selecting AWS / GCP / Azure
The NetworkPolicy covers the in-cluster layer. The following **cannot** be
expressed in portable k8s and MUST be set on the chosen provider (see
`docs/INFRA_EGRESS_POLICY.md` §2.4–2.5):
- **AWS:** private subnets + NAT gateway with NACL denies for RFC1918/metadata;
  IMDSv2 enforced (`HttpTokens=required`, hop limit 1); least-privilege task role.
- **GCP:** VPC egress `deny` firewall rules; block `metadata.google.internal`;
  Cloud NAT with logging; `disable-legacy-endpoints=true`.
- **Azure:** NSG egress denies + Azure Firewall FQDN application rules; deny IMDS
  from the workload where not required.

None of the provider-level enforcement is marked executed — it is pending
provider selection.
