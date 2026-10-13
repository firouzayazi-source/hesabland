<?php
/**
 * ⛔ خرابی‌های بی‌صدای پول که بازبینیِ کلِ پروژه (مهر ۱۴۰۵) پیدا کرد.
 *
 * **خواسته‌ی مالکِ نصب:** «کل پروژه رو برای وجود باگ، موازی‌کاری و کد مرده
 * بگرد و اصلاح کن.» هیچ‌کدامِ این‌ها خطا نمی‌داد و هیچ تستی هم نمی‌گرفتشان —
 * همه فقط عددِ غلط می‌ساختند. هر گروه یکی را از مسیرِ **واقعی** (اندپوینت با
 * ورود و CSRF، یا تابعِ اصلی) می‌سنجد:
 *
 *   ۱. ویرایشِ حساب موجودیِ اولیه‌ی منفی را مثبت نمی‌کند.
 *   ۲. حسابی که پرداختِ بدهی دارد حذف نمی‌شود (پولش از موجودی‌ها نمی‌پرد).
 *   ۳. مبلغِ بدهی کمتر از پرداخت‌شده نمی‌شود؛ تسویه با مبلغِ تازه از نو سنجیده.
 *   ۴. طلبِ چکِ برگشتی با ویرایش و حذفِ چک همگام می‌ماند.
 *   ۵. تراکنشِ دوره‌ای بی‌حساب ثبت نمی‌شود؛ قانونِ متوقف تأیید نمی‌شود.
 *   ۶. کارمزدِ انتقال سقف دارد؛ «۱٫۵» ده‌برابر خوانده نمی‌شود.
 *   ۷. بازگرداندنِ بکاپ پیوندهای بی‌کلیدِ خارجی را از نو نگاشت می‌کند.
 *   ۸. چکِ برگشتی در صفحه‌ی شخص دو بار شمرده نمی‌شود.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

$root = realpath(__DIR__ . '/..');

if (!file_exists($root . '/config/config.php')) {
    T::group('خرابی‌های بی‌صدای پول');
    T::blocked('خرابی‌های بی‌صدای پول', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/user_data.php';
require_once $root . '/includes/user_import.php';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::group('خرابی‌های بی‌صدای پول');
    T::blocked('خرابی‌های بی‌صدای پول', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

// ---------------------------------------------------------------
// ۶ (بخشِ خالص) — بی‌دیتابیس
// ---------------------------------------------------------------
T::group('مبلغ و تعداد — توابعِ خالص');
T::same([1, 1250000, 1250000, 0], [sanitizeAmount('۱٫۵'), sanitizeAmount('۱٬۲۵۰٬۰۰۰'),
                                    sanitizeAmount('1.250.000'), sanitizeAmount('')],
    '⛔ «٫» ممیز است (۱٫۵ ≠ ۱۵)؛ «٬» و «.» هزارگان می‌مانند');
T::same(['۱٬۲۵۰٫۵', '۱٬۲۵۰٫۵', '۳', '۰٫۰۰۱۳'],
    [formatQty(1250.5), formatQuantity(1250.5), formatQty(3), formatQuantity(0.00125)],
    'یک قالبِ تعداد: formatQuantity همان formatQty با ۴ رقم');

// ---------------------------------------------------------------
$U = '__test_money_audit';
$PASS = 'Audit#money9';

$purge = function () use ($pdo, $U) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $U]);
    $id = (int)$st->fetchColumn();
    if (!$id) { return; }
    try { deleteUserAccount($id); } catch (Throwable $e) { /* ادامه */ }
    $pdo->prepare('DELETE FROM users WHERE id = :i')->execute(['i' => $id]);
};
$purge();
$pdo->prepare("INSERT INTO users (full_name, username, password_hash, role, is_active)
               VALUES ('بازبینی پول', :u, :p, 'user', 1)")
    ->execute(['u' => $U, 'p' => password_hash($PASS, PASSWORD_DEFAULT)]);
$uid = (int)$pdo->lastInsertId();

$wallet = function (string $name, int $init) use ($pdo, $uid): int {
    $pdo->prepare("INSERT INTO wallets (user_id, name, kind, initial_balance, is_active, sort_order)
                   VALUES (:u, :n, 'bank', :i, 1, 0)")->execute(['u' => $uid, 'n' => $name, 'i' => $init]);
    return (int)$pdo->lastInsertId();
};
$wMain = $wallet('اصلی', 0);
$wNeg  = $wallet('منفی', -2000000);
$wPaid = $wallet('پرداخت‌دار', 0);
$wGone = $wallet('رفتنی', 0);

$col = function (string $sql, array $p) use ($pdo) {
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetchColumn();
};

// ---------------------------------------------------------------
// ۷ — بازگرداندنِ بکاپ (بی‌سرور)
// ---------------------------------------------------------------
T::group('⛔ بازگرداندنِ بکاپ پیوندهای بی‌کلیدِ خارجی را نگاشت می‌کند');
$fk = importForeignKeys();
T::same(['transactions', 'cheques'],
    [$fk['trade_sales']['profit_tx_id'] ?? null, $fk['debts']['cheque_id'] ?? null],
    'سودِ معامله و طلبِ چک در فهرستِ پیوندها');
if (tableExists('trades') && tableHasColumn('trade_sales', 'profit_tx_id')) {
    $pdo->prepare("INSERT INTO trades (user_id, title, qty, buy_total, side_costs, buy_date)
                   VALUES (:u, 'سکه', 2, 1000000, 0, CURDATE())")->execute(['u' => $uid]);
    $tid = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO trade_sales (trade_id, user_id, qty, sale_total, sale_date)
                   VALUES (:t, :u, 1, 800000, CURDATE())")->execute(['t' => $tid, 'u' => $uid]);
    syncTradeProfitTransactions($uid, $tid);
    $income = fn() => (int)$col("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = :u AND type = 'income'", ['u' => $uid]);
    $before = $income();
    $r = importUserData($uid, json_decode(json_encode(exportUserData($uid)), true));
    $tid2 = (int)$col('SELECT id FROM trades WHERE user_id = :u', ['u' => $uid]);
    $link = (int)$col('SELECT profit_tx_id FROM trade_sales WHERE user_id = :u', ['u' => $uid]);
    $real = (int)$col("SELECT id FROM transactions WHERE user_id = :u AND type = 'income' ORDER BY id DESC LIMIT 1", ['u' => $uid]);
    syncTradeProfitTransactions($uid, $tid2);
    T::same([true, $real, $before], [$r['ok'] ?? null, $link, $income()],
        '⛔ بعد از بازگرداندن، پیوندِ سود درست است و همگام‌سازی سودِ دوم نمی‌سازد');
    // شناسه‌ها بعد از بازگرداندن عوض شده‌اند.
    $ids = $pdo->prepare('SELECT id, name FROM wallets WHERE user_id = :u');
    $ids->execute(['u' => $uid]);
    foreach ($ids->fetchAll() as $w) {
        if ($w['name'] === 'اصلی') { $wMain = (int)$w['id']; }
        if ($w['name'] === 'منفی') { $wNeg = (int)$w['id']; }
        if ($w['name'] === 'پرداخت‌دار') { $wPaid = (int)$w['id']; }
        if ($w['name'] === 'رفتنی') { $wGone = (int)$w['id']; }
    }
    $pdo->prepare('DELETE FROM transactions WHERE user_id = :u')->execute(['u' => $uid]);
    $pdo->prepare('DELETE FROM trade_sales WHERE user_id = :u')->execute(['u' => $uid]);
    $pdo->prepare('DELETE FROM trades WHERE user_id = :u')->execute(['u' => $uid]);
} else {
    T::skip('بکاپِ معامله', 'جدولِ معامله نیامده');
}

// ---------------------------------------------------------------
// سرور
// ---------------------------------------------------------------
$port = 0;
for ($p = 8991; $p <= 9019; $p++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
    if ($sock) { fclose($sock); $port = $p; break; }
}
if (!$port || !function_exists('curl_init')) {
    T::skip('مسیرِ واقعی', $port ? 'افزونه‌ی curl نیست' : 'پورت آزاد پیدا نشد');
    $purge();
    exit(T::report());
}
$log = tempnam(sys_get_temp_dir(), 'moneyaud');
$pid = (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
    $port, escapeshellarg($root), escapeshellarg($log))));
$up = false;
for ($i = 0; $i < 40; $i++) {
    usleep(150000);
    $s = @fsockopen('127.0.0.1', $port, $a, $b, 0.3);
    if ($s) { fclose($s); $up = true; break; }
}
$jar = tempnam(sys_get_temp_dir(), 'moneyjar');
$stop = function () use ($pid, $purge, $jar) {
    if ($pid > 0) { @shell_exec('kill ' . $pid . ' 2>/dev/null'); }
    $purge();
    @unlink($jar);
};
if (!$up) {
    T::ok(false, 'سرور آزمایشی بالا آمد', substr((string)@file_get_contents($log), 0, 300));
    $stop();
    exit(T::report());
}

$req = function (string $path, ?array $post = null) use ($port, $jar): array {
    $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => ['X-Requested-With: XMLHttpRequest'],
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

[, $html] = $req('login.php');
preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m);
[$lc] = $req('login.php', ['csrf_token' => $m[1] ?? '', 'username' => $U, 'password' => $PASS]);
T::group('ورود');
T::ok($lc === 302 || $lc === 303, 'کاربرِ آزمایشی وارد شد', "کد {$lc}");
[, $page] = $req('wallets.php');
preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $page, $m);
$csrf = $m[1] ?? '';
$api = function (string $ep, array $post) use ($req, $csrf): array {
    [$c, $b] = $req('api/' . $ep, $post + ['csrf_token' => $csrf]);
    return [$c, json_decode($b, true) ?: []];
};

