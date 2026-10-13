<?php
/**
 * ⛔ رنگِ فروشگاه — لهجه‌ی سیستمِ طراحی که با پالت عوض می‌شود.
 *
 * **خواسته‌ی مالکِ نصب (بازطراحی):** «Accent سبز (هویت حساب‌لند)… بدون
 * گرادیان‌های آماتور». منوی طیف‌دارِ قبلی رفت؛ پالت فقط لهجه را عوض می‌کند.
 *
 * چهار خطر، همه بی‌صدا:
 *   ۱. پالتی در `Biz::PALETTES` باشد و در `store.css` نه (یا فقط روز، نه
 *      شب) — انتخابش هیچ اثری ندارد یا در شب رنگِ روز می‌ماند.
 *   ۲. لهجه روی کارت یا زیرِ متنِ دکمه ناخوانا شود.
 *   ۳. ذخیره‌ی پیش‌فرض با نام (نه NULL) — پیش‌فرضِ فردا به کسی نمی‌رسد.
 *   ۴. رنگِ یک فروشگاه روی فروشگاهِ دیگر بنشیند، یا نامِ دلخواه روی `<html>`.
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

$root = dirname(__DIR__);
const TPREFIX = '__bthm_';
const TPASS   = 'Theme12345';

$css = (string)preg_replace('#/\*.*?\*/#s', '', (string)file_get_contents($root . '/assets/css/store.css'));

/** @return array<string,string> */
function thTokens(string $body): array
{
    preg_match_all('/--([a-z0-9-]+)\s*:\s*([^;]+);/', $body, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $x) { $out[$x[1]] = trim($x[2]); }
    return $out;
}
/** همه‌ی بدنه‌های قاعده با این انتخابگرِ دقیق، به ترتیبِ فایل، با آفست. */
function thBlocks(string $css, string $sel): array
{
    preg_match_all('/(?:^|[{}])\s*' . preg_quote($sel, '/') . '\s*\{([^{}]*)\}/', $css, $m, PREG_OFFSET_CAPTURE);
    return array_map(fn($x) => [$x[0], $x[1]], $m[1]);
}
function thLum(array $c): float
{
    $v = [];
    foreach ($c as $x) { $x /= 255; $v[] = $x <= 0.03928 ? $x / 12.92 : (($x + 0.055) / 1.055) ** 2.4; }
    return 0.2126 * $v[0] + 0.7152 * $v[1] + 0.0722 * $v[2];
}
function thCr(array $a, array $b): float
{
    $x = thLum($a); $y = thLum($b);
    return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
}
function thHex(string $h): array { $h = ltrim($h, '#'); return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))]; }
function thMix(array $a, array $b, float $t): array { return [$a[0]*$t + $b[0]*(1-$t), $a[1]*$t + $b[1]*(1-$t), $a[2]*$t + $b[2]*(1-$t)]; }

// ---------------------------------------------------------------
T::group('۱ — هر پالت در CSS کامل است (روز و شب در یک بلوک)، و هیچ پالتی یتیم نیست');
// ---------------------------------------------------------------
// ⛔ سیستمِ طراحیِ فروشگاه: هر پالت فقط `--p-*` را عوض می‌کند و **یک** بلوک
//    دارد با هر دو رنگِ روز و شب؛ حالتِ شب فقط نگاشت می‌کند
//    (`--st-accent: var(--p-acc-n)`). پس «پالتی که در شب رنگِ روز می‌ماند»
//    یعنی توکنِ `-n` جا افتاده — همین سنجیده می‌شود.
$keys = array_keys(Biz::PALETTES);
$def  = $keys[0] ?? '';
T::same('emerald', $def, 'سبزِ حساب‌لند پیش‌فرض است (خواسته‌ی مالکِ نصب: لهجه‌ی سبز)');
T::ok(count($keys) >= 5, 'دست‌کم پنج رنگ', (string)count($keys));

