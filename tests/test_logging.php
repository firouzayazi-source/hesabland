<?php
/**
 * تستِ لاگ، شناسه‌ی درخواست، و دفترِ ممیزی — رفتار، نه شکل.
 *
 * دو نیمه دارد: (۱) خودِ `Log`/`Audit` بدونِ HTTP، (۲) مسیرِ واقعی با
 * `php -S` و نشستِ واقعی — ورود، خروج، ورودِ ناموفق، سرآیندِ
 * `X-Request-Id`، پاکتِ خطا، اندپوینتِ سلامت، خطای مرورگر، و صفحه‌ی
 * «کد پیگیری» برای استثنای گرفته‌نشده.
 *
 * ⛔ مهم‌ترین بررسی‌هایش آن‌هایی است که **نبودِ** چیزی را می‌سنجند:
 *    رمز و شماره کارت در هیچ خطی نباشد، شناسه‌ی تایپ‌شده در ورودِ
 *    ناموفق ثبت نشود، `admin_insights` از `audit_log` نخواند، و
 *    `app_errors` هنوز `user_id` نداشته باشد. لاگری که این‌ها را نشت
 *    بدهد از نبودنش بدتر است.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';

if (!file_exists(__DIR__ . '/../config/config.php')) {
    T::blocked('تستِ لاگ', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';

$root = dirname(__DIR__);

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::blocked('تستِ لاگ', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}
if (!tableExists('audit_log') || !tableExists('app_errors')) {
    T::blocked('تستِ لاگ', 'migration_audit_log / migration_app_errors اجرا نشده');
    exit(T::report());
}

/** آخرین خطوطِ فایلِ همین کانال که event مشخصی دارند. */
$linesWith = function (string $event, ?string $file = null): array {
    $rows = Log::readLines($file ?? Log::file(), 200000);
    return array_values(array_filter($rows, fn($r) => ($r['event'] ?? '') === $event));
};

// =====================================================================
T::group('پاک‌سازی — هیچ رازی نوشته نمی‌شود');
// =====================================================================

$r = Log::redact([
    'password' => 'hunter2', 'user' => ['token' => 'abc', 'name' => 'x'],
    'note' => 'card 6037 9912 3456 7890 and IR123456789012345678901234 and a@b.ir',
    'amount' => 12345, 'CSRF_TOKEN' => 'zz',
]);
T::same(Log::REDACTED, $r['password'], 'کلیدِ password پنهان می‌شود');
T::same(Log::REDACTED, $r['user']['token'], 'کلیدِ حساس در عمق هم پنهان می‌شود');
T::same('x', $r['user']['name'], 'کلیدِ بی‌خطر دست‌نخورده می‌ماند');
T::same(Log::REDACTED, $r['CSRF_TOKEN'], 'نامِ کلید بدونِ حساسیت به حروف سنجیده می‌شود');
T::same(12345, $r['amount'], 'عددِ کوچک (شناسه/مبلغ) عدد می‌ماند');
T::ok(!str_contains($r['note'], '6037') && str_contains($r['note'], '[کارت]'), 'شماره‌ی کارت با شکلش پیدا و پنهان می‌شود', $r['note']);
T::ok(!str_contains($r['note'], 'IR1234') && str_contains($r['note'], '[شبا]'), 'شبا با شکلش پنهان می‌شود', $r['note']);
T::ok(!str_contains($r['note'], 'a@b.ir') && str_contains($r['note'], '[ایمیل]'), 'ایمیل پنهان می‌شود', $r['note']);

function __logProbeThrow(string $secretArg): void { throw new RuntimeException('probe'); }
try { __logProbeThrow('SECRET_ARG_VALUE'); } catch (Throwable $e) { $trace = Log::trace($e); }
T::ok($trace !== [] && !str_contains(implode("\n", $trace), 'SECRET_ARG_VALUE'),
    '⛔ ردِ پشته مقدارِ آرگومان‌ها را ندارد', implode(' | ', $trace));
