import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { ApiRequestError } from '@lib/apiClient';
import { commerceApi } from '../commerceApi';
import { formatMoney, type Product, type ProductVariant } from '../types';

/** Product detail with variant selection, add-to-cart, wishlist and cross-sell. */
export function ProductDetailPage(): React.JSX.Element {
  const { slug = '' } = useParams();
  const [product, setProduct] = useState<Product | null>(null);
  const [variant, setVariant] = useState<ProductVariant | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    commerceApi
      .product(slug)
      .then((p) => {
        setProduct(p);
        setVariant(p.variants[0] ?? null);
      })
      .catch(() => setProduct(null));
  }, [slug]);

  async function addToCart(): Promise<void> {
    if (!product) return;
    setBusy(true);
    setError(null);
    setMessage(null);
    try {
      await commerceApi.addToCart(product.id, 1, variant?.sku);
      setMessage('Added to cart.');
    } catch (err) {
      setError(err instanceof ApiRequestError ? err.error.message : 'Could not add to cart.');
    } finally {
      setBusy(false);
    }
  }

  async function saveForLater(): Promise<void> {
    if (!product) return;
    try {
      await commerceApi.addToWishlist(product.id);
      setMessage('Saved to your wishlist.');
    } catch {
      setError('Could not update your wishlist.');
    }
  }

  if (!product) {
    return (
      <Layout>
        <p className="muted">Loading product…</p>
      </Layout>
    );
  }

  const priceMinor = product.base_price_minor + (variant?.price_delta_minor ?? 0);

  return (
    <Layout>
      <p>
        <Link to="/shop">← Back to shop</Link>
      </p>
      <h1>{product.name}</h1>
      {product.brand && <p className="muted">{product.brand}</p>}
      <p className="commerce-price commerce-price--lg">{formatMoney(priceMinor, product.currency)}</p>

      {product.description && <p>{product.description}</p>}

      {product.variants.length > 0 && (
        <label className="commerce-field">
          Variant
          <select
            value={variant?.sku ?? ''}
            onChange={(e) => setVariant(product.variants.find((v) => v.sku === e.target.value) ?? null)}
          >
            {product.variants.map((v) => (
              <option key={v.sku} value={v.sku}>
                {v.name}
              </option>
            ))}
          </select>
        </label>
      )}

      <div className="commerce-actions">
        <Button onClick={() => void addToCart()} disabled={busy}>
          Add to cart
        </Button>
        <Button className="button--secondary" onClick={() => void saveForLater()}>
          ♡ Save
        </Button>
      </div>

      {message && <p className="commerce-ok">{message}</p>}
      {error && <p className="commerce-error">{error}</p>}

      {product.related && product.related.length > 0 && (
        <section className="commerce-recs">
          <h2>You may also like</h2>
          <div className="commerce-grid">
            {product.related.slice(0, 4).map((p) => (
              <Link key={p.id} to={`/shop/${p.slug}`} className="commerce-card">
                <div className="commerce-card__body">
                  <strong>{p.name}</strong>
                  <span className="commerce-price">{formatMoney(p.base_price_minor, p.currency)}</span>
                </div>
              </Link>
            ))}
          </div>
        </section>
      )}
    </Layout>
  );
}
