<?php
/**
 * ارسال ایمیل — بدون هیچ کتابخانه‌ی خارجی.
 *
 * پروژه عمداً Composer ندارد، پس PHPMailer و مشابهش در دسترس نیستند.
 * دو راه پشتیبانی می‌شود و در config.php انتخاب می‌شود:
 *
 *   MAIL_METHOD = 'mail'  → تابع mail() خود PHP.
 *                           ساده، ولی روی VPS تازه معمولاً یا MTA نصب
 *                           نیست یا ایمیل به اسپم می‌رود.
 *   MAIL_METHOD = 'smtp'  → اتصال مستقیم به یک سرور SMTP.
 *                           قابل اتکاتر، چون از دامنه‌ای می‌فرستد که
 *                           سابقه‌ی ارسال دارد.
 *
 * اگر هیچ‌کدام تنظیم نشده باشد، Mailer::isConfigured() نادرست برمی‌گرداند
 * و صفحه‌ی بازیابی به‌جای وانمود کردن، صادقانه می‌گوید در دسترس نیست.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/outbound.php';

class Mailer
{
    /** آخرین خطا — برای لاگ، نه برای نمایش به کاربر */
    public static string $lastError = '';

    public static function isConfigured(): bool
    {
        $method = defined('MAIL_METHOD') ? MAIL_METHOD : '';
        if ($method === 'mail') {
            return function_exists('mail') && defined('MAIL_FROM') && MAIL_FROM !== '';
        }
        if ($method === 'smtp') {
            return defined('SMTP_HOST') && SMTP_HOST !== ''
                && defined('MAIL_FROM') && MAIL_FROM !== '';
        }
        return false;
    }

    public static function send(string $to, string $subject, string $htmlBody, string $textBody = ''): bool
    {
        self::$lastError = '';

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            self::$lastError = 'آدرس گیرنده معتبر نیست.';
            return false;
        }
        if (!self::isConfigured()) {
            self::$lastError = 'ارسال ایمیل تنظیم نشده است (MAIL_METHOD در config.php).';
            return false;
        }
        if ($textBody === '') {
            $textBody = self::htmlToText($htmlBody);
        }

        try {
            return MAIL_METHOD === 'smtp'
                ? self::sendSmtp($to, $subject, $htmlBody, $textBody)
                : self::sendMailFunc($to, $subject, $htmlBody, $textBody);
        } catch (Throwable $e) {
            self::$lastError = $e->getMessage();
            Log::error('mailer.send_failed', $e);
            return false;
        }
    }

    // ---------------------------------------------------------------

    /**
     * ساخت نسخه‌ی متنی از HTML.
     *
     * دو نکته که strip_tags تنها از پس‌شان برنمی‌آید:
     *   ۱. تگ‌های بلوکی باید به خط تازه تبدیل شوند، وگرنه جمله‌ها به هم
     *      می‌چسبند («سلام فیروزبرای تغییر رمز…»).
     *   ۲. آدرس لینک‌ها باید بماند. در ایمیل بازیابی رمز، اگر کلاینت
     *      کاربر HTML را نشان ندهد و آدرس هم نباشد، ایمیل بی‌فایده است.
     */
    public static function htmlToText(string $html): string
    {
        // آدرس لینک را کنار متنش بگذار
        $html = preg_replace_callback(
            '#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is',
            function ($m) {
                $label = trim(strip_tags($m[2]));
                $url   = trim($m[1]);
                return ($label === '' || $label === $url) ? $url : "$label: $url";
            },
            $html
        );
        $html = preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = preg_replace('#</(p|div|li|h[1-6]|tr|blockquote)\s*>#i', "\n\n", $html);
        $html = preg_replace('#<li\b[^>]*>#i', '• ', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim($text);
    }

    private static function fromName(): string
    {
        return defined('MAIL_FROM_NAME') && MAIL_FROM_NAME !== ''
            ? MAIL_FROM_NAME
            : (defined('APP_NAME') ? APP_NAME : 'حساب لند');
    }

    /** سرآیند غیر-ASCII باید طبق RFC 2047 کدگذاری شود، وگرنه عنوان فارسی خراب می‌رسد */
    private static function encodeHeader(string $text): string
    {
        return preg_match('/[\x80-\xFF]/', $text)
            ? '=?UTF-8?B?' . base64_encode($text) . '?='
            : $text;
    }

    /** بدنه‌ی چندبخشی: هم متن ساده هم HTML، تا در هر کلاینتی خوانده شود */
    private static function buildBody(string $html, string $text, string $boundary): string
    {
        $nl = "\r\n";
        return "--$boundary$nl"
            . "Content-Type: text/plain; charset=UTF-8$nl"
            . "Content-Transfer-Encoding: base64$nl$nl"
            . chunk_split(base64_encode($text)) . $nl
            . "--$boundary$nl"
            . "Content-Type: text/html; charset=UTF-8$nl"
            . "Content-Transfer-Encoding: base64$nl$nl"
            . chunk_split(base64_encode($html)) . $nl
            . "--$boundary--$nl";
    }

    private static function sendMailFunc(string $to, string $subject, string $html, string $text): bool
    {
        // ⛔ `mail()` همیشه به MTAِ واقعیِ سیستم می‌رود — در اجرای تست‌ها هرگز
        if (Outbound::noSend()) { throw new RuntimeException('ایمیل در اجرای تست‌ها فرستاده نمی‌شود (' . Outbound::ENV . '=1).'); }
        $boundary = 'b' . bin2hex(random_bytes(12));
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'From: ' . self::encodeHeader(self::fromName()) . ' <' . MAIL_FROM . '>',
            'Reply-To: ' . MAIL_FROM,
            'X-Mailer: hesabland',
        ];
        $okSent = @mail($to, self::encodeHeader($subject),
            self::buildBody($html, $text, $boundary), implode("\r\n", $headers));
        if (!$okSent) {
            self::$lastError = 'تابع mail() ناموفق بود — احتمالاً MTA روی سرور نصب نیست.';
        }
        return $okSent;
    }

    // ---------------------------------------------------------------
    // کلاینت کوچک SMTP. فقط همان چیزی را پیاده می‌کند که برای فرستادن
    // یک ایمیل لازم است: EHLO، STARTTLS، AUTH LOGIN، MAIL/RCPT/DATA.

    private static function sendSmtp(string $to, string $subject, string $html, string $text): bool
    {
        $host    = SMTP_HOST;
        // ⛔ اجرای تست‌ها: هیچ ایمیلی به SMTPِ واقعی نمی‌رود (فقط سرورِ ساختگیِ محلی)
        if (($e = Outbound::blockedFor($host, 'ایمیل')) !== null) { throw new RuntimeException($e); }
        $port    = defined('SMTP_PORT') ? (int)SMTP_PORT : 587;
        $secure  = defined('SMTP_SECURE') ? strtolower(SMTP_SECURE) : 'tls'; // tls | ssl | none
        $user    = defined('SMTP_USER') ? SMTP_USER : '';
        $pass    = defined('SMTP_PASS') ? SMTP_PASS : '';
        $timeout = defined('SMTP_TIMEOUT') ? (int)SMTP_TIMEOUT : 20;

        $dsn = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $fp  = @stream_socket_client($dsn, $errno, $errstr, $timeout,
            STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            throw new RuntimeException("اتصال به $dsn برقرار نشد: $errstr ($errno)");
        }
        stream_set_timeout($fp, $timeout);

        $read = function () use ($fp): array {
            $lines = [];
            while (($line = fgets($fp, 1024)) !== false) {
                $lines[] = rtrim($line, "\r\n");
                // خط آخر پاسخ چندخطی، بعد از کد فاصله دارد نه خط تیره
                if (strlen($line) < 4 || $line[3] !== '-') { break; }
            }
            $last = end($lines) ?: '';
            return [(int)substr($last, 0, 3), implode(' | ', $lines)];
        };
        // ⛔ نامِ کاربری و رمز هرگز در پیامِ خطا نمی‌آیند — این پیام در `app_errors`، لاگِ
        //    `var/log` و صفحه‌ی «خطاهای برنامه» می‌نشیند. پیش از این فقط دستورِ «AUTH…» پوشانده
        //    می‌شد و خطِ بعدی (رمزِ base64) **خام** ذخیره می‌شد؛ با «535» رمزِ ایمیل روی صفحه‌ی
        //    مدیر دیده شد (مهر ۱۴۰۵). حالا هر خطِ اعتبارنامه برچسبِ خودش را دارد (`$label`)، و
        //    هر پیام پیش از پرتاب از هر شکلِ رمز و نامِ کاربری پاک می‌شود (`$scrub`) — حتی اگر
        //    سرورِ ایمیل خودش آن را در پاسخ تکرار کند.
        $secrets = array_values(array_filter([$pass, base64_encode($pass), $user, base64_encode($user)], fn($v) => strlen($v) >= 3));
        usort($secrets, fn($a, $b) => strlen($b) <=> strlen($a));
        $scrub = fn(string $m): string => $secrets ? str_replace($secrets, '***', $m) : $m;
        $cmd = function (string $c, array $expect, ?string $label = null) use ($fp, $read, $scrub): string {
            if ($c !== '') { fwrite($fp, $c . "\r\n"); }
            [$code, $msg] = $read();
            if (!in_array($code, $expect, true)) {
                $shown = $label ?? ($c !== '' ? preg_replace('/^(AUTH|.*PASS).*/i', '$1 …', $c) : '(اتصال)');
                throw new RuntimeException($scrub("SMTP: پاسخ $code به «{$shown}» — $msg")
                    . ($code === 535 ? ' (نام کاربری یا رمزِ ایمیل در config.php درست نیست — deploy/mail-setup.sh)' : ''));
            }
            return $msg;
        };

        try {
            $cmd('', [220]);
            $ehloName = defined('SMTP_EHLO') && SMTP_EHLO !== '' ? SMTP_EHLO : 'localhost';
            $cmd('EHLO ' . $ehloName, [250]);

            if ($secure === 'tls') {
                $cmd('STARTTLS', [220]);
                // خطای واقعی OpenSSL را نگه می‌داریم؛ بدون آن پیام «TLS
                // ناموفق بود» هیچ سرنخی نمی‌دهد. شایع‌ترین علت روی
                // هاست‌های اشتراکی این است که گواهی برای نام میزبان
                // واقعی سرور صادر شده نه mail.<دامنه>.
                $tlsError = '';
                set_error_handler(function ($no, $str) use (&$tlsError) {
                    $tlsError = $str;
                    return true;
                });
                $okTls = stream_socket_enable_crypto($fp, true,
                    STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
                restore_error_handler();
                if (!$okTls) {
                    $hint = '';
                    if (stripos($tlsError, 'certificate') !== false || stripos($tlsError, 'verify') !== false) {
                        $hint = ' — گواهی این سرور با نام «' . $host . '» نمی‌خواند.'
                              . ' نام میزبان واقعی سرور ایمیل را در SMTP_HOST بگذارید'
                              . ' (همان نامی که در بنر ESMTP دیده می‌شود).';
                    }
                    throw new RuntimeException('SMTP: برقراری TLS ناموفق بود'
                        . ($tlsError !== '' ? ': ' . $tlsError : '.') . $hint);
                }
                $cmd('EHLO ' . $ehloName, [250]);   // بعد از TLS باید دوباره EHLO داد
            }

            if ($user !== '') {
                $cmd('AUTH LOGIN', [334]);
                $cmd(base64_encode($user), [334], 'نام کاربری');
                $cmd(base64_encode($pass), [235], 'رمز');
            }

            $cmd('MAIL FROM:<' . MAIL_FROM . '>', [250]);
            $cmd('RCPT TO:<' . $to . '>', [250, 251]);
            $cmd('DATA', [354]);

            $boundary = 'b' . bin2hex(random_bytes(12));
            $headers =
                'Date: ' . date('r') . "\r\n"
                . 'From: ' . self::encodeHeader(self::fromName()) . ' <' . MAIL_FROM . ">\r\n"
                . 'To: <' . $to . ">\r\n"
                . 'Subject: ' . self::encodeHeader($subject) . "\r\n"
                . 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $ehloName . ">\r\n"
                . "MIME-Version: 1.0\r\n"
                . 'Content-Type: multipart/alternative; boundary="' . $boundary . "\"\r\n\r\n";

            $body = self::buildBody($html, $text, $boundary);
            // خطی که با نقطه شروع شود باید dot-stuff شود، وگرنه پیام نصفه می‌شود
            $body = preg_replace('/^\./m', '..', $body);

            fwrite($fp, $headers . $body . "\r\n.\r\n");
            [$code, $msg] = $read();
            if ($code !== 250) { throw new RuntimeException("SMTP: پیام پذیرفته نشد ($code) — $msg"); }

            @fwrite($fp, "QUIT\r\n");
            fclose($fp);
            return true;
        } catch (Throwable $e) {
            @fclose($fp);
            throw $e;
        }
    }
}
