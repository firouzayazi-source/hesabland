<?php
/**
 * ماه‌های قبل برای کارتِ «مانده این ماه»ِ خانه (بالا کشیدن = یک ماه عقب‌تر).
 *
 * ⛔ فقط‌خواندنی است و عمداً CSRF ندارد (فهرستِ `$csrfExempt` در
 *    `test_api_contract.php`). اگر روزی چیزی نوشت، باید اضافه شود.
 * ⛔ عددها فقط از `monthHistory()` — همان تعریفِ عددِ ماهِ جاری (`monthComparison()`).
 * ⛔ صفحه‌ی خانه این را **نمی‌خواند**؛ فقط با اولین لمسِ کارت (`app.js`) گرفته
 *    می‌شود، تا بازدیدِ عادیِ خانه یک کوئری بیشتر نخورد (`test_query_budget`).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!Auth::isLoggedIn()) { jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401); }

$userId = Auth::userId();
$base   = APP_BASE_PATH . '/transactions.php?period=custom';

$months = [];
foreach (monthHistory($userId) as $m) {
    $months[] = [
        'label'   => $m['label'],
        'income'  => formatMoney($m['income']),
        'expense' => formatMoney($m['expense']),
        'net'     => ($m['net'] < 0 ? '−' : '') . formatMoney(abs($m['net'])),
        'neg'     => $m['net'] < 0,
        'url'     => $base . '&from_date=' . $m['from'] . '&to_date=' . $m['to'],
    ];
}
jsonResponse(['success' => true, 'months' => $months]);
