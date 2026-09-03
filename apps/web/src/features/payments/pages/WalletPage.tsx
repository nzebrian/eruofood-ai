import { useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { AsyncView, EmptyState } from '@shared/components/StateViews';
import { describeError, useAsyncData } from '@shared/hooks/useAsyncData';
import { useAuth } from '@features/auth/useAuth';
import { paymentsApi } from '../paymentsApi';
import { formatMoney } from '../types';

/** The user's wallet: balance, top-up and statement. */
export function WalletPage(): React.JSX.Element {
  const { user } = useAuth();
  const [amount, setAmount] = useState('5000');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  // The balance and the statement are one screen and one loader. Previously a
  // failed wallet read rendered "—" where the balance goes, which on a money
  // screen reads as a real answer rather than as "we could not ask".
  const wallet = useAsyncData(async () => {
    const [account, statement] = await Promise.all([
      paymentsApi.wallet(),
      paymentsApi.statement(),
    ]);
    return { account, statement: statement.data };
  }, 'payments|wallet');

  async function topUp(event: React.FormEvent): Promise<void> {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await paymentsApi.topUp(
        Math.round(Number(amount) * 100),
        user?.email ?? 'customer@example.com',
      );
      wallet.reload();
    } catch (err) {
      setError(describeError(err, 'Top-up failed.'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Layout>
      <h1>Your wallet</h1>

      <AsyncView
        state={wallet.state}
        loadingLabel="Loading your wallet…"
        errorTitle="We could not load your wallet"
        onRetry={wallet.reload}
      >
        {({ account, statement }) => (
          <>
            <p className="pay-balance">{formatMoney(account.balance_minor, account.currency)}</p>

            <form onSubmit={(e) => void topUp(e)} className="pay-topup">
              <label className="field">
                <span className="field__label">Top up (₦)</span>
                <input
                  className="field__input"
                  type="number"
                  min={1}
                  value={amount}
                  onChange={(e) => setAmount(e.target.value)}
                />
              </label>
              <Button type="submit" busy={busy}>
                Top up
              </Button>
            </form>
            {error !== null ? (
              <p className="pay-error" role="alert">
                {error}
              </p>
            ) : null}

            <h2>Recent transactions</h2>
            {statement.length === 0 ? (
              <EmptyState
                title="No transactions yet"
                description="Top-ups, payments and refunds will appear here."
              />
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
          </>
        )}
      </AsyncView>
    </Layout>
  );
}
