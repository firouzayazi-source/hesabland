<?php
/**
 * تستِ «سطحِ قیمت، سقفِ اعتبار و پورسانت» — `BizProducts::LEVELS`، `BizParties::creditError()`،
 * `BizStaff::commission()`.
 *
 * ⛔ قیمتِ سطحِ مشتری فقط وقتی ردیفِ فاکتور بی‌قیمت است می‌نشیند و سطحِ خالیِ کالا = قیمتِ
 *    فروش (نه صفر)؛ فروشِ نسیه‌ای که مانده را از سقف بالاتر ببرد صادر نمی‌شود (پیش‌نویس می‌ماند
 *    و شماره نمی‌گیرد) مگر با «گذر از سقف» — که فقط دسترسیِ مالی دارد، حتی اگر فروشنده
 *    فیلدش را دستی بفرستد؛ پورسانت روی فروشِ خالصِ بی‌مالیات است و برگشتی از سازنده‌ی
 *    **فاکتورِ اصل** کم می‌شود.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
T::group('سطحِ قیمت، سقفِ اعتبار و پورسانت');

if (!file_exists(__DIR__ . '/../config/config.php')) { T::blocked('قیمت و اعتبار', 'config/config.php وجود ندارد'); exit(T::report()); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/biz.php';
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_docs.php';
require_once __DIR__ . '/../includes/biz_docview.php';
require_once __DIR__ . '/../includes/biz_staff.php';

try { $pdo = Database::getConnection(); } catch (Throwable $e) { T::blocked('قیمت و اعتبار', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }
if (!BizProducts::levelsReady() || !BizParties::termsReady()) { T::blocked('قیمت و اعتبار', 'migration_biz_pricing اجرا نشده'); exit(T::report()); }
if (!Biz::staffReady()) { T::blocked('قیمت و اعتبار', 'migration_biz_staff اجرا نشده'); exit(T::report()); }

const PR_PREFIX = '__store_price_';
const PR_PASS   = 'Price#pass9';
$wipe = function () use ($pdo) {
    foreach ($pdo->query("SELECT id FROM users WHERE username LIKE '" . PR_PREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN) as $id) { deleteUserAccount((int)$id); }
};
$wipe();
register_shutdown_function($wipe);
$one = function (string $sql, array $p = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn(); };
$mk = function (string $n) use ($pdo): int {
    $id = (int)createUserAccount($pdo, 'فروشگاه ' . $n, PR_PREFIX . $n, PR_PREFIX . $n . '@example.com', PR_PASS)['id'];
    Biz::setType($id, 'business');
    return $id;
};
$u = $mk('own'); $o = $mk('oth');
$cash = (int)BizCash::list($u)[0]['id'];

// ---- ۱. سطحِ قیمت ----
$pid = (int)BizProducts::save($u, ['type' => 'service', 'name' => 'نصبِ گلس', 'unit' => 'عدد', 'sell_price' => '1000'])['id'];
T::ok(BizProducts::savePrices($u, $pid, ['price_wholesale' => '۸۰۰', 'price_colleague' => ''])['ok'], 'قیمتِ عمده ذخیره شد');
T::same([800, null], [(int)$one('SELECT price_wholesale FROM biz_products WHERE id = :i', ['i' => $pid]),
    $one('SELECT price_colleague FROM biz_products WHERE id = :i', ['i' => $pid])], 'عمده ۸۰۰ و همکارِ خالی = NULL (نه صفر)');
T::ok(!BizProducts::savePrices($u, $pid, ['price_wholesale' => '-5'])['ok'], 'قیمتِ منفی رد');
BizProducts::savePrices($o, $pid, ['price_wholesale' => '1']);
T::same(800, (int)$one('SELECT price_wholesale FROM biz_products WHERE id = :i', ['i' => $pid]), '⛔ فروشگاهِ دیگر قیمتِ این کالا را عوض نکرد');

$party = function (string $name, string $level, string $limit = '') use ($u): int {
    $id = (int)BizParties::save($u, ['name' => $name, 'kind' => 'customer'])['id'];
    $r = BizParties::saveTerms($u, $id, ['price_level' => $level, 'credit_limit' => $limit]);
    if (!$r['ok']) { throw new RuntimeException($r['message']); }
    return $id;
};
$whole = $party('عمده‌فروش', 'wholesale');
$coll  = $party('همکارِ بازار', 'colleague');
$ret   = $party('مشتریِ تکی', 'retail');
$bad   = $party('سطحِ ناشناخته', 'vip');
T::same('retail', (string)$one('SELECT price_level FROM biz_parties WHERE id = :i', ['i' => $bad]), 'سطحِ ناشناخته = تک‌فروشی');
T::same('price_wholesale', BizProducts::priceColFor($u, $whole), 'ستونِ قیمتِ مشتریِ عمده');
T::same('sell_price', BizProducts::priceColFor($o, $whole), '⛔ سطحِ مشتریِ فروشگاهِ دیگر خوانده نمی‌شود');
T::same('sell_price', BizProducts::priceColFor($u, 0), 'گذری = قیمتِ فروش');

$draftPrice = function (int $partyId, string $price = '') use ($u): int {
    $r = BizInvoices::saveDraft($u, 'sale', ['party_id' => $partyId, 'lines' => [['item' => 'نصبِ گلس', 'qty' => '2', 'price' => $price]]]);
    if (!$r['ok']) { throw new RuntimeException($r['message']); }
    $id = (int)$r['id'];
    $p = (int)Database::getConnection()->query('SELECT unit_price FROM biz_invoice_lines WHERE invoice_id = ' . $id)->fetchColumn();
    BizInvoices::deleteDraft($u, $id);
    return $p;
};
T::same(800, $draftPrice($whole), '⛔ ردیفِ بی‌قیمت برای مشتریِ عمده = قیمتِ عمده');
T::same(1000, $draftPrice($coll), '⛔ سطحِ خالیِ کالا (همکار) = قیمتِ فروش، نه صفر');
T::same(1000, $draftPrice($ret), 'تک‌فروشی = قیمتِ فروش');
T::same(1000, $draftPrice(0), 'گذری = قیمتِ فروش');
T::same(950, $draftPrice($whole, '950'), '⛔ قیمتِ نوشته‌شده دست نمی‌خورد');
$pr = (int)BizInvoices::saveDraft($u, 'purchase', ['party_id' => $whole, 'lines' => [['item' => 'نصبِ گلس', 'qty' => '1', 'price' => '']]])['id'];
T::ok((int)$one('SELECT unit_price FROM biz_invoice_lines WHERE invoice_id = :i', ['i' => $pr]) !== 800, '⛔ فاکتورِ خرید قیمتِ عمده را نمی‌گیرد');
BizInvoices::deleteDraft($u, $pr);

// ---- ۲. سقفِ اعتبار ----
$lim = $party('مشتریِ اعتباری', 'retail', '۵٬۰۰۰');
T::same(5000, (int)$one('SELECT credit_limit FROM biz_parties WHERE id = :i', ['i' => $lim]), 'سقف ۵۰۰۰');
$sale = function (int $partyId, int $total, array $pay = ['amount' => '0']) use ($u): array {
    $id = (int)BizInvoices::saveDraft($u, 'sale', ['party_id' => $partyId, 'lines' => [['item' => 'نصبِ گلس', 'qty' => '1', 'price' => (string)$total]]])['id'];
    return [$id, BizInvoices::issue($u, $id, $pay)];
};
[$a, $r] = $sale($lim, 6000);
T::ok(!$r['ok'] && !empty($r['credit']), '⛔ نسیه‌ی بیش از سقف صادر نشد', $r['message'] ?? '');
T::same(['draft', null], [(string)$one('SELECT status FROM biz_invoices WHERE id = :i', ['i' => $a]), $one('SELECT number FROM biz_invoices WHERE id = :i', ['i' => $a])], '⛔ پیش‌نویس ماند و شماره نگرفت');
$r = BizInvoices::issue($u, $a, ['account_id' => $cash, 'amount' => '1000', 'part' => true]);
T::ok($r['ok'], 'با ۱۰۰۰ نقد (مانده دقیقاً روی سقف) صادر شد', $r['message']);
[, $r] = $sale($lim, 1);
T::ok(!$r['ok'], '⛔ یک تومان بیشتر از سقف رد');
[$c2, $r] = $sale($lim, 3000, ['amount' => '0', 'over_credit' => true]);
T::ok($r['ok'], '⛔ «گذر از سقف» صادر کرد', $r['message']);
[$b, $r] = $sale($lim, 1, ['account_id' => $cash, 'full' => true]);
T::ok($r['ok'], '⛔ فروشِ تمام‌نقد به مشتریِ بیش از سقف رد نمی‌شود', $r['message']);
$ot = (int)BizParties::save($o, ['name' => 'مشتریِ دیگر', 'kind' => 'customer'])['id'];
T::same(null, BizParties::creditError($pdo, $o, $lim, 999999), '⛔ سقفِ طرف‌حسابِ فروشگاهِ دیگر خوانده نمی‌شود');
[, $r] = $sale($ret, 900000);
T::ok($r['ok'], 'بی‌سقف (۰) = بی‌محدودیت');

// ---- ۳. پورسانت ----
$st = BizStaff::add($u, ['full_name' => 'فروشنده‌ی پورسانتی', 'username' => PR_PREFIX . 'sel', 'password' => PR_PASS, 'role' => 'seller', 'commission_pct' => '۲٫۵']);
T::ok($st['ok'], 'فروشنده با ۲٫۵٪', $st['message'] ?? '');
$selId = (int)$one('SELECT staff_id FROM store_staff WHERE id = :i', ['i' => (int)$st['id']]);
$_SESSION = ['user_id' => $selId];
$d = '2026-02-10';
$s1 = (int)BizInvoices::saveDraft($u, 'sale', ['party_id' => $ret, 'inv_date' => $d, 'vat_rate' => '10', 'discount' => '40000',
    'lines' => [['item' => 'نصبِ گلس', 'qty' => '4', 'price' => '100000']]])['id'];
BizInvoices::issue($u, $s1, ['amount' => '0']);
$s2 = (int)BizInvoices::saveDraft($u, 'sale', ['party_id' => 0, 'inv_date' => $d, 'lines' => [['item' => 'نصبِ گلس', 'qty' => '1', 'price' => '200000']]])['id'];
BizInvoices::issue($u, $s2, ['account_id' => $cash, 'full' => true]);
$_SESSION = [];
T::same($selId, (int)$one('SELECT created_by FROM biz_invoices WHERE id = :i', ['i' => $s1]), 'سازنده‌ی فاکتور فروشنده است');
$s3 = (int)BizInvoices::saveDraft($u, 'sale', ['party_id' => $ret, 'inv_date' => $d, 'lines' => [['item' => 'نصبِ گلس', 'qty' => '1', 'price' => '50000']]])['id'];
BizInvoices::issue($u, $s3, ['amount' => '0']);
// برگشت از فاکتورِ فروشنده را صاحب ثبت می‌کند
$ln = (int)$one('SELECT id FROM biz_invoice_lines WHERE invoice_id = :i', ['i' => $s1]);
$rr = BizInvoices::createReturn($u, $s1, [$ln => '1'], [], $d);
T::ok($rr['ok'], 'برگشتِ یکی از فاکتورِ فروشنده (به دستِ صاحب)', $rr['message'] ?? '');
$cm = BizStaff::commission($u, '2026-02-01', '2026-02-28');
$row = array_values(array_filter($cm['rows'], fn($x) => $x['id'] === $selId))[0] ?? null;
T::ok($row !== null, 'ردیفِ فروشنده در گزارش');
T::same([560000, 90000, 470000, 2], [$row['sales'] ?? 0, $row['returns'] ?? 0, $row['net'] ?? 0, $row['docs'] ?? 0], '⛔ فروش بی‌مالیات و پس از تخفیفِ فاکتور، برگشت از سازنده‌ی فاکتورِ اصل');
T::same(11750, $row['commission'] ?? 0, '⛔ پورسانت = ۲٫۵٪ × ۴۷۰٬۰۰۰');
$rest = array_values(array_filter($cm['rows'], fn($x) => $x['id'] === 0))[0] ?? null;
T::same([50000, 0], [$rest['net'] ?? -1, $rest['commission'] ?? -1], '⛔ فروشِ صاحب جدا و بی‌پورسانت (برگشت به حسابش نیامد)');
T::same(11750, $cm['sums']['commission'], 'جمعِ پورسانت');
T::same([], BizStaff::commission($u, '2026-03-01', '2026-03-31')['rows'], 'بازه‌ی بی‌فروش خالی');
T::same([], BizStaff::commission($o, '2026-02-01', '2026-02-28')['rows'], '⛔ فروشگاهِ دیگر چیزی نمی‌بیند');

// ---- ۴. HTTP ----
$root = realpath(__DIR__ . '/..');
$port = 0;
for ($p = 9520; $p <= 9560; $p++) { $s = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2); if ($s) { fclose($s); $port = $p; break; } }
if (!$port) { T::blocked('قیمت و اعتبار (HTTP)', 'پورت آزاد پیدا نشد'); exit(T::report()); }
$log = tempnam(sys_get_temp_dir(), 'prlog');
$srv = (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!', $port, escapeshellarg($root), escapeshellarg($log))));
register_shutdown_function(static function () use ($srv, $log) { exec("kill {$srv} 2>/dev/null"); @unlink($log); });
for ($i = 0, $up = false; $i < 40 && !$up; $i++) { usleep(150000); $sk = @fsockopen('127.0.0.1', $port, $a1, $b1, 0.3); if ($sk) { fclose($sk); $up = true; } }
T::ok($up, 'سرورِ آزمایشی بالا آمد');
if (!$up) { exit(T::report()); }
$client = function () use ($port) {
    $jar = tempnam(sys_get_temp_dir(), 'prjar');
    return function (string $path, ?array $post = null) use ($port, $jar): array {
        $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
        $loc = '';
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 40,
            CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$loc) { if (stripos($h, 'Location:') === 0) { $loc = trim(substr($h, 9)); } return strlen($h); }]);
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $body = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return [$code, $body, $loc];
    };
};
$login = function (callable $req, string $user) {
    [, $lp] = $req('store/login.php');
    $tok = preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $lp, $m) ? $m[1] : '';
    $req('store/login.php', ['csrf_token' => $tok, 'username' => $user, 'password' => PR_PASS]);
};
$field = fn(string $html, string $name): string => preg_match('/name="' . preg_quote($name, '/') . '"[^>]*value="([^"]*)"/', $html, $m) ? $m[1]
    : (preg_match('/name="csrf-token" content="([^"]+)"/', $html, $m2) && $name === 'csrf_token' ? $m2[1] : '');

$own = $client(); $login($own, PR_PREFIX . 'own');
$sel = $client(); $login($sel, PR_PREFIX . 'sel');
[$c, $h] = $own('store/invoice-edit.php?k=sale');
T::ok($c === 200 && str_contains($h, 'name="over_credit"'), 'صاحب تیکِ «گذر از سقف» را دارد');
T::ok(str_contains($h, 'data-level="wholesale"') && str_contains($h, 'data-wholesale="800"'), 'سطحِ مشتری و قیمتِ عمده برای store.js', (string)preg_match('/data-level="wholesale"/', $h) . (string)preg_match('/data-wholesale/', $h));
[$c, $h] = $sel('store/invoice-edit.php?k=sale');
T::ok($c === 200 && !str_contains($h, 'name="over_credit"'), '⛔ فروشنده تیکِ «گذر از سقف» ندارد');
$issued = fn(): int => (int)$one("SELECT COUNT(*) FROM biz_invoices WHERE user_id = :u AND party_id = :p AND status = 'issued'", ['u' => $u, 'p' => $lim]);
$post = function (callable $req, string $html) use ($field, $lim, $cash): array {
    return $req('store/invoice-edit.php?k=sale', ['csrf_token' => $field($html, 'csrf_token'), '_once' => $field($html, '_once'),
        'party_id' => (string)$lim, 'inv_date' => BizDocView::jDate(date('Y-m-d')), 'discount' => '', 'extra' => '', 'note' => '',
        'lines' => [['item' => 'نصبِ گلس', 'qty' => '1', 'price' => '7000']], 'pay_mode' => 'none', 'account_id' => (string)$cash,
        'method' => 'cash', 'over_credit' => '1', 'action' => 'issue']);
};
$n0 = $issued();
[$c, , $loc] = $post($sel, $h);
T::ok($c === 302 && str_contains($loc, 'invoice-edit.php'), '⛔ فروشنده با فرستادنِ دستیِ over_credit هم صادر نکرد', $c . ' ' . $loc);
T::same($n0, $issued(), 'و هیچ فاکتوری صادر نشد');
[, $h] = $own('store/invoice-edit.php?k=sale');
[$c, , $loc] = $post($own, $h);
T::ok($c === 302 && str_contains($loc, 'invoice.php?id='), '⛔ صاحب با «گذر از سقف» صادر کرد', $c . ' ' . $loc);
T::same($n0 + 1, $issued(), 'یک فاکتور صادر شد');

[$c, $h] = $own('store/party.php?id=' . $lim);
T::ok($c === 200 && str_contains($h, 'data-credit-room') && str_contains($h, 'بیش از سقف'), 'صفحه‌ی طرف‌حساب: سقف و «بیش از سقف»');
T::ok(str_contains($h, 'name="credit_limit"') && str_contains($h, 'name="price_level"'), 'فرمِ قیمت و اعتبار');
[$c, $h] = $own('store/party.php?id=' . $ret);
T::ok(!str_contains($h, 'data-credit-room'), 'بی‌سقف: نمایشِ سقف نیست');
[$c, $h] = $own('store/product.php?id=' . $pid);
T::ok($c === 200 && str_contains($h, 'name="price_wholesale"') && str_contains($h, 'name="price_colleague"'), 'فرمِ کالا: قیمتِ عمده و همکار');
[$c, $h] = $own('store/print.php?doc=commission&p=custom&from=' . urlencode(BizDocView::jDate('2026-02-01')) . '&to=' . urlencode(BizDocView::jDate('2026-02-28')));
T::ok($c === 200 && str_contains($h, 'فروشنده‌ی پورسانتی') && str_contains($h, formatMoney(11750)), 'برگه‌ی پورسانت', (string)$c);
[$c] = $sel('store/print.php?doc=commission');
T::ok($c === 403 || $c === 404, '⛔ فروشنده (بی‌گزارش) برگه‌ی پورسانت را نمی‌بیند', (string)$c);
[$c, $h] = $own('store/reports.php');
T::ok(str_contains($h, 'name="doc" value="commission"'), 'در مرکزِ گزارش‌ها');

$sl = (string)@file_get_contents($log);
T::ok(!preg_match('/PHP (Fatal|Warning|Notice|Deprecated)/', $sl), 'لاگِ سرور بی‌هشدار و بی‌خطا', mb_substr($sl, 0, 600));

$wipe();
exit(T::report());
