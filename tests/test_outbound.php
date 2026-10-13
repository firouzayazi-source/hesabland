<?php
/**
 * ⛔ در اجرای تست‌ها هیچ پیامک، ایمیل یا اعلانی بیرون نمی‌رود (`includes/outbound.php`).
 *
 * سه لایه‌ی ارسال (`Sms::post()`، `Mailer::sendSmtp()`/`sendMailFunc()`، `Push::sendRaw()`)
 * هر کدام در فرایندِ جدا و با مقصدِ **غیرمحلی** آزموده می‌شوند: با `HESAB_NO_SEND=1` باید
 * فوری و بی‌اتصال رد شوند، و بی‌آن همان مسیرِ همیشگی (که این‌جا به شبکه نمی‌رسد) را بروند.
 * مقصدِ ایمیل `203.0.113.5` است (TEST-NET، هرگز پاسخ نمی‌دهد): اتصالِ واقعی تا مهلتِ ۵
 * ثانیه می‌ماند، پس «زیرِ یک ثانیه با پیامِ سد» یعنی واقعاً وصل نشد.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/../includes/outbound.php';
$root = dirname(__DIR__);

T::group('۱ — مقصدِ محلی');
foreach (['127.0.0.1' => true, 'localhost' => true, '::1' => true, '[::1]' => true, '127.5.6.7' => true,
          'http://127.0.0.1:9000/x' => true, 'smtp.gmail.com' => false, '203.0.113.5' => false,
          'https://api.kavenegar.com/v1' => false, 'https://fcm.googleapis.com/fcm/send/x' => false,
          '127.0.0.1.evil.com' => false, 'http://localhost.evil.com/' => false] as $h => $want) {
    T::same($want, Outbound::isLoopback($h), "«{$h}» " . ($want ? 'محلی است' : 'محلی نیست'));
}

T::group('۲ — هر سه لایه‌ی ارسال، در فرایندِ جدا');
$tmp = sys_get_temp_dir() . '/outb_' . bin2hex(random_bytes(4));
@mkdir($tmp);
file_put_contents("{$tmp}/mail.php", <<<'PHP'
<?php
error_reporting(E_ALL & ~E_WARNING);
define('MAIL_METHOD', $argv[2]); define('MAIL_FROM', 'shop@example.com'); define('SMTP_HOST', '203.0.113.5');
define('SMTP_PORT', 587); define('SMTP_SECURE', 'none'); define('SMTP_USER', ''); define('SMTP_PASS', '');
define('SMTP_EHLO', 'localhost'); define('SMTP_TIMEOUT', 5);
require $argv[1] . '/includes/mailer.php';
$t = microtime(true);
$ok = Mailer::send('to@example.com', 'x', '<p>x</p>');
echo json_encode(['ok' => $ok, 'err' => Mailer::$lastError, 'sec' => microtime(true) - $t], JSON_UNESCAPED_UNICODE);
PHP);
file_put_contents("{$tmp}/sms.php", <<<'PHP'
<?php
error_reporting(E_ALL & ~E_WARNING);
define('SMS_METHOD', 'kavenegar'); define('SMS_API_KEY', 'fake-key-for-test'); define('SMS_SENDER', '1000');
require $argv[1] . '/includes/db.php';
require $argv[1] . '/includes/sms.php';
$ok = Sms::send('09120000000', 'x');
echo json_encode(['ok' => $ok, 'err' => Sms::$lastError], JSON_UNESCAPED_UNICODE);
PHP);
file_put_contents("{$tmp}/push.php", <<<'PHP'
<?php
error_reporting(E_ALL & ~E_WARNING);
require $argv[1] . '/includes/push.php';
$t = microtime(true);
$code = Push::sendRaw($argv[2], 'x', 'vapid t=x');
echo json_encode(['code' => $code, 'sec' => microtime(true) - $t]);
PHP);
$run = function (string $script, array $args, bool $noSend) use ($tmp, $root): array {
    $env = $noSend ? 'HESAB_NO_SEND=1' : 'HESAB_NO_SEND=';
    $cmd = $env . ' php ' . escapeshellarg("{$tmp}/{$script}") . ' ' . escapeshellarg($root)
         . implode('', array_map(fn($a) => ' ' . escapeshellarg($a), $args)) . ' 2>/dev/null';
    return json_decode(trim((string)shell_exec($cmd)), true) ?: ['raw' => true];
};

$m = $run('mail.php', ['smtp'], true);
T::ok(($m['ok'] ?? null) === false && str_contains((string)($m['err'] ?? ''), 'HESAB_NO_SEND') && ($m['sec'] ?? 9) < 1,
    '⛔ ایمیل (SMTP) به میزبانِ بیرونی: فوری رد، بی‌اتصال', json_encode($m, JSON_UNESCAPED_UNICODE));
$m = $run('mail.php', ['mail'], true);
T::ok(($m['ok'] ?? null) === false && str_contains((string)($m['err'] ?? ''), 'HESAB_NO_SEND'),
    '⛔ ایمیل با `mail()`ِ سیستم: رد', json_encode($m, JSON_UNESCAPED_UNICODE));
$m = $run('mail.php', ['smtp'], false);
T::ok(!str_contains((string)($m['err'] ?? ''), 'HESAB_NO_SEND'), 'بی‌کلید همان مسیرِ همیشگی (این‌جا به شبکه نمی‌رسد)', json_encode($m, JSON_UNESCAPED_UNICODE));

$s = $run('sms.php', [], true);
T::ok(($s['ok'] ?? null) === false && str_contains((string)($s['err'] ?? ''), 'HESAB_NO_SEND'),
    '⛔ پیامک به پنلِ واقعی (کاوه‌نگار): رد پیش از درخواست', json_encode($s, JSON_UNESCAPED_UNICODE));

$p = $run('push.php', ['https://fcm.googleapis.com/fcm/send/test-endpoint'], true);
T::ok(($p['code'] ?? -1) === 0 && ($p['sec'] ?? 9) < 0.5, '⛔ اعلانِ گوشی به سرویسِ مرورگر: رد، بی‌درخواست', json_encode($p));

T::group('۳ — اجرای تست‌ها همیشه با کلید');
$sh = (string)file_get_contents("{$root}/tests/run.sh");
T::ok(str_contains($sh, 'export HESAB_NO_SEND=1'), 'tests/run.sh کلید را برای همه‌ی فرایندها می‌گذارد');
T::ok(str_contains($sh, '/opt/hesab/app') && str_contains($sh, 'HESAB_TESTS_ON_MAIN'), 'و روی پوشه‌ی سایتِ اصلی اجرا نمی‌شود');

array_map('unlink', glob("{$tmp}/*") ?: []);
@rmdir($tmp);
exit(T::report());
