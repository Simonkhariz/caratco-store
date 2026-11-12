'use client';

import React, { useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { ChevronDown, ImageOff, Plus, Share2, Trash2, Upload } from 'lucide-react';
import {
  AdminProduct,
  PRODUCT_IMAGE_PLACEHOLDER,
  apiCreateAdminProduct,
  apiDeleteAdminProduct,
  apiGetAdminProducts,
  apiUpdateAdminProduct,
  apiUpdateAdminProductStatus,
  apiUploadProductImage,
} from '@/lib/api-client';
import {
  AdminProductSave,
  MAX_SPECIFICATIONS,
  MAX_SPECIFICATION_NAME,
  MAX_SPECIFICATION_VALUE,
  MAX_WARRANTY,
  ProductSpecification,
  adminGetCategories,
  errorMessage,
  fieldErrors,
  productExtras,
} from '@/lib/admin-api';
import { formatPrice, num } from '@/lib/admin-format';
import { isNumericSlug, optionPairProblems, slugify, specificationRows, splitUploads, uploadFailureMessage, wholeNumber } from '@/lib/admin-logic';
import { SHOP, hasOption1, hasOption2, option1Word, option2Word, optionsText, withArticle } from '@/lib/shop';
import { ShareProductModal, type ShareableProduct } from '@/components/admin/ShareProductModal';
import { AdminImage } from '@/components/admin/AdminImage';
import {
  Button,
  Checkbox,
  DataState,
  Field,
  FilterSelect,
  Modal,
  Notice,
  PageHeader,
  Pagination,
  Panel,
  SearchInput,
  Select,
  StatusPill,
  Table,
  TextArea,
  TextInput,
  Toolbar,
  inputClass,
  redirectIfSignedOut,
  useAdminData,
  useConfirm,
  useSearchBox,
  useDiscardGuard,
  useToast,
  useUrlFilters,
} from '@/components/admin/ui';

type ProductStatus = 'draft' | 'published' | 'archived';

// The names of the two options and the style field come from the shop's config (lib/shop.ts).
const STYLES = SHOP.style.values;
// Short names, one per choice: the shop's filter lists every distinct name, so "M" and "Medium"
// would show as two sizes. A product saved with another name keeps it in its own list.
const OPTION1_CHOICES = SHOP.option1.choices;
/** The dropdown entry that turns the first option into a text box (never saved as a value). */
const OTHER_CHOICE = '__other__';
const OPTION1_PLURAL = SHOP.option1.plural.toLowerCase();
const OPTION2_PLURAL = SHOP.option2.plural.toLowerCase();
/** False when the second option is plain text (a scent, a flavour): no colour picker, no swatch column. */
const SWATCHES = SHOP.option2.swatch;

const STATUS_LABELS: Record<ProductStatus, { label: string; tone: 'green' | 'amber' | 'neutral' }> = {
  published: { label: 'Published', tone: 'green' },
  draft: { label: 'Draft', tone: 'amber' },
  archived: { label: 'Archived', tone: 'neutral' },
};

interface VariantForm {
  /** Set for variants that already exist (their stock is changed on the Inventory page). */
  id?: number;
  sku: string;
  /** The first option ('' = the product does not use it; the server stores its "none" value). */
  size: string;
  /** "Other…" was chosen: the first option is typed instead of picked from the list. */
  size_typed?: boolean;
  color_name: string;
  color_hex: string;
  price_override: string;
  stock_quantity: string;
  low_stock_threshold: string;
}

interface ImageForm {
  image_url: string;
  alt_text: string;
  is_primary: boolean;
}

interface ProductForm {
  name: string;
  slug: string;
  category_id: string;
  dress_style: string;
  description: string;
  base_price: string;
  original_price: string;
  sku: string;
  status: ProductStatus;
  is_featured: boolean;
  is_flash_deal: boolean;
  /** datetime-local value ('YYYY-MM-DDTHH:MM'); empty = no end date. */
  flash_deal_ends_at: string;
  /** Optional starting star rating, '' = the store default. */
  rating_start: string;
  images: ImageForm[];
  variants: VariantForm[];
  specifications: ProductSpecification[];
  warranty: string;
  is_heavy: boolean;
}

/** 'YYYY-MM-DD HH:MM:SS' (server) → 'YYYY-MM-DDTHH:MM' (datetime-local input). */
function toDateTimeLocal(value: string | null | undefined): string {
  return value ? value.replace(' ', 'T').slice(0, 16) : '';
}

function flashDealEnd(value: string | null | undefined): Date | null {
  if (!value) return null;
  const d = new Date(value.includes('T') ? value : value.replace(' ', 'T'));
  return Number.isNaN(d.getTime()) ? null : d;
}

/** Wide screens: one row per variant (option 1, option 2, Swatch when option 2 is a colour, SKU, Price, Stock, Alert, Remove). */
const VARIANT_GRID = SWATCHES
  ? 'lg:grid-cols-[minmax(0,8rem)_minmax(0,7rem)_3.75rem_minmax(0,1fr)_minmax(0,7rem)_minmax(0,5rem)_minmax(0,5rem)_2rem]'
  : 'lg:grid-cols-[minmax(0,8rem)_minmax(0,9rem)_minmax(0,1fr)_minmax(0,7rem)_minmax(0,5rem)_minmax(0,5rem)_2rem]';
const VARIANT_FIELD = 'flex min-w-0 flex-col gap-1';
/** Field names are shown on the phone/tablet cards; the header row names them on wide screens. */
const VARIANT_LABEL = 'text-xs text-[#6b7280] lg:sr-only';
const NEW_VARIANT: VariantForm = {
  sku: '',
  size: '',
  color_name: '',
  color_hex: '#000000',
  price_override: '',
  stock_quantity: '0',
  low_stock_threshold: '5',
};

const EMPTY_FORM: ProductForm = {
  name: '',
  slug: '',
  category_id: '',
  dress_style: '',
  description: '',
  base_price: '',
  original_price: '',
  sku: '',
  status: 'draft',
  is_featured: false,
  is_flash_deal: false,
  flash_deal_ends_at: '',
  rating_start: '',
  images: [],
  variants: [{ ...NEW_VARIANT }],
  // A new product starts with the names this kind of shop usually fills in (none for most shops).
  specifications: SHOP.specs.suggested.slice(0, MAX_SPECIFICATIONS).map((name) => ({ name, value: '' })),
  warranty: '',
  is_heavy: false,
};

function productToForm(p: AdminProduct): ProductForm {
  const images: ImageForm[] =
    p.images && p.images.length > 0
      ? p.images.map((img) => ({ image_url: img.image_url, alt_text: img.alt_text || '', is_primary: Boolean(img.is_primary) }))
      : p.primary_image
        ? [{ image_url: p.primary_image, alt_text: p.name, is_primary: true }]
        : [];
  if (images.length > 0 && !images.some((i) => i.is_primary)) images[0].is_primary = true;
  const extras = productExtras(p);

  return {
    name: p.name,
    slug: p.slug,
    category_id: p.category_id ? String(p.category_id) : '',
    dress_style: p.dress_style || '',
    description: p.description || '',
    base_price: String(num(p.base_price)),
    original_price: p.original_price ? String(num(p.original_price)) : '',
    sku: p.sku,
    status: p.status,
    is_featured: Boolean(p.is_featured),
    is_flash_deal: Boolean(p.is_flash_deal),
    flash_deal_ends_at: toDateTimeLocal(p.flash_deal_ends_at),
    rating_start: p.rating_start != null ? String(num(p.rating_start)) : '',
    images,
    variants: (p.variants ?? []).map((v) => ({
      id: v.id,
      sku: v.sku,
      // The stored "none" value is an empty box here, never a choice called "Standard" or "Default".
      size: hasOption1(v.size) ? v.size : '',
      color_name: hasOption2(v.color_name) ? v.color_name : '',
      color_hex: v.color_hex || '#000000',
      price_override: v.price_override ? String(num(v.price_override)) : '',
      stock_quantity: String(num(v.stock_quantity)),
      low_stock_threshold: String(v.low_stock_threshold ?? 5),
    })),
    specifications: (Array.isArray(extras.specifications) ? extras.specifications : []).map((s) => ({
      name: String(s?.name ?? ''),
      value: String(s?.value ?? ''),
    })),
    warranty: extras.warranty ?? '',
    is_heavy: Boolean(extras.is_heavy),
  };
}

/** Server field messages the editor shows beside a field (anything else goes in the banner). */
const SHOWN_ERROR_FIELDS = new Set([
  'name', 'slug', 'category_id', 'description', 'base_price', 'original_price', 'sku', 'status',
  'rating_start', 'flash_deal_ends_at', 'variants', 'images', 'specifications',
  // These boxes exist only when the shop's config switches them on.
  ...(SHOP.style.enabled ? ['dress_style'] : []),
  ...(SHOP.features.warranty ? ['warranty'] : []),
  ...(SHOP.features.heavyDelivery ? ['is_heavy'] : []),
]);

/**
 * The first option of one variant: the shop's list of choices, with "Other…" for a value that is
 * not in it, or simply a text box when the shop has no list.
 */
function Option1Field({
  label,
  value,
  typed,
  invalid,
  onChange,
}: {
  label: string;
  value: string;
  typed: boolean;
  invalid: boolean;
  /** `size_typed` is only ever true or absent, so going back to the list leaves the form as it was. */
  onChange: (changes: Pick<VariantForm, 'size' | 'size_typed'>) => void;
}) {
  const inputRef = useRef<HTMLInputElement>(null);
  // Set when "Other…" is picked, so the text box that replaces the list is ready to type in.
  const focusTextBox = useRef(false);
  useEffect(() => {
    if (typed && focusTextBox.current) inputRef.current?.focus();
    focusTextBox.current = false;
  }, [typed]);

  if (typed || OPTION1_CHOICES.length === 0) {
    const input = (
      <input
        ref={inputRef}
        aria-label={`${label} ${option1Word}`}
        maxLength={50}
        value={value}
        onChange={(e) => onChange({ size: e.target.value })}
        className={`${inputClass} h-9 w-full py-1 lg:h-8 ${typed ? 'pr-8' : ''}`}
        aria-invalid={invalid ? true : undefined}
      />
    );
    if (!typed) return input;
    return (
      <span className="relative block">
        {input}
        <button
          type="button"
          onClick={() => {
            // Back to the list: a typed value that is one of the choices takes the list's spelling.
            const listed = OPTION1_CHOICES.find((c) => c.toLowerCase() === value.trim().toLowerCase());
            onChange({ size: listed ?? value.trim(), size_typed: undefined });
          }}
          aria-label={`${label}: choose ${withArticle(option1Word)} from the list`}
          title="Choose from the list"
          className="absolute inset-y-0 right-0 flex w-8 items-center justify-center rounded-r-lg text-[#6b7280] outline-none hover:text-[#111827] focus-visible:ring-[3px] focus-visible:ring-[#9ca3af]/40"
        >
          <ChevronDown className="size-4" aria-hidden="true" />
        </button>
      </span>
    );
  }

  const choices = value && !OPTION1_CHOICES.includes(value) ? [...OPTION1_CHOICES, value] : OPTION1_CHOICES;
  return (
    <select
      aria-label={`${label} ${option1Word}`}
      value={value}
      onChange={(e) => {
        const other = e.target.value === OTHER_CHOICE;
        focusTextBox.current = other;
        onChange(other ? { size: '', size_typed: true } : { size: e.target.value });
      }}
      className={`${inputClass} h-9 w-full !py-0 pr-7 lg:h-8`}
      aria-invalid={invalid ? true : undefined}
    >
      <option value="">Choose…</option>
      {choices.map((s) => (
        <option key={s} value={s}>
          {s}
        </option>
      ))}
      <option value={OTHER_CHOICE}>Other…</option>
    </select>
  );
}

/** '4.5' -> 4.5; null when empty or not 1 to 5 with at most one decimal. */
function startingRating(value: string): number | null {
  const n = Number(value.trim());
  if (value.trim() === '' || !Number.isFinite(n) || n < 1 || n > 5) return null;
  return Math.abs(Math.round(n * 10) - n * 10) < 1e-9 ? Math.round(n * 10) / 10 : null;
}

/** Under the starting rating: what it does, and for a saved product what customers see now. */
function ratingHint(p: AdminProduct | undefined): string {
  const base = 'Optional, 1 to 5. Empty uses the store default (Settings). Counts like 5 reviews, so real reviews take over.';
  if (!p) return base;
  const shown = p.rating_avg > 0 ? `${p.rating_avg.toFixed(1)}★` : 'no stars';
  const reviews =
    p.rating_count > 0
      ? `${p.rating_count} ${p.rating_count === 1 ? 'review' : 'reviews'}, customers' own average ${(p.review_avg ?? 0).toFixed(1)}★`
      : 'no reviews yet';
  return `${base} Shown now: ${shown} (${reviews}).`;
}

function stockPill(p: AdminProduct) {
  const total = num(p.total_stock);
  if (total <= 0) return <StatusPill label="Out of stock" tone="red" />;
  if (p.is_low_stock) return <StatusPill label="Low stock" tone="amber" />;
  return <StatusPill label="In stock" tone="green" />;
}

export function ProductManagement() {
  const toast = useToast();
  const confirm = useConfirm();

  const [filters, setFilters] = useUrlFilters({ search: '', category: 'all', status: 'all', inventory: 'all', page: '1' });
  const page = Number(filters.page) || 1;

  const [searchText, setSearchText] = useSearchBox(filters.search, setFilters);

  const { data, loading, error, reload, mutate } = useAdminData('ProductManagement',
    () =>
      apiGetAdminProducts({
        page,
        per_page: 15,
        search: filters.search || undefined,
        category: filters.category !== 'all' ? filters.category : undefined,
        status: filters.status !== 'all' ? filters.status : undefined,
        inventory: filters.inventory !== 'all' ? filters.inventory : undefined,
      }),
    [page, filters.search, filters.category, filters.status, filters.inventory]
  );
  const products = data?.items ?? [];

  const categoriesData = useAdminData('ProductManagement#2', () => adminGetCategories(), []);
  const categories = categoriesData.data ?? [];

  const [busyId, setBusyId] = useState<number | null>(null);

  // ---------------------------------------------------------------------------
  // Editor
  // ---------------------------------------------------------------------------
  const [editorOpen, setEditorOpen] = useState(false);
  const [sharing, setSharing] = useState<ShareableProduct | null>(null);
  const [editingId, setEditingId] = useState<number | null>(null);
  const editingProduct = editingId ? products.find((p) => p.id === editingId) : undefined;
  const [form, setForm] = useState<ProductForm>(EMPTY_FORM);
  const closeEditor = useDiscardGuard(editorOpen, form, () => setEditorOpen(false));
  const [slugTouched, setSlugTouched] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [newImageUrl, setNewImageUrl] = useState('');
  const fileRef = useRef<HTMLInputElement>(null);
  // Counts each opening of the editor, so a late upload can't land in a different product's form.
  const editorOpening = useRef(0);
  // The newer fields the product being edited came without (see the save: they are not sent empty).
  const unreadFields = useRef({ specifications: false, warranty: false, is_heavy: false });

  const update = <K extends keyof ProductForm>(key: K, value: ProductForm[K]) => setForm((f) => ({ ...f, [key]: value }));

  const updateVariant = (index: number, changes: Partial<VariantForm>) =>
    setForm((f) => ({ ...f, variants: f.variants.map((v, i) => (i === index ? { ...v, ...changes } : v)) }));

  const openCreate = () => {
    editorOpening.current += 1;
    setEditingId(null);
    setForm({ ...EMPTY_FORM, variants: [{ ...NEW_VARIANT }], specifications: EMPTY_FORM.specifications.map((s) => ({ ...s })) });
    setSlugTouched(false);
    setErrors({});
    setFormError(null);
    setNewImageUrl('');
    setEditorOpen(true);
  };

  const openEdit = (p: AdminProduct) => {
    editorOpening.current += 1;
    setEditingId(p.id);
    setForm(productToForm(p));
    const extras = productExtras(p);
    unreadFields.current = {
      specifications: !Array.isArray(extras.specifications),
      warranty: extras.warranty === undefined,
      is_heavy: extras.is_heavy === undefined,
    };
    setSlugTouched(true);
    setErrors({});
    setFormError(null);
    setNewImageUrl('');
    setEditorOpen(true);
  };

  const handleUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const files = Array.from(e.target.files ?? []);
    if (files.length === 0) return;
    const opening = editorOpening.current;
    setUploading(true);
    try {
      // One bad file must not cost the others: keep what worked and name what didn't.
      const { uploaded, failed } = splitUploads(files, await Promise.allSettled(files.map((f) => apiUploadProductImage(f))));
      if (editorOpening.current !== opening) return;
      if (uploaded.length > 0) {
        setForm((f) => {
          const hasPrimary = f.images.some((i) => i.is_primary);
          return {
            ...f,
            images: [
              ...f.images,
              ...uploaded.map((r, i) => ({ image_url: r.image_url, alt_text: '', is_primary: !hasPrimary && i === 0 })),
            ],
          };
        });
        toast.success(uploaded.length === 1 ? 'Photo uploaded.' : `${uploaded.length} photos uploaded.`);
      }
      if (failed.length > 0 && !failed.some((f) => redirectIfSignedOut(f.reason))) toast.error(uploadFailureMessage(failed));
    } finally {
      setUploading(false);
      if (fileRef.current) fileRef.current.value = '';
    }
  };

  const addImageUrl = () => {
    const url = newImageUrl.trim();
    if (!/^(\/(?!\/)|https:\/\/)/.test(url)) {
      setErrors((e) => ({ ...e, new_image: 'Use a store path (/uploads/…) or an https:// address.' }));
      return;
    }
    setErrors((e) => {
      const rest = { ...e };
      delete rest.new_image;
      return rest;
    });
    setForm((f) => ({ ...f, images: [...f.images, { image_url: url, alt_text: '', is_primary: !f.images.some((i) => i.is_primary) }] }));
    setNewImageUrl('');
  };

  const removeImage = (index: number) =>
    setForm((f) => {
      const images = f.images.filter((_, i) => i !== index);
      if (images.length > 0 && !images.some((i) => i.is_primary)) images[0] = { ...images[0], is_primary: true };
      return { ...f, images };
    });

  const removeVariant = async (index: number) => {
    const v = form.variants[index];
    if (v.id) {
      const ok = await confirm({
        title: `Remove ${optionsText(v.size, v.color_name) || 'this variant'}?`,
        message: 'It stops being sold once you save. Past orders keep their record of it.',
        confirmLabel: 'Remove variant',
        tone: 'danger',
      });
      if (!ok) return;
    }
    setForm((f) => ({ ...f, variants: f.variants.filter((_, i) => i !== index) }));
  };

  const validate = (): Record<string, string> => {
    const e: Record<string, string> = {};
    if (form.name.trim().length < 3) e.name = 'Use at least 3 characters.';
    if (!/^[a-z0-9-]+$/.test(form.slug.trim())) e.slug = 'Lowercase letters, numbers and dashes only.';
    else if (isNumericSlug(form.slug.trim())) e.slug = `A slug cannot be only a number: add a word, e.g. product-${form.slug.trim()}.`;
    const base = parseFloat(form.base_price);
    if (!(base > 0)) e.base_price = 'Enter a price above 0.';
    if (form.original_price.trim() !== '' && !(parseFloat(form.original_price) > base)) {
      e.original_price = 'Must be higher than the price (it is shown crossed out).';
    }
    if (editingId && form.sku.trim().length < 3) e.sku = 'Use at least 3 characters.';
    if (form.rating_start.trim() !== '' && startingRating(form.rating_start) === null) {
      e.rating_start = 'Enter 1 to 5 with at most one decimal, e.g. 4.5, or leave it empty.';
    }
    if (form.variants.length === 0) e.variants = `Add at least one ${option1Word} and ${option2Word}.`;
    // An option may be left empty (the product does not use it). Typing the "none" word means the same.
    const problems = optionPairProblems(
      form.variants.map((v) => [hasOption1(v.size) ? v.size.trim() : '', hasOption2(v.color_name) ? v.color_name.trim() : ''])
    );
    form.variants.forEach((v, i) => {
      if (problems[i] === 'empty') {
        // Only a product with a single variant can do without both options.
        e[`variant_${i}`] = `${OPTION1_CHOICES.length > 0 ? 'Choose' : 'Enter'} ${withArticle(option1Word)} or enter ${withArticle(option2Word)}.`;
        return;
      }
      if (problems[i] === 'duplicate') e[`variant_${i}`] = `This ${option1Word} and ${option2Word} is listed twice.`;
      if (!v.id && !((wholeNumber(v.stock_quantity.trim() || '0') ?? -1) >= 0)) {
        e[`variant_${i}`] = 'Starting stock must be a whole number, 0 or more.';
      }
      if (v.low_stock_threshold.trim() !== '' && !((wholeNumber(v.low_stock_threshold) ?? -1) >= 0)) {
        e[`variant_${i}`] = 'The low-stock alert must be a whole number, 0 or more.';
      }
      if (v.price_override.trim() !== '' && !(parseFloat(v.price_override) > 0)) e[`variant_${i}`] = 'Variant price must be above 0.';
    });
    // A row without a value is simply not saved; a value without a name would be lost, so it is refused.
    if (specificationRows(form.specifications).unnamed > 0) e.specifications = 'Give each row a name, or remove the row.';
    return e;
  };

  const handleSave = async (ev: React.FormEvent) => {
    ev.preventDefault();
    if (uploading) return;
    const local = validate();
    setErrors(local);
    setFormError(Object.keys(local).length ? 'Please fix the highlighted fields.' : null);
    if (Object.keys(local).length) return;

    const specifications = specificationRows(form.specifications).rows;
    const warranty = form.warranty.trim();
    // A key that is left out keeps what the server has. That is what happens to a field this shop
    // has switched off, and to one the product list did not carry (a list cached before an update),
    // unless something was entered: an empty box must never wipe a value nobody saw.
    const send = (field: keyof typeof unreadFields.current, entered: boolean) => !editingId || !unreadFields.current[field] || entered;

    const payload: AdminProductSave = {
      name: form.name.trim(),
      slug: form.slug.trim(),
      category_id: form.category_id ? Number(form.category_id) : null,
      product_type: 'physical',
      status: form.status,
      dress_style: form.dress_style || null,
      base_price: Number(form.base_price),
      original_price: form.original_price.trim() ? Number(form.original_price) : null,
      // Blank SKU on a new product: the server generates one from the category and name.
      sku: form.sku.trim(),
      description: form.description.trim(),
      is_featured: form.is_featured ? 1 : 0,
      is_flash_deal: form.is_flash_deal ? 1 : 0,
      // Empty clears the end date; the server ignores it when the product is not a flash deal.
      flash_deal_ends_at: form.is_flash_deal ? form.flash_deal_ends_at : '',
      rating_start: startingRating(form.rating_start),
      images: form.images.map((img, idx) => ({
        image_url: img.image_url,
        alt_text: img.alt_text.trim() || form.name.trim(),
        is_primary: img.is_primary ? 1 : 0,
        display_order: idx,
      })),
      variants: form.variants.map((v) => ({
        ...(v.id ? { id: v.id } : { stock_quantity: wholeNumber(v.stock_quantity) ?? 0 }),
        sku: v.sku.trim(),
        // Empty = the product does not use this option; the server stores its "none" value.
        size: v.size.trim(),
        color_name: v.color_name.trim(),
        color_hex: SWATCHES ? v.color_hex : '#000000',
        price_override: v.price_override.trim() ? Number(v.price_override) : null,
        low_stock_threshold: wholeNumber(v.low_stock_threshold) ?? 0,
      })),
      ...(send('specifications', specifications.length > 0) ? { specifications } : {}),
      ...(SHOP.features.warranty && send('warranty', warranty !== '') ? { warranty } : {}),
      ...(SHOP.features.heavyDelivery && send('is_heavy', form.is_heavy) ? { is_heavy: form.is_heavy } : {}),
    };

    setSaving(true);
    try {
      if (editingId) {
        await apiUpdateAdminProduct(editingId, payload);
        toast.success('Product saved.');
      } else {
        await apiCreateAdminProduct(payload);
        toast.success('Product created.');
      }
      setEditorOpen(false);
      reload();
    } catch (err) {
      if (redirectIfSignedOut(err)) return;
      const fields = fieldErrors(err);
      setErrors(fields);
      // A message for something the form has no box for is spelled out, never just "fix the highlighted fields".
      const unplaced = Object.entries(fields)
        .filter(([key]) => !SHOWN_ERROR_FIELDS.has(key) && !key.startsWith('variant_'))
        .map(([, message]) => message);
      setFormError(
        Object.keys(fields).length
          ? ['Please fix the highlighted fields.', ...unplaced].join(' ')
          : errorMessage(err, 'The product could not be saved.')
      );
    } finally {
      setSaving(false);
    }
  };

  // ---------------------------------------------------------------------------
  // Row actions
  // ---------------------------------------------------------------------------
  const changeStatus = async (p: AdminProduct, status: ProductStatus) => {
    if (status === p.status) return;
    if (status === 'archived') {
      const ok = await confirm({
        title: `Archive "${p.name}"?`,
        message: 'It disappears from the store and can no longer be ordered. Past orders are not affected. You can publish it again later.',
        confirmLabel: 'Archive',
        tone: 'danger',
      });
      if (!ok) return;
    }
    setBusyId(p.id);
    try {
      // The new status shows at once; the list is re-read from the server afterwards.
      await mutate(
        (d) => ({ ...d, items: d.items.map((x) => (x.id === p.id ? { ...x, status } : x)) }),
        () => (status === 'archived' ? apiDeleteAdminProduct(p.id) : apiUpdateAdminProductStatus(p.id, status))
      );
      toast.success(`"${p.name}" is now ${STATUS_LABELS[status].label.toLowerCase()}.`);
    } catch (err) {
      if (!redirectIfSignedOut(err)) toast.error(errorMessage(err, 'The status could not be changed.'));
    } finally {
      setBusyId(null);
    }
  };

  const hasFilters = !!filters.search || filters.category !== 'all' || filters.status !== 'all' || filters.inventory !== 'all';
  const categoryOptionsForForm =
    form.category_id && !categories.some((c) => String(c.id) === form.category_id)
      ? [...categories, { id: Number(form.category_id), name: `Category #${form.category_id}`, is_active: false }]
      : categories;
  const styles = form.dress_style && !STYLES.includes(form.dress_style) ? [...STYLES, form.dress_style] : STYLES;

  const updateSpecification = (index: number, changes: Partial<ProductSpecification>) =>
    setForm((f) => ({ ...f, specifications: f.specifications.map((s, i) => (i === index ? { ...s, ...changes } : s)) }));

  return (
    <div className="flex w-full flex-col">
      <PageHeader
        title="Products"
        description={`Catalogue, prices, photos and the ${OPTION1_PLURAL} and ${OPTION2_PLURAL} you sell.`}
        actions={
          <Button variant="primary" onClick={openCreate}>
            <Plus className="size-4" aria-hidden="true" />
            New product
          </Button>
        }
      />

      <Toolbar>
        <SearchInput label="Search products" placeholder="Search name, SKU or description" value={searchText} onChange={setSearchText} />
        <FilterSelect
          label="Category"
          value={filters.category}
          onChange={(v) => setFilters({ category: v })}
          options={[
            { value: 'all', label: 'All categories' },
            ...categories.map((c) => ({ value: String(c.id), label: c.is_active ? c.name : `${c.name} (hidden)` })),
          ]}
        />
        <FilterSelect
          label="Status"
          value={filters.status}
          onChange={(v) => setFilters({ status: v })}
          options={[
            { value: 'all', label: 'All statuses' },
            { value: 'published', label: 'Published' },
            { value: 'draft', label: 'Draft' },
            { value: 'archived', label: 'Archived' },
          ]}
        />
        <FilterSelect
          label="Stock"
          value={filters.inventory}
          onChange={(v) => setFilters({ inventory: v })}
          options={[
            { value: 'all', label: 'Any stock' },
            { value: 'in_stock', label: 'In stock' },
            { value: 'low_stock', label: 'Low stock' },
            { value: 'out_of_stock', label: 'Out of stock' },
          ]}
        />
      </Toolbar>

      <Panel>
        <DataState
          loading={loading}
          error={error}
          onRetry={reload}
          hasData={!!data}
          empty={products.length === 0}
          emptyTitle={hasFilters ? 'No products match these filters' : 'No products yet'}
          emptyDescription={hasFilters ? 'Try a different search or filter.' : 'Create your first product to start selling.'}
        >
          <Table minWidth={900}>
            <thead>
              <tr>
                <th>Product</th>
                <th>Category</th>
                <th>Price</th>
                <th>Stock</th>
                <th>Status</th>
                <th className="text-right">
                  <span className="sr-only">Actions</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {products.map((p) => {
                const price = num(p.base_price);
                const original = p.original_price ? num(p.original_price) : 0;
                return (
                  <tr key={p.id}>
                    <td>
                      <div className="flex items-center gap-3">
                        <AdminImage
                          src={p.primary_image}
                          fallback={PRODUCT_IMAGE_PLACEHOLDER}
                          alt=""
                          width={44}
                          height={44}
                          className="size-11 shrink-0 rounded-lg border border-[#e5e7eb] bg-[#f9fafb] object-cover"
                        />
                        <div className="min-w-0">
                          <p className="max-w-[260px] truncate font-medium text-[#111827]" title={p.name}>
                            {p.name}
                          </p>
                          <p className="text-xs text-[#6b7280]">
                            <span className="font-mono">{p.sku}</span>
                            {p.variants_count > 0 && ` · ${p.variants_count} variant${p.variants_count === 1 ? '' : 's'}`}
                            {Boolean(p.is_featured) && ' · Featured'}
                            {Boolean(p.is_flash_deal) &&
                              (() => {
                                const end = flashDealEnd(p.flash_deal_ends_at);
                                if (!end) return ' · Flash deal';
                                return end.getTime() <= Date.now()
                                  ? ' · Flash deal ended'
                                  : ` · Flash deal until ${end.toLocaleString('en-NG', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })}`;
                              })()}
                          </p>
                        </div>
                      </div>
                    </td>
                    <td>
                      {p.category_name || <span className="text-[#6b7280]">No category</span>}
                      {SHOP.style.enabled && p.dress_style && <span className="block text-xs text-[#6b7280]">{p.dress_style}</span>}
                    </td>
                    <td className="whitespace-nowrap tabular-nums">
                      <span className="font-semibold">{formatPrice(price)}</span>
                      {original > price && <span className="ml-1.5 text-xs text-[#6b7280] line-through">{formatPrice(original)}</span>}
                    </td>
                    <td className="whitespace-nowrap">
                      <Link
                        href={`/admin/inventory?search=${encodeURIComponent(p.name)}`}
                        className="inline-flex items-center gap-2 rounded outline-none hover:underline focus-visible:ring-[3px] focus-visible:ring-[#9ca3af]/40"
                        title="Manage stock on the Inventory page"
                      >
                        <span className="tabular-nums">{num(p.total_stock)}</span>
                        {stockPill(p)}
                      </Link>
                    </td>
                    <td>
                      <label htmlFor={`status-${p.id}`} className="sr-only">
                        Status of {p.name}
                      </label>
                      <select
                        id={`status-${p.id}`}
                        value={p.status}
                        disabled={busyId === p.id}
                        onChange={(e) => changeStatus(p, e.target.value as ProductStatus)}
                        className="h-8 rounded-lg border border-[#d1d5db] bg-white px-2 pr-7 text-xs font-medium text-[#111827] outline-none focus:border-[#6b7280] disabled:opacity-50"
                      >
                        <option value="published">Published</option>
                        <option value="draft">Draft</option>
                        <option value="archived">Archived</option>
                      </select>
                    </td>
                    <td className="text-right">
                      <div className="inline-flex gap-1.5">
                        <Button
                          size="sm"
                          variant="ghost"
                          onClick={() => setSharing({ name: p.name, slug: p.slug, status: p.status, price })}
                          aria-label={`Share ${p.name}`}
                          title="Send the product link to a customer"
                        >
                          <Share2 className="size-3.5" aria-hidden="true" />
                          Share
                        </Button>
                        <Button size="sm" onClick={() => openEdit(p)}>
                          Edit
                        </Button>
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </Table>
          <Pagination meta={data?.pagination} onPage={(n) => setFilters({ page: String(n) })} />
        </DataState>
      </Panel>

      <ShareProductModal product={sharing} onClose={() => setSharing(null)} />

      <Modal
        open={editorOpen}
        onClose={closeEditor}
        busy={saving || uploading}
        persistent
        size="xl"
        title={editingId ? `Edit ${form.name || 'product'}` : 'New product'}
        description="Physical products only. Prices are in naira."
        footer={
          <>
            <Button onClick={closeEditor} disabled={saving || uploading}>
              Cancel
            </Button>
            <Button variant="primary" type="submit" form="product-form" loading={saving} disabled={uploading}>
              {editingId ? 'Save product' : 'Create product'}
            </Button>
          </>
        }
      >
        <form id="product-form" onSubmit={handleSave} className="space-y-6" noValidate>
          {formError && (
            <p role="alert" className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-[13px] text-red-700">
              {formError}
            </p>
          )}

          <fieldset className="grid gap-4 sm:grid-cols-2">
            <legend className="mb-2 text-[13px] font-semibold text-[#111827]">Details</legend>
            <Field label="Name" required error={errors.name}>
              {(p) => (
                <TextInput
                  {...p}
                  data-autofocus
                  value={form.name}
                  onChange={(e) => {
                    const name = e.target.value;
                    setForm((f) => ({ ...f, name, slug: slugTouched ? f.slug : slugify(name, 'product') }));
                  }}
                />
              )}
            </Field>
            <Field label="Slug" required error={errors.slug} hint="Used in the product's web address.">
              {(p) => (
                <TextInput
                  {...p}
                  value={form.slug}
                  onChange={(e) => {
                    setSlugTouched(true);
                    update('slug', e.target.value.toLowerCase());
                  }}
                />
              )}
            </Field>
            <Field label="Category" error={errors.category_id}>
              {(p) => (
                <Select {...p} value={form.category_id} onChange={(e) => update('category_id', e.target.value)}>
                  <option value="">No category</option>
                  {categoryOptionsForForm.map((c) => (
                    <option key={c.id} value={String(c.id)}>
                      {c.is_active ? c.name : `${c.name} (hidden)`}
                    </option>
                  ))}
                </Select>
              )}
            </Field>
            {SHOP.style.enabled && (
              <Field label={SHOP.style.label} error={errors.dress_style}>
                {(p) => (
                  <Select {...p} value={form.dress_style} onChange={(e) => update('dress_style', e.target.value)}>
                    <option value="">Not set</option>
                    {styles.map((s) => (
                      <option key={s} value={s}>
                        {s}
                      </option>
                    ))}
                  </Select>
                )}
              </Field>
            )}
            <Field label="Description" error={errors.description} className="sm:col-span-2">
              {(p) => (
                <TextArea
                  {...p}
                  rows={4}
                  placeholder={SHOP.wording.descriptionHint}
                  value={form.description}
                  onChange={(e) => update('description', e.target.value)}
                />
              )}
            </Field>
          </fieldset>

          <fieldset className="grid gap-4 sm:grid-cols-3">
            <legend className="mb-2 text-[13px] font-semibold text-[#111827]">Price and status</legend>
            <Field label="Price (₦)" required error={errors.base_price}>
              {(p) => <TextInput {...p} type="number" min={0} step="0.01" value={form.base_price} onChange={(e) => update('base_price', e.target.value)} />}
            </Field>
            <Field label="Was price (₦)" error={errors.original_price} hint="Optional. Shown crossed out.">
              {(p) => (
                <TextInput {...p} type="number" min={0} step="0.01" value={form.original_price} onChange={(e) => update('original_price', e.target.value)} />
              )}
            </Field>
            <Field label="SKU" required={!!editingId} error={errors.sku} hint={editingId ? undefined : 'Leave blank to generate one.'}>
              {(p) => <TextInput {...p} className="font-mono" value={form.sku} onChange={(e) => update('sku', e.target.value.toUpperCase())} />}
            </Field>
            <Field label="Status" error={errors.status}>
              {(p) => (
                <Select {...p} value={form.status} onChange={(e) => update('status', e.target.value as ProductStatus)}>
                  <option value="draft">Draft (not in the store)</option>
                  <option value="published">Published</option>
                  <option value="archived">Archived</option>
                </Select>
              )}
            </Field>
            <Field label="Starting rating (★)" error={errors.rating_start} hint={ratingHint(editingProduct)}>
              {(p) => (
                <TextInput
                  {...p}
                  type="number"
                  inputMode="decimal"
                  min={1}
                  max={5}
                  step="0.1"
                  placeholder="Store default"
                  value={form.rating_start}
                  onChange={(e) => update('rating_start', e.target.value)}
                />
              )}
            </Field>
            <div className="flex flex-col justify-end gap-2 pb-1 sm:col-span-3">
              <Checkbox label="Featured on the home page" hint="The home page's Featured row shows the 4 newest ticked products; View All lists the rest. Must be Published." checked={form.is_featured} onChange={(v) => update('is_featured', v)} />
              <Checkbox label="Show in flash deals" checked={form.is_flash_deal} onChange={(v) => update('is_flash_deal', v)} />
              {SHOP.features.heavyDelivery && (
                <>
                  <Checkbox
                    label="Large or heavy item (adds the zone's heavy-item fee to delivery)"
                    checked={form.is_heavy}
                    onChange={(v) => update('is_heavy', v)}
                  />
                  {errors.is_heavy && <p className="text-xs text-red-600">{errors.is_heavy}</p>}
                </>
              )}
            </div>
            {form.is_flash_deal && (
              <Field
                label="Flash deal ends"
                error={errors.flash_deal_ends_at}
                hint="Leave empty for no countdown. After this time the product stops showing as a flash deal."
                className="sm:col-span-2"
              >
                {(p) => (
                  <TextInput
                    {...p}
                    type="datetime-local"
                    value={form.flash_deal_ends_at}
                    onChange={(e) => update('flash_deal_ends_at', e.target.value)}
                  />
                )}
              </Field>
            )}
          </fieldset>

          <fieldset className="space-y-3">
            <legend className="mb-2 text-[13px] font-semibold text-[#111827]">Photos ({form.images.length})</legend>
            {errors.images && <Notice tone="danger">{errors.images}</Notice>}
            <div className="flex flex-wrap items-start gap-2">
              <input
                ref={fileRef}
                type="file"
                accept="image/jpeg,image/png,image/webp"
                multiple
                className="hidden"
                onChange={handleUpload}
                aria-hidden="true"
                tabIndex={-1}
              />
              <Button size="sm" onClick={() => fileRef.current?.click()} loading={uploading} disabled={saving}>
                <Upload className="size-3.5" aria-hidden="true" />
                Upload photos
              </Button>
              <div className="min-w-[220px] flex-1">
                <label htmlFor="product-image-url" className="sr-only">
                  Image address
                </label>
                <div className="flex gap-2">
                  <input
                    id="product-image-url"
                    value={newImageUrl}
                    onChange={(e) => setNewImageUrl(e.target.value)}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter') {
                        e.preventDefault();
                        addImageUrl();
                      }
                    }}
                    placeholder="Or paste an image address (/uploads/… or https://…)"
                    aria-invalid={errors.new_image ? true : undefined}
                    aria-describedby={errors.new_image ? 'product-image-url-error' : undefined}
                    className={`${inputClass} h-8 py-1`}
                  />
                  <Button size="sm" onClick={addImageUrl} disabled={!newImageUrl.trim()}>
                    Add
                  </Button>
                </div>
                {errors.new_image && (
                  <p id="product-image-url-error" className="mt-1 text-xs text-red-600">
                    {errors.new_image}
                  </p>
                )}
              </div>
            </div>
            <p className="text-xs text-[#6b7280]">JPG, PNG or WebP. The main photo is shown on product cards.</p>

            {form.images.length === 0 ? (
              <div className="flex items-center gap-2 rounded-lg border border-dashed border-[#d1d5db] px-3 py-4 text-[13px] text-[#6b7280]">
                <ImageOff className="size-4" aria-hidden="true" />
                No photos yet. Products without photos show a neutral placeholder.
              </div>
            ) : (
              <ul className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                {form.images.map((img, idx) => (
                  <li
                    key={`${img.image_url}-${idx}`}
                    className={`flex flex-col gap-1.5 rounded-lg border p-1.5 ${img.is_primary ? 'border-[#111827]' : 'border-[#e5e7eb]'}`}
                  >
                    <div className="relative aspect-square w-full overflow-hidden rounded-md bg-[#f9fafb]">
                      <AdminImage
                        src={img.image_url}
                        fallback={PRODUCT_IMAGE_PLACEHOLDER}
                        alt={img.alt_text || `Photo ${idx + 1}`}
                        fill
                        sizes="(max-width: 640px) 50vw, 160px"
                        className="object-cover"
                      />
                    </div>
                    <div className="flex items-center justify-between gap-1">
                      <label className="flex items-center gap-1.5 text-xs text-[#374151]">
                        <input
                          type="radio"
                          name="primary-image"
                          checked={img.is_primary}
                          onChange={() => update('images', form.images.map((it, i) => ({ ...it, is_primary: i === idx })))}
                          className="accent-[#111827]"
                        />
                        Main photo
                      </label>
                      <button
                        type="button"
                        onClick={() => removeImage(idx)}
                        aria-label={`Remove photo ${idx + 1}`}
                        className="rounded p-1 text-[#6b7280] outline-none hover:bg-red-50 hover:text-red-600 focus-visible:ring-[3px] focus-visible:ring-[#9ca3af]/40"
                      >
                        <Trash2 className="size-3.5" aria-hidden="true" />
                      </button>
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </fieldset>

          <fieldset className="space-y-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <legend className="text-[13px] font-semibold text-[#111827]">
                {SHOP.option1.plural} and {OPTION2_PLURAL} ({form.variants.length})
              </legend>
              <Button size="sm" onClick={() => update('variants', [...form.variants, { ...NEW_VARIANT }])}>
                <Plus className="size-3.5" aria-hidden="true" />
                Add variant
              </Button>
            </div>
            {errors.variants && <Notice tone="danger">{errors.variants}</Notice>}
            <p className="text-xs text-[#6b7280]">
              One row for each choice you sell. Leave a box empty when the product does not come in different {OPTION1_PLURAL} or {OPTION2_PLURAL}.
            </p>
            {editingId && (
              <p className="text-xs text-[#6b7280]">
                Stock on existing variants is changed on the{' '}
                <Link href={`/admin/inventory?search=${encodeURIComponent(form.name)}`} className="font-medium text-[#111827] underline">
                  Inventory page
                </Link>{' '}
                (every change is logged with a reason). Enter starting stock for new variants here.
              </p>
            )}

            {/* One row per variant on wide screens; a small form card per variant on phones and tablets. */}
            <div className="rounded-lg border border-[#e5e7eb]">
              <div
                aria-hidden="true"
                className={`hidden rounded-t-lg bg-[#fafafa] px-2 py-2 text-[11px] font-semibold uppercase tracking-wide text-[#6b7280] lg:grid lg:gap-2 ${VARIANT_GRID}`}
              >
                <span>{SHOP.option1.label}</span>
                <span>{SHOP.option2.label}</span>
                {SWATCHES && <span>Swatch</span>}
                <span>SKU</span>
                <span>Price (₦)</span>
                <span>Stock</span>
                <span>Low-stock alert</span>
                <span />
              </div>
              <ul>
                {form.variants.map((v, idx) => {
                  const rowError = errors[`variant_${idx}`];
                  const label = `Variant ${idx + 1}`;
                  const removeButton = (
                    <button
                      type="button"
                      onClick={() => removeVariant(idx)}
                      disabled={form.variants.length === 1}
                      aria-label={`Remove ${label.toLowerCase()}`}
                      title={form.variants.length === 1 ? 'A product needs at least one variant' : 'Remove variant'}
                      className="rounded p-2 text-[#6b7280] outline-none hover:bg-red-50 hover:text-red-600 focus-visible:ring-[3px] focus-visible:ring-[#9ca3af]/40 disabled:opacity-30 lg:p-1.5"
                    >
                      <Trash2 className="size-4 lg:size-3.5" aria-hidden="true" />
                    </button>
                  );
                  return (
                    <li key={v.id ?? `new-${idx}`} className="border-t border-[#f3f4f6] p-3 first:border-t-0 lg:px-2 lg:py-2">
                      <div className="mb-2 flex items-center justify-between lg:hidden">
                        <span className="text-[13px] font-semibold text-[#111827]">{label}</span>
                        {removeButton}
                      </div>
                      <div className={`grid grid-cols-2 gap-3 sm:grid-cols-3 lg:items-center lg:gap-2 ${VARIANT_GRID}`}>
                        {/* Not a <label>: in its text-box form this field also holds the "back to the list" button. */}
                        <div className={VARIANT_FIELD}>
                          <span className={VARIANT_LABEL}>{SHOP.option1.label}</span>
                          <Option1Field
                            label={label}
                            value={v.size}
                            typed={Boolean(v.size_typed)}
                            invalid={Boolean(rowError)}
                            onChange={(changes) => updateVariant(idx, changes)}
                          />
                        </div>
                        <label className={VARIANT_FIELD}>
                          <span className={VARIANT_LABEL}>{SHOP.option2.label}</span>
                          <input
                            aria-label={SWATCHES ? `${label} ${option2Word} name` : `${label} ${option2Word}`}
                            maxLength={50}
                            value={v.color_name}
                            onChange={(e) => updateVariant(idx, { color_name: e.target.value })}
                            placeholder={SHOP.option2.placeholder}
                            className={`${inputClass} h-9 w-full py-1 lg:h-8`}
                            aria-invalid={rowError ? true : undefined}
                          />
                        </label>
                        {SWATCHES && (
                          <label className={VARIANT_FIELD}>
                            <span className={VARIANT_LABEL}>Swatch</span>
                            <input
                              type="color"
                              aria-label={`${label} ${option2Word} swatch`}
                              value={v.color_hex}
                              onChange={(e) => updateVariant(idx, { color_hex: e.target.value })}
                              className="h-9 w-full cursor-pointer rounded border border-[#d1d5db] bg-white p-0.5 lg:h-8 lg:w-10"
                            />
                          </label>
                        )}
                        <label className={`${VARIANT_FIELD} col-span-2 sm:col-span-1`}>
                          <span className={VARIANT_LABEL}>SKU</span>
                          <input
                            aria-label={`${label} SKU`}
                            value={v.sku}
                            onChange={(e) => updateVariant(idx, { sku: e.target.value.toUpperCase() })}
                            placeholder="Auto"
                            className={`${inputClass} h-9 w-full py-1 font-mono lg:h-8`}
                          />
                        </label>
                        <label className={VARIANT_FIELD}>
                          <span className={VARIANT_LABEL}>Price (₦)</span>
                          <input
                            type="number"
                            inputMode="decimal"
                            min={0}
                            step="0.01"
                            aria-label={`${label} price`}
                            value={v.price_override}
                            onChange={(e) => updateVariant(idx, { price_override: e.target.value })}
                            placeholder="Price"
                            className={`${inputClass} h-9 w-full py-1 lg:h-8`}
                          />
                        </label>
                        {v.id ? (
                          <div className={VARIANT_FIELD}>
                            <span className={VARIANT_LABEL}>Stock</span>
                            <span className="flex h-9 items-center font-semibold tabular-nums lg:h-8" title="Change stock on the Inventory page">
                              {v.stock_quantity}
                            </span>
                          </div>
                        ) : (
                          <label className={VARIANT_FIELD}>
                            <span className={VARIANT_LABEL}>Starting stock</span>
                            <input
                              type="number"
                              inputMode="numeric"
                              min={0}
                              step={1}
                              aria-label={`${label} starting stock`}
                              value={v.stock_quantity}
                              onChange={(e) => updateVariant(idx, { stock_quantity: e.target.value })}
                              className={`${inputClass} h-9 w-full py-1 lg:h-8`}
                            />
                          </label>
                        )}
                        <label className={VARIANT_FIELD}>
                          <span className={VARIANT_LABEL}>Low-stock alert</span>
                          <input
                            type="number"
                            inputMode="numeric"
                            min={0}
                            step={1}
                            aria-label={`${label} low-stock alert level`}
                            value={v.low_stock_threshold}
                            onChange={(e) => updateVariant(idx, { low_stock_threshold: e.target.value })}
                            className={`${inputClass} h-9 w-full py-1 lg:h-8`}
                          />
                        </label>
                        <div className="hidden justify-end lg:flex">{removeButton}</div>
                      </div>
                      {rowError && <p className="mt-2 text-xs text-red-600">{rowError}</p>}
                    </li>
                  );
                })}
              </ul>
            </div>
            <p className="text-xs text-[#6b7280]">Variant price is optional; leave it blank to use the product price.</p>
          </fieldset>

          <fieldset className="space-y-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <legend className="text-[13px] font-semibold text-[#111827]">
                {SHOP.specs.label} ({form.specifications.length})
              </legend>
              <Button
                size="sm"
                onClick={() => update('specifications', [...form.specifications, { name: '', value: '' }])}
                disabled={form.specifications.length >= MAX_SPECIFICATIONS}
                title={form.specifications.length >= MAX_SPECIFICATIONS ? `A product can have up to ${MAX_SPECIFICATIONS} rows` : undefined}
              >
                <Plus className="size-3.5" aria-hidden="true" />
                Add a row
              </Button>
            </div>
            {errors.specifications && <Notice tone="danger">{errors.specifications}</Notice>}
            {form.specifications.length > 0 && (
              <ul className="space-y-2">
                {form.specifications.map((s, idx) => (
                  <li key={idx} className="flex items-start gap-2">
                    <div className="grid min-w-0 flex-1 gap-2 sm:grid-cols-[minmax(0,14rem)_minmax(0,1fr)]">
                      <input
                        aria-label={`Row ${idx + 1} name`}
                        maxLength={MAX_SPECIFICATION_NAME}
                        value={s.name}
                        onChange={(e) => updateSpecification(idx, { name: e.target.value })}
                        placeholder="Name"
                        className={`${inputClass} h-9 py-1`}
                        aria-invalid={errors.specifications && !s.name.trim() && s.value.trim() ? true : undefined}
                      />
                      <input
                        aria-label={`Row ${idx + 1} value`}
                        maxLength={MAX_SPECIFICATION_VALUE}
                        value={s.value}
                        onChange={(e) => updateSpecification(idx, { value: e.target.value })}
                        placeholder="Value"
                        className={`${inputClass} h-9 py-1`}
                      />
                    </div>
                    <button
                      type="button"
                      onClick={() => update('specifications', form.specifications.filter((_, i) => i !== idx))}
                      aria-label={`Remove row ${idx + 1}`}
                      title="Remove row"
                      className="rounded p-2 text-[#6b7280] outline-none hover:bg-red-50 hover:text-red-600 focus-visible:ring-[3px] focus-visible:ring-[#9ca3af]/40"
                    >
                      <Trash2 className="size-4" aria-hidden="true" />
                    </button>
                  </li>
                ))}
              </ul>
            )}
            <p className="text-xs text-[#6b7280]">
              Optional. Short facts about the product, one row each: a name and its value. A row without a value is not saved.
            </p>
            {SHOP.features.warranty && (
              <Field label="Warranty" error={errors.warranty} hint="Optional. Leave it empty when the product has none.">
                {(p) => (
                  <TextInput
                    {...p}
                    maxLength={MAX_WARRANTY}
                    placeholder="e.g. 12 months manufacturer warranty"
                    value={form.warranty}
                    onChange={(e) => update('warranty', e.target.value)}
                  />
                )}
              </Field>
            )}
          </fieldset>
        </form>
      </Modal>
    </div>
  );
}
