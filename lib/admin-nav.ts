/**
 * Admin navigation: one real route per screen. Used by the sidebar and the header breadcrumb.
 * (Old /admin?tab= links are redirected in next.config.mjs.)
 */

export interface AdminNavLink {
  href: string;
  label: string;
}

export interface AdminNavItem extends AdminNavLink {
  /** File name (without .svg) in public/admin. */
  icon: string;
  children?: AdminNavLink[];
}

export interface AdminNavSection {
  title: string;
  items: AdminNavItem[];
  /** Shown only while dev mode is on (Store settings). */
  devOnly?: boolean;
}

export const ADMIN_NAV: AdminNavSection[] = [
  {
    title: 'Operations',
    items: [
      { href: '/admin', label: 'Overview', icon: 'sb-dashboard' },
      {
        href: '/admin/orders',
        label: 'Orders',
        icon: 'sb-ticket',
        children: [
          { href: '/admin/orders', label: 'All orders' },
          { href: '/admin/shipping', label: 'Shipping queue' },
          { href: '/admin/shipping/zones', label: 'Delivery zones' },
        ],
      },
      {
        href: '/admin/products',
        label: 'Catalogue & stock',
        icon: 'sb-file',
        children: [
          { href: '/admin/products', label: 'Products' },
          { href: '/admin/categories', label: 'Categories' },
          { href: '/admin/inventory', label: 'Inventory' },
        ],
      },
      { href: '/admin/customers', label: 'Customers', icon: 'user-account' },
      {
        href: '/admin/payments',
        label: 'Payments',
        icon: 'zap',
        children: [
          { href: '/admin/payments', label: 'Transactions' },
          { href: '/admin/refunds', label: 'Refunds' },
        ],
      },
    ],
  },
  {
    title: 'Growth',
    items: [
      {
        href: '/admin/promotions',
        label: 'Marketing',
        icon: 'target',
        children: [
          { href: '/admin/promotions', label: 'Promo codes' },
          { href: '/admin/marketing', label: 'Newsletter' },
          { href: '/admin/storefront', label: 'Banners & reviews' },
        ],
      },
      { href: '/admin/analytics', label: 'Analytics', icon: 'analytics' },
    ],
  },
  {
    title: 'System',
    items: [
      { href: '/admin/notifications', label: 'Email outbox', icon: 'bell' },
      { href: '/admin/audit-logs', label: 'Audit log', icon: 'clock-01' },
      { href: '/admin/settings', label: 'Store settings', icon: 'sb-settings' },
      { href: '/admin/account', label: 'My account', icon: 'user-group' },
    ],
  },
  {
    title: 'Dev mode',
    devOnly: true,
    items: [
      { href: '/admin/dev', label: 'Tech switches', icon: 'zap' },
      { href: '/admin/data', label: 'Data & storage', icon: 'database' },
      { href: '/admin/system', label: 'System status', icon: 'headset' },
    ],
  },
];

/** The most specific nav entry for a path, for the breadcrumb and active state. */
export function findAdminNav(pathname: string): { section: string; label: string; href: string } | null {
  let best: { section: string; label: string; href: string } | null = null;
  for (const section of ADMIN_NAV) {
    for (const item of section.items) {
      for (const link of [item, ...(item.children ?? [])]) {
        const matches = link.href === '/admin' ? pathname === '/admin' : pathname === link.href || pathname.startsWith(`${link.href}/`);
        // ">=": a group shares its address with its first page ("Catalogue & stock" and
        // "Products"); the page, listed after it, is the one to name.
        if (matches && (!best || link.href.length >= best.href.length)) {
          best = { section: section.title, label: link.label, href: link.href };
        }
      }
    }
  }
  return best;
}

export function isActiveAdminLink(pathname: string, href: string): boolean {
  return findAdminNav(pathname)?.href === href;
}
