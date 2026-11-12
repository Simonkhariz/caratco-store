import { SHOP } from '@/lib/shop';
import type { Metadata } from 'next';
import { ContentPage } from '@/components/content/ContentPage';
import { DeliveryZonesTable } from '@/components/content/DeliveryZonesTable';
import { SHIPPING } from '@/lib/site-content';

export const metadata: Metadata = {
  title: `Shipping & delivery | ${SHOP.name}`,
  description: 'Delivery fees by state and estimated delivery times, taken live from our checkout.',
};

export default function ShippingPage() {
  return (
    <ContentPage title="Shipping & delivery" intro={SHIPPING.intro} sections={SHIPPING.sections}>
      <section className="mt-10">
        <h2 className="text-lg font-bold text-black">Current delivery fees</h2>
        <DeliveryZonesTable />
      </section>
    </ContentPage>
  );
}
