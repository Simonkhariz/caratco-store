'use client';

import React from 'react';
import { usePathname } from 'next/navigation';
import { Header } from '@/components/layout/Header';
import { Footer } from '@/components/layout/Footer';
import { Toast } from '@/components/ui/Toast';
import { WishlistDrawer } from '@/components/layout/WishlistDrawer';
import { StoreLiveUpdates } from '@/components/layout/StoreLiveUpdates';

interface StoreShellProps {
  children: React.ReactNode;
}

export const StoreShell: React.FC<StoreShellProps> = ({ children }) => {
  const pathname = usePathname();
  const isAdmin = pathname?.startsWith('/admin');

  if (isAdmin) {
    return <div className="min-h-screen w-full bg-[#f8f8f8]">{children}</div>;
  }

  return (
    <>
      <Header />
      <main className="flex-grow">{children}</main>
      <Footer />
      <Toast />
      <WishlistDrawer />
      <StoreLiveUpdates />
    </>
  );
};
