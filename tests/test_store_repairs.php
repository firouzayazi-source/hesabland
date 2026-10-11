<?php
/**
 * تستِ «تعمیرات و گارانتی» — `BizRepairs`، `BizWarranty`، `BizInvoices::stampWarranty()`.
 *
 * ⛔ می‌سنجد: قبضِ پذیرش (شماره، سنجه‌ها، جداییِ فروشگاه‌ها)، وضعیت (تحویل بی‌فاکتورِ صادرشده
 *    نه، قبضِ بسته فقط با بازگشاییِ صاحب)، هزینه = پیش‌نویسِ فروشِ واقعی (یک بار)، گارانتیِ
 *    عکس‌گرفته هنگامِ صدور (تغییرِ بعدیِ کالا اثر ندارد)، و استعلام با IMEI؛ و HTTP و دسترسی.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
T::group('تعمیرات و گارانتی');

if (!file_exists(__DIR__ . '/../config/config.php')) { T::blocked('تعمیرات', 'config/config.php وجود ندارد'); exit(T::report()); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/biz.php';
require_once __DIR__ . '/../includes/biz_repairs.php';
require_once __DIR__ . '/../includes/biz_staff.php';

try { $pdo = Database::getConnection(); } catch (Throwable $e) { T::blocked('تعمیرات', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }
if (!BizRepairs::ready() || !BizWarranty::ready()) { T::blocked('تعمیرات', 'migration_biz_repairs اجرا نشده'); exit(T::report()); }

const RP_PREFIX = '__store_repair_';
const RP_PASS   = 'Repair#pass9';
const IMA = '356938035643809';
const IMB = '490154203237518';
$wipe = function () use ($pdo) {
    foreach ($pdo->query("SELECT id FROM users WHERE username LIKE '" . RP_PREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN) as $id) { deleteUserAccount((int)$id); }
};
$wipe();
register_shutdown_function($wipe);
$one = function (string $sql, array $p = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn(); };
$mk = function (string $n) use ($pdo): int {
    $id = (int)createUserAccount($pdo, 'فروشگاه ' . $n, RP_PREFIX . $n, RP_PREFIX . $n . '@example.com', RP_PASS)['id'];
    Biz::setType($id, 'business');
    return $id;
};
$u = $mk('own'); $o = $mk('oth');
$cus = (int)BizParties::save($u, ['name' => 'مشتریِ تعمیر', 'kind' => 'customer', 'phone' => '09120000000'])['id'];
$sup = (int)BizParties::save($u, ['name' => 'پخش', 'kind' => 'supplier'])['id'];
$cash = (int)BizCash::list($u)[0]['id'];

// ---- ۱. قبض ----
$base = ['device' => 'سامسونگ A54', 'problem' => 'شکستگیِ صفحه', 'customer_name' => 'رضا گذری', 'customer_phone' => '۰۹۱۲۱۱۱۲۲۳۳', 'estimate' => '۱٬۵۰۰٬۰۰۰'];
$r = BizRepairs::create($u, $base + ['imei' => IMB]);
T::ok($r['ok'] && $r['number'] === 1, 'قبضِ ۱', $r['message']);
$r1 = (int)$r['id'];
$row = BizRepairs::get($u, $r1);
T::same([1500000, '09121112233', 'received'], [(int)$row['estimate'], $row['customer_phone'], $row['status']], 'برآورد و تلفن با رقمِ فارسی، وضعیتِ اول «پذیرش‌شده»');
T::same(2, BizRepairs::create($u, ['device' => 'آیفون ۱۱', 'problem' => 'باتری', 'party_id' => $cus])['number'], 'قبضِ ۲ با مشتریِ ثبت‌شده');
T::same(1, BizRepairs::create($o, $base)['number'], 'شماره‌ی هر فروشگاه جدا');
foreach ([
    'بی‌دستگاه' => ['device' => ''] + $base,
    'بی‌ایراد' => ['problem' => ''] + $base,
    'IMEIِ خراب' => ['imei' => '12345'] + $base,
    'بی‌مشتری' => ['device' => 'x', 'problem' => 'y'],
    'مشتریِ فروشگاهِ دیگر' => ['device' => 'x', 'problem' => 'y', 'party_id' => (int)BizParties::save($o, ['name' => 'غریبه', 'kind' => 'customer'])['id']],
    'تعمیرکارِ غریبه' => ['technician_id' => $o] + $base,
    'قولِ پیش از پذیرش' => ['received_on' => '1405/07/10', 'promised_on' => '1405/07/01'] + $base,
] as $why => $in) {
    T::ok(!BizRepairs::create($u, $in)['ok'], "⛔ رد: {$why}");
}
T::same(null, BizRepairs::get($o, $r1), '⛔ فروشگاهِ دیگر قبض را نمی‌بیند');
T::ok(!BizRepairs::setStatus($o, $r1, 'ready')['ok'], '⛔ و وضعیتش را عوض نمی‌کند');
T::ok(!BizRepairs::toInvoice($o, $r1)['ok'], '⛔ و برایش فاکتور نمی‌سازد');
$staff = BizStaff::add($u, ['full_name' => 'تعمیرکار', 'username' => RP_PREFIX . 'tech', 'password' => RP_PASS, 'role' => 'seller']);
$techId = (int)$one('SELECT staff_id FROM store_staff WHERE id = :i', ['i' => (int)$staff['id']]);
T::ok(BizRepairs::update($u, $r1, $base + ['imei' => IMB, 'technician_id' => $techId])['ok'], 'همکارِ همین فروشگاه تعمیرکار می‌شود');

// ---- ۲. فهرست ----
$rc = (int)BizRepairs::create($u, ['device' => 'نوکیا', 'problem' => 'آب‌خوردگی', 'customer_name' => 'بی‌تعمیر'])['id'];
BizRepairs::setStatus($u, $rc, 'cancelled');
T::same(2, BizRepairs::list($u, 'open')['total'], '⛔ دو قبضِ باز (قبضِ «بی‌تعمیر برگشت» باز نیست)');
T::same(3, BizRepairs::list($u, '')['total'], 'همه: سه قبض');
T::same([$r1], array_map(fn($x) => (int)$x['id'], BizRepairs::list($u, '', IMB)['rows']), 'جست‌وجو با IMEI');
T::same([$r1], array_map(fn($x) => (int)$x['id'], BizRepairs::list($u, '', '1112233')['rows']), 'جست‌وجو با تلفن');

// ---- ۳. هزینه و تحویل ----
$stockBefore = (int)$one('SELECT COUNT(*) FROM biz_stock_moves WHERE user_id = :u', ['u' => $u]);
$ti = BizRepairs::toInvoice($u, $r1);
T::ok($ti['ok'], 'فاکتورِ هزینه ساخته شد', $ti['message']);
$inv = (int)$ti['invoice_id'];
T::same(['sale', 'draft'], [(string)$one('SELECT kind FROM biz_invoices WHERE id = :i', ['i' => $inv]), (string)$one('SELECT status FROM biz_invoices WHERE id = :i', ['i' => $inv])], '⛔ هزینه = پیش‌نویسِ فاکتورِ فروش');
T::same('1500000', (string)$one('SELECT total FROM biz_invoices WHERE id = :i', ['i' => $inv]), 'به مبلغِ برآورد');
T::same($inv, (int)BizRepairs::toInvoice($u, $r1)['invoice_id'], '⛔ دوباره زدن فاکتورِ دوم نمی‌سازد');
T::ok(!BizRepairs::setStatus($u, $r1, 'delivered')['ok'], '⛔ تحویل با فاکتورِ هنوز پیش‌نویس رد');
T::ok(BizInvoices::issue($u, $inv, ['account_id' => $cash, 'full' => true])['ok'], 'صدورِ فاکتورِ تعمیر');
T::ok(BizRepairs::setStatus($u, $r1, 'delivered')['ok'], 'حالا تحویل');
T::ok($one('SELECT delivered_at FROM biz_repairs WHERE id = :i', ['i' => $r1]) !== null, 'زمانِ تحویل ثبت شد');
T::ok(!BizRepairs::update($u, $r1, $base)['ok'], '⛔ قبضِ تحویل‌شده ویرایش نمی‌شود');
$_SESSION = ['user_id' => $techId, 'biz_owner' => $u, 'biz_role' => 'seller'];
T::ok(!BizRepairs::setStatus($u, $r1, 'repairing')['ok'], '⛔ فروشنده قبضِ بسته را باز نمی‌کند');
$_SESSION = [];
T::ok(BizRepairs::setStatus($u, $r1, 'repairing')['ok'], 'صاحب بازش می‌کند');
T::same(null, $one('SELECT delivered_at FROM biz_repairs WHERE id = :i', ['i' => $r1]), 'زمانِ تحویل پاک شد');
T::ok(!BizRepairs::setStatus($u, $r1, 'x')['ok'], 'وضعیتِ ناشناخته رد');

// ---- ۴. گارانتی ----
$ph = (int)BizProducts::save($u, ['type' => 'phone', 'name' => 'گوشیِ گارانتی‌دار', 'unit' => 'دستگاه', 'buy_price' => '100', 'sell_price' => '200'])['id'];
T::ok(BizWarranty::saveProduct($u, $ph, '۱۸', 'شرکتی')['ok'], 'گارانتیِ ۱۸ ماه شرکتی روی کالا');
T::ok(!BizWarranty::saveProduct($u, $ph, '500', '')['ok'], 'گارانتیِ بیش از سقف رد');
$pu = (int)BizInvoices::saveDraft($u, 'purchase', ['party_id' => $sup, 'inv_date' => '2026-01-01', 'lines' => [['item' => 'گوشیِ گارانتی‌دار', 'qty' => '1', 'price' => '100', 'imei1' => IMA]]])['id'];
T::ok(BizInvoices::issue($u, $pu, ['amount' => '0'])['ok'], 'خریدِ گوشی');
T::same('in_stock', BizWarranty::lookup($u, IMA)['state'], 'پیش از فروش: در انبار');
$sd = '2026-01-15';
$sa = BizInvoices::saveDraft($u, 'sale', ['party_id' => $cus, 'inv_date' => $sd, 'lines' => [['item' => 'گوشیِ گارانتی‌دار', 'qty' => '1', 'price' => '200', 'imei1' => IMA]]]);
T::ok($sa['ok'], 'پیش‌نویسِ فروش', $sa['message'] ?? '');
T::same(null, $one('SELECT warranty_months FROM biz_invoice_lines WHERE invoice_id = :i', ['i' => (int)$sa['id']]), 'پیش‌نویس هنوز عکسِ گارانتی ندارد');
$is = BizInvoices::issue($u, (int)$sa['id'], ['account_id' => $cash, 'full' => true]);
T::ok($is['ok'], 'فروشِ گوشی', $is['message']);
T::same('18', (string)$one('SELECT warranty_months FROM biz_invoice_lines WHERE invoice_id = :i', ['i' => (int)$sa['id']]), '⛔ صدور عکسِ گارانتی را روی ردیف گذاشت');
BizWarranty::saveProduct($u, $ph, '6', '');
$w = BizWarranty::lookup($u, IMA, '2026-06-01');
T::same(['sold', 18, '2027-07-15', true], [$w['state'], $w['months'], $w['until'], $w['valid']], '⛔ تغییرِ بعدیِ کالا گارانتیِ فروخته را عوض نکرد');
T::same(false, BizWarranty::lookup($u, IMA, '2027-07-16')['valid'], 'روزِ بعد از پایان: تمام شده');
T::ok(BizInvoices::unissue($u, (int)$sa['id'])['ok'], 'برگشتِ فروش به پیش‌نویس (اصلاح)');
T::ok(BizInvoices::issue($u, (int)$sa['id'], ['account_id' => $cash, 'full' => true])['ok'], 'صدورِ دوباره');
T::same(18, BizWarranty::lookup($u, IMA, '2026-06-01')['months'], '⛔ صدورِ دوباره عکسِ ۱۸ ماه را با ۶ ماهِ امروزِ کالا عوض نکرد');
T::same('مشتریِ تعمیر', BizWarranty::lookup($u, IMA)['party'], 'خریدار در استعلام');
T::same('unknown', BizWarranty::lookup($u, '353918057426721')['state'], 'IMEIِ ناشناخته');
T::same(null, BizWarranty::lookup($u, '12'), 'IMEIِ نامعتبر');
T::same('unknown', BizWarranty::lookup($o, IMA)['state'], '⛔ فروشگاهِ دیگر گوشیِ این فروشگاه را نمی‌شناسد');
T::same('2026-02-28', BizWarranty::until('2026-01-31', 1) <= '2026-03-03' ? '2026-02-28' : 'x', 'پایانِ ماهِ کوتاه سرریزِ غیرعادی ندارد');

// ---- ۵. HTTP ----
$root = dirname(__DIR__);
$port = 0;
for ($p = 9560; $p <= 9600; $p++) { $s = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2); if ($s) { fclose($s); $port = $p; break; } }
if (!$port) { T::blocked('تعمیرات (HTTP)', 'پورت آزاد پیدا نشد'); exit(T::report()); }
$log = tempnam(sys_get_temp_dir(), 'rplog');
$srv = (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!', $port, escapeshellarg($root), escapeshellarg($log))));
register_shutdown_function(static function () use ($srv, $log) { exec("kill {$srv} 2>/dev/null"); @unlink($log); });
for ($i = 0, $up = false; $i < 40 && !$up; $i++) { usleep(150000); $sk = @fsockopen('127.0.0.1', $port, $a, $b, 0.3); if ($sk) { fclose($sk); $up = true; } }
if (!$up) { T::blocked('تعمیرات (HTTP)', 'سرور بالا نیامد'); exit(T::report()); }
$client = function () use ($port) {
    $jar = tempnam(sys_get_temp_dir(), 'rpjar');
    return function (string $path, ?array $post = null) use ($port, $jar): array {
        $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 40, CURLOPT_HEADER => true]);
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $body = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return [$code, $body];
    };
};
$login = function (callable $req, string $user) {
    [, $lp] = $req('store/login.php');
    $tok = preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $lp, $m) ? $m[1] : '';
    $req('store/login.php', ['csrf_token' => $tok, 'username' => $user, 'password' => RP_PASS]);
};
$own = $client(); $login($own, RP_PREFIX . 'own');
[$c, $html] = $own('store/repairs.php?w=' . IMA);
T::ok($c === 200 && str_contains($html, 'گارانتی دارد') && str_contains($html, toPersianDigits('18') . ' ماه'), '⛔ صفحه: استعلامِ گارانتی (۱۸ ماهِ فروخته، نه ۶ ماهِ امروزِ کالا)', (string)$c);
T::ok(str_contains($html, 'آیفون ۱۱'), 'قبضِ باز در فهرست');
[$c, $html] = $own('store/repair.php');
$tok = preg_match('/name="csrf-token" content="([^"]+)"/', $html, $m) ? $m[1] : '';
$once = preg_match('/name="_once" value="([^"]+)"/', $html, $m) ? $m[1] : '';
[$c, $raw] = $own('store/repair.php', ['csrf_token' => $tok, '_once' => $once, 'print' => '1', 'device' => 'شیائومی نوت ۱۲', 'problem' => 'شارژ نمی‌شود',
    'customer_name' => 'مینا', 'customer_phone' => '09350000000', 'received_on' => toJalali(today())]);
T::ok($c === 302 && preg_match('~print\.php\?doc=repair&id=(\d+)~', $raw, $mm) === 1, '⛔ «ثبت و چاپِ قبض» به برگه‌ی قبض می‌رود', (string)$c);
[$c, $html] = $own('store/print.php?doc=repair&id=' . ($mm[1] ?? 0));
T::ok($c === 200 && str_contains($html, 'شیائومی نوت ۱۲') && str_contains($html, 'شارژ نمی‌شود'), 'برگه‌ی قبض: دستگاه و ایراد');
$tech = $client(); $login($tech, RP_PREFIX . 'tech');
[$c] = $tech('store/repairs.php');
T::same(200, $c, 'فروشنده به تعمیرات دسترسی دارد');
BizStaff::add($u, ['full_name' => 'حسابدار', 'username' => RP_PREFIX . 'acc', 'password' => RP_PASS, 'role' => 'accountant']);
$acc = $client(); $login($acc, RP_PREFIX . 'acc');
[$c] = $acc('store/repairs.php');
T::same(403, $c, '⛔ حسابدار (بی‌دسترسیِ تعمیرات) نه');
[$c] = $acc('store/print.php?doc=repair&id=' . $r1);
T::same(403, $c, '⛔ و قبضِ چاپی هم نه');

exit(T::report());
