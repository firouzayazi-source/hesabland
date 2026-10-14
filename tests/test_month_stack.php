<?php
/**
 * ⛔ کارتِ «مانده این ماه» در خانه: کشیدن به بالا = ماهِ قبل (تا یازده ماه)، رها کردن =
 *    برگشت به ماهِ جاری.
 *
 * **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵):** «اگه به سمت بالا بکشیم خود کارت رو دقیقا مبالغ
 * ماه قبلش و همونجور به سمت بالاتر بکشیم ماه قبلش … وقتی که کاربر رهاش میکنه برگرد
 * رو ماه فعلی.»
 *
 * سنجیده می‌شود:
 *   ۱. `monthHistoryBuckets()` (خالص): مرزِ ماهِ شمسی، مرزِ سال، ماهِ خالیِ میانه
 *      می‌ماند و ماه‌های خالیِ پیش از اولین فعالیت حذف می‌شوند.
 *   ۲. `monthHistory()`: ماهِ جاری نمی‌آید، روزِ آخرِ ماه در همان ماه، دادهٔ کاربرِ
 *      دیگر نمی‌آید.
 *   ۳. `api/month_history.php`: بی‌ورود ۴۰۱، با ورود همان عددها و لینکِ تراکنش‌ها.
 *   ۴. در کرومیومِ واقعی با لمس: بالا/پایین، صفحه اسکرول نمی‌شود، رها کردن برمی‌گرداند.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
$root = realpath(__DIR__ . '/..');

if (!file_exists($root . '/config/config.php')) {
    T::blocked('کارتِ ماه‌های قبل', 'config/config.php وجود ندارد');
    exit(T::report());
}
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/user_data.php';

// ───────────────────────────── ۱. خالص
T::group('۱ — دسته‌بندیِ ماه‌ها (خالص)');
$g = fn(int $y, int $m, int $d) => vsprintf('%04d-%02d-%02d', jalaliToGregorian($y, $m, $d));
// «امروز» = اردیبهشتِ ۱۴۰۵؛ پس ماه‌های قبل: فروردین ۱۴۰۵، اسفند ۱۴۰۴، بهمن، دی، …
$rows = [
    ['d' => $g(1405, 1, 1),  'income' => 100, 'expense' => 0],
    ['d' => $g(1405, 1, 31), 'income' => 0,   'expense' => 40],   // روزِ آخرِ فروردین
    ['d' => $g(1404, 12, jalaliMonthLength(1404, 12)), 'income' => 0, 'expense' => 7], // روزِ آخرِ اسفند
    // بهمن ۱۴۰۴ خالی (میانه — باید بماند)
    ['d' => $g(1404, 10, 15), 'income' => 5, 'expense' => 0],
    ['d' => $g(1405, 2, 1),  'income' => 999, 'expense' => 999],  // ماهِ جاری — نباید بیاید
];
$b = monthHistoryBuckets($rows, 1405, 2, 11);
T::same(4, count($b), 'چهار ماه: فروردین، اسفند، بهمنِ خالی، دی — قدیمی‌ترِ خالی‌ها حذف شدند');
T::same(['فروردین ۱۴۰۵', 'اسفند ۱۴۰۴', 'بهمن ۱۴۰۴', 'دی ۱۴۰۴'], array_column($b, 'label'), 'ترتیب: تازه‌ترین اول، از سال رد می‌شود');
T::same([100, 40, 60], [$b[0]['income'], $b[0]['expense'], $b[0]['net']], '⛔ روزِ اول و روزِ آخرِ فروردین هر دو در فروردین');
T::same([0, 7, -7], [$b[1]['income'], $b[1]['expense'], $b[1]['net']], '⛔ روزِ آخرِ اسفند در اسفند (مانده‌ی منفی)');
T::same([0, 0], [$b[2]['income'], $b[2]['expense']], 'بهمنِ خالیِ میانه می‌ماند (صفر هم یک حقیقت است)');
T::same([$g(1404, 12, 1), $g(1404, 12, jalaliMonthLength(1404, 12))], [$b[1]['from'], $b[1]['to']], 'بازه‌ی اسفند: روزِ اول تا روزِ آخرِ همان ماه');
T::same([], monthHistoryBuckets([], 1405, 2, 11), 'بی‌هیچ داده‌ای، هیچ ماهی');
T::same(11, count(monthHistoryBuckets([['d' => $g(1404, 3, 2), 'income' => 1, 'expense' => 0]], 1405, 2, 11)), 'سقفِ یازده ماهِ قبل (با ماهِ جاری دوازده)');

// ───────────────────────────── ۲. دیتابیس
try { $pdo = Database::getConnection(); }
catch (Throwable $e) { T::blocked('کارتِ ماه‌های قبل', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }

$USERS = ['mstack_a' => 'Mstack#a91', 'mstack_b' => 'Mstack#b91'];
$purge = function (string $username) use ($pdo) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $username]);
    $id = (int)$st->fetchColumn();
    if (!$id) { return; }
    try { deleteUserAccount($id); } catch (Throwable $e) {}
    try { $pdo->prepare('DELETE FROM users WHERE id = :i')->execute(['i' => $id]); } catch (Throwable $e) {}
};
$serverPid = 0;
$jar = tempnam(sys_get_temp_dir(), 'msjar');
$cleanup = function () use (&$serverPid, $purge, $USERS, $jar) {
    if ($serverPid) { @exec("kill $serverPid 2>/dev/null"); $serverPid = 0; }
    foreach (array_keys($USERS) as $u) { try { $purge($u); } catch (Throwable $e) {} }
    @unlink($jar);
};

try {
    $ids = [];
    foreach ($USERS as $u => $p) {
        $purge($u);
        $pdo->prepare("INSERT INTO users (full_name, username, password_hash, role, is_active)
                       VALUES ('آزمونِ ماه', :u, :p, 'user', 1)")
            ->execute(['u' => $u, 'p' => password_hash($p, PASSWORD_DEFAULT)]);
        $ids[$u] = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO wallets (user_id,name,kind,initial_balance,is_active,sort_order) VALUES (:u,'کیف پول','cash',0,1,0)")
            ->execute(['u' => $ids[$u]]);
    }
    $wid = fn(int $uid) => (int)$pdo->query("SELECT id FROM wallets WHERE user_id = {$uid} LIMIT 1")->fetchColumn();
    $add = function (int $uid, string $type, int $amount, string $date) use ($pdo, $wid) {
        $pdo->prepare("INSERT INTO transactions (user_id,type,amount,title,transaction_date,wallet_id)
                       VALUES (:u,:t,:a,'آزمونِ ماه',:d,:w)")
            ->execute(['u' => $uid, 't' => $type, 'a' => $amount, 'd' => $date, 'w' => $wid($uid)]);
    };
    [$jy, $jm] = gregorianToJalali((int)date('Y'), (int)date('m'), (int)date('d'));
    $ago = function (int $k) use ($jy, $jm): array { $y = $jy; $m = $jm - $k; while ($m < 1) { $m += 12; $y--; } return [$y, $m]; };
    [$y1, $m1] = $ago(1); [$y2, $m2] = $ago(2);
    $A = $ids['mstack_a'];
    $add($A, 'income', 500000, $g($y1, $m1, 3));
    $add($A, 'expense', 200000, $g($y1, $m1, jalaliMonthLength($y1, $m1)));
    $add($A, 'expense', 70000, $g($y2, $m2, 10));
    $add($A, 'expense', 1234, date('Y-m-d'));                       // ماهِ جاری
    $add($ids['mstack_b'], 'income', 9999999, $g($y1, $m1, 3));      // کاربرِ دیگر

    T::group('۲ — monthHistory() روی دیتابیس');
    $h = monthHistory($A);
    T::same(2, count($h), 'دو ماهِ قبل (قدیمی‌ترِ خالی حذف)');
    T::same([500000, 200000, 300000], [$h[0]['income'], $h[0]['expense'], $h[0]['net']], '⛔ ماهِ قبل: روزِ آخرِ ماه هم شمرده شد، ماهِ جاری و کاربرِ دیگر نه');
    T::same([0, 70000, -70000], [$h[1]['income'], $h[1]['expense'], $h[1]['net']], 'دو ماه قبل: منفی');

    // ───────────────────────────── ۳. اندپوینت و مرورگر
    $port = 0;
    for ($p = 8991; $p <= 9020; $p++) {
        $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
        if ($sock) { fclose($sock); $port = $p; break; }
    }
    if (!$port) { T::skip('اندپوینت و مرورگر', 'پورت آزاد پیدا نشد'); $cleanup(); exit(T::report()); }
    $log = tempnam(sys_get_temp_dir(), 'mssrv');
    $serverPid = (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!', $port, escapeshellarg($root), escapeshellarg($log))));
    $up = false;
    for ($i = 0; $i < 40; $i++) { usleep(150000); $s = @fsockopen('127.0.0.1', $port, $a, $b2, 0.3); if ($s) { fclose($s); $up = true; break; } }
    if (!$up) { T::blocked('اندپوینت و مرورگر', 'سرور آزمایشی بالا نیامد'); $cleanup(); exit(T::report()); }

    $get = function (string $path, ?array $post = null, bool $withJar = true) use ($port, $jar): array {
        $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
        $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 25];
        if ($withJar) { $opt[CURLOPT_COOKIEJAR] = $jar; $opt[CURLOPT_COOKIEFILE] = $jar; }
        curl_setopt_array($ch, $opt);
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        return [$code, $body];
    };

    T::group('۳ — api/month_history.php');
    [$c] = $get('api/month_history.php', null, false);
    T::same(401, $c, '⛔ بی‌ورود: ۴۰۱');
    [, $html] = $get('login.php');
    preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m);
    [$c] = $get('login.php', ['csrf_token' => $m[1] ?? '', 'username' => 'mstack_a', 'password' => $USERS['mstack_a']]);
    T::ok($c === 302 || $c === 303, 'ورودِ کاربرِ آزمایشی');
    [$c, $body] = $get('api/month_history.php');
    $j = json_decode($body, true);
    T::same(200, $c, 'با ورود: ۲۰۰');
    T::same(2, count($j['months'] ?? []), 'دو ماه');
    $m0 = $j['months'][0] ?? [];
    T::same([formatMoney(500000), formatMoney(200000), formatMoney(300000), false], [$m0['income'] ?? '', $m0['expense'] ?? '', $m0['net'] ?? '', $m0['neg'] ?? null], 'عددهای ماهِ قبل، با ارقامِ فارسی');
    T::same('−' . formatMoney(70000), $j['months'][1]['net'] ?? '', 'مانده‌ی منفی با «−»');
    T::ok(str_contains((string)($m0['url'] ?? ''), 'transactions.php?period=custom&from_date=' . $g($y1, $m1, 1) . '&to_date=' . $g($y1, $m1, jalaliMonthLength($y1, $m1))),
        'لینکِ «تراکنش‌های این ماه» دقیقاً همان بازه', (string)($m0['url'] ?? ''));
    [, $home] = $get('index.php');
    T::ok(str_contains($home, 'id="monthStack"') && str_contains($home, 'api/month_history.php'), 'خانه کارتِ ماه‌ها را دارد');

    // ───────────────────────────── ۴. مرورگر
    $node = trim((string)@shell_exec('command -v node 2>/dev/null'));
    $sess = '';
    foreach (explode("\n", (string)@file_get_contents($jar)) as $line) {
        $parts = preg_split('/\t/', trim($line));
        if (count($parts) >= 7 && $parts[5] === 'DAFTAR_SESSION') { $sess = $parts[6]; }
    }
    if ($node === '' || $sess === '') {
        T::skip('کشیدن در مرورگر', $node === '' ? 'node نصب نیست' : 'کوکیِ نشست پیدا نشد');
    } else {
        $cmd = escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/month_stack_probe.js') . ' '
             . escapeshellarg("http://127.0.0.1:{$port}/") . ' DAFTAR_SESSION ' . escapeshellarg($sess) . ' 2>/dev/null';
        $out = json_decode(trim((string)shell_exec($cmd)), true);
        if (!is_array($out)) {
            T::ok(false, 'probe اجرا شد', 'خروجی خوانده نشد');
        } elseif (empty($out['ok'])) {
            $why = (string)($out['why'] ?? '?');
            if ($why === 'no_chromium' || $why === 'chromium_no_start') { T::skip('کشیدن در مرورگر', 'کرومیوم در دسترس نیست'); }
            else { T::ok(false, 'probe اجرا شد', $why . ' ' . substr((string)($out['detail'] ?? ''), 0, 200)); }
        } else {
            T::group('۴ — کشیدن با لمس در کرومیوم');
            $l1 = $h[0]['label']; $l2 = $h[1]['label'];
            T::same('pan-x', $out['touchAction'] ?? '', '⛔ کارت فقط کشیدنِ افقی را به مرورگر می‌دهد');
            T::same(true, $out['startCurrent'] ?? null, 'اول ماهِ جاری');
            T::same([true, $l1, 'ماهِ قبل', formatMoney(300000), formatMoney(500000), formatMoney(200000)],
                [$out['up1']['past'] ?? null, $out['up1']['month'] ?? '', $out['up1']['ago'] ?? '', $out['up1']['net'] ?? '', $out['up1']['inc'] ?? '', $out['up1']['out'] ?? ''],
                '⛔ بالا کشیدن: ماهِ قبل با همان عددها');
            T::same(true, $out['pageDidNotScroll'] ?? null, '⛔ کشیدنِ عمودی روی کارت صفحه را اسکرول نکرد');
            T::same([3, 1], [$out['up1']['dots'] ?? 0, $out['up1']['on'] ?? -1], 'نشانگر: سه نقطه (جاری + دو ماه)، دومی روشن');
            T::same([$l2, '−' . formatMoney(70000)], [$out['up2']['month'] ?? '', $out['up2']['net'] ?? ''], 'بالاتر: دو ماه قبل');
            T::same($l1, $out['down1']['month'] ?? '', 'پایین کشیدن: یک ماه تازه‌تر');
            T::same(true, $out['stillPastAt4s'] ?? null, 'تا چند ثانیه همان ماه می‌ماند (فرصتِ خواندن)');
            T::same(false, $out['afterIdle']['past'] ?? null, '⛔ رها کردن: خودش به ماهِ جاری برگشت');
            T::same(true, $out['tapNoChange'] ?? null, 'لمسِ کوتاه ماه را عوض نمی‌کند');
        }
    }
} catch (Throwable $e) {
    T::ok(false, 'اجرای تستِ ماه‌های قبل', $e->getMessage());
}
$cleanup();
exit(T::report());
