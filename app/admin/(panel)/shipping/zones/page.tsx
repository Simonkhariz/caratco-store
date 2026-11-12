import type { Metadata } from 'next';
import { AdminShippingZones } from '@/components/admin/AdminShippingZones';

export const metadata: Metadata = { title: 'Delivery zones · Store Admin' };

export default function Page() {
  return <AdminShippingZones />;
}
