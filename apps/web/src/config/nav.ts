import type { LucideIcon } from "lucide-react";
import {
  LayoutDashboard, GraduationCap, Award, Bell, User, Building2, Building, Headset,
  MessageCircleQuestion, Contact, Users, BarChart3, FileText, LayoutGrid, PlayCircle, ShoppingCart, FileSignature,
  Presentation, LineChart, Film, Receipt, CreditCard, Armchair, FolderTree, Upload, BookOpenCheck,
  ShieldCheck, KeyRound, Palette, Ticket, ReceiptText,
} from "lucide-react";

/**
 * labelKey is a dot-path into the i18n dictionary (resolved via useI18n().t). `flag` optionally gates
 * the entry behind a feature flag: the item shows unless the flag is explicitly OFF (default-on — an
 * unknown/unreachable flag keeps the item visible). The underlying route is never removed.
 *
 * `permission` names the permission the destination's API actually requires. It exists to stop the
 * sidebar advertising a page that will only ever refuse the person clicking it — a company owner was
 * being shown Brand & Domains and SSO, both of which need a platform permission org owners do not
 * hold. Like `flag` it is presentation only: the route still exists, the API still authorizes, and
 * a session payload that does not disclose permissions keeps showing the item rather than hiding
 * the whole portal on a stale contract.
 */
export type NavItem = {
  labelKey: string;
  href: string;
  icon: LucideIcon;
  flag?: string;
  permission?: string;
};

/** The platform permission the white-label surfaces authorize against. */
const MANAGE_USERS = "identity.users.manage";

export const learningNav: NavItem[] = [
  { labelKey: "nav.dashboard", href: "/dashboard", icon: LayoutDashboard },
  { labelKey: "nav.myLearning", href: "/my-learning", icon: GraduationCap },
  { labelKey: "nav.continueLearning", href: "/continue-learning", icon: PlayCircle },
  { labelKey: "nav.certificates", href: "/certificates", icon: Award },
];

// Account is managed via Profile (details) and Notifications (preferences). There is no separate
// "Settings" domain, so no Settings nav item is exposed (avoids a dead/stub destination).
export const accountNav: NavItem[] = [
  { labelKey: "nav.profile", href: "/profile", icon: User },
  { labelKey: "nav.security", href: "/security", icon: ShieldCheck },
  { labelKey: "nav.notifications", href: "/notifications", icon: Bell },
];

export const commerceNav: NavItem[] = [
  { labelKey: "nav.orders", href: "/orders", icon: ShoppingCart },
  { labelKey: "nav.billing", href: "/billing", icon: Receipt },
  { labelKey: "nav.subscriptions", href: "/subscriptions", icon: CreditCard },
  { labelKey: "nav.contracts", href: "/contracts", icon: FileSignature },
];

// Instructor Portal: ownership-scoped teaching surface (dashboard, courses, students) plus the
// shared profile page.
// /teach/earnings and /teach/sessions exist as dormant routes (they render ComingSoon) and are
// deliberately absent here: a sidebar entry that leads to "not built yet" is worse than no entry.
export const instructorNav: NavItem[] = [
  { labelKey: "nav.teachDashboard", href: "/teach", icon: LayoutDashboard },
  { labelKey: "nav.teachCourses", href: "/teach/courses", icon: Presentation },
  { labelKey: "nav.teachMedia", href: "/teach/media", icon: Film },
  { labelKey: "nav.teachStudents", href: "/teach/students", icon: Users },
  { labelKey: "nav.teachQuestions", href: "/teach/questions", icon: MessageCircleQuestion },
  { labelKey: "nav.profile", href: "/profile", icon: User },
];

// Commerce admin console: platform-wide commerce operations, gated by AdminGuard. Surfaced through a
// dedicated admin AppShell (see (commerce)/admin/layout.tsx) with its own sidebar.
export const adminNav: NavItem[] = [
  { labelKey: "nav.adminAnalytics", href: "/admin/analytics", icon: BarChart3 },
  { labelKey: "nav.adminOrders", href: "/admin/orders", icon: ShoppingCart },
  { labelKey: "nav.adminCoupons", href: "/admin/coupons", icon: Ticket },
  { labelKey: "nav.adminCreditNotes", href: "/admin/credit-notes", icon: ReceiptText },
];

export const organizationNav: NavItem[] = [
  { labelKey: "nav.organization", href: "/org", icon: Building2 },
  { labelKey: "nav.organizations", href: "/org/organizations", icon: Building },
  { labelKey: "nav.consulting", href: "/org/consulting", icon: Headset },
];

// Enterprise Manager Portal: self-serve org operation, gated to org manager/admin. Separate from the
// marketing enterprise-lead form and from the broader organization surface.
export const managerNav: NavItem[] = [
  { labelKey: "nav.managerPortal", href: "/manager", icon: LayoutDashboard },
  { labelKey: "nav.managerMembers", href: "/manager/members", icon: Users },
  { labelKey: "nav.managerDepartments", href: "/manager/departments", icon: FolderTree },
  { labelKey: "nav.managerTraining", href: "/manager/training", icon: BookOpenCheck },
  { labelKey: "nav.managerSeats", href: "/manager/seats", icon: Armchair },
  { labelKey: "nav.managerImport", href: "/manager/import", icon: Upload },
  // Both need `identity.users.manage`, which an organization owner does not get from owning an
  // organization. Shown to the admins who can actually use them; everyone else keeps the route
  // (and its graceful refusal) but is not invited to it.
  { labelKey: "nav.managerSso", href: "/manager/sso", icon: KeyRound, permission: MANAGE_USERS },
  { labelKey: "nav.managerBrand", href: "/manager/branding", icon: Palette, permission: MANAGE_USERS },
];

export const crmNav: NavItem[] = [
  { labelKey: "nav.crm", href: "/crm", icon: LayoutDashboard },
  { labelKey: "nav.leads", href: "/crm/leads", icon: Contact },
  { labelKey: "nav.consulting", href: "/crm/consulting", icon: Headset },
  { labelKey: "nav.accounts", href: "/crm/accounts", icon: Users },
];

export const analyticsNav: NavItem[] = [
  { labelKey: "nav.analytics", href: "/analytics", icon: BarChart3 },
  { labelKey: "nav.reports", href: "/reports", icon: FileText },
  { labelKey: "nav.reportsInsights", href: "/reports/insights", icon: LineChart, flag: "reports" },
  { labelKey: "nav.dashboards", href: "/dashboards", icon: LayoutGrid },
];
