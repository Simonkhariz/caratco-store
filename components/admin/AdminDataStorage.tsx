'use client';

import React, { useState } from 'react';
import { Eye, RefreshCw, ShieldCheck } from 'lucide-react';
import {
  type CleanupChallenge,
  type DataOverview,
  type DataRecordColumn,
  type DataSettingKey,
  type DataSettings,
  type HealthLevel,
  type StorageHealthReport,
  adminGetDataOverview,
  adminGetDataRecords,
  adminRunCleanup,
  adminUpdateDataSettings,
  adminVerifyCleanup,
  errorMessage,
  fieldErrors,
} from '@/lib/admin-api';
import { ApiError } from '@/lib/api-client';
import { formatDateTime, humanize } from '@/lib/admin-format';
import { formatPrice } from '@/lib/currency';
import {
  Button,
  DataState,
  Field,
  Modal,
  Notice,
  PageHeader,
  Pagination,
  Panel,
  Select,
  StatusPill,
  Table,
  TextInput,
  redirectIfSignedOut,
  useAdminData,
  useToast,
} from '@/components/admin/ui';

const LEVEL_PILL: Record<HealthLevel, { label: string; tone: 'green' | 'amber' | 'red' }> = {
  ok: { label: 'Healthy', tone: 'green' },
  warning: { label: 'Needs attention', tone: 'amber' },
  critical: { label: 'Problem', tone: 'red' },
};

/** The keep-period settings in the order shown, with what each one does. */
const SETTINGS: Array<{ key: DataSettingKey; label: string; keep?: string; hint: React.ReactNode }> = [
  {
    key: 'retention_customer_days',
    label: 'Customer records',
    keep: 'Keep (never list for removal)',
    hint: (
      <>
        A customer with no sign-in and no order for this long is listed for your review. If you confirm, their account, saved addresses, cart and
        wishlist are deleted, and their name, phone, email and street address are removed from their finished orders. Anyone with an unpaid,
        undelivered or flagged order, or a refund in progress, is never listed.
      </>
    ),
  },
  {
    key: 'retention_unpaid_days',
    label: 'Unfinished checkouts',
    hint: 'Cancelled orders that were never paid, and carts that visitors left without signing in.',
  },
  {
    key: 'retention_message_days',
    label: 'Emails and payment notifications',
    hint: 'Copies of sent and failed emails (they contain names and addresses) and raw notifications from Kora and Monnify. Emails are never kept longer than customer records.',
  },
  {
    key: 'retention_activity_days',
    label: 'Admin activity and stock history',
    keep: 'Keep forever',
    hint: 'The audit log of admin changes and the record of every stock adjustment. Keep at least a year so you can look back at mistakes or disputes.',
  },
  {
    key: 'storage_alert_mb',
    label: 'Alert me when the database is bigger than',
    hint: 'You get a warning in the admin bell from 80% of this, and an email from the daily check. Set it to what your hosting plan allows.',
  },
];

/** Friendly names for the tables most likely to grow. */
const TABLE_NAMES: Record<string, string> = {
  orders: 'Orders',
  order_items: 'Order items',
  order_status_history: 'Order status history',
  payments: 'Payments',
  refunds: 'Refunds',
  shipments: 'Shipments',
  payment_webhook_events: 'Payment notifications',
  email_outbox: 'Emails',
  admin_audit_logs: 'Admin activity log',
  inventory_logs: 'Stock history',
  sessions: 'Sign-in sessions',
  rate_limits: 'Rate-limit counters',
  users: 'Accounts',
  addresses: 'Saved addresses',
  carts: 'Carts',
  cart_items: 'Cart items',
  products: 'Products',
  product_variants: 'Product variants',
  product_images: 'Product images',
  reviews: 'Reviews',
  newsletter_subscribers: 'Newsletter subscribers',
  api_slow_requests: 'Slow-request records',
};

function optionLabel(key: DataSettingKey, value: number, keep?: string): string {
  if (key === 'storage_alert_mb') return value >= 1000 ? `${value / 1000} GB` : `${value} MB`;
  if (value === 0) return keep ?? 'Keep';
  if (value % 365 === 0) return value === 365 ? '1 year' : `${value / 365} years`;
  if (value >= 90 && value % 30 === 0) return `${value / 30} months`;
  return `${value} days`;
}