// ---------------------------------------------------------------
T::group('⛔ ۱ — ویرایشِ حساب موجودیِ اولیه‌ی منفی را مثبت نمی‌کند');
// فرم قدرِ مطلق را نشان می‌دهد؛ کاربر فقط نام را عوض کرد.
[$c] = $api('save_wallet.php', ['wallet_id' => $wNeg, 'name' => 'منفی ۲', 'kind' => 'bank',
                                'initial_balance' => '۲٬۰۰۰٬۰۰۰', 'color' => '#16794f']);
T::same([200, -2000000], [$c, (int)$col('SELECT initial_balance FROM wallets WHERE id = :i', ['i' => $wNeg])],
    '⛔ تغییرِ نام: −۲ میلیون می‌ماند (قبلاً +۲ می‌شد)');
[$c] = $api('save_wallet.php', ['wallet_id' => $wNeg, 'name' => 'منفی ۲', 'kind' => 'bank',
                                'initial_balance' => '۵۰۰٬۰۰۰', 'color' => '#16794f']);
T::same(500000, (int)$col('SELECT initial_balance FROM wallets WHERE id = :i', ['i' => $wNeg]),
    'عددِ تازه‌ی واقعی هنوز ذخیره می‌شود');

// ---------------------------------------------------------------
T::group('⛔ ۲ — حسابِ دارای پرداختِ بدهی حذف نمی‌شود');
$pdo->prepare("INSERT INTO debts (user_id, direction, counterparty_name, amount, paid_amount, entry_date, is_settled)
               VALUES (:u, 'receivable', 'تست', 1000000, 400000, CURDATE(), 0)")->execute(['u' => $uid]);
$debtP = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO debt_payments (debt_id, user_id, wallet_id, amount, payment_date)
               VALUES (:d, :u, :w, 400000, CURDATE())')->execute(['d' => $debtP, 'u' => $uid, 'w' => $wPaid]);
T::ok(walletUsageCount($wPaid, $uid) > 0, 'پرداختِ بدهی در شمارشِ استفاده‌ی حساب است');
[$c] = $api('delete_wallet.php', ['wallet_id' => $wPaid]);
T::same([409, 1], [$c, (int)$col('SELECT COUNT(*) FROM wallets WHERE id = :i', ['i' => $wPaid])],
    '⛔ حذف رد شد و حساب (و ۴۰۰ هزارش) سرِ جایش است');
[$c] = $api('delete_wallet.php', ['wallet_id' => $wGone]);
T::same(200, $c, 'حسابِ بی‌ارجاع هنوز حذف می‌شود');

// ---------------------------------------------------------------
T::group('⛔ ۳ — مبلغِ بدهی و وضعیتِ تسویه');
$upd = fn(int $id, string $amt) => $api('update_debt.php', ['debt_id' => $id, 'counterparty_name' => 'تست',
    'amount' => $amt, 'entry_date' => date('Y-m-d'), 'due_date' => '']);
[$c] = $upd($debtP, '300000');
T::same([422, 1000000], [$c, (int)$col('SELECT amount FROM debts WHERE id = :i', ['i' => $debtP])],
    '⛔ کمتر از پرداخت‌شده (۴۰۰ هزار) رد می‌شود');
$pdo->prepare("INSERT INTO debts (user_id, direction, counterparty_name, amount, paid_amount, entry_date, is_settled, settled_at)
               VALUES (:u, 'payable', 'تست', 500000, 500000, CURDATE(), 1, NOW())")->execute(['u' => $uid]);
$debtS = (int)$pdo->lastInsertId();
[$c] = $upd($debtS, '800000');
T::same([200, 0], [$c, (int)$col('SELECT is_settled FROM debts WHERE id = :i', ['i' => $debtS])],
    '⛔ بالا بردنِ مبلغِ تسویه‌شده آن را باز می‌کند (مانده‌ی ۳۰۰ هزار پنهان نمی‌ماند)');
[$c] = $upd($debtS, '500000');
T::same(1, (int)$col('SELECT is_settled FROM debts WHERE id = :i', ['i' => $debtS]), 'برگشت به مبلغِ پرداخت‌شده دوباره تسویه');
// ردیفِ قدیمیِ ناهمخوان (مبلغ < پرداخت) صفحه‌ی بدهی را خراب نمی‌کند.
$pdo->prepare("INSERT INTO debts (user_id, direction, counterparty_name, amount, paid_amount, entry_date, is_settled)
               VALUES (:u, 'receivable', 'کهنه', 100000, 300000, CURDATE(), 0)")->execute(['u' => $uid]);
[$c, $dp] = $req('debts.php');
$recv = preg_match('~<div class="value stats-income">\s*([^<]*?)\s*</div>~u', $dp, $mm) ? $mm[1] : 'MISSING';
T::same([200, '۶۰۰٬۰۰۰'], [$c, $recv],
    '⛔ جمعِ طلب‌ها با ردیفِ ناهمخوان هم درست است (۶۰۰ هزار، نه جمعِ خامِ مبلغ‌ها)');

// ---------------------------------------------------------------
T::group('⛔ ۴ — طلبِ چکِ برگشتی همگام با چک');
if (tableHasColumn('debts', 'cheque_id') && tableHasColumn('cheques', 'status')) {
    $pdo->prepare("INSERT INTO cheques (user_id, direction, counterparty_name, amount, due_date, is_settled, status)
                   VALUES (:u, 'received', 'برگشتی', 5000000, CURDATE(), 0, 'pending')")->execute(['u' => $uid]);
    $chq = (int)$pdo->lastInsertId();
    [$c] = $api('toggle_cheque_settled.php', ['cheque_id' => $chq, 'status' => 'bounced']);
    $linked = (int)$col('SELECT id FROM debts WHERE cheque_id = :c', ['c' => $chq]);
    T::ok($linked > 0, 'برگشت خوردن طلب ساخت', "کد {$c}");

    // ⛔ ۸ — صفحه‌ی شخص: ۵ میلیون فقط یک بار (در طلب)، نه در خالصِ چک هم.
    [, $pp] = $req('person.php?name=' . rawurlencode('برگشتی'));
    $net = function (string $label) use ($pp): string {
        return preg_match('~' . preg_quote($label, '~') . '</span>\s*<b[^>]*>\s*(.*?)\s*</b>~su', $pp, $mm)
            ? trim(strip_tags($mm[1])) : 'MISSING';
    };
    T::same(['۰', '۵٬۰۰۰٬۰۰۰'], [$net('خالص چک‌های پاس‌نشده'), $net('خالص طلب و بدهی')],
        '⛔ صفحه‌ی شخص: چکِ برگشتی در خالصِ چک‌های در جریان نیست — فقط یک بار، در طلب');

    [$c] = $api('update_cheque.php', ['cheque_id' => $chq, 'counterparty_name' => 'برگشتی تازه',
                                      'amount' => '4000000', 'due_date' => date('Y-m-d')]);
    $d = $pdo->prepare('SELECT amount, counterparty_name FROM debts WHERE id = :i');
    $d->execute(['i' => $linked]);
    T::same([200, 4000000, 'برگشتی تازه'], [$c, (int)($r = $d->fetch())['amount'], $r['counterparty_name']],
        '⛔ ویرایشِ چک مبلغ و نامِ طلب را هم عوض می‌کند');
    [$c] = $api('delete_cheque.php', ['cheque_id' => $chq]);
    $left = $pdo->prepare('SELECT cheque_id FROM debts WHERE id = :i');
    $left->execute(['i' => $linked]);
    $row = $left->fetch();
    T::same([200, true, null], [$c, $row !== false, is_array($row) && array_key_exists('cheque_id', $row) ? $row['cheque_id'] : 'x'],
        '⛔ حذفِ چک: طلب می‌ماند (طرف هنوز بدهکار است)، پیوندِ مرده برداشته می‌شود');
} else {
    T::skip('طلبِ چک', 'ستونِ cheque_id/status نیامده');
}

// ---------------------------------------------------------------
T::group('⛔ ۵ — تراکنشِ دوره‌ای');
$mkRule = function (int $walletId, int $active = 1) use ($pdo, $uid): int {
    $pdo->prepare("INSERT INTO recurring_transactions (user_id, wallet_id, type, amount, title, frequency,
                      interval_count, start_date, next_due_date, mode, is_active)
                   VALUES (:u, :w, 'expense', 70000, 'اشتراک', 'monthly', 1, :d, :d2, 'confirm', :a)")
        ->execute(['u' => $uid, 'w' => $walletId, 'd' => date('Y-m-d', strtotime('-40 days')),
                   'd2' => date('Y-m-d', strtotime('-10 days')), 'a' => $active]);
    return (int)$pdo->lastInsertId();
};
$wTmp = $wallet('موقت', 0);
$rule = $mkRule($wTmp);
$pdo->prepare('DELETE FROM wallets WHERE id = :i')->execute(['i' => $wTmp]);   // FK → wallet_id NULL
[$c] = $api('confirm_recurring.php', ['recurring_id' => $rule]);
$w = $col("SELECT wallet_id FROM transactions WHERE user_id = :u AND recurring_id = :r", ['u' => $uid, 'r' => $rule]);
T::same([200, true], [$c, $w !== null && (int)$w > 0], '⛔ قانونِ بی‌حساب: تراکنش به حسابِ پیش‌فرض می‌رود، نه بی‌حساب');
$paused = $mkRule($wMain, 0);
[$c] = $api('confirm_recurring.php', ['recurring_id' => $paused]);
T::same(404, $c, 'قانونِ متوقف تأیید نمی‌شود');
[$c] = $api('confirm_recurring.php', ['recurring_id' => $mkRule($wMain), 'amount' => '9999999999999']);
T::same(422, $c, 'مبلغِ جایگزینِ بیش از سقف رد می‌شود');

// ---------------------------------------------------------------
T::group('۶ — سقفِ کارمزدِ انتقال');
[$c] = $api('save_transfer.php', ['from_wallet_id' => $wMain, 'to_wallet_id' => $wNeg, 'amount' => '1000',
                                  'fee' => '99999999999999', 'transfer_date' => date('Y-m-d'), 'date' => date('Y-m-d')]);
T::same(422, $c, '⛔ کارمزدِ بیش از سقف رد می‌شود (وگرنه تا PHP_INT_MAX اشباع می‌شد)');

$stop();
exit(T::report());
