<?php
/**
 * گرفتنِ یک آدرسِ بیرونی با سدِ SSRF — مشترکِ طرفِ شخصی و فروشگاه.
 *
 * ⛔ از `biz_io.php` (ورودِ کالا از سایت) به اینجا آمد تا نرخِ ارز و طلا
 *    (`includes/rates.php`) هم از همین سد بگذرد بی‌آنکه طرفِ شخصی لایه‌ی
 *    فروشگاه را بار کند (قاعده ۷۰). `BizFetch` همان است (`extends SafeFetch`)،
 *    پس رفتار و تست‌های ورودِ کالا دست نخوردند.
 */

class SafeFetch
{
    /**
     * ⛔ فقط برای تستِ خطِ فرمان (سرورِ ساختگی روی 127.0.0.1). از وب هرگز
     *    گذاشته نمی‌شود و بیرون از `cli` هم خوانده نمی‌شود.
     */
    public static bool $allowLoopbackForTests = false;

    public const MAX_BYTES = 5 * 1024 * 1024;
    private const TIMEOUT = 15;
    private const MAX_REDIRECTS = 3;

    /**
     * ⛔ مهلتِ **کل** (زمانِ یونیکس) برای همه‌ی درخواست‌های یک کار — نه فقط هر
     *    درخواست. ورود از سایت تا ۲۱ صفحه می‌خواند، هر کدام با ۳ ریدایرکت و
     *    مهلتِ ۱۵ ثانیه؛ زمانِ انتظارِ شبکه هم جزوِ مهلتِ اجرای PHP نیست. پس
     *    یک سایتِ عمداً کند می‌توانست یک کارگرِ PHP را تا بیست دقیقه نگه دارد
     *    و با چند نشست همه‌ی کارگرها را — سایت برای همه‌ی فروشگاه‌ها می‌خوابید
     *    (بازرسیِ مهر ۱۴۰۵). `null` = بی‌مهلتِ کل (فقط مهلتِ هر درخواست).
     */
    public static ?int $deadline = null;

