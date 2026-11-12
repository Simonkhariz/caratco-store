import type { Metadata } from 'next';
import { AdminShipping } from '@/components/admin/AdminShipping';

export const metadata: Metadata = { title: 'Shipping queue · Store Admin' };

export default function Page() {
  return <AdminShipping />;
}
