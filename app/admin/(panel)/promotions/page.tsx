import type { Metadata } from 'next';
import { AdminPromotions } from '@/components/admin/AdminPromotions';

export const metadata: Metadata = { title: 'Promo codes · Store Admin' };

export default function Page() {
  return <AdminPromotions />;
}
