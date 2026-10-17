-- ============================================================
-- migration_biz_used_buy.sql — رسیدِ خریدِ گوشیِ دست‌دوم با هویتِ فروشنده (مهر ۱۴۰۵)
--
-- خواسته‌ی مالکِ نصب: وقتی گوشی از مشتری خریده می‌شود، کدِ ملی و مشخصاتِ فروشنده ثبت و رسید
-- چاپ شود — اگر بعداً معلوم شود گوشی سرقتی بوده، فروشگاه مدرکِ خریدِ قانونی دارد.
--
-- ⛔ به یک فاکتورِ **خرید**ِ واقعی بسته است (`invoice_id` یکتا) — پول و موجودی همان فاکتور است؛
--    این ردیف فقط **عکسِ لحظه‌ی خرید** از هویتِ فروشنده و وضعیتِ دستگاه است (ویرایشِ بعدیِ
--    طرف‌حساب آن را عوض نمی‌کند).
-- ایدمپوتنت (IF NOT EXISTS).
-- ============================================================

CREATE TABLE IF NOT EXISTS `biz_used_buys` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED NOT NULL,
    `invoice_id`    INT UNSIGNED NOT NULL,
    `seller_name`   VARCHAR(120) NOT NULL,
    `national_id`   CHAR(10)     NOT NULL,
    `father_name`   VARCHAR(60)  NULL DEFAULT NULL,
    `phone`         VARCHAR(20)  NULL DEFAULT NULL,
    `address`       VARCHAR(255) NULL DEFAULT NULL,
    `condition_note` VARCHAR(255) NULL DEFAULT NULL COMMENT 'وضعیتِ ظاهری و فنی',
    `accessories`   VARCHAR(255) NULL DEFAULT NULL COMMENT 'BizUsedBuy::ACCESSORIES، با «،»',
    `hamta_checked` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'استعلامِ همتا انجام شد',
    `created_by`    INT UNSIGNED NULL DEFAULT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_biz_used_buy_inv` (`invoice_id`),
    KEY `idx_biz_used_buy_nid` (`user_id`, `national_id`),
    CONSTRAINT `fk_biz_used_buy_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_biz_used_buy_inv` FOREIGN KEY (`invoice_id`) REFERENCES `biz_invoices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

SELECT 'migration_biz_used_buy با موفقیت اجرا شد.' AS result;
