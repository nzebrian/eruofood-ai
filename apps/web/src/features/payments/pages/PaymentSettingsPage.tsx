import { useCallback, useEffect, useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { paymentsApi } from '../paymentsApi';
import type { SavedMethod } from '../types';

/** Saved (tokenised) payment methods. */
export function PaymentSettingsPage(): React.JSX.Element {
  const [methods, setMethods] = useState<SavedMethod[]>([]);

  const refresh = useCallback((): void => {
    paymentsApi
      .methods()
      .then(setMethods)
      .catch(() => setMethods([]));
  }, []);

  useEffect(refresh, [refresh]);

  async function addDemoCard(): Promise<void> {
    await paymentsApi.saveMethod({
      provider: 'paystack',
      token: `tok_${Date.now()}`,
      brand: 'visa',
      last4: '4081',
      expiry_month: 12,
      expiry_year: 2030,
      default: methods.length === 0,
    });
    refresh();
  }

  async function remove(id: string): Promise<void> {
    await paymentsApi.deleteMethod(id);
    refresh();
  }

  async function makeDefault(id: string): Promise<void> {
    await paymentsApi.makeDefault(id);
    refresh();
  }

  return (
    <Layout>
      <h1>Payment settings</h1>
      <p className="muted">
        Saved cards are tokenised — only the card brand and last four digits are stored.
      </p>

      {methods.length === 0 ? (
        <p className="muted">No saved methods.</p>
      ) : (
        <ul className="list">
          {methods.map((m) => (
            <li key={m.id} className="pay-row">
              <span>
                {m.label}
                {m.default ? ' · Default' : ''}
              </span>
              <span>
                {!m.default && (
                  <Button className="button--secondary" onClick={() => void makeDefault(m.id)}>
                    Make default
                  </Button>
                )}{' '}
                <Button className="button--secondary" onClick={() => void remove(m.id)}>
                  Remove
                </Button>
              </span>
            </li>
          ))}
        </ul>
      )}

      <div className="pay-actions">
        <Button onClick={() => void addDemoCard()}>Add a card</Button>
      </div>
    </Layout>
  );
}
