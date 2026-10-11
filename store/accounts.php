<?php
/**
 * صندوق و بانک — صندوق‌ها و حساب‌های **خودِ فروشگاه** با موجودیِ هر کدام.
 *
 * ⛔ `biz_accounts`، نه حساب‌های حساب لند (`wallets`): پولِ مغازه دفترِ
 *    خودش را دارد و هرگز در `walletBalances()` نمی‌نشیند. موجودی از
 *    `BizCash::balanceSql()` می‌آید (اولیه + دریافت/درآمد − پرداخت/هزینه ±
 *    انتقال)؛ «گردش» همان فهرستِ `payments.php?acc=` است.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_print.php';

$userId   = Biz::ownerId();
$self     = Biz::url('accounts.php');
$editAcc  = (int)getParam('acc', '0');
$form     = ['name' => '', 'kind' => 'cash', 'opening_balance' => ''];
$error    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    if (postParam('action') === 'cash_toggle') {
        $res = BizCash::setActive($userId, (int)postParam('acc_id'), postParam('on') === '1');
        redirectWithMessage($self, $res['ok'] ? 'success' : 'error', $res['message']);
    }
    $res = BizCash::save($userId, $_POST, (int)postParam('acc_id'));
    if ($res['ok']) {
        redirectWithMessage($self, 'success', $res['message']);
    }
    $error   = $res['message'];
    $editAcc = (int)postParam('acc_id');
    $form    = array_merge($form, array_intersect_key($_POST, $form));
}

$accounts = BizCash::list($userId);
if ($editAcc > 0 && $error === '') {
    foreach ($accounts as $a) {
        if ((int)$a['id'] === $editAcc && !BizCash::isCheque($a)) {
            $form = ['name' => $a['name'], 'kind' => $a['kind'], 'opening_balance' => (string)(int)$a['opening_balance']];
        }
    }
}
$total = BizCash::total($accounts);

$pageTitle = 'صندوق و بانک';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <div>
        <h1 class="st-h1">صندوق و بانک</h1>
        <p class="st-muted">جمعِ موجودیِ صندوق‌های فعال: <b class="st-num<?= $total < 0 ? ' is-neg' : '' ?>"><?= formatMoney($total) ?></b> تومان</p>
    </div>
    <div class="st-head-actions">
        <a class="st-btn st-btn-ghost" href="<?= h(Biz::url('payment.php?k=transfer')) ?>">انتقال بینِ صندوق‌ها</a>
        <a class="st-btn st-btn-ghost" href="<?= h(Biz::url('cash-count.php')) ?>">شمارشِ صندوق</a>
    </div>
</div>

<div class="st-acc-grid">
    <?php foreach ($accounts as $a): $on = (int)$a['is_active'] === 1; $b = (int)$a['balance']; $chq = BizCash::isCheque($a); ?>
    <section class="st-card st-acc<?= $on ? '' : ' is-inactive' ?>">
        <div class="st-card-head">
            <h2 class="st-h3"><?= h($a['name']) ?></h2>
            <span class="st-muted-i"><?= h(BizCash::KINDS[$a['kind']] ?? (string)$a['kind']) ?><?= $on ? '' : ' · غیرفعال' ?></span>
        </div>
        <p class="st-acc-bal"><span class="st-num<?= $b < 0 ? ' is-neg' : '' ?>"><?= ($b < 0 ? '−' : '') . formatMoney(abs($b)) ?></span> <small>تومان</small></p>
        <div class="st-move-end">
            <a class="st-link-btn" href="<?= h(Biz::url('payments.php?acc=' . (int)$a['id'])) ?>">گردش</a>
            <a class="st-link-btn" href="<?= h(BizPrint::url('account', ['id' => (int)$a['id'], 'p' => 'month'])) ?>">چاپ</a>
            <?php if ($chq): /* حسابِ نگه‌داریِ چک را برنامه می‌سازد؛ ویرایش و غیرفعال ندارد */ ?>
            <a class="st-link-btn" href="<?= h(Biz::url('cheques.php')) ?>">چک‌ها</a>
            <?php else: ?>
            <a class="st-link-btn" href="<?= h(Biz::url('accounts.php?acc=' . (int)$a['id'])) ?>#edit">ویرایش</a>
            <form method="post" action="<?= h($self) ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="cash_toggle">
                <input type="hidden" name="acc_id" value="<?= (int)$a['id'] ?>">
                <input type="hidden" name="on" value="<?= $on ? '0' : '1' ?>">
                <button type="submit" class="st-link-btn"><?= $on ? 'غیرفعال' : 'فعال' ?></button>
            </form>
            <?php endif; ?>
        </div>
        <?php if ($chq): ?><p class="st-muted st-acc-note">چک‌های در جریان؛ در جمعِ موجودی نیست تا وصول شود.</p><?php endif; ?>
    </section>
    <?php endforeach; ?>
</div>

<section class="st-card" id="edit">
    <?php if ($error !== ''): ?><div class="st-flash st-flash-err" role="alert"><?= h($error) ?></div><?php endif; ?>
    <form method="post" class="st-form" action="<?= h($self) ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="cash_save">
        <input type="hidden" name="acc_id" value="<?= $editAcc ?>">
        <h2 class="st-h2"><?= $editAcc > 0 ? 'ویرایشِ حساب' : 'افزودنِ صندوق یا حساب' ?></h2>
        <p class="st-muted">موجودیِ اولیه همان پولی است که روزِ شروع در این صندوق یا حساب بود؛ بقیه را دریافت و پرداخت‌ها می‌سازند.</p>
        <div class="st-row3">
            <label class="st-field">
                <span>نام</span>
                <input type="text" name="name" required maxlength="<?= BizCash::NAME_MAX ?>" value="<?= h((string)$form['name']) ?>" placeholder="مثلاً کارت‌خوانِ ملت">
            </label>
            <label class="st-field">
                <span>نوع</span>
                <select name="kind">
                    <?php foreach (BizCash::USER_KINDS as $k): $l = BizCash::KINDS[$k]; ?>
                        <option value="<?= h($k) ?>"<?= $k === $form['kind'] ? ' selected' : '' ?>><?= h($l) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="st-field">
                <span>موجودیِ اولیه (تومان)</span>
                <input type="text" name="opening_balance" inputmode="numeric" dir="ltr" value="<?= h((string)$form['opening_balance']) ?>">
            </label>
        </div>
        <div class="st-danger-row">
            <button type="submit" class="st-btn"><?= $editAcc > 0 ? 'ذخیره‌ی تغییرات' : 'افزودن' ?></button>
            <?php if ($editAcc > 0): ?><a class="st-btn st-btn-ghost" href="<?= h($self) ?>">انصراف</a><?php endif; ?>
        </div>
    </form>
</section>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
