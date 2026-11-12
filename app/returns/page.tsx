import { SHOP } from '@/lib/shop';
import type { Metadata } from 'next';
import { ContentPage } from '@/components/content/ContentPage';
import { RETURNS } from '@/lib/site-content';

export const metadata: Metadata = {
  title: `Returns & refunds | ${SHOP.name}`,
  description: 'How to request a return and how refunds are sent back to you.',
};

export default function ReturnsPage() {
  return <ContentPage title="Returns & refunds" intro={RETURNS.intro} sections={RETURNS.sections} />;
}
