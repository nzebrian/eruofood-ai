# Applying Repository Governance

For a repository **administrator**. Roughly 45 minutes, most of it testing.

Nothing in this runbook can be done by the automation that wrote it: the M29-A
session credential reported `admin: false` and every governance write returned
`403 Resource not accessible by integration`.

**Read step 0 before doing anything.** Two of its decisions cannot be reversed
by editing a JSON file afterwards.

---

## 0. Decisions that must be made first

### 0.1 Organization, or stay personal?

`nzebrian/eruofood-ai` is owned by a user account. That is not a problem in
itself, but it forecloses things:

| | Personal account | Organization |
|---|---|---|
| CODEOWNERS may name teams | ❌ individual usernames only | ✅ |
| Org-level rulesets | ❌ | ✅ |
| Required workflows across repos | ❌ | ✅ |
| Audit log retention | limited | ✅ |

**If you migrate, do it before step 1.** Transferring a repository preserves
rulesets, but every CODEOWNERS entry you write now as `@username` would need
rewriting as `@org/team` afterwards — and you would be re-testing the whole
policy for no reason.

### 0.2 Who reviews?

`required_approving_review_count: 1` means **one approval from somebody who is
not the pull request author**. GitHub does not permit self-approval.

> With one account holding write access, this rule blocks every pull request —
> including yours. It would have blocked M27 and M28.

So before enabling it, either add a second person with write access, or set the
count to `0` and rely on the other rules (which still prevent direct pushes,
force-pushes and merging with failing checks). **Do not** work around it by
adding yourself as a bypass actor; that removes the protection from every rule
in the ruleset, not just this one.

### 0.3 Who cuts releases?

`release.yml` fires on `v*.*.*` and promotes a container image. Decide which
named accounts may create those tags. "Whoever is an admin" is not an answer —
write down the accounts.

---

## 1. Replace the CODEOWNERS placeholders

Open `.github/CODEOWNERS`. Every rule is commented out and every owner is a
`<OWNER:...>` token.

1. Map each token to a real handle:

   | Token | Domain |
   |---|---|
   | `<OWNER:MAINTAINERS>` | catch-all |
   | `<OWNER:API>` | `apps/api/`, API contracts |
   | `<OWNER:FINANCE>` | Payments module, payment config, financial scripts |
   | `<OWNER:WEB>` | `apps/web/`, API contracts |
   | `<OWNER:MOBILE>` | `apps/mobile/` |
   | `<OWNER:PLATFORM>` | `infra/`, workflows, production runbooks |
   | `<OWNER:GOVERNANCE>` | `.github/governance/`, CODEOWNERS itself |

2. Grant every named owner **write access**. GitHub silently ignores a code
   owner who cannot push.

3. Uncomment the rules. Keep the order — CODEOWNERS resolves *last match wins*,
   so the catch-all stays at the top and `/.github/CODEOWNERS` stays at the
   bottom.

4. Verify — this must return an empty `errors` array:

   ```bash
   gh api /repos/nzebrian/eruofood-ai/codeowners/errors
   ```

5. Confirm locally:

   ```bash
   php apps/api/scripts/verify_repository_governance.php
   ```

   The CODEOWNERS placeholder check moves from EXTERNAL/ADMIN REQUIRED to PASS
   only when no `<OWNER:...>` token remains **and** at least one rule is active.

---

## 2. Apply the `main` ruleset

```bash
jq '.rulesets[0]' .github/governance/main-ruleset.json \
  | gh api -X POST /repos/nzebrian/eruofood-ai/rulesets --input -
```

If you set the approval count to `0` per §0.2, edit
`rules[].parameters.required_approving_review_count` **in the JSON file** and
commit that change — do not diverge silently from the artifact. A ruleset that
does not match its committed description is worse than one that is missing,
because the next person will trust the file.

Record the returned ruleset `id`.

---

## 3. Apply the production tag rulesets

Two rulesets, deliberately. GitHub scopes `bypass_actors` to the whole ruleset,
so putting the release actors on the `creation` rule would also exempt them from
`deletion` — and a release tag that its creator can delete is not an immutable
release record.

```bash
# 3a. Restricted creation. Add the release actors as bypass_actors FIRST.
jq '.rulesets[0]' .github/governance/production-tags-ruleset.json \
  | gh api -X POST /repos/nzebrian/eruofood-ai/rulesets --input -

# 3b. Immutability. bypass_actors MUST stay empty.
jq '.rulesets[1]' .github/governance/production-tags-ruleset.json \
  | gh api -X POST /repos/nzebrian/eruofood-ai/rulesets --input -
```

