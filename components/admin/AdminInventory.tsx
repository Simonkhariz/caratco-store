'use client';

import React, { useState } from 'react';
import Link from 'next/link';
import { InventoryRow, adminAdjustStock, adminGetInventory, adminGetInventoryLogs, errorMessage, fieldErrors } from '@/lib/admin-api';
import { formatDateTime, formatPrice, num } from '@/lib/admin-format';
import { wholeNumber } from '@/lib/admin-logic';
import { SHOP, hasOption2, option1Word, option2Word, optionsText } from '@/lib/shop';
import {
  Button,
  DataState,
  DetailList,
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
  TextInput,
  Toolbar,
  redirectIfSignedOut,
  useAdminData,
  useSearchBox,
  useToast,
  useUrlFilters,
} from '@/components/admin/ui';

const REASONS = ['Restock delivery', 'Stock count correction', 'Damaged / lost', 'Returned to stock', 'Other'];

function stockPill(stock: number, threshold: number) {
  if (stock <= 0) return <StatusPill label="Out of stock" tone="red" />;
  if (stock <= threshold) return <StatusPill label="Low" tone="amber" />;
  return <StatusPill label="In stock" tone="green" />;
}

/** "M / Navy", or a dash for an item without options (the stored "none" values are never printed). */
function variantLabel(size: string, color: string): string {
  return optionsText(size, color) || '—';
}

/** "Product (M / Navy)", "Product · M / Navy · SKU": an item without options is just its name. */
function withOptions(row: { product_name: string; size: string; color_name: string }, open = ' (', close = ')'): string {
  const options = optionsText(row.size, row.color_name);
  return options ? `${row.product_name}${open}${options}${close}` : row.product_name;
}

export function AdminInventory() {
  const [filters, setFilters] = useUrlFilters({ view: 'stock', filter: 'all', search: '', page: '1' });
  const view = filters.view === 'logs' ? 'logs' : 'stock';

  return (
    <div className="flex w-full flex-col">
      <PageHeader title="Inventory" description={`Stock per ${option1Word} and ${option2Word}. Every change is recorded in stock movements.`} />

      <div role="tablist" aria-label="Inventory views" className="mb-3 inline-flex w-fit rounded-lg border border-[#e5e7eb] bg-[#f9fafb] p-0.5">
        {(
          [
            ['stock', 'Stock levels'],
            ['logs', 'Stock movements'],
          ] as const
        ).map(([key, label]) => (
          <button
            key={key}
            type="button"
            role="tab"
            aria-selected={view === key}
            onClick={() => setFilters({ view: key, filter: 'all', search: '', page: '1' })}
            className={`rounded-md px-3 py-1.5 text-[13px] font-medium outline-none transition-colors focus-visible:ring-[3px] focus-visible:ring-[#9ca3af]/40 ${
              view === key ? 'bg-white text-[#111827] shadow-sm' : 'text-[#6b7280] hover:text-[#111827]'
            }`}
          >
            {label}
          </button>
        ))}
      </div>

      {view === 'stock' ? (
        <StockLevels filters={filters} setFilters={setFilters} />
      ) : (
        <StockMovements page={Number(filters.page) || 1} onPage={(p) => setFilters({ page: String(p) })} />
      )}
    </div>
  );
}

type Filters = { view: string; filter: string; search: string; page: string };

