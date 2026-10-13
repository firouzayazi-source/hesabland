<?php
/**
 * قلم‌های صفحه‌ی خانه — روشن/خاموش از پروفایل.
 *
 * **خواسته‌ی مالکِ نصب:** «تمام گزینه‌های خانه — تاریخِ روز، دریافت و
 * پرداخت، ٪ کمتر از همین روزِ ماهِ قبل، سررسیدِ گذشته، این ماه ٪ بیشتر —
 * باید امکانِ فعال‌سازی و غیرفعال‌سازی با توگل در تنظیمات داشته باشن،
 * پیش‌فرض روشن… کارت باید سایزِ متغیر نسبت به دیتاهای موجود داشته باشه.»
 *
 * سه لایه، و هر سه لازم‌اند:
 *   ۱. تابعِ خالصِ `homeHiddenParse()` (بی‌دیتابیس).
 *   ۲. `financialHighlights()` با `$skip`: جمله‌ی خاموش **ساخته نمی‌شود**
 *      و جمله‌ی بعدی جایش را در سقفِ سه‌تایی می‌گیرد — نه پنهان‌سازیِ
 *      بعدی که فقط کارت را کم‌جمله‌تر می‌کرد.
 *   ۳. HTTP با نشستِ واقعی: ذخیره از اندپوینت، خانه قلمِ خاموش را **رندر
 *      نمی‌کند**، و پروفایل حالتِ درست را نشان می‌دهد.
 *
 * ⛔ fixture طوری ساخته شده که **هر هفت** قلم در حالتِ پیش‌فرض واقعاً
 *    روی خانه بیایند. بدونِ آن، بررسیِ «خاموش که شد، رفت» روی قلمی که
 *    از اول هم رندر نمی‌شد سبز می‌ماند — همان «سنجشی که روی خرابی سبز
 *    می‌شود».
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';
$root = realpath(__DIR__ . '/..');

// ---------- ۱. تابعِ خالص ----------
T::group('خانه — فهرستِ قلم‌ها و تجزیه‌ی ذخیره‌شده');
require_once $root . '/includes/functions.php';

$keys = array_keys(HOME_WIDGETS);
T::same(['date', 'meter', 'split', 'compare', 'overdue', 'budget', 'growth'], $keys,
    'هفت قلم، به همین ترتیب (ترتیبِ کارتِ تنظیمات)');
$badGroup = [];
foreach (HOME_WIDGETS as $k => $w) {
    if (!isset(HOME_WIDGET_GROUPS[$w['group'] ?? ''])) { $badGroup[] = $k; }
    if (trim((string)($w['label'] ?? '')) === '' || trim((string)($w['hint'] ?? '')) === '') { $badGroup[] = $k . ':text'; }
}
T::same([], $badGroup, 'هر قلم گروهِ معتبر، برچسب و توضیح دارد');
$empty = array_diff(array_keys(HOME_WIDGET_GROUPS), array_column(HOME_WIDGETS, 'group'));
T::same([], array_values($empty), 'هیچ گروهِ خالی‌ای در کارتِ تنظیمات نیست');

T::same([], homeHiddenParse(null), 'NULL یعنی همه روشن');
T::same([], homeHiddenParse(''), 'رشته‌ی خالی یعنی همه روشن');
T::same(['date', 'overdue'], homeHiddenParse('overdue, date'), 'ترتیب از HOME_WIDGETS می‌آید، نه از رشته');
T::same(['split'], homeHiddenParse('bogus,split,,<x>,split'), 'کلیدِ ناشناخته و تکراری دور ریخته می‌شود');
T::ok(homeWidgetOn([], 'date') && !homeWidgetOn(['date'], 'date'), 'homeWidgetOn');

// ---------- ۲ و ۳ ----------
T::group('خانه — ذخیره، رندر، و جای خالیِ جمله‌ی خاموش');
if (!file_exists($root . '/config/config.php')) { T::blocked('قلم‌های خانه', 'config/config.php وجود ندارد'); exit(T::report()); }
require_once $root . '/includes/db.php';
require_once $root . '/includes/user_data.php';
try { $pdo = Database::getConnection(); }
catch (Throwable $e) { T::blocked('قلم‌های خانه', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }
if (!tableHasColumn('users', 'home_hidden')) {
    T::blocked('قلم‌های خانه', 'ستونِ users.home_hidden نیست — migration_home_widgets.sql اجرا نشده');
    exit(T::report());
}

$USER = ['home_w_u', 'Home#pass9'];
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
$cleanup = function () use (&$serverPid, $purge, $USER, &$jars) {
    if ($serverPid) { @exec("kill $serverPid 2>/dev/null"); $serverPid = 0; }
    try { $purge($USER[0]); } catch (Throwable $e) {}
    foreach ($jars as $j) { @unlink($j); }
};

try {
    $purge($USER[0]);
    $pdo->prepare("INSERT INTO users (full_name, username, password_hash, role, is_active)
                   VALUES ('کاربر خانه', :u, :p, 'user', 1)")
        ->execute(['u' => $USER[0], 'p' => password_hash($USER[1], PASSWORD_DEFAULT)]);
    $uid = (int)$pdo->lastInsertId();
    try { $pdo->prepare('UPDATE users SET balance_setup_at = NOW() WHERE id = :u')->execute(['u' => $uid]); } catch (Throwable $e) {}

    // ⛔ fixture: هر هفت قلم باید در حالتِ پیش‌فرض واقعاً بیاید.
    $pdo->prepare("INSERT INTO categories (user_id, name, type) VALUES (:u, 'خانه‌آزمون', 'expense')")->execute(['u' => $uid]);
    $cat = (int)$pdo->lastInsertId();
    [$jy, $jm, $jd] = gregorianToJalali((int)date('Y'), (int)date('m'), (int)date('d'));
    $win = monthComparisonWindow($jy, $jm, $jd);
    $tx = $pdo->prepare('INSERT INTO transactions (user_id, type, amount, title, transaction_date, category_id)
                         VALUES (:u, :t, :a, :ti, :d, :c)');
    $tx->execute(['u' => $uid, 't' => 'income',  'a' => 1000000, 'ti' => 'دریافتی', 'd' => today(), 'c' => null]);
    $tx->execute(['u' => $uid, 't' => 'expense', 'a' => 500000,  'ti' => 'خرجِ ماه', 'd' => today(), 'c' => $cat]);
    $tx->execute(['u' => $uid, 't' => 'expense', 'a' => 200000,  'ti' => 'خرجِ ماهِ قبل', 'd' => $win['prev_start'], 'c' => $cat]);
    $pdo->prepare("INSERT INTO budgets (user_id, category_id, period_type, amount, is_active) VALUES (:u, :c, 'monthly', 100000, 1)")
        ->execute(['u' => $uid, 'c' => $cat]);
    $pdo->prepare("INSERT INTO debts (user_id, direction, counterparty_name, amount, entry_date, due_date)
                   VALUES (:u, 'payable', 'طرف', 300000, :e, :d)")
        ->execute(['u' => $uid, 'e' => date('Y-m-d', strtotime('-10 days')), 'd' => date('Y-m-d', strtotime('-2 days'))]);

    // ---- ۲. جای خالیِ جمله‌ی خاموش پر می‌شود ----
    $kinds = fn(array $hl): array => array_column($hl, 'kind');
    $all = financialHighlights($uid, null, null, false, []);
    T::same(['overdue', 'budget', 'growth'], $kinds($all), 'پیش‌فرض: سه جمله‌ی اول (سقفِ سه‌تایی جمله‌ی ماه را کنار می‌گذارد)');
    $noOver = financialHighlights($uid, null, null, false, ['overdue']);
    T::same(['budget', 'growth', 'month'], $kinds($noOver), 'سررسید خاموش: جمله‌ی بعدی جایش را می‌گیرد، نه یک جای خالی');
    $none = financialHighlights($uid, null, null, true, ['overdue', 'budget', 'growth']);
    T::same([], $none, 'هر سه خاموش (و ماه روی کارت): هیچ جمله‌ای — کارتِ بینش رندر نمی‌شود');

    // ⛔ نگهبانِ خودِ `saveHomeHidden()` جدا سنجیده می‌شود: اندپوینت خاموش‌ها
    //    را از روی `HOME_WIDGETS` حساب می‌کند و کلیدِ دلخواه هرگز به آن
    //    نمی‌رسد، پس بدونِ این بررسی برداشتنِ صافیِ تابع زنده می‌ماند
    //    (جهش نشانش داد) — و فراخوانِ فردا همان رشته‌ی خام را می‌نوشت.
    T::same([], homeHiddenWidgets($uid), 'پیش‌فرض (و کش را پر می‌کند تا بررسیِ کش پوچ نباشد)');
    saveHomeHidden($uid, ['bogus', 'growth', '<x>', 'date']);
    $st = $pdo->prepare('SELECT home_hidden FROM users WHERE id = :u');
    $st->execute(['u' => $uid]);
    T::same('date,growth', $st->fetchColumn(), 'saveHomeHidden خودش کلیدِ ناشناخته را دور می‌ریزد و مرتب می‌نویسد');
    T::same(['date', 'growth'], homeHiddenWidgets($uid), 'کشِ درخواست هم‌زمان به‌روز شد (مقدارِ کهنه نمی‌ماند)');
    saveHomeHidden($uid, []);

    // ---- ۳. HTTP ----
    $port = 0;
    for ($p = 8951; $p <= 8969; $p++) {
        $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
        if ($sock) { fclose($sock); $port = $p; break; }
    }
    if (!$port) { T::skip('قلم‌های خانه', 'پورت آزاد پیدا نشد'); $cleanup(); exit(T::report()); }
    $log = tempnam(sys_get_temp_dir(), 'hwsrv');
    $serverPid = (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
        $port, escapeshellarg($root), escapeshellarg($log))));
    $up = false;
    for ($i = 0; $i < 40; $i++) {
        usleep(150000);
        $s = @fsockopen('127.0.0.1', $port, $a, $b, 0.3);
        if ($s) { fclose($s); $up = true; break; }
    }
    if (!$up) { T::blocked('قلم‌های خانه', 'سرور آزمایشی بالا نیامد'); $cleanup(); exit(T::report()); }

    $jar = tempnam(sys_get_temp_dir(), 'hwjar'); $jars[] = $jar;
    $req = function (string $path, ?array $post = null, array $hdr = []) use ($port, $jar): array {
        $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 25, CURLOPT_HTTPHEADER => $hdr]);
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        return [$code, $body];
    };
    $pdo->exec("DELETE FROM login_attempts WHERE request_ip = '127.0.0.1'");
    [, $html] = $req('login.php');
    preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m);
    $req('login.php', ['csrf_token' => $m[1] ?? '', 'username' => $USER[0], 'password' => $USER[1]]);
    [$code, $home] = $req('index.php');
    T::same(200, $code, 'خانه باز می‌شود');

    // ⚠ نشانه‌ها **کلاسِ واقعیِ عنصر**اند (`class="…`)، نه نامِ کلاس به‌تنهایی:
    //   CSS و JS درون‌صفحه‌ای همان نام‌ها را دارند و `strpos` خام با قلمِ
    //   رندرنشده هم سبز می‌ماند.
    $MARK = [
        'date'    => 'class="home-date"',
        'meter'   => 'class="month-meter',
        'split'   => 'class="balance-split"',
        'compare' => 'class="month-compare',
        'overdue' => 'سررسیدِ گذشته دارید',
        'budget'  => 'بودجه‌ی «خانه‌آزمون» رد شده',
        'growth'  => 'بیشتر از ماه قبل خرجِ «خانه‌آزمون»',
    ];
    $seen = function (string $html) use ($MARK): array {
        $out = [];
        foreach ($MARK as $k => $needle) { if (strpos($html, $needle) !== false) { $out[] = $k; } }
        return $out;
    };
    T::same(array_keys($MARK), $seen($home), 'پیش‌فرض: هر هفت قلم روی خانه هست');

    $csrf = preg_match('/<meta name="csrf-token" content="([^"]+)"/', $home, $mm) ? $mm[1] : '';
    // ⚠ `http_build_query` کلیدِ `on[]` را `on[0]` می‌کند؛ بدنه دستی ساخته می‌شود
    //   تا دقیقاً همان چیزی برود که `FormData` مرورگر می‌فرستد.
    $post = function (array $on) use ($port, $jar, $csrf): array {
        $body = 'csrf_token=' . rawurlencode($csrf);
        foreach ($on as $k) { $body .= '&' . rawurlencode('on[]') . '=' . rawurlencode($k); }
        $ch = curl_init("http://127.0.0.1:{$port}/api/save_home_widgets.php");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => ['X-Requested-With: XMLHttpRequest']]);
        $b = (string)curl_exec($ch);
        $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        return [$c, json_decode($b, true) ?: []];
    };
    $stored = function () use ($pdo, $uid) {
        $st = $pdo->prepare('SELECT home_hidden FROM users WHERE id = :u');
        $st->execute(['u' => $uid]);
        return $st->fetchColumn();
    };

    // سه قلم خاموش
    [$c, $d] = $post(['meter', 'compare', 'budget', 'growth']);
    T::ok($c === 200 && !empty($d['success']), 'ذخیره از اندپوینت', json_encode($d, JSON_UNESCAPED_UNICODE));
    T::same('date,split,overdue', $stored(), 'دیتابیس فقط خاموش‌ها را نگه می‌دارد');
    [, $home] = $req('index.php');
    T::same(['meter', 'compare', 'budget', 'growth'], $seen($home), 'خاموش‌ها رندر نمی‌شوند و بقیه سرِ جایشان‌اند');
    T::ok(strpos($home, 'class="balance-ribbon"') !== false, 'کارتِ ماه خودش می‌ماند (فقط کوچک‌تر)');

    // همه خاموش
    [$c, $d] = $post([]);
    T::ok($c === 200 && !empty($d['success']), 'همه خاموش ذخیره می‌شود');
    [, $home] = $req('index.php');
    T::same([], $seen($home), 'همه خاموش: هیچ‌کدام رندر نمی‌شود');
    T::ok(strpos($home, 'class="card insight-card"') === false, 'همه خاموش: کارتِ بینش اصلاً رندر نمی‌شود (نه یک کارتِ خالی)');
    T::ok(strpos($home, 'class="bv-num"') !== false, 'همه خاموش: عددِ «مانده این ماه» همچنان هست');

    // کلیدِ ناشناخته
    [$c, $d] = $post(['date', 'bogus', '<script>']);
    T::same(implode(',', array_diff($keys, ['date'])), $stored(), 'کلیدِ ناشناخته نه ذخیره می‌شود نه چیزی را روشن می‌کند');

    // پروفایل
    [, $prof] = $req('profile.php');
    T::ok(strpos($prof, 'id="homeWidgetsForm"') !== false, 'کارتِ «صفحه‌ی خانه» در پروفایل هست');
    T::same(count($keys), preg_match_all('/name="on\[\]"/', $prof), 'پروفایل برای هر قلم یک کلید دارد');
    T::same(1, preg_match_all('/name="on\[\]" value="[a-z]+" checked/', $prof), 'فقط همان قلمِ روشن تیک دارد');
    T::ok(preg_match('/name="on\[\]" value="date" checked/', $prof) === 1, 'و آن قلم «تاریخ» است');
    T::ok(preg_match('/id="hwAllOn"(?![^>]*hidden)/', $prof) === 1, 'وقتی چیزی خاموش است «همه را روشن کن» دیده می‌شود');

    // همه روشن → NULL
    [$c, $d] = $post($keys);
    T::same(null, $stored(), 'همه روشن یعنی NULL، نه رشته‌ی خالی (یک معنا، یک شکل)');
    [, $home] = $req('index.php');
    T::same(array_keys($MARK), $seen($home), 'برگشت به پیش‌فرض: هر هفت قلم برگشت');
    [, $prof] = $req('profile.php');
    T::ok(preg_match('/id="hwAllOn"[^>]*hidden/', $prof) === 1, 'همه روشن: «همه را روشن کن» پنهان است');

    // CSRF
    $ch = curl_init("http://127.0.0.1:{$port}/api/save_home_widgets.php");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => 'csrf_token=bad', CURLOPT_HTTPHEADER => ['X-Requested-With: XMLHttpRequest']]);
    curl_exec($ch);
    $cc = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);
    T::ok($cc >= 400, 'بدونِ توکنِ درست ذخیره نمی‌شود', (string)$cc);
    T::same(null, $stored(), 'و دیتابیس دست‌نخورده ماند');
} finally {
    $cleanup();
}

exit(T::report());
