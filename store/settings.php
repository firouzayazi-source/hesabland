<?php
/**
 * تنظیماتِ فروشگاه — سربرگِ فاکتور. صندوق‌ها در `accounts.php` هستند.
 *
 * فرمِ POST با CSRF و بعد ریدایرکت (الگوی `admin/errors.php`): بدونِ
 * جاوااسکریپت هم کار می‌کند و تازه‌سازیِ صفحه فرم را دوباره نمی‌فرستد.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/avatar.php';
require_once __DIR__ . '/../includes/biz_docview.php';

$userId   = Biz::ownerId();
$error    = '';
$values   = Biz::settings($userId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    // ⛔ تصویرِ شخصی از همان `saveUserAvatar()`ِ پروفایلِ حساب لند می‌رود
    //    (includes/avatar.php) — نوع از محتوا، بازکشی با GD، نامِ تصادفی.
    if (postParam('action') === 'avatar') {
        $res = saveUserAvatar($userId, $_FILES['avatar'] ?? null);
        redirectWithMessage(Biz::url('settings.php') . '#avatar', $res['ok'] ? 'success' : 'error', $res['message']);
    }
    if (postParam('action') === 'avatar_delete') {
        $res = deleteUserAvatar($userId);
        redirectWithMessage(Biz::url('settings.php') . '#avatar', $res['ok'] ? 'success' : 'error', $res['message']);
    }
    // رنگِ فروشگاه فرمِ خودش را دارد تا ذخیره‌ی رنگ به سربرگ دست نزند.
    if (postParam('action') === 'palette') {
        $res = Biz::savePalette($userId, (string)postParam('palette'));
        redirectWithMessage(Biz::url('settings.php') . '#palette', $res['ok'] ? 'success' : 'error', $res['message']);
    }
    // ⛔ بستنِ دوره — فقط `Biz::saveLock()` (تاریخِ شمسیِ فرم → میلادی)
    if (postParam('action') === 'lock') {
        $raw = (string)postParam('lock_quick') !== '' ? (string)postParam('lock_quick') : BizDocView::gDate((string)postParam('lock_date'));
        if ((string)postParam('lock_date') !== '' && (string)postParam('lock_quick') === '' && $raw === '') {
            redirectWithMessage(Biz::url('settings.php') . '#lock', 'error', 'تاریخ را به شکلِ ۱۴۰۵/۰۶/۳۱ بنویسید.');
        }
        $res = Biz::saveLock($userId, $raw, postParam('confirm_unlock') === '1');
        redirectWithMessage(Biz::url('settings.php') . '#lock', $res['ok'] ? 'success' : 'error', $res['message']);
    }
    // ⛔ چیدمانِ منوی «+» — فقط `Biz::saveNewMenu()` (کلیدهای کاتالوگ، دست‌کم یکی)
    if (postParam('action') === 'new_menu') {
        $res = Biz::saveNewMenu($userId, is_array($_POST['menu'] ?? null) ? $_POST['menu'] : []);
        redirectWithMessage(Biz::url('settings.php') . '#newmenu', $res['ok'] ? 'success' : 'error', $res['message']);
    }
    // ⛔ گردکردنِ قیمتِ کالای ارزی — فقط `Biz::saveRateRound()`
    if (postParam('action') === 'rate_round') {
        $res = Biz::saveRateRound($userId, (int)postParam('rate_round', '1000'));
        redirectWithMessage(Biz::url('settings.php') . '#rates', $res['ok'] ? 'success' : 'error', $res['message']);
    }
    if (postParam('action') === 'accounting') {
        $res = Biz::saveAccounting($userId, $_POST);
        redirectWithMessage(Biz::url('settings.php') . '#accounting', $res['ok'] ? 'success' : 'error', $res['message']);
    }
    if (postParam('action') === 'comms' || postParam('action') === 'share_rotate') {
        $rot = postParam('action') === 'share_rotate';
        $res = BizComms::saveSettings($userId, $rot ? BizComms::settings($userId) : $_POST, $rot);
        redirectWithMessage(Biz::url('settings.php') . '#comms', $res['ok'] ? 'success' : 'error', $res['message']);
    }
    if (postParam('action') === 'invoice_prefs') {
        $res = Biz::saveInvoicePrefs($userId, $_POST);
        redirectWithMessage(Biz::url('settings.php') . '#invoice', $res['ok'] ? 'success' : 'error', $res['message']);
    }
    $in = [];
    foreach (array_keys(Biz::SETTING_LIMITS) as $k) {
        $in[$k] = (string)($_POST[$k] ?? '');
    }
    $res = Biz::saveSettings($userId, $in);
    if ($res['ok']) {
        redirectWithMessage(Biz::url('settings.php'), 'success', $res['message']);
    }
    $error  = $res['message'];
    $values = $in;
}

$ready    = Biz::businessInfoReady();
$invPrefs = Biz::invoicePrefs($userId);
// مشتریِ پیش‌فرض — فقط مشتری‌های فعال (تأمین‌کننده روی فاکتورِ فروش نمی‌نشیند)
$customers = $ready ? array_values(array_filter(BizParties::all($userId, '', 500, false)['rows'],
    fn($p) => $p['kind'] !== 'supplier' && (int)$p['is_active'] === 1)) : [];

$pageTitle = 'تنظیمات فروشگاه';
require __DIR__ . '/../includes/biz_head.php';
?>
<h1 class="st-h1">تنظیمات فروشگاه</h1>

<?php $__av = avatarUrl($_SESSION['avatar'] ?? null); $__avOk = Biz::$avatarReady ?? usersHaveColumn(Database::getConnection(), 'avatar');   // از کوئریِ نوعِ حساب — بی‌کوئریِ ساختار ?>
<h2 class="st-h2 st-section-title" id="avatar">تصویر شخصی</h2>
<div class="st-card st-form">
    <p class="st-muted">جای حرفِ اولِ نامتان، بالای صفحه و در منو می‌نشیند — همان تصویرِ پروفایلِ دفترِ شخصی.</p>
    <?php if (!$__avOk): ?>
    <p class="st-muted">ستونِ تصویر هنوز در دیتابیس ساخته نشده (<code dir="ltr">bash deploy/migrate.sh --apply</code>).</p>
    <?php else: ?>
    <div class="st-avatar-row">
        <span class="st-avatar st-avatar-lg<?= $__av !== '' ? ' has-img' : '' ?>" data-avatar-preview aria-hidden="true"><?php if ($__av !== ''): ?><img class="st-avatar-img" src="<?= h($__av) ?>" alt="" width="72" height="72"><?php else: ?><?= h(mb_substr(trim(Auth::fullName() !== '' ? Auth::fullName() : 'کاربر'), 0, 1)) ?><?php endif; ?></span>
        <form method="post" enctype="multipart/form-data" class="st-avatar-form" action="<?= h(Biz::url('settings.php')) ?>" data-avatar-form>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="avatar">
            <label class="st-field">
                <span>انتخابِ تصویر</span>
                <input type="file" name="avatar" accept="image/*" required data-avatar-input>
            </label>
            <button type="submit" class="st-btn">ذخیره‌ی تصویر</button>
        </form>
        <?php if ($__av !== ''): ?>
        <form method="post" action="<?= h(Biz::url('settings.php')) ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="avatar_delete">
            <button type="submit" class="st-btn st-btn-ghost">حذفِ تصویر</button>
        </form>
        <?php endif; ?>
    </div>
    <p class="st-muted">JPG، PNG یا WEBP. مربعِ وسطِ تصویر برداشته و کوچک می‌شود.</p>
    <?php endif; ?>
</div>

<h2 class="st-h2 st-section-title" id="theme">حالتِ شب و روز</h2>
<div class="st-card st-form">
    <?php /* ⛔ پیش‌فرض «خودکار» (پیروی از گوشی)؛ دکمه‌ی ماه/خورشیدِ بالا دستی‌اش
             می‌کند و همین کلید برش می‌گرداند. در همین مرورگر ذخیره می‌شود
             (`st_theme`)، پس فرم و سرور ندارد — بی‌جاوااسکریپت خاموش می‌ماند. */ ?>
    <label class="st-check">
        <input type="checkbox" data-theme-auto disabled checked>
        <span>خودکار — هر وقت گوشی یا رایانه شب شد، فروشگاه هم شب می‌شود</span>
    </label>
    <p class="st-muted">با دکمه‌ی ماه/خورشید بالای صفحه حالت دستی می‌شود و همان می‌ماند؛ برای برگشت به خودکار، همین را روشن کنید.</p>
