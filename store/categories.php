<?php
/**
 * دسته‌بندیِ کالا — موجودیتِ واقعی (`biz_categories`)، نه متنِ آزاد.
 *
 * تغییرِ نامِ یک دسته همه‌ی کالاهایش را با خود می‌برد (کالا به **شناسه**
 * اشاره می‌کند). حذف کالاها را حذف نمی‌کند، **بی‌دسته**شان می‌کند و پیام
 * تعدادشان را می‌گوید.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_catalog.php';

$userId = Biz::ownerId();
$self   = Biz::url('categories.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $action = postParam('action');
    if ($action === 'delete') {
        $res = BizCategories::delete($userId, (int)postParam('cat_id'));
    } else {
        $res = BizCategories::save($userId, postParam('name'), (int)postParam('cat_id'));
    }
    redirectWithMessage($self, $res['ok'] ? 'success' : 'error', $res['message']);
}

$cats = BizCategories::list($userId);
$edit = (int)getParam('edit', '0');

$pageTitle = 'دسته‌بندی‌ها';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <div>
        <h1 class="st-h1">دسته‌بندی‌ها</h1>
        <p class="st-muted">دسته در فهرستِ کالا، گزارشِ فروش به تفکیکِ دسته و برگه‌ی موجودیِ انبار به کار می‌آید.</p>
    </div>
</div>

<section class="st-card">
    <form method="post" class="st-inline-form" action="<?= h($self) ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="save">
        <label class="st-field st-grow"><span>دسته‌ی تازه</span>
            <input type="text" name="name" required maxlength="<?= BizCategories::NAME_MAX ?>" placeholder="مثلاً گوشی، لوازم جانبی، قاب">
        </label>
        <button type="submit" class="st-btn">افزودن</button>
    </form>
</section>

<?php if (!$cats): ?>
    <div class="st-card st-empty-card"><p class="st-empty">هنوز دسته‌ای نساخته‌اید. دسته را از اینجا یا هنگامِ تعریفِ کالا بسازید.</p></div>
<?php else: ?>
<div class="st-table-wrap">
    <table class="st-table">
        <thead><tr><th>دسته</th><th class="st-th-num">کالای فعال</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($cats as $c): ?>
            <tr>
                <td>
                    <?php if ($edit === (int)$c['id']): ?>
                    <form method="post" class="st-inline-form" action="<?= h($self) ?>">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="cat_id" value="<?= (int)$c['id'] ?>">
                        <input type="text" name="name" required maxlength="<?= BizCategories::NAME_MAX ?>" value="<?= h($c['name']) ?>" aria-label="نامِ تازه" autofocus>
                        <button type="submit" class="st-btn">ذخیره</button>
                        <a class="st-link-btn" href="<?= h($self) ?>">انصراف</a>
                    </form>
                    <?php else: ?>
                    <a class="st-row-link" href="<?= h(Biz::url('products.php?c=' . rawurlencode($c['name']))) ?>"><?= h($c['name']) ?></a>
                    <?php endif; ?>
                </td>
                <td class="st-td-num"><span class="st-num"><?= toPersianDigits((string)(int)$c['products']) ?></span></td>
                <td class="st-td-num">
                    <span class="st-move-end">
                        <a class="st-link-btn" href="<?= h($self . '?edit=' . (int)$c['id']) ?>">تغییرِ نام</a>
                        <form method="post" action="<?= h($self) ?>" onsubmit="return confirm('دسته حذف شود؟ کالاهایش حذف نمی‌شوند، بی‌دسته می‌شوند.');">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="cat_id" value="<?= (int)$c['id'] ?>">
                            <button type="submit" class="st-link-btn st-link-danger">حذف</button>
                        </form>
                    </span>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
