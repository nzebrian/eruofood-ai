import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Layout } from '@shared/components/Layout';
import { catalogApi } from '../catalogApi';
import type { Category, FoodSummary } from '../types';

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
  const [foods, setFoods] = useState<FoodSummary[]>([]);
  const [categories, setCategories] = useState<Category[]>([]);
  const [q, setQ] = useState('');
  const [categoryId, setCategoryId] = useState('');
  const [region, setRegion] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    catalogApi
      .categories()
      .then(setCategories)
      .catch(() => setCategories([]));
  }, []);

  useEffect(() => {
    setLoading(true);
    catalogApi
      .foods({ q, category_id: categoryId, region })
      .then((page) => setFoods(page.data))
      .catch(() => setFoods([]))
      .finally(() => setLoading(false));
  }, [q, categoryId, region]);

  return (
    <Layout>
      <h1>Nigerian Food Database</h1>

      <div className="filters">
        <input
          className="field__input"
          placeholder="Search foods…"
          value={q}
          onChange={(e) => setQ(e.target.value)}
        />
        <select className="field__input" value={categoryId} onChange={(e) => setCategoryId(e.target.value)}>
          <option value="">All categories</option>
          {categories.map((c) => (
            <option key={c.id} value={c.id}>
              {c.name}
            </option>
          ))}
        </select>
        <select className="field__input" value={region} onChange={(e) => setRegion(e.target.value)}>
          <option value="">All regions</option>
          {REGIONS.map((r) => (
            <option key={r} value={r}>
              {r.replace(/_/g, ' ')}
            </option>
          ))}
        </select>
      </div>

      {loading ? (
        <p>Loading…</p>
      ) : foods.length === 0 ? (
        <p>No foods found.</p>
      ) : (
        <div className="grid">
          {foods.map((food) => (
            <Link key={food.id} to={`/foods/${food.slug}`} className="card">
              {food.primary_image ? (
                <img src={food.primary_image} alt={food.name} className="card__img" />
              ) : (
                <div className="card__img card__img--placeholder">🍲</div>
              )}
              <div className="card__body">
                <h3>{food.name}</h3>
                <p className="card__meta">{food.region.replace(/_/g, ' ')}</p>
              </div>
            </Link>
          ))}
        </div>
      )}
    </Layout>
  );
}
