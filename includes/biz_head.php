<?php
/**
 * ⛔ پوسته‌ی محیطِ فروشگاهی — سرآیند، نوارِ کناریِ سمتِ راست و نوارِ بالا.
 *
 * عمداً **هیچ** چیزی از پوسته‌ی شخصی (`header.php`/`sidebar.php`/
 * `footer.php`/`app.js`/`style.css`) لود نمی‌کند: آن‌ها منو، شیتِ ثبتِ
 * تراکنش، پیش‌گیریِ صفحه‌های شخصی، معرفیِ اولیه و صفِ پیامکِ بانک را با
 * خودشان می‌آوردند. استایلِ این محیط فایلِ خودش است (`store.css`).
 *
 * قاب (سیستمِ طراحیِ فروشگاه — بالای `store.css`):
 * - **نوارِ کناری** (راست): شناسه‌ی فروشگاه، منوی گروه‌بندی‌شده از `Biz::NAV`،
 *   و پایینش کاربر و راهِ برگشت به دفترِ شخصی. روی دسکتاپ با «جمع کردن»
 *   فقط آیکون می‌ماند؛ روی گوشی کشو است.
 *   ⛔ کشو بی‌جاوااسکریپت است: یک چک‌باکسِ پنهان + `<label>`. کشویی که با
 *      نرسیدنِ اسکریپت باز نشود همان «دکمه‌ی بی‌کار» است.
 * - **نوارِ بالا**: مسیر (breadcrumb)، جست‌وجو (فرمِ GET به `search.php`؛
 *   `store.js` آن را فرمانِ سریعِ Ctrl+K می‌کند)، «+ ثبتِ جدید» (`<details>`)،
 *   حالتِ شب و نمایه.
 *
 * ⛔ صفر کوئریِ اضافه نسبت به پوسته‌ی قبلی: شناسه‌ی فروشگاه و رنگ از همان
 *    `Biz::settings()`، و بقیه از نشست.
 *
 * ورودیِ مستند فقط `$pageTitle` است؛ هر متغیرِ سراسریِ دیگرِ این فایل
 * پیشوندِ `__` دارد (قاعده ۵۰).
 */
if (!defined('APP_BASE_PATH')) { http_response_code(404); exit; }
require_once __DIR__ . '/csrf.php';

$__bizUid   = Biz::ownerId();
$__bizSet   = Biz::settings($__bizUid);
$__bizShop  = $__bizSet['shop_name'] !== '' ? $__bizSet['shop_name'] : 'فروشگاه من';
$__bizName  = Auth::fullName() !== '' ? Auth::fullName() : 'کاربر';
$__bizInit  = mb_substr(trim($__bizName), 0, 1);
// ⛔ تصویرِ شخصی جای حرفِ اول — نامش را `Biz::requirePage()` در همان کوئریِ
//    نوعِ حساب خوانده (صفر کوئریِ اضافه)؛ فایلِ نبوده یعنی همان حرف.
require_once __DIR__ . '/avatar.php';
$__bizAvUrl = avatarUrl($_SESSION['avatar'] ?? null);
$__bizAv    = $__bizAvUrl !== ''
    ? '<img class="st-avatar-img" src="' . h($__bizAvUrl) . '" alt="" width="38" height="38" decoding="async">'
    : h($__bizInit);
$__bizType  = Biz::TYPES[$_SESSION['account_type'] ?? ''] ?? '';
$__bizScript = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
$__bizPage  = Biz::navCurrent($__bizScript);
$__bizFlash = getFlash();
// ⛔ رنگِ فروشگاه از `biz_settings` (همان کوئریِ `settings()` — صفر کوئریِ اضافه).
//    پیش‌فرض ویژگی نمی‌گیرد: خودِ `:root`ِ `store.css` است.
$__bizPal   = Biz::palette($__bizUid);
$__bizPalA  = $__bizPal === array_key_first(Biz::PALETTES) ? '' : ' data-st-palette="' . h($__bizPal) . '"';
$__bizIcons = Biz::navIcons();
// ⛔ «خطاهای فروشگاه» فقط برای مدیرِ نصب (`store/errors.php`) — در `Biz::NAV`
//    نیست تا مالکِ فروشگاه نه منو، نه فرمانِ سریع، نه منوی پایینش را ببیند.
//    کوئریِ شمارش هم فقط برای مدیر اجرا می‌شود؛ صفحه‌ی فروشنده صفر کوئریِ اضافه.
$__bizErrs  = Auth::isAdmin() ? AppErrors::openCount('store') : null;

