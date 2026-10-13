<?php
/**
 * صفحه‌ی حریم خصوصی.
 *
 * ⛔ عمداً کوتاه و بی‌تعارف است. متنِ حقوقیِ بلندی که هیچ‌کس نمی‌خواند
 *    اعتماد نمی‌سازد؛ چهار جمله‌ی راست می‌سازد. هر ادعایی که اینجا
 *    نوشته شده باید در کد قابل نشان دادن باشد — اگر روزی یکی از این‌ها
 *    عوض شد، این صفحه هم باید همان روز عوض شود.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/sms.php';
require_once __DIR__ . '/includes/push.php';
require_once __DIR__ . '/includes/user_data.php';
require_once __DIR__ . '/includes/store_share.php';

Auth::initSession();

/**
 * ⛔ اینجا عمداً `Auth::requireLogin()` نیست، و این یک سهو نیست.
 *
 *    سیاستِ حریم خصوصی چیزی است که آدم **پیش از** ساختنِ حساب می‌خواند —
 *    و فروشگاه‌های اندروید (مایکت) هم برای اپی که مجوزِ خواندنِ پیامک
 *    می‌خواهد، همین آدرس را باز می‌کنند. پشتِ صفحه‌ی ورود، بازبینِ
 *    فروشگاه به‌جای سیاست یک فرمِ ورود می‌دید و هیچ‌جا هم نوشته نمی‌شد
 *    چرا — خرابیِ بی‌صدا، این بار بیرون از اپ.
 *
 *    امنیتی هم کم نمی‌شود: تمامِ متنِ این صفحه درباره‌ی **خودِ برنامه**
 *    است، نه درباره‌ی یک کاربرِ مشخص. تنها بندی که به کاربر بند است
 *    (دارایی در فروشگاه) پشتِ `isLoggedIn()` می‌ماند.
 *
 *    قاعده ۳۰ در `test_api_contract.php` برگشتِ همان یک خط را می‌بندد.
 */
$__loggedIn = Auth::isLoggedIn();

$support = getSetting('support_email', '');
$flows   = outboundDataFlows();

if ($__loggedIn):
    $pageTitle = 'حریم خصوصی';
    include __DIR__ . '/includes/header.php';
?>

<a href="<?= APP_BASE_PATH ?>/profile.php" class="page-back js-page-back">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
         stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
    بازگشت
</a>

<?php else: ?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <!-- viewport-fit=cover لازم است وگرنه iOS مقدار env(safe-area-inset-*)
         را صفر می‌دهد و همه‌ی محاسبه‌های حاشیه‌ی امن بی‌اثر می‌مانند. -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <title>حریم خصوصی | <?= h(defined('APP_NAME') ? APP_NAME : 'حساب لند') ?></title>
    <?php foreach (assetUrls(['css/style.css']) as $__u): ?>
    <link rel="stylesheet" href="<?= h($__u) ?>">
    <?php endforeach; ?>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= iconUrl('icon-32.png') ?>">
    <meta name="theme-color" content="#2a3563">
</head>
<body>
<?= envBanner() ?>
<div class="privacy-shell">
    <h1 class="privacy-title"><?= h(defined('APP_NAME') ? APP_NAME : 'حساب لند') ?> — حریم خصوصی</h1>
<?php endif; ?>

