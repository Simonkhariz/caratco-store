import type { Metadata } from 'next';
import { AdminMarketing } from '@/components/admin/AdminMarketing';

export const metadata: Metadata = { title: 'Newsletter · Store Admin' };

export default function Page() {
  return <AdminMarketing />;
}
