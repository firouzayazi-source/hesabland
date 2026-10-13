<?php
/**
 * پالت‌های رنگ (پروفایل → «نمایش») و فهرستِ بی‌سرِ روزِ خانه.
 *
 * ⛔ سه خرابیِ بی‌صدا، که هیچ‌کدام خطا نمی‌دهند:
 *
 *    ۱. **پالتِ ناقص.** هر پالت کلِ مجموعه‌ی توکن‌ها را بازتعریف می‌کند؛
 *       توکنی که جا بماند از زمرد به ارث می‌رسد و روی پالتِ نارنجی یک
 *       خاکستریِ ته‌رنگ‌سبز یا یک چیپِ فعالِ سبز می‌نشیند. فهرستِ
 *       توکن‌های لازم از **خودِ بخشِ ۴۰** کشف می‌شود، نه از یک آرایه‌ی
 *       دستی — وگرنه توکنِ فردا بی‌صدا بیرونِ پوشش می‌ماند.
 *    ۲. **پالتِ ناخوانا.** رنگِ تعامل (`--gold`) روی کارتِ سفید و متنِ
 *       سفید روی برند باید خوانا باشند (WCAG ≥ ۴٫۵). «زیباست» جای
 *       اندازه‌گیری را نمی‌گیرد.
 *    ۳. **پالتی که در شب برنده نمی‌شود.** بخشِ ۳۴ یک قاعده‌ی بنفشِ
 *       حالتِ شب برای کارتِ ماه دارد با وزنِ بیشتر؛ در اولین نسخه هر پنج
 *       پالت در شب بنفش می‌شدند. این را فقط مرورگر می‌بیند (بخشِ ۳).
 *
 * ⚠ بخش ۱ و ۲ بی‌دیتابیس‌اند؛ بخش ۳ کرومیوم و دیتابیس می‌خواهد.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/../includes/functions.php';

$root = realpath(__DIR__ . '/..');
$css  = (string)file_get_contents($root . '/assets/css/style.css');
$cssNc = (string)preg_replace('#/\*.*?\*/#s', '', $css);

/** بدنه‌ی اولین قاعده با این انتخابگرِ دقیق، بعد از آفست. */
function palBlock(string $css, string $selector, int $from = 0): ?string
{
    $re = '/(?:^|\})\s*' . preg_quote($selector, '/') . '\s*\{([^{}]*)\}/';
    if (preg_match($re, substr($css, $from), $m)) { return $m[1]; }
    return null;
}
/** @return array<string,string> */
function palTokens(?string $body): array
{
    $out = [];
    if ($body === null) { return $out; }
    preg_match_all('/--([a-z0-9-]+)\s*:\s*([^;]+);/', $body, $m, PREG_SET_ORDER);
    foreach ($m as $x) { $out[$x[1]] = trim($x[2]); }
    return $out;
}
function palLum(string $hex): float
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) { $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2]; }
    $c = [];
    foreach ([0, 2, 4] as $i) {
        $v = hexdec(substr($hex, $i, 2)) / 255;
        $c[] = $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    }
    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
}
function palContrast(string $a, string $b): float
{
    $x = palLum($a); $y = palLum($b);
    return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
}

// ---------------------------------------------------------------
T::group('فهرستِ پالت‌ها و کاملیِ هر پالت');
// ---------------------------------------------------------------
$keys = array_keys(UI_PALETTES);
// ⛔ خواسته‌ی مالکِ نصب: «پیش‌فرض رنگ و تم نیلی باشه برای همه».
T::same('indigo', $keys[0] ?? null, 'نیلی اولین (پیش‌فرض) است');
$def = $keys[0] ?? '';
T::ok(count($keys) >= 5, 'دست‌کم پنج پالت', (string)count($keys));
$bad = [];
foreach (UI_PALETTES as $k => $label) {
    if (!preg_match('/^[a-z]{3,12}$/', $k)) { $bad[] = "کلیدِ «{$k}» شکلِ مجاز ندارد"; }
    if (trim($label) === '') { $bad[] = "{$k} برچسب ندارد"; }
}
T::bulk(count($keys), $bad, 'کلیدها فقط حرفِ کوچکِ لاتین‌اند (همان الگوی سرآیند) و برچسب دارند');

