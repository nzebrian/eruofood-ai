import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { AuthCard, ErrorText } from '@shared/components/AuthCard';
import { Button } from '@shared/components/Button';
import { FormField } from '@shared/components/FormField';
import { ApiRequestError } from '@lib/apiClient';
import { useAuth } from '../useAuth';

export function RegisterPage(): React.JSX.Element {
  const { register } = useAuth();
  const navigate = useNavigate();

  const [form, setForm] = useState({ name: '', email: '', password: '', password_confirmation: '' });
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  function update(field: keyof typeof form) {
    return (e: React.ChangeEvent<HTMLInputElement>) => setForm({ ...form, [field]: e.target.value });
  }

  async function onSubmit(event: React.FormEvent): Promise<void> {
    event.preventDefault();
    setError(null);
    setBusy(true);
    try {
      await register(form);
      void navigate('/');
    } catch (err) {
      setError(err instanceof ApiRequestError ? err.error.message : 'Registration failed.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <AuthCard title="Create account" subtitle="Join EruoFood AI">
      <form onSubmit={(e) => void onSubmit(e)}>
        <ErrorText message={error} />
        <FormField label="Full name" name="name" value={form.name} onChange={update('name')} required />
        <FormField label="Email" name="email" type="email" value={form.email} onChange={update('email')} required />
        <FormField
          label="Password"
          name="password"
          type="password"
          value={form.password}
          onChange={update('password')}
          required
        />
        <FormField
          label="Confirm password"
          name="password_confirmation"
          type="password"
          value={form.password_confirmation}
          onChange={update('password_confirmation')}
          required
        />
        <Button type="submit" busy={busy}>
          Create account
        </Button>
      </form>
      <div className="auth__links">
        <Link to="/login">Already have an account? Sign in</Link>
      </div>
    </AuthCard>
  );
}
