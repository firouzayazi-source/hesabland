<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/home_calendar.php';

Auth::initSession();
Auth::requireLogin();

$pdo = Database::getConnection();
$userId = Auth::userId();

// بررسی و پردازش تراکنش‌های دوره‌ای سررسیدشده (بدون نیاز به Cron)
$recurringNeedsAttention = [];
try {
    $recurringNeedsAttention = processRecurringTransactions($userId);
} catch (PDOException $e) {
    $recurringNeedsAttention = []; // جدول هنوز ساخته نشده
}

$today      = today();
$monthStart = startOfJalaliMonth();

// جمعِ روزانه‌ی کل تاریخچه — یک بار خوانده می‌شود و همه‌ی بازه‌های این
// صفحه از رویش ساخته می‌شوند (`dashDailyRows()`، مشترک با خانه‌ی دسکتاپ).
require_once __DIR__ . '/includes/dash_parts.php';
$dailyRows = dashDailyRows($userId);

function getPeriodStats(array $dailyRows, string $fromDate, string $toDate): array
{
    $income = 0; $expense = 0;
    foreach ($dailyRows as $row) {
        $d = $row['transaction_date'];
        if ($d < $fromDate || $d > $toDate) { continue; }
        $income  += (int)$row['daily_income'];
        $expense += (int)$row['daily_expense'];
    }

    return ['income' => $income, 'expense' => $expense, 'net' => $income - $expense];
}

$monthStats = getPeriodStats($dailyRows, $monthStart, $today);

// ---------- کارتِ سالانه‌ی چهارفصل ---------- (`dashYearState()`)
$yearState = dashYearState($dailyRows, $today, getParam('y', ''));

// ---------- بخش گزارش با بازه دلخواه ----------
$customRangeSubmitted = isset($_GET['from_date']) || isset($_GET['to_date']);

$fromDate = getParam('from_date', $monthStart);
$toDate   = getParam('to_date', $today);

if (!isValidDate($fromDate)) $fromDate = $monthStart;
if (!isValidDate($toDate)) $toDate = $today;
if ($fromDate > $toDate) {
    [$fromDate, $toDate] = [$toDate, $fromDate];
}

$rangeStats = getPeriodStats($dailyRows, $fromDate, $toDate);
// ⛔ وصولِ طلب/چک و پرداختِ بدهی/چک جدا از درآمد و هزینه (`moneyMovesTotals()`):
//    پول آمده و رفته، ولی «سودِ خالص» را تکان نمی‌دهد.
require_once __DIR__ . '/includes/tx_query.php';
$rangeMoves = moneyMovesTotals($userId, $fromDate, $toDate);

$incomeCount = 0; $expenseCount = 0;
foreach ($dailyRows as $row) {
    if ($row['transaction_date'] < $fromDate || $row['transaction_date'] > $toDate) { continue; }
    $incomeCount  += (int)$row['income_count'];
    $expenseCount += (int)$row['expense_count'];
}

$rangeDailyRows = array_values(array_filter(
    $dailyRows,
    fn($r) => $r['transaction_date'] >= $fromDate && $r['transaction_date'] <= $toDate
));


