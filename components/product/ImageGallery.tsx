'use client';

import React, { useState, useEffect } from 'react';
import Image from '@/components/ui/StoreImage';
import { Dialog } from '@/components/ui/Dialog';
import { HugeiconsIcon } from '@hugeicons/react';
import { Search01Icon, Cancel01Icon, ArrowLeft01Icon, ArrowRight01Icon } from '@hugeicons/core-free-icons';

interface ImageGalleryProps {
  images: string[];
  productName: string;
}

export const ImageGallery: React.FC<ImageGalleryProps> = ({ images, productName }) => {
  const [selectedIndex, setSelectedIndex] = useState(0);
  const [isZoomModalOpen, setIsZoomModalOpen] = useState(false);
  const [zoomPos, setZoomPos] = useState({ x: 50, y: 50 });
  const [isHovered, setIsHovered] = useState(false);
  const count = images.length;
  const hasMany = count > 1;

  // Back to the first photo when the photos change (another product or colour).
  const [indexFor, setIndexFor] = useState(images);
  if (images !== indexFor) {
    setIndexFor(images);
    setSelectedIndex(0);
  }

  const altFor = (idx: number) => (count > 1 ? `${productName}, photo ${idx + 1} of ${count}` : productName);
  const show = (idx: number) => setSelectedIndex(((idx % count) + count) % count);

  // Arrow keys move through photos while the lightbox is open.
  useEffect(() => {
    if (!isZoomModalOpen || !hasMany) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'ArrowRight') {
        e.preventDefault();
        setSelectedIndex((i) => (i + 1) % count);
      } else if (e.key === 'ArrowLeft') {
        e.preventDefault();
        setSelectedIndex((i) => (i - 1 + count) % count);
      }
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [isZoomModalOpen, hasMany, count]);

  const handleMouseMove = (e: React.MouseEvent<HTMLButtonElement>) => {
    const { left, top, width, height } = e.currentTarget.getBoundingClientRect();
    setZoomPos({ x: ((e.clientX - left) / width) * 100, y: ((e.clientY - top) / height) * 100 });
  };

  const current = images[selectedIndex] || images[0];

  return (
    <div className="flex flex-col-reverse lg:flex-row gap-4 sm:gap-6 w-full items-start">
      {/* Thumbnails: horizontal on mobile, vertical on desktop */}
      {hasMany && (
        <div
          className="flex lg:flex-col gap-3 sm:gap-3.5 overflow-x-auto lg:overflow-visible no-scrollbar justify-start shrink-0 w-full lg:w-[140px] xl:w-[152px] py-2 sm:py-2.5 px-1 -mx-1"
          role="group"
          aria-label="Product photos"
        >
          {images.map((img, idx) => {
            const isSelected = selectedIndex === idx;
            return (
              <button
                key={idx}
                type="button"
                onClick={() => setSelectedIndex(idx)}
                aria-label={`Show photo ${idx + 1} of ${count}`}
                aria-current={isSelected ? 'true' : undefined}
                className={`relative aspect-square w-[30%] min-w-[90px] max-w-[120px] sm:w-28 sm:min-w-0 sm:max-w-none lg:w-full lg:h-[160px] xl:lg:h-[167px] bg-card rounded-2xl overflow-hidden shrink-0 transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 ${
                  isSelected ? 'ring-2 ring-accent ring-offset-2 shadow-sm' : 'hover:opacity-80 opacity-90'
                }`}
              >
                {/* The first thumbnail is the main photo again: load it with the page like the main one. */}
                <Image src={img} alt="" fill loading={idx === 0 ? 'eager' : undefined} sizes="(max-width: 639px) 120px, (max-width: 1023px) 112px, 168px" className="object-cover object-center" />
              </button>
            );
          })}
        </div>
      )}

      {/* Main photo: hover to magnify, click / Enter to open the full-screen view */}
      <button
        type="button"
        onPointerEnter={(e) => {
          if (e.pointerType === 'mouse') setIsHovered(true);
        }}
        onPointerLeave={() => setIsHovered(false)}
        onMouseMove={handleMouseMove}
        onClick={() => setIsZoomModalOpen(true)}
        aria-label={`Open full-screen view of ${altFor(selectedIndex)}`}
        className="relative flex-1 min-w-0 w-full aspect-square lg:aspect-auto lg:h-[500px] xl:lg:h-[530px] bg-card rounded-3xl overflow-hidden shadow-sm cursor-zoom-in group focus:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2"
      >
        <Image
          src={current}
          alt={altFor(selectedIndex)}
          fill
          preload
          sizes="(max-width: 1024px) 100vw, 550px"
          className="object-cover object-center transition-transform duration-200"
          style={isHovered ? { transformOrigin: `${zoomPos.x}% ${zoomPos.y}%`, transform: 'scale(1.4)' } : undefined}
        />
        <span
          className="absolute top-4 right-4 bg-white/80 backdrop-blur-sm p-2.5 rounded-full text-black opacity-0 group-hover:opacity-100 group-focus-visible:opacity-100 transition-opacity shadow-sm pointer-events-none"
          aria-hidden="true"
        >
          <HugeiconsIcon icon={Search01Icon} size={18} />
        </span>
      </button>

      {/* Full-screen lightbox */}
      <Dialog
        open={isZoomModalOpen}
        onClose={() => setIsZoomModalOpen(false)}
        title={`${productName} photos`}
        placement="fullscreen"
        backdropClassName="bg-black/90 backdrop-blur-md"
        panelClassName="w-full max-w-5xl flex flex-col items-center gap-4"
      >
        <button
          type="button"
          onClick={() => setIsZoomModalOpen(false)}
          className="fixed top-5 right-5 z-10 p-2 rounded-full bg-white/10 hover:bg-white/20 text-white transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
          aria-label="Close full-screen view"
        >
          <HugeiconsIcon icon={Cancel01Icon} size={24} />
        </button>

        <div className="relative w-full h-[75vh]">
          <Image src={current} alt={altFor(selectedIndex)} fill className="object-contain" sizes="100vw" />
        </div>

        {hasMany && (
          <div className="flex items-center gap-4 text-white">
            <button
              type="button"
              onClick={() => show(selectedIndex - 1)}
              className="p-2.5 rounded-full bg-white/10 hover:bg-white/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
              aria-label="Previous photo"
            >
              <HugeiconsIcon icon={ArrowLeft01Icon} size={22} />
            </button>
            <span className="text-sm tabular-nums" aria-live="polite">
              {selectedIndex + 1} / {count}
            </span>
            <button
              type="button"
              onClick={() => show(selectedIndex + 1)}
              className="p-2.5 rounded-full bg-white/10 hover:bg-white/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
              aria-label="Next photo"
            >
              <HugeiconsIcon icon={ArrowRight01Icon} size={22} />
            </button>
          </div>
        )}
      </Dialog>
    </div>
  );
};
