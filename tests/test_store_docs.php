<?php
/**
 * ⛔ محیطِ فروشگاهی — مراحلِ ۳ تا ۵: فاکتور، دریافت و پرداخت، برگشت، گزارش.
 *
 * چهار خطرِ اصلی، هر کدام جدا:
 *   ۱. **سند اثرِ نصفه بگذارد** — صادر شود ولی موجودی یا مانده عوض نشود، یا
 *      باطل شود و اثرش نماند.
 *   ۲. **دو عدد برای یک حقیقت** — مانده‌ی طرف‌حساب (`BALANCE_SQL`)،
 *      صورت‌حساب، `paid`ِ هر فاکتور و موجودیِ صندوق از هم دور بیفتند.
 *   ۳. **نشت بین فروشگاه‌ها** — B سند، صندوق یا فاکتورِ A را بگیرد.
 *   ۴. **پولِ مغازه با پولِ خانه قاطی شود**.
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
require_once __DIR__ . '/../includes/biz_reports.php';

$root = dirname(__DIR__);
const DPREFIX = '__bdoc_';
const DPASS   = 'Doc12345';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::blocked('اسنادِ فروشگاه', 'اتصال به دیتابیس برقرار نشد: ' . $e->getMessage());
    exit(T::report());
}
if (!Biz::available() || !tableExists('biz_invoices')) {
    T::blocked('اسنادِ فروشگاه', 'جدول‌های اسناد نیست — اول: bash deploy/migrate.sh --apply');
    exit(T::report());
}

$wipe = function () use ($pdo) {
    $ids = $pdo->query("SELECT id FROM users WHERE username LIKE '" . DPREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        foreach (['biz_allocations', 'biz_payments', 'biz_stock_moves'] as $t) {
            $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]);
        }
        // برگشتی‌ها به فاکتورِ اصلی اشاره می‌کنند: اول آن‌ها
        $pdo->prepare('DELETE FROM biz_invoices WHERE user_id = :u AND ref_invoice_id IS NOT NULL')->execute(['u' => $id]);
        foreach (['biz_invoices', 'biz_products', 'biz_categories', 'biz_parties', 'biz_accounts', 'biz_settings'] as $t) {
            $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]);
        }
        foreach (userDataTables() as $t) {
            try { $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]); }
            catch (PDOException $e) { /* جدولی که نیست */ }
        }
        $pdo->prepare('DELETE FROM audit_log WHERE target_user_id = :u')->execute(['u' => $id]);
    }
    $pdo->exec("DELETE FROM users WHERE username LIKE '" . DPREFIX . "%'");
    $pdo->exec("DELETE FROM login_attempts WHERE username_tried LIKE '" . DPREFIX . "%'");
};
$wipe();

$make = function (string $name) use ($pdo): int {
    $res = createUserAccount($pdo, 'کاربرِ ' . $name, DPREFIX . $name, DPREFIX . $name . '@example.com', DPASS);
    if (!($res['ok'] ?? false)) { throw new RuntimeException('ساختِ کاربر: ' . ($res['error'] ?? '?')); }
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => DPREFIX . $name]);
    return (int)$st->fetchColumn();
};
$a = $make('a');
$b = $make('b');
Biz::setType($a, 'both');
Biz::setType($b, 'business');
$walletsBefore = json_encode(walletBalances($a));

$stock = fn(int $pid): float => (float)BizProducts::get($a, $pid)['stock_qty'];
$bal   = fn(int $pid): int => (int)BizParties::get($a, $pid)['balance'];
$accBal = function (int $id) use ($a): int {
    foreach (BizCash::list($a) as $r) { if ((int)$r['id'] === $id) { return (int)$r['balance']; } }
    return PHP_INT_MIN;
};
$inv = fn(int $id): array => BizInvoices::get($a, $id);
$lines = fn(array $rows): array => array_map(fn($r) => ['item' => $r[0], 'qty' => (string)$r[1], 'price' => (string)$r[2],
                                                       'disc' => (string)($r[3] ?? '')], $rows);

