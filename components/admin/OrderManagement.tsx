'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { AlertTriangle, ChevronRight, Download } from 'lucide-react';
import { type AdminOrderListItem, adminDownloadExport, adminGetOrders, adminUpdateOrderStatus, errorMessage } from '@/lib/admin-api';
import { ORDER_STATUS, formatDate, formatPrice, gatewayLabel, num, orderStatus, paymentStatus } from '@/lib/admin-format';
import {
  Button,
  DataState,
  FilterSelect,
  PageHeader,
  Pagination,
  Panel,
  SearchInput,
  StatusPill,
  Table,
  Toolbar,
  redirectIfSignedOut,
  useAdminData,
  useConfirm,
  useSearchBox,
  useToast,
  useUrlFilters,
} from '@/components/admin/ui';
import { ManualPaymentModal } from '@/components/admin/ManualPaymentModal';

const STATUS_OPTIONS = [
  { value: 'all', label: 'All orders' },
  { value: 'attention', label: 'Needs attention' },
  { value: 'payment_claimed', label: 'Customer says paid' },
  { value: 'awaiting_whatsapp', label: 'Awaiting WhatsApp payment' },
  ...Object.entries(ORDER_STATUS).map(([value, s]) => ({ value, label: s.label })),
];

/** The customer chose "Pay via WhatsApp" and the transfer has not been confirmed yet. */
function awaitingWhatsApp(o: AdminOrderListItem): boolean {
  return o.order_status === 'pending_payment' && o.payment_provider === 'whatsapp' && o.payment_record_status === 'pending';
}

/**
 * All orders, newest first. Filters live in the URL (/admin/orders?status=attention).
 * Status changes, shipping and payments are handled on the order detail page.
 */
