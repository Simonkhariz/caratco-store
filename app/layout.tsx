import { SHOP } from '@/lib/shop';
import type { Metadata } from 'next';
import localFont from 'next/font/local';
import './globals.css';
import { AuthProvider } from '@/context/AuthContext';
import { CartProvider } from '@/context/CartContext';
import { WishlistProvider } from '@/context/WishlistContext';
import { StoreShell } from '@/components/layout/StoreShell';
import { StoreDataProvider, type StoreSeed } from '@/components/layout/StoreDataProvider';
import { QueryProvider } from '@/components/layout/QueryProvider';
import { AuthModal } from '@/components/auth/AuthModal';
import { serverApiGet } from '@/lib/server-api';
import { SHARE_HEIGHT, SHARE_WIDTH, siteOrigin } from '@/lib/share-image';

// Fonts are served with the site (no requests to outside font services, nothing blocks the
// first paint); the fallback font is sized to match, so text does not jump when they arrive.
// Satoshi: ITF Free Font License (fontshare.com). Space Grotesk: SIL Open Font License.
const satoshi = localFont({
  src: [
    { path: './fonts/satoshi-400.woff2', weight: '400', style: 'normal' },
    { path: './fonts/satoshi-500.woff2', weight: '500', style: 'normal' },
    { path: './fonts/satoshi-700.woff2', weight: '700', style: 'normal' },
    { path: './fonts/satoshi-900.woff2', weight: '900', style: 'normal' },
  ],
  variable: '--font-satoshi',
  display: 'swap',
  // Four weights (~100 KB): preloading them all slowed the first paint on mobile data, so the
  // browser fetches the ones a page uses as soon as the stylesheet asks for them.
  preload: false,
});

const spaceGrotesk = localFont({
  src: './fonts/space-grotesk-latin.woff2',
  weight: '300 700',
  variable: '--font-space-grotesk',
  display: 'swap',
});

// Every page is built for its visit, with browsers told not to keep it ("no-store"), so an
// owner's change shows on the next page anyone opens. Cached pages showed old shop details for
// hours on phones: Next.js lets browsers serve a stale copy while fetching a new one. Pages
// already open follow changes through StoreLiveUpdates (components/layout/StoreShell.tsx).
export const dynamic = 'force-dynamic';

const SITE_TITLE = SHOP.title;
// The shop's colours as CSS variables on <html>: every bg-accent, bg-surface, bg-card and bg-hero
// class reads them (tailwind.config.js).
const THEME_STYLE = {
  '--accent': SHOP.theme.accent,
  '--accent-hover': SHOP.theme.accentHover,
  '--accent-ink': SHOP.theme.accentInk,
  '--surface': SHOP.theme.surface,
  '--card': SHOP.theme.card,
  '--hero': SHOP.theme.hero,
} as React.CSSProperties;
const SITE_DESCRIPTION = SHOP.description;

/**
 * A shared link to the shop shows the hero picture (WhatsApp, Facebook, X and the rest read
 * these tags). The picture's address must be a full one, hence the shop's own address as the
 * base. A product page replaces the picture with the product's (app/products/[id]/page.tsx).
 */
export async function generateMetadata(): Promise<Metadata> {
  const picture = { url: '/share/home', width: SHARE_WIDTH, height: SHARE_HEIGHT, type: 'image/jpeg', alt: SHOP.name };
  return {
    metadataBase: new URL(await siteOrigin()),
    title: SITE_TITLE,
    description: SITE_DESCRIPTION,
    openGraph: {
      type: 'website',
      siteName: SHOP.name,
      title: SITE_TITLE,
      description: SITE_DESCRIPTION,
      images: [picture],
    },
    twitter: { card: 'summary_large_image', title: SITE_TITLE, description: SITE_DESCRIPTION, images: [picture.url] },
  };
}

export default async function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  // Header categories, footer contact details and checkout options come with every page
  // instead of three requests from each visitor's browser. The version lets the page notice
  // later admin changes.
  const [facets, shipping, payments, versionData] = await Promise.all([
    serverApiGet<StoreSeed['facets']>('/catalog/facets'),
    serverApiGet<StoreSeed['shipping']>('/checkout/shipping-zones'),
    serverApiGet<StoreSeed['payments']>('/checkout/payment-methods'),
    serverApiGet<{ version: string }>('/storefront/version'),
  ]);
  const version = versionData?.version ?? null;

  return (
    // suppressHydrationWarning: the admin's dark mode is set on <html> before React loads
    // (app/admin/layout.tsx), so its class differs from the server's on purpose. Only this
    // element's own attributes are exempt; the rest of the page is still checked.
    <html lang="en" className={`${satoshi.variable} ${spaceGrotesk.variable}`} style={THEME_STYLE} suppressHydrationWarning>
      <body className="min-h-screen flex flex-col bg-white text-black antialiased selection:bg-accent selection:text-accent-ink">
        <QueryProvider>
          <StoreDataProvider seed={{ facets, shipping, payments, version }}>
            <AuthProvider>
              <CartProvider>
                <WishlistProvider>
                  <StoreShell>{children}</StoreShell>
                  <AuthModal />
                </WishlistProvider>
              </CartProvider>
            </AuthProvider>
          </StoreDataProvider>
        </QueryProvider>
      </body>
    </html>
  );
}
