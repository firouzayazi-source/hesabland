<?php
/**
 * ⛔ رمزِ ایمیل هرگز در پیامِ خطای SMTP نمی‌آید.
 *
 * خرابیِ واقعی (مهر ۱۴۰۵): سرورِ ایمیل رمز را با «535» رد کرد و پیامِ خطا — که در
 * `app_errors`، لاگِ `var/log` و صفحه‌ی «خطاهای برنامه»ی مدیر می‌نشیند — **خطِ
 * base64ِ رمز** را خام داشت؛ پوشاندن فقط دستورِ «AUTH…» را می‌گرفت.
 *
 * یک سرورِ SMTPِ ساختگی (بی‌TLS) همان گفت‌وگو را بازی می‌کند و در هر مرحله رد می‌کند؛
 * `Mailer` در فرایندِ جدا با اعتبارنامه‌ی تصادفی اجرا می‌شود (ثابت‌های config پیش از
 * خودِ config تعریف می‌شوند) و پیام و لاگ برای هر شکلِ رمز و نامِ کاربری گشته می‌شوند.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
T::group('رمزِ ایمیل در خطای SMTP');

$root = dirname(__DIR__);
$tmp  = sys_get_temp_dir() . '/smtp_' . bin2hex(random_bytes(4));
@mkdir($tmp);

// سرورِ ساختگی: یک اتصال؛ رد در مرحله‌ی `$fail` (pass | user | rcpt)
file_put_contents("{$tmp}/server.php", <<<'PHP'
<?php
[$port, $fail, $echo] = [(int)$argv[1], $argv[2], $argv[3] === '1'];
$srv = stream_socket_server("tcp://127.0.0.1:{$port}", $e1, $e2);
if (!$srv) { exit(1); }
file_put_contents($argv[4], 'up');
$c = @stream_socket_accept($srv, 20);
if (!$c) { exit(1); }
$say = fn(string $l) => fwrite($c, $l . "\r\n");
$say('220 fake ESMTP');
$step = '';
while (($line = fgets($c)) !== false) {
    $line = rtrim($line, "\r\n");
    if ($step === 'user') {
        if ($fail === 'user') { $say('535 5.7.8 bad user ' . ($echo ? $line : '')); break; }
        $say('334 UGFzc3dvcmQ6'); $step = 'pass'; continue;
    }
    if ($step === 'pass') {
        // ⚠ `$echo`: بعضی سرورها ورودی را در پاسخ تکرار می‌کنند — پاک‌سازی باید آن را هم بگیرد
        $say('535 5.7.8 Incorrect authentication data' . ($echo ? ' ' . $line : '')); break;
    }
    if (stripos($line, 'EHLO') === 0) { $say('250-fake'); $say('250 AUTH LOGIN'); continue; }
    if (stripos($line, 'AUTH LOGIN') === 0) { $say('334 VXNlcm5hbWU6'); $step = 'user'; continue; }
    $say('500 what');
}
fclose($c);
PHP);

// فرایندِ Mailer: ثابت‌ها پیش از config (هشدارِ «already defined» پنهان)
file_put_contents("{$tmp}/send.php", <<<'PHP'
<?php
error_reporting(E_ALL & ~E_WARNING);
[$port, $user, $pass] = [(int)$argv[1], $argv[2], $argv[3]];
define('MAIL_METHOD', 'smtp'); define('MAIL_FROM', 'shop@example.com'); define('SMTP_HOST', '127.0.0.1');
define('SMTP_PORT', $port); define('SMTP_SECURE', 'none'); define('SMTP_USER', $user); define('SMTP_PASS', $pass);
define('SMTP_EHLO', 'localhost'); define('SMTP_TIMEOUT', 5);
require $argv[4] . '/includes/mailer.php';
$ok = Mailer::send('to@example.com', 'آزمون', '<p>سلام</p>');
echo json_encode(['ok' => $ok, 'error' => Mailer::$lastError], JSON_UNESCAPED_UNICODE);
PHP);

$logDir = "{$root}/var/log";
$logSize = function () use ($logDir): array {
    $o = [];
    foreach (glob("{$logDir}/*.log") ?: [] as $f) { $o[$f] = filesize($f); }
    return $o;
};
$run = function (string $fail, bool $echo) use ($tmp, $root, $logSize): array {
    $port = 0;
    for ($p = 9521; $p <= 9580; $p++) { $s = @stream_socket_server("tcp://127.0.0.1:{$p}"); if ($s) { fclose($s); $port = $p; break; } }
    $user = 'shop' . bin2hex(random_bytes(3)) . '@example.com';
    $pass = 'Pw@#' . bin2hex(random_bytes(6));
    $ready = "{$tmp}/ready_{$port}";
    @unlink($ready);
    $srv = proc_open(['php', "{$tmp}/server.php", (string)$port, $fail, $echo ? '1' : '0', $ready], [], $pp);
    for ($i = 0; $i < 50 && !is_file($ready); $i++) { usleep(100000); }
    $before = $logSize();
    $out = (string)shell_exec(sprintf('php %s %d %s %s %s 2>&1', escapeshellarg("{$tmp}/send.php"), $port,
        escapeshellarg($user), escapeshellarg($pass), escapeshellarg($root)));
    proc_close($srv);
    // فقط آنچه همین اجرا به لاگ افزود
    $logged = '';
    foreach ($logSize() as $f => $sz) {
        $from = $before[$f] ?? 0;
        if ($sz > $from) { $logged .= (string)file_get_contents($f, false, null, $from); }
    }
    $r = json_decode(trim($out), true);
    return [$r ?: ['ok' => null, 'error' => $out], $user, $pass, $logged];
};
$leaks = function (string $hay, string $user, string $pass): array {
    $found = [];
    foreach (['رمز' => $pass, 'رمزِ base64' => base64_encode($pass), 'نامِ کاربری' => $user,
              'نامِ کاربریِ base64' => base64_encode($user),
              // در JSONِ لاگ «/» و «+»ِ base64 فرار داده می‌شوند
              'رمزِ base64 در JSON' => substr(json_encode(base64_encode($pass)), 1, -1)] as $k => $v) {
        if ($v !== '' && str_contains($hay, $v)) { $found[] = $k; }
    }
    return $found;
};

foreach ([['pass', false, 'رد شدنِ رمز (۵۳۵)'], ['pass', true, 'رد شدنِ رمز، سرور ورودی را تکرار می‌کند'],
          ['user', true, 'رد شدنِ نامِ کاربری، سرور ورودی را تکرار می‌کند']] as [$fail, $echo, $what]) {
    [$r, $user, $pass, $logged] = $run($fail, $echo);
    T::ok($r['ok'] === false && str_contains((string)$r['error'], '535'), "{$what}: ارسال شکست خورد و کدِ ۵۳۵ گفته شد", (string)$r['error']);
    T::same([], $leaks((string)$r['error'], $user, $pass), "⛔ {$what}: پیامِ خطا هیچ شکلی از رمز و نامِ کاربری ندارد");
    T::same([], $leaks($logged, $user, $pass), "⛔ {$what}: لاگِ var/log هم ندارد");
    T::ok($logged === '' || str_contains($logged, 'mailer.send_failed'), "{$what}: خطا در لاگ ثبت شد (برای پیدا کردن)");
}
[$r] = $run('pass', false);
T::ok(str_contains((string)$r['error'], '«رمز»') && str_contains((string)$r['error'], 'mail-setup.sh'),
    'پیام می‌گوید کدام مرحله (رمز) رد شد و راهِ درست کردنش چیست', (string)$r['error']);

array_map('unlink', glob("{$tmp}/*") ?: []);
@rmdir($tmp);
exit(T::report());
