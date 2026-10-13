<?php
/**
 * اسکریپت‌های سرور: hastico، backup.sh، restore.sh، migrate.sh، config-lib.sh.
 *
 * ⛔ هر بخش یک خرابیِ واقعی (بازبینی) را نگه می‌دارد که **بی‌صدا** بود:
 *
 *   ۱. reload «اولین php-fpmِ فعال» به‌جای مالِ این اپ، و بی `-t`.
 *   ۲. chmodِ سراسری که کلیدِ خصوصیِ VAPID و var/biz-import را ۶۴۴ می‌کرد.
 *   ۳. `rollback` به HEAD~1 — نسخه‌ای که هرگز روی سرور نبوده.
 *   ۴. رمزِ دیتابیس در خطِ فرمان (`ps aux`).
 *   ۵. نگه‌داریِ بکاپ بر اساسِ تعداد: بکاپ‌های پیش از استقرار شبانه‌ها را می‌بردند.
 *   ۶. `restore.sh --into` هر دیتابیسی را می‌پذیرفت (با --admin، دیتابیسِ ربات‌ها).
 *   ۷. migrate.sh هر خطای «تکراری» را «اجراشده» ثبت می‌کرد — migrationِ نیمه‌کاره.
 *   ۸. سنجشِ سلامتِ nginx-*.sh بی `--noproxy`.
 *
 * منطق همان فایل‌های واقعی است؛ فقط چیزی که به سرور دست می‌زند (systemctl،
 * php-fpm، mysqldump) با نسخه‌ی بی‌اثر جایگزین می‌شود. بخشِ migrate.sh
 * دیتابیسِ واقعی و مدیرِ دیتابیس (سوکت) می‌خواهد؛ نبودش رد است نه شکست.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

$root = realpath(__DIR__ . '/..');
$tmp  = sys_get_temp_dir() . '/deploytest_' . getmypid();
@mkdir($tmp, 0700, true);
register_shutdown_function(function () use ($tmp) { shell_exec('rm -rf ' . escapeshellarg($tmp)); });

/** یک اسکریپتِ bash: [کدِ خروج، خروجی]. */
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
$hl  = escapeshellarg($root . '/hastico');
$lib = escapeshellarg($root . '/deploy/config-lib.sh');
$isRoot = function_exists('posix_geteuid') ? posix_geteuid() === 0 : trim((string)shell_exec('id -u')) === '0';

// =====================================================================
T::group('⛔ کشِ PHP: فقط php-fpmِ صاحبِ pool این اپ، و فقط بعد از -t');

$E = "$tmp/etcphp";
@mkdir("$E/8.3/fpm/pool.d", 0755, true);
@mkdir("$E/8.4/fpm/pool.d", 0755, true);
file_put_contents("$E/8.3/fpm/pool.d/bots.conf", "[bots]\n");       // سرویسِ دیگری روی 8.3
file_put_contents("$E/8.4/fpm/pool.d/hesab.conf", "[hesab]\n");
@mkdir("$tmp/bin", 0755, true);
file_put_contents("$tmp/bin/php-fpm8.4", "#!/bin/sh\necho \"fpm-test \$*\" >> '$tmp/calls.log'; [ -e '$tmp/fpm-broken' ] && { echo 'ERROR: bad pool'; exit 78; }; exit 0\n");
file_put_contents("$tmp/bin/php-fpm8.3", "#!/bin/sh\nexit 0\n");
chmod("$tmp/bin/php-fpm8.4", 0755); chmod("$tmp/bin/php-fpm8.3", 0755);

// ⚠ systemctl و php-fpm خروجی‌شان را در فایل می‌نویسند، چون خودِ
//   clear_php_cache خروجیِ reload را دور می‌ریزد و -t را می‌گیرد. و
//   `if` لازم است: hastico با set -e source می‌شود.
$cache = function (string $extra = '') use ($bash, $hl, $E, $tmp): array {
    @unlink("$tmp/calls.log");
    [$rc, $o] = $bash(<<<SH
export PATH='$tmp/bin':"\$PATH"
source $hl
PHP_ETC_DIR='$E'
systemctl() { echo "SYSTEMCTL \$*" >> '$tmp/calls.log'; return 0; }
$extra
if clear_php_cache; then echo "RC=0"; else echo "RC=1"; fi
SH);
    return [$rc, $o . "\n" . (string)@file_get_contents("$tmp/calls.log")];
};
[, $o] = $cache();
T::ok(str_contains($o, 'SYSTEMCTL reload php8.4-fpm') && str_contains($o, 'RC=0'),
    'سرویسِ صاحبِ pool.d/hesab.conf (8.4) reload می‌شود', $o);
