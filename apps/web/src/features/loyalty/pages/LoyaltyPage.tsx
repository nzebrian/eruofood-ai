import { useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { AsyncView, EmptyState } from '@shared/components/StateViews';
import { describeError, useAsyncData } from '@shared/hooks/useAsyncData';
import { loyaltyApi } from '../loyaltyApi';
import type { LedgerEntry, LoyaltyAccount, ReferralCode, Reward } from '../types';

interface LoyaltyView {
  account: LoyaltyAccount;
  rewards: Reward[];
  ledger: LedgerEntry[];
}

/** The member's loyalty hub: balance and tier progress, rewards to redeem, points history, referral code. */
export function LoyaltyPage(): React.JSX.Element {
  const loyalty = useAsyncData<LoyaltyView>(async () => {
    const [account, rewards, ledger] = await Promise.all([
      loyaltyApi.me(),
      loyaltyApi.rewards(),
      loyaltyApi.ledger(),
    ]);
    return { account, rewards: rewards.data, ledger: ledger.data };
  }, 'loyalty|hub');

  return (
    <Layout>
      <h1>Rewards</h1>

      <AsyncView
        state={loyalty.state}
        loadingLabel="Loading your rewards…"
        errorTitle="We could not load your rewards"
        onRetry={loyalty.reload}
      >
        {(view) => <LoyaltyHub view={view} onReload={loyalty.reload} />}
      </AsyncView>
    </Layout>
  );
}

function LoyaltyHub({
  view,
  onReload,
}: {
  view: LoyaltyView;
  onReload: () => void;
}): React.JSX.Element {
  const [applyCode, setApplyCode] = useState('');
  const [notice, setNotice] = useState<string | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<string | null>(null);
  const [referral, setReferral] = useState<ReferralCode | null>(null);

  const { account, rewards, ledger } = view;
  const balance = account.balance;
  const tierName = 'name' in account.tier ? account.tier.name : account.tier.key;

  function redeem(reward: Reward): void {
    setBusyId(reward.id);
    setNotice(null);
    setFailure(null);
    loyaltyApi
      .redeem(reward.id)
      .then((redemption) => {
        setNotice(`Redeemed “${reward.name}” — your code is ${redemption.code}.`);
        onReload();
      })
      .catch((err: unknown) =>
        setFailure(describeError(err, 'Could not redeem this reward — check your balance.')),
      )
      .finally(() => setBusyId(null));
  }

  function showReferral(): void {
    setFailure(null);
    loyaltyApi
      .referralCode()
      .then(setReferral)
      .catch((err: unknown) =>
        setFailure(describeError(err, 'Could not fetch your referral code.')),
      );
  }

  function apply(e: React.FormEvent): void {
    e.preventDefault();
    if (applyCode.trim() === '') return;
    setNotice(null);
    setFailure(null);
    loyaltyApi
      .applyReferral(applyCode.trim())
      .then(() => {
        setNotice('Referral code applied — you will both earn points on your first order.');
        setApplyCode('');
      })
      .catch((err: unknown) =>
        setFailure(describeError(err, 'That referral code could not be applied.')),
      );
  }

  return (
    <>
      <section className="loyalty-summary">
        <div className="loyalty-balance">
          <span className="loyalty-balance__points">{balance.toLocaleString()}</span>
          <span className="muted">points</span>
        </div>
        <div className="loyalty-tier">
          <span className={`badge badge--tier-${account.tier.key}`}>{tierName}</span>
          {account.next_tier != null && (
            <p className="muted">
              {account.next_tier.points_to_go.toLocaleString()} points to {account.next_tier.name}
            </p>
          )}
        </div>
      </section>

      {notice !== null && (
        <p className="loyalty-notice" role="status">
          {notice}
        </p>
      )}
      {failure !== null && (
        <p className="error" role="alert">
          {failure}
        </p>
      )}

      <div className="loyalty-layout">
        <section>
          <h2>Rewards catalogue</h2>
          {rewards.length === 0 ? (
            <EmptyState
              title="No rewards available right now"
              description="New rewards are added regularly — check back soon."
            />
          ) : (
            <ul className="reward-list">
              {rewards.map((r) => (
                <li key={r.id} className="reward-item">
                  <div className="reward-item__body">
                    <strong>{r.name}</strong>
                    <p className="muted">{r.description}</p>
                    <span className="reward-item__cost">{r.points_cost.toLocaleString()} pts</span>
                  </div>
                  {/*
                    This is the call site that exposed F-03. `busy` and
                    `disabled` are computed from different things, and the
                    shared Button used to let the caller's `disabled` win — so
                    an affordable reward stayed clickable while its redemption
                    was in flight, and a double-tap redeemed twice.
                  */}
                  <Button
                    busy={busyId === r.id}
                    disabled={balance < r.points_cost}
                    onClick={() => redeem(r)}
                  >
                    Redeem
                    <span className="sr-only"> {r.name}</span>
                  </Button>
                </li>
              ))}
            </ul>
          )}
        </section>

        <section>
          <h2>Points history</h2>
          {ledger.length === 0 ? (
            <EmptyState
              title="No activity yet"
              description="Points you earn and spend will be listed here."
            />
          ) : (
            <ul className="ledger-list">
              {ledger.map((e) => (
                <li key={e.id} className="ledger-item">
                  <span
                    className={`ledger-item__points ${e.points >= 0 ? 'is-positive' : 'is-negative'}`}
                  >
                    {e.points >= 0 ? '+' : ''}
                    {e.points.toLocaleString()}
                  </span>
                  <span className="ledger-item__reason">{e.reason}</span>
                  <span className="muted">{new Date(e.created_at).toLocaleDateString()}</span>
                </li>
              ))}
            </ul>
          )}

          <h2>Refer a friend</h2>
          {referral === null ? (
            <Button className="button--secondary" onClick={showReferral}>
              Show my referral code
            </Button>
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
    </>
  );
}
