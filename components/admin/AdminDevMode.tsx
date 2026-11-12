'use client';

import React, { useState } from 'react';
import Link from 'next/link';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import {
  type DevMode,
  type DevSwitch,
  adminResetDevSwitches,
  adminSetDevMode,
  adminUpdateDevSwitch,
  errorMessage,
  fieldErrors,
} from '@/lib/admin-api';
import { useAdminSession } from '@/components/admin/AdminAuthGate';
import { devModeQuery } from '@/lib/admin-queries';
import {
  Button,
  DataState,
  ErrorState,
  Field,
  LoadingState,
  Notice,
  PageHeader,
  Panel,
  StatusPill,
  Switch,
  TextInput,
  redirectIfSignedOut,
  usePendingValues,
  useToast,
} from '@/components/admin/ui';

function useDevMode() {
  const { admin } = useAdminSession();
  return useQuery(devModeQuery(admin.id));
}

const LINK_CLASS = 'font-semibold text-[#111827] underline-offset-2 hover:underline';

/**
 * Wraps a dev mode page. With dev mode off the page's content never loads (the API refuses it
 * anyway), and the admin is told how to turn it on. Links to these pages can still arrive here,
 * such as the database alert in the bell.
 */
export function DevModeGate({ title, children }: { title: string; children: React.ReactNode }) {
  const devMode = useDevMode();
  if (devMode.status === 'pending') return <LoadingState />;
  if (devMode.status === 'error') return <ErrorState message={errorMessage(devMode.error)} onRetry={() => void devMode.refetch()} />;
  if (devMode.data.on) return <>{children}</>;
  return (
    <div className="flex flex-col gap-4">
      <PageHeader title={title} description="This is a dev mode page." />
      <Panel title="Dev mode is off" bodyClassName="p-4">
        <p className="text-sm text-[#374151]">
          Turn on dev mode in{' '}
          <Link href="/admin/settings#dev-mode" className={LINK_CLASS}>
            Store settings
          </Link>{' '}
          to open this page. You will need your password.
        </p>
        <p className="mt-2 text-xs text-[#6b7280]">Database alerts still appear in the bell while dev mode is off. Nothing is ever cleaned up automatically.</p>
      </Panel>
    </div>
  );
}

