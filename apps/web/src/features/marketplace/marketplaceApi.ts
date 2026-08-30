import { apiClient } from '@lib/apiClient';
import { newIdempotencyKey } from '@lib/idempotency';
import type { Cart, MenuItem, Order, OrderSummary, Paginated, SalesSummary, Vendor, VendorSummary } from './types';

function query(params: Record<string, string | number | boolean | undefined>): string {
  const q = new URLSearchParams();
  for (const [k, v] of Object.entries(params)) {
    if (v !== undefined && v !== '') q.set(k, String(v));
  }
  const s = q.toString();
  return s ? `?${s}` : '';
}

export interface CheckoutPayload {
  fulfilment: 'delivery' | 'pickup';
  delivery_address?: {
    line: string;
    city: string;
    state: string;
    location?: { latitude: number; longitude: number };
  };
  note?: string;
}

/** Client for the marketplace REST endpoints. */
export const marketplaceApi = {
  vendors: (params: Record<string, string | number | boolean | undefined>) =>
    apiClient.getPage<Paginated<VendorSummary>>(`/vendors${query(params)}`),
  vendor: (slug: string) => apiClient.get<Vendor>(`/vendors/${slug}`),
  menu: (vendorId: string) => apiClient.get<MenuItem[]>(`/vendors/${vendorId}/menu`),

  cart: () => apiClient.get<Cart>('/cart'),
  addToCart: (menuItemId: string, quantity: number, variantName?: string) =>
    apiClient.post<Cart>('/cart/items', {
      menu_item_id: menuItemId,
      quantity,
      variant_name: variantName,
    }),
  updateCartItem: (menuItemId: string, quantity: number, variantName?: string) =>
    apiClient.put<Cart>('/cart/items', { menu_item_id: menuItemId, quantity, variant_name: variantName }),
  clearCart: () => apiClient.delete<void>('/cart'),

  /**
   * Place the order (M43).
   *
   * The only money-moving call on this client. `advanceOrder` changes an order's
   * status and moves nothing, so it stays unkeyed. See `commerceApi.checkout`
   * for why the key is an explicit parameter rather than a transport default.
   */
  checkout: (payload: CheckoutPayload, idempotencyKey: string = newIdempotencyKey()) =>
    apiClient.postIdempotent<Order>('/checkout', payload, idempotencyKey),
  orders: () => apiClient.getPage<Paginated<OrderSummary>>('/orders'),
  order: (id: string) => apiClient.get<Order>(`/orders/${id}`),

  myVendors: () => apiClient.get<Vendor[]>('/me/vendors'),
  vendorDashboard: (id: string) => apiClient.get<SalesSummary>(`/vendors/${id}/dashboard`),
  vendorOrders: (id: string) => apiClient.getPage<Paginated<OrderSummary>>(`/vendors/${id}/orders`),
  advanceOrder: (id: string, status: string) => apiClient.post<Order>(`/orders/${id}/status`, { status }),
};
