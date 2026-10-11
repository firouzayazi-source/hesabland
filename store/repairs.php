<?php
/**
 * تعمیرات و گارانتی — فهرستِ قبض‌های پذیرش و استعلامِ گارانتی با IMEI.
 *
 * منطق فقط `BizRepairs` و `BizWarranty`؛ این صفحه فهرست و فرم است.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_repairs.php';

$userId = Biz::ownerId();
$filter = (string)getParam('f', 'open');
if ($filter !== 'open' && $filter !== 'all' && !isset(BizRepairs::STATUSES[$filter])) { $filter = 'open'; }
$q      = mb_substr(trim(getParam('q')), 0, 80);
$page   = max(1, (int)getParam('page', '1'));
$list   = BizRepairs::list($userId, $filter === 'all' ? '' : $filter, $q, $page);
$counts = BizRepairs::counts($userId);
$wImei  = trim(getParam('w'));
$wRes   = $wImei !== '' ? BizWarranty::lookup($userId, $wImei) : null;

$pageTitle = 'تعمیرات و گارانتی';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <div>
        <h1 class="st-h1">تعمیرات و گارانتی</h1>
        <p class="st-muted">قبضِ پذیرش با IMEI و وضعیت؛ هزینه‌ی تعمیر یک فاکتورِ فروشِ معمولی است (موجودیِ قطعه و مانده‌ی مشتری از همان‌جا).</p>
    </div>
    <div class="st-head-actions"><a class="st-btn" href="<?= h(Biz::url('repair.php')) ?>">+ پذیرشِ تعمیر</a></div>
</div>

<?php if (!BizRepairs::ready()): ?>
    <div class="st-flash st-flash-err">این بخش هنوز راه نیفتاده است؛ مدیرِ نصب باید به‌روزرسانی را با migration اجرا کند.</div>
<?php else: ?>

<section class="st-card" id="warranty">
    <h2 class="st-h2">استعلامِ گارانتی</h2>
    <form class="st-inline-form" method="get" action="<?= h(Biz::url('repairs.php')) ?>#warranty" role="search">
        <label class="st-field st-grow"><span>IMEIِ گوشی</span><input type="text" name="w" inputmode="numeric" dir="ltr" maxlength="20" value="<?= h($wImei) ?>" placeholder="۱۵ رقم (روی جعبه یا با *#06#)"></label>
        <button type="submit" class="st-btn st-btn-ghost">استعلام</button>
    </form>
    <?php if ($wImei !== ''): ?>
    <div class="st-warranty-res" role="status">
        <?php if ($wRes === null): ?>
            <p class="st-flash st-flash-err">IMEI باید ۱۴ تا ۱۷ رقم باشد.</p>
        <?php elseif ($wRes['state'] === 'unknown'): ?>
            <p class="st-muted">این گوشی در فاکتورهای این فروشگاه نیست.</p>
        <?php elseif ($wRes['state'] !== 'sold'): ?>
            <p class="st-muted"><?= $wRes['state'] === 'in_stock' ? 'این گوشی الان در انبارِ همین فروشگاه است (فروخته نشده).' : 'آخرین سندِ این گوشی فروش نیست (برگشت به فروشنده).' ?></p>
        <?php else: ?>
            <p class="st-warranty-head <?= $wRes['valid'] ? 'is-ok' : 'is-off' ?>">
                <?= $wRes['months'] === 0 ? 'بی‌گارانتی فروخته شده' : ($wRes['valid'] ? 'گارانتی دارد' : 'گارانتی تمام شده') ?>
            </p>
            <dl class="st-dl">
                <dt>کالا</dt><dd><?= h($wRes['product']) ?></dd>
                <dt>فاکتور</dt><dd><a class="st-row-link" href="<?= h(Biz::url('invoice.php?id=' . $wRes['invoice_id'])) ?>">فروشِ <?= toPersianDigits((string)$wRes['number']) ?></a> · <?= h(toJalali($wRes['sold_on'])) ?></dd>
                <?php if ($wRes['party'] !== ''): ?><dt>خریدار</dt><dd><?= h($wRes['party']) ?></dd><?php endif; ?>
                <?php if ($wRes['months'] > 0): ?>
                <dt>گارانتی</dt><dd><?= toPersianDigits((string)$wRes['months']) ?> ماه<?= $wRes['label'] !== '' ? ' · ' . h($wRes['label']) : '' ?></dd>
                <dt>تا</dt><dd><?= h(toJalali($wRes['until'])) ?></dd>
                <?php endif; ?>
            </dl>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</section>

<form class="st-filters" method="get" action="<?= h(Biz::url('repairs.php')) ?>" role="search">
    <input type="hidden" name="f" value="<?= h($filter) ?>">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="شماره‌ی قبض، دستگاه، IMEI، نام یا تلفنِ مشتری…" aria-label="جست‌وجو">
    <button type="submit" class="st-btn st-btn-ghost">بگرد</button>
</form>

<nav class="st-tabs" aria-label="وضعیت">
    <?php
    $openN = array_sum(array_intersect_key($counts, array_flip(BizRepairs::OPEN)));
    $chips = ['open' => 'باز (' . toPersianDigits((string)$openN) . ')'];
    foreach (BizRepairs::STATUSES as $sk => $sl) { $chips[$sk] = $sl . (isset($counts[$sk]) ? ' (' . toPersianDigits((string)$counts[$sk]) . ')' : ''); }
    $chips['all'] = 'همه';
    foreach ($chips as $ck => $cl): ?>
        <a href="<?= h(Biz::url('repairs.php') . '?' . http_build_query(array_filter(['f' => $ck, 'q' => $q], fn($v) => $v !== ''))) ?>" class="st-tab-chip<?= $ck === $filter ? ' is-active' : '' ?>"><?= h($cl) ?></a>
    <?php endforeach; ?>
</nav>

<?php if (!$list['rows']): ?>
    <div class="st-card st-empty-card"><p class="st-empty"><?= $q !== '' ? 'قبضی با این مشخصات پیدا نشد.' : 'قبضی در این وضعیت نیست.' ?></p></div>
<?php else: ?>
<div class="st-table-wrap">
    <table class="st-table st-table-compact">
        <thead><tr><th>قبض</th><th>دستگاه</th><th>مشتری</th><th>پذیرش</th><th>وضعیت</th><th class="st-th-num st-hide-sm">برآورد</th></tr></thead>
        <tbody>
        <?php foreach ($list['rows'] as $r): ?>
            <tr class="<?= in_array($r['status'], ['delivered', 'cancelled'], true) ? 'is-inactive' : '' ?>">
                <td><a class="st-row-link" href="<?= h(Biz::url('repair.php?id=' . (int)$r['id'])) ?>"><span class="st-num"><?= toPersianDigits((string)$r['number']) ?></span></a></td>
                <td><?= h((string)$r['device']) ?><?php if ($r['imei']): ?><span class="st-imei" dir="ltr">IMEI <?= h((string)$r['imei']) ?></span><?php endif; ?></td>
                <td><?= h(BizRepairs::customer($r)) ?></td>
                <td><span class="st-num"><?= h(toJalali((string)$r['received_on'])) ?></span></td>
                <td><span class="st-pill is-rep-<?= h((string)$r['status']) ?>"><?= h(BizRepairs::STATUSES[$r['status']] ?? $r['status']) ?></span></td>
                <td class="st-td-num st-hide-sm"><?= (int)$r['estimate'] > 0 ? BizDocView::money((int)$r['estimate']) : '<span class="st-muted-i">—</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?= BizView::pager('repairs.php', ['f' => $filter, 'q' => $q], $list['page'], $list['pages'], $list['total']) ?>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