// =================================================================
T::group('۰ — fixture');
$cash = (int)BizCash::list($a)[0]['id'];
$r = BizCash::save($a, ['name' => 'بانک', 'kind' => 'bank', 'opening_balance' => '1000']);
$bank = (int)$r['id'];
$r = BizProducts::save($a, ['name' => 'کالای آزمون', 'sku' => 'T1', 'unit' => 'عدد', 'buy_price' => '100', 'sell_price' => '150']);
$P = (int)$r['id'];
$r = BizProducts::save($a, ['name' => 'خدمتِ آزمون', 'unit' => 'عدد', 'sell_price' => '40', 'is_service' => '1']);
$S = (int)$r['id'];
$sup = (int)BizParties::save($a, ['name' => 'تأمین‌کننده', 'kind' => 'supplier'])['id'];
$cus = (int)BizParties::save($a, ['name' => 'مشتری', 'kind' => 'customer'])['id'];
T::ok($P > 0 && $S > 0 && $sup > 0 && $cus > 0 && $bank > 0, 'کالا، خدمت، دو طرف‌حساب و دو صندوق ساخته شد');

// =================================================================
T::group('۱ — خرید: شماره فقط هنگامِ صدور، بها با سهمِ حمل');

$r = BizInvoices::saveDraft($a, 'purchase', ['party_id' => $sup, 'extra' => '50', 'lines' => $lines([['کالای آزمون', 10, 100]])]);
T::ok($r['ok'], 'پیش‌نویسِ خرید ذخیره شد', $r['message']);
$pu1 = (int)$r['id'];
T::ok($inv($pu1)['number'] === null && $stock($P) === 0.0, 'پیش‌نویس نه شماره دارد نه اثری روی موجودی');
T::same(1050, (int)$inv($pu1)['total'], 'جمع = ردیف‌ها + حمل');
$r = BizInvoices::issue($a, $pu1, ['amount' => '0']);
T::ok($r['ok'], 'خریدِ نسیه صادر شد', $r['message']);
T::same(1, (int)$inv($pu1)['number'], 'شماره‌ی ۱');
T::same(10.0, $stock($P), 'موجودی ۱۰ شد');
T::same(105.0, (float)BizProducts::get($a, $P)['avg_cost'], '⛔ بهای تمام‌شده سهمِ حمل را دارد (۱۰۵۰ ÷ ۱۰)');
T::same(-1050, $bal($sup), 'فروشگاه به تأمین‌کننده بدهکار است');
T::same('open', BizInvoices::state($inv($pu1)), 'وضعیت: صادرشده، پرداخت‌نشده');

// =================================================================
T::group('۲ — فروش: موجودی، بهای تمام‌شده، پرداختِ بخشی');

$r = BizInvoices::saveDraft($a, 'sale', ['party_id' => $cus, 'lines' => $lines([['T1', 4, 150], ['خدمتِ آزمون', 1, 40, 40]])]);
T::ok($r['ok'], 'فاکتورِ فروش (کد کالا + خدمت با تخفیفِ کامل)', $r['message']);
$s1 = (int)$r['id'];
T::same(600, (int)$inv($s1)['total'], 'جمع = ۶۰۰ (خدمت با تخفیفِ ردیف صفر شد)');
$r = BizInvoices::issue($a, $s1, ['account_id' => $cash, 'amount' => '200']);
T::ok($r['ok'], 'صادر شد با دریافتِ ۲۰۰', $r['message']);
T::same(6.0, $stock($P), 'موجودی ۶ شد');
$l = $inv($s1)['lines'];
T::same(105, (int)$l[0]['unit_cost'], '⛔ بهای تمام‌شده‌ی ردیفِ فروش = میانگینِ همان لحظه');
T::same(0, (int)$l[1]['unit_cost'], 'خدمت بهای تمام‌شده‌ی صفر دارد');
T::same('partial', BizInvoices::state($inv($s1)), 'وضعیت: پرداختِ جزئی');
T::same(400, $bal($cus), 'مشتری ۴۰۰ بدهکار است');
T::same(200, $accBal($cash), 'صندوق ۲۰۰');

