'use client';

import React, { useState } from 'react';
import Link from 'next/link';
import { ArrowLeft, ExternalLink } from 'lucide-react';
import {
  AdminOrderDetail as OrderDetail,
  OrderStatus,
  adminConfirmDelivery,
  adminGetOrder,
  adminResolveAttention,
  adminUpdateOrderStatus,
  errorMessage,
  fieldErrors,
} from '@/lib/admin-api';
import {
  ORDER_TRANSITION_LABELS,
  formatDateTime,
  formatPrice,
  gatewayLabel,
  humanize,
  num,
  orderStatus,
  paymentStatus,
} from '@/lib/admin-format';
import { canRecordLatePayment } from '@/lib/admin-logic';
import { optionsText } from '@/lib/shop';
import {
  Button,
  DetailList,
  EmptyState,
  ErrorState,
  Field,
  LoadingState,
  Modal,
  Notice,
  Panel,
  StatusPill,
  Table,
  TextArea,
  redirectIfSignedOut,
  useAdminData,
  useConfirm,
  useToast,
} from '@/components/admin/ui';
import { ShipOrderModal } from '@/components/admin/ShipOrderModal';
import { ManualPaymentModal } from '@/components/admin/ManualPaymentModal';

/** Simple status changes (no extra details needed), with the explanation shown before confirming. */
const STATUS_CHANGE_HELP: Partial<Record<OrderStatus, string>> = {
  processing: 'Use this once you start packing the order. From "Refund pending" it declines the refund request, and the order can then be shipped. The customer sees "Processing".',
  completed: 'Close the order once delivery is settled. No further changes are expected. From "Refund pending" it declines the refund request: no money moves.',
  returned: 'Use this when the parcel comes back to the store. You can then record a refund if one is due.',
  refund_pending: 'Marks the order as awaiting a refund. Record the refund on the Refunds page once the money is returned.',
  delivered: 'The refund request is declined: the order goes back to "Delivered". No money moves.',
};

