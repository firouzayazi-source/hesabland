<?php
/**
 * فروشِ سریع (پیشخوان) — کارِ روزانه‌ی مغازه در کمترین تپ: بارکد یا نام را
 * بزن، مقدار را درست کن، «ثبت و چاپِ فیش».
 *
 * ⛔ هیچ مسیرِ دومی برای فروش نیست: همان `saveDraft()` + `issue()`ِ
 *    فاکتورِ فروش، با پیش‌فرض‌های پیشخوان (مشتریِ گذری، دریافتِ کامل).
 *    اگر صدور شکست بخورد (مثلاً کالا موجود نیست)، پیش‌نویس پاک می‌شود و
 *    فرم با همان ردیف‌ها و پیامِ علت برمی‌گردد — پیش‌نویسِ فراموش‌شده‌ای
 *    در فهرست جا نمی‌ماند.
 * ⛔ بی‌جاوااسکریپت هم کار می‌کند؛ `store.js` فقط خواندنِ بارکد (Enter)،
 *    افزایشِ مقدارِ ردیفِ تکراری و جمعِ زنده را اضافه می‌کند.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_docview.php';
require_once __DIR__ . '/../includes/biz_print.php';

$userId   = Biz::ownerId();
$self     = Biz::url('quick-sale.php');
$accounts = BizDocView::accounts($userId);
$form = ['party_id' => 0, 'lines' => [], 'discount' => '', 'pay_mode' => 'full', 'pay_amount' => '',
         'account_id' => (int)($accounts[0]['id'] ?? 0), 'method' => 'cash'];
$error = '';
$blank = 5;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $action = postParam('action');
    $lines  = is_array($_POST['lines'] ?? null) ? $_POST['lines'] : [];
    $form = array_merge($form, [
        'party_id' => (int)postParam('party_id'), 'discount' => postParam('discount'),
        'lines' => array_values(array_filter($lines, fn($l) => is_array($l)
            && (trim((string)($l['item'] ?? '')) !== '' || trim((string)($l['price'] ?? '')) !== ''))),
        'pay_mode' => in_array(postParam('pay_mode'), ['none', 'full', 'part'], true) ? postParam('pay_mode') : 'full',
        'pay_amount' => postParam('pay_amount'), 'account_id' => (int)postParam('account_id'),
        'method' => isset(BizPay::quickMethods()[postParam('method')]) ? postParam('method') : 'cash',
    ]);
    if ($action === 'addrows') {
        $blank = 6;
    } else {
        // ⛔ دو بار زدنِ «ثبت» دو فروش نمی‌سازد (`BizOnce`)
        if (($dup = BizOnce::claim()) !== null) { BizOnce::redirectDuplicate($dup, $self); }
        $res = BizInvoices::saveDraft($userId, 'sale', [
            'party_id' => $form['party_id'], 'inv_date' => date('Y-m-d'), 'discount' => $form['discount'], 'lines' => $lines,
        ]);
        if (!$res['ok']) {
            BizOnce::release();
            $error = $res['message'];
        } else {
            $id = (int)$res['id'];
            // ⛔ پیش‌نویسِ فروشِ سریع هرگز جا نمی‌ماند — حتی با استثنا (بازرسیِ
            //    مهر ۱۴۰۵: بن‌بست از `deleteDraft()` می‌پرید و پیش‌نویسِ یتیم می‌ماند).
            try {
                $r = BizInvoices::issue($userId, $id, [
                    'account_id' => $form['account_id'], 'method' => $form['method'], 'full' => $form['pay_mode'] === 'full',
                    'amount' => $form['pay_mode'] === 'part' ? $form['pay_amount'] : '0', 'part' => $form['pay_mode'] === 'part',
                ]);
            } catch (Throwable $e) {
                BizInvoices::deleteDraft($userId, $id);
                throw $e;
            }
            if ($r['ok']) {
                BizOnce::done(Biz::url('invoice.php?id=' . $id));
                if ($action === 'sale_print') {
                    header('Location: ' . BizPrint::url('invoice', ['id' => $id, 'back' => 'quick']));
                    exit;
                }
                redirectWithMessage($self, ($r['imei_warn'] ?? '') !== '' ? 'warning' : 'success', $r['message']);
            }
            BizInvoices::deleteDraft($userId, $id);
            BizOnce::release();
            $error = $r['message'];
        }
    }
}

$parsed = BizInvoices::parseLines($userId, $form['lines']);
$form['lines'] = BizDocView::mergeMeta($form['lines'], $parsed['meta']);
$vatQs = Biz::vatRate($userId);                          // فروشِ سریع همیشه نرخِ امروزِ فروشگاه
$tot = BizInvoices::totals($parsed['lines'], sanitizeAmount($form['discount']), 0, $vatQs);
if (count($parsed['lines']) === count($form['lines'])) {
    foreach ($form['lines'] as $k => $l) { $form['lines'][$k]['line_total'] = $parsed['lines'][$k]['line_total']; }
}
$parties = BizDocView::parties($userId, 2000, false);   // منو مانده نشان نمی‌دهد
Biz::$navActive = 'quick-sale.php';

$pageTitle = 'فروش سریع';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <h1 class="st-h1">فروش سریع</h1>
    <div class="st-head-actions">
        <a class="st-btn st-btn-ghost" href="<?= h(Biz::url('invoice-edit.php?k=sale')) ?>">فاکتورِ کامل</a>
    </div>
</div>
<?php if ($error !== ''): ?><div class="st-flash st-flash-err" role="alert"><?= h($error) ?></div><?php endif; ?>

<form method="post" action="<?= h($self) ?>" class="st-docform st-pos" data-invoice data-price="sell" data-pos>
    <?= Csrf::field() ?><?= BizOnce::field() ?>
    <section class="st-card st-scan">
        <label class="st-field">
            <span>بارکد یا نامِ کالا <small class="st-muted">— با Enter به فهرست اضافه می‌شود</small></span>
            <input type="text" list="bizProducts" autocomplete="off" autofocus data-scan placeholder="بارکد، IMEI یا نام — اسکن کنید یا بنویسید…">
        </label>
    </section>

    <section class="st-card st-lines-card">
        <div class="st-table-wrap st-lines-wrap">
            <table class="st-table st-lines">
                <?= BizDocView::lineHead() ?>
                <tbody data-lines><?= BizDocView::lineRows($form['lines'], $blank) ?></tbody>
            </table>
        </div>
        <div class="st-lines-tools">
            <button type="submit" name="action" value="addrows" class="st-link-btn" formnovalidate data-add-rows>+ ردیفِ بیشتر</button>
        </div>
    </section>

    <div class="st-doc-foot">
        <section class="st-card st-sumbox">
            <div class="st-sum-line"><span>جمعِ ردیف‌ها</span><b class="st-num" data-subtotal><?= formatMoney($tot['subtotal']) ?></b></div>
            <label class="st-sum-line"><span>تخفیف</span><input type="text" name="discount" value="<?= h((string)$form['discount']) ?>" inputmode="numeric" dir="ltr" data-discount></label>
            <?php if ($vatQs > 0): ?>
            <input type="hidden" value="<?= h(BizInvoices::rateText($vatQs)) ?>" data-vat-rate>
            <div class="st-sum-line"><span>مالیات بر ارزش افزوده (<?= h(BizView::pct($vatQs)) ?>)</span><b class="st-num" data-tax><?= formatMoney($tot['tax']) ?></b></div>
            <?php endif; ?>
            <div class="st-sum-line st-sum-total"><span>مبلغِ قابلِ پرداخت</span><b class="st-num" data-total><?= formatMoney($tot['total']) ?></b></div>
        </section>
        <section class="st-card st-paybox">
            <div class="st-row2">
                <label class="st-field"><span>مشتری</span>
                    <select name="party_id">
                        <option value="0">مشتریِ گذری</option>
                        <?php foreach ($parties as $p): ?><option value="<?= (int)$p['id'] ?>"<?= (int)$p['id'] === (int)$form['party_id'] ? ' selected' : '' ?>><?= h($p['name']) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label class="st-field"><span>صندوق</span>
                    <select name="account_id" data-acc-auto><?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id'] ?>" data-kind="<?= h((string)($a['kind'] ?? '')) ?>"<?= (int)$a['id'] === (int)$form['account_id'] ? ' selected' : '' ?>><?= h($a['name']) ?></option><?php endforeach; ?></select>
                </label>
            </div>
            <div class="st-seg st-seg-sm">
                <?php foreach (BizPay::quickMethods() as $mk => $ml): ?>
                <label class="st-seg-opt"><input type="radio" name="method" value="<?= h($mk) ?>"<?= $mk === $form['method'] ? ' checked' : '' ?>><span><?= h($ml) ?></span></label>
                <?php endforeach; ?>
            </div>
            <div class="st-seg st-seg-sm">
                <?php foreach (['full' => 'دریافتِ کامل', 'part' => 'بخشی', 'none' => 'نسیه (فقط با مشتریِ ثبت‌شده)'] as $pm => $pl): ?>
                <label class="st-seg-opt"><input type="radio" name="pay_mode" value="<?= $pm ?>"<?= $form['pay_mode'] === $pm ? ' checked' : '' ?>><span><?= h($pl) ?></span></label>
                <?php endforeach; ?>
            </div>
            <label class="st-field"><span>مبلغِ دریافتی (برای «بخشی»)</span><input type="text" name="pay_amount" value="<?= h((string)$form['pay_amount']) ?>" inputmode="numeric" dir="ltr"></label>
        </section>
    </div>
    <div class="st-actionbar st-actionbar-sticky">
        <button type="submit" name="action" value="sale_print" class="st-btn">ثبت و چاپِ فیش</button>
        <button type="submit" name="action" value="sale" class="st-btn st-btn-ghost">ثبت</button>
    </div>
</form>
<?= BizDocView::productDatalist($userId, 'bizProducts', 2000, true) ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
