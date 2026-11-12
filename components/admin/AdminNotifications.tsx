'use client';

import { useState } from 'react';
import { RefreshCw, RotateCcw, Send } from 'lucide-react';
import { ApiError } from '@/lib/api-client';
import { adminGetOutbox, adminGetOutboxStatus, adminProcessOutbox, adminRetryOutbox, errorMessage, OutboxRow } from '@/lib/admin-api';
import { formatDateTime, formatRelative, humanize } from '@/lib/admin-format';
import {
  Button,
  DataState,
  Notice,
  FilterSelect,
  PageHeader,
  Pagination,
  Panel,
  StatusPill,
  Table,
  Toolbar,
  redirectIfSignedOut,
  useAdminData,
  useToast,
  useUrlFilters,
} from '@/components/admin/ui';

const STATUS_OPTIONS = [
  { value: 'all', label: 'All emails' },
  { value: 'pending', label: 'Waiting to send' },
  { value: 'sent', label: 'Sent' },
  { value: 'failed', label: 'Failed' },
];

function statusPill(row: OutboxRow): { label: string; tone: 'amber' | 'green' | 'red' | 'neutral' } {
  if (row.status === 'sent') {
    return row.transport === 'log' ? { label: 'Logged only', tone: 'neutral' } : { label: 'Delivered', tone: 'green' };
  }
  if (row.status === 'failed') return { label: 'Failed', tone: 'red' };
  return row.attempt_count > 0 ? { label: 'Retrying', tone: 'amber' } : { label: 'Waiting', tone: 'amber' };
}

/** Sign-in links and confirmation codes carry a one-time secret that is never stored, so they cannot be re-sent. */
const NOT_RESENDABLE = ['magic_link', 'account_verification', 'admin_confirm_code'];

