import type { Metadata } from 'next';
import { OrderManagement } from '@/components/admin/OrderManagement';

export const metadata: Metadata = { title: 'Orders · Store Admin' };

export default function Page() {
  return <OrderManagement />;
}
