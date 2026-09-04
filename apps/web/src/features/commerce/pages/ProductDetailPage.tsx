import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { AsyncView } from '@shared/components/StateViews';
import { describeError, useAsyncData } from '@shared/hooks/useAsyncData';
import { commerceApi } from '../commerceApi';
import { formatMoney, type Product } from '../types';

/** Product detail with variant selection, add-to-cart, wishlist and cross-sell. */
export function ProductDetailPage(): React.JSX.Element {
  const { slug = '' } = useParams();

  // `.catch(() => setProduct(null))` was also the loading condition, so a
  // failed product read left "Loading product…" on screen for good.
  const product = useAsyncData(() => commerceApi.product(slug), `commerce|product|${slug}`);

  return (
    <Layout>
      <p>
        <Link to="/shop">← Back to shop</Link>
      </p>

      <AsyncView
        state={product.state}
        loadingLabel="Loading product…"
        errorTitle="We could not load this product"
        onRetry={product.reload}
      >
        {(item) => <ProductDetail key={item.id} product={item} />}
      </AsyncView>
    </Layout>
  );
}

function ProductDetail({ product }: { product: Product }): React.JSX.Element {
  const [variantSku, setVariantSku] = useState(product.variants[0]?.sku ?? '');
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const variant = product.variants.find((v) => v.sku === variantSku) ?? null;
  const priceMinor = product.base_price_minor + (variant?.price_delta_minor ?? 0);

  async function run(action: () => Promise<unknown>, ok: string, failure: string): Promise<void> {
    setBusy(true);
    setError(null);
    setMessage(null);
    try {
      await action();
      setMessage(ok);
    } catch (err) {
      setError(describeError(err, failure));
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <h1>{product.name}</h1>
      {product.brand && <p className="muted">{product.brand}</p>}
      <p className="commerce-price commerce-price--lg">
        {formatMoney(priceMinor, product.currency)}
      </p>

      {product.description && <p>{product.description}</p>}

      {product.variants.length > 0 && (
        <label className="commerce-field">
          <span>Variant</span>
          <select value={variantSku} onChange={(e) => setVariantSku(e.target.value)}>
            {product.variants.map((v) => (
              <option key={v.sku} value={v.sku}>
                {v.name}
              </option>
            ))}
          </select>
        </label>
      )}

      <div className="commerce-actions">
        <Button
          busy={busy}
          onClick={() =>
            void run(
              () => commerceApi.addToCart(product.id, 1, variant?.sku),
              'Added to cart.',
              'Could not add to cart.',
            )
          }
        >
          Add to cart
        </Button>
        <Button
          className="button--secondary"
          busy={busy}
          onClick={() =>
            void run(
              () => commerceApi.addToWishlist(product.id),
              'Saved to your wishlist.',
              'Could not update your wishlist.',
            )
          }
        >
          ♡ Save
        </Button>
      </div>

      {message !== null && (
        <p className="commerce-ok" role="status">
          {message}
        </p>
      )}
      {error !== null && (
        <p className="commerce-error" role="alert">
          {error}
        </p>
      )}

      {product.related && product.related.length > 0 && (
        <section className="commerce-recs">
          <h2>You may also like</h2>
          <div className="commerce-grid">
            {product.related.slice(0, 4).map((p) => (
              <Link key={p.id} to={`/shop/${p.slug}`} className="commerce-card">
                <div className="commerce-card__body">
                  <strong>{p.name}</strong>
                  <span className="commerce-price">
                    {formatMoney(p.base_price_minor, p.currency)}
                  </span>
                </div>
              </Link>
            ))}
          </div>
        </section>
      )}
    </>
  );
}
