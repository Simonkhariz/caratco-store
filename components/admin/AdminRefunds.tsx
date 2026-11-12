'use client';

import React, { useEffect, useEffectEvent, useState } from 'react';
import Link from 'next/link';
import {
  adminCreateRefund,
  adminGetRefundEligibility,
  adminGetRefunds,
  adminResolveRefund,
  errorMessage,
  fieldErrors,
  type RefundEligibility,
  type RefundRow,
} from '@/lib/admin-api';
import { formatDateTime, formatPrice, gatewayLabel, humanize, num, orderStatus, paymentStatus } from '@/lib/admin-format';
import { refundStartAmount } from '@/lib/admin-logic';
import type { StatusTone } from '@/lib/order-status';
import {
  Button,
  Checkbox,
  DataState,
  DetailList,
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

const REFUND_STATUS: Record<string, { label: string; tone: StatusTone }> = {
  pending: { label: 'Pending', tone: 'amber' },
  completed: { label: 'Completed', tone: 'green' },
  rejected: { label: 'Rejected', tone: 'neutral' },
};

function refundStatus(status: string) {
  return REFUND_STATUS[status] ?? { label: humanize(status), tone: 'neutral' as StatusTone };
}

export function AdminRefunds() {
  const [filters, setFilters] = useUrlFilters({ status: 'all', page: '1', order: '' });
  const page = Math.max(1, parseInt(filters.page, 10) || 1);
  const list = useAdminData('AdminRefunds', () => adminGetRefunds({ status: filters.status, page, per_page: 20 }), [filters.status, page]);
  // Counts refunds completed or rejected in the list, so the "record a refund" figures follow.
  const [listChanges, setListChanges] = useState(0);

  return (
    <div className="space-y-5">
      <PageHeader title="Refunds" description="Record money returned to customers and track refunds that are still to be paid." />

      <RecordRefundPanel initialOrder={filters.order} onRecorded={list.reload} listChanges={listChanges} />

      <div>
        <Toolbar>
          <FilterSelect
            label="Refund status"
            value={filters.status}
            onChange={(status) => setFilters({ status })}
            options={[
              { value: 'all', label: 'All refunds' },
              { value: 'pending', label: 'Pending' },
              { value: 'completed', label: 'Completed' },
              { value: 'rejected', label: 'Rejected' },
            ]}
          />
        </Toolbar>
        <RefundList
          items={list.data?.items ?? []}
          loading={list.loading}
          error={list.error}
          hasData={!!list.data}
          filtered={filters.status !== 'all'}
          onRetry={list.reload}
          onChanged={() => {
            list.reload();
            setListChanges((n) => n + 1);
          }}
        />
        {list.data && (
          <div className="rounded-b-xl border border-t-0 border-[#e5e7eb] bg-white">
            <Pagination meta={list.data.pagination} onPage={(p) => setFilters({ page: String(p) })} />
          </div>
        )}
      </div>
    </div>
  );
}

// -----------------------------------------------------------------------------
// Record a refund
// -----------------------------------------------------------------------------

interface RefundForm {
  amount: string;
  reason: string;
  status: 'completed' | 'pending';
  reference: string;
  restock: boolean;
}

const EMPTY_FORM: RefundForm = { amount: '', reason: '', status: 'completed', reference: '', restock: false };

function RecordRefundPanel({ initialOrder, onRecorded, listChanges }: { initialOrder: string; onRecorded: () => void; listChanges: number }) {
  const toast = useToast();
  const confirm = useConfirm();
  const [orderNumber, setOrderNumber] = useState(initialOrder);
  const [checking, setChecking] = useState(false);
  const [lookupError, setLookupError] = useState<string | null>(null);
  const [eligibility, setEligibility] = useState<RefundEligibility | null>(null);
  const [form, setForm] = useState<RefundForm>(EMPTY_FORM);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState(false);

  const check = async (number: string) => {
    const trimmed = number.trim();
    if (!trimmed) {
      setLookupError('Enter an order number.');
      return;
    }
    setChecking(true);
    setLookupError(null);
    setEligibility(null);
    setErrors({});
    try {
      const result = await adminGetRefundEligibility(trimmed);
      setEligibility(result);
      setForm({ ...EMPTY_FORM, amount: refundStartAmount(result) });
    } catch (err) {
      if (redirectIfSignedOut(err)) return;
      setLookupError(errorMessage(err, 'Could not look up this order.'));
    } finally {
      setChecking(false);
    }
  };

  // A refund completed or rejected in the list below changes what this order can still be
  // refunded: bring the figures up to date without clearing what is being typed.
  const refreshFigures = useEffectEvent(() => {
    if (!eligibility) return;
    const number = eligibility.order_number;
    adminGetRefundEligibility(number).then(
      (result) => setEligibility((shown) => (shown && shown.order_number === number ? result : shown)),
      () => undefined // the figures stay as they were; "Check order" still works
    );
  });
  useEffect(() => {
    if (listChanges > 0) refreshFigures();
  }, [listChanges]);

  // Arriving from an order page with ?order=… checks it straight away: shown as checking on
  // the first paint, then looked up.
  const [checkedFor, setCheckedFor] = useState('');
  if (initialOrder.trim() && initialOrder !== checkedFor) {
    setCheckedFor(initialOrder);
    setOrderNumber(initialOrder);
    setChecking(true);
    setLookupError(null);
    setEligibility(null);
    setErrors({});
  }
  useEffect(() => {
    const trimmed = initialOrder.trim();
    if (!trimmed) return;
    let current = true;
    adminGetRefundEligibility(trimmed)
      .then(
        (result) => {
          if (!current) return;
          setEligibility(result);
          setForm({ ...EMPTY_FORM, amount: refundStartAmount(result) });
        },
        (err) => {
          if (redirectIfSignedOut(err) || !current) return;
          setLookupError(errorMessage(err, 'Could not look up this order.'));
        }
      )
      .finally(() => current && setChecking(false));
    return () => {
      current = false;
    };
  }, [initialOrder]);

  // A refund takes one payment at a time, so the next one can be less than what is left in all.
  const onePaymentAtATime = !!eligibility && eligibility.refundable_total > eligibility.refundable_amount + 0.005;

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!eligibility) return;
    const amount = parseFloat(form.amount);
    const local: Record<string, string> = {};
    if (!Number.isFinite(amount) || amount <= 0) local.amount = 'Enter an amount greater than 0.';
    else if (amount > eligibility.refundable_amount + 0.001) {
      local.amount = onePaymentAtATime
        ? `At most ${formatPrice(eligibility.refundable_amount)} in one refund: this order has more than one payment, so refund them one at a time.`
        : `At most ${formatPrice(eligibility.refundable_amount)} can be refunded.`;
    }
    if (!form.reason.trim()) local.reason = 'Give a reason (kept on the order record).';
    setErrors(local);
    if (Object.keys(local).length) return;

    // The server returns items to stock only when the order ends up fully refunded. Refunds still
    // pending are not money returned yet, so they do not count towards "fully".
    const completesRefund = (value: number) => value + eligibility.refunded_amount >= eligibility.paid_amount - 0.01;

    const ok = await confirm({
      title: `Record a ${formatPrice(amount)} refund?`,
      message: (
        <div className="space-y-2">
          <p>
            Order <span className="font-mono font-semibold">{eligibility.order_number}</span> ·{' '}
            {form.status === 'completed' ? 'marked as already paid back to the customer.' : 'recorded as pending until you mark it completed.'}
          </p>
          {form.restock && eligibility.can_restock && (completesRefund(amount) ? (
            <p>The order’s items will be returned to stock{form.status === 'completed' ? '' : ' when this refund is completed'}.</p>
          ) : (
            <p>This is a partial refund: the order’s items go back to stock once the order is fully refunded.</p>
          ))}
          {!completesRefund(amount) && (
            <p>Money stays on this order after this refund, so its status does not change. If it is flagged as needing attention, the flag stays on until you mark it as resolved on the order page.</p>
          )}
          <p className="text-[#6b7280]">This records the refund. It does not send money.</p>
        </div>
      ),
      confirmLabel: 'Record refund',
    });
    if (!ok) return;

    setSubmitting(true);
    try {
      const refund = await adminCreateRefund({
        order_number: eligibility.order_number,
        amount,
        reason: form.reason.trim(),
        status: form.status,
        restock: form.restock && eligibility.can_restock,
        provider_refund_reference: form.reference.trim() || undefined,
      });
      toast.success(
        refund.status === 'completed'
          ? 'Refund recorded as completed.'
          : 'Refund recorded as pending. Mark it completed once the money has been returned.'
      );
      onRecorded();
      await check(eligibility.order_number);
    } catch (err) {
      if (redirectIfSignedOut(err)) return;
      const fields = fieldErrors(err);
      if (Object.keys(fields).length) setErrors(fields);
      toast.error(errorMessage(err, 'The refund could not be recorded.'));
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Panel title="Record a refund" bodyClassName="space-y-4 p-4">
      <Notice tone="warning">
        This records a refund in the store. It does not move money: send the money from the Kora or Monnify dashboard, or from your bank,
        then record it here.
      </Notice>

      <form
        className="flex flex-wrap items-end gap-2"
        onSubmit={(e) => {
          e.preventDefault();
          check(orderNumber);
        }}
      >
        <Field label="Order number" error={lookupError ?? undefined} className="min-w-[220px] flex-1 sm:max-w-xs">
          {(p) => (
            <TextInput
              {...p}
              value={orderNumber}
              onChange={(e) => setOrderNumber(e.target.value)}
              placeholder="ORD-20260101-ABC123"
              className="font-mono"
            />
          )}
        </Field>
        <Button type="submit" loading={checking}>
          Check order
        </Button>
      </form>

      {eligibility && (
        <div className="space-y-4 border-t border-[#f3f4f6] pt-4">
          <DetailList
            items={[
              {
                label: 'Order',
                value: (
                  <span className="flex flex-wrap items-center gap-1.5">
                    <Link href={`/admin/orders/${encodeURIComponent(eligibility.order_number)}`} className="font-mono font-semibold hover:underline">
                      {eligibility.order_number}
                    </Link>
                    <StatusPill {...orderStatus(eligibility.order_status)} />
                    <StatusPill {...paymentStatus(eligibility.payment_status)} />
                  </span>
                ),
              },
              {
                label: 'Payment',
                value: eligibility.payment_reference ? (
                  <span>
                    {gatewayLabel(eligibility.gateway)} · <span className="font-mono text-xs">{eligibility.payment_reference}</span>
                  </span>
                ) : (
                  'No successful payment'
                ),
              },
              { label: 'Paid', value: formatPrice(eligibility.paid_amount) },
              { label: 'Already refunded', value: formatPrice(eligibility.refunded_amount) },
              { label: 'Pending refunds', value: formatPrice(eligibility.pending_refund_amount) },
              { label: 'Can still be refunded', value: <strong>{formatPrice(eligibility.refundable_total)}</strong> },
            ]}
          />

          {eligibility.refundable_amount <= 0 ? (
            <Notice>
              {eligibility.paid_amount <= 0
                ? 'This order has no successful payment, so there is nothing to refund.'
                : eligibility.pending_refund_amount > 0
                  ? 'Everything paid has been refunded or is waiting on a pending refund, so there is nothing more to refund.'
                  : 'This order is fully refunded: nothing is left to refund.'}
            </Notice>
          ) : (
            <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2" noValidate>
              <Field
                label="Amount (₦)"
                required
                error={errors.amount}
                hint={
                  onePaymentAtATime
                    ? `Up to ${formatPrice(eligibility.refundable_amount)} in this refund: this order has more than one payment, refunded one at a time, newest first.`
                    : `Up to ${formatPrice(eligibility.refundable_amount)}.`
                }
              >
                {(p) => (
                  <TextInput
                    {...p}
                    type="number"
                    inputMode="decimal"
                    min={0}
                    step="0.01"
                    max={eligibility.refundable_amount}
                    value={form.amount}
                    onChange={(e) => setForm((f) => ({ ...f, amount: e.target.value }))}
                  />
                )}
              </Field>
              <Field label="Gateway or bank refund reference" error={errors.provider_refund_reference} hint="Optional. From the Kora/Monnify dashboard or bank.">
                {(p) => <TextInput {...p} value={form.reference} onChange={(e) => setForm((f) => ({ ...f, reference: e.target.value }))} />}
              </Field>
              <Field label="Reason" required error={errors.reason} className="sm:col-span-2">
                {(p) => <TextArea {...p} value={form.reason} onChange={(e) => setForm((f) => ({ ...f, reason: e.target.value }))} />}
              </Field>

              <fieldset className="sm:col-span-2">
                <legend className="mb-1.5 text-xs font-semibold text-[#374151]">Has the money already been returned?</legend>
                <div className="space-y-2">
                  {(
                    [
                      ['completed', 'Yes – completed', 'I have already sent the money from the gateway dashboard or bank.'],
                      ['pending', 'Not yet – pending', 'Approved, to be paid later. Mark it completed once sent.'],
                    ] as const
                  ).map(([value, label, hint]) => (
                    <label key={value} className="flex items-start gap-2 text-[13px] text-[#1f2937]">
                      <input
                        type="radio"
                        name="refund-status"
                        value={value}
                        checked={form.status === value}
                        onChange={() => setForm((f) => ({ ...f, status: value }))}
                        className="mt-0.5 size-4 accent-[#111827]"
                      />
                      <span>
                        {label}
                        <span className="block text-xs text-[#6b7280]">{hint}</span>
                      </span>
                    </label>
                  ))}
                </div>
              </fieldset>

              {eligibility.can_restock && (
                <div className="sm:col-span-2">
                  <Checkbox
                    label="Return the order’s items to stock"
                    hint="Only if the items were never shipped, or came back in sellable condition. Items go back once the order is fully refunded."
                    checked={form.restock}
                    onChange={(restock) => setForm((f) => ({ ...f, restock }))}
                  />
                </div>
              )}

              <div className="flex justify-end sm:col-span-2">
                <Button type="submit" variant="primary" loading={submitting}>
                  Record refund
                </Button>
              </div>
            </form>
          )}
        </div>
      )}
    </Panel>
  );
}

