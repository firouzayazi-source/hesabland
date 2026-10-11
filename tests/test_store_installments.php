<?php
/**
 * تستِ «فروشِ اقساطی و معاوضه» — `BizInstallments`، `BizTradeIn`، صندوقِ تهاتر.
 *
 * ⛔ اقساط فقط زمان‌بندی است: پرداخت‌شده‌ی هر قسط از دریافت‌های بعد از برنامه، به ترتیبِ
 *    سررسید؛ هیچ پولی جابه‌جا نمی‌شود. معاوضه = خریدِ واقعی + تهاترِ جفت: گوشیِ کهنه با IMEI
 *    در انبار، ماندهِ مشتری = قیمتِ نو − ارزشِ کهنه، صندوقِ تهاتر همیشه صفر و غیرقابلِ
 *    دست‌کاری، ابطالِ یکی هر دو را برمی‌گرداند، و دفترِ دوطرفه با صفحه‌ها می‌خواند.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
T::group('فروشِ اقساطی و معاوضه');

if (!file_exists(__DIR__ . '/../config/config.php')) { T::blocked('اقساط', 'config/config.php وجود ندارد'); exit(T::report()); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/biz.php';
require_once __DIR__ . '/../includes/biz_sales_ext.php';
require_once __DIR__ . '/../includes/biz_reports.php';
require_once __DIR__ . '/../includes/biz_acc.php';
require_once __DIR__ . '/../includes/biz_docview.php';
require_once __DIR__ . '/../includes/schedule.php';

try { $pdo = Database::getConnection(); } catch (Throwable $e) { T::blocked('اقساط', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }
if (!BizInstallments::ready()) { T::blocked('اقساط', 'migration_biz_installments اجرا نشده'); exit(T::report()); }

const IN_PREFIX = '__store_inst_';
$wipe = function () use ($pdo) {
    foreach ($pdo->query("SELECT id FROM users WHERE username LIKE '" . IN_PREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN) as $id) { deleteUserAccount((int)$id); }
};
$wipe();
register_shutdown_function($wipe);
$one = function (string $sql, array $p = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn(); };
$mk = function (string $n) use ($pdo): int {
    $id = (int)createUserAccount($pdo, 'فروشگاه ' . $n, IN_PREFIX . $n, IN_PREFIX . $n . '@example.com', 'Inst#pass9')['id'];
    Biz::setType($id, 'business');
    return $id;
};
$u = $mk('own'); $o = $mk('oth');
$cash = (int)BizCash::list($u)[0]['id'];
$cus  = (int)BizParties::save($u, ['name' => 'خریدارِ اقساطی', 'kind' => 'customer'])['id'];
$sup  = (int)BizParties::save($u, ['name' => 'پخش', 'kind' => 'supplier'])['id'];
$svc  = (int)BizProducts::save($u, ['type' => 'service', 'name' => 'خدمت', 'unit' => 'عدد', 'sell_price' => '1'])['id'];
$sale = function (int $total, int $party = -1, int $pay = 0, string $date = '2026-01-10') use ($u, $cus, $cash): int {
    $id = (int)BizInvoices::saveDraft($u, 'sale', ['party_id' => $party === -1 ? $cus : $party, 'inv_date' => $date,
        'lines' => [['item' => 'خدمت', 'qty' => '1', 'price' => (string)$total]]])['id'];
    $r = BizInvoices::issue($u, $id, $pay > 0 ? ['account_id' => $cash, 'amount' => (string)$pay, 'part' => true] : ($party === 0 ? ['account_id' => $cash, 'full' => true] : ['amount' => '0']));
    if (!$r['ok']) { throw new RuntimeException($r['message']); }
    return $id;
};
$recv = function (int $inv, int $amt, string $date) use ($u, $cus, $cash) {
    $r = BizPay::create($u, ['kind' => 'receipt', 'party_id' => $cus, 'invoice_id' => $inv, 'amount' => (string)$amt, 'account_id' => $cash, 'pay_date' => $date, 'method' => 'cash']);
    if (!$r['ok']) { throw new RuntimeException($r['message']); }
    return (int)$r['id'];
};

// ---- ۱. اقساط ----
$s1 = $sale(6000000, -1, 1000000);
$r = BizInstallments::plan($u, $s1, 5, '2026-02-10');
T::ok($r['ok'], 'پنج قسط', $r['message']);
$sc = BizInstallments::schedule($u, $s1, '2026-01-20');
T::same([1000000, 1000000, 1000000, 1000000, 1000000], array_column($sc, 'amount'), 'هر قسط ۱ میلیون (پیش‌پرداخت از مانده کم شد)');
T::same(['2026-02-10', '2026-03-12', '2026-04-10', '2026-05-11', '2026-06-11'], array_column($sc, 'due_date'), 'ماه‌به‌ماه با لنگرِ روزِ شمسی (هر ۲۱ام)');
T::same([21], array_values(array_unique(array_map(fn($d) => jalaliDayOfDate($d), array_column($sc, 'due_date')))), 'همه روزِ ۲۱ِ ماهِ شمسی');
T::same(['due', 'due', 'due', 'due', 'due'], array_column($sc, 'state'), 'پیش‌پرداخت هیچ قسطی را «پرداخت» نکرد');
$recv($s1, 1500000, '2026-02-09');
$sc = BizInstallments::schedule($u, $s1, '2026-02-20');
T::same([[1000000, 0, 'paid'], [500000, 500000, 'partial']], array_map(fn($x) => [$x['paid'], $x['remaining'], $x['state']], array_slice($sc, 0, 2)), '⛔ دریافت به ترتیبِ سررسید پر کرد');
T::same('overdue', BizInstallments::schedule($u, $s1, '2026-03-20')[1]['state'], 'بعد از سررسید: سررسید گذشته');
T::same(6000000 - 2500000, (int)$one('SELECT total - paid FROM biz_invoices WHERE id = :i', ['i' => $s1]), 'ماندهِ فاکتور همان مسیرِ همیشگی');
$open = BizInstallments::open($u, '2026-04-30', '2026-03-20');
T::same([[2, 500000, 'overdue'], [3, 1000000, 'due']], array_map(fn($x) => [$x['seq'], $x['remaining'], $x['state']], $open), '«اقساطِ مشتریان»: باز تا بازه، پرداخت‌شده بیرون');
$s2 = $sale(1000001);
T::ok(BizInstallments::plan($u, $s2, 3, '2026-02-01', 'week')['ok'], 'هفتگی');
$sc2 = BizInstallments::schedule($u, $s2);
T::same([333333, 333333, 333335], array_column($sc2, 'amount'), 'باقیمانده‌ی تقسیم به قسطِ آخر');
T::same(['2026-02-01', '2026-02-08', '2026-02-15'], array_column($sc2, 'due_date'), 'هفته‌به‌هفته');
T::ok(BizInstallments::plan($u, $s2, 2, '2026-03-01')['ok'], 'برنامه‌ی تازه جایگزین شد');
T::same(2, count(BizInstallments::schedule($u, $s2)), 'دو قسطِ تازه، نه پنج');
foreach ([
    'گذری' => fn() => BizInstallments::plan($u, $sale(100, 0), 2, '2026-02-01'),
    'خرید' => fn() => BizInstallments::plan($u, (int)BizInvoices::saveDraft($u, 'purchase', ['party_id' => $sup, 'lines' => [['item' => 'خدمت', 'qty' => '1', 'price' => '5']]])['id'], 2, '2026-02-01'),
    'تسویه‌شده' => fn() => BizInstallments::plan($u, $sale(100, -1, 100), 2, '2026-02-01'),
    'صفر قسط' => fn() => BizInstallments::plan($u, $s2, 0, '2026-02-01'),
    '۶۱ قسط' => fn() => BizInstallments::plan($u, $s2, 61, '2026-02-01'),
    'پیش از فاکتور' => fn() => BizInstallments::plan($u, $s2, 2, '2025-12-01'),
    'فروشگاهِ دیگر' => fn() => BizInstallments::plan($o, $s1, 2, '2026-02-01'),
] as $why => $f) {
    T::ok(!$f()['ok'], "⛔ رد: {$why}");
}
T::same([], BizInstallments::schedule($o, $s1), '⛔ فروشگاهِ دیگر برنامه را نمی‌بیند');
BizInvoices::void($u, $s2);
T::same(['void', 'void'], array_column(BizInstallments::schedule($u, $s2), 'state'), 'فاکتورِ باطل: قسط‌ها «باطل»');
T::same([], array_filter(BizInstallments::open($u, '2030-01-01'), fn($x) => $x['invoice_id'] === $s2), 'و در «اقساطِ مشتریان» نیست');

// ---- ۲. معاوضه ----
$cashBal = fn(int $acc): int => (int)array_column(BizCash::list($u), 'balance', 'id')[$acc];
$partyBal = fn(int $p): int => (int)$one('SELECT ' . BizParties::BALANCE_SQL . ' FROM biz_parties p WHERE p.id = :i AND p.user_id = :u', ['i' => $p, 'u' => $u]);
$walkIn = $sale(500, 0);
$cash0 = $cashBal($cash);
$ph = (int)BizProducts::save($u, ['type' => 'phone', 'name' => 'گوشیِ نو', 'unit' => 'دستگاه', 'buy_price' => '8000000', 'sell_price' => '10000000'])['id'];
$pu = (int)BizInvoices::saveDraft($u, 'purchase', ['party_id' => $sup, 'inv_date' => '2026-01-01', 'lines' => [['item' => 'گوشیِ نو', 'qty' => '1', 'price' => '8000000', 'imei1' => '356938035643809']]])['id'];
BizInvoices::issue($u, $pu, ['amount' => '0']);
$pu2 = (int)BizInvoices::saveDraft($u, 'purchase', ['party_id' => $sup, 'inv_date' => '2026-01-01', 'lines' => [['item' => 'گوشیِ نو', 'qty' => '1', 'price' => '8000000', 'imei1' => '353918057426721']]])['id'];
BizInvoices::issue($u, $pu2, ['amount' => '0']);
$bal0 = $partyBal($cus);
$ns = (int)BizInvoices::saveDraft($u, 'sale', ['party_id' => $cus, 'inv_date' => '2026-01-12', 'lines' => [['item' => 'گوشیِ نو', 'qty' => '1', 'price' => '10000000', 'imei1' => '356938035643809']]])['id'];
T::ok(BizInvoices::issue($u, $ns, ['amount' => '0'])['ok'], 'فروشِ گوشیِ نو');
$OLD = '490154203237518';
foreach ([
    'ارزشِ بیش از مانده' => ['new_name' => 'کهنه', 'imei' => $OLD, 'value' => '10000001'],
    'IMEIِ خراب' => ['new_name' => 'کهنه', 'imei' => '12', 'value' => '1000'],
    'کالای غیرگوشی' => ['product_id' => $svc, 'imei' => $OLD, 'value' => '1000'],
    'کالای انباریِ بی‌IMEI' => ['product_id' => (int)BizProducts::save($u, ['type' => 'goods', 'name' => 'قابِ ساده', 'unit' => 'عدد', 'buy_price' => '1', 'sell_price' => '2'])['id'], 'imei' => $OLD, 'value' => '1000'],
    'بی‌کالا' => ['imei' => $OLD, 'value' => '1000'],
    'IMEIِ گوشیِ در انبار' => ['product_id' => $ph, 'imei' => '353918057426721', 'value' => '1000'],
] as $why => $in) {
    $purBefore = (int)$one("SELECT COUNT(*) FROM biz_invoices WHERE user_id = :u AND kind = 'purchase'", ['u' => $u]);
    T::ok(!BizTradeIn::run($u, $ns, $in)['ok'], "⛔ معاوضه رد: {$why}");
    T::same($purBefore, (int)$one("SELECT COUNT(*) FROM biz_invoices WHERE user_id = :u AND kind = 'purchase'", ['u' => $u]), "و ردی (حتی پیش‌نویس) از خرید نماند ({$why})");
}
T::ok(!BizTradeIn::run($u, $walkIn, ['new_name' => 'x', 'imei' => $OLD, 'value' => '100'])['ok'], '⛔ معاوضه روی فاکتورِ گذری رد');
T::ok(!BizTradeIn::run($o, $ns, ['new_name' => 'x', 'imei' => $OLD, 'value' => '100'])['ok'], '⛔ فروشگاهِ دیگر رد');

$r = BizTradeIn::run($u, $ns, ['new_name' => 'آیفون ۱۱ دست‌دوم', 'imei' => $OLD, 'value' => '۳٬۰۰۰٬۰۰۰']);
T::ok($r['ok'], 'معاوضه ثبت شد', $r['message']);
$tp = (int)$r['purchase_id'];
T::same(['purchase', 'issued', $cus], [(string)$one('SELECT kind FROM biz_invoices WHERE id = :i', ['i' => $tp]), (string)$one('SELECT status FROM biz_invoices WHERE id = :i', ['i' => $tp]), (int)$one('SELECT party_id FROM biz_invoices WHERE id = :i', ['i' => $tp])], '⛔ گوشیِ کهنه = فاکتورِ خریدِ واقعی از همان مشتری');
$st = BizSerial::lookup($u, $OLD);
T::same('in', $st['dir'] ?? null, '⛔ گوشیِ کهنه با IMEI در انبار است');
$oldPid = (int)$st['product_id'];
T::same([1.0, 3000000.0], [(float)$one('SELECT stock_qty FROM biz_products WHERE id = :i', ['i' => $oldPid]), (float)$one('SELECT avg_cost FROM biz_products WHERE id = :i', ['i' => $oldPid])], 'موجودی ۱ به بهای ارزشِ معاوضه');
T::same(7000000, (int)$one('SELECT total - paid FROM biz_invoices WHERE id = :i', ['i' => $ns]), '⛔ ماندهِ فاکتورِ فروش ۷ میلیون');
T::same(0, (int)$one('SELECT total - paid FROM biz_invoices WHERE id = :i', ['i' => $tp]), 'فاکتورِ خریدِ معاوضه تسویه است');
T::same($bal0 + 7000000, $partyBal($cus), '⛔ ماندهِ مشتری = قیمتِ نو − ارزشِ کهنه');
$clr = (int)$one("SELECT id FROM biz_accounts WHERE user_id = :u AND kind = 'clearing'", ['u' => $u]);
T::ok($clr > 0, 'صندوقِ تهاتر ساخته شد');
T::same(0, $cashBal($clr), '⛔ صندوقِ تهاتر صفر');
T::same($cash0, $cashBal($cash), '⛔ صندوقِ نقد تکان نخورد');
T::ok(!in_array($clr, array_map(fn($a) => (int)$a['id'], BizDocView::accounts($u)), true), 'تهاتر در منوی انتخابِ صندوق نیست');
T::ok(!BizPay::create($u, ['kind' => 'receipt', 'party_id' => $cus, 'amount' => '5', 'account_id' => $clr, 'pay_date' => '2026-02-01', 'method' => 'cash'])['ok'], '⛔ دریافتِ دستی به صندوقِ تهاتر رد');
T::ok(!BizPay::create($u, ['kind' => 'transfer', 'amount' => '5', 'account_id' => $cash, 'to_account_id' => $clr, 'pay_date' => '2026-02-01'])['ok'], '⛔ انتقال به تهاتر رد');
T::ok(!BizCash::save($u, ['name' => 'دست‌کاری', 'kind' => 'cash', 'opening_balance' => '100'], $clr)['ok'], '⛔ ویرایشِ صندوقِ تهاتر رد');
T::ok(BizLedger::balanced(BizLedger::entries($u, BizReports::ALL_TO)), 'دفترِ دوطرفه تراز');
$rec = BizLedger::reconcile($u);
T::ok($rec['ok'], '⛔ دفتر با صفحه‌ها می‌خواند (صندوق‌ها و طرف‌حساب‌ها)', json_encode($rec, JSON_UNESCAPED_UNICODE));

// ابطالِ یکی ⇒ هر دو
$rp = (int)$one("SELECT id FROM biz_payments WHERE user_id = :u AND kind = 'receipt' AND account_id = :c AND status <> 'void'", ['u' => $u, 'c' => $clr]);
$r = BizPay::void($u, $rp);
T::ok($r['ok'], 'ابطالِ دریافتِ تهاتر', $r['message']);
T::same(0, (int)$one("SELECT COUNT(*) FROM biz_payments WHERE user_id = :u AND account_id = :c AND status <> 'void'", ['u' => $u, 'c' => $clr]), '⛔ جفتش هم باطل شد');
T::same([10000000, 3000000], [(int)$one('SELECT total - paid FROM biz_invoices WHERE id = :i', ['i' => $ns]), (int)$one('SELECT total - paid FROM biz_invoices WHERE id = :i', ['i' => $tp])], 'هر دو فاکتور به ماندهِ کامل برگشتند');
T::same(0, $cashBal($clr), 'تهاتر همچنان صفر');

exit(T::report());
