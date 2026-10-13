<?php
/**
 * داشبوردِ فروشگاه — «در ۵ ثانیه وضعیتِ مالیِ کسب‌وکار».
 *
 * از بالا به پایین، به همان ترتیبی که صاحبِ مغازه تصمیم می‌گیرد:
 * ۱. **کارِ سریع** — فروشِ سریع، فاکتور، دریافت، هزینه. کار پیش از عدد.
 * ۲. **شش شاخص** — موجودیِ نقد، طلب، بدهی، درآمد، هزینه و سودِ خالصِ ماه؛
 *    هر کدام با تغییر نسبت به **همین مدت در ماهِ قبل** و مینی‌نمودار.
 *    ⛔ جایی که مقایسه‌ی منصفانه ممکن نیست (طلب و بدهی تاریخچه ندارند)
 *       درصدی ساخته نمی‌شود — وضعیت نوشته می‌شود، نه عددِ ساختگی.
 * ۳. **نیاز به توجه** (کنار) — هر ردیف یک کار با دکمه‌اش.
 * ۴. **جریانِ نقد** (۷ روز تا ۱ سال)، **درآمد و هزینه** (هفتگی/ماهانه/سالانه)،
 *    **حساب‌ها** با تغییرِ امروز، و **سهمِ هزینه‌ها**.
 * ۵. **طلب و بدهی** با وضعیتِ سررسید، **آخرین تراکنش‌ها**.
 * ۶. **فروشگاه**: فروش و سودِ امروز، گوشی‌های موجود، پرفروش‌ها، راکدها،
 *    کم‌موجودی‌ها و بهترین مشتری‌ها.
 *
 * ⛔ هیچ عددی از حساب لندِ شخصی نیست (قاعده ۷۰) و هر عدد از همان تعریفی
 *    می‌آید که صفحه‌ی خودش دارد (`BizDash` بالای فایلش توضیح می‌دهد).
 * ⛔ هیچ نموداری جاوااسکریپت نمی‌خواهد (SVGِ سمتِ سرور)؛ بازه با لینک عوض
 *    می‌شود (`?cf=`، `?ie=`) و `store.js` فقط راهنمای روی نقطه را می‌دهد.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_docview.php';
require_once __DIR__ . '/../includes/biz_dash.php';

$userId   = Biz::ownerId();

// ⛔ همکاری که نه پول را می‌بیند نه سود را (فروشنده) داشبوردِ مالی نمی‌گیرد —
//    موجودیِ صندوق و سودِ ماه برای او بسته است؛ فقط فروشِ خودش و کمبودِ انبار.
if (!Biz::can('money') && !Biz::can('cost')) {
    require __DIR__ . '/../includes/biz_seller_home.php';
    return;
}

$settings = Biz::settings($userId);
$today    = today();
$mStart   = startOfJalaliMonth();
[$pmFrom, $pmTo] = BizDash::prevSamePeriod($mStart, $today);
$cf = (string)getParam('cf', '30');
if (!isset(BizDash::CF_RANGES[$cf])) { $cf = '30'; }
$ie = (string)getParam('ie', 'm');
if (!isset(BizDash::IE_GRAINS[$ie])) { $ie = 'm'; }

// ---------- داده (هر خط یک کوئری) ----------
$accounts = BizCash::list($userId, false, $today);   // ⛔ تا امروز — همان مرزِ `cashDaily()`
$cashSum  = BizCash::total($accounts);
$todayAcc = BizDash::todayByAccount($userId, $today);
$serFrom  = $ie === 'y' ? date('Y-m-d', strtotime($today . ' -4 years')) : date('Y-m-d', strtotime(min($pmFrom, $today) . ' -370 days'));
$cash     = BizDash::cashDaily($userId, $serFrom, $today);
$sales    = BizDash::salesDaily($userId, $serFrom, $today);
$saleM    = BizReports::sales($userId, $mStart, $today);
$saleT    = BizReports::sales($userId, $today, $today);
$book     = BizDash::partyBook($userId);   // ⛔ مانده‌ها یک بار، نه سه بار
$parties  = $book['summary'];
$flags    = BizDash::invoiceFlags($userId);
$cheqDue  = BizCheques::due($userId);
$stock    = BizDash::stock($userId);
$low      = $stock['low'] + $stock['out'] > 0 ? BizProducts::lowStock($userId, 5) : [];
$expM     = BizReports::expenses($userId, $mStart, $today, 5);
$expP     = BizReports::expenses($userId, $pmFrom, $pmTo, 20);
$debtors  = $book['debtors'];
$creditors = $book['creditors'];
$recent   = BizDash::recentPayments($userId, 8);
$hasSales = $saleM['docs'] > 0 || $sales !== [];
$top      = $hasSales ? BizReports::topProducts($userId, $mStart, $today, 5) : [];
$stale    = $stock['count'] > 0 ? BizDash::stale($userId, 30, 5) : [];
$topCust  = $hasSales ? BizDash::topCustomers($userId, $mStart, $today, 5) : [];

// ---------- شاخص‌ها ----------
$cashPick = fn($v) => $v ? $v['in'] - $v['out'] : 0;
$netMonth = BizDash::sum($cash, 'in', $mStart, $today) - BizDash::sum($cash, 'out', $mStart, $today);
$cashPrev = $cashSum - $netMonth;                              // موجودیِ نقدِ اولِ ماه
$cashRun  = [];                                                 // موجودیِ پایانِ هر روز، ۳۰ روزِ اخیر
$bal = $cashSum;
foreach (array_reverse(BizDash::fill($cash, date('Y-m-d', strtotime($today . ' -29 days')), $today, $cashPick), true) as $d => $net) {
    $cashRun[$d] = $bal; $bal -= $net;
}
$cashRun = array_reverse($cashRun, true);

$incCur  = $saleM['net'] + BizDash::sum($cash, 'inc', $mStart, $today);
$incPrev = BizDash::sum($sales, 'rev', $pmFrom, $pmTo) + BizDash::sum($cash, 'inc', $pmFrom, $pmTo);
$expCur  = BizDash::sum($cash, 'exp', $mStart, $today);
$expPrev = BizDash::sum($cash, 'exp', $pmFrom, $pmTo);
// ⛔ سودِ خالص فقط از `BizReports::profit()` — با کسریِ انبار و خریدِ بی‌انبار (`other`)
$netCur  = BizReports::profit($saleM['gross'], $saleM['other'], BizDash::sum($cash, 'inc', $mStart, $today), $expCur);
$netPrev = BizReports::profit(BizDash::sum($sales, 'rev', $pmFrom, $pmTo) - BizDash::sum($sales, 'cost', $pmFrom, $pmTo),
                              BizDash::sum($sales, 'other', $pmFrom, $pmTo), BizDash::sum($cash, 'inc', $pmFrom, $pmTo), $expPrev);
$mDays   = BizDash::fill($sales + [], $mStart, $today, fn($v) => 0);
$incSer  = [];
$expSer  = [];
$netSer  = [];
$runN = 0;
foreach ($mDays as $d => $_) {
    $s = $sales[$d] ?? ['rev' => 0, 'cost' => 0, 'other' => 0]; $c = $cash[$d] ?? ['inc' => 0, 'exp' => 0];
    $incSer[] = $s['rev'] + $c['inc'];
    $expSer[] = $c['exp'];
    $runN += BizReports::profit($s['rev'] - $s['cost'], $s['other'], $c['inc'], $c['exp']);
    $netSer[] = $runN;
}

/** @return string نشانِ تغییر؛ `$goodUp=false` یعنی افزایش بد است (هزینه). */
$trend = function (int $cur, int $prev, bool $goodUp = true): string {
    $c = BizDash::change($cur, $prev);
    if ($c['pct'] === null) { return $c['dir'] === 'flat' ? '' : '<span class="st-trend" title="ماهِ قبل در همین مدت صفر بود">تازه</span>'; }
    $good = $c['dir'] === 'flat' ? '' : ((($c['dir'] === 'up') === $goodUp) ? ' is-up' : ' is-down');
    $arrow = $c['dir'] === 'up' ? '▲' : ($c['dir'] === 'down' ? '▼' : '•');
    return '<span class="st-trend' . $good . '" dir="ltr" title="نسبت به همین مدت در ماهِ قبل">' . $arrow . ' '
        . toPersianDigits(str_replace('.', '٫', (string)abs($c['pct']))) . '٪</span>';
};
$dirOf = fn(int $cur, int $prev, bool $goodUp = true): string => ($c = BizDash::change($cur, $prev))['dir'] === 'up' ? ($goodUp ? 'up' : 'down') : ($c['dir'] === 'down' ? ($goodUp ? 'down' : 'up') : '');
$signed = fn(int $v): string => ($v < 0 ? '−' : '') . formatMoney(abs($v));

