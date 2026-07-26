/** Types for the Restaurant, Vendor & Food Business marketplace. */

export type VendorType = 'restaurant' | 'market_vendor' | 'home_kitchen' | 'cloud_kitchen';
export type FulfilmentType = 'delivery' | 'pickup';
export type OrderStatus =
  | 'pending'
  | 'confirmed'
  | 'preparing'
  | 'ready'
  | 'dispatched'
  | 'delivered'
  | 'cancelled';

export interface GeoPoint {
  latitude: number;
  longitude: number;
}

export interface VendorSummary {
  id: string;
  name: string;
  slug: string;
  type: VendorType;
  category: string;
  status: string;
  rating_average: number;
  rating_count: number;
  featured: boolean;
  location: GeoPoint | null;
  primary_image: string | null;
}

export interface Vendor extends VendorSummary {
  description: string | null;
  contact: { phone: string; email: string | null; whatsapp: string | null };
  address: { line: string; city: string; state: string; location: GeoPoint | null };
  images: string[];
  delivery_zones: { name: string; fee_minor: number; radius_km: number }[];
}

export interface MenuVariant {
  name: string;
  price_delta_minor: number;
}

export interface MenuItem {
  id: string;
  vendor_id: string;
  name: string;
  description: string | null;
  base_price_minor: number;
  currency: string;
  variants: MenuVariant[];
  available: boolean;
  orderable: boolean;
  images: string[];
  tags: string[];
  featured: boolean;
  promotion: { type: string; value: number } | null;
  stock: number | null;
}

export interface CartLine {
  menu_item_id: string;
  name: string;
  variant_name: string | null;
  unit_price_minor: number;
  quantity: number;
  line_total_minor: number;
}

export interface Cart {
  vendor_id: string | null;
  currency: string;
  items: CartLine[];
  subtotal_minor: number;
}

export interface OrderSummary {
  id: string;
  reference: string;
  vendor_id: string;
  status: OrderStatus;
  fulfilment: FulfilmentType;
  total_minor: number;
  currency: string;
  placed_at: string;
}

export interface Order extends OrderSummary {
  lines: CartLine[];
  subtotal_minor: number;
  delivery_fee_minor: number;
  status_history: { status: string; at: string }[];
}

export interface SalesSummary {
  total_orders: number;
  delivered_orders: number;
  pending_orders: number;
  revenue_minor: number;
  currency: string;
}

export interface Paginated<T> {
  data: T[];
  meta: { page: number; per_page: number; total: number };
}

/** Format minor units (kobo) as Naira. */
export function formatMoney(minor: number, currency = 'NGN'): string {
  const symbol = currency === 'NGN' ? '₦' : '';
  return `${symbol}${(minor / 100).toLocaleString(undefined, { minimumFractionDigits: 0 })}`;
}
