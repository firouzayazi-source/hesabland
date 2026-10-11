<?php
/**
 * دفاتر و مالیات — نگاهِ حسابدار به همان اسنادِ فروشگاه (`includes/biz_acc.php`).
 *
 * زبانه‌ها (`?t=`): ترازنامه، ترازِ آزمایشی، روزنامه، مالیات بر ارزش افزوده،
 * سالِ مالی، سامانه‌ی مودیان، سرفصل‌های هزینه و درآمد.
 *
 * ⛔ دفتر **مشتق** است (`BizLedger`): هیچ عددی اینجا نوشته نمی‌شود جز بستن و
 *    بازگشاییِ سال و سرفصل‌ها. هر عدد از اسنادِ صادرشده ساخته می‌شود، پس
 *    هرگز از صفحه‌های دیگرِ فروشگاه عقب نمی‌افتد؛ مغایرت‌گیری (`reconcile()`)
 *    زیرِ ترازنامه همین را هر بار می‌سنجد.
 * ⛔ خروجی‌ها (CSVِ روزنامه، JSONِ مودیان) فقط GET و فقط از دفترِ همین فروشگاه.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_docview.php';
require_once __DIR__ . '/../includes/biz_acc.php';
require_once __DIR__ . '/../includes/biz_dash.php';

$userId = Biz::ownerId();
$TABS = ['balance' => 'ترازنامه', 'trial' => 'ترازِ آزمایشی', 'journal' => 'روزنامه', 'vat' => 'مالیات بر ارزش افزوده',
         'year' => 'سالِ مالی', 'moadian' => 'سامانه‌ی مودیان', 'cats' => 'سرفصل‌ها'];
$t = (string)getParam('t');
$t = isset($TABS[$t]) ? $t : 'balance';
$self = Biz::url('accounting.php?t=' . $t);
$ready = Biz::accReady();

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    switch (postParam('action')) {
        case 'year_close':  $r = BizYear::close($userId, (int)postParam('jy')); break;
        case 'year_reopen': $r = BizYear::reopen($userId, (int)postParam('jy'), postParam('confirm') === '1'); break;
        case 'cat_save':    $r = BizExpCats::save($userId, (string)postParam('kind'), (string)postParam('name'), (int)postParam('id')); break;
        case 'cat_toggle':  $r = BizExpCats::setActive($userId, (int)postParam('id'), postParam('on') === '1'); break;
        default:            $r = ['ok' => false, 'message' => 'درخواست نامعتبر است.'];
    }
    redirectWithMessage($self, $r['ok'] ? 'success' : 'error', $r['message']);
}

// بازه (روزنامه، تراز، مودیان) — همان `BizReports::PERIODS`ِ صفحه‌ی گزارش
$period = (string)getParam('p');
$period = isset(BizReports::PERIODS[$period]) ? $period : ($t === 'trial' ? 'all' : 'month');
[$rFrom, $rTo] = BizReports::range($period, BizDocView::gDate((string)getParam('from')), BizDocView::gDate((string)getParam('to')));
$keep = fn(array $extra = []): string => Biz::url('accounting.php?' . http_build_query(['t' => $t, 'p' => $period] + ($period === 'custom'
            ? ['from' => BizDocView::jDate($rFrom), 'to' => BizDocView::jDate($rTo)] : []) + $extra));

// ---------- خروجی‌ها ----------
if ($ready && $t === 'journal' && getParam('csv') === '1') {
    $csv = BizLedger::csv(BizLedger::entries($userId, $rTo, $period === 'all' ? '' : $rFrom));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="journal-' . $rFrom . '-' . $rTo . '.csv"');
    header('Cache-Control: no-store');
    echo $csv;
    exit;
}
if ($ready && $t === 'moadian' && getParam('json') !== '') {
    $ids = getParam('json') === 'all' ? array_map(fn($r) => (int)$r['id'], BizMoadian::list($userId, $rFrom, $rTo)) : [(int)getParam('json')];
    $out = [];
    foreach ($ids as $iid) {
        $p = BizMoadian::payload($userId, $iid);
        if ($p['ok']) { $out[] = $p['invoice']; }
    }
    if (!$out) { redirectWithMessage($self, 'error', 'صورتحسابی برای خروجی نبود.'); }
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="moadian-' . (count($ids) === 1 ? $ids[0] : $rFrom . '-' . $rTo) . '.json"');
    header('Cache-Control: no-store');
    echo json_encode(count($ids) === 1 ? $out[0] : $out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$money = fn(int $v): string => '<span class="st-num' . ($v < 0 ? ' is-neg' : '') . '">' . ($v < 0 ? '−' : '') . formatMoney(abs($v)) . '</span>';
Biz::$navActive = 'accounting.php';
$pageTitle = 'دفاتر و مالیات';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <div>
        <h1 class="st-h1">دفاتر و مالیات</h1>
        <p class="st-muted">دفترِ دوطرفه از همین اسنادِ فروشگاه ساخته می‌شود — هیچ ثبتِ دوباره‌ای لازم نیست.</p>
    </div>
</div>
<nav class="st-tabs" aria-label="بخش">
    <?php foreach ($TABS as $tk => $tl): ?>
        <a class="st-tab-chip<?= $tk === $t ? ' is-active' : '' ?>" href="<?= h(Biz::url('accounting.php?t=' . $tk)) ?>"><?= h($tl) ?></a>
    <?php endforeach; ?>
</nav>
<?php if (!$ready): ?>
<div class="st-flash st-flash-warn" role="status">لایه‌ی حسابداری هنوز راه نیفتاده است — مدیرِ نصب باید migrationها را اعمال کند.</div>
<?php require __DIR__ . '/../includes/biz_foot.php'; exit; endif; ?>

<?php
// ⛔ نوارِ بازه — فقط زبانه‌هایی که بازه دارند
if (in_array($t, ['trial', 'journal', 'moadian'], true)): ?>
<nav class="st-tabs" aria-label="بازه">
    <?php foreach (BizReports::PERIODS as $pk => $pl): if ($pk === 'custom') { continue; } ?>
        <a class="st-tab-chip<?= $pk === $period ? ' is-active' : '' ?>" href="<?= h(Biz::url('accounting.php?t=' . $t . '&p=' . $pk)) ?>"><?= h($pl) ?></a>
    <?php endforeach; ?>
</nav>
<form method="get" class="st-card st-form st-inline-form" action="<?= h(Biz::url('accounting.php')) ?>">
    <input type="hidden" name="t" value="<?= h($t) ?>"><input type="hidden" name="p" value="custom">
    <div class="st-row3">
        <label class="st-field"><span>از</span><input type="text" name="from" dir="ltr" inputmode="numeric" value="<?= h(BizDocView::jDate($rFrom)) ?>"></label>
        <label class="st-field"><span>تا</span><input type="text" name="to" dir="ltr" inputmode="numeric" value="<?= h(BizDocView::jDate($rTo)) ?>"></label>
        <div class="st-field"><span>&nbsp;</span><button type="submit" class="st-btn st-btn-ghost">نمایش</button></div>
    </div>
</form>
<?php endif; ?>

<?php if ($t === 'balance'):
    $at = BizDocView::gDate((string)getParam('at'));
    $at = $at !== '' ? $at : date('Y-m-d');
    // ⛔ یک بار همه‌ی سندها: ترازنامه از «تا این تاریخ»، مغایرت‌گیری از کلِ دفتر
    //    (عددِ صفحه‌ها مانده‌ی امروز با همه‌ی سندهاست — `BizLedger::reconcile()`)
    $all = BizLedger::entries($userId, BizReports::ALL_TO);
    $entries = BizLedger::upTo($all, $at);
    $bs = BizLedger::balanceSheet($userId, $at, $entries);
    $rec = BizLedger::reconcile($userId, BizLedger::trial($userId, BizReports::ALL_TO, '', $all));
    $col = function (string $title, array $rows, int $sum) use ($money): string {
        $h = '<section class="st-card"><h2 class="st-h3">' . h($title) . '</h2><ul class="st-list">';
        foreach ($rows as $k => $v) { $h .= '<li class="st-list-row"><span>' . h($k) . '</span>' . $money((int)$v) . '</li>'; }
        return $h . '<li class="st-list-row st-sum-total"><b>جمع</b><b>' . $money($sum) . '</b></li></ul></section>';
    };
?>
<form method="get" class="st-card st-form st-inline-form" action="<?= h(Biz::url('accounting.php')) ?>">
    <input type="hidden" name="t" value="balance">
    <div class="st-row3">
        <label class="st-field"><span>ترازنامه در تاریخِ</span><input type="text" name="at" dir="ltr" inputmode="numeric" value="<?= h(BizDocView::jDate($at)) ?>"></label>
        <div class="st-field"><span>&nbsp;</span><button type="submit" class="st-btn st-btn-ghost">نمایش</button></div>
    </div>
</form>
<?php if ($bs['diff'] === 0): ?>
<div class="st-flash st-flash-ok" role="status">ترازنامه تراز است: دارایی‌ها <?= formatMoney($bs['assets']) ?> = بدهی‌ها <?= formatMoney($bs['liabilities']) ?> + سرمایه <?= formatMoney($bs['equities']) ?> تومان.</div>
<?php else: ?>
<div class="st-flash st-flash-err" role="alert">ترازنامه <?= formatMoney(abs($bs['diff'])) ?> تومان اختلاف دارد — لطفاً به پشتیبانی خبر دهید.</div>
<?php endif; ?>
<div class="st-grid-3">
    <?= $col('دارایی‌ها', $bs['asset'], $bs['assets']) ?>
    <?= $col('بدهی‌ها', $bs['liability'], $bs['liabilities']) ?>
    <?= $col('حقوقِ صاحبانِ سرمایه', $bs['equity'], $bs['equities']) ?>
</div>
<section class="st-card">
    <h2 class="st-h3">مغایرت‌گیری با دفترهای فروشگاه</h2>
    <?php if ($rec['ok']): ?>
    <p>✓ مانده‌ی هر صندوق و هر طرف‌حساب در دفتر دقیقاً همان است که صفحه‌های «صندوق و بانک» و «مشتریان و تأمین‌کنندگان» نشان می‌دهند.</p>
    <?php else: ?>
    <div class="st-flash st-flash-err" role="alert">مغایرت پیدا شد:</div>
    <ul class="st-list">
        <?php foreach (array_merge($rec['cash'], $rec['parties']) as $m): ?>
        <li class="st-list-row"><span><?= h($m['name']) ?></span><span>دفتر <?= $money($m['ledger']) ?> · صفحه <?= $money($m['book']) ?></span></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <p class="st-muted">ارزشِ انبار در دفتر <?= $money($rec['stock']['ledger']) ?> و به میانگینِ موزونِ کالاها <?= $money($rec['stock']['book']) ?> تومان<?= $rec['stock']['diff'] !== 0 ? ' — اختلافِ ' . formatMoney(abs($rec['stock']['diff'])) . ' تومان از گردِ بهای هر سند است' : '' ?>.</p>
</section>

<?php elseif ($t === 'trial'):
    $tb = BizLedger::trial($userId, $rTo, $period === 'all' ? '' : $rFrom);
?>
<div class="st-table-wrap">
    <table class="st-table st-table-compact">
        <thead><tr><th>کد</th><th>حساب</th><th class="st-td-num">گردشِ بدهکار</th><th class="st-td-num">گردشِ بستانکار</th><th class="st-td-num">مانده</th></tr></thead>
        <tbody>
        <?php foreach ($tb['rows'] as $row): ?>
            <tr>
                <td class="st-num"><?= h(toPersianDigits($row['code'])) ?></td>
                <td><?= h($row['name']) ?>
                    <?php if (!empty($tb['detail'][$row['code']])): ?>
                    <details class="st-detail"><summary class="st-muted-i">تفصیلی (<?= toPersianDigits((string)count($tb['detail'][$row['code']])) ?>)</summary>
                        <ul class="st-list"><?php foreach ($tb['detail'][$row['code']] as $d): ?><li class="st-list-row"><span><?= h($d['name'] !== '' ? $d['name'] : '—') ?></span><?= $money($d['dr'] - $d['cr']) ?></li><?php endforeach; ?></ul>
                    </details>
                    <?php endif; ?>
                </td>
                <td class="st-td-num"><?= $money($row['dr']) ?></td>
                <td class="st-td-num"><?= $money($row['cr']) ?></td>
                <td class="st-td-num"><?= $money(abs($row['balance'])) ?> <small class="st-muted-i"><?= $row['balance'] > 0 ? 'بد' : ($row['balance'] < 0 ? 'بس' : '') ?></small></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><th></th><th>جمع</th><th class="st-td-num"><?= $money($tb['dr']) ?></th><th class="st-td-num"><?= $money($tb['cr']) ?></th><th class="st-td-num"><?= $tb['dr'] === $tb['cr'] ? '✓ تراز' : 'اختلاف ' . formatMoney(abs($tb['dr'] - $tb['cr'])) ?></th></tr></tfoot>
    </table>
</div>
<p class="st-muted">بازه‌ی <?= h(BizReports::rangeLabel($rFrom, $rTo)) ?><?= $period !== 'all' ? ' — فقط گردشِ همین بازه؛ مانده‌ی تجمعی در «از ابتدا» یا ترازنامه' : '' ?>.</p>

<?php elseif ($t === 'journal'):
    $entries = BizLedger::entries($userId, $rTo, $period === 'all' ? '' : $rFrom);
    $shown = 0;
?>
<div class="st-head-actions"><a class="st-btn st-btn-ghost" href="<?= h($keep(['csv' => '1'])) ?>">دریافتِ CSV برای حسابدار</a></div>
<?php if (!$entries): ?><p class="st-muted">در این بازه سندی نیست.</p><?php endif; ?>
<?php foreach ($entries as $n => $e): if ($shown >= BizLedger::JOURNAL_CAP) { break; } $shown += count($e['lines']); ?>
<section class="st-card st-journal">
    <div class="st-card-head">
        <h2 class="st-h3"><span class="st-num"><?= h(toPersianDigits((string)($n + 1))) ?>.</span> <?= $e['ref'] !== '' ? '<a href="' . h(Biz::url($e['ref'])) . '">' . h($e['desc']) . '</a>' : h($e['desc']) ?></h2>
        <span class="st-muted-i st-num"><?= h(toJalali($e['date'])) ?></span>
    </div>
    <div class="st-table-wrap st-flat"><table class="st-table st-table-compact">
        <tbody>
        <?php foreach ($e['lines'] as [$code, , $dn, $d, $c]): ?>
            <tr><td class="st-num"><?= h(toPersianDigits($code)) ?></td><td><?= h(BizLedger::ACCOUNTS[$code][0]) ?><?= $dn !== '' ? ' <span class="st-muted-i">· ' . h($dn) . '</span>' : '' ?></td>
                <td class="st-td-num"><?= $d ? $money($d) : '' ?></td><td class="st-td-num"><?= $c ? $money($c) : '' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
<?php endforeach; ?>
<?php if ($shown >= BizLedger::JOURNAL_CAP): ?><p class="st-flash st-flash-warn">فقط <?= toPersianDigits((string)BizLedger::JOURNAL_CAP) ?> سطرِ اول نمایش داده شد؛ همه در CSV هست یا بازه را کوتاه‌تر کنید.</p><?php endif; ?>

<?php elseif ($t === 'vat'):
    [$cjy] = BizVat::jym(date('Y-m-d'));
    $jy = (int)getParam('jy', (string)$cjy);
    $jy = $jy >= 1380 && $jy <= 1500 ? $jy : $cjy;
    [, $cjm] = BizVat::jym(date('Y-m-d'));
    $q = getParam('q') === '' ? ($jy === $cjy ? intdiv($cjm - 1, 3) + 1 : 0) : max(0, min(4, (int)getParam('q')));
    [$vf, $vt] = $q === 0 ? BizVat::year($jy) : BizVat::quarter($jy, $q);
    $vr = BizVat::report($userId, $vf, $vt);
    $rate = Biz::vatRate($userId);
?>
<p class="st-muted">نرخِ امروزِ فروشگاه: <b><?= $rate > 0 ? h(BizView::pct($rate)) : 'خاموش' ?></b> — <a href="<?= h(Biz::url('settings.php#accounting')) ?>">تنظیم</a>. اظهارنامه فصلی است؛ برگشت‌ها از هر دو سو کم شده‌اند.</p>
<nav class="st-tabs" aria-label="دوره">
    <a class="st-tab-chip" href="<?= h(Biz::url('accounting.php?t=vat&jy=' . ($jy - 1) . '&q=' . $q)) ?>">‹ <?= h(toPersianDigits((string)($jy - 1))) ?></a>
    <a class="st-tab-chip<?= $q === 0 ? ' is-active' : '' ?>" href="<?= h(Biz::url('accounting.php?t=vat&jy=' . $jy . '&q=0')) ?>">کلِ <?= h(toPersianDigits((string)$jy)) ?></a>
    <?php foreach ([1 => 'بهار', 2 => 'تابستان', 3 => 'پاییز', 4 => 'زمستان'] as $qk => $ql): ?>
        <a class="st-tab-chip<?= $qk === $q ? ' is-active' : '' ?>" href="<?= h(Biz::url('accounting.php?t=vat&jy=' . $jy . '&q=' . $qk)) ?>"><?= h($ql) ?></a>
    <?php endforeach; ?>
    <?php if ($jy < $cjy): ?><a class="st-tab-chip" href="<?= h(Biz::url('accounting.php?t=vat&jy=' . ($jy + 1) . '&q=' . $q)) ?>"><?= h(toPersianDigits((string)($jy + 1))) ?> ›</a><?php endif; ?>
</nav>
<div class="st-table-wrap">
    <table class="st-table st-table-compact">
        <thead><tr><th>ماه</th><th class="st-td-num">فروش (بی‌مالیات)</th><th class="st-td-num">مالیاتِ فروش</th><th class="st-td-num st-hide-sm">خرید (بی‌مالیات)</th><th class="st-td-num">مالیاتِ خرید</th><th class="st-td-num">بدهی به سازمان</th></tr></thead>
        <tbody>
        <?php foreach ($vr['rows'] as $r): ?>
            <tr><td><?= h(BizDash::MONTHS[(int)$r['jm']] . ' ' . toPersianDigits((string)$r['jy'])) ?></td>
                <td class="st-td-num"><?= $money($r['sale_net']) ?></td><td class="st-td-num"><?= $money($r['sale_tax']) ?></td>
                <td class="st-td-num st-hide-sm"><?= $money($r['buy_net']) ?></td><td class="st-td-num"><?= $money($r['buy_tax']) ?></td>
                <td class="st-td-num"><b><?= $money($r['payable']) ?></b></td></tr>
        <?php endforeach; ?>
        <?php if (!$vr['rows']): ?><tr><td colspan="6" class="st-muted">در این دوره سندِ صادرشده‌ای نیست.</td></tr><?php endif; ?>
        </tbody>
        <tfoot><tr><th>جمع</th><th class="st-td-num"><?= $money($vr['sum']['sale_net']) ?></th><th class="st-td-num"><?= $money($vr['sum']['sale_tax']) ?></th>
            <th class="st-td-num st-hide-sm"><?= $money($vr['sum']['buy_net']) ?></th><th class="st-td-num"><?= $money($vr['sum']['buy_tax']) ?></th><th class="st-td-num"><b><?= $money($vr['sum']['payable']) ?></b></th></tr></tfoot>
    </table>
</div>
<p class="st-muted"><?= $vr['sum']['payable'] >= 0 ? 'مبلغِ «بدهی به سازمان» را باید در اظهارنامه‌ی این دوره پرداخت کنید.' : 'مالیاتِ خرید بیشتر بوده — مازاد اعتبارِ قابلِ انتقال به دوره‌ی بعد است.' ?> فروشِ معاف در این دوره: <?= $money($vr['sum']['exempt']) ?> تومان.</p>

<?php elseif ($t === 'year'):
    [$cjy] = BizVat::jym(date('Y-m-d'));
    $closes = [];
    foreach (BizYear::closes($userId) as $c) { $closes[(int)$c['jyear']] = $c; }
?>
<p class="st-muted">بستنِ سال سود و زیان، ترازِ آزمایشی و ترازنامه‌ی روزِ آخرِ اسفند را نگه می‌دارد و دوره را تا همان روز قفل می‌کند — هیچ سندی در آن سال دیگر ثبت، ابطال یا اصلاح نمی‌شود. سال فقط بعد از پایانش بسته می‌شود.</p>
<div class="st-table-wrap">
    <table class="st-table">
        <thead><tr><th>سال</th><th>وضعیت</th><th class="st-td-num">سود و زیان</th><th></th></tr></thead>
        <tbody>
        <?php for ($y = $cjy; $y >= $cjy - 5; $y--): $c = $closes[$y] ?? null; ?>
            <tr>
                <td class="st-num"><?= h(toPersianDigits((string)$y)) ?></td>
                <td><?= $c ? 'بسته — ' . h(toJalali(substr((string)$c['closed_at'], 0, 10))) : ($y === $cjy ? 'سالِ جاری' : 'باز') ?></td>
                <td class="st-td-num"><?= $c ? $money((int)$c['profit']) : '' ?></td>
                <td>
                    <?php if ($c): ?>
                    <form method="post" action="<?= h($self) ?>" class="st-inline" onsubmit="return confirm('سالِ بسته باز شود؟ سال‌های بعدش هم باز می‌شوند.');">
                        <?= Csrf::field() ?><input type="hidden" name="action" value="year_reopen"><input type="hidden" name="jy" value="<?= $y ?>">
                        <label class="st-check"><input type="checkbox" name="confirm" value="1"> تأیید</label>
                        <button type="submit" class="st-link-btn">بازگشایی</button>
                    </form>
                    <?php elseif ($y < $cjy): ?>
                    <form method="post" action="<?= h($self) ?>" class="st-inline" onsubmit="return confirm('سالِ <?= h(toPersianDigits((string)$y)) ?> بسته شود؟');">
                        <?= Csrf::field() ?><input type="hidden" name="action" value="year_close"><input type="hidden" name="jy" value="<?= $y ?>">
                        <button type="submit" class="st-btn st-btn-ghost">بستنِ سال</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endfor; ?>
        </tbody>
    </table>
</div>
<p class="st-muted">گزارشِ هر سال: <a href="<?= h(Biz::url('reports.php?p=last_year')) ?>">سالِ مالیِ قبل</a> · <a href="<?= h(Biz::url('reports.php?p=year')) ?>">امسال</a>.</p>

<?php elseif ($t === 'moadian'):
    $m = Biz::moadian($userId);
    $shop = Biz::settings($userId);
    $list = BizMoadian::list($userId, $rFrom, $rTo);
?>
<section class="st-card">
    <h2 class="st-h3">آمادگی</h2>
    <ul class="st-list">
        <li class="st-list-row"><span>شناسه‌ی یکتای حافظه‌ی مالیاتی</span><span><?= $m['memory_id'] !== '' ? '✓ <span class="ltr-num">' . h($m['memory_id']) . '</span>' : '✗ <a href="' . h(Biz::url('settings.php#accounting')) . '">ثبت کنید</a>' ?></span></li>
        <li class="st-list-row"><span>کدِ اقتصادی یا شناسه‌ی ملیِ فروشگاه</span><span><?= (string)$shop['economic_code'] !== '' || (string)$shop['national_id'] !== '' ? '✓' : '✗ <a href="' . h(Biz::url('settings.php')) . '">ثبت کنید</a>' ?></span></li>
        <li class="st-list-row"><span>شناسه‌ی کالا/خدمتِ پیش‌فرض</span><span><?= $m['sstid'] !== '' ? '✓' : '— (یا روی هر کالا)' ?></span></li>
    </ul>
    <p class="st-muted">خروجی، صورتحسابِ استانداردِ سامانه‌ی مودیان است (سرآیند، اقلام، مبلغ‌ها به <b>ریال</b>، شماره‌ی مالیاتیِ ۲۲ نویسه‌ای) برای تحویل به شرکتِ معتمد یا ابزارِ ارسال. <b>ارسالِ مستقیم</b> امضای دیجیتال با کلیدِ خصوصیِ خودِ فروشگاه می‌خواهد و از اینجا انجام نمی‌شود؛ این خروجی با سامانه‌ی واقعی آزموده نشده است.</p>
</section>
<?php if ($list): ?>
<div class="st-head-actions"><a class="st-btn st-btn-ghost" href="<?= h($keep(['json' => 'all'])) ?>">دریافتِ همه (JSON)</a></div>
<?php endif; ?>
<div class="st-table-wrap">
    <table class="st-table st-table-compact">
        <thead><tr><th>سند</th><th>تاریخ</th><th class="st-hide-sm">طرف‌حساب</th><th class="st-td-num">مبلغ</th><th class="st-td-num st-hide-sm">مالیات</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($list as $r): ?>
            <tr><td><a href="<?= h(Biz::url('invoice.php?id=' . (int)$r['id'])) ?>"><?= h(BizInvoices::title($r)) ?></a></td>
                <td class="st-num"><?= h(toJalali((string)$r['inv_date'])) ?></td>
                <td class="st-hide-sm"><?= h((string)($r['party_name'] ?? 'گذری')) ?></td>
                <td class="st-td-num"><?= $money((int)$r['total']) ?></td>
                <td class="st-td-num st-hide-sm"><?= $money((int)$r['tax_total']) ?></td>
                <td><a class="st-link-btn" href="<?= h($keep(['json' => (int)$r['id']])) ?>">JSON</a></td></tr>
        <?php endforeach; ?>
        <?php if (!$list): ?><tr><td colspan="6" class="st-muted">در این بازه فاکتورِ فروشِ صادرشده‌ای نیست.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<?php else: /* cats */
    $all = BizExpCats::list($userId);
