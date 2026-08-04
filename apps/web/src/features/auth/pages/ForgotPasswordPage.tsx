import { useState } from 'react';
import { Link } from 'react-router-dom';
import { AuthCard, ErrorText } from '@shared/components/AuthCard';
import { Button } from '@shared/components/Button';
import { FormField } from '@shared/components/FormField';
import { authApi } from '../authApi';

export function ForgotPasswordPage(): React.JSX.Element {
  const [email, setEmail] = useState('');
  const [sent, setSent] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function onSubmit(event: React.FormEvent): Promise<void> {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await authApi.forgotPassword(email);
      setSent(true);
    } catch {
      setError('Could not send the reset link. Please try again.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <AuthCard title="Reset password" subtitle="We'll email you a reset link">
      {sent ? (
        <p className="auth__success">
          If an account exists for {email}, a reset link is on its way.
        </p>
      ) : (
        <form onSubmit={(e) => void onSubmit(e)}>
          <ErrorText message={error} />
          <FormField
            label="Email"
            name="email"
            type="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
          />
          <Button type="submit" busy={busy}>
            Send reset link
          </Button>
        </form>
      )}
      <div className="auth__links">
        <Link to="/login">Back to sign in</Link>
      </div>
    </AuthCard>
  );
}
