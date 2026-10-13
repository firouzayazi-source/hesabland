<?php
/**
 * ⛔ منوی «+ ثبتِ جدید» فروشگاه — کاشی‌ها و چیدمانِ هر فروشگاه.
 *
 * خواسته‌ی مالکِ نصب: «مثلِ عکسِ دوم بشه طراحیش و امکانِ اضافه کردن و پاک
 * کردن داشته باشم؛ مشتری یا تأمین‌کننده و محصول نباشن بهتره.»
 *
 * خطرها: (۱) کاتالوگ و کاشی‌ها از هم دور بیفتند و قلمی بی‌آیکون/نامرئی شود؛
 * (۲) چیدمانِ یک فروشگاه به فروشگاهِ دیگر نشت کند؛ (۳) کلیدِ دلخواه از فرم به
 * آدرسِ منو برسد؛ (۴) منو خالی شود؛ (۵) دو دکمه‌ی «+» دو چیزِ مختلف نشان دهند.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_docview.php';

$root = dirname(__DIR__);
const NPREFIX = '__bnm_';
const NPASS   = 'Menu12345';

/* ---------------------------------------------------------------- */
T::group('۱ — کاتالوگ، کاشی و پیش‌فرض (بی‌دیتابیس)');
T::same(array_keys(Biz::NEW_MENU), array_keys(Biz::NEW_TILES), '⛔ هر قلمِ کاتالوگ کاشی دارد و هر کاشی در کاتالوگ است، به همان ترتیب');
$badTile = [];
foreach (Biz::NEW_TILES as $k => $t) {
    if (count($t) !== 3 || $t[0] === '' || !preg_match('/^[a-z]+$/', $t[1]) || !str_starts_with($t[2], '<')) { $badTile[] = $k; }
}
T::same([], $badTile, 'هر کاشی نام، رنگ و آیکون دارد');
$css = (string)file_get_contents($root . '/assets/css/store.css');
$noTone = array_values(array_unique(array_filter(array_column(Biz::NEW_TILES, 1), fn($t) => !str_contains($css, '.is-tone-' . $t . ' '))));
T::same([], $noTone, 'هر رنگِ کاشی قاعده‌ی خودش را در store.css دارد');
$noDark = array_values(array_unique(array_filter(array_column(Biz::NEW_TILES, 1),
    fn($t) => substr_count($css, '[data-st-theme="dark"] .is-tone-' . $t . ' ') < 1 || substr_count($css, ':root:not([data-st-theme]) .is-tone-' . $t . ' ') < 1)));
T::same([], $noDark, 'و هر رنگ در هر دو درِ حالتِ شب روشن‌تر می‌شود');
T::same(6, count(Biz::NEW_DEFAULT), 'پیش‌فرض شش کاشی (دو ردیفِ سه‌تایی)');
T::ok(!in_array('party.php', Biz::NEW_DEFAULT, true) && !in_array('product.php', Biz::NEW_DEFAULT, true),
    '⛔ «مشتری یا تأمین‌کننده» و «محصول» در پیش‌فرض نیستند');
T::same([], array_diff(Biz::NEW_DEFAULT, array_keys(Biz::NEW_MENU)), 'پیش‌فرض فقط از کاتالوگ');
$missing = [];
foreach (array_keys(Biz::NEW_MENU) as $k) {
    if (!is_file($root . '/store/' . strtok($k, '?'))) { $missing[] = $k; }
}
T::same([], $missing, 'هر قلم به صفحه‌ای می‌رود که هست');
foreach (['includes/biz_head.php', 'includes/biz_foot.php'] as $f) {
    $src = (string)file_get_contents($root . '/' . $f);
    T::ok(str_contains($src, 'Biz::newMenuHtml(') && !preg_match('/foreach \(Biz::NEW_MENU as \$__href/', $src),
        "⛔ {$f} منو را فقط از Biz::newMenuHtml() می‌گیرد (یک رندر برای هر دو دکمه)");
}

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::blocked('منوی «+»', 'اتصال به دیتابیس برقرار نشد: ' . $e->getMessage());
    exit(T::report());
}
if (!Biz::available() || !tableHasColumn('biz_settings', 'new_menu')) {
    T::blocked('منوی «+»', 'ستونِ biz_settings.new_menu نیست — اول: bash deploy/migrate.sh --apply');
    exit(T::report());
}

