import { useCallback, useEffect, useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { reviewsApi } from '../reviewsApi';
import { REVIEW_SORTS, type RatingSummary, type Review, type ReviewSort, type SubjectType } from '../types';

const SUBJECT_TYPES: SubjectType[] = ['product', 'food', 'recipe', 'vendor', 'restaurant', 'rider'];

function Stars({ value }: { value: number }): React.JSX.Element {
  const rounded = Math.round(value);
  return (
    <span className="review-stars" aria-label={`${value} out of 5`}>
      {[1, 2, 3, 4, 5].map((n) => (
        <span key={n} className={n <= rounded ? 'review-star is-on' : 'review-star'}>
          ★
        </span>
      ))}
    </span>
  );
}

/** Public storefront reviews for a subject: rating summary, review list, and a submit form. */
export function SubjectReviewsPage(): React.JSX.Element {
  const [subjectType, setSubjectType] = useState<SubjectType>('vendor');
  const [subjectId, setSubjectId] = useState('vendor-1');
  const [sort, setSort] = useState<ReviewSort>('newest');
  const [verifiedOnly, setVerifiedOnly] = useState(false);
  const [summary, setSummary] = useState<RatingSummary | null>(null);
  const [reviews, setReviews] = useState<Review[]>([]);
  const [rating, setRating] = useState(5);
  const [title, setTitle] = useState('');
  const [body, setBody] = useState('');
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);

  const refresh = useCallback((): void => {
    if (subjectId.trim() === '') return;
    reviewsApi
      .summary(subjectType, subjectId)
      .then(setSummary)
      .catch(() => setSummary(null));
    reviewsApi
      .forSubject(subjectType, subjectId, { sort, verified: verifiedOnly })
      .then((page) => setReviews(page.data))
      .catch(() => setReviews([]));
  }, [subjectType, subjectId, sort, verifiedOnly]);

  useEffect(refresh, [refresh]);

  const submit = (e: React.FormEvent): void => {
    e.preventDefault();
    setBusy(true);
    setNotice(null);
    reviewsApi
      .submit({ subject_type: subjectType, subject_id: subjectId, rating, title, body })
      .then((review) => {
        setTitle('');
        setBody('');
        setNotice(
          review.status === 'published'
            ? 'Thanks — your review is live.'
            : 'Thanks — your review is awaiting moderation.',
        );
        refresh();
      })
      .catch(() => setNotice('Could not submit your review.'))
      .finally(() => setBusy(false));
  };

  const vote = (id: string): void => {
    reviewsApi
      .vote(id, true)
      .then(() => refresh())
      .catch(() => undefined);
  };

  return (
    <Layout>
      <h1>Reviews &amp; Ratings</h1>

      <div className="reviews-controls">
        <select
          value={subjectType}
          onChange={(e) => setSubjectType(e.target.value as SubjectType)}
          aria-label="Subject type"
        >
          {SUBJECT_TYPES.map((t) => (
            <option key={t} value={t}>
              {t}
            </option>
          ))}
        </select>
        <input
          value={subjectId}
          onChange={(e) => setSubjectId(e.target.value)}
          placeholder="Subject id"
          aria-label="Subject id"
        />
        <select value={sort} onChange={(e) => setSort(e.target.value as ReviewSort)} aria-label="Sort">
          {REVIEW_SORTS.map((s) => (
            <option key={s} value={s}>
              {s}
            </option>
          ))}
        </select>
        <label className="reviews-verified">
          <input type="checkbox" checked={verifiedOnly} onChange={(e) => setVerifiedOnly(e.target.checked)} />
          Verified only
        </label>
      </div>

      {summary !== null && (
        <section className="review-summary">
          <div className="review-summary__score">
            <span className="review-summary__average">{summary.average.toFixed(1)}</span>
            <Stars value={summary.average} />
            <span className="muted">
              {summary.count} review{summary.count === 1 ? '' : 's'} · {summary.verified_count} verified
            </span>
          </div>
          <ul className="review-distribution">
            {[5, 4, 3, 2, 1].map((star) => (
              <li key={star}>
                <span>{star}★</span>
                <span className="review-distribution__count">{summary.distribution[String(star)] ?? 0}</span>
              </li>
            ))}
          </ul>
        </section>
      )}

      <div className="reviews-layout">
        <form className="review-form" onSubmit={submit}>
          <h2>Write a review</h2>
          <select value={rating} onChange={(e) => setRating(Number(e.target.value))} aria-label="Your rating">
            {[5, 4, 3, 2, 1].map((n) => (
              <option key={n} value={n}>
                {n} star{n === 1 ? '' : 's'}
              </option>
            ))}
          </select>
          <input value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Title" aria-label="Title" />
          <textarea
            value={body}
            onChange={(e) => setBody(e.target.value)}
            placeholder="Share your experience"
            aria-label="Body"
            rows={4}
          />
          <Button type="submit" busy={busy}>
            Submit review
          </Button>
          {notice !== null && <p className="review-notice">{notice}</p>}
        </form>

        <ul className="review-list">
          {reviews.length === 0 ? (
            <li className="muted">No reviews yet — be the first.</li>
          ) : (
            reviews.map((r) => (
              <li key={r.id} className="review-item">
                <div className="review-item__head">
                  <Stars value={r.rating} />
                  {r.verified_purchase && <span className="badge badge--verified">Verified purchase</span>}
                </div>
                {r.title !== null && <h3 className="review-item__title">{r.title}</h3>}
                {r.body !== null && <p className="review-item__body">{r.body}</p>}
                {r.owner_response !== null && (
                  <blockquote className="review-item__response">
                    <strong>Owner response:</strong> {r.owner_response.body}
                  </blockquote>
                )}
                <div className="review-item__foot">
                  <button className="review-helpful" onClick={() => vote(r.id)}>
                    Helpful ({r.helpful_yes})
                  </button>
                </div>
              </li>
            ))
          )}
        </ul>
      </div>
    </Layout>
  );
}
