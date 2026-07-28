# ADR-0014: Reviews & Ratings — one context that owns every review, ratings out via events

- **Status:** Accepted
- **Date:** 2026-07-28
- **Deciders:** Engineering, Product, Trust & Safety

## Context

Milestone 14 adds Reviews & Ratings: customers review the things they interact
with — products, foods, recipes, vendors, restaurants and riders — with a 1–5
star rating, optional title/body/photos and a verified-purchase badge; reviews
are moderated (a queue with a content filter, approve/reject/remove); the
community votes reviews helpful; subject owners respond publicly; and every
subject carries an authoritative rating summary used across the platform. The
hard requirements: an **independent bounded context**, **no business module
stores or aggregates its own reviews**, **all reviews go through the Reviews
Domain**, and **aggregate rating projections are fed back to other contexts via
published domain events** (other contexts consume ratings, never compute them).

## Decision

- **A standalone `EruoFood\Reviews` context that owns every review.** No module
  stores, mutates or aggregates reviews on its own; `ReviewService` is the one
  entry point for every interaction, and the `Review` aggregate enforces the
  moderation lifecycle (`ReviewStatus`: pending → published → rejected/removed)
  so only a published review is visible and counts toward a rating. Subjects are
  polymorphic soft references — a `(SubjectType, id)` pair into the owning
  context; Reviews never touches that context's tables.
- **Ratings are computed in exactly one place and flow out as events.** The
  `RatingProjector` is the *only* writer of a subject's `RatingSummary`: it
  recomputes count, average, the 1–5 distribution and the verified count from the
  subject's published reviews and publishes `reviews.rating_summary_updated`.
  Other contexts consume that event to cache a rating locally — they never query
  Reviews' tables or recompute a rating. Every path that changes the published
  set (submit-and-publish, approve, remove, edit) goes through the projector, so
  the summary can never drift from the reviews.
- **Verified purchase is event-fed, never a cross-context query.** A config
  `eligibility_events` map ties an order/interaction event name (e.g.
  `marketplace.order_placed`) to the subject type and the event fields carrying
  the buyer id and subject id. The `EventTranslator` reacts by name, reads those
  fields from the event's public properties via reflection (handling stringable
  value objects), and records `(user, subject)` eligibility idempotently in a
  ledger. At submit time the review is stamped `verified_purchase` from the
  ledger. Reviews imports no other context's event or model.
- **Moderation posture is configurable and content filtering is pluggable.**
  Under `post` moderation a clean review auto-publishes and a content-filter hit
  holds it for a human; under `pre` moderation everything is held. The
  `ContentModerator` port has an offline word-list adapter (the dependency-free
  default, a certain first pass) and an AI-backed adapter that escalates clean
  text to the AI engine via its published `AiAdvisor` contract, falling back to
  the word-list on any provider error — a provider hiccup never blocks a
  submission.
- **One review per subject per author.** Enforced in the domain (duplicate check
  → `409`) and by a unique `(subject_type, subject_id, author_id)` index.

## Consequences

- The platform has a single, consistent rating everywhere. A product card, a
  vendor page and search results all read the same summary, refreshed by one
  event, and no module can produce a divergent number.
- Reviews is inbound-decoupled: the only coupling is one-way, by event name, for
  verified-purchase eligibility. Other contexts depend on Reviews only through
  the published rating-summary event, mirroring how Search consumes documents and
  the Support CRM consumes the event timeline.
- The verified-purchase ledger is a soft, eventually-consistent projection of
  order history; a replayed order event is idempotent, and Reviews never blocks
  on an order table being reachable.
- Moderation and analytics share the corpus but not the write path: the
  `RatingSummary` is only ever written by the projector, so any moderation
  action stays consistent by re-projecting rather than mutating a rating in place.

## Alternatives considered

- **Per-module reviews (each context stores its own).** Rejected — it violates
  the single-source rule, scatters moderation and duplicate rules, and makes a
  platform-wide "top rated" impossible without cross-context joins.
- **Synchronous rating reads from other contexts.** Rejected — it couples every
  product/vendor read to the Reviews store and re-computes ratings in many
  places; the published summary event keeps consumers fast and decoupled.
- **Deriving verified purchase by querying orders at submit time.** Rejected —
  it would import the Marketplace/Commerce read models into Reviews; the
  event-fed ledger keeps the contexts independent.