T::ok(str_contains($trace[0] ?? '', 'tests/test_logging.php'), 'مسیرِ فایل در ردِ پشته نسبی است', $trace[0] ?? '');

// =====================================================================
T::group('نوشتن در فایل و شکلِ خط');
// =====================================================================

// ⚠ فقط حرف، نه رقم: `AppErrors::scrub()` هر دنباله‌ی چهاررقمی به بالا را
//    `[عدد]` می‌کند، پس نشانه‌ی hex گاهی خودش شسته می‌شد و بررسی الکی
//    قرمز می‌شد.
$marker = 'probe_' . substr(str_shuffle('abcdefghijklmnopqrstuvwxyz'), 0, 8);
T::ok(Log::info('test.' . $marker, ['n' => 1, 'password' => 'p']), 'خطِ info نوشته می‌شود');
T::ok(!Log::debug('test.debug_' . $marker), 'با LOG_LEVEL پیش‌فرض، debug نوشته نمی‌شود (نویز نه)');
$ln = $linesWith('test.' . $marker);
T::same(1, count($ln), 'دقیقاً یک خط با آن event در فایلِ امروز هست');
$ln = $ln[0] ?? [];
foreach (['ts', 'level', 'env', 'svc', 'req', 'event'] as $k) {
    T::ok(array_key_exists($k, $ln), "کلیدِ اجباری «{$k}» در خط هست");
}
T::same('cli', $ln['svc'] ?? '', 'در خط فرمان svc = cli');
T::ok(str_starts_with((string)($ln['req'] ?? ''), 'cli-'), 'شناسه‌ی درخواستِ خط فرمان پیشوندِ cli دارد');
T::same(Log::REDACTED, $ln['password'] ?? '', 'ctx پیش از نوشتن شسته شده');
T::ok((bool)preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', (string)($ln['ts'] ?? '')),
    'زمان ISO-8601 با افستِ منطقه است', (string)($ln['ts'] ?? ''));
T::same('prod', Log::env(), 'بدونِ APP_ENV محیط prod است (سمتِ امن)');

$before = Log::dbStats()['n'];
$pdo->query('SELECT 1')->fetchColumn();
$pdo->prepare('SELECT 2')->execute();
T::same($before + 2, Log::dbStats()['n'], 'هر کوئری (query و prepare/execute) شمرده می‌شود');

// =====================================================================
T::group('Log::error — یک فراخوانی، دو مقصد');
// =====================================================================

AppErrors::clear();
$errMsg = 'probe error ' . $marker . ' card 6037991234567890';
try { __logProbeThrow($errMsg); } catch (Throwable $e) { $ok = Log::error('test.err_' . $marker, $e); }
T::ok($ok, 'Log::error ثبت شد');
$rows = AppErrors::recent(3);
T::same(1, count($rows), 'یک ردیف در app_errors');
T::ok(!str_contains((string)($rows[0]['message'] ?? ''), '6037991234567890'), '⛔ عددِ بلند در app_errors پوشانده شده');
$fl = $linesWith('test.err_' . $marker);
T::same(1, count($fl), 'و یک خط در فایل — نه دو تا');
T::same('error', $fl[0]['level'] ?? '', 'سطحِ خطِ فایل error است');
T::ok(is_array($fl[0]['trace'] ?? null) && ($fl[0]['trace'] ?? []) !== [], 'خطِ فایل ردِ پشته دارد');
T::ok(array_key_exists('stage', $fl[0]), 'خطِ فایل مرحله (stage) دارد');
T::ok(!str_contains(json_encode($fl[0]), '6037991234567890'), '⛔ عددِ بلند در فایل هم پوشانده شده');

// =====================================================================
T::group('Audit — فهرستِ بسته، شستنِ جزئیات، هرس');
// =====================================================================

$pdo->exec('DELETE FROM audit_log');
T::ok(!Audit::log('made.up_action', 'x', 1), 'رویدادِ ناشناخته ثبت نمی‌شود');
T::same(0, (int)$pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn(), 'و ردیفی هم نساخت');

