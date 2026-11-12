import { HomeClient } from '@/components/home/HomeClient';
import type { HomepageData } from '@/lib/api-client';
import { serverApiGet } from '@/lib/server-api';

// Built for each visit (see app/layout.tsx), so the owner's changes show at once.
export default async function HomePage() {
  const initial = await serverApiGet<HomepageData>('/storefront/homepage');
  return <HomeClient initial={initial} />;
}
