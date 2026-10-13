<?php
/**
 * ⛔ محیطِ فروشگاهی — مرحله‌ی ۲: کالا و موجودی، طرف‌حساب، صندوق.
 *
 * سه خطرِ اصلی، هر کدام جدا:
 *   ۱. **موجودی دروغ بگوید** — میانگینِ بها غلط، کشِ `stock_qty` با
 *      حرکت‌ها نخواند، یا موجودی در لحظه‌ای منفی شود.
 *   ۲. **نشت بین فروشگاه‌ها** — کاربرِ B کالا یا طرف‌حسابِ A را ببیند یا
 *      دست بزند.
 *   ۳. **پولِ مغازه با پولِ خانه قاطی شود** — هیچ عملیاتِ فروشگاهی نباید
 *      `walletBalances()`ِ شخصی را تکان دهد.
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

$root = dirname(__DIR__);
const CPREFIX = '__bcat_';
const CPASS   = 'Cat12345';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::blocked('کالا و موجودیِ فروشگاه', 'اتصال به دیتابیس برقرار نشد: ' . $e->getMessage());
    exit(T::report());
}
if (!Biz::available() || !tableExists('biz_accounts')) {
    T::blocked('کالا و موجودیِ فروشگاه', 'جدول‌های فروشگاه نیست — اول: bash deploy/migrate.sh --apply');
    exit(T::report());
}

$wipe = function () use ($pdo) {
    $ids = $pdo->query("SELECT id FROM users WHERE username LIKE '" . CPREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        foreach (['biz_stock_moves', 'biz_products', 'biz_parties', 'biz_accounts', 'biz_settings'] as $t) {
            $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]);
        }
        foreach (userDataTables() as $t) {
            try { $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]); }
            catch (PDOException $e) { /* جدولی که نیست */ }
        }
        $pdo->prepare('DELETE FROM audit_log WHERE target_user_id = :u')->execute(['u' => $id]);
    }
    $pdo->exec("DELETE FROM users WHERE username LIKE '" . CPREFIX . "%'");
    $pdo->exec("DELETE FROM login_attempts WHERE username_tried LIKE '" . CPREFIX . "%'");
};
$wipe();

$make = function (string $name) use ($pdo): int {
    $res = createUserAccount($pdo, 'کاربرِ ' . $name, CPREFIX . $name, CPREFIX . $name . '@example.com', CPASS);
    if (!($res['ok'] ?? false)) { throw new RuntimeException('ساختِ کاربر: ' . ($res['error'] ?? '?')); }
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => CPREFIX . $name]);
    return (int)$st->fetchColumn();
};
$a = $make('a');
$b = $make('b');
Biz::setType($a, 'both');
Biz::setType($b, 'business');

$walletsBefore = json_encode(walletBalances($a));

// =================================================================
T::group('۱ — تعریفِ کالا');

$r = BizProducts::save($a, ['name' => '  ']);
T::ok(!$r['ok'], 'نامِ خالی رد می‌شود');
$r = BizProducts::save($a, ['name' => 'x', 'unit' => 'بشکه']);
T::ok(!$r['ok'], 'واحدِ ناشناخته رد می‌شود');
$r = BizProducts::save($a, ['name' => 'x', 'unit' => 'عدد', 'min_stock' => '2.5']);
T::ok(!$r['ok'], '⛔ مقدارِ اعشاری برای واحدِ «عدد» رد می‌شود');
T::ok(BizProducts::qtyFits('کیلوگرم', 2.5) && !BizProducts::qtyFits('عدد', 2.5) && BizProducts::qtyFits('عدد', 3.0),
    'قاعده‌ی اعشار از `UNITS` می‌آید');

$r = BizProducts::save($a, ['name' => 'قابِ گوشی', 'sku' => '۱۲۳-AB', 'category' => 'لوازم جانبی', 'unit' => 'عدد',
    'buy_price' => '۱۰۰٬۰۰۰', 'sell_price' => '150000', 'min_stock' => '3', 'opening_qty' => '10']);
