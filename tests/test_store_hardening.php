<?php
/**
 * ⛔ سخت‌سازیِ محیطِ فروشگاهی پیش از عرضه — بازرسیِ مهر ۱۴۰۵.
 *
 * **خواسته‌ی مالکِ نصب:** «بخشِ فروشگاهی را بررسی کن … می‌خوام سیستم بی‌نقص
 * باشه در همه‌جا برای وارد شدن به معامله‌ی واقعی.»
 *
 * هر گروه یک خرابیِ **بازتولیدشده** است — نه حدس — و پیش از اصلاح همین
 * سناریو عددِ غلط می‌داد. اگر روزی یکی قرمز شد، همان پول دوباره گم می‌شود.
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
require_once __DIR__ . '/../includes/biz_io.php';

const HPREFIX = '__bhard_';
const HPASS   = 'Hard12345';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::blocked('سخت‌سازیِ فروشگاه', 'اتصال به دیتابیس برقرار نشد: ' . $e->getMessage());
    exit(T::report());
}
if (!Biz::available() || !tableHasColumn('biz_invoice_lines', 'imei1') || !BizSerial::hasFirstIssued()) {
    T::blocked('سخت‌سازیِ فروشگاه', 'ستون‌های فروشگاه نیست — اول: bash deploy/migrate.sh --apply');
    exit(T::report());
}

$wipe = function () use ($pdo) {
    $ids = $pdo->query("SELECT id FROM users WHERE username LIKE '" . HPREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN);
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
            catch (PDOException $e) { }
        }
        $pdo->prepare('DELETE FROM audit_log WHERE target_user_id = :u')->execute(['u' => $id]);
    }
    $pdo->exec("DELETE FROM users WHERE username LIKE '" . HPREFIX . "%'");
};
$wipe();

$make = function (string $name) use ($pdo): int {
    $res = createUserAccount($pdo, 'کاربرِ ' . $name, HPREFIX . $name, HPREFIX . $name . '@example.com', HPASS);
    if (!($res['ok'] ?? false)) { throw new RuntimeException('ساختِ کاربر: ' . ($res['error'] ?? '?')); }
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => HPREFIX . $name]);
    $id = (int)$st->fetchColumn();
    Biz::setType($id, 'business');
    return $id;
};

$L = fn(string $item, string $qty, string $price, string $i1 = '', string $i2 = ''): array
    => ['item' => $item, 'qty' => $qty, 'price' => $price, 'imei1' => $i1, 'imei2' => $i2];
$acc = fn(int $u): int => (int)BizCash::list($u)[0]['id'];
/** پیش‌نویس + صدور؛ شناسه یا پیامِ خطا */
$doc = function (int $u, string $kind, array $lines, array $head = []) use ($acc) {
    $r = BizInvoices::saveDraft($u, $kind, ['lines' => $lines] + $head);
    if (!$r['ok']) { return $r['message']; }
    $i = BizInvoices::issue($u, (int)$r['id'], ['full' => true, 'account_id' => $acc($u)]);
    return $i['ok'] ? (int)$r['id'] : $i['message'];
};
$stock = fn(int $u, int $pid): float => (float)BizProducts::get($u, $pid)['stock_qty'];
$inStock = fn(int $u, int $pid = 0): array => array_column(BizSerial::inStock($u, $pid)['rows'], 'imei1');

const X = '350000000000006';
const Y = '350000000079190';
const Z = '350000000158382';

// =================================================================
T::group('۱ — گوشیِ فروخته‌شده با «اصلاح و صدورِ دوباره»ی خریدش برنمی‌گردد');
$u = $make('a');
$PH = (int)BizProducts::save($u, ['type' => 'phone', 'name' => 'گوشی آزمون', 'unit' => 'دستگاه', 'buy_price' => '400', 'sell_price' => '500'])['id'];
$p1 = $doc($u, 'purchase', [$L('گوشی آزمون', '1', '400', X)]);
$p2 = $doc($u, 'purchase', [$L('گوشی آزمون', '1', '450', Y)]);
$s1 = $doc($u, 'sale', [$L('گوشی آزمون', '1', '600', X)]);
T::ok(is_int($p1) && is_int($p2) && is_int($s1), 'خریدِ X و Y، فروشِ X', var_export([$p1, $p2, $s1], true));

$r = BizInvoices::unissue($u, $p1);
T::ok($r['ok'], 'خریدِ X برای اصلاحِ قیمت به پیش‌نویس رفت', $r['message']);
$r = BizInvoices::issue($u, $p1, ['full' => true, 'account_id' => $acc($u)]);
T::ok($r['ok'], 'و دوباره صادر شد', $r['message']);
T::same([Y], $inStock($u, $PH), '⛔ فقط Y در انبار است — X فروخته شده و با صدورِ دوباره‌ی خریدش برنگشت');
T::same('out', BizSerial::lookup($u, X)['dir'] ?? null, 'سرگذشتِ X: آخرین رخداد همان فروش است');
T::ok(!is_int($doc($u, 'sale', [$L('گوشی آزمون', '1', '600', X)])), '⛔ X دوباره فروختنی نیست');

$r = BizInvoices::void($u, $p1);
T::ok(!$r['ok'] && str_contains($r['message'], X), '⛔ ابطالِ خریدِ گوشیِ فروخته‌شده رد می‌شود (سرگذشت بی‌ورود می‌ماند)', $r['message']);
$r = BizInvoices::void($u, $p2);
T::ok($r['ok'], 'ابطالِ خریدی که گوشی‌اش هنوز در انبار است آزاد است', $r['message']);

// اصلاحِ فروشی که گوشی‌اش بعداً دوباره خریده و فروخته شده
$p3 = $doc($u, 'purchase', [$L('گوشی آزمون', '1', '300', X)]);
$s2 = $doc($u, 'sale', [$L('گوشی آزمون', '1', '650', X)]);
T::ok(is_int($p3) && is_int($s2), 'X دوباره خریده و فروخته شد');
$r = BizInvoices::unissue($u, $s1);
$r2 = $r['ok'] ? BizInvoices::issue($u, $s1, ['full' => true, 'account_id' => $acc($u)]) : $r;
T::ok($r2['ok'], '⛔ اصلاحِ فروشِ اولِ X رد نمی‌شود: وضعیت در جای خودِ سند سنجیده می‌شود', $r2['message']);
T::same([], $inStock($u, $PH), 'و X بیرون است (فروشِ دوم آخرین رخداد است)');

// =================================================================
T::group('۲ — خواندنِ قفل‌دارِ حرکت‌ها');
$src = (string)file_get_contents(__DIR__ . '/../includes/biz_catalog.php');
T::ok((bool)preg_match('/FROM biz_stock_moves\s+WHERE product_id = :p AND user_id = :u\s+ORDER BY[^"]*FOR UPDATE/', $src),
    '⛔ `recalc()` حرکت‌ها را با `FOR UPDATE` می‌خواند (دو صدورِ هم‌زمان هم را می‌بینند)');

