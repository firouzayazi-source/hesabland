-- ============================================================
-- migration_biz_offline.sql — فروش در حالتِ قطعیِ اینترنت (مهر ۱۴۰۵)
--
-- خواسته‌ی مالکِ نصب: اینترنتِ مغازه که قطع شد، فروش نخوابد — فروش در همان
-- گوشی/رایانه نگه داشته شود و بعد از وصل شدن فرستاده شود.
--
-- ⛔ فروشِ آفلاین فقط **پیش‌نویسِ فاکتورِ فروش** می‌شود (`BizOffline::import()`)؛
--    موجودی و پول فقط وقتی کسی آنلاین صادرش کند — آفلاین نه موجودی دیده
--    می‌شود نه سقفِ اعتبار، پس صدورِ کور ممنوع است.
-- ⛔ این جدول فقط «این شناسه‌ی دستگاه قبلاً رسیده» است: ارسالِ دوباره‌ی همان
--    صف (قطعی وسطِ ارسال، دو بار زدن) پیش‌نویسِ دوم نمی‌سازد.
-- ایدمپوتنت (IF NOT EXISTS).
-- ============================================================

CREATE TABLE IF NOT EXISTS `biz_offline_sales` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED NOT NULL,
    `ref`         VARCHAR(40)  NOT NULL COMMENT 'شناسه‌ای که مرورگر هنگامِ ذخیره ساخت',
    `invoice_id`  INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL = در حالِ ساختن',
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_biz_offline_ref` (`user_id`, `ref`),
    KEY `idx_biz_offline_inv` (`invoice_id`),
    CONSTRAINT `fk_biz_offline_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_biz_offline_inv` FOREIGN KEY (`invoice_id`) REFERENCES `biz_invoices` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

SELECT 'migration_biz_offline با موفقیت اجرا شد.' AS result;