T::ok($r['ok'], 'کالا با موجودیِ اول دوره ساخته شد', $r['message']);
$p1 = (int)$r['id'];
$row = BizProducts::get($a, $p1);
T::same('123-AB', $row['sku'], 'ارقامِ فارسیِ کد لاتین شد');
T::same(100000, (int)$row['buy_price'], 'قیمت با جداکننده و ارقامِ فارسی خوانده شد');
T::same(10.0, (float)$row['stock_qty'], 'موجودیِ اول دوره نشست');
T::same(100000.0, (float)$row['avg_cost'], 'بهای اول دوره = قیمتِ خرید وقتی خالی است');

$r = BizProducts::save($a, ['name' => 'تکراری', 'sku' => '123-AB', 'unit' => 'عدد']);
T::ok(!$r['ok'] && str_contains($r['message'], 'کد'), '⛔ کدِ تکراری در همان فروشگاه رد می‌شود');
$r = BizProducts::save($b, ['name' => 'همان کد در فروشگاهِ دیگر', 'sku' => '123-AB', 'unit' => 'عدد']);
T::ok($r['ok'], 'همان کد در فروشگاهِ دیگر مجاز است — یکتایی به‌ازای هر کاربر');
$pb = (int)$r['id'];

$r = BizProducts::save($a, ['name' => 'نصبِ گلس', 'unit' => 'عدد', 'sell_price' => '50000', 'is_service' => '1', 'opening_qty' => '5']);
T::ok(!$r['ok'], '⛔ خدمت موجودیِ اول دوره نمی‌گیرد');
$r = BizProducts::save($a, ['name' => 'نصبِ گلس', 'unit' => 'عدد', 'sell_price' => '50000', 'is_service' => '1']);
$svc = (int)$r['id'];
T::ok($r['ok'] && BizProducts::stockState(BizProducts::get($a, $svc)) === 'service', 'خدمت ثبت شد و «موجودی» ندارد');
T::ok(!BizStock::adjustTo($a, $svc, 3)['ok'], '⛔ خدمت انبارگردانی نمی‌شود');

$r = BizProducts::save($a, ['name' => 'قابِ گوشی', 'unit' => 'عدد', 'is_service' => '1'], $p1);
T::ok(!$r['ok'], '⛔ کالای دارای موجودی خدمت نمی‌شود — موجودی‌اش بی‌صدا از ارزشِ انبار می‌افتاد');

// =================================================================
T::group('۲ — موجودی: میانگینِ متحرک و هرگز منفی');

$r = BizStock::adjustTo($a, $p1, 6, 'شمارش');
T::ok($r['ok'], 'انبارگردانی ثبت شد', $r['message']);
$row = BizProducts::get($a, $p1);
T::same(6.0, (float)$row['stock_qty'], 'موجودی به عددِ شمارش‌شده رسید');
T::same(100000.0, (float)$row['avg_cost'], 'خروج میانگین را عوض نمی‌کند');
$r = BizStock::adjustTo($a, $p1, 6);
T::ok($r['ok'] && str_contains($r['message'], 'همان'), 'شمارشِ برابر هیچ حرکتی نمی‌سازد');
T::same(2, (int)$pdo->query("SELECT COUNT(*) FROM biz_stock_moves WHERE product_id = {$p1}")->fetchColumn(),
    'فقط دو حرکت: اول دوره و یک انبارگردانی');
T::ok(!BizStock::adjustTo($a, $p1, -1)['ok'], 'موجودیِ واقعیِ منفی رد می‌شود');

