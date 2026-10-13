<?php
/**
 * ⛔ دفترِ چکِ فروشگاه (`BizCheques`).
 *
 * خطرها، هر کدام بی‌صدا:
 *   ۱. **چکِ وصول‌نشده در جمعِ نقد** — صندوق پولی نشان می‌دهد که هنوز نیست.
 *   ۲. **وصول/برگشت که پول را دو بار یا هیچ‌بار جابه‌جا کند** — موجودیِ بانک،
 *      صندوقِ چک و مانده‌ی طرف‌حساب باید در هر گام با هم بخوانند.
 *   ۳. **صندوقِ چک از درِ پشتی** — دریافت/پرداخت یا انتقالِ دستی به آن.
 *   ۴. **فروشگاهِ دیگر** چکِ این فروشگاه را وصول کند یا بانکش را بگیرد.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/user_import.php';
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_docs.php';
require_once __DIR__ . '/../includes/biz_docview.php';

$root = dirname(__DIR__);
const CPREFIX = '__bchq_';
const CPASS   = 'Chq12345';

T::group('۰ — روش‌های فرم‌های سریع (بی‌دیتابیس)');
T::ok(!isset(BizPay::quickMethods()['cheque']), '⛔ فاکتور، فروشِ سریع و برگشت «چک» ندارند (شماره و سررسید جا ندارد)');
T::ok(isset(BizPay::METHODS['cheque']), 'ولی «دریافت/پرداخت» چک دارد');

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::blocked('دفترِ چک', 'اتصال به دیتابیس برقرار نشد: ' . $e->getMessage());
    exit(T::report());
}
if (!Biz::available() || !tableExists('biz_payments') || !BizCheques::ready()) {
    T::blocked('دفترِ چک', 'ستون‌های چک نیست — اول: bash deploy/migrate.sh --apply');
    exit(T::report());
}

$wipe = function () use ($pdo) {
    $ids = $pdo->query("SELECT id FROM users WHERE username LIKE '" . CPREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        foreach (['biz_allocations', 'biz_payments', 'biz_stock_moves'] as $t) {
            $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]);
        }
        $pdo->prepare('DELETE FROM biz_invoices WHERE user_id = :u AND ref_invoice_id IS NOT NULL')->execute(['u' => $id]);
        foreach (['biz_invoices', 'biz_products', 'biz_categories', 'biz_parties', 'biz_accounts', 'biz_settings'] as $t) {
            $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]);
        }
        foreach (userDataTables() as $t) {
            try { $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]); }
            catch (PDOException $e) { /* جدولی که نیست */ }
        }
        $pdo->prepare('DELETE FROM audit_log WHERE target_user_id = :u')->execute(['u' => $id]);
    }
    $pdo->exec("DELETE FROM users WHERE username LIKE '" . CPREFIX . "%'");
    $pdo->exec("DELETE FROM login_attempts WHERE username_tried LIKE '" . CPREFIX . "%'");
};
$wipe();
$make = function (string $name) use ($pdo): int {
    $res = createUserAccount($pdo, 'کاربرِ ' . $name, CPREFIX . $name, CPREFIX . $name . '@example.com', CPASS);
    if (!($res['ok'] ?? false)) { throw new RuntimeException('ساختِ کاربر: ' . ($res['error'] ?? '?')); }
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => CPREFIX . $name]);
    return (int)$st->fetchColumn();
};
$a = $make('a');
$b = $make('b');
Biz::setType($a, 'business');
Biz::setType($b, 'business');