/** The on/off control in Store settings. */
export function DevModePanel() {
  const toast = useToast();
  const devMode = useDevMode();
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | undefined>();
  const [busy, setBusy] = useState(false);
  const on = devMode.data?.on === true;

  const turnOn = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(undefined);
    try {
      await adminSetDevMode(true, password);
      setPassword('');
      toast.success('Dev mode is on.');
    } catch (err) {
      if (redirectIfSignedOut(err)) return;
      const message = fieldErrors(err).password ?? errorMessage(err, 'Dev mode could not be turned on.');
      setError(message);
    } finally {
      setBusy(false);
    }
  };

  const turnOff = async () => {
    setBusy(true);
    try {
      await adminSetDevMode(false);
      toast.success('Dev mode is off.');
    } catch (err) {
      if (!redirectIfSignedOut(err)) toast.error(errorMessage(err, 'Dev mode could not be turned off.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div id="dev-mode" className="scroll-mt-20">
      <Panel title="Dev mode" bodyClassName="p-4">
        <DataState loading={devMode.status === 'pending'} error={devMode.status === 'error' ? errorMessage(devMode.error) : null} onRetry={() => void devMode.refetch()} hasData={!!devMode.data} empty={false}>
          {on ? (
            <div className="flex flex-col gap-3">
              <p className="flex flex-wrap items-center gap-2 text-sm text-[#374151]">
                <StatusPill label="On" tone="green" />
                The technical pages are open to every admin until it is turned off.
              </p>
              <ul className="flex flex-col gap-1 text-sm">
                <li><Link href="/admin/dev" className={LINK_CLASS}>Tech switches</Link></li>
                <li><Link href="/admin/data" className={LINK_CLASS}>Data &amp; storage</Link></li>
                <li><Link href="/admin/system" className={LINK_CLASS}>System status</Link></li>
              </ul>
              <div>
                <Button variant="secondary" loading={busy} onClick={() => void turnOff()}>Turn off dev mode</Button>
              </div>
            </div>
          ) : (
            <form onSubmit={turnOn} className="flex flex-col gap-3" noValidate>
              <p className="text-sm text-[#374151]">
                Opens the technical pages: Tech switches, Data &amp; storage and System status. They are meant for the developer, so they stay
                hidden until needed.
              </p>
              <Field label="Your password" error={error} className="max-w-sm">
                {(p) => <TextInput {...p} type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} />}
              </Field>
              <div>
                <Button type="submit" variant="primary" loading={busy} disabled={password === ''}>Turn on dev mode</Button>
              </div>
            </form>
          )}
        </DataState>
      </Panel>
    </div>
  );
}

function SwitchRow({ item, shownOn, disabled, onToggle }: { item: DevSwitch; shownOn: boolean; disabled: boolean; onToggle: (on: boolean) => void }) {
  const labelId = `dev-switch-${item.key}`;
  const differs = item.available && item.on !== item.recommended;
  // Not set up on this server: it is not running, whatever the saved choice says.
  const on = shownOn && item.available;
  return (
    <li className="flex items-start justify-between gap-4 px-4 py-3">
      <div className="min-w-0">
        <p id={labelId} className="flex flex-wrap items-center gap-2 text-sm font-semibold text-[#111827]">
          {item.label}
          {!item.available && <StatusPill label="Not set up" tone="neutral" />}
          {differs && <StatusPill label={`Recommended: ${item.recommended ? 'on' : 'off'}`} tone="amber" />}
        </p>
        <p className="mt-0.5 text-xs text-[#6b7280]">{item.what}</p>
        <p className="mt-0.5 text-xs text-[#6b7280]">
          <span className="font-semibold">When off:</span> {item.when_off}
        </p>
        {item.note && <p className="mt-1 text-xs font-medium text-amber-700">{item.note}</p>}
      </div>
      <Switch on={on} onChange={onToggle} labelledBy={labelId} disabled={disabled || !item.available} className="mt-0.5" />
    </li>
  );
}

function SwitchesContent({ data }: { data: DevMode }) {
  const toast = useToast();
  const [busy, setBusy] = useState(false);

  const run = async (change: () => Promise<unknown>, done: string) => {
    setBusy(true);
    try {
      await change();
      toast.success(done);
    } catch (err) {
      if (!redirectIfSignedOut(err)) toast.error(errorMessage(err, 'The change could not be saved.'));
    } finally {
      setBusy(false);
    }
  };

  // The switch moves at once; the saved state comes back with the answer (or it moves back).
  const queryClient = useQueryClient();
  const { admin } = useAdminSession();
  const pending = usePendingValues<boolean>();
  const toggle = async (item: DevSwitch, on: boolean) => {
    if (pending.isPending(item.key)) return; // one save per switch at a time
    pending.show(item.key, on);
    try {
      const fresh = await adminUpdateDevSwitch(item.key, on);
      queryClient.setQueryData(devModeQuery(admin.id).queryKey, fresh);
      toast.success(`${item.label} is now ${on ? 'on' : 'off'}.`);
    } catch (err) {
      if (!redirectIfSignedOut(err)) toast.error(errorMessage(err, 'The change could not be saved.'));
    } finally {
      pending.settle(item.key);
    }
  };

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title="Tech switches"
        description="Turn the shop's scalability technology on or off. Changes apply within a minute and are recorded in the audit log."
      />
      {(data.warnings ?? []).map((warning) => (
        <Notice key={warning} tone="warning">{warning}</Notice>
      ))}
      <Panel
        title="Switches"
        bodyClassName="p-0"
        actions={
          <Button variant="ghost" size="sm" disabled={busy} onClick={() => void run(() => adminResetDevSwitches(), 'Switches are back to their defaults.')}>
            Reset to defaults
          </Button>
        }
      >
        <ul className="divide-y divide-[#f3f4f6]">
          {(data.switches ?? []).map((item) => (
            <SwitchRow
              key={item.key}
              item={item}
              shownOn={pending.valueOf(item.key, item.on)}
              disabled={busy}
              onToggle={(on) => void toggle(item, on)}
            />
          ))}
        </ul>
      </Panel>
      <Panel title="No switch, on purpose" bodyClassName="p-0">
        <ul className="divide-y divide-[#f3f4f6]">
          {(data.fixed ?? []).map((item) => (
            <li key={item.name} className="px-4 py-3">
              <p className="flex flex-wrap items-center gap-2 text-sm font-semibold text-[#111827]">
                {item.name}
                <StatusPill label={item.state} tone={item.state === 'Off' ? 'amber' : 'neutral'} />
              </p>
              <p className="mt-0.5 text-xs text-[#6b7280]">{item.why}</p>
            </li>
          ))}
        </ul>
      </Panel>
    </div>
  );
}

/** Dev mode page: the switches. */
export function AdminDevSwitches() {
  const devMode = useDevMode();
  return <DevModeGate title="Tech switches">{devMode.data && <SwitchesContent data={devMode.data} />}</DevModeGate>;
}