// ورودی با بهای دیگر — از مرحله‌ی ۳ «خرید» است؛ اینجا مستقیم نشانده می‌شود
// تا خودِ `recalc()` سنجیده شود، نه نسخه‌ی دومی از آن.
$pdo->prepare("INSERT INTO biz_stock_moves (user_id, product_id, move_date, kind, qty, unit_cost)
               VALUES (:u, :p, CURDATE(), 'purchase', 10, 200000)")->execute(['u' => $a, 'p' => $p1]);
$rc = BizStock::recalc($a, $p1);
T::same(16.0, $rc['qty'], 'موجودی: ۶ + ۱۰');
T::same(162500.0, $rc['avg'], '⛔ میانگینِ موزون: (۶×۱۰۰٬۰۰۰ + ۱۰×۲۰۰٬۰۰۰) ÷ ۱۶ = ۱۶۲٬۵۰۰');
$pdo->prepare("INSERT INTO biz_stock_moves (user_id, product_id, move_date, kind, qty, unit_cost)
               VALUES (:u, :p, CURDATE(), 'sale', -4, 999999)")->execute(['u' => $a, 'p' => $p1]);
$rc = BizStock::recalc($a, $p1);
T::ok($rc['qty'] === 12.0 && $rc['avg'] === 162500.0, '⛔ خروج میانگین را عوض نمی‌کند (متحرک، نه دوره‌ای)');
$r = BizStock::adjustTo($a, $p1, 14);
$rc = BizStock::recalc($a, $p1);
T::ok($r['ok'] && $rc['avg'] === 162500.0, 'ورودِ انبارگردانی به میانگینِ جاری ارزش‌گذاری می‌شود');
$row = BizProducts::get($a, $p1);
T::ok((float)$row['stock_qty'] === $rc['qty'] && (float)$row['avg_cost'] === $rc['avg'],
    '⛔ کشِ روی ردیف با محاسبه‌ی دوباره از حرکت‌ها یکی است');
$pdo->prepare("DELETE FROM biz_stock_moves WHERE product_id = :p AND kind IN ('purchase','sale')")->execute(['p' => $p1]);
$rc = BizStock::recalc($a, $p1);
// ۱۰ − ۴ + ۲ (انبارگردانیِ «۱۲ → ۱۴» که حالا به میانگینِ همان لحظه، ۱۰۰٬۰۰۰، ارزش دارد)
T::ok($rc['qty'] === 8.0 && $rc['avg'] === 100000.0, 'برداشتنِ یک ورودیِ گذشته همه‌ی عددها را از نو و درست می‌سازد',
    "qty={$rc['qty']} avg={$rc['avg']}");

// «در هیچ لحظه‌ای منفی نشود» — نه فقط در پایان
$r2 = BizProducts::save($a, ['name' => 'شارژر', 'unit' => 'عدد', 'buy_price' => '80000', 'opening_qty' => '10']);
$p2 = (int)$r2['id'];
BizStock::adjustTo($a, $p2, 2);
$r = BizStock::setOpening($a, $p2, 5, 80000);
T::ok(!$r['ok'] && str_contains($r['message'], 'منفی'),
    '⛔ کم کردنِ اول دوره که موجودی را در میانه منفی می‌کند رد می‌شود (۵ − ۸)', $r['message']);
T::same(2.0, (float)BizProducts::get($a, $p2)['stock_qty'], 'و rollback شد — موجودی همان ۲ ماند');
T::same(10.0, (float)$pdo->query("SELECT qty FROM biz_stock_moves WHERE product_id = {$p2} AND kind = 'opening'")->fetchColumn(),
    'ردیفِ اول دوره هم دست‌نخورده ماند');
$adjId = (int)$pdo->query("SELECT id FROM biz_stock_moves WHERE product_id = {$p2} AND kind = 'adjust'")->fetchColumn();
$r = BizStock::deleteMove($a, $adjId);
T::ok($r['ok'] && (float)BizProducts::get($a, $p2)['stock_qty'] === 10.0, 'حذفِ انبارگردانی موجودی را برگرداند');
$openId = (int)$pdo->query("SELECT id FROM biz_stock_moves WHERE product_id = {$p2} AND kind = 'opening'")->fetchColumn();
T::ok(!BizStock::deleteMove($a, $openId)['ok'], 'اول دوره از مسیرِ «حذفِ حرکت» حذف نمی‌شود');
$r = BizStock::setOpening($a, $p2, 0, 0);
T::ok($r['ok'] && (float)BizProducts::get($a, $p2)['stock_qty'] === 0.0, 'اول دوره‌ی صفر حذف می‌شود');

$r3 = BizProducts::save($a, ['name' => 'برنج', 'unit' => 'کیلوگرم', 'buy_price' => '90000', 'opening_qty' => '12.5']);
T::ok($r3['ok'] && (float)BizProducts::get($a, (int)$r3['id'])['stock_qty'] === 12.5, 'کیلوگرم اعشار می‌پذیرد');
T::ok(!BizStock::adjustTo($a, $p1, 3.5)['ok'], '⛔ انبارگردانیِ اعشاری برای «عدد» رد می‌شود');

// =================================================================
T::group('۳ — فهرست، جست‌وجو، صافی و خلاصه');

$l = BizProducts::list($a, 'قاب');
T::ok($l['total'] === 1 && (int)$l['rows'][0]['id'] === $p1, 'جست‌وجوی نام');
T::same(1, BizProducts::list($a, '123-ab')['total'], 'جست‌وجوی کد');
T::same(0, BizProducts::list($a, '%')['total'], '⛔ `%` فرار داده می‌شود — کلِ فهرست را برنمی‌گرداند');
T::same(0, BizProducts::list($a, '_')['total'], '⛔ `_` هم');
T::same(1, BizProducts::list($a, '', 'out')['total'], 'صافیِ «ناموجود» (شارژرِ صفر)');
BizProducts::setActive($a, $p2, false);
T::ok(BizProducts::list($a, '', 'inactive')['total'] === 1 && BizProducts::list($a, '', 'out')['total'] === 0,
    'غیرفعال از فهرستِ اصلی بیرون و در «غیرفعال» است');
T::same(1, BizProducts::list($a, '', '', 'لوازم جانبی')['total'], 'صافیِ دسته');
T::same(['لوازم جانبی'], BizProducts::categories($a), 'دسته‌ها از خودِ کالاها کشف می‌شوند');
$sum = BizProducts::summary($a);
$exp = (int)round(8 * 100000 + 12.5 * 90000);
T::same($exp, $sum['value'], '⛔ ارزشِ انبار = Σ موجودی × میانگینِ بها (خدمت و غیرفعال بیرون)');
BizProducts::setActive($a, $p2, true);

// صفحه‌بندی: شماره‌ی بیرون از بازه بریده می‌شود
for ($i = 1; $i <= BizProducts::PAGE_SIZE + 2; $i++) {
    BizProducts::save($b, ['name' => 'کالای ' . $i, 'unit' => 'عدد']);
}
$l = BizProducts::list($b, '', '', '', 99);
T::ok($l['pages'] === 2 && $l['page'] === 2 && count($l['rows']) === $l['total'] - BizProducts::PAGE_SIZE,
    'صفحه‌ی بیرون از بازه به آخرین صفحه بریده شد', "total={$l['total']} rows=" . count($l['rows']));

// =================================================================
T::group('۴ — طرف‌حساب');

$r = BizParties::save($a, ['name' => 'علی رضایی', 'kind' => 'customer', 'phone' => '۰۹۱۲ ۱۲۳ ۴۵۶۷',
    'opening_amount' => '۲٬۰۰۰٬۰۰۰', 'opening_side' => 'they']);
T::ok($r['ok'], 'مشتری ساخته شد', $r['message']);
$c1 = (int)$r['id'];
$r = BizParties::save($a, ['name' => 'پخشِ نمونه', 'kind' => 'supplier', 'opening_amount' => '500000', 'opening_side' => 'we']);
$s1 = (int)$r['id'];
BizParties::save($a, ['name' => 'هم مشتری هم فروشنده', 'kind' => 'both']);
T::same(2000000, (int)BizParties::get($a, $c1)['balance'], 'مانده‌ی «او بدهکار است» مثبت');
T::same(-500000, (int)BizParties::get($a, $s1)['balance'], '⛔ مانده‌ی «فروشگاه بدهکار است» منفی');
T::same('09121234567', str_replace(' ', '', (string)BizParties::get($a, $c1)['phone']), 'ارقامِ فارسیِ تلفن لاتین شد');
T::ok(!BizParties::save($a, ['name' => 'x', 'kind' => 'friend'])['ok'], 'نوعِ ناشناخته رد می‌شود');
T::ok(!BizParties::save($a, ['name' => 'x', 'phone' => 'abc'])['ok'], 'تلفنِ نامعتبر رد می‌شود');
$ps = BizParties::summary($a);
T::ok($ps['receivable'] === 2000000 && $ps['payable'] === 500000 && $ps['debtors'] === 1 && $ps['creditors'] === 1,
    'خلاصه: طلب و بدهی جدا جمع می‌شوند، نه خالص');
T::same(2, BizParties::list($a, '', 'customer')['total'], 'صافیِ «مشتری‌ها» نوعِ «هر دو» را هم دارد');
T::same(1, BizParties::list($a, '', 'debtor')['total'], 'صافیِ بدهکاران');
T::same(1, BizParties::list($a, '0912')['total'], 'جست‌وجوی تلفن');
T::same(1, BizParties::list($a, '۰۹۱۲')['total'], 'جست‌وجوی تلفن با ارقامِ فارسی');

// =================================================================
T::group('۵ — صندوق‌های فروشگاه');

$acc = BizCash::list($a);
T::ok(count($acc) === 1 && $acc[0]['name'] === 'صندوق' && $acc[0]['kind'] === 'cash', 'بارِ اول یک «صندوق» ساخته شد');
T::same(1, count(BizCash::list($a)), 'بارِ دوم دوباره ساخته نمی‌شود');
$r = BizCash::save($a, ['name' => 'کارت‌خوانِ ملت', 'kind' => 'pos', 'opening_balance' => '3,000,000']);
T::ok($r['ok'], 'کارت‌خوان اضافه شد');
$pos = (int)$r['id'];
BizCash::save($a, ['name' => 'صندوق', 'kind' => 'cash', 'opening_balance' => '1000000'], (int)$acc[0]['id']);
T::same(4000000, BizCash::total(BizCash::list($a)), 'جمعِ صندوق‌ها');
// ⛔ حسابِ موجودی‌دار غیرفعال نمی‌شود — پولش بی‌صدا از جمع بیرون می‌افتاد
//    (بازرسیِ مهر ۱۴۰۵؛ این تست پیش‌تر همان خرابی را «درست» می‌دانست)
T::ok(!BizCash::setActive($a, $pos, false)['ok'], '⛔ حسابِ موجودی‌دار غیرفعال نمی‌شود');
T::same(4000000, BizCash::total(BizCash::list($a)), 'و پولش در جمع می‌ماند');
$zero = (int)BizCash::save($a, ['name' => 'حسابِ خالی', 'kind' => 'bank'])['id'];
T::ok(BizCash::setActive($a, $zero, false)['ok'], 'حسابِ بی‌موجودی غیرفعال می‌شود');
T::same(4000000, BizCash::total(BizCash::list($a)), 'حسابِ غیرفعال در جمع نیست (و چیزی هم کم نشد)');
$r = BizCash::setActive($a, (int)$acc[0]['id'], false);
T::ok(!$r['ok'], '⛔ آخرین صندوقِ فعال غیرفعال نمی‌شود', $r['message']);
T::ok(!BizCash::save($a, ['name' => 'x', 'kind' => 'wallet'])['ok'], 'نوعِ ناشناخته رد می‌شود');

// =================================================================
T::group('۶ — جداسازی: فروشگاه‌ها از هم، و مغازه از خانه');

T::same(null, BizProducts::get($b, $p1), '⛔ کاربرِ B کالای A را نمی‌بیند');
T::ok(!BizProducts::save($b, ['name' => 'دزدی', 'unit' => 'عدد'], $p1)['ok'] && BizProducts::get($a, $p1)['name'] === 'قابِ گوشی',
    '⛔ و ویرایشش نمی‌کند');
T::ok(!BizStock::adjustTo($b, $p1, 0)['ok'] && (float)BizProducts::get($a, $p1)['stock_qty'] === 8.0,
    '⛔ و موجودی‌اش را عوض نمی‌کند');
T::ok(!BizStock::adjustTo($b, $p1, 100)['ok']
    && (int)$pdo->query("SELECT COUNT(*) FROM biz_stock_moves WHERE product_id = {$p1} AND user_id = {$b}")->fetchColumn() === 0,
    '⛔ و هیچ حرکتی به نامِ B روی کالای A نمی‌نشیند — حتی وقتی موجودی منفی نمی‌شود');
T::ok(!BizProducts::delete($b, $p1)['ok'] && BizProducts::get($a, $p1) !== null, '⛔ و حذفش نمی‌کند');
T::ok(!BizStock::deleteMove($b, $openId)['ok'], '⛔ حرکتِ انبارِ A از B حذف نمی‌شود');
T::same(null, BizParties::get($b, $c1), '⛔ طرف‌حسابِ A برای B نیست');
T::ok(!BizParties::delete($b, $c1)['ok'] && BizParties::get($a, $c1) !== null, '⛔ و حذفش نمی‌کند');
T::ok(!BizCash::setActive($b, $pos, true)['ok'], '⛔ صندوقِ A از B دست نمی‌خورد');
T::same(0, BizProducts::list($b, 'قاب')['total'], '⛔ جست‌وجوی B به کالای A نمی‌رسد');
T::same($walletsBefore, json_encode(walletBalances($a)),
    '⛔ هیچ‌کدام از عملیاتِ فروشگاه موجودیِ حساب‌های شخصیِ همان کاربر را تکان نداد');
T::same(0, (int)$pdo->query("SELECT COUNT(*) FROM transactions WHERE user_id = {$a}")->fetchColumn(),
    '⛔ و هیچ ردیفِ `transactions` ساخته نشد');

// =================================================================
T::group('۷ — فرم‌ها از راهِ HTTP');

$port = 0;
for ($pp = 9061; $pp <= 9110; $pp++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$pp", $e1, $e2);
    if ($sock) { fclose($sock); $port = $pp; break; }
}
$log = tempnam(sys_get_temp_dir(), 'bcat');
$srv = $port ? (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
    $port, escapeshellarg($root), escapeshellarg($log)))) : 0;
