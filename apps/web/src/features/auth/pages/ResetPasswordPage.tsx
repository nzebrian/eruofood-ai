import { useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { AuthCard, ErrorText } from '@shared/components/AuthCard';
import { Button } from '@shared/components/Button';
import { FormField } from '@shared/components/FormField';
import { ApiRequestError } from '@lib/apiClient';
import { authApi } from '../authApi';

export function ResetPasswordPage(): React.JSX.Element {
  const [params] = useSearchParams();
  const navigate = useNavigate();
  const email = params.get('email') ?? '';
  const token = params.get('token') ?? '';

  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function onSubmit(event: React.FormEvent): Promise<void> {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await authApi.resetPassword({
        email,
        token,
        password,
        password_confirmation: confirmation,
      });
      navigate('/login');
    } catch (err) {
      setError(err instanceof ApiRequestError ? err.error.message : 'Reset failed.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <AuthCard title="Choose a new password">
      <form onSubmit={(e) => void onSubmit(e)}>
        <ErrorText message={error} />
        <FormField
          label="New password"
          name="password"
          type="password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          required
        />
        <FormField
          label="Confirm password"
          name="password_confirmation"
          type="password"
          value={confirmation}
          onChange={(e) => setConfirmation(e.target.value)}
          required
        />
        <Button type="submit" busy={busy}>
          Reset password
        </Button>
      </form>
      <div className="auth__links">
        <Link to="/login">Back to sign in</Link>
      </div>
    </AuthCard>
  );
}
