<?php
/**
 * ⛔ مدل و حالت‌ها (`BizModels`، `store/models.php`، migration_biz_models).
 *
 *   ۱. حالت‌ها ردیفِ واقعیِ کالا (از `BizProducts::save()`)؛ ساختِ دوباره تکراری نمی‌سازد؛ مقدارِ برداشته حذف نمی‌کند؛
 *      تغییرِ نامِ مدل نامِ حالت‌ها را عوض می‌کند؛ سقف‌ها.
 *   ۲. حالت مثلِ هر کالا در فاکتور و انبار کار می‌کند.
 *   ۳. قیمتِ گروهی (`setSellPrices()`): کالای وصل به نرخِ روز رد؛ کالای فروشگاهِ دیگر دست نمی‌خورد.
 *   ۴. حذفِ مدل کالاها را نگه می‌دارد؛ بکاپ و بازگرداندن پیوندِ مدل را نگاشت می‌کند.
 *   ۵. HTTP: ساختن و قیمت از صفحه؛ شناسه‌ی بیرونِ مدل از فرم پذیرفته نمی‌شود؛ فروشنده نمی‌سازد.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/lib/store_http.php';
T::group('مدل و حالت‌ها');
if (!file_exists(__DIR__ . '/../config/config.php')) { T::blocked('مدل', 'config/config.php وجود ندارد'); exit(T::report()); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/user_import.php';
require_once __DIR__ . '/../includes/biz.php';
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_docs.php';
require_once __DIR__ . '/../includes/biz_staff.php';
require_once __DIR__ . '/../includes/biz_models.php';

try { $pdo = Database::getConnection(); } catch (Throwable $e) { T::blocked('مدل', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }
if (!BizModels::ready()) { T::blocked('مدل', 'migration_biz_models اجرا نشده'); exit(T::report()); }

const MD_PREFIX = '__store_models_';
const MD_PASS   = 'Model#pass9';
$wipe = function () use ($pdo) {
    foreach ($pdo->query("SELECT id FROM users WHERE username LIKE '" . MD_PREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN) as $id) { deleteUserAccount((int)$id); }
};
$wipe();
register_shutdown_function($wipe);
$mk = function (string $n) use ($pdo): int {
    $r = createUserAccount($pdo, 'صاحب ' . $n, MD_PREFIX . $n, MD_PREFIX . $n . '@example.com', MD_PASS);
    Biz::setType((int)$r['id'], 'business');
    return (int)$r['id'];
};
$one = function (string $sql, array $p = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn(); };
$u = $mk('a');
$o = $mk('o');

T::group('۱ — ساختن');
T::same(['۱۲۸ گیگ', '۲۵۶ گیگ', '256GB'], BizModels::values("۱۲۸ گیگ، ۲۵۶ گیگ, 256GB\n۱۲۸ گیگ ,  "), 'مقدارها: «،» و «,» و خطِ تازه، یکتا، بی‌فاصله‌ی اضافه');
T::ok(!BizModels::save($u, ['name' => 'iPhone 15'])['ok'], 'بی‌مقدار رد');
$r = BizModels::save($u, ['name' => 'iPhone 15', 'values1' => '۱۲۸ گیگ، ۲۵۶ گیگ', 'values2' => 'مشکی، آبی، صورتی',
    'type' => 'phone', 'unit' => 'دستگاه', 'buy_price' => '۵۰٬۰۰۰٬۰۰۰', 'sell_price' => '۵۵٬۰۰۰٬۰۰۰']);
T::ok($r['ok'] && $r['created'] === 6, '۲ × ۳ = ۶ حالت', $r['message']);
$M = (int)$r['id'];
$m = BizModels::get($u, $M);
T::same(6, count($m['items']), 'شش کالای واقعی با model_id');
$names = array_column($m['items'], 'name');
sort($names);
T::ok(in_array('iPhone 15 ۱۲۸ گیگ مشکی', $names, true) && in_array('iPhone 15 ۲۵۶ گیگ صورتی', $names, true), 'نامِ حالت = مدل + دو مقدار');
$first = $m['items'][0];
T::same(['phone', 'دستگاه', 50000000, 55000000], [BizProducts::typeOf($first), (string)$first['unit'], (int)$first['buy_price'], (int)$first['sell_price']], 'نوع، واحد و قیمت‌ها از همان save()');
$r = BizModels::save($u, ['name' => 'iPhone 15', 'values1' => '۱۲۸ گیگ، ۲۵۶ گیگ، ۵۱۲ گیگ', 'values2' => 'مشکی', 'type' => 'phone', 'unit' => 'دستگاه'], $M);
T::ok($r['ok'] && $r['created'] === 1, '⛔ مقدارِ تازه فقط حالت‌های تازه (۵۱۲×مشکی)؛ «آبی/صورتی»ِ برداشته حذف نشد', $r['message']);
T::same(7, (int)$one('SELECT COUNT(*) FROM biz_products WHERE user_id = :u AND model_id = :m', ['u' => $u, 'm' => $M]), 'حالا ۷ حالت');
$r = BizModels::save($u, ['name' => 'iPhone 15 Pro', 'values1' => '۱۲۸ گیگ', 'values2' => 'مشکی', 'type' => 'phone', 'unit' => 'دستگاه'], $M);
T::same(0, (int)$one("SELECT COUNT(*) FROM biz_products WHERE user_id = :u AND model_id = :m AND name NOT LIKE 'iPhone 15 Pro %'", ['u' => $u, 'm' => $M]), 'تغییرِ نامِ مدل: همه‌ی حالت‌ها نامِ تازه');
$big = implode('،', array_map(fn($i) => 'ر' . $i, range(1, 13)));
T::ok(!BizModels::save($u, ['name' => 'X', 'values1' => $big, 'type' => 'goods'])['ok'], 'بیش از ۱۲ مقدار رد');
$ten = implode('،', array_map(fn($i) => 'ر' . $i, range(1, 8)));
T::ok(!BizModels::save($u, ['name' => 'Y', 'values1' => $ten, 'values2' => $ten, 'type' => 'goods'])['ok'], 'بیش از ۶۰ حالت (۸×۸) رد');
T::ok(!BizModels::save($u, ['name' => 'Z', 'values1' => 'الف', 'type' => 'phone', 'unit' => 'کیلوگرم'])['ok'], 'اعتبارسنجیِ save(): گوشیِ کیلوگرمی رد');

T::group('۲ — حالت مثلِ هر کالا');
$v128 = (int)$one("SELECT id FROM biz_products WHERE user_id = :u AND model_id = :m AND variant1 = '۱۲۸ گیگ' AND variant2 = 'مشکی'", ['u' => $u, 'm' => $M]);
$acc = (int)BizCash::list($u)[0]['id'];
$vName = (string)$one('SELECT name FROM biz_products WHERE id = :i', ['i' => $v128]);
$d = BizInvoices::saveDraft($u, 'purchase', ['lines' => [['product_id' => $v128, 'item' => $vName, 'qty' => '1', 'price' => '100', 'imei1' => '353918100000013', 'imei2' => '']]]);
$iss = BizInvoices::issue($u, (int)$d['id'], ['full' => true, 'account_id' => $acc]);
T::ok(($iss['ok'] ?? false) && (float)$one('SELECT stock_qty FROM biz_products WHERE id = :i', ['i' => $v128]) === 1.0, 'خریدِ IMEIدار روی یک حالت: فقط همان موجودی گرفت', json_encode([$d, $iss], JSON_UNESCAPED_UNICODE));
T::same(1.0, (float)$one('SELECT SUM(stock_qty) FROM biz_products WHERE model_id = :m', ['m' => $M]), 'و حالت‌های دیگر نه');

T::group('۳ — قیمتِ گروهی');
$ids = array_map('intval', $pdo->query('SELECT id FROM biz_products WHERE model_id = ' . $M . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
$pdo->prepare("UPDATE biz_products SET rate_code = 'usd' WHERE id = :i")->execute(['i' => $ids[1]]);
$foreign = (int)BizProducts::save($o, ['name' => 'کالای دیگری', 'unit' => 'عدد', 'sell_price' => '10'])['id'];
$r = BizProducts::setSellPrices($u, [$ids[0] => 60000000, $ids[1] => 1, $foreign => 99]);
T::ok($r['ok'] && $r['changed'] === 1 && $r['skipped'] === [$ids[1]], '⛔ وصل به نرخِ روز: رد و گفته می‌شود', json_encode($r, JSON_UNESCAPED_UNICODE));
T::same(60000000, (int)$one('SELECT sell_price FROM biz_products WHERE id = :i', ['i' => $ids[0]]), 'قیمتِ تازه نشست');
T::same(10, (int)$one('SELECT sell_price FROM biz_products WHERE id = :i', ['i' => $foreign]), '⛔ کالای فروشگاهِ دیگر دست نخورد');
T::ok(!BizProducts::setSellPrices($u, [$ids[0] => -5])['ok'], 'قیمتِ منفی رد');
$pdo->prepare('UPDATE biz_products SET rate_code = NULL WHERE id = :i')->execute(['i' => $ids[1]]);

T::group('۴ — بکاپ و حذف');
$ex = exportUserData($u);
$b = $mk('b');
$imp = importUserData($b, $ex);
T::ok(!empty($imp['ok']) || !isset($imp['ok']), 'بازگرداندنِ بکاپ در فروشگاهِ دیگر', json_encode(array_slice($imp, 0, 3), JSON_UNESCAPED_UNICODE));
$bm = (int)$one('SELECT id FROM biz_models WHERE user_id = :u', ['u' => $b]);
T::ok($bm > 0 && $bm !== $M, 'مدل با شناسه‌ی تازه آمد');
T::same(7, (int)$one('SELECT COUNT(*) FROM biz_products WHERE user_id = :u AND model_id = :m', ['u' => $b, 'm' => $bm]), '⛔ پیوندِ حالت‌ها به مدلِ **تازه** نگاشت شد (نه شناسه‌ی قدیمِ فایل)');
T::ok(BizModels::delete($u, $M), 'حذفِ مدل');
T::same(7, (int)$one('SELECT COUNT(*) FROM biz_products WHERE user_id = :u AND id IN (' . implode(',', $ids) . ') AND model_id IS NULL', ['u' => $u]), '⛔ کالاها ماندند (بی‌مدل)');
T::ok(!BizModels::delete($o, $bm), 'مدلِ فروشگاهِ دیگر حذف نمی‌شود');

T::group('۵ — HTTP');
$port = stHttpServer();
T::ok($port > 0, 'سرورِ آزمایشی بالا آمد');
if (!$port) { exit(T::report()); }
$c = stHttpClient($port);
stHttpLogin($c, MD_PREFIX . 'a', MD_PASS);
[$code, $h] = $c('store/models.php');
T::ok($code === 200 && str_contains($h, 'مدلِ تازه'), 'صفحه‌ی مدل‌ها');
$c('store/models.php', ['csrf_token' => stCsrf($h), 'action' => 'save', 'id' => '0', 'name' => 'Galaxy S24', 'values1' => '256GB', 'values2' => 'مشکی، کرم',
    'type' => 'phone', 'unit' => 'دستگاه', 'category' => '', 'buy_price' => '', 'sell_price' => '۴۰٬۰۰۰٬۰۰۰']);
$gm = (int)$one("SELECT id FROM biz_models WHERE user_id = :u AND name = 'Galaxy S24'", ['u' => $u]);
T::same(2, (int)$one('SELECT COUNT(*) FROM biz_products WHERE model_id = :m', ['m' => $gm]), 'ساختن از صفحه: دو حالت');
[$code, $h] = $c('store/models.php?id=' . $gm);
T::ok($code === 200 && str_contains($h, 'name="price['), 'جدولِ قیمتِ حالت‌ها');
$gids = array_map('intval', $pdo->query('SELECT id FROM biz_products WHERE model_id = ' . $gm . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
$c('store/models.php', ['csrf_token' => stCsrf($h), 'action' => 'prices', 'id' => (string)$gm,
    'price' => [$gids[0] => '۴۲٬۰۰۰٬۰۰۰', $ids[0] => '1']]);
T::same(42000000, (int)$one('SELECT sell_price FROM biz_products WHERE id = :i', ['i' => $gids[0]]), 'قیمت از صفحه (ارقامِ فارسی)');
T::same(60000000, (int)$one('SELECT sell_price FROM biz_products WHERE id = :i', ['i' => $ids[0]]), '⛔ شناسه‌ی کالای بیرونِ این مدل از فرم پذیرفته نشد');
[, $ph] = $c('store/products.php');
T::ok(str_contains($ph, 'models.php?id=' . $gm), 'فهرستِ کالاها: نشانِ «حالتی از یک مدل» با لینک');

BizStaff::add($u, ['full_name' => 'فروشنده', 'username' => MD_PREFIX . 'sel', 'password' => MD_PASS, 'role' => 'seller']);
$s = stHttpClient($port);
stHttpLogin($s, MD_PREFIX . 'sel', MD_PASS);
[$code, $h] = $s('store/models.php');
T::ok($code === 200 && !str_contains($h, 'مدلِ تازه'), 'فروشنده: فهرست را می‌بیند، فرمِ ساختن نه');
[, $qs] = $s('store/quick-sale.php');
$s('store/models.php', ['csrf_token' => stCsrf($qs), 'action' => 'save', 'id' => '0', 'name' => 'Seller Model', 'values1' => 'الف', 'type' => 'goods', 'unit' => 'عدد']);
T::same(0, (int)$one("SELECT COUNT(*) FROM biz_models WHERE name = 'Seller Model'"), '⛔ فروشنده با POSTِ دستی هم مدل نمی‌سازد');

exit(T::report());
