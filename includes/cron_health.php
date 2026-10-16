<?php
/**
 * ⛔ «آخرین اجرا»ی کارهای زمان‌بندی‌شده — چون cronِ مرده هیچ صدایی ندارد.
 *
 * **مسئله‌ی واقعی:** پنج کار با cron اجرا می‌شوند (بکاپ، بکاپِ بیرونی،
 * پاک‌سازیِ نشست، یادآوریِ روزانه، و اعلانِ ساعتیِ سررسید) و از این میان
 * فقط `backup-offsite.sh` ردی از خودش می‌گذاشت (`.offsite-state`). برای
 * بقیه، اگر cron پاک شود، فایلش خراب باشد، یا اسکریپت هر شب با خطا
 * برگردد، **هیچ‌کس نمی‌فهمد** — و بدترینش بکاپ است، که تنها خرابیِ
 * برگشت‌ناپذیرِ این اپ هم همان است. سه هفته بکاپ نگرفتن دقیقاً همان‌قدر
 * ساکت است که سه هفته بکاپ گرفتن.
 *
 * **⛔ نشانه یک فایلِ ساده است، نه یک جدول.** سه تا از این پنج کار اسکریپتِ
 * bash هستند و دیتابیس را باز نمی‌کنند؛ با جدول یا باید PHP صدا بزنند
 * (یک وابستگیِ تازه در مسیرِ بکاپ) یا رفتارشان با آن دو تای دیگر فرق
 * می‌کرد. یک فایل با یک عددِ اپکِ یونیکس را هم bash می‌نویسد هم PHP
 * می‌خواند، و روی دیسکی می‌نشیند که `deploy.sh` از قبل نوشتنی نگه
 * می‌دارد (`var/`).
 *
 * **⛔ فهرستِ `JOBS` بسته است** (مثل `MIGRATIONS` و `EXPECT`): کارِ
 * زمان‌بندی‌شده‌ی تازه‌ای که اینجا ثبت نشود، در پنل مدیر **دیده نمی‌شود** —
 * یعنی دقیقاً همان خرابیِ بی‌صدایی که این فایل برای نبودنش نوشته شده.
 * `test_cron_health.php` فهرست را با خودِ اسکریپت‌ها می‌سنجد.
 */

require_once __DIR__ . '/log.php';

final class CronHealth
{
    /**
     * کارهای زمان‌بندی‌شده: کلید → [برچسب، دوره به ساعت، اسکریپت].
     *
     * «دوره» فاصله‌ی **مورد انتظار** بین دو اجراست و تنها چیزی است که
     * «کهنه» را تعریف می‌کند. ⚠ سقفِ هشدار دو برابرِ دوره به‌علاوه‌ی یک
     * ساعت اغماض است (`staleAfter()`): با سقفِ دقیقاً برابرِ دوره، یک
     * اجرای چند دقیقه دیرتر هم «خراب» خوانده می‌شد و هشدارِ الکی از
     * نبودِ هشدار بدتر است.
     */
    public const JOBS = [
        'backup'     => ['پشتیبان‌گیری از دیتابیس',  24, 'deploy/backup.sh'],
        'offsite'    => ['بردنِ بکاپ به بیرون',       24, 'deploy/backup-offsite.sh'],
        'sessions'   => ['پاک‌سازیِ نشست‌های کهنه',   24, 'deploy/session-clean.sh'],
        'reminders'  => ['ایمیلِ روزانه‌ی سررسید',    24, 'deploy/reminders.php'],
        'due-notify' => ['اعلانِ ساعتیِ سررسید',       1, 'deploy/due-notify.php'],
        // ⚠ هر دقیقه اجرا می‌شود ولی دوره‌اش اینجا ۱ ساعت نوشته شده، پس
        //   آستانه‌ی کهنگی ۳ ساعت می‌شود. با دوره‌ی واقعی (یک دقیقه) هر
        //   قطعیِ گذرای دو دقیقه‌ای یک هشدار می‌ساخت — و هشدارِ الکی از
        //   نبودِ هشدار بدتر است.
        'store-sync' => ['هم‌گام‌سازی سهم فروشگاه',    1, 'deploy/store-sync.php'],
        // ⚠ همان استدلالِ store-sync: هر دقیقه، ولی دوره ۱ ساعت نوشته شده.
        'push'       => ['اعلانِ گوشی (Web Push)',      1, 'deploy/push-send.php'],
        'rates'      => ['نرخِ ارز، طلا و سکه',         6, 'deploy/rates.php'],
        // ⛔ با HSTS، گواهیِ منقضی یعنی سایتِ بسته — پایشِ مرده باید دیده شود.
        'ssl'        => ['پایشِ گواهیِ SSL',            6, 'deploy/ssl-check.php'],
    ];