// کشفِ توکن‌های لازم از بخشِ ۴۰ — همان دو بلوکی که پیش‌فرض را می‌سازند.
// ⛔ لنگر `--palette-default:` است، نه یک رنگ: پیش‌فرض یک بار عوض شد و
//    لنگرِ رنگی همان روز بی‌صدا بخشِ اشتباهی را می‌گرفت.
$s40 = strpos($cssNc, '--palette-default:');
$s40 = $s40 === false ? -1 : strrpos(substr($cssNc, 0, $s40), ':root');
$emerL = $s40 >= 0 ? palTokens(palBlock($cssNc, ':root', $s40)) : [];
$emerD = $s40 >= 0 ? palTokens(palBlock($cssNc, 'html[data-theme="dark"]', $s40)) : [];
T::ok(count($emerL) >= 30, 'توکن‌های روزِ پیش‌فرض از بخشِ ۴۰ کشف شد', (string)count($emerL));
T::ok(count($emerD) >= 15, 'توکن‌های شبِ پیش‌فرض از بخشِ ۴۰ کشف شد', (string)count($emerD));
foreach (['rb1', 'rb2', 'rb3', 'fab1', 'fab-ink', 'ph1', 'tint', 'rb-in'] as $must) {
    T::ok(isset($emerL[$must]), "پیش‌فرض توکنِ --{$must} را تعریف می‌کند");
}
// ⚠ `--brand-on` و `--r*` مشترک‌اند و عمداً در پالت‌ها تکرار نمی‌شوند.
$shared = ['brand-on', 'palette-default'];

$badL = []; $badD = []; $badS = [];
$pal = [];
foreach (array_slice($keys, 1) as $k) {
    $L = palTokens(palBlock($cssNc, 'html[data-palette="' . $k . '"]'));
    $D = palTokens(palBlock($cssNc, 'html[data-theme="dark"][data-palette="' . $k . '"]'));
    $pal[$k] = [$L, $D];
    foreach (array_diff(array_keys($emerL), array_keys($L), $shared) as $miss) { $badL[] = "{$k}: --{$miss}"; }
    foreach (array_diff(array_keys($emerD), array_keys($D), $shared) as $miss) { $badD[] = "{$k} (شب): --{$miss}"; }
    if (!preg_match('/\.palette-swatch\[data-pal="' . $k . '"\]\s*\{[^}]*linear-gradient/', $cssNc)) { $badS[] = $k; }
}
if (!preg_match('/\.palette-swatch\[data-pal="' . $def . '"\]\s*\{[^}]*linear-gradient/', $cssNc)) { $badS[] = $def; }
// ⛔ و پیش‌فرض خودش بلوکِ ویژگی ندارد: `:root` است. بلوکی برایش یعنی
//    دو مرجع، که دیر یا زود از هم دور می‌افتند.
T::ok(strpos($cssNc, 'html[data-palette="' . $def . '"]') === false, 'پالتِ پیش‌فرض بلوکِ data-palette ندارد (خودِ :root است)');
T::same($def, trim((string)(palTokens(palBlock($cssNc, ':root', $s40))['palette-default'] ?? '')), 'نشانِ --palette-default بخشِ ۴۰ همان اولین کلیدِ UI_PALETTES است');
T::bulk(count($keys) - 1, $badL, 'هر پالت هر توکنِ روزِ پیش‌فرض را بازتعریف می‌کند');
T::bulk(count($keys) - 1, $badD, 'هر پالت هر توکنِ شبِ پیش‌فرض را بازتعریف می‌کند');
T::bulk(count($keys), $badS, 'هر پالت نمونه‌ی طیف‌دارِ خودش را در انتخابگر دارد');

