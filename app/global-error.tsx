'use client';

/**
 * Root error boundary: replaces the whole document when the root layout itself fails, so it
 * renders its own <html>/<body> and uses inline styles (the global stylesheet may not load).
 */
export default function GlobalError({ error, reset }: { error: Error & { digest?: string }; reset: () => void }) {
  return (
    <html lang="en">
      <body
        style={{
          margin: 0,
          minHeight: '100vh',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          padding: '16px',
          background: '#f5f5f5',
          color: '#111111',
          fontFamily: 'Arial, Helvetica, sans-serif',
        }}
      >
        <main
          role="alert"
          style={{
            maxWidth: '420px',
            width: '100%',
            background: '#ffffff',
            border: '1px solid #e5e5e5',
            borderRadius: '24px',
            padding: '32px',
            textAlign: 'center',
          }}
        >
          <h1 style={{ fontSize: '22px', margin: '0 0 8px' }}>Something went wrong</h1>
          <p style={{ fontSize: '14px', lineHeight: 1.5, color: '#525252', margin: '0 0 20px' }}>
            The store could not be loaded. Please try again. If it keeps happening, come back in a few minutes.
          </p>
          {error?.digest && (
            <p style={{ fontSize: '12px', color: '#525252', margin: '0 0 20px' }}>
              Reference: <span style={{ fontFamily: 'monospace' }}>{error.digest}</span>
            </p>
          )}
          <div style={{ display: 'flex', gap: '12px', justifyContent: 'center', flexWrap: 'wrap' }}>
            <button
              type="button"
              onClick={() => reset()}
              style={{
                background: '#000000',
                color: '#ffffff',
                border: 'none',
                borderRadius: '999px',
                padding: '12px 24px',
                fontSize: '14px',
                fontWeight: 600,
                cursor: 'pointer',
              }}
            >
              Try again
            </button>
            {/* A plain link (not next/link): the app router may be the thing that failed. */}
            {/* eslint-disable-next-line @next/next/no-html-link-for-pages */}
            <a
              href="/"
              style={{
                border: '1px solid #d4d4d4',
                color: '#000000',
                borderRadius: '999px',
                padding: '12px 24px',
                fontSize: '14px',
                fontWeight: 600,
                textDecoration: 'none',
              }}
            >
              Go to the homepage
            </a>
          </div>
        </main>
      </body>
    </html>
  );
}
