<?php
/**
 * تستِ «فقط خواندنی» بعد از تمام شدنِ اشتراک.
 *
 * ⛔ چیزی که این تست نگه می‌دارد، مهم‌ترین وعده‌ی کلِ طرحِ اشتراک است:
 *    **داده‌ی کاربر گروگان گرفته نمی‌شود.** نسخه‌ی اولِ گیت، کلِ صفحه‌ی
 *    چک/طلب/معامله/دوره‌ای را با یک صفحه‌ی قفل عوض می‌کرد — یعنی
 *    کاربری که اشتراکش تمام می‌شد، چک‌ها و طلب‌های ماه‌های قبلش را هم
 *    **نمی‌دید**. حالا صفحه کامل رندر می‌شود و فقط درِ ورودیِ رکوردِ
 *    تازه بسته است.
 *
 * ⛔ و نیمه‌ی دوم: بستنِ چرخه‌ی رکوردِ موجود (پاس شدنِ چک، پرداختِ قسط،
 *    ویرایش، حذف) باید **باز** بماند. آن اتفاق‌ها در دنیای واقعی
 *    می‌افتند چه ما اجازه بدهیم چه ندهیم؛ اگر ثبتشان بسته باشد دفتر
 *    غلط می‌شود — و دفترِ غلط از دفترِ نداشته بدتر است.
 *
 * ⚠ این تست تنظیمِ سراسریِ `plan_enforced` را موقتاً روشن می‌کند و در
 *   پایان (و با `register_shutdown_function`، حتی اگر وسطِ کار بمیرد)
 *   به مقدارِ قبلی برمی‌گرداند. بدونِ آن، یک شکستِ وسطِ راه کلِ نصبِ
 *   توسعه را در حالتِ اجرا رها می‌کرد.
 */

// ---------- نگهبان: فقط خط فرمان ----------
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

if (!file_exists(__DIR__ . '/../config/config.php')) {
    T::group('اشتراک: فقط خواندنی');
    T::blocked('تست فقط‌خواندنی', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plan.php';
require_once __DIR__ . '/../includes/user_data.php';   // برای userDataTables()

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::group('اشتراک: فقط خواندنی');
    T::blocked('تست فقط‌خواندنی', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

if (!tableExists('cheques') || !tableExists('debts') || !tableExists('wallets')) {
    T::group('اشتراک: فقط خواندنی');
    T::skip('تست فقط‌خواندنی', 'migration های لازم اجرا نشده‌اند');
    exit(T::report());
}

$root = dirname(__DIR__);
$USER = '__test_planro';
$PASS = 'PlanRo!12345';
$MARK = 'شخصِ‌آزمایشیِ‌چک';          // نشانه‌ای که باید در صفحه دیده شود

// ---------------------------------------------------------------
// وضعیتِ قبلیِ تنظیم، و برگرداندنش در هر حالتی
$prevEnforce = getSetting(PLAN_ENFORCE_SETTING, '0');
register_shutdown_function(function () use ($prevEnforce) {
    try { setSetting(PLAN_ENFORCE_SETTING, $prevEnforce); } catch (Throwable $e) {}
});

$cleanup = function () use ($pdo, $USER) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $USER]);
    $id = (int)$st->fetchColumn();
    if ($id <= 0) { return; }
    // ⛔ فهرستِ جدول‌ها از خودِ دیتابیس کشف می‌شود، نه دستی — همان درسی
    //    که در test_api_auth گرفته شد: جدولِ تازه، یک ردیفِ جامانده، و
    //    ساختِ کاربر در اجرای بعدی بی‌صدا شکست می‌خورد.
    foreach (array_reverse(userDataTables()) as $t) {
        try { $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]); }
        catch (PDOException $e) {}
    }
    for ($i = 0; $i < 6; $i++) {
        try { $pdo->prepare('DELETE FROM users WHERE id = :u')->execute(['u' => $id]); break; }
        catch (PDOException $e) {
            foreach (userDataTables() as $t) {
                try { $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]); }
                catch (PDOException $e2) {}
            }
        }
    }
};
$cleanup();

$pdo->prepare(
    "INSERT INTO users (username, password_hash, full_name, role, is_active)
     VALUES (:u, :p, 'کاربر تست فقط‌خواندنی', 'user', 1)"
)->execute(['u' => $USER, 'p' => password_hash($PASS, PASSWORD_DEFAULT)]);
$uid = (int)$pdo->lastInsertId();

// اطمینان از اینکه کاربر Pro نیست
$pdo->prepare('UPDATE users SET pro_until = NULL WHERE id = :u')->execute(['u' => $uid]);

ensureDefaultWallet($uid);
$walletId = (int)defaultWalletId($uid);

// یک چک و یک طلب که **از قبل** ثبت شده‌اند — همان چیزی که نباید ناپدید شود
$pdo->prepare(
    "INSERT INTO cheques (user_id, direction, counterparty_name, amount, due_date)
     VALUES (:u, 'received', :n, 5000000, DATE_ADD(CURDATE(), INTERVAL 20 DAY))"
)->execute(['u' => $uid, 'n' => $MARK]);
$chequeId = (int)$pdo->lastInsertId();

