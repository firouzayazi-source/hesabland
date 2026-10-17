<?php
/**
 * `deploy/nginx-hsts.sh` — روشن/خاموش کردنِ HSTS، با nginxِ واقعی و گواهیِ واقعی.
 *
 * ⛔ چرا nginxِ واقعی: «nginx -t سبز شد» چیزی را ثابت نمی‌کند (درسِ nginx-api.sh).
 *    این تست یک nginxِ جدا روی پورتِ بالا با گواهیِ خودامضا بالا می‌آورد و
 *    `nginx`/`systemctl` را با دو پوسته‌ی کوچک به همان نمونه وصل می‌کند؛ پس
 *    اسکریپت همان کاری را می‌کند که روی سرور — درج، `nginx -t`، reload، و سنجشِ
 *    سرآیند با curl — و ما سرآیندِ واقعیِ پاسخ را می‌خوانیم.
 *
 * ⚠ root، nginx و openssl لازم است؛ نبودشان `T::skip` است (ابزارِ اختیاری).
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';

T::group('nginx-hsts.sh — پیش‌شرط، درج، سنجش و برگشت');

$isRoot = function_exists('posix_geteuid') ? posix_geteuid() === 0 : trim((string)shell_exec('id -u')) === '0';
$nginx  = trim((string)shell_exec('command -v nginx 2>/dev/null'));
$ossl   = trim((string)shell_exec('command -v openssl 2>/dev/null'));
if (!$isRoot || $nginx === '' || $ossl === '') {
    T::skip('nginx-hsts.sh', 'root، nginx یا openssl نیست');
    exit(T::report());
}

$root   = realpath(__DIR__ . '/..');
$script = $root . '/deploy/nginx-hsts.sh';
$N      = sys_get_temp_dir() . '/hsts_' . getmypid();
$port   = 20000 + getmypid() % 20000;
$domain = 'hesab.test';
@mkdir("$N/logs", 0700, true);
@mkdir("$N/bin", 0700, true);

function mkCert(string $N, string $domain, int $days): void
{
    exec('openssl req -x509 -newkey rsa:2048 -nodes -keyout ' . escapeshellarg("$N/k.pem") . ' -out ' . escapeshellarg("$N/c.pem")
        . " -days {$days} -subj /CN={$domain} -addext subjectAltName=DNS:{$domain} 2>/dev/null");
}

function site(string $N, string $domain, int $port, string $headers, string $login = 'return 200 "ok";', bool $ssl = true): string
{
    $sslLines = $ssl ? "    ssl_certificate     $N/c.pem;\n    ssl_certificate_key $N/k.pem; # managed by Certbot\n" : '';
    return "server {\n    listen 127.0.0.1:{$port} ssl;\n    server_name {$domain};\n{$sslLines}"
        . "    add_header X-Content-Type-Options nosniff always;\n{$headers}"
        . "    location = /login.php { {$login} }\n}\n";
}

$hdrBoth = "    add_header Referrer-Policy strict-origin-when-cross-origin always;\n"
         . "    add_header Content-Security-Policy \"default-src 'self'\" always;\n";

mkCert($N, $domain, 90);
file_put_contents("$N/site", site($N, $domain, $port, $hdrBoth));
file_put_contents("$N/nginx.conf", "pid $N/nginx.pid;\nerror_log $N/logs/error.log;\nevents {}\nhttp {\n access_log off;\n"
    . " client_body_temp_path $N/tmp1; proxy_temp_path $N/tmp2; fastcgi_temp_path $N/tmp3; uwsgi_temp_path $N/tmp4; scgi_temp_path $N/tmp5;\n"
    . " include $N/site;\n}\n");
// ⛔ پوسته‌ها: اسکریپت همان `nginx -t` و `systemctl reload nginx` را صدا می‌زند
file_put_contents("$N/bin/nginx", "#!/bin/bash\nexec $nginx -c $N/nginx.conf -p $N \"\$@\"\n");
file_put_contents("$N/bin/systemctl", "#!/bin/bash\n[[ \"\$1 \$2\" == 'reload nginx' ]] && exec $nginx -c $N/nginx.conf -p $N -s reload\nexit 1\n");
chmod("$N/bin/nginx", 0755); chmod("$N/bin/systemctl", 0755);
touch("$N/cron");

shell_exec("$nginx -c $N/nginx.conf -p $N 2>&1"); usleep(300000);

function hsts(array $env = [], string $args = ''): array
{
    global $script, $N, $port, $domain, $root;
    $base = ['PATH' => "$N/bin:" . getenv('PATH'), 'SITE_FILE' => "$N/site", 'DOMAIN' => $domain, 'PORT' => (string)$port,
             'APP_DIR' => $root, 'CRON_FILE' => "$N/cron", 'HESAB_SSL_CAFILE' => "$N/c.pem", 'HESAB_NO_SEND' => '1'];
    $e = '';
    foreach ($env + $base as $k => $v) { $e .= $k . '=' . escapeshellarg($v) . ' '; }
    exec("env {$e} bash " . escapeshellarg($script) . " {$args} 2>&1", $out, $rc);
    return ['rc' => $rc, 'out' => implode("\n", $out)];
}

/**
 * سرآیندِ تحویل‌شده. ⚠ با صبر تا `$want`: بعد از reload کارگرهای قدیمیِ nginx تا چند صد
 * میلی‌ثانیه با پیکربندیِ قبلی جواب می‌دهند (همان صبرِ خودِ `nginx-hsts.sh`) — بی‌آن تست
 * گاهی سرآیندِ قبلی را می‌دید و الکی شکست می‌خورد.
 */
