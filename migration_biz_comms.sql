-- ============================================================
-- migration_biz_comms.sql — ارتباط با مشتری: پیامک، لینکِ فاکتور، امتیازِ وفاداری
--
--   biz_settings.comms        تنظیماتِ پیامک و باشگاهِ مشتری (JSON، `BizComms::settings()`)
--   biz_sms_log               هر پیامکِ فرستاده به مشتری (نوع، سند، شماره، نتیجه) — سقفِ ماهانه
--                             و جلوگیری از دوباره‌فرستادن از همین؛ **متنِ پیامک ذخیره نمی‌شود**
--   biz_invoices.loyalty_points  امتیازِ مصرف‌شده روی همان فاکتور (به‌صورتِ تخفیف)
--
-- ایمن و قابل اجرای دوباره.
-- ============================================================

ALTER TABLE `biz_settings`
    ADD COLUMN IF NOT EXISTS `comms` TEXT NULL DEFAULT NULL COMMENT 'BizComms::settings()';

CREATE TABLE IF NOT EXISTS `biz_sms_log` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `kind`       VARCHAR(16)  NOT NULL COMMENT 'BizComms::KINDS',
    `ref_id`     INT UNSIGNED NOT NULL,
    `party_id`   INT UNSIGNED NULL DEFAULT NULL,
    `phone`      VARCHAR(20)  NOT NULL,
    `ok`         TINYINT(1)   NOT NULL DEFAULT 0,
    `created_by` INT UNSIGNED NULL DEFAULT NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_biz_sms_log_month` (`user_id`, `created_at`),
    KEY `idx_biz_sms_log_ref` (`user_id`, `kind`, `ref_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

ALTER TABLE `biz_invoices`
    ADD COLUMN IF NOT EXISTS `loyalty_points` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'امتیازِ مصرف‌شده (تخفیف)';

SELECT 'migration_biz_comms با موفقیت اجرا شد.' AS result;
