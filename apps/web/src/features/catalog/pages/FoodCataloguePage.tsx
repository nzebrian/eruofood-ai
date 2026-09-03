import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Layout } from '@shared/components/Layout';
import { AsyncView, EmptyState } from '@shared/components/StateViews';
import { useAsyncData } from '@shared/hooks/useAsyncData';
import { catalogApi } from '../catalogApi';
import type { FoodSummary } from '../types';

const REGIONS = [
  'north_central',
  'north_east',
  'north_west',
  'south_east',
  'south_south',
  'south_west',
  'nationwide',
];

export function FoodCataloguePage(): React.JSX.Element {
  const [q, setQ] = useState('');
  const [categoryId, setCategoryId] = useState('');
  const [region, setRegion] = useState('');

  // The category list only populates a filter. If it fails the page is still
  // usable, so it degrades to "All categories" rather than taking the screen
  // down with it — but it is not silently swallowed either: `state` still
  // records the failure for anything that wants to look.
  const categories = useAsyncData(() => catalogApi.categories(), 'catalog|categories');
  const categoryOptions = categories.state.status === 'ready' ? categories.state.data : [];

  const foods = useAsyncData(
    () => catalogApi.foods({ q, category_id: categoryId, region }),
    `catalog|foods|${q}|${categoryId}|${region}`,
  );

  const hasFilters = q !== '' || categoryId !== '' || region !== '';

  return (
    <Layout>
      <h1>Nigerian Food Database</h1>

      <div className="filters">
        <input
          className="field__input"
          placeholder="Search foods…"
          aria-label="Search foods"
          value={q}
          onChange={(e) => setQ(e.target.value)}
        />
        <select
          className="field__input"
          aria-label="Filter by category"
          value={categoryId}
          onChange={(e) => setCategoryId(e.target.value)}
        >
          <option value="">All categories</option>
          {categoryOptions.map((c) => (
            <option key={c.id} value={c.id}>
              {c.name}
            </option>
          ))}
        </select>
        <select
          className="field__input"
          aria-label="Filter by region"
          value={region}
          onChange={(e) => setRegion(e.target.value)}
        >
          <option value="">All regions</option>
          {REGIONS.map((r) => (
            <option key={r} value={r}>
              {r.replace(/_/g, ' ')}
            </option>
          ))}
        </select>
      </div>

      <AsyncView
        state={foods.state}
        loadingLabel="Loading foods…"
        errorTitle="We could not load the food database"
        onRetry={foods.reload}
      >
        {(page) =>
          page.data.length === 0 ? (
            <EmptyState
              title={hasFilters ? 'No foods match those filters' : 'No foods yet'}
              description={
                hasFilters
                  ? 'Try a different search term, category or region.'
                  : 'The catalogue is empty for now — check back soon.'
              }
            />
          ) : (
            <div className="grid">
              {page.data.map((food: FoodSummary) => (
                <Link key={food.id} to={`/foods/${food.slug}`} className="card">
                  {food.primary_image ? (
                    <img src={food.primary_image} alt={food.name} className="card__img" />
                  ) : (
                    <div className="card__img card__img--placeholder" aria-hidden="true">
                      🍲
                    </div>
                  )}
                  <div className="card__body">
                    <h3>{food.name}</h3>
                    <p className="card__meta">{food.region.replace(/_/g, ' ')}</p>
                  </div>
                </Link>
              ))}
            </div>
          )
        }
      </AsyncView>
    </Layout>
  );
}
