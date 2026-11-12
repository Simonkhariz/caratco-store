// Which pictures next/image may resize (lib/image.ts). Run with: npm run test:web
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { canOptimizeImage } from '../lib/image.ts';

test("the shop's own pictures are resized", () => {
  assert.equal(canOptimizeImage('/uploads/prod_20261022_102734_27f8e528.webp'), true);
  assert.equal(canOptimizeImage('/uploads/catalog/product-faded-vintage-jeans-1.webp'), true);
  assert.equal(canOptimizeImage('/images/categories/jeans.webp'), true);
});

test('pictures from other sites are shown as they are, never fetched by the optimiser', () => {
  assert.equal(canOptimizeImage('https://images.unsplash.com/photo-1583743814966-8936f5b7be1a?w=600'), false);
  assert.equal(canOptimizeImage('http://example.com/a.jpg'), false);
  assert.equal(canOptimizeImage('//evil.example/a.jpg'), false);
});

test('SVG and empty values are never resized', () => {
  assert.equal(canOptimizeImage('/images/logo.svg'), false);
  assert.equal(canOptimizeImage(''), false);
  assert.equal(canOptimizeImage(null), false);
});
