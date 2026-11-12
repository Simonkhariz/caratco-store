'use client';

import { SHOP } from '@/lib/shop';
import React, { useState } from 'react';
import { Download, RefreshCw } from 'lucide-react';
import Link from 'next/link';
import { AnalyticsReport, adminDownloadExport, adminGetAnalyticsReport, errorMessage } from '@/lib/admin-api';
import { ITEM_SALES_NOTE, formatDate, formatPrice, gatewayLabel, humanize, num } from '@/lib/admin-format';
import {
  Button,
  DataState,
  FilterSelect,
  Notice,
  PageHeader,
  Panel,
  Table,
  TextInput,
  Toolbar,
  redirectIfSignedOut,
  useAdminData,
  useToast,
  useUrlFilters,
} from '@/components/admin/ui';

const RANGES = [
  { value: 'today', label: 'Today' },
  { value: 'yesterday', label: 'Yesterday' },
  { value: '7days', label: 'Last 7 days' },
  { value: '30days', label: 'Last 30 days' },
  { value: '90days', label: 'Last 90 days' },
  { value: 'year', label: 'This year' },
  { value: 'all', label: 'All time' },
  { value: 'custom', label: 'Custom dates' },
];

/** Sales report for a date range, with CSV exports of the report and of orders. */
export function AdminAnalytics() {
  const toast = useToast();
  const [filters, setFilters] = useUrlFilters({ range: '30days', start_date: '', end_date: '' });
  const isCustom = filters.range === 'custom';
  const customReady = isCustom && !!filters.start_date && !!filters.end_date;

  const params = isCustom
    ? customReady
      ? { range: 'custom', start_date: filters.start_date, end_date: filters.end_date }
      : null
    : { range: filters.range };

  const { data, loading, error, reload } = useAdminData<AnalyticsReport | null>('AdminAnalytics',
    async () => (params ? adminGetAnalyticsReport(params) : null),
    [filters.range, filters.start_date, filters.end_date]
  );

  // Draft custom dates are applied with a button, so half-typed dates don't fire requests.
  // They follow the applied dates whenever the address changes (back/forward, presets).
  const [draftStart, setDraftStart] = useState(filters.start_date);
  const [draftEnd, setDraftEnd] = useState(filters.end_date);
  const appliedDates = `${filters.start_date}|${filters.end_date}`;
  const [draftsFor, setDraftsFor] = useState(appliedDates);
  if (appliedDates !== draftsFor) {
    setDraftsFor(appliedDates);
    setDraftStart(filters.start_date);
    setDraftEnd(filters.end_date);
  }

  const [exporting, setExporting] = useState<'analytics' | 'orders' | null>(null);

  const exportCsv = async (type: 'analytics' | 'orders') => {
    if (!params) return;
    setExporting(type);
    try {
      const q = new URLSearchParams(params as Record<string, string>).toString();
      const endpoint = type === 'analytics' ? `/admin/analytics/export?${q}` : `/admin/orders/export?${q}`;
      await adminDownloadExport(endpoint, `${SHOP.key}-${type}-${params.range}-${new Date().toISOString().slice(0, 10)}.csv`);
      toast.success(type === 'analytics' ? 'Report downloaded.' : 'Orders downloaded.');
    } catch (err) {
      if (redirectIfSignedOut(err)) return;
      toast.error(errorMessage(err, 'The export could not be generated.'));
    } finally {
      setExporting(null);
    }
  };

  const applyCustom = (e: React.FormEvent) => {
    e.preventDefault();
    if (!draftStart || !draftEnd) {
      toast.error('Choose both a start and an end date.');
      return;
    }
    if (draftEnd < draftStart) {
      toast.error('The end date must be on or after the start date.');
      return;
    }
    setFilters({ range: 'custom', start_date: draftStart, end_date: draftEnd });
  };

  return (
    <div className="flex w-full flex-col gap-4">
      <PageHeader
        title="Analytics"
        description="Sales, conversion, categories and payment gateways for a date range. Exports use the same range."
        actions={
          <>
            <Button onClick={() => exportCsv('analytics')} loading={exporting === 'analytics'} disabled={!params || !!exporting}>
              <Download className="size-3.5" aria-hidden="true" />
              Report CSV
            </Button>
            <Button onClick={() => exportCsv('orders')} loading={exporting === 'orders'} disabled={!params || !!exporting}>
              <Download className="size-3.5" aria-hidden="true" />
              Orders CSV
            </Button>
          </>
        }
      />

      <Toolbar>
        <FilterSelect
          label="Date range"
          value={filters.range}
          onChange={(v) => setFilters(v === 'custom' ? { range: v } : { range: v, start_date: '', end_date: '' })}
          options={RANGES}
        />
        {isCustom && (
          <form onSubmit={applyCustom} className="flex flex-wrap items-center gap-2">
            <label className="flex items-center gap-1.5 text-xs text-[#374151]">
              From
              <TextInput type="date" value={draftStart} onChange={(e) => setDraftStart(e.target.value)} className="h-9 w-auto py-1" />
            </label>
            <label className="flex items-center gap-1.5 text-xs text-[#374151]">
              To
              <TextInput type="date" value={draftEnd} onChange={(e) => setDraftEnd(e.target.value)} className="h-9 w-auto py-1" />
            </label>
            <Button type="submit" variant="primary">
              Apply
            </Button>
          </form>
        )}
        <Button variant="ghost" onClick={reload} disabled={!params} aria-label="Refresh report">
          <RefreshCw className="size-3.5" aria-hidden="true" />
        </Button>
      </Toolbar>

      <Notice>
        Sales are counted on the day the payment was verified and refunds on the day they were completed, the same rules as the Overview.
        Orders paid and then cancelled count as owed until you record the refund.
      </Notice>

      {!params ? (
        <Panel>
          <p className="px-4 py-10 text-center text-[13px] text-[#6b7280]">Choose a start and end date, then select Apply.</p>
        </Panel>
      ) : (
        <DataState loading={loading} error={error} onRetry={reload} hasData={!!data} empty={!data} skeleton="page">
          {data && <Report report={data} />}
        </DataState>
      )}
    </div>
  );
}

