<?php
/**
 * ⛔ `Audit` — دفترِ رویدادهای امنیتی و مدیریتی. «چه کسی، کِی، از کجا،
 *    چه کاری کرد» — و **نه** «چه چیزی».
 *
 * **چرا هست:** تا امروز هیچ ردی از ورودِ موفق، تغییرِ رمز، ابطالِ
 * دسترسی، غیرفعال‌سازیِ کاربر، تغییرِ تنظیماتِ مدیر، هدیه‌ی اشتراک، یا
 * بازگرداندنِ کلِ دفتر از فایل وجود نداشت. یعنی وقتی کاربر می‌گفت
 * «رمزم عوض شده و من عوض نکرده‌ام» یا مدیر می‌پرسید «کی این تنظیم را
 * زد؟»، جوابی نبود.
 *
 * **⛔ و این عمداً همان چیزی نیست که `CLAUDE.md` رد کرده.** قاعده‌ی «هیچ
 * ردیابیِ تازه‌ای» و «ورودها را ثبت نمی‌کنیم» در بخشِ «آمار استفاده» نوشته
 * شده و موضوعش **ردِ رفتاریِ محصول** بود: چه کسی چند تراکنش زد، چه
 * قابلیتی را استفاده کرد. این دفتر سه مرزِ صریح دارد تا همان قاعده
 * سرِ جایش بماند:
 *   ۱. **فقط رویدادهای امنیتی/مدیریتی** (`ACTIONS`، فهرستِ بسته). ثبتِ
 *      تراکنش، حذفِ چک، ویرایشِ بودجه — کارِ روزمره‌ی کاربر با دفترِ
 *      خودش — **اینجا نمی‌آید**. آن یعنی همان ردِ رفتاری.
 *   ۲. **هیچ محتوایی**: نه مبلغ، نه عنوان، نه طرفِ حساب، نه ایمیل، نه
 *      رمز. فقط نامِ رویداد، شناسه‌ها، آی‌پی و یک `detail` کوتاهِ
 *      شسته‌شده (`Log::redact()`).
 *   ۳. **`admin/insights.php` از این جدول نمی‌خواند** و نباید بخواند
 *      (قاعده ۴۴ در `test_api_contract.php`). «آخرین فعالیت» همچنان از
 *      رکوردهای خودِ کاربر می‌آید، نه از ورودها.
 * و `privacy.php` این دفتر را با همین سه مرز **می‌گوید**، چون آن صفحه
 * قرار است هر ادعایش در کد نشان‌دادنی باشد.
 *
 * **⛔ ستونِ کاربر `actor_id` است، نه `user_id` — و این عمدی است.**
 * `userDataTables()` هر جدولِ `user_id`دار را هنگامِ حذفِ حساب می‌برد و
 * در خروجیِ کاربر می‌آورد. ردیفِ `account.deleted` باید **بعد از** حذفِ
 * حساب هم بماند، وگرنه دفتر دقیقاً درباره‌ی مهم‌ترین رویدادش ساکت
 * می‌شود. ردیف‌ها ۹۰ روز می‌مانند و بعد خودشان پاک می‌شوند
 * (`KEEP_DAYS`)، پس چیزی برای همیشه نمی‌ماند.
 *
 * **⛔ هرگز استثنا پرتاب نمی‌کند.** کارِ جانبی است؛ اگر جدول نیامده یا
 * درج شکست خورد، عملیاتِ اصلی (ورود، تغییر رمز) نباید بشکند — همان
 * قاعده‌ی `AppErrors::record()` و `CronHealth::beat()`.
 */

final class Audit
{
    /** چند روز نگه داشته شود. */
    public const KEEP_DAYS = 90;

