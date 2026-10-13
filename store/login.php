<?php
/**
 * ⛔ ورودِ محیطِ فروشگاهی — پنلِ خودِ فروشگاه، بدونِ نام و نشانِ حساب لند.
 *
 * پشتِ صحنه همان موتورِ ورود است (`Auth::attemptLogin()`): سدِ حدسِ رمز،
 * ممیزی و «این دستگاه را به خاطر بسپار» همه خودبه‌خود کار می‌کنند و
 * هیچ‌کدام دو بار نوشته نشده‌اند.
 *
 * مقصدِ بعد از ورود را نوعِ حساب تعیین می‌کند، نه این صفحه: حسابی که
 * فروشگاه دارد (`both` یا `business`) ← داشبوردِ فروشگاه، حسابِ شخصی ←
 * خانه‌ی خودش. پس کسی که از درِ اشتباه آمده به بن‌بست نمی‌رسد.
 * ⛔ نوع از دیتابیس تازه می‌شود (`refreshType()`)، نه از نشست: کاربرِ
 *    واردشده‌ی حساب لند که مدیر همین حالا برایش فروشگاه روشن کرده، با
 *    باز کردنِ `/store` نباید به خانه‌ی شخصی برگردانده شود.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();

$home = static function (): string {
    return in_array(Biz::refreshType(), Biz::STORE_TYPES, true) ? Biz::url() : Biz::personalUrl();
};

if (Auth::isLoggedIn()) {
    header('Location: ' . $home());
    exit;
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // عمداً verifyOrFail نیست — همان دلیلِ login.php: صفحه‌ی ورود ساعت‌ها
    // باز می‌ماند و توکنِ کهنه نباید به صفحه‌ی سفید برسد.
    if (!Csrf::validate(postParam('csrf_token'))) {
        $error = 'نشست شما منقضی شده بود. لطفاً دوباره تلاش کنید.';
    } else {
        $username = postParam('username');
        $result   = Auth::attemptLogin($username, $_POST['password'] ?? '');
        if ($result['success']) {
            // هر ورود این دستگاه را به خاطر می‌سپارد (قاعده ۶۰).
            Auth::trustThisDevice((int)Auth::userId());
            header('Location: ' . $home());
            exit;
        }
        $error = $result['message'];
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>ورود به پنل فروشگاه</title>
    <?php foreach (assetUrls(['css/store.css']) as $__u): ?>
    <link rel="stylesheet" href="<?= h($__u) ?>">
    <?php endforeach; ?>
    <meta name="theme-color" content="#1c1917">
    <meta name="robots" content="noindex">
    <?= Biz::webAppTags('فروشگاه') ?>
</head>
<body class="st-body st-auth">
<?= envBanner() ?>
    <main class="st-auth-box">
        <div class="st-auth-mark is-logo" aria-hidden="true"><img src="<?= h(Biz::icon('180')) ?>" alt="" width="64" height="64"></div>
        <h1 class="st-h1">ورود به پنل فروشگاه</h1>

        <?php if ($error !== ''): ?>
        <div class="st-flash st-flash-err" role="alert"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="post" class="st-form auth-form" action="<?= h(Biz::url('login.php')) ?>">
            <?= Csrf::field() ?>
            <label class="st-field">
                <span>نام کاربری یا ایمیل</span>
                <input type="text" name="username" required dir="ltr" autocomplete="username"
                       value="<?= h($username) ?>" autofocus>
            </label>
            <label class="st-field">
                <span>رمز عبور</span>
                <input type="password" name="password" required dir="ltr" autocomplete="current-password">
            </label>
            <button type="submit" class="st-btn st-btn-block">ورود</button>
        </form>
        <p class="st-muted st-auth-note">رمز را فراموش کرده‌اید؟ با مدیرِ سامانه تماس بگیرید.</p>
    </main>
    <?php require __DIR__ . '/../includes/auth_form_js.php'; ?>
</body>
</html>
