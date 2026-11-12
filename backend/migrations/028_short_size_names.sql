-- -----------------------------------------------------------------------------
-- Migration 028: One name per size
--
-- The catalogue uses short size names (S, M, L, XL) but the product editor offered long ones
-- (Small, Medium, Large, X-Large), so the shop's size filter listed the same size twice
-- ("M" and "Medium"). The editor now offers the short names; this renames options that were saved
-- with a long one.
--
-- An option is only renamed when the product does not already have the short one in that colour
-- (size + colour must stay unique per product), so nothing is merged or lost. Stock, prices and
-- past orders are untouched: orders keep their own copy of the size they were placed with.
-- Safe to run again (a second run finds nothing left to rename).
-- -----------------------------------------------------------------------------

UPDATE `product_variants` v
LEFT JOIN `product_variants` taken
       ON taken.`product_id` = v.`product_id` AND taken.`color_name` = v.`color_name` AND taken.`id` <> v.`id`
      AND taken.`size` = CASE LOWER(v.`size`)
            WHEN 'xx-small' THEN 'XXS' WHEN 'x-small' THEN 'XS' WHEN 'small' THEN 'S' WHEN 'medium' THEN 'M'
            WHEN 'large' THEN 'L' WHEN 'x-large' THEN 'XL' WHEN 'xx-large' THEN 'XXL' WHEN '3x-large' THEN '3XL' END
SET v.`size` = CASE LOWER(v.`size`)
            WHEN 'xx-small' THEN 'XXS' WHEN 'x-small' THEN 'XS' WHEN 'small' THEN 'S' WHEN 'medium' THEN 'M'
            WHEN 'large' THEN 'L' WHEN 'x-large' THEN 'XL' WHEN 'xx-large' THEN 'XXL' WHEN '3x-large' THEN '3XL' END
WHERE LOWER(v.`size`) IN ('xx-small', 'x-small', 'small', 'medium', 'large', 'x-large', 'xx-large', '3x-large')
  AND taken.`id` IS NULL;
