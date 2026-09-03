import { useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { AsyncView, EmptyState, ErrorState } from '@shared/components/StateViews';
import { describeError, useAsyncData } from '@shared/hooks/useAsyncData';
import { reviewsApi } from '../reviewsApi';
import { REVIEW_SORTS, type ReviewSort, type SubjectType } from '../types';

const SUBJECT_TYPES: SubjectType[] = ['product', 'food', 'recipe', 'vendor', 'restaurant', 'rider'];

function Stars({ value }: { value: number }): React.JSX.Element {
  const rounded = Math.round(value);
  return (
    <span className="review-stars" aria-label={`${value} out of 5`}>
      {[1, 2, 3, 4, 5].map((n) => (
        <span key={n} className={n <= rounded ? 'review-star is-on' : 'review-star'} aria-hidden="true">
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
  const [rating, setRating] = useState(5);
  const [title, setTitle] = useState('');
  const [body, setBody] = useState('');
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  const key = `reviews|${subjectType}|${subjectId}|${sort}|${String(verifiedOnly)}`;
  const data = useAsyncData(async () => {
    const [summary, page] = await Promise.all([
      reviewsApi.summary(subjectType, subjectId),
      reviewsApi.forSubject(subjectType, subjectId, { sort, verified: verifiedOnly }),
    ]);
    return { summary, reviews: page.data };
  }, key);

  function submit(e: React.FormEvent): void {
    e.preventDefault();
    setBusy(true);
    setNotice(null);
    setActionError(null);
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
        data.reload();
      })
      .catch((err: unknown) => setActionError(describeError(err, 'Could not submit your review.')))
      .finally(() => setBusy(false));
  }

  function vote(id: string): void {
    setActionError(null);
    reviewsApi
      .vote(id, true)
      .then(() => data.reload())
      .catch((err: unknown) => setActionError(describeError(err, 'Could not record your vote.')));
  }

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
        <select
          value={sort}
          onChange={(e) => setSort(e.target.value as ReviewSort)}
          aria-label="Sort reviews"
        >
          {REVIEW_SORTS.map((s) => (
            <option key={s} value={s}>
              {s}
            </option>
          ))}
        </select>
        <label className="reviews-verified">
          <input
            type="checkbox"
            checked={verifiedOnly}
            onChange={(e) => setVerifiedOnly(e.target.checked)}
          />
          Verified only
        </label>
      </div>

      {actionError !== null ? <ErrorState message={actionError} title="That did not work" /> : null}

      <AsyncView
        state={data.state}
        loadingLabel="Loading reviews…"
        errorTitle="We could not load these reviews"
        onRetry={data.reload}
      >
        {({ summary, reviews }) => (
          <>
            <section className="review-summary">
              <div className="review-summary__score">
                <span className="review-summary__average">{summary.average.toFixed(1)}</span>
                <Stars value={summary.average} />
                <span className="muted">
                  {summary.count} review{summary.count === 1 ? '' : 's'} · {summary.verified_count}{' '}
                  verified
                </span>
              </div>
              <ul className="review-distribution">
                {[5, 4, 3, 2, 1].map((star) => (
                  <li key={star}>
                    <span>{star}★</span>
                    <span className="review-distribution__count">
                      {summary.distribution[String(star)] ?? 0}
                    </span>
                  </li>
                ))}
              </ul>
            </section>

            <div className="reviews-layout">
              <form className="review-form" onSubmit={submit}>
                <h2>Write a review</h2>
                <select
                  value={rating}
                  onChange={(e) => setRating(Number(e.target.value))}
                  aria-label="Your rating"
                >
                  {[5, 4, 3, 2, 1].map((n) => (
                    <option key={n} value={n}>
                      {n} star{n === 1 ? '' : 's'}
                    </option>
                  ))}
                </select>
                <input
                  value={title}
                  onChange={(e) => setTitle(e.target.value)}
                  placeholder="Title"
                  aria-label="Review title"
                />
                <textarea
                  value={body}
                  onChange={(e) => setBody(e.target.value)}
                  placeholder="Share your experience"
                  aria-label="Review body"
                  rows={4}
                />
                <Button type="submit" busy={busy}>
                  Submit review
                </Button>
                {notice !== null && (
                  <p className="review-notice" role="status">
                    {notice}
                  </p>
                )}
              </form>

              {reviews.length === 0 ? (
                <EmptyState
                  title="No reviews yet"
                  description="Be the first to say what you thought."
                />
              ) : (
                <ul className="review-list">
                  {reviews.map((r) => (
                    <li key={r.id} className="review-item">
                      <div className="review-item__head">
                        <Stars value={r.rating} />
                        {r.verified_purchase && (
                          <span className="badge badge--verified">Verified purchase</span>
                        )}
                      </div>
                      {r.title !== null && <h3 className="review-item__title">{r.title}</h3>}
                      {r.body !== null && <p className="review-item__body">{r.body}</p>}
                      {r.owner_response !== null && (
                        <blockquote className="review-item__response">
                          <strong>Owner response:</strong> {r.owner_response.body}
                        </blockquote>
                      )}
                      <div className="review-item__foot">
                        <button
                          type="button"
                          className="review-helpful"
                          onClick={() => vote(r.id)}
                        >
                          Helpful ({r.helpful_yes})
                          {r.title !== null ? (
                            <span className="sr-only"> — {r.title}</span>
                          ) : null}
                        </button>
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </>
        )}
      </AsyncView>
    </Layout>
  );
}
