import type { Metadata } from 'next';
import { AdminStorefront } from '@/components/admin/AdminStorefront';

export const metadata: Metadata = { title: 'Banners & reviews · Store Admin' };

export default function Page() {
  return <AdminStorefront />;
}
