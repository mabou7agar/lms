import type { LucideIcon } from "lucide-react";
import {
  LayoutDashboard, GraduationCap, Award, Bell, User, Building2, Building, Headset,
  Contact, Users, BarChart3, FileText, LayoutGrid, PlayCircle, ShoppingCart, FileSignature,
  Presentation, LineChart, Film,
} from "lucide-react";

/**
 * labelKey is a dot-path into the i18n dictionary (resolved via useI18n().t). `flag` optionally gates
 * the entry behind a feature flag: the item shows unless the flag is explicitly OFF (default-on — an
 * unknown/unreachable flag keeps the item visible). The underlying route is never removed.
 */
export type NavItem = { labelKey: string; href: string; icon: LucideIcon; flag?: string };

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
  { labelKey: "nav.notifications", href: "/notifications", icon: Bell },
];

export const commerceNav: NavItem[] = [
  { labelKey: "nav.orders", href: "/orders", icon: ShoppingCart },
  { labelKey: "nav.contracts", href: "/contracts", icon: FileSignature },
];

// Instructor Portal: ownership-scoped teaching surface (dashboard, courses, students) plus the
// shared profile page.
export const instructorNav: NavItem[] = [
  { labelKey: "nav.teachDashboard", href: "/teach", icon: LayoutDashboard },
  { labelKey: "nav.teachCourses", href: "/teach/courses", icon: Presentation },
  { labelKey: "nav.teachMedia", href: "/teach/media", icon: Film },
  { labelKey: "nav.teachStudents", href: "/teach/students", icon: Users },
  { labelKey: "nav.profile", href: "/profile", icon: User },
];

export const organizationNav: NavItem[] = [
  { labelKey: "nav.organization", href: "/org", icon: Building2 },
  { labelKey: "nav.organizations", href: "/org/organizations", icon: Building },
  { labelKey: "nav.consulting", href: "/org/consulting", icon: Headset },
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
