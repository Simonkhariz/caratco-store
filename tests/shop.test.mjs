// The shop's settings and the helpers that keep an unused option out of sight (lib/shop.ts).
// Every check reads the words from backend/config/shop.json, so it holds for any kind of shop.
// Run with: npm run test:web
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  SHOP,
  hasOption1,
  hasOption2,
  mainOption,
  nameWithOptions,
  option1HelpFor,
  option1Word,
  option2Word,
  optionsText,
  shopFilters,
  styleHeading,
  styleHeadingParts,
  styleName,
  styleWord,
  withArticle,
} from '../lib/shop.ts';

const NONE1 = SHOP.option1.none;
const NONE2 = SHOP.option2.none;

test('an option is used only when it holds a real value', () => {
  assert.equal(hasOption1('M'), true);
  assert.equal(hasOption2('Navy'), true);
  for (const empty of [null, undefined, '', '   ']) {
    assert.equal(hasOption1(empty), false);
    assert.equal(hasOption2(empty), false);
  }
});

test('the "none" value is never a choice, however it is typed', () => {
  for (const none of [NONE1, NONE1.toUpperCase(), NONE1.toLowerCase(), `  ${NONE1} `]) assert.equal(hasOption1(none), false, none);
  for (const none of [NONE2, NONE2.toUpperCase(), NONE2.toLowerCase(), `  ${NONE2} `]) assert.equal(hasOption2(none), false, none);
  // Each option has its own "none" word: the other option's word is an ordinary value.
  if (NONE1.toLowerCase() !== NONE2.toLowerCase()) {
    assert.equal(hasOption1(NONE2), true);
    assert.equal(hasOption2(NONE1), true);
  }
});

test('the options of a line leave out whatever the product does not use', () => {
  assert.equal(optionsText('M', 'Navy'), 'M / Navy');
  assert.equal(optionsText(' M ', ' Navy '), 'M / Navy');
  assert.equal(optionsText('M', 'Navy', ' · '), 'M · Navy');
  assert.equal(optionsText('50ml', NONE2), '50ml');
  assert.equal(optionsText(NONE1, 'Vanilla'), 'Vanilla');
  assert.equal(optionsText(NONE1, NONE2), '');
  assert.equal(optionsText(null, undefined), '');
  assert.equal(optionsText('M', null, ' · '), 'M', 'no stray separator');
});

test('the one option that names a line: option 1, else option 2, else nothing', () => {
  assert.equal(mainOption('M', 'Navy'), 'M');
  assert.equal(mainOption(NONE1, 'Vanilla'), 'Vanilla');
  assert.equal(mainOption(undefined, ' Vanilla '), 'Vanilla');
  assert.equal(mainOption(NONE1, NONE2), '');
  assert.equal(mainOption(null, null), '');
});

test('a name gets its options in brackets, and no empty brackets without any', () => {
  assert.equal(nameWithOptions('Classic Tee', optionsText('M', 'Navy')), 'Classic Tee (M / Navy)');
  assert.equal(nameWithOptions('Gift Card', optionsText(NONE1, NONE2)), 'Gift Card');
  assert.equal(nameWithOptions('Gift Card', ''), 'Gift Card');
});

test('catalogue filters never hold a "none" value, or a style when the style filter is off', () => {
  const filters = { category: 'tops', dressStyle: 'casual', selectedSizes: ['M', NONE1, NONE1.toLowerCase()], selectedColors: [NONE2, 'Navy'], page: 2 };
  const cleaned = shopFilters(filters);
  assert.deepEqual(cleaned.selectedSizes, ['M']);
  assert.deepEqual(cleaned.selectedColors, ['Navy']);
  assert.equal(cleaned.dressStyle, SHOP.style.enabled ? 'casual' : null);
  assert.equal(cleaned.category, 'tops');
  assert.equal(cleaned.page, 2);
  assert.deepEqual(filters.selectedSizes.length, 3, 'the filters passed in are not changed');
});

