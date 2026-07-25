import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { Layout } from '@shared/components/Layout';
import { catalogApi } from '../catalogApi';
import type { Food, RecipeSummary } from '../types';

export function FoodDetailPage(): React.JSX.Element {
  const { slug = '' } = useParams();
  const [food, setFood] = useState<Food | null>(null);
  const [recipes, setRecipes] = useState<RecipeSummary[]>([]);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    catalogApi
      .food(slug)
      .then((f) => {
        setFood(f);
        return catalogApi.recipesForFood(f.id);
      })
      .then((page) => setRecipes(page.data))
      .catch(() => setError('Food not found.'));
  }, [slug]);

  if (error) return <Layout>{<p>{error}</p>}</Layout>;
  if (!food) return <Layout>{<p>Loading…</p>}</Layout>;

  return (
    <Layout>
      <h1>{food.name}</h1>
      {food.local_names.length > 0 && (
        <p className="muted">
          Also known as: {food.local_names.map((l) => `${l.name} (${l.language})`).join(', ')}
        </p>
      )}
      <p className="muted">
        {food.region.replace(/_/g, ' ')}
        {food.states.length > 0 ? ` · ${food.states.join(', ')}` : ''}
      </p>
      {food.description && <p>{food.description}</p>}

      {food.nutrition && (
        <section className="panel">
          <h3>Nutrition ({food.nutrition.basis.replace(/_/g, ' ')})</h3>
          <ul>
            <li>{food.nutrition.calories} kcal</li>
            <li>Protein: {food.nutrition.protein_grams} g</li>
            <li>Carbs: {food.nutrition.carbohydrate_grams} g</li>
            <li>Fat: {food.nutrition.fat_grams} g</li>
          </ul>
        </section>
      )}

      <h2>Recipes</h2>
      {recipes.length === 0 ? (
        <p>No recipes yet.</p>
      ) : (
        <ul className="list">
          {recipes.map((r) => (
            <li key={r.id}>
              <Link to={`/recipes/${r.slug}`}>{r.title}</Link>{' '}
              <span className="muted">
                · {r.difficulty} · {r.total_time_minutes} min · ★ {r.rating_average}
              </span>
            </li>
          ))}
        </ul>
      )}
    </Layout>
  );
}
