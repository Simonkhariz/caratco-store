'use client';

import React, { useId, useMemo, useState } from 'react';
import Link from 'next/link';
import {
  AlertTriangle,
  ArrowUpRight,
  BarChart3,
  ChevronDown,
  Clock,
  CreditCard,
  Package,
  PackageX,
  ReceiptText,
  RefreshCw,
  Search,
  ShoppingBag,
  Trophy,
  Truck,
  Wallet,
} from 'lucide-react';
import { adminGetOverview, DashboardOverview } from '@/lib/admin-api';
import { ITEM_SALES_NOTE, formatDate, formatDateTime, formatPrice, formatRelative, gatewayLabel, humanize, num, orderStatus, paymentStatus } from '@/lib/admin-format';
import type { StatusTone } from '@/lib/order-status';
import { optionsText } from '@/lib/shop';
import { AdminIcon } from '@/components/admin/AdminIcon';
import { useAdminSession } from '@/components/admin/AdminAuthGate';
import { ErrorState, LoadingState, Notice, useAdminData, useUrlFilters } from '@/components/admin/ui';

const PERIODS = [
  { value: '7', label: 'Last 7 days' },
  { value: '30', label: 'Last 30 days' },
  { value: '90', label: 'Last 90 days' },
];

type Period = 7 | 30 | 90;
type TrendPoint = DashboardOverview['trend'][number];

function toPeriod(value: string): Period {
  return value === '7' ? 7 : value === '90' ? 90 : 30;
}

/**
 * Admin home, laid out like the Kravio dashboard: sparkline KPI cards, a sales bar chart,
 * a "latest updates" feed, workload tiles and the lists that need action. Every figure comes
 * from /admin/dashboard/overview; sales are payments verified in the period minus refunds
 * completed in it, and stock and queue counts are live.
 */
export function AdminOverview() {
  const { admin } = useAdminSession();
  const [filters, setFilters] = useUrlFilters({ period: '30' });
  const period = toPeriod(filters.period);
  const { data, loading, error, reload } = useAdminData('AdminOverview', () => adminGetOverview(period), [period]);
  const firstName = (admin.full_name || '').trim().split(/\s+/)[0];

  return (
    <div className="flex w-full flex-col gap-4">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0">
          <h1 className="flex items-center gap-2 text-2xl font-medium tracking-tight text-[#1f2937]">
            Hello{firstName ? `, ${firstName}` : ''}
            <span aria-hidden="true" className="inline-block origin-[70%_70%] animate-[wave_1.6s_ease-in-out_1]">
              👋
            </span>
          </h1>
          <p className="mt-1.5 text-[13px] text-[#4b5563]">Here is how the store did over the last {period} days.</p>
        </div>
        <div className="flex items-center gap-2">
          <PeriodSelect value={String(period)} onChange={(v) => setFilters({ period: v })} />
          <button
            type="button"
            onClick={reload}
            disabled={loading}
            aria-label="Refresh overview"
            title="Refresh"
            className="flex size-9 items-center justify-center rounded-lg border-[0.8px] border-[#e5e7eb] bg-white text-[#4b5563] shadow-[0_1px_2px_rgba(0,0,0,0.04)] outline-none transition-colors hover:bg-[#f9fafb] hover:text-[#1f2937] focus-visible:ring-[3px] focus-visible:ring-[#9ca3af]/40 disabled:opacity-60"
          >
            <RefreshCw className={`size-4 ${loading && data ? 'animate-spin' : ''}`} aria-hidden="true" />
          </button>
        </div>
      </header>

      {error && data && (
        <Notice tone="danger">
          <span role="alert">
            The figures could not be refreshed: {error} What you see below is from the last successful load (the last {data.period.days} days).{' '}
            <button type="button" onClick={reload} className="font-semibold underline underline-offset-2">
              Try again
            </button>
          </span>
        </Notice>
      )}

      {error && !data ? (
        <Shell title="Overview" icon={<BarChart3 className="size-4" aria-hidden="true" />}>
          <ErrorState message={error} onRetry={reload} />
        </Shell>
      ) : !data ? (
        <Shell title="Overview" icon={<BarChart3 className="size-4" aria-hidden="true" />}>
          <LoadingState label="Loading the overview…" variant="page" />
        </Shell>
      ) : (
        <div className={`flex flex-col gap-4 transition-opacity ${loading ? 'opacity-60' : ''}`}>
          <div className="grid gap-4 xl:grid-cols-[minmax(0,3fr)_minmax(320px,1fr)]">
            <div className="flex min-w-0 flex-col gap-4">
              <KpiCards data={data} />
              <SalesTrend data={data} />
            </div>
            <LatestUpdates data={data} />
          </div>
          <WorkloadTiles data={data} />
          <RecentOrders data={data} />
          <div className="grid gap-4 xl:grid-cols-2">
            <LowStock data={data} />
            <TopProducts data={data} />
          </div>
        </div>
      )}
    </div>
  );
}

// -----------------------------------------------------------------------------
// Building blocks
// -----------------------------------------------------------------------------

/** Kravio's card frame: a grey hatched rim with the title, around a white inner panel. */
function Shell({
  title,
  icon,
  actions,
  children,
  className = '',
  bodyClassName = '',
  iconFirst = false,
}: {
  title: React.ReactNode;
  icon?: React.ReactNode;
  actions?: React.ReactNode;
  children: React.ReactNode;
  className?: string;
  bodyClassName?: string;
  /** Icon before the title (section cards) instead of at the far right (KPI cards). */
  iconFirst?: boolean;
}) {
  const titleId = useId();
  return (
    <section
      aria-labelledby={titleId}
      className={`relative flex min-w-0 flex-col overflow-clip rounded-xl bg-[#f6f6f6] p-1 shadow-[inset_0_0_0_0.8px_#e5e7eb] ${className}`}
    >
      <span
        aria-hidden="true"
        className="pointer-events-none absolute inset-0 bg-[url('/admin/card-pattern.svg')] bg-[length:1639px_560px] bg-[position:center_top] bg-no-repeat"
      />
      <div className="relative flex min-h-[36px] w-full shrink-0 items-center justify-between gap-2 px-2 py-1.5">
        <div className="flex min-w-0 items-center gap-2.5 text-[#4b5563]">
          {iconFirst && icon}
          <h2 id={titleId} className="truncate text-sm font-medium leading-none text-[#4b5563]">
            {title}
          </h2>
        </div>
        <div className="flex shrink-0 items-center gap-2 text-[#4b5563]">
          {actions}
          {!iconFirst && icon}
        </div>
      </div>
      <div className={`relative flex min-h-px w-full flex-1 flex-col rounded-[10px] border-[0.8px] border-[#e5e7eb] bg-white ${bodyClassName}`}>{children}</div>
    </section>
  );
}