$wipe = function () use ($pdo) {
    $ids = $pdo->query("SELECT id FROM users WHERE username LIKE '" . NPREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        foreach (['biz_accounts', 'biz_settings'] as $t) { $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]); }
        foreach (userDataTables() as $t) {
            try { $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]); }
            catch (PDOException $e) { /* جدولی که نیست */ }
        }
        $pdo->prepare('DELETE FROM audit_log WHERE target_user_id = :u')->execute(['u' => $id]);
    }
    $pdo->exec("DELETE FROM users WHERE username LIKE '" . NPREFIX . "%'");
    $pdo->exec("DELETE FROM login_attempts WHERE username_tried LIKE '" . NPREFIX . "%'");
};
$wipe();
$make = function (string $name) use ($pdo): int {
    $res = createUserAccount($pdo, 'کاربرِ ' . $name, NPREFIX . $name, NPREFIX . $name . '@example.com', NPASS);
    if (!($res['ok'] ?? false)) { throw new RuntimeException('ساختِ کاربر: ' . ($res['error'] ?? '?')); }
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => NPREFIX . $name]);
    return (int)$st->fetchColumn();
};
$a = $make('a');
$b = $make('b');
Biz::setType($a, 'business');
Biz::setType($b, 'business');
$raw = function (int $u) use ($pdo) {
    $st = $pdo->prepare('SELECT new_menu FROM biz_settings WHERE user_id = :u');
    $st->execute(['u' => $u]);
    return $st->fetchColumn();
};

try {

/* ---------------------------------------------------------------- */
T::group('۲ — ذخیره و خواندن');
T::same(Biz::NEW_DEFAULT, Biz::newMenu($a), 'فروشگاهِ تازه: پیش‌فرض');
$r = Biz::saveNewMenu($a, ['party.php', 'payment.php?k=receipt', 'quick-sale.php', 'javascript:alert(1)', '../admin/users.php']);
T::ok($r['ok'], 'چیدمانِ تازه ذخیره شد', $r['message']);
T::same(['quick-sale.php', 'payment.php?k=receipt', 'party.php'], Biz::newMenu($a), '⛔ فقط کلیدهای کاتالوگ، به ترتیبِ کاتالوگ (نه ترتیبِ فرم)');
T::ok(!str_contains((string)$raw($a), 'javascript') && !str_contains((string)$raw($a), 'admin'), '⛔ کلیدِ دلخواه حتی ذخیره هم نشد');
T::same(Biz::NEW_DEFAULT, Biz::newMenu($b), '⛔ چیدمانِ A به B نشت نکرد');
$r = Biz::saveNewMenu($a, []);
T::ok(!$r['ok'] && Biz::newMenu($a) === ['quick-sale.php', 'payment.php?k=receipt', 'party.php'], '⛔ منوی خالی رد می‌شود و قبلی می‌ماند', $r['message']);
T::ok(!Biz::saveNewMenu($a, ['nope.php'])['ok'], 'فقط کلیدِ ناشناخته = خالی = رد');
Biz::saveNewMenu($a, array_reverse(Biz::NEW_DEFAULT));
T::same(null, $raw($a) === false ? null : $raw($a), '⛔ همان پیش‌فرض NULL ذخیره می‌شود (تا پیش‌فرضِ فردا برسد)');
$pdo->prepare("UPDATE biz_settings SET new_menu = '{خراب' WHERE user_id = :u")->execute(['u' => $a]);
Biz::forgetSettings($a);
T::same(Biz::NEW_DEFAULT, Biz::newMenu($a), 'JSONِ خراب ← پیش‌فرض، نه منوی خالی');
$pdo->prepare('UPDATE biz_settings SET new_menu = :m WHERE user_id = :u')->execute(['m' => '["gone.php"]', 'u' => $a]);
Biz::forgetSettings($a);
T::same(Biz::NEW_DEFAULT, Biz::newMenu($a), 'کلیدی که از کاتالوگ رفته ← پیش‌فرض');

/* ---------------------------------------------------------------- */
T::group('۳ — رندر');
Biz::saveNewMenu($a, ['product.php', 'payment.php?k=receipt&method=cheque', 'invoice-edit.php?k=sale']);
$html = Biz::newMenuHtml($a);
T::same(3, substr_count($html, 'class="st-new-tile '), 'سه کاشی');
T::ok(str_contains($html, 'کالای جدید') && str_contains($html, 'چکِ دریافتی') && str_contains($html, 'فاکتور فروش') && !str_contains($html, 'فروش سریع'),
    'همان سه، با نامِ کوتاهِ کاشی');
T::ok(str_contains($html, h(Biz::url('payment.php?k=receipt&method=cheque'))), 'آدرس فرار داده شده (& → &amp;)');
T::ok(str_contains($html, 'settings.php') && str_contains($html, '#newmenu') && str_contains($html, 'چیدمانِ این منو را عوض کنید'), 'لینکِ «چیدمانِ این منو را عوض کنید»');

/* ---------------------------------------------------------------- */
T::group('۴ — صفحه‌ها با HTTP');
$port = 0;
for ($pp = 9541; $pp <= 9590; $pp++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$pp", $e1, $e2);
    if ($sock) { fclose($sock); $port = $pp; break; }
}
$log = tempnam(sys_get_temp_dir(), 'bnm');
$srv = $port ? (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
    $port, escapeshellarg($root), escapeshellarg($log)))) : 0;
