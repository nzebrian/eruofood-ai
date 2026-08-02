import { useCallback, useEffect, useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { loyaltyApi } from '../loyaltyApi';
import type { LedgerEntry, LoyaltyAccount, ReferralCode, Reward } from '../types';

/** The member's loyalty hub: balance and tier progress, rewards to redeem, points history, referral code. */
export function LoyaltyPage(): React.JSX.Element {
  const [account, setAccount] = useState<LoyaltyAccount | null>(null);
  const [rewards, setRewards] = useState<Reward[]>([]);
  const [ledger, setLedger] = useState<LedgerEntry[]>([]);
  const [referral, setReferral] = useState<ReferralCode | null>(null);
  const [applyCode, setApplyCode] = useState('');
  const [notice, setNotice] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<string | null>(null);

  const refresh = useCallback((): void => {
    loyaltyApi
      .me()
      .then(setAccount)
      .catch(() => setAccount(null));
    loyaltyApi
      .rewards()
      .then((page) => setRewards(page.data))
      .catch(() => setRewards([]));
    loyaltyApi
      .ledger()
      .then((page) => setLedger(page.data))
      .catch(() => setLedger([]));
  }, []);

  useEffect(refresh, [refresh]);

  const redeem = (reward: Reward): void => {
    setBusyId(reward.id);
    setNotice(null);
    loyaltyApi
      .redeem(reward.id)
      .then((redemption) => {
        setNotice(`Redeemed "${reward.name}" — your code is ${redemption.code}.`);
        refresh();
      })
      .catch(() => setNotice('Could not redeem this reward — check your balance.'))
      .finally(() => setBusyId(null));
  };

  const showReferral = (): void => {
    loyaltyApi
      .referralCode()
      .then(setReferral)
      .catch(() => setReferral(null));
  };

  const apply = (e: React.FormEvent): void => {
    e.preventDefault();
    if (applyCode.trim() === '') return;
    loyaltyApi
      .applyReferral(applyCode.trim())
      .then(() => {
        setNotice('Referral code applied — you will both earn points on your first order.');
        setApplyCode('');
      })
      .catch(() => setNotice('That referral code could not be applied.'));
  };

  const balance = account?.balance ?? 0;
  const tierName = account !== null && 'name' in account.tier ? account.tier.name : (account?.tier.key ?? '—');

  return (
    <Layout>
      <h1>Rewards</h1>

      <section className="loyalty-summary">
        <div className="loyalty-balance">
          <span className="loyalty-balance__points">{balance.toLocaleString()}</span>
          <span className="muted">points</span>
        </div>
        <div className="loyalty-tier">
          <span className={`badge badge--tier-${account?.tier.key ?? 'bronze'}`}>{tierName}</span>
          {account?.next_tier != null && (
            <p className="muted">
              {account.next_tier.points_to_go.toLocaleString()} points to {account.next_tier.name}
            </p>
          )}
        </div>
      </section>

      {notice !== null && <p className="loyalty-notice">{notice}</p>}

      <div className="loyalty-layout">
        <section>
          <h2>Rewards catalogue</h2>
          <ul className="reward-list">
            {rewards.length === 0 ? (
              <li className="muted">No rewards available right now.</li>
            ) : (
              rewards.map((r) => (
                <li key={r.id} className="reward-item">
                  <div className="reward-item__body">
                    <strong>{r.name}</strong>
                    <p className="muted">{r.description}</p>
                    <span className="reward-item__cost">{r.points_cost.toLocaleString()} pts</span>
                  </div>
                  <Button busy={busyId === r.id} disabled={balance < r.points_cost} onClick={() => redeem(r)}>
                    Redeem
                  </Button>
                </li>
              ))
            )}
          </ul>
        </section>

        <section>
          <h2>Points history</h2>
          <ul className="ledger-list">
            {ledger.length === 0 ? (
              <li className="muted">No activity yet.</li>
            ) : (
              ledger.map((e) => (
                <li key={e.id} className="ledger-item">
                  <span className={`ledger-item__points ${e.points >= 0 ? 'is-positive' : 'is-negative'}`}>
                    {e.points >= 0 ? '+' : ''}
                    {e.points.toLocaleString()}
                  </span>
                  <span className="ledger-item__reason">{e.reason}</span>
                  <span className="muted">{new Date(e.created_at).toLocaleDateString()}</span>
                </li>
              ))
            )}
          </ul>

          <h2>Refer a friend</h2>
          {referral === null ? (
            <button className="button button--secondary" onClick={showReferral}>
              Show my referral code
            </button>
          ) : (
            <p className="loyalty-referral-code">
              Your code: <strong>{referral.code}</strong>
            </p>
          )}
          <form className="loyalty-apply" onSubmit={apply}>
            <input
              value={applyCode}
              onChange={(e) => setApplyCode(e.target.value)}
              placeholder="Enter a referral code"
              aria-label="Referral code"
            />
            <Button type="submit">Apply</Button>
          </form>
        </section>
      </div>
    </Layout>
  );
}