// =================================================================
T::group('۳ — مشتریِ گذری و فروشِ بیش از موجودی');

$r = BizInvoices::saveDraft($a, 'sale', ['lines' => $lines([['T1', 1, 150]])]);
$w1 = (int)$r['id'];
$r = BizInvoices::issue($a, $w1, ['account_id' => $cash, 'amount' => '100']);
T::ok(!$r['ok'] && $inv($w1)['status'] === 'draft', '⛔ فروشِ گذری با پرداختِ بخشی صادر نمی‌شود', $r['message']);
T::same(6.0, $stock($P), 'و موجودی دست نخورد');
$r = BizInvoices::issue($a, $w1, ['account_id' => $cash, 'full' => true]);
T::ok($r['ok'] && BizInvoices::state($inv($w1)) === 'paid', 'با «دریافتِ کامل» صادر و تسویه شد', $r['message']);
T::same(5.0, $stock($P), 'موجودی ۵');

$r = BizInvoices::saveDraft($a, 'sale', ['party_id' => $cus, 'lines' => $lines([['T1', 99, 150]])]);
$big = (int)$r['id'];
$r = BizInvoices::issue($a, $big, ['amount' => '0']);
T::ok(!$r['ok'] && $inv($big)['status'] === 'draft' && $inv($big)['number'] === null,
    '⛔ فروشِ بیش از موجودی صادر نمی‌شود و شماره نمی‌سوزد', $r['message']);
T::same(5.0, $stock($P), 'موجودی دست نخورد');
T::ok(BizInvoices::deleteDraft($a, $big)['ok'], 'پیش‌نویس حذف شد');

// =================================================================
T::group('۴ — دریافت: اول قدیمی‌ترین فاکتور (FIFO)');

$r = BizInvoices::saveDraft($a, 'sale', ['party_id' => $cus, 'lines' => $lines([['T1', 2, 150]])]);
$s2 = (int)$r['id'];
BizInvoices::issue($a, $s2, ['amount' => '0']);
T::same(3, (int)$inv($s2)['number'], '⛔ پیش‌نویسِ حذف‌شده شماره را نسوزاند (۱، ۲ گذری، ۳)');
$r = BizPay::create($a, ['kind' => 'receipt', 'party_id' => $cus, 'account_id' => $cash, 'amount' => '500']);
T::ok($r['ok'], 'دریافتِ ۵۰۰ بی‌فاکتور', $r['message']);
$rc = (int)$r['id'];
T::same(600, (int)$inv($s1)['paid'], 'فاکتورِ اول کامل تسویه شد');
T::same(100, (int)$inv($s2)['paid'], 'باقی به فاکتورِ دوم خورد');
T::same(500, (int)BizPay::get($a, $rc)['allocated'], 'کشِ `allocated`');
T::same(200, $bal($cus), 'مانده‌ی مشتری ۲۰۰');

// =================================================================
T::group('۵ — برگشت از فروش (نسیه) — اعتبار روی فاکتورِ باز می‌نشیند');

$o1 = $inv($s1);
$lineP = (int)$o1['lines'][0]['id'];
$r = BizInvoices::createReturn($a, $s1, [$lineP => '5'], ['amount' => '0']);
T::ok(!$r['ok'], '⛔ بیش از مقدارِ فروخته برنمی‌گردد', $r['message']);
$r = BizInvoices::createReturn($a, $s1, [$lineP => '1'], ['amount' => '0']);
T::ok($r['ok'], 'برگشتِ یک عدد', $r['message']);
$rt1 = (int)$r['id'];
T::same(4.0, $stock($P), 'موجودی برگشت (۳ + ۱)');
T::same(105, (int)$inv($rt1)['lines'][0]['unit_cost'], '⛔ کالا با همان بهایی برمی‌گردد که بیرون رفت');
T::same(150, (int)$inv($rt1)['total'], 'مبلغِ برگشت = خالصِ ردیفِ اصلی');
T::same(50, $bal($cus), 'مانده ۵۰');
T::same(250, (int)$inv($s2)['paid'], '⛔ اعتبارِ برگشت فاکتورِ بازِ بعدی را تسویه کرد');
T::same('paid', BizInvoices::state($inv($rt1)), 'برگشت کامل مصرف شد');
$r = BizInvoices::createReturn($a, $s1, [$lineP => '4'], ['amount' => '0']);
T::ok(!$r['ok'], '⛔ برگشت‌پذیر = فروخته − برگشت‌خورده (۳ نه ۴)', $r['message']);
$stm = BizParties::statement($a, $cus);
T::same($bal($cus), (int)$stm['balance'], '⛔ مانده‌ی پایانیِ صورت‌حساب = BALANCE_SQL');

