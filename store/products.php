<?php
/**
 * فهرستِ کالاها — جست‌وجو (نام یا کد)، صافیِ موجودی، دسته، و صفحه‌بندی.
 *
 * ⛔ صفحه‌بندی با `LIMIT` در SQL است نه برشِ آرایه: فهرست تنها
 *    مصرف‌کننده‌ی کوئریِ خودش است (همان مرزِ `admin/users.php`)، و یک
 *    مغازه هزاران کالا دارد.
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
$filter = isset(BizProducts::FILTERS[$filter]) ? $filter : '';
$cat    = mb_substr(trim(getParam('c')), 0, 80);
$page   = max(1, (int)getParam('page', '1'));

$list  = BizProducts::list($userId, $q, $filter, $cat, $page);
$cats  = BizProducts::categories($userId);
$sum   = BizProducts::summary($userId);
$keep  = ['q' => $q, 'f' => $filter, 'c' => $cat];

$stateLabel = ['out' => 'ناموجود', 'low' => 'کم', 'service' => 'خدمت', 'ok' => ''];

$pageTitle = 'کالاها';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <h1 class="st-h1">کالاها</h1>
    <div class="st-head-actions">
        <a class="st-btn st-btn-ghost" href="<?= h(Biz::url('models.php')) ?>">مدل و حالت‌ها</a>
        <?php if (Biz::can('products')): ?><a class="st-btn st-btn-ghost" href="<?= h(Biz::url('bulk-price.php')) ?>">تغییرِ قیمت‌ها</a><?php endif; ?>
        <a class="st-btn st-btn-ghost" href="<?= h(Biz::url('products-io.php')) ?>">ورود و خروجِ اکسل</a>
        <a class="st-btn st-btn-ghost" href="<?= h(Biz::url('products-cleanup.php')) ?>">پاک‌سازی و حذفِ کالاها</a>
        <a class="st-btn st-btn-ghost" href="<?= h(Biz::url('print.php') . '?' . http_build_query(array_filter(['doc' => 'stock', 'f' => $filter, 'c' => $cat], fn($v) => $v !== ''))) ?>">چاپ</a>
        <?php if (!empty($list['rows'])): ?><a class="st-btn st-btn-ghost" href="<?= h(Biz::url('labels.php?ids=' . implode(',', array_map(fn($p) => (int)$p['id'], $list['rows'])))) ?>">برچسبِ کالاهای این صفحه</a><?php endif; ?>
        <?php if (Biz::can('products')): ?><a class="st-btn" href="<?= h(Biz::url('product.php')) ?>">+ کالای تازه</a><?php endif; ?>
    </div>
</div>

<div class="st-chips-row">
    <span class="st-stat"><?= toPersianDigits((string)$sum['count']) ?> کالای فعال</span>
    <?php if (Biz::can('cost')): ?><span class="st-stat">ارزشِ انبار <b class="st-num"><?= formatMoney($sum['value']) ?></b> تومان</span><?php endif; ?>
    <?php if ($sum['low'] > 0): ?><span class="st-stat is-low"><?= toPersianDigits((string)$sum['low']) ?> کم‌موجودی</span><?php endif; ?>
    <?php if ($sum['out'] > 0): ?><span class="st-stat is-out"><?= toPersianDigits((string)$sum['out']) ?> ناموجود</span><?php endif; ?>
</div>

<form class="st-filters" method="get" action="<?= h(Biz::url('products.php')) ?>" role="search">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="جست‌وجوی نام، کد یا IMEI…" aria-label="جست‌وجو">
    <?php if ($cats): ?>
    <select name="c" aria-label="دسته">
        <option value="">همه‌ی دسته‌ها</option>
        <?php foreach ($cats as $c): ?>
            <option value="<?= h($c) ?>"<?= $c === $cat ? ' selected' : '' ?>><?= h($c) ?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <?php if ($filter !== ''): ?><input type="hidden" name="f" value="<?= h($filter) ?>"><?php endif; ?>
    <button type="submit" class="st-btn st-btn-ghost">بگرد</button>
</form>

<nav class="st-tabs" aria-label="صافیِ موجودی">
    <?php foreach (BizProducts::FILTERS as $fk => $fl):
        $href = Biz::url('products.php') . '?' . http_build_query(array_filter(['q' => $q, 'c' => $cat, 'f' => $fk], fn($v) => $v !== '')); ?>
        <a href="<?= h($href) ?>" class="st-tab-chip<?= $fk === $filter ? ' is-active' : '' ?>"><?= h($fl) ?></a>
    <?php endforeach; ?>
</nav>

<?php if (!$list['rows']): ?>
    <div class="st-card st-empty-card">
        <?php if ($q !== '' || $cat !== '' || $filter !== ''): ?>
            <p class="st-empty">کالایی با این مشخصات پیدا نشد.</p>
        <?php else: ?>
            <p class="st-empty">هنوز کالایی ثبت نکرده‌اید. با «کالای تازه» شروع کنید؛ موجودیِ فعلیِ انبار را همان‌جا به‌عنوان موجودیِ اول دوره بنویسید.</p>
            <?php if (Biz::can('products')): ?><a class="st-btn" href="<?= h(Biz::url('product.php')) ?>">+ کالای تازه</a><?php endif; ?>
        <?php endif; ?>
    </div>
<?php else: ?>
<div class="st-table-wrap">
    <table class="st-table">
        <thead>
            <tr>
                <th>کالا</th>
                <th class="st-hide-sm">دسته</th>
                <th class="st-th-num">موجودی</th>
                <th class="st-th-num">قیمتِ فروش</th>
                <?php if (Biz::can('cost')): ?><th class="st-th-num st-hide-sm">ارزشِ انبار</th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($list['rows'] as $p):
            $state = BizProducts::stockState($p);
            $value = $state === 'service' ? null : (int)round(max(0, (float)$p['stock_qty']) * (float)$p['avg_cost']); ?>
            <tr class="<?= (int)$p['is_active'] === 1 ? '' : 'is-inactive' ?>">
                <td>
                    <a class="st-row-link" href="<?= h(Biz::url('product.php?id=' . (int)$p['id'])) ?>"><?= h($p['name']) ?></a>
                    <?php if ((string)$p['sku'] !== ''): ?><span class="st-sku" dir="ltr"><?= h($p['sku']) ?></span><?php endif; ?>
                    <?php if (!empty($p['model_id'])): ?><a class="st-badge is-info" href="<?= h(Biz::url('models.php?id=' . (int)$p['model_id'])) ?>">حالتی از یک مدل</a><?php endif; ?>
                </td>
                <td class="st-hide-sm"><?= $p['category'] !== null ? h($p['category']) : '<span class="st-muted-i">—</span>' ?></td>
                <td class="st-td-num">
                    <?php if ($state === 'service'): ?>
                        <span class="st-badge">خدمت</span>
                    <?php else: ?>
                        <span class="st-num"><?= h(BizView::qty($p['stock_qty'], (string)$p['unit'])) ?></span>
                        <?php if ($stateLabel[$state] !== ''): ?><span class="st-badge is-<?= h($state) ?>"><?= h($stateLabel[$state]) ?></span><?php endif; ?>
                    <?php endif; ?>
                </td>
                <td class="st-td-num"><span class="st-num"><?= formatMoney((int)$p['sell_price']) ?></span></td>
                <?php if (Biz::can('cost')): ?><td class="st-td-num st-hide-sm"><span class="st-num"><?= $value === null ? '—' : formatMoney($value) ?></span></td><?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?= BizView::pager('products.php', $keep, $list['page'], $list['pages'], $list['total']) ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
