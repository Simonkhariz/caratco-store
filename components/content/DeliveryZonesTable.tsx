'use client';

import { formatPrice } from '@/lib/currency';
import { useStoreInfo } from '@/lib/use-store-info';
import { SHOP } from '@/lib/shop';

/** Live delivery zones and fees, exactly as checkout uses them. */
export function DeliveryZonesTable() {
  const { zones, loaded, error, reload } = useStoreInfo();

  if (!loaded) {
    return (
      <p role="status" className="mt-6 text-sm text-neutral-600">
        Loading delivery fees…
      </p>
    );
  }

  if (error) {
    return (
      <div role="alert" className="mt-6 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
        <p>We could not load the delivery fees right now. The exact fee is always shown at checkout.</p>
        <button type="button" onClick={reload} className="mt-2 font-semibold underline">
          Try again
        </button>
      </div>
    );
  }

  const active = zones.filter((z) => z.is_active !== false).sort((a, b) => Number(a.fee) - Number(b.fee));

  if (active.length === 0) {
    return <p className="mt-6 text-sm text-neutral-700">Delivery zones have not been set up yet. Please contact us before ordering.</p>;
  }

  const statesLabel = (states: string[]) => {
    const named = states.filter((s) => s !== '*');
    const rest = states.includes('*') ? 'All other states' : '';
    return [named.join(', '), rest].filter(Boolean).join(' · ') || '—';
  };

  const extraFee = (zone: (typeof active)[number]) =>
    Number(zone.extra_item_fee) > 0
      ? `${Number(zone.items_included) > 1 ? `covers ${zone.items_included} items, then ` : 'for 1 item, then '}+${formatPrice(Number(zone.extra_item_fee))} each extra item`
      : null;

  // Only a shop that charges more for large items says so, and only for a zone that has the fee.
  const heavyFee = (zone: (typeof active)[number]) =>
    SHOP.features.heavyDelivery && Number(zone.heavy_item_fee) > 0 ? `Large items: +${formatPrice(Number(zone.heavy_item_fee))} each` : null;

  return (
    <>
    {/* Phones: one card per zone, so the fee is always on screen (the table needs 520px). */}
    <ul className="mt-6 space-y-3 sm:hidden">
      {active.map((zone) => (
        <li key={zone.id} className="rounded-2xl border border-neutral-200 p-4 text-sm">
          <div className="flex items-start justify-between gap-3">
            <p className="font-semibold text-black">{zone.name}</p>
            <p className="shrink-0 whitespace-nowrap font-bold text-black">{formatPrice(Number(zone.fee))}</p>
          </div>
          {extraFee(zone) && <p className="mt-0.5 text-right text-xs text-neutral-600">{extraFee(zone)}</p>}
          {heavyFee(zone) && <p className="mt-0.5 text-right text-xs text-neutral-600">{heavyFee(zone)}</p>}
          <p className="mt-2 text-neutral-700">{statesLabel(zone.states_covered ?? [])}</p>
          {zone.estimated_days && <p className="mt-1 text-xs text-neutral-600">Estimated delivery: {zone.estimated_days}</p>}
        </li>
      ))}
    </ul>
    <div className="mt-6 hidden overflow-x-auto rounded-2xl border border-neutral-200 sm:block">
      <table className="w-full min-w-[520px] text-left text-sm">
        <caption className="sr-only">Delivery fees by zone</caption>
        <thead className="bg-neutral-50 text-xs uppercase tracking-wider text-neutral-700">
          <tr>
            <th scope="col" className="px-4 py-3">
              Zone
            </th>
            <th scope="col" className="px-4 py-3">
              States
            </th>
            <th scope="col" className="px-4 py-3 text-right">
              Fee
            </th>
            <th scope="col" className="px-4 py-3">
              Estimated delivery
            </th>
          </tr>
        </thead>
        <tbody className="divide-y divide-neutral-100">
          {active.map((zone) => (
            <tr key={zone.id}>
              <th scope="row" className="px-4 py-3 font-semibold text-black">
                {zone.name}
              </th>
              <td className="px-4 py-3 text-neutral-700">{statesLabel(zone.states_covered ?? [])}</td>
              <td className="px-4 py-3 text-right font-semibold text-black">
                <span className="whitespace-nowrap">{formatPrice(Number(zone.fee))}</span>
                {/* The fee can grow with the number of items (set per zone by the store). */}
                {Number(zone.extra_item_fee) > 0 && (
                  <span className="block text-xs font-normal text-neutral-600">
                    {Number(zone.items_included) > 1 ? `covers ${zone.items_included} items, then ` : 'for 1 item, then '}+
                    {formatPrice(Number(zone.extra_item_fee))} each extra item
                  </span>
                )}
                {heavyFee(zone) && <span className="block text-xs font-normal text-neutral-600">{heavyFee(zone)}</span>}
              </td>
              <td className="px-4 py-3 text-neutral-700">{zone.estimated_days || '—'}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
    </>
  );
}
