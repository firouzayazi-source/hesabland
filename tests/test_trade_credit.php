<?php
/**
 * تستِ خرید امانی/نسیه و فروش نسیه در «معاملات» — رفتار، با HTTP و
 * نشستِ واقعی.
 *
 * «از علی گوشی گرفتم و پولش را نداده‌ام» → بدهی به علی در «طلب و بدهی».
 * «به محمد فروختم و پولش را بعداً می‌دهد» → طلب از محمد.
 *
 * هر کدام از این‌ها اگر بشکند بی‌صداست — عددی غلط، بی‌هیچ خطایی:
 *   • پای نسیه هیچ حسابی را تکان نمی‌دهد (نه برداشت، نه واریز).
 *   • ردیفِ طلب/بدهی با همان مبلغ و نام و جهت ساخته و پیوند می‌شود.
 *   • ویرایشِ معامله ردیف را هم‌گام می‌کند؛ نقدی کردنش پسش می‌گیرد.
 *   • ردیفی که پرداخت دارد هرگز خودبه‌خود پاک نمی‌شود — فقط پیوندش.
 *   • مبلغِ تازه کمتر از پرداخت‌شده رد می‌شود و هیچ چیزی نیمه‌کاره نمی‌ماند.
 *   • حذفِ فروش/معامله طلب/بدهیِ بی‌پرداخت را هم می‌برد (یتیم نمی‌ماند).
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';

if (!file_exists(__DIR__ . '/../config/config.php')) {
    T::group('معاملات نسیه');
    T::blocked('تست معاملات نسیه', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/trade_credit.php';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::group('معاملات نسیه');
    T::blocked('تست معاملات نسیه', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

if (!tradesTablesExist($pdo) || !tradeCreditAvailable()) {
    T::group('معاملات نسیه');
    T::blocked('تست معاملات نسیه', 'migration_trade_credit.sql اجرا نشده');
    exit(T::report());
}

$root = dirname(__DIR__);
$USER = '__test_tradecredit';
$PASS = 'TradeCredit!123';
$ALI  = 'علیِ‌تستیِ‌امانی';
$MOH  = 'محمدِ‌تستیِ‌نسیه';

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
     VALUES (:u, :p, 'کاربر تست نسیه', 'user', 1)"
)->execute(['u' => $USER, 'p' => password_hash($PASS, PASSWORD_DEFAULT)]);
$uid = (int)$pdo->lastInsertId();
$pdo->prepare('UPDATE users SET pro_until = DATE_ADD(CURDATE(), INTERVAL 30 DAY) WHERE id = :u')
    ->execute(['u' => $uid]);
if (tableHasColumn('users', 'trades_enabled')) {
    $pdo->prepare('UPDATE users SET trades_enabled = 1 WHERE id = :u')->execute(['u' => $uid]);
}
ensureDefaultWallet($uid);
$walletId = (int)defaultWalletId($uid);
$pdo->prepare('UPDATE wallets SET initial_balance = 500000000 WHERE id = :w AND user_id = :u')
    ->execute(['w' => $walletId, 'u' => $uid]);

$bal = function () use ($uid, $walletId): int {
    foreach (walletBalances($uid) as $w) {
        if ((int)$w['id'] === $walletId) { return (int)$w['balance']; }
    }
    return PHP_INT_MIN;
};
$debtFor = function (string $col, int $id) use ($pdo, $uid): ?array {
    $st = $pdo->prepare("SELECT * FROM debts WHERE user_id = :u AND {$col} = :i");
    $st->execute(['u' => $uid, 'i' => $id]);
    $r = $st->fetch();
    return $r ?: null;
};
$debtById = function (int $id) use ($pdo, $uid): ?array {
    $st = $pdo->prepare('SELECT * FROM debts WHERE user_id = :u AND id = :i');
    $st->execute(['u' => $uid, 'i' => $id]);
    $r = $st->fetch();
    return $r ?: null;
};
$lastTrade = function () use ($pdo, $uid): array {
    $st = $pdo->prepare('SELECT * FROM trades WHERE user_id = :u ORDER BY id DESC LIMIT 1');
    $st->execute(['u' => $uid]);
    return $st->fetch() ?: [];
};
$lastSale = function () use ($pdo, $uid): array {
    $st = $pdo->prepare('SELECT * FROM trade_sales WHERE user_id = :u ORDER BY id DESC LIMIT 1');
    $st->execute(['u' => $uid]);
    return $st->fetch() ?: [];
};

// ---------- سرورِ آزمایشی ----------
$port = 0;
for ($p = 8961; $p <= 8990; $p++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
    if ($sock) { fclose($sock); $port = $p; break; }
}
if (!$port) {
    T::group('معاملات نسیه');
    T::skip('تست معاملات نسیه', 'پورت آزاد پیدا نشد');
    $cleanup();
    exit(T::report());
}
$log = tempnam(sys_get_temp_dir(), 'tcredit');
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
    T::group('معاملات نسیه');
    T::ok(false, 'سرور آزمایشی بالا آمد', substr((string)@file_get_contents($log), 0, 300));
    $stop();
    exit(T::report());
}

$jar = tempnam(sys_get_temp_dir(), 'tcreditjar');
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

[$pc, $html] = $req('trades.php');
preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $mm);
$tok = $mm[1] ?? '';
T::ok($tok !== '', 'توکن CSRF گرفته شد');
T::ok($pc === 200 && str_contains($html, 'id="trade_pay_mode"') && str_contains($html, 'id="sell_pay_mode"'),
    'هر دو فرم (خرید و فروش) گزینه‌ی «امانی/نسیه» را رندر می‌کنند');
T::ok(str_contains($html, '</html>'), 'صفحه‌ی معاملات تا آخر رندر شد');

$today = date('Y-m-d');
$b0 = $bal();

// ---------------------------------------------------------------
T::group('خرید امانی → بدهی به فروشنده');

$buy = ['csrf_token' => $tok, 'title' => 'گوشیِ امانیِ تست', 'qty' => '1',
        'buy_total' => '30000000', 'side_costs' => '0', 'buy_date' => $today,
        'buy_wallet_id' => (string)$walletId, 'pay_mode' => 'credit', 'notes' => ''];

[$cNo] = $req('api/save_trade.php', array_replace($buy, ['counterparty_name' => '']));
T::same(422, $cNo, '⛔ امانی بدونِ نام فروشنده رد می‌شود (بدهی به «هیچ‌کس» ساخته نمی‌شود)');
T::same([], $lastTrade(), 'و هیچ معامله‌ای نیمه‌کاره ننشست');

[$c1, $b1] = $req('api/save_trade.php', array_replace($buy, ['counterparty_name' => $ALI]));
T::same(200, $c1, 'خرید امانی ثبت شد', substr($b1, 0, 200));
$t1 = $lastTrade();
$t1Id = (int)($t1['id'] ?? 0);
T::same(1, (int)($t1['on_credit'] ?? -1), 'معامله on_credit = 1 دارد');
T::ok(array_key_exists('buy_wallet_id', $t1) && $t1['buy_wallet_id'] === null,
    '⛔ حسابِ خرید NULL است — حتی با اینکه فرم یک حساب فرستاده بود');
T::same($b0, $bal(), '⛔ موجودیِ حساب تکان نخورد');

$d1 = $debtFor('trade_id', $t1Id);
T::ok($d1 !== null, 'یک ردیفِ «طلب و بدهی» به همین معامله وصل شد');
T::same('payable', $d1['direction'] ?? '', 'جهتش «بدهی» است');
T::same(30000000, (int)($d1['amount'] ?? 0), 'مبلغش همان مبلغِ خرید است');
T::same($ALI, $d1['counterparty_name'] ?? '', 'به نامِ فروشنده');
if (debtDueOptional()) {
    T::ok(array_key_exists('due_date', (array)$d1) && $d1['due_date'] === null,
        'سررسیدِ ساختگی ندارد (NULL)');
}

// ---------------------------------------------------------------
T::group('ویرایش هم‌گام می‌کند');

[$c2] = $req('api/save_trade.php', array_replace($buy, ['trade_id' => (string)$t1Id, 'buy_total' => '32000000',
    'counterparty_name' => $ALI . '۲']));
T::same(200, $c2, 'ویرایش پذیرفته شد');
$d1b = $debtFor('trade_id', $t1Id);
T::same((int)$d1['id'], (int)($d1b['id'] ?? 0), '⛔ همان ردیف به‌روز شد، ردیفِ دوم ساخته نشد');
T::same(32000000, (int)($d1b['amount'] ?? 0), 'مبلغِ بدهی با مبلغِ تازه‌ی خرید هم‌گام شد');
T::same($ALI . '۲', $d1b['counterparty_name'] ?? '', 'نامِ طرف هم');

// پرداختِ جزئی روی بدهی
[$cp] = $req('api/add_debt_payment.php', ['csrf_token' => $tok, 'debt_id' => (string)$d1['id'],
    'amount' => '5000000', 'payment_date' => $today, 'wallet_id' => (string)$walletId]);
T::same(200, $cp, 'پرداختِ جزئیِ ۵م روی بدهی ثبت شد');
T::same($b0 - 5000000, $bal(), 'پرداخت از حساب کم شد (مسیرِ همیشگیِ طلب و بدهی)');

[$c3] = $req('api/save_trade.php', array_replace($buy, ['trade_id' => (string)$t1Id, 'buy_total' => '4000000',
    'counterparty_name' => $ALI]));
T::same(422, $c3, '⛔ مبلغِ کمتر از پرداخت‌شده رد می‌شود');
T::same(32000000, (int)$lastTrade()['buy_total'], '⛔ و خودِ معامله هم عوض نشد (rollback)');

// ---------------------------------------------------------------
T::group('فروش نسیه → طلب از خریدار');

$b1bal = $bal();
$sell = ['csrf_token' => $tok, 'trade_id' => (string)$t1Id, 'qty' => '1', 'sale_total' => '40000000',
         'sale_date' => $today, 'wallet_id' => (string)$walletId, 'pay_mode' => 'credit', 'notes' => ''];
[$cs0] = $req('api/sell_trade.php', array_replace($sell, ['counterparty_name' => '']));
T::same(422, $cs0, '⛔ فروش نسیه بدونِ نام خریدار رد می‌شود');
T::same([], $lastSale(), 'و هیچ فروشی ننشست');

[$cs1, $bs1] = $req('api/sell_trade.php', array_replace($sell, ['counterparty_name' => $MOH]));
T::same(200, $cs1, 'فروش نسیه ثبت شد', substr($bs1, 0, 200));
$s1 = $lastSale();
$s1Id = (int)($s1['id'] ?? 0);
T::same(1, (int)($s1['on_credit'] ?? -1), 'فروش on_credit = 1 دارد');
T::ok(array_key_exists('wallet_id', $s1) && $s1['wallet_id'] === null,
    '⛔ حسابِ فروش NULL است — نه کیف پولِ پیش‌فرض');
T::same($b1bal, $bal(), '⛔ موجودیِ حساب تکان نخورد');

$r1 = $debtFor('trade_sale_id', $s1Id);
T::ok($r1 !== null, 'طلب به همین فروش وصل شد');
T::same('receivable', $r1['direction'] ?? '', 'جهتش «طلب» است');
T::same(40000000, (int)($r1['amount'] ?? 0), 'مبلغش همان مبلغِ فروش است');
T::same($MOH, $r1['counterparty_name'] ?? '', 'به نامِ خریدار');
if (tableHasColumn('trade_sales', 'profit_tx_id')) {
    T::ok(!empty($s1['profit_tx_id']), 'سهمِ سود مثل همیشه ثبت شد — فروش انجام شده');
}

[, $th] = $req('trades.php?view=archive');
T::ok(str_contains($th, 'trade-credit-tag') && str_contains($th, 'نسیه به ' . $MOH),
    'روی کارتِ فروش «نسیه به …» دیده می‌شود');
[$dc, $dh] = $req('debts.php');
T::ok($dc === 200 && str_contains($dh, '</html>') && str_contains($dh, $MOH),
    'صفحه‌ی «طلب و بدهی» طلبِ تازه را نشان می‌دهد');

// ---------------------------------------------------------------
T::group('حذفِ فروش');

[$cd] = $req('api/delete_trade_sale.php', ['csrf_token' => $tok, 'sale_id' => (string)$s1Id]);
T::same(200, $cd, 'فروش حذف شد');
T::same(null, $debtById((int)$r1['id']), '⛔ طلبِ بی‌پرداختش هم رفت — یتیم نماند');

// ---------------------------------------------------------------
T::group('نقدی کردنِ خرید');

[$c4, $b4] = $req('api/save_trade.php', array_replace($buy, ['trade_id' => (string)$t1Id, 'buy_total' => '32000000',
    'counterparty_name' => $ALI, 'pay_mode' => 'paid']));
T::same(200, $c4, 'تبدیل به «پرداخت کردم» پذیرفته شد');
$kept = $debtById((int)$d1['id']);
T::ok($kept !== null, '⛔ بدهیِ پرداخت‌دار پاک نشد — پول واقعاً جابه‌جا شده');
T::ok($kept !== null && $kept['trade_id'] === null, 'فقط پیوندش برداشته شد');
T::ok(str_contains($b4, 'طلب و بدهی'), 'پیام می‌گوید ردیف در «طلب و بدهی» ماند');
$t1c = $lastTrade();
T::same(0, (int)$t1c['on_credit'], 'معامله دیگر امانی نیست');
T::same($walletId, (int)$t1c['buy_wallet_id'], 'و به حساب وصل شد');

// امانیِ بی‌پرداخت → نقدی: ردیف پاک می‌شود
[$c5] = $req('api/save_trade.php', array_replace($buy, ['title' => 'امانیِ دوم', 'counterparty_name' => $ALI]));
T::same(200, $c5, 'امانیِ دوم ثبت شد');
$t2Id = (int)$lastTrade()['id'];
$d2 = $debtFor('trade_id', $t2Id);
T::ok($d2 !== null, 'بدهیِ دوم ساخته شد');
[$c6] = $req('api/save_trade.php', array_replace($buy, ['trade_id' => (string)$t2Id, 'title' => 'امانیِ دوم',
    'counterparty_name' => $ALI, 'pay_mode' => 'paid']));
T::same(200, $c6, 'به نقدی تبدیل شد');
T::same(null, $debtById((int)$d2['id']), '⛔ بدهیِ بی‌پرداخت پس گرفته شد');

// ---------------------------------------------------------------
T::group('حذفِ معامله');

[$c7] = $req('api/save_trade.php', array_replace($buy, ['title' => 'امانیِ سوم', 'qty' => '2', 'counterparty_name' => $ALI]));
T::same(200, $c7, 'امانیِ سوم ثبت شد');
$t3Id = (int)$lastTrade()['id'];
$d3 = $debtFor('trade_id', $t3Id);
[$c8] = $req('api/sell_trade.php', array_replace($sell, ['trade_id' => (string)$t3Id, 'counterparty_name' => $MOH]));
[$c9] = $req('api/sell_trade.php', array_replace($sell, ['trade_id' => (string)$t3Id, 'counterparty_name' => $MOH . '۲']));
T::ok($c8 === 200 && $c9 === 200, 'دو فروشِ نسیه روی آن ثبت شد');
$st = $pdo->prepare('SELECT id FROM trade_sales WHERE trade_id = :t AND user_id = :u ORDER BY id');
$st->execute(['t' => $t3Id, 'u' => $uid]);
$sids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
$ra = $debtFor('trade_sale_id', $sids[0] ?? 0);
$rb = $debtFor('trade_sale_id', $sids[1] ?? 0);
T::ok($ra !== null && $rb !== null && $d3 !== null, 'سه ردیفِ طلب/بدهی وصل‌اند');
[$cpp] = $req('api/add_debt_payment.php', ['csrf_token' => $tok, 'debt_id' => (string)$rb['id'],
    'amount' => '1000000', 'payment_date' => $today, 'wallet_id' => (string)$walletId]);
T::same(200, $cpp, 'روی طلبِ دوم پرداختِ جزئی ثبت شد');

[$cdt, $bdt] = $req('api/delete_trade.php', ['csrf_token' => $tok, 'trade_id' => (string)$t3Id]);
T::same(200, $cdt, 'معامله حذف شد');
T::same(null, $debtById((int)$d3['id']), '⛔ بدهیِ خریدِ بی‌پرداخت رفت');
T::same(null, $debtById((int)$ra['id']), '⛔ طلبِ فروشِ بی‌پرداخت رفت');
$rbAfter = $debtById((int)$rb['id']);
T::ok($rbAfter !== null && $rbAfter['trade_sale_id'] === null,
    '⛔ طلبِ پرداخت‌دار ماند و فقط پیوندش برداشته شد');
T::ok(str_contains($bdt, 'طلب و بدهی'), 'پیام می‌گوید چه چیزی ماند');

// ---------------------------------------------------------------
T::group('بدونِ تغییرِ رفتارِ قدیمی');

[$c10] = $req('api/save_trade.php', array_replace($buy, ['title' => 'خریدِ نقدی', 'pay_mode' => 'paid', 'counterparty_name' => '']));
T::same(200, $c10, 'خریدِ نقدیِ بی‌نام مثل همیشه ثبت می‌شود');
$t4 = $lastTrade();
T::same(null, $debtFor('trade_id', (int)$t4['id']), 'و هیچ طلب/بدهی‌ای نمی‌سازد');
$b4bal = $bal();
[$c11] = $req('api/sell_trade.php', array_replace($sell, ['trade_id' => (string)$t4['id'], 'pay_mode' => 'paid',
    'counterparty_name' => '', 'wallet_id' => '0']));
T::same(200, $c11, 'فروشِ نقدی مثل همیشه ثبت می‌شود');
T::same($walletId, (int)$lastSale()['wallet_id'], 'پولش به کیف پولِ پیش‌فرض رفت');
T::same($b4bal + 40000000, $bal(), 'و موجودی به اندازه‌ی فروش بالا رفت');

// ⛔ جداسازیِ کاربران: با شناسه‌ی معامله‌ای که **واقعاً** بدهیِ پیوندی
//    دارد، ولی کاربرِ دیگر — وگرنه بررسی روی شناسه‌ی بی‌ردیف پوچ است.
[$c12] = $req('api/save_trade.php', array_replace($buy, ['title' => 'امانیِ جداسازی', 'counterparty_name' => $ALI]));
$t5Id = (int)$lastTrade()['id'];
$d5 = $debtFor('trade_id', $t5Id);
T::ok($c12 === 200 && $d5 !== null, 'امانیِ جداسازی با بدهیِ پیوندی ثبت شد');
$other = $uid + 999999;
T::same(null, tradeCreditLinked($pdo, $other, 'buy', $t5Id),
    '⛔ tradeCreditLinked برای کاربرِ دیگر همان ردیف را پیدا نمی‌کند');
T::same('none', tradeCreditRelease($pdo, $other, 'buy', $t5Id),
    '⛔ tradeCreditRelease با کاربرِ دیگر به ردیف دست نمی‌زند');
// کاربرِ ناموجود → درجِ تازه روی کلیدِ خارجیِ کاربر می‌میرد؛ مهم این است
// که ردیفِ **این** کاربر دست نخورد.
$up = [];
try { $up = tradeCreditUpsert($pdo, $other, 'buy', $t5Id, 'نفوذی', 1, $today, 'x'); }
catch (PDOException $e) {}
$d5b = $debtById((int)$d5['id']);
T::ok($d5b !== null && (int)$d5b['amount'] === 30000000 && $d5b['counterparty_name'] === $ALI,
    '⛔ tradeCreditUpsert با کاربرِ دیگر بدهیِ این کاربر را عوض نمی‌کند');
if (!empty($up['debt_id'])) {
    $pdo->prepare('DELETE FROM debts WHERE id = :i')->execute(['i' => (int)$up['debt_id']]);
}

$stop();
exit(T::report());
