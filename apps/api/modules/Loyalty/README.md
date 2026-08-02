# Loyalty Module (`EruoFood\Loyalty`)

The **Loyalty, Rewards & Referrals** bounded context — the platform's single home
for every point, tier, reward redemption and referral: members earn points for
orders, reviews and referrals, climb membership tiers, and redeem points for
rewards; a referral programme rewards both sides on a qualifying event.

**No business module awards or stores its own points.** `LoyaltyService` is the
one entry point for a balance, points are earned from published domain events,
and a member's tier is computed in exactly one place — the `TierProjector` —
and published as an event. Other contexts consume the tier and reward-voucher
events; they never compute points or apply a benefit. The only inbound coupling
is a config-driven earn-rule map, keyed by each event's stable name.

## What it owns

- **Accounts & the points ledger** (`LoyaltyAccount`, `LedgerEntry`) — a running
  balance and lifetime total, with an immutable append-only entry per movement
  (earn/redeem/expire/adjust). The balance never goes negative; the ledger is the
  source of truth and the balance is a maintained fold of it.
- **Tiers** (`Tier`, `TierPolicy`) — a config ladder resolved from lifetime
  points; the projector is the single writer and publishes `tier_changed`.
- **Rewards & redemptions** (`Reward`, `Redemption`) — a redeemable catalogue with
  points cost, stock and an active window; redeeming issues a voucher code and
  publishes `reward_redeemed` for the pricing context to apply.
- **Referrals** (`Referral`, `ReferralCode`) — shareable codes, attribution with
  self-referral and one-per-referee guards, and qualification on a configured
  event.

## Decoupling

- **Points in via events.** A config `earn_rules` map ties an order/review event
  name to a flat award and/or per-amount rate plus the fields carrying the user id
  and amount; the `EventTranslator` awards points by reflection, applying the
  member's tier multiplier. No other context's event class is imported.
- **Tiers & vouchers out via events.** `tier_changed`, `reward_redeemed`,
  `points_earned`, `points_redeemed`, `points_expired` and `referral_qualified`.
- **Expiry** is a scheduled sweep (`loyalty:scan-expiry`), not a read-time
  calculation.

## Layout

```
src/
  Domain/          Enums, value objects (Points), the LoyaltyAccount aggregate +
                   ledger, Tier/TierPolicy, Reward/Redemption, Referral, ports,
                   and events.
  Application/     LoyaltyService (the one entry point), TierProjector (the only
                   tier writer), RedemptionService, ReferralService, RewardService,
                   EventTranslator, LoyaltyAnalyticsService, LoyaltyPresenter.
  Infrastructure/  Eloquent models + repositories, migrations (2027_02_01_*), the
                   event subscriber, the expiry-scan command, the seeder and the
                   service provider (composition root).
  Interface/       HTTP controllers (member, rewards, referrals, admin) and routes
                   (mounted under /api/v1/loyalty).
tests/             Unit (ledger/tier, rewards/referrals) and Feature (the full
                   API flow).
```

See [`docs/api/loyalty-endpoints.md`](../../../../docs/api/loyalty-endpoints.md)
for the endpoints and [`docs/adr/0015-loyalty-rewards-referrals.md`](../../../../docs/adr/0015-loyalty-rewards-referrals.md)
for the design rationale.
