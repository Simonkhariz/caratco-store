'use client';

import { useState } from 'react';
import Link from 'next/link';
import {
  adminGetCustomer,
  adminGetCustomers,
  adminUpdateCustomerStatus,
  errorMessage,
  type CustomerDetail,
  type CustomerRow,
} from '@/lib/admin-api';
import { formatDate, formatPrice, humanize, num, orderStatus, paymentStatus } from '@/lib/admin-format';
import type { StatusTone } from '@/lib/order-status';
import {
  Button,
  DataState,
  DetailList,
  FilterSelect,
  Modal,
  PageHeader,
  Pagination,
  Panel,
  SearchInput,
  StatusPill,
  Table,
  Toolbar,
  redirectIfSignedOut,
  useAdminData,
  useClientPagination,
  useConfirm,
  useSearchBox,
  useToast,
  useUrlFilters,
} from '@/components/admin/ui';

const CUSTOMER_STATUS: Record<string, { label: string; tone: StatusTone }> = {
  active: { label: 'Active', tone: 'green' },
  unverified: { label: 'Email not verified', tone: 'amber' },
  suspended: { label: 'Suspended', tone: 'red' },
};

function customerStatus(status: string) {
  return CUSTOMER_STATUS[status] ?? { label: humanize(status), tone: 'neutral' as StatusTone };
}


