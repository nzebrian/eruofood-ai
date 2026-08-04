# EruoFood AI — Outbound Network (Egress) Policy for Webhook Delivery

This document specifies the **production-ready outbound network policy** for the
webhook-delivery workers, and draws an explicit line between:

- **Application controls** — enforced in code, already implemented and
  **EXECUTED — PASSED** in the test suite (see below); and
- **Infrastructure controls** — enforced by the network/cloud platform, which
  **cannot be applied inside this session** because they depend on the final
  cloud provider and live network fabric. These are provided here as
  deployment-ready specifications, not as executed configuration.

> **Why both layers are required.** The application layer resolves and validates a
> webhook destination, but a hostile or misconfigured resolver can still return a
> different address at TCP-connect time (DNS rebinding / TOCTOU), and application
> code cannot revoke an already-open socket's route. Only the network layer can
> guarantee that a webhook worker is *physically unable* to reach internal ranges
> or the cloud metadata service. Defence-in-depth: the app blocks what it can see;
> the infrastructure blocks what it cannot.

---

## 1. Application controls (EXECUTED — PASSED, in this codebase)

Implemented in `modules/PublicApi/src/Infrastructure/Webhook/NetworkWebhookUrlGuard.php`
and the webhook dispatcher; covered by `WebhookSecurityTest` and the standalone
SSRF harness (**25/25**, re-verified green in Milestone 19).

| Control | Implementation |
|---|---|
| Scheme allow-list | `https` only (`http` only if explicitly enabled for non-prod) |
| Port allow-list | `443` (and `80` only if `http` enabled); all others refused |
| Credentials in URL | `user:pass@host` refused |
| Literal-IP block | Loopback, RFC1918, link-local `169.254.0.0/16`, CGNAT `100.64.0.0/10`, IPv6 ULA `fc00::/7`, IPv6 loopback `::1`, IPv4-mapped IPv6 |
| Cloud metadata block | `169.254.169.254` (and `fd00:ec2::254`) refused |
| DNS resolution check | Hostname resolved and **every** returned A/AAAA record validated against the block-list |
| DNS-rebinding / TOCTOU | Destination **re-validated at send time**, not only at registration |
| Redirect following | Disabled (`withoutRedirecting()`) — a 3xx to an internal host cannot be chased |
| Response bounding | Connect/total timeouts + `CURLOPT_MAXFILESIZE` response cap |
| HMAC signing + replay window | `WebhookSigner` with timestamp tolerance (receiver-side replay defence) |

**Limit (why infra is still required):** the resolve-then-connect gap means the
address validated in PHP is not provably the address the kernel connects to. The
app closes the common cases; the network layer must close the race.

---

## 2. Infrastructure controls (deployment-ready specs — NOT applied here)

These MUST be enforced at the platform layer for the webhook-worker workload
(the pods/tasks that run `publicapi:dispatch-webhooks` and the queued delivery
jobs). They are **NOT VALIDATED** in this session — no production network fabric
exists here. Apply the block that matches the target platform.

### 2.1 Required outcomes (provider-agnostic)

1. **Default-deny egress.** The webhook workers may open outbound connections
   **only** to public destinations on **TCP 443** (and 80 only if plain `http`
   webhooks are permitted). Everything else is denied.
2. **Block all internal ranges** at the network layer, regardless of DNS:
   `127.0.0.0/8`, `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`,
   `169.254.0.0/16`, `100.64.0.0/10`, `::1/128`, `fc00::/7`, `fe80::/10`.
3. **Block the cloud metadata endpoint** `169.254.169.254/32` (and the IPv6
   variant) explicitly and first — this is the single highest-value SSRF target.
4. **Force egress through a controlled path** — a forward proxy or NAT gateway
   with its own allow/deny policy and logging — so egress is auditable and DNS is
   resolved by a trusted resolver, not attacker-controlled records.
5. **Dedicated low-privilege identity** for the webhook workers: no IAM role that
   grants anything the metadata service could hand out; assume metadata *will* be
   reachable by a bug and ensure it yields nothing useful (IMDSv2 hop-limit 1,
   IMDSv1 disabled on AWS; equivalent elsewhere).
6. **DNS egress restriction** — workers resolve only via the trusted resolver;
   direct outbound UDP/TCP 53 to arbitrary servers is denied.

### 2.2 Kubernetes `NetworkPolicy` (deny-internal egress)

Apply to the webhook-worker pods (label `app: webhook-worker`). This denies the
private ranges and the metadata IP while allowing public egress on 443/53-to-DNS.
(A `NetworkPolicy` `to.ipBlock.except` on `0.0.0.0/0` is the portable way to carve
out internal ranges; the CNI must support egress policy — Cilium/Calico do.)