// =================================================================
T::group('۶ — ابطال و برگشت به پیش‌نویس');

$r = BizInvoices::unissue($a, $s1);
T::ok(!$r['ok'], '⛔ فاکتورِ دارای برگشت به پیش‌نویس برنمی‌گردد', $r['message']);
$r = BizInvoices::void($a, $s2);
T::ok($r['ok'], 'فاکتورِ دوم باطل شد', $r['message']);
T::same(6.0, $stock($P), 'موجودی‌اش برگشت');
T::ok($inv($s2)['number'] !== null && (int)$inv($s2)['paid'] === 0, 'شماره می‌ماند، پرداخت از آن برداشته شد');
T::same(-250, $bal($cus), 'مانده: فروشگاه ۲۵۰ به مشتری بدهکار است');
T::same(400, (int)BizPay::get($a, $rc)['allocated'], '⛔ دریافت فقط تا بازِ فاکتورِ اول خورد؛ ۱۰۰ پیش‌پرداخت ماند، نه گم');
T::same(0, (int)$pdo->query("SELECT COUNT(*) FROM biz_allocations WHERE invoice_id = {$s2}")->fetchColumn(), 'هیچ تخصیصی روی سندِ باطل نماند');
$r = BizInvoices::unissue($a, $pu1);
T::ok(!$r['ok'] && $inv($pu1)['status'] === 'issued', '⛔ خریدی که کالایش فروخته شده به پیش‌نویس برنمی‌گردد (موجودی منفی می‌شد)', $r['message']);
T::same(6.0, $stock($P), 'و موجودی دست نخورد');

$r = BizPay::void($a, (int)$pdo->query("SELECT id FROM biz_payments WHERE invoice_id = {$w1}")->fetchColumn());
T::ok(!$r['ok'], '⛔ دریافتِ فاکتورِ گذری جدا باطل نمی‌شود', $r['message']);
$r = BizInvoices::void($a, $w1);
T::ok($r['ok'] && (int)$pdo->query("SELECT COUNT(*) FROM biz_payments WHERE invoice_id = {$w1} AND status = 'ok'")->fetchColumn() === 0,
    'باطل شدنِ گذری دریافتِ همراهش را هم باطل کرد');
T::same(7.0, $stock($P), 'موجودی ۷');

// =================================================================
T::group('۷ — برگشت از خرید، پرداخت، هزینه، انتقال');