// ⛔ و برعکس: پالتی که در CSS هست ولی در فهرست نه، یتیم است — کسی که
//    آن را انتخاب کرده بود با بارگذاریِ بعدی بی‌صدا به زمرد برمی‌گردد.
preg_match_all('/html\[data-palette="([a-z]+)"\]/', $cssNc, $cm);
$orph = array_values(array_diff(array_unique($cm[1]), $keys));
T::bulk(count(array_unique($cm[1])), array_map(fn($x) => "«{$x}» در CSS هست ولی در UI_PALETTES نه", $orph),
    'هیچ پالتِ یتیمی در style.css نیست');

// ---------------------------------------------------------------
T::group('خوانایی — اندازه‌گیری، نه سلیقه');
// ---------------------------------------------------------------
$pal = [$def => [$emerL, $emerD]] + $pal;
$badC = [];
$n = 0;
foreach ($pal as $k => [$L, $D]) {
    $D += $L;          // شب آنچه را تعریف نکرده از روزِ همان پالت می‌گیرد
    $checks = [
        ['--gold روی --surface (روز)',       $L['gold'] ?? '',      $L['surface'] ?? '',  4.5],
        ['--brand-fg روی --surface (روز)',   $L['brand-fg'] ?? '',  $L['surface'] ?? '',  4.5],
        ['متنِ سفید روی --brand (روز)',      '#ffffff',             $L['brand'] ?? '',    4.5],
        ['--gold روی --surface (شب)',        $D['gold'] ?? '',      $D['surface'] ?? '',  4.5],
        ['--brand-fg روی --surface (شب)',    $D['brand-fg'] ?? '',  $D['surface'] ?? '',  4.5],
        ['--ink روی --paper (شب)',           $D['ink'] ?? '',       $D['paper'] ?? '',    7.0],
        ['--fab-ink روی --fab1 (روز)',       $L['fab-ink'] ?? '',   $L['fab1'] ?? '',     4.5],
        ['--fab-ink روی --fab2 (شب)',        $D['fab-ink'] ?? '',   $D['fab2'] ?? '',     4.5],
    ];
    foreach ($checks as [$what, $fg, $bg, $min]) {
        $n++;
        if (!preg_match('/^#[0-9a-f]{3,6}$/i', $fg) || !preg_match('/^#[0-9a-f]{3,6}$/i', $bg)) {
            $badC[] = "{$k}: {$what} — رنگ پیدا نشد"; continue;
        }
        $r = palContrast($fg, $bg);
        if ($r < $min) { $badC[] = sprintf('%s: %s = %.2f (کمینه %.1f)', $k, $what, $r, $min); }
    }
}
T::bulk($n, $badC, 'تضادِ رنگ‌های متن و تعامل در هر پالت و هر دو حالت کافی است');

// ⛔ منوی کناریِ دسکتاپ طیفِ همان پالت است (`--rb2 → --rb3`)، پس خوانایی‌اش
//    به هر شش پالت بند است. اعداد از **خودِ** قاعده خوانده می‌شوند (درصدِ
//    آمیختن، پرده‌ی تیره، شفافیتِ متن)، نه از یک کپیِ محلی — وگرنه با عوض
//    شدنِ CSS این سنجه همان عددهای قدیمی را می‌سنجید و سبز می‌ماند.
$sbPos  = strrpos($cssNc, 'html[data-theme="dark"] .sidebar {');
$sbBody = $sbPos === false ? '' : (string)substr($cssNc, $sbPos, (int)strpos($cssNc, '}', $sbPos) - $sbPos);
$okMix  = preg_match('/color-mix\(in srgb,\s*var\(--rb2\)\s*(\d+)%,\s*var\(--rb3\)\)/', $sbBody, $mm);
$okVeil = preg_match('/rgba\(0,\s*0,\s*0,\s*(\.\d+|0\.\d+)\)/', $sbBody, $vm);
$okTxt  = preg_match('/\.sidebar-nav a,\s*\.logout-link\s*\{[^}]*?(?<![\w-])color:\s*rgba\(255,\s*255,\s*255,\s*(\.\d+|0\.\d+)\)/', $cssNc, $tm);
T::ok($okMix && $okVeil && $okTxt, 'منوی کناری از --rb2/--rb3 می‌سازد و پرده و شفافیتِ متنش پیدا شد',
    $okMix ? '' : 'color-mix(--rb2, --rb3) پیدا نشد');
