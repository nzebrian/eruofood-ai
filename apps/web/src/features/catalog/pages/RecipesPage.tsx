import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Layout } from '@shared/components/Layout';
import { AsyncView, EmptyState } from '@shared/components/StateViews';
import { useAsyncData } from '@shared/hooks/useAsyncData';
import { catalogApi } from '../catalogApi';

export function RecipesPage(): React.JSX.Element {
  const [q, setQ] = useState('');
  const [difficulty, setDifficulty] = useState('');
  const [sort, setSort] = useState('recent');

  const recipes = useAsyncData(
    () => catalogApi.recipes({ q, difficulty, sort }),
    `catalog|recipes|${q}|${difficulty}|${sort}`,
  );

  const hasFilters = q !== '' || difficulty !== '';

  return (
    <Layout>
      <h1>Recipes</h1>
      <div className="filters">
        <input
          className="field__input"
          placeholder="Search recipes…"
          aria-label="Search recipes"
          value={q}
          onChange={(e) => setQ(e.target.value)}
        />
        <select
          className="field__input"
          aria-label="Filter by difficulty"
          value={difficulty}
          onChange={(e) => setDifficulty(e.target.value)}
        >
          <option value="">Any difficulty</option>
          <option value="easy">Easy</option>
          <option value="medium">Medium</option>
          <option value="hard">Hard</option>
        </select>
        <select
          className="field__input"
          aria-label="Sort recipes"
          value={sort}
          onChange={(e) => setSort(e.target.value)}
        >
          <option value="recent">Newest</option>
          <option value="rating">Top rated</option>
          <option value="quick">Quickest</option>
        </select>
      </div>

      <AsyncView
        state={recipes.state}
        loadingLabel="Loading recipes…"
        errorTitle="We could not load the recipes"
        onRetry={recipes.reload}
      >
        {(page) =>
          page.data.length === 0 ? (
            <EmptyState
              title={hasFilters ? 'No recipes match those filters' : 'No recipes yet'}
              description={
                hasFilters
                  ? 'Try a different search term or difficulty.'
                  : 'Recipes will appear here as they are published.'
              }
            />
          ) : (
            <ul className="list">
              {page.data.map((r) => (
                <li key={r.id}>
                  <Link to={`/recipes/${r.slug}`}>{r.title}</Link>{' '}
                  <span className="muted">
                    · {r.difficulty} · {r.total_time_minutes} min · ★ {r.rating_average} (
                    {r.rating_count})
                  </span>
                </li>
              ))}
            </ul>
          )
        }
      </AsyncView>
    </Layout>
  );
}
