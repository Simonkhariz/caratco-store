'use client';

import React, { useRef, useState } from 'react';
import { ImagePlus, Pencil, Plus, Star, Trash2 } from 'lucide-react';
import {
  Banner,
  BannerInput,
  ReviewRow,
  adminDeleteBanner,
  adminGetBanners,
  adminGetReviews,
  adminSaveBanner,
  adminUpdateReviewStatus,
  errorMessage,
  fieldErrors,
} from '@/lib/admin-api';
import { apiUploadProductImage } from '@/lib/api-client';
import { AdminImage } from '@/components/admin/AdminImage';
import { formatDate } from '@/lib/admin-format';
import { SHOP } from '@/lib/shop';
import {
  Button,
  Checkbox,
  DataState,
  Field,
  FilterSelect,
  Modal,
  PageHeader,
  Pagination,
  Panel,
  StatusPill,
  Table,
  TextInput,
  Toolbar,
  redirectIfSignedOut,
  useAdminData,
  useClientPagination,
  useConfirm,
  useDiscardGuard,
  useToast,
  useUrlFilters,
} from '@/components/admin/ui';

type View = 'banners' | 'reviews';

/**
 * Storefront content: homepage hero banners and customer review moderation.
 */
export function AdminStorefront() {
  const [filters, setFilters] = useUrlFilters({ view: 'banners', status: 'pending', page: '1' });
  const view: View = filters.view === 'reviews' ? 'reviews' : 'banners';

  const tabs: Array<{ id: View; label: string }> = [
    { id: 'banners', label: 'Homepage banners' },
    { id: 'reviews', label: 'Reviews' },
  ];

  return (
    <div>
      <PageHeader title="Banners & reviews" description="What shoppers see on the homepage, and which reviews appear on product pages." />

      <div role="tablist" aria-label="Storefront content" className="mb-3 inline-flex rounded-lg border border-[#e5e7eb] bg-[#f9fafb] p-0.5">
        {tabs.map((tab) => (
          <button
            key={tab.id}
            type="button"
            role="tab"
            aria-selected={view === tab.id}
            onClick={() => setFilters({ view: tab.id })}
            className={`h-8 rounded-md px-3 text-[13px] font-semibold outline-none transition-colors focus-visible:ring-[3px] focus-visible:ring-[#9ca3af]/40 ${
              view === tab.id ? 'bg-white text-[#111827] shadow-sm' : 'text-[#6b7280] hover:text-[#111827]'
            }`}
          >
            {tab.label}
          </button>
        ))}
      </div>

      {view === 'banners' ? (
        <BannersPanel />
      ) : (
        <ReviewsPanel
          status={filters.status}
          page={Math.max(1, parseInt(filters.page, 10) || 1)}
          onStatus={(status) => setFilters({ status })}
          onPage={(page) => setFilters({ page: String(page) })}
        />
      )}
    </div>
  );
}

// =============================================================================
// Banners
// =============================================================================

const EMPTY_BANNER: BannerInput = {
  title: '',
  subtitle: '',
  cta_text: 'Shop now',
  cta_url: '/category',
  image_url: '',
  is_active: true,
  display_order: 1,
};

