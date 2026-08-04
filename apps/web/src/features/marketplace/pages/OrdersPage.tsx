import { useEffect, useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { marketplaceApi } from '../marketplaceApi';
import { formatMoney, type OrderSummary } from '../types';

/** The customer's order history and tracking. */
export function OrdersPage(): React.JSX.Element {
  const [orders, setOrders] = useState<OrderSummary[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    marketplaceApi
      .orders()
      .then((page) => setOrders(page.data))
      .catch(() => setOrders([]))
      .finally(() => setLoading(false));
  }, []);

  return (
    <Layout>
      <h1>My orders</h1>
      {loading ? (
        <p>Loading…</p>
      ) : orders.length === 0 ? (
        <p className="muted">You have no orders yet.</p>
      ) : (
        <table className="table">
          <thead>
            <tr>
              <th>Reference</th>
              <th>Status</th>
              <th>Type</th>
              <th>Total</th>
              <th>Placed</th>
            </tr>
          </thead>
          <tbody>
            {orders.map((o) => (
              <tr key={o.id}>
                <td>{o.reference}</td>
                <td>
                  <span className={`badge badge--${o.status}`}>{o.status}</span>
                </td>
                <td>{o.fulfilment}</td>
                <td>{formatMoney(o.total_minor, o.currency)}</td>
                <td>{new Date(o.placed_at).toLocaleDateString()}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </Layout>
  );
}
