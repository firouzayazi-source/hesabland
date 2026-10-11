-- ============================================================
-- migration_biz_installments.sql — فروشِ اقساطی
--
-- برنامه‌ی اقساط **فقط زمان‌بندی** است، نه پول: مانده همان ماندهِ فاکتور است
-- (`total − paid`) و هر دریافت از همان مسیرِ همیشگی ثبت و به فاکتور تخصیص داده
-- می‌شود. «پرداخت‌شده‌ی هر قسط» از جمعِ دریافت‌های بعد از ساختِ برنامه، به ترتیبِ
-- سررسید پر می‌شود (`BizInstallments::schedule()`) — هیچ ردیفی «پرداخت» نمی‌خورد.
--
--   biz_installment_plans  یک برنامه برای هر فاکتور (`paid_before` = دریافتِ پیش از برنامه)
--   biz_installments       ردیف‌ها: سررسید و مبلغ
--
-- ایمن و قابل اجرای دوباره.
-- ============================================================

CREATE TABLE IF NOT EXISTS `biz_installment_plans` (
    `invoice_id`  INT UNSIGNED NOT NULL,
    `user_id`     INT UNSIGNED NOT NULL,
    `paid_before` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `created_by`  INT UNSIGNED NULL DEFAULT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`invoice_id`),
    KEY `idx_biz_iplan_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `biz_installments` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `invoice_id` INT UNSIGNED NOT NULL,
    `seq`        SMALLINT UNSIGNED NOT NULL,
    `due_date`   DATE NOT NULL,
    `amount`     BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_biz_inst_seq` (`invoice_id`, `seq`),
    KEY `idx_biz_inst_due` (`user_id`, `due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

SELECT 'migration_biz_installments با موفقیت اجرا شد.' AS result;
