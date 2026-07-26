import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Layout } from '@shared/components/Layout';
import { marketplaceApi } from '../marketplaceApi';
import type { VendorSummary } from '../types';

/** Browse & search verified vendors (restaurants, kitchens, market vendors). */
export function VendorsPage(): React.JSX.Element {
  const [vendors, setVendors] = useState<VendorSummary[]>([]);
  const [q, setQ] = useState('');
  const [loading, setLoading] = useState(true);

  function load(term: string): void {
    setLoading(true);
    marketplaceApi
      .vendors({ q: term, per_page: 30 })
      .then((page) => setVendors(page.data))
      .catch(() => setVendors([]))
      .finally(() => setLoading(false));
  }

  useEffect(() => load(''), []);

  return (
    <Layout>
      <h1>Restaurants &amp; vendors</h1>
      <form
        className="chat__form"
        onSubmit={(e) => {
          e.preventDefault();
          load(q);
        }}
      >
        <input
          className="field__input"
          value={q}
          onChange={(e) => setQ(e.target.value)}
          placeholder="Search vendors…"
          aria-label="Search vendors"
        />
        <button className="button" type="submit">
          Search
        </button>
      </form>

      {loading ? (
        <p>Loading…</p>
      ) : vendors.length === 0 ? (
        <p className="muted">No vendors found.</p>
      ) : (
        <ul className="list">
          {vendors.map((v) => (
            <li key={v.id}>
              <Link to={`/vendors/${v.slug}`}>
                <strong>{v.name}</strong>
              </Link>{' '}
              — {v.category} · ⭐ {v.rating_average} ({v.rating_count})
              {v.featured ? ' · ⭐ featured' : ''}
            </li>
          ))}
        </ul>
      )}
    </Layout>
  );
}