$pdo->prepare(
    "INSERT INTO debts (user_id, direction, counterparty_name, amount, entry_date, due_date)
     VALUES (:u, 'payable', :n, 3000000, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))"
)->execute(['u' => $uid, 'n' => $MARK]);
$debtId = (int)$pdo->lastInsertId();

// ---------------------------------------------------------------
// سرورِ آزمایشی
$port = 0;
for ($p = 8971; $p <= 8999; $p++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
    if ($sock) { fclose($sock); $port = $p; break; }
}
if (!$port) {
    T::group('اشتراک: فقط خواندنی');
    T::skip('تست فقط‌خواندنی', 'پورت آزاد پیدا نشد');
    $cleanup();
    exit(T::report());
}

$log = tempnam(sys_get_temp_dir(), 'planro');
$pid = (int)trim((string)shell_exec(sprintf(
    'php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
    $port, escapeshellarg($root), escapeshellarg($log))));

$up = false;
for ($i = 0; $i < 40; $i++) {
    usleep(150000);
    $s = @fsockopen('127.0.0.1', $port, $a, $b, 0.3);
    if ($s) { fclose($s); $up = true; break; }
}
if (!$up) {
    T::group('اشتراک: فقط خواندنی');
    T::ok(false, 'سرور آزمایشی بالا آمد', substr((string)@file_get_contents($log), 0, 300));
    $cleanup();
    exit(T::report());
}

$jar = tempnam(sys_get_temp_dir(), 'planrojar');
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

$stop = function () use ($pid, $cleanup) {
    if ($pid > 0) { @shell_exec('kill ' . $pid . ' 2>/dev/null'); }
    $cleanup();
};

// ورود
[, $loginHtml] = $req('login.php');
preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $loginHtml, $m);
[$lc] = $req('login.php', [
    'csrf_token' => $m[1] ?? '', 'username' => $USER, 'password' => $PASS,
]);

T::group('آماده‌سازی');
T::ok($lc === 302 || $lc === 303, 'کاربرِ آزمایشی وارد شد', "کد {$lc}");
if (!($lc === 302 || $lc === 303)) { $stop(); exit(T::report()); }

/** توکنِ CSRF تازه از یک صفحه‌ی داخلی. */
$freshToken = function () use ($req): string {
    [, $html] = $req('index.php');
    preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $mm);
    return $mm[1] ?? '';
};

// ---------------------------------------------------------------
T::group('با اجرای خاموش، هیچ چیزی عوض نمی‌شود');

setSetting(PLAN_ENFORCE_SETTING, '0');
[$c0, $b0] = $req('cheques.php');
T::same(200, $c0, 'صفحه‌ی چک‌ها باز است');
T::ok(str_contains($b0, 'js-add-cheque'), '⛔ دکمه‌ی ثبت چک سرِ جایش است');
T::ok(!str_contains($b0, 'plan-ro'), 'نوارِ فقط‌خواندنی رندر نمی‌شود');

// ---------------------------------------------------------------
T::group('⛔ با اجرای روشن، داده‌ی قبلی همچنان دیده می‌شود');

setSetting(PLAN_ENFORCE_SETTING, '1');

[$c1, $b1] = $req('cheques.php');
T::same(200, $c1, 'صفحه‌ی چک‌ها همچنان ۲۰۰ می‌دهد، نه صفحه‌ی قفل');
T::ok(str_contains($b1, '</html>'), 'تا آخر رندر می‌شود');
T::ok(str_contains($b1, $MARK),
    '⛔ چکِ ثبت‌شده‌ی قبلی روی صفحه دیده می‌شود');
T::ok(str_contains($b1, 'plan-ro'), 'نوارِ «فقط خواندنی» رندر می‌شود');
T::ok(!str_contains($b1, 'js-add-cheque'),
    '⛔ دکمه‌ی «ثبت چک تازه» برداشته می‌شود');

[$c2, $b2] = $req('debts.php');
T::same(200, $c2, 'صفحه‌ی طلب و بدهی هم باز است');
T::ok(str_contains($b2, $MARK), '⛔ بدهیِ ثبت‌شده‌ی قبلی دیده می‌شود');
T::ok(!str_contains($b2, 'js-add-debt'), 'دکمه‌ی افزودن طلب/بدهی برداشته می‌شود');

[$c3, $b3] = $req('recurring.php');
T::same(200, $c3, 'صفحه‌ی تراکنش دوره‌ای باز است');
T::ok(!str_contains($b3, 'js-add-recurring'), 'دکمه‌ی «قانون جدید» برداشته می‌شود');

// ---------------------------------------------------------------
T::group('⛔ ثبتِ تازه بسته است (۴۰۲)');

$tok = $freshToken();
T::ok($tok !== '', 'توکن CSRF گرفته شد');

