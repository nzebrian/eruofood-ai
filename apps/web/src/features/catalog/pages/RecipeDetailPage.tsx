import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { useAuth } from '@features/auth/useAuth';
import { catalogApi } from '../catalogApi';
import type { Recipe, RecipeReview } from '../types';

export function RecipeDetailPage(): React.JSX.Element {
  const { slug = '' } = useParams();
  const { user } = useAuth();
  const [recipe, setRecipe] = useState<Recipe | null>(null);
  const [reviews, setReviews] = useState<RecipeReview[]>([]);
  const [favourited, setFavourited] = useState(false);
  const [rating, setRating] = useState(5);
  const [comment, setComment] = useState('');

  useEffect(() => {
    catalogApi
      .recipe(slug)
      .then((r) => {
        setRecipe(r);
        setFavourited(r.is_favourited);
        return catalogApi.recipeReviews(r.id);
      })
      .then((page) => setReviews(page.data))
      .catch(() => setRecipe(null));
  }, [slug]);

  async function toggleFavourite(): Promise<void> {
    if (!recipe) return;
    if (favourited) {
      await catalogApi.removeFavourite(recipe.id);
      setFavourited(false);
    } else {
      await catalogApi.addFavourite(recipe.id);
      setFavourited(true);
    }
  }

  async function submitReview(e: React.FormEvent): Promise<void> {
    e.preventDefault();
    if (!recipe) return;
    await catalogApi.submitReview(recipe.id, rating, comment || null);
    const page = await catalogApi.recipeReviews(recipe.id);
    setReviews(page.data);
    setComment('');
  }

  if (!recipe) return <Layout>{<p>Loading…</p>}</Layout>;

  return (
    <Layout>
      <h1>{recipe.title}</h1>
      <p className="muted">
        {recipe.difficulty} · prep {recipe.prep_time_minutes}m · cook {recipe.cook_time_minutes}m ·
        serves {recipe.serving_size} · ★ {recipe.rating_average} ({recipe.rating_count}) · v
        {recipe.version}
      </p>
      {user && (
        <Button onClick={() => void toggleFavourite()}>
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
        <form onSubmit={(e) => void submitReview(e)} className="panel">
          <label className="field__label">Rating</label>
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
          <textarea
            className="field__input"
            placeholder="Share your thoughts…"
            value={comment}
            onChange={(e) => setComment(e.target.value)}
          />
          <Button type="submit">Submit review</Button>
        </form>
      )}
      <ul className="list">
        {reviews.map((r) => (
          <li key={r.id}>
            ★ {r.rating} — {r.comment ?? ''}
          </li>
        ))}
      </ul>
    </Layout>
  );
}