$need = ['p-acc', 'p-acc2', 'p-acc-ink', 'p-wash', 'p-acc-n', 'p-acc2-n', 'p-acc-ink-n', 'p-wash-n'];
$roots = thBlocks($css, ':root');
$rootT = $roots ? thTokens($roots[0][0]) : [];
T::same($def, $rootT['palette-default'] ?? '', '⛔ نشانِ پیش‌فرضِ :root همان اولین کلیدِ Biz::PALETTES است');
T::ok(!str_contains($css, 'html[data-st-palette="' . $def . '"]'), 'پیش‌فرض بلوکِ data-st-palette ندارد (خودِ :root است)');

$pal = [$def => $rootT];
$badP = array_map(fn($tk) => "{$def} (:root): --{$tk}", array_values(array_diff($need, array_keys($rootT))));
foreach (array_slice($keys, 1) as $k) {
    $bl = thBlocks($css, 'html[data-st-palette="' . $k . '"]');
    if (count($bl) !== 1) { $badP[] = "{$k}: " . count($bl) . ' بلوک (باید یکی، با روز و شب)'; continue; }
    $T = thTokens($bl[0][0]);
    foreach ($need as $tk) { if (!isset($T[$tk])) { $badP[] = "{$k}: --{$tk}"; } }
    $pal[$k] = $T;
}
T::bulk(count($keys), $badP, 'هر رنگ هر هشت توکنش (روز و شب) را دارد');

preg_match_all('/html\[data-st-palette="([a-z]+)"\]/', $css, $cm);
$orph = array_values(array_diff(array_unique($cm[1]), $keys));
T::bulk(count(array_unique($cm[1])), array_map(fn($x) => "«{$x}» در CSS هست ولی در Biz::PALETTES نه", $orph), 'هیچ رنگِ یتیمی در store.css نیست');

$badS = [];
foreach ($keys as $k) {
    $acc = strtolower($pal[$k]['p-acc'] ?? '');
    if (!preg_match('/\.st-pal-swatch\[data-pal="' . $k . '"\]::after\s*\{[^}]*background:\s*(#[0-9a-f]{6})/i', $css, $sm) || strtolower($sm[1]) !== $acc) {
        $badS[] = "{$k}: نمونه‌ی انتخابگر رنگِ خودِ پالت (--p-acc) نیست";
    }
    if (strtolower(Biz::PALETTES[$k]['theme']) !== $acc) { $badS[] = "{$k}: theme با --p-accِ روز یکی نیست"; }
}
T::bulk(count($keys), $badS, 'هر رنگ نمونه‌ی خودش را دارد و رنگِ نوارِ وضعیتش همان --p-acc است');

// ⛔ دو درِ حالتِ شب (ویژگی و، بی‌اسکریپت، سیستم) باید **همان** نگاشت باشند
$darkAttr = thBlocks($css, ':root[data-st-theme="dark"]');
$darkSys  = thBlocks($css, ':root:not([data-st-theme])');
$dA = $darkAttr ? thTokens($darkAttr[0][0]) : [];
$dS = $darkSys ? thTokens($darkSys[0][0]) : [];
T::ok($dA !== [] && $dA === $dS, 'حالتِ شبِ انتخابی و حالتِ شبِ سیستم یک نگاشت‌اند (هیچ‌کدام از دیگری عقب نمانده)');
T::ok(($dA['st-accent'] ?? '') === 'var(--p-acc-n)' && ($dA['st-accent-ink'] ?? '') === 'var(--p-acc-ink-n)'
    && ($rootT['st-accent'] ?? '') === 'var(--p-acc)', 'لهجه در روز از --p-acc و در شب از --p-acc-n می‌آید');
