<?php
/**
 * تستِ «سندِ جفت» و دوره‌ی بسته — چهار پیشنهادِ بازرسیِ دوم که اجرا شد (مهر ۱۴۰۵).
 * خواسته‌ی مالکِ نصب: «تمامِ مواردی که گفتی رو، اگه کارآمدن، انجام بده — بی‌باگ».
 *
 *   ۱. ⛔ حقوق و کسرِ مساعده‌اش با هم باطل می‌شوند (`pair_id`).
 *   ۲. ⛔ چکِ دوره‌ی بسته «برگشتی» می‌شود — با سندِ معکوسِ امروز، دوره‌ی بسته دست‌نخورده؛
 *      ابطالِ همان سند برگشتی را پس می‌گیرد.
 *   ۳. ⛔ مانده‌ی اول دوره‌ی طرف‌حساب/صندوقِ تازه ترازِ دوره‌ی بسته را عوض نمی‌کند.
 *   ۴. ⛔ فرمِ حساب علامتِ موجودیِ اولیه را صریح دارد (HTTPِ واقعی).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
T::group('سندِ جفت و دوره‌ی بسته');

if (!file_exists(__DIR__ . '/../config/config.php')) { T::blocked('سندِ جفت', 'config/config.php وجود ندارد'); exit(T::report()); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/biz.php';
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_docs.php';
require_once __DIR__ . '/../includes/biz_reports.php';
require_once __DIR__ . '/../includes/biz_acc.php';

try { $pdo = Database::getConnection(); } catch (Throwable $e) { T::blocked('سندِ جفت', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }
if (!tableExists('biz_invoices') || !Biz::accReady() || !BizCheques::ready() || !BizPay::pairReady()) {
    T::blocked('سندِ جفت', 'جدول‌های فروشگاه یا migration_biz_pair نیست — اول: bash deploy/migrate.sh --apply');
    exit(T::report());
}

const SP_PREFIX = '__store_pair_';
const SP_PASS   = 'Store#pair9';
$wipe = function () use ($pdo) {
    $ids = $pdo->query("SELECT id FROM users WHERE username LIKE '" . SP_PREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) { deleteUserAccount((int)$id); }
};
$wipe();
register_shutdown_function($wipe);
$make = function (string $name, bool $shop = true) use ($pdo): int {
    $res = createUserAccount($pdo, 'کاربرِ ' . $name, SP_PREFIX . $name, SP_PREFIX . $name . '@example.com', SP_PASS);
    if (!($res['ok'] ?? false)) { throw new RuntimeException('ساختِ کاربر: ' . ($res['error'] ?? '?')); }
    $id = (int)$res['id'];
    if ($shop) { Biz::setType($id, 'business'); }
    return $id;
};
$today = date('Y-m-d');
$ago   = fn(int $d): string => date('Y-m-d', strtotime($today . " -{$d} days"));
$one   = function (string $sql, array $p = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn(); };
$cashOf = function (int $u, int $acc): int { return (int)array_column(BizCash::list($u), 'balance', 'id')[$acc]; };
$balOf  = function (int $u, int $pid) use ($pdo): int {
    $st = $pdo->prepare('SELECT ' . BizParties::BALANCE_SQL . ' FROM biz_parties p WHERE p.id = :id AND p.user_id = :u');
    $st->execute(['id' => $pid, 'u' => $u]);
    return (int)$st->fetchColumn();
};
$trialAt = function (int $u, string $at): array {
    $t = BizLedger::trial($u, $at);
    return array_map(fn($r) => [$r['dr'], $r['cr']], $t['rows']);
};

// =================================================================
T::group('۱ — حقوق و کسرِ مساعده با هم باطل می‌شوند');
$u = $make('pay');
$A = (int)BizCash::save($u, ['name' => 'صندوقِ حقوق', 'kind' => 'cash', 'opening_balance' => '100000'])['id'];
$EMP = (int)BizParties::save($u, ['name' => 'کارمندِ آزمون', 'kind' => 'employee'])['id'];
T::ok(BizPayroll::advance($u, $EMP, '2000', $A, $today)['ok'], 'مساعده‌ی ۲٬۰۰۰');
$r = BizPayroll::paySalary($u, $EMP, '10000', '2000', $A, $today);
T::ok($r['ok'], 'حقوقِ ۱۰٬۰۰۰ با کسرِ ۲٬۰۰۰', $r['message']);
$exp = (int)$one("SELECT id FROM biz_payments WHERE user_id = :u AND kind = 'expense' ORDER BY id DESC LIMIT 1", ['u' => $u]);
$ded = (int)$one("SELECT id FROM biz_payments WHERE user_id = :u AND kind = 'receipt' ORDER BY id DESC LIMIT 1", ['u' => $u]);
T::same($exp, (int)$one('SELECT pair_id FROM biz_payments WHERE id = :i', ['i' => $ded]), '⛔ کسر به حقوقش اشاره می‌کند');
T::same(90000, $cashOf($u, $A), 'صندوق: ۱۰۰٬۰۰۰ − ۲٬۰۰۰ − ۱۰٬۰۰۰ + ۲٬۰۰۰');
$r = BizPay::void($u, $exp);
T::ok($r['ok'], 'ابطالِ حقوق', $r['message']);
T::same('void', (string)$one('SELECT status FROM biz_payments WHERE id = :i', ['i' => $ded]), '⛔ کسرِ مساعده هم همراهش باطل شد');
T::same(98000, $cashOf($u, $A), '⛔ صندوق ۹۸٬۰۰۰ (نه ۱۰۰٬۰۰۰)');
T::same(2000, $balOf($u, $EMP), '⛔ مساعده‌ی کارمند هنوز باز (نه «تسویه»)');
// از سمتِ دیگر: باطل کردنِ خودِ کسر، حقوق را هم می‌برد
BizPayroll::paySalary($u, $EMP, '10000', '2000', $A, $today);
$exp2 = (int)$one("SELECT id FROM biz_payments WHERE user_id = :u AND kind = 'expense' AND status = 'ok' ORDER BY id DESC LIMIT 1", ['u' => $u]);
$ded2 = (int)$one("SELECT id FROM biz_payments WHERE user_id = :u AND kind = 'receipt' AND status = 'ok' ORDER BY id DESC LIMIT 1", ['u' => $u]);
T::ok(BizPay::void($u, $ded2)['ok'], 'ابطالِ کسرِ مساعده');
T::same('void', (string)$one('SELECT status FROM biz_payments WHERE id = :i', ['i' => $exp2]), '⛔ حقوقش هم باطل شد');
T::same(98000, $cashOf($u, $A), 'صندوق دوباره ۹۸٬۰۰۰');
// حقوقِ بی‌کسر (بی‌جفت) مثلِ قبل تنها باطل می‌شود
BizPayroll::paySalary($u, $EMP, '5000', '0', $A, $today);
$exp3 = (int)$one("SELECT id FROM biz_payments WHERE user_id = :u AND kind = 'expense' AND status = 'ok' ORDER BY id DESC LIMIT 1", ['u' => $u]);
$r = BizPay::void($u, $exp3);
T::same('سند باطل شد.', $r['message'], 'حقوقِ بی‌کسر: فقط خودش');

// =================================================================
T::group('۲ — چکِ دوره‌ی بسته «برگشتی» می‌شود، دوره دست نمی‌خورد');
$c = $make('chq');
$B = (int)BizCash::save($c, ['name' => 'صندوق', 'kind' => 'cash'])['id'];
$CU = (int)BizParties::save($c, ['name' => 'مشتریِ چک', 'kind' => 'customer'])['id'];
$sv = BizInvoices::saveDraft($c, 'sale', ['party_id' => $CU, 'inv_date' => $ago(20), 'lines' => [['item' => 'خدمتِ آزمون', 'qty' => '1', 'price' => '1000']]]);
T::ok($sv['ok'] && BizInvoices::issue($c, (int)$sv['id'], ['amount' => '0', 'account_id' => $B])['ok'], 'فروشِ نسیه‌ی ۱٬۰۰۰ (۲۰ روز پیش)');
$ck = BizPay::create($c, ['kind' => 'receipt', 'party_id' => $CU, 'amount' => '1000', 'account_id' => $B, 'method' => 'cheque',
                          'pay_date' => $ago(20), 'cheque_due' => $ago(-10), 'cheque_no' => '5555']);
T::ok($ck['ok'], 'چکِ دریافتی (۲۰ روز پیش)', $ck['message']);
$CK = (int)$ck['id'];
T::same(1000, (int)BizInvoices::get($c, (int)$sv['id'])['paid'], 'فاکتور با چک تسویه شد');
T::ok(Biz::saveLock($c, $ago(10))['ok'], 'دوره تا ده روز پیش بسته شد');
$lockTrial = $trialAt($c, $ago(10));

$r = BizCheques::bounce($c, $CK);
T::ok($r['ok'], '⛔ چکِ دوره‌ی بسته برگشتی شد (پیش از این رد می‌شد)', $r['message']);
T::same(['ok', 'bounced'], [(string)$one('SELECT status FROM biz_payments WHERE id = :i', ['i' => $CK]),
                            (string)$one('SELECT cheque_status FROM biz_payments WHERE id = :i', ['i' => $CK])], 'دریافتِ دوره‌ی بسته دست‌نخورده، فقط «برگشتی»');
$rev = $pdo->prepare('SELECT * FROM biz_payments WHERE pair_id = :c AND user_id = :u');
$rev->execute(['c' => $CK, 'u' => $c]);
$R = $rev->fetch();
T::ok($R && $R['kind'] === 'payment' && $R['pay_date'] === $today && (int)$R['amount'] === 1000, '⛔ سندِ معکوس: پرداختِ ۱٬۰۰۰ با تاریخِ امروز', json_encode($R ?: null));
T::same(null, $R['cheque_status'] ?? null, 'سندِ معکوس خودش چکِ تازه نیست');
T::same($lockTrial, $trialAt($c, $ago(10)), '⛔ ترازِ روزِ قفل عیناً همان ماند');
T::same(1000, $balOf($c, $CU), 'مشتری دوباره ۱٬۰۰۰ بدهکار');
T::same(0, (int)BizInvoices::get($c, (int)$sv['id'])['paid'], '⛔ فاکتور دوباره باز (نه «پرداخت‌شده»)');
$chqAcc = (int)$one("SELECT id FROM biz_accounts WHERE user_id = :u AND kind = 'cheque_in'", ['u' => $c]);
T::same(0, $cashOf($c, $chqAcc), 'صندوقِ چک‌های دریافتی خالی شد');
T::ok(BizLedger::reconcile($c)['ok'], 'دفتر با مانده‌ها می‌خواند');
T::ok(in_array('cheque_bounce', array_column(BizLog::forDoc($c, 'payment', $CK), 'action'), true), 'سرگذشتِ چک «برگشتِ چک» دارد');

$r = BizPay::void($c, $CK);
T::ok(!$r['ok'], '⛔ خودِ چکِ برگشتی باطل نمی‌شود (اول سندِ معکوس)', $r['message']);
// حتی وقتی دوره باز شد: ابطالِ دریافت در کنارِ سندِ معکوسِ زنده یعنی بدهیِ دوبرابر
Biz::saveLock($c, '', true);
$r = BizPay::void($c, $CK);
T::ok(!$r['ok'] && str_contains($r['message'], 'برگشتی'), '⛔ با دوره‌ی باز هم چکِ برگشتیِ معکوس‌دار باطل نمی‌شود', $r['message']);
T::ok(Biz::saveLock($c, $ago(10))['ok'], 'دوره دوباره بسته شد');
$r = BizPay::void($c, (int)$R['id']);
T::ok($r['ok'], 'ابطالِ سندِ معکوس', $r['message']);
T::same('pending', (string)$one('SELECT cheque_status FROM biz_payments WHERE id = :i', ['i' => $CK]), '⛔ برگشتی پس گرفته شد: چک دوباره در جریان');
T::same(0, $balOf($c, $CU), 'مانده‌ی مشتری صفر');
T::same(1000, (int)BizInvoices::get($c, (int)$sv['id'])['paid'], 'فاکتور دوباره تسویه');
T::same($lockTrial, $trialAt($c, $ago(10)), 'ترازِ روزِ قفل هنوز همان');
T::ok(in_array('cheque_unbounce', array_column(BizLog::forDoc($c, 'payment', $CK), 'action'), true), 'سرگذشت: «پس گرفتنِ برگشتیِ چک»');

// چکِ دوره‌ی باز مثلِ قبل خودِ سند را باطل می‌کند
$ck2 = BizPay::create($c, ['kind' => 'receipt', 'party_id' => $CU, 'amount' => '300', 'account_id' => $B, 'method' => 'cheque',
                           'pay_date' => $today, 'cheque_due' => $ago(-5), 'cheque_no' => '6666']);
T::ok(BizCheques::bounce($c, (int)$ck2['id'])['ok'], 'چکِ دوره‌ی باز برگشتی شد');
T::same('void', (string)$one('SELECT status FROM biz_payments WHERE id = :i', ['i' => (int)$ck2['id']]), 'دوره‌ی باز: همان رفتارِ قبلی (ابطالِ دریافت)');
T::same(0, (int)$one('SELECT COUNT(*) FROM biz_payments WHERE pair_id = :c', ['c' => (int)$ck2['id']]), 'و سندِ معکوسی ساخته نشد');

// =================================================================
T::group('۳ — مانده‌ی اول دوره‌ی تازه ترازِ دوره‌ی بسته را عوض نمی‌کند');
$o = $make('open');
$O = (int)BizCash::save($o, ['name' => 'صندوقِ قدیمی', 'kind' => 'cash', 'opening_balance' => '1000'])['id'];
$pdo->prepare('UPDATE biz_accounts SET created_at = :d WHERE id = :i')->execute(['d' => $ago(40) . ' 10:00:00', 'i' => $O]);
T::ok(Biz::saveLock($o, $ago(10))['ok'], 'دوره بسته شد');
$before = $trialAt($o, $ago(10));
T::same([1000, 0], $before['1101'] ?? null, 'ترازِ روزِ قفل: صندوقِ قدیمی ۱٬۰۰۰');
$np = BizParties::save($o, ['name' => 'طرف‌حسابِ تازه', 'kind' => 'customer', 'opening_amount' => '5000']);
$na = BizCash::save($o, ['name' => 'بانکِ تازه', 'kind' => 'bank', 'opening_balance' => '7000']);
T::ok($np['ok'] && $na['ok'], 'امروز: طرف‌حساب با ۵٬۰۰۰ و بانک با ۷٬۰۰۰ مانده ساخته شد');
T::same($before, $trialAt($o, $ago(10)), '⛔ ترازِ روزِ قفل عوض نشد (پیش از این ۱۱۰۱: ۱۰۰۰ → ۸۰۰۰)');
$now = $trialAt($o, BizReports::ALL_TO);
T::same([8000, 0], $now['1101'] ?? null, 'ترازِ امروز هر دو صندوق را دارد');
T::same([5000, 0], $now['1103'] ?? null, 'و طلبِ طرف‌حسابِ تازه را');
T::ok(BizLedger::reconcile($o)['ok'], 'دفتر با مانده‌ها می‌خواند');
$jr = array_values(array_filter(BizLedger::entries($o, BizReports::ALL_TO), fn($e) => str_starts_with($e['desc'], 'افتتاحیه')));
T::same([$ago(40), $today], array_column($jr, 'date'), 'افتتاحیه‌ها هر کدام با تاریخِ خودشان');
T::ok(BizLedger::balanced(BizLedger::entries($o, BizReports::ALL_TO)), 'همه‌ی سندها تراز');

// =================================================================
T::group('۴ — علامتِ موجودیِ اولیه در فرمِ حساب (HTTP)');
$w = $make('wal', false);
$root = dirname(__DIR__);
$port = 0;
for ($p = 9510; $p <= 9550; $p++) { $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2); if ($sock) { fclose($sock); $port = $p; break; } }
if (!$port) { T::blocked('علامتِ موجودی', 'پورت آزاد پیدا نشد'); exit(T::report()); }
$log = tempnam(sys_get_temp_dir(), 'spair');
$srv = (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!', $port, escapeshellarg($root), escapeshellarg($log))));
register_shutdown_function(static function () use ($srv, $log) { exec("kill {$srv} 2>/dev/null"); @unlink($log); });
for ($i = 0, $up = false; $i < 40 && !$up; $i++) { usleep(150000); $sk = @fsockopen('127.0.0.1', $port, $a, $b, 0.3); if ($sk) { fclose($sk); $up = true; } }
T::ok($up, 'سرورِ آزمایشی بالا آمد');
if (!$up) { exit(T::report()); }
$jar = tempnam(sys_get_temp_dir(), 'spairjar');
$req = function (string $path, ?array $post = null) use ($port, $jar): array {
    $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 40]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); unset($ch);
    return [$code, $body];
};
[, $lp] = $req('login.php');
$tok = preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $lp, $m) ? $m[1] : '';
$req('login.php', ['csrf_token' => $tok, 'username' => SP_PREFIX . 'wal', 'password' => SP_PASS]);
[$code, $page] = $req('wallets.php');
$tok = preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $page, $m) ? $m[1] : '';
T::ok($code === 200 && str_contains($page, 'id="wallet_init_neg"') && str_contains($page, 'name="initial_sign" value="1"'), 'فرمِ حساب کلیدِ «منفی» را دارد');
$save = fn(array $f) => json_decode($req('api/save_wallet.php', $f + ['csrf_token' => $tok, 'kind' => 'bank', 'color' => '#16794f'])[1], true) ?: [];
$r = $save(['name' => 'کارتِ اعتباری', 'initial_balance' => '۳۰۰٬۰۰۰', 'initial_sign' => '1', 'initial_negative' => '1']);
T::ok(!empty($r['success']), 'حسابِ تازه با موجودیِ منفی', json_encode($r, JSON_UNESCAPED_UNICODE));
$wid = (int)$one("SELECT id FROM wallets WHERE user_id = :u AND name = 'کارتِ اعتباری'", ['u' => $w]);
T::same(-300000, (int)$one('SELECT initial_balance FROM wallets WHERE id = :i', ['i' => $wid]), '⛔ ساخت: −۳۰۰٬۰۰۰');
$save(['wallet_id' => $wid, 'name' => 'کارتِ اعتباری', 'initial_balance' => '۴۰۰٬۰۰۰', 'initial_sign' => '1', 'initial_negative' => '1']);
T::same(-400000, (int)$one('SELECT initial_balance FROM wallets WHERE id = :i', ['i' => $wid]), '⛔ اصلاحِ عدد با کلیدِ روشن منفی می‌ماند (نه +۴۰۰٬۰۰۰)');
$save(['wallet_id' => $wid, 'name' => 'کارتِ اعتباری', 'initial_balance' => '۴۰۰٬۰۰۰', 'initial_sign' => '1']);
T::same(400000, (int)$one('SELECT initial_balance FROM wallets WHERE id = :i', ['i' => $wid]), 'کلیدِ خاموش: مثبت (انتخابِ صریحِ کاربر)');
$wl = (string)$req('wallets.php')[1];
T::ok(preg_match('~data-id="' . $wid . '"[^>]*data-init="400000"|data-init="400000"[^>]*data-id="' . $wid . '"~', $wl) === 1, 'صفحه مقدارِ تازه را برای پرکردنِ فرم دارد');

exit(T::report());