// مسیر: گروه ← قلمِ منو ← (اگر صفحه‌ی جزئیات است) عنوانِ خودِ صفحه
$__bizCrumbs = [];
foreach (Biz::nav() as $__g => $__items) {
    if (isset($__items[$__bizPage])) {
        $__bizCrumbs[] = [$__g, ''];
        $__bizCrumbs[] = [$__items[$__bizPage], $__bizPage !== $__bizScript ? Biz::url($__bizPage) : ''];
        break;
    }
}
// صفحه‌ی جزئیات (کالا، فاکتور، …) عنوانِ خودش را هم می‌گیرد
if (($pageTitle ?? '') !== '' && ($__bizPage !== $__bizScript || !$__bizCrumbs)) {
    $__bizCrumbs[] = [(string)$pageTitle, ''];
}

// ⛔ دادهٔ فرمانِ سریع: فقط برچسب و آدرسِ صفحه‌ها (هیچ رکوردی) — رکوردها را
//    `search.php` با همان نشست و همان سنجشِ مالکیت می‌دهد.
$__bizCmd = ['pages' => [], 'new' => [], 'search' => Biz::url('search.php')];
foreach (Biz::nav() as $__g => $__items) {
    foreach ($__items as $__f => $__l) {
        if (is_file(__DIR__ . '/../' . Biz::DIR . '/' . $__f)) { $__bizCmd['pages'][] = ['t' => $__l, 'g' => $__g, 'u' => Biz::url($__f), 'k' => $__f]; }
    }
}
foreach (array_filter(Biz::NEW_MENU, [Biz::class, 'canNew'], ARRAY_FILTER_USE_KEY) as $__h => $__l) { $__bizCmd['new'][] = ['t' => $__l, 'u' => Biz::url($__h), 'k' => $__h]; }
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl" data-area="store"<?= $__bizPalA ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= h(($pageTitle ?? '') !== '' ? $pageTitle . ' · ' . $__bizShop : $__bizShop) ?></title>
    <script><?= Biz::bootScript() ?></script>
    <?php /* ⛔ گزارش‌گرِ خطای مرورگر (`client-errors.js`) — مشترک با حساب‌لند. `data-area`
             محیط را می‌گوید (`AppErrors::AREAS`) و توکن از همین متا خوانده می‌شود؛
             بی‌این دو، خطای جاوااسکریپتِ فروشگاه یا ۴۰۳ می‌گرفت یا «حساب‌لند» ثبت می‌شد. */ ?>
    <meta name="csrf-token" content="<?= h(Csrf::token()) ?>">
    <script>window.APP_BASE = <?= json_encode(APP_BASE_PATH, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;</script>
    <?php /* ⛔ فونت داخلِ CSS تعریف شده و مرورگر تا تجزیه‌ی آن خبر ندارد لازمش
             دارد؛ preload هر دو را با هم می‌گیرد (همان آدرسِ `@font-face`، بی‌`?v=`
             — آدرسِ متفاوت یعنی دو دانلود). */ ?>
    <link rel="preload" href="<?= APP_BASE_PATH ?>/assets/fonts/Vazirmatn.woff2" as="font" type="font/woff2" crossorigin>
    <?php foreach (assetUrls(['css/store.css']) as $__u): ?>
    <link rel="stylesheet" href="<?= h($__u) ?>">
    <?php endforeach; ?>
    <?php /* ⛔ `store.js` فقط **بهبود** می‌دهد (فرمانِ سریع، جمعِ زنده و افزودنِ
             ردیفِ فاکتور، خواندنِ بارکد، Toast، حالتِ شب). هر فرم بی‌آن هم کار
             می‌کند و جمع را همیشه سرور حساب می‌کند؛ کشوی منو، «+ ثبت» و
             جست‌وجو اصلاً به آن بند نیستند. */ ?>
    <?php foreach (assetUrls(['js/client-errors.js', 'js/store.js']) as $__u): ?>
    <script defer src="<?= h($__u) ?>"></script>
    <?php endforeach; ?>
    <meta name="theme-color" content="<?= h(Biz::PALETTES[$__bizPal]['theme']) ?>">
    <?= Biz::webAppTags($__bizShop) ?>
    <?php /* ⛔ تعویضِ فوریِ صفحه: مکثِ نشانگر/لمس صفحه‌ی بعد را از پیش می‌سازد (`Biz::speculationRulesJson()`). */ ?>
    <script type="speculationrules" id="stSpecRules"><?= Biz::speculationRulesJson() ?></script>
    <script type="application/json" id="stCmdData"><?= json_encode($__bizCmd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
</head>
<body class="st-body">
<?= envBanner() ?>
<input type="checkbox" id="stNavToggle" class="st-nav-toggle" aria-hidden="true" tabindex="-1">
<aside class="st-side" aria-label="منوی فروشگاه">
    <a class="st-side-brand" href="<?= h(Biz::url()) ?>" title="<?= h($__bizShop) ?>">
        <span class="st-brand-mark is-logo" aria-hidden="true"><img src="<?= h(Biz::icon('96')) ?>" alt="" width="34" height="34"></span>
        <span class="st-brand-text">
            <span class="st-brand-name"><?= h($__bizShop) ?></span>
            <span class="st-brand-sub">حساب‌لند · فروشگاه</span>
        </span>
    </a>
    <nav class="st-side-nav">
        <?php foreach (Biz::nav() as $__group => $__items):
            $__shown = array_filter($__items, fn($f) => is_file(__DIR__ . '/../' . Biz::DIR . '/' . $f), ARRAY_FILTER_USE_KEY);
            if (!$__shown) { continue; } ?>
        <div class="st-side-group">
            <p class="st-side-title"><?= h($__group) ?></p>
            <?php foreach ($__shown as $__file => $__label): ?>
            <a href="<?= h(Biz::url($__file)) ?>" title="<?= h($__label) ?>"
               class="st-side-item<?= $__bizPage === $__file ? ' is-active' : '' ?>"
               <?= $__bizPage === $__file ? 'aria-current="page"' : '' ?>>
                <svg class="st-side-ico" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $__bizIcons[$__file] ?? '<circle cx="12" cy="12" r="4"/>' ?></svg>
                <span><?= h($__label) ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
        <?php if ($__bizErrs !== null): ?>
        <div class="st-side-group">
            <p class="st-side-title">مدیرِ نصب</p>
            <a href="<?= h(Biz::url('errors.php')) ?>" title="خطاهای فروشگاه"
               class="st-side-item<?= $__bizScript === 'errors.php' ? ' is-active' : '' ?>"
               <?= $__bizScript === 'errors.php' ? 'aria-current="page"' : '' ?>>
                <svg class="st-side-ico" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l9.5 16.5h-19z"/><path d="M12 10v4M12 17.5v.01"/></svg>
                <span>خطاهای فروشگاه</span>
                <?php if ($__bizErrs > 0): ?><b class="st-side-count" aria-label="<?= h(toPersianDigits((string)$__bizErrs)) ?> خطای باز"><?= h(toPersianDigits((string)$__bizErrs)) ?></b><?php endif; ?>
            </a>
        </div>
        <?php endif; ?>
    </nav>
    <div class="st-side-foot">
        <div class="st-me" title="<?= h($__bizName) ?>">
            <span class="st-avatar<?= $__bizAvUrl !== '' ? ' has-img' : '' ?>" aria-hidden="true"><?= $__bizAv ?></span>
            <span class="st-me-text">
                <span class="st-me-name"><?= h($__bizName) ?></span>
                <span class="st-me-role"><?= h($__bizType) ?></span>
            </span>
        </div>
        <div class="st-side-links">
            <?php /* حسابِ «شخصی + فروشگاه» یک راهِ برگشت به حساب لندِ خودش دارد؛
                     همان نشست است و ورودِ دوباره نمی‌خواهد. */ ?>
            <?php if (!Biz::isStoreOnly()): ?>
            <a class="st-switch" href="<?= h(Biz::personalUrl()) ?>" title="برگشت به دفترِ شخصی">دفتر شخصی</a>
            <?php endif; ?>
            <a class="st-logout" href="<?= h(Biz::url('logout.php')) ?>">خروج</a>
        </div>
        <?php /* ⚠ «جمع کردن» فقط با اسکریپت کار می‌کند، پس تا رسیدنش پنهان است. */ ?>
        <button type="button" class="st-side-mini-btn" data-side-mini hidden aria-label="جمع کردنِ منو">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>
            <span>جمع کردنِ منو</span>
        </button>
    </div>
</aside>
<label for="stNavToggle" class="st-side-scrim" aria-hidden="true"></label>
<div class="st-shell">
<header class="st-top">
    <div class="st-top-in">
        <label for="stNavToggle" class="st-menu-btn" role="button" aria-label="منو">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
        </label>
        <ol class="st-crumbs" aria-label="مسیر">
            <?php if (!$__bizCrumbs): ?><li><span><?= h($__bizShop) ?></span></li><?php endif; ?>
            <?php foreach ($__bizCrumbs as [$__ct, $__cu]): ?>
            <li><?php if ($__cu !== ''): ?><a href="<?= h($__cu) ?>"><?= h($__ct) ?></a><?php else: ?><span><?= h($__ct) ?></span><?php endif; ?></li>
            <?php endforeach; ?>
        </ol>
        <form class="st-search" role="search" method="get" action="<?= h(Biz::url('search.php')) ?>" data-cmd-open>
            <svg class="st-search-ico" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
            <input type="search" name="q" placeholder="جستجو یا فرمان…" aria-label="جستجو در مشتری، کالا، فاکتور، چک و صندوق" autocomplete="off">
            <kbd class="st-kbd">Ctrl K</kbd>
        </form>
        <div class="st-top-tools">
            <a class="st-icon-btn st-search-mobile" href="<?= h(Biz::url('search.php')) ?>" aria-label="جستجو" data-cmd-link>
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
            </a>
            <details class="st-dd st-new-top">
                <summary class="st-new-btn"><span aria-hidden="true">+</span> ثبتِ جدید</summary>
                <div class="st-dd-menu st-new-menu"><?= Biz::newMenuHtml($__bizUid) ?></div>
            </details>
            <button type="button" class="st-icon-btn st-theme-btn" data-theme-toggle hidden aria-label="حالتِ شب و روز">
                <svg class="st-ico-moon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z"/></svg>
                <svg class="st-ico-sun" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
            </button>
            <details class="st-dd st-profile">
                <summary aria-label="حساب کاربری"><span class="st-avatar<?= $__bizAvUrl !== '' ? ' has-img' : '' ?>"><?= $__bizAv ?></span></summary>
                <div class="st-dd-menu">
                    <div class="st-dd-head"><b><?= h($__bizName) ?></b><span><?= h($__bizShop) ?> · <?= h($__bizType) ?></span></div>
                    <a href="<?= h(Biz::url('settings.php')) ?>#avatar">تصویر شخصی</a>
                    <a href="<?= h(Biz::url('account.php')) ?>">رمز و بکاپ</a>
                    <a href="<?= h(Biz::url('settings.php')) ?>">تنظیمات فروشگاه</a>
                    <a href="<?= h(Biz::url('invoice-design.php')) ?>">طراحی فاکتور</a>
                    <?php if (!Biz::isStoreOnly()): ?>
                    <a href="<?= h(Biz::personalUrl()) ?>">دفتر شخصی</a>
                    <?php endif; ?>
                    <div class="st-dd-sep"></div>
                    <a href="<?= h(Biz::url('logout.php')) ?>">خروج</a>
                </div>
            </details>
        </div>
    </div>
</header>
<main class="st-main">
<?php if ($__bizFlash !== null): ?>
    <?php /* «warning» = کار انجام شد ولی چیزی دیدنی است (IMEIِ مشکوک) — نمی‌پرد تا خوانده شود */ ?>
    <div class="st-flash st-flash-<?= $__bizFlash['type'] === 'success' ? 'ok' : ($__bizFlash['type'] === 'warning' ? 'warn' : 'err') ?>" role="status"<?= $__bizFlash['type'] === 'warning' ? '' : ' data-toast' ?>><?= h($__bizFlash['message']) ?></div>
<?php endif; ?>
