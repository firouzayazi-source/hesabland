<?php
/**
 * یک کالا — تعریف و ویرایش، و برای کالای انبارشدنی: موجودی، موجودیِ اول
 * دوره، انبارگردانی و تاریخچه‌ی حرکت‌ها.
 *
 * همه‌ی نوشتن‌ها فرمِ POST با CSRF و بعد ریدایرکت‌اند (الگوی
 * `admin/errors.php`): بدونِ جاوااسکریپت کار می‌کنند و تازه‌سازیِ صفحه
 * چیزی را دوباره نمی‌فرستد. منطق همه در `includes/biz_catalog.php` است؛
 * این صفحه فقط ورودی را تحویل می‌دهد.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_catalog.php';

$userId  = Biz::ownerId();
$id      = (int)getParam('id', '0');
$product = $id > 0 ? BizProducts::get($userId, $id) : null;
if ($id > 0 && !$product) {
    redirectWithMessage(Biz::url('products.php'), 'error', 'کالا پیدا نشد.');
}
$self  = Biz::url('product.php' . ($id > 0 ? '?id=' . $id : ''));
// ⛔ بی‌دسترسیِ «تعریفِ کالا» (فروشنده): هیچ نوشتنی، و فقط نمای خواندنی پایین.
if (!Biz::can('products') && ($_SERVER['REQUEST_METHOD'] === 'POST' || !$product)) { Biz::denied('products'); }
$error = '';
$form  = $product ?? [
    'name' => '', 'sku' => '', 'category' => '', 'unit' => 'عدد', 'buy_price' => '', 'sell_price' => '',
    'min_stock' => '', 'track_stock' => 1, 'has_serial' => 0, 'note' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $action = postParam('action');

    if ($action === 'save') {
        $res = BizProducts::save($userId, $_POST, $id);
        if ($res['ok']) {
            // ⛔ قیمتِ ارزی جدا ذخیره می‌شود (`saveRate()`)، فقط وقتی فرم آن را فرستاده.
            if (isset($_POST['rate_code'])) {
                $rr = BizProducts::saveRate($userId, (int)$res['id'], $_POST);
                if (!$rr['ok']) { redirectWithMessage(Biz::url('product.php?id=' . (int)$res['id']), 'error', 'کالا ذخیره شد، ولی قیمتِ ارزی نه: ' . $rr['message']); }
                if ($rr['message'] !== '') { $res['message'] .= ' ' . $rr['message']; }
            }
            // ⛔ مالیات و شناسه‌ی مودیان هم جدا (`saveTax()`) — ورود از فایل آن‌ها را نمی‌فرستد
            // ⛔ گارانتیِ پیش‌فرضِ کالا — جدا (`BizWarranty::saveProduct()`)؛ عکسش هنگامِ صدور روی ردیف
            // ⛔ قیمتِ عمده/همکار جدا (`savePrices()`) — خالی = همان قیمتِ فروش
            if (isset($_POST['levels_form'])) {
                $lp = BizProducts::savePrices($userId, (int)$res['id'], $_POST);
                if (!$lp['ok']) { redirectWithMessage(Biz::url('product.php?id=' . (int)$res['id']), 'error', 'کالا ذخیره شد، ولی ' . $lp['message']); }
            }
            if (isset($_POST['warranty_form'])) {
                require_once __DIR__ . '/../includes/biz_repairs.php';
                $wr = BizWarranty::saveProduct($userId, (int)$res['id'], postParam('warranty_months'), postParam('warranty_label'));
                if (!$wr['ok']) { redirectWithMessage(Biz::url('product.php?id=' . (int)$res['id']), 'error', 'کالا ذخیره شد، ولی ' . $wr['message']); }
            }
            if (isset($_POST['tax_form'])) {
                $tr = BizProducts::saveTax($userId, (int)$res['id'], $_POST);
                if (!$tr['ok']) { redirectWithMessage(Biz::url('product.php?id=' . (int)$res['id']), 'error', 'کالا ذخیره شد، ولی ' . $tr['message']); }
            }
            redirectWithMessage(Biz::url('product.php?id=' . (int)$res['id']), 'success', $res['message']);
        }
        $error = $res['message'];
        $form  = array_merge($form, array_intersect_key($_POST, $form));
        $t = (string)postParam('type');
        $form['track_stock'] = $t === 'service' ? 0 : 1;
        $form['has_serial']  = $t === 'phone' ? 1 : 0;
    } elseif ($product && $action === 'opening') {
        $cost = trim(postParam('opening_cost')) === '' ? (int)$product['buy_price'] : sanitizeAmount(postParam('opening_cost'));
        $res  = BizStock::setOpening($userId, $id, sanitizeQty(postParam('opening_qty')), $cost);
        redirectWithMessage($self, $res['ok'] ? 'success' : 'error', $res['message']);
    } elseif ($product && $action === 'quickbuy') {
        // ⛔ خرید با فروشنده و بها — فاکتورِ خریدِ واقعی (`BizQuickBuy`)، نه انبارگردانی
        require_once __DIR__ . '/../includes/biz_quickbuy.php';
        $dd = BizDocView::docDate(postParam('qb_date'), 'تاریخِ خرید');
        if (!$dd['ok']) { redirectWithMessage($self, 'error', $dd['message']); }
        if (($dup = BizOnce::claim()) !== null) { BizOnce::redirectDuplicate($dup, $self); }
        $r = BizQuickBuy::run($userId, ['product_id' => $id, 'party_id' => (int)postParam('qb_party'),
            'party_name' => (int)postParam('qb_party') > 0 ? '' : postParam('qb_party_name'),
            'qty' => postParam('qb_qty'), 'buy' => postParam('qb_buy'), 'sell' => postParam('qb_sell'),
            'date' => $dd['date'], 'pay' => postParam('qb_pay'), 'account_id' => (int)postParam('qb_account'),
            'imeis' => postParam('qb_imeis')]);
        if ($r['ok']) { BizOnce::done($self); } else { BizOnce::release(); }
        redirectWithMessage($self, $r['ok'] ? 'success' : 'error', $r['message']);
    } elseif ($product && in_array($action, ['qb_link', 'qb_issue'], true)) {
        // ⛔ «خرید خورده، موجودی نخورده» — فقط موجودی، بی‌خریدِ دوم (`BizQuickBuy`)
        require_once __DIR__ . '/../includes/biz_quickbuy.php';
        if (($dup = BizOnce::claim()) !== null) { BizOnce::redirectDuplicate($dup, $self); }
        $r = $action === 'qb_link' ? BizQuickBuy::linkLine($userId, (int)postParam('line_id'), $id)
                                   : BizQuickBuy::issueDraft($userId, (int)postParam('invoice_id'));
        if ($r['ok']) { BizOnce::done($self); } else { BizOnce::release(); }
        redirectWithMessage($self, $r['ok'] ? 'success' : 'error', $r['message']);
    } elseif ($product && $action === 'adjust') {
        if (trim(postParam('actual_qty')) === '') {
            redirectWithMessage($self, 'error', 'موجودیِ شمارش‌شده را بنویسید.');
        }
        // ⛔ «موجودیِ الکی» نه: افزایش با انبارگردانی بهای خرید و فروشنده ندارد. فقط با
        //    دلیلِ نوشته‌شده (اضافه‌ی شمارش، پیدا شدن) — خرید از «خرید و افزودنِ موجودی».
        if (sanitizeQty(postParam('actual_qty')) > (float)$product['stock_qty'] + 0.0005 && trim(postParam('note')) === '') {
            redirectWithMessage($self, 'error', 'افزایشِ موجودی با انبارگردانی دلیل می‌خواهد (مثلاً «اضافه‌ی شمارش»). اگر خریده‌اید، از «خرید و افزودنِ موجودی» ثبت کنید تا فروشنده و بهای خرید معلوم باشد.');
        }
        // ⛔ فروشگاهِ چندانباره: شمارشِ یک انبار (۰ = اصلی)؛ انبارِ نامعتبر رد، نه «اصلی»ِ بی‌صدا
        $wh = isset($_POST['warehouse_id']) ? BizWarehouses::pick($userId, postParam('warehouse_id')) : null;
        if ($wh === false) { redirectWithMessage($self, 'error', 'انبار پیدا نشد یا غیرفعال است.'); }
        $res = BizStock::adjustTo($userId, $id, sanitizeQty(postParam('actual_qty')), postParam('note'), $wh);
        redirectWithMessage($self, $res['ok'] ? 'success' : 'error', $res['message']);
    } elseif ($product && $action === 'delete_move') {
        $res = BizStock::deleteMove($userId, (int)postParam('move_id'));
        redirectWithMessage($self, $res['ok'] ? 'success' : 'error', $res['ok'] ? 'انبارگردانی حذف شد.' : $res['message']);
    } elseif ($product && $action === 'toggle') {
        $on = (int)$product['is_active'] !== 1;
        $res = BizProducts::setActive($userId, $id, $on);
        redirectWithMessage($self, $res['ok'] ? 'success' : 'error', $res['message']);
    } elseif ($product && $action === 'delete') {
        $res = BizProducts::delete($userId, $id);
        redirectWithMessage($res['ok'] ? Biz::url('products.php') : $self, $res['ok'] ? 'success' : 'error', $res['message']);
    } else {
        redirectWithMessage($self, 'error', 'درخواست نامعتبر است.');
    }
}

require_once __DIR__ . '/../includes/biz_rates.php';
$rateReady = Rates::available() && tableHasColumn('biz_products', 'rate_code');
$rateOn    = $rateReady && Rates::isCode((string)($form['rate_code'] ?? ''));
$cats    = BizProducts::categories($userId);
$tracked = $product && (int)$product['track_stock'] === 1;
$ptype   = BizProducts::typeOf($form);
// گوشی: فهرستِ دستگاه‌های در انبار با IMEI (فقط برای همین نوع — یک کوئری)
$units   = null;
if ($product && BizProducts::typeOf($product) === 'phone') {
    require_once __DIR__ . '/../includes/biz_docs.php';
    $units = BizSerial::inStock($userId, $id, 500);
}
$moves   = $tracked ? BizStock::moves($userId, $id) : [];
$opening = null;
foreach ($moves as $m) { if ($m['kind'] === 'opening') { $opening = $m; } }
$unit    = $product ? (string)$product['unit'] : 'عدد';
// مبلغِ ذخیره‌شده با جداکننده نشان داده می‌شود؛ `sanitizeAmount()` هنگامِ
// ذخیره همان را پاک می‌کند. ورودیِ خامِ فرمِ ردشده دست‌نخورده برمی‌گردد.
$numVal  = fn($v): string => ($v === '' || $v === null) ? '' : (is_numeric($v) ? number_format((float)$v) : (string)$v);

$pageTitle = $product ? (string)$product['name'] : 'کالای تازه';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <div>
        <a class="st-back" href="<?= h(Biz::url('products.php')) ?>">‹ کالاها</a>
        <h1 class="st-h1"><?= h($product ? (string)$product['name'] : 'کالای تازه') ?></h1>
    </div>
    <div class="st-head-actions">
        <?php if ($product && (int)$product['is_active'] !== 1): ?><span class="st-badge is-out">غیرفعال</span><?php endif; ?>
        <?php if ($product && (int)$product['track_stock'] === 1): ?><a class="st-btn st-btn-ghost" href="<?= h(Biz::url('print.php?doc=kardex&id=' . (int)$product['id'])) ?>">چاپِ کاردکس</a><?php endif; ?>
        <?php if ($product): ?><a class="st-btn st-btn-ghost" href="<?= h(Biz::url('labels.php?ids=' . (int)$product['id'] . ((int)$product['has_serial'] === 1 ? '&units=1' : ''))) ?>">چاپِ برچسب</a><?php endif; ?>
        <?php if ($product && (int)($product['has_serial'] ?? 0) === 1): ?><a class="st-btn st-btn-ghost" href="<?= h(Biz::url('print.php?doc=serials&id=' . (int)$product['id'])) ?>">گزارشِ IMEI</a><?php if (BizPartNo::ready()): ?><a class="st-btn st-btn-ghost" href="<?= h(Biz::url('print.php?doc=partnos&id=' . (int)$product['id'])) ?>">پارت‌نامبر</a><?php endif; ?><?php endif; ?>
    </div>
</div>

<?php if ($error !== ''): ?>
<div class="st-flash st-flash-err" role="alert"><?= h($error) ?></div>
<?php endif; ?>

<?php if (!Biz::can('products')): /* نمای خواندنیِ فروشنده — بی‌بهای خرید مگر دسترسیِ «سود» */ ?>
<div class="st-card">
    <dl class="st-dl">
        <dt>قیمتِ فروش</dt><dd class="st-num"><?= formatMoney((int)$product['sell_price']) ?> تومان</dd>
        <?php if ($tracked): ?><dt>موجودی</dt><dd class="st-num"><?= h(BizView::qty($product['stock_qty'], $unit)) ?></dd><?php endif; ?>
        <?php if (Biz::can('cost')): ?><dt>میانگینِ بهای خرید</dt><dd class="st-num"><?= formatMoney((int)round((float)$product['avg_cost'])) ?> تومان</dd><?php endif; ?>
        <?php if ((string)$product['sku'] !== ''): ?><dt>کد / بارکد</dt><dd class="ltr-num"><?= h((string)$product['sku']) ?></dd><?php endif; ?>
        <?php if ((string)($product['note'] ?? '') !== ''): ?><dt>توضیح</dt><dd><?= h((string)$product['note']) ?></dd><?php endif; ?>
    </dl>
