<?php
/**
 * ⛔ اسکریپت‌های استقرار کلاینتِ دیتابیس را با نامِ `mariadb` صدا می‌زنند، اگر باشد.
 *
 * در MariaDB 11 نامِ `mysql`/`mysqldump` منسوخ است (هشدار روی stderr، یا اصلاً نصب نیست).
 * `deploy/config-lib.sh` دو تابعِ هم‌نام می‌سازد؛ این‌جا با `PATH`ی از برنامه‌های ساختگی
 * سنجیده می‌شود که هر دو حالت (با `mariadb` و بی‌آن) و `$(...)` درست بروند.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
$root = dirname(__DIR__);
$tmp  = sys_get_temp_dir() . '/dbcli_' . bin2hex(random_bytes(4));
@mkdir("{$tmp}/new", 0777, true); @mkdir("{$tmp}/old");
$stub = function (string $path, string $say): void {
    file_put_contents($path, "#!/bin/sh\necho {$say} \"\$@\"\n"); chmod($path, 0755);
};
$stub("{$tmp}/new/mariadb", 'NEW'); $stub("{$tmp}/new/mariadb-dump", 'NEWDUMP');
$stub("{$tmp}/new/mysql", 'OLD');   $stub("{$tmp}/new/mysqldump", 'OLDDUMP');
$stub("{$tmp}/old/mysql", 'OLD');   $stub("{$tmp}/old/mysqldump", 'OLDDUMP');

$run = function (string $dir) use ($root): string {
    $script = '. ' . escapeshellarg("{$root}/deploy/config-lib.sh")
            . '; mysql -N x; x=$(mysqldump --q y); echo "$x"';
    return trim((string)shell_exec('env -i PATH=' . escapeshellarg("{$dir}:/usr/bin:/bin")
        . ' bash -c ' . escapeshellarg($script) . ' 2>&1'));
};

T::group('کلاینتِ دیتابیس در اسکریپت‌های استقرار');
$out = $run("{$tmp}/new");
T::same("NEW -N x\nNEWDUMP --q y", $out, '⛔ با `mariadb` نصب‌شده، `mysql` و `mysqldump` (حتی داخلِ `$(...)`) به نامِ تازه می‌روند');
$out = $run("{$tmp}/old");
$hasReal = trim((string)shell_exec('command -v mariadb 2>/dev/null')) !== '';
if (!$hasReal) {
    T::same("OLD -N x\nOLDDUMP --q y", $out, 'بی‌`mariadb`، همان `mysql` (سرورِ قدیم)');
} else {
    T::ok(true, 'حالتِ «بی‌mariadb» این‌جا سنجیده نمی‌شود (mariadb در /usr/bin هست)');
}

array_map('unlink', array_merge(glob("{$tmp}/new/*") ?: [], glob("{$tmp}/old/*") ?: []));
@rmdir("{$tmp}/new"); @rmdir("{$tmp}/old"); @rmdir($tmp);
exit(T::report());
