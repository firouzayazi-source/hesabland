<?php
/**
 * تستِ «ارتباط با مشتری» — پیامک (`BizComms`)، لینکِ امضاشده‌ی فاکتور (`BizShare`،
 * `store/view.php`) و امتیازِ وفاداری (`BizLoyalty`).
 *
 * ⛔ پیامک سه در دارد که هر سه پیش‌فرض بسته‌اند (مدیرِ نصب، فروشگاه، سقفِ ماهانه)، متنش از
 *    سند است، دو بار پشتِ هم نمی‌رود و متنش ذخیره نمی‌شود؛ لینکِ فاکتور فقط با امضای درست و
 *    فقط برای فروشِ صادرشده باز است و با «باطل کردن» همه می‌میرند؛ امتیاز مشتق است، فقط با
 *    تخفیفِ هم‌ارز مصرف می‌شود و ابطالِ فاکتور پسش می‌دهد.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
T::group('ارتباط با مشتری: پیامک، لینکِ فاکتور، باشگاه');

if (!file_exists(__DIR__ . '/../config/config.php')) { T::blocked('ارتباط', 'config/config.php وجود ندارد'); exit(T::report()); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/biz.php';
require_once __DIR__ . '/../includes/biz_docs.php';
require_once __DIR__ . '/../includes/biz_repairs.php';
require_once __DIR__ . '/../includes/biz_sales_ext.php';
require_once __DIR__ . '/../includes/biz_docview.php';

try { $pdo = Database::getConnection(); } catch (Throwable $e) { T::blocked('ارتباط', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }
if (!BizComms::ready()) { T::blocked('ارتباط', 'migration_biz_comms اجرا نشده'); exit(T::report()); }

const CM_PREFIX = '__store_comms_';
const CM_PASS   = 'Comms#pass9';
$keepAdmin = getSetting(BizComms::ADMIN_KEY, '0');
$keepMethod = getSetting('sms_method', '');
$wipe = function () use ($pdo, $keepAdmin, $keepMethod) {
    foreach ($pdo->query("SELECT id FROM users WHERE username LIKE '" . CM_PREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN) as $id) { deleteUserAccount((int)$id); }
    setSetting(BizComms::ADMIN_KEY, $keepAdmin);
    setSetting('sms_method', $keepMethod);
};
$wipe();
register_shutdown_function($wipe);
$one = function (string $sql, array $p = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn(); };
$mk = function (string $n) use ($pdo): int {
    $id = (int)createUserAccount($pdo, 'فروشگاه ' . $n, CM_PREFIX . $n, CM_PREFIX . $n . '@example.com', CM_PASS)['id'];
    Biz::setType($id, 'business');
    return $id;
};
$u = $mk('own'); $o = $mk('oth');
Biz::saveSettings($u, ['shop_name' => 'موبایلِ آزمون', 'phone' => '', 'address' => '', 'invoice_footer' => '']);
$cash = (int)BizCash::list($u)[0]['id'];
$cus  = (int)BizParties::save($u, ['name' => 'مشتریِ پیامکی', 'kind' => 'customer', 'phone' => '۰۹۱۲ ۳۴۵ ۶۷۸۹'])['id'];
$mute = (int)BizParties::save($u, ['name' => 'بی‌شماره', 'kind' => 'customer'])['id'];
$sup  = (int)BizParties::save($u, ['name' => 'پخش', 'kind' => 'supplier'])['id'];
BizProducts::save($u, ['type' => 'service', 'name' => 'خدمت', 'unit' => 'عدد', 'sell_price' => '1']);
$sale = function (int $party, int $total, array $extra = [], bool $issue = true) use ($u) {
    $d = BizInvoices::saveDraft($u, 'sale', ['party_id' => $party, 'inv_date' => '2026-02-01', 'lines' => [['item' => 'خدمت', 'qty' => '1', 'price' => (string)$total]]] + $extra);
    if (!$d['ok']) { return [0, $d]; }
    return [(int)$d['id'], $issue ? BizInvoices::issue($u, (int)$d['id'], ['amount' => '0']) : ['ok' => true]];
};
$logs = fn(): int => (int)$one('SELECT COUNT(*) FROM biz_sms_log WHERE user_id = :u', ['u' => $u]);

// ---- ۱. سه درِ پیامک ----
T::same([false, false], [BizComms::settings($u)['sms_on'], BizComms::settings($u)['loyalty_on']], 'پیش‌فرض: پیامک و باشگاه خاموش');
[$s1] = $sale($cus, 2500000);
setSetting(BizComms::ADMIN_KEY, '0'); setSetting('sms_method', 'log');
T::ok(!BizComms::send($u, 'invoice', $s1)['ok'], '⛔ کلیدِ مدیرِ نصب خاموش: نمی‌رود');
setSetting(BizComms::ADMIN_KEY, '1'); setSetting('sms_method', '');
if (!Sms::isConfigured()) {   // config.php روی این ماشین پنل ندارد؛ وگرنه این سد را نمی‌شود این‌جا دید
    T::ok(str_contains((string)BizComms::smsBlocked($u), 'پنلِ پیامک'), '⛔ پنلِ پیامکِ تنظیم‌نشده: بسته');
}
setSetting('sms_method', 'log');
T::ok(!BizComms::send($u, 'invoice', $s1)['ok'], '⛔ کلیدِ فروشگاه خاموش: نمی‌رود');
T::same(0, $logs(), 'و هیچ ردی نماند');
T::ok(BizComms::saveSettings($u, ['sms_on' => '1', 'loyalty_on' => '1', 'earn_per' => '۱٬۰۰۰٬۰۰۰', 'point_value' => '10,000'])['ok'], 'روشن کردنِ پیامک و باشگاه');
T::same(null, BizComms::smsBlocked($u), 'حالا آماده');
setSetting(BizComms::ADMIN_KEY, '0');
T::ok(str_contains((string)BizComms::smsBlocked($u), 'مدیرِ نصب'), '⛔ روشنیِ فروشگاه کلیدِ مدیرِ نصب را دور نمی‌زند');
setSetting(BizComms::ADMIN_KEY, '1');
T::ok(!BizComms::saveSettings($u, ['earn_per' => '500'])['ok'], 'مبلغِ امتیازِ کمتر از ۱۰۰۰ رد');

// ---- ۲. پیامکِ فاکتور ----
Sms::$sent = [];
$r = BizComms::send($u, 'invoice', $s1);
T::ok($r['ok'], 'پیامکِ فاکتور', $r['message']);
$msg = end(Sms::$sent);
T::same('09123456789', $msg['to'] ?? null, '⛔ شماره با `normalizePhone()`');
T::ok(str_contains($msg['text'] ?? '', 'موبایلِ آزمون') && str_contains($msg['text'] ?? '', formatMoney(2500000))
    && str_contains($msg['text'] ?? '', 'view.php?') && str_contains($msg['text'] ?? '', 't=' . BizShare::token($u, $s1)), 'متن از سند: نامِ فروشگاه، مبلغ، لینکِ امضاشده');
T::same(['09123456789', 1], [(string)$one('SELECT phone FROM biz_sms_log WHERE user_id = :u', ['u' => $u]), (int)$one('SELECT ok FROM biz_sms_log WHERE user_id = :u', ['u' => $u])], 'گزارش: شماره و نتیجه');
T::same(['id', 'user_id', 'kind', 'ref_id', 'party_id', 'phone', 'ok', 'created_by', 'created_at'],
    array_column($pdo->query('SHOW COLUMNS FROM biz_sms_log')->fetchAll(), 'Field'), '⛔ متنِ پیامک ذخیره نمی‌شود (ستونی برایش نیست)');
T::ok(!BizComms::send($u, 'invoice', $s1)['ok'], '⛔ دوباره پشتِ هم نمی‌رود');
T::same(1, count(Sms::$sent), 'فقط یک پیامک رفت');
[$d1] = $sale($cus, 100, [], false);
T::ok(!BizComms::send($u, 'invoice', $d1)['ok'], '⛔ پیش‌نویس فرستاده نمی‌شود');
[$m1] = $sale($mute, 100);
T::ok(!BizComms::send($u, 'invoice', $m1)['ok'], '⛔ مشتریِ بی‌شماره: نمی‌رود');
T::ok(!BizComms::send($o, 'invoice', $s1)['ok'], '⛔ فاکتورِ فروشگاهِ دیگر');
T::ok(!BizComms::send($u, 'nope', $s1)['ok'], 'نوعِ ناشناخته رد');

// ---- ۳. یادآوریِ مانده، تعمیر، قسط ----
$r = BizComms::send($u, 'debt', $cus);
T::ok($r['ok'] && str_contains(end(Sms::$sent)['text'], formatMoney(2500000)), 'یادآوریِ مانده با مبلغِ مانده', $r['message']);
$paid = (int)BizParties::save($u, ['name' => 'تسویه‌کرده', 'kind' => 'customer', 'phone' => '09125556677'])['id'];
T::ok(!BizComms::send($u, 'debt', $paid)['ok'], '⛔ طرف‌حسابِ بی‌بدهی (با شماره) رد');
$rp = (int)BizRepairs::create($u, ['device' => 'سامسونگ', 'problem' => 'صفحه', 'customer_name' => 'رضا', 'customer_phone' => '09121112233'])['id'];
T::ok(!BizComms::send($u, 'repair', $rp)['ok'], '⛔ قبضِ هنوز آماده‌نشده رد');
BizRepairs::setStatus($u, $rp, 'ready');
$r = BizComms::send($u, 'repair', $rp);
T::ok($r['ok'] && end(Sms::$sent)['to'] === '09121112233' && str_contains(end(Sms::$sent)['text'], 'آماده‌ی تحویل'), '«آماده‌ی تحویل» به شماره‌ی قبض', $r['message']);
$rp2 = (int)BizRepairs::create($u, ['device' => 'شیائومی', 'problem' => 'باتری', 'party_id' => $cus])['id'];
BizRepairs::setStatus($u, $rp2, 'ready');
T::ok(BizComms::send($u, 'repair', $rp2)['ok'] && end(Sms::$sent)['to'] === '09123456789', 'قبضِ بی‌شماره: به شماره‌ی مشتریِ ثبت‌شده');
BizInstallments::plan($u, $s1, 5, '2026-03-01');
BizPay::create($u, ['kind' => 'receipt', 'party_id' => $cus, 'invoice_id' => $s1, 'amount' => '500000', 'account_id' => $cash, 'pay_date' => '2026-02-20', 'method' => 'cash']);
$r = BizComms::send($u, 'installment', $s1);
T::ok($r['ok'] && str_contains(end(Sms::$sent)['text'], formatMoney(500000)) && str_contains(end(Sms::$sent)['text'], 'قسطِ ' . toPersianDigits('2')), '⛔ یادآوریِ قسطِ **بعدی** (اولی پرداخت شده)', $r['message']);

// ---- ۴. سقفِ ماهانه ----
$ins = $pdo->prepare("INSERT INTO biz_sms_log (user_id, kind, ref_id, phone, ok) VALUES (:u, 'debt', 0, '09120000000', 1)");
for ($i = BizComms::sentThisMonth($u); $i < BizComms::SMS_MONTHLY; $i++) { $ins->execute(['u' => $u]); }
T::ok(str_contains((string)BizComms::smsBlocked($u), 'سقف'), '⛔ سقفِ ماهانه پر: بسته');
$pdo->prepare("DELETE FROM biz_sms_log WHERE user_id = :u AND ref_id = 0")->execute(['u' => $u]);
T::same(null, BizComms::smsBlocked($u), 'دوباره باز');

// ---- ۵. لینکِ امضاشده ----
$tok = BizShare::token($u, $s1);
T::ok(BizShare::verify($u, $s1, $tok), 'امضای درست');
T::ok(!BizShare::verify($u, $s1, substr($tok, 0, -1) . (substr($tok, -1) === 'A' ? 'B' : 'A')), '⛔ امضای دست‌خورده');
T::ok(!BizShare::verify($u, $m1, $tok), '⛔ امضای فاکتورِ دیگر');
T::ok(!BizShare::verify($o, $s1, $tok), '⛔ فروشگاهِ دیگر');
// ⛔ حتی با نمکِ یکسان، امضا به شناسه‌ی فروشگاه بسته است (نه فقط به نمک)
$pdo->prepare('INSERT INTO biz_settings (user_id, comms) VALUES (:u, :c) ON DUPLICATE KEY UPDATE comms = VALUES(comms)')
    ->execute(['u' => $o, 'c' => json_encode(['share_salt' => BizComms::settings($u)['share_salt']])]);
$rc = new ReflectionProperty(BizComms::class, 'cache'); PHP_VERSION_ID < 80100 && $rc->setAccessible(true); $rc->setValue(null, []);
T::ok($tok !== BizShare::token($o, $s1) && !BizShare::verify($o, $s1, $tok), '⛔ امضا به فروشگاه بسته است، حتی با نمکِ یکسان');

// ---- ۶. باشگاهِ مشتری ----
[$s2] = $sale($cus, 1000000);
$sale($cus, 5000000, [], false);   // پیش‌نویس امتیاز نمی‌سازد
T::same(['earned' => 3, 'used' => 0, 'balance' => 3], BizLoyalty::balance($u, $cus), 'کسب‌شده = ⌊۳٬۵ میلیون ÷ ۱ میلیون⌋');
[$l1, $r] = $sale($cus, 100000, ['discount' => '20000', 'loyalty_points' => '2']);
T::ok($r['ok'], 'مصرفِ ۲ امتیاز با تخفیفِ ۲۰٬۰۰۰', $r['message'] ?? '');
T::same(2, (int)$one('SELECT loyalty_points FROM biz_invoices WHERE id = :i', ['i' => $l1]), 'امتیاز روی فاکتور');
T::same(1, BizLoyalty::balance($u, $cus)['balance'], 'یکی ماند');
[$l2, $r] = $sale($cus, 100000, ['discount' => '20000', 'loyalty_points' => '2']);
T::ok(!$r['ok'], '⛔ امتیازِ ناکافی رد', $r['message'] ?? '');
T::same('draft', (string)$one('SELECT status FROM biz_invoices WHERE id = :i', ['i' => $l2]), 'پیش‌نویس ماند');
[$l3, $r] = $sale($cus, 100000, ['discount' => '9999', 'loyalty_points' => '1']);
T::ok(!$r['ok'], '⛔ امتیاز بی‌تخفیفِ هم‌ارز رد');
[$l4] = $sale(0, 100000, ['discount' => '10000', 'loyalty_points' => '1'], false);
$r = BizInvoices::issue($u, $l4, ['account_id' => $cash, 'full' => true]);
T::ok(!$r['ok'] && str_contains($r['message'], 'مشتری‌دار'), '⛔ گذری امتیاز مصرف نمی‌کند (حتی با تسویه‌ی کامل)', $r['message']);
$ln = (int)$one('SELECT id FROM biz_invoice_lines WHERE invoice_id = :i', ['i' => $s2]);
BizInvoices::createReturn($u, $s2, [$ln => '1'], [], '2026-02-02');
T::same(['earned' => 2, 'used' => 2, 'balance' => 0], BizLoyalty::balance($u, $cus), '⛔ برگشت امتیازِ کسب‌شده را کم کرد');
BizInvoices::void($u, $l1);
T::same(2, BizLoyalty::balance($u, $cus)['balance'], '⛔ ابطالِ فاکتور امتیازِ مصرفی را پس داد');
BizComms::saveSettings($u, ['sms_on' => '1', 'loyalty_on' => '', 'earn_per' => '1000000', 'point_value' => '10000']);
[$l5, $r] = $sale($cus, 100000, ['discount' => '10000', 'loyalty_points' => '1']);
T::ok(!$r['ok'], '⛔ باشگاهِ خاموش: امتیاز مصرف نمی‌شود');
BizComms::saveSettings($u, ['sms_on' => '1', 'loyalty_on' => '1', 'earn_per' => '1000000', 'point_value' => '10000']);
T::same(0, BizLoyalty::balance($o, $cus)['earned'], '⛔ فروشگاهِ دیگر امتیازِ این مشتری را نمی‌بیند');

// ---- ۷. HTTP ----
$root = realpath(__DIR__ . '/..');
$port = 0;
for ($p = 9670; $p <= 9710; $p++) { $sk = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2); if ($sk) { fclose($sk); $port = $p; break; } }
if (!$port) { T::blocked('ارتباط (HTTP)', 'پورت آزاد پیدا نشد'); exit(T::report()); }
$log = tempnam(sys_get_temp_dir(), 'cmlog');
$srv = (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!', $port, escapeshellarg($root), escapeshellarg($log))));
register_shutdown_function(static function () use ($srv, $log) { exec("kill {$srv} 2>/dev/null"); @unlink($log); });
for ($i = 0, $up = false; $i < 40 && !$up; $i++) { usleep(150000); $sk = @fsockopen('127.0.0.1', $port, $a1, $b1, 0.3); if ($sk) { fclose($sk); $up = true; } }
T::ok($up, 'سرورِ آزمایشی بالا آمد');
if (!$up) { exit(T::report()); }
$anon = function (string $path) use ($port): array {
    $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_HEADER => true]);
    $raw = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); unset($ch);
    return [$code, $raw];
};
$view = fn(int $s, int $i, string $t) => $anon('store/view.php?' . http_build_query(['s' => $s, 'i' => $i, 't' => $t]));
[$c, $h] = $view($u, $s1, BizShare::token($u, $s1));
T::ok($c === 200 && str_contains($h, '</html>') && str_contains($h, 'موبایلِ آزمون'), 'مشتری بی‌ورود فاکتور را می‌بیند', (string)$c);
T::ok(!str_contains($h, 'invoice-design.php') && !str_contains($h, 'print-settings.php') && stripos($h, 'X-Robots-Tag: noindex') !== false, '⛔ بی‌لینک به داخلِ فروشگاه، noindex');
[$c] = $view($u, $s1, 'x' . BizShare::token($u, $s1));
T::same(404, $c, '⛔ امضای غلط ۴۰۴');
[$c] = $view($u, $d1, BizShare::token($u, $d1));
T::same(404, $c, '⛔ پیش‌نویس با امضای درست هم ۴۰۴');
[$pu] = [(int)BizInvoices::saveDraft($u, 'purchase', ['party_id' => $sup, 'lines' => [['item' => 'خدمت', 'qty' => '1', 'price' => '5']]])['id']];
BizInvoices::issue($u, $pu, ['amount' => '0']);
[$c] = $view($u, $pu, BizShare::token($u, $pu));
T::same(404, $c, '⛔ فاکتورِ خرید ۴۰۴');
$old = BizShare::token($u, $s1);
BizComms::saveSettings($u, BizComms::settings($u), true);
[$c] = $view($u, $s1, $old);
T::same(404, $c, '⛔ بعد از «باطل کردنِ همه‌ی لینک‌ها» لینکِ قبلی ۴۰۴');
$jar = tempnam(sys_get_temp_dir(), 'cmjar');
$req = function (string $path, ?array $post = null) use ($port, $jar): array {
    $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 40]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); unset($ch);
    return [$code, $body];
};
[, $lp] = $req('store/login.php');
$req('store/login.php', ['csrf_token' => preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $lp, $m) ? $m[1] : '', 'username' => CM_PREFIX . 'own', 'password' => CM_PASS]);
[$c, $h] = $req('store/invoice.php?id=' . $s1);
T::ok($c === 200 && str_contains($h, 'data-share-link') && str_contains($h, htmlspecialchars('t=' . BizShare::token($u, $s1))) && str_contains($h, 'value="sms_invoice"'), 'صفحه‌ی فاکتور: لینک و دکمه‌ی پیامک');
[$c, $h] = $req('store/party.php?id=' . $cus);
T::ok(str_contains($h, 'data-loyalty') && str_contains($h, 'value="sms_debt"'), 'صفحه‌ی مشتری: امتیاز و یادآوری');
[$c, $h] = $req('store/settings.php');
T::ok(str_contains($h, 'id="comms"') && str_contains($h, 'name="loyalty_on"'), 'تنظیمات: ارتباط با مشتری');
[$c, $h] = $req('store/invoice-edit.php?k=sale&party=' . $cus);
T::ok(str_contains($h, 'name="loyalty_points"') && str_contains($h, 'value="loyalty_apply"'), 'فرمِ فاکتور: امتیاز');
$adm = (string)file_get_contents(__DIR__ . '/../admin/access.php');
T::ok(str_contains($adm, "'" . BizComms::ADMIN_KEY . "'"), '⛔ کلیدِ پنلِ مدیر همان `BizComms::ADMIN_KEY` است');
$sl = (string)@file_get_contents($log);
T::ok(!preg_match('/PHP (Fatal|Warning|Notice|Deprecated)/', $sl), 'لاگِ سرور بی‌هشدار و بی‌خطا', mb_substr($sl, 0, 600));
@unlink($jar);

$wipe();
exit(T::report());
