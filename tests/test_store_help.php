<?php
/**
 * ⛔ راهنمای داخلِ برنامه (`BizHelp`، `store/help.php`).
 *
 *   ۱. فهرستِ بسته: هر صفحه‌ی منو و هر صفحه‌ی جزئیاتِ `store/` یک موضوع دارد، و هر موضوع یک صفحه‌ی واقعی است.
 *   ۲. HTTP: دکمه‌ی «؟» راهنمای **همان** صفحه را نشان می‌دهد؛ صفحه‌ی راهنما همه را دارد.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/lib/store_http.php';
T::group('راهنما');
if (!file_exists(__DIR__ . '/../config/config.php')) { T::blocked('راهنما', 'config/config.php وجود ندارد'); exit(T::report()); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/biz.php';
require_once __DIR__ . '/../includes/biz_help.php';

T::group('۱ — فهرستِ بسته');
$root = realpath(__DIR__ . '/..');
// ⚠ بیرون از راهنما: ورود/خروج، برگه‌ی چاپ، فاکتورِ مشتری، برگشت از درگاه، خطاهای مدیر، و خودِ راهنما
$exempt = ['login.php', 'logout.php', 'print.php', 'view.php', 'pay-return.php', 'errors.php', 'help.php'];
$missing = [];
$pages = array_map('basename', glob($root . '/store/*.php') ?: []);
foreach ($pages as $f) {
    if (!in_array($f, $exempt, true) && BizHelp::topic($f) === null) { $missing[] = "store/{$f} راهنما ندارد"; }
}
T::bulk(count($pages), $missing, 'هر صفحه‌ی فروشگاه (جز چند استثنای نوشته‌شده) راهنما دارد');
$ghost = [];
foreach (array_keys(BizHelp::TOPICS) as $f) { if (!in_array($f, $pages, true)) { $ghost[] = "راهنمای {$f} صفحه‌ای ندارد"; } }
T::bulk(count(BizHelp::TOPICS), $ghost, 'هیچ راهنمایی برای صفحه‌ی نبوده نمانده');
$empty = [];
foreach (BizHelp::TOPICS as $f => [$t, $pts]) { if ($t === '' || !$pts || array_filter($pts, fn($p) => mb_strlen($p) > 160)) { $empty[] = $f; } }
T::bulk(count(BizHelp::TOPICS), $empty, 'هر موضوع عنوان و دستِ کم یک نکته‌ی کوتاه (≤ ۱۶۰ نویسه) دارد');
T::same('', BizHelp::button('nope.php'), 'صفحه‌ی بی‌موضوع: دکمه‌ای نیست');
T::ok(str_contains(BizHelp::button('quick-sale.php'), 'فروش سریع') && str_contains(BizHelp::button('quick-sale.php'), 'help.php#quick-sale'), 'دکمه: عنوان و لینکِ همان بخش');

T::group('۲ — HTTP');
try { $pdo = Database::getConnection(); } catch (Throwable $e) { T::blocked('راهنما', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }
const HP_PREFIX = '__store_help_';
$wipe = function () use ($pdo) {
    foreach ($pdo->query("SELECT id FROM users WHERE username LIKE '" . HP_PREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN) as $id) { deleteUserAccount((int)$id); }
};
$wipe();
register_shutdown_function($wipe);
$r = createUserAccount($pdo, 'صاحب', HP_PREFIX . 'own', HP_PREFIX . 'own@example.com', 'Help#pass9');
Biz::setType((int)$r['id'], 'business');
$port = stHttpServer();
T::ok($port > 0, 'سرورِ آزمایشی بالا آمد');
if (!$port) { exit(T::report()); }
$o = stHttpClient($port);
stHttpLogin($o, HP_PREFIX . 'own', 'Help#pass9');
[$c, $h] = $o('store/quick-sale.php');
T::ok($c === 200 && str_contains($h, 'class="st-dd st-help"') && str_contains($h, BizHelp::TOPICS['quick-sale.php'][1][0]), 'فروش سریع: «؟» با راهنمای خودش');
T::ok(!str_contains($h, BizHelp::TOPICS['purchases.php'][1][0]), '⛔ و نه راهنمای صفحه‌ی دیگر');
[$c, $h] = $o('store/help.php');
$miss = [];
foreach (BizHelp::TOPICS as $f => [$t]) { if (!str_contains($h, 'id="' . pathinfo($f, PATHINFO_FILENAME) . '"')) { $miss[] = $f; } }
T::ok($c === 200 && str_contains($h, '</html>'), 'صفحه‌ی راهنما رندر شد');
T::bulk(count(BizHelp::TOPICS), $miss, 'صفحه‌ی راهنما هر موضوع را یک بار با لنگرِ خودش دارد');
T::same(count(BizHelp::TOPICS), substr_count($h, 'class="st-card st-help-card"'), 'هیچ موضوعی دو بار نیامده');

exit(T::report());
