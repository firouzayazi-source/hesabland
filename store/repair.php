<?php
/**
 * قبضِ تعمیر — پذیرشِ تازه، ویرایش، وضعیت، فاکتورِ هزینه و چاپِ قبض.
 *
 * ⛔ هیچ پولی این‌جا ثبت نمی‌شود: «فاکتورِ هزینه» یک پیش‌نویسِ فروش می‌سازد
 *    (`BizRepairs::toInvoice()`) و پول و موجودیِ قطعه از همان مسیرِ همیشگی است.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_repairs.php';

$userId = Biz::ownerId();
$id     = (int)getParam('id', '0');
$rep    = $id > 0 ? BizRepairs::get($userId, $id) : null;
if ($id > 0 && !$rep) { redirectWithMessage(Biz::url('repairs.php'), 'error', 'قبض پیدا نشد.'); }
$self  = Biz::url('repair.php' . ($id > 0 ? '?id=' . $id : ''));
$error = '';
$form  = $rep ? array_merge($rep, [
    'received_on' => BizDocView::jDate((string)$rep['received_on']),
    'promised_on' => $rep['promised_on'] ? BizDocView::jDate((string)$rep['promised_on']) : '',
    'estimate'    => (int)$rep['estimate'] > 0 ? number_format((int)$rep['estimate']) : '',
]) : [
    'party_id' => (int)getParam('party', '0'), 'customer_name' => '', 'customer_phone' => '', 'device' => '',
    'imei' => trim(getParam('imei')), 'problem' => '', 'accessories' => '', 'estimate' => '', 'technician_id' => (int)Auth::userId(),
    'received_on' => BizDocView::jDate(date('Y-m-d')), 'promised_on' => '', 'note' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $action = postParam('action');
    if ($action === 'status' && $rep) {
        $r = BizRepairs::setStatus($userId, $id, postParam('status'));
        redirectWithMessage($self, $r['ok'] ? 'success' : 'error', $r['message']);
    }
    if ($action === 'invoice' && $rep) {
        Biz::requireCap('sell');
        $r = BizRepairs::toInvoice($userId, $id);
        if ($r['ok']) { redirectWithMessage(Biz::url('invoice-edit.php?id=' . $r['invoice_id']), 'success', $r['message']); }
        redirectWithMessage($self, 'error', $r['message']);
    }
    $in = array_intersect_key($_POST, array_flip(['party_id', 'customer_name', 'customer_phone', 'device', 'imei', 'problem',
                                                  'accessories', 'estimate', 'technician_id', 'received_on', 'promised_on', 'note']));
    $in = array_map(fn($v) => is_string($v) ? $v : '', $in);
    if ($rep) {
        $r = BizRepairs::update($userId, $id, $in);
        if ($r['ok']) { redirectWithMessage($self, 'success', $r['message']); }
    } else {
        if (($dup = BizOnce::claim()) !== null) { BizOnce::redirectDuplicate($dup, Biz::url('repairs.php')); }
        $r = BizRepairs::create($userId, $in);
        if ($r['ok']) {
            BizOnce::done(Biz::url('repair.php?id=' . $r['id']));
            if (postParam('print') === '1') { header('Location: ' . Biz::url('print.php?doc=repair&id=' . $r['id'])); exit; }
            redirectWithMessage(Biz::url('repair.php?id=' . $r['id']), 'success', $r['message']);
        }
        BizOnce::release();
    }
    $error = $r['message'];
    $form  = array_merge($form, $in);
}

$parties = array_values(array_filter(BizDocView::parties($userId, 2000, false), fn($p) => ($p['kind'] ?? 'customer') !== 'supplier'));
$techs   = BizRepairs::technicians($userId);
$closed  = $rep && !in_array($rep['status'], BizRepairs::OPEN, true);

$pageTitle = $rep ? 'قبضِ تعمیرِ ' . toPersianDigits((string)$rep['number']) : 'پذیرشِ تعمیر';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <div>
        <a class="st-back" href="<?= h(Biz::url('repairs.php')) ?>">‹ تعمیرات</a>
        <h1 class="st-h1"><?= h($pageTitle) ?></h1>
    </div>
    <?php if ($rep): ?>
    <div class="st-head-actions">
        <span class="st-pill is-rep-<?= h((string)$rep['status']) ?>"><?= h(BizRepairs::STATUSES[$rep['status']]) ?></span>
        <a class="st-btn st-btn-ghost" href="<?= h(Biz::url('print.php?doc=repair&id=' . $id)) ?>">چاپِ قبض</a>
    </div>
    <?php endif; ?>
</div>
<?php if ($error !== ''): ?><div class="st-flash st-flash-err" role="alert"><?= h($error) ?></div><?php endif; ?>

<?php if ($rep): ?>
<section class="st-card">
    <h2 class="st-h2">وضعیت</h2>
    <div class="st-status-row">
    <?php foreach (BizRepairs::STATUSES as $sk => $sl): if ($sk === $rep['status']) { continue; } ?>
        <form method="post" action="<?= h($self) ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="status"><input type="hidden" name="status" value="<?= h($sk) ?>">
            <button type="submit" class="st-btn st-btn-ghost st-btn-sm<?= $sk === 'cancelled' ? ' st-link-danger' : '' ?>"><?= h($sl) ?></button>
        </form>
    <?php endforeach; ?>
    </div>
    <div class="st-repair-bill">
        <?php if ($rep['invoice_id'] !== null && $rep['invoice_status'] !== 'void'): ?>
            <p>هزینه: <a class="st-row-link" href="<?= h(Biz::url(($rep['invoice_status'] === 'draft' ? 'invoice-edit.php' : 'invoice.php') . '?id=' . (int)$rep['invoice_id'])) ?>">
                <?= $rep['invoice_status'] === 'draft' ? 'پیش‌نویسِ فاکتور' : 'فاکتورِ فروشِ ' . toPersianDigits((string)$rep['invoice_number']) ?></a>
                — <?= BizDocView::money((int)$rep['invoice_total']) ?> تومان</p>
        <?php elseif (!$closed && Biz::can('sell')): ?>
            <form method="post" action="<?= h($self) ?>">
                <?= Csrf::field() ?><input type="hidden" name="action" value="invoice">
                <button type="submit" class="st-btn">ساختِ فاکتورِ هزینه</button>
                <span class="st-muted-i">یک پیش‌نویسِ فروش با «تعمیرِ <?= h((string)$rep['device']) ?>» به مبلغِ برآورد؛ قطعه‌ها را همان‌جا اضافه کنید.</span>
            </form>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<form method="post" class="st-card st-form" action="<?= h($self) ?>" autocomplete="off">
    <?= Csrf::field() ?><?= $rep ? '' : BizOnce::field() ?>
    <h2 class="st-h2"><?= $rep ? 'مشخصاتِ قبض' : 'پذیرشِ دستگاه' ?></h2>
    <?php if ($closed): ?><p class="st-muted">قبضِ بسته‌شده ویرایش نمی‌شود.</p><?php endif; ?>
    <fieldset <?= $closed ? 'disabled' : '' ?> class="st-fieldset">
    <div class="st-row2">
        <label class="st-field"><span>مشتری</span>
            <select name="party_id">
                <option value="0">— گذری (نام و تلفن پایین) —</option>
                <?php foreach ($parties as $p): ?><option value="<?= (int)$p['id'] ?>"<?= (int)$p['id'] === (int)$form['party_id'] ? ' selected' : '' ?>><?= h($p['name']) ?><?= !empty($p['phone']) ? ' · ' . h((string)$p['phone']) : '' ?></option><?php endforeach; ?>
            </select>
        </label>
        <label class="st-field"><span>تعمیرکار</span>
            <select name="technician_id"><option value="0">—</option><?php foreach ($techs as $tid => $tn): ?><option value="<?= (int)$tid ?>"<?= (int)$tid === (int)$form['technician_id'] ? ' selected' : '' ?>><?= h($tn) ?></option><?php endforeach; ?></select>
        </label>
    </div>
    <div class="st-row2">
        <label class="st-field"><span>نامِ مشتریِ گذری</span><input type="text" name="customer_name" maxlength="<?= BizRepairs::LIMITS['customer_name'] ?>" value="<?= h((string)$form['customer_name']) ?>"></label>
        <label class="st-field"><span>تلفن</span><input type="tel" name="customer_phone" dir="ltr" maxlength="<?= BizRepairs::LIMITS['customer_phone'] ?>" value="<?= h((string)$form['customer_phone']) ?>"></label>
    </div>
    <div class="st-row2">
        <label class="st-field"><span>دستگاه (مدل)</span><input type="text" name="device" required maxlength="<?= BizRepairs::LIMITS['device'] ?>" value="<?= h((string)$form['device']) ?>" placeholder="مثلاً سامسونگ A54"></label>
        <label class="st-field"><span>IMEI <small class="st-muted">(اختیاری)</small></span><input type="text" name="imei" inputmode="numeric" dir="ltr" maxlength="20" value="<?= h((string)$form['imei']) ?>"></label>
    </div>
    <label class="st-field"><span>ایراد</span><textarea name="problem" rows="2" required maxlength="<?= BizRepairs::LIMITS['problem'] ?>"><?= h((string)$form['problem']) ?></textarea></label>
    <label class="st-field"><span>لوازمِ همراه <small class="st-muted">(قاب، شارژر، سیم‌کارت…)</small></span><input type="text" name="accessories" maxlength="<?= BizRepairs::LIMITS['accessories'] ?>" value="<?= h((string)$form['accessories']) ?>"></label>
    <div class="st-row2">
        <label class="st-field"><span>برآوردِ هزینه (تومان)</span><input type="text" name="estimate" inputmode="numeric" dir="ltr" value="<?= h((string)$form['estimate']) ?>"></label>
        <label class="st-field"><span>تاریخِ پذیرش</span><input type="text" name="received_on" dir="ltr" value="<?= h((string)$form['received_on']) ?>"></label>
    </div>
    <label class="st-field st-field-sm"><span>قولِ تحویل <small class="st-muted">(اختیاری)</small></span><input type="text" name="promised_on" dir="ltr" value="<?= h((string)$form['promised_on']) ?>" placeholder="۱۴۰۵/۰۷/۲۵"></label>
    <label class="st-field"><span>یادداشت</span><textarea name="note" rows="2" maxlength="<?= BizRepairs::LIMITS['note'] ?>"><?= h((string)$form['note']) ?></textarea></label>
    </fieldset>
    <?php if (!$closed): ?>
    <div class="st-actionbar">
        <?php if (!$rep): ?><button type="submit" name="print" value="1" class="st-btn">ثبت و چاپِ قبض</button><?php endif; ?>
        <button type="submit" class="st-btn<?= $rep ? '' : ' st-btn-ghost' ?>"><?= $rep ? 'ذخیره' : 'ثبت' ?></button>
    </div>
    <?php endif; ?>
</form>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
