import { useSyncExternalStore } from "react";

const emptySubscribe = () => () => {};

/**
 * Returns `false` during SSR and the initial hydration render, then `true` once mounted on the
 * client. Lets a component read client-only values (cookies, `matchMedia`, feature detection)
 * AFTER hydration without a `setState`-in-effect and without a hydration mismatch — `getServerSnapshot`
 * pins the server/first-render value, and `useSyncExternalStore` re-renders once on the client.
 */
export function useHydrated(): boolean {
  return useSyncExternalStore(
    emptySubscribe,
    () => true,
    () => false,
  );
}