function served(string $want): string
{
    global $port, $domain;
    $v = '';
    for ($i = 0; $i < 20; $i++) {
        $h = (string)shell_exec("curl -sk --noproxy '*' -o /dev/null -D - --max-time 5 --resolve {$domain}:{$port}:127.0.0.1 https://{$domain}:{$port}/login.php");
        $v = preg_match('/^strict-transport-security:\s*(.+?)\s*$/mi', $h, $m) ? $m[1] : '';
        if ($v === $want) { break; }
        usleep(500000);
    }
    return $v;
}

$orig = file_get_contents("$N/site");

// ۱ — نمایشی: چیزی نمی‌نویسد
$r = hsts();
T::same(0, $r['rc'], 'نمایشی با پیش‌شرطِ سالم: کدِ ۰');
T::ok((bool)preg_match('/^  \+\s+add_header Strict-Transport-Security "max-age=15768000" always;$/m', $r['out']), 'نمایشی خطِ افزوده را نشان می‌دهد', $r['out']);
T::same($orig, file_get_contents("$N/site"), 'نمایشی فایلِ سایت را دست نمی‌زند');
T::same('', served(''), 'پیش از --apply هیچ HSTSی تحویل نمی‌شود');

// ۲ — گواهیِ نزدیکِ انقضا: روشن نمی‌شود
mkCert($N, $domain, 5);
shell_exec("$N/bin/systemctl reload nginx 2>&1"); usleep(400000);
$r = hsts([], '--apply');
T::same(1, $r['rc'], '⛔ گواهیِ ۵روزه: --apply رد می‌شود');
T::ok(strpos($r['out'], 'گواهی سالم نیست') !== false, 'و علت را می‌گوید', $r['out']);
T::same($orig, file_get_contents("$N/site"), 'و فایل دست‌نخورده می‌ماند');
T::same('', served(''), 'و هیچ HSTSی تحویل نمی‌شود');

// ۳ — گواهیِ نامعتبر برای مرورگر (بی‌CAِ مورد اعتماد): روشن نمی‌شود
mkCert($N, $domain, 90);
shell_exec("$N/bin/systemctl reload nginx 2>&1"); usleep(400000);
$r = hsts(['HESAB_SSL_CAFILE' => ''], '--apply');
T::same(1, $r['rc'], '⛔ گواهیِ نامعتبر برای مرورگر: --apply رد می‌شود');