function BannersPanel() {
  const toast = useToast();
  const confirm = useConfirm();
  const { data, loading, error, reload, mutate } = useAdminData('AdminStorefront', () => adminGetBanners(), []);
  const [editing, setEditing] = useState<{ id: number | null; form: BannerInput } | null>(null);

  const banners = [...(data ?? [])].sort((a, b) => a.display_order - b.display_order || a.id - b.id);
  const { pageItems: bannerPage, meta: bannerMeta, setPage: setBannerPage } = useClientPagination(banners, 10);

  const openNew = () => {
    const nextOrder = banners.length ? Math.max(...banners.map((b) => b.display_order)) + 1 : 1;
    setEditing({ id: null, form: { ...EMPTY_BANNER, display_order: nextOrder } });
  };

  const openEdit = (b: Banner) =>
    setEditing({
      id: b.id,
      form: {
        title: b.title,
        subtitle: b.subtitle ?? '',
        cta_text: b.cta_text ?? '',
        cta_url: b.cta_url ?? '',
        image_url: b.image_url,
        is_active: !!Number(b.is_active),
        display_order: b.display_order,
      },
    });

  const handleDelete = async (b: Banner) => {
    const ok = await confirm({
      title: 'Delete this banner?',
      message: `"${b.title}" will be removed from the homepage. This cannot be undone.`,
      confirmLabel: 'Delete banner',
      tone: 'danger',
    });
    if (!ok) return;
    try {
      await mutate((list) => list.filter((x) => x.id !== b.id), () => adminDeleteBanner(b.id));
      toast.success('Banner deleted.');
    } catch (err) {
      if (!redirectIfSignedOut(err)) toast.error(errorMessage(err));
    }
  };

  return (
    <>
      <Panel
        title="Homepage banners"
        actions={
          <Button size="sm" variant="primary" onClick={openNew}>
            <Plus className="size-3.5" aria-hidden="true" />
            New banner
          </Button>
        }
      >
        <DataState
          loading={loading}
          error={error}
          onRetry={reload}
          hasData={!!data}
          empty={banners.length === 0}
          emptyTitle="No banners yet"
          emptyDescription="Active banners appear in the homepage hero, in display order."
        >
          <Table minWidth={760}>
            <thead>
              <tr>
                <th>Image</th>
                <th>Banner</th>
                <th>Link</th>
                <th>Order</th>
                <th>Status</th>
                <th className="text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              {bannerPage.map((b) => (
                <tr key={b.id}>
                  <td>
                    <AdminImage src={b.image_url} alt={b.title} width={80} height={48} className="h-12 w-20 rounded-md border border-[#e5e7eb] object-cover" />
                  </td>
                  <td className="max-w-[260px]">
                    <p className="truncate font-semibold text-[#111827]">{b.title}</p>
                    {b.subtitle && <p className="truncate text-xs text-[#6b7280]">{b.subtitle}</p>}
                  </td>
                  <td className="max-w-[200px]">
                    {b.cta_url ? (
                      <>
                        <p className="truncate text-xs font-medium text-[#374151]">{b.cta_text || '—'}</p>
                        <p className="truncate font-mono text-xs text-[#6b7280]">{b.cta_url}</p>
                      </>
                    ) : (
                      <span className="text-xs text-[#6b7280]">No link</span>
                    )}
                  </td>
                  <td className="tabular-nums">{b.display_order}</td>
                  <td>{Number(b.is_active) ? <StatusPill label="Live" tone="green" /> : <StatusPill label="Hidden" tone="neutral" />}</td>
                  <td>
                    <div className="flex justify-end gap-1">
                      <Button size="sm" variant="ghost" onClick={() => openEdit(b)} aria-label={`Edit ${b.title}`}>
                        <Pencil className="size-3.5" aria-hidden="true" />
                        Edit
                      </Button>
                      <Button size="sm" variant="ghost" onClick={() => handleDelete(b)} aria-label={`Delete ${b.title}`}>
                        <Trash2 className="size-3.5 text-red-600" aria-hidden="true" />
                      </Button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </Table>
          <Pagination meta={bannerMeta} onPage={setBannerPage} />
        </DataState>
      </Panel>

      {editing && (
        <BannerModal
          id={editing.id}
          initial={editing.form}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null);
            reload();
          }}
        />
      )}
    </>
  );
}

