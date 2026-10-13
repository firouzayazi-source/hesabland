<?php
/**
 * ⛔ بستنِ دوره و چکِ خرجی (واگذاریِ چکِ مشتری به فروشنده).
 *
 * بستنِ دوره: خطرِ اصلی **دری است که از قلم افتاده** — یک مسیرِ نوشتن که
 * تاریخِ روزِ بسته را می‌پذیرد و سودِ ماهِ بسته را بی‌صدا عوض می‌کند. پس
 * هر مسیر جدا امتحان می‌شود و در آخر سنجیده می‌شود که عددهای ماهِ بسته
 * (سود، بهای تمام‌شده، مانده‌ها) واقعاً تکان نخورده‌اند.
 *
 * چکِ خرجی: خطرِ اصلی **پولِ دو بار شمرده‌شده یا گم‌شده** است — چکی که هم
 * در دستِ ماست هم پیشِ فروشنده، یا پرداختی بی‌چک. پس بعد از هر قدم مانده‌ی
 * هر دو طرف، صندوقِ چک و جمعِ نقد سنجیده می‌شود.
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
require_once __DIR__ . '/../includes/user_import.php';
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_docs.php';
require_once __DIR__ . '/../includes/biz_reports.php';
require_once __DIR__ . '/../includes/biz_docview.php';

$root = dirname(__DIR__);
const LPREFIX = '__block_';
const LPASS   = 'Lock12345';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::blocked('بستنِ دوره', 'اتصال به دیتابیس برقرار نشد: ' . $e->getMessage());
    exit(T::report());
}
if (!Biz::available() || !tableHasColumn('biz_settings', 'lock_date') || !BizCheques::ready()) {
    T::blocked('بستنِ دوره', 'ستونِ biz_settings.lock_date نیست — اول: bash deploy/migrate.sh --apply');
    exit(T::report());
}

$wipe = function () use ($pdo) {
    $ids = $pdo->query("SELECT id FROM users WHERE username LIKE '" . LPREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        $pdo->prepare('UPDATE biz_payments SET cheque_settle_id = NULL WHERE user_id = :u')->execute(['u' => $id]);
        foreach (['biz_allocations', 'biz_payments', 'biz_stock_moves'] as $t) {
            $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]);
        }
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
    $pdo->exec("DELETE FROM users WHERE username LIKE '" . LPREFIX . "%'");
    $pdo->exec("DELETE FROM login_attempts WHERE username_tried LIKE '" . LPREFIX . "%'");
};
$wipe();

$make = function (string $name) use ($pdo): int {
    $res = createUserAccount($pdo, 'کاربرِ ' . $name, LPREFIX . $name, LPREFIX . $name . '@example.com', LPASS);
    if (!($res['ok'] ?? false)) { throw new RuntimeException('ساختِ کاربر: ' . ($res['error'] ?? '?')); }
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => LPREFIX . $name]);
    return (int)$st->fetchColumn();
};
$a = $make('a');
$b = $make('b');
Biz::setType($a, 'business');
Biz::setType($b, 'business');

$acc = function (int $u, string $kind = 'cash'): int {
    $r = BizCash::save($u, ['name' => $kind === 'bank' ? 'بانک' : 'صندوق', 'kind' => $kind]);
    return (int)$r['id'];
};
$CASH = $acc($a);
$BANK = $acc($a, 'bank');
$party = fn(int $u, string $n, string $k = 'both'): int => (int)BizParties::save($u, ['name' => $n, 'kind' => $k])['id'];
$CUST = $party($a, 'مشتریِ چک‌دار', 'customer');
$SUP  = $party($a, 'فروشنده‌ی عمده', 'supplier');
$P = (int)BizProducts::save($a, ['name' => 'کالای قفل', 'unit' => 'عدد', 'type' => 'goods'])['id'];
$line = fn(int $q, int $price) => ['product_id' => $P, 'item' => 'کالای قفل', 'qty' => (string)$q, 'price' => (string)$price];
$doc = function (string $kind, string $date, array $lines, int $partyId, array $pay = []) use ($a) {
    $r = BizInvoices::saveDraft($a, $kind, ['party_id' => $partyId, 'inv_date' => $date, 'lines' => $lines]);
    if (!$r['ok']) { throw new RuntimeException('پیش‌نویس: ' . $r['message']); }
    $i = BizInvoices::issue($a, (int)$r['id'], $pay);
    if (!$i['ok']) { throw new RuntimeException('صدور: ' . $i['message']); }
    return (int)$r['id'];
};
$bal  = fn(int $pid): int => (int)BizParties::get($a, $pid)['balance'];
$cashTotal = fn(): int => BizCash::total(BizCash::list($a));
$accBal = function (int $id) use ($a): int {
    foreach (BizCash::list($a) as $x) { if ((int)$x['id'] === $id) { return (int)$x['balance']; } }
    return 0;
};
$locked = fn(array $r): bool => !$r['ok'] && str_contains((string)$r['message'], 'بسته است');

$D0 = date('Y-m-d', strtotime('-40 days'));   // ماهِ بسته
$D1 = date('Y-m-d', strtotime('-35 days'));
$LOCK = date('Y-m-d', strtotime('-30 days'));
$OPEN = date('Y-m-d', strtotime('-5 days'));   // دوره‌ی باز

try {

/* ---------------------------------------------------------------- */
T::group('۱ — تنظیمِ قفل');
T::same(null, Biz::lockDate($a), 'پیش‌فرض: هیچ دوره‌ای بسته نیست');
T::same(null, Biz::lockError($a, $D0), 'بی‌قفل، هیچ تاریخی رد نمی‌شود');
// داده‌ی ماهِ «بسته» پیش از قفل
$doc('purchase', $D0, [$line(10, 100)], $SUP);
$SALE0 = $doc('sale', $D1, [$line(4, 300)], $CUST, ['account_id' => $CASH, 'full' => 1]);
$REC0 = (int)BizPay::create($a, ['kind' => 'receipt', 'party_id' => $CUST, 'account_id' => $CASH, 'amount' => '50', 'pay_date' => $D1])['id'];
BizStock::adjustTo($a, $P, 5, 'شمارش');
$pdo->prepare("UPDATE biz_stock_moves SET move_date = :d WHERE user_id = :u AND kind = 'adjust'")->execute(['d' => $D1, 'u' => $a]);
$pdo->prepare('UPDATE biz_products SET created_at = :d WHERE id = :p')->execute(['d' => $D0 . ' 09:00:00', 'p' => $P]);
$pdo->prepare('UPDATE biz_parties SET created_at = :d WHERE user_id = :u')->execute(['d' => $D0 . ' 09:00:00', 'u' => $a]);
$pdo->prepare('UPDATE biz_accounts SET created_at = :d WHERE user_id = :u')->execute(['d' => $D0 . ' 09:00:00', 'u' => $a]);
BizStock::rebuild($a);
$before = BizReports::sales($a, $D0, $LOCK);
$balCust0 = $bal($CUST); $balSup0 = $bal($SUP); $cash0 = $cashTotal();