T::ok(str_contains(Biz::bootScript(), "setAttribute('data-st-theme'"), 'اسکریپتِ سرآیند حالت را پیش از رندر روی <html> می‌گذارد (بی‌چشمک)');
// ⛔ «خودکار» پیش‌فرضِ همه (خواسته‌ی مالکِ نصب): یک بار انتخابِ دستیِ قدیمی پاک
//    می‌شود، و راهِ برگشت به خودکار هم هست — بدونِ آن، یک تپ روی ماه/خورشید
//    کاربر را برای همیشه دستی می‌کرد.
$boot = Biz::bootScript();
T::ok(str_contains($boot, "if(localStorage.getItem('st_theme_v')!=='2'){localStorage.removeItem('st_theme');localStorage.setItem('st_theme_v','2');}")
    && strpos($boot, "st_theme_v") < strpos($boot, "t=localStorage.getItem('st_theme')"),
    '⛔ فروشگاه: انتخابِ دستیِ قدیمی یک بار پاک می‌شود، پیش از خواندنِ حالت');
$hdr = (string)file_get_contents(__DIR__ . '/../includes/header.php');
T::ok(preg_match("~if \(localStorage\.getItem\('daftar_theme_v'\) !== '2'\) \{\s*localStorage\.removeItem\('daftar_theme'\);~", $hdr) === 1
    && strpos($hdr, 'daftar_theme_v') < strpos($hdr, "var mode = localStorage.getItem('daftar_theme') || 'auto';"),
    '⛔ شخصی: همان، و پیش‌فرضِ بی‌انتخاب «auto»');
$stjs = (string)file_get_contents(__DIR__ . '/../assets/js/store.js');
$sset = (string)file_get_contents(__DIR__ . '/../store/settings.php');
T::ok(str_contains($sset, '<input type="checkbox" data-theme-auto') && str_contains($stjs, "store('st_theme', null);"),
    '⛔ فروشگاه: کلیدِ «خودکار» در تنظیمات هست و انتخابِ دستی را پاک می‌کند');

// ---------------------------------------------------------------
T::group('۲ — خوانایی در هر رنگ، روز و شب (از خودِ توکن‌ها، نه کپیِ محلی)');
// ---------------------------------------------------------------
$surfL = thHex($rootT['st-surface'] ?? '#ffffff');
$surfD = thHex($dA['st-surface'] ?? '#14171c');
$bad = []; $n = 0;
foreach ($pal as $k => $T) {
    foreach (['روز' => ['p-acc', 'p-acc-ink', 'p-wash', $surfL], 'شب' => ['p-acc-n', 'p-acc-ink-n', 'p-wash-n', $surfD]] as $mode => [$ac, $ink, $wash, $surf]) {
        $n++;
        foreach ([$ac, $ink, $wash] as $tk) {
            if (!preg_match('/^#[0-9a-f]{6}$/i', $T[$tk] ?? '')) { $bad[] = "{$k} ({$mode}): --{$tk} رنگِ شش‌رقمی نیست"; continue 2; }
        }
        if (($r = thCr(thHex($T[$ink]), thHex($T[$ac]))) < 4.5) { $bad[] = sprintf('%s (%s): متنِ دکمه‌ی لهجه %.2f', $k, $mode, $r); }
        if (($r = thCr(thHex($T[$ac]), $surf)) < 4.5) { $bad[] = sprintf('%s (%s): لینک/لهجه روی کارت %.2f', $k, $mode, $r); }
        // آیکونِ قلمِ فعالِ منو روی زمینه‌ی آرامِ لهجه — عنصرِ غیرمتنی، کفِ ۳
        if (($r = thCr(thHex($T[$ac]), thHex($T[$wash]))) < 3.0) { $bad[] = sprintf('%s (%s): آیکونِ فعال روی زمینه‌ی آرام %.2f', $k, $mode, $r); }
    }
}
T::bulk($n, $bad, 'دکمه، لینک و قلمِ فعالِ منو در هر رنگ (روز و شب) خوانا هستند');
$ink = thHex($rootT['st-ink'] ?? '#000000'); $inkD = thHex($dA['st-ink'] ?? '#ffffff');
T::ok(thCr($ink, $surfL) >= 7 && thCr($inkD, $surfD) >= 7, 'متنِ اصلی روی کارت، روز و شب، دست‌کم ۷ تضاد دارد');
T::ok(thCr(thHex($rootT['st-primary-ink'] ?? '#fff'), thHex($rootT['st-primary'] ?? '#000')) >= 7
    && thCr(thHex($dA['st-primary-ink'] ?? '#000'), thHex($dA['st-primary'] ?? '#fff')) >= 7, 'دکمه‌ی اصلیِ زغالی (و وارونه‌ی شبش) خواناست');

