-- ============================================================
-- migration_cheque_value.sql — «ارزشِ امروزِ» چک (تنزیل)
--
-- **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵):** «۷۰۰ میلیون چک دارم ولی با سودِ ۵ یا ۶ درصد
-- مالِ ۶ ماهِ دیگه است — ارزشِ واقعیِ دارایی به‌روز حساب بشه: ۱۰ روز مونده یه
-- مبلغ، ۴ روز مونده یه مبلغ، روزِ پاس شدن خودِ مبلغِ چک. هر چک یا هر سری چک
-- درصدِ خودش رو داره، و باید بگم این چک رو چطوری گرفتیم.»
--
-- ⛔ هیچ پولی اینجا نوشته نمی‌شود: مبلغِ چک (`amount`) همان مبلغِ اسمی است که روزِ
--    پاس شدن به حساب می‌نشیند. این ستون‌ها فقط **روشِ** حسابِ ارزشِ امروز را نگه
--    می‌دارند و عدد هر بار از نو ساخته می‌شود (`chequePresentValue()`) — هیچ عددِ
--    ذخیره‌شده‌ای نیست که کهنه شود.
--
--   value_method  NULL = بی‌سود (ارزش = مبلغ) | simple | compound | loan
--   value_rate    درصدِ **ماهانه**
--   value_base    فقط `loan`: سهمِ اصلِ پول در همین چک
--   value_from    فقط `loan`: شروعِ دوره‌ی همین قسط (سود از اینجا تا سررسید جمع می‌شود)
--   series_id     چک‌هایی که با هم ثبت شدند (شناسه‌ی اولین چکِ سری)، بی‌کلیدِ خارجی
--   series_no     جایگاهِ چک در سری (۱ از ۱۰)
--
-- ایمن و قابل اجرای دوباره.
-- ============================================================

ALTER TABLE `cheques`
    ADD COLUMN IF NOT EXISTS `value_method` VARCHAR(10) NULL DEFAULT NULL COMMENT 'NULL بی‌سود / simple / compound / loan',
    ADD COLUMN IF NOT EXISTS `value_rate`   DECIMAL(6,3) NULL DEFAULT NULL COMMENT 'درصدِ ماهانه',
    ADD COLUMN IF NOT EXISTS `value_base`   BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'loan: سهمِ اصل در این چک',
    ADD COLUMN IF NOT EXISTS `value_from`   DATE NULL DEFAULT NULL COMMENT 'loan: شروعِ دوره‌ی این قسط',
    ADD COLUMN IF NOT EXISTS `series_id`    BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'سری: شناسه‌ی اولین چک',
    ADD COLUMN IF NOT EXISTS `series_no`    SMALLINT UNSIGNED NULL DEFAULT NULL COMMENT 'جایگاه در سری';

ALTER TABLE `cheques`
    ADD INDEX IF NOT EXISTS `idx_cheques_series` (`user_id`, `series_id`);

SELECT 'migration_cheque_value با موفقیت اجرا شد.' AS result;