</div>
<?php require __DIR__ . '/../includes/biz_foot.php'; return; endif; ?>

<?php if ($tracked): ?>
<div class="st-kpis st-kpis-3">
    <div class="st-kpi-card">
        <span class="st-kpi-label">موجودی</span>
        <span class="st-kpi-value st-num"><?= h(BizView::qty($product['stock_qty'], $unit)) ?></span>
        <?php $st = BizProducts::stockState($product); if ($st === 'low' || $st === 'out'): ?>
            <span class="st-badge is-<?= h($st) ?>"><?= $st === 'out' ? 'ناموجود' : 'کمتر از حداقل' ?></span>
        <?php endif; ?>
    </div>
    <div class="st-kpi-card">
        <span class="st-kpi-label">میانگینِ بهای خرید</span>
        <span class="st-kpi-value st-num"><?= formatMoney((int)round((float)$product['avg_cost'])) ?></span>
        <span class="st-kpi-sub">تومان برای هر <?= h($unit) ?></span>
    </div>
    <div class="st-kpi-card">
        <span class="st-kpi-label">ارزشِ موجودی</span>
        <span class="st-kpi-value st-num"><?= formatMoney((int)round(max(0, (float)$product['stock_qty']) * (float)$product['avg_cost'])) ?></span>
        <span class="st-kpi-sub">تومان</span>
    </div>
