import { apiClient } from '@lib/apiClient';
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
  checkout: (payload: CheckoutPayload) => apiClient.post<Order>('/commerce/checkout', payload),
  orders: () => apiClient.getPage<Paginated<OrderSummary>>('/commerce/orders'),
  order: (id: string) => apiClient.get<Order>(`/commerce/orders/${id}`),
};
