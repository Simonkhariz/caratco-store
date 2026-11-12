import type { Metadata } from 'next';
import { AdminAccount } from '@/components/admin/AdminAccount';

export const metadata: Metadata = { title: 'My account · Store Admin' };

export default function Page() {
  return <AdminAccount />;
}
