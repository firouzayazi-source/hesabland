-- ============================================================
-- migration_biz_staff.sql — چند کاربر برای هر فروشگاه
--
-- **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵):** «برای بخشِ حسابداریِ فروشگاه که قراره کشوری
-- بسه … همه رو بساز.» اولین کمبود: هر فروشگاه فقط یک ورود داشت؛ فروشگاهی با دو
-- فروشنده یا رمزش را می‌داد یا نمی‌توانست استفاده کند.
--
--   store_staff    هر همکارِ فروشگاه: `user_id` = صاحبِ فروشگاه (مالکِ داده، مثلِ همه‌ی
--                  جدول‌های biz_)، `staff_id` = حسابِ ورودِ خودِ همکار (یکتا)، نقش و درصدِ
--                  پورسانت. داده‌ی فروشگاه همیشه زیرِ `user_id`ِ صاحب می‌ماند (`Biz::ownerId()`).
--   created_by     چه کسی سند/دریافت را ثبت کرد (پورسانت و سرگذشت).
--
-- ⚠ نامِ جدول `store_` است نه `biz_` (مثلِ `store_shareholders`): حذفِ حساب و بازگرداندن
--   در طرفِ شخصی باید آن را بشناسند، و طرفِ شخصی هیچ جدولِ `biz_` را نام نمی‌برد (قاعده ۷۰).
--
-- ایمن و قابل اجرای دوباره.
-- ============================================================

CREATE TABLE IF NOT EXISTS `store_staff` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`        INT UNSIGNED NOT NULL COMMENT 'صاحبِ فروشگاه',
    `staff_id`       INT UNSIGNED NOT NULL COMMENT 'حسابِ ورودِ همکار',
    `role`           VARCHAR(20)  NOT NULL DEFAULT 'seller' COMMENT 'Biz::STAFF_ROLES',
    `commission_pct` DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT 'پورسانت از فروشِ خالص (٪)',
    `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_store_staff_login` (`staff_id`),
    KEY `idx_store_staff_owner` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

ALTER TABLE `biz_invoices`
    ADD COLUMN IF NOT EXISTS `created_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'ثبت‌کننده (صاحب یا همکار)';
ALTER TABLE `biz_payments`
    ADD COLUMN IF NOT EXISTS `created_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'ثبت‌کننده (صاحب یا همکار)';

SELECT 'migration_biz_staff با موفقیت اجرا شد.' AS result;
