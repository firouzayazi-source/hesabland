-- ============================================================
-- migration_biz_pricing.sql — سطحِ قیمت و سقفِ اعتبارِ مشتری
--
--   biz_products.price_wholesale / price_colleague   قیمتِ عمده و همکار (خالی = همان قیمتِ فروش)
--   biz_parties.price_level    سطحِ قیمتِ هر مشتری (`BizProducts::LEVELS`)
--   biz_parties.credit_limit   سقفِ اعتبار (تومان؛ ۰ = بی‌سقف) — صدورِ فروشِ نسیه که مانده را
--                              از آن بالاتر ببرد رد می‌شود مگر با اجازه‌ی صریح (`BizParties::creditError()`)
--
-- پورسانت ستونِ تازه نمی‌خواهد: درصدش روی همکار (`store_staff.commission_pct`) و فروش از
-- `biz_invoices.created_by` (migration_biz_staff).
--
-- ایمن و قابل اجرای دوباره.
-- ============================================================

ALTER TABLE `biz_products`
    ADD COLUMN IF NOT EXISTS `price_wholesale` BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'قیمتِ عمده',
    ADD COLUMN IF NOT EXISTS `price_colleague` BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'قیمتِ همکار';

ALTER TABLE `biz_parties`
    ADD COLUMN IF NOT EXISTS `price_level`  VARCHAR(12) NOT NULL DEFAULT 'retail' COMMENT 'BizProducts::LEVELS',
    ADD COLUMN IF NOT EXISTS `credit_limit` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'سقفِ اعتبار؛ ۰ = بی‌سقف';

SELECT 'migration_biz_pricing با موفقیت اجرا شد.' AS result;
