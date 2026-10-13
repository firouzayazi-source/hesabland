<?php
/**
 * ⛔ به‌روزرسانیِ درون‌برنامه‌ای — «وقتی آپدیت شد، برنامه رو باز کرد،
 *    پیام برای کاربر بره که آپدیت کنه».
 *
 * زنجیره سه تکه دارد و خرابیِ هر کدام **بی‌صداست**:
 *   ۱. سایت آخرین نسخه را از **خودِ فایلِ APK** می‌خواند
 *      (`includes/apk_meta.php`). عددِ غلط یعنی یا کاربرِ به‌روز هر روز
 *      «به‌روزرسانی کن» می‌گیرد، یا کاربرِ عقب‌مانده هرگز.
 *   ۲. اپ نسخه‌ی خودش را با `?appv=` می‌گوید و سرور کوکی می‌گذارد
 *      (`Auth::captureAppVersion()`) — حتی پشتِ ریدایرکتِ ورود.
 *   ۳. `app.js` فقط **داخلِ اپ** و فقط برای نسخه‌ی قدیمی‌تر نوار نشان
 *      می‌دهد (`appUpdateDue()`)، و «بعداً» فقط همان نسخه را، و فقط
 *      مدتی، ساکت می‌کند.
 *
 * هر سه اینجا *رفتاری* سنجیده می‌شوند؛ قاعده ۶۴ در `test_api_contract`
 * *شکل* را نگه می‌دارد (برای ماشینی که node و کرومیوم ندارد).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/lib/apk_fixture.php';
require_once __DIR__ . '/../includes/apk_meta.php';

$root = realpath(__DIR__ . '/..');
$tmp  = sys_get_temp_dir() . '/hesab_appupd_' . getmypid();
@mkdir($tmp, 0700, true);
$cleanup = static function () use ($tmp) {
    foreach (glob($tmp . '/{,.}*', GLOB_BRACE) ?: [] as $f) { if (is_file($f)) { @unlink($f); } }
    @rmdir($tmp);
};
register_shutdown_function($cleanup);

$PKG = ANDROID_PACKAGE;

// =====================================================================
T::group('۱ — خواندنِ نسخه از خودِ فایلِ APK');

$cases = [
    'UTF-16 (aaptِ قدیمی)'                => [['code' => 10, 'name' => '1.9', 'package' => $PKG], false, true, 8],
    'UTF-8 (aapt2)'                       => [['code' => 11, 'name' => '1.9', 'package' => $PKG], true,  true, 8],
    'فقط شناسه‌ی منبع، بی‌نامِ ویژگی'     => [['code' => 12, 'name' => '2.0', 'package' => $PKG], true,  false, 8],
    'عضوِ بی‌فشرده‌سازی (stored)'         => [['code' => 13, 'name' => '2.1', 'package' => $PKG], false, true, 0],
    'عددِ هگز (0x11)'                     => [['code' => 14, 'name' => '2.2', 'package' => $PKG, 'hex' => true], true, true, 8],
];
foreach ($cases as $label => [$m, $utf8, $names, $method]) {
    $f = "$tmp/c" . $m['code'] . '.apk';
    file_put_contents($f, apkFixture($m, $utf8, $names, $method));
    T::same(['version_code' => $m['code'], 'version_name' => $m['name'], 'package' => $PKG],
            apkManifestInfo($f), $label);
}

$good = apkFixture(['code' => 20, 'name' => '3.0', 'package' => $PKG]);
file_put_contents("$tmp/trunc.apk", substr($good, 0, (int)(strlen($good) * 0.6)));
T::same(null, apkManifestInfo("$tmp/trunc.apk"), 'دانلودِ نصفه (فهرستِ مرکزی ندارد) → null');

// ⚠ CRC: عضوی که بازشدنی است ولی بایت‌هایش عوض شده، عددِ بی‌معنا می‌داد.
$ax  = axmlFixture(['code' => 21, 'name' => '3.1', 'package' => $PKG]);
$bad = zipFixture(['AndroidManifest.xml' => $ax], 0);
// ⚠ خودِ عددِ versionCode عوض می‌شود، نه یک بایتِ تصادفی: بایتِ تصادفی
//   معمولاً تجزیه را هم می‌شکند و آن‌وقت بررسی بی‌سنجشِ CRC هم سبز می‌ماند
//   (جهش نشانش داد). اینجا فقط CRC می‌تواند بفهمد.
$pos = strpos($bad, pack('vCCV', 8, 0, 0x10, 21));
$bad[$pos + 4] = chr(99);
file_put_contents("$tmp/crc.apk", $bad);
T::same(null, apkManifestInfo("$tmp/crc.apk"), 'بایتِ دست‌کاری‌شده (CRC نمی‌خواند) → null');

file_put_contents("$tmp/nozip.apk", str_repeat('<html>not an apk</html>', 20));
T::same(null, apkManifestInfo("$tmp/nozip.apk"), 'صفحه‌ی HTML به‌جای APK → null');
file_put_contents("$tmp/noman.apk", zipFixture(['classes.dex' => 'x']));
T::same(null, apkManifestInfo("$tmp/noman.apk"), 'zipِ بی‌manifest → null');
T::same(null, apkManifestInfo("$tmp/missing.apk"), 'فایلِ ناموجود → null');

// =====================================================================
T::group('۲ — آخرین نسخه‌ی روی سرور، و کشِ آن');

require_once __DIR__ . '/../includes/functions.php';

$apk   = "$tmp/hesabland.apk";
$cache = "$tmp/apk-meta.json";
file_put_contents($apk, apkFixture(['code' => 10, 'name' => '1.9', 'package' => $PKG], false, true, 0));
T::same(['code' => 10, 'name' => '1.9'], apkLatestFrom($apk, $cache), 'نسخه‌ی فایل خوانده می‌شود');
T::ok(is_file($cache), 'نتیجه کش می‌شود');

// ⛔ جابه‌جاییِ اتمی مثل apk-publish.sh: فایلِ تازه باید همان لحظه دیده شود.
// ⚠ هم‌اندازه و (به احتمالِ زیاد) در همان ثانیه — عمداً، تا کلیدی که فقط
//   اندازه یا زمان را بسنجد همین‌جا کهنه بماند و گرفته شود.
file_put_contents("$apk.tmp", apkFixture(['code' => 11, 'name' => '2.0', 'package' => $PKG], false, true, 0));
T::same(filesize($apk), filesize("$apk.tmp"), '(نمونه‌ی دوم هم‌اندازه است)');
rename("$apk.tmp", $apk);
T::same(['code' => 11, 'name' => '2.0'], apkLatestFrom($apk, $cache),
        'فایلِ تازه همان درخواستِ بعدی دیده می‌شود (کشِ کهنه نمی‌ماند)');

// کش واقعاً استفاده می‌شود: محتوای کش را دست‌کاری کن، کلید را نگه دار.
$c = json_decode((string)file_get_contents($cache), true);
$c['info']['version_code'] = 99;
file_put_contents($cache, json_encode($c));
T::same(99, apkLatestFrom($apk, $cache)['code'] ?? null, 'تا فایل عوض نشده، از کش خوانده می‌شود');
@unlink($cache);

// ⛔ بسته‌ی دیگر → نوار نیاید.
file_put_contents($apk, apkFixture(['code' => 50, 'name' => '9.9', 'package' => 'com.example.other']));
T::same(null, apkLatestFrom($apk, $cache), 'APKِ اپِ دیگر → null (به‌روزرسانی اپِ دیگری نصب نکند)');
@unlink($cache);

file_put_contents($apk, 'broken');
T::same(null, apkLatestFrom($apk, $cache), 'فایلِ خراب → null');
$c = json_decode((string)@file_get_contents($cache), true);
T::ok(is_array($c) && array_key_exists('info', $c) && $c['info'] === null,
      'نتیجه‌ی ناموفق هم کش می‌شود (فایلِ خراب در هر بارگذاری دوباره تجزیه نشود)');
@unlink($apk);
T::same(null, apkLatestFrom($apk, $cache), 'بی‌فایل → null');

// =====================================================================
T::group('۳ — deploy/apk-version.php');

$run = static function (array $args) use ($root): array {
    $cmd = 'php ' . escapeshellarg($root . '/deploy/apk-version.php');
    foreach ($args as $a) { $cmd .= ' ' . escapeshellarg($a); }
    exec($cmd . ' 2>/dev/null', $o, $rc);
    return [$rc, implode("\n", $o)];
};
file_put_contents("$tmp/ok.apk", apkFixture(['code' => 10, 'name' => '1.9', 'package' => $PKG]));
file_put_contents("$tmp/other.apk", apkFixture(['code' => 10, 'name' => '1.9', 'package' => 'com.example.other']));
T::same([0, "10\t1.9\t$PKG"], $run(['--expect', "$tmp/ok.apk"]), 'اپِ ما: کد ۰ و «کد، نام، بسته»');
T::same(2, $run(['--expect', "$tmp/other.apk"])[0], 'بسته‌ی دیگر با --expect: کد ۲ (منتشر نشود)');
T::same(0, $run(["$tmp/other.apk"])[0], 'بی --expect فقط می‌خواند');
T::same(1, $run(["$tmp/nozip.apk"])[0], 'فایلِ غیرِ APK: کد ۱');

// =====================================================================
T::group('۴ — نسخه‌ی نصب‌شده: `?appv=` → کوکی');

$router = "$tmp/router.php";
$fixture = <<<'HTML'
<!DOCTYPE html>
<html lang="fa" dir="rtl" class="js-loading">
<head><meta charset="UTF-8">
<meta name="apk-latest" content="%LATEST%" data-name="1.9" data-url="/download/hesabland.apk?v=7" data-installed="%INST%" data-app="حساب لند">
<script>window.APP_BASE = '';</script>
<script defer src="/assets/js/app.js"></script>
</head><body><div class="page-content"><p>محتوا</p></div></body></html>
HTML;
file_put_contents("$tmp/fixture.html", $fixture);
file_put_contents($router, '<?php
$p = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
if ($p === "/__appv") {
    require ' . var_export($root . '/includes/auth.php', true) . ';
    Auth::captureAppVersion();
    header("Content-Type: text/plain");
    echo "v=", Auth::appVersion();
    return true;
}
if ($p === "/__update_probe") {
    $h = file_get_contents(' . var_export("$tmp/fixture.html", true) . ');
    $latest = preg_match("/^[0-9]{1,9}$/", $_GET["latest"] ?? "") ? $_GET["latest"] : "0";
    $inst   = preg_match("/^[0-9]{1,9}$/", $_GET["inst"] ?? "") ? $_GET["inst"] : "";
    echo str_replace(["%LATEST%", "%INST%"], [$latest, $inst], $h);
    return true;
}
return false;
');

$port = 0;
for ($p = 8971; $p <= 8999; $p++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
    if ($sock) { fclose($sock); $port = $p; break; }
}
if (!$port) { T::skip('سرور آزمایشی', 'پورت آزاد پیدا نشد'); exit(T::report()); }

$log = "$tmp/srv.log";
$pid = (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s %s > %s 2>&1 & echo $!',
    $port, escapeshellarg($root), escapeshellarg($router), escapeshellarg($log))));
register_shutdown_function(static function () use ($pid) { if ($pid > 0) { @posix_kill($pid, 9) || exec("kill -9 $pid 2>/dev/null"); } });
$up = false;
for ($i = 0; $i < 40; $i++) {
    usleep(150000);
    $s = @fsockopen('127.0.0.1', $port, $a, $b, 0.3);
    if ($s) { fclose($s); $up = true; break; }
}
if (!$up) { T::blocked('سرور آزمایشی', 'بالا نیامد: ' . substr((string)@file_get_contents($log), 0, 200)); exit(T::report()); }

$hit = static function (string $path, string $cookie = '') use ($port): array {
    $ch = curl_init("http://127.0.0.1:{$port}{$path}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 15]);
    if ($cookie !== '') { curl_setopt($ch, CURLOPT_COOKIE, $cookie); }
    $r = (string)curl_exec($ch);
    $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    unset($ch);
    return [substr($r, 0, $hs), substr($r, $hs)];
};

[$h, $b] = $hit('/__appv?appv=10');
if (!str_starts_with($b, 'v=')) {
    T::blocked('کوکیِ نسخه', 'auth.php لود نشد (config.php؟): ' . substr($b, 0, 160));
} else {
    T::same('v=10', $b, 'نسخه‌ی همان درخواست خوانده می‌شود');
    T::ok((bool)preg_match('/^Set-Cookie: daftar_app_vc=10;[^\r\n]*HttpOnly/mi', $h), 'کوکیِ httponly گذاشته می‌شود', $h);
    [$h2, $b2] = $hit('/__appv?appv=abc');
    T::ok(!str_contains($h2, 'daftar_app_vc') && $b2 === 'v=0', 'مقدارِ غیرِعددی پذیرفته نمی‌شود');
    [$h3] = $hit('/__appv?appv=0');
    T::ok(!str_contains($h3, 'daftar_app_vc'), 'صفر پذیرفته نمی‌شود');
    [, $b4] = $hit('/__appv', 'daftar_app_vc=12');
    T::same('v=12', $b4, 'درخواستِ بعدی نسخه را از کوکی می‌خواند');
    [, $b5] = $hit('/__appv', 'daftar_app_vc=<script>');
    T::same('v=0', $b5, 'کوکیِ دست‌کاری‌شده نادیده گرفته می‌شود');
}

// =====================================================================
T::group('۵ — تصمیمِ نشان دادن (node روی خودِ app.js)');

exec('command -v node 2>/dev/null', $no, $nrc);
if ($nrc !== 0) {
    T::skip('appUpdateDue', 'node نصب نیست');
} else {
    $now = 1_700_000_000_000;
    $d = [
        ['اپِ قدیمی‌تر، داخلِ اپ → نشان بده',        ['latest' => 10, 'installed' => 9,  'inApp' => true], true],
        ['اپِ به‌روز → نه',                          ['latest' => 10, 'installed' => 10, 'inApp' => true], false],
        ['اپِ جلوتر از سرور → نه',                  ['latest' => 10, 'installed' => 11, 'inApp' => true], false],
        ['کرومِ معمولی، نه داخلِ اپ → نه',           ['latest' => 10, 'installed' => 9,  'inApp' => false], false],
        ['نسخه‌ی نامعلوم (اپِ پیش از این قابلیت) → بله', ['latest' => 10, 'installed' => '', 'inApp' => true], true],
        ['نسخه‌ی سرور نامعلوم → نه',                 ['latest' => 0,  'installed' => 9,  'inApp' => true], false],
        ['«بعداً» برای همین نسخه، هنوز در مهلت → نه', ['latest' => 10, 'installed' => 9, 'inApp' => true,
                                                     'snoozeFor' => 10, 'snoozeUntil' => $now + 1000, 'now' => $now], false],
        ['«بعداً» تمام شد → بله',                    ['latest' => 10, 'installed' => 9, 'inApp' => true,
                                                     'snoozeFor' => 10, 'snoozeUntil' => $now - 1, 'now' => $now], true],
        ['«بعداً» برای نسخه‌ی قبلی، نسخه‌ی تازه‌تر → بله', ['latest' => 11, 'installed' => 9, 'inApp' => true,
                                                     'snoozeFor' => 10, 'snoozeUntil' => $now + 1000, 'now' => $now], true],
        ['ورودیِ تهی → نه (بی‌خطا)',                 null, false],
        // ⛔ به‌روزرسانی با یک تپ (`appUpdateHref`): از نسخه‌ی ۱۱ اپ خودش
        //    فایل را می‌گیرد و پنجره‌ی نصب را باز می‌کند؛ قدیمی‌تر همان
        //    دانلودِ مرورگر، و هر ورودیِ مشکوک هم همان.
        ['⛔ اپِ ۱۱: لینکِ intent با بازگشتِ مرورگر',
         ['href' => true, 'url' => 'https://h.test/download/hesabland.apk', 'latest' => 12, 'installed' => 11,
          'pkg' => 'ir.stland.hesabland'],
         ['native' => true, 'href' => 'intent://update?v=12#Intent;scheme=hesabland;action=ir.stland.hesabland.UPDATE;'
             . 'package=ir.stland.hesabland;S.browser_fallback_url=https%3A%2F%2Fh.test%2Fdownload%2Fhesabland.apk;end']],
        ['اپِ ۱۰ (بی‌صفحه‌ی بومی) → دانلودِ مرورگر',
         ['href' => true, 'url' => 'https://h.test/d.apk', 'latest' => 12, 'installed' => 10, 'pkg' => 'ir.stland.hesabland'],
         ['native' => false, 'href' => 'https://h.test/d.apk']],
        ['نسخه‌ی نامعلوم → دانلودِ مرورگر',
         ['href' => true, 'url' => 'https://h.test/d.apk', 'latest' => 12, 'installed' => '', 'pkg' => 'ir.stland.hesabland'],
         ['native' => false, 'href' => 'https://h.test/d.apk']],
        ['⛔ نامِ بسته‌ی دست‌کاری‌شده → دانلودِ مرورگر، نه intentِ ساختگی',
         ['href' => true, 'url' => 'https://h.test/d.apk', 'latest' => 12, 'installed' => 11, 'pkg' => 'x;S.evil=1'],
         ['native' => false, 'href' => 'https://h.test/d.apk']],
        ['آدرسِ بی‌https → دانلودِ مرورگر',
         ['href' => true, 'url' => 'http://h.test/d.apk', 'latest' => 12, 'installed' => 11, 'pkg' => 'ir.stland.hesabland'],
         ['native' => false, 'href' => 'http://h.test/d.apk']],
    ];
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open('node ' . escapeshellarg(__DIR__ . '/update_js_dump.js'), $desc, $pipes);
    fwrite($pipes[0], json_encode(array_map(static fn($c) => $c[1], $d)));
    fclose($pipes[0]);
    $out = json_decode((string)stream_get_contents($pipes[1]), true);
    $err = stream_get_contents($pipes[2]);
    proc_close($proc);
    if (!is_array($out)) {
        T::ok(false, 'node خروجی داد', substr((string)$err, 0, 200));
    } else {
        foreach ($d as $i => [$label, , $want]) { T::same($want, $out[$i] ?? 'missing', $label); }
    }
}

// =====================================================================
T::group('۶ — نوار در کرومیومِ واقعی');

if ($nrc !== 0) {
    T::skip('نوار در مرورگر', 'node نصب نیست');
    exit(T::report());
}
$raw = (string)shell_exec('node ' . escapeshellarg(__DIR__ . '/update_probe.js') . ' '
    . escapeshellarg("http://127.0.0.1:{$port}/") . ' 2>&1');
$r = json_decode(trim($raw), true);
if (!is_array($r)) {
    T::ok(false, 'probe اجرا شد', substr($raw, 0, 300));
} elseif (!($r['ok'] ?? false)) {
    if (in_array($r['why'] ?? '', ['no_chromium', 'chromium_no_start'], true)) {
        T::skip('نوار در مرورگر', 'کرومیوم نیست');
    } else {
        T::ok(false, 'probe اجرا شد', json_encode($r, JSON_UNESCAPED_UNICODE));
    }
} else {
    T::ok($r['shownOld'] === true, 'اپِ قدیمی‌تر: نوار دیده می‌شود');
    T::same('?latest=10&inst=9', $r['search'], 'فقط `appv` از نوارِ آدرس پاک می‌شود، بقیه‌ی پارامترها می‌مانند');
    T::same('#smsq=%5B%22x%22%5D', $r['hash'], 'فرگمنتِ پیامک دست نمی‌خورد');
    T::ok(str_contains((string)$r['title'], '۱٫۹') && str_contains((string)$r['title'], 'حساب لند'),
          'عنوان نامِ برنامه و نسخه‌ی تازه را می‌گوید', (string)$r['title']);
    T::same('/download/hesabland.apk?v=7', $r['href'], 'دکمه به همان APKِ روی دامنه می‌رود');
    T::ok($r['hasDownload'] === true, 'لینک `download` دارد');
    T::ok(str_contains((string)$r['first'], 'app-update-bar'), 'نوار بالای محتواست');
    T::ok($r['position'] !== 'fixed', 'شناور نیست (position: fixed ندارد)', (string)$r['position']);
    T::ok($r['goneAfterX'] === true, '«بعداً» نوار را می‌بندد');
    $sx = explode(':', (string)$r['snoozeX']);
    T::ok(($sx[0] ?? '') === '10' && (int)($sx[1] ?? 0) > 0, '«بعداً» همان نسخه را به تعویق می‌اندازد', (string)$r['snoozeX']);
    T::ok($r['hiddenSnoozed'] === true, 'در مهلتِ «بعداً» دوباره نمی‌آید');
    T::ok($r['shownNewer'] === true, 'نسخه‌ی تازه‌تر با وجودِ «بعداً» دوباره می‌پرسد');
    T::ok($r['hiddenCurrent'] === true, 'اپِ به‌روز نوار نمی‌بیند');
    T::ok($r['shownUnknown'] === true, 'اپِ بی‌نسخه (۱٫۸ و قبل‌تر) نوار می‌بیند');
    T::ok(str_contains((string)$r['tapNote'], 'اعلانِ دانلود'), 'بعد از تپ، گامِ بعد گفته می‌شود', (string)$r['tapNote']);
    $st = explode(':', (string)$r['snoozeTap']);
    $left = (int)($st[1] ?? 0) - (int)$r['now'];
    T::ok(($st[0] ?? '') === '10' && $left > 0 && $left <= 6 * 3600 * 1000 + 5000,
          'تپ فقط چند ساعت ساکت می‌کند (نه سه روز)', (string)$r['snoozeTap']);
    T::ok($r['hiddenBrowser'] === true, 'همان سایت در کرومِ معمولی نوار نمی‌گیرد');
}

exit(T::report());