T::ok(!str_contains($o, 'php8.3-fpm'), '⛔ php-fpmِ سرویسِ دیگر (8.3) هرگز reload نمی‌شود');
T::ok(strpos($o, 'fpm-test -t') !== false && strpos($o, 'fpm-test -t') < strpos($o, 'SYSTEMCTL reload'),
    'پیکربندی پیش از reload با php-fpm8.4 -t سنجیده می‌شود');

touch("$tmp/fpm-broken");
[, $o] = $cache();
T::ok(!str_contains($o, 'SYSTEMCTL reload') && str_contains($o, 'RC=1'),
    '⛔ با -tِ ناموفق reload نمی‌شود و کدِ خروج ناصفر است', $o);
@unlink("$tmp/fpm-broken");

[, $o] = $cache("POOL_NAME=nope");
T::ok(!str_contains($o, 'SYSTEMCTL reload') && str_contains($o, 'RC=1'),
    'pool این اپ نیست → هیچ php-fpmی reload نمی‌شود (نه «اولین فعال»)', $o);

file_put_contents("$E/8.3/fpm/pool.d/hesab-staging.conf", "[stg]\n");
[, $o] = $cache("POOL_NAME=hesab-staging");
[, $role] = $bash("HESAB_ROLE=staging source $hl\necho \"POOL=\$POOL_NAME\"");
T::ok(str_contains($role, 'POOL=hesab-staging'), 'نقشِ staging نامِ pool خودش را می‌گیرد', $role);
T::ok(str_contains($o, 'SYSTEMCTL reload php8.3-fpm'), 'staging pool خودش (hesab-staging) را پیدا می‌کند', $o);

// tune.sh همان کشف را به کار می‌برد، نه نسخه‌ی دوم.
$tune = (string)file_get_contents($root . '/deploy/tune.sh');
T::ok(str_contains($tune, 'fpm_pool_file') && str_contains($tune, 'fpm_config_test')
      && !str_contains($tune, 'for f in /etc/php/*/fpm/pool.d'),
    'tune.sh کشف و سنجش را از config-lib.sh می‌گیرد (یک نسخه)');

// vps-setup.sh: php-fpm -t پیش از reload (حالتِ نمایشی ترتیب را چاپ می‌کند).
[, $o] = $bash('bash ' . escapeshellarg($root . '/deploy/vps-setup.sh') . ' --domain hesab.x.ir');
$tAt = preg_match('/php-fpm[0-9.]+ -t/', $o, $mm, PREG_OFFSET_CAPTURE) ? $mm[0][1] : -1;
$rAt = preg_match('/systemctl reload php[0-9.]+-fpm/', $o, $mm, PREG_OFFSET_CAPTURE) ? $mm[0][1] : -1;
T::ok($tAt >= 0 && $rAt > $tAt, 'vps-setup.sh: php-fpm -t پیش از reload');

// =====================================================================
T::group('⛔ دسترسی‌ها: var/ و config/ از chmodِ سراسری بیرون‌اند');

