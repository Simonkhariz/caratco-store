import { SHOP } from '@/lib/shop';
import type { Metadata } from 'next';
import { ContentPage } from '@/components/content/ContentPage';
import { ContactDetails } from '@/components/content/ContactDetails';

export const metadata: Metadata = {
  title: `Contact us | ${SHOP.name}`,
  description: `Email, WhatsApp and phone details for ${SHOP.name} customer support.`,
};

export default function ContactPage() {
  return (
    <ContentPage title="Contact us" intro={SHOP.wording.contactIntro}>
      <ContactDetails />
    </ContentPage>
  );
}
