/** Types for the Payments, Wallet & Financial Services module. */

export type PaymentStatus =
  | 'pending'
  | 'processing'
  | 'succeeded'
  | 'failed'
  | 'cancelled'
  | 'refunded'
  | 'partially_refunded';

export interface Paginated<T> {
  data: T[];
  meta: { page: number; per_page: number; total: number };
}

export interface PaymentIntent {
  payment_id: string;
  reference: string;
  status: PaymentStatus;
  provider: string;
  authorization_url: string | null;
}

export interface Payment {
  id: string;
  reference: string;
  order_id: string | null;
  amount_minor: number;
  refunded_minor: number;
  currency: string;
  status: PaymentStatus;
  provider: string;
  method_type: string;
  created_at: string;
}

export interface Wallet {
  id: string;
  owner_type: string;
  balance_minor: number;
  currency: string;
}

export interface WalletTransaction {
  id: string;
  type: string;
  direction: 'credit' | 'debit';
  amount_minor: number;
  balance_after_minor: number;
  description: string | null;
  created_at: string;
}

export interface SavedMethod {
  id: string;
  provider: string;
  brand: string;
  last4: string;
  expiry_month: number;
  expiry_year: number;
  label: string;
  default: boolean;
}

/** Format integer minor units (kobo) as a currency string, e.g. ₦1,900.00. */
export function formatMoney(minor: number, currency = 'NGN'): string {
  const symbol = currency === 'NGN' ? '₦' : `${currency} `;
  return `${symbol}${(minor / 100).toLocaleString('en-NG', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`;
}
