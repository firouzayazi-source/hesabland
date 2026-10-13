<?php
/**
 * کالای امانی — گوشی یا کالای همکار که نزدِ ماست ولی **مالِ ما نیست**.
 *
 * منطق فقط `BizConsign`. ⛔ امانی در موجودی و ارزشِ انبار نیست؛ «خرید» و «فروش» هر دو از
 * راهِ فاکتورِ خریدِ واقعی از امانت‌دهنده می‌گذرند (نسیه، به بهای توافقی).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_docview.php';

$userId = Biz::ownerId();
$self   = Biz::url('consignments.php');
$status = getParam('s', 'held');
$status = isset(BizConsign::STATUSES[$status]) || $status === 'all' ? $status : 'held';

if (BizConsign::ready() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $id = (int)postParam('id');
    $d  = BizDocView::docDate((string)postParam('date'), 'تاریخ');
    switch (postParam('action')) {
        case 'receive':
            $r = !$d['ok'] ? $d : BizConsign::receive($userId, [
                'party_id' => (int)postParam('party_id'), 'item' => (string)postParam('item'), 'qty' => (string)postParam('qty'),
                'imeis' => (string)postParam('imeis'), 'unit_cost' => (string)postParam('unit_cost'), 'date' => $d['date'] ?? '',
                'note' => (string)postParam('note'),
            ]);
            break;
        case 'buy':  $r = !$d['ok'] ? $d : BizConsign::buy($userId, $id, $d['date']); break;
        case 'sell':
            $r = !$d['ok'] ? $d : BizConsign::sell($userId, $id, $d['date']);
            if ($r['ok'] && !empty($r['sale_id'])) { redirectWithMessage(Biz::url('invoice-edit.php?id=' . (int)$r['sale_id']), 'success', $r['message']); }
            break;
        case 'return': $r = !$d['ok'] ? $d : BizConsign::giveBack($userId, $id, $d['date']); break;
        case 'delete': $r = BizConsign::remove($userId, $id); break;
        default: $r = ['ok' => false, 'message' => 'درخواست نامعتبر است.'];
    }
    redirectWithMessage($self . ($status !== 'held' ? '?s=' . $status : ''), $r['ok'] ? 'success' : 'error', $r['message']);
}

$list    = BizConsign::list($userId, $status);
$parties = BizConsign::ready() ? BizDocView::parties($userId, 2000, false) : [];
$today   = BizDocView::jDate(date('Y-m-d'));

$pageTitle = 'کالای امانی';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <div>
        <h1 class="st-h1">کالای امانی</h1>
        <p class="st-muted">گوشی یا کالایی که همکار به امانت داده: تا خریده نشده موجودیِ شما نیست و در ارزشِ انبار نمی‌آید. «خرید» یا «فروش» یک فاکتورِ خریدِ واقعی (نسیه) از همان همکار می‌سازد.</p>
    </div>
</div>

<?php if (!BizConsign::ready()): ?>
    <div class="st-flash st-flash-err">این بخش هنوز راه نیفتاده است؛ مدیرِ نصب باید به‌روزرسانی را با migration اجرا کند.</div>
<?php else: ?>

<div class="st-kpis st-kpis-3">
    <div class="st-kpi-card"><span class="st-kpi-label">امانتِ باز</span><span class="st-kpi-value st-num"><?= toPersianDigits((string)$list['held']) ?></span><span class="st-kpi-sub">ردیف</span></div>
    <?php if (Biz::can('cost')): ?>
    <div class="st-kpi-card"><span class="st-kpi-label">ارزشِ امانی (بهای توافقی)</span><span class="st-kpi-value st-num"><?= formatMoney($list['held_value']) ?></span><span class="st-kpi-sub">تومان — بیرون از ارزشِ انبار</span></div>
    <?php endif; ?>
</div>

<form method="post" action="<?= h($self) ?>" class="st-card st-form">
    <?= Csrf::field() ?><input type="hidden" name="action" value="receive">
    <h2 class="st-h2">دریافتِ امانی</h2>
    <div class="st-row3">
        <label class="st-field"><span>امانت‌دهنده</span>
            <select name="party_id" required><option value="">— انتخاب کنید —</option>
                <?php foreach ($parties as $p): ?><option value="<?= (int)$p['id'] ?>"><?= h((string)$p['name']) ?></option><?php endforeach; ?>
            </select></label>
        <label class="st-field"><span>کالا</span><input type="text" name="item" list="bizProducts" required autocomplete="off" placeholder="نام یا کدِ کالا"></label>
        <label class="st-field"><span>بهای توافقیِ هر واحد (تومان)</span><input data-money type="text" name="unit_cost" inputmode="numeric" dir="ltr" required></label>
    </div>
    <div class="st-row3">
        <label class="st-field"><span>تعداد <small class="st-muted">(کالای غیرِ گوشی)</small></span><input data-num type="text" name="qty" inputmode="decimal" dir="ltr" value="1"></label>
        <label class="st-field"><span>IMEIها <small class="st-muted">(گوشی: هر خط یک دستگاه)</small></span><textarea name="imeis" rows="2" dir="ltr" placeholder="356938035643809 / 356938035643817"></textarea></label>
        <label class="st-field"><span>تاریخِ دریافت</span><input data-num type="text" name="date" dir="ltr" inputmode="numeric" value="<?= h($today) ?>"></label>
    </div>
    <label class="st-field"><span>توضیح <small class="st-muted">(اختیاری)</small></span><input type="text" name="note" maxlength="<?= BizConsign::NOTE_MAX ?>"></label>
    <button type="submit" class="st-btn">ثبتِ امانی</button>
    <?= BizDocView::productDatalist($userId) ?>
</form>

<nav class="st-tabs" aria-label="وضعیت">
    <?php foreach (BizConsign::STATUSES + ['all' => 'همه'] as $sk => $sl): ?>
        <a class="st-tab-chip<?= $sk === $status ? ' is-active' : '' ?>" href="<?= h($self . '?s=' . $sk) ?>"><?= h($sl) ?></a>
    <?php endforeach; ?>
</nav>

<?php if (!$list['rows']): ?>
    <div class="st-card st-empty-card"><p class="st-empty">ردیفی در این وضعیت نیست.</p></div>
<?php else: ?>
<div class="st-table-wrap">
    <table class="st-table st-table-compact">
        <thead><tr><th>دریافت</th><th>کالا</th><th>امانت‌دهنده</th><th class="st-th-num">تعداد</th><?php if (Biz::can('cost')): ?><th class="st-th-num">بهای توافقی</th><?php endif; ?><th>وضعیت</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($list['rows'] as $c): $held = $c['status'] === 'held'; ?>
            <tr>
                <td><span class="st-num"><?= h(toJalali((string)$c['received_on'])) ?></span></td>
                <td><?= h((string)$c['product_name']) ?><?php if ($c['imei1'] !== null): ?><br><span class="st-muted-i" dir="ltr" data-latin><?= h((string)$c['imei1']) ?><?= $c['imei2'] !== null ? ' / ' . h((string)$c['imei2']) : '' ?></span><?php endif; ?></td>
                <td><?= h((string)$c['party_name']) ?></td>
                <td class="st-td-num"><span class="st-num"><?= h(formatQty((float)$c['qty'])) ?></span></td>
                <?php if (Biz::can('cost')): ?><td class="st-td-num"><?= BizDocView::money((int)$c['unit_cost']) ?></td><?php endif; ?>
                <td><?= h(BizConsign::STATUSES[$c['status']] ?? $c['status']) ?><?php if ($c['invoice_id'] !== null): ?> · <a class="st-row-link" href="<?= h(Biz::url('invoice.php?id=' . (int)$c['invoice_id'])) ?>">فاکتورِ خرید</a><?php endif; ?></td>
                <td>
                <?php if ($held): ?>
                    <form method="post" action="<?= h($self) ?>" class="st-inline">
                        <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="date" value="<?= h($today) ?>">
                        <?php if (Biz::can('sell') && Biz::can('buy')): ?><button type="submit" name="action" value="sell" class="st-btn st-btn-sm">فروش</button><?php endif; ?>
                        <?php if (Biz::can('buy')): ?><button type="submit" name="action" value="buy" class="st-btn st-btn-ghost st-btn-sm" onclick="return confirm('یک فاکتورِ خریدِ نسیه از امانت‌دهنده صادر شود؟');">خرید</button><?php endif; ?>
                        <button type="submit" name="action" value="return" class="st-link-btn">پس دادن</button>
                        <button type="submit" name="action" value="delete" class="st-link-btn" onclick="return confirm('این ردیفِ امانی حذف شود؟');">حذف</button>
                    </form>
                <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
