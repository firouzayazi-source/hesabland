<?php
/**
 * ⛔ لیستِ قیمت برای همکاران و مشتری (`BizPriceList`، `store/price-list.php`، `store/prices.php`).
 *
 *   ۱. پیش‌فرض خاموش؛ روشن کردن نمک می‌سازد، خاموش کردن پاکش می‌کند، «باطل کردن» عوضش می‌کند.
 *   ۲. لینک: هر سطح امضای خودش؛ سطحِ روشن‌نشده، امضای سطحِ دیگر، فروشگاهِ دیگر و نمکِ کهنه همه ۴۰۴ِ خنثی.
 *   ۳. محتوا: نام و قیمتِ همان سطح (خالی = قیمتِ فروش)، «ناموجود» نه عدد؛ هرگز قیمتِ خرید یا شمارِ موجودی؛
 *      خدمت/غیرفعال/بی‌قیمت نه؛ صافیِ ذخیره‌شده (نه از آدرس).
 *   ۴. فروشنده تنظیم نمی‌کند.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/lib/store_http.php';
T::group('لیستِ قیمت');
if (!file_exists(__DIR__ . '/../config/config.php')) { T::blocked('لیستِ قیمت', 'config/config.php وجود ندارد'); exit(T::report()); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/biz.php';
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_staff.php';
require_once __DIR__ . '/../includes/biz_pricelist.php';

try { $pdo = Database::getConnection(); } catch (Throwable $e) { T::blocked('لیستِ قیمت', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }
if (!BizPriceList::ready() || !BizProducts::levelsReady()) { T::blocked('لیستِ قیمت', 'migration اجرا نشده'); exit(T::report()); }
const PL2_PREFIX = '__store_plist_';
const PL2_PASS   = 'Plist#pass9';
$wipe = function () use ($pdo) {
    foreach ($pdo->query("SELECT id FROM users WHERE username LIKE '" . PL2_PREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN) as $id) { deleteUserAccount((int)$id); }
};
$wipe();
register_shutdown_function($wipe);
$mk = function (string $n) use ($pdo): int {
    $r = createUserAccount($pdo, 'صاحب ' . $n, PL2_PREFIX . $n, PL2_PREFIX . $n . '@example.com', PL2_PASS);
    Biz::setType((int)$r['id'], 'business');
    return (int)$r['id'];
};
$u = $mk('a');
$o = $mk('o');
Biz::saveSettings($u, ['shop_name' => 'موبایلِ نمونه', 'phone' => '02100000000']);
$P = [];
foreach ([['Galaxy A55', 'گوشی', 24500000, 23000000, 22000000, 3, 21500000], ['Galaxy A35', 'گوشی', 18000000, null, null, 0, 17000000],
          ['قاب', 'لوازم', 150000, 120000, null, 40, 60000], ['تعمیر', 'خدمات', 500000, null, null, 0, 0], ['بی‌قیمت', 'لوازم', 0, null, null, 5, 0]] as [$n, $cat, $sell, $wh, $col, $qty, $buy]) {
    $r = BizProducts::save($u, ['name' => $n, 'type' => $n === 'تعمیر' ? 'service' : 'goods', 'unit' => 'عدد', 'category' => $cat,
        'sell_price' => (string)$sell, 'buy_price' => (string)$buy, 'opening_qty' => $qty > 0 ? (string)$qty : '']);
    $P[$n] = (int)$r['id'];
    BizProducts::savePrices($u, $P[$n], ['price_wholesale' => $wh === null ? '' : (string)$wh, 'price_colleague' => $col === null ? '' : (string)$col]);
}
BizProducts::save($o, ['name' => 'کالای دیگری', 'unit' => 'عدد', 'sell_price' => '999']);

T::group('۱ — روشن و خاموش');
$cfg = BizPriceList::config($u);
T::ok(!BizPriceList::on($cfg) && $cfg['salt'] === '', 'پیش‌فرض خاموش');
T::same(null, BizPriceList::verify($u, 'retail', BizPriceList::token($u, 'retail', '')), '⛔ خاموش: حتی امضای نمکِ خالی پذیرفته نمی‌شود');
BizPriceList::save($u, ['levels' => ['retail', 'colleague', 'nope'], 'in_stock' => '1', 'title' => 'قیمتِ امروز']);
$cfg = BizPriceList::config($u);
T::same(['retail', 'colleague'], $cfg['levels'], 'سطحِ ناشناخته دور ریخته شد');
T::ok(strlen($cfg['salt']) === 16, 'روشن کردن نمک ساخت');
$salt1 = $cfg['salt'];
BizPriceList::save($u, ['levels' => ['retail', 'colleague'], 'in_stock' => '1', 'title' => 'قیمتِ امروز']);
T::same($salt1, BizPriceList::config($u)['salt'], 'ذخیره‌ی دوباره نمک را عوض نمی‌کند (لینک‌ها می‌مانند)');

T::group('۲ — محتوا');
$rows = BizPriceList::rows($u, 'retail', BizPriceList::config($u));
T::same(['Galaxy A55' => 24500000, 'قاب' => 150000], array_column($rows, 'price', 'name'), 'فقط موجودها (Galaxy A35 ناموجود)، بی‌خدمت، بی‌کالای بی‌قیمت');
$rowsC = BizPriceList::rows($u, 'colleague', ['in_stock' => false] + BizPriceList::config($u));
T::same(['Galaxy A35' => 18000000, 'Galaxy A55' => 22000000, 'قاب' => 150000], array_column($rowsC, 'price', 'name'), 'سطحِ همکار؛ سطحِ خالی = قیمتِ فروش');
T::same(false, array_column($rowsC, 'available', 'name')['Galaxy A35'], 'ناموجود: «موجود نه»، بی‌عدد');
T::same(['name', 'category', 'price', 'available'], array_keys($rowsC[0]), '⛔ هیچ ستونِ دیگری (قیمتِ خرید، موجودی) از تابع بیرون نمی‌آید');

T::group('۳ — HTTP');
$port = stHttpServer();
T::ok($port > 0, 'سرورِ آزمایشی بالا آمد');
if (!$port) { exit(T::report()); }
$anon = stHttpClient($port);
$url = fn(string $lv, ?string $tok = null, int $shop = 0) => 'store/prices.php?' . http_build_query(['s' => $shop ?: $u, 'l' => $lv, 't' => $tok ?? BizPriceList::token($shop ?: $u, $lv, BizPriceList::config($shop ?: $u)['salt'])]);
[$c, $h] = $anon($url('retail'));
T::ok($c === 200 && str_contains($h, 'موبایلِ نمونه') && str_contains($h, '۲۴٬۵۰۰٬۰۰۰') && str_contains($h, 'قیمتِ امروز'), 'لینکِ تک‌فروشی بی‌ورود باز شد');
T::ok(!str_contains($h, '۲۱٬۵۰۰٬۰۰۰') && !str_contains($h, '۶۰٬۰۰۰') && !str_contains($h, 'Galaxy A35') && !str_contains($h, 'تعمیر'), '⛔ نه قیمتِ خرید، نه ناموجود، نه خدمت');
T::ok(!str_contains($h, '/store/index.php') && !str_contains($h, 'login.php'), 'بی‌لینک به داخلِ فروشگاه');
[$c, $h] = $anon($url('colleague'));
T::ok($c === 200 && str_contains($h, '۲۲٬۰۰۰٬۰۰۰') && !str_contains($h, '۲۴٬۵۰۰٬۰۰۰'), 'لینکِ همکار: قیمتِ همکار');
[$c] = $anon($url('wholesale', BizPriceList::token($u, 'wholesale', $salt1)));
T::same(404, $c, '⛔ سطحِ روشن‌نشده (عمده) حتی با امضای درست باز نمی‌شود');
[$c] = $anon('store/prices.php?' . http_build_query(['s' => $u, 'l' => 'colleague', 't' => BizPriceList::token($u, 'retail', $salt1)]));
T::same(404, $c, '⛔ امضای تک‌فروشی با سطحِ همکار: ۴۰۴ (دست‌کاریِ آدرس به قیمتِ همکار نمی‌رسد)');
// ⛔ فروشگاهِ دیگر، روشن و حتی با **همان نمک**: امضا به شناسه‌ی فروشگاه بسته است
$pdo->prepare("INSERT INTO biz_settings (user_id, shop_name, price_list) VALUES (:u, 'دیگری', :j) ON DUPLICATE KEY UPDATE price_list = VALUES(price_list)")
    ->execute(['u' => $o, 'j' => json_encode(['levels' => ['retail'], 'salt' => $salt1, 'in_stock' => false])]);
[$c] = $anon('store/prices.php?' . http_build_query(['s' => $o, 'l' => 'retail', 't' => BizPriceList::token($u, 'retail', $salt1)]));
T::same(404, $c, '⛔ امضای این فروشگاه برای فروشگاهِ دیگر (حتی با نمکِ یکسان): ۴۰۴');
[$c] = $anon('store/prices.php?' . http_build_query(['s' => $o, 'l' => 'retail', 't' => BizPriceList::token($o, 'retail', $salt1)]));
T::same(200, $c, 'و امضای خودِ آن فروشگاه کار می‌کند (سنجشِ بالا کور نیست)');
[$c] = $anon('store/prices.php?' . http_build_query(['s' => $u, 'l' => 'retail', 't' => 'x', 'in_stock' => '0']));
T::same(404, $c, 'امضای غلط: ۴۰۴');

$own = stHttpClient($port);
stHttpLogin($own, PL2_PREFIX . 'a', PL2_PASS);
[$c, $h] = $own('store/price-list.php');
T::ok($c === 200 && str_contains($h, 'data-share-url') && str_contains($h, 'wa.me'), 'صفحه‌ی صاحب: لینک‌ها با اشتراک‌گذاری');
$oldTok = BizPriceList::token($u, 'retail', $salt1);
$own('store/price-list.php', ['csrf_token' => stCsrf($h), 'action' => 'rotate', 'levels' => ['retail', 'colleague'], 'in_stock' => '1', 'title' => 'قیمتِ امروز']);
Biz::forgetSettings($u);
T::ok(BizPriceList::config($u)['salt'] !== $salt1, '«باطل کردن»: نمکِ تازه');
[$c] = $anon($url('retail', $oldTok));
T::same(404, $c, '⛔ لینکِ قبلی بعد از باطل کردن: ۴۰۴');
[$c] = $anon($url('retail'));
T::same(200, $c, 'لینکِ تازه کار می‌کند');
[, $h] = $own('store/price-list.php');
$own('store/price-list.php', ['csrf_token' => stCsrf($h), 'action' => 'save']);
Biz::forgetSettings($u);
T::same('', BizPriceList::config($u)['salt'], 'هیچ سطحی: خاموش و نمک پاک شد');
[$c] = $anon($url('retail', BizPriceList::token($u, 'retail', '')));
T::same(404, $c, '⛔ خاموش: هیچ لینکی باز نمی‌شود');

BizStaff::add($u, ['full_name' => 'فروشنده', 'username' => PL2_PREFIX . 'sel', 'password' => PL2_PASS, 'role' => 'seller']);
$s = stHttpClient($port);
stHttpLogin($s, PL2_PREFIX . 'sel', PL2_PASS);
[$c, $h] = $s('store/price-list.php');
T::ok($c !== 200 || !str_contains($h, 'name="levels[]"'), '⛔ فروشنده تنظیمِ لیستِ قیمت را ندارد');
[, $qs] = $s('store/quick-sale.php');
$s('store/price-list.php', ['csrf_token' => stCsrf($qs), 'action' => 'save', 'levels' => ['colleague']]);
Biz::forgetSettings($u);
T::same([], BizPriceList::config($u)['levels'], '⛔ و با POSTِ دستی هم روشنش نمی‌کند');

exit(T::report());