// ---------- نیاز به توجه ----------
$alerts = [];
foreach ($cheqDue as $c) {
    $cd = (int)$c['days'];
    $when = $cd < 0 ? toPersianDigits((string)abs($cd)) . ' روز گذشته' : ($cd === 0 ? 'امروز' : ($cd === 1 ? 'فردا' : toPersianDigits((string)$cd) . ' روزِ دیگر'));
    $alerts[] = [$cd < 0 ? 'err' : 'warn', ($c['kind'] === 'receipt' ? 'چکِ دریافتی' : 'چکِ پرداختی') . ' · سررسید ' . $when,
        trim((string)($c['party_name'] ?? '') . ' · ' . formatMoney((int)$c['amount']) . ' تومان', ' ·'),
        Biz::url('cheques.php' . ($cd < 0 ? '?f=overdue' : '')), $c['kind'] === 'receipt' ? 'وصول' : 'پرداخت'];
}
if ($flags['late'] > 0) {
    $alerts[] = ['err', toPersianDigits((string)$flags['late']) . ' فاکتورِ معوق (بیش از ' . toPersianDigits((string)BizDash::LATE_DAYS) . ' روز)',
        'مانده ' . formatMoney($flags['late_sum']) . ' تومان', Biz::url('parties.php?f=debtor'), 'پیگیری'];
}
if ($flags['partial'] > 0) {
    $alerts[] = ['warn', toPersianDigits((string)$flags['partial']) . ' فاکتورِ نیمه‌پرداخت', 'بخشی دریافت شده، بقیه مانده', Biz::url('sales.php?f=open'), 'دریافت'];
}
if ($flags['open'] > $flags['partial'] && $flags['open'] > 0) {
    $alerts[] = ['info', toPersianDigits((string)$flags['open']) . ' فاکتورِ فروشِ تسویه‌نشده', 'مجموع ' . formatMoney($flags['open_sum']) . ' تومان', Biz::url('sales.php?f=open'), 'مشاهده'];
}
if ($stock['low'] + $stock['out'] > 0) {
    $alerts[] = [$stock['out'] > 0 ? 'err' : 'warn', toPersianDigits((string)($stock['low'] + $stock['out'])) . ' کالای کم‌موجودی',
        $stock['out'] > 0 ? toPersianDigits((string)$stock['out']) . ' کالا تمام شده است' : 'زیرِ حداقلِ موجودی', Biz::url('invoice-edit.php?k=purchase'), 'خرید'];
}
if ($flags['drafts'] > 0) {
    $alerts[] = ['info', toPersianDigits((string)$flags['drafts']) . ' پیش‌نویسِ صادرنشده', 'تا صادر نشود در موجودی و حساب اثری ندارد', Biz::url('sales.php?f=draft'), 'تکمیل'];
}
foreach ($accounts as $a) {
    if ((int)$a['is_active'] === 1 && !BizCash::isCheque($a) && (int)$a['balance'] < 0) {
        $alerts[] = ['err', 'موجودیِ «' . $a['name'] . '» منفی است', $signed((int)$a['balance']) . ' تومان — ثبتی جا افتاده؟', Biz::url('payments.php?acc=' . (int)$a['id']), 'بررسی'];
    }
}

