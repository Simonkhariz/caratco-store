import type { Metadata } from 'next';
import { AdminNotifications } from '@/components/admin/AdminNotifications';

export const metadata: Metadata = { title: 'Email outbox · Store Admin' };

export default function Page() {
  return <AdminNotifications />;
}