if (!$isRoot) {
    T::skip('apply_permissions', 'به root نیاز دارد (chown)');
} else {
    $A = "$tmp/app";
    $G = 'git -c user.email=t@t -c user.name=t -c init.defaultBranch=main';
    [$rc, $o] = $bash(<<<SH
set -e
mkdir -p '$A'; cd '$A'
$G init -q .
mkdir -p config deploy
echo '<?php' > config/config.example.php; echo x > a.php; echo x > hastico; echo x > deploy.sh; echo x > deploy/x.sh
$G add -A; $G commit -qm A
mkdir -p var/push var/biz-import/j1 var/sessions var/log uploads
# نصبِ موجود: استقرارهای قبلی این‌ها را ۶۴۴/۷۵۵ کرده‌اند.
echo k > var/push/vapid.json; chmod 644 var/push/vapid.json
chmod 755 var/biz-import var/biz-import/j1; echo d > var/biz-import/j1/f.csv; chmod 644 var/biz-import/j1/f.csv
# فایل‌هایی که PHP خودش خصوصی می‌سازد و هیچ‌کس نباید بازشان کند.
echo s > var/sessions/sess_abc; chmod 600 var/sessions/sess_abc
echo l > var/log/app.jsonl; chmod 640 var/log/app.jsonl
echo '<?php' > config/config.php; chmod 640 config/config.php
echo '<?php' > config/config.php.bak-1; chmod 644 config/config.php.bak-1
SH);
    T::same(0, $rc, 'نصبِ آزمایشی ساخته شد' . ($rc ? " — $o" : ''));
    [$rc, $o] = $bash("source $hl\nAPP_USER=root\nsudo() { shift 2; \"\$@\"; }\ncd '$A'\napply_permissions\necho DONE");
    $mode = fn(string $p): string => substr(sprintf('%o', @fileperms("$A/$p")), -3);
    T::ok(str_contains($o, 'DONE'), 'apply_permissions اجرا شد', $o);
    T::same('600', $mode('var/push/vapid.json'), '⛔ کلیدِ خصوصیِ VAPID به ۶۰۰ برمی‌گردد (نصبِ موجود)');
    T::same('700', $mode('var/biz-import/j1'), '⛔ پوشه‌ی ورودِ فروشگاه ۷۰۰');
    T::same('600', $mode('var/biz-import/j1/f.csv'), '⛔ فایلِ ورودِ فروشگاه ۶۰۰');
    T::same('600', $mode('var/sessions/sess_abc'), '⛔ فایلِ نشستِ PHP ۶۰۰ می‌ماند (نه ۶۴۴ — ربودنِ نشست)');
    T::same('640', $mode('var/log/app.jsonl'), 'لاگِ var/log دست نمی‌خورد');
    T::same('640', $mode('config/config.php'), 'config.php ۶۴۰ است');
    T::same('600', $mode('config/config.php.bak-1'), 'بکاپِ رمزدارِ config.php برای کاربرانِ دیگر خواندنی نیست');
    T::same('644', $mode('config/config.example.php'), 'config.example.php همان ۶۴۴ِ گیت');
    T::same('644', $mode('a.php'), 'کدِ اپ ۶۴۴');
    T::same('755', $mode('hastico'), 'hastico اجرایی می‌ماند');
    T::same('700', $mode('var/sessions'), 'var/sessions ۷۰۰');
    T::ok(!file_exists("$A/uploads/.htaccess"), 'uploads/.htaccess (Apache) دیگر نوشته نمی‌شود');
}

// =====================================================================
T::group('⛔ rollback: به نسخه‌ای که واقعاً پیش از استقرار روی سرور بود');

$R = "$tmp/rb";
$G = 'git -c user.email=t@t -c user.name=t -c init.defaultBranch=main';
[$rc, $o] = $bash(<<<SH
set -e
mkdir -p '$R'; cd '$R'
$G init -q --bare origin.git
$G clone -q origin.git work 2>/dev/null; cd work
for c in A B C D; do echo \$c > f.txt; $G add f.txt; $G commit -qm \$c; done
$G push -q origin HEAD:main
cd '$R'; $G clone -q origin.git prod; cd prod; git reset -q --hard HEAD~3
SH);
T::same(0, $rc, 'مخزن‌های آزمایشی ساخته شدند' . ($rc ? " — $o" : ''));
$stub = <<<SH
source $hl
apply_permissions() { :; }
clear_php_cache() { :; }
preflight_config() { :; }
health_check() { echo HEALTH_OK; return 0; }
STAGING_DIR='$R/none'; APP_DIR='$R/prod'; DEPLOY_BEAT='$R/prod/var/deploy-ok.beat'
cd '$R/prod'
SH;
$head = fn(): string => trim((string)shell_exec("git -C " . escapeshellarg("$R/prod") . " log -1 --pretty=%s"));