    /**
     * ⛔ فهرستِ بسته‌ی رویدادها — نامِ ناشناخته ثبت نمی‌شود (و یک `warn`
     *    در لاگ می‌گذارد). بدونِ فهرستِ بسته، غلطِ املاییِ یک نام دو
     *    رویدادِ متفاوت می‌ساخت و گزارشِ «چند بار رمز عوض شد» ناقص
     *    می‌شد، بی‌هیچ خطایی.
     */
    public const ACTIONS = [
        // احراز هویت
        'auth.login', 'auth.login_failed', 'auth.logout', 'auth.password_changed',
        'auth.password_reset', 'auth.access_revoked',
        // کاربر (خودش یا مدیر)
        'user.created', 'user.updated', 'user.activated', 'user.deactivated',
        'user.login_unlocked', 'account.deleted',
        // نوعِ حساب (شخصی / فروشگاهی) — فقط مدیر، از `Biz::setType()`
        'user.account_type',
        // تنظیماتِ نصب
        'settings.changed',
        // اشتراک و پرداخت (مدیر)
        'plan.granted', 'plan.revoked', 'plan.discount_redeemed',
        'payment.approved', 'payment.rejected',
        // داده‌ی کاربر — عملیات‌های یک‌جا و برگشت‌ناپذیر
        'data.exported', 'data.imported',
        // دسته‌بندی (مدیر)
        'category.privatized', 'category.merged',
        /*
         * سهامدارِ فروشگاه (مدیر) — وصل کردن یعنی دادنِ دسترسی به
         * داده‌ی مالیِ یک سیستمِ دیگر به یک کاربر، پس دقیقاً از همان
         * جنسِ `plan.granted` است و باید رد داشته باشد.
         */
        'store_share.linked', 'store_share.unlinked',
        // سودِ کلِ فروشگاه به دفترِ کدام مدیر می‌رود — همان جنس
        'store_share.house',
        // همکارانِ فروشگاه (صاحبِ فروشگاه) — دادنِ دسترسی به دفترِ فروشگاه
        'store.staff_added', 'store.staff_changed', 'store.staff_removed',
    ];

    /** کلیدهای تنظیماتی که مقدارشان هرگز در `detail` نمی‌آید. */
    private const SECRET_SETTING = '/(key|pass|secret|token)/i';

    private static ?bool $tableOk = null;
    private static bool  $pruned  = false;

    /**
     * ⛔ به `functions.php` بند نیست. `logout.php` فقط `auth.php` را لود
     *    می‌کند و آنجا `tableExists()` وجود ندارد؛ نسخه‌ی اول همین را
     *    می‌پرسید و **خروج هرگز به جدول نمی‌رسید** — خطِ فایل نوشته می‌شد
     *    (`db.n = 0`) و هیچ خطایی نبود. تستِ HTTP گرفتش، نه بازبینی.
     */
    public static function available(): bool
    {
        if (self::$tableOk === null) {
            if (function_exists('tableExists')) {
                self::$tableOk = tableExists('audit_log');
            } else {
                try {
                    Database::getConnection()->query('SELECT 1 FROM audit_log LIMIT 0');
                    self::$tableOk = true;
                } catch (Throwable $e) {
                    self::$tableOk = false;
                }
            }
        }
        return self::$tableOk;
    }

