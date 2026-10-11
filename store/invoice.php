<?php
/**
 * نمای یک سندِ صادرشده (یا باطل) — شبیهِ کاغذ، با فهرستِ دریافت/پرداخت و
 * برگشتی‌ها، و اقدام‌ها: ثبتِ دریافت/پرداخت، برگشت، چاپ، برگشت به
 * پیش‌نویس و باطل. پیش‌نویس به ویرایشگر می‌رود.
 *
 * ⛔ هیچ منطقی اینجا نیست؛ هر اقدام یک POST با CSRF به `BizInvoices` است و
 *    بعد ریدایرکت.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_docview.php';
require_once __DIR__ . '/../includes/biz_print.php';

$userId = Biz::ownerId();
$id     = (int)getParam('id', '0');
$inv    = BizInvoices::get($userId, $id);
if (!$inv) {
    redirectWithMessage(Biz::url('sales.php'), 'error', 'سند پیدا نشد.');
}
if ($inv['status'] === 'draft') {
    header('Location: ' . Biz::url('invoice-edit.php?id=' . $id));
    exit;
}
Biz::requireCap(BizRights::docCap((string)$inv['kind']));
$side = BizDocView::sideOf((string)$inv['kind']);
Biz::$navActive = BizDocView::SIDES[$side]['page'];
$self = Biz::url('invoice.php?id=' . $id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $action = postParam('action');
    if (in_array($action, ['void', 'unissue'], true)) { Biz::requireCap('void'); }
    // ⛔ اقساط فقط زمان‌بندی است و معاوضه خریدِ واقعی + تهاترِ جفت (`biz_sales_ext.php`)
    if (in_array($action, ['plan', 'plan_delete', 'tradein'], true)) {
        require_once __DIR__ . '/../includes/biz_sales_ext.php';
        Biz::requireCap($action === 'tradein' ? 'buy' : 'receive');
        if ($action === 'plan') {
            $fd = BizDocView::docDate(postParam('first_due'), 'سررسیدِ قسطِ اول', true);
            $r = $fd['ok'] ? BizInstallments::plan($userId, $id, (int)toLatinDigits(postParam('count')), $fd['date'], postParam('every'))
                           : ['ok' => false, 'message' => $fd['message']];
        } elseif ($action === 'plan_delete') {
            BizInstallments::remove($userId, $id);
            $r = ['ok' => true, 'message' => 'برنامه‌ی اقساط برداشته شد (فاکتور و دریافت‌ها دست نخوردند).'];
        } else {
            $r = BizTradeIn::run($userId, $id, ['product_id' => (int)postParam('ti_product'), 'new_name' => postParam('ti_name'),
                                                'imei' => postParam('ti_imei'), 'value' => postParam('ti_value')]);
        }
        redirectWithMessage($self, $r['ok'] ? 'success' : 'error', $r['message']);
    }
    if ($action === 'void') {
        $r = BizInvoices::void($userId, $id);
    } elseif ($action === 'unissue') {
        $r = BizInvoices::unissue($userId, $id);
        if ($r['ok']) { redirectWithMessage(Biz::url('invoice-edit.php?id=' . $id), 'success', $r['message']); }
    } else {
        $r = ['ok' => false, 'message' => 'درخواست نامعتبر است.'];
    }
    redirectWithMessage($self, $r['ok'] ? 'success' : 'error', $r['message']);
}

$pays    = BizPay::forInvoice($userId, $id);
$remain  = BizInvoices::remaining($inv);
$payKind = BizInvoices::SETTLED_BY[$inv['kind']];
$retKind = BizInvoices::RETURN_OF[$inv['kind']] ?? null;
$st = Database::getConnection()->prepare(
    'SELECT id, kind, number, status, inv_date, total FROM biz_invoices WHERE ref_invoice_id = :id AND user_id = :u ORDER BY id'
);
$st->execute(['id' => $id, 'u' => $userId]);
$returns = $st->fetchAll();
$canReturn = false;
if ($retKind !== null && $inv['status'] === 'issued') {
    foreach (BizInvoices::returnable($userId, $inv) as $r) { if ($r['left'] > 0) { $canReturn = true; break; } }
}
$partyLbl = BizDocView::SIDES[$side]['party'];
// مانده‌ی قبلی و کلِ طرف‌حساب — فقط سندِ صادرشده‌ی دارای طرف‌حساب
$balance = $inv['status'] === 'issued' && $inv['party_id'] !== null
    ? BizParties::balanceAround($userId, (int)$inv['party_id'], $id) : null;

$invPrefs = Biz::invoicePrefs($userId);
$costWarn = $invPrefs['warn_below_cost'] && $inv['status'] === 'issued' ? BizInvoices::belowCost($inv) : [];
$profit   = $invPrefs['show_profit'] ? BizInvoices::profit($inv) : null;

$pageTitle = BizInvoices::title($inv);
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <div>
        <a class="st-back" href="<?= h(Biz::url(BizDocView::SIDES[$side]['page']) . (in_array($inv['kind'], ['sale_return', 'purchase_return'], true) ? '?t=returns' : '')) ?>">‹ <?= h(BizDocView::SIDES[$side]['title']) ?></a>
        <h1 class="st-h1"><?= h(BizInvoices::title($inv)) ?> <?= BizDocView::pill($inv) ?></h1>
    </div>
    <div class="st-head-actions">
        <a class="st-btn st-btn-ghost" href="<?= h(BizPrint::url('invoice', ['id' => $id])) ?>">چاپ</a>
        <?php if ($inv['status'] === 'issued' && $remain > 0): ?>
            <a class="st-btn" href="<?= h(Biz::url('payment.php?k=' . $payKind . '&inv=' . $id)) ?>"><?= $payKind === 'receipt' ? 'ثبتِ دریافت' : 'ثبتِ پرداخت' ?></a>
        <?php endif; ?>
        <?php if ($canReturn): ?>
            <a class="st-btn st-btn-ghost" href="<?= h(Biz::url('return.php?inv=' . $id)) ?>"><?= h(BizInvoices::KINDS[$retKind]) ?></a>
        <?php endif; ?>
    </div>
</div>

<?php if ($costWarn): ?>
<div class="st-flash st-flash-warn" role="status">زیرِ بهای خرید فروخته شد: <?= h(implode('، ', array_map(fn($w) => '«' . $w['desc'] . '» ' . formatMoney($w['per']) . ' (بها ' . formatMoney($w['cost']) . ')', $costWarn))) ?></div>
<?php endif; ?>
<article class="st-card st-docview">
    <dl class="st-docmeta">
        <div><dt><?= h($partyLbl) ?></dt><dd><?php if ($inv['party_id'] !== null): ?><a href="<?= h(Biz::url('party.php?id=' . (int)$inv['party_id'])) ?>"><?= h((string)$inv['party_name']) ?></a><?php else: ?>گذری<?php endif; ?></dd></div>
        <div><dt>تاریخ</dt><dd class="st-num"><?= h(toJalali((string)$inv['inv_date'])) ?></dd></div>
        <?php if (!empty($inv['due_date'])): ?><div><dt>سررسید</dt><dd class="st-num"><?= h(toJalali((string)$inv['due_date'])) ?></dd></div><?php endif; ?>
        <?php if ($inv['ref_invoice_id'] !== null): ?>
            <div><dt>فاکتورِ اصلی</dt><dd><a href="<?= h(Biz::url('invoice.php?id=' . (int)$inv['ref_invoice_id'])) ?>"><?= h(BizInvoices::KINDS[$inv['ref_kind']] ?? '') ?> <?= toPersianDigits((string)$inv['ref_number']) ?></a></dd></div>
        <?php endif; ?>
    </dl>

    <div class="st-table-wrap st-flat">
        <table class="st-table st-doc-lines">
            <thead><tr><th class="st-hide-sm">#</th><th>کالا</th><th class="st-th-num">تعداد</th><th class="st-th-num st-hide-sm">فی</th><th class="st-th-num st-hide-sm">تخفیف</th><th class="st-th-num">جمع</th></tr></thead>
            <tbody>
            <?php foreach ($inv['lines'] as $n => $l): ?>
                <tr>
                    <td class="st-num st-hide-sm"><?= toPersianDigits((string)($n + 1)) ?></td>
                    <td>
                        <?php if ($l['product_id'] !== null): ?><a class="st-row-link" href="<?= h(Biz::url('product.php?id=' . (int)$l['product_id'])) ?>"><?= h((string)$l['description']) ?></a>
                        <?php else: ?><?= h((string)$l['description']) ?> <span class="st-line-note">شرحِ آزاد</span><?php endif; ?>
                        <?= BizDocView::lineSub($l) ?><?= BizDocView::luhnBadge($l) ?>
                    </td>
                    <td class="st-td-num"><span class="st-num"><?= h(formatQty($l['qty'])) ?></span> <?= h((string)$l['unit']) ?></td>
                    <td class="st-td-num st-hide-sm"><?= BizDocView::money((int)$l['unit_price']) ?></td>
                    <td class="st-td-num st-hide-sm"><?= (int)$l['line_discount'] > 0 ? BizDocView::money((int)$l['line_discount']) : '' ?></td>
                    <td class="st-td-num"><?= BizDocView::money((int)$l['line_total']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="st-doc-totals">
        <?php $invTax = (int)($inv['tax_total'] ?? 0); ?>
        <?php if ((int)$inv['discount'] > 0 || (int)$inv['extra'] > 0 || $invTax > 0): ?>
            <div class="st-sum-line"><span>جمعِ ردیف‌ها</span><?= BizDocView::money((int)$inv['subtotal']) ?></div>
            <?php if ((int)$inv['discount'] > 0): ?><div class="st-sum-line"><span>تخفیف</span><?= BizDocView::money(-(int)$inv['discount']) ?></div><?php endif; ?>
            <?php if ((int)$inv['extra'] > 0): ?><div class="st-sum-line"><span>حمل و هزینه‌ی دیگر</span><?= BizDocView::money((int)$inv['extra']) ?></div><?php endif; ?>
            <?php if ($invTax > 0): ?><div class="st-sum-line"><span>مالیات بر ارزش افزوده (<?= h(BizView::pct((float)($inv['vat_rate'] ?? 0))) ?>)</span><?= BizDocView::money($invTax) ?></div><?php endif; ?>
        <?php endif; ?>
        <div class="st-sum-line st-sum-total"><span>مبلغِ سند</span><?= BizDocView::money((int)$inv['total']) ?></div>
        <?php if ($inv['status'] === 'issued'): ?>
            <div class="st-sum-line"><span><?= $payKind === 'receipt' ? 'دریافت‌شده' : 'پرداخت‌شده' ?></span><?= BizDocView::money((int)$inv['paid']) ?></div>
            <div class="st-sum-line st-sum-due"><span>مانده</span><?= BizDocView::money($remain) ?></div>
        <?php endif; ?>
        <?php if ($profit !== null): ?>
            <div class="st-sum-line st-sum-profit"><span>سودِ این فاکتور <small class="st-muted-i">(روی چاپ نمی‌آید)</small></span><?= BizDocView::money($profit) ?></div>
        <?php endif; ?>
        <?php if ($balance !== null): ?>
            <div class="st-balbox">
                <?= BizDocView::balanceRows($balance, (string)$inv['party_name'], fn(int $v): string => BizDocView::money($v)) ?>
                <a class="st-field-link" href="<?= h(Biz::url('party.php?id=' . (int)$inv['party_id'])) ?>">صورت‌حساب و اصلاحِ مانده‌ی اول دوره ›</a>
            </div>
        <?php endif; ?>
    </div>
    <?php if ((string)$inv['note'] !== ''): ?><p class="st-muted st-docnote"><?= nl2br(h((string)$inv['note'])) ?></p><?php endif; ?>
    <?php if ($inv['status'] === 'void'): ?><p class="st-flash st-flash-err">این سند باطل شده؛ اثرش بر موجودی و مانده برگشته است.</p><?php endif; ?>
</article>

<div class="st-cols">
    <section class="st-card">
        <h2 class="st-h2"><?= $payKind === 'receipt' ? 'دریافت‌ها' : 'پرداخت‌ها' ?></h2>
        <?php if (!$pays): ?>
            <p class="st-empty">هنوز چیزی به این سند نخورده است.</p>
        <?php else: ?>
        <ul class="st-list">
            <?php foreach ($pays as $p): ?>
            <li class="st-list-row">
                <a href="<?= h(Biz::url('payment.php?id=' . (int)$p['id'])) ?>"><?= h(BizPay::KINDS[$p['kind']] ?? '') ?> <?= toPersianDigits((string)$p['number']) ?>
                    <span class="st-muted-i"><?= h(toJalali((string)$p['pay_date'])) ?> · <?= h((string)$p['account_name']) ?> · <?= h(BizPay::METHODS[$p['method']] ?? '') ?></span></a>
                <?= BizDocView::money((int)$p['applied']) ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>
    <?php if ($returns || $retKind !== null): ?>
    <section class="st-card">
        <h2 class="st-h2">برگشتی‌ها</h2>
        <?php if (!$returns): ?>
            <p class="st-empty"><?= $canReturn ? 'از این فاکتور برگشتی ثبت نشده است.' : 'چیزی برای برگشت نمانده است.' ?></p>
        <?php else: ?>
        <ul class="st-list">
            <?php foreach ($returns as $r): ?>
            <li class="st-list-row<?= $r['status'] === 'void' ? ' is-inactive' : '' ?>">
                <a href="<?= h(Biz::url('invoice.php?id=' . (int)$r['id'])) ?>"><?= h(BizInvoices::title($r)) ?> <span class="st-muted-i"><?= h(toJalali((string)$r['inv_date'])) ?></span></a>
                <?= BizDocView::money((int)$r['total']) ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</div>

<?php if ($inv['kind'] === 'sale' && $inv['status'] === 'issued' && $inv['party_id'] !== null):
    require_once __DIR__ . '/../includes/biz_sales_ext.php';
    $sched = BizInstallments::schedule($userId, $id);
    $canPlan = $remain > 0 && Biz::can('receive') && BizInstallments::ready(); ?>
<?php if ($sched || $canPlan): ?>
<section class="st-card" id="installments">
    <h2 class="st-h2">اقساط</h2>
    <?php if ($sched): ?>
    <div class="st-table-wrap">
        <table class="st-table st-table-compact">
            <thead><tr><th>قسط</th><th>سررسید</th><th class="st-th-num">مبلغ</th><th class="st-th-num">پرداخت‌شده</th><th>وضعیت</th></tr></thead>
            <tbody>
            <?php foreach ($sched as $q): ?>
                <tr class="<?= $q['state'] === 'paid' ? 'is-inactive' : '' ?>">
                    <td><span class="st-num"><?= toPersianDigits((string)$q['seq']) ?></span></td>
                    <td><span class="st-num"><?= h(toJalali($q['due_date'])) ?></span></td>
                    <td class="st-td-num"><?= BizDocView::money($q['amount']) ?></td>
                    <td class="st-td-num"><?= BizDocView::money($q['paid']) ?></td>
                    <td><span class="st-pill is-inst-<?= h($q['state']) ?>"><?= h(BizInstallments::STATES[$q['state']]) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="st-muted">هر دریافت از همین مشتری (از «ثبتِ دریافت») به ترتیبِ سررسید قسط‌ها را پر می‌کند.</p>
    <?php if (Biz::can('receive')): ?>
    <form method="post" action="<?= h($self) ?>" onsubmit="return confirm('برنامه‌ی اقساط برداشته شود؟ فاکتور و دریافت‌ها دست نمی‌خورند.');">
        <?= Csrf::field() ?><input type="hidden" name="action" value="plan_delete">
        <button type="submit" class="st-link-btn st-link-danger">برداشتنِ برنامه</button>
    </form>
    <?php endif; ?>
    <?php elseif ($canPlan): ?>
    <form method="post" class="st-inline-form" action="<?= h($self) ?>">
        <?= Csrf::field() ?><input type="hidden" name="action" value="plan">
        <label class="st-field st-field-sm"><span>تعدادِ قسط</span><input type="text" name="count" inputmode="numeric" dir="ltr" required value="۶"></label>
        <label class="st-field st-field-sm"><span>قسطِ اول</span><input type="text" name="first_due" dir="ltr" required value="<?= h(BizDocView::jDate(date('Y-m-d', strtotime('+1 month')))) ?>"></label>
        <label class="st-field st-field-sm"><span>فاصله</span><select name="every"><?php foreach (BizInstallments::EVERY as $ek => $el): ?><option value="<?= h($ek) ?>"><?= h($el) ?></option><?php endforeach; ?></select></label>
        <button type="submit" class="st-btn st-btn-ghost">ساختِ اقساط</button>
    </form>
    <p class="st-muted">مانده‌ی <?= BizDocView::money($remain) ?> تومان به قسط‌های برابر تقسیم می‌شود (باقیمانده به قسطِ آخر). پیش‌پرداخت را پیش از این با «ثبتِ دریافت» بزنید؛ سودِ اقساط را پیش از صدور در «اضافات»ِ فاکتور بگذارید.</p>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php if ($remain > 0 && Biz::can('buy') && Biz::can('sell')):
    $phones = BizTradeIn::phones($userId); ?>
<section class="st-card" id="tradein">
    <h2 class="st-h2">معاوضه — خریدِ گوشیِ کهنه‌ی همین مشتری</h2>
    <form method="post" class="st-form" action="<?= h($self) ?>" autocomplete="off">
        <?= Csrf::field() ?><input type="hidden" name="action" value="tradein">
        <div class="st-row2">
            <label class="st-field"><span>کالا (گوشیِ دست‌دوم)</span>
                <select name="ti_product"><option value="0">— کالای تازه با نامِ پایین —</option>
                <?php foreach ($phones as $p): ?><option value="<?= (int)$p['id'] ?>"><?= h($p['name']) ?></option><?php endforeach; ?></select></label>
            <label class="st-field"><span>نامِ کالای تازه</span><input type="text" name="ti_name" maxlength="120" placeholder="مثلاً آیفون ۱۱ دست‌دوم"></label>
        </div>
        <div class="st-row2">
            <label class="st-field"><span>IMEI</span><input type="text" name="ti_imei" inputmode="numeric" dir="ltr" maxlength="20" required></label>
            <label class="st-field"><span>ارزشِ گوشیِ کهنه (تومان)</span><input type="text" name="ti_value" inputmode="numeric" dir="ltr" required></label>
        </div>
        <p class="st-muted">یک فاکتورِ خرید از همین مشتری ثبت می‌شود (گوشی با IMEI وارد انبار)، و ارزشش از ماندهِ این فاکتور کم می‌شود — بی‌جابه‌جاییِ پولِ نقد.</p>
        <button type="submit" class="st-btn st-btn-ghost">ثبتِ معاوضه</button>
    </form>
</section>
<?php endif; ?>
<?php endif; ?>

<?php if ($inv['status'] === 'issued'): ?>
<section class="st-card st-danger">
    <div class="st-danger-row">
        <?php
        // همان قاعده‌ی `BizInvoices::undo()`: بی‌برگشت؛ و فاکتورِ گذری بی‌پولِ جدا
        // و برگشت هرگز به پیش‌نویس نمی‌رود (پیوندِ ردیف‌ها به فاکتورِ اصلی می‌رفت)
        $canUnissue = isset(BizInvoices::RETURN_OF[(string)$inv['kind']])
            && !$returns && ($inv['party_id'] !== null || !array_filter($pays, fn($p) => (int)$p['origin_invoice'] !== 1));
        if ($canUnissue): ?>
        <form method="post" action="<?= h($self) ?>"<?= $pays ? " onsubmit=\"return confirm('سند برای اصلاح به پیش‌نویس برگردد؟ دریافت/پرداختی که همراهِ همین فاکتور ثبت شده بود باطل می‌شود (هنگامِ صدورِ دوباره ثبتش کنید)؛ دریافت/پرداخت‌های جدا روی حسابِ طرف‌حساب می‌مانند و دوباره به همین فاکتور می‌خورند.');\"" : '' ?>>
            <?= Csrf::field() ?><input type="hidden" name="action" value="unissue">
            <button type="submit" class="st-btn st-btn-ghost">برگشت به پیش‌نویس (برای اصلاح)</button>
        </form>
        <?php endif; ?>
        <form method="post" action="<?= h($self) ?>" onsubmit="return confirm('سند باطل شود؟ اثرش بر موجودی و مانده برمی‌گردد و دریافت/پرداختی که همراهش ثبت شده بود هم باطل می‌شود.');">
            <?= Csrf::field() ?><input type="hidden" name="action" value="void">
            <button type="submit" class="st-btn st-btn-danger">باطل کردن</button>
        </form>
    </div>
    <p class="st-muted">سندِ صادرشده حذف نمی‌شود؛ باطل می‌شود و شماره و ردش می‌ماند.</p>
</section>
<?php endif; ?>
<?= BizDocView::history(BizLog::forDoc($userId, 'invoice', $id)) ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
