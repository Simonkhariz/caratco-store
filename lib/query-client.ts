import { isServer, QueryClient } from '@tanstack/react-query';

/**
 * TanStack Query setup for client-side server data (admin screens, payment status). Pages
 * the server renders keep using lib/server-api.ts, and public catalogue lists keep the short
 * browser cache in lib/api-client.ts; queries are not used for either.
 *
 * Defaults are deliberately conservative; each query sets its own freshness when migrated:
 * - staleTime 0: cached data shows at once when a screen is opened again, and is always
 *   confirmed with the server in the background.
 * - One retry, only for network errors and 5xx answers. A 4xx (not signed in, not allowed,
 *   validation) is never retried.
 * - No refetch on window focus unless a query asks for it.
 * - Mutations are never retried: a checkout, payment or admin change runs at most once.
 */
export const QUERY_RETRY_LIMIT = 1;

/**
 * ApiError (lib/api-client.ts) carries statusCode: 0 for a network failure or timeout, else
 * the HTTP status. Read structurally, so this module doesn't import the API client (which
 * imports clearQueryCache from here).
 */
export function shouldRetryQuery(failureCount: number, error: unknown): boolean {
  if (failureCount >= QUERY_RETRY_LIMIT) return false;
  const status = (error as { statusCode?: unknown } | null)?.statusCode;
  if (typeof status === 'number') return status === 0 || status >= 500;
  return true;
}

function makeQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: 0,
        gcTime: 5 * 60 * 1000,
        retry: shouldRetryQuery,
        refetchOnWindowFocus: false,
      },
      mutations: {
        retry: false,
      },
    },
  });
}

let browserQueryClient: QueryClient | undefined;

/**
 * The browser keeps one client per tab. The server makes a new one for every render, so
 * cached data can never be shared between visitors.
 */
export function getQueryClient(): QueryClient {
  if (isServer) return makeQueryClient();
  browserQueryClient ??= makeQueryClient();
  return browserQueryClient;
}

/** Forget every cached query in this tab (called on sign-out). */
export function clearQueryCache(): void {
  browserQueryClient?.clear();
}