<div class="card">
    <h2 class="card-title">داده‌ی شما کجاست</h2>

    <p class="privacy-p">
        دفتر شما — تراکنش‌ها، حساب‌ها، چک‌ها، طلب و بدهی — روی همان سروری
        می‌ماند که این برنامه رویش نصب است. <strong>هیچ آمار استفاده‌ای
        جمع نمی‌شود</strong>، هیچ ابزار تحلیلی و هیچ اسکریپت یا فونتی از
        سرویس بیرونی روی این صفحه‌ها بار نمی‌شود.
    </p>

    <?php if ($flows === []): ?>
        <p class="privacy-p">
            روی این نصب، <strong>هیچ چیزی به سرویس بیرونی فرستاده
            نمی‌شود</strong> — نه تراکنش، نه شماره حساب، نه ایمیل.
        </p>
    <?php else: ?>
        <p class="privacy-p">
            <strong>ولی این‌ها از سرور بیرون می‌روند</strong>، و اینجا
            نوشته می‌شوند چون همین حالا روی این نصب روشن‌اند:
        </p>
        <?php foreach ($flows as $flow): ?>
            <p class="privacy-p"><?= h($flow['text']) ?></p>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($__loggedIn && StoreShare::available() && StoreShare::linkFor(Auth::userId()) !== null): ?>
        <p class="privacy-p">
            <strong>دارایی شما در فروشگاه</strong> از حسابداری همان فروشگاه
            خوانده می‌شود — سرویسی روی همین سرور، با تأییدِ مدیر و فقط
            <strong>خواندنی</strong>. یک نسخه از همان اعداد اینجا کش می‌شود
            تا صفحه با ری‌استارتِ آن سرویس خالی نشود. هیچ چیزی از دفترِ
            شخصیِ شما به آن سمت نمی‌رود.
        </p>
    <?php endif; ?>

    <p class="privacy-p">
        <strong>پیامک بانک</strong> که برای ثبت سریع می‌چسبانید، اصلاً از
        مرورگر شما بیرون نمی‌رود. خواندنش کاملاً روی همان دستگاه انجام
        می‌شود و فقط فیلدهای فرم پر می‌شوند. در اپ اندروید هم همین‌طور
        است: متنِ پیامک نه ذخیره می‌شود و نه به سرور می‌رود، و صندوقِ
        پیامک فقط وقتی خوانده می‌شود که خودتان دکمه‌ی «بررسی پیامک‌های
        قبلی» را بزنید — هرگز خودکار.
    </p>

    <p class="privacy-p">
        <strong>نرخ طلا و ارز</strong> دستی وارد می‌شود. برنامه به هیچ
        سرویس قیمتی وصل نیست، پس نه چیزی از شما بیرون می‌رود و نه
        کارکردش به اینترنت بیرون بند است.
    </p>

    <p class="privacy-p">
        <strong>شماره کارت، شماره حساب و شبا</strong> در فهرست حساب‌ها
        نمایش داده نمی‌شوند و فقط وقتی نمای کارت را باز کنید دیده
        می‌شوند.
        <?php if (Crypto::available()): ?>
            روی این نصب، این سه مورد در دیتابیس <strong>رمزشده</strong>
            ذخیره می‌شوند.
        <?php else: ?>
            روی این نصب رمزنگاری‌شان روشن نیست؛ مدیر می‌تواند روشنش کند.
        <?php endif; ?>
    </p>

    <p class="privacy-p">
        <strong>ایمیل شما</strong> فقط برای ورود، بازیابی رمز، و — اگر
        خودتان روشن نگه دارید — یادآوری سررسیدها استفاده می‌شود.
    </p>

    <?php /* ⛔ این دو بند باید با `includes/log.php` و `includes/audit.php`
             بخوانند. اگر روزی چیزی در آن دو عوض شد، این متن هم همان روز
             عوض می‌شود — قاعده ۴۴ در test_api_contract هر دو را می‌سنجد. */ ?>
    <p class="privacy-p">
        <strong>لاگِ فنی:</strong> برای پیدا کردنِ خطاها، هر درخواست به
        سرور با یک شناسه، زمانِ پاسخ، آدرسِ صفحه و شناسه‌ی عددیِ حساب
        شما ثبت می‌شود و <strong><?= h(toPersianDigits((string)Log::KEEP_DAYS)) ?> روز</strong>
        روی همان سرور می‌ماند. در این لاگ هیچ مبلغ، عنوان، طرفِ حساب،
        رمز، شماره کارت یا ایمیلی نوشته نمی‌شود.
    </p>

    <p class="privacy-p">
        <strong>رویدادهای امنیتی</strong> — ورود و خروج، تغییر رمز، خروج از
        دستگاه‌ها، ساخت یا حذفِ حساب، و تغییرِ تنظیمات توسط مدیر — با زمان
        و آی‌پی تا <strong><?= h(toPersianDigits((string)Audit::KEEP_DAYS)) ?> روز</strong>
        نگه داشته می‌شوند تا بشود فهمید چه کسی چه کاری کرده. کارِ روزمره‌ی
        شما با دفترتان (ثبت و ویرایشِ تراکنش، چک، بودجه) در آن ثبت
        <strong>نمی‌شود</strong> و «آمار استفاده» هم از آن نمی‌خواند.
    </p>

    <?php /* ⛔ تیکت چیزی است که **خودِ کاربر** می‌نویسد، پس باید بداند
             کجا می‌رود و چه کسی می‌بیندش. اگر روزی مرزِ این بخش عوض شد،
             این بند هم همان روز باید عوض شود. */ ?>
    <p class="privacy-p">
        <strong>درخواست‌های پشتیبانی:</strong> متنی که در بخش پشتیبانی
        می‌نویسید و فایلی که پیوست می‌کنید برای مدیرِ همین نصب دیده
        می‌شود — چون برای همان نوشته شده. کنارِ هر درخواست فقط
        <strong>نسخه‌ی برنامه و نامِ مرورگرتان</strong> خودکار اضافه
        می‌شود تا لازم نباشد از شما بپرسیم؛ نه آی‌پی، نه آدرسِ صفحه، نه
        چیزی از دفترتان. درخواست‌ها و پیوست‌هایشان در
        <a href="<?= APP_BASE_PATH ?>/profile.php">خروجی کاملِ داده</a>
        می‌آیند و با حذفِ حساب هم پاک می‌شوند.
    </p>
</div>

<div class="card">
    <h2 class="card-title">اختیارِ شما</h2>
    <p class="privacy-p">
        هر وقت بخواهید می‌توانید <strong>خروجی کاملِ</strong> داده‌تان را
        در یک فایل بگیرید، یا <strong>حساب و همه‌ی داده‌اش را برای همیشه
        پاک کنید</strong>. هر دو در
        <a href="<?= APP_BASE_PATH ?>/profile.php">حساب کاربری</a>،
        بخش «داده‌ی من».
    </p>
    <p class="privacy-p">
        حذف حساب برگشت‌پذیر نیست و پشتیبانی هم نمی‌تواند برش گرداند.
    </p>
</div>

<div class="card">
    <h2 class="card-title">تماس</h2>
    <?php if ($support !== ''): ?>
        <p class="privacy-p">
            سؤال یا مشکل: <a href="mailto:<?= h($support) ?>"><?= h($support) ?></a>
        </p>
    <?php else: ?>
        <p class="privacy-p hint">
            آدرس پشتیبانی هنوز تنظیم نشده است. مدیرِ این نصب می‌تواند
            آن را در پنل مدیریت وارد کند.
        </p>
    <?php endif; ?>
    <p class="hint version-line">نسخه: <?= h(appVersion()) ?></p>
</div>

<?php if ($__loggedIn): ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
<?php else: ?>
</div>
</body>
</html>
<?php endif; ?>

