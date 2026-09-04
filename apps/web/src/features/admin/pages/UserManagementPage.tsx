import { useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { AsyncView, EmptyState, ErrorState } from '@shared/components/StateViews';
import { describeError, useAsyncData } from '@shared/hooks/useAsyncData';
import { adminApi } from '../adminApi';
import type { UserSummary } from '../types';

/** User Administration: search, suspend and reinstate platform users. */
export function UserManagementPage(): React.JSX.Element {
  const [q, setQ] = useState('');
  const [status, setStatus] = useState('');
  const [query, setQuery] = useState({ q: '', status: '' });
  const [busyId, setBusyId] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  const users = useAsyncData(
    () => adminApi.users(query.q, query.status, 1),
    `admin|users|${query.q}|${query.status}`,
  );

  function toggle(user: UserSummary): void {
    setBusyId(user.id);
    setActionError(null);
    const action =
      user.status === 'suspended'
        ? adminApi.reinstateUser(user.id)
        : adminApi.suspendUser(user.id, 'Administrative action');

    action
      .then(() => users.reload())
      .catch((err: unknown) =>
        // `.catch(() => undefined)`: suspending a user could fail and the
        // administrator would see the row unchanged, with no way to tell
        // whether the action had taken effect.
        setActionError(
          describeError(
            err,
            user.status === 'suspended'
              ? 'Could not reinstate that user.'
              : 'Could not suspend that user.',
          ),
        ),
      )
      .finally(() => setBusyId(null));
  }

  return (
    <Layout>
      <h1>User Administration</h1>

      <form
        className="admin-filters"
        onSubmit={(e) => {
          e.preventDefault();
          setQuery({ q, status });
        }}
      >
        <input
          value={q}
          onChange={(e) => setQ(e.target.value)}
          placeholder="Search name or email"
          aria-label="Search users by name or email"
        />
        <select
          value={status}
          onChange={(e) => setStatus(e.target.value)}
          aria-label="Filter by account status"
        >
          <option value="">All statuses</option>
          <option value="active">Active</option>
          <option value="suspended">Suspended</option>
        </select>
        <Button type="submit">Search</Button>
      </form>

      {actionError !== null ? <ErrorState message={actionError} title="That did not work" /> : null}

      <AsyncView
        state={users.state}
        loadingLabel="Loading users…"
        errorTitle="We could not load the user list"
        onRetry={users.reload}
      >
        {(page) =>
          page.data.length === 0 ? (
            <EmptyState
              title="No users match this search"
              description="Try a different name, email or status."
            />
          ) : (
            <div className="table-scroll">
              <table className="admin-table">
                <caption className="sr-only">Platform users matching the current search</caption>
                <thead>
                  <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Email</th>
                    <th scope="col">Status</th>
                    <th scope="col">Verified</th>
                    <th scope="col">
                      <span className="sr-only">Actions</span>
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {page.data.map((u) => (
                    <tr key={u.id}>
                      <td>{u.name}</td>
                      <td className="break-anywhere">{u.email}</td>
                      <td>
                        <span className={`badge badge--${u.status}`}>{u.status}</span>
                      </td>
                      <td>{u.verified ? 'Yes' : 'No'}</td>
                      <td>
                        <Button
                          className="button--secondary"
                          busy={busyId === u.id}
                          onClick={() => toggle(u)}
                        >
                          {u.status === 'suspended' ? 'Reinstate' : 'Suspend'}
                          <span className="sr-only"> {u.name}</span>
                        </Button>
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