$cash  = (int)BizCash::list($a)[0]['id'];
$bank  = (int)BizCash::save($a, ['name' => 'بانکِ ملت', 'kind' => 'bank', 'opening_balance' => '0'])['id'];
$bBank = (int)BizCash::save($b, ['name' => 'بانکِ ب', 'kind' => 'bank', 'opening_balance' => '0'])['id'];
$cus   = (int)BizParties::save($a, ['name' => 'مشتری', 'kind' => 'customer'])['id'];
$sup   = (int)BizParties::save($a, ['name' => 'پخش', 'kind' => 'supplier'])['id'];
$bal   = function (int $u, int $acc): int {
    foreach (BizCash::list($u) as $r) { if ((int)$r['id'] === $acc) { return (int)$r['balance']; } }
    return PHP_INT_MIN;
};
$party = fn(int $id): int => (int)BizParties::get($a, $id)['balance'];
$lines = fn(array $rows): array => array_map(fn($x) => ['item' => $x[0], 'qty' => (string)$x[1], 'price' => (string)$x[2]], $rows);
$sd = (int)BizInvoices::saveDraft($a, 'sale', ['party_id' => $cus, 'lines' => $lines([['خدمتِ تعمیر', 1, 1000]])])['id'];
T::ok(BizInvoices::issue($a, $sd, ['amount' => '0'])['ok'], 'فاکتورِ فروشِ نسیه‌ی ۱۰۰۰');
$due10 = date('Y-m-d', strtotime('+10 days'));

// =================================================================
T::group('۱ — ثبتِ چکِ دریافتی');
$r = BizPay::create($a, ['kind' => 'receipt', 'party_id' => $cus, 'amount' => '1000', 'account_id' => $cash, 'method' => 'cheque']);
T::ok(!$r['ok'] && str_contains($r['message'], 'سررسید'), 'بی‌سررسید رد می‌شود', $r['message']);
$r = BizPay::create($a, ['kind' => 'receipt', 'invoice_id' => 0, 'party_id' => 0, 'amount' => '1000', 'account_id' => $cash,
                         'method' => 'cheque', 'cheque_due' => $due10]);
T::ok(!$r['ok'], 'بی‌طرف‌حساب رد می‌شود', $r['message']);
$r = BizPay::create($a, ['kind' => 'receipt', 'party_id' => $cus, 'amount' => '1000', 'account_id' => $cash, 'method' => 'cheque',
                         'cheque_due' => $due10, 'cheque_no' => '۱۲۳۴۵۶', 'cheque_bank' => 'ملت']);
T::ok($r['ok'], 'چکِ دریافتی ثبت شد', $r['message']);
$chq = (int)$r['id'];
$row = $pdo->query("SELECT * FROM biz_payments WHERE id = {$chq}")->fetch();
$cin = (int)$pdo->query("SELECT id FROM biz_accounts WHERE user_id = {$a} AND kind = 'cheque_in'")->fetchColumn();
T::ok($cin > 0 && (int)$row['account_id'] === $cin, '⛔ پول به صندوقِ «چک‌های دریافتیِ در جریان» رفت، نه صندوقی که فرم فرستاد');
T::same(['123456', 'ملت', $due10, 'pending'], [$row['cheque_no'], $row['cheque_bank'], $row['cheque_due'], $row['cheque_status']], 'شماره با رقمِ لاتین، بانک، سررسید، در جریان');
T::same(0, $bal($a, $cash), 'صندوقِ نقد دست نخورد');
T::same(1000, $bal($a, $cin), 'صندوقِ چک ۱۰۰۰');
T::same(0, BizCash::total(BizCash::list($a)), '⛔ چکِ در جریان در «جمعِ نقد» نیست');
T::same(0, $party($cus), 'مانده‌ی مشتری صفر شد (چک را گرفته‌ایم)');
T::same(1000, (int)BizInvoices::get($a, $sd)['paid'], 'فاکتور با چک تسویه شد');

