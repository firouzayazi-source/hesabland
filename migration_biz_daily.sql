-- ============================================================
-- migration_biz_daily.sql — خلاصه‌ی شبانه برای صاحبِ فروشگاه (مهر ۱۴۰۵)
--
-- خواسته‌ی مالکِ نصب: صاحبِ فروشگاه هر شب بی‌آنکه برنامه را باز کند بداند
-- امروز چه فروخت، چه سودی کرد، چقدر نقد دارد و فردا کدام چک سررسید است.
--
-- ⛔ هیچ عددی ذخیره نمی‌شود — متن هر شب از همان تابع‌های داشبورد ساخته
--    می‌شود (`BizDaily::collect()`). تنها چیزهای ذخیره‌شده: روشن/خاموش، و
--    «امروز فرستاده شد» تا cronِ ساعتی دو بار نفرستد.
-- ایدمپوتنت (IF NOT EXISTS).
-- ============================================================

ALTER TABLE `biz_settings`
    ADD COLUMN IF NOT EXISTS `daily_summary` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'خلاصه‌ی شبانه روشن است',
    ADD COLUMN IF NOT EXISTS `daily_sent_on` DATE NULL DEFAULT NULL COMMENT 'فقط BizDaily::claim()';

SELECT 'migration_biz_daily با موفقیت اجرا شد.' AS result;
