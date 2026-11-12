'use client';

import React, { useRef, useState } from 'react';
import Image from 'next/image';
import { ImageOff, Plus, Upload } from 'lucide-react';
import { canOptimizeImage } from '@/lib/image';
import { isNumericSlug, slugify, wholeNumber } from '@/lib/admin-logic';
import { apiUploadProductImage } from '@/lib/api-client';
import { AdminCategory, adminDeactivateCategory, adminGetCategories, adminSaveCategory, errorMessage, fieldErrors } from '@/lib/admin-api';
import {
  Button,
  Checkbox,
  DataState,
  Pagination,
  Field,
  Modal,
  PageHeader,
  Panel,
  StatusPill,
  Table,
  TextArea,
  TextInput,
  redirectIfSignedOut,
  useAdminData,
  useClientPagination,
  useConfirm,
  useDiscardGuard,
  useToast,
} from '@/components/admin/ui';

interface CategoryForm {
  name: string;
  slug: string;
  description: string;
  image_url: string;
  display_order: string;
  is_active: boolean;
}

const EMPTY_FORM: CategoryForm = { name: '', slug: '', description: '', image_url: '', display_order: '0', is_active: true };

export function AdminCategories() {
  const toast = useToast();
  const confirm = useConfirm();
  const { data, loading, error, reload, mutate } = useAdminData('AdminCategories', () => adminGetCategories(), []);
  const categories = data ?? [];
  const { pageItems, meta, setPage } = useClientPagination(categories);

  const [editing, setEditing] = useState<AdminCategory | null>(null);
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState<CategoryForm>(EMPTY_FORM);
  const closeEditor = useDiscardGuard(open, form, () => setOpen(false));
  const [slugTouched, setSlugTouched] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [busyId, setBusyId] = useState<number | null>(null);
  const fileRef = useRef<HTMLInputElement>(null);
  // Counts each opening of the form, so a late upload can't land in a different category's form.
  const formOpening = useRef(0);

  const openCreate = () => {
    formOpening.current += 1;
    setEditing(null);
    setForm({ ...EMPTY_FORM, display_order: String(categories.length + 1) });
    setSlugTouched(false);
    setErrors({});
    setOpen(true);
  };

  const openEdit = (cat: AdminCategory) => {
    formOpening.current += 1;
    setEditing(cat);
    setForm({
      name: cat.name,
      slug: cat.slug,
      description: cat.description ?? '',
      image_url: cat.image_url ?? '',
      display_order: String(cat.display_order),
      is_active: cat.is_active,
    });
    setSlugTouched(true);
    setErrors({});
    setOpen(true);
  };

  const setName = (name: string) =>
    setForm((f) => ({ ...f, name, slug: slugTouched ? f.slug : slugify(name, 'category') }));

  const handleUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    const opening = formOpening.current;
    setUploading(true);
    try {
      const res = await apiUploadProductImage(file);
      if (formOpening.current === opening) setForm((f) => ({ ...f, image_url: res.image_url }));
    } catch (err) {
      if (!redirectIfSignedOut(err)) toast.error(errorMessage(err, 'The image could not be uploaded.'));
    } finally {
      setUploading(false);
      if (fileRef.current) fileRef.current.value = '';
    }
  };

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    if (uploading) return;
    const local: Record<string, string> = {};
    if (form.name.trim().length < 2) local.name = 'Use at least 2 characters.';
    if (!/^[a-z0-9-]+$/.test(form.slug.trim())) local.slug = 'Lowercase letters, numbers and dashes only.';
    else if (isNumericSlug(form.slug.trim())) local.slug = `A slug cannot be only a number: add a word, e.g. category-${form.slug.trim()}.`;
    const displayOrder = wholeNumber(form.display_order.trim() || '0');
    if (displayOrder === null || displayOrder < 0) local.display_order = 'Use a whole number, 0 or more.';
    setErrors(local);
    if (Object.keys(local).length) return;

    setSaving(true);
    try {
      await adminSaveCategory(editing?.id ?? null, {
        name: form.name.trim(),
        slug: form.slug.trim(),
        description: form.description.trim(),
        image_url: form.image_url.trim(),
        display_order: displayOrder ?? 0,
        is_active: form.is_active,
      });
      toast.success(editing ? 'Category updated.' : 'Category created.');
      setOpen(false);
      reload();
    } catch (err) {
      if (redirectIfSignedOut(err)) return;
      const fields = fieldErrors(err);
      setErrors(fields);
      if (!Object.keys(fields).length) toast.error(errorMessage(err, 'The category could not be saved.'));
    } finally {
      setSaving(false);
    }
  };

  const handleHide = async (cat: AdminCategory) => {
    const ok = await confirm({
      title: `Hide "${cat.name}"?`,
      message: (
        <>
          The category disappears from the store menu and catalogue filters. Its {cat.product_count} product
          {cat.product_count === 1 ? '' : 's'} stay as they are and can still be found through search. You can restore it any time.
        </>
      ),
      confirmLabel: 'Hide category',
      tone: 'danger',
    });
    if (!ok) return;
    setBusyId(cat.id);
    try {
      await mutate((list) => list.map((c) => (c.id === cat.id ? { ...c, is_active: false } : c)), () => adminDeactivateCategory(cat.id));
      toast.success(`"${cat.name}" is hidden from the store.`);
    } catch (err) {
      if (!redirectIfSignedOut(err)) toast.error(errorMessage(err, 'The category could not be hidden.'));
    } finally {
      setBusyId(null);
    }
  };

  const handleRestore = async (cat: AdminCategory) => {
    setBusyId(cat.id);
    try {
      await mutate((list) => list.map((c) => (c.id === cat.id ? { ...c, is_active: true } : c)), () => adminSaveCategory(cat.id, { is_active: true }));
      toast.success(`"${cat.name}" is visible in the store again.`);
    } catch (err) {
      if (!redirectIfSignedOut(err)) toast.error(errorMessage(err, 'The category could not be restored.'));
    } finally {
      setBusyId(null);
    }
  };

  return (
    <div className="flex w-full flex-col">
      <PageHeader
        title="Categories"
        description="Group products for the store menu and catalogue filters."
        actions={
          <Button variant="primary" onClick={openCreate}>
            <Plus className="size-4" aria-hidden="true" />
            New category
          </Button>
        }
      />

      <Panel>
        <DataState
          loading={loading}
          error={error}
          onRetry={reload}
          hasData={!!data}
          empty={categories.length === 0}
          emptyTitle="No categories yet"
          emptyDescription="Create a category so products can be grouped in the store."
        >
          <Table minWidth={680}>
            <thead>
              <tr>
                <th>Name</th>
                <th>Slug</th>
                <th>Products</th>
                <th>Order</th>
                <th>Status</th>
                <th className="text-right">
                  <span className="sr-only">Actions</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {pageItems.map((cat) => (
                <tr key={cat.id}>
                  <td>
                    <div className="flex items-center gap-2.5">
                      <CategoryThumb key={cat.image_url ?? ""} src={cat.image_url} className="size-8" />
                      <span className="font-medium text-[#111827]">{cat.name}</span>
                    </div>
                  </td>
                  <td className="font-mono text-xs text-[#4b5563]">{cat.slug}</td>
                  <td className="tabular-nums">
                    {cat.published_count} published
                    {cat.product_count !== cat.published_count && <span className="text-[#6b7280]"> of {cat.product_count}</span>}
                  </td>
                  <td className="tabular-nums">{cat.display_order}</td>
                  <td>{cat.is_active ? <StatusPill label="Visible" tone="green" /> : <StatusPill label="Hidden" tone="neutral" />}</td>
                  <td className="text-right">
                    <div className="inline-flex gap-1.5">
                      <Button size="sm" onClick={() => openEdit(cat)}>
                        Edit
                      </Button>
                      {cat.is_active ? (
                        <Button size="sm" variant="ghost" disabled={busyId === cat.id} onClick={() => handleHide(cat)}>
                          Hide
                        </Button>
                      ) : (
                        <Button size="sm" variant="ghost" disabled={busyId === cat.id} onClick={() => handleRestore(cat)}>
                          Restore
                        </Button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </Table>
          <Pagination meta={meta} onPage={setPage} />
        </DataState>
      </Panel>

      <Modal
        open={open}
        onClose={closeEditor}
        busy={saving || uploading}
        persistent
        title={editing ? `Edit ${editing.name}` : 'New category'}
        footer={
          <>
            <Button onClick={closeEditor} disabled={saving || uploading}>
              Cancel
            </Button>
            <Button variant="primary" type="submit" form="category-form" loading={saving} disabled={uploading}>
              {editing ? 'Save changes' : 'Create category'}
            </Button>
          </>
        }
      >
        <form id="category-form" onSubmit={handleSave} className="grid gap-4 sm:grid-cols-2" noValidate>
          <Field label="Name" required error={errors.name}>
            {(p) => <TextInput {...p} data-autofocus value={form.name} onChange={(e) => setName(e.target.value)} />}
          </Field>
          <Field label="Slug" required error={errors.slug} hint="Used in the store address, e.g. /category?cat=slug">
            {(p) => (
              <TextInput
                {...p}
                value={form.slug}
                onChange={(e) => {
                  setSlugTouched(true);
                  setForm((f) => ({ ...f, slug: e.target.value.toLowerCase() }));
                }}
              />
            )}
          </Field>
          <Field label="Description" error={errors.description} className="sm:col-span-2">
            {(p) => <TextArea {...p} rows={3} value={form.description} onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))} />}
          </Field>
          <Field label="Image URL" error={errors.image_url} hint="Optional. Upload a JPG, PNG or WebP, or paste an image address." className="sm:col-span-2">
            {(p) => (
              <div className="flex items-stretch gap-2 [&>button]:!h-auto [&>button]:self-stretch">
                <TextInput
                  {...p}
                  className="min-w-0 flex-1"
                  value={form.image_url}
                  onChange={(e) => setForm((f) => ({ ...f, image_url: e.target.value }))}
                  placeholder="/uploads/… or https://…"
                />
                <input ref={fileRef} type="file" accept="image/jpeg,image/png,image/webp" className="hidden" onChange={handleUpload} aria-hidden="true" tabIndex={-1} />
                <Button size="sm" onClick={() => fileRef.current?.click()} loading={uploading} disabled={saving}>
                  <Upload className="size-3.5" aria-hidden="true" />
                  Upload
                </Button>
              </div>
            )}
          </Field>
          {form.image_url.trim() !== '' && (
            <div className="sm:col-span-2">
              <ImagePreview key={form.image_url} src={form.image_url.trim()} onRemove={() => setForm((f) => ({ ...f, image_url: '' }))} />
            </div>
          )}
          <Field label="Display order" error={errors.display_order} hint="Lower numbers appear first.">
            {(p) => (
              <TextInput {...p} type="number" min={0} value={form.display_order} onChange={(e) => setForm((f) => ({ ...f, display_order: e.target.value }))} />
            )}
          </Field>
          <div className="flex items-end pb-1">
            <Checkbox
              label="Visible in the store"
              checked={form.is_active}
              onChange={(v) => setForm((f) => ({ ...f, is_active: v }))}
            />
          </div>
        </form>
      </Modal>
    </div>
  );
}

/** Table thumbnail: the image, or an empty tile when there is none or it can't be loaded. */
function CategoryThumb({ src, className }: { src: string | null; className: string }) {
  const [failed, setFailed] = useState(false);
  if (!src || failed) {
    return (
      <span
        className={`${className} flex shrink-0 items-center justify-center rounded-md border border-dashed border-[#d1d5db] bg-[#f9fafb] text-[#9ca3af]`}
        title={src ? 'This image can’t be loaded' : undefined}
        aria-hidden="true"
      >
        {src && <ImageOff className="size-3.5" />}
      </span>
    );
  }
  return (
    <Image
      src={src}
      alt=""
      width={32}
      height={32}
      unoptimized={!canOptimizeImage(src)}
      onError={() => setFailed(true)}
      className={`${className} shrink-0 rounded-md border border-[#e5e7eb] object-cover`}
    />
  );
}

/** Form preview under the image field; says so plainly when the address doesn't load. */
function ImagePreview({ src, onRemove }: { src: string; onRemove: () => void }) {
  const [state, setState] = useState<'loading' | 'ok' | 'failed'>('loading');
  return (
    <div className="flex items-center gap-3 rounded-lg border border-[#e5e7eb] bg-[#f9fafb] p-2">
      <div className="relative size-16 shrink-0 overflow-hidden rounded-md border border-[#e5e7eb] bg-white">
        {state !== 'failed' ? (
          <Image
            src={src}
            alt="Category image preview"
            fill
            sizes="64px"
            unoptimized={!canOptimizeImage(src)}
            onLoad={() => setState('ok')}
            onError={() => setState('failed')}
            className="object-cover"
          />
        ) : (
          <span className="flex size-full items-center justify-center text-[#9ca3af]" aria-hidden="true">
            <ImageOff className="size-5" />
          </span>
        )}
      </div>
      <p className={`min-w-0 flex-1 text-xs ${state === 'failed' ? 'text-red-700' : 'text-[#6b7280]'}`} role={state === 'failed' ? 'alert' : undefined}>
        {state === 'failed'
          ? 'This image can’t be loaded. Check the address or upload the picture instead.'
          : state === 'loading'
            ? 'Loading preview…'
            : 'Preview of the category image.'}
      </p>
      <button type="button" onClick={onRemove} className="shrink-0 rounded-md px-2 py-1 text-xs font-medium text-[#4b5563] hover:bg-white hover:text-[#111827]">
        Remove
      </button>
    </div>
  );
}
