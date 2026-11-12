'use client';

import React from 'react';
import Image from '@/components/ui/StoreImage';
import Link from 'next/link';
import { useCart } from '@/context/CartContext';
import { HugeiconsIcon } from '@hugeicons/react';
import { CheckmarkCircle01Icon, Cancel01Icon } from '@hugeicons/core-free-icons';

export const Toast: React.FC = () => {
  const { toast, hideToast } = useCart();

  if (!toast) return null;

  return (
    <div role="status" aria-live="polite" data-toast className="fixed bottom-4 inset-x-4 sm:inset-x-auto sm:right-6 sm:bottom-6 z-[70] max-w-sm sm:w-auto animate-in fade-in slide-in-from-bottom-2 duration-200">
      <div className="bg-neutral-900/95 backdrop-blur-md text-white pl-2.5 pr-2 py-2 rounded-2xl shadow-xl flex items-center gap-2.5 border border-white/10">
        {toast.image ? (
          <div className="relative w-8 h-8 rounded-lg overflow-hidden bg-neutral-800 shrink-0 border border-neutral-700">
            <Image
              src={toast.image}
              alt={toast.title}
              fill
              className="object-cover"
              sizes="32px"
            />
          </div>
        ) : (
          <div className="w-7 h-7 rounded-full bg-emerald-500/20 text-emerald-400 flex items-center justify-center shrink-0">
            <HugeiconsIcon icon={CheckmarkCircle01Icon} size={16} />
          </div>
        )}

        <div className="flex-1 min-w-0 pr-1">
          <p className="text-xs font-bold text-white truncate">{toast.title}</p>
          <p className="text-xs text-neutral-300 truncate">{toast.message}</p>
        </div>

        {toast.showCartAction && (
          <Link
            href="/cart"
            onClick={hideToast}
            className="bg-white text-black hover:bg-neutral-200 text-xs font-bold px-2.5 py-1 rounded-full shrink-0 transition-colors"
          >
            View cart
          </Link>
        )}

        <button
          onClick={hideToast}
          className="text-neutral-400 hover:text-white p-1 rounded-md transition-colors shrink-0"
          aria-label="Close notification"
        >
          <HugeiconsIcon icon={Cancel01Icon} size={14} />
        </button>
      </div>
    </div>
  );
};
