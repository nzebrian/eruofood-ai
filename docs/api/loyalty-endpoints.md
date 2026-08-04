# Loyalty, Rewards & Referrals — API Endpoints

Base URL: `/api/v1`. All paths are under **`/loyalty`**. The tier ladder and the
rewards catalogue are **public** to browse; a member's balance, ledger,
redemptions and referral code require authentication; adjustments, reward
management, fulfilment and analytics require an **admin role** (enforced in the
controllers). **No business module awards or stores its own points** — points
are earned from published order/review events, and tiers/vouchers flow out via
published events. Full schema:
[`packages/api-contracts/openapi.yaml`](../../packages/api-contracts/openapi.yaml).

## Public read surface

| Method & Path | Purpose |
|---|---|
| `GET /loyalty/tiers` | The membership tier ladder (key, name, threshold, earn multiplier). |
| `GET /loyalty/rewards` | The active rewards catalogue. |

## Member surface (auth)

| Method & Path | Purpose |
|---|---|
| `GET /loyalty/me` | The member's account — balance, lifetime points, tier and progress to the next tier (account opened on first read). |
| `GET /loyalty/ledger` | The points ledger, newest first. |
| `GET /loyalty/redemptions` | The member's reward redemptions. |
| `POST /loyalty/rewards/{rewardId}/redeem` | Redeem points for a reward — issues a voucher code (**422** if unaffordable/unavailable). |
| `POST /loyalty/redemptions/{id}/cancel` | Cancel a redemption — refunds points, restocks the reward. |
| `GET /loyalty/referrals/code` | The member's personal referral code (created on first request). |
| `POST /loyalty/referrals/apply` | Apply a referrer's `code` — attributes the caller as a referee (**409** on self-referral or reuse). |

## Admin surface (admin role)

| Method & Path | Purpose |
|---|---|
| `POST /loyalty/admin/adjust` | Manually adjust a member's points (`user_id`, signed `points`, `reason`). |
| `GET /loyalty/admin/rewards` | The full catalogue including inactive rewards. |
| `POST /loyalty/admin/rewards` | Create a reward (`name`, `benefit_type`, `benefit_value`, `points_cost`, `stock`, window). |
| `PUT /loyalty/admin/rewards/{id}` | Update a reward. |
| `POST /loyalty/admin/redemptions/fulfil` | Mark a redemption fulfilled by its `code` once the benefit is applied. |
| `GET /loyalty/admin/analytics` | Membership, points liability, earned/redeemed/expired flow, tier distribution, top rewards. |

## Earning, tiers & decoupling

- **Points are earned from published events**, not by any module calling Loyalty.
  A config `earn_rules` map ties an event name (e.g. `commerce.order_paid`,
  `reviews.review_published`) to a flat award and/or a per-amount rate, plus which
  event fields carry the user id and amount. The member's **tier earn multiplier**
  is applied on top. Reflection reads the fields; no event class is imported.
- **Tiers are computed in one place.** The `TierProjector` resolves a member's
  tier from lifetime points and publishes `loyalty.tier_changed` when it moves —
  other contexts consume that event, they never compute a tier.
- **Redemptions flow out as events.** Redeeming publishes `loyalty.reward_redeemed`
  carrying the voucher; the consuming context (Payments/Commerce) applies the
  benefit. Loyalty never applies a discount itself. Also published:
  `loyalty.points_earned`, `loyalty.points_redeemed`, `loyalty.points_expired`,
  `loyalty.referral_qualified`.
- **Referrals** qualify when the referee triggers the configured qualifying event
  (their first order); both sides are then awarded points.
- **Expiry**: points expire after a configurable window; the `loyalty:scan-expiry`
  command sweeps expired points off balances on a schedule.

## Error codes

| HTTP | When |
|---|---|
| `403` | Cancelling/viewing another member's redemption; admin-only action. |
| `404` | Unknown reward, redemption or referral code. |
| `409` | Self-referral, or a referee reusing/duplicating a referral. |
| `422` | Non-positive points, insufficient balance, an unavailable/out-of-stock reward, or an illegal state transition. |