[$rc, $o] = $bash($stub . "\ncmd_rollback", "y\n");
T::ok($rc !== 0 && str_contains($o, 'ثبت نشده') && $head() === 'A',
    'بی‌ثبتِ نسخه‌ی قبلی، حدس نمی‌زند (HEAD~1 نه) و چیزی عوض نمی‌شود', $o);

[$rc, $o] = $bash($stub . "\ncmd_deploy --no-backup");
T::same('D', $head(), 'استقرار A → D (سه کامیت با هم)');
T::same(trim((string)shell_exec("git -C " . escapeshellarg("$R/prod") . " rev-parse HEAD~3")),
    trim((string)@file_get_contents("$R/prod/var/deploy-prev")), 'پیش از فرود، کامیتِ A در var/deploy-prev ثبت شد');

[$rc, $o] = $bash($stub . "\ncmd_deploy --no-backup");
T::ok(str_contains((string)@file_get_contents("$R/prod/var/deploy-prev"),
    trim((string)shell_exec("git -C " . escapeshellarg("$R/prod") . " rev-parse HEAD~3"))),
    'استقرارِ بی‌تغییر «نسخه‌ی قبلی» را بازنویسی نمی‌کند');

[$rc, $o] = $bash($stub . "\ncmd_rollback", "y\n");
T::same('A', $head(), '⛔ rollback به A برگشت (نسخه‌ی واقعاً مستقرِ قبلی)، نه C = HEAD~1');

// =====================================================================
T::group('⛔ رمزِ دیتابیس هرگز در خطِ فرمان');

$PW = 'Sec"r\\et$1 #x';
$cfg = "$tmp/cfg.php";
file_put_contents($cfg, "<?php\ndefine('DB_HOST', 'db.internal');\ndefine('DB_NAME', 'hesab_db');\n"
    . "define('DB_USER', 'hesab_user');\ndefine('DB_PASSWORD', " . var_export($PW, true) . ");\n");
// mysqldumpِ بی‌اثر: آرگومان‌ها و محتوای فایلِ گزینه را ثبت می‌کند.
file_put_contents("$tmp/bin/mysqldump", <<<'SH'
#!/bin/bash
echo "ARGV: $*" >> "$LOGF"
for a in "$@"; do case "$a" in --defaults-extra-file=*) f="${a#*=}"; echo "MODE: $(stat -c %a "$f")" >> "$LOGF"; cat "$f" >> "$LOGF"; echo "$f" > "$LOGF.path" ;; esac; done
echo 'CREATE TABLE `transactions` (id int);'
SH);
chmod("$tmp/bin/mysqldump", 0755);
// ⚠ `config-lib.sh` اگر `mariadb-dump` باشد آن را صدا می‌زند (MariaDB 11) — همان برنامه‌ی ساختگی با هر دو نام
copy("$tmp/bin/mysqldump", "$tmp/bin/mariadb-dump"); chmod("$tmp/bin/mariadb-dump", 0755);
$B = "$tmp/backups";
@mkdir($B, 0700, true);
$log = "$tmp/dump.log";
[$rc, $o] = $bash("export PATH='$tmp/bin':\"\$PATH\" LOGF='$log' CONFIG='$cfg' APP_DIR='$tmp/app2' BACKUP_DIR='$B'\nbash " . escapeshellarg($root . '/deploy/backup.sh'));
$L = (string)@file_get_contents($log);
T::ok($rc === 0 && str_contains($o, 'بکاپ گرفته شد'), 'backup.sh با فایلِ گزینه بکاپ می‌گیرد', $o);
T::ok(!str_contains(preg_replace('/^(?!ARGV).*$/m', '', $L), 'Sec') && !preg_match('/ARGV:.*\s-p/', $L),
    '⛔ رمز در آرگومان‌های mysqldump نیست (ps aux)', $L);
