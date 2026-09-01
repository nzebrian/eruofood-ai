import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { AuthCard, ErrorText } from '@shared/components/AuthCard';
import { Button } from '@shared/components/Button';
import { FormField } from '@shared/components/FormField';
import { ApiRequestError } from '@lib/apiClient';
import { useAuth } from '../useAuth';
import { authApi } from '../authApi';
import { isTwoFactorChallenge } from '../types';

export function LoginPage(): React.JSX.Element {
  const { login, completeLogin } = useAuth();
  const navigate = useNavigate();

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [challengeToken, setChallengeToken] = useState<string | null>(null);
  const [code, setCode] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function onSubmit(event: React.FormEvent): Promise<void> {
    event.preventDefault();
    setError(null);
    setBusy(true);
    try {
      if (challengeToken) {
        completeLogin(await authApi.loginTwoFactor({ challenge_token: challengeToken, code }));
        void navigate('/');
        return;
      }
      const result = await login(email, password);
      if (isTwoFactorChallenge(result)) {
        setChallengeToken(result.challenge_token);
      } else {
        void navigate('/');
      }
    } catch (err) {
      setError(err instanceof ApiRequestError ? err.error.message : 'Something went wrong.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <AuthCard title="Sign in" subtitle="Welcome back to EruoFood AI">
      <form onSubmit={(e) => void onSubmit(e)}>
        <ErrorText message={error} />
        {challengeToken ? (
          <FormField
            label="Two-factor code"
            name="code"
            value={code}
            onChange={(e) => setCode(e.target.value)}
            autoFocus
            required
          />
        ) : (
          <>
            <FormField
              label="Email"
              name="email"
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
            />
            <FormField
              label="Password"
              name="password"
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
            />
          </>
        )}
        <Button type="submit" busy={busy}>
          {challengeToken ? 'Verify' : 'Sign in'}
        </Button>
      </form>
      <div className="auth__links">
        <Link to="/forgot-password">Forgot password?</Link>
        <Link to="/register">Create account</Link>
      </div>
    </AuthCard>
  );
}