function StockLevels({ filters, setFilters }: { filters: Filters; setFilters: (c: Partial<Filters>) => void }) {
  const toast = useToast();
  const page = Number(filters.page) || 1;
  const stockFilter = (['all', 'low_stock', 'out_of_stock', 'low_or_out'].includes(filters.filter) ? filters.filter : 'all') as
    | 'all'
    | 'low_stock'
    | 'out_of_stock'
    | 'low_or_out';

  const [searchText, setSearchText] = useSearchBox(filters.search, setFilters);

  const { data, loading, error, reload } = useAdminData('AdminInventory',
    // 50 rows: every variant (size and colour) is its own row, so a page of 25 holds only a few products.
    () => adminGetInventory({ filter: stockFilter, search: filters.search, page, per_page: 50 }),
    [stockFilter, filters.search, page]
  );
  const rows = data?.items ?? [];

  const [target, setTarget] = useState<InventoryRow | null>(null);
  const [change, setChange] = useState('');
  const [reason, setReason] = useState(REASONS[0]);
  const [note, setNote] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  const openAdjust = (row: InventoryRow) => {
    setTarget(row);
    setChange('');
    setReason(REASONS[0]);
    setNote('');
    setErrors({});
  };

  const current = target ? num(target.stock_quantity) : 0;
  // 2.5 or 1e3 is refused below, never cut down to +2 or +1.
  const amount = wholeNumber(change) ?? 0;
  const validAmount = amount !== 0;
  const resulting = current + amount;

  const handleAdjust = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!target) return;
    const local: Record<string, string> = {};
    // "Below 0" is decided by the server from the live stock: the figure on screen can be old.
    if (!validAmount) local.change = 'Enter a whole number other than 0 (e.g. 10 or -3).';
    if (reason === 'Other' && !note.trim()) local.note = 'Describe the reason.';
    setErrors(local);
    if (Object.keys(local).length) return;

    const reasonText = (reason === 'Other' ? note.trim() : note.trim() ? `${reason} – ${note.trim()}` : reason).slice(0, 240);
    setSaving(true);
    try {
      const saved = await adminAdjustStock({ variant_id: target.id, change_amount: amount, reason: reasonText });
      const name = withOptions(target);
      const now = num(saved?.new_stock ?? resulting);
      // Sales since this screen loaded change the starting figure: say what it really was.
      toast.success(
        saved && num(saved.previous_stock) !== current
          ? `Stock for ${name} is now ${now} (it was ${num(saved.previous_stock)}, not ${current}, when you saved).`
          : `Stock for ${name} is now ${now}.`
      );
      setTarget(null);
      reload();
    } catch (err) {
      if (redirectIfSignedOut(err)) return;
      const fields = fieldErrors(err);
      if (fields.adjustment) fields.change = fields.adjustment;
      setErrors(fields);
      if (!fields.change && !fields.reason) toast.error(errorMessage(err, 'The stock could not be adjusted.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <>
      <Toolbar>
        <SearchInput label="Search inventory" placeholder="Search product or SKU" value={searchText} onChange={setSearchText} />
        <FilterSelect
          label="Stock level"
          value={stockFilter}
          onChange={(v) => setFilters({ filter: v })}
          options={[
            { value: 'all', label: 'All stock levels' },
            { value: 'low_or_out', label: 'Low or sold out' },
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
          empty={rows.length === 0}
          emptyTitle={filters.search || stockFilter !== 'all' ? 'No variants match these filters' : 'No product variants yet'}
          emptyDescription={filters.search || stockFilter !== 'all' ? 'Try a different search or stock level.' : `Add products with ${SHOP.option1.plural.toLowerCase()} and ${SHOP.option2.plural.toLowerCase()} to track stock here.`}
        >
          <Table minWidth={860}>
            <thead>
              <tr>
                <th>Product</th>
                <th>SKU</th>
                <th>
                  {SHOP.option1.label} / {option2Word}
                </th>
                <th>Stock</th>
                <th>Price</th>
                <th>Updated</th>
                <th className="text-right">
                  <span className="sr-only">Actions</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => {
                const stock = num(row.stock_quantity);
                const price = row.price_override !== null && num(row.price_override) > 0 ? num(row.price_override) : num(row.base_price);
                return (
                  <tr key={row.id}>
                    <td>
                      <Link
                        href={`/admin/products?search=${encodeURIComponent(row.product_name)}`}
                        className="font-medium text-[#111827] hover:underline"
                      >
                        {row.product_name}
                      </Link>
                      {row.product_status !== 'published' && <span className="ml-1.5 text-xs text-[#6b7280]">({row.product_status})</span>}
                    </td>
                    <td className="font-mono text-xs text-[#4b5563]">{row.sku}</td>
                    <td>
                      <span className="inline-flex items-center gap-1.5">
                        {SHOP.option2.swatch && hasOption2(row.color_name) && (
                          <span
                            className="size-3.5 shrink-0 rounded-full border border-black/10"
                            style={{ backgroundColor: row.color_hex || '#ffffff' }}
                            aria-hidden="true"
                          />
                        )}
                        {variantLabel(row.size, row.color_name)}
                      </span>
                    </td>
                    <td>
                      <span className="inline-flex items-center gap-2">
                        <span className="font-semibold tabular-nums">{stock}</span>
                        {stockPill(stock, num(row.low_stock_threshold))}
                      </span>
                    </td>
                    <td className="tabular-nums">
                      {formatPrice(price)}
                      {row.price_override !== null && num(row.price_override) > 0 && <span className="ml-1 text-xs text-[#6b7280]">(variant price)</span>}
                    </td>
                    <td className="whitespace-nowrap text-[#4b5563]">{formatDateTime(row.updated_at)}</td>
                    <td className="text-right">
                      <Button size="sm" onClick={() => openAdjust(row)}>
                        Adjust
                      </Button>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </Table>
          <Pagination meta={data?.pagination} onPage={(p) => setFilters({ page: String(p) })} />
        </DataState>
      </Panel>

      <Modal
        open={!!target}
        onClose={() => setTarget(null)}
        busy={saving}
        persistent
        size="sm"
        title="Adjust stock"
        description={target ? `${withOptions(target, ' · ', '')} · ${target.sku}` : undefined}
        footer={
          <>
            <Button onClick={() => setTarget(null)} disabled={saving}>
              Cancel
            </Button>
            <Button variant="primary" type="submit" form="adjust-stock-form" loading={saving}>
              Save adjustment
            </Button>
          </>
        }
      >
        {target && (
          <form id="adjust-stock-form" onSubmit={handleAdjust} className="space-y-4" noValidate>
            <DetailList
              items={[
                { label: 'Current stock', value: <span className="font-semibold tabular-nums">{current}</span> },
                {
                  label: 'After this change',
                  value: <span className={`font-semibold tabular-nums ${resulting < 0 ? 'text-red-600' : ''}`}>{validAmount ? resulting : '—'}</span>,
                },
              ]}
            />
            <Field label="Change" required error={errors.change} hint="Positive adds stock, negative removes it.">
              {(p) => (
                <TextInput {...p} data-autofocus type="number" step={1} inputMode="numeric" placeholder="e.g. 10 or -3" value={change} onChange={(e) => setChange(e.target.value)} />
              )}
            </Field>
            <Field label="Reason" required error={errors.reason}>
              {(p) => (
                <Select {...p} value={reason} onChange={(e) => setReason(e.target.value)}>
                  {REASONS.map((r) => (
                    <option key={r} value={r}>
                      {r}
                    </option>
                  ))}
                </Select>
              )}
            </Field>
            <Field
              label={reason === 'Other' ? 'Describe the reason' : 'Note'}
              required={reason === 'Other'}
              error={errors.note}
              hint={reason === 'Other' ? undefined : 'Optional, e.g. a delivery or count reference.'}
            >
              {(p) => <TextInput {...p} maxLength={180} value={note} onChange={(e) => setNote(e.target.value)} />}
            </Field>
            {validAmount && resulting === 0 && <Notice tone="warning">This variant will show as sold out in the store.</Notice>}
          </form>
        )}
      </Modal>
    </>
  );
}

function StockMovements({ page, onPage }: { page: number; onPage: (page: number) => void }) {
  const { data, loading, error, reload } = useAdminData('AdminInventory#2', () => adminGetInventoryLogs({ page, per_page: 25 }), [page]);
  const rows = data?.items ?? [];

  return (
    <Panel>
      <DataState
        loading={loading}
        error={error}
        onRetry={reload}
        hasData={!!data}
        empty={rows.length === 0}
        emptyTitle="No stock movements yet"
        emptyDescription="Adjustments, new variants and product-form stock edits appear here."
      >
        <Table minWidth={860}>
          <thead>
            <tr>
              <th>When</th>
              <th>Product</th>
              <th>Variant</th>
              <th>Stock</th>
              <th>Change</th>
              <th>Reason</th>
              <th>By</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((log) => {
              const delta = num(log.change_amount);
              return (
                <tr key={log.id}>
                  <td className="whitespace-nowrap text-[#4b5563]">{formatDateTime(log.created_at)}</td>
                  <td className="font-medium text-[#111827]">{log.product_name}</td>
                  <td>
                    {variantLabel(log.size, log.color_name)}
                    <span className="block font-mono text-xs text-[#6b7280]">{log.variant_sku}</span>
                  </td>
                  <td className="whitespace-nowrap tabular-nums">
                    {log.previous_stock} → {log.new_stock}
                  </td>
                  <td className={`whitespace-nowrap font-semibold tabular-nums ${delta > 0 ? 'text-emerald-700' : delta < 0 ? 'text-red-700' : ''}`}>
                    {delta > 0 ? `+${delta}` : delta}
                  </td>
                  <td className="max-w-[260px] break-words">{log.reason}</td>
                  <td className="whitespace-nowrap text-[#4b5563]">{log.adjusted_by_name ?? '—'}</td>
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
