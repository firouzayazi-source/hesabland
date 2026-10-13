<?php
/**
 * تقویمِ خانه — از زبانه‌ی «سررسیدها» به خانه.
 *
 * **خواسته‌ی مالکِ نصب:** «تقویم رو از داخل یادآوری من بیار بیرون یه جای
 * خوشگل توی داشبورد چه در گوشی و چه در دسکتاپ براش بساز از جای قبلی
 * پاکش کن.»
 *
 * سه لایه:
 *   ۱. توابعِ خالص (بی‌دیتابیس): ماه، آدرس، شبکه‌ی روزها (روزِ اولِ هفته،
 *      اسفندِ کبیسه، جمعه، ردیفِ هفته‌ی امروز).
 *   ۲. داده: نقطه‌ها و جمع‌ها از تراکنش و `financialEvents()`، و جداییِ
 *      کاربران.
 *   ۳. HTTP: جا — گوشی بالای «گزارش» و نه خانه، دسکتاپ زیرِ «آخرین
 *      تراکنش‌ها» و نه ستونِ کناری («تقویم بهتر بیاد زیر تراکنش‌ها و حساب‌ها
 *      برگردن سر جای خودشون») — اندپوینت، هدایتِ آدرس‌های قدیمی، و نبودنِ
 *      زبانه در `due.php`.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';
$root = realpath(__DIR__ . '/..');
// ⚠ `home_calendar.php` بی‌`APP_BASE_PATH` بی‌صدا ۴۰۴ می‌دهد و خارج می‌شود
//   (نگهبانِ partial) — پس بی‌کانفیگ «مسدود»، نه یک اجرای خالیِ سبز.
if (!file_exists($root . '/config/config.php')) { T::blocked('تقویمِ خانه', 'config/config.php وجود ندارد'); exit(T::report()); }
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/home_calendar.php';

/** دکمه‌های روز: [class, date, inner] */
$cells = function (string $html): array {
    preg_match_all('~<button type="button" class="(hcal-d[^"]*)" data-date="([0-9-]+)"[^>]*>(.*?)</button>~s', $html, $m, PREG_SET_ORDER);
    return array_map(fn($x) => [$x[1], $x[2], $x[3]], $m);
};
$has = fn(string $cls, string $c): bool => in_array($c, explode(' ', $cls), true);

// ---------------------------------------------------------------
T::group('تقویمِ خانه — ماه و آدرس');
[$ty, $tm] = homeCalendarToday();
T::same([$ty, $tm], homeCalendarMonth(0, 0), 'بی‌پارامتر = ماهِ امروز');
T::same([1405, 1], homeCalendarMonth(1404, 13), 'ماهِ ۱۳ = فروردینِ سالِ بعد');
T::same([1403, 12], homeCalendarMonth(1404, 0), 'ماهِ ۰ = اسفندِ سالِ قبل');
T::same([$ty, $tm], homeCalendarMonth(1999, 5), 'سالِ بیرون از بازه = ماهِ امروز');
unset($_COOKIE[DESK_COOKIE]);
T::same(APP_BASE_PATH . '/dashboard.php#cal', homeCalendarUrl(), 'گوشی: آدرسِ ماهِ جاری = «گزارش»، بی‌پارامتر');
T::same(APP_BASE_PATH . '/dashboard.php#cal', homeCalendarUrl($ty, $tm), 'ماهِ جاری حتی با پارامتر هم بی‌پارامتر');
T::same(APP_BASE_PATH . '/dashboard.php?jy=1403&jm=12#cal', homeCalendarUrl(1403, 12), 'ماهِ دیگر با jy/jm');
$_COOKIE[DESK_COOKIE] = '1';
T::same(APP_BASE_PATH . '/index.php?jy=1403&jm=12#cal', homeCalendarUrl(1403, 12), 'دسکتاپ: همان ماه روی خانه');
unset($_COOKIE[DESK_COOKIE]);

