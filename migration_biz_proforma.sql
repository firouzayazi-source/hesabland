-- ============================================================
-- migration_biz_proforma.sql — پیش‌فاکتور
--
-- پیش‌فاکتور یک **پیش‌نویسِ فروش** است با شماره‌ی خودش و تاریخِ اعتبار: نه موجودی را
-- تکان می‌دهد نه مانده‌ی مشتری را (پیش‌نویس است). «تبدیل به فاکتور» همان «صدور»ِ همین
-- سند است؛ شماره‌ی پیش‌فاکتور روی فاکتورِ صادرشده می‌ماند («از پیش‌فاکتورِ ۱۲»).
--
-- ایمن و قابل اجرای دوباره.
-- ============================================================

ALTER TABLE `biz_invoices`
    ADD COLUMN IF NOT EXISTS `proforma_no`    INT UNSIGNED NULL DEFAULT NULL COMMENT 'شماره‌ی پیش‌فاکتور (BizInvoices::markProforma)',
    ADD COLUMN IF NOT EXISTS `proforma_until` DATE NULL DEFAULT NULL COMMENT 'اعتبارِ پیش‌فاکتور تا';

ALTER TABLE `biz_invoices`
    ADD INDEX IF NOT EXISTS `idx_biz_inv_proforma` (`user_id`, `proforma_no`);

SELECT 'migration_biz_proforma با موفقیت اجرا شد.' AS result;
