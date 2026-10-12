<?php
/**
 * گزارش و چاپ — بالای صفحه گزارشِ فروش و سود (`BizReports`) برای یک بازه،
 * پایینش «همه‌ی گزارش‌ها» — گروه‌بندی‌شده مثلِ نرم‌افزارهای حسابداری، از
 * `BizPrint::HUB` (تنها مرجع).
 *
 * ⛔ بازه با پیوندِ GET عوض می‌شود (`?p=`)، نه جاوااسکریپت؛ `custom` از/تا
 *    را شمسی می‌گیرد. همه‌ی عددها از اسنادِ **صادرشده** و خالصِ برگشت‌اند.
 *
 * هر کارت یک فرمِ GET به `print.php` است، نه جاوااسکریپت: با دکمه‌ی
 * بازگشتِ مرورگر و کپیِ آدرس هم کار می‌کند.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_print.php';
require_once __DIR__ . '/../includes/biz_docview.php';
require_once __DIR__ . '/../includes/biz_reports.php';

$userId   = Biz::ownerId();
$period   = getParam('p');
$period   = isset(BizReports::PERIODS[$period]) ? $period : 'month';
[$rFrom, $rTo] = BizReports::range($period, BizDocView::gDate(getParam('from')), BizDocView::gDate(getParam('to')));
$rSales   = BizReports::sales($userId, $rFrom, $rTo);
$rCash    = BizReports::cash($userId, $rFrom, $rTo);
$rTop     = BizReports::topProducts($userId, $rFrom, $rTo);
$rCats    = BizReports::byCategory($userId, $rFrom, $rTo);
$rExp     = BizReports::expenses($userId, $rFrom, $rTo);
$rAging   = BizReports::aging($userId);
$rPrint   = BizPrint::url('sales', ['p' => $period, 'from' => $period === 'custom' ? BizDocView::jDate($rFrom) : '', 'to' => $period === 'custom' ? BizDocView::jDate($rTo) : '']);
$cats     = BizProducts::categories($userId);
$parties  = BizParties::all($userId, '', 500, false);   // فقط برای منوی صافی
$products = BizProducts::all($userId, '', '', 500);
$prefs    = Biz::printPrefs($userId);
$printUrl = Biz::url('print.php');
$accounts = BizCash::list($userId);
$stockProducts = array_values(array_filter($products['rows'], fn($r) => (int)$r['track_stock'] === 1));
// ⛔ بازه‌ی گزارش‌های دوره‌ای همان بازه‌ی بالای صفحه است — پنهان در هر فرم
$periodHidden = array_filter(['p' => $period, 'from' => $period === 'custom' ? BizDocView::jDate($rFrom) : '',
                              'to' => $period === 'custom' ? BizDocView::jDate($rTo) : ''], fn($v) => $v !== '');

$pageTitle = 'گزارش و چاپ';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <div>
        <h1 class="st-h1">گزارش فروش و سود</h1>
        <p class="st-muted"><span class="st-num"><?= h(BizReports::rangeLabel($rFrom, $rTo)) ?></span></p>
    </div>
    <div class="st-head-actions"><a class="st-btn st-btn-ghost" href="#all">همه‌ی گزارش‌ها</a><a class="st-btn st-btn-ghost" href="<?= h($rPrint) ?>">چاپِ سود و زیان</a></div>
</div>
<nav class="st-tabs" aria-label="بازه">
    <?php foreach (BizReports::PERIODS as $pk => $pl): if ($pk === 'custom') { continue; } ?>
        <a class="st-tab-chip<?= $pk === $period ? ' is-active' : '' ?>" href="<?= h(Biz::url('reports.php?p=' . $pk)) ?>"><?= h($pl) ?></a>
    <?php endforeach; ?>
</nav>
<form class="st-filters" method="get" action="<?= h(Biz::url('reports.php')) ?>">
    <input type="hidden" name="p" value="custom">
    <input data-num type="text" name="from" value="<?= $period === 'custom' ? h(BizDocView::jDate($rFrom)) : '' ?>" placeholder="از تاریخ ۱۴۰۵/۰۱/۰۱" dir="ltr" class="st-date-in" aria-label="از تاریخ">
    <input data-num type="text" name="to" value="<?= $period === 'custom' ? h(BizDocView::jDate($rTo)) : '' ?>" placeholder="تا تاریخ" dir="ltr" class="st-date-in" aria-label="تا تاریخ">
    <button type="submit" class="st-btn st-btn-ghost">بازه‌ی دلخواه</button>
</form>

<div class="st-kpis">
    <div class="st-kpi-card">
        <span class="st-kpi-label">فروشِ خالص</span>
        <span class="st-kpi-value st-num"><?= formatMoney($rSales['net']) ?></span>
        <span class="st-kpi-sub"><?= toPersianDigits((string)$rSales['docs']) ?> فاکتور · برگشتی <?= formatMoney($rSales['returns']) ?></span>
    </div>
    <div class="st-kpi-card">
        <span class="st-kpi-label">سودِ ناخالص</span>
        <span class="st-kpi-value st-num<?= $rSales['gross'] < 0 ? ' is-neg' : ' is-pos' ?>"><?= ($rSales['gross'] < 0 ? '−' : '') . formatMoney(abs($rSales['gross'])) ?></span>
        <span class="st-kpi-sub"><?= $rSales['margin'] !== null ? 'حاشیه‌ی ' . toPersianDigits((string)$rSales['margin']) . '٪ · ' : '' ?>بهای تمام‌شده <?= formatMoney($rSales['cogs']) ?></span>
    </div>
    <div class="st-kpi-card">
        <?php $rAdj = BizBooks::plAdjust($userId, $rFrom, $rTo); $rNet = BizReports::profit($rSales['gross'], $rSales['other'], $rCash['income'], $rCash['expense'], $rAdj); ?>
        <span class="st-kpi-label">هزینه‌های فروشگاه</span>
        <span class="st-kpi-value st-num"><?= formatMoney($rCash['expense']) ?></span>
        <span class="st-kpi-sub">سودِ خالص <?= ($rNet < 0 ? '−' : '') . formatMoney(abs($rNet)) ?><?= $rSales['other'] !== 0 ? ' · کسریِ انبار و خریدِ بی‌انبار ' . ($rSales['other'] < 0 ? '−' : '') . formatMoney(abs($rSales['other'])) : '' ?><?= $rAdj !== 0 ? ' · استهلاک و سندِ دستی ' . ($rAdj < 0 ? '−' : '') . formatMoney(abs($rAdj)) : '' ?></span>
    </div>
    <div class="st-kpi-card">
        <span class="st-kpi-label">میانگینِ هر فاکتور</span>
        <span class="st-kpi-value st-num"><?= formatMoney($rSales['avg']) ?></span>
        <span class="st-kpi-sub">دریافت <?= formatMoney($rCash['receipt']) ?> · پرداخت <?= formatMoney($rCash['payment']) ?></span>
    </div>
</div>
<p class="st-muted st-unit-note">مبلغ‌ها به تومان. سود = فروشِ خالص − بهای تمام‌شده‌ی کالای فروخته (میانگینِ خرید در تاریخِ فروش؛ گوشی: بهای خریدِ همان گوشی)؛ «سودِ خالص» هزینه‌ها و درآمدهای متفرقه، کسریِ انبارگردانی و خریدِ بی‌انبار (خدمت و شرحِ آزاد در فاکتورِ خرید) را هم حساب می‌کند.</p>

<div class="st-cols">
    <section class="st-card">
        <h2 class="st-h2">پرفروش‌ترین کالاها</h2>
        <?php if (!$rTop): ?><p class="st-empty">در این بازه فروشی صادر نشده است.</p><?php else: ?>
        <div class="st-table-wrap st-flat"><table class="st-table">
            <thead><tr><th>کالا</th><th class="st-th-num">تعداد</th><th class="st-th-num">فروش</th><th class="st-th-num">سود</th></tr></thead>
            <tbody><?php foreach ($rTop as $t): ?><tr>
                <td><?php if ($t['product_id'] !== null): ?><a class="st-row-link" href="<?= h(Biz::url('product.php?id=' . (int)$t['product_id'])) ?>"><?= h((string)$t['name']) ?></a><?php else: ?><?= h((string)$t['name']) ?><?php endif; ?></td>
                <td class="st-td-num"><span class="st-num"><?= h(formatQty($t['qty'])) ?></span></td>
                <td class="st-td-num"><?= BizDocView::money((int)$t['rev']) ?></td>
                <td class="st-td-num"><?= BizDocView::money((int)$t['profit']) ?></td>
            </tr><?php endforeach; ?></tbody>
        </table></div>
        <?php endif; ?>
    </section>
    <section class="st-card">
        <h2 class="st-h2">به تفکیکِ دسته</h2>
        <?php if (!$rCats): ?><p class="st-empty">در این بازه فروشی صادر نشده است.</p><?php else: $catMax = max(1, max(array_map(fn($c) => (int)$c['rev'], $rCats))); ?>
        <ul class="st-list">
            <?php foreach ($rCats as $c): ?>
            <li class="st-catrow">
                <div class="st-list-row st-plain"><span><?= $c['cat'] !== null ? h((string)$c['cat']) : 'بی‌دسته' ?></span><span class="st-move-end"><?= BizDocView::money((int)$c['rev']) ?> <small class="st-muted-i">سود <?= h(formatMoney((int)$c['profit'])) ?></small></span></div>
                <div class="st-meter"><span style="width: <?= max(1, (int)round(max(0, (int)$c['rev']) / $catMax * 100)) ?>%"></span></div>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>
    <section class="st-card">
        <h2 class="st-h2">سنِ طلب از مشتری‌ها</h2>
        <?php if ($rAging['total'] === 0): ?><p class="st-empty">هیچ فاکتورِ فروشِ تسویه‌نشده‌ای نیست.</p><?php else: ?>
        <ul class="st-list">
            <?php foreach ($rAging['buckets'] as $bk => $bv): ?>
            <li class="st-list-row"><span><?= h(BizReports::AGING_LABELS[$bk]) ?> <span class="st-muted-i"><?= toPersianDigits((string)$bv['n']) ?> فاکتور</span></span><?= BizDocView::money($bv['s']) ?></li>
            <?php endforeach; ?>
        </ul>
        <p class="st-muted">از سررسید (یا اگر ندارد، از تاریخِ فاکتور). <a href="<?= h(Biz::url('sales.php?f=open')) ?>">فهرستِ فاکتورهای تسویه‌نشده</a></p>
        <?php endif; ?>
    </section>
    <section class="st-card">
        <h2 class="st-h2">بزرگ‌ترین هزینه‌ها</h2>
        <?php if (!$rExp): ?><p class="st-empty">در این بازه هزینه‌ای ثبت نشده است. <a href="<?= h(Biz::url('payment.php?k=expense')) ?>">ثبتِ هزینه</a></p><?php else: ?>
        <ul class="st-list"><?php foreach ($rExp as $e): ?><li class="st-list-row"><span><?= h((string)$e['title']) ?> <span class="st-muted-i"><?= toPersianDigits((string)$e['n']) ?> بار</span></span><?= BizDocView::money((int)$e['s']) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
    </section>
</div>

<h2 class="st-h2 st-section-title" id="all">همه‌ی گزارش‌ها</h2>
<p class="st-muted">گزارش‌های دوره‌ای بازه‌ی بالای همین صفحه را می‌گیرند (<b><?= h(BizReports::rangeLabel($rFrom, $rTo)) ?></b>). کاغذ: <bdi data-latin><?= h(Biz::PRINT_OPTIONS['paper'][$prefs['paper']]) ?></bdi> —
    <a href="<?= h(Biz::url('print-settings.php')) ?>">تنظیماتِ چاپ</a></p>

<div class="st-hub">
<?php foreach (BizPrint::HUB as $group => $items): ?>
    <section class="st-card st-hub-group">
        <h2 class="st-h2"><?= h($group) ?></h2>
        <ul class="st-hub-list">
        <?php foreach ($items as $it): $in = $it['input']; ?>
            <li>
                <form class="st-hub-item" method="get" action="<?= h($printUrl) ?>">
                    <input type="hidden" name="doc" value="<?= h($it['doc']) ?>">
                    <?php if (isset($it['k'])): ?><input type="hidden" name="k" value="<?= h($it['k']) ?>"><?php endif; ?>
                    <?php if ($in === 'period' || $in === 'account'): foreach ($periodHidden as $hk => $hv): ?>
                        <input type="hidden" name="<?= h($hk) ?>" value="<?= h($hv) ?>">
                    <?php endforeach; endif; ?>
                    <div class="st-hub-text">
                        <span class="st-hub-label"><?= h($it['label']) ?></span>
                        <span class="st-muted-i"><?= h($it['desc']) ?></span>
                    </div>
                    <div class="st-hub-ctl">
                    <?php if ($in === 'pfilter'): ?>
                        <select name="f" aria-label="صافی"><?php foreach (BizProducts::FILTERS as $k => $l): ?><option value="<?= h($k) ?>"><?= h($l) ?></option><?php endforeach; ?></select>
                        <?php if ($cats): ?><select name="c" aria-label="دسته"><option value="">همه‌ی دسته‌ها</option><?php foreach ($cats as $c): ?><option value="<?= h($c) ?>"><?= h($c) ?></option><?php endforeach; ?></select><?php endif; ?>
                    <?php elseif ($in === 'cfilter'): ?>
                        <select name="f" aria-label="صافی"><?php foreach (BizParties::FILTERS as $k => $l): ?><option value="<?= h($k) ?>"><?= h($l) ?></option><?php endforeach; ?></select>
                    <?php elseif ($in === 'sfilter'): ?>
                        <select name="f" aria-label="وضعیت"><?php foreach (BizSerial::REPORT_FILTERS as $k => $l): ?><option value="<?= h($k) ?>"><?= h($l) ?></option><?php endforeach; ?></select>
                    <?php elseif ($in === 'chfilter'): ?>
                        <select name="k" aria-label="وضعیتِ چک"><?php foreach (BizCheques::FILTERS as $k => $l): ?><option value="<?= h($k) ?>"><?= h($l) ?></option><?php endforeach; ?></select>
                    <?php elseif ($in === 'party'): ?>
                        <?php if (!$parties['rows']): ?><span class="st-muted-i">هنوز طرف‌حسابی نیست.</span><?php else: ?>
                        <select name="id" required aria-label="طرف‌حساب"><?php foreach ($parties['rows'] as $r): ?><option value="<?= (int)$r['id'] ?>"><?= h((string)$r['name']) ?></option><?php endforeach; ?></select>
                        <?php endif; ?>
                    <?php elseif ($in === 'product'): ?>
                        <?php if (!$stockProducts): ?><span class="st-muted-i">هنوز کالای انبارداری نیست.</span><?php else: ?>
                        <select name="id" required aria-label="کالا"><?php foreach ($stockProducts as $r): ?><option value="<?= (int)$r['id'] ?>"><?= h((string)$r['name']) ?></option><?php endforeach; ?></select>
                        <?php endif; ?>
                    <?php elseif ($in === 'account'): ?>
                        <select name="id" required aria-label="صندوق یا حساب"><?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id'] ?>"><?= h((string)$a['name']) ?></option><?php endforeach; ?></select>
                    <?php endif; ?>
                    <?php $blocked = ($in === 'party' && !$parties['rows']) || ($in === 'product' && !$stockProducts); ?>
                        <button type="submit" class="st-btn st-btn-ghost"<?= $blocked ? ' disabled' : '' ?>>نمایش</button>
                    </div>
                </form>
            </li>
        <?php endforeach; ?>
        </ul>
    </section>
<?php endforeach; ?>
</div>
<?php if ($parties['capped'] || $products['capped']): ?><p class="st-muted-i">در فهرستِ انتخاب فقط ۵۰۰ قلمِ اول آمده‌اند؛ بقیه از صفحه‌ی خودِ طرف‌حساب یا کالا چاپ می‌شوند.</p><?php endif; ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
