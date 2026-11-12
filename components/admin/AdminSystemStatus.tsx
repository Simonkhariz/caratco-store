'use client';

import React from 'react';
import Link from 'next/link';
import { CheckCircle2, RefreshCw, XCircle, AlertTriangle } from 'lucide-react';
import { adminGetSystemStatus, SystemStatus } from '@/lib/admin-api';
import { formatDateTime } from '@/lib/admin-format';
import { Button, DataState, Notice, PageHeader, Panel, StatusPill, useAdminData } from '@/components/admin/ui';

type CheckState = 'ok' | 'warn' | 'fail';

function CheckIcon({ state }: { state: CheckState }) {
  if (state === 'ok') return <CheckCircle2 className="size-4 shrink-0 text-emerald-600" aria-hidden="true" />;
  if (state === 'warn') return <AlertTriangle className="size-4 shrink-0 text-amber-600" aria-hidden="true" />;
  return <XCircle className="size-4 shrink-0 text-red-600" aria-hidden="true" />;
}

const STATE_TEXT: Record<CheckState, string> = { ok: 'OK', warn: 'Check', fail: 'Problem' };

function Row({ label, state, value, href, linkLabel }: { label: string; state: CheckState; value: React.ReactNode; href?: string; linkLabel?: string }) {
  return (
    <li className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-[13px]">
      <span className="flex items-center gap-2">
        <CheckIcon state={state} />
        <span className="sr-only">{STATE_TEXT[state]}:</span>
        <span className="font-medium text-[#111827]">{label}</span>
      </span>
      <span className="flex items-center gap-3 text-right text-[#374151]">
        {value}
        {href && (
          <Link href={href} className="text-xs font-semibold text-[#111827] hover:underline">
            {linkLabel ?? 'View'}
          </Link>
        )}
      </span>
    </li>
  );
}

function gatewayState(mode: string): { state: CheckState; text: string } {
  if (mode === 'live') return { state: 'ok', text: 'Live keys' };
  if (mode === 'sandbox') return { state: 'warn', text: 'Sandbox (test) keys' };
  return { state: 'fail', text: 'Not configured' };
}

/** The shop accepts pictures up to 5 MB; PHP's own limit (0 = none) must allow that. */
const uploadTooSmall = (limitBytes: number) => limitBytes > 0 && limitBytes < 5 * 1048576;

/** Only what the backend recorded: set up or not, then the last attempt's real outcome. */
function refreshState(refresh: SystemStatus['storefront_refresh']): { state: CheckState; text: React.ReactNode } {
  if (!refresh.configured) {
    return {
      state: 'warn',
      text: (
        <span>
          Not set up
          <span className="block text-xs text-[#6b7280]">Shop pages show admin changes within about a minute</span>
        </span>
      ),
    };
  }
  if (!refresh.last) {
    return { state: 'ok', text: 'Set up · not used yet' };
  }
  if (refresh.last.ok) {
    return { state: 'ok', text: `Working · last ${formatDateTime(refresh.last.at)}` };
  }
  return {
    state: 'fail',
    text: (
      <span>
        Last attempt failed {formatDateTime(refresh.last.at)}
        <span className="block text-xs text-[#6b7280]">{refresh.last.error ?? 'Unknown error'} · shop pages still update within about a minute</span>
      </span>
    ),
  };
}

/** Health of the pieces the store depends on, read live from the backend. */
export function AdminSystemStatus() {
  const { data, loading, error, reload } = useAdminData<SystemStatus>('AdminSystemStatus', () => adminGetSystemStatus(), []);

  return (
    <div className="flex w-full flex-col gap-4">
      <PageHeader
        title="System status"
        description="Live checks of the database, storage, shop page refresh, payment gateways, email and order queues."
        actions={
          <Button onClick={reload} loading={loading && !!data}>
            <RefreshCw className="size-3.5" aria-hidden="true" />
            Refresh
          </Button>
        }
      />

      <DataState loading={loading} error={error} empty={false} onRetry={reload} hasData={!!data}>
        {data && <StatusBody data={data} />}
      </DataState>
    </div>
  );
}