</div>
<?php endif; ?>

<div class="st-cols">
<form method="post" class="st-card st-form" action="<?= h($self) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="save">
    <h2 class="st-h2"><?= $product ? 'مشخصاتِ کالا' : 'تعریفِ کالا' ?></h2>

    <label class="st-field">
        <span>نامِ کالا</span>
        <input type="text" name="name" required maxlength="<?= BizProducts::LIMITS['name'] ?>" value="<?= h((string)$form['name']) ?>" <?= $product ? '' : 'autofocus' ?>>
    </label>
    <div class="st-row2">
        <label class="st-field">
            <span>کد یا بارکد <small class="st-muted">(اختیاری)</small></span>
            <input type="text" name="sku" dir="ltr" maxlength="<?= BizProducts::LIMITS['sku'] ?>" value="<?= h((string)$form['sku']) ?>">
        </label>
        <label class="st-field">
            <span>دسته <small class="st-muted">(اختیاری)</small></span>
            <input type="text" name="category" list="bizCats" maxlength="<?= BizProducts::LIMITS['category'] ?>" value="<?= h((string)$form['category']) ?>" placeholder="مثلاً گوشی، لوازم جانبی">
        </label>
    </div>
    <datalist id="bizCats"><?php foreach ($cats as $c): ?><option value="<?= h($c) ?>"><?php endforeach; ?></datalist>

    <div class="st-row2">
        <label class="st-field">
            <span>قیمتِ خرید (تومان)</span>
            <input data-money type="text" name="buy_price" inputmode="numeric" dir="ltr" value="<?= h($numVal($form['buy_price'])) ?>">
        </label>
        <label class="st-field">
            <span>قیمتِ فروش (تومان)<?= $rateOn ? ' <small class="st-muted">— از نرخِ روز</small>' : '' ?></span>
            <input data-money type="text" name="sell_price" inputmode="numeric" dir="ltr" value="<?= h($numVal($form['sell_price'])) ?>"<?= $rateOn ? ' readonly' : '' ?>>
        </label>
    </div>
    <?php /* ⛔ قیمتِ فروش از نرخِ روز (`includes/rates.php`) — اختیاری. وصل که
             باشد، قیمتِ فروش را `BizRates::apply()` با هر نرخِ تازه می‌نویسد
             و خانه‌ی بالا فقط خواندنی است. */ ?>
    <?php if ($rateReady): ?>
    <details class="st-card st-rate-box"<?= $rateOn ? ' open' : '' ?>>
        <summary>قیمتِ فروش از نرخِ روز <small class="st-muted">(دلار، طلا، سکه — اختیاری)</small></summary>
        <div class="st-row3">
            <label class="st-field">
                <span>بر پایه‌ی</span>
                <select name="rate_code"><?= Rates::optionsHtml($rateOn ? (string)$form['rate_code'] : null, 'ندارد — قیمت دستی') ?></select>
            </label>
            <label class="st-field">
                <span>قیمتِ پایه</span>
                <input data-money type="text" name="rate_base" inputmode="decimal" dir="ltr" value="<?= h($rateOn ? rtrim(rtrim((string)$form['rate_base'], '0'), '.') : '') ?>" placeholder="مثلاً 250 (دلار، گرم، عدد…)">
            </label>
            <label class="st-field">
                <span>درصدِ سود <small class="st-muted">(اختیاری)</small></span>
                <input data-num type="text" name="rate_margin" inputmode="decimal" dir="ltr" value="<?= h($rateOn && (float)$form['rate_margin'] != 0 ? rtrim(rtrim((string)$form['rate_margin'], '0'), '.') : '') ?>" placeholder="0">
            </label>
        </div>
        <p class="st-muted">
            قیمتِ فروش = پایه × نرخ × (۱ + سود٪)، گرد به <?= h(BizRates::ROUNDS[Biz::rateRound($userId)] ?? 'هزار تومان') ?>
            (<a href="<?= h(Biz::url('settings.php#rates')) ?>">تنظیم</a>). با هر نرخِ تازه خودش به‌روز می‌شود؛ فاکتورِ صادرشده هرگز عوض نمی‌شود.
            <?php if ($rateOn && Rates::price((string)$form['rate_code']) !== null): ?>
                <br>نرخِ امروزِ <?= h(Rates::label((string)$form['rate_code'])) ?>: <b class="ltr-num"><?= formatMoney((int)Rates::price((string)$form['rate_code'])) ?></b> تومان
                (<?= h(Rates::ago(Rates::all()[(string)$form['rate_code']]['fetched_at'] ?? null)) ?>)
            <?php endif; ?>
        </p>
    </details>
    <?php endif; ?>
    <div class="st-row2">
        <label class="st-field">
            <span>واحد</span>
            <select name="unit">
                <?php foreach (array_keys(BizProducts::UNITS) as $u): ?>
                    <option value="<?= h($u) ?>"<?= $u === (string)$form['unit'] ? ' selected' : '' ?>><?= h($u) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="st-field">
            <span>هشدارِ کم‌موجودی از <small class="st-muted">(۰ = خاموش)</small></span>
            <input data-num type="text" name="min_stock" inputmode="decimal" dir="ltr" value="<?= h((float)($form['min_stock'] ?: 0) > 0 ? rtrim(rtrim((string)$form['min_stock'], '0'), '.') : '') ?>">
        </label>
    </div>

    <?php if (!$product): ?>
    <fieldset class="st-fieldset">
        <legend>موجودیِ فعلیِ انبار <small class="st-muted">(اختیاری)</small></legend>
        <div class="st-row2">
            <label class="st-field">
                <span>تعداد یا مقدار</span>
                <input data-num type="text" name="opening_qty" inputmode="decimal" dir="ltr" value="<?= h((string)($_POST['opening_qty'] ?? '')) ?>">
            </label>
            <label class="st-field">
                <span>بهای خریدِ هر واحد <small class="st-muted">(خالی = قیمتِ خرید)</small></span>
                <input data-money type="text" name="opening_cost" inputmode="numeric" dir="ltr" value="<?= h((string)($_POST['opening_cost'] ?? '')) ?>">
            </label>
        </div>
    </fieldset>
    <?php endif; ?>

    <fieldset class="st-fieldset">
        <legend>نوعِ کالا</legend>
        <div class="st-seg st-seg-sm" role="radiogroup" aria-label="نوعِ کالا">
            <?php foreach (BizProducts::TYPES as $tk => $tl): ?>
            <label class="st-seg-opt"><input type="radio" name="type" value="<?= h($tk) ?>"<?= $ptype === $tk ? ' checked' : '' ?>><span><?= h($tl) ?></span></label>
            <?php endforeach; ?>
        </div>
        <p class="st-muted">گوشی: هر دستگاه با IMEIِ خودش خرید و فروش می‌شود (فاکتورِ خرید، یک ردیف برای هر گوشی). خدمت موجودی ندارد.</p>
    </fieldset>
    <?php if (BizProducts::levelsReady()): ?>
    <fieldset class="st-fieldset">
        <legend>قیمتِ عمده و همکار</legend>
        <input type="hidden" name="levels_form" value="1">
        <div class="st-row2">
            <label class="st-field"><span>قیمتِ عمده <small class="st-muted">(خالی = قیمتِ فروش)</small></span>
                <input data-money type="text" name="price_wholesale" inputmode="numeric" dir="ltr" value="<?= h($numVal($_POST['price_wholesale'] ?? ($product['price_wholesale'] ?? ''))) ?>"></label>
            <label class="st-field"><span>قیمتِ همکار <small class="st-muted">(خالی = قیمتِ فروش)</small></span>
                <input data-money type="text" name="price_colleague" inputmode="numeric" dir="ltr" value="<?= h($numVal($_POST['price_colleague'] ?? ($product['price_colleague'] ?? ''))) ?>"></label>
        </div>
    </fieldset>
    <?php endif; ?>
    <?php if (tableHasColumn('biz_products', 'warranty_months')): ?>
    <fieldset class="st-fieldset">
        <legend>گارانتی</legend>
        <input type="hidden" name="warranty_form" value="1">
        <div class="st-row2">
            <label class="st-field"><span>مدتِ گارانتی (ماه) <small class="st-muted">— ۰ = ندارد</small></span>
                <input data-num type="text" name="warranty_months" inputmode="numeric" dir="ltr" value="<?= h((string)($_POST['warranty_months'] ?? ($product['warranty_months'] ?? '0'))) ?>"></label>
            <label class="st-field"><span>نوع <small class="st-muted">(مثلاً شرکتی، فروشگاه)</small></span>
                <input type="text" name="warranty_label" maxlength="40" value="<?= h((string)($_POST['warranty_label'] ?? ($product['warranty_label'] ?? ''))) ?>"></label>
        </div>
        <p class="st-muted">هنگامِ صدورِ فاکتور روی همان فروش ثبت می‌شود؛ تغییرِ بعدی گارانتیِ فروخته‌شده‌ها را عوض نمی‌کند. استعلام با IMEI از «تعمیرات و گارانتی».</p>
    </fieldset>
    <?php endif; ?>
    <?php if (Biz::accReady()): ?>
    <fieldset class="st-fieldset">
        <legend>مالیات و سامانه‌ی مودیان</legend>
        <input type="hidden" name="tax_form" value="1">
        <label class="st-check"><input type="checkbox" name="vat_exempt" value="1"<?= (int)($product['vat_exempt'] ?? ($_POST['vat_exempt'] ?? 0)) === 1 ? ' checked' : '' ?>> معاف از مالیات بر ارزش افزوده</label>
        <label class="st-field"><span>شناسه‌ی کالا/خدمت <small class="st-muted">(۱۳ رقم، سامانه‌ی مودیان — خالی = پیش‌فرضِ فروشگاه)</small></span>
            <input type="text" name="tax_code" value="<?= h((string)($product['tax_code'] ?? ($_POST['tax_code'] ?? ''))) ?>" inputmode="numeric" dir="ltr" maxlength="13"></label>
    </fieldset>
    <?php endif; ?>
    <label class="st-field">
        <span>یادداشت <small class="st-muted">(اختیاری)</small></span>
        <textarea name="note" rows="2" maxlength="<?= BizProducts::LIMITS['note'] ?>"><?= h((string)$form['note']) ?></textarea>
    </label>
    <button type="submit" class="st-btn"><?= $product ? 'ذخیره‌ی تغییرات' : 'ثبتِ کالا' ?></button>