// ---------- یادآوری طلب/بدهی نزدیک به سررسید یا سررسیدگذشته ----------
$reminderStmt = $pdo->prepare('
    SELECT id, direction, counterparty_name, amount, paid_amount, due_date
    FROM debts
    WHERE user_id = :user_id AND is_settled = 0 AND due_date <= :soon
    ORDER BY due_date ASC
    LIMIT 5
');
$reminderStmt->execute(['user_id' => $userId, 'soon' => date('Y-m-d', strtotime('+7 days'))]);
$debtReminders = $reminderStmt->fetchAll();

$chequeReminders = [];
try {
    $chRemStmt = $pdo->prepare('
        SELECT id, direction, counterparty_name, amount, due_date
        FROM cheques
        WHERE user_id = :user_id AND ' . chequeActiveSql() . ' AND due_date IS NOT NULL AND due_date <= :soon
        ORDER BY due_date ASC
        LIMIT 5
    ');
    $chRemStmt->execute(['user_id' => $userId, 'soon' => date('Y-m-d', strtotime('+7 days'))]);
    $chequeReminders = $chRemStmt->fetchAll();
} catch (PDOException $e) {
    $chequeReminders = [];
}

// موجودی حساب‌ها عمداً اینجا نیست: جایش صفحه‌ی «حساب‌ها و انتقال» است.
// در گزارش دو بار یک عدد نشان دادن، فقط شلوغی بود.

// گزارش مقایسه‌ای و بینش هزینه
$comparison = null;
$insights = null;
try {
    $comparison = monthComparison($userId);
    $insights = spendingInsights($userId, startOfJalaliMonth(), $today);
} catch (PDOException $e) {
    $comparison = null;
    $insights = null;
}

$pageTitle = 'داشبورد';
include __DIR__ . '/includes/header.php';
?>

<?php /* ⛔ تقویم — بالای «گزارش» (خواسته‌ی مالکِ نصب: «در گوشی بهتره که در
         بخش گزارش‌ها کار بشه همون بالا چون صفحه خانه در گوشی خیلی شلوغ
         میشه»). پوسته بی‌کوئری است و داده از `api/home_calendar.php` می‌آید،
         پس بودجه‌ی کوئریِ این صفحه عوض نشد. */ ?>
<?php [$__calJy, $__calJm] = homeCalendarMonth((int)getParam('jy', '0'), (int)getParam('jm', '0')); ?>
<?php renderHomeCalendar($__calJy, $__calJm); ?>

<?php if (!empty($debtReminders)): ?>
<div class="card" style="border-color: var(--color-expense);">
    <h2 class="card-title" style="color: var(--color-expense);">⏰ یادآوری طلب و بدهی</h2>
    <?php foreach ($debtReminders as $r): ?>
        <?php $overdue = $r['due_date'] < today(); ?>
        <div class="stats-row" style="border-bottom: 1px solid var(--color-gray-100); padding: 8px 0;">
            <span class="stats-label">
                <?= $r['direction'] === 'receivable' ? 'طلب از' : 'بدهی به' ?> <?= h($r['counterparty_name']) ?>
                — <?= $overdue ? '<span style="color:var(--color-expense); font-weight:700;">سررسید گذشته</span>' : 'سررسید ' . toJalali($r['due_date']) ?>
            </span>
            <?php /* ⛔ مانده، نه کلِ مبلغ (`debtRemaining()`) — بدهیِ ۱٬۰۰۰٬۰۰۰ که ۹۰۰٬۰۰۰اش پرداخت شده ۱۰۰٬۰۰۰ است، همان عددِ «سررسیدها» */ ?>
            <span class="stats-value" style="direction:ltr;"><?= formatMoney(debtRemaining($r)) ?> <small>تومان</small></span>
        </div>
    <?php endforeach; ?>
    <a href="debts.php" class="link-more" style="display:inline-block; margin-top:10px;">مدیریت طلب و بدهی ←</a>
</div>
<?php endif; ?>

<?php if (!empty($chequeReminders)): ?>
<div class="card" style="border-color: var(--color-expense);">
    <h2 class="card-title" style="color: var(--color-expense);">⏰ یادآوری چک‌ها</h2>
    <?php foreach ($chequeReminders as $r): ?>
        <?php $overdue = $r['due_date'] < today(); ?>
        <div class="stats-row" style="border-bottom: 1px solid var(--color-gray-100); padding: 8px 0;">
            <span class="stats-label">
                چک <?= $r['direction'] === 'received' ? 'دریافتی از' : 'صادره به' ?> <?= h($r['counterparty_name']) ?>
                — <?= $overdue ? '<span style="color:var(--color-expense); font-weight:700;">سررسید گذشته</span>' : 'سررسید ' . toJalali($r['due_date']) ?>
            </span>
            <span class="stats-value" style="direction:ltr;"><?= formatMoney($r['amount']) ?> <small>تومان</small></span>
        </div>
    <?php endforeach; ?>
    <a href="cheques.php" class="link-more" style="display:inline-block; margin-top:10px;">مدیریت چک‌ها ←</a>
</div>
<?php endif; ?>

<?php if (!empty($recurringNeedsAttention)): ?>
<div class="card" style="border: 1px solid #d97706;">
    <h2 class="card-title" style="color:#d97706;">🔁 تراکنش‌های دوره‌ای در انتظار</h2>
    <p class="hint" style="margin-bottom:10px;"><?= toPersianDigits(count($recurringNeedsAttention)) ?> مورد سررسید شده — بررسی کنید.</p>
    <a href="recurring.php" class="link-more">مشاهده و تأیید ←</a>
</div>
<?php endif; ?>



<?php
renderYearReportCard($yearState, 'dashboard.php');
renderBreakdownCta($monthStats['income'], $monthStats['expense']);
renderTrendCard($dailyRows);
renderMonthComparisonCard($comparison, $insights);
?>

<div class="card collapsible-card <?= $customRangeSubmitted ? '' : 'collapsed' ?>">
    <div class="collapsible-header">
        <h2 class="card-title" style="margin-bottom:0;">گزارش با بازه دلخواه</h2>
        <span class="collapse-chevron">▾</span>
    </div>
    <div class="collapsible-body">
    <form method="GET" class="report-form">
        <div class="form-group">
            <label>از تاریخ</label>
            <div class="jdp-field">
                <input type="text" class="jdp-display" readonly value="<?= toJalali($fromDate) ?>">
                <input type="hidden" class="jdp-hidden" name="from_date" value="<?= h($fromDate) ?>">
            </div>
        </div>
        <div class="form-group">
            <label>تا تاریخ</label>
            <div class="jdp-field">
                <input type="text" class="jdp-display" readonly value="<?= toJalali($toDate) ?>">
                <input type="hidden" class="jdp-hidden" name="to_date" value="<?= h($toDate) ?>">
            </div>
        </div>
        <div style="display:flex; gap:8px;">
            <button type="submit" class="btn btn-primary">نمایش گزارش</button>
            <a href="export_transactions.php?from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>" class="btn btn-secondary">دانلود اکسل</a>
        </div>
    </form>

    <div class="summary-grid">
        <div class="summary-box"><div class="label">مجموع درآمد</div><div class="value stats-income"><?= formatMoney($rangeStats['income']) ?></div></div>
        <div class="summary-box"><div class="label">مجموع هزینه</div><div class="value stats-expense"><?= formatMoney($rangeStats['expense']) ?></div></div>
        <div class="summary-box"><div class="label">سود خالص</div><div class="value <?= $rangeStats['net'] >= 0 ? 'stats-net-positive' : 'stats-net-negative' ?>"><?= formatMoney($rangeStats['net']) ?></div></div>
        <div class="summary-box"><div class="label">تعداد درآمدها</div><div class="value"><?= toPersianDigits($incomeCount) ?></div></div>
        <div class="summary-box"><div class="label">تعداد هزینه‌ها</div><div class="value"><?= toPersianDigits($expenseCount) ?></div></div>
        <?php if ($rangeMoves['count'] > 0): ?>
        <div class="summary-box"><div class="label">دریافت از طلب و چک</div><div class="value stats-income"><?= formatMoney($rangeMoves['in']) ?></div></div>
        <div class="summary-box"><div class="label">پرداختِ بدهی و چک</div><div class="value stats-expense"><?= formatMoney($rangeMoves['out']) ?></div></div>
        <?php endif; ?>
    </div>
    <?php if ($rangeMoves['count'] > 0): ?>
    <p class="hint">وصولِ طلب، پرداختِ بدهی و چکِ پاس‌شده پولِ حساب را جابه‌جا کرده‌اند ولی درآمد یا هزینه نیستند، پس در «سود خالص» نمی‌آیند. فهرستشان: <a href="<?= h(APP_BASE_PATH) ?>/transactions.php?type=move&amp;period=custom&amp;from_date=<?= urlencode($fromDate) ?>&amp;to_date=<?= urlencode($toDate) ?>">وصول و پرداخت</a></p>
    <?php endif; ?>

    <?php if (empty($rangeDailyRows) && $rangeMoves['count'] === 0): ?>
        <p style="text-align:center; color:var(--color-gray-500); padding:20px 0;">برای این بازه تراکنشی ثبت نشده است.</p>
    <?php endif; ?>
    </div>
</div>

<?php /* ⛔ ردیف‌های تراکنش اینجا هم دکمه‌ی «ویرایش» دارند (جزئیاتِ روزِ تقویم
         در گزارش، و نتیجه‌ی جست‌وجو)؛ بی این مودال آن دکمه بی‌صدا هیچ
         کاری نمی‌کرد. دسته‌ها از `cachedCategories()` — کوئریِ تازه‌ای نیست. */ ?>
<?php include __DIR__ . '/includes/edit_tx_modal.php'; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
