<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/home_calendar.php';
require_once __DIR__ . '/includes/tx_query.php';

Auth::initSession();
Auth::requireLogin();

$pdo = Database::getConnection();
$userId = Auth::userId();
$today = today();

// بررسی و پردازش تراکنش‌های دوره‌ای سررسیدشده (بدون نیاز به Cron)
$recurringNeedsAttention = [];
try {
    $recurringNeedsAttention = processRecurringTransactions($userId);
} catch (PDOException $e) {
    $recurringNeedsAttention = []; // جدول هنوز ساخته نشده
}

$categories = cachedCategories();
$incomeCategories  = array_filter($categories, fn($c) => $c['type'] === 'income');
$expenseCategories = array_filter($categories, fn($c) => $c['type'] === 'expense');

$recentStmt = $pdo->prepare('
    SELECT t.id, t.type, t.amount, t.title, t.note, t.transaction_date, t.created_at, t.category_id,
           c.name AS category_name, c.icon AS cat_icon, c.color AS cat_color' . txStoreShareCols() . '
    FROM transactions t
    LEFT JOIN categories c ON c.id = t.category_id
    WHERE t.user_id = :user_id
    ORDER BY t.created_at DESC
    LIMIT 8
');
$recentStmt->execute(['user_id' => $userId]);
// ⛔ وصولِ طلب و چکِ پاس‌شده هم «آخرین جابه‌جایی»اند (`moneyMoves()`)، به ترتیبِ ثبت.
$recentTransactions = array_merge($recentStmt->fetchAll(), moneyMoves($userId, [], 'created', 8));
usort($recentTransactions, fn($a, $b) => strcmp((string)$b['created_at'], (string)$a['created_at']));
$recentTransactions = array_slice($recentTransactions, 0, 8);

// آمار ماه جاری برای نوار مانده بالای صفحه.
// ⛔ از `monthComparison()` می‌آید، نه یک کوئریِ جدا: همان تابع جمعِ
//    «از اولِ ماه تا امروز» را دارد و مقایسه با همین روزِ ماهِ قبل را هم،
//    و `financialHighlights()` هم پیش از این همان را **دوباره** صدا
//    می‌زد. حالا یک بار خوانده و به هر دو داده می‌شود.
$monthCmp     = monthComparison($userId);
$monthIncome  = (int)$monthCmp['current_income'];
$monthExpense = (int)$monthCmp['current_expense'];
$monthNet     = $monthIncome - $monthExpense;

// ⛔ جمله‌ها **بعد از** جمع‌های بالا ساخته می‌شوند و خودشان هیچ استثنایی
//    پرتاب نمی‌کنند (`financialHighlights` هر بخش را جدا `try` می‌کند).
//    این یک کارِ جانبی است؛ اگر یک جدول نیامده باشد نباید صفحه‌ی خانه —
//    یعنی اولین چیزی که کاربر می‌بیند — بشکند.
// ⛔ موجودیِ حساب‌ها **یک بار** خوانده می‌شود و پاس داده می‌شود.
//    سنگین‌ترین کوئریِ اپ است (شش منبعِ پول با LEFT JOIN و تجمیع) و
//    پیش از این در همین یک بارگذاری دو بار اجرا می‌شد: یک بار از
//    `financialHighlights()` → `safeToSpend()` → `totalBalance()` و یک
//    بار از `pinnedWallets()`.
//
// ⚠ چرا پاس دادن و نه کش: کشِ درخواستی یک بار آزموده شد و تست‌ها
//   قرمز شدند — هر مسیری که پول می‌نویسد و بعد موجودی می‌خواند عددِ
//   کهنه می‌گرفت، بی‌هیچ خطایی. اینجا فراخواننده تازگی را خودش
//   تضمین می‌کند، پس چیزی نمی‌تواند کهنه بماند.
$walletRows = walletBalances($userId);

// ⛔ جمله‌ی «حالِ کلیِ ماه» اینجا حذف می‌شود چون همان مقایسه **روی خودِ
//    کارتِ ماه** نوشته شده؛ دو بار گفتنِ یک جمله در یک صفحه فقط جای
//    یک خبرِ دیگر را می‌گرفت.
// ⛔ قلم‌هایی که کاربر در پروفایل خاموش کرده (`HOME_WIDGETS`). تصمیمِ
//    «روشن یا خاموش» فقط از `homeWidgetOn()` می‌آید؛ قلمِ خاموش **رندر
//    نمی‌شود** (نه پنهان با CSS)، پس کارتِ ماه بی‌هیچ ارتفاعِ ثابتی
//    خودش به اندازه‌ی محتوای باقی‌مانده کوچک می‌شود.
$homeHidden = homeHiddenWidgets($userId);
$hw = fn(string $k): bool => homeWidgetOn($homeHidden, $k);

// ⛔ تقویم (`includes/home_calendar.php`) — فقط روی خانه‌ی **دسکتاپ**؛ روی
//    گوشی بالای «گزارش» است («صفحه خانه در گوشی خیلی شلوغ میشه»). پوسته
//    بی‌دیتابیس است و داده‌اش بعد از بارگذاری می‌آید، پس هیچ کوئری‌ای به
//    خانه اضافه نمی‌شود. ماه از `?jy=&jm=` (لینکِ `calendar.php` و ماهِ
//    قبل/بعدِ بی‌جاوااسکریپت).
[$calJy, $calJm] = homeCalendarMonth((int)getParam('jy', '0'), (int)getParam('jm', '0'));

// ---------- نمای دسکتاپ: داشبوردِ کلی ----------
//
// **خواسته‌ی مالکِ نصب:** «وقتی اپ باز میشه در دسکتاپ باید داشبورد با
// مانده حساب‌ها و دارایی و کلیات باز بشه.»
//
// ⛔ فقط وقتی `deskView()` درست است ساخته می‌شود — روی گوشی هیچ‌کدام از
//    این کوئری‌ها زده نمی‌شوند و HTML هم دست نمی‌خورد (دلیلش بالای همان
//    تابع). هر عدد از **همان تابعی** می‌آید که صفحه‌ی خودش از آن می‌خواند:
//    موجودی از `$walletRows`، خالص دارایی از `netWorthPortfolio()` (همان
//    صفحه‌ی دارایی)، و «پول قابل خرج» و سررسیدها از **یک** `safeToSpend()`
//    که به `financialHighlights()` هم پاس داده می‌شود.
$desk        = deskView();
$deskSafe    = null;
$deskNw      = null;
$deskWallets = [];
$deskDaily   = [];
$deskYear    = null;
$deskInsights = null;
$deskDues    = ['rows' => [], 'overdue_n' => 0, 'overdue_sum' => 0, 'soon_n' => 0, 'soon_out' => 0, 'soon_in' => 0];
if ($desk) {
    try { $deskSafe = safeToSpend($userId, 30, $walletRows); } catch (Throwable $e) { $deskSafe = null; }
    try {
        $deskNw = netWorthPortfolio($userId, Auth::isAdmin(), $walletRows);
        // ⚠ عکسِ روزانه از خانه هم ثبت می‌شود (`INSERT IGNORE`، اولینِ روز
        //   برنده است): تا امروز فقط بازدیدِ صفحه‌ی دارایی آن را می‌ساخت،
        //   پس روندِ خالص دارایی برای کسی که آن صفحه را کم باز می‌کند سوراخ
        //   داشت. اجزا همان‌اند، پس دو مسیر عددِ متفاوتی نمی‌نویسند.
        recordNetWorthSnapshot($userId, netWorthSnapParts($deskNw['rows']));
    } catch (Throwable $e) { $deskNw = null; }

    foreach ($walletRows as $__w) {
        if ((int)$__w['is_active'] === 1) { $deskWallets[] = $__w; }
    }

    // ⛔ «داشبورد بیاد ادامه‌ی خانه» (خواسته‌ی مالکِ نصب) — همان تکه‌هایی که
    //    `dashboard.php` رندر می‌کند، از همان توابع (`includes/dash_parts.php`).
    //    روی گوشی هیچ‌کدام زده نمی‌شوند. جمعِ ماه از `$monthCmp`ِ همین صفحه
    //    می‌آید، نه دوباره: همان عددِ کارتِ «مانده این ماه».
    require_once __DIR__ . '/includes/dash_parts.php';
    try { $deskDaily = dashDailyRows($userId); } catch (Throwable $e) { $deskDaily = []; }
    $deskYear = dashYearState($deskDaily, $today, getParam('y', ''));
    try { $deskInsights = spendingInsights($userId, startOfJalaliMonth(), $today); } catch (Throwable $e) { $deskInsights = null; }

    // ⛔ «سررسیدِ نزدیک» = ۱۴ روزِ آینده، به‌علاوه‌ی هر چه **گذشته** و
    //    هنوز باز است — همان پنجره‌ای که `safeToSpend()` از آن تعهد
    //    می‌سازد، پس کارت و فهرست از یک چیز حرف می‌زنند.
    $__soonTo = date('Y-m-d', strtotime(today() . ' +14 days'));
    foreach (($deskSafe['events'] ?? []) as $__e) {
        $__past = $__e['date'] < today();
        if (!$__past && $__e['date'] > $__soonTo) { continue; }
        $deskDues['rows'][] = $__e;
        if ($__past) {
            $deskDues['overdue_n']++;
            if ($__e['direction'] === 'out') { $deskDues['overdue_sum'] += (int)$__e['amount']; }
        } else {
            $deskDues['soon_n']++;
            $deskDues['soon_' . ($__e['direction'] === 'out' ? 'out' : 'in')] += (int)$__e['amount'];
        }
    }
}

$highlights = financialHighlights($userId, $walletRows, $monthCmp, true, $homeHidden, $deskSafe);

// نوارِ «چقدر از دریافتی خرج شد» و خطِ مقایسه — تصمیمش در
// `monthMeter()` است تا تست بدونِ رندرِ صفحه بسنجدش.
$meter = monthMeter($monthCmp);

// ⛔ «دقیقه‌ی اول». تصمیمش تنها در `openingBalanceHint()` است.
$openingHint = openingBalanceHint($userId);

// حساب‌هایی که کاربر خودش برای خانه پین کرده. تصمیمش تنها در
// `pinnedWallets()` است و اگر چیزی پین نشده باشد، نوار اصلاً رندر
// نمی‌شود — نه یک نوارِ خالی.
$pinned = pinnedWallets($userId, $walletRows);

$pageTitle = 'خانه';
$pageDesk  = $desk;
include __DIR__ . '/includes/header.php';
?>

<?php /* تاریخِ امروز به شمسی — **گزارشِ مالکِ نصب:** «تاریخ روز به شمسی در
         صفحه اصلی هنوز اضافه نشده». لینک به تقویم است (`homeCalendarUrl()`:
         دسکتاپ همین صفحه، گوشی «گزارش»)، چون سؤالِ بعد از «امروز چندم است»
         همان «امروز چه سررسیدی دارم» است.
         ⚠ از `today()` می‌آید (منطقه‌ی زمانیِ اپ)، نه `date()` خام. */ ?>
<?php if ($hw('date')): ?>
<a class="home-date" href="<?= h(homeCalendarUrl()) ?>">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4.5" width="18" height="16" rx="2.5"/><path d="M3 9.5h18M8 2.5v4M16 2.5v4"/></svg>
    <span><?= h(jalaliLongDate(today())) ?></span>
</a>
<?php endif; ?>

<?php /* ⛔ پیامکِ بانکیِ نامطمئن اینجا منتظر می‌ماند — فقط روی خانه، و فقط
         وقتی چیزی هست (`app.js`، `renderSmsPending()`). شیتِ ثبت دیگر
         خودش باز نمی‌شود: «یکسره روی صفحه‌ست… هیچ کاری نمیشه کرد». */ ?>
<div id="smsPendingSlot" hidden></div>

<?php /* ⛔ جای این کارت **بالای همه چیز** است، نه پایین‌تر. حرفش این است
         که عددهای زیرش هنوز درست نیستند؛ نشاندنش زیرِ همان عددها یعنی
         کاربر اول باور می‌کند و بعد می‌خواند. فقط تا وقتی دیده می‌شود
         که کاربر جواب نداده باشد و هیچ حسابی موجودی اولیه نداشته باشد. */ ?>
<?php if ($openingHint): ?>
<div class="card start-card">
    <div class="start-head">
        <span class="start-step">قدم اول</span>
        <h2 class="start-title">همین حالا چقدر پول دارید؟</h2>
    </div>
    <p class="start-why">
        تا این عدد را وارد نکنید، حساب‌ها از صفر شروع می‌کنند: با اولین هزینه
        «مجموع حساب‌ها» منفی می‌شود و «پول قابل خرج» هم همین‌طور.
    </p>

    <form id="startBalanceForm" class="start-form" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= Csrf::token() ?>">
        <input type="hidden" name="wallet_id" value="<?= (int)$openingHint['wallet_id'] ?>">
        <input type="hidden" name="mode" value="set">
        <label class="start-label" for="start_balance">
            موجودی «<?= h($openingHint['wallet_name']) ?>» به تومان
        </label>
        <div class="start-row">
            <input type="text" inputmode="numeric" id="start_balance" name="amount"
                   class="amount-input" placeholder="۰" required>
            <button type="submit" class="btn btn-primary" id="startBalanceSubmit">ثبت</button>
        </div>
        <div id="startBalanceMessage" class="form-message" hidden></div>
    </form>

    <div class="start-foot">
        <?php if ($openingHint['more']): ?>
            <a href="<?= APP_BASE_PATH ?>/wallets.php">حساب‌های دیگر ←</a>
        <?php endif; ?>
        <button type="button" class="start-skip" id="startBalanceSkip">نیازی نیست</button>
    </div>
</div>
<?php endif; ?>

<?php /* اسلایدرِ خانه — یک نوار، چند اسلاید.

         ⛔ **اسلاید اول همیشه «مانده این ماه» است** و دقیقاً همان‌جا و
            همان اندازه‌ای می‌ماند که همیشه بوده. حساب‌های پین‌شده
            اسلایدهای بعدی‌اند، نه یک نوارِ تازه زیرِ آن: با نوارِ جدا،
            هر چیزی که پایین‌تر است ۱۲۰ پیکسل پایین می‌رفت و صفحه‌ی
            خانه‌ی کسی که یک حساب پین می‌کند عوض می‌شد.

         ⛔ کارت‌ها **هم‌قدِ نوارِ ماه** می‌شوند، بدونِ هیچ عددِ ثابتی:
            `align-items` پیش‌فرضِ فلکس `stretch` است و ارتفاعِ ردیف را
            بلندترین اسلاید (همان نوار) تعیین می‌کند.

         ⛔ کارت **همان `.bank-card`ِ صفحه‌ی حساب‌هاست**، نه یک طرحِ دوم.
            با دو طرح، اولین باری که یکی عوض شود کاربر بسته به اینکه از
            کجا نگاه می‌کند دو چیزِ متفاوت می‌بیند.

         ⚠ شماره‌ی کارت **روی خودِ کارت** دیده می‌شود — این خواسته‌ی صریحِ
            مالکِ نصب است و جای قاعده‌ی قبلی («فقط داخلِ نمای کارت») را
            گرفت. هزینه‌اش واقعی است: صفحه‌ی خانه همان صفحه‌ای است که
            کاربر جلوی دیگران هم بازش می‌کند. شماره‌ی **حساب** و **شبا**
            عمداً روی کارت نمی‌آیند و فقط داخلِ نما باز می‌شوند، چون
            آن‌ها در یک نگاه از دور خوانده نمی‌شوند ولی در یک عکس
            می‌مانند. */ ?>
<?php if ($desk):
    $__m = fn(int $v): string => ($v < 0 ? '−' : '') . formatMoney(abs($v));
    $__walletSum = totalBalance($userId, $walletRows);
?>
<?php /* ⛔ ردیفِ شاخص‌ها — فقط نمای دسکتاپ (`deskView()`). چهار سؤالی که آدم
         پشتِ میز اول می‌پرسد: «چقدر پول دارم»، «کلاً چقدر دارم»، «چقدرش را
         می‌توانم خرج کنم»، و «چه چیزی در راه است». هر کاشی لینکِ صفحه‌ی
         همان عدد است — عددی که نشود دنبالش کرد، کاربر به آن اعتماد
         نمی‌کند. ⚠ کلاسِ `desk-only`: اگر کوکی هست ولی پنجره کوچک شده،
         CSS پنهانش می‌کند. */ ?>
<div class="desk-kpis desk-only">
    <a class="desk-kpi" href="<?= APP_BASE_PATH ?>/wallets.php">
        <span class="desk-kpi-label">مجموع حساب‌ها</span>
        <span class="desk-kpi-value"><span class="ltr-num<?= $__walletSum < 0 ? ' is-neg' : '' ?>"><?= $__m($__walletSum) ?></span> <small><?= h(APP_CURRENCY) ?></small></span>
        <span class="desk-kpi-sub"><?= toPersianDigits((string)count($deskWallets)) ?> حساب فعال</span>
    </a>
    <?php if ($deskNw !== null): ?>
    <a class="desk-kpi" href="<?= APP_BASE_PATH ?>/my-assets.php">
        <span class="desk-kpi-label">خالص دارایی</span>
        <span class="desk-kpi-value"><span class="ltr-num<?= $deskNw['total'] < 0 ? ' is-neg' : '' ?>"><?= $__m((int)$deskNw['total']) ?></span> <small><?= h(APP_CURRENCY) ?></small></span>
        <span class="desk-kpi-sub">حساب‌ها، دارایی، چک و طلب/بدهی</span>
    </a>
    <?php endif; ?>
    <?php if ($deskSafe !== null && $deskSafe['available'] !== null): ?>
    <a class="desk-kpi" href="<?= APP_BASE_PATH ?>/due.php?t=list">
        <span class="desk-kpi-label">پول قابل خرج <small>(۳۰ روز)</small></span>
        <span class="desk-kpi-value"><span class="ltr-num<?= (int)$deskSafe['available'] < 0 ? ' is-neg' : '' ?>"><?= $__m((int)$deskSafe['available']) ?></span> <small><?= h(APP_CURRENCY) ?></small></span>
        <span class="desk-kpi-sub">تعهدِ قطعی: <span class="ltr-num"><?= formatMoney((int)$deskSafe['commitments']) ?></span></span>
    </a>
    <?php endif; ?>
    <a class="desk-kpi<?= $deskDues['overdue_n'] > 0 ? ' is-warn' : '' ?>" href="<?= APP_BASE_PATH ?>/due.php?t=list">
        <span class="desk-kpi-label">سررسیدِ ۱۴ روزِ آینده</span>
        <span class="desk-kpi-value"><span class="ltr-num"><?= toPersianDigits((string)$deskDues['soon_n']) ?></span> <small>مورد</small></span>
        <span class="desk-kpi-sub">
            <?php if ($deskDues['overdue_n'] > 0): ?>
                <?= toPersianDigits((string)$deskDues['overdue_n']) ?> مورد گذشته<?= $deskDues['overdue_sum'] > 0 ? ' · <span class="ltr-num">' . formatMoney($deskDues['overdue_sum']) . '</span>' : '' ?>
            <?php elseif ($deskDues['soon_out'] > 0): ?>
                پرداخت: <span class="ltr-num"><?= formatMoney($deskDues['soon_out']) ?></span>
            <?php else: ?>
                پرداختِ نزدیکی نیست
            <?php endif; ?>
        </span>
    </a>
</div>
<div class="home-desk">
<div class="home-main">
<?php endif; ?>

<div class="home-carousel">
    <div class="home-slides" id="homeSlides">
        <div class="balance-ribbon">
            <div class="balance-label">مانده این ماه</div>
            <div class="balance-value"><span class="bv-num"><?= $monthNet < 0 ? '−' : '' ?><?= formatMoney(abs($monthNet)) ?></span><span class="bv-unit">تومان</span></div>
            <?php if ($hw('meter') && $meter['pct'] !== null): ?>
            <div class="month-meter<?= $meter['tone'] !== '' ? ' is-' . h($meter['tone']) : '' ?>">
                <div class="month-meter-track"><div class="month-meter-fill" style="width: <?= (int)$meter['fill'] ?>%"></div></div>
                <div class="month-meter-caption"><?= h($meter['caption']) ?></div>
            </div>
            <?php endif; ?>
            <?php if ($hw('split')): ?>
            <div class="balance-split">
                <div class="bs-in-row">
                    <div class="bs-label">دریافتی</div>
                    <div class="bs-value bs-in"><?= formatMoney($monthIncome) ?></div>
                </div>
                <div class="bs-out-row">
                    <div class="bs-label">پرداختی</div>
                    <div class="bs-value bs-out"><?= formatMoney($monthExpense) ?></div>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($hw('compare') && $meter['compare'] !== null): ?>
            <div class="month-compare is-<?= h($meter['compare']['dir']) ?>">
                <?php if ($meter['compare']['dir'] === 'up'): ?>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
                <?php elseif ($meter['compare']['dir'] === 'down'): ?>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12l7 7 7-7"/></svg>
                <?php else: ?>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M5 12h14"/></svg>
                <?php endif; ?>
                <span><?= h($meter['compare']['text']) ?></span>
            </div>
            <?php endif; ?>
        </div>

        <?php foreach ($pinned as $pw): ?>
            <?php
                $__bp   = bankPreset($pw['bank_code'] ?? null);
                $__bank = $pw['bank_name'] ?: ($__bp['name'] ?? '');
                $__bal  = ((int)$pw['balance'] < 0 ? '−' : '') . formatMoney(abs((int)$pw['balance']));
                $__kind = walletKindLabel($pw['kind'], $pw['kind_label'] ?? null);
                $__card = formatCardNumber($pw['card_number'] ?? '');
                $__logo = bankLogoUrl($pw['bank_code'] ?? null);
            ?>
            <?php /* ⚠ همان `data-*`هایی که ردیفِ `wallets.php` دارد، با همان
                     نام‌ها: `app.js` یک شنونده‌ی مشترک روی `.js-show-card`
                     دارد و اگر یکی از این کلیدها نامش فرق کند، نما
                     **بی‌صدا** خالی باز می‌شود. */ ?>
            <div class="bank-card home-card js-show-card" role="button" tabindex="0"
                 style="--bc1: <?= h($pw['color']) ?>; --bc2: <?= h(shadeColor($pw['color'])) ?>;"
                 data-id="<?= (int)$pw['id'] ?>"
                 data-name="<?= h($pw['name']) ?>"
                 data-bank="<?= h($__bank) ?>"
                 data-card="<?= h($__card) ?>"
                 data-account="<?= h(toPersianDigits($pw['account_number'] ?? '')) ?>"
                 data-iban="<?= h(formatIban($pw['iban'] ?? '')) ?>"
                 data-kind="<?= h($__kind) ?>"
                 data-balance="<?= h($__bal) ?>"
                 data-c1="<?= h($pw['color']) ?>"
                 data-c2="<?= h(shadeColor($pw['color'])) ?>"
                 data-logo="<?= h($__logo ?? '') ?>">
                <div class="bank-card-shine"></div>
                <?php /* ⚠ نگاشتِ فیلدها **دقیقاً** همانِ مودال است: بالا
                         نامِ بانک (و اگر نبود نامِ حساب)، پایین نامِ خودِ
                         حساب. برعکسش کارتِ خانه و کارتِ داخلِ نما را دو
                         چیزِ متفاوت نشان می‌داد، و روی حسابی که نامش با
                         بانکش یکی است هر دو خط یک کلمه می‌شدند. */ ?>
                <div class="bank-card-top">
                    <span class="bank-card-bank"><?php if ($__logo !== null): ?><span class="bank-logo"><img src="<?= h($__logo) ?>" alt="" width="22" height="22"></span><?php endif; ?><?= h($__bank ?: $pw['name']) ?></span>
                    <span class="bank-card-kind"><?= h($__kind) ?></span>
                </div>
                <div class="bank-card-chip" aria-hidden="true"></div>
                <div class="bank-card-number"><?= h($__card) ?></div>
                <div class="bank-card-bottom">
                    <div>
                        <span class="bank-card-label">صاحب حساب</span>
                        <span class="bank-card-owner"><?= h($pw['name']) ?></span>
                    </div>
                    <div class="bank-card-balance-wrap">
                        <span class="bank-card-label">موجودی</span>
                        <span class="bank-card-balance"><?= h($__bal) ?></span>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php /* ⛔ با اسلایدِ تمام‌عرض، «سرک کشیدنِ» کارتِ بعدی از بین می‌رود —
             و همان تنها چیزی بود که می‌گفت نوار کشیدنی است. نقطه‌ها
             جایگزینِ آن نشانه‌اند، پس اختیاری نیستند. با یک اسلاید
             اصلاً رندر نمی‌شوند: نشانگرِ تک‌نقطه‌ای چیزی نمی‌گوید. */ ?>
    <?php if ($pinned): ?>
    <div class="home-dots">
        <?php for ($i = 0; $i <= count($pinned); $i++): ?>
            <button type="button" class="home-dot<?= $i === 0 ? ' is-on' : '' ?>"
                    data-slide="<?= $i ?>"
                    aria-label="<?= $i === 0 ? 'مانده این ماه' : h($pinned[$i - 1]['name']) ?>"></button>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<?php /* ⛔ جای این کارت عمداً **بلافاصله زیرِ عددِ ماه** است.
         کاربر عدد را می‌بیند و بلافاصله می‌پرسد «خب یعنی چه؟» — جواب
         باید همان‌جا باشد، نه دو صفحه آن‌طرف‌تر. اگر جمله‌ای نبود، کارت
         اصلاً رندر نمی‌شود: کارتِ خالیِ «بینشی نیست» بدتر از نبودنش
         است. */ ?>
<?php if ($highlights): ?>
<div class="card insight-card">
    <?php foreach ($highlights as $h): ?>
        <a class="insight-row insight-<?= h($h['tone']) ?>"
           href="<?= APP_BASE_PATH ?>/<?= h($h['link'] ?? 'dashboard.php') ?>">
            <span class="insight-dot"></span>
            <span class="insight-text"><?= h($h['text']) ?></span>
            <svg class="insight-go" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M11 18l-6-6 6-6"/></svg>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header-row">
        <h2 class="card-title">آخرین تراکنش‌های من</h2>
        <a href="transactions.php" class="link-more">مشاهده همه ←</a>
    </div>

    <div class="table-wrapper">
        <?php if (empty($recentTransactions)): ?>
            <?php /* ⛔ متنِ قبلی می‌گفت «با دکمه + **پایین صفحه**» — و روی
                     دسکتاپ `.bottom-nav` با `display:none` کنار می‌رود، پس
                     تنها راهنماییِ کاربرِ خالی‌دفتر به دکمه‌ای اشاره می‌کرد
                     که وجود نداشت. راهِ درست این است که خودِ اینجا **در**
                     باشد: `.js-add-tx` تنها بندِ باز کردنِ شیتِ ثبت است و
                     روی هر عرضی کار می‌کند. */ ?>
            <p class="empty-row">هنوز تراکنشی ثبت نکرده‌اید.</p>
            <p class="empty-cta"><button type="button" class="btn btn-primary js-add-tx">ثبت اولین تراکنش</button></p>
        <?php else: ?>
            <?php /* ⛔ روی خانه **بی‌سرِ روز** است، عمداً: هشت ردیفِ آخر معمولاً
                     مالِ یکی دو روزند و خطِ «دیروز · چهارشنبه ۱ مهر» بالای
                     دو ردیف فقط جا می‌گرفت (خواسته‌ی مالکِ نصب). تاریخِ هر
                     ردیف با تپ باز می‌شود، و صفحه‌ی تراکنش‌ها — که فهرستِ
                     بلند دارد — همچنان گروه‌بندیِ روزانه را دارد. */ ?>
            <div class="tx-day-group tx-flat">
                <?php foreach ($recentTransactions as $__tx) { renderTransactionRow($__tx); } ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($desk): ?>
<?php /* ⛔ تقویم — زیرِ «آخرین تراکنش‌ها» در ستونِ اصلی (خواسته‌ی مالکِ نصب:
         «تقویم بهتر بیاد زیر تراکنش‌ها و حساب‌ها برگردن سر جای خودشون»).
         ستونِ اصلی کوتاه‌تر از ستونِ کناری بود و زیرش خالی می‌ماند؛ حالا
         همان جا پر می‌شود و کارتِ پهن مبلغِ هر روز را هم جا می‌دهد. */ ?>
<?php renderHomeCalendar($calJy, $calJm, true); ?>
</div><?php /* .home-main */ ?>

<?php /* ⛔ ستونِ کناری — فقط نمای دسکتاپ. در RTL ستونِ دومِ توری سمتِ چپ
         می‌نشیند، پس ستونِ اصلی (کارتِ ماه و تراکنش‌ها) همان‌جایی می‌ماند
         که چشمِ فارسی‌خوان اول می‌رود. */ ?>
<aside class="home-side desk-only">
    <div class="card desk-card">
        <div class="card-header-row">
            <h2 class="card-title">مانده‌ی حساب‌ها</h2>
            <a href="<?= APP_BASE_PATH ?>/wallets.php" class="link-more">مدیریت ←</a>
        </div>
        <?php if (!$deskWallets): ?>
            <p class="empty-row">هنوز حسابی ندارید.</p>
        <?php else: ?>
        <div class="desk-list">
            <?php foreach ($deskWallets as $__w):
                $__logo = bankLogoUrl($__w['bank_code'] ?? null);
                $__b    = (int)$__w['balance'];
            ?>
            <a class="desk-row" href="<?= APP_BASE_PATH ?>/transactions.php?wallet=<?= (int)$__w['id'] ?>">
                <span class="desk-row-mark" style="--wc: <?= h($__w['color']) ?>;"><?php if ($__logo !== null): ?><img src="<?= h($__logo) ?>" alt="" width="18" height="18"><?php endif; ?></span>
                <span class="desk-row-name"><?= h($__w['name']) ?><small><?= h(walletKindLabel($__w['kind'], $__w['kind_label'] ?? null)) ?></small></span>
                <span class="desk-row-amount ltr-num<?= $__b < 0 ? ' is-neg' : '' ?>"><?= $__m($__b) ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($deskNw !== null && $deskNw['rows']):
        $__pl = chartPalette('green', 'light');
        $__pd = chartPalette('green', 'dark');
        $__pn = count($__pl);
        $__top = array_slice($deskNw['rows'], 0, 6);
    ?>
    <?php /* ⚠ همان رنگ و ترتیبِ صفحه‌ی دارایی (`chartPalette('green')` به
             ترتیبِ رتبه)، تا قلمِ سوم اینجا و آنجا یک رنگ باشد. درصد فقط
             وقتی که هم جمع مثبت باشد هم خودِ قلم — همان قاعده‌ی آن صفحه. */ ?>
    <div class="card desk-card">
        <div class="card-header-row">
            <h2 class="card-title">ترکیب دارایی</h2>
            <a href="<?= APP_BASE_PATH ?>/my-assets.php" class="link-more">جزئیات ←</a>
        </div>
        <div class="desk-list">
            <?php foreach ($__top as $__i => $__r):
                $__v   = (int)$__r['value'];
                $__pct = ($deskNw['total'] > 0 && $__v > 0) ? round($__v / $deskNw['total'] * 100) : null;
            ?>
            <div class="desk-comp">
                <div class="desk-comp-head">
                    <span class="cat-dot" style="--dot-l:<?= h($__pl[$__i % $__pn]) ?>; --dot-d:<?= h($__pd[$__i % $__pn]) ?>;"></span>
                    <span class="desk-row-name"><?= h($__r['name']) ?></span>
                    <span class="desk-row-amount ltr-num<?= $__v < 0 ? ' is-neg' : '' ?>"><?= $__m($__v) ?></span>
                </div>
                <?php if ($__pct !== null): ?>
                <div class="cat-breakdown-bar-track"><div class="cat-breakdown-bar" style="width:<?= (int)min(100, $__pct) ?>%; --dot-l:<?= h($__pl[$__i % $__pn]) ?>; --dot-d:<?= h($__pd[$__i % $__pn]) ?>;"></div></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if (count($deskNw['rows']) > count($__top)): ?>
            <p class="hint">و <?= toPersianDigits((string)(count($deskNw['rows']) - count($__top))) ?> قلمِ دیگر در صفحه‌ی دارایی.</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card desk-card">
        <div class="card-header-row">
            <h2 class="card-title">سررسیدهای نزدیک</h2>
            <a href="<?= APP_BASE_PATH ?>/due.php?t=list" class="link-more">همه ←</a>
        </div>
        <?php if (!$deskDues['rows']): ?>
            <p class="empty-row">تا ۱۴ روزِ آینده سررسیدی ندارید.</p>
        <?php else: ?>
        <div class="desk-list">
            <?php foreach (array_slice($deskDues['rows'], 0, 7) as $__e):
                $__past = $__e['date'] < today();
            ?>
            <a class="desk-row" href="<?= APP_BASE_PATH ?>/<?= h($__e['url'] ?? 'due.php?t=list') ?>">
                <span class="desk-due-date<?= $__past ? ' is-past' : '' ?>"><?= h(toJalali($__e['date'])) ?></span>
                <span class="desk-row-name"><?= h($__e['title']) ?><small><?= h(eventKindLabel((string)$__e['kind'])) ?><?= $__past ? ' · گذشته' : '' ?></small></span>
                <span class="desk-row-amount ltr-num <?= $__e['direction'] === 'out' ? 'is-out' : 'is-in' ?>"><?= $__e['direction'] === 'out' ? '−' : '+' ?><?= formatMoney((int)$__e['amount']) ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php if (count($deskDues['rows']) > 7): ?>
            <p class="hint">و <?= toPersianDigits((string)(count($deskDues['rows']) - 7)) ?> مورد دیگر.</p>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</aside>
</div><?php /* .home-desk */ ?>

<?php /* ⛔ ادامه‌ی خانه = داشبورد، فقط روی دسکتاپ. سه کارتِ یادآوریِ داشبورد
         (طلب، چک، دوره‌ای) عمداً نیامدند: ستونِ کناری همین حالا «سررسیدهای
         نزدیک» را دارد. «گزارش با بازه‌ی دلخواه» هم نیامد — ابزار است، نه
         نما — و در `dashboard.php` می‌ماند. */ ?>
<section class="home-desk-more desk-only" aria-label="گزارش‌ها">
    <?php renderYearReportCard($deskYear, 'index.php'); ?>
    <div class="desk-more-grid">
        <div class="desk-more-main"><?php renderTrendCard($deskDaily); ?></div>
        <div class="desk-more-side">
            <?php renderBreakdownCta($monthIncome, $monthExpense); ?>
            <?php renderMonthComparisonCard($monthCmp, $deskInsights); ?>
        </div>
    </div>
</section>
<?php endif; ?>

<meta name="csrf-token" content="<?= Csrf::token() ?>">

<?php include __DIR__ . '/includes/edit_tx_modal.php'; ?>

<?php /* ⛔ معرفیِ اولیه فقط برای دفترِ **خالی** رندر می‌شود، و این شرط
         هیچ کوئریِ تازه‌ای نمی‌خواهد: `$recentTransactions` همین بالا
         خوانده شده. برای کاربرِ قدیمی اصلاً وارد HTML نمی‌شود — نه یک
         لایه‌ی پنهانِ بی‌دلیل در هر بارگذاری، همان استدلالِ
         `$pinned` پایین‌تر. دلیلِ تصمیم‌ها در خودِ فایل نوشته شده. */ ?>
<?php if (empty($recentTransactions)): ?>
<?php include __DIR__ . '/includes/intro_sheet.php'; ?>
<?php endif; ?>

<?php /* نمای کارت و تعدیل موجودی — همان دو مودالِ `wallets.php`، از یک
         فایلِ مشترک. فقط وقتی رندر می‌شوند که کارتی روی خانه باشد؛
         بدونِ آن دو لایه‌ی پنهان بی‌دلیل در HTML هر بارگذاری می‌ماندند. */ ?>
<?php if ($pinned): ?>
<?php include __DIR__ . '/includes/wallet_card_modals.php'; ?>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
