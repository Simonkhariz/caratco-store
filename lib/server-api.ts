/**
 * Public API reads made by the Next.js server while it builds a page, so pages arrive with
 * their content instead of loading it in the browser afterwards. Never kept by Next.js: every
 * page is built for its visit (app/layout.tsx), and the API's own catalogue cache, cleared by
 * every admin change, keeps these reads quick. So an admin change shows on the very next page.
 * Any failure returns null and the page falls back to loading in the browser, so a slow or
 * offline API never breaks the page.
 */
const BACKEND_ORIGIN = (process.env.BACKEND_ORIGIN || 'http://127.0.0.1:8000').replace(/\/$/, '');

export async function serverApiGet<T>(path: string): Promise<T | null> {
  return (await serverApiGetFull<T>(path))?.data ?? null;
}

/** Like serverApiGet, but keeps the response's `meta` (pagination on list endpoints). */
export async function serverApiGetFull<T, M = Record<string, unknown>>(path: string): Promise<{ data: T; meta: M | null } | null> {
  try {
    const res = await fetch(`${BACKEND_ORIGIN}/api/v1${path}`, {
      headers: { Accept: 'application/json' },
      cache: 'no-store',
      signal: AbortSignal.timeout(4000),
    });
    if (!res.ok) return null;
    const json = (await res.json()) as { success?: boolean; data?: T; meta?: M };
    return json.success && json.data !== undefined ? { data: json.data, meta: json.meta ?? null } : null;
  } catch {
    return null;
  }
}