$up = false;
for ($i = 0; $srv && $i < 40; $i++) {
    usleep(150000);
    $sk = @fsockopen('127.0.0.1', $port, $x, $y, 0.3);
    if ($sk) { fclose($sk); $up = true; break; }
}
if (!$up) {
    T::blocked('فرم‌های فروشگاه (HTTP)', 'سرورِ آزمایشی بالا نیامد');
    if ($srv) { exec("kill $srv 2>/dev/null"); }
    $wipe();
    exit(T::report());
}
$jar = tempnam(sys_get_temp_dir(), 'bcatjar');
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
[$c, , $loc] = $req('store/login.php', ['csrf_token' => $csrfOf($lp), 'username' => CPREFIX . 'a', 'password' => CPASS]);
T::ok($c === 302 && str_ends_with($loc, '/store/'), 'حسابِ «شخصی + فروشگاه» از درِ فروشگاه وارد شد', "{$c} {$loc}");

foreach (['store/index.php', 'store/products.php', 'store/product.php', 'store/product.php?id=' . $p1,
          'store/parties.php', 'store/party.php', 'store/party.php?id=' . $c1, 'store/settings.php'] as $pg) {
    [$c, $body] = $req($pg);
    T::ok($c === 200 && str_contains($body, '</html>'), "«{$pg}» کامل رندر شد", "کد: {$c}");
}
[, $dash] = $req('store/index.php');
T::ok(str_contains($dash, formatMoney(1000000)) && str_contains($dash, formatMoney(2000000)),
    'داشبورد صندوق و طلب را از دفترِ فروشگاه نشان می‌دهد');