function formatBytes(bytes: number): string {
  if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
  if (bytes < 1024 * 1024 * 1024) return `${(bytes / 1048576).toFixed(1)} MB`;
  return `${(bytes / 1073741824).toFixed(2)} GB`;
}

function daysAgo(days: number | null): string {
  if (days === null) return 'Never';
  if (days === 0) return 'Today';
  return days === 1 ? 'Yesterday' : `${days} days ago`;
}

/** Records (not people) a clean-up would remove. */
function recordTotal(counts: Record<string, number>): number {
  return Object.entries(counts).reduce((sum, [key, n]) => (key === 'inactive_customers' ? sum : sum + n), 0);
}

/** "1 record", "2 records". */
function recordCount(n: number): string {
  return `${n.toLocaleString()} record${n === 1 ? '' : 's'}`;
}

/**
 * Admin → Data & storage: keep periods, database size and speed, and the exact records that
 * are past their keep period. Nothing is removed automatically: the admin reviews the records
 * and confirms a clean-up with their password and a code emailed to them.
 */
export function AdminDataStorage() {
  const { data, loading, error, reload } = useAdminData<DataOverview>('AdminDataStorage#overview', () => adminGetDataOverview(), []);

  return (
    <div className="flex w-full flex-col gap-4">
      <PageHeader
        title="Data & storage"
        description="How long data is kept, how big and fast the database is, and old data waiting for your review."
        actions={
          <Button onClick={reload} loading={loading && !!data}>
            <RefreshCw className="size-3.5" aria-hidden="true" />
            Refresh
          </Button>
        }
      />
      <DataState loading={loading} error={error} empty={false} onRetry={reload} hasData={!!data}>
        {data && (
          <div className="flex flex-col gap-4">
            <HealthPanel health={data.health} />
            <ReviewPanel data={data} onChanged={reload} />
            <RetentionPanel data={data} onSaved={reload} />
            <div className="grid items-start gap-4 xl:grid-cols-2">
              <TablesPanel health={data.health} />
              <SlowPanel health={data.health} />
            </div>
          </div>
        )}
      </DataState>
    </div>
  );
}

function Stat({ label, value, sub, children }: { label: string; value: React.ReactNode; sub?: React.ReactNode; children?: React.ReactNode }) {
  return (
    <div className="min-w-0 px-4 py-3">
      <p className="text-xs font-medium text-[#6b7280]">{label}</p>
      <p className="mt-1 text-lg font-semibold tabular-nums text-[#111827]">{value}</p>
      {children}
      {sub && <p className="mt-0.5 text-xs text-[#6b7280]">{sub}</p>}
    </div>
  );
}

