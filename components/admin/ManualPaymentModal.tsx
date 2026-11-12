'use client';

import React, { useState } from 'react';
import { AdminOrderDetail as OrderDetail, adminConfirmPayment, errorMessage, fieldErrors } from '@/lib/admin-api';
import { formatPrice, num } from '@/lib/admin-format';
import { Button, Field, Modal, TextArea, TextInput, redirectIfSignedOut, useToast } from '@/components/admin/ui';

/**
 * Record a bank transfer / WhatsApp payment the admin has seen arrive. Settles the customer's
 * open WhatsApp payment (no second payment row) and marks the order paid.
 */
export function ManualPaymentModal({
  open,
  order,
  late = false,
  onClose,
  onDone,
}: {
  open: boolean;
  order: Pick<OrderDetail, 'order_number' | 'grand_total'> | null;
  /** The order was cancelled before the transfer was checked: it stays cancelled. */
  late?: boolean;
  onClose: () => void;
  onDone: (order: OrderDetail) => void;
}) {
  const toast = useToast();
  const [reference, setReference] = useState('');
  const [note, setNote] = useState('');
  const [error, setError] = useState<string | undefined>();
  const [saving, setSaving] = useState(false);

  // Start empty for each order opened from a list.
  const orderNumber = order?.order_number;
  const [formFor, setFormFor] = useState(orderNumber);
  if (orderNumber !== formFor) {
    setFormFor(orderNumber);
    setReference('');
    setNote('');
    setError(undefined);
  }

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!order) return;
    if (reference.trim().length < 4) {
      setError('Enter the bank or transfer reference (at least 4 characters).');
      return;
    }
    setSaving(true);
    try {
      const updated = await adminConfirmPayment(order.order_number, { reference: reference.trim(), note: note.trim() || undefined });
      // The order may have expired while this window was open: the answer says what happened.
      const keptCancelled = late || updated?.order_status === 'cancelled';
      toast.success(keptCancelled ? 'Late payment recorded. The order stays cancelled: refund the customer or mark it as resolved.' : 'Payment recorded. The order is now paid.');
      setReference('');
      setNote('');
      setError(undefined);
      onDone(updated);
    } catch (err) {
      if (redirectIfSignedOut(err)) return;
      setError(fieldErrors(err).reference);
      toast.error(errorMessage(err));
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
      title={order ? `Record ${late ? 'late ' : ''}payment for ${order.order_number}` : 'Record payment'}
      description={
        <>
          Only record a payment after you have seen <strong>{formatPrice(num(order?.grand_total))}</strong> arrive in the store&apos;s account.
          {late && (
            <>
              {' '}
              It is recorded as a late payment: <strong>the order stays cancelled</strong> and its items stay back on sale. Afterwards, refund the
              customer or mark the order as resolved.
            </>
          )}
        </>
      }
      footer={
        <>
          <Button onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button type="submit" form="manual-payment-form" variant="primary" loading={saving}>
            {late ? 'Record late payment' : 'Record payment'}
          </Button>
        </>
      }
    >
      <form id="manual-payment-form" onSubmit={submit} className="grid gap-4" noValidate>
        <Field label="Bank / transfer reference" required error={error} hint="As shown on your bank statement or the customer's receipt.">
          {(p) => <TextInput {...p} data-autofocus value={reference} onChange={(e) => setReference(e.target.value)} />}
        </Field>
        <Field label="Note" hint="Optional, e.g. the sender's name.">
          {(p) => <TextArea {...p} value={note} onChange={(e) => setNote(e.target.value)} />}
        </Field>
      </form>
    </Modal>
  );
}