[, $form] = $req('store/product.php');
$tok = $csrfOf($form);
[$c, , $loc] = $req('store/product.php', ['csrf_token' => $tok, 'action' => 'save', 'name' => 'هندزفری <b>',
    'unit' => 'عدد', 'buy_price' => '40000', 'sell_price' => '65000', 'opening_qty' => '7', 'category' => 'لوازم جانبی']);
T::ok($c === 302 && preg_match('~store/product\.php\?id=(\d+)$~', $loc, $mm), 'فرمِ کالای تازه ← ریدایرکت به صفحه‌ی کالا', "{$c} {$loc}");
$pn = (int)($mm[1] ?? 0);
$row = BizProducts::get($a, $pn);
T::ok($row && (float)$row['stock_qty'] === 7.0, 'کالا و موجودیِ اول دوره از راهِ فرم ثبت شد');
[, $pl] = $req('store/products.php?q=' . urlencode('هندزفری'));
T::ok(str_contains($pl, 'هندزفری &lt;b&gt;') && !str_contains($pl, 'هندزفری <b>'), '⛔ نامِ کالا در فهرست فرار داده می‌شود');

[$c, , $loc] = $req('store/product.php?id=' . $pn, ['csrf_token' => $tok, 'action' => 'adjust', 'actual_qty' => '۵', 'note' => 'شکستگی']);
T::ok($c === 302 && (float)BizProducts::get($a, $pn)['stock_qty'] === 5.0, 'انبارگردانی از راهِ فرم (ارقامِ فارسی)', "{$c}");
[$c] = $req('store/product.php?id=' . $pn, ['action' => 'adjust', 'actual_qty' => '1']);
T::ok((float)BizProducts::get($a, $pn)['stock_qty'] === 5.0, '⛔ بدونِ CSRF چیزی عوض نمی‌شود', "کد: {$c}");