T::ok(str_contains($L, 'MODE: 600'), 'فایلِ گزینه ۶۰۰ است');
T::ok(str_contains($L, 'password="Sec\\"r\\\\et$1 #x"'), 'رمز با فرارِ درستِ فایلِ گزینه‌ی MySQL نوشته شد', $L);
T::ok(str_contains($L, 'host="db.internal"'), 'DB_HOSTِ کانفیگ رعایت می‌شود (نه localhostِ بی‌صدا)');
$cnfPath = trim((string)@file_get_contents("$log.path"));
T::ok($cnfPath !== '' && !file_exists($cnfPath), 'فایلِ رمز بعد از پایان پاک شد (trap)');

$migSrc = (string)file_get_contents($root . '/deploy/migrate.sh');
$resSrc = (string)file_get_contents($root . '/deploy/restore.sh');
$bakSrc = (string)file_get_contents($root . '/deploy/backup.sh');
$noP = [];
foreach (['migrate.sh' => $migSrc, 'restore.sh' => $resSrc, 'backup.sh' => $bakSrc] as $n => $s) {
    foreach (explode("\n", $s) as $i => $ln) {
        if (preg_match('/^\s*#/', $ln)) { continue; }
        if (preg_match('/-p"\$DB_PASS"|-p\$DB_PASS|--password=/', $ln)) { $noP[] = "$n:" . ($i + 1); }
    }
}
T::bulk(3, $noP, '⛔ هیچ‌کدام از migrate/restore/backup رمز را با -p نمی‌دهند');

// =====================================================================
T::group('⛔ نگه‌داریِ بکاپ: بر اساسِ سن، با کفِ تعداد');

shell_exec('rm -f ' . escapeshellarg($B) . '/*');
for ($i = 1; $i <= 6; $i++) {                       // شبانه‌های ۱ تا ۶ روزِ پیش
    $f = "$B/hesab_db_night$i.sql.gz"; file_put_contents($f, gzencode('x')); touch($f, time() - $i * 86400);
}
for ($i = 1; $i <= 20; $i++) {                      // بیست بکاپِ پیش از استقرار، امروز
    $f = "$B/hesab_db_deploy$i.sql.gz"; file_put_contents($f, gzencode('x')); touch($f, time() - $i * 60);
}
for ($i = 1; $i <= 3; $i++) {                       // کهنه‌تر از ۱۴ روز
    $f = "$B/hesab_db_old$i.sql.gz"; file_put_contents($f, gzencode('x')); touch($f, time() - (20 + $i) * 86400);
}
[$rc, $o] = $bash("export PATH='$tmp/bin':\"\$PATH\" LOGF='$log' CONFIG='$cfg' APP_DIR='$tmp/app2' BACKUP_DIR='$B'\nbash " . escapeshellarg($root . '/deploy/backup.sh'));
$left = array_map('basename', glob("$B/*.sql.gz"));
T::ok(count(array_filter($left, fn($f) => str_contains($f, 'night'))) === 6,
    '⛔ بکاپ‌های پیش از استقرار شبانه‌های هفته را بیرون نمی‌کنند', implode(' ', $left));
T::ok(!array_filter($left, fn($f) => str_contains($f, 'old')), 'کهنه‌تر از ۱۴ روز (و بیرون از کفِ تعداد) پاک شد');

shell_exec('rm -f ' . escapeshellarg($B) . '/*');
for ($i = 1; $i <= 3; $i++) {
    $f = "$B/hesab_db_old$i.sql.gz"; file_put_contents($f, gzencode('x')); touch($f, time() - (40 + $i) * 86400);
}
[$rc, $o] = $bash("export PATH='$tmp/bin':\"\$PATH\" LOGF='$log' CONFIG='$cfg' APP_DIR='$tmp/app2' BACKUP_DIR='$B' KEEP=3\nbash " . escapeshellarg($root . '/deploy/backup.sh'));
T::same(3, count(glob("$B/*.sql.gz")), 'همیشه دست‌کم KEEP فایلِ آخر می‌ماند، حتی کهنه');

// =====================================================================
T::group('⛔ restore.sh: فقط دیتابیس‌های همین پروژه، و پیش‌فرضِ درست');