// =================================================================
T::group('۲ — صندوقِ چک از درِ پشتی بسته است');
$r = BizPay::create($a, ['kind' => 'receipt', 'party_id' => $cus, 'amount' => '10', 'account_id' => $cin, 'method' => 'cash']);
T::ok(!$r['ok'], '⛔ دریافتِ نقد به صندوقِ چک رد می‌شود', $r['message']);
$r = BizPay::create($a, ['kind' => 'transfer', 'amount' => '10', 'account_id' => $cin, 'to_account_id' => $bank]);
T::ok(!$r['ok'], '⛔ انتقالِ دستی از صندوقِ چک رد می‌شود', $r['message']);
$r = BizPay::create($a, ['kind' => 'transfer', 'amount' => '10', 'account_id' => $cash, 'to_account_id' => $cin]);
T::ok(!$r['ok'], '⛔ انتقالِ دستی به صندوقِ چک رد می‌شود', $r['message']);
T::ok(!BizCash::save($a, ['name' => 'x', 'kind' => 'cash'], $cin)['ok'], 'صندوقِ چک ویرایش نمی‌شود');
T::ok(!BizCash::save($a, ['name' => 'x', 'kind' => 'cheque_in'])['ok'], 'کاربر صندوقِ چک نمی‌سازد');
T::ok(!BizCash::setActive($a, $cin, false)['ok'], 'صندوقِ چک غیرفعال نمی‌شود');
T::ok(!in_array($cin, array_map(fn($x) => (int)$x['id'], BizDocView::accounts($a)), true), 'در فهرستِ انتخابِ صندوق نیست');
BizCash::setActive($a, $bank, false);
T::ok(!BizCash::setActive($a, $cash, false)['ok'], '⛔ صندوقِ چک «آخرین صندوقِ فعال» شمرده نمی‌شود');
BizCash::setActive($a, $bank, true);

// =================================================================
T::group('۳ — فهرست و سررسید');
$l = BizCheques::list($a);
T::same([1, 1000, 0], [$l['total'], $l['in'], $l['out']], 'در جریان: یک چک، ۱۰۰۰ دریافتی');
T::same(0, BizCheques::list($a, 'out')['total'], 'صافیِ پرداختی خالی است');
T::same([], BizCheques::due($a), 'سررسیدِ ده روز دیگر در «هفت روزِ آینده» نیست');
$pdo->exec("UPDATE biz_payments SET cheque_due = DATE_SUB(CURDATE(), INTERVAL 2 DAY) WHERE id = {$chq}");
$d = BizCheques::due($a);
T::ok(count($d) === 1 && (int)$d[0]['days'] === -2, 'سررسیدِ گذشته در داشبورد با «۲ روز گذشته»', json_encode($d, JSON_UNESCAPED_UNICODE));
T::same(1, BizCheques::list($a, 'overdue')['overdue'], 'شمارِ سررسید گذشته');
T::same(0, BizCheques::list($b)['total'], '⛔ فروشگاهِ دیگر چکی نمی‌بیند');
T::same([], BizCheques::due($b), '⛔ و در داشبوردش هم نه');

// =================================================================
T::group('۴ — وصول و برگرداندنش');
T::ok(!BizCheques::clear($b, $chq, $bBank)['ok'], '⛔ فروشگاهِ دیگر چک را وصول نمی‌کند');
T::ok(!BizCheques::clear($a, $chq, $bBank)['ok'], '⛔ به بانکِ فروشگاهِ دیگر وصول نمی‌شود');
// ⚠ صندوقِ چکِ **دیگر** نه خودِ `$cin`: انتقال به خودش را `createTx` جدا می‌گیرد و بررسی پوچ می‌شد
$coutEarly = BizCash::chequeAccount($pdo, $a, 'out');
T::ok(!BizCheques::clear($a, $chq, $coutEarly)['ok'], '⛔ به صندوقِ چکِ دیگر «وصول» نمی‌شود');
$r = BizCheques::clear($a, $chq, $bank, date('Y-m-d'));
T::ok($r['ok'], 'وصول شد', $r['message']);
T::same([1000, 0], [$bal($a, $bank), $bal($a, $cin)], '⛔ پول از صندوقِ چک به بانک رسید');
T::same(1000, BizCash::total(BizCash::list($a)), 'حالا در جمعِ نقد هست');
T::same(0, $party($cus), 'مانده‌ی مشتری با وصول عوض نشد');
$settle = (int)$pdo->query("SELECT id FROM biz_payments WHERE user_id = {$a} AND kind = 'transfer' AND status = 'ok' AND cheque_settle_id = {$chq}")->fetchColumn();
T::ok($pdo->query("SELECT cheque_status FROM biz_payments WHERE id = {$chq}")->fetchColumn() === 'cleared' && $settle > $chq,
    'وضعیت «وصول‌شده»، و انتقالِ وصول (ساخته‌شده بعد از چک) به چک اشاره می‌کند');
