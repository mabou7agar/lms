import { describe, expect, it } from "vitest";
import { validateEnv, assertEnv } from "@/lib/config/env";

const base = {
  NEXT_PUBLIC_API_BASE_URL: "https://api.example.com/api/v1",
  API_INTERNAL_URL: "http://api.internal/api/v1",
};

describe("env validation", () => {
  it("passes a complete server environment", () => {
    expect(validateEnv(base, { requireServer: true })).toEqual([]);
    expect(() => assertEnv(base)).not.toThrow();
  });

  it("flags a missing required public variable", () => {
    const errs = validateEnv({ ...base, NEXT_PUBLIC_API_BASE_URL: "" }, { requireServer: true });
    expect(errs.join(" ")).toContain("NEXT_PUBLIC_API_BASE_URL");
  });

  it("flags a missing required server variable on the server", () => {
    const errs = validateEnv({ NEXT_PUBLIC_API_BASE_URL: base.NEXT_PUBLIC_API_BASE_URL }, { requireServer: true });
    expect(errs.join(" ")).toContain("API_INTERNAL_URL");
  });

  it("does not require server variables on the client", () => {
    const errs = validateEnv({ NEXT_PUBLIC_API_BASE_URL: base.NEXT_PUBLIC_API_BASE_URL }, { requireServer: false });
    expect(errs).toEqual([]);
  });

  it("rejects a private value exposed through NEXT_PUBLIC_", () => {
    const errs = validateEnv({ ...base, NEXT_PUBLIC_STRIPE_SECRET: "sk_live_x" }, { requireServer: true });
    expect(errs.join(" ")).toContain("NEXT_PUBLIC_STRIPE_SECRET");
    expect(() => assertEnv({ ...base, NEXT_PUBLIC_DB_PASSWORD: "x" })).toThrow(/NEXT_PUBLIC_DB_PASSWORD/);
  });

  it("allows a legitimately public key (no false positive)", () => {
    const errs = validateEnv({ ...base, NEXT_PUBLIC_STRIPE_PUBLISHABLE_KEY: "pk_live_x" }, { requireServer: true });
    expect(errs).toEqual([]);
  });
});