file_put_contents("$tmp/bin/mysql", "#!/bin/sh\nexit 1\n");   // اتصال نمی‌گیرد؛ به آن نمی‌رسیم
chmod("$tmp/bin/mysql", 0755);
copy("$tmp/bin/mysql", "$tmp/bin/mariadb"); chmod("$tmp/bin/mariadb", 0755);
$restore = fn(string $args) => $bash("export PATH='$tmp/bin':\"\$PATH\" CONFIG='$cfg' BACKUP_DIR='$B' STAGING_DIR='$tmp/nostg'\nbash "
    . escapeshellarg($root . '/deploy/restore.sh') . " $args");
[$rc, $o] = $restore('--admin --into bots_db');
T::ok($rc !== 0 && str_contains($o, 'دیتابیسِ این پروژه نیست'), '⛔ --into دیتابیسِ سرویسِ دیگر رد می‌شود', $o);
[$rc, $o] = $restore('--admin --into hesab_db_x');
T::ok($rc !== 0 && str_contains($o, 'دیتابیسِ این پروژه نیست'), 'نامِ شبیه هم رد می‌شود');
[$rc, $o] = $restore('--admin --into hesab_staging');
T::ok(!str_contains($o, 'دیتابیسِ این پروژه نیست'), 'دیتابیسِ staging پذیرفته می‌شود');

shell_exec('rm -f ' . escapeshellarg($B) . '/*');
$dump = gzencode("CREATE TABLE `transactions` (id int);\n");
file_put_contents("$B/hesab_db_2026-10-01_0330.sql.gz", $dump); touch("$B/hesab_db_2026-10-01_0330.sql.gz", time() - 3600);
file_put_contents("$B/hesab_db_before-restore_2026-10-01_0900.sql.gz", $dump);
[$rc, $o] = $restore('');
T::ok(str_contains($o, 'انتخاب شد: hesab_db_2026-10-01_0330.sql.gz'),
    '⛔ پیش‌فرض بکاپِ ایمنیِ before-restore نیست (وضعیتِ کنارگذاشته)', $o);

// =====================================================================
T::group('⛔ سنجشِ nginx-*.sh پراکسیِ محیط را دور می‌زند');

$badNp = [];
foreach (['nginx-api.sh', 'nginx-var.sh', 'nginx-csp.sh', 'nginx-realip.sh'] as $f) {
    $s = (string)file_get_contents("$root/deploy/$f");
    // هر فراخوانیِ curl تا پایانِ ادامه‌ی خط (\) یک واحد است.
    preg_match_all('/^[^#\n]*\bcurl\b(?:[^\n]*\\\\\n)*[^\n]*/m', $s, $mm);
    // راهنمای چاپ‌شده برای مالکِ نصب (info "… curl …") فراخوانی نیست.
    $probes = array_filter($mm[0], fn($c) => str_contains($c, '--resolve')
        && !preg_match('/^\s*(info|plain|echo|printf|warn|red|green)\b/', $c));
    if (!$probes) { $badNp[] = "$f: هیچ سنجشِ --resolve پیدا نشد"; }
    foreach ($probes as $c) {
        if (!str_contains($c, "--noproxy '*'")) { $badNp[] = "$f: " . trim(strtok($c, "\n")); }
    }
}
T::bulk(4, $badNp, '⛔ هر سنجشِ --resolve در nginx-*.sh با --noproxy است');

// =====================================================================
T::group('⛔ migrate.sh: «تکراری» فقط برای migration_indexes و فقط با شاهد');