/** Every email the store has queued: order confirmations, shipping updates, sign-in links, newsletters. */
export function AdminNotifications() {
  const toast = useToast();
  const [filters, setFilters] = useUrlFilters({ status: 'all', page: '1' });
  const page = Math.max(1, parseInt(filters.page, 10) || 1);
  const { data, loading, error, reload } = useAdminData('AdminNotifications',
    () => adminGetOutbox({ status: filters.status, page, per_page: 25 }),
    [filters.status, page]
  );
  const { data: mail, reload: reloadStatus } = useAdminData('AdminNotifications#2', () => adminGetOutboxStatus(), []);
  const [retrying, setRetrying] = useState<number | null>(null);
  const [processing, setProcessing] = useState(false);

  const processNow = async () => {
    setProcessing(true);
    try {
      const res = await adminProcessOutbox();
      toast.success(res.message || 'Queue processed.');
      await Promise.all([reload(), reloadStatus()]);
    } catch (err) {
      if (redirectIfSignedOut(err)) return;
      if (err instanceof ApiError && err.errorCode === 'TIMEOUT') {
        toast.error('The server did not answer within 2 minutes. It may still be sending, so some emails may have gone out: the list below shows where things stand.');
        void Promise.all([reload(), reloadStatus()]);
        return;
      }
      toast.error(errorMessage(err, 'The queue could not be processed.'));
    } finally {
      setProcessing(false);
    }
  };

  const retry = async (row: OutboxRow) => {
    setRetrying(row.id);
    try {
      const res = await adminRetryOutbox(row.id);
      toast.success(res.message || `Email to ${row.recipient_email} sent.`);
      await Promise.all([reload(), reloadStatus()]);
    } catch (err) {
      if (redirectIfSignedOut(err)) return;
      toast.error(errorMessage(err, 'The email could not be re-sent.'));
      // The server already tried again and updated the row: show where it stands now.
      void Promise.all([reload(), reloadStatus()]);
    } finally {
      setRetrying(null);
    }
  };

  const rows = data?.items ?? [];

  return (
    <div className="flex w-full flex-col">
      <PageHeader
        title="Email outbox"
        description="Every email the store has queued. Message bodies are not shown here because they can contain customer details."
        actions={
          <>
            <Button onClick={processNow} loading={processing} disabled={!mail || mail.outbox_pending === 0}>
              <Send className="size-3.5" aria-hidden="true" />
              Send queued now{mail ? ` (${mail.outbox_pending})` : ''}
            </Button>
            <Button
              onClick={() => {
                reload();
                reloadStatus();
              }}
              loading={loading && !!data}
              aria-label="Refresh list"
            >
              <RefreshCw className="size-3.5" aria-hidden="true" />
            </Button>
          </>
        }
      />

      {mail?.mode === 'log_only' && (
        <div className="mb-3">
          <Notice tone="warning">
            Email is in log-only mode: messages are written to <code>backend/storage/logs/mail.log</code> and do not reach customers. Set
            MAIL_MAILER=resend (with RESEND_API_KEY) or MAIL_MAILER=smtp and the MAIL_* settings in backend/.env to deliver them
            (see docs/EMAIL_SETUP.md).
          </Notice>
        </div>
      )}
      {mail && mail.oldest_due_minutes !== null && mail.oldest_due_minutes > 15 && (
        <div className="mb-3">
          <Notice tone="warning">
            The oldest email due to be sent has waited {mail.oldest_due_minutes} minutes. Emails are retried automatically
            while the shop has visitors; schedule <code>php backend/scripts/process_outbox.php</code> every minute (cron) so they
            also go out when it is quiet, or use “Send queued now”.
          </Notice>
        </div>
      )}
      <Toolbar>
        <FilterSelect label="Status" value={filters.status} onChange={(v) => setFilters({ status: v })} options={STATUS_OPTIONS} />
      </Toolbar>

      <Panel>
        <DataState
          loading={loading}
          error={error}
          onRetry={reload}
          hasData={!!data}
          empty={rows.length === 0}
          emptyTitle={filters.status === 'all' ? 'No emails yet' : `No ${STATUS_OPTIONS.find((o) => o.value === filters.status)?.label.toLowerCase()} emails`}
        >
          <Table minWidth={900}>
            <thead>
              <tr>
                <th scope="col">Recipient</th>
                <th scope="col">Subject</th>
                <th scope="col">Type</th>
                <th scope="col">Status</th>
                <th scope="col" className="text-right">Attempts</th>
                <th scope="col">Queued</th>
                <th scope="col">Sent</th>
                <th scope="col">
                  <span className="sr-only">Actions</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => {
                const s = statusPill(row);
                const canResend = !NOT_RESENDABLE.includes(row.template) && row.status !== 'sent';
                return (
                  <tr key={row.id}>
                    <td className="max-w-[220px]">
                      <span className="block truncate" title={row.recipient_email}>
                        {row.recipient_email}
                      </span>
                    </td>
                    <td className="max-w-[260px]">
                      <span className="block truncate" title={row.subject}>
                        {row.subject}
                      </span>
                    </td>
                    <td className="whitespace-nowrap text-xs text-[#374151]">{humanize(row.template)}</td>
                    <td>
                      <StatusPill label={s.label} tone={s.tone} />
                      {row.last_error && (
                        <span className={`mt-1 block max-w-[240px] truncate text-xs ${row.status === 'failed' ? 'text-red-700' : 'text-[#6b7280]'}`} title={row.last_error}>
                          {row.last_error}
                        </span>
                      )}
                      {row.status === 'pending' && row.next_attempt_at && row.attempt_count > 0 && (
                        <span className="mt-1 block text-xs text-[#6b7280]">Next try {formatDateTime(row.next_attempt_at)}</span>
                      )}
                    </td>
                    <td className="text-right tabular-nums">{row.attempt_count ?? 0}</td>
                    <td className="whitespace-nowrap text-xs text-[#6b7280]" title={formatDateTime(row.created_at)}>
                      {formatRelative(row.created_at)}
                    </td>
                    <td className="whitespace-nowrap text-xs text-[#6b7280]">{row.sent_at ? formatDateTime(row.sent_at) : '—'}</td>
                    <td className="text-right">
                      {canResend && (
                        <Button size="sm" onClick={() => retry(row)} loading={retrying === row.id} disabled={retrying !== null && retrying !== row.id}>
                          <RotateCcw className="size-3.5" aria-hidden="true" />
                          {row.status === 'failed' ? 'Retry' : 'Send now'}
                        </Button>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </Table>
          <Pagination meta={data?.pagination} onPage={(p) => setFilters({ page: String(p) })} />
        </DataState>
      </Panel>
    </div>
  );
}