T::ok(!Biz::saveLock($a, date('Y-m-d', strtotime('+1 day')))['ok'], 'قفلِ آینده رد می‌شود');
T::ok(!Biz::saveLock($a, '2026-13-40')['ok'], 'تاریخِ نامعتبر رد می‌شود');
$r = Biz::saveLock($a, $LOCK);
T::ok($r['ok'] && Biz::lockDate($a) === $LOCK, 'دوره بسته شد', $r['message']);
T::ok(Biz::lockError($a, $LOCK) !== null && Biz::lockError($a, $D0) !== null, '⛔ خودِ روزِ قفل و پیش از آن بسته‌اند');
T::same(null, Biz::lockError($a, date('Y-m-d', strtotime($LOCK . ' +1 day'))), 'فردای قفل باز است');
T::same(null, Biz::lockError($b, $D0), '⛔ قفلِ فروشگاهِ A روی B اثری ندارد');
$later = date('Y-m-d', strtotime($LOCK . ' +2 days'));
T::ok(Biz::saveLock($a, $later)['ok'], 'جلوتر بردنِ قفل تأیید نمی‌خواهد');
$r = Biz::saveLock($a, $LOCK);
T::ok(!$r['ok'] && Biz::lockDate($a) === $later, '⛔ عقب بردنِ قفل بی‌تأیید رد می‌شود', $r['message']);
T::ok(!Biz::saveLock($a, '')['ok'] && Biz::lockDate($a) === $later, '⛔ باز کردنِ همه بی‌تأیید رد می‌شود');
T::ok(Biz::saveLock($a, $LOCK, true)['ok'] && Biz::lockDate($a) === $LOCK, 'با تأیید عقب می‌رود');

