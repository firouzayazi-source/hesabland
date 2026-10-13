<?php
/**
 * ⛔ سرعتِ تعویض صفحه در فروشگاه — Speculation Rules و preloadِ فونت.
 *
 * بخشِ بی‌مرورگر خودِ قاعده را می‌سنجد (JSONِ معتبر، `moderate`، استثنای
 * «خروج»، دامنه‌ی `/store/`)؛ بخشِ مرورگر (`store_speed_probe.js`، زیرِ
 * **همان** CSPِ تولید با `csp_router.php`) رفتار را: مکثِ نشانگر روی یک قلمِ
 * منو → ناوبری از پیش‌گرفته، «خروج» هرگز پیش‌گرفته نمی‌شود (با شاهد، و
 * نشست هنوز زنده است)، منوی پایینِ گوشی هم، و فونت یک بار و از preload.
 * شکل را قاعده ۷۰ه نگه می‌دارد، برای ماشینی که کرومیوم ندارد.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

$root = realpath(__DIR__ . '/..');
T::group('سرعتِ فروشگاه — قاعده‌ی پیش‌گیری');

if (!file_exists($root . '/config/config.php')) { T::blocked('سرعتِ فروشگاه', 'config/config.php وجود ندارد'); exit(T::report()); }
require_once $root . '/includes/db.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/user_data.php';
require_once $root . '/includes/signup.php';

$rules = json_decode(Biz::speculationRulesJson(), true);
T::ok(is_array($rules) && isset($rules['prefetch'][0], $rules['prerender'][0]), 'JSONِ معتبر با prefetch و prerender');
T::same('moderate', $rules['prefetch'][0]['eagerness'] ?? null, '⛔ prefetch با «moderate» (نه eager)');
T::same('moderate', $rules['prerender'][0]['eagerness'] ?? null, '⛔ prerender با «moderate» — فقط با مکث/لمس، نه برای همه‌ی لینک‌ها');
$flat = (string)json_encode($rules, JSON_UNESCAPED_SLASHES);
T::ok(str_contains($flat, '/store/logout.php'), '⛔ «خروج» استثناست');
T::ok(str_contains($flat, '"href_matches":"' . APP_BASE_PATH . '/store/*"'), 'دامنه فقط /store/ است');
T::ok(str_contains($flat, '[onclick]') && str_contains($flat, '[target]'), 'لینکِ onclick/target‌دار پیش‌گرفته نمی‌شود');
T::ok(str_contains($flat, '/store/print.php'), 'برگه‌ی چاپ prerender نمی‌شود');

// ⛔ وب‌اپِ نصب‌شده: بدونِ مانیفست، آیفون هر قلمِ منو را در برگه‌ی مرورگر باز
//    می‌کرد (گزارشِ مالکِ نصب). هر آدرسِ منو باید داخلِ `scope` باشد.
T::group('وب‌اپِ نصب‌شده — مانیفستِ فروشگاه');
$mfSrc = (string)file_get_contents($root . '/assets/store-manifest.php');
$scope = APP_BASE_PATH . '/' . Biz::DIR . '/';
$outOf = [];
foreach (array_merge(array_keys(Biz::navFlat()), Biz::TABBAR, ['login.php', 'logout.php', 'settings.php', 'search.php']) as $pg) {
    $u = Biz::url($pg);
    if (strncmp($u, $scope, strlen($scope)) !== 0) { $outOf[] = $u; }
}
T::same([], $outOf, '⛔ هر قلمِ منوی کناری/پایین داخلِ scopeِ مانیفست است (وگرنه در برگه‌ی مرورگر باز می‌شود)');
$tags = Biz::webAppTags('x');
T::ok(str_contains($tags, 'rel="manifest" href="' . APP_BASE_PATH . '/assets/store-manifest.php"')
    && str_contains($tags, 'rel="apple-touch-icon"') && str_contains($tags, 'apple-mobile-web-app-capable'),
    'Biz::webAppTags: مانیفست، آیکونِ آیفون و حالتِ تمام‌صفحه');
foreach (['includes/biz_head.php', 'store/login.php'] as $f) {
    T::ok(str_contains((string)file_get_contents($root . '/' . $f), 'Biz::webAppTags('), "{$f} برچسب‌های وب‌اپ را دارد (کاربر از همین صفحه هم آیکون می‌سازد)");
}

// ⛔ لوگوی فروشگاه فقط مالِ فروشگاه است: هیچ آیکونِ هستیکو (`icon-*`) در وب‌اپِ
//    فروشگاه، و هیچ `store-*` در مانیفست و برچسب‌های هستیکو.
T::ok(!preg_match('/icons\/icon-/', $tags) && preg_match_all('/icons\/store-(16|32|180)\.png/', $tags) === 3,
    'برچسب‌های وب‌اپِ فروشگاه: favicon و آیکونِ آیفون از لوگوی فروشگاه (نه آیکونِ هستیکو)');
$missIc = [];
foreach (Biz::ICONS as $sz) {
    $fp = $root . '/assets/icons/store-' . $sz . '.png';
    $info = is_file($fp) ? @getimagesize($fp) : false;
    $want = (int)$sz;
    if (!$info || $info[0] !== $want || $info[1] !== $want) { $missIc[] = $sz; }
}
T::same([], $missIc, 'هر اندازه‌ی Biz::ICONS فایلِ مربعیِ هم‌اندازه دارد');
// آیکون تمام‌بلید: گوشه باید رنگِ صفحه (نارنجی) باشد، نه سفیدِ دورِ مربعِ گرد
$im = @imagecreatefrompng($root . '/assets/icons/store-512.png');
$c = $im ? imagecolorsforindex($im, imagecolorat($im, 1, 1)) : ['red' => 255, 'green' => 255, 'blue' => 255];
T::ok($c['red'] > 200 && $c['blue'] < 90, 'store-512 تمام‌بلید است (گوشه‌ی سفید پر شده)', json_encode($c));
$personal = (string)file_get_contents($root . '/assets/manifest.php') . (string)file_get_contents($root . '/includes/header.php');
T::ok(!str_contains($personal, 'store-'), '⛔ مانیفست و سرآیندِ هستیکو به لوگوی فروشگاه دست نزده‌اند');
foreach (['includes/biz_head.php' => "Biz::icon('96')", 'store/login.php' => "Biz::icon('180')"] as $f => $needle) {
    T::ok(str_contains((string)file_get_contents($root . '/' . $f), $needle), "{$f} لوگوی فروشگاه را نشان می‌دهد");
}

$node = trim((string)@shell_exec('command -v node 2>/dev/null'));
if ($node === '') { T::skip('سرعتِ فروشگاه (مرورگر)', 'node نصب نیست'); exit(T::report()); }
try { $pdo = Database::getConnection(); }
catch (Throwable $e) { T::blocked('سرعتِ فروشگاه', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }
if (!Biz::available()) { T::blocked('سرعتِ فروشگاه', 'ستونِ users.account_type نیست'); exit(T::report()); }

$USER = ['__stspeed_u', 'StSpeed#probe9'];
$serverPid = 0;
$jar = tempnam(sys_get_temp_dir(), 'stspjar');
$purge = function (string $username) use ($pdo) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $username]);
    $id = (int)$st->fetchColumn();
    if (!$id) { return; }
    try { deleteUserAccount($id); } catch (Throwable $e) {}
    try { $pdo->prepare('DELETE FROM users WHERE id = :i')->execute(['i' => $id]); } catch (Throwable $e) {}
};
$cleanup = function () use (&$serverPid, $purge, $USER, $jar) {
    if ($serverPid) { @exec("kill $serverPid 2>/dev/null"); $serverPid = 0; }
    try { $purge($USER[0]); } catch (Throwable $e) {}
    @unlink($jar);
};

try {
    $purge($USER[0]);
    $r = createUserAccount($pdo, 'فروشنده‌ی سرعت', $USER[0], $USER[0] . '@example.com', $USER[1]);
    Biz::setType((int)($r['id'] ?? 0), 'business');

    $port = 0;
    for ($p = 8991; $p <= 9010; $p++) {
        $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
        if ($sock) { fclose($sock); $port = $p; break; }
    }
    if (!$port) { T::skip('سرعتِ فروشگاه', 'پورت آزاد پیدا نشد'); $cleanup(); exit(T::report()); }
    $log = tempnam(sys_get_temp_dir(), 'stspsrv');
    $serverPid = (int)trim((string)shell_exec(sprintf('PHP_CLI_SERVER_WORKERS=3 php -S 127.0.0.1:%d -t %s %s > %s 2>&1 & echo $!',
        $port, escapeshellarg($root), escapeshellarg(__DIR__ . '/csp_router.php'), escapeshellarg($log))));
    $up = false;
    for ($i = 0; $i < 40; $i++) {
        usleep(150000);
        $s = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.3);
        if ($s) { fclose($s); $up = true; break; }
    }
    if (!$up) { T::blocked('سرعتِ فروشگاه', 'سرور آزمایشی بالا نیامد'); $cleanup(); exit(T::report()); }

    $get = function (string $path, ?array $post = null) use ($port, $jar): array {
        $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 25, CURLOPT_ENCODING => '']);
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        return [$code, $body];
    };
    $pdo->exec("DELETE FROM login_attempts WHERE request_ip = '127.0.0.1'");
    [, $html] = $get('store/login.php');
    preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m);
    [$code] = $get('store/login.php', ['csrf_token' => $m[1] ?? '', 'username' => $USER[0], 'password' => $USER[1]]);
    T::ok($code === 302 || $code === 303, 'ورودِ فروشگاهِ آزمایشی');
    [$mc, $mb] = $get('assets/store-manifest.php');
    $mf = json_decode($mb, true);
    T::ok($mc === 200 && is_array($mf), 'assets/store-manifest.php پاسخ ۲۰۰ و JSONِ معتبر می‌دهد (بی‌کوکی هم)', substr($mb, 0, 120));
    T::same([$scope, $scope, 'standalone'], [$mf['scope'] ?? null, $mf['start_url'] ?? null, $mf['display'] ?? null],
        '⛔ scope و start_url هر دو /store/ و تمام‌صفحه');
    T::ok(($mf['id'] ?? '') === $scope && ($mf['id'] ?? '') !== APP_BASE_PATH . '/index.php', 'id جدا از هستیکو — هر دو روی یک گوشی نصب می‌شوند');
    $miss = [];
    foreach (($mf['icons'] ?? []) as $ic) {
        if (!is_file($root . strtok((string)$ic['src'], '?'))) { $miss[] = $ic['src']; }
    }
    T::ok(!empty($mf['icons']) && $miss === [], 'فایلِ هر آیکونِ مانیفست روی دیسک هست', implode(', ', $miss));
    $mh = @get_headers('http://127.0.0.1:' . $port . '/assets/store-manifest.php', true) ?: [];
    T::ok(stripos((string)($mh['Cache-Control'] ?? ''), 'no-cache') !== false,
        '⛔ مانیفستِ فروشگاه بازسنجی می‌شود (no-cache)، نه کشِ یک‌روزه — وگرنه آیکونِ کهنه هنگامِ افزودن', json_encode($mh['Cache-Control'] ?? null));
    $srcs = implode(' ', array_column($mf['icons'] ?? [], 'src'));
    T::ok(!str_contains($srcs, '/icon-') && substr_count($srcs, '/store-') === 3
        && in_array('maskable', array_column($mf['icons'] ?? [], 'purpose'), true),
        '⛔ مانیفستِ فروشگاه فقط لوگوی فروشگاه (با نسخه‌ی maskable) را دارد');
    [, $home] = $get('store/index.php');
    T::ok(str_contains($home, '/assets/store-manifest.php') && str_contains($html, '/assets/store-manifest.php'),
        'پوسته و صفحه‌ی ورودِ فروشگاه هر دو مانیفست را دارند');
    T::ok(str_contains($home, 'id="stSpecRules"') && str_contains($home, 'rel="preload" href="' . APP_BASE_PATH . '/assets/fonts/Vazirmatn.woff2"'),
        'پوسته: قاعده‌ی پیش‌گیری و preloadِ فونت رندر شدند');

    $sess = '';
    foreach (explode("\n", (string)@file_get_contents($jar)) as $line) {
        $parts = preg_split('/\t/', trim($line));
        if (count($parts) >= 7 && $parts[5] === 'DAFTAR_SESSION') { $sess = $parts[6]; }
    }
    if ($sess === '') { T::blocked('سرعتِ فروشگاه', 'کوکیِ نشست پیدا نشد'); $cleanup(); exit(T::report()); }

    T::group('سرعتِ فروشگاه — کرومیوم زیرِ CSPِ تولید');
    $raw = trim((string)shell_exec(escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/store_speed_probe.js') . ' '
        . escapeshellarg("http://127.0.0.1:{$port}/") . ' DAFTAR_SESSION ' . escapeshellarg($sess) . ' 2>/dev/null'));
    $out = json_decode($raw, true);
    if (!is_array($out) || empty($out['ok'])) {
        $why = is_array($out) ? (string)($out['why'] ?? '?') : 'خروجیِ نامعتبر: ' . substr($raw, 0, 120);
        if ($why === 'no_chromium' || $why === 'chromium_no_start') { T::skip('سرعتِ فروشگاه (مرورگر)', 'کرومیوم در دسترس نیست'); }
        else { T::ok(false, 'probe اجرا شد', $why); }
        $cleanup(); exit(T::report());
    }
    T::ok($out['rules'] === true, 'قاعده‌ی speculationrules زیرِ CSP معتبر و پشتیبانی‌شده است');
    T::same(['n' => 1, 'init' => ['link']], $out['font'], '⛔ فونت یک بار و از preload آمد (نه دیر، نه دو بار)');
    T::ok($out['hoverFound'] === true, 'قلمِ «کالاها» در منوی کناری پیدا شد');
    T::same('navigational-prefetch', $out['normal'], '⛔ مکثِ نشانگر → ناوبری از پیش‌گرفته آمد (زیرِ no-store)');
    T::ok($out['exFound'] === true, 'لینکِ «خروج» روی صفحه پیدا شد');
    $urls = (array)$out['prefetchedUrls'];
    T::ok(in_array('store/parties.php', $urls, true), '⛔ شاهد: همان پنجره لینکِ عادی را پیش گرفت (probe کور نیست)', implode(', ', $urls));
    T::ok(!in_array('store/logout.php', $urls, true), '⛔ «خروج» پیش‌گرفته نشد', implode(', ', $urls));
    T::ok($out['stillIn'] === true, '⛔ و نشست هنوز زنده است');
    T::ok($out['tabFound'] === true, 'منوی پایینِ گوشی پیدا شد');
    T::same('navigational-prefetch', $out['tab'], 'منوی پایینِ گوشی هم پیش‌گرفته می‌شود');
} catch (Throwable $e) {
    T::ok(false, 'اجرای تست بی‌استثنا', $e->getMessage());
}

$cleanup();
exit(T::report());