    /**
     * ثبتِ یک رویداد.
     *
     * @param string   $action   یکی از `ACTIONS`
     * @param string   $entity   نوعِ موضوع (`user`, `setting`, `payment`, …) یا خالی
     * @param int|null $entityId شناسه‌ی موضوع
     * @param array    $detail   جزئیاتِ کوتاه، بدونِ محتوای دفتر — شسته می‌شود
     * @param int|null $actorId  پیش‌فرض: کاربرِ همین درخواست؛ `null` یعنی سیستم/خط فرمان
     * @param int|null $targetUserId کاربری که رویداد درباره‌ی اوست (اگر با actor فرق دارد)
     */
    public static function log(string $action, string $entity = '', ?int $entityId = null,
                               array $detail = [], ?int $actorId = null, ?int $targetUserId = null): bool
    {
        if (!in_array($action, self::ACTIONS, true)) {
            Log::warn('audit.unknown_action', ['action' => $action]);
            return false;
        }

        $actor  = $actorId ?? Log::userId();
        $ip     = PHP_SAPI === 'cli' ? null : (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $clean  = Log::redact($detail);
        $json   = $clean === [] ? null
                : mb_substr((string)json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 500);

        // ⛔ فایل هم می‌گیرد: جریانِ قابلِ grep با شناسه‌ی درخواست. جدول
        //    دفترِ ماندگار است، فایل جریانِ زمانی — دو نقشِ متفاوت، نه
        //    تکرارِ یک خطا در دو لایه.
        Log::info('audit.' . $action, ['entity' => $entity, 'entity_id' => $entityId,
            'target_uid' => $targetUserId, 'detail' => $clean]);

        try {
            if (!self::available()) { return false; }
            Database::getConnection()->prepare(
                'INSERT INTO audit_log (request_id, actor_id, target_user_id, action, entity, entity_id, ip, detail)
                 VALUES (:r, :a, :t, :ac, :e, :ei, :ip, :d)'
            )->execute([
                'r'  => mb_substr(Log::requestId(), 0, 64),
                'a'  => $actor,
                't'  => $targetUserId,
                'ac' => $action,
                'e'  => $entity === '' ? null : mb_substr($entity, 0, 40),
                'ei' => $entityId,
                'ip' => ($ip === null || $ip === '') ? null : mb_substr($ip, 0, 45),
                'd'  => $json,
            ]);
            self::pruneOncePerDay();
            return true;
        } catch (Throwable $e) {
            Log::write('warn', 'audit.write_failed', ['msg' => AppErrors::scrub($e->getMessage())]);
            return false;
        }
    }

    /** یک تغییرِ تنظیم — مقدارِ کلیدهای محرمانه پنهان می‌ماند. */
    public static function setting(string $key, ?string $old, string $new): bool
    {
        $secret = (bool)preg_match(self::SECRET_SETTING, $key);
        return self::log('settings.changed', 'setting', null, [
            'key'  => $key,
            'from' => $secret ? ($old === null || $old === '' ? '' : Log::REDACTED) : $old,
            'to'   => $secret ? ($new === '' ? '' : Log::REDACTED) : $new,
        ]);
    }

    /** آخرین رویدادها — برای کارتِ «رویدادهای امنیتی» در پنل مدیر. */
    public static function recent(int $limit = 20): array
    {
        try {
            if (!self::available()) { return []; }
            $st = Database::getConnection()->prepare(
                'SELECT a.id, a.created_at, a.request_id, a.actor_id, a.target_user_id, a.action,
                        a.entity, a.entity_id, a.ip, a.detail,
                        u.username AS actor_name, t.username AS target_name
                   FROM audit_log a
                   LEFT JOIN users u ON u.id = a.actor_id
                   LEFT JOIN users t ON t.id = a.target_user_id
                  ORDER BY a.id DESC LIMIT ' . max(1, min(200, $limit))
            );
            $st->execute();
            return $st->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    /** شمارشِ یک رویداد در N ساعتِ گذشته — برای آستانه‌ی هشدار (`log-report`). */
    public static function countSince(string $action, int $hours): int
    {
        try {
            if (!self::available()) { return 0; }
            $st = Database::getConnection()->prepare(
                'SELECT COUNT(*) FROM audit_log WHERE action = :a AND created_at >= DATE_SUB(NOW(), INTERVAL :h HOUR)'
            );
            $st->execute(['a' => $action, 'h' => max(1, $hours)]);
            return (int)$st->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** حذفِ ردیف‌های کهنه‌تر از `KEEP_DAYS`. مرزِ زمان را دیتابیس می‌گذارد. */
    public static function prune(): int
    {
        try {
            if (!self::available()) { return 0; }
            $st = Database::getConnection()->prepare(
                'DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL :d DAY)'
            );
            $st->execute(['d' => self::KEEP_DAYS]);
            return $st->rowCount();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * یک بار در روز، از اولین ردیفی که آن روز نوشته می‌شود. نشانه‌اش
     * یک فایل در `var/log` است نه `app_settings` — `setSetting()` خودش
     * رویدادِ ممیزی می‌سازد و از اینجا یک حلقه می‌شد.
     */
    private static function pruneOncePerDay(): void
    {
        if (self::$pruned) { return; }
        self::$pruned = true;

        $marker = Log::dir() . '/.audit-pruned';
        $today  = date('Y-m-d');
        if (is_readable($marker) && trim((string)@file_get_contents($marker)) === $today) { return; }
        if (!is_dir(Log::dir())) { return; }
        if (@file_put_contents($marker, $today) === false) { return; }
        self::prune();
    }
}