// -----------------------------------------------------------------------------
// Refund list
// -----------------------------------------------------------------------------

function RefundList({
  items,
  loading,
  error,
  hasData,
  filtered,
  onRetry,
  onChanged,
}: {
  items: RefundRow[];
  loading: boolean;
  error: string | null;
  hasData: boolean;
  filtered: boolean;
  onRetry: () => void;
  onChanged: () => void;
}) {
  const toast = useToast();
  const confirm = useConfirm();
  const [busyId, setBusyId] = useState<number | null>(null);

  const resolve = async (refund: RefundRow, status: 'completed' | 'rejected') => {
    const ok = await confirm(
      status === 'completed'
        ? {
            title: `Mark the ${formatPrice(num(refund.amount))} refund as completed?`,
            message: 'Only do this once the money has actually reached the customer. The order’s payment status will be updated.',
            confirmLabel: 'Mark completed',
          }
        : {
            title: `Reject the ${formatPrice(num(refund.amount))} refund?`,
            message: 'The refund will be cancelled and that amount becomes refundable again.',
            confirmLabel: 'Reject refund',
            tone: 'danger',
          }
    );
    if (!ok) return;
    setBusyId(refund.id);
    try {
      await adminResolveRefund(refund.id, status);
      toast.success(status === 'completed' ? 'Refund marked completed.' : 'Refund rejected.');
      onChanged();
    } catch (err) {
      if (redirectIfSignedOut(err)) return;
      toast.error(errorMessage(err, 'Could not update this refund.'));
    } finally {
      setBusyId(null);
    }
  };

  return (
    <Panel className="rounded-b-none">
      <DataState
        loading={loading}
        error={error}
        onRetry={onRetry}
        hasData={hasData}
        empty={items.length === 0}
        emptyTitle={filtered ? 'No refunds with this status' : 'No refunds recorded'}
        emptyDescription={filtered ? undefined : 'Refunds you record above appear here.'}
      >
        <Table minWidth={980}>
          <thead>
            <tr>
              <th scope="col">Order</th>
              <th scope="col">Customer</th>
              <th scope="col" className="text-right">Amount</th>
              <th scope="col">Reason</th>
              <th scope="col">Status</th>
              <th scope="col">Recorded</th>
              <th scope="col">
                <span className="sr-only">Actions</span>
              </th>
            </tr>
          </thead>
          <tbody>
            {items.map((r) => (
              <tr key={r.id}>
                <td>
                  <Link href={`/admin/orders/${encodeURIComponent(r.order_number)}`} className="whitespace-nowrap font-mono text-xs font-semibold text-[#111827] hover:underline">
                    {r.order_number}
                  </Link>
                  <span className="block text-xs text-[#6b7280]">
                    {gatewayLabel(r.gateway)}
                    {r.provider_refund_reference ? ` · ${r.provider_refund_reference}` : ''}
                  </span>
                </td>
                <td>
                  <span className="block text-[#111827]">{r.customer_name || '—'}</span>
                  {r.customer_email && <span className="block text-xs text-[#6b7280]">{r.customer_email}</span>}
                </td>
                <td className="whitespace-nowrap text-right tabular-nums">{formatPrice(num(r.amount))}</td>
                <td className="max-w-[260px]">
                  <span className="line-clamp-2 text-[#374151]" title={r.reason}>
                    {r.reason}
                  </span>
                  {Number(r.restocked) === 1 && <span className="block text-xs text-[#6b7280]">Items returned to stock</span>}
                  {Number(r.restocked) === 2 && r.status !== 'rejected' && (
                    <span className="block text-xs text-[#6b7280]">Items return to stock once the order is fully refunded</span>
                  )}
                </td>
                <td>
                  <StatusPill {...refundStatus(r.status)} />
                </td>
                <td className="whitespace-nowrap text-xs text-[#374151]">
                  <span className="block">{formatDateTime(r.created_at)}</span>
                  {r.processed_by_name && <span className="block text-[#6b7280]">by {r.processed_by_name}</span>}
                  {r.status !== 'pending' && r.updated_at !== r.created_at && (
                    <span className="block text-[#6b7280]">
                      {refundStatus(r.status).label} {formatDateTime(r.updated_at)}
                    </span>
                  )}
                </td>
                <td>
                  {r.status === 'pending' && (
                    <div className="flex justify-end gap-1.5">
                      <Button size="sm" variant="primary" loading={busyId === r.id} onClick={() => resolve(r, 'completed')}>
                        Mark completed
                      </Button>
                      <Button size="sm" disabled={busyId === r.id} onClick={() => resolve(r, 'rejected')}>
                        Reject
                      </Button>
                    </div>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </Table>
      </DataState>
    </Panel>
  );
}
