import type { Metadata } from 'next';
import { AdminRefunds } from '@/components/admin/AdminRefunds';

export const metadata: Metadata = { title: 'Refunds · Store Admin' };

export default function Page() {
  return <AdminRefunds />;
}
