/** @type {import('next').NextConfig} */

// Server-only: where the PHP API runs. The browser never talks to it directly; it calls
// same-origin /api/v1/* and /uploads/*, which Next.js proxies here. Same origin means the
// API's HTTP-only SameSite session cookies just work and no token is exposed to JavaScript.
const BACKEND_ORIGIN = (process.env.BACKEND_ORIGIN || 'http://127.0.0.1:8000').replace(/\/$/, '');

// Links from the old single-page admin (/admin?tab=orders) go to the real routes.
const LEGACY_ADMIN_TABS = {
  orders: 'orders', shipping: 'shipping', products: 'products', categories: 'categories',
  inventory: 'inventory', customers: 'customers', payments: 'payments', refunds: 'refunds',
  promotions: 'promotions', marketing: 'marketing', storefront: 'storefront', analytics: 'analytics',
  notifications: 'notifications', 'audit-logs': 'audit-logs', settings: 'settings',
  'system-status': 'system', account: 'account',
};

const nextConfig = {
  // deploy/cpanel/build.ps1: a self-contained build to upload to shared hosting, where building
  // on the server usually runs out of memory. Normal builds stay as they are.
  ...(process.env.SHOPCO_STANDALONE === 'true' ? { output: 'standalone' } : {}),
  reactStrictMode: true,
  experimental: {
    // Calls forwarded to the API (/api/v1) give up after 30 s by default. Some admin actions
    // (a newsletter to many subscribers, reports) can take longer on a slow host; cutting them
    // off shows the admin an error while the server carries on. 120 s matches PHP's limit.
    proxyTimeout: 120_000,
  },
  async redirects() {
    return Object.entries(LEGACY_ADMIN_TABS).map(([tab, route]) => ({
      source: '/admin',
      has: [{ type: 'query', key: 'tab', value: tab }],
      destination: `/admin/${route}`,
      permanent: false,
    }));
  },
  async rewrites() {
    return [
      { source: '/api/v1/:path*', destination: `${BACKEND_ORIGIN}/api/v1/:path*` },
      { source: '/uploads/:path*', destination: `${BACKEND_ORIGIN}/uploads/:path*` },
    ];
  },
  // Only the shop's own pictures (/images, /uploads) are resized: no outside hosts are allowed,
  // so /_next/image cannot be used to fetch and resize images from other sites. Pictures linked
  // from elsewhere are shown as they are (components/ui/StoreImage.tsx).
  // WebP only: AVIF files are about 20% smaller, but encoding them is so slow that in the load
  // test 200 first-time picture sizes at once all took over 30 s (docs/LOAD_TEST_RESULTS.md).
  images: {
    formats: ['image/webp'],
    minimumCacheTTL: 86400,
    // deploy/cpanel/build.ps1 -NoImageResizing, for a host that can't run the picture library.
    ...(process.env.SHOPCO_NO_IMAGE_RESIZING === 'true' ? { unoptimized: true } : {}),
  },
};

export default nextConfig;
