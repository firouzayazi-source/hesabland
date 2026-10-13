<?php
/**
 * پیشنهادِ خرید — «چه بخرم»: کفِ موجودی و سرعتِ فروشِ روزهای اخیر.
 *
 * منطق فقط `BizReorder`. ⛔ «سفارش» فقط **پیش‌نویسِ** فاکتورِ خرید است (یکی برای هر فروشنده) و
 * تا رسیدنِ کالا و صدورش هیچ اثری بر موجودی و مانده ندارد.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_docview.php';
require_once __DIR__ . '/../includes/biz_insight.php';

$userId = Biz::ownerId();
$days   = (int)getParam('d', (string)BizReorder::DAYS);
$days   = in_array($days, [14, 30, 60, 90], true) ? $days : BizReorder::DAYS;
$cover  = (int)getParam('c', (string)BizReorder::COVER);
$cover  = in_array($cover, [7, 14, 30, 60], true) ? $cover : BizReorder::COVER;
$self   = Biz::url('reorder.php?d=' . $days . '&c=' . $cover);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $pick = array_map('intval', array_keys(array_filter((array)($_POST['pick'] ?? []), fn($v) => $v === '1')));
    $qtys = [];
    foreach ($pick as $pid) { $qtys[$pid] = (string)($_POST['qty'][$pid] ?? ''); }
    $sups = array_map('intval', (array)($_POST['sup'] ?? []));
    $r = BizReorder::makeDrafts($userId, $qtys, $sups);
    if ($r['ok'] && count($r['drafts']) === 1) { redirectWithMessage(Biz::url('invoice-edit.php?id=' . $r['drafts'][0]), 'success', $r['message']); }
    redirectWithMessage($r['ok'] ? Biz::url('purchases.php') : $self, $r['ok'] ? 'success' : 'error', $r['message']);
}

$rows = BizReorder::suggest($userId, $days, $cover);
$suppliers = BizQuickBuy::suppliers($userId);

$pageTitle = 'پیشنهادِ خرید';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <div>
        <h1 class="st-h1">پیشنهادِ خرید</h1>
        <p class="st-muted">کالاهایی که زیرِ «حداقل موجودی» رفته‌اند یا با سرعتِ فروشِ <?= toPersianDigits((string)$days) ?> روزِ اخیر کمتر از <?= toPersianDigits((string)$cover) ?> روز دوام می‌آورند. از انتخاب‌شده‌ها برای هر فروشنده یک پیش‌نویسِ فاکتورِ خرید ساخته می‌شود؛ با رسیدنِ کالا صادرش کنید.</p>
    </div>
</div>

<nav class="st-tabs" aria-label="سرعتِ فروش">
    <?php foreach ([14 => 'دو هفته', 30 => 'یک ماه', 60 => 'دو ماه', 90 => 'سه ماه'] as $dk => $dl): ?>
        <a class="st-tab-chip<?= $dk === $days ? ' is-active' : '' ?>" href="<?= h(Biz::url('reorder.php?d=' . $dk . '&c=' . $cover)) ?>">فروشِ <?= h($dl) ?></a>
    <?php endforeach; ?>
</nav>
<nav class="st-tabs" aria-label="پوشش">
    <?php foreach ([7 => 'یک هفته', 14 => 'دو هفته', 30 => 'یک ماه', 60 => 'دو ماه'] as $ck => $cl): ?>
        <a class="st-tab-chip<?= $ck === $cover ? ' is-active' : '' ?>" href="<?= h(Biz::url('reorder.php?d=' . $days . '&c=' . $ck)) ?>">برای <?= h($cl) ?></a>
    <?php endforeach; ?>
</nav>

<?php if (!$rows): ?>
    <div class="st-card st-empty-card"><p class="st-empty">فعلاً کالایی کمبود ندارد. برای هشدارِ زودتر، روی کالاها «حداقل موجودی» بگذارید.</p></div>
<?php else: ?>
<form method="post" action="<?= h($self) ?>">
    <?= Csrf::field() ?>
    <div class="st-table-wrap">
        <table class="st-table st-table-compact">
            <thead><tr><th><input type="checkbox" class="js-check-all" data-target="reorder" aria-label="انتخابِ همه" checked></th><th>کالا</th>
                <th class="st-th-num">موجودی</th><th class="st-th-num">فروشِ روزانه</th><th class="st-th-num">دوام</th><th class="st-th-num">پیشنهاد</th><th>فروشنده</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?php if (!$r['serial']): ?><input type="checkbox" name="pick[<?= $r['id'] ?>]" value="1" data-group="reorder" checked aria-label="انتخاب"><?php endif; ?></td>
                    <td><a class="st-row-link" href="<?= h(Biz::url('product.php?id=' . $r['id'])) ?>"><?= h($r['name']) ?></a>
                        <?php if ($r['serial']): ?><br><small class="st-muted-i">گوشی — با IMEI از «+ موجودی» در صفحه‌ی کالا</small><?php endif; ?></td>
                    <td class="st-td-num"><span class="st-num<?= $r['stock'] <= 0 ? ' is-neg' : '' ?>"><?= h(formatQty($r['stock'])) ?></span> <small class="st-muted-i"><?= h($r['unit']) ?></small></td>
                    <td class="st-td-num"><span class="st-num"><?= h(formatQty($r['per_day'])) ?></span></td>
                    <td class="st-td-num"><?= $r['days_left'] === null ? '<small class="st-muted-i">زیرِ حداقل</small>' : '<span class="st-num">' . toPersianDigits((string)$r['days_left']) . '</span> <small class="st-muted-i">روز</small>' ?></td>
                    <td class="st-td-num"><?php if (!$r['serial']): ?><input data-num type="text" name="qty[<?= $r['id'] ?>]" value="<?= h(formatQty($r['suggest'])) ?>" inputmode="decimal" dir="ltr" class="st-input-sm" aria-label="تعدادِ سفارش"><?php else: ?><span class="st-num"><?= h(formatQty($r['suggest'])) ?></span><?php endif; ?></td>
                    <td><?php if (!$r['serial']): ?>
                        <select name="sup[<?= $r['id'] ?>]" aria-label="فروشنده"><option value="0">— بی‌فروشنده —</option>
                            <?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['id'] ?>"<?= (int)$s['id'] === $r['supplier_id'] ? ' selected' : '' ?>><?= h((string)$s['name']) ?></option><?php endforeach; ?>
                        </select><?php else: ?><?= h($r['supplier']) ?><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (Biz::can('buy')): ?><button type="submit" class="st-btn">ساختِ پیش‌نویسِ خرید از انتخاب‌شده‌ها</button><?php endif; ?>
</form>
<?php endif; ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
