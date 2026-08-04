import { apiClient } from '@lib/apiClient';
import type {
  Paginated,
  Payment,
  PaymentIntent,
  SavedMethod,
  Wallet,
  WalletTransaction,
} from './types';

/** Client for the Payments REST endpoints (mounted at /payments). */
export const paymentsApi = {
  initiate: (payload: {
    amount_minor: number;
    customer_email: string;
    order_id?: string;
    provider?: string;
  }) => apiClient.post<PaymentIntent>('/payments/payments', payload),
  verify: (id: string) => apiClient.post<Payment>(`/payments/payments/${id}/verify`),
  payments: () => apiClient.getPage<Paginated<Payment>>('/payments/payments'),
  payment: (id: string) => apiClient.get<Payment>(`/payments/payments/${id}`),

  wallet: () => apiClient.get<Wallet>('/payments/wallet'),
  statement: () => apiClient.getPage<Paginated<WalletTransaction>>('/payments/wallet/statement'),
  topUp: (amount_minor: number, customer_email: string) =>
    apiClient.post<PaymentIntent>('/payments/wallet/topup', { amount_minor, customer_email }),
  transfer: (to_user_id: string, amount_minor: number, note?: string) =>
    apiClient.post<Wallet>('/payments/wallet/transfer', { to_user_id, amount_minor, note }),

  methods: () => apiClient.get<SavedMethod[]>('/payments/methods'),
  saveMethod: (payload: {
    provider: string;
    token: string;
    brand: string;
    last4: string;
    expiry_month: number;
    expiry_year: number;
    default?: boolean;
  }) => apiClient.post<SavedMethod>('/payments/methods', payload),
  makeDefault: (id: string) => apiClient.post<SavedMethod>(`/payments/methods/${id}/default`),
  deleteMethod: (id: string) => apiClient.delete<void>(`/payments/methods/${id}`),

  refund: (payment_id: string, reason: string, amount_minor?: number) =>
    apiClient.post<unknown>('/payments/refunds', { payment_id, reason, amount_minor }),
};
