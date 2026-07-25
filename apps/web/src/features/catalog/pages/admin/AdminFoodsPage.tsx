import { useEffect, useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { FormField } from '@shared/components/FormField';
import { apiClient, ApiRequestError } from '@lib/apiClient';
import { catalogApi } from '../../catalogApi';
import type { Category, Food, FoodSummary, Paginated } from '../../types';

/** Minimal admin dashboard: list foods and create a new one. */
export function AdminFoodsPage(): React.JSX.Element {
  const [foods, setFoods] = useState<FoodSummary[]>([]);
  const [categories, setCategories] = useState<Category[]>([]);
  const [name, setName] = useState('');
  const [categoryId, setCategoryId] = useState('');
  const [region, setRegion] = useState('south_west');
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  function reload(): void {
    // Admin view lists all statuses via the public search (published) + drafts appear after refresh.
    catalogApi
      .foods({ per_page: 50 })
      .then((page) => setFoods(page.data))
      .catch(() => setFoods([]));
  }

  useEffect(() => {
    reload();
    catalogApi
      .categories()
      .then((c) => {
        setCategories(c);
        if (c[0]) setCategoryId(c[0].id);
      })
      .catch(() => setCategories([]));
  }, []);

  async function createFood(e: React.FormEvent): Promise<void> {
    e.preventDefault();
    setError(null);
    setMessage(null);
    try {
      const food = await apiClient.post<Food>('/admin/foods', {
        name,
        category_id: categoryId,
        region,
      });
      await apiClient.post<Paginated<Food>>(`/admin/foods/${food.id}/publish`);
      setMessage(`Created and published "${food.name}".`);
      setName('');
      reload();
    } catch (err) {
      setError(err instanceof ApiRequestError ? err.error.message : 'Failed to create food.');
    }
  }

  return (
    <Layout>
      <h1>Admin · Foods</h1>

      <form onSubmit={(e) => void createFood(e)} className="panel">
        <h3>Add a food</h3>
        {error ? <p className="auth__error">{error}</p> : null}
        {message ? <p className="auth__success">{message}</p> : null}
        <FormField label="Name" name="name" value={name} onChange={(e) => setName(e.target.value)} required />
        <label className="field">
          <span className="field__label">Category</span>
          <select className="field__input" value={categoryId} onChange={(e) => setCategoryId(e.target.value)}>
            {categories.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </select>
        </label>
        <label className="field">
          <span className="field__label">Region</span>
          <select className="field__input" value={region} onChange={(e) => setRegion(e.target.value)}>
            {['north_central', 'north_east', 'north_west', 'south_east', 'south_south', 'south_west', 'nationwide'].map(
              (r) => (
                <option key={r} value={r}>
                  {r.replace(/_/g, ' ')}
                </option>
              ),
            )}
          </select>
        </label>
        <Button type="submit">Create & publish</Button>
      </form>

      <h2>Published foods</h2>
      <ul className="list">
        {foods.map((f) => (
          <li key={f.id}>
            {f.name} <span className="muted">· {f.region.replace(/_/g, ' ')} · {f.status}</span>
          </li>
        ))}
      </ul>
    </Layout>
  );
}
