<?php
/**
 * تستِ «سطحِ دیدن» — cronِ مرده و خطای PHP هر دو بی‌صدا بودند.
 *
 * دو قابلیتِ تازه را با هم می‌سنجد چون هر دو یک شکلِ خرابی دارند:
 * چیزی که **اتفاق نمی‌افتد** و هیچ‌کس خبردار نمی‌شود.
 *   ۱. `CronHealth` — پنج کارِ زمان‌بندی‌شده و «آخرین اجرا»ی هرکدام.
 *   ۲. `AppErrors`  — خطاهای PHP، که تا امروز فقط در لاگِ FPM بودند.
 */

// ---------- نگهبان: فقط خط فرمان ----------
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

T::group('سلامتِ کارهای زمان‌بندی‌شده');

if (!file_exists(__DIR__ . '/../config/config.php')) {
    T::blocked('تستِ سطحِ دیدن', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/cron_health.php';

$root = dirname(__DIR__);

// =====================================================================
// ۱. فهرستِ `JOBS` بسته است — از روی خودِ اسکریپت‌ها کشف می‌شود
// =====================================================================
/**
 * ⛔ فهرستِ دستی دیر یا زود عقب می‌افتد، و اینجا عقب افتادنش یعنی کارِ
 *    زمان‌بندی‌شده‌ای که در پنل مدیر **دیده نمی‌شود** — دقیقاً همان
 *    خرابیِ بی‌صدایی که `CronHealth` برای نبودنش ساخته شد. پس فهرست با
 *    **کشف از دیسک** سنجیده می‌شود: هر اسکریپتی که `--install-cron`
 *    دارد باید یک ردیف در `JOBS` داشته باشد.
 */
$installers = [];
foreach (glob($root . '/deploy/*') as $p) {
    if (is_dir($p)) { continue; }
    $src = (string)file_get_contents($p);
    // ⚠ «خودش این گزینه را می‌پذیرد» (رشته‌ی کوتیشن‌دار یا برچسبِ case)، نه «نامش آمده»:
    //   `nginx-hsts.sh` فقط `ssl-check.php --install-cron` را صدا می‌زند و کارِ cronِ خودش را ندارد.
    if (preg_match('/[\'"]--install-cron[\'"]|^\s*--install-cron\)/m', $src)) {
        $installers[] = 'deploy/' . basename($p);
    }
}

$declared = array_map(fn($j) => $j[2], array_values(CronHealth::JOBS));

T::ok($installers !== [], 'اسکریپتِ زمان‌بندی‌شده پیدا شد',
    'هیچ فایلی با --install-cron نبود — الگوی جست‌وجو کهنه شده');

$missing = array_values(array_diff($installers, $declared));
T::bulk(count($installers), $missing,
    'هر اسکریپتِ دارای --install-cron در `JOBS` ثبت شده است');

$orphan = array_values(array_diff($declared, $installers));
T::bulk(count($declared), $orphan, 'هیچ ردیفِ کهنه‌ای در `JOBS` نمانده');

// =====================================================================
// ۲. هر کار واقعاً نشانه‌اش را می‌زند
// =====================================================================
/**
 * ⛔ ثبت شدن در `JOBS` کافی نیست: اگر اسکریپت `cron_beat` را صدا نزند،
 *    پنل مدیر برای همیشه «اجرا نشده» نشان می‌دهد — یعنی یک هشدارِ
 *    همیشگی برای کاری که سالم است، که آدم را عادت می‌دهد هشدارها را
 *    نادیده بگیرد.
 */
$noBeat = [];
foreach (CronHealth::JOBS as $key => [$label, $hours, $script]) {
    $path = $root . '/' . $script;
    if (!is_readable($path)) { $noBeat[] = "{$script} روی دیسک نیست"; continue; }
    $src = (string)file_get_contents($path);

    $ok = str_ends_with($script, '.php')
        ? strpos($src, "CronHealth::beat('{$key}')") !== false
        : (bool)preg_match('/cron_beat\s+' . preg_quote($key, '/') . '\b/', $src);

    if (!$ok) { $noBeat[] = "{$script} نشانه‌ی «{$key}» را نمی‌زند"; }
}
T::bulk(count(CronHealth::JOBS), $noBeat, 'هر اسکریپت نشانه‌ی خودش را ثبت می‌کند');

// و اسکریپت‌های bash باید کمک‌کننده‌ی مشترک را لود کنند، نه نسخه‌ی خودشان
$ownBeat = [];
foreach (CronHealth::JOBS as $key => [$l, $h, $script]) {
    if (str_ends_with($script, '.php')) { continue; }
    $src = (string)file_get_contents($root . '/' . $script);
    if (strpos($src, 'cron-beat.sh') === false) {
        $ownBeat[] = "{$script} — `cron-beat.sh` را لود نمی‌کند";
    }
}
T::bulk(3, $ownBeat, 'همه از همان `deploy/cron-beat.sh` رد می‌شوند');

// =====================================================================
// ۳. رفتار: هرگز → سالم → کهنه
// =====================================================================
$job  = 'backup';
$file = CronHealth::file($job);
$keep = is_readable($file) ? (string)file_get_contents($file) : null;

@unlink($file);
$st = array_column(CronHealth::status(), null, 'key');
T::same('never', $st[$job]['state'], 'بدونِ نشانه، وضعیت «هرگز اجرا نشده» است');

T::ok(CronHealth::beat($job), 'ثبتِ نشانه انجام شد');
$st = array_column(CronHealth::status(), null, 'key');
T::same('ok', $st[$job]['state'], 'بلافاصله پس از ثبت، «سالم»');

/**
 * ⛔ مهم‌ترین بررسیِ این بخش: «کهنه» باید واقعاً از «سالم» جدا شود.
 *    نشانه عمداً به گذشته برده می‌شود — با صبر کردن آزمودنی نبود، و
 *    همان دامی است که یک بار سرِ `markBalanceSetup()` افتادیم (دو
 *    فراخوان در یک ثانیه، پس `NOW()` یکی بود و جهش زنده ماند).
 */
file_put_contents($file, (string)(time() - CronHealth::staleAfter($job) - 60));
$st = array_column(CronHealth::status(), null, 'key');
T::same('stale', $st[$job]['state'], 'نشانه‌ی قدیمی «کهنه» خوانده می‌شود');

// درست روی مرز هنوز سالم است — وگرنه یک اجرای چند دقیقه دیرتر هم
// «خراب» اعلام می‌شد و هشدارِ الکی از نبودِ هشدار بدتر است.
file_put_contents($file, (string)(time() - CronHealth::staleAfter($job) + 600));
$st = array_column(CronHealth::status(), null, 'key');
T::same('ok', $st[$job]['state'], 'کمی پیش از مرز هنوز «سالم» است');

T::same(false, CronHealth::beat('__no_such_job__'), 'کارِ ناشناخته نشانه نمی‌گیرد');

if ($keep !== null) { file_put_contents($file, $keep); } else { @unlink($file); }

// =====================================================================
// ۴. خطاهای برنامه
// =====================================================================
T::group('سطحِ دیدنِ خطاهای PHP');

require_once __DIR__ . '/../includes/app_errors.php';

// ⛔ نرمال‌سازی: مقدارِ کاربر می‌رود، بخشِ گویا می‌ماند.
$scrub = AppErrors::scrub("SQLSTATE: Unknown column 'wallet_id' for ali@example.com id 1234567");
T::ok(strpos($scrub, 'ali@example.com') === false, 'ایمیل از متنِ خطا برداشته می‌شود', $scrub);
T::ok(strpos($scrub, '1234567') === false, 'عددِ بلند از متنِ خطا برداشته می‌شود', $scrub);
T::ok(strpos($scrub, "Unknown column 'wallet_id'") !== false,
    'ولی بخشِ گویا دست‌نخورده می‌ماند', $scrub);

// عددِ کوتاه (شماره‌ی خط، کدِ خطا) نباید پوشانده شود، وگرنه متن بی‌معنا می‌شود
T::ok(strpos(AppErrors::scrub('Error 42 on line 7'), '42') !== false,
    'عددِ کوتاه پوشانده نمی‌شود');

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::blocked('ثبتِ خطا', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

if (!tableExists('app_errors')) {
    T::skip('ثبتِ خطا', 'migration_app_errors اجرا نشده');
    exit(T::report());
}

/**
 * ⛔ این جدول نباید هیچ‌وقت ستونی بگیرد که به **کاربر** اشاره کند.
 *    با `user_id` (یا آدرسِ صفحه) همان «ردِ رفتاریِ تازه» ساخته می‌شود
 *    که `admin/insights.php` صریحاً از آن پرهیز کرده و `privacy.php`
 *    رویش ادعا نوشته. این بررسی همان مرز را نگه می‌دارد.
 */
$cols = [];
$st = $pdo->query("SHOW COLUMNS FROM app_errors");
foreach ($st->fetchAll() as $c) { $cols[] = strtolower($c['Field']); }

$forbidden = array_values(array_intersect($cols, ['user_id', 'username', 'ip', 'url', 'request_uri']));
T::bulk(count($cols), $forbidden, '⛔ جدولِ خطا هیچ ستونِ اشاره‌کننده به کاربر ندارد');

AppErrors::clear();

$msg = 'تستِ ثبتِ خطا ' . bin2hex(random_bytes(4));
T::ok(AppErrors::record('warning', $msg, __FILE__, 11), 'خطا ثبت شد');
T::ok(AppErrors::record('warning', $msg, __FILE__, 11), 'ثبتِ دوباره‌ی همان خطا');

$rows = AppErrors::recent(5);
T::same(1, count($rows), '⛔ خطای تکراری ردیفِ تازه نمی‌سازد (کلیدِ یکتا)');
T::same(2, (int)($rows[0]['hits'] ?? 0), 'بلکه شمارنده بالا می‌رود');

// مسیر باید نسبی باشد، وگرنه فقط عرضِ جدول را می‌گیرد
T::ok(strpos((string)($rows[0]['file'] ?? ''), '/home') !== 0,
    'مسیرِ فایل نسبی ذخیره می‌شود', (string)($rows[0]['file'] ?? ''));

T::same(1, AppErrors::countSince(7), 'شمارشِ هفتگی همان یک خطا را می‌بیند');

AppErrors::clear();
T::same(0, count(AppErrors::recent(5)), 'پاک کردن فهرست کار می‌کند');

/**
 * ⛔ و گیرنده باید واقعاً نصب شده باشد — نه فقط تعریف شده. `db.php`
 *    تنها فایلی است که هر مسیر لود می‌کند، پس نصب از آنجاست؛ با نصب از
 *    جای دیگر همیشه مسیری می‌ماند که خطایش را کسی نمی‌بیند.
 */
$dbSrc = (string)file_get_contents($root . '/includes/db.php');
T::ok(strpos($dbSrc, 'AppErrors::install()') !== false,
    'گیرنده‌ی خطا از `db.php` نصب می‌شود');

$prev = set_exception_handler(null);
T::ok($prev !== null, 'گیرنده‌ی استثنای گرفته‌نشده واقعاً ثبت شده است');
if ($prev !== null) { set_exception_handler($prev); }

exit(T::report());