</div>
<h2 class="st-h2 st-section-title" id="palette">رنگِ فروشگاه</h2>
<form method="post" class="st-card st-form" action="<?= h(Biz::url('settings.php')) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="palette">
    <p class="st-muted">منوی کناری، دکمه‌ها و کارتِ «فروشِ امروز» با همین رنگ طیف می‌گیرند — روی همه‌ی دستگاه‌ها.</p>
    <div class="st-palettes" role="radiogroup" aria-label="رنگِ فروشگاه">
        <?php $__cur = Biz::palette($userId); foreach (Biz::PALETTES as $__k => $__p): ?>
        <label class="st-pal">
            <input type="radio" name="palette" value="<?= h($__k) ?>"<?= $__k === $__cur ? ' checked' : '' ?>>
            <span class="st-pal-swatch" data-pal="<?= h($__k) ?>" aria-hidden="true"></span>
            <span class="st-pal-name"><?= h($__p['label']) ?></span>
        </label>
        <?php endforeach; ?>
    </div>
    <button type="submit" class="st-btn">ذخیره‌ی رنگ</button>
</form>
<h2 class="st-h2 st-section-title" id="newmenu">منوی «+ ثبتِ جدید»</h2>
<form method="post" class="st-card st-form" action="<?= h(Biz::url('settings.php')) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="new_menu">
    <p class="st-muted">هر کاری که زیاد انجام می‌دهید را روشن کنید تا در منوی «+» (دکمه‌ی وسطِ پایینِ گوشی و «ثبتِ جدید» بالای صفحه) کاشی داشته باشد؛ بقیه را خاموش کنید. همه‌ی کارها از فرمانِ سریع (جستجو) هم در دسترس‌اند.</p>
    <div class="st-new-pick">
        <?php $__nm = Biz::newMenu($userId); foreach (Biz::NEW_MENU as $__k => $__l): [$__s, $__t, $__svg] = Biz::NEW_TILES[$__k]; ?>
        <label class="st-new-opt is-tone-<?= h($__t) ?>">
            <input type="checkbox" name="menu[]" value="<?= h($__k) ?>"<?= in_array($__k, $__nm, true) ? ' checked' : '' ?>>
            <span class="st-new-ico"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $__svg ?></svg></span>
            <span><?= h($__l) ?></span>
        </label>
        <?php endforeach; ?>
    </div>
    <button type="submit" class="st-btn">ذخیره‌ی منو</button>
