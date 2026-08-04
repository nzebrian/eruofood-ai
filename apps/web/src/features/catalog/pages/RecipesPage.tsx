import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Layout } from '@shared/components/Layout';
import { catalogApi } from '../catalogApi';
import type { RecipeSummary } from '../types';

export function RecipesPage(): React.JSX.Element {
  const [recipes, setRecipes] = useState<RecipeSummary[]>([]);
  const [q, setQ] = useState('');
  const [difficulty, setDifficulty] = useState('');
  const [sort, setSort] = useState('recent');

  useEffect(() => {
    catalogApi
      .recipes({ q, difficulty, sort })
      .then((page) => setRecipes(page.data))
      .catch(() => setRecipes([]));
  }, [q, difficulty, sort]);

  return (
    <Layout>
      <h1>Recipes</h1>
      <div className="filters">
        <input
          className="field__input"
          placeholder="Search recipes…"
          value={q}
          onChange={(e) => setQ(e.target.value)}
        />
        <select className="field__input" value={difficulty} onChange={(e) => setDifficulty(e.target.value)}>
          <option value="">Any difficulty</option>
          <option value="easy">Easy</option>
          <option value="medium">Medium</option>
          <option value="hard">Hard</option>
        </select>
        <select className="field__input" value={sort} onChange={(e) => setSort(e.target.value)}>
          <option value="recent">Newest</option>
          <option value="rating">Top rated</option>
          <option value="quick">Quickest</option>
        </select>
      </div>
      <ul className="list">
        {recipes.map((r) => (
          <li key={r.id}>
            <Link to={`/recipes/${r.slug}`}>{r.title}</Link>{' '}
            <span className="muted">
              · {r.difficulty} · {r.total_time_minutes} min · ★ {r.rating_average} ({r.rating_count})
            </span>
          </li>
        ))}
      </ul>
    </Layout>
  );
}
