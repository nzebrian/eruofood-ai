import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { ApiRequestError } from '@lib/apiClient';
import { marketplaceApi } from '../marketplaceApi';
import { formatMoney, type Cart, type Order } from '../types';

/** Cart review + a minimal delivery checkout. */
export function CartPage(): React.JSX.Element {
  const [cart, setCart] = useState<Cart | null>(null);
  const [placed, setPlaced] = useState<Order | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [address, setAddress] = useState({ line: '', city: 'Lagos', state: 'Lagos' });

  function refresh(): void {
    marketplaceApi
      .cart()
      .then(setCart)
      .catch(() => setCart(null));
  }

  useEffect(refresh, []);

  async function checkout(event: React.FormEvent): Promise<void> {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const order = await marketplaceApi.checkout({ fulfilment: 'delivery', delivery_address: address });
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
          <Link to="/orders">View your orders →</Link>
        </p>
      </Layout>
    );
  }

  return (
    <Layout>
      <h1>Your cart</h1>
      {!cart || cart.items.length === 0 ? (
        <p className="muted">
          Your cart is empty. <Link to="/vendors">Browse vendors →</Link>
        </p>
      ) : (
        <>
          <ul className="list">
            {cart.items.map((line) => (
              <li key={`${line.menu_item_id}|${line.variant_name ?? ''}`}>
                {line.quantity} × {line.name}
                {line.variant_name ? ` (${line.variant_name})` : ''} —{' '}
                {formatMoney(line.line_total_minor, cart.currency)}
              </li>
            ))}
          </ul>
          <p>
            <strong>Subtotal: {formatMoney(cart.subtotal_minor, cart.currency)}</strong>
          </p>

          <form onSubmit={(e) => void checkout(e)} className="form">
            <h2>Delivery address</h2>
            <input
              className="field__input"
              placeholder="Street address"
              value={address.line}
              onChange={(e) => setAddress({ ...address, line: e.target.value })}
              required
            />
            <input
              className="field__input"
              placeholder="City"
              value={address.city}
              onChange={(e) => setAddress({ ...address, city: e.target.value })}
            />
            <Button type="submit" busy={busy}>
              Checkout
            </Button>
          </form>
          {error ? <p className="error">{error}</p> : null}
        </>
      )}
    </Layout>
  );
}