</form>
<?php require_once __DIR__ . '/../includes/biz_rates.php'; ?>
<?php if (Rates::available()): $__rates = Rates::all(); $__rr = Biz::rateRound($userId); ?>
<h2 class="st-h2 st-section-title" id="rates">نرخِ روز و قیمتِ ارزی</h2>
<form method="post" class="st-card st-form" action="<?= h(Biz::url('settings.php')) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="rate_round">
    <p class="st-muted">
        برای هر کالا می‌توانید قیمتِ پایه را به دلار، گرمِ طلا یا سکه بدهید (در صفحه‌ی همان کالا)؛ قیمتِ فروش با هر نرخِ تازه
        خودش به‌روز می‌شود. فاکتورِ صادرشده هرگز عوض نمی‌شود.
    </p>
    <div class="st-rates">
        <?php foreach (Rates::CODES as $__c => [$__l, $__u]): $__r = $__rates[$__c] ?? null; if (!$__r) { continue; } ?>
            <span class="st-rate<?= Rates::isStale($__r) ? ' is-stale' : '' ?>"><b><?= h($__l) ?></b> <span class="ltr-num"><?= formatMoney((int)$__r['price']) ?></span> <small><?= h(Rates::ago($__r['fetched_at'])) ?></small></span>
        <?php endforeach; ?>
        <?php if (!$__rates): ?><span class="st-muted">هنوز نرخی دریافت نشده؛ مدیرِ برنامه منبع‌ها را در پنلِ مدیر روشن می‌کند.</span><?php endif; ?>
    </div>
    <label class="st-field">
        <span>گرد کردنِ قیمتِ فروش</span>
        <select name="rate_round">
            <?php foreach (BizRates::ROUNDS as $__v => $__lbl): ?>
                <option value="<?= (int)$__v ?>"<?= $__v === $__rr ? ' selected' : '' ?>><?= h($__lbl) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <button type="submit" class="st-btn">ذخیره</button>