T::ok(Audit::log('settings.changed', 'setting', null, ['key' => 'k', 'password' => 'p', 'note' => 'a@b.ir']),
    'رویدادِ شناخته ثبت می‌شود');
$row = $pdo->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT 1')->fetch();
T::same(null, $row['actor_id'], 'در خط فرمان actor خالی است');
T::same(null, $row['ip'], 'در خط فرمان ip خالی است');
T::ok(str_contains((string)$row['detail'], Log::REDACTED) && !str_contains((string)$row['detail'], '"p"'),
    '⛔ رمز داخلِ detail پنهان است', (string)$row['detail']);
T::ok(!str_contains((string)$row['detail'], 'a@b.ir'), '⛔ ایمیل داخلِ detail پنهان است');
T::ok(str_starts_with((string)$row['request_id'], 'cli-'), 'شناسه‌ی درخواست روی ردیف هست');

Audit::setting('sms_api_key', 'oldkey', 'newkey');
$row = $pdo->query('SELECT detail FROM audit_log ORDER BY id DESC LIMIT 1')->fetch();
T::ok(!str_contains((string)$row['detail'], 'newkey') && !str_contains((string)$row['detail'], 'oldkey'),
    '⛔ مقدارِ تنظیمِ محرمانه (key/pass/secret/token) در detail نمی‌آید', (string)$row['detail']);
Audit::setting('allow_signup', '0', '1');
$row = $pdo->query('SELECT detail FROM audit_log ORDER BY id DESC LIMIT 1')->fetch();
T::ok(str_contains((string)$row['detail'], '"from":"0"') && str_contains((string)$row['detail'], '"to":"1"'),
    'مقدارِ تنظیمِ عادی هم قبل هم بعد را دارد', (string)$row['detail']);

// هرس: یک ردیفِ کهنه
$pdo->exec("UPDATE audit_log SET created_at = DATE_SUB(NOW(), INTERVAL " . (Audit::KEEP_DAYS + 5) . " DAY) ORDER BY id ASC LIMIT 1");
$n = Audit::prune();
T::same(1, $n, 'هرس فقط ردیفِ کهنه‌تر از KEEP_DAYS را می‌برد');
T::same(90, Audit::KEEP_DAYS, 'KEEP_DAYS همان ۹۰ روزی است که privacy.php می‌گوید');

// همه‌ی نام‌های استفاده‌شده در کد در فهرست‌اند و برعکس — فهرست بسته است
$used = [];
foreach (['api', 'includes', 'admin', 'deploy', '.', 'api/v1/routes'] as $d) {
    foreach (glob($root . '/' . $d . '/*.php') as $p) {
        $src = (string)file_get_contents($p);
        if (realpath($p) === realpath($root . '/includes/audit.php')) {
            // خودِ تعریفِ فهرست استفاده نیست؛ ولی `self::log('settings.changed'` هست.
            $src = (string)preg_replace('/const ACTIONS = \[.*?\];/s', '', $src);
        }
        if (preg_match_all("/Audit::log\('([a-z_.]+)'/", $src, $mm)) {
            foreach ($mm[1] as $a) { $used[$a] = true; }
        }
        // نامِ داخلِ سه‌تایی (`$x ? 'user.activated' : 'user.deactivated'`) هم استفاده است
        foreach (Audit::ACTIONS as $a) {
            if (str_contains($src, "'" . $a . "'")) { $used[$a] = true; }
        }
    }
}
$undeclared = array_values(array_diff(array_keys($used), Audit::ACTIONS));
T::bulk(count($used), $undeclared, 'هر Audit::log در کد نامی از ACTIONS دارد');
$unused = array_values(array_diff(Audit::ACTIONS, array_keys($used)));
T::bulk(count(Audit::ACTIONS), $unused, 'هر نامِ ACTIONS جایی در کد استفاده شده (فهرستِ کهنه نه)');

// =====================================================================
T::group('setSetting فقط با actor و فقط روی تغییرِ واقعی ممیزی می‌شود');
// =====================================================================