export function AdminOrderDetail({ orderNumber }: { orderNumber: string }) {
  const toast = useToast();
  const confirm = useConfirm();
  const { data: order, setData, loading, error, reload } = useAdminData('AdminOrderDetail', () => adminGetOrder(orderNumber), [orderNumber]);

  const [shipOpen, setShipOpen] = useState(false);
  const [paymentOpen, setPaymentOpen] = useState(false);
  const [statusTarget, setStatusTarget] = useState<OrderStatus | null>(null);
  const [resolveOpen, setResolveOpen] = useState(false);
  const [busy, setBusy] = useState(false);

  const notFound = error && !order && /not found/i.test(error);

  if (loading && !order) return <LoadingState label="Loading order…" variant="detail" />;
  if (notFound) {
    return (
      <EmptyState
        title="Order not found"
        description={`There is no order ${orderNumber}.`}
        action={
          <Link href="/admin/orders" className="text-[13px] font-semibold underline">
            Back to orders
          </Link>
        }
      />
    );
  }
  if (error && !order) return <ErrorState message={error} onRetry={reload} />;
  if (!order) return null;

  const os = orderStatus(order.order_status);
  const ps = paymentStatus(order.payment_status);
  const addr = order.shipping_address_snapshot ?? {};
  const zone = order.shipping_zone_snapshot;
  // Large or heavy items the delivery fee counted when the order was placed (0 in most shops).
  const heavyItems = num(zone?.heavy_items);
  const heavyItemFee = num(zone?.heavy_item_fee);
  const transitions = order.allowed_transitions ?? [];
  const canRefund = order.payment_status === 'successful' || order.payment_status === 'partially_refunded';
  // Underpaid money stays on a payment that never became successful, and can still be sent back.
  const hasUnderpayment = (order.payments ?? []).some(
    (p) => Number(p.amount_received) > 0 && ['pending', 'failed', 'cancelled'].includes(p.status),
  );
  const latePayment = canRecordLatePayment(order);

  const handleError = (err: unknown) => {
    if (redirectIfSignedOut(err)) return;
    toast.error(errorMessage(err));
  };

  const markDelivered = async () => {
    const ok = await confirm({
      title: 'Mark as delivered?',
      message: 'Confirm that the customer has received the parcel.',
      confirmLabel: 'Mark delivered',
    });
    if (!ok) return;
    setBusy(true);
    try {
      setData(await adminConfirmDelivery(order.order_number));
      toast.success('Order marked as delivered.');
    } catch (err) {
      handleError(err);
    } finally {
      setBusy(false);
    }
  };

  const cancelOrder = async () => {
    const paid = canRefund;
    const ok = await confirm({
      title: `Cancel ${order.order_number}?`,
      tone: 'danger',
      confirmLabel: 'Cancel order',
      cancelLabel: 'Keep order',
      message: (
        <>
          {order.order_status === 'pending_payment' && order.customer_marked_paid_at && (
            <p className="mb-2 font-semibold">The customer marked their WhatsApp transfer as paid. Check your bank first: cancel only if the money has not arrived.</p>
          )}
          <p>The reserved stock goes back on sale and the customer is told the order was cancelled.</p>
          {paid && (
            <p className="mt-2 font-semibold">
              This order has been paid. Cancelling does not return the money: record the refund on the Refunds page after you send it.
            </p>
          )}
        </>
      ),
    });
    if (!ok) return;
    setBusy(true);
    try {
      setData(await adminUpdateOrderStatus(order.order_number, 'cancelled', 'Cancelled by the store.'));
      toast.success('Order cancelled.');
    } catch (err) {
      handleError(err);
    } finally {
      setBusy(false);
    }
  };

  const runTransition = (status: OrderStatus) => {
    if (status === 'shipped') setShipOpen(true);
    // Delivery confirmation is for parcels on their way; from "Refund pending", "Delivered" means
    // the refund was declined, which is a plain status change.
    else if (status === 'delivered' && order.order_status !== 'refund_pending') markDelivered();
    else if (status === 'cancelled') cancelOrder();
    else setStatusTarget(status);
  };

  const items = order.items ?? [];

  return (
    <div className="flex flex-col gap-4">
      <div>
        <Link href="/admin/orders" className="inline-flex items-center gap-1 text-xs font-medium text-[#6b7280] hover:text-[#111827]">
          <ArrowLeft className="size-3.5" aria-hidden="true" />
          All orders
        </Link>
        <div className="mt-2 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
          <div className="min-w-0">
            <h1 className="text-lg font-semibold tracking-tight text-[#111827]">{order.order_number}</h1>
            <div className="mt-1.5 flex flex-wrap items-center gap-2 text-xs text-[#6b7280]">
              <StatusPill label={os.label} tone={os.tone} />
              <StatusPill label={`Payment: ${ps.label}`} tone={ps.tone} />
              <span>Placed {formatDateTime(order.created_at)}</span>
              {order.order_status === 'pending_payment' && order.expires_at && <span>· Payment window ends {formatDateTime(order.expires_at)}</span>}
            </div>
          </div>
          <div className="flex flex-wrap gap-2">
            {order.order_status === 'pending_payment' && (
              <Button variant="primary" onClick={() => setPaymentOpen(true)} disabled={busy}>
                Record manual payment
              </Button>
            )}
            {latePayment && (
              <Button onClick={() => setPaymentOpen(true)} disabled={busy}>
                Record late payment
              </Button>
            )}
            {order.requires_attention && (
              <Button onClick={() => setResolveOpen(true)} disabled={busy}>
                Mark as resolved
              </Button>
            )}
            {transitions.map((t) => (
              <Button
                key={t}
                variant={t === 'cancelled' ? 'danger' : t === 'shipped' || t === 'delivered' ? 'primary' : 'secondary'}
                onClick={() => runTransition(t)}
                disabled={busy}
              >
                {ORDER_TRANSITION_LABELS[t] ?? humanize(t)}
              </Button>
            ))}
            {(canRefund || hasUnderpayment) && (
              <Link
                href={`/admin/refunds?order=${encodeURIComponent(order.order_number)}`}
                className="inline-flex h-9 items-center rounded-lg border border-[#d1d5db] bg-white px-3.5 text-[13px] font-semibold text-[#1f2937] hover:bg-[#f9fafb]"
              >
                Record a refund
              </Link>
            )}
          </div>
        </div>
      </div>

      {order.order_status === 'pending_payment' && order.customer_marked_paid_at && (
        <Notice tone="warning">
          <strong>Customer says they have paid.</strong> They marked their WhatsApp transfer as paid on{' '}
          {formatDateTime(order.customer_marked_paid_at)}. Check that {formatPrice(num(order.grand_total))} has arrived in your
          account, then use <strong>Record manual payment</strong> with the transfer reference.
        </Notice>
      )}

      {order.requires_attention && (
        <Notice tone="danger">
          <strong>Needs attention.</strong> {order.attention_reason || 'Check the payments on this order.'}
        </Notice>
      )}

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div className="flex min-w-0 flex-col gap-4 lg:col-span-2">
          <Panel title={`Items (${items.length})`}>
            {items.length === 0 ? (
              <EmptyState title="No items recorded" />
            ) : (
              <Table minWidth={560}>
                <thead>
                  <tr>
                    <th scope="col">Item</th>
                    <th scope="col">SKU</th>
                    <th scope="col" className="text-right">
                      Price
                    </th>
                    <th scope="col" className="text-right">
                      Qty
                    </th>
                    <th scope="col" className="text-right">
                      Total
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {items.map((it) => {
                    const opts = it.variant_options ?? {};
                    // Leaves out an option the product does not use (its stored "none" value).
                    const variant = optionsText(opts.size, opts.color ?? opts.color_name);
                    const warranty = typeof opts.warranty === 'string' ? opts.warranty.trim() : '';
                    return (
                      <tr key={it.id}>
                        <td>
                          <span className="block font-medium text-[#111827]">{it.item_name}</span>
                          {variant && <span className="block text-xs text-[#6b7280]">{variant}</span>}
                          {warranty && <span className="block text-xs text-[#6b7280]">Warranty: {warranty}</span>}
                        </td>
                        <td className="text-xs text-[#4b5563]">{it.sku || '—'}</td>
                        <td className="whitespace-nowrap text-right tabular-nums">{formatPrice(num(it.unit_price))}</td>
                        <td className="text-right tabular-nums">{it.quantity}</td>
                        <td className="whitespace-nowrap text-right font-medium tabular-nums">{formatPrice(num(it.line_total))}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </Table>
            )}
            <dl className="space-y-1.5 border-t border-[#f3f4f6] px-4 py-3 text-[13px]">
              <div className="flex justify-between">
                <dt className="text-[#6b7280]">Subtotal</dt>
                <dd className="tabular-nums">{formatPrice(num(order.subtotal))}</dd>
              </div>
              {num(order.discount_amount) > 0 && (
                <div className="flex justify-between">
                  <dt className="text-[#6b7280]">Discount{order.applied_promo_code ? ` (${order.applied_promo_code})` : ''}</dt>
                  <dd className="tabular-nums">−{formatPrice(num(order.discount_amount))}</dd>
                </div>
              )}
              <div className="flex justify-between">
                <dt className="text-[#6b7280]">Delivery{zone?.name ? ` (${zone.name})` : ''}</dt>
                <dd className="tabular-nums">{formatPrice(num(order.shipping_fee))}</dd>
              </div>
              <div className="flex justify-between border-t border-[#f3f4f6] pt-1.5 font-semibold">
                <dt>Total</dt>
                <dd className="tabular-nums">{formatPrice(num(order.grand_total))}</dd>
              </div>
            </dl>
          </Panel>

          <Panel title="Payments">
            {(order.payments ?? []).length === 0 ? (
              <EmptyState title="No payment attempts yet" description="The customer has not started paying for this order." />
            ) : (
              <Table minWidth={620}>
                <thead>
                  <tr>
                    <th scope="col">Method</th>
                    <th scope="col">Reference</th>
                    <th scope="col" className="text-right">
                      Amount
                    </th>
                    <th scope="col">Status</th>
                    <th scope="col">Confirmed</th>
                  </tr>
                </thead>
                <tbody>
                  {order.payments.map((p) => {
                    const s = p.status === 'cancelled' ? { label: 'Not used', tone: 'neutral' as const } : paymentStatus(p.status);
                    const received = p.amount_received !== null && p.amount_received !== undefined ? num(p.amount_received) : null;
                    return (
                      <tr key={p.id}>
                        <td className="whitespace-nowrap">{gatewayLabel(p.provider)}</td>
                        <td className="break-all text-xs text-[#4b5563]">{p.provider_reference || '—'}</td>
                        <td className="whitespace-nowrap text-right tabular-nums">
                          {formatPrice(num(p.amount))}
                          {received !== null && received !== num(p.amount) && (
                            <span className="block text-xs text-red-700">Received {formatPrice(received)}</span>
                          )}
                        </td>
                        <td>
                          <StatusPill label={s.label} tone={s.tone} />
                        </td>
                        <td className="whitespace-nowrap text-xs text-[#4b5563]">{formatDateTime(p.verified_at)}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </Table>
            )}
          </Panel>

          {(order.refunds ?? []).length > 0 && (
            <Panel title="Refunds">
              <Table minWidth={520}>
                <thead>
                  <tr>
                    <th scope="col">Date</th>
                    <th scope="col" className="text-right">
                      Amount
                    </th>
                    <th scope="col">Status</th>
                    <th scope="col">Reason</th>
                  </tr>
                </thead>
                <tbody>
                  {order.refunds.map((r) => (
                    <tr key={r.id}>
                      <td className="whitespace-nowrap text-xs">{formatDateTime(r.created_at)}</td>
                      <td className="whitespace-nowrap text-right tabular-nums">{formatPrice(num(r.amount))}</td>
                      <td>
                        <StatusPill
                          label={`${humanize(r.status)}${
                            Number(r.restocked) === 1 ? ' · restocked' : Number(r.restocked) === 2 ? ' · restock once fully refunded' : ''
                          }`}
                          tone={r.status === 'completed' ? 'green' : r.status === 'rejected' ? 'neutral' : 'amber'}
                        />
                      </td>
                      <td className="text-xs text-[#4b5563]">{r.reason}</td>
                    </tr>
                  ))}
                </tbody>
              </Table>
            </Panel>
          )}

          <Panel title="History">
            {(order.history ?? []).length === 0 ? (
              <EmptyState title="No status changes yet" />
            ) : (
              <ol className="space-y-3 px-4 py-3">
                {order.history.map((h, i) => (
                  <li key={i} className="flex gap-3 text-[13px]">
                    <span className="mt-1.5 size-2 shrink-0 rounded-full bg-[#9ca3af]" aria-hidden="true" />
                    <div className="min-w-0">
                      <p className="font-medium text-[#111827]">
                        {h.previous_status ? `${orderStatus(h.previous_status).label} → ` : ''}
                        {orderStatus(h.new_status).label}
                      </p>
                      {h.comment && <p className="text-[#4b5563]">{h.comment}</p>}
                      <p className="text-xs text-[#6b7280]">
                        {formatDateTime(h.created_at)}
                        {h.changed_by_user_id ? '' : ' · system / customer'}
                      </p>
                    </div>
                  </li>
                ))}
              </ol>
            )}
          </Panel>
        </div>

        <div className="flex min-w-0 flex-col gap-4">
          <Panel title="Customer & delivery" bodyClassName="px-4 py-3">
            {order.personal_data_removed_at && (
              <p className="mb-3 rounded-lg bg-[#f9fafb] px-3 py-2 text-[13px] text-[#374151]">
                The customer&apos;s name, contact details and street address were removed on {formatDateTime(order.personal_data_removed_at)}{' '}
                under your <Link href="/admin/data" className="font-semibold underline">data retention setting</Link>. Items, totals and payments are kept.
              </p>
            )}
            <DetailList
              items={[
                { label: 'Name', value: addr.full_name || '—' },
                { label: 'Email', value: order.customer_email || order.guest_email || (order.user_id ? 'Signed-in customer' : '—') },
                { label: 'Phone', value: addr.phone || '—' },
                {
                  label: 'Address',
                  value:
                    [addr.address_line1, addr.address_line2, addr.city, addr.state, addr.postal_code, addr.country].filter(Boolean).join(', ') || '—',
                },
                ...(addr.location && Number.isFinite(Number(addr.location.latitude)) && Number.isFinite(Number(addr.location.longitude))
                  ? [
                      {
                        label: 'Location pin',
                        value: (
                          <a
                            href={`https://www.google.com/maps/search/?api=1&query=${Number(addr.location.latitude)},${Number(addr.location.longitude)}`}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="font-medium text-[#111827] underline"
                          >
                            Open in Google Maps
                            {addr.location.accuracy_m ? ` (±${addr.location.accuracy_m} m)` : ''}
                            <span className="sr-only"> (opens in a new tab)</span>
                          </a>
                        ),
                      },
                    ]
                  : []),
                { label: 'Delivery zone', value: zone?.name ? `${zone.name}${zone.estimated_days ? ` · ${zone.estimated_days}` : ''}` : '—' },
                { label: 'Delivery fee', value: formatPrice(num(order.shipping_fee)) },
                ...(heavyItems > 0
                  ? [
                      {
                        label: 'Large or heavy items',
                        value: heavyItemFee > 0 ? `${heavyItems} × ${formatPrice(heavyItemFee)} (part of the delivery fee)` : String(heavyItems),
                      },
                    ]
                  : []),
              ]}
            />
            {order.age_confirmed_at && (
              <p className="mt-3 text-xs text-[#6b7280]">Age confirmed at checkout: {formatDateTime(order.age_confirmed_at)}</p>
            )}
            {order.notes && (
              <div className="mt-3 rounded-lg bg-[#f9fafb] px-3 py-2 text-[13px] text-[#374151]">
                <p className="text-xs font-semibold text-[#6b7280]">Customer note</p>
                <p className="mt-0.5 whitespace-pre-line">{order.notes}</p>
              </div>
            )}
          </Panel>

          <Panel title="Shipment" bodyClassName="px-4 py-3">
            {order.shipment ? (
              <DetailList
                items={[
                  { label: 'Courier', value: order.shipment.carrier },
                  { label: 'Tracking number', value: order.shipment.tracking_number || '—' },
                  {
                    label: 'Tracking link',
                    value: order.shipment.tracking_url ? (
                      <a
                        href={order.shipment.tracking_url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="inline-flex items-center gap-1 font-medium underline"
                      >
                        Open tracking page
                        <ExternalLink className="size-3" aria-hidden="true" />
                        <span className="sr-only">(opens in a new tab)</span>
                      </a>
                    ) : (
                      '—'
                    ),
                  },
                  { label: 'Status', value: humanize(order.shipment.status) },
                  { label: 'Shipped', value: formatDateTime(order.shipment.shipped_at) },
                  { label: 'Delivered', value: formatDateTime(order.shipment.delivered_at) },
                ]}
              />
            ) : (
              <p className="text-[13px] text-[#6b7280]">Not shipped yet.</p>
            )}
          </Panel>

          {(order.audit_logs ?? []).length > 0 && (
            <Panel title="Admin activity">
              <ul className="divide-y divide-[#f3f4f6]">
                {order.audit_logs.map((a) => (
                  <li key={a.id} className="px-4 py-2.5 text-[13px]">
                    <p className="font-medium text-[#111827]">{humanize(a.action)}</p>
                    <p className="text-xs text-[#6b7280]">
                      {a.admin_name || 'System'} · {formatDateTime(a.created_at)}
                    </p>
                  </li>
                ))}
              </ul>
            </Panel>
          )}
        </div>
      </div>

      <ShipOrderModal orderNumber={shipOpen ? order.order_number : null} onClose={() => setShipOpen(false)} onShipped={reload} />

      <ManualPaymentModal
        open={paymentOpen}
        order={order}
        late={latePayment}
        onClose={() => setPaymentOpen(false)}
        onDone={(updated) => {
          setData(updated);
          setPaymentOpen(false);
        }}
      />

      <StatusChangeModal
        target={statusTarget}
        order={order}
        onClose={() => setStatusTarget(null)}
        onDone={(updated) => {
          setData(updated);
          setStatusTarget(null);
        }}
      />

      <ResolveAttentionModal
        open={resolveOpen}
        order={order}
        onClose={() => setResolveOpen(false)}
        onDone={(updated) => {
          setData(updated);
          setResolveOpen(false);
        }}
      />
    </div>
  );
}

const RESOLVE_NOTE_MIN = 3;
const RESOLVE_NOTE_MAX = 500;

/** Clear the "needs attention" flag; the note says what was done and stays on the order's record. */
function ResolveAttentionModal({
  open,
  order,
  onClose,
  onDone,
}: {
  open: boolean;
  order: OrderDetail;
  onClose: () => void;
  onDone: (order: OrderDetail) => void;
}) {
  const toast = useToast();
  const [note, setNote] = useState('');
  const [error, setError] = useState<string | undefined>();
  const [saving, setSaving] = useState(false);

  // Start empty each time it opens.
  const [wasOpen, setWasOpen] = useState(open);
  if (open !== wasOpen) {
    setWasOpen(open);
    if (open) {
      setNote('');
      setError(undefined);
    }
  }

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    const text = note.trim();
    if (text.length < RESOLVE_NOTE_MIN || text.length > RESOLVE_NOTE_MAX) {
      setError(`Write what you did, in ${RESOLVE_NOTE_MIN} to ${RESOLVE_NOTE_MAX} characters.`);
      return;
    }
    setSaving(true);
    setError(undefined);
    try {
      const updated = await adminResolveAttention(order.order_number, text);
      toast.success('Marked as resolved.');
      onDone(updated);
    } catch (err) {
      if (redirectIfSignedOut(err)) return;
      const message = fieldErrors(err).note;
      if (message) setError(message);
      else toast.error(errorMessage(err));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      busy={saving}
      persistent
      size="sm"
      title="Mark as resolved?"
      description={order.attention_reason || undefined}
      footer={
        <>
          <Button onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button type="submit" form="resolve-attention-form" variant="primary" loading={saving}>
            Mark as resolved
          </Button>
        </>
      }
    >
      <form id="resolve-attention-form" onSubmit={submit} noValidate>
        <Field label="What did you do?" required error={error} hint={`${note.trim().length}/${RESOLVE_NOTE_MAX} characters.`}>
          {(p) => <TextArea {...p} data-autofocus maxLength={RESOLVE_NOTE_MAX} value={note} onChange={(e) => setNote(e.target.value)} />}
        </Field>
      </form>
    </Modal>
  );
}

function StatusChangeModal({
  target,
  order,
  onClose,
  onDone,
}: {
  target: OrderStatus | null;
  order: OrderDetail;
  onClose: () => void;
  onDone: (order: OrderDetail) => void;
}) {
  const toast = useToast();
  const [comment, setComment] = useState('');
  const [saving, setSaving] = useState(false);

  // A note written for one status change never carries over to another.
  const [commentFor, setCommentFor] = useState(target);
  if (target !== commentFor) {
    setCommentFor(target);
    setComment('');
  }

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!target) return;
    setSaving(true);
    try {
      const updated = await adminUpdateOrderStatus(order.order_number, target, comment.trim() || undefined);
      toast.success(`Order moved to "${orderStatus(target).label}".`);
      setComment('');
      onDone(updated);
    } catch (err) {
      if (redirectIfSignedOut(err)) return;
      toast.error(errorMessage(err));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open={!!target}
      onClose={onClose}
      busy={saving}
      persistent
      size="sm"
      title={target ? `${ORDER_TRANSITION_LABELS[target] ?? humanize(target)}?` : ''}
      description={target ? STATUS_CHANGE_HELP[target] : undefined}
      footer={
        <>
          <Button onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button type="submit" form="status-change-form" variant="primary" loading={saving}>
            Confirm
          </Button>
        </>
      }
    >
      <form id="status-change-form" onSubmit={submit} noValidate>
        <Field label="Note for the order history" hint="Optional.">
          {(p) => <TextArea {...p} value={comment} onChange={(e) => setComment(e.target.value)} />}
        </Field>
      </form>
    </Modal>
  );
}
