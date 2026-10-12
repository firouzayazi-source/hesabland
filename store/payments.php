<?php
/**
 * دریافت و پرداخت (خزانه) — همه‌ی جابه‌جاییِ پولِ فروشگاه: دریافت از
 * مشتری، پرداخت به تأمین‌کننده، هزینه (اجاره، قبض، حقوق)، درآمدِ متفرقه و
 * انتقال بینِ صندوق‌ها. `?acc=` گردشِ یک صندوق است.
 *
 * ⛔ جمعِ «ورود/خروج» بالای جدول از همان کوئریِ فهرست (`BizPay::list`)
 *    می‌آید، نه از یک حسابِ دوم.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_docview.php';

$userId = Biz::ownerId();
$filter = getParam('f');
$filter = isset(BizPay::FILTERS[$filter]) ? $filter : '';
$acc    = (int)getParam('acc', '0');
$q      = mb_substr(trim(getParam('q')), 0, 80);
$from   = BizDocView::gDate(getParam('from'));
$to     = BizDocView::gDate(getParam('to'));
$page   = max(1, (int)getParam('page', '1'));
$list   = BizPay::list($userId, $filter, $acc, $q, $page, $from, $to);
$accounts = BizCash::list($userId);
$accName = '';
foreach ($accounts as $a) { if ((int)$a['id'] === $acc) { $accName = (string)$a['name']; } }
$keep = ['f' => $filter, 'acc' => $acc ?: '', 'q' => $q, 'from' => $from !== '' ? BizDocView::jDate($from) : '', 'to' => $to !== '' ? BizDocView::jDate($to) : ''];
$url  = fn(array $o): string => Biz::url('payments.php') . '?' . http_build_query(array_filter(array_merge($keep, $o), fn($v) => $v !== '' && $v !== null));

$pageTitle = 'دریافت و پرداخت';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <div>
        <h1 class="st-h1"><?= $accName !== '' ? 'گردشِ ' . h($accName) : 'دریافت و پرداخت' ?></h1>
        <?php if ($accName !== ''): ?><a class="st-back" href="<?= h($url(['acc' => ''])) ?>">همه‌ی صندوق‌ها</a><?php endif; ?>
    </div>
    <div class="st-head-actions">
        <a class="st-btn" href="<?= h(Biz::url('payment.php?k=receipt')) ?>">+ دریافت</a>
        <a class="st-btn st-btn-ghost" href="<?= h(Biz::url('payment.php?k=payment')) ?>">+ پرداخت</a>
        <a class="st-btn st-btn-ghost" href="<?= h(Biz::url('payment.php?k=expense')) ?>">+ هزینه</a>
        <a class="st-btn st-btn-ghost" href="<?= h(Biz::url('payment.php?k=transfer')) ?>">انتقال</a>
        <?php if ($accName !== ''): ?><a class="st-btn st-btn-ghost" href="<?= h(BizPrint::url('account', ['id' => $acc] + BizDocView::periodParams($from, $to))) ?>">چاپِ گردش</a><?php endif; ?>
    </div>
</div>

<form class="st-filters" method="get" action="<?= h(Biz::url('payments.php')) ?>" role="search">
    <?php if ($filter !== ''): ?><input type="hidden" name="f" value="<?= h($filter) ?>"><?php endif; ?>
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="طرف‌حساب یا شرح…" aria-label="جست‌وجو">
    <select name="acc" aria-label="صندوق">
        <option value="">همه‌ی صندوق‌ها</option>
        <?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id'] ?>"<?= (int)$a['id'] === $acc ? ' selected' : '' ?>><?= h($a['name']) ?></option><?php endforeach; ?>
    </select>
    <input data-num type="text" name="from" value="<?= h($keep['from']) ?>" placeholder="از تاریخ" dir="ltr" class="st-date-in" aria-label="از تاریخ">
    <input data-num type="text" name="to" value="<?= h($keep['to']) ?>" placeholder="تا تاریخ" dir="ltr" class="st-date-in" aria-label="تا تاریخ">
    <button type="submit" class="st-btn st-btn-ghost">بگرد</button>
</form>

<nav class="st-tabs" aria-label="نوع">
    <?php foreach (BizPay::FILTERS as $fk => $fl): ?>
        <a href="<?= h($url(['f' => $fk, 'page' => ''])) ?>" class="st-tab-chip<?= $fk === $filter ? ' is-active' : '' ?>"><?= h($fl) ?></a>
    <?php endforeach; ?>
</nav>

<?php if (!$list['rows']): ?>
    <div class="st-card st-empty-card"><p class="st-empty"><?= ($q !== '' || $filter !== '' || $acc || $from !== '' || $to !== '') ? 'چیزی با این مشخصات پیدا نشد.' : 'هنوز دریافت یا پرداختی ثبت نشده است.' ?></p></div>
<?php else: ?>
<div class="st-sumrow">
    <span><?= toPersianDigits((string)$list['total']) ?> ردیف</span>
    <span>ورود <?= BizDocView::money($list['in']) ?></span>
    <span>خروج <?= BizDocView::money(-$list['out']) ?></span>
</div>
<div class="st-table-wrap">
    <table class="st-table st-table-compact">
        <thead><tr><th>شماره</th><th>تاریخ</th><th>شرح / طرف‌حساب</th><th>نوع</th><th class="st-hide-sm">صندوق</th><th class="st-th-num">مبلغ</th></tr></thead>
        <tbody>
        <?php foreach ($list['rows'] as $r):
            $sign = $r['kind'] === 'transfer' ? ($acc > 0 && (int)$r['to_account_id'] === $acc ? 1 : ($acc > 0 ? -1 : 0)) : BizPay::cashSign((string)$r['kind']); ?>
            <tr class="<?= $r['status'] === 'void' ? 'is-inactive' : '' ?>">
                <td><a class="st-row-link" href="<?= h(Biz::url('payment.php?id=' . (int)$r['id'])) ?>"><span class="st-num"><?= toPersianDigits((string)$r['number']) ?></span></a></td>
                <td><span class="st-num"><?= h(toJalali((string)$r['pay_date'])) ?></span></td>
                <td><?= h(BizPay::label($r)) ?></td>
                <td><span class="st-pill is-<?= $r['status'] === 'void' ? 'void' : 'k-' . h((string)$r['kind']) ?>"><?= h(BizPay::KINDS[$r['kind']] ?? '') ?></span></td>
                <td class="st-hide-sm"><?= h((string)$r['account_name']) ?></td>
                <td class="st-td-num"><?= $sign === 0 ? BizDocView::money((int)$r['amount']) : BizDocView::money($sign * (int)$r['amount']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?= BizView::pager('payments.php', $keep, $list['page'], $list['pages'], $list['total']) ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
