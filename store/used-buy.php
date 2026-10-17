<?php
/**
 * رسیدِ خریدِ گوشیِ دست‌دوم — فرم روی یک فاکتورِ خریدِ صادرشده، و جست‌وجو با IMEI یا کدِ ملی (`BizUsedBuy`).
 * ⛔ هیچ منطقی این‌جا نیست؛ پول و موجودی همان فاکتورِ خرید است. دسترسیِ «خرید».
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_used_buy.php';
require_once __DIR__ . '/../includes/biz_docview.php';
require_once __DIR__ . '/../includes/biz_print.php';
if (!BizUsedBuy::ready()) { Biz::notFound(); }

$userId = Biz::ownerId();
$invId  = (int)(postParam('inv') ?: getParam('inv'));
$self   = Biz::url('used-buy.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $r = BizUsedBuy::save($userId, $invId, $_POST, (int)Auth::userId());
    redirectWithMessage($self . '?inv=' . $invId, $r['ok'] ? 'success' : 'error', $r['message']);
}

$inv = $invId > 0 ? BizInvoices::get($userId, $invId) : null;
if ($invId > 0 && (!$inv || (string)$inv['kind'] !== 'purchase')) { Biz::notFound(); }
$rec = $inv ? BizUsedBuy::get($userId, $invId) : null;
$q   = trim((string)getParam('q'));
$found = !$inv ? BizUsedBuy::find($userId, $q) : [];
$v = $rec ?? ['seller_name' => (string)($inv['party_name'] ?? ''), 'national_id' => (string)($inv['party_national_id'] ?? ''),
              'father_name' => '', 'phone' => (string)($inv['party_phone'] ?? ''), 'address' => (string)($inv['party_address'] ?? ''),
              'condition_note' => '', 'accessories' => '', 'hamta_checked' => 0];
$acc = array_filter(explode(',', (string)($v['accessories'] ?? '')));
$navActive = 'purchases.php';
$pageTitle = 'خریدِ دست‌دوم';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <h1 class="st-h1">رسیدِ خریدِ گوشیِ دست‌دوم</h1>
    <div class="st-head-actions">
        <?php if ($inv): ?><a class="st-btn st-btn-ghost" href="<?= h(Biz::url('invoice.php?id=' . $invId)) ?>">‹ فاکتورِ خرید</a>
        <?php if ($rec): ?><a class="st-btn" href="<?= h(BizPrint::url('used_buy', ['id' => $invId])) ?>">چاپِ رسید</a><?php endif; ?>
        <?php else: ?><a class="st-btn st-btn-ghost" href="<?= h(Biz::url('purchases.php')) ?>">‹ فاکتورهای خرید</a><?php endif; ?>
    </div>
</div>

<?php if (!$inv): ?>
<p class="st-muted">«این گوشی را از چه کسی خریدیم؟» — IMEI، کدِ ملی یا نامِ فروشنده را جست‌وجو کنید. رسید از دکمه‌ی «رسیدِ خریدِ دست‌دوم» روی فاکتورِ خرید ساخته می‌شود.</p>
<form class="st-filters" method="get" action="<?= h($self) ?>" role="search">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="IMEI، کدِ ملی یا نام…" aria-label="جست‌وجو" data-scan-cam>
    <button type="submit" class="st-btn st-btn-ghost">جست‌وجو</button>
</form>
<?php if ($found): ?>
<div class="st-table-wrap"><table class="st-table">
    <thead><tr><th>تاریخ</th><th>فروشنده</th><th>کدِ ملی</th><th>فاکتور</th></tr></thead>
    <tbody>
    <?php foreach ($found as $f): ?>
        <tr><td><?= h(toJalali((string)$f['inv_date'])) ?></td><td><?= h((string)$f['seller_name']) ?></td>
            <td class="ltr-num" dir="ltr"><?= h(toPersianDigits((string)$f['national_id'])) ?></td>
            <td><a class="st-row-link" href="<?= h($self . '?inv=' . (int)$f['invoice_id']) ?>">خرید <?= toPersianDigits((string)$f['number']) ?></a></td></tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php elseif ($q !== ''): ?><p class="st-empty">رسیدی پیدا نشد.</p><?php endif; ?>

<?php else: ?>
<section class="st-card">
    <h2 class="st-h2">دستگاه‌های این خرید</h2>
    <ul class="st-list">
    <?php foreach ($inv['lines'] as $l): if ((string)($l['imei1'] ?? '') === '') { continue; } ?>
        <li class="st-list-row"><span><?= h((string)($l['description'] ?? $l['product_name'] ?? '')) ?></span>
            <span dir="ltr" class="ltr-num"><?= h(toPersianDigits((string)$l['imei1'])) ?><?= (string)($l['imei2'] ?? '') !== '' ? ' · ' . h(toPersianDigits((string)$l['imei2'])) : '' ?></span>
            <?= BizDocView::hamtaLinks($l) ?></li>
    <?php endforeach; ?>
    </ul>
    <?php if (!BizUsedBuy::imeis($inv)): ?><p class="st-muted">این فاکتور گوشیِ IMEIدار ندارد.</p><?php endif; ?>
</section>

<form method="post" action="<?= h($self) ?>" class="st-card st-form">
    <?= Csrf::field() ?>
    <input type="hidden" name="inv" value="<?= $invId ?>">
    <h2 class="st-h2">مشخصاتِ فروشنده</h2>
    <div class="st-row3">
        <label class="st-field"><span>نام و نام‌خانوادگی</span><input type="text" name="seller_name" required maxlength="<?= BizUsedBuy::LIMITS['seller_name'] ?>" value="<?= h((string)$v['seller_name']) ?>"></label>
        <label class="st-field"><span>کدِ ملی</span><input type="text" name="national_id" required inputmode="numeric" maxlength="12" dir="ltr" value="<?= h((string)$v['national_id']) ?>"></label>
        <label class="st-field"><span>نامِ پدر</span><input type="text" name="father_name" maxlength="<?= BizUsedBuy::LIMITS['father_name'] ?>" value="<?= h((string)($v['father_name'] ?? '')) ?>"></label>
    </div>
    <div class="st-row2">
        <label class="st-field"><span>تلفن</span><input type="tel" name="phone" maxlength="<?= BizUsedBuy::LIMITS['phone'] ?>" dir="ltr" value="<?= h((string)($v['phone'] ?? '')) ?>"></label>
        <label class="st-field"><span>نشانی</span><input type="text" name="address" maxlength="<?= BizUsedBuy::LIMITS['address'] ?>" value="<?= h((string)($v['address'] ?? '')) ?>"></label>
    </div>
    <h2 class="st-h2">وضعیتِ دستگاه</h2>
    <label class="st-field"><span>وضعیتِ ظاهری و فنی</span><input type="text" name="condition_note" maxlength="<?= BizUsedBuy::LIMITS['condition_note'] ?>" value="<?= h((string)($v['condition_note'] ?? '')) ?>" placeholder="مثلاً خط و خش جزئی روی قاب، باتری ۸۵٪"></label>
    <fieldset class="st-plan-periods"><legend class="st-muted">لوازمِ همراه</legend>
        <?php foreach (BizUsedBuy::ACCESSORIES as $k => $l): ?>
        <label class="st-check"><input type="checkbox" name="accessories[]" value="<?= h($k) ?>"<?= in_array($k, $acc, true) ? ' checked' : '' ?>><span><?= h($l) ?></span></label>
        <?php endforeach; ?>
    </fieldset>
    <label class="st-check"><input type="checkbox" name="hamta_checked" value="1"<?= (int)($v['hamta_checked'] ?? 0) === 1 ? ' checked' : '' ?>><span>استعلامِ همتا انجام شد و دستگاه مشکلی نداشت</span></label>
    <button type="submit" class="st-btn"><?= $rec ? 'ذخیره‌ی تغییرات' : 'ثبتِ رسید' ?></button>
</form>
<?php endif; ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
