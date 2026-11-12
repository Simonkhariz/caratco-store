import { SHOP } from '@/lib/shop';
import type { Metadata } from 'next';

export const metadata: Metadata = {
  title: `Track your order | ${SHOP.name}`,
};

export default function TrackOrderLayout({ children }: { children: React.ReactNode }) {
  return children;
}