function HealthPanel({ health }: { health: StorageHealthReport }) {
  const pill = LEVEL_PILL[health.level];
  const used = Math.min(100, health.database.used_percent);
  const barTone = health.database.used_percent >= 100 ? 'bg-red-500' : health.database.used_percent >= 80 ? 'bg-amber-500' : 'bg-[#1f2937]';
  const lastRun = health.cleanup.last_run;
  const thresholdSec = health.slow_requests.threshold_ms / 1000;

  return (
    <Panel
      title="Database health"
      actions={
        <span className="flex items-center gap-2 text-xs text-[#6b7280]">
          <StatusPill label={pill.label} tone={pill.tone} />
          checked {formatDateTime(health.checked_at)}
        </span>
      }
    >
      {health.issues.length > 0 && (
        <div className="flex flex-col gap-2 border-b border-[#f3f4f6] p-3">
          {health.issues.map((issue) => (
            <Notice key={issue.code} tone={issue.level === 'critical' ? 'danger' : 'warning'}>
              <strong>{issue.title}.</strong> {issue.detail}
            </Notice>
          ))}
        </div>
      )}
      <div className="grid divide-y divide-[#f3f4f6] sm:grid-cols-2 sm:divide-y-0 lg:grid-cols-4 lg:divide-x">
        <Stat label="Database size" value={formatBytes(health.database.size_bytes)} sub={`${health.database.used_percent}% of your ${optionLabel('storage_alert_mb', health.database.alert_mb)} limit`}>
          <div
            className="mt-2 h-1.5 overflow-hidden rounded-full bg-[#f3f4f6]"
            role="meter"
            aria-label="Database size against your limit"
            aria-valuemin={0}
            aria-valuemax={100}
            aria-valuenow={used}
          >
            <div className={`h-full rounded-full ${barTone}`} style={{ width: `${Math.max(used, 1)}%` }} />
          </div>
        </Stat>
        <Stat
          label="Slow requests (24 hours)"
          value={health.slow_requests.threshold_ms > 0 ? health.slow_requests.last_24h.toLocaleString() : 'Off'}
          sub={
            health.slow_requests.threshold_ms > 0
              ? health.slow_requests.last_24h > 0
                ? `took over ${thresholdSec} s · slowest ${(health.slow_requests.slowest_ms / 1000).toFixed(1)} s`
                : `none took over ${thresholdSec} s`
              : 'SLOW_REQUEST_MS=0 in backend/.env'
          }
        />
        <Stat
          label="Typical query time"
          value={health.queries ? `${health.queries.typical_ms} ms` : '—'}
          sub={health.queries && health.queries.typical_ms < 100 ? 'fast' : 'normally under 50 ms'}
        />
        <Stat
          label="Last clean-up"
          value={daysAgo(health.cleanup.days_since)}
          sub={lastRun ? `${formatDateTime(lastRun.at)}${lastRun.by ? ` · by ${lastRun.by}` : ''}` : 'nothing has been removed yet'}
        />
      </div>
    </Panel>
  );
}

