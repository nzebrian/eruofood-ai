import { useEffect, useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { paymentsApi } from '../paymentsApi';
import { formatMoney, type Payment } from '../types';

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
  const [payments, setPayments] = useState<Payment[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    paymentsApi
      .payments()
      .then((page) => setPayments(page.data))
      .catch(() => setPayments([]))
      .finally(() => setLoading(false));
  }, []);

  return (
    <Layout>
      <h1>Payment history</h1>
      {loading ? (
        <p className="muted">Loading…</p>
      ) : payments.length === 0 ? (
        <p className="muted">No payments yet.</p>
      ) : (
        <table className="pay-table">
          <thead>
            <tr>
              <th>Reference</th>
              <th>Amount</th>
              <th>Provider</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            {payments.map((p) => (
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
      )}
    </Layout>
  );
}
