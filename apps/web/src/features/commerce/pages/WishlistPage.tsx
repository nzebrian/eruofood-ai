import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { commerceApi } from '../commerceApi';
import { formatMoney, type ProductSummary } from '../types';

/** The shopper's saved-for-later products. */
export function WishlistPage(): React.JSX.Element {
  const [products, setProducts] = useState<ProductSummary[]>([]);
  const [loading, setLoading] = useState(true);

  const refresh = useCallback((): void => {
    setLoading(true);
    commerceApi
      .wishlist()
      .then((w) => setProducts(w.products))
      .catch(() => setProducts([]))
      .finally(() => setLoading(false));
  }, []);

  useEffect(refresh, [refresh]);

  async function remove(productId: string): Promise<void> {
    await commerceApi.removeFromWishlist(productId);
    refresh();
  }

  return (
    <Layout>
      <h1>Your wishlist</h1>
      {loading ? (
        <p className="muted">Loading…</p>
      ) : products.length === 0 ? (
        <p className="muted">
          Nothing saved yet. <Link to="/shop">Browse the shop →</Link>
        </p>
      ) : (
        <ul className="list">
          {products.map((p) => (
            <li key={p.id} className="commerce-cart__row">
              <Link to={`/shop/${p.slug}`}>{p.name}</Link>
              <span>{formatMoney(p.base_price_minor, p.currency)}</span>
              <Button className="button--secondary" onClick={() => void remove(p.id)}>
                Remove
              </Button>
            </li>
          ))}
        </ul>
      )}
    </Layout>
  );
}
