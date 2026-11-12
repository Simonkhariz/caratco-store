import type { Metadata } from 'next';
import { AdminSettings } from '@/components/admin/AdminSettings';

export const metadata: Metadata = { title: 'Store settings · Store Admin' };

export default function Page() {
  return <AdminSettings />;
}
