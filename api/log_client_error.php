<?php
/**
 * خطای جاوااسکریپتِ مرورگر — تنها راهی که «دکمه‌ها کار نمی‌کنند» جایی
 * دیده شود.
 *
 * ⛔ خرابیِ سمتِ مرورگر در این اپ بی‌صداترین خرابی است: صفحه کامل و
 *    خوش‌ظاهر بالا می‌آید و فقط هیچ دکمه‌ای کار نمی‌کند (همان چیزی که
 *    `js-loading` برایش ساخته شد). `client-errors.js` (هر دو پوسته) هر `error` و
 *    `unhandledrejection` را یک بار (حداکثر دو تا در هر صفحه) اینجا
 *    می‌فرستد: پیام، فایل، خط، و مرورگر. **هیچ محتوایی از صفحه نه.**
 *
 * ⚠ فقط با ورود: بدونِ آن هر کسی می‌توانست با یک حلقه‌ی curl لاگ را پر
 *   کند. و در هر درخواست فقط یک خط نوشته می‌شود.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) { jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405); }
Csrf::verifyOrFail(postParam('csrf_token'));

$msg  = mb_substr(postParam('message'), 0, 300);
$file = mb_substr(postParam('file'), 0, 200);
$line = (int)postParam('line');
$page = mb_substr(postParam('page'), 0, 120);     // فقط مسیر، نه query string
$ua   = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200);
// ⛔ محیط فقط از فهرستِ بسته‌ی `AppErrors::AREAS` — هر چیزِ دیگری یعنی
//    «از مسیر»، که برای این اندپوینت همیشه حساب‌لند است. بی‌این کلید هر
//    خطای جاوااسکریپتِ فروشگاه «حساب‌لند» ثبت می‌شد (درخواست از `api/` است،
//    نه `store/`) و در `store/errors.php` دیده نمی‌شد.
$area = postParam('area');

if ($msg === '') { jsonResponse(['success' => false, 'message' => 'پیام خالی است.'], 422); }

// همان مسیرِ خطاهای PHP: خطِ کامل در فایل + ردیفِ یکتا در app_errors تا
// در پنل مدیر کنارِ خطاهای سرور دیده شود.
AppErrors::record('client', $msg, $file, $line, [
    'event' => 'client.error', 'area' => isset(AppErrors::AREAS[$area]) ? $area : null, 'page' => $page, 'ua' => $ua, 'stack' => mb_substr(postParam('stack'), 0, 800),
]);

jsonResponse(['success' => true]);
