'use client';

import { SHOP } from '@/lib/shop';
import React, { useEffect, useId, useRef, useState } from 'react';
import { useAuth } from '@/context/AuthContext';
import { ApiError, errorMessage as describeError } from '@/lib/api-client';
import { firstValidationMessage } from '@/lib/checkout-validation';
import { HugeiconsIcon } from '@hugeicons/react';
import { Cancel01Icon, Mail01Icon, LockPasswordIcon, UserIcon, CallIcon, CheckmarkCircle02Icon } from '@hugeicons/core-free-icons';
import { Dialog } from '@/components/ui/Dialog';

type Tab = 'login' | 'register' | 'magic';

const TABS: Array<{ id: Tab; label: string }> = [
  { id: 'login', label: 'Sign in' },
  { id: 'register', label: 'Register' },
  { id: 'magic', label: 'Email link' },
];

const inputClass =
  'w-full pl-10 pr-4 py-3 rounded-full bg-neutral-50 border border-neutral-300 text-sm text-black placeholder:text-neutral-500 focus:outline-none focus:ring-2 focus:ring-accent';
const labelClass = 'block text-xs font-semibold text-neutral-700 mb-1.5';

/** An input with a leading decorative icon and a properly linked label. */
function IconField({
  id,
  label,
  icon,
  hint,
  ...input
}: React.InputHTMLAttributes<HTMLInputElement> & { id: string; label: string; icon: typeof Mail01Icon; hint?: string }) {
  const hintId = hint ? `${id}-hint` : undefined;
  return (
    <div>
      <label htmlFor={id} className={labelClass}>
        {label}
      </label>
      <div className="relative">
        <input id={id} aria-describedby={hintId} className={inputClass} {...input} />
        <span className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-neutral-500" aria-hidden="true">
          <HugeiconsIcon icon={icon} size={18} />
        </span>
      </div>
      {hint && (
        <p id={hintId} className="mt-1 text-xs text-neutral-600">
          {hint}
        </p>
      )}
    </div>
  );
}

