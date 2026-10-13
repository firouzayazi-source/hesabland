<?php
/**
 * «بعد از ورود دیگه کاربر از اپ خارج نشه مگه اینکه خودش بخواد».
 *
 * ⛔ آزمون از راهِ HTTP و با **شبیه‌سازیِ بستنِ اپ** است: کوکیِ نشست
 *    `lifetime = 0` دارد و با بستنِ اپ روی گوشی می‌میرد، پس تنها چیزی که
 *    کاربر را نگه می‌دارد کوکیِ دستگاه (`daftar_device`) است. هر بررسی
 *    یک «جارِ» تازه می‌سازد که **فقط** آن کوکی را دارد و می‌پرسد: آیا
 *    خانه باز می‌شود یا به صفحه‌ی ورود می‌رویم؟
 *
 * دو خرابیِ واقعی که این تست برایشان نوشته شد:
 *   ۱. «این دستگاه را به خاطر بسپار» یک کلید بود؛ خاموش ماندنش یعنی با هر
 *      بستنِ اپ بیرون افتادن.
 *   ۲. تنظیم یا تغییرِ رمز در پروفایل `revokeAllAccessFor()` را صدا می‌زد
 *      که ردیفِ **همین** دستگاه را هم پاک می‌کرد؛ نشستِ جاری می‌ماند و
 *      روزها بعد، با اولین بستنِ اپ، کاربر بیرون می‌افتاد.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';
$root = realpath(__DIR__ . '/..');

T::group('ماندن در حساب — تا وقتی خودِ کاربر خارج شود');
if (!file_exists($root . '/config/config.php')) { T::blocked('ماندن در حساب', 'config/config.php وجود ندارد'); exit(T::report()); }
require_once $root . '/includes/db.php';
require_once $root . '/includes/user_data.php';
try { $pdo = Database::getConnection(); }
catch (Throwable $e) { T::blocked('ماندن در حساب', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }
if (!tableExists('trusted_devices')) { T::blocked('ماندن در حساب', 'جدول trusted_devices نیست'); exit(T::report()); }

$USER = ['stay_u', 'Stay#pass9'];
$serverPid = 0;
$jars = [];
$purge = function (string $username) use ($pdo) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $username]);
    $id = (int)$st->fetchColumn();
    if (!$id) { return; }
    try { deleteUserAccount($id); } catch (Throwable $e) {}
    try { $pdo->prepare('DELETE FROM users WHERE id = :i')->execute(['i' => $id]); } catch (Throwable $e) {}
};
$cleanup = function () use (&$serverPid, $purge, $USER, &$jars) {
    if ($serverPid) { @exec("kill $serverPid 2>/dev/null"); $serverPid = 0; }
    try { $purge($USER[0]); } catch (Throwable $e) {}
    foreach ($jars as $j) { @unlink($j); }
};

try {
    $purge($USER[0]);
    $pdo->prepare("INSERT INTO users (full_name, username, password_hash, role, is_active)
                   VALUES ('کاربر ماندگار', :u, :p, 'user', 1)")
        ->execute(['u' => $USER[0], 'p' => password_hash($USER[1], PASSWORD_DEFAULT)]);
    $uid = (int)$pdo->lastInsertId();
    try { $pdo->prepare('UPDATE users SET session_minutes = 0 WHERE id = :u')->execute(['u' => $uid]); } catch (Throwable $e) {}

    $port = 0;
    for ($p = 8931; $p <= 8949; $p++) {
        $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
        if ($sock) { fclose($sock); $port = $p; break; }
    }
    if (!$port) { T::skip('ماندن در حساب', 'پورت آزاد پیدا نشد'); $cleanup(); exit(T::report()); }
    $log = tempnam(sys_get_temp_dir(), 'stsrv');
    $serverPid = (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
        $port, escapeshellarg($root), escapeshellarg($log))));
    $up = false;
    for ($i = 0; $i < 40; $i++) {
        usleep(150000);
        $s = @fsockopen('127.0.0.1', $port, $a, $b, 0.3);
        if ($s) { fclose($s); $up = true; break; }
    }
    if (!$up) { T::blocked('ماندن در حساب', 'سرور آزمایشی بالا نیامد'); $cleanup(); exit(T::report()); }

    $newJar = function () use (&$jars): string { $j = tempnam(sys_get_temp_dir(), 'stjar'); $jars[] = $j; return $j; };
    $req = function (string $jar, string $path, ?array $post = null, array $hdr = []) use ($port): array {
        $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 25, CURLOPT_HTTPHEADER => $hdr]);
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        return [$code, $body];
    };
    $cookieOf = function (string $jar, string $name): string {
        foreach (explode("\n", (string)@file_get_contents($jar)) as $line) {
            $parts = preg_split('/\t/', trim($line));
            if (count($parts) >= 7 && $parts[5] === $name) { return $parts[6]; }
        }
        return '';
    };
    // «بستنِ اپ»: جارِ تازه‌ای که فقط کوکیِ دستگاه را دارد.
    $reopen = function (string $fromJar) use ($newJar, $cookieOf, $req): int {
        $dev = $cookieOf($fromJar, 'daftar_device');
        if ($dev === '') { return -1; }
        $j = $newJar();
        file_put_contents($j, "127.0.0.1\tFALSE\t/\tFALSE\t0\tdaftar_device\t{$dev}\n");
        [$code] = $req($j, 'index.php');
        return $code;
    };
    $devices = function () use ($pdo, $uid): int {
        $st = $pdo->prepare('SELECT COUNT(*) FROM trusted_devices WHERE user_id = :u');
        $st->execute(['u' => $uid]);
        return (int)$st->fetchColumn();
    };

    // ---- ۱) ورود بدونِ هیچ کلیدی، و بستن و باز کردنِ اپ ----
    $pdo->exec("DELETE FROM login_attempts WHERE request_ip = '127.0.0.1'");
    $A = $newJar();
    [, $html] = $req($A, 'login.php');
    T::ok(strpos($html, 'name="trust_device"') === false, 'صفحه‌ی ورود کلیدِ «به خاطر بسپار» ندارد (همیشه به خاطر می‌سپارد)');
    preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m);
    [$code] = $req($A, 'login.php', ['csrf_token' => $m[1] ?? '', 'username' => $USER[0], 'password' => $USER[1]]);
    T::ok($code === 302 || $code === 303, 'ورود');
    T::ok($cookieOf($A, 'daftar_device') !== '', 'ورود کوکیِ دستگاه را می‌گذارد، بی‌آنکه کلیدی زده شود');
    T::same(200, $reopen($A), 'بعد از بستن و باز کردنِ اپ، خانه باز می‌شود (نه صفحه‌ی ورود)');

    $csrfOf = function (string $jar) use ($req): string {
        [, $h] = $req($jar, 'profile.php');
        return preg_match('/<meta name="csrf-token" content="([^"]+)"/', $h, $mm) ? $mm[1] : '';
    };
    $ajax = ['X-Requested-With: XMLHttpRequest'];

    // ---- ۲) اولین رمز برای حسابِ بی‌رمز: هیچ دستگاهی بیرون نمی‌افتد ----
    $B = $newJar();   // «گوشیِ دوم»
    [, $html] = $req($B, 'login.php');
    preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m);
    $req($B, 'login.php', ['csrf_token' => $m[1] ?? '', 'username' => $USER[0], 'password' => $USER[1]]);
    T::same(2, $devices(), 'دو دستگاهِ مورد اعتماد');
    $pdo->prepare('UPDATE users SET password_hash = NULL WHERE id = :u')->execute(['u' => $uid]);
    [$code, $body] = $req($A, 'api/change_password.php', ['csrf_token' => $csrfOf($A),
        'current_password' => '', 'new_password' => 'Firs#t99', 'new_password_confirm' => 'Firs#t99'], $ajax);
    T::ok($code === 200 && strpos($body, '"success":true') !== false, 'تنظیمِ اولین رمز', $body);
    T::same(200, $reopen($A), 'اولین رمز: همین دستگاه بعد از بستنِ اپ وارد می‌ماند');
    T::same(200, $reopen($B), 'اولین رمز: گوشیِ دیگر هم بیرون نمی‌افتد (اعتبارنامه‌ای عوض نشد)');

    // ---- ۳) تغییرِ رمزِ موجود: بقیه بیرون، ولی همین دستگاه می‌ماند ----
    [$code, $body] = $req($A, 'api/change_password.php', ['csrf_token' => $csrfOf($A),
        'current_password' => 'Firs#t99', 'new_password' => 'Seco#nd99', 'new_password_confirm' => 'Seco#nd99'], $ajax);
    T::ok($code === 200 && strpos($body, '"success":true') !== false, 'تغییرِ رمز', $body);
    T::same(200, $reopen($A), 'تغییرِ رمز: همین دستگاه بعد از بستنِ اپ وارد می‌ماند');
    T::ok($reopen($B) !== 200, 'تغییرِ رمز: دستگاهِ دیگر باطل شد (امنیتِ تغییرِ رمز سرِ جایش است)');
    [$code] = $req($A, 'index.php');
    T::same(200, $code, 'تغییرِ رمز: نشستِ جاری هم زنده است');

    // ---- ۴) خروجِ خواسته‌ی کاربر: واقعاً بیرون ----
    $req($A, 'logout.php');
    T::ok($reopen($A) !== 200, 'بعد از «خروج»، باز کردنِ اپ به صفحه‌ی ورود می‌رود');
} finally {
    $cleanup();
}

exit(T::report());
