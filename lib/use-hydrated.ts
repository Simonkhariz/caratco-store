import { useSyncExternalStore } from 'react';

const noSubscription = () => () => {};

/**
 * False in the server's HTML and until the page's scripts have run. A button painted before then
 * can't respond, so buttons a shopper taps at once are shown as not ready rather than ignoring them.
 */
export function useHydrated(): boolean {
  return useSyncExternalStore(noSubscription, () => true, () => false);
}
