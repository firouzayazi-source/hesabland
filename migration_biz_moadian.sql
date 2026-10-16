-- ============================================================
-- migration_biz_moadian.sql — ارسالِ مستقیمِ صورتحساب به سامانه‌ی مودیان (مهر ۱۴۰۵)
--
-- خواسته‌ی مالکِ نصب (بعد از مقایسه با «قیاس»): «پیش‌ساخت برای مشتریان» —
-- آماده در محصول، خاموش تا فروشگاه کلید و گواهیِ خودش را بگذارد.
--
-- ⛔ کلیدِ خصوصی فقط رمزشده (`Crypto`) و هرگز در خروجیِ کاربر/بکاپِ کاربر
--    (`USER_IMPORT_SKIP` برای `biz_settings` از قبل هست). گواهی عمومی است.
-- ⛔ هر تلاش یک ردیف (`biz_moadian_sends`): سابقه پاک نمی‌شود — همان چیزی
--    که سامانه گفته، با شماره‌ی پیگیری، برای روزِ اختلاف.
-- ایدمپوتنت (IF NOT EXISTS).
-- ============================================================

ALTER TABLE `biz_settings`
    ADD COLUMN IF NOT EXISTS `moadian_on`   TINYINT(1)  NOT NULL DEFAULT 0 COMMENT 'ارسالِ مستقیم روشن است',
    ADD COLUMN IF NOT EXISTS `moadian_env`  VARCHAR(10) NOT NULL DEFAULT 'sandbox' COMMENT 'BizMoadianLive::ENVS',
    ADD COLUMN IF NOT EXISTS `moadian_key`  TEXT NULL DEFAULT NULL COMMENT 'کلیدِ خصوصیِ PEM — فقط رمزشده (Crypto)',
    ADD COLUMN IF NOT EXISTS `moadian_cert` TEXT NULL DEFAULT NULL COMMENT 'گواهیِ امضا (PEM)';

CREATE TABLE IF NOT EXISTS `biz_moadian_sends` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED NOT NULL,
    `invoice_id`  INT UNSIGNED NOT NULL,
    `subject`     TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'ins: ۱ اصلی، ۳ ابطالی، ۴ برگشت از فروش',
    `env`         VARCHAR(10)  NOT NULL DEFAULT 'sandbox',
    `taxid`       VARCHAR(22)  NOT NULL,
    `uid`         VARCHAR(40)  NOT NULL COMMENT 'requestTraceId',
    `reference`   VARCHAR(80)  NULL DEFAULT NULL COMMENT 'referenceNumber از سامانه',
    `status`      VARCHAR(12)  NOT NULL DEFAULT 'sent' COMMENT 'BizMoadianLive::STATUSES',
    `message`     TEXT NULL DEFAULT NULL,
    `created_by`  INT UNSIGNED NULL DEFAULT NULL,
    `sent_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `checked_at`  DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_biz_moadian_inv` (`user_id`, `invoice_id`, `id`),
    KEY `idx_biz_moadian_status` (`user_id`, `status`),
    CONSTRAINT `fk_biz_moadian_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_biz_moadian_inv` FOREIGN KEY (`invoice_id`) REFERENCES `biz_invoices` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

SELECT 'migration_biz_moadian با موفقیت اجرا شد.' AS result;
