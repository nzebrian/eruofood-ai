import { useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { AsyncView, EmptyState, ErrorState } from '@shared/components/StateViews';
import { describeError, useAsyncData } from '@shared/hooks/useAsyncData';
import { paymentsApi } from '../paymentsApi';

/** Saved (tokenised) payment methods. */
export function PaymentSettingsPage(): React.JSX.Element {
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);

  const methods = useAsyncData(() => paymentsApi.methods(), 'payments|methods');
  const savedCount = methods.state.status === 'ready' ? methods.state.data.length : 0;

  async function run(action: () => Promise<unknown>, failure: string): Promise<void> {
    setBusy(true);
    setActionError(null);
    try {
      await action();
      methods.reload();
    } catch (err) {
      // Every one of these used to be an unhandled rejection: the calls were
      // awaited with no try/catch at all, so a failed removal simply did
      // nothing visible and the card stayed on screen.
      setActionError(describeError(err, failure));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Layout>
      <h1>Payment settings</h1>
      <p className="muted">
        Saved cards are tokenised — only the card brand and last four digits are stored.
      </p>

      {actionError !== null ? <ErrorState message={actionError} title="That did not work" /> : null}

      <AsyncView
        state={methods.state}
        loadingLabel="Loading your saved cards…"
        errorTitle="We could not load your saved cards"
        onRetry={methods.reload}
      >
        {(saved) =>
          saved.length === 0 ? (
            <EmptyState
              title="No saved cards"
              description="Add one below to check out faster next time."
            />
          ) : (
            <ul className="list">
              {saved.map((m) => (
                <li key={m.id} className="pay-row">
                  <span>
                    {m.label}
                    {m.default ? ' · Default' : ''}
                  </span>
                  <span className="pay-row__actions">
                    {!m.default && (
                      <Button
                        className="button--secondary"
                        busy={busy}
                        onClick={() =>
                          void run(
                            () => paymentsApi.makeDefault(m.id),
                            'Could not make that card your default.',
                          )
                        }
                      >
                        Make default
                        <span className="sr-only"> — {m.label}</span>
                      </Button>
                    )}{' '}
                    <Button
                      className="button--secondary"
                      busy={busy}
                      onClick={() =>
                        void run(() => paymentsApi.deleteMethod(m.id), 'Could not remove that card.')
                      }
                    >
                      Remove
                      <span className="sr-only"> — {m.label}</span>
                    </Button>
                  </span>
                </li>
              ))}
            </ul>
          )
        }
      </AsyncView>

      <div className="pay-actions">
        <Button
          busy={busy}
          onClick={() =>
            void run(
              () =>
                paymentsApi.saveMethod({
                  provider: 'paystack',
                  token: `tok_${String(Date.now())}`,
                  brand: 'visa',
                  last4: '4081',
                  expiry_month: 12,
                  expiry_year: 2030,
                  default: savedCount === 0,
                }),
              'Could not add that card.',
            )
          }
        >
          Add a card
        </Button>
      </div>
    </Layout>
  );
}
