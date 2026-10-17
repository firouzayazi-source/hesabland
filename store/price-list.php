<?php
/**
 * تنظیمِ لیستِ قیمت برای همکاران و مشتری، و لینک‌هایش (`BizPriceList`).
 * ⛔ هیچ منطقی این‌جا نیست؛ صفحه‌ی عمومی `prices.php` است. دسترسیِ «کالا».
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_pricelist.php';
if (!BizPriceList::ready()) { Biz::notFound(); }

$userId = Biz::ownerId();
$self   = Biz::url('price-list.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $r = BizPriceList::save($userId, $_POST, postParam('action') === 'rotate');
    redirectWithMessage($self, $r['ok'] ? 'success' : 'error', $r['message']);
}

$cfg    = BizPriceList::config($userId);
$cats   = BizProducts::categories($userId);
$levels = BizProducts::levelsReady() ? BizProducts::LEVELS : array_intersect_key(BizProducts::LEVELS, ['retail' => 1]);
$navActive = 'products.php';
$pageTitle = 'لیستِ قیمت';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <h1 class="st-h1">لیستِ قیمت برای همکاران و مشتری</h1>
    <div class="st-head-actions"><a class="st-btn st-btn-ghost" href="<?= h(Biz::url('products.php')) ?>">‹ کالاها</a></div>
</div>
<p class="st-muted">یک لینکِ همیشه‌به‌روز از قیمت‌های خودتان — در واتساپ یا تلگرام بفرستید یا چاپ کنید. قیمتِ خرید و تعدادِ موجودی هرگز نشان داده نمی‌شود؛ فقط «موجود» یا «ناموجود».</p>

<?php if (BizPriceList::on($cfg)): ?>
<h2 class="st-h2 st-section-title">لینک‌ها</h2>
<div class="st-card">
    <?php foreach ($cfg['levels'] as $lv): $url = BizPriceList::url($userId, $lv); ?>
    <div class="st-pl-link">
        <b><?= h(BizProducts::LEVELS[$lv]['label']) ?></b>
        <input type="text" readonly dir="ltr" value="<?= h($url) ?>" aria-label="لینکِ لیستِ <?= h(BizProducts::LEVELS[$lv]['label']) ?>" onfocus="this.select()">
        <div class="st-danger-row">
            <a class="st-btn st-btn-ghost" href="<?= h($url) ?>" target="_blank" rel="noopener">باز کردن و چاپ</a>
            <button type="button" class="st-btn st-btn-ghost" data-share-url="<?= h($url) ?>" data-share-title="<?= h('لیستِ قیمتِ ' . BizProducts::LEVELS[$lv]['label']) ?>">اشتراک‌گذاری</button>
            <a class="st-btn st-btn-ghost" href="<?= h('https://wa.me/?text=' . rawurlencode($url)) ?>" target="_blank" rel="noopener">واتساپ</a>
            <a class="st-btn st-btn-ghost" href="<?= h('https://t.me/share/url?url=' . rawurlencode($url)) ?>" target="_blank" rel="noopener">تلگرام</a>
        </div>
    </div>
    <?php endforeach; ?>
    <form method="post" action="<?= h($self) ?>" class="st-inline-form" onsubmit="return confirm('همه‌ی لینک‌های قبلی باطل شوند؟');">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="rotate">
        <?php foreach ($cfg['levels'] as $lv): ?><input type="hidden" name="levels[]" value="<?= h($lv) ?>"><?php endforeach; ?>
        <input type="hidden" name="category" value="<?= h($cfg['category']) ?>"><input type="hidden" name="type" value="<?= h($cfg['type']) ?>">
        <?php if ($cfg['in_stock']): ?><input type="hidden" name="in_stock" value="1"><?php endif; ?><input type="hidden" name="title" value="<?= h($cfg['title']) ?>">
        <button type="submit" class="st-btn st-btn-ghost">باطل کردنِ لینک‌های قبلی و ساختنِ لینکِ تازه</button>
    </form>
</div>
<?php endif; ?>

<h2 class="st-h2 st-section-title">تنظیم</h2>
<form method="post" action="<?= h($self) ?>" class="st-card st-form">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="save">
    <fieldset class="st-plan-periods"><legend class="st-muted">کدام قیمت‌ها لینک داشته باشند (هیچ‌کدام = خاموش)</legend>
        <?php foreach ($levels as $k => $l): ?>
        <label class="st-check"><input type="checkbox" name="levels[]" value="<?= h($k) ?>"<?= in_array($k, $cfg['levels'], true) ? ' checked' : '' ?>><span><?= h($l['label']) ?></span></label>
        <?php endforeach; ?>
    </fieldset>
    <div class="st-row3">
        <label class="st-field"><span>عنوان</span><input type="text" name="title" maxlength="<?= BizPriceList::TITLE_MAX ?>" value="<?= h($cfg['title']) ?>" placeholder="مثلاً قیمتِ امروزِ گوشی"></label>
        <label class="st-field"><span>دسته</span>
            <select name="category"><option value="">همه</option><?php foreach ($cats as $c): ?><option value="<?= h($c) ?>"<?= $c === $cfg['category'] ? ' selected' : '' ?>><?= h($c) ?></option><?php endforeach; ?></select></label>
        <label class="st-field"><span>نوع</span>
            <select name="type"><option value="">همه</option><option value="phone"<?= $cfg['type'] === 'phone' ? ' selected' : '' ?>>فقط گوشی</option><option value="goods"<?= $cfg['type'] === 'goods' ? ' selected' : '' ?>>کالا و لوازم</option></select></label>
    </div>
    <label class="st-check"><input type="checkbox" name="in_stock" value="1"<?= $cfg['in_stock'] ? ' checked' : '' ?>><span>فقط کالاهای موجود</span></label>
    <button type="submit" class="st-btn">ذخیره</button>
</form>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
