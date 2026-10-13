<?php
/**
 * سنجشِ سلامت — برای مانیتورِ بیرونی و برای `hesabland`.
 *
 * دو سطح، عمداً:
 *   - **عمومی** (هر کسی): فقط `{"ok": true|false}` و کدِ ۲۰۰/۵۰۳. همین
 *     برای یک مانیتورِ uptime کافی است و هیچ چیزی درباره‌ی نصب لو
 *     نمی‌دهد (نه نسخه، نه وضعیتِ cron، نه شمارِ خطا).
 *   - **کامل** (مدیرِ واردشده، یا درخواست از خودِ سرور): دیتابیس و
 *     زمانش، نوشتنی بودنِ `var/`، خطاهای ۲۴ ساعت، cronهای کهنه، نسخه.
 *
 * ⛔ فقط چیزهایی که **واقعاً** در این نصب هستند سنجیده می‌شوند: اپ،
 *    دیتابیس، دیسکِ نوشتنی، و cron. کش، صف و سرویسِ بیرونی وجود ندارند
 *    و سنجشِ چیزی که نیست همان «سنجشی است که همیشه سبز است».
 *
 * ⚠ نشست فقط وقتی باز می‌شود که کوکیِ نشست از قبل باشد: مانیتورِ
 *   بیرونی هر دقیقه می‌زند و `initSession()` برای هر ضربه یک فایلِ
 *   نشست می‌ساخت — همان دامِ ۴۳۱۰ فایلِ `var/sessions`.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/cron_health.php';
require_once __DIR__ . '/includes/user_data.php';   // appVersion()

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$detailed = in_array((string)($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true);
if (!$detailed && !empty($_COOKIE[session_name()])) {
    require_once __DIR__ . '/includes/auth.php';
    Auth::initSession();
    $detailed = Auth::isLoggedIn() && Auth::isAdmin();
}

$ok    = true;
$out   = [];

// ---- دیتابیس ----
$t = hrtime(true);
try {
    $pdo = Database::getConnection();
    $pdo->query('SELECT 1')->fetchColumn();
    $out['db'] = ['ok' => true, 'ms' => round((hrtime(true) - $t) / 1e6, 1)];
} catch (Throwable $e) {
    $ok = false;
    $out['db'] = ['ok' => false];
}

// ---- دیسکِ نوشتنی (نشست و لاگ) ----
$varOk = is_writable(__DIR__ . '/var/sessions') && (is_writable(Log::dir()) || (!is_dir(Log::dir()) && is_writable(__DIR__ . '/var')));
if (!$varOk) { $ok = false; }
$out['var_writable'] = $varOk;

if (!$detailed) {
    http_response_code($ok ? 200 : 503);
    echo json_encode(['ok' => $ok], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- جزئیات ----
$stale = [];
foreach (CronHealth::status() as $c) {
    if ($c['state'] !== 'ok') { $stale[] = ['job' => $c['key'], 'state' => $c['state'], 'age' => $c['age']]; }
}
$out['cron']       = ['ok' => $stale === [], 'problems' => $stale];
$out['errors_24h'] = AppErrors::countSince(1);
$out['version']    = appVersion();
$out['env']        = Log::env();
$out['php']        = PHP_VERSION;
$out['time']       = date('c');
$out['request_id'] = Log::requestId();

http_response_code($ok ? 200 : 503);
echo json_encode(['ok' => $ok] + $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
