<?php
/**
 * ⛔ تصویرِ شخصی در فروشگاه — بالا-چپِ پوسته جای حرفِ اولِ نام.
 *
 * سه لایه، و هر سه لازم:
 *   ۱. **تابع** (`saveUserAvatar()`/`deleteUserAvatar()`/`avatarUrl()` در
 *      `includes/avatar.php`) — تنها مسیرِ ذخیره، مشترک با پروفایلِ حساب لند.
 *      نوع از محتوا، بازکشی با GD (دنباله‌ی چسبیده به فایل می‌رود)، نامِ
 *      تصادفی، و `avatarUrl()` هیچ نامِ دست‌کاری‌شده‌ای را به مسیر نمی‌رساند.
 *   ۲. **HTTP با نشستِ واقعی** — بی‌CSRF هیچ‌کار، فایلِ جعلی با پیامِ روشن
 *      رد، تصویرِ درست روی **هر** صفحه‌ی فروشگاه بالا-چپ، حذف ← همان حرف،
 *      و فروشگاهِ دیگر تصویرِ این یکی را نمی‌بیند.
 *   ۳. **کرومیوم زیرِ CSPِ تولید** (`store_avatar_probe.js`) — کوچک‌سازیِ
 *      سمتِ مرورگر (JPEGِ کوچک به‌جای عکسِ درشت) و بار شدنِ واقعیِ تصویر.
 *
 * ⚠ کرومیوم/node اختیاری‌اند (`T::skip`)؛ دیتابیس و GD لازم‌اند.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

$root = realpath(__DIR__ . '/..');
T::group('تصویرِ شخصیِ فروشگاه — تابعِ مشترک');

if (!file_exists($root . '/config/config.php')) { T::blocked('تصویرِ فروشگاه', 'config/config.php وجود ندارد'); exit(T::report()); }
if (!function_exists('imagecreatetruecolor')) { T::skip('تصویرِ فروشگاه', 'افزونه‌ی gd نیست'); exit(T::report()); }

require_once $root . '/includes/db.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/user_data.php';
require_once $root . '/includes/signup.php';
require_once $root . '/includes/avatar.php';
try { $pdo = Database::getConnection(); }
catch (Throwable $e) { T::blocked('تصویرِ فروشگاه', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }
if (!usersHaveColumn($pdo, 'avatar')) { T::blocked('تصویرِ فروشگاه', 'ستون users.avatar نیست (migration_p4)'); exit(T::report()); }
if (!Biz::available()) { T::blocked('تصویرِ فروشگاه', 'ستونِ users.account_type نیست'); exit(T::report()); }

$A = ['__stav_a', 'StAv#probe9'];
$B = ['__stav_b', 'StAv#probe8'];
$dir = sys_get_temp_dir() . '/stav_' . getmypid();
@mkdir($dir, 0700, true);
$serverPid = 0;
$jars = [];

$purge = function (string $username) use ($pdo, $root) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $username]);
    $id = (int)$st->fetchColumn();
    if (!$id) { return; }
    foreach (glob($root . '/uploads/avatars/u' . $id . '_*') ?: [] as $f) { @unlink($f); }
    try { deleteUserAccount($id); } catch (Throwable $e) {}
    try { $pdo->prepare('DELETE FROM users WHERE id = :i')->execute(['i' => $id]); } catch (Throwable $e) {}
};
$cleanup = function () use (&$serverPid, $purge, $A, $B, $dir, &$jars) {
    if ($serverPid) { @exec("kill $serverPid 2>/dev/null"); $serverPid = 0; }
    try { $purge($A[0]); $purge($B[0]); } catch (Throwable $e) {}
    foreach ($jars as $j) { @unlink($j); }
    foreach (glob($dir . '/*') ?: [] as $f) { @unlink($f); }
    @rmdir($dir);
};
$mkUser = function (array $u, string $name) use ($pdo): int {
    $r = createUserAccount($pdo, $name, $u[0], $u[0] . '@example.com', $u[1]);
    $id = (int)($r['id'] ?? 0);
    Biz::setType($id, 'business');
    return $id;
};
$fileOf = fn(string $path, string $type = 'image/png') => ['name' => basename($path), 'type' => $type, 'tmp_name' => $path,
                                                          'error' => UPLOAD_ERR_OK, 'size' => (int)filesize($path)];
$avatarOf = function (int $id) use ($pdo): ?string {
    $st = $pdo->prepare('SELECT avatar FROM users WHERE id = :i');
    $st->execute(['i' => $id]);
    $v = $st->fetchColumn();
    return is_string($v) && $v !== '' ? $v : null;
};

try {
    $purge($A[0]); $purge($B[0]);
    $aId = $mkUser($A, 'عباس آزمایشی');
    $bId = $mkUser($B, 'بهرام آزمایشی');

    // ---------- نمونه‌ها ----------
    $im = imagecreatetruecolor(900, 600);
    imagefill($im, 0, 0, imagecolorallocate($im, 20, 120, 90));
    imagepng($im, "$dir/ok.png");
    // ⛔ PNGِ معتبر با کدِ PHP چسبیده به تهش — بازکشی باید آن را ببرد
    copy("$dir/ok.png", "$dir/tail.png");
    file_put_contents("$dir/tail.png", '<?php echo "pwn"; ?>', FILE_APPEND);
    file_put_contents("$dir/fake.png", '<?php echo 1; ?>');
    $sm = imagecreatetruecolor(20, 20); imagepng($sm, "$dir/tiny.png");
    if (function_exists('imagebmp')) { imagebmp($im, "$dir/x.bmp"); }
    imagegif($im, "$dir/x.gif");

    // ---------- ۱. تابع ----------
    $r = saveUserAvatar($aId, null);
    T::ok(!$r['ok'] && $r['message'] === 'تصویری دریافت نشد.', 'بی‌فایل: «تصویری دریافت نشد»');
    $r = saveUserAvatar($aId, $fileOf("$dir/fake.png"));
    T::ok(!$r['ok'] && $r['message'] === 'فایل انتخابی تصویر معتبری نیست.', '⛔ فایلِ جعلی (کدِ PHP با پسوندِ png) رد می‌شود');
    $r = saveUserAvatar($aId, $fileOf("$dir/tiny.png"));
    T::ok(!$r['ok'] && str_contains($r['message'], 'کوچک'), 'تصویرِ ۲۰×۲۰ رد می‌شود');
    $r = saveUserAvatar($aId, $fileOf("$dir/x.gif", 'image/gif'));
    T::ok(!$r['ok'] && str_contains($r['message'], 'JPG'), '⛔ GIF (بیرونِ فهرستِ بسته) رد می‌شود — سمتِ مرورگر پیش از فرستادن JPEG می‌شود');
    if (is_file("$dir/x.bmp")) {
        $r = saveUserAvatar($aId, $fileOf("$dir/x.bmp", 'image/bmp'));
        T::ok(!$r['ok'], '⛔ BMP رد می‌شود');
    }
    T::same(null, $avatarOf($aId), 'هیچ‌کدام از شکست‌ها ستون را ننوشت');

    $r = saveUserAvatar($aId, $fileOf("$dir/tail.png"));
    T::ok($r['ok'], 'PNGِ معتبر ذخیره شد', $r['message']);
    $name1 = $avatarOf($aId);
    $path1 = $root . '/uploads/avatars/' . (string)$name1;
    T::ok($name1 !== null && preg_match('/^u' . $aId . '_[0-9a-f]{20}\.jpg$/', $name1) === 1, 'نامِ فایل تصادفی و از الگوی ثابت است', (string)$name1);
    $info = @getimagesize($path1);
    T::ok(is_array($info) && $info['mime'] === 'image/jpeg' && $info[0] === AVATAR_SIZE && $info[1] === AVATAR_SIZE,
        '⛔ فایلِ ذخیره‌شده JPEGِ بازکشیده‌ی ' . AVATAR_SIZE . '×' . AVATAR_SIZE . ' است، نه فایلِ کاربر');
    T::ok(is_file($path1) && !str_contains((string)file_get_contents($path1), '<?php'), '⛔ کدِ چسبیده به فایل با بازکشی رفت');

    $r = saveUserAvatar($aId, $fileOf("$dir/ok.png"));
    $name2 = $avatarOf($aId);
    T::ok($r['ok'] && $name2 !== $name1 && !is_file($path1), 'بارِ دوم: فایلِ قبلی پاک شد (یتیم نماند)');

    T::same('', avatarUrl('../../config/config.php'), '⛔ avatarUrl: نامِ دست‌کاری‌شده به مسیر نمی‌رسد');
    T::same('', avatarUrl('u1_nothere.jpg'), 'avatarUrl: فایلِ نبوده → ""');
    // ⛔ فایلی که در پوشه هست ولی از الگوی ما نیست (مثلاً از یک بکاپِ دست‌کاری‌شده)
    $stray = $root . '/uploads/avatars/u' . $aId . '_stray.php';
    file_put_contents($stray, '<?php echo 1;');
    T::same('', avatarUrl(basename($stray)), '⛔ avatarUrl: فایلِ غیرِ .jpg در همان پوشه هم به آدرس نمی‌رسد');
    @unlink($stray);
    T::same('', avatarUrl(null), 'avatarUrl: خالی → ""');
    T::same(APP_BASE_PATH . '/uploads/avatars/' . $name2, avatarUrl($name2), 'avatarUrl: فایلِ موجود → آدرسِ مطلق');

    // ---------- ۲. HTTP ----------
    T::group('تصویرِ شخصیِ فروشگاه — HTTP با نشستِ واقعی');
    $port = 0;
    for ($p = 8971; $p <= 8990; $p++) {
        $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
        if ($sock) { fclose($sock); $port = $p; break; }
    }
    if (!$port) { T::skip('تصویرِ فروشگاه', 'پورت آزاد پیدا نشد'); $cleanup(); exit(T::report()); }
    $log = tempnam(sys_get_temp_dir(), 'stavsrv');
    $serverPid = (int)trim((string)shell_exec(sprintf('PHP_CLI_SERVER_WORKERS=2 php -S 127.0.0.1:%d -t %s %s > %s 2>&1 & echo $!',
        $port, escapeshellarg($root), escapeshellarg(__DIR__ . '/csp_router.php'), escapeshellarg($log))));
    $up = false;
    for ($i = 0; $i < 40; $i++) {
        usleep(150000);
        $s = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.3);
        if ($s) { fclose($s); $up = true; break; }
    }
    if (!$up) { T::blocked('تصویرِ فروشگاه', 'سرور آزمایشی بالا نیامد'); $cleanup(); exit(T::report()); }

    $client = function () use ($port, &$jars): callable {
        $jar = tempnam(sys_get_temp_dir(), 'stavjar');
        $jars[] = $jar;
        return function (string $path, $post = null) use ($port, $jar): array {
            $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 25, CURLOPT_ENCODING => '']);
            if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $post); }
            $body = (string)curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            unset($ch);
            return [$code, $body, $jar];
        };
    };
    $csrf = fn(string $html) => preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m) ? $m[1] : '';
    $login = function (callable $c, array $u) use ($csrf, $pdo): bool {
        $pdo->exec("DELETE FROM login_attempts WHERE request_ip = '127.0.0.1'");
        [, $h] = $c('store/login.php');
        [$code] = $c('store/login.php', http_build_query(['csrf_token' => $csrf($h), 'username' => $u[0], 'password' => $u[1]]));
        return $code === 302 || $code === 303;
    };
    $topAvatar = function (string $html): ?string {
        // بالا-چپ = نمایه‌ی نوارِ بالا (`.st-profile`)
        if (!preg_match('/<details class="st-dd st-profile">\s*<summary[^>]*>(.*?)<\/summary>/su', $html, $m)) { return null; }
        return $m[1];
    };

    $asA = $client();
    $asB = $client();
    T::ok($login($asA, $A) && $login($asB, $B), 'ورودِ دو فروشگاهِ آزمایشی');

    // ⛔ ستون از قبل پر است (لایه‌ی ۱)؛ اول پاکش می‌کنیم تا حالتِ «حرف» دیده شود
    deleteUserAvatar($aId);
    [, $h] = $asA('store/index.php');
    $top = (string)$topAvatar($h);
    T::ok($top !== '' && !str_contains($top, '<img') && str_contains($top, 'ع'), 'بی‌تصویر: بالا-چپ همان حرفِ اولِ نام («ع») است');

    [, $h] = $asA('store/settings.php');
    $tok = $csrf($h);
    T::ok(str_contains($h, 'id="avatar"') && str_contains($h, 'enctype="multipart/form-data"') && str_contains($h, 'accept="image/*"'),
        'تنظیمات: بخشِ «تصویر شخصی» با فرمِ multipart');
    T::ok(preg_match('/<details class="st-dd st-profile">.*?settings\.php#avatar/su', $h) === 1, 'منوی نمایه لینکِ مستقیمِ «تصویر شخصی» دارد');

    [$code] = $asA('store/settings.php', ['action' => 'avatar', 'avatar' => new CURLFile("$dir/ok.png", 'image/png')]);
    T::ok($code === 403 && $avatarOf($aId) === null, '⛔ بی‌CSRF: ۴۰۳ و هیچ تصویری ننشست');

    [$code] = $asA('store/settings.php', ['csrf_token' => $tok, 'action' => 'avatar', 'avatar' => new CURLFile("$dir/fake.png", 'image/png')]);
    [, $h] = $asA('store/settings.php');
    T::ok($code === 302 && str_contains($h, 'فایل انتخابی تصویر معتبری نیست.') && $avatarOf($aId) === null,
        '⛔ فایلِ جعلی: ریدایرکت با پیامِ روشن، ستون خالی ماند');

    [$code] = $asA('store/settings.php', ['csrf_token' => $csrf($h), 'action' => 'avatar', 'avatar' => new CURLFile("$dir/ok.png", 'image/png')]);
    $name3 = $avatarOf($aId);
    T::ok($code === 302 && $name3 !== null, 'تصویرِ درست ذخیره شد');
    foreach (['store/index.php', 'store/products.php', 'store/settings.php'] as $pg) {
        [, $h] = $asA($pg);
        $top = (string)$topAvatar($h);
        T::ok(str_contains($top, 'class="st-avatar-img"') && str_contains($top, '/uploads/avatars/' . $name3),
            "⛔ {$pg}: تصویر بالا-چپ جای حرفِ اول نشست");
    }
    // ⛔ ورودِ تازه (دستگاهِ دیگر): نامِ تصویر از همان کوئریِ نوعِ حساب می‌آید
    $asA2 = $client();
    T::ok($login($asA2, $A), 'ورودِ دوباره‌ی همان فروشگاه با نشستِ تازه');
    [, $h2] = $asA2('store/index.php');
    T::ok(str_contains((string)$topAvatar($h2), '/uploads/avatars/' . $name3), '⛔ نشستِ تازه هم تصویر را بالا-چپ دارد (نه فقط همان نشستی که آپلود کرد)');
    [, $hb] = $asB('store/index.php');
    T::ok(!str_contains((string)$topAvatar($hb), '<img') && !str_contains($hb, (string)$name3),
        '⛔ فروشگاهِ دیگر تصویرِ این یکی را نمی‌بیند');

    [, $h] = $asA('store/settings.php');
    [$code] = $asA('store/settings.php', http_build_query(['csrf_token' => $csrf($h), 'action' => 'avatar_delete']));
    [, $h] = $asA('store/index.php');
    T::ok($code === 302 && $avatarOf($aId) === null && !is_file($root . '/uploads/avatars/' . $name3)
        && !str_contains((string)$topAvatar($h), '<img'), 'حذف: ستون خالی، فایل پاک، و بالا-چپ دوباره همان حرف');
    [, $h2] = $asA2('store/index.php');
    T::ok(!str_contains((string)$topAvatar($h2), '<img'), '⛔ نشستِ دیگر هم بعد از حذف دیگر تصویری نشان نمی‌دهد (از دیتابیس تازه می‌شود)');

    // ---------- ۳. کرومیوم زیرِ CSP ----------
    T::group('تصویرِ شخصیِ فروشگاه — کرومیوم زیرِ CSPِ تولید');
    $node = trim((string)@shell_exec('command -v node 2>/dev/null'));
    if ($node === '') { T::skip('تصویرِ فروشگاه (مرورگر)', 'node نصب نیست'); $cleanup(); exit(T::report()); }
    // ⛔ عکسِ «دوربینِ گوشی» (۱۲ مگاپیکسل، چند مگابایت) — نه یک PNGِ کوچک:
    //    رمزگشاییِ همین اندازه با `<img>`ِ رشته‌ی اصلی صفحه را قفل کرده بود.
    $big = imagecreatetruecolor(4000, 3000);
    for ($y = 0; $y < 3000; $y += 7) { imageline($big, 0, $y, 4000, ($y * 3) % 3000, imagecolorallocate($big, $y % 255, (7 * $y) % 255, 90)); }
    imagejpeg($big, "$dir/big.jpg", 92);
    $bigSize = (int)filesize("$dir/big.jpg");
    $sess = '';
    [, , $jarA] = $asA('store/index.php');
    foreach (explode("\n", (string)@file_get_contents($jarA)) as $line) {
        $parts = preg_split('/\t/', trim($line));
        if (count($parts) >= 7 && $parts[5] === 'DAFTAR_SESSION') { $sess = $parts[6]; }
    }
    $raw = trim((string)shell_exec(escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/store_avatar_probe.js') . ' '
        . escapeshellarg("http://127.0.0.1:{$port}/") . ' DAFTAR_SESSION ' . escapeshellarg($sess) . ' '
        . escapeshellarg("$dir/big.jpg") . ' 2>/dev/null'));
    $out = json_decode($raw, true);
    if (!is_array($out) || empty($out['ok'])) {
        $why = is_array($out) ? (string)($out['why'] ?? '?') : 'خروجیِ نامعتبر: ' . substr($raw, 0, 120);
        if ($why === 'no_chromium' || $why === 'chromium_no_start') { T::skip('تصویرِ فروشگاه (مرورگر)', 'کرومیوم در دسترس نیست'); }
        else { T::ok(false, 'probe اجرا شد', $why); }
        $cleanup(); exit(T::report());
    }
    T::ok($out['before'] === false && $out['inputFound'] === true, 'پیش از انتخاب: بی‌تصویر، و فیلدِ فایل پیدا شد');
    $pk = $out['picked'] ?? [];
    T::ok(($pk['preview'] ?? false) && ($pk['previewData'] ?? false) && ($pk['previewLoaded'] ?? false),
        '⛔ پیش‌نمایش با `data:` (نه `blob:`) زیرِ CSP واقعاً بار شد');
    T::ok(($pk['type'] ?? '') === 'image/jpeg' && ($pk['size'] ?? 0) > 0 && ($pk['size'] ?? 0) < intdiv($bigSize, 20),
        '⛔ مرورگر عکسِ درشت را پیش از فرستادن به JPEGِ کوچک تبدیل کرد',
        'نوع: ' . ($pk['type'] ?? '?') . '، حجم: ' . ($pk['size'] ?? 0) . ' از ' . $bigSize);
    $af = $out['after'] ?? null;
    T::ok(is_array($af) && $af['loaded'] === true && str_contains((string)$af['src'], '/uploads/avatars/'),
        '⛔ بعد از ذخیره، تصویرِ بالا-چپ زیرِ CSP واقعاً بار شد', var_export($af, true) . ' ' . ($out['flash'] ?? ''));
} catch (Throwable $e) {
    T::ok(false, 'اجرای تست بی‌استثنا', $e->getMessage() . ' @' . $e->getLine());
}

$cleanup();
exit(T::report());
