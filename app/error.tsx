'use client';

import Link from 'next/link';
import { HugeiconsIcon } from '@hugeicons/react';
import { Alert02Icon, RefreshIcon, Home01Icon } from '@hugeicons/core-free-icons';

interface ErrorProps {
  error: Error & { digest?: string };
  reset: () => void;
}

/**
 * Route-level error boundary. Customers never see the technical error; Next.js already logs it
 * on the server. The digest (if any) is a short reference the store can match in its logs.
 */
export default function RouteError({ error, reset }: ErrorProps) {
  return (
    <div className="min-h-[60vh] flex items-center justify-center px-4 py-16">
      <div role="alert" className="max-w-md w-full bg-white rounded-3xl border border-neutral-200 p-8 text-center shadow-lg animate-in fade-in">
        <div className="w-16 h-16 bg-red-100 text-red-600 rounded-full flex items-center justify-center mx-auto mb-4" aria-hidden="true">
          <HugeiconsIcon icon={Alert02Icon} size={32} />
        </div>

        <h1 className="font-integral text-2xl text-black mb-2">SOMETHING WENT WRONG</h1>

        <p className="text-neutral-600 text-sm mb-6 leading-relaxed">
          This page could not be loaded. Please try again. If it keeps happening, go back to the homepage and try again in a few minutes.
        </p>

        {error?.digest && (
          <p className="text-xs text-neutral-500 mb-6">
            Reference: <span className="font-mono">{error.digest}</span>
          </p>
        )}

        <div className="flex flex-col sm:flex-row items-center justify-center gap-3">
          <button
            type="button"
            onClick={() => reset()}
            className="w-full sm:w-auto inline-flex items-center justify-center gap-2 bg-accent hover:bg-accent-hover text-accent-ink font-semibold text-sm px-6 py-3 rounded-full transition-all shadow-md"
          >
            <HugeiconsIcon icon={RefreshIcon} size={16} aria-hidden="true" />
            <span>Try again</span>
          </button>

          <Link
            href="/"
            className="w-full sm:w-auto inline-flex items-center justify-center gap-2 border border-neutral-300 hover:bg-neutral-50 text-black font-semibold text-sm px-6 py-3 rounded-full transition-all"
          >
            <HugeiconsIcon icon={Home01Icon} size={16} aria-hidden="true" />
            <span>Go to the homepage</span>
          </Link>
        </div>
      </div>
    </div>
  );
}