// ---------- نمودارِ جریانِ نقد ----------
$cfDays = (int)$cf;
$cfFrom = date('Y-m-d', strtotime($today . ' -' . ($cfDays - 1) . ' days'));
$cfGrain = $cfDays <= 30 ? 'd' : ($cfDays <= 180 ? 'w' : 'm');
$flowPts = [];
if ($cfGrain === 'd') {
    foreach (BizDash::fill($cash, $cfFrom, $today, fn($v) => 0) as $d => $_) {
        $v = $cash[$d] ?? ['in' => 0, 'out' => 0];
        [$gy, $gm, $gd] = array_map('intval', explode('-', $d));
        [, $jm, $jd] = gregorianToJalali($gy, $gm, $gd);
        $flowPts[] = ['label' => toPersianDigits($jd . '/' . $jm), 'in' => $v['in'], 'out' => $v['out'],
            'tip' => toJalali($d) . '|ورود ' . formatMoney($v['in']) . '|خروج ' . formatMoney($v['out']) . '|خالص ' . $signed($v['in'] - $v['out'])];
    }
} else {
    foreach (BizDash::bucket($cash, $cfFrom, $today, $cfGrain) as $b) {
        $vi = (int)($b['v']['in'] ?? 0); $vo = (int)($b['v']['out'] ?? 0);
        $flowPts[] = ['label' => $b['label'], 'in' => $vi, 'out' => $vo,
            'tip' => ($cfGrain === 'w' ? 'هفته‌ی ' : '') . $b['label'] . '|ورود ' . formatMoney($vi) . '|خروج ' . formatMoney($vo) . '|خالص ' . $signed($vi - $vo)];
    }
}
$cfIn = array_sum(array_column($flowPts, 'in'));
$cfOut = array_sum(array_column($flowPts, 'out'));

// ---------- نمودارِ درآمد و هزینه ----------
$ieFrom = $ie === 'w' ? date('Y-m-d', strtotime($today . ' -83 days')) : ($ie === 'y' ? $serFrom : date('Y-m-d', strtotime($today . ' -364 days')));
$ieSer = [];
foreach (BizDash::fill($cash, $ieFrom, $today, fn($v) => 0) as $d => $_) {
    $s = $sales[$d] ?? ['rev' => 0, 'cost' => 0]; $c = $cash[$d] ?? ['inc' => 0, 'exp' => 0];
    $ieSer[$d] = ['a' => $s['rev'] + $c['inc'], 'b' => $c['exp'] + $s['cost']];
}
$iePts = [];
foreach (BizDash::bucket($ieSer, $ieFrom, $today, $ie) as $b) {
    $va = (int)($b['v']['a'] ?? 0); $vb = (int)($b['v']['b'] ?? 0);
    $iePts[] = ['label' => $b['label'], 'a' => $va, 'b' => $vb,
        'tip' => ($ie === 'w' ? 'هفته‌ی ' : '') . $b['label'] . '|درآمد ' . formatMoney($va) . '|هزینه و بهای کالا ' . formatMoney($vb) . '|سود ' . $signed($va - $vb)];
}
$ieHas = array_sum(array_column($iePts, 'a')) + array_sum(array_column($iePts, 'b')) > 0;