$up = false;
for ($i = 0; $srv && $i < 40; $i++) {
    usleep(150000);
    $sk = @fsockopen('127.0.0.1', $port, $x, $y, 0.3);
    if ($sk) { fclose($sk); $up = true; break; }
}
if (!$up) {
    T::blocked('منوی «+» (HTTP)', 'سرورِ آزمایشی بالا نیامد');
} else {
    $jar = tempnam(sys_get_temp_dir(), 'bnmjar');
    $req = function (string $path, ?array $post = null) use ($port, $jar): array {
        $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 40]);
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        return [$code, $body];
    };
    $csrfOf = fn(string $html): string => preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m) ? $m[1] : '';
    [, $lp] = $req('store/login.php');
    T::same(302, $req('store/login.php', ['csrf_token' => $csrfOf($lp), 'username' => NPREFIX . 'a', 'password' => NPASS])[0], 'ورود');

    [$c, $dash] = $req('store/index.php');
    T::ok($c === 200 && str_contains($dash, '</html>'), 'داشبورد رندر شد');
    T::same(6, substr_count($dash, 'class="st-new-tile '), '⛔ هر دو دکمه‌ی «+» (بالا و منوی پایین) همان سه کاشی را دارند');
    T::ok(!str_contains($dash, 'class="st-new-tile is-tone-green" href="' . h(Biz::url('quick-sale.php')) . '"'), 'کاشیِ خاموش رندر نمی‌شود');

    [$c, $set] = $req('store/settings.php');
    T::ok($c === 200 && str_contains($set, 'id="newmenu"') && substr_count($set, 'name="menu[]"') === count(Biz::NEW_MENU),
        'تنظیمات: یک تیک برای هر قلمِ کاتالوگ');
    T::same(3, preg_match_all('/name="menu\[\]" value="[^"]*" checked>/', $set), 'سه تیکِ روشن (چیدمانِ فعلی)');
    $req('store/settings.php', ['action' => 'new_menu', 'menu' => ['party.php']]);
    Biz::forgetSettings($a);
    T::ok(!in_array('party.php', Biz::newMenu($a), true), '⛔ بی‌CSRF هیچ‌کار');
    [$c] = $req('store/settings.php', ['csrf_token' => $csrfOf($set), 'action' => 'new_menu', 'menu' => ['party.php', 'payment.php?k=expense']]);
    Biz::forgetSettings($a);
    T::ok($c === 302 && Biz::newMenu($a) === ['payment.php?k=expense', 'party.php'], 'ذخیره با فرم', json_encode(Biz::newMenu($a)));
    [, $dash] = $req('store/index.php');
    T::same(4, substr_count($dash, 'class="st-new-tile '), 'منو همان لحظه عوض شد (۲ کاشی × ۲ دکمه)');
    [$c] = $req('store/settings.php', ['csrf_token' => $csrfOf($set), 'action' => 'new_menu']);
    Biz::forgetSettings($a);
    T::ok($c === 302 && count(Biz::newMenu($a)) === 2, 'فرمِ بی‌هیچ تیک چیزی را خالی نکرد');
    exec("kill $srv 2>/dev/null");
    @unlink($jar);
}
@unlink($log);

} catch (Throwable $e) {
    T::ok(false, 'استثنا', $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

$wipe();
exit(T::report());