    /**
     * ⛔ آدرس را می‌سنجد و IP ای را که باید به آن وصل شد برمی‌گرداند. هر
     *    نشانیِ داخلی (loopback، شبکه‌ی خصوصی، link-local، CGNAT، رزرو)
     *    رد می‌شود — وگرنه این فرم ابزاری بود برای خواندنِ سرویس‌های داخلیِ
     *    همین سرور (مثلِ حسابداریِ فروشگاه روی 127.0.0.1).
     * @return array{ok:bool, message?:string, host?:string, port?:int, ip?:string, url?:string}
     */
    public static function check(string $url): array
    {
        $url = trim($url);
        $u = parse_url($url);
        if (!$u || !isset($u['scheme'], $u['host']) || !in_array(strtolower($u['scheme']), ['http', 'https'], true)) {
            return ['ok' => false, 'message' => 'آدرس باید با http:// یا https:// شروع شود.'];
        }
        if (isset($u['user']) || isset($u['pass'])) {
            return ['ok' => false, 'message' => 'آدرسِ دارای نام کاربری و رمز پذیرفته نمی‌شود.'];
        }
        $scheme = strtolower($u['scheme']);
        $port = (int)($u['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (!in_array($port, [80, 443], true) && !self::loopbackOk()) {
            return ['ok' => false, 'message' => 'فقط درگاهِ استانداردِ وب (۸۰ و ۴۴۳) پذیرفته می‌شود.'];
        }
        $host = strtolower(trim($u['host'], '[]'));
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return ['ok' => false, 'message' => 'آدرسِ IPv6 پشتیبانی نمی‌شود؛ نامِ دامنه را بنویسید.'];
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (@gethostbynamel($host) ?: []);
        if (!$ips) { return ['ok' => false, 'message' => 'دامنه‌ی «' . $host . '» پیدا نشد.']; }
        foreach ($ips as $ip) {
            if (!self::publicIp($ip)) {
                return ['ok' => false, 'message' => 'این آدرس به شبکه‌ی داخلی اشاره می‌کند و پذیرفته نمی‌شود.'];
            }
        }
        return ['ok' => true, 'host' => $host, 'port' => $port, 'ip' => $ips[0], 'url' => $url];
    }

    private static function loopbackOk(): bool
    {
        return self::$allowLoopbackForTests && PHP_SAPI === 'cli';
    }

    public static function publicIp(string $ip): bool
    {
        if (self::loopbackOk() && $ip === '127.0.0.1') { return true; }
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        $n = ip2long($ip);
        foreach ([['0.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16], ['192.0.0.0', 24],
                  ['198.18.0.0', 15], ['224.0.0.0', 4], ['240.0.0.0', 4]] as [$net, $bits]) {
            $mask = -1 << (32 - $bits);
            if (($n & $mask) === (ip2long($net) & $mask)) { return false; }
        }
        return true;
    }

    /**
     * ⛔ IP ای که `check()` سنجید سنجاق می‌شود (`CURLOPT_RESOLVE`)، پس
     *    DNS rebinding بینِ سنجش و اتصال ممکن نیست؛ ریدایرکت دستی دنبال و
     *    **هر بار** دوباره سنجیده می‌شود.
     * @return array{ok:bool, body?:string, type?:string, code?:int, message?:string, url?:string}
     */
    public static function get(string $url, int $max = self::MAX_BYTES): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'message' => 'افزونه‌ی curl روی سرور نیست؛ فایل را دانلود و بارگذاری کنید.'];
        }
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $left = self::$deadline !== null ? self::$deadline - time() : self::TIMEOUT;
            if ($left <= 0) { return ['ok' => false, 'message' => 'سایت بیش از حد کند جواب داد؛ دریافت متوقف شد.']; }
            $c = self::check($url);
            if (!$c['ok']) { return $c; }
            $body = '';
            $tooBig = false;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RESOLVE        => [$c['host'] . ':' . $c['port'] . ':' . $c['ip']],
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => min(8, $left),
                CURLOPT_TIMEOUT        => min(self::TIMEOUT, $left),
                // سایتی که قطره‌قطره می‌فرستد (کمتر از ۱ کیلوبایت در ثانیه، ۱۰ ثانیه) رها می‌شود
                CURLOPT_LOW_SPEED_LIMIT => 1024,
                CURLOPT_LOW_SPEED_TIME  => 10,
                CURLOPT_NOPROXY        => '*',
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; HesabLand/1.0)',
                CURLOPT_HTTPHEADER     => ['Accept: application/json, text/csv, application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, */*;q=0.5'],
                CURLOPT_ENCODING       => '',
                CURLOPT_WRITEFUNCTION  => static function ($h, string $chunk) use (&$body, &$tooBig, $max): int {
                    if (strlen($body) + strlen($chunk) > $max) { $tooBig = true; return 0; }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            $okRun = curl_exec($ch);
            $code  = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $type  = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $loc   = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            $err   = curl_error($ch);
            unset($ch);   // ⛔ نه `curl_close()` — از PHP 8.0 بی‌اثر و در 8.5 منسوخ
            if ($tooBig) { return ['ok' => false, 'message' => 'پاسخِ سایت بیش از ۵ مگابایت است.']; }
            if ($okRun === false) { return ['ok' => false, 'message' => 'به سایت وصل نشد (' . $err . ').']; }
            if ($code >= 300 && $code < 400 && $loc !== '') { $url = $loc; continue; }
            return ['ok' => $code >= 200 && $code < 300, 'body' => $body, 'type' => $type, 'code' => $code, 'url' => $url,
                    'message' => $code >= 200 && $code < 300 ? '' : 'سایت پاسخِ ' . $code . ' داد.'];
        }
        return ['ok' => false, 'message' => 'سایت بیش از حد ریدایرکت کرد.'];
    }
}
