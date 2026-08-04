/** Types for the Marketplace, Grocery & Commerce platform. */

export type ProductKind = 'grocery' | 'general';
export type GroceryDepartment =
  | 'produce'
  | 'pantry'
  | 'beverages'
  | 'frozen'
  | 'household'
  | 'other';
export type ProductStatus = 'draft' | 'pending' | 'published' | 'rejected';
export type CommerceOrderStatus =
  | 'pending'
  | 'paid'
  | 'processing'
  | 'shipped'
  | 'delivered'
  | 'cancelled'
  | 'returned';

export interface Paginated<T> {
  data: T[];
  meta: { page: number; per_page: number; total: number };
}

export interface ProductVariant {
  sku: string;
  name: string;
  price_delta_minor: number;
  barcode: string | null;
}

export interface ProductSummary {
  id: string;
  store_id: string;
  name: string;
  slug: string;
  kind: ProductKind;
  department: GroceryDepartment | null;
  base_price_minor: number;
  currency: string;
  primary_image: string | null;
  status: ProductStatus;
  featured: boolean;
  rating_average: number;
  rating_count: number;
}

export interface Product extends ProductSummary {
  category_id: string | null;
  description: string | null;
  description_ai_generated: boolean;
  brand: string | null;
  barcode: string | null;
  variants: ProductVariant[];
  images: string[];
  tags: string[];
  related?: ProductSummary[];
}

export interface CartItem {
  product_id: string;
  store_id: string;
  name: string;
  variant_sku: string | null;
  unit_price_minor: number;
  quantity: number;
  line_total_minor: number;
}

export interface Cart {
  currency: string;
  coupon_code: string | null;
  items: CartItem[];
  item_count: number;
  subtotal_minor: number;
}

export interface PriceBreakdown {
  currency: string;
  subtotal_minor: number;
  discount_minor: number;
  tax_minor: number;
  shipping_minor: number;
  total_minor: number;
}

export interface OrderSummary {
  id: string;
  reference: string;
  status: CommerceOrderStatus;
  total_minor: number;
  currency: string;
  pickup: boolean;
  placed_at: string;
}

export interface Order extends OrderSummary {
  customer_user_id: string;
  lines: CartItem[];
  subtotal_minor: number;
  discount_minor: number;
  tax_minor: number;
  shipping_minor: number;
  coupon_code: string | null;
  note: string | null;
}

export interface Promotion {
  id: string;
  name: string;
  type: 'percentage' | 'fixed';
  value: number;
  flash_sale: boolean;
  ends_at: string | null;
}

export interface Recommendation {
  blurb: string;
  products: ProductSummary[];
}

export interface Category {
  id: string;
  name: string;
  slug: string;
  kind: ProductKind;
  department: GroceryDepartment | null;
}

/** Format integer minor units (kobo) as a currency string, e.g. ₦1,900.00. */
export function formatMoney(minor: number, currency = 'NGN'): string {
  const symbol = currency === 'NGN' ? '₦' : `${currency} `;
  return `${symbol}${(minor / 100).toLocaleString('en-NG', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`;
}

export const DEPARTMENTS: { value: GroceryDepartment; label: string }[] = [
  { value: 'produce', label: 'Fresh Produce' },
  { value: 'pantry', label: 'Pantry' },
  { value: 'beverages', label: 'Beverages' },
  { value: 'frozen', label: 'Frozen' },
  { value: 'household', label: 'Household' },
  { value: 'other', label: 'Other' },
];
