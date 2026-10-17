<?php
/**
 * ⛔ «شروعِ کار» (`BizSetup`، `store/start.php`، migration_biz_setup).
 *
 *   ۱. پیشرفت از خودِ داده: صندوقِ خودکار قدمِ «صندوق» را انجام نمی‌دهد، کارت‌خوان یا موجودیِ اولیه می‌دهد؛
 *      سربرگ تلفن هم می‌خواهد؛ کالا، طرف‌حساب، فقط فروشِ **صادرشده**.
 *   ۲. سربرگ از این صفحه بقیه‌ی تنظیماتِ موجود (پای فاکتور، کدِ اقتصادی) را پاک نمی‌کند.
 *   ۳. HTTP: کارت روی داشبورد با قدمِ بعد؛ «دیگر نشان نده» برش می‌دارد؛ همکار صفحه را نمی‌بیند.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/lib/store_http.php';
T::group('شروعِ کار');
if (!file_exists(__DIR__ . '/../config/config.php')) { T::blocked('شروعِ کار', 'config/config.php وجود ندارد'); exit(T::report()); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/biz.php';
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_docs.php';
require_once __DIR__ . '/../includes/biz_staff.php';
require_once __DIR__ . '/../includes/biz_setup.php';

try { $pdo = Database::getConnection(); } catch (Throwable $e) { T::blocked('شروعِ کار', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }
if (!BizSetup::ready()) { T::blocked('شروعِ کار', 'migration_biz_setup اجرا نشده'); exit(T::report()); }

const SU_PREFIX = '__store_setup_';
const SU_PASS   = 'Setup#pass9';
$wipe = function () use ($pdo) {
    foreach ($pdo->query("SELECT id FROM users WHERE username LIKE '" . SU_PREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN) as $id) { deleteUserAccount((int)$id); }
};
$wipe();
register_shutdown_function($wipe);
$r = createUserAccount($pdo, 'صاحب', SU_PREFIX . 'own', SU_PREFIX . 'own@example.com', SU_PASS);
$u = (int)$r['id'];
Biz::setType($u, 'business');
$fresh = function () use ($u) { Biz::forgetSettings($u); return BizSetup::status($u); };

T::group('۱ — پیشرفت از خودِ داده');
BizCash::list($u);   // صندوقِ خودکار
$s = $fresh();
T::same(['info' => false, 'accounts' => false, 'products' => false, 'parties' => false, 'sale' => false], $s, 'فروشگاهِ تازه: هیچ قدمی (صندوقِ خودکار حساب نیست)');
T::same('info', BizSetup::next($s), 'قدمِ بعد: سربرگ');

Biz::saveSettings($u, ['shop_name' => 'موبایلِ آزمایش', 'invoice_footer' => 'پای فاکتورِ قبلی', 'economic_code' => '411111111111']);
T::ok(!$fresh()['info'], '⛔ نام بی‌تلفن: سربرگ هنوز کامل نیست');
$res = BizSetup::saveInfo($u, ['shop_name' => 'موبایلِ آزمایش', 'phone' => '']);
T::ok(!$res['ok'], 'saveInfo بی‌تلفن رد');
$res = BizSetup::saveInfo($u, ['shop_name' => 'موبایلِ آزمایش', 'phone' => '02112345678', 'address' => 'خیابانِ یک']);
T::ok($res['ok'] && $fresh()['info'], 'سربرگ با تلفن: انجام شد');
Biz::forgetSettings($u);
$set = Biz::settings($u);
T::same(['پای فاکتورِ قبلی', '411111111111'], [$set['invoice_footer'], $set['economic_code']], '⛔ سربرگ از «شروعِ کار» پای فاکتور و کدِ اقتصادی را پاک نکرد');

BizCash::save($u, ['name' => 'صندوقِ دوم', 'kind' => 'cash', 'opening_balance' => '0']);
T::ok(!$fresh()['accounts'], 'صندوقِ نقدیِ صفر: هنوز نه');
BizCash::save($u, ['name' => 'کارت‌خوانِ ملت', 'kind' => 'pos', 'opening_balance' => '']);
T::ok($fresh()['accounts'], 'کارت‌خوان: انجام شد');

$p = BizProducts::save($u, ['name' => 'قابِ گوشی', 'unit' => 'عدد', 'sell_price' => '100', 'opening_qty' => '5']);
T::ok($fresh()['products'], 'کالا: انجام شد');
$pt = BizParties::save($u, ['name' => 'مشتریِ اول', 'kind' => 'customer']);
T::ok($fresh()['parties'], 'طرف‌حساب: انجام شد');
$d = BizInvoices::saveDraft($u, 'sale', ['party_id' => (int)$pt['id'], 'lines' => [['product_id' => (int)$p['id'], 'item' => 'قابِ گوشی', 'qty' => '1', 'price' => '100']]]);
T::ok(!$fresh()['sale'], '⛔ پیش‌نویس فروش حساب نمی‌شود');
$iss = BizInvoices::issue($u, (int)$d['id']);
T::ok(($iss['ok'] ?? false) && $fresh()['sale'], 'فاکتورِ صادرشده: انجام شد', json_encode($iss, JSON_UNESCAPED_UNICODE));
T::same(null, BizSetup::next($fresh()), 'همه انجام شد');
T::same(5, BizSetup::doneCount($fresh()), 'شمار: ۵');

T::group('۲ — صاحبِ دیگری');
$r2 = createUserAccount($pdo, 'دیگری', SU_PREFIX . 'oth', SU_PREFIX . 'oth@example.com', SU_PASS);
$o2 = (int)$r2['id'];
Biz::setType($o2, 'business');
T::same(0, BizSetup::doneCount(BizSetup::status($o2)), '⛔ داده‌ی فروشگاهِ دیگر در پیشرفتِ این یکی شمرده نمی‌شود');

T::group('۳ — HTTP');
$port = stHttpServer();
T::ok($port > 0, 'سرورِ آزمایشی بالا آمد');
if (!$port) { exit(T::report()); }
$o = stHttpClient($port);
stHttpLogin($o, SU_PREFIX . 'oth', SU_PASS);
[$c, $h] = $o('store/index.php');
T::ok($c === 200 && str_contains($h, 'data-setup') && str_contains($h, 'ادامه‌ی راه‌اندازی') && str_contains($h, 'start.php#info'), 'داشبوردِ فروشگاهِ تازه: کارتِ «شروعِ کار» با قدمِ بعد (سربرگ)');
[$c, $h] = $o('store/start.php');
T::ok($c === 200 && str_contains($h, '</html>') && str_contains($h, '۰ از ۵ قدم'), 'صفحه‌ی «شروعِ کار» رندر شد: ۰ از ۵');
$o('store/start.php', ['csrf_token' => stCsrf($h), 'action' => 'info', 'shop_name' => 'فروشگاهِ دوم', 'phone' => '09120000000', 'address' => '']);
$o('store/start.php', ['csrf_token' => stCsrf($h), 'action' => 'account', 'name' => 'حسابِ ملی', 'kind' => 'bank', 'opening_balance' => '۱٬۲۰۰']);
Biz::forgetSettings($o2);
$s2 = BizSetup::status($o2);
T::ok($s2['info'] && $s2['accounts'], 'سربرگ و حساب از همین صفحه ذخیره شدند');
T::same(1200, (int)$pdo->query("SELECT opening_balance FROM biz_accounts WHERE user_id = {$o2} AND kind = 'bank'")->fetchColumn(), 'موجودی با ارقامِ فارسی و جداکننده خوانده شد');
[, $h] = $o('store/index.php');
T::ok(str_contains($h, 'start.php#products'), 'داشبورد: قدمِ بعد حالا «کالاها»');
[, $h] = $o('store/start.php');
$o('store/start.php', ['csrf_token' => stCsrf($h), 'action' => 'hide']);
[, $h] = $o('store/index.php');
T::ok(!str_contains($h, 'data-setup'), '«دیگر نشان نده»: کارت از داشبورد رفت');
[, $h] = $o('store/start.php');
T::ok(str_contains($h, 'دوباره روی داشبورد نشان بده'), 'و از همان صفحه برمی‌گردد');
$o('store/start.php', ['csrf_token' => stCsrf($h), 'action' => 'show']);
[, $h] = $o('store/index.php');
T::ok(str_contains($h, 'data-setup'), 'نشان دادنِ دوباره');

// فروشگاهِ کامل‌شده کارت ندارد
$a = stHttpClient($port);
stHttpLogin($a, SU_PREFIX . 'own', SU_PASS);
[, $h] = $a('store/index.php');
T::ok(!str_contains($h, 'data-setup'), 'همه‌ی قدم‌ها انجام: کارتی روی داشبورد نیست');

// همکار
$sel = BizStaff::add($o2, ['full_name' => 'فروشنده', 'username' => SU_PREFIX . 'sel', 'password' => SU_PASS, 'role' => 'seller']);
$sr = stHttpClient($port);
stHttpLogin($sr, SU_PREFIX . 'sel', SU_PASS);
[$c] = $sr('store/start.php');
T::ok($c !== 200, '⛔ فروشنده صفحه‌ی «شروعِ کار» را نمی‌بیند (کد ' . $c . ')');
[, $h] = $sr('store/index.php');
T::ok(!str_contains($h, 'data-setup'), 'و کارتش روی داشبوردِ او نیست');
// ⛔ مدیرِ فروشگاه داشبوردِ کامل را می‌بیند ولی «تنظیمات» ندارد — کارت هم نه
BizStaff::add($o2, ['full_name' => 'مدیر', 'username' => SU_PREFIX . 'mgr', 'password' => SU_PASS, 'role' => 'manager']);
$mg = stHttpClient($port);
stHttpLogin($mg, SU_PREFIX . 'mgr', SU_PASS);
[$c, $h] = $mg('store/index.php');
T::ok($c === 200 && str_contains($h, 'st-kpi-card') && !str_contains($h, 'data-setup'), '⛔ مدیرِ فروشگاه (بی‌«تنظیمات»): داشبوردِ کامل، بی‌کارتِ «شروعِ کار»');

exit(T::report());
