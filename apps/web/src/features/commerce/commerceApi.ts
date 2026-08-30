import { apiClient } from '@lib/apiClient';
import { newIdempotencyKey } from '@lib/idempotency';
import type {
  Cart,
  Category,
  Order,
  OrderSummary,
  Paginated,
  PriceBreakdown,
  Product,
  ProductSummary,
  Promotion,
  Recommendation,
} from './types';

function query(params: Record<string, string | number | boolean | undefined>): string {
  const q = new URLSearchParams();
  for (const [k, v] of Object.entries(params)) {
    if (v !== undefined && v !== '') q.set(k, String(v));
  }
  const s = q.toString();
  return s ? `?${s}` : '';
}

export interface CheckoutPayload {
  pickup: boolean;
  shipping_address?: { line1: string; city: string; state: string; postcode?: string };
  note?: string;
}

/** Client for the Commerce (Marketplace/Grocery) REST endpoints (mounted at /commerce). */
export const commerceApi = {
  products: (params: Record<string, string | number | boolean | undefined>) =>
    apiClient.getPage<Paginated<ProductSummary>>(`/commerce/products${query(params)}`),
  product: (slug: string) => apiClient.get<Product>(`/commerce/products/${slug}`),
  categories: (params: Record<string, string | undefined> = {}) =>
    apiClient.get<Category[]>(`/commerce/categories${query(params)}`),
  promotions: () => apiClient.get<Promotion[]>('/commerce/promotions'),
  flashSales: () => apiClient.get<Promotion[]>('/commerce/promotions/flash-sales'),
  recommendations: () => apiClient.get<Recommendation>('/commerce/recommendations'),
  crossSell: (productId: string) =>
    apiClient.get<Recommendation>(`/commerce/products/${productId}/cross-sell`),

  cart: () => apiClient.get<Cart>('/commerce/cart'),
  addToCart: (productId: string, quantity: number, variantSku?: string) =>
    apiClient.post<Cart>('/commerce/cart/items', {
      product_id: productId,
      quantity,
      variant_sku: variantSku,
    }),
  updateCartItem: (productId: string, quantity: number, variantSku?: string) =>
    apiClient.put<Cart>('/commerce/cart/items', {
      product_id: productId,
      quantity,
      variant_sku: variantSku,
    }),
  applyCoupon: (code: string) => apiClient.post<Cart>('/commerce/cart/coupon', { code }),
  clearCart: () => apiClient.delete<void>('/commerce/cart'),

  wishlist: () =>
    apiClient.get<{ product_ids: string[]; products: ProductSummary[] }>('/commerce/wishlist'),
  addToWishlist: (productId: string) =>
    apiClient.post<unknown>('/commerce/wishlist', { product_id: productId }),
  removeFromWishlist: (productId: string) =>
    apiClient.delete<unknown>(`/commerce/wishlist/${productId}`),

  quote: (pickup: boolean) =>
    apiClient.get<PriceBreakdown>(`/commerce/checkout/quote${query({ pickup })}`),
  /**
   * Place the order (M43).
   *
   * The one call on this client that moves money, and the only one that carries
   * an `Idempotency-Key`. Browsing, carting and wishlisting are all safely
   * repeatable; a second checkout is a second order.
   *
   * `idempotencyKey` is explicit so a caller re-sending the *same* payload can
   * reuse it and have the original order replayed. The server refuses the same
   * key with a changed payload (`IDEMPOTENCY_KEY_REUSED`), so a retry after the
   * cart or address changed must take a fresh key — omit the argument for that.
   */
  checkout: (payload: CheckoutPayload, idempotencyKey: string = newIdempotencyKey()) =>
    apiClient.postIdempotent<Order>('/commerce/checkout', payload, idempotencyKey),
  orders: () => apiClient.getPage<Paginated<OrderSummary>>('/commerce/orders'),
  order: (id: string) => apiClient.get<Order>(`/commerce/orders/${id}`),
};
