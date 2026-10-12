<?php
/**
 * ⛔ جست‌وجوی کالا در فاکتور، «شرحِ کالا»، و «مانده‌ی قبلی/کل» روی فاکتور.
 *
 * **گزارشِ مالکِ نصب:** «سرچِ کالا اصلاً کار نمی‌کنه، یعنی کالا معرفی شده
 * اما نمیاره که فاکتور بزنم.» علتِ اصلی: `utf8mb4_persian_ci` حرفِ «ي/ك»ِ
 * عربی (کالای واردشده از اکسل) را با «ی/ک»ِ کیبوردِ فارسی یکی نمی‌داند، و
 * پاپ‌آپِ `<datalist>` هم فقط رشته را عیناً می‌گردد.
 *
 * چهار خطر:
 *   ۱. کالایی که هست پیدا نشود (حروفِ عربی، «آ»، نیم‌فاصله، ارقامِ فارسی).
 *   ۲. **برعکس**: متنِ آزاد بی‌صدا به کالای اشتباه بچسبد (پس فقط برابریِ
 *      یکتا، هرگز «شامل بودن») — آن‌وقت موجودیِ کالای دیگری کم می‌شد.
 *   ۳. «شرحِ کالا» ذخیره یا فرار داده نشود.
 *   ۴. «مانده‌ی قبلی» پیش‌پرداخت را دو بار کم کند، یا دفترِ کسِ دیگری را بخواند.
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
require_once __DIR__ . '/../includes/biz_docview.php';

$root = dirname(__DIR__);
const SPREFIX = '__bsrch_';
const SPASS   = 'Search12345';

// ---------------------------------------------------------------
T::group('۰ — تطبیقِ «از نظرِ آدم یکی» (بی‌دیتابیس)');
T::same('شارژر سریع کابل', BizCommon::persian('شارژر سريع كابل'), 'persian(): «ي/ك»ِ عربی → فارسی');
T::same(BizCommon::fold('آیفون ۱۵ — ۱۲۸'), BizCommon::fold('ايفون  15 — 128'), 'fold(): «آ»، «ي»، ارقام و فاصله‌ی اضافه');
T::same(BizCommon::fold('گلس‌سامسونگ'), BizCommon::fold('گلس سامسونگ'), 'fold(): نیم‌فاصله = فاصله');
T::same(BizCommon::fold('SM-A556'), BizCommon::fold('sm-a556'), 'fold(): بزرگی و کوچکیِ لاتین');
T::ok(BizCommon::fold('قاب') !== BizCommon::fold('قاب آیفون'), '⛔ fold() برابری است، نه «شامل بودن»');

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::blocked('جست‌وجوی کالا', 'اتصال به دیتابیس برقرار نشد: ' . $e->getMessage());
    exit(T::report());
}
if (!Biz::available() || !tableHasColumn('biz_invoice_lines', 'note')) {
    T::blocked('جست‌وجوی کالا', 'ستونِ «شرحِ کالا» نیست — اول: bash deploy/migrate.sh --apply');
    exit(T::report());
}

$wipe = function () use ($pdo) {
    foreach ($pdo->query("SELECT id FROM users WHERE username LIKE '" . SPREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        deleteUserAccount((int)$id);
    }
    $pdo->exec("DELETE FROM users WHERE username LIKE '" . SPREFIX . "%'");
    $pdo->exec("DELETE FROM login_attempts WHERE username_tried LIKE '" . SPREFIX . "%'");
};
$wipe();
$make = function (string $name) use ($pdo): int {
    $res = createUserAccount($pdo, 'کاربرِ ' . $name, SPREFIX . $name, SPREFIX . $name . '@example.com', SPASS);
    if (!($res['ok'] ?? false)) { throw new RuntimeException('ساختِ کاربر: ' . ($res['error'] ?? '?')); }
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => SPREFIX . $name]);
    return (int)$st->fetchColumn();
};
$a = $make('a');
$b = $make('b');
Biz::setType($a, 'business');
Biz::setType($b, 'business');
const IMS = '356938035643809';

// ---------------------------------------------------------------
T::group('۱ — نام‌ها یکسان ذخیره می‌شوند (و migration کهنه‌ها را یکسان می‌کند)');
$r = BizProducts::save($a, ['type' => 'goods', 'name' => 'کيف چرمي', 'unit' => 'عدد', 'sell_price' => '90']);
T::same('کیف چرمی', (string)BizProducts::get($a, (int)$r['id'])['name'], '⛔ save() حروفِ عربی را فارسی ذخیره می‌کند');
// کالای «واردشده از اکسل» پیش از این نسخه: مستقیم با حروفِ عربی
$pdo->prepare("INSERT INTO biz_products (user_id, name, unit, buy_price, sell_price, track_stock) VALUES (:u, 'شارژر سريع كابل تايپ سي', 'عدد', 300, 450, 1)")
    ->execute(['u' => $a]);
$CH = (int)$pdo->lastInsertId();
BizStock::setOpening($a, $CH, 10, 300);
BizProducts::save($a, ['type' => 'goods', 'name' => 'شارژر ماشین', 'unit' => 'عدد', 'sell_price' => '80']);
$st = $pdo->prepare('SELECT COUNT(*) FROM biz_products WHERE user_id = :u AND name LIKE :q');
$st->execute(['u' => $a, 'q' => '%شارژر سریع%']);
T::same(0, (int)$st->fetchColumn(), 'پیش از migration: persian_ci «ي» را «ی» نمی‌داند (همان باگ)');
$mig = (string)file_get_contents($root . '/migration_biz_search.sql');
$upd = preg_match('/UPDATE `biz_products`.*?;/s', $mig, $mm) ? $mm[0] : '';
T::ok($upd !== '', 'migration یک UPDATEِ یکسان‌سازی دارد');
$pdo->exec($upd);
$st->execute(['u' => $a, 'q' => '%شارژر سریع%']);
T::same(1, (int)$st->fetchColumn(), '⛔ پس از migration همان کالا با کیبوردِ فارسی پیدا می‌شود');
T::same(1, BizProducts::list($a, 'شارژر سريع')['total'], 'و جست‌وجوی فهرستِ کالا با حروفِ عربی هم');
// برگرداندنِ حالتِ «واردشده» برای بقیه‌ی تست (تطبیقِ fold)
$pdo->prepare("UPDATE biz_products SET name = 'شارژر سريع كابل تايپ سي' WHERE id = :i")->execute(['i' => $CH]);

// ---------------------------------------------------------------
T::group('۲ — parseLines: برابریِ یکتا، هرگز «شامل بودن»');
$PH = (int)BizProducts::save($a, ['type' => 'phone', 'name' => 'آیفون ۱۵ — ۱۲۸', 'unit' => 'دستگاه', 'buy_price' => '400', 'sell_price' => '500'])['id'];
$G1 = (int)BizProducts::save($a, ['type' => 'goods', 'name' => 'گلس سامسونگ', 'unit' => 'عدد', 'sell_price' => '20', 'opening_qty' => '9'])['id'];
BizProducts::save($a, ['type' => 'goods', 'name' => 'گلس‌ سامسونگ', 'unit' => 'عدد', 'sell_price' => '25']);   // هم‌معنا، دو کالا
// ⚠ از `meta` خوانده می‌شود نه `lines`: ردیفِ گوشیِ بی‌IMEI خطا می‌گیرد ولی کالایش پیدا شده است
$line = fn(string $item): array => BizInvoices::parseLines($a, [['item' => $item, 'qty' => '1', 'price' => '10']])['meta'][0] ?? [];
T::same($CH, (int)($line('شارژر سریع کابل تایپ سی')['product_id'] ?? 0), '⛔ نامِ کامل با «ی/ک»ِ فارسی کالای «ي/ك»ِ عربی را می‌گیرد');
$l = $line('ايفون ۱۵ — 128');
T::same($PH, (int)($l['product_id'] ?? 0), '«ايفون» بی‌«آ» و ارقامِ لاتین همان گوشی است');
T::same(null, $line('شارژر سریع')['product_id'] ?? null, '⛔ متنِ ناقص به کالا نمی‌چسبد (شرحِ آزاد می‌ماند)');
T::same($G1, (int)($line('گلس سامسونگ ')['product_id'] ?? 0), 'نامِ دقیقِ یکی از دو هم‌معنا همان را می‌گیرد');
T::same(null, $line('گلس‌سامسونگ')['product_id'] ?? null, '⛔ متنی که به دو کالا می‌خورد شرحِ آزاد می‌ماند (نه کالای اشتباه)');
// کالای فروشگاهِ دیگر هرگز
BizProducts::save($b, ['type' => 'goods', 'name' => 'هندزفری بی', 'unit' => 'عدد']);
T::same(null, BizInvoices::parseLines($a, [['item' => 'هندزفري بي', 'qty' => '1', 'price' => '1']])['meta'][0]['product_id'] ?? null,
    '⛔ تطبیقِ fold فقط میانِ کالاهای همین فروشگاه');

// ---------------------------------------------------------------
T::group('۳ — «شرحِ کالا» روی ردیف');
$p = BizInvoices::parseLines($a, [['item' => 'گلس سامسونگ', 'qty' => '1', 'price' => '20', 'note' => "  مات،   ضدضربه \n"]]);
T::same('مات، ضدضربه', $p['lines'][0]['note'] ?? null, 'parseLines: یک‌خطی و بی‌فاصله‌ی اضافه');
$p = BizInvoices::parseLines($a, [['item' => 'گلس سامسونگ', 'qty' => '1', 'price' => '20', 'note' => str_repeat('ب', 400)]]);
T::same(BizInvoices::LINE_NOTE_MAX, mb_strlen((string)$p['lines'][0]['note']), 'سقفِ طول');
$acc = (int)BizCash::list($a)[0]['id'];
$cus = (int)BizParties::save($a, ['kind' => 'customer', 'name' => 'مشتری آزمون', 'opening_amount' => '1000', 'opening_side' => 'they'])['id'];
$r = BizInvoices::saveDraft($a, 'sale', ['party_id' => $cus, 'inv_date' => date('Y-m-d'), 'lines' => [
    ['item' => 'گلس سامسونگ', 'qty' => '2', 'price' => '20', 'note' => '<b>مات</b>'],
]]);
$S1 = (int)$r['id'];
T::ok($r['ok'] && BizInvoices::issue($a, $S1, ['account_id' => $acc, 'method' => 'cash', 'amount' => '15'])['ok'], 'فاکتورِ فروش با شرح صادر شد');
$inv = BizInvoices::get($a, $S1);
T::same('<b>مات</b>', $inv['lines'][0]['note'], 'شرح ذخیره شد');
T::ok(str_contains(BizDocView::lineSub($inv['lines'][0]), '&lt;b&gt;مات&lt;/b&gt;'), '⛔ lineSub() شرح را فرار می‌دهد (نوشته‌ی کاربر)');
$rr = BizInvoices::createReturn($a, $S1, [(int)$inv['lines'][0]['id'] => '1'], ['account_id' => $acc, 'method' => 'cash', 'full' => true]);
T::ok($rr['ok'], 'برگشت از فروش', $rr['message']);
T::same('<b>مات</b>', BizInvoices::get($a, (int)$rr['id'])['lines'][0]['note'] ?? null, 'برگشت شرحِ ردیفِ اصلی را می‌برد');

// ---------------------------------------------------------------
T::group('۴ — مانده‌ی قبلی و کل');
// اول دوره ۱۰۰۰ + فروش ۴۰ − دریافتِ همراه ۱۵ = ۱۰۲۵
$ba = BizParties::balanceAround($a, $cus, $S1);
T::same(['prev' => 1000, 'doc' => 40, 'paid' => -15, 'after' => 1025], $ba, 'قبلی ۱۰۰۰، این سند ۴۰، دریافتِ همراه ۱۵، کل ۱۰۲۵');
// پیش‌پرداخت (بی‌فاکتور) و بعد فاکتورِ دوم: پیش‌پرداخت فقط در «قبلی» کم می‌شود
$pay = BizPay::create($a, ['kind' => 'receipt', 'party_id' => $cus, 'account_id' => $acc, 'amount' => '100', 'method' => 'cash', 'pay_date' => date('Y-m-d')]);
T::ok($pay['ok'] ?? false, 'پیش‌پرداخت ثبت شد', $pay['message'] ?? '');
$r = BizInvoices::saveDraft($a, 'sale', ['party_id' => $cus, 'inv_date' => date('Y-m-d', strtotime('+1 day')), 'lines' => [['item' => 'گلس سامسونگ', 'qty' => '1', 'price' => '60']]]);
$S2 = (int)$r['id'];
T::same(null, BizParties::balanceAround($a, $cus, $S2), 'پیش‌نویس مانده‌ی قبلی ندارد (در صورت‌حساب نیست)');
BizInvoices::issue($a, $S2, ['account_id' => $acc, 'method' => 'cash', 'amount' => '0']);
$ba2 = BizParties::balanceAround($a, $cus, $S2);
$now = (int)BizParties::get($a, $cus)['balance'];
T::same($now, $ba2['after'] ?? null, '⛔ «کل» پس از آخرین فاکتور همان مانده‌ی امروز است (پیش‌پرداخت دو بار کم نشد)', json_encode($ba2));
T::same(0, $ba2['paid'] ?? null, 'فاکتورِ نسیه دریافتِ همراه ندارد');
T::same(null, BizParties::balanceAround($b, $cus, $S1), '⛔ طرف‌حسابِ فروشگاهِ دیگر → هیچ');

// ---------------------------------------------------------------
T::group('۵ — صفحه‌ها با HTTP');
$port = 0;
for ($pp = 9411; $pp <= 9460; $pp++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$pp", $e1, $e2);
    if ($sock) { fclose($sock); $port = $pp; break; }
}
$log = tempnam(sys_get_temp_dir(), 'bsrch');
$srv = $port ? (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
    $port, escapeshellarg($root), escapeshellarg($log)))) : 0;
$up = false;
for ($i = 0; $srv && $i < 40; $i++) {
    usleep(150000);
    $sk = @fsockopen('127.0.0.1', $port, $x, $y, 0.3);
    if ($sk) { fclose($sk); $up = true; break; }
}
if (!$up) {
    T::blocked('جست‌وجوی کالا (HTTP)', 'سرورِ آزمایشی بالا نیامد');
    if ($srv) { exec("kill $srv 2>/dev/null"); }
    $wipe();
    exit(T::report());
}
$jar = tempnam(sys_get_temp_dir(), 'bsrchjar');
$req = function (string $path, ?array $post = null) use ($port, $jar): array {
    $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 40]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
};
$csrfOf = fn(string $html): string => preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m) ? $m[1] : '';
[, $lp] = $req('store/login.php');
[$c] = $req('store/login.php', ['csrf_token' => $csrfOf($lp), 'username' => SPREFIX . 'a', 'password' => SPASS]);
T::ok($c === 302, 'ورود به فروشگاه');

[$c, $ed] = $req('store/invoice-edit.php?k=sale');
T::ok($c === 200 && str_contains($ed, '>تعداد</th>') && !str_contains($ed, '>مقدار</th>'), '⛔ ستونِ «تعداد»، نه «مقدار»');
T::ok(str_contains($ed, '>شرحِ کالا</th>') && str_contains($ed, 'name="lines[0][note]"'), 'ستونِ «شرحِ کالا» با خانه‌ی توضیح');
T::ok(str_contains($ed, 'data-balance="' . (int)BizParties::get($a, $cus)['balance'] . '"'), 'منوی مشتری مانده‌ی امروزِ هر طرف‌حساب را دارد');
T::ok(str_contains($ed, 'data-balbox data-sign="1" hidden'), 'جعبه‌ی مانده بی‌مشتری پنهان است');
T::ok(str_contains($ed, 'list="bizProducts"'), 'بی‌جاوااسکریپت پاپ‌آپِ datalist سرِ جایش است');

// بی‌جاوااسکریپت: نامِ کامل با «ی» → کالای «ي»دار؛ شرح نگه داشته می‌شود
[, $body] = $req('store/invoice-edit.php?k=sale', ['csrf_token' => $csrfOf($ed), 'action' => 'addrows', 'party_id' => (string)$cus,
    'lines' => [['item' => 'شارژر سریع کابل تایپ سی', 'qty' => '1', 'price' => '', 'note' => 'سفید']]]);
T::ok(str_contains($body, 'name="lines[0][product_id]" value="' . $CH . '"') && str_contains($body, 'value="سفید"'),
    '⛔ فرمِ ردشده کالای «ي»دار را پیدا کرد و شرح را نگه داشت');
T::ok(preg_match('/data-balbox data-sign="1">/', $body) === 1, 'با مشتری، جعبه‌ی مانده باز رندر می‌شود');

[, $vw] = $req('store/invoice.php?id=' . $S1);
T::ok(str_contains($vw, 'مانده‌ی قبلیِ مشتری آزمون') && str_contains($vw, '− این سند') === false && str_contains($vw, '+ این سند'),
    'صفحه‌ی سند: مانده‌ی قبلی و «+ این سند»');
T::ok(str_contains($vw, '&lt;b&gt;مات&lt;/b&gt;') && !str_contains($vw, '<b>مات</b>'), '⛔ شرح در صفحه‌ی سند فرار داده شده');
[, $pr] = $req('store/print.php?doc=invoice&id=' . $S1);
T::ok(str_contains($pr, 'pr-bal is-prev') && str_contains($pr, 'مانده‌ی کل'), 'چاپ: مانده‌ی قبلی و کل (پیش‌فرض روشن)');
$prefs = Biz::printPrefs($a);
$prefs['show_balance'] = false;
Biz::savePrintPrefs($a, array_map(fn($v) => is_bool($v) ? ($v ? '1' : '') : $v, $prefs));
[, $pr] = $req('store/print.php?doc=invoice&id=' . $S1);
T::ok(!str_contains($pr, 'pr-bal'), '⛔ تا فاکتور طراحی نشده، کلیدِ تنظیماتِ چاپ حاکم است');
[, $ps] = $req('store/invoice-design.php');
T::ok(str_contains($ps, 'name="show_balance"'), 'کلیدش حالا در طراحیِ فاکتور است');
[, $pl] = $req('store/products.php?q=' . rawurlencode('شارژر سریع'));
T::ok(!str_contains($pl, 'کالایی با این مشخصات'), '⛔ فهرستِ کالا: متنِ فارسی کالای «ي»دارِ migration‌نخورده را هم پیدا می‌کند');

$bad = [];
foreach (['store/invoice-edit.php?k=sale', 'store/invoice-edit.php?k=purchase', 'store/quick-sale.php', 'store/invoice.php?id=' . $S1,
          'store/invoice.php?id=' . $rr['id'], 'store/print.php?doc=invoice&id=' . $S1, 'store/return.php?inv=' . $S1, 'store/index.php'] as $pg) {
    [$c, $body] = $req($pg);
    if ($c !== 200 || !str_contains($body, '</html>') || preg_match('/(Fatal error|Warning:|Notice:|Deprecated:)/', $body)) { $bad[] = "{$pg} ({$c})"; }
}
T::ok(!$bad, 'صفحه‌های فاکتور کامل و بی‌هشدار', implode('، ', $bad));

// ---------------------------------------------------------------
T::group('۶ — جست‌وجو در کرومیوم (`search_probe.js`)');
// گوشیِ در انبار برای «لمسِ گوشی»
$r = BizInvoices::saveDraft($a, 'purchase', ['party_id' => 0, 'inv_date' => date('Y-m-d'), 'lines' => [['item' => 'آیفون ۱۵ — ۱۲۸', 'qty' => '1', 'price' => '400', 'imei1' => IMS]]]);
BizInvoices::issue($a, (int)$r['id'], ['account_id' => $acc, 'method' => 'cash', 'full' => true]);
$node = trim((string)shell_exec('command -v node 2>/dev/null'));
if ($node === '') {
    T::skip('جست‌وجو در کرومیوم', 'node نیست');
} else {
    $out = (string)shell_exec(sprintf('%s %s %s %s %s %s %s 2>/dev/null', escapeshellarg($node),
        escapeshellarg(__DIR__ . '/search_probe.js'), escapeshellarg("http://127.0.0.1:{$port}/"),
        escapeshellarg(SPREFIX . 'a'), escapeshellarg(SPASS), escapeshellarg(IMS), escapeshellarg((string)$cus)));
    $pr = json_decode(trim($out), true);
    if (!is_array($pr) || empty($pr['ok'])) {
        T::skip('جست‌وجو در کرومیوم', 'کرومیوم در دسترس نیست: ' . ($pr['why'] ?? trim($out)));
    } else {
        $bal = formatMoney(abs((int)BizParties::get($a, $cus)['balance']));
        foreach ($pr['res'] as $w => $o) {
            T::ok(!$o['listAttr'], "{$w}: ⛔ پاپ‌آپِ datalist با جاوااسکریپت برداشته شد");
            T::ok(in_array('تعداد', $o['head'], true) && in_array('شرحِ کالا', $o['head'], true), "{$w}: سرآیند «تعداد» و «شرحِ کالا»");
            T::ok($o['ac0'] === ['شارژر سريع كابل تايپ سي'] && $o['acInView'], "{$w}: ⛔ «شارژر سریع» کالای «ي»دار را پیشنهاد داد، داخلِ صفحه",
                json_encode($o['ac0'], JSON_UNESCAPED_UNICODE));
            T::same(['شارژر سريع كابل تايپ سي', 'شارژر ماشین'], $o['ac0b'], "{$w}: «شارژر» دو پیشنهاد، به ترتیبِ نام");
            T::ok($o['item0'] === 'شارژر سريع كابل تايپ سي' && $o['pid0'] === (string)$CH && $o['price0'] === '۴۵۰' /* num-input.js */ && $o['focusQty'] && $o['acClosed'],
                "{$w}: ↓ + Enter انتخاب کرد: شناسه، قیمت، فوکوس روی «تعداد»",
                json_encode([$o['item0'], $o['pid0'], $o['price0'], $o['focusQty'], $o['acClosed']], JSON_UNESCAPED_UNICODE));
            T::ok($o['note0'], "{$w}: کالای غیرِ گوشی: «شرحِ کالا» توضیح است، نه IMEI");
            T::ok(count(array_filter($o['ac1'], fn($t) => str_contains($t, 'آیفون'))) >= 2, "{$w}: «ایفون» بی‌«آ» → آیفون (مدل و گوشیِ در انبار)");
            T::ok($o['imei1'] === IMS && $o['qty1'] === '۱' && $o['phoneDesc'], "{$w}: لمسِ گوشیِ در انبار: IMEI و تعدادِ ۱، شرح = IMEI",
                json_encode([$o['imei1'], $o['qty1'], $o['phoneDesc']]));
            T::ok($o['none'] && $o['escClosed'], "{$w}: متنِ بی‌کالا «نیست» می‌گوید؛ Escape می‌بندد");
            T::ok($o['balHiddenBefore'] && $o['balShown'] && toLatinDigits(preg_replace('/\D+/u', '', (string)$o['balPrev'])) === toLatinDigits(preg_replace('/\D+/u', '', $bal)), "{$w}: انتخابِ مشتری مانده‌ی قبلی را نشان داد",
                json_encode([$o['balPrev'], $bal], JSON_UNESCAPED_UNICODE));
            T::ok(str_ends_with((string)$o['balLink'], 'party.php?id=' . $cus), "{$w}: لینکِ صورت‌حساب به همان مشتری");
            T::same('کیف چرمی', $o['quick'], "{$w}: فروشِ سریع: متنِ ناقص + Enter با یک پیشنهاد ردیف را پر کرد");
            T::ok($o['sw'] <= $o['W'], "{$w}: بی‌اسکرولِ افقی", "{$o['sw']} > {$o['W']}");
        }
    }
}

exec("kill $srv 2>/dev/null");
@unlink($log); @unlink($jar);
$wipe();
exit(T::report());