$pdo->exec('DELETE FROM audit_log');
$key = '__log_probe_' . substr($marker, 6);
setSetting($key, '1');
T::same(0, (int)$pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn(),
    'بدونِ کاربر (cron/خط فرمان) ردیفِ ممیزی ساخته نمی‌شود');
Log::setUser(1);
setSetting($key, '1');
T::same(0, (int)$pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn(),
    'مقدارِ یکسان ردیف نمی‌سازد');
setSetting($key, '2');
T::same(1, (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'settings.changed'")->fetchColumn(),
    'تغییرِ واقعی با کاربر یک ردیف می‌سازد');
$row = $pdo->query('SELECT actor_id, detail FROM audit_log ORDER BY id DESC LIMIT 1')->fetch();
T::same(1, (int)$row['actor_id'], 'actor همان کاربرِ درخواست است');
T::ok(str_contains((string)$row['detail'], '"from":"1"') && str_contains((string)$row['detail'], '"to":"2"'), 'قبل و بعد ثبت شده');
Log::setUser(null);
forgetSetting($key);

// =====================================================================
T::group('⛔ مرزها: app_errors بی‌کاربر می‌ماند، insights از audit نمی‌خواند');
// =====================================================================

$cols = array_map(fn($c) => strtolower($c['Field']), $pdo->query('SHOW COLUMNS FROM app_errors')->fetchAll());
T::ok(!in_array('user_id', $cols, true) && !in_array('request_id', $cols, true),
    'app_errors همچنان نه user_id دارد نه request_id (شناسه فقط در فایل است)');
$ai = (string)file_get_contents($root . '/includes/admin_insights.php') . (string)file_get_contents($root . '/admin/insights.php');
$aiNoCmt = '';
foreach (token_get_all($ai) as $t) {
    if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
    $aiNoCmt .= is_array($t) ? $t[1] : $t;
}
T::ok(!preg_match('/FROM\s+audit_log|Audit::recent\(\)\s*\)|userActivity[^;]*audit/i', $aiNoCmt)
    && !str_contains($aiNoCmt, 'auth.login\''),
    '«آمار استفاده» از audit_log آمار نمی‌سازد (فقط کارتِ جدا نشانش می‌دهد)');
$auditCols = array_map(fn($c) => strtolower($c['Field']), $pdo->query('SHOW COLUMNS FROM audit_log')->fetchAll());
T::ok(!in_array('user_id', $auditCols, true), '⛔ audit_log ستونِ user_id ندارد (تا با حذفِ حساب نرود و در خروجی نیاید)');
T::ok(!in_array('audit_log', userDataTables(), true), 'و userDataTables() آن را جزوِ داده‌ی کاربر نمی‌شمارد');

// =====================================================================
T::group('مسیرِ واقعی با HTTP');
// =====================================================================

$port = 0;
for ($p = 8951; $p <= 8969; $p++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$p", $errno, $errstr);
    if ($sock) { fclose($sock); $port = $p; break; }
}
if (!$port) { T::skip('مسیرِ HTTP', 'پورت آزاد پیدا نشد'); exit(T::report()); }

$srvLog = tempnam(sys_get_temp_dir(), 'logtest');
$pid = (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
    $port, escapeshellarg($root), escapeshellarg($srvLog))));
$up = false;
for ($i = 0; $i < 40; $i++) {
    usleep(150000);
    $s = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.3);
    if ($s) { fclose($s); $up = true; break; }
}
$probeFile = $root . '/__log_probe_throw.php';
$cleanup = function () use ($pid, $probeFile) {
    if ($pid) { @exec("kill $pid 2>/dev/null"); }
    @unlink($probeFile);
};
register_shutdown_function($cleanup);
if (!$up) { T::skip('مسیرِ HTTP', 'سرور آزمایشی بالا نیامد'); exit(T::report()); }

