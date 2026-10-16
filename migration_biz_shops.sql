-- ============================================================
-- migration_biz_shops.sql — چند فروشگاه با یک حساب (مهر ۱۴۰۵)
--
-- خواسته‌ی مالکِ نصب (بعد از مقایسه با «قیاس»): کسی که دو فروشگاهِ جدا دارد،
-- هر دو را با یک ورود و جدا از هم داشته باشد.
--
-- ⛔ هر فروشگاهِ اضافه یک **حسابِ داده**ی جدا در `users` است (بی‌رمز، بی‌شماره —
--    هرگز خودش وارد نمی‌شود) و صاحب با `Biz::ownerId()` واردش می‌شود — همان مسیری
--    که همکاران دارند. پس هر قاعده‌ی جداسازیِ داده‌ی فروشگاه بی‌تغییر برقرار است.
-- ⛔ `user_id` = صاحب (حسابِ ورود)، `shop_id` = حسابِ داده‌ی فروشگاه. اجازه است، نه
--    دفتر: از فایلِ بکاپ نوشته نمی‌شود (`USER_IMPORT_SKIP`).
-- ایدمپوتنت (IF NOT EXISTS).
-- ============================================================

CREATE TABLE IF NOT EXISTS `store_shops` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL COMMENT 'صاحب (حسابِ ورود)',
    `shop_id`    INT UNSIGNED NOT NULL COMMENT 'حسابِ داده‌ی فروشگاهِ اضافه',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_store_shops_shop` (`shop_id`),
    KEY `idx_store_shops_owner` (`user_id`),
    CONSTRAINT `fk_store_shops_owner` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_store_shops_shop` FOREIGN KEY (`shop_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

SELECT 'migration_biz_shops با موفقیت اجرا شد.' AS result;