$r = BizInvoices::createReturn($a, $pu1, [(int)$inv($pu1)['lines'][0]['id'] => '2'], ['amount' => '0']);
T::ok($r['ok'], 'برگشت از خریدِ نسیه', $r['message']);
T::same(5.0, $stock($P), 'موجودی ۵');
T::same(-840, $bal($sup), 'بدهی به تأمین‌کننده ۸۴۰');
T::same(210, (int)$inv($pu1)['paid'], 'اعتبارِ برگشت روی فاکتورِ خرید');
$r = BizPay::create($a, ['kind' => 'payment', 'party_id' => $sup, 'account_id' => $bank, 'amount' => '840', 'method' => 'transfer']);
T::ok($r['ok'], 'پرداختِ ۸۴۰ از بانک', $r['message']);
T::same('paid', BizInvoices::state($inv($pu1)), 'خرید تسویه شد');
T::same(0, $bal($sup), 'مانده‌ی تأمین‌کننده صفر');
T::same(160, $accBal($bank), 'بانک ۱۰۰۰ − ۸۴۰');
T::ok(!BizPay::create($a, ['kind' => 'expense', 'account_id' => $cash, 'amount' => '50'])['ok'], 'هزینه بی‌شرح رد می‌شود');
T::ok(BizPay::create($a, ['kind' => 'expense', 'account_id' => $cash, 'amount' => '50', 'title' => 'قبض'])['ok'], 'هزینه');
T::ok(BizPay::create($a, ['kind' => 'income', 'account_id' => $cash, 'amount' => '30', 'title' => 'متفرقه'])['ok'], 'درآمد');
T::ok(!BizPay::create($a, ['kind' => 'transfer', 'account_id' => $cash, 'to_account_id' => $cash, 'amount' => '10'])['ok'], 'انتقال به خودش رد می‌شود');
T::ok(BizPay::create($a, ['kind' => 'transfer', 'account_id' => $cash, 'to_account_id' => $bank, 'amount' => '100'])['ok'], 'انتقال');
// صندوق: ۲۰۰ + ۵۰۰ − ۵۰ + ۳۰ − ۱۰۰ (دریافتِ گذری باطل شد)
T::same(580, $accBal($cash), '⛔ موجودیِ صندوق از همه‌ی منابع');
T::same(260, $accBal($bank), 'بانک + انتقال');
T::ok(!BizPay::create($a, ['kind' => 'receipt', 'account_id' => $cash, 'amount' => '10'])['ok'], 'دریافت بی‌طرف‌حساب رد می‌شود');

$r = BizPay::void($a, $rc);
T::ok($r['ok'], 'دریافتِ ۵۰۰ باطل شد');
T::same(80, $accBal($cash), 'از صندوق بیرون رفت');
T::same(250, $bal($cus), 'و مانده‌ی مشتری برگشت');
T::same(350, (int)$inv($s1)['paid'], 'تخصیص از نو ساخته شد: دریافتِ همراهِ فاکتور + اعتبارِ برگشتِ خودش');

// =================================================================
T::group('۸ — جمعِ تخفیفِ فاکتور بینِ ردیف‌ها');

$t = BizInvoices::totals([['line_total' => 333], ['line_total' => 333], ['line_total' => 334]], 100, 7);
T::same($t['total'], array_sum(array_column($t['lines'], 'net_total')), '⛔ جمعِ خالصِ ردیف‌ها دقیقاً جمعِ فاکتور است');

// =================================================================
T::group('۹ — گزارش');

$rep = BizReports::sales($a, date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('+1 day')));
// فروش‌های صادرِ باطل‌نشده: s1 (۶۰۰، بها ۴۲۰)؛ برگشت: ۱۵۰ (بها ۱۰۵)
T::same(600, $rep['sales'], 'فروشِ ناخالص فقط صادرشده‌ها');
T::same(150, $rep['returns'], 'برگشت');
T::same(450, $rep['net'], 'فروشِ خالص');
T::same(315, $rep['cogs'], '⛔ بهای تمام‌شده از `unit_cost`ِ ردیف، منهای برگشت');
T::same(135, $rep['gross'], 'سودِ ناخالص');

// =================================================================
T::group('۱۰ — جداسازی و جدایی از دفترِ شخصی');

T::ok(BizInvoices::get($b, $s1) === null, '⛔ B فاکتورِ A را نمی‌بیند');
T::ok(!BizInvoices::void($b, $s1)['ok'] && $inv($s1)['status'] === 'issued', '⛔ B فاکتورِ A را باطل نمی‌کند');
T::ok(!BizInvoices::saveDraft($b, 'sale', ['party_id' => $cus, 'lines' => $lines([['x', 1, 1]])])['ok'], '⛔ B با طرف‌حسابِ A سند نمی‌زند');
T::ok(!BizPay::create($b, ['kind' => 'expense', 'account_id' => $cash, 'amount' => '1', 'title' => 'x'])['ok'], '⛔ B از صندوقِ A خرج نمی‌کند');
$bAcc = (int)BizCash::list($b)[0]['id'];
T::ok(!BizPay::create($b, ['kind' => 'receipt', 'account_id' => $bAcc, 'invoice_id' => $s1, 'amount' => '1'])['ok'], '⛔ B به فاکتورِ A دریافت نمی‌زند');
$bSale = BizInvoices::saveDraft($b, 'sale', ['lines' => $lines([['T1', 1, 150]])]);
T::ok($bSale['ok'] && BizInvoices::get($b, (int)$bSale['id'])['lines'][0]['product_id'] === null,
    '⛔ کدِ کالای A در سندِ B هیچ کالایی را پیدا نمی‌کند');
