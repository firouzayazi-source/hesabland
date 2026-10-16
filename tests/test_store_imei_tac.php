<?php
/**
 * ⛔ هشدارِ TACِ IMEI و «استعلامِ همتا» (`BizSerial::tacWarnings()`).
 *
 * **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵):** گوشی‌هایی در بازار است که IMEIشان مالِ
 * دستگاهِ دیگری (کارت‌خوان) است؛ رقمِ کنترل (Luhn) این را نمی‌گیرد.
 *
 *   ۱. TAC و «خانواده»ی نام.
 *   ۲. TACی که قبلاً با برندِ دیگری ثبت شده ⇒ هشدار هنگامِ صدور؛ همان برند
 *      (رنگ/حافظه‌ی دیگر) ⇒ بی‌هشدار؛ سندِ خودش در سابقه شمرده نمی‌شود.
 *   ۳. فهرستِ TACِ مشکوکِ نصب: خطِ نامعتبر رد، TACِ مشکوک ⇒ هشدار.
 *   ۴. هیچ‌کدام صدور را نمی‌بندد.
 *   ۵. لینکِ پیامکِ همتا (۷۷۷۷) و فرمِ فهرست فقط برای مدیرِ نصب.
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
require_once __DIR__ . '/../includes/biz_docview.php';

const TPREFIX = '__btac_';

try { $pdo = Database::getConnection(); }
catch (Throwable $e) { T::blocked('TACِ IMEI', 'اتصال به دیتابیس برقرار نشد: ' . $e->getMessage()); exit(T::report()); }
if (!Biz::available() || !tableHasColumn('biz_invoice_lines', 'imei1')) {
    T::blocked('TACِ IMEI', 'ستون‌های فروشگاه نیست — اول: bash deploy/migrate.sh --apply');
    exit(T::report());
}

$wipe = function () use ($pdo) {
    foreach ($pdo->query("SELECT id FROM users WHERE username LIKE '" . TPREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        foreach (['biz_allocations', 'biz_payments', 'biz_stock_moves'] as $t) { $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]); }
        foreach (['biz_invoices', 'biz_products', 'biz_categories', 'biz_parties', 'biz_accounts', 'biz_settings'] as $t) {
            $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]);
        }
        foreach (userDataTables() as $t) { try { $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]); } catch (PDOException $e) { } }
        $pdo->prepare('DELETE FROM audit_log WHERE target_user_id = :u')->execute(['u' => $id]);
    }
    $pdo->exec("DELETE FROM users WHERE username LIKE '" . TPREFIX . "%'");
};
$wipe();
$flagBak = getSetting(BizSerial::FLAGGED_TACS_KEY, '');

/** IMEIِ ۱۵ رقمیِ معتبر از TAC و شماره‌ی سریالِ ۶ رقمی */
$imei = function (string $tac, int $serial): string {
    $b = $tac . str_pad((string)$serial, 6, '0', STR_PAD_LEFT);
    for ($d = 0; $d <= 9; $d++) { if (BizSerial::luhnOk($b . $d)) { return $b . $d; } }
    throw new RuntimeException('no check digit');
};

$res = createUserAccount($pdo, 'فروشگاهِ TAC', TPREFIX . 'a', TPREFIX . 'a@example.com', 'Tac12345');
$st = $pdo->prepare('SELECT id FROM users WHERE username = :u'); $st->execute(['u' => TPREFIX . 'a']);
$u = (int)$st->fetchColumn();
Biz::setType($u, 'business');
$acc = (int)BizCash::list($u)[0]['id'];
$buy = function (string $name, string $im) use ($u, $acc): array {
    $r = BizInvoices::saveDraft($u, 'purchase', ['lines' => [['item' => $name, 'qty' => '1', 'price' => '100', 'imei1' => $im, 'imei2' => '']]]);
    if (!$r['ok']) { return ['ok' => false, 'message' => $r['message'], 'imei_warn' => '']; }
    return BizInvoices::issue($u, (int)$r['id'], ['full' => true, 'account_id' => $acc]) + ['id' => (int)$r['id']];
};
foreach (['iPhone 13 128GB Black', 'iPhone 13 128GB Blue', 'Galaxy A54 256GB'] as $n) {
    BizProducts::save($u, ['type' => 'phone', 'name' => $n, 'unit' => 'دستگاه', 'buy_price' => '100', 'sell_price' => '200']);
}

T::group('۱ — TAC و خانواده');
T::same('35391810', BizSerial::tac('353918100000015'), 'TAC = هشت رقمِ اول');
T::same('35391810', BizSerial::tac('۳۵۳۹۱۸۱۰-۰۰۰۰۰۱-۵'), 'ارقامِ فارسی و خط‌تیره');
T::same('', BizSerial::tac('12345'), 'IMEIِ کوتاه: بی‌TAC');
T::same('iphone', BizSerial::family('iPhone 13 128GB Black'), 'خانواده: اولین واژه‌ی لاتین');
T::same(BizSerial::family('iPhone 13 Blue'), BizSerial::family('iphone 13 pro'), 'رنگ و حافظه خانواده را عوض نمی‌کنند');
T::ok(BizSerial::family('Galaxy A54') !== BizSerial::family('iPhone 13'), 'برندِ دیگر = خانواده‌ی دیگر');