function PeriodSelect({ value, onChange }: { value: string; onChange: (value: string) => void }) {
  const id = useId();
  return (
    <div className="relative inline-flex h-9 items-center rounded-lg border-[0.8px] border-[#e5e7eb] bg-white shadow-[0_1px_2px_rgba(0,0,0,0.04)] transition-[border-color,box-shadow] focus-within:border-[#9ca3af] focus-within:shadow-[0_0_0_3px_rgba(156,163,175,0.18)] hover:border-[#d1d5db]">
      <AdminIcon name="calendar" className="pointer-events-none absolute left-2.5" />
      <label htmlFor={id} className="sr-only">
        Period
      </label>
      <select
        id={id}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className="h-full cursor-pointer appearance-none rounded-lg bg-transparent pl-8 pr-8 text-[13px] text-[#1f2937] outline-none"
      >
        {PERIODS.map((p) => (
          <option key={p.value} value={p.value}>
            {p.label}
          </option>
        ))}
      </select>
      <ChevronDown className="pointer-events-none absolute right-2.5 size-3.5 text-[#6b7280]" aria-hidden="true" />
    </div>
  );
}

function SurfaceLink({ href, children }: { href: string; children: React.ReactNode }) {
  return (
    <Link
      href={href}
      className="inline-flex h-7 items-center gap-1 rounded-lg border-[0.8px] border-[#e5e7eb] bg-white px-2.5 text-xs font-medium text-[#1f2937] shadow-[0_1px_2px_rgba(0,0,0,0.04)] outline-none transition-colors hover:bg-[#f9fafb] focus-visible:ring-[3px] focus-visible:ring-[#9ca3af]/40"
    >
      {children}
      <ArrowUpRight className="size-3.5 text-[#6b7280]" aria-hidden="true" />
    </Link>
  );
}

function Delta({ value, days, className = '' }: { value: number | null; days: number; className?: string }) {
  if (value === null) {
    return <span className={`text-[13px] text-[#6b7280] ${className}`}>No earlier data to compare</span>;
  }
  const tone = value > 0 ? 'text-[#059669]' : value < 0 ? 'text-[#dc2626]' : 'text-[#4b5563]';
  return (
    <span className={`flex flex-wrap items-center gap-x-1.5 gap-y-1 ${className}`}>
      <span className={`text-xs font-medium ${tone}`}>
        {value > 0 ? '+' : ''}
        {value.toLocaleString('en-NG', { maximumFractionDigits: 1 })}%
      </span>
      <span className="text-[13px] text-[#6b7280]">vs prior {days} days</span>
    </span>
  );
}

function compactNaira(value: number): string {
  if (value >= 1_000_000) return `₦${(value / 1_000_000).toLocaleString('en-NG', { maximumFractionDigits: 1 })}m`;
  if (value >= 1_000) return `₦${(value / 1_000).toLocaleString('en-NG', { maximumFractionDigits: 1 })}k`;
  return `₦${Math.round(value).toLocaleString('en-NG')}`;
}

function parseDay(date: string): Date | null {
  const d = new Date(`${date}T00:00:00`);
  return Number.isNaN(d.getTime()) ? null : d;
}

function shortDay(date: string, days: number): string {
  const d = parseDay(date);
  if (!d) return date;
  return days <= 7 ? d.toLocaleDateString('en-NG', { weekday: 'short' }) : d.toLocaleDateString('en-NG', { day: 'numeric', month: 'short' });
}

function niceMax(value: number): number {
  if (value <= 0) return 1;
  const magnitude = Math.pow(10, Math.floor(Math.log10(value)));
  for (const s of [1, 2, 2.5, 4, 5, 8, 10]) {
    if (s * magnitude >= value) return s * magnitude;
  }
  return 10 * magnitude;
}

// -----------------------------------------------------------------------------
// KPI cards with sparklines
// -----------------------------------------------------------------------------

/** Groups the daily trend into at most `max` buckets so 90-day sparklines stay readable. */
function bucketTrend(points: TrendPoint[], max = 15): Array<{ sales: number; paid: number }> {
  const size = Math.max(1, Math.ceil(points.length / max));
  const buckets: Array<{ sales: number; paid: number }> = [];
  for (let i = 0; i < points.length; i += size) {
    const slice = points.slice(i, i + size);
    buckets.push({
      sales: slice.reduce((s, p) => s + num(p.sales), 0),
      paid: slice.reduce((s, p) => s + num(p.paid_orders), 0),
    });
  }
  return buckets;
}

function Sparkline({ values, tone }: { values: number[]; tone: 'up' | 'down' | 'flat' }) {
  const gradientId = `spark-${useId().replace(/:/g, '')}`;
  const w = 84;
  const h = 36;
  const color = tone === 'up' ? '#059669' : tone === 'down' ? '#ef4444' : '#9ca3af';
  if (values.length < 2) return null;
  const max = Math.max(...values);
  const min = Math.min(...values);
  const range = max - min || 1;
  const pts = values.map((v, i) => [(i / (values.length - 1)) * (w - 1) + 0.5, h - 1.5 - ((v - min) / range) * (h - 4)] as const);
  const line = pts.map(([x, y], i) => `${i === 0 ? 'M' : 'L'}${x.toFixed(1)} ${y.toFixed(1)}`).join(' ');
  const area = `${line} L${(w - 0.5).toFixed(1)} ${h} L0.5 ${h} Z`;
  return (
    <svg
      viewBox={`0 0 ${w} ${h}`}
      preserveAspectRatio="none"
      fill="none"
      aria-hidden="true"
      className="animate-draw h-9 w-[60px] shrink-0 transition-transform duration-300 group-hover/kpi:scale-[1.04] max-sm:w-[84px] 2xl:w-[84px]"
      style={{ transformOrigin: 'bottom right' }}
    >
      <defs>
        <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
          <stop stopColor={color} stopOpacity="0.3" />
          <stop offset="1" stopColor={color} stopOpacity="0" />
        </linearGradient>
      </defs>
      <path d={area} fill={`url(#${gradientId})`} />
      <path d={line} stroke={color} strokeWidth="1" strokeLinejoin="round" vectorEffect="non-scaling-stroke" />
    </svg>
  );
}

