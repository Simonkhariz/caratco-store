import { Landmark } from 'lucide-react';
import { VISA_LOGO } from '@/lib/brand-logos';

const tile = 'flex h-8 min-w-[3.25rem] items-center justify-center rounded-md border border-neutral-200 bg-white px-2 shadow-sm';

/** Payment options the store actually accepts (cards through Kora / Monnify, or a bank transfer). */
export function PaymentBadges() {
  return (
    <ul aria-label="Accepted payment methods" className="flex flex-wrap items-center justify-center gap-2">
      <li className={tile}>
        <svg role="img" aria-label="Visa" viewBox={VISA_LOGO.viewBox} className="h-3.5 w-auto fill-[#1A1F71]">
          <path d={VISA_LOGO.path} />
        </svg>
      </li>
      <li className={tile}>
        {/* The Mastercard mark: two overlapping circles in its brand colours. */}
        <svg role="img" aria-label="Mastercard" viewBox="0 0 38 24" className="h-5 w-auto">
          <circle cx="12" cy="12" r="10" fill="#EB001B" />
          <circle cx="26" cy="12" r="10" fill="#F79E1B" />
          {/* Where the circles overlap (they meet at x = 19, y = 12 ± 7.14). */}
          <path d="M19 4.86a10 10 0 0 1 0 14.28 10 10 0 0 1 0-14.28z" fill="#FF5F00" />
        </svg>
      </li>
      <li className={tile}>
        <span className="text-[13px] font-extrabold italic leading-none tracking-tight text-[#00425F]">Verve</span>
      </li>
      <li className={`${tile} gap-1.5`}>
        <Landmark className="h-3.5 w-3.5 text-neutral-800" aria-hidden="true" />
        <span className="text-[11px] font-semibold leading-none text-neutral-800">Bank transfer</span>
      </li>
    </ul>
  );
}
