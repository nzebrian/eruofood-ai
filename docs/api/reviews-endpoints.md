# Reviews & Ratings — API Endpoints

Base URL: `/api/v1`. All paths are under **`/reviews`**. Browsing a subject's
published reviews and its authoritative rating summary is **public**;
submitting, editing, voting and (for subject owners) responding requires
authentication; the moderation queue and analytics require a **moderator/admin
role** (enforced in the controllers). **No business module stores or aggregates
its own reviews** — every review flows through this context, and ratings flow
out to other contexts via the published rating-summary event. Full schema:
[`packages/api-contracts/openapi.yaml`](../../packages/api-contracts/openapi.yaml).

Subject types: `product`, `food`, `recipe`, `vendor`, `restaurant`, `rider`.

## Public read surface

| Method & Path | Purpose |
|---|---|
| `GET /reviews/{subjectType}/{subjectId}` | Published reviews for a subject (`sort` = newest\|oldest\|helpful\|rating_desc\|rating_asc, `verified`, `page`, `per_page`). |
| `GET /reviews/{subjectType}/{subjectId}/summary` | The authoritative rating summary (count, average, 1–5 distribution, verified count). |
| `GET /reviews/{id}` | A single review. |

## Customer surface (auth)

| Method & Path | Purpose |
|---|---|
| `POST /reviews` | Submit a review (`subject_type`, `subject_id`, `rating` 1–5, `title`, `body`, `photos`). Auto-publishes under post-moderation; held for a moderator on a content-filter hit or under pre-moderation. One review per subject per author (**409** otherwise). |
| `PUT /reviews/{id}` | Edit your own review (**403** for another author). |
| `POST /reviews/{id}/vote` | Vote a review helpful/unhelpful (`helpful`). |
| `POST /reviews/{id}/response` | Subject-owner (vendor/restaurant) public response (`body`). Requires an owner role. |
| `GET /reviews/me` | The authenticated user's reviews. |

## Moderation workspace (moderator role)

| Method & Path | Purpose |
|---|---|
| `GET /reviews/moderation/queue` | Reviews awaiting a decision (oldest first). |
| `POST /reviews/moderation/{id}/approve` | Publish a held review and re-project the subject rating. |
| `POST /reviews/moderation/{id}/reject` | Reject a held review with a `reason`. |
| `POST /reviews/moderation/{id}/remove` | Remove a published review with a `reason` (re-projects the rating). |

## Admin analytics (moderator role)

| Method & Path | Purpose |
|---|---|
| `GET /reviews/admin/analytics` | Moderation funnel, star distribution, verified rate, spread by subject type. |
| `GET /reviews/admin/top-rated/{subjectType}` | The highest-rated subjects of a type (`min_count`, `limit`). |

## Decoupling & events

- **Verified purchase** is derived from published order events, not by querying
  any order table. A config `eligibility_events` map ties an event name (e.g.
  `marketplace.order_placed`) to the subject type and the event fields carrying
  the buyer id and subject id; the ledger records `(user, subject)` eligibility
  idempotently and the review is stamped `verified_purchase` at submit time.
- **Ratings flow outward.** Publishing (or approving/removing) a review
  re-projects the subject's `RatingSummary` and publishes
  `reviews.rating_summary_updated`. Other contexts consume that event — they
  never compute ratings themselves. The summary is written only by the projector.
- Other published events: `reviews.review_published` (cues owner notifications)
  and `reviews.review_flagged` (cues the moderation queue).

## Error codes

| HTTP | When |
|---|---|
| `403` | Editing another author's review; moderator/owner role required. |
| `404` | Unknown review. |
| `409` | A second review of the same subject by the same author. |
| `422` | Invalid rating (outside 1–5), too many photos, an illegal state transition, or an unknown subject type. |
