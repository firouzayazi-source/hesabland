<?php
/**
 * حقوق و مساعده — کارکنان همان طرف‌حساب‌های نوعِ «کارمند»اند.
 *
 * - مساعده = پرداخت به کارمند (مانده‌اش بدهکار می‌شود).
 * - حقوق = هزینه‌ی کامل زیرِ «حقوق و دستمزد» + (اگر کسرِ مساعده هست) دریافت
 *   از همان کارمند — در یک تراکنش (`BizPayroll::paySalary()`).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_docview.php';
require_once __DIR__ . '/../includes/biz_acc.php';

$userId   = Biz::ownerId();
$self     = Biz::url('payroll.php');
$accounts = BizDocView::accounts($userId);
$emps     = BizPayroll::employees($userId);
$form     = ['emp' => (int)getParam('emp', '0'), 'gross' => '', 'deduct' => '', 'amount' => '',
             'account_id' => (int)($accounts[0]['id'] ?? 0), 'date' => BizDocView::jDate(date('Y-m-d')), 'note' => ''];
$error    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    foreach ($form as $k => $_) { $form[$k] = postParam($k); }
    $act = postParam('action');
    $dd  = BizDocView::docDate((string)$form['date'], 'تاریخ');
    if (!$dd['ok']) {
        $error = $dd['message'];
    } else {
        if (($dup = BizOnce::claim()) !== null) { BizOnce::redirectDuplicate($dup, $self); }
        $r = $act === 'advance'
            ? BizPayroll::advance($userId, (int)$form['emp'], (string)$form['amount'], (int)$form['account_id'], $dd['date'], (string)$form['note'])
            : BizPayroll::paySalary($userId, (int)$form['emp'], (string)$form['gross'], (string)$form['deduct'], (int)$form['account_id'], $dd['date'], (string)$form['note']);
        if ($r['ok']) {
            BizOnce::done($self);
            redirectWithMessage($self, 'success', $r['message']);
        }
        BizOnce::release();
        $error = $r['message'];
    }
}
$history = BizPayroll::history($userId);
$empSelect = function () use ($emps, $form): string {
    $o = '<select name="emp" required><option value="">— کارمند —</option>';
    foreach ($emps as $e) {
        $b = (int)$e['balance'];
        $o .= '<option value="' . (int)$e['id'] . '"' . ((int)$e['id'] === (int)$form['emp'] ? ' selected' : '') . '>' . h((string)$e['name'])
            . ($b > 0 ? ' (مساعده‌ی باز ' . h(formatMoney($b)) . ')' : '') . '</option>';
    }
    return $o . '</select>';
};
$accSelect = function () use ($accounts, $form): string {
    $o = '<select name="account_id">';
    foreach ($accounts as $a) { $o .= '<option value="' . (int)$a['id'] . '"' . ((int)$a['id'] === (int)$form['account_id'] ? ' selected' : '') . '>' . h((string)$a['name']) . '</option>'; }
    return $o . '</select>';
};

Biz::$navActive = 'payroll.php';
$pageTitle = 'حقوق و مساعده';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <div>
        <h1 class="st-h1">حقوق و مساعده</h1>
        <p class="st-muted">کارکنان طرف‌حساب‌های نوعِ «کارمند»اند — <a href="<?= h(Biz::url('party.php?kind=employee')) ?>">افزودنِ کارمند</a> · <a href="<?= h(Biz::url('parties.php?f=employee')) ?>">فهرستِ کارکنان</a>.</p>
    </div>
</div>
<?php if ($error !== ''): ?><div class="st-flash st-flash-err" role="alert"><?= h($error) ?></div><?php endif; ?>
<?php if (!Biz::accReady()): ?>
<div class="st-flash st-flash-warn" role="status">لایه‌ی حسابداری هنوز راه نیفتاده است.</div>
<?php elseif (!$emps): ?>
<section class="st-card"><p>هنوز کارمندی ثبت نشده است. یک طرف‌حساب با نوعِ «کارمند» بسازید.</p>
    <a class="st-btn" href="<?= h(Biz::url('party.php?kind=employee')) ?>">افزودنِ کارمند</a></section>
<?php else: ?>
<div class="st-grid-2">
    <form method="post" action="<?= h($self) ?>" class="st-card st-form">
        <?= Csrf::field() ?><?= BizOnce::field() ?><input type="hidden" name="action" value="salary">
        <h2 class="st-h3">پرداختِ حقوق</h2>
        <label class="st-field"><span>کارمند</span><?= $empSelect() ?></label>
        <div class="st-row2">
            <label class="st-field"><span>حقوقِ کامل (تومان)</span><input type="text" name="gross" required inputmode="numeric" dir="ltr" value="<?= h((string)$form['gross']) ?>"></label>
            <label class="st-field"><span>کسرِ مساعده <small class="st-muted">(اختیاری)</small></span><input type="text" name="deduct" inputmode="numeric" dir="ltr" value="<?= h((string)$form['deduct']) ?>"></label>
        </div>
        <div class="st-row2">
            <label class="st-field"><span>از صندوق</span><?= $accSelect() ?></label>
            <label class="st-field"><span>تاریخ</span><input type="text" name="date" dir="ltr" inputmode="numeric" value="<?= h((string)$form['date']) ?>"></label>
        </div>
        <label class="st-field"><span>توضیح <small class="st-muted">(مثلاً حقوقِ مهر)</small></span><input type="text" name="note" maxlength="300" value="<?= h((string)$form['note']) ?>"></label>
        <p class="st-muted">کلِ حقوق هزینه‌ی «حقوق و دستمزد» می‌شود؛ از صندوق فقط «حقوق − کسر» بیرون می‌رود و مساعده به همان اندازه تسویه می‌شود.</p>
        <button type="submit" class="st-btn">ثبتِ حقوق</button>
    </form>
    <form method="post" action="<?= h($self) ?>" class="st-card st-form">
        <?= Csrf::field() ?><?= BizOnce::field() ?><input type="hidden" name="action" value="advance">
        <h2 class="st-h3">پرداختِ مساعده</h2>
        <label class="st-field"><span>کارمند</span><?= $empSelect() ?></label>
        <div class="st-row2">
            <label class="st-field"><span>مبلغ (تومان)</span><input type="text" name="amount" required inputmode="numeric" dir="ltr" value="<?= h((string)$form['amount']) ?>"></label>
            <label class="st-field"><span>از صندوق</span><?= $accSelect() ?></label>
        </div>
        <label class="st-field"><span>تاریخ</span><input type="text" name="date" dir="ltr" inputmode="numeric" value="<?= h((string)$form['date']) ?>"></label>
        <input type="hidden" name="note" value="">
        <p class="st-muted">مساعده هزینه نیست؛ کارمند بدهکار می‌شود تا هنگامِ حقوق کسر شود.</p>
        <button type="submit" class="st-btn st-btn-ghost">ثبتِ مساعده</button>
    </form>
</div>
<?php if ($history): ?>
<h2 class="st-h2 st-section-title">گردشِ اخیر</h2>
<div class="st-table-wrap">
    <table class="st-table st-table-compact">
        <thead><tr><th>تاریخ</th><th>کارمند</th><th>شرح</th><th class="st-td-num">مبلغ</th></tr></thead>
        <tbody>
        <?php foreach ($history as $h): ?>
            <tr><td class="st-num"><?= h(toJalali((string)$h['pay_date'])) ?></td><td><?= h((string)$h['emp']) ?></td>
                <td><a href="<?= h(Biz::url('payment.php?id=' . (int)$h['id'])) ?>"><?= h(BizPay::KINDS[$h['kind']] ?? '') ?></a> <span class="st-muted-i"><?= h((string)$h['title']) ?></span></td>
                <td class="st-td-num"><?= BizDocView::money((int)$h['amount']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
