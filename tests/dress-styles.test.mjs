// The home page's style tiles (lib/dress-styles.ts): how wide each one is. Run with: npm run test:web
import { test } from 'node:test';
import assert from 'node:assert/strict';
import './helpers/alias-hooks.mjs';

const { DRESS_STYLE_IMAGES, styleTileSpan } = await import('../lib/dress-styles.ts');

const spans = (count) => Array.from({ length: count }, (_, index) => styleTileSpan(index, count));
/** The tiles as rows of 12 columns, the way the grid wraps them. */
function rows(count) {
  const out = [[]];
  for (const span of spans(count)) {
    const last = out[out.length - 1];
    if (last.reduce((sum, s) => sum + s, 0) + span > 12) out.push([span]);
    else last.push(span);
  }
  return out;
}

test('tiles go two to a row, narrow beside wide', () => {
  assert.deepEqual(spans(2), [4, 8]);
  assert.deepEqual(spans(4), [4, 8, 8, 4]);
  assert.deepEqual(spans(6), [4, 8, 8, 4, 4, 8]);
});

test('a last tile on its own takes the whole row', () => {
  assert.deepEqual(spans(1), [12]);
  assert.deepEqual(spans(3), [4, 8, 12]);
  assert.deepEqual(spans(5), [4, 8, 8, 4, 12]);
  assert.deepEqual(spans(7), [4, 8, 8, 4, 4, 8, 12]);
});

test('every row is full, whatever the number of tiles', () => {
  for (let count = 1; count <= 12; count += 1) {
    for (const row of rows(count)) assert.equal(row.reduce((sum, s) => sum + s, 0), 12, `${count} tiles: ${JSON.stringify(rows(count))}`);
  }
  assert.deepEqual(spans(0), []);
});

test('tile pictures are found by the style name in lower case', () => {
  for (const name of Object.keys(DRESS_STYLE_IMAGES)) assert.equal(name, name.trim().toLowerCase());
});