export const AuthModal: React.FC = () => {
  const { isAuthModalOpen, authModalTab, authNotice, openAuthModal, closeAuthModal, login, register, requestMagicLink } = useAuth();
  const uid = useId();
  const panelRef = useRef<HTMLDivElement>(null);
  const tabRefs = useRef<Record<Tab, HTMLButtonElement | null>>({ login: null, register: null, magic: null });

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [fullName, setFullName] = useState('');
  const [phone, setPhone] = useState('');
  const [magicSent, setMagicSent] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  const tab: Tab = authModalTab as Tab;

  // Closing the window forgets the password and any message, so reopening starts clean. What was
  // typed for the name and email stays (closing by mistake should not cost the whole form).
  const [wasOpen, setWasOpen] = useState(isAuthModalOpen);
  if (wasOpen !== isAuthModalOpen) {
    setWasOpen(isAuthModalOpen);
    if (!isAuthModalOpen) {
      setPassword('');
      setMagicSent(false);
      setErrorMessage(null);
      setSuccessMessage(null);
    }
  }

  // An error is shown at the top of the form: bring it into view (on a short phone the shopper is
  // down at the button when it appears).
  useEffect(() => {
    if (errorMessage) panelRef.current?.querySelector('[role="alert"]')?.scrollIntoView({ block: 'nearest' });
  }, [errorMessage]);

  // When the tab changes, move focus to its first field (the Dialog focuses on open).
  const firstRender = useRef(true);
  useEffect(() => {
    if (!isAuthModalOpen) {
      firstRender.current = true;
      return;
    }
    if (firstRender.current) {
      firstRender.current = false;
      return;
    }
    panelRef.current?.querySelector<HTMLElement>('[role="tabpanel"] input')?.focus({ preventScroll: true });
  }, [tab, isAuthModalOpen]);

  if (!isAuthModalOpen) return null;

  const resetForm = () => {
    setEmail('');
    setPassword('');
    setFullName('');
    setPhone('');
    setMagicSent(false);
    setErrorMessage(null);
    setSuccessMessage(null);
  };

  const switchTab = (next: Tab) => {
    resetForm();
    openAuthModal(next);
  };

  // Arrow keys move between tabs (WAI-ARIA tabs pattern).
  const onTabKeyDown = (e: React.KeyboardEvent) => {
    const order = TABS.map((t) => t.id);
    const i = order.indexOf(tab);
    let next: Tab | null = null;
    if (e.key === 'ArrowRight') next = order[(i + 1) % order.length];
    if (e.key === 'ArrowLeft') next = order[(i - 1 + order.length) % order.length];
    if (e.key === 'Home') next = order[0];
    if (e.key === 'End') next = order[order.length - 1];
    if (next) {
      e.preventDefault();
      switchTab(next);
      tabRefs.current[next]?.focus();
    }
  };

  const handleLoginSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSubmitting(true);
    setErrorMessage(null);
    try {
      await login(email, password);
      resetForm();
    } catch (err) {
      setErrorMessage(describeError(err, 'Sign in failed. Please check your email and password.'));
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleRegisterSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSubmitting(true);
    setErrorMessage(null);
    try {
      await register({ full_name: fullName, email, password, phone: phone || undefined });
      resetForm();
    } catch (err) {
      // A 422 says which detail is wrong; its headline only says the form failed.
      const detail = err instanceof ApiError && err.statusCode === 422 ? firstValidationMessage(err.details) : null;
      setErrorMessage(detail ?? describeError(err, 'We could not create your account. Please check your details.'));
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleMagicRequest = async (e?: React.FormEvent) => {
    e?.preventDefault();
    setIsSubmitting(true);
    setErrorMessage(null);
    try {
      await requestMagicLink(email);
      setMagicSent(true);
    } catch (err) {
      setErrorMessage(describeError(err, 'We could not send a sign-in link. Please try again.'));
    } finally {
      setIsSubmitting(false);
    }
  };

  const panelId = `${uid}-panel`;
  const tabId = (t: Tab) => `${uid}-tab-${t}`;
  const title = tab === 'register' ? 'Create an account' : tab === 'magic' ? 'Sign in with an email link' : 'Sign in';

  return (
    <Dialog
      open={isAuthModalOpen}
      onClose={closeAuthModal}
      title={title}
      busy={isSubmitting}
      panelClassName="w-full max-w-md"
    >
      <div ref={panelRef} className="relative flex max-h-[92vh] supports-[height:100dvh]:max-h-[92dvh] w-full flex-col overflow-hidden rounded-t-3xl bg-white shadow-2xl animate-in zoom-in-95 sm:rounded-3xl">
        {/* A fixed row: only the form below it scrolls (long sign-up form on a short phone). */}
        <div className="flex shrink-0 items-center justify-between border-b border-neutral-100 py-3 pl-6 pr-4 sm:pl-8 sm:pr-6">
          <span className="font-integral text-xl font-bold tracking-tight text-black" aria-hidden="true">
            {SHOP.name}
          </span>
          <button
            type="button"
            onClick={closeAuthModal}
            disabled={isSubmitting}
            className="rounded-full p-2 text-neutral-600 transition-colors hover:bg-neutral-100 hover:text-black disabled:opacity-50"
            aria-label="Close sign-in"
          >
            <HugeiconsIcon icon={Cancel01Icon} size={20} aria-hidden="true" />
          </button>
        </div>

        <div className="min-h-[min(32rem,70vh)] flex-1 overflow-y-auto p-6 sm:min-h-0 sm:px-8">

        <div role="tablist" aria-label="How do you want to sign in?" className="flex rounded-full bg-neutral-100 p-1 mb-6 text-sm font-medium" onKeyDown={onTabKeyDown}>
          {TABS.map((t) => {
            const selected = tab === t.id;
            return (
              <button
                key={t.id}
                ref={(el) => {
                  tabRefs.current[t.id] = el;
                }}
                id={tabId(t.id)}
                type="button"
                role="tab"
                aria-selected={selected}
                aria-controls={panelId}
                tabIndex={selected ? 0 : -1}
                onClick={() => switchTab(t.id)}
                className={`flex-1 py-2 text-center rounded-full transition-all ${
                  selected ? 'bg-white text-black shadow-sm' : 'text-neutral-600 hover:text-black'
                }`}
              >
                {t.label}
              </button>
            );
          })}
        </div>

        {authNotice && !errorMessage && (
          <div role="status" className="mb-4 p-3.5 rounded-2xl bg-amber-50 text-amber-900 text-sm font-medium border border-amber-200">
            {authNotice}
          </div>
        )}
        {errorMessage && (
          <div role="alert" className="mb-4 p-3.5 rounded-2xl bg-red-50 text-red-800 text-sm font-medium border border-red-200">
            {errorMessage}
          </div>
        )}
        {successMessage && (
          <div role="status" className="mb-4 p-3.5 rounded-2xl bg-emerald-50 text-emerald-800 text-sm font-medium border border-emerald-100 flex items-center gap-2">
            <HugeiconsIcon icon={CheckmarkCircle02Icon} size={18} aria-hidden="true" />
            <span>{successMessage}</span>
          </div>
        )}

        <div role="tabpanel" id={panelId} aria-labelledby={tabId(tab)}>
          {tab === 'login' && (
            <form onSubmit={handleLoginSubmit} className="space-y-4">
              <IconField
                id={`${uid}-login-email`}
                label="Email address"
                icon={Mail01Icon}
                type="email"
                required
                autoComplete="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="name@example.com"
              />
              <IconField
                id={`${uid}-login-password`}
                label="Password"
                icon={LockPasswordIcon}
                type="password"
                required
                autoComplete="current-password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
              />

              <button
                type="submit"
                disabled={isSubmitting}
                className="w-full py-3.5 px-6 rounded-full bg-accent text-accent-ink text-sm font-semibold hover:bg-accent-hover transition-colors disabled:opacity-50 mt-2"
              >
                {isSubmitting ? 'Signing in…' : 'Sign in'}
              </button>

              <div className="text-center pt-2">
                <button type="button" onClick={() => switchTab('magic')} className="text-sm text-neutral-600 hover:text-black underline">
                  No password? Get a sign-in link by email
                </button>
              </div>
            </form>
          )}

          {tab === 'register' && (
            <form onSubmit={handleRegisterSubmit} className="space-y-3.5">
              <IconField
                id={`${uid}-reg-name`}
                label="Full name"
                icon={UserIcon}
                type="text"
                required
                autoComplete="name"
                value={fullName}
                onChange={(e) => setFullName(e.target.value)}
              />
              <IconField
                id={`${uid}-reg-email`}
                label="Email address"
                icon={Mail01Icon}
                type="email"
                required
                autoComplete="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="name@example.com"
              />
              <IconField
                id={`${uid}-reg-phone`}
                label="Phone number (optional)"
                icon={CallIcon}
                type="tel"
                autoComplete="tel"
                inputMode="tel"
                value={phone}
                onChange={(e) => setPhone(e.target.value)}
              />
              <IconField
                id={`${uid}-reg-password`}
                label="Password"
                icon={LockPasswordIcon}
                type="password"
                required
                minLength={8}
                autoComplete="new-password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                hint="At least 8 characters."
              />

              <button
                type="submit"
                disabled={isSubmitting}
                className="w-full py-3.5 px-6 rounded-full bg-accent text-accent-ink text-sm font-semibold hover:bg-accent-hover transition-colors disabled:opacity-50 mt-2"
              >
                {isSubmitting ? 'Creating account…' : 'Create account'}
              </button>
            </form>
          )}

          {tab === 'magic' && (
            <div className="space-y-4">
              {!magicSent ? (
                <form onSubmit={handleMagicRequest} className="space-y-4">
                  <p className="text-sm text-neutral-600">We&apos;ll email you a one-time link to sign in. No password needed.</p>
                  <IconField
                    id={`${uid}-magic-email`}
                    label="Email address"
                    icon={Mail01Icon}
                    type="email"
                    required
                    autoComplete="email"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    placeholder="name@example.com"
                  />
                  <button
                    type="submit"
                    disabled={isSubmitting}
                    className="w-full py-3.5 px-6 rounded-full bg-accent text-accent-ink text-sm font-semibold hover:bg-accent-hover transition-colors disabled:opacity-50"
                  >
                    {isSubmitting ? 'Sending link…' : 'Send sign-in link'}
                  </button>
                </form>
              ) : (
                <div className="space-y-4" role="status" aria-live="polite">
                  <div className="p-4 bg-neutral-50 rounded-2xl text-sm text-neutral-700 border border-neutral-200">
                    <p className="font-semibold text-neutral-900 mb-1">Check your inbox</p>
                    <p>
                      If <strong className="text-black">{email}</strong> can receive sign-in links, we&apos;ve just sent one. Open it on this
                      device to sign in. The link works once and expires in 15 minutes.
                    </p>
                  </div>

                  <div className="flex items-center justify-center gap-4">
                    <button type="button" onClick={() => setMagicSent(false)} className="text-sm text-neutral-600 hover:text-black underline">
                      Use a different email
                    </button>
                    <span className="text-neutral-400" aria-hidden="true">
                      |
                    </span>
                    <button
                      type="button"
                      disabled={isSubmitting}
                      onClick={() => handleMagicRequest()}
                      className="text-sm text-neutral-600 hover:text-black underline disabled:opacity-50"
                    >
                      {isSubmitting ? 'Sending…' : 'Send another link'}
                    </button>
                  </div>
                </div>
              )}
            </div>
          )}
        </div>
        </div>
      </div>
    </Dialog>
  );
};