function StatusBody({ data }: { data: SystemStatus }) {
  const kora = gatewayState(data.payments.kora);
  const monnify = gatewayState(data.payments.monnify);
  const refresh = refreshState(data.storefront_refresh);
  const healthy = data.system_health === 'healthy';

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center gap-3 rounded-xl border border-[#e5e7eb] bg-white px-4 py-3">
        <StatusPill label={healthy ? 'Healthy' : 'Degraded'} tone={healthy ? 'green' : 'red'} />
        <span className="text-[13px] text-[#374151]">
          {data.environment === 'production' ? 'Production' : 'Development'} · server time {data.server_time}
        </span>
      </div>

      {data.config_warnings.length > 0 && (
        <Notice tone="warning">
          <span className="font-semibold">To fix before customers use the shop:</span>
          <ul className="mt-1 list-disc pl-5">
            {data.config_warnings.map((w) => (
              <li key={w}>{w}</li>
            ))}
          </ul>
        </Notice>
      )}

      {data.mail.mode === 'log_only' && (
        <Notice tone="warning">
          Email is in log-only mode: messages are written to <code className="font-mono text-xs">backend/storage/logs/mail.log</code> and are not
          delivered to customers. Set MAIL_MAILER=resend (with RESEND_API_KEY) or MAIL_MAILER=smtp and the MAIL_* settings in backend/.env to send real email.
        </Notice>
      )}

      {data.mail.oldest_due_minutes !== null && data.mail.oldest_due_minutes > 15 && (
        <Notice tone="warning">
          The oldest email due to be sent has waited {data.mail.oldest_due_minutes} minutes. Emails are retried automatically while the shop
          has visitors; schedule <code className="font-mono text-xs">php backend/scripts/process_outbox.php</code> every minute (cron or Task
          Scheduler) so they also go out when it is quiet, or use “Send queued now” on the Email outbox page.
        </Notice>
      )}

      <div className="grid gap-4 xl:grid-cols-2">
        <Panel title="Infrastructure">
          <ul className="divide-y divide-[#f3f4f6]">
            <Row
              label="Database"
              state={data.database.connected ? 'ok' : 'fail'}
              value={data.database.connected ? `Connected${data.database.latency_ms !== null ? ` · ${data.database.latency_ms} ms` : ''}` : 'Unreachable'}
            />
            <Row
              label="Migrations"
              state={data.migrations.applied > 0 ? 'ok' : 'warn'}
              value={
                <span>
                  {data.migrations.applied} applied
                  {data.migrations.latest && <span className="block text-xs text-[#6b7280]">latest {data.migrations.latest}</span>}
                </span>
              }
            />
            <Row
              label="Image storage"
              state={data.storage.uploads_writable ? 'ok' : 'fail'}
              value={`${data.storage.driver === 'local' ? 'Server disk' : data.storage.driver} · ${
                data.storage.uploads_writable ? 'writable' : 'not writable (product images cannot be uploaded)'
              }`}
            />
            <Row label="Shop page refresh after admin changes" state={refresh.state} value={refresh.text} />
            <Row
              label="Redis (optional cache)"
              state={!data.redis.configured || data.redis.switched_off || data.redis.ok ? 'ok' : 'warn'}
              value={
                <span>
                  {data.redis.switched_off
                    ? 'Switched off in Tech switches · using the file cache'
                    : !data.redis.configured
                    ? 'Not used · the file cache is in use'
                    : data.redis.ok
                      ? `Answering${data.redis.ms !== null ? ` · ${data.redis.ms} ms` : ''}`
                      : 'Not answering · the file cache is standing in'}
                  {data.redis.configured && !data.redis.ok && data.redis.error && (
                    <span className="block text-xs text-[#6b7280]">{data.redis.error}</span>
                  )}
                </span>
              }
            />
            <Row
              label="PHP"
              state={data.runtime.missing_extensions.length > 0 ? 'fail' : !data.runtime.opcache || data.runtime.missing_backup_extensions.length > 0 ? 'warn' : 'ok'}
              value={
                <span>
                  {data.runtime.php} · OPcache {data.runtime.opcache ? 'on' : 'off'}
                  {data.runtime.missing_extensions.length > 0 && (
                    <span className="block text-xs text-red-700">Missing extensions: {data.runtime.missing_extensions.join(', ')}</span>
                  )}
                  {data.runtime.missing_backup_extensions.length > 0 && (
                    <span className="block text-xs text-[#6b7280]">Backups need: {data.runtime.missing_backup_extensions.join(', ')}</span>
                  )}
                  {!data.runtime.opcache && <span className="block text-xs text-[#6b7280]">Turn OPcache on for faster pages</span>}
                </span>
              }
            />
            <Row
              label="Largest picture upload"
              state={uploadTooSmall(data.runtime.upload_limit_bytes) ? 'warn' : 'ok'}
              value={
                <span>
                  {data.runtime.upload_limit_bytes > 0 ? `${(data.runtime.upload_limit_bytes / 1048576).toFixed(1).replace(/\.0$/, '')} MB` : 'No server limit'}
                  {uploadTooSmall(data.runtime.upload_limit_bytes) && (
                    <span className="block text-xs text-[#6b7280]">Pictures up to 5 MB are accepted; ask your host to set upload_max_filesize and post_max_size to 6M</span>
                  )}
                </span>
              }
            />
            <Row
              label="Visitor addresses"
              state={data.network.public_address || data.environment !== 'production' ? 'ok' : 'warn'}
              value={
                <span>
                  Your address as the shop sees it: <span className="font-mono">{data.network.client_ip}</span>
                  {!data.network.public_address && (
                    <span className="block text-xs text-[#6b7280]">
                      {data.environment === 'production'
                        ? 'A private or local address: every visitor looks the same, so sign-in and order limits are shared. Set up the proxy (docs/DEPLOYMENT.md).'
                        : 'Expected on this computer; on the live server this should be your real public address.'}
                    </span>
                  )}
                </span>
              }
            />
          </ul>
        </Panel>

        <Panel title="Payments & email">
          <ul className="divide-y divide-[#f3f4f6]">
            <Row label="Kora" state={kora.state} value={kora.text} />
            <Row label="Monnify" state={monnify.state} value={monnify.text} />
            <Row
              label="Email delivery"
              state={data.mail.mode === 'log_only' ? 'warn' : 'ok'}
              value={data.mail.mode === 'resend' ? 'Resend' : data.mail.mode === 'smtp' ? 'SMTP' : 'Log only'}
            />
            <Row
              label="Emails waiting to send"
              state={data.mail.outbox_pending > 0 ? 'warn' : 'ok'}
              value={data.mail.outbox_pending.toLocaleString()}
              href="/admin/notifications?status=pending"
            />
            <Row
              label="Emails that failed"
              state={data.mail.outbox_failed > 0 ? 'fail' : 'ok'}
              value={data.mail.outbox_failed.toLocaleString()}
              href="/admin/notifications?status=failed"
            />
          </ul>
        </Panel>
      </div>

      <Panel title="Order queues">
        <ul className="divide-y divide-[#f3f4f6]">
          <Row
            label="Orders needing attention"
            state={data.orders.needs_attention > 0 ? 'warn' : 'ok'}
            value={data.orders.needs_attention.toLocaleString()}
            href="/admin/orders?status=attention"
          />
          <Row
            label="Unpaid orders past their payment window"
            state={data.orders.unpaid_overdue > 0 ? 'warn' : 'ok'}
            value={
              <span>
                {data.orders.unpaid_overdue.toLocaleString()}
                {data.orders.unpaid_overdue > 0 && (
                  <span className="block text-xs text-[#6b7280]">Released automatically when the orders list loads or the expiry job runs</span>
                )}
              </span>
            }
            href="/admin/orders?status=pending_payment"
          />
          <Row
            label="Paid orders waiting to ship"
            state="ok"
            value={data.orders.awaiting_fulfilment.toLocaleString()}
            href="/admin/shipping"
          />
        </ul>
      </Panel>
    </div>
  );
}
