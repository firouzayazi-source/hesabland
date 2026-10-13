<?php
/**
 * تستِ «وصول و پرداخت» — `moneyMoves()`.
 *
 * **گزارشِ مالکِ نصب (مهر ۱۴۰۵):** «۵ تومن از کسی طلب داشتم، ۱ تومن بهم داده؛ از طلب
 * کم می‌شه ولی توی تراکنش‌ها و گزارش‌ها نمیاد.»
 *
 * ⛔ می‌سنجد: وصولِ طلب، پرداختِ بدهی و چکِ پاس‌شده در فهرستِ تراکنش‌ها، آخرین‌های
 *    خانه، خروجیِ اکسل و گزارشِ بازه دیده می‌شوند — با برچسبِ خودشان — و **هیچ‌کدام
 *    درآمد/هزینه نمی‌شوند** (نه ردیفِ `transactions`، نه در جمعِ ماه، نه در صافیِ درآمد).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
T::group('وصول و پرداخت');
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tx_query.php';

if (!file_exists(__DIR__ . '/../config/config.php')) { T::blocked('وصول و پرداخت', 'config/config.php وجود ندارد'); exit(T::report()); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
try { $pdo = Database::getConnection(); } catch (Throwable $e) { T::blocked('وصول و پرداخت', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }
if (!tableHasColumn('debt_payments', 'wallet_id') || !tableHasColumn('cheques', 'status')) { T::blocked('وصول و پرداخت', 'migration_money_links اجرا نشده'); exit(T::report()); }

$root = dirname(__DIR__);
$U = ['__money_moves_u', 'Money#moves9'];
$purge = function () use ($pdo, $U) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $U[0]]);
    if ($id = $st->fetchColumn()) { deleteUserAccount((int)$id); }
};
$purge();
register_shutdown_function($purge);
$acc = createUserAccount($pdo, 'کاربرِ وصول', $U[0], $U[0] . '@example.com', $U[1]);
$uid = (int)($acc['id'] ?? 0);
T::ok($uid > 0, 'کاربرِ آزمایشی ساخته شد');

$port = 0;
for ($p = 9410; $p <= 9450; $p++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
    if ($sock) { fclose($sock); $port = $p; break; }
}
if (!$port) { T::blocked('وصول و پرداخت', 'پورت آزاد پیدا نشد'); exit(T::report()); }
$log = tempnam(sys_get_temp_dir(), 'mvlog');
$srv = (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!', $port, escapeshellarg($root), escapeshellarg($log))));
register_shutdown_function(static function () use ($srv, $log) { exec("kill {$srv} 2>/dev/null"); @unlink($log); });
for ($i = 0, $up = false; $i < 40 && !$up; $i++) { usleep(150000); $sk = @fsockopen('127.0.0.1', $port, $a, $b, 0.3); if ($sk) { fclose($sk); $up = true; } }
T::ok($up, 'سرورِ آزمایشی بالا آمد');
if (!$up) { exit(T::report()); }

$jar = tempnam(sys_get_temp_dir(), 'mvjar');
$req = function (string $path, ?array $post = null) use ($port, $jar): array {
    $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 40]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); unset($ch);
    return [$code, $body];
};
[, $lp] = $req('login.php');
$tok = preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $lp, $m) ? $m[1] : '';
$req('login.php', ['csrf_token' => $tok, 'username' => $U[0], 'password' => $U[1]]);
[, $html] = $req('debts.php');
$csrf = preg_match('/name="csrf-token" content="([^"]+)"/', $html, $m) || preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m) ? $m[1] : '';
T::ok($csrf !== '', 'توکنِ CSRF گرفته شد');

$api = function (string $file, array $post) use ($req, $csrf): array {
    [$code, $body] = $req('api/' . $file, $post + ['csrf_token' => $csrf]);
    return [$code, json_decode($body, true) ?: []];
};
$today = today();
$wallet = defaultWalletId($uid);
$bal = fn() => (int)array_column(walletBalances($uid), 'balance', 'id')[$wallet];
$bal0 = $bal();

// طلبِ ۵ میلیون؛ ۱ میلیون وصول
[, $j] = $api('add_debt.php', ['direction' => 'receivable', 'counterparty_name' => 'آقای رضایی', 'amount' => '5000000', 'entry_date' => $today]);
T::ok(!empty($j['success']), 'طلب ثبت شد', (string)($j['message'] ?? ''));
$debtId = (int)$pdo->query("SELECT id FROM debts WHERE user_id = {$uid} AND counterparty_name = 'آقای رضایی'")->fetchColumn();
[, $j] = $api('add_debt_payment.php', ['debt_id' => $debtId, 'amount' => '1000000', 'payment_date' => $today, 'note' => 'نقدِ اول', 'wallet_id' => $wallet]);
T::ok(!empty($j['success']), 'وصولِ ۱ میلیون ثبت شد', (string)($j['message'] ?? ''));

// بدهیِ ۲ میلیون؛ ۳۰۰ هزار پرداخت
[, $j] = $api('add_debt.php', ['direction' => 'payable', 'counterparty_name' => 'فروشگاهِ نور', 'amount' => '2000000', 'entry_date' => $today]);
$payId = (int)$pdo->query("SELECT id FROM debts WHERE user_id = {$uid} AND counterparty_name = 'فروشگاهِ نور'")->fetchColumn();
[, $j] = $api('add_debt_payment.php', ['debt_id' => $payId, 'amount' => '300000', 'payment_date' => $today, 'wallet_id' => $wallet]);
T::ok(!empty($j['success']), 'پرداختِ ۳۰۰ هزار ثبت شد');

// چکِ دریافتیِ ۷۰۰ هزار، پاس
[, $j] = $api('add_cheque.php', ['direction' => 'received', 'counterparty_name' => 'خانمِ کاظمی', 'amount' => '700000', 'due_date' => $today]);
$chqId = (int)$pdo->query("SELECT id FROM cheques WHERE user_id = {$uid}")->fetchColumn();
[, $j] = $api('toggle_cheque_settled.php', ['cheque_id' => $chqId, 'status' => 'cleared', 'wallet_id' => $wallet]);
T::ok(!empty($j['success']), 'چک پاس شد', (string)($j['message'] ?? ''));

// ⛔ هیچ‌کدام تراکنش نیست؛ موجودی همه را دارد
T::same(0, (int)$pdo->query("SELECT COUNT(*) FROM transactions WHERE user_id = {$uid}")->fetchColumn(), '⛔ هیچ ردیفِ transactions ساخته نشد (درآمد/هزینه نیستند)');
T::same($bal0 + 1000000 - 300000 + 700000, $bal(), 'موجودیِ حساب هر سه را دارد');

// تابع
$mv = moneyMoves($uid);
T::same(3, count($mv), 'سه جابه‌جایی');
$byTitle = array_column($mv, null, 'title');
T::ok(isset($byTitle['وصولِ طلب از آقای رضایی']) && $byTitle['وصولِ طلب از آقای رضایی']['type'] === 'income', 'وصولِ طلب = آمد');
T::ok(isset($byTitle['پرداختِ بدهی به فروشگاهِ نور']) && $byTitle['پرداختِ بدهی به فروشگاهِ نور']['type'] === 'expense', 'پرداختِ بدهی = رفت');
T::ok(isset($byTitle['پاس شدنِ چکِ خانمِ کاظمی']), 'چکِ پاس‌شده هم هست');
T::same([], moneyMoves($uid, ['type' => 'income']), '⛔ صافیِ «درآمد» هیچ وصولی نمی‌آورد');
T::same(1, count(moneyMoves($uid, ['search' => 'رضایی'])), 'جست‌وجو با نامِ طرف');
T::same(1, count(moneyMoves($uid, ['search' => '300000'])), 'جست‌وجو با مبلغ');
$y = date('Y-m-d', strtotime($today . ' -1 day'));
T::same([], moneyMoves($uid, ['date_from' => $y, 'date_to' => $y]), 'بازه‌ی دیروز خالی است');
T::same(['in' => 1700000, 'out' => 300000, 'count' => 3], moneyMovesTotals($uid, $today, $today), 'جمعِ آمد و رفتِ امروز');
// ⛔ جداسازی: پرداختِ کاربرِ دیگر هرگز اینجا نمی‌آید
$U2 = '__money_moves_u2';
$purge2 = function () use ($pdo, $U2) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u'); $st->execute(['u' => $U2]);
    if ($id = $st->fetchColumn()) { deleteUserAccount((int)$id); }
};
$purge2();
register_shutdown_function($purge2);
$uid2 = (int)(createUserAccount($pdo, 'دیگری', $U2, $U2 . '@example.com', 'Other#user9')['id'] ?? 0);
$pdo->prepare("INSERT INTO debts (user_id, direction, counterparty_name, amount, entry_date) VALUES (:u, 'receivable', 'غریبه', 900, :d)")->execute(['u' => $uid2, 'd' => $today]);
$d2 = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO debt_payments (debt_id, user_id, wallet_id, amount, payment_date) VALUES (:d, :u, :w, 900, :p)')
    ->execute(['d' => $d2, 'u' => $uid2, 'w' => defaultWalletId($uid2), 'p' => $today]);
T::same(3, count(moneyMoves($uid)), '⛔ پرداختِ کاربرِ دیگر در فهرستِ این کاربر نیست');
T::same(1, count(moneyMoves($uid2)), 'و کاربرِ دیگر فقط مالِ خودش را می‌بیند');

// صفحه‌ی تراکنش‌ها
[$c, $html] = $req('transactions.php');
T::ok($c === 200 && str_contains($html, '</html>'), 'صفحه‌ی تراکنش‌ها کامل رندر شد', (string)$c);
T::ok(str_contains($html, 'وصولِ طلب از آقای رضایی'), '⛔ وصولِ طلب در تراکنش‌ها دیده می‌شود');
T::ok(preg_match('~وصولِ طلب از آقای رضایی</span>.*?\+' . preg_quote(formatMoney(1000000), '~') . '~su', $html) === 1, 'با مبلغِ +۱٬۰۰۰٬۰۰۰');
T::ok(str_contains($html, 'پرداختِ بدهی به فروشگاهِ نور') && str_contains($html, 'پاس شدنِ چکِ خانمِ کاظمی'), 'پرداختِ بدهی و چکِ پاس‌شده هم');
T::same(3, substr_count($html, 'class="tx-row is-move"'), 'هر سه با ردیفِ «وصول و پرداخت» (نه ردیفِ تراکنش)');
T::ok(str_contains($html, 'وصولِ طلب — درآمد یا هزینه نیست</span>'), 'برچسبِ صریح: درآمد/هزینه نیست');
T::ok(!str_contains($html, 'js-edit-tx" data-id="' . $debtId), 'ردیفِ وصول دکمه‌ی ویرایشِ تراکنش ندارد');
T::ok(str_contains($html, '(' . toPersianDigits('3') . ' تراکنش)'), 'شمارشِ فهرست جابه‌جایی‌ها را هم دارد');
[, $html] = $req('transactions.php?type=income');
T::ok(!str_contains($html, 'وصولِ طلب از آقای رضایی'), '⛔ صافیِ «درآمد» وصولِ طلب را نشان نمی‌دهد');

// صفحه‌بندی: ۴۵ تراکنش + ۳ جابه‌جایی؛ هیچ ردیفی تکرار یا گم نمی‌شود
$ins = $pdo->prepare("INSERT INTO transactions (user_id, type, amount, title, transaction_date, wallet_id, created_at) VALUES (:u, 'expense', 1000, :t, :d, :w, :c)");
for ($i = 0; $i < 45; $i++) {
    $ins->execute(['u' => $uid, 't' => 'خرید ' . $i, 'd' => date('Y-m-d', strtotime($today . " -{$i} day")), 'w' => $wallet, 'c' => date('Y-m-d H:i:s', time() - 86400 * $i)]);
}
[, $html] = $req('transactions.php?type=move');
T::ok(str_contains($html, 'وصولِ طلب از آقای رضایی') && str_contains($html, 'data-filter="move"'), 'صافیِ «وصول و پرداخت»');
T::ok(!str_contains($html, 'خرید 0'), '⛔ صافیِ «وصول و پرداخت» هیچ تراکنشی نمی‌آورد');
[, $p1] = $req('transactions.php');
[, $p2] = $req('transactions.php?p=2');
preg_match_all('~<span class="tx-row-title">(.*?)</span>~u', $p1 . $p2, $mm);
T::same(48, count($mm[1]), 'دو صفحه = ۴۸ ردیف');
T::same(48, count(array_unique($mm[1])), '⛔ هیچ ردیفی بینِ دو صفحه تکرار نشد');
T::ok(str_contains($p1, 'وصولِ طلب از آقای رضایی'), 'وصولِ امروز در صفحه‌ی اول، کنارِ تراکنش‌های امروز');

// خانه
[, $home] = $req('index.php');
T::ok(str_contains($home, 'وصولِ طلب از آقای رضایی') || str_contains($home, 'پاس شدنِ چکِ خانمِ کاظمی'), 'آخرین‌های خانه جابه‌جایی را هم نشان می‌دهد');

// اکسل
[$c, $csv] = $req('api/export_transactions.php', ['csrf_token' => $csrf, 'period' => 'all', 'type' => 'all']);
T::ok(str_contains($csv, 'وصولِ طلب از آقای رضایی') && str_contains($csv, ',"وصولِ طلب",'), '⛔ اکسل هم وصول را دارد، با نوعِ «وصولِ طلب» نه «درآمد»', substr($csv, 0, 200));
T::same(48 + 1, substr_count(trim($csv), "\n") + 1, 'اکسل: سرآیند + ۴۸ ردیف');
[, $csv2] = $req('api/export_transactions.php', ['csrf_token' => $csrf, 'period' => 'all', 'type' => 'expense']);
T::ok(!str_contains($csv2, 'آقای رضایی'), 'اکسلِ «هزینه» جابه‌جایی ندارد');

// گزارش
$mc = monthComparison($uid);
T::same(0, (int)$mc['current_income'], '⛔ درآمدِ ماه با وصولِ طلب باد نکرد');
[, $dash] = $req('dashboard.php?from_date=' . $today . '&to_date=' . $today);
T::ok(preg_match('~دریافت از طلب و چک</div><div class="value stats-income">' . preg_quote(formatMoney(1700000), '~') . '~u', $dash) === 1, '⛔ گزارشِ بازه: دریافت از طلب و چک ۱٬۷۰۰٬۰۰۰');
T::ok(preg_match('~پرداختِ بدهی و چک</div><div class="value stats-expense">' . preg_quote(formatMoney(300000), '~') . '~u', $dash) === 1, 'و پرداختِ بدهی و چک ۳۰۰٬۰۰۰');

exit(T::report());
