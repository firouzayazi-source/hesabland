<?php
/**
 * یک طرف‌حساب — تعریف و ویرایش. مانده‌ی اول دوره با یک **جهتِ صریح**
 * وارد می‌شود («او بدهکار است» / «ما بدهکاریم»)، نه با علامتِ منفی که روی
 * کیبوردِ موبایل گم می‌شود.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_docview.php';

$userId = Biz::ownerId();
$id     = (int)getParam('id', '0');
$party  = $id > 0 ? BizParties::get($userId, $id) : null;
if ($id > 0 && !$party) {
    redirectWithMessage(Biz::url('parties.php'), 'error', 'طرف‌حساب پیدا نشد.');
}
$self  = Biz::url('party.php' . ($id > 0 ? '?id=' . $id : ''));
$error = '';
$kindIn = getParam('kind');

$form = [
    'name'           => $party['name'] ?? '',
    'kind'           => $party['kind'] ?? (isset(BizParties::KINDS[$kindIn]) ? $kindIn : 'customer'),
    'phone'          => $party['phone'] ?? '',
    'address'        => $party['address'] ?? '',
    'note'           => $party['note'] ?? '',
    'opening_amount' => $party ? (string)abs((int)$party['opening_balance']) : '',
    'opening_side'   => $party && (int)$party['opening_balance'] < 0 ? 'we' : 'they',
    'national_id'    => (string)($party['national_id'] ?? ''),
    'economic_code'  => (string)($party['economic_code'] ?? ''),
    'postal_code'    => (string)($party['postal_code'] ?? ''),
    'code'           => (string)($party['code'] ?? ''),
    'price_level'    => (string)($party['price_level'] ?? 'retail'),
    'credit_limit'   => (int)($party['credit_limit'] ?? 0) > 0 ? number_format((int)$party['credit_limit']) : '',
    'fx_code'        => (string)($party['fx_code'] ?? ''),
];
// ⚠ ستونِ «ارزِ حساب» (migration_biz_ops) — ردیفِ موجود خودش می‌گوید
$hasFx = $party ? array_key_exists('fx_code', $party) : BizFx::ready();
$hasTerms = $party ? array_key_exists('credit_limit', $party) : BizParties::termsReady();
// ⚠ همان شگرد: ردیفِ موجود می‌گوید ستونِ «کد» آمده یا نه
$hasPcode = $party ? array_key_exists('code', $party) : BizParties::hasPartyCode();
// ردیفِ موجود (`p.*`) خودش می‌گوید ستون‌ها آمده‌اند — بی‌کوئریِ نقشه‌ی ساختار
$hasCodes = $party ? array_key_exists('economic_code', $party) : BizParties::hasCodes();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $action = postParam('action');

    if ($action === 'save') {
        $res = BizParties::save($userId, $_POST, $id);
        // ⛔ سطحِ قیمت و سقفِ اعتبار جدا (`saveTerms()`) — ورود از فایل آن‌ها را نمی‌فرستد
        if ($res['ok'] && isset($_POST['terms_form'])) {
            $tr = BizParties::saveTerms($userId, (int)$res['id'], $_POST);
            if (!$tr['ok']) { redirectWithMessage(Biz::url('party.php?id=' . (int)$res['id']), 'error', 'طرف‌حساب ذخیره شد، ولی ' . $tr['message']); }
        }
        // ⛔ ارزِ حساب جدا (`BizFx::setPartyCode()`) — سندهای گذشته نرخِ خودشان را نگه می‌دارند
        if ($res['ok'] && $hasFx && isset($_POST['fx_form'])) {
            $fr = BizFx::setPartyCode($userId, (int)$res['id'], (string)postParam('fx_code'));
            if (!$fr['ok']) { redirectWithMessage(Biz::url('party.php?id=' . (int)$res['id']), 'error', 'طرف‌حساب ذخیره شد، ولی ' . $fr['message']); }
        }
        if ($res['ok']) {
            redirectWithMessage(Biz::url('party.php?id=' . (int)$res['id']), 'success', $res['message']);
        }
        $error = $res['message'];
        foreach ($form as $k => $_) { if (isset($_POST[$k])) { $form[$k] = (string)$_POST[$k]; } }
    } elseif ($party && $action === 'sms_debt') {
        Biz::requireCap('receive');
        $res = BizComms::send($userId, 'debt', $id);
        redirectWithMessage($self, $res['ok'] ? 'success' : 'error', $res['message']);
    } elseif ($party && $action === 'toggle') {
        $on = (int)$party['is_active'] !== 1;
        $res = BizParties::setActive($userId, $id, $on);
        redirectWithMessage($self, $res['ok'] ? 'success' : 'error', $res['message']);
    } elseif ($party && $action === 'delete') {
        $res = BizParties::delete($userId, $id);
        redirectWithMessage($res['ok'] ? Biz::url('parties.php') : $self, $res['ok'] ? 'success' : 'error', $res['message']);
    } else {
        redirectWithMessage($self, 'error', 'درخواست نامعتبر است.');
    }
}

$pageTitle = $party ? (string)$party['name'] : 'طرف‌حسابِ تازه';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <div>
        <a class="st-back" href="<?= h(Biz::url('parties.php')) ?>">‹ طرف‌حساب‌ها</a>
        <h1 class="st-h1"><?= h($party ? (string)$party['name'] : 'طرف‌حسابِ تازه') ?></h1>
    </div>
    <div class="st-head-actions">
        <?php if ($party && (int)$party['is_active'] !== 1): ?><span class="st-badge is-out">غیرفعال</span><?php endif; ?>
        <?php if ($party): ?><a class="st-btn st-btn-ghost" href="<?= h(Biz::url('print.php?doc=party&id=' . (int)$party['id'])) ?>">چاپِ صورت‌حساب</a><?php endif; ?>
    </div>
</div>

<?php if ($error !== ''): ?>
<div class="st-flash st-flash-err" role="alert"><?= h($error) ?></div>
<?php endif; ?>

<?php if ($party): $bal = (int)$party['balance']; $stmt = BizParties::statement($userId, $id); ?>
<nav class="st-actions" aria-label="سندِ تازه برای این طرف‌حساب">
    <?php if ($party['kind'] !== 'supplier'): ?><a class="st-action is-primary" href="<?= h(Biz::url('invoice-edit.php?k=sale&party=' . $id)) ?>">فاکتور فروش</a><?php endif; ?>
    <?php if ($party['kind'] !== 'customer'): ?><a class="st-action" href="<?= h(Biz::url('invoice-edit.php?k=purchase&party=' . $id)) ?>">فاکتور خرید</a><?php endif; ?>
    <a class="st-action" href="<?= h(Biz::url('payment.php?k=receipt&party=' . $id)) ?>">دریافت از او</a>
    <a class="st-action" href="<?= h(Biz::url('payment.php?k=payment&party=' . $id)) ?>">پرداخت به او</a>
</nav>
<div class="st-kpis st-kpis-1">
    <div class="st-kpi-card">
        <span class="st-kpi-label">مانده</span>
        <?php if ($bal === 0): ?>
            <span class="st-kpi-value">تسویه</span>
        <?php else: ?>
            <span class="st-kpi-value st-num <?= $bal > 0 ? 'is-pos' : 'is-neg' ?>"><?= formatMoney(abs($bal)) ?></span>
            <span class="st-kpi-sub"><?= $bal > 0 ? 'تومان — او به فروشگاه بدهکار است' : 'تومان — فروشگاه به او بدهکار است' ?></span>
        <?php endif; ?>
        <?php $lim = (int)($party['credit_limit'] ?? 0); if ($lim > 0): $room = $lim - max(0, $bal); ?>
            <span class="st-kpi-sub" data-credit-room>سقفِ اعتبار <?= formatMoney($lim) ?> · <?= $room >= 0 ? 'جای خالی ' . formatMoney($room) : '<span class="is-neg">بیش از سقف ' . formatMoney(-$room) . '</span>' ?></span>
        <?php endif; ?>
        <?php if (BizComms::ready() && BizComms::settings($userId)['loyalty_on'] && $party['kind'] !== 'supplier'): $lp = BizLoyalty::balance($userId, $id); ?>
            <span class="st-kpi-sub" data-loyalty>امتیازِ باشگاه: <?= toPersianDigits((string)$lp['balance']) ?> (کسب‌شده <?= toPersianDigits((string)$lp['earned']) ?>، مصرف‌شده <?= toPersianDigits((string)$lp['used']) ?>)</span>
        <?php endif; ?>
        <?php if ($bal > 0 && BizComms::ready() && Biz::can('receive')): ?>
            <form method="post" action="<?= h($self) ?>" class="st-inline"><?= Csrf::field() ?><input type="hidden" name="action" value="sms_debt">
                <button type="submit" class="st-link-btn">پیامکِ یادآوریِ مانده</button></form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($party && $stmt && $stmt['lines']): $rows = array_slice($stmt['lines'], -40); ?>
<section class="st-card">
    <div class="st-card-head">
        <h2 class="st-h2">گردشِ حساب</h2>
        <a href="<?= h(Biz::url('print.php?doc=party&id=' . $id)) ?>">صورت‌حسابِ کامل</a>
    </div>
    <?php if (count($stmt['lines']) > count($rows)): ?><p class="st-muted-i"><?= toPersianDigits((string)count($rows)) ?> ردیفِ آخر از <?= toPersianDigits((string)count($stmt['lines'])) ?>؛ همه در صورت‌حسابِ چاپی.</p><?php endif; ?>
    <div class="st-table-wrap st-flat">
        <table class="st-table st-doc-lines">
            <thead><tr><th class="st-hide-sm">تاریخ</th><th>شرح</th><th class="st-th-num">بدهکار</th><th class="st-th-num">بستانکار</th><th class="st-th-num st-hide-sm">مانده</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $l):
                $href = isset($l['invoice_id']) ? Biz::url('invoice.php?id=' . $l['invoice_id']) : (isset($l['payment_id']) ? Biz::url('payment.php?id=' . $l['payment_id']) : null); ?>
                <tr>
                    <td class="st-hide-sm"><span class="st-num"><?= h(toJalali((string)$l['date'])) ?></span></td>
                    <td><?= $href ? '<a class="st-row-link" href="' . h($href) . '">' . h($l['desc']) . '</a>' : h($l['desc']) ?>
                        <small class="st-show-sm st-muted-i st-num"><?= h(toJalali((string)$l['date'])) ?></small></td>
                    <td class="st-td-num"><?= $l['debit'] ? BizDocView::money((int)$l['debit']) : '' ?></td>
                    <td class="st-td-num"><?= $l['credit'] ? BizDocView::money((int)$l['credit']) : '' ?></td>
                    <td class="st-td-num st-hide-sm"><?= BizDocView::money(abs((int)$l['balance'])) ?> <small class="st-muted-i"><?= $l['balance'] > 0 ? 'بد' : ($l['balance'] < 0 ? 'بس' : '') ?></small></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<form method="post" class="st-card st-form st-form-narrow" action="<?= h($self) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="save">

    <label class="st-field">
        <span>نام</span>
        <input type="text" name="name" required maxlength="<?= BizParties::LIMITS['name'] ?>" value="<?= h((string)$form['name']) ?>" <?= $party ? '' : 'autofocus' ?>>
    </label>
    <fieldset class="st-fieldset">
        <legend>نوع</legend>
        <div class="st-seg">
            <?php foreach (BizParties::KINDS as $k => $l): ?>
            <label class="st-seg-opt">
                <input type="radio" name="kind" value="<?= h($k) ?>"<?= $k === $form['kind'] ? ' checked' : '' ?>>
                <span><?= h($l) ?></span>
            </label>
            <?php endforeach; ?>
        </div>
    </fieldset>
    <?php if ($hasPcode): ?>
    <label class="st-field">
        <span>کدِ طرف‌حساب <small class="st-muted">(اختیاری — همان کدِ نرم‌افزارِ قبلی؛ ورود از فایل با آن تطبیق می‌دهد)</small></span>
        <input type="text" name="code" dir="ltr" maxlength="<?= BizParties::CODE_MAX ?>" value="<?= h((string)$form['code']) ?>">
    </label>
    <?php endif; ?>
    <div class="st-row2">
        <label class="st-field">
            <span>تلفن <small class="st-muted">(اختیاری)</small></span>
            <input type="text" name="phone" dir="ltr" inputmode="tel" maxlength="<?= BizParties::LIMITS['phone'] ?>" value="<?= h((string)$form['phone']) ?>">
        </label>
        <label class="st-field">
            <span>آدرس <small class="st-muted">(اختیاری)</small></span>
            <input type="text" name="address" maxlength="<?= BizParties::LIMITS['address'] ?>" value="<?= h((string)$form['address']) ?>">
        </label>
    </div>
    <?php if ($hasCodes): ?>
    <fieldset class="st-fieldset">
        <legend>اطلاعاتِ رسمی <small class="st-muted">(اختیاری — روی فاکتورِ رسمی زیرِ نامِ خریدار)</small></legend>
        <div class="st-row3">
            <?php foreach (BizParties::CODE_KEYS as $ck): ?>
            <label class="st-field">
                <span><?= h(BizCommon::CODES[$ck][2]) ?></span>
                <input type="text" name="<?= h($ck) ?>" dir="ltr" inputmode="numeric" maxlength="24" value="<?= h((string)$form[$ck]) ?>">
            </label>
            <?php endforeach; ?>
        </div>
    </fieldset>
    <?php endif; ?>
    <fieldset class="st-fieldset">
        <legend>مانده‌ی اول دوره <small class="st-muted">(اختیاری)</small></legend>
        <div class="st-row2">
            <label class="st-field">
                <span>مبلغ (تومان)</span>
                <input data-money type="text" name="opening_amount" inputmode="numeric" dir="ltr" value="<?= h((string)$form['opening_amount'] === '0' ? '' : (string)$form['opening_amount']) ?>">
            </label>
            <label class="st-field">
                <span>جهت</span>
                <select name="opening_side">
                    <option value="they"<?= $form['opening_side'] === 'they' ? ' selected' : '' ?>>او به فروشگاه بدهکار است</option>
                    <option value="we"<?= $form['opening_side'] === 'we' ? ' selected' : '' ?>>فروشگاه به او بدهکار است</option>
                </select>
            </label>
        </div>
    </fieldset>
    <?php if ($hasTerms): ?>
    <fieldset class="st-fieldset">
        <legend>قیمت و اعتبار</legend>
        <input type="hidden" name="terms_form" value="1">
        <div class="st-row2">
            <label class="st-field"><span>سطحِ قیمت</span>
                <select name="price_level"><?php foreach (BizProducts::LEVELS as $lk => $lv): ?><option value="<?= h($lk) ?>"<?= $lk === $form['price_level'] ? ' selected' : '' ?>><?= h($lv['label']) ?></option><?php endforeach; ?></select></label>
            <label class="st-field"><span>سقفِ اعتبار (تومان) <small class="st-muted">— خالی = بی‌سقف</small></span>
                <input data-money type="text" name="credit_limit" inputmode="numeric" dir="ltr" value="<?= h((string)$form['credit_limit']) ?>"></label>
        </div>
        <?php if ($hasFx): ?>
        <input type="hidden" name="fx_form" value="1">
        <label class="st-field"><span>ارزِ حساب</span>
            <select name="fx_code">
                <option value="">تومان</option>
                <?php foreach (BizFx::codes() as $fk => $fl): ?><option value="<?= h($fk) ?>"<?= $fk === $form['fx_code'] ? ' selected' : '' ?>><?= h($fl) ?></option><?php endforeach; ?>
            </select></label>
        <?php endif; ?>
        <p class="st-muted">فاکتورِ این طرف‌حساب با قیمتِ همین سطح پر می‌شود (اگر برای کالا تعریف شده باشد). فروشِ نسیه‌ای که مانده را از سقف بالاتر ببرد صادر نمی‌شود، مگر کسی با دسترسیِ مالی «گذر از سقف» را بزند.<?= $hasFx ? ' با حسابِ ارزی، هر فاکتور و دریافت/پرداخت نرخِ روزِ خودش را نگه می‌دارد و مانده‌ی ارزی و سود/زیانِ نرخ در «هشدارها و پیش‌بینی» دیده می‌شود.' : '' ?></p>
    </fieldset>
    <?php endif; ?>
    <label class="st-field">
        <span>یادداشت <small class="st-muted">(اختیاری)</small></span>
        <textarea name="note" rows="2" maxlength="<?= BizParties::LIMITS['note'] ?>"><?= h((string)$form['note']) ?></textarea>
    </label>
    <button type="submit" class="st-btn"><?= $party ? 'ذخیره‌ی تغییرات' : 'ثبتِ طرف‌حساب' ?></button>
</form>

<?php if ($party): ?>
<section class="st-card st-danger">
    <div class="st-danger-row">
        <form method="post" action="<?= h($self) ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="toggle">
            <button type="submit" class="st-btn st-btn-ghost"><?= (int)$party['is_active'] === 1 ? 'غیرفعال کردن' : 'فعال کردن' ?></button>
        </form>
        <form method="post" action="<?= h($self) ?>" onsubmit="return confirm('این طرف‌حساب حذف شود؟ این کار برگشت ندارد.');">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="delete">
            <button type="submit" class="st-btn st-btn-danger">حذف</button>
        </form>
    </div>
</section>
<?php endif; ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
