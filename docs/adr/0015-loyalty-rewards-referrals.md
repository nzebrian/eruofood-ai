# ADR-0015: Loyalty, Rewards & Referrals — one context that owns every point, earned from events

- **Status:** Accepted
- **Date:** 2026-08-02
- **Deciders:** Engineering, Product, Growth

## Context

Milestone 15 adds Loyalty, Rewards & Referrals: members earn points for the
things they do on the platform (orders, reviews, referrals), climb membership
tiers, and redeem points for rewards (discount vouchers, free delivery,
freebies); a referral programme rewards both sides when an invited member places
their first order; points expire on a policy; and admins can adjust balances,
manage the rewards catalogue and read programme analytics. The hard
requirements, matching the platform's conventions: an **independent bounded
context**, **no business module awards or stores its own points**, **all loyalty
flows through the Loyalty Domain**, and **projections (tier, redemption vouchers)
are fed back to other contexts via published domain events** — consumers apply
benefits and cache tiers, they never compute points.

## Decision

- **A standalone `EruoFood\Loyalty` context that owns every point.** No module
  awards, spends or stores points on its own; `LoyaltyService` is the one entry
  point for a balance, and the `LoyaltyAccount` aggregate is the consistency
  boundary — it keeps a running balance and lifetime total and appends an
  immutable `LedgerEntry` for every movement. The balance can never go negative:
  earning/adjusting-up add, redeeming/expiring/adjusting-down subtract, and an
  over-spend is rejected in the aggregate.
- **Points are earned from published events, never by a module calling Loyalty.**
  A config `earn_rules` map ties an external event name (`commerce.order_paid`,
  `reviews.review_published`, …) to a flat award and/or a per-amount rate and the
  fields carrying the user id and amount. The `EventTranslator` reacts by name,
  reads those fields from the event's public properties via reflection, applies
  the member's **tier earn multiplier**, and awards the points. Loyalty imports no
  other context's event or model — the coupling is one-way and by name, exactly
  like Search's indexing and the Support CRM's timeline.
- **Tier is computed in exactly one place.** The `TierProjector` resolves a
  member's tier from lifetime points via the config tier ladder and publishes
  `loyalty.tier_changed` when it moves. Every award re-projects the tier, so a
  tier can never drift from lifetime points, and other contexts consume the event
  to cache tier-based perks rather than recomputing.
- **Redemptions issue a voucher and flow out as an event.** Redeeming debits
  points, decrements reward stock and issues a `Redemption` (a unique code)
  atomically, then publishes `loyalty.reward_redeemed`. The consuming context
  (Payments/Commerce) reads the voucher to apply the discount or free delivery —
  Loyalty never applies a benefit itself, keeping it decoupled from pricing.
  Cancelling a redemption refunds the points and restocks the reward.
- **Referrals guard against abuse.** A referral is one attribution per referee
  (self-referral rejected in the aggregate, one-referrer-per-referee enforced by a
  unique index); it qualifies only when the referee triggers the configured
  qualifying event, at which point both sides are awarded through `LoyaltyService`
  and `loyalty.referral_qualified` is published.
- **Expiry is a scheduled sweep.** Earn entries carry an `expires_at`; the
  `loyalty:scan-expiry` command expires the still-live remainder of each lapsed
  entry (original minus what already expired against it), clamped to the current
  balance, and publishes `loyalty.points_expired`. Applied continuously rather
  than at read time so a balance is always current.

## Consequences

- Points are consistent everywhere: one ledger, one balance, one tier per member,
  all refreshed by events. No module can mint or spend points behind Loyalty's
  back or show a divergent balance.
- Loyalty is inbound-decoupled: it depends on other contexts only through their
  published events (for earning and referral qualification), and other contexts
  depend on Loyalty only through its published tier/voucher events. Pricing stays
  in Payments/Commerce, which apply the voucher the redemption event carries.
- The ledger is the source of truth; the account's `balance`/`lifetime_points`
  are a maintained fold of it, written transactionally with the entries, so a read
  never has to sum the whole ledger.
- The earn-rule and tier ladders are config, so Growth can tune award rates,
  thresholds and multipliers without code changes.

## Alternatives considered

- **Points awarded inline by each module (Commerce credits points on checkout).**
  Rejected — it scatters the earning rules, couples every module to the loyalty
  store, and makes a consistent platform-wide balance impossible.
- **Applying reward discounts inside Loyalty.** Rejected — it would pull pricing
  and cart/payment knowledge into Loyalty; issuing a voucher event and letting the
  pricing context apply it keeps the boundary clean.
- **Computing tier on read from lifetime points.** Rejected in favour of a
  projected tier written once and published, so consumers get a stable value and a
  `tier_changed` signal rather than each re-deriving it.
