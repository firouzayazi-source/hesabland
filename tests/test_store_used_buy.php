<?php
/**
 * ⛔ رسیدِ خریدِ گوشیِ دست‌دوم (`BizUsedBuy`، `store/used-buy.php`، برگه‌ی `used_buy`).
 *
 *   ۱. کدِ ملی با رقمِ کنترلِ رسمی؛ رسید فقط روی خریدِ صادرشده‌ی IMEIدار.
 *   ۲. عکسِ لحظه: ویرایشِ بعدیِ طرف‌حساب رسید را عوض نمی‌کند؛ یک رسید برای هر فاکتور.
 *   ۳. جست‌وجو با IMEI، کدِ ملی، نام — فقط همان فروشگاه.
 *   ۴. HTTP: دکمه روی فاکتورِ خرید؛ فرم با ارقامِ فارسی؛ چاپ با اقرار و امضا؛ فروشگاهِ دیگر ۴۰۴؛ فروشنده بی‌«خرید» نه.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/lib/store_http.php';
T::group('خریدِ دست‌دوم');
if (!file_exists(__DIR__ . '/../config/config.php')) { T::blocked('دست‌دوم', 'config/config.php وجود ندارد'); exit(T::report()); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/biz.php';
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_docs.php';
require_once __DIR__ . '/../includes/biz_staff.php';
require_once __DIR__ . '/../includes/biz_used_buy.php';

try { $pdo = Database::getConnection(); } catch (Throwable $e) { T::blocked('دست‌دوم', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }
if (!BizUsedBuy::ready()) { T::blocked('دست‌دوم', 'migration_biz_used_buy اجرا نشده'); exit(T::report()); }
const UB_PREFIX = '__store_usedbuy_';
const UB_PASS   = 'Used#pass9';
$wipe = function () use ($pdo) {
    foreach ($pdo->query("SELECT id FROM users WHERE username LIKE '" . UB_PREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN) as $id) { deleteUserAccount((int)$id); }
};
$wipe();
register_shutdown_function($wipe);
$mk = function (string $n) use ($pdo): int {
    $r = createUserAccount($pdo, 'صاحب ' . $n, UB_PREFIX . $n, UB_PREFIX . $n . '@example.com', UB_PASS);
    Biz::setType((int)$r['id'], 'business');
    return (int)$r['id'];
};
$one = function (string $sql, array $p = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn(); };
// کدِ ملیِ معتبر از ۹ رقم
$nid = function (string $nine): string {
    $s = 0; for ($i = 0; $i < 9; $i++) { $s += (int)$nine[$i] * (10 - $i); }
    $r = $s % 11;
    return $nine . (string)($r < 2 ? $r : 11 - $r);
};
$imei = function (string $b14): string {
    $s = 0; for ($i = 0; $i < 14; $i++) { $d = (int)$b14[13 - $i]; if ($i % 2 === 0) { $d *= 2; if ($d > 9) { $d -= 9; } } $s += $d; }
    return $b14 . (string)((10 - $s % 10) % 10);
};

T::group('۱ — کدِ ملی');
$N1 = $nid('008123456');
T::ok(BizUsedBuy::nationalIdValid($N1), 'کدِ ملیِ معتبر');
T::ok(BizUsedBuy::nationalIdValid(toPersianDigits(substr($N1, 0, 3) . '-' . substr($N1, 3, 6) . '-' . substr($N1, 9))), 'با ارقامِ فارسی و خط‌تیره');
T::ok(!BizUsedBuy::nationalIdValid(substr($N1, 0, 9) . (string)(((int)$N1[9] + 1) % 10)), '⛔ رقمِ کنترلِ غلط رد');
T::ok(!BizUsedBuy::nationalIdValid('1111111111') && !BizUsedBuy::nationalIdValid('0000000000'), 'رقم‌های یکسان رد');
T::ok(!BizUsedBuy::nationalIdValid('12345678'), 'کوتاه رد');

T::group('۲ — رسید');
$u = $mk('a');
$o = $mk('o');
$acc = (int)BizCash::list($u)[0]['id'];
BizProducts::save($u, ['type' => 'phone', 'name' => 'iPhone 12 کارکرده', 'unit' => 'دستگاه', 'sell_price' => '20000000']);
BizProducts::save($u, ['name' => 'قاب', 'unit' => 'عدد', 'sell_price' => '100']);
$seller = (int)BizParties::save($u, ['name' => 'علی فروشنده', 'kind' => 'supplier', 'phone' => '09120000000'])['id'];
$I1 = $imei('35391810000101');
$buy = function (array $lines, bool $issue = true) use ($u, $acc, $seller): int {
    $d = BizInvoices::saveDraft($u, 'purchase', ['party_id' => $seller, 'lines' => $lines]);
    if ($issue) { BizInvoices::issue($u, (int)$d['id'], ['full' => true, 'account_id' => $acc]); }
    return (int)$d['id'];
};
$inv = $buy([['item' => 'iPhone 12 کارکرده', 'qty' => '1', 'price' => '15000000', 'imei1' => $I1, 'imei2' => '']]);
$draft = $buy([['item' => 'iPhone 12 کارکرده', 'qty' => '1', 'price' => '15000000', 'imei1' => $imei('35391810000102'), 'imei2' => '']], false);
$noImei = $buy([['item' => 'قاب', 'qty' => '1', 'price' => '100']]);
$in = ['seller_name' => 'علی فروشنده', 'national_id' => $N1, 'father_name' => 'حسن', 'phone' => '۰۹۱۲۰۰۰۰۰۰۰', 'condition_note' => 'خط و خش جزئی',
       'accessories' => ['box', 'charger', 'nope'], 'hamta_checked' => '1'];
T::ok(!BizUsedBuy::save($u, $draft, $in, $u)['ok'], '⛔ فاکتورِ پیش‌نویس رد');

T::ok(!BizUsedBuy::save($u, $noImei, $in, $u)['ok'], '⛔ خریدِ بی‌IMEI رد');
T::ok(!BizUsedBuy::save($u, $inv, ['national_id' => '1234567890'] + $in, $u)['ok'], '⛔ کدِ ملیِ نامعتبر رد');
T::ok(!BizUsedBuy::save($u, $inv, ['seller_name' => ''] + $in, $u)['ok'], 'بی‌نام رد');
T::ok(!BizUsedBuy::save($o, $inv, $in, $o)['ok'], '⛔ فاکتورِ فروشگاهِ دیگر رد');
// ⛔ فاکتورِ **فروشِ** صادرشده‌ی IMEIدار — رسیدِ خرید روی فروش معنا ندارد
$sale = BizInvoices::saveDraft($u, 'sale', ['party_id' => $seller, 'lines' => [['item' => 'iPhone 12 کارکرده', 'qty' => '1', 'price' => '20000000', 'imei1' => $I1, 'imei2' => '']]]);
$sIss = BizInvoices::issue($u, (int)$sale['id'], ['full' => true, 'account_id' => $acc]);
T::ok(($sIss['ok'] ?? false) === true, 'فروشِ همان گوشی صادر شد', json_encode($sIss, JSON_UNESCAPED_UNICODE));
T::ok(!BizUsedBuy::save($u, (int)$sale['id'], $in, $u)['ok'], '⛔ فاکتورِ فروش رد (رسیدِ خرید فقط روی خرید)');
T::ok(BizUsedBuy::save($u, $inv, $in, $u)['ok'], 'رسید ثبت شد');
$rec = BizUsedBuy::get($u, $inv);
T::same([$N1, '09120000000', 'box,charger', 1], [(string)$rec['national_id'], (string)$rec['phone'], (string)$rec['accessories'], (int)$rec['hamta_checked']],
    'کدِ ملی و تلفنِ لاتین، لوازمِ ناشناخته دور ریخته شد');
BizUsedBuy::save($u, $inv, ['condition_note' => 'سالم'] + $in, $u);
T::same([1, 'سالم'], [(int)$one('SELECT COUNT(*) FROM biz_used_buys WHERE invoice_id = :i', ['i' => $inv]), (string)BizUsedBuy::get($u, $inv)['condition_note']], 'ویرایش: همان یک رسید');
BizParties::save($u, ['name' => 'نامِ عوض‌شده', 'kind' => 'supplier'], $seller);
T::same('علی فروشنده', (string)BizUsedBuy::get($u, $inv)['seller_name'], '⛔ عکسِ لحظه: ویرایشِ طرف‌حساب رسید را عوض نکرد');

T::group('۳ — جست‌وجو');
T::same([$inv], array_map(fn($r) => (int)$r['invoice_id'], BizUsedBuy::find($u, toPersianDigits($I1))), 'با IMEI (ارقامِ فارسی)');
T::same([$inv], array_map(fn($r) => (int)$r['invoice_id'], BizUsedBuy::find($u, $N1)), 'با کدِ ملی');
T::same(1, count(BizUsedBuy::find($u, 'علی')), 'با نام');
T::same([], BizUsedBuy::find($o, $I1), '⛔ فروشگاهِ دیگر نمی‌بیند');

T::group('۴ — HTTP');
$port = stHttpServer();
T::ok($port > 0, 'سرورِ آزمایشی بالا آمد');
if (!$port) { exit(T::report()); }
$c = stHttpClient($port);
stHttpLogin($c, UB_PREFIX . 'a', UB_PASS);
[, $h] = $c('store/invoice.php?id=' . $inv);
T::ok(str_contains($h, 'used-buy.php?inv=' . $inv), 'فاکتورِ خریدِ IMEIدار: دکمه‌ی رسید');
[, $h] = $c('store/invoice.php?id=' . $noImei);
T::ok(!str_contains($h, 'used-buy.php?inv='), 'خریدِ بی‌گوشی: دکمه نیست');
$inv2 = $buy([['item' => 'iPhone 12 کارکرده', 'qty' => '1', 'price' => '14000000', 'imei1' => $imei('35391810000103'), 'imei2' => '']]);
[$code, $h] = $c('store/used-buy.php?inv=' . $inv2);
T::ok($code === 200 && str_contains($h, 'name="national_id"') && str_contains($h, 'نامِ عوض‌شده'), 'فرم با نامِ طرف‌حساب پرشده');
$N2 = $nid('112233445');
$c('store/used-buy.php', ['csrf_token' => stCsrf($h), 'inv' => (string)$inv2, 'seller_name' => 'زهرا', 'national_id' => toPersianDigits($N2), 'accessories' => ['box']]);
T::same($N2, (string)$one('SELECT national_id FROM biz_used_buys WHERE invoice_id = :i', ['i' => $inv2]), 'ثبت از فرم با ارقامِ فارسی');
[$code, $h] = $c("store/print.php?doc=used_buy&id=" . $inv);
T::ok($code === 200 && str_contains($h, toPersianDigits($N1)) && str_contains($h, toPersianDigits($I1)) && str_contains($h, 'اقرار می‌کنم') && str_contains($h, 'اثرِ انگشتِ فروشنده'),
    'برگه‌ی چاپ: کدِ ملی، IMEI، اقرار و جای امضا');
[$code, $h] = $c('store/used-buy.php?q=' . urlencode($I1));
T::ok(str_contains($h, 'علی فروشنده'), 'جست‌وجو از صفحه با IMEI');
$x = stHttpClient($port);
stHttpLogin($x, UB_PREFIX . 'o', UB_PASS);
[$code] = $x('store/print.php?doc=used_buy&id=' . $inv);
T::same(404, $code, '⛔ فروشگاهِ دیگر برگه را نمی‌بیند');
[$code] = $x('store/used-buy.php?inv=' . $inv);
T::same(404, $code, '⛔ و فرم را هم نه');
BizStaff::add($u, ['full_name' => 'فروشنده', 'username' => UB_PREFIX . 'sel', 'password' => UB_PASS, 'role' => 'seller']);
$s = stHttpClient($port);
stHttpLogin($s, UB_PREFIX . 'sel', UB_PASS);
[$code, $h] = $s('store/used-buy.php?inv=' . $inv);
T::ok($code !== 200 || !str_contains($h, 'name="national_id"'), '⛔ فروشنده (بی‌«خرید») رسید نمی‌سازد');

exit(T::report());
