<?php
/**
 * مدل و حالت‌ها — فهرست، ساختن و ویرایش (`BizModels`).
 *
 * ⛔ هیچ منطقی این‌جا نیست: حالت‌ها فقط از `BizModels::save()` (← `BizProducts::save()`)،
 *    قیمتِ گروهی فقط `BizProducts::setSellPrices()`. هر حالت کالای واقعی است و صفحه‌ی خودش را دارد.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_models.php';
require_once __DIR__ . '/../includes/biz_docview.php';
if (!BizModels::ready()) { Biz::notFound(); }

$userId = Biz::ownerId();
$id     = (int)getParam('id');
$self   = Biz::url('models.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    Biz::requireCap('products');   // ⛔ ساختن، قیمت و حذف — نه فقط پنهان کردنِ فرم
    $act = (string)postParam('action');
    $mid = (int)postParam('id');
    if ($act === 'save') {
        $r = BizModels::save($userId, $_POST, $mid);
        redirectWithMessage($self . (!empty($r['id']) ? '?id=' . (int)$r['id'] : ''), $r['ok'] ? 'success' : 'error', $r['message']);
    }
    if ($act === 'prices' && $mid > 0) {
        $m = BizModels::get($userId, $mid);
        $own = $m ? array_flip(array_map(fn($p) => (int)$p['id'], $m['items'])) : [];
        $prices = [];
        foreach ((array)($_POST['price'] ?? []) as $pid => $v) {
            // ⛔ فقط حالت‌های همین مدل — شناسه‌ی دیگری از فرم پذیرفته نمی‌شود
            if (isset($own[(int)$pid]) && trim((string)$v) !== '') { $prices[(int)$pid] = sanitizeAmount($v); }
        }
        $r = BizProducts::setSellPrices($userId, $prices);
        redirectWithMessage($self . '?id=' . $mid, $r['ok'] ? 'success' : 'error', $r['message']);
    }
    if ($act === 'delete' && $mid > 0) {
        $ok = BizModels::delete($userId, $mid);
        redirectWithMessage($self, $ok ? 'success' : 'error', $ok ? 'مدل حذف شد؛ حالت‌هایش کالای معمولی ماندند.' : 'مدل پیدا نشد.');
    }
    redirectWithMessage($self, 'error', 'درخواست نامعتبر است.');
}

$model  = $id > 0 ? BizModels::get($userId, $id) : null;
if ($id > 0 && !$model) { Biz::notFound(); }
$models = $model ? [] : BizModels::all($userId);
$v1 = $v2 = [];
foreach ($model['items'] ?? [] as $p) {
    if ((string)$p['variant1'] !== '' && !in_array((string)$p['variant1'], $v1, true)) { $v1[] = (string)$p['variant1']; }
    if ((string)$p['variant2'] !== '' && !in_array((string)$p['variant2'], $v2, true)) { $v2[] = (string)$p['variant2']; }
}
$canCost = Biz::can('cost');
$navActive = 'products.php';
$pageTitle = $model ? (string)$model['name'] : 'مدل و حالت‌ها';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <h1 class="st-h1"><?= h($model ? (string)$model['name'] : 'مدل و حالت‌ها') ?></h1>
    <?php if ($model): ?><div class="st-head-actions"><a class="st-btn st-btn-ghost" href="<?= h($self) ?>">همه‌ی مدل‌ها</a></div><?php endif; ?>
</div>

<?php if (!$model): ?>
<p class="st-muted">یک مدل (مثلاً «iPhone 15») با ویژگی‌هایش (حافظه، رنگ) بسازید؛ هر ترکیب یک کالای جدا با موجودی و IMEIِ خودش ساخته می‌شود.</p>
<?php if ($models): ?>
<div class="st-table-wrap"><table class="st-table">
    <thead><tr><th>مدل</th><th class="st-th-num">حالت</th><th class="st-th-num">موجودی</th></tr></thead>
    <tbody>
    <?php foreach ($models as $m): ?>
        <tr><td><a class="st-row-link" href="<?= h($self . '?id=' . (int)$m['id']) ?>"><?= h((string)$m['name']) ?></a></td>
            <td class="st-td-num"><?= toPersianDigits((string)(int)$m['variants']) ?></td>
            <td class="st-td-num"><?= toPersianDigits((string)(float)$m['stock']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>
<?php endif; ?>

<?php if ($model && $model['items']): ?>
<h2 class="st-h2 st-section-title">حالت‌ها</h2>
<form method="post" action="<?= h($self) ?>" class="st-card">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="prices">
    <input type="hidden" name="id" value="<?= (int)$model['id'] ?>">
    <div class="st-table-wrap"><table class="st-table">
        <thead><tr><th><?= h((string)$model['attr1']) ?></th><th><?= h((string)$model['attr2']) ?></th><th class="st-th-num">موجودی</th>
            <?php if ($canCost): ?><th class="st-th-num">میانگینِ خرید</th><?php endif; ?><th class="st-th-num">قیمتِ فروش (تومان)</th></tr></thead>
        <tbody>
        <?php foreach ($model['items'] as $p): $rate = (string)($p['rate_code'] ?? '') !== ''; ?>
            <tr class="<?= (int)$p['is_active'] === 1 ? '' : 'is-inactive' ?>">
                <td><a class="st-row-link" href="<?= h(Biz::url('product.php?id=' . (int)$p['id'])) ?>"><?= h((string)($p['variant1'] ?? '') ?: '—') ?></a></td>
                <td><?= h((string)($p['variant2'] ?? '') ?: '—') ?></td>
                <td class="st-td-num"><?= h(BizView::qty($p['stock_qty'], (string)$p['unit'])) ?></td>
                <?php if ($canCost): ?><td class="st-td-num"><?= formatMoney((int)round((float)$p['avg_cost'])) ?></td><?php endif; ?>
                <td class="st-td-num"><?php if ($rate): ?><?= formatMoney((int)$p['sell_price']) ?> <span class="st-muted-i">نرخِ روز</span>
                    <?php else: ?><input data-money type="text" name="price[<?= (int)$p['id'] ?>]" inputmode="numeric" dir="ltr" class="st-input-sm" value="<?= h(formatMoney((int)$p['sell_price'])) ?>" aria-label="قیمتِ فروشِ <?= h((string)$p['name']) ?>"><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php if (Biz::can('products')): ?><button type="submit" class="st-btn">ذخیره‌ی قیمت‌ها</button><?php endif; ?>
</form>
<?php endif; ?>

<?php if (Biz::can('products')): ?>
<h2 class="st-h2 st-section-title"><?= $model ? 'ویرایشِ مدل و افزودنِ حالت' : 'مدلِ تازه' ?></h2>
<form method="post" action="<?= h($self) ?>" class="st-card st-form">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($model['id'] ?? 0) ?>">
    <div class="st-row3">
        <label class="st-field"><span>نامِ مدل</span>
            <input type="text" name="name" required maxlength="<?= BizModels::NAME_MAX ?>" value="<?= h((string)($model['name'] ?? '')) ?>" placeholder="مثلاً iPhone 15"></label>
        <label class="st-field"><span>ویژگیِ اول</span>
            <input type="text" name="attr1" maxlength="<?= BizModels::ATTR_MAX ?>" value="<?= h((string)($model['attr1'] ?? 'حافظه')) ?>"></label>
        <label class="st-field"><span>ویژگیِ دوم</span>
            <input type="text" name="attr2" maxlength="<?= BizModels::ATTR_MAX ?>" value="<?= h((string)($model['attr2'] ?? 'رنگ')) ?>"></label>
    </div>
    <div class="st-row2">
        <label class="st-field"><span>مقدارهای ویژگیِ اول (با ویرگول)</span>
            <input type="text" name="values1" value="<?= h(implode('، ', $v1)) ?>" placeholder="۱۲۸ گیگ، ۲۵۶ گیگ"></label>
        <label class="st-field"><span>مقدارهای ویژگیِ دوم (با ویرگول)</span>
            <input type="text" name="values2" value="<?= h(implode('، ', $v2)) ?>" placeholder="مشکی، آبی، صورتی"></label>
    </div>
    <?php if (!$model): ?>
    <div class="st-row3">
        <label class="st-field"><span>نوع</span>
            <select name="type"><?php foreach (BizProducts::TYPES as $k => $l): if ($k === 'service') { continue; } ?><option value="<?= h($k) ?>"<?= $k === 'phone' ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
        <label class="st-field"><span>واحد</span>
            <select name="unit"><?php foreach (array_keys(BizProducts::UNITS) as $u): ?><option value="<?= h($u) ?>"<?= $u === 'دستگاه' ? ' selected' : '' ?>><?= h($u) ?></option><?php endforeach; ?></select></label>
        <label class="st-field"><span>دسته</span><input type="text" name="category" maxlength="<?= BizProducts::LIMITS['category'] ?>"></label>
    </div>
    <?php else: ?>
    <input type="hidden" name="type" value="<?= h(BizProducts::typeOf($model['items'][0] ?? [])) ?>">
    <input type="hidden" name="unit" value="<?= h((string)($model['items'][0]['unit'] ?? 'عدد')) ?>">
    <?php endif; ?>
    <div class="st-row2">
        <label class="st-field"><span>قیمتِ خریدِ حالت‌های تازه (تومان)</span><input data-money type="text" name="buy_price" inputmode="numeric" dir="ltr"></label>
        <label class="st-field"><span>قیمتِ فروشِ حالت‌های تازه (تومان)</span><input data-money type="text" name="sell_price" inputmode="numeric" dir="ltr"></label>
    </div>
    <p class="st-muted">برداشتنِ یک مقدار از فهرست حالتِ موجود را حذف نمی‌کند (ممکن است فاکتور داشته باشد)؛ فقط برای مقدارهای تازه حالت ساخته می‌شود.</p>
    <div class="st-danger-row">
        <button type="submit" class="st-btn"><?= $model ? 'ذخیره و ساختِ حالت‌های تازه' : 'ساختنِ مدل و حالت‌ها' ?></button>
    </div>
</form>
<?php if ($model): ?>
<form method="post" action="<?= h($self) ?>" class="st-inline-form" onsubmit="return confirm('مدل حذف شود؟ حالت‌ها کالای معمولی می‌مانند.');">
    <?= Csrf::field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$model['id'] ?>">
    <button type="submit" class="st-btn st-btn-ghost">حذفِ مدل (کالاها می‌مانند)</button>
</form>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