test('the words for the middle of a sentence follow the labels', () => {
  assert.equal(option1Word, SHOP.option1.label.toLowerCase());
  assert.equal(option2Word, SHOP.option2.label.toLowerCase());
  assert.equal(styleWord, SHOP.style.short.toLowerCase());
  assert.equal(withArticle('size'), 'a size');
  assert.equal(withArticle('option'), 'an option');
});

test('the tip under the option-1 picker is the category\'s own, else the general one, else nothing', () => {
  const jewellery = { help: 'General tip', helpByCategory: { rings: 'Ring size tip', necklaces: '' } };
  assert.equal(option1HelpFor('rings', jewellery), 'Ring size tip');
  assert.equal(option1HelpFor(' Rings ', jewellery), 'Ring size tip', 'the slug is matched whatever its case');
  assert.equal(option1HelpFor('watches', jewellery), 'General tip');
  assert.equal(option1HelpFor('', jewellery), 'General tip');
  assert.equal(option1HelpFor(null, jewellery), 'General tip');
  // A category given an empty text shows no tip at all.
  assert.equal(option1HelpFor('necklaces', jewellery), '');
  assert.equal(option1HelpFor('rings', { help: '', helpByCategory: {} }), '');
  assert.equal(option1HelpFor('watches', { help: '', helpByCategory: { rings: 'Ring size tip' } }), '');
  // This shop's own settings.
  assert.equal(option1HelpFor('a-category-no-shop-has'), SHOP.option1.help);
  for (const [slug, text] of Object.entries(SHOP.option1.helpByCategory)) assert.equal(option1HelpFor(slug.toUpperCase()), text);
});

test('a style is shown as the shop wrote it; only a link carries the lower-case form', () => {
  assert.equal(styleName('lg', ['Voltix', 'LG']), 'LG');
  assert.equal(styleName('LG', ['Voltix', 'LG']), 'LG');
  assert.equal(styleName('eau de parfum', ['Eau de Parfum']), 'Eau de Parfum');
  assert.equal(styleName(' voltix ', ['Voltix', 'LG']), 'Voltix');
  // The shop's own list is the second place to look, and an unknown style is shown as it came.
  for (const name of SHOP.style.values) assert.equal(styleName(name.toLowerCase()).toLowerCase(), name.trim().toLowerCase());
  for (const name of SHOP.style.values) assert.ok(SHOP.style.values.map((v) => v.trim()).includes(styleName(name.toUpperCase())));
  assert.equal(styleName('no-such-style-xyz'), 'no-such-style-xyz');
  assert.equal(styleName(null), '');
});

test('the catalogue heading for a style follows the shop\'s pattern', () => {
  assert.ok(SHOP.style.heading.includes('{value}'), 'a shop file without the pattern shows the value alone');
  const [before, after] = styleHeadingParts();
  assert.equal(styleHeading('Casual'), `${before}Casual${after}`);
  assert.ok(SHOP.style.heading.startsWith(`${before}{value}`), 'the name goes where the pattern has {value}');
  assert.ok(styleHeading('LG').includes('LG'), 'the name keeps its spelling');
  assert.ok(!styleHeading('LG').includes('{value}'));
});

test('the settings every screen reads are there', () => {
  // Keys with a fallback (a shop file may leave them out).
  assert.ok(SHOP.style.short, 'style.short falls back to style.label');
  assert.equal(typeof SHOP.option1.helpByCategory, 'object');
  assert.equal(typeof SHOP.wording.searchExampleShort, 'string');
  assert.equal(typeof SHOP.wording.searchExampleLong, 'string');
  for (const text of [SHOP.option1.label, SHOP.option1.plural, NONE1, SHOP.option2.label, SHOP.option2.plural, NONE2, SHOP.specs.label]) {
    assert.ok(typeof text === 'string' && text.trim() !== '');
  }
  assert.equal(typeof SHOP.option2.swatch, 'boolean');
  assert.equal(typeof SHOP.style.enabled, 'boolean');
  assert.ok(Number.isInteger(SHOP.ageCheck.minAge) && SHOP.ageCheck.minAge > 0);
  for (const src of Object.values(SHOP.style.images)) assert.ok(typeof src === 'string' && src !== '');
});
