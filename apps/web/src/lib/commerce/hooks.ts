"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  acceptContract,
  addToCart,
  checkout,
  clearCart,
  getCart,
  getBundles,
  getContracts,
  getOrder,
  getOrders,
  getProduct,
  getProducts,
  removeCartItem,
  validateCoupon,
} from "./api";

export const useProducts = (page: number) =>
  useQuery({ queryKey: ["products", page], queryFn: () => getProducts(page) });
export const useBundles = (page: number) =>
  useQuery({ queryKey: ["bundles", page], queryFn: () => getBundles(page) });
export const useProduct = (publicId: string) =>
  useQuery({ queryKey: ["product", publicId], queryFn: () => getProduct(publicId), enabled: publicId.length > 0 });
export const useCart = () => useQuery({ queryKey: ["cart"], queryFn: getCart });
export const useOrders = (page: number) => useQuery({ queryKey: ["orders", page], queryFn: () => getOrders(page) });
export const useOrder = (id: string) =>
  useQuery({ queryKey: ["order", id], queryFn: () => getOrder(id), enabled: id.length > 0 });
export const useContracts = () => useQuery({ queryKey: ["contracts"], queryFn: getContracts });

export function useAddToCart() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: { product: string; coupon_code?: string }) => addToCart(body),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["cart"] }),
  });
}
export function useRemoveCartItem() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (productPublicId: string) => removeCartItem(productPublicId),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["cart"] }),
  });
}
export function useClearCart() {
  const qc = useQueryClient();
  return useMutation({ mutationFn: clearCart, onSuccess: () => qc.invalidateQueries({ queryKey: ["cart"] }) });
}
export function useCheckout() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: checkout,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["cart"] });
      qc.invalidateQueries({ queryKey: ["orders"] });
    },
  });
}
export function useAcceptContract() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => acceptContract(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["contracts"] });
      qc.invalidateQueries({ queryKey: ["orders"] });
    },
  });
}
export function useValidateCoupon() {
  return useMutation({ mutationFn: (code: string) => validateCoupon(code) });
}
