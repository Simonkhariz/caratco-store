import { SHOP } from '@/lib/shop';
import type { Metadata } from 'next';
import { ContentPage } from '@/components/content/ContentPage';
import { ABOUT } from '@/lib/site-content';

export const metadata: Metadata = {
  title: `About us | ${SHOP.name}`,
  description: `Who we are and how ordering from ${SHOP.name} works: payment, delivery, tracking and emails.`,
};

export default function AboutPage() {
  return <ContentPage title="About us" intro={ABOUT.intro} sections={ABOUT.sections} />;
}
