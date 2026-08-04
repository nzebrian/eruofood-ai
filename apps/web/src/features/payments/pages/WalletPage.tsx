import { useCallback, useEffect, useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { useAuth } from '@features/auth/useAuth';
import { ApiRequestError } from '@lib/apiClient';
import { paymentsApi } from '../paymentsApi';
import { formatMoney, type Wallet, type WalletTransaction } from '../types';

/** The user's wallet: balance, top-up and statement. */
export function WalletPage(): React.JSX.Element {
  const { user } = useAuth();
  const [wallet, setWallet] = useState<Wallet | null>(null);
  const [statement, setStatement] = useState<WalletTransaction[]>([]);
  const [amount, setAmount] = useState('5000');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const refresh = useCallback((): void => {
    paymentsApi.wallet().then(setWallet).catch(() => setWallet(null));
    paymentsApi
      .statement()
      .then((page) => setStatement(page.data))
      .catch(() => setStatement([]));
  }, []);

  useEffect(refresh, [refresh]);

  async function topUp(event: React.FormEvent): Promise<void> {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await paymentsApi.topUp(Math.round(Number(amount) * 100), user?.email ?? 'customer@example.com');
      refresh();
    } catch (err) {
      setError(err instanceof ApiRequestError ? err.error.message : 'Top-up failed.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <Layout>
      <h1>Your wallet</h1>
      <div className="pay-balance">
        {wallet ? formatMoney(wallet.balance_minor, wallet.currency) : '—'}
      </div>

      <form onSubmit={(e) => void topUp(e)} className="pay-topup">
        <label>
          Top up (₦)
          <input
            type="number"
            min={1}
            value={amount}
            onChange={(e) => setAmount(e.target.value)}
            aria-label="Top-up amount"
          />
        </label>
        <Button type="submit" busy={busy}>
          Top up
        </Button>
      </form>
      {error && <p className="pay-error">{error}</p>}

      <h2>Recent transactions</h2>
      {statement.length === 0 ? (
        <p className="muted">No transactions yet.</p>
      ) : (
        <ul className="list">
          {statement.map((t) => (
            <li key={t.id} className="pay-row">
              <span>{t.description ?? t.type}</span>
              <span className={t.direction === 'credit' ? 'pay-credit' : 'pay-debit'}>
                {t.direction === 'credit' ? '+' : '−'}
                {formatMoney(t.amount_minor)}
              </span>
              <span className="muted">{formatMoney(t.balance_after_minor)}</span>
            </li>
          ))}
        </ul>
      )}
    </Layout>
  );
}