function ReviewPanel({ data, onChanged }: { data: DataOverview; onChanged: () => void }) {
  const [viewing, setViewing] = useState<string | null>(null);
  const [confirming, setConfirming] = useState(false);
  const due = data.health.cleanup.due;
  const asOf = data.health.as_of;
  const order = Object.keys(data.labels);
  const rows = Object.entries(due)
    .filter(([, count]) => count > 0)
    .sort(([a], [b]) => order.indexOf(a) - order.indexOf(b));
  const records = rows.filter(([key]) => !data.housekeeping.includes(key));
  const technical = rows.filter(([key]) => data.housekeeping.includes(key));
  // Lines the admin unticked: left out of the clean-up. The two lines under "Inactive customers"
  // follow it, so a customer is never half-removed (the server does the same).
  const [skipped, setSkipped] = useState<string[]>([]);
  const followsCustomers = (key: string) => key === 'inactive_customer_accounts' || key === 'inactive_customer_orders';
  const isTicked = (key: string) => !skipped.includes(followsCustomers(key) ? 'inactive_customers' : key);
  const toggle = (key: string) => setSkipped((list) => (list.includes(key) ? list.filter((k) => k !== key) : [...list, key]));
  const included = Object.fromEntries(Object.entries(due).filter(([key]) => isTicked(key)));
  const total = recordTotal(included);

  const group = (title: string, items: Array<[string, number]>, hint?: string) =>
    items.length > 0 && (
      <div>
        <p className="border-b border-[#f3f4f6] bg-[#f9fafb] px-4 py-2 text-xs font-semibold text-[#6b7280]">
          {title}
          {hint && <span className="font-normal"> · {hint}</span>}
        </p>
        <ul className="divide-y divide-[#f3f4f6]">
          {items.map(([key, count]) => (
            <li
              key={key}
              className={`flex items-center justify-between gap-3 px-4 py-2 text-[13px] ${key === 'inactive_customers' ? 'bg-amber-50/60' : ''}`}
            >
              {followsCustomers(key) ? (
                <span className={`pl-9 ${isTicked(key) ? 'text-[#374151]' : 'text-[#9ca3af]'}`}>{data.labels[key] ?? key}</span>
              ) : (
                <label className={`flex min-w-0 cursor-pointer items-center gap-2.5 ${isTicked(key) ? 'font-medium text-[#111827]' : 'text-[#9ca3af]'}`}>
                  <input
                    type="checkbox"
                    checked={isTicked(key)}
                    onChange={() => toggle(key)}
                    className="size-4 shrink-0 cursor-pointer accent-black"
                  />
                  <span>{data.labels[key] ?? key}</span>
                </label>
              )}
              <span className="flex items-center gap-3">
                <span className={`font-semibold tabular-nums ${isTicked(key) ? 'text-[#111827]' : 'text-[#9ca3af] line-through'}`}>{count.toLocaleString()}</span>
                <Button size="sm" variant="ghost" onClick={() => setViewing(key)} aria-label={`View ${data.labels[key] ?? key}`}>
                  <Eye className="size-3.5" aria-hidden="true" />
                  View
                </Button>
              </span>
            </li>
          ))}
        </ul>
      </div>
    );

  return (
    <Panel
      title="Waiting for your review"
      actions={<span className="text-xs text-[#6b7280]">as of {formatDateTime(asOf)}</span>}
      bodyClassName="p-0"
    >
      <div className="border-b border-[#f3f4f6] px-4 py-3">
        <Notice>
          Nothing is ever removed automatically. These records are past the keep periods below. Open each list to see exactly what would go; only
          you can remove them, with your password and a code sent to your email. Order totals, payments and refunds are always kept.
        </Notice>
      </div>
      {data.health.cleanup.note && (
        <div className="border-b border-[#f3f4f6] px-4 py-3">
          <Notice tone="warning">{data.health.cleanup.note}</Notice>
        </div>
      )}
      {rows.length === 0 ? (
        !data.health.cleanup.note && <p className="px-4 py-4 text-[13px] text-[#6b7280]">Nothing to review: everything is within your keep periods.</p>
      ) : (
        <>
          {group('Customer and business data', records)}
          {group('Technical records', technical, 'no customer data')}
        </>
      )}
      <div className="flex flex-wrap items-center gap-3 border-t border-[#f3f4f6] px-4 py-3">
        <Button variant="danger" onClick={() => setConfirming(true)} disabled={total === 0}>
          <ShieldCheck className="size-3.5" aria-hidden="true" />
          Clean up {total > 0 ? recordCount(total) : ''}…
        </Button>
        <p className="text-xs text-[#6b7280]">
          {skipped.some((key) => (due[key] ?? 0) > 0)
            ? 'Only the ticked lines are removed. '
            : 'Untick a line to keep it. '}
          You&apos;ll be asked for your password and a one-time code from your email.
        </p>
      </div>

      {viewing && <RecordsDialog rule={viewing} label={data.labels[viewing] ?? viewing} asOf={asOf} onClose={() => setViewing(null)} />}
      {confirming && (
        <CleanupDialog
          data={data}
          skip={skipped}
          included={included}
          onClose={() => setConfirming(false)}
          onDone={() => {
            setConfirming(false);
            onChanged();
          }}
        />
      )}
    </Panel>
  );
}

function cellValue(column: DataRecordColumn, value: string | number | null): React.ReactNode {
  if (value === null || value === '') return <span className="text-[#9ca3af]">—</span>;
  switch (column.type) {
    case 'date':
      return formatDateTime(String(value));
    case 'money':
      return formatPrice(Number(value));
    case 'number':
      return Number(value).toLocaleString();
    case 'code':
      return <span className="font-mono text-xs">{String(value)}</span>;
    default:
      return column.key === 'order_status' || column.key === 'status' || column.key === 'type' ? humanize(String(value)) : String(value);
  }
}

