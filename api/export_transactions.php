<?php
/**
 * خروجیِ CSV از تراکنش‌های **فیلترشده** — همان چیزی که روی صفحه دیده شد.
 *
 * ⛔ POST است نه GET، با CSRF — دقیقاً به دلیلِ `export_data.php`: یک
 *    لینکِ GET را می‌شود در `<img src>` جای دیگری جاسازی کرد و مرورگرِ
 *    کاربرِ واردشده خودش صدایش می‌زند. خروجیِ اینجا می‌تواند کلِ
 *    تاریخچه‌ی مالیِ او باشد.
 *
 * ⛔ صافی‌ها از `buildTransactionFilter()` می‌آیند، نه از کدِ همین فایل.
 *    با نسخه‌ی دوم، کاربر یک فهرست می‌دید و فهرستِ دیگری دانلود می‌کرد —
 *    و کسی فایلِ CSV را با صفحه مقایسه نمی‌کند، پس خرابی **بی‌صداست**.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tx_query.php';

Auth::initSession();


// ⚠ اینجا عمداً ۴۰۱ است نه هدایت: قاعده‌ی `api/` همین است و
//   `test_api_auth` همه‌ی اندپوینت‌ها را با هم می‌سنجد. کاربرِ واقعی هم
//   به اینجا نمی‌رسد چون دکمه‌اش فقط در `transactions.php` رندر می‌شود
//   که خودش `requireLogin()` دارد.
if (!Auth::isLoggedIn()) {
    header('Content-Type: application/json; charset=utf-8');
    jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    failJsonOrRedirect('درخواست نامعتبر است.', 405, '../transactions.php');
}
Csrf::verifyOrFail(postParam('csrf_token'));

$userId = Auth::userId();
$pdo    = Database::getConnection();

try {
    $stmt  = txCsvQuery($userId, $_POST);
    $moves = txCsvMoves($userId, $_POST);
    Audit::log('data.exported', 'transactions_csv', null, ['kind' => 'csv']);
} catch (Throwable $e) {
    Log::error('api.export_csv', $e);
    failJsonOrRedirect('خروجی گرفته نشد. اگر تکرار شد به پشتیبانی خبر بدهید.', 500, '../transactions.php');
}

txCsvStream($stmt, $moves);