T::same('۸۵۰ هزار', hcalShort(850000), 'مبلغِ کوتاه: هزار');
T::same('۱٫۵ میلیون', hcalShort(1500000), 'یک رقمِ اعشار زیرِ ده');
T::same('۲۵ میلیون', hcalShort(25000000), 'بی‌اعشار از ده به بالا');
T::same('۱۳ میلیون', hcalShort(12500000), 'از ده به بالا گرد می‌شود (نه «۱۲٫۵»؛ خانه‌ی روز جا ندارد)');
T::same('۲ میلیارد', hcalShort(2000000000), 'میلیارد، بی «٫۰»');
T::same('۹۰۰', hcalShort(900), 'زیرِ هزار همان عدد');

// ---------------------------------------------------------------
T::group('تقویمِ خانه — شبکه‌ی روزها');
// ۱ فروردین ۱۴۰۵ = ۲۱ مارس ۲۰۲۶، شنبه → بی‌خانه‌ی خالیِ اول
$far = homeCalendarHtml(1405, 1, null);
$c = $cells($far);
T::same(31, count($c), 'فروردین ۳۱ دکمه‌ی روز دارد');
T::same('2026-03-21', $c[0][1], 'روزِ اول = ۲۱ مارس');
$beforeFirst = explode('<button', explode('<div class="hcal-grid">', $far)[1] ?? '')[0];
T::ok(strpos($beforeFirst, 'is-adj') === false, 'شنبه است، پس پیش از روزِ اول هیچ روزِ ماهِ قبل نیست');
$esf = homeCalendarHtml(1404, 12, null);   // ۱ اسفند ۱۴۰۴ = ۲۰ فوریه ۲۰۲۶، جمعه → شش خانه‌ی ماهِ قبل
$beforeFirst = explode('<button', explode('<div class="hcal-grid">', $esf)[1] ?? '')[0];
T::same(6, substr_count($beforeFirst, 'is-adj'), 'اسفند ۱۴۰۴ از جمعه شروع می‌شود: شش روزِ بهمن پیش از آن');
T::ok(str_contains($beforeFirst, '>۲۵<') && str_contains($beforeFirst, '>۳۰<'), 'و همان ۲۵ تا ۳۰ِ بهمن‌اند');
T::same(30, count($cells(homeCalendarHtml(1403, 12, null))), 'اسفندِ ۱۴۰۳ (کبیسه) ۳۰ روز');
T::same(29, count($cells(homeCalendarHtml(1404, 12, null))), 'اسفندِ ۱۴۰۴ ۲۹ روز');

// جمعه‌ها: هر دکمه‌ی is-fri واقعاً جمعه است و هر جمعه is-fri دارد
$friBad = [];
foreach ([[1405, 1], [1404, 12], [$ty, $tm]] as [$y, $mo]) {
    foreach ($cells(homeCalendarHtml($y, $mo, null)) as [$cls, $d]) {
        $isFri = (int)date('w', strtotime($d)) === 5;
        if ($isFri !== $has($cls, 'is-fri')) { $friBad[] = "{$y}/{$mo} {$d}"; }
    }
}
T::same([], $friBad, 'is-fri دقیقاً روی جمعه‌هاست');

// هر ماه ضربِ ۷ خانه دارد (روزهای ماهِ قبل/بعد ردیف را پر می‌کنند)
$mar = homeCalendarHtml(1404, 12, null);
$adj = preg_match_all('~class="hcal-d is-adj~', $mar);
T::same(0, (count($cells($mar)) + $adj) % 7, 'خانه‌ها ضربِ ۷ (ردیفِ آخر هم کامل)');

// امروز و هفته‌اش
$now = homeCalendarHtml($ty, $tm, null);
$todayCells = array_values(array_filter($cells($now), fn($x) => $has($x[0], 'is-today')));
T::ok(count($todayCells) === 1 && $todayCells[0][1] === today(), 'ماهِ جاری: دقیقاً یک «امروز» و همان today()');
T::same(7, preg_match_all('~class="hcal-d[^"]*\bis-wk\b~', $now), 'نوارِ هفته دقیقاً هفت خانه');
$wkDates = array_column(array_filter($cells($now), fn($x) => $has($x[0], 'is-wk')), 1);
T::ok(in_array(today(), $wkDates, true), 'و امروز در همان هفته است');
T::ok(strpos($now, 'data-now="1"') !== false && strpos($now, 'hcal-now') === false, 'ماهِ جاری: دکمه‌ی «امروز» ندارد');
T::ok(strpos($mar, 'data-now="0"') !== false && strpos($mar, 'class="hcal-now') !== false, 'ماهِ دیگر: دکمه‌ی «امروز» دارد');
T::same(0, count(array_filter($cells($mar), fn($x) => $has($x[0], 'is-today'))), 'ماهِ دیگر هیچ «امروز»ی ندارد');
T::ok(strpos($now, 'data-pending="1"') !== false && strpos($now, 'hcal-dot is-') === false, 'پوسته: بی‌نقطه و منتظرِ داده');
T::ok(strpos($far, 'data-jy="1404" data-jm="12"') !== false && strpos($far, 'data-jy="1405" data-jm="2"') !== false,
    'ماهِ قبل/بعدِ فروردین = اسفندِ سالِ قبل و اردیبهشت');

