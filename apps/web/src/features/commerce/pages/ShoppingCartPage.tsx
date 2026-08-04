import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { ApiRequestError } from '@lib/apiClient';
import { commerceApi } from '../commerceApi';
import { formatMoney, type Cart, type Order, type PriceBreakdown } from '../types';

/** Cart review, coupon entry, price breakdown and checkout. */
export function ShoppingCartPage(): React.JSX.Element {
  const [cart, setCart] = useState<Cart | null>(null);
  const [quote, setQuote] = useState<PriceBreakdown | null>(null);
  const [placed, setPlaced] = useState<Order | null>(null);
  const [coupon, setCoupon] = useState('');
  const [pickup, setPickup] = useState(false);
  const [address, setAddress] = useState({ line1: '', city: 'Lagos', state: 'Lagos' });
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const refresh = useCallback((): void => {
    commerceApi
      .cart()
      .then((c) => {
        setCart(c);
        setCoupon(c.coupon_code ?? '');
      })
      .catch(() => setCart(null));
  }, []);

  useEffect(refresh, [refresh]);

  useEffect(() => {
    if (!cart || cart.items.length === 0) {
      setQuote(null);
      return;
    }
    commerceApi
      .quote(pickup)
      .then(setQuote)
      .catch(() => setQuote(null));
  }, [cart, pickup]);

  async function applyCoupon(): Promise<void> {
    setError(null);
    try {
      await commerceApi.applyCoupon(coupon);
      refresh();
    } catch (err) {
      setError(err instanceof ApiRequestError ? err.error.message : 'Invalid coupon.');
    }
  }

  async function updateQty(productId: string, variantSku: string | null, quantity: number): Promise<void> {
    await commerceApi.updateCartItem(productId, quantity, variantSku ?? undefined);
    refresh();
  }

  async function checkout(event: React.FormEvent): Promise<void> {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const order = await commerceApi.checkout({
        pickup,
        shipping_address: pickup ? undefined : address,
      });
      setPlaced(order);
      refresh();
    } catch (err) {
      setError(err instanceof ApiRequestError ? err.error.message : 'Checkout failed.');
    } finally {
      setBusy(false);
    }
  }

  if (placed) {
    return (
      <Layout>
        <h1>Order placed 🎉</h1>
        <p>
          Reference <strong>{placed.reference}</strong> · Total{' '}
          {formatMoney(placed.total_minor, placed.currency)}
        </p>
        <p>
          <Link to="/shop">Continue shopping →</Link>
        </p>
      </Layout>
    );
  }

  return (
    <Layout>
      <h1>Your cart</h1>
      {!cart || cart.items.length === 0 ? (
        <p className="muted">
          Your cart is empty. <Link to="/shop">Browse the shop →</Link>
        </p>
      ) : (
        <div className="commerce-cart">
          <ul className="list">
            {cart.items.map((line) => (
              <li key={`${line.product_id}|${line.variant_sku ?? ''}`} className="commerce-cart__row">
                <span>
                  {line.name}
                  {line.variant_sku ? ` (${line.variant_sku})` : ''}
                </span>
                <input
                  type="number"
                  min={0}
                  max={99}
                  value={line.quantity}
                  onChange={(e) =>
                    void updateQty(line.product_id, line.variant_sku, Number(e.target.value))
                  }
                  aria-label={`Quantity of ${line.name}`}
                />
                <span>{formatMoney(line.line_total_minor, cart.currency)}</span>
              </li>
            ))}
          </ul>

          <div className="commerce-coupon">
            <input
              type="text"
              placeholder="Coupon code"
              value={coupon}
              onChange={(e) => setCoupon(e.target.value)}
              aria-label="Coupon code"
            />
            <Button className="button--secondary" onClick={() => void applyCoupon()}>
              Apply
            </Button>
          </div>

          {quote && (
            <dl className="commerce-breakdown">
              <div>
                <dt>Subtotal</dt>
                <dd>{formatMoney(quote.subtotal_minor, quote.currency)}</dd>
              </div>
              <div>
                <dt>Discount</dt>
                <dd>−{formatMoney(quote.discount_minor, quote.currency)}</dd>
              </div>
              <div>
                <dt>Tax</dt>
                <dd>{formatMoney(quote.tax_minor, quote.currency)}</dd>
              </div>
              <div>
                <dt>Shipping</dt>
                <dd>{formatMoney(quote.shipping_minor, quote.currency)}</dd>
              </div>
              <div className="commerce-breakdown__total">
                <dt>Total</dt>
                <dd>{formatMoney(quote.total_minor, quote.currency)}</dd>
              </div>
            </dl>
          )}

          <form onSubmit={(e) => void checkout(e)} className="commerce-checkout">
            <label className="commerce-check">
              <input type="checkbox" checked={pickup} onChange={(e) => setPickup(e.target.checked)} />
              Pick up (no shipping)
            </label>

            {!pickup && (
              <div className="commerce-address">
                <input
                  placeholder="Address line"
                  value={address.line1}
                  onChange={(e) => setAddress({ ...address, line1: e.target.value })}
                  required
                />
                <input
                  placeholder="City"
                  value={address.city}
                  onChange={(e) => setAddress({ ...address, city: e.target.value })}
                  required
                />
                <input
                  placeholder="State"
                  value={address.state}
                  onChange={(e) => setAddress({ ...address, state: e.target.value })}
                  required
                />
              </div>
            )}

            <Button type="submit" disabled={busy}>
              Place order
            </Button>
          </form>
        </div>
      )}
      {error && <p className="commerce-error">{error}</p>}
    </Layout>
  );
}