T::ok(!BizCheques::clear($a, $chq, $bank)['ok'], 'وصولِ دوباره رد می‌شود');
T::ok(!BizPay::void($a, $chq)['ok'], '⛔ چکِ وصول‌شده جدا باطل نمی‌شود');
T::ok(!BizPay::void($a, $settle)['ok'], '⛔ انتقالِ وصول جدا باطل نمی‌شود');
T::ok(!BizCheques::bounce($a, $chq)['ok'], 'چکِ وصول‌شده برگشت نمی‌خورد');
T::same(1, BizCheques::list($a, 'cleared')['total'], 'در صافیِ وصول‌شده');
T::ok(!BizCheques::unclear($b, $chq)['ok'], '⛔ فروشگاهِ دیگر وصول را برنمی‌گرداند');
T::ok(BizCheques::unclear($a, $chq)['ok'], 'وصول برگشت خورد');
T::same([0, 1000], [$bal($a, $bank), $bal($a, $cin)], '⛔ پول به صندوقِ چک برگشت');
T::same('void', $pdo->query("SELECT status FROM biz_payments WHERE id = {$settle}")->fetchColumn(), 'انتقالِ وصول باطل شد (حذف نه)');

// =================================================================
T::group('۵ — برگشتی');
T::ok(!BizCheques::bounce($b, $chq)['ok'], '⛔ فروشگاهِ دیگر برگشت نمی‌زند');
$r = BizCheques::bounce($a, $chq);
T::ok($r['ok'], 'برگشت خورد', $r['message']);
T::same(0, $bal($a, $cin), 'صندوقِ چک خالی شد');
T::same(1000, $party($cus), '⛔ بدهیِ مشتری برگشت');
T::same(0, (int)BizInvoices::get($a, $sd)['paid'], '⛔ فاکتور دوباره تسویه‌نشده است');
T::same(['void', 'bounced'], array_values($pdo->query("SELECT status, cheque_status FROM biz_payments WHERE id = {$chq}")->fetch(PDO::FETCH_NUM)), 'سند باطل، چک برگشتی');
T::ok(!BizCheques::clear($a, $chq, $bank)['ok'], 'چکِ برگشتی وصول نمی‌شود');
T::same(1, BizCheques::list($a, 'bounced')['total'], 'در صافیِ برگشتی');

// =================================================================
T::group('۶ — چکِ پرداختی');
$r = BizPay::create($a, ['kind' => 'payment', 'party_id' => $sup, 'amount' => '500', 'account_id' => $bank, 'method' => 'cheque', 'cheque_due' => $due10]);
T::ok($r['ok'], 'چکِ پرداختی ثبت شد', $r['message']);
$out = (int)$r['id'];
$cout = (int)$pdo->query("SELECT id FROM biz_accounts WHERE user_id = {$a} AND kind = 'cheque_out'")->fetchColumn();
T::same([0, -500], [$bal($a, $bank), $bal($a, $cout)], '⛔ بانک دست نخورد؛ صندوقِ «چک‌های پرداختی» −۵۰۰');
T::same(500, BizCheques::list($a, 'out')['out'], 'پرداختیِ در جریان ۵۰۰');
T::ok(BizCheques::clear($a, $out, $bank)['ok'], 'پاس شد');
T::same([-500, 0], [$bal($a, $bank), $bal($a, $cout)], 'پول از بانک رفت و صندوقِ چک صفر شد');

