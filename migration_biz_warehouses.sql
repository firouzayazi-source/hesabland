-- ============================================================
-- migration_biz_warehouses.sql — چند انبار و انتقال بینِ انبارها
--
--   biz_warehouses              انبارهای دیگر (شعبه، پشتِ مغازه، نمایندگی). «انبارِ اصلی» ردیف
--                               ندارد: `NULL` در هر ستونِ `warehouse_id` یعنی همان.
--   biz_stock_moves.warehouse_id  حرکتِ سند در کدام انبار (از سرِ فاکتور)
--   biz_invoices.warehouse_id     فاکتور از/به کدام انبار
--   biz_stock_transfers         انتقال بینِ دو انبار — **جدا از حرکت‌ها**: موجودیِ کل، بهای
--                               تمام‌شده، کاردکس و هر گزارشِ موجود دست نمی‌خورد (`BizStock::recalc()`
--                               آن را نمی‌بیند)؛ فقط تقسیمِ موجودی میانِ انبارها عوض می‌شود.
--
-- ⛔ فروشگاهِ تک‌انبار هیچ تفاوتی نمی‌بیند: تا انباری ساخته نشده همه‌چیز `NULL` است.
-- ایمن و قابل اجرای دوباره.
-- ============================================================

CREATE TABLE IF NOT EXISTS `biz_warehouses` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `name`       VARCHAR(80)  NOT NULL,
    `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_biz_warehouses_name` (`user_id`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `biz_stock_transfers` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED NOT NULL,
    `product_id`  INT UNSIGNED NOT NULL,
    `from_wh`     INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL = انبارِ اصلی',
    `to_wh`       INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL = انبارِ اصلی',
    `qty`         DECIMAL(14,3) NOT NULL,
    `imei`        VARCHAR(20)  NULL DEFAULT NULL COMMENT 'گوشی: همان دستگاه',
    `tdate`       DATE         NOT NULL,
    `note`        VARCHAR(200) NULL DEFAULT NULL,
    `created_by`  INT UNSIGNED NULL DEFAULT NULL,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_biz_transfers_prod` (`user_id`, `product_id`, `tdate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

ALTER TABLE `biz_stock_moves`
    ADD COLUMN IF NOT EXISTS `warehouse_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL = انبارِ اصلی';

ALTER TABLE `biz_invoices`
    ADD COLUMN IF NOT EXISTS `warehouse_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL = انبارِ اصلی';

SELECT 'migration_biz_warehouses با موفقیت اجرا شد.' AS result;