// ---------------------------------------------------------------
try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::blocked('رنگِ فروشگاه', 'اتصال به دیتابیس برقرار نشد: ' . $e->getMessage());
    exit(T::report());
}
if (!Biz::available() || !tableHasColumn('biz_settings', 'palette')) {
    T::blocked('رنگِ فروشگاه', 'ستونِ palette نیست — اول: bash deploy/migrate.sh --apply');
    exit(T::report());
}

$wipe = function () use ($pdo) {
    foreach ($pdo->query("SELECT id FROM users WHERE username LIKE '" . TPREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        deleteUserAccount((int)$id);
    }
    $pdo->exec("DELETE FROM login_attempts WHERE username_tried LIKE '" . TPREFIX . "%'");
};
$wipe();
$make = function (string $name) use ($pdo): int {
    $res = createUserAccount($pdo, 'فروشگاهِ ' . $name, TPREFIX . $name, TPREFIX . $name . '@example.com', TPASS);
    if (!($res['ok'] ?? false)) { throw new RuntimeException('ساختِ کاربر: ' . ($res['error'] ?? '?')); }
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => TPREFIX . $name]);
    return (int)$st->fetchColumn();
};
$a = $make('a');
$b = $make('b');
Biz::setType($a, 'business');
Biz::setType($b, 'business');
$raw = function (int $uid) use ($pdo) {
    $st = $pdo->prepare('SELECT palette FROM biz_settings WHERE user_id = :u');
    $st->execute(['u' => $uid]);
    return $st->fetchColumn();
};

T::group('۳ — ذخیره: پیش‌فرض NULL، نامِ ناشناخته رد، هر فروشگاه جدا');
T::same($def, Biz::palette($a), 'فروشگاهِ تازه رنگِ پیش‌فرض دارد');
T::ok(Biz::savePalette($a, 'ocean')['ok'], 'ذخیره‌ی «اقیانوس»');
T::same('ocean', Biz::palette($a), 'palette() همان را می‌خواند (کشِ درخواست تازه شد)');
T::same('ocean', $raw($a), 'در دیتابیس نام نشست');
T::same($def, Biz::palette($b), '⛔ رنگِ فروشگاهِ A روی B ننشست');
$r = Biz::savePalette($a, 'x" onload="alert(1)');
T::ok(!$r['ok'] && Biz::palette($a) === 'ocean', '⛔ نامِ ناشناخته رد شد و رنگِ قبلی ماند');
Biz::savePalette($a, $def);
T::ok($raw($a) === null, '⛔ پیش‌فرض NULL ذخیره شد، نه نامش (پیش‌فرضِ فردا به همه برسد)');
$pdo->prepare('UPDATE biz_settings SET palette = :p WHERE user_id = :u')->execute(['p' => 'neon', 'u' => $a]);
$fresh = (new ReflectionClass('Biz'));
foreach (['settingsCache', 'paletteCache'] as $prop) { $pp = $fresh->getProperty($prop); PHP_VERSION_ID < 80100 && $pp->setAccessible(true); $pp->setValue(null, []); }
T::same($def, Biz::palette($a), 'مقدارِ ناشناخته در دیتابیس به پیش‌فرض برمی‌گردد');
Biz::savePalette($a, $def);