function BannerModal({ id, initial, onClose, onSaved }: { id: number | null; initial: BannerInput; onClose: () => void; onSaved: () => void }) {
  const toast = useToast();
  const [form, setForm] = useState<BannerInput>(initial);
  const guardedClose = useDiscardGuard(true, form, onClose);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [uploading, setUploading] = useState(false);
  const fileRef = useRef<HTMLInputElement>(null);

  const set = <K extends keyof BannerInput>(key: K, value: BannerInput[K]) => setForm((f) => ({ ...f, [key]: value }));

  const handleUpload = async (file: File | undefined) => {
    if (!file) return;
    setUploading(true);
    setErrors((e) => ({ ...e, image_url: '' }));
    try {
      const res = await apiUploadProductImage(file);
      set('image_url', res.image_url);
    } catch (err) {
      if (!redirectIfSignedOut(err)) setErrors((e) => ({ ...e, image_url: errorMessage(err, 'The image could not be uploaded.') }));
    } finally {
      setUploading(false);
      if (fileRef.current) fileRef.current.value = '';
    }
  };

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    try {
      await adminSaveBanner(id, { ...form, title: form.title.trim(), cta_url: form.cta_url.trim(), image_url: form.image_url.trim() });
      toast.success(id ? 'Banner updated.' : 'Banner created.');
      onSaved();
    } catch (err) {
      if (redirectIfSignedOut(err)) return;
      const fields = fieldErrors(err);
      setErrors(fields);
      if (Object.keys(fields).length === 0) toast.error(errorMessage(err));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open
      onClose={guardedClose}
      busy={saving || uploading}
      persistent
      title={id ? 'Edit banner' : 'New banner'}
      size="lg"
      footer={
        <>
          <Button onClick={guardedClose} disabled={saving || uploading}>
            Cancel
          </Button>
          <Button variant="primary" type="submit" form="banner-form" loading={saving} disabled={uploading}>
            {id ? 'Save changes' : 'Create banner'}
          </Button>
        </>
      }
    >
      <form id="banner-form" onSubmit={handleSave} className="grid gap-4 sm:grid-cols-2" noValidate>
        <Field label="Title" required error={errors.title} className="sm:col-span-2">
          {(p) => <TextInput {...p} data-autofocus value={form.title} maxLength={255} onChange={(e) => set('title', e.target.value)} />}
        </Field>
        <Field label="Subtitle" error={errors.subtitle} className="sm:col-span-2">
          {(p) => <TextInput {...p} value={form.subtitle} maxLength={255} onChange={(e) => set('subtitle', e.target.value)} />}
        </Field>
        <Field label="Button text" error={errors.cta_text}>
          {(p) => <TextInput {...p} value={form.cta_text} maxLength={50} onChange={(e) => set('cta_text', e.target.value)} />}
        </Field>
        <Field label="Button link" error={errors.cta_url} hint={`A store path like ${SHOP.wording.categoryHint}, or an https:// URL.`}>
          {(p) => <TextInput {...p} value={form.cta_url} maxLength={255} onChange={(e) => set('cta_url', e.target.value)} />}
        </Field>

        <div className="sm:col-span-2">
          <Field label="Image" required error={errors.image_url} hint="Upload a JPG, PNG or WebP, or paste an https:// image URL.">
            {(p) => <TextInput {...p} value={form.image_url} placeholder="/uploads/… or https://…" onChange={(e) => set('image_url', e.target.value)} />}
          </Field>
          <div className="mt-2 flex flex-wrap items-center gap-3">
            <input
              ref={fileRef}
              type="file"
              accept="image/jpeg,image/png,image/webp"
              className="sr-only"
              id="banner-image-upload"
              onChange={(e) => handleUpload(e.target.files?.[0])}
            />
            <Button size="sm" loading={uploading} onClick={() => fileRef.current?.click()}>
              <ImagePlus className="size-3.5" aria-hidden="true" />
              {uploading ? 'Uploading…' : 'Upload image'}
            </Button>
            <AdminImage src={form.image_url} alt="Banner preview" width={112} height={64} className="h-16 w-28 rounded-md border border-[#e5e7eb] object-cover" />
          </div>
        </div>

        <Field label="Display order" error={errors.display_order} hint="Lower numbers show first.">
          {(p) => (
            <TextInput {...p} type="number" min={0} value={form.display_order} onChange={(e) => set('display_order', parseInt(e.target.value, 10) || 0)} />
          )}
        </Field>
        <div className="flex items-end pb-2">
          <Checkbox label="Show on the homepage" checked={form.is_active} onChange={(v) => set('is_active', v)} />
        </div>
      </form>
    </Modal>
  );
}

// =============================================================================
// Reviews
// =============================================================================

const REVIEW_STATUS_TONE = { pending: 'amber', approved: 'green', rejected: 'neutral' } as const;

