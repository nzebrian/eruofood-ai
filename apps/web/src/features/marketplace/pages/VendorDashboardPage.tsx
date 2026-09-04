import { Layout } from '@shared/components/Layout';
import { AsyncView, EmptyState } from '@shared/components/StateViews';
import { useAsyncData } from '@shared/hooks/useAsyncData';
import { marketplaceApi } from '../marketplaceApi';
import { formatMoney, type SalesSummary } from '../types';

/** Vendor sales dashboard: the owner's vendors and their sales figures. */
export function VendorDashboardPage(): React.JSX.Element {
  const dashboard = useAsyncData(async () => {
    const vendors = await marketplaceApi.myVendors();
    // One vendor's figures failing must not blank the others, so each is
    // settled independently — but a vendor whose summary is missing now says
    // so, rather than rendering as a heading with nothing under it.
    const pairs = await Promise.all(
      vendors.map(async (v) => {
        try {
          return [v.id, await marketplaceApi.vendorDashboard(v.id)] as const;
        } catch {
          return [v.id, null] as const;
        }
      }),
    );
    const summaries: Record<string, SalesSummary | null> = {};
    for (const [id, summary] of pairs) summaries[id] = summary;
    return { vendors, summaries };
  }, 'marketplace|vendor-dashboard');

  return (
    <Layout>
      <h1>Vendor dashboard</h1>

      <AsyncView
        state={dashboard.state}
        loadingLabel="Loading your vendors…"
        errorTitle="We could not load your vendor dashboard"
        onRetry={dashboard.reload}
      >
        {({ vendors, summaries }) =>
          vendors.length === 0 ? (
            <EmptyState
              title="No vendors yet"
              description="Vendors you own will appear here with their sales figures."
            />
          ) : (
            <>
              {vendors.map((v) => {
                const s = summaries[v.id];
                return (
                  <section key={v.id} className="ai-result">
                    <h2>
                      {v.name} <small className="muted">({v.status})</small>
                    </h2>
                    {s ? (
                      <dl className="usage">
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
                      </dl>
                    ) : (
                      <p className="muted">
                        Sales figures for this vendor could not be loaded just now.
                      </p>
                    )}
                  </section>
                );
              })}
            </>
          )
        }
      </AsyncView>
    </Layout>
  );
}
