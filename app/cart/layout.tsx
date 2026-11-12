import { SHOP } from '@/lib/shop';
import type { Metadata } from 'next';

export const metadata: Metadata = {
  title: `Your cart | ${SHOP.name}`,
  robots: { index: false, follow: false },
};

export default function CartLayout({ children }: { children: React.ReactNode }) {
  return children;
}
