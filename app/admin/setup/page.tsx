'use client';

import React, { useEffect, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { Store } from 'lucide-react';
import { SetupStorePayload, apiGetSetupStatus, apiSetupStore } from '@/lib/api-client';
import { fieldErrors } from '@/lib/admin-api';
import { NIGERIAN_STATES } from '@/lib/nigeria';
import { Button, Field, LoadingState, Notice, Select, TextInput } from '@/components/admin/ui';

interface SetupForm extends SetupStorePayload {
  confirm_password: string;
}

const EMPTY: SetupForm = {
  name: '',
  email: '',
  password: '',
  confirm_password: '',
  store_name: '',
  contact_email: '',
  contact_phone: '',
  origin_address: '',
  origin_city: '',
  origin_state: '',
};

/**
 * One-time store setup: creates the first administrator and the store details.
 * The backend closes this endpoint permanently once an admin exists.
 */
export default function AdminSetupPage() {
  const router = useRouter();
  const [checking, setChecking] = useState(true);
  const [unreachable, setUnreachable] = useState(false);
  const [form, setForm] = useState<SetupForm>(EMPTY);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    apiGetSetupStatus()
      .then((status) => {
        if (status.is_setup_completed) router.replace('/admin/login');
        else setChecking(false);
      })
      .catch(() => {
        setUnreachable(true);
        setChecking(false);
      });
  }, [router]);

  const update = (key: keyof SetupForm) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) =>
    setForm((f) => ({ ...f, [key]: e.target.value }));

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};
    if (form.password.length < 10) local.password = 'Use at least 10 characters.';
    if (form.password !== form.confirm_password) local.confirm_password = 'The passwords do not match.';
    if (!form.origin_state) local.origin_state = 'Choose the state you ship from.';
    setErrors(local);
    setFormError(null);
    if (Object.keys(local).length > 0) return;

    setSubmitting(true);
    try {
      const payload: SetupStorePayload = {
        name: form.name,
        email: form.email,
        password: form.password,
        store_name: form.store_name,
        contact_email: form.contact_email || form.email,
        contact_phone: form.contact_phone,
        origin_address: form.origin_address,
        origin_city: form.origin_city,
        origin_state: form.origin_state,
      };
      await apiSetupStore(payload);
      router.replace('/admin');
    } catch (err) {
      const fields = fieldErrors(err);
      // The backend reports the address trio under origin_address.
      setErrors(fields);
      setFormError(Object.keys(fields).length ? 'Please fix the highlighted fields.' : err instanceof Error ? err.message : 'Setup failed.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <main className="flex min-h-dvh items-center justify-center bg-[#f8f8f8] px-4 py-10">
      <div className="w-full max-w-xl rounded-2xl border border-[#e5e7eb] bg-white p-6 shadow-sm sm:p-8">
        {checking ? (
          <LoadingState label="Checking store setup…" />
        ) : unreachable ? (
          <Notice tone="danger">The store server is not responding. Check that the backend is running, then reload this page.</Notice>
        ) : (
          <form onSubmit={handleSubmit} className="space-y-6" noValidate>
            <div className="flex flex-col items-center gap-2 text-center">
              <div className="flex size-12 items-center justify-center rounded-full bg-[#f3f4f6] text-[#111827]">
                <Store className="size-5" aria-hidden="true" />
              </div>
              <h1 className="text-xl font-bold text-[#111827]">Set up your store</h1>
              <p className="text-sm text-[#6b7280]">
                Create the administrator account and tell customers who you are. This page closes once setup is done.
              </p>
            </div>

            {formError && (
              <p role="alert" className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                {formError}
              </p>
            )}

            <fieldset className="grid gap-4 sm:grid-cols-2">
              <legend className="mb-2 text-sm font-semibold text-[#111827]">Administrator</legend>
              <Field label="Full name" required error={errors.name} className="sm:col-span-2">
                {(p) => <TextInput {...p} autoComplete="name" value={form.name} onChange={update('name')} />}
              </Field>
              <Field label="Email" required error={errors.email} className="sm:col-span-2">
                {(p) => <TextInput {...p} type="email" autoComplete="username" value={form.email} onChange={update('email')} />}
              </Field>
              <Field label="Password" required error={errors.password} hint="At least 10 characters.">
                {(p) => <TextInput {...p} type="password" autoComplete="new-password" value={form.password} onChange={update('password')} />}
              </Field>
              <Field label="Confirm password" required error={errors.confirm_password}>
                {(p) => <TextInput {...p} type="password" autoComplete="new-password" value={form.confirm_password} onChange={update('confirm_password')} />}
              </Field>
            </fieldset>

            <fieldset className="grid gap-4 sm:grid-cols-2">
              <legend className="mb-2 text-sm font-semibold text-[#111827]">Store</legend>
              <Field label="Store name" required error={errors.store_name} className="sm:col-span-2">
                {(p) => <TextInput {...p} value={form.store_name} onChange={update('store_name')} />}
              </Field>
              <Field label="Support email" error={errors.contact_email} hint="Shown to customers. Defaults to your email.">
                {(p) => <TextInput {...p} type="email" value={form.contact_email} onChange={update('contact_email')} />}
              </Field>
              <Field label="Support phone" error={errors.contact_phone}>
                {(p) => <TextInput {...p} type="tel" autoComplete="tel" value={form.contact_phone} onChange={update('contact_phone')} />}
              </Field>
              <Field label="Dispatch address" required error={errors.origin_address} className="sm:col-span-2" hint="Where parcels ship from.">
                {(p) => <TextInput {...p} autoComplete="street-address" value={form.origin_address} onChange={update('origin_address')} />}
              </Field>
              <Field label="City" required>
                {(p) => <TextInput {...p} autoComplete="address-level2" value={form.origin_city} onChange={update('origin_city')} />}
              </Field>
              <Field label="State" required error={errors.origin_state}>
                {(p) => (
                  <Select {...p} value={form.origin_state} onChange={update('origin_state')}>
                    <option value="">Choose a state</option>
                    {NIGERIAN_STATES.map((s) => (
                      <option key={s} value={s}>
                        {s}
                      </option>
                    ))}
                  </Select>
                )}
              </Field>
            </fieldset>

            <Button type="submit" variant="primary" className="w-full" loading={submitting}>
              {submitting ? 'Setting up…' : 'Create store and sign in'}
            </Button>

            <p className="text-center text-xs text-[#6b7280]">
              <Link href="/" className="hover:text-black hover:underline">
                Back to the store
              </Link>
            </p>
          </form>
        )}
      </div>
    </main>
  );
}