[$c] = $req('store/product.php?id=' . $pb);
T::ok($c === 302, '⛔ صفحه‌ی کالای فروشگاهِ دیگر باز نمی‌شود (برگشت به فهرست)', "کد: {$c}");
[$c] = $req('store/product.php?id=' . $pb, ['csrf_token' => $tok, 'action' => 'delete']);
T::ok(BizProducts::get($b, $pb) !== null, '⛔ و فرمِ حذف با شناسه‌ی آن کالا چیزی را پاک نمی‌کند', "کد: {$c}");

[$c, , $loc] = $req('store/party.php', ['csrf_token' => $tok, 'action' => 'save', 'name' => 'مشتریِ فرم',
    'kind' => 'customer', 'opening_amount' => '120000', 'opening_side' => 'we']);
T::ok($c === 302 && preg_match('~party\.php\?id=(\d+)$~', $loc, $mp)
    && (int)BizParties::get($a, (int)$mp[1])['balance'] === -120000, 'طرف‌حساب از راهِ فرم، با جهتِ «فروشگاه بدهکار است»', "{$c} {$loc}");

[$c, , $loc] = $req('store/accounts.php', ['csrf_token' => $tok, 'action' => 'cash_save', 'acc_id' => '0',
    'name' => 'بانکِ ملی', 'kind' => 'bank', 'opening_balance' => '250000']);