// ---------- سهمِ هزینه‌ها ----------
$expTotal = $expCur;
$expPrevMap = [];
foreach ($expP as $e) { $expPrevMap[(string)$e['title']] = (int)$e['s']; }
$slices = array_map(fn($e) => (int)$e['s'], $expM);
if ($expTotal > array_sum($slices)) { $slices[] = $expTotal - array_sum($slices); }

$qs = function (array $set): string {
    $p = array_merge(['cf' => (string)getParam('cf', ''), 'ie' => (string)getParam('ie', '')], $set);
    $p = array_filter($p, fn($v) => $v !== '');
    return Biz::url('index.php' . ($p ? '?' . http_build_query($p) : ''));
};
$kindIco = ['cash' => '<rect x="3" y="7" width="18" height="11" rx="2"/><circle cx="12" cy="12.5" r="2.5"/>', 'bank' => '<path d="M3 10l9-6 9 6"/><path d="M5 10v8M9.5 10v8M14.5 10v8M19 10v8"/><path d="M3 20h18"/>',
            'pos' => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M8 7h8M8 11h2M12 11h2M8 15h2M12 15h2"/>', 'cheque_in' => '<rect x="2.5" y="6" width="19" height="12" rx="2"/><path d="M6 14h5"/>',
            'cheque_out' => '<rect x="2.5" y="6" width="19" height="12" rx="2"/><path d="M6 14h5"/>'];
$pageTitle = 'داشبورد';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-dash-head">
    <div>
        <p class="st-eyebrow"><?= h(jalaliLongDate($today)) ?></p>
        <h1 class="st-h1"><?= h($settings['shop_name'] !== '' ? $settings['shop_name'] : 'داشبوردِ فروشگاه') ?></h1>
    </div>
    <nav class="st-actions" aria-label="کارِ سریع" style="margin:0">
        <a class="st-action is-primary" href="<?= h(Biz::url('quick-sale.php')) ?>"><svg class="st-action-ico" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="M13 2L4 14h7l-1 8 9-12h-7z"/></svg>فروشِ سریع</a>
        <a class="st-action" href="<?= h(Biz::url('invoice-edit.php?k=sale')) ?>">فاکتور فروش</a>
        <a class="st-action" href="<?= h(Biz::url('invoice-edit.php?k=purchase')) ?>">فاکتور خرید</a>
        <a class="st-action" href="<?= h(Biz::url('payment.php?k=receipt')) ?>">دریافت</a>
        <a class="st-action" href="<?= h(Biz::url('payment.php?k=expense')) ?>">هزینه</a>
    </nav>
</div>

<?php if ($settings['shop_name'] === ''): ?>
<section class="st-card st-card-invite">
    <h2 class="st-h2">سربرگِ فروشگاه را کامل کنید</h2>
    <p class="st-muted">نام، تلفن و آدرسِ فروشگاه بالای فاکتورهای چاپی می‌نشیند.</p>
    <a class="st-btn" href="<?= h(Biz::url('settings.php')) ?>">تنظیمات فروشگاه</a>
</section>
<?php endif; ?>

<div class="st-kpis st-kpis-6" data-kpis>
    <a class="st-kpi-card" href="<?= h(Biz::url('accounts.php')) ?>">
        <span class="st-kpi-label">موجودیِ نقد و بانک</span>
        <span class="st-kpi-value st-num<?= $cashSum < 0 ? ' is-neg' : '' ?>"><?= $signed($cashSum) ?></span>
        <span class="st-kpi-foot"><?= $trend($cashSum, $cashPrev) ?><?= BizChart::spark($cashRun, $dirOf($cashSum, $cashPrev)) ?></span>
        <span class="st-kpi-sub">نسبت به اولِ ماه</span>
    </a>
    <a class="st-kpi-card" href="<?= h(Biz::url('parties.php?f=debtor')) ?>">
        <span class="st-kpi-label">طلب از مشتریان</span>
        <span class="st-kpi-value st-num"><?= formatMoney($parties['receivable']) ?></span>
        <span class="st-kpi-sub"><?= toPersianDigits((string)$parties['debtors']) ?> بدهکار<?= $flags['late'] > 0 ? ' · <b class="st-late">' . toPersianDigits((string)$flags['late']) . ' معوق</b>' : '' ?></span>
    </a>
    <a class="st-kpi-card" href="<?= h(Biz::url('parties.php?f=creditor')) ?>">
        <span class="st-kpi-label">بدهی به تأمین‌کنندگان</span>
        <span class="st-kpi-value st-num"><?= formatMoney($parties['payable']) ?></span>
        <span class="st-kpi-sub"><?= toPersianDigits((string)$parties['creditors']) ?> طلبکار</span>
    </a>
    <a class="st-kpi-card" href="<?= h(Biz::url('reports.php?p=month')) ?>">
        <span class="st-kpi-label">درآمدِ این ماه</span>
        <span class="st-kpi-value st-num"><?= formatMoney($incCur) ?></span>
        <span class="st-kpi-foot"><?= $trend($incCur, $incPrev) ?><?= BizChart::spark($incSer, $dirOf($incCur, $incPrev)) ?></span>
        <span class="st-kpi-sub">فروشِ خالص و درآمدِ متفرقه</span>
    </a>
    <a class="st-kpi-card" href="<?= h(Biz::url('payments.php?f=expense')) ?>">
        <span class="st-kpi-label">هزینه‌های این ماه</span>
        <span class="st-kpi-value st-num"><?= formatMoney($expCur) ?></span>
        <span class="st-kpi-foot"><?= $trend($expCur, $expPrev, false) ?><?= BizChart::spark($expSer, $dirOf($expCur, $expPrev, false)) ?></span>
        <span class="st-kpi-sub">اجاره، قبض، حقوق و …</span>
    </a>
    <a class="st-kpi-card<?= $netCur < 0 ? ' is-warn' : '' ?>" href="<?= h(Biz::url('print.php?doc=sales&p=month')) ?>">
        <span class="st-kpi-label">سودِ خالصِ این ماه</span>
        <span class="st-kpi-value st-num<?= $netCur < 0 ? ' is-neg' : '' ?>"><?= $signed($netCur) ?></span>
        <span class="st-kpi-foot"><?= $trend($netCur, $netPrev) ?><?= BizChart::spark($netSer, $dirOf($netCur, $netPrev)) ?></span>
        <span class="st-kpi-sub">سودِ ناخالص + درآمد − هزینه</span>
    </a>
