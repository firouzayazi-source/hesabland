<?php
/**
 * نسخه‌ی آزمایشی (staging) و انتشارِ «همان چیزی که دیده شد».
 *
 * ⛔ خواسته‌ی مالکِ نصب: «برای بارگذاری روی مین راحت باشه و سر در گم
 *    نشیم — هی کد بزنیم بعد بگیم دیده نشد.» پس سه چیز سنجیده می‌شود:
 *
 *   ۱. `hesabland release` فقط **همان کامیتی** را منتشر می‌کند که آخرین
 *      استقرارِ سبزِ staging رویش بود — نه کامیت‌های تازه‌تر، نه چیزی از
 *      شاخه‌ی دیگر — و اگر staging سبز نشده باشد اصلاً جلو نمی‌رود.
 *   ۲. استقرارِ مستقیمِ چیزی که در staging دیده نشده، بی‌پرسش انجام
 *      نمی‌شود.
 *   ۳. staging هرگز روی نام‌های سایتِ اصلی نمی‌نشیند (پوشه، دیتابیس،
 *      آدرس)، رمز دارد (جز برای خودِ سرور) و ایندکس نمی‌شود.
 *
 * هیچ دیتابیس و هیچ سرویسی لازم نیست: مخزن‌ها موقت‌اند و توابعی که به
 * سرور دست می‌زنند (دسترسی‌ها، reload، سنجشِ سلامت) در همین تست با
 * نسخه‌ی بی‌اثر جایگزین می‌شوند — منطقِ تصمیم همان فایلِ واقعی است.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

$root = realpath(__DIR__ . '/..');
$tmp  = sys_get_temp_dir() . '/stg_' . getmypid();
@mkdir($tmp, 0700, true);

/** یک اسکریپتِ bash را اجرا می‌کند: [کدِ خروج، خروجی]. */
$bash = function (string $script, string $stdin = '') use ($tmp): array {
    $f = $tmp . '/s_' . bin2hex(random_bytes(4)) . '.sh';
    file_put_contents($f, $script);
    $p = proc_open(['bash', $f], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $rc = proc_close($p);
    @unlink($f);
    return [$rc, preg_replace('/\e\[[0-9;]*m/', '', $out)];
};

// =====================================================================
T::group('vps-setup.sh --staging — همان قالب، نام‌های جدا');

// قالب‌ها در حالتِ نمایشی به /dev/null می‌روند؛ اینجا چاپشان می‌کنیم.
$vps = $tmp . '/vps.sh';
file_put_contents($vps, str_replace("        cat > /dev/null", "        cat",
    (string)file_get_contents($root . '/deploy/vps-setup.sh')));

[$rc, $stg] = $bash("bash " . escapeshellarg($vps) . " --staging --domain staging.hesab.stland.ir");
[$rc2, $prod] = $bash("bash " . escapeshellarg($vps) . " --domain hesab.stland.ir");

T::same(0, $rc, 'حالتِ نمایشیِ staging بی‌خطا تمام می‌شود');
T::same(0, $rc2, 'حالتِ نمایشیِ سایتِ اصلی هم بی‌خطا (دست‌نخورده)');
T::ok(!str_contains($stg . $prod, 'command not found'), 'هیچ بک‌تیکی داخلِ قالب‌ها اجرا نمی‌شود');

foreach (['/opt/hesab/staging', 'hesabstg', 'pool.d/hesab-staging.conf', 'sites-available/hesab-staging',
          'hesab_staging', '/etc/nginx/hesab-staging.htpasswd', 'php-hesab-staging.sock'] as $n) {
    T::ok(str_contains($stg, $n), "staging از نامِ «{$n}» استفاده می‌کند");
}
// «۸. کارهایی که خودتان…» عمداً `cd /opt/hesab/app` را می‌گوید (hesabland از آنجا اجرا می‌شود).
$stgWrites = explode('۸. کارهایی', $stg)[0];
foreach (['/opt/hesab/app', "'hesab'", 'sites-available/hesab ', 'php-hesab.sock', 'hesab_db'] as $n) {
    T::ok(!str_contains($stgWrites, $n), "staging هیچ‌جا «{$n}»ِ سایتِ اصلی را نمی‌نویسد");
}

$blockOf = fn(string $out): string => preg_match('/^server \{.*?^\}/ms', $out, $m) ? $m[0] : '';
$stgSite = $blockOf($stg);
$prodSite = $blockOf($prod);
foreach (['satisfy any;', 'allow 127.0.0.1;', 'deny all;', 'auth_basic "HesabLand staging";',
          'auth_basic_user_file /etc/nginx/hesab-staging.htpasswd;', 'X-Robots-Tag "noindex, nofollow"'] as $n) {
    T::ok(str_contains($stgSite, $n), "سایتِ staging: {$n}");
}
T::ok(!str_contains($prodSite, 'auth_basic') && !str_contains($prodSite, 'X-Robots-Tag'),
      'سایتِ اصلی نه رمز می‌گیرد نه noindex');
// همان قالب: جز خطوطِ staging و نام‌ها، هر دو بلوک یکی‌اند.
$norm = function (string $site): string {
    $site = preg_replace('/^\s*(satisfy|allow|deny all;|auth_basic|add_header X-Robots|# ⛔ نسخه‌ی آزمایشی).*\n/m', '', $site);
    return preg_replace(['/staging\.hesab\.stland\.ir|hesab\.stland\.ir/', '#/opt/hesab/(staging|app)#', '/php-hesab(-staging)?\.sock/'],
                        ['DOMAIN', 'DIR', 'SOCK'], $site);
};
T::same($norm($prodSite), $norm($stgSite), 'قالبِ nginxِ staging همان قالبِ سایتِ اصلی است (CSP، var/، api/v1 …)');
T::ok(str_contains($stg, 'pm = ondemand') && str_contains($prod, 'pm = dynamic'), 'pool: staging کوچک و ondemand، سایتِ اصلی دست‌نخورده');

[$rcBad, $bad] = $bash("APP_DIR=/opt/hesab/app bash " . escapeshellarg($vps) . " --staging --domain s.x");
T::ok($rcBad !== 0 && str_contains($bad, 'نام‌های سایتِ اصلی'), 'staging با پوشه‌ی سایتِ اصلی رد می‌شود');
[$rcBad, $bad] = $bash("DB_NAME=hesab_db bash " . escapeshellarg($vps) . " --staging --domain s.x");
T::ok($rcBad !== 0, 'staging با دیتابیسِ سایتِ اصلی رد می‌شود');

// کانفیگ از config.example.php ساخته می‌شود، با ایمیل و پیامکِ خاموش.
$src = (string)file_get_contents($root . '/deploy/vps-setup.sh');
if (preg_match("/        php -r '\n(.*?)\n        ' \"/s", $src, $m)) {
    $out = $tmp . '/stg-config.php';
    $cmd = 'php -r ' . escapeshellarg($m[1]) . ' ' . implode(' ', array_map('escapeshellarg',
        [$root . '/config/config.example.php', $out, 'hesab_staging', 'hesab_stg', 'pw', 'https://staging.x.ir', 'sec']));
    exec($cmd . ' 2>&1', $o, $rcc);
    $conf = (string)@file_get_contents($out);
    T::ok($rcc === 0 && str_contains(shell_exec('php -l ' . escapeshellarg($out)) ?? '', 'No syntax errors'), 'کانفیگِ ساخته‌شده نحوِ درست دارد');
    foreach (["define('APP_ENV', 'staging');", "define('DB_NAME', 'hesab_staging');", "define('MAIL_METHOD', '');",
              "define('SMS_METHOD', '');", "define('STORE_API_URL', '');", "define('APP_ENCRYPTION_KEY', '');",
              "define('APP_URL', 'https://staging.x.ir');", "define('APP_FORCE_HTTPS', true);"] as $n) {
        T::ok(str_contains($conf, $n), "کانفیگِ staging: {$n}");
    }
} else {
    T::ok(false, 'تکه‌ی ساختِ کانفیگ در vps-setup.sh پیدا نشد');
}

// =====================================================================
T::group('staging_guard — staging هرگز روی سایتِ اصلی نمی‌نشیند');

$hl = escapeshellarg($root . '/hesabland');
$mkconf = function (string $path, array $c): void {
    @mkdir(dirname($path), 0700, true);
    $s = "<?php\n";
    foreach ($c as $k => $v) { $s .= "define('{$k}', " . var_export($v, true) . ");\n"; }
    file_put_contents($path, $s);
};
$P = $tmp . '/prod'; $S = $tmp . '/stag';
@mkdir($P . '/.git', 0700, true); @mkdir($S . '/.git', 0700, true);
$mkconf("$P/config/config.php", ['DB_NAME' => 'hesab_db', 'APP_URL' => 'https://hesab.x.ir', 'APP_ENV' => 'prod']);

$guard = function (array $stgConf) use ($bash, $hl, $P, $S, $mkconf): string {
    $mkconf("$S/config/config.php", $stgConf);
    [, $o] = $bash("source $hl; staging_guard '$P/config/config.php' '$S/config/config.php' '$P' '$S' && echo OK");
    return trim($o);
};
$good = ['DB_NAME' => 'hesab_staging', 'APP_URL' => 'https://staging.hesab.x.ir', 'APP_ENV' => 'staging'];
T::same('OK', $guard($good), 'کانفیگِ درست پذیرفته می‌شود');
T::ok(str_contains($guard(['APP_ENV' => 'prod'] + $good), "APP_ENV"), 'APP_ENV جز staging رد می‌شود');
T::ok(str_contains($guard(['DB_NAME' => 'hesab_db'] + $good), 'دیتابیس'), 'دیتابیسِ یکسان با سایتِ اصلی رد می‌شود');
T::ok(str_contains($guard(['DB_NAME' => ''] + $good), 'دیتابیس'), 'دیتابیسِ خالی رد می‌شود');
T::ok(str_contains($guard(['APP_URL' => 'https://hesab.x.ir/'] + $good), 'آدرس'), 'آدرسِ یکسان با سایتِ اصلی رد می‌شود');
[, $o] = $bash("source $hl; staging_guard '$P/config/config.php' '$P/config/config.php' '$P' '$P/.' && echo OK");
T::ok(str_contains($o, 'همان پوشه') && !str_contains($o, 'OK'), 'پوشه‌ی یکسان (حتی با مسیرِ متفاوت) رد می‌شود');
[, $o] = $bash("source $hl; staging_guard '$P/config/config.php' '$tmp/none/config/config.php' '$P' '$tmp/none' && echo OK");
T::ok(str_contains($o, 'vps-setup.sh --staging'), 'staging ساخته نشده → راهِ ساختنش گفته می‌شود');

$mkconf("$S/config/config.php", ['MAIL_METHOD' => 'smtp', 'SMS_METHOD' => 'log', 'STORE_API_URL' => '']);
[, $o] = $bash("source $hl; staging_outbound '$S/config/config.php'");
T::ok(str_contains($o, 'ایمیل') && !str_contains($o, 'پیامک'), 'ایمیلِ روشن گفته می‌شود؛ SMS_METHOD=log بی‌خطر است');

// =====================================================================
T::group('release — فقط همان کامیتِ دیده‌شده');

$G = 'git -c user.email=t@t -c user.name=t -c init.defaultBranch=main';
$R = $tmp . '/rel';
$setup = <<<SH
set -e
rm -rf '$R'; mkdir -p '$R'; cd '$R'
$G init -q --bare origin.git
$G clone -q origin.git work 2>/dev/null; cd work
echo a > f.txt; $G add f.txt; $G commit -qm A; $G push -q origin HEAD:main
cd '$R'; $G clone -q origin.git prod; $G clone -q origin.git stag
cd work; echo b > f.txt; $G commit -qam B; $G push -q origin HEAD:main
echo c > f.txt; $G commit -qam C; $G push -q origin HEAD:main
cd '$R/stag'; git fetch -q; git reset -q --hard HEAD~0; git reset -q --hard origin/main~1
mkdir -p var; printf '%s %s\\n' "\$(date +%s)" "\$(git rev-parse --short HEAD)" > var/deploy-ok.beat
SH;
[$rc, $o] = $bash($setup);
T::same(0, $rc, 'مخزن‌های آزمایشی ساخته شدند' . ($rc ? " — $o" : ''));

// توابعی که به سرور دست می‌زنند بی‌اثر می‌شوند؛ تصمیم‌ها همان فایلِ واقعی.
$stub = <<<SH
source $hl
apply_permissions() { :; }
clear_php_cache() { :; }
preflight_config() { :; }
health_check() { echo HEALTH_OK; return 0; }
STAGING_DIR='$R/stag'; APP_DIR='$R/prod'; DEPLOY_BEAT='$R/prod/var/deploy-ok.beat'
cd '$R/prod'
SH;
$sha = fn(string $dir, string $ref = 'HEAD'): string => trim((string)shell_exec("git -C " . escapeshellarg($dir) . " rev-parse --short $ref"));

[$rc, $o] = $bash($stub . "\ncmd_release", "n\n");
T::ok($rc !== 0 && str_contains($o, 'لغو شد'), '«n» یعنی لغو');
T::same($sha("$R/prod"), $sha("$R/prod", 'origin/main~2'), 'با لغو، سایتِ اصلی دست نخورد (همان A)');
T::ok(str_contains($o, $sha("$R/stag")), 'نسخه‌ی دیده‌شده (B) نشان داده می‌شود');
T::ok(str_contains($o, '1 کامیتِ تازه‌تر') && str_contains($o, 'منتشر نمی‌شوند'), 'کامیتِ دیده‌نشده (C) صریح گفته می‌شود');

[$rc, $o] = $bash($stub . "\ncmd_release --yes");
T::same(0, $rc, 'release --yes موفق' . ($rc ? " — " . substr($o, -400) : ''));
T::same($sha("$R/stag"), $sha("$R/prod"), '⛔ سایتِ اصلی دقیقاً روی کامیتِ staging (B) نشست، نه آخرین کامیت (C)');
T::ok(str_contains($o, 'HEALTH_OK'), 'سنجشِ سلامت بعد از انتشار اجرا شد');
T::ok(str_contains((string)@file_get_contents("$R/prod/var/deploy-ok.beat"), $sha("$R/stag")), 'نشانه‌ی سبزِ سایتِ اصلی همان کامیت را ثبت کرد');

[$rc, $o] = $bash($stub . "\ncmd_release --yes");
T::ok($rc === 0 && str_contains($o, 'کاری لازم نیست'), 'انتشارِ دوباره‌ی همان نسخه کاری نمی‌کند');

// staging بعد از آخرین سبز عوض شده (مثلاً استقرارِ ناموفق) → رد
$bash("cd '$R/stag' && git reset -q --hard origin/main");
[$rc, $o] = $bash($stub . "\ncmd_release --yes");
T::ok($rc !== 0 && str_contains($o, 'سالم تمام نشد'), 'staging بعد از آخرین سبز جابه‌جا شده → انتشار رد می‌شود');

// کامیتی که روی شاخه‌ی سایتِ اصلی نیست → رد
$bash("cd '$R/stag' && echo z > f.txt && $G commit -qam Z && $G push -q origin HEAD:other && mkdir -p var && printf '%s %s\\n' \"\$(date +%s)\" \"\$(git rev-parse --short HEAD)\" > var/deploy-ok.beat");
[$rc, $o] = $bash($stub . "\ncmd_release --yes");
T::ok($rc !== 0 && str_contains($o, 'روی شاخه‌ی سایتِ اصلی'), 'کامیتِ شاخه‌ی دیگر روی سایتِ اصلی منتشر نمی‌شود');

// نبودِ هیچ سبزی
@unlink("$R/stag/var/deploy-ok.beat");
[$rc, $o] = $bash($stub . "\ncmd_release --yes");
T::ok($rc !== 0 && str_contains($o, 'هیچ استقرارِ سبزی ندارد'), 'بی‌استقرارِ سبزِ staging، انتشار رد می‌شود');

// استقرارِ مستقیمِ چیزی که دیده نشده → پرسیده می‌شود
$bash("cd '$R/stag' && git reset -q --hard origin/main~1 && printf '%s %s\\n' \"\$(date +%s)\" \"\$(git rev-parse --short HEAD)\" > var/deploy-ok.beat");
$before = $sha("$R/prod");
[$rc, $o] = $bash($stub . "\ncmd_deploy", "n\n");
T::ok($rc !== 0 && str_contains($o, 'در staging دیده نشده'), 'deploy مستقیمِ نسخه‌ی دیده‌نشده (C) می‌پرسد');
T::same($before, $sha("$R/prod"), 'با «n» سایتِ اصلی دست نخورد');

// --pause: فعلاً مستقیم روی سایتِ اصلی، بی‌پرسش — و staging دست‌نخورده
$pauseEnv = "\nSTAGING_PAUSE='$R/prod/var/staging-paused'\n";
[$rc, $o] = $bash($stub . $pauseEnv . "cmd_staging --pause");
T::ok($rc === 0 && is_file("$R/prod/var/staging-paused") && is_dir("$R/stag/.git"), '--pause نشانه می‌گذارد و staging سرِ جایش می‌ماند');
[$rc, $o] = $bash($stub . $pauseEnv . "cmd_deploy", "n\n");
T::ok($rc === 0 && !str_contains($o, 'در staging دیده نشده'), 'با --pause، deploy بی‌پرسش روی سایتِ اصلی می‌رود' . ($rc ? " — " . substr($o, -300) : ''));
T::same($sha("$R/prod", 'origin/main'), $sha("$R/prod"), 'و سایتِ اصلی روی آخرین کامیت نشست');
[$rc, $o] = $bash($stub . $pauseEnv . "cmd_staging --resume");
T::ok($rc === 0 && !is_file("$R/prod/var/staging-paused"), '--resume نشانه را برمی‌دارد');
$bash("cd '$R/prod' && git reset -q --hard origin/main~1");
[$rc, $o] = $bash($stub . $pauseEnv . "cmd_deploy", "n\n");
T::ok($rc !== 0 && str_contains($o, 'در staging دیده نشده'), 'بعد از --resume، deploy دوباره می‌پرسد');

// =====================================================================
T::group('staging — همان استقرار، روی پوشه‌ی staging');

$mkconf("$R/prod/config/config.php", ['DB_NAME' => 'hesab_db', 'APP_URL' => 'https://hesab.x.ir', 'APP_ENV' => 'prod']);
$mkconf("$R/stag/config/config.php", $good);
$fake = $tmp . '/fake-self.sh';
file_put_contents($fake, "#!/usr/bin/env bash\necho \"SELF APP_DIR=\$APP_DIR ROLE=\$HESAB_ROLE USER=\$APP_USER ARGS=\$*\"\n");
[$rc, $o] = $bash($stub . "\nPROD_DIR='$R/prod'; SELF='$fake'; git -C '$R/stag' checkout -q -B other HEAD; cmd_staging");
T::ok(str_contains($o, "SELF APP_DIR=$R/stag ROLE=staging USER=hesabstg ARGS=deploy --no-backup --migrate --force"),
      'staging همان deploy را با پوشه، کاربر و نقشِ staging اجرا می‌کند' . (str_contains($o, 'SELF') ? '' : " — $o"));
T::same('main', trim((string)shell_exec("git -C '$R/stag' rev-parse --abbrev-ref HEAD")), 'staging روی شاخه‌ی سایتِ اصلی رفت');
T::same('origin/main', trim((string)shell_exec("git -C '$R/stag' rev-parse --abbrev-ref @{u}")), 'و بالادستش همان شاخه‌ی گیت‌هاب است');

// =====================================================================
T::group('نوارِ «نسخه‌ی آزمایشی» — فقط در staging');

$banner = function (string $env) use ($root): string {
    return (string)shell_exec('php -r ' . escapeshellarg(
        "define('APP_ENV', '$env'); require '$root/includes/functions.php'; echo envBanner();") . ' 2>&1');
};
$b = $banner('staging');
T::ok(str_contains($b, 'نسخه‌ی آزمایشی') && str_contains($b, 'pointer-events:none'), 'در staging نوار رندر می‌شود و جلوی لمس را نمی‌گیرد');
T::same('', $banner('prod'), 'در سایتِ اصلی هیچ چیزی رندر نمی‌شود');
foreach (['includes/header.php', 'includes/biz_head.php', 'login.php', 'store/login.php'] as $f) {
    T::ok((bool)preg_match('/^<body[^\n]*\n<\?= envBanner\(\) \?>/m', (string)file_get_contents("$root/$f")), "{$f}: نوار درست بعد از <body>");
}

// =====================================================================
T::group('nginxِ واقعی: رمز برای همه جز خودِ سرور');

$nginx = trim((string)shell_exec('command -v nginx 2>/dev/null'));
$ip = trim((string)shell_exec("hostname -I 2>/dev/null | awk '{print \$1}'"));
$isRoot = function_exists('posix_geteuid') ? posix_geteuid() === 0 : trim((string)shell_exec('id -u')) === '0';
if ($nginx === '' || $ip === '' || !$isRoot || $stgSite === '') {
    T::skip('nginxِ واقعی', 'nginx، آی‌پیِ غیرِloopback یا root در دسترس نیست');
} else {
    $N = '/srv/stg_ng_' . getmypid();
    @mkdir("$N/root", 0755, true); @mkdir("$N/logs", 0755, true);
    file_put_contents("$N/root/x.txt", "hi\n");
    file_put_contents("$N/htpasswd", 'hesab:' . trim((string)shell_exec('openssl passwd -apr1 secret1')) . "\n");
    $site = str_replace(['listen 80;', '/opt/hesab/staging', '/etc/nginx/hesab-staging.htpasswd', 'include snippets/fastcgi-php.conf;'],
                        ['listen 8093;', "$N/root", "$N/htpasswd", 'return 200 "php";'], $stgSite);
    $site = preg_replace('/^\s*(listen \[::\]:80;|fastcgi_pass .*|fastcgi_read_timeout .*)\n/m', '', $site);
    $site = str_replace('    location / {', "    location = /.well-known/acme-challenge/tok { default_type text/plain; return 200 \"tok\"; }\n    location / {", $site);
    file_put_contents("$N/site.conf", $site);
    file_put_contents("$N/nginx.conf", "pid $N/nginx.pid; error_log $N/logs/e.log; events {}\nhttp { access_log off; client_body_temp_path $N; include $N/site.conf; }\n");
    shell_exec("chmod -R a+rX " . escapeshellarg($N));
    exec("nginx -t -c $N/nginx.conf -p $N 2>&1", $to, $trc);
    T::same(0, $trc, 'nginx -t قالبِ staging را می‌پذیرد');
    shell_exec("nginx -c $N/nginx.conf -p $N 2>&1"); usleep(400000);
    $code = fn(string $url, string $auth = ''): string =>
        trim((string)shell_exec("curl -s --noproxy '*' -o /dev/null -w '%{http_code}' " . ($auth ? '-u ' . escapeshellarg($auth) . ' ' : '') . escapeshellarg($url)));
    T::same('401', $code("http://$ip:8093/x.txt"), 'از بیرون بی‌رمز: ۴۰۱');
    T::same('401', $code("http://$ip:8093/x.txt", 'hesab:bad'), 'از بیرون با رمزِ غلط: ۴۰۱');
    T::same('200', $code("http://$ip:8093/x.txt", 'hesab:secret1'), 'از بیرون با رمزِ درست: ۲۰۰');
    T::same('200', $code("http://127.0.0.1:8093/x.txt"), 'از خودِ سرور بی‌رمز: ۲۰۰ (سنجشِ سلامت کار می‌کند)');
    T::same('200', $code("http://$ip:8093/.well-known/acme-challenge/tok"), 'چالشِ certbot بی‌رمز رد می‌شود (گواهی گرفتنی است)');
    T::same('404', $code("http://$ip:8093/var/version.txt", 'hesab:secret1'), 'var/ در staging هم بسته است');
    shell_exec("nginx -c $N/nginx.conf -p $N -s stop 2>&1"); usleep(200000);
    shell_exec('rm -rf ' . escapeshellarg($N));
}

// =====================================================================
T::group('سنجشِ سلامت — نصبِ تازه (ریدایرکت به setup.php) سالم است');

// ⛔ اولین استقرارِ واقعیِ staging با «کد 302 — سایت سالم نیست» ایستاد: دیتابیسِ
//    تازه هیچ کاربری ندارد و login.php به setup.php می‌رود. `curl` اینجا یک
//    تابعِ bash است که همان پاسخ‌ها را می‌دهد؛ منطقِ سنجش همان فایلِ واقعی.
$curlStub = function (string $loginMode, string $setupBody) use ($hl): string {
    return <<<SH
source $hl
DOMAIN=staging.x.ir
curl() {
    local a url='' w=''
    for a in "\$@"; do case "\$a" in https://*) url="\$a" ;; esac; done
    local prev=''
    for a in "\$@"; do [[ "\$prev" == "-w" ]] && w="\$a"; prev="\$a"; done
    case "\$url" in
      */login.php)
        case "$loginMode" in
          setup) [[ "\$w" == '%{http_code}' ]] && printf 302; [[ "\$w" == '%{redirect_url}' ]] && printf 'https://staging.x.ir/setup.php'; [[ -z "\$w" ]] && printf 'redirect' ;;
          other) [[ "\$w" == '%{http_code}' ]] && printf 302; [[ "\$w" == '%{redirect_url}' ]] && printf 'https://evil.x/'; [[ -z "\$w" ]] && printf 'redirect' ;;
          ok)    [[ "\$w" == '%{http_code}' ]] && printf 200; [[ -z "\$w" ]] && printf '<html></html>' ;;
        esac ;;
      */setup.php)
        [[ "\$w" == '%{http_code}' ]] && printf 200; [[ -z "\$w" ]] && printf '%s' '$setupBody' ;;
      *api/v1*) printf '{"ok":true}' ;;
    esac
    return 0
}
sleep() { SECONDS=\$((SECONDS + 5)); }
if health_check; then echo "RC=0"; else echo "RC=1"; fi
SH;
};
[, $o] = $bash($curlStub('setup', '<html>setup</html>'));
T::ok(str_contains($o, 'RC=0') && str_contains($o, 'نصبِ تازه'), 'login → setup.php و setup کامل رندر شد: سالم' . (str_contains($o, 'RC=0') ? '' : " — $o"));
[, $o] = $bash($curlStub('setup', '<html>cut'));
T::ok(str_contains($o, 'RC=1'), 'setup.php نصفه (بی </html>): رد');
[, $o] = $bash($curlStub('other', '<html></html>'));
T::ok(str_contains($o, 'RC=1'), 'ریدایرکت به هر جای دیگری: همچنان رد');
[, $o] = $bash($curlStub('ok', ''));
T::ok(str_contains($o, 'RC=0'), 'صفحه‌ی ورودِ عادی (۲۰۰): سالم');

shell_exec('rm -rf ' . escapeshellarg($tmp));
exit(T::report());