$jar = tempnam(sys_get_temp_dir(), 'logjar');
$req = function (string $path, ?array $fields = null, array $headers = [], bool $cookies = true) use ($port, $jar): array {
    $ch = curl_init("http://127.0.0.1:$port$path");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($cookies) { curl_setopt($ch, CURLOPT_COOKIEJAR, $jar); curl_setopt($ch, CURLOPT_COOKIEFILE, $jar); }
    if ($fields !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields)); }
    $raw  = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs   = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    unset($ch);
    $hdr = [];
    foreach (explode("\r\n", substr($raw, 0, $hs)) as $h) {
        if (str_contains($h, ':')) { [$k, $v] = explode(':', $h, 2); $hdr[strtolower(trim($k))] = trim($v); }
    }
    return [$code, substr($raw, $hs), $hdr];
};

// ---- سلامت ----
[$c, $b, $h] = $req('/health.php', null, [], false);
$j = json_decode($b, true);
T::same(200, $c, 'health.php ۲۰۰ می‌دهد');
T::ok(is_array($j) && ($j['ok'] ?? false) === true, 'و ok=true');
T::ok(isset($j['db']['ok']) && $j['db']['ok'] === true, 'از لوکال جزئیات (دیتابیس) هم می‌آید');
T::ok(isset($j['cron']) && isset($j['version']), 'cron و نسخه در جزئیات هست');
T::ok(!empty($h['x-request-id']), 'سرآیندِ X-Request-Id روی پاسخ هست');

// ---- شناسه‌ی درخواست ----
[, , $h] = $req('/health.php', null, ['X-Request-Id: client-abc-12345'], false);
T::same('client-abc-12345', $h['x-request-id'] ?? '', 'شناسه‌ی معتبرِ مشتری نگه داشته می‌شود');
[, , $h] = $req('/health.php', null, ['X-Request-Id: bad id!!'], false);
T::ok(($h['x-request-id'] ?? '') !== 'bad id!!' && ($h['x-request-id'] ?? '') !== '', 'شناسه‌ی نامعتبر رد و جایگزین می‌شود');
[, , $h] = $req('/health.php', null, ['X-Request-Id: ' . str_repeat('a', 65)], false);
T::ok(strlen($h['x-request-id'] ?? '') <= 64, 'شناسه‌ی بلندتر از ۶۴ پذیرفته نمی‌شود');

// ---- پاکتِ خطا ----
[$c, $b, $h] = $req('/api/add_transaction.php', ['x' => '1'], [], false);
$j = json_decode($b, true);
T::same(401, $c, 'بدونِ ورود ۴۰۱');
T::same($h['x-request-id'] ?? '?', $j['request_id'] ?? '', 'پاکتِ خطای api/ همان request_id سرآیند را دارد');
[$c, $b, $h] = $req('/api/v1/index.php?p=me', null, [], false);
$j = json_decode($b, true);
T::same(401, $c, 'api/v1 بدونِ توکن ۴۰۱');
T::same($h['x-request-id'] ?? '?', $j['error']['request_id'] ?? '', 'پاکتِ خطای api/v1 هم request_id دارد');

// ---- کاربرِ آزمایشی، ورود، خروج ----
$U = '__log_probe_user'; $P = 'LogProbe12345';
$purge = function () use ($pdo, $U) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u'); $st->execute(['u' => $U]);
    if ($id = $st->fetchColumn()) { deleteUserAccount((int)$id); }
};
$purge();
$acc = createUserAccount($pdo, 'کاربر لاگ', $U, '', $P, 'user');
T::ok($acc['ok'] ?? false, 'کاربر ساخته شد');
$uid = (int)($acc['id'] ?? 0);
$pdo->exec('DELETE FROM audit_log');
$pdo->prepare('DELETE FROM login_attempts WHERE 1')->execute();

$csrfOf = function (string $html): string {
    return preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m) ? $m[1] : '';
};
[, $lh] = $req('/login.php');
$tok = $csrfOf($lh);
T::ok($tok !== '', 'توکن CSRF از صفحه‌ی ورود گرفته شد');

