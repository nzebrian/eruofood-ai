import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { AsyncView, EmptyState } from '@shared/components/StateViews';
import { describeError, useAsyncData } from '@shared/hooks/useAsyncData';
import { commerceApi } from '../commerceApi';
import { formatMoney, type Cart, type Order, type PriceBreakdown } from '../types';

/** Cart review, coupon entry, price breakdown and checkout. */
export function ShoppingCartPage(): React.JSX.Element {
  const [placed, setPlaced] = useState<Order | null>(null);

  // `.catch(() => setCart(null))` rendered "Your cart is empty" for every
  // failure, immediately before checkout.
  const cart = useAsyncData(() => commerceApi.cart(), 'commerce|cart');

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

      <AsyncView
        state={cart.state}
        loadingLabel="Loading your cart…"
        errorTitle="We could not load your cart"
        onRetry={cart.reload}
      >
        {(basket) =>
          basket.items.length === 0 ? (
            <EmptyState
              title="Your cart is empty"
              description="Add something from the shop and it will show up here."
              action={
                <Link className="button button--secondary" to="/shop">
                  Browse the shop
                </Link>
              }
            />
          ) : (
            <CartEditor cart={basket} onReload={cart.reload} onPlaced={setPlaced} />
          )
        }
      </AsyncView>
    </Layout>
  );
}

function CartEditor({
  cart,
  onReload,
  onPlaced,
}: {
  cart: Cart;
  onReload: () => void;
  onPlaced: (order: Order) => void;
}): React.JSX.Element {
  const [coupon, setCoupon] = useState(cart.coupon_code ?? '');
  const [pickup, setPickup] = useState(false);
  const [address, setAddress] = useState({ line1: '', city: 'Lagos', state: 'Lagos' });
  const [quote, setQuote] = useState<PriceBreakdown | null>(null);
  const [quoteFailed, setQuoteFailed] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  // Re-quote whenever the priced contents change. A signature over the lines
  // is the honest dependency: quantities and the coupon both move the total.
  const quoteKey = [
    cart.coupon_code ?? '',
    ...cart.items.map((l) => `${l.product_id}:${l.variant_sku ?? ''}:${String(l.quantity)}`),
  ].join('|');

  useEffect(() => {
    let cancelled = false;
    setQuoteFailed(false);
    commerceApi.quote(pickup).then(
      (breakdown) => {
        if (!cancelled) setQuote(breakdown);
      },
      () => {
        // A quote we could not fetch is not a total of zero. The breakdown is
        // replaced by a line saying so rather than silently disappearing.
        if (!cancelled) {
          setQuote(null);
          setQuoteFailed(true);
        }
      },
    );
    return () => {
      cancelled = true;
    };
  }, [quoteKey, pickup]);

  async function run(action: () => Promise<unknown>, failure: string): Promise<void> {
    setBusy(true);
    setError(null);
    try {
      await action();
      onReload();
    } catch (err) {
      setError(describeError(err, failure));
    } finally {
      setBusy(false);
    }
  }

  async function checkout(event: React.FormEvent): Promise<void> {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      onPlaced(
        await commerceApi.checkout({
          pickup,
          shipping_address: pickup ? undefined : address,
        }),
      );
    } catch (err) {
      setError(describeError(err, 'Checkout failed.'));
    } finally {
      setBusy(false);
    }
  }

  return (
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
                void run(
                  () =>
                    commerceApi.updateCartItem(
                      line.product_id,
                      Number(e.target.value),
                      line.variant_sku ?? undefined,
                    ),
                  'Could not update that quantity.',
                )
              }
              aria-label={`Quantity of ${line.name}`}
            />
            <span>{formatMoney(line.line_total_minor, cart.currency)}</span>
          </li>
        ))}
      </ul>

      <div className="commerce-coupon">
        <label className="sr-only" htmlFor="coupon">
          Coupon code
        </label>
        <input
          id="coupon"
          type="text"
          placeholder="Coupon code"
          value={coupon}
          onChange={(e) => setCoupon(e.target.value)}
        />
        <Button
          className="button--secondary"
          busy={busy}
          onClick={() => void run(() => commerceApi.applyCoupon(coupon), 'Invalid coupon.')}
        >
          Apply
        </Button>
      </div>

      {quote ? (
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
      ) : quoteFailed ? (
        <p className="muted" role="status">
          We could not price this cart just now. Your total will be confirmed at checkout.
        </p>
      ) : null}

      <form onSubmit={(e) => void checkout(e)} className="commerce-checkout">
        <label className="commerce-check">
          <input type="checkbox" checked={pickup} onChange={(e) => setPickup(e.target.checked)} />
          Pick up (no shipping)
        </label>

        {!pickup && (
          <div className="commerce-address">
            <label className="field">
              <span className="field__label">Address line</span>
              <input
                className="field__input"
                value={address.line1}
                onChange={(e) => setAddress({ ...address, line1: e.target.value })}
                required
              />
            </label>
            <label className="field">
              <span className="field__label">City</span>
              <input
                className="field__input"
                value={address.city}
                onChange={(e) => setAddress({ ...address, city: e.target.value })}
                required
              />
            </label>
            <label className="field">
              <span className="field__label">State</span>
              <input
                className="field__input"
                value={address.state}
                onChange={(e) => setAddress({ ...address, state: e.target.value })}
                required
              />
            </label>
          </div>
        )}

        <Button type="submit" busy={busy}>
          Place order
        </Button>
      </form>

      {error !== null && (
        <p className="commerce-error" role="alert">
          {error}
        </p>
      )}
    </div>
  );
}
