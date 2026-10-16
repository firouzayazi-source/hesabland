<?php
/**
 * ⛔ دفاترِ قانونی — روزنامه و کل (`BizLegalBooks`).
 *
 * **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵):** دفترِ روزنامه و کل به شکلِ دفاترِ قانونی.
 *
 *   ۱. افتتاحیه = مانده‌ی حساب‌های دائمی پیش از دوره + خالصِ سود و زیان به «انباشته»؛ تراز.
 *   ۲. یک سند در روز، ردیف به تفکیکِ حسابِ کل، شماره‌ی پیاپی، هر سند تراز.
 *   ۳. دفترِ کل: مانده‌ی پایانیِ هر حسابِ دائمی = ترازِ آزمایشیِ همان روز (یک منبع).
 *   ۴. صفحه‌بندی با نقلِ جمع؛ CSV.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_docs.php';
require_once __DIR__ . '/../includes/biz_acc.php';
require_once __DIR__ . '/../includes/biz_print.php';

const LPREFIX = '__blb_';

T::group('۰ — صفحه‌بندی (بی‌دیتابیس)');
$rows = array_map(fn($i) => ['dr' => $i, 'cr' => 0], range(1, 7));
$pg = BizLegalBooks::pages($rows, 3);
T::same(3, count($pg), 'هفت ردیف، سه‌تایی ⇒ سه صفحه');
T::same(['dr' => 6, 'cr' => 0], $pg[1]['carry_in'], 'نقل از صفحه‌ی قبل = جمعِ صفحه‌ی اول');
T::same(['dr' => 28, 'cr' => 0], $pg[2]['carry_out'], 'جمعِ کلِ صفحه‌ی آخر');
T::same(1, count(BizLegalBooks::pages([], 3)), 'دفترِ خالی هم یک صفحه دارد');

try { $pdo = Database::getConnection(); }
catch (Throwable $e) { T::blocked('دفاترِ قانونی', 'اتصال به دیتابیس برقرار نشد: ' . $e->getMessage()); exit(T::report()); }
if (!Biz::available() || !Biz::accReady()) { T::blocked('دفاترِ قانونی', 'جدول‌ها نیست — اول: bash deploy/migrate.sh --apply'); exit(T::report()); }

$wipe = function () use ($pdo) {
    foreach ($pdo->query("SELECT id FROM users WHERE username LIKE '" . LPREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        foreach (['biz_allocations', 'biz_payments', 'biz_stock_moves'] as $t) { $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]); }
        $pdo->prepare('DELETE FROM biz_invoices WHERE user_id = :u AND ref_invoice_id IS NOT NULL')->execute(['u' => $id]);
        foreach (['biz_invoices', 'biz_products', 'biz_categories', 'biz_parties', 'biz_accounts', 'biz_settings'] as $t) {
            $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]);
        }
        foreach (userDataTables() as $t) { try { $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]); } catch (PDOException $e) { } }
        $pdo->prepare('DELETE FROM audit_log WHERE target_user_id = :u')->execute(['u' => $id]);
    }
    $pdo->exec("DELETE FROM users WHERE username LIKE '" . LPREFIX . "%'");
};
$wipe();
createUserAccount($pdo, 'فروشگاهِ دفتر', LPREFIX . 'a', LPREFIX . 'a@example.com', 'Book12345');
$st = $pdo->prepare('SELECT id FROM users WHERE username = :u'); $st->execute(['u' => LPREFIX . 'a']);
$u = (int)$st->fetchColumn();
Biz::setType($u, 'business');
$A = (int)BizCash::list($u)[0]['id'];
BizProducts::save($u, ['type' => 'goods', 'name' => 'قاب', 'unit' => 'عدد', 'buy_price' => '100', 'sell_price' => '250']);
$d0 = date('Y-m-d', strtotime('-20 days')); $d1 = date('Y-m-d', strtotime('-10 days')); $d2 = date('Y-m-d', strtotime('-9 days'));
$doc = function (string $kind, string $date, string $qty, string $price) use ($u, $A): int {
    $r = BizInvoices::saveDraft($u, $kind, ['inv_date' => $date, 'lines' => [['item' => 'قاب', 'qty' => $qty, 'price' => $price]]]);
    if (!$r['ok']) { throw new RuntimeException($r['message']); }
    $i = BizInvoices::issue($u, (int)$r['id'], ['full' => true, 'account_id' => $A]);
    if (!$i['ok']) { throw new RuntimeException($i['message']); }
    return (int)$r['id'];
};
require_once __DIR__ . '/../includes/biz_docview.php';
$doc('purchase', $d0, '10', '100');      // پیش از دوره
$doc('sale', $d0, '2', '250');           // پیش از دوره: سود ۳۰۰
$doc('sale', $d1, '3', '250');           // در دوره
$doc('sale', $d2, '1', '250');
$doc('purchase', $d2, '2', '100');
BizPay::create($u, ['kind' => 'expense', 'amount' => '40', 'account_id' => $A, 'pay_date' => $d2]);
$from = date('Y-m-d', strtotime('-15 days')); $to = date('Y-m-d');
$doc('sale', $from, '1', '250');         // ⛔ درست روزِ شروع: در دوره، نه در افتتاحیه
$j = BizLegalBooks::journal($u, $from, $to);

T::group('۱ — افتتاحیه');
$open = $j['docs'][0] ?? null;
T::ok($open !== null && $open['no'] === 1 && str_contains($open['desc'], 'افتتاحیه') && $open['date'] === $from, 'سندِ ۱ افتتاحیه است، با تاریخِ شروعِ دوره');
$od = array_sum(array_column($open['lines'] ?? [], 'dr')); $oc = array_sum(array_column($open['lines'] ?? [], 'cr'));
T::ok($od === $oc && $od > 0, '⛔ افتتاحیه تراز است', "{$od} / {$oc}");
$re = array_values(array_filter($open['lines'] ?? [], fn($l) => $l['code'] === BizLegalBooks::RE));
T::ok(count($re) === 1 && $re[0]['cr'] === 300 && $re[0]['name'] === BizLegalBooks::RE_NAME, '⛔ سودِ پیش از دوره (۵۰۰ − ۲۰۰) به «سود و زیانِ انباشته»', json_encode($re, JSON_UNESCAPED_UNICODE));
$types = BizCoa::map($u);
T::ok(!array_filter($open['lines'] ?? [], fn($l) => $l['code'] !== BizLegalBooks::RE && in_array($types[$l['code']][1] ?? '', ['income', 'expense'], true)),
    'هیچ حسابِ درآمد و هزینه‌ای در افتتاحیه نیست');

T::group('۲ — یک سند در روز');
$days = array_slice($j['docs'], 1);
T::same([$from, $d1, $d2], array_column($days, 'date'), 'روزهای دوره (از خودِ روزِ شروع)، هر کدام یک سند، به ترتیب');
T::same([2, 3, 4], array_column($days, 'no'), 'شماره‌ی سندها پیاپی، بعد از افتتاحیه');
$bal = true;
foreach ($j['docs'] as $d) { if (array_sum(array_column($d['lines'], 'dr')) !== array_sum(array_column($d['lines'], 'cr'))) { $bal = false; } }
T::ok($bal, '⛔ هر سندِ روزنامه تراز است');
$codes = array_column($days[2]['lines'], 'code');
T::ok(count($codes) === count(array_unique(array_map(fn($l) => $l['code'] . ($l['dr'] ? 'd' : 'c'), $days[2]['lines']))), 'هر حساب در هر سمت یک ردیف (خلاصه‌ی روز)');
$firstCr = array_search(true, array_map(fn($l) => $l['cr'] > 0, $days[2]['lines']), true);
T::ok(!array_filter(array_slice($days[2]['lines'], 0, (int)$firstCr), fn($l) => $l['cr'] > 0), 'بدهکارها پیش از بستانکارها');
$mv = BizLedger::trial($u, $to, $from);
T::same($mv['dr'] + $od, $j['dr'], '⛔ جمعِ روزنامه = گردشِ دوره (ترازِ آزمایشی) + افتتاحیه');

T::group('۳ — دفترِ کل');
$led = BizLegalBooks::ledger($j);
$tb = BizLedger::trial($u, $to);
$bad = [];
foreach ($led as $code => $a) {
    if ($code === BizLegalBooks::RE || in_array($types[$code][1] ?? '', ['income', 'expense'], true)) { continue; }
    if ($a['close'] !== ($tb['rows'][$code]['balance'] ?? 0)) { $bad[] = "{$code}: {$a['close']} ≠ " . ($tb['rows'][$code]['balance'] ?? 0); }
}
T::same([], $bad, '⛔ مانده‌ی پایانیِ هر حسابِ دائمی = ترازِ آزمایشیِ همان روز');
T::same(0, array_sum(array_column($led, 'close')), 'جمعِ مانده‌ی همه‌ی حساب‌ها صفر است');
$sales = $led['4101'] ?? null;
T::ok($sales !== null && $sales['cr'] === 1250 && $sales['close'] === -1250, 'فروشِ دوره در کل: ۲۵۰ + ۷۵۰ + ۲۵۰ (بی‌فروشِ پیش از دوره)', json_encode($sales));
$last = end($sales['rows']);
T::ok($last['bal'] === $sales['close'] && str_contains($last['desc'], toPersianDigits((string)$last['no'])), 'مانده‌ی جاری و ارجاع به شماره‌ی سندِ روزنامه');

T::group('۴ — خروجی و ثبت');
$csv = BizLegalBooks::csv($j, 'journal');
T::ok(str_starts_with($csv, "\xEF\xBB\xBF") && str_contains($csv, 'شماره‌ی سند') && str_contains($csv, BizLegalBooks::RE_NAME), 'CSVِ روزنامه با BOM');
$csvL = BizLegalBooks::csv($j, 'ledger');
T::ok(str_contains($csvL, 'تشخیص') && (str_contains($csvL, ',بد') || str_contains($csvL, ',بس')), 'CSVِ کل با تشخیص');
T::ok(isset(BizPrint::DOCS['legal_journal'], BizPrint::DOCS['legal_ledger']) && BizPrint::DOC_CAPS['legal_journal'] === 'accounting'
    && BizPrint::DOC_CAPS['legal_ledger'] === 'accounting', '⛔ چاپی ثبت شده و دسترسیِ «دفاتر» می‌خواهد (نه فقط گزارش)');

if (!getenv('LB_KEEP')) { $wipe(); }
exit(T::report());
