<?php
/**
 * تستِ «دفترِ کلِ کامل‌تر» — حساب‌های دلخواه (`BizCoa`)، سندِ دستی (`BizJournal`)، دارایی‌ی
 * ثابت و استهلاک (`BizAssets`)، و اثرشان بر دفترِ مشتق و سودِ خالص.
 *
 * ⛔ سندِ دستی تراز و روی حساب‌های کنترلی ممنوع؛ استهلاک خطِ مستقیم با لنگرِ روزِ شمسی و
 *    باقیمانده به ماهِ آخر؛ خریدِ نقدی سندِ «خریدِ دارایی» است (موجودیِ صندوق کم، سود دست
 *    نخورده) که جدا باطل نمی‌شود؛ دفتر تراز و بی‌مغایرت می‌ماند و سودِ ترازنامه = `profit()`
 *    + `plAdjust()`؛ دوره‌ی بسته جلوی همه را می‌گیرد؛ بازگرداندنِ بکاپ پیوندها را نگاشت می‌کند.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
T::group('سندِ دستی، حساب‌های دلخواه و دارایی‌های ثابت');

if (!file_exists(__DIR__ . '/../config/config.php')) { T::blocked('دفترِ کل', 'config/config.php وجود ندارد'); exit(T::report()); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/user_import.php';
require_once __DIR__ . '/../includes/biz.php';
require_once __DIR__ . '/../includes/biz_docs.php';
require_once __DIR__ . '/../includes/biz_acc.php';
require_once __DIR__ . '/../includes/biz_docview.php';

try { $pdo = Database::getConnection(); } catch (Throwable $e) { T::blocked('دفترِ کل', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }
if (!BizBooks::ready()) { T::blocked('دفترِ کل', 'migration_biz_books اجرا نشده'); exit(T::report()); }

const BK_PREFIX = '__store_books_';
const BK_PASS   = 'Books#pass9';
$wipe = function () use ($pdo) {
    foreach ($pdo->query("SELECT id FROM users WHERE username LIKE '" . BK_PREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN) as $id) { deleteUserAccount((int)$id); }
};
$wipe();
register_shutdown_function($wipe);
$one = function (string $sql, array $p = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn(); };
$mk = function (string $n) use ($pdo): int {
    $id = (int)createUserAccount($pdo, 'فروشگاه ' . $n, BK_PREFIX . $n, BK_PREFIX . $n . '@example.com', BK_PASS)['id'];
    Biz::setType($id, 'business');
    return $id;
};
$u = $mk('own'); $o = $mk('oth');
$cash = (int)BizCash::list($u)[0]['id'];
$cashBal = fn(): int => (int)array_column(BizCash::list($u), 'balance', 'id')[$cash];
BizPay::create($u, ['kind' => 'capital', 'amount' => '100000000', 'account_id' => $cash, 'pay_date' => '2025-01-01', 'method' => 'cash']);

// ---- ۱. حساب‌های دلخواه ----
T::ok(BizCoa::save($u, '۲۲۰۱', 'وامِ بانکی', 'liability')['ok'], 'حسابِ وام (رقمِ فارسی)');
T::ok(BizCoa::save($u, '5301', 'هزینه‌ی تبلیغات', 'expense')['ok'], 'حسابِ هزینه‌ی تبلیغات');
foreach ([
    'کدِ برنامه'        => ['5201', 'x', 'expense'],
    'رقمِ اول با نوع نمی‌خواند' => ['4301', 'x', 'expense'],
    'کدِ کوتاه'         => ['52', 'x', 'expense'],
    'تکراری'           => ['2201', 'x', 'liability'],
    'نوعِ ناشناخته'     => ['6101', 'x', 'other'],
    'بی‌نام'            => ['5302', '', 'expense'],
] as $why => [$c, $n, $t]) {
    T::ok(!BizCoa::save($u, $c, $n, $t)['ok'], "⛔ رد: {$why}");
}
T::ok(BizCoa::save($o, '2201', 'وامِ دیگری', 'liability')['ok'], 'فروشگاهِ دیگر همان کد را جدا دارد');
T::same('وامِ بانکی', BizCoa::map($u)['2201'][0] ?? null, 'نقشه‌ی حساب‌ها: دلخواه کنارِ برنامه');
$loanId = (int)$one("SELECT id FROM biz_coa WHERE user_id = :u AND code = '2201'", ['u' => $u]);
BizCoa::save($u, '9999', 'وامِ بلندمدت', 'asset', $loanId);
T::same(['2201', 'liability', 'وامِ بلندمدت'], [(string)$one('SELECT code FROM biz_coa WHERE id = :i', ['i' => $loanId]),
    (string)$one('SELECT type FROM biz_coa WHERE id = :i', ['i' => $loanId]), (string)$one('SELECT name FROM biz_coa WHERE id = :i', ['i' => $loanId])],
    '⛔ ویرایش فقط نام؛ کد و نوع ثابت');
T::ok(!BizCoa::save($o, '', 'دست‌کاری', '', $loanId)['ok'], '⛔ ویرایشِ حسابِ فروشگاهِ دیگر رد');
T::same('وامِ بلندمدت', (string)$one('SELECT name FROM biz_coa WHERE id = :i', ['i' => $loanId]), '⛔ فروشگاهِ دیگر نامِ حساب را عوض نکرد');

// ---- ۲. سندِ دستی ----
$jv = fn(string $d, array $lines, string $desc = 'سندِ آزمایشی') => BizJournal::create($u, $d, $desc, $lines);
$r = $jv('2025-02-10', [['code' => '5301', 'debit' => '۳٬۰۰۰٬۰۰۰'], ['code' => '2201', 'credit' => '3000000'], ['code' => '', 'debit' => '', 'credit' => '']]);
T::ok($r['ok'], 'سندِ تبلیغات به حسابِ وام', $r['message']);
$j1 = (int)$r['id'];
T::same(2, (int)$one('SELECT COUNT(*) FROM biz_journal_lines WHERE journal_id = :j', ['j' => $j1]), 'ردیفِ خالیِ فرم ذخیره نشد');
foreach ([
    'ناتراز'                 => [['code' => '5301', 'debit' => '10'], ['code' => '2201', 'credit' => '9']],
    'یک ردیف'               => [['code' => '5301', 'debit' => '10']],
    'هر دو سو در یک ردیف'    => [['code' => '5301', 'debit' => '10', 'credit' => '10'], ['code' => '2201', 'credit' => '0']],
    'صندوق (۱۱۰۱)'           => [['code' => '1101', 'debit' => '10'], ['code' => '2201', 'credit' => '10']],
    'طرف‌حساب (۱۱۰۳)'        => [['code' => '1103', 'debit' => '10'], ['code' => '2201', 'credit' => '10']],
    'انبار (۱۱۰۴)'           => [['code' => '1104', 'debit' => '10'], ['code' => '2201', 'credit' => '10']],
    'دارایی‌ی ثابت (۱۲۰۱)'    => [['code' => '1201', 'debit' => '10'], ['code' => '2201', 'credit' => '10']],
    'حسابِ ناموجود'          => [['code' => '5999', 'debit' => '10'], ['code' => '2201', 'credit' => '10']],
] as $why => $lines) {
    T::ok(!$jv('2025-02-11', $lines)['ok'], "⛔ سندِ دستی رد: {$why}");
}
T::ok(!BizJournal::create($o, '2025-02-11', 'x', [['code' => '5301', 'debit' => '10'], ['code' => '3101', 'credit' => '10']])['ok'], '⛔ حسابِ دلخواهِ فروشگاهِ دیگر به کار نمی‌آید');
BizCoa::setActive($u, (int)$one("SELECT id FROM biz_coa WHERE user_id = :u AND code = '5301'", ['u' => $u]), false);
T::ok(!$jv('2025-02-12', [['code' => '5301', 'debit' => '1'], ['code' => '2201', 'credit' => '1']])['ok'], 'حسابِ غیرفعال در سندِ تازه نه');
BizCoa::setActive($u, (int)$one("SELECT id FROM biz_coa WHERE user_id = :u AND code = '5301'", ['u' => $u]), true);
$j2 = (int)$jv('2025-03-01', [['code' => '5201', 'debit' => '500000'], ['code' => '3101', 'credit' => '500000']], 'باطل‌شونده')['id'];
T::same(2, (int)$one('SELECT number FROM biz_journals WHERE id = :i', ['i' => $j2]), 'شماره‌ی پیاپی');
T::ok($jv('2025-03-02', [['code' => '3101', 'debit' => '200'], ['code' => '4201', 'credit' => '200']], 'درآمدِ دستی')['ok'], 'سندِ درآمدِ دستی');
T::ok(!BizJournal::void($o, $j2)['ok'], '⛔ فروشگاهِ دیگر باطل نمی‌کند');
T::ok(BizJournal::void($u, $j2)['ok'], 'ابطال');
T::ok(!BizJournal::void($u, $j2)['ok'], 'ابطالِ دوباره رد');

// ---- ۳. دارایی‌ی ثابت ----
$c0 = $cashBal();
$r = BizAssets::create($u, ['name' => 'ویترینِ شیشه‌ای', 'acq_date' => '2025-01-21', 'cost' => '12,000,010', 'salvage' => '10',
                            'life_months' => '12', 'funding' => 'cash', 'account_id' => (string)$cash]);
T::ok($r['ok'], 'دارایی با پرداخت از صندوق', $r['message']);
$fa = (int)$r['id'];
T::same($c0 - 12000010, $cashBal(), '⛔ موجودیِ صندوق کم شد (OUT_KINDS)');
$pay = (int)$one("SELECT id FROM biz_payments WHERE user_id = :u AND asset_id = :a AND kind = 'asset' AND status = 'ok'", ['u' => $u, 'a' => $fa]);
T::ok($pay > 0, 'سندِ «خریدِ دارایی‌ی ثابت» به دارایی پیوند دارد');
$a = BizAssets::get($u, $fa);
$sc = BizAssets::schedule($a);
T::same(12, count($sc), 'دوازده ماه');
T::same([1000000, 1000000], [$sc[0]['amount'], $sc[11]['amount']], 'ماهانه ۱ میلیون (بها − اسقاط)');
T::same(12000000, array_sum(array_column($sc, 'amount')), '⛔ جمعِ استهلاک دقیقاً بها − اسقاط');
T::same([2], array_values(array_unique(array_map(fn($s) => jalaliDayOfDate($s['date']), $sc))), '⛔ همه روزِ ۲ِ ماهِ شمسی (لنگرِ روزِ خرید، ۲ بهمن)');
T::same('2025-02-20', $sc[0]['date'], 'ماهِ اول یک ماه بعد از خرید');
T::same(3000000, BizAssets::accumulated($a, '2025-04-25'), 'انباشته تا اردیبهشت: سه ماه');
$a3 = BizAssets::create($u, ['name' => 'سه‌قسطی', 'acq_date' => '2025-01-21', 'cost' => '100', 'life_months' => '3', 'funding' => 'owner']);
T::same([33, 33, 34], array_column(BizAssets::schedule(BizAssets::get($u, (int)$a3['id'])), 'amount'), 'باقیمانده‌ی تقسیم به ماهِ آخر');
T::same($c0 - 12000010, $cashBal(), '⛔ آورده‌ی مالک صندوق را تکان نداد');
foreach ([
    'اسقاط ≥ بها'   => ['name' => 'x', 'acq_date' => '2025-01-01', 'cost' => '100', 'salvage' => '100', 'life_months' => '5', 'funding' => 'owner'],
    'عمرِ صفر'      => ['name' => 'x', 'acq_date' => '2025-01-01', 'cost' => '100', 'life_months' => '0', 'funding' => 'owner'],
    'عمرِ ۶۰۱'      => ['name' => 'x', 'acq_date' => '2025-01-01', 'cost' => '100', 'life_months' => '601', 'funding' => 'owner'],
    'بی‌نام'         => ['name' => '', 'acq_date' => '2025-01-01', 'cost' => '100', 'life_months' => '5', 'funding' => 'owner'],
    'بی‌صندوق'      => ['name' => 'x', 'acq_date' => '2025-01-01', 'cost' => '100', 'life_months' => '5', 'funding' => 'cash', 'account_id' => '0'],
] as $why => $in) {
    $n0 = (int)$one('SELECT COUNT(*) FROM biz_fixed_assets WHERE user_id = :u', ['u' => $u]);
    T::ok(!BizAssets::create($u, $in)['ok'], "⛔ دارایی رد: {$why}");
    T::same($n0, (int)$one('SELECT COUNT(*) FROM biz_fixed_assets WHERE user_id = :u', ['u' => $u]), "و ردی نماند ({$why})");
}
T::ok(!BizPay::void($u, $pay)['ok'], '⛔ پرداختِ خریدِ دارایی جدا باطل نمی‌شود');
T::ok(!BizPay::create($u, ['kind' => 'asset', 'amount' => '10', 'account_id' => $cash, 'pay_date' => '2025-01-01'])['ok'], '⛔ نوعِ «خریدِ دارایی» از فرم ساخته نمی‌شود');

// ---- ۴. دفترِ مشتق و سود ----
$all = BizLedger::entries($u, BizReports::ALL_TO);
T::ok(BizLedger::balanced($all), '⛔ همه‌ی سندها تراز');
$rec = BizLedger::reconcile($u);
T::ok($rec['ok'], '⛔ مغایرت‌گیری: صندوق و طرف‌حساب با صفحه‌ها یکی', json_encode($rec['cash'], JSON_UNESCAPED_UNICODE));
$at = '2025-04-25';
$tb = BizLedger::trial($u, $at, '', BizLedger::upTo($all, $at));
$bal = fn(string $c): int => (int)($tb['rows'][$c]['balance'] ?? 0);
T::same([12000010 + 100, -3000000 - 100, 3000000 + 100, -3000000], [$bal('1201'), $bal('1202'), $bal('5202'), $bal('2201')],
    'دارایی، استهلاکِ انباشته، هزینه‌ی استهلاک و وامِ دلخواه در تراز');
T::same(3000000, $bal('5301'), 'حسابِ هزینه‌ی دلخواه در تراز');
T::same(0, (int)($tb['rows']['5201']['balance'] ?? 0), '⛔ سندِ باطل در دفتر نیست');
$bs = BizLedger::balanceSheet($u, $at, BizLedger::upTo($all, $at));
T::same(0, $bs['diff'], '⛔ ترازنامه تراز است (حساب‌های دلخواه در گروهِ خودشان)');
T::same(12000010 + 100 - 3000100, $bs['asset']['دارایی‌های ثابت (خالصِ استهلاک)'], 'ترازنامه: دارایی‌ی ثابت خالص');
T::same(3000000, $bs['liability'][toPersianDigits('2201') . ' — وامِ بلندمدت'] ?? null, 'ترازنامه: وامِ دلخواه زیرِ بدهی‌ها');
$s = BizReports::sales($u, '2025-01-01', $at); $cs = BizReports::cash($u, '2025-01-01', $at);
$adj = BizBooks::plAdjust($u, '2025-01-01', $at);
T::same(-(3000000 + 3000100) + 200, $adj, '⛔ plAdjust = درآمدِ دستی − (تبلیغاتِ دستی + استهلاک)');
$pl = BizReports::profit($s['gross'], $s['other'], $cs['income'], $cs['expense'], $adj);
T::same($bs['equity']['سود و زیانِ سالِ جاری'] + $bs['equity']['سود و زیانِ سال‌های قبل'], $pl, '⛔ سودِ ترازنامه = profit() + plAdjust()');
T::same(0, $cs['expense'], '⛔ خریدِ دارایی هزینه نیست');

// ---- ۵. کنار گذاشتن ----
T::ok(!BizAssets::dispose($u, $fa, '2025-01-01')['ok'], 'کنار گذاشتن پیش از خرید رد');
T::ok(BizAssets::dispose($u, $fa, '2025-04-25')['ok'], 'کنار گذاشتن');
T::same(3000000, BizAssets::accumulated(BizAssets::get($u, $fa), '2026-01-01'), '⛔ استهلاک بعد از کنار گذاشتن ایستاد');
$tb2 = BizLedger::trial($u, BizReports::ALL_TO);
T::same([100, 9000010], [(int)$tb2['rows']['1201']['balance'], (int)$tb2['rows']['5203']['balance']], '⛔ بها از دفتر بیرون، ارزشِ دفتری زیان شد');
T::same([3000100, -100], [(int)$tb2['rows']['5202']['balance'], (int)$tb2['rows']['1202']['balance']], '⛔ دفتر هم بعد از کنار گذاشتن استهلاکی ندارد');
T::ok(BizLedger::balanced(BizLedger::entries($u, BizReports::ALL_TO)), 'باز هم تراز');
T::ok(!BizAssets::remove($u, $fa)['ok'], 'حذفِ کنارگذاشته رد (اول برگردانید)');
T::ok(BizAssets::undispose($u, $fa)['ok'], 'برگرداندن');

// ---- ۶. دوره‌ی بسته ----
Biz::saveLock($u, '2025-03-31');
T::ok(!$jv('2025-03-15', [['code' => '5301', 'debit' => '1'], ['code' => '2201', 'credit' => '1']])['ok'], '⛔ سندِ دستی در دوره‌ی بسته رد');
T::ok(!BizJournal::void($u, $j1)['ok'], '⛔ ابطالِ سندِ دوره‌ی بسته رد');
T::ok(!BizAssets::remove($u, $fa)['ok'], '⛔ حذفِ دارایی‌ی خریده‌شده در دوره‌ی بسته رد');
T::ok(!BizAssets::remove($u, (int)$a3['id'])['ok'], '⛔ و دارایی‌ی آورده‌ای (بی‌پرداخت) هم');
T::ok(!BizAssets::create($u, ['name' => 'x', 'acq_date' => '2025-03-01', 'cost' => '100', 'life_months' => '5', 'funding' => 'owner'])['ok'], '⛔ خرید در دوره‌ی بسته رد');
T::ok(!BizAssets::dispose($u, $fa, '2025-03-01')['ok'], '⛔ کنار گذاشتن در دوره‌ی بسته رد');
Biz::saveLock($u, '', true);

// ---- ۷. حذف ----
$c1 = $cashBal();
T::ok(!BizAssets::remove($o, $fa)['ok'], '⛔ فروشگاهِ دیگر حذف نمی‌کند');
T::ok(BizAssets::remove($u, $fa)['ok'], 'حذفِ دارایی');
T::same($c1 + 12000010, $cashBal(), '⛔ پرداختِ خرید باطل شد و پول برگشت');
T::same(0, (int)$one('SELECT COUNT(*) FROM biz_fixed_assets WHERE id = :i', ['i' => $fa]), 'ردیفِ دارایی رفت');
T::ok(BizLedger::reconcile($u)['ok'] && BizLedger::balanced(BizLedger::entries($u, BizReports::ALL_TO)), 'دفتر سالم ماند');

// ---- ۸. بکاپ: پیوندِ بی‌کلید نگاشت می‌شود ----
$fk = importForeignKeys();
T::same(['biz_journals', 'biz_fixed_assets'], [$fk['biz_journal_lines']['journal_id'] ?? null, $fk['biz_payments']['asset_id'] ?? null],
    '⛔ پیوندِ ردیفِ سند و خریدِ دارایی در نگاشتِ بازگرداندن');
T::ok(($fk['biz_installments']['invoice_id'] ?? null) === 'biz_invoices' && ($fk['biz_repairs']['invoice_id'] ?? null) === 'biz_invoices',
    '⛔ و قسط و قبضِ تعمیر هم');
$order = importTableOrder(['biz_payments', 'biz_fixed_assets', 'biz_journal_lines', 'biz_journals'], $fk);
T::ok(array_search('biz_fixed_assets', $order, true) < array_search('biz_payments', $order, true)
    && array_search('biz_journals', $order, true) < array_search('biz_journal_lines', $order, true), 'پدر پیش از فرزند، بی‌حلقه');

// ---- ۹. HTTP ----
$root = realpath(__DIR__ . '/..');
$port = 0;
for ($p = 9570; $p <= 9610; $p++) { $sk = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2); if ($sk) { fclose($sk); $port = $p; break; } }
if (!$port) { T::blocked('دفترِ کل (HTTP)', 'پورت آزاد پیدا نشد'); exit(T::report()); }
$log = tempnam(sys_get_temp_dir(), 'bklog');
$srv = (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!', $port, escapeshellarg($root), escapeshellarg($log))));
register_shutdown_function(static function () use ($srv, $log) { exec("kill {$srv} 2>/dev/null"); @unlink($log); });
for ($i = 0, $up = false; $i < 40 && !$up; $i++) { usleep(150000); $sk = @fsockopen('127.0.0.1', $port, $a1, $b1, 0.3); if ($sk) { fclose($sk); $up = true; } }
T::ok($up, 'سرورِ آزمایشی بالا آمد');
if (!$up) { exit(T::report()); }
$jar = tempnam(sys_get_temp_dir(), 'bkjar');
$req = function (string $path, ?array $post = null) use ($port, $jar): array {
    $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 40]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, $body];
};
[, $lp] = $req('store/login.php');
$req('store/login.php', ['csrf_token' => preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $lp, $m) ? $m[1] : '', 'username' => BK_PREFIX . 'own', 'password' => BK_PASS]);
foreach (['vouchers' => 'name="action" value="jv_save"', 'coa' => 'وامِ بلندمدت', 'assets' => 'name="action" value="fa_save"'] as $tab => $needle) {
    [$c, $h] = $req('store/accounting.php?t=' . $tab);
    T::ok($c === 200 && str_contains($h, '</html>') && str_contains($h, $needle), "زبانه‌ی «{$tab}» رندر شد", (string)$c);
}
[, $h] = $req('store/accounting.php?t=vouchers');
T::ok(!preg_match('/<option value="1101"/', $h) && str_contains($h, '<option value="2201"'), '⛔ فرمِ سندِ دستی صندوق را پیشنهاد نمی‌کند، حسابِ دلخواه را می‌کند');
$tok = preg_match('/name="csrf-token" content="([^"]+)"/', $h, $m) ? $m[1] : '';
$req('store/accounting.php?t=vouchers', ['csrf_token' => $tok, 'action' => 'jv_save', 'jdate' => BizDocView::jDate('2025-05-01'), 'description' => 'از فرم',
    'lines' => [['code' => '5301', 'debit' => '700'], ['code' => '2201', 'credit' => '700']]]);
T::same(1, (int)$one("SELECT COUNT(*) FROM biz_journals WHERE user_id = :u AND description = 'از فرم'", ['u' => $u]), 'سندِ دستی از فرم');
$req('store/accounting.php?t=assets', ['csrf_token' => $tok, 'action' => 'fa_save', 'name' => 'لپ‌تاپ', 'acq_date' => BizDocView::jDate('2025-05-01'),
    'cost' => '۲۴٬۰۰۰', 'life_months' => '24', 'salvage' => '', 'funding' => 'cash', 'account_id' => (string)$cash, 'note' => '']);
T::same(24000, (int)$one("SELECT cost FROM biz_fixed_assets WHERE user_id = :u AND name = 'لپ‌تاپ'", ['u' => $u]), 'دارایی از فرم');
[$c, $h] = $req('store/accounting.php?t=journal&p=all');
T::ok($c === 200 && str_contains($h, 'استهلاکِ ماهانه‌ی دارایی‌های ثابت') && str_contains($h, 'هزینه‌ی تبلیغات'), 'روزنامه: استهلاک و نامِ حسابِ دلخواه');
[$c, $h] = $req('store/payment.php?k=asset');
T::ok(!str_contains($h, 'payment.php?k=asset"'), '⛔ «خریدِ دارایی» زبانه‌ی فرمِ پرداخت نیست');
$pid = (int)$one("SELECT id FROM biz_payments WHERE user_id = :u AND kind = 'asset' AND status = 'ok'", ['u' => $u]);
[$c, $h] = $req('store/payment.php?id=' . $pid);
T::ok($c === 200 && !str_contains($h, 'value="void"'), 'صفحه‌ی پرداختِ دارایی دکمه‌ی ابطال ندارد');
[$c, $h] = $req('store/print.php?doc=sales&p=custom&from=' . urlencode(BizDocView::jDate('2025-01-01')) . '&to=' . urlencode(BizDocView::jDate('2025-12-31')));
T::ok($c === 200 && str_contains($h, 'استهلاکِ دارایی‌ها و سندهای دستی'), 'صورتِ سود و زیان ردیفِ استهلاک و سندِ دستی دارد');
$sl = (string)@file_get_contents($log);
T::ok(!preg_match('/PHP (Fatal|Warning|Notice|Deprecated)/', $sl), 'لاگِ سرور بی‌هشدار و بی‌خطا', mb_substr($sl, 0, 600));
@unlink($jar);

$wipe();
exit(T::report());
