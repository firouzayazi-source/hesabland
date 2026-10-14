<?php
/**
 * ⛔ `deploy/mail-setup.sh` رمز را واقعاً جایی می‌نویسد که PHP می‌خواند.
 *
 * خرابیِ واقعی (مهر ۱۴۰۵، سرورِ تازه): config.php دو `define('SMTP_PASS')` داشت
 * (یکی با کامنتِ ته‌خط). PHP اولی را می‌خواند؛ اسکریپت فقط دومی را عوض می‌کرد،
 * پس هیچ رمزی، حتی رمزِ درست، اثر نمی‌کرد و سرورِ ایمیل فقط «535» می‌گفت. و دو
 * بار جای‌نگه‌دارِ فارسیِ راهنما («رمزصندوق») به‌جای رمز نوشته شد.
 *
 * اسکریپتِ واقعی روی یک کپیِ موقتِ پوشه اجرا می‌شود (APP_DIR از جای خودِ اسکریپت).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }
require_once __DIR__ . '/lib/assert.php';
$root = dirname(__DIR__);
$tmp  = sys_get_temp_dir() . '/mailsetup_' . bin2hex(random_bytes(4));
@mkdir("{$tmp}/deploy", 0777, true); @mkdir("{$tmp}/config");
copy("{$root}/deploy/mail-setup.sh", "{$tmp}/deploy/mail-setup.sh");
$cfg = "{$tmp}/config/config.php";

$run = function (string $pass) use ($tmp): array {
    $cmd = 'bash ' . escapeshellarg("{$tmp}/deploy/mail-setup.sh") . ' --yes --no-test'
         . ' --app-url https://hesab.example.ir --smtp-host 127.0.0.1 --smtp-port 1 --smtp-secure tls'
         . ' --smtp-user no-reply@example.ir --smtp-pass ' . escapeshellarg($pass) . ' 2>&1';
    exec($cmd, $out, $rc);
    return [$rc, implode("\n", $out)];
};
$read = function () use ($cfg): array {
    $php = 'error_reporting(0); require ' . var_export($cfg, true) . '; echo json_encode([defined("SMTP_PASS") ? SMTP_PASS : null, defined("SMTP_HOST") ? SMTP_HOST : null]);';
    $o = shell_exec('php -r ' . escapeshellarg($php) . ' 2>/dev/null');
    return json_decode((string)$o, true) ?: [null, null];
};

T::group('رمزِ تکراری در config.php');
file_put_contents($cfg, "<?php\ndefine('DB_NAME', 'x');\ndefine('SMTP_PASS', 'old-garbage-113'); // رمزِ ایمیل\ndefine('SMTP_HOST', 'mail.old');\n\n// ---- ارسال ایمیل (deploy/mail-setup.sh) ----\ndefine('SMTP_PASS', 'second');\n");
[$rc, $out] = $run('Abc123def456');
[$pass, $host] = $read();
T::same(0, $rc, 'اسکریپت موفق تمام شد', $out);
T::same('Abc123def456', $pass, '⛔ رمزِ تازه همان است که PHP می‌خواند (نه تکراریِ قبلی)');
T::same(1, substr_count((string)file_get_contents($cfg), "define('SMTP_PASS'"), 'فقط یک خطِ SMTP_PASS ماند');
T::same('127.0.0.1', $host, 'خطِ با کامنتِ ته‌خط هم جایگزین شد');
T::ok(str_contains($out, 'تکراری'), 'حذفِ تکراری گفته شد');
T::same('', (string)shell_exec('php -l ' . escapeshellarg($cfg) . ' 2>&1 | grep -v "No syntax errors"'), 'نحوِ config.php سالم');

T::group('رمزِ با کاراکترهای خاص');
[$rc] = $run('a\\b$c\'d);e');
T::same('a\\b$c\'d);e', $read()[0], '⛔ بک‌اسلش، $، کوتیشن و «);» دست‌نخورده می‌رسند');

T::group('جای‌نگه‌دارِ فارسی');
$before = (string)file_get_contents($cfg);
[$rc, $out] = $run('رمزصندوق');
T::ok($rc !== 0 && str_contains($out, 'جای‌نگه‌دار'), '⛔ رمزِ فارسی رد می‌شود با پیامِ روشن', $out);
T::same($before, (string)file_get_contents($cfg), 'و config.php دست نخورد');

array_map('unlink', glob("{$tmp}/config/*") ?: []); array_map('unlink', glob("{$tmp}/deploy/*") ?: []);
@rmdir("{$tmp}/config"); @rmdir("{$tmp}/deploy"); @rmdir($tmp);
exit(T::report());
