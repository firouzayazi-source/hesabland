<?php
/**
 * ⛔ اشتراکِ فروشگاه و درگاهِ پرداخت (`StorePlan`، `PayGateway`، migration_store_plan).
 *
 *   ۱. دوره‌ی آزمایشی از اولین دیدن (نه از ساختِ حساب)، یک بار؛ تمدید از بیشترِ امروز و انقضا؛ مادام‌العمر دست نمی‌خورد.
 *   ۲. درگاه: مبلغ از ردیفِ خودمان، authority باید همان باشد، تأییدِ سرور به سرور، فقط یک بار تمدید.
 *   ۳. کارت‌به‌کارت: ادعا تا تأییدِ مدیر؛ تأیید/رد یک بار.
 *   ۴. HTTP: خاموش = بی‌اثر؛ روشن و منقضی = فقط خواندنی (POST ← اشتراک)، رمز و بکاپ باز؛ نوارِ هشدار؛
 *      همکار و فروشگاهِ اضافه زیرِ اشتراکِ صاحب؛ برگشت از درگاه بی‌نشست هم ثبت می‌شود.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/lib/store_http.php';
T::group('اشتراکِ فروشگاه');
if (!file_exists(__DIR__ . '/../config/config.php')) { T::blocked('اشتراکِ فروشگاه', 'config/config.php وجود ندارد'); exit(T::report()); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/biz.php';
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_staff.php';
require_once __DIR__ . '/../includes/store_plan.php';

try { $pdo = Database::getConnection(); } catch (Throwable $e) { T::blocked('اشتراکِ فروشگاه', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }
if (!StorePlan::ready()) { T::blocked('اشتراکِ فروشگاه', 'migration_store_plan اجرا نشده'); exit(T::report()); }

const PL_PREFIX = '__store_plan_';
const PL_PASS   = 'Plan#pass9';
$keys = [StorePlan::ENFORCE_SETTING, StorePlan::PRICE_SETTING, StorePlan::TRIAL_SETTING, StorePlan::GATEWAY_SETTING,
         StorePlan::MERCHANT_SETTING, StorePlan::SANDBOX_SETTING, 'plan_card_number'];
$saved = [];
foreach ($keys as $k) { $st = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :k'); $st->execute(['k' => $k]); $v = $st->fetchColumn(); $saved[$k] = $v === false ? null : (string)$v; }
$wipe = function () use ($pdo) {
    foreach ($pdo->query("SELECT id FROM users WHERE username LIKE '" . PL_PREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN) as $id) { deleteUserAccount((int)$id); }
};
$restore = function () use ($pdo, $saved) {
    foreach ($saved as $k => $v) {
        if ($v === null) { $pdo->prepare('DELETE FROM app_settings WHERE setting_key = :k')->execute(['k' => $k]); }
        else { $pdo->prepare('UPDATE app_settings SET setting_value = :v WHERE setting_key = :k')->execute(['v' => $v, 'k' => $k]); }
    }
};
$wipe();
register_shutdown_function(function () use ($wipe, $restore) { $restore(); $wipe(); });
$set = function (string $k, string $v) { setSetting($k, $v); };
$one = function (string $sql, array $p = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn(); };
$mk = function (string $n) use ($pdo): int {
    $r = createUserAccount($pdo, 'صاحب ' . $n, PL_PREFIX . $n, PL_PREFIX . $n . '@example.com', PL_PASS);
    Biz::setType((int)$r['id'], 'business');
    return (int)$r['id'];
};
$until = fn(int $u) => $one('SELECT store_until FROM users WHERE id = :u', ['u' => $u]);
$daysFromToday = fn(int $d) => (string)$one('SELECT DATE_ADD(CURDATE(), INTERVAL :d DAY)', ['d' => $d]);

T::group('۱ — دوره‌ی آزمایشی و تمدید');
$set(StorePlan::TRIAL_SETTING, '14');
$set(StorePlan::PRICE_SETTING, '300000');
$a = $mk('a');
$pdo->prepare('UPDATE users SET created_at = DATE_SUB(NOW(), INTERVAL 400 DAY) WHERE id = :u')->execute(['u' => $a]);
$s = StorePlan::status($a, false);
T::same([null, false], [$s['until'], $s['active']], 'بی‌شروعِ آزمایشی: تاریخی ثبت نمی‌شود');
$s = StorePlan::status($a, true);
T::same($daysFromToday(14), (string)$s['until'], '⛔ حسابِ ۴۰۰روزه: آزمایشی از امروز (نه از روزِ ساختِ حساب)');
T::ok($s['active'] && $s['trial'] && $s['days_left'] === 14, 'فعال، آزمایشی، ۱۴ روز');
$pdo->prepare('UPDATE users SET store_until = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE id = :u')->execute(['u' => $a]);
T::ok(!StorePlan::status($a, true)['active'], '⛔ آزمایشیِ تمام‌شده دوباره شروع نمی‌شود');
StorePlan::extend($pdo, $a, 1);
T::same((string)$one('SELECT DATE_ADD(CURDATE(), INTERVAL 1 MONTH)'), (string)$until($a), 'منقضی ← تمدید از امروز');
StorePlan::extend($pdo, $a, 3);
T::same((string)$one('SELECT DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 1 MONTH), INTERVAL 3 MONTH)'), (string)$until($a), 'فعال ← تمدید از انقضای فعلی (روزهای مانده حفظ)');
StorePlan::grant($a, 0, 1);
T::same(StorePlan::FOREVER, (string)$until($a), 'هدیه‌ی مادام‌العمر');
StorePlan::extend($pdo, $a, 12);
T::same(StorePlan::FOREVER, (string)$until($a), '⛔ تمدید روی مادام‌العمر آن را پاک نمی‌کند');
$fs = StorePlan::status($a);
T::ok($fs['forever'] && $fs['days_left'] === null, 'مادام‌العمر: «روزِ مانده» ندارد');
T::same(300000 * 10, StorePlan::periodPrice(12), 'یک ساله = ده ماه پول');
T::same(0, StorePlan::periodPrice(2), 'دوره‌ی نامعتبر: صفر');

T::group('۲ — درگاه');
$b = $mk('b');
T::ok(!StorePlan::startGateway($b, 1, 'https://x/cb?cb=1')['ok'], 'بی‌درگاه: رد');
$r = PayGateway::saveConfig('zarinpal', 'bad merchant!', false);
T::ok(!$r['ok'], 'مرچنتِ نامعتبر رد');
$merchant = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
PayGateway::saveConfig('zarinpal', $merchant, true);
T::same($merchant, PayGateway::merchant(), 'مرچنت خوانده می‌شود');
if (Crypto::available()) { T::ok((string)$one('SELECT setting_value FROM app_settings WHERE setting_key = :k', ['k' => StorePlan::MERCHANT_SETTING]) !== $merchant, '⛔ مرچنت رمزشده ذخیره شد'); }
PayGateway::saveConfig('zarinpal', '', true);
T::same($merchant, PayGateway::merchant(), 'مرچنتِ خالی = دست نزن');

$calls = [];
$verifyCode = 100;
PayGateway::$httpForTests = function (string $url, string $body) use (&$calls, &$verifyCode) {
    $calls[] = [$url, json_decode($body, true)];
    if (str_ends_with($url, '/request.json')) { return ['code' => 200, 'body' => json_encode(['data' => ['code' => 100, 'authority' => sprintf('A%035d', count($calls))], 'errors' => []])]; }
    return ['code' => 200, 'body' => json_encode($verifyCode === 100 ? ['data' => ['code' => 100, 'ref_id' => 987654321, 'card_pan' => '6037****1234'], 'errors' => []]
                                                               : ['data' => [], 'errors' => ['code' => $verifyCode, 'message' => 'تأیید نشد']])];
};
$r = StorePlan::startGateway($b, 6, 'https://hesab.test/store/subscription.php?cb=1');
T::ok($r['ok'] && str_starts_with((string)$r['redirect'], 'https://sandbox.zarinpal.com/pg/StartPay/A0000'), 'درخواست: هدایت به درگاهِ آزمایشی', json_encode($r, JSON_UNESCAPED_UNICODE));
$pid = (int)$r['id'];
$A1 = (string)$one('SELECT authority FROM store_plan_payments WHERE id = :i', ['i' => $pid]);
T::same(sprintf('A%035d', 1), $A1, 'authorityِ درگاه روی ردیف نشست');
[$u0, $p0] = $calls[0];
T::ok(str_starts_with($u0, 'https://sandbox.zarinpal.com/pg/v4/payment/request.json'), 'آدرسِ درخواستِ v4');
T::same([1500000, 'IRT', $merchant], [$p0['amount'], $p0['currency'], $p0['merchant_id']], 'مبلغِ شش‌ماهه (۵ ماه پول) به تومان');
T::ok(str_ends_with((string)$p0['callback_url'], '&p=' . $pid), 'آدرسِ برگشت شناسه‌ی پرداخت را دارد');
T::ok(!StorePlan::finishGateway($pid, sprintf('A%035d', 99), 'OK')['ok'], '⛔ authorityِ دیگر: رد');
T::same('pending', (string)$one('SELECT status FROM store_plan_payments WHERE id = :i', ['i' => $pid]), 'و پرداخت دست نخورد');
$before = $until($b);
$r = StorePlan::finishGateway($pid, $A1, 'OK');
T::ok($r['ok'] && str_contains($r['message'], '987654321'), 'تأیید: موفق با کدِ پیگیری', $r['message']);
[$u1, $p1] = end($calls);
T::ok(str_ends_with($u1, '/verify.json') && $p1['amount'] === 1500000, '⛔ تأیید با مبلغِ ردیفِ خودمان');
T::same([null, (string)$one('SELECT DATE_ADD(CURDATE(), INTERVAL 6 MONTH)')], [$before, (string)$until($b)], 'بی‌اشتراکِ قبلی ← شش ماه از امروز');
$row = $pdo->query('SELECT status, ref_id, card_pan FROM store_plan_payments WHERE id = ' . $pid)->fetch();
T::same(['paid', '987654321', '6037****1234'], [$row['status'], $row['ref_id'], $row['card_pan']], 'ردیف: پرداخت‌شده با پیگیری و کارتِ پوشیده');
$after = $until($b);
$r = StorePlan::finishGateway($pid, $A1, 'OK');
T::ok($r['ok'] && $until($b) === $after, '⛔ برگشتِ دوباره (رفرش): دو بار تمدید نمی‌شود');

// لغو و شکستِ تأیید
$r = StorePlan::startGateway($b, 1, 'https://hesab.test/cb?cb=1');
$pdo->prepare("UPDATE store_plan_payments SET authority = 'A000000000000000000000000000CANCEL01' WHERE id = :i")->execute(['i' => (int)$r['id']]);
$r2 = StorePlan::finishGateway((int)$r['id'], 'A000000000000000000000000000CANCEL01', 'NOK');
T::ok(!$r2['ok'] && $until($b) === $after, 'لغو در درگاه: ناموفق، بی‌تمدید');
T::same('failed', (string)$one('SELECT status FROM store_plan_payments WHERE id = :i', ['i' => (int)$r['id']]), 'و ردیف «ناموفق»');
$r = StorePlan::startGateway($b, 1, 'https://hesab.test/cb?cb=1');
$pdo->prepare("UPDATE store_plan_payments SET authority = 'A000000000000000000000000000VERIFY01' WHERE id = :i")->execute(['i' => (int)$r['id']]);
$verifyCode = -51;
$r2 = StorePlan::finishGateway((int)$r['id'], 'A000000000000000000000000000VERIFY01', 'OK');
T::ok(!$r2['ok'] && $until($b) === $after, '⛔ «OK» در آدرس ولی تأییدِ سرور رد: بی‌تمدید');
$verifyCode = 100;

T::group('۳ — کارت‌به‌کارت');
$c = $mk('c');
T::ok(!StorePlan::submitCard($c, 1, '')['ok'], 'بی‌پیگیری رد');
T::ok(StorePlan::submitCard($c, 3, '۱۲۳۴')['ok'], 'ثبتِ ادعا');
T::ok(!StorePlan::submitCard($c, 1, '5678')['ok'], 'ادعای دوم تا تأییدِ اولی رد');
T::same(null, $until($c), '⛔ ادعا به‌تنهایی تمدید نمی‌کند');
$cid = (int)$one("SELECT id FROM store_plan_payments WHERE user_id = :u AND method = 'card'", ['u' => $c]);
T::same('1234', (string)$one('SELECT ref_id FROM store_plan_payments WHERE id = :i', ['i' => $cid]), 'پیگیری با ارقامِ لاتین ذخیره شد');
T::ok(StorePlan::decide($cid, true, 1)['ok'], 'تأییدِ مدیر');
T::same((string)$one('SELECT DATE_ADD(CURDATE(), INTERVAL 3 MONTH)'), (string)$until($c), 'سه ماه تمدید شد');
T::ok(!StorePlan::decide($cid, true, 1)['ok'], '⛔ تأییدِ دوباره رد (دو بار تمدید نمی‌شود)');
StorePlan::submitCard($c, 1, '9999');
$cid2 = (int)$one("SELECT MAX(id) FROM store_plan_payments WHERE user_id = :u AND method = 'card'", ['u' => $c]);
$u3 = $until($c);
StorePlan::decide($cid2, false, 1, 'پیدا نشد');
T::ok($until($c) === $u3 && (string)$one('SELECT status FROM store_plan_payments WHERE id = :i', ['i' => $cid2]) === 'rejected', 'رد: بی‌تمدید');

T::group('۴ — HTTP');
$own = $mk('own');
$set(StorePlan::ENFORCE_SETTING, '0');
$port = stHttpServer();
T::ok($port > 0, 'سرورِ آزمایشی بالا آمد');
if (!$port) { exit(T::report()); }
$o = stHttpClient($port);
stHttpLogin($o, PL_PREFIX . 'own', PL_PASS);
[$cd, $h] = $o('store/index.php');
T::ok($cd === 200 && !str_contains($h, 'st-plan-bar'), 'اجرا خاموش: نوارِ اشتراک نیست');
T::same(null, $until($own), '⛔ اجرا خاموش: دوره‌ی آزمایشی شروع نمی‌شود');

$set(StorePlan::ENFORCE_SETTING, '1');
$set(StorePlan::TRIAL_SETTING, '3');
[$cd, $h] = $o('store/index.php');
T::same($daysFromToday(3), (string)$until($own), 'روشن شد: آزمایشی از همین دیدن');
T::ok(str_contains($h, 'st-plan-bar') && str_contains($h, 'دوره‌ی آزمایشی'), 'نوارِ «دوره‌ی آزمایشی … تمام می‌شود» (کمتر از ۷ روز)');

$pdo->prepare('UPDATE users SET store_until = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE id = :u')->execute(['u' => $own]);
[$cd, $h] = $o('store/settings.php');
T::ok($cd === 200 && str_contains($h, 'فقط خواندنی'), 'منقضی: صفحه‌ها باز با نوارِ «فقط خواندنی»');
$o('store/settings.php', ['csrf_token' => stCsrf($h), 'shop_name' => 'نامِ تازه', 'phone' => '021']);
T::ok((string)$one('SELECT COALESCE(MAX(shop_name), \'\') FROM biz_settings WHERE user_id = :u', ['u' => $own]) !== 'نامِ تازه', '⛔ منقضی: POST ذخیره نشد');
[$cd, $h] = $o('store/subscription.php');
T::ok($cd === 200 && str_contains($h, 'تمام شده') && str_contains($h, 'name="action" value="pay"'), 'صفحه‌ی اشتراک: منقضی، با دکمه‌ی پرداخت');
$o('store/subscription.php', ['csrf_token' => stCsrf($h), 'action' => 'card', 'months' => '1', 'ref' => '4321']);
T::same(1, (int)$one("SELECT COUNT(*) FROM store_plan_payments WHERE user_id = :u AND method = 'card'", ['u' => $own]), 'منقضی: ثبتِ پرداخت از صفحه‌ی اشتراک کار می‌کند');
[$cd, $h] = $o('store/account.php');
[$cd2, $h2] = $o('store/account.php', ['csrf_token' => stCsrf($h), 'action' => 'export']);
T::ok(!str_contains($h2, 'اشتراکِ فروشگاه تمام شده است — همه‌چیز'), 'منقضی: «رمز و بکاپ» POST را می‌پذیرد');

// همکار و فروشگاهِ اضافه زیرِ اشتراکِ صاحب
$sel = BizStaff::add($own, ['full_name' => 'فروشنده', 'username' => PL_PREFIX . 'sel', 'password' => PL_PASS, 'role' => 'seller']);
$s = stHttpClient($port);
stHttpLogin($s, PL_PREFIX . 'sel', PL_PASS);
[$cd, $h] = $s('store/quick-sale.php');
T::ok(str_contains($h, 'st-plan-bar') && str_contains($h, 'به صاحبِ فروشگاه خبر دهید'), 'همکار: نوارِ انقضا با «به صاحب خبر دهید»');
[$cd, $h] = $s('store/subscription.php');
T::ok($cd === 200 && !str_contains($h, 'value="pay"'), 'همکار: وضعیت را می‌بیند، دکمه‌ی پرداخت نه');
// پرداختِ در انتظارِ صاحب کنار می‌رود، وگرنه ردِ همکار به دلیلِ «در انتظار» بود نه «همکار»
$pdo->prepare("UPDATE store_plan_payments SET status = 'rejected' WHERE user_id = :u AND status = 'pending'")->execute(['u' => $own]);
$nCard = (int)$one("SELECT COUNT(*) FROM store_plan_payments WHERE user_id = :u", ['u' => $own]);
[, $qs] = $s('store/quick-sale.php');   // ⚠ صفحه‌ی اشتراکِ همکار فرمی ندارد — توکنِ همان نشست از صفحه‌ی دیگر
T::ok(stCsrf($qs) !== '', 'توکنِ CSRFِ نشستِ همکار');
$s('store/subscription.php', ['csrf_token' => stCsrf($qs), 'action' => 'card', 'months' => '1', 'ref' => '7777']);
T::same($nCard, (int)$one("SELECT COUNT(*) FROM store_plan_payments WHERE user_id = :u", ['u' => $own]), '⛔ همکار با POSTِ دستی هم پرداختی ثبت نمی‌کند');
if (Biz::shopsReady()) {
    $shop = Biz::createShop($own, 'شعبه‌ی دو');
    T::same($own, StorePlan::payerFor((int)$shop['id']), 'پرداخت‌کننده‌ی فروشگاهِ اضافه = صاحب');
}

// برگشت از درگاه بی‌نشست
$pr = StorePlan::startGateway($own, 1, 'https://hesab.test/cb?cb=1');
$auth = (string)$one('SELECT authority FROM store_plan_payments WHERE id = :i', ['i' => (int)$pr['id']]);
// ⚠ سرورِ آزمایشی فرایندِ جداست و `$httpForTests` ندارد؛ پس تأییدِ واقعی آن‌جا به سدِ Outbound می‌خورد —
//   این‌جا فقط می‌سنجیم که برگشت بی‌نشست به صفحه‌ی ورود پرتاب نمی‌شود و پرداخت را پردازش می‌کند (ناموفق، بی‌تمدید).
$anon = stHttpClient($port);
[$cd] = $anon('store/pay-return.php?r=1&p=' . (int)$pr['id'] . '&Authority=' . $auth . '&Status=OK');
T::same('failed', (string)$one('SELECT status FROM store_plan_payments WHERE id = :i', ['i' => (int)$pr['id']]), 'برگشتِ بی‌نشست پردازش شد (در تست تأیید به سدِ Outbound می‌خورد: ناموفق)');
T::ok((string)$until($own) < (string)$one('SELECT CURDATE()'), '⛔ بی‌تأییدِ سرور: تمدیدی نشد');

PayGateway::$httpForTests = null;
$r = PayGateway::request(1000, 'x', 'https://hesab.test/cb');
T::ok(!$r['ok'] && str_contains($r['message'], 'وصل نشد'), '⛔ در اجرای تست درخواستِ واقعی به درگاه نمی‌رود (Outbound)', $r['message']);

$set(StorePlan::ENFORCE_SETTING, '0');
exit(T::report());