</div>
<p class="st-muted st-unit-note">همه‌ی مبلغ‌ها به تومان. مقایسه با همین مدت در ماهِ قبل.</p>

<div class="st-dash-grid">
    <div class="st-stack st-dash-main">
        <section class="st-card">
            <div class="st-card-head">
                <h2 class="st-h2">جریانِ نقدینگی</h2>
                <nav class="st-range" aria-label="بازه">
                    <?php foreach (BizDash::CF_RANGES as $k => $l): ?>
                    <a href="<?= h($qs(['cf' => $k])) ?>#cf" class="<?= $cf === (string)$k ? 'is-active' : '' ?>"><?= h($l) ?></a>
                    <?php endforeach; ?>
                </nav>
            </div>
            <div class="st-chart-sum" id="cf">
                <div><span>ورود</span><b class="st-num is-pos"><?= formatMoney($cfIn) ?></b></div>
                <div><span>خروج</span><b class="st-num"><?= formatMoney($cfOut) ?></b></div>
                <div><span>خالص</span><b class="st-num<?= $cfIn - $cfOut < 0 ? ' is-neg' : '' ?>"><?= $signed($cfIn - $cfOut) ?></b></div>
            </div>
            <?php if ($cfIn + $cfOut === 0): ?>
                <p class="st-empty">در این بازه پولی وارد یا خارجِ صندوق‌ها نشده است.</p>
            <?php else: ?>
            <ul class="st-legend"><li class="is-in"><i></i>ورود</li><li class="is-out"><i></i>خروج</li><li class="is-net"><i></i>خالص</li></ul>
            <div class="st-chart"><?= BizChart::flow($flowPts) ?></div>
            <?php endif; ?>
        </section>

        <section class="st-card">
            <div class="st-card-head">
                <h2 class="st-h2">درآمد و هزینه</h2>
                <nav class="st-range" aria-label="دانه‌بندی">
                    <?php foreach (BizDash::IE_GRAINS as $k => $l): ?>
                    <a href="<?= h($qs(['ie' => $k])) ?>#ie" class="<?= $ie === $k ? 'is-active' : '' ?>"><?= h($l) ?></a>
                    <?php endforeach; ?>
                </nav>
            </div>
            <?php if (!$ieHas): ?>
                <p class="st-empty" id="ie">هنوز فروش یا هزینه‌ای ثبت نشده است.</p>
            <?php else: ?>
            <ul class="st-legend" id="ie"><li class="is-bin"><i></i>درآمد</li><li class="is-bout"><i></i>هزینه و بهای کالای فروخته</li></ul>
            <div class="st-chart"><?= BizChart::bars($iePts) ?></div>
            <?php endif; ?>
        </section>

        <div class="st-dash-pair">
            <section class="st-card">
                <div class="st-card-head"><h2 class="st-h2">طلب از مشتریان</h2><a href="<?= h(Biz::url('parties.php?f=debtor')) ?>">همه</a></div>
                <?php if (!$debtors): ?><p class="st-empty">هیچ مشتری‌ای بدهکار نیست.</p><?php else: ?>
                <ul class="st-mini-list">
                    <?php foreach ($debtors as $p): [$ak, $al] = BizDash::ageState($p['oldest']); ?>
                    <li class="st-mini-row">
                        <a href="<?= h(Biz::url('party.php?id=' . (int)$p['id'])) ?>"><?= h($p['name']) ?><small><?= $p['oldest'] ? 'از ' . h(toJalali((string)$p['oldest'])) : 'مانده‌ی اول دوره' ?></small></a>
                        <span class="st-mini-end"><b class="st-num"><?= formatMoney((int)$p['balance']) ?></b><span class="st-badge <?= ['ok' => 'is-ok', 'near' => 'is-warn', 'due' => 'is-warn', 'late' => 'is-out'][$ak] ?>"><?= h($al) ?></span></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </section>
            <section class="st-card">
                <div class="st-card-head"><h2 class="st-h2">بدهی به تأمین‌کنندگان</h2><a href="<?= h(Biz::url('parties.php?f=creditor')) ?>">همه</a></div>
                <?php if (!$creditors): ?><p class="st-empty">به هیچ تأمین‌کننده‌ای بدهکار نیستید.</p><?php else: ?>
                <ul class="st-mini-list">
                    <?php foreach ($creditors as $p): [$ak, $al] = BizDash::ageState($p['oldest']); ?>
                    <li class="st-mini-row">
                        <a href="<?= h(Biz::url('party.php?id=' . (int)$p['id'])) ?>"><?= h($p['name']) ?><small><?= $p['oldest'] ? 'از ' . h(toJalali((string)$p['oldest'])) : 'مانده‌ی اول دوره' ?></small></a>
                        <span class="st-mini-end"><b class="st-num"><?= formatMoney(abs((int)$p['balance'])) ?></b><span class="st-badge <?= ['ok' => 'is-ok', 'near' => 'is-warn', 'due' => 'is-warn', 'late' => 'is-out'][$ak] ?>"><?= h($al) ?></span></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </section>
        </div>

        <section class="st-card" style="padding:0">
            <div class="st-card-head" style="padding:18px 20px 0"><h2 class="st-h2">آخرین تراکنش‌ها</h2><a href="<?= h(Biz::url('payments.php')) ?>">همه‌ی دریافت و پرداخت‌ها</a></div>
            <?php if (!$recent): ?>
                <p class="st-empty" style="padding:0 20px 18px">هنوز دریافت یا پرداختی ثبت نشده است.</p>
            <?php else: ?>
            <div class="st-table-wrap st-flat" style="border:0;margin:0;border-radius:0 0 var(--st-radius) var(--st-radius)">
                <table class="st-table st-table-compact">
                    <thead><tr><th>شرح</th><th class="st-hide-sm">تاریخ</th><th class="st-hide-sm">حساب</th><th>نوع</th><th class="st-th-num">مبلغ</th></tr></thead>
                    <tbody>
                    <?php foreach ($recent as $r):
                        $sign = in_array($r['kind'], BizPay::IN_KINDS, true) ? 1 : (in_array($r['kind'], BizPay::OUT_KINDS, true) ? -1 : 0);
                        $void = $r['status'] !== 'ok'; ?>
                    <tr class="<?= $void ? 'is-inactive' : '' ?>">
                        <td><a class="st-row-link" href="<?= h(Biz::url('payment.php?id=' . (int)$r['id'])) ?>"><?= h(BizPay::label($r) !== '' ? BizPay::label($r) : BizPay::KINDS[$r['kind']]) ?></a>
                            <?php if (($r['method'] ?? '') === 'cheque'): ?><span class="st-sku">چک<?= (string)($r['cheque_no'] ?? '') !== '' ? ' ' . h(toPersianDigits((string)$r['cheque_no'])) : '' ?></span><?php endif; ?></td>
                        <td class="st-hide-sm st-muted-i"><?= h(toJalali((string)$r['pay_date'])) ?></td>
                        <td class="st-hide-sm st-muted-i"><?= h((string)($r['account_name'] ?? '')) ?></td>
                        <td><span class="st-pill is-k-<?= h($r['kind']) ?>"><?= h(BizPay::KINDS[$r['kind']] ?? '') ?></span><?= $void ? ' <span class="st-pill is-void">باطل</span>' : '' ?></td>
                        <td class="st-td-num"><span class="st-num<?= $sign > 0 && !$void ? ' is-pos' : '' ?>"><?= ($sign < 0 ? '−' : ($sign > 0 ? '+' : '')) . formatMoney((int)$r['amount']) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>
    </div>

    <aside class="st-stack st-dash-side" aria-label="خلاصه">
        <section class="st-card">
            <div class="st-card-head"><h2 class="st-h2">نیاز به توجهِ شما</h2><?php if ($alerts): ?><span class="st-badge"><?= toPersianDigits((string)count($alerts)) ?></span><?php endif; ?></div>
            <?php if (!$alerts): ?>
                <p class="st-empty">چیزی منتظرِ شما نیست — همه‌چیز مرتب است.</p>
            <?php else: ?>
            <ul class="st-alerts">
                <?php foreach (array_slice($alerts, 0, 7) as [$lvl, $title, $sub, $url, $cta]): ?>
                <li class="st-alert<?= $lvl === 'err' ? ' is-err' : ($lvl === 'info' ? ' is-info' : '') ?>">
                    <i aria-hidden="true"></i>
                    <span class="st-alert-text"><b><?= h($title) ?></b><span><?= h($sub) ?></span></span>
                    <a class="st-btn st-btn-ghost st-btn-sm" href="<?= h($url) ?>"><?= h($cta) ?></a>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </section>

        <section class="st-card">
            <div class="st-card-head"><h2 class="st-h2">حساب‌ها</h2><a href="<?= h(Biz::url('accounts.php')) ?>">مدیریت</a></div>
            <ul class="st-acc-rows">
                <?php foreach ($accounts as $a): if ((int)$a['is_active'] !== 1) { continue; }
                    $isC = BizCash::isCheque($a); $chg = $todayAcc[(int)$a['id']] ?? 0; ?>
                <li class="st-acc-row">
                    <span class="st-acc-ico" aria-hidden="true"><svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $kindIco[$a['kind']] ?? $kindIco['cash'] ?></svg></span>
                    <span class="st-acc-main">
                        <b><?= h($a['name']) ?></b>
                        <span><?= h(BizCash::KINDS[$a['kind']] ?? '') ?><?= $chg !== 0 ? ' · امروز <span class="st-num ' . ($chg > 0 ? 'is-pos' : 'is-neg') . '">' . ($chg > 0 ? '+' : '−') . formatMoney(abs($chg)) . '</span>' : '' ?></span>
                        <?php if (!$isC): ?>
                        <span class="st-acc-acts">
                            <a href="<?= h(Biz::url('payment.php?k=income&acc=' . (int)$a['id'])) ?>">افزایش</a>
                            <a href="<?= h(Biz::url('payment.php?k=transfer&acc=' . (int)$a['id'])) ?>">انتقال</a>
                            <a href="<?= h(Biz::url('payments.php?acc=' . (int)$a['id'])) ?>">جزئیات</a>
                        </span>
                        <?php endif; ?>
                    </span>
                    <span class="st-acc-side"><b class="st-num<?= (int)$a['balance'] < 0 ? ' is-neg' : '' ?>"><?= $signed((int)$a['balance']) ?></b><?php if ($isC): ?><span class="st-muted-i">هنوز نقد نیست</span><?php endif; ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section class="st-card">
            <div class="st-card-head"><h2 class="st-h2">سهمِ هزینه‌های این ماه</h2><a href="<?= h(Biz::url('payments.php?f=expense')) ?>">همه</a></div>
            <?php if ($expTotal === 0): ?>
                <p class="st-empty">این ماه هزینه‌ای ثبت نشده است.</p>
            <?php else: ?>
            <div class="st-donut-wrap">
                <?= BizChart::donut($slices) ?>
                <ul class="st-donut-list">
                    <?php foreach ($expM as $i => $e):
                        $prev = $expPrevMap[(string)$e['title']] ?? 0; $ch = BizDash::change((int)$e['s'], $prev); ?>
                    <li><i class="st-c<?= $i + 1 ?>"></i><span title="<?= h((string)$e['title']) ?>"><?= h((string)$e['title']) ?> · <?= toPersianDigits((string)round((int)$e['s'] / $expTotal * 100)) ?>٪</span>
                        <b class="st-num"><?= formatMoney((int)$e['s']) ?><?php if ($ch['pct'] !== null && $ch['dir'] !== 'flat'): ?> <span class="st-trend <?= $ch['dir'] === 'up' ? 'is-down' : 'is-up' ?>" dir="ltr"><?= $ch['dir'] === 'up' ? '▲' : '▼' ?> <?= toPersianDigits((string)round(abs($ch['pct']))) ?>٪</span><?php endif; ?></b></li>
                    <?php endforeach; ?>
                    <?php if (count($slices) > count($expM)): ?>
                    <li><i class="st-c<?= count($expM) + 1 ?>"></i><span>سایر</span><b class="st-num"><?= formatMoney(end($slices)) ?></b></li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php endif; ?>
        </section>
    </aside>
