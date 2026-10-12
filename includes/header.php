<?php
// ⚠ اینجا لود می‌شود نه در تک‌تکِ صفحه‌ها: زنگِ اعلان در نوارِ بالاست و
//   نوارِ بالا در همین فایل است. با لودِ صفحه‌به‌صفحه، زنگ روی بعضی
//   صفحه‌ها می‌آمد و روی بعضی نه — و کاربر فکر می‌کرد اعلانش پرید.
require_once __DIR__ . '/notify.php';

if (!isset($pageTitle)) {
    $pageTitle = APP_NAME;
}
/* عرضِ صفحه — همان الگوی `$pageTitle`: صفحه پیش از این include
   مقدارش را می‌گذارد و اینجا فقط پیش‌فرض گرفته می‌شود. پیش‌فرض
   **باریک** است، پس هیچ صفحه‌ای با افزودنِ این خط عوض نمی‌شود. */
if (!isset($pageWide)) {
    $pageWide = false;
}
/* نمای دسکتاپ — فقط بالای `DESK_MIN_PX` عرض را باز می‌کند (برخلافِ
   `$pageWide` که از ۷۲۰ به بعد باز است)، تا تبلت و پنجره‌ی نیمه‌باز همان
   ستونِ خواندنیِ همیشگی بمانند. */
if (!isset($pageDesk)) {
    $pageDesk = false;
}
// فشرده‌سازی خروجی در includes/db.php و پیش از هر خروجی فعال می‌شود
?>
<!DOCTYPE html>
<!-- js-loading را app.js بلافاصله برمی‌دارد. اگر برنداشت یعنی اسکریپت
     نرسیده و صفحه با اینکه کامل به نظر می‌رسد، هیچ دکمه‌ای ندارد —
     استایل .js-loading همین را بعد از یک مکث کوتاه نشان می‌دهد. -->
