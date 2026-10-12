<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/store_share.php';
require_once __DIR__ . '/includes/rates.php';

Auth::initSession();
Auth::requireLogin();

$pdo = Database::getConnection();
$userId = Auth::userId();
$todayStr = today();

seedUserDefaults($userId);

// ⚠ نرخِ روز و وصلِ خودکار (`includes/rates.php`) فقط وقتی ستون‌هایش هست.
$rateReady = tableHasColumn('asset_types', 'rate_code') && Rates::available();
$typesStmt = $pdo->prepare($rateReady
    ? 'SELECT id, name, unit, current_price, rate_code FROM asset_types WHERE user_id = :user_id ORDER BY name'
    : 'SELECT id, name, unit, NULL AS current_price, NULL AS rate_code FROM asset_types WHERE user_id = :user_id ORDER BY name');
$typesStmt->execute(['user_id' => $userId]);
$assetTypes = $typesStmt->fetchAll();
$typeById   = array_column($assetTypes, null, 'id');

// موجودی تجمیع‌شده به تفکیک نوع
//
// ⛔ ارزش با `COALESCE(at.current_price, a.unit_price)` حساب می‌شود، نه
//    فقط `a.unit_price`. `unit_price` بهای واحد **هنگام ثبت** است؛ در
//    اقتصادِ تورمی، ارزشِ سکه‌ای که پارسال خریده شده هیچ ربطی به آن
//    عدد ندارد. اگر کاربر نرخِ روز را وارد نکرده باشد، همان بهای خرید
//    می‌ماند — یعنی رفتارِ قبلی، نه صفر.
$assetSummary = assetSummaryRows($userId);
$totalPortfolioValue = array_sum(array_column($assetSummary, 'total_value'));
// بهای تمام‌شده جدا نگه داشته می‌شود تا بشود «سود/زیانِ محقق‌نشده» را
// نشان داد — بدون آن، کاربر فقط یک عددِ بزرگ‌تر می‌بیند و نمی‌داند
// چقدرش رشدِ قیمت است و چقدرش پولی که گذاشته.
$totalPortfolioCost = array_sum(array_column($assetSummary, 'total_cost'));