Before running 3a, add each release actor to `rulesets[0].bypass_actors`:

```json
{ "actor_id": 12345, "actor_type": "Team", "bypass_mode": "always" }
```

Applied with an empty `bypass_actors`, 3a denies tag creation to **everyone**.
That is the safe failure direction, but it does stop releases — so it should be
a decision, not a surprise.

---

## 4. Configure required checks

The seven contexts are already in `main-ruleset.json`. Verify GitHub received
them exactly — five contain **U+00B7 MIDDLE DOT (·)**, and a mistyped context
never reports, which leaves pull requests pending forever:

```bash
gh api /repos/nzebrian/eruofood-ai/rulesets/<ID> \
  | jq '.rules[] | select(.type=="required_status_checks")
        | .parameters.required_status_checks[].context'
```

Compare against `.github/governance/required-checks.json`. They must match byte
for byte.

M29-A removed `paths:` from the `pull_request` trigger of `ci-api.yml`,
`ci-web.yml`, `contracts.yml` and `ci-docker.yml` so all seven report on every
pull request. **If you re-add a path filter to any of them, the corresponding
required check will hang.**

---

## 5. Confirm there are no bypass actors

```bash
gh api /repos/nzebrian/eruofood-ai/rulesets \
  | jq '.[] | {id, name, enforcement}'

for id in <MAIN_ID> <TAG_IMMUTABLE_ID>; do
  gh api /repos/nzebrian/eruofood-ai/rulesets/$id | jq '{name, bypass_actors}'
done
```

`bypass_actors` must be `[]` for the `main` ruleset and for the tag-immutability
ruleset. Only the tag-creation ruleset carries actors, and only the ones you
chose in §0.3.

Emergencies are handled by `BREAK_GLASS.md`, not by a standing exemption.

---

## 6. Test every protection

Do not skip this. An unverified ruleset is an assumption, and the whole point of
M29-A is that assumptions about enforcement were exactly what was wrong.

Work on a scratch clone. Each command **must fail**.

### 6.1 Direct push blocked
```bash
git checkout main && git pull
echo "governance probe" >> /tmp/probe && git commit -am "probe" --allow-empty
git push origin main
# expect: GH013 / "Changes must be made through a pull request"
```

### 6.2 Force-push blocked
```bash
git push --force origin main
# expect: rejected — non-fast-forward updates are blocked
```

### 6.3 Branch deletion blocked
```bash
git push origin --delete main
# expect: rejected — deletions are blocked
```

### 6.4 Approval requirement
Open a trivial pull request. `Merge` must be disabled with *"Review required"*.
Confirm you cannot approve your own — this is the §0.2 consequence, seen live.

### 6.5 Failing CI blocks merge
On a scratch branch, break one check on purpose:

```bash
# e.g. introduce a Pint violation
printf '<?php\n$x=1;   $y=2;\n' > apps/api/tests/_governance_probe.php
```

Push, open a pull request, wait for `Lint · Analyse · Test` to fail. Merge must
be blocked. **Delete the probe file afterwards** — do not merge it, and do not
leave it on a branch that could be merged later.

### 6.6 CODEOWNER routing
Open a pull request touching `apps/api/modules/Payments/`. The finance owner
must be requested automatically. If not, re-check §1.4 and that the owner has
write access.

### 6.7 Production tag restriction
As an account that is **not** a release actor:

```bash
git tag -a v0.0.1-governance-probe -m "probe" && git push origin v0.0.1-governance-probe
# expect: rejected — tag creation restricted
```

Then as a release actor, confirm creation succeeds, and confirm deletion is
still refused:

```bash
git push origin --delete v0.0.1-governance-probe
# expect: rejected — deletions are blocked by the immutability ruleset
```

Clean the probe tag up via an administrator with the ruleset temporarily
disabled, following `BREAK_GLASS.md`. If that feels heavy for a test tag, that
is the protection working.

---

## 7. Record what you did

Update `.github/governance/README.md` with the ruleset IDs and the date applied,
and run `VERIFY_GOVERNANCE.md` end to end. Commit the CODEOWNERS change through
a pull request — the first one the new rules will govern.
