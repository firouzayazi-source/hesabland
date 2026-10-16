<?php
/**
 * ⛔ چند فروشگاه با یک حساب (`Biz::createShop()`/`switchShop()`، migration_biz_shops).
 *
 * **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵):** دو فروشگاهِ جدا با یک ورود (مثلِ «چند شرکت» در قیاس).
 *
 *   ۱. ساختن: حسابِ داده‌ی بی‌رمز و بی‌شماره، «فقط فروشگاه»، سربرگ با همان نام؛ سقف.
 *   ۲. با HTTPِ واقعی: رفتن به فروشگاهِ دوم ⇒ داده‌ی جدا (کالا، صندوق)؛ برگشت؛ نشستِ دست‌کاری‌شده
 *      به فروشگاهِ دیگری نمی‌رسد؛ فروشگاهِ کسِ دیگر انتخاب‌شدنی نیست.
 *   ۳. همکار صفحه را نمی‌بیند و فروشگاه عوض نمی‌کند.
 *   ۴. حسابِ داده خودش وارد نمی‌شود.
 *   ۵. بکاپ مالِ همان فروشگاه؛ `store_shops` از فایل نوشته نمی‌شود؛ حذفِ صاحب فروشگاه‌هایش را می‌برد.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
T::group('چند فروشگاه');
if (!file_exists(__DIR__ . '/../config/config.php')) { T::blocked('چند فروشگاه', 'config/config.php وجود ندارد'); exit(T::report()); }
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

try { $pdo = Database::getConnection(); } catch (Throwable $e) { T::blocked('چند فروشگاه', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }
if (!Biz::shopsReady() || !Biz::staffReady()) { T::blocked('چند فروشگاه', 'migration_biz_shops اجرا نشده'); exit(T::report()); }

const SH_PREFIX = '__store_shops_';
const SH_PASS   = 'Shops#pass9';
$wipe = function () use ($pdo) {
    foreach ($pdo->query("SELECT id FROM users WHERE username LIKE '" . SH_PREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN) as $id) { deleteUserAccount((int)$id); }
};
$wipe();
register_shutdown_function($wipe);
$one = function (string $sql, array $p = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn(); };
$mk = function (string $n) use ($pdo): int {
    $r = createUserAccount($pdo, 'صاحب ' . $n, SH_PREFIX . $n, SH_PREFIX . $n . '@example.com', SH_PASS);
    Biz::setType((int)$r['id'], 'business');
    return (int)$r['id'];
};
$owner = $mk('own');
$other = $mk('oth');
Biz::saveSettings($owner, ['shop_name' => 'شعبه‌ی مرکزی']);
BizProducts::save($owner, ['name' => 'کالای فروشگاهِ اول', 'unit' => 'عدد', 'sell_price' => '10']);

T::group('۱ — ساختن');
T::ok(!Biz::createShop($owner, '   ')['ok'], 'نامِ خالی رد');
$r = Biz::createShop($owner, 'شعبه‌ی  دوم');
T::ok($r['ok'], 'فروشگاهِ دوم ساخته شد', $r['message']);
$S2 = (int)$r['id'];
$row = $pdo->query('SELECT account_type, password_hash, phone, email FROM users WHERE id = ' . $S2)->fetch();
T::same(['business', null, null, null], [$row['account_type'], $row['password_hash'], $row['phone'], $row['email']], '⛔ حسابِ داده: «فقط فروشگاه»، بی‌رمز، بی‌شماره، بی‌ایمیل');
T::same('شعبه‌ی دوم', (string)$one('SELECT shop_name FROM biz_settings WHERE user_id = :u', ['u' => $S2]), 'سربرگ با همان نام (فاصله‌ی اضافه حذف)');
T::same([$owner, $S2], array_column(Biz::shops($owner), 'id'), 'فهرست: اول فروشگاهِ اصلی، بعد دوم');
T::same('شعبه‌ی مرکزی', Biz::shops($owner)[0]['name'], 'نامِ اصلی از سربرگش');
T::same([$other], array_column(Biz::shops($other), 'id'), 'فروشگاهِ کسِ دیگر در فهرستِ او نیست');
BizProducts::save($S2, ['name' => 'کالای فروشگاهِ دوم', 'unit' => 'عدد', 'sell_price' => '20']);

T::group('۲ — رفتن و برگشتن (HTTP)');
$root = realpath(__DIR__ . '/..');
$port = 18000 + random_int(0, 899);
$log  = tempnam(sys_get_temp_dir(), 'shlog');
$srv = (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!', $port, escapeshellarg($root), escapeshellarg($log))));
register_shutdown_function(static function () use ($srv, $log) { exec("kill {$srv} 2>/dev/null"); @unlink($log); });
for ($i = 0, $up = false; $i < 40 && !$up; $i++) { usleep(150000); $sk = @fsockopen('127.0.0.1', $port, $a, $b, 0.3); if ($sk) { fclose($sk); $up = true; } }
T::ok($up, 'سرورِ آزمایشی بالا آمد');
if (!$up) { exit(T::report()); }
$client = function () use ($port) {
    $jar = tempnam(sys_get_temp_dir(), 'shjar');
    return function (string $path, ?array $post = null) use ($port, $jar): array {
        $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 40]);
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $body = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); unset($ch);
        return [$code, $body];
    };
};
$login = function (callable $req, string $user, string $pass = SH_PASS) {
    [, $lp] = $req('store/login.php');
    $tok = preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $lp, $m) ? $m[1] : '';
    return $req('store/login.php', ['csrf_token' => $tok, 'username' => $user, 'password' => $pass]);
};
$tok = fn(string $html): string => preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m) ? $m[1] : '';

$o = $client(); $login($o, SH_PREFIX . 'own');
[$c, $h] = $o('store/products.php');
T::ok($c === 200 && str_contains($h, 'کالای فروشگاهِ اول') && !str_contains($h, 'کالای فروشگاهِ دوم'), 'در فروشگاهِ اصلی: فقط کالای خودش');
[$c, $h] = $o('store/index.php');
T::ok(str_contains($h, Biz::url('shops.php')), 'منوی حساب «فروشگاه‌های من» دارد');
[$c, $h] = $o('store/shops.php');
T::ok($c === 200 && str_contains($h, 'شعبه‌ی دوم') && str_contains($h, 'شعبه‌ی مرکزی'), 'صفحه‌ی فروشگاه‌ها هر دو را دارد');
$o('store/shops.php', ['csrf_token' => $tok($h), 'action' => 'switch', 'id' => (string)$S2]);
[$c, $h] = $o('store/products.php');
T::ok($c === 200 && str_contains($h, 'کالای فروشگاهِ دوم') && !str_contains($h, 'کالای فروشگاهِ اول'), '⛔ در فروشگاهِ دوم: فقط کالای دوم');
T::ok(str_contains($h, 'شعبه‌ی دوم'), 'پوسته نامِ فروشگاهِ دوم را نشان می‌دهد');
// فاکتور در فروشگاهِ دوم زیرِ حسابِ داده‌ی دوم نوشته می‌شود
[, $h] = $o('store/invoice-edit.php?k=sale');
$o('store/invoice-edit.php?k=sale', ['csrf_token' => $tok($h), 'once' => preg_match('/name="once"[^>]*value="([^"]+)"/', $h, $m) ? $m[1] : '',
    'action' => 'draft', 'lines' => [['item' => 'کالای فروشگاهِ دوم', 'qty' => '1', 'price' => '20']]]);
T::ok((int)$one('SELECT COUNT(*) FROM biz_invoices WHERE user_id = :u', ['u' => $S2]) >= 1 && (int)$one('SELECT COUNT(*) FROM biz_invoices WHERE user_id = :u', ['u' => $owner]) === 0,
    '⛔ سندِ ساخته‌شده در فروشگاهِ دوم زیرِ همان فروشگاه است، نه اصلی');
// فروشگاهِ کسِ دیگر
[, $h] = $o('store/shops.php');
$o('store/shops.php', ['csrf_token' => $tok($h), 'action' => 'switch', 'id' => (string)$other]);
[, $h] = $o('store/products.php');
T::ok(!str_contains($h, 'کالای') || str_contains($h, 'کالای فروشگاهِ دوم'), '⛔ فروشگاهِ کسِ دیگر انتخاب نشد (همان فروشگاهِ دوم ماند)');
T::same(['ok' => false], array_intersect_key(Biz::switchShop($owner, $other), ['ok' => 1]), '⛔ switchShop فروشگاهِ کسِ دیگر را رد می‌کند');
// برگشت به اصلی
[, $h] = $o('store/shops.php');
$o('store/shops.php', ['csrf_token' => $tok($h), 'action' => 'switch', 'id' => (string)$owner]);
[, $h] = $o('store/products.php');
T::ok(str_contains($h, 'کالای فروشگاهِ اول') && !str_contains($h, 'کالای فروشگاهِ دوم'), 'برگشت به فروشگاهِ اصلی');
// پیوند برداشته شود ⇒ نشستی که هنوز آن را انتخاب کرده به اصلی برمی‌گردد
[, $h] = $o('store/shops.php');
$o('store/shops.php', ['csrf_token' => $tok($h), 'action' => 'switch', 'id' => (string)$S2]);
$pdo->prepare('UPDATE store_shops SET user_id = :x WHERE shop_id = :s')->execute(['x' => $other, 's' => $S2]);
[, $h] = $o('store/products.php');
T::ok(str_contains($h, 'کالای فروشگاهِ اول') && !str_contains($h, 'کالای فروشگاهِ دوم'), '⛔ مالکیت هر صفحه از دیتابیس سنجیده می‌شود (نه از نشست)');
$pdo->prepare('UPDATE store_shops SET user_id = :o WHERE shop_id = :s')->execute(['o' => $owner, 's' => $S2]);

T::group('۳ — همکار');
$r = BizStaff::add($owner, ['full_name' => 'فروشنده', 'username' => SH_PREFIX . 'sel', 'password' => SH_PASS, 'role' => 'seller']);
T::ok($r['ok'], 'همکارِ فروشگاهِ اصلی', $r['message']);
$s = $client(); $login($s, SH_PREFIX . 'sel');
[$c] = $s('store/shops.php');
T::ok(in_array($c, [403, 404], true), '⛔ همکار صفحه‌ی فروشگاه‌ها را ندارد', (string)$c);
[, $h] = $s('store/index.php');
T::ok(!str_contains($h, Biz::url('shops.php')), 'و در منو هم نیست');
$selId = (int)$one('SELECT id FROM users WHERE username = :u', ['u' => SH_PREFIX . 'sel']);
$_SESSION = ['user_id' => $selId, 'biz_owner' => $owner, 'biz_role' => 'seller'];
T::ok(!Biz::switchShop($selId, $selId)['ok'] && !isset($_SESSION['biz_shop']), '⛔ switchShop() خودش هم همکار را رد می‌کند (دفاعِ دوم پشتِ صفحه)');
$_SESSION = [];

T::group('۴ — حسابِ داده وارد نمی‌شود');
$uname = (string)$one('SELECT username FROM users WHERE id = :i', ['i' => $S2]);
$x = $client();
[, $h] = $login($x, $uname, '');
[$c, $h] = $x('store/index.php');
T::ok(!str_contains($h, 'کالای فروشگاهِ دوم') && !str_contains($h, 'st-main'), '⛔ با نامِ کاربریِ حسابِ داده و رمزِ خالی وارد نمی‌شود');

T::group('۵ — بکاپ و حذف');
$ex = exportUserData($S2);
T::ok(in_array('کالای فروشگاهِ دوم', array_column($ex['tables']['biz_products'] ?? [], 'name'), true), 'بکاپِ فروشگاهِ دوم دفترِ خودش است');
$acc = (string)file_get_contents($root . '/store/account.php');
T::ok(str_contains($acc, 'sendUserExport(Biz::ownerId())'), '⛔ «رمز و بکاپ» بکاپِ همان فروشگاهِ جاری را می‌دهد');
T::ok(userImportSkipped('store_shops'), '⛔ store_shops از فایل نوشته نمی‌شود (اجازه است، نه دفتر)');
$r3 = Biz::createShop($owner, 'سوم');
for ($i = 0; $i < Biz::SHOPS_MAX; $i++) { Biz::createShop($owner, 'اضافه ' . $i); }
T::same(Biz::SHOPS_MAX, (int)$one('SELECT COUNT(*) FROM store_shops WHERE user_id = :u', ['u' => $owner]), '⛔ سقفِ فروشگاه‌های اضافه');
$ids = array_map('intval', $pdo->query('SELECT shop_id FROM store_shops WHERE user_id = ' . $owner)->fetchAll(PDO::FETCH_COLUMN));
$d = deleteUserAccount($owner);
T::ok($d['ok'], 'حذفِ صاحب', $d['reason'] ?? '');
T::same(0, (int)$one('SELECT COUNT(*) FROM users WHERE id IN (' . implode(',', $ids) . ')'), '⛔ همه‌ی فروشگاه‌های اضافه‌اش هم رفتند');
T::same(0, (int)$one('SELECT COUNT(*) FROM biz_products WHERE user_id = :u', ['u' => $S2]), 'و داده‌شان');
T::ok((int)$one('SELECT COUNT(*) FROM users WHERE id = :u', ['u' => $other]) === 1, 'صاحبِ دیگر دست نخورد');

exit(T::report());
