import type { Metadata } from 'next';
import { AdminDevSwitches } from '@/components/admin/AdminDevMode';

export const metadata: Metadata = { title: 'Tech switches · Store Admin' };

export default function Page() {
  return <AdminDevSwitches />;
}