export function OrderManagement() {
  const toast = useToast();
  const router = useRouter();
  const confirm = useConfirm();
  const [paying, setPaying] = useState<AdminOrderListItem | null>(null);
  const [cancelling, setCancelling] = useState<string | null>(null);

  // WhatsApp transfer that never arrived: cancel straight from the list.
  const cancelUnpaid = async (o: AdminOrderListItem) => {
    const ok = await confirm({
      title: `Cancel ${o.order_number}?`,
      tone: 'danger',
      confirmLabel: 'Cancel order',
      cancelLabel: 'Keep order',
      message: (
        <>
          {o.customer_marked_paid_at && (
            <p className="mb-2 font-semibold">The customer marked this transfer as paid. Check your bank first: cancel only if the money has not arrived.</p>
          )}
          <p>The reserved items go back on sale and the customer gets an email saying the order was cancelled and no payment was taken.</p>
        </>
      ),
    });
    if (!ok) return;
    setCancelling(o.order_number);
    try {
      await adminUpdateOrderStatus(o.order_number, 'cancelled', 'Cancelled by the store: the WhatsApp transfer was not received.');
      toast.success(`${o.order_number} cancelled.`);
      reload();
    } catch (err) {
      if (!redirectIfSignedOut(err)) toast.error(errorMessage(err));
    } finally {
      setCancelling(null);
    }
  };
  const [filters, setFilters] = useUrlFilters({ status: 'all', search: '', page: '1' });
  const [searchText, setSearchText] = useSearchBox(filters.search, setFilters, 350);
  const [exporting, setExporting] = useState(false);

  const page = Math.max(1, parseInt(filters.page, 10) || 1);
  const { data, loading, error, reload } = useAdminData('OrderManagement',
    () => adminGetOrders({ status: filters.status, search: filters.search, page, per_page: 20 }),
    [filters.status, filters.search, page],
    // New orders appear by themselves, like the bell's counts.
    { refreshEvery: 60_000 }
  );

  const exportCsv = async () => {
    setExporting(true);
    try {
      // Same filters as the list (all dates).
      const q = new URLSearchParams();
      if (filters.status !== 'all') q.set('status', filters.status);
      if (filters.search) q.set('search', filters.search);
      const query = q.toString();
      await adminDownloadExport(`/admin/orders/export${query ? `?${query}` : ''}`, `orders-${new Date().toISOString().slice(0, 10)}.csv`);
    } catch (err) {
      if (!redirectIfSignedOut(err)) toast.error(errorMessage(err, 'The export could not be generated.'));
    } finally {
      setExporting(false);
    }
  };

  const items = data?.items ?? [];

  return (
    <div>
      <PageHeader
        title="Orders"
        description="Every order placed in the store. Open an order to ship it, record a payment or change its status."
        actions={
          <Button onClick={exportCsv} loading={exporting}>
            <Download className="size-3.5" aria-hidden="true" />
            Export CSV
          </Button>
        }
      />

      <Toolbar>
        <SearchInput
          label="Search orders"
          value={searchText}
          onChange={setSearchText}
          placeholder="Order number, email, name, phone or tracking no."
        />
        <FilterSelect label="Order status" value={filters.status} onChange={(status) => setFilters({ status })} options={STATUS_OPTIONS} />
      </Toolbar>

      <Panel>
        <DataState
          loading={loading}
          error={error}
          onRetry={reload}
          hasData={!!data}
          empty={items.length === 0}
          emptyTitle={
            filters.status === 'attention'
              ? 'No orders need attention'
              : filters.status === 'payment_claimed'
                ? 'No payments waiting to be checked'
                : 'No orders found'
          }
          emptyDescription={
            filters.search || filters.status !== 'all' ? 'Try a different search or status.' : 'Orders appear here as soon as customers check out.'
          }
        >
          <Table minWidth={980}>
            <thead>
              <tr>
                <th scope="col">Order</th>
                <th scope="col">Customer</th>
                <th scope="col">Placed</th>
                <th scope="col" className="text-right">
                  Items
                </th>
                <th scope="col" className="text-right">
                  Total
                </th>
                <th scope="col">Order status</th>
                <th scope="col">Payment</th>
                <th scope="col">Method</th>
                <th scope="col">
                  <span className="sr-only">Actions</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {items.map((o) => {
                const os = orderStatus(o.order_status);
                const ps = paymentStatus(o.payment_status);
                const name = o.shipping_address_snapshot?.full_name?.trim();
                const href = `/admin/orders/${encodeURIComponent(o.order_number)}`;
                return (
                  <tr
                    key={o.id}
                    className="cursor-pointer hover:bg-[#fafafa]"
                    onClick={(e) => {
                      // The whole row opens the order; links and buttons inside keep their own action.
                      if ((e.target as HTMLElement).closest('a,button')) return;
                      router.push(href);
                    }}
                  >
                    <td>
                      <Link href={href} className="whitespace-nowrap font-semibold text-[#2563eb] underline-offset-2 hover:underline">
                        {o.order_number}
                      </Link>
                      {o.requires_attention && (
                        <span
                          className="mt-1 flex max-w-[220px] items-center gap-1 text-xs font-medium text-red-700"
                          title={o.attention_reason ? `Needs attention: ${o.attention_reason}` : undefined}
                        >
                          <AlertTriangle className="size-3 shrink-0" aria-hidden="true" />
                          <span className="truncate">Needs attention{o.attention_reason ? `: ${o.attention_reason}` : ''}</span>
                        </span>
                      )}
                    </td>
                    <td>
                      <span className="block text-[#111827]">{name || '—'}</span>
                      <span className="block max-w-[180px] truncate text-xs text-[#6b7280]" title={o.customer_email || o.guest_email || undefined}>
                        {o.customer_email || o.guest_email || ''}
                      </span>
                    </td>
                    <td className="whitespace-nowrap text-[#4b5563]">{formatDate(o.created_at)}</td>
                    <td className="text-right tabular-nums">{num(o.total_quantity) || num(o.items_count)}</td>
                    <td className="whitespace-nowrap text-right font-medium tabular-nums">{formatPrice(num(o.grand_total))}</td>
                    <td>
                      <StatusPill label={os.label} tone={os.tone} />
                    </td>
                    <td>
                      <StatusPill label={ps.label} tone={ps.tone} />
                      {o.order_status === 'pending_payment' && o.customer_marked_paid_at && (
                        <span className="mt-1 block text-xs font-medium text-amber-800" title={`Marked as paid ${formatDate(o.customer_marked_paid_at)}`}>
                          Customer says paid
                        </span>
                      )}
                    </td>
                    <td className="text-[#4b5563]">{o.payment_provider ? gatewayLabel(o.payment_provider) : '—'}</td>
                    <td className="whitespace-nowrap text-right">
                      {awaitingWhatsApp(o) ? (
                        <span className="inline-flex gap-1.5">
                          <Button size="sm" variant="primary" onClick={() => setPaying(o)} title="The transfer arrived: record the payment">
                            Confirm<span className="sr-only"> payment for order {o.order_number}</span>
                          </Button>
                          <Button
                            size="sm"
                            variant="secondary"
                            onClick={() => cancelUnpaid(o)}
                            disabled={cancelling === o.order_number}
                            title="No transfer arrived: cancel the order"
                          >
                            {cancelling === o.order_number ? 'Cancelling…' : 'Cancel'}
                            <span className="sr-only"> order {o.order_number}</span>
                          </Button>
                        </span>
                      ) : (
                        <Link
                          href={href}
                          className="inline-flex items-center gap-0.5 rounded-md px-2 py-1 text-xs font-medium text-[#4b5563] hover:bg-[#f3f4f6] hover:text-[#111827]"
                        >
                          Open
                          <ChevronRight className="size-3.5" aria-hidden="true" />
                          <span className="sr-only">order {o.order_number}</span>
                        </Link>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </Table>
          <Pagination meta={data?.pagination} onPage={(p) => setFilters({ page: String(p) })} />
        </DataState>
      </Panel>

      <ManualPaymentModal
        open={paying !== null}
        order={paying}
        onClose={() => setPaying(null)}
        onDone={() => {
          setPaying(null);
          reload();
        }}
      />
    </div>
  );
}
