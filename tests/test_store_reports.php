<?php
/**
 * ⛔ «همه‌ی گزارش‌ها»ی فروشگاه — شش گزارشِ تازه و درِ ورودشان.
 *
 * خطرِ اصلی **دو عدد برای یک حقیقت** است: گزارشِ کالا، گزارشِ فاکتور و
 * صورت سود و زیان باید یک سود بگویند؛ مانده‌ی «خلاصه‌ی اشخاص» باید همان
 * مانده‌ی صفحه‌ی طرف‌حساب باشد؛ و مانده‌ی پایانیِ گردشِ صندوق همان موجودیِ
 * صفحه‌ی صندوق‌ها. هر کدام اینجا جدا سنجیده می‌شود، به‌علاوه‌ی جداسازیِ دو
 * فروشگاه و رندرِ واقعیِ برگه‌ها با HTTP.
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
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_docs.php';
require_once __DIR__ . '/../includes/biz_reports.php';
require_once __DIR__ . '/../includes/biz_print.php';
require_once __DIR__ . '/../includes/biz_docview.php';

$root = dirname(__DIR__);
const RPREFIX = '__brep_';
const RPASS   = 'Rep12345';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::blocked('گزارش‌های فروشگاه', 'اتصال به دیتابیس برقرار نشد: ' . $e->getMessage());
    exit(T::report());
}
if (!Biz::available() || !tableExists('biz_invoices') || !tableHasColumn('biz_invoice_lines', 'imei1')) {
    T::blocked('گزارش‌های فروشگاه', 'جدول‌های اسناد نیست — اول: bash deploy/migrate.sh --apply');
    exit(T::report());
}

// =================================================================
T::group('۰ — فهرستِ «همه‌ی گزارش‌ها» (بی‌دیتابیس)');

$hubDocs = [];
foreach (BizPrint::HUB as $items) {
    foreach ($items as $it) { $hubDocs[] = $it['doc'] . (isset($it['k']) ? ':' . $it['k'] : ''); }
}
$missing = [];
foreach (BizPrint::HUB as $items) {
    foreach ($items as $it) { if (!isset(BizPrint::DOCS[$it['doc']])) { $missing[] = $it['doc']; } }
}
T::same([], $missing, '⛔ هر قلمِ HUB یک سندِ موجود در DOCS است');
$orphan = [];
foreach (array_keys(BizPrint::DOCS) as $d) {
    if (in_array($d, ['invoice', 'payment', 'repair'], true)) { continue; }   // سند، نه گزارش — از صفحه‌ی خودشان (قبضِ تعمیر از repair.php)
    $ok = false;
    foreach ($hubDocs as $h) { if ($h === $d || str_starts_with($h, $d . ':')) { $ok = true; } }
    if (!$ok) { $orphan[] = $d; }
}
T::same([], $orphan, '⛔ هیچ گزارشِ چاپی بیرونِ «همه‌ی گزارش‌ها» نمی‌ماند');
foreach (BizPrint::SIDE_TITLES as $d => $sides) {
    foreach (array_keys($sides) as $k) {
        T::ok(in_array($d . ':' . $k, $hubDocs, true), "هر دو سوی «{$d}» ({$k}) در فهرست هست");
    }
}
T::same('همه‌ی زمان‌ها', BizReports::rangeLabel(...BizReports::range('all')), '«از ابتدا» تاریخِ ساختگی نشان نمی‌دهد');
T::same(['p' => 'all'], BizDocView::periodParams('', ''), '⛔ فهرستِ بی‌صافیِ تاریخ «از ابتدا» چاپ می‌شود، نه «این ماه»');
$pp = BizDocView::periodParams('2026-03-21', '');
T::ok($pp['p'] === 'custom' && $pp['from'] === '1405/01/01' && BizDocView::gDate($pp['to']) === BizReports::ALL_TO,
    'صافیِ یک‌طرفه سرِ دیگرش را باز می‌گذارد', json_encode($pp, JSON_UNESCAPED_UNICODE));

// =================================================================
$wipe = function () use ($pdo) {
    $ids = $pdo->query("SELECT id FROM users WHERE username LIKE '" . RPREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN);
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
    $pdo->exec("DELETE FROM users WHERE username LIKE '" . RPREFIX . "%'");
    $pdo->exec("DELETE FROM login_attempts WHERE username_tried LIKE '" . RPREFIX . "%'");
};
$wipe();
$make = function (string $name) use ($pdo): int {
    $res = createUserAccount($pdo, 'کاربرِ ' . $name, RPREFIX . $name, RPREFIX . $name . '@example.com', RPASS);
    if (!($res['ok'] ?? false)) { throw new RuntimeException('ساختِ کاربر: ' . ($res['error'] ?? '?')); }
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => RPREFIX . $name]);
    return (int)$st->fetchColumn();
};
$a = $make('a');
$b = $make('b');
Biz::setType($a, 'business');
Biz::setType($b, 'business');
$lines = fn(array $rows): array => array_map(fn($r) => ['item' => $r[0], 'qty' => (string)$r[1], 'price' => (string)$r[2],
                                                       'imei1' => (string)($r[3] ?? '')], $rows);

T::group('۱ — fixture: خرید، فروش با گوشی، برگشت، هزینه، انتقال');
$cash = (int)BizCash::list($a)[0]['id'];
$bank = (int)BizCash::save($a, ['name' => 'بانک', 'kind' => 'bank', 'opening_balance' => '1000'])['id'];
$P = (int)BizProducts::save($a, ['name' => 'گلس', 'sku' => 'G1', 'unit' => 'عدد', 'buy_price' => '100', 'sell_price' => '150'])['id'];
$F = (int)BizProducts::save($a, ['type' => 'phone', 'name' => 'گوشیِ آزمون', 'unit' => 'دستگاه', 'buy_price' => '1000', 'sell_price' => '1500'])['id'];
$sup = (int)BizParties::save($a, ['name' => 'پخش', 'kind' => 'supplier'])['id'];
$cus = (int)BizParties::save($a, ['name' => 'مشتری', 'kind' => 'customer'])['id'];
$quiet = (int)BizParties::save($a, ['name' => 'بی‌گردش', 'kind' => 'customer'])['id'];
$I1 = '356938035643809'; $I2 = '490154203237518';

$r = BizInvoices::saveDraft($a, 'purchase', ['party_id' => $sup, 'lines' => $lines([['G1', 10, 100], ['گوشیِ آزمون', 1, 1000, $I1], ['گوشیِ آزمون', 1, 1000, $I2]])]);
$pu = (int)$r['id'];
$ok1 = BizInvoices::issue($a, $pu, ['amount' => '0'])['ok'];
$r = BizInvoices::saveDraft($a, 'sale', ['party_id' => $cus, 'lines' => $lines([['G1', 4, 150], [$I1, 1, 1500]])]);
$s1 = (int)$r['id'];
$ok2 = BizInvoices::issue($a, $s1, ['account_id' => $cash, 'amount' => '500'])['ok'];
$s1l = BizInvoices::get($a, $s1)['lines'];
$gl = 0; foreach ($s1l as $l) { if ((int)$l['product_id'] === $P) { $gl = (int)$l['id']; } }
$ok3 = BizInvoices::createReturn($a, $s1, [$gl => '1'], ['amount' => '0'])['ok'];
$ok4 = BizPay::create($a, ['kind' => 'expense', 'account_id' => $cash, 'amount' => '50', 'title' => 'قبض'])['ok']
    && BizPay::create($a, ['kind' => 'income', 'account_id' => $cash, 'amount' => '30', 'title' => 'متفرقه'])['ok']
    && BizPay::create($a, ['kind' => 'transfer', 'account_id' => $cash, 'to_account_id' => $bank, 'amount' => '100'])['ok']
    && BizPay::create($a, ['kind' => 'payment', 'party_id' => $sup, 'account_id' => $bank, 'amount' => '300', 'method' => 'transfer'])['ok'];
T::ok($ok1 && $ok2 && $ok3 && $ok4, 'خرید، فروش، برگشت و چهار دریافت/پرداخت صادر شد');

[$af, $at] = BizReports::range('all');

// =================================================================
T::group('۲ — فروش و خرید به تفکیکِ کالا');
$bp = BizReports::byProduct($a, 'sale', $af, $at);
$row = fn(array $rows, string $name): ?array => array_values(array_filter($rows, fn($r) => $r['name'] === $name))[0] ?? null;
$g = $row($bp['rows'], 'گلس'); $f = $row($bp['rows'], 'گوشیِ آزمون');
T::ok($g && (float)$g['qty'] === 3.0 && $g['amount'] === 450, '⛔ گلس: ۴ فروش − ۱ برگشت = ۳ و ۴۵۰ (خالصِ برگشت)', json_encode($g, JSON_UNESCAPED_UNICODE));
T::ok($g && $g['cost'] === 300 && $g['profit'] === 150, 'بهای تمام‌شده‌ی خالص ۳۰۰ و سود ۱۵۰ (برگشت با بهای ردیفِ اصلی)');
T::ok($f && $f['amount'] === 1500 && $f['profit'] === 500, 'گوشی: فروش ۱۵۰۰، سود ۵۰۰');
T::same([1950, 1300, 650], [$bp['amount'], $bp['cost'], $bp['profit']], 'جمع‌ها');
$sa = BizReports::sales($a, $af, $at);
T::same($sa['gross'], $bp['profit'], '⛔ سودِ «به تفکیکِ کالا» همان سودِ صورت سود و زیان است');
$bpp = BizReports::byProduct($a, 'purchase', $af, $at);
T::same(3000, $bpp['amount'], 'خرید به تفکیکِ کالا: ۱۰۰۰ + ۲۰۰۰');
T::same(0, $bpp['profit'], 'خرید سود ندارد');

// =================================================================
T::group('۳ — به تفکیکِ فاکتور و سودِ فاکتور');
$bi = BizReports::byInvoice($a, 'sale', $af, $at);
T::same(2, count($bi['rows']), 'فاکتورِ فروش و برگشتی‌اش');
T::same(1950, $bi['total'], '⛔ جمعِ خالص: برگشتی با علامتِ منفی');
T::same($sa['gross'], $bi['profit'], '⛔ جمعِ سودِ فاکتورها همان سودِ صورت سود و زیان');
$rs = array_values(array_filter($bi['rows'], fn($r) => (int)$r['id'] === $s1))[0] ?? [];
T::same(700, (int)($rs['profit'] ?? -1), 'سودِ فاکتورِ ۱: ۲۱۰۰ − (۴۰۰ + ۱۰۰۰)');
$bip = BizReports::byInvoice($a, 'purchase', $af, $at);
T::ok(count($bip['rows']) === 1 && $bip['total'] === 3000 && $bip['profit'] === 0, 'خرید به تفکیکِ فاکتور');
$future = BizReports::byInvoice($a, 'sale', date('Y-m-d', strtotime('+1 day')), BizReports::ALL_TO);
T::same(0, count($future['rows']), 'بازه‌ی بی‌سند هیچ ردیفی ندارد');

// =================================================================
T::group('۴ — خلاصه‌ی اشخاص');
$bpa = BizReports::byParty($a, $af, $at);
$pr = []; foreach ($bpa['rows'] as $r) { $pr[$r['id']] = $r; }
T::ok(isset($pr[$cus]) && $pr[$cus]['sale'] === 2100 && $pr[$cus]['sale_return'] === 150 && $pr[$cus]['receipt'] === 500, 'مشتری: فروش، برگشت و دریافت', json_encode($pr[$cus] ?? null, JSON_UNESCAPED_UNICODE));
T::same((int)BizParties::get($a, $cus)['balance'], $pr[$cus]['balance'] ?? null, '⛔ مانده همان مانده‌ی صفحه‌ی طرف‌حساب است');
T::same(-2700, $pr[$sup]['balance'] ?? null, 'تأمین‌کننده: ۳۰۰۰ − ۳۰۰ پرداخت');
T::ok(!isset($pr[$quiet]), 'طرف‌حسابِ بی‌گردش و بی‌مانده نمی‌آید');

// =================================================================
T::group('۵ — گردشِ صندوق و بانک');
$bals = []; foreach (BizCash::list($a) as $acc) { $bals[(int)$acc['id']] = (int)$acc['balance']; }
$sc = BizReports::accountStatement($a, $cash, $af, $at);
$sb = BizReports::accountStatement($a, $bank, $af, $at);
T::same($bals[$cash], $sc['closing'] ?? null, '⛔ مانده‌ی پایانیِ صندوق = موجودیِ صفحه‌ی صندوق‌ها');
T::same($bals[$bank], $sb['closing'] ?? null, '⛔ مانده‌ی پایانیِ بانک (با موجودیِ اولیه و انتقال)');
T::same(1000, $sb['opening'] ?? null, 'مانده‌ی ابتدای «از ابتدا» همان موجودیِ اولیه است');
$tr = array_values(array_filter($sb['lines'] ?? [], fn($l) => str_contains($l['desc'], 'انتقال')))[0] ?? null;
T::ok($tr && $tr['in'] === 100 && str_contains($tr['desc'], 'از '), 'انتقالِ ورودی در بانک «ورود» است', json_encode($tr, JSON_UNESCAPED_UNICODE));
$tc = array_values(array_filter($sc['lines'] ?? [], fn($l) => str_contains($l['desc'], 'انتقال')))[0] ?? null;
T::ok($tc && $tc['out'] === 100, 'همان انتقال در صندوق «خروج» است');
$later = BizReports::accountStatement($a, $cash, date('Y-m-d', strtotime('+1 day')), BizReports::ALL_TO);
T::ok($later && $later['opening'] === $bals[$cash] && !$later['lines'], 'بازه‌ی بعدی: همه‌چیز در مانده‌ی ابتدای بازه');
T::same(null, BizReports::accountStatement($b, $cash, $af, $at), '⛔ صندوقِ فروشگاهِ دیگر پیدا نمی‌شود');

// =================================================================
T::group('۶ — گزارشِ IMEI');
$sr = BizSerial::report($a);
T::same([1, 1], [$sr['in'], $sr['out']], 'یک گوشی فروخته، یکی در انبار');
$u1 = array_values(array_filter($sr['rows'], fn($u) => $u['imei1'] === $I1))[0] ?? null;
T::ok($u1 && $u1['state'] === 'out' && $u1['last']['party'] === 'مشتری' && $u1['last']['price'] === 1500 && $u1['first']['price'] === 1000,
    'گوشیِ فروخته: از پخش با ۱۰۰۰، به مشتری با ۱۵۰۰', json_encode($u1, JSON_UNESCAPED_UNICODE));
T::same([$I2], array_column(BizSerial::report($a, 'in')['rows'], 'imei1'), 'صافیِ «در انبار»');
T::same([$I1], array_column(BizSerial::report($a, 'out')['rows'], 'imei1'), 'صافیِ «فروخته»');
T::same(0, count(BizSerial::report($a, '', $P)['rows']), 'صافیِ کالا');
T::same(0, count(BizSerial::report($b)['rows']), '⛔ فروشگاهِ دیگر هیچ گوشی‌ای نمی‌بیند');
BizInvoices::void($a, (int)$pdo->query("SELECT id FROM biz_invoices WHERE user_id = {$a} AND kind = 'sale_return'")->fetchColumn());
$vo = BizInvoices::void($a, $s1);
T::ok($vo['ok'], 'فاکتورِ فروش باطل شد', $vo['message']);
T::same([2, 0], [BizSerial::report($a)['in'], BizSerial::report($a)['out']], '⛔ سندِ باطل اثری ندارد — گوشی به انبار برگشت');

// =================================================================
T::group('۷ — برگه‌ها و پیوندها با HTTP');
$port = 0;
for ($pp = 9311; $pp <= 9360; $pp++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$pp", $e1, $e2);
    if ($sock) { fclose($sock); $port = $pp; break; }
}
$log = tempnam(sys_get_temp_dir(), 'brep');
$srv = $port ? (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
    $port, escapeshellarg($root), escapeshellarg($log)))) : 0;
$up = false;
for ($i = 0; $srv && $i < 40; $i++) {
    usleep(150000);
    $sk = @fsockopen('127.0.0.1', $port, $x, $y, 0.3);
    if ($sk) { fclose($sk); $up = true; break; }
}
if (!$up) {
    T::blocked('برگه‌های گزارش (HTTP)', 'سرورِ آزمایشی بالا نیامد');
    if ($srv) { exec("kill $srv 2>/dev/null"); }
    $wipe();
    exit(T::report());
}
$jar = tempnam(sys_get_temp_dir(), 'brepjar');
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
[$c] = $req('store/login.php', ['csrf_token' => $csrfOf($lp), 'username' => RPREFIX . 'a', 'password' => RPASS]);
T::same(302, $c, 'ورود به فروشگاه');

$docs = [
    'store/print.php?doc=sales&p=all'                   => 'هزینه‌ها به تفکیکِ شرح',
    'store/print.php?doc=by_product&k=sale&p=all'       => BizPrint::SIDE_TITLES['by_product']['sale'],
    'store/print.php?doc=by_product&k=purchase&p=all'   => BizPrint::SIDE_TITLES['by_product']['purchase'],
    'store/print.php?doc=by_invoice&k=sale&p=all'       => BizPrint::SIDE_TITLES['by_invoice']['sale'],
    'store/print.php?doc=by_invoice&k=purchase&p=all'   => 'فاکتور خرید ۱',
    'store/print.php?doc=by_party&p=all'                => 'پخش',
    'store/print.php?doc=serials'                       => $I2,
    'store/print.php?doc=serials&f=in&id=' . $F         => 'گوشیِ آزمون',
    'store/print.php?doc=account&p=all&id=' . $bank     => 'مانده‌ی ابتدای بازه',
];
$bad = [];
foreach ($docs as $path => $needle) {
    [$c, $body] = $req($path);
    if ($c !== 200 || !str_contains($body, '</html>') || !str_contains($body, $needle)) { $bad[] = "{$path} → {$c}"; }
}
T::same([], $bad, 'هر شش گزارش (و صورت سود و زیان) کامل رندر می‌شوند و محتوای درست دارند');
$other = (int)BizCash::list($b)[0]['id'];
T::same(404, $req('store/print.php?doc=account&p=all&id=' . $other)[0], '⛔ گردشِ صندوقِ فروشگاهِ دیگر ۴۰۴');
$otherP = (int)BizProducts::save($b, ['name' => 'کالای ب', 'unit' => 'عدد'])['id'];
T::same(404, $req('store/print.php?doc=serials&id=' . $otherP)[0], '⛔ گزارشِ IMEIِ کالای فروشگاهِ دیگر ۴۰۴');

[$c, $rep] = $req('store/reports.php?p=all');
$missingLabels = [];
foreach (BizPrint::HUB as $group => $items) {
    if (!str_contains($rep, h($group))) { $missingLabels[] = $group; }
    foreach ($items as $it) { if (!str_contains($rep, h($it['label']))) { $missingLabels[] = $it['label']; } }
}
T::ok($c === 200 && !$missingLabels, 'صفحه‌ی گزارش همه‌ی گروه‌ها و گزارش‌ها را دارد', implode('، ', $missingLabels));
T::ok(str_contains($rep, 'name="p" value="all"'), '⛔ فرم‌های دوره‌ای بازه‌ی همان صفحه را پنهان می‌فرستند');
[, $sl] = $req('store/sales.php');
T::ok(str_contains($sl, 'doc=by_invoice&amp;k=sale&amp;p=all'), 'فهرستِ فروش «چاپِ فهرست» دارد (از ابتدا)');
[, $pl] = $req('store/purchases.php?from=1405/01/01');
T::ok(str_contains($pl, 'doc=by_invoice&amp;k=purchase&amp;p=custom'), 'فهرستِ خرید با صافیِ تاریخ همان بازه را چاپ می‌کند');
[, $ac] = $req('store/accounts.php');
T::ok(str_contains($ac, 'doc=account&amp;id=' . $bank), 'کارتِ هر صندوق پیوندِ چاپِ گردش دارد');
[, $py] = $req('store/payments.php?acc=' . $bank);
T::ok(str_contains($py, 'doc=account&amp;id=' . $bank), 'گردشِ یک صندوق «چاپِ گردش» دارد');
[, $pd] = $req('store/product.php?id=' . $F);
T::ok(str_contains($pd, 'doc=serials&amp;id=' . $F), 'صفحه‌ی گوشی پیوندِ گزارشِ IMEI دارد');
[, $pg] = $req('store/product.php?id=' . $P);
T::ok(!str_contains($pg, 'doc=serials'), 'کالای بی‌IMEI پیوندِ IMEI ندارد');

exec("kill $srv 2>/dev/null");
@unlink($log); @unlink($jar);
$wipe();
exit(T::report());