</form>

<?php if ($tracked):
    require_once __DIR__ . '/../includes/biz_quickbuy.php';
    $qbSerial = BizProducts::typeOf($product) === 'phone';
    $qbLast   = BizQuickBuy::lastSupplier($userId, $id);
    $qbAccts  = BizDocView::accounts($userId);
    $qbHist   = BizQuickBuy::history($userId, $id, 10);
    $qbPend   = BizQuickBuy::pending($userId, $id, $product); ?>
<div>
    <?php /* ⛔ «کالا باید معلوم باشد از کی خریدم» — خریدهای صادرشده‌ی همین کالا */ ?>
    <section class="st-card" id="bought">
        <h2 class="st-h2">از چه کسی خریده شد</h2>
        <?php if (!$qbHist): ?>
            <p class="st-muted">هنوز هیچ فاکتورِ خریدی برای این کالا صادر نشده<?= (float)$product['stock_qty'] > 0 ? ' — موجودیِ فعلی از «اول دوره» یا انبارگردانی است' : '' ?>.</p>
        <?php else: ?>
        <div class="st-table-wrap"><table class="st-table st-table-compact">
            <thead><tr><th>تاریخ</th><th>فروشنده</th><th class="st-td-num">تعداد × فی</th><th>سند</th></tr></thead>
            <tbody>
            <?php foreach ($qbHist as $hh): ?>
                <tr><td class="st-num"><?= h(toJalali($hh['date'])) ?></td><td><?= h($hh['party']) ?></td>
                    <td class="st-td-num"><span class="st-num"><?= h(formatQty($hh['qty'])) ?> <?= h($unit) ?> ×</span> <?= BizDocView::money($hh['price']) ?></td>
                    <td><a href="<?= h(Biz::url('invoice.php?id=' . $hh['invoice_id'])) ?>"><?= h($hh['title']) ?></a></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
        <?php if ($qbPend['drafts'] || $qbPend['loose']): ?>
        <div class="st-sp-pending">
            <p class="st-sp-pending-title"><b>خریدش ثبت شده ولی موجودی نیامده؟</b> فقط موجودی بدهید — خریدِ دوم ساخته نمی‌شود:</p>
            <?php foreach ($qbPend['drafts'] as $d): ?>
            <form method="post" action="<?= h($self) ?>" class="st-sp-pending-row">
                <?= Csrf::field() ?><?= BizOnce::field() ?><input type="hidden" name="action" value="qb_issue"><input type="hidden" name="invoice_id" value="<?= (int)$d['invoice_id'] ?>">
                <span><?= h($d['title']) ?> از <?= h($d['party']) ?> · <?= h(toPersianDigits(formatQty($d['qty']))) ?> <?= h($unit) ?> · <span class="st-muted-i">هنوز صادر نشده</span></span>
                <button type="submit" class="st-btn st-btn-ghost st-btn-sm">صدورِ همین پیش‌نویس</button>
            </form>
            <?php endforeach; ?>
            <?php foreach ($qbPend['loose'] as $l): ?>
            <form method="post" action="<?= h($self) ?>" class="st-sp-pending-row<?= $l['same'] ? ' is-same' : '' ?>" onsubmit="return confirm('این ردیف به همین کالا وصل شود؟ موجودی‌اش به انبار می‌آید؛ مبلغ و فروشنده همان می‌ماند.');">
                <?= Csrf::field() ?><?= BizOnce::field() ?><input type="hidden" name="action" value="qb_link"><input type="hidden" name="line_id" value="<?= (int)$l['line_id'] ?>">
                <span>«<?= h($l['desc']) ?>» در <?= h($l['title']) ?> از <?= h($l['party']) ?> · <?= h(toPersianDigits(formatQty($l['qty']))) ?> × <?= h(formatMoney($l['price'])) ?> · <?= h(toPersianDigits(toJalali($l['date']))) ?> <span class="st-muted-i">— شرحِ آزاد، به انبار نرفته</span></span>
                <button type="submit" class="st-btn st-btn-ghost st-btn-sm">فقط موجودی بده</button>
            </form>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <?php /* ⛔ خرید با فروشنده و بها — فاکتورِ خریدِ صادرشده (`BizQuickBuy`): فروشنده
             طلبکار می‌شود و بهای میانگین درست. انبارگردانیِ پایین فقط برای شمارش است. */ ?>
    <form method="post" class="st-card st-form" action="<?= h($self) ?>" id="quickbuy">
        <?= Csrf::field() ?><?= BizOnce::field() ?>
        <input type="hidden" name="action" value="quickbuy">
        <h2 class="st-h2">خرید و افزودنِ موجودی</h2>
        <p class="st-muted">از چه کسی، چند و به چه قیمتی خریدید. یک فاکتورِ خریدِ صادرشده ساخته می‌شود؛ نسیه به حسابِ فروشنده می‌رود.</p>
        <div class="st-row2">
            <label class="st-field"><span>فروشنده</span>
                <select name="qb_party">
                    <option value="0">— فروشنده‌ی تازه یا گذری —</option>
                    <?php foreach (BizQuickBuy::suppliers($userId) as $p): ?>
                    <option value="<?= (int)$p['id'] ?>"<?= (int)$p['id'] === $qbLast ? ' selected' : '' ?>><?= h($p['name']) ?><?= $p['supplier'] ? '' : ' (مشتری)' ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="st-field"><span>یا نامِ فروشنده‌ی تازه</span><input type="text" name="qb_party_name" maxlength="150" autocomplete="off"></label>
        </div>
        <?php if ($qbSerial): ?>
        <label class="st-field"><span>IMEIِ گوشی‌ها <small class="st-muted">(هر خط یک گوشی؛ دوسیم‌کارت: «IMEI۱ / IMEI۲»)</small></span>
            <textarea name="qb_imeis" rows="3" dir="ltr" inputmode="numeric" autocomplete="off"></textarea>
        </label>
        <?php endif; ?>
        <div class="st-row2">
            <?php if (!$qbSerial): ?>
            <label class="st-field"><span>تعداد (<?= h($unit) ?>)</span><input data-num type="text" name="qb_qty" inputmode="decimal" dir="ltr"></label>
            <?php endif; ?>
            <label class="st-field"><span>قیمتِ خریدِ هر <?= h($unit) ?></span><input data-money type="text" name="qb_buy" inputmode="numeric" dir="ltr" value="<?= (int)$product['buy_price'] > 0 ? h((string)(int)$product['buy_price']) : '' ?>"></label>
        </div>
        <div class="st-row2">
            <label class="st-field"><span>قیمتِ فروش</span><input data-money type="text" name="qb_sell" inputmode="numeric" dir="ltr" value="<?= h((string)(int)$product['sell_price']) ?>"></label>
            <label class="st-field"><span>تاریخِ خرید</span><input data-num type="text" name="qb_date" inputmode="numeric" dir="ltr" value="<?= h(BizDocView::jDate(date('Y-m-d'))) ?>"></label>
        </div>
        <div class="st-row2">
            <div class="st-seg st-seg-sm" role="radiogroup" aria-label="پرداخت">
                <label class="st-seg-opt"><input type="radio" name="qb_pay" value="credit" checked><span>نسیه</span></label>
                <label class="st-seg-opt"><input type="radio" name="qb_pay" value="cash"><span>نقد — پرداخت شد</span></label>
            </div>
            <label class="st-field"><span>صندوق <small class="st-muted">(برای نقد)</small></span>
                <select name="qb_account"><?php foreach ($qbAccts as $a): ?><option value="<?= (int)$a['id'] ?>"><?= h($a['name']) ?></option><?php endforeach; ?></select>
            </label>
        </div>
        <button type="submit" class="st-btn">ثبتِ خرید</button>
    </form>

    <form method="post" class="st-card st-form" action="<?= h($self) ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="adjust">
        <h2 class="st-h2">انبارگردانی</h2>
        <p class="st-muted">فقط برای <b>شمارش</b>: موجودیِ واقعیِ شمارش‌شده را بنویسید؛ تفاوتش ثبت می‌شود. افزایش دلیل می‌خواهد و فروشنده و بهای خرید ندارد — خرید را از «خرید و افزودنِ موجودی» ثبت کنید.</p>
        <div class="st-row2">
            <label class="st-field">
                <span>موجودیِ واقعی (<?= h($unit) ?>)</span>
                <input data-num type="text" name="actual_qty" inputmode="decimal" dir="ltr" required>
            </label>
            <label class="st-field">
                <span>توضیح <small class="st-muted">(اختیاری)</small></span>
                <input type="text" name="note" maxlength="200" placeholder="مثلاً شکستگی، شمارشِ پایانِ ماه">
            </label>
        </div>
        <?php if (BizWarehouses::any($userId)): ?>
        <label class="st-field"><span>کدام انبار شمرده شد</span>
            <select name="warehouse_id"><option value="0"><?= h(BizWarehouses::MAIN) ?></option><?php foreach (BizWarehouses::list($userId, true) as $w): ?><option value="<?= (int)$w['id'] ?>"><?= h((string)$w['name']) ?></option><?php endforeach; ?></select></label>
        <?php endif; ?>
        <button type="submit" class="st-btn st-btn-ghost">ثبتِ انبارگردانی</button>
    </form>

    <form method="post" class="st-card st-form" action="<?= h($self) ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="opening">
        <h2 class="st-h2">موجودیِ اول دوره</h2>
        <p class="st-muted">موجودیِ انبار پیش از شروعِ کار با این پنل. عوض کردنش همه‌ی عددها را از نو حساب می‌کند.</p>
        <div class="st-row2">
            <label class="st-field">
                <span>مقدار (<?= h($unit) ?>)</span>
                <input data-num type="text" name="opening_qty" inputmode="decimal" dir="ltr" value="<?= $opening ? h(formatQty($opening['qty'])) : '' ?>">
            </label>
            <label class="st-field">
                <span>بهای خریدِ هر واحد</span>
                <input data-money type="text" name="opening_cost" inputmode="numeric" dir="ltr" value="<?= $opening ? h((string)(int)$opening['unit_cost']) : '' ?>">
            </label>
        </div>
        <button type="submit" class="st-btn st-btn-ghost">ذخیره‌ی موجودیِ اول دوره</button>
    </form>