/* ---------------------------------------------------------------- */
T::group('۲ — فاکتور در دوره‌ی بسته');
$r = BizInvoices::saveDraft($a, 'purchase', ['party_id' => $SUP, 'inv_date' => $D1, 'lines' => [$line(10, 1)]]);
T::ok($locked($r), '⛔ پیش‌نویس با تاریخِ بسته همان اول رد می‌شود (نه بعد از تایپِ کلِ فاکتور)', $r['message']);
// پیش‌نویسی که پیش از قفل ساخته شده بود
Biz::saveLock($a, '', true);
$oldDraft = (int)BizInvoices::saveDraft($a, 'purchase', ['party_id' => $SUP, 'inv_date' => $D0, 'lines' => [$line(10, 1)]])['id'];
Biz::saveLock($a, $LOCK);
$r = BizInvoices::issue($a, $oldDraft);
T::ok($locked($r), '⛔ صدورِ پیش‌نویسِ قدیمی با تاریخِ بسته رد می‌شود — خریدِ ۱ تومانی بهای فروشِ بسته را عوض می‌کرد', $r['message']);
T::ok($locked(BizInvoices::void($a, $SALE0)), '⛔ ابطالِ فاکتورِ دوره‌ی بسته');
T::ok($locked(BizInvoices::unissue($a, $SALE0)), '⛔ برگشت به پیش‌نویسِ فاکتورِ دوره‌ی بسته');
$orig = BizInvoices::get($a, $SALE0);
$r = BizInvoices::createReturn($a, $SALE0, [(int)$orig['lines'][0]['id'] => '1'], [], $D1);
T::ok($locked($r), '⛔ برگشت با تاریخِ بسته', $r['message']);
$r = BizInvoices::createReturn($a, $SALE0, [(int)$orig['lines'][0]['id'] => '1'], [], $OPEN);
T::ok($r['ok'], 'برگشتِ همان فاکتور با تاریخِ امروزیِ دوره‌ی باز مجاز است (رویدادِ امروز)', $r['message']);
$S1 = $doc('sale', $OPEN, [$line(1, 300)], $CUST);
T::ok($S1 > 0, 'فاکتورِ دوره‌ی باز صادر می‌شود');

/* ---------------------------------------------------------------- */
T::group('۳ — دریافت/پرداخت و چک در دوره‌ی بسته');
$r = BizPay::create($a, ['kind' => 'expense', 'account_id' => $CASH, 'amount' => '10', 'title' => 'اجاره', 'pay_date' => $D1]);
T::ok($locked($r), '⛔ هزینه با تاریخِ بسته', $r['message']);
T::ok($locked(BizPay::void($a, $REC0)), '⛔ ابطالِ دریافتِ دوره‌ی بسته');
T::ok(BizPay::create($a, ['kind' => 'expense', 'account_id' => $CASH, 'amount' => '10', 'title' => 'اجاره', 'pay_date' => $OPEN])['ok'], 'هزینه‌ی دوره‌ی باز');
Biz::saveLock($a, '', true);
$chOld = (int)BizPay::create($a, ['kind' => 'receipt', 'party_id' => $CUST, 'account_id' => $CASH, 'amount' => '200', 'pay_date' => $D1,
                                  'method' => 'cheque', 'cheque_due' => $OPEN, 'cheque_no' => '111'])['id'];
$chClr = (int)BizPay::create($a, ['kind' => 'receipt', 'party_id' => $CUST, 'account_id' => $CASH, 'amount' => '60', 'pay_date' => $D1,
                                  'method' => 'cheque', 'cheque_due' => $D1, 'cheque_no' => '112'])['id'];