</div>

<h2 class="st-h2 st-section-title" style="margin:26px 0 12px;font-size:17px">فروشگاه</h2>
<div class="st-kpis st-kpis-6">
    <a class="st-kpi-card" href="<?= h(Biz::url('reports.php?p=today')) ?>">
        <span class="st-kpi-label">فروشِ امروز</span>
        <span class="st-kpi-value st-num"><?= formatMoney($saleT['net']) ?></span>
        <span class="st-kpi-sub"><?= toPersianDigits((string)$saleT['docs']) ?> فاکتور</span>
    </a>
    <a class="st-kpi-card" href="<?= h(Biz::url('reports.php?p=today')) ?>">
        <span class="st-kpi-label">سودِ ناخالصِ امروز</span>
        <span class="st-kpi-value st-num<?= $saleT['gross'] < 0 ? ' is-neg' : '' ?>"><?= $signed($saleT['gross']) ?></span>
        <span class="st-kpi-sub"><?= $saleT['margin'] !== null ? 'حاشیه ' . toPersianDigits(str_replace('.', '٫', (string)$saleT['margin'])) . '٪' : 'هنوز فروشی نیست' ?></span>
    </a>
    <a class="st-kpi-card" href="<?= h(Biz::url('reports.php?p=month')) ?>">
        <span class="st-kpi-label">فروشِ این ماه</span>
        <span class="st-kpi-value st-num"><?= formatMoney($saleM['net']) ?></span>
        <span class="st-kpi-sub"><?= toPersianDigits((string)$saleM['docs']) ?> فاکتور · میانگین <?= formatMoney($saleM['avg']) ?></span>
    </a>
    <a class="st-kpi-card" href="<?= h(Biz::url('reports.php?p=month')) ?>">
        <span class="st-kpi-label">سودِ ناخالصِ ماه</span>
        <span class="st-kpi-value st-num<?= $saleM['gross'] < 0 ? ' is-neg' : '' ?>"><?= $signed($saleM['gross']) ?></span>
        <span class="st-kpi-sub"><?= $saleM['margin'] !== null ? 'حاشیه ' . toPersianDigits(str_replace('.', '٫', (string)$saleM['margin'])) . '٪' : '—' ?></span>
    </a>
    <a class="st-kpi-card" href="<?= h(Biz::url('products.php')) ?>">
        <span class="st-kpi-label"><?= $stock['phones'] > 0 ? 'گوشیِ موجود' : 'کالای فعال' ?></span>
        <span class="st-kpi-value st-num"><?= toPersianDigits((string)($stock['phones'] > 0 ? $stock['phones'] : $stock['count'])) ?></span>
        <span class="st-kpi-sub"><?= $stock['phones'] > 0 ? 'به ارزشِ ' . formatMoney($stock['phone_value']) : toPersianDigits((string)$stock['count']) . ' قلم' ?></span>
    </a>
    <a class="st-kpi-card" href="<?= h(Biz::url('print.php?doc=stock')) ?>">
        <span class="st-kpi-label">ارزشِ انبار</span>
        <span class="st-kpi-value st-num"><?= formatMoney($stock['value']) ?></span>
        <span class="st-kpi-sub">به بهای تمام‌شده</span>
    </a>
