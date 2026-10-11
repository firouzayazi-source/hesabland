<?php
/**
 * خروجیِ CSVِ یک بازه‌ی تاریخ (پیوندِ «دانلود اکسل» در گزارش و فرمِ «داده‌ها»).
 *
 * ⛔ فقط درِ ورود است: کوئری و نوشتن همان `txCsvQuery()`/`txCsvStream()`ِ
 *    `api/export_transactions.php` — نسخه‌ی دومی که ستون و قالبِ خودش را
 *    داشت حذف شد. GET می‌ماند (فقط خواندن است، مثلِ صفحه‌ی گزارش).
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/tx_query.php';

Auth::initSession();
Auth::requireLogin();

$userId = Auth::userId();

$fromDate = getParam('from_date', startOfJalaliMonth());
$toDate   = getParam('to_date', today());

if (!isValidDate($fromDate)) $fromDate = startOfJalaliMonth();
if (!isValidDate($toDate)) $toDate = today();
if ($fromDate > $toDate) {
    [$fromDate, $toDate] = [$toDate, $fromDate];
}

try {
    $range = ['period' => 'custom', 'from_date' => $fromDate, 'to_date' => $toDate];
    $stmt  = txCsvQuery($userId, $range);
    $moves = txCsvMoves($userId, $range);
    Audit::log('data.exported', 'transactions_csv', null, ['kind' => 'csv']);
} catch (Throwable $e) {
    Log::error('export_csv', $e);
    redirectWithMessage('data.php', 'error', 'خروجی گرفته نشد. اگر تکرار شد به پشتیبانی خبر بدهید.');
}

txCsvStream($stmt, $moves);
exit;