</form>
<?php endif; ?>
<h2 class="st-h2 st-section-title">سربرگِ فاکتور</h2>

<?php if ($error !== ''): ?>
<div class="st-flash st-flash-err" role="alert"><?= h($error) ?></div>
<?php endif; ?>

<form method="post" class="st-card st-form" action="<?= h(Biz::url('settings.php')) ?>">
    <?= Csrf::field() ?>
    <p class="st-muted">این‌ها بالای فاکتورهای چاپی می‌نشینند.</p>

    <label class="st-field">
        <span>نامِ فروشگاه</span>
        <input type="text" name="shop_name" required maxlength="<?= Biz::SETTING_LIMITS['shop_name'] ?>"
               value="<?= h($values['shop_name']) ?>" autocomplete="organization">
    </label>
    <label class="st-field">
        <span>تلفن</span>
        <input type="text" name="phone" dir="ltr" inputmode="tel" maxlength="<?= Biz::SETTING_LIMITS['phone'] ?>"
               value="<?= h($values['phone']) ?>" autocomplete="tel">
    </label>
    <label class="st-field">
        <span>آدرس</span>
        <input type="text" name="address" maxlength="<?= Biz::SETTING_LIMITS['address'] ?>"
               value="<?= h($values['address']) ?>" autocomplete="street-address">
    </label>
    <?php if ($ready): ?>
    <fieldset class="st-fieldset">
        <legend>اطلاعاتِ رسمی <small class="st-muted">(اختیاری — روی سربرگِ فاکتورِ رسمی)</small></legend>
        <div class="st-row2">
            <?php foreach (Biz::SETTING_CODES as $ck): ?>
            <label class="st-field">
                <span><?= h(BizCommon::CODES[$ck][2]) ?></span>
                <input type="text" name="<?= h($ck) ?>" dir="ltr" inputmode="numeric" maxlength="24" value="<?= h((string)($values[$ck] ?? '')) ?>">
            </label>
            <?php endforeach; ?>
        </div>
    </fieldset>
    <?php endif; ?>
    <label class="st-field">
        <span>متنِ پایینِ فاکتور <small class="st-muted">(اختیاری)</small></span>
        <textarea name="invoice_footer" rows="3" maxlength="<?= Biz::SETTING_LIMITS['invoice_footer'] ?>"><?= h($values['invoice_footer']) ?></textarea>
    </label>

    <button type="submit" class="st-btn">ذخیره</button>
</form>

