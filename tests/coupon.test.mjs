// Cart preview of coupon rules (lib/coupon.ts); must match backend/src/Services/CouponRules.php.
// Run with: npm run test:web
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { couponDiscount, couponRuleFrom, couponShortfall } from '../lib/coupon.ts';

test('percentage, with and without a cap', () => {
  const pct = couponRuleFrom({ discount_type: 'percent', discount_percent: 10 });
  assert.equal(couponDiscount(pct, 50000), 5000);
  const capped = couponRuleFrom({ discount_type: 'percent', discount_percent: 10, max_discount_amount: 1000 });
  assert.equal(couponDiscount(capped, 50000), 1000);
  assert.equal(couponDiscount(capped, 5000), 500);
});

test('fixed amount, minimum order and never more than the items', () => {
  const fixed = couponRuleFrom({ discount_type: 'fixed', discount_value: 5000, min_order_amount: 20000 });
  assert.equal(couponDiscount(fixed, 19999), 0);
  assert.equal(couponShortfall(fixed, 15000), 5000);
  assert.equal(couponDiscount(fixed, 20000), 5000);
  assert.equal(couponShortfall(fixed, 25000), 0);
  const big = couponRuleFrom({ discount_type: 'fixed', discount_value: 5000 });
  assert.equal(couponDiscount(big, 3000), 3000);
  assert.equal(couponDiscount(big, 0), 0);
});

test('a cap never applies to a fixed code, and odd values count as none', () => {
  const rule = couponRuleFrom({ discount_type: 'fixed', discount_value: 4000, max_discount_amount: 100, min_order_amount: '' });
  assert.equal(rule.maxDiscountAmount, null);
  assert.equal(rule.minOrderAmount, null);
  assert.equal(couponDiscount(rule, 10000), 4000);
  assert.equal(couponRuleFrom({}).discountType, 'percent');
});
