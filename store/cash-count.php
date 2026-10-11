<?php
/**
 * شمارشِ صندوق — «پولِ واقعیِ صندوق این است». تفاوت با دفتر یک سندِ
 * هزینه/درآمد زیرِ سرفصلِ سیستمیِ «کسری/اضافه‌ی صندوق» می‌شود
 * (`BizCashCount::record()`)، و خودِ شمارش در تاریخچه می‌ماند.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_docview.php';
require_once __DIR__ . '/../includes/biz_acc.php';

$userId   = Biz::ownerId();
$self     = Biz::url('cash-count.php');
$accounts = BizDocView::accounts($userId);
$form     = ['account_id' => (int)getParam('acc', (string)($accounts[0]['id'] ?? 0)), 'counted' => '', 'date' => BizDocView::jDate(date('Y-m-d')), 'note' => ''];
$error    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    foreach ($form as $k => $_) { $form[$k] = postParam($k); }
    $dd = BizDocView::docDate((string)$form['date'], 'تاریخِ شمارش');
    if (!$dd['ok']) {
        $error = $dd['message'];
    } else {
        if (($dup = BizOnce::claim()) !== null) { BizOnce::redirectDuplicate($dup, $self); }
        $r = BizCashCount::record($userId, (int)$form['account_id'], (string)$form['counted'], $dd['date'], (string)$form['note']);
        if ($r['ok']) {
            BizOnce::done($self);
            redirectWithMessage($self, 'success', $r['message']);
        }
        BizOnce::release();
        $error = $r['message'];
    }
}
$history = BizCashCount::history($userId);

Biz::$navActive = 'accounts.php';
$pageTitle = 'شمارشِ صندوق';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <div>
        <a class="st-back" href="<?= h(Biz::url('accounts.php')) ?>">‹ صندوق و بانک</a>
        <h1 class="st-h1">شمارشِ صندوق</h1>
        <p class="st-muted">پولِ صندوق را بشمارید و عدد را بنویسید؛ اگر با دفتر نخواند، تفاوت به‌عنوانِ «کسری» یا «اضافه‌ی صندوق» ثبت می‌شود تا موجودیِ دفتر همان پولِ واقعی باشد.</p>
    </div>
</div>
<?php if ($error !== ''): ?><div class="st-flash st-flash-err" role="alert"><?= h($error) ?></div><?php endif; ?>
<?php if (!Biz::accReady()): ?>
<div class="st-flash st-flash-warn" role="status">لایه‌ی حسابداری هنوز راه نیفتاده است.</div>
<?php else: ?>
<form method="post" action="<?= h($self) ?>" class="st-card st-form st-form-narrow">
    <?= Csrf::field() ?><?= BizOnce::field() ?>
    <label class="st-field"><span>صندوق</span>
        <select name="account_id"><?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id'] ?>"<?= (int)$a['id'] === (int)$form['account_id'] ? ' selected' : '' ?>><?= h($a['name']) ?> — دفتر: <?= h(formatMoney((int)$a['balance'])) ?></option><?php endforeach; ?></select>
    </label>
    <div class="st-row2">
        <label class="st-field"><span>پولِ شمرده‌شده (تومان)</span><input type="text" name="counted" required inputmode="numeric" dir="ltr" value="<?= h((string)$form['counted']) ?>" autofocus></label>
        <label class="st-field"><span>تاریخ</span><input type="text" name="date" dir="ltr" inputmode="numeric" value="<?= h((string)$form['date']) ?>"></label>
    </div>
    <label class="st-field"><span>توضیح <small class="st-muted">(اختیاری)</small></span><input type="text" name="note" maxlength="300" value="<?= h((string)$form['note']) ?>"></label>
    <button type="submit" class="st-btn">ثبتِ شمارش</button>
</form>
<?php if ($history): ?>
<h2 class="st-h2 st-section-title">شمارش‌های اخیر</h2>
<div class="st-table-wrap">
    <table class="st-table st-table-compact">
        <thead><tr><th>تاریخ</th><th>صندوق</th><th class="st-td-num st-hide-sm">دفتر</th><th class="st-td-num">شمرده‌شده</th><th class="st-td-num">تفاوت</th></tr></thead>
        <tbody>
        <?php foreach ($history as $c): $d = (int)$c['counted'] - (int)$c['system_balance']; ?>
            <tr><td class="st-num"><?= h(toJalali((string)$c['count_date'])) ?></td><td><?= h((string)$c['account_name']) ?></td>
                <td class="st-td-num st-hide-sm"><?= BizDocView::money((int)$c['system_balance']) ?></td><td class="st-td-num"><?= BizDocView::money((int)$c['counted']) ?></td>
                <td class="st-td-num"><?= $c['payment_id'] !== null ? '<a href="' . h(Biz::url('payment.php?id=' . (int)$c['payment_id'])) . '">' . BizDocView::money($d) . '</a>' : 'برابر' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
