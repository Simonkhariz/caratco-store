'use client';

import React, { useState } from 'react';
import { adminAssignShipment, errorMessage, fieldErrors } from '@/lib/admin-api';
import { Button, Field, Modal, TextInput, redirectIfSignedOut, useToast } from '@/components/admin/ui';

interface ShipOrderModalProps {
  orderNumber: string | null;
  onClose: () => void;
  /** Called after the shipment was saved (the order is now shipped). */
  onShipped: () => void;
}

/**
 * Record the courier hand-over: carrier and tracking number are required (the customer gets
 * them in the shipment email and on Track order). Moves the order to "shipped".
 */
export function ShipOrderModal({ orderNumber, onClose, onShipped }: ShipOrderModalProps) {
  const toast = useToast();
  const [carrier, setCarrier] = useState('');
  const [trackingNumber, setTrackingNumber] = useState('');
  const [trackingUrl, setTrackingUrl] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  // A fresh form each time it opens for an order.
  const [formFor, setFormFor] = useState(orderNumber);
  if (orderNumber !== formFor) {
    setFormFor(orderNumber);
    if (orderNumber) {
      setCarrier('');
      setTrackingNumber('');
      setTrackingUrl('');
      setErrors({});
    }
  }

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!orderNumber) return;
    const local: Record<string, string> = {};
    if (!carrier.trim()) local.carrier = 'Enter the courier (e.g. GIG Logistics).';
    if (!trackingNumber.trim()) local.tracking_number = 'Enter the tracking or waybill number from the courier.';
    if (trackingUrl.trim() && !/^https:\/\//i.test(trackingUrl.trim())) local.tracking_url = 'The link must start with https://';
    setErrors(local);
    if (Object.keys(local).length) return;

    setSaving(true);
    try {
      await adminAssignShipment(orderNumber, {
        carrier: carrier.trim(),
        tracking_number: trackingNumber.trim(),
        tracking_url: trackingUrl.trim() || undefined,
      });
      toast.success(`${orderNumber} marked as shipped. The customer has been emailed the tracking details.`);
      onShipped();
      onClose();
    } catch (err) {
      if (redirectIfSignedOut(err)) return;
      const fields = fieldErrors(err);
      if (Object.keys(fields).length) setErrors(fields);
      toast.error(errorMessage(err));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open={!!orderNumber}
      onClose={onClose}
      busy={saving}
      persistent
      title={`Ship ${orderNumber ?? ''}`}
      description="Enter the courier details after handing the parcel over."
      footer={
        <>
          <Button onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button type="submit" form="ship-order-form" variant="primary" loading={saving}>
            Mark as shipped
          </Button>
        </>
      }
    >
      <form id="ship-order-form" onSubmit={submit} className="grid gap-4" noValidate>
        <Field label="Courier" required error={errors.carrier}>
          {(p) => <TextInput {...p} data-autofocus maxLength={100} value={carrier} onChange={(e) => setCarrier(e.target.value)} placeholder="e.g. GIG Logistics" />}
        </Field>
        <Field label="Tracking number" required error={errors.tracking_number}>
          {(p) => <TextInput {...p} maxLength={100} value={trackingNumber} onChange={(e) => setTrackingNumber(e.target.value)} />}
        </Field>
        <Field label="Tracking link" error={errors.tracking_url} hint="Optional. The courier's tracking page (https://…).">
          {(p) => <TextInput {...p} type="url" inputMode="url" value={trackingUrl} onChange={(e) => setTrackingUrl(e.target.value)} />}
        </Field>
      </form>
    </Modal>
  );
}
