<?php
/**
 * برگشت از فروش / برگشت از خرید — **فقط از روی فاکتورِ اصلی** (`?inv=`).
 *
 * هر ردیف مقدارِ برگشت‌پذیرِ خودش را دارد (مقدارِ اصلی منهای برگشتی‌های
 * قبلی) و بهایش همان «خالصِ» ردیفِ اصلی است، پس مبلغِ برگشت همان است که
 * واقعاً دست‌به‌دست شد. ساخت و صدور در یک تراکنش (`createReturn`).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_docview.php';

$userId = Biz::ownerId();
$origId = (int)getParam('inv', '0');
$orig   = BizInvoices::get($userId, $origId);
if (!$orig || !isset(BizInvoices::RETURN_OF[$orig['kind']]) || $orig['status'] !== 'issued') {
    redirectWithMessage(Biz::url('sales.php'), 'error', 'برگشت فقط از فاکتورِ صادرشده‌ی فروش یا خرید زده می‌شود.');
}
$kind = BizInvoices::RETURN_OF[$orig['kind']];
Biz::requireCap(BizRights::docCap($kind));
$side = BizDocView::sideOf($kind);
Biz::$navActive = BizDocView::SIDES[$side]['page'];
$self = Biz::url('return.php?inv=' . $origId);
$left = BizInvoices::returnable($userId, $orig);
$accounts = BizDocView::accounts($userId);
$refundLbl = $kind === 'sale_return' ? 'پس دادنِ پول به مشتری' : 'پس گرفتنِ پول از فروشنده';

$form = ['qty' => [], 'date' => BizDocView::jDate(date('Y-m-d')), 'note' => '',
         'refund' => $orig['party_id'] === null ? 'full' : 'none', 'account_id' => (int)($accounts[0]['id'] ?? 0), 'method' => 'cash'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $form['qty']    = is_array($_POST['qty'] ?? null) ? $_POST['qty'] : [];
    $form['date']   = postParam('ret_date');
    $form['note']   = postParam('note');
    $form['refund'] = postParam('refund') === 'full' ? 'full' : 'none';
    $form['account_id'] = (int)postParam('account_id');
    $form['method'] = isset(BizPay::quickMethods()[postParam('method')]) ? postParam('method') : 'cash';

    $rd = BizDocView::docDate((string)$form['date'], 'تاریخِ برگشت');
    // ⛔ دو بار زدنِ «ثبت» دو برگشت نمی‌سازد (`BizOnce`)
    if ($rd['ok'] && ($dup = BizOnce::claim()) !== null) { BizOnce::redirectDuplicate($dup, Biz::url('invoice.php?id=' . $origId)); }
    $r = !$rd['ok'] ? ['ok' => false, 'message' => $rd['message']] : BizInvoices::createReturn($userId, $origId, $form['qty'],
        ['account_id' => $form['account_id'], 'full' => $form['refund'] === 'full', 'method' => $form['method']],
        $rd['date'], $form['note']);
    if ($r['ok']) {
        BizOnce::done(Biz::url('invoice.php?id=' . (int)$r['id']));
        redirectWithMessage(Biz::url('invoice.php?id=' . (int)$r['id']), ($r['imei_warn'] ?? '') !== '' ? 'warning' : 'success', $r['message']);
    }
    BizOnce::release();
    $error = $r['message'];
}

$pageTitle = BizInvoices::KINDS[$kind];
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <div>
        <a class="st-back" href="<?= h(Biz::url('invoice.php?id=' . $origId)) ?>">‹ <?= h(BizInvoices::title($orig)) ?></a>
        <h1 class="st-h1"><?= h(BizInvoices::KINDS[$kind]) ?></h1>
        <p class="st-muted">از <?= h(BizInvoices::title($orig)) ?> — <?= $orig['party_name'] !== null ? h((string)$orig['party_name']) : 'گذری' ?></p>
    </div>
</div>

<?php if ($error !== ''): ?><div class="st-flash st-flash-err" role="alert"><?= h($error) ?></div><?php endif; ?>

<form method="post" action="<?= h($self) ?>" class="st-docform">
    <?= Csrf::field() ?><?= BizOnce::field() ?>
    <section class="st-card st-lines-card">
        <div class="st-table-wrap st-flat">
            <table class="st-table">
                <thead><tr><th>کالا</th><th class="st-th-num st-hide-sm">فروخته/خریده</th><th class="st-th-num">برگشت‌پذیر</th><th class="st-th-num st-hide-sm">بهای خالص</th><th class="st-th-num">تعدادِ برگشت</th></tr></thead>
                <tbody>
                <?php foreach ($left as $lid => $x): $o = $x['line']; $unitNet = (float)$o['qty'] > 0 ? (int)round((int)$o['net_total'] / (float)$o['qty']) : 0; ?>
                    <tr class="<?= $x['left'] <= 0 ? 'is-inactive' : '' ?>">
                        <td><?= h((string)$o['description']) ?><?= BizDocView::lineSub($o) ?></td>
                        <td class="st-td-num st-hide-sm"><span class="st-num"><?= h(formatQty($o['qty'])) ?></span> <?= h((string)$o['unit']) ?></td>
                        <td class="st-td-num"><span class="st-num"><?= h(formatQty($x['left'])) ?></span></td>
                        <td class="st-td-num st-hide-sm"><?= BizDocView::money($unitNet) ?></td>
                        <td class="st-td-num">
                            <?php if ($x['left'] > 0): ?>
                            <input class="st-qty-in" type="text" name="qty[<?= (int)$lid ?>]" value="<?= h((string)($form['qty'][$lid] ?? '')) ?>" inputmode="decimal" dir="ltr" placeholder="۰" aria-label="تعدادِ برگشتِ <?= h((string)$o['description']) ?>">
                            <?php else: ?><span class="st-muted-i">—</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <div class="st-doc-foot">
        <section class="st-card st-paybox">
            <h2 class="st-h3"><?= h($refundLbl) ?></h2>
            <div class="st-seg st-seg-sm">
                <label class="st-seg-opt"><input type="radio" name="refund" value="full"<?= $form['refund'] === 'full' ? ' checked' : '' ?>><span>همین حالا، کامل</span></label>
                <?php if ($orig['party_id'] !== null): ?>
                <label class="st-seg-opt"><input type="radio" name="refund" value="none"<?= $form['refund'] === 'none' ? ' checked' : '' ?>><span>به حسابش (بعداً)</span></label>
                <?php endif; ?>
            </div>
            <div class="st-row2">
                <label class="st-field"><span>صندوق</span>
                    <select name="account_id" data-acc-auto><?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id'] ?>" data-kind="<?= h((string)($a['kind'] ?? '')) ?>"<?= (int)$a['id'] === (int)$form['account_id'] ? ' selected' : '' ?>><?= h($a['name']) ?></option><?php endforeach; ?></select>
                </label>
                <label class="st-field"><span>روش</span>
                    <select name="method"><?php foreach (BizPay::quickMethods() as $mk => $ml): ?><option value="<?= h($mk) ?>"<?= $mk === $form['method'] ? ' selected' : '' ?>><?= h($ml) ?></option><?php endforeach; ?></select>
                </label>
            </div>
            <?php if ($orig['party_id'] === null): ?><p class="st-muted">فاکتورِ اصلی گذری است، پس پول همین حالا برمی‌گردد.</p><?php endif; ?>
        </section>
        <section class="st-card st-form">
            <label class="st-field"><span>تاریخِ برگشت</span><input type="text" name="ret_date" value="<?= h((string)$form['date']) ?>" dir="ltr" inputmode="numeric"></label>
            <label class="st-field"><span>علت یا توضیح <small class="st-muted">(اختیاری)</small></span><textarea name="note" rows="2" maxlength="<?= BizInvoices::NOTE_MAX ?>"><?= h((string)$form['note']) ?></textarea></label>
        </section>
    </div>
    <div class="st-actionbar">
        <button type="submit" class="st-btn">ثبتِ <?= h(BizInvoices::KINDS[$kind]) ?></button>
    </div>
</form>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
