import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Layout } from '@shared/components/Layout';
import { commerceApi } from '../commerceApi';
import {
  DEPARTMENTS,
  formatMoney,
  type GroceryDepartment,
  type ProductKind,
  type ProductSummary,
  type Promotion,
  type Recommendation,
} from '../types';

/** The storefront: search, filter by kind/department, and browse products. */
export function ShopPage(): React.JSX.Element {
  const [products, setProducts] = useState<ProductSummary[]>([]);
  const [promotions, setPromotions] = useState<Promotion[]>([]);
  const [recommendation, setRecommendation] = useState<Recommendation | null>(null);
  const [term, setTerm] = useState('');
  const [kind, setKind] = useState<ProductKind | ''>('');
  const [department, setDepartment] = useState<GroceryDepartment | ''>('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    setLoading(true);
    commerceApi
      .products({ q: term, kind: kind || undefined, department: department || undefined, per_page: 24 })
      .then((page) => setProducts(page.data))
      .catch(() => setProducts([]))
      .finally(() => setLoading(false));
  }, [term, kind, department]);

  useEffect(() => {
    commerceApi.flashSales().then(setPromotions).catch(() => setPromotions([]));
    commerceApi.recommendations().then(setRecommendation).catch(() => setRecommendation(null));
  }, []);

  return (
    <Layout>
      <h1>Marketplace &amp; Grocery</h1>

      {promotions.length > 0 && (
        <div className="commerce-flash">
          ⚡ Flash sale: {promotions.map((p) => p.name).join(' · ')}
        </div>
      )}

      <div className="commerce-filters">
        <input
          type="search"
          placeholder="Search products…"
          value={term}
          onChange={(e) => setTerm(e.target.value)}
          aria-label="Search products"
        />
        <select value={kind} onChange={(e) => setKind(e.target.value as ProductKind | '')} aria-label="Kind">
          <option value="">All kinds</option>
          <option value="grocery">Grocery</option>
          <option value="general">General</option>
        </select>
        <select
          value={department}
          onChange={(e) => setDepartment(e.target.value as GroceryDepartment | '')}
          aria-label="Department"
        >
          <option value="">All departments</option>
          {DEPARTMENTS.map((d) => (
            <option key={d.value} value={d.value}>
              {d.label}
            </option>
          ))}
        </select>
      </div>

      {recommendation && recommendation.products.length > 0 && (
        <section className="commerce-recs">
          <h2>Recommended for you</h2>
          {recommendation.blurb && <p className="muted">{recommendation.blurb}</p>}
          <div className="commerce-grid">
            {recommendation.products.slice(0, 4).map((p) => (
              <ProductCard key={p.id} product={p} />
            ))}
          </div>
        </section>
      )}

      {loading ? (
        <p className="muted">Loading products…</p>
      ) : products.length === 0 ? (
        <p className="muted">No products found.</p>
      ) : (
        <div className="commerce-grid">
          {products.map((p) => (
            <ProductCard key={p.id} product={p} />
          ))}
        </div>
      )}
    </Layout>
  );
}

function ProductCard({ product }: { product: ProductSummary }): React.JSX.Element {
  return (
    <Link to={`/shop/${product.slug}`} className="commerce-card">
      <div className="commerce-card__img" aria-hidden>
        {product.primary_image ? (
          <img src={product.primary_image} alt="" />
        ) : (
          <span>{product.kind === 'grocery' ? '🛒' : '📦'}</span>
        )}
      </div>
      <div className="commerce-card__body">
        <strong>{product.name}</strong>
        <span className="commerce-price">{formatMoney(product.base_price_minor, product.currency)}</span>
        {product.rating_count > 0 && (
          <span className="muted">
            ★ {product.rating_average.toFixed(1)} ({product.rating_count})
          </span>
        )}
      </div>
    </Link>
  );
}
