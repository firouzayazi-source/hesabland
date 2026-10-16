<?php
/**
 * ssl-check.php — پایشِ گواهیِ SSLِ سایت (هر ۶ ساعت با cron).
 *
 * ─── رعایت قانون جداسازی ───────────────────────────────────────────
 * فقط `/etc/cron.d/hesab-ssl` را می‌نویسد (با --install-cron) و فقط
 * `var/ssl-state.json`، دیتابیسِ همین اپ و ایمیلِ مدیران. به گواهی، certbot
 * و nginx دست نمی‌زند — فقط به همین سرور با SNIِ دامنه‌ی خودش وصل می‌شود و
 * گواهیِ تحویل‌شده را می‌خواند. (رفع: `sudo ./hesabland ssl`.)
 * ───────────────────────────────────────────────────────────────────
 *
 * اجرا:
 *     php deploy/ssl-check.php                 # فقط وضعیت (بی‌هشدار، بی‌نوشتن)
 *     php deploy/ssl-check.php --run           # کارِ cron: ثبت و هشدار
 *     sudo php deploy/ssl-check.php --install-cron
 *
 * کدِ خروج: ۰ سالم، ۱ نزدیکِ انقضا، ۲ خراب.
 * (`--host`، `--port` و متغیرِ `HESAB_SSL_CAFILE` فقط برای آزمودن.)
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/ssl_watch.php';
require_once __DIR__ . '/../includes/cron_health.php';

$args   = array_slice($argv, 1);
$run    = in_array('--run', $args, true);
$appDir = dirname(__DIR__);
$opt = function (string $name) use ($args): string {
    $i = array_search($name, $args, true);
    return $i !== false && isset($args[$i + 1]) ? (string)$args[$i + 1] : '';
};

if (in_array('--install-cron', $args, true)) {
    if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
        fwrite(STDERR, "برای نصب cron باید با sudo اجرا شود.\n");
        exit(1);
    }
    $line = "23 */6 * * * root cd {$appDir} && /usr/bin/php deploy/ssl-check.php --run >> /var/log/hesab-ssl.log 2>&1\n";
    if (@file_put_contents('/etc/cron.d/hesab-ssl', "# پایشِ گواهیِ SSL — هر ۶ ساعت\n" . $line) === false) {
        fwrite(STDERR, "نوشتنِ /etc/cron.d/hesab-ssl ممکن نشد.\n");
        exit(1);
    }
    @chmod('/etc/cron.d/hesab-ssl', 0644);
    echo "زمان‌بندیِ هر ۶ ساعت نصب شد: /etc/cron.d/hesab-ssl\n";
    echo "برای لغو:  sudo rm /etc/cron.d/hesab-ssl\n";
    exit(0);
}

$host = $opt('--host');
if ($host === '') {
    $host = (string)parse_url(defined('APP_URL') ? APP_URL : '', PHP_URL_HOST);
}
if ($host === '' || $host === 'example.com') {
    fwrite(STDERR, "دامنه‌ی سایت از APP_URL خوانده نشد (config/config.php).\n");
    exit(2);
}
$port  = (int)($opt('--port') ?: 443);
$probe = SslWatch::probe($host, '127.0.0.1', $port, 8, (string)getenv('HESAB_SSL_CAFILE'));

if ($run) {
    $res = SslWatch::run($host, $probe);
    CronHealth::beat('ssl');
    echo date('Y-m-d H:i') . " {$host} {$res['state']} " . ($res['days'] ?? '-') . " روز؛ ایمیل {$res['sent']}، اعلان {$res['notified']}\n";
} else {
    $res = SslWatch::assess($probe, $host, time());
}

$icon = ['ok' => '✓', 'warn' => '⚠', 'critical' => '⛔'][$res['state']];
echo "{$icon} {$host}: " . $res['reason'] . "\n";
if ($res['valid_to']) { echo '  انقضا: ' . toJalali(date('Y-m-d', (int)$res['valid_to'])) . ($probe['issuer'] ? '  ·  صادرکننده: ' . $probe['issuer'] : '') . "\n"; }
if ($res['state'] !== 'ok') { echo "  رفع:  cd /opt/hesab/app && " . SslWatch::FIX_CMD . "\n"; }

exit(['ok' => 0, 'warn' => 1, 'critical' => 2][$res['state']]);