BizCheques::clear($a, $chClr, $BANK, $D1);
Biz::saveLock($a, $LOCK);
T::ok($locked(BizCheques::clear($a, $chOld, $BANK, $D1)), '⛔ وصول با تاریخِ بسته');
T::ok($locked(BizCheques::unclear($a, $chClr)), '⛔ برگرداندنِ وصولی که در دوره‌ی بسته ثبت شده');
// ⛔ برگشتیِ چکی که دریافتش در دوره‌ی بسته است دیگر رد نمی‌شود (مالکِ نصب، مهر ۱۴۰۵): دریافتِ
//    بسته دست نمی‌خورد و سندِ معکوسِ امروز ثبت می‌شود (`migration_biz_pair`، `test_store_pair`).
//    گروهِ ۵ پایین‌تر ثابت می‌کند عددهای دوره‌ی بسته تکان نخوردند.
$r = BizCheques::bounce($a, $chOld);
T::ok($r['ok'], '⛔ برگشتیِ چکِ دوره‌ی بسته با سندِ معکوسِ امروز', $r['message']);
T::same('ok', (string)$pdo->query("SELECT status FROM biz_payments WHERE id = {$chOld}")->fetchColumn(), 'دریافتِ دوره‌ی بسته باطل نشد');
$rev = (int)$pdo->query("SELECT id FROM biz_payments WHERE pair_id = {$chOld}")->fetchColumn();
T::ok(BizPay::void($a, $rev)['ok'], 'ابطالِ سندِ معکوس برگشتی را پس گرفت');
T::ok(BizCheques::clear($a, $chOld, $BANK, $OPEN)['ok'], 'وصولِ همان چک با تاریخِ امروز مجاز است');

/* ---------------------------------------------------------------- */
T::group('۴ — انبار و مانده‌های اول دوره');
T::ok($locked(BizStock::setOpening($a, $P, 20, 50)), '⛔ موجودیِ اول دوره‌ی کالای ساخته‌شده در دوره‌ی بسته');
$mv = (int)$pdo->query("SELECT id FROM biz_stock_moves WHERE user_id = {$a} AND kind = 'adjust' LIMIT 1")->fetchColumn();
T::ok($locked(BizStock::deleteMove($a, $mv)), '⛔ حذفِ انبارگردانیِ دوره‌ی بسته');
T::ok(BizStock::adjustTo($a, $P, 4, 'امروز')['ok'], 'انبارگردانیِ امروز (دوره‌ی باز) مجاز است');
Biz::saveLock($a, date('Y-m-d'));
T::ok($locked(BizStock::adjustTo($a, $P, 3, 'امروزِ بسته')), '⛔ وقتی امروز هم بسته است، انبارگردانی رد می‌شود');
Biz::saveLock($a, $LOCK, true);
$r = BizParties::save($a, ['name' => 'مشتریِ چک‌دار (نامِ تازه)', 'kind' => 'customer', 'opening_amount' => '999', 'opening_side' => 'they'], $CUST);
T::ok($locked($r), '⛔ مانده‌ی اول دوره‌ی طرف‌حسابِ قدیمی', $r['message']);
$r = BizParties::save($a, ['name' => 'مشتریِ چک‌دار', 'kind' => 'customer', 'phone' => '02112345678'], $CUST);
T::ok($r['ok'], 'ویرایشِ نام و تلفنش آزاد است (مانده دست نخورده)', $r['message']);
T::ok($locked(BizCash::save($a, ['name' => 'صندوق', 'kind' => 'cash', 'opening_balance' => '5000'], $CASH)), '⛔ موجودیِ اولیه‌ی صندوقِ قدیمی');
T::ok(BizCash::save($a, ['name' => 'صندوقِ اصلی', 'kind' => 'cash', 'opening_balance' => '0'], $CASH)['ok'], 'تغییرِ نامِ صندوق آزاد است');
$NEWP = (int)BizParties::save($a, ['name' => 'مشتریِ تازه', 'kind' => 'customer', 'opening_amount' => '300', 'opening_side' => 'they'])['id'];
T::ok($NEWP > 0 && $bal($NEWP) === 300, 'طرف‌حسابِ تازه با مانده‌ی قبلی ساخته می‌شود');
$NP = BizProducts::save($a, ['name' => 'کالای تازه', 'unit' => 'عدد', 'type' => 'goods', 'opening_qty' => '3', 'opening_cost' => '70']);
T::ok($NP['ok'], 'کالای تازه با موجودیِ اول دوره ساخته می‌شود');

/* ---------------------------------------------------------------- */
T::group('۵ — عددهای دوره‌ی بسته تکان نخوردند');
$after = BizReports::sales($a, $D0, $LOCK);
T::same([$before['net'], $before['cogs'], $before['gross'], $before['other']], [$after['net'], $after['cogs'], $after['gross'], $after['other']],
    '⛔ فروش، بهای تمام‌شده، سود و کسریِ دوره‌ی بسته همان‌اند');
