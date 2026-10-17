<?php
/**
 * ⛔ خلاصه‌ی شبانه برای صاحبِ فروشگاه (`BizDaily`، `deploy/store-daily.php`).
 *
 *   ۱. عددها همان داشبوردند: فروش، سودِ `BizReports::profit()`، نقدِ صندوق، چک‌های تا فردا.
 *   ۲. روزِ خالی چیزی نمی‌فرستد؛ ساعت پیش از `HOUR` نه.
 *   ۳. یک بار در روز (`claim()`)؛ خاموش = هیچ؛ گیرنده صاحب است، همکار هرگز.
 *   ۴. تنظیم: فقط صاحب می‌بیند و ذخیره می‌کند؛ نصبِ cron و `CronHealth::JOBS`.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/lib/store_http.php';
T::group('خلاصه‌ی شبانه');
if (!file_exists(__DIR__ . '/../config/config.php')) { T::blocked('خلاصه', 'config/config.php وجود ندارد'); exit(T::report()); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/biz.php';
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_docs.php';
require_once __DIR__ . '/../includes/biz_staff.php';
require_once __DIR__ . '/../includes/biz_daily.php';
require_once __DIR__ . '/../includes/cron_health.php';

try { $pdo = Database::getConnection(); } catch (Throwable $e) { T::blocked('خلاصه', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }
if (!BizDaily::ready()) { T::blocked('خلاصه', 'migration_biz_daily اجرا نشده'); exit(T::report()); }
const SD_PREFIX = '__store_daily_';
const SD_PASS   = 'Daily#pass9';
$wipe = function () use ($pdo) {
    foreach ($pdo->query("SELECT id FROM users WHERE username LIKE '" . SD_PREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN) as $id) { deleteUserAccount((int)$id); }
};
$wipe();
register_shutdown_function($wipe);
$mk = function (string $n) use ($pdo): int {
    $r = createUserAccount($pdo, 'صاحب ' . $n, SD_PREFIX . $n, SD_PREFIX . $n . '@example.com', SD_PASS);
    Biz::setType((int)$r['id'], 'business');
    return (int)$r['id'];
};
$one = function (string $sql, array $p = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn(); };
$mails = [];
BizDaily::$mailForTests = function (string $to, string $subject, string $text) use (&$mails) { $mails[] = [$to, $subject, $text]; return true; };

T::group('۱ — عددها');
$u = $mk('a');
Biz::saveSettings($u, ['shop_name' => 'موبایل نمونه', 'phone' => '', 'address' => '', 'invoice_footer' => '']);
$acc = (int)BizCash::list($u)[0]['id'];
$today = today();
$d0 = BizDaily::collect($u, $today);
T::ok(!BizDaily::worth($d0), '⛔ روزِ خالی چیزی برای گفتن ندارد');
$r0 = BizDaily::run(true, $u);
T::same([0, 1], [$r0['sent'], $r0['empty']], 'روزِ خالی فرستاده نمی‌شود');
T::same(null, $one('SELECT daily_sent_on FROM biz_settings WHERE user_id = :u', ['u' => $u]), 'روزِ خالی «فرستاده» علامت نمی‌خورد');

$p = BizProducts::save($u, ['name' => 'قاب', 'unit' => 'عدد', 'sell_price' => '1000']);
$cust = (int)BizParties::save($u, ['name' => 'مشتری', 'kind' => 'customer'])['id'];
$sup  = (int)BizParties::save($u, ['name' => 'پخش', 'kind' => 'supplier'])['id'];
$b = BizInvoices::saveDraft($u, 'purchase', ['party_id' => $sup, 'lines' => [['product_id' => (int)$p['id'], 'item' => 'قاب', 'qty' => '10', 'price' => '600']]]);
BizInvoices::issue($u, (int)$b['id'], ['full' => true, 'account_id' => $acc]);
BizPay::create($u, ['kind' => 'capital', 'amount' => '20000', 'account_id' => $acc]);
foreach ([2, 3] as $q) {
    $s = BizInvoices::saveDraft($u, 'sale', ['party_id' => $cust, 'lines' => [['product_id' => (int)$p['id'], 'item' => 'قاب', 'qty' => (string)$q, 'price' => '1000']]]);
    BizInvoices::issue($u, (int)$s['id'], ['full' => true, 'account_id' => $acc]);
}
BizPay::create($u, ['kind' => 'expense', 'account_id' => $acc, 'amount' => '300', 'title' => 'قبض']);
BizPay::create($u, ['kind' => 'receipt', 'party_id' => $cust, 'account_id' => $acc, 'amount' => '700', 'method' => 'cheque',
                    'cheque_no' => '4455', 'cheque_due' => date('Y-m-d', strtotime('+1 day'))]);
BizPay::create($u, ['kind' => 'receipt', 'party_id' => $cust, 'account_id' => $acc, 'amount' => '900', 'method' => 'cheque',
                    'cheque_no' => '9999', 'cheque_due' => date('Y-m-d', strtotime('+5 day'))]);
$d = BizDaily::collect($u, $today);
T::same(5000, $d['sales'], 'فروشِ امروز (بی‌مالیات)');
T::same(2, $d['invoices'], 'شمارِ فاکتورهای فروش');
T::same(5000 - 5 * 600 - 300, $d['profit'], 'سودِ خالص = فروش − بها − هزینه (همان `profit()`)');
T::same(BizCash::total(BizCash::list($u, false, $today)), $d['cash'], 'نقد = جمعِ نقدِ صندوق‌ها (بی‌چک)');
T::same(['4455'], array_column($d['cheques'], 'cheque_no'), '⛔ فقط چکِ تا فردا، نه پنج روزِ دیگر');
T::ok(BizDaily::worth($d), 'روزِ پرکار ارزشِ گفتن دارد');
$t = BizDaily::text($d, 'موبایل نمونه');
$all = $t['title'] . "\n" . implode("\n", $t['lines']);
T::ok(str_contains($all, 'موبایل نمونه') && str_contains($all, formatMoney(5000)) && str_contains($all, formatMoney(1700)), 'متن: نامِ فروشگاه، فروش، سود', $all);
T::ok(str_contains($all, 'چکِ دریافتی · فردا'), 'متن: چکِ فردا', $all);
T::ok(str_contains($all, '۲ فاکتور'), 'متن: ارقامِ فارسی', $all);

T::group('۲ — ساعت');
T::ok(!BizDaily::timeHasCome(BizDaily::HOUR - 1) && !BizDaily::timeHasCome(3), '⛔ پیش از ساعتِ شب نه (و نه بامدادِ روزِ بعد)');
T::ok(BizDaily::timeHasCome(BizDaily::HOUR) && BizDaily::timeHasCome(23), 'از ساعتِ شب به بعد');

T::group('۳ — یک بار، فقط صاحب');
$staff = $mk('staff');
$pdo->prepare('INSERT INTO store_staff (user_id, staff_id, role) VALUES (:o, :s, :r)')->execute(['o' => $u, 's' => $staff, 'r' => 'seller']);
$pdo->prepare('INSERT INTO biz_settings (user_id, shop_name) VALUES (:u, :n) ON DUPLICATE KEY UPDATE shop_name = VALUES(shop_name)')->execute(['u' => $staff, 'n' => 'نباید']);
T::ok(!in_array($staff, array_map('intval', array_column(BizDaily::shops(), 'id')), true), '⛔ همکار هرگز فروشگاهِ خلاصه نیست');
$mails = [];
$r = BizDaily::run(false, $u);
T::same(1, $r['sent'], 'نمایشی: می‌رفت');
T::same([], $mails, 'نمایشی چیزی نمی‌فرستد');
T::same(null, $one('SELECT daily_sent_on FROM biz_settings WHERE user_id = :u', ['u' => $u]), 'نمایشی علامت نمی‌زند');
$r = BizDaily::run(true, $u);
T::same(1, $r['sent'], 'فرستاده شد');
T::same([SD_PREFIX . 'a@example.com'], array_column($mails, 0), 'ایمیل به صاحب');
T::same(1, (int)$one("SELECT COUNT(*) FROM notifications WHERE user_id = :u AND kind = 'store_daily'", ['u' => $u]), 'اعلان برای صاحب');
T::same(0, (int)$one("SELECT COUNT(*) FROM notifications WHERE user_id = :u AND kind = 'store_daily'", ['u' => $staff]), '⛔ اعلانِ سود به همکار نه');
$r = BizDaily::run(true, $u);
T::same([0, 1], [$r['sent'], $r['skipped']], '⛔ دومین اجرای همان روز نمی‌فرستد');
T::same(1, count($mails), 'هنوز یک ایمیل');
// فردا دوباره
$pdo->prepare('UPDATE biz_settings SET daily_sent_on = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE user_id = :u')->execute(['u' => $u]);
T::same(1, BizDaily::run(true, $u)['sent'], 'روزِ بعد دوباره');

T::group('۴ — خاموش');
BizDaily::setOn($u, false);
Biz::forgetSettings($u);
T::same(false, Biz::dailySummaryOn($u), 'خاموش خوانده می‌شود');
$pdo->prepare('UPDATE biz_settings SET daily_sent_on = NULL WHERE user_id = :u')->execute(['u' => $u]);
T::ok(!in_array($u, array_map('intval', array_column(BizDaily::shops(), 'id')), true), 'خاموش در فهرست نیست');
T::ok(!BizDaily::claim($u, $today), '⛔ خاموش ادعا نمی‌شود');
BizDaily::setOn($u, true);

T::group('۵ — فروشگاهِ دوم به صاحبِ واقعی');
if (tableExists('store_shops')) {
    $shop2 = $mk('shop2');
    Biz::saveSettings($shop2, ['shop_name' => 'شعبه دو', 'phone' => '', 'address' => '', 'invoice_footer' => '']);
    $pdo->prepare('INSERT INTO store_shops (user_id, shop_id) VALUES (:u, :s)')->execute(['u' => $u, 's' => $shop2]);
    BizPay::create($shop2, ['kind' => 'capital', 'amount' => '5000', 'account_id' => (int)BizCash::list($shop2)[0]['id']]);
    $mails = [];
    T::same(1, BizDaily::run(true, $shop2)['sent'], 'شعبه‌ی دوم');
    T::same([SD_PREFIX . 'a@example.com'], array_column($mails, 0), '⛔ ایمیلِ شعبه به صاحبِ واقعی، نه حسابِ داده');
}

T::group('۶ — تنظیمات و cron');
$port = stHttpServer();
T::ok($port > 0, 'سرورِ آزمایشی بالا آمد');
if ($port) {
    $c = stHttpClient($port);
    stHttpLogin($c, SD_PREFIX . 'a', SD_PASS);
    [, $h] = $c('store/settings.php');
    T::ok(str_contains($h, 'id="daily"') && str_contains($h, 'name="daily_on"'), 'بخشِ خلاصه‌ی شبانه در تنظیمات');
    $c('store/settings.php', ['csrf_token' => stCsrf($h), 'action' => 'daily_summary']);
    T::same(0, (int)$one('SELECT daily_summary FROM biz_settings WHERE user_id = :u', ['u' => $u]), 'خاموش کردن از فرم');
    [, $h] = $c('store/settings.php');
    $c('store/settings.php', ['csrf_token' => stCsrf($h), 'action' => 'daily_summary', 'daily_on' => '1']);
    T::same(1, (int)$one('SELECT daily_summary FROM biz_settings WHERE user_id = :u', ['u' => $u]), 'روشن کردن از فرم');
    $c('store/settings.php', ['csrf_token' => 'x', 'action' => 'daily_summary']);
    T::same(1, (int)$one('SELECT daily_summary FROM biz_settings WHERE user_id = :u', ['u' => $u]), '⛔ بی‌CSRF دست نمی‌خورد');
    // همکارِ با نقشِ «صاحب» (دارای تنظیمات) نه می‌بیند نه ذخیره می‌کند
    $pdo->prepare("UPDATE store_staff SET role = 'owner' WHERE staff_id = :s")->execute(['s' => $staff]);
    $x = stHttpClient($port);
    stHttpLogin($x, SD_PREFIX . 'staff', SD_PASS);
    [$code, $h] = $x('store/settings.php');
    T::ok(!str_contains($h, 'name="daily_on"'), '⛔ همکار بخشِ خلاصه را نمی‌بیند', (string)$code);
    $x('store/settings.php', ['csrf_token' => stCsrf($h), 'action' => 'daily_summary']);
    T::same(1, (int)$one('SELECT daily_summary FROM biz_settings WHERE user_id = :u', ['u' => $u]), '⛔ همکار خاموشش نمی‌کند');
}

$src = file_get_contents(__DIR__ . '/../deploy/store-daily.php');
T::ok(isset(CronHealth::JOBS['store-daily']) && CronHealth::JOBS['store-daily'][2] === 'deploy/store-daily.php', 'در `CronHealth::JOBS`');
T::ok(str_contains($src, "PHP_SAPI !== 'cli'") && str_contains($src, '/etc/cron.d/hesab-store-daily'), 'فقط خطِ فرمان؛ cron فقط hesab-*');
T::ok(str_contains($src, "CronHealth::beat('store-daily')"), 'نشانه‌ی سلامت');

exit(T::report());
