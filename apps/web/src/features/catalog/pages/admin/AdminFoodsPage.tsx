import { useEffect, useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { FormField } from '@shared/components/FormField';
import { AsyncView, EmptyState } from '@shared/components/StateViews';
import { describeError, useAsyncData } from '@shared/hooks/useAsyncData';
import { apiClient } from '@lib/apiClient';
import { catalogApi } from '../../catalogApi';
import type { Food, Paginated } from '../../types';

const REGIONS = [
  'north_central',
  'north_east',
  'north_west',
  'south_east',
  'south_south',
  'south_west',
  'nationwide',
];

/** Minimal admin dashboard: list foods and create a new one. */
export function AdminFoodsPage(): React.JSX.Element {
  const [name, setName] = useState('');
  const [categoryId, setCategoryId] = useState('');
  const [region, setRegion] = useState('south_west');
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  // Admin view lists all statuses via the public search (published); drafts
  // appear after a refresh.
  const foods = useAsyncData(() => catalogApi.foods({ per_page: 50 }), 'catalog|admin|foods');
  const categories = useAsyncData(() => catalogApi.categories(), 'catalog|admin|categories');
  const categoryOptions = categories.state.status === 'ready' ? categories.state.data : [];

  // Default the select to the first category once the list arrives, without
  // clobbering a choice the administrator has already made. Keyed on the id
  // rather than the array: a fresh array identity on every render would make
  // this effect re-run forever.
  const firstCategoryId = categoryOptions[0]?.id ?? '';
  useEffect(() => {
    if (categoryId === '' && firstCategoryId !== '') setCategoryId(firstCategoryId);
  }, [firstCategoryId, categoryId]);

  async function createFood(e: React.FormEvent): Promise<void> {
    e.preventDefault();
    setError(null);
    setMessage(null);
    setBusy(true);
    try {
      const food = await apiClient.post<Food>('/admin/foods', {
        name,
        category_id: categoryId,
        region,
      });
      await apiClient.post<Paginated<Food>>(`/admin/foods/${food.id}/publish`);
      setMessage(`Created and published "${food.name}".`);
      setName('');
      foods.reload();
    } catch (err) {
      setError(describeError(err, 'Failed to create food.'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Layout>
      <h1>Admin · Foods</h1>

      <form onSubmit={(e) => void createFood(e)} className="panel">
        <h2>Add a food</h2>
        {error !== null ? (
          <p className="auth__error" role="alert">
            {error}
          </p>
        ) : null}
        {message !== null ? (
          <p className="auth__success" role="status">
            {message}
          </p>
        ) : null}
        <FormField
          label="Name"
          name="name"
          value={name}
          onChange={(e) => setName(e.target.value)}
          required
        />
        <label className="field">
          <span className="field__label">Category</span>
          <select
            className="field__input"
            value={categoryId}
            onChange={(e) => setCategoryId(e.target.value)}
          >
            {categoryOptions.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </select>
        </label>
        <label className="field">
          <span className="field__label">Region</span>
          <select
            className="field__input"
            value={region}
            onChange={(e) => setRegion(e.target.value)}
          >
            {REGIONS.map((r) => (
              <option key={r} value={r}>
                {r.replace(/_/g, ' ')}
              </option>
            ))}
          </select>
        </label>
        <Button type="submit" busy={busy}>
          Create &amp; publish
        </Button>
      </form>

      <h2>Published foods</h2>
      <AsyncView
        state={foods.state}
        loadingLabel="Loading foods…"
        errorTitle="We could not load the food list"
        onRetry={foods.reload}
      >
        {(page) =>
          page.data.length === 0 ? (
            <EmptyState
              title="No published foods"
              description="Create one with the form above and it will appear here."
            />
          ) : (
            <ul className="list">
              {page.data.map((f) => (
                <li key={f.id}>
                  {f.name}{' '}
                  <span className="muted">
                    · {f.region.replace(/_/g, ' ')} · {f.status}
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
