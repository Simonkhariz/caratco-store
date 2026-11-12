'use client';

import React, { useState } from 'react';
import { Copy, Trash2, UserPlus } from 'lucide-react';
import {
  AdminTeamMember,
  adminCreateAdmin,
  adminDeleteAdmin,
  adminGetAccount,
  adminListAdmins,
  adminSetAdminStatus,
  adminUpdateAccount,
  errorMessage,
  fieldErrors,
} from '@/lib/admin-api';
import { formatDate, humanize } from '@/lib/admin-format';
import { useAdminSession } from '@/components/admin/AdminAuthGate';
import {
  Button,
  DataState,
  DetailList,
  Field,
  Modal,
  Notice,
  PageHeader,
  Panel,
  StatusPill,
  Table,
  TextInput,
  redirectIfSignedOut,
  useAdminData,
  useToast,
} from '@/components/admin/ui';

const MIN_PASSWORD = 10;

/**
 * The signed-in administrator's profile, sign-in email and password, and the admin team
 * (add an admin, switch one off or back on).
 * Changing the password signs out every other admin session.
 */
export function AdminAccount() {
  const toast = useToast();
  const { updateAdmin } = useAdminSession();
  const { data: account, loading, error, reload } = useAdminData('AdminAccount', () => adminGetAccount(), []);

  const [fullName, setFullName] = useState('');
  const [phone, setPhone] = useState('');
  const [email, setEmail] = useState('');
  const [emailPassword, setEmailPassword] = useState('');
  const [profileErrors, setProfileErrors] = useState<Record<string, string>>({});
  const [savingProfile, setSavingProfile] = useState(false);

  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [passwordErrors, setPasswordErrors] = useState<Record<string, string>>({});
  const [savingPassword, setSavingPassword] = useState(false);

  // Fill the profile form when the account loads. When it loads again (shown from memory first,
  // then checked with the server), a field being typed in is left alone; after a save the whole
  // form follows the answer.
  const [formFor, setFormFor] = useState<typeof account>(null);
  const [followAll, setFollowAll] = useState(false);
  if (account && account !== formFor) {
    const untouched = (typed: string, before: string | null | undefined) => formFor === null || followAll || typed === (before ?? '');
    if (untouched(fullName, formFor?.full_name)) setFullName(account.full_name ?? '');
    if (untouched(phone, formFor?.phone)) setPhone(account.phone ?? '');
    if (untouched(email, formFor?.email)) {
      setEmail(account.email ?? '');
      setEmailPassword('');
    }
    setFormFor(account);
    setFollowAll(false);
  }

  const emailChanged = !!account && email.trim().toLowerCase() !== (account.email ?? '').toLowerCase();
  const profileChanged =
    !!account && (fullName.trim() !== (account.full_name ?? '') || phone.trim() !== (account.phone ?? '') || emailChanged);

  const handleProfile = async (e: React.FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};
    if (!fullName.trim()) local.full_name = 'Name cannot be empty.';
    if (emailChanged && !email.trim()) local.email = 'Enter an email address.';
    if (emailChanged && !emailPassword) local.current_password = 'Enter your current password to change the sign-in email.';
    setProfileErrors(local);
    if (Object.keys(local).length > 0) return;

    setSavingProfile(true);
    try {
      const res = await adminUpdateAccount({
        full_name: fullName.trim(),
        phone: phone.trim(),
        ...(emailChanged ? { email: email.trim(), current_password: emailPassword } : {}),
      });
      toast.success(res.message || 'Profile updated.');
      updateAdmin({ full_name: fullName.trim(), phone: phone.trim() || null, ...(emailChanged ? { email: res.data?.email ?? email.trim().toLowerCase() } : {}) });
      setEmailPassword('');
      setFollowAll(true);
      reload();
    } catch (err) {
      if (redirectIfSignedOut(err)) return;
      const fields = fieldErrors(err);
      setProfileErrors(fields);
      if (Object.keys(fields).length === 0) toast.error(errorMessage(err));
    } finally {
      setSavingProfile(false);
    }
  };

  const handlePassword = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!account) return;
    const local: Record<string, string> = {};
    if (!currentPassword) local.current_password = 'Enter your current password.';
    if (newPassword.length < MIN_PASSWORD) local.new_password = `Use at least ${MIN_PASSWORD} characters.`;
    if (newPassword !== confirmPassword) local.confirm_password = 'The passwords do not match.';
    setPasswordErrors(local);
    if (Object.keys(local).length > 0) return;

    setSavingPassword(true);
    try {
      // Only the password: the server keeps the name and phone it has (the copy on this screen
      // could be an old one).
      const res = await adminUpdateAccount({ current_password: currentPassword, new_password: newPassword });
      toast.success(res.message || 'Password changed.');
      setCurrentPassword('');
      setNewPassword('');
      setConfirmPassword('');
    } catch (err) {
      if (redirectIfSignedOut(err)) return;
      const fields = fieldErrors(err);
      setPasswordErrors(fields);
      if (Object.keys(fields).length === 0) toast.error(errorMessage(err));
    } finally {
      setSavingPassword(false);
    }
  };

  return (
    <div className="flex flex-col gap-4">
      <PageHeader title="My account" description="Your administrator profile, sign-in details and the admin team." />

      <DataState loading={loading} error={error} onRetry={reload} hasData={!!account} empty={false} skeleton="form">
        {account && (
          <div className="grid gap-4 lg:grid-cols-2">
            <Panel title="Profile" bodyClassName="p-4">
              <div className="mb-4">
                <DetailList
                  items={[
                    { label: 'Role', value: humanize(account.role) },
                    { label: 'Member since', value: formatDate(account.created_at) },
                  ]}
                />
              </div>
              <form onSubmit={handleProfile} className="flex flex-col gap-4" noValidate>
                <Field label="Full name" required error={profileErrors.full_name}>
                  {(p) => <TextInput {...p} autoComplete="name" value={fullName} onChange={(e) => setFullName(e.target.value)} />}
                </Field>
                <Field
                  label="Email (used to sign in)"
                  required
                  error={profileErrors.email}
                  hint="Admin alerts go to this email too, unless Settings → “Send admin alerts to” names other addresses."
                >
                  {(p) => <TextInput {...p} type="email" autoComplete="email" value={email} onChange={(e) => setEmail(e.target.value)} />}
                </Field>
                {emailChanged && (
                  <Field label="Current password" required error={profileErrors.current_password} hint="Needed to change the sign-in email.">
                    {(p) => (
                      <TextInput {...p} type="password" autoComplete="current-password" value={emailPassword} onChange={(e) => setEmailPassword(e.target.value)} />
                    )}
                  </Field>
                )}
                <Field label="Phone" error={profileErrors.phone}>
                  {(p) => <TextInput {...p} type="tel" autoComplete="tel" value={phone} onChange={(e) => setPhone(e.target.value)} />}
                </Field>
                <div>
                  <Button type="submit" variant="primary" loading={savingProfile} disabled={!profileChanged}>
                    Save profile
                  </Button>
                </div>
              </form>
            </Panel>

            <Panel title="Change password" bodyClassName="p-4">
              <form onSubmit={handlePassword} className="flex flex-col gap-4" noValidate>
                {/* Hidden username helps password managers pair the new password with this account. */}
                <input type="email" autoComplete="username" value={account.email} readOnly hidden />
                <Field label="Current password" required error={passwordErrors.current_password}>
                  {(p) => (
                    <TextInput {...p} type="password" autoComplete="current-password" value={currentPassword} onChange={(e) => setCurrentPassword(e.target.value)} />
                  )}
                </Field>
                <Field label="New password" required error={passwordErrors.new_password} hint={`At least ${MIN_PASSWORD} characters.`}>
                  {(p) => <TextInput {...p} type="password" autoComplete="new-password" value={newPassword} onChange={(e) => setNewPassword(e.target.value)} />}
                </Field>
                <Field label="Confirm new password" required error={passwordErrors.confirm_password}>
                  {(p) => (
                    <TextInput {...p} type="password" autoComplete="new-password" value={confirmPassword} onChange={(e) => setConfirmPassword(e.target.value)} />
                  )}
                </Field>
                <p className="text-xs text-[#6b7280]">Changing your password signs out every other device where you are signed in to the admin.</p>
                <div>
                  <Button type="submit" variant="primary" loading={savingPassword}>
                    Change password
                  </Button>
                </div>
              </form>
            </Panel>
          </div>
        )}
      </DataState>

      <AdminTeam />
    </div>
  );
}

