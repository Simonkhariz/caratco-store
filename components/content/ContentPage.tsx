import React from 'react';
import Link from 'next/link';
import type { ContentSection } from '@/lib/site-content';

interface ContentPageProps {
  title: string;
  intro?: string;
  sections?: ContentSection[];
  children?: React.ReactNode;
}

/** Shared layout for the storefront information pages (copy lives in lib/site-content.ts). */
export function ContentPage({ title, intro, sections = [], children }: ContentPageProps) {
  return (
    <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 sm:py-12 lg:px-8">
      <nav aria-label="Breadcrumb" className="mb-6 text-sm text-neutral-600">
        <ol className="flex items-center gap-2">
          <li>
            <Link href="/" className="hover:text-black hover:underline">
              Home
            </Link>
          </li>
          <li aria-hidden="true">/</li>
          <li aria-current="page" className="text-black">
            {title}
          </li>
        </ol>
      </nav>

      <div className="max-w-3xl">
        <h1 className="font-integral text-3xl uppercase tracking-tight text-black sm:text-4xl">{title}</h1>
        {intro && <p className="mt-4 text-base leading-relaxed text-neutral-700">{intro}</p>}

        {sections.map((section) => (
          <section key={section.heading} className="mt-8">
            <h2 className="text-lg font-bold text-black">{section.heading}</h2>
            {section.body.map((paragraph) => (
              <p key={paragraph} className="mt-3 text-sm leading-relaxed text-neutral-700 sm:text-base">
                {paragraph}
              </p>
            ))}
          </section>
        ))}

        {children}
      </div>
    </div>
  );
}
