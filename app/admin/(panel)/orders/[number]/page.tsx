import type { Metadata } from 'next';
import { AdminOrderDetail } from '@/components/admin/AdminOrderDetail';

type Props = { params: Promise<{ number: string }> };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { number } = await params;
  return { title: `${decodeURIComponent(number)} · Store Admin` };
}

export default async function Page({ params }: Props) {
  const { number } = await params;
  return <AdminOrderDetail orderNumber={decodeURIComponent(number)} />;
}