/**
 * N پردازشِ **جدا** هم‌زمان (هر کدام اتصالِ خودش به دیتابیس) — مسابقه‌ی واقعی،
 * نه شبیه‌سازی. هر پردازش `$code` را با `$u` اجرا می‌کند و یک خط چاپ می‌کند.
 * @return string[] خروجیِ هر پردازش
 */
$race = function (int $n, string $code): array {
    $tmp = tempnam(sys_get_temp_dir(), 'bhrace') . '.php';
    file_put_contents($tmp, "<?php\nrequire '" . __DIR__ . "/../includes/db.php';\nrequire '" . __DIR__ . "/../includes/functions.php';\n"
        . "require '" . __DIR__ . "/../includes/biz_catalog.php';\nrequire '" . __DIR__ . "/../includes/biz_docs.php';\n"
        . "usleep(random_int(0, 20000));\ntry {\n" . $code . "\n} catch (Throwable \$e) { echo 'EXC ' . \$e->getMessage(); }\n");
    $procs = []; $pipes = [];
    for ($i = 0; $i < $n; $i++) {
        $procs[$i] = proc_open([PHP_BINARY, $tmp], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
    }
    $out = [];
    foreach ($procs as $i => $pr) {
        $out[] = trim((string)stream_get_contents($pipes[$i][1]) . (string)stream_get_contents($pipes[$i][2]));
        proc_close($pr);
    }
    @unlink($tmp);
    return $out;
};

// =================================================================
T::group('۳ — برگشت: نه به پیش‌نویس، نه دو بار، نه هم‌زمان');
$u = $make('b');
$party = (int)BizParties::save($u, ['name' => 'مشتری برگشت', 'kind' => 'customer'])['id'];
$AC = (int)BizProducts::save($u, ['type' => 'goods', 'name' => 'گارد', 'unit' => 'عدد', 'buy_price' => '100', 'sell_price' => '150', 'opening_qty' => '10'])['id'];
$sale = $doc($u, 'sale', [$L('گارد', '2', '150')], ['party_id' => $party]);
$ret = BizInvoices::createReturn($u, $sale, [(int)BizInvoices::get($u, $sale)['lines'][0]['id'] => '2'], ['full' => true, 'account_id' => $acc($u)]);
T::ok($ret['ok'], 'برگشتِ ۲ تا از فروشِ ۲ تایی', $ret['message']);
$r = BizInvoices::unissue($u, (int)$ret['id']);
T::ok(!$r['ok'], '⛔ برگشت به پیش‌نویس نمی‌رود (پیوندِ ردیف‌ها به فاکتورِ اصلی می‌ماند)', $r['message']);
T::same(10.0, $stock($u, $AC), 'موجودی همان ۱۰');

$sale2 = $doc($u, 'sale', [$L('گارد', '1', '150')], ['party_id' => $party]);
$line2 = (int)BizInvoices::get($u, $sale2)['lines'][0]['id'];
$outs = $race(6, "\$r = BizInvoices::createReturn({$u}, {$sale2}, [{$line2} => '1'], ['full' => true, 'account_id' => " . $acc($u) . "]); echo \$r['ok'] ? 'OK' : 'NO';");
$okN = count(array_filter($outs, fn($o) => $o === 'OK'));
T::same(1, $okN, '⛔ شش برگشتِ هم‌زمانِ یک قلمِ تکی: فقط یکی می‌گذرد', implode(' | ', $outs));
T::ok(!array_filter($outs, fn($o) => str_starts_with($o, 'EXC')), '⛔ و هیچ‌کدام بن‌بست/استثنا نمی‌گیرد (قفلِ فروشگاه)', implode(' | ', $outs));
T::same(10.0, $stock($u, $AC), 'موجودی دقیقاً یک بار برگشت');

// =================================================================
T::group('۴ — شماره‌ی فاکتور دوباره داده نمی‌شود');
$s3 = $doc($u, 'sale', [$L('گارد', '1', '150')], ['party_id' => $party]);
$num = (int)BizInvoices::get($u, $s3)['number'];
BizInvoices::unissue($u, $s3);
$r = BizInvoices::deleteDraft($u, $s3);
T::ok($r['ok'] && BizInvoices::get($u, $s3)['status'] === 'void', '⛔ پیش‌نویسِ شماره‌دار حذف نمی‌شود، باطل می‌شود', $r['message']);
$s4 = $doc($u, 'sale', [$L('گارد', '1', '150')], ['party_id' => $party]);
T::ok((int)BizInvoices::get($u, $s4)['number'] === $num + 1, '⛔ فاکتورِ بعدی شماره‌ی تازه می‌گیرد، نه همان ' . $num,
    (string)BizInvoices::get($u, $s4)['number']);
$d = BizInvoices::saveDraft($u, 'sale', ['lines' => [$L('گارد', '1', '150')]]);
$r = BizInvoices::deleteDraft($u, (int)$d['id']);
T::ok($r['ok'] && BizInvoices::get($u, (int)$d['id']) === null, 'پیش‌نویسِ بی‌شماره مثلِ قبل حذف می‌شود');

// =================================================================
T::group('۵ — صدورِ هم‌زمان از چند دستگاه');
$before = $stock($u, $AC);
$outs = $race(8, "\$r = BizInvoices::saveDraft({$u}, 'sale', ['party_id' => {$party}, 'lines' => [['item' => 'گارد', 'qty' => '1', 'price' => '150']]]);"
    . " \$i = BizInvoices::issue({$u}, (int)\$r['id'], ['full' => true, 'account_id' => " . $acc($u) . "]); echo \$i['ok'] ? 'OK' : 'NO ' . \$i['message'];");
T::ok(count(array_filter($outs, fn($o) => $o === 'OK')) === 8, '⛔ هشت فروشِ هم‌زمان: همه صادر شدند، بی‌بن‌بست', implode(' | ', $outs));
$nums = $pdo->prepare("SELECT number FROM biz_invoices WHERE user_id = :u AND kind = 'sale' AND number IS NOT NULL");
$nums->execute(['u' => $u]);
$all = array_map('intval', $nums->fetchAll(PDO::FETCH_COLUMN));
T::same(count($all), count(array_unique($all)), '⛔ هیچ شماره‌ی تکراری');
T::same($before - 8, $stock($u, $AC), 'موجودی دقیقاً هشت تا کم شد');

// =================================================================
T::group('۷ — نوعِ کالا، واحدِ گوشی، و تطبیقِ نام در ورود از فایل');
$u = $make('d');
$G = (int)BizProducts::save($u, ['type' => 'goods', 'name' => 'کابل 1.5 متری', 'unit' => 'عدد', 'buy_price' => '100', 'sell_price' => '200', 'opening_qty' => '10'])['id'];
$sd = $doc($u, 'sale', [$L('کابل 1.5 متری', '10', '200')]);
T::ok(is_int($sd), 'کلِ موجودی فروخته شد');
$r = BizProducts::save($u, ['type' => 'service', 'name' => 'کابل 1.5 متری', 'unit' => 'عدد', 'sell_price' => '200'], $G);
T::ok(!$r['ok'], '⛔ کالای فروخته‌شده (حتی با موجودیِ صفر) خدمت نمی‌شود — سودِ ماه‌های گذشته بازنویسی می‌شد', $r['message']);
$NS = (int)BizProducts::save($u, ['type' => 'goods', 'name' => 'قلمِ تازه', 'unit' => 'عدد'])['id'];
$r = BizProducts::save($u, ['type' => 'service', 'name' => 'قلمِ تازه', 'unit' => 'عدد'], $NS);
T::ok($r['ok'], 'قلمِ بی‌سابقه آزادانه خدمت می‌شود', $r['message']);

$PH = (int)BizProducts::save($u, ['type' => 'phone', 'name' => 'گوشی د', 'unit' => 'دستگاه'])['id'];
$r = BizProducts::save($u, ['name' => 'گوشی د', 'unit' => 'کیلوگرم'], $PH);
T::ok(!$r['ok'] && (int)BizProducts::get($u, $PH)['has_serial'] === 1 && BizProducts::get($u, $PH)['unit'] === 'دستگاه',
    '⛔ مسیرِ بی‌`type` (ورود از فایل) گوشی را «کیلوگرمی» نمی‌کند', $r['message']);

T::ok(BizImport::nameKey('کابل 1.5 متری') !== BizImport::nameKey('کابل 15 متری'),
    '⛔ «کابل 1.5 متری» و «کابل 15 متری» دو کالای جدا هستند (نقطه دور ریخته نمی‌شود)');
T::same(BizImport::nameKey('كابل  1.5 متري'), BizImport::nameKey('کابل 1.5 متری'), 'ی/ک عربی و فاصله‌ی دوتایی یکی می‌شوند');

// =================================================================
T::group('۸ — قفلِ دوره: موجودیِ اول دوره با قدیمی‌ترین حرکت سنجیده می‌شود');
$O = (int)BizProducts::save($u, ['type' => 'goods', 'name' => 'قاب امروز', 'unit' => 'عدد', 'buy_price' => '100', 'opening_qty' => '10', 'opening_cost' => '100'])['id'];
$past = date('Y-m-d', strtotime('-20 days'));
$sp = $doc($u, 'sale', [$L('قاب امروز', '2', '200')], ['inv_date' => $past]);
T::ok(is_int($sp), 'فروشِ تاریخ‌گذشته‌ی کالای امروز', is_int($sp) ? '' : (string)$sp);
Biz::saveLock($u, date('Y-m-d', strtotime('-10 days')), true);
$r = BizStock::setOpening($u, $O, 10, 140);
T::ok(!$r['ok'], '⛔ اول دوره‌ی کالای تازه عوض نمی‌شود وقتی فروشش در دوره‌ی بسته است', $r['message']);
Biz::saveLock($u, '', true);

// =================================================================
T::group('۹ — غیرفعال کردن پول را از جمع‌ها بیرون نمی‌برد');
$cust = (int)BizParties::save($u, ['name' => 'بدهکار', 'kind' => 'customer'])['id'];
$sc = BizInvoices::saveDraft($u, 'sale', ['party_id' => $cust, 'lines' => [$L('قاب امروز', '1', '1000')]]);
BizInvoices::issue($u, (int)$sc['id'], ['amount' => '0', 'account_id' => $acc($u)]);
$r = BizParties::setActive($u, $cust, false);
T::ok(!$r['ok'], '⛔ طرف‌حسابِ بدهکار غیرفعال نمی‌شود', $r['message']);
$r = BizProducts::setActive($u, $O, false);
T::ok(!$r['ok'], '⛔ کالای موجوددار غیرفعال نمی‌شود', $r['message']);
$bank = (int)BizCash::save($u, ['name' => 'بانک د', 'kind' => 'bank', 'opening_balance' => '5000'])['id'];
$r = BizCash::setActive($u, $bank, false);
T::ok(!$r['ok'], '⛔ حسابِ موجودی‌دار غیرفعال نمی‌شود', $r['message']);
$empty = (int)BizParties::save($u, ['name' => 'بی‌مانده', 'kind' => 'customer'])['id'];
T::ok(BizParties::setActive($u, $empty, false)['ok'], 'طرف‌حسابِ بی‌مانده آزادانه غیرفعال می‌شود');

// =================================================================
T::group('۱۰ — کالای انبارگردانی‌شده حذف نمی‌شود');
$J = (int)BizProducts::save($u, ['type' => 'goods', 'name' => 'شمارشی', 'unit' => 'عدد', 'buy_price' => '10', 'opening_qty' => '5'])['id'];
BizStock::adjustTo($u, $J, 3, 'کسری');
$r = BizProducts::delete($u, $J);
T::ok(!$r['ok'] && BizProducts::get($u, $J) !== null, '⛔ کسریِ شمارش زیانِ واقعی است؛ حذفِ کالا آن را از سود پاک نمی‌کند', $r['message']);
$K = (int)BizProducts::save($u, ['type' => 'goods', 'name' => 'فقط اول دوره', 'unit' => 'عدد', 'opening_qty' => '2'])['id'];
T::ok(BizProducts::delete($u, $K)['ok'], 'کالای فقط با اول دوره مثلِ قبل حذف‌شدنی است');

// =================================================================
T::group('۱۱ — IMEIِ ناشناخته و دو شماره‌ی یک گوشی');
$u2 = $make('e');
$P2 = (int)BizProducts::save($u2, ['type' => 'phone', 'name' => 'گوشی ه', 'unit' => 'دستگاه'])['id'];
$doc($u2, 'purchase', [$L('گوشی ه', '1', '500', X, Y)]);
$doc($u2, 'purchase', [$L('گوشی ه', '1', '500', Z)]);
$typo = '350000000000014';
$r = $doc($u2, 'sale', [$L('گوشی ه', '1', '700', $typo)]);
T::ok(!is_int($r) && str_contains((string)$r, 'تایپ'), '⛔ IMEIِ ناشناخته وقتی همه‌ی گوشی‌ها IMEI دارند رد می‌شود (اشتباهِ تایپی)', (string)$r);
$s2 = $doc($u2, 'sale', [$L('گوشی ه', '1', '700', Y)]);
T::ok(is_int($s2), 'فروش با IMEIِ دومِ گوشی', is_int($s2) ? '' : (string)$s2);
T::same('out', BizSerial::lookup($u2, X)['dir'] ?? null, '⛔ IMEIِ اولِ همان گوشی هم بیرون است (یک دستگاه، دو شماره)');
T::ok(!is_int($doc($u2, 'sale', [$L('گوشی ه', '1', '700', X)])), '⛔ همان گوشی با IMEIِ اولش دوباره فروخته نمی‌شود');
T::same([Z], $inStock($u2, $P2), 'فقط Z در انبار');
// گوشیِ اول دوره (بی‌IMEI) هنوز با IMEIِ تازه فروختنی است
BizStock::setOpening($u2, $P2, 1, 400);
$r = $doc($u2, 'sale', [$L('گوشی ه', '1', '700', $typo)]);
T::ok(is_int($r), 'IMEIِ ناشناخته از موجودیِ بی‌IMEI (اول دوره) پذیرفته است', is_int($r) ? '' : (string)$r);

// =================================================================
T::group('۱۲ — عددها: «۱٬۰۰۰» و هزینه‌ی جانبی روی فاکتورِ صفر');
T::same(1000.0, sanitizeQty('1,000'), '⛔ «1,000» هزار است');
T::same(2.5, sanitizeQty('2,5'), '«2,5» همان ۲٫۵');
$t = BizInvoices::totals([['line_total' => 0], ['line_total' => 0]], 0, 70);
T::same(70, array_sum(array_column($t['lines'], 'net_total')), '⛔ جمعِ ردیف‌ها = جمعِ فاکتور، حتی وقتی همه‌ی ردیف‌ها صفرند');

// =================================================================
T::group('۱۳ — دریافت از سایت: مهلتِ کل و یک دریافت در هر لحظه');
BizFetch::$deadline = time() - 1;
$r = BizFetch::get('https://example.com/x.csv');
BizFetch::$deadline = null;
T::ok(!$r['ok'] && str_contains((string)$r['message'], 'کند'), '⛔ مهلتِ کل که گذشت، درخواستِ تازه‌ای زده نمی‌شود', (string)$r['message']);
$lockDir = dirname(__DIR__) . '/var/biz-import';
@mkdir($lockDir, 0700, true);
$fh = fopen($lockDir . '/fetch-' . $u . '.lock', 'c');
flock($fh, LOCK_EX);
// قفل در همین پردازش است؛ flock روی توصیف‌گرِ دیگر در لینوکس هم رد می‌شود
$r = BizImport::fromUrl('https://example.com/x.csv', $u);
flock($fh, LOCK_UN); fclose($fh);
T::ok(!$r['ok'] && str_contains((string)$r['message'], 'در جریان'), '⛔ دریافتِ دوم هم‌زمان برای همان فروشگاه رد می‌شود', (string)$r['message']);
// آدرسِ داخلی همان‌جا رد می‌شود (سدِ SSRF) — بی‌شبکه، ولی از مسیرِ کاملِ `fromUrl()`
$r = BizImport::fromUrl('http://127.0.0.1/x.csv', $u);
T::ok(!$r['ok'] && BizFetch::$deadline === null, '⛔ مهلتِ کل پس از کار پاک می‌شود (درخواست‌های بعدیِ همین پردازش کوتاه نمی‌شوند)');

// =================================================================
T::group('۱۴ — بازگرداندنِ بکاپِ شخصی دفترِ فروشگاه را دست نمی‌زند');
require_once __DIR__ . '/../includes/user_import.php';
$imp = importableTables(['transactions' => [['id' => 1]], 'biz_products' => []]);
T::ok(!array_filter($imp, fn($t) => str_starts_with($t, 'biz_')),
    '⛔ بکاپِ شخصی (بی‌دفترِ فروشگاه) هیچ جدولِ فروشگاه را پاک نمی‌کند', implode(',', array_filter($imp, fn($t) => str_starts_with($t, 'biz_'))));
T::ok(in_array('transactions', $imp, true), 'جدول‌های شخصی مثلِ قبل بازگردانده می‌شوند');
$full = importableTables(['transactions' => [], 'biz_products' => [['id' => 1]]]);
T::ok(in_array('biz_products', $full, true) && in_array('biz_invoices', $full, true), 'بکاپِ کاملِ فروشگاه همچنان کلِ دفترش را برمی‌گرداند');

// =================================================================
T::group('۱۵ — تاریخ، سقفِ عدد، پرداختِ بخشی، قیمتِ خالی، جست‌وجوی طرف‌حساب');
require_once __DIR__ . '/../includes/biz_docview.php';
T::same(date('Y-m-d'), BizDocView::docDate('')['date'] ?? null, 'تاریخِ خالی = امروز');
foreach (['۱۴۰۵/۰۷/۴۵', '1405/12/30', '05/07/01', 'دیروز'] as $bad) {
    $dr = BizDocView::docDate($bad);
    T::ok(!$dr['ok'] && str_contains((string)$dr['message'], 'معتبر نیست'), "⛔ «{$bad}» امروز نمی‌شود، «معتبر نیست» گفته می‌شود", (string)($dr['message'] ?? ''));
}
T::ok(!BizDocView::docDate('1450/01/01')['ok'] && !BizDocView::docDate('1305/01/01')['ok'], '⛔ سالِ ۱۴۵۰ و ۱۳۰۵ بیرون از بازه');
T::ok(BizDocView::docDate('1450/01/01', 'سررسید', true)['ok'] === false && BizDocView::docDate(toJalali(date('Y-m-d', strtotime('+3 years'))), 'سررسید', true)['ok'],
    'سررسیدِ چک تا پنج سال جلوتر پذیرفته است');

$u3 = $make('f');
$AC3 = (int)BizProducts::save($u3, ['type' => 'goods', 'name' => 'هندزفری', 'unit' => 'عدد', 'buy_price' => '100', 'sell_price' => '250', 'opening_qty' => '20'])['id'];
$r = BizInvoices::saveDraft($u3, 'sale', ['lines' => [$L('هندزفری', '1', '999999999999999')]]);
T::ok(!$r['ok'] && str_contains($r['message'], 'فی بیش از حد بزرگ'), '⛔ قیمتِ پانزده‌رقمی (بارکدِ اسکن‌شده) رد می‌شود و خودِ «فی» گفته می‌شود، نه ۵۰۰', $r['message']);
$r = BizInvoices::saveDraft($u3, 'sale', ['lines' => [$L('هندزفری', '99999999999', '10')]]);
T::ok(!$r['ok'], '⛔ مقدارِ بیش از سقف رد می‌شود', $r['message']);
$r = BizParties::save($u3, ['name' => 'بزرگ', 'kind' => 'customer', 'opening_amount' => '99999999999999999999']);
T::ok(!$r['ok'], '⛔ مانده‌ی اول دوره‌ی بیست‌رقمی رد می‌شود (داشبورد سرریز نمی‌کند)', $r['message']);

$d = BizInvoices::saveDraft($u3, 'sale', ['lines' => [$L('هندزفری', '2', '')]]);
T::ok($d['ok'] && (int)BizInvoices::get($u3, (int)$d['id'])['total'] === 500, '⛔ قیمتِ خالی = قیمتِ فروشِ کالا، نه فروشِ مجانی',
    (string)(BizInvoices::get($u3, (int)($d['id'] ?? 0))['total'] ?? ''));
$cust3 = (int)BizParties::save($u3, ['name' => 'علي رضايي', 'kind' => 'customer', 'phone' => '0912 123 4567'])['id'];
$d2 = BizInvoices::saveDraft($u3, 'sale', ['party_id' => $cust3, 'lines' => [$L('هندزفری', '1', '250')]]);
$r = BizInvoices::issue($u3, (int)$d2['id'], ['amount' => '2500', 'account_id' => $acc($u3), 'part' => true]);
T::ok(!$r['ok'], '⛔ «بخشی» بیش از جمعِ فاکتور (یک صفرِ اضافه) رد می‌شود', $r['message']);
$r = BizInvoices::issue($u3, (int)$d2['id'], ['amount' => '', 'account_id' => $acc($u3), 'part' => true]);
T::ok(!$r['ok'], '⛔ «بخشی» بی‌مبلغ بی‌صدا نسیه نمی‌شود', $r['message']);
$r = BizInvoices::issue($u3, (int)$d2['id'], ['amount' => '100', 'account_id' => $acc($u3), 'part' => true]);
T::ok($r['ok'], 'بخشیِ درست صادر می‌شود', $r['message']);

T::same('علی رضایی', BizParties::get($u3, $cust3)['name'], 'نامِ عربی‌تایپ‌شده با ی/ک فارسی ذخیره می‌شود');
T::same(1, BizParties::list($u3, 'علي')['total'], '⛔ جست‌وجوی «علي» (ی عربی) پیدایش می‌کند');
T::same(1, BizParties::list($u3, '۰۹۱۲۱۲۳۴۵۶۷')['total'], '⛔ تلفنِ بی‌فاصله تلفنِ فاصله‌دار را پیدا می‌کند');
BizParties::save($u3, ['name' => 'مهدی', 'kind' => 'customer', 'phone' => '09351112233']);
T::same(1, BizParties::list($u3, '0935 111-2233')['total'], '⛔ و تلفنِ فاصله‌دارِ جست‌وجو تلفنِ بی‌فاصله را');

// =================================================================
T::group('۱۶ — رقمِ کنترلِ IMEI: هشدار، نه سد');
T::same(true, BizSerial::luhnOk('490154203237518'), 'IMEIِ درست (نمونه‌ی استاندارد)');
T::same(false, BizSerial::luhnOk('490154203237519'), 'یک رقمِ آخرِ غلط');
T::same(false, BizSerial::luhnOk('490154203237528'), 'جابه‌جاییِ دو رقمِ کنار هم هم گرفته می‌شود');
T::same(null, BizSerial::luhnOk('49015420323751'), '۱۴ رقم رقمِ کنترل ندارد');
T::same(null, BizSerial::luhnOk('4901542032375180'), '۱۶ رقم (IMEISV) رقمِ کنترل ندارد');
T::same(['490154203237519'], BizSerial::luhnWarnings([['imei1' => '490154203237518', 'imei2' => '490154203237519'], ['imei1' => '490154203237519']]),
        'فقط IMEIِ غلط، یک بار');
$u6 = $make('luhn');
BizProducts::save($u6, ['type' => 'phone', 'name' => 'گوشی کپی', 'unit' => 'دستگاه', 'buy_price' => '100', 'sell_price' => '150']);
const BAD = '490154203237519';
$r = BizInvoices::saveDraft($u6, 'purchase', ['lines' => [$L('گوشی کپی', '1', '100', BAD)]]);
$i = BizInvoices::issue($u6, (int)$r['id'], ['full' => true, 'account_id' => $acc($u6)]);
T::ok($i['ok'], '⛔ خریدِ گوشی با IMEIِ نامعتبر **ثبت شد** (سد نیست)', $i['message']);
T::ok(str_contains($i['message'], BAD) && str_contains($i['message'], 'رقمِ کنترل') && $i['imei_warn'] !== '', 'و پیامِ صدور هشدار دارد');
$sId = BizInvoices::saveDraft($u6, 'sale', ['lines' => [$L('گوشی کپی', '1', '150', BAD)]]);
$i2 = BizInvoices::issue($u6, (int)$sId['id'], ['full' => true, 'account_id' => $acc($u6)]);
T::ok($i2['ok'] && $i2['imei_warn'] !== '', 'فروشش هم ثبت شد، با هشدار');
$i3 = BizInvoices::createReturn($u6, (int)$sId['id'], [(int)BizInvoices::get($u6, (int)$sId['id'])['lines'][0]['id'] => '1'], ['full' => true, 'account_id' => $acc($u6)]);
T::ok($i3['ok'] && ($i3['imei_warn'] ?? '') !== '', 'برگشتش هم، با هشدار', $i3['message']);
$okP = BizInvoices::saveDraft($u6, 'purchase', ['lines' => [$L('گوشی کپی', '1', '100', '490154203237518')]]);
$i4 = BizInvoices::issue($u6, (int)$okP['id'], ['full' => true, 'account_id' => $acc($u6)]);
T::ok($i4['ok'] && $i4['imei_warn'] === '' && !str_contains($i4['message'], '⚠'), 'IMEIِ درست بی‌هشدار');
T::ok(str_contains(BizDocView::luhnBadge(['imei1' => BAD]), 'st-imei-warn') && BizDocView::luhnBadge(['imei1' => '490154203237518']) === '',
      'نشانِ صفحه‌ی سند فقط برای IMEIِ غلط');

// =================================================================
T::group('۱۷ — بازبینیِ دوم (مهر ۱۴۰۵): ترتیبِ IMEI، انبارگردانی، گذری، چک، تخفیف، BizOnce');
$day = fn(int $n): string => date('Y-m-d', strtotime(($n >= 0 ? '+' : '') . $n . ' day'));
$r7 = $make('r7');
$PH7 = (int)BizProducts::save($r7, ['type' => 'phone', 'name' => 'گوشی ترتیب', 'unit' => 'دستگاه', 'buy_price' => '400', 'sell_price' => '500'])['id'];
BizStock::setOpening($r7, $PH7, 1, 400);                       // یک گوشیِ اول دوره، بی‌IMEI
const X7 = '351111111111111';
const Y7 = '352222222222222';
const Z7 = '353333333333333';
// ⛔ خریدِ تاریخ‌گذشته‌ای که **بعد از** فروشِ همان گوشی ثبت شد: سرگذشت به ترتیبِ
//    تاریخِ سند (همان ترتیبِ انبار)، نه زمانِ صدور
T::ok(is_int($doc($r7, 'sale', [$L('گوشی ترتیب', '1', '600', X7)])), 'فروشِ امروزِ X (از موجودیِ بی‌IMEI)');
T::ok(is_int($doc($r7, 'purchase', [$L('گوشی ترتیب', '1', '400', X7)], ['inv_date' => $day(-3)])), 'خریدِ X با تاریخِ ۳ روز پیش، دیرتر ثبت شد');
T::same('out', BizSerial::lookup($r7, X7)['dir'] ?? null, '⛔ X بیرون است (فروشِ امروز بعد از خریدِ ۳ روز پیش)');
T::same([], $inStock($r7, $PH7), '⛔ و در فهرستِ گوشی‌های در انبار نیست');
T::ok(!is_int($doc($r7, 'sale', [$L('گوشی ترتیب', '1', '600', X7)])), '⛔ X دوباره فروخته نمی‌شود');
$rep7 = BizSerial::report($r7, '', $PH7);
T::same([0, 1], [$rep7['in'], $rep7['out']], 'گزارشِ IMEI همان ترتیب را دارد');
// ⛔ سندِ تاریخ‌گذشته وسطِ سرگذشت می‌نشیند — رخدادِ **بعدی** هم سنجیده می‌شود
T::ok(is_int($doc($r7, 'purchase', [$L('گوشی ترتیب', '1', '450', Y7)], ['inv_date' => $day(-5)])), 'خریدِ Y پنج روز پیش');
T::ok(is_int($doc($r7, 'sale', [$L('گوشی ترتیب', '1', '650', Y7)])), 'فروشِ Y امروز');
$bs7 = $doc($r7, 'sale', [$L('گوشی ترتیب', '1', '650', Y7)], ['inv_date' => $day(-2)]);
T::ok(is_string($bs7) && str_contains($bs7, Y7), '⛔ فروشِ تاریخ‌گذشته‌ی Y (که بعداً فروخته شده) رد می‌شود — یک گوشی دو بار بیرون نمی‌رود', var_export($bs7, true));
T::ok(is_int($doc($r7, 'purchase', [$L('گوشی ترتیب', '1', '300', Z7)], ['inv_date' => $day(-1)])), 'خریدِ Z دیروز');
$bp7 = $doc($r7, 'purchase', [$L('گوشی ترتیب', '1', '300', Z7)], ['inv_date' => $day(-4)]);
T::ok(is_string($bp7) && str_contains($bp7, Z7), '⛔ خریدِ تاریخ‌گذشته‌ی Z پیش از خریدِ بعدی‌اش رد می‌شود — یک گوشی دو بار وارد نمی‌شود', var_export($bp7, true));

// ⛔ انبارگردانی با موجودیِ «تا امروز» سنجیده می‌شود، نه `stock_qty` (که سندِ آینده را دارد)
$a7 = $make('adj');
$G7 = (int)BizProducts::save($a7, ['type' => 'goods', 'name' => 'کالای شمارش', 'unit' => 'عدد', 'buy_price' => '100', 'sell_price' => '150'])['id'];
$doc($a7, 'purchase', [$L('کالای شمارش', '5', '100')]);
$doc($a7, 'purchase', [$L('کالای شمارش', '10', '100')], ['inv_date' => $day(3)]);
T::same(15.0, $stock($a7, $G7), 'موجودی با خریدِ سه روزِ بعد ۱۵');
$r = BizStock::adjustTo($a7, $G7, 5, 'شمارش');
T::ok($r['ok'] && str_contains($r['message'], 'همان'), '⛔ شمارشِ درستِ امروز (۵) «تغییری نیست» است، نه «منفی می‌شود»', $r['message']);
$r = BizStock::adjustTo($a7, $G7, 4, 'یکی گم شد');
T::ok($r['ok'], 'شمارشِ ۴ ثبت شد', $r['message']);
T::same(14.0, $stock($a7, $G7), '⛔ کسریِ یکی: ۱۵ → ۱۴ (نه حرکتِ −۱۱)');
$b7 = $make('adj2');
$H7 = (int)BizProducts::save($b7, ['type' => 'goods', 'name' => 'کالای پیش‌فروش', 'unit' => 'عدد', 'buy_price' => '100', 'sell_price' => '150'])['id'];
$doc($b7, 'purchase', [$L('کالای پیش‌فروش', '10', '100')], ['inv_date' => $day(-5)]);
$doc($b7, 'sale', [$L('کالای پیش‌فروش', '8', '150')], ['inv_date' => $day(3)]);
$r = BizStock::adjustTo($b7, $H7, 9, 'شمارش');
T::ok($r['ok'], 'امروز ۹ تا در قفسه است', $r['message']);
$adj7 = array_values(array_filter(BizStock::ledger($b7, $H7)['rows'], fn($m) => $m['kind'] === 'adjust'));
T::same([-1.0, 9.0], [(float)($adj7[0]['qty'] ?? 0), (float)($adj7[0]['balance'] ?? 0)], '⛔ حرکتِ کسریِ −۱ (نه اضافه‌ی +۷ به خاطرِ پیش‌فروشِ هفته‌ی بعد)');
T::same(1.0, $stock($b7, $H7), 'و موجودیِ پایانی ۱');

// ⛔ کاردکس و فهرستِ حرکت‌ها همان ترتیبِ `recalc()`: خریدِ «صدورِ دوباره»شده جلوی فروشِ همان روز
$k7 = $make('kdx');
$K7 = (int)BizProducts::save($k7, ['type' => 'goods', 'name' => 'کالای کاردکس', 'unit' => 'عدد', 'buy_price' => '100', 'sell_price' => '150', 'opening_qty' => '3'])['id'];
$kp = $doc($k7, 'purchase', [$L('کالای کاردکس', '5', '100')]);
$doc($k7, 'sale', [$L('کالای کاردکس', '3', '150')]);
BizInvoices::unissue($k7, $kp);
$r = BizInvoices::issue($k7, $kp, ['full' => true, 'account_id' => $acc($k7)]);
T::ok($r['ok'], 'خرید برای اصلاح دوباره صادر شد', $r['message']);
$led7 = BizStock::ledger($k7, $K7)['rows'];
T::same([['opening', 3.0], ['purchase', 8.0], ['sale', 5.0]], array_map(fn($m) => [$m['kind'], (float)$m['balance']], $led7),
        '⛔ کاردکس: خرید پیش از فروشِ همان روز (همان زنجیره‌ی بها)');
T::same(['sale', 'purchase', 'opening'], array_column(BizStock::moves($k7, $K7), 'kind'), '⛔ صفحه‌ی کالا: همان ترتیب، وارونه');

// ⛔ فاکتورِ گذری: دریافتِ اضافه نه، و ابطالش همه‌ی پول‌هایش را باطل می‌کند
$w7 = $make('walk');
$W7 = (int)BizProducts::save($w7, ['type' => 'goods', 'name' => 'کالای گذری', 'unit' => 'عدد', 'buy_price' => '100', 'sell_price' => '1000', 'opening_qty' => '5'])['id'];
$wi = $doc($w7, 'sale', [$L('کالای گذری', '1', '1000')]);
$x = BizPay::create($w7, ['kind' => 'receipt', 'party_id' => 0, 'invoice_id' => $wi, 'amount' => '5000', 'account_id' => $acc($w7)]);
T::ok(!$x['ok'], '⛔ دریافتِ اضافه روی فاکتورِ گذریِ تسویه‌شده رد می‌شود', $x['message']);
// داده‌ی قدیمی (پیش از این سقف): پولِ جدا روی همان فاکتور
$pdo->prepare("INSERT INTO biz_payments (user_id, kind, number, account_id, invoice_id, amount, pay_date, method)
               VALUES (:u, 'receipt', 99, :a, :i, 5000, CURDATE(), 'cash')")->execute(['u' => $w7, 'a' => $acc($w7), 'i' => $wi]);
$r = BizInvoices::void($w7, $wi);
T::ok($r['ok'], 'فاکتورِ گذری باطل شد', $r['message']);
$st7 = $pdo->prepare("SELECT COUNT(*) FROM biz_payments WHERE user_id = :u AND invoice_id = :i AND status = 'ok'");
$st7->execute(['u' => $w7, 'i' => $wi]);
T::same(0, (int)$st7->fetchColumn(), '⛔ همه‌ی پول‌های فاکتورِ گذری باطل شدند (پولِ جدا مالِ هیچ‌کس نمی‌ماند)');
T::same(0, (int)BizCash::list($w7)[0]['balance'], 'صندوق صفر');

// ⛔ وصولِ چک پیش از تاریخِ دریافتش رد می‌شود (همان سدِ واگذاری)
$c7 = $make('chq');
$CC7 = (int)BizParties::save($c7, ['name' => 'مشتریِ چک', 'kind' => 'customer'])['id'];
$ch7 = BizPay::create($c7, ['kind' => 'receipt', 'party_id' => $CC7, 'amount' => '1000', 'account_id' => $acc($c7), 'method' => 'cheque',
                            'cheque_due' => $day(30), 'pay_date' => date('Y-m-d')]);
T::ok($ch7['ok'], 'چکِ دریافتی', $ch7['message']);
$r = BizCheques::clear($c7, (int)$ch7['id'], $acc($c7), $day(-60));
T::ok(!$r['ok'] && str_contains($r['message'], 'پیش از'), '⛔ وصول با تاریخِ پیش از دریافتِ چک رد می‌شود', $r['message']);
T::same(0, (int)BizCash::list($c7)[0]['balance'], 'و پولی به صندوق نرسید');
T::ok(BizCheques::clear($c7, (int)$ch7['id'], $acc($c7), date('Y-m-d'))['ok'], 'وصولِ هم‌روز آزاد است');

// ⛔ تخفیفِ فاکتور هیچ ردیفی را منفی نمی‌کند (باقیمانده‌ی گرد کردن)
$tt = BizInvoices::totals([['line_total' => 2], ['line_total' => 2], ['line_total' => 2], ['line_total' => 1]], 5, 0, 0.0);
$nets = array_column($tt['lines'], 'net_total');
T::ok(min($nets) >= 0, '⛔ هیچ ردیفی زیرِ صفر نمی‌رود', json_encode($nets));
T::same($tt['net'], array_sum($nets), 'جمعِ ردیف‌ها = جمعِ فاکتور');
$tt = BizInvoices::totals([['line_total' => 300], ['line_total' => 700]], 101, 0, 0.0);
T::same([270, 629], array_column($tt['lines'], 'net_total'), 'تخفیفِ معمولی: سهم به نسبت، باقیمانده به ردیفِ آخر (مثلِ قبل)');

// ⛔ BizOnce: خطای پیش‌بینی‌نشده بعد از `claim()` نشان را «در کار» نمی‌گذارد
$onceScript = tempnam(sys_get_temp_dir(), 'bonce');
file_put_contents($onceScript, '<?php
require ' . var_export(dirname(__DIR__) . '/includes/db.php', true) . ';
require ' . var_export(dirname(__DIR__) . '/includes/functions.php', true) . ';
require ' . var_export(dirname(__DIR__) . '/includes/biz.php', true) . ';
$_SESSION = [];
preg_match(\'/value="([0-9a-f]+)"/\', BizOnce::field(), $m);
$_POST["_once"] = $m[1];
BizOnce::claim();
register_shutdown_function(function () use ($m) { echo "STATE=" . ($_SESSION["biz_once"][$m[1]]["s"] ?? "?"); });
throw new RuntimeException("boom");
');
$onceOut = (string)shell_exec(PHP_BINARY . ' ' . escapeshellarg($onceScript) . ' 2>/dev/null');
@unlink($onceScript);
T::ok(str_contains($onceOut, 'STATE=new'), '⛔ ۵۰۰ بعد از claim: نشان آزاد شد (ارسالِ دوباره «یک بار ثبت شده بود» نمی‌گوید)', $onceOut);

// سرگذشتِ ابطالِ پیش‌نویسِ شماره‌دار (داخلِ همان تراکنش)
$d7 = $doc($k7, 'sale', [$L('کالای کاردکس', '1', '150')]);
BizInvoices::unissue($k7, $d7);
$r = BizInvoices::deleteDraft($k7, $d7);
T::ok($r['ok'] && in_array('void', array_column(BizLog::forDoc($k7, 'invoice', $d7), 'action'), true) || !Biz::accReady(),
      'پیش‌نویسِ شماره‌دار باطل شد و سرگذشتش ردِ «ابطال» دارد', $r['message']);

// =================================================================
T::group('۶ — دو بار زدنِ «ثبت»: یک سند (HTTP)');
$root = dirname(__DIR__);
$port = 0;
for ($pp = 9411; $pp <= 9460; $pp++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$pp", $e1, $e2);
    if ($sock) { fclose($sock); $port = $pp; break; }
}
$log = tempnam(sys_get_temp_dir(), 'bhard');
$srv = $port ? (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
    $port, escapeshellarg($root), escapeshellarg($log)))) : 0;
$up = false;
for ($i = 0; $srv && $i < 40; $i++) {
    usleep(150000);
    $sk = @fsockopen('127.0.0.1', $port, $x, $y, 0.3);
    if ($sk) { fclose($sk); $up = true; break; }
}
if (!$up) {
    T::blocked('دو بار زدن (HTTP)', 'سرورِ آزمایشی بالا نیامد');
} else {
    $jar = tempnam(sys_get_temp_dir(), 'bhardjar');
    $req = function (string $path, ?array $post = null) use ($port, $jar): array {
        $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 40]);
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $raw = (string)curl_exec($ch);
        $hlen = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        $loc = preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $hlen), $m) ? $m[1] : '';
        return [$code, substr($raw, $hlen), $loc];
    };
    $field = fn(string $html, string $name): string
        => preg_match('/name="' . preg_quote($name, '/') . '"[^>]*value="([^"]+)"/', $html, $m) ? $m[1] : '';
    $sales = fn(int $u): int => (int)$pdo->query("SELECT COUNT(*) FROM biz_invoices WHERE user_id = {$u} AND kind = 'sale' AND status = 'issued'")->fetchColumn();

    $w = $make('web');
    BizProducts::save($w, ['type' => 'goods', 'name' => 'شارژر', 'unit' => 'عدد', 'buy_price' => '100', 'sell_price' => '200', 'opening_qty' => '10']);
    [, $lp] = $req('store/login.php');
    [$c] = $req('store/login.php', ['csrf_token' => $field($lp, 'csrf_token'), 'username' => HPREFIX . 'web', 'password' => HPASS]);
    T::ok($c === 302, 'ورود به فروشگاه', (string)$c);

    [$c, $qs] = $req('store/quick-sale.php');
    $once = $field($qs, '_once');
    T::ok($c === 200 && $once !== '', 'فرمِ فروشِ سریع نشانِ یک‌بارمصرف دارد');
    $post = ['csrf_token' => $field($qs, 'csrf_token'), '_once' => $once, 'action' => 'sale', 'pay_mode' => 'full',
             'account_id' => (string)$acc($w), 'method' => 'cash',
             'lines' => [['item' => 'شارژر', 'qty' => '1', 'price' => '200']]];
    $before = $sales($w);
    [$c1] = $req('store/quick-sale.php', $post);
    [$c2, , $loc2] = $req('store/quick-sale.php', $post);
    T::ok($c1 === 302 && $c2 === 302, 'هر دو ارسال پاسخ گرفتند', "{$c1} {$c2}");
    T::same($before + 1, $sales($w), '⛔ همان فرم دو بار فرستاده شد: یک فروش، نه دو');
    T::ok(str_contains($loc2, 'invoice.php?id='), 'ارسالِ دوم به همان فاکتورِ اول می‌رود', $loc2);

    // فرمِ تازه (نشانِ تازه) فروشِ دوم را می‌سازد — سد فقط تکرار را می‌گیرد
    [, $qs2] = $req('store/quick-sale.php');
    $post['_once'] = $field($qs2, '_once');
    $post['csrf_token'] = $field($qs2, 'csrf_token');
    $req('store/quick-sale.php', $post);
    T::same($before + 2, $sales($w), 'فرمِ تازه فروشِ تازه می‌سازد');

    // شکست نشان را آزاد می‌کند: اصلاح و ارسالِ دوباره پذیرفته است
    [, $qs3] = $req('store/quick-sale.php');
    $bad = $post; $bad['_once'] = $field($qs3, '_once'); $bad['csrf_token'] = $field($qs3, 'csrf_token');
    $bad['lines'] = [['item' => 'شارژر', 'qty' => '999', 'price' => '200']];
    [$c] = $req('store/quick-sale.php', $bad);
    T::same($before + 2, $sales($w), 'فروشِ بیش از موجودی رد شد');
    $bad['lines'] = [['item' => 'شارژر', 'qty' => '1', 'price' => '200']];
    $req('store/quick-sale.php', $bad);
    T::same($before + 3, $sales($w), '⛔ پس از شکست، همان فرمِ اصلاح‌شده ثبت می‌شود (نشان آزاد شد)');

    // ⛔ IMEIِ با رقمِ کنترلِ غلط: فروشِ سریع ثبت می‌شود، پیام «هشدار» است (نمی‌پرد) و صفحه‌ی فاکتور نشانش را دارد
    BizProducts::save($w, ['type' => 'phone', 'name' => 'گوشی وب', 'unit' => 'دستگاه', 'buy_price' => '100', 'sell_price' => '150']);
    $bp = BizInvoices::saveDraft($w, 'purchase', ['lines' => [$L('گوشی وب', '1', '100', '490154203237519')]]);
    BizInvoices::issue($w, (int)$bp['id'], ['full' => true, 'account_id' => $acc($w)]);
    [, $qs4] = $req('store/quick-sale.php');
    $lp4 = ['csrf_token' => $field($qs4, 'csrf_token'), '_once' => $field($qs4, '_once'), 'action' => 'sale', 'pay_mode' => 'full',
            'account_id' => (string)$acc($w), 'method' => 'cash',
            'lines' => [['item' => 'گوشی وب', 'qty' => '1', 'price' => '150', 'imei1' => '490154203237519']]];
    $n4 = $sales($w);
    [$c] = $req('store/quick-sale.php', $lp4);
    [, $after4] = $req('store/quick-sale.php');
    T::ok($sales($w) === $n4 + 1 && str_contains($after4, 'st-flash st-flash-warn') && str_contains($after4, 'رقمِ کنترلِ درستی ندارد')
          && !preg_match('/st-flash-warn"[^>]*data-toast/', $after4), '⛔ فروشِ سریع ثبت شد و هشدار ماندگار (نه پیامِ پرنده) نشان داده شد');
    $lastSale = (int)$pdo->query("SELECT MAX(id) FROM biz_invoices WHERE user_id = {$w} AND kind = 'sale'")->fetchColumn();
    [$c, $ip] = $req('store/invoice.php?id=' . $lastSale);
    T::ok(str_contains($ip, 'st-imei-warn'), 'صفحه‌ی فاکتور کنارِ IMEI نشانِ هشدار دارد');
    [$c, $pp] = $req('store/print.php?doc=invoice&id=' . $lastSale);
    T::ok($c === 200 && !str_contains($pp, 'st-imei-warn') && !str_contains($pp, 'رقمِ کنترل'), '⛔ برگه‌ی چاپ (دستِ مشتری) هشدار ندارد');
    [, $ie] = $req('store/invoice-edit.php?k=sale', ['csrf_token' => $field($qs4, 'csrf_token'), 'action' => 'addrows', 'party_id' => '0',
        'inv_date' => '', 'lines' => [['item' => 'گوشی وب', 'qty' => '1', 'price' => '150', 'imei1' => '490154203237519']]]);
    T::ok(str_contains($ie, 'data-imei-luhn'), 'ویرایشگرِ فاکتور پیش از صدور هم هشدار می‌دهد');

        // صفحه‌ی «رمز و بکاپ» برای حسابِ فروشگاه
    [$c, $ac] = $req('store/account.php');
    T::ok($c === 200 && str_contains($ac, '</html>') && str_contains($ac, 'name="new_password"'), 'صفحه‌ی رمز و بکاپ باز می‌شود');
    [$c, , $loc] = $req('store/account.php', ['csrf_token' => $field($ac, 'csrf_token'), 'action' => 'password',
        'current_password' => HPASS, 'new_password' => 'Hard12345New', 'new_password_confirm' => 'Hard12345New']);
    $hash = (string)$pdo->query("SELECT password_hash FROM users WHERE username = '" . HPREFIX . "web'")->fetchColumn();
    T::ok($c === 302 && password_verify('Hard12345New', $hash), '⛔ حسابِ فروشگاه رمزش را خودش عوض می‌کند', "{$c} {$loc}");
    [$c, $ac2] = $req('store/account.php');
    T::ok($c === 200, 'و همین دستگاه وارد می‌ماند', (string)$c);
    $ch = curl_init("http://127.0.0.1:{$port}/store/account.php");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_POST => true, CURLOPT_TIMEOUT => 40,
        CURLOPT_POSTFIELDS => http_build_query(['csrf_token' => $field($ac2, 'csrf_token'), 'action' => 'export'])]);
    $body = (string)curl_exec($ch);
    $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    unset($ch);
    $js = json_decode($body, true);
    T::ok(str_contains($type, 'octet-stream') && is_array($js) && isset($js['tables']['biz_products']),
        '⛔ بکاپِ کامل (با دفترِ فروشگاه) دانلود می‌شود', substr($type . ' ' . $body, 0, 120));

    exec("kill $srv 2>/dev/null");
    @unlink($jar);
}
@unlink($log);

$wipe();
exit(T::report());
