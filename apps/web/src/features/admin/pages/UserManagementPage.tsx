import { useCallback, useEffect, useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { adminApi } from '../adminApi';
import type { UserSummary } from '../types';

/** User Administration: search, suspend and reinstate platform users. */
export function UserManagementPage(): React.JSX.Element {
  const [q, setQ] = useState('');
  const [status, setStatus] = useState('');
  const [users, setUsers] = useState<UserSummary[]>([]);
  const [loading, setLoading] = useState(false);
  const [busyId, setBusyId] = useState<string | null>(null);

  const refresh = useCallback((): void => {
    setLoading(true);
    adminApi
      .users(q, status, 1)
      .then((page) => setUsers(page.data))
      .catch(() => setUsers([]))
      .finally(() => setLoading(false));
  }, [q, status]);

  useEffect(refresh, [refresh]);

  const toggle = (user: UserSummary): void => {
    setBusyId(user.id);
    const action =
      user.status === 'suspended'
        ? adminApi.reinstateUser(user.id)
        : adminApi.suspendUser(user.id, 'Administrative action');
    action
      .then(refresh)
      .catch(() => undefined)
      .finally(() => setBusyId(null));
  };

  return (
    <Layout>
      <h1>User Administration</h1>

      <form
        className="admin-filters"
        onSubmit={(e) => {
          e.preventDefault();
          refresh();
        }}
      >
        <input
          value={q}
          onChange={(e) => setQ(e.target.value)}
          placeholder="Search name or email"
          aria-label="Search users"
        />
        <select value={status} onChange={(e) => setStatus(e.target.value)} aria-label="Status">
          <option value="">All statuses</option>
          <option value="active">Active</option>
          <option value="suspended">Suspended</option>
        </select>
        <Button type="submit">Search</Button>
      </form>

      {loading ? (
        <p className="muted">Loading…</p>
      ) : users.length === 0 ? (
        <p className="muted">No users found.</p>
      ) : (
        <table className="admin-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Email</th>
              <th>Status</th>
              <th>Verified</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {users.map((u) => (
              <tr key={u.id}>
                <td>{u.name}</td>
                <td>{u.email}</td>
                <td>
                  <span className={`badge badge--${u.status}`}>{u.status}</span>
                </td>
                <td>{u.verified ? 'Yes' : 'No'}</td>
                <td>
                  <button
                    className="button button--secondary"
                    onClick={() => toggle(u)}
                    disabled={busyId === u.id}
                  >
                    {u.status === 'suspended' ? 'Reinstate' : 'Suspend'}
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </Layout>
  );
}