if ($okMix && $okVeil && $okTxt) {
    $hex = static fn (string $h): array => array_map('hexdec', str_split(ltrim($h, '#'), 2));
    $mix = static fn (array $a, array $b, float $t): array => [$a[0]*$t + $b[0]*(1-$t), $a[1]*$t + $b[1]*(1-$t), $a[2]*$t + $b[2]*(1-$t)];
    $toHex = static fn (array $c): string => sprintf('#%02x%02x%02x', (int)round($c[0]), (int)round($c[1]), (int)round($c[2]));
    $badSb = []; $nSb = 0;
    foreach ($pal as $k => [$L, $D]) {
        foreach (['روز' => $L, 'شب' => $D + $L] as $mode => $T) {
            $nSb++;
            if (!isset($T['rb2'], $T['rb3'])) { $badSb[] = "{$k} ({$mode}): --rb2/--rb3 پیدا نشد"; continue; }
            $bg  = $mix([0, 0, 0], $mix($hex($T['rb2']), $hex($T['rb3']), (float)$mm[1] / 100), (float)$vm[1]);
            $fg  = $mix([255, 255, 255], $bg, (float)$tm[1]);
            $r = palContrast($toHex($fg), $toHex($bg));
            if ($r < 4.5) { $badSb[] = sprintf('%s (%s): متنِ منو روی طیف = %.2f', $k, $mode, $r); }
        }
    }
    T::bulk($nSb, $badSb, 'متنِ منوی کناری روی طیفِ هر پالت (روز و شب) دست‌کم ۴٫۵ تضاد دارد');
}

// ---------------------------------------------------------------
T::group('در مرورگر — پالت واقعاً برنده می‌شود، و خانه بی‌سرِ روز است');
// ---------------------------------------------------------------
$node = trim((string)@shell_exec('command -v node 2>/dev/null'));
if ($node === '') { T::skip('پالت در مرورگر', 'node نصب نیست'); exit(T::report()); }
if (!file_exists($root . '/config/config.php')) { T::blocked('پالت در مرورگر', 'config/config.php وجود ندارد'); exit(T::report()); }

