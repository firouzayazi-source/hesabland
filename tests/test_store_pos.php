<?php
/**
 * ⛔ اتصال به کارت‌خوان (`BizPos`, migration_biz_pos).
 *
 * **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵):** مبلغ مستقیم به کارت‌خوان — برای هر دستگاهی (محصولِ فروشی).
 *
 *   ۱. آدرسِ سرویسِ محلی فقط همین رایانه/شبکه‌ی داخلی (مبلغِ فروش به سایتِ بیرونی نمی‌رود).
 *   ۲. تعریف: صندوقِ کارت‌خوان/بانک، مسیرهای پاسخ، تنظیمِ اندروید.
 *   ۳. start برای هر راه‌انداز؛ finish: پاسخِ خامِ سرویس فقط روی سرور خوانده می‌شود؛ دستی با شماره‌ی پیگیری.
 *   ۴. یک رسید دو بار نه: شماره‌ی پیگیریِ تکراری رد؛ claim فقط یک بار، با همان مبلغ و صندوق.
 *   ۵. HTTPِ واقعی: فرمِ دریافت با تراکنشِ موفق سند می‌سازد و پیوند می‌زند؛ دوباره نه؛ همکار تعریف نمی‌کند.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
T::group('کارت‌خوان');
if (!file_exists(__DIR__ . '/../config/config.php')) { T::blocked('کارت‌خوان', 'config/config.php وجود ندارد'); exit(T::report()); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/biz.php';
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_docs.php';
require_once __DIR__ . '/../includes/biz_staff.php';
require_once __DIR__ . '/../includes/biz_pos.php';
require_once __DIR__ . '/../includes/biz_docview.php';

T::group('۱ — آدرسِ سرویسِ محلی (بی‌دیتابیس)');
foreach (['http://127.0.0.1:8088/pay' => true, 'http://localhost:9000' => true, 'https://[::1]:7000/x' => true,
          'https://192.168.1.20:8443/pos' => true, 'http://192.168.1.20:8088/pos' => false, 'https://pos.example.com/pay' => false,
          'http://8.8.8.8/pay' => false, 'ftp://127.0.0.1/x' => false, 'http://user:pw@127.0.0.1/' => false, '127.0.0.1:8088' => false,
          'https://172.20.0.5/p' => true, 'https://172.40.0.5/p' => false] as $u => $ok) {
    T::same($ok, BizPos::bridgeUrlError($u) === null, ($ok ? 'پذیرفته: ' : '⛔ رد: ') . $u);
}
T::same('A1', BizPos::pathGet(['data' => ['rrn' => 'A1']], 'data.rrn'), 'مسیرِ نقطه‌دار');
T::same(null, BizPos::pathGet(['data' => 'x'], 'data.rrn'), 'مسیرِ ناموجود ⇒ null');

try { $pdo = Database::getConnection(); } catch (Throwable $e) { T::blocked('کارت‌خوان', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }
if (!BizPos::ready() || !Biz::staffReady()) { T::blocked('کارت‌خوان', 'migration_biz_pos اجرا نشده'); exit(T::report()); }

const PS_PREFIX = '__store_pos_';
const PS_PASS   = 'Pos#pass9';
$wipe = function () use ($pdo) {
    foreach ($pdo->query("SELECT id FROM users WHERE username LIKE '" . PS_PREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN) as $id) { deleteUserAccount((int)$id); }
};
$wipe();
register_shutdown_function($wipe);
$one = function (string $sql, array $p = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn(); };
$r = createUserAccount($pdo, 'صاحب', PS_PREFIX . 'own', PS_PREFIX . 'own@example.com', PS_PASS);
$u = (int)$r['id'];
Biz::setType($u, 'business');
$cash = (int)BizCash::list($u)[0]['id'];
$posAcc = (int)BizCash::save($u, ['name' => 'کارت‌خوانِ ملت', 'kind' => 'pos'])['id'];
$party = (int)BizParties::save($u, ['name' => 'مشتریِ آزمون', 'kind' => 'customer'])['id'];

T::group('۲ — تعریف');
T::ok(!BizPos::saveTerminal($u, ['name' => 'x', 'driver' => 'manual', 'account_id' => $cash])['ok'], '⛔ صندوقِ نقد کارت‌خوان نمی‌گیرد');
T::ok(!BizPos::saveTerminal($u, ['name' => 'x', 'driver' => 'bridge', 'account_id' => $posAcc, 'cfg' => ['url' => 'https://evil.example/collect', 'ok_path' => 's', 'rrn_path' => 'r']])['ok'], '⛔ سرویسِ بیرونی رد');
T::ok(!BizPos::saveTerminal($u, ['name' => 'x', 'driver' => 'bridge', 'account_id' => $posAcc, 'cfg' => ['url' => 'http://127.0.0.1:8088/pay']])['ok'], 'بی‌مسیرِ پاسخ رد');
T::ok(!BizPos::saveTerminal($u, ['name' => 'x', 'driver' => 'android', 'account_id' => $posAcc, 'cfg' => ['action' => 'a;b', 'package' => 'p', 'amount_extra' => 'amount']])['ok'], 'action با نویسه‌ی نامعتبر رد (بی‌تزریق در Intent)');
$M = (int)BizPos::saveTerminal($u, ['name' => 'دستی', 'driver' => 'manual', 'account_id' => $posAcc])['id'];
$B = (int)BizPos::saveTerminal($u, ['name' => 'سرویس', 'driver' => 'bridge', 'account_id' => $posAcc, 'cfg' => [
    'url' => 'http://127.0.0.1:8088/pay', 'method' => 'post', 'body' => '{"Amount": {amount_rial}, "Id": "{ref}"}',
    'ok_path' => 'result.code', 'ok_value' => '00', 'rrn_path' => 'result.rrn', 'card_path' => 'result.pan', 'msg_path' => 'result.msg']])['id'];
$A = (int)BizPos::saveTerminal($u, ['name' => 'اندروید', 'driver' => 'android', 'account_id' => $posAcc, 'cfg' => [
    'action' => 'ir.psp.PAY', 'package' => 'ir.psp.pos', 'amount_extra' => 'amount', 'unit' => 'toman']])['id'];
T::ok($M > 0 && $B > 0 && $A > 0, 'سه کارت‌خوان تعریف شد');
T::same('POST', BizPos::get($u, $B)['cfg']['method'], 'روش یکدست (بزرگ)');

T::group('۳ — start و finish');
$s = BizPos::start($u, $B, 150000);
T::ok($s['ok'] && $s['client']['url'] === 'http://127.0.0.1:8088/pay' && $s['client']['body'] === '{"Amount": 1500000, "Id": "HL' . $s['id'] . '"}',
    'سرویس: بدنه با مبلغِ ریالی و شناسه‌ی ما', json_encode($s['client'] ?? null));
$f = BizPos::finish($u, $s['id'], ['raw' => json_encode(['result' => ['code' => '00', 'rrn' => '123456789012', 'pan' => '603799******1234', 'msg' => 'OK']])]);
T::ok($f['ok'] && $f['rrn'] === '123456789012', '⛔ پاسخِ خامِ سرویس روی سرور خوانده شد ⇒ موفق', $f['message']);
T::same(['success', '603799******1234'], [(string)$one('SELECT status FROM biz_pos_txns WHERE id = :i', ['i' => $s['id']]), (string)$one('SELECT card FROM biz_pos_txns WHERE id = :i', ['i' => $s['id']])], 'وضعیت و کارتِ پوشیده');
T::ok(!BizPos::finish($u, $s['id'], ['raw' => '{}'])['ok'], 'تراکنشِ بسته دوباره بسته نمی‌شود');
$s2 = BizPos::start($u, $B, 1000);
$f2 = BizPos::finish($u, $s2['id'], ['raw' => json_encode(['result' => ['code' => '51', 'msg' => 'موجودی کافی نیست']], JSON_UNESCAPED_UNICODE)]);
T::ok(!$f2['ok'] && $f2['status'] === 'failed' && str_contains($f2['message'], 'موجودی کافی نیست'), 'کدِ ناموفق ⇒ رد با پیامِ دستگاه', $f2['message']);
$s3 = BizPos::start($u, $B, 1000);
T::ok(!BizPos::finish($u, $s3['id'], ['raw' => 'not json'])['ok'], 'پاسخِ خراب ⇒ ناموفق');
$s4 = BizPos::start($u, $B, 1000);
$f4 = BizPos::finish($u, $s4['id'], ['raw' => json_encode(['result' => ['code' => '00']])]);
T::ok(!$f4['ok'] && str_contains($f4['message'], 'پیگیری'), '⛔ «موفق» بی‌شماره‌ی پیگیری پذیرفته نمی‌شود', $f4['message']);
$sa = BizPos::start($u, $A, 20000);
T::same('intent:#Intent;action=ir.psp.PAY;package=ir.psp.pos;S.amount=20000;end', $sa['client']['intent'] ?? '', 'اندروید: Intent با مبلغِ تومانی');
$sm = BizPos::start($u, $M, 50000);
T::ok(!BizPos::finish($u, $sm['id'], ['ok' => true, 'rrn' => '12'])['ok'], 'دستی: شماره‌ی پیگیریِ کوتاه رد (تراکنش باز می‌ماند)');
T::ok(!BizPos::finish($u, $sm['id'], ['ok' => true, 'rrn' => '۱۲۳۴۵۶۷۸۹۰۱۲'])['ok'], '⛔ شماره‌ی پیگیریِ تکراری (حتی با ارقامِ فارسی) رد');
$fm = BizPos::finish($u, $sm['id'], ['ok' => true, 'rrn' => '۹۹۸۸۷۷۶۶۵۵', 'card' => '۱۲۳۴']);
T::ok($fm['ok'] && $fm['rrn'] === '9988776655', 'دستی: شماره‌ی پیگیری با ارقامِ فارسی', $fm['message']);
T::ok(!BizPos::start($u, $B, 0)['ok'], 'مبلغِ صفر رد');

T::group('۴ — claim و settle');
T::ok(!BizPos::claim($u, $sm['id'], 49999, $posAcc)['ok'], '⛔ مبلغِ دیگر رد');
T::ok(!BizPos::claim($u, $sm['id'], 50000, $cash)['ok'], '⛔ صندوقِ دیگر رد');
T::ok(!BizPos::claim($u, $s2['id'], 1000, $posAcc)['ok'], '⛔ تراکنشِ ناموفق رد');
T::ok(BizPos::claim($u, $sm['id'], 50000, $posAcc)['ok'], 'تراکنشِ موفق رزرو شد');
T::ok(!BizPos::claim($u, $sm['id'], 50000, $posAcc)['ok'], '⛔ رزروِ دوم رد');
BizPos::settle($u, $sm['id'], null);
T::same('success', (string)$one('SELECT status FROM biz_pos_txns WHERE id = :i', ['i' => $sm['id']]), 'دریافت ساخته نشد ⇒ رزرو برگشت');

T::group('۵ — HTTP: فرمِ دریافت و اندپوینت');
$root = realpath(__DIR__ . '/..');
$port = 18000 + random_int(0, 899);
$log  = tempnam(sys_get_temp_dir(), 'pslog');
$srv = (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!', $port, escapeshellarg($root), escapeshellarg($log))));
register_shutdown_function(static function () use ($srv, $log) { exec("kill {$srv} 2>/dev/null"); @unlink($log); });
for ($i = 0, $up = false; $i < 40 && !$up; $i++) { usleep(150000); $sk = @fsockopen('127.0.0.1', $port, $a, $b, 0.3); if ($sk) { fclose($sk); $up = true; } }
T::ok($up, 'سرورِ آزمایشی بالا آمد');
if (!$up) { exit(T::report()); }
$client = function () use ($port) {
    $jar = tempnam(sys_get_temp_dir(), 'psjar');
    return function (string $path, ?array $post = null) use ($port, $jar): array {
        $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 40]);
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $body = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); unset($ch);
        return [$code, $body];
    };
};
$login = function (callable $req, string $user) {
    [, $lp] = $req('store/login.php');
    $tok = preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $lp, $m) ? $m[1] : '';
    $req('store/login.php', ['csrf_token' => $tok, 'username' => $user, 'password' => PS_PASS]);
};
$field = fn(string $html, string $name): string => preg_match('/name="' . preg_quote($name, '/') . '"[^>]*value="([^"]*)"/', $html, $m) ? $m[1] : '';
$o = $client(); $login($o, PS_PREFIX . 'own');
[$c, $h] = $o('store/payment.php?k=receipt');
T::ok($c === 200 && str_contains($h, 'data-pos-box') && str_contains($h, 'ارسالِ مبلغ به کارت‌خوان'), 'فرمِ دریافت بخشِ کارت‌خوان دارد');
$tok = $field($h, 'csrf_token');
[, $j] = $o('store/pos.php', ['csrf_token' => $tok, 'action' => 'start', 'terminal' => (string)$M, 'amount' => '۷۵٬۰۰۰']);
$js = json_decode($j, true);
T::ok(($js['ok'] ?? false) && ($js['amount'] ?? 0) === 75000, 'اندپوینتِ start (مبلغ با ارقامِ فارسی)', $j);
[, $j] = $o('store/pos.php', ['csrf_token' => $tok, 'action' => 'finish', 'id' => (string)$js['id'], 'ok' => '1', 'rrn' => '5544332211']);
T::ok((json_decode($j, true)['ok'] ?? false), 'اندپوینتِ finish', $j);
$post = fn(array $extra) => $extra + ['csrf_token' => $tok, 'once' => $field($h, 'once'), 'amount' => '75000', 'pay_date' => BizDocView::jDate(date('Y-m-d')),
                             'account_id' => (string)$posAcc, 'method' => 'card', 'title' => '', 'note' => '', 'party_id' => (string)$party];
$before = (int)$one("SELECT COUNT(*) FROM biz_payments WHERE user_id = :u AND kind = 'receipt'", ['u' => $u]);
[, $h2] = $o('store/payment.php?k=receipt', $post(['pos_txn' => (string)$js['id'], 'amount' => '70000']));
T::same($before, (int)$one("SELECT COUNT(*) FROM biz_payments WHERE user_id = :u AND kind = 'receipt'", ['u' => $u]), '⛔ مبلغِ متفاوت با کارت‌خوان ⇒ دریافتی ساخته نشد');
T::same('success', (string)$one('SELECT status FROM biz_pos_txns WHERE id = :i', ['i' => $js['id']]), 'و تراکنش آزاد ماند');
[, $h] = $o('store/payment.php?k=receipt');
[, $hx] = $o('store/payment.php?k=receipt', $post(['pos_txn' => (string)$js['id']]));
if (getenv('POS_DEBUG')) { echo preg_match('/st-flash-err[^>]*>([^<]+)/u', $hx, $mm) ? $mm[1] : substr(strip_tags($hx), 0, 300), "\n"; }
$pid = (int)$one('SELECT payment_id FROM biz_pos_txns WHERE id = :i', ['i' => $js['id']]);
T::ok($pid > 0 && (int)$one('SELECT amount FROM biz_payments WHERE id = :p AND user_id = :u', ['p' => $pid, 'u' => $u]) === 75000
    && (int)$one('SELECT account_id FROM biz_payments WHERE id = :p', ['p' => $pid]) === $posAcc, '⛔ دریافت ساخته و به تراکنش پیوند خورد (همان مبلغ و صندوق)');
$n = (int)$one("SELECT COUNT(*) FROM biz_payments WHERE user_id = :u AND kind = 'receipt'", ['u' => $u]);
[, $h] = $o('store/payment.php?k=receipt');
$o('store/payment.php?k=receipt', $post(['pos_txn' => (string)$js['id']]));
T::same($n, (int)$one("SELECT COUNT(*) FROM biz_payments WHERE user_id = :u AND kind = 'receipt'", ['u' => $u]), '⛔ یک رسیدِ کارت‌خوان دو دریافت نمی‌سازد');
[$c] = $o('store/pos.php');
T::same(200, $c, 'صاحب صفحه‌ی کارت‌خوان‌ها را دارد');

$sel = BizStaff::add($u, ['full_name' => 'فروشنده', 'username' => PS_PREFIX . 'sel', 'password' => PS_PASS, 'role' => 'seller']);
T::ok($sel['ok'], 'فروشنده', $sel['message']);
$s = $client(); $login($s, PS_PREFIX . 'sel');
[$c] = $s('store/pos.php');
T::same(403, $c, '⛔ فروشنده کارت‌خوان تعریف نمی‌کند (پول)');
[, $hs] = $s('store/payment.php?k=receipt');
[, $j] = $s('store/pos.php', ['csrf_token' => $field($hs, 'csrf_token'), 'action' => 'start', 'terminal' => (string)$M, 'amount' => '1000']);
T::ok((json_decode($j, true)['ok'] ?? false), 'ولی با «دریافت» از کارت‌خوان استفاده می‌کند', $j);
[$c, $j] = $o('store/pos.php', ['csrf_token' => 'bad', 'action' => 'start', 'terminal' => (string)$M, 'amount' => '1000']);
T::ok($c >= 400, '⛔ بی‌CSRF رد', (string)$c);

$pj = (string)file_get_contents($root . '/assets/js/store-pos.js');
T::ok(str_contains($pj, "getElementById('stSpecRules')") && preg_match('/\.then\(function \(r\) \{ resetSpec\(\);/', $pj) === 1,
    '⛔ هر درخواستِ نویسنده‌ی کارت‌خوان پیش‌گرفته‌ها را از نو می‌چیند (همان resetSpeculation)');
T::ok(!str_contains((string)file_get_contents($root . '/includes/biz_head.php'), 'store-pos.js'), 'اسکریپتِ کارت‌خوان فقط در فرمِ دریافت بار می‌شود، نه در هر صفحه');
exit(T::report());
