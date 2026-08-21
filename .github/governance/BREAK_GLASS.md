# Break-Glass Procedure

There is **no standing bypass**. `bypass_actors` is empty on the `main` ruleset
and on the tag-immutability ruleset, and it stays empty.

That is deliberate, and it is the whole design. A standing bypass is invisible:
somebody with it merges past a failing financial gate at 3am and the only trace
is a merge commit that looks like every other one. Disabling a ruleset for a
recorded window is loud — it appears in the audit log twice, on the way down and
on the way back up — and it forces a decision to be made by a person who has to
put their name on it.

The cost of this procedure is the point. If it feels heavy, it is working.

---

## Before you reach for this

Most emergencies do not need it. Check first:

| Situation | Better route |
|---|---|
| Money is moving and should not be | `FLAG_SETTLEMENT_EXECUTE=false`. No deploy, no merge, no bypass. See `docs/DISASTER_RECOVERY.md`. |
| A bad commit is on `main` | `git revert` through a normal pull request. Reverting is a forward commit; it needs no bypass. |
| CI is red on a pre-existing failure | Fix the check or the base branch. A red gate you cannot explain is a reason to stop, not to bypass. |
| A release must be cut urgently | Use a release actor. That path already exists and is already authorised. |
| A required check is stuck pending | Almost always a re-added `paths:` filter on a `pull_request` trigger. Fix the workflow — see `required-checks.json`. |

Break-glass is for the case where the protection *itself* is the obstacle and
there is no forward path: a ruleset misconfigured such that nothing can merge, a
required check whose workflow was deleted, an incident where the repository must
change before CI can possibly pass.

---

## Procedure

### 1. Authorise

A repository administrator decides, and is named in the record. If the situation
allows a second person to concur, get one. If it does not, say so in the record
rather than leaving the field blank.

### 2. Record — before, not after

Open the incident record (§4) and fill in everything through *Risk assessment*
**before** touching the configuration. A record written afterwards is a
reconstruction, and reconstructions are kind to their authors.

### 3. Disable, do not exempt

```bash
gh api -X PUT /repos/nzebrian/eruofood-ai/rulesets/<ID> \
  -f enforcement=disabled
```

**Never** add a bypass actor instead. A bypass survives the incident; a disabled
ruleset is conspicuous and gets restored. Disable the *narrowest* ruleset that
unblocks the work — if only tag creation is in the way, do not disable `main`.

Note the exact UTC time.

### 4. Act

Do the minimum. Nothing opportunistic, nothing tidied up while you are in there.
Every commit made in this window carries the incident ID in its message:

```
fix(governance): <what>

Break-glass: INC-2026-08-21-001
```

### 5. Restore — same session, no exceptions

```bash
gh api -X PUT /repos/nzebrian/eruofood-ai/rulesets/<ID> \
  -f enforcement=active
```

Restore before you close the laptop. The most common failure of this procedure
is not misuse; it is a ruleset that was disabled on a Friday and noticed on a
Tuesday.

### 6. Verify

Run `VERIFY_GOVERNANCE.md` **in full** — §1, §2 and §3. Not a spot check:
confirm the rules apply, the required checks are intact, `bypass_actors` is
still empty, and a direct push is still refused.

### 7. Review

Within five working days. Not to assign blame — to answer one question: *what
would have made this unnecessary?* If the answer is "nothing", the procedure was
used correctly. If it is "a fix we keep deferring", schedule the fix.

---

## Incident record

Copy into the incident tracker. Every field is required; `n/a` is an acceptable
answer, an empty field is not.

```
INCIDENT ID:            INC-YYYY-MM-DD-NNN

REASON:                 What could not be done any other way, and why the
                        alternatives in "Before you reach for this" did not apply.

RISK ASSESSMENT:        What protection is being removed, what could go wrong
                        while it is off, and what limits the blast radius.
                        Financial impact stated explicitly, including "none".

AUTHORIZED BY:          GitHub handle of the administrator.
CONCURRED BY:           Second person, or "none — sole administrator available".

TEMPORARY RULE CHANGE:  Ruleset ID and name; enforcement active -> disabled.
                        State which rules were NOT touched.

START TIME (UTC):
END TIME (UTC):
DURATION:

ACTION PERFORMED:       Every commit SHA, tag and configuration change made
                        during the window.

VERIFICATION:           Output of verify_repository_governance.php and the
                        VERIFY_GOVERNANCE.md §2 and §3 results, after restoration.

RULE RESTORATION:       Timestamp, and confirmation that enforcement is active
                        and bypass_actors is empty.

POST-INCIDENT REVIEW:   Date, attendees, and what would have made this
                        unnecessary.
```

---

## Financial guard rails

These hold during a break-glass window as at any other time:

- **No live money movement.** `settlement.execute` and
  `settlement.accrual_posting` stay `false`. Nothing in this procedure
  authorises enabling them.
- **UNKNOWN stays UNKNOWN.** An interrupted transfer is never resolved by hand
  to `failed` to unblock something. Only provider-authoritative reconciliation
  may decide.
- **Financial tests are not bypassed to merge.** If a financial gate is red, the
  gate is the finding.
- **No production tag** is created during a break-glass window unless cutting
  that release *is* the incident, and it is recorded as such.

A window opened to fix a governance misconfiguration is not authorisation to
touch the financial system.
