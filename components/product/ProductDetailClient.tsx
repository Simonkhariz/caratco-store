'use client';

import { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useCart, MAX_QUANTITY_PER_LINE } from '@/context/CartContext';
import { useWishlist } from '@/context/WishlistContext';
import { ImageGallery } from '@/components/product/ImageGallery';
import { ShareProduct } from '@/components/product/ShareProduct';
import { VariantSelector } from '@/components/product/VariantSelector';
import {
  colorStockFor,
  findVariant,
  firstChoice,
  selectionLabelFor,
  sizeAfterColorChange,
  sizeStatesFor,
  soldOutColorName,
  stockMessageFor,
} from '@/lib/variants';
import { SHOP, option1HelpFor } from '@/lib/shop';
import { StickyMobileBar } from '@/components/product/StickyMobileBar';
import { ProductTabs } from '@/components/product/ProductTabs';
import { ProductCard } from '@/components/ui/ProductCard';
import { formatPrice } from '@/lib/currency';
import {
  ApiProductDetail,
  ApiError,
  errorMessage,
  getProductDetail,
  getProducts,
  mapApiProductToStorefront,
} from '@/lib/api-client';
import { Product, ProductColor, ProductVariant } from '@/types';
import { HugeiconsIcon } from '@hugeicons/react';
import {
  StarIcon,
  ArrowRight01Icon,
  Alert02Icon,
  RefreshIcon,
} from '@hugeicons/core-free-icons';

/**
 * Product page (client). The server page.tsx provides the metadata and the product itself
 * (`initial`), so the page arrives already filled in; stock is then refreshed in the
 * background, because the server copy can be up to a minute old.
 */
