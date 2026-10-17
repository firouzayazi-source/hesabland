<?php
/**
 * تغییرِ گروهیِ قیمتِ فروش — با صافی، یا با چسباندنِ لیستِ قیمتِ روز (`BizBulkPrice`).
 *
 * ⛔ دو مرحله: «پیش‌نمایش» هیچ چیزی نمی‌نویسد؛ «اعمال» قیمت‌ها را دوباره روی سرور از همان صافی/متن
 *    حساب می‌کند و فقط با `BizProducts::setSellPrices()` می‌نویسد (کالای وصل به نرخِ روز رد).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_bulk_price.php';

$userId = Biz::ownerId();
$self   = Biz::url('bulk-price.php');
$tab    = (string)(postParam('tab') ?: getParam('t')) === 'list' ? 'list' : 'group';
$in     = [
    'q' => (string)postParam('q'), 'c' => (string)postParam('c'), 't' => (string)postParam('t'), 'm' => (string)postParam('m'),
    'mode' => (string)(postParam('mode') ?: 'pct'), 'value' => (string)postParam('value'), 'round' => (int)(postParam('round') ?: 1000),
    'dir' => (string)(postParam('dir') ?: 'up'), 'text' => (string)postParam('text'),
];
$preview = null;
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $act = (string)postParam('action');
    if ($tab === 'group') {
        $rows = BizBulkPrice::select($userId, $in);
        $calc = BizBulkPrice::compute($rows, $in['mode'], $in['value'], $in['round'], $in['dir'] === 'down');
        if (!$calc['ok']) { $error = $calc['message']; }
        elseif ($act === 'apply') {
            $r = BizProducts::setSellPrices($userId, $calc['prices']);
            redirectWithMessage($self, $r['ok'] ? 'success' : 'error', $r['message']);
        } else {
            $preview = ['rows' => array_map(fn($p) => $p + ['new' => $calc['prices'][(int)$p['id']]], $rows), 'missing' => [], 'ambiguous' => [], 'bad' => []];
        }
    } else {
        $parsed = BizBulkPrice::parseList($userId, $in['text']);
        if ($act === 'apply') {
            $r = BizProducts::setSellPrices($userId, $parsed['prices']);
            redirectWithMessage($self . '?t=list', $r['ok'] ? 'success' : 'error', $r['message']);
        }
        $preview = $parsed;
    }
}

$cats = BizProducts::categories($userId);
$pageTitle = 'تغییرِ گروهیِ قیمت';
$navActive = 'products.php';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <h1 class="st-h1">تغییرِ گروهیِ قیمت</h1>
    <div class="st-head-actions"><a class="st-btn st-btn-ghost" href="<?= h(Biz::url('products.php')) ?>">‹ کالاها</a></div>
</div>
<p class="st-muted">اول پیش‌نمایش را ببینید، بعد اعمال کنید. فاکتورهای صادرشده عوض نمی‌شوند؛ کالای وصل به نرخِ روز قیمتش را از همان‌جا می‌گیرد.</p>

<nav class="st-tabs" aria-label="روش">
    <a class="st-tab-chip<?= $tab === 'group' ? ' is-active' : '' ?>" href="<?= h($self) ?>">درصد یا مبلغ روی یک گروه</a>
    <a class="st-tab-chip<?= $tab === 'list' ? ' is-active' : '' ?>" href="<?= h($self . '?t=list') ?>">چسباندنِ لیستِ قیمت</a>
</nav>

<?php if ($error !== ''): ?><div class="st-flash st-flash-err" role="alert"><?= h($error) ?></div><?php endif; ?>

<form method="post" action="<?= h($self) ?>" class="st-card st-form">
    <?= Csrf::field() ?>
    <input type="hidden" name="tab" value="<?= h($tab) ?>">
    <?php if ($tab === 'group'): ?>
    <div class="st-row3">
        <label class="st-field"><span>نام یا کد شامل</span><input type="text" name="q" value="<?= h($in['q']) ?>" placeholder="مثلاً Galaxy"></label>
        <label class="st-field"><span>دسته</span>
            <select name="c"><option value="">همه</option><?php foreach ($cats as $c): ?><option value="<?= h($c) ?>"<?= $c === $in['c'] ? ' selected' : '' ?>><?= h($c) ?></option><?php endforeach; ?></select></label>
        <label class="st-field"><span>نوع</span>
            <select name="t"><option value="">همه</option><option value="phone"<?= $in['t'] === 'phone' ? ' selected' : '' ?>>گوشی</option><option value="goods"<?= $in['t'] === 'goods' ? ' selected' : '' ?>>کالا و لوازم</option></select></label>
    </div>
    <div class="st-row3">
        <label class="st-field"><span>تغییر</span>
            <select name="mode"><?php foreach (BizBulkPrice::MODES as $k => $l): ?><option value="<?= h($k) ?>"<?= $k === $in['mode'] ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
        <label class="st-field"><span>مقدار</span><input data-num type="text" name="value" inputmode="decimal" dir="ltr" value="<?= h($in['value']) ?>" required></label>
        <label class="st-field"><span>جهت</span>
            <select name="dir"><option value="up">افزایش</option><option value="down"<?= $in['dir'] === 'down' ? ' selected' : '' ?>>کاهش</option></select></label>
    </div>
    <label class="st-field"><span>گرد کردن به</span>
        <select name="round"><?php foreach (BizBulkPrice::ROUNDS as $k => $l): ?><option value="<?= $k ?>"<?= $k === $in['round'] ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
    <?php else: ?>
    <label class="st-field"><span>هر خط: کد یا نامِ کالا، بعد قیمت (با Tab، «;» یا فاصله)</span>
        <textarea name="text" rows="8" dir="auto" placeholder="Galaxy A55 256GB&#9;۲۴٬۵۰۰٬۰۰۰&#10;SKU-1234; 1850000"><?= h($in['text']) ?></textarea></label>
    <?php endif; ?>
    <div class="st-danger-row">
        <button type="submit" name="action" value="preview" class="st-btn st-btn-ghost">پیش‌نمایش</button>
        <?php if ($preview !== null && $preview['rows']): ?><button type="submit" name="action" value="apply" class="st-btn">اعمالِ <?= toPersianDigits((string)count($preview['rows'])) ?> قیمت</button><?php endif; ?>
    </div>
</form>

<?php if ($preview !== null): ?>
<?php foreach (['missing' => 'پیدا نشد', 'ambiguous' => 'به چند کالا می‌خورد — کد بنویسید', 'bad' => 'قیمت خوانده نشد'] as $k => $lbl): if (!$preview[$k]) { continue; } ?>
<div class="st-flash st-flash-warn"><b><?= h($lbl) ?>:</b> <?= h(implode('، ', array_slice($preview[$k], 0, 20))) ?><?= count($preview[$k]) > 20 ? ' …' : '' ?></div>
<?php endforeach; ?>
<?php if ($preview['rows']): ?>
<div class="st-table-wrap"><table class="st-table" data-preview>
    <thead><tr><th>کالا</th><th class="st-th-num">قیمتِ فعلی</th><th class="st-th-num">قیمتِ تازه</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($preview['rows'] as $p): $rate = (string)($p['rate_code'] ?? '') !== ''; ?>
        <tr><td><?= h((string)$p['name']) ?></td><td class="st-td-num"><?= formatMoney((int)$p['sell_price']) ?></td>
            <td class="st-td-num"><b><?= formatMoney((int)$p['new']) ?></b></td>
            <td><?= $rate ? '<span class="st-badge is-warn">نرخِ روز — عوض نمی‌شود</span>' : '' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php elseif ($tab === 'group' && $error === ''): ?>
<p class="st-empty">کالایی با این صافی نیست.</p>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