function KpiCard({
  title,
  icon,
  value,
  delta,
  days,
  series,
  hint,
  index,
}: {
  title: string;
  icon: React.ReactNode;
  value: string;
  delta: number | null;
  days: number;
  series: number[];
  hint?: string;
  index: number;
}) {
  const tone = delta === null || delta === 0 ? 'flat' : delta > 0 ? 'up' : 'down';
  return (
    <div className="group/kpi animate-rise min-w-0" style={{ animationDelay: `${80 + index * 70}ms` }}>
      <Shell
        title={title}
        icon={<span className="transition-transform duration-300 group-hover/kpi:-rotate-12 group-hover/kpi:scale-110">{icon}</span>}
        className="h-full min-h-[140px]"
        bodyClassName="justify-end px-3 pb-3 pt-6 transition-[box-shadow,transform] duration-300 group-hover/kpi:-translate-y-px group-hover/kpi:shadow-[0px_2px_3px_-1px_rgba(0,0,0,0.1),0px_0px_0px_1px_rgba(25,28,33,0.08)]"
      >
        <p className="mb-2 text-2xl font-medium leading-none tabular-nums text-[#1f2937]" title={hint ? `${value} · ${hint}` : value}>
          {value}
        </p>
        <div className="flex w-full items-end justify-between gap-2">
          <Delta value={delta} days={days} className="min-w-0 pb-0.5" />
          <Sparkline values={series} tone={tone} />
        </div>
      </Shell>
    </div>
  );
}

function KpiCards({ data }: { data: DashboardOverview }) {
  const { kpis, deltas, period } = data;
  const buckets = useMemo(() => bucketTrend(data.trend), [data.trend]);
  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
      <KpiCard
        index={0}
        title="Net sales"
        icon={<Wallet className="size-4" aria-hidden="true" />}
        value={formatPrice(num(kpis.net_sales))}
        hint={`${formatPrice(num(kpis.gross_sales))} received, ${formatPrice(num(kpis.total_refunded))} refunded${num(kpis.owed_refunds) > 0 ? `, ${formatPrice(num(kpis.owed_refunds))} owed on cancelled orders` : ''}`}
        delta={deltas.net_sales}
        days={period.days}
        series={buckets.map((b) => b.sales)}
      />
      <KpiCard
        index={1}
        title="Paid orders"
        icon={<ShoppingBag className="size-4" aria-hidden="true" />}
        value={num(kpis.paid_orders).toLocaleString()}
        delta={deltas.paid_orders}
        days={period.days}
        series={buckets.map((b) => b.paid)}
      />
      <KpiCard
        index={2}
        title="Average order value"
        icon={<ReceiptText className="size-4" aria-hidden="true" />}
        value={formatPrice(num(kpis.aov))}
        delta={deltas.aov}
        days={period.days}
        series={buckets.map((b) => (b.paid > 0 ? b.sales / b.paid : 0))}
      />
    </div>
  );
}

// -----------------------------------------------------------------------------
// Sales trend bar chart
// -----------------------------------------------------------------------------

const CHART_HEIGHT = 216;