// ---------------------------------------------------------------
T::group('۴ — با HTTP و نشستِ واقعی');
// ---------------------------------------------------------------
$port = 0;
for ($pp = 18470; $pp < 18490; $pp++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$pp", $e1, $e2);
    if ($sock) { fclose($sock); $port = $pp; break; }
}
$log = tempnam(sys_get_temp_dir(), 'bthm');
$srv = $port ? (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
    $port, escapeshellarg($root), escapeshellarg($log)))) : 0;
$up = false;
for ($i = 0; $srv && $i < 40; $i++) {
    usleep(150000);
    $sk = @fsockopen('127.0.0.1', $port, $x, $y, 0.3);
    if ($sk) { fclose($sk); $up = true; break; }
}
if (!$up) {
    T::blocked('رنگِ فروشگاه (HTTP)', 'سرورِ آزمایشی بالا نیامد');
    if ($srv) { exec("kill $srv 2>/dev/null"); }
    $wipe();
    exit(T::report());
}
$jar = tempnam(sys_get_temp_dir(), 'bthmjar');
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
[$c] = $req('store/login.php', ['csrf_token' => $csrfOf($lp), 'username' => TPREFIX . 'a', 'password' => TPASS]);
T::ok($c === 302, 'ورود به فروشگاه');

[$c, $home] = $req('store/index.php');
T::ok($c === 200 && str_contains($home, '<html lang="fa" dir="rtl" data-area="store">'), 'پیش‌فرض هیچ ویژگیِ رنگی روی <html> ندارد (فقط محیطِ خطا، `data-area`)');
T::ok(str_contains($home, '<meta name="theme-color" content="' . Biz::PALETTES[$def]['theme'] . '">'), 'نوارِ وضعیتِ گوشی رنگِ پیش‌فرض را دارد');

[$c, $set] = $req('store/settings.php');
T::same(count($keys), substr_count($set, 'name="palette"'), 'انتخابگر یک رادیو برای هر رنگ دارد');
T::ok(str_contains($set, 'value="' . $def . '" checked'), 'رنگِ جاری انتخاب‌شده است');
[$c] = $req('store/settings.php', ['action' => 'palette', 'palette' => 'lilac']);
T::ok($c !== 302 && $raw($a) === null, '⛔ بی‌CSRF هیچ چیزی ذخیره نشد');
[$c, $loc] = $req('store/settings.php', ['csrf_token' => $csrfOf($set), 'action' => 'palette', 'palette' => 'lilac']);
T::ok($c === 302, 'ذخیره با CSRF → ریدایرکت');
[$c, $home] = $req('store/index.php');
T::ok(str_contains($home, '<html lang="fa" dir="rtl" data-area="store" data-st-palette="lilac">'), 'همان صفحه‌ی بعد <html> رنگِ «یاس» را دارد (سمتِ سرور، بی‌اسکریپت)');
T::ok(str_contains($home, '<meta name="theme-color" content="' . Biz::PALETTES['lilac']['theme'] . '">'), 'نوارِ وضعیتِ گوشی هم «یاس» شد');
[$c, $set] = $req('store/settings.php');
T::ok(str_contains($set, 'value="lilac" checked'), 'انتخابگر رنگِ ذخیره‌شده را نشان می‌دهد');
[$c] = $req('store/settings.php', ['csrf_token' => $csrfOf($set), 'action' => 'palette', 'palette' => 'neon']);
// ⚠ از دیتابیس، نه `palette()`: کشِ همین پروسه مقدارِ پیش از درخواستِ HTTP را دارد
T::same('lilac', $raw($a), '⛔ رنگِ ناشناخته از فرم هم رد شد');
T::ok($raw($b) === null || $raw($b) === false, '⛔ فروشگاهِ B دست نخورد');
[$c, $set] = $req('store/settings.php');
T::ok(str_contains($set, 'value="lilac" checked') && str_contains($set, 'name="shop_name"'), 'فرمِ سربرگ کنارِ رنگ سرِ جایش است');

exec("kill $srv 2>/dev/null");
@unlink($log); @unlink($jar);
$wipe();
exit(T::report());
