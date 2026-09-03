import { Layout } from '@shared/components/Layout';
import { AsyncView, EmptyState } from '@shared/components/StateViews';
import { useAsyncData } from '@shared/hooks/useAsyncData';
import { paymentsApi } from '../paymentsApi';
import { formatMoney } from '../types';

const STATUS_LABEL: Record<string, string> = {
  succeeded: 'Paid',
  partially_refunded: 'Partly refunded',
  refunded: 'Refunded',
  failed: 'Failed',
  pending: 'Pending',
  processing: 'Processing',
  cancelled: 'Cancelled',
};

/** The user's payment history. */
export function TransactionsPage(): React.JSX.Element {
  const payments = useAsyncData(() => paymentsApi.payments(), 'payments|history');

  return (
    <Layout>
      <h1>Payment history</h1>

      <AsyncView
        state={payments.state}
        loadingLabel="Loading your payments…"
        errorTitle="We could not load your payment history"
        onRetry={payments.reload}
      >
        {(page) =>
          page.data.length === 0 ? (
            <EmptyState
              title="No payments yet"
              description="Orders you pay for will be listed here."
            />
          ) : (
            /* A table is the right element for this data; the wrapper is what
               lets it scroll on a narrow screen instead of widening the page. */
            <div className="table-scroll">
              <table className="pay-table">
                <caption className="sr-only">Your payments, most recent first</caption>
                <thead>
                  <tr>
                    <th scope="col">Reference</th>
                    <th scope="col">Amount</th>
                    <th scope="col">Provider</th>
                    <th scope="col">Status</th>
                  </tr>
                </thead>
                <tbody>
                  {page.data.map((p) => (
                    <tr key={p.id}>
                      <td>{p.reference}</td>
                      <td>{formatMoney(p.amount_minor, p.currency)}</td>
                      <td>{p.provider}</td>
                      <td>
                        <span className={`pay-status pay-status--${p.status}`}>
                          {STATUS_LABEL[p.status] ?? p.status}
                        </span>
                      </td>
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
