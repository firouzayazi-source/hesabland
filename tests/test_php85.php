<?php
/**
 * ⛔ تابع‌هایی که در PHP 8.5 منسوخ شده‌اند در کدِ برنامه نیایند (سرورِ تازه: PHP 8.5).
 *
 * `php -l` این‌ها را نمی‌گیرد (هشدارشان هنگامِ اجراست، نه کامپایل)، و هشدارِ اجرا فقط
 * در مسیری دیده می‌شود که تستی از آن بگذرد — پیامکِ واقعی، اعلانِ گوشی و سهمِ فروشگاه
 * در تست‌ها به شبکه نمی‌رسند. پس با توکنایزر روی همه‌ی فایل‌ها.
 *
 * - `curl_close`/`imagedestroy`/`finfo_close`: از PHP 8.0 بی‌اثرند → `unset($h)`.
 * - `$http_response_header` → `Outbound::responseHeaders(get_defined_vars())`.
 * - `setAccessible()` فقط پشتِ `PHP_VERSION_ID < 80100`.
 *
 * ⚠ `tests/` هم گشته می‌شود: اولین اجرای کامل روی PHP 8.5 بیش از هزار هشدارِ `curl_close`
 *   فقط از خودِ تست‌ها داد، چون این اسکنر آن‌جا را نمی‌دید.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
$root = dirname(__DIR__);
const BANNED_FUNCS = ['curl_close', 'curl_share_close', 'imagedestroy', 'finfo_close', 'xml_parser_free'];

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    fn($f) => !($f->isDir() && in_array($f->getFilename(), ['.git', 'vendor', 'node_modules', 'var', 'uploads', 'mobile'], true))
));
foreach ($it as $f) { if ($f->getExtension() === 'php' && $f->getPathname() !== __FILE__) { $files[] = $f->getPathname(); } }

$bad = [];
foreach ($files as $path) {
    $rel = substr($path, strlen($root) + 1);
    $src  = (string)file_get_contents($path);
    $lines = explode("\n", $src);
    $toks = token_get_all($src);
    foreach ($toks as $i => $t) {
        if (!is_array($t)) { continue; }
        if ($t[0] === T_STRING && in_array(strtolower($t[1]), BANNED_FUNCS, true)) {
            // فقط فراخوانی: نامِ بعدش «(» و پیشش «->»/«::»/function نباشد
            $j = $i + 1; while (isset($toks[$j]) && is_array($toks[$j]) && $toks[$j][0] === T_WHITESPACE) { $j++; }
            $k = $i - 1; while ($k >= 0 && is_array($toks[$k]) && $toks[$k][0] === T_WHITESPACE) { $k--; }
            $prev = $toks[$k] ?? null;
            $isMember = is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true);
            if (($toks[$j] ?? null) === '(' && !$isMember) { $bad[] = "{$rel}:{$t[2]} {$t[1]}()"; }
        }
        // `setAccessible()` در 8.5 منسوخ و از 8.1 بی‌اثر است: فقط پشتِ `PHP_VERSION_ID < 80100`
        if ($t[0] === T_STRING && $t[1] === 'setAccessible' && !str_contains($lines[$t[2] - 1] ?? '', 'PHP_VERSION_ID < 80100')) {
            $bad[] = "{$rel}:{$t[2]} setAccessible() بی‌گارد";
        }
        if ($t[0] === T_VARIABLE && $t[1] === '$http_response_header') { $bad[] = "{$rel}:{$t[2]} \$http_response_header"; }
    }
}

T::group('PHP 8.5 — تابع‌های منسوخ');
T::ok(count($files) > 200, 'همه‌ی فایل‌های برنامه گشته شدند (' . count($files) . ')');
T::same([], $bad, '⛔ هیچ curl_close/imagedestroy/finfo_close یا $http_response_header در کدِ برنامه نیست');
exit(T::report());