    /** پوشه‌ی نشانه‌ها — همان `var/` که `deploy.sh` نوشتنی نگه می‌دارد. */
    public static function dir(): string
    {
        return dirname(__DIR__) . '/var/cron';
    }

    public static function file(string $job): string
    {
        return self::dir() . '/' . $job . '.beat';
    }

    /** سقفِ کهنگی برای یک کار، به ثانیه. */
    public static function staleAfter(string $job): int
    {
        $hours = self::JOBS[$job][1] ?? 24;
        return ($hours * 2 + 1) * 3600;
    }

    /**
     * ثبتِ «همین حالا اجرا شد».
     *
     * ⛔ هیچ استثنایی پرتاب نمی‌کند: این کارِ جانبی است و نباید خودِ
     *    بکاپ یا یادآوری را بشکند — همان قاعده‌ی `recordNetWorthSnapshot()`.
     */
    public static function beat(string $job): bool
    {
        if (!isset(self::JOBS[$job])) { return false; }

        try {
            $dir = self::dir();
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) { return false; }
            return @file_put_contents(self::file($job), (string)time()) !== false;
        } catch (Throwable $e) {
            Log::error('cron.beat_failed', $e);
            return false;
        }
    }

    /**
     * وضعیتِ همه‌ی کارها.
     *
     * هر قلم: `['key','label','hours','script','at','age','state']` که
     * `state` یکی از `never` / `ok` / `stale` است.
     *
     * ⚠ «هرگز اجرا نشده» عمداً از «کهنه» جداست: اولی یعنی cron **نصب
     *   نشده** و دومی یعنی نصب شده و **از کار افتاده** — دو خرابیِ متفاوت
     *   با دو راهِ حلِ متفاوت، دقیقاً مثل «نرسید» در برابر «رد شد» در
     *   `backup-offsite.sh`.
     */
    public static function status(): array
    {
        $now = time();
        $out = [];

        foreach (self::JOBS as $key => [$label, $hours, $script]) {
            $at = null;
            $f  = self::file($key);
            if (is_readable($f)) {
                $raw = (int)trim((string)@file_get_contents($f));
                if ($raw > 0) { $at = $raw; }
            }

            $state = 'never';
            $age   = null;
            if ($at !== null) {
                $age   = max(0, $now - $at);
                $state = $age > self::staleAfter($key) ? 'stale' : 'ok';
            }

            $out[] = [
                'key' => $key, 'label' => $label, 'hours' => $hours,
                'script' => $script, 'at' => $at, 'age' => $age, 'state' => $state,
            ];
        }

        return $out;
    }

    /** فاصله‌ی خوانا («۳ ساعت پیش») — تاریخِ دقیق اینجا چیزی اضافه نمی‌کند. */
    public static function ago(?int $seconds): string
    {
        if ($seconds === null) { return '—'; }
        if ($seconds < 90)     { return 'همین حالا'; }
        if ($seconds < 5400)   { return toPersianDigits((string)(int)round($seconds / 60)) . ' دقیقه پیش'; }
        if ($seconds < 172800) { return toPersianDigits((string)(int)round($seconds / 3600)) . ' ساعت پیش'; }
        return toPersianDigits((string)(int)round($seconds / 86400)) . ' روز پیش';
    }
}
