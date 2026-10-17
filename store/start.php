<?php
/**
 * «شروعِ کار» — راه‌اندازیِ قدم‌به‌قدمِ فروشگاهِ تازه (`BizSetup`).
 *
 * ⛔ هیچ منطقی این‌جا نیست: پیشرفت از `BizSetup::status()`، سربرگ با
 *    `BizSetup::saveInfo()` (همان `Biz::saveSettings()`)، صندوق با `BizCash::save()`.
 *    کالا و طرف‌حساب به صفحه‌ی خودشان یا «ورود از فایل» می‌روند — فرمِ دومی ساخته نمی‌شود.
 * ⛔ فقط صاحبِ فروشگاه (دسترسیِ «تنظیمات»، `Biz::PAGE_CAPS`).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_setup.php';

$userId = Biz::ownerId();
$self   = Biz::url('start.php');
if (!BizSetup::ready()) { Biz::notFound(); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $act = (string)postParam('action');
    if ($act === 'info') {
        $r = BizSetup::saveInfo($userId, $_POST);
        redirectWithMessage($self . ($r['ok'] ? '#accounts' : '#info'), $r['ok'] ? 'success' : 'error', $r['message']);
    }
    if ($act === 'account') {
        $r = BizCash::save($userId, $_POST);
        redirectWithMessage($self . '#accounts', $r['ok'] ? 'success' : 'error', $r['ok'] ? 'حساب اضافه شد.' : $r['message']);
    }
    if ($act === 'hide' || $act === 'show') {
        BizSetup::setHidden($userId, $act === 'hide');
        redirectWithMessage($act === 'hide' ? Biz::url() : $self, 'success',
            $act === 'hide' ? 'کارتِ «شروعِ کار» از داشبورد برداشته شد. از «تنظیمات» دوباره پیدایش می‌کنید.' : 'کارتِ «شروعِ کار» دوباره روی داشبورد است.');
    }
    redirectWithMessage($self, 'error', 'درخواست نامعتبر است.');
}

$status = BizSetup::status($userId);
$next   = BizSetup::next($status);
$done   = BizSetup::doneCount($status);
$total  = count(BizSetup::STEPS);
$info   = Biz::settings($userId);
$accs   = array_values(array_filter(BizCash::list($userId), fn($a) => in_array((string)$a['kind'], BizCash::USER_KINDS, true)));
$hidden = BizSetup::hidden($userId);

$pageTitle = 'شروعِ کار';
require __DIR__ . '/../includes/biz_head.php';
?>
<h1 class="st-h1">شروعِ کار</h1>
<p class="st-muted">پنج قدم تا فروشگاهی که کامل کار می‌کند. هر قدم را از همین‌جا یا از صفحه‌ی خودش انجام دهید — پیشرفت خودکار از روی اطلاعاتِ فروشگاه خوانده می‌شود.</p>

<div class="st-setup-progress" role="progressbar" aria-valuemin="0" aria-valuemax="<?= $total ?>" aria-valuenow="<?= $done ?>">
    <span class="st-setup-bar" style="--p: <?= (int)round($done * 100 / $total) ?>%"></span>
    <b><?= toPersianDigits((string)$done) ?> از <?= toPersianDigits((string)$total) ?> قدم</b>
</div>

<?php if ($next === null): ?>
<div class="st-flash st-flash-ok">همه‌ی قدم‌ها انجام شده — فروشگاه آماده است. 🎉</div>
<?php endif; ?>

<ol class="st-setup">
<?php $i = 0; foreach (BizSetup::STEPS as $key => [$label, $hint, $page]): $i++; $ok = !empty($status[$key]); ?>
    <li class="st-setup-step<?= $ok ? ' is-done' : '' ?><?= $key === $next ? ' is-next' : '' ?>" id="<?= h($key) ?>">
        <div class="st-setup-head">
            <span class="st-setup-num" aria-hidden="true"><?= $ok ? '✓' : toPersianDigits((string)$i) ?></span>
            <div>
                <b><?= h($label) ?></b><?= $ok ? ' <span class="st-badge is-ok">انجام شد</span>' : '' ?>
                <p class="st-muted"><?= h($hint) ?></p>
            </div>
        </div>

        <?php if ($key === 'info'): ?>
        <form method="post" class="st-form" action="<?= h($self) ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="info">
            <div class="st-row3">
                <label class="st-field"><span>نامِ فروشگاه</span>
                    <input type="text" name="shop_name" required maxlength="<?= Biz::SETTING_LIMITS['shop_name'] ?>" value="<?= h($info['shop_name']) ?>"></label>
                <label class="st-field"><span>تلفن</span>
                    <input type="tel" name="phone" required maxlength="<?= Biz::SETTING_LIMITS['phone'] ?>" dir="ltr" value="<?= h($info['phone']) ?>"></label>
                <label class="st-field"><span>نشانی</span>
                    <input type="text" name="address" maxlength="<?= Biz::SETTING_LIMITS['address'] ?>" value="<?= h($info['address']) ?>"></label>
            </div>
            <div class="st-danger-row">
                <button type="submit" class="st-btn"><?= $ok ? 'ذخیره‌ی تغییرات' : 'ذخیره و قدمِ بعد' ?></button>
                <a class="st-btn st-btn-ghost" href="<?= h(Biz::url($page)) ?>">لوگو، کدِ اقتصادی و بقیه‌ی سربرگ</a>
            </div>
        </form>

        <?php elseif ($key === 'accounts'): ?>
        <?php if ($accs): ?>
        <ul class="st-list">
            <?php foreach ($accs as $a): ?>
            <li class="st-list-row"><span><?= h((string)$a['name']) ?> <span class="st-muted-i"><?= h(BizCash::KINDS[(string)$a['kind']] ?? '') ?></span></span>
                <span class="ltr-num"><?= h(formatMoney((int)$a['balance'])) ?></span></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <form method="post" class="st-form" action="<?= h($self) ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="account">
            <div class="st-row3">
                <label class="st-field"><span>نامِ حساب</span>
                    <input type="text" name="name" required maxlength="<?= BizCash::NAME_MAX ?>" placeholder="مثلاً کارت‌خوانِ ملت"></label>
                <label class="st-field"><span>نوع</span>
                    <select name="kind">
                        <?php foreach (BizCash::USER_KINDS as $k): ?>
                        <option value="<?= h($k) ?>"<?= $k === 'pos' ? ' selected' : '' ?>><?= h(BizCash::KINDS[$k]) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                <label class="st-field"><span>موجودیِ امروز (تومان)</span>
                    <input data-money type="text" name="opening_balance" inputmode="numeric" dir="ltr"></label>
            </div>
            <div class="st-danger-row">
                <button type="submit" class="st-btn">افزودنِ حساب</button>
                <a class="st-btn st-btn-ghost" href="<?= h(Biz::url($page)) ?>">صندوق و بانک</a>
            </div>
        </form>

        <?php elseif ($key === 'products'): ?>
        <div class="st-danger-row">
            <a class="st-btn<?= $ok ? ' st-btn-ghost' : '' ?>" href="<?= h(Biz::url('product.php')) ?>">افزودنِ کالا</a>
            <a class="st-btn st-btn-ghost" href="<?= h(Biz::url('import.php')) ?>">ورود از اکسل یا نرم‌افزارِ دیگر</a>
        </div>

        <?php elseif ($key === 'parties'): ?>
        <div class="st-danger-row">
            <a class="st-btn<?= $ok ? ' st-btn-ghost' : '' ?>" href="<?= h(Biz::url('party.php')) ?>">افزودنِ مشتری یا تأمین‌کننده</a>
            <a class="st-btn st-btn-ghost" href="<?= h(Biz::url('import.php')) ?>">ورود از فایل</a>
        </div>

        <?php else: ?>
        <div class="st-danger-row">
            <a class="st-btn<?= $ok ? ' st-btn-ghost' : '' ?>" href="<?= h(Biz::url($page)) ?>">فروشِ سریع</a>
        </div>
        <?php endif; ?>
    </li>
<?php endforeach; ?>
</ol>

<form method="post" action="<?= h($self) ?>" class="st-inline-form">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="<?= $hidden ? 'show' : 'hide' ?>">
    <button type="submit" class="st-btn st-btn-ghost"><?= $hidden ? 'کارتِ «شروعِ کار» را دوباره روی داشبورد نشان بده' : 'دیگر روی داشبورد نشان نده' ?></button>
</form>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
