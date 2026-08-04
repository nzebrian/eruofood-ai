import { useEffect, useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { marketplaceApi } from '../marketplaceApi';
import { formatMoney, type SalesSummary, type Vendor } from '../types';

/** Vendor sales dashboard: the owner's vendors and their sales figures. */
export function VendorDashboardPage(): React.JSX.Element {
  const [vendors, setVendors] = useState<Vendor[]>([]);
  const [summaries, setSummaries] = useState<Record<string, SalesSummary>>({});
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    marketplaceApi
      .myVendors()
      .then((list) => {
        setVendors(list);
        return Promise.all(
          list.map((v) =>
            marketplaceApi
              .vendorDashboard(v.id)
              .then((s) => [v.id, s] as const)
              .catch(() => null),
          ),
        );
      })
      .then((pairs) => {
        const map: Record<string, SalesSummary> = {};
        for (const pair of pairs ?? []) {
          if (pair) map[pair[0]] = pair[1];
        }
        setSummaries(map);
      })
      .catch(() => setVendors([]))
      .finally(() => setLoading(false));
  }, []);

  return (
    <Layout>
      <h1>Vendor dashboard</h1>
      {loading ? (
        <p>Loading…</p>
      ) : vendors.length === 0 ? (
        <p className="muted">You have no vendors yet.</p>
      ) : (
        vendors.map((v) => {
          const s = summaries[v.id];
          return (
            <section key={v.id} className="ai-result">
              <h2>
                {v.name} <small className="muted">({v.status})</small>
              </h2>
              {s ? (
                <div className="usage">
                  <div>
                    <dt>Orders</dt>
                    <dd>{s.total_orders}</dd>
                  </div>
                  <div>
                    <dt>Delivered</dt>
                    <dd>{s.delivered_orders}</dd>
                  </div>
                  <div>
                    <dt>Pending</dt>
                    <dd>{s.pending_orders}</dd>
                  </div>
                  <div>
                    <dt>Revenue</dt>
                    <dd>{formatMoney(s.revenue_minor, s.currency)}</dd>
                  </div>
                </div>
              ) : null}
            </section>
          );
        })
      )}
    </Layout>
  );
}