function SalesTrend({ data }: { data: DashboardOverview }) {
  const points = data.trend;
  const days = data.period.days;
  const [hover, setHover] = useState<number | null>(null);
  const [picked, setPicked] = useState<number | null>(null);
  const [showTable, setShowTable] = useState(false);

  const values = points.map((p) => num(p.sales));
  // Money refunded on each day (completed refunds). Drawn as the red top of that day's bar.
  const refunds = points.map((p) => num(p.refunds));
  const refundTotal = refunds.reduce((s, v) => s + v, 0);
  // A bar is as tall as the larger of the two, so a refund on a day with no sales still shows.
  const heights = points.map((_, i) => Math.max(values[i], refunds[i]));
  const total = values.reduce((s, v) => s + v, 0);
  const top = niceMax(Math.max(0, ...heights));
  const ticks = [top, (top * 3) / 4, top / 2, top / 4, 0];
  const bestIndex = total > 0 ? values.indexOf(Math.max(...values)) : null;
  const active = hover ?? picked ?? bestIndex;
  const activePoint = active !== null ? points[active] : null;
  const activeValue = active !== null ? values[active] : 0;
  const activeRefund = active !== null ? refunds[active] : 0;
  const activeHeight = ((active !== null ? heights[active] : 0) / top) * 100;
  const labelEvery = Math.max(1, Math.ceil(points.length / 7));
  const thin = points.length > 31;
  const center = active !== null ? ((active + 0.5) / points.length) * 100 : 0;
  const tooltipShift = center < 12 ? '-12%' : center > 88 ? '-88%' : '-50%';

  const onKeyDown = (e: React.KeyboardEvent) => {
    if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft' && e.key !== 'Home' && e.key !== 'End') return;
    e.preventDefault();
    const current = active ?? 0;
    const next =
      e.key === 'Home' ? 0 : e.key === 'End' ? points.length - 1 : Math.min(points.length - 1, Math.max(0, current + (e.key === 'ArrowRight' ? 1 : -1)));
    setPicked(next);
    setHover(null);
  };

  return (
    <div className="animate-rise [animation-delay:260ms]">
      <Shell
        title="Sales trend"
        iconFirst
        icon={<BarChart3 className="size-4" aria-hidden="true" />}
        actions={
          <button
            type="button"
            aria-pressed={showTable}
            onClick={() => setShowTable((s) => !s)}
            className="inline-flex h-7 items-center rounded-lg border-[0.8px] border-[#e5e7eb] bg-white px-2.5 text-xs font-medium text-[#1f2937] shadow-[0_1px_2px_rgba(0,0,0,0.04)] outline-none transition-colors hover:bg-[#f9fafb] focus-visible:ring-[3px] focus-visible:ring-[#9ca3af]/40"
          >
            {showTable ? 'Show chart' : 'Show as table'}
          </button>
        }
        bodyClassName="gap-5 px-3.5 pb-3.5 pt-4"
      >
        <div className="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
          <div className="flex flex-wrap items-end gap-x-3 gap-y-1.5 leading-none">
            <p className="text-[32px] font-medium tabular-nums text-[#1f2937]">{formatPrice(total)}</p>
            <Delta value={data.deltas.gross_sales} days={days} className="pb-1" />
          </div>
          <dl className="flex flex-wrap gap-x-5 gap-y-2 text-xs">
            <MiniStat label="Refunded" value={formatPrice(num(data.kpis.total_refunded))} />
            {num(data.kpis.pending_refunds) > 0 && <MiniStat label="Refunds pending" value={formatPrice(num(data.kpis.pending_refunds))} />}
            {num(data.kpis.owed_refunds) > 0 && <MiniStat label="Refunds owed" value={formatPrice(num(data.kpis.owed_refunds))} />}
            <MiniStat label="Orders placed" value={num(data.kpis.total_orders).toLocaleString()} delta={data.deltas.total_orders} />
            <MiniStat label="New customers" value={num(data.kpis.new_customers).toLocaleString()} delta={data.deltas.new_customers} />
          </dl>
        </div>
        <p className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-[#6b7280]">
          <span className="inline-flex items-center gap-1.5">
            <span className="size-2.5 rounded-sm bg-[#d1d5db]" aria-hidden="true" />
            Payments verified each day
          </span>
          <span className="inline-flex items-center gap-1.5">
            <span className="size-2.5 rounded-sm bg-[#ef4444]" aria-hidden="true" />
            Refunded that day
          </span>
          {num(data.kpis.pending_refunds) > 0 && <span>A pending refund shows here once you mark it completed.</span>}
        </p>

        {showTable ? (
          <div className="max-h-[300px] overflow-auto rounded-lg border-[0.8px] border-[#e5e7eb]">
            <table className="w-full min-w-[500px] border-collapse text-left text-[13px]">
              <thead className="sticky top-0 bg-[#f9fafb] text-xs text-[#4b5563]">
                <tr>
                  <th scope="col" className="px-3 py-2 font-medium">Date</th>
                  <th scope="col" className="px-3 py-2 text-right font-medium">Sales</th>
                  <th scope="col" className="px-3 py-2 text-right font-medium">Refunded</th>
                  <th scope="col" className="px-3 py-2 text-right font-medium">Paid orders</th>
                  <th scope="col" className="px-3 py-2 text-right font-medium">Orders placed</th>
                </tr>
              </thead>
              <tbody>
                {[...points].sort((a, b) => (a.date < b.date ? 1 : -1)).map((p) => (
                  <tr key={p.date} className="border-t border-[#f3f4f6]">
                    <td className="px-3 py-2">{formatDate(p.date)}</td>
                    <td className="px-3 py-2 text-right tabular-nums">{formatPrice(num(p.sales))}</td>
                    <td className="px-3 py-2 text-right tabular-nums">{num(p.refunds) > 0 ? `−${formatPrice(num(p.refunds))}` : '—'}</td>
                    <td className="px-3 py-2 text-right tabular-nums">{num(p.paid_orders)}</td>
                    <td className="px-3 py-2 text-right tabular-nums">{num(p.total_orders)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <figure className="flex w-full items-start gap-3">
            <figcaption className="sr-only">
              {total > 0
                ? `Daily verified sales over the last ${days} days, ${formatPrice(total)} in total${refundTotal > 0 ? `, with ${formatPrice(refundTotal)} refunded` : ''}. Use the left and right arrow keys to read each day.`
                : `No verified sales in the last ${days} days.`}
            </figcaption>
            <div className="flex min-w-0 flex-1 flex-col gap-3">
              <div
                role="group"
                tabIndex={0}
                aria-label="Sales per day. Use left and right arrow keys to inspect days."
                onKeyDown={onKeyDown}
                onMouseLeave={() => setHover(null)}
                className="relative w-full rounded-sm outline-none focus-visible:ring-[3px] focus-visible:ring-[#9ca3af]/40"
                style={{ height: CHART_HEIGHT }}
              >
                {/* Grid */}
                {ticks.map((t) => (
                  <span
                    key={t}
                    aria-hidden="true"
                    className={`pointer-events-none absolute inset-x-0 border-t ${t === 0 ? 'border-[#e5e7eb]' : 'border-[#f2f2f3]'}`}
                    style={{ top: `${100 - (t / top) * 100}%` }}
                  />
                ))}

                {/* Bars */}
                <div className="absolute inset-0 flex items-end">
                  {points.map((p, i) => {
                    const v = heights[i];
                    const refunded = refunds[i];
                    const isActive = i === active;
                    const height = v > 0 ? Math.max(1.5, (v / top) * 100) : 0;
                    return (
                      <div
                        key={p.date}
                        className="flex h-full min-w-0 flex-1 items-end justify-center"
                        onMouseEnter={() => setHover(i)}
                      >
                        <div
                          className="animate-grow relative w-[64%] max-w-[56px] transition-[height] duration-700 ease-[var(--ease-out-expo)]"
                          style={{ height: v > 0 ? `${height}%` : 2, animationDelay: `${380 + Math.min(i, 30) * 25}ms` }}
                        >
                          <div
                            className={`absolute inset-0 transition-opacity duration-300 ${thin ? 'rounded-t-[3px]' : 'rounded-b-[4px] rounded-t-[8px]'} ${
                              isActive ? 'opacity-0' : 'opacity-100'
                            } bg-gradient-to-b from-[#e6e6e6] to-[rgba(230,230,230,0.6)] shadow-[inset_0_0_0_0.444px_#d8d8d8,inset_0_0_0_1px_white]`}
                          />
                          <div
                            className={`absolute inset-0 transition-opacity duration-300 ${thin ? 'rounded-t-[3px]' : 'rounded-b-[4px] rounded-t-[8px]'} ${
                              isActive ? 'opacity-100' : 'opacity-0'
                            } bg-[linear-gradient(180deg,#37475d_0%,#1f2937_64.7%)]`}
                          />
                          {/* The part of the day's money that was refunded (the whole bar when only a refund happened). */}
                          {refunded > 0 && (
                            <div
                              className={`absolute inset-x-0 top-0 bg-[#ef4444] ${thin ? 'rounded-t-[3px]' : 'rounded-t-[8px]'}`}
                              style={{ height: `${Math.max(6, Math.min(100, (refunded / v) * 100))}%` }}
                            />
                          )}
                        </div>
                      </div>
                    );
                  })}
                </div>

                {/* Active day marker */}
                {activePoint && (activeValue > 0 || activeRefund > 0) && (
                  <>
                    <span
                      aria-hidden="true"
                      className="pointer-events-none absolute inset-x-0 border-t border-dashed border-[#6b7280]/60 transition-[bottom] duration-300"
                      style={{ bottom: `${activeHeight}%` }}
                    />
                    <span
                      aria-hidden="true"
                      className="pointer-events-none absolute z-10 whitespace-nowrap rounded-md bg-[#1f2937] px-2 py-1 text-xs font-medium text-white shadow-[0_4px_10px_rgba(0,0,0,0.12)] transition-[left,bottom] duration-300"
                      style={{ left: `${center}%`, bottom: `calc(${activeHeight}% + 8px)`, transform: `translateX(${tooltipShift})` }}
                    >
                      {shortDay(activePoint.date, 30)} : {formatPrice(activeValue)}
                      {activeRefund > 0 && <span className="text-[#fca5a5]"> · {formatPrice(activeRefund)} refunded</span>}
                    </span>
                  </>
                )}

                {total === 0 && (
                  <p className="absolute inset-0 flex items-center justify-center text-[13px] text-[#6b7280]">No verified payments in this period yet.</p>
                )}
              </div>

              {/* X axis */}
              <div className="flex text-[13px] text-[#4b5563]" aria-hidden="true">
                {points.map((p, i) => (
                  // On phones only every other label shows, so dates never overlap.
                  <span
                    key={p.date}
                    className={`min-w-0 flex-1 overflow-visible whitespace-nowrap text-center ${points.length > 7 && (i / labelEvery) % 2 === 1 ? 'max-sm:invisible' : ''}`}
                  >
                    {i % labelEvery === 0 ? shortDay(p.date, days) : ''}
                  </span>
                ))}
              </div>
              <p className="sr-only" aria-live="polite">
                {activePoint
                  ? `${formatDate(activePoint.date)}: ${formatPrice(activeValue)}${activeRefund > 0 ? `, ${formatPrice(activeRefund)} refunded` : ''}, ${num(activePoint.paid_orders)} paid of ${num(activePoint.total_orders)} placed`
                  : ''}
              </p>
            </div>

            {/* Y axis (right, as in Kravio) */}
            <div className="relative w-12 shrink-0 text-right text-[13px] tabular-nums text-[#4b5563]" style={{ height: CHART_HEIGHT }} aria-hidden="true">
              {total > 0 &&
                ticks.map((t) => (
                  <span key={t} className="absolute right-0 -translate-y-1/2" style={{ top: `${100 - (t / top) * 100}%` }}>
                    {compactNaira(t)}
                  </span>
                ))}
            </div>
          </figure>
        )}

        {activePoint && !showTable && (
          <p className="-mt-2 text-xs text-[#6b7280]" aria-hidden="true">
            <span className="font-medium text-[#1f2937]">{formatDate(activePoint.date)}</span> · {num(activePoint.paid_orders)} paid of{' '}
            {num(activePoint.total_orders)} placed{active === bestIndex && hover === null && picked === null ? ' · best day in this period' : ''}
          </p>
        )}
      </Shell>
    </div>
  );
}

function MiniStat({ label, value, delta }: { label: string; value: string; delta?: number | null }) {
  return (
    <div className="flex flex-col gap-1">
      <dt className="text-[#6b7280]">{label}</dt>
      <dd className="flex items-baseline gap-1.5">
        <span className="text-sm font-medium tabular-nums text-[#1f2937]">{value}</span>
        {delta !== undefined && delta !== null && (
          <span className={`text-[11px] font-medium ${delta > 0 ? 'text-[#059669]' : delta < 0 ? 'text-[#dc2626]' : 'text-[#4b5563]'}`}>
            {delta > 0 ? '+' : ''}
            {delta.toLocaleString('en-NG', { maximumFractionDigits: 1 })}%
          </span>
        )}
      </dd>
    </div>
  );
}

// -----------------------------------------------------------------------------
// Latest updates feed
// -----------------------------------------------------------------------------

type FeedKind = 'orders' | 'payments' | 'stock';

interface FeedItem {
  key: string;
  kind: FeedKind;
  title: string;
  detail: string;
  /** Timestamp shown on the right; stock rows have none and show `side` instead. */
  time: string | null;
  side?: string;
  href: string;
  icon: React.ReactNode;
  tone: 'blue' | 'red' | 'amber' | 'green';
  urgent: boolean;
}

const FEED_TABS: Array<{ value: 'all' | FeedKind; label: string }> = [
  { value: 'all', label: 'All' },
  { value: 'orders', label: 'Orders' },
  { value: 'payments', label: 'Payments' },
  { value: 'stock', label: 'Stock' },
];

const FEED_TONES: Record<FeedItem['tone'], string> = {
  blue: 'text-[#2563eb] bg-[#eff6ff] border-[#dbeafe]',
  red: 'text-[#dc2626] bg-[#fef2f2] border-[#fee2e2]',
  amber: 'text-[#d97706] bg-[#fffbeb] border-[#fef3c7]',
  green: 'text-[#059669] bg-[#ecfdf5] border-[#d1fae5]',
};

function orderHref(orderNumber: string) {
  return `/admin/orders/${encodeURIComponent(orderNumber)}`;
}

function timeOf(value: string | null): number {
  if (!value) return 0;
  const t = new Date(value.replace(' ', 'T')).getTime();
  return Number.isNaN(t) ? 0 : t;
}

function buildFeed(data: DashboardOverview): FeedItem[] {
  const items: FeedItem[] = [];
  data.attention_orders.forEach((o) =>
    items.push({
      key: `attention-${o.order_number}`,
      kind: 'orders',
      title: 'Order needs attention',
      detail: `${o.order_number} · ${o.attention_reason ? humanize(o.attention_reason) : 'Flagged for review'}`,
      time: o.updated_at,
      href: orderHref(o.order_number),
      icon: <AlertTriangle className="size-3.5" aria-hidden="true" />,
      tone: 'red',
      urgent: true,
    })
  );
  data.payment_alerts.forEach((p) => {
    const unconfirmed = p.status === 'pending';
    items.push({
      key: `payment-${p.id}`,
      kind: 'payments',
      title: unconfirmed ? 'Payment not confirmed yet' : 'Payment failed',
      detail: `${p.order_number} · ${gatewayLabel(p.gateway)} · ${formatPrice(num(p.amount))}`,
      time: p.created_at,
      href: orderHref(p.order_number),
      icon: unconfirmed ? <Clock className="size-3.5" aria-hidden="true" /> : <CreditCard className="size-3.5" aria-hidden="true" />,
      tone: unconfirmed ? 'amber' : 'red',
      urgent: true,
    });
  });
  data.recent_orders.forEach((o) => {
    const status = orderStatus(o.order_status);
    items.push({
      key: `order-${o.id}`,
      kind: 'orders',
      title: 'New order',
      detail: `${o.order_number} · ${o.customer_name || o.customer_email || 'Guest'} · ${formatPrice(num(o.total_amount))} · ${status.label}`,
      time: o.created_at,
      href: orderHref(o.order_number),
      icon: <ShoppingBag className="size-3.5" aria-hidden="true" />,
      tone: o.order_status === 'cancelled' ? 'amber' : num(o.requires_attention) ? 'red' : 'blue',
      urgent: false,
    });
  });
  items.sort((a, b) => timeOf(b.time) - timeOf(a.time));

  // Stock rows have no event time; they go after the dated updates.
  data.critical_stock.forEach((v) => {
    const stock = num(v.stock_quantity);
    items.push({
      key: `stock-${v.id}`,
      kind: 'stock',
      title: stock === 0 ? 'Sold out' : 'Low stock',
      // An item without options is just its name (the stored "none" values are never printed).
      detail: [v.product_name, optionsText(v.size, v.color_name)].filter(Boolean).join(' · '),
      time: null,
      side: stock === 0 ? 'None left' : `${stock} left`,
      href: stock === 0 ? '/admin/inventory?filter=out_of_stock' : '/admin/inventory?filter=low_stock',
      icon: stock === 0 ? <PackageX className="size-3.5" aria-hidden="true" /> : <Package className="size-3.5" aria-hidden="true" />,
      tone: stock === 0 ? 'red' : 'amber',
      urgent: true,
    });
  });
  return items;
}

function LatestUpdates({ data }: { data: DashboardOverview }) {
  const [tab, setTab] = useState<'all' | FeedKind>('all');
  const [query, setQuery] = useState('');
  const searchId = useId();
  const feed = useMemo(() => buildFeed(data), [data]);
  const q = query.trim().toLowerCase();
  const visible = feed.filter((f) => (tab === 'all' || f.kind === tab) && (!q || `${f.title} ${f.detail}`.toLowerCase().includes(q)));
  const urgent = visible.filter((f) => f.urgent).length;
  const tabIndex = FEED_TABS.findIndex((t) => t.value === tab);

  return (
    // On wide screens the feed fills the height of the KPI + chart column and scrolls inside it.
    <div className="animate-rise relative min-w-0 [animation-delay:300ms]">
      <Shell title="Latest updates" icon={<AdminIcon name="news" />} className="h-full xl:absolute xl:inset-0" bodyClassName="min-h-0 gap-4 px-3.5 pb-3.5 pt-4">
        <div className="flex flex-col gap-3">
          <div role="group" aria-label="Show updates for" className="relative grid grid-cols-4 gap-2">
            <span
              aria-hidden="true"
              className="absolute top-0 h-7 rounded-lg border border-[#1f2937] bg-[linear-gradient(180deg,#37475d_0%,#1f2937_64.697%)] transition-[left] duration-300 ease-[var(--ease-out-expo)]"
              style={{ width: 'calc((100% - 24px) / 4)', left: `calc(((100% - 24px) / 4 + 8px) * ${tabIndex})` }}
            />
            {FEED_TABS.map((t) => (
              <button
                key={t.value}
                type="button"
                aria-pressed={tab === t.value}
                onClick={() => setTab(t.value)}
                className={`relative z-[1] h-7 min-w-0 truncate rounded-lg border-[0.8px] px-1 text-xs font-medium outline-none transition-colors focus-visible:ring-[3px] focus-visible:ring-[#9ca3af]/40 ${
                  tab === t.value ? 'border-transparent text-white' : 'border-[#e5e7eb] text-[#1f2937] hover:bg-[#f9fafb]'
                }`}
              >
                {t.label}
              </button>
            ))}
          </div>
          <label
            htmlFor={searchId}
            className="flex h-8 cursor-text items-center gap-2 rounded-lg border-[0.8px] border-[#e5e7eb] bg-white pl-2.5 pr-2 transition-[border-color,box-shadow] hover:border-[#d1d5db] focus-within:border-[#9ca3af] focus-within:shadow-[0_0_0_3px_rgba(156,163,175,0.18)]"
          >
            <Search className="size-4 text-[#6b7280]" aria-hidden="true" />
            <span className="sr-only">Search updates</span>
            <input
              id={searchId}
              type="search"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="Search updates"
              className="min-w-0 flex-1 bg-transparent text-[13px] text-[#1f2937] outline-none placeholder:text-[#6b7280] [&::-webkit-search-cancel-button]:hidden"
            />
            {query && (
              <button type="button" onClick={() => setQuery('')} className="text-xs text-[#6b7280] hover:text-[#1f2937]">
                Clear
              </button>
            )}
          </label>
        </div>

        <p className="text-[13px] text-[#4b5563]" aria-live="polite">
          <span className="text-base font-medium text-[#1f2937]">{visible.length}</span> update{visible.length === 1 ? '' : 's'}
          {urgent > 0 && <span className="text-[#b45309]"> · {urgent} need{urgent === 1 ? 's' : ''} action</span>}
        </p>

        {visible.length === 0 ? (
          <div className="flex flex-1 flex-col items-center justify-center gap-1 py-8 text-center">
            <p className="text-[13px] font-medium text-[#1f2937]">{q ? 'No updates match' : 'All quiet'}</p>
            <p className="text-xs text-[#6b7280]">{q ? 'Try another search.' : 'New orders, payment problems and low stock show up here.'}</p>
          </div>
        ) : (
          <ol className="-mx-1 flex min-h-0 flex-1 flex-col overflow-y-auto px-1 max-xl:max-h-[440px]">
            {visible.map((f, i) => (
              <li key={f.key} className="relative pb-3 last:pb-0">
                {i < visible.length - 1 && (
                  <span aria-hidden="true" className="absolute bottom-0 left-[14px] top-[32px] border-l border-dashed border-[#e5e7eb]" />
                )}
                <Link
                  href={f.href}
                  className="group flex items-start gap-3 rounded-lg outline-none focus-visible:ring-[3px] focus-visible:ring-[#9ca3af]/40"
                >
                  <span className={`flex size-[29px] shrink-0 items-center justify-center rounded-lg border-[0.8px] ${FEED_TONES[f.tone]}`}>{f.icon}</span>
                  <span className="flex min-w-0 flex-1 flex-col gap-1 pt-0.5">
                    <span className="flex items-start justify-between gap-2">
                      <span className="truncate text-xs font-medium text-[#1f2937] group-hover:underline">{f.title}</span>
                      <span
                        className="shrink-0 text-[11px] tabular-nums text-[#6b7280]"
                        title={f.time ? formatDateTime(f.time) : undefined}
                      >
                        {f.time ? formatRelative(f.time) : f.side}
                      </span>
                    </span>
                    <span className="line-clamp-2 text-xs text-[#6b7280]">{f.detail}</span>
                  </span>
                </Link>
              </li>
            ))}
          </ol>
        )}
      </Shell>
    </div>
  );
}

// -----------------------------------------------------------------------------
// Workload tiles
// -----------------------------------------------------------------------------

function WorkloadTiles({ data }: { data: DashboardOverview }) {
  const { kpis } = data;
  const lowOrOut = num(kpis.low_stock_count) + num(kpis.out_of_stock_count);
  const tiles = [
    {
      title: 'Needs attention',
      icon: <AlertTriangle className="size-4" aria-hidden="true" />,
      value: num(kpis.needs_attention),
      hint: 'Late, duplicate or mismatched payments',
      href: '/admin/orders?status=attention',
      urgent: num(kpis.needs_attention) > 0,
    },
    {
      title: 'To ship',
      icon: <Truck className="size-4" aria-hidden="true" />,
      value: num(kpis.pending_fulfillment),
      hint: 'Paid or processing orders',
      href: '/admin/shipping',
      urgent: false,
    },
    {
      title: 'Awaiting payment',
      icon: <Clock className="size-4" aria-hidden="true" />,
      value: num(kpis.awaiting_payment),
      hint: 'Still within their payment window',
      href: '/admin/orders?status=pending_payment',
      urgent: false,
    },
    {
      title: 'Low or sold out',
      icon: <Package className="size-4" aria-hidden="true" />,
      value: lowOrOut,
      hint: `${num(kpis.low_stock_count)} low · ${num(kpis.out_of_stock_count)} sold out`,
      href: '/admin/inventory?filter=low_or_out',
      urgent: num(kpis.out_of_stock_count) > 0,
    },
  ];

  return (
    <div className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
      {tiles.map((t, i) => (
        <div key={t.title} className="group/kpi animate-rise min-w-0" style={{ animationDelay: `${340 + i * 50}ms` }}>
          <Shell title={t.title} icon={t.icon} className="h-full" bodyClassName="p-0">
            <Link
              href={t.href}
              className="flex flex-1 items-end justify-between gap-3 rounded-[10px] px-3 pb-3 pt-5 outline-none transition-[box-shadow,transform] duration-300 group-hover/kpi:-translate-y-px group-hover/kpi:shadow-[0px_2px_3px_-1px_rgba(0,0,0,0.1),0px_0px_0px_1px_rgba(25,28,33,0.08)] focus-visible:ring-[3px] focus-visible:ring-[#9ca3af]/40"
            >
              <span className="flex min-w-0 flex-col gap-2 leading-none">
                <span className="flex items-center gap-2 text-2xl font-medium tabular-nums text-[#1f2937]">
                  {t.value.toLocaleString()}
                  {t.urgent && <span className="size-2 rounded-full bg-[#ef4444]" aria-label="needs action" role="img" />}
                </span>
                <span className="line-clamp-2 text-xs leading-snug text-[#6b7280]">{t.hint}</span>
              </span>
              <ArrowUpRight className="size-4 shrink-0 text-[#9ca3af] transition-colors group-hover/kpi:text-[#1f2937]" aria-hidden="true" />
            </Link>
          </Shell>
        </div>
      ))}
    </div>
  );
}

// -----------------------------------------------------------------------------
// Recent orders table
// -----------------------------------------------------------------------------

const TONE_DOT: Record<StatusTone, string> = {
  amber: 'bg-[#f59e0b]',
  blue: 'bg-[#3b82f6]',
  green: 'bg-[#10b981]',
  neutral: 'bg-[#9ca3af]',
  red: 'bg-[#ef4444]',
};

function StatusDot({ status }: { status: { label: string; tone: StatusTone } }) {
  return (
    <span className="inline-flex items-center gap-2 whitespace-nowrap text-[13px] text-[#52525b]">
      <span className={`size-1.5 shrink-0 rounded-full ${TONE_DOT[status.tone]}`} aria-hidden="true" />
      {status.label}
    </span>
  );
}

function initials(name: string): string {
  return (
    name
      .split(/[\s@.]+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part.charAt(0).toUpperCase())
      .join('') || '?'
  );
}

function RecentOrders({ data }: { data: DashboardOverview }) {
  const rows = data.recent_orders;
  return (
    <div className="animate-rise min-w-0 [animation-delay:420ms]">
      <Shell title="Recent orders" iconFirst icon={<ShoppingBag className="size-4" aria-hidden="true" />} actions={<SurfaceLink href="/admin/orders">All orders</SurfaceLink>}>
        {rows.length === 0 ? (
          <div className="flex flex-col items-center gap-1 px-4 py-12 text-center">
            <p className="text-[13px] font-medium text-[#1f2937]">No orders yet</p>
            <p className="text-xs text-[#6b7280]">Orders placed on the storefront appear here.</p>
          </div>
        ) : (
          <div className="w-full overflow-x-auto">
            <table className="w-full border-collapse text-left text-[13px] text-[#52525b] md:min-w-[760px]">
              <thead>
                <tr className="bg-[#f9fafb] text-[13px] text-[#4b5563]">
                  <th scope="col" className="rounded-tl-[10px] px-4 py-2.5 font-normal">Order</th>
                  <th scope="col" className="hidden px-4 py-2.5 font-normal md:table-cell">Customer</th>
                  <th scope="col" className="hidden px-3 py-2.5 font-normal sm:table-cell md:px-4">Status</th>
                  <th scope="col" className="hidden px-4 py-2.5 font-normal sm:table-cell">Payment</th>
                  <th scope="col" className="px-3 py-2.5 text-right font-normal md:px-4">Total</th>
                  <th scope="col" className="hidden rounded-tr-[10px] px-4 py-2.5 font-normal md:table-cell">Placed</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((o) => {
                  const who = o.customer_name || o.customer_email || 'Guest';
                  return (
                    <tr key={o.id} className="group/row border-t border-[#f3f4f6] transition-colors hover:bg-[#fafafa]">
                      <td className="px-3 py-2.5 md:px-4">
                        <Link href={orderHref(o.order_number)} className="whitespace-nowrap font-medium text-[#1f2937] hover:underline">
                          {o.order_number}
                        </Link>
                        <span className="block text-xs text-[#6b7280]">
                          {num(o.items_count)} item{num(o.items_count) === 1 ? '' : 's'}
                          {!!num(o.requires_attention) && <span className="font-medium text-[#b45309]"> · needs attention</span>}
                        </span>
                        <span className="mt-1 block sm:hidden">
                          <StatusDot status={orderStatus(o.order_status)} />
                        </span>
                      </td>
                      <td className="hidden max-w-[240px] px-4 py-2.5 md:table-cell">
                        <span className="flex items-center gap-2">
                          <span
                            className="flex size-6 shrink-0 items-center justify-center rounded-full bg-[#f3f4f6] text-[10px] font-semibold text-[#4b5563] shadow-[inset_0_0_0_0.8px_#e5e7eb] transition-transform duration-200 group-hover/row:scale-110"
                            aria-hidden="true"
                          >
                            {initials(who)}
                          </span>
                          <span className="min-w-0">
                            <span className="block truncate text-[#1f2937]">{who}</span>
                            {o.customer_name && o.customer_email && <span className="block truncate text-xs text-[#6b7280]">{o.customer_email}</span>}
                          </span>
                        </span>
                      </td>
                      <td className="hidden px-3 py-2.5 sm:table-cell md:px-4">
                        <StatusDot status={orderStatus(o.order_status)} />
                      </td>
                      <td className="hidden px-4 py-2.5 sm:table-cell">
                        <PaymentCell status={o.payment_status} method={o.payment_method} />
                      </td>
                      <td className="whitespace-nowrap px-3 py-2.5 text-right tabular-nums text-[#1f2937] md:px-4">{formatPrice(num(o.total_amount))}</td>
                      <td className="hidden whitespace-nowrap px-4 py-2.5 md:table-cell" title={formatDateTime(o.created_at)}>
                        {formatRelative(o.created_at)}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </Shell>
    </div>
  );
}

function PaymentCell({ status, method }: { status: string; method: string | null }) {
  return (
    <span className="flex flex-col gap-0.5">
      <StatusDot status={paymentStatus(status)} />
      {method && <span className="pl-3.5 text-xs text-[#6b7280]">{gatewayLabel(method)}</span>}
    </span>
  );
}

// -----------------------------------------------------------------------------
// Stock and top products
// -----------------------------------------------------------------------------

function LowStock({ data }: { data: DashboardOverview }) {
  const rows = data.critical_stock;
  return (
    <Shell title="Low stock" iconFirst icon={<Package className="size-4" aria-hidden="true" />} actions={<SurfaceLink href="/admin/inventory?filter=low_or_out">Inventory</SurfaceLink>}>
      {rows.length === 0 ? (
        <div className="flex flex-col items-center gap-1 px-4 py-10 text-center">
          <p className="text-[13px] font-medium text-[#1f2937]">Stock levels are healthy</p>
          <p className="text-xs text-[#6b7280]">Variants at or below their low-stock threshold appear here.</p>
        </div>
      ) : (
        <ul className="divide-y divide-[#f3f4f6]">
          {rows.map((v) => {
            const stock = num(v.stock_quantity);
            const threshold = Math.max(1, num(v.low_stock_threshold));
            return (
              <li key={v.id} className="flex items-center justify-between gap-3 px-3.5 py-2.5 text-[13px]">
                <div className="min-w-0">
                  <p className="truncate font-medium text-[#1f2937]">{v.product_name}</p>
                  <p className="truncate text-xs text-[#6b7280]">
                    {[optionsText(v.size, v.color_name), v.sku].filter(Boolean).join(' · ')}
                  </p>
                </div>
                <div className="flex w-[120px] shrink-0 flex-col items-end gap-1.5">
                  <StatusDot status={stock === 0 ? { label: 'Sold out', tone: 'red' } : { label: `${stock} left`, tone: 'amber' }} />
                  <span className="h-1.5 w-full overflow-hidden rounded-full bg-[#f3f4f6]" aria-hidden="true">
                    <span
                      className={`block h-full rounded-full ${stock === 0 ? 'bg-[#ef4444]' : 'bg-[#f59e0b]'}`}
                      style={{ width: `${Math.min(100, (stock / threshold) * 100)}%` }}
                    />
                  </span>
                  <span className="text-[11px] text-[#6b7280]">Alert at {num(v.low_stock_threshold)}</span>
                </div>
              </li>
            );
          })}
        </ul>
      )}
    </Shell>
  );
}

function TopProducts({ data }: { data: DashboardOverview }) {
  const rows = data.top_products;
  const maxUnits = Math.max(1, ...rows.map((r) => num(r.total_units_sold)));
  return (
    <Shell title={`Top products · last ${data.period.days} days`} iconFirst icon={<Trophy className="size-4" aria-hidden="true" />} actions={<SurfaceLink href="/admin/analytics">Analytics</SurfaceLink>}>
      {rows.length === 0 ? (
        <div className="flex flex-col items-center gap-1 px-4 py-10 text-center">
          <p className="text-[13px] font-medium text-[#1f2937]">No paid sales in this period</p>
          <p className="text-xs text-[#6b7280]">Products from orders with a verified payment are ranked by units sold.</p>
        </div>
      ) : (
        <>
        <p className="px-3.5 pt-3 text-xs text-[#6b7280]">{ITEM_SALES_NOTE}</p>
        <ol className="flex flex-col gap-3 px-3.5 py-3.5">
          {rows.map((p, i) => {
            const units = num(p.total_units_sold);
            return (
              <li key={p.id} className="text-[13px]">
                <div className="mb-1.5 flex items-center justify-between gap-3">
                  <p className="min-w-0 truncate">
                    <span className="mr-2 text-xs tabular-nums text-[#6b7280]">{i + 1}</span>
                    <span className="font-medium text-[#1f2937]">{p.name}</span>
                  </p>
                  <p className="shrink-0 text-right text-xs tabular-nums text-[#4b5563]">
                    {units} sold · <span className="font-medium text-[#1f2937]">{formatPrice(num(p.total_revenue))}</span>
                  </p>
                </div>
                <div className="h-2.5 w-full overflow-hidden rounded-[4px] bg-[#f6f6f6]" aria-hidden="true">
                  <div
                    className={`animate-draw h-full rounded-[4px] ${
                      i === 0 ? 'bg-[linear-gradient(180deg,#37475d_0%,#1f2937_64.7%)]' : 'bg-gradient-to-b from-[#e6e6e6] to-[rgba(230,230,230,0.6)] shadow-[inset_0_0_0_0.444px_#d8d8d8]'
                    }`}
                    style={{ width: `${(units / maxUnits) * 100}%`, animationDelay: `${200 + i * 60}ms` }}
                  />
                </div>
              </li>
            );
          })}
        </ol>
        </>
      )}
    </Shell>
  );
}
