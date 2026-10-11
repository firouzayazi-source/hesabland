<?php
/**
 * تستِ «ارزشِ امروزِ چک» — `chequePresentValue()` و «این چک را چطور گرفتیم؟».
 *
 * **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵):** «۷۰۰ میلیون چک دارم ولی با سودِ ۵ یا ۶ درصد مالِ
 * ۶ ماهِ دیگه است … ۱۰ روز مونده یه مبلغ، ۴ روز مونده یه مبلغ، روزِ پاس شدن خودِ مبلغ.
 * هر چک یا هر سری چک درصدِ خودش رو داره … ۱۰۰ میلیون، ۶٪، ۱۰ ماه: هر چک ۱۶ میلیون.»
 *
 * ⛔ می‌سنجد: حسابِ هر روش (و مرزهایش)، طرحِ اقساطِ وام، ثبتِ سری و وام با HTTPِ واقعی،
 *    خالص دارایی به ارزشِ امروز، ویرایشِ درصد، سدِ «وام» برای چکِ بی‌اصل، پیش‌نمایش،
 *    و اینکه مبلغِ ذخیره‌شده (`amount`) همیشه همان مبلغِ اسمی است.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
T::group('ارزشِ امروزِ چک');

require_once __DIR__ . '/../includes/functions.php';

// ---- ۱. حسابِ هر روش (بی‌دیتابیس) ----
$T0 = '2026-01-01';
$due = fn(int $days) => date('Y-m-d', strtotime("{$T0} +{$days} days"));
$pv  = fn(array $c) => chequePresentValue($c + ['amount' => 100000000], $T0);

T::same(100000000, $pv(['due_date' => $due(180)]), 'بی‌روش = همان مبلغ');
T::same(100000000, $pv(['due_date' => $due(180), 'value_method' => 'bogus', 'value_rate' => 5]), 'روشِ ناشناخته = همان مبلغ');
T::same(100000000, $pv(['due_date' => '', 'value_method' => 'simple', 'value_rate' => 5]), 'بی‌سررسید = همان مبلغ');
T::same(100000000, $pv(['due_date' => $T0, 'value_method' => 'simple', 'value_rate' => 5]), '⛔ روزِ پاس شدن = خودِ مبلغِ چک');
T::same(100000000, $pv(['due_date' => $due(-3), 'value_method' => 'compound', 'value_rate' => 5]), 'سررسیدِ گذشته = همان مبلغ');
T::same(70000000, $pv(['due_date' => $due(180), 'value_method' => 'simple', 'value_rate' => 5]), '⛔ ساده: ۶ ماه × ۵٪ = ۳۰٪ کم');
T::same(98333333, $pv(['due_date' => $due(10), 'value_method' => 'simple', 'value_rate' => 5]), 'ساده: ۱۰ روز مانده');
T::same(99333333, $pv(['due_date' => $due(4), 'value_method' => 'simple', 'value_rate' => 5]), 'ساده: ۴ روز مانده بیشتر از ۱۰ روز');
T::same(0, $pv(['due_date' => $due(900), 'value_method' => 'simple', 'value_rate' => 5]), 'ساده هرگز زیرِ صفر نمی‌رود');
T::same((int)round(100000000 / 1.06 ** 6), $pv(['due_date' => $due(180), 'value_method' => 'compound', 'value_rate' => 6]), 'مرکب: مبلغ ÷ ۱٫۰۶^۶');
T::same(100000000, chequePresentValue(['amount' => 160000000, 'due_date' => $due(300), 'value_method' => 'loan', 'value_rate' => 6,
    'value_base' => 100000000, 'value_from' => $T0], $T0), 'وام: روزِ دادنِ پول = اصل');
T::same(130000000, chequePresentValue(['amount' => 160000000, 'due_date' => $due(300), 'value_method' => 'loan', 'value_rate' => 6,
    'value_base' => 100000000, 'value_from' => $due(-300)], $T0), 'وام: نیمه‌ی دوره = اصل + نیمِ سود');
T::same(160000000, chequePresentValue(['amount' => 160000000, 'due_date' => $due(300), 'value_method' => 'loan', 'value_rate' => 6,
    'value_base' => 900000000, 'value_from' => $T0], $T0), 'وام: اصلِ بیشتر از مبلغ به مبلغ بریده می‌شود');

// ---- ۲. سنجه‌ی ورودی ----
T::same([null, null, null], chequeValueInput('', '', ''), 'بی‌سود بی‌درصد و بی‌سررسید پذیرفته است');
T::same(['simple', 5.5, null], chequeValueInput('simple', '۵٫۵', '2026-06-01'), 'درصد با رقمِ فارسی و «٫»');
T::ok(chequeValueInput('simple', '0', '2026-06-01')[2] !== null, 'درصدِ صفر رد');
T::ok(chequeValueInput('simple', '51', '2026-06-01')[2] !== null, 'درصدِ بیش از سقف رد');
T::ok(chequeValueInput('simple', '5', '')[2] !== null, 'روشِ دار‌سود بی‌سررسید رد');
T::ok(chequeValueInput('x', '5', '2026-06-01')[2] !== null, 'روشِ ناشناخته رد');

// ---- ۳. طرحِ اقساطِ وام: همان حسابِ مالکِ نصب ----
$plan = chequeLoanPlan(100000000, 6, 10, $T0, '2026-02-01');
T::same(10, count($plan), 'ده چک');
T::same([160000000], array_values(array_unique([array_sum(array_column($plan, 'amount'))])), '⛔ جمع = ۱۶۰ میلیون');
T::same([16000000], array_values(array_unique(array_column($plan, 'amount'))), '⛔ هر چک ۱۶ میلیون');
T::same(100000000, array_sum(array_column($plan, 'base')), 'جمعِ اصل‌ها همان ۱۰۰ میلیون');
$sum0 = 0;
foreach ($plan as $x) {
    $sum0 += chequePresentValue(['amount' => $x['amount'], 'due_date' => $x['due'], 'value_method' => 'loan', 'value_rate' => 6,
                                 'value_base' => $x['base'], 'value_from' => $x['from']], $T0);
}
T::same(100000000, $sum0, '⛔ ارزشِ امروزِ کلِ سری روزِ اول = اصلِ پول');
$odd = chequeLoanPlan(100000001, 6, 3, $T0, '2026-02-01');
T::same(100000001, array_sum(array_column($odd, 'base')), 'باقیمانده‌ی تقسیمِ اصل به قسطِ آخر');
T::ok($odd[2]['base'] > $odd[0]['base'], 'قسطِ آخر باقیمانده را می‌گیرد');
$dates = chequeSeriesDates('2026-03-21', 3);
T::same('2026-03-21', $dates[0], 'سری از همان سررسیدِ اول');
T::same(3, count(array_unique($dates)), 'سررسیدهای سری ماه‌به‌ماه، تکراری نه');

// ---- ۴. HTTP: ثبت، کارت، خالص دارایی، ویرایش، پیش‌نمایش ----
if (!file_exists(__DIR__ . '/../config/config.php')) { T::blocked('ارزشِ چک (HTTP)', 'config/config.php وجود ندارد'); exit(T::report()); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
try { $pdo = Database::getConnection(); } catch (Throwable $e) { T::blocked('ارزشِ چک (HTTP)', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }
if (!tableHasColumn('cheques', 'value_method')) { T::blocked('ارزشِ چک (HTTP)', 'migration_cheque_value اجرا نشده'); exit(T::report()); }

$root = dirname(__DIR__);
$U = ['__cheque_value_u', 'Cheque#value9'];
$purge = function () use ($pdo, $U) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $U[0]]);
    if ($id = $st->fetchColumn()) { deleteUserAccount((int)$id); }
};
$purge();
register_shutdown_function($purge);
$acc = createUserAccount($pdo, 'کاربرِ چک', $U[0], $U[0] . '@example.com', $U[1]);
$uid = (int)($acc['id'] ?? 0);
T::ok($uid > 0, 'کاربرِ آزمایشی ساخته شد');

$port = 0;
for ($p = 9360; $p <= 9400; $p++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
    if ($sock) { fclose($sock); $port = $p; break; }
}
if (!$port) { T::blocked('ارزشِ چک (HTTP)', 'پورت آزاد پیدا نشد'); exit(T::report()); }
$log = tempnam(sys_get_temp_dir(), 'chqval');
$srv = (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!', $port, escapeshellarg($root), escapeshellarg($log))));
register_shutdown_function(static function () use ($srv, $log) { exec("kill {$srv} 2>/dev/null"); @unlink($log); });
for ($i = 0, $up = false; $i < 40 && !$up; $i++) { usleep(150000); $sk = @fsockopen('127.0.0.1', $port, $a, $b, 0.3); if ($sk) { fclose($sk); $up = true; } }
T::ok($up, 'سرورِ آزمایشی بالا آمد');
if (!$up) { exit(T::report()); }

$jar = tempnam(sys_get_temp_dir(), 'chqjar');
$req = function (string $path, ?array $post = null) use ($port, $jar): array {
    $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 40]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, $body];
};
[, $lp] = $req('login.php');
$tok = preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $lp, $m) ? $m[1] : '';
$req('login.php', ['csrf_token' => $tok, 'username' => $U[0], 'password' => $U[1]]);
[$c, $html] = $req('cheques.php');
T::ok($c === 200 && str_contains($html, '</html>'), 'صفحه‌ی چک‌ها کامل رندر شد', "کد: {$c}");
$csrf = preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m) ? $m[1] : '';
T::ok(str_contains($html, 'این چک را چطور گرفتیم؟') && str_contains($html, 'value="loan"'), 'فرمِ ثبت می‌پرسد «چطور گرفتیم» (با اقساطِ وام)');

$today = today();
$api = function (string $file, array $post) use ($req, $csrf): array {
    [$code, $body] = $req('api/' . $file, $post + ['csrf_token' => $csrf]);
    return [$code, json_decode($body, true) ?: []];
};
$rows = function (string $who) use ($pdo, $uid): array {
    $st = $pdo->prepare('SELECT * FROM cheques WHERE user_id = :u AND counterparty_name = :n ORDER BY due_date, id');
    $st->execute(['u' => $uid, 'n' => $who]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
};
$firstDue = date('Y-m-d', strtotime($today . ' +30 days'));

// وام: ۱۰۰ میلیون، ۶٪، ۱۰ چک
[$c, $j] = $api('add_cheque.php', ['direction' => 'received', 'counterparty_name' => 'وام‌گیر', 'value_method' => 'loan',
    'value_rate' => '۶', 'series_count' => '۱۰', 'loan_principal' => '۱۰۰٬۰۰۰٬۰۰۰', 'loan_from' => $today, 'due_date' => $firstDue,
    'cheque_number' => '1000']);
T::ok(!empty($j['success']), 'ثبتِ اقساطِ وام پذیرفته شد', (string)($j['message'] ?? $c));
$loan = $rows('وام‌گیر');
T::same(10, count($loan), '⛔ ده ردیفِ واقعیِ چک ساخته شد');
T::same([16000000], array_values(array_unique(array_map('intval', array_column($loan, 'amount')))), '⛔ مبلغِ ذخیره‌شده‌ی هر چک ۱۶ میلیون (مبلغِ اسمی)');
T::same([(int)$loan[0]['id']], array_values(array_unique(array_map('intval', array_column($loan, 'series_id')))), 'همه یک سری، به شناسه‌ی اولین چک');
T::same(range(1, 10), array_map('intval', array_column($loan, 'series_no')), 'جایگاهِ ۱ تا ۱۰');
T::same('1009', (string)$loan[9]['cheque_number'], 'شماره‌ی چکِ عددی در سری جلو می‌رود');
T::same(chequeSeriesDates($firstDue, 10), array_column($loan, 'due_date'), 'سررسیدها ماه‌به‌ماه');
T::same(100000000, array_sum(array_map(fn($r) => chequePresentValue($r), $loan)), '⛔ ارزشِ امروزِ سریِ وام = اصلِ پول');

// سری ساده: ۳ چک × ۵۰ میلیون، تنزیلِ ساده ۵٪
[, $j] = $api('add_cheque.php', ['direction' => 'received', 'counterparty_name' => 'تنزیل‌کار', 'value_method' => 'simple',
    'value_rate' => '5', 'series_count' => '3', 'amount' => '50,000,000', 'due_date' => $firstDue, 'sayadi_number' => '1234']);
T::ok(!empty($j['success']), 'ثبتِ سریِ تنزیلِ ساده پذیرفته شد', (string)($j['message'] ?? ''));
$simple = $rows('تنزیل‌کار');
T::same(3, count($simple), 'سه چک');
T::same([null], array_values(array_unique(array_column($simple, 'sayadi_number'))), 'صیادی (یکتای هر برگ) روی سری نمی‌نشیند');

// چکِ بی‌سود: همان مبلغ
[, $j] = $api('add_cheque.php', ['direction' => 'issued', 'counterparty_name' => 'فروشنده', 'amount' => '20000000', 'due_date' => $firstDue]);
T::ok(!empty($j['success']), 'چکِ پرداختیِ بی‌سود ثبت شد');
$plain = $rows('فروشنده');
T::same(null, $plain[0]['value_method'], 'بی‌سود = ستونِ NULL');

// سنجه‌ها در اندپوینت
[$c, $j] = $api('add_cheque.php', ['direction' => 'received', 'counterparty_name' => 'خطا', 'amount' => '1000', 'value_method' => 'simple', 'value_rate' => '80', 'due_date' => $firstDue]);
T::ok($c === 422 && empty($j['success']), 'درصدِ ۸۰ رد شد', (string)$c);
[$c] = $api('add_cheque.php', ['direction' => 'received', 'counterparty_name' => 'خطا', 'amount' => '1000', 'series_count' => '3']);
T::same(422, $c, 'سری بی‌سررسید رد شد');
[$c] = $api('add_cheque.php', ['direction' => 'received', 'counterparty_name' => 'خطا', 'amount' => '1000', 'series_count' => '61', 'due_date' => $firstDue]);
T::same(422, $c, 'سریِ بیش از سقف رد شد');
T::same([], $rows('خطا'), 'هیچ ردیفی از ثبت‌های ردشده نماند');

// صفحه: ارزشِ امروز در کارت و جمع
$expectedReceived = array_sum(array_map(fn($r) => chequePresentValue($r), array_merge($loan, $simple)));
[, $html] = $req('cheques.php');
T::ok(str_contains($html, 'سری چک · ' . toPersianDigits('3') . ' از ' . toPersianDigits('10')), 'کارت: «سری چک · ۳ از ۱۰»');
T::ok(str_contains($html, 'تنزیلِ ساده ' . toPersianDigits('5') . '٪ ماهانه'), 'کارت روش و درصد را می‌گوید');
T::ok(substr_count($html, 'class="cheque-today-value"') === 13, '⛔ «امروز …» روی هر چکِ دارسود، نه روی بی‌سود', (string)substr_count($html, 'class="cheque-today-value"'));
T::ok(str_contains($html, 'ارزشِ امروز <span class="ltr-num">' . formatMoney($expectedReceived) . '</span>'), '⛔ جمعِ دریافتنی به ارزشِ امروز', formatMoney($expectedReceived));

// خالص دارایی
$nw = netWorthPortfolio($uid, false);
$chq = array_values(array_filter($nw['rows'] ?? $nw, fn($r) => is_array($r) && ($r['kind'] ?? '') === 'cheques'))[0] ?? null;
T::ok($chq !== null, 'خالص دارایی ردیفِ چک دارد');
T::same($expectedReceived - 20000000, (int)($chq['value'] ?? 0), '⛔ خالص دارایی چک را به ارزشِ امروز می‌شمارد');
T::same(160000000 + 150000000 - 20000000, (int)($chq['face'] ?? 0), 'مبلغِ اسمی کنارش می‌ماند');

// ویرایش: درصدِ چکِ ساده عوض شود؛ مبلغ همان
$edit = ['cheque_id' => $simple[0]['id'], 'counterparty_name' => 'تنزیل‌کار', 'amount' => '50000000', 'due_date' => $simple[0]['due_date'], 'value_present' => '1'];
[, $j] = $api('update_cheque.php', $edit + ['value_method' => 'compound', 'value_rate' => '۴']);
T::ok(!empty($j['success']), 'ویرایشِ روش و درصد پذیرفته شد', (string)($j['message'] ?? ''));
$after = $rows('تنزیل‌کار')[0];
T::same(['compound', '4.000', '50000000'], [$after['value_method'], (string)$after['value_rate'], (string)$after['amount']], 'روش و درصدِ همان یک چک عوض شد، مبلغ نه');
T::same('simple', $rows('تنزیل‌کار')[1]['value_method'], 'چکِ دیگرِ سری دست نخورد');
[$c] = $api('update_cheque.php', $edit + ['value_method' => 'loan', 'value_rate' => '6']);
T::same(422, $c, '⛔ چکِ بی‌اصل به «اقساطِ وام» نمی‌رود');
[, $j] = $api('update_cheque.php', ['cheque_id' => $loan[0]['id'], 'counterparty_name' => 'وام‌گیر', 'amount' => '5000000',
    'due_date' => $loan[0]['due_date'], 'value_present' => '1', 'value_method' => 'loan', 'value_rate' => '6']);
T::ok(!empty($j['success']), 'چکِ وام با مبلغِ کمتر ویرایش شد');
T::same('5000000', (string)$rows('وام‌گیر')[0]['value_base'], 'اصلِ قسط هرگز بیشتر از مبلغِ چک نمی‌ماند');
[, $j] = $api('update_cheque.php', $edit + ['value_method' => '', 'value_rate' => '']);
T::same(null, $rows('تنزیل‌کار')[0]['value_method'], 'برگشت به «بی‌سود»');
[, $j] = $api('update_cheque.php', ['cheque_id' => $simple[1]['id'], 'counterparty_name' => 'تنزیل‌کار', 'amount' => '50000000', 'due_date' => $simple[1]['due_date']]);
T::same('simple', $rows('تنزیل‌کار')[1]['value_method'], 'ویرایشِ بی‌`value_present` روش را پاک نمی‌کند');

// پیش‌نمایش: همان حسابِ سرور
$q = http_build_query(['value_method' => 'loan', 'value_rate' => '6', 'series_count' => '10', 'loan_principal' => '100000000',
    'loan_from' => $today, 'due_date' => $firstDue]);
[$c, $body] = $req('api/cheque_value_preview.php?' . $q);
$p = json_decode($body, true) ?: [];
T::same([true, 10, formatMoney(16000000), formatMoney(160000000), formatMoney(100000000)],
    [$p['success'] ?? null, $p['count'] ?? null, $p['each'] ?? null, $p['face'] ?? null, $p['value'] ?? null], '⛔ پیش‌نمایش: ۱۰ چک، هر کدام ۱۶ میلیون، ارزشِ امروز ۱۰۰');
[, $body] = $req('api/cheque_value_preview.php?' . http_build_query(['value_method' => 'simple', 'value_rate' => '99', 'amount' => '1000', 'due_date' => $firstDue]));
T::ok(empty((json_decode($body, true) ?: [])['success']), 'پیش‌نمایش درصدِ نامعتبر را رد می‌کند');
$n = (int)$pdo->query('SELECT COUNT(*) FROM cheques WHERE user_id = ' . $uid)->fetchColumn();
T::same(14, $n, 'پیش‌نمایش هیچ چکی ثبت نکرد');

exit(T::report());
