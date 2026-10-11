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
