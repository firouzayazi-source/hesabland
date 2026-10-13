<?php
/**
 * تستِ رفتارِ «رسیدگی به خطا» — `admin/errors.php` و `AppErrors`.
 *
 * **خواسته‌ی مالکِ نصب:** «یک سیستم لاگ می‌خوام که توی بخش مدیریت
 * اطلاع‌رسانی بشه و بتونم راحت برطرفش کنم، شلوغ نباشه و قابل مدیریت.»
 *
 * ⛔ مهم‌ترین بررسیِ کلِ این فایل یکی است: **رخدادِ دوباره، «برطرف شد» را
 *    پس می‌گیرد.** بدونِ آن، آن دکمه به «برای همیشه ساکت» بدل می‌شود —
 *    یعنی ابزارِ دیدنِ خرابی، خودش به ابزارِ پنهان کردنِ خرابی تبدیل
 *    می‌شود، و این بدترین حالتِ ممکن است چون هیچ خطایی هم نمی‌دهد.
 */

// ---------- نگهبان: فقط خط فرمان ----------
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

T::group('رسیدگی به خطا — ثبت، برطرف کردن، برگشت');

if (!file_exists(__DIR__ . '/../config/config.php')) {
    T::blocked('تستِ رسیدگی به خطا', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::blocked('تستِ رسیدگی به خطا', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

if (!tableExists('app_errors')) {
    T::blocked('تستِ رسیدگی به خطا', 'migration_app_errors اجرا نشده');
    exit(T::report());
}
if (!AppErrors::triageAvailable()) {
    T::blocked('تستِ رسیدگی به خطا', 'migration_error_triage اجرا نشده');
    exit(T::report());
}

// ---------------------------------------------------------------
// fixture
// ---------------------------------------------------------------
/**
 * ⚠ ردیف‌های آزمایشی با یک نشانه‌ی **حرفی** شناخته می‌شوند، نه hex:
 *   `AppErrors::scrub()` هر دنباله‌ی چهاررقمی به بالا را می‌پوشاند و یک
 *   نشانه‌ی شانسیِ رقم‌دار خودش شسته می‌شد — همان دامی که یک بار در
 *   `test_logging` افتادیم.
 */
$MARK = 'errprobe' . substr(str_shuffle('abcdefghijklmnopqrstuvwxyz'), 0, 8);

$wipe = static function () use ($pdo, $MARK): void {
    $pdo->prepare('DELETE FROM app_errors WHERE message LIKE :m')->execute(['m' => '%' . $MARK . '%']);
};
$wipe();
register_shutdown_function($wipe);

/** شمارشِ سطرِ همین نشانه — برای اینکه خطاهای واقعیِ دیتابیسِ توسعه دخالت نکنند. */
$mine = static function (string $filter) use ($MARK): array {
    return array_values(array_filter(
        AppErrors::browse($filter, 500),
        static fn($r) => str_contains((string)$r['message'], $MARK)
    ));
};

/** `record()` سقفِ ۵ ثبت در هر درخواست دارد؛ اینجا پروسه یکی است. */
$resetCap = static function (): void {
    $p = new ReflectionProperty(AppErrors::class, 'recorded');
    PHP_VERSION_ID < 80100 && $p->setAccessible(true);
    $p->setValue(null, 0);
};

// =====================================================================
// ۱. ثبت و «برطرف شد»
// =====================================================================
$resetCap();
T::ok(AppErrors::record('error', "first {$MARK} boom", '/x/includes/probe.php', 11), 'خطای آزمایشی ثبت شد');
$open0 = $mine('open');
T::same(1, count($open0), 'و در فهرستِ «باز» دیده می‌شود');
$id = (int)$open0[0]['id'];
T::ok($id > 0, 'ردیف شناسه دارد (دکمه‌ی رسیدگی به آن بند است)');
T::same(null, $open0[0]['resolved_at'], 'خطای تازه رسیدگی‌نشده است');

$before = AppErrors::openCount();
T::ok(AppErrors::resolve($id), '«برطرف شد» انجام شد');
T::same($before - 1, AppErrors::openCount(), 'شمارشِ بازها یکی کم شد');
T::same(0, count($mine('open')), 'از فهرستِ «باز» بیرون رفت');
T::same(1, count($mine('resolved')), 'و در «رسیدگی‌شده» است');
T::same(1, count($mine('all')), '⛔ ولی حذف نشد — «برطرف شد» با «پاک کردن» یکی نیست');

/**
 * ⚠ مهرِ اولِ رسیدگی عمداً به گذشته برده می‌شود: بدونِ آن هر دو
 *   فراخوان در یک ثانیه می‌افتادند، `NOW()` عینِ همان مقدار را
 *   می‌داد و `rowCount()` صفر می‌شد — پس بررسی حتی با برداشتنِ کاملِ
 *   نگهبان هم سبز می‌ماند. **جهش نشانش داد**، همان دامی که یک بار سرِ
 *   `BACKUP_AFTER_DAYS` هم افتادیم.
 */
$pdo->prepare('UPDATE app_errors SET resolved_at = DATE_SUB(NOW(), INTERVAL 3 DAY) WHERE id = :i')
    ->execute(['i' => $id]);
$stampBefore = (string)$pdo->query('SELECT resolved_at FROM app_errors WHERE id = ' . $id)->fetchColumn();
T::ok(!AppErrors::resolve($id), 'رسیدگیِ دوباره روی همان ردیف چیزی عوض نمی‌کند');
T::same($stampBefore, (string)$pdo->query('SELECT resolved_at FROM app_errors WHERE id = ' . $id)->fetchColumn(),
    '⛔ و تاریخِ رسیدگیِ اول جابه‌جا نمی‌شود (وگرنه ساعتِ هرس هم عقب می‌رفت)');

// =====================================================================
// ۲. ⛔ رخدادِ دوباره «برطرف شد» را پس می‌گیرد
// =====================================================================
/**
 * ⛔ قلبِ این قابلیت. اگر این بررسی قرمز شود یعنی مالکِ نصب می‌تواند یک
 *    خطای **زنده** را برای همیشه ساکت کند و هیچ‌وقت نفهمد — نه خطایی، نه
 *    نشانی، فقط یک اپِ خراب که پنل می‌گوید سالم است.
 */
$resetCap();
AppErrors::record('error', "first {$MARK} boom", '/x/includes/probe.php', 11);
$again = $mine('open');
T::same(1, count($again), '⛔ رخدادِ دوباره خطا را به فهرستِ باز برگرداند');
T::same(null, $again[0]['resolved_at'], 'و مهرِ «برطرف شد» برداشته شد');
T::same(2, (int)$again[0]['hits'], 'شمارنده‌ی رخداد جلو رفت (ردیفِ دوم ساخته نشد)');
T::same($id, (int)$again[0]['id'], 'همان ردیف است، نه یک ردیفِ تازه');

// بازگرداندنِ دستی
T::ok(AppErrors::resolve($id), 'دوباره «برطرف شد»');
T::ok(AppErrors::reopen($id), 'و با «بازگرداندن» به باز برگشت');
T::same(1, count($mine('open')), 'واقعاً باز شد');
T::ok(!AppErrors::reopen($id), 'بازگرداندنِ چیزی که باز است کاری نمی‌کند');

// =====================================================================
// ۳. صافی‌ها و شمارش‌ها
// =====================================================================
$resetCap();
AppErrors::record('warning', "second {$MARK} hmm", '/x/includes/probe.php', 22);
AppErrors::record('fatal', "third {$MARK} dead", '/x/includes/probe.php', 33);
T::same(3, count($mine('all')), 'سه خطای متمایز ثبت شد');
T::same(3, count($mine('open')), 'هر سه باز');

$sinceAll = AppErrors::openSince(24);
$thirdId  = (int)$mine('open')[0]['id'];   // آخرین رخداد، بالای فهرست
AppErrors::resolve($thirdId);
T::same($sinceAll - 1, AppErrors::openSince(24),
    '⛔ `openSince()` فقط بازها را می‌شمارد — اعلانِ مدیر از همین می‌آید');

/**
 * ⛔ خطایی که ماه‌ها است رخ نداده نباید هر روز اعلان بدهد. بدونِ این
 *    شرط، یک خطای بازِ عمدی به یک «هشدارِ همیشگی» بدل می‌شد و آدم را
 *    عادت می‌داد اعلان‌ها را نادیده بگیرد.
 */
$pdo->prepare('UPDATE app_errors SET last_seen = DATE_SUB(NOW(), INTERVAL 40 DAY) WHERE message LIKE :m')
    ->execute(['m' => '%' . $MARK . '%']);
$sinceOld = AppErrors::openSince(24);
T::ok($sinceOld <= $sinceAll - 3, '⛔ خطای کهنه در پنجره‌ی ۲۴ ساعت شمرده نمی‌شود');
T::ok(count($mine('open')) >= 1, 'ولی همچنان در فهرستِ «باز» هست — پنهان نمی‌شود');

T::same(count($mine('open')), count(AppErrors::browse('__unknown__', 500)
    ? array_values(array_filter(AppErrors::browse('__unknown__', 500),
        static fn($r) => str_contains((string)$r['message'], $MARK))) : []),
    'صافیِ ناشناخته به «باز» برمی‌گردد، نه فهرستِ خالی');

// =====================================================================
// ۴. پاک کردن و هرس
// =====================================================================
/**
 * ⛔ «پاک کردن رسیدگی‌شده‌ها» فقط چیزی را می‌برد که مالکِ نصب یک بار
 *    دیده و بسته است. جایگزینِ «پاک کردن فهرست» است، که خطاهای
 *    **دیده‌نشده** را هم می‌برد.
 */
$openBefore = count($mine('open'));
$resBefore  = count($mine('resolved'));
T::ok($resBefore >= 1, 'دست‌کم یک رسیدگی‌شده برای پاک کردن هست');
AppErrors::purgeResolved();
T::same(0, count($mine('resolved')), 'رسیدگی‌شده‌ها پاک شدند');
T::same($openBefore, count($mine('open')), '⛔ و بازها دست‌نخورده ماندند');

// هرسِ خودکار: فقط رسیدگی‌شده‌ی کهنه
$rows = $mine('open');
$keepOpenId = (int)$rows[0]['id'];
AppErrors::resolve($keepOpenId);
$pdo->prepare('UPDATE app_errors SET resolved_at = DATE_SUB(NOW(), INTERVAL :d DAY) WHERE id = :i')
    ->execute(['d' => AppErrors::KEEP_DAYS + 10, 'i' => $keepOpenId]);
$stillOpen = count($mine('open'));
AppErrors::prune();
T::same(0, count($mine('resolved')), 'رسیدگی‌شده‌ی کهنه‌تر از KEEP_DAYS هرس شد');
T::same($stillOpen, count($mine('open')), '⛔ خطای باز هرگز خودبه‌خود هرس نمی‌شود');

// رسیدگی‌شده‌ی تازه نباید هرس شود
$resetCap();
AppErrors::record('error', "fourth {$MARK} fresh", '/x/includes/probe.php', 44);
$freshId = (int)$mine('open')[0]['id'];
AppErrors::resolve($freshId);
AppErrors::prune();
T::same(1, count($mine('resolved')), 'رسیدگی‌شده‌ی تازه دست‌نخورده ماند');

T::same(90, AppErrors::KEEP_DAYS, 'مقدارِ KEEP_DAYS همان است که مستند شده');
T::same(['open', 'resolved', 'all'], array_keys(AppErrors::FILTERS), 'فهرستِ بسته‌ی صافی‌ها');

// =====================================================================
// ۵. اعلانِ مدیر
// =====================================================================
T::group('اعلانِ خطا فقط برای مدیر');

if (!Notify::available()) {
    T::skip('اعلانِ خطا', 'migration_notifications اجرا نشده');
} else {
    $wipe();
    $resetCap();
    AppErrors::record('fatal', "notify {$MARK} boom", '/x/includes/probe.php', 55);

    $U = '__err_probe_admin';
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $U]);
    if ($old = $st->fetchColumn()) { deleteUserAccount((int)$old); }
    $acc = createUserAccount($pdo, 'مدیر آزمایشی', $U, '', 'ErrProbe12345', 'admin');
    T::ok($acc['ok'] ?? false, 'کاربرِ آزمایشی ساخته شد');
    $uid = (int)($acc['id'] ?? 0);

    $notifOf = static function (int $uid) use ($pdo): int {
        $st = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :u AND kind = 'apperr'");
        $st->execute(['u' => $uid]);
        return (int)$st->fetchColumn();
    };

    // کاربر عادی: هیچ اعلانی نمی‌گیرد
    $_SESSION = ['role' => 'user'];
    Notify::generateFor($uid, true);
    T::same(0, $notifOf($uid), '⛔ کاربر عادی اعلانِ خطای برنامه نمی‌گیرد');

    // مدیر: یکی می‌گیرد
    $_SESSION = ['role' => 'admin'];
    Notify::generateFor($uid, true);
    T::same(1, $notifOf($uid), 'مدیر اعلان گرفت');

    // ⛔ و فقط یکی در روز — وگرنه یک روزِ بد ده‌ها اعلان می‌ساخت.
    $_SESSION = ['role' => 'admin'];
    Notify::generateFor($uid, true);
    Notify::generateFor($uid, true);
    T::same(1, $notifOf($uid), '⛔ اجرای دوباره اعلانِ تکراری نمی‌سازد (dedup روزانه)');

    /**
     * ⛔ و با **عوض شدنِ تعداد** هم اعلانِ دوم ساخته نمی‌شود.
     *
     * وسوسه‌ی طبیعی این است که عدد داخلِ `dedup_key` برود («۳ خطا» با
     * «۵ خطا» یکی نیست)، ولی آن‌وقت یک روزِ بد ده اعلان می‌ساخت —
     * دقیقاً همان شلوغی‌ای که مالکِ نصب از آن شکایت کرد.
     */
    $resetCap();
    AppErrors::record('error', "notify2 {$MARK} more", '/x/includes/probe.php', 66);
    $_SESSION = ['role' => 'admin'];
    Notify::generateFor($uid, true);
    T::same(1, $notifOf($uid), '⛔ با زیاد شدنِ تعدادِ خطا هم اعلانِ دوم ساخته نمی‌شود');

    $row = $pdo->prepare("SELECT * FROM notifications WHERE user_id = :u AND kind = 'apperr' LIMIT 1");
    $row->execute(['u' => $uid]);
    $n = $row->fetch();
    T::same('admin/errors.php', (string)($n['link'] ?? ''), 'اعلان به صفحه‌ی خطاها لینک می‌دهد');
    T::ok(!str_contains((string)$n['body'], $MARK), '⛔ متنِ خطا داخلِ اعلان نمی‌رود');

    // با نبودِ خطای بازِ تازه، اعلانی ساخته نمی‌شود.
    //
    // ⚠ دیتابیسِ توسعه ممکن است خطاهای **واقعیِ** بازِ خودش را داشته
    //   باشد، پس پاک کردنِ ردیف‌های آزمایشی کافی نیست. به‌جای حذفِ
    //   داده‌ی واقعی، تاریخشان موقتاً عقب برده و بعد **دقیقاً**
    //   برگردانده می‌شود — وگرنه این بررسی یا الکی قرمز می‌شد یا
    //   باید کنار گذاشته می‌شد، و «بررسیِ کنارگذاشته‌شده» همان
    //   سبزِ دروغین است.
    $pdo->prepare("DELETE FROM notifications WHERE user_id = :u")->execute(['u' => $uid]);
    $wipe();
    $snap = $pdo->query('SELECT id, last_seen FROM app_errors
                          WHERE resolved_at IS NULL AND last_seen >= DATE_SUB(NOW(), INTERVAL 24 HOUR)')->fetchAll();
    $shift = $pdo->prepare('UPDATE app_errors SET last_seen = DATE_SUB(NOW(), INTERVAL 40 DAY) WHERE id = :i');
    foreach ($snap as $r) { $shift->execute(['i' => (int)$r['id']]); }
    try {
        T::same(0, AppErrors::openSince(24), 'پنجره‌ی ۲۴ ساعت واقعاً خالی شد');
        $_SESSION = ['role' => 'admin'];
        Notify::generateFor($uid, true);
        T::same(0, $notifOf($uid), 'بدونِ خطای بازِ تازه، اعلانی ساخته نمی‌شود');
    } finally {
        $back = $pdo->prepare('UPDATE app_errors SET last_seen = :t WHERE id = :i');
        foreach ($snap as $r) { $back->execute(['t' => $r['last_seen'], 'i' => (int)$r['id']]); }
    }

    deleteUserAccount($uid);
}

// =====================================================================
// ۳. `@` یعنی «انتظار می‌رود» — ثبت نمی‌شود
// =====================================================================
/**
 * **گزارشِ مالکِ نصب (با اسکرین‌شات):** یک «خطای» باز برای
 * `@file_get_contents(var/apk-meta.json)` — کشی که بارِ اول نیست و کد
 * همان را پیش‌بینی کرده بود. گیرنده‌ی سراسری `@` را نادیده می‌گرفت.
 *
 * ⚠ هر دو نیمه لازم است: با فقط نیمه‌ی اول، جهشِ «گیرنده هیچ هشداری
 *   ثبت نکند» هم سبز می‌ماند.
 */
T::group('خطای خاموش‌شده با @ ثبت نمی‌شود');
$resetCap();
$missing = '/nonexistent-dir/' . $MARK . '-quiet.json';
$r = @file_get_contents($missing);
T::same(false, $r, 'خواندنِ فایلِ ناموجود شکست خورد (پیش‌شرطِ آزمون)');
T::same(0, count(array_filter($mine('all'), static fn($x) => str_contains((string)$x['message'], '-quiet.json'))),
    '⛔ هشدارِ خاموش‌شده با @ هیچ ردیفی نساخت');

$resetCap();
$loud = '/nonexistent-dir/' . $MARK . '-loud.json';
$prevDisplay = ini_set('display_errors', '0');
$prevLog = ini_set('log_errors', '0');
$r = file_get_contents($loud);
ini_set('display_errors', (string)$prevDisplay);
ini_set('log_errors', (string)$prevLog);
T::same(1, count(array_filter($mine('all'), static fn($x) => str_contains((string)$x['message'], '-loud.json'))),
    'ولی همان هشدار بدونِ @ هنوز ثبت می‌شود (گیرنده خاموش نشده)');

exit(T::report());