$r = BizPay::create($a, ['kind' => 'receipt', 'party_id' => $cus, 'amount' => '300', 'account_id' => $cash, 'method' => 'cheque', 'cheque_due' => $due10]);
$c3 = (int)$r['id'];
T::ok(BizPay::void($a, $c3)['ok'], 'چکِ در جریان مثلِ هر سندی باطل می‌شود (ثبتِ اشتباه)');
T::same(0, BizCheques::list($a)['total'], 'چکِ باطل در «در جریان» نیست');
// فاکتورِ گذری (بی‌طرف‌حساب) — چکِ برگشتیِ آن به هیچ‌کس برنمی‌گشت
$gd = (int)BizInvoices::saveDraft($a, 'sale', ['party_id' => 0, 'lines' => $lines([['خدمتِ گذری', 1, 400]])])['id'];
T::ok(BizInvoices::issue($a, $gd, ['account_id' => $cash, 'amount' => '400'])['ok'], 'فاکتورِ گذری با تسویه‌ی نقد');
$r = BizPay::create($a, ['kind' => 'receipt', 'invoice_id' => $gd, 'amount' => '10', 'account_id' => $cash, 'method' => 'cheque', 'cheque_due' => $due10]);
T::ok(!$r['ok'] && str_contains($r['message'], 'گذری'), '⛔ چک برای فاکتورِ گذری رد می‌شود', $r['message']);

// =================================================================
T::group('۶ب — بکاپ: پیوندِ وصول ← چک بعد از بازگرداندن');
// ⛔ بازگرداندن شناسه‌ها را فقط از روی کلیدِ خارجی و به ترتیبِ درج نگاشت
//    می‌کند؛ پیوندی که رو به جلو بود یا کلیدِ خارجی نداشت، به ردیفِ دیگری می‌خورد.
$c = $make('c');
Biz::setType($c, 'business');
$parsed = parseBackupFile((string)json_encode(exportUserData($a), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$res = !empty($parsed['ok']) ? importUserData($c, $parsed['data']) : ['ok' => false, 'message' => 'فایل خوانده نشد'];
T::ok($res['ok'], 'بازگرداندنِ دفترِ فروشگاه در حسابِ دیگر', $res['message'] ?? '');
$cc = BizCheques::list($c, 'cleared');
$ca = BizCheques::list($a, 'cleared');
T::same($ca['total'], $cc['total'], 'همان تعداد چکِ وصول‌شده');
$cRow = $cc['rows'][0] ?? [];
T::ok(($cRow['settle_account'] ?? null) !== null && (int)$cRow['user_id'] === $c, '⛔ چکِ وصول‌شده انتقالِ وصولش را در حسابِ تازه پیدا می‌کند');
$bad = (int)$pdo->query("SELECT COUNT(*) FROM biz_payments s LEFT JOIN biz_payments y ON y.id = s.cheque_settle_id
                         WHERE s.user_id = {$c} AND s.cheque_settle_id IS NOT NULL AND (y.user_id IS NULL OR y.user_id <> {$c} OR y.cheque_status IS NULL)")->fetchColumn();
T::same(0, $bad, '⛔ هیچ انتقالِ وصولی به چکِ حسابِ دیگر یا به سندِ غیرِچک اشاره نمی‌کند');
T::same(
    array_map(fn($x) => (int)$x['balance'], array_filter(BizCash::list($a), fn($x) => BizCash::isCheque($x))),
    array_map(fn($x) => (int)$x['balance'], array_filter(BizCash::list($c), fn($x) => BizCash::isCheque($x))),
    'موجودیِ صندوق‌های چک یکی است'
);

// =================================================================
T::group('۷ — صفحه‌ها با HTTP');
$c4 = (int)BizPay::create($a, ['kind' => 'receipt', 'party_id' => $cus, 'amount' => '700', 'account_id' => $cash, 'method' => 'cheque',
                                'cheque_due' => date('Y-m-d', strtotime('-1 day')), 'cheque_no' => '777'])['id'];
$port = 0;
for ($pp = 9411; $pp <= 9460; $pp++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$pp", $e1, $e2);
    if ($sock) { fclose($sock); $port = $pp; break; }
}
$log = tempnam(sys_get_temp_dir(), 'bchq');
$srv = $port ? (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
    $port, escapeshellarg($root), escapeshellarg($log)))) : 0;
$up = false;
for ($i = 0; $srv && $i < 40; $i++) {
    usleep(150000);
    $sk = @fsockopen('127.0.0.1', $port, $x, $y, 0.3);
    if ($sk) { fclose($sk); $up = true; break; }
}
if (!$up) {
    T::blocked('دفترِ چک (HTTP)', 'سرورِ آزمایشی بالا نیامد');
    if ($srv) { exec("kill $srv 2>/dev/null"); }
    $wipe();
    exit(T::report());
}
$jar = tempnam(sys_get_temp_dir(), 'bchqjar');
$req = function (string $path, ?array $post = null) use ($port, $jar): array {
    $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 40]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);
    return [$code, $body];
};
$csrfOf = fn(string $html): string => preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m) ? $m[1] : '';
[, $lp] = $req('store/login.php');
T::same(302, $req('store/login.php', ['csrf_token' => $csrfOf($lp), 'username' => CPREFIX . 'a', 'password' => CPASS])[0], 'ورود');

