-- ============================================================
-- migration_biz_models.sql — مدل و حالت‌ها (رنگ، حافظه) (مهر ۱۴۰۵)
--
-- خواسته‌ی مالکِ نصب: «آیفون ۱۵، ۱۲۸ گیگ، مشکی» یک کالای مادر با چند حالت باشد، نه ده
-- کالای جدا که هر بار دستی ساخته شوند.
--
-- ⛔ هر حالت یک **ردیفِ واقعیِ `biz_products`** است (با `model_id`) — موجودی، IMEI، فاکتور،
--    گزارش و چاپ هیچ تغییری نمی‌کنند. مدل فقط گروه‌بندی و سازنده‌ی حالت‌هاست؛ حذفِ مدل کالاها
--    را حذف نمی‌کند (`ON DELETE SET NULL`).
-- ایدمپوتنت (IF NOT EXISTS).
-- ============================================================

CREATE TABLE IF NOT EXISTS `biz_models` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `name`       VARCHAR(100) NOT NULL,
    `attr1`      VARCHAR(30)  NOT NULL DEFAULT 'حافظه' COMMENT 'نامِ ویژگیِ اول',
    `attr2`      VARCHAR(30)  NOT NULL DEFAULT 'رنگ'   COMMENT 'نامِ ویژگیِ دوم',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_biz_models_user` (`user_id`, `name`),
    CONSTRAINT `fk_biz_models_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

ALTER TABLE `biz_products`
    ADD COLUMN IF NOT EXISTS `model_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'BizModels — حالتی از این مدل',
    ADD COLUMN IF NOT EXISTS `variant1` VARCHAR(40) NULL DEFAULT NULL COMMENT 'مقدارِ ویژگیِ اول (مثلاً ۱۲۸ گیگ)',
    ADD COLUMN IF NOT EXISTS `variant2` VARCHAR(40) NULL DEFAULT NULL COMMENT 'مقدارِ ویژگیِ دوم (مثلاً مشکی)',
    ADD KEY IF NOT EXISTS `idx_biz_products_model` (`user_id`, `model_id`);

SET @fk := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_biz_products_model');
SET @sql := IF(@fk = 0,
    'ALTER TABLE `biz_products` ADD CONSTRAINT `fk_biz_products_model` FOREIGN KEY (`model_id`) REFERENCES `biz_models` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SELECT 'migration_biz_models با موفقیت اجرا شد.' AS result;
