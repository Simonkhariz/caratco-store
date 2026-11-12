import { SHOP } from '@/lib/shop';
import type { Metadata } from 'next';
import { ContentPage } from '@/components/content/ContentPage';
import { PRIVACY } from '@/lib/site-content';

export const metadata: Metadata = {
  title: `Privacy policy | ${SHOP.name}`,
  description: `What personal information ${SHOP.name} collects, why, and your choices.`,
};

export default function PrivacyPage() {
  return <ContentPage title="Privacy policy" intro={PRIVACY.intro} sections={PRIVACY.sections} />;
}
