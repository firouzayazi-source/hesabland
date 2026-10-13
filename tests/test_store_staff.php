<?php
/**
 * تستِ «همکارانِ فروشگاه» — چند کاربر، نقش و دسترسی (`Biz::can()`، `BizStaff`).
 *
 * **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵):** فروشِ کشوری؛ فروشگاهی که فروشنده و حسابدار دارد
 * نباید رمزِ صاحب را به همه بدهد.
 *
 * ⛔ با HTTPِ واقعی و نشست‌های جدا: همکار دفترِ **صاحب** را می‌بیند (نه فروشگاهِ خالیِ
 *    خودش)، صفحه و کارِ بسته ۴۰۳ می‌گیرد (حتی با تغییرِ `?k=` در آدرس)، منو قلمِ بسته
 *    را نشان نمی‌دهد، فروشنده بهای خرید و سود را نمی‌بیند، سند نامِ ثبت‌کننده را دارد،
 *    غیرفعال/تغییرِ نقش همان لحظه اثر دارد، و حذفِ صاحب حسابِ همکاران را هم می‌برد.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
T::group('همکارانِ فروشگاه');

if (!file_exists(__DIR__ . '/../config/config.php')) { T::blocked('همکاران', 'config/config.php وجود ندارد'); exit(T::report()); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/biz.php';
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_docs.php';
require_once __DIR__ . '/../includes/biz_staff.php';

try { $pdo = Database::getConnection(); } catch (Throwable $e) { T::blocked('همکاران', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }
if (!Biz::staffReady() || !tableExists('store_staff')) { T::blocked('همکاران', 'migration_biz_staff اجرا نشده'); exit(T::report()); }

const SF_PREFIX = '__store_staff_';
const SF_PASS   = 'Staff#pass9';
$wipe = function () use ($pdo) {
    $ids = $pdo->query("SELECT id FROM users WHERE username LIKE '" . SF_PREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) { deleteUserAccount((int)$id); }
};
$wipe();
register_shutdown_function($wipe);
$one = function (string $sql, array $p = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn(); };

// ---- ۱. ثابت‌ها ----
T::same(['owner', 'seller', 'manager', 'accountant'], array_keys(Biz::STAFF_ROLES), 'چهار نقش');
$unknownCaps = [];
foreach (Biz::STAFF_ROLES as $rk => $r) { foreach ($r['caps'] as $c) { if ($c !== '*' && !isset(Biz::CAPS[$c])) { $unknownCaps[] = "{$rk}:{$c}"; } } }
T::same([], $unknownCaps, 'هر دسترسیِ هر نقش در CAPS تعریف شده');
T::same('settings', Biz::pageCap('صفحه-ی-فردا.php'), '⛔ صفحه‌ی ناشناخته بسته است (settings)، نه باز');
$disk = array_map('basename', glob(__DIR__ . '/../store/*.php') ?: []);
T::same([], array_values(array_diff($disk, array_keys(Biz::PAGE_CAPS))), 'هر صفحه‌ی store/ در PAGE_CAPS آمده');
T::same('buy', BizRights::docCap('purchase'), 'فاکتورِ خرید = دسترسیِ خرید');
T::same('buy', BizRights::docCap('purchase_return'), 'برگشت از خرید = خرید');
T::same('sell', BizRights::docCap('sale_return'), 'برگشت از فروش = فروش');
T::same('receive', BizRights::payCap('receipt'), 'دریافت = «دریافت از مشتری»');
T::same('money', BizRights::payCap('payment'), 'پرداخت = پول');

// ---- ۲. فروشگاه و همکار ----
$mk = function (string $n) use ($pdo): int {
    $r = createUserAccount($pdo, 'صاحب ' . $n, SF_PREFIX . $n, SF_PREFIX . $n . '@example.com', SF_PASS);
    $id = (int)$r['id'];
    Biz::setType($id, 'business');
    return $id;
};
$owner = $mk('own');
$other = $mk('oth');
$pidOwn = (int)BizProducts::save($owner, ['name' => 'گوشیِ فروشگاه', 'unit' => 'عدد', 'buy_price' => '7000000', 'sell_price' => '9000000'])['id'];
BizProducts::save($other, ['name' => 'کالای فروشگاهِ دیگر', 'unit' => 'عدد', 'sell_price' => '10']);

$r = BizStaff::add($owner, ['full_name' => 'علی فروشنده', 'username' => SF_PREFIX . 'sel', 'password' => SF_PASS, 'role' => 'seller', 'commission_pct' => '۲٫۵']);
T::ok($r['ok'], 'فروشنده اضافه شد', $r['message']);
$selRow = (int)$r['id'];
$selId  = (int)$one('SELECT staff_id FROM store_staff WHERE id = :i', ['i' => $selRow]);
T::same('business', (string)$one('SELECT account_type FROM users WHERE id = :i', ['i' => $selId]), 'حسابِ همکار «فقط فروشگاه» است');
T::same('2.50', (string)$one('SELECT commission_pct FROM store_staff WHERE id = :i', ['i' => $selRow]), 'پورسانت با رقمِ فارسی و «٫»');
T::same(0, (int)$one('SELECT COUNT(*) FROM biz_settings WHERE user_id = :i', ['i' => $selId]), '⛔ همکار فروشگاهِ دومی (biz_settings) نگرفت');
T::ok(!BizStaff::add($owner, ['full_name' => 'x', 'username' => SF_PREFIX . 'sel', 'password' => SF_PASS, 'role' => 'seller'])['ok'], 'نامِ کاربریِ تکراری رد');
T::ok(!BizStaff::add($owner, ['full_name' => 'x', 'username' => SF_PREFIX . 'x1', 'password' => SF_PASS, 'role' => 'owner'])['ok'], '⛔ نقشِ «صاحب» داده نمی‌شود');
T::ok(!BizStaff::add($owner, ['full_name' => 'x', 'username' => SF_PREFIX . 'x2', 'password' => SF_PASS, 'role' => 'seller', 'commission_pct' => '90'])['ok'], 'پورسانتِ بیش از سقف رد');
T::same(null, BizStaff::get($other, $selRow), '⛔ فروشگاهِ دیگر همکارِ این فروشگاه را نمی‌بیند');
T::ok(!BizStaff::update($other, $selRow, ['role' => 'manager', 'is_active' => true])['ok'], '⛔ و نمی‌تواند نقشش را عوض کند');
$r = BizStaff::add($owner, ['full_name' => 'مدیر', 'username' => SF_PREFIX . 'man', 'password' => SF_PASS, 'role' => 'manager']);
$manRow = (int)$r['id'];

// ---- ۳. HTTP ----
$root = dirname(__DIR__);
$port = 0;
for ($p = 9460; $p <= 9500; $p++) { $s = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2); if ($s) { fclose($s); $port = $p; break; } }
if (!$port) { T::blocked('همکاران (HTTP)', 'پورت آزاد پیدا نشد'); exit(T::report()); }
$log = tempnam(sys_get_temp_dir(), 'sflog');
$srv = (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!', $port, escapeshellarg($root), escapeshellarg($log))));
register_shutdown_function(static function () use ($srv, $log) { exec("kill {$srv} 2>/dev/null"); @unlink($log); });
for ($i = 0, $up = false; $i < 40 && !$up; $i++) { usleep(150000); $sk = @fsockopen('127.0.0.1', $port, $a, $b, 0.3); if ($sk) { fclose($sk); $up = true; } }
T::ok($up, 'سرورِ آزمایشی بالا آمد');
if (!$up) { exit(T::report()); }

$client = function () use ($port) {
    $jar = tempnam(sys_get_temp_dir(), 'sfjar');
    return function (string $path, ?array $post = null) use ($port, $jar): array {
        $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 40]);
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $body = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); unset($ch);
        return [$code, $body];
    };
};
$login = function (callable $req, string $user) {
    [, $lp] = $req('store/login.php');
    $tok = preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $lp, $m) ? $m[1] : '';
    $req('store/login.php', ['csrf_token' => $tok, 'username' => $user, 'password' => SF_PASS]);
};
$tokenOf = fn(string $html): string => preg_match('/name="csrf-token" content="([^"]+)"/', $html, $m) ? $m[1] : '';

$sel = $client(); $login($sel, SF_PREFIX . 'sel');
[$c, $html] = $sel('store/index.php');
T::ok($c === 200 && str_contains($html, '</html>'), 'فروشنده وارد داشبورد شد', (string)$c);
T::ok(str_contains($html, 'فروشِ امروزِ من') && !str_contains($html, 'سودِ خالصِ این ماه'), '⛔ داشبوردِ فروشنده بی‌سود و صندوق');
T::ok(!str_contains($html, Biz::url('purchases.php') . '"') && !str_contains($html, Biz::url('staff.php')), '⛔ منوی فروشنده «خرید» و «همکاران» ندارد');
T::ok(!str_contains($html, 'invoice-edit.php?k=purchase'), 'منوی «+» فروشنده فاکتورِ خرید ندارد');
[$c, $html] = $sel('store/products.php');
T::ok($c === 200 && str_contains($html, 'گوشیِ فروشگاه'), '⛔ فروشنده کالای **صاحب** را می‌بیند (نه فروشگاهِ خالی)');
T::ok(!str_contains($html, 'کالای فروشگاهِ دیگر'), 'و نه کالای فروشگاهِ دیگر');
T::ok(!str_contains($html, 'ارزشِ انبار'), 'ستونِ «ارزشِ انبار» (بهای خرید) برای فروشنده نیست');
[$c, $html] = $sel('store/product.php?id=' . $pidOwn);
T::ok($c === 200 && !str_contains($html, formatMoney(7000000)) && str_contains($html, formatMoney(9000000)), '⛔ کالا: قیمتِ فروش آری، بهای خرید نه');
T::ok(!str_contains($html, 'name="buy_price"'), 'فرمِ ویرایشِ کالا برای فروشنده نیست');
$stok = $tokenOf($html);
[$c] = $sel('store/product.php?id=' . $pidOwn, ['csrf_token' => $stok, 'action' => 'save', 'name' => 'دست‌کاری', 'sell_price' => '1']);
T::same(403, $c, '⛔ فروشنده کالا را ویرایش نمی‌کند');
T::same('گوشیِ فروشگاه', (string)$one('SELECT name FROM biz_products WHERE id = :i', ['i' => $pidOwn]), 'نامِ کالا دست نخورد');
foreach (['purchases.php', 'reports.php', 'settings.php', 'staff.php', 'accounting.php', 'payments.php', 'invoice-edit.php?k=purchase', 'payment.php?k=payment', 'payment.php?k=expense', 'product.php', 'import.php'] as $pg) {
    [$c] = $sel('store/' . $pg);
    T::same(403, $c, "⛔ فروشنده: {$pg} بسته است");
}
foreach (['print.php?doc=sales', 'print.php?doc=stock', 'print.php?doc=account'] as $pg) {
    [$c] = $sel('store/' . $pg);
    T::same(403, $c, "⛔ فروشنده: برگه‌ی چاپیِ {$pg} بسته است");
}
foreach (['print.php?doc=prices', 'quick-sale.php', 'sales.php', 'invoice-edit.php?k=sale', 'payment.php?k=receipt', 'parties.php', 'account.php'] as $pg) {
    [$c, $h] = $sel('store/' . $pg);
    T::ok($c === 200 && str_contains($h, '</html>'), "فروشنده: {$pg} باز است", (string)$c);
}
[, $h] = $sel('store/account.php');
T::ok(!str_contains($h, 'دانلودِ بکاپ'), '⛔ بکاپِ کلِ دفتر برای همکار نیست');

// سند نامِ ثبت‌کننده را دارد
$_SESSION = ['user_id' => $selId];
$r = BizInvoices::saveDraft($owner, 'sale', ['lines' => [['item' => 'خدمتِ نصب', 'qty' => '1', 'price' => '500000']]]);
T::ok($r['ok'], 'پیش‌نویسِ فروش', $r['message'] ?? '');
T::same($selId, (int)$one('SELECT created_by FROM biz_invoices WHERE id = :i', ['i' => (int)$r['id']]), '⛔ created_by = همکاری که ثبت کرد');
T::same($owner, (int)$one('SELECT user_id FROM biz_invoices WHERE id = :i', ['i' => (int)$r['id']]), '⛔ داده زیرِ صاحبِ فروشگاه');
$_SESSION = [];

// مدیر
$man = $client(); $login($man, SF_PREFIX . 'man');
[$c] = $man('store/purchases.php');
T::same(200, $c, 'مدیرِ فروشگاه خرید را می‌بیند');
[$c] = $man('store/staff.php');
T::same(403, $c, '⛔ ولی «همکاران» فقط مالِ صاحب است');
[$c] = $man('store/settings.php');
T::same(403, $c, 'و «تنظیمات» هم');

// صاحب
$own = $client(); $login($own, SF_PREFIX . 'own');
[$c, $html] = $own('store/staff.php');
T::ok($c === 200 && str_contains($html, 'علی فروشنده'), 'صاحب فهرستِ همکاران را می‌بیند');
$otok = $tokenOf($html);
[$c] = $own('store/staff.php', ['csrf_token' => $otok, 'action' => 'add', 'full_name' => 'حسابدار', 'username' => SF_PREFIX . 'acc',
                                'password' => SF_PASS, 'role' => 'accountant', 'commission_pct' => '']);
T::same(1, (int)$one("SELECT COUNT(*) FROM store_staff s JOIN users u ON u.id = s.staff_id WHERE u.username = :n AND s.user_id = :o",
    ['n' => SF_PREFIX . 'acc', 'o' => $owner]), 'افزودنِ همکار از صفحه');

// غیرفعال ⇒ همان لحظه بیرون
$pdo->prepare('UPDATE users SET access_revoked_at = NULL WHERE id = :i')->execute(['i' => $selId]);
BizStaff::update($owner, $selRow, ['role' => 'seller', 'commission_pct' => '3', 'is_active' => true]);
T::same(null, $one('SELECT access_revoked_at FROM users WHERE id = :i', ['i' => $selId]), 'تغییرِ فقط پورسانت کسی را بیرون نمی‌اندازد');
BizStaff::update($owner, $selRow, ['role' => 'seller', 'commission_pct' => '2.5', 'is_active' => false]);
T::ok($one('SELECT access_revoked_at FROM users WHERE id = :i', ['i' => $selId]) !== null, '⛔ غیرفعال کردن همه‌ی دسترسی‌های همکار را باطل کرد (دستگاه و اپ)');
[$c] = $sel('store/index.php');
T::ok($c === 404 || $c === 302, '⛔ همکارِ غیرفعال دیگر به فروشگاه نمی‌رسد', (string)$c);
BizStaff::update($owner, $selRow, ['role' => 'manager', 'commission_pct' => '0', 'is_active' => true]);
$sel2 = $client(); $login($sel2, SF_PREFIX . 'sel');
[$c] = $sel2('store/purchases.php');
T::same(200, $c, 'بعد از تغییرِ نقش به مدیر، خرید باز است');

// حذفِ همکار
$r = BizStaff::remove($owner, $manRow);
T::ok($r['ok'], 'برداشتنِ مدیر', $r['message']);
T::same(0, (int)$one('SELECT COUNT(*) FROM users WHERE username = :n', ['n' => SF_PREFIX . 'man']), 'حسابِ ورودش پاک شد');
[$c] = $man('store/index.php');
T::ok($c !== 200, 'و نشستِ بازش دیگر کار نمی‌کند', (string)$c);

// حذفِ صاحب ⇒ همکاران هم
$staffIds = array_map('intval', $pdo->query("SELECT staff_id FROM store_staff WHERE user_id = {$owner}")->fetchAll(PDO::FETCH_COLUMN));
T::ok(count($staffIds) >= 2, 'پیش از حذف دست‌کم دو همکار');
$res = deleteUserAccount($owner);
T::ok($res['ok'], 'حذفِ صاحبِ فروشگاه', (string)($res['reason'] ?? ''));
$left = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE id IN (' . implode(',', $staffIds) . ')')->fetchColumn();
T::same(0, $left, '⛔ حسابِ همکاران با صاحب رفت (همکارِ یتیم نماند)');
T::same(0, (int)$one('SELECT COUNT(*) FROM store_staff WHERE user_id = :o', ['o' => $owner]), 'و ردیف‌های store_staff');

exit(T::report());
