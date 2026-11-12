'use client';

import { useState } from 'react';
import Link from 'next/link';
import { MapPin } from 'lucide-react';
import { AdminOrderListItem, adminConfirmDelivery, adminGetOrders, errorMessage } from '@/lib/admin-api';
import { formatDate, formatDateTime, formatPrice, num, orderStatus } from '@/lib/admin-format';
import {
  Button,
  DataState,
  FilterSelect,
  PageHeader,
  Pagination,
  Panel,
  StatusPill,
  Table,
  Toolbar,
  redirectIfSignedOut,
  useAdminData,
  useConfirm,
  useToast,
  useUrlFilters,
} from '@/components/admin/ui';
import { ShipOrderModal } from '@/components/admin/ShipOrderModal';

const VIEWS = [
  { value: 'to_ship', label: 'To ship' },
  { value: 'in_transit', label: 'In transit' },
] as const;

/**
 * Fulfilment queue: paid orders waiting to be shipped, and shipped orders waiting for delivery.
 */
export function AdminShipping() {
  const toast = useToast();
  const confirm = useConfirm();
  const [filters, setFilters] = useUrlFilters({ view: 'to_ship', status: 'to_ship', page: '1' });
  const [shipOrder, setShipOrder] = useState<string | null>(null);
  const [busyOrder, setBusyOrder] = useState<string | null>(null);

  const view = filters.view === 'in_transit' ? 'in_transit' : 'to_ship';
  // "To ship" starts with paid and processing orders together, so an order marked Processing
  // while it is being packed never drops out of the queue.
  const status = view === 'in_transit' ? 'shipped' : filters.status === 'processing' || filters.status === 'paid' ? filters.status : 'to_ship';
  const page = Math.max(1, parseInt(filters.page, 10) || 1);

  const { data, loading, error, reload } = useAdminData('AdminShipping',
    () => adminGetOrders({ status, page, per_page: 20 }),
    [status, page],
    { refreshEvery: 60_000 }
  );

  const markDelivered = async (o: AdminOrderListItem) => {
    const ok = await confirm({
      title: `Mark ${o.order_number} as delivered?`,
      message: 'Confirm that the customer has received the parcel.',
      confirmLabel: 'Mark delivered',
    });
    if (!ok) return;
    setBusyOrder(o.order_number);
    try {
      await adminConfirmDelivery(o.order_number);
      toast.success(`${o.order_number} marked as delivered.`);
      reload();
    } catch (err) {
      if (!redirectIfSignedOut(err)) toast.error(errorMessage(err));
    } finally {
      setBusyOrder(null);
    }
  };

  const items = data?.items ?? [];

  return (
    <div>
      <PageHeader
        title="Shipping queue"
        description="Paid orders to pack and hand to a courier, and parcels on their way to customers."
        actions={
          <Link
            href="/admin/shipping/zones"
            className="inline-flex h-9 items-center gap-1.5 rounded-lg border border-[#d1d5db] bg-white px-3.5 text-[13px] font-semibold text-[#1f2937] hover:bg-[#f9fafb]"
          >
            <MapPin className="size-3.5" aria-hidden="true" />
            Delivery zones
          </Link>
        }
      />

      <div role="tablist" aria-label="Queue" className="mb-3 inline-flex rounded-lg border border-[#e5e7eb] bg-[#f9fafb] p-0.5">
        {VIEWS.map((v) => (
          <button
            key={v.value}
            type="button"
            role="tab"
            aria-selected={view === v.value}
            onClick={() => setFilters({ view: v.value, status: 'to_ship' })}
            className={`h-8 rounded-md px-3 text-[13px] font-semibold outline-none transition-colors focus-visible:ring-[3px] focus-visible:ring-[#9ca3af]/40 ${
              view === v.value ? 'bg-white text-[#111827] shadow-sm' : 'text-[#6b7280] hover:text-[#111827]'
            }`}
          >
            {v.label}
          </button>
        ))}
      </div>

      {view === 'to_ship' && (
        <Toolbar>
          <FilterSelect
            label="Order status"
            value={status}
            onChange={(s) => setFilters({ status: s })}
            options={[
              { value: 'to_ship', label: 'All waiting to ship' },
              { value: 'paid', label: 'Paid – not started' },
              { value: 'processing', label: 'Processing – being packed' },
            ]}
          />
        </Toolbar>
      )}

      <Panel>
        <DataState
          loading={loading}
          error={error}
          onRetry={reload}
          hasData={!!data}
          empty={items.length === 0}
          emptyTitle={view === 'in_transit' ? 'No parcels in transit' : 'Nothing to ship'}
          emptyDescription={view === 'in_transit' ? 'Shipped orders appear here until they are delivered.' : 'Paid and processing orders appear here until they are shipped.'}
        >
          <Table minWidth={860}>
            <thead>
              <tr>
                <th scope="col">Order</th>
                <th scope="col">Deliver to</th>
                <th scope="col" className="text-right">
                  Items
                </th>
                <th scope="col" className="text-right">
                  Total
                </th>
                <th scope="col">{view === 'in_transit' ? 'Courier' : 'Status'}</th>
                <th scope="col">{view === 'in_transit' ? 'Shipped' : 'Placed'}</th>
                <th scope="col">
                  <span className="sr-only">Action</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {items.map((o) => {
                const addr = o.shipping_address_snapshot ?? {};
                const s = orderStatus(o.order_status);
                // The action follows the row's own status, never the tab: while a tab is loading,
                // the rows on screen can still be the other tab's.
                const inTransit = o.order_status === 'shipped';
                return (
                  <tr key={o.id} className="hover:bg-[#fafafa]">
                    <td>
                      <Link href={`/admin/orders/${encodeURIComponent(o.order_number)}`} className="font-semibold text-[#111827] underline-offset-2 hover:underline">
                        {o.order_number}
                      </Link>
                    </td>
                    <td>
                      <span className="block text-[#111827]">{addr.full_name || '—'}</span>
                      <span className="block text-xs text-[#6b7280]">{[addr.city, addr.state].filter(Boolean).join(', ') || '—'}</span>
                    </td>
                    <td className="text-right tabular-nums">{num(o.total_quantity) || num(o.items_count)}</td>
                    <td className="whitespace-nowrap text-right tabular-nums">{formatPrice(num(o.grand_total))}</td>
                    <td>
                      {inTransit ? (
                        <>
                          <span className="block">{o.carrier || '—'}</span>
                          {o.tracking_number && <span className="block text-xs text-[#6b7280]">{o.tracking_number}</span>}
                        </>
                      ) : (
                        <StatusPill label={s.label} tone={s.tone} />
                      )}
                    </td>
                    <td className="whitespace-nowrap text-[#4b5563]">
                      {inTransit ? formatDateTime(o.shipped_at) : formatDate(o.created_at)}
                    </td>
                    <td className="text-right">
                      {inTransit ? (
                        <Button size="sm" onClick={() => markDelivered(o)} loading={busyOrder === o.order_number} disabled={loading}>
                          Mark delivered
                        </Button>
                      ) : (
                        <Button size="sm" variant="primary" onClick={() => setShipOrder(o.order_number)} disabled={loading}>
                          Ship
                        </Button>
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

      <ShipOrderModal orderNumber={shipOrder} onClose={() => setShipOrder(null)} onShipped={reload} />
    </div>
  );
}
