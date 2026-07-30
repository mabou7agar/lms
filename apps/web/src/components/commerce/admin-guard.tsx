"use client";

import type { ReactNode } from "react";
import { ShieldAlert } from "lucide-react";
import { RequireAuth } from "@/lib/auth/guards";
import { useAuth } from "@/lib/auth/auth-context";
import { useI18n } from "@/lib/i18n/i18n-context";
import { EmptyState } from "@/components/states/empty-state";

/**
 * Roles permitted to reach the commerce admin console. Client-side gating is a UX affordance only —
 * every admin endpoint is independently authorized by permission middleware on the server, which is
 * the real security boundary.
 */
const ADMIN_ROLES = ["admin", "super_admin", "commerce_manager"] as const;

function RoleCheck({ children }: { children: ReactNode }) {
  const { t } = useI18n();
  const { user } = useAuth();
  const roles: string[] = user?.roles ?? [];
  const allowed = roles.some((role) => (ADMIN_ROLES as readonly string[]).includes(role));

  if (!allowed) {
    return <EmptyState icon={<ShieldAlert className="size-8" />} title={t("commerce.admin.noAccess")} />;
  }
  return <>{children}</>;
}

/**
 * Guards a commerce admin surface: first requires an authenticated session ({@link RequireAuth}),
 * then narrows to the admin roles. Non-admins see a "no access" state rather than the data.
 */
export function AdminGuard({ children }: { children: ReactNode }) {
  return (
    <RequireAuth>
      <RoleCheck>{children}</RoleCheck>
    </RequireAuth>
  );
}