// ---------------------------------------------------------------
T::group('تقویمِ خانه — داده');
require_once $root . '/includes/user_data.php';
try { $pdo = Database::getConnection(); }
catch (Throwable $e) { T::blocked('تقویمِ خانه', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }

$USER  = ['home_cal_u', 'HomeCal#9'];
$OTHER = ['home_cal_o', 'HomeCal#9'];
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
$cleanup = function () use (&$serverPid, $purge, $USER, $OTHER, &$jars) {
    if ($serverPid) { @exec("kill $serverPid 2>/dev/null"); $serverPid = 0; }
    try { $purge($USER[0]); $purge($OTHER[0]); } catch (Throwable $e) {}
    foreach ($jars as $j) { @unlink($j); }
};

try {
    $mk = function (array $u) use ($pdo, $purge): int {
        $purge($u[0]);
        $pdo->prepare("INSERT INTO users (full_name, username, password_hash, role, is_active)
                       VALUES ('کاربر تقویم', :u, :p, 'user', 1)")
            ->execute(['u' => $u[0], 'p' => password_hash($u[1], PASSWORD_DEFAULT)]);
        $id = (int)$pdo->lastInsertId();
        try { $pdo->prepare('UPDATE users SET balance_setup_at = NOW() WHERE id = :u')->execute(['u' => $id]); } catch (Throwable $e) {}
        return $id;
    };
    $uid = $mk($USER);
    $oid = $mk($OTHER);

    $g = fn(int $y, int $m, int $d): string => vsprintf('%04d-%02d-%02d', jalaliToGregorian($y, $m, $d));
    $dIn = $g(1404, 12, 5); $dOut = $g(1404, 12, 9); $dDebt = $g(1404, 12, 20);
    $tx = $pdo->prepare('INSERT INTO transactions (user_id, type, amount, title, transaction_date) VALUES (:u, :t, :a, :ti, :d)');
    $tx->execute(['u' => $uid, 't' => 'income',  'a' => 1200000, 'ti' => 'حقوق', 'd' => $dIn]);
    $tx->execute(['u' => $uid, 't' => 'income',  'a' => 300000,  'ti' => 'هدیه', 'd' => $dIn]);
    $tx->execute(['u' => $uid, 't' => 'expense', 'a' => 450000,  'ti' => 'خرید', 'd' => $dOut]);
    // ⛔ کاربرِ دیگر، همان روز — نه نقطه، نه جمع
    $tx->execute(['u' => $oid, 't' => 'expense', 'a' => 99000000, 'ti' => 'دیگری', 'd' => $dIn]);
    $tx->execute(['u' => $oid, 't' => 'income',  'a' => 77000000, 'ti' => 'دیگری', 'd' => $g(1404, 12, 12)]);
    // بدهیِ گذشته‌ی تسویه‌نشده → سررسیدِ گذشته
    $pdo->prepare("INSERT INTO debts (user_id, direction, counterparty_name, amount, entry_date, due_date)
                   VALUES (:u, 'payable', 'تقویم‌آزمون', 500000, :e, :d)")
        ->execute(['u' => $uid, 'e' => $g(1404, 12, 1), 'd' => $dDebt]);

    $data = homeCalendarData($uid, 1404, 12);
    T::same(1500000, $data['in'], 'جمعِ دریافتیِ ماه (دو تراکنشِ یک روز)');
    T::same(450000, $data['out'], 'جمعِ پرداختیِ ماه');
    T::same(1, $data['dues'], 'یک سررسید در ماه');
    T::same(1, $data['late'], 'و گذشته است');
    T::ok(!isset($data['days'][$g(1404, 12, 12)]), '⛔ روزی که فقط کاربرِ دیگر دارد نقطه نمی‌گیرد');

    $html = homeCalendarHtml(1404, 12, $data);
    $byDate = [];
    foreach ($cells($html) as [$cls, $d, $inner]) { $byDate[$d] = [$cls, $inner]; }
    T::ok(str_contains($byDate[$dIn][1], 'hcal-dot is-in') && !str_contains($byDate[$dIn][1], 'is-out'),
        'روزِ دریافت فقط نقطه‌ی سبز (پرداختِ کاربرِ دیگر همان روز دیده نمی‌شود)');
    T::ok(str_contains($byDate[$dOut][1], 'hcal-dot is-out') && !str_contains($byDate[$dOut][1], 'is-in'), 'روزِ پرداخت فقط نقطه‌ی قرمز');
    T::ok(str_contains($byDate[$dDebt][1], 'hcal-dot is-late') && str_contains($byDate[$dDebt][1], 'hcal-dot is-out'),
        'روزِ بدهیِ گذشته: پرداخت + سررسیدِ گذشته');
    T::ok($has($byDate[$dIn][0], 'has-any') && !$has($byDate[$g(1404, 12, 2)][0], 'has-any'), 'فقط روزِ دارای چیزی زمینه می‌گیرد');
    T::ok(str_contains($html, formatMoney(1500000)) && str_contains($html, formatMoney(450000)), 'جمع‌ها روی کارت');
    T::ok(str_contains($html, 'سررسیدِ گذشته') && str_contains($html, 'due.php?t=list&amp;f=overdue'), 'چیپِ سررسیدِ گذشته به همان صافی می‌رود');
    T::ok(str_contains($byDate[$dIn][1], '<b class="is-in" title="' . formatMoney(1500000) . '">۱٫۵ میلیون</b>'),
        'مبلغِ روز (برای کارتِ پهن): جمعِ دو دریافتِ همان روز، با عددِ دقیق در title');
    T::ok(str_contains($byDate[$dOut][1], '>۴۵۰ هزار</b>') && !str_contains($byDate[$dDebt][1], 'hcal-amt'),
        'پرداختِ روز؛ و روزی که فقط سررسید دارد مبلغِ «ثبت‌شده» نمی‌گیرد');
    T::ok(!str_contains($html, 'data-pending'), 'با داده دیگر «منتظر» نیست');

    // سررسیدِ آینده (نه گذشته) → نقطه‌ی طلایی، نه نارنجی
    [$ny, $nm] = homeCalendarMonth($ty, $tm + 1);
    $dFut = $g($ny, $nm, 10);
    $pdo->prepare("INSERT INTO debts (user_id, direction, counterparty_name, amount, entry_date, due_date)
                   VALUES (:u, 'receivable', 'آینده‌آزمون', 200000, :e, :d)")
        ->execute(['u' => $uid, 'e' => today(), 'd' => $dFut]);
    $fut = homeCalendarHtml($ny, $nm, homeCalendarData($uid, $ny, $nm));
    $fd = array_values(array_filter($cells($fut), fn($x) => $x[1] === $dFut));
    T::ok($fd && str_contains($fd[0][2], 'is-due') && str_contains($fd[0][2], 'is-in') && !str_contains($fd[0][2], 'is-late'),
        'طلبِ آینده: دریافت + سررسید (طلایی)، نه «گذشته»');
    T::ok(str_contains($fut, '۱ سررسید') && !str_contains($fut, 'سررسیدِ گذشته'), 'چیپِ «۱ سررسید»');

    // ---------------------------------------------------------------
    T::group('تقویمِ خانه — HTTP');
    $port = 0;
    for ($p = 8971; $p <= 8989; $p++) {
        $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
        if ($sock) { fclose($sock); $port = $p; break; }
    }
    if (!$port) { T::skip('تقویمِ خانه', 'پورت آزاد پیدا نشد'); $cleanup(); exit(T::report()); }
    $log = tempnam(sys_get_temp_dir(), 'hcsrv');
    $serverPid = (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
        $port, escapeshellarg($root), escapeshellarg($log))));
    $up = false;
    for ($i = 0; $i < 40; $i++) {
        usleep(150000);
        $s = @fsockopen('127.0.0.1', $port, $a, $b, 0.3);
        if ($s) { fclose($s); $up = true; break; }
    }
    if (!$up) { T::blocked('تقویمِ خانه', 'سرور آزمایشی بالا نیامد'); $cleanup(); exit(T::report()); }

    $jar = tempnam(sys_get_temp_dir(), 'hcjar'); $jars[] = $jar;
    $req = function (string $path, ?array $post = null, string $cookie = '') use ($port, $jar): array {
        $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 25, CURLOPT_HEADER => true]);
        if ($cookie !== '') { curl_setopt($ch, CURLOPT_COOKIE, $cookie); }
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $raw  = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hs   = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        unset($ch);
        $loc = preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $hs), $lm) ? $lm[1] : '';
        return [$code, substr($raw, $hs), $loc];
    };
    $pdo->exec("DELETE FROM login_attempts WHERE request_ip = '127.0.0.1'");
    [, $html] = $req('login.php');
    preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m);
    $req('login.php', ['csrf_token' => $m[1] ?? '', 'username' => $USER[0], 'password' => $USER[1]]);

    // خانه‌ی گوشی — بی‌تقویم («صفحه خانه در گوشی خیلی شلوغ میشه»)
    [$code, $home] = $req('index.php');
    T::same(200, $code, 'خانه باز می‌شود');
    T::ok(!str_contains($home, 'id="cal"') && !str_contains($home, 'class="card hcal'), '⛔ خانه‌ی گوشی تقویم ندارد');
    T::ok(str_contains($home, 'class="home-date" href="' . APP_BASE_PATH . '/dashboard.php#cal"'), 'تاریخِ بالای خانه به تقویمِ «گزارش» می‌رود');

    // «گزارش» — همان بالا
    [$code, $rep] = $req('dashboard.php');
    T::same(200, $code, '«گزارش» باز می‌شود');
    T::same(1, substr_count($rep, 'id="cal"'), 'دقیقاً یک تقویم');
    T::ok(str_contains($rep, '<section class="card hcal" id="cal"'), 'پیش‌فرض ماهِ کامل (نه نوارِ هفته)');
    $bodyStart = strpos($rep, '<main') !== false ? strpos($rep, '<main') : strpos($rep, 'page-content');
    $firstCard = preg_match('~class="card[ "]~', substr($rep, (int)$bodyStart), $fm, PREG_OFFSET_CAPTURE) ? $fm[0][1] : -1;
    T::ok($firstCard >= 0 && strpos(substr($rep, (int)$bodyStart), 'class="card hcal"') === $firstCard, '⛔ «همون بالا»: اولین کارتِ صفحه');
    T::ok(str_contains($rep, 'data-pending="1"') && !str_contains($rep, 'hcal-dot is-'), '⛔ صفحه داده‌ی تقویم را خودش نمی‌خواند (پوسته، بی‌کوئری)');
    T::ok(str_contains($rep, "localStorage.getItem('daftar_hcal_month') === '0'"), 'جمع کردن به هفته با انتخابِ کاربر، پیش از نقاشی');

    // ماهِ دیگر از آدرس
    [, $homeM] = $req('dashboard.php?jy=1404&jm=12');
    T::ok(str_contains($homeM, 'اسفند ۱۴۰۴') && str_contains($homeM, 'data-now="0"'), 'پوسته‌ی ماهِ دیگر از ?jy=&jm=');

    // اندپوینت
    [$code, $frag] = $req('api/home_calendar.php?jy=1404&jm=12');
    T::same(200, $code, 'اندپوینت ۲۰۰');
    T::ok(str_starts_with(trim($frag), '<div class="hcal-inner"') && !str_contains($frag, '<html'), 'فقط درونِ کارت، نه صفحه');
    T::ok(str_contains($frag, formatMoney(1500000)) && str_contains($frag, 'hcal-dot is-late'), 'داده‌ی همین کاربر');
    T::ok(!str_contains($frag, formatMoney(99000000)) && !str_contains($frag, formatMoney(77000000)), '⛔ نه داده‌ی کاربرِ دیگر');
    [, $fragNow] = $req('api/home_calendar.php');
    T::ok(str_contains($fragNow, 'data-now="1"'), 'بی‌پارامتر = ماهِ جاری');
    [, $fragBad] = $req('api/home_calendar.php?jy=abc&jm=99');
    T::ok(str_contains($fragBad, 'data-now="1"') || str_contains($fragBad, 'hcal-inner'), 'ورودیِ خراب = ماهِ معتبر، نه خطا');

    // آدرس‌های قدیمی
    [$code, , $loc] = $req('calendar.php?jy=1404&jm=12');
    T::ok($code === 301 && str_ends_with($loc, '/dashboard.php?jy=1404&jm=12#cal'), 'calendar.php (گوشی) → تقویمِ «گزارش» با همان ماه', "{$code} {$loc}");
    [$code, , $loc] = $req('calendar.php?jy=1404&jm=12', null, DESK_COOKIE . '=1');
    T::ok($code === 301 && str_ends_with($loc, '/index.php?jy=1404&jm=12#cal'), 'calendar.php (دسکتاپ) → تقویمِ خانه', "{$code} {$loc}");
    [$code, , $loc] = $req('due.php?t=calendar');
    T::ok($code === 301 && str_ends_with($loc, '/dashboard.php#cal'), '⛔ due.php?t=calendar → تقویم (نه بی‌صدا «سررسیدها»)', "{$code} {$loc}");
    [$code, , $loc] = $req('due.php?t=calendar&jy=1404&jm=12');
    T::ok($code === 301 && str_ends_with($loc, '/dashboard.php?jy=1404&jm=12#cal'), 'و ماه را نگه می‌دارد', "{$code} {$loc}");
    [$code, $due] = $req('due.php');
    T::ok($code === 200 && !str_contains($due, 't=calendar') && !str_contains($due, '>تقویم<'), '⛔ «سررسیدها» دیگر زبانه‌ی تقویم ندارد');
    T::same(2, preg_match_all('~<a href="\?t=[a-z]+" class="page-tab ~', $due), 'دو زبانه مانده: سررسیدها و یادآورهای من');

    // دسکتاپ — زیرِ «آخرین تراکنش‌ها»، و ستونِ کناری مثلِ قبل
    [, $desk] = $req('index.php', null, DESK_COOKIE . '=1');
    $side = preg_match('/<aside class="home-side desk-only">(.*?)<\/aside>/s', $desk, $sm) ? $sm[1] : '';
    $main = preg_match('/<div class="home-main">(.*?)<aside class="home-side/s', $desk, $mm2) ? $mm2[1] : '';
    T::same(1, substr_count($desk, 'id="cal"'), 'دسکتاپ: دقیقاً یک تقویم');
    T::ok(str_contains($main, '<section class="card hcal is-desk" id="cal"'), 'دسکتاپ: در ستونِ اصلی، ماهِ کامل');
    T::ok(strpos($main, 'آخرین تراکنش‌های من') !== false && strpos($main, 'id="cal"') > strpos($main, 'آخرین تراکنش‌های من'),
        '⛔ و زیرِ «آخرین تراکنش‌ها»');
    T::ok(preg_match('~id="cal".*?</section>\s*</div>\s*<aside class="home-side~s', $desk) === 1,
        '⛔ آخرین چیزِ ستونِ اصلی است (نه بیرونِ آن، کنارِ ستونِ کناری)');
    T::ok($side !== '' && !str_contains($side, 'hcal'), '⛔ ستونِ کناری تقویم ندارد');
    T::ok(preg_match('~^\s*<div class="card desk-card">\s*<div class="card-header-row">\s*<h2 class="card-title">مانده‌ی حساب‌ها</h2>~', $side) === 1,
        '⛔ ستونِ کناری دوباره با «مانده‌ی حساب‌ها» شروع می‌شود');
    T::ok(!str_contains($desk, "daftar_hcal_month') === '0'"), 'روی خانه‌ی دسکتاپ نوارِ هفته‌ای در کار نیست');
    T::ok(str_contains($desk, 'class="home-date" href="' . APP_BASE_PATH . '/index.php#cal"'), 'دسکتاپ: تاریخِ بالا به تقویمِ همین صفحه');
} finally {
    $cleanup();
}

exit(T::report());
