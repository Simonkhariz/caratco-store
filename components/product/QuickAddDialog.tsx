'use client';

import { useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import Link from 'next/link';
import Image from '@/components/ui/StoreImage';
import { useRouter } from 'next/navigation';
import { HugeiconsIcon } from '@hugeicons/react';
import { Cancel01Icon } from '@hugeicons/core-free-icons';
import { Product, ProductColor } from '@/types';
import { useCart, MAX_QUANTITY_PER_LINE } from '@/context/CartContext';
import { Dialog } from '@/components/ui/Dialog';
import { VariantSelector } from '@/components/product/VariantSelector';
import { formatPrice } from '@/lib/currency';
import { colorStockFor, findVariant, firstChoice, sizeAfterColorChange, sizeStatesFor, stockMessageFor } from '@/lib/variants';

/**
 * Choose the options (colour and size in the template) and add to the cart without leaving the
 * list (a bottom sheet on phones). Opened by a product card when the product comes in more than
 * one option; a product with none has a single variant and goes straight into the cart
 * (lib/use-quick-add.ts).
 */
export function QuickAddDialog({ product, onClose }: { product: Product; onClose: () => void }) {
  const router = useRouter();
  const { addToCart } = useCart();
  const variants = useMemo(() => product.variants ?? [], [product]);
  const start = firstChoice(product);
  const [selectedColor, setSelectedColor] = useState<ProductColor | null>(start?.color ?? null);
  const [selectedSize, setSelectedSize] = useState<string | null>(start?.size ?? null);
  const [quantity, setQuantity] = useState(1);

  const selectedVariant = findVariant(variants, selectedColor, selectedSize);
  const stock = selectedVariant?.stock ?? 0;
  const canPurchase = !!selectedVariant && stock > 0;
  const price = selectedVariant?.price ?? product.price;
  const href = `/products/${product.slug}`;

  const add = () => {
    if (!selectedVariant || !canPurchase) return false;
    addToCart(product, selectedVariant, quantity); // shows the "Added to cart" notice
    onClose();
    return true;
  };

  // Rendered into <body>: product cards sit inside sections that clip fixed overlays
  // (content-visibility on the home page, hover transforms).
  return createPortal(
    <Dialog open onClose={onClose} title={`Add ${product.name} to cart`} panelClassName="w-full max-w-md">
      <div className="relative flex max-h-[90vh] supports-[height:100dvh]:max-h-[90dvh] flex-col overflow-hidden rounded-t-3xl bg-white shadow-2xl sm:rounded-3xl">
        {/* A fixed row with the product and the close button: only the options below it scroll. */}
        <div className="relative flex shrink-0 items-center gap-3 border-b border-neutral-100 py-3 pl-5 pr-12 sm:pl-6 sm:pr-14">
          <button
            type="button"
            onClick={onClose}
            data-close
            className="absolute right-3 top-1/2 -translate-y-1/2 rounded-full p-2 text-neutral-600 hover:bg-neutral-100 hover:text-black sm:right-4"
            aria-label="Close"
          >
            <HugeiconsIcon icon={Cancel01Icon} size={18} aria-hidden="true" />
          </button>
          <div className="relative h-14 w-14 shrink-0 overflow-hidden rounded-xl bg-card">
            {product.images[0] && <Image src={product.images[0]} alt="" fill sizes="56px" className="object-cover" />}
          </div>
          <div className="min-w-0">
            <p className="line-clamp-1 font-bold text-black">{product.name}</p>
            <p className="mt-0.5 flex items-baseline gap-2">
              <span className="text-base font-bold text-black">{formatPrice(price)}</span>
              {product.originalPrice && product.originalPrice > price && (
                <span className="text-sm font-semibold text-neutral-600 line-through">
                  <span className="sr-only">Was </span>
                  {formatPrice(product.originalPrice)}
                </span>
              )}
            </p>
          </div>
        </div>

        <div className="min-h-0 flex-1 overflow-y-auto p-5 sm:p-6">
        <VariantSelector
          colors={product.colors}
          sizes={product.sizes}
          selectedColor={selectedColor}
          onSelectColor={(color) => {
            setSelectedColor(color);
            setQuantity(1);
            setSelectedSize(sizeAfterColorChange(variants, color, selectedSize));
          }}
          selectedSize={selectedSize}
          onSelectSize={(size) => {
            setSelectedSize(size);
            setQuantity(1);
          }}
          sizeStates={sizeStatesFor(product.sizes, variants, selectedColor)}
          colorInStock={colorStockFor(variants)}
          quantity={quantity}
          maxQuantity={Math.max(1, Math.min(MAX_QUANTITY_PER_LINE, stock))}
          onQuantityChange={setQuantity}
          canPurchase={canPurchase}
          stockMessage={stockMessageFor(selectedVariant, variants.length)}
          onAddToCart={add}
          onBuyNow={() => {
            if (add()) router.push('/cart');
          }}
        />

        <Link href={href} onClick={onClose} className="mt-4 block text-center text-sm font-semibold text-neutral-700 underline hover:no-underline">
          View full details
        </Link>
        </div>
      </div>
    </Dialog>,
    document.body
  );
}
