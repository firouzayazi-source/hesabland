<?php
/**
 * `SslWatch` + `deploy/ssl-check.php` + `hesabland ssl` — هشدارِ گواهی و رفعِ یک‌دستوری.
 *
 * ⛔ چرا مهم است: با HSTS، گواهیِ منقضی سایت را برای همه می‌بندد. پس سه چیز سنجیده
 *    می‌شود: (۱) داوری درست است و مرزهایش دقیق، (۲) هشدار واقعاً به مدیر می‌رسد —
 *    ایمیل، اعلان، «خطاهای برنامه» — بی‌آنکه هر ۶ ساعت تکرار شود ولی بدتر شدن
 *    دوباره بفرستد، (۳) رفع فقط گواهیِ همین دامنه را تمدید می‌کند و nginx را فقط
 *    بعد از `nginx -t` سبز reload می‌کند.
 *    گواهی‌ها واقعی‌اند (openssl + `s_server`)، نه ساختگی.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/ssl_watch.php';

$root = realpath(__DIR__ . '/..');
$now  = 1_800_000_000;
$host = 'hesab.test';
$good = ['reached' => true, 'valid_to' => $now + 60 * 86400, 'names' => [$host], 'issuer' => 'LE', 'trusted' => true, 'error' => ''];
$at   = fn(int $days, array $o = []) => $o + ['valid_to' => $now + $days * 86400 + 3600] + $good;

T::group('داوری — مرزها');
T::same('ok',       SslWatch::assess($at(15), $host, $now)['state'], '۱۵ روز مانده: سالم');
T::same('warn',     SslWatch::assess($at(14), $host, $now)['state'], '۱۴ روز مانده: نزدیکِ انقضا');
T::same('warn',     SslWatch::assess($at(4), $host, $now)['state'],  '۴ روز مانده: هنوز نزدیکِ انقضا');
T::same('critical', SslWatch::assess($at(3), $host, $now)['state'],  '۳ روز مانده: خراب');
T::same('critical', SslWatch::assess(['valid_to' => $now - 1] + $good, $host, $now)['state'], 'منقضی: خراب');
T::same('critical', SslWatch::assess(['reached' => false, 'error' => 'refused'] + $good, $host, $now)['state'], 'اتصال نشد: خراب');
T::same('critical', SslWatch::assess(['names' => ['other.test']] + $good, $host, $now)['state'], 'گواهیِ دامنه‌ی دیگر: خراب');
T::same('critical', SslWatch::assess(['trusted' => false] + $good, $host, $now)['state'], 'نامعتبر برای مرورگر: خراب');
T::same(14, SslWatch::assess($at(14), $host, $now)['days'], 'شمارِ روز');
T::ok(SslWatch::nameMatches('a.hesab.test', ['*.hesab.test']), 'wildcard یک برچسب را می‌پوشاند');
T::ok(!SslWatch::nameMatches('hesab.test', ['*.hesab.test']), 'wildcard خودِ دامنه را نمی‌پوشاند');
T::ok(!SslWatch::nameMatches('x.a.hesab.test', ['*.hesab.test']), 'wildcard دو برچسب را نمی‌پوشاند');
$msg = SslWatch::errorMessage(SslWatch::assess($at(10), $host, $now), $host);
T::same($msg, SslWatch::errorMessage(SslWatch::assess($at(10), $host, $now + 86400), $host), '⛔ متنِ ردیفِ خطا با گذشتِ روز عوض نمی‌شود (یک ردیف، نه هر روز یکی)');
T::ok(strpos($msg, SslWatch::FIX_CMD) !== false && strpos(SslWatch::mailText(SslWatch::assess($at(10), $host, $now), $host), "\n" . SslWatch::FIX_CMD . "\n") !== false,
    'متنِ خطا و ایمیل دستورِ رفع را دارند (ایمیل در خطِ جدا، برای کپی از گوشی)');

// =====================================================================
T::group('گواهیِ واقعی (openssl s_server)');
$ossl = trim((string)shell_exec('command -v openssl 2>/dev/null'));
$D = sys_get_temp_dir() . '/sslw_' . getmypid();
@mkdir($D, 0700, true);
$port = 30000 + getmypid() % 20000;
$srv = null;
if ($ossl !== '') {
    exec("openssl req -x509 -newkey rsa:2048 -nodes -keyout $D/k.pem -out $D/c.pem -days 10 -subj /CN={$host} -addext subjectAltName=DNS:{$host} 2>/dev/null");
    $srv = proc_open(['openssl', 's_server', '-quiet', '-accept', (string)$port, '-key', "$D/k.pem", '-cert', "$D/c.pem", '-www'],
        [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pp);
    usleep(500000);
    $p = SslWatch::probe($host, '127.0.0.1', $port, 5, "$D/c.pem");
    T::ok($p['reached'] && $p['trusted'], 'با CAِ خودش: خوانده و معتبر');
    T::same([$host], $p['names'], 'نامِ گواهی');
    T::same('warn', SslWatch::assess($p, $host, time())['state'], 'گواهیِ ۱۰روزه: نزدیکِ انقضا');
    $p2 = SslWatch::probe($host, '127.0.0.1', $port, 5);
    T::ok($p2['reached'] && !$p2['trusted'], 'بی‌CAِ مورد اعتماد: خوانده می‌شود ولی معتبر نیست');
    T::same('critical', SslWatch::assess($p2, $host, time())['state'], 'و خراب داوری می‌شود');
    $p3 = SslWatch::probe($host, '127.0.0.1', $port + 1, 3);
    T::ok(!$p3['reached'], 'پورتِ بسته: نرسید');

    exec('HESAB_SSL_CAFILE=' . escapeshellarg("$D/c.pem") . " php $root/deploy/ssl-check.php --host {$host} --port {$port} 2>&1", $o, $rc);
    T::same(1, $rc, 'ssl-check.php: کدِ خروجِ ۱ برای نزدیکِ انقضا');
    T::ok(strpos(implode("\n", $o), SslWatch::FIX_CMD) !== false, 'و دستورِ رفع را چاپ می‌کند');
} else {
    T::skip('گواهیِ واقعی', 'openssl نیست');
}

// =====================================================================
T::group('هشدار — ایمیل، اعلان، خطاهای برنامه');
$pdo = Database::getConnection();
$stateFile = SslWatch::stateFile();
$keepState = is_readable($stateFile) ? file_get_contents($stateFile) : null;
@unlink($stateFile);
$cleanup = function () use ($pdo) {
    $pdo->exec("DELETE FROM notifications WHERE kind = 'ssl'");
    $pdo->exec("DELETE FROM app_errors WHERE file = '" . SslWatch::ERR_FILE . "'");
};
$cleanup();

// ⛔ همیشه یک مدیرِ ایمیل‌دار — بی‌آن «ایمیلِ تکراری نه» صفر را با صفر می‌سنجید (جهشِ زنده‌مانده)
$pdo->exec("DELETE FROM users WHERE username = 'ssl_admin_t'");
$pdo->exec("INSERT INTO users (username, full_name, password_hash, role, is_active, email) VALUES ('ssl_admin_t', 'ssl', 'x', 'admin', 1, 'ssl@example.test')");
$madeAdmin = (int)$pdo->lastInsertId();
$admins = $pdo->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
$withMail = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND email IS NOT NULL AND email <> ''")->fetchColumn();

$mails = [];
SslWatch::$mailForTests = function ($to, $subj, $text) use (&$mails) { $mails[] = [$to, $subj, $text]; return true; };
$open = fn() => (int)$pdo->query("SELECT COUNT(*) FROM app_errors WHERE file = '" . SslWatch::ERR_FILE . "' AND resolved_at IS NULL")->fetchColumn();
$notes = fn() => (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE kind = 'ssl'")->fetchColumn();

$r = SslWatch::run($host, $at(60), $now);
T::same('ok', $r['state'], 'سالم: وضعیت ثبت شد');
T::same([0, 0, 0], [count($mails), $notes(), $open()], 'سالم: هیچ هشداری');
T::same('ok', SslWatch::state()['state'] ?? null, 'پنل از var/ssl-state.json می‌خواند');

$r = SslWatch::run($host, $at(10), $now);
T::ok($withMail >= 1, 'دستِ کم یک مدیرِ ایمیل‌دار');
T::same($withMail, count($mails), 'نزدیکِ انقضا: به هر مدیرِ ایمیل‌دار یک ایمیل');
T::ok(isset($mails[0]) && strpos($mails[0][2], SslWatch::FIX_CMD) !== false, 'ایمیل دستورِ رفع را دارد');
T::same(count($admins), $notes(), 'و به هر مدیر یک اعلان (به گوشی با push-send)');
T::same(1, $open(), 'و یک ردیفِ باز در «خطاهای برنامه»');

SslWatch::run($host, $at(10), $now + 6 * 3600);
T::same($withMail, count($mails), '⛔ شش ساعت بعد، همان روز: ایمیلِ دوباره نه');
T::same(count($admins), $notes(), 'و اعلانِ تکراری نه');
T::same(1, $open(), 'و همان یک ردیف (شمارش بالا می‌رود)');

SslWatch::run($host, $at(2), $now + 7 * 3600);
T::same(2 * $withMail, count($mails), '⛔ بدتر شدن (خراب) همان روز: دوباره ایمیل');
T::same(2 * count($admins), $notes(), 'و اعلانِ تازه');

SslWatch::run($host, $at(1), $now + 86400 + 3600);
T::same(3 * $withMail, count($mails), 'روزِ بعد، هنوز خراب: ایمیلِ روزانه');

$r = SslWatch::run($host, $at(90), $now + 2 * 86400);
T::same(0, $open(), '⛔ تمدید شد: ردیفِ «خطاهای برنامه» خودکار بسته شد');
T::same('', $r['mailed_on'], 'و حافظه‌ی ایمیل پاک شد (خرابیِ بعدی همان روز خبر می‌دهد)');
SslWatch::run($host, $at(10), $now + 2 * 86400 + 3600);
T::same(4 * $withMail, count($mails), 'خرابیِ تازه بعد از سالم شدن: همان لحظه ایمیل');

SslWatch::$mailForTests = null;
$cleanup();
if ($madeAdmin) { $pdo->exec("DELETE FROM users WHERE id = {$madeAdmin}"); }

// =====================================================================
T::group('hesabland ssl — فقط گواهیِ همین دامنه، reload فقط بعد از nginx -t');
$B = "$D/bin";
@mkdir($B, 0700, true);
file_put_contents("$B/certbot", "#!/bin/bash\necho \"\$*\" >> $D/certbot.log\n"
    . "[[ \"\$1\" == certificates ]] && { [[ -n \"\${NO_CERT:-}\" ]] || echo '  Certificate Name: {$host}'; exit 0; }\nexit \${RENEW_RC:-0}\n");
file_put_contents("$B/nginx", "#!/bin/bash\necho \"nginx \$*\" >> $D/order.log\nexit \${NGX_T:-0}\n");
file_put_contents("$B/systemctl", "#!/bin/bash\necho \"systemctl \$*\" >> $D/order.log\nexit 0\n");
file_put_contents("$B/sleep", "#!/bin/bash\nexit 0\n");
foreach (['certbot', 'nginx', 'systemctl', 'sleep'] as $f) { chmod("$B/$f", 0755); }

$sslRun = function (array $env, string $args = '') use ($B, $D, $root, $host) {
    @unlink("$D/certbot.log"); @unlink("$D/order.log");
    $e = 'PATH=' . escapeshellarg("$B:" . getenv('PATH')) . ' DOMAIN=' . $host . ' HESAB_NO_SEND=1 APP_DIR=' . escapeshellarg($root);
    foreach ($env as $k => $v) { $e .= " {$k}=" . escapeshellarg($v); }
    exec("cd " . escapeshellarg($root) . " && env {$e} bash -c 'source ./hesabland; cmd_ssl {$args}' 2>&1", $out, $rc);
    return ['rc' => $rc, 'out' => implode("\n", $out),
            'certbot' => is_readable("$D/certbot.log") ? file("$D/certbot.log", FILE_IGNORE_NEW_LINES) : [],
            'order' => is_readable("$D/order.log") ? file("$D/order.log", FILE_IGNORE_NEW_LINES) : []];
};

$r = $sslRun([]);
T::ok(in_array("certificates -d {$host}", $r['certbot'], true), 'نامِ گواهی را از certbot برای همین دامنه می‌پرسد');
T::ok(in_array("renew --cert-name {$host} --non-interactive", $r['certbot'], true), '⛔ فقط همین گواهی تمدید می‌شود (--cert-name)', implode(' | ', $r['certbot']));
T::bulk(count($r['certbot']), array_values(array_filter($r['certbot'], fn($l) => $l === 'renew' || str_starts_with($l, 'renew ') && strpos($l, '--cert-name') === false)),
    '⛔ هرگز renewِ همه‌ی گواهی‌های سرور');
T::same(['nginx -t', 'systemctl reload nginx'], $r['order'], 'اول nginx -t، بعد reload — نه restart');
T::same(1, $r['rc'], 'وقتی سایت هنوز گواهیِ سالم تحویل نمی‌دهد: «انجام شد» نمی‌گوید (کدِ ۱)');
T::ok(strpos($r['out'], 'hesabland ssl --force') !== false, 'و راهِ تمدیدِ اجباری را پیشنهاد می‌کند');

$r = $sslRun(['NGX_T' => '1']);
T::same(['nginx -t', 'nginx -t'], $r['order'], '⛔ nginx -t رد: هیچ reloadی');
T::same(1, $r['rc'], 'و شکست گزارش می‌شود');

$r = $sslRun([], '--force');
T::ok(in_array("renew --cert-name {$host} --non-interactive --force-renewal", $r['certbot'], true), '--force: تمدیدِ اجباریِ همان گواهی');

$r = $sslRun(['NO_CERT' => '1']);
T::same(1, $r['rc'], 'گواهی در certbot نیست: شکست');
T::ok(strpos($r['out'], "certbot --nginx -d {$host}") !== false, 'و راهِ گرفتنِ گواهیِ تازه را می‌گوید');
T::same([], $r['order'], 'و به nginx دست نمی‌زند');

$r = $sslRun(['RENEW_RC' => '1']);
T::ok(strpos($r['out'], 'certbot تمدید نکرد') !== false, 'شکستِ certbot گفته می‌شود');

// ⛔ اجرای cmd_ssl بالا با `--run` وضعیت و اعلان نوشته؛ پاک می‌شود
$cleanup();
if ($keepState !== null) { file_put_contents($stateFile, $keepState); } else { @unlink($stateFile); }

if ($srv) { proc_terminate($srv); proc_close($srv); }
exec('rm -rf ' . escapeshellarg($D));

exit(T::report());
