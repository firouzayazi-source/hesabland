<?php
/**
 * پیش‌نمایشِ «ارزشِ امروز» در فرمِ ثبتِ چک — فقط خواندنی (هیچ نوشتنی، هیچ کوئری‌ای).
 *
 * ⛔ چرا اندپوینت و نه حسابِ جاوااسکریپتی: حسابِ پول یک جا دارد
 *    (`chequePresentValue()`، `chequeLoanPlan()`)؛ نسخه‌ی دومی در مرورگر دیر یا زود عددِ
 *    دیگری می‌گفت و کاربر در فرم یک عدد و در کارت عددِ دیگر می‌دید.
 *    عمداً CSRF ندارد (فقط‌خواندنی، در `$csrfExempt`ِ `test_api_contract`).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');
if (!Auth::isLoggedIn()) { jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401); }

$due    = (string)getParam('due_date');
$count  = max(1, min(CHEQUE_SERIES_MAX, (int)toLatinDigits((string)getParam('series_count', '1'))));
$isLoan = getParam('value_method') === 'loan';
if ($due !== '' && !isValidDate($due)) { jsonResponse(['success' => false, 'message' => 'تاریخ سررسید نامعتبر است.']); }
[$method, $rate, $err] = chequeValueInput((string)getParam('value_method'), (string)getParam('value_rate'), $due);
if ($err !== null) { jsonResponse(['success' => false, 'message' => $err]); }
if ($due === '') { jsonResponse(['success' => false, 'message' => '']); }

$today = today();
if ($isLoan) {
    $principal = sanitizeAmount((string)getParam('loan_principal'));
    $from      = (string)getParam('loan_from', $today);
    if ($principal <= 0 || !isValidDate($from) || $from >= $due) { jsonResponse(['success' => false, 'message' => '']); }
    $plan = chequeLoanPlan($principal, (float)$rate, $count, $from, $due);
} else {
    $amount = sanitizeAmount((string)getParam('amount'));
    if ($amount <= 0) { jsonResponse(['success' => false, 'message' => '']); }
    $plan = array_map(fn($d) => ['due' => $d, 'amount' => $amount, 'base' => null, 'from' => null], chequeSeriesDates($due, $count));
}

$face = 0; $value = 0;
foreach ($plan as $x) {
    $face  += $x['amount'];
    $value += chequePresentValue(['amount' => $x['amount'], 'due_date' => $x['due'], 'value_method' => $method,
                                  'value_rate' => $rate, 'value_base' => $x['base'], 'value_from' => $x['from']], $today);
}
$last = end($plan);
jsonResponse([
    'success' => true,
    'count'   => count($plan),
    'each'    => formatMoney($plan[0]['amount']),
    'face'    => formatMoney($face),
    'value'   => formatMoney($value),
    'profit'  => formatMoney($face - $value),
    'first'   => toJalali($plan[0]['due']),
    'last'    => toJalali($last['due']),
]);
