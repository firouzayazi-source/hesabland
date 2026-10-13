<?php
/**
 * store-sync.php — هم‌گام‌سازیِ دوره‌ایِ سهمِ سهامداران با حسابداری فروشگاه
 *
 * ─── ⛔ چرا cron لازم شد ──────────────────────────────────────────
 *
 * تا امروز هم‌گام‌سازی فقط از `my-assets.php` صدا زده می‌شد، یعنی **وقتی
 * کاربر صفحه‌ی دارایی را باز می‌کرد**. `CLAUDE.md` همان موقع صریح نوشته
 * بود که این یک حد است، و «اگر روزی خواسته شد سود همان لحظه ثبت شود،
 * آن روز یک cron لازم است — نه جبران‌سازیِ دیگری». امروز همان روز است:
 *
 *     «مهمه همه این اتفاقات آنلاین باشه، یعنی هر اتفاقی در حسابداری
 *      فروشگاه افتاد همون لحظه اینجا هم آپدیت بشه — حتی اگر معامله‌ای
 *      کنسل شد، سودی که در حسابلند وارد شده پاک بشه.»
 *
 * ⛔ **و «پاک شدن» چیزِ تازه‌ای لازم نداشت.** `StoreShare::sync()` از اول
 *    یک *هم‌گام‌سازی* است نه *افزودن*: سطری که در سمتِ فروشگاه دیگر نیست
 *    از دفترِ کاربر هم حذف می‌شود. پس فاکتورِ کنسل‌شده خودبه‌خود برمی‌گردد
 *    و تنها چیزی که کم بود **زمانِ اجرا** بود.
 *
 * ─── چرا هر دقیقه، و نه وب‌هوک ───
 *
 * وب‌هوک زیرِ یک ثانیه است ولی یک اندپوینتِ نویسنده و یک توکنِ تازه روی
 * حساب لند می‌خواهد، و **باز هم cron لازم دارد**: وب‌هوکی که یک بار گم
 * شود بی‌صدا جا می‌ماند و هیچ‌کس نمی‌فهمد. این اسکریپت ایدمپوتنت است،
 * پس اجرای بعدی هر چیزی را که جا مانده باشد خودش جبران می‌کند — همان
 * قاعده‌ای که `reminder_notifications` و `store_share_ref` رویش بنا شده‌اند.
 *
 * ─── اجرا ───
 *
 *     php deploy/store-sync.php                 فقط وضعیت را می‌گوید
 *     php deploy/store-sync.php --run           واقعاً هم‌گام می‌کند
 *     php deploy/store-sync.php --install-cron  هر دقیقه زمان‌بندی می‌کند
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/store_share.php';
require_once __DIR__ . '/../includes/cron_health.php';

$args   = array_slice($argv, 1);
$run    = in_array('--run', $args, true);
$appDir = dirname(__DIR__);

if (in_array('--install-cron', $args, true)) {
    /*
     * ⚠ هر دقیقه، و خروجی به لاگِ خودش. با `>> /var/log/...` اگر روزی
     *   خطا بدهد، هر دقیقه یک خط می‌نویسد — پس فقط خطا چاپ می‌شود، نه
     *   گزارشِ موفق. (پایین‌تر: مسیرِ موفق ساکت است.)
     */
    $line = "* * * * * root cd {$appDir} && /usr/bin/php deploy/store-sync.php --run >> /var/log/hesab-store-sync.log 2>&1\n";
    file_put_contents('/etc/cron.d/hesab-store-sync', "# هم‌گام‌سازی سهم سهامداران فروشگاه — هر دقیقه\n" . $line);
    @chmod('/etc/cron.d/hesab-store-sync', 0644);
    echo "زمان‌بندیِ هر دقیقه نصب شد: /etc/cron.d/hesab-store-sync\n";
    echo "برای لغو:  sudo rm /etc/cron.d/hesab-store-sync\n";
    exit(0);
}

/*
 * ⛔ پیکربندیِ خالی یعنی قابلیت **روشن نیست**، نه اینکه خراب است.
 *    پس نشانه زده می‌شود و کد خروج صفر است — وگرنه نصبی که اصلاً
 *    فروشگاهی ندارد هر سه ساعت یک «cron کهنه» می‌گرفت، و هشدارِ الکی
 *    آدم را عادت می‌دهد هشدارها را نادیده بگیرد.
 */
if (!StoreShare::configured()) {
    if ($run) { CronHealth::beat('store-sync'); }
    if (!$run) { echo "اتصال به حسابداری فروشگاه تنظیم نشده است (STORE_API_URL / STORE_API_TOKEN).\n"; }
    exit(0);
}

if (!$run) {
    $st = StoreShare::status();
    echo "اتصال:      تنظیم شده\n";
    echo 'پیوندِ فعال: ' . StoreShare::activeCount() . ' سهامدار' . "\n";
    echo 'آخرین هم‌گام‌سازی: ' . ($st['fetched_at'] ?? '—') . "\n";
    if (!empty($st['last_error'])) {
        echo 'آخرین خطا:  ' . $st['last_error'] . "\n";
    }
    echo "\nبرای اجرای واقعی:  php deploy/store-sync.php --run\n";
    exit(0);
}

$res = StoreShare::sync();

if (!$res['ok']) {
    /*
     * ⛔ نشانه زده **نمی‌شود** — همان قاعده‌ی CronHealth: نشانه‌ای که روی
     *    خرابی هم بخورد دروغ می‌گوید. اگر کانتینرِ فروشگاه چهار ساعت
     *    بخوابد، پنل مدیر باید «کهنه» نشان بدهد، نه «سالم».
     */
    fwrite(STDERR, date('Y-m-d H:i:s') . ' — هم‌گام‌سازی انجام نشد: ' . $res['message'] . "\n");
    exit(1);
}

CronHealth::beat('store-sync');

/*
 * ⚠ مسیرِ موفق **ساکت** است مگر چیزی واقعاً عوض شده باشد. با اجرای هر
 *   دقیقه، یک خطِ «انجام شد» یعنی ۱۴۴۰ خط در روز در لاگ — که همان
 *   انبار کردنِ آشغال است و خطای واقعی را در خودش گم می‌کند.
 */
if ($res['written'] > 0 || $res['removed'] > 0) {
    Log::info('store_sync.applied', [
        'written' => $res['written'],
        'removed' => $res['removed'],
    ]);
    echo date('Y-m-d H:i:s') . " — {$res['written']} ثبت، {$res['removed']} حذف\n";
}
exit(0);