export function AdminCustomers() {
  const [filters, setFilters] = useUrlFilters({ status: 'all', search: '', page: '1' });
  const [searchText, setSearchText] = useSearchBox(filters.search, setFilters);
  const [selectedId, setSelectedId] = useState<number | null>(null);

  const page = Math.max(1, parseInt(filters.page, 10) || 1);
  const { data, loading, error, reload } = useAdminData('AdminCustomers',
    () => adminGetCustomers({ status: filters.status, search: filters.search, page, per_page: 20 }),
    [filters.status, filters.search, page]
  );

  const items = data?.items ?? [];

  return (
    <div>
      <PageHeader
        title="Customers"
        description="People with a store account. Guest checkouts appear on their orders, not here."
      />

      <Toolbar>
        <SearchInput label="Search customers" placeholder="Name, email or phone" value={searchText} onChange={setSearchText} />
        <FilterSelect
          label="Account status"
          value={filters.status}
          onChange={(status) => setFilters({ status })}
          options={[
            { value: 'all', label: 'All statuses' },
            { value: 'active', label: 'Active' },
            { value: 'unverified', label: 'Email not verified' },
            { value: 'suspended', label: 'Suspended' },
          ]}
        />
      </Toolbar>

      <Panel>
        <DataState
          loading={loading}
          error={error}
          onRetry={reload}
          hasData={!!data}
          empty={items.length === 0}
          emptyTitle={filters.search || filters.status !== 'all' ? 'No customers match these filters' : 'No customers yet'}
          emptyDescription={filters.search || filters.status !== 'all' ? 'Try a different search or status.' : 'Customers appear here once they create an account.'}
        >
          <Table minWidth={820}>
            <thead>
              <tr>
                <th scope="col">Customer</th>
                <th scope="col">Phone</th>
                <th scope="col">Joined</th>
                <th scope="col" className="text-right">Orders</th>
                <th scope="col" className="text-right">Paid</th>
                <th scope="col">Status</th>
              </tr>
            </thead>
            <tbody>
              {items.map((c: CustomerRow) => {
                const s = customerStatus(c.status);
                return (
                  <tr key={c.id} className="hover:bg-[#fafafa]">
                    <td>
                      <button
                        type="button"
                        onClick={() => setSelectedId(c.id)}
                        className="rounded text-left outline-none focus-visible:ring-[3px] focus-visible:ring-[#9ca3af]/50"
                        aria-label={`View ${c.full_name || c.email}`}
                      >
                        <span className="block font-semibold text-[#111827] hover:underline">{c.full_name || '—'}</span>
                        <span className="block text-xs text-[#6b7280]">{c.email}</span>
                      </button>
                    </td>
                    <td className="text-[#374151]">{c.phone || '—'}</td>
                    <td className="whitespace-nowrap text-[#374151]">{formatDate(c.created_at)}</td>
                    <td className="text-right tabular-nums">{num(c.total_orders)}</td>
                    <td className="text-right tabular-nums">{formatPrice(num(c.lifetime_spend))}</td>
                    <td>
                      <StatusPill label={s.label} tone={s.tone} />
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </Table>
        </DataState>
        <Pagination meta={data?.pagination} onPage={(p) => setFilters({ page: String(p) })} />
      </Panel>

      <CustomerDetailModal customerId={selectedId} onClose={() => setSelectedId(null)} onChanged={reload} />
    </div>
  );
}

function CustomerDetailModal({ customerId, onClose, onChanged }: { customerId: number | null; onClose: () => void; onChanged: () => void }) {
  const toast = useToast();
  const confirm = useConfirm();
  const [saving, setSaving] = useState(false);

  const { data, loading, error, reload } = useAdminData<CustomerDetail | null>('AdminCustomers#detail',
    async () => (customerId !== null ? adminGetCustomer(customerId) : null),
    [customerId]
  );
  // Only the customer that is open (never the previous one while the next loads).
  const detail = data && data.customer.id === customerId ? data : null;

  const customer = detail?.customer;
  const isSuspended = customer?.status === 'suspended';

  const changeStatus = async () => {
    if (!customer) return;
    const next = isSuspended ? 'active' : 'suspended';
    const ok = await confirm(
      next === 'suspended'
        ? {
            title: `Suspend ${customer.full_name || customer.email}?`,
            message: 'They will be signed out on every device and cannot sign in until you reactivate the account. Their orders are not affected.',
            confirmLabel: 'Suspend customer',
            tone: 'danger',
          }
        : {
            title: `Reactivate ${customer.full_name || customer.email}?`,
            message: 'They will be able to sign in again.',
            confirmLabel: 'Reactivate',
          }
    );
    if (!ok) return;
    setSaving(true);
    try {
      await adminUpdateCustomerStatus(customer.id, next);
      toast.success(next === 'suspended' ? 'Customer suspended and signed out.' : 'Customer reactivated.');
      await reload();
      onChanged();
    } catch (err) {
      if (redirectIfSignedOut(err)) return;
      toast.error(errorMessage(err, 'Could not change the account status.'));
    } finally {
      setSaving(false);
    }
  };

  const addresses = detail?.addresses ?? [];

  return (
    <Modal
      open={customerId !== null}
      onClose={onClose}
      busy={saving}
      size="lg"
      title={customer ? customer.full_name || customer.email : 'Customer'}
      description={customer ? customer.email : undefined}
      footer={
        customer ? (
          <>
            <Button onClick={onClose}>Close</Button>
            <Button variant={isSuspended ? 'primary' : 'danger'} loading={saving} onClick={changeStatus}>
              {isSuspended ? 'Reactivate account' : 'Suspend account'}
            </Button>
          </>
        ) : undefined
      }
    >
      <DataState loading={loading} error={error} onRetry={reload} empty={false}>
        {customer && detail && (
          <div className="space-y-5">
            <DetailList
              items={[
                { label: 'Status', value: <StatusPill {...customerStatus(customer.status)} /> },
                { label: 'Phone', value: customer.phone || '—' },
                { label: 'Joined', value: formatDate(customer.created_at) },
                { label: 'Email verified', value: customer.email_verified_at ? formatDate(customer.email_verified_at) : 'Not yet' },
                { label: 'Orders', value: num(detail.summary.total_orders) },
                { label: 'Paid to date', value: formatPrice(num(detail.summary.total_spend)) },
              ]}
            />

            <section>
              <h3 className="mb-2 text-[13px] font-semibold text-[#111827]">Saved addresses</h3>
              {addresses.length === 0 ? (
                <p className="text-[13px] text-[#6b7280]">No saved addresses.</p>
              ) : (
                <ul className="grid gap-2 sm:grid-cols-2">
                  {addresses.map((a) => (
                    <li key={a.id} className="rounded-lg border border-[#e5e7eb] p-3 text-[13px] text-[#374151]">
                      <p className="font-semibold text-[#111827]">
                        {a.label || 'Address'}
                        {a.is_default ? <span className="ml-1.5 text-xs font-normal text-[#6b7280]">(default)</span> : null}
                      </p>
                      <p>{a.recipient_name}</p>
                      <p>{a.street_address}</p>
                      <p>{[a.city, a.state].filter(Boolean).join(', ')}</p>
                      {a.phone && <p className="text-[#6b7280]">{a.phone}</p>}
                    </li>
                  ))}
                </ul>
              )}
            </section>

            <section>
              <h3 className="mb-2 text-[13px] font-semibold text-[#111827]">Recent orders</h3>
              <CustomerOrders orders={detail.orders} email={detail.customer.email} totalOrders={num(detail.summary.total_orders)} />
            </section>
          </div>
        )}
      </DataState>
    </Modal>
  );
}

/** A customer's orders, 10 per page, with a link to all of them in Orders. */
function CustomerOrders({ orders, email, totalOrders }: { orders: CustomerDetail['orders']; email: string; totalOrders: number }) {
  const { pageItems, meta, setPage } = useClientPagination(orders, 10);

  if (orders.length === 0) return <p className="text-[13px] text-[#6b7280]">No orders yet.</p>;

  return (
    <>
      <Table minWidth={560}>
        <thead>
          <tr>
            <th scope="col">Order</th>
            <th scope="col">Placed</th>
            <th scope="col">Status</th>
            <th scope="col">Payment</th>
            <th scope="col" className="text-right">Total</th>
          </tr>
        </thead>
        <tbody>
          {pageItems.map((o) => (
            <tr key={o.id}>
              <td>
                <Link href={`/admin/orders/${encodeURIComponent(o.order_number)}`} className="font-mono text-xs font-semibold text-[#2563eb] hover:underline">
                  {o.order_number}
                </Link>
              </td>
              <td className="whitespace-nowrap">{formatDate(o.created_at)}</td>
              <td>
                <StatusPill {...orderStatus(o.order_status)} />
              </td>
              <td>
                <StatusPill {...paymentStatus(o.payment_status)} />
              </td>
              <td className="text-right tabular-nums">{formatPrice(num(o.grand_total))}</td>
            </tr>
          ))}
        </tbody>
      </Table>
      <Pagination meta={meta} onPage={setPage} />
      {totalOrders > orders.length && (
        <p className="mt-2 text-xs text-[#6b7280]">
          Showing the latest {orders.length} of {totalOrders}.{' '}
          <Link href={`/admin/orders?search=${encodeURIComponent(email)}`} className="font-semibold text-[#111827] underline-offset-2 hover:underline">
            See all in Orders
          </Link>
        </p>
      )}
    </>
  );
}