</div>
<?php endif; ?>
</div>

<?php if ($units !== null): ?>
<section class="st-card" id="imei">
    <div class="st-card-head">
        <h2 class="st-h2">گوشی‌های در انبار <small class="st-muted">(<?= toPersianDigits((string)count($units['rows'])) ?>)</small></h2>
        <a href="<?= h(Biz::url('invoice-edit.php?k=purchase')) ?>">+ خریدِ گوشیِ تازه</a>
    </div>
    <?php if (!$units['rows']): ?>
        <p class="st-empty">هیچ گوشیِ IMEIداری از این مدل در انبار نیست. گوشی با فاکتورِ خرید وارد می‌شود (یک ردیف برای هر دستگاه، با IMEI ۱ و ۲).</p>
    <?php else: ?>
    <ul class="st-unit-list">
        <?php foreach ($units['rows'] as $u): ?>
        <li><span class="st-num" dir="ltr"><?= h($u['imei1']) ?></span><?php if ($u['imei2'] !== null): ?><span class="st-num st-muted" dir="ltr"><?= h($u['imei2']) ?></span><?php endif; ?></li>
        <?php endforeach; ?>
    </ul>
    <?php if ($units['capped']): ?><p class="st-muted">فهرست بریده شده است.</p><?php endif; ?>
    <?php endif; ?>
    <?php if ((float)$product['stock_qty'] > count($units['rows'])): ?>
        <p class="st-muted">موجودیِ انبار <?= h(formatQty($product['stock_qty'])) ?> است ولی <?= toPersianDigits((string)count($units['rows'])) ?> گوشی IMEIِ ثبت‌شده دارد — بقیه پیش از ثبتِ IMEI (موجودیِ اول دوره یا انبارگردانی) وارد شده‌اند و هنگامِ فروش IMEIشان نوشته می‌شود.</p>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($tracked): ?>
