<?php
/**
 * طرف‌حساب‌ها — مشتری و تأمین‌کننده، با مانده‌ی هر کدام.
 *
 * مانده از `BizParties::BALANCE_SQL` می‌آید (تنها تعریف)؛ امروز فقط مانده‌ی
 * اول دوره است و فاکتور و دریافت/پرداختِ مرحله‌ی ۳ همان‌جا اضافه می‌شوند.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_catalog.php';

$userId = Biz::ownerId();
$q      = mb_substr(trim(getParam('q')), 0, 80);
$filter = getParam('f');
$filter = isset(BizParties::FILTERS[$filter]) ? $filter : '';
$page   = max(1, (int)getParam('page', '1'));

$list = BizParties::list($userId, $q, $filter, $page);
$sum  = BizParties::summary($userId);

$pageTitle = 'طرف‌حساب‌ها';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <h1 class="st-h1">طرف‌حساب‌ها</h1>
    <div class="st-head-actions">
        <a class="st-btn st-btn-ghost" href="<?= h(Biz::url('print.php') . '?' . http_build_query(array_filter(['doc' => 'parties', 'f' => $filter], fn($v) => $v !== ''))) ?>">چاپِ مانده‌ها</a>
        <a class="st-btn" href="<?= h(Biz::url('party.php')) ?>">+ طرف‌حسابِ تازه</a>
    </div>
</div>

<div class="st-chips-row">
    <span class="st-stat"><?= toPersianDigits((string)$sum['count']) ?> طرف‌حساب</span>
    <span class="st-stat">طلب <b class="st-num is-pos"><?= formatMoney($sum['receivable']) ?></b></span>
    <span class="st-stat">بدهی <b class="st-num<?= $sum['payable'] > 0 ? ' is-neg' : '' ?>"><?= formatMoney($sum['payable']) ?></b></span>
</div>

<form class="st-filters" method="get" action="<?= h(Biz::url('parties.php')) ?>" role="search">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="جست‌وجوی نام یا تلفن…" aria-label="جست‌وجو">
    <?php if ($filter !== ''): ?><input type="hidden" name="f" value="<?= h($filter) ?>"><?php endif; ?>
    <button type="submit" class="st-btn st-btn-ghost">بگرد</button>
</form>

<nav class="st-tabs" aria-label="صافیِ طرف‌حساب">
    <?php foreach (BizParties::FILTERS as $fk => $fl):
        $href = Biz::url('parties.php') . '?' . http_build_query(array_filter(['q' => $q, 'f' => $fk], fn($v) => $v !== '')); ?>
        <a href="<?= h($href) ?>" class="st-tab-chip<?= $fk === $filter ? ' is-active' : '' ?>"><?= h($fl) ?></a>
    <?php endforeach; ?>
</nav>

<?php if (!$list['rows']): ?>
    <div class="st-card st-empty-card">
        <?php if ($q !== '' || $filter !== ''): ?>
            <p class="st-empty">طرف‌حسابی با این مشخصات پیدا نشد.</p>
        <?php else: ?>
            <p class="st-empty">هنوز مشتری یا تأمین‌کننده‌ای ثبت نکرده‌اید. اگر از پیش به کسی بدهکارید یا از کسی طلب دارید، همان را به‌عنوان مانده‌ی اول دوره بنویسید.</p>
            <a class="st-btn" href="<?= h(Biz::url('party.php')) ?>">+ طرف‌حسابِ تازه</a>
        <?php endif; ?>
    </div>
<?php else: ?>
<div class="st-table-wrap">
    <table class="st-table">
        <thead>
            <tr>
                <th>نام</th>
                <th class="st-hide-sm">نوع</th>
                <th class="st-hide-sm">تلفن</th>
                <th class="st-th-num">مانده</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($list['rows'] as $p): $bal = (int)$p['balance']; ?>
            <tr class="<?= (int)$p['is_active'] === 1 ? '' : 'is-inactive' ?>">
                <td><a class="st-row-link" href="<?= h(Biz::url('party.php?id=' . (int)$p['id'])) ?>"><?= h($p['name']) ?></a></td>
                <td class="st-hide-sm"><?= h(BizParties::KINDS[$p['kind']] ?? (string)$p['kind']) ?></td>
                <td class="st-hide-sm"><?= $p['phone'] !== null ? '<span class="st-num">' . h($p['phone']) . '</span>' : '<span class="st-muted-i">—</span>' ?></td>
                <td class="st-td-num">
                    <?php if ($bal === 0): ?>
                        <span class="st-muted-i">تسویه</span>
                    <?php else: ?>
                        <span class="st-num <?= $bal > 0 ? 'is-pos' : 'is-neg' ?>"><?= formatMoney(abs($bal)) ?></span>
                        <span class="st-bal-side"><?= $bal > 0 ? 'بدهکار' : 'طلبکار' ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?= BizView::pager('parties.php', ['q' => $q, 'f' => $filter], $list['page'], $list['pages'], $list['total']) ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