export function ProductDetailClient({ id, initial = null }: { id: string; initial?: ApiProductDetail | null }) {
  const router = useRouter();
  const initialProduct = useMemo(() => (initial ? mapApiProductToStorefront(initial) : null), [initial]);

  const [loading, setLoading] = useState(!initialProduct);
  const [error, setError] = useState<{ notFound?: boolean; message: string } | null>(null);
  const [product, setProduct] = useState<Product | null>(initialProduct);
  const [relatedProducts, setRelatedProducts] = useState<Product[]>([]);

  const [selectedColor, setSelectedColor] = useState<ProductColor | null>(firstChoice(initialProduct)?.color ?? null);
  const [selectedSize, setSelectedSize] = useState<string | null>(firstChoice(initialProduct)?.size ?? null);
  const [quantity, setQuantity] = useState(1);
  // The latest choice, for the quiet background refresh below.
  const selectedColorRef = useRef(selectedColor);
  const selectedSizeRef = useRef(selectedSize);
  useEffect(() => {
    selectedColorRef.current = selectedColor;
    selectedSizeRef.current = selectedSize;
  }, [selectedColor, selectedSize]);

  const { addToCart } = useCart();
  const { toggleWishlist, isInWishlist } = useWishlist();

  const loadRelated = useCallback(
    (mapped: Product) =>
      getProducts({ category: mapped.category, per_page: 5 }).then(
        (relatedRes) =>
          setRelatedProducts(
            relatedRes.items
              .filter((item) => String(item.id) !== mapped.id && item.slug !== mapped.slug)
              .slice(0, 4)
              .map(mapApiProductToStorefront)
          ),
        () => {
          // Non-blocking for related products
        }
      ),
    []
  );

  // Load (or, when the server already sent it, quietly refresh) the product from the API.
  // State changes only once the API has answered; "loading" is set by the caller.
  const loadProduct = useCallback(
    (background = false) =>
      getProductDetail(id)
        .then(
          (detail) => {
            const mapped = mapApiProductToStorefront(detail);
            setProduct(mapped);
            if (background) {
              // Keep the shopper's choice when it still exists.
              // A size that has sold out since is dropped (it is no longer shown).
              const mv = mapped.variants ?? [];
              const color = mv.find((v) => v.color.name === selectedColorRef.current?.name)?.color ?? firstChoice(mapped)?.color ?? null;
              setSelectedColor(color);
              setSelectedSize(color ? sizeAfterColorChange(mv, color, selectedSizeRef.current) : null);
            } else {
              setQuantity(1);
              setSelectedColor(firstChoice(mapped)?.color ?? null);
              setSelectedSize(firstChoice(mapped)?.size ?? null);
              void loadRelated(mapped);
            }
          },
          (err) => {
            if (background) return; // The server copy is still on screen.
            const is404 = err instanceof ApiError && (err.statusCode === 404 || err.errorCode === 'RESOURCE_NOT_FOUND');
            setError({
              notFound: is404,
              message: is404 ? `Product "${id}" was not found or is no longer available.` : errorMessage(err, 'We could not load this product.'),
            });
          }
        )
        .finally(() => {
          if (!background) setLoading(false);
        }),
    [id, loadRelated]
  );

  // "Try again" after a failed load.
  const retryLoad = () => {
    setLoading(true);
    setError(null);
    void loadProduct();
  };

  useEffect(() => {
    if (initialProduct) {
      void loadRelated(initialProduct);
      void loadProduct(true);
    } else {
      void loadProduct(); // `loading` starts as true when there is no server copy
    }
  }, [initialProduct, loadProduct, loadRelated]);

  useEffect(() => {
    router.prefetch('/cart');
  }, [router]);

  const variants: ProductVariant[] = useMemo(() => product?.variants ?? [], [product]);

  const selectedVariant = useMemo(() => findVariant(variants, selectedColor, selectedSize), [variants, selectedColor, selectedSize]);
  // Which sizes exist (and are in stock) for the selected colour.
  const sizeStates = useMemo(() => sizeStatesFor(product?.sizes ?? [], variants, selectedColor), [product, variants, selectedColor]);
  const colorInStock = useMemo(() => colorStockFor(variants), [variants]);

  const handleSelectColor = (color: ProductColor) => {
    setSelectedColor(color);
    setQuantity(1);
    setSelectedSize(sizeAfterColorChange(variants, color, selectedSize));
  };

  const handleSelectSize = (size: string) => {
    setSelectedSize(size);
    setQuantity(1);
  };

  const stock = selectedVariant?.stock ?? 0;
  const maxQuantity = Math.max(1, Math.min(MAX_QUANTITY_PER_LINE, stock));
  const canPurchase = !!selectedVariant && stock > 0;
  const displayPrice = selectedVariant?.price ?? product?.price ?? 0;
  const stockMessage = stockMessageFor(selectedVariant, variants.length, soldOutColorName(variants, selectedColor));

  const handleAddToCart = () => {
    if (!product || !selectedVariant || !canPurchase) return;
    addToCart(product, selectedVariant, quantity);
  };

  const handleBuyNow = () => {
    if (!product || !selectedVariant || !canPurchase) return;
    addToCart(product, selectedVariant, quantity);
    router.push('/cart');
  };

  const isWishlisted = product ? isInWishlist(product.id) : false;
  const handleToggleWishlist = () => {
    if (product) toggleWishlist(product);
  };

  // 1. Loading State Skeleton
  if (loading) {
    return (
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 animate-pulse">
        <div className="h-4 bg-neutral-200 rounded w-48 mb-8" />
        <div className="grid grid-cols-1 md:grid-cols-12 gap-8 lg:gap-12">
          <div className="md:col-span-6 aspect-square bg-neutral-200 rounded-2xl" />
          <div className="md:col-span-6 space-y-4">
            <div className="h-8 bg-neutral-200 rounded w-3/4" />
            <div className="h-5 bg-neutral-200 rounded w-1/4" />
            <div className="h-8 bg-neutral-200 rounded w-1/3" />
            <div className="h-20 bg-neutral-200 rounded w-full" />
            <div className="h-12 bg-neutral-200 rounded-full w-full" />
          </div>
        </div>
      </div>
    );
  }

  // 2. Missing Product / 404 State
  if (error?.notFound) {
    return (
      <div className="max-w-3xl mx-auto px-4 py-20 text-center">
        <div className="w-16 h-16 bg-neutral-100 text-neutral-500 rounded-full flex items-center justify-center mx-auto mb-4" aria-hidden="true">
          <HugeiconsIcon icon={Alert02Icon} size={32} />
        </div>
        <h1 className="font-integral text-2xl sm:text-3xl text-black mb-3">PRODUCT NOT FOUND</h1>
        <p className="text-neutral-600 text-sm sm:text-base max-w-md mx-auto mb-8">
          The item you are looking for does not exist, has been removed, or is currently unpublished.
        </p>
        <Link
          href="/category"
          className="inline-flex items-center gap-2 bg-accent text-accent-ink hover:bg-accent-hover font-semibold px-8 py-3.5 rounded-full text-sm transition-all shadow-md"
        >
          <span>Explore All Products</span>
          <HugeiconsIcon icon={ArrowRight01Icon} size={16} />
        </Link>
      </div>
    );
  }

  // 3. Connection / API Error State (Never silently fall back to fake prices)
  if (error) {
    return (
      <div className="max-w-3xl mx-auto px-4 py-20 text-center">
        <div className="w-16 h-16 bg-red-100 text-red-600 rounded-full flex items-center justify-center mx-auto mb-4" aria-hidden="true">
          <HugeiconsIcon icon={Alert02Icon} size={32} />
        </div>
        <h1 className="font-integral text-2xl sm:text-3xl text-red-900 mb-3">CANNOT LOAD PRODUCT</h1>
        <p className="text-red-700 text-sm sm:text-base max-w-md mx-auto mb-8">
          {error.message} Please check your connection and try again.
        </p>
        <button
          onClick={retryLoad}
          className="inline-flex items-center gap-2 bg-red-600 hover:bg-red-700 text-white font-semibold px-8 py-3.5 rounded-full text-sm transition-all shadow-md"
        >
          <HugeiconsIcon icon={RefreshIcon} size={16} />
          <span>Try again</span>
        </button>
      </div>
    );
  }

  if (!product) return null;

  const inStock = variants.some((v) => v.stock > 0);

  return (
    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8 pb-24 md:pb-12">
      {/* Breadcrumbs */}
      <nav aria-label="Breadcrumb" className="flex items-center gap-2 text-xs sm:text-sm text-neutral-600 mb-8 overflow-x-auto no-scrollbar whitespace-nowrap">
        <Link href="/" className="hover:text-black transition-colors">
          Home
        </Link>
        <HugeiconsIcon icon={ArrowRight01Icon} size={14} className="text-neutral-500 shrink-0" aria-hidden="true" />
        <Link href="/category" className="hover:text-black transition-colors">
          Shop
        </Link>
        <HugeiconsIcon icon={ArrowRight01Icon} size={14} className="text-neutral-500 shrink-0" aria-hidden="true" />
        {product.category && (
          <>
            <Link
              href={`/category?cat=${encodeURIComponent(String(product.category))}`}
              className="hover:text-black transition-colors capitalize"
            >
              {product.categoryName || product.category}
            </Link>
            <HugeiconsIcon icon={ArrowRight01Icon} size={14} className="text-neutral-500 shrink-0" aria-hidden="true" />
          </>
        )}
        <span className="text-black font-medium truncate" aria-current="page">{product.name}</span>
      </nav>

      {/* Main Grid: Gallery on Left + Info & Buying Actions on Right */}
      <div className="grid grid-cols-1 md:grid-cols-12 gap-8 lg:gap-12 items-start">
        {/* Left Gallery (6 cols on tablets and desktop) */}
        <div className="md:col-span-6 w-full min-w-0">
          <ImageGallery
            images={product.images}
            productName={product.name}
          />
        </div>

        {/* Right Info & Buying Actions (6 cols on tablets and desktop) */}
        <div className="md:col-span-6 min-w-0 space-y-6">
          <div>
            {/* Stock status */}
            <div className="flex items-center gap-2 mb-2 flex-wrap">
              {!inStock && (
                <span className="inline-flex items-center text-xs font-semibold px-2.5 py-0.5 rounded-full bg-neutral-100 text-neutral-600">
                  Out of Stock
                </span>
              )}
            </div>

            <div className="flex items-start justify-between gap-3">
              <h1 className="min-w-0 font-integral text-2xl sm:text-4xl text-black leading-tight uppercase font-black">
                {product.name}
              </h1>
              <ShareProduct name={product.name} slug={product.slug} price={displayPrice} />
            </div>

            {/* Star rating: the starting rating blended with approved reviews; the count is real reviews only. */}
            {product.rating > 0 ? (
              <div
                className="flex items-center gap-2 mt-3"
                aria-label={`Rated ${product.rating.toFixed(1)} out of 5${product.reviewCount > 0 ? ` from ${product.reviewCount} ${product.reviewCount === 1 ? 'review' : 'reviews'}` : ''}`}
              >
                <div className="flex items-center text-[#FFC633]" aria-hidden="true">
                  {Array.from({ length: 5 }).map((_, i) => (
                    <span key={i} className={i < Math.round(product.rating) ? 'text-[#FFC633]' : 'text-neutral-300'}>
                      <HugeiconsIcon
                        icon={StarIcon}
                        size={16}
                        className={i < Math.round(product.rating) ? 'fill-current' : ''}
                      />
                    </span>
                  ))}
                </div>
                <span className="text-sm font-medium text-black">
                  {product.rating.toFixed(1)}/<span className="text-neutral-500">5</span>
                </span>
                {product.reviewCount > 0 && (
                  <span className="text-xs text-neutral-500">
                    ({product.reviewCount} {product.reviewCount === 1 ? 'review' : 'reviews'})
                  </span>
                )}
              </div>
            ) : (
              <p className="mt-3 text-xs text-neutral-500">No reviews yet</p>
            )}

            {/* Price Row (NGN) */}
            <div className="flex items-center gap-3 mt-3">
              <span className="font-bold text-2xl sm:text-3xl text-black">
                {formatPrice(displayPrice)}
              </span>
              {product.originalPrice && product.originalPrice > displayPrice && (
                <>
                  <span className="font-bold text-2xl sm:text-3xl text-neutral-500 line-through">
                    <span className="sr-only">Was </span>
                    {formatPrice(product.originalPrice)}
                  </span>
                  {/* Worked out from the price shown, so it follows the chosen option's own price. */}
                  {Math.round((1 - displayPrice / product.originalPrice) * 100) > 0 && (
                    <span className="bg-[#FF3333]/10 text-[#FF3333] text-sm font-bold px-3 py-1 rounded-full">
                      -{Math.round((1 - displayPrice / product.originalPrice) * 100)}%
                    </span>
                  )}
                </>
              )}
            </div>

            {/* What helps a buyer decide, beside the price: the warranty, and who may buy. */}
            {product.warranty && (
              <p className="mt-2 text-sm text-neutral-700">
                <span className="font-semibold text-black">Warranty:</span> {product.warranty}
              </p>
            )}
            {product.ageRestricted && (
              <p className="mt-2 text-xs text-neutral-600">For buyers aged {SHOP.ageCheck.minAge} and over.</p>
            )}

            {/* Description */}
            <p className="text-neutral-600 text-sm sm:text-base leading-relaxed mt-4 pb-6 border-b border-neutral-200">
              {product.description}
            </p>
          </div>

          {/* Modular Variant Selector Component */}
          <VariantSelector
            colors={product.colors}
            sizes={product.sizes}
            selectedColor={selectedColor}
            onSelectColor={handleSelectColor}
            selectedSize={selectedSize}
            onSelectSize={handleSelectSize}
            sizeStates={sizeStates}
            colorInStock={colorInStock}
            quantity={quantity}
            maxQuantity={maxQuantity}
            onQuantityChange={setQuantity}
            canPurchase={canPurchase}
            stockMessage={stockMessage}
            option1Help={option1HelpFor(product.category)}
            onAddToCart={handleAddToCart}
            onBuyNow={handleBuyNow}
            isWishlisted={isWishlisted}
            onToggleWishlist={handleToggleWishlist}
          />
        </div>
      </div>

      {/* Tabs: details, approved reviews (with the review form) and FAQs */}
      <ProductTabs product={product} />

      {/* You Might Also Like Section */}
      {relatedProducts.length > 0 && (
        <section className="mt-20 pt-10 border-t border-neutral-200">
          <h2 className="font-integral text-2xl sm:text-4xl text-center text-black mb-10">
            YOU MIGHT ALSO LIKE
          </h2>
          <div className="grid grid-cols-2 md:grid-cols-4 gap-4 sm:gap-6">
            {relatedProducts.map((p) => (
              <ProductCard key={p.id} product={p} />
            ))}
          </div>
        </section>
      )}

      {/* Sticky Mobile Purchase Bar */}
      <StickyMobileBar
        product={product}
        price={displayPrice}
        selectionLabel={selectionLabelFor(selectedVariant, product)}
        canPurchase={canPurchase}
        onAddToCart={handleAddToCart}
        onBuyNow={handleBuyNow}
      />
    </div>
  );
}
