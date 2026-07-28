import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Layout } from '@shared/components/Layout';
import { adminApi } from '../adminApi';
import type { AuditEntry } from '../types';

const SECTIONS = [
  { to: '/admin/users', title: 'User Administration', desc: 'Search, suspend and verify users' },
  { to: '/admin/content', title: 'Content Management', desc: 'Pages, blog, news, FAQ and banners' },
  { to: '/admin/config', title: 'System Configuration', desc: 'Settings, feature flags, maintenance' },
  { to: '/admin/support', title: 'Support Centre', desc: 'The live ticket queue' },
  { to: '/analytics', title: 'Analytics', desc: 'Executive & operations dashboards' },
];

/** Platform administration overview: entry points plus the latest audit activity. */
export function AdminDashboardPage(): React.JSX.Element {
  const [recent, setRecent] = useState<AuditEntry[]>([]);

  useEffect(() => {
    adminApi
      .audit('', 1)
      .then((page) => setRecent(page.data.slice(0, 8)))
      .catch(() => setRecent([]));
  }, []);

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
      {recent.length === 0 ? (
        <p className="muted">No audit activity yet.</p>
      ) : (
        <table className="admin-table">
          <thead>
            <tr>
              <th>When</th>
              <th>Category</th>
              <th>Action</th>
              <th>Subject</th>
            </tr>
          </thead>
          <tbody>
            {recent.map((e) => (
              <tr key={e.id}>
                <td>{new Date(e.created_at).toLocaleString()}</td>
                <td>{e.category}</td>
                <td>{e.action}</td>
                <td>{e.subject_id ?? '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </Layout>
  );
}
