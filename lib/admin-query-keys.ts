import type { Query } from '@tanstack/react-query';
import { getQueryClient } from '@/lib/query-client';

/**
 * Admin query keys, and the refreshes admin changes trigger (called from lib/admin-api.ts).
 * Every key starts with ['admin', adminId], so one admin's cached data is never shown to
 * another, even if a session ends without signing out (sign-out also clears the cache).
 */
export const adminKeys = {
  all: (adminId: number) => ['admin', adminId] as const,
  bell: (adminId: number) => ['admin', adminId, 'bell'] as const,
  storageAlert: (adminId: number) => ['admin', adminId, 'storage-alert'] as const,
  devMode: (adminId: number) => ['admin', adminId, 'dev-mode'] as const,
};

const adminQuery = (part: string) => (query: Query) => query.queryKey[0] === 'admin' && query.queryKey[2] === part;

/** Bell counts may have changed (payment confirmed, order cancelled, refund resolved…). */
export function refreshBellCounts(): void {
  void getQueryClient().invalidateQueries({ predicate: adminQuery('bell') });
}

/** The database alert may have changed (clean-up run, retention or alert settings saved). */
export function refreshStorageAlert(): void {
  void getQueryClient().invalidateQueries({ predicate: adminQuery('storage-alert') });
}

/** Dev mode or a tech switch changed. */
export function refreshDevMode(): void {
  void getQueryClient().invalidateQueries({ predicate: adminQuery('dev-mode') });
}

/**
 * Run `refresh` once an admin change has finished: after a success, and also after a network
 * failure, timeout or server error, because the change may have been saved before the answer
 * was lost. A refused change (4xx: validation, not allowed) changed nothing, so nothing is
 * refreshed. The change's own result or error is passed on unchanged.
 */
export function refreshAfter<T>(change: Promise<T>, refresh: () => void): Promise<T> {
  return change.then(
    (result) => {
      refresh();
      return result;
    },
    (error: unknown) => {
      const status = (error as { statusCode?: unknown } | null)?.statusCode;
      if (typeof status !== 'number' || status === 0 || status >= 500) refresh();
      throw error;
    }
  );
}
