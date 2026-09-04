import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { AsyncView, EmptyState, ErrorState } from '@shared/components/StateViews';
import { describeError, useAsyncData } from '@shared/hooks/useAsyncData';
import { useAuth } from '@features/auth/useAuth';
import { catalogApi } from '../catalogApi';

export function RecipeDetailPage(): React.JSX.Element {
  const { slug = '' } = useParams();
  const { user } = useAuth();
  const [rating, setRating] = useState(5);
  const [comment, setComment] = useState('');
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);

  // Previously `.catch(() => setRecipe(null))`, which rendered "Loading…"
  // forever: a failed request was indistinguishable from one still running,
  // and the page never recovered.
  const detail = useAsyncData(async () => {
    const recipe = await catalogApi.recipe(slug);
    const reviews = await catalogApi.recipeReviews(recipe.id);
    return { recipe, reviews: reviews.data, favourited: recipe.is_favourited };
  }, `catalog|recipe|${slug}`);

  async function toggleFavourite(favourited: boolean, recipeId: string): Promise<void> {
    setBusy(true);
    setActionError(null);
    try {
      if (favourited) {
        await catalogApi.removeFavourite(recipeId);
      } else {
        await catalogApi.addFavourite(recipeId);
      }
      detail.updateData((previous) => ({ ...previous, favourited: !favourited }));
    } catch (err) {
      setActionError(describeError(err, 'Could not update your favourites.'));
    } finally {
      setBusy(false);
    }
  }

  async function submitReview(event: React.FormEvent, recipeId: string): Promise<void> {
    event.preventDefault();
    setBusy(true);
    setActionError(null);
    try {
      await catalogApi.submitReview(recipeId, rating, comment || null);
      const page = await catalogApi.recipeReviews(recipeId);
      detail.updateData((previous) => ({ ...previous, reviews: page.data }));
      setComment('');
    } catch (err) {
      setActionError(describeError(err, 'Could not submit your review.'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Layout>
      <AsyncView
        state={detail.state}
        loadingLabel="Loading this recipe…"
        errorTitle="We could not load this recipe"
        onRetry={detail.reload}
      >
        {({ recipe, reviews, favourited }) => (
          <>
            <h1>{recipe.title}</h1>
            <p className="muted">
              {recipe.difficulty} · prep {recipe.prep_time_minutes}m · cook{' '}
              {recipe.cook_time_minutes}m · serves {recipe.serving_size} · ★ {recipe.rating_average}{' '}
              ({recipe.rating_count}) · v{recipe.version}
            </p>

            {actionError !== null ? (
              <ErrorState message={actionError} title="That did not work" />
            ) : null}

            {user && (
              <Button busy={busy} onClick={() => void toggleFavourite(favourited, recipe.id)}>
                {favourited ? '★ Favourited' : '☆ Add to favourites'}
              </Button>
            )}
            {recipe.summary && <p>{recipe.summary}</p>}

            <h2>Ingredients</h2>
            <ul className="list">
              {recipe.ingredients.map((i, idx) => (
                <li key={idx}>
                  {i.amount} {i.unit} {i.name}
                  {i.note ? ` — ${i.note}` : ''}
                </li>
              ))}
            </ul>

            <h2>Steps</h2>
            <ol className="list">
              {recipe.steps.map((s) => (
                <li key={s.order}>
                  {s.instruction}
                  {s.duration_minutes ? ` (${s.duration_minutes} min)` : ''}
                </li>
              ))}
            </ol>

            {recipe.tips.length > 0 && (
              <>
                <h2>Tips</h2>
                <ul className="list">
                  {recipe.tips.map((t, i) => (
                    <li key={i}>{t}</li>
                  ))}
                </ul>
              </>
            )}

            <h2>Reviews</h2>
            {user && (
              <form onSubmit={(e) => void submitReview(e, recipe.id)} className="panel">
                {/* The label used to be a bare `<label>` with no `htmlFor` and
                    no wrapped control, so it named nothing. Wrapping is the
                    same association the shared FormField uses. */}
                <label className="field">
                  <span className="field__label">Rating</span>
                  <select
                    className="field__input"
                    value={rating}
                    onChange={(e) => setRating(Number(e.target.value))}
                  >
                    {[5, 4, 3, 2, 1].map((n) => (
                      <option key={n} value={n}>
                        {n} ★
                      </option>
                    ))}
                  </select>
                </label>
                <label className="field">
                  <span className="field__label">Your review</span>
                  <textarea
                    className="field__input"
                    placeholder="Share your thoughts…"
                    value={comment}
                    onChange={(e) => setComment(e.target.value)}
                  />
                </label>
                <Button type="submit" busy={busy}>
                  Submit review
                </Button>
              </form>
            )}

            {reviews.length === 0 ? (
              <EmptyState
                title="No reviews yet"
                description={
                  user
                    ? 'Be the first to say how it turned out.'
                    : 'Sign in to be the first to review this recipe.'
                }
              />
            ) : (
              <ul className="list">
                {reviews.map((r) => (
                  <li key={r.id}>
                    ★ {r.rating} — {r.comment ?? ''}
                  </li>
                ))}
              </ul>
            )}
          </>
        )}
      </AsyncView>
    </Layout>
  );
}
