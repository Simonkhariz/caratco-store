// Catalogue URL helpers shared by the server-rendered category page and the browser.
// Run with: npm run test:web   (Node 23.6+ runs the TypeScript module directly)
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  buildCatalogUrl,
  catalogApiParams,
  catalogShowingLabel,
  parseCatalogParams,
  priceFilterFrom,
  productListFrom,
  productListPath,
  urlParamsFrom,
} from '../lib/catalog-query.ts';

const apiPathFor = (query) => productListPath(catalogApiParams(parseCatalogParams(new URLSearchParams(query))));

test('a plain catalogue page asks for the first 12 best sellers', () => {
  assert.equal(apiPathFor(''), '/products?sort=best_selling&page=1&per_page=12');
});

test('every filter reaches the API request', () => {
  assert.equal(
    apiPathFor('cat=hoodies&style=casual&q=%20fleece%20&color=navy,black&size=M&min_price=5000&max_price=20000&sort=price-low-high&onSale=true&flash=true&page=3'),
    '/products?category=hoodies&search=fleece&min_price=5000&max_price=20000&dress_style=casual&colors=navy%2Cblack&sizes=M&on_sale=1&flash_deals=1&sort=price_asc&page=3&per_page=12'
  );
});

test('the page key is the same for old and new parameter names', () => {
  // The server and the browser compare this key to decide whether the server's results apply.
  const legacy = buildCatalogUrl(parseCatalogParams(new URLSearchParams('category=jeans&colors=blue&newArrivals=true')));
  const current = buildCatalogUrl(parseCatalogParams(new URLSearchParams('cat=jeans&color=blue&sort=newest')));
  assert.equal(legacy, current);
  assert.equal(current, '/category?cat=jeans&color=blue&sort=newest');
});

test('the server reads searchParams the way the browser reads the address', () => {
  const raw = { cat: 'shirts', size: ['L', 'XL'], page: '2', empty: undefined };
  const fromServer = buildCatalogUrl(parseCatalogParams(urlParamsFrom(raw)));
  const fromBrowser = buildCatalogUrl(parseCatalogParams(new URLSearchParams('cat=shirts&size=L&size=XL&page=2')));
  assert.equal(fromServer, fromBrowser);
});

test('a product list answer becomes items plus pagination', () => {
  const items = [{ id: 1 }, { id: 2 }];
  assert.deepEqual(productListFrom(items, { total: 30, page: 2, per_page: 12, total_pages: 3 }).pagination, {
    total: 30,
    page: 2,
    per_page: 12,
    total_pages: 3,
  });
  assert.deepEqual(productListFrom(items, null), { items, pagination: { total: 2, page: 1, per_page: 12, total_pages: 1 } });
  assert.deepEqual(productListFrom('not a list', null).items, []);
});

test('sale=true is another name for onSale=true', () => {
  assert.equal(parseCatalogParams(new URLSearchParams('sale=true')).onSale, true);
  assert.equal(parseCatalogParams(new URLSearchParams('sale=false')).onSale, false);
  assert.equal(buildCatalogUrl(parseCatalogParams(new URLSearchParams('sale=true'))), '/category?onSale=true');
});

test('the catalogue count reads right for one product and for ranges', () => {
  assert.equal(catalogShowingLabel(1, 1, 1), 'Showing 1 of 1 product');
  assert.equal(catalogShowingLabel(1, 12, 43), 'Showing 1–12 of 43 products');
  assert.equal(catalogShowingLabel(4, 7, 43), 'Showing 37–43 of 43 products');
  assert.equal(catalogShowingLabel(2, 1, 13), 'Showing 13 of 13 products');
});

test('the price filter changes only when the boxes say something new', () => {
  const none = { minPrice: null, maxPrice: null };
  assert.equal(priceFilterFrom('', '', none), null, 'leaving empty boxes keeps the page');
  assert.equal(priceFilterFrom('1000', '5000', { minPrice: 1000, maxPrice: 5000 }), null, 'leaving unchanged boxes keeps the page');
  assert.deepEqual(priceFilterFrom('1000', '', none), { minPrice: 1000, maxPrice: null });
  assert.deepEqual(priceFilterFrom('', '', { minPrice: 1000, maxPrice: null }), none);
  assert.equal(priceFilterFrom('abc', '', none), null);
});

test('a minimum above the maximum is swapped, and -5 counts as 0', () => {
  assert.deepEqual(priceFilterFrom('5000', '1000', { minPrice: null, maxPrice: null }), { minPrice: 1000, maxPrice: 5000 });
  assert.equal(priceFilterFrom('5000', '1000', { minPrice: 1000, maxPrice: 5000 }), null);
  assert.deepEqual(priceFilterFrom('-5', '', { minPrice: null, maxPrice: null }), { minPrice: 0, maxPrice: null });
});