[$ac] = $req('api/add_cheque.php', [
    'csrf_token' => $tok, 'direction' => 'received',
    'counterparty_name' => 'یک نفر', 'amount' => '1000000',
    'due_date' => date('Y-m-d', strtotime('+10 days')),
]);
T::same(402, $ac, '⛔ ثبتِ چکِ تازه ۴۰۲ می‌گیرد');

[$ad] = $req('api/add_debt.php', [
    'csrf_token' => $tok, 'direction' => 'payable',
    'counterparty_name' => 'یک نفر', 'amount' => '1000000',
    'entry_date' => date('Y-m-d'),
]);
T::same(402, $ad, '⛔ ثبتِ بدهیِ تازه ۴۰۲ می‌گیرد');

[$at] = $req('api/save_trade.php', [
    'csrf_token' => $tok, 'title' => 'کالا', 'qty' => '1', 'buy_price' => '1000',
]);
T::same(402, $at, '⛔ ثبتِ معامله‌ی تازه ۴۰۲ می‌گیرد');

[$ar] = $req('api/save_recurring.php', [
    'csrf_token' => $tok, 'recurring_id' => '0', 'type' => 'expense',
    'title' => 'قبض', 'amount' => '100000', 'wallet_id' => (string)$walletId,
]);
T::same(402, $ar, '⛔ قانونِ دوره‌ایِ تازه ۴۰۲ می‌گیرد');

// ---------------------------------------------------------------
T::group('⛔ ولی رکوردِ موجود قفل نیست');

/*
 * ⚠ اینجا فقط «۴۰۲ نبودن» سنجیده می‌شود، نه موفق بودنِ کامل: هدفِ این
 *   گروه جای گیت است نه منطقِ خودِ اندپوینت (که تست‌های دیگری دارد).
 *   استثنا `update_cheque` است که با پیلودِ کامل می‌رود تا تست پوچ
 *   نباشد — «۴۰۲ نگرفت» با «کار کرد» یکی نیست.
 */
[$uc, $ucb] = $req('api/update_cheque.php', [
    'csrf_token' => $tok, 'cheque_id' => (string)$chequeId,
    'counterparty_name' => $MARK, 'amount' => '5500000',
    'due_date' => date('Y-m-d', strtotime('+20 days')),
]);
T::same(200, $uc, '⛔ ویرایشِ چکِ موجود کار می‌کند');
T::ok(str_contains($ucb, '"success":true'), 'و واقعاً ذخیره می‌شود', substr($ucb, 0, 120));

$st = $pdo->prepare('SELECT amount FROM cheques WHERE id = :i');
$st->execute(['i' => $chequeId]);
T::same(5500000, (int)$st->fetchColumn(), 'مبلغِ تازه در دیتابیس نشست');

foreach ([
    'api/toggle_cheque_settled.php' => ['cheque_id' => (string)$chequeId, 'status' => 'cleared'],
    'api/update_debt.php'           => ['debt_id' => (string)$debtId,
                                        'counterparty_name' => $MARK, 'amount' => '3000000',
                                        'entry_date' => date('Y-m-d')],
    'api/add_debt_payment.php'      => ['debt_id' => (string)$debtId, 'amount' => '500000',
                                        'payment_date' => date('Y-m-d')],
    'api/toggle_debt_settled.php'   => ['debt_id' => (string)$debtId, 'is_settled' => '0'],
    'api/save_recurring.php'        => ['recurring_id' => '999999', 'type' => 'expense',
                                        'title' => 'قبض', 'amount' => '100000'],
    'api/delete_cheque.php'         => ['cheque_id' => (string)$chequeId],
] as $ep => $payload) {
    [$code] = $req($ep, array_merge(['csrf_token' => $tok], $payload));
    T::ok($code !== 402, "⛔ {$ep} با اشتراکِ تمام‌شده ۴۰۲ نمی‌دهد", "کد {$code}");
}

// ---------------------------------------------------------------
T::group('و با Pro شدن، در دوباره باز می‌شود');

$pdo->prepare("UPDATE users SET pro_until = DATE_ADD(CURDATE(), INTERVAL 30 DAY) WHERE id = :u")
    ->execute(['u' => $uid]);

$tok2 = $freshToken();
[$ac2] = $req('api/add_cheque.php', [
    'csrf_token' => $tok2, 'direction' => 'received',
    'counterparty_name' => 'یک نفر', 'amount' => '1000000',
    'due_date' => date('Y-m-d', strtotime('+10 days')),
]);
T::ok($ac2 !== 402, 'کاربرِ Pro دیگر ۴۰۲ نمی‌گیرد', "کد {$ac2}");

[, $b4] = $req('cheques.php');
T::ok(!str_contains($b4, 'plan-ro'), 'نوارِ فقط‌خواندنی برای Pro رندر نمی‌شود');
T::ok(str_contains($b4, 'js-add-cheque'), 'و دکمه‌ی ثبت برمی‌گردد');

// ---------------------------------------------------------------
setSetting(PLAN_ENFORCE_SETTING, $prevEnforce);
$stop();
exit(T::report());
