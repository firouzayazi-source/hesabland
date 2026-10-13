<?php
/**
 * خانه‌ی دسکتاپ — داشبوردِ کلی (مانده‌ی حساب‌ها، خالص دارایی، پول قابل خرج،
 * سررسیدهای نزدیک).
 *
 * سه چیز سنجیده می‌شود و هر سه لازم‌اند:
 *   ۱. **گوشی دست نمی‌خورد:** بدونِ کوکیِ `DESK_COOKIE` هیچ بلوکِ دسکتاپی
 *      در HTML نیست و هیچ کوئریِ دسکتاپی زده نمی‌شود.
 *   ۲. **یک عدد، دو جا:** «خالص دارایی»ِ خانه همان عددِ صفحه‌ی دارایی است
 *      (هر دو از `netWorthPortfolio()`). با دو نسخه از منطق، کاربر روی خانه
 *      یک عدد و در صفحه‌ی دارایی عددِ دیگری می‌دید.
 *   ۳. **چیدمان در مرورگرِ واقعی** (`desk_probe.js`): کوکی، دو ستون، پنهان
 *      شدن زیرِ ۱۱۰۰ پیکسل، و نبودنِ اسکرولِ افقی.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

$root = realpath(__DIR__ . '/..');

T::group('خانه‌ی دسکتاپ — بی‌دیتابیس');

if (!file_exists($root . '/config/config.php')) {
    T::blocked('خانه‌ی دسکتاپ', 'config/config.php وجود ندارد');
    exit(T::report());
}
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/user_data.php';

$_COOKIE = [];
T::ok(!deskView(), 'بدونِ کوکی نمای دسکتاپ نیست');
$_COOKIE[DESK_COOKIE] = 'x';
T::ok(!deskView(), 'فقط مقدارِ «۱» پذیرفته می‌شود');
$_COOKIE[DESK_COOKIE] = '1';
T::ok(deskView(), 'با کوکیِ «۱» نمای دسکتاپ است');
$_COOKIE = [];

$js = deskCookieScript();
T::ok(str_contains($js, '(min-width: ' . DESK_MIN_PX . 'px)') && str_contains($js, DESK_COOKIE . '='),
    'اسکریپتِ کوکی همان آستانه و همان نامِ کوکی را دارد');
T::ok(!str_contains($js, '</'), 'اسکریپتِ کوکی هیچ «</» ای ندارد (درون‌خطی می‌نشیند)');

$css = (string)file_get_contents($root . '/assets/css/style.css');
T::ok((bool)preg_match('/@media \(min-width: ' . DESK_MIN_PX . 'px\) \{[^@]*\.desk-only \{ display: block; \}/s', $css),
    '⛔ CSS بلوک‌های دسکتاپ را با **همان** DESK_MIN_PX نشان می‌دهد');
T::ok((bool)preg_match('/^\.desk-only \{ display: none; \}/m', $css),
    'بیرونِ آن پرسش، بلوک‌های دسکتاپ پنهان‌اند');

// ---------------------------------------------------------------
try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::blocked('خانه‌ی دسکتاپ', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

$USER = ['__desk_home__', 'DeskHome#77'];
$purge = function (string $username) use ($pdo) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $username]);
    $id = $st->fetchColumn();
    if (!$id) { return; }
    try { deleteUserAccount((int)$id); } catch (Throwable $e) { /* ignore */ }
    $pdo->prepare('DELETE FROM users WHERE id = :i')->execute(['i' => $id]);
};
$serverPid = 0;
$cleanup = function () use (&$serverPid, $purge, $USER) {
    if ($serverPid) { @exec("kill $serverPid 2>/dev/null"); $serverPid = 0; }
    try { $purge($USER[0]); } catch (Throwable $e) { /* ignore */ }
};