// ۴ — روشن کردن
$r = hsts([], '--apply');
T::same(0, $r['rc'], '--apply با گواهیِ سالم موفق است');
T::same('max-age=15768000', served('max-age=15768000'), 'سرآیندِ واقعیِ پاسخ: max-age=15768000');
$site = file_get_contents("$N/site");
T::ok((bool)preg_match('/Content-Security-Policy[^\n]*\n    add_header Strict-Transport-Security "max-age=15768000" always;\n/', $site), 'درست بعد از CSP درج شد');
T::same(1, substr_count($site, 'Strict-Transport-Security'), 'فقط یک بار');
T::ok(count(glob("$N/site.bak.*") ?: []) >= 1, 'نسخه‌ی پشتیبانِ فایلِ سایت ماند');
T::ok(strpos($r['out'], 'hesabland ssl') !== false, 'پیامِ پایانی دستورِ رفعِ گواهی را می‌گوید');

// ۵ — دوباره: کاری نمی‌کند
$r = hsts([], '--apply');
T::same(0, $r['rc'], 'اجرای دوباره کدِ ۰');
T::ok(strpos($r['out'], 'کاری لازم نیست') !== false, 'و می‌گوید کاری لازم نیست');

// ۶ — خاموش: جایگزین می‌شود، اضافه نه
$r = hsts([], '--off');
T::same(0, $r['rc'], '--off موفق است');
T::same('max-age=0', served('max-age=0'), 'سرآیندِ واقعی: max-age=0 (مرورگرها فراموش می‌کنند)');
T::same(1, substr_count(file_get_contents("$N/site"), 'Strict-Transport-Security'), 'جایگزین شد، نه خطِ دوم');

// ۷ — سایت بعد از reload خراب جواب می‌دهد → برگشت
file_put_contents("$N/site", $orig = site($N, $domain, $port, $hdrBoth, 'return 503;'));
shell_exec("$N/bin/systemctl reload nginx 2>&1"); usleep(400000);
$r = hsts([], '--apply');
T::same(1, $r['rc'], '⛔ صفحه‌ی ورودِ غیرِ ۲۰۰ بعد از reload: شکست');
T::same($orig, file_get_contents("$N/site"), 'و فایلِ سایت به حالتِ قبل برگشت');
usleep(400000);
T::same('', served(''), 'و nginx دوباره بی‌HSTS جواب می‌دهد');

// ۸ — فقط Referrer-Policy (بی‌CSP): بعد از آن درج می‌شود
file_put_contents("$N/site", site($N, $domain, $port, "    add_header Referrer-Policy strict-origin-when-cross-origin always;\n"));
shell_exec("$N/bin/systemctl reload nginx 2>&1"); usleep(400000);
$r = hsts([], '--apply');
T::same(0, $r['rc'], 'بی‌CSP: بعد از Referrer-Policy');
T::same('max-age=15768000', served('max-age=15768000'), 'و تحویل می‌شود');

// ۹ — هیچ لنگری نیست: حدس نمی‌زند
file_put_contents("$N/site", $noAnchor = site($N, $domain, $port, ''));
shell_exec("$N/bin/systemctl reload nginx 2>&1"); usleep(400000);
$r = hsts([], '--apply');
T::same(1, $r['rc'], '⛔ بی‌هدرِ امنیتی: رد');
T::same($noAnchor, file_get_contents("$N/site"), 'و فایل دست‌نخورده');

// ۱۰ — سایتِ بی‌گواهی (فقط متنِ فایل): رد
file_put_contents("$N/site.plain", site($N, $domain, $port, $hdrBoth, 'return 200 "ok";', false));
$r = hsts(['SITE_FILE' => "$N/site.plain"], '--apply');
T::same(1, $r['rc'], '⛔ فایلِ سایت بی‌ssl_certificate: رد');
T::ok(strpos($r['out'], 'certbot --nginx') !== false, 'و راهِ گرفتنِ گواهی را می‌گوید');

// ۱۱ — پایش نصب نیست: نمایشی می‌گوید با --apply نصب می‌شود
file_put_contents("$N/site", site($N, $domain, $port, $hdrBoth));
$r = hsts(['CRON_FILE' => "$N/no-cron"]);
T::ok(strpos($r['out'], 'پایشِ گواهی نصب نیست') !== false, 'بی‌cron: نمایشی می‌گوید پایش نصب می‌شود');

shell_exec("$nginx -c $N/nginx.conf -p $N -s stop 2>&1"); usleep(200000);
exec('rm -rf ' . escapeshellarg($N));

exit(T::report());
