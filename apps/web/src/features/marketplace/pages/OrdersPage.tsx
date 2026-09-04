import { Link } from 'react-router-dom';
import { Layout } from '@shared/components/Layout';
import { AsyncView, EmptyState } from '@shared/components/StateViews';
import { useAsyncData } from '@shared/hooks/useAsyncData';
import { marketplaceApi } from '../marketplaceApi';
import { formatMoney } from '../types';

/** The customer's order history and tracking. */
export function OrdersPage(): React.JSX.Element {
  const orders = useAsyncData(() => marketplaceApi.orders(), 'marketplace|orders');

  return (
    <Layout>
      <h1>My orders</h1>

      <AsyncView
        state={orders.state}
        loadingLabel="Loading your orders…"
        errorTitle="We could not load your orders"
        onRetry={orders.reload}
      >
        {(page) =>
          page.data.length === 0 ? (
            <EmptyState
              title="No orders yet"
              description="When you order from a vendor it will appear here with its status."
              action={
                <Link className="button button--secondary" to="/vendors">
                  Browse vendors
                </Link>
              }
            />
          ) : (
            <div className="table-scroll">
              <table className="table">
                <caption className="sr-only">Your orders, most recent first</caption>
                <thead>
                  <tr>
                    <th scope="col">Reference</th>
                    <th scope="col">Status</th>
                    <th scope="col">Type</th>
                    <th scope="col">Total</th>
                    <th scope="col">Placed</th>
                  </tr>
                </thead>
                <tbody>
                  {page.data.map((o) => (
                    <tr key={o.id}>
                      <td className="break-anywhere">{o.reference}</td>
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
            </div>
          )
        }
      </AsyncView>
    </Layout>
  );
}
