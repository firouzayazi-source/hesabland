<?php
/**
 * تستِ «چند انبار» — `BizWarehouses`، انتقال، و سدِ منفیِ هر انبار در `BizStock::write()`.
 *
 * ⛔ فروشگاهِ تک‌انبار هیچ تفاوتی نمی‌بیند؛ انتقال موجودیِ کل و بها را دست نمی‌زند؛ هیچ
 *    انباری در هیچ روزی منفی نمی‌شود (فروش، ابطالِ خرید، حذفِ انتقال)؛ برگشت به انبارِ
 *    فاکتورِ اصل می‌رود؛ گوشی با IMEI و از همان انباری که هست جابه‌جا می‌شود؛ انبارگردانی
 *    یک انبار را می‌شمارد؛ و دفترِ دوطرفه با انبار می‌خواند.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
T::group('چند انبار و انتقال');

if (!file_exists(__DIR__ . '/../config/config.php')) { T::blocked('انبارها', 'config/config.php وجود ندارد'); exit(T::report()); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/user_import.php';
require_once __DIR__ . '/../includes/biz.php';
require_once __DIR__ . '/../includes/biz_docs.php';
require_once __DIR__ . '/../includes/biz_acc.php';
require_once __DIR__ . '/../includes/biz_docview.php';
require_once __DIR__ . '/../includes/biz_staff.php';

try { $pdo = Database::getConnection(); } catch (Throwable $e) { T::blocked('انبارها', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }
if (!BizWarehouses::ready()) { T::blocked('انبارها', 'migration_biz_warehouses اجرا نشده'); exit(T::report()); }

const WH_PREFIX = '__store_wh_';
const WH_PASS   = 'Wareh#pass9';
$wipe = function () use ($pdo) {
    foreach ($pdo->query("SELECT id FROM users WHERE username LIKE '" . WH_PREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN) as $id) { deleteUserAccount((int)$id); }
};
$wipe();
register_shutdown_function($wipe);
$one = function (string $sql, array $p = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn(); };
$mk = function (string $n) use ($pdo): int {
    $id = (int)createUserAccount($pdo, 'فروشگاه ' . $n, WH_PREFIX . $n, WH_PREFIX . $n . '@example.com', WH_PASS)['id'];
    Biz::setType($id, 'business');
    return $id;
};
$u = $mk('own'); $o = $mk('oth');
$cash = (int)BizCash::list($u)[0]['id'];
$sup = (int)BizParties::save($u, ['name' => 'پخش', 'kind' => 'supplier'])['id'];
$cus = (int)BizParties::save($u, ['name' => 'خریدار', 'kind' => 'customer'])['id'];
$pid = (int)BizProducts::save($u, ['type' => 'goods', 'name' => 'شارژر', 'unit' => 'عدد', 'buy_price' => '100', 'sell_price' => '150'])['id'];
$doc = function (string $kind, int $qty, string $date, int $wh = -1, string $item = 'شارژر', string $imei = '') use ($u, $sup, $cus): array {
    $in = ['party_id' => $kind === 'sale' ? $cus : $sup, 'inv_date' => $date,
           'lines' => [['item' => $item, 'qty' => (string)$qty, 'price' => $kind === 'sale' ? '150' : '100', 'imei1' => $imei]]];
    if ($wh >= 0) { $in['warehouse_id'] = (string)$wh; }
    $d = BizInvoices::saveDraft($u, $kind, $in);
    if (!$d['ok']) { return [0, $d]; }
    return [(int)$d['id'], BizInvoices::issue($u, (int)$d['id'], ['amount' => '0'])];
};
$qtyOf = fn(int $p): float => (float)$one('SELECT stock_qty FROM biz_products WHERE id = :i', ['i' => $p]);
$by = fn(int $p): array => BizWarehouses::walk($pdo, $u, $p)['now'];

// ---- ۱. تک‌انبار: بی‌تفاوت ----
[$p1, $r] = $doc('purchase', 10, '2026-01-05');
T::ok($r['ok'], 'خرید', $r['message'] ?? '');
[$s1, $r] = $doc('sale', 3, '2026-01-06');
T::ok($r['ok'] && !BizWarehouses::any($u), 'فروش؛ فروشگاه هنوز تک‌انبار است');
T::same(0, (int)$one('SELECT COUNT(*) FROM biz_stock_moves WHERE user_id = :u AND warehouse_id IS NOT NULL', ['u' => $u]), '⛔ تک‌انبار: هیچ حرکتی انبار نگرفت');
$avg0 = (float)$one('SELECT avg_cost FROM biz_products WHERE id = :i', ['i' => $pid]);

// ---- ۲. ساختِ انبار ----
$r = BizWarehouses::save($u, 'شعبه‌ی دو');
T::ok($r['ok'], 'انبارِ «شعبه‌ی دو»');
$B = (int)$r['id'];
foreach (['تکراری' => 'شعبه‌ی دو', 'نامِ انبارِ اصلی' => BizWarehouses::MAIN, 'بی‌نام' => '  '] as $why => $n) {
    T::ok(!BizWarehouses::save($u, $n)['ok'], "⛔ انبار رد: {$why}");
}
T::ok(BizWarehouses::save($o, 'شعبه‌ی دو')['ok'], 'فروشگاهِ دیگر همان نام را جدا دارد');
$oB = (int)$one("SELECT id FROM biz_warehouses WHERE user_id = :u", ['u' => $o]);
T::ok(!BizWarehouses::save($o, 'دست‌کاری', $B)['ok'], '⛔ فروشگاهِ دیگر نامِ انبار را عوض نمی‌کند');
T::same('شعبه‌ی دو', (string)$one('SELECT name FROM biz_warehouses WHERE id = :i', ['i' => $B]), 'و نام همان ماند');
T::ok(BizWarehouses::any($u), 'حالا چندانباره');
T::same([0 => 7.0], $by($pid), 'همه در انبارِ اصلی');

// ---- ۳. انتقال ----
$r = BizWarehouses::transfer($u, $pid, 0, $B, '۴', '2026-01-07', 'برای شعبه');
T::ok($r['ok'], 'انتقالِ ۴ به شعبه', $r['message']);
$t1 = (int)$r['id'];
T::same([0 => 3.0, $B => 4.0], $by($pid), '⛔ اصلی ۳، شعبه ۴');
T::same([7.0, $avg0], [$qtyOf($pid), (float)$one('SELECT avg_cost FROM biz_products WHERE id = :i', ['i' => $pid])], '⛔ موجودیِ کل و میانگینِ بها دست نخورد');
foreach ([
    'بیش از موجودیِ مبدأ'   => [0, $B, '4'],
    'مبدأ و مقصد یکی'       => [$B, $B, '1'],
    'مقدارِ صفر'            => [0, $B, '0'],
    'اعشار برای «عدد»'      => [0, $B, '1.5'],
    'انبارِ فروشگاهِ دیگر'  => [0, $oB, '1'],
] as $why => [$f, $t, $q]) {
    $n0 = (int)$one('SELECT COUNT(*) FROM biz_stock_transfers WHERE user_id = :u', ['u' => $u]);
    T::ok(!BizWarehouses::transfer($u, $pid, $f, $t, $q, '2026-01-07')['ok'], "⛔ انتقال رد: {$why}");
    T::same($n0, (int)$one('SELECT COUNT(*) FROM biz_stock_transfers WHERE user_id = :u', ['u' => $u]), "و ردی نماند ({$why})");
}
T::ok(!BizWarehouses::transfer($u, $pid, $B, 0, '1', '2026-01-06')['ok'], '⛔ انتقال پیش از رسیدنِ کالا به آن انبار (روزِ قبل) رد');
T::ok(!BizWarehouses::transfer($o, $pid, 0, $oB, '1', '2026-01-07')['ok'], '⛔ کالای فروشگاهِ دیگر منتقل نمی‌شود');

// ---- ۴. فروش از هر انبار ----
[$sx, $r] = $doc('sale', 4, '2026-01-08', 0);
T::ok(!$r['ok'], '⛔ فروشِ ۴ از اصلی (۳ دارد) رد — هرچند کل ۷ است', $r['message'] ?? '');
T::same('draft', (string)$one('SELECT status FROM biz_invoices WHERE id = :i', ['i' => $sx]), 'پیش‌نویس ماند');
BizInvoices::deleteDraft($u, $sx);
[$s2, $r] = $doc('sale', 4, '2026-01-08', $B);
T::ok($r['ok'], 'فروشِ ۴ از شعبه', $r['message'] ?? '');
T::same([$B], array_map('intval', $pdo->query("SELECT DISTINCT warehouse_id FROM biz_stock_moves WHERE ref_id = {$s2} AND ref_type = 'invoice'")->fetchAll(PDO::FETCH_COLUMN)), '⛔ حرکتِ فروش انبارِ سند را گرفت');
T::same([0 => 3.0, $B => 0.0], $by($pid), 'شعبه خالی');
T::ok(!BizInvoices::saveDraft($u, 'sale', ['party_id' => $cus, 'warehouse_id' => (string)$oB, 'lines' => [['item' => 'شارژر', 'qty' => '1', 'price' => '1']]])['ok'], '⛔ انبارِ فروشگاهِ دیگر روی فاکتور رد');

// ---- ۵. برگشت به همان انبار ----
$ln = (int)$one('SELECT id FROM biz_invoice_lines WHERE invoice_id = :i', ['i' => $s2]);
$rr = BizInvoices::createReturn($u, $s2, [$ln => '1'], [], '2026-01-09');
T::ok($rr['ok'], 'برگشتِ یکی', $rr['message'] ?? '');
T::same([0 => 3.0, $B => 1.0], $by($pid), '⛔ برگشت به انبارِ فاکتورِ اصل (شعبه) رفت');

// ---- ۶. ابطالِ خرید و حذفِ انتقال ----
[$p2, $r] = $doc('purchase', 2, '2026-01-10', $B);
T::ok($r['ok'], 'خریدِ ۲ به شعبه');
T::ok(BizWarehouses::transfer($u, $pid, $B, 0, '3', '2026-01-11')['ok'], 'انتقالِ ۳ از شعبه به اصلی');
T::ok(!BizInvoices::void($u, $p2)['ok'], '⛔ ابطالِ خریدی که شعبه را منفی می‌کند رد (کل منفی نمی‌شود)');
T::same('issued', (string)$one('SELECT status FROM biz_invoices WHERE id = :i', ['i' => $p2]), 'خرید صادر ماند');
T::ok(!BizWarehouses::removeTransfer($u, $t1)['ok'], '⛔ حذفِ انتقالی که شعبه را منفی می‌کند رد');
T::ok(!BizWarehouses::removeTransfer($o, $t1)['ok'], '⛔ فروشگاهِ دیگر انتقال را حذف نمی‌کند');

// ---- ۷. انبارگردانیِ یک انبار ----
$before = $by($pid);
$r = BizStock::adjustTo($u, $pid, 5, 'شمارشِ شعبه', $B);
T::ok($r['ok'], 'انبارگردانیِ شعبه', $r['message']);
$after = $by($pid);
T::same([$before[0], 5.0], [$after[0], $after[$B]], '⛔ فقط شعبه عوض شد');
T::same(array_sum($after), $qtyOf($pid), 'کل = جمعِ انبارها');
$r = BizStock::adjustTo($u, $pid, (float)$after[0], 'شمارشِ اصلی');
T::ok($r['ok'] && str_contains($r['message'], 'چیزی عوض نشد'), 'شمارشِ اصلی با همان عدد: بی‌تغییر (نه با کل)');

// ---- ۸. گوشی ----
$ph = (int)BizProducts::save($u, ['type' => 'phone', 'name' => 'گوشیِ الف', 'unit' => 'دستگاه', 'buy_price' => '1000', 'sell_price' => '1500'])['id'];
$IM = '356938035643809';
[$pp, $r] = $doc('purchase', 1, '2026-01-12', 0, 'گوشیِ الف', $IM);
T::ok($r['ok'], 'خریدِ گوشی به اصلی', $r['message'] ?? '');
T::ok(!BizWarehouses::transfer($u, $ph, 0, $B, '1', '2026-01-13')['ok'], '⛔ گوشی بی‌IMEI منتقل نمی‌شود');
T::ok(!BizWarehouses::transfer($u, $ph, $B, 0, '1', '2026-01-13', '', $IM)['ok'], '⛔ از انباری که گوشی در آن نیست رد');
T::ok(BizWarehouses::transfer($u, $ph, 0, $B, '1', '2026-01-13', '', $IM)['ok'], 'گوشی به شعبه');
T::same($B, BizWarehouses::phoneAt($pdo, $u, $IM), '⛔ گوشی حالا در شعبه است');
$IM2 = '353918057426721';
[$pp2, $r] = $doc('purchase', 1, '2026-01-13', 0, 'گوشیِ الف', $IM2);
T::ok($r['ok'], 'گوشیِ دوم در اصلی');
T::ok(!BizWarehouses::transfer($u, $ph, 0, $B, '1', '2026-01-14', '', $IM)['ok'], '⛔ انتقالِ دوباره از اصلی رد (اصلی گوشیِ دیگری دارد، نه این)');
T::ok(BizWarehouses::transfer($u, $ph, 0, $B, '1', '2026-01-14', '', $IM2)['ok'] === true
    && BizWarehouses::transfer($u, $ph, $B, 0, '2', '2026-01-14', '', $IM2)['ok'] === false, '⛔ گوشی یکی‌یکی (دو تا با یک IMEI رد)');
[$sp, $r] = $doc('sale', 1, '2026-01-15', 0, 'گوشیِ الف', $IM);
T::ok(!$r['ok'], '⛔ فروشِ گوشی از اصلی (در شعبه است) رد');
BizInvoices::deleteDraft($u, $sp);

// ---- ۹. غیرفعال، قفل، دفتر ----
T::ok(!BizWarehouses::setActive($u, $B, false)['ok'], '⛔ انبارِ دارای کالا غیرفعال نمی‌شود');
$C = (int)BizWarehouses::save($u, 'انبارِ خالی')['id'];
T::ok(BizWarehouses::setActive($u, $C, false)['ok'], 'انبارِ خالی غیرفعال شد');
T::ok(!BizWarehouses::transfer($u, $pid, 0, $C, '1', '2026-01-16')['ok'], '⛔ به انبارِ غیرفعال منتقل نمی‌شود');
$t9 = (int)BizWarehouses::transfer($u, $pid, 0, $B, '1', '2026-01-17')['id'];
T::ok($t9 > 0, 'انتقالِ حذف‌شدنی');
Biz::saveLock($u, '2026-01-31');
T::ok(!BizWarehouses::transfer($u, $pid, 0, $B, '1', '2026-01-20')['ok'], '⛔ انتقال در دوره‌ی بسته رد');
T::ok(!BizWarehouses::removeTransfer($u, $t9)['ok'], '⛔ حذفِ انتقالِ دوره‌ی بسته رد');
Biz::saveLock($u, '', true);
T::ok(BizWarehouses::removeTransfer($u, $t9)['ok'], 'بعد از باز کردنِ دوره حذف می‌شود');
$rec = BizLedger::reconcile($u);
T::same(0, (int)$rec['stock']['diff'], '⛔ ارزشِ انبار در دفتر با کالاها یکی (انتقال اثرِ پولی ندارد)');
T::ok(BizLedger::balanced(BizLedger::entries($u, BizReports::ALL_TO)), 'دفتر تراز');

// ---- ۱۰. بکاپ ----
$fk = importForeignKeys();
T::same(['biz_warehouses', 'biz_warehouses', 'biz_products'], [$fk['biz_stock_moves']['warehouse_id'] ?? null, $fk['biz_stock_transfers']['to_wh'] ?? null, $fk['biz_stock_transfers']['product_id'] ?? null],
    '⛔ پیوندِ انبار در نگاشتِ بازگرداندن');

// ---- ۱۱. HTTP ----
$st = BizStaff::add($u, ['full_name' => 'فروشنده', 'username' => WH_PREFIX . 'sel', 'password' => WH_PASS, 'role' => 'seller']);
$root = realpath(__DIR__ . '/..');
$port = 0;
for ($p = 9620; $p <= 9660; $p++) { $sk = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2); if ($sk) { fclose($sk); $port = $p; break; } }
if (!$port) { T::blocked('انبارها (HTTP)', 'پورت آزاد پیدا نشد'); exit(T::report()); }
$log = tempnam(sys_get_temp_dir(), 'whlog');
$srv = (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!', $port, escapeshellarg($root), escapeshellarg($log))));
register_shutdown_function(static function () use ($srv, $log) { exec("kill {$srv} 2>/dev/null"); @unlink($log); });
for ($i = 0, $up = false; $i < 40 && !$up; $i++) { usleep(150000); $sk = @fsockopen('127.0.0.1', $port, $a1, $b1, 0.3); if ($sk) { fclose($sk); $up = true; } }
T::ok($up, 'سرورِ آزمایشی بالا آمد');
if (!$up) { exit(T::report()); }
$client = function () use ($port) {
    $jar = tempnam(sys_get_temp_dir(), 'whjar');
    return function (string $path, ?array $post = null) use ($port, $jar): array {
        $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 40]);
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $body = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return [$code, $body];
    };
};
$login = function (callable $req, string $user) {
    [, $lp] = $req('store/login.php');
    $req('store/login.php', ['csrf_token' => preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $lp, $m) ? $m[1] : '', 'username' => $user, 'password' => WH_PASS]);
};
$own = $client(); $login($own, WH_PREFIX . 'own');
[$c, $h] = $own('store/warehouses.php');
T::ok($c === 200 && str_contains($h, '</html>') && str_contains($h, 'شعبه‌ی دو') && str_contains($h, 'موجودی به تفکیکِ انبار'), 'صفحه‌ی انبارها', (string)$c);
$tok = preg_match('/name="csrf-token" content="([^"]+)"/', $h, $m) ? $m[1] : '';
$n0 = (int)$one('SELECT COUNT(*) FROM biz_stock_transfers WHERE user_id = :u', ['u' => $u]);
$own('store/warehouses.php', ['csrf_token' => $tok, 'action' => 'transfer', 'item' => 'شارژر', 'qty' => '1', 'from' => '0', 'to' => (string)$B,
    'tdate' => BizDocView::jDate('2026-02-01'), 'note' => '', 'imei' => '']);
T::same($n0 + 1, (int)$one('SELECT COUNT(*) FROM biz_stock_transfers WHERE user_id = :u', ['u' => $u]), 'انتقال از فرم (کالا با نام)');
[$c, $h] = $own('store/invoice-edit.php?k=sale');
T::ok(str_contains($h, 'name="warehouse_id"'), 'فرمِ فاکتورِ فروشگاهِ چندانباره انبار دارد');
[$c, $h] = $own('store/product.php?id=' . $pid);
T::ok(str_contains($h, 'کدام انبار شمرده شد'), 'انبارگردانیِ کالا انبار را می‌پرسد');
$sel = $client(); $login($sel, WH_PREFIX . 'sel');
[$c] = $sel('store/warehouses.php');
T::same(403, $c, '⛔ فروشنده (بی‌دسترسیِ کالا) صفحه‌ی انبارها را نمی‌بیند');
$oc = $client(); $login($oc, WH_PREFIX . 'oth');
$mk2 = $mk('one');
$one1 = $client(); $login($one1, WH_PREFIX . 'one');
[$c, $h] = $one1('store/invoice-edit.php?k=sale');
T::ok($c === 200 && !str_contains($h, 'name="warehouse_id"'), '⛔ فروشگاهِ تک‌انبار انتخابِ انبار نمی‌بیند');
$sl = (string)@file_get_contents($log);
T::ok(!preg_match('/PHP (Fatal|Warning|Notice|Deprecated)/', $sl), 'لاگِ سرور بی‌هشدار و بی‌خطا', mb_substr($sl, 0, 600));

$wipe();
exit(T::report());
