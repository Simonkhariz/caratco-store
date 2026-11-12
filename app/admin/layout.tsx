import type { Metadata } from 'next';
import { AdminUiProvider } from '@/components/admin/ui';
import { AdminThemeClass } from '@/lib/admin-theme';
import { ADMIN_THEME_BOOT_SCRIPT } from '@/lib/admin-theme-boot';
import './admin-dark.css';

export const metadata: Metadata = {
  title: 'Store Admin',
  description: 'Store administration: orders, catalogue, inventory, payments and fulfilment.',
  robots: { index: false, follow: false },
};

export default function AdminLayout({ children }: { children: React.ReactNode }) {
  return (
    <div className="min-h-screen w-full bg-[#f8f8f8] font-sans text-[#1f2937] antialiased selection:bg-[#1f2937] selection:text-white">
      {/* Dark before the first paint (no light flash), then kept in step by AdminThemeClass. */}
      <script dangerouslySetInnerHTML={{ __html: ADMIN_THEME_BOOT_SCRIPT }} />
      <AdminThemeClass />
      <AdminUiProvider>{children}</AdminUiProvider>
    </div>
  );
}
