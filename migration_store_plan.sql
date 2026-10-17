-- ============================================================
-- migration_store_plan.sql — اشتراکِ فروشگاه و پرداختِ آنلاین (مهر ۱۴۰۵)
--
-- خواسته‌ی مالکِ نصب: حساب لند به فروشگاه‌ها فروخته می‌شود — دوره‌ی آزمایشی،
-- تمدید با درگاهِ پرداخت (یا کارت‌به‌کارت با تأییدِ مدیر) و یادآوریِ تمدید.
--
-- ⛔ انقضا **تاریخ** است (`users.store_until`)، نه کلید — همان استدلالِ `pro_until`:
--    انقضا خودش اتفاق می‌افتد و هیچ cronی لازم نیست. روی حسابِ **ورود**ِ صاحب
--    می‌نشیند و همه‌ی فروشگاه‌هایش (store_shops) و همکارانش را می‌پوشاند.
-- ⛔ هر پرداخت یک ردیف؛ تأییدِ درگاه یک بار (`status` از pending فقط یک بار جلو
--    می‌رود)، و `authority`ِ درگاه یکتاست.
-- ایدمپوتنت (IF NOT EXISTS).
-- ============================================================

ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `store_until` DATE NULL DEFAULT NULL COMMENT 'اشتراکِ فروشگاه تا این روز (StorePlan)';

CREATE TABLE IF NOT EXISTS `store_plan_payments` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL COMMENT 'پرداخت‌کننده = صاحبِ فروشگاه (حسابِ ورود)',
    `months`     SMALLINT UNSIGNED NOT NULL,
    `amount`     BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'تومان',
    `method`     VARCHAR(12) NOT NULL COMMENT 'gateway | card | grant',
    `status`     VARCHAR(10) NOT NULL DEFAULT 'pending' COMMENT 'pending | paid | failed | rejected',
    `gateway`    VARCHAR(16) NULL DEFAULT NULL,
    `authority`  VARCHAR(64) NULL DEFAULT NULL COMMENT 'شناسه‌ی درگاه — یکتا',
    `ref_id`     VARCHAR(64) NULL DEFAULT NULL COMMENT 'کدِ پیگیریِ درگاه یا رسیدِ کارت‌به‌کارت',
    `card_pan`   VARCHAR(24) NULL DEFAULT NULL COMMENT 'کارتِ پوشیده‌ای که درگاه برگرداند',
    `note`       VARCHAR(255) NULL DEFAULT NULL,
    `actor_id`   INT UNSIGNED NULL DEFAULT NULL COMMENT 'مدیری که تأیید/هدیه/رد کرد',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `paid_at`    DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_store_plan_authority` (`authority`),
    KEY `idx_store_plan_user` (`user_id`, `created_at`),
    KEY `idx_store_plan_status` (`status`, `created_at`),
    CONSTRAINT `fk_store_plan_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

SELECT 'migration_store_plan با موفقیت اجرا شد.' AS result;