[$c, $pg] = $req('store/cheques.php');
T::ok($c === 200 && str_contains($pg, '</html>') && str_contains($pg, 'چک ۷۷۷') && str_contains($pg, 'گذشته'), 'صفحه‌ی چک‌ها چکِ سررسیدگذشته را نشان می‌دهد');
$req('store/cheques.php', ['action' => 'clear', 'cheque_id' => (string)$c4, 'bank_id' => (string)$bank]);
T::same('pending', $pdo->query("SELECT cheque_status FROM biz_payments WHERE id = {$c4}")->fetchColumn(), '⛔ بی‌CSRF وصول نمی‌شود');
[$c, , ] = $req('store/cheques.php', ['csrf_token' => $csrfOf($pg), 'action' => 'clear', 'cheque_id' => (string)$c4, 'bank_id' => (string)$bank, 'clear_date' => BizDocView::jDate(date('Y-m-d'))]);
T::same(302, $c, 'وصول با فرم → ریدایرکت');
T::same('cleared', $pdo->query("SELECT cheque_status FROM biz_payments WHERE id = {$c4}")->fetchColumn(), 'وصول با فرم انجام شد');
[, $pa] = $req('store/payment.php?k=receipt');
T::ok(str_contains($pa, 'name="cheque_due"') && str_contains($pa, 'data-cheque-method'), 'فرمِ دریافت فیلدهای چک را دارد');
[, $ac] = $req('store/accounts.php');
T::ok(str_contains($ac, 'چک‌های دریافتیِ در جریان') && !str_contains($ac, 'value="cheque_in"'), 'صندوقِ چک دیده می‌شود ولی در منوی «نوع» نیست');
[$c, $pr] = $req('store/print.php?doc=cheques&k=all');
T::ok($c === 200 && str_contains($pr, 'دفترِ چک') && str_contains($pr, 'جمع'), 'چاپِ دفترِ چک');
[, $rp] = $req('store/reports.php');
T::ok(str_contains($rp, 'value="cheques"'), 'در «همه‌ی گزارش‌ها»');
$pdo->exec("UPDATE biz_payments SET cheque_due = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE user_id = {$a} AND id = {$out}");
[, $ix] = $req('store/index.php');
T::ok(!str_contains($ix, 'روز گذشته'), 'چکِ پاس‌شده در داشبورد نیست');
$c5 = (int)BizPay::create($a, ['kind' => 'receipt', 'party_id' => $cus, 'amount' => '90', 'account_id' => $cash, 'method' => 'cheque',
                                'cheque_due' => date('Y-m-d', strtotime('-3 days'))])['id'];
[, $ix] = $req('store/index.php');
T::ok(str_contains($ix, 'روز گذشته') && str_contains($ix, 'cheques.php?f=overdue'), 'داشبورد چکِ سررسیدگذشته را در «نیازمندِ اقدام» دارد');
[$c, $pv] = $req('store/payment.php?id=' . $c5);
T::ok($c === 200 && str_contains($pv, 'وضعیتِ چک'), 'صفحه‌ی سند وضعیتِ چک را دارد');

exec("kill $srv 2>/dev/null");
@unlink($log); @unlink($jar);
$wipe();
exit(T::report());
