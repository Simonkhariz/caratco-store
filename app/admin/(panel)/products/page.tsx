import type { Metadata } from 'next';
import { ProductManagement } from '@/components/admin/ProductManagement';

export const metadata: Metadata = { title: 'Products · Store Admin' };

export default function Page() {
  return <ProductManagement />;
}
