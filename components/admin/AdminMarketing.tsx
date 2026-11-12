'use client';

import React, { useState } from 'react';
import { Send } from 'lucide-react';
import { ApiError } from '@/lib/api-client';
import { adminGetSubscribers, adminSendBroadcast, errorMessage } from '@/lib/admin-api';
import { formatDate } from '@/lib/admin-format';
import {
  Button,
  DataState,
  Field,
  FilterSelect,
  Notice,
  PageHeader,
  Pagination,
  Panel,
  StatusPill,
  Table,
  TextArea,
  TextInput,
  Toolbar,
  redirectIfSignedOut,
  useAdminData,
  useConfirm,
  useToast,
  useUrlFilters,
} from '@/components/admin/ui';

const SUBJECT_MAX = 150;

/**
 * Newsletter: people who subscribed in the store footer plus registered customers (one entry per
 * email), and broadcasts to the active ones. Every email carries a personal unsubscribe link
 * (added by the backend); someone who unsubscribed is never put back, account or not.
 */
export function AdminMarketing() {
  const toast = useToast();
  const confirm = useConfirm();
  const [filters, setFilters] = useUrlFilters({ status: 'active', origin: 'all', page: '1' });
  const page = Math.max(1, parseInt(filters.page, 10) || 1);

  const { data, loading, error, reload } = useAdminData('AdminMarketing',
    () => adminGetSubscribers({ status: filters.status, origin: filters.origin, page, per_page: 25 }),
    [filters.status, filters.origin, page]
  );

  const [subject, setSubject] = useState('');
  const [content, setContent] = useState('');
  const [sending, setSending] = useState(false);
  const [logOnlyNotice, setLogOnlyNotice] = useState(false);

  const activeCount = data?.counts.active ?? 0;
  const canSend = !!data && activeCount > 0 && subject.trim() !== '' && content.trim() !== '' && subject.length <= SUBJECT_MAX;

  const handleSend = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!canSend) return;
    const ok = await confirm({
      title: `Send to ${activeCount.toLocaleString()} ${activeCount === 1 ? 'person' : 'people'}?`,
      message: (
        <>
          <p>
            Subject: <strong>{subject.trim()}</strong>
          </p>
          <p className="mt-2">
            {(data?.counts.active_subscribers ?? 0).toLocaleString()} newsletter subscriber{data?.counts.active_subscribers === 1 ? '' : 's'} and{' '}
            {(data?.counts.active_customers ?? 0).toLocaleString()} registered customer{data?.counts.active_customers === 1 ? '' : 's'}. Each gets
            their own copy with an unsubscribe link. This cannot be recalled once queued.
          </p>
        </>
      ),
      confirmLabel: 'Queue broadcast',
    });
    if (!ok) return;

    setSending(true);
    try {
      const res = await adminSendBroadcast({ subject: subject.trim(), content: content.trim(), confirm_recipients: activeCount });
      const plural = (n: number) => `${n.toLocaleString()} ${n === 1 ? 'person' : 'people'}`;
      toast.success(
        (res.queued_count > 0 ? `Queued for ${plural(res.queued_count)}.` : 'Nothing new to send.') +
          (res.already_queued_today > 0 ? ` ${plural(res.already_queued_today)} already had this newsletter today and won't get it twice.` : '')
      );
      setLogOnlyNotice(res.mail_mode === 'log_only');
      setSubject('');
      setContent('');
    } catch (err) {
      if (redirectIfSignedOut(err)) return;
      if (err instanceof ApiError && err.errorCode === 'RECIPIENTS_CHANGED') {
        reload();
      }
      toast.error(
        err instanceof ApiError && err.errorCode === 'TIMEOUT'
          ? 'The server did not answer within 2 minutes. The broadcast may still be queuing: check the Email outbox before sending again. Anyone who already has this newsletter today won’t get it twice.'
          : errorMessage(err)
      );
    } finally {
      setSending(false);
    }
  };

  const items = data?.items ?? [];

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title="Newsletter"
        description="Goes to people who subscribed in the store and to customers with a confirmed email or a paid order. Anyone who unsubscribes stops getting it, account or not."
      />

      <div className="grid gap-3 sm:grid-cols-3">
        <div className="rounded-xl border border-[#e5e7eb] bg-white px-4 py-3">
          <p className="text-xs text-[#6b7280]">Will receive the newsletter</p>
          <p className="mt-1 text-xl font-semibold tabular-nums text-[#111827]">{data ? activeCount.toLocaleString() : '—'}</p>
        </div>
        <div className="rounded-xl border border-[#e5e7eb] bg-white px-4 py-3">
          <p className="text-xs text-[#6b7280]">Subscribers · Registered customers</p>
          <p className="mt-1 text-xl font-semibold tabular-nums text-[#111827]">
            {data ? `${data.counts.active_subscribers.toLocaleString()} · ${data.counts.active_customers.toLocaleString()}` : '—'}
          </p>
        </div>
        <div className="rounded-xl border border-[#e5e7eb] bg-white px-4 py-3">
          <p className="text-xs text-[#6b7280]">Unsubscribed</p>
          <p className="mt-1 text-xl font-semibold tabular-nums text-[#111827]">{data ? data.counts.unsubscribed.toLocaleString() : '—'}</p>
        </div>
      </div>

      <Panel title="Write a broadcast" bodyClassName="p-4">
        <form onSubmit={handleSend} className="flex flex-col gap-4" noValidate>
          <Field
            label="Subject"
            required
            hint={`${subject.length}/${SUBJECT_MAX} characters`}
            error={subject.length > SUBJECT_MAX ? `Keep the subject under ${SUBJECT_MAX} characters.` : undefined}
          >
            {(p) => <TextInput {...p} value={subject} onChange={(e) => setSubject(e.target.value)} />}
          </Field>
          <Field label="Message" required>
            {(p) => <TextArea {...p} rows={8} value={content} onChange={(e) => setContent(e.target.value)} />}
          </Field>

          {data && activeCount === 0 ? (
            <Notice tone="warning">There are no subscribers or registered customers yet, so there is no one to send to.</Notice>
          ) : (
            <p className="text-xs text-[#6b7280]">
              Goes to {data ? activeCount.toLocaleString() : '…'} {activeCount === 1 ? 'person' : 'people'} (subscribers and registered customers); every email includes an
              unsubscribe link. Emails are queued and sent in batches by the email worker (or “Send queued now” on the Email outbox page).
            </p>
          )}

          {logOnlyNotice && (
            <Notice tone="warning">
              Email delivery is in log-only mode: queued messages are written to the server mail log, not sent, until SMTP is configured
              in backend/.env.
            </Notice>
          )}

          <div>
            <Button type="submit" variant="primary" loading={sending} disabled={!canSend}>
              <Send className="size-3.5" aria-hidden="true" />
              Review and send
            </Button>
          </div>
        </form>
      </Panel>

      <Panel title="Who gets it">
        <div className="px-4 pt-3">
          <Toolbar>
            <FilterSelect
              label="Subscriber status"
              value={filters.status}
              onChange={(status) => setFilters({ status })}
              options={[
                { value: 'active', label: 'Active' },
                { value: 'unsubscribed', label: 'Unsubscribed' },
                { value: 'all', label: 'Everyone' },
              ]}
            />
            <FilterSelect
              label="Source"
              value={filters.origin}
              onChange={(origin) => setFilters({ origin })}
              options={[
                { value: 'all', label: 'All sources' },
                { value: 'subscriber', label: 'Subscribers' },
                { value: 'customer', label: 'Registered customers' },
              ]}
            />
          </Toolbar>
        </div>
        <DataState
          loading={loading}
          error={error}
          onRetry={reload}
          hasData={!!data}
          empty={items.length === 0}
          emptyTitle="Nobody here"
          emptyDescription="People join from the newsletter form in the store footer, and every registered customer is included."
        >
          <Table minWidth={620}>
            <thead>
              <tr>
                <th>Email</th>
                <th>Source</th>
                <th>Since</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {items.map((s) => (
                <tr key={s.email}>
                  <td className="max-w-[280px] truncate text-[#111827]">{s.email}</td>
                  <td className="text-[#6b7280]">
                    {s.origin === 'customer' ? 'Registered customer' : s.has_account ? 'Subscriber · has an account' : 'Subscriber'}
                  </td>
                  <td className="whitespace-nowrap text-[#6b7280]">{formatDate(s.subscribed_at)}</td>
                  <td>
                    {s.status === 'active' ? (
                      <StatusPill label="Active" tone="green" />
                    ) : (
                      <span className="inline-flex flex-col gap-0.5">
                        <StatusPill label="Unsubscribed" tone="neutral" />
                        {s.unsubscribed_at && <span className="text-xs text-[#6b7280]">{formatDate(s.unsubscribed_at)}</span>}
                      </span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </Table>
          <Pagination meta={data?.pagination} onPage={(p) => setFilters({ page: String(p) })} />
        </DataState>
      </Panel>
    </div>
  );
}
