<?php
/**
 * ⛔ اطلاعاتِ رسمیِ فروشگاه، کدهای خریدار، و گزینه‌های فاکتور.
 *
 * سه خطر:
 *   ۱. **کدِ رسمیِ خراب روی فاکتورِ رسمی** — ارقامِ فارسی، فاصله، طولِ غلط.
 *   ۲. **گزینه‌ای که بی‌صدا رفتار را عوض کند** — پیش‌فرضِ همه خاموش است؛ و
 *      «قیمت را به‌روز کن» نباید قیمت را صفر کند یا به کالای فروشگاهِ دیگر
 *      برسد.
 *   ۳. **مشتریِ پیش‌فرضِ فروشگاهِ دیگر** روی فاکتور بنشیند.
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
const SPREFIX = '__bset_';
const SPASS   = 'Set12345';

// =================================================================
T::group('۰ — کدِ رسمی (بی‌دیتابیس)');
T::same(['ok' => true, 'value' => '411111111111'], BizCommon::code('economic_code', '۴۱۱۱ ۱۱۱۱-۱۱۱۱'), 'ارقامِ فارسی، فاصله و خط‌تیره → رقمِ لاتین');
T::same(['ok' => true, 'value' => null], BizCommon::code('postal_code', '  '), 'خالی یعنی «ندارد»، نه خطا');
T::ok(!BizCommon::code('postal_code', '12345')['ok'], 'کد پستی باید ۱۰ رقم باشد');
T::ok(!BizCommon::code('national_id', '12345678901234')['ok'], 'شناسه‌ی ملی بیش از ۱۱ رقم رد می‌شود');
T::ok(!BizCommon::code('economic_code', '41111a111111')['ok'], 'حرف در کد رد می‌شود');
T::ok(str_contains((string)(BizCommon::code('postal_code', '1')['message'] ?? ''), 'کد پستی'), 'پیام نامِ همان فیلد را می‌گوید');

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::blocked('تنظیماتِ فروشگاه', 'اتصال به دیتابیس برقرار نشد: ' . $e->getMessage());
    exit(T::report());
}
if (!Biz::available() || !tableExists('biz_invoices') || !Biz::businessInfoReady() || !BizParties::hasCodes()) {
    T::blocked('تنظیماتِ فروشگاه', 'ستون‌های اطلاعاتِ رسمی نیست — اول: bash deploy/migrate.sh --apply');
    exit(T::report());
}

$wipe = function () use ($pdo) {
    $ids = $pdo->query("SELECT id FROM users WHERE username LIKE '" . SPREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN);
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
    $pdo->exec("DELETE FROM users WHERE username LIKE '" . SPREFIX . "%'");
    $pdo->exec("DELETE FROM login_attempts WHERE username_tried LIKE '" . SPREFIX . "%'");
};
$wipe();
$make = function (string $name) use ($pdo): int {
    $res = createUserAccount($pdo, 'کاربرِ ' . $name, SPREFIX . $name, SPREFIX . $name . '@example.com', SPASS);
    if (!($res['ok'] ?? false)) { throw new RuntimeException('ساختِ کاربر: ' . ($res['error'] ?? '?')); }
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => SPREFIX . $name]);
    return (int)$st->fetchColumn();
};
$a = $make('a');
$b = $make('b');
Biz::setType($a, 'business');
Biz::setType($b, 'business');
$fresh = function (int $u) {   // کشِ درون‌پروسه‌ای را پاک کن تا از دیتابیس خوانده شود
    foreach (['settingsCache', 'invoiceRaw', 'paletteCache'] as $prop) {
        $r = new ReflectionProperty(Biz::class, $prop);
        $v = $r->getValue(); unset($v[$u]); $r->setValue(null, $v);
    }
};

// =================================================================
T::group('۱ — اطلاعاتِ رسمیِ فروشگاه');
$base = ['shop_name' => 'فروشگاهِ آزمون', 'phone' => '', 'address' => '', 'invoice_footer' => ''];
$r = Biz::saveSettings($a, $base + ['postal_code' => '123']);
T::ok(!$r['ok'] && str_contains($r['message'], 'کد پستی'), 'کد پستیِ کوتاه رد می‌شود', $r['message']);
$fresh($a);
T::same('', Biz::settings($a)['shop_name'], '⛔ ردِ یک کد هیچ چیزی ذخیره نمی‌کند');
$r = Biz::saveSettings($a, $base + ['economic_code' => '۴۱۱۱۱۱۱۱۱۱۱۱', 'national_id' => '10101010101', 'reg_no' => '۵۵۵', 'postal_code' => '1234567890']);
T::ok($r['ok'], 'کدهای درست ذخیره شد', $r['message']);
$fresh($a);
$set = Biz::settings($a);
T::same(['411111111111', '10101010101', '555', '1234567890'], [$set['economic_code'], $set['national_id'], $set['reg_no'], $set['postal_code']], 'کدها با رقمِ لاتین خوانده می‌شوند');
Biz::saveSettings($a, $base + ['economic_code' => '', 'national_id' => '', 'reg_no' => '', 'postal_code' => '']);
$fresh($a);
T::same(null, $pdo->query("SELECT economic_code FROM biz_settings WHERE user_id = {$a}")->fetchColumn() ?: null, 'خالی کردنِ فیلد کد را پاک می‌کند (NULL، نه رشته‌ی خالی)');
Biz::saveSettings($a, $base + ['economic_code' => '411111111111', 'national_id' => '10101010101']);

// =================================================================
T::group('۲ — گزینه‌های فاکتور: پیش‌فرض خاموش');
$fresh($a);
$pr = Biz::invoicePrefs($a);
T::same(['update_buy_price' => false, 'update_sell_price' => false, 'warn_below_cost' => false, 'show_profit' => false, 'default_party' => 0], $pr,
    '⛔ پیش‌فرضِ همه خاموش — هیچ نصبی رفتارش عوض نمی‌شود');
$pdo->prepare('UPDATE biz_settings SET invoice_prefs = :p WHERE user_id = :u')->execute(['p' => '{"show_profit":"yes","x":1', 'u' => $a]);
$fresh($a);
T::same(false, Biz::invoicePrefs($a)['show_profit'], 'JSONِ خراب یا مقدارِ غیرِبولی به پیش‌فرض برمی‌گردد');

$cash = (int)BizCash::list($a)[0]['id'];
$P    = (int)BizProducts::save($a, ['name' => 'گلس', 'sku' => 'G1', 'unit' => 'عدد', 'buy_price' => '100', 'sell_price' => '150'])['id'];
$sup  = (int)BizParties::save($a, ['name' => 'پخش', 'kind' => 'supplier'])['id'];
$cus  = (int)BizParties::save($a, ['name' => 'مشتری', 'kind' => 'customer'])['id'];
$bCus = (int)BizParties::save($b, ['name' => 'مشتریِ ب', 'kind' => 'customer'])['id'];
$r = Biz::saveInvoicePrefs($a, ['default_party' => (string)$bCus]);
T::ok(!$r['ok'], '⛔ مشتریِ فروشگاهِ دیگر پیش‌فرض نمی‌شود', $r['message']);
$lines = fn(array $rows): array => array_map(fn($x) => ['item' => $x[0], 'qty' => (string)$x[1], 'price' => (string)$x[2]], $rows);
$buy = function (int $price) use ($a, $sup, $lines): bool {
    $r = BizInvoices::saveDraft($a, 'purchase', ['party_id' => $sup, 'lines' => $lines([['G1', 10, $price]])]);
    return BizInvoices::issue($a, (int)$r['id'], ['amount' => '0'])['ok'];
};
$price = fn(string $col): int => (int)$pdo->query("SELECT {$col} FROM biz_products WHERE id = {$P}")->fetchColumn();
T::ok($buy(120), 'خرید با فیِ ۱۲۰ (گزینه خاموش)');
T::same(100, $price('buy_price'), 'گزینه‌ی خاموش قیمتِ خرید را دست نمی‌زند');

// =================================================================
T::group('۳ — به‌روزرسانیِ قیمت پس از صدور');
$fresh($a);
T::ok(Biz::saveInvoicePrefs($a, ['update_buy_price' => '1', 'update_sell_price' => '1', 'warn_below_cost' => '1', 'show_profit' => '1', 'default_party' => (string)$cus])['ok'], 'چهار گزینه و مشتریِ پیش‌فرض ذخیره شد');
$fresh($a);
T::ok($buy(130), 'خرید با فیِ ۱۳۰');
T::same(130, $price('buy_price'), '⛔ «قیمتِ خرید»ِ کالا به فیِ فاکتور رسید');
T::ok($buy(0), 'خریدِ هدیه با فیِ صفر');
T::same(130, $price('buy_price'), '⛔ فیِ صفر قیمت را صفر نمی‌کند');
$bP = (int)BizProducts::save($b, ['name' => 'گلسِ ب', 'sku' => 'G1', 'unit' => 'عدد', 'buy_price' => '999'])['id'];
T::same(999, (int)$pdo->query("SELECT buy_price FROM biz_products WHERE id = {$bP}")->fetchColumn(), 'کالای هم‌کدِ فروشگاهِ دیگر دست نخورد');

// =================================================================
T::group('۴ — هشدارِ زیرِ بها و سودِ فاکتور');
// میانگینِ بها: (۱۰×۱۲۰ + ۱۰×۱۳۰ + ۱۰×۰) ÷ ۳۰ ≈ ۸۳ — خریدِ هدیه میانگین را پایین آورد
$r = BizInvoices::saveDraft($a, 'sale', ['party_id' => $cus, 'lines' => $lines([['G1', 2, 50]])]);
$sd = (int)$r['id'];
$w = BizInvoices::belowCost(BizInvoices::get($a, $sd));
T::ok(count($w) === 1 && $w[0]['per'] === 50 && $w[0]['cost'] === 83, 'پیش‌نویس: فروشِ ۵۰ زیرِ میانگینِ بها (۸۳) هشدار دارد', json_encode($w, JSON_UNESCAPED_UNICODE));
T::ok(BizInvoices::issue($a, $sd, ['account_id' => $cash, 'amount' => '0'])['ok'], 'صادر شد — هشدار صدور را نمی‌بندد');
$inv = BizInvoices::get($a, $sd);
T::same(1, count(BizInvoices::belowCost($inv)), 'صادرشده: با بهای ثبت‌شده‌ی ردیف هم هشدار دارد');
T::same(50, $price('sell_price'), '⛔ «قیمتِ فروش» هم به فیِ فاکتورِ فروش رسید (گزینه روشن)');
$pf = BizInvoices::profit($inv);
T::ok($pf !== null && $pf < 0, 'سودِ این فاکتور منفی است', (string)$pf);
$bi = BizReports::byInvoice($a, 'sale', ...BizReports::range('all'));
T::same($bi['profit'], $pf, '⛔ سودِ صفحه‌ی فاکتور همان سودِ گزارشِ «به تفکیکِ فاکتور»');
$r = BizInvoices::saveDraft($a, 'sale', ['party_id' => $cus, 'lines' => $lines([['G1', 1, 500]])]);
T::same([], BizInvoices::belowCost(BizInvoices::get($a, (int)$r['id'])), 'بالای بها هشداری ندارد');
T::same(null, BizInvoices::profit(BizInvoices::get($a, (int)$r['id'])), 'پیش‌نویس سود ندارد');

// =================================================================
T::group('۵ — کدهای خریدار');
$r = BizParties::save($a, ['name' => 'مشتری', 'kind' => 'customer', 'economic_code' => '۱۲۳']);
T::ok(!$r['ok'] && str_contains($r['message'], 'کد اقتصادی'), 'کدِ اقتصادیِ کوتاه رد می‌شود');
$r = BizParties::save($a, ['name' => 'مشتری', 'kind' => 'customer', 'economic_code' => '۴۲۲۲۲۲۲۲۲۲۲۲', 'national_id' => '0012345678', 'postal_code' => ''], $cus);
T::ok($r['ok'], 'کدهای خریدار ذخیره شد', $r['message']);
$pp = BizParties::get($a, $cus);
T::same(['422222222222', '0012345678', null], [$pp['economic_code'], $pp['national_id'], $pp['postal_code']], 'کدها با رقمِ لاتین؛ صفرِ اولِ کدِ ملی می‌ماند');
BizParties::save($a, ['name' => 'مشتری', 'kind' => 'customer', 'phone' => '0912'], $cus);
T::same('422222222222', BizParties::get($a, $cus)['economic_code'], '⛔ ذخیره‌ای که کدها را نفرستاده (ورود از فایل) کدِ ثبت‌شده را پاک نمی‌کند');
$invC = BizInvoices::get($a, $sd);
T::same('422222222222', $invC['party_economic_code'] ?? null, 'فاکتور کدِ اقتصادیِ خریدار را دارد');

// =================================================================
T::group('۶ — صفحه‌ها با HTTP');
$port = 0;
for ($pp2 = 9361; $pp2 <= 9410; $pp2++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$pp2", $e1, $e2);
    if ($sock) { fclose($sock); $port = $pp2; break; }
}
$log = tempnam(sys_get_temp_dir(), 'bset');
$srv = $port ? (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
    $port, escapeshellarg($root), escapeshellarg($log)))) : 0;
$up = false;
for ($i = 0; $srv && $i < 40; $i++) {
    usleep(150000);
    $sk = @fsockopen('127.0.0.1', $port, $x, $y, 0.3);
    if ($sk) { fclose($sk); $up = true; break; }
}
if (!$up) {
    T::blocked('تنظیماتِ فروشگاه (HTTP)', 'سرورِ آزمایشی بالا نیامد');
    if ($srv) { exec("kill $srv 2>/dev/null"); }
    $wipe();
    exit(T::report());
}
$jar = tempnam(sys_get_temp_dir(), 'bsetjar');
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
T::same(302, $req('store/login.php', ['csrf_token' => $csrfOf($lp), 'username' => SPREFIX . 'a', 'password' => SPASS])[0], 'ورود');

[, $pr1] = $req('store/print.php?doc=invoice&id=' . $sd);
T::ok(str_contains($pr1, '411111111111') && str_contains($pr1, '10101010101'), 'سربرگِ چاپ کدهای فروشگاه را دارد');
T::ok(str_contains($pr1, '422222222222') && str_contains($pr1, '0012345678'), 'چاپِ فاکتور کدهای خریدار را دارد');
T::ok(!str_contains($pr1, 'سودِ این فاکتور'), '⛔ سود روی چاپ نمی‌آید');
[, $iv] = $req('store/invoice.php?id=' . $sd);
T::ok(str_contains($iv, 'سودِ این فاکتور') && str_contains($iv, 'زیرِ بهای خرید'), 'صفحه‌ی فاکتور سود و هشدار را نشان می‌دهد');
[, $ed] = $req('store/invoice-edit.php?k=sale');
T::ok((bool)preg_match('/<option value="' . $cus . '"[^>]*selected/', $ed), 'فاکتورِ فروشِ تازه با مشتریِ پیش‌فرض باز می‌شود');
[, $edp] = $req('store/invoice-edit.php?k=purchase');
T::ok(!preg_match('/<option value="' . $cus . '"[^>]*selected/', $edp), 'فاکتورِ خرید مشتریِ پیش‌فرض نمی‌گیرد');

[, $st] = $req('store/settings.php');
T::ok(str_contains($st, 'name="economic_code"') && str_contains($st, 'name="update_buy_price"') && str_contains($st, 'name="default_party"'), 'صفحه‌ی تنظیمات هر دو بخش را دارد');
$req('store/settings.php', ['action' => 'invoice_prefs']);
$fresh($a);
T::same(true, Biz::invoicePrefs($a)['show_profit'], '⛔ بی‌CSRF هیچ گزینه‌ای عوض نمی‌شود');
$req('store/settings.php', ['csrf_token' => $csrfOf($st), 'action' => 'invoice_prefs']);
$fresh($a);
T::same(false, Biz::invoicePrefs($a)['show_profit'], 'تیکِ برداشته خاموش ذخیره می‌شود');
[, $iv2] = $req('store/invoice.php?id=' . $sd);
T::ok(!str_contains($iv2, 'سودِ این فاکتور') && !str_contains($iv2, 'زیرِ بهای خرید'), 'گزینه‌ی خاموش: نه سود، نه هشدار');
[, $pa] = $req('store/party.php?id=' . $cus);
T::ok(str_contains($pa, 'name="economic_code"') && str_contains($pa, '422222222222'), 'فرمِ طرف‌حساب کدها را نشان می‌دهد');

exec("kill $srv 2>/dev/null");
@unlink($log); @unlink($jar);
$wipe();
exit(T::report());
