-- ============================================================
-- migration_biz_pos.sql — اتصال به کارت‌خوان (مهر ۱۴۰۵)
--
-- خواسته‌ی مالکِ نصب (بعد از مقایسه با «قیاس»): مبلغ مستقیم به کارت‌خوان برود — و
-- چون این محصول فروشی است، «هر کسی ممکنه با دستگاهِ مختلفی وصل بشه»: پس هیچ
-- شرکتِ خاصی سخت‌کد نیست؛ هر کارت‌خوان یک «راه‌انداز» (`BizPos::DRIVERS`) و تنظیمات دارد.
--
-- ⛔ هر تراکنشِ کارت‌خوان یک ردیف (`biz_pos_txns`)، و هر ردیفِ موفق فقط **یک** دریافت
--    می‌سازد (`status = used` + `payment_id`) — یک رسیدِ کارت دو بار ثبت نمی‌شود.
-- ایدمپوتنت (IF NOT EXISTS).
-- ============================================================

CREATE TABLE IF NOT EXISTS `biz_pos_terminals` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `name`       VARCHAR(60)  NOT NULL,
    `account_id` INT UNSIGNED NOT NULL COMMENT 'صندوقِ کارت‌خوان (پول به این صندوق می‌نشیند)',
    `driver`     VARCHAR(12)  NOT NULL DEFAULT 'manual' COMMENT 'BizPos::DRIVERS',
    `config`     TEXT NULL DEFAULT NULL COMMENT 'JSON — فقط BizPos::saveTerminal()',
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_biz_pos_term_user` (`user_id`, `is_active`),
    CONSTRAINT `fk_biz_pos_term_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_biz_pos_term_acc` FOREIGN KEY (`account_id`) REFERENCES `biz_accounts` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `biz_pos_txns` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED NOT NULL,
    `terminal_id` INT UNSIGNED NOT NULL,
    `amount`      BIGINT NOT NULL COMMENT 'تومان',
    `status`      VARCHAR(10) NOT NULL DEFAULT 'pending' COMMENT 'BizPos::STATUSES',
    `rrn`         VARCHAR(20) NULL DEFAULT NULL COMMENT 'شماره‌ی مرجع/پیگیری',
    `card`        VARCHAR(25) NULL DEFAULT NULL COMMENT 'شماره‌ی کارتِ پوشیده',
    `message`     VARCHAR(300) NULL DEFAULT NULL,
    `payment_id`  INT UNSIGNED NULL DEFAULT NULL COMMENT 'دریافتی که این تراکنش ساخت',
    `created_by`  INT UNSIGNED NULL DEFAULT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `done_at`     DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_biz_pos_payment` (`payment_id`),
    KEY `idx_biz_pos_txn_user` (`user_id`, `status`),
    KEY `idx_biz_pos_txn_rrn` (`user_id`, `rrn`),
    CONSTRAINT `fk_biz_pos_txn_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_biz_pos_txn_term` FOREIGN KEY (`terminal_id`) REFERENCES `biz_pos_terminals` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_biz_pos_txn_pay` FOREIGN KEY (`payment_id`) REFERENCES `biz_payments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

SELECT 'migration_biz_pos با موفقیت اجرا شد.' AS result;
