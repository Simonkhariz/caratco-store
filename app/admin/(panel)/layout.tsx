import { Suspense } from 'react';
import { AdminAuthGate } from '@/components/admin/AdminAuthGate';
import { AdminShell } from '@/components/admin/AdminShell';
import { LoadingState } from '@/components/admin/ui';

/** Every admin screen except login and setup: requires an admin session. */
export default function AdminPanelLayout({ children }: { children: React.ReactNode }) {
  return (
    <AdminAuthGate>
      <AdminShell>
        <Suspense fallback={<LoadingState variant="page" />}>{children}</Suspense>
      </AdminShell>
    </AdminAuthGate>
  );
}