T::same($walletsBefore, json_encode(walletBalances($a)), '⛔ موجودیِ حساب‌های شخصی تکان نخورد');
T::same(0, (int)$pdo->query("SELECT COUNT(*) FROM transactions WHERE user_id = {$a}")->fetchColumn(), '⛔ و هیچ ردیفِ `transactions` ساخته نشد');

// =================================================================
T::group('۱۱ — صفحه‌ها با HTTP');

$port = 0;
for ($pp = 9161; $pp <= 9210; $pp++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$pp", $e1, $e2);
    if ($sock) { fclose($sock); $port = $pp; break; }
}
$log = tempnam(sys_get_temp_dir(), 'bdoc');
$srv = $port ? (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
    $port, escapeshellarg($root), escapeshellarg($log)))) : 0;
$up = false;
for ($i = 0; $srv && $i < 40; $i++) {
    usleep(150000);
    $sk = @fsockopen('127.0.0.1', $port, $x, $y, 0.3);
    if ($sk) { fclose($sk); $up = true; break; }
}
if (!$up) {
    T::blocked('صفحه‌های اسناد (HTTP)', 'سرورِ آزمایشی بالا نیامد');
    if ($srv) { exec("kill $srv 2>/dev/null"); }
    $wipe();
    exit(T::report());
}
$jar = tempnam(sys_get_temp_dir(), 'bdocjar');
$req = function (string $path, ?array $post = null) use ($port, $jar): array {
    $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 40]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw  = (string)curl_exec($ch);
    $hlen = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);
    $loc = preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $hlen), $m) ? $m[1] : '';
    return [$code, substr($raw, $hlen), $loc];
};
$csrfOf = fn(string $html): string => preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m) ? $m[1] : '';

[, $lp] = $req('store/login.php');
[$c, , $loc] = $req('store/login.php', ['csrf_token' => $csrfOf($lp), 'username' => DPREFIX . 'a', 'password' => DPASS]);
T::ok($c === 302, 'ورود به فروشگاه', "{$c} {$loc}");

$draft = (int)BizInvoices::saveDraft($a, 'sale', ['party_id' => $cus, 'lines' => $lines([['T1', 1, 150]])])['id'];
$payId = (int)$pdo->query("SELECT id FROM biz_payments WHERE user_id = {$a} AND status = 'ok' ORDER BY id LIMIT 1")->fetchColumn();
$pages = ['store/index.php', 'store/sales.php', 'store/sales.php?t=returns', 'store/purchases.php', 'store/quick-sale.php',
          'store/invoice.php?id=' . $s1, 'store/invoice.php?id=' . $rt1, 'store/invoice-edit.php?k=sale', 'store/invoice-edit.php?k=purchase',
          'store/invoice-edit.php?id=' . $draft, 'store/return.php?inv=' . $s1, 'store/payments.php', 'store/payments.php?f=receipt',
          'store/payment.php?k=receipt', 'store/payment.php?k=expense', 'store/payment.php?k=transfer', 'store/payment.php?id=' . $payId,
          'store/accounts.php', 'store/cheques.php', 'store/categories.php', 'store/reports.php', 'store/reports.php?p=year',
          'store/print.php?doc=invoice&id=' . $s1, 'store/party.php?id=' . $cus];