try {
    T::group('آماده‌سازی');
    $purge($USER[0]);
    $pdo->prepare("INSERT INTO users (full_name, username, password_hash, role, is_active)
                   VALUES ('کاربر دسکتاپ', :u, :p, 'user', 1)")
        ->execute(['u' => $USER[0], 'p' => password_hash($USER[1], PASSWORD_DEFAULT)]);
    $uid = (int)$pdo->lastInsertId();
    $today = date('Y-m-d');
    $ins = function (string $sql, array $a) use ($pdo): int { $pdo->prepare($sql)->execute($a); return (int)$pdo->lastInsertId(); };

    $w1 = $ins("INSERT INTO wallets (user_id,name,kind,initial_balance,is_active,sort_order) VALUES (:u,'کیف پول','cash',2000000,1,0)", ['u' => $uid]);
    $ins("INSERT INTO wallets (user_id,name,kind,initial_balance,is_active,sort_order) VALUES (:u,'بانک ملت دسکتاپ','bank',5000000,1,1)", ['u' => $uid]);
    $ins("INSERT INTO wallets (user_id,name,kind,initial_balance,is_active,sort_order) VALUES (:u,'حساب بسته‌ی آزمون','bank',777000,0,2)", ['u' => $uid]);
    $cat = (int)$pdo->query("SELECT id FROM categories WHERE type='expense' AND user_id IS NULL LIMIT 1")->fetchColumn();
    $ins("INSERT INTO transactions (user_id,type,amount,title,transaction_date,category_id,wallet_id) VALUES (:u,'expense',300000,'نان',:d,:c,:w)",
        ['u' => $uid, 'd' => $today, 'c' => $cat ?: null, 'w' => $w1]);
    $at = $ins("INSERT INTO asset_types (user_id,name) VALUES (:u,'سکه دسکتاپ')", ['u' => $uid]);
    $ins("INSERT INTO assets (user_id,asset_type_id,quantity,entry_date,unit_price) VALUES (:u,:t,2,:d,1500000)", ['u' => $uid, 't' => $at, 'd' => $today]);
    $ins("INSERT INTO debts (user_id,direction,counterparty_name,amount,entry_date,due_date) VALUES (:u,'payable','رضا دسکتاپ',3000000,:d,:e)",
        ['u' => $uid, 'd' => $today, 'e' => date('Y-m-d', strtotime('+5 day'))]);
    $ins("INSERT INTO cheques (user_id,direction,counterparty_name,amount,due_date) VALUES (:u,'issued','شرکت گذشته',400000,:e)",
        ['u' => $uid, 'e' => date('Y-m-d', strtotime('-3 day'))]);
    // ⚠ طلبِ دور (بیرونِ ۱۴ روز) — نباید در فهرستِ «نزدیک» بیاید
    $ins("INSERT INTO debts (user_id,direction,counterparty_name,amount,entry_date,due_date) VALUES (:u,'receivable','دور دسکتاپ',900000,:d,:e)",
        ['u' => $uid, 'd' => $today, 'e' => date('Y-m-d', strtotime('+25 day'))]);
    T::pass('کاربرِ آزمایشی با سه حساب (یکی بسته)، دارایی، بدهی، چکِ گذشته و طلبِ دور');

    // --- مرجعِ عددها، مستقل از صفحه ---
    $nw = netWorthPortfolio($uid, false);
    // (۲٬۰۰۰٬۰۰۰ − ۳۰۰٬۰۰۰) + ۵٬۰۰۰٬۰۰۰ (حسابِ بسته بیرون) + ۳٬۰۰۰٬۰۰۰ سکه − ۴۰۰٬۰۰۰ چک − ۳٬۰۰۰٬۰۰۰ + ۹۰۰٬۰۰۰
    T::same(7200000, (int)$nw['total'], '⛔ خالص دارایی با دستِ خودِ تست حساب شده، نه با کدِ زیرِ آزمون');
    $kinds = array_column($nw['rows'], 'kind');
    T::ok(in_array('wallets', $kinds, true) && in_array('asset', $kinds, true)
        && in_array('cheques', $kinds, true) && in_array('debts', $kinds, true),
        'هر چهار جنسِ قلم در خالص دارایی هست');
    $parts = netWorthSnapParts($nw['rows']);
    T::same(6700000, $parts['wallets'], 'سطلِ «حساب‌ها»ی عکسِ روزانه فقط حساب‌های فعال');
    T::same(-400000, $parts['cheques_net'], 'سطلِ چک هم‌نامِ kind است (نه در «دارایی»)');
    T::same(-2100000, $parts['debts_net'], 'سطلِ طلب/بدهی هم‌نامِ kind است');
    T::same(3000000, $parts['assets'], 'سطلِ دارایی');

    // ---------------------------------------------------------------
    $port = 0;
    for ($p = 8931; $p <= 8960; $p++) {
        $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
        if ($sock) { fclose($sock); $port = $p; break; }
    }
    if (!$port) { T::skip('خانه‌ی دسکتاپ (HTTP)', 'پورت آزاد پیدا نشد'); $cleanup(); exit(T::report()); }
    $log = tempnam(sys_get_temp_dir(), 'deskh');
    $serverPid = (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s %s > %s 2>&1 & echo $!',
        $port, escapeshellarg($root), escapeshellarg($root . '/tests/csp_router.php'), escapeshellarg($log))));
    $up = false;
    for ($i = 0; $i < 40; $i++) { usleep(150000); $s = @fsockopen('127.0.0.1', $port, $a, $b, 0.3); if ($s) { fclose($s); $up = true; break; } }
    T::ok($up, 'سرور آزمایشی بالا آمد');

    $jar = tempnam(sys_get_temp_dir(), 'deskjar');
    $get = function (string $path, ?array $post = null, bool $desk = false) use ($port, $jar): array {
        $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
                                CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30]);
        if ($desk) { curl_setopt($ch, CURLOPT_COOKIE, DESK_COOKIE . '=1'); }
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $body];
    };
    [, $html] = $get('login.php');
    T::ok(str_contains($html, DESK_COOKIE . '='), 'صفحه‌ی ورود هم کوکیِ نمای دسکتاپ را می‌گذارد (اولین خانه بعد از ورود)');
    preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m);
    [$lc] = $get('login.php', ['csrf_token' => $m[1] ?? '', 'username' => $USER[0], 'password' => $USER[1]]);
    T::ok($lc === 302 || $lc === 303, 'ورود انجام شد', "کد {$lc}");

    $questions = fn(): int => (int)($pdo->query("SHOW GLOBAL STATUS LIKE 'Questions'")->fetch()['Value'] ?? 0);
    $count = function (bool $desk) use ($get, $questions): array {
        $best = PHP_INT_MAX; $body = '';
        for ($i = 0; $i < 3; $i++) {
            $a = $questions();
            [, $body] = $get('index.php', null, $desk);
            $n = $questions() - $a - 2;
            if ($n < $best) { $best = $n; }
        }
        return [$best, $body];
    };
    $get('index.php'); $get('index.php', null, true); // گرم کردنِ کارهای یک‌بار-در-روز

    T::group('⛔ گوشی دست نمی‌خورد');
    [$nPhone, $phone] = $count(false);
    T::ok(str_contains($phone, '</html>') && str_contains($phone, 'balance-ribbon'), 'خانه‌ی بی‌کوکی کامل رندر شد');
    foreach (['desk-kpis', 'home-side', 'home-desk', ' is-desk', 'home-desk-more', 'season-card', 'trendChart'] as $needle) {
        T::ok(!str_contains($phone, $needle), "بدونِ کوکی «{$needle}» در HTML نیست");
    }

    T::group('خانه‌ی دسکتاپ');
    [$nDesk, $deskHtml] = $count(true);
    T::ok(str_contains($deskHtml, '</html>'), 'خانه‌ی دسکتاپ کامل رندر شد (خطای کشنده‌ی وسطِ رندر نیست)');
    T::ok(str_contains($deskHtml, 'page-content is-desk'), 'ستونِ محتوا کلاسِ is-desk گرفت');
    T::same(4, preg_match_all('/class="desk-kpi(?: is-warn)?"/', $deskHtml), 'چهار کاشیِ شاخص');
    T::ok($nDesk - $nPhone <= 8,
        '⛔ هزینه‌ی دسکتاپ سقف دارد (≤ ۸ کوئریِ بیشتر از گوشی)', "گوشی {$nPhone}، دسکتاپ {$nDesk}");
    T::ok($nDesk > $nPhone, 'و دسکتاپ واقعاً داده‌ی بیشتری می‌خواند (سنجه پوچ نیست)', "گوشی {$nPhone}، دسکتاپ {$nDesk}");
    T::pass("شمارِ کوئریِ خانه: گوشی {$nPhone}، دسکتاپ {$nDesk}");

    // ⛔ «داشبورد بیاد ادامه‌ی خانه» — همان تکه‌های `dashboard.php`، از همان توابع
    $more = preg_match('/<section class="home-desk-more desk-only"[^>]*>(.*?)<\/section>/s', $deskHtml, $mm) ? $mm[1] : '';
    T::ok($more !== '', '⛔ ادامه‌ی خانه (داشبورد) روی دسکتاپ رندر شد');
    T::same(4, substr_count($more, 'class="season-card'), 'کارتِ سالانه: چهار فصل');
    T::same(12, substr_count($more, '<a class="season-month'), 'کارتِ سالانه: دوازده ماه');
    T::ok(str_contains($more, 'id="year"'), 'لنگرِ #year روی خانه هم هست (قلمِ «داشبورد» به همین می‌رود)');
    T::ok(str_contains($more, 'breakdown-cta') && str_contains($more, 'trendChart'), 'گزارشِ دسته‌بندی و نمودارِ روند');
    T::ok(str_contains($more, 'مقایسه با ماه قبل'), 'مقایسه با ماه قبل');
    T::ok(!str_contains($more, 'گزارش با بازه دلخواه') && !str_contains($more, 'یادآوری چک'),
        '⛔ یادآوری‌ها و بازه‌ی دلخواه عمداً نیامدند');
    $sb = preg_match('#<nav class="sidebar".*?</nav>#s', $deskHtml, $sbm) ? $sbm[0] : '';
    T::ok($sb !== '' && !str_contains($sb, '/dashboard.php"'),
        '⛔ قلمِ «داشبورد»ِ منوی کناری روی دسکتاپ به خانه می‌رود، نه صفحه‌ی دوم');
    T::ok($sb !== '' && !str_contains($sb, '#year'),
        '⛔ و به بالای صفحه، نه وسطش (`#year`) — «وقتی روی داشبورد میزنم بیاد بالای صفحه»');
    [, $dash] = $get('dashboard.php', null, true);
    foreach (['class="season-card', 'breakdown-cta', 'trendChart'] as $needle) {
        T::ok(substr_count($dash, $needle) === substr_count($more, $needle), "⛔ خانه و داشبورد «{$needle}» را یکسان رندر می‌کنند (یک منبع)");
    }
    [$cyr, $yrHtml] = $get('index.php?y=abc', null, true);
    T::ok($cyr === 200 && str_contains($yrHtml, 'سال ' . toPersianDigits((string)gregorianToJalali((int)date('Y'), (int)date('m'), (int)date('d'))[0])),
        '`?y=` نامعتبر روی خانه هم به امسال برمی‌گردد');
    T::ok(str_contains($yrHtml, 'index.php?y=') || !str_contains($yrHtml, 'dashboard.php?y='),
        'ناوبریِ سال روی خانه روی خانه می‌ماند');

    $f = formatMoney(7200000);
    T::ok(str_contains($deskHtml, $f), "⛔ «خالص دارایی» روی خانه همان {$f} است");
    [, $assetsHtml] = $get('my-assets.php');
    T::ok((bool)preg_match('/id="assetGrandTotal">' . preg_quote($f, '/') . '/u', $assetsHtml),
        '⛔ و صفحه‌ی دارایی هم دقیقاً همان عدد را می‌گوید');
    T::ok(str_contains($deskHtml, formatMoney(6700000)), 'مجموع حساب‌ها = فقط حساب‌های فعال');

    // ستونِ کناری
    $side = preg_match('/<aside class="home-side desk-only">(.*?)<\/aside>/s', $deskHtml, $sm) ? $sm[1] : '';
    T::ok($side !== '', 'ستونِ کناری رندر شد');
    T::ok(str_contains($side, 'بانک ملت دسکتاپ'), 'حسابِ فعال در فهرستِ مانده‌ها');
    T::ok(!str_contains($side, 'حساب بسته‌ی آزمون'), '⛔ حسابِ غیرفعال در فهرستِ مانده‌ها نیست');
    T::ok(str_contains($side, 'بدهی به رضا دسکتاپ'), 'بدهیِ ۵ روزِ آینده در سررسیدهای نزدیک');
    T::ok(str_contains($side, 'شرکت گذشته') && str_contains($side, 'is-past'), 'چکِ گذشته هم هست و «گذشته» علامت خورده');
    T::ok(!str_contains($side, 'دور دسکتاپ'), '⛔ طلبِ ۲۵ روزِ بعد (بیرونِ ۱۴ روز) در فهرستِ نزدیک نیست');
    T::ok((bool)preg_match('/class="desk-kpi is-warn"/', $deskHtml), 'کاشیِ سررسید با سررسیدِ گذشته هشدار می‌گیرد');

    // عکسِ روزانه از خانه
    $pdo->prepare('DELETE FROM net_worth_snapshots WHERE user_id = :u')->execute(['u' => $uid]);
    $get('index.php', null, true);
    $st = $pdo->prepare('SELECT wallets + assets + trades_open + cheques_net + debts_net FROM net_worth_snapshots WHERE user_id = :u AND snap_date = :d');
    $st->execute(['u' => $uid, 'd' => today()]);
    T::same(7200000, (int)$st->fetchColumn(), 'خانه‌ی دسکتاپ عکسِ روزانه‌ی خالص دارایی را با همان اجزا ثبت می‌کند');
    $pdo->prepare('DELETE FROM net_worth_snapshots WHERE user_id = :u')->execute(['u' => $uid]);
    $get('index.php');
    $st->execute(['u' => $uid, 'd' => today()]);
    T::ok($st->fetchColumn() === false, 'خانه‌ی گوشی عکس ثبت نمی‌کند (کوئریِ اضافه‌ای روی گوشی نیست)');

    // ---------------------------------------------------------------
    T::group('خانه‌ی دسکتاپ در مرورگر');
    $node = trim((string)@shell_exec('command -v node 2>/dev/null'));
    if ($node === '') {
        T::skip('خانه‌ی دسکتاپ (مرورگر)', 'node نصب نیست');
    } else {
        $raw = (string)shell_exec(sprintf('%s %s %s %s %s 2>/dev/null', escapeshellarg($node),
            escapeshellarg(__DIR__ . '/desk_probe.js'), escapeshellarg("http://127.0.0.1:{$port}/"),
            escapeshellarg($USER[0]), escapeshellarg($USER[1])));
        $r = json_decode(trim($raw), true);
        if (!is_array($r) || empty($r['ok'])) {
            if (($r['why'] ?? '') === 'no_chromium') {
                T::skip('خانه‌ی دسکتاپ (مرورگر)', 'کرومیوم نصب نیست');
            } else {
                T::ok(false, 'probe اجرا شد', substr($raw, 0, 300));
            }
        } else {
            $w = $r['wide'];
            T::ok($w['cookie'], 'در پنجره‌ی ۱۴۴۰ کوکیِ دسکتاپ نشست');
            T::ok($w['sideVisible'] && $w['kpisVisible'], '⛔ اولین خانه‌ی بعد از ورود همان داشبوردِ کلی است');
            T::same(4, $w['kpiCount'], 'چهار کاشی');
            T::same(1, $w['kpiRow'], 'هر چهار کاشی در **یک** ردیف');
            T::ok($w['side'] && $w['main'] && $w['side']['r'] <= $w['main']['l'] + 1,
                'ستونِ کناری سمتِ چپِ ستونِ اصلی (RTL)', json_encode([$w['side'], $w['main']]));
            T::ok($w['pcW'] > 900, 'ستونِ محتوا از ۷۲۰ پیکسل باز شد', "عرض {$w['pcW']}");
            T::ok(!$w['hscroll'], 'بدونِ اسکرولِ افقی در ۱۴۴۰');
            T::ok($w['moreVisible'] && $w['yearVisible'] && $w['moreBelow'], '⛔ داشبورد زیرِ خانه دیده می‌شود');
            T::ok($w['trendCanvas'] > 200, 'نمودارِ روند واقعاً کشیده شد', "ارتفاع {$w['trendCanvas']}");
            T::ok($w['moreMain'] && $w['moreSide'] && $w['moreSide']['r'] <= $w['moreMain']['l'] + 1,
                'روند و دسته‌بندی کنارِ هم (دسته‌بندی سمتِ چپ)', json_encode([$w['moreMain'], $w['moreSide']]));

            T::ok($r['midSameLoad']['sideInHtml'] && !$r['midSameLoad']['sideVisible'],
                'کوچک کردنِ پنجره: همان بارگذاری، ستونِ کناری با CSS پنهان');
            T::ok(!$r['midNext']['cookie'] && !$r['midNext']['sideVisible'], 'در ۱۰۰۰ پیکسل کوکی برداشته شد');
            T::ok(!$r['midAfter']['sideInHtml'], 'و بارگذاریِ بعد اصلاً بلوکِ دسکتاپ نمی‌سازد');
            T::ok(!$r['midAfter']['hscroll'], 'بدونِ اسکرولِ افقی در ۱۰۰۰');

            $ph = $r['phone'];
            T::ok(!$ph['cookie'] && !$ph['sideInHtml'] && $ph['ribbon'], '⛔ گوشی (۳۹۰): همان خانه‌ی همیشگی');
            T::ok(!$ph['moreInHtml'], '⛔ گوشی: داشبورد زیرِ خانه نمی‌آید');
            T::ok($ph['pcW'] <= 390 && !$ph['hscroll'], 'گوشی بدونِ اسکرولِ افقی', "عرض {$ph['pcW']}");

            T::ok($r['back']['sideVisible'] && $r['back']['kpiRow'] === 1 && !$r['back']['hscroll'],
                'برگشت به ۱۲۸۰: دوباره داشبوردِ کلی، یک ردیف، بی‌اسکرول');
        }
    }
} finally {
    $cleanup();
}

exit(T::report());
