-- ============================================================
-- migration_biz_ops.sql — امانی، ارز، مغایرت‌گیریِ بانکی، هزینه‌ی تکراری
--
--   biz_consignments          کالای امانی (گوشیِ همکار): **موجودی نیست** و در ارزشِ انبار
--                             نمی‌آید؛ «خرید» یعنی فاکتورِ خریدِ واقعی از همکار (`BizConsign::buy()`)
--   biz_parties.fx_code       ارزِ حسابِ طرف‌حساب (`NULL` = تومان) — دلار، درهم، یورو، تتر
--   biz_invoices.fx_rate      نرخِ تومانیِ یک واحد ارز روی همان سند (عکسِ لحظه، هرگز بازنویسی نمی‌شود)
--   biz_payments.fx_rate      همان، برای دریافت/پرداختِ طرف‌حسابِ ارزی
--   biz_bank_lines            ردیف‌های صورت‌حسابِ بانک و جفتِ هر کدام در اسناد (`BizBankRec`)
--   biz_recurring             هزینه‌ی تکراری (اجاره، اینترنت) — سررسید از تاریخِ شروع و شمارِ
--                             ثبت‌شده‌ها ساخته می‌شود (`jalaliAddMonths` با لنگر)، پس روزِ ۳۱ نمی‌لغزد
--
-- ⛔ هیچ‌کدام عددِ موجودِ دفتر را عوض نمی‌کند: تا کسی از این‌ها استفاده نکند همه `NULL`/خالی‌اند.
-- ایمن و قابل اجرای دوباره.
-- ============================================================

CREATE TABLE IF NOT EXISTS `biz_consignments` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED NOT NULL,
    `party_id`    INT UNSIGNED NOT NULL COMMENT 'امانت‌دهنده (همکار)',
    `product_id`  INT UNSIGNED NOT NULL,
    `qty`         DECIMAL(14,3) NOT NULL DEFAULT 1,
    `imei1`       VARCHAR(20)  NULL DEFAULT NULL,
    `imei2`       VARCHAR(20)  NULL DEFAULT NULL,
    `unit_cost`   BIGINT UNSIGNED NOT NULL COMMENT 'بهای توافقی، تومان',
    `received_on` DATE         NOT NULL,
    `status`      VARCHAR(10)  NOT NULL DEFAULT 'held' COMMENT 'BizConsign::STATUSES',
    `closed_on`   DATE         NULL DEFAULT NULL,
    `invoice_id`  INT UNSIGNED NULL DEFAULT NULL COMMENT 'فاکتورِ خریدی که از آن ساخته شد',
    `note`        VARCHAR(200) NULL DEFAULT NULL,
    `created_by`  INT UNSIGNED NULL DEFAULT NULL,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_biz_consign_status` (`user_id`, `status`, `received_on`),
    KEY `idx_biz_consign_imei` (`user_id`, `imei1`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

ALTER TABLE `biz_parties`
    ADD COLUMN IF NOT EXISTS `fx_code` VARCHAR(8) NULL DEFAULT NULL COMMENT 'BizFx::CODES — NULL = تومان';

ALTER TABLE `biz_invoices`
    ADD COLUMN IF NOT EXISTS `fx_rate` DECIMAL(16,2) NULL DEFAULT NULL COMMENT 'تومان برای یک واحدِ ارزِ طرف‌حساب';

ALTER TABLE `biz_payments`
    ADD COLUMN IF NOT EXISTS `fx_rate` DECIMAL(16,2) NULL DEFAULT NULL COMMENT 'تومان برای یک واحدِ ارزِ طرف‌حساب';

CREATE TABLE IF NOT EXISTS `biz_bank_lines` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED NOT NULL,
    `account_id`  INT UNSIGNED NOT NULL,
    `line_date`   DATE         NOT NULL,
    `amount`      BIGINT       NOT NULL COMMENT 'مثبت = واریز، منفی = برداشت',
    `descr`       VARCHAR(255) NULL DEFAULT NULL,
    `line_hash`   CHAR(40)     NOT NULL COMMENT 'سدِ واردِ دوباره‌ی همان ردیف',
    `payment_id`  INT UNSIGNED NULL DEFAULT NULL COMMENT 'سندِ جفت‌شده',
    `matched_how` VARCHAR(8)   NULL DEFAULT NULL COMMENT 'auto | manual',
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_biz_bank_line` (`user_id`, `account_id`, `line_hash`),
    KEY `idx_biz_bank_acc` (`user_id`, `account_id`, `line_date`),
    KEY `idx_biz_bank_pay` (`user_id`, `payment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `biz_recurring` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`      INT UNSIGNED NOT NULL,
    `title`        VARCHAR(150) NOT NULL,
    `amount`       BIGINT UNSIGNED NOT NULL,
    `category_id`  INT UNSIGNED NULL DEFAULT NULL COMMENT 'سرفصلِ هزینه',
    `account_id`   INT UNSIGNED NULL DEFAULT NULL COMMENT 'صندوقِ پیش‌فرضِ پرداخت',
    `every_months` TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `start_date`   DATE         NOT NULL COMMENT 'لنگرِ سری',
    `posted`       INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'چند دوره ثبت یا رد شده',
    `is_active`    TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_biz_recurring_user` (`user_id`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

SELECT 'migration_biz_ops با موفقیت اجرا شد.' AS result;
