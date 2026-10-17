<?php
/**
 * اشتراکِ فروشگاه — وضعیت، تمدید با درگاه یا کارت‌به‌کارت، و برگشت از درگاه (`StorePlan`).
 *
 * ⛔ برگشت از درگاه در `pay-return.php` است (بی‌ورود، چون نشست در درگاه ممکن است منقضی شود).
 * ⛔ همکار فقط وضعیت را می‌بیند؛ پرداخت کارِ صاحب است.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/store_plan.php';

Auth::initSession();
Biz::requirePage();
$self = Biz::url('subscription.php');
if (!StorePlan::ready()) { Biz::notFound(); }

$payer   = StorePlan::payerFor(Biz::ownerId());
$isOwner = !Biz::isStaff();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    if (!$isOwner) { redirectWithMessage($self, 'error', 'پرداخت کارِ صاحبِ فروشگاه است.'); }
    $months = (int)postParam('months');
    if (postParam('action') === 'pay') {
        $u = parse_url(defined('APP_URL') ? (string)APP_URL : '');
        $origin = isset($u['scheme'], $u['host']) ? $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '') : '';
        $r = StorePlan::startGateway($payer, $months, $origin . Biz::url('pay-return.php') . '?r=1');
        if ($r['ok']) { header('Location: ' . $r['redirect']); exit; }
        redirectWithMessage($self, 'error', $r['message']);
    }
    if (postParam('action') === 'card') {
        $r = StorePlan::submitCard($payer, $months, (string)postParam('ref'));
        redirectWithMessage($self, $r['ok'] ? 'success' : 'error', $r['message']);
    }
    redirectWithMessage($self, 'error', 'درخواست نامعتبر است.');
}

$st      = StorePlan::status($payer, StorePlan::enforced());
$price   = StorePlan::price();
$gateway = PayGateway::configured();
$card    = getSetting('plan_card_number', '');
$cardOwn = getSetting('plan_card_owner', '');
$history = $isOwner ? StorePlan::payments($payer, 20) : [];

$pageTitle = 'اشتراکِ فروشگاه';
require __DIR__ . '/../includes/biz_head.php';
?>
<h1 class="st-h1">اشتراکِ فروشگاه</h1>

<section class="st-card">
    <?php if (!StorePlan::enforced()): ?>
        <p><b>همه‌ی امکانات باز است.</b> <span class="st-muted">اشتراک هنوز اجرا نمی‌شود؛ پرداخت فقط اعتبارِ شما را جلو می‌برد.</span></p>
    <?php endif; ?>
    <?php if ($st['forever']): ?>
        <p class="st-plan-state is-ok">اشتراکِ مادام‌العمر</p>
    <?php elseif ($st['until'] === null): ?>
        <p class="st-plan-state">هنوز اشتراکی ثبت نشده است<?= StorePlan::enforced() ? '' : ' — دوره‌ی آزمایشی با روشن شدنِ اشتراک شروع می‌شود' ?>.</p>
    <?php elseif ($st['active']): ?>
        <p class="st-plan-state is-ok"><?= $st['trial'] ? 'دوره‌ی آزمایشی' : 'اشتراکِ فعال' ?> تا <b><?= h(toJalali((string)$st['until'])) ?></b>
            — <?= toPersianDigits((string)$st['days_left']) ?> روز مانده</p>
    <?php else: ?>
        <p class="st-plan-state is-err">اشتراک از <b><?= h(toJalali((string)$st['until'])) ?></b> تمام شده — همه‌چیز دیده می‌شود ولی ثبتِ تازه نه. رمز و بکاپ همیشه باز است.</p>
    <?php endif; ?>
</section>

<?php if (!$isOwner): ?>
<p class="st-muted">تمدیدِ اشتراک کارِ صاحبِ فروشگاه است.</p>
<?php elseif ($price <= 0): ?>
<p class="st-muted">قیمتِ اشتراک هنوز تعیین نشده است؛ برای تمدید با پشتیبانی تماس بگیرید.</p>
<?php else: ?>
<h2 class="st-h2 st-section-title">تمدید</h2>
<form method="post" class="st-card st-form" action="<?= h($self) ?>">
    <?= Csrf::field() ?>
    <fieldset class="st-plan-periods">
        <legend class="st-muted">دوره</legend>
        <?php $first = true; foreach (StorePlan::PERIODS as $m => $_): ?>
        <label class="st-check"><input type="radio" name="months" value="<?= $m ?>"<?= $first ? ' checked' : '' ?>>
            <span><?= h(StorePlan::PERIOD_LABELS[$m]) ?> — <b class="ltr-num"><?= h(formatMoney(StorePlan::periodPrice($m))) ?></b> تومان</span></label>
        <?php $first = false; endforeach; ?>
    </fieldset>
    <?php if ($gateway): ?>
        <button type="submit" name="action" value="pay" class="st-btn">پرداختِ آنلاین با <?= h(PayGateway::DRIVERS[PayGateway::driver()]['label']) ?></button>
    <?php endif; ?>
    <?php if ($card !== ''): ?>
        <div class="st-plan-card">
            <p><?= $gateway ? 'یا کارت‌به‌کارت' : 'کارت‌به‌کارت' ?> به <b class="ltr-num" dir="ltr"><?= h(trim(chunk_split($card, 4, ' '))) ?></b><?= $cardOwn !== '' ? ' (' . h($cardOwn) . ')' : '' ?>
                و شماره‌ی پیگیری را بنویسید؛ پس از تأییدِ مدیر تمدید می‌شود.</p>
            <label class="st-field"><span>شماره‌ی پیگیری یا چهار رقمِ آخرِ کارت</span>
                <input type="text" name="ref" maxlength="64" inputmode="numeric" dir="ltr" autocomplete="off"></label>
            <button type="submit" name="action" value="card" class="st-btn st-btn-ghost">ثبتِ پرداختِ کارت‌به‌کارت</button>
        </div>
    <?php elseif (!$gateway): ?>
        <p class="st-muted">هنوز راهِ پرداختی تنظیم نشده است؛ با پشتیبانی تماس بگیرید.</p>
    <?php endif; ?>
</form>
<?php endif; ?>

<?php if ($history): ?>
<h2 class="st-h2 st-section-title">پرداخت‌ها</h2>
<div class="st-table-wrap"><table class="st-table">
    <thead><tr><th>تاریخ</th><th>دوره</th><th>روش</th><th class="st-td-num">مبلغ</th><th>وضعیت</th><th>پیگیری</th></tr></thead>
    <tbody>
    <?php foreach ($history as $p): ?>
        <tr><td><?= h(toJalali(substr((string)$p['created_at'], 0, 10))) ?></td>
            <td><?= h(StorePlan::GRANTS[(int)$p['months']] ?? toPersianDigits((string)$p['months']) . ' ماه') ?></td>
            <td><?= h(StorePlan::METHODS[(string)$p['method']] ?? '') ?></td>
            <td class="st-td-num"><?= h(formatMoney((int)$p['amount'])) ?></td>
            <td><span class="st-badge <?= ['paid' => 'is-ok', 'pending' => 'is-warn', 'failed' => 'is-error', 'rejected' => 'is-error'][(string)$p['status']] ?? '' ?>"><?= h(StorePlan::STATUSES[(string)$p['status']] ?? '') ?></span></td>
            <td class="ltr-num" dir="ltr"><?= h((string)($p['ref_id'] ?? '')) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
