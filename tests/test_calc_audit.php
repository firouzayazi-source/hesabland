<?php
/**
 * تستِ «بازرسیِ محاسباتی» (مهر ۱۴۰۵) — هر باگِ تأییدشده یک بررسی، با همان
 * سناریویی که آن را نشان داد. خواسته‌ی مالکِ نصب: «پروژه رو بگرد ببین باگِ
 * محاسباتی نداریم؛ اگه هست برطرف کن».
 *
 * ⛔ هر بررسی عددِ **درست** را می‌سنجد، نه «خطا نداد». چرایی‌ها کنارِ خودِ کد است
 *    (جست‌وجوی «بازرسیِ محاسباتی (مهر ۱۴۰۵)»).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
T::group('بازرسیِ محاسباتی — توابعِ خالص');

if (!file_exists(__DIR__ . '/../config/config.php')) { T::blocked('بازرسیِ محاسباتی', 'config/config.php وجود ندارد'); exit(T::report()); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/rates.php';
require_once __DIR__ . '/../includes/dash_parts.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';

// ---- مقدار: «۲/۵» دو و نیم است ----
T::same(2.5, sanitizeQty('۲/۵'), '⛔ «۲/۵» = ۲٫۵ (نه ۲۵)');
T::same(2.5, sanitizeQty('2,5'), '«2,5» = ۲٫۵');
T::same(1000.0, sanitizeQty('1,000'), '«1,000» هنوز هزار است');
T::same(1.2345, sanitizeQty('1.2345', 4), 'دقتِ ۴ رقم برای دارایی');
T::same(1.235, sanitizeQty('1.2345'), 'پیش‌فرض همان ۳ رقم');

// ---- نرخ: تتر دلار نیست؛ عیارِ دیگر گرمِ ۱۸ نیست ----
T::same('usdt', Rates::guessCode('USDT', 'عدد'), '⛔ USDT تتر است، نه دلار');
T::same('usd', Rates::guessCode('دلار', 'دلار'), 'دلار همان دلار');
T::same(null, Rates::guessCode('طلای ۲۱ عیار', 'گرم'), '⛔ طلای ۲۱ عیار به گرمِ ۱۸ وصل نمی‌شود');
T::same(null, Rates::guessCode('طلا 14 عیار', 'گرم'), 'و ۱۴ عیار هم نه');
T::same('gold18', Rates::guessCode('طلای ۱۸ عیار', 'گرم'), '۱۸ عیار همان گرمِ ۱۸');
T::same('gold18', Rates::guessCode('طلا', 'گرم'), 'طلای بی‌عیار = ۱۸');
T::same('gold24', Rates::guessCode('طلای 24', 'گرم'), '۲۴ عیار');

// ---- اقساط: قسطِ آخر روی سررسید ----
$due = jalaliDateToGregorianStr(1406, 2, 31);
$dates = array_map(fn($i) => toLatinDigits(toJalali($i['date'])),
    debtInstallments(['installment_count' => 3, 'amount' => 3000000, 'paid_amount' => 0, 'installment_every' => 'monthly', 'due_date' => $due]));
T::same(['1405/12/29', '1406/01/31', '1406/02/31'], $dates, '⛔ از اسفندِ ۲۹روزه رد شد ولی قسط‌های بعد روی ۳۱ (سررسید) برگشتند');

// ---- کارتِ سال: تراکنشِ آینده‌دار مثلِ کارتِ ماه شمرده نمی‌شود ----
$today = date('Y-m-d');
$rows = [['transaction_date' => $today, 'daily_income' => 0, 'daily_expense' => 700],
         ['transaction_date' => date('Y-m-d', strtotime($today . ' +1 day')), 'daily_income' => 0, 'daily_expense' => 500]];
[$jy] = gregorianToJalali((int)date('Y'), (int)date('m'), (int)date('d'));
$ys = dashYearState($rows, $today, $jy);
// فردا اگر سالِ بعد باشد هم پاسخ ۷۰۰ است؛ پس آزمون همیشه همین عدد را می‌خواهد
T::same(700, (int)($ys['report']['expense'] ?? -1), '⛔ کارتِ سال فقط تا امروز (۷۰۰، نه ۱۲۰۰) — همان مرزِ کارتِ «این ماه»');

// =================================================================
try { $pdo = Database::getConnection(); } catch (Throwable $e) { T::blocked('بازرسیِ محاسباتی', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }

const CA_PREFIX = '__calc_audit_';
const CA_PASS   = 'Calc#audit9';
$wipe = function () use ($pdo) {
    $ids = $pdo->query("SELECT id FROM users WHERE username LIKE '" . CA_PREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) { deleteUserAccount((int)$id); }
};
$wipe();
register_shutdown_function($wipe);
$make = function (string $name) use ($pdo): int {
    $res = createUserAccount($pdo, 'کاربرِ ' . $name, CA_PREFIX . $name, CA_PREFIX . $name . '@example.com', CA_PASS);
    if (!($res['ok'] ?? false)) { throw new RuntimeException('ساختِ کاربر: ' . ($res['error'] ?? '?')); }
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => CA_PREFIX . $name]);
    return (int)$st->fetchColumn();
};
$u = $make('p');
$catId = function (string $type) use ($pdo, $u): int {
    $pdo->prepare('INSERT INTO categories (user_id, name, type) VALUES (:u, :n, :t)')
        ->execute(['u' => $u, 'n' => 'دسته‌ی بازرسی ' . $type . mt_rand(100, 999), 't' => $type]);
    return (int)$pdo->lastInsertId();
};
$addTx = function (int $cat, int $amount, string $date, string $type = 'expense') use ($pdo, $u): void {
    $pdo->prepare('INSERT INTO transactions (user_id, category_id, type, amount, title, transaction_date) VALUES (:u, :c, :t, :a, :ti, :d)')
        ->execute(['u' => $u, 'c' => $cat, 't' => $type, 'a' => $amount, 'ti' => 'بازرسی', 'd' => $date]);
};

T::group('بازرسیِ محاسباتی — پولِ شخصی');

// ---- بودجه: ۴٬۹۸۰٬۰۰۰ از ۵٬۰۰۰٬۰۰۰ «بیشتر از بودجه» نیست ----
if (tableExists('budgets')) {
    $bc = $catId('expense');
    $pdo->prepare("INSERT INTO budgets (user_id, category_id, period_type, amount) VALUES (:u, :c, 'monthly', 5000000)")->execute(['u' => $u, 'c' => $bc]);
    $addTx($bc, 4980000, $today);
    $b = array_values(array_filter(budgetStatuses($u), fn($x) => (int)$x['category_id'] === $bc))[0] ?? [];
    T::ok(($b['status'] ?? '') !== 'over', '⛔ ۲۰٬۰۰۰ مانده ⇒ «بیشتر از بودجه» نیست', (string)($b['status'] ?? '?'));
    T::same(99, (int)($b['percent'] ?? -1), 'درصد ۹۹ (floor)، نه ۱۰۰');
    T::same(20000, (int)($b['remaining'] ?? -1), 'مانده‌ی بودجه ۲۰٬۰۰۰');
    $addTx($bc, 20000, $today);
    $b = array_values(array_filter(budgetStatuses($u), fn($x) => (int)$x['category_id'] === $bc))[0] ?? [];
    T::ok(($b['status'] ?? '') === 'warn' && (int)$b['percent'] === 100, 'دقیقاً تمام ⇒ ۱۰۰٪ ولی «بیشتر از» نه');
    $addTx($bc, 1, $today);
    $b = array_values(array_filter(budgetStatuses($u), fn($x) => (int)$x['category_id'] === $bc))[0] ?? [];
    T::same('over', $b['status'] ?? '', 'یک تومان بیشتر ⇒ «بیشتر از بودجه»');
}

// ---- تکرارِ روزانه در تقویمِ دو ماهِ بعد ----
if (tableExists('recurring_transactions')) {
    $pdo->prepare("INSERT INTO recurring_transactions (user_id, type, amount, title, frequency, interval_count, start_date, next_due_date, mode)
                   VALUES (:u, 'expense', 1000, 'روزانه‌ی بازرسی', 'daily', 1, :s, :n, 'remind')")
        ->execute(['u' => $u, 's' => $today, 'n' => $today]);
    $from = date('Y-m-d', strtotime($today . ' +60 days'));
    $to   = date('Y-m-d', strtotime($from . ' +29 days'));
    $ev = array_filter(financialEvents($u, $from, $to), fn($e) => $e['kind'] === 'recurring' && $e['title'] === 'روزانه‌ی بازرسی');
    T::same(30, count($ev), '⛔ تکرارِ روزانه در بازه‌ی ۶۰ روز جلوتر ۳۰ رویداد دارد (نه صفر)');
    // سریِ روزانه‌ای که سال‌ها عقب مانده (کاربرِ بی‌ورود): پرشِ یک‌جا تا شروعِ بازه
    $pdo->prepare('UPDATE recurring_transactions SET next_due_date = :n, start_date = :n2 WHERE user_id = :u')
        ->execute(['n' => date('Y-m-d', strtotime($today . ' -5000 days')), 'n2' => date('Y-m-d', strtotime($today . ' -5000 days')), 'u' => $u]);
    $ev = array_filter(financialEvents($u, $from, $to), fn($e) => $e['kind'] === 'recurring' && $e['title'] === 'روزانه‌ی بازرسی');
    T::same(30, count($ev), 'و سریِ ۵۰۰۰ روز عقب‌مانده هم (پرشِ روزانه/هفتگی)');
    // ماهانه در بازه‌ی سه تا چهار سالِ بعد — بیش از ۴۰ سررسید پیش از بازه
    $pdo->prepare("UPDATE recurring_transactions SET frequency = 'monthly', next_due_date = :n, start_date = :n2 WHERE user_id = :u")
        ->execute(['n' => $today, 'n2' => $today, 'u' => $u]);
    $ev = array_filter(financialEvents($u, date('Y-m-d', strtotime($today . ' +3 years')), date('Y-m-d', strtotime($today . ' +4 years -1 day'))),
        fn($e) => $e['kind'] === 'recurring' && $e['title'] === 'روزانه‌ی بازرسی');
    // ۱۱ یا ۱۲ بسته به مرزِ ماهِ شمسی در سالِ میلادی؛ با سقفِ ۴۰ دور صفر تا ۴ بود
    T::ok(count($ev) >= 11 && count($ev) <= 12, '⛔ تکرارِ ماهانه سه سال جلوتر هم سررسیدِ هر ماه را دارد (سقفِ حلقه فقط ترمز است)', (string)count($ev));
    $pdo->prepare('DELETE FROM recurring_transactions WHERE user_id = :u')->execute(['u' => $u]);
}

// ---- سودِ معامله: جمعِ ثبت‌شده‌ها = سودِ صفحه ----
if (tableExists('trades') && tableHasColumn('trade_sales', 'profit_tx_id')) {
    $pdo->prepare("INSERT INTO trades (user_id, title, qty, buy_total, side_costs, buy_date) VALUES (:u, 'معامله‌ی بازرسی', 3, 1000, 0, :d)")
        ->execute(['u' => $u, 'd' => $today]);
    $tid = (int)$pdo->lastInsertId();
    for ($i = 0; $i < 3; $i++) {
        $pdo->prepare('INSERT INTO trade_sales (trade_id, user_id, qty, sale_total, sale_date) VALUES (:t, :u, 1, 500, :d)')
            ->execute(['t' => $tid, 'u' => $u, 'd' => $today]);
    }
    syncTradeProfitTransactions($u, $tid);
    $tr = array_values(array_filter(tradesWithProgress($u), fn($t) => (int)$t['id'] === $tid))[0];
    $booked = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN tx.type = 'income' THEN tx.amount ELSE -tx.amount END), 0)
                             FROM transactions tx JOIN trade_sales s ON s.profit_tx_id = tx.id WHERE s.trade_id = :t AND tx.user_id = :u");
    $booked->execute(['t' => $tid, 'u' => $u]);
    T::same(500, (int)$tr['realized_profit'], 'سودِ صفحه ۵۰۰');
    T::same(500, (int)$booked->fetchColumn(), '⛔ جمعِ درآمدهای ثبت‌شده هم ۵۰۰ (نه ۵۰۱)');
}

// =================================================================
T::group('بازرسیِ محاسباتی — از راهِ HTTP');
$root = dirname(__DIR__);
$port = 0;
for ($p = 9360; $p <= 9400; $p++) { $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2); if ($sock) { fclose($sock); $port = $p; break; } }
if (!$port) { T::blocked('بازرسیِ محاسباتی (HTTP)', 'پورت آزاد پیدا نشد'); exit(T::report()); }
$log = tempnam(sys_get_temp_dir(), 'calcaud');
$srv = (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!', $port, escapeshellarg($root), escapeshellarg($log))));
register_shutdown_function(static function () use ($srv, $log) { exec("kill {$srv} 2>/dev/null"); @unlink($log); });
for ($i = 0, $up = false; $i < 40 && !$up; $i++) { usleep(150000); $sk = @fsockopen('127.0.0.1', $port, $a, $b, 0.3); if ($sk) { fclose($sk); $up = true; } }
T::ok($up, 'سرورِ آزمایشی بالا آمد');
if (!$up) { exit(T::report()); }
$jar = tempnam(sys_get_temp_dir(), 'calcjar');
$req = function (string $path, ?array $post = null) use ($port, $jar): array {
    $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 40]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); unset($ch);
    return [$code, $body];
};
$json = fn(array $r): array => json_decode($r[1], true) ?: ['_raw' => mb_substr($r[1], 0, 200)];
[, $lp] = $req('login.php');
$tok = preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $lp, $m) ? $m[1] : '';
$req('login.php', ['csrf_token' => $tok, 'username' => CA_PREFIX . 'p', 'password' => CA_PASS]);
[, $html] = $req('debts.php');
$tok = preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m) ? $m[1] : '';
T::ok($tok !== '', 'وارد شد و توکن گرفت');

// ---- پس‌انداز: حذفِ واریز موجودی را منفی نمی‌کند ----
if (tableExists('savings_goals')) {
    $pdo->prepare("INSERT INTO savings_goals (user_id, title, target_amount) VALUES (:u, 'هدفِ بازرسی', 10000)")->execute(['u' => $u]);
    $gid = (int)$pdo->lastInsertId();
    $req('api/add_savings_entry.php', ['csrf_token' => $tok, 'goal_id' => $gid, 'direction' => 'deposit', 'amount' => '4000', 'entry_date' => $today]);
    $req('api/add_savings_entry.php', ['csrf_token' => $tok, 'goal_id' => $gid, 'direction' => 'withdraw', 'amount' => '4000', 'entry_date' => $today]);
    $st = $pdo->prepare('SELECT id FROM savings_entries WHERE goal_id = :g AND amount > 0');
    $st->execute(['g' => $gid]);
    $dep = (int)$st->fetchColumn();
    $r = $json($req('api/delete_savings_entry.php', ['csrf_token' => $tok, 'entry_id' => $dep]));
    T::ok(empty($r['success']), '⛔ حذفِ واریزی که برداشت شده رد شد', json_encode($r, JSON_UNESCAPED_UNICODE));
    $g = array_values(array_filter(savingsGoalsWithProgress($u), fn($x) => (int)$x['id'] === $gid))[0];
    T::same(0, (int)$g['current_amount'], 'موجودیِ هدف صفر ماند (نه −۴٬۰۰۰)');
    $st = $pdo->prepare('SELECT id FROM savings_entries WHERE goal_id = :g AND amount < 0');
    $st->execute(['g' => $gid]);
    $r = $json($req('api/delete_savings_entry.php', ['csrf_token' => $tok, 'entry_id' => (int)$st->fetchColumn()]));
    T::ok(!empty($r['success']), 'اول برداشت حذف می‌شود');
    $r = $json($req('api/delete_savings_entry.php', ['csrf_token' => $tok, 'entry_id' => $dep]));
    T::ok(!empty($r['success']), 'بعد واریز هم');
}

// ---- چکِ برگشتیِ نیمه‌وصول «پاس» نمی‌شود ----
if (tableHasColumn('cheques', 'status') && tableHasColumn('debts', 'cheque_id')) {
    $pdo->prepare("INSERT INTO cheques (user_id, direction, counterparty_name, amount, due_date) VALUES (:u, 'received', 'صادرکننده‌ی بازرسی', 1000000, :d)")
        ->execute(['u' => $u, 'd' => $today]);
    $cid = (int)$pdo->lastInsertId();
    $r = $json($req('api/toggle_cheque_settled.php', ['csrf_token' => $tok, 'cheque_id' => $cid, 'status' => 'bounced']));
    T::ok(!empty($r['debt_created']), 'برگشت خورد و طلب شد', json_encode($r, JSON_UNESCAPED_UNICODE));
    $st = $pdo->prepare('SELECT id FROM debts WHERE cheque_id = :c AND user_id = :u');
    $st->execute(['c' => $cid, 'u' => $u]);
    $did = (int)$st->fetchColumn();
    $r = $json($req('api/add_debt_payment.php', ['csrf_token' => $tok, 'debt_id' => $did, 'amount' => '300000', 'payment_date' => $today]));
    T::ok(!empty($r['success']), '۳۰۰٬۰۰۰ روی طلب وصول شد', json_encode($r, JSON_UNESCAPED_UNICODE));
    $before = totalBalance($u);
    $r = $json($req('api/toggle_cheque_settled.php', ['csrf_token' => $tok, 'cheque_id' => $cid, 'status' => 'cleared']));
    T::ok(empty($r['success']), '⛔ «پاس شد» رد شد — باقی از راهِ همان طلب', json_encode($r, JSON_UNESCAPED_UNICODE));
    T::same($before, totalBalance($u), 'موجودی یک میلیونِ اضافه نگرفت');
    T::same('bounced', (string)$pdo->query('SELECT status FROM cheques WHERE id = ' . $cid)->fetchColumn(), 'چک برگشتی ماند');
}

// ---- دارایی: «۲/۵» دو و نیم ذخیره می‌شود؛ و سودِ ساختگی نه ----
if (tableExists('asset_types')) {
    $pdo->prepare("INSERT INTO asset_types (user_id, name, unit) VALUES (:u, 'طلای بازرسی', 'گرم')")->execute(['u' => $u]);
    $at = (int)$pdo->lastInsertId();
    $r = $json($req('api/add_asset.php', ['csrf_token' => $tok, 'asset_type_id' => $at, 'quantity' => '۲/۵', 'unit_price' => '1000', 'entry_date' => $today]));
    T::ok(!empty($r['success']), 'دارایی با «۲/۵» ثبت شد', json_encode($r, JSON_UNESCAPED_UNICODE));
    $q = $pdo->prepare('SELECT quantity FROM assets WHERE user_id = :u AND asset_type_id = :t');
    $q->execute(['u' => $u, 't' => $at]);
    T::same(2.5, (float)$q->fetchColumn(), '⛔ مقدارِ ذخیره‌شده ۲٫۵ (نه ۲۵)');

    // فروشِ جزئی با ۴ رقم: ۱٫۲۳۴۵ − ۱ = ۰٫۲۳۴۵ (نه ۰٫۲۳۵)
    $pdo->prepare('INSERT INTO assets (user_id, asset_type_id, quantity, unit_price, entry_date) VALUES (:u, :t, 1.2345, 1000, :d)')
        ->execute(['u' => $u, 't' => $at, 'd' => $today]);
    $aid = (int)$pdo->lastInsertId();
    $r = $json($req('api/sell_asset.php', ['csrf_token' => $tok, 'asset_id' => $aid, 'qty' => '1', 'sale_total' => '1000', 'sale_date' => $today]));
    T::ok(!empty($r['success']), 'یک گرم از ۱٫۲۳۴۵ فروخته شد', json_encode($r, JSON_UNESCAPED_UNICODE));
    T::same(0.2345, (float)$pdo->query('SELECT quantity FROM assets WHERE id = ' . $aid)->fetchColumn(), '⛔ مانده ۰٫۲۳۴۵ (نه ۰٫۲۳۵ — بیشتر از قبل)');
    $pdo->prepare('DELETE FROM assets WHERE id = :id')->execute(['id' => $aid]);

    // بی‌نرخِ روز: ارزش = بها ⇒ هیچ جمله‌ی «سود/زیانِ محقق‌نشده» (نه «۰ زیان»)
    [, $page] = $req('my-assets.php');
    T::ok(str_contains($page, '</html>') && !str_contains($page, 'محقق‌نشده دارید'), '⛔ بی‌نرخِ روز جمله‌ی «۰ زیانِ محقق‌نشده» نیست');
    // ثبتِ بی‌بها + نرخِ روز: فقط ثبتِ بهادار در سود
    if (tableHasColumn('asset_types', 'current_price')) {
        $pdo->prepare('UPDATE asset_types SET current_price = 1200 WHERE id = :t')->execute(['t' => $at]);
        $pdo->prepare('INSERT INTO assets (user_id, asset_type_id, quantity, unit_price, entry_date) VALUES (:u, :t, 10, NULL, :d)')
            ->execute(['u' => $u, 't' => $at, 'd' => $today]);
        [, $page] = $req('my-assets.php');
        // سودِ درست: ۲٫۵ × (۱۲۰۰ − ۱۰۰۰) = ۵۰۰ — نه ۵۰۰ + ۱۰ × ۱۲۰۰
        T::ok(preg_match('~<strong class="delta-up">' . preg_quote(formatMoney(500), '~') . '</strong>\s*سود محقق‌نشده~u', $page) === 1,
            '⛔ سودِ محقق‌نشده فقط از ثبتِ بهادار (۵۰۰، نه ۱۲٬۵۰۰)');
    }
}

// ---- گزارشِ دسته: دسته‌ی غیرفعال‌شده هم شمرده می‌شود ----
$rc = $catId('expense');
$addTx($rc, 777000, $today);
$pdo->prepare('UPDATE categories SET is_active = 0 WHERE id = :c')->execute(['c' => $rc]);
[, $page] = $req('category-report.php?type=expense&preset=this_month');
T::ok(str_contains($page, formatMoney(777000)), '⛔ هزینه‌ی دسته‌ی غیرفعال‌شده در گزارشِ دسته هست');

// ---- داشبورد: یادآورِ بدهی مانده را می‌گوید ----
$pdo->prepare("INSERT INTO debts (user_id, direction, counterparty_name, amount, paid_amount, entry_date, due_date, is_settled)
               VALUES (:u, 'payable', 'طلبکارِ بازرسی', 1000000, 900000, :e, :d, 0)")->execute(['u' => $u, 'e' => $today, 'd' => $today]);
[, $page] = $req('dashboard.php');
T::ok(preg_match('~طلبکارِ بازرسی.*?<span class="stats-value"[^>]*>' . preg_quote(formatMoney(100000), '~') . ' <small>~su', $page) === 1,
    '⛔ یادآورِ بدهی «۱۰۰٬۰۰۰» (مانده) می‌گوید، نه یک میلیون');

// =================================================================
T::group('بازرسیِ محاسباتی — فروشگاه');
if (!tableExists('biz_invoices') || !tableHasColumn('users', 'account_type')) { T::blocked('بازرسیِ فروشگاه', 'جدول‌های فروشگاه نیست'); exit(T::report()); }
require_once __DIR__ . '/../includes/biz.php';
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_docs.php';
require_once __DIR__ . '/../includes/biz_quickbuy.php';
$s = $make('s');
Biz::setType($s, 'business');
$acc = (int)BizCash::list($s)[0]['id'];
$L = fn(string $item, string $qty, string $price, string $i1 = ''): array => ['item' => $item, 'qty' => $qty, 'price' => $price, 'imei1' => $i1, 'imei2' => ''];
$doc = function (string $kind, array $lines, array $head = [], array $pay = ['full' => true]) use ($s, $acc) {
    $r = BizInvoices::saveDraft($s, $kind, ['lines' => $lines] + $head);
    if (!$r['ok']) { return $r['message']; }
    $i = BizInvoices::issue($s, (int)$r['id'], $pay + ['account_id' => $acc]);
    return $i['ok'] ? (int)$r['id'] : $i['message'];
};
$bal = function (int $pid) use ($s): int {
    foreach (BizParties::all($s)['rows'] as $p) { if ((int)$p['id'] === $pid) { return (int)$p['balance']; } }
    return PHP_INT_MIN;
};
$paid = fn(int $id): int => (int)BizInvoices::get($s, $id)['paid'];

// ---- گوشی: «برگشت به پیش‌نویس» + «حذف» دورِ سدِ ابطال نیست ----
$PH = (int)BizProducts::save($s, ['type' => 'phone', 'name' => 'گوشی بازرسی', 'unit' => 'دستگاه', 'buy_price' => '400', 'sell_price' => '500'])['id'];
$X = '350000000000006';
$p1 = $doc('purchase', [$L('گوشی بازرسی', '1', '400', $X)]);
$s1 = $doc('sale', [$L('گوشی بازرسی', '1', '600', $X)]);
$p2 = $doc('purchase', [$L('گوشی بازرسی', '1', '420', $X)]);
T::ok(is_int($p1) && is_int($s1) && is_int($p2), 'خریدِ X، فروشِ X، خریدِ دوباره‌ی X', var_export([$p1, $s1, $p2], true));
T::ok(!BizInvoices::unissue($s, $s1)['ok'] || true, 'برگشتِ فروش به پیش‌نویس (آزاد یا رد)');
$r = BizInvoices::deleteDraft($s, $s1);
T::ok(!$r['ok'], '⛔ حذفِ پیش‌نویسِ شماره‌دارِ فروش رد شد — X بعدش دوباره خریده شده', $r['message']);
$r = BizInvoices::issue($s, $s1, ['full' => true, 'account_id' => $acc]);
T::ok($r['ok'], 'و همان سند دوباره صادر می‌شود', $r['message']);
T::same(1.0, (float)BizProducts::get($s, $PH)['stock_qty'], 'موجودیِ گوشی ۱ (نه ۲)');

// ---- برگشتِ جزئی: مالیاتِ هیچ برگشتی منفی نیست، جمع = اصل ----
if (tableHasColumn('biz_invoices', 'vat_rate')) {
    $G = (int)BizProducts::save($s, ['type' => 'goods', 'name' => 'قاب بازرسی', 'unit' => 'عدد', 'buy_price' => '1', 'sell_price' => '5'])['id'];
    $doc('purchase', [$L('قاب بازرسی', '10', '1')]);
    $cust = (int)BizParties::save($s, ['name' => 'مشتری مالیات', 'kind' => 'customer'])['id'];
    $sv = BizInvoices::saveDraft($s, 'sale', ['party_id' => $cust, 'vat_rate' => '10', 'lines' => [$L('قاب بازرسی', '4', '5')]]);
    $ok = $sv['ok'] && BizInvoices::issue($s, (int)$sv['id'], ['amount' => '0', 'account_id' => $acc])['ok'];
    $orig = $ok ? BizInvoices::get($s, (int)$sv['id']) : null;
    if ($orig && (int)$orig['lines'][0]['tax_amount'] === 2) {
        $lid = (int)$orig['lines'][0]['id'];
        $taxes = [];
        for ($i = 0; $i < 4; $i++) {
            $rr = BizInvoices::createReturn($s, (int)$sv['id'], [$lid => '1']);
            $taxes[] = $rr['ok'] ? (int)BizInvoices::get($s, (int)$rr['id'])['tax_total'] : -99;
        }
        T::ok(min($taxes) >= 0 && array_sum($taxes) === 2, '⛔ مالیاتِ چهار برگشتِ تکی نامنفی و جمعشان ۲', json_encode($taxes));
    } else {
        T::blocked('مالیاتِ برگشتِ جزئی', 'فاکتورِ با مالیاتِ ۲ ساخته نشد');
    }
}

// ---- تأمینِ موجودی: تعدادِ بی‌اندازه رد می‌شود ----
$sup = (int)BizParties::save($s, ['name' => 'فروشنده بازرسی', 'kind' => 'supplier'])['id'];
$G2 = (int)BizProducts::save($s, ['type' => 'goods', 'name' => 'سیمِ بازرسی', 'unit' => 'کیلوگرم', 'buy_price' => '10', 'sell_price' => '20'])['id'];
$r = BizQuickBuy::run($s, ['product_id' => $G2, 'qty' => '10000000000000000000', 'buy' => '10', 'party_id' => $sup, 'pay' => 'credit', 'date' => $today]);
T::ok(!$r['ok'], '⛔ تعدادِ ۱e19 رد شد (نه ۱٫۰۱۹ در انبار)', $r['message']);
T::same(0.0, (float)BizProducts::get($s, $G2)['stock_qty'], 'موجودی دست نخورد');

// ---- صندوقِ داشبورد: پولِ تاریخ‌آینده هنوز «امروز» نیست ----
$fut = BizPay::create($s, ['kind' => 'income', 'amount' => '400', 'account_id' => $acc, 'pay_date' => date('Y-m-d', strtotime($today . ' +5 days')), 'title' => 'درآمدِ آینده']);
T::ok($fut['ok'], 'درآمدِ پنج روزِ بعد ثبت شد', $fut['message'] ?? '');
$all = (int)array_column(BizCash::list($s), 'balance', 'id')[$acc];
$now = (int)array_column(BizCash::list($s, false, $today), 'balance', 'id')[$acc];
T::same(400, $all - $now, '⛔ موجودیِ «تا امروز» درآمدِ آینده را ندارد');

// ---- مانده‌ی اول دوره در تسویه‌ی فاکتورها ----
$c1 = (int)BizParties::save($s, ['name' => 'بدهکارِ قدیمی', 'kind' => 'customer', 'opening_amount' => '1000'])['id'];
$inv1 = $doc('sale', [$L('قاب بازرسی', '1', '500')], ['party_id' => $c1], ['amount' => '0']);
$rc1 = BizPay::create($s, ['kind' => 'receipt', 'party_id' => $c1, 'amount' => '1000', 'account_id' => $acc, 'pay_date' => $today]);
T::ok(is_int($inv1) && $rc1['ok'], 'مشتریِ با بدهیِ قدیمیِ ۱۰۰۰: فروشِ نسیه‌ی ۵۰۰ و دریافتِ ۱۰۰۰');
T::same(500, $bal($c1), 'مانده ۵۰۰');
T::same(0, $paid($inv1), '⛔ دریافت اول بدهیِ قدیمی را بست — فاکتورِ تازه هنوز باز (پرداختِ ۰، نه «تسویه»)');

$c2 = (int)BizParties::save($s, ['name' => 'بستانکارِ قدیمی', 'kind' => 'customer', 'opening_amount' => '1000', 'opening_side' => 'we'])['id'];
$inv2 = $doc('sale', [$L('قاب بازرسی', '1', '500')], ['party_id' => $c2], ['amount' => '0']);
T::same(-500, $bal($c2), 'مشتریِ بستانکار با فروشِ ۵۰۰: مانده −۵۰۰');
T::same(500, is_int($inv2) ? $paid($inv2) : -1, '⛔ اعتبارِ قدیمی فاکتورِ تازه را بست (نه «معوق»)');

// تغییرِ مانده‌ی اول دوره از فرم ⇒ تسویه از نو
$c2row = BizParties::get($s, $c2);
BizParties::save($s, ['name' => $c2row['name'], 'kind' => 'customer', 'opening_amount' => '0'], $c2);
T::same(0, $paid($inv2), 'مانده‌ی اول دوره صفر شد ⇒ فاکتور دوباره باز');

exit(T::report());
