import type { Metadata } from 'next';
import { AdminPayments } from '@/components/admin/AdminPayments';

export const metadata: Metadata = { title: 'Payments · Store Admin' };

export default function Page() {
  return <AdminPayments />;
}
