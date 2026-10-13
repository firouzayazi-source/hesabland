<?php
/**
 * reminders.php — خلاصه‌ی روزانه‌ی سررسیدها با ایمیل
 *
 * ⛔ چرا این مهم‌ترین چیزی بود که اپ نداشت: هسته‌ی برنامه سررسید است —
 *    چک، طلب و بدهی، تراکنش دوره‌ای. ولی اپ فقط وقتی حرف می‌زد که
 *    کاربر خودش بازش کند، یعنی دقیقاً همان لحظه‌ای که دیگر یادآوری لازم
 *    ندارد. چکِ برگشتی عارضه‌ی حقوقی دارد و بدهیِ دیرکرد رابطه‌ی
 *    خانوادگی را خراب می‌کند؛ اپی که برایش پول می‌دهند پیش از سررسید
 *    خبر می‌دهد.
 *
 * ⚠ عمداً ایمیل است نه Web Push: سرویسِ پوشِ کروم از ایران پایدار نیست
 *   و روی iOS فقط در حالت نصب‌شده کار می‌کند. ایمیل هیچ وابستگیِ
 *   بیرونی ندارد (Mailer خودمان با SMTP) و همیشه می‌رسد.
 *
 * ─── رعایت قانون جداسازی ───────────────────────────────────────────
 * فقط از دیتابیس همین پروژه می‌خواند و فقط ایمیل می‌فرستد. هیچ فایلی
 * بیرون از پوشه‌ی اپ نمی‌نویسد؛ cron را هم فقط با --install-cron و در
 * /etc/cron.d/hesab-reminders می‌سازد.
 * ───────────────────────────────────────────────────────────────────
 *
 * اجرا:
 *     php deploy/reminders.php              # نمایشی — فقط بگو چه می‌فرستادی
 *     php deploy/reminders.php --send       # واقعاً بفرست
 *     php deploy/reminders.php --user ali   # فقط همین کاربر (برای آزمودن)
 *     sudo php deploy/reminders.php --install-cron
 */

// ---------- نگهبان: فقط خط فرمان ----------
// این فایل داخل ریشه‌ی وب است. بدون این نگهبان، باز کردن آدرسش در
// مرورگر به هر کسی اجازه می‌داد به همه‌ی کاربران ایمیل بفرستد.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/cron_health.php';

$args    = array_slice($argv, 1);
$send    = in_array('--send', $args, true);
$cronIns = in_array('--install-cron', $args, true);
$onlyUser = '';
foreach ($args as $i => $a) {
    if ($a === '--user' && isset($args[$i + 1])) { $onlyUser = $args[$i + 1]; }
}

$c = fn(string $code, string $s): string => "\033[{$code}m{$s}\033[0m";
$green = fn($s) => $c('0;32', $s);
$red   = fn($s) => $c('0;31', $s);
$info  = fn($s) => $c('0;36', $s);
$warn  = fn($s) => $c('0;33', $s);

// ---------- نصب cron ----------
if ($cronIns) {
    if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
        fwrite(STDERR, $red('برای نصب cron باید با sudo اجرا شود.') . "\n");
        exit(1);
    }
    $appDir = dirname(__DIR__);
    $cronApp = defined('APP_NAME') ? APP_NAME : 'حساب لند';
    $cron = <<<CRON
# یادآوری روزانه‌ی سررسیدهای {$cronApp} — فقط مربوط به همین پروژه
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
30 7 * * * root php {$appDir}/deploy/reminders.php --send >/dev/null 2>&1

CRON;
    if (@file_put_contents('/etc/cron.d/hesab-reminders', $cron) === false) {
        fwrite(STDERR, $red('نوشتن /etc/cron.d/hesab-reminders ممکن نشد.') . "\n");
        exit(1);
    }
    @chmod('/etc/cron.d/hesab-reminders', 0644);
    echo $green('زمان‌بندی شد: هر روز ساعت ۷:۳۰ صبح.') . "\n";
    echo $info('فایل: /etc/cron.d/hesab-reminders') . "\n";
    echo $info('برای لغو:  sudo rm /etc/cron.d/hesab-reminders') . "\n";
    exit(0);
}

// ---------- اتصال ----------
try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    fwrite(STDERR, $red('اتصال به دیتابیس برقرار نشد.') . "\n");
    exit(1);
}

if (!tableExists('notification_prefs')) {
    fwrite(STDERR, $red('جدول notification_prefs نیست. روی سرور:  bash deploy/migrate.sh --apply') . "\n");
    exit(1);
}

if ($send && !Mailer::isConfigured()) {
    fwrite(STDERR, $red('ارسال ایمیل تنظیم نشده است (MAIL_METHOD در config.php).') . "\n");
    fwrite(STDERR, $info('راهنما:  bash deploy/mail-setup.sh') . "\n");
    exit(1);
}

// ---------- کاربران ----------
// فقط کسانی که ایمیل دارند. ردیفِ نبودن در notification_prefs یعنی
// «هنوز تصمیم نگرفته» و پیش‌فرضِ روشن می‌گیرد — وگرنه این قابلیت برای
// همه‌ی کاربرانِ موجود خاموش می‌ماند و هیچ‌کس هرگز روشنش نمی‌کرد.
$sql = "SELECT u.id, u.full_name, u.username, u.email,
               COALESCE(p.email_on, 1)    AS email_on,
               COALESCE(p.days_before, 3) AS days_before,
               p.last_sent_on
        FROM users u
        LEFT JOIN notification_prefs p ON p.user_id = u.id
        WHERE u.is_active = 1 AND u.email IS NOT NULL AND u.email <> ''";
