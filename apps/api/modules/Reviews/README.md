# Reviews Module (`EruoFood\Reviews`)

The **Reviews & Ratings** bounded context — the platform's single home for every
review and the authoritative rating of every subject: polymorphic reviews of
products, foods, recipes, vendors, restaurants and riders, with 1–5 stars,
optional title/body/photos, a verified-purchase badge, moderation, helpfulness
voting and subject-owner responses.

**No business module stores or aggregates its own reviews.** `ReviewService` is
the one entry point for every interaction, and a subject's rating is computed in
exactly one place — the `RatingProjector` — and published as an event. Other
contexts consume that summary; they never compute a rating or read Reviews'
tables. The only inbound coupling is a config-driven event map (verified-purchase
eligibility), keyed by each event's stable name.

## What it owns

- **Reviews** (`Review` aggregate) — a star `Rating`, optional title/body/photos,
  a `verified_purchase` flag, the moderation `ReviewStatus`
  (`pending → published → rejected/removed`), helpfulness counters, and an
  optional `OwnerResponse`. Only a published review is visible and counts toward
  a rating; the status transitions are guarded in the aggregate.
- **The rating summary** (`RatingSummary`) — count, average, the 1–5 star
  distribution and the verified count for a `Subject`. A pure projection of the
  subject's published reviews, written only by the `RatingProjector`.
- **The verified-purchase ledger** (`PurchaseEligibilityRepository`) — a soft,
  event-fed record of `(user, subject)` eligibility, learned from published
  order events and checked at submit time.
- **Review analytics** — the moderation funnel, platform-wide star distribution,
  verified rate and spread across subject types, plus top-rated subjects.

## Decoupling

- **Ratings out via events.** Publishing, approving or removing a review
  re-projects the subject's summary and publishes
  `reviews.rating_summary_updated`. Consumers cache the rating locally from that
  event. Also published: `reviews.review_published` and `reviews.review_flagged`.
- **Verified purchase in via events.** A config `eligibility_events` map ties an
  order event name (e.g. `marketplace.order_placed`) to the subject type and the
  event fields carrying the buyer id and subject id; the `EventTranslator`
  records eligibility idempotently by reflection — no other context's event class
  is imported, and no order table is queried.
- **Content moderation is pluggable.** The `ContentModerator` port has an offline
  word-list adapter (the default) and an AI-backed adapter that escalates clean
  text to the AI engine's published `AiAdvisor` contract, falling back to the
  word-list on any error.

## Layout

```
src/
  Domain/          Enums, value objects (Rating, Subject, OwnerResponse),
                   the Review aggregate, RatingSummary, ports and events.
  Application/     ReviewService (the one entry point), RatingProjector (the
                   only rating writer), ModerationService, ReviewAnalyticsService,
                   EventTranslator, ReviewPresenter, the ContentModerator port.
  Infrastructure/  Eloquent models + repositories, migrations (2027_01_01_*),
                   the word-list & AI-backed moderators, the event subscriber,
                   the seeder and the service provider (composition root).
  Interface/       HTTP controllers (public read, customer, moderation, admin)
                   and routes (mounted under /api/v1/reviews).
tests/             Unit (review lifecycle, rating projection, moderation) and
                   Feature (the full API flow).
```

See [`docs/api/reviews-endpoints.md`](../../../../docs/api/reviews-endpoints.md)
for the endpoints and [`docs/adr/0014-reviews-and-ratings.md`](../../../../docs/adr/0014-reviews-and-ratings.md)
for the design rationale.
