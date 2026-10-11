<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();

header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'ابتدا وارد حساب کاربری خود شوید.'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405);
}

Csrf::verifyOrFail(postParam('csrf_token'));

// ⛔ قفلِ صفحه بدونِ این فقط تزئین است: کسی که آدرسِ اندپوینت را
//    بداند مستقیم صدایش می‌زند.
require_once __DIR__ . '/../includes/plan_gate.php';
apiRequirePlan('cheques');

$userId = Auth::userId();

$direction        = postParam('direction');
$counterpartyName = postParam('counterparty_name');
$rawAmount        = postParam('amount');
$bankId           = postParam('bank_id');
$sayadiNumber     = postParam('sayadi_number');
$chequeNumber     = postParam('cheque_number');
$note             = postParam('note');
$dueDate          = postParam('due_date');
// ⛔ «این چک را چطور گرفتیم؟» (`migration_cheque_value`) — روش، درصدِ ماهانه، و سری
$hasValue    = tableHasColumn('cheques', 'value_method');
$seriesCount = $hasValue ? max(1, (int)toLatinDigits(postParam('series_count', '1'))) : 1;
$isLoan      = $hasValue && postParam('value_method') === 'loan';

$errors = [];
if ($seriesCount > CHEQUE_SERIES_MAX) {
    $errors[] = 'حداکثر ' . toPersianDigits((string)CHEQUE_SERIES_MAX) . ' چک در یک سری.';
}
[$valueMethod, $valueRate, $valueErr] = $hasValue
    ? chequeValueInput(postParam('value_method'), postParam('value_rate'), (string)$dueDate)
    : [null, null, null];
if ($valueErr !== null) { $errors[] = $valueErr; }
if ($seriesCount > 1 && $dueDate === '') { $errors[] = 'برای سری چک، سررسیدِ اولین چک لازم است.'; }
// اقساطِ وام: مبلغِ هر چک از اصل و درصد ساخته می‌شود، نه از فیلدِ مبلغ
$loanPrincipal = 0;
$loanFrom      = '';
if ($isLoan) {
    $rawAmount     = postParam('loan_principal');
    $loanPrincipal = sanitizeAmount($rawAmount);
    $loanFrom      = (string)postParam('loan_from', today());
    if (!isValidDate($loanFrom)) { $errors[] = 'تاریخِ دادنِ پول نامعتبر است.'; }
    elseif ($dueDate !== '' && $loanFrom >= $dueDate) { $errors[] = 'تاریخِ دادنِ پول باید پیش از سررسیدِ اولین چک باشد.'; }
}

if (!in_array($direction, ['received', 'issued'], true)) {
    $errors[] = 'نوع چک نامعتبر است.';
}

// فقط این دو مورد اجباری هستند
if ($counterpartyName === '' || mb_strlen($counterpartyName) > 150) {
    $errors[] = 'نام شخص الزامی است و باید کمتر از ۱۵۰ کاراکتر باشد.';
}

$amount = sanitizeAmount($rawAmount);
if (($ae = amountInputError($rawAmount)) !== null) {
    $errors[] = $ae;
} elseif ($amount <= 0) {
    $errors[] = 'مبلغ باید بزرگ‌تر از صفر باشد.';
}
if ($amount > 999999999999) {
    $errors[] = 'مبلغ وارد شده بیش از حد بزرگ است.';
}

// موارد اختیاری
if ($dueDate !== '' && !isValidDate($dueDate)) {
    $errors[] = 'تاریخ سررسید نامعتبر است.';
}
if (mb_strlen($sayadiNumber) > 30) {
    $errors[] = 'شماره صیادی نباید بیشتر از ۳۰ کاراکتر باشد.';
}
if (mb_strlen($chequeNumber) > 30) {
    $errors[] = 'شماره چک نباید بیشتر از ۳۰ کاراکتر باشد.';
}
if (mb_strlen($note) > 1000) {
    $errors[] = 'توضیحات نباید بیشتر از ۱۰۰۰ کاراکتر باشد.';
}

if (!empty($errors)) {
    jsonResponse(['success' => false, 'message' => implode(' ', $errors)], 422);
}

$pdo = Database::getConnection();

