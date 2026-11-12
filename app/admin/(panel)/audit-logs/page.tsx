import type { Metadata } from 'next';
import { AdminAuditLogs } from '@/components/admin/AdminAuditLogs';

export const metadata: Metadata = { title: 'Audit log · Store Admin' };

export default function Page() {
  return <AdminAuditLogs />;
}