</div>

<div class="st-dash-pair" style="margin-bottom:16px">
    <section class="st-card">
        <div class="st-card-head"><h2 class="st-h2">پرفروش‌های این ماه</h2><a href="<?= h(Biz::url('print.php?doc=by_product&k=sale&p=month')) ?>">گزارشِ کامل</a></div>
        <?php if (!$top): ?><p class="st-empty">این ماه هنوز فروشی صادر نشده است.</p><?php else: ?>
        <?php foreach ($top as $i => $t): ?>
        <div class="st-rank">
            <span><?= toPersianDigits((string)($i + 1)) ?></span>
            <span class="st-rank-name"><b><?= h((string)$t['name']) ?></b><small><?= h(BizView::qty($t['qty'], (string)$t['unit'])) ?> · سود <span class="st-num<?= (int)$t['profit'] < 0 ? ' is-neg' : '' ?>"><?= $signed((int)$t['profit']) ?></span></small></span>
            <b class="st-num"><?= formatMoney((int)$t['rev']) ?></b>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </section>
    <section class="st-card">
        <div class="st-card-head"><h2 class="st-h2">موجودی</h2><a href="<?= h(Biz::url('products.php?f=low')) ?>">کم‌موجودی‌ها</a></div>
        <?php if (!$low && !$stale): ?><p class="st-empty">نه کالای کم‌موجودی داریم نه کالای راکد.</p><?php endif; ?>
        <?php if ($low): ?>
        <p class="st-subhead">کم‌موجودی</p>
        <ul class="st-mini-list">
            <?php foreach ($low as $p): ?>
            <li class="st-mini-row"><a href="<?= h(Biz::url('product.php?id=' . (int)$p['id'])) ?>"><?= h($p['name']) ?></a>
                <span class="st-mini-end"><span class="st-num"><?= h(BizView::qty($p['stock_qty'], (string)$p['unit'])) ?></span><span class="st-badge <?= (float)$p['stock_qty'] <= 0 ? 'is-out' : 'is-low' ?>"><?= (float)$p['stock_qty'] <= 0 ? 'تمام شد' : 'کم' ?></span></span></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <?php if ($stale): ?>
        <p class="st-subhead">راکد (بی‌فروش در ۳۰ روز)</p>
        <ul class="st-mini-list">
            <?php foreach ($stale as $p): ?>
            <li class="st-mini-row"><a href="<?= h(Biz::url('product.php?id=' . (int)$p['id'])) ?>"><?= h($p['name']) ?><small><?= h(BizView::qty($p['stock_qty'], (string)$p['unit'])) ?></small></a>
                <span class="st-mini-end"><b class="st-num"><?= formatMoney((int)$p['val']) ?></b><span class="st-muted-i">سرمایه‌ی خوابیده</span></span></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>
</div>

<section class="st-card">
    <div class="st-card-head"><h2 class="st-h2">بهترین مشتری‌های این ماه</h2><a href="<?= h(Biz::url('print.php?doc=by_party&p=month')) ?>">گردشِ اشخاص</a></div>
    <?php if (!$topCust): ?><p class="st-empty">این ماه هنوز فروشی به مشتریِ ثبت‌شده نداشته‌اید.</p><?php else: ?>
    <?php foreach ($topCust as $i => $c): ?>
    <div class="st-rank">
        <span><?= toPersianDigits((string)($i + 1)) ?></span>
        <span class="st-rank-name"><b><a class="st-row-link" href="<?= h(Biz::url('party.php?id=' . (int)$c['id'])) ?>"><?= h((string)$c['name']) ?></a></b><small><?= toPersianDigits((string)$c['docs']) ?> فاکتور</small></span>
        <b class="st-num"><?= formatMoney((int)$c['rev']) ?></b>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
