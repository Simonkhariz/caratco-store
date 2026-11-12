import { BRAND_LOGOS, brandKey } from '@/lib/brand-logos';

/** Wordmark looks for brands that have no logo in lib/brand-logos.ts. */
const WORDMARK_CLASS: Record<string, string> = {
  gucci: 'font-serif uppercase tracking-[0.28em]',
  calvinklein: 'uppercase font-light tracking-[0.32em]',
  tommyhilfiger: 'uppercase font-bold tracking-[0.12em]',
  levis: 'font-black italic tracking-tight',
  versace: 'font-serif uppercase tracking-[0.3em]',
  prada: 'font-serif uppercase tracking-[0.24em]',
};

function Brand({ name }: { name: string }) {
  const key = brandKey(name);
  const logo = BRAND_LOGOS[key];
  if (logo) {
    const [, , w, h] = logo.viewBox.split(' ').map(Number);
    return (
      <svg
        role="img"
        aria-label={name}
        viewBox={logo.viewBox}
        className={`w-auto shrink-0 fill-current ${w / h > 3 ? 'h-5 sm:h-6' : 'h-6 sm:h-8'}`}
        // Very flat wordmarks (wider than 3:1) are drawn a little shorter so they look the same size.
      >
        <path d={logo.path} />
      </svg>
    );
  }
  return (
    <span className={`shrink-0 whitespace-nowrap text-lg sm:text-2xl ${WORDMARK_CLASS[key] ?? 'font-bold uppercase tracking-wide'}`}>
      {name}
    </span>
  );
}

/**
 * Endlessly moving strip of the brands the store carries (admin Settings → "storefront_brands"),
 * the last thing on the home page's first screen. Hidden when no brands are set.
 */
export function BrandMarquee({ brands }: { brands?: string[] }) {
  const list = (brands ?? []).map((b) => b.trim()).filter(Boolean);
  if (list.length === 0) return null;
  // Repeat the brands until one copy is wider than a large screen (16 logos is about 2,000px),
  // then show that copy twice so the loop joins up.
  const copy = [...list];
  while (copy.length < 16) copy.push(...list);

  return (
    <section aria-label="Brands we stock" className="shrink-0 bg-accent py-[clamp(1rem,2.4svh,1.25rem)] text-white sm:py-[clamp(1.1rem,2.6svh,1.75rem)]">
      <div
        className="marquee overflow-hidden"
        style={{ maskImage: 'linear-gradient(to right, transparent, black 6%, black 94%, transparent)' }}
      >
        <ul className="marquee-track flex w-max items-center" style={{ ['--marquee-duration' as string]: `${copy.length * 3.5}s` }}>
          {[0, 1].map((half) =>
            copy.map((name, i) => (
              <li key={`${half}-${i}`} aria-hidden={half === 1 || i >= list.length ? true : undefined} className="flex items-center px-6 sm:px-10">
                <Brand name={name} />
              </li>
            ))
          )}
        </ul>
      </div>
    </section>
  );
}