T::group('۲ — سابقه‌ی فروشگاه');
$A = '35000011';
$r = $buy('iPhone 13 128GB Black', $imei($A, 1));
T::ok($r['ok'] && $r['imei_warn'] === '', 'اولین ثبتِ یک TAC: بی‌هشدار', $r['message'] ?? '');
$r = $buy('iPhone 13 128GB Blue', $imei($A, 2));
T::ok($r['ok'] && $r['imei_warn'] === '', 'همان TAC با رنگِ دیگرِ همان مدل: بی‌هشدار', $r['imei_warn'] ?? '');
$r = $buy('Galaxy A54 256GB', $imei($A, 3));
T::ok($r['ok'], '⛔ هشدار صدور را نمی‌بندد', $r['message'] ?? '');
T::ok(str_contains($r['imei_warn'], $imei($A, 3)) && str_contains($r['imei_warn'], 'iPhone 13'),
    '⛔ TACِ آیفون زیرِ «Galaxy» ⇒ هشدار با نامِ مدلِ قبلی', $r['imei_warn']);
T::ok(str_contains($r['message'], '⚠'), 'و در پیامِ صدور هم هست', $r['message']);

// سندِ خودش در سابقه شمرده نمی‌شود: اولین ثبتِ یک TACِ تازه زیرِ Galaxy، بعد سنجشِ دوباره‌ی همان سند
$B = '35000022';
$r = $buy('Galaxy A54 256GB', $imei($B, 1));
$lines = [['product_id' => 1, 'description' => 'Galaxy A54 256GB', 'imei1' => $imei($B, 1), 'imei2' => '']];
T::same([], BizSerial::tacWarnings($pdo, $u, $lines, (int)$r['id']), 'سندِ خودش با خودش سنجیده نمی‌شود');
T::ok(BizSerial::tacWarnings($pdo, $u, [['product_id' => 1, 'description' => 'iPhone 13', 'imei1' => $imei($B, 5), 'imei2' => '']]) !== [],
    'ولی ردیفِ دیگری با برندِ دیگر می‌گیرد');
T::same([], BizSerial::tacWarnings($pdo, $u, [['product_id' => null, 'description' => 'iPhone 13', 'imei1' => $imei($B, 6), 'imei2' => '']]),
    'شرحِ آزاد (بی‌کالا) سنجیده نمی‌شود');

T::group('۳ — فهرستِ TACِ مشکوکِ نصب');
$bad = BizSerial::saveFlaggedTacs("35999999 کارت‌خوان\nنامعتبر");
T::ok(!$bad['ok'] && str_contains($bad['message'], '۲'), 'خطِ نامعتبر رد و شماره‌اش گفته می‌شود', $bad['message']);
T::same($flagBak, getSetting(BizSerial::FLAGGED_TACS_KEY, ''), 'و چیزی ذخیره نشد');
$ok = BizSerial::saveFlaggedTacs("۳۵۹۹۹۹۹۹ کارت‌خوان\n35888888");
T::ok($ok['ok'], 'ارقامِ فارسی پذیرفته', $ok['message']);
T::same(['35999999' => 'کارت‌خوان', '35888888' => ''], BizSerial::flaggedTacs(), 'فهرست خوانده شد');
$r = $buy('iPhone 13 128GB Black', $imei('35999999', 1));
T::ok($r['ok'] && str_contains($r['imei_warn'], 'فهرستِ TACِ مشکوک') && str_contains($r['imei_warn'], 'کارت‌خوان'),
    '⛔ TACِ مشکوک ⇒ هشدار با یادداشت (حتی بی‌سابقه)', $r['imei_warn'] ?? '');
setSetting(BizSerial::FLAGGED_TACS_KEY, $flagBak);

T::group('۴ — استعلامِ همتا');
T::same('sms:7777?body=353918100000015', BizSerial::hamtaSms('35391810 0000015'), 'پیامکِ آماده به ۷۷۷۷');
T::same('sms:7777?body=353918100000015', BizSerial::hamtaSms('3539181000000150'), 'IMEISVِ ۱۶ رقمی ⇒ ۱۵ رقمِ اول');
T::same('', BizSerial::hamtaSms('abc'), 'IMEIِ نامعتبر: بی‌لینک');
$h = BizDocView::hamtaLinks(['imei1' => '353918100000015', 'imei2' => '353918100000023']);
T::ok(substr_count($h, 'sms:7777') === 2 && str_contains($h, 'IMEI ۲'), 'صفحه‌ی سند: یک لینک برای هر IMEI', $h);
$inv = (string)file_get_contents(__DIR__ . '/../store/invoice.php');
T::ok(str_contains($inv, 'hamtaLinks('), 'صفحه‌ی فاکتور لینک را نشان می‌دهد');
$print = (string)file_get_contents(__DIR__ . '/../store/print.php');
T::ok(!str_contains($print, 'hamtaLinks('), 'ولی چاپ (برگه‌ی دستِ مشتری) نه');
$set = (string)file_get_contents(__DIR__ . '/../store/settings.php');
T::ok(preg_match_all('/Auth::isAdmin\(\) (?:\|\||&&) !?Biz::isStaff\(\)/', $set) >= 2,
    '⛔ فرم و ذخیره‌ی فهرست فقط برای مدیرِ نصب (نه صاحبِ فروشگاهِ دیگر، نه همکار)');

$wipe();
exit(T::report());
