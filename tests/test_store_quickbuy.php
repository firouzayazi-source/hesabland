<?php
/**
 * تستِ «تأمینِ موجودی» — `BizQuickBuy` و پنلِ «+ موجودی» در فاکتورِ فروش.
 *
 * **خواسته‌ی مالکِ نصب:** «اگر کالایی موجودی نداره، همون‌جا توی فاکتور
 * بتونم موجودی بدم … از چه کسی خریدم که بدهکارش بشم، به چه قیمتی خریدم،
 * به چه قیمتی می‌فروشم … و وقتی برگشتم تمامِ دیتای قبلی سرِ جاش باشه.»
 *
 * سه چیز سنجیده می‌شود:
 *   ۱. ⛔ یک **فاکتورِ خریدِ واقعی** است: موجودی، بهای میانگین، مانده‌ی فروشنده
 *      (نسیه) یا صندوق (نقد) — نه انبارگردانیِ بی‌بها و بی‌طلبکار.
 *   ۲. ⛔ شکست هیچ ردی نمی‌گذارد: نه پیش‌نویسِ یتیم، نه فروشنده‌ی تازه برای
 *      خریدی که نشد.
 *   ۳. ⛔ از دلِ فاکتورِ فروش، با HTTPِ واقعی: هر چه در فاکتور تایپ شده بود
 *      سرِ جایش می‌ماند، دو بار زدن دو خرید نمی‌سازد، و بعدش فروش صادر می‌شود.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';

T::group('تأمینِ موجودی — خرید از دلِ فاکتور');

if (!file_exists(__DIR__ . '/../config/config.php')) {
    T::blocked('تأمینِ موجودی', 'config/config.php وجود ندارد');
    exit(T::report());
}
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/biz.php';
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_quickbuy.php';

$root = dirname(__DIR__);
try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::blocked('تأمینِ موجودی', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}
if (!tableExists('biz_invoices') || !tableHasColumn('users', 'account_type')) {
    T::blocked('تأمینِ موجودی', 'جدول‌های فروشگاه نیست — اول: bash deploy/migrate.sh --apply');
    exit(T::report());
}

const QPREFIX = '__qbuy_';
const QPASS   = 'Qbuy12345';
$wipe = function () use ($pdo) {
    $ids = $pdo->query("SELECT id FROM users WHERE username LIKE '" . QPREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) { deleteUserAccount((int)$id); }
    $pdo->exec("DELETE FROM login_attempts WHERE username_tried LIKE '" . QPREFIX . "%'");
};
$wipe();
register_shutdown_function($wipe);

$make = function (string $name) use ($pdo): int {
    $res = createUserAccount($pdo, 'فروشگاهِ ' . $name, QPREFIX . $name, QPREFIX . $name . '@example.com', QPASS);
    if (!($res['ok'] ?? false)) { throw new RuntimeException('ساختِ کاربر: ' . ($res['error'] ?? '?')); }
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => QPREFIX . $name]);
    $id = (int)$st->fetchColumn();
    Biz::setType($id, 'business');
    return $id;
};
$u = $make('shop');

$stock = fn(int $pid): float => (float)BizProducts::get($u, $pid)['stock_qty'];
$count = function (string $sql, array $p) use ($pdo): int { $st = $pdo->prepare($sql); $st->execute($p); return (int)$st->fetchColumn(); };
$purchases = fn(): int => $count("SELECT COUNT(*) FROM biz_invoices WHERE user_id = :u AND kind = 'purchase'", ['u' => $u]);
$partiesN  = fn(): int => $count('SELECT COUNT(*) FROM biz_parties WHERE user_id = :u', ['u' => $u]);
$balance   = function (int $partyId) use ($u): int {
    foreach (BizParties::all($u)['rows'] as $p) { if ((int)$p['id'] === $partyId) { return (int)$p['balance']; } }
    return PHP_INT_MIN;
};
$cash = function (int $accId) use ($u): int {
    foreach (BizCash::list($u) as $a) { if ((int)$a['id'] === $accId) { return (int)$a['balance']; } }
    return PHP_INT_MIN;
};
$today = date('Y-m-d');

$glass = (int)BizProducts::save($u, ['type' => 'goods', 'name' => 'گلاس آیفون ۱۵', 'unit' => 'عدد', 'buy_price' => '40000', 'sell_price' => '90000'])['id'];
$svc   = (int)BizProducts::save($u, ['type' => 'service', 'name' => 'نصبِ گلاس', 'unit' => 'عدد', 'sell_price' => '20000'])['id'];
$phone = (int)BizProducts::save($u, ['type' => 'phone', 'name' => 'گوشیِ آزمایشی', 'unit' => 'دستگاه', 'buy_price' => '10000000', 'sell_price' => '12000000'])['id'];
$sup   = (int)BizParties::save($u, ['name' => 'پخشِ نمونه', 'kind' => 'supplier'])['id'];
$acc   = (int)BizCash::save($u, ['name' => 'صندوقِ مغازه', 'kind' => 'cash', 'opening_balance' => '1000000'])['id'];
T::ok($glass > 0 && $phone > 0 && $sup > 0 && $acc > 0, 'کالا، فروشنده و صندوقِ آزمایشی ساخته شد');
T::same(0.0, $stock($glass), 'گلاس موجودی ندارد (پیش‌شرط)');

// ---- ۱. نسیه از فروشنده: فاکتورِ خریدِ صادرشده، طلبِ فروشنده، بهای خرید ----
$p0 = $purchases(); $b0 = $balance($sup);
$r = BizQuickBuy::run($u, ['product_id' => $glass, 'party_id' => $sup, 'qty' => '3', 'buy' => '50000', 'date' => $today, 'pay' => 'credit']);
T::ok($r['ok'], 'نسیه از فروشنده ثبت شد', $r['message']);
T::same(3.0, $stock($glass), '⛔ موجودیِ گلاس ۳ شد');
T::same($p0 + 1, $purchases(), 'یک فاکتورِ خرید ساخته شد');
$inv = BizInvoices::get($u, (int)($r['invoice_id'] ?? 0));
T::same('issued', $inv['status'] ?? null, '⛔ فاکتورِ خرید **صادرشده** است، نه پیش‌نویس');
T::same($sup, (int)($inv['party_id'] ?? 0), 'به نامِ همان فروشنده');
T::same(BizQuickBuy::NOTE, (string)($inv['note'] ?? ''), 'توضیحش می‌گوید از کجا آمده');
T::same(150000, abs($balance($sup) - $b0), '⛔ مانده‌ی فروشنده به اندازه‌ی ۱۵۰٬۰۰۰ جابه‌جا شد (طلبِ او)');
T::ok(($balance($sup) - $b0) < 0, '⛔ و در جهتِ «ما به او بدهکاریم»، همان جهتِ فاکتورِ خریدِ دستی', (string)($balance($sup) - $b0));
T::same(50000, (int)BizProducts::get($u, $glass)['avg_cost'], '⛔ بهای میانگین همان قیمتِ خرید است (سودِ فروش درست می‌ماند)');
T::same($sup, BizQuickBuy::lastSupplier($u, $glass), 'آخرین فروشنده‌ی این کالا همان است (پیش‌فرضِ پنل)');

// ---- ۲. نقد از صندوق، فروشنده‌ی گذری؛ قیمتِ فروشِ تازه ----
$c0 = $cash($acc); $n0 = $partiesN();
$r = BizQuickBuy::run($u, ['product_id' => $glass, 'party_id' => 0, 'qty' => '2', 'buy' => '45000', 'sell' => '95000',
                           'date' => $today, 'pay' => 'cash', 'account_id' => $acc]);
T::ok($r['ok'], 'خریدِ نقد از فروشنده‌ی گذری ثبت شد', $r['message']);
T::same(5.0, $stock($glass), 'موجودی ۵ شد');
T::same($c0 - 90000, $cash($acc), '⛔ ۹۰٬۰۰۰ از صندوق کم شد');
T::same($n0, $partiesN(), 'هیچ طرف‌حسابی ساخته نشد');
T::same(95000, (int)BizProducts::get($u, $glass)['sell_price'], 'قیمتِ فروشِ کالا ۹۵٬۰۰۰ شد');

// ---- ۳. فروشنده‌ی تازه با نام؛ دفعه‌ی بعد همان، نه تکراری ----
$r = BizQuickBuy::run($u, ['product_id' => $glass, 'party_name' => 'علي موبایل', 'qty' => '1', 'buy' => '40000', 'date' => $today]);
T::ok($r['ok'], 'خرید از فروشنده‌ی تازه', $r['message']);
T::same($n0 + 1, $partiesN(), 'فروشنده‌ی تازه ساخته شد');
$newP = BizParties::get($u, (int)$r['party_id']);
T::same('supplier', (string)($newP['kind'] ?? ''), 'به‌عنوانِ تأمین‌کننده');
$r2 = BizQuickBuy::run($u, ['product_id' => $glass, 'party_name' => 'علی موبایل', 'qty' => '1', 'buy' => '40000', 'date' => $today]);
T::same((int)$r['party_id'], (int)($r2['party_id'] ?? 0), '⛔ همان نام (حتی با «ي» عربی) همان فروشنده است، نه یک نفرِ دوم');
T::same($n0 + 1, $partiesN(), 'و طرف‌حسابِ تکراری ساخته نشد');

// ---- ۴. خطاها هیچ ردی نمی‌گذارند ----
T::group('شکست هیچ ردی نمی‌گذارد');
$p0 = $purchases(); $n0 = $partiesN(); $s0 = $stock($glass);
$bad = [
    'نسیه بی‌فروشنده'  => ['product_id' => $glass, 'qty' => '1', 'buy' => '1000', 'date' => $today, 'pay' => 'credit'],
    'نقد بی‌صندوق'     => ['product_id' => $glass, 'qty' => '1', 'buy' => '1000', 'date' => $today, 'pay' => 'cash'],
    'قیمتِ خرید صفر'   => ['product_id' => $glass, 'party_id' => $sup, 'qty' => '1', 'buy' => '0', 'date' => $today],
    'تعدادِ صفر'       => ['product_id' => $glass, 'party_id' => $sup, 'qty' => '0', 'buy' => '1000', 'date' => $today],
    'خدمت'             => ['product_id' => $svc, 'party_id' => $sup, 'qty' => '1', 'buy' => '1000', 'date' => $today],
    'کالای ناموجود'    => ['product_id' => 999999999, 'party_id' => $sup, 'qty' => '1', 'buy' => '1000', 'date' => $today],
    'تاریخِ نامعتبر'   => ['product_id' => $glass, 'party_id' => $sup, 'qty' => '1', 'buy' => '1000', 'date' => 'x'],
    'گوشیِ بی‌IMEI'    => ['product_id' => $phone, 'party_id' => $sup, 'buy' => '1000', 'date' => $today],
    'IMEIِ نامعتبر'    => ['product_id' => $phone, 'party_name' => 'نباید ساخته شود', 'buy' => '1000', 'date' => $today, 'imeis' => '123'],
];
foreach ($bad as $label => $in) {
    $r = BizQuickBuy::run($u, $in);
    T::ok(!$r['ok'] && $r['message'] !== '', "«{$label}» رد شد", $r['message']);
}
T::same($p0, $purchases(), '⛔ هیچ فاکتورِ خریدی (حتی پیش‌نویس) از خطاها نماند');
T::same($n0, $partiesN(), '⛔ فروشنده‌ی تازه برای خریدِ ردشده ساخته نشد');
T::same($s0, $stock($glass), 'موجودی دست نخورد');

// ---- ۵. گوشی: هر خط یک دستگاه؛ IMEIِ تکراری رد و بی‌رد ----
T::group('گوشی با IMEI');
$r = BizQuickBuy::run($u, ['product_id' => $phone, 'party_id' => $sup, 'buy' => '10000000', 'date' => $today,
                           'imeis' => "356938035643809\n356938035643817 / 356938035643825"]);
T::ok($r['ok'], 'دو گوشی (یکی دوسیم‌کارت) خریده شد', $r['message']);
T::same(2.0, $stock($phone), '⛔ موجودی ۲ دستگاه — تعداد از شمارِ IMEIها');
T::ok(str_starts_with($r['message'], formatQty(2) . ' دستگاه'), 'پیام همان تعداد را می‌گوید («۲ دستگاه»)', $r['message']);
$lines = BizInvoices::get($u, (int)$r['invoice_id'])['lines'] ?? [];
T::same(['356938035643809', '356938035643817'], array_map(fn($l) => (string)$l['imei1'], $lines), 'هر دستگاه یک ردیف با IMEIِ خودش');
T::same('356938035643825', (string)($lines[1]['imei2'] ?? ''), 'IMEI ۲ِ گوشیِ دوسیم‌کارت');
$p0 = $purchases();
$r = BizQuickBuy::run($u, ['product_id' => $phone, 'party_id' => $sup, 'buy' => '10000000', 'date' => $today, 'imeis' => '356938035643809']);
T::ok(!$r['ok'], '⛔ IMEIِ گوشیِ در انبار دوباره خریده نمی‌شود', $r['message']);
T::same($p0, $purchases(), '⛔ و پیش‌نویسِ یتیمی از صدورِ ردشده نماند');

// ---- ۶. دوره‌ی بسته ----
if (Biz::accReady() || tableHasColumn('biz_settings', 'lock_date')) {
    $lk = Biz::saveLock($u, date('Y-m-d', strtotime('-1 day')));
    if ($lk['ok'] ?? false) {
        $n0 = $partiesN();
        $r = BizQuickBuy::run($u, ['product_id' => $glass, 'party_name' => 'در دوره‌ی بسته', 'qty' => '1', 'buy' => '1000',
                                   'date' => date('Y-m-d', strtotime('-3 day'))]);
        T::ok(!$r['ok'], '⛔ خرید با تاریخِ دوره‌ی بسته رد شد', $r['message']);
        T::same($n0, $partiesN(), '⛔ و پیش از آن فروشنده‌ای ساخته نشد');
        Biz::saveLock($u, '', true);
    }
}

// =====================================================================
T::group('⛔ خرید خورده، موجودی نخورده — فقط موجودی، بی‌خریدِ دوم');
// فاکتورِ خریدِ صادرشده با ردیفِ «شرحِ آزاد» (به کالا وصل نیست): پول و طلبِ فروشنده هست، انبار نه
$cover = (int)BizProducts::save($u, ['type' => 'goods', 'name' => 'کاورِ کتابی', 'unit' => 'عدد', 'buy_price' => '25000', 'sell_price' => '60000'])['id'];
$stockDiff = fn(): ?int => Biz::accReady() && class_exists('BizLedger') ? (int)BizLedger::reconcile($u)['stock']['diff'] : null;
$d0 = $stockDiff();
$fr = BizInvoices::saveDraft($u, 'purchase', ['party_id' => $sup, 'inv_date' => $today,
    'lines' => [['item' => 'کاور کتابی مشکی', 'qty' => '4', 'price' => '25000']]]);
T::ok($fr['ok'], 'خریدِ «کاور کتابی مشکی» با شرحِ آزاد ثبت شد (نامش با کالا یکی نیست، پس وصل نشد)', $fr['message']);
$freeInv = (int)$fr['id'];
T::ok(BizInvoices::issue($u, $freeInv, ['amount' => '0'])['ok'], 'و صادر شد (نسیه)');
T::same(0.0, $stock($cover), 'پیش‌شرط: پول و طلب ثبت شده ولی موجودی نیامده');
$freeLine = (int)(BizInvoices::get($u, $freeInv)['lines'][0]['id'] ?? 0);
T::same(null, BizInvoices::get($u, $freeInv)['lines'][0]['product_id'], 'ردیف به هیچ کالایی وصل نیست');

$pend = BizQuickBuy::pending($u, $cover);
T::same($freeLine, (int)($pend['loose'][0]['line_id'] ?? 0), '⛔ همان ردیف اولِ فهرستِ «خرید خورده، موجودی نخورده» است');
T::same(false, $pend['loose'][0]['same'] ?? null, 'هم‌نام نیست، پس وصل کردن تصمیمِ کاربر است (خودکار نه)');
T::same([], BizQuickBuy::pending($u, $phone)['loose'], 'گوشی ردیفِ آزاد نمی‌گیرد (بی‌IMEI به انبار نمی‌رود)');

$p0 = $purchases(); $b0 = $balance($sup);
$lk = BizQuickBuy::linkLine($u, $freeLine, $cover);
T::ok($lk['ok'], 'فقط موجودی: ردیف به کالا وصل شد', $lk['message']);
T::same(4.0, $stock($cover), '⛔ موجودیِ کاور ۴ شد');
T::same(25000, (int)BizProducts::get($u, $cover)['avg_cost'], '⛔ با بهای همان خرید');
T::same($p0, $purchases(), '⛔ هیچ فاکتورِ خریدِ دومی ساخته نشد');
T::same($b0, $balance($sup), '⛔ و طلبِ فروشنده دو برابر نشد');
T::same($cover, (int)(BizInvoices::get($u, $freeInv)['lines'][0]['product_id'] ?? 0), 'ردیفِ سند حالا به کالا اشاره می‌کند');
T::same(1, $count("SELECT COUNT(*) FROM biz_stock_moves WHERE user_id = :u AND product_id = :p AND ref_id = :i AND kind = 'purchase'",
    ['u' => $u, 'p' => $cover, 'i' => $freeInv]), 'یک حرکتِ انبارِ «خرید» برای همان سند');
if ($d0 !== null) {
    T::same($d0, $stockDiff(), '⛔ دفترِ دوطرفه با انبار همچنان می‌خواند (ردیف از «خریدِ بی‌انبار» به موجودی رفت)');
    T::ok($count("SELECT COUNT(*) FROM biz_doc_log WHERE user_id = :u AND invoice_id = :i AND action = 'stock_link'", ['u' => $u, 'i' => $freeInv]) === 1,
        'در سرگذشتِ سند «وصلِ ردیف به کالا» ثبت شد');
}
$again = BizQuickBuy::linkLine($u, $freeLine, $glass);
T::ok(!$again['ok'], '⛔ ردیفِ وصل‌شده دوباره به کالای دیگری وصل نمی‌شود', $again['message']);
T::same(4.0, $stock($cover), 'و موجودی دست نخورد');
T::ok(!BizQuickBuy::linkLine($u, $freeLine + 999999, $cover)['ok'], 'ردیفِ ناموجود رد شد');

// خدمت و گوشی به ردیفِ آزاد وصل نمی‌شوند
$fr2 = BizInvoices::saveDraft($u, 'purchase', ['party_id' => $sup, 'inv_date' => $today, 'lines' => [['item' => 'چیزِ دیگر', 'qty' => '1', 'price' => '1000']]]);
BizInvoices::issue($u, (int)$fr2['id'], ['amount' => '0']);
$l2 = (int)BizInvoices::get($u, (int)$fr2['id'])['lines'][0]['id'];
$rs = BizQuickBuy::linkLine($u, $l2, $svc);
T::ok(!$rs['ok'] && str_contains($rs['message'], 'خدمت'), 'خدمت رد شد — با دلیلِ خودش', $rs['message']);
T::ok(!BizQuickBuy::linkLine($u, $l2, $phone)['ok'], '⛔ گوشی بی‌IMEI رد شد');

// ⛔ ردیفِ آزادِ **پیش‌نویس** خرید خورده نیست — در فهرستِ «فقط موجودی» نمی‌آید
$fd = BizInvoices::saveDraft($u, 'purchase', ['party_id' => $sup, 'inv_date' => $today, 'lines' => [['item' => 'پیش‌نویسِ آزاد', 'qty' => '1', 'price' => '1000']]]);
$draftLine = (int)BizInvoices::get($u, (int)$fd['id'])['lines'][0]['id'];
T::ok(!in_array($draftLine, array_column(BizQuickBuy::pending($u, $cover)['loose'], 'line_id'), true), '⛔ ردیفِ پیش‌نویس (صادرنشده) پیشنهاد نمی‌شود');
T::ok(!BizQuickBuy::linkLine($u, $draftLine, $cover)['ok'], 'و وصل هم نمی‌شود');
BizInvoices::deleteDraft($u, (int)$fd['id']);
// ⛔ «صدورِ پیش‌نویس» فقط پیش‌نویسِ **خرید** — فاکتورِ فروش از این راه صادر نمی‌شود
$sd = BizInvoices::saveDraft($u, 'sale', ['party_id' => $sup, 'inv_date' => $today, 'lines' => [['item' => 'گلاس آیفون ۱۵', 'product_id' => (string)$glass, 'qty' => '1', 'price' => '95000']]]);
T::ok(!BizQuickBuy::issueDraft($u, (int)$sd['id'])['ok'], '⛔ پیش‌نویسِ فروش با «صدورِ پیش‌نویسِ خرید» صادر نمی‌شود');
T::same('draft', BizInvoices::get($u, (int)$sd['id'])['status'] ?? null, 'و پیش‌نویس ماند');
BizInvoices::deleteDraft($u, (int)$sd['id']);

// پیش‌نویسِ خرید: صدورش موجودی را می‌آورد، نه خریدِ دوم
$dr = BizInvoices::saveDraft($u, 'purchase', ['party_id' => $sup, 'inv_date' => $today,
    'lines' => [['item' => 'کاورِ کتابی', 'product_id' => (string)$cover, 'qty' => '2', 'price' => '26000']]]);
$pend = BizQuickBuy::pending($u, $cover);
T::same((int)$dr['id'], (int)($pend['drafts'][0]['invoice_id'] ?? 0), '⛔ پیش‌نویسِ خریدِ همین کالا پیدا شد');
$p0 = $purchases();
$is = BizQuickBuy::issueDraft($u, (int)$dr['id']);
T::ok($is['ok'], 'پیش‌نویس صادر شد', $is['message']);
T::same(6.0, $stock($cover), 'موجودی ۶ شد');
T::same($p0, $purchases(), '⛔ فاکتورِ تازه‌ای ساخته نشد — همان پیش‌نویس');
T::ok(!BizQuickBuy::issueDraft($u, (int)$dr['id'])['ok'], 'صدورِ دوباره رد شد');

$hd = BizInvoices::saveDraft($u, 'purchase', ['party_id' => $sup, 'inv_date' => $today, 'lines' => [['item' => 'کاورِ کتابی', 'product_id' => (string)$cover, 'qty' => '1', 'price' => '1']]]);
$hist = BizQuickBuy::history($u, $cover);
BizInvoices::deleteDraft($u, (int)$hd['id']);
T::same(2, count($hist), '«از چه کسی خریده شد»: دو خرید (ردیفِ وصل‌شده هم) — پیش‌نویسِ باز نه');
T::same('پخشِ نمونه', $hist[0]['party'] ?? null, 'با نامِ فروشنده');

// =====================================================================
T::group('از دلِ فاکتورِ فروش (HTTP) — داده‌ی فاکتور سرِ جایش');
$port = 0;
for ($p = 9240; $p <= 9290; $p++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
    if ($sock) { fclose($sock); $port = $p; break; }
}
if (!$port) { T::blocked('تأمینِ موجودی (HTTP)', 'پورت آزاد پیدا نشد'); exit(T::report()); }
$log = tempnam(sys_get_temp_dir(), 'qbuy');
$srv = (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
    $port, escapeshellarg($root), escapeshellarg($log))));
register_shutdown_function(static function () use ($srv, $log) { exec("kill {$srv} 2>/dev/null"); @unlink($log); });
$up = false;
for ($i = 0; $i < 40; $i++) {
    usleep(150000);
    $sk = @fsockopen('127.0.0.1', $port, $a, $b, 0.3);
    if ($sk) { fclose($sk); $up = true; break; }
}
T::ok($up, 'سرورِ آزمایشی بالا آمد');
if (!$up) { exit(T::report()); }

$jar = tempnam(sys_get_temp_dir(), 'qbuyjar');
$req = function (string $path, ?array $post = null) use ($port, $jar): array {
    $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 40]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $raw = (string)curl_exec($ch);
    $hl = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);
    $loc = preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $hl), $m) ? $m[1] : '';
    return [$code, substr($raw, $hl), $loc];
};
$field = static fn(string $html, string $name): string =>
    preg_match('/name="' . preg_quote($name, '/') . '"[^>]*value="([^"]*)"/', $html, $m) ? html_entity_decode($m[1]) : '';
[, $h] = $req('store/login.php');
$req('store/login.php', ['csrf_token' => $field($h, 'csrf_token'), 'username' => QPREFIX . 'shop', 'password' => QPASS]);

// کالای تازه‌ی بی‌موجودی، و یک فاکتورِ فروش با دو ردیف و توضیح
$case = (int)BizProducts::save($u, ['type' => 'goods', 'name' => 'قابِ سیلیکونی', 'unit' => 'عدد', 'buy_price' => '30000', 'sell_price' => '70000'])['id'];
$cust = (int)BizParties::save($u, ['name' => 'مشتریِ نمونه', 'kind' => 'customer'])['id'];
[$c, $page] = $req('store/invoice-edit.php?k=sale');
T::same(200, $c, 'فاکتورِ فروشِ تازه باز شد');
$base = [
    'csrf_token' => $field($page, 'csrf_token'), '_once' => $field($page, '_once'),
    'party_id' => (string)$cust, 'inv_date' => BizDocView::jDate($today), 'note' => 'یادداشتِ فاکتور که نباید گم شود',
    'lines' => [
        ['item' => 'قابِ سیلیکونی', 'product_id' => (string)$case, 'qty' => '2', 'price' => '70000', 'note' => 'رنگِ آبی'],
        ['item' => 'گلاس آیفون ۱۵', 'product_id' => (string)$glass, 'qty' => '1', 'price' => '95000'],
    ],
    'pay_mode' => 'full', 'account_id' => (string)$acc, 'method' => 'cash',
];

[$c, $page] = $req('store/invoice-edit.php?k=sale', $base + ['action' => 'addrows']);
T::ok($c === 200 && str_contains($page, 'data-stock-warn') && str_contains($page, 'name="sp_open" value="0"'),
    '⛔ فاکتورِ تازه (ذخیره‌نشده) کسریِ ردیفِ ۱ را نشان می‌دهد، با «+ موجودی»', "کد: {$c}");
T::ok(!str_contains($page, 'name="sp_open" value="1"'), 'ردیفِ گلاس (موجودی دارد) دکمه ندارد');

[$c, $page] = $req('store/invoice-edit.php?k=sale', $base + ['sp_open' => '0']);
T::ok($c === 200 && str_contains($page, 'data-sp'), 'پنلِ «تأمینِ موجودی» در همان صفحه باز شد', "کد: {$c}");
T::same('2', toLatinDigits($field($page, 'sp[qty]')), '⛔ تعدادِ پیش‌فرض = کسری (۲ − ۰)');
T::same('30000', $field($page, 'sp[buy]'), 'قیمتِ خرید از کالا');
T::same('70000', $field($page, 'sp[sell]'), 'قیمتِ فروش از ردیف');
T::ok(str_contains($page, 'یادداشتِ فاکتور که نباید گم شود') && str_contains($page, 'value="رنگِ آبی"'),
    '⛔ توضیحِ فاکتور و شرحِ ردیف سرِ جایشان‌اند');

$once = $field($page, '_once');
$spPost = array_merge($base, ['_once' => $once, 'csrf_token' => $field($page, 'csrf_token'), 'action' => 'sp_save',
    'sp' => ['row' => $field($page, 'sp[row]'), 'product_id' => (string)$case, 'party_id' => '0', 'party_name' => 'بنکداریِ تازه',
             'qty' => '2', 'buy' => '32000', 'sell' => '75000', 'date' => BizDocView::jDate($today), 'pay' => 'credit',
             'account_id' => (string)$acc]]);
$p0 = $purchases();
[$c, $page] = $req('store/invoice-edit.php?k=sale', $spPost);
T::ok($c === 200 && str_contains($page, 'data-sp-done'), '⛔ خرید ثبت شد و همان فاکتورِ فروش دوباره نشان داده شد (نه ریدایرکت)', "کد: {$c}");
T::same(2.0, $stock($case), '⛔ موجودیِ قاب ۲ شد');
T::same($p0 + 1, $purchases(), 'یک فاکتورِ خرید');
T::ok(!str_contains($page, 'data-stock-warn') && !str_contains($page, 'name="sp_open"'), 'هشدارِ کسری و دکمه رفت');
T::ok(str_contains($page, 'یادداشتِ فاکتور که نباید گم شود') && str_contains($page, 'value="رنگِ آبی"')
    && str_contains($page, 'value="گلاس آیفون ۱۵"') && str_contains($page, 'value="مشتریِ نمونه"') === false
    && preg_match('/<option value="' . $cust . '"[^>]*selected/', $page) === 1,
    '⛔ همه‌ی داده‌ی فاکتور سرِ جایش: مشتری، توضیح، شرحِ ردیف، ردیفِ دوم');
T::same('75000', $field($page, 'lines[0][price]'), 'فیِ ردیف قیمتِ فروشِ تازه شد (۷۵٬۰۰۰)');

[$c, $page2] = $req('store/invoice-edit.php?k=sale', $spPost);
T::same($p0 + 1, $purchases(), '⛔ دو بار زدنِ «ثبتِ خرید» دو فاکتورِ خرید نمی‌سازد');
T::ok($c === 200 && str_contains($page2, 'یادداشتِ فاکتور که نباید گم شود'), 'و فاکتورِ فروش هم دور ریخته نشد');

// حالا خودِ فروش صادر می‌شود
$issue = array_merge($base, ['_once' => $field($page, '_once'), 'csrf_token' => $field($page, 'csrf_token'), 'action' => 'issue']);
$issue['lines'][0]['price'] = '75000';
[$c, , $loc] = $req('store/invoice-edit.php?k=sale', $issue);
T::ok($c === 302 && str_contains($loc, 'invoice.php?id='), '⛔ بعد از تأمین، فاکتورِ فروش صادر شد', "{$c} {$loc}");
T::same(0.0, $stock($case), 'و موجودیِ قاب به صفر برگشت (۲ خریده، ۲ فروخته)');

// ---- «فقط موجودی» از دلِ فاکتورِ فروش ----
$strap = (int)BizProducts::save($u, ['type' => 'goods', 'name' => 'بندِ ساعت', 'unit' => 'عدد', 'buy_price' => '50000', 'sell_price' => '120000'])['id'];
$fr3 = BizInvoices::saveDraft($u, 'purchase', ['party_id' => $sup, 'inv_date' => $today, 'lines' => [['item' => 'بند ساعت چرمی', 'qty' => '3', 'price' => '50000']]]);
BizInvoices::issue($u, (int)$fr3['id'], ['amount' => '0']);
$l3 = (int)BizInvoices::get($u, (int)$fr3['id'])['lines'][0]['id'];
[, $fresh] = $req('store/invoice-edit.php?k=sale');
$b3 = array_merge($base, ['csrf_token' => $field($fresh, 'csrf_token'), '_once' => $field($fresh, '_once'),
    'lines' => [['item' => 'بندِ ساعت', 'product_id' => (string)$strap, 'qty' => '2', 'price' => '120000', 'note' => 'مشکی']]]);
[$c, $page] = $req('store/invoice-edit.php?k=sale', $b3 + ['sp_open' => '0']);
T::ok($c === 200 && str_contains($page, 'name="sp_link" value="' . $l3 . '"'), '⛔ پنل خریدِ ثبت‌شده‌ی بی‌موجودی را پیشنهاد می‌دهد («فقط موجودی بده»)', "کد: {$c}");
T::ok(str_contains($page, 'product.php?id=' . $strap) && str_contains($page, 'target="_blank"'), 'و «ویرایشِ مشخصاتِ کالا» در برگه‌ی تازه (فاکتور دست نمی‌خورد)');
$p0 = $purchases(); $b0 = $balance($sup);
[$c, $page] = $req('store/invoice-edit.php?k=sale', array_merge($b3, ['_once' => $field($page, '_once'), 'csrf_token' => $field($page, 'csrf_token'),
    'sp_link' => (string)$l3, 'sp' => ['row' => '0', 'product_id' => (string)$strap]]));
T::ok($c === 200 && str_contains($page, 'data-sp-done') && str_contains($page, 'value="مشکی"'), '⛔ وصل شد و فاکتورِ فروش با داده‌اش برگشت', "کد: {$c}");
T::same(3.0, $stock($strap), '⛔ موجودی ۳ — از همان خرید');
T::same([$p0, $b0], [$purchases(), $balance($sup)], '⛔ بی‌خرید و بی‌طلبِ تازه');

// ---- صفحه‌ی کالا: انبارگردانیِ افزاینده دلیل می‌خواهد ----
[, $pp] = $req('store/product.php?id=' . $strap);
T::ok(str_contains($pp, 'id="bought"') && str_contains($pp, 'پخشِ نمونه'), '«از چه کسی خریده شد» روی صفحه‌ی کالا، با نامِ فروشنده');
$adj = ['csrf_token' => $field($pp, 'csrf_token'), 'action' => 'adjust', 'actual_qty' => '10', 'note' => ''];
$req('store/product.php?id=' . $strap, $adj);
T::same(3.0, $stock($strap), '⛔ افزایش با انبارگردانی بی‌دلیل رد شد («موجودیِ الکی» نه)');
$req('store/product.php?id=' . $strap, ['note' => 'اضافه‌ی شمارشِ پایانِ ماه'] + $adj);
T::same(10.0, $stock($strap), 'با دلیلِ نوشته‌شده پذیرفته شد');
$req('store/product.php?id=' . $strap, ['actual_qty' => '9'] + $adj);
T::same(9.0, $stock($strap), 'کاهش (کسریِ شمارش) دلیل لازم ندارد');

// ---- صفحه‌ی کالا: همان خرید، بی‌فاکتور ----
T::group('صفحه‌ی کالا: «خرید و افزودنِ موجودی»');
[$c, $pp] = $req('store/product.php?id=' . $case);
T::ok($c === 200 && str_contains($pp, 'id="quickbuy"') && preg_match('/<option value="\d+" selected>بنکداریِ تازه/u', $pp) === 1,
    'کارتِ خرید هست و آخرین فروشنده‌ی همین کالا پیش‌فرض است', "کد: {$c}");
$qb = ['csrf_token' => $field($pp, 'csrf_token'), '_once' => $field($pp, '_once'), 'action' => 'quickbuy',
       'qb_party' => (string)$sup, 'qb_qty' => '۴', 'qb_buy' => '31,000', 'qb_sell' => '', 'qb_date' => BizDocView::jDate($today), 'qb_pay' => 'credit'];
$p0 = $purchases();
[$c, , $loc] = $req('store/product.php?id=' . $case, $qb);
T::ok($c === 302, 'خرید از صفحه‌ی کالا ثبت شد', "{$c} {$loc}");
T::same(4.0, $stock($case), '⛔ موجودی ۴ شد (ارقامِ فارسی و جداکننده فهمیده شد)');
$req('store/product.php?id=' . $case, $qb);
T::same($p0 + 1, $purchases(), '⛔ تکرارِ همان فرم خریدِ دوم نمی‌سازد');
[$c] = $req('store/product.php?id=' . $case, ['action' => 'quickbuy'] + array_diff_key($qb, ['csrf_token' => 1]));
T::same(403, $c, '⛔ بی‌توکن رد می‌شود (CSRF)');

exit(T::report());