T::same('issued', BizInvoices::get($a, $SALE0)['status'], 'فاکتورِ بسته صادرشده ماند');
T::same('ok', BizPay::get($a, $REC0)['status'], 'دریافتِ بسته سرِ جایش است');

/* ---------------------------------------------------------------- */
T::group('۶ — چکِ خرجی: واگذاری به فروشنده');
Biz::saveLock($a, '', true);
$PUR = $doc('purchase', $OPEN, [$line(5, 300)], $SUP);                     // بدهیِ ما به فروشنده ۱۵۰۰
$balCust = $bal($CUST); $balSup = $bal($SUP); $cashB = $cashTotal();
$paidSup = function () use ($pdo, $a, $SUP): int {
    return (int)$pdo->query("SELECT COALESCE(SUM(paid), 0) FROM biz_invoices WHERE user_id = {$a} AND party_id = {$SUP} AND status = 'issued' AND kind = 'purchase'")->fetchColumn();
};
$paid0 = $paidSup();
$CH = (int)BizPay::create($a, ['kind' => 'receipt', 'party_id' => $CUST, 'account_id' => $CASH, 'amount' => '1000', 'pay_date' => $OPEN,
                               'method' => 'cheque', 'cheque_due' => date('Y-m-d', strtotime('+20 days')), 'cheque_no' => '5050', 'cheque_bank' => 'ملت'])['id'];
$chqIn = (int)BizPay::get($a, $CH)['account_id'];
T::same($balCust - 1000, $bal($CUST), 'دریافتِ چک بدهیِ مشتری را کم کرد');
T::same(1000, $accBal($chqIn), 'چک در صندوقِ «چک‌های دریافتیِ در جریان»');
T::same($cashB, $cashTotal(), 'جمعِ نقد تکان نخورد (چک نقد نیست)');

$r = BizCheques::endorse($a, $CH, $CUST);
T::ok(!$r['ok'], 'به همان مشتری واگذار نمی‌شود', $r['message']);
$r = BizCheques::endorse($a, $CH, $SUP, date('Y-m-d', strtotime($OPEN . ' -1 day')));
T::ok(!$r['ok'], 'تاریخِ واگذاری پیش از دریافت رد می‌شود', $r['message']);
$r = BizCheques::endorse($a, $CH, $SUP);
T::ok($r['ok'], 'چک به فروشنده واگذار شد', $r['message']);
$e = $pdo->query("SELECT * FROM biz_payments WHERE user_id = {$a} AND cheque_settle_id = {$CH} AND kind = 'payment'")->fetch();
T::ok($e && $e['status'] === 'ok' && (int)$e['account_id'] === $chqIn && (int)$e['party_id'] === $SUP && (int)$e['amount'] === 1000 && $e['cheque_status'] === null,
    '⛔ یک پرداخت به فروشنده از همان صندوقِ چک، با پیوند به چک (و خودش چکِ دفتر نیست)');
T::same('endorsed', BizPay::get($a, $CH)['cheque_status'], 'چک «واگذارشده» شد');
T::same($balSup + 1000, $bal($SUP), '⛔ بدهیِ ما به فروشنده ۱۰۰۰ کم شد');
T::same($balCust - 1000, $bal($CUST), 'مشتری همچنان بستانکار است (چکش را داده)');
T::same(0, $accBal($chqIn), '⛔ چک از صندوقِ چک بیرون رفت');
T::same($cashB, $cashTotal(), '⛔ و هیچ پولِ نقدی جابه‌جا نشد');
T::same($paid0 + 1000, $paidSup(), 'فاکتورهای خریدِ فروشنده ۱۰۰۰ تسویه شدند (FIFO — اول قدیمی‌ترین)');
$lst = BizCheques::list($a, 'endorsed');
T::ok($lst['total'] === 1 && $lst['rows'][0]['endorse_party'] === 'فروشنده‌ی عمده', 'صافیِ «واگذارشده» با نامِ گیرنده');
T::ok(!array_filter(BizCheques::list($a, 'in')['rows'], fn($x) => (int)$x['id'] === $CH), 'در «دریافتیِ در جریان» نیست');
T::ok(!array_filter(BizCheques::due($a, 400), fn($x) => (int)$x['id'] === $CH), 'و در هشدارِ سررسیدِ داشبورد هم نیست');

