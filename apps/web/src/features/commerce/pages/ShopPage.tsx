import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Layout } from '@shared/components/Layout';
import { AsyncView, EmptyState } from '@shared/components/StateViews';
import { useAsyncData } from '@shared/hooks/useAsyncData';
import { commerceApi } from '../commerceApi';
import {
  DEPARTMENTS,
  formatMoney,
  type GroceryDepartment,
  type ProductKind,
  type ProductSummary,
} from '../types';

/** The storefront: search, filter by kind/department, and browse products. */
export function ShopPage(): React.JSX.Element {
  const [term, setTerm] = useState('');
  const [kind, setKind] = useState<ProductKind | ''>('');
  const [department, setDepartment] = useState<GroceryDepartment | ''>('');

  const products = useAsyncData(
    () =>
      commerceApi.products({
        q: term,
        kind: kind || undefined,
        department: department || undefined,
        per_page: 24,
      }),
    `commerce|products|${term}|${kind}|${department}`,
  );

  // Merchandising, not the page's reason for existing: if either fails the
  // shop still works, so their banners are simply not rendered.
  const promotions = useAsyncData(() => commerceApi.flashSales(), 'commerce|flash-sales');
  const recommendation = useAsyncData(() => commerceApi.recommendations(), 'commerce|recommended');
  const flashSales = promotions.state.status === 'ready' ? promotions.state.data : [];
  const recommended =
    recommendation.state.status === 'ready' ? recommendation.state.data : null;

  const hasFilters = term !== '' || kind !== '' || department !== '';

  return (
    <Layout>
      <h1>Marketplace &amp; Grocery</h1>

      {flashSales.length > 0 && (
        <p className="commerce-flash">
          ⚡ Flash sale: {flashSales.map((p) => p.name).join(' · ')}
        </p>
      )}

      <div className="commerce-filters">
        <input
          type="search"
          placeholder="Search products…"
          value={term}
          onChange={(e) => setTerm(e.target.value)}
          aria-label="Search products"
        />
        <select
          value={kind}
          onChange={(e) => setKind(e.target.value as ProductKind | '')}
          aria-label="Filter by kind"
        >
          <option value="">All kinds</option>
          <option value="grocery">Grocery</option>
          <option value="general">General</option>
        </select>
        <select
          value={department}
          onChange={(e) => setDepartment(e.target.value as GroceryDepartment | '')}
          aria-label="Filter by department"
        >
          <option value="">All departments</option>
          {DEPARTMENTS.map((d) => (
            <option key={d.value} value={d.value}>
              {d.label}
            </option>
          ))}
        </select>
      </div>

      {recommended && recommended.products.length > 0 && (
        <section className="commerce-recs">
          <h2>Recommended for you</h2>
          {recommended.blurb && <p className="muted">{recommended.blurb}</p>}
          <div className="commerce-grid">
            {recommended.products.slice(0, 4).map((p) => (
              <ProductCard key={p.id} product={p} />
            ))}
          </div>
        </section>
      )}

      <AsyncView
        state={products.state}
        loadingLabel="Loading products…"
        errorTitle="We could not load the shop"
        onRetry={products.reload}
      >
        {(page) =>
          page.data.length === 0 ? (
            <EmptyState
              title={hasFilters ? 'No products match those filters' : 'Nothing in the shop yet'}
              description={
                hasFilters
                  ? 'Try a different search term, kind or department.'
                  : 'Products will appear here as they are listed.'
              }
            />
          ) : (
            <div className="commerce-grid">
              {page.data.map((p) => (
                <ProductCard key={p.id} product={p} />
              ))}
            </div>
          )
        }
      </AsyncView>
    </Layout>
  );
}

function ProductCard({ product }: { product: ProductSummary }): React.JSX.Element {
  return (
    <Link to={`/shop/${product.slug}`} className="commerce-card">
      <div className="commerce-card__img" aria-hidden="true">
        {product.primary_image ? (
          <img src={product.primary_image} alt="" />
        ) : (
          <span>{product.kind === 'grocery' ? '🛒' : '📦'}</span>
        )}
      </div>
      <div className="commerce-card__body">
        <strong>{product.name}</strong>
        <span className="commerce-price">
          {formatMoney(product.base_price_minor, product.currency)}
        </span>
        {product.rating_count > 0 && (
          <span className="muted">
            ★ {product.rating_average.toFixed(1)} ({product.rating_count})
          </span>
        )}
      </div>
    </Link>
  );
}
