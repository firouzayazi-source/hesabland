<?php
/**
 * پاک‌سازیِ کالاهای استفاده‌نشده — حذفِ دسته‌جمعیِ کالاهایی که در هیچ
 * سندی (فاکتور، برگشت، پیش‌نویس) نیامده‌اند؛ معمولاً باقیمانده‌ی ورود از
 * اکسل یا سایت.
 *
 * ⛔ «استفاده‌نشده» فقط `BizProducts::deleteUnused()` است و شرطش روی خودِ
 *    `DELETE` می‌نشیند؛ این صفحه فقط فهرست و فرم است. هر نوشتن POST + CSRF و
 *    بعد ریدایرکت.
 * ⚠ فهرستِ انتخابی سقف دارد (`CLEANUP_LIST_MAX`) چون `max_input_vars`ِ PHP
 *    بقیه‌ی تیک‌ها را بی‌صدا می‌انداخت؛ «حذفِ همه» دامنه را روی سرور دوباره
 *    می‌سازد و سقف ندارد.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_catalog.php';

$userId = Biz::ownerId();
$scope  = getParam('s');
$scope  = isset(BizProducts::CLEANUP_SCOPES[$scope]) ? $scope : 'empty';
$cat    = mb_substr(trim(getParam('c')), 0, 80);
$keep   = array_filter(['s' => $scope === 'empty' ? '' : $scope, 'c' => $cat], fn($v) => $v !== '');
$self   = Biz::url('products-cleanup.php') . ($keep ? '?' . http_build_query($keep) : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $ids = postParam('mode') === 'all' ? null : (array)($_POST['ids'] ?? []);
    $r   = BizProducts::deleteUnused($userId, $ids, $scope, $cat);
    redirectWithMessage($self, $r['ok'] ? 'success' : 'error', $r['message']);
}

$list = BizProducts::unused($userId, $scope, $cat);
$cats = BizProducts::categories($userId);
$withStock = 0;
foreach ($list['rows'] as $p) { if ((int)$p['track_stock'] === 1 && (float)$p['stock_qty'] != 0.0) { $withStock++; } }

$pageTitle = 'پاک‌سازیِ کالاها';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <div>
        <a class="st-back" href="<?= h(Biz::url('products.php')) ?>">→ کالاها</a>
        <h1 class="st-h1">پاک‌سازیِ کالاهای استفاده‌نشده</h1>
        <p class="st-muted">کالایی که در هیچ فاکتور، برگشت یا پیش‌نویسی نیامده حذف‌شدنی است. کالای استفاده‌شده حذف نمی‌شود؛ آن را غیرفعال کنید.</p>
    </div>
</div>

<form class="st-filters" method="get" action="<?= h(Biz::url('products-cleanup.php')) ?>">
    <select name="s" aria-label="دامنه">
        <?php foreach (BizProducts::CLEANUP_SCOPES as $sk => $sl): ?>
            <option value="<?= h($sk) ?>"<?= $sk === $scope ? ' selected' : '' ?>><?= h($sl) ?></option>
        <?php endforeach; ?>
    </select>
    <?php if ($cats): ?>
    <select name="c" aria-label="دسته">
        <option value="">همه‌ی دسته‌ها</option>
        <?php foreach ($cats as $c): ?>
            <option value="<?= h($c) ?>"<?= $c === $cat ? ' selected' : '' ?>><?= h($c) ?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <button type="submit" class="st-btn st-btn-ghost">نمایش</button>
</form>

<?php if (!$list['rows']): ?>
    <div class="st-card st-empty-card"><p class="st-empty">کالای استفاده‌نشده‌ای<?= $scope === 'empty' ? ' بدونِ موجودی' : '' ?> نیست.</p></div>
<?php else: ?>
<section class="st-card st-cleanup-all">
    <p>
        <b class="st-num"><?= toPersianDigits((string)$list['total']) ?></b> کالای استفاده‌نشده<?= $cat !== '' ? ' در دسته‌ی «' . h($cat) . '»' : '' ?>
        <?= $scope === 'empty' ? ' (بدونِ موجودی)' : '' ?>.
        <?php if ($list['capped']): ?><span class="st-muted-i">فقط <?= toPersianDigits((string)BizProducts::CLEANUP_LIST_MAX) ?> ردیفِ اول زیرِ فهرست آمده؛ «حذفِ همه» همه را می‌برد.</span><?php endif; ?>
    </p>
    <form method="post" action="<?= h($self) ?>" onsubmit="return confirm('<?= toPersianDigits((string)$list['total']) ?> کالای استفاده‌نشده حذف شود؟ این کار برگشت ندارد.');">
        <?= Csrf::field() ?>
        <input type="hidden" name="mode" value="all">
        <button type="submit" class="st-btn st-btn-danger">حذفِ همه‌ی <?= toPersianDigits((string)$list['total']) ?> کالا</button>
    </form>
</section>

<form method="post" action="<?= h($self) ?>" class="st-card st-cleanup-form" onsubmit="return confirm('کالاهای تیک‌خورده حذف شوند؟ این کار برگشت ندارد.');">
    <?= Csrf::field() ?>
    <input type="hidden" name="mode" value="pick">
    <div class="st-cleanup-head">
        <label class="st-check"><input type="checkbox" class="js-check-all" data-target="cleanup" checked> انتخابِ همه</label>
        <?php if ($withStock > 0): ?><span class="st-muted-i"><?= toPersianDigits((string)$withStock) ?> کالا موجودیِ اول دوره دارد؛ با حذف، آن موجودی هم پاک می‌شود.</span><?php endif; ?>
    </div>
    <ul class="st-cleanup-list">
        <?php foreach ($list['rows'] as $p):
            $hasStock = (int)$p['track_stock'] === 1 && (float)$p['stock_qty'] != 0.0; ?>
        <li>
            <label class="st-check">
                <input type="checkbox" name="ids[]" value="<?= (int)$p['id'] ?>" data-group="cleanup"<?= $hasStock ? '' : ' checked' ?>>
                <span class="st-cleanup-name"><?= h($p['name']) ?><?php if ((string)$p['sku'] !== ''): ?> <span class="st-sku" dir="ltr"><?= h($p['sku']) ?></span><?php endif; ?></span>
            </label>
            <span class="st-muted-i"><?= $p['category'] !== null ? h($p['category']) : '' ?><?php if ($hasStock): ?> · موجودی <span class="st-num"><?= h(BizView::qty($p['stock_qty'], (string)$p['unit'])) ?></span><?php endif; ?><?= (int)$p['is_active'] === 1 ? '' : ' · غیرفعال' ?></span>
        </li>
        <?php endforeach; ?>
    </ul>
    <button type="submit" class="st-btn st-btn-danger">حذفِ کالاهای انتخاب‌شده</button>
</form>
<?php endif; ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
