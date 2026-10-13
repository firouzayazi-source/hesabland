<?php
/**
 * تستِ طلب/بدهیِ «بدون سررسید» — رفتار، با HTTP و نشستِ واقعی.
 *
 * ⛔ چرا: `debts.due_date` از روزِ اول `NOT NULL` بود، پس «به علی قرض
 *    دادم، هر وقت داشت پس می‌دهد» یک تاریخِ **ساختگی** می‌خواست — و آن
 *    تاریخ بعد در «آینده مالی»، «پول قابل خرج» و یادآوری‌ها به‌عنوان
 *    سررسیدِ واقعی می‌نشست. حالا خالی یعنی `NULL` یعنی «سررسید ندارد».
 *
 * چیزهایی که سنجیده می‌شوند و هر کدام یک خرابیِ بی‌صدای متفاوت است:
 *   • ثبت و ویرایش بدونِ سررسید واقعاً `NULL` می‌نشاند (نه امروز).
 *   • بدهیِ بی‌سررسید در `financialEvents()` نمی‌آید و «عقب‌افتاده» نیست.
 *   • صفحه‌ها (debts / person / search) با `NULL` تا آخر رندر می‌شوند.
 *   • پرداختِ جزئی روی آن کار می‌کند: ۲۰م، ۸م پرداخت → ۱۲م باقیمانده.
 *   • وامِ قسطی بدونِ هیچ لنگری (سررسید یا اولین قسط) رد می‌شود.
 *   • تاریخِ نامعتبر همچنان رد می‌شود (خالی ≠ هر چیزی).
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';

if (!file_exists(__DIR__ . '/../config/config.php')) {
    T::group('طلب/بدهی بدون سررسید');
    T::blocked('تست بدون سررسید', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/user_data.php';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::group('طلب/بدهی بدون سررسید');
    T::blocked('تست بدون سررسید', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

if (!tableExists('debts')) {
    T::group('طلب/بدهی بدون سررسید');
    T::skip('تست بدون سررسید', 'جدول debts نیست');
    exit(T::report());
}
if (!debtDueOptional()) {
    T::group('طلب/بدهی بدون سررسید');
    T::blocked('تست بدون سررسید', 'migration_debt_optional_due.sql اجرا نشده');
    exit(T::report());
}

$root = dirname(__DIR__);
$USER = '__test_nodue';
$PASS = 'NoDue!12345';
$NAME = 'طرفِ‌بی‌سررسید';

$cleanup = function () use ($pdo, $USER) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $USER]);
    $id = (int)$st->fetchColumn();
    if ($id <= 0) { return; }
    for ($i = 0; $i < 4; $i++) {
        foreach (array_reverse(userDataTables()) as $t) {
            try { $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]); }
            catch (PDOException $e) {}
        }
        try { $pdo->prepare('DELETE FROM users WHERE id = :u')->execute(['u' => $id]); break; }
        catch (PDOException $e) {}
    }
};
$cleanup();

$pdo->prepare(
    "INSERT INTO users (username, password_hash, full_name, role, is_active)
     VALUES (:u, :p, 'کاربر تست بی‌سررسید', 'user', 1)"
)->execute(['u' => $USER, 'p' => password_hash($PASS, PASSWORD_DEFAULT)]);
$uid = (int)$pdo->lastInsertId();
$pdo->prepare('UPDATE users SET pro_until = DATE_ADD(CURDATE(), INTERVAL 30 DAY) WHERE id = :u')
    ->execute(['u' => $uid]);
ensureDefaultWallet($uid);
$walletId = (int)defaultWalletId($uid);

// ---------- سرورِ آزمایشی ----------
$port = 0;
for ($p = 8931; $p <= 8960; $p++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
    if ($sock) { fclose($sock); $port = $p; break; }
}
if (!$port) {
    T::group('طلب/بدهی بدون سررسید');
    T::skip('تست بدون سررسید', 'پورت آزاد پیدا نشد');
    $cleanup();
    exit(T::report());
}
$log = tempnam(sys_get_temp_dir(), 'nodue');
$pid = (int)trim((string)shell_exec(sprintf(
    'php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
    $port, escapeshellarg($root), escapeshellarg($log))));
$up = false;
for ($i = 0; $i < 40; $i++) {
    usleep(150000);
    $s = @fsockopen('127.0.0.1', $port, $a, $b, 0.3);
    if ($s) { fclose($s); $up = true; break; }
}
$stop = function () use ($pid, $cleanup) {
    if ($pid > 0) { @shell_exec('kill ' . $pid . ' 2>/dev/null'); }
    $cleanup();
};
if (!$up) {
    T::group('طلب/بدهی بدون سررسید');
    T::ok(false, 'سرور آزمایشی بالا آمد', substr((string)@file_get_contents($log), 0, 300));
    $stop();
    exit(T::report());
}

$jar = tempnam(sys_get_temp_dir(), 'noduejar');
$req = function (string $path, ?array $post = null) use ($port, $jar): array {
    $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_HTTPHEADER     => ['X-Requested-With: XMLHttpRequest'],
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);
    return [$code, $body];
};

[, $loginHtml] = $req('login.php');
preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $loginHtml, $m);
[$lc] = $req('login.php', ['csrf_token' => $m[1] ?? '', 'username' => $USER, 'password' => $PASS]);

T::group('آماده‌سازی');
T::ok($lc === 302 || $lc === 303, 'کاربرِ آزمایشی وارد شد', "کد {$lc}");
if (!($lc === 302 || $lc === 303)) { $stop(); exit(T::report()); }

[, $html] = $req('debts.php');
preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $mm);
$tok = $mm[1] ?? '';
T::ok($tok !== '', 'توکن CSRF گرفته شد');
T::ok(str_contains($html, 'js-no-due'), 'کلیدِ «بدون سررسید» در فرم رندر می‌شود');

$today = date('Y-m-d');
$base = ['csrf_token' => $tok, 'direction' => 'receivable', 'counterparty_name' => $NAME,
         'amount' => '20000000', 'entry_date' => $today];

// ---------------------------------------------------------------
T::group('ثبت بدونِ سررسید');

[$c1, $b1] = $req('api/add_debt.php', $base + ['due_date' => '']);
T::same(200, $c1, 'ثبت بدونِ سررسید پذیرفته می‌شود', substr($b1, 0, 200));

$row = $pdo->prepare('SELECT id, due_date FROM debts WHERE user_id = :u ORDER BY id DESC LIMIT 1');
$row->execute(['u' => $uid]);
$d = $row->fetch();
$debtId = (int)($d['id'] ?? 0);
T::ok($debtId > 0, 'ردیف ساخته شد');
T::ok(array_key_exists('due_date', (array)$d) && $d['due_date'] === null,
    '⛔ سررسید NULL نشسته، نه امروز یا تاریخی ساختگی');

[$cBad] = $req('api/add_debt.php', $base + ['due_date' => '2026-13-45']);
T::same(422, $cBad, 'تاریخِ نامعتبر همچنان رد می‌شود');

// ---------------------------------------------------------------
T::group('⛔ بی‌سررسید هیچ‌جا سررسید نیست');

$ev = financialEvents($uid, date('Y-m-d', strtotime('-400 days')), date('Y-m-d', strtotime('+400 days')));
$hits = array_filter($ev, static fn($e) => ($e['kind'] ?? '') === 'debt'
    && str_contains((string)($e['title'] ?? ''), $NAME));
T::same(0, count($hits), 'در financialEvents نمی‌آید (نه به‌عنوان امروز، نه عقب‌افتاده)');

[$cp, $bp] = $req('debts.php');
T::same(200, $cp, 'صفحه‌ی طلب و بدهی ۲۰۰');
T::ok(str_contains($bp, '</html>'), 'تا آخر رندر می‌شود');
T::ok(str_contains($bp, 'بدون سررسید'), '«بدون سررسید» روی کارت نوشته می‌شود');
T::ok(!str_contains($bp, 'debt-overdue'), '⛔ کارت «عقب‌افتاده» (قرمز) نیست');

[$cq, $bq] = $req('person.php?name=' . rawurlencode($NAME));
T::same(200, $cq, 'گردشِ شخص ۲۰۰');
T::ok(str_contains($bq, '</html>') && str_contains($bq, 'بدون سررسید'),
    'گردشِ شخص با سررسیدِ NULL تا آخر رندر می‌شود');

[$cs, $bs] = $req('search.php?q=' . rawurlencode($NAME));
T::same(200, $cs, 'جست‌وجو ۲۰۰');
T::ok(str_contains($bs, '</html>'), 'جست‌وجو با سررسیدِ NULL تا آخر رندر می‌شود');

// ---------------------------------------------------------------
T::group('پرداختِ جزئی روی بدهیِ بی‌سررسید — ۲۰م، ۸م → ۱۲م');

$pay = $req('api/add_debt_payment.php', ['csrf_token' => $tok, 'debt_id' => (string)$debtId,
    'amount' => '8000000', 'payment_date' => $today, 'wallet_id' => (string)$walletId]);
T::same(200, $pay[0], 'پرداختِ جزئی ثبت شد', substr($pay[1], 0, 200));
$r = $pdo->prepare('SELECT amount, paid_amount, is_settled, due_date FROM debts WHERE id = :id');
$r->execute(['id' => $debtId]);
$after = $r->fetch();
T::same(12000000, debtRemaining($after), 'باقیمانده دقیقاً ۱۲٬۰۰۰٬۰۰۰');
T::same(0, (int)$after['is_settled'], 'هنوز تسویه نشده (جزئی)');
T::same(null, $after['due_date'], 'پرداخت به سررسید دست نزد');
[, $bp2] = $req('debts.php');
T::ok(str_contains($bp2, 'تسویه‌ی جزئی'), 'وضعیتِ «تسویه‌ی جزئی» روی کارت دیده می‌شود');

// ---------------------------------------------------------------
T::group('ویرایش: افزودن و برداشتنِ سررسید');

$due = date('Y-m-d', strtotime('+15 days'));
[$ce] = $req('api/update_debt.php', ['csrf_token' => $tok, 'debt_id' => (string)$debtId,
    'counterparty_name' => $NAME, 'amount' => '20000000', 'entry_date' => $today, 'due_date' => $due]);
T::same(200, $ce, 'ویرایش با سررسید');
$r->execute(['id' => $debtId]);
T::same($due, $r->fetch()['due_date'], 'سررسید نشست');

[$ce2] = $req('api/update_debt.php', ['csrf_token' => $tok, 'debt_id' => (string)$debtId,
    'counterparty_name' => $NAME, 'amount' => '20000000', 'entry_date' => $today, 'due_date' => '']);
T::same(200, $ce2, 'ویرایش با برداشتنِ سررسید');
$r->execute(['id' => $debtId]);
T::same(null, $r->fetch()['due_date'], '⛔ سررسید دوباره NULL شد');

// ---------------------------------------------------------------
if (tableHasColumn('debts', 'installment_count')) {
    T::group('⛔ وامِ قسطی لنگر لازم دارد');

    [$ci, $bi] = $req('api/add_debt.php', $base + ['due_date' => '', 'is_installment' => '1',
        'installment_count' => '12', 'installment_every' => 'monthly']);
    T::same(422, $ci, 'قسطی بدونِ سررسید و بدونِ اولین قسط رد می‌شود', substr($bi, 0, 200));

    [$ci2] = $req('api/add_debt.php', $base + ['due_date' => '', 'is_installment' => '1',
        'installment_count' => '12', 'installment_every' => 'monthly',
        'first_installment_date' => date('Y-m-d', strtotime('+30 days'))]);
    T::same(200, $ci2, 'قسطی با اولین قسط ولی بی‌سررسید پذیرفته می‌شود');
    $li = $pdo->prepare('SELECT id FROM debts WHERE user_id = :u AND installment_count = 12
                         ORDER BY id DESC LIMIT 1');
    $li->execute(['u' => $uid]);
    $instId = (int)$li->fetchColumn();

    [$cu] = $req('api/update_debt.php', ['csrf_token' => $tok, 'debt_id' => (string)$instId,
        'counterparty_name' => $NAME, 'amount' => '20000000', 'entry_date' => $today, 'due_date' => '']);
    T::same(200, $cu, 'ویرایشِ قسطیِ دارای اولین قسط بدونِ سررسید مجاز است');

    $pdo->prepare('UPDATE debts SET first_installment_date = NULL WHERE id = :id')->execute(['id' => $instId]);
    [$cu2] = $req('api/update_debt.php', ['csrf_token' => $tok, 'debt_id' => (string)$instId,
        'counterparty_name' => $NAME, 'amount' => '20000000', 'entry_date' => $today, 'due_date' => '']);
    T::same(422, $cu2, '⛔ ولی بدونِ هیچ لنگری رد می‌شود');
}

// ---------------------------------------------------------------
// ⚠ این گروه مالِ کارتِ سالانه‌ی داشبورد است و فقط چون همین تست نشستِ
//   واقعی دارد اینجا نشسته (`tests/test_year_report.php` تابعِ خالص را
//   می‌سنجد). سالِ خارج از بازه باید به امسال برگردد، نه صفحه‌ی خالی.
T::group('داشبورد: کارتِ سالانه و ?y=');

[$ty, , ] = gregorianToJalali((int)date('Y'), (int)date('m'), (int)date('d'));
$thisYear = 'سال ' . toPersianDigits($ty);
foreach (['', '?y=99999', '?y=abc', '?y=' . ($ty + 1)] as $q) {
    [$cd, $bd] = $req('dashboard.php' . $q);
    T::ok($cd === 200 && str_contains($bd, '</html>'), "داشبورد{$q} تا آخر رندر می‌شود");
    T::ok(str_contains($bd, $thisYear), "داشبورد{$q} روی امسال است");
}
[, $bd] = $req('dashboard.php');
T::same(4, substr_count($bd, 'class="season-card'), 'چهار کارتِ فصل');
T::same(12, substr_count($bd, '<a class="season-month'), 'دوازده ماه');
T::ok(!str_contains($bd, 'class="stats-grid"'), 'چهار کارتِ قدیمیِ امروز/هفته/ماه/سال برداشته شد');
T::ok(str_contains($bd, 'back=y' . $ty), '⛔ لینکِ ماه‌ها سالِ برگشت را حمل می‌کند');

// ⛔ «وارد ماه خاص میشیم دکمه برگشت نداره» — دکمه، و ماندنش با صافی.
[$ct, $bt] = $req('transactions.php?period=custom&from_date=2025-03-21&to_date=2025-04-20&back=y1404');
T::ok($ct === 200 && str_contains($bt, 'dashboard.php?y=1404#year'), '⛔ صفحه‌ی تراکنش دکمه‌ی برگشت به همان سال دارد');
T::ok(str_contains($bt, 'name="back" value="y1404"'), '⛔ و با زدنِ صافی از دست نمی‌رود');
[, $bt2] = $req('transactions.php?back=' . rawurlencode('https://evil.example'));
T::ok(!str_contains($bt2, 'evil.example') && !str_contains($bt2, 'class="page-back'),
    '⛔ `back` دلخواه هرگز در صفحه نمی‌نشیند');
[, $bt3] = $req('transactions.php');
T::ok(!str_contains($bt3, 'class="page-back'), 'بدونِ `back` دکمه‌ای نیست');

// تاریخِ امروز روی خانه — «تاریخ روز به شمسی در صفحه اصلی هنوز اضافه نشده».
[, $bh] = $req('index.php');
T::ok(str_contains($bh, 'class="home-date"') && str_contains($bh, jalaliLongDate(date('Y-m-d'))),
    '⛔ خانه تاریخِ امروز را به شمسی نشان می‌دهد');

$stop();
exit(T::report());
