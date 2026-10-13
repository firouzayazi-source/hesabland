<?php
/**
 * ⛔ کلیدِ «هیچ پیامی بیرون نرود» برای اجرای تست‌ها — و خواندنِ سرآیندِ پاسخِ HTTP.
 *
 * `HESAB_NO_SEND=1` (که `tests/run.sh` همیشه می‌گذارد و به هر فرایندِ فرزند و `php -S`
 * می‌رسد) هر پیامک، ایمیل و اعلانِ گوشی را **پیش از اتصال** رد می‌کند، مگر مقصد خودِ همین
 * ماشین باشد (سرورِ ساختگیِ تست‌ها: `127.0.0.1`، `localhost`، `::1`).
 *
 * چرا لازم شد (انتقال به سرورِ تازه، مهر ۱۴۰۵): روشِ پیامک از `app_settings` هم خوانده
 * می‌شود (`smsSetting()`)، و تست‌ها فقط ثابتِ `SMS_METHOD`ِ config را می‌سنجیدند — روی
 * دیتابیسی که از بکاپِ سایتِ اصلی بالا آمده، پنلِ واقعی و پیامکِ واقعی بود. ایمیل
 * (`PasswordReset`) هم با SMTPِ واقعیِ config می‌رفت. یک سد در **لایه‌ی ارسال** همه را
 * می‌گیرد، هر تستی که فردا نوشته شود هم.
 *
 * ⚠ روی وب (`php-fpm`) این متغیر هرگز تعریف نیست؛ هیچ ورودیِ کاربری به آن نمی‌رسد.
 */

final class Outbound
{
    public const ENV = 'HESAB_NO_SEND';

    public static function noSend(): bool
    {
        return getenv(self::ENV) === '1';
    }

    /** مقصد (نامِ میزبان یا آدرسِ کامل) همین ماشین است؟ */
    public static function isLoopback(string $hostOrUrl): bool
    {
        $h = str_contains($hostOrUrl, '://') ? (string)parse_url($hostOrUrl, PHP_URL_HOST) : $hostOrUrl;
        $h = strtolower(trim($h, '[] '));
        if ($h === 'localhost' || $h === '::1') { return true; }
        return (bool)preg_match('/^127(\.\d{1,3}){3}$/', $h);
    }

    /** پیامِ ردِ ارسال در حالتِ تست، یا `null` وقتی آزاد است. */
    public static function blockedFor(string $hostOrUrl, string $what): ?string
    {
        if (!self::noSend() || self::isLoopback($hostOrUrl)) { return null; }
        return $what . ' در اجرای تست‌ها فرستاده نمی‌شود (' . self::ENV . '=1).';
    }

    /**
     * سرآیندهای آخرین پاسخِ `file_get_contents()`. ⛔ متغیرِ `$http_response_header` در PHP 8.5
     * منسوخ است؛ از ۸٫۴ به بعد `http_get_last_response_headers()`، و پیش از آن همان متغیر از
     * محدوده‌ی فراخواننده (`get_defined_vars()`، بی‌آنکه نامش در کد بیاید).
     * @param array $callerVars `get_defined_vars()`ِ فراخواننده
     * @return list<string>
     */
    public static function responseHeaders(array $callerVars): array
    {
        if (function_exists('http_get_last_response_headers')) {
            return http_get_last_response_headers() ?? [];
        }
        return (array)($callerVars['http_response_header'] ?? []);
    }
}
