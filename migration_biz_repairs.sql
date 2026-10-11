-- ============================================================
-- migration_biz_repairs.sql — پذیرشِ تعمیرات و گارانتی
--
--   biz_repairs                  قبضِ پذیرش: دستگاه، IMEI، ایراد، لوازمِ همراه، وضعیت
--                                (`BizRepairs::STATUSES`)، برآورد، تعمیرکار، و فاکتورِ فروشی
--                                که هزینه با آن گرفته شد (`invoice_id`). پول هرگز این‌جا
--                                نیست — فقط در فاکتور.
--   biz_products.warranty_*      گارانتیِ پیش‌فرضِ کالا (ماه و برچسب، مثلاً «۱۸ ماه شرکتی»)
--   biz_invoice_lines.warranty_months  عکسِ گارانتی هنگامِ **صدور** (`BizWarranty::stamp()`):
--                                عوض کردنِ کالا گارانتیِ فروخته‌شده را عوض نمی‌کند.
--
-- ایمن و قابل اجرای دوباره.
-- ============================================================

CREATE TABLE IF NOT EXISTS `biz_repairs` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED NOT NULL,
    `number`        INT UNSIGNED NOT NULL,
    `party_id`      INT UNSIGNED NULL DEFAULT NULL,
    `customer_name` VARCHAR(120) NULL DEFAULT NULL COMMENT 'مشتریِ گذری',
    `customer_phone` VARCHAR(20) NULL DEFAULT NULL,
    `device`        VARCHAR(120) NOT NULL,
    `imei`          VARCHAR(20)  NULL DEFAULT NULL,
    `problem`       VARCHAR(500) NOT NULL,
    `accessories`   VARCHAR(190) NULL DEFAULT NULL,
    `status`        VARCHAR(20)  NOT NULL DEFAULT 'received',
    `estimate`      BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `technician_id` INT UNSIGNED NULL DEFAULT NULL,
    `received_on`   DATE NOT NULL,
    `promised_on`   DATE NULL DEFAULT NULL,
    `delivered_at`  DATETIME NULL DEFAULT NULL,
    `invoice_id`    INT UNSIGNED NULL DEFAULT NULL,
    `note`          VARCHAR(500) NULL DEFAULT NULL,
    `created_by`    INT UNSIGNED NULL DEFAULT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_biz_repairs_no` (`user_id`, `number`),
    KEY `idx_biz_repairs_status` (`user_id`, `status`),
    KEY `idx_biz_repairs_imei` (`user_id`, `imei`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

ALTER TABLE `biz_products`
    ADD COLUMN IF NOT EXISTS `warranty_months` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'گارانتیِ پیش‌فرض (ماه)؛ ۰ = ندارد',
    ADD COLUMN IF NOT EXISTS `warranty_label`  VARCHAR(40) NULL DEFAULT NULL COMMENT 'مثلاً «شرکتی»';

ALTER TABLE `biz_invoice_lines`
    ADD COLUMN IF NOT EXISTS `warranty_months` SMALLINT UNSIGNED NULL DEFAULT NULL COMMENT 'عکسِ گارانتی هنگامِ صدور';

SELECT 'migration_biz_repairs با موفقیت اجرا شد.' AS result;
