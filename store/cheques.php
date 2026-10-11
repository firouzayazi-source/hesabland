<?php
/**
 * چک‌ها — دفترِ چک‌های دریافتی و پرداختیِ فروشگاه: در جریان، سررسید گذشته،
 * وصول‌شده، واگذارشده و برگشتی؛ با «وصول»، «واگذاری به فروشنده» (چکِ خرجی)،
 * «برگشت از واگذاری» و «برگشت خورد».
 *
 * ⛔ چک ردیفِ جدایی نیست؛ همان دریافت/پرداختی است که روشش «چک» است
 *    (`BizCheques`). این صفحه فقط وضعیتش را جلو می‌برد، و هر نوشتن POST +
 *    CSRF و بعد ریدایرکت است — تازه‌سازیِ صفحه وصول را دوباره نمی‌فرستد.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_docview.php';
require_once __DIR__ . '/../includes/biz_print.php';

$userId = Biz::ownerId();
$filter = getParam('f');
$filter = isset(BizCheques::FILTERS[$filter]) ? $filter : '';
$page   = max(1, (int)getParam('page', '1'));
$keep   = ['f' => $filter, 'page' => $page > 1 ? $page : ''];
$self   = Biz::url('cheques.php') . (($q = http_build_query(array_filter($keep, fn($v) => $v !== ''))) !== '' ? '?' . $q : '');
$ready  = BizCheques::ready();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $cid = (int)postParam('cheque_id');
    // ⛔ تاریخِ نامعتبر «امروز» نمی‌شود (`BizDocView::docDate()`)
    $cd = BizDocView::docDate((string)postParam(postParam('action') === 'endorse' ? 'endorse_date' : 'clear_date'));
    if (!$cd['ok']) { redirectWithMessage($self, 'error', $cd['message']); }
    switch (postParam('action')) {
        case 'clear':   $r = BizCheques::clear($userId, $cid, (int)postParam('bank_id'), $cd['date']); break;
        case 'unclear': $r = BizCheques::unclear($userId, $cid); break;
        case 'bounce':  $r = BizCheques::bounce($userId, $cid); break;
        case 'endorse': $r = BizCheques::endorse($userId, $cid, (int)postParam('party_id'), $cd['date']); break;
        case 'unendorse': $r = BizCheques::unendorse($userId, $cid); break;
        default:        $r = ['ok' => false, 'message' => 'درخواست نامعتبر است.'];
    }
    redirectWithMessage($self, $r['ok'] ? 'success' : 'error', $r['message']);
}

$list  = $ready ? BizCheques::list($userId, $filter, $page) : ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'in' => 0, 'out' => 0, 'overdue' => 0];
$banks = $ready ? BizDocView::accounts($userId) : [];
// طرف‌حساب‌ها برای «واگذاری» — فقط اگر در همین صفحه چکِ دریافتیِ در جریانی هست (یک کوئری، بی‌مانده)
$hasIn = (bool)array_filter($list['rows'], fn($r) => $r['kind'] === 'receipt' && $r['cheque_status'] === 'pending');
$payees = $hasIn ? array_values(array_filter(BizParties::all($userId, '', 3000, false)['rows'], fn($p) => (int)$p['is_active'] === 1)) : [];
$today = BizDocView::jDate(date('Y-m-d'));
$url   = fn(array $o): string => Biz::url('cheques.php') . '?' . http_build_query(array_filter(array_merge($keep, $o), fn($v) => $v !== '' && $v !== null));

$pageTitle = 'چک‌ها';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <div>
        <h1 class="st-h1">چک‌ها</h1>
        <p class="st-muted">چکِ دریافتی و پرداختی از «دریافت/پرداخت» با روشِ «چک» ثبت می‌شود و تا وصول در این دفتر می‌ماند.</p>
    </div>
    <div class="st-head-actions">
        <a class="st-btn" href="<?= h(Biz::url('payment.php?k=receipt')) ?>">+ چکِ دریافتی</a>
        <a class="st-btn st-btn-ghost" href="<?= h(Biz::url('payment.php?k=payment')) ?>">+ چکِ پرداختی</a>
        <?php if ($ready): ?><a class="st-btn st-btn-ghost" href="<?= h(BizPrint::url('cheques', ['k' => $filter])) ?>">چاپ</a><?php endif; ?>
    </div>
</div>

<?php if (!$ready): ?>
    <div class="st-card"><p class="st-flash st-flash-warn">دفترِ چک هنوز روی این نصب راه نیفتاده است (مهاجرتِ پایگاه‌داده اجرا نشده).</p></div>
<?php else: ?>
<div class="st-kpis st-kpis-3">
    <a class="st-kpi-card" href="<?= h($url(['f' => 'in', 'page' => ''])) ?>"><span class="st-kpi-label">دریافتیِ در جریان</span><span class="st-kpi-value"><?= BizDocView::money($list['in']) ?></span><span class="st-kpi-sub">تا وصول، در جمعِ نقد نیست</span></a>
    <a class="st-kpi-card" href="<?= h($url(['f' => 'out', 'page' => ''])) ?>"><span class="st-kpi-label">پرداختیِ در جریان</span><span class="st-kpi-value"><?= BizDocView::money($list['out']) ?></span></a>
    <a class="st-kpi-card<?= $list['overdue'] > 0 ? ' is-warn' : '' ?>" href="<?= h($url(['f' => 'overdue', 'page' => ''])) ?>"><span class="st-kpi-label">سررسید گذشته</span><span class="st-kpi-value st-num"><?= toPersianDigits((string)$list['overdue']) ?></span></a>
</div>

<nav class="st-tabs" aria-label="وضعیت">
    <?php foreach (BizCheques::FILTERS as $fk => $fl): ?>
        <a href="<?= h($url(['f' => $fk, 'page' => ''])) ?>" class="st-tab-chip<?= $fk === $filter ? ' is-active' : '' ?>"><?= h($fl) ?></a>
    <?php endforeach; ?>
</nav>

<?php if (!$list['rows']): ?>
    <div class="st-card st-empty-card"><p class="st-empty"><?= $filter === '' ? 'چکِ در جریانی نیست.' : 'چکی با این وضعیت نیست.' ?></p></div>
<?php else: ?>
<div class="st-cheques">
    <?php foreach ($list['rows'] as $r):
        $in   = $r['kind'] === 'receipt';
        $st   = (string)$r['cheque_status'];
        $late = $st === 'pending' && (string)$r['cheque_due'] < date('Y-m-d'); ?>
    <article class="st-card st-cheque<?= $late ? ' is-late' : '' ?><?= $st !== 'pending' ? ' is-' . h($st) : '' ?>">
        <div class="st-cheque-head">
            <div>
                <span class="st-pill is-k-<?= $in ? 'receipt' : 'payment' ?>"><?= $in ? 'دریافتی' : 'پرداختی' ?></span>
                <a class="st-cheque-title" href="<?= h(Biz::url('payment.php?id=' . (int)$r['id'])) ?>"><?= h(BizCheques::label($r)) ?></a>
                <span class="st-muted-i"><?= $r['party_name'] !== null ? ($in ? 'از ' : 'به ') . h((string)$r['party_name']) : '' ?></span>
            </div>
            <span class="st-cheque-amount"><?= BizDocView::money((int)$r['amount']) ?> <small>تومان</small></span>
        </div>
        <p class="st-cheque-meta">
            سررسید <span class="st-num"><?= h(toJalali((string)$r['cheque_due'])) ?></span>
            <?php if ($late): ?> · <b class="st-late">گذشته</b><?php endif; ?>
            · <?= h(BizCheques::STATUSES[$st] ?? '') ?>
            <?php if ($st === 'cleared' && $r['settle_date'] !== null): ?> در <?= h((string)$r['settle_account']) ?> · <span class="st-num"><?= h(toJalali((string)$r['settle_date'])) ?></span><?php endif; ?>
            <?php if ($st === 'endorsed' && $r['endorse_date'] !== null): ?> به <a href="<?= h(Biz::url('party.php?id=' . (int)$r['endorse_party_id'])) ?>"><?= h((string)$r['endorse_party']) ?></a> · <span class="st-num"><?= h(toJalali((string)$r['endorse_date'])) ?></span><?php endif; ?>
        </p>
        <?php if ($st === 'pending'): ?>
        <div class="st-cheque-actions">
            <form method="post" action="<?= h($self) ?>" class="st-filters st-cheque-clear">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="clear">
                <input type="hidden" name="cheque_id" value="<?= (int)$r['id'] ?>">
                <select name="bank_id" aria-label="<?= $in ? 'واریز به' : 'برداشت از' ?>">
                    <?php foreach ($banks as $b): ?><option value="<?= (int)$b['id'] ?>"<?= $b['kind'] === 'bank' ? ' selected' : '' ?>><?= h($b['name']) ?></option><?php endforeach; ?>
                </select>
                <input type="text" name="clear_date" value="<?= h($today) ?>" dir="ltr" class="st-date-in" aria-label="تاریخِ وصول">
                <button type="submit" class="st-btn st-btn-sm"><?= $in ? 'وصول شد' : 'پاس شد' ?></button>
            </form>
            <?php if ($in && $payees): ?>
            <form method="post" action="<?= h($self) ?>" class="st-filters st-cheque-clear">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="endorse">
                <input type="hidden" name="cheque_id" value="<?= (int)$r['id'] ?>">
                <select name="party_id" aria-label="واگذاری به">
                    <option value="">واگذاری به…</option>
                    <?php foreach ($payees as $pp): if ((int)$pp['id'] === (int)$r['party_id']) { continue; } ?><option value="<?= (int)$pp['id'] ?>"><?= h((string)$pp['name']) ?></option><?php endforeach; ?>
                </select>
                <input type="text" name="endorse_date" value="<?= h($today) ?>" dir="ltr" class="st-date-in" aria-label="تاریخِ واگذاری">
                <button type="submit" class="st-btn st-btn-sm st-btn-ghost">خرج کردن (واگذاری)</button>
            </form>
            <?php endif; ?>
            <form method="post" action="<?= h($self) ?>" onsubmit="return confirm('این چک برگشت خورد؟ سندش باطل و مبلغ دوباره به حسابِ طرف‌حساب برمی‌گردد.');">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="bounce">
                <input type="hidden" name="cheque_id" value="<?= (int)$r['id'] ?>">
                <button type="submit" class="st-link-btn st-link-danger">برگشت خورد</button>
            </form>
        </div>
        <?php elseif ($st === 'endorsed'): ?>
        <form method="post" action="<?= h($self) ?>" onsubmit="return confirm('فروشنده چک را پس داد (یا نزدِ او برگشت خورد)؟ پرداختِ واگذاری باطل، بدهیِ شما به او برمی‌گردد و چک دوباره در جریان می‌شود.');">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="unendorse">
            <input type="hidden" name="cheque_id" value="<?= (int)$r['id'] ?>">
            <button type="submit" class="st-link-btn">برگشت از واگذاری</button>
        </form>
        <?php elseif ($st === 'cleared'): ?>
        <form method="post" action="<?= h($self) ?>" onsubmit="return confirm('وصول برگردد و چک دوباره در جریان شود؟');">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="unclear">
            <input type="hidden" name="cheque_id" value="<?= (int)$r['id'] ?>">
            <button type="submit" class="st-link-btn">برگرداندنِ وصول</button>
        </form>
        <?php endif; ?>
    </article>
    <?php endforeach; ?>
</div>
<?= BizView::pager('cheques.php', array_merge($keep, ['page' => '']), $list['page'], $list['pages'], $list['total']) ?>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