T::ok($c === 302 && BizCash::total(BizCash::list($a)) === 4250000, 'صندوقِ تازه از راهِ صفحه‌ی «صندوق و بانک»', "{$c}");
[$c, , $loc] = $req('store/settings.php', ['csrf_token' => $tok, 'shop_name' => 'فروشگاهِ آ']);
$st = $pdo->prepare('SELECT shop_name FROM biz_settings WHERE user_id = :u');
$st->execute(['u' => $a]);
T::same('فروشگاهِ آ', (string)$st->fetchColumn(), 'فرمِ سربرگ هم هنوز کار می‌کند');

// =================================================================
T::group('۷ب — چیدمان در کرومیوم: گوشی و دسکتاپ، بی‌اسکرولِ افقی');

// ⚠ نامِ بلندِ بی‌فاصله و عددِ درشت عمداً در fixture است — بدونِ آن‌ها
//   «بیرون نمی‌زند» روی هر چیدمانی سبز می‌ماند.
BizProducts::save($a, ['name' => str_repeat('بلندترین‌نام', 8), 'sku' => 'LONG-SKU-0000000000001', 'unit' => 'عدد',
    'buy_price' => '999999999', 'sell_price' => '1999999999', 'opening_qty' => '9999', 'category' => str_repeat('دسته', 10)]);
BizParties::save($a, ['name' => str_repeat('طرف‌حساب‌بلند', 6), 'kind' => 'both', 'phone' => '+98 912 123 4567',
    'opening_amount' => '99999999999']);