// بانک اختیاری است، ولی اگر داده شد باید مال همین کاربر و از scope درست باشد
$bankIdValue = null;
if ($bankId !== '' && (int)$bankId > 0) {
    $expectedScope = $direction === 'issued' ? 'mine' : 'external';
    $bankStmt = $pdo->prepare('SELECT id FROM banks WHERE id = :id AND user_id = :user_id AND scope = :scope');
    $bankStmt->execute(['id' => (int)$bankId, 'user_id' => $userId, 'scope' => $expectedScope]);
    if ($bankStmt->fetch()) {
        $bankIdValue = (int)$bankId;
    }
}

// ⛔ هر چکِ سری یک ردیفِ واقعی است (پاس، برگشت، یادآوری و سررسیدِ خودش)؛ سری فقط
//    برچسبِ «۳ از ۱۰» و `series_id` است. همه در **یک** تراکنش: یا همه یا هیچ.
$plan = [];
if ($isLoan) {
    foreach (chequeLoanPlan($loanPrincipal, (float)$valueRate, $seriesCount, $loanFrom, $dueDate) as $x) {
        if ($x['amount'] <= 0 || $x['amount'] > 999999999999) {
            jsonResponse(['success' => false, 'message' => 'مبلغِ اصل برای این تعداد قسط معتبر نیست.'], 422);
        }
        $plan[] = $x;
    }
} else {
    foreach ($dueDate !== '' ? chequeSeriesDates($dueDate, $seriesCount) : [''] as $d) {
        $plan[] = ['due' => $d, 'amount' => $amount, 'base' => null, 'from' => null];
    }
}
// شماره‌ی چکِ عددی در سری یکی‌یکی جلو می‌رود؛ صیادی یکتای هر برگ است، پس فقط برای چکِ تنها
$numBase = toLatinDigits($chequeNumber);
$numStep = count($plan) > 1 && preg_match('/^\d{1,18}$/', $numBase);

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare($hasValue
        ? 'INSERT INTO cheques (user_id, direction, counterparty_name, amount, bank_id, sayadi_number, cheque_number, note, due_date,
                                value_method, value_rate, value_base, value_from, series_no)
           VALUES (:user_id, :direction, :counterparty_name, :amount, :bank_id, :sayadi_number, :cheque_number, :note, :due_date,
                   :vm, :vr, :vb, :vf, :sn)'
        : 'INSERT INTO cheques (user_id, direction, counterparty_name, amount, bank_id, sayadi_number, cheque_number, note, due_date)
           VALUES (:user_id, :direction, :counterparty_name, :amount, :bank_id, :sayadi_number, :cheque_number, :note, :due_date)');
    $seriesId = null;
    foreach ($plan as $i => $x) {
        $args = [
            'user_id'           => $userId,
            'direction'         => $direction,
            'counterparty_name' => $counterpartyName,
            'amount'            => $x['amount'],
            'bank_id'           => $bankIdValue,
            'sayadi_number'     => $sayadiNumber !== '' && count($plan) === 1 ? toLatinDigits($sayadiNumber) : null,
            'cheque_number'     => $chequeNumber === '' ? null
                                   : ($numStep ? (string)((int)$numBase + $i) : ($i === 0 ? $numBase : null)),
            'note'              => $note !== '' ? $note : null,
            'due_date'          => $x['due'] !== '' ? $x['due'] : null,
        ];
        if ($hasValue) {
            $args += ['vm' => $valueMethod, 'vr' => $valueRate, 'vb' => $x['base'], 'vf' => $x['from'],
                      'sn' => count($plan) > 1 ? $i + 1 : null];
        }
        $stmt->execute($args);
        $id = (int)$pdo->lastInsertId();
        if (count($plan) > 1) {
            $seriesId ??= $id;
            $pdo->prepare('UPDATE cheques SET series_id = :s WHERE id = :id AND user_id = :u')
                ->execute(['s' => $seriesId, 'id' => $id, 'u' => $userId]);
        }
    }
    $pdo->commit();

    jsonResponse(['success' => true, 'message' => count($plan) > 1
        ? toPersianDigits((string)count($plan)) . ' چک ثبت شد' . ($isLoan ? '؛ هر کدام ' . formatMoney($plan[0]['amount']) . ' تومان.' : '.')
        : 'چک با موفقیت ثبت شد.']);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    Log::error('api.add_cheque', $e);
    jsonResponse(['success' => false, 'message' => 'خطایی در ثبت چک رخ داد.'], 500);
}