$bad = [];
foreach ($pages as $pg) {
    [$c, $body] = $req($pg);
    if ($c !== 200 || !str_contains($body, '</html>') || preg_match('/(Fatal error|Warning:|Notice:|Deprecated:)/', $body)) { $bad[] = "{$pg} ({$c})"; }
}
T::ok(!$bad, 'هر ' . count($pages) . ' صفحه‌ی اسناد کامل و بی‌هشدار رندر شد', implode('، ', $bad));
[, $body] = $req('store/invoice.php?id=' . $s1);
T::ok(str_contains($body, 'st-pill is-partial') || str_contains($body, 'st-pill is-open'), 'نشانِ وضعیت روی سند');
[, $sl] = $req('store/sales.php?f=draft');
T::ok(str_contains($sl, 'invoice-edit.php?id=' . $draft), 'صافیِ «پیش‌نویس» پیش‌نویس را نشان می‌دهد');

// فروشِ سریعِ گذری از راهِ فرم
[, $qs] = $req('store/quick-sale.php');
$tok = $csrfOf($qs);
$before = $stock($P);
[$c, , $loc] = $req('store/quick-sale.php', ['csrf_token' => $tok, 'action' => 'sale', 'party_id' => '0',
    'lines' => [['item' => 'T1', 'qty' => '۲', 'price' => '150']], 'pay_mode' => 'full', 'account_id' => (string)$cash, 'method' => 'card']);
T::ok($c === 302 && $stock($P) === $before - 2.0, 'فروشِ سریع از راهِ فرم: موجودی ۲ تا کم شد', "{$c} {$loc}");
[$c] = $req('store/quick-sale.php', ['csrf_token' => $tok, 'action' => 'sale', 'party_id' => '0',
    'lines' => [['item' => 'T1', 'qty' => '1', 'price' => '150']], 'pay_mode' => 'none', 'account_id' => (string)$cash]);
T::same($before - 2.0, $stock($P), '⛔ فروشِ سریعِ گذری بی‌دریافت ثبت نمی‌شود');
T::same(0, (int)$pdo->query("SELECT COUNT(*) FROM biz_invoices WHERE user_id = {$a} AND status = 'draft' AND id <> {$draft}")->fetchColumn(),
    '⛔ و پیش‌نویسِ یتیمی به جا نماند');
[$c] = $req('store/quick-sale.php', ['action' => 'sale', 'party_id' => '0',
    'lines' => [['item' => 'T1', 'qty' => '1', 'price' => '150']], 'pay_mode' => 'full', 'account_id' => (string)$cash]);
T::same($before - 2.0, $stock($P), '⛔ بدونِ CSRF هیچ فروشی ثبت نمی‌شود');

// صدور از ویرایشگر
[, $ed] = $req('store/invoice-edit.php?id=' . $draft);
[$c, , $loc] = $req('store/invoice-edit.php?id=' . $draft, ['csrf_token' => $csrfOf($ed), 'action' => 'issue', 'party_id' => (string)$cus,
    'inv_date' => '', 'lines' => [['item' => 'T1', 'qty' => '1', 'price' => '150']], 'pay_mode' => 'none', 'account_id' => (string)$cash]);
T::ok($c === 302 && str_contains($loc, 'invoice.php?id=' . $draft) && $inv($draft)['status'] === 'issued', 'صدور از ویرایشگر', "{$c} {$loc}");

// =================================================================
T::group('۱۱ب — چیدمان در کرومیوم: گوشی و دسکتاپ، بی‌اسکرولِ افقی');
// ⚠ نامِ بلندِ بی‌فاصله و عددِ درشت عمداً — بدونشان «بیرون نمی‌زند» پوچ است.
$longP = (int)BizProducts::save($a, ['name' => str_repeat('کالای‌بلند', 7), 'unit' => 'عدد', 'buy_price' => '999999999',
    'sell_price' => '1999999999', 'opening_qty' => '50'])['id'];
$longDraft = (int)BizInvoices::saveDraft($a, 'sale', ['party_id' => $cus, 'note' => str_repeat('توضیح ', 20),
    'lines' => $lines([[str_repeat('کالای‌بلند', 7), 12, 1999999999, 5000], ['T1', 1, 150], ['شرحِ آزادِ بسیار بلند', 1, 10]])])['id'];