require_once $root . '/includes/db.php';
require_once $root . '/includes/user_data.php';
try { $pdo = Database::getConnection(); }
catch (Throwable $e) { T::blocked('پالت در مرورگر', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }

$USER = ['palette_u', 'Pal#probe9'];
$serverPid = 0;
$jar = tempnam(sys_get_temp_dir(), 'pljar');
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
    $pdo->prepare("INSERT INTO users (full_name, username, password_hash, role, is_active)
                   VALUES ('کاربر پالت', :u, :p, 'user', 1)")
        ->execute(['u' => $USER[0], 'p' => password_hash($USER[1], PASSWORD_DEFAULT)]);
    $uid = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO wallets (user_id,name,kind,initial_balance,is_active,sort_order)
                   VALUES (:u,'کیف پول','cash',1000000,1,0)")->execute(['u' => $uid]);
    $wid = (int)$pdo->lastInsertId();
    try { $pdo->prepare('UPDATE users SET balance_setup_at = NOW() WHERE id = :u')->execute(['u' => $uid]); } catch (Throwable $e) {}
    $cat = (int)$pdo->query("SELECT id FROM categories WHERE type='expense' AND user_id IS NULL ORDER BY id LIMIT 1")->fetchColumn();
    // ⛔ دو روزِ متفاوت، وگرنه گروه‌بندیِ روزانه‌ی صفحه‌ی تراکنش‌ها هم
    //    فقط یک سر می‌ساخت و بررسیِ «خانه صفر سر» چیزی را نمی‌سنجید.
    foreach ([0, 0, 1, 2] as $i => $ago) {
        $pdo->prepare("INSERT INTO transactions (user_id,type,amount,title,transaction_date,category_id,wallet_id)
                       VALUES (:u,'expense',:a,'خرید پالت',:d,:c,:w)")
            ->execute(['u' => $uid, 'a' => 100000 + $i, 'd' => date('Y-m-d', strtotime("-$ago day")), 'c' => $cat, 'w' => $wid]);
    }

    $port = 0;
    for ($p = 8971; $p <= 8989; $p++) {
        $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
        if ($sock) { fclose($sock); $port = $p; break; }
    }
    if (!$port) { T::skip('پالت در مرورگر', 'پورت آزاد پیدا نشد'); $cleanup(); exit(T::report()); }
    $log = tempnam(sys_get_temp_dir(), 'plsrv');
    $serverPid = (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
        $port, escapeshellarg($root), escapeshellarg($log))));
    $up = false;
    for ($i = 0; $i < 40; $i++) {
        usleep(150000);
        $s = @fsockopen('127.0.0.1', $port, $a, $b, 0.3);
        if ($s) { fclose($s); $up = true; break; }
    }
    if (!$up) { T::blocked('پالت در مرورگر', 'سرور آزمایشی بالا نیامد'); $cleanup(); exit(T::report()); }

    $req = function (string $path, ?array $post = null) use ($port, $jar): array {
        $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar,
            CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 25]);
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        return [$code, $body];
    };
    [, $html] = $req('login.php');
    preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m2);
    $pdo->exec("DELETE FROM login_attempts WHERE request_ip = '127.0.0.1'");
    [$code] = $req('login.php', ['csrf_token' => $m2[1] ?? '', 'username' => $USER[0], 'password' => $USER[1]]);
    T::ok($code === 302 || $code === 303, 'ورودِ کاربرِ آزمایشی');

    // سمتِ سرور، بی‌مرورگر: خانه هیچ سرِ روزی ندارد، صفحه‌ی تراکنش‌ها دارد.
    [, $home] = $req('index.php');
    [, $txp]  = $req('transactions.php');
    T::same(0, substr_count($home, 'class="tx-day-header"'), 'خانه: «آخرین تراکنش‌ها» بی‌سرِ روز است');
    T::ok(substr_count($home, 'class="tx-row"') >= 4, 'خانه: ردیف‌ها همچنان رندر می‌شوند',
        (string)substr_count($home, 'class="tx-row"'));
    T::ok(substr_count($txp, 'class="tx-day-header"') >= 3, 'صفحه‌ی تراکنش‌ها سرِ روز را نگه داشته',
        (string)substr_count($txp, 'class="tx-day-header"'));
    [, $prof] = $req('profile.php');
    T::same(count($keys), substr_count($prof, 'name="ui_palette"'), 'انتخابگرِ پروفایل همه‌ی پالت‌ها را دارد');

    $sess = '';
    foreach (explode("\n", (string)@file_get_contents($jar)) as $line) {
        $parts = preg_split('/\t/', trim($line));
        if (count($parts) >= 7 && $parts[5] === 'DAFTAR_SESSION') { $sess = $parts[6]; }
    }
    if ($sess === '') { T::blocked('پالت در مرورگر', 'کوکیِ نشست پیدا نشد'); $cleanup(); exit(T::report()); }

    $expect = [];
    foreach ($pal as $k => [$L, $D]) {
        $expect[$k] = ['light' => ['brand' => $L['brand'], 'rb1' => $L['rb1']],
                       'dark'  => ['brand' => ($D + $L)['brand'], 'rb1' => ($D + $L)['rb1']]];
    }
    $cmd = escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/palette_probe.js') . ' '
         . escapeshellarg("http://127.0.0.1:{$port}/") . ' ' . escapeshellarg($sess) . ' '
         . escapeshellarg(implode(',', $keys)) . ' 2>/dev/null';
    $out = json_decode(trim((string)shell_exec($cmd)), true);
    if (!is_array($out) || empty($out['ok'])) {
        $why = is_array($out) ? ($out['why'] ?? '?') : 'خروجیِ نامعتبر';
        if ($why === 'no_chromium') { T::skip('پالت در مرورگر', 'کرومیوم نصب نیست'); }
        else { T::ok(false, 'probe اجرا شد', $why); }
        $cleanup(); exit(T::report());
    }

    $hex2rgb = static function (string $h): string {
        $h = ltrim($h, '#');
        return sprintf('rgb(%d, %d, %d)', hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2)));
    };
    $badB = []; $badR = []; $badM = [];
    foreach ($expect as $k => $modes) {
        foreach ($modes as $mode => $e) {
            $got = $out['pages'][$k][$mode] ?? null;
            if (!$got) { $badB[] = "{$k}/{$mode}: اندازه‌ای نیامد"; continue; }
            if (strcasecmp($got['brand'], $e['brand']) !== 0) { $badB[] = "{$k}/{$mode}: --brand = {$got['brand']} (انتظار {$e['brand']})"; }
            if (strpos($got['ribbon'], $hex2rgb($e['rb1'])) === false) { $badR[] = "{$k}/{$mode}: کارتِ ماه {$got['ribbon']}"; }
            if (strcasecmp($got['meta'], $e['brand']) !== 0) { $badM[] = "{$k}/{$mode}: theme-color = {$got['meta']}"; }
        }
    }
    $nm = count($expect) * 2;
    T::bulk($nm, $badB, 'هر پالت در روز و شب --brand ِ خودش را می‌گیرد');
    T::bulk($nm, $badR, 'کارتِ ماه در روز و شب طیفِ همان پالت را دارد (نه بنفشِ بخشِ ۳۴)');
    T::bulk($nm, $badM, 'رنگِ نوارِ وضعیتِ گوشی (theme-color) با برندِ پالت هم‌خوان است');

    $pk = $out['picker'] ?? [];
    // ⚠ `??` مقدارِ null را پنهان می‌کند (`null ?? 'x'` همان 'x' است)، پس
    //    کلید مستقیم خوانده می‌شود — همان درسِ test_phone_signup.
    $pv = static fn(string $k) => array_key_exists($k, $pk) ? $pk[$k] : '__missing__';
    T::same($def, $pv('initialChecked'), 'بی‌انتخاب: پیش‌فرض (نیلی) علامت خورده');
    T::same(null, $pv('initialAttr'), 'بی‌انتخاب: هیچ data-palette ای روی html نیست');
    T::same('ocean', $pv('afterAttr'), 'زدنِ «اقیانوس» همان لحظه اعمال می‌شود');
    T::same('ocean', $pv('afterLS'), '… و در این مرورگر می‌ماند');
    T::same(strtolower($pal['ocean'][0]['brand'] ?? ''), strtolower((string)$pv('afterMeta')), '… و رنگِ نوارِ وضعیت همان لحظه عوض می‌شود');
    T::same('ocean', $pv('persistAttr'), '… و صفحه‌ی بعد هم همان است');
    T::same(null, $pv('bogusAttr'), 'نامِ ناشناخته در localStorage به پیش‌فرض برمی‌گردد');
    T::same(null, $pv('bogusLS'), '… و با باز کردنِ پروفایل پاک می‌شود');
    T::same(null, $pv('emeraldAttr'), 'برگشت به پیش‌فرض ویژگی را برمی‌دارد');
    T::same('emerald', $pv('greenAttr'), 'زدنِ «زمرد» (که دیگر پیش‌فرض نیست) ویژگیِ خودش را می‌نویسد');
    T::same(0, $pv('hscroll'), 'پروفایل روی ۳۹۰ اسکرولِ افقی ندارد');
    T::same([], $out['errors'] ?? ['?'], 'هیچ خطای جاوااسکریپتی در مسیر نبود');
} finally {
    $cleanup();
}

exit(T::report());
