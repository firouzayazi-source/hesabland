<?php
/**
 * ⛔ حسابِ کاربریِ فروشگاه — رمز و بکاپ.
 *
 * بازرسیِ مهر ۱۴۰۵: حسابِ «فقط فروشگاه» هیچ راهی برای عوض کردنِ رمز یا گرفتنِ
 * بکاپ نداشت — دروازه فقط `/store` را باز می‌کند و پروفایل و پشتیبان‌گیریِ حساب
 * لند برایش بسته‌اند. فروشگاهی که رمزش لو رفته باید همان لحظه عوضش کند.
 *
 * ⛔ **هیچ منطقِ تازه‌ای اینجا نیست:** رمز از `changeOwnPassword()` و بکاپ از
 *    `sendUserExport()` — همان دو تابعی که حساب لند صدا می‌زند. با نسخه‌ی دوم،
 *    دیر یا زود یکی ابطالِ دستگاه‌ها یا قاعده‌ی رمز را جا می‌انداخت.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';

// ⛔ رمز مالِ **خودِ** واردشده است (همکار رمزِ خودش را عوض می‌کند)، ولی بکاپ
//    همه‌ی دفترِ فروشگاه است و فقط صاحبِ فروشگاه می‌گیردش.
$userId = (int)Auth::userId();
$self   = Biz::url('account.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    if (postParam('action') === 'password') {
        $r = changeOwnPassword($userId, postParam('current_password'), postParam('new_password'), postParam('new_password_confirm'));
        redirectWithMessage($self, $r['ok'] ? 'success' : 'error', $r['message']);
    }
    if (postParam('action') === 'export') {
        if (Biz::isStaff()) { redirectWithMessage($self, 'error', 'بکاپِ فروشگاه را فقط صاحبِ فروشگاه می‌گیرد.'); }
        // موفق: فایل فرستاده شد و همین‌جا خارج شد
        redirectWithMessage($self, 'error', sendUserExport($userId));
    }
    redirectWithMessage($self, 'error', 'درخواست نامعتبر است.');
}

$hasPassword = userHasPassword($userId);
$pageTitle   = 'حساب کاربری';
require __DIR__ . '/../includes/biz_head.php';
?>

<h1 class="st-h1">حساب کاربری</h1>

<h2 class="st-h2 st-section-title" id="password"><?= $hasPassword ? 'تغییرِ رمز' : 'گذاشتنِ رمز' ?></h2>
<form method="post" class="st-card st-form" action="<?= h($self) ?>" autocomplete="off">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="password">
    <?php if ($hasPassword): ?>
    <label class="st-field">
        <span>رمزِ فعلی</span>
        <input type="password" name="current_password" required autocomplete="current-password">
    </label>
    <?php endif; ?>
    <label class="st-field">
        <span>رمزِ تازه</span>
        <input type="password" name="new_password" required autocomplete="new-password">
    </label>
    <label class="st-field">
        <span>تکرارِ رمزِ تازه</span>
        <input type="password" name="new_password_confirm" required autocomplete="new-password">
    </label>
    <p class="st-muted">با تغییرِ رمز، دستگاه‌های دیگری که واردِ این حساب‌اند بیرون می‌افتند؛ این دستگاه وارد می‌ماند.</p>
    <button type="submit" class="st-btn">ذخیره‌ی رمز</button>
</form>

<?php if (Biz::isStaff()): ?>
<p class="st-muted">شما همکارِ این فروشگاه هستید (<?= h(Biz::STAFF_ROLES[Biz::role()]['label']) ?>). بکاپ و تنظیمات دستِ صاحبِ فروشگاه است.</p>
<?php else: ?>
<h2 class="st-h2 st-section-title" id="backup">بکاپ</h2>
<form method="post" class="st-card st-form" action="<?= h($self) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="export">
    <p class="st-muted">یک فایلِ کامل از همه‌ی داده‌ی این حساب — کالا، طرف‌حساب، فاکتور، دریافت و پرداخت، چک — با پسوندِ <span class="ltr-num">.sthesab</span>. آن را جایی بیرون از این گوشی یا رایانه نگه دارید. برای بازگرداندنِ دفترِ فروشگاه از این فایل با پشتیبانی تماس بگیرید — بازگرداندنِ خودکار فقط دفترِ شخصی را برمی‌گرداند، تا قاعده‌های فروشگاه (موجودی، شماره‌ی فاکتور، بستنِ دوره) دور زده نشوند.</p>
    <button type="submit" class="st-btn st-btn-ghost">دانلودِ بکاپ</button>
</form>
<?php endif; ?>

<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
