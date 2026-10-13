<?php
/**
 * «آزمایش اعلان» — همین حالا یک اعلان به همه‌ی دستگاه‌های خودِ کاربر.
 *
 * ⛔ از همان `Push::sendToUser()`ِ مسیرِ cron می‌رود، نه یک فرستنده‌ی دوم
 *    (درسِ «آزمایش اعلان» در اپِ اندروید): با نسخه‌ی دوم، دکمه می‌توانست
 *    سبز شود در حالی که مسیرِ واقعی خراب است.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/push.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) { jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405); }
Csrf::verifyOrFail(postParam('csrf_token'));

$userId = Auth::userId();

if (!Push::available() || Push::countFor((int)$userId) === 0) {
    jsonResponse(['success' => false, 'message' => 'اول اعلان را روی این دستگاه روشن کنید.'], 422);
}

$r = Push::sendToUser((int)$userId, [
    'title' => defined('APP_NAME') ? APP_NAME : 'حساب لند',
    'body'  => 'اعلانِ آزمایشی — اگر این را روی گوشی می‌بینید، همه‌چیز درست است.',
    'url'   => 'notifications.php',
    'tag'   => 'test',
]);
if ($r['sent'] > 0) {
    jsonResponse(['success' => true, 'message' => 'فرستاده شد. تا چند ثانیه‌ی دیگر روی گوشی دیده می‌شود.']);
}
// ⛔ «نرسید» با «رد شد» یکی نیست — همان درسِ backup-offsite.
jsonResponse(['success' => false, 'message' => $r['gone'] > 0
    ? 'این دستگاه اجازه‌ی اعلان را پس گرفته؛ دوباره «روشن کردن» را بزنید.'
    : 'سرور به سرویسِ اعلانِ مرورگر نرسید. چند دقیقه‌ی دیگر دوباره امتحان کنید.'], 502);
