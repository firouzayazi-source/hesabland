<?php
/**
 * ⛔ اسکنِ بارکد و IMEI با دوربین (`assets/js/store-scan.js`).
 *
 *   ۱. منطقِ خالص در node (همان فایلِ واقعی): از بارکدهای یک جعبه فقط IMEIِ ۱۵ رقمیِ لون‌سالم، فاصله و
 *      خط‌تیره حذف، IMEI۲ همان IMEI۱ را برنمی‌دارد؛ خانه‌ی عمومی اولین بارکد.
 *   ۲. کرومیومِ واقعی با دوربینِ ساختگی: فروشِ سریع گوشیِ موجود را با همان مسیرِ Enter اضافه می‌کند؛
 *      فاکتور IMEIِ سالم را می‌گیرد و EAN را نه؛ ردیفِ تازه دکمه می‌گیرد؛ مرورگرِ بی‌`BarcodeDetector` دکمه نمی‌بیند.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/lib/store_http.php';
T::group('اسکن با دوربین');

$root = realpath(__DIR__ . '/..');
$src  = (string)file_get_contents($root . '/assets/js/store-scan.js');
T::ok(!preg_match('/fetch\s*\(|XMLHttpRequest|sendBeacon/', $src), '⛔ store-scan.js هیچ درخواستی به سرور نمی‌فرستد');
T::ok(!preg_match('/<script[^>]+src=["\']https?:/i', $src) && !str_contains($src, 'import('), '⛔ بی‌کتابخانه‌ی بیرونی');

$node = trim((string)shell_exec('command -v node 2>/dev/null'));
// IMEIِ لون‌سالم از ۱۴ رقم
$imei = function (string $b14): string {
    $s = 0;
    for ($i = 0; $i < 14; $i++) { $d = (int)$b14[13 - $i]; if ($i % 2 === 0) { $d *= 2; if ($d > 9) { $d -= 9; } } $s += $d; }
    return $b14 . (string)((10 - $s % 10) % 10);
};
$I1 = $imei('35391810000001');
$I2 = $imei('35391810000002');
$bad = substr($I1, 0, 14) . (string)(((int)substr($I1, 14)) + 1) % 10;

T::group('۱ — منطقِ خالص (node)');
if ($node === '') {
    T::skip('منطقِ store-scan.js', 'node نیست');
} else {
    $cases = [
        [['6260123456789', 'SN:R58N1234', $I1, $I2], 'imei', null],
        [['6260123456789', substr($I1, 0, 6) . '-' . substr($I1, 6, 6) . '-' . substr($I1, 12)], 'imei', null],
        [[$I1, $I2], 'imei', $I1],
        [[$bad], 'imei', null],
        [['6260123456789'], 'imei', null],
        [['', '  6260123456789 '], 'any', null],
        [['IMEI:' . $I2], 'imei', null],
        [['6260123456789', 'SN:R58N1234', $I1], 'any', null],
    ];
    $js = 'const m = require(' . json_encode($root . '/assets/js/store-scan.js') . '); const c = ' . json_encode($cases)
        . '; process.stdout.write(JSON.stringify(c.map(([v, mode, skip]) => m.pick(v, mode, skip))));';
    $out = json_decode((string)shell_exec(escapeshellarg($node) . ' -e ' . escapeshellarg($js) . ' 2>&1'), true);
    T::ok(is_array($out), 'store-scan.js در node بارگذاری شد');
    $out = is_array($out) ? $out : [];
    T::same($I1, $out[0] ?? 'x', 'جعبه با EAN، سریال و دو IMEI: اولین IMEIِ سالم');
    T::same($I1, $out[1] ?? 'x', 'IMEI با خط‌تیره: خط‌تیره حذف');
    T::same($I2, $out[2] ?? 'x', '⛔ خانه‌ی IMEI۲: همان IMEI۱ برداشته نمی‌شود');
    T::same(null, array_key_exists(3, $out) ? $out[3] : 'x', '⛔ IMEIِ با رقمِ کنترلِ غلط پذیرفته نمی‌شود');
    T::same(null, array_key_exists(4, $out) ? $out[4] : 'x', '⛔ EAN در خانه‌ی IMEI پذیرفته نمی‌شود');
    T::same('6260123456789', $out[5] ?? 'x', 'خانه‌ی عمومی: اولین بارکدِ ناخالی، بی‌فاصله');
    T::same($I2, $out[6] ?? 'x', 'پیشوندِ «IMEI:» حذف');
    T::same($I1, $out[7] ?? 'x', '⛔ خانه‌ی عمومی، جعبه‌ی گوشی: IMEI (دستگاهِ مشخص)، نه EANِ اولِ فهرست');
}

T::group('۲ — کرومیوم (دوربینِ ساختگی)');
if (!file_exists($root . '/config/config.php') || $node === '') { T::skip('کرومیوم', 'node یا config نیست'); exit(T::report()); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/biz.php';
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_docs.php';
try { $pdo = Database::getConnection(); } catch (Throwable $e) { T::blocked('اسکن', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }
const SC_PREFIX = '__store_scan_';
$wipe = function () use ($pdo) {
    foreach ($pdo->query("SELECT id FROM users WHERE username LIKE '" . SC_PREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN) as $id) { deleteUserAccount((int)$id); }
};
$wipe();
register_shutdown_function($wipe);
$r = createUserAccount($pdo, 'فروشگاهِ اسکن', SC_PREFIX . 'a', SC_PREFIX . 'a@example.com', 'Scan#pass9');
$u = (int)$r['id'];
Biz::setType($u, 'business');
$acc = (int)BizCash::list($u)[0]['id'];
BizProducts::save($u, ['type' => 'phone', 'name' => 'Galaxy A55 256GB', 'unit' => 'دستگاه', 'buy_price' => '100', 'sell_price' => '200']);
$d = BizInvoices::saveDraft($u, 'purchase', ['lines' => [['item' => 'Galaxy A55 256GB', 'qty' => '1', 'price' => '100', 'imei1' => $I1, 'imei2' => $I2]]]);
$iss = BizInvoices::issue($u, (int)$d['id'], ['full' => true, 'account_id' => $acc]);
T::ok(($iss['ok'] ?? false) === true, 'گوشیِ آزمایشی با IMEI در انبار', json_encode($iss, JSON_UNESCAPED_UNICODE));

$port = stHttpServer();
if (!$port) { T::skip('کرومیوم', 'سرورِ آزمایشی بالا نیامد'); exit(T::report()); }
$cfg = ['boxCodes' => ['6260123456789', 'SN:R58N1234', $I1, $I2]];
$out = (string)shell_exec(sprintf('%s %s %s %s %s %s 2>/dev/null', escapeshellarg($node), escapeshellarg(__DIR__ . '/scan_probe.js'),
    escapeshellarg("http://127.0.0.1:{$port}/"), escapeshellarg(SC_PREFIX . 'a'), escapeshellarg('Scan#pass9'), escapeshellarg(json_encode($cfg))));
$pr = json_decode(trim($out), true);
if (!is_array($pr) || empty($pr['ok'])) { T::skip('کرومیوم', 'در دسترس نیست: ' . ($pr['why'] ?? trim($out))); exit(T::report()); }
$res = $pr['res'];
T::ok(!empty($res['qsButton']), 'فروشِ سریع: دکمه‌ی دوربین کنارِ خانه‌ی اسکن');
T::same([$I1], $res['qs']['imeis'] ?? null, '⛔ فروشِ سریع: گوشیِ همان IMEI با مسیرِ Enter اضافه شد (EAN و سریال نادیده)');
T::ok(($res['qs']['overlayHidden'] ?? false) === true && ($res['qs']['gum'] ?? 0) === 1, 'بعد از خواندن، دوربین بسته شد (یک بار باز شد)');
T::same('', $res['qs']['scanValue'] ?? 'x', 'خانه‌ی اسکن مثلِ اسکنرِ دستی خالی شد');
T::ok(!empty($res['inv']['b1']), 'فاکتور: خانه‌ی IMEI۱ دکمه دارد');
T::same($I1, $res['inv']['v1'] ?? null, 'فاکتور: IMEI۱ از جعبه');
T::same($I2, $res['inv']['v2'] ?? null, '⛔ فاکتور: IMEI۲ = IMEIِ دوم، نه تکرارِ اولی');
T::same('', $res['inv']['eanOnly'] ?? 'x', '⛔ فقط EAN جلوی دوربین: خانه‌ی IMEI پر نمی‌شود');
T::ok(str_contains((string)($res['inv']['hint'] ?? ''), 'IMEI نیست'), 'و راهنما می‌گوید این بارکد IMEI نیست');
T::ok(!empty($res['inv']['closed']), 'دکمه‌ی «بستن» دوربین را می‌بندد');
T::ok(($res['added']['after'] ?? 0) > ($res['added']['before'] ?? 0), 'ردیفِ تازه‌ی فاکتور هم دکمه گرفت', json_encode($res['added'] ?? null));
T::same($I1, $res['added']['lastValue'] ?? null, '⛔ دکمه‌ی ردیفِ کپی‌شده واقعاً کار می‌کند (نه فقط دیده می‌شود)');
T::same(0, $res['noDetector'] ?? -1, '⛔ مرورگرِ بی‌BarcodeDetector: هیچ دکمه‌ای (ورودِ دستی سرِ جایش)');

exit(T::report());