$node = trim((string)shell_exec('command -v node 2>/dev/null'));
if ($node === '') {
    T::skip('چیدمانِ اسناد در کرومیوم', 'node نیست');
} else {
    $probePages = ['store/index.php', 'store/sales.php', 'store/invoice.php?id=' . $s1, 'store/invoice-edit.php?id=' . $longDraft,
                   'store/quick-sale.php', 'store/return.php?inv=' . $s1, 'store/payments.php', 'store/payment.php?k=receipt&party=' . $cus,
                   'store/accounts.php', 'store/cheques.php', 'store/categories.php', 'store/reports.php'];
    $out = (string)shell_exec(sprintf('%s %s %s %s %s %s 2>/dev/null', escapeshellarg($node),
        escapeshellarg(__DIR__ . '/store_probe.js'), escapeshellarg("http://127.0.0.1:{$port}/"),
        escapeshellarg(DPREFIX . 'a'), escapeshellarg(DPASS), escapeshellarg(json_encode($probePages)))
        . (getenv('STORE_SHOTS') ? ' ' . escapeshellarg((string)getenv('STORE_SHOTS')) : ''));
    $pr = json_decode(trim($out), true);
    if (!is_array($pr) || empty($pr['ok'])) {
        T::skip('چیدمانِ اسناد در کرومیوم', 'کرومیوم در دسترس نیست: ' . ($pr['why'] ?? trim($out)));
    } else {
        T::same(count($probePages) * 2, count($pr['res']), 'هر صفحه روی دو عرض سنجیده شد');
        foreach ($pr['res'] as $m) {
            T::ok($m['sw'] <= $m['W'] && !$m['out'], "«{$m['pg']}» روی {$m['w']}: بی‌اسکرولِ افقی و بی‌بیرون‌زدگی",
                "scrollWidth={$m['sw']} width={$m['W']} " . implode(' | ', $m['out']));
            T::ok(!$m['rtl'], "«{$m['pg']}» روی {$m['w']}: هر متنِ فارسی rtl و راست‌چین است", implode(' | ', $m['rtl'] ?? ['?']));
        }
    }
}

// سندِ A برای B
[, $lp] = $req('store/logout.php');
[, $lp] = $req('store/login.php');
$req('store/login.php', ['csrf_token' => $csrfOf($lp), 'username' => DPREFIX . 'b', 'password' => DPASS]);
[$c, $body] = $req('store/invoice.php?id=' . $s1);
T::ok($c !== 200 || !str_contains($body, 'مشتری'), '⛔ صفحه‌ی فاکتورِ A برای B باز نمی‌شود', "کد: {$c}");
[$c, $body] = $req('store/return.php?inv=' . $s1);
T::ok($c !== 200 || !str_contains($body, 'کالای آزمون'), '⛔ صفحه‌ی برگشتِ فاکتورِ A برای B باز نمی‌شود', "کد: {$c}");

exec("kill $srv 2>/dev/null");
@unlink($log); @unlink($jar);

// =================================================================
T::group('۱۲ — حذفِ کاملِ حساب با برگشت و پرداخت');
// ⛔ ارجاعِ «برگشت ← اصل» `SET NULL` است؛ با `RESTRICT` همین حذف برای هر
//    فروشگاهی که یک برگشت دارد شکست می‌خورد (آزموده شد).
$del = deleteUserAccount($a);
T::ok($del['ok'] ?? false, '⛔ حسابِ فروشگاهیِ دارای برگشت، دریافت و تخصیص کامل حذف می‌شود', (string)($del['reason'] ?? ''));
$left = 0;
foreach (['biz_invoices', 'biz_invoice_lines', 'biz_payments', 'biz_allocations', 'biz_stock_moves', 'biz_products', 'biz_parties', 'biz_accounts'] as $t) {
    $left += (int)$pdo->query("SELECT COUNT(*) FROM `{$t}` WHERE user_id = {$a}")->fetchColumn();
}
T::same(0, $left, 'هیچ ردیفی از دفترِ فروشگاه جا نماند');
$wipe();
exit(T::report());
