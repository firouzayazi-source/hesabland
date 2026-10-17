<?php
/**
 * ⛔ تغییرِ گروهیِ قیمت و چسباندنِ لیستِ قیمت (`BizBulkPrice`، `store/bulk-price.php`).
 *
 *   ۱. محاسبه: درصد/مبلغ/ثابت، افزایش/کاهش، گرد کردن؛ منفی و درصدِ بی‌معنا رد.
 *   ۲. صافی: نام/کد، نوع، فقط فعال‌ها و بی‌خدمت.
 *   ۳. لیست: Tab، «;»، فاصله؛ کد، نامِ دقیق، «از نظرِ آدم یکی»؛ چندپهلو و ناپیدا و بدخط گفته می‌شوند، هرگز حدس.
 *   ۴. HTTP: پیش‌نمایش نمی‌نویسد؛ اعمال دوباره روی سرور حساب می‌کند (عددِ فرم باور نمی‌شود)؛ نرخِ روز دست نمی‌خورد؛
 *      فروشنده راه ندارد.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/lib/store_http.php';
T::group('تغییرِ گروهیِ قیمت');
if (!file_exists(__DIR__ . '/../config/config.php')) { T::blocked('قیمت', 'config/config.php وجود ندارد'); exit(T::report()); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/biz.php';
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_staff.php';
require_once __DIR__ . '/../includes/biz_bulk_price.php';

try { $pdo = Database::getConnection(); } catch (Throwable $e) { T::blocked('قیمت', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }
const BP_PREFIX = '__store_bulk_';
const BP_PASS   = 'Bulk#pass9';
$wipe = function () use ($pdo) {
    foreach ($pdo->query("SELECT id FROM users WHERE username LIKE '" . BP_PREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN) as $id) { deleteUserAccount((int)$id); }
};
$wipe();
register_shutdown_function($wipe);
$r = createUserAccount($pdo, 'فروشگاه', BP_PREFIX . 'a', BP_PREFIX . 'a@example.com', BP_PASS);
$u = (int)$r['id'];
Biz::setType($u, 'business');
$one = function (string $sql, array $p = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn(); };
$P = [];
foreach ([['Galaxy A55', 'phone', 24500000, 'S-A55'], ['Galaxy A35', 'phone', 18000000, ''], ['آیفون ۱۵', 'phone', 60000000, ''],
          ['قاب سیلیکونی', 'goods', 150000, 'CASE-1'], ['شارژر ۲۵ وات', 'goods', 420000, ''], ['تعمیر', 'service', 500000, ''],
          ['کالای غیرفعال', 'goods', 1000, ''], ['شارژر ۲۵وات', 'goods', 430000, ''], ['آداپتور', 'goods', 90000, ''], ['اداپتور', 'goods', 95000, '']] as [$n, $t, $p, $sku]) {
    $P[$n] = (int)BizProducts::save($u, ['name' => $n, 'type' => $t, 'unit' => $t === 'phone' ? 'دستگاه' : 'عدد', 'sell_price' => (string)$p, 'sku' => $sku])['id'];
}
$pdo->prepare('UPDATE biz_products SET is_active = 0 WHERE id = :i')->execute(['i' => $P['کالای غیرفعال']]);

T::group('۱ — محاسبه');
$rows = [['id' => 1, 'name' => 'a', 'sell_price' => 24500000], ['id' => 2, 'name' => 'b', 'sell_price' => 150000]];
T::same([1 => 25235000, 2 => 155000], BizBulkPrice::compute($rows, 'pct', '۳', 1000)['prices'] ?? null, '۳٪ افزایش، گرد به هزار');
T::same([1 => 23765000, 2 => 146000], BizBulkPrice::compute($rows, 'pct', '3', 1000, true)['prices'] ?? null, '۳٪ کاهش');
T::same([1 => 24550000, 2 => 200000], BizBulkPrice::compute($rows, 'add', '۵۰٬۰۰۰', 1)['prices'] ?? null, '+۵۰ هزار تومان');
T::same([1 => 1000000, 2 => 1000000], BizBulkPrice::compute($rows, 'set', '1000000', 1)['prices'] ?? null, 'قیمتِ ثابت');
T::same([1 => 25100000, 2 => 200000], BizBulkPrice::compute($rows, 'pct', '2.5', 100000)['prices'] ?? null, 'درصدِ اعشاری، گرد به صد هزار (۲۵٬۱۱۲٬۵۰۰ ← ۲۵٬۱۰۰٬۰۰۰)');
T::same([1 => 25235000], BizBulkPrice::compute([$rows[0]], 'pct', '۳٫۰', 1000)['prices'] ?? null, 'ممیزِ فارسی');
T::ok(!BizBulkPrice::compute($rows, 'add', '200000', 1, true)['ok'], '⛔ کاهشی که قیمت را منفی می‌کند رد');
T::ok(!BizBulkPrice::compute($rows, 'pct', '0', 1)['ok'] && !BizBulkPrice::compute($rows, 'pct', '600', 1)['ok'], 'درصدِ صفر یا بیش از ۵۰۰ رد');
T::ok(!BizBulkPrice::compute($rows, 'pct', 'abc', 1)['ok'], 'درصدِ نامعتبر رد');
T::ok(!BizBulkPrice::compute($rows, 'xx', '3', 1)['ok'], 'نوعِ نامعتبر رد');

T::group('۲ — صافی');
$ids = fn(array $rs) => array_map(fn($p) => (int)$p['id'], $rs);
$all = $ids(BizBulkPrice::select($u, []));
T::ok(!in_array($P['تعمیر'], $all, true) && !in_array($P['کالای غیرفعال'], $all, true), '⛔ خدمت و غیرفعال در گروه نیستند');
T::same([$P['Galaxy A35'], $P['Galaxy A55']], $ids(BizBulkPrice::select($u, ['q' => 'Galaxy'])), 'نام شامل');
T::same([$P['Galaxy A55']], $ids(BizBulkPrice::select($u, ['q' => 's-a55'])), 'کد شامل');
T::same(3, count(BizBulkPrice::select($u, ['t' => 'phone'])), 'فقط گوشی');

T::group('۳ — لیستِ قیمت');
$txt = "S-A55\t۲۵٬۰۰۰٬۰۰۰\nGalaxy A35; 18,500,000\nایفون ۱۵ ۶۱٬۰۰۰٬۰۰۰\nشارژر ۲۵ وات 440000\nأداپتور\t100000\nکالای ناموجود\t100\nبی‌قیمت\nCASE-1\t-5";
$res = BizBulkPrice::parseList($u, $txt);
T::same([$P['Galaxy A55'] => 25000000, $P['Galaxy A35'] => 18500000, $P['آیفون ۱۵'] => 61000000], array_intersect_key($res['prices'], array_flip([$P['Galaxy A55'], $P['Galaxy A35'], $P['آیفون ۱۵']])),
    'کد با Tab، نام با «;»، «ایفون» با فاصله‌ی آخر (از نظرِ آدم یکی)');
T::same(440000, $res['prices'][$P['شارژر ۲۵ وات']] ?? null, 'نامِ دقیق بر «از نظرِ آدم یکی» مقدم است (شارژر ۲۵ وات، نه ۲۵وات)');
T::ok(!isset($res['prices'][$P['آداپتور']]) && !isset($res['prices'][$P['اداپتور']]) && in_array('أداپتور', $res['ambiguous'], true), '⛔ «أداپتور» به دو کالا می‌خورد: کنار گذاشته شد، حدس نه');
T::same(['کالای ناموجود'], $res['missing'], 'ناپیدا گفته شد');
T::ok(in_array('بی‌قیمت', $res['bad'], true) && in_array("CASE-1\t-5", $res['bad'], true), 'بی‌قیمت و قیمتِ منفی: بدخط');
T::same(4, count($res['rows']), 'چهار ردیف برای پیش‌نمایش (منفی، چندپهلو و ناپیدا بیرون)');

T::group('۴ — HTTP');
$pdo->prepare("UPDATE biz_products SET rate_code = 'usd' WHERE id = :i")->execute(['i' => $P['Galaxy A35']]);
$port = stHttpServer();
T::ok($port > 0, 'سرورِ آزمایشی بالا آمد');
if (!$port) { exit(T::report()); }
$c = stHttpClient($port);
stHttpLogin($c, BP_PREFIX . 'a', BP_PASS);
[$code, $h] = $c('store/bulk-price.php');
T::ok($code === 200 && str_contains($h, 'پیش‌نمایش'), 'صفحه باز شد');
$form = ['csrf_token' => stCsrf($h), 'tab' => 'group', 'q' => 'Galaxy', 'c' => '', 't' => '', 'mode' => 'pct', 'value' => '۱۰', 'round' => '1000', 'dir' => 'up'];
[$code, $h] = $c('store/bulk-price.php', $form + ['action' => 'preview']);
T::ok(str_contains($h, 'data-preview') && str_contains($h, '۲۶٬۹۵۰٬۰۰۰') && str_contains($h, 'نرخِ روز — عوض نمی‌شود'), 'پیش‌نمایش: قیمتِ تازه و نشانِ نرخِ روز');
T::same(24500000, (int)$one('SELECT sell_price FROM biz_products WHERE id = :i', ['i' => $P['Galaxy A55']]), '⛔ پیش‌نمایش چیزی ننوشت');
$c('store/bulk-price.php', $form + ['action' => 'apply', 'prices' => [$P['Galaxy A55'] => '1']]);
T::same(26950000, (int)$one('SELECT sell_price FROM biz_products WHERE id = :i', ['i' => $P['Galaxy A55']]), '⛔ اعمال: دوباره روی سرور حساب شد (عددِ دستکاری‌شده‌ی فرم نه)');
T::same(18000000, (int)$one('SELECT sell_price FROM biz_products WHERE id = :i', ['i' => $P['Galaxy A35']]), '⛔ کالای وصل به نرخِ روز دست نخورد');
T::same(150000, (int)$one('SELECT sell_price FROM biz_products WHERE id = :i', ['i' => $P['قاب سیلیکونی']]), 'بیرونِ صافی دست نخورد');
[, $h] = $c('store/bulk-price.php?t=list');
$c('store/bulk-price.php', ['csrf_token' => stCsrf($h), 'tab' => 'list', 'action' => 'apply', 'text' => "CASE-1\t۱۶۰٬۰۰۰"]);
T::same(160000, (int)$one('SELECT sell_price FROM biz_products WHERE id = :i', ['i' => $P['قاب سیلیکونی']]), 'لیست از صفحه اعمال شد');

BizStaff::add($u, ['full_name' => 'فروشنده', 'username' => BP_PREFIX . 'sel', 'password' => BP_PASS, 'role' => 'seller']);
$s = stHttpClient($port);
stHttpLogin($s, BP_PREFIX . 'sel', BP_PASS);
[$code, $h] = $s('store/bulk-price.php');
T::ok($code !== 200 || !str_contains($h, 'name="mode"'), '⛔ فروشنده (بی‌«کالا») صفحه را ندارد');
[, $qs] = $s('store/quick-sale.php');
$s('store/bulk-price.php', ['csrf_token' => stCsrf($qs), 'tab' => 'list', 'action' => 'apply', 'text' => "CASE-1\t1"]);
T::same(160000, (int)$one('SELECT sell_price FROM biz_products WHERE id = :i', ['i' => $P['قاب سیلیکونی']]), '⛔ و با POSTِ دستی هم چیزی عوض نمی‌کند');

exit(T::report());