/** The exact records one rule would remove, page by page. */
function RecordsDialog({ rule, label, asOf, onClose }: { rule: string; label: string; asOf: string; onClose: () => void }) {
  const [page, setPage] = useState(1);
  const { data, loading, error, reload } = useAdminData('AdminDataStorage', () => adminGetDataRecords(rule, asOf, page), [rule, asOf, page], { onPastEnd: setPage });
  const right = (c: DataRecordColumn) => c.type === 'money' || c.type === 'number';

  return (
    <Modal open onClose={onClose} title={label} description={`What a clean-up would remove, as of ${formatDateTime(asOf)}.`} size="lg">
      <DataState loading={loading} error={error} onRetry={reload} hasData={!!data} empty={!!data && data.items.length === 0} emptyTitle="Nothing here any more">
        {data && (
          <div className="-mx-5 -my-4">
            <Table minWidth={Math.max(420, data.columns.length * 130)}>
              <thead>
                <tr>
                  {data.columns.map((c) => (
                    <th key={c.key} scope="col" className={right(c) ? 'text-right' : ''}>
                      {c.label}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {data.items.map((item, i) => (
                  <tr key={i}>
                    {data.columns.map((c) => (
                      <td key={c.key} className={right(c) ? 'text-right tabular-nums' : ''}>
                        {cellValue(c, item[c.key] ?? null)}
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </Table>
            <Pagination meta={data.pagination} onPage={setPage} />
          </div>
        )}
      </DataState>
    </Modal>
  );
}

/**
 * Two-step confirmation: the admin's password, then the 6-digit code emailed to them.
 * The server removes exactly the records reviewed as of the page's time, or refuses.
 */
function CleanupDialog({
  data,
  skip,
  included,
  onClose,
  onDone,
}: {
  data: DataOverview;
  /** Lines the admin unticked. */
  skip: string[];
  /** The ticked lines and their counts: what this clean-up removes. */
  included: Record<string, number>;
  onClose: () => void;
  onDone: () => void;
}) {
  const toast = useToast();
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [challenge, setChallenge] = useState<CleanupChallenge | null>(null);
  const [errors, setErrors] = useState<{ password?: string; code?: string; form?: string }>({});
  const [busy, setBusy] = useState(false);
  const due = included;
  const customers = due.inactive_customers ?? 0;
  const total = recordTotal(due);

  const fail = (err: unknown, field: 'password' | 'code') => {
    if (redirectIfSignedOut(err)) return;
    // No answer in time: the clean-up may still be finishing on the server. The code is used
    // either way, so close and show the current list instead of asking for it again.
    if (field === 'code' && err instanceof ApiError && err.errorCode === 'TIMEOUT') {
      toast.error('The server is taking long to answer. The clean-up may still be finishing: check the list again in a minute.');
      onDone();
      return;
    }
    // The records changed, or the review is over 30 minutes old: show the current list.
    if (err instanceof ApiError && (err.statusCode === 409 || err.errorCode === 'REVIEW_EXPIRED')) {
      toast.error(errorMessage(err));
      onDone();
      return;
    }
    if (err instanceof ApiError && err.statusCode === 410) {
      // Expired, used or too many wrong codes: start again from the password.
      setChallenge(null);
      setCode('');
      setPassword('');
      setErrors({ form: errorMessage(err) });
      return;
    }
    const fields = fieldErrors(err);
    setErrors(fields[field] ? { [field]: errorMessage(err) } : { form: errorMessage(err) });
  };

  const sendCode = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!password) {
      setErrors({ password: 'Enter your admin password.' });
      return;
    }
    setBusy(true);
    setErrors({});
    try {
      setChallenge(await adminVerifyCleanup(password, data.health.as_of, skip));
      setPassword('');
    } catch (err) {
      fail(err, 'password');
    } finally {
      setBusy(false);
    }
  };

  const confirm = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!challenge || code.length !== 6) return;
    setBusy(true);
    setErrors({});
    try {
      const { removed } = await adminRunCleanup(challenge.confirmation_id, code);
      const count = recordTotal(removed);
      toast.success(`Clean-up finished: ${count.toLocaleString()} old record${count === 1 ? '' : 's'} removed. All admins have been emailed.`);
      onDone();
    } catch (err) {
      fail(err, 'code');
    } finally {
      setBusy(false);
    }
  };

  const summary = (
    <ul className="divide-y divide-[#f3f4f6] rounded-lg border border-[#e5e7eb] text-[13px]">
      {Object.entries(due)
        .filter(([, n]) => n > 0)
        .map(([key, n]) => (
          <li key={key} className="flex justify-between gap-3 px-3 py-1.5">
            <span className="text-[#374151]">{data.labels[key] ?? key}</span>
            <span className="font-semibold tabular-nums">{n.toLocaleString()}</span>
          </li>
        ))}
    </ul>
  );

  return (
    <Modal
      open
      onClose={onClose}
      busy={busy}
      persistent
      title={challenge ? 'Enter the code from your email' : 'Confirm it’s you'}
      description={
        challenge
          ? undefined
          : `${recordCount(total)} you reviewed (as of ${formatDateTime(data.health.as_of)}) will be permanently removed.`
      }
      footer={
        <>
          <Button onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          {challenge ? (
            <Button type="submit" form="cleanup-code" variant="danger" loading={busy} disabled={code.length !== 6}>
              Delete permanently
            </Button>
          ) : (
            <Button type="submit" form="cleanup-password" variant="primary" loading={busy}>
              Send code to my email
            </Button>
          )}
        </>
      }
    >
      <div className="flex flex-col gap-4">
        {errors.form && <Notice tone="danger">{errors.form}</Notice>}
        {!challenge ? (
          <form id="cleanup-password" onSubmit={sendCode} className="flex flex-col gap-4" noValidate>
            {summary}
            <Notice tone="warning">
              This can&apos;t be undone.
              {customers > 0 && ` ${customers.toLocaleString()} inactive ${customers === 1 ? 'customer loses their' : 'customers lose their'} account and personal details.`}{' '}
              Order totals, payments and refunds are kept.
            </Notice>
            <Field label="Your admin password" error={errors.password} required>
              {(p) => (
                <TextInput
                  {...p}
                  data-autofocus
                  type="password"
                  autoComplete="current-password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                />
              )}
            </Field>
          </form>
        ) : (
          <form id="cleanup-code" onSubmit={confirm} className="flex flex-col gap-4" noValidate>
            <p className="text-[13px] leading-relaxed text-[#374151]">
              We emailed a 6-digit code to <strong>{challenge.sent_to}</strong>. It works once, until {formatDateTime(challenge.expires_at)}.
            </p>
            {challenge.mail_mode === 'log_only' && (
              <Notice>
                Email is in log-only mode on this server, so the code is in <code className="font-mono text-xs">backend/storage/logs/mail.log</code>.
              </Notice>
            )}
            <Field label="Code" error={errors.code} required>
              {(p) => (
                <TextInput
                  {...p}
                  data-autofocus
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  maxLength={6}
                  value={code}
                  onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
                  className="font-mono text-lg tracking-[0.4em]"
                />
              )}
            </Field>
            {summary}
            <button
              type="button"
              className="self-start text-xs font-semibold text-[#111827] underline-offset-2 hover:underline disabled:opacity-50"
              disabled={busy}
              onClick={() => {
                setChallenge(null);
                setCode('');
                setErrors({});
              }}
            >
              Didn&apos;t get it? Send a new code
            </button>
          </form>
        )}
      </div>
    </Modal>
  );
}

function RetentionPanel({ data, onSaved }: { data: DataOverview; onSaved: () => void }) {
  const toast = useToast();
  const [values, setValues] = useState<DataSettings>(data.settings);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  // The form starts from the saved settings each time they (re)load.
  const [valuesFor, setValuesFor] = useState(data.settings);
  if (data.settings !== valuesFor) {
    setValuesFor(data.settings);
    setValues(data.settings);
  }

  const changed = SETTINGS.map((s) => s.key).filter((key) => values[key] !== data.settings[key]);

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    if (changed.length === 0) return;
    const payload: Partial<DataSettings> = {};
    changed.forEach((key) => {
      payload[key] = values[key];
    });
    setSaving(true);
    setErrors({});
    try {
      await adminUpdateDataSettings(payload);
      toast.success('Saved. Records past the new periods are listed for your review; nothing was removed.');
      onSaved();
    } catch (err) {
      if (redirectIfSignedOut(err)) return;
      const fields = fieldErrors(err);
      setErrors(fields);
      toast.error(Object.keys(fields).length ? 'Please fix the highlighted settings.' : errorMessage(err));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Panel title="How long to keep data" bodyClassName="p-4">
      <form onSubmit={save} className="flex flex-col gap-4" noValidate>
        {data.settings.retention_customer_days === 0 && (
          <Notice>
            Customer records are kept for ever at the moment. Choose a period so you are alerted about personal data of people who stopped
            shopping with you (the Nigeria Data Protection Act asks you not to keep it longer than needed). 1 year is a common choice.
          </Notice>
        )}
        <div className="grid gap-4 sm:grid-cols-2">
          {SETTINGS.map((s) => (
            <Field key={s.key} label={s.label} hint={s.hint} error={errors[s.key]} className={s.key === 'retention_customer_days' ? 'sm:col-span-2' : ''}>
              {(p) => (
                <Select {...p} value={values[s.key]} onChange={(e) => setValues((v) => ({ ...v, [s.key]: Number(e.target.value) }))}>
                  {data.options[s.key].map((option) => (
                    <option key={option} value={option}>
                      {optionLabel(s.key, option, s.keep)}
                    </option>
                  ))}
                </Select>
              )}
            </Field>
          ))}
        </div>
        <p className="text-xs leading-relaxed text-[#6b7280]">
          Saving never removes anything: records past these periods are listed above for your review. Never removed at all: order totals and items,
          payments and refunds (tax rules require you to keep business records for six years), orders that need your attention, and active
          newsletter subscriptions.
        </p>
        <div className="flex flex-wrap items-center gap-3">
          <Button type="submit" variant="primary" loading={saving} disabled={changed.length === 0}>
            Save changes
          </Button>
          {changed.length > 0 && (
            <Button
              variant="ghost"
              disabled={saving}
              onClick={() => {
                setValues(data.settings);
                setErrors({});
              }}
            >
              Discard
            </Button>
          )}
        </div>
      </form>
    </Panel>
  );
}

function TablesPanel({ health }: { health: StorageHealthReport }) {
  return (
    <Panel title="Biggest tables" bodyClassName="p-0">
      <Table minWidth={420}>
        <thead>
          <tr>
            <th scope="col">Table</th>
            <th scope="col" className="text-right">
              Rows (about)
            </th>
            <th scope="col" className="text-right">
              Size
            </th>
          </tr>
        </thead>
        <tbody>
          {health.database.tables.map((t) => (
            <tr key={t.name}>
              <td>
                <span className="font-medium text-[#111827]">{TABLE_NAMES[t.name] ?? t.name}</span>
                {TABLE_NAMES[t.name] && <span className="ml-2 font-mono text-xs text-[#9ca3af]">{t.name}</span>}
              </td>
              <td className="text-right tabular-nums">{t.rows.toLocaleString()}</td>
              <td className="text-right tabular-nums">{formatBytes(t.bytes)}</td>
            </tr>
          ))}
        </tbody>
      </Table>
    </Panel>
  );
}

function SlowPanel({ health }: { health: StorageHealthReport }) {
  const routes = health.slow_requests.routes;
  return (
    <Panel title="Slowest requests (24 hours)" bodyClassName="p-0">
      {routes.length === 0 ? (
        <p className="px-4 py-4 text-[13px] text-[#6b7280]">
          {health.slow_requests.threshold_ms > 0
            ? `No request took longer than ${health.slow_requests.threshold_ms / 1000} s. The site is responding quickly.`
            : 'Slow-request tracking is off (SLOW_REQUEST_MS=0 in backend/.env).'}
        </p>
      ) : (
        <Table minWidth={460}>
          <thead>
            <tr>
              <th scope="col">Request</th>
              <th scope="col" className="text-right">
                Times
              </th>
              <th scope="col" className="text-right">
                Average
              </th>
              <th scope="col" className="text-right">
                Slowest
              </th>
            </tr>
          </thead>
          <tbody>
            {routes.map((r) => (
              <tr key={`${r.method} ${r.route}`}>
                <td className="font-mono text-xs">
                  {r.method} {r.route.replace(/^\/api\/v1/, '')}
                </td>
                <td className="text-right tabular-nums">{r.hits.toLocaleString()}</td>
                <td className="text-right tabular-nums">{(r.avg_ms / 1000).toFixed(1)} s</td>
                <td className="text-right tabular-nums">{(r.max_ms / 1000).toFixed(1)} s</td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
    </Panel>
  );
}
