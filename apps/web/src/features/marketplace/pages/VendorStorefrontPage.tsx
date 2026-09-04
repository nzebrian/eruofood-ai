import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { AsyncView, EmptyState } from '@shared/components/StateViews';
import { describeError, useAsyncData } from '@shared/hooks/useAsyncData';
import { marketplaceApi } from '../marketplaceApi';
import { formatMoney, type MenuItem } from '../types';

/** A vendor's storefront: profile + menu with add-to-cart. */
export function VendorStorefrontPage(): React.JSX.Element {
  const { slug = '' } = useParams();
  const [notice, setNotice] = useState<string | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const [addingId, setAddingId] = useState<string | null>(null);

  // `.catch(() => setVendor(null))` was also the loading condition, so a
  // failed storefront showed "Loading…" indefinitely.
  const storefront = useAsyncData(async () => {
    const vendor = await marketplaceApi.vendor(slug);
    const menu = await marketplaceApi.menu(vendor.id);
    return { vendor, menu };
  }, `marketplace|vendor|${slug}`);

  function add(item: MenuItem): void {
    setAddingId(item.id);
    setNotice(null);
    setFailure(null);
    marketplaceApi
      .addToCart(item.id, 1)
      .then(() => setNotice(`Added ${item.name} to your cart.`))
      .catch((err: unknown) => setFailure(describeError(err, 'Could not add to cart.')))
      .finally(() => setAddingId(null));
  }

  return (
    <Layout>
      <AsyncView
        state={storefront.state}
        loadingLabel="Loading this vendor…"
        errorTitle="We could not load this vendor"
        onRetry={storefront.reload}
      >
        {({ vendor, menu }) => (
          <>
            <h1>{vendor.name}</h1>
            <p className="muted">
              {vendor.category} · {vendor.type.replace('_', ' ')} · ⭐ {vendor.rating_average} (
              {vendor.rating_count})
            </p>
            {vendor.description ? <p>{vendor.description}</p> : null}

            {notice !== null ? (
              <p className="success" role="status">
                {notice}
              </p>
            ) : null}
            {failure !== null ? (
              <p className="error" role="alert">
                {failure}
              </p>
            ) : null}

            <h2>Menu</h2>
            {menu.length === 0 ? (
              <EmptyState
                title="Nothing on the menu yet"
                description="This vendor has not published any items."
              />
            ) : (
              <ul className="list">
                {menu.map((item) => (
                  <li key={item.id} className="menu-row">
                    <span>
                      <strong>{item.name}</strong>
                      {item.promotion ? ' 🏷️' : ''} —{' '}
                      {formatMoney(item.base_price_minor, item.currency)}
                      {item.description ? (
                        <span className="muted"> · {item.description}</span>
                      ) : null}
                    </span>
                    <Button
                      busy={addingId === item.id}
                      disabled={!item.orderable}
                      onClick={() => add(item)}
                    >
                      {item.orderable ? 'Add' : 'Unavailable'}
                      <span className="sr-only"> — {item.name}</span>
                    </Button>
                  </li>
                ))}
              </ul>
            )}

            <p>
              <Link to="/cart">Go to cart →</Link>
            </p>
          </>
        )}
      </AsyncView>
    </Layout>
  );
}
