import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { Layout } from '@shared/components/Layout';
import { ApiRequestError } from '@lib/apiClient';
import { marketplaceApi } from '../marketplaceApi';
import { formatMoney, type MenuItem, type Vendor } from '../types';

/** A vendor's storefront: profile + menu with add-to-cart. */
export function VendorStorefrontPage(): React.JSX.Element {
  const { slug = '' } = useParams();
  const [vendor, setVendor] = useState<Vendor | null>(null);
  const [menu, setMenu] = useState<MenuItem[]>([]);
  const [notice, setNotice] = useState<string | null>(null);

  useEffect(() => {
    marketplaceApi
      .vendor(slug)
      .then((v) => {
        setVendor(v);
        return marketplaceApi.menu(v.id);
      })
      .then(setMenu)
      .catch(() => setVendor(null));
  }, [slug]);

  function add(item: MenuItem): void {
    marketplaceApi
      .addToCart(item.id, 1)
      .then(() => setNotice(`Added ${item.name} to your cart.`))
      .catch((err) =>
        setNotice(err instanceof ApiRequestError ? err.error.message : 'Could not add to cart.'),
      );
  }

  if (!vendor) {
    return (
      <Layout>
        <p>Loading…</p>
      </Layout>
    );
  }

  return (
    <Layout>
      <h1>{vendor.name}</h1>
      <p className="muted">
        {vendor.category} · {vendor.type.replace('_', ' ')} · ⭐ {vendor.rating_average} ({vendor.rating_count})
      </p>
      {vendor.description ? <p>{vendor.description}</p> : null}

      {notice ? <p className="success">{notice}</p> : null}

      <h2>Menu</h2>
      {menu.length === 0 ? (
        <p className="muted">No items yet.</p>
      ) : (
        <ul className="list">
          {menu.map((item) => (
            <li key={item.id} className="menu-row">
              <span>
                <strong>{item.name}</strong>
                {item.promotion ? ' 🏷️' : ''} — {formatMoney(item.base_price_minor, item.currency)}
                {item.description ? <span className="muted"> · {item.description}</span> : null}
              </span>
              <button className="button" onClick={() => add(item)} disabled={!item.orderable}>
                {item.orderable ? 'Add' : 'Unavailable'}
              </button>
            </li>
          ))}
        </ul>
      )}

      <p>
        <Link to="/cart">Go to cart →</Link>
      </p>
    </Layout>
  );
}
