<?php
/**
 * ⛔ فروشگاهِ موبایل: گوشی با IMEI، «+»ِ کالای تازه در فاکتور، و فاکتورِ بی‌سررسید.
 *
 * چهار خطرِ اصلی:
 *   ۱. **یک گوشی دو بار وارد یا دو بار فروخته شود** — وضعیتِ هر IMEI از
 *      اسنادِ صادرشده است (`BizSerial::states`) و صدور باید رد کند.
 *   ۲. **ابطال اثرِ IMEI را جا بگذارد** — سندِ باطل خودبه‌خود بیرون است؛
 *      پس از ابطالِ فروش همان گوشی دوباره فروختنی است.
 *   ۳. **نشت بین فروشگاه‌ها** — IMEIِ فروشگاهِ A برای B نه پیدا می‌شود و نه
 *      جلوی خریدِ همان شماره را می‌گیرد.
 *   ۴. **«+» فاکتورِ نیمه‌کاره را از دست بدهد** یا کالای بی‌IMEI بسازد.
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
require_once __DIR__ . '/../includes/biz_dash.php';

$root = dirname(__DIR__);
const MPREFIX = '__bimei_';
const MPASS   = 'Imei12345';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::blocked('گوشی با IMEI', 'اتصال به دیتابیس برقرار نشد: ' . $e->getMessage());
    exit(T::report());
}
if (!Biz::available() || !tableHasColumn('biz_invoice_lines', 'imei1') || !tableHasColumn('biz_products', 'has_serial')) {
    T::blocked('گوشی با IMEI', 'ستون‌های IMEI نیست — اول: bash deploy/migrate.sh --apply');
    exit(T::report());
}

$wipe = function () use ($pdo) {
    $ids = $pdo->query("SELECT id FROM users WHERE username LIKE '" . MPREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
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
    $pdo->exec("DELETE FROM users WHERE username LIKE '" . MPREFIX . "%'");
    $pdo->exec("DELETE FROM login_attempts WHERE username_tried LIKE '" . MPREFIX . "%'");
};
$wipe();

$make = function (string $name) use ($pdo): int {
    $res = createUserAccount($pdo, 'کاربرِ ' . $name, MPREFIX . $name, MPREFIX . $name . '@example.com', MPASS);
    if (!($res['ok'] ?? false)) { throw new RuntimeException('ساختِ کاربر: ' . ($res['error'] ?? '?')); }
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => MPREFIX . $name]);
    return (int)$st->fetchColumn();
};
$a = $make('a');
$b = $make('b');
Biz::setType($a, 'business');
Biz::setType($b, 'business');

$stock = fn(int $u, int $pid): float => (float)BizProducts::get($u, $pid)['stock_qty'];
$inStock = fn(int $u, int $pid = 0): array => array_column(BizSerial::inStock($u, $pid)['rows'], 'imei1');
$L = fn(string $item, string $qty, string $price, string $i1 = '', string $i2 = ''): array
    => ['item' => $item, 'qty' => $qty, 'price' => $price, 'imei1' => $i1, 'imei2' => $i2];

const IM1 = '356938035643809';
const IM1B = '356938035643817';
const IM2 = '490154203237518';
const IM3 = '353918057426721';

// =================================================================
T::group('۰ — نوعِ کالا: گوشی، لوازم جانبی، خدمت');
$r = BizProducts::save($a, ['type' => 'phone', 'name' => 'آیفون ۱۵ — ۱۲۸', 'unit' => 'دستگاه', 'buy_price' => '400', 'sell_price' => '500']);
T::ok($r['ok'], 'گوشی تعریف شد', $r['message']);
$PH = (int)$r['id'];
$r = BizProducts::save($a, ['type' => 'goods', 'name' => 'قاب آزمون', 'unit' => 'عدد', 'buy_price' => '10', 'sell_price' => '20', 'opening_qty' => '5']);
$AC = (int)$r['id'];
$r = BizProducts::save($a, ['type' => 'service', 'name' => 'نصبِ گلس', 'unit' => 'عدد', 'sell_price' => '5']);
$SV = (int)$r['id'];
T::same('phone', BizProducts::typeOf(BizProducts::get($a, $PH)), 'typeOf: گوشی');
T::same('goods', BizProducts::typeOf(BizProducts::get($a, $AC)), 'typeOf: کالا');
T::same('service', BizProducts::typeOf(BizProducts::get($a, $SV)), 'typeOf: خدمت');
$r = BizProducts::save($a, ['type' => 'phone', 'name' => 'گوشیِ کیلویی', 'unit' => 'کیلوگرم']);
T::ok(!$r['ok'] && str_contains($r['message'], 'دستگاه'), '⛔ گوشی با واحدِ اعشاری ثبت نمی‌شود');
$r = BizProducts::save($a, ['type' => 'bogus', 'name' => 'x']);
T::ok(!$r['ok'], 'نوعِ ناشناخته رد می‌شود');
// مسیرِ قدیمی (ورود از فایل: فقط is_service) گوشی بودن را پاک نمی‌کند
$cur = BizProducts::get($a, $PH);
$r = BizProducts::save($a, ['name' => $cur['name'], 'unit' => 'دستگاه', 'buy_price' => '400', 'sell_price' => '520'], $PH);
T::ok($r['ok'] && (int)BizProducts::get($a, $PH)['has_serial'] === 1, '⛔ ویرایش بی‌`type` گوشی را «کالا» نمی‌کند');

// =================================================================
T::group('۱ — ردیفِ IMEIدار: قاعده‌ها در parseLines');
$e = fn(array $rows): string => implode(' ', BizInvoices::parseLines($a, $rows)['errors']);
T::ok(str_contains($e([$L('آیفون ۱۵ — ۱۲۸', '1', '400')]), 'IMEI'), '⛔ گوشی بی‌IMEI پذیرفته نمی‌شود');
T::ok(str_contains($e([$L('آیفون ۱۵ — ۱۲۸', '2', '400', IM1)]), 'یک ردیف'), '⛔ ردیفِ IMEIدار فقط یک دستگاه');
T::ok(str_contains($e([$L('قاب آزمون', '1', '20', IM1)]), 'گوشی نیست'), '⛔ IMEI روی لوازم جانبی نمی‌نشیند');
T::ok(str_contains($e([$L('شرحِ آزاد', '1', '20', IM1)]), '+'), '⛔ IMEI روی شرحِ آزاد نمی‌نشیند (راهِ «+» را می‌گوید)');
T::ok(str_contains($e([$L('آیفون ۱۵ — ۱۲۸', '1', '400', '12345')]), 'معتبر نیست'), '⛔ IMEIِ کوتاه رد می‌شود');
T::ok(str_contains($e([$L('آیفون ۱۵ — ۱۲۸', '1', '400', IM1), $L('آیفون ۱۵ — ۱۲۸', '1', '400', IM2, IM1)]), 'تکراری'),
    '⛔ یک IMEI در یک سند دو بار نمی‌آید (حتی در ستونِ دوم)');
$p = BizInvoices::parseLines($a, [$L('آیفون ۱۵ — ۱۲۸', '', '400', '', ' ۴۹۰۱۵۴ ۲۰۳۲۳۷۵۱۸ ')]);
T::same(IM2, $p['lines'][0]['imei1'] ?? null, 'IMEIِ تنهای ستونِ دوم به اولی می‌رود؛ ارقامِ فارسی و فاصله پاک می‌شوند');
T::same(1.0, (float)($p['lines'][0]['qty'] ?? 0), 'مقدارِ خالی = ۱');

// =================================================================
T::group('۲ — خرید با IMEI: موجودی و فهرستِ در انبار');
$r = BizInvoices::saveDraft($a, 'purchase', ['lines' => [
    $L('آیفون ۱۵ — ۱۲۸', '1', '400', IM1, IM1B), $L('آیفون ۱۵ — ۱۲۸', '1', '400', IM2), $L('قاب آزمون', '3', '10'),
]]);
T::ok($r['ok'], 'پیش‌نویسِ خرید با دو گوشی و لوازم', $r['message']);
$pu = (int)$r['id'];
T::same([], $inStock($a), '⛔ پیش‌نویس هیچ گوشی‌ای را «در انبار» نمی‌کند');
$r = BizInvoices::issue($a, $pu, ['full' => true, 'account_id' => (int)BizCash::list($a)[0]['id']]);
T::ok($r['ok'], 'خرید صادر شد', $r['message']);
T::same(2.0, $stock($a, $PH), 'موجودیِ گوشی ۲');
T::same([IM1, IM2], $inStock($a, $PH), 'هر دو گوشی در انبار');
$u = BizSerial::lookup($a, IM1B);
T::ok($u !== null && $u['product_id'] === $PH && $u['imei1'] === IM1, 'جست‌وجو با IMEI ۲ هم همان گوشی را پیدا می‌کند');

$r = BizInvoices::saveDraft($a, 'purchase', ['lines' => [$L('آیفون ۱۵ — ۱۲۸', '1', '400', IM1B)]]);
$dup = (int)$r['id'];
$r = BizInvoices::issue($a, $dup, ['full' => true, 'account_id' => (int)BizCash::list($a)[0]['id']]);
T::ok(!$r['ok'] && str_contains($r['message'], 'در انبار است'), '⛔ گوشیِ در انبار دو بار خریده نمی‌شود (IMEI ۲ هم)', $r['message']);
T::same(2.0, $stock($a, $PH), '⛔ و موجودی هم عوض نشد (همه در یک تراکنش برگشت)');
T::same('draft', BizInvoices::get($a, $dup)['status'], 'سند پیش‌نویس ماند');
BizInvoices::deleteDraft($a, $dup);

// =================================================================
T::group('۳ — فروش با تایپِ IMEI در خانه‌ی کالا (جست‌وجوی هوشمند)');
$typed = toPersianDigits(substr(IM2, 0, 6)) . ' ' . toPersianDigits(substr(IM2, 6));
$p = BizInvoices::parseLines($a, [$L($typed, '', '520')]);
T::ok(!$p['errors'] && ($p['lines'][0]['product_id'] ?? 0) === $PH && ($p['lines'][0]['imei1'] ?? '') === IM2,
    '⛔ IMEI در خانه‌ی کالا → همان گوشی با IMEIِ پرشده', implode(' ', $p['errors']));
T::same($PH, $p['meta'][0]['product_id'] ?? null, 'meta کالای پیدا‌شده را برای صفحه می‌گوید');
T::ok(str_contains(implode(' ', BizInvoices::parseLines($a, [$L('359999000011112', '1', '10')])['errors']), 'پیدا نشد'),
    '⛔ IMEIِ ناشناخته «شرحِ آزاد» نمی‌شود؛ می‌گوید پیدا نشد');
$r = BizInvoices::saveDraft($a, 'sale', ['lines' => [$L($typed, '', '520')]]);
$s1 = (int)$r['id'];
$r = BizInvoices::issue($a, $s1, ['full' => true, 'account_id' => (int)BizCash::list($a)[0]['id']]);
T::ok($r['ok'], 'فروشِ گوشی با IMEI صادر شد', $r['message']);
T::same([IM1], $inStock($a, $PH), 'گوشیِ فروخته‌شده از فهرستِ در انبار رفت');
T::same(1.0, $stock($a, $PH), 'موجودی ۱');

$r = BizInvoices::saveDraft($a, 'sale', ['lines' => [$L('آیفون ۱۵ — ۱۲۸', '1', '520', IM2)]]);
$s2 = (int)$r['id'];
$r = BizInvoices::issue($a, $s2, ['full' => true, 'account_id' => (int)BizCash::list($a)[0]['id']]);
T::ok(!$r['ok'] && str_contains($r['message'], 'در انبار نیست'), '⛔ گوشیِ فروخته‌شده دوباره فروخته نمی‌شود', $r['message']);
T::same(1.0, $stock($a, $PH), '⛔ موجودی دست نخورد');
BizInvoices::deleteDraft($a, $s2);

// کالای دیگر با IMEIِ گوشیِ این مدل
$r = BizProducts::save($a, ['type' => 'phone', 'name' => 'سامسونگ آزمون', 'unit' => 'دستگاه', 'sell_price' => '300', 'opening_qty' => '2', 'opening_cost' => '200']);
$PH2 = (int)$r['id'];
$r = BizInvoices::saveDraft($a, 'sale', ['lines' => [$L('سامسونگ آزمون', '1', '300', IM1)]]);
$s3 = (int)$r['id'];
$r = BizInvoices::issue($a, $s3, ['full' => true, 'account_id' => (int)BizCash::list($a)[0]['id']]);
T::ok(!$r['ok'] && str_contains($r['message'], 'کالای دیگری'), '⛔ IMEIِ یک مدل روی مدلِ دیگر فروخته نمی‌شود', $r['message']);
BizInvoices::deleteDraft($a, $s3);
// گوشیِ پیش از IMEI (موجودیِ اول دوره): IMEIِ دیده‌نشده در فروش پذیرفته است
$r = BizInvoices::saveDraft($a, 'sale', ['lines' => [$L('سامسونگ آزمون', '1', '300', IM3)]]);
$s4 = (int)$r['id'];
$r = BizInvoices::issue($a, $s4, ['full' => true, 'account_id' => (int)BizCash::list($a)[0]['id']]);
T::ok($r['ok'], 'گوشیِ موجودیِ اول دوره با IMEIِ تازه فروخته می‌شود', $r['message']);

// =================================================================
T::group('۴ — ابطال و برگشت: وضعیت از اسنادِ صادرشده است');
$r = BizInvoices::void($a, $s1);
T::ok($r['ok'], 'فروشِ گوشی باطل شد', $r['message']);
T::same([IM1, IM2], $inStock($a, $PH), '⛔ پس از ابطال همان گوشی دوباره «در انبار» است (بی‌هیچ کدِ برگرداندن)');
$r = BizInvoices::saveDraft($a, 'sale', ['lines' => [$L('آیفون ۱۵ — ۱۲۸', '1', '520', IM2)]]);
$s5 = (int)$r['id'];
$r = BizInvoices::issue($a, $s5, ['full' => true, 'account_id' => (int)BizCash::list($a)[0]['id']]);
T::ok($r['ok'], 'و دوباره فروختنی است', $r['message']);
$orig = BizInvoices::get($a, $s5);
$r = BizInvoices::createReturn($a, $s5, [(int)$orig['lines'][0]['id'] => '1'], ['full' => true, 'account_id' => (int)BizCash::list($a)[0]['id']]);
T::ok($r['ok'], 'برگشت از فروشِ گوشی', $r['message']);
$ret = BizInvoices::get($a, (int)$r['id']);
T::same(IM2, $ret['lines'][0]['imei1'] ?? null, '⛔ ردیفِ برگشت IMEIِ همان گوشی را دارد');
T::same([IM1, IM2], $inStock($a, $PH), 'گوشیِ برگشتی دوباره در انبار');
$r = BizInvoices::saveDraft($a, 'purchase', ['lines' => [$L('آیفون ۱۵ — ۱۲۸', '1', '400', IM1)]]);
$pr2 = (int)$r['id'];
$r = BizInvoices::issue($a, $pr2, ['full' => true, 'account_id' => (int)BizCash::list($a)[0]['id']]);
T::ok(!$r['ok'], '⛔ گوشیِ در انبار با خریدِ دوم هم وارد نمی‌شود');
BizInvoices::deleteDraft($a, $pr2);
$po = BizInvoices::get($a, $pu);
$line = array_values(array_filter($po['lines'], fn($l) => $l['imei1'] === IM1))[0];
$r = BizInvoices::createReturn($a, $pu, [(int)$line['id'] => '1'], ['full' => true, 'account_id' => (int)BizCash::list($a)[0]['id']]);
T::ok($r['ok'], 'برگشت از خریدِ یک گوشی', $r['message']);
T::same([IM2], $inStock($a, $PH), 'گوشیِ پس‌داده‌شده از انبار رفت');

// =================================================================
T::group('۵ — جست‌وجو با IMEI در فهرست‌ها');
$ids = array_map('intval', array_column(BizInvoices::list($a, ['sale'], IM2)['rows'], 'id'));
T::ok(in_array($s5, $ids, true) && in_array($s1, $ids, true), 'فهرستِ فروش با IMEI هر دو فاکتورِ آن گوشی را می‌آورد');
T::ok(!in_array($s4, $ids, true), 'و فاکتورِ گوشیِ دیگر را نه');
$ids = array_map('intval', array_column(BizInvoices::list($a, ['purchase'], toPersianDigits(IM1B))['rows'], 'id'));
T::same([$pu], $ids, 'فهرستِ خرید با IMEI ۲ (ارقامِ فارسی)');
$ids = array_map('intval', array_column(BizProducts::list($a, IM2)['rows'], 'id'));
T::same([$PH], $ids, 'فهرستِ کالا با IMEI مدلِ همان گوشی را می‌آورد');

// =================================================================
T::group('۶ — جداسازیِ دو فروشگاه');
T::same(null, BizSerial::lookup($b, IM2), '⛔ IMEIِ فروشگاهِ A برای B پیدا نمی‌شود');
T::same([], array_column(BizInvoices::list($b, ['sale'], IM2)['rows'], 'id'), '⛔ و در فهرستِ B هم نیست');
$r = BizProducts::save($b, ['type' => 'phone', 'name' => 'گوشیِ B', 'unit' => 'دستگاه', 'buy_price' => '1']);
$PB = (int)$r['id'];
$r = BizInvoices::saveDraft($b, 'purchase', ['lines' => [$L('گوشیِ B', '1', '1', IM2)]]);
$r = BizInvoices::issue($b, (int)$r['id'], ['full' => true, 'account_id' => (int)BizCash::list($b)[0]['id']]);
T::ok($r['ok'], '⛔ IMEIِ در انبارِ A جلوی خریدِ همان شماره در B را نمی‌گیرد', $r['message']);
T::same([IM2], $inStock($a, $PH), 'و فهرستِ A دست نخورد');
$p = BizInvoices::parseLines($b, [['item' => 'آیفون ۱۵ — ۱۲۸', 'product_id' => (string)$PH, 'qty' => '1', 'price' => '1']]);
T::ok(($p['lines'][0]['product_id'] ?? null) === null, '⛔ شناسه‌ی کالای A روی ردیفِ B نمی‌نشیند');

// =================================================================
T::group('۷ — فاکتور سررسید ندارد');
T::ok(!isset(BizInvoices::FILTERS['overdue']), 'صافیِ «سررسید گذشته» نیست');
$pdo->prepare("UPDATE biz_invoices SET due_date = '2020-01-01', paid = 0 WHERE id = :i AND user_id = :u")->execute(['i' => $s4, 'u' => $a]);
T::ok(BizInvoices::state(BizInvoices::get($a, $s4)) !== 'overdue', '⛔ حتی سندِ قدیمیِ سررسیددار «سررسید گذشته» نمی‌شود');
// همان عددی که داشبورد می‌خواند (`BizDash::invoiceFlags()`): «معوق» از سنِ سند است، نه سررسید
$fl = BizDash::invoiceFlags($a);
T::ok(!array_key_exists('overdue_sale', $fl) && $fl['late'] === 0, 'داشبورد سررسید نمی‌شمارد', json_encode($fl));

// =================================================================
T::group('۸ — صفحه‌ها با HTTP: «+»، IMEI، بی‌سررسید');
$port = 0;
for ($pp = 9311; $pp <= 9360; $pp++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$pp", $e1, $e2);
    if ($sock) { fclose($sock); $port = $pp; break; }
}
$log = tempnam(sys_get_temp_dir(), 'bimei');
$srv = $port ? (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
    $port, escapeshellarg($root), escapeshellarg($log)))) : 0;
$up = false;
for ($i = 0; $srv && $i < 40; $i++) {
    usleep(150000);
    $sk = @fsockopen('127.0.0.1', $port, $x, $y, 0.3);
    if ($sk) { fclose($sk); $up = true; break; }
}
if (!$up) {
    T::blocked('صفحه‌های IMEI (HTTP)', 'سرورِ آزمایشی بالا نیامد');
    if ($srv) { exec("kill $srv 2>/dev/null"); }
    $wipe();
    exit(T::report());
}
$jar = tempnam(sys_get_temp_dir(), 'bimeijar');
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
    curl_close($ch);
    $loc = preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $hlen), $m) ? $m[1] : '';
    return [$code, substr($raw, $hlen), $loc];
};
$csrfOf = fn(string $html): string => preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m) ? $m[1] : '';
$drafts = fn(): int => (int)$pdo->query("SELECT COUNT(*) FROM biz_invoices WHERE user_id = {$a} AND status = 'draft'")->fetchColumn();

[, $lp] = $req('store/login.php');
[$c, , $loc] = $req('store/login.php', ['csrf_token' => $csrfOf($lp), 'username' => MPREFIX . 'a', 'password' => MPASS]);
T::ok($c === 302, 'ورود به فروشگاه', "{$c} {$loc}");

[$c, $ed] = $req('store/invoice-edit.php?k=purchase');
T::ok($c === 200 && !str_contains($ed, 'name="due_date"'), '⛔ ویرایشگرِ فاکتور فیلدِ سررسید ندارد');
T::ok(substr_count($ed, 'data-np-open') >= 4, 'هر ردیف «+» دارد');
T::ok(str_contains($ed, 'data-np hidden'), 'پنلِ «کالای تازه» بسته رندر می‌شود');
$tok = $csrfOf($ed);
$base = ['csrf_token' => $tok, 'party_id' => '0', 'inv_date' => '', 'discount' => '', 'extra' => '', 'note' => 'یادداشتِ نیمه‌کاره',
         'pay_mode' => 'full', 'account_id' => (string)BizCash::list($a)[0]['id'], 'method' => 'cash'];

// بی‌جاوااسکریپت: «+» روی ردیفِ دوم (خالی، بعد از یک ردیفِ پر)
[$c, $body] = $req('store/invoice-edit.php?k=purchase', $base + ['np_open' => '1',
    'lines' => [$L('قاب آزمون', '2', '10'), $L('359999000022224', '', '')]]);
T::ok($c === 200 && preg_match('/data-np(?! hidden)[\s>]/', $body) === 1, '«+» بی‌جاوااسکریپت پنل را باز برمی‌گرداند');
T::ok(str_contains($body, 'value="phone" checked') && str_contains($body, 'value="359999000022224"'),
    '⛔ متنِ IMEIِ ردیف نوع را «گوشی» و IMEI را پیشنهاد می‌کند');
T::ok(str_contains($body, 'یادداشتِ نیمه‌کاره') && str_contains($body, 'value="قاب آزمون"'), '⛔ هیچ چیزی از فاکتورِ نیمه‌کاره گم نشد');
T::same(0, $drafts(), 'و پیش‌نویسی هم ساخته نشد');

$npBase = ['row' => '1', 'type' => 'phone', 'name' => 'شیائومی آزمون', 'sku' => '', 'buy' => '150', 'sell' => '190'];
[$c, $body] = $req('store/invoice-edit.php?k=purchase', $base + ['action' => 'np_save',
    'np' => $npBase + ['imei1' => '123', 'imei2' => ''], 'lines' => [$L('قاب آزمون', '2', '10'), $L('359999000022224', '', '')]]);
T::ok(str_contains($body, 'معتبر نیست'), 'IMEIِ نامعتبرِ پنل رد می‌شود');
T::same(0, (int)$pdo->query("SELECT COUNT(*) FROM biz_products WHERE user_id = {$a} AND name = 'شیائومی آزمون'")->fetchColumn(),
    '⛔ و کالا هم ساخته نشد (IMEI پیش از ساختِ کالا سنجیده می‌شود)');

[$c, $body] = $req('store/invoice-edit.php?k=purchase', $base + ['action' => 'np_save',
    'np' => $npBase + ['imei1' => '359999000022224', 'imei2' => '359999000022232'],
    'lines' => [$L('قاب آزمون', '2', '10'), $L('359999000022224', '', '')]]);
$np = $pdo->query("SELECT * FROM biz_products WHERE user_id = {$a} AND name = 'شیائومی آزمون'")->fetch();
T::ok($np && (int)$np['has_serial'] === 1 && $np['unit'] === 'دستگاه' && (int)$np['buy_price'] === 150,
    '«ثبتِ کالا» گوشیِ تازه را با قیمت ساخت');
T::ok(str_contains($body, 'در ردیفِ ۲ نشست') && str_contains($body, 'value="شیائومی آزمون"')
    && str_contains($body, 'value="359999000022232"') && str_contains($body, 'data-np hidden'),
    '⛔ و همان ردیف را با نام، IMEI ۱ و ۲ پر کرد و پنل بسته شد');
T::ok(str_contains($body, 'name="lines[1][item]" value="شیائومی آزمون"') && !str_contains($body, 'value="359999000022224" list=')
    && str_contains($body, 'name="lines[0][item]" value="قاب آزمون"'),
    '⛔ ردیفِ «+» جایگزین شد، نه ردیفِ تازه‌ای کنارش (و ردیفِ قبلی دست نخورد)');
T::same(0, $drafts(), 'هنوز پیش‌نویسی نیست — فقط کالا ساخته شد');

// ذخیره و صدور از فرم با همان ردیف‌ها
[$c, , $loc] = $req('store/invoice-edit.php?k=purchase', $base + ['action' => 'issue',
    'lines' => [$L('قاب آزمون', '2', '10'), $L('شیائومی آزمون', '1', '150', '359999000022224', '359999000022232')]]);
T::ok($c === 302 && str_contains($loc, 'invoice.php?id='), 'خریدِ گوشیِ تازه از فرم صادر شد', "{$c} {$loc}");
T::same(['359999000022224'], $inStock($a, (int)$np['id']), 'گوشیِ تازه در انبار');

// بی‌جاوااسکریپت: IMEI در خانه‌ی کالا → «ردیفِ بیشتر» نام را برمی‌گرداند
[, $ed] = $req('store/invoice-edit.php?k=sale');
[$c, $body] = $req('store/invoice-edit.php?k=sale', ['csrf_token' => $csrfOf($ed), 'action' => 'addrows', 'party_id' => '0',
    'lines' => [$L('359999000022224', '', '')]] + $base);
T::ok(str_contains($body, 'value="شیائومی آزمون"') && preg_match('/data-imei-box>/', $body) === 1,
    '⛔ IMEIِ تایپ‌شده بی‌جاوااسکریپت هم به نامِ گوشی و خانه‌ی IMEIِ باز تبدیل می‌شود');
T::ok(str_contains($ed, 'data-imei1="' . IM2 . '"'), 'فهرستِ جست‌وجوی فاکتورِ فروش گوشی‌های در انبار را دارد');

$sp = BizInvoices::list($a, ['sale'], IM2)['rows'][0]['id'] ?? 0;
$pages = ['store/invoice.php?id=' . $sp, 'store/print.php?doc=invoice&id=' . $sp, 'store/product.php?id=' . $PH,
          'store/product.php?id=' . $AC, 'store/product.php', 'store/products.php?q=' . IM2, 'store/sales.php?q=' . IM2,
          'store/quick-sale.php', 'store/index.php', 'store/return.php?inv=' . $sp];
$bad = [];
foreach ($pages as $pg) {
    [$c, $body] = $req($pg);
    if ($c !== 200 || !str_contains($body, '</html>') || preg_match('/(Fatal error|Warning:|Notice:|Deprecated:)/', $body)) { $bad[] = "{$pg} ({$c})"; }
}
T::ok(!$bad, 'هر ' . count($pages) . ' صفحه کامل و بی‌هشدار رندر شد', implode('، ', $bad));
[, $body] = $req('store/invoice.php?id=' . $sp);
T::ok(str_contains($body, 'IMEI ' . IM2), 'صفحه‌ی سند IMEI را زیرِ شرح نشان می‌دهد');
[, $body] = $req('store/print.php?doc=invoice&id=' . $sp);
T::ok(str_contains($body, 'IMEI ' . IM2), 'و برگه‌ی چاپ هم');
[, $body] = $req('store/product.php?id=' . $PH);
T::ok(str_contains($body, IM2) && str_contains($body, 'گوشی‌های در انبار'), 'صفحه‌ی کالا گوشی‌های در انبار را با IMEI فهرست می‌کند');
[, $body] = $req('store/sales.php?q=' . IM2);
T::ok(str_contains($body, 'invoice.php?id=' . $sp), 'جست‌وجوی IMEI در فهرستِ فروش');

// =================================================================
T::group('۹ — رفتارِ فاکتور در کرومیوم: IMEI، «+» و پنل');
$node = trim((string)shell_exec('command -v node 2>/dev/null'));
if ($node === '') {
    T::skip('رفتارِ فاکتور در کرومیوم', 'node نیست');
} else {
    $out = (string)shell_exec(sprintf('%s %s %s %s %s %s %s %s 2>/dev/null', escapeshellarg($node),
        escapeshellarg(__DIR__ . '/imei_probe.js'), escapeshellarg("http://127.0.0.1:{$port}/"),
        escapeshellarg(MPREFIX . 'a'), escapeshellarg(MPASS), escapeshellarg(IM2),
        escapeshellarg('آیفون ۱۵ — ۱۲۸'), escapeshellarg('قاب آزمون')));
    $pr = json_decode(trim($out), true);
    if (!is_array($pr) || empty($pr['ok'])) {
        T::skip('رفتارِ فاکتور در کرومیوم', 'کرومیوم در دسترس نیست: ' . ($pr['why'] ?? trim($out)));
    } else {
        foreach ($pr['res'] as $w => $o) {
            T::ok(!$o['due'], "{$w}: فیلدِ سررسید نیست");
            T::ok($o['item0'] === 'آیفون ۱۵ — ۱۲۸' && $o['imei0'] === IM2 && $o['qty0'] === '۱' && $o['price0'] === '۵۲۰' /* num-input.js */
                && $o['pid0'] === (string)$PH && $o['box0'],
                "{$w}: ⛔ IMEIِ تایپ‌شده (فارسی با فاصله) ردیف را با گوشی، IMEI، مقدار و قیمت پر کرد",
                json_encode([$o['item0'], $o['imei0'], $o['qty0'], $o['price0'], $o['pid0'], $o['box0']], JSON_UNESCAPED_UNICODE));
            T::ok($o['box1'] && !$o['box2'], "{$w}: مدلِ گوشی خانه‌ی IMEI را باز می‌کند، لوازم جانبی نه");
            T::ok($o['plusInside'] && $o['plusAtEnd'], "{$w}: «+» داخلِ خانه‌ی کالا و در انتهای آن است");
            T::ok($o['npHiddenBefore'] && $o['npOpen'] && $o['stayed'] && $o['npName'] === 'قاب سیلیکونی آزمون' && $o['npRow'] === '3',
                "{$w}: ⛔ «+» پنل را درجا باز کرد (فرم فرستاده نشد) و متنِ ردیف را پیشنهاد داد",
                json_encode([$o['npHiddenBefore'], $o['npOpen'], $o['stayed'], $o['npName'], $o['npRow']], JSON_UNESCAPED_UNICODE));
            T::ok($o['npImeiHidden'] && $o['npImeiShownPhone'] && $o['npClosed'], "{$w}: خانه‌های IMEIِ پنل فقط برای گوشی؛ «بستن» پنل را می‌بندد");
            T::same('4', $o['addedPlusValue'], "{$w}: ردیفِ افزوده‌ی جاوااسکریپت «+»ِ خودش را با اندیسِ درست دارد");
            T::ok($o['npTypeFromImei'] === 'phone' && $o['npImei1'] === '359999000011112', "{$w}: IMEIِ ردیف + «+» → نوعِ گوشی با همان IMEI");
            T::ok($o['sw'] <= $o['W'], "{$w}: بی‌اسکرولِ افقی", "{$o['sw']} > {$o['W']}");
        }
    }
}

exec("kill $srv 2>/dev/null");
@unlink($log); @unlink($jar);
$wipe();
exit(T::report());
