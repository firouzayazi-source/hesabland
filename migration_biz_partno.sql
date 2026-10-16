-- ============================================================
-- migration_biz_partno.sql — پارت‌نامبرِ هر دستگاه (مهر ۱۴۰۵)
--
-- خواسته‌ی مالکِ نصب (بعد از مقایسه با «قیاس»): گزارش بر اساسِ پارت‌نامبر،
-- و شناختنِ گوشیِ بازسازی‌شده/تعویضی از روی آن.
--
-- ⛔ روی **ردیفِ سند**، کنارِ IMEI — نه روی کالا: پارت‌نامبر مالِ یک دستگاه است
--    (منطقه و نوعِ فروش)، و دو گوشیِ یک کالا (یک مدل و رنگ) می‌توانند دو
--    پارت‌نامبرِ متفاوت داشته باشند. همان دلیلِ «IMEI جدولِ جدا ندارد».
-- ایدمپوتنت (IF NOT EXISTS).
-- ============================================================

ALTER TABLE `biz_invoice_lines`
    ADD COLUMN IF NOT EXISTS `part_no` VARCHAR(30) NULL DEFAULT NULL COMMENT 'پارت‌نامبرِ دستگاه (BizPartNo)' AFTER `imei2`;
ALTER TABLE `biz_invoice_lines`
    ADD INDEX IF NOT EXISTS `idx_biz_lines_partno` (`user_id`, `part_no`);

SELECT 'migration_biz_partno با موفقیت اجرا شد.' AS result;