$node = trim((string)shell_exec('command -v node 2>/dev/null'));
if ($node === '') {
    T::skip('چیدمانِ فروشگاه در کرومیوم', 'node نیست');
} else {
    $probePages = ['store/index.php', 'store/products.php', 'store/product.php?id=' . $p1,
                   'store/parties.php', 'store/party.php?id=' . $c1, 'store/settings.php',
                   'store/search.php?q=' . rawurlencode('بلند')];
    $out = (string)shell_exec(sprintf('%s %s %s %s %s %s 2>/dev/null', escapeshellarg($node),
        escapeshellarg(__DIR__ . '/store_probe.js'), escapeshellarg("http://127.0.0.1:{$port}/"),
        escapeshellarg(CPREFIX . 'a'), escapeshellarg(CPASS), escapeshellarg(json_encode($probePages)))
        . (getenv('STORE_SHOTS') ? ' ' . escapeshellarg((string)getenv('STORE_SHOTS')) : ''));
    $pr = json_decode(trim($out), true);
    if (!is_array($pr) || empty($pr['ok'])) {
        T::skip('چیدمانِ فروشگاه در کرومیوم', 'کرومیوم در دسترس نیست: ' . ($pr['why'] ?? trim($out)));
    } else {
        T::same(count($probePages) * 2, count($pr['res']), 'هر هفت صفحه (با جستجوی نامِ بلند) روی دو عرض سنجیده شد');
        foreach ($pr['res'] as $m) {
            T::ok($m['sw'] <= $m['W'] && !$m['out'], "«{$m['pg']}» روی {$m['w']}: بی‌اسکرولِ افقی و بی‌بیرون‌زدگی",
                "scrollWidth={$m['sw']} width={$m['W']} " . implode(' | ', $m['out']));
            // ⛔ «همه چیز راست‌چین جز عدد» — خواسته‌ی مالکِ نصب
            T::ok(!$m['rtl'], "«{$m['pg']}» روی {$m['w']}: هر متنِ فارسی rtl و راست‌چین است", implode(' | ', $m['rtl'] ?? ['?']));
            if ($m['w'] >= 900) {
                T::ok(!empty($m['side']['shown']) && abs($m['side']['right'] - $m['W']) <= 1,
                    "«{$m['pg']}» روی {$m['w']}: نوارِ کناری دیده می‌شود و به لبه‌ی **راست** چسبیده", json_encode($m['side']));
            } else {
                T::ok(empty($m['side']['shown']), "«{$m['pg']}» روی {$m['w']}: نوارِ کناری تا زدنِ «منو» پنهان است", json_encode($m['side']));
                T::ok(isset($m['drawer']['right']) && abs($m['drawer']['right'] - $m['W']) <= 1 && $m['drawer']['left'] > 0,
                    "«{$m['pg']}» روی {$m['w']}: «منو» کشو را از لبه‌ی راست باز می‌کند", json_encode($m['drawer']));
            }
        }
    }
}

// حسابِ شخصی هیچ‌کدام از این صفحه‌ها را نمی‌بیند
Biz::setType($a, 'personal');
foreach (['store/products.php', 'store/product.php?id=' . $p1, 'store/parties.php'] as $pg) {
    [$c] = $req($pg);
    T::same(404, $c, "⛔ بعد از خاموش شدنِ فروشگاه «{$pg}» ۴۰۴ است");
}
T::ok(BizProducts::get($a, $p1) !== null, 'خاموش کردنِ فروشگاه داده‌اش را پاک نمی‌کند');

exec("kill $srv 2>/dev/null");
@unlink($log);
@unlink($jar);

// =================================================================
T::group('۸ — حذفِ حساب همه‌ی داده‌ی فروشگاه را می‌برد');

foreach (['biz_products', 'biz_stock_moves', 'biz_parties', 'biz_accounts'] as $t) {
    T::ok(in_array($t, userDataTables(), true), "`{$t}` از روی ستونِ user_id کشف می‌شود (خروجی و حذفِ حساب)");
}

$wipe();
exit(T::report());