function ReviewsPanel({
  status,
  page,
  onStatus,
  onPage,
}: {
  status: string;
  page: number;
  onStatus: (status: string) => void;
  onPage: (page: number) => void;
}) {
  const toast = useToast();
  // Every review being saved right now (approving one does not free the buttons of another).
  const [busyIds, setBusyIds] = useState<ReadonlySet<number>>(new Set());
  const [expanded, setExpanded] = useState<Record<number, boolean>>({});
  const { data, loading, error, reload, mutate } = useAdminData('AdminStorefront#2', () => adminGetReviews({ status, page, per_page: 20 }), [status, page]);

  const moderate = async (review: ReviewRow, next: 'approved' | 'rejected' | 'pending') => {
    if (busyIds.has(review.id)) return;
    setBusyIds((ids) => new Set(ids).add(review.id));
    try {
      // Shown at once: a review that no longer matches the filter leaves the list.
      await mutate(
        (d) => ({
          ...d,
          items: status && status !== 'all' && status !== next ? d.items.filter((r) => r.id !== review.id) : d.items.map((r) => (r.id === review.id ? { ...r, status: next } : r)),
        }),
        () => adminUpdateReviewStatus(review.id, next)
      );
      toast.success(next === 'approved' ? 'Review approved and shown on the product page.' : next === 'rejected' ? 'Review rejected.' : 'Review moved back to pending.');
    } catch (err) {
      if (!redirectIfSignedOut(err)) toast.error(errorMessage(err));
    } finally {
      setBusyIds((ids) => {
        const rest = new Set(ids);
        rest.delete(review.id);
        return rest;
      });
    }
  };

  const items = data?.items ?? [];

  return (
    <Panel>
      <div className="px-4 pt-3">
        <Toolbar>
          <FilterSelect
            label="Review status"
            value={status}
            onChange={onStatus}
            options={[
              { value: 'pending', label: 'Waiting for review' },
              { value: 'approved', label: 'Approved' },
              { value: 'rejected', label: 'Rejected' },
              { value: 'all', label: 'All reviews' },
            ]}
          />
          <p className="text-xs text-[#6b7280]">Only approved reviews appear on product pages and count towards the rating.</p>
        </Toolbar>
      </div>
      <DataState
        loading={loading}
        error={error}
        onRetry={reload}
        hasData={!!data}
        empty={items.length === 0}
        emptyTitle={status === 'pending' ? 'No reviews waiting' : 'No reviews'}
        emptyDescription={status === 'pending' ? 'New customer reviews will appear here for approval.' : undefined}
      >
        <Table minWidth={900}>
          <thead>
            <tr>
              <th>Product</th>
              <th>Customer</th>
              <th>Rating</th>
              <th>Review</th>
              <th>Date</th>
              <th>Status</th>
              <th className="text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            {items.map((r) => {
              const isOpen = !!expanded[r.id];
              const long = r.comment.length > 140;
              return (
                <tr key={r.id}>
                  <td className="max-w-[180px]">
                    <a
                      href={`/products/${r.product_slug}`}
                      target="_blank"
                      rel="noopener"
                      className="line-clamp-2 font-medium text-[#111827] hover:underline"
                    >
                      {r.product_name}
                      <span className="sr-only"> (opens in a new tab)</span>
                    </a>
                  </td>
                  <td className="max-w-[160px]">
                    <p className="truncate text-[#111827]">{r.author_name}</p>
                    {!!Number(r.is_verified_purchase) && <span className="mt-0.5 inline-block text-xs font-semibold text-emerald-700">Verified purchase</span>}
                  </td>
                  <td>
                    <span className="inline-flex items-center gap-1 whitespace-nowrap" aria-label={`${r.rating} out of 5`}>
                      <Star className="size-3.5 fill-amber-400 text-amber-400" aria-hidden="true" />
                      <span aria-hidden="true" className="tabular-nums">
                        {r.rating}/5
                      </span>
                    </span>
                  </td>
                  <td className="max-w-[340px]">
                    {r.title && <p className="font-semibold text-[#111827]">{r.title}</p>}
                    <p className={`text-[#374151] ${isOpen ? '' : 'line-clamp-2'}`}>{r.comment}</p>
                    {long && (
                      <button
                        type="button"
                        onClick={() => setExpanded((e) => ({ ...e, [r.id]: !isOpen }))}
                        aria-expanded={isOpen}
                        className="mt-0.5 text-xs font-semibold text-[#374151] underline-offset-2 hover:underline"
                      >
                        {isOpen ? 'Show less' : 'Show more'}
                      </button>
                    )}
                  </td>
                  <td className="whitespace-nowrap text-xs text-[#6b7280]">{formatDate(r.created_at)}</td>
                  <td>
                    <StatusPill label={r.status.charAt(0).toUpperCase() + r.status.slice(1)} tone={REVIEW_STATUS_TONE[r.status] ?? 'neutral'} />
                  </td>
                  <td>
                    <div className="flex justify-end gap-1">
                      {r.status !== 'approved' && (
                        <Button size="sm" variant="primary" loading={busyIds.has(r.id)} onClick={() => moderate(r, 'approved')}>
                          Approve
                        </Button>
                      )}
                      {r.status !== 'rejected' && (
                        <Button size="sm" disabled={busyIds.has(r.id)} onClick={() => moderate(r, 'rejected')}>
                          Reject
                        </Button>
                      )}
                      {r.status !== 'pending' && (
                        <Button size="sm" variant="ghost" disabled={busyIds.has(r.id)} onClick={() => moderate(r, 'pending')}>
                          Move to pending
                        </Button>
                      )}
                    </div>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </Table>
        <Pagination meta={data?.pagination} onPage={onPage} />
      </DataState>
    </Panel>
  );
}