// ورودِ ناموفق
$req('/login.php', ['csrf_token' => $tok, 'username' => $U, 'password' => 'wrong-pass-1']);
$row = $pdo->query("SELECT * FROM audit_log WHERE action = 'auth.login_failed' ORDER BY id DESC LIMIT 1")->fetch();
T::ok((bool)$row, 'ورودِ ناموفق ثبت شد');
T::same($uid, (int)($row['target_user_id'] ?? 0), 'حسابِ هدف روی ردیفِ ورودِ ناموفق');
T::same('127.0.0.1', (string)($row['ip'] ?? ''), 'آی‌پی روی ردیف');
T::ok(!str_contains((string)($row['detail'] ?? ''), $U), '⛔ شناسه‌ی تایپ‌شده در ردیفِ ورودِ ناموفق نیست');
$pdo->prepare('DELETE FROM login_attempts WHERE 1')->execute();

// ورودِ موفق
[, $lh] = $req('/login.php'); $tok = $csrfOf($lh);
[$lc] = $req('/login.php', ['csrf_token' => $tok, 'username' => $U, 'password' => $P]);
T::same(302, $lc, 'ورود انجام شد');
$row = $pdo->query("SELECT * FROM audit_log WHERE action = 'auth.login' ORDER BY id DESC LIMIT 1")->fetch();
T::ok((bool)$row, 'ورودِ موفق ثبت شد');
T::same($uid, (int)($row['actor_id'] ?? 0), 'actor همان کاربر است');
T::ok(str_contains((string)$row['detail'], '"via":"password"'), 'روشِ ورود ثبت شده');
T::ok(!str_contains((string)$row['detail'], $P), '⛔ رمز در ردیف نیست');

// خطِ request برای یک صفحه‌ی واردشده
[, $home, $h] = $req('/index.php');
$rid = $h['x-request-id'] ?? '';
$webLines = Log::readLines(Log::dir() . '/web-' . date('Y-m-d') . '.log', 500000);
$mine = array_values(array_filter($webLines, fn($r) => ($r['req'] ?? '') === $rid && ($r['event'] ?? '') === 'request'));
T::same(1, count($mine), 'برای آن درخواست دقیقاً یک خطِ request در web-*.log هست');
$m0 = $mine[0] ?? [];
T::same('index.php', $m0['route'] ?? '', 'route ثبت شده');
T::same($uid, (int)($m0['uid'] ?? 0), 'uid روی خطِ request');
T::same(200, (int)($m0['status'] ?? 0), 'status ثبت شده');
T::ok(($m0['ms'] ?? 0) > 0 && ($m0['db']['n'] ?? 0) > 0, 'زمان و تعدادِ کوئری ثبت شده');
T::same('web', $m0['svc'] ?? '', 'svc = web');
T::ok(!str_contains(json_encode($webLines), $P), '⛔ رمز در هیچ خطِ لاگِ وب نیست');

// خطای مرورگر
$tok = $csrfOf($home);
AppErrors::clear();
// ⚠ curlِ PHP به‌خودی‌خود هیچ User-Agent ای نمی‌فرستد؛ مرورگرِ واقعی
//    می‌فرستد. پس صریح داده می‌شود تا «ua روی خط هست» چیزِ واقعی را بسنجد.
[$c, $b] = $req('/api/log_client_error.php', ['csrf_token' => $tok, 'message' => 'TypeError: x is not a function ' . $marker,
    'file' => 'app.js', 'line' => '42', 'page' => '/index.php', 'stack' => 'at foo (app.js:42)'],
    ['User-Agent: ProbeBrowser/1.0 (' . $marker . ')']);
T::same(200, $c, 'خطای مرورگر پذیرفته شد');
$rows = AppErrors::recent(3);
T::ok(count($rows) === 1 && $rows[0]['level'] === 'client', 'در app_errors با سطحِ client نشست');
$cl = array_values(array_filter(Log::readLines(Log::dir() . '/web-' . date('Y-m-d') . '.log', 500000),
    fn($r) => ($r['event'] ?? '') === 'client.error' && str_contains((string)($r['msg'] ?? ''), $marker)));
