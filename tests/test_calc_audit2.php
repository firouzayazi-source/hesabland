<?php
/**
 * تستِ «بازرسیِ دوم» (مهر ۱۴۰۵) — ویرایش و حذفی که عددِ دیگری را بی‌صدا خراب می‌کرد.
 * خواسته‌ی مالکِ نصب: «باز هم بگرد برای باگ‌های بیشتر و ریزبینانه، مثلِ یک متخصص».
 *
 * ⛔ هر بررسی سناریوی خودِ خرابی را با HTTPِ واقعی (یا تابعِ تنهای مرجع) اجرا می‌کند و
 *    عددِ درست را می‌سنجد؛ چرایی کنارِ خودِ کد است («بازرسیِ مهر ۱۴۰۵»).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
T::group('بازرسیِ دوم — توابعِ خالص');

if (!file_exists(__DIR__ . '/../config/config.php')) { T::blocked('بازرسیِ دوم', 'config/config.php وجود ندارد'); exit(T::report()); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';

T::ok(!isValidDate('1403-12-30'), '⛔ تاریخِ شمسیِ میلادی‌خوانده‌شده رد می‌شود');
T::ok(!isValidDate('0001-01-01') && !isValidDate('9999-12-31'), 'سالِ بی‌معنا رد می‌شود');
T::ok(isValidDate('2026-10-09'), 'تاریخِ عادی پذیرفته');
T::ok(amountInputError('-250000') !== null && amountInputError('−۲۵۰۰۰۰') !== null, '⛔ مبلغِ منفی رد می‌شود (نه بی‌صدا مثبت)');
T::ok(amountInputError('1e6') !== null, 'نمادِ علمی رد می‌شود');
T::same(null, amountInputError('۱٬۲۵۰٬۰۰۰'), 'مبلغِ عادی پذیرفته');

try { $pdo = Database::getConnection(); } catch (Throwable $e) { T::blocked('بازرسیِ دوم', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }

$refs = categoryRefTables();
T::ok(!isset($refs['biz_products']) && !isset($refs['biz_payments']), '⛔ category_idِ فروشگاه (کلید به جدولِ دیگر) ارجاعِ دسته‌ی شخصی نیست', implode(',', array_keys($refs)));
T::ok(isset($refs['transactions'], $refs['budgets']), 'ارجاع‌های واقعی هنوز کشف می‌شوند');

const CB_PREFIX = '__calc_audit2_';
const CB_PASS   = 'Calc#audit2x';
$wipe = function () use ($pdo) {
    $ids = $pdo->query("SELECT id FROM users WHERE username LIKE '" . CB_PREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) { deleteUserAccount((int)$id); }
};
$wipe();
register_shutdown_function($wipe);
$make = function (string $name) use ($pdo): int {
    $res = createUserAccount($pdo, 'کاربرِ ' . $name, CB_PREFIX . $name, CB_PREFIX . $name . '@example.com', CB_PASS);
    if (!($res['ok'] ?? false)) { throw new RuntimeException('ساختِ کاربر: ' . ($res['error'] ?? '?')); }
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => CB_PREFIX . $name]);
    return (int)$st->fetchColumn();
};
$u = $make('p');
$today = date('Y-m-d');
$one = function (string $sql, array $p = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn(); };

// ---- HTTP ----
T::group('بازرسیِ دوم — پولِ شخصی (HTTP)');
$root = dirname(__DIR__);
$port = 0;
for ($p = 9460; $p <= 9500; $p++) { $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2); if ($sock) { fclose($sock); $port = $p; break; } }
if (!$port) { T::blocked('بازرسیِ دوم (HTTP)', 'پورت آزاد پیدا نشد'); exit(T::report()); }
$log = tempnam(sys_get_temp_dir(), 'calc2');
$srv = (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!', $port, escapeshellarg($root), escapeshellarg($log))));
register_shutdown_function(static function () use ($srv, $log) { exec("kill {$srv} 2>/dev/null"); @unlink($log); });
for ($i = 0, $up = false; $i < 40 && !$up; $i++) { usleep(150000); $sk = @fsockopen('127.0.0.1', $port, $a, $b, 0.3); if ($sk) { fclose($sk); $up = true; } }
T::ok($up, 'سرورِ آزمایشی بالا آمد');
if (!$up) { exit(T::report()); }
$jar = tempnam(sys_get_temp_dir(), 'calc2jar');
$req = function (string $path, ?array $post = null) use ($port, $jar): array {
    $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 40]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); unset($ch);
    return [$code, $body];
};
[, $lp] = $req('login.php');
$tok = preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $lp, $m) ? $m[1] : '';
$req('login.php', ['csrf_token' => $tok, 'username' => CB_PREFIX . 'p', 'password' => CB_PASS]);
[, $html] = $req('debts.php');
$tok = preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m) ? $m[1] : '';
T::ok($tok !== '', 'وارد شد');
$api = function (string $ep, array $post) use ($req, $tok): array {
    [$c, $b] = $req('api/' . $ep, $post + ['csrf_token' => $tok]);
    return ['code' => $c] + (json_decode($b, true) ?: ['_raw' => mb_substr($b, 0, 200)]);
};
$wallet = (int)$one('SELECT id FROM wallets WHERE user_id = :u ORDER BY id LIMIT 1', ['u' => $u]);
$cat = function (string $type = 'expense') use ($pdo, $u): int {
    $pdo->prepare('INSERT INTO categories (user_id, name, type) VALUES (:u, :n, :t)')
        ->execute(['u' => $u, 'n' => 'دسته‌ی بازرسیِ دوم ' . $type . mt_rand(100, 999), 't' => $type]);
    return (int)$pdo->lastInsertId();
};

// ---- ۱. تغییرِ تاریخِ شروعِ دوره‌ای تراکنش‌ها را دوباره نمی‌سازد ----
if (tableHasColumn('transactions', 'recurring_id')) {
    $start = date('Y-m-d', strtotime($today . ' -95 days'));
    $pdo->prepare("INSERT INTO recurring_transactions (user_id, wallet_id, type, amount, title, frequency, interval_count, start_date, next_due_date, mode)
                   VALUES (:u, :w, 'expense', 1000000, 'اجاره‌ی بازرسی', 'monthly', 1, :s, :n, 'auto')")
        ->execute(['u' => $u, 'w' => $wallet, 's' => $start, 'n' => $start]);
    $rid = (int)$pdo->lastInsertId();
    processRecurringTransactions($u, true);
    $gen = fn() => (int)$one('SELECT COUNT(*) FROM transactions WHERE recurring_id = :r AND user_id = :u', ['r' => $rid, 'u' => $u]);
    $n0 = $gen();
    T::ok($n0 >= 3, 'دوره‌ایِ خودکار تراکنش‌های گذشته را ساخت', (string)$n0);
    $r = $api('save_recurring.php', ['recurring_id' => $rid, 'type' => 'expense', 'title' => 'اجاره‌ی بازرسی', 'amount' => '1000000',
        'wallet_id' => $wallet, 'frequency' => 'monthly', 'interval_count' => '1',
        'start_date' => date('Y-m-d', strtotime($start . ' +1 day')), 'mode' => 'auto']);
    T::ok(!empty($r['success']), 'تاریخِ شروع یک روز جلو رفت', json_encode($r, JSON_UNESCAPED_UNICODE));
    processRecurringTransactions($u, true);
    T::same($n0, $gen(), '⛔ تراکنش‌های گذشته دوباره ساخته نشدند (موجودی دو برابر کم نشد)');
}

// ---- ۲. حذفِ دسته‌ی دارای بودجه ----
$bc = $cat();
$pdo->prepare("INSERT INTO budgets (user_id, category_id, period_type, amount) VALUES (:u, :c, 'monthly', 500000)")->execute(['u' => $u, 'c' => $bc]);
$r = $api('manage_reference.php', ['kind' => 'category', 'action' => 'delete', 'id' => $bc]);
T::ok(empty($r['success']), '⛔ دسته‌ای که بودجه دارد حذف نشد', json_encode($r, JSON_UNESCAPED_UNICODE));
T::same(1, (int)$one('SELECT COUNT(*) FROM budgets WHERE category_id = :c', ['c' => $bc]), 'بودجه سرِ جایش ماند');

// ---- ۲ب. ویرایشِ بودجه به دسته‌ای که بودجه دارد: ۴۲۲ نه ۵۰۰ ----
$bc2 = $cat();
$pdo->prepare("INSERT INTO budgets (user_id, category_id, period_type, amount) VALUES (:u, :c, 'monthly', 700000)")->execute(['u' => $u, 'c' => $bc2]);
$bid2 = (int)$pdo->lastInsertId();
$r = $api('save_budget.php', ['budget_id' => $bid2, 'category_id' => $bc, 'period_type' => 'monthly', 'amount' => '700000']);
T::same(422, $r['code'], '⛔ ویرایشِ بودجه به دسته‌ی تکراری: پیامِ روشن (۴۲۲)، نه خطای ۵۰۰');

// ---- ۳. حسابِ آخر غیرفعال نمی‌شود ----
foreach ($pdo->query('SELECT id FROM wallets WHERE user_id = ' . $u . ' AND id <> ' . $wallet)->fetchAll(PDO::FETCH_COLUMN) as $wx) {
    $pdo->prepare('UPDATE wallets SET is_active = 0 WHERE id = :i')->execute(['i' => (int)$wx]);
}
$r = $api('toggle_wallet.php', ['wallet_id' => $wallet]);
T::ok(empty($r['success']), '⛔ تنها حسابِ فعال غیرفعال نشد', json_encode($r, JSON_UNESCAPED_UNICODE));
T::same(1, (int)$one('SELECT is_active FROM wallets WHERE id = :i', ['i' => $wallet]), 'حساب فعال ماند');

// ---- ۴. مبلغِ منفی ----
$ec = $cat();
$r = $api('add_transaction.php', ['type' => 'expense', 'amount' => '-250000', 'title' => 'منفی', 'transaction_date' => $today, 'category_id' => $ec, 'wallet_id' => $wallet]);
T::ok(empty($r['success']), '⛔ هزینه‌ی «‎-250000» رد شد (نه هزینه‌ی مثبتِ ۲۵۰ هزار)', json_encode($r, JSON_UNESCAPED_UNICODE));

// ---- ۵. سقفِ بهای دارایی ----
if (tableExists('asset_types')) {
    $pdo->prepare("INSERT INTO asset_types (user_id, name, unit) VALUES (:u, 'سکه‌ی بازرسی', 'عدد')")->execute(['u' => $u]);
    $at = (int)$pdo->lastInsertId();
    $r = $api('add_asset.php', ['asset_type_id' => $at, 'quantity' => '1', 'unit_price' => '99999999999999999999', 'entry_date' => $today]);
    T::ok(empty($r['success']), '⛔ بهای بی‌اندازه رد شد (نه بریده به سقفِ BIGINT)');
}

// ---- ۶. بدهیِ کامل‌پرداخت‌شده «باز» نمی‌شود ----
$r = $api('add_debt.php', ['direction' => 'payable', 'counterparty_name' => 'بستانکارِ بازرسیِ دوم', 'amount' => '400000', 'entry_date' => $today, 'due_date' => '']);
$did = (int)$one("SELECT id FROM debts WHERE user_id = :u AND counterparty_name = 'بستانکارِ بازرسیِ دوم'", ['u' => $u]);
$api('add_debt_payment.php', ['debt_id' => $did, 'amount' => '400000', 'payment_date' => $today]);
$r = $api('toggle_debt_settled.php', ['debt_id' => $did]);
T::ok(empty($r['success']), '⛔ برداشتنِ تیکِ تسویه‌ی پرداخت‌شده با پرداخت‌ها رد شد', json_encode($r, JSON_UNESCAPED_UNICODE));
T::same(1, (int)$one('SELECT is_settled FROM debts WHERE id = :i', ['i' => $did]), 'تسویه ماند (نه «باز با مانده‌ی صفر»)');
$api('add_debt.php', ['direction' => 'payable', 'counterparty_name' => 'بستانکارِ بازِ بازرسی', 'amount' => '400000', 'entry_date' => $today, 'due_date' => '']);
$did2 = (int)$one("SELECT id FROM debts WHERE user_id = :u AND counterparty_name = 'بستانکارِ بازِ بازرسی'", ['u' => $u]);
$r = $api('add_debt_payment.php', ['debt_id' => $did2, 'amount' => '-400', 'payment_date' => $today]);
T::ok(empty($r['success']), '⛔ پرداختِ «‎-400» رد شد (نه پرداختِ ۴۰۰)', json_encode($r, JSON_UNESCAPED_UNICODE));
T::same(0, (int)$one('SELECT paid_amount FROM debts WHERE id = :i', ['i' => $did2]), 'هیچ پرداختی ننشست');

// ---- ۷. «لغو»ِ حذفِ چکِ برگشتی پیوندِ طلب را برمی‌گرداند ----
if (tableHasColumn('cheques', 'status') && tableHasColumn('debts', 'cheque_id')) {
    $pdo->prepare("INSERT INTO cheques (user_id, direction, counterparty_name, amount, due_date) VALUES (:u, 'received', 'چکِ بازرسیِ دوم', 7000000, :d)")
        ->execute(['u' => $u, 'd' => $today]);
    $cid = (int)$pdo->lastInsertId();
    $api('toggle_cheque_settled.php', ['cheque_id' => $cid, 'status' => 'bounced']);
    $r = $api('delete_cheque.php', ['cheque_id' => $cid]);
    T::ok(!empty($r['undo_token']), 'چکِ برگشتی حذف شد (با امکانِ لغو)');
    $r2 = $api('undo_delete.php', ['undo_token' => (string)($r['undo_token'] ?? '')]);
    T::ok(!empty($r2['success']), 'لغو شد', json_encode($r2, JSON_UNESCAPED_UNICODE));
    T::same(1, (int)$one('SELECT COUNT(*) FROM debts WHERE cheque_id = :c AND user_id = :u', ['c' => $cid, 'u' => $u]), '⛔ طلبِ چک دوباره به همان چک وصل است');
    $api('toggle_cheque_settled.php', ['cheque_id' => $cid, 'status' => 'pending']);
    $api('toggle_cheque_settled.php', ['cheque_id' => $cid, 'status' => 'bounced']);
    T::same(1, (int)$one("SELECT COUNT(*) FROM debts WHERE user_id = :u AND counterparty_name = 'چکِ بازرسیِ دوم'", ['u' => $u]),
        '⛔ «برگشتی» زدنِ دوباره طلبِ دوم نساخت');
}

// ---- ۸. «لغو» موجودیِ پس‌انداز را منفی نمی‌کند ----
if (tableExists('savings_goals')) {
    $pdo->prepare("INSERT INTO savings_goals (user_id, title, target_amount) VALUES (:u, 'هدفِ بازرسیِ دوم', 10000000)")->execute(['u' => $u]);
    $gid = (int)$pdo->lastInsertId();
    $api('add_savings_entry.php', ['goal_id' => $gid, 'direction' => 'deposit', 'amount' => '5000000', 'entry_date' => $today]);
    $api('add_savings_entry.php', ['goal_id' => $gid, 'direction' => 'withdraw', 'amount' => '5000000', 'entry_date' => $today]);
    $wd = (int)$one('SELECT id FROM savings_entries WHERE goal_id = :g AND amount < 0', ['g' => $gid]);
    $dp = (int)$one('SELECT id FROM savings_entries WHERE goal_id = :g AND amount > 0', ['g' => $gid]);
    $r = $api('delete_savings_entry.php', ['entry_id' => $wd]);
    $api('delete_savings_entry.php', ['entry_id' => $dp]);
    $r2 = $api('undo_delete.php', ['undo_token' => (string)($r['undo_token'] ?? '')]);
    T::ok(empty($r2['success']), '⛔ «لغو»ِ حذفِ برداشت بعد از حذفِ واریز رد شد', json_encode($r2, JSON_UNESCAPED_UNICODE));
    T::same(0, (int)$one('SELECT COALESCE(SUM(amount), 0) FROM savings_entries WHERE goal_id = :g', ['g' => $gid]), 'موجودیِ هدف صفر ماند (نه −۵ میلیون)');
}

// ---- ۹. سودِ معامله و بدهیِ امانی فقط از صفحه‌ی معاملات ----
if (tableHasColumn('trade_sales', 'profit_tx_id')) {
    $r = $api('save_trade.php', ['title' => 'معامله‌ی بازرسیِ دوم', 'qty' => '2', 'buy_total' => '1000000', 'buy_date' => $today, 'buy_wallet_id' => $wallet]);
    $tid = (int)$one("SELECT id FROM trades WHERE user_id = :u AND title = 'معامله‌ی بازرسیِ دوم'", ['u' => $u]);
    $api('sell_trade.php', ['trade_id' => $tid, 'qty' => '1', 'sale_total' => '800000', 'sale_date' => $today, 'wallet_id' => $wallet]);
    $ptx = (int)$one('SELECT profit_tx_id FROM trade_sales WHERE trade_id = :t', ['t' => $tid]);
    T::ok($ptx > 0, 'فروش تراکنشِ سود ساخت');
    $r = $api('delete_transaction.php', ['transaction_id' => $ptx]);
    T::ok(empty($r['success']), '⛔ تراکنشِ سودِ معامله از فرمِ تراکنش حذف نمی‌شود', json_encode($r, JSON_UNESCAPED_UNICODE));
    $r = $api('update_transaction.php', ['transaction_id' => $ptx, 'id' => $ptx, 'type' => 'expense', 'amount' => '9999999', 'title' => 'x', 'transaction_date' => $today]);
    T::ok(empty($r['success']), '⛔ و ویرایش هم نمی‌شود');
    if (tableHasColumn('debts', 'trade_id')) {
        $api('save_trade.php', ['title' => 'خریدِ امانیِ بازرسی', 'qty' => '1', 'buy_total' => '3000000', 'buy_date' => $today,
            'pay_mode' => 'credit', 'counterparty_name' => 'فروشنده‌ی امانی']);
        $cd = (int)$one('SELECT id FROM debts WHERE user_id = :u AND trade_id IS NOT NULL ORDER BY id DESC LIMIT 1', ['u' => $u]);
        if ($cd > 0) {
            $r = $api('delete_debt.php', ['debt_id' => $cd]);
            T::ok(empty($r['success']), '⛔ بدهیِ ساخته‌شده از معامله از صفحه‌ی بدهی حذف نمی‌شود');
        } else {
            T::blocked('بدهیِ امانی', 'خریدِ امانی بدهی نساخت');
        }
    }
}

// =================================================================
T::group('بازرسیِ دوم — فروشگاه');
if (!tableExists('biz_invoices') || !tableHasColumn('users', 'account_type')) { T::blocked('بازرسیِ فروشگاه', 'جدول‌های فروشگاه نیست'); exit(T::report()); }
require_once __DIR__ . '/../includes/biz.php';
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_docs.php';
require_once __DIR__ . '/../includes/biz_io.php';
require_once __DIR__ . '/../includes/biz_reports.php';
$s = $make('s');
Biz::setType($s, 'business');
$acc = (int)BizCash::list($s)[0]['id'];

// ---- عددِ فایلِ ورود ----
T::same(12500.0, BizImport::num('۱۲.۵۰۰', true), '⛔ قیمتِ «۱۲.۵۰۰» در فایل ۱۲٬۵۰۰ تومان است (نه ۱۳)');
T::same(2.5, BizImport::num('۲/۵'), '⛔ تعدادِ «۲/۵» دو و نیم (نه ۲۵)');
T::same(1250000.0, BizImport::num('1.25E+06', true), 'نمادِ علمیِ اکسل');
T::same(12.5, BizImport::num('12.5'), 'تعدادِ اعشاری همان');

// ---- طرف‌حساب و حسابِ غیرفعالِ مانده‌دار ----
$cust = (int)BizParties::save($s, ['name' => 'مشتریِ غیرفعالِ بازرسی', 'kind' => 'customer'])['id'];
$pdo->prepare('UPDATE biz_parties SET is_active = 0, opening_balance = 1000 WHERE id = :i')->execute(['i' => $cust]);
$sum = BizParties::summary($s);
T::same(1000, (int)$sum['receivable'], '⛔ طلب از طرف‌حسابِ غیرفعالِ مانده‌دار شمرده می‌شود');
$r = BizParties::delete($s, $cust);
T::ok(!$r['ok'], '⛔ طرف‌حسابِ دارای مانده‌ی اول دوره حذف نمی‌شود', $r['message']);
$bank = (int)BizCash::save($s, ['name' => 'بانکِ غیرفعالِ بازرسی', 'kind' => 'bank', 'opening_balance' => '500'])['id'];
$pdo->prepare('UPDATE biz_accounts SET is_active = 0 WHERE id = :i')->execute(['i' => $bank]);
$all = BizCash::list($s);
T::same(array_sum(array_map(fn($a) => BizCash::isCheque($a) ? 0 : (int)$a['balance'], $all)), BizCash::total($all), '⛔ جمعِ نقد حسابِ غیرفعالِ مانده‌دار را هم دارد');

// ---- گزارشِ به تفکیکِ طرف‌حساب ----
$c2 = (int)BizParties::save($s, ['name' => 'مشتریِ گزارشِ بازرسی', 'kind' => 'customer'])['id'];
$sv = BizInvoices::saveDraft($s, 'sale', ['party_id' => $c2, 'lines' => [['item' => 'خدمتِ بازرسی', 'qty' => '1', 'price' => '1000']]]);
BizInvoices::issue($s, (int)$sv['id'], ['full' => true, 'account_id' => $acc]);
$pdo->prepare('UPDATE biz_parties SET is_active = 0 WHERE id = :i')->execute(['i' => $c2]);
$bp = BizReports::byParty($s, $today, $today);
T::same(1000, (int)($bp['sums']['sale'] ?? -1), '⛔ «به تفکیکِ طرف‌حساب» فروشِ طرف‌حسابِ غیرفعال‌شده را دارد');

// ---- حذفِ کالا در دوره‌ی بسته ----
$pr = (int)BizProducts::save($s, ['type' => 'goods', 'name' => 'کالای قفلِ بازرسی', 'unit' => 'عدد', 'buy_price' => '100', 'sell_price' => '150'])['id'];
BizStock::setOpening($s, $pr, 10, 1000);
$pdo->prepare('UPDATE biz_stock_moves SET move_date = :d WHERE product_id = :p')->execute(['d' => date('Y-m-d', strtotime($today . ' -40 days')), 'p' => $pr]);
$lk = Biz::saveLock($s, date('Y-m-d', strtotime($today . ' -10 days')));
T::ok($lk['ok'] ?? false, 'دوره تا ده روز پیش بسته شد', $lk['message'] ?? '');
$r = BizProducts::delete($s, $pr);
T::ok(!$r['ok'], '⛔ کالای دارای موجودیِ اول دوره در دوره‌ی بسته حذف نمی‌شود', $r['message']);
$r = BizProducts::deleteUnused($s, [$pr], 'all');
T::same(0, (int)($r['deleted'] ?? -1), '⛔ و پاک‌سازیِ دسته‌جمعی هم آن را نمی‌برد');

// ---- سرفصل‌های پیش‌فرض ----
if (Biz::accReady()) {
    require_once __DIR__ . '/../includes/biz_acc.php';
    $s2 = $make('s2');
    Biz::setType($s2, 'business');
    BizExpCats::sysId($pdo, $s2, array_key_first(BizExpCats::SYSTEM));
    T::ok(count(BizExpCats::list($s2, 'expense')) > 2, '⛔ اولین کار «سرفصلِ سیستمی» بود و پیش‌فرض‌ها هم ساخته شدند');
}

exit(T::report());
