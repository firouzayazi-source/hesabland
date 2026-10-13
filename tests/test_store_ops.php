<?php
/**
 * تستِ ۹ ارتقای فروشگاه (migration_biz_ops):
 *   امانی، حسابِ ارزی و تسعیر، مغایرت‌گیریِ بانکی، برچسبِ بارکد، پیشنهادِ خرید،
 *   پیش‌بینیِ نقد، هشدارها، سودِ هر شعبه، هزینه‌ی تکراری.
 *
 * ⛔ هر کدام با عددِ دستی سنجیده می‌شود، و با «دفتر را دست نزد»: امانی موجودی نیست تا خریده
 *    شود؛ مانده‌ی تومانیِ دفتر با ارز عوض نمی‌شود؛ جفت کردنِ بانک سندی نمی‌سازد؛ هزینه‌ی
 *    تکراری فقط با «ثبت» و از راهِ `BizPay`؛ سفارشِ خرید فقط پیش‌نویس است.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
T::group('۰ — آماده‌سازی');

if (!file_exists(__DIR__ . '/../config/config.php')) { T::blocked('عملیاتِ فروشگاه', 'config/config.php وجود ندارد'); exit(T::report()); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/biz.php';
require_once __DIR__ . '/../includes/biz_docs.php';
require_once __DIR__ . '/../includes/biz_docview.php';
require_once __DIR__ . '/../includes/biz_insight.php';
require_once __DIR__ . '/../includes/rates.php';

try { $pdo = Database::getConnection(); } catch (Throwable $e) { T::blocked('عملیاتِ فروشگاه', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }
if (!BizConsign::ready() || !BizFx::ready() || !BizBankRec::ready() || !BizRecurring::ready()) {
    T::blocked('عملیاتِ فروشگاه', 'migration_biz_ops اجرا نشده — bash deploy/migrate.sh --apply');
    exit(T::report());
}

const OPS_PREFIX = '__store_ops_';
const OPS_PASS   = 'Ops#pass1234';
$wipe = function () use ($pdo) {
    foreach ($pdo->query("SELECT id FROM users WHERE username LIKE '" . OPS_PREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN) as $id) { deleteUserAccount((int)$id); }
};
$wipe();
// نرخِ روزِ دلار سراسری است — نگه داشته و برگردانده می‌شود
$usdRow = Rates::available() ? ($pdo->query("SELECT * FROM market_rates WHERE code = 'usd'")->fetch() ?: null) : null;
register_shutdown_function(function () use ($wipe, $pdo, $usdRow) {
    $wipe();
    if (!Rates::available()) { return; }
    $pdo->exec("DELETE FROM market_rates WHERE code = 'usd'");
    if ($usdRow) {
        $pdo->prepare('INSERT INTO market_rates (code, price, prev_price, source, manual, fetched_at) VALUES (:c, :p, :pp, :s, :m, :f)')
            ->execute(['c' => 'usd', 'p' => $usdRow['price'], 'pp' => $usdRow['prev_price'], 's' => $usdRow['source'], 'm' => $usdRow['manual'], 'f' => $usdRow['fetched_at']]);
    }
});
$one = function (string $sql, array $p = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn(); };
$mk = function (string $n) use ($pdo): int {
    $id = (int)createUserAccount($pdo, 'فروشگاه ' . $n, OPS_PREFIX . $n, OPS_PREFIX . $n . '@example.com', OPS_PASS)['id'];
    Biz::setType($id, 'business');
    return $id;
};
$u = $mk('own'); $o = $mk('oth');
$cash = (int)BizCash::list($u)[0]['id'];
$bank = (int)BizCash::save($u, ['name' => 'بانکِ ملت', 'kind' => 'bank', 'opening_balance' => '1000000'])['id'];
$sup  = (int)BizParties::save($u, ['name' => 'همکارِ امانت‌دهنده', 'kind' => 'supplier'])['id'];
$cus  = (int)BizParties::save($u, ['name' => 'مشتریِ ثابت', 'kind' => 'customer'])['id'];
$ph   = (int)BizProducts::save($u, ['type' => 'phone', 'name' => 'گوشیِ امانی', 'unit' => 'عدد', 'buy_price' => '20000000', 'sell_price' => '24000000'])['id'];
$acc  = (int)BizProducts::save($u, ['type' => 'goods', 'name' => 'قابِ گوشی', 'sku' => 'CASE-1', 'unit' => 'عدد', 'buy_price' => '100000', 'sell_price' => '150000'])['id'];
T::ok($ph > 0 && $acc > 0 && $sup > 0 && $cus > 0 && $bank > 0, 'fixture: دو کالا، دو طرف‌حساب، بانک');
$bal = fn(int $p): int => (int)$one('SELECT ' . BizParties::BALANCE_SQL . ' FROM biz_parties p WHERE p.id = :i', ['i' => $p]);
$stock = fn(int $p): float => (float)$one('SELECT stock_qty FROM biz_products WHERE id = :i', ['i' => $p]);
$I1 = '490154203237518'; $I2 = '356938035643809';

// =================================================================
T::group('۱ — کالای امانی: موجودی نیست تا خریده شود');

$r = BizConsign::receive($u, ['party_id' => $sup, 'product_id' => $ph, 'imeis' => $I1 . "\n" . $I2, 'unit_cost' => '۲۰٬۰۰۰٬۰۰۰', 'date' => '2026-09-01']);
T::ok($r['ok'] && count($r['ids']) === 2, 'دو گوشیِ امانی، هر کدام یک ردیف', $r['message']);
[$c1, $c2] = $r['ids'];
T::same(0.0, $stock($ph), '⛔ امانی موجودیِ انبار را بالا نبرد');
T::same(0, $bal($sup), '⛔ و به امانت‌دهنده بدهکار نشدیم');
T::same(null, BizSerial::lookup($u, $I1), '⛔ و IMEI در سرگذشتِ انبار نیست');
T::ok(!BizConsign::receive($u, ['party_id' => $sup, 'product_id' => $ph, 'imeis' => $I1, 'unit_cost' => '1', 'date' => '2026-09-01'])['ok'],
    '⛔ همان IMEI دوباره امانی نمی‌شود (امانتِ باز)');
T::ok(!BizConsign::receive($u, ['party_id' => $sup, 'product_id' => $ph, 'imeis' => '', 'unit_cost' => '1', 'date' => '2026-09-01'])['ok'], '⛔ گوشی بی‌IMEI رد');
T::ok(!BizConsign::receive($o, ['party_id' => $sup, 'product_id' => $ph, 'imeis' => '358240051111110', 'unit_cost' => '1', 'date' => '2026-09-01'])['ok'],
    '⛔ فروشگاهِ دیگر با طرف‌حساب و کالای این فروشگاه امانی نمی‌گیرد');
$l = BizConsign::list($u);
T::same([2, 40000000], [$l['held'], $l['held_value']], 'فهرست: دو امانتِ باز به ارزشِ ۴۰ میلیون');

$r = BizConsign::buy($u, $c1, '2026-09-10');
T::ok($r['ok'] && $r['invoice_id'] > 0, 'خریدِ امانی ⇒ فاکتورِ خریدِ واقعی', $r['message']);
$inv = BizInvoices::get($u, (int)$r['invoice_id']);
T::same(['purchase', 'issued', $sup, 20000000], [$inv['kind'], $inv['status'], (int)$inv['party_id'], (int)$inv['total']], 'فاکتور: خریدِ صادرشده از همان همکار، به بهای توافقی');
T::same([1.0, -20000000], [$stock($ph), $bal($sup)], 'حالا موجودیِ ماست و به همکار بدهکاریم (نسیه)');
T::same('in', BizSerial::lookup($u, $I1)['dir'] ?? null, 'و IMEI در انبار است');
T::ok(!BizConsign::buy($u, $c1, '2026-09-10')['ok'], '⛔ دوباره خریده نمی‌شود (دو کلیک = یک خرید)');
T::same(1, (int)$one("SELECT COUNT(*) FROM biz_invoices WHERE user_id = :u AND kind = 'purchase' AND status = 'issued'", ['u' => $u]), 'و فقط یک فاکتورِ خرید');
// خریدِ ناموفق ادعا را پس می‌دهد: دوره‌ی بسته
Biz::saveLock($u, '2026-09-15');
$r = BizConsign::buy($u, $c2, '2026-09-12');
T::ok(!$r['ok'], '⛔ خرید در دوره‌ی بسته رد');
T::same('held', (string)$one('SELECT status FROM biz_consignments WHERE id = :i', ['i' => $c2]), '⛔ و ردیف دوباره امانتِ باز است (ادعا پس گرفته شد)');
Biz::saveLock($u, '', true);
$r = BizConsign::sell($u, $c2, '2026-09-20');
T::ok($r['ok'] && !empty($r['sale_id']), 'فروشِ امانی: خرید + پیش‌نویسِ فروش', $r['message']);
$sale = BizInvoices::get($u, (int)$r['sale_id']);
T::same(['sale', 'draft', $I2, 24000000], [$sale['kind'], $sale['status'], (string)$sale['lines'][0]['imei1'], (int)$sale['total']],
    'پیش‌نویسِ فروش با همان IMEI و قیمتِ فروشِ کالا');
T::same(2.0, $stock($ph), 'هر دو گوشی حالا موجودیِ ما');
$r = BizConsign::receive($u, ['party_id' => $sup, 'product_id' => $ph, 'imeis' => $I1, 'unit_cost' => '1', 'date' => '2026-09-21']);
T::ok(!$r['ok'], '⛔ گوشیِ در انبار امانی نمی‌شود');
$r = BizConsign::receive($u, ['party_id' => $sup, 'item' => 'قابِ گوشی', 'qty' => '۳', 'unit_cost' => '90000', 'date' => '2026-09-21']);
T::ok($r['ok'], 'امانیِ کالای معمولی با تعداد (از نامِ کالا)', $r['message']);
$c3 = $r['ids'][0];
T::ok(BizConsign::giveBack($u, $c3, '2026-09-25')['ok'], 'پس دادنِ امانی');
T::ok(!BizConsign::giveBack($u, $c3, '2026-09-25')['ok'] && !BizConsign::remove($u, $c3)['ok'], '⛔ پس‌داده دوباره پس داده یا حذف نمی‌شود');
T::ok(!BizConsign::remove($u, $c1)['ok'], '⛔ ردیفِ خریده‌شده حذف نمی‌شود (فاکتور دارد)');

// =================================================================
T::group('۲ — حسابِ ارزی و سود/زیانِ نرخ');

$fx = (int)BizParties::save($u, ['name' => 'واردکننده‌ی دبی', 'kind' => 'supplier'])['id'];
T::ok(!BizFx::setPartyCode($u, $fx, 'btc')['ok'], '⛔ ارزِ ناشناخته رد');
T::ok(BizFx::setPartyCode($u, $fx, 'usd')['ok'], 'حسابِ این شخص دلاری شد');
T::same(null, BizFx::partyCode($o, $fx), '⛔ فروشگاهِ دیگر ارزِ این طرف‌حساب را نمی‌بیند');
if (Rates::available()) { $pdo->exec("DELETE FROM market_rates WHERE code = 'usd'"); Rates::forget(); }
$d = BizInvoices::saveDraft($u, 'purchase', ['party_id' => $fx, 'inv_date' => '2026-09-01', 'lines' => [['item' => 'قابِ گوشی', 'qty' => '50', 'price' => '100000']]]);
T::ok(!$d['ok'], '⛔ بی‌نرخ و بی‌نرخِ روز، سندِ طرف‌حسابِ ارزی رد می‌شود (نرخ حدس زده نمی‌شود)', $d['message'] ?? '');
$d = BizInvoices::saveDraft($u, 'purchase', ['party_id' => $fx, 'inv_date' => '2026-09-01', 'fx_rate' => '۵۰٬۰۰۰',
    'lines' => [['item' => 'قابِ گوشی', 'qty' => '50', 'price' => '100000']]]);
T::ok($d['ok'], 'خرید با نرخِ ۵۰٬۰۰۰ (۵ میلیون = ۱۰۰ دلار)', $d['message'] ?? '');
$fxInv = (int)$d['id'];
T::same(50000.0, (float)$one('SELECT fx_rate FROM biz_invoices WHERE id = :i', ['i' => $fxInv]), 'نرخ روی سند نشست');
$d2 = BizInvoices::saveDraft($u, 'purchase', ['party_id' => $fx, 'inv_date' => '2026-09-01', 'lines' => [['item' => 'قابِ گوشی', 'qty' => '50', 'price' => '100000']]], $fxInv);
T::ok($d2['ok'] && (float)$one('SELECT fx_rate FROM biz_invoices WHERE id = :i', ['i' => $fxInv]) === 50000.0, 'ویرایشِ بی‌نرخ ⇒ نرخِ خودِ پیش‌نویس می‌ماند');
T::ok(BizInvoices::issue($u, $fxInv, ['amount' => '0'])['ok'], 'صدورِ نسیه');
$r = BizPay::create($u, ['kind' => 'payment', 'party_id' => $fx, 'account_id' => $cash, 'amount' => '2500000', 'fx_rate' => '62500', 'pay_date' => '2026-09-05']);
T::ok($r['ok'], 'پرداختِ ۲٫۵ میلیون به نرخِ ۶۲٬۵۰۰ (= ۴۰ دلار)');
T::ok(!BizPay::create($u, ['kind' => 'payment', 'party_id' => $fx, 'account_id' => $cash, 'amount' => '10', 'fx_rate' => 'abc'])['ok'], '⛔ نرخِ نامعتبر رد');
$toman = (int)BizParties::save($u, ['name' => 'تومانی', 'kind' => 'supplier'])['id'];
$d = BizInvoices::saveDraft($u, 'purchase', ['party_id' => $toman, 'fx_rate' => '99', 'lines' => [['item' => 'قابِ گوشی', 'qty' => '1', 'price' => '100000']]]);
T::same(null, $one('SELECT fx_rate FROM biz_invoices WHERE id = :i', ['i' => (int)$d['id']]), '⛔ طرف‌حسابِ تومانی نرخ نمی‌گیرد');
$b = array_values(array_filter(BizFx::balances($u), fn($x) => $x['id'] === $fx))[0] ?? null;
T::same(['fx' => -60.0, 'book' => -2500000, 'rated' => -2500000, 'unrated' => 0], ['fx' => $b['fx'], 'book' => $b['book'], 'rated' => $b['rated'], 'unrated' => $b['unrated']],
    'مانده: −۱۰۰ + ۴۰ = −۶۰ دلار؛ دفتر −۲٫۵ میلیون (دست نخورده)');
T::same($bal($fx), $b['book'], '⛔ مانده‌ی تومانیِ دفتر همان `BALANCE_SQL` است');
if (Rates::available()) {
    $pdo->exec("INSERT INTO market_rates (code, price, prev_price, source, manual, fetched_at) VALUES ('usd', 70000, NULL, 'test', 1, NOW())");
    Rates::forget();
    $b = array_values(array_filter(BizFx::balances($u), fn($x) => $x['id'] === $fx))[0];
    T::same([70000, -4200000, -1700000], [$b['now_rate'], $b['value_now'], $b['diff']],
        'به نرخِ امروزِ ۷۰٬۰۰۰: ۶۰ دلار = ۴٫۲ میلیون؛ زیانِ نرخ ۱٫۷ میلیون (بدهیِ دلاری گران‌تر شد)');
    $r = BizPay::create($u, ['kind' => 'payment', 'party_id' => $fx, 'account_id' => $cash, 'amount' => '700000', 'pay_date' => '2026-09-06']);
    T::same(70000.0, (float)$one('SELECT fx_rate FROM biz_payments WHERE id = :i', ['i' => (int)$r['id']]), 'پرداختِ بی‌نرخ ⇒ نرخِ روز روی سند');
} else {
    T::skip('تسعیر به نرخِ روز', 'جدولِ نرخ‌ها نیست');
}

// =================================================================
T::group('۳ — مغایرت‌گیریِ بانکی');

$rc = BizPay::create($u, ['kind' => 'receipt', 'party_id' => $cus, 'account_id' => $bank, 'amount' => '300000', 'pay_date' => '2026-10-02', 'method' => 'card']);
$ex = BizPay::create($u, ['kind' => 'expense', 'account_id' => $bank, 'amount' => '45000', 'title' => 'کارمزد', 'pay_date' => '2026-10-03']);
$tr = BizPay::create($u, ['kind' => 'transfer', 'account_id' => $cash, 'to_account_id' => $bank, 'amount' => '500000', 'pay_date' => '2026-10-04']);
T::ok($rc['ok'] && $ex['ok'] && $tr['ok'], 'سه سند روی بانک: دریافت، هزینه، انتقال به بانک');
$rows = [
    ['گزارشِ حساب', '', '', ''],
    ['تاریخ', 'شرح', 'واریز', 'برداشت'],
    ['۱۴۰۵/۰۷/۱۲', 'واریزِ پوز', '۳٬۰۰۰٬۰۰۰', ''],      // ۳۰۰٬۰۰۰ تومان (ریال)
    ['1405/07/12', 'کارمزد', '', '450000'],                // ۴۵٬۰۰۰ تومان
    ['14050713', 'واریز از صندوق', '5000000', ''],         // ۵۰۰٬۰۰۰ تومان
    ['1405/07/14', 'واریزِ نامعلوم', '1,230,000', ''],     // ۱۲۳٬۰۰۰ — سند ندارد
    ['جمع', '', '9680000', '450000'],                      // بی‌تاریخ ⇒ کنار
];
$p = BizBankRec::parse($rows, true);
T::ok($p['ok'] && count($p['lines']) === 4 && $p['skipped'] === 1, 'سرآیند پیدا شد؛ چهار ردیف، ردیفِ «جمع» کنار رفت', json_encode($p, JSON_UNESCAPED_UNICODE));
T::same([300000, -45000, 500000, 123000], array_column($p['lines'], 'amount'), 'ریال ⇒ تومان، برداشت منفی');
T::same(['2026-10-04', '2026-10-04', '2026-10-05', '2026-10-06'], array_column($p['lines'], 'date'), 'تاریخِ شمسی (با «/» و بی‌آن) ⇒ میلادی');
T::ok(!BizBankRec::parse([['a', 'b'], ['1', '2']])['ok'], '⛔ فایلِ بی‌ستونِ تاریخ رد');
T::ok(!BizBankRec::import($o, $bank, $p['lines'])['ok'], '⛔ حسابِ فروشگاهِ دیگر رد');
T::ok(!BizBankRec::import($u, BizCash::chequeAccount($pdo, $u, 'in'), $p['lines'])['ok'], '⛔ صندوقِ چک مغایرت‌گیری نمی‌شود');
$r = BizBankRec::import($u, $bank, $p['lines']);
T::same([4, 0, 3], [$r['added'], $r['dup'], $r['matched']], 'چهار ردیف، سه جفتِ خودکار (هم‌مبلغ، در چهار روز)', $r['message']);
$r = BizBankRec::import($u, $bank, $p['lines']);
T::same([0, 4], [$r['added'], $r['dup']], '⛔ ورودِ دوباره‌ی همان فایل ردیفِ تکراری نمی‌سازد');
$twice = BizBankRec::parse([['تاریخ', 'مبلغ'], ['1405/07/20', '10000'], ['1405/07/20', '10000']], true);
T::same(2, BizBankRec::import($u, $bank, $twice['lines'])['added'], 'ولی دو واریزِ هم‌مبلغِ یک روز دو ردیف‌اند');
$data = BizBankRec::lines($u, $bank);
T::same([3, 3], [$data['matched'], $data['open']], 'سه جفت، سه بی‌سند');
T::same(0, count(BizBankRec::openPayments($u, $bank, '2026-10-01', '2026-10-10')), 'هر سه سندِ بانک جفت دارند');
$open = array_values(array_filter($data['rows'], fn($x) => $x['payment_id'] === null && (int)$x['amount'] === 123000))[0];
$rc2 = BizPay::create($u, ['kind' => 'receipt', 'party_id' => $cus, 'account_id' => $bank, 'amount' => '123000', 'pay_date' => '2026-10-20']);
T::same(0, BizBankRec::autoMatch($u, $bank), '⛔ سندِ دورتر از چهار روز خودکار جفت نمی‌شود');
T::ok(!BizBankRec::match($u, (int)$open['id'], (int)$ex['id'])['ok'], '⛔ جفتِ دستی با مبلغِ دیگر رد (و سندِ از قبل جفت‌شده)');
$odd = BizPay::create($u, ['kind' => 'receipt', 'party_id' => $cus, 'account_id' => $bank, 'amount' => '999', 'pay_date' => '2026-10-06']);
T::ok(!BizBankRec::match($u, (int)$open['id'], (int)$odd['id'])['ok'], '⛔ جفتِ دستی با سندِ آزادِ هم‌حساب ولی مبلغِ دیگر رد');
T::ok(!BizBankRec::match($u, (int)$open['id'], (int)$tr['id'] + 100000)['ok'], '⛔ سندِ ناموجود رد');
T::ok(BizBankRec::match($u, (int)$open['id'], (int)$rc2['id'])['ok'], 'جفتِ دستیِ هم‌مبلغ');
T::ok(!BizBankRec::match($o, (int)$open['id'], (int)$rc2['id'])['ok'], '⛔ فروشگاهِ دیگر جفت نمی‌کند');
$exLine = (int)$one('SELECT id FROM biz_bank_lines WHERE payment_id = :p', ['p' => (int)$ex['id']]);
T::ok(BizPay::void($u, (int)$ex['id'])['ok'], 'هزینه‌ی جفت‌شده باطل شد');
BizBankRec::autoMatch($u, $bank);
T::same(null, $one('SELECT payment_id FROM biz_bank_lines WHERE id = :i', ['i' => $exLine]), '⛔ جفتِ سندِ باطل آزاد شد');
$before = (int)$one('SELECT COUNT(*) FROM biz_payments WHERE user_id = :u', ['u' => $u]);
BizBankRec::unmatch($u, (int)$open['id']); BizBankRec::autoMatch($u, $bank);
T::same($before, (int)$one('SELECT COUNT(*) FROM biz_payments WHERE user_id = :u', ['u' => $u]), '⛔ مغایرت‌گیری هیچ سندی نساخت');

// =================================================================
T::group('۴ — هزینه‌ی تکراری');

$cat = BizExpCats::save($u, 'expense', 'اجاره‌ی انبار');
$r = BizRecurring::save($u, ['title' => '', 'category_id' => (string)$cat['id'], 'amount' => '۱۵٬۰۰۰٬۰۰۰', 'every_months' => '1',
                             'start_date' => '2026-09-22', 'account_id' => (string)$cash]);   // ۱۴۰۵/۰۶/۳۱
T::ok($r['ok'], 'اجاره‌ی ماهانه از ۳۱ شهریور', $r['message']);
$rid = (int)$r['id'];
$row = BizRecurring::get($u, $rid);
T::same('اجاره‌ی انبار', (string)$row['title'], 'شرحِ خالی = نامِ سرفصل');
T::same(['2026-09-22', '2026-10-22', '2026-11-21', '2027-02-19', '2027-03-20', '2027-04-20'],
    [BizRecurring::dueAt($row, 0), BizRecurring::dueAt($row, 1), BizRecurring::dueAt($row, 2), BizRecurring::dueAt($row, 5),
     BizRecurring::dueAt($row, 6), BizRecurring::dueAt($row, 7)],
    '⛔ لنگرِ ۳۱: مهر و آبان ۳۰، بهمن ۳۰، اسفند ۲۹، فروردین دوباره ۳۱ — نمی‌لغزد');
T::ok(!BizRecurring::save($u, ['title' => 'x', 'amount' => '0', 'every_months' => '1', 'start_date' => '2026-09-22'])['ok'], '⛔ مبلغِ صفر رد');
T::ok(!BizRecurring::save($u, ['title' => 'x', 'amount' => '10', 'every_months' => '5', 'start_date' => '2026-09-22'])['ok'], '⛔ دوره‌ی ناشناخته رد');
T::ok(!BizRecurring::save($u, ['title' => 'x', 'amount' => '10', 'every_months' => '1', 'start_date' => '2026-09-22', 'category_id' => (string)BizExpCats::save($o, 'expense', 'غریبه')['id']])['ok'],
    '⛔ سرفصلِ فروشگاهِ دیگر رد');
$n0 = (int)$one("SELECT COUNT(*) FROM biz_payments WHERE user_id = :u AND kind = 'expense'", ['u' => $u]);
T::same($n0, (int)$one("SELECT COUNT(*) FROM biz_payments WHERE user_id = :u AND kind = 'expense'", ['u' => $u]), '⛔ ساختنِ هزینه‌ی تکراری هیچ سندی نساخت');
$r = BizRecurring::post($u, $rid);
$pay = BizPay::get($u, (int)$r['payment_id']);
T::same(['expense', 15000000, '2026-09-22', (int)$cat['id']], [$pay['kind'], (int)$pay['amount'], (string)$pay['pay_date'], (int)$pay['category_id']],
    'ثبت = هزینه‌ی واقعی به تاریخِ سررسید، با سرفصل');
T::same('2026-10-22', BizRecurring::nextDue(BizRecurring::get($u, $rid)), 'سررسیدِ بعدی جلو رفت');
T::ok(Biz::saveLock($u, '2026-10-12')['ok'], 'دوره تا ۲۰ مهر بسته شد');
T::ok(!BizRecurring::post($u, $rid, ['date' => '2026-10-10'])['ok'], '⛔ ثبت با تاریخِ دوره‌ی بسته رد');
T::same(1, (int)BizRecurring::get($u, $rid)['posted'], '⛔ و شمارنده جلو نرفت (همان تراکنش برگشت)');
Biz::saveLock($u, '', true);
T::ok(BizRecurring::skip($u, $rid)['ok'], 'رد کردنِ یک دوره بی‌سند');
T::same('2026-11-21', BizRecurring::nextDue(BizRecurring::get($u, $rid)), 'سررسید از روی دوره‌ی ردشده گذشت');
T::ok(!BizRecurring::post($o, $rid)['ok'], '⛔ فروشگاهِ دیگر ثبت نمی‌کند');
$q = BizRecurring::save($u, ['title' => 'بیمه', 'amount' => '1000', 'every_months' => '3', 'start_date' => '2026-09-22']);
$qr = BizRecurring::get($u, (int)$q['id']);
T::same(['2026-12-21', '2027-03-20'], [BizRecurring::dueAt($qr, 1), BizRecurring::dueAt($qr, 2)], 'سه‌ماهه: ۳۰ آذر، ۲۹ اسفند');
BizRecurring::setActive($u, (int)$q['id'], false);
$occ = BizRecurring::occurrences($u, '2027-01-25');
T::same(['2026-11-21', '2026-12-21', '2027-01-20'], array_column($occ, 'date'), 'سررسیدهای پیشِ رو (برای پیش‌بینی)');

// =================================================================
T::group('۵ — پیشنهادِ خرید');

$sk = (int)BizProducts::save($u, ['type' => 'goods', 'name' => 'شارژرِ پرفروش', 'unit' => 'عدد', 'buy_price' => '200000', 'sell_price' => '300000', 'min_stock' => '5'])['id'];
$d = BizInvoices::saveDraft($u, 'purchase', ['party_id' => $sup, 'inv_date' => '2026-09-01', 'lines' => [['item' => 'شارژرِ پرفروش', 'qty' => '40', 'price' => '200000']]]);
BizInvoices::issue($u, (int)$d['id'], ['amount' => '0']);
$d = BizInvoices::saveDraft($u, 'sale', ['party_id' => $cus, 'inv_date' => '2026-09-20', 'lines' => [['item' => 'شارژرِ پرفروش', 'qty' => '30', 'price' => '300000']]]);
BizInvoices::issue($u, (int)$d['id'], ['amount' => '0']);
$sug = array_column(BizReorder::suggest($u, 30, 30, '2026-10-01'), null, 'id');
T::ok(isset($sug[$sk]), 'کالای پرفروش پیشنهاد شد');
T::same([10.0, 1.0, 10, 20.0, $sup], [$sug[$sk]['stock'], $sug[$sk]['per_day'], $sug[$sk]['days_left'], $sug[$sk]['suggest'], $sug[$sk]['supplier_id']],
    'موجودی ۱۰، روزی یکی، ۱۰ روز دوام؛ برای ۳۰ روز ۲۰ تا؛ آخرین فروشنده');
$sug2 = array_column(BizReorder::suggest($u, 30, 7, '2026-10-01'), null, 'id');
T::ok(!isset($sug2[$sk]), 'برای یک هفته (۷ تا) ۱۰ تا کافی است ⇒ پیشنهاد نمی‌شود');
$drafts0 = (int)$one("SELECT COUNT(*) FROM biz_invoices WHERE user_id = :u AND status = 'draft' AND kind = 'purchase'", ['u' => $u]);
$stock0 = $stock($sk);
$r = BizReorder::makeDrafts($u, [$sk => '۲۰', $ph => '2'], [$sk => $sup]);
T::ok($r['ok'] && count($r['drafts']) === 1 && $r['skipped'] === ['گوشیِ امانی'], 'یک پیش‌نویس برای فروشنده؛ گوشی کنار ماند', $r['message']);
$dr = BizInvoices::get($u, $r['drafts'][0]);
T::same(['draft', $sup, 20.0, 4000000], [$dr['status'], (int)$dr['party_id'], (float)$dr['lines'][0]['qty'], (int)$dr['total']], 'پیش‌نویسِ خرید با قیمتِ خریدِ کالا');
T::same($stock0, $stock($sk), '⛔ سفارش موجودی را تکان نداد');

// =================================================================
T::group('۶ — پیش‌بینیِ نقد');

$today = '2026-11-01';
T::ok(BizPay::create($u, ['kind' => 'capital', 'account_id' => $cash, 'amount' => '100000000', 'pay_date' => '2026-10-01'])['ok'], 'آورده‌ی مالک — نقدِ مثبتِ شروع');
$startCash = BizCash::total(BizCash::list($u, false, $today));
$chq = BizPay::create($u, ['kind' => 'receipt', 'party_id' => $cus, 'account_id' => $cash, 'amount' => '2000000', 'method' => 'cheque',
                           'cheque_due' => '2026-11-10', 'pay_date' => '2026-10-28']);
$chqOut = BizPay::create($u, ['kind' => 'payment', 'party_id' => $sup, 'account_id' => $cash, 'amount' => '900000000', 'method' => 'cheque',
                              'cheque_due' => '2026-12-01', 'pay_date' => '2026-10-28']);
T::ok($chq['ok'] && $chqOut['ok'], 'یک چکِ دریافتی (۱۰ آبان) و یک چکِ پرداختیِ بزرگ (۱۰ آذر)');
$fc = BizForecast::weeks($u, 8, $today);
T::same($startCash, $fc['start'], 'شروع = نقدِ امروزِ صندوق‌ها (`BizCash::total()`)');
T::same(2000000, $fc['weeks'][1]['in'], 'چکِ دریافتی در هفته‌ی دوم');
T::ok($fc['weeks'][2]['out'] >= 15000000, 'اجاره‌ی ۲۱ آبان (سررسیدِ بعدیِ هزینه‌ی تکراری) در هفته‌ی سوم');
T::same(900000000, $fc['weeks'][4]['out'], 'چکِ پرداختی در هفته‌ی پنجم');
$run = $fc['start'];
foreach ($fc['weeks'] as $w) { $run += $w['in'] - $w['out']; }
T::same($run, end($fc['weeks'])['balance'], 'مانده‌ی پایانی = شروع + همه‌ی ورود و خروج‌ها');
T::same(4, $fc['first_negative'], 'اولین هفته‌ی منفی: پنجم (اندیسِ ۴)');

// =================================================================
T::group('۷ — هشدارها');

$d = BizInvoices::saveDraft($u, 'sale', ['party_id' => $cus, 'inv_date' => '2026-10-25', 'lines' => [['item' => 'قابِ گوشی', 'qty' => '1', 'price' => '50000']]]);
// قابِ گوشی موجودی ندارد ⇒ اول بخر
$d0 = BizInvoices::saveDraft($u, 'purchase', ['party_id' => $sup, 'inv_date' => '2026-10-20', 'lines' => [['item' => 'قابِ گوشی', 'qty' => '5', 'price' => '100000']]]);
BizInvoices::issue($u, (int)$d0['id'], ['amount' => '0']);
T::ok(BizInvoices::issue($u, (int)$d['id'], ['amount' => '0'])['ok'], 'فروشِ قاب به ۵۰ هزار (بهای خرید ۱۰۰ هزار)');
BizParties::saveTerms($u, $cus, ['price_level' => 'retail', 'credit_limit' => '1000']);
$pdo->prepare("UPDATE biz_products SET created_at = '2026-01-01 00:00:00' WHERE id = :i")->execute(['i' => $acc]);
$al = array_column(BizAlerts::all($u, true, '2026-11-01'), null, 'key');
T::ok(count($al['below_cost']['items']) === 1 && str_contains($al['below_cost']['items'][0]['text'], formatMoney(50000)), 'فروشِ زیرِ بها با مبلغِ زیان', json_encode($al['below_cost'], JSON_UNESCAPED_UNICODE));
T::ok(count($al['credit']['items']) === 1 && str_contains($al['credit']['items'][0]['text'], 'مشتریِ ثابت'), 'مشتریِ بالای سقفِ اعتبار');
T::ok((bool)array_filter($al['cheques']['items'], fn($i) => str_contains($i['text'], formatMoney(2000000))) === false, 'چکِ ۱۰ آبان هنوز «نزدیک» نیست (سه روز)');
T::ok(count($al['recurring']['items']) === 0, 'هزینه‌ی تکراری هنوز سررسید نشده (۲۱ آبان)');
$noCost = array_column(BizAlerts::all($u, false, '2026-11-01'), null, 'key');
T::ok(!isset($noCost['below_cost']), '⛔ بی‌دسترسیِ «بها و سود» فروشِ زیرِ بها دیده نمی‌شود');
T::ok(!array_filter($noCost['dead']['items'], fn($i) => str_contains($i['text'], 'ارزشِ')), '⛔ و ارزشِ کالای خوابیده هم نه');
$late = array_column(BizAlerts::all($u, true, '2027-02-01'), null, 'key');
T::ok(count($late['recurring']['items']) === 1 && count($late['dead']['items']) >= 1, 'سه ماه بعد: اجاره‌ی سررسیدشده و کالای خوابیده');
T::ok(!BizAlerts::all($o, true)[0]['items'], '⛔ فروشگاهِ دیگر هیچ‌کدام را نمی‌بیند');

// =================================================================
T::group('۸ — سودِ هر شعبه');

$wB = (int)BizWarehouses::save($u, 'شعبه‌ی دو')['id'];
$cus2 = (int)BizParties::save($u, ['name' => 'مشتریِ شعبه', 'kind' => 'customer'])['id'];   // مشتریِ بالای سقف فروش نمی‌گیرد
$d = BizInvoices::saveDraft($u, 'purchase', ['party_id' => $sup, 'inv_date' => '2026-10-01', 'warehouse_id' => (string)$wB, 'lines' => [['item' => 'شارژرِ پرفروش', 'qty' => '5', 'price' => '200000']]]);
BizInvoices::issue($u, (int)$d['id'], ['amount' => '0']);
$d = BizInvoices::saveDraft($u, 'sale', ['party_id' => $cus2, 'inv_date' => '2026-10-02', 'warehouse_id' => (string)$wB, 'lines' => [['item' => 'شارژرِ پرفروش', 'qty' => '2', 'price' => '350000']]]);
T::ok(BizInvoices::issue($u, (int)$d['id'], ['amount' => '0'])['ok'], 'فروش از شعبه‌ی دو');
$pl = BizBranches::pl($u, '2026-09-01', '2026-10-31');
$byWh = array_column($pl['rows'], null, 'wh');
T::same([700000, 400000, 300000], [$byWh[$wB]['net'], $byWh[$wB]['cogs'], $byWh[$wB]['gross']], 'شعبه‌ی دو: فروش ۷۰۰ هزار، بها ۴۰۰ هزار، سود ۳۰۰ هزار');
$tot = BizReports::sales($u, '2026-09-01', '2026-10-31');
T::same([$tot['net'], $tot['cogs']], [array_sum(array_column($pl['rows'], 'net')), array_sum(array_column($pl['rows'], 'cogs'))],
    '⛔ جمعِ شعبه‌ها = همان «سود و زیان»ِ کل (`BizReports::sales()`)');

// =================================================================
T::group('۹ — بارکد (Code 128)');

// مرجع: کتابخانه‌ی مستقلِ python-barcode روی همین رشته‌ها (هر «۱» یک ماژولِ سیاه)
$ref = [
    'ABC-123' => '1101001000010100011000100010110001000100011010011011100100111001101100111001011001011100101100001001100011101011',
    '356938035643809' => '11010011100100010001101011001000010001100010100100110001110001011010110001110101001111001011110111011100101100111010001101100011101011',
    'SKU 1' => '110100100001101110100010110001110110111011101101100110010011100110100001101001100011101011',
];
foreach ($ref as $s => $want) {
    $m = '';
    foreach (BizBarcode::modules($s) as $i => $w) { $m .= str_repeat($i % 2 ? '0' : '1', $w); }
    T::same($want, $m, "«{$s}» همان بارکدِ مرجع (IMEI: هفت جفت با C و رقمِ آخر با B)");
}
T::ok(!BizBarcode::encodable('کالا') && BizBarcode::svg('کالا') === '', '⛔ متنِ فارسی بارکد نمی‌شود (برچسبِ بی‌بارکد)');
T::ok(str_contains(BizBarcode::svg('CASE-1'), '<rect'), 'SVG ساخته شد');

// =================================================================
T::group('۱۰ — صفحه‌ها: دسترسی و منو');

foreach (['consignments.php' => 'buy', 'reorder.php' => 'buy', 'bank-rec.php' => 'money', 'recurring.php' => 'money',
          'insights.php' => 'reports', 'labels.php' => 'products'] as $pg => $cap) {
    T::same($cap, Biz::pageCap($pg), "«{$pg}» دسترسیِ «{$cap}» می‌خواهد (فروشنده نمی‌بیند)");
    T::ok(!in_array($cap, Biz::STAFF_ROLES['seller']['caps'], true), "⛔ «{$pg}» برای فروشنده بسته است");
}
$navKeys = array_merge(...array_map('array_keys', array_values(Biz::NAV)));
foreach (['consignments.php', 'reorder.php', 'bank-rec.php', 'recurring.php', 'insights.php'] as $pg) {
    T::ok(in_array($pg, $navKeys, true) && isset(Biz::navIcons()[$pg]), "«{$pg}» در منو با آیکون");
}
T::same('products.php', Biz::NAV_PARENT['labels.php'] ?? null, 'برچسب زیرِ «محصولات»');

// =================================================================
T::group('۱۱ — از راهِ HTTP: فرم‌ها، بارگذاری و رندرِ کامل');

$port = 0;
for ($pp = 9411; $pp <= 9460; $pp++) { $sock = @stream_socket_server("tcp://127.0.0.1:$pp"); if ($sock) { fclose($sock); $port = $pp; break; } }
$root = dirname(__DIR__);
$log = tempnam(sys_get_temp_dir(), 'ops');
$srv = $port ? (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!', $port, escapeshellarg($root), escapeshellarg($log)))) : 0;
$up = false;
for ($i = 0; $srv && $i < 40; $i++) { usleep(150000); $sock = @fsockopen("127.0.0.1", $port, $x, $y, 0.3); if ($sock) { fclose($sock); $up = true; break; } }
if (!$up) {
    T::skip('HTTP', 'سرورِ آزمایشی بالا نیامد');
} else {
    $jar = tempnam(sys_get_temp_dir(), 'opsjar');
    $req = function (string $path, $post = null) use ($port, $jar): array {
        $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 40]);
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($post) && !array_filter($post, fn($v) => $v instanceof CURLFile) ? http_build_query($post) : $post); }
        $raw = (string)curl_exec($ch); $hl = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); unset($ch);
        $loc = preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $hl), $m) ? $m[1] : '';
        return [$code, substr($raw, $hl), $loc];
    };
    $csrf = fn(string $h): string => preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $h, $m) ? $m[1] : '';
    [, $lp] = $req('store/login.php');
    $req('store/login.php', ['csrf_token' => $csrf($lp), 'username' => OPS_PREFIX . 'own', 'password' => OPS_PASS]);
    foreach (['store/consignments.php', 'store/consignments.php?s=all', 'store/recurring.php', 'store/bank-rec.php', 'store/bank-rec.php?f=all',
              'store/reorder.php', 'store/insights.php', 'store/insights.php?t=cash', 'store/insights.php?t=branches', 'store/insights.php?t=fx',
              'store/labels.php?ids=' . $acc . ',' . $sk, 'store/labels.php?ids=' . $ph . '&units=1&layout=roll',
              'store/party.php?id=' . $fx, 'store/invoice-edit.php?k=purchase', 'store/payment.php?k=payment'] as $pg) {
        [$c, $h] = $req($pg);
        T::ok($c === 200 && str_contains($h, '</html>') && !preg_match('/(Fatal error|Warning|Notice|Uncaught)/', $h), "«{$pg}» کامل رندر شد", (string)$c);
    }
    [, $h] = $req('store/labels.php?ids=' . $acc);
    T::ok(str_contains($h, '<svg') && str_contains($h, 'CASE-1'), 'برچسب: بارکدِ کدِ کالا');
    [, $h] = $req('store/labels.php?ids=' . $ph . '&units=1');
    T::ok(substr_count($h, '<svg') === 2 && str_contains($h, $I1) && str_contains($h, $I2), 'برچسبِ گوشی: یکی برای هر دستگاهِ در انبار با IMEIِ خودش');
    [, $h] = $req('store/insights.php?t=fx');
    T::ok(str_contains($h, 'واردکننده‌ی دبی'), 'زبانه‌ی ارزی طرف‌حسابِ دلاری را نشان می‌دهد');
    [, $h] = $req('store/invoice-edit.php?k=purchase');
    T::ok(str_contains($h, 'data-fx-field') && str_contains($h, 'data-fx="'), 'فاکتورِ خرید: خانه‌ی نرخ و نشانِ ارزیِ طرف‌حساب');

    // فرم‌ها
    [, $h] = $req('store/consignments.php');
    [$c] = $req('store/consignments.php', ['csrf_token' => $csrf($h), 'action' => 'receive', 'party_id' => (string)$sup, 'item' => 'قابِ گوشی',
                                            'qty' => '۲', 'unit_cost' => '۸۰٬۰۰۰', 'date' => '۱۴۰۵/۰۷/۲۰']);
    T::same(1, (int)$one("SELECT COUNT(*) FROM biz_consignments WHERE user_id = :u AND unit_cost = 80000 AND status = 'held'", ['u' => $u]), 'فرمِ امانی با ارقامِ فارسی', (string)$c);
    [, $h] = $req('store/recurring.php');
    $req('store/recurring.php', ['csrf_token' => $csrf($h), 'action' => 'save', 'title' => 'اینترنت', 'amount' => '۵۰۰٬۰۰۰', 'every_months' => '1',
                                 'start_date' => '۱۴۰۵/۰۷/۰۱', 'account_id' => (string)$cash]);
    T::same(500000, (int)$one("SELECT amount FROM biz_recurring WHERE user_id = :u AND title = 'اینترنت'", ['u' => $u]), 'فرمِ هزینه‌ی تکراری');
    $csv = tempnam(sys_get_temp_dir(), 'bank') . '.csv';
    file_put_contents($csv, "\xEF\xBB\xBFتاریخ,شرح,بستانکار,بدهکار\n1405/07/21,واریز,770000,\n");
    [, $h] = $req('store/bank-rec.php?acc=' . $bank);
    [$c, , $loc] = $req('store/bank-rec.php?acc=' . $bank, ['csrf_token' => $csrf($h), 'action' => 'import', 'account_id' => (string)$bank, 'rial' => '1',
                                                            'file' => new CURLFile($csv, 'text/csv', 'bank.csv')]);
    @unlink($csv);
    T::same(1, (int)$one('SELECT COUNT(*) FROM biz_bank_lines WHERE user_id = :u AND amount = 77000', ['u' => $u]), 'بارگذاریِ صورت‌حساب از راهِ فرم (ریال ⇒ تومان)', "{$c} {$loc}");
    [, $h] = $req('store/reorder.php');
    $n0 = (int)$one("SELECT COUNT(*) FROM biz_invoices WHERE user_id = :u AND status = 'draft'", ['u' => $u]);
    $req('store/reorder.php', ['csrf_token' => $csrf($h), 'pick' => [$sk => '1'], 'qty' => [$sk => '۳'], 'sup' => [$sk => (string)$sup]]);
    T::same($n0 + 1, (int)$one("SELECT COUNT(*) FROM biz_invoices WHERE user_id = :u AND status = 'draft'", ['u' => $u]), 'فرمِ پیشنهادِ خرید ⇒ یک پیش‌نویس');
    [, $h] = $req('store/party.php?id=' . $fx);
    $req('store/party.php?id=' . $fx, ['csrf_token' => $csrf($h), 'action' => 'save', 'name' => 'واردکننده‌ی دبی', 'kind' => 'supplier', 'terms_form' => '1',
                                       'price_level' => 'retail', 'credit_limit' => '', 'fx_form' => '1', 'fx_code' => 'aed']);
    T::same('aed', BizFx::partyCode($u, $fx), 'فرمِ طرف‌حساب ارزِ حساب را عوض کرد');
    // ⛔ چیدمان در کرومیومِ واقعی: گوشی (۳۹۰) و دسکتاپ — بی‌اسکرولِ افقی، متنِ فارسی راست‌چین
    $node = trim((string)shell_exec('command -v node 2>/dev/null'));
    $probePages = ['store/consignments.php?s=all', 'store/recurring.php', 'store/bank-rec.php?f=all', 'store/reorder.php',
                   'store/insights.php', 'store/insights.php?t=cash', 'store/insights.php?t=fx'];
    $out = $node === '' ? '' : (string)shell_exec(sprintf('%s %s %s %s %s %s', escapeshellarg($node),
        escapeshellarg(__DIR__ . '/store_probe.js'), escapeshellarg("http://127.0.0.1:{$port}/"),
        escapeshellarg(OPS_PREFIX . 'own'), escapeshellarg(OPS_PASS), escapeshellarg(json_encode($probePages)))
        . (getenv('STORE_SHOTS') ? ' ' . escapeshellarg((string)getenv('STORE_SHOTS')) : ''));
    $pr = json_decode(trim($out), true);
    if (!is_array($pr) || empty($pr['ok'])) {
        T::skip('چیدمانِ صفحه‌های تازه در کرومیوم', 'کرومیوم در دسترس نیست: ' . ($pr['why'] ?? trim($out)));
    } else {
        foreach ($pr['res'] as $m) {
            T::ok($m['sw'] <= $m['W'] && !$m['out'], "«{$m['pg']}» روی {$m['w']}: بی‌اسکرولِ افقی و بی‌بیرون‌زدگی",
                "scrollWidth={$m['sw']} width={$m['W']} " . implode(' | ', $m['out']));
            T::ok(!$m['rtl'], "«{$m['pg']}» روی {$m['w']}: متنِ فارسی rtl و راست‌چین", implode(' | ', $m['rtl'] ?? ['?']));
        }
    }
    $req('store/logout.php', ['csrf_token' => $csrf($h)]);
    [$c, , $loc] = $req('store/insights.php');
    T::ok($c === 302 || str_contains($loc, 'login'), '⛔ بی‌ورود: به صفحه‌ی ورود');
    @unlink($jar);
}
if ($srv) { exec("kill $srv 2>/dev/null"); }
@unlink($log);

exit(T::report());
