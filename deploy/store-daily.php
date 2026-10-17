<?php
/**
 * store-daily.php — خلاصه‌ی شبانه برای صاحبِ فروشگاه (هر ساعت با cron).
 *
 * ⛔ cron ساعتی است و خودِ `BizDaily::run()` از ساعتِ `BizDaily::HOUR` (به وقتِ اپ)
 *    می‌فرستد و هر فروشگاه را روزی یک بار (`claim()`) — پس منطقه‌ی زمانیِ سرور
 *    هرگز «خلاصه‌ی دیروز ساعتِ یکِ بامداد» نمی‌سازد.
 *
 * ─── رعایت قانون جداسازی ───────────────────────────────────────────
 * فقط `/etc/cron.d/hesab-store-daily` را می‌نویسد (با --install-cron)، و فقط به
 * دیتابیسِ همین اپ دست می‌زند. بیرون فقط ایمیل (Mailer) و اعلان (جدولِ خودمان).
 * ───────────────────────────────────────────────────────────────────
 *
 * اجرا:
 *     php deploy/store-daily.php                 # نمایشی — چه کسی چه می‌گرفت
 *     php deploy/store-daily.php --send          # کارِ cron
 *     php deploy/store-daily.php --shop 12       # فقط یک فروشگاه (نمایشی)
 *     sudo php deploy/store-daily.php --install-cron
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/biz_daily.php';
require_once __DIR__ . '/../includes/cron_health.php';

$args = array_slice($argv, 1);
$send = in_array('--send', $args, true);
$shop = null;
foreach ($args as $i => $a) {
    if ($a === '--shop' && isset($args[$i + 1])) { $shop = (int)$args[$i + 1]; }
}

if (in_array('--install-cron', $args, true)) {
    if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
        fwrite(STDERR, "برای نصب cron باید با sudo اجرا شود.\n");
        exit(1);
    }
    $appDir = dirname(__DIR__);
    $cron = "# خلاصه‌ی شبانه‌ی فروشگاه — فقط مربوط به همین پروژه\n"
          . "SHELL=/bin/bash\nPATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin\n"
          . "7 * * * * root php {$appDir}/deploy/store-daily.php --send >/dev/null 2>&1\n";
    if (@file_put_contents('/etc/cron.d/hesab-store-daily', $cron) === false) {
        fwrite(STDERR, "نوشتن /etc/cron.d/hesab-store-daily ممکن نشد.\n");
        exit(1);
    }
    @chmod('/etc/cron.d/hesab-store-daily', 0644);
    echo "زمان‌بندی شد: هر ساعت؛ خلاصه از ساعتِ " . BizDaily::HOUR . " شب، روزی یک بار.\n";
    echo "فایل: /etc/cron.d/hesab-store-daily\n";
    echo "برای لغو:  sudo rm /etc/cron.d/hesab-store-daily\n";
    exit(0);
}

try {
    Database::getConnection();
} catch (Throwable $e) {
    fwrite(STDERR, "اتصال به دیتابیس برقرار نشد.\n");
    exit(1);
}

if (!BizDaily::ready()) {
    if ($send) { CronHealth::beat('store-daily'); exit(0); }
    echo "ستونِ خلاصه‌ی شبانه نیست. روی سرور:  bash deploy/migrate.sh --apply\n";
    exit(0);
}

// ⚠ با --shop حتی --send هم ساعت را نمی‌پرسد (آزمودنِ دستی)، ولی claim سرِ جایش است
$r = BizDaily::run($send, $shop);
foreach ($r['log'] as $l) { echo "  {$l}\n"; }
echo ($send ? 'فرستاده' : 'می‌رفت') . ": {$r['sent']} · روزِ خالی: {$r['empty']} · رد: {$r['skipped']} · خطا: {$r['failed']}\n";
if ($send && $r['failed'] === 0) { CronHealth::beat('store-daily'); }
exit($r['failed'] > 0 ? 1 : 0);