// رکوردهای جزئی
$listStmt = $pdo->prepare('
    SELECT a.*, at.name AS type_name, at.unit, at.current_price
    FROM assets a
    JOIN asset_types at ON at.id = a.asset_type_id
    WHERE a.user_id = :user_id
    ORDER BY a.entry_date DESC, a.created_at DESC
');
$listStmt->execute(['user_id' => $userId]);
$assetRecords = $listStmt->fetchAll();

// ⛔ «جزئیاتِ دارایی دقیقاً همانی که وارد شده» — خواسته‌ی مالکِ نصب: «گردن‌بندِ طلا»،
//    «رمز ارز TON». تا امروز ردیف فقط نامِ **نوع** و مقدار را نشان می‌داد و توضیحِ
//    نوشته‌شده پشتِ «▾» پنهان بود؛ سه گردن‌بند و یک سکه همه «طلا» دیده می‌شدند.
//    عنوانِ هر ثبت = خطِ اولِ توضیحِ خودش (`assetEntryLabel()`)، وگرنه نامِ نوع. و
//    کارتِ «ارزش به نرخِ روز» هم می‌گوید هر نوع **شاملِ** چه چیزهایی است — از همین
//    فهرست، بی‌کوئریِ دوم.
$labelsByType = [];
foreach ($assetRecords as $__a) {
    $__l = assetEntryLabel($__a['note'] ?? null);
    if ($__l !== '') { $labelsByType[(int)$__a['asset_type_id']][] = $__l; }
}

// نمای کلی دارایی‌ها: هم دارایی‌های ثبت‌شده‌ی خود کاربر، هم کالایی که
// در بخش معاملات خریده و هنوز نفروخته، چک و طلب/بدهیِ باز، حساب‌ها، و
// سهمِ فروشگاه — چون همه‌ی این‌ها دارایی‌اند.
//
// ⛔ خودِ محاسبه در `netWorthPortfolio()` است، نه اینجا: خانه‌ی دسکتاپ هم
//    «خالص دارایی» را نشان می‌دهد و دو نسخه از این منطق دیر یا زود دو
//    عدد می‌گفتند. دلیلِ تک‌تکِ قلم‌ها (چرا معاملات یک قلمِ جمع‌شده است،
//    چرا فقط چکِ در جریان، چرا «مانده»ی فروشگاه و چرا دو قلمِ فروشگاه
//    فقط برای مدیر) بالای همان تابع نوشته شده.
//
// ⚠️ اینجا فقط «چقدر داریم» دیده می‌شود. خرید و فروش فقط در بخش
// معاملات انجام می‌شود و کم و زیاد کردن مقدار دارایی فقط همین‌جا.
$storeSyncStatus = ['fetched_at' => null, 'last_error' => null];
if (StoreShare::available()) {
    // آینه اگر کهنه بود تازه می‌شود — و همان‌جا سهمِ سود در دفترِ
    // کاربر ثبت می‌شود. جای این فراخوانی عمداً همین یک صفحه است؛
    // `netWorthPortfolio()` خودش هیچ درخواستِ شبکه‌ای نمی‌زند.
    StoreShare::refreshIfStale();
    $storeSyncStatus = StoreShare::status();
}
// ⛔ دکمه‌ی ورود به صفحه‌ی کالاها به **همان تابعی** بند است که خودِ آن
//    صفحه دامنه‌اش را از آن می‌گیرد (`StoreShare::assetOwners()`). با یک
//    شرطِ محلیِ دوم، دیر یا زود دکمه برای کسی رندر می‌شد که صفحه‌اش
//    خالی است — همان «دکمه‌ی بی‌کار از نبودنش بدتر است».
$storeCanView = StoreShare::available() && StoreShare::canViewAssets($userId, Auth::isAdmin());

$nw = netWorthPortfolio($userId, Auth::isAdmin(), null, $assetSummary);
$portfolio      = $nw['rows'];
$tradeOpenCount = $nw['trade_open_count'];
$chequeCount    = $nw['cheque_count'];
$debtCount      = $nw['debt_count'];
$storeShare     = $nw['store_share'];

// همان پالت گزارش دسته‌بندی، تا دو صفحه یک زبان بصری داشته باشند.
// قاچ اول سبز است (دارایی) و بقیه از چرخه‌ی هیوهای متمایز می‌آیند.
$paletteLight = chartPalette('green', 'light');
$paletteDark  = chartPalette('green', 'dark');
$pn = count($paletteLight);
foreach ($portfolio as $i => $row) {
    $portfolio[$i]['color_l'] = $paletteLight[$i % $pn];
    $portfolio[$i]['color_d'] = $paletteDark[$i % $pn];
    // کلید پایدار برای به‌خاطر سپردن انتخابِ روشن/خاموش در همین مرورگر
    $portfolio[$i]['key'] = $row['kind'] . ':' . $row['name'];
}

// جمع اولیه = همه‌ی قلم‌ها روشن. جاوااسکریپت با خاموش کردن هر قلم،
// همین عدد و درصدها و نمودار را دوباره می‌سازد.
$portfolioTotal = 0;
foreach ($portfolio as $row) { $portfolioTotal += $row['value']; }
$chartable = array_values(array_filter($portfolio, fn($r) => $r['value'] > 0));

// ---------- روندِ خالص دارایی ----------
// عکسِ امروز با همان اجزایی که بالا حساب شده‌اند ثبت می‌شود — بدون
// cron، به همان روشِ processRecurringTransactions. اجزا جدا ذخیره
// می‌شوند نه جمع، چون هر قلم کلیدِ روشن/خاموش دارد.
$snapParts = netWorthSnapParts($portfolio);
recordNetWorthSnapshot($userId, $snapParts);

$nwHistory = netWorthHistory($userId, 12);
// «نسبت به قبل» فقط وقتی معنا دارد که دست‌کم دو نقطه باشد؛ با یک نقطه
// عددِ «۰٪» ساختگی است.
$nwPrev  = count($nwHistory) >= 2 ? $nwHistory[count($nwHistory) - 2]['total'] : null;
$nwDelta = $nwPrev !== null ? $portfolioTotal - $nwPrev : null;

// سود/زیانِ محقق‌نشده‌ی دارایی‌های ثبت‌شده — فقط وقتی نرخِ روز وارد
// شده باشد، وگرنه ارزش و بها یکی‌اند و خطِ «۰» بی‌معناست.
// ⛔ فقط ثبت‌هایی که بهای خرید دارند، در هر دو طرف (`priced_value`): ثبتِ بی‌بها هزینه‌ی
//    صفر دارد و کلِ ارزشش «سود» خوانده می‌شد. و عددِ صحیح (`round`): جمعِ DECIMAL شناور
//    است و `0.0 !== 0` جمله‌ی «۰ زیانِ محقق‌نشده» می‌ساخت — بازرسیِ محاسباتی (مهر ۱۴۰۵).
$unrealized = (int)round(array_sum(array_column($assetSummary, 'priced_value')) - $totalPortfolioCost);

$pageTitle = 'دارایی‌ها';
include __DIR__ . '/includes/header.php';
?>

<!-- ---------- نمای کلی دارایی‌ها ----------
     هر قلم یک کلید روشن/خاموش دارد. خاموش کردنش چیزی را پاک نمی‌کند؛
     فقط از جمع و نمودار بیرونش می‌گذارد — تا بشود پرسید «دارایی‌ام
     بدون طلا چقدر است؟» یا «بدون پولِ توی حساب‌ها چقدر؟». انتخاب در
     همین مرورگر به خاطر می‌ماند. -->
<div class="card">
    <div class="asset-total-row">
        <?php /* ⚠ «خالص» نه «کل»: بدهی از این عدد کم می‌شود، پس
                 «ارزش کل دارایی‌ها» اسمِ غلطی بود. */ ?>
        <span class="asset-total-label">خالص دارایی</span>
        <b class="asset-total-value" id="assetGrandTotal"><?= formatMoney($portfolioTotal) ?> <small><?= h(APP_CURRENCY) ?></small></b>
    </div>

    <?php if ($nwDelta !== null && $nwDelta !== 0): ?>
        <p class="nw-delta <?= $nwDelta > 0 ? 'delta-up' : 'delta-down' ?>">
            <?= $nwDelta > 0 ? '▲' : '▼' ?> <?= formatMoney(abs($nwDelta)) ?>
            نسبت به <?= h(toJalali($nwHistory[count($nwHistory) - 2]['date'])) ?>
        </p>
    <?php endif; ?>

    <?php if (count($nwHistory) >= 2): ?>
        <?php /* اسپارک‌لاین بی‌محور و بی‌عدد: اینجا شکلِ روند مهم است نه
                 مقدارِ دقیق — آن را همان عددِ بالای صفحه می‌گوید. */ ?>
        <div class="nw-spark"><canvas id="nwSpark" height="46"></canvas></div>
    <?php endif; ?>

    <?php if ($unrealized !== 0 && $totalPortfolioCost > 0): ?>
        <p class="hint asset-total-note">
            از دارایی‌های ثبت‌شده،
            <strong class="<?= $unrealized > 0 ? 'delta-up' : 'delta-down' ?>"><?= formatMoney(abs($unrealized)) ?></strong>
            <?= $unrealized > 0 ? 'سود' : 'زیان' ?> محقق‌نشده دارید
            (بهای خرید <?= formatMoney($totalPortfolioCost) ?>).
        </p>
    <?php endif; ?>

    <p class="hint asset-total-note" id="assetExcludedNote" hidden></p>

    <?php if (empty($portfolio)): ?>
        <p class="empty-row">هنوز دارایی‌ای ثبت نشده است.</p>
    <?php else: ?>
        <?php if (!empty($chartable)): ?>
        <div class="chart-container" style="max-width:250px; height:250px; margin:16px auto 18px;">
            <canvas id="assetChart"></canvas>
        </div>
        <?php endif; ?>

        <?php if (count($portfolio) > 6): ?>
        <div class="asset-search-wrap">
            <input type="search" id="assetFilter" class="asset-search"
                   placeholder="جستجو میان <?= toPersianDigits(count($portfolio)) ?> دارایی…"
                   autocapitalize="none" autocorrect="off">
        </div>
        <?php endif; ?>

        <div class="category-breakdown-list" id="assetBreakdown">
            <?php foreach ($portfolio as $row): ?>
                <?php
                // درصد فقط وقتی معنا دارد که هم جمع کل مثبت باشد هم خودِ قلم.
                // با یک خالصِ منفیِ بزرگ (بدهی بیشتر از دارایی) جمع کل منفی
                // می‌شود و «۰٪» گمراه‌کننده بود.
                $pct = ($portfolioTotal > 0 && $row['value'] > 0)
                    ? round($row['value'] / $portfolioTotal * 100, 1) : 0;
                $showPct = $portfolioTotal > 0 && $row['value'] > 0;
                ?>
                <div class="cat-breakdown-item asset-item"
                     data-name="<?= h($row['name']) ?>"
                     data-key="<?= h($row['key']) ?>"
                     data-value="<?= (int)$row['value'] ?>">
                    <div class="cat-breakdown-summary">
                        <span class="cat-dot" style="--dot-l:<?= h($row['color_l']) ?>; --dot-d:<?= h($row['color_d']) ?>;"></span>
                        <?php
                        // برچسب فقط روی دارایی‌های ثبت‌شده معنا دارد. دو ردیفِ
                        // جمع‌شده («مجموع دارایی‌های بخش معاملات» و «مجموع
                        // حساب‌ها») خودشان اسمشان را می‌گویند، و برچسبِ تکراری
                        // فقط عرض می‌گرفت و نام را با «…» می‌برید.
                        ?>
                        <span class="cat-breakdown-name"><?= h($row['name']) ?></span>
                        <span class="cat-breakdown-pct"><?= $showPct ? toPersianDigits($pct) . '٪' : '—' ?></span>
                        <?php
                        // مقدار منفی با علامت «−» می‌آید، وگرنه یک بدهیِ خالص
                        // شبیه دارایی دیده می‌شد. علامت ریاضیِ منها (U+2212)
                        // است نه خط تیره، تا در راست‌به‌چپ درست بنشیند.
                        ?>
                        <span class="cat-breakdown-amount<?= $row['value'] < 0 ? ' asset-amount-neg' : '' ?>">
                            <?= $row['value'] !== 0 ? ($row['value'] < 0 ? '−' : '') . formatMoney(abs($row['value'])) : '۰' ?>
                        </span>
                        <label class="switch switch-sm asset-toggle" title="اعمال در جمع و نمودار">
                            <input type="checkbox" class="js-asset-include" checked>
                            <span class="switch-track"><span class="switch-knob"></span></span>
                        </label>
                    </div>
                    <div class="asset-item-qty">
                        <?= formatQuantity($row['qty']) ?> <?= h($row['unit']) ?>
                        <?php // «قیمت ثبت نشده» فقط برای دارایی معنا دارد؛ خالصِ صفرِ
                              // چک یا طلب/بدهی یعنی سر به سر، نه قیمتِ نداشته. ?>
                        <?php if ($row['value'] === 0 && $row['kind'] === 'asset'): ?>
                            <span class="asset-noprice">قیمت واحد ثبت نشده</span>
                        <?php elseif ($row['value'] === 0): ?>
                            <span class="asset-noprice">سر به سر</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($row['value'] > 0): ?>
                    <div class="cat-breakdown-bar-track">
                        <div class="cat-breakdown-bar" style="width:<?= $pct ?>%; --dot-l:<?= h($row['color_l']) ?>; --dot-d:<?= h($row['color_d']) ?>;"></div>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="asset-empty-filter" id="assetNoMatch" hidden>چیزی با این نام پیدا نشد.</p>

        <?php if ($tradeOpenCount > 0): ?>
        <p class="hint" style="margin-top:12px;">
            «مجموع دارایی‌های بخش معاملات» جمعِ <?= toPersianDigits((string)$tradeOpenCount) ?> کالای فروخته‌نشده است.
            برای دیدن و فروششان به <a href="<?= APP_BASE_PATH ?>/trades.php">بخش معاملات</a> بروید.
        </p>
        <?php endif; ?>

        <?php if ($chequeCount > 0 || $debtCount > 0): ?>
        <p class="hint" style="margin-top:<?= $tradeOpenCount > 0 ? '6' : '12' ?>px;">
            <?php if ($chequeCount > 0): ?>
                «خالص چک‌های در جریان» یعنی دریافتی منهای صادره، فقط برای چک‌های
                <b>پاس‌نشده</b> — چکِ پاس‌شده پولش از قبل در موجودی حساب‌ها هست.
                <?php foreach ($portfolio as $__cr) { if ($__cr['kind'] === 'cheques' && isset($__cr['face']) && $__cr['face'] !== $__cr['value']): ?>
                    به <b>ارزشِ امروز</b> حساب شده (مبلغِ اسمی <span class="ltr-num"><?= ($__cr['face'] < 0 ? '−' : '') . formatMoney(abs($__cr['face'])) ?></span>)؛
                    هر روز که به سررسید نزدیک‌تر شود به مبلغِ خودِ چک می‌رسد.
                <?php break; endif; } ?>
                (<a href="<?= APP_BASE_PATH ?>/cheques.php">بخش چک‌ها</a>)
            <?php endif; ?>
            <?php if ($debtCount > 0): ?>
                «خالص طلب و بدهی» یعنی باقیمانده‌ی طلب منهای باقیمانده‌ی بدهی؛
                اگر بدهی بیشتر باشد از جمع کل کم می‌شود.
                (<a href="<?= APP_BASE_PATH ?>/debts.php">بخش طلب و بدهی</a>)
            <?php endif; ?>
        </p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($storeCanView): ?>
<?php
/*
 * ⛔ کارتِ کوچک، و فهرستِ کالاها در صفحه‌ی خودش.
 *
 * **خواسته‌ی مالکِ نصب:** «دارایی من در فروشگاه خودش یک کارت کوچیک بشه
 * که از طریق اون بتونم توش تمام محصولاتشون رو مدیریت بکنم.»
 *
 * نسخه‌ی قبلی فهرستِ کاملِ دستگاه‌ها را **درجا** رندر می‌کرد. با یک
 * سهامدارِ ده‌تایی قابل تحمل بود؛ ولی این صفحه وظیفه‌اش نشان دادنِ
 * **ترکیبِ** دارایی است و یک فهرستِ صدتایی وسطش، نمودار و توگل‌ها را
 * زیرِ خود دفن می‌کرد.
 *
 * ⛔ و هیچ دکمه‌ی خرید و فروشی ندارد و نباید داشته باشد — منطقِ درصدِ
 *    سهم و سود در حسابداریِ فروشگاه است. یک دکمه‌ی «فروش» اینجا یعنی
 *    نسخه‌ی دومِ آن منطق، همان مرزی که بین `my-assets.php` و
 *    `trades.php` هم عمداً کشیده شده.
 */
/*
 * ⛔ همان سه تکه‌ی صفحه‌ی کالاها — «سرمایه یا گوشی است یا پولِ آن گوشی
 *    که در فروشگاه است»: کالای در انبار، مانده‌ی نقدی، و جمعشان
 *    (`valueFor()`). «مانده» و «اصل سرمایه»ی دفتر کل از اینجا رفتند؛ روی
 *    نصبِ واقعی با کالاها نمی‌خواندند و عددی بودند که از هیچ‌جای صفحه
 *    درنمی‌آمد. نصبِ عقب‌مانده‌ی فروشگاه (بی‌`holding`) همان «مانده»ی
 *    قبلی را می‌گیرد، با برچسبِ خودش.
 */
$shNum    = static fn($v): int => (int)round((float)($v ?? 0));
$shUnified = $storeShare !== null && array_key_exists('holding', $storeShare);
$shActive  = $storeShare !== null ? (int)($storeShare['active_count'] ?? 0) : 0;
$shSold    = $storeShare !== null ? (int)($storeShare['sold_count'] ?? 0) : 0;
?>
<div class="card store-mini">
    <div class="card-header-row">
        <h2 class="card-title">دارایی من در فروشگاه</h2>
        <span class="asset-tag">فقط نمایش</span>
    </div>

    <?php if ($storeShare !== null): ?>
        <div class="store-own-stats">
            <div class="store-own-stat">
                <span class="store-own-label"><?= $shUnified ? 'دارایی در فروشگاه' : 'مانده' ?></span>
                <b class="ltr-num<?= (int)StoreShare::valueFor($userId) < 0 ? ' asset-amount-neg' : '' ?>"><?= formatMoney((int)StoreShare::valueFor($userId)) ?></b>
            </div>
            <?php if ($shUnified): ?>
            <div class="store-own-stat">
                <span class="store-own-label">در انبار</span>
                <b class="ltr-num"><?= formatMoney($shNum($storeShare['active_cost'] ?? 0) + $shNum($storeShare['items_cost'] ?? 0)) ?></b>
            </div>
            <div class="store-own-stat">
                <span class="store-own-label">مانده نقدی</span>
                <b class="ltr-num<?= $shNum($storeShare['cash_held'] ?? 0) < 0 ? ' asset-amount-neg' : '' ?>"><?= formatMoney($shNum($storeShare['cash_held'] ?? 0)) ?></b>
            </div>
            <?php endif; ?>
            <div class="store-own-stat">
                <span class="store-own-label">سهم سود</span>
                <b class="ltr-num"><?= formatMoney($shNum($storeShare['own_profit'] ?? ($storeShare['earned'] ?? 0))) ?></b>
            </div>
        </div>
        <p class="hint asset-total-note">
            <?= toPersianDigits((string)$shActive) ?> دستگاه در انبار و
            <?= toPersianDigits((string)$shSold) ?> دستگاه فروخته‌شده.
            سهمِ سود خودکار در دفتر شما ثبت می‌شود.
            <?php if ($storeSyncStatus['fetched_at']): ?>
                آخرین به‌روزرسانی: <?= h(jalaliWithWeekday(substr((string)$storeSyncStatus['fetched_at'], 0, 10))) ?>.
            <?php endif; ?>
            <?php if ($storeSyncStatus['last_error']): ?>
                <br><span style="color:var(--warn-ink);">آخرین تلاش ناموفق بود؛ عددهای بالا از آخرین نسخه‌ی موفق است.</span>
            <?php endif; ?>
        </p>
    <?php else: ?>
        <?php /* مدیری که خودش سهامدار نیست: عددی ندارد ولی باید همه را ببیند. */ ?>
        <p class="hint asset-total-note">
            شما به سهامداری وصل نیستید، ولی به‌عنوان مدیر می‌توانید کالای همه‌ی
            سهامدارها و خودِ فروشگاه را ببینید.
        </p>
    <?php endif; ?>

    <a href="<?= APP_BASE_PATH ?>/store-assets.php" class="btn btn-primary store-enter">
        <?= Auth::isAdmin() ? 'دیدن کالاهای فروشگاه' : 'دیدن کالاهای من' ?> ←
    </a>
</div>
<?php endif; ?>

<?php /* ⛔ ارزش به نرخِ روز — **خواسته‌ی مالکِ نصب:** «وقتی دارایی طلا یا دلار
         می‌ذاره به تومن در لحظه حساب بشه». هر نوعی که دارایی دارد: مقدار × نرخ =
         ارزش، و از کجا آمده (خودکار/دستی/بهای خرید). وصل کردن همین‌جاست، نه فقط در
         «فهرست‌های من». عددها همان `assetSummaryRows()`اند — محاسبه‌ی دومی نیست. */ ?>
<?php if ($assetSummary): $__rates = $rateReady ? Rates::all() : []; ?>
<div class="card asset-rates-card">
    <div class="card-header-row">
        <h2 class="card-title">ارزش به نرخِ روز</h2>
        <?php if ($rateReady): ?><span class="asset-tag">به‌روز با نرخِ بازار</span><?php endif; ?>
    </div>
    <div class="asset-rate-list">
        <?php foreach ($assetSummary as $__s):
            $__t   = $typeById[$__s['id']] ?? [];
            $__rc  = (string)($__t['rate_code'] ?? '');
            $__auto = $rateReady && Rates::isCode($__rc);
            $__r   = $__auto ? ($__rates[$__rc] ?? null) : null;
            $__cp  = (int)($__s['current_price'] ?? 0);
        ?>
        <div class="asset-rate-row">
            <div class="asset-rate-main">
                <b><?= h($__s['name']) ?></b>
                <small>
                    <?php /* ⚠ با کلمه شروع می‌شود: عددِ چسبیده به لبه‌ی راست (قاعده‌ی ترازِ عددها) */ ?>
                    موجودی <span class="ltr-num"><?= h(formatQuantity($__s['total_qty'])) ?></span> <?= h($__s['unit']) ?>
                    <?php if ($__cp > 0): ?> × <span class="ltr-num"><?= formatMoney($__cp) ?></span><?php endif; ?>
                </small>
                <?php if (!empty($labelsByType[(int)$__s['id']])): $__ls = $labelsByType[(int)$__s['id']]; ?>
                <small class="asset-rate-items">شامل: <?= h(implode('، ', array_slice($__ls, 0, 4))) ?><?= count($__ls) > 4 ? ' و ' . toPersianDigits((string)(count($__ls) - 4)) . ' موردِ دیگر' : '' ?></small>
                <?php endif; ?>
                <small class="asset-rate-src<?= $__auto && $__r && Rates::isStale($__r) ? ' is-stale' : '' ?>">
                    <?php if ($__auto && $__r): ?>
                        خودکار از نرخِ «<?= h(Rates::label($__rc)) ?>»، <?= h(Rates::ago($__r['fetched_at'])) ?><?= Rates::isStale($__r) ? ' (کهنه)' : '' ?>
                    <?php elseif ($__auto): ?>
                        وصل به «<?= h(Rates::label($__rc)) ?>» — هنوز نرخی نیامده، فعلاً <?= $__cp > 0 ? 'آخرین نرخ' : 'بهای خرید' ?>
                    <?php elseif ($__cp > 0): ?>
                        نرخِ دستی<?= !empty($__s['price_updated_at']) ? ' · ' . h(toJalali((string)$__s['price_updated_at'])) : '' ?>
                    <?php else: ?>
                        به بهای خرید — نرخِ روز ندارد
                    <?php endif; ?>
                </small>
            </div>
            <b class="asset-rate-value ltr-num"><?= formatMoney((int)round((float)$__s['total_value'])) ?></b>
            <?php if ($rateReady): ?>
            <label class="asset-rate-pick">
                <span class="sr-only">نرخِ خودکارِ <?= h($__s['name']) ?></span>
                <select class="js-asset-rate" data-id="<?= (int)$__s['id'] ?>" data-price="<?= $__cp ?>">
                    <?= Rates::optionsHtml($__auto ? $__rc : null, 'نرخِ دستی / بهای خرید') ?>
                </select>
            </label>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if ($rateReady): ?>
    <p class="hint">
        دلار، طلا و سکه خودکار به نرخِ بازار وصل‌اند و ارزششان با هر نرخِ تازه به‌روز می‌شود. برای عوض کردن،
        از منوی کنارِ هر ردیف نرخِ دیگری انتخاب کنید؛ نرخِ دستی در <a href="<?= APP_BASE_PATH ?>/references.php">فهرست‌های من</a>.
        ⚠ واحدِ نوعِ دارایی باید با واحدِ نرخ یکی باشد (گرم با گرم).
    </p>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header-row">
        <h2 class="card-title">ثبت‌های دارایی</h2>
        <button type="button" class="btn btn-primary btn-sm" data-modal-open="addAssetModal" <?= empty($assetTypes) ? 'disabled' : '' ?>>+ ثبت دارایی</button>
    </div>

    <?php if (empty($assetTypes)): ?>
        <p class="empty-row">ابتدا از صفحه‌ی «فهرست‌های من» یک نوع دارایی اضافه کنید.<br><a href="<?= APP_BASE_PATH ?>/references.php" class="link-more">رفتن به فهرست‌های من ←</a></p>
    <?php elseif (empty($assetRecords)): ?>
        <p class="empty-row">هنوز دارایی‌ای ثبت نشده است.</p>
    <?php else: ?>
        <?php foreach ($assetRecords as $a): ?>
            <?php
            $__label = assetEntryLabel($a['note'] ?? null);
            // ارزشِ همین ثبت: نرخِ روز، وگرنه بهای خرید — همان قاعده‌ی `assetSummaryRows()`
            $__per   = (int)($a['current_price'] ?? 0) > 0 ? (int)$a['current_price'] : (int)($a['unit_price'] ?? 0);
            ?>
            <div class="tx-row asset-entry">
                <div class="tx-row-summary">
                    <span class="tx-row-texts">
                        <span class="tx-row-title"><?= h($__label !== '' ? $__label : $a['type_name']) ?></span>
                        <span class="tx-row-cat"><?php /* ⚠ با کلمه شروع می‌شود، نه عدد (قاعده‌ی ترازِ عددها، `test_number_align`) */ ?><?= $__label !== '' ? h($a['type_name']) . ' · ' : 'مقدار ' ?><span class="ltr-num"><?= formatQuantity($a['quantity']) ?></span> <?= h($a['unit']) ?> · <?= toJalali($a['entry_date']) ?></span>
                    </span>
                    <?php if ($__per > 0): ?>
                    <span class="tx-row-amount ltr-num"><?= formatMoney((int)round((float)$a['quantity'] * $__per)) ?></span>
                    <?php endif; ?>
                    <span class="tx-row-chevron">▾</span>
                </div>
                <div class="tx-row-details">
                    <div class="tx-row-details-line"><span>تاریخ</span><span><?= toJalali($a['entry_date']) ?></span></div>
                    <?php if (!empty($a['unit_price'])): ?>
                        <div class="tx-row-details-line"><span>قیمت واحد هنگامِ خرید</span><span><?= formatMoney($a['unit_price']) ?> تومان</span></div>
                        <div class="tx-row-details-line"><span>بهای خرید</span><span><?= formatMoney((float)$a['quantity'] * (int)$a['unit_price']) ?> تومان</span></div>
                    <?php endif; ?>
                    <?php if (!empty($a['current_price'])): ?>
                        <div class="tx-row-details-line"><span>ارزشِ امروز</span><span><b><?= formatMoney((int)round((float)$a['quantity'] * (int)$a['current_price'])) ?></b> تومان</span></div>
                    <?php endif; ?>
                    <?php if (!empty($a['note'])): ?>
                        <div class="tx-row-details-line"><span>توضیح</span><span><?= h($a['note']) ?></span></div>
                    <?php endif; ?>
                    <div class="tx-row-actions">
                        <button type="button" class="btn btn-secondary btn-sm js-edit-asset"
                            data-id="<?= (int)$a['id'] ?>"
                            data-quantity="<?= h($a['quantity']) ?>"
                            data-unit-price="<?= (int)($a['unit_price'] ?? 0) ?>"
                            data-note="<?= h($a['note'] ?? '') ?>"
                            data-entry-date="<?= h($a['entry_date']) ?>">ویرایش</button>
                        <button class="delete-btn js-delete-asset" data-id="<?= (int)$a['id'] ?>">حذف</button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- مدیریت انواع دارایی به صفحه‌ی «فهرست‌های من» منتقل شد. -->
<p class="ref-link-row">
    <a href="<?= APP_BASE_PATH ?>/references.php" class="link-more">افزودن یا حذف انواع دارایی ←</a>
</p>

<!-- مودال افزودن دارایی -->
<div class="modal-overlay" id="addAssetModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>ثبت دارایی</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>

        <form id="addAssetForm" autocomplete="off">
            <?= Csrf::field() ?>

            <div class="form-group">
                <label for="add_asset_type">نوع دارایی</label>
                <select id="add_asset_type" name="asset_type_id" required>
                    <?php foreach ($assetTypes as $at): ?>
                        <option value="<?= (int)$at['id'] ?>" data-unit="<?= h($at['unit']) ?>"
                                data-price="<?= (int)($at['current_price'] ?? 0) ?>"
                                data-auto="<?= $rateReady && Rates::isCode((string)($at['rate_code'] ?? '')) ? '1' : '0' ?>"><?= h($at['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="add_asset_qty">مقدار <span class="req">*</span></label>
                    <input type="text" inputmode="decimal" id="add_asset_qty" data-num name="quantity" required placeholder="مثلاً ۲٫۵">
                </div>
                <div class="form-group">
                    <label>تاریخ</label>
                    <div class="jdp-field">
                        <input type="text" class="jdp-display" readonly value="<?= toJalali($todayStr) ?>">
                        <input type="hidden" class="jdp-hidden" id="add_asset_date" name="entry_date" value="<?= h($todayStr) ?>">
                    </div>
                </div>
            </div>

            <?php /* ⛔ ارزشِ امروز همین‌جا، هم‌زمان با تایپِ مقدار (`app.js`، از
                     `data-price`ِ گزینه) — کاربر پیش از ثبت می‌بیند به تومان چقدر است. */ ?>
            <p class="asset-live-value" id="addAssetLive" hidden></p>

            <div class="form-group">
                <label for="add_asset_price">قیمت واحد هنگامِ خرید به تومان (اختیاری)</label>
                <input type="text" inputmode="numeric" id="add_asset_price" name="unit_price" autocomplete="off">
                <p class="hint">برای دیدنِ سود و زیان. ارزشِ امروز جدا و خودکار حساب می‌شود.</p>
            </div>

            <div class="form-group">
                <label for="add_asset_note">نام یا توضیح <small class="hint">(خطِ اول عنوانِ ثبت می‌شود — مثلاً «گردن‌بندِ طلا»، «TON»)</small></label>
                <textarea id="add_asset_note" name="note" rows="2" maxlength="1000" placeholder="مثلاً گردن‌بندِ طلا ۱۸ عیار"></textarea>
            </div>

            <div id="addAssetMessage" class="form-message" hidden></div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary" id="addAssetSubmitBtn">ثبت</button>
            </div>
        </form>
    </div>
</div>

<!-- مودال ویرایش دارایی -->
<div class="modal-overlay" id="editAssetModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>ویرایش دارایی</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>

        <form id="editAssetForm" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="asset_id" id="edit_asset_id">

            <div class="form-row">
                <div class="form-group">
                    <label for="edit_asset_qty">مقدار <span class="req">*</span></label>
                    <input type="text" inputmode="decimal" id="edit_asset_qty" data-num name="quantity" required>
                </div>
                <div class="form-group">
                    <label>تاریخ</label>
                    <div class="jdp-field">
                        <input type="text" class="jdp-display" id="edit_asset_date_display" readonly>
                        <input type="hidden" class="jdp-hidden" id="edit_asset_date" name="entry_date">
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label for="edit_asset_price">قیمت واحد به تومان (اختیاری)</label>
                <input type="text" inputmode="numeric" id="edit_asset_price" name="unit_price" autocomplete="off">
            </div>

            <div class="form-group">
                <label for="edit_asset_note">نام یا توضیح <small class="hint">(خطِ اول عنوانِ ثبت می‌شود)</small></label>
                <textarea id="edit_asset_note" name="note" rows="2" maxlength="1000"></textarea>
            </div>

            <div id="editAssetMessage" class="form-message" hidden></div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary" id="editAssetSubmitBtn">ذخیره تغییرات</button>
            </div>
        </form>
    </div>
</div>

<meta name="csrf-token" content="<?= Csrf::token() ?>">

<?php /* ⚠ شرط شاملِ اسپارک‌لاین هم هست: کاربری که هنوز دارایی ثبت
         نکرده ولی تاریخچه دارد، بدون این Chart.js را نمی‌گرفت و
         نمودارِ روند خالی می‌ماند. */ ?>
<?php if (!empty($chartable) || count($nwHistory) >= 2): ?>
<!-- همان منبعی که گزارش دسته‌بندی از آن استفاده می‌کند -->
<?php foreach (assetUrls(['js/chart.umd.js']) as $__u): ?>
<script defer src="<?= h($__u) ?>"></script>
<?php endforeach; ?>
<?php if (count($nwHistory) >= 2): ?>
<script id="netWorthData" type="application/json"><?= json_encode(array_map(fn($r) => [
    'd' => toJalali($r['date']),
    'v' => (int)$r['total'],
], $nwHistory), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
<?php endif; ?>
<?php endif; ?>

<?php if (!empty($chartable)): ?>
<script id="assetPortfolioData" type="application/json"><?= json_encode(array_map(fn($r) => [
    'key'   => $r['key'],
    'name'  => $r['name'],
    'value' => (int)$r['value'],
    'cl'    => $r['color_l'],
    'cd'    => $r['color_d'],
], $chartable), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