<html lang="fa" dir="rtl" class="js-loading">
<head>
    <script>
        /* حالت شب سه‌حالته: auto (پیش‌فرض) / light / dark
           در حالت auto، اپ زنده از تنظیمات گوشی پیروی می‌کند. */
        (function () {
            try {
                /* ⛔ «خودکار» پیش‌فرضِ همه است (خواسته‌ی مالکِ نصب: «حالتِ خودکارِ
                   شب و روز پیش‌فرض فعال باشه مگه اینکه کاربر خاموش کنه»). یک بار
                   (`daftar_theme_v`) هر انتخابِ دستیِ قدیمی پاک می‌شود — بیشترشان
                   با یک تپِ گذرا روی ماه/خورشید ساخته شده بودند، نه با تصمیم. بعد از
                   آن، انتخابِ دستی (دکمه‌ی بالا، یا خاموش کردنِ «خودکار» در حساب
                   کاربری) دوباره می‌ماند. */
                if (localStorage.getItem('daftar_theme_v') !== '2') {
                    localStorage.removeItem('daftar_theme');
                    localStorage.setItem('daftar_theme_v', '2');
                }
                var mode = localStorage.getItem('daftar_theme') || 'auto';
                var sysDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
                var dark = (mode === 'dark') || (mode === 'auto' && sysDark);
                if (dark) { document.documentElement.setAttribute('data-theme', 'dark'); }
                document.documentElement.setAttribute('data-theme-mode', mode);
                /* پالتِ رنگ (UI_PALETTES). ⛔ فقط نام‌های همان فهرست پذیرفته
                   می‌شوند و پیش‌فرض (اولین کلید، نیلی) ویژگی نمی‌گیرد؛ مقدارِ دیگری در
                   localStorage (دست‌کاری یا پالتی که حذف شده) بی‌صدا به
                   پیش‌فرض برمی‌گردد، نه به صفحه‌ی بی‌رنگ. */
                var pal = localStorage.getItem('daftar_palette') || '';
                if (<?= json_encode(array_values(array_slice(array_keys(UI_PALETTES), 1)), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>.indexOf(pal) !== -1) {
                    document.documentElement.setAttribute('data-palette', pal);
                }
            } catch (e) {}
        })();
        /* ⛔ نمای دسکتاپ: کوکیِ `DESK_COOKIE` برای بارگذاریِ **بعدی** (دلیلش بالای `deskView()`). */
        <?= deskCookieScript() ?>
    </script>
    <meta charset="UTF-8">
    <!-- viewport-fit=cover لازم است وگرنه iOS مقدار env(safe-area-inset-*)
         را صفر می‌دهد و همه‌ی محاسبه‌های حاشیه‌ی امن بی‌اثر می‌مانند. -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <title><?= h($pageTitle) ?> | <?= h(APP_NAME) ?></title>
    <!-- فونت داخل CSS تعریف شده، پس مرورگر تا وقتی style.css را نگرفته و
         تجزیه نکرده خبر ندارد لازمش دارد. با preload هر دو با هم دانلود
         می‌شوند و متن یک رفت‌وبرگشت زودتر با فونت درست می‌نشیند. -->
    <link rel="preload" href="<?= APP_BASE_PATH ?>/assets/fonts/Vazirmatn.woff2" as="font" type="font/woff2" crossorigin>
    <?php foreach (assetUrls(['css/style.css']) as $__u): ?>
    <link rel="stylesheet" href="<?= h($__u) ?>">
    <?php endforeach; ?>
    <!-- اسکریپت با defer در head می‌آید نه ته صفحه: این‌طور مرورگر همان
         اول شروع به گرفتنش می‌کند و موازی با خواندن HTML دانلود می‌شود،
         ولی اجرایش مثل قبل بعد از ساخته‌شدن کل صفحه است. -->
    <script>window.APP_BASE = '<?= APP_BASE_PATH ?>';</script>
    <?php foreach (assetUrls(['js/client-errors.js', 'js/num-input.js', 'js/jalali-datepicker.js', 'js/sms-core.js', 'js/app.js']) as $__u): ?>
    <script defer src="<?= h($__u) ?>"></script>
    <?php endforeach; ?>
    <?php /* ⛔ پیش‌گیریِ صفحه‌ی بعد — تصمیم و فهرستِ استثناها در
             `speculationRulesJson()`؛ مرورگرِ بی‌پشتیبانی نادیده‌اش می‌گیرد. */ ?>
    <script type="speculationrules" id="specRules" data-next="<?= h(speculationNextTabs((string)($_SERVER['SCRIPT_NAME'] ?? ''))) ?>"><?= speculationRulesJson((string)($_SERVER['SCRIPT_NAME'] ?? '')) ?></script>
    <link rel="apple-touch-icon" href="<?= iconUrl('icon-180.png') ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= iconUrl('icon-32.png') ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= iconUrl('icon-16.png') ?>">
    <link rel="manifest" href="<?= APP_BASE_PATH ?>/assets/manifest.php">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?= h(APP_NAME) ?>">
    <meta name="theme-color" content="#2a3563">
    <?php
    /* ⛔ نوارِ «نسخه‌ی تازه‌ی اپ آماده است»: سرور آخرین نسخه (از خودِ فایلِ
       APK) و نسخه‌ی نصب‌شده (کوکیِ `Auth::captureAppVersion()`) را می‌دهد و
       `app.js` تصمیم می‌گیرد، چون فقط مرورگر می‌داند *داخلِ اپ* است یا
       کرومِ معمولی. فقط برای اندروید رندر می‌شود — بقیه یک stat هم نمی‌دهند. */
    $__apk = isAndroidRequest() ? androidApkLatest() : null;
    if ($__apk !== null): ?>
    <meta name="apk-latest" content="<?= (int)$__apk['code'] ?>" data-name="<?= h($__apk['name']) ?>" data-url="<?= h($__apk['url']) ?>" data-installed="<?= Auth::appVersion() ?: '' ?>" data-app="<?= h(APP_NAME) ?>" data-package="<?= h(defined('ANDROID_PACKAGE') ? ANDROID_PACKAGE : '') ?>">
    <?php endif; ?>
</head>
<body>
<?= envBanner() ?>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="main-content">
        <header class="topbar">
            <button class="menu-toggle" id="menuToggle" aria-label="باز کردن منو">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M4 6H20M4 12H20M4 18H20" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            </button>
            <h1 class="page-title"><?= h($pageTitle) ?></h1>
            <div class="topbar-user">
                <?php
                    /* ⛔ زنگ فقط وقتی رندر می‌شود که جدولش آمده باشد —
                       لینکی که به صفحه‌ی «هنوز ساخته نشده» می‌رود از
                       نبودنش بدتر است. تولیدِ اعلان روزی یک بار و از
                       همین‌جا انجام می‌شود، پس هیچ cron ای لازم نیست
                       (همان الگوی `processRecurringTransactions`). */
                    $__notifOn = false;
                    $__unread  = 0;
                    if (Auth::isLoggedIn() && Notify::available()) {
                        $__notifOn = true;
                        Notify::generateFor((int)Auth::userId());
                        $__unread = Notify::unreadCount((int)Auth::userId());
                    }
                ?>
                <?php if ($__notifOn): ?>
                <?php /* ⛔ `data-unread` همان عدد را به `app.js` می‌دهد تا روی **آیکونِ اپ**
                         هم بنشیند (`navigator.setAppBadge`) — هیچ کوئریِ تازه‌ای نیست. */ ?>
                <a href="<?= APP_BASE_PATH ?>/notifications.php" class="theme-toggle notif-bell" aria-label="اعلان‌ها" data-unread="<?= (int)$__unread ?>">
                    <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 01-3.4 0"/></svg>
                    <?php if ($__unread > 0): ?>
                        <span class="notif-badge"><?= toPersianDigits((string)min($__unread, 99)) ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
                <a href="<?= APP_BASE_PATH ?>/search.php" class="theme-toggle" aria-label="جستجو">
                    <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
                </a>
                <button type="button" class="theme-toggle" id="themeToggle" aria-label="تغییر حالت شب و روز">
                    <svg class="theme-icon-moon" width="19" height="19" viewBox="0 0 24 24" fill="none"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <svg class="theme-icon-sun" width="19" height="19" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="4.5" stroke="currentColor" stroke-width="2"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                </button>
                <a href="<?= APP_BASE_PATH ?>/profile.php" class="user-name" style="text-decoration:none;"><?= h(Auth::fullName()) ?></a>
                <?php
                /* ⛔ برچسب از `Auth::roleLabel()` می‌آید، نه از یک
                   سه‌گانه‌ی محلی. با `isAdmin() ? 'مدیر' : 'کاربر'`،
                   «پشتیبان» و «همکار» هر دو «کاربر» دیده می‌شدند —
                   یعنی نقشی که مدیر با دست داده، **هیچ‌جا** دیده
                   نمی‌شد و کاربر هم نمی‌فهمید چرا بخشی برایش باز است.
                   رنگ فقط دو حالت دارد (مدیر/غیرمدیر) چون معنای
                   `badge-admin` همان «دسترسیِ کامل» است. */
                ?>
                <span class="user-role-badge <?= Auth::isAdmin() ? 'badge-admin' : 'badge-user' ?>">
                    <?= h(Auth::roleLabel(Auth::role())) ?>
                </span>
            </div>
        </header>

        <?php /* صفحه‌ای که جدولِ پهن دارد پیش از این include مقدارِ
                 `$pageWide = true` می‌گذارد؛ بقیه دست‌نخورده می‌مانند. */ ?>
        <div class="page-content<?= $pageWide ? ' is-wide' : '' ?><?= $pageDesk ? ' is-desk' : '' ?>">
            <?php /* پیشنهادِ نصبِ اپ اندروید — بالای همه چیز، ولی فقط
                     روی اندروید و فقط وقتی APK واقعاً وجود دارد. خودش
                     تصمیم می‌گیرد که رندر شود یا نه. */ ?>
            <?= androidInstallBanner() ?>
            <?php /* ⛔ پیشوندِ `__` اجباری است و یک خرابیِ واقعی را بست.
                     این فایل در **دامنه‌ی سراسریِ خودِ صفحه** اجرا می‌شود،
                     پس هر نامِ عمومی‌ای که اینجا مقدار بگیرد، متغیرِ
                     هم‌نامِ صفحه را — که پیش از این include مقدار گرفته —
                     بی‌صدا بازنویسی می‌کند. `$flash` دقیقاً همین را کرد:
                     `support.php` پیامِ «درخواست شما ثبت شد» را در
                     `$flash` می‌گذاشت، اینجا `null` می‌شد، و چون
                     `null !== ''` است نوارِ سبز **خالی** روی هر صفحه‌ی
                     پشتیبانی رندر می‌شد و پیامِ واقعی هرگز دیده نمی‌شد.
                     قاعده ۵۰ در `test_api_contract.php` این را می‌سنجد. */ ?>
            <?php $__flash = getFlash(); ?>
            <?php if ($__flash): ?>
                <div class="alert alert-<?= h($__flash['type']) ?>"><?= h($__flash['message']) ?></div>
            <?php endif; ?>