T::same(1, count($cl), 'و یک خطِ client.error در فایل');
T::ok(str_contains((string)($cl[0]['ua'] ?? ''), 'ProbeBrowser/1.0'), 'مرورگر (ua) روی خط هست');
T::same('/index.php', (string)($cl[0]['page'] ?? ''), 'و صفحه‌ای که خطا در آن رخ داد');
[$c] = $req('/api/log_client_error.php', ['message' => 'x'], [], false);
T::same(401, $c, 'بدونِ ورود پذیرفته نمی‌شود');

// خروج
$req('/logout.php');
$row = $pdo->query("SELECT actor_id FROM audit_log WHERE action = 'auth.logout' ORDER BY id DESC LIMIT 1")->fetch();
T::same($uid, (int)($row['actor_id'] ?? 0), 'خروج با همان کاربر ثبت شد');

// ---- استثنای گرفته‌نشده → صفحه‌ی «کد پیگیری» + خطِ fatal ----
file_put_contents($probeFile, "<?php\nrequire_once __DIR__ . '/includes/db.php';\nthrow new RuntimeException('probe uncaught " . $marker . "');\n");
[$c, $b, $h] = $req('/__log_probe_throw.php', null, [], false);
@unlink($probeFile);
$rid = $h['x-request-id'] ?? '';
T::same(500, $c, 'استثنای گرفته‌نشده ۵۰۰ می‌دهد');
if (str_contains($b, 'Stack trace')) {
    T::skip('صفحه‌ی «کد پیگیری»', 'display_errors روشن است — خودِ ردِ پشته نشان داده می‌شود');
} else {
    T::ok(str_contains($b, $rid) && str_contains($b, 'کد'), 'صفحه‌ی خطا همان کدِ پیگیریِ سرآیند را نشان می‌دهد');
    T::ok(!str_contains($b, 'probe uncaught'), '⛔ متنِ خودِ خطا به کاربر نمی‌رود');
}
$fat = array_values(array_filter(Log::readLines(Log::dir() . '/web-' . date('Y-m-d') . '.log', 500000),
    fn($r) => ($r['req'] ?? '') === $rid && ($r['level'] ?? '') === 'fatal'));
T::same(1, count($fat), 'یک خطِ fatal با همان شناسه در فایل هست');
T::ok(str_contains((string)($fat[0]['msg'] ?? ''), 'probe uncaught'), 'و متنِ خطا آنجا هست (برای مالکِ نصب)');
// ⚠ ترتیبِ `last_seen` در یک ثانیه با ردیفِ client یکی است، پس «اولین
//    ردیف» تصادفی است؛ خودِ ردیف با پیامش پیدا می‌شود.
$rowE = array_values(array_filter(AppErrors::recent(5),
    fn($r) => in_array($r['level'], ['exception', 'fatal'], true) && str_contains((string)$r['message'], 'probe uncaught')));
T::same(1, count($rowE), 'و در app_errors هم نشست');

// ---- گزارش ----
exec('php ' . escapeshellarg($root . '/deploy/log-report.php') . ' --hours 1 --json 2>&1', $out, $rc);
$rep = json_decode(implode("\n", $out), true);
T::ok(is_array($rep) && isset($rep['requests']) && $rep['requests'] > 0, 'log-report درخواست‌های همین تست را می‌شمارد', implode(' ', $out));
T::ok(is_array($rep) && ($rep['status_5xx'] ?? 0) >= 1, 'و ۵xx ی که همین حالا ساختیم را می‌بیند');
$out = [];
exec('php ' . escapeshellarg($root . '/deploy/log-report.php') . ' --req ' . escapeshellarg($rid) . ' 2>&1', $out, $rc);
T::ok($rc === 0 && count($out) >= 2, '--req همه‌ی خطوطِ یک درخواست را می‌آورد (fatal + request)');

$purge();
AppErrors::clear();
@unlink($jar); @unlink($srvLog);
exit(T::report());