function Stat({ label, value, sub }: { label: string; value: string; sub?: string }) {
  return (
    <div className="flex min-w-0 flex-col gap-1 rounded-xl border border-[#e5e7eb] bg-white p-4">
      <p className="text-xs font-medium text-[#6b7280]">{label}</p>
      <p className="truncate text-2xl font-semibold tabular-nums text-[#111827]">{value}</p>
      {sub && <p className="text-xs text-[#6b7280]">{sub}</p>}
    </div>
  );
}

function pct(part: number, whole: number): string {
  return whole > 0 ? `${Math.round((part / whole) * 100)}%` : '—';
}

/** "1 paid order", "2 paid orders". */
function count(n: number, noun: string): string {
  return `${n.toLocaleString()} ${noun}${n === 1 ? '' : 's'}`;
}

function Report({ report }: { report: AnalyticsReport }) {
  const s = report.summary;
  const [showFormulas, setShowFormulas] = useState(false);
  const daily = report.daily_breakdown ?? [];
  const categories = report.category_breakdown ?? [];
  const gateways = report.gateway_breakdown ?? [];
  const topProducts = report.top_products ?? [];
  const owed = report.owed_refunds ?? [];
  const formulas = Object.entries(report.formulas ?? {});
  const hasActivity = daily.some((d) => d.orders_count > 0 || d.paid_count > 0 || d.refunds > 0);

  return (
    <div className="flex flex-col gap-4">
      <p className="text-xs text-[#6b7280]">
        {formatDate(report.filters.start_date)} – {formatDate(report.filters.end_date)}
      </p>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5">
        <Stat
          label="Net sales"
          value={formatPrice(num(s.net_sales))}
          sub={`${formatPrice(num(s.gross_sales))} received − ${formatPrice(num(s.total_refunded))} refunded${num(s.owed_refunds) > 0 ? ` − ${formatPrice(num(s.owed_refunds))} owed` : ''}`}
        />
        <Stat
          label="Refunded"
          value={formatPrice(num(s.total_refunded))}
          sub={
            num(s.pending_refunds) > 0
              ? `${count(num(s.refund_count), 'refund')} completed · ${formatPrice(num(s.pending_refunds))} still pending`
              : `${count(num(s.refund_count), 'refund')} completed in this period`
          }
        />
        <Stat label="Average order value" value={formatPrice(num(s.aov))} sub={`From ${count(num(s.paid_orders), 'paid order')}`} />
        <Stat
          label="Units sold"
          value={num(s.total_units_sold).toLocaleString()}
          sub={`${count(num(s.paying_customers), 'paying customer')} · ${num(s.repeat_customer_rate)}% ordered again`}
        />
        <Stat
          label="Placed orders that were paid"
          value={`${num(s.conversion_rate)}%`}
          sub={`Of ${count(num(s.total_orders), 'order')} placed in this period`}
        />
      </div>

      {owed.length > 0 && (
        <Panel title={`Refunds owed (${owed.length})`}>
          <p className="px-4 pt-3 text-[13px] text-[#374151]">
            These orders were paid and then cancelled, and the money has not all been returned. Record each refund once you have sent it.
          </p>
          <Table minWidth={520}>
            <thead>
              <tr>
                <th scope="col">Order</th>
                <th scope="col" className="text-right">Paid</th>
                <th scope="col" className="text-right">Refunded</th>
                <th scope="col" className="text-right">Owed</th>
                <th scope="col">
                  <span className="sr-only">Action</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {owed.map((o) => (
                <tr key={o.order_number}>
                  <td>
                    <Link href={`/admin/orders/${encodeURIComponent(o.order_number)}`} className="font-semibold hover:underline">
                      {o.order_number}
                    </Link>
                  </td>
                  <td className="text-right tabular-nums">{formatPrice(num(o.paid))}</td>
                  <td className="text-right tabular-nums">{formatPrice(num(o.refunded))}</td>
                  <td className="text-right font-semibold tabular-nums text-red-700">{formatPrice(num(o.owed))}</td>
                  <td className="text-right">
                    <Link href={`/admin/refunds?order=${encodeURIComponent(o.order_number)}`} className="text-xs font-semibold text-[#111827] underline">
                      Record refund
                    </Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </Table>
        </Panel>
      )}

      <Panel title="Daily breakdown">
        {!hasActivity ? (
          <p className="px-4 py-8 text-center text-[13px] text-[#6b7280]">No orders, payments or refunds in this range.</p>
        ) : (
          <div className="sm:max-h-[420px] sm:overflow-y-auto">
            <Table minWidth={560}>
              <thead>
                <tr>
                  <th scope="col">Date</th>
                  <th scope="col" className="text-right">Orders placed</th>
                  <th scope="col" className="text-right">Payments received</th>
                  <th scope="col" className="text-right">Sales</th>
                  <th scope="col" className="text-right">Refunds</th>
                </tr>
              </thead>
              <tbody>
                {[...daily].sort((a, b) => (a.date < b.date ? 1 : -1)).map((d) => (
                  <tr key={d.date}>
                    <td>{formatDate(d.date)}</td>
                    <td className="text-right tabular-nums">{num(d.orders_count)}</td>
                    <td className="text-right tabular-nums">{num(d.paid_count)}</td>
                    <td className="text-right tabular-nums">{formatPrice(num(d.sales))}</td>
                    <td className="text-right tabular-nums">{num(d.refunds) > 0 ? `−${formatPrice(num(d.refunds))}` : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </Table>
          </div>
        )}
      </Panel>

      <div className="grid gap-4 xl:grid-cols-2">
        <Panel title="Top products">
          {topProducts.length === 0 ? (
            <p className="px-4 py-8 text-center text-[13px] text-[#6b7280]">No paid sales in this range.</p>
          ) : (
            <>
            <p className="px-4 py-2 text-xs text-[#6b7280]">{ITEM_SALES_NOTE}</p>
            <Table minWidth={420}>
              <thead>
                <tr>
                  <th scope="col">Product</th>
                  <th scope="col" className="text-right">Units</th>
                  <th scope="col" className="text-right">Item sales</th>
                </tr>
              </thead>
              <tbody>
                {topProducts.map((p) => (
                  <tr key={`${p.product_id}-${p.name}`}>
                    <td>{p.name}</td>
                    <td className="text-right tabular-nums">{num(p.units_sold)}</td>
                    <td className="text-right tabular-nums">{formatPrice(num(p.revenue))}</td>
                  </tr>
                ))}
              </tbody>
            </Table>
            </>
          )}
        </Panel>

        <Panel title="Sales by category">
          {categories.length === 0 ? (
            <p className="px-4 py-8 text-center text-[13px] text-[#6b7280]">No paid sales in this range.</p>
          ) : (
            <>
            <p className="px-4 py-2 text-xs text-[#6b7280]">{ITEM_SALES_NOTE}</p>
            <Table minWidth={420}>
              <thead>
                <tr>
                  <th scope="col">Category</th>
                  <th scope="col" className="text-right">Units</th>
                  <th scope="col" className="text-right">Orders</th>
                  <th scope="col" className="text-right">Item sales</th>
                </tr>
              </thead>
              <tbody>
                {categories.map((c) => (
                  <tr key={c.category_name}>
                    <td>{c.category_name}</td>
                    <td className="text-right tabular-nums">{num(c.units_sold)}</td>
                    <td className="text-right tabular-nums">{num(c.order_count)}</td>
                    <td className="text-right tabular-nums">{formatPrice(num(c.revenue))}</td>
                  </tr>
                ))}
              </tbody>
            </Table>
            </>
          )}
        </Panel>
      </div>

      <Panel title="Payment gateways">
        {gateways.length === 0 ? (
          <p className="px-4 py-8 text-center text-[13px] text-[#6b7280]">No payment attempts in this range.</p>
        ) : (
          <Table minWidth={560}>
            <thead>
              <tr>
                <th scope="col">Gateway</th>
                <th scope="col" className="text-right">Attempts</th>
                <th scope="col" className="text-right">Paid</th>
                <th scope="col" className="text-right">Failed</th>
                <th scope="col" className="text-right">Received</th>
              </tr>
            </thead>
            <tbody>
              {gateways.map((g) => (
                <tr key={g.provider_name}>
                  <td>{gatewayLabel(g.provider_name)}</td>
                  <td className="text-right tabular-nums">{num(g.total_attempts)}</td>
                  <td className="text-right tabular-nums">
                    {num(g.successful_count)} <span className="text-xs text-[#6b7280]">({pct(num(g.successful_count), num(g.total_attempts))})</span>
                  </td>
                  <td className="text-right tabular-nums">{num(g.failed_count)}</td>
                  <td className="text-right tabular-nums">{formatPrice(num(g.total_volume))}</td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Panel>

      {formulas.length > 0 && (
        <Panel
          title="How these numbers are calculated"
          actions={
            <Button size="sm" variant="ghost" aria-expanded={showFormulas} aria-controls="analytics-formulas" onClick={() => setShowFormulas((v) => !v)}>
              {showFormulas ? 'Hide' : 'Show'}
            </Button>
          }
        >
          {showFormulas && (
            <dl id="analytics-formulas" className="divide-y divide-[#f3f4f6] text-[13px]">
              {formulas.map(([key, text]) => (
                <div key={key} className="grid gap-1 px-4 py-2.5 sm:grid-cols-[200px_1fr]">
                  <dt className="font-medium text-[#111827]">{key.toLowerCase() === 'aov' ? 'Average order value' : humanize(key)}</dt>
                  <dd className="text-[#374151]">{text}</dd>
                </div>
              ))}
            </dl>
          )}
        </Panel>
      )}
    </div>
  );
}