$params = [];
if ($onlyUser !== '') {
    $sql .= ' AND u.username = :un';
    $params['un'] = $onlyUser;
}
$st = $pdo->prepare($sql);
$st->execute($params);
$users = $st->fetchAll();

if (!$users) {
    echo $warn('هیچ کاربرِ فعالی با ایمیل پیدا نشد.') . "\n";
    exit(0);
}

$today = today();
$sent = 0; $skipped = 0; $failed = 0;

echo "\n" . $info('یادآوری سررسید' . ($send ? '' : '  (حالت نمایشی)')) . "\n\n";

foreach ($users as $u) {
    $uid = (int)$u['id'];
    $name = $u['full_name'] ?: $u['username'];

    if (!$u['email_on']) {
        echo "  - {$name}: یادآوری خاموش است\n";
        $skipped++;
        continue;
    }

    // ⛔ یک ایمیل در روز، حتی اگر cron دو بار اجرا شود یا کسی دستی
    //    صدایش بزند. بدون این، یک اشتباهِ زمان‌بندی به هر کاربر چند
    //    ایمیل می‌فرستاد — سریع‌ترین راهِ اینکه کاربر یادآوری را
    //    خاموش کند یا آدرس را اسپم علامت بزند.
    if ($send && $u['last_sent_on'] === $today && $onlyUser === '') {
        echo "  - {$name}: امروز فرستاده شده\n";
        $skipped++;
        continue;
    }

    $days = max(0, min(60, (int)$u['days_before']));
    $to   = date('Y-m-d', strtotime("+{$days} days"));
    // از ۹۰ روز قبل، تا سررسیدهای گذشته هم بیایند — همان بازه‌ای که
    // upcoming.php و safeToSpend() می‌گیرند.
    $from = date('Y-m-d', strtotime('-90 days'));

    try {
        // ⚠ یادآورهای شخصی هم در همان ایمیل می‌آیند — کاربر یک ایمیل
        //   در روز می‌گیرد، نه دو تا. (`last_sent_on` هم همان یکی را
        //   نگهبانی می‌کند.)
        $events = array_merge(
            financialEvents($uid, $from, $to),
            customReminderEvents($uid, $from, $to)
        );
        usort($events, fn($a, $b) => strcmp($a['date'], $b['date']));
    } catch (Throwable $e) {
        echo '  ' . $red("× {$name}: خطا در خواندن رویدادها") . "\n";
        $failed++;
        continue;
    }

    $overdue = [];
    $soon    = [];
    foreach ($events as $e) {
        if ($e['date'] < $today) { $overdue[] = $e; }
        else                     { $soon[] = $e; }
    }

    if (!$overdue && !$soon) {
        echo "  - {$name}: سررسیدی نیست\n";
        $skipped++;
        continue;
    }

    $summary = [];
    if ($overdue) { $summary[] = toPersianDigits(count($overdue)) . ' سررسیدگذشته'; }
    if ($soon)    { $summary[] = toPersianDigits(count($soon)) . ' تا ' . toPersianDigits($days) . ' روز آینده'; }
    // ⚠ نامِ برند از `APP_NAME` می‌آید، نه سخت‌کد. این تنها متنی است که
    //   **بیرون از اپ** به دستِ کاربر می‌رسد (موضوعِ ایمیل)، پس روی
    //   نصبی که برندش عوض شده، سخت‌کد بودنش یعنی ایمیل‌ها با نامِ ما
    //   می‌روند — و کسی هم متوجه نمی‌شود، چون خروجیِ این اسکریپت را
    //   هیچ‌کس نمی‌بیند (با cron اجرا می‌شود).
    $appName = defined('APP_NAME') ? APP_NAME : 'حساب لند';
    $subject = $appName . ' — ' . implode(' و ', $summary);

    [$html, $text] = reminderEmailBody($name, $overdue, $soon, $days);

    if (!$send) {
        echo '  ' . $info("→ {$name} <{$u['email']}>") . "  {$subject}\n";
        $sent++;
        continue;
    }

    if (Mailer::send($u['email'], $subject, $html, $text)) {
        $pdo->prepare(
            'INSERT INTO notification_prefs (user_id, last_sent_on) VALUES (:u, :d)
             ON DUPLICATE KEY UPDATE last_sent_on = VALUES(last_sent_on)'
        )->execute(['u' => $uid, 'd' => $today]);
        echo '  ' . $green("✓ {$name} <{$u['email']}>") . "  {$subject}\n";
        $sent++;
    } else {
        echo '  ' . $red("× {$name}: " . Mailer::$lastError) . "\n";
        $failed++;
    }
}

echo "\n";
printf("  فرستاده: %d    رد شده: %d    ناموفق: %d\n\n", $sent, $skipped, $failed);
if (!$send) {
    echo $warn('حالت نمایشی — چیزی فرستاده نشد.') . "\n";
    echo $info('برای ارسال واقعی:  php deploy/reminders.php --send') . "\n\n";
}

// ⛔ نشانه فقط در حالتِ واقعی ثبت می‌شود: اجرای نمایشی هیچ ایمیلی
//    نمی‌فرستد، پس ثبتش یعنی «سالم» گفتن به کاری که انجام نشده.
if ($send) {
    CronHealth::beat('reminders');
    Log::info('job.done', ['job' => 'reminders', 'sent' => $sent, 'skipped' => $skipped,
        'failed' => $failed, 'ms' => Log::elapsedMs()]);
}

exit($failed > 0 ? 1 : 0);