?>
<p class="st-muted">هر هزینه و درآمدِ متفرقه زیرِ یک سرفصل ثبت می‌شود و گزارش‌ها و ترازِ آزمایشی به تفکیکِ همین سرفصل‌اند. سرفصل‌های سیستمی (حقوق، کسری و اضافه‌ی صندوق) را خودِ برنامه به کار می‌برد و غیرفعال نمی‌شوند.</p>
<div class="st-grid-2">
<?php foreach (BizExpCats::KINDS as $kk => $kl): ?>
    <section class="st-card">
        <h2 class="st-h3">سرفصل‌های <?= h($kl) ?></h2>
        <ul class="st-list">
        <?php foreach (array_filter($all, fn($c) => $c['kind'] === $kk) as $c): $on = (int)$c['is_active'] === 1; ?>
            <li class="st-list-row<?= $on ? '' : ' is-inactive' ?>"><span><?= h((string)$c['name']) ?><?= $c['sys_key'] !== null ? ' <small class="st-muted-i">· سیستمی</small>' : '' ?><?= $on ? '' : ' <small class="st-muted-i">· غیرفعال</small>' ?></span>
                <span>
                    <a class="st-link-btn" href="<?= h(Biz::url('payment.php?k=' . $kk . '&cat=' . (int)$c['id'])) ?>">ثبت</a>
                    <?php if ($c['sys_key'] === null): ?>
                    <form method="post" action="<?= h($self) ?>" class="st-inline">
                        <?= Csrf::field() ?><input type="hidden" name="action" value="cat_toggle"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="on" value="<?= $on ? '0' : '1' ?>">
                        <button type="submit" class="st-link-btn"><?= $on ? 'غیرفعال' : 'فعال' ?></button>
                    </form>
                    <?php endif; ?>
                </span></li>
        <?php endforeach; ?>
        </ul>
        <form method="post" action="<?= h($self) ?>" class="st-form">
            <?= Csrf::field() ?><input type="hidden" name="action" value="cat_save"><input type="hidden" name="kind" value="<?= h($kk) ?>">
            <label class="st-field"><span>سرفصلِ تازه</span><input type="text" name="name" required maxlength="<?= BizExpCats::NAME_MAX ?>"></label>
            <button type="submit" class="st-btn st-btn-ghost">افزودن</button>
        </form>
    </section>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
