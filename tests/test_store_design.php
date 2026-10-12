<?php
/**
 * ⛔ پاک‌سازیِ کالاهای استفاده‌نشده و طراحیِ فاکتور با لوگو.
 *
 * خطرها، هر کدام بی‌صدا:
 *   ۱. **حذفِ کالای استفاده‌شده** — فاکتورِ قدیمی (حتی پیش‌نویس) بی‌کالا
 *      می‌ماند؛ یا کالای **فروشگاهِ دیگر** با شناسه‌ی فرم حذف شود.
 *   ۲. «بی‌موجودی» که کالای دارای موجودیِ اول دوره را هم ببرد.
 *   ۳. **لوگو** که فایلِ خامِ کاربر (یا چیزی غیرِ تصویر) روی برگه بنشاند، یا
 *      لوگوی فروشگاهِ دیگر روی فاکتورِ این فروشگاه.
 *   ۴. طراحی که ذخیره نشود، پیش‌نمایشی که **ذخیره کند**، یا رنگی که از
 *      `<style>` بیرون بزند.
 *   ۵. فاکتورِ نصبِ هنوز‌طراحی‌نشده که ظاهرش عوض شود.
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
require_once __DIR__ . '/../includes/biz_docview.php';
require_once __DIR__ . '/../includes/biz_invoice_design.php';

$root = dirname(__DIR__);
const DPREFIX = '__bdes_';
const DPASS   = 'Des12345';

T::group('۰ — عدد به حروف و پاک‌سازیِ گزینه‌ها (بی‌دیتابیس)');
T::same('صفر', BizCommon::words(0), '۰');
T::same('بیست و یک', BizCommon::words(21), '۲۱');
T::same('یکصد و پنج', BizCommon::words(105), '۱۰۵');
T::same('یک هزار و یک', BizCommon::words(1001), '۱۰۰۱');
T::same('یک میلیون و دویست هزار و پنجاه', BizCommon::words(1200050), '۱٬۲۰۰٬۰۵۰ — سه‌رقمیِ صفر جا می‌افتد');
T::same('پنجاه و هشت میلیون و هشتصد هزار', BizCommon::words(58800000), '۵۸٬۸۰۰٬۰۰۰');
T::same('منفی چهل و پنج', BizCommon::words(-45), 'منفی');
T::ok(str_starts_with(BizCommon::words(PHP_INT_MIN), 'منفی نه کوینتیلیون') && str_ends_with(BizCommon::words(PHP_INT_MIN), 'هشتصد و هشت'), '⛔ PHP_INT_MIN بی‌سرریز');

$c = BizInvoiceDesign::clean(['template' => 'zzz', 'accent' => 'red;}body{display:none', 'logo_pos' => 'end', 'title_sale' => str_repeat('ا', 90)], true);
T::same('classic', $c['template'], 'قالبِ ناشناخته → پیش‌فرض');
T::same('#111827', $c['accent'], '⛔ رنگِ نامعتبر به پیش‌فرض برمی‌گردد — از `<style>` بیرون نمی‌زند');
T::same('end', $c['logo_pos'], 'گزینه‌ی معتبر می‌ماند');
T::same(BizInvoiceDesign::TEXTS['title_sale'], mb_strlen($c['title_sale']), 'متن به سقف بریده می‌شود');
T::ok($c['show_logo'] === false && $c['col_row'] === false, 'فرمِ چک‌باکسی: کلیدِ نبوده یعنی خاموش');
T::same('#1e3a8a', BizInvoiceDesign::clean(['accent' => '#1E3A8A'], true)['accent'], 'رنگ کوچک‌حرف می‌شود');
$fb = BizInvoiceDesign::clean([], false, ['show_sign' => true, 'show_header' => false, 'show_balance' => false]);
T::ok($fb['show_sign'] === true && $fb['show_shop'] === false && $fb['show_balance'] === false && $fb['col_row'] === true,
    '⛔ طراحی‌نشده: کلیدهای مشترک از «تنظیمات چاپ»، بقیه پیش‌فرض');
$flat = [];
foreach (BizInvoiceDesign::FLAGS as $g) { $flat += $g; }
T::same([], array_values(array_diff(array_keys($flat), array_keys(BizInvoiceDesign::DEFAULTS))), 'هر کلیدِ فرم پیش‌فرض دارد');

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::blocked('طراحیِ فاکتور', 'اتصال به دیتابیس برقرار نشد: ' . $e->getMessage());
    exit(T::report());
}
if (!Biz::available() || !BizInvoiceDesign::ready()) {
    T::blocked('طراحیِ فاکتور', 'جدولِ لوگو یا ستونِ طراحی نیست — اول: bash deploy/migrate.sh --apply');
    exit(T::report());
}

$wipe = function () use ($pdo) {
    $ids = $pdo->query("SELECT id FROM users WHERE username LIKE '" . DPREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        foreach (['biz_allocations', 'biz_payments', 'biz_stock_moves'] as $t) {
            $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]);
        }
        $pdo->prepare('DELETE FROM biz_invoices WHERE user_id = :u AND ref_invoice_id IS NOT NULL')->execute(['u' => $id]);
        foreach (['biz_invoices', 'biz_products', 'biz_categories', 'biz_parties', 'biz_accounts', 'biz_settings', 'biz_logos'] as $t) {
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
Biz::setType($a, 'business');
Biz::setType($b, 'business');
$exists = function (int $id) use ($pdo): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM biz_products WHERE id = :id');
    $st->execute(['id' => $id]);
    return (int)$st->fetchColumn() === 1;
};
$prod = fn(int $u, string $name, array $x = []): int => (int)BizProducts::save($u, $x + ['type' => 'goods', 'name' => $name, 'unit' => 'عدد', 'sell_price' => '1000'])['id'];

// =================================================================
T::group('۱ — کدام کالا «استفاده‌نشده» است');
$empty  = $prod($a, 'بی‌استفاده‌ی بی‌موجودی', ['category' => 'قاب']);
$stock  = $prod($a, 'بی‌استفاده با موجودی', ['opening_qty' => '5', 'buy_price' => '800']);
$svc    = $prod($a, 'خدمتِ بی‌استفاده', ['type' => 'service']);
$usedI  = $prod($a, 'فروخته‌شده', ['opening_qty' => '3', 'buy_price' => '800']);
$usedD  = $prod($a, 'فقط در پیش‌نویس');
$other  = $prod($b, 'کالای فروشگاهِ ب');
$cus = (int)BizParties::save($a, ['name' => 'مشتری', 'kind' => 'customer'])['id'];
$inv = BizInvoices::saveDraft($a, 'sale', ['party_id' => $cus, 'inv_date' => date('Y-m-d'), 'lines' => [['item' => 'فروخته‌شده', 'qty' => '1', 'price' => '1000']]]);
T::ok(BizInvoices::issue($a, (int)$inv['id'], ['full' => false, 'amount' => '0'])['ok'], 'فاکتورِ فروش صادر شد');
BizInvoices::saveDraft($a, 'sale', ['party_id' => $cus, 'inv_date' => date('Y-m-d'), 'lines' => [['item' => 'فقط در پیش‌نویس', 'qty' => '1', 'price' => '1000']]]);
BizStock::adjustTo($a, $usedI, 0.0, 'انبارگردانی');   // حرکتِ بی‌سند — مانع نیست، ولی سندِ فروش هست

$ids = fn(array $l): array => array_map(fn($r) => (int)$r['id'], $l['rows']);
$eList = BizProducts::unused($a, 'empty');
$got = $ids($eList); sort($got); $want = [$empty, $svc]; sort($want);
T::same($want, $got, 'بی‌موجودی: فقط بی‌سند و بی‌موجودی (و خدمت)');
T::ok(!in_array($stock, $ids($eList), true), '⛔ «بی‌موجودی» کالای با موجودیِ اول دوره را نمی‌آورد');
$aList = $ids(BizProducts::unused($a, 'all'));
sort($aList);
$want = [$empty, $stock, $svc];
sort($want);
T::same($want, $aList, 'همه: با موجودیِ اول دوره هم، ولی نه فروخته‌شده و نه پیش‌نویس');
T::ok(!in_array($other, $ids(BizProducts::unused($a, 'all')), true), '⛔ کالای فروشگاهِ دیگر در فهرست نیست');
T::same([$empty], $ids(BizProducts::unused($a, 'all', 'قاب')), 'صافیِ دسته');

// =================================================================
T::group('۲ — حذفِ دسته‌جمعی');
$r = BizProducts::deleteUnused($a, [$usedI, $usedD, $other, $stock]);
T::ok($r['ok'] && $r['deleted'] === 1 && $r['skipped'] === 3, 'از چهار شناسه فقط کالای بی‌سند حذف شد: ' . $r['message']);
T::ok($exists($usedI) && $exists($usedD), '⛔ کالای فاکتورِ صادرشده و پیش‌نویس می‌ماند');
T::ok($exists($other), '⛔ کالای فروشگاهِ دیگر با شناسه‌ی فرم حذف نمی‌شود');
T::ok(!$exists($stock), 'کالای تیک‌خورده‌ی با موجودی حذف شد (انتخابِ صریح)');
T::same(0, (int)$pdo->query("SELECT COUNT(*) FROM biz_stock_moves WHERE product_id = {$stock}")->fetchColumn(), 'حرکت‌های اول دوره‌اش هم رفت (CASCADE)');
T::ok(!BizProducts::deleteUnused($a, [])['ok'], 'انتخابِ خالی رد می‌شود');
$stock2 = $prod($a, 'دومی با موجودی', ['opening_qty' => '2', 'buy_price' => '800']);
$r = BizProducts::deleteUnused($a, null, 'empty');
T::ok($r['ok'] && $r['deleted'] === 2, '«حذفِ همه» (بی‌موجودی): ' . $r['message']);
T::ok(!$exists($empty) && !$exists($svc) && $exists($stock2), '⛔ «حذفِ همه»ِ بی‌موجودی کالای با موجودی را نمی‌برد');
T::ok($exists($usedI) && $exists($usedD) && $exists($other), 'استفاده‌شده‌ها و فروشگاهِ دیگر دست نخوردند');
T::ok(!BizProducts::deleteUnused($a, null, 'empty')['ok'], 'دیگر چیزی نمانده');

// ⛔ ورودِ اشتباه (گزارشِ مالکِ نصب، مهر ۱۴۰۵): موجودیِ «ورود از فایل» انبارگردانی است و هیچ
//    دامنه‌ی دیگری پاکش نمی‌کرد. `reset` = هر کالای بی‌فاکتور، فقط با تأییدِ نوشتاری.
T::group('۲ب — حذفِ کاملِ ورودِ اشتباه');
$imp = $prod($a, 'واردشده‌ی اشتباه', ['opening_qty' => '4', 'buy_price' => '500', 'category' => 'اشتباه']);
BizStock::adjustTo($a, $imp, 9.0, 'ورود از فایل');
$imp2 = $prod($a, 'دومیِ اشتباه', ['category' => 'اشتباه']);
BizStock::adjustTo($a, $imp2, 3.0, 'ورود از فایل');
$keepCat = $prod($a, 'در دسته‌ی ماندنی', ['category' => 'ماندنی']);
BizInvoices::saveDraft($a, 'sale', ['party_id' => $cus, 'inv_date' => date('Y-m-d'), 'lines' => [['item' => 'در دسته‌ی ماندنی', 'qty' => '1', 'price' => '1']]]);
$wh = null;
if (BizWarehouses::ready()) {
    $wh = (int)BizWarehouses::save($a, 'انبارِ آزمونِ پاک‌سازی')['id'];
    BizWarehouses::transfer($a, $imp, 0, $wh, '2', date('Y-m-d'));
}
T::ok(!in_array($imp, $ids(BizProducts::unused($a, 'all')), true), 'دامنه‌ی «همه» کالای انبارگردانی‌شده را نمی‌آورد (همان رفتارِ پیشین)');
$rl = $ids(BizProducts::unused($a, 'reset'));
T::ok(in_array($imp, $rl, true) && in_array($imp2, $rl, true), '«حذفِ کامل» کالای انبارگردانی‌شده را می‌آورد');
T::ok(!in_array($usedI, $rl, true) && !in_array($usedD, $rl, true) && !in_array($keepCat, $rl, true) && !in_array($other, $rl, true),
    '⛔ نه فاکتوردار، نه پیش‌نویس‌دار، نه فروشگاهِ دیگر');
$r = BizProducts::deleteUnused($a, null, 'reset', '', 'حذ');
T::ok(!$r['ok'] && $exists($imp), '⛔ بی‌کلمه‌ی تأیید هیچ‌کار');
T::ok(!BizProducts::deleteUnused($a, [$imp], 'reset')['ok'] && $exists($imp), '⛔ انتخابِ دستی هم بی‌تأیید نه');
$r = BizProducts::deleteUnused($a, [$imp2, $usedI], 'reset', '', BizProducts::RESET_WORD);
T::ok($r['ok'] && $r['deleted'] === 1 && !$exists($imp2) && $exists($usedI), 'انتخابِ دستی در حذفِ کامل: انبارگردانی‌شده رفت، فاکتوردار ماند');
Biz::saveLock($a, date('Y-m-d'));
$r = BizProducts::deleteUnused($a, null, 'reset', '', BizProducts::RESET_WORD);
T::ok($exists($imp), '⛔ کالای دارای حرکت در دوره‌ی بسته نمی‌رود');
Biz::saveLock($a, '', true);
$r = BizProducts::deleteUnused($a, null, 'reset', '', ' ' . BizProducts::RESET_WORD . ' ', true);
T::ok($r['ok'] && !$exists($imp) && !$exists($imp2), 'حذفِ کامل: ' . $r['message']);
T::same(0, (int)$pdo->query("SELECT COUNT(*) FROM biz_stock_moves WHERE product_id IN ({$imp}, {$imp2})")->fetchColumn(), 'حرکت‌ها (اول دوره و انبارگردانی) هم رفتند');
if ($wh !== null) {
    T::same(0, (int)$pdo->query("SELECT COUNT(*) FROM biz_stock_transfers WHERE product_id = {$imp}")->fetchColumn(), '⛔ انتقالِ انبارش هم رفت (بی‌کلید، یتیم نمی‌ماند)');
}
T::ok($exists($usedI) && $exists($usedD) && $exists($keepCat) && $exists($other), '⛔ فاکتوردار، پیش‌نویس‌دار و فروشگاهِ دیگر ماندند');
$catNames = array_map('strval', BizProducts::categories($a));
T::ok(!in_array('اشتباه', $catNames, true) && in_array('ماندنی', $catNames, true), 'دسته‌ی خالی‌مانده رفت، دسته‌ی دارای کالا ماند');
T::same((int)BizStock::recalc($a, $usedI)['qty'] === (int)$pdo->query("SELECT stock_qty FROM biz_products WHERE id = {$usedI}")->fetchColumn(), true, 'موجودیِ کالای مانده سالم');
T::ok(!$exists($stock2), 'حذفِ کامل کالای بی‌استفاده‌ی با موجودیِ اول دوره را هم برد');
$stock2 = $prod($a, 'دومی با موجودی', ['opening_qty' => '2', 'buy_price' => '800']);   // برای بخشِ HTTP

// =================================================================
T::group('۳ — لوگو');
$png = function (int $w, int $h, bool $alpha = true): string {
    $im = imagecreatetruecolor($w, $h);
    if ($alpha) { imagesavealpha($im, true); imagealphablending($im, false); imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127)); }
    imagefilledellipse($im, intdiv($w, 2), intdiv($h, 2), intdiv($w, 2), intdiv($h, 2), imagecolorallocate($im, 30, 58, 138));
    ob_start(); imagepng($im); $o = (string)ob_get_clean(); imagedestroy($im);
    return $o;
};
T::same(null, BizInvoiceDesign::logo($a), 'بی‌لوگو');
$poly = $png(1200, 600) . "\n<?php echo 'x'; ?><script>alert(1)</script>";
$r = BizInvoiceDesign::saveLogoBytes($a, $poly);
T::ok($r['ok'], 'PNG (با دنباله‌ی مشکوک) پذیرفته شد: ' . $r['message']);
$lg = BizInvoiceDesign::logo($a);
T::same('image/png', $lg['mime'] ?? '', 'شفاف → PNG');
$raw = base64_decode((string)($lg['data'] ?? ''), true);
T::ok(is_string($raw) && !str_contains($raw, '<?php') && !str_contains($raw, '<script'), '⛔ تصویر از نو ساخته شد — دنباله‌ی فایلِ کاربر نمی‌ماند');
T::ok(($lg['width'] ?? 0) === 800 && ($lg['height'] ?? 0) === 400, 'به کادرِ ۸۰۰×۴۰۰ کوچک شد');
$jpg = (function () { $im = imagecreatetruecolor(300, 150); imagefill($im, 0, 0, imagecolorallocate($im, 200, 10, 10)); ob_start(); imagejpeg($im); return (string)ob_get_clean(); })();
T::ok(BizInvoiceDesign::saveLogoBytes($b, $jpg)['ok'] && (BizInvoiceDesign::logo($b)['mime'] ?? '') === 'image/jpeg', 'JPG → JPEG (برای فروشگاهِ ب)');
T::ok(!BizInvoiceDesign::saveLogoBytes($a, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')['ok'], '⛔ SVG رد می‌شود');
T::ok(!BizInvoiceDesign::saveLogoBytes($a, "GIF89a<?php system('id'); ?>")['ok'], '⛔ فایلِ جعلی با امضای تصویر رد می‌شود');
$bmp = (function () { $im = imagecreatetruecolor(40, 20); ob_start(); imagebmp($im); return (string)ob_get_clean(); })();
$rb  = BizInvoiceDesign::saveLogoBytes($a, $bmp);
T::ok(!$rb['ok'] && str_contains($rb['message'], 'فقط تصویرِ'), '⛔ قالبی بیرونِ فهرستِ بسته (BMP) رد می‌شود، حتی اگر GD بخواندش');
$big = (function () { $im = imagecreate(6000, 6000); imagecolorallocate($im, 255, 255, 255); ob_start(); imagepng($im, null, 9); $o = (string)ob_get_clean(); imagedestroy($im); return $o; })();
T::ok(!BizInvoiceDesign::saveLogoBytes($a, $big)['ok'], '⛔ ابعادِ بیش از حد (بمبِ حافظه) پیش از بازکردن رد می‌شود');
T::same('image/png', BizInvoiceDesign::logo($a)['mime'] ?? '', 'ردِ فایلِ بد لوگوی قبلی را پاک نکرد');
$pdo->prepare("UPDATE biz_logos SET mime = 'text/html' WHERE user_id = :u")->execute(['u' => $b]);
T::same(null, BizInvoiceDesign::logo($b), '⛔ ردیفِ دست‌کاری‌شده (نوعِ غیرِ تصویر، مثلاً از بکاپ) خوانده نمی‌شود');
$pdo->prepare("UPDATE biz_logos SET mime = 'image/jpeg', data = 'AAA\" onerror=\"x' WHERE user_id = :u")->execute(['u' => $b]);
T::same(null, BizInvoiceDesign::logo($b), '⛔ base64ِ نامعتبر خوانده نمی‌شود');
T::ok(in_array('biz_logos', userDataTables(), true), 'لوگو در خروجی و حذفِ حساب کشف می‌شود');
T::ok(str_contains(json_encode(exportUserData($a)['tables']['biz_logos'] ?? []), 'image\/png'), 'لوگو در فایلِ پشتیبانِ حساب می‌آید');

// =================================================================
T::group('۴ — ذخیره‌ی طراحی');
T::ok(!BizInvoiceDesign::saved($a), 'هنوز طراحی نشده');
T::ok(!BizInvoiceDesign::save($a, ['accent' => 'blue'])['ok'], '⛔ رنگِ نامعتبر در ذخیره صریح رد می‌شود');
$form = ['template' => 'modern', 'accent' => '#0f766e', 'logo_pos' => 'start', 'logo_size' => 'lg',
    'show_logo' => '1', 'show_shop' => '1', 'show_party' => '1', 'col_row' => '1', 'col_sku' => '1', 'show_words' => '1', 'show_stamp' => '1',
    'title_sale' => 'صورتحسابِ <b>فروش</b>', 'terms' => "شرطِ اول\nشرطِ دوم"];
T::ok(BizInvoiceDesign::save($a, $form)['ok'], 'ذخیره شد');
$d = BizInvoiceDesign::get($a);
T::ok(BizInvoiceDesign::saved($a) && $d['template'] === 'modern' && $d['accent'] === '#0f766e' && $d['show_words'] && !$d['col_discount'],
    'همان‌جا خوانده می‌شود (بی‌کشِ کهنه) و کلیدِ نبوده خاموش است');
$pdo->prepare("UPDATE biz_settings SET print_prefs = :p WHERE user_id = :u")->execute(['u' => $a, 'p' => json_encode(['show_sign' => true])]);
Biz::forgetSettings($a);
T::ok(BizInvoiceDesign::get($a)['show_sign'] === false, '⛔ پس از ذخیره، «تنظیمات چاپ» روی فاکتور اثر ندارد');
T::ok(BizInvoiceDesign::reset($a)['ok'] && !BizInvoiceDesign::saved($a), 'برگشت به پیش‌فرض');
T::ok(BizInvoiceDesign::logo($a) !== null, 'برگشت لوگو را نبرد');
BizInvoiceDesign::save($a, $form);

// =================================================================
T::group('۵ — برگه و صفحه‌ها با HTTP');
$port = 0;
for ($pp = 9461; $pp <= 9510; $pp++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$pp", $e1, $e2);
    if ($sock) { fclose($sock); $port = $pp; break; }
}
$log = tempnam(sys_get_temp_dir(), 'bdes');
$srv = $port ? (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
    $port, escapeshellarg($root), escapeshellarg($log)))) : 0;
$up = false;
for ($i = 0; $srv && $i < 40; $i++) {
    usleep(150000);
    $sk = @fsockopen('127.0.0.1', $port, $x, $y, 0.3);
    if ($sk) { fclose($sk); $up = true; break; }
}
if (!$up) {
    T::blocked('طراحیِ فاکتور (HTTP)', 'سرورِ آزمایشی بالا نیامد');
    if ($srv) { exec("kill $srv 2>/dev/null"); }
    $wipe();
    exit(T::report());
}
$jar = tempnam(sys_get_temp_dir(), 'bdesjar');
$req = function (string $path, ?array $post = null, bool $multipart = false) use ($port, $jar): array {
    $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 40]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $post : http_build_query($post)); }
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
};
$csrfOf = fn(string $html): string => preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m) ? $m[1] : '';
[, $lp] = $req('store/login.php');
T::same(302, $req('store/login.php', ['csrf_token' => $csrfOf($lp), 'username' => DPREFIX . 'a', 'password' => DPASS])[0], 'ورود');

$invId = (int)$inv['id'];
[$c, $pr] = $req('store/print.php?doc=invoice&id=' . $invId);
T::ok($c === 200 && str_contains($pr, '</html>'), 'برگه‌ی فاکتور رندر شد');
T::ok(str_contains($pr, 'pr-t-modern') && str_contains($pr, '--pr-accent: #0f766e'), 'قالب و رنگِ طراحی');
T::ok(str_contains($pr, 'src="data:image/png;base64,'), 'لوگو با `data:` (CSP) روی برگه');
T::ok(str_contains($pr, 'صورتحسابِ &lt;b&gt;فروش&lt;/b&gt;') && !str_contains($pr, 'صورتحسابِ <b>'), '⛔ عنوانِ دلخواه فرار داده شد');
T::ok(str_contains($pr, 'مبلغِ کل به حروف') && str_contains($pr, 'یک هزار تومان'), 'مبلغ به حروف');
T::ok(str_contains($pr, '>کد</th>') && !str_contains($pr, '>تخفیف</th>'), 'ستون‌ها طبقِ طراحی (کد روشن، تخفیف خاموش)');
T::ok(str_contains($pr, 'شرطِ اول<br />') && str_contains($pr, 'محلِ مهرِ فروشگاه'), 'شرایط و جای مهر');
T::ok(!str_contains($pr, 'base64,' . (BizInvoiceDesign::logo($b)['data'] ?? 'zz-none')), 'لوگوی فروشگاهِ دیگر نیست');

[$c, $pv] = $req('store/print.php?doc=invoice&id=' . $invId . '&preview=1&embed=1&template=minimal&accent=' . rawurlencode('#123456;}*{display:none'));
T::ok($c === 200 && str_contains($pv, 'pr-t-minimal') && str_contains($pv, '--pr-accent: #111827') && !str_contains($pv, 'display:none'), '⛔ پیش‌نمایش: گزینه‌ها از آدرس، رنگِ نامعتبر بی‌اثر');
T::ok(!str_contains($pv, 'class="pr-bar"') && !str_contains($pv, 'data:image'), 'پیش‌نمایشِ قابی بی‌نوارِ ابزار؛ لوگو خاموش چون در آدرس نیامد');
$req('store/print.php?doc=invoice&id=' . $invId . '&preview=1&embed=1&template=formal&accent=' . rawurlencode('#654321'));
Biz::forgetSettings($a);
T::ok(BizInvoiceDesign::get($a)['template'] === 'modern' && BizInvoiceDesign::get($a)['accent'] === '#0f766e', '⛔ پیش‌نمایش (حتی با گزینه‌های معتبر) چیزی ذخیره نمی‌کند');
[$c, $sm] = $req('store/print.php?doc=invoice&sample=1');
T::ok($c === 200 && str_contains($sm, 'مشتریِ نمونه'), 'فاکتورِ نمونه');
[$c] = $req('store/print.php?doc=invoice&id=' . $invId . '9');
T::same(404, $c, 'فاکتورِ ناموجود ۴۰۴');

[$c, $dp] = $req('store/invoice-design.php');
T::ok($c === 200 && str_contains($dp, 'data-design-preview') && str_contains($dp, 'name="template"') && str_contains($dp, 'name="logo"'), 'صفحه‌ی طراح');
T::ok(str_contains($dp, 'doc=invoice&amp;id=' . $invId), 'پیش‌نمایش با آخرین فاکتورِ فروش');
$req('store/invoice-design.php', ['action' => 'reset']);
Biz::forgetSettings($a);    // ⚠ کشِ همین پروسه — وگرنه بررسی مقدارِ پیش از درخواست را می‌خواند
T::ok(BizInvoiceDesign::saved($a), '⛔ بی‌CSRF هیچ‌کار');
[$c] = $req('store/invoice-design.php', ['csrf_token' => $csrfOf($dp), 'action' => 'save', 'template' => 'formal', 'accent' => '#b91c1c', 'show_party' => '1']);
T::same(302, $c, 'ذخیره با فرم → ریدایرکت');
Biz::forgetSettings($a);
T::same('formal', BizInvoiceDesign::get($a)['template'], 'قالبِ رسمی ذخیره شد');
$tmp = tempnam(sys_get_temp_dir(), 'bdeslogo');
file_put_contents($tmp, $jpg);
[$c] = $req('store/invoice-design.php', ['csrf_token' => $csrfOf($dp), 'action' => 'logo', 'logo' => new CURLFile($tmp, 'image/png', 'logo.php')], true);
T::ok($c === 302 && (BizInvoiceDesign::logo($a)['mime'] ?? '') === 'image/jpeg', 'بارگذاری با فرم؛ نوع از محتوا (نه از نام/اعلامِ مرورگر)');
@unlink($tmp);
[$c] = $req('store/invoice-design.php', ['csrf_token' => $csrfOf($dp), 'action' => 'logo_remove']);
T::ok($c === 302 && BizInvoiceDesign::logo($a) === null, 'برداشتنِ لوگو');
[, $ps] = $req('store/print-settings.php');
T::ok(str_contains($ps, 'invoice-design.php') && !str_contains($ps, 'name="show_balance"'), 'تنظیمات چاپ به طراح لینک دارد و «مانده‌ی قبلی» به طراح رفته');

[$c, $cl] = $req('store/products-cleanup.php?s=all');
T::ok($c === 200 && str_contains($cl, 'دومی با موجودی') && !str_contains($cl, 'فروخته‌شده') && !str_contains($cl, 'کالای فروشگاهِ ب'), 'صفحه‌ی پاک‌سازی فقط استفاده‌نشده‌های همین فروشگاه');
$req('store/products-cleanup.php?s=all', ['mode' => 'all']);
T::ok($exists($stock2), '⛔ بی‌CSRF هیچ‌کار');
[$c] = $req('store/products-cleanup.php?s=all', ['csrf_token' => $csrfOf($cl), 'mode' => 'pick', 'ids' => [(string)$stock2, (string)$usedI]]);
T::ok($c === 302 && !$exists($stock2) && $exists($usedI), 'حذفِ انتخاب‌شده‌ها با فرم؛ استفاده‌شده ماند');
[, $pl] = $req('store/products.php');
T::ok(str_contains($pl, 'products-cleanup.php'), 'لینکِ پاک‌سازی از فهرستِ کالاها');
$junk = $prod($a, 'اشتباهِ فرمی', ['opening_qty' => '1', 'buy_price' => '1']);
BizStock::adjustTo($a, $junk, 7.0, 'ورود از فایل');
[$c, $cl] = $req('store/products-cleanup.php?s=reset');
T::ok($c === 200 && str_contains($cl, 'اشتباهِ فرمی') && str_contains($cl, 'name="confirm"'), 'صفحه‌ی «حذفِ کامل» با کادرِ تأیید');
$req('store/products-cleanup.php?s=reset', ['csrf_token' => $csrfOf($cl), 'mode' => 'all', 'confirm' => 'بله']);
T::ok($exists($junk), '⛔ کلمه‌ی غلط: هیچ‌کار');
[$c] = $req('store/products-cleanup.php?s=reset', ['csrf_token' => $csrfOf($cl), 'mode' => 'all', 'confirm' => BizProducts::RESET_WORD]);
T::ok($c === 302 && !$exists($junk) && $exists($usedI), 'حذفِ کامل با فرم؛ فاکتوردار ماند');

exec("kill $srv 2>/dev/null");
@unlink($log); @unlink($jar);
$wipe();
exit(T::report());
