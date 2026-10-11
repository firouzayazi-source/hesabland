<?php
/**
 * اقساطِ مشتریان — قسط‌های باز تا یک بازه (سررسیدگذشته‌ها همیشه بالا).
 *
 * فقط خواندنی؛ «دریافت» همان صفحه‌ی همیشگیِ دریافت برای همان فاکتور است.
 * منطق فقط `BizInstallments::open()`.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_docview.php';
require_once __DIR__ . '/../includes/biz_sales_ext.php';

$userId  = Biz::ownerId();
$today   = today();
$days    = (int)getParam('d', '30');
$days    = in_array($days, [7, 30, 90, 365], true) ? $days : 30;
$until   = date('Y-m-d', strtotime($today . " +{$days} days"));
$rows    = BizInstallments::open($userId, $until, $today);
$overdue = array_filter($rows, fn($r) => $r['state'] === 'overdue');
$sumDue  = array_sum(array_column($rows, 'remaining'));
$sumOver = array_sum(array_column($overdue, 'remaining'));

$pageTitle = 'اقساطِ مشتریان';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <div>
        <h1 class="st-h1">اقساطِ مشتریان</h1>
        <p class="st-muted">برنامه‌ی اقساط روی صفحه‌ی هر فاکتورِ فروش ساخته می‌شود؛ هر دریافت از مشتری قسط‌ها را به ترتیبِ سررسید پر می‌کند.</p>
    </div>
</div>

<?php if (!BizInstallments::ready()): ?>
    <div class="st-flash st-flash-err">این بخش هنوز راه نیفتاده است؛ مدیرِ نصب باید به‌روزرسانی را با migration اجرا کند.</div>
<?php else: ?>
<div class="st-kpis st-kpis-3">
    <div class="st-kpi-card"><span class="st-kpi-label">سررسید گذشته</span><span class="st-kpi-value st-num<?= $sumOver > 0 ? ' is-neg' : '' ?>"><?= formatMoney($sumOver) ?></span><span class="st-kpi-sub"><?= toPersianDigits((string)count($overdue)) ?> قسط · تومان</span></div>
    <div class="st-kpi-card"><span class="st-kpi-label">تا <?= toPersianDigits((string)$days) ?> روزِ آینده</span><span class="st-kpi-value st-num"><?= formatMoney($sumDue) ?></span><span class="st-kpi-sub"><?= toPersianDigits((string)count($rows)) ?> قسط · تومان</span></div>
</div>

<nav class="st-tabs" aria-label="بازه">
    <?php foreach ([7 => 'یک هفته', 30 => 'یک ماه', 90 => 'سه ماه', 365 => 'یک سال'] as $dk => $dl): ?>
        <a href="<?= h(Biz::url('installments.php?d=' . $dk)) ?>" class="st-tab-chip<?= $dk === $days ? ' is-active' : '' ?>"><?= h($dl) ?></a>
    <?php endforeach; ?>
</nav>

<?php if (!$rows): ?>
    <div class="st-card st-empty-card"><p class="st-empty">قسطِ بازی در این بازه نیست.</p></div>
<?php else: ?>
<div class="st-table-wrap">
    <table class="st-table st-table-compact">
        <thead><tr><th>سررسید</th><th>مشتری</th><th>فاکتور</th><th class="st-th-num">مانده‌ی قسط</th><th>وضعیت</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><span class="st-num"><?= h(toJalali($r['due_date'])) ?></span></td>
                <td><?= h($r['party_name']) ?><?php if ($r['party_phone'] !== ''): ?><span class="st-muted-i" dir="ltr"> <?= h($r['party_phone']) ?></span><?php endif; ?></td>
                <td><a class="st-row-link" href="<?= h(Biz::url('invoice.php?id=' . $r['invoice_id'])) ?>#installments">فروشِ <?= toPersianDigits((string)$r['number']) ?> · قسطِ <?= toPersianDigits((string)$r['seq']) ?></a></td>
                <td class="st-td-num"><?= BizDocView::money($r['remaining']) ?></td>
                <td><span class="st-pill is-inst-<?= h($r['state']) ?>"><?= h(BizInstallments::STATES[$r['state']]) ?></span></td>
                <td><a class="st-btn st-btn-ghost st-btn-sm" href="<?= h(Biz::url('payment.php?k=receipt&inv=' . $r['invoice_id'] . '&amount=' . $r['remaining'])) ?>">دریافت</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