$adminOk = trim((string)shell_exec("mysql -N -e 'SELECT 1' 2>/dev/null")) === '1';
if (!$adminOk) {
    T::skip('migrate.sh روی دیتابیسِ واقعی', 'مدیرِ دیتابیس (سوکت) در دسترس نیست');
} else {
    $db = 'hesab_mtest_' . substr(bin2hex(random_bytes(3)), 0, 6);
    $u  = 'mt_' . substr(bin2hex(random_bytes(3)), 0, 6);
    $pw = 'p"w\\#' . bin2hex(random_bytes(3));
    $sqlPw = str_replace(["\\", "'"], ["\\\\", "''"], $pw);
    shell_exec("mysql -e " . escapeshellarg("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci;"
        . " CREATE USER '$u'@'localhost' IDENTIFIED BY '$sqlPw'; GRANT ALL ON `$db`.* TO '$u'@'localhost';") . ' 2>&1');
    register_shutdown_function(function () use ($db, $u) {
        shell_exec("mysql -e " . escapeshellarg("DROP DATABASE IF EXISTS `$db`; DROP USER IF EXISTS '$u'@'localhost';") . ' 2>&1');
    });
    $mcfg = "$tmp/mig.php";
    file_put_contents($mcfg, "<?php\ndefine('DB_HOST', 'localhost');\ndefine('DB_NAME', '$db');\n"
        . "define('DB_USER', '$u');\ndefine('DB_PASSWORD', " . var_export($pw, true) . ");\n");
    $q = fn(string $sql): string => trim((string)shell_exec("mysql -N " . escapeshellarg($db) . " -e " . escapeshellarg($sql) . ' 2>&1'));
    $mig = fn(string $mode, string $script = '') => $bash("CONFIG='$mcfg' bash "
        . escapeshellarg($script ?: $root . '/deploy/migrate.sh') . " $mode");
    $idx = fn(string $t): string => $q("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema='$db' AND table_name='$t' AND index_name='idx_user_settled_due'");

    [$rc, $o] = $mig('--apply');
    T::ok($rc === 0 && str_contains($o, 'همه‌ی migration ها اعمال شدند'), 'زنجیره با کاربرِ اپ و فایلِ گزینه اعمال شد', substr($o, -400));
    [$rc, $o] = $mig('--verify');
    T::same(0, $rc, '--verify روی نصبِ تازه سبز است');

    // ⛔ ایندکس‌های migration_indexes گم شده‌اند ولی «اجراشده» ثبت است.
    $q("ALTER TABLE cheques DROP INDEX idx_user_settled_due; ALTER TABLE debts DROP INDEX idx_user_settled_due");
    [$rc, $o] = $mig('--verify');
    T::ok($rc !== 0 && str_contains($o, 'migration_indexes.sql'), '⛔ --verify نبودِ ایندکس‌های migration_indexes را می‌بیند (شاهد دارد)', $o);
    [$rc, $o] = $mig('--apply');
    T::ok($rc === 0 && $idx('cheques') !== '0' && $idx('debts') !== '0',
        '⛔ --apply همه‌ی ایندکس‌های گم‌شده را می‌سازد، نه فقط «تکراری» را ثبت کند', $o);

    // ⛔ migrationِ نیمه‌کاره (غیرِ migration_indexes) دیگر «اجراشده» ثبت نمی‌شود.
    $C = "$tmp/migcopy";
    @mkdir("$C/deploy", 0755, true);
    copy("$root/deploy/migrate.sh", "$C/deploy/migrate.sh");
    copy("$root/deploy/config-lib.sh", "$C/deploy/config-lib.sh");
    foreach (glob("$root/*.sql") as $f) { copy($f, "$C/" . basename($f)); }
    file_put_contents("$C/migration_sms_learn.sql",
        "ALTER TABLE `wallets` ADD COLUMN `zz_half` INT NULL;\nALTER TABLE `wallets` ADD COLUMN IF NOT EXISTS `sms_keys` VARCHAR(255) NULL;\n");
    $q("ALTER TABLE wallets DROP COLUMN sms_keys; ALTER TABLE wallets ADD COLUMN zz_half INT NULL;"
        . " DELETE FROM schema_migrations WHERE filename='migration_sms_learn.sql'");
    [$rc, $o] = $mig('--apply', "$C/deploy/migrate.sh");
    T::ok($rc !== 0 && str_contains($o, 'Duplicate column'), 'خطای «ستونِ تکراری» در migrationِ عادی خطاست', $o);
    T::same('0', $q("SELECT COUNT(*) FROM schema_migrations WHERE filename='migration_sms_learn.sql'"),
        '⛔ migrationِ نیمه‌کاره «اجراشده» ثبت نمی‌شود');
}

exit(T::report());
