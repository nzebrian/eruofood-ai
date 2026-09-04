import { MemoryRouter } from 'react-router-dom';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiRequestError } from '@lib/apiClient';
import type { LedgerEntry, LoyaltyAccount, Paginated, Redemption, Reward } from '../types';
import { LoyaltyPage } from './LoyaltyPage';

/**
 * M48, at the call site that exposed F-03.
 *
 * `LoyaltyPage` passes `busy` and `disabled` to the shared Button computed
 * from different things: `busy` is "this redemption is in flight", `disabled`
 * is "you cannot afford this reward". Before the Button fix the caller's
 * `disabled` overwrote the component's own, so an affordable reward stayed
 * clickable while its redemption was in flight — and a double-tap redeemed a
 * value-bearing reward twice.
 *
 * The unit tests in `Button.test.tsx` cover the component. These cover the
 * page, because the defect only appeared when the two props disagreed.
 */

const me = vi.hoisted(() => vi.fn<() => Promise<LoyaltyAccount>>());
const rewards = vi.hoisted(() => vi.fn<() => Promise<Paginated<Reward>>>());
const ledger = vi.hoisted(() => vi.fn<() => Promise<Paginated<LedgerEntry>>>());
const redeem = vi.hoisted(() => vi.fn<(id: string) => Promise<Redemption>>());

vi.mock('../loyaltyApi', () => ({
  loyaltyApi: { me, rewards, ledger, redeem, referralCode: vi.fn(), applyReferral: vi.fn() },
}));

vi.mock('@features/auth/useAuth', () => ({
  useAuth: () => ({ user: null, loading: false }),
}));

const ACCOUNT: LoyaltyAccount = {
  user_id: 'u1',
  balance: 5000,
  lifetime_points: 12000,
  tier: { key: 'gold' },
  next_tier: null,
  updated_at: '2026-09-01T00:00:00Z',
};

function reward(overrides: Partial<Reward> = {}): Reward {
  return {
    id: 'r1',
    name: 'Free delivery',
    description: 'One free delivery on your next order',
    benefit_type: 'delivery',
    benefit_value: 100,
    points_cost: 1000,
    stock: null,
    active: true,
    starts_at: null,
    ends_at: null,
    created_at: '2026-08-01T00:00:00Z',
    ...overrides,
  };
}

function page<T>(data: T[]): Paginated<T> {
  return { data, meta: { page: 1, per_page: 20, total: data.length } };
}

function renderPage(): void {
  render(
    <MemoryRouter>
      <LoyaltyPage />
    </MemoryRouter>,
  );
}

beforeEach(() => {
  me.mockReset().mockResolvedValue(ACCOUNT);
  rewards.mockReset().mockResolvedValue(page([reward()]));
  ledger.mockReset().mockResolvedValue(page<LedgerEntry>([]));
  redeem.mockReset();
});

afterEach(() => {
  vi.restoreAllMocks();
});

describe('LoyaltyPage', () => {
  it('shows a loading state, then the balance', async () => {
    renderPage();

    expect(screen.getByRole('status')).toHaveTextContent('Loading your rewards…');
    expect(await screen.findByText('5,000')).toBeInTheDocument();
  });

  it('reports a failed load rather than showing an empty rewards catalogue', async () => {
    me.mockRejectedValue(
      new ApiRequestError(503, { code: 'UNAVAILABLE', message: 'Rewards are offline.' }),
    );
    renderPage();

    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent('We could not load your rewards');
    expect(alert).toHaveTextContent('Rewards are offline.');
    expect(screen.queryByText('No rewards available right now')).not.toBeInTheDocument();
  });

  it('says the catalogue is empty only when it is genuinely empty', async () => {
    rewards.mockResolvedValue(page<Reward>([]));
    renderPage();

    expect(await screen.findByText('No rewards available right now')).toBeInTheDocument();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });

  it('disables an unaffordable reward', async () => {
    rewards.mockResolvedValue(page([reward({ points_cost: 999999 })]));
    renderPage();

    const button = await screen.findByRole('button', { name: /redeem/i });
    expect(button).toBeDisabled();
  });

  it('cannot redeem an affordable reward twice while the first redemption is in flight', async () => {
    // Never settles: the redemption stays in flight for the whole test.
    redeem.mockReturnValue(new Promise<Redemption>(() => undefined));
    renderPage();

    const button = await screen.findByRole('button', { name: /redeem/i });
    // Affordable: 1000 points against a 5000 balance, so `disabled` is false
    // and `busy` is what must win.
    expect(button).toBeEnabled();

    await userEvent.click(button);

    await waitFor(() => {
      expect(screen.getByRole('button', { name: /please wait/i })).toBeDisabled();
    });

    // A dispatched click bypasses the browser's own refusal, which is what
    // proves the guard rather than just the attribute.
    fireEvent.click(screen.getByRole('button', { name: /please wait/i }));

    expect(redeem).toHaveBeenCalledTimes(1);
  });

  it('reports a failed redemption instead of leaving the button silent', async () => {
    redeem.mockRejectedValue(
      new ApiRequestError(422, { code: 'INSUFFICIENT', message: 'Not enough points.' }),
    );
    renderPage();

    await userEvent.click(await screen.findByRole('button', { name: /redeem/i }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Not enough points.');
  });
});
