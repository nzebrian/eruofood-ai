import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Layout } from '@shared/components/Layout';
import { catalogApi } from '../catalogApi';
import type { RecipeSummary } from '../types';

export function FavouritesPage(): React.JSX.Element {
  const [recipes, setRecipes] = useState<RecipeSummary[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    catalogApi
      .favourites()
      .then((page) => setRecipes(page.data))
      .catch(() => setRecipes([]))
      .finally(() => setLoading(false));
  }, []);

  return (
    <Layout>
      <h1>My favourite recipes</h1>
      {loading ? (
        <p>Loading…</p>
      ) : recipes.length === 0 ? (
        <p>You haven&apos;t favourited any recipes yet.</p>
      ) : (
        <ul className="list">
          {recipes.map((r) => (
            <li key={r.id}>
              <Link to={`/recipes/${r.slug}`}>{r.title}</Link>
            </li>
          ))}
        </ul>
      )}
    </Layout>
  );
}