<?php if ($ready): ?>
<h2 class="st-h2 st-section-title" id="invoice">گزینه‌های فاکتور</h2>
<form method="post" class="st-card st-form" action="<?= h(Biz::url('settings.php')) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="invoice_prefs">
    <p class="st-muted">همه پیش‌فرض خاموش‌اند. فروشِ بیش از موجودی همیشه بسته است — موجودیِ انبار هیچ‌وقت منفی نمی‌شود.</p>
    <?php foreach (Biz::INVOICE_FLAGS as $fk => $fl): ?>
    <label class="st-check">
        <input type="checkbox" name="<?= h($fk) ?>" value="1"<?= $invPrefs[$fk] ? ' checked' : '' ?>>
        <span><?= h($fl) ?></span>
    </label>
    <?php endforeach; ?>
    <label class="st-field">
        <span>مشتریِ پیش‌فرضِ فاکتورِ فروش</span>
        <select name="default_party">
            <option value="0">— بی‌پیش‌فرض (گذری یا انتخاب در هر فاکتور) —</option>
            <?php foreach ($customers as $c): ?>
            <option value="<?= (int)$c['id'] ?>"<?= (int)$c['id'] === $invPrefs['default_party'] ? ' selected' : '' ?>><?= h((string)$c['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <button type="submit" class="st-btn">ذخیره‌ی گزینه‌ها</button>
</form>
<?php endif; ?>

<?php if (BizComms::ready()): $cm = BizComms::settings($userId); $smsWhy = BizComms::smsBlocked($userId); ?>
<h2 class="st-h2 st-section-title" id="comms">ارتباط با مشتری</h2>
<form method="post" class="st-card st-form" action="<?= h(Biz::url('settings.php')) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="comms">
    <label class="st-check"><input type="checkbox" name="sms_on" value="1"<?= $cm['sms_on'] ? ' checked' : '' ?>>
        <span>پیامک به مشتری (فاکتور، یادآوریِ مانده و قسط، «گوشی آماده است») — فقط با دکمه، هرگز خودکار</span></label>
    <p class="st-muted"><?= $smsWhy === null ? 'آماده است. این ماه ' . toPersianDigits((string)BizComms::sentThisMonth($userId)) . ' از ' . toPersianDigits((string)BizComms::SMS_MONTHLY) . ' پیامک.' : h($smsWhy) ?></p>
    <label class="st-check"><input type="checkbox" name="loyalty_on" value="1"<?= $cm['loyalty_on'] ? ' checked' : '' ?>>
        <span>باشگاهِ مشتری (امتیازِ وفاداری)</span></label>
    <div class="st-row2">
        <label class="st-field"><span>هر چند تومان خرید = یک امتیاز</span><input type="text" name="earn_per" inputmode="numeric" dir="ltr" value="<?= h(number_format($cm['earn_per'])) ?>" data-money></label>
        <label class="st-field"><span>ارزشِ هر امتیاز (تومان تخفیف)</span><input type="text" name="point_value" inputmode="numeric" dir="ltr" value="<?= h(number_format($cm['point_value'])) ?>" data-money></label>
    </div>
    <button type="submit" class="st-btn">ذخیره</button>
</form>
<form method="post" class="st-card st-form" action="<?= h(Biz::url('settings.php')) ?>" onsubmit="return confirm('همه‌ی لینک‌های فاکتور که تا حالا برای مشتری‌ها فرستاده‌اید باطل شوند؟');">
    <?= Csrf::field() ?><input type="hidden" name="action" value="share_rotate">
    <p class="st-muted">لینکِ فاکتور (برای مشتری، بی‌ورود) امضا دارد و فقط همان فاکتور را نشان می‌دهد.</p>
    <button type="submit" class="st-btn st-btn-ghost">باطل کردنِ همه‌ی لینک‌های فاکتور</button>
</form>
<?php endif; ?>

<?php if (Biz::accReady()): $accSet = Biz::moadian($userId); ?>
<h2 class="st-h2 st-section-title" id="accounting">مالیات و سامانه‌ی مودیان</h2>
<form method="post" class="st-card st-form" action="<?= h(Biz::url('settings.php')) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="accounting">
    <label class="st-field"><span>نرخِ مالیات بر ارزش افزوده (٪) <small class="st-muted">(۰ = خاموش؛ نرخِ امروز ۱۰)</small></span>
        <input type="text" name="vat_rate" value="<?= h(BizInvoices::rateText(Biz::vatRate($userId))) ?>" inputmode="decimal" dir="ltr" maxlength="5"></label>
    <p class="st-muted">نرخ روی هر فاکتورِ <b>تازه</b> می‌نشیند و در خودِ فاکتور عوض‌شدنی است؛ فاکتورِ صادرشده با نرخِ خودش می‌ماند. کالای معاف را در صفحه‌ی همان کالا علامت بزنید. گزارشِ فصلی در <a href="<?= h(Biz::url('accounting.php?t=vat')) ?>">دفاتر و مالیات</a>.</p>
    <div class="st-row2">
        <label class="st-field"><span>شناسه‌ی یکتای حافظه‌ی مالیاتی <small class="st-muted">(۶ نویسه، از کارپوشه)</small></span>
            <input type="text" name="moadian_memory_id" value="<?= h($accSet['memory_id']) ?>" dir="ltr" maxlength="6" autocomplete="off"></label>
        <label class="st-field"><span>شناسه‌ی کالا/خدمتِ پیش‌فرض <small class="st-muted">(۱۳ رقم)</small></span>
            <input type="text" name="moadian_sstid" value="<?= h($accSet['sstid']) ?>" inputmode="numeric" dir="ltr" maxlength="13"></label>
    </div>
    <button type="submit" class="st-btn">ذخیره</button>
</form>
<?php endif; ?>

<section class="st-card">
    <h2 class="st-h2">صندوق و حساب‌های فروشگاه</h2>
    <p class="st-muted">صندوق‌ها حالا صفحه‌ی خودشان را دارند، با موجودی و گردشِ هر کدام.</p>
    <a class="st-btn st-btn-ghost" href="<?= h(Biz::url('accounts.php')) ?>">صندوق و بانک</a>
</section>
<?php
$lockAt   = Biz::lockDate($userId);
$prevEnd  = date('Y-m-d', strtotime(startOfJalaliMonth() . ' -1 day'));   // پایانِ ماهِ شمسیِ قبل
?>
<h2 class="st-h2 st-section-title" id="lock">بستنِ دوره</h2>
<form method="post" class="st-card st-form" action="<?= h(Biz::url('settings.php')) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="lock">
    <p class="st-muted">وقتی حسابِ یک ماه را بستید و گزارشش را گرفتید، آن را قفل کنید: هیچ فاکتور، برگشت، دریافت/پرداخت، وصول یا انبارگردانی با تاریخِ تا آن روز دیگر ثبت، باطل یا اصلاح نمی‌شود — پس سود، بهای تمام‌شده و مانده‌های آن دوره دیگر عوض نمی‌شوند.</p>
    <p><?= $lockAt !== null
        ? 'الان بسته تا <b class="st-num">' . h(toJalali($lockAt)) . '</b>.'
        : 'الان هیچ دوره‌ای بسته نیست.' ?></p>
    <label class="st-field">
        <span>بستن تا تاریخِ (خالی = باز کردنِ همه)</span>
        <input type="text" name="lock_date" value="<?= $lockAt !== null ? h(BizDocView::jDate($lockAt)) : '' ?>" placeholder="۱۴۰۵/۰۶/۳۱" dir="ltr" class="st-date-in">
    </label>
    <?php if ($lockAt !== null): ?>
    <label class="st-check">
        <input type="checkbox" name="confirm_unlock" value="1">
        <span>می‌خواهم دوره‌ای را که بسته بود دوباره باز کنم (برای عقب بردن یا خالی کردنِ تاریخ لازم است)</span>
    </label>
    <?php endif; ?>
    <div class="st-head-actions">
        <button type="submit" class="st-btn">ذخیره</button>
        <?php if ($lockAt === null || $lockAt < $prevEnd): ?>
        <button type="submit" name="lock_quick" value="<?= h($prevEnd) ?>" class="st-btn st-btn-ghost">بستن تا پایانِ ماهِ قبل (<?= h(toJalali($prevEnd)) ?>)</button>
        <?php endif; ?>
    </div>
</form>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
