<?php
/**
 * rates.php — نرخِ روزِ ارز، طلا و سکه (هر ۶ ساعت با cron).
 *
 * ─── رعایت قانون جداسازی ───────────────────────────────────────────
 * فقط `/etc/cron.d/hesab-rates` را می‌نویسد (با --install-cron)، و فقط به
 * دیتابیسِ همین اپ دست می‌زند. درخواستِ بیرونی فقط به منبع‌هایی است که
 * مدیر در «پنلِ مدیر ← نرخ ارز و طلا» روشن کرده (`Rates::PROVIDERS`)، از
 * پشتِ سدِ SSRF (`SafeFetch`).
 * ───────────────────────────────────────────────────────────────────
 *
 * اجرا:
 *     php deploy/rates.php                    # وضعیت: نرخ‌ها و منبع‌ها
 *     php deploy/rates.php --run              # دریافتِ واقعی (کارِ cron)
 *     php deploy/rates.php --probe            # هر منبع را جدا می‌آزماید و پاسخِ خامش را نشان می‌دهد
 *     php deploy/rates.php --probe brsapi     # فقط یک منبع
 *     sudo php deploy/rates.php --install-cron
 *
 * ⛔ `--probe` چیزی نمی‌نویسد. برای همین است: سرورِ خارج از ایران ممکن است
 *    از منبع‌های ایرانی جواب نگیرد، و این تنها راهِ دیدنِ «کدام جواب می‌دهد،
 *    با چه عددی» پیش از روشن کردنش است.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/rates.php';
// ⛔ لایه‌ی فروشگاه خودش را با `Rates::listen()` ثبت می‌کند، تا نرخِ تازه به
//    قیمتِ فروشِ کالاهای ارزی هم برسد.
require_once __DIR__ . '/../includes/biz_rates.php';
require_once __DIR__ . '/../includes/cron_health.php';

$args   = array_slice($argv, 1);
$appDir = dirname(__DIR__);

if (in_array('--install-cron', $args, true)) {
    // دقیقه‌ی ۷ — نه سرِ ساعت، که همه‌ی cronها همان‌جا می‌زنند.
    $line = "7 */6 * * * root cd {$appDir} && /usr/bin/php deploy/rates.php --run >> /var/log/hesab-rates.log 2>&1\n";
    file_put_contents('/etc/cron.d/hesab-rates', "# نرخِ ارز، طلا و سکه — هر ۶ ساعت\n" . $line);
    @chmod('/etc/cron.d/hesab-rates', 0644);
    echo "زمان‌بندیِ هر ۶ ساعت نصب شد: /etc/cron.d/hesab-rates\n";
    echo "برای لغو:  sudo rm /etc/cron.d/hesab-rates\n";
    exit(0);
}

if (!Rates::available()) {
    if (in_array('--run', $args, true)) { CronHealth::beat('rates'); exit(0); }
    echo "جدولِ نرخ‌ها نیست؛ اول:  sudo ./hesabland --migrate\n";
    exit(1);
}

$fmt = fn(?int $v): string => $v === null ? '—' : number_format($v);

if (in_array('--run', $args, true)) {
    $res = Rates::refresh();
    $line = date('Y-m-d H:i') . ' نوشته: ' . count($res['written']) . ' · رد: ' . count($res['rejected']);
    foreach ($res['report'] as $p => $msg) { $line .= ' · ' . $p . ': ' . $msg; }
    echo $line . "\n";
    foreach ($res['rejected'] as $code => $why) { echo "  رد شد {$code}: {$why}\n"; }
    // ⛔ نشانه فقط وقتی دستِ کم یک نرخ نوشته شد — وگرنه «سبز» دروغ بود.
    if ($res['written']) { CronHealth::beat('rates'); }
    exit(0);
}

$pi = array_search('--probe', $args, true);
if ($pi !== false) {
    $only = $args[$pi + 1] ?? null;
    foreach (Rates::sources() as $p => $src) {
        if ($only !== null && $only !== $p) { continue; }
        echo "\n━━ {$src['label']} ({$p}) — " . ($src['enabled'] ? 'روشن' : 'خاموش') . ', کلید: ' . ($src['has_key'] ? 'دارد' : 'ندارد') . "\n";
        $r = Rates::fetchOne($p, $src);
        if (!$r['ok']) {
            echo "  ✗ {$r['error']}\n";
        } else {
            echo '  ✓ از ' . preg_replace('~(key=)[^&]+~i', '$1••••', (string)$r['url']) . "\n";
            foreach ($r['rates'] as $code => $v) {
                $code = ltrim($code, '@');
                printf("    %-14s %16s تومان  (%s)\n", $code, $fmt($v), Rates::label($code));
            }
        }
        $body = trim((string)($r['body'] ?? ''));
        if ($body !== '') {
            echo "  پاسخِ خام (۴۰۰ نویسه‌ی اول):\n    " . mb_substr(preg_replace('~\s+~', ' ', $body), 0, 400) . "\n";
        }
    }
    echo "\nچیزی نوشته نشد. برای دریافتِ واقعی:  php deploy/rates.php --run\n";
    exit(0);
}

echo "نرخ‌ها:\n";
$rates = Rates::all();
foreach (Rates::CODES as $code => [$label, $unit]) {
    $r = $rates[$code] ?? null;
    printf("  %-14s %16s  %-28s %s\n", $code, $fmt($r ? (int)$r['price'] : null),
        $r ? $r['source'] . ($r['manual'] ? ' (دستی)' : '') : '', $r ? Rates::ago($r['fetched_at']) . (Rates::isStale($r) ? ' — کهنه' : '') : '');
}
echo "\nمنبع‌ها (به ترتیبِ اولویت):\n";
foreach (Rates::sources() as $p => $src) {
    printf("  %-8s %-6s کلید:%-4s آخرین موفق: %-14s %s\n", $p, $src['enabled'] ? 'روشن' : 'خاموش',
        $src['has_key'] ? 'دارد' : 'ندارد', Rates::ago($src['last_ok_at']), $src['last_error'] ? '— ' . $src['last_error'] : '');
}
