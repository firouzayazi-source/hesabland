-- ============================================================
-- migration_biz_books.sql — سرفصل‌های دلخواه، سندِ حسابداریِ دستی، دارایی‌های ثابت
--
--   biz_coa              حساب‌های دلخواهِ هر فروشگاه کنارِ `BizLedger::ACCOUNTS` (کدِ ۴ تا ۸ رقمی، نوع)
--   biz_journals         سندِ دستی (شماره، تاریخ، شرح، وضعیت ok/void، سازنده)
--   biz_journal_lines    ردیف‌های سندِ دستی (کد، بدهکار، بستانکار، شرحِ ردیف)
--   biz_fixed_assets     دارایی‌های ثابت؛ استهلاکِ ماهانه ذخیره نمی‌شود (مشتق، `BizAssets`)
--   biz_payments.asset_id  پرداختِ خریدِ دارایی (`kind = 'asset'`) به همان دارایی — پیوند فقط از این سو
--                          (ستونِ برعکس روی دارایی، بازگرداندنِ بکاپ را در حلقه‌ی پیوندها می‌انداخت)
--
-- ⛔ سندِ دستی روی حساب‌های کنترلی (صندوق، چک، طرف‌حساب، انبار، دارایی‌های ثابت) نمی‌نشیند:
--    مانده‌ی آن‌ها از سندهای خودشان است و `BizLedger::reconcile()` مغایرت نشان می‌داد.
--
-- ایمن و قابل اجرای دوباره.
-- ============================================================

CREATE TABLE IF NOT EXISTS `biz_coa` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `code`       VARCHAR(8)   NOT NULL,
    `name`       VARCHAR(80)  NOT NULL,
    `type`       VARCHAR(10)  NOT NULL COMMENT 'BizLedger::TYPES',
    `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_biz_coa_code` (`user_id`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `biz_journals` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED NOT NULL,
    `number`      INT UNSIGNED NOT NULL,
    `jdate`       DATE         NOT NULL,
    `description` VARCHAR(255) NOT NULL,
    `status`      VARCHAR(8)   NOT NULL DEFAULT 'ok' COMMENT 'ok / void',
    `created_by`  INT UNSIGNED NULL DEFAULT NULL,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_biz_journals_number` (`user_id`, `number`),
    KEY `idx_biz_journals_date` (`user_id`, `status`, `jdate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `biz_journal_lines` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `journal_id` INT UNSIGNED NOT NULL,
    `code`       VARCHAR(8)   NOT NULL,
    `debit`      BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `credit`     BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `note`       VARCHAR(160) NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_biz_journal_lines_j` (`journal_id`),
    KEY `idx_biz_journal_lines_u` (`user_id`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `biz_fixed_assets` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED NOT NULL,
    `name`          VARCHAR(120) NOT NULL,
    `acq_date`      DATE         NOT NULL,
    `cost`          BIGINT UNSIGNED NOT NULL,
    `salvage`       BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `life_months`   SMALLINT UNSIGNED NOT NULL,
    `funding`       VARCHAR(8)   NOT NULL DEFAULT 'cash' COMMENT 'cash (پرداخت از صندوق) / owner (آورده‌ی مالک)',
    `disposed_on`   DATE         NULL DEFAULT NULL,
    `note`          VARCHAR(300) NULL DEFAULT NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_biz_fixed_assets_u` (`user_id`, `acq_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

ALTER TABLE `biz_payments`
    ADD COLUMN IF NOT EXISTS `asset_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'خریدِ دارایی‌ی ثابت (kind = asset)';

SELECT 'migration_biz_books با موفقیت اجرا شد.' AS result;