const emptyAdminForm = { full_name: '', email: '', password: '', current_password: '' };

/** The admin accounts, with "Add admin", switching another admin off or back on, and deleting one. */
function AdminTeam() {
  const toast = useToast();
  const { admin: me } = useAdminSession();
  const { data, loading, error, reload } = useAdminData('AdminTeam', () => adminListAdmins(), []);
  const admins = data?.items ?? [];
  // Only an owner can switch off or delete an owner (the server refuses anyone else).
  const canChange = (a: AdminTeamMember) => !a.is_you && (a.role !== 'super_admin' || me.role === 'super_admin');
  const minPassword = data?.min_password_length ?? MIN_PASSWORD;

  const [adding, setAdding] = useState(false);
  const [form, setForm] = useState(emptyAdminForm);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [created, setCreated] = useState<{ email: string; password: string } | null>(null);

  // One confirmation window for both actions on another admin: switch off/on, or delete.
  const [changing, setChanging] = useState<AdminTeamMember | null>(null);
  const [deleting, setDeleting] = useState(false);
  const [statusPassword, setStatusPassword] = useState('');
  const [statusError, setStatusError] = useState('');
  const [savingStatus, setSavingStatus] = useState(false);

  const openAdd = () => {
    setForm(emptyAdminForm);
    setErrors({});
    setCreated(null);
    setAdding(true);
  };

  const handleAdd = async (e: React.FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};
    if (!form.full_name.trim()) local.full_name = 'Enter their full name.';
    if (!form.email.trim()) local.email = 'Enter their email address.';
    if (form.password && form.password.length < minPassword) local.password = `At least ${minPassword} characters, or leave empty to make one up.`;
    if (!form.current_password) local.current_password = 'Enter your own password to confirm.';
    setErrors(local);
    if (Object.keys(local).length > 0) return;

    setSaving(true);
    try {
      const res = await adminCreateAdmin({
        full_name: form.full_name.trim(),
        email: form.email.trim(),
        password: form.password || undefined,
        current_password: form.current_password,
      });
      toast.success(res.message || 'Admin added.');
      reload();
      const generated = res.data?.generated_password;
      if (generated) {
        // Shown once, in the same window, so the creator can copy it.
        setCreated({ email: res.data?.email ?? form.email.trim(), password: generated });
      } else {
        setAdding(false);
      }
      setForm(emptyAdminForm);
    } catch (err) {
      if (redirectIfSignedOut(err)) return;
      const fields = fieldErrors(err);
      setErrors(fields);
      if (Object.keys(fields).length === 0) toast.error(errorMessage(err));
    } finally {
      setSaving(false);
    }
  };

  const closeAdd = () => {
    setAdding(false);
    setCreated(null);
  };

  const copyPassword = async () => {
    if (!created) return;
    try {
      await navigator.clipboard.writeText(created.password);
      toast.success('Password copied.');
    } catch {
      toast.error('Could not copy. Select the password and copy it by hand.');
    }
  };

  const openStatus = (admin: AdminTeamMember, remove = false) => {
    setChanging(admin);
    setDeleting(remove);
    setStatusPassword('');
    setStatusError('');
  };

  const handleStatus = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!changing) return;
    if (!statusPassword) {
      setStatusError('Enter your own password to confirm.');
      return;
    }
    setSavingStatus(true);
    try {
      const res = deleting
        ? await adminDeleteAdmin(changing.id, statusPassword)
        : await adminSetAdminStatus(changing.id, changing.status === 'active' ? 'suspended' : 'active', statusPassword);
      toast.success(res.message || (deleting ? 'Admin deleted.' : 'Saved.'));
      setChanging(null);
      reload();
    } catch (err) {
      if (redirectIfSignedOut(err)) return;
      const fields = fieldErrors(err);
      if (fields.current_password) setStatusError(fields.current_password);
      else toast.error(errorMessage(err));
    } finally {
      setSavingStatus(false);
    }
  };

  const switchingOff = !deleting && changing?.status === 'active';
  const actionLabel = deleting ? 'Delete' : switchingOff ? 'Switch off' : 'Switch on';

  return (
    <Panel
      title="Admin team"
      actions={
        <Button size="sm" variant="primary" onClick={openAdd}>
          <UserPlus className="size-3.5" aria-hidden="true" />
          Add admin
        </Button>
      }
    >
      <DataState loading={loading} error={error} onRetry={reload} hasData={!!data} empty={admins.length === 0} emptyTitle="No admin accounts">
        <Table minWidth={720}>
          <thead>
            <tr>
              <th>Name</th>
              <th>Email</th>
              <th>Role</th>
              <th>Last seen</th>
              <th>Status</th>
              <th aria-label="Actions" />
            </tr>
          </thead>
          <tbody>
            {admins.map((a) => (
              <tr key={a.id}>
                <td className="text-[#111827]">
                  {a.full_name}
                  {a.is_you && <span className="ml-1.5 text-xs text-[#6b7280]">(you)</span>}
                </td>
                <td className="max-w-[260px] truncate text-[#6b7280]">{a.email}</td>
                <td className="text-[#6b7280]">{a.role === 'super_admin' ? 'Owner' : humanize(a.role)}</td>
                <td className="whitespace-nowrap text-[#6b7280]">{a.last_seen_at ? formatDate(a.last_seen_at) : '—'}</td>
                <td>{a.status === 'active' ? <StatusPill label="Active" tone="green" /> : <StatusPill label="Switched off" tone="neutral" />}</td>
                <td className="text-right">
                  {canChange(a) && (
                    <span className="inline-flex items-center justify-end gap-1.5">
                      <Button size="sm" variant={a.status === 'active' ? 'ghost' : 'secondary'} onClick={() => openStatus(a)}>
                        {a.status === 'active' ? 'Switch off' : 'Switch on'}
                      </Button>
                      <Button size="sm" variant="ghost" onClick={() => openStatus(a, true)} aria-label={`Delete ${a.full_name}`} title="Delete admin">
                        <Trash2 className="size-3.5 text-red-600" aria-hidden="true" />
                      </Button>
                    </span>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </Table>
      </DataState>

      <Modal
        open={adding}
        onClose={closeAdd}
        busy={saving}
        persistent
        title={created ? 'Admin added' : 'Add an admin'}
        description={created ? undefined : 'They can sign in to this admin straight away, with the same access as you.'}
        footer={
          created ? (
            <Button variant="primary" onClick={closeAdd}>
              Done
            </Button>
          ) : (
            <>
              <Button onClick={closeAdd} disabled={saving}>
                Cancel
              </Button>
              <Button variant="primary" type="submit" form="add-admin-form" loading={saving}>
                Add admin
              </Button>
            </>
          )
        }
      >
        {created ? (
          <div className="flex flex-col gap-3">
            <p className="text-sm text-[#374151]">
              Give <strong>{created.email}</strong> this password. It is shown only now; ask them to change it under My account after
              signing in.
            </p>
            <div className="flex items-center gap-2">
              <code className="min-w-0 flex-1 select-all rounded-lg border border-[#e5e7eb] bg-[#f9fafb] px-3 py-2 font-mono text-sm text-[#111827]">
                {created.password}
              </code>
              <Button size="sm" onClick={copyPassword}>
                <Copy className="size-3.5" aria-hidden="true" />
                Copy
              </Button>
            </div>
          </div>
        ) : (
          <form id="add-admin-form" onSubmit={handleAdd} className="flex flex-col gap-4" noValidate>
            <Field label="Full name" required error={errors.full_name}>
              {(p) => <TextInput {...p} data-autofocus autoComplete="off" value={form.full_name} onChange={(e) => setForm((f) => ({ ...f, full_name: e.target.value }))} />}
            </Field>
            <Field label="Email (they sign in with it)" required error={errors.email}>
              {(p) => <TextInput {...p} type="email" autoComplete="off" value={form.email} onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))} />}
            </Field>
            <Field label="Password" error={errors.password} hint={`At least ${minPassword} characters. Leave empty and one is made up for you (shown once).`}>
              {(p) => (
                <TextInput {...p} type="password" autoComplete="new-password" value={form.password} onChange={(e) => setForm((f) => ({ ...f, password: e.target.value }))} />
              )}
            </Field>
            <Field label="Your own password" required error={errors.current_password} hint="To confirm it is really you adding an admin.">
              {(p) => (
                <TextInput
                  {...p}
                  type="password"
                  autoComplete="current-password"
                  value={form.current_password}
                  onChange={(e) => setForm((f) => ({ ...f, current_password: e.target.value }))}
                />
              )}
            </Field>
          </form>
        )}
      </Modal>

      <Modal
        open={!!changing}
        onClose={() => setChanging(null)}
        busy={savingStatus}
        persistent
        title={changing ? `${actionLabel} ${changing.full_name}?` : ''}
        footer={
          <>
            <Button onClick={() => setChanging(null)} disabled={savingStatus}>
              Cancel
            </Button>
            <Button variant={deleting || switchingOff ? 'danger' : 'primary'} type="submit" form="admin-status-form" loading={savingStatus}>
              {deleting ? 'Delete admin' : actionLabel}
            </Button>
          </>
        }
      >
        <form id="admin-status-form" onSubmit={handleStatus} className="flex flex-col gap-4" noValidate>
          <Notice tone={deleting || switchingOff ? 'warning' : 'info'}>
            {deleting
              ? `This removes ${changing?.email ?? 'the account'} for good: they are signed out everywhere and cannot sign in again. It cannot be undone. Orders, stock changes and the activity log keep what they did, with their name. To stop them only for a while, use Switch off instead.`
              : switchingOff
                ? 'They are signed out everywhere and cannot sign in until an admin switches them back on. Nothing is deleted.'
                : 'They can sign in again with their existing password.'}
          </Notice>
          <Field label="Your own password" required error={statusError}>
            {(p) => (
              <TextInput {...p} data-autofocus type="password" autoComplete="current-password" value={statusPassword} onChange={(e) => setStatusPassword(e.target.value)} />
            )}
          </Field>
        </form>
      </Modal>
    </Panel>
  );
}
