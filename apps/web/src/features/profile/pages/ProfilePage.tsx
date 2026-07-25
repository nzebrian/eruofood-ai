import { useState } from 'react';
import { Button } from '@shared/components/Button';
import { FormField } from '@shared/components/FormField';
import { ErrorText } from '@shared/components/AuthCard';
import { ApiRequestError } from '@lib/apiClient';
import { useAuth } from '@features/auth/useAuth';
import { authApi } from '@features/auth/authApi';

export function ProfilePage(): React.JSX.Element {
  const { user, logout, refreshUser } = useAuth();

  const [name, setName] = useState(user?.name ?? '');
  const [phone, setPhone] = useState(user?.phone ?? '');
  const [status, setStatus] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  if (!user) {
    return <p>Loading…</p>;
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
      setError(err instanceof ApiRequestError ? err.error.message : 'Update failed.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="page">
      <header className="page__header">
        <h1>My profile</h1>
        <button className="button button--ghost" onClick={() => void logout()}>
          Sign out
        </button>
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
        {status ? <p className="auth__success">{status}</p> : null}
        <FormField label="Full name" name="name" value={name} onChange={(e) => setName(e.target.value)} />
        <FormField
          label="Phone (E.164)"
          name="phone"
          value={phone ?? ''}
          onChange={(e) => setPhone(e.target.value)}
          placeholder="+2348012345678"
        />
        <Button type="submit" busy={busy}>
          Save changes
        </Button>
      </form>
    </div>
  );
}
