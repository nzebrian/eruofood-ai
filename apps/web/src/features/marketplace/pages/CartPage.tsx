import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { AsyncView, EmptyState } from '@shared/components/StateViews';
import { describeError, useAsyncData } from '@shared/hooks/useAsyncData';
import { marketplaceApi } from '../marketplaceApi';
import { formatMoney, type Order } from '../types';

/** Cart review + a minimal delivery checkout. */
export function CartPage(): React.JSX.Element {
  const [placed, setPlaced] = useState<Order | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [address, setAddress] = useState({ line: '', city: 'Lagos', state: 'Lagos' });

  // `.catch(() => setCart(null))` produced "Your cart is empty" for every
  // failure. Being told your cart is empty when it is not, moments before
  // checkout, is the worst version of this defect in the application.
  const cart = useAsyncData(() => marketplaceApi.cart(), 'marketplace|cart');

  async function checkout(event: React.FormEvent): Promise<void> {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const order = await marketplaceApi.checkout({
        fulfilment: 'delivery',
        delivery_address: address,
      });
      setPlaced(order);
      cart.reload();
    } catch (err) {
      setError(describeError(err, 'Checkout failed.'));
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
          <Link to="/orders">View your orders →</Link>
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
              description="Add something from a vendor and it will show up here."
              action={
                <Link className="button button--secondary" to="/vendors">
                  Browse vendors
                </Link>
              }
            />
          ) : (
            <>
              <ul className="list">
                {basket.items.map((line) => (
                  <li key={`${line.menu_item_id}|${line.variant_name ?? ''}`}>
                    {line.quantity} × {line.name}
                    {line.variant_name ? ` (${line.variant_name})` : ''} —{' '}
                    {formatMoney(line.line_total_minor, basket.currency)}
                  </li>
                ))}
              </ul>
              <p>
                <strong>Subtotal: {formatMoney(basket.subtotal_minor, basket.currency)}</strong>
              </p>

              <form onSubmit={(e) => void checkout(e)} className="form">
                <h2>Delivery address</h2>
                <label className="field">
                  <span className="field__label">Street address</span>
                  <input
                    className="field__input"
                    value={address.line}
                    onChange={(e) => setAddress({ ...address, line: e.target.value })}
                    required
                  />
                </label>
                <label className="field">
                  <span className="field__label">City</span>
                  <input
                    className="field__input"
                    value={address.city}
                    onChange={(e) => setAddress({ ...address, city: e.target.value })}
                  />
                </label>
                <Button type="submit" busy={busy}>
                  Checkout
                </Button>
              </form>
              {error !== null ? (
                <p className="error" role="alert">
                  {error}
                </p>
              ) : null}
            </>
          )
        }
      </AsyncView>
    </Layout>
  );
}
