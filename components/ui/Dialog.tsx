'use client';

/**
 * Storefront dialog / drawer primitive.
 *
 * Gives every overlay the same accessible behaviour: role="dialog" + aria-modal, a label,
 * Escape to close (unless `busy`), focus moved into the panel and restored on close, Tab kept
 * inside the panel, page scroll locked, and backdrop click to close (unless `busy` or
 * `dismissOnBackdrop` is false). Visuals stay with the caller via `panelClassName`.
 */

import React, { useEffect, useEffectEvent, useId, useRef } from 'react';

type Placement = 'center' | 'left' | 'right' | 'bottom' | 'fullscreen';

export interface DialogProps {
  open: boolean;
  onClose: () => void;
  /** Accessible name. Rendered visually only when `showTitle` is true; otherwise sr-only. */
  title: string;
  showTitle?: boolean;
  /** Id of an element inside the panel that describes it. */
  describedBy?: string;
  placement?: Placement;
  /** Classes for the panel (size, colours, radius, padding). */
  panelClassName?: string;
  /** Classes for the backdrop. */
  backdropClassName?: string;
  /** Block closing (e.g. while a payment request is in flight). */
  busy?: boolean;
  dismissOnBackdrop?: boolean;
  /** Element to focus first; defaults to the first focusable element in the panel. */
  initialFocusRef?: React.RefObject<HTMLElement>;
  children: React.ReactNode;
}

const FOCUSABLE =
  'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/** Dialogs open right now, newest last: only the newest one handles Escape and Tab. */
const openDialogs: symbol[] = [];
/** What <body>'s overflow was before the first dialog opened; put back when the last one closes. */
let overflowBeforeDialogs: string | null = null;

const PLACEMENT_WRAPPER: Record<Placement, string> = {
  center: 'items-end justify-center p-0 sm:items-center sm:p-4',
  left: 'items-stretch justify-start',
  right: 'items-stretch justify-end',
  bottom: 'items-end justify-center',
  fullscreen: 'items-center justify-center p-4',
};

export function Dialog({
  open,
  onClose,
  title,
  showTitle = false,
  describedBy,
  placement = 'center',
  panelClassName = '',
  backdropClassName = 'bg-black/50 backdrop-blur-sm',
  busy = false,
  dismissOnBackdrop = true,
  initialFocusRef,
  children,
}: DialogProps) {
  const titleId = useId();
  const panelRef = useRef<HTMLDivElement>(null);
  // Escape always uses the latest onClose / busy without re-running the focus effect.
  const closeOnEscape = useEffectEvent((): boolean => {
    if (busy) return false;
    onClose();
    return true;
  });

  useEffect(() => {
    if (!open) return;
    const previouslyFocused = document.activeElement as HTMLElement | null;
    const panel = panelRef.current;
    const first = initialFocusRef?.current ?? panel?.querySelector<HTMLElement>('[data-autofocus]') ?? panel?.querySelector<HTMLElement>(FOCUSABLE);
    (first ?? panel)?.focus({ preventScroll: true });

    const self = Symbol('dialog');
    openDialogs.push(self);

    const onKey = (e: KeyboardEvent) => {
      // A dialog opened over this one (quick add over the wishlist) answers the keys, not this one.
      if (openDialogs[openDialogs.length - 1] !== self) return;
      if (e.key === 'Escape') {
        if (closeOnEscape()) e.stopPropagation();
        return;
      }
      if (e.key !== 'Tab' || !panel) return;
      const items = Array.from(panel.querySelectorAll<HTMLElement>(FOCUSABLE)).filter((el) => el.offsetParent !== null || el === document.activeElement);
      if (items.length === 0) {
        e.preventDefault();
        return;
      }
      const firstEl = items[0];
      const lastEl = items[items.length - 1];
      // Focus fell outside the dialog (the focused control was removed, or a click on the page
      // edge): Tab comes back in instead of wandering through the page behind.
      if (!panel.contains(document.activeElement)) {
        e.preventDefault();
        (e.shiftKey ? lastEl : firstEl).focus();
        return;
      }
      if (e.shiftKey && (document.activeElement === firstEl || document.activeElement === panel)) {
        e.preventDefault();
        lastEl.focus();
      } else if (!e.shiftKey && document.activeElement === lastEl) {
        e.preventDefault();
        firstEl.focus();
      }
    };

    document.addEventListener('keydown', onKey);
    // The page stays locked until the last dialog closes, whatever order they close in (each one
    // restoring "what it found" could leave the page locked when an underneath one closes first).
    if (openDialogs.length === 1) overflowBeforeDialogs = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      const at = openDialogs.indexOf(self);
      if (at !== -1) openDialogs.splice(at, 1);
      if (openDialogs.length === 0) {
        document.body.style.overflow = overflowBeforeDialogs ?? '';
        overflowBeforeDialogs = null;
      }
      previouslyFocused?.focus?.({ preventScroll: true });
    };
  }, [open, initialFocusRef]);

  if (!open) return null;

  return (
    <div className={`fixed inset-0 z-50 flex ${PLACEMENT_WRAPPER[placement]}`}>
      <div
        className={`animate-fade fixed inset-0 ${backdropClassName}`}
        onClick={() => {
          if (dismissOnBackdrop && !busy) onClose();
        }}
        aria-hidden="true"
      />
      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        aria-describedby={describedBy}
        tabIndex={-1}
        className={`relative outline-none ${panelClassName}`}
      >
        <h2 id={titleId} className={showTitle ? 'text-lg font-bold text-black' : 'sr-only'}>
          {title}
        </h2>
        {children}
      </div>
    </div>
  );
}