<section class="st-card">
    <h2 class="st-h2">حرکت‌های انبار</h2>
    <?php if (!$moves): ?>
        <p class="st-empty">هنوز هیچ حرکتی ثبت نشده است.</p>
    <?php else: ?>
    <ul class="st-list">
        <?php foreach ($moves as $m): $mq = (float)$m['qty']; ?>
        <li class="st-list-row">
            <span>
                <?php if ($m['ref_type'] === BizStock::REF_INVOICE): ?>
                <a href="<?= h(Biz::url('invoice.php?id=' . (int)$m['ref_id'])) ?>"><b><?= h(BizStock::KINDS[$m['kind']] ?? (string)$m['kind']) ?></b></a>
                <?php else: ?>
                <b><?= h(BizStock::KINDS[$m['kind']] ?? (string)$m['kind']) ?></b>
                <?php endif; ?>
                <span class="st-muted-i"><?= h(toJalali((string)$m['move_date'])) ?></span>
                <?php if ((string)$m['note'] !== ''): ?><span class="st-muted-i">· <?= h((string)$m['note']) ?></span><?php endif; ?>
            </span>
            <span class="st-move-end">
                <span class="st-num <?= $mq < 0 ? 'is-neg' : 'is-pos' ?>"><?= $mq > 0 ? '+' : '−' ?><?= h(formatQty(abs($mq))) ?></span>
                <?php if ($m['kind'] === 'adjust'): ?>
                <form method="post" action="<?= h($self) ?>" onsubmit="return confirm('این انبارگردانی حذف شود؟');">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="delete_move">
                    <input type="hidden" name="move_id" value="<?= (int)$m['id'] ?>">
                    <button type="submit" class="st-link-btn" aria-label="حذفِ این حرکت">حذف</button>
                </form>
                <?php endif; ?>
            </span>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($product): ?>
<section class="st-card st-danger">
    <div class="st-danger-row">
        <form method="post" action="<?= h($self) ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="toggle">
            <button type="submit" class="st-btn st-btn-ghost"><?= (int)$product['is_active'] === 1 ? 'غیرفعال کردن' : 'فعال کردن' ?></button>
        </form>
        <form method="post" action="<?= h($self) ?>" onsubmit="return confirm('کالا و همه‌ی حرکت‌های انبارش حذف شود؟ این کار برگشت ندارد.');">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="delete">
            <button type="submit" class="st-btn st-btn-danger">حذفِ کالا</button>
        </form>
    </div>
</section>
<?php endif; ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
