'use client';

import Link from 'next/link';
import { Copy } from 'lucide-react';
import { adminGetPayments, type PaymentRow } from '@/lib/admin-api';
import { formatDateTime, formatPrice, gatewayLabel, num, paymentStatus } from '@/lib/admin-format';
import {
  DataState,
  FilterSelect,
  Notice,
  PageHeader,
  Pagination,
  Panel,
  SearchInput,
  StatusPill,
  Table,
  Toolbar,
  useAdminData,
  useSearchBox,
  useToast,
  useUrlFilters,
} from '@/components/admin/ui';

/** Difference between what was charged and what the gateway reports as received. */
function receivedNote(p: PaymentRow): { label: string; tone: 'under' | 'over' } | null {
  if (p.amount_received === null || p.amount_received === undefined || p.amount_received === '') return null;
  const diff = num(p.amount_received) - num(p.amount);
  if (Math.abs(diff) < 0.01) return null;
  return diff < 0
    ? { label: `Underpaid by ${formatPrice(-diff)}`, tone: 'under' }
    : { label: `Overpaid by ${formatPrice(diff)}`, tone: 'over' };
}

export function AdminPayments() {
  const toast = useToast();
  const [filters, setFilters] = useUrlFilters({ gateway: 'all', status: 'all', search: '', page: '1' });
  const [searchText, setSearchText] = useSearchBox(filters.search, setFilters);

  const page = Math.max(1, parseInt(filters.page, 10) || 1);
  const { data, loading, error, reload } = useAdminData('AdminPayments',
    () => adminGetPayments({ gateway: filters.gateway, status: filters.status, search: filters.search, page, per_page: 25 }),
    [filters.gateway, filters.status, filters.search, page]
  );
  const items = data?.items ?? [];
  const filtered = filters.search !== '' || filters.gateway !== 'all' || filters.status !== 'all';

  const copy = async (text: string) => {
    try {
      await navigator.clipboard.writeText(text);
      toast.success('Reference copied.');
    } catch {
      toast.error('Could not copy. Select the reference and copy it manually.');
    }
  };

  return (
    <div>
      <PageHeader title="Payments" description="Every payment attempt from Kora, Monnify and manual transfers, newest first." />

      <div className="mb-3">
        <Notice>
          To confirm a WhatsApp or bank-transfer payment, open{' '}
          <Link href="/admin/orders?status=awaiting_whatsapp" className="font-semibold underline-offset-2 hover:underline">
            Orders → Awaiting WhatsApp payment
          </Link>{' '}
          and press Confirm payment. &ldquo;Not used&rdquo; rows are checkouts the customer opened but paid another way (or never paid).
        </Notice>
      </div>

      <Toolbar>
        <SearchInput label="Search payments" placeholder="Reference, order number or email" value={searchText} onChange={setSearchText} />
        <FilterSelect
          label="Gateway"
          value={filters.gateway}
          onChange={(gateway) => setFilters({ gateway })}
          options={[
            { value: 'all', label: 'All gateways' },
            { value: 'kora', label: 'Kora' },
            { value: 'monnify', label: 'Monnify' },
            { value: 'whatsapp', label: 'WhatsApp / transfer' },
            { value: 'manual', label: 'Manual' },
          ]}
        />
        <FilterSelect
          label="Payment status"
          value={filters.status}
          onChange={(status) => setFilters({ status })}
          options={[
            { value: 'all', label: 'All statuses' },
            { value: 'pending', label: 'Pending' },
            { value: 'successful', label: 'Paid' },
            { value: 'failed', label: 'Failed' },
            { value: 'cancelled', label: 'Not used' },
            { value: 'partially_refunded', label: 'Partly refunded' },
            { value: 'refunded', label: 'Refunded' },
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
          emptyTitle={filtered ? 'No payments match these filters' : 'No payments yet'}
          emptyDescription={filtered ? 'Try a different search, gateway or status.' : 'Payment attempts appear here when customers check out.'}
        >
          <Table minWidth={980}>
            <thead>
              <tr>
                <th scope="col">Reference</th>
                <th scope="col">Order</th>
                <th scope="col">Customer</th>
                <th scope="col">Gateway</th>
                <th scope="col" className="text-right">Amount</th>
                <th scope="col">Status</th>
                <th scope="col">Time</th>
              </tr>
            </thead>
            <tbody>
              {items.map((p) => {
                const note = receivedNote(p);
                return (
                  <tr key={p.id}>
                    <td>
                      <div className="flex items-center gap-1">
                        <span className="max-w-[180px] truncate font-mono text-xs text-[#111827]" title={p.payment_reference}>
                          {p.payment_reference}
                        </span>
                        <button
                          type="button"
                          onClick={() => copy(p.payment_reference)}
                          aria-label={`Copy reference ${p.payment_reference}`}
                          className="shrink-0 rounded p-1 text-[#6b7280] outline-none hover:bg-black/[0.05] hover:text-[#111827] focus-visible:ring-[3px] focus-visible:ring-[#9ca3af]/50"
                        >
                          <Copy className="size-3.5" aria-hidden="true" />
                        </button>
                      </div>
                    </td>
                    <td>
                      {p.order_number ? (
                        <div className="flex flex-col gap-1">
                          <Link
                            href={`/admin/orders/${encodeURIComponent(p.order_number)}`}
                            className="whitespace-nowrap font-mono text-xs font-semibold text-[#111827] hover:underline"
                          >
                            {p.order_number}
                          </Link>
                          {!!p.requires_attention && (
                            <span className="w-fit">
                              <StatusPill label="Needs attention" tone="red" />
                            </span>
                          )}
                        </div>
                      ) : (
                        <span className="text-[#6b7280]">—</span>
                      )}
                    </td>
                    <td>
                      <span className="block text-[#111827]">{p.customer_name || '—'}</span>
                      {p.customer_email && <span className="block text-xs text-[#6b7280]">{p.customer_email}</span>}
                    </td>
                    <td className="whitespace-nowrap">{gatewayLabel(p.gateway)}</td>
                    <td className="text-right">
                      <span className="block tabular-nums text-[#111827]">{formatPrice(num(p.amount))}</span>
                      {note && (
                        <span className={`block whitespace-nowrap text-xs font-semibold ${note.tone === 'under' ? 'text-red-700' : 'text-amber-700'}`}>
                          Received {formatPrice(num(p.amount_received))} · {note.label}
                        </span>
                      )}
                    </td>
                    <td>
                      {p.status === 'cancelled' ? <StatusPill label="Not used" tone="neutral" /> : <StatusPill {...paymentStatus(p.status)} />}
                    </td>
                    <td className="whitespace-nowrap text-xs text-[#374151]">
                      {p.verified_at ? (
                        <>
                          <span className="block">Verified {formatDateTime(p.verified_at)}</span>
                          <span className="block text-[#6b7280]">Started {formatDateTime(p.created_at)}</span>
                        </>
                      ) : (
                        <span className="block">Started {formatDateTime(p.created_at)}</span>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </Table>
        </DataState>
        <Pagination meta={data?.pagination} onPage={(p) => setFilters({ page: String(p) })} />
      </Panel>
    </div>
  );
}
