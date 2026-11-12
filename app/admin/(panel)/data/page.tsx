import type { Metadata } from 'next';
import { AdminDataStorage } from '@/components/admin/AdminDataStorage';
import { DevModeGate } from '@/components/admin/AdminDevMode';

export const metadata: Metadata = { title: 'Data & storage · Store Admin' };

export default function Page() {
  return (
    <DevModeGate title="Data & storage">
      <AdminDataStorage />
    </DevModeGate>
  );
}
