<?php
/**
 * هشدارها و پیش‌بینی — چهار زبانه، همه فقط‌خواندنی:
 *   alerts    هشدارهای هوشمند (`BizAlerts`)
 *   cash      پیش‌بینیِ نقدِ هشت هفته (`BizForecast`)
 *   branches  سود و زیانِ هر انبار/شعبه (`BizBranches`) — فقط فروشگاهِ چندانباره
 *   fx        مانده‌ی ارزی و سود/زیانِ نرخ (`BizFx::balances()`) — فقط اگر طرف‌حسابِ ارزی هست
 *
 * ⛔ بی‌دسترسیِ «بها و سود» (`cost`) فروشِ زیرِ بها، ارزشِ کالای خوابیده و سودِ شعبه دیده نمی‌شوند.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_docview.php';
require_once __DIR__ . '/../includes/biz_insight.php';

$userId = Biz::ownerId();
$cost   = Biz::can('cost');
$tabs   = ['alerts' => 'هشدارها', 'cash' => 'پیش‌بینیِ نقد'];
if ($cost && BizWarehouses::any($userId)) { $tabs['branches'] = 'سودِ هر شعبه'; }
$fxRows = [];
if (BizFx::ready()) { $fxRows = BizFx::balances($userId); if ($fxRows) { $tabs['fx'] = 'حساب‌های ارزی'; } }
$t = getParam('t', 'alerts');
$t = isset($tabs[$t]) ? $t : 'alerts';

$pageTitle = 'هشدارها و پیش‌بینی';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <div>
        <h1 class="st-h1">هشدارها و پیش‌بینی</h1>
        <p class="st-muted">آنچه امروز باید دیده شود و پولی که هفته‌های آینده می‌آید و می‌رود — همه از همان اسنادِ ثبت‌شده، بی‌هیچ عددِ جدا.</p>
    </div>
</div>
<nav class="st-tabs" aria-label="بخش">
    <?php foreach ($tabs as $tk => $tl): ?><a class="st-tab-chip<?= $tk === $t ? ' is-active' : '' ?>" href="<?= h(Biz::url('insights.php?t=' . $tk)) ?>"><?= h($tl) ?></a><?php endforeach; ?>
</nav>

<?php if ($t === 'alerts'):
    $all = BizAlerts::all($userId, $cost);
    $n = BizAlerts::count($all); ?>
    <?php if ($n === 0): ?><div class="st-card st-empty-card"><p class="st-empty">همه‌چیز مرتب است؛ هشداری نیست.</p></div><?php endif; ?>
    <?php foreach ($all as $g): if (!$g['items']) { continue; } ?>
    <section class="st-card st-alert-group is-<?= h($g['tone']) ?>">
        <h2 class="st-h3"><?= h($g['title']) ?> <span class="st-alert-count st-num"><?= toPersianDigits((string)count($g['items'])) ?></span></h2>
        <ul class="st-list">
            <?php foreach ($g['items'] as $it): ?><li class="st-list-row"><a class="st-row-link" href="<?= h($it['href']) ?>"><?= h($it['text']) ?></a></li><?php endforeach; ?>
        </ul>
    </section>
    <?php endforeach; ?>

<?php elseif ($t === 'cash'):
    $fc = BizForecast::weeks($userId);
    $max = max(1, abs($fc['start']), ...array_map(fn($w) => abs($w['balance']), $fc['weeks'])); ?>
    <div class="st-kpis st-kpis-3">
        <div class="st-kpi-card"><span class="st-kpi-label">نقدِ امروز (صندوق و بانک)</span><span class="st-kpi-value st-num"><?= formatMoney($fc['start']) ?></span><span class="st-kpi-sub">تومان</span></div>
        <div class="st-kpi-card"><span class="st-kpi-label">پایانِ هفته‌ی هشتم</span><span class="st-kpi-value st-num<?= end($fc['weeks'])['balance'] < 0 ? ' is-neg' : '' ?>"><?= ($b = end($fc['weeks'])['balance']) < 0 ? '−' : '' ?><?= formatMoney(abs($b)) ?></span><span class="st-kpi-sub">تومان</span></div>
        <div class="st-kpi-card"><span class="st-kpi-label">کمبودِ نقد</span><span class="st-kpi-value"><?= $fc['first_negative'] === null ? 'ندارد' : 'هفته‌ی ' . toPersianDigits((string)($fc['first_negative'] + 1)) ?></span></div>
    </div>
    <div class="st-table-wrap">
        <table class="st-table st-table-compact">
            <thead><tr><th>هفته</th><th class="st-th-num">ورود</th><th class="st-th-num">خروج</th><th class="st-th-num">مانده‌ی پیش‌بینی</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($fc['weeks'] as $i => $w): ?>
                <tr>
                    <td><span class="st-num"><?= h(toJalali($w['from'])) ?></span> <small class="st-muted-i">تا <?= h(toJalali($w['to'])) ?></small>
                        <?php if ($w['items']): ?><details><summary class="st-muted-i"><?= toPersianDigits((string)count($w['items'])) ?> قلم</summary><ul class="st-list">
                        <?php foreach ($w['items'] as $it): ?><li class="st-list-row"><span><?= h($it['label']) ?> <small class="st-muted-i"><?= h(toJalali($it['date'])) ?></small></span><span class="st-num<?= $it['amount'] < 0 ? ' is-neg' : '' ?>"><?= $it['amount'] < 0 ? '−' : '+' ?><?= formatMoney(abs($it['amount'])) ?></span></li><?php endforeach; ?>
                        </ul></details><?php endif; ?></td>
                    <td class="st-td-num"><?= BizDocView::money($w['in']) ?></td>
                    <td class="st-td-num"><?= BizDocView::money($w['out']) ?></td>
                    <td class="st-td-num"><span class="st-num<?= $w['balance'] < 0 ? ' is-neg' : '' ?>"><?= $w['balance'] < 0 ? '−' : '' ?><?= formatMoney(abs($w['balance'])) ?></span></td>
                    <td style="width:25%"><span class="st-forecast-bar<?= $w['balance'] < 0 ? ' is-neg' : '' ?>" style="width:<?= (int)round(abs($w['balance']) / $max * 100) ?>%"></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="st-muted">در حساب آمده‌اند: چک‌های در جریان (به تاریخِ سررسید)، اقساطِ باز و هزینه‌های تکراری؛ هر چیزِ سررسیدگذشته در هفته‌ی اول. بیرون مانده‌اند (چون سررسید ندارند):
        طلبِ فاکتورهای فروشِ باز <?= formatMoney($fc['open_sale']) ?> تومان (اقساطِ همین فاکتورها بالا هست) و بدهیِ خریدهای باز <?= formatMoney($fc['open_purchase']) ?> تومان.</p>

<?php elseif ($t === 'branches'):
    $period = getParam('p', 'month');
    $period = isset(BizReports::PERIODS[$period]) && $period !== 'custom' ? $period : 'month';
    [$from, $to] = BizReports::range($period);
    $pl = BizBranches::pl($userId, $from, $to); ?>
    <nav class="st-tabs" aria-label="بازه">
        <?php foreach (BizReports::PERIODS as $pk => $pl0): if ($pk === 'custom') { continue; } ?><a class="st-tab-chip<?= $pk === $period ? ' is-active' : '' ?>" href="<?= h(Biz::url('insights.php?t=branches&p=' . $pk)) ?>"><?= h($pl0) ?></a><?php endforeach; ?>
    </nav>
    <?php if (!$pl['rows']): ?><div class="st-card st-empty-card"><p class="st-empty">در این بازه فروشی نبوده است.</p></div><?php else: ?>
    <div class="st-table-wrap">
        <table class="st-table st-table-compact">
            <thead><tr><th>انبار / شعبه</th><th class="st-th-num">فاکتور</th><th class="st-th-num">فروشِ خالص</th><th class="st-th-num">بهای تمام‌شده</th><th class="st-th-num">سودِ ناخالص</th><th class="st-th-num">حاشیه</th></tr></thead>
            <tbody>
            <?php foreach ($pl['rows'] as $r): ?>
                <tr><td><?= h($r['name']) ?></td><td class="st-td-num"><span class="st-num"><?= toPersianDigits((string)$r['docs']) ?></span></td>
                    <td class="st-td-num"><?= BizDocView::money($r['net']) ?></td><td class="st-td-num"><?= BizDocView::money($r['cogs']) ?></td>
                    <td class="st-td-num"><span class="st-num<?= $r['gross'] < 0 ? ' is-neg' : '' ?>"><?= $r['gross'] < 0 ? '−' : '' ?><?= formatMoney(abs($r['gross'])) ?></span></td>
                    <td class="st-td-num"><?= $r['margin'] === null ? '—' : '<span class="st-num">' . h(BizView::pct((float)$r['margin'])) . '</span>' ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="st-muted">هزینه‌های فروشگاه در همین بازه <?= formatMoney($pl['expense']) ?> تومان است و به شعبه‌ای نسبت داده نمی‌شود؛ سودِ خالصِ کل در «گزارش‌ها».</p>
    <?php endif; ?>

<?php elseif ($t === 'fx'): ?>
    <div class="st-table-wrap">
        <table class="st-table st-table-compact">
            <thead><tr><th>طرف‌حساب</th><th class="st-th-num">مانده‌ی ارزی</th><th class="st-th-num">مانده در دفتر</th><th class="st-th-num">نرخِ امروز</th><th class="st-th-num">به نرخِ امروز</th><th class="st-th-num">سود/زیانِ نرخ</th></tr></thead>
            <tbody>
            <?php foreach ($fxRows as $r): ?>
                <tr>
                    <td><a class="st-row-link" href="<?= h(Biz::url('party.php?id=' . $r['id'])) ?>"><?= h($r['name']) ?></a> <small class="st-muted-i"><?= h(BizFx::label($r['code'])) ?></small></td>
                    <td class="st-td-num"><span class="st-num"><?= h(BizFx::money(abs($r['fx']), $r['code'])) ?></span> <small class="st-muted-i"><?= h(BizParties::sideLabel((int)round($r['fx'] * 100))) ?></small></td>
                    <td class="st-td-num"><?= BizDocView::money(abs($r['book'])) ?><?php if ($r['unrated'] !== 0): ?><br><small class="st-muted-i">بی‌نرخ: <?= formatMoney(abs($r['unrated'])) ?></small><?php endif; ?></td>
                    <td class="st-td-num"><?= $r['now_rate'] === null ? '<small class="st-muted-i">نرخِ روز نیست</small>' : BizDocView::money($r['now_rate']) ?></td>
                    <td class="st-td-num"><?= $r['value_now'] === null ? '—' : BizDocView::money(abs($r['value_now'])) ?></td>
                    <td class="st-td-num"><?php if ($r['diff'] === null): ?>—<?php else: ?><span class="st-num<?= $r['diff'] < 0 ? ' is-neg' : '' ?>"><?= $r['diff'] < 0 ? '−' : '+' ?><?= formatMoney(abs($r['diff'])) ?></span><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="st-muted">مانده‌ی ارزی از نرخِ هر سند ساخته می‌شود. «سود/زیانِ نرخ» = ارزشِ همان مانده به نرخِ امروز منهای مبلغی که در دفتر نشسته: مثبت یعنی تغییرِ نرخ به سودِ فروشگاه بوده. این عدد گزارش است و تا تسویه در دفتر ثبت نمی‌شود. «بی‌نرخ» بخشی از مانده است که سندش نرخ نداشت (مثلاً مانده‌ی اول دوره).</p>
<?php endif; ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
