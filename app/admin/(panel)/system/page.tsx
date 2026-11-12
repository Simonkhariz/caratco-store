import type { Metadata } from 'next';
import { DevModeGate } from '@/components/admin/AdminDevMode';
import { AdminSystemStatus } from '@/components/admin/AdminSystemStatus';

export const metadata: Metadata = { title: 'System status · Store Admin' };

export default function Page() {
  return (
    <DevModeGate title="System status">
      <AdminSystemStatus />
    </DevModeGate>
  );
}