/* ---------------------------------------------------------------- */
T::group('۷ — درهای پشتی بسته‌اند');
T::ok(!BizPay::void($a, $CH)['ok'], '⛔ دریافتِ چکِ واگذارشده باطل نمی‌شود');
T::ok(!BizPay::void($a, (int)$e['id'])['ok'], '⛔ پرداختِ واگذاری جدا باطل نمی‌شود');
T::ok(!BizCheques::bounce($a, $CH)['ok'] && str_contains(BizCheques::bounce($a, $CH)['message'], 'برگشت از واگذاری'), '⛔ برگشتی‌ِ چکِ واگذارشده — با راهنما');
T::ok(!BizCheques::clear($a, $CH, $BANK)['ok'], 'وصولِ چکِ واگذارشده رد می‌شود');
T::ok(!BizCheques::endorse($a, $CH, $SUP)['ok'], 'دوباره واگذار نمی‌شود');
$r = BizPay::create($a, ['kind' => 'payment', 'party_id' => $SUP, 'account_id' => $chqIn, 'amount' => '10', 'method' => 'cash']);
T::ok(!$r['ok'], '⛔ پرداختِ دستی از صندوقِ چک هنوز بسته است', $r['message']);
$r = BizPay::create($a, ['kind' => 'payment', 'party_id' => $SUP, 'account_id' => $chqIn, 'amount' => '10', 'method' => 'cheque', '_cheque_endorse' => 0, 'cheque_due' => $OPEN]);
T::ok(!$r['ok'] || (int)BizPay::get($a, (int)$r['id'])['account_id'] !== $chqIn, 'چکِ پرداختیِ معمولی به صندوقِ دریافتی نمی‌رود');
$OUT = (int)BizPay::create($a, ['kind' => 'payment', 'party_id' => $SUP, 'account_id' => $BANK, 'amount' => '70', 'method' => 'cheque', 'cheque_due' => $OPEN])['id'];
$r = BizCheques::endorse($a, $OUT, $CUST);
T::ok(!$r['ok'] && str_contains($r['message'], 'فقط چکِ دریافتی'), 'چکِ پرداختیِ خودمان واگذار نمی‌شود', $r['message']);

/* ---------------------------------------------------------------- */
T::group('۸ — برگشت از واگذاری، و بعد برگشتی روی مشتری');
$balSup7 = $bal($SUP); $paid7 = $paidSup();
$r = BizCheques::unendorse($a, $CH);
T::ok($r['ok'], 'فروشنده چک را پس داد', $r['message']);
T::same('void', $pdo->query("SELECT status FROM biz_payments WHERE id = " . (int)$e['id'])->fetchColumn(), 'پرداختِ واگذاری باطل شد');
T::same('pending', BizPay::get($a, $CH)['cheque_status'], 'چک دوباره در جریان');
T::same($balSup7 - 1000, $bal($SUP), '⛔ بدهیِ ما به فروشنده دقیقاً ۱۰۰۰ برگشت');
T::same(1000, $accBal($chqIn), 'چک دوباره در صندوقِ چک');
T::same($paid7 - 1000, $paidSup(), 'و تسویه‌ی فاکتورهای خرید هم ۱۰۰۰ عقب رفت');
T::ok(BizCheques::bounce($a, $CH)['ok'], 'حالا برگشتی روی مشتری ثبت می‌شود');
T::same($balCust, $bal($CUST), '⛔ بدهیِ مشتری به پیش از چک برگشت');
T::same(0, $accBal($chqIn), 'صندوقِ چک خالی');
T::same($cashB, $cashTotal(), 'در کلِ مسیر هیچ پولِ نقدی جابه‌جا نشد');
// دوباره‌واگذاری
$CH2 = (int)BizPay::create($a, ['kind' => 'receipt', 'party_id' => $CUST, 'account_id' => $CASH, 'amount' => '400', 'pay_date' => $OPEN,
                                'method' => 'cheque', 'cheque_due' => $OPEN, 'cheque_no' => '6060'])['id'];
T::ok(BizCheques::endorse($a, $CH2, $SUP)['ok'] && BizCheques::unendorse($a, $CH2)['ok'] && BizCheques::endorse($a, $CH2, $NEWP)['ok'],
    'واگذاری، برگشت و واگذاری به کسِ دیگر');
