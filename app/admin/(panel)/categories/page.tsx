import type { Metadata } from 'next';
import { AdminCategories } from '@/components/admin/AdminCategories';

export const metadata: Metadata = { title: 'Categories · Store Admin' };

export default function Page() {
  return <AdminCategories />;
}
