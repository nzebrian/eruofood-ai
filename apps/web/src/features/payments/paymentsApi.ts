import { apiClient } from '@lib/apiClient';
import { newIdempotencyKey } from '@lib/idempotency';
import type {
  Paginated,
  Payment,
  PaymentIntent,
  SavedMethod,
  Wallet,
  WalletTransaction,
} from './types';

/**
 * Client for the Payments REST endpoints (mounted at /payments).
 *
 * ## Idempotency (M43)
 *
 * The four money-moving calls below — initiate, top-up, transfer, refund — mint
 * a key per invocation and send it as `Idempotency-Key`. The reads and the
 * saved-method management do not, because repeating them cannot move money.
 *
 * `idempotencyKey` is an explicit trailing parameter rather than something the
 * transport invents, so a caller re-sending an **identical** payload can pass
 * the key it already used and have the server replay the original result. Note
 * the server's rule before doing that: the same key with a *different* payload
 * is refused outright as `IDEMPOTENCY_KEY_REUSED`, so a retry whose amount or
 * recipient has changed must take a new key — which is what omitting the
 * argument gives it.
 */
export const paymentsApi = {
  initiate: (
    payload: {
      amount_minor: number;
      customer_email: string;
      order_id?: string;
      provider?: string;
    },
    idempotencyKey: string = newIdempotencyKey(),
  ) => apiClient.postIdempotent<PaymentIntent>('/payments/payments', payload, idempotencyKey),
  verify: (id: string) => apiClient.post<Payment>(`/payments/payments/${id}/verify`),
  payments: () => apiClient.getPage<Paginated<Payment>>('/payments/payments'),
  payment: (id: string) => apiClient.get<Payment>(`/payments/payments/${id}`),

  wallet: () => apiClient.get<Wallet>('/payments/wallet'),
  statement: () => apiClient.getPage<Paginated<WalletTransaction>>('/payments/wallet/statement'),
  topUp: (
    amount_minor: number,
    customer_email: string,
    idempotencyKey: string = newIdempotencyKey(),
  ) =>
    apiClient.postIdempotent<PaymentIntent>(
      '/payments/wallet/topup',
      { amount_minor, customer_email },
      idempotencyKey,
    ),
  transfer: (
    to_user_id: string,
    amount_minor: number,
    note?: string,
    idempotencyKey: string = newIdempotencyKey(),
  ) =>
    apiClient.postIdempotent<Wallet>(
      '/payments/wallet/transfer',
      { to_user_id, amount_minor, note },
      idempotencyKey,
    ),

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

  refund: (
    payment_id: string,
    reason: string,
    amount_minor?: number,
    idempotencyKey: string = newIdempotencyKey(),
  ) =>
    apiClient.postIdempotent<unknown>(
      '/payments/refunds',
      { payment_id, reason, amount_minor },
      idempotencyKey,
    ),
};
