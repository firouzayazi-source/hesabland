<?php
/**
 * داشبوردِ همکارِ بی‌دسترسیِ مالی (فروشنده) — از `store/index.php`.
 *
 * ⛔ هیچ عددِ صندوق، سود یا بهای خرید اینجا نیست. «فروشِ امروزِ من» از
 *    `created_by` (`Biz::stampAuthor()`) می‌آید؛ کمبودِ انبار همان `BizProducts::lowStock()`.
 *    ورودی: `$userId` (صاحبِ فروشگاه، `Biz::ownerId()`).
 */
if (!defined('APP_BASE_PATH') || !isset($userId)) { http_response_code(404); exit; }
require_once __DIR__ . '/biz_catalog.php';

$__me    = (int)Auth::userId();
$__today = today();
$__mine  = ['n' => 0, 's' => 0];
$__rows  = [];
if (Biz::staffReady()) {
    $__st = Database::getConnection()->prepare(
        "SELECT id, number, inv_date, total, status FROM biz_invoices
         WHERE user_id = :u AND created_by = :me AND kind = 'sale' AND inv_date = :d AND status <> 'void'
         ORDER BY id DESC LIMIT 20"
    );
    $__st->execute(['u' => $userId, 'me' => $__me, 'd' => $__today]);
    $__rows = $__st->fetchAll();
    foreach ($__rows as $__r) { if ($__r['status'] === 'issued') { $__mine['n']++; $__mine['s'] += (int)$__r['total']; } }
}
$__low = BizProducts::lowStock($userId, 8);

$pageTitle = 'داشبورد';
require __DIR__ . '/biz_head.php';
?>
<div class="st-page-head">
    <div>
        <h1 class="st-h1">سلام، <?= h(Auth::fullName()) ?></h1>
        <p class="st-muted"><?= h(Biz::STAFF_ROLES[Biz::role()]['label']) ?> · <?= h(jalaliLongDate($__today)) ?></p>
    </div>
</div>

<div class="st-kpis st-kpis-3">
    <div class="st-kpi-card">
        <span class="st-kpi-label">فروشِ امروزِ من</span>
        <span class="st-kpi-value st-num"><?= formatMoney($__mine['s']) ?></span>
        <span class="st-kpi-sub"><?= toPersianDigits((string)$__mine['n']) ?> فاکتور · تومان</span>
    </div>
</div>

<section class="st-card">
    <h2 class="st-h2">ثبتِ سریع</h2>
    <?= Biz::newMenuHtml($userId) ?>
</section>

<?php if ($__rows): ?>
<section class="st-card">
    <h2 class="st-h2">فاکتورهای امروزِ من</h2>
    <ul class="st-list">
    <?php foreach ($__rows as $__r): ?>
        <li class="st-list-row">
            <a class="st-row-link" href="<?= h(Biz::url('invoice.php?id=' . (int)$__r['id'])) ?>">
                <?= $__r['status'] === 'draft' ? 'پیش‌نویس' : 'فاکتورِ ' . toPersianDigits((string)$__r['number']) ?>
            </a>
            <span class="st-num"><?= formatMoney((int)$__r['total']) ?></span>
        </li>
    <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<?php if ($__low): ?>
<section class="st-card">
    <h2 class="st-h2">کم یا تمام شده</h2>
    <ul class="st-list">
    <?php foreach ($__low as $__p): ?>
        <li class="st-list-row">
            <a class="st-row-link" href="<?= h(Biz::url('product.php?id=' . (int)$__p['id'])) ?>"><?= h($__p['name']) ?></a>
            <span class="st-num"><?= h(BizView::qty($__p['stock_qty'], (string)$__p['unit'])) ?></span>
        </li>
    <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>
<?php require __DIR__ . '/biz_foot.php'; ?>
