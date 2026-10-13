<?php
/**
 * تصویرِ پروفایل — «هر عکسی» باید بنشیند، زیرِ CSPِ تولید.
 *
 * ⛔ خرابیِ واقعی که این تست برایش نوشته شد: قابِ برش تصویر را با
 *    `URL.createObjectURL` بار می‌کرد و CSPِ سایت (`img-src 'self' data:`)
 *    آدرسِ `blob:` را می‌بندد. پس روی سرور **هر** عکسی — از هر گوشی‌ای —
 *    پیامِ «این فایل تصویر معتبری نیست» می‌گرفت، و روی ماشینِ توسعه
 *    (بی‌CSP) کاملاً سالم بود. تستی که بی‌CSP اجرا شود همین را نمی‌بیند؛
 *    پس سرور با `csp_router.php` همان سیاستِ `deploy/nginx-csp.sh` را
 *    می‌فرستد.
 *
 * ⚠ کرومیوم و node ابزارِ اختیاری‌اند (`T::skip`)؛ دیتابیس لازم است
 *   (`T::blocked`). قاعده ۵۹ در `test_api_contract.php` شکل را نگه می‌دارد.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

$root = realpath(__DIR__ . '/..');

T::group('آپلودِ تصویرِ پروفایل در مرورگر، زیرِ CSPِ تولید');
$node = trim((string)@shell_exec('command -v node 2>/dev/null'));
if ($node === '') { T::skip('آپلود تصویر', 'node نصب نیست'); exit(T::report()); }
if (!function_exists('imagecreatetruecolor')) { T::skip('آپلود تصویر', 'افزونه‌ی gd نیست'); exit(T::report()); }
if (!file_exists($root . '/config/config.php')) { T::blocked('آپلود تصویر', 'config/config.php وجود ندارد'); exit(T::report()); }

require_once $root . '/includes/db.php';
require_once $root . '/includes/user_data.php';
try { $pdo = Database::getConnection(); }
catch (Throwable $e) { T::blocked('آپلود تصویر', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }
if (!usersHaveColumn($pdo, 'avatar')) { T::blocked('آپلود تصویر', 'ستون users.avatar نیست (migration_p4)'); exit(T::report()); }

$USER = ['avatar_u', 'Av#probe9'];
$serverPid = 0;
$uid = 0;
$jar = tempnam(sys_get_temp_dir(), 'avjar');
$dir = sys_get_temp_dir() . '/avprobe_' . getmypid();
@mkdir($dir, 0700, true);
$purge = function (string $username) use ($pdo, $root) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $username]);
    $id = (int)$st->fetchColumn();
    if (!$id) { return; }
    foreach (glob($root . '/uploads/avatars/u' . $id . '_*') ?: [] as $f) { @unlink($f); }
    try { deleteUserAccount($id); } catch (Throwable $e) {}
    try { $pdo->prepare('DELETE FROM users WHERE id = :i')->execute(['i' => $id]); } catch (Throwable $e) {}
};
$cleanup = function () use (&$serverPid, $purge, $USER, $jar, $dir) {
    if ($serverPid) { @exec("kill $serverPid 2>/dev/null"); $serverPid = 0; }
    try { $purge($USER[0]); } catch (Throwable $e) {}
    @unlink($jar);
    foreach (glob($dir . '/*') ?: [] as $f) { @unlink($f); }
    @rmdir($dir);
};

try {
    $purge($USER[0]);
    $pdo->prepare("INSERT INTO users (full_name, username, password_hash, role, is_active)
                   VALUES ('کاربر تصویر', :u, :p, 'user', 1)")
        ->execute(['u' => $USER[0], 'p' => password_hash($USER[1], PASSWORD_DEFAULT)]);
    $uid = (int)$pdo->lastInsertId();

    // ⛔ چند قالب و یک عکسِ درشتِ «دوربینِ گوشی»، نه فقط یک PNGِ کوچک:
    //    خواسته «هر عکسی» است و GIF و عکسِ ۱۲ مگاپیکسلی دو شکستِ متفاوت‌اند.
    $mk = static function (int $w, int $h) {
        $im = imagecreatetruecolor($w, $h);
        for ($y = 0; $y < $h; $y += max(1, intdiv($h, 40))) {
            imagefilledrectangle($im, 0, $y, $w, $y + max(1, intdiv($h, 40)), imagecolorallocate($im, ($y * 7) % 255, 120, 200));
        }
        return $im;
    };
    $files = [];
    $im = $mk(640, 480);  imagepng($im, $files['png'] = "$dir/a.png");
    $im = $mk(480, 640);  imagejpeg($im, $files['jpg'] = "$dir/b.jpg", 85);
    $im = $mk(300, 300);  imagegif($im, $files['gif'] = "$dir/c.gif");
    if (function_exists('imagewebp')) { $im = $mk(500, 500); imagewebp($im, $files['webp'] = "$dir/d.webp"); }
    $im = $mk(3000, 4000); imagejpeg($im, $files['big'] = "$dir/e.jpg", 80);
    file_put_contents($files['txt'] = "$dir/f.txt", "not an image\n");

    $port = 0;
    for ($p = 8951; $p <= 8969; $p++) {
        $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
        if ($sock) { fclose($sock); $port = $p; break; }
    }
    if (!$port) { T::skip('آپلود تصویر', 'پورت آزاد پیدا نشد'); $cleanup(); exit(T::report()); }
    $log = tempnam(sys_get_temp_dir(), 'avsrv');
    $serverPid = (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s %s > %s 2>&1 & echo $!',
        $port, escapeshellarg($root), escapeshellarg(__DIR__ . '/csp_router.php'), escapeshellarg($log))));
    $up = false;
    for ($i = 0; $i < 40; $i++) {
        usleep(150000);
        $s = @fsockopen('127.0.0.1', $port, $a, $b, 0.3);
        if ($s) { fclose($s); $up = true; break; }
    }
    if (!$up) { T::blocked('آپلود تصویر', 'سرور آزمایشی بالا نیامد'); $cleanup(); exit(T::report()); }

    $req = function (string $path, ?array $post = null) use ($port, $jar): array {
        $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_HEADER => true,
            CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 25]);
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        return [$code, $body];
    };
    [, $html] = $req('login.php');
    // ⛔ خودِ سنجه هم سنجیده می‌شود: اگر روتر CSP نفرستد، کلِ این فایل
    //    همان تستِ بی‌CSP است که باگ را نمی‌دید.
    T::ok(stripos($html, "Content-Security-Policy: ") !== false && strpos($html, "img-src 'self' data:") !== false,
        'سرورِ آزمایشی همان CSPِ تولید را می‌فرستد');
    preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m2);
    $pdo->exec("DELETE FROM login_attempts WHERE request_ip = '127.0.0.1'");
    [$code] = $req('login.php', ['csrf_token' => $m2[1] ?? '', 'username' => $USER[0], 'password' => $USER[1]]);
    T::ok($code === 302 || $code === 303, 'ورودِ کاربرِ آزمایشی');

    $sess = '';
    foreach (explode("\n", (string)@file_get_contents($jar)) as $line) {
        $parts = preg_split('/\t/', trim($line));
        if (count($parts) >= 7 && $parts[5] === 'DAFTAR_SESSION') { $sess = $parts[6]; }
    }
    if ($sess === '') { T::blocked('آپلود تصویر', 'کوکیِ نشست پیدا نشد'); $cleanup(); exit(T::report()); }

    $cmd = escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/avatar_probe.js') . ' '
         . escapeshellarg("http://127.0.0.1:{$port}/") . ' ' . escapeshellarg($sess) . ' '
         . escapeshellarg(implode(',', array_values($files))) . ' 2>/dev/null';
    $out = json_decode(trim((string)shell_exec($cmd)), true);
    if (!is_array($out) || empty($out['ok'])) {
        $why = is_array($out) ? ($out['why'] ?? '?') : 'خروجیِ نامعتبر';
        if ($why === 'no_chromium') { T::skip('آپلود تصویر', 'کرومیوم نصب نیست'); }
        else { T::ok(false, 'probe اجرا شد', $why); }
        $cleanup(); exit(T::report());
    }

    $byFile = [];
    foreach ($out['results'] as $r) { $byFile[$r['file']] = $r; }
    foreach ($files as $kind => $path) {
        $r = $byFile[basename($path)] ?? null;
        if ($kind === 'txt') {
            T::ok($r && !$r['shown'] && $r['msg'] !== '', 'فایلِ غیرتصویری قاب را باز نمی‌کند و پیام می‌دهد', json_encode($r, JSON_UNESCAPED_UNICODE));
            continue;
        }
        T::ok($r && $r['shown'], "عکسِ {$kind}: قابِ برش باز می‌شود", json_encode($r, JSON_UNESCAPED_UNICODE));
        T::ok($r && $r['changed'], "عکسِ {$kind}: ذخیره می‌شود و روی پروفایل می‌نشیند", json_encode($r, JSON_UNESCAPED_UNICODE));
    }
    $st = $pdo->prepare('SELECT avatar FROM users WHERE id = :u');
    $st->execute(['u' => $uid]);
    $av = (string)$st->fetchColumn();
    $info = $av !== '' ? @getimagesize($root . '/uploads/avatars/' . $av) : false;
    T::ok($info !== false && ($info['mime'] ?? '') === 'image/jpeg', 'فایلِ ذخیره‌شده روی دیسک یک JPEGِ واقعی است', $av);
    T::same([], $out['csp'] ?? ['?'], 'هیچ نقضِ CSP ای در مسیر نبود');
    T::same([], $out['errors'] ?? ['?'], 'هیچ خطای جاوااسکریپتی در مسیر نبود');
} finally {
    $cleanup();
}

exit(T::report());
