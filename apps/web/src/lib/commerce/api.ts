import { api } from "@/lib/api/client";
import type { ApiSuccess, Paginated } from "@/types/api";

export type Price = {
  currency: string;
  amount_minor: number;
  sale_amount_minor: number | null;
  on_sale: boolean;
  effective_minor: number;
};
/** A course granted by a product, as returned inside the product payload. */
export type ProductCourse = { id: string; title: string; slug: string };

/**
 * A purchasable product: a single course or a bundle of them. The commercial policy fields mirror
 * the admin-controlled settings on the product — see the API ProductResource.
 */
export type Product = {
  id: string;
  type: "course" | "bundle";
  title: string;
  slug: string;
  description: string | null;
  image?: string | null;
  prices: Price[];
  /** Present from the commercial-policy wave; older payloads may omit these. */
  audience?: "individual" | "company" | "both" | null;
  courses?: ProductCourse[];
  access?: {
    duration_type: "lifetime" | "fixed_days" | "fixed_months" | "fixed_years" | "fixed_date" | null;
    duration_value: number | null;
    ends_at: string | null;
  };
  certificate?: {
    enabled: boolean;
    expiry_type: "none" | "fixed_days" | "fixed_months" | "fixed_years" | "fixed_date" | null;
    expiry_value: number | null;
  };
  seats?: {
    mode: "not_applicable" | "fixed" | "buyer_selects" | "unlimited" | null;
    default_count: number | null;
    reassignment_policy: string | null;
    reassignment_progress_threshold: number | null;
    employee_access_expires_with_purchase: boolean;
  };
};
export type CartItem = { id: string; product_id: string; title: string; unit_amount_minor: number };
export type Cart = {
  id: string;
  currency: string;
  coupon: string | null;
  items: CartItem[];
  subtotal_minor: number;
  discount_minor: number;
  // Tax is computed server-side (VAT/GST). Optional so pre-tax carts stay backward-compatible.
  tax_minor?: number;
  total_minor: number;
};
export type Order = {
  id: string;
  status: string;
  currency: string;
  subtotal_minor: number;
  discount_minor: number;
  // Tax component of the order total (VAT/GST), computed server-side.
  tax_minor: number;
  total_minor: number;
  placed_at: string | null;
  paid_at: string | null;
  fulfilled_at: string | null;
  items?: { title: string; unit_amount_minor: number }[];
  invoice?: { number: string; status: string } | null;
};
export type Contract = {
  id: string;
  status: string;
  accepted_at: string | null;
  template?: { key: string; version: number; title: string; body: string };
  order_id?: string | null;
};
export type CheckoutResult = {
  order: Order;
  contract_id: string | null;
  // client_secret is a per-intent token for the browser SDK — NOT a secret API key.
  // redirect_url is set when the gateway is hosted/redirect-based (MENA HPP) rather than inline.
  payment: {
    provider_reference: string;
    client_secret: string | null;
    status: string;
    redirect_url?: string | null;
  };
};

// A single ledger movement against an order (charge, capture, refund, …).
export type OrderTransaction = {
  id: string;
  type: string;
  status: string;
  amount_minor: number;
  provider_reference: string | null;
  created_at: string | null;
};
// Invoice with the full tax breakdown, as returned on the order detail endpoint.
export type OrderInvoice = {
  number: string;
  status: string;
  subtotal_minor: number;
  discount_minor: number;
  tax_minor: number;
  total_minor: number;
};
// Order detail extends the list shape with the richer invoice and the transaction ledger.
export type OrderDetail = Omit<Order, "invoice"> & {
  invoice?: OrderInvoice | null;
  transactions?: OrderTransaction[];
};

// Result of validating a coupon code against the current cart/context.
export type CouponValidation = {
  code: string;
  valid: boolean;
  discount_minor: number;
  currency?: string;
  reason?: string | null;
};

export const getProducts = (page = 1) => api.get<Paginated<Product>>(`products?page=${page}`, { auth: false });

/** Purchasable bundles only (a bundle grants several courses in one purchase). */
export const getBundles = (page = 1) =>
  api.get<Paginated<Product>>(`products?type=bundle&page=${page}`, { auth: false });

/** A single purchasable product (course product or bundle) by public id. */
export const getProduct = (publicId: string) =>
  api.data<Product>(`products/${publicId}`, { auth: false });
export const getCart = () => api.data<Cart>("cart");
export const addToCart = (body: { product: string; coupon_code?: string }) =>
  api.post<ApiSuccess<Cart>>("cart", body);
export const removeCartItem = (productPublicId: string) =>
  api.del<ApiSuccess<Cart>>(`cart/items/${productPublicId}`);
export const clearCart = () => api.del("cart");
export const checkout = () => api.post<ApiSuccess<CheckoutResult>>("checkout");
export const getOrders = (page = 1) => api.get<Paginated<Order>>(`orders?page=${page}`);
export const getOrder = (id: string) => api.data<OrderDetail>(`orders/${id}`);
export const getContracts = () => api.data<Contract[]>("contracts");
export const acceptContract = (contractId: string) => api.post(`contracts/${contractId}/accept`);
export const validateCoupon = (code: string) =>
  api.data<CouponValidation>("coupons/validate", { method: "POST", body: { code } });
