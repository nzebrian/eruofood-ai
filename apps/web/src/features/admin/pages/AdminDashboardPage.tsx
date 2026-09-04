import { Link } from 'react-router-dom';
import { Layout } from '@shared/components/Layout';
import { AsyncView, EmptyState } from '@shared/components/StateViews';
import { useAsyncData } from '@shared/hooks/useAsyncData';
import { adminApi } from '../adminApi';

const SECTIONS = [
  { to: '/admin/users', title: 'User Administration', desc: 'Search, suspend and verify users' },
  { to: '/admin/content', title: 'Content Management', desc: 'Pages, blog, news, FAQ and banners' },
  {
    to: '/admin/config',
    title: 'System Configuration',
    desc: 'Settings, feature flags, maintenance',
  },
  { to: '/admin/support', title: 'Support Centre', desc: 'The live ticket queue' },
  { to: '/analytics', title: 'Analytics', desc: 'Executive & operations dashboards' },
];

/** Platform administration overview: entry points plus the latest audit activity. */
export function AdminDashboardPage(): React.JSX.Element {
  // "No audit activity yet." for a failed read is a bad answer on any screen
  // and a worse one on an audit trail, where absence is itself a finding.
  const audit = useAsyncData(() => adminApi.audit('', 1), 'admin|audit|recent');

  return (
    <Layout>
      <h1>Administration</h1>

      <div className="admin-grid">
        {SECTIONS.map((s) => (
          <Link key={s.to} to={s.to} className="admin-card">
            <span className="admin-card__title">{s.title}</span>
            <span className="admin-card__desc">{s.desc}</span>
          </Link>
        ))}
      </div>

      <h2>Recent activity</h2>
      <AsyncView
        state={audit.state}
        loadingLabel="Loading recent activity…"
        errorTitle="We could not load the audit trail"
        onRetry={audit.reload}
      >
        {(page) =>
          page.data.length === 0 ? (
            <EmptyState
              title="No audit activity yet"
              description="Administrative actions are recorded here as they happen."
            />
          ) : (
            <div className="table-scroll">
              <table className="admin-table">
                <caption className="sr-only">The eight most recent administrative actions</caption>
                <thead>
                  <tr>
                    <th scope="col">When</th>
                    <th scope="col">Category</th>
                    <th scope="col">Action</th>
                    <th scope="col">Subject</th>
                  </tr>
                </thead>
                <tbody>
                  {page.data.slice(0, 8).map((e) => (
                    <tr key={e.id}>
                      <td>{new Date(e.created_at).toLocaleString()}</td>
                      <td>{e.category}</td>
                      <td>{e.action}</td>
                      <td className="break-anywhere">{e.subject_id ?? '—'}</td>
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
