import { MemoryRouter } from 'react-router-dom';
import { render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiRequestError } from '@lib/apiClient';
import type { Cart } from '../types';
import { CartPage } from './CartPage';

/**
 * M48 / F-09, on the page where the defect was worst.
 *
 * `CartPage` used to fetch with `.catch(() => setCart(null))`, and `cart ===
 * null` was also its "empty" condition. A shopper whose cart request returned
 * a 500 was therefore told, immediately before checkout, that their cart was
 * empty. These tests hold the three answers apart.
 */

const cart = vi.hoisted(() => vi.fn<() => Promise<Cart>>());

vi.mock('../marketplaceApi', () => ({
  marketplaceApi: {
    cart,
    checkout: vi.fn(),
  },
}));

vi.mock('@features/auth/useAuth', () => ({
  useAuth: () => ({ user: null, loading: false }),
}));

function renderCart(): void {
  render(
    <MemoryRouter>
      <CartPage />
    </MemoryRouter>,
  );
}

const EMPTY: Cart = { vendor_id: null, currency: 'NGN', items: [], subtotal_minor: 0 };

const FULL: Cart = {
  vendor_id: 'v1',
  currency: 'NGN',
  items: [
    {
      menu_item_id: 'm1',
      name: 'Jollof rice',
      variant_name: null,
      unit_price_minor: 250000,
      quantity: 2,
      line_total_minor: 500000,
    },
  ],
  subtotal_minor: 500000,
};

beforeEach(() => {
  cart.mockReset();
});

afterEach(() => {
  vi.restoreAllMocks();
});

describe('CartPage', () => {
  it('shows a loading state while the cart is being fetched', () => {
    cart.mockReturnValue(new Promise<Cart>(() => undefined));
    renderCart();

    expect(screen.getByRole('status')).toHaveTextContent('Loading your cart…');
    expect(screen.queryByText(/your cart is empty/i)).not.toBeInTheDocument();
  });

  it('says the cart is empty only when the server says it is empty', async () => {
    cart.mockResolvedValue(EMPTY);
    renderCart();

    expect(await screen.findByText('Your cart is empty')).toBeInTheDocument();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: /browse vendors/i })).toBeInTheDocument();
  });

  it('reports a failure as a failure, never as an empty cart', async () => {
    cart.mockRejectedValue(
      new ApiRequestError(500, { code: 'SERVER_ERROR', message: 'The kitchen is on fire.' }),
    );
    renderCart();

    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent('We could not load your cart');
    expect(alert).toHaveTextContent('The kitchen is on fire.');
    // The whole point: this must not look like an empty cart.
    expect(screen.queryByText('Your cart is empty')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Try again' })).toBeInTheDocument();
  });

  it('renders the lines and the checkout form once the cart loads', async () => {
    cart.mockResolvedValue(FULL);
    renderCart();

    expect(await screen.findByText(/2 × Jollof rice/)).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: /delivery address/i })).toBeInTheDocument();
    expect(screen.getByLabelText(/street address/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /checkout/i })).toBeInTheDocument();
    expect(screen.queryByRole('status')).not.toBeInTheDocument();
  });
});