T::same(300 + 400, $bal($NEWP), 'پرداخت به گیرنده‌ی دوم روی حسابِ او نشست (۳۰۰ بدهیِ قبلی + ۴۰۰ چکی که به او دادیم)');

/* ---------------------------------------------------------------- */
T::group('۹ — قفل روی چکِ خرجی');
$CH3 = (int)BizPay::create($a, ['kind' => 'receipt', 'party_id' => $CUST, 'account_id' => $CASH, 'amount' => '90', 'pay_date' => $D1,
                                'method' => 'cheque', 'cheque_due' => $OPEN])['id'];
T::ok(BizCheques::endorse($a, $CH3, $SUP, $D1)['ok'], 'واگذاری پیش از قفل');
Biz::saveLock($a, $LOCK);
T::ok($locked(BizCheques::unendorse($a, $CH3)), '⛔ برگشت از واگذاریِ دوره‌ی بسته');
T::ok($locked(BizCheques::endorse($a, $CH2, $SUP, $D1)) || !BizCheques::endorse($a, $CH2, $SUP, $D1)['ok'], 'واگذاری با تاریخِ بسته رد می‌شود');
Biz::saveLock($a, '', true);

/* ---------------------------------------------------------------- */
T::group('۱۰ — بکاپ: پیوندِ واگذاری ← چک بعد از بازگرداندن');
$c = $make('c');
Biz::setType($c, 'business');
$parsed = parseBackupFile((string)json_encode(exportUserData($a), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$res = !empty($parsed['ok']) ? importUserData($c, $parsed['data']) : ['ok' => false, 'message' => 'فایل خوانده نشد'];
T::ok($res['ok'], 'بازگرداندنِ دفتر در حسابِ دیگر', $res['message'] ?? '');
$ec = BizCheques::list($c, 'endorsed');
T::ok($ec['total'] === BizCheques::list($a, 'endorsed')['total'] && $ec['total'] > 0 && ($ec['rows'][0]['endorse_party'] ?? null) !== null,
    '⛔ چکِ واگذارشده گیرنده‌اش را در حسابِ تازه پیدا می‌کند');
$bad = (int)$pdo->query("SELECT COUNT(*) FROM biz_payments s LEFT JOIN biz_payments y ON y.id = s.cheque_settle_id
                         WHERE s.user_id = {$c} AND s.cheque_settle_id IS NOT NULL AND (y.user_id IS NULL OR y.user_id <> {$c})")->fetchColumn();
T::same(0, $bad, 'هیچ پیوندی به چکِ حسابِ دیگر اشاره نمی‌کند');

/* ---------------------------------------------------------------- */
T::group('۱۱ — صفحه‌ها با HTTP');
$port = 0;
for ($pp = 9471; $pp <= 9520; $pp++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$pp", $e1, $e2);
    if ($sock) { fclose($sock); $port = $pp; break; }
}
$log = tempnam(sys_get_temp_dir(), 'block');
$srv = $port ? (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
    $port, escapeshellarg($root), escapeshellarg($log)))) : 0;
$up = false;
for ($i = 0; $srv && $i < 40; $i++) {
    usleep(150000);
    $sk = @fsockopen('127.0.0.1', $port, $x, $y, 0.3);
    if ($sk) { fclose($sk); $up = true; break; }
}
if (!$up) {
    T::blocked('بستنِ دوره (HTTP)', 'سرورِ آزمایشی بالا نیامد');
} else {
    $jar = tempnam(sys_get_temp_dir(), 'blockjar');
    $req = function (string $path, ?array $post = null) use ($port, $jar): array {
        $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 40]);
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        return [$code, $body];
    };
    $csrfOf = fn(string $html): string => preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m) ? $m[1] : '';
    [, $lp] = $req('store/login.php');
    T::same(302, $req('store/login.php', ['csrf_token' => $csrfOf($lp), 'username' => LPREFIX . 'a', 'password' => LPASS])[0], 'ورود');

    [$code, $st] = $req('store/settings.php');
    T::ok($code === 200 && str_contains($st, '</html>') && str_contains($st, 'id="lock"') && str_contains($st, 'name="lock_quick"'),
        'تنظیمات بخشِ «بستنِ دوره» را دارد');
    $req('store/settings.php', ['action' => 'lock', 'lock_date' => BizDocView::jDate($LOCK)]);
    Biz::forgetSettings($a);
    T::same(null, Biz::lockDate($a), '⛔ بی‌CSRF هیچ‌کار');
    [$code] = $req('store/settings.php', ['csrf_token' => $csrfOf($st), 'action' => 'lock', 'lock_date' => BizDocView::jDate($LOCK)]);
    Biz::forgetSettings($a);
    T::ok($code === 302 && Biz::lockDate($a) === $LOCK, 'قفل با فرم (تاریخِ شمسی)');
    [, $st] = $req('store/settings.php');
    T::ok(str_contains($st, 'name="confirm_unlock"') && str_contains($st, toJalali($LOCK)), 'قفلِ فعلی و گزینه‌ی تأییدِ باز کردن دیده می‌شوند');
    $req('store/settings.php', ['csrf_token' => $csrfOf($st), 'action' => 'lock', 'lock_date' => '']);
    Biz::forgetSettings($a);
    T::same($LOCK, Biz::lockDate($a), '⛔ باز کردن با فرم بی‌تیکِ تأیید رد شد');
    $req('store/settings.php', ['csrf_token' => $csrfOf($st), 'action' => 'lock', 'lock_date' => '', 'confirm_unlock' => '1']);
    Biz::forgetSettings($a);
    T::same(null, Biz::lockDate($a), 'با تیکِ تأیید باز شد');

    $CH4 = (int)BizPay::create($a, ['kind' => 'receipt', 'party_id' => $CUST, 'account_id' => $CASH, 'amount' => '250', 'pay_date' => $OPEN,
                                    'method' => 'cheque', 'cheque_due' => $OPEN, 'cheque_no' => '7070'])['id'];
    [$code, $cp] = $req('store/cheques.php');
    T::ok($code === 200 && str_contains($cp, 'value="endorse"') && str_contains($cp, 'خرج کردن (واگذاری)'), 'فرمِ واگذاری روی چکِ دریافتیِ در جریان');
    $req('store/cheques.php', ['action' => 'endorse', 'cheque_id' => (string)$CH4, 'party_id' => (string)$SUP]);
    T::same('pending', BizPay::get($a, $CH4)['cheque_status'], '⛔ بی‌CSRF واگذار نمی‌شود');
    [$code] = $req('store/cheques.php', ['csrf_token' => $csrfOf($cp), 'action' => 'endorse', 'cheque_id' => (string)$CH4,
                                          'party_id' => (string)$SUP, 'endorse_date' => BizDocView::jDate($OPEN)]);
    T::ok($code === 302 && BizPay::get($a, $CH4)['cheque_status'] === 'endorsed', 'واگذاری با فرم');
    [, $cp] = $req('store/cheques.php?f=endorsed');
    T::ok(str_contains($cp, 'برگشت از واگذاری') && str_contains($cp, 'فروشنده‌ی عمده'), 'زبانه‌ی «واگذارشده» با گیرنده و دکمه‌ی برگشت');
    [$code, $pr] = $req('store/print.php?doc=cheques&k=endorsed');
    T::ok($code === 200 && str_contains($pr, 'واگذارشده') && str_contains($pr, 'فروشنده‌ی عمده'), 'چاپِ دفترِ چک گیرنده را دارد');
    $ep = (int)$pdo->query("SELECT id FROM biz_payments WHERE user_id = {$a} AND cheque_settle_id = {$CH4} AND kind = 'payment' AND status = 'ok'")->fetchColumn();
    [$code, $pv] = $req('store/payment.php?id=' . $ep);
    T::ok($code === 200 && str_contains($pv, 'چکِ خرجی') && str_contains($pv, 'payment.php?id=' . $CH4), 'صفحه‌ی پرداختِ واگذاری به چک لینک دارد');
    [$code] = $req('store/cheques.php', ['csrf_token' => $csrfOf($cp), 'action' => 'unendorse', 'cheque_id' => (string)$CH4]);
    T::ok($code === 302 && BizPay::get($a, $CH4)['cheque_status'] === 'pending', 'برگشت از واگذاری با فرم');
    exec("kill $srv 2>/dev/null");
    @unlink($jar);
}
@unlink($log);

} catch (Throwable $e) {
    T::ok(false, 'استثنا', $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

$wipe();
exit(T::report());
