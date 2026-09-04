import { useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { FormField } from '@shared/components/FormField';
import { ErrorText } from '@shared/components/AuthCard';
import { Loading } from '@shared/components/StateViews';
import { describeError } from '@shared/hooks/useAsyncData';
import { useAuth } from '@features/auth/useAuth';
import { authApi } from '@features/auth/authApi';

/**
 * The signed-in user's account page.
 *
 * M48 / F-10: this was the one authenticated destination that did not render
 * inside `Layout`. It is linked from the header as "Account", so a user could
 * arrive here and then find the entire navigation gone — the browser back
 * button was the only way out. It is also the only place `logout()` is called
 * from, which made the dead end worse: signing out required first reaching a
 * page with no way onward.
 */
export function ProfilePage(): React.JSX.Element {
  const { user, logout, refreshUser } = useAuth();

  const [name, setName] = useState(user?.name ?? '');
  const [phone, setPhone] = useState(user?.phone ?? '');
  const [status, setStatus] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [signingOut, setSigningOut] = useState(false);

  if (!user) {
    return (
      <Layout>
        <Loading label="Loading your account…" />
      </Layout>
    );
  }

  async function onSave(event: React.FormEvent): Promise<void> {
    event.preventDefault();
    setBusy(true);
    setError(null);
    setStatus(null);
    try {
      await authApi.updateProfile({ name, phone: phone || null });
      await refreshUser();
      setStatus('Profile updated.');
    } catch (err) {
      setError(describeError(err, 'Update failed.'));
    } finally {
      setBusy(false);
    }
  }

  async function onSignOut(): Promise<void> {
    setSigningOut(true);
    try {
      await logout();
    } finally {
      // `ProtectedRoute` sees the cleared session and redirects to /login, so
      // there is nothing to navigate to from here.
      setSigningOut(false);
    }
  }

  return (
    <Layout>
      <div className="page">
        <header className="page__header">
          <h1>My profile</h1>
          <Button
            className="button--ghost"
            busy={signingOut}
            onClick={() => void onSignOut()}
          >
            Sign out
          </Button>
        </header>

        <section className="page__meta">
          <p>
            <strong>Email:</strong> {user.email}{' '}
            {user.email_verified ? '✓ verified' : '(unverified)'}
          </p>
          <p>
            <strong>Roles:</strong> {user.roles.join(', ')}
          </p>
          <p>
            <strong>Two-factor:</strong> {user.two_factor_enabled ? 'enabled' : 'disabled'}
          </p>
        </section>

        <form onSubmit={(e) => void onSave(e)} className="page__form">
          <ErrorText message={error} />
          {status !== null ? (
            <p className="auth__success" role="status">
              {status}
            </p>
          ) : null}
          <FormField
            label="Full name"
            name="name"
            value={name}
            onChange={(e) => setName(e.target.value)}
          />
          <FormField
            label="Phone (E.164)"
            name="phone"
            value={phone}
            onChange={(e) => setPhone(e.target.value)}
            placeholder="+2348012345678"
          />
          <Button type="submit" busy={busy}>
            Save changes
          </Button>
        </form>
      </div>
    </Layout>
  );
}
