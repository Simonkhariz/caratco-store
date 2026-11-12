import type { Metadata } from 'next';
import { AdminInventory } from '@/components/admin/AdminInventory';

export const metadata: Metadata = { title: 'Inventory · Store Admin' };

export default function Page() {
  return <AdminInventory />;
}
