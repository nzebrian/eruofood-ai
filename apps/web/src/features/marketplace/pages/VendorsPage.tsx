import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { AsyncView, EmptyState } from '@shared/components/StateViews';
import { useAsyncData } from '@shared/hooks/useAsyncData';
import { marketplaceApi } from '../marketplaceApi';

/** Browse & search verified vendors (restaurants, kitchens, market vendors). */
export function VendorsPage(): React.JSX.Element {
  const [q, setQ] = useState('');
  const [term, setTerm] = useState('');

  const vendors = useAsyncData(
    () => marketplaceApi.vendors({ q: term, per_page: 30 }),
    `marketplace|vendors|${term}`,
  );

  return (
    <Layout>
      <h1>Restaurants &amp; vendors</h1>
      <form
        className="chat__form"
        onSubmit={(e) => {
          e.preventDefault();
          setTerm(q);
        }}
      >
        <input
          className="field__input"
          value={q}
          onChange={(e) => setQ(e.target.value)}
          placeholder="Search vendors…"
          aria-label="Search vendors"
        />
        <Button type="submit">Search</Button>
      </form>

      <AsyncView
        state={vendors.state}
        loadingLabel="Loading vendors…"
        errorTitle="We could not load the vendor list"
        onRetry={vendors.reload}
      >
        {(page) =>
          page.data.length === 0 ? (
            <EmptyState
              title={term === '' ? 'No vendors yet' : `No vendors match “${term}”`}
              description={
                term === ''
                  ? 'Verified vendors will appear here as they join.'
                  : 'Try a shorter or different search term.'
              }
            />
          ) : (
            <ul className="list">
              {page.data.map((v) => (
                <li key={v.id}>
                  <Link to={`/vendors/${v.slug}`}>
                    <strong>{v.name}</strong>
                  </Link>{' '}
                  — {v.category} · ⭐ {v.rating_average} ({v.rating_count})
                  {v.featured ? ' · ⭐ featured' : ''}
                </li>
              ))}
            </ul>
          )
        }
      </AsyncView>
    </Layout>
  );
}