```yaml
apiVersion: networking.k8s.io/v1
kind: NetworkPolicy
metadata:
  name: webhook-worker-egress
  namespace: eruofood
spec:
  podSelector:
    matchLabels: { app: webhook-worker }
  policyTypes: [Egress]
  egress:
    # DNS to the in-cluster resolver only
    - to:
        - namespaceSelector: { matchLabels: { kubernetes.io/metadata.name: kube-system } }
          podSelector: { matchLabels: { k8s-app: kube-dns } }
      ports:
        - { protocol: UDP, port: 53 }
        - { protocol: TCP, port: 53 }
    # Public HTTPS only, with ALL internal ranges + metadata IP excluded
    - to:
        - ipBlock:
            cidr: 0.0.0.0/0
            except:
              - 127.0.0.0/8
              - 10.0.0.0/8
              - 172.16.0.0/12
              - 192.168.0.0/16
              - 169.254.0.0/16   # link-local incl. 169.254.169.254 metadata
              - 100.64.0.0/10    # CGNAT
      ports:
        - { protocol: TCP, port: 443 }
```

For stronger, L7-aware control (recommended), pair the above with a **Cilium**
`CiliumNetworkPolicy` using `toFQDNs` to allow only registered webhook hostnames,
or route all worker egress through an **egress gateway** with the firewall rules
in §2.3.

### 2.3 Forward-proxy / firewall policy (egress gateway)

Route all webhook-worker egress through a proxy (e.g. Squid/Envoy) or a NAT
gateway with a stateful firewall. Minimum ruleset (deny-by-default):

```
# --- DENY (evaluated first) ---
deny  dst 169.254.169.254/32          # cloud metadata (highest priority)
deny  dst 127.0.0.0/8, ::1/128        # loopback
deny  dst 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16   # RFC1918
deny  dst 169.254.0.0/16, fe80::/10   # link-local
deny  dst 100.64.0.0/10               # CGNAT
deny  dst fc00::/7                     # IPv6 ULA
deny  proto != tcp
deny  dport not in {443, 80}          # 80 only if http webhooks enabled

# --- ALLOW ---
allow dst PUBLIC dport 443            # (optionally: only to the registered
                                      #  webhook FQDN allow-list, refreshed from
                                      #  the developer_webhooks table)
# log every allow + every deny with client workload identity
```

The proxy performs DNS resolution with a trusted resolver and re-checks the
resolved address against the deny-list at connect time — this is what closes the
DNS-rebinding race the application layer cannot fully close.

### 2.4 AWS security-group / IMDS specifics (if AWS is the target)

- Webhook workers run in **private subnets**; outbound only via a **NAT gateway**
  whose route table + NACL deny the RFC1918/metadata ranges above.
- **IMDSv2 enforced**, hop limit `1`, IMDSv1 **disabled** on the worker
  instances/tasks (`HttpTokens=required`, `HttpPutResponseHopLimit=1`).
- Worker task role scoped to **least privilege** (no `*` policies; only the
  queues/secrets it needs).
- Security group egress: allow `443` to `0.0.0.0/0`, no `10./172./192.` allow
  rules; rely on NAT-GW NACL for the range denials.

### 2.5 GCP / Azure equivalents

- **GCP:** VPC egress firewall `deny` rules for the ranges above (priority above
  any allow); block `169.254.169.254` and `metadata.google.internal`; require the
  `Metadata-Flavor: Google` header discipline; use a **Cloud NAT** with logging;
  disable legacy metadata endpoints (`disable-legacy-endpoints=true`).
- **Azure:** NSG egress rules denying the internal ranges + `169.254.169.254`;
  egress via **Azure Firewall** with FQDN application rules for the webhook
  allow-list; deny IMDS from the workload where not required.

---

## 3. Validation status of this policy

| Layer | Verdict | Evidence |
|---|---|---|
| Application SSRF guard | **EXECUTED — PASSED** | `WebhookSecurityTest` + SSRF harness 25/25 (M19 re-run green) |
| Infrastructure egress (NetworkPolicy / firewall / IMDS) | **NOT VALIDATED (deployment-time)** | No production network fabric in this session; specs above are deployment-ready and must be applied and tested in staging |

**Acceptance test for the infra layer (run in staging):** from a webhook-worker
pod/task, attempt connections to `169.254.169.254:80`, `127.0.0.1`, an RFC1918
address, and a public `:22` — **all must fail**; a public `:443` to a registered
webhook host must succeed. Then run the SSRF section of
`docs/PENETRATION_TEST_PLAN.md` end-to-end.
