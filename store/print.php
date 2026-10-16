<?php
/**
 * برگه‌های چاپیِ فروشگاه — «پرینت حساب».
 *
 * `?doc=` یکی از `BizPrint::DOCS` است؛ ناشناخته به «موجودی انبار»
 * برمی‌گردد، نه صفحه‌ی خالی. هندسه‌ی برگه از «تنظیماتِ چاپ» می‌آید
 * (`BizPrint::head()`)؛ این فایل فقط محتوای هر سند را می‌نویسد.
 *
 * ⛔ فقط خواندنی — هیچ POST ای ندارد. طرف‌حساب و کالا با
 *    `BizParties::statement()`/`BizProducts::get()` خوانده می‌شوند که شرطِ
 *    `user_id` دارند؛ شناسه‌ی کاربرِ دیگر ۴۰۴ِ خنثی می‌گیرد.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_print.php';
require_once __DIR__ . '/../includes/biz_docs.php';
require_once __DIR__ . '/../includes/biz_reports.php';

$userId = Biz::ownerId();
$doc    = getParam('doc');
$doc    = isset(BizPrint::DOCS[$doc]) ? $doc : 'stock';
// ⛔ هر برگه دسترسیِ خودش (`BizPrint::DOC_CAPS`)؛ فاکتور و رسید پایین‌تر با نوعِ خودِ سند
$docCap = array_key_exists($doc, BizPrint::DOC_CAPS) ? BizPrint::DOC_CAPS[$doc] : 'reports';
if ($docCap !== null) { Biz::requireCap($docCap); }
$id     = (int)getParam('id', '0');
$filter = getParam('f');
$cat    = mb_substr(trim(getParam('c')), 0, 80);
$money  = static fn($v): string => '<span class="pr-num">' . formatMoney((int)$v) . '</span>';
$qty    = static fn($v): string => '<span class="pr-num">' . h(formatQty($v)) . '</span>';
// مبلغِ علامت‌دار — منفی با «−» جلوی عدد، نه با پرانتز
$signed = static fn($v): string => ((int)$v < 0 ? '−' : '') . '<span class="pr-num">' . formatMoney(abs((int)$v)) . '</span>';

// ⛔ بازه‌ی گزارش‌های دوره‌ای — همان `BizReports::range()` که صفحه‌ی گزارش دارد
require_once __DIR__ . '/../includes/biz_docview.php';
$period = getParam('p');
$period = isset(BizReports::PERIODS[$period]) ? $period : 'month';
[$rf, $rt] = BizReports::range($period, BizDocView::gDate(getParam('from')), BizDocView::gDate(getParam('to')));
$periodBack = Biz::url('reports.php') . '?' . http_build_query(array_filter([
    'p' => $period, 'from' => $period === 'custom' ? BizDocView::jDate($rf) : '', 'to' => $period === 'custom' ? BizDocView::jDate($rt) : '',
], fn($v) => $v !== ''));
$side = getParam('k') === 'purchase' ? 'purchase' : 'sale';

switch ($doc) {
case 'by_product':
    $bp = BizReports::byProduct($userId, $side, $rf, $rt);
    $isSale = $side === 'sale';
    BizPrint::head($userId, BizPrint::SIDE_TITLES['by_product'][$side], $periodBack, ['بازه' => BizReports::rangeLabel($rf, $rt)]);
    $cols = $isSale ? 6 : 4; ?>
    <table class="pr-table">
        <thead><tr><th>کالا</th><th class="pr-wide">کد</th><th class="pr-c-num">تعداد</th><th class="pr-c-num">مبلغ</th>
            <?php if ($isSale): ?><th class="pr-c-num pr-wide">بهای تمام‌شده</th><th class="pr-c-num">سود</th><?php endif; ?></tr></thead>
        <tbody>
        <?php if (!$bp['rows']): ?><tr><td colspan="<?= $cols ?>" class="pr-empty">در این بازه سندِ صادرشده‌ای نیست.</td></tr><?php endif; ?>
        <?php foreach ($bp['rows'] as $r): ?>
            <tr>
                <td><?= h((string)$r['name']) ?></td>
                <td class="pr-wide"><span class="pr-num"><?= h((string)($r['sku'] ?? '')) ?></span></td>
                <td class="pr-c-num"><?= $qty($r['qty']) ?> <?= h((string)$r['unit']) ?></td>
                <td class="pr-c-num"><?= $signed($r['amount']) ?></td>
                <?php if ($isSale): ?><td class="pr-c-num pr-wide"><?= $signed($r['cost']) ?></td><td class="pr-c-num"><?= $signed($r['profit']) ?></td><?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="3">جمع</td><td class="pr-c-num"><?= $signed($bp['amount']) ?></td>
            <?php if ($isSale): ?><td class="pr-c-num pr-wide"><?= $signed($bp['cost']) ?></td><td class="pr-c-num"><?= $signed($bp['profit']) ?></td><?php endif; ?></tr></tfoot>
    </table>
    <p class="pr-note">مبلغ‌ها به تومان و خالصِ برگشت‌اند؛ فقط اسنادِ صادرشده.<?= $isSale ? ' سود = مبلغ − بهای تمام‌شده‌ی لحظه‌ی فروش.' : '' ?></p>
    <?php if ($bp['capped']): ?><p class="pr-note">فهرست بریده شده است؛ بازه را کوتاه‌تر کنید.</p><?php endif; ?>
    <?php BizPrint::foot($userId);
    break;

case 'by_invoice':
    $bi = BizReports::byInvoice($userId, $side, $rf, $rt);
    $isSale = $side === 'sale';
    BizPrint::head($userId, BizPrint::SIDE_TITLES['by_invoice'][$side], $periodBack, ['بازه' => BizReports::rangeLabel($rf, $rt)]);
    $cols = $isSale ? 7 : 6; ?>
    <table class="pr-table">
        <thead><tr><th>سند</th><th>تاریخ</th><th><?= $isSale ? 'مشتری' : 'فروشنده' ?></th><th class="pr-c-num">مبلغ</th>
            <th class="pr-c-num pr-wide"><?= $isSale ? 'دریافت‌شده' : 'پرداخت‌شده' ?></th><th class="pr-c-num">مانده</th>
            <?php if ($isSale): ?><th class="pr-c-num">سود</th><?php endif; ?></tr></thead>
        <tbody>
        <?php if (!$bi['rows']): ?><tr><td colspan="<?= $cols ?>" class="pr-empty">در این بازه سندِ صادرشده‌ای نیست.</td></tr><?php endif; ?>
        <?php foreach ($bi['rows'] as $r): $s = (int)$r['sign']; ?>
            <tr>
                <td><?= h(BizInvoices::title($r)) ?></td>
                <td><span class="pr-num"><?= h(toJalali((string)$r['inv_date'])) ?></span></td>
                <td><?= $r['party_name'] !== null ? h((string)$r['party_name']) : 'گذری' ?></td>
                <td class="pr-c-num"><?= $signed($s * (int)$r['total']) ?></td>
                <td class="pr-c-num pr-wide"><?= $signed($s * (int)$r['paid']) ?></td>
                <td class="pr-c-num"><?= $r['due'] > 0 ? $signed($s * $r['due']) : '' ?></td>
                <?php if ($isSale): ?><td class="pr-c-num"><?= $signed($r['profit']) ?></td><?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="3">جمع (خالصِ برگشت)</td><td class="pr-c-num"><?= $signed($bi['total']) ?></td>
            <td class="pr-c-num pr-wide"><?= $signed($bi['paid']) ?></td><td class="pr-c-num"><?= $signed($bi['due']) ?></td>
            <?php if ($isSale): ?><td class="pr-c-num"><?= $signed($bi['profit']) ?></td><?php endif; ?></tr></tfoot>
    </table>
    <p class="pr-note">مبلغ‌ها به تومان؛ برگشتی‌ها با علامتِ منفی. فقط اسنادِ صادرشده.</p>
    <?php if ($bi['capped']): ?><p class="pr-note">فهرست بریده شده است؛ بازه را کوتاه‌تر کنید.</p><?php endif; ?>
    <?php BizPrint::foot($userId);
    break;

case 'by_party':
    $bpa = BizReports::byParty($userId, $rf, $rt);
    BizPrint::head($userId, BizPrint::DOCS['by_party'], $periodBack, ['بازه' => BizReports::rangeLabel($rf, $rt)]);
    $sm = $bpa['sums']; ?>
    <table class="pr-table">
        <thead><tr><th>نام</th><th class="pr-c-num">فروش</th><th class="pr-c-num pr-wide">برگشت از فروش</th><th class="pr-c-num">خرید</th>
            <th class="pr-c-num pr-wide">برگشت از خرید</th><th class="pr-c-num">دریافت</th><th class="pr-c-num">پرداخت</th><th class="pr-c-num">مانده‌ی امروز</th></tr></thead>
        <tbody>
        <?php if (!$bpa['rows']): ?><tr><td colspan="8" class="pr-empty">در این بازه گردشی نیست.</td></tr><?php endif; ?>
        <?php foreach ($bpa['rows'] as $r): $b = (int)$r['balance']; ?>
            <tr>
                <td><?= h($r['name']) ?></td>
                <td class="pr-c-num"><?= $r['sale'] ? $money($r['sale']) : '' ?></td>
                <td class="pr-c-num pr-wide"><?= $r['sale_return'] ? $money($r['sale_return']) : '' ?></td>
                <td class="pr-c-num"><?= $r['purchase'] ? $money($r['purchase']) : '' ?></td>
                <td class="pr-c-num pr-wide"><?= $r['purchase_return'] ? $money($r['purchase_return']) : '' ?></td>
                <td class="pr-c-num"><?= $r['receipt'] ? $money($r['receipt']) : '' ?></td>
                <td class="pr-c-num"><?= $r['payment'] ? $money($r['payment']) : '' ?></td>
                <td class="pr-c-num"><?= $money(abs($b)) ?> <?= $b > 0 ? 'بد' : ($b < 0 ? 'بس' : '') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td>جمع</td><td class="pr-c-num"><?= $money($sm['sale']) ?></td><td class="pr-c-num pr-wide"><?= $money($sm['sale_return']) ?></td>
            <td class="pr-c-num"><?= $money($sm['purchase']) ?></td><td class="pr-c-num pr-wide"><?= $money($sm['purchase_return']) ?></td>
            <td class="pr-c-num"><?= $money($sm['receipt']) ?></td><td class="pr-c-num"><?= $money($sm['payment']) ?></td><td></td></tr></tfoot>
    </table>
    <p class="pr-note">ستون‌ها گردشِ همین بازه‌اند و «مانده» وضعیتِ امروز: «بد» یعنی او به فروشگاه بدهکار است، «بس» یعنی فروشگاه به او. مبلغ‌ها به تومان.</p>
    <?php if ($bpa['capped']): ?><p class="pr-note">فهرست بریده شده است.</p><?php endif; ?>
    <?php BizPrint::foot($userId);
    break;

case 'commission':
    require_once __DIR__ . '/../includes/biz_staff.php';
    $cm = BizStaff::commission($userId, $rf, $rt);
    BizPrint::head($userId, BizPrint::DOCS['commission'], $periodBack, ['بازه' => BizReports::rangeLabel($rf, $rt)]); ?>
    <table class="pr-table">
        <thead><tr><th>همکار</th><th class="pr-wide">نقش</th><th class="pr-c-num">فاکتور</th><th class="pr-c-num pr-wide">فروش</th>
            <th class="pr-c-num pr-wide">برگشت</th><th class="pr-c-num">فروشِ خالص</th><th class="pr-c-num">درصد</th><th class="pr-c-num">پورسانت</th></tr></thead>
        <tbody>
        <?php if (!$cm['rows']): ?><tr><td colspan="8" class="pr-empty">در این بازه فروشی نیست.</td></tr><?php endif; ?>
        <?php foreach ($cm['rows'] as $r): ?>
            <tr>
                <td><?= h($r['name']) ?></td>
                <td class="pr-wide"><?= h($r['role']) ?></td>
                <td class="pr-c-num"><span class="pr-num"><?= toPersianDigits((string)$r['docs']) ?></span></td>
                <td class="pr-c-num pr-wide"><?= $money($r['sales']) ?></td>
                <td class="pr-c-num pr-wide"><?= $r['returns'] ? $money($r['returns']) : '' ?></td>
                <td class="pr-c-num"><?= $signed($r['net']) ?></td>
                <td class="pr-c-num"><?= $r['id'] > 0 ? '<span class="pr-num">' . h(toPersianDigits(rtrim(rtrim(number_format($r['pct'], 2, '.', ''), '0'), '.'))) . '٪</span>' : '' ?></td>
                <td class="pr-c-num"><?= $r['id'] > 0 ? $money($r['commission']) : '' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="5">جمع</td><td class="pr-c-num"><?= $signed($cm['sums']['net']) ?></td><td></td><td class="pr-c-num"><?= $money($cm['sums']['commission']) ?></td></tr></tfoot>
    </table>
    <p class="pr-note">پایه‌ی پورسانت فروشِ خالصِ بی‌مالیات است؛ برگشتی از پورسانتِ همان کسی کم می‌شود که فاکتورِ اصل را ساخته. درصد همان درصدِ امروزِ صفحه‌ی «همکاران» است. مبلغ‌ها به تومان.</p>
    <?php BizPrint::foot($userId, ['حسابدار', 'تأیید']);
    break;

case 'serials':
    $sf = isset(BizSerial::REPORT_FILTERS[$filter]) ? $filter : '';
    $sr = BizSerial::report($userId, $sf, $id);
    $sp = $id > 0 ? BizProducts::get($userId, $id) : null;
    if ($id > 0 && !$sp) { Biz::notFound(); }
    BizPrint::head($userId, BizPrint::DOCS['serials'] . ($sf !== '' ? ' — ' . BizSerial::REPORT_FILTERS[$sf] : ''),
        $sp ? Biz::url('product.php?id=' . $id) : Biz::url('reports.php'), [
        'کالا'     => $sp ? (string)$sp['name'] : '',
        'در انبار' => toPersianDigits((string)$sr['in']) . ' گوشی',
        'بیرون'    => toPersianDigits((string)$sr['out']) . ' گوشی',
    ]); ?>
    <table class="pr-table">
        <thead><tr><th>IMEI</th><th>کالا</th><th>ورود</th><th class="pr-c-num pr-wide">بهای خرید</th><th>وضعیت</th><th class="pr-c-num pr-wide">بهای فروش</th></tr></thead>
        <tbody>
        <?php if (!$sr['rows']): ?><tr><td colspan="6" class="pr-empty">هیچ گوشیِ IMEIداری با این صافی نیست.</td></tr><?php endif; ?>
        <?php foreach ($sr['rows'] as $u): $f = $u['first']; $l = $u['last']; $out = $u['state'] === 'out'; ?>
            <tr>
                <td><span class="pr-num" dir="ltr"><?= h($u['imei1']) ?></span><?php if ($u['imei2'] !== null): ?><br><span class="pr-num pr-sub" dir="ltr"><?= h($u['imei2']) ?></span><?php endif; ?><?php if (!empty($u['part_no'])): ?><br><span class="pr-sub" dir="ltr"><?= h(BizPartNo::label((string)$u['part_no'])) ?></span><?php endif; ?></td>
                <td><?= h($u['product']) ?></td>
                <td><?= h(BizInvoices::KINDS[$f['kind']] ?? '') ?> <span class="pr-num"><?= toPersianDigits((string)$f['number']) ?></span> · <span class="pr-num"><?= h(toJalali($f['date'])) ?></span><br><span class="pr-sub"><?= h($f['party']) ?></span></td>
                <td class="pr-c-num pr-wide"><?= in_array($f['kind'], BizSerial::IN_KINDS, true) ? $money($f['price']) : '' ?></td>
                <td><?php if ($out): ?><?= h(BizInvoices::KINDS[$l['kind']] ?? '') ?> <span class="pr-num"><?= toPersianDigits((string)$l['number']) ?></span> · <span class="pr-num"><?= h(toJalali($l['date'])) ?></span><br><span class="pr-sub"><?= h($l['party']) ?></span><?php else: ?>در انبار<?php endif; ?></td>
                <td class="pr-c-num pr-wide"><?= $out ? $money($l['price']) : '' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="pr-note">وضعیتِ هر گوشی از آخرین سندِ صادرشده با همان IMEI می‌آید؛ سندِ باطل اثری ندارد. گوشی‌ای که پیش از ثبتِ IMEI (موجودی اول دوره) وارد شده اینجا نیست.</p>
    <?php if ($sr['capped']): ?><p class="pr-note">فهرست بریده شده است؛ با صافیِ وضعیت یا کالا چاپ کنید.</p><?php endif; ?>
    <?php BizPrint::foot($userId, ['انباردار', 'تأیید']);
    break;

case 'partnos':
    // ⛔ همان دستگاه‌های `BizSerial::report()` به تفکیکِ پارت‌نامبر (`BizPartNo::report()`)
    if (!BizPartNo::ready()) { Biz::notFound(); }
    $pr = BizPartNo::report($userId, $id);
    $pp = $id > 0 ? BizProducts::get($userId, $id) : null;
    if ($id > 0 && !$pp) { Biz::notFound(); }
    BizPrint::head($userId, BizPrint::DOCS['partnos'], $pp ? Biz::url('product.php?id=' . $id) : Biz::url('reports.php'), [
        'کالا'     => $pp ? (string)$pp['name'] : '',
        'در انبار' => toPersianDigits((string)array_sum(array_column($pr, 'in'))) . ' گوشی',
        'فروخته'   => toPersianDigits((string)array_sum(array_column($pr, 'out'))) . ' گوشی',
    ]); ?>
    <table class="pr-table">
        <thead><tr><th>پارت‌نامبر</th><th>کالا</th><th class="pr-c-num">در انبار</th><th class="pr-c-num">فروخته</th><th class="pr-c-num pr-wide">میانگینِ خرید</th><th class="pr-c-num pr-wide">میانگینِ فروش</th></tr></thead>
        <tbody>
        <?php if (!$pr): ?><tr><td colspan="6" class="pr-empty">هیچ گوشیِ IMEIداری نیست.</td></tr><?php endif; ?>
        <?php foreach ($pr as $g): ?>
            <tr>
                <td><span dir="ltr"><?= h($g['label']) ?></span></td>
                <td><?= h($g['product']) ?></td>
                <td class="pr-c-num"><?= toPersianDigits((string)$g['in']) ?></td>
                <td class="pr-c-num"><?= toPersianDigits((string)$g['out']) ?></td>
                <td class="pr-c-num pr-wide"><?= $g['buy_n'] > 0 ? $money(intdiv($g['buy'], $g['buy_n'])) : '' ?></td>
                <td class="pr-c-num pr-wide"><?= $g['sell_n'] > 0 ? $money(intdiv($g['sell'], $g['sell_n'])) : '' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="pr-note">پارت‌نامبرِ اپل منطقه (مثلاً LL = آمریکا) و نوعِ دستگاه را می‌گوید: M نو، F بازسازی‌شده، N تعویضی. پارت‌نامبرِ هر گوشی آخرین مقداری است که در سندهایش نوشته شده. مبلغ‌ها به تومان.</p>
    <?php BizPrint::foot($userId, ['انباردار', 'تأیید']);
    break;

case 'cheques':
    if (!BizCheques::ready()) { Biz::notFound(); }
    $cf = getParam('k');
    $cf = isset(BizCheques::FILTERS[$cf]) ? $cf : '';
    $cl = BizCheques::list($userId, $cf, 1, 2000);
    BizPrint::head($userId, BizPrint::DOCS['cheques'] . ' — ' . BizCheques::FILTERS[$cf], Biz::url('cheques.php' . ($cf !== '' ? '?f=' . $cf : '')), [
        'دریافتیِ در جریان' => formatMoney($cl['in']) . ' تومان',
        'پرداختیِ در جریان' => formatMoney($cl['out']) . ' تومان',
        'سررسید گذشته'     => toPersianDigits((string)$cl['overdue']) . ' چک',
    ]);
    $chSum = 0; ?>
    <table class="pr-table">
        <thead><tr><th>سررسید</th><th>نوع</th><th>چک</th><th>طرف‌حساب</th><th>وضعیت</th><th class="pr-c-num">مبلغ</th></tr></thead>
        <tbody>
        <?php if (!$cl['rows']): ?><tr><td colspan="6" class="pr-empty">چکی با این وضعیت نیست.</td></tr><?php endif; ?>
        <?php foreach ($cl['rows'] as $r): $chSum += (int)$r['amount']; ?>
            <tr>
                <td><span class="pr-num"><?= h(toJalali((string)$r['cheque_due'])) ?></span></td>
                <td><?= $r['kind'] === 'receipt' ? 'دریافتی' : 'پرداختی' ?></td>
                <td><?= h(BizCheques::label($r)) ?></td>
                <td><?= h((string)($r['party_name'] ?? '')) ?></td>
                <td><?= h(BizCheques::STATUSES[$r['cheque_status']] ?? '') ?><?php if ($r['cheque_status'] === 'cleared' && $r['settle_date'] !== null): ?><br><span class="pr-sub"><?= h((string)$r['settle_account']) ?> · <span class="pr-num"><?= h(toJalali((string)$r['settle_date'])) ?></span></span><?php endif; ?><?php if ($r['cheque_status'] === 'endorsed' && $r['endorse_date'] !== null): ?><br><span class="pr-sub">به <?= h((string)$r['endorse_party']) ?> · <span class="pr-num"><?= h(toJalali((string)$r['endorse_date'])) ?></span></span><?php endif; ?></td>
                <td class="pr-c-num"><?= $money($r['amount']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <?php if ($cl['rows']): ?><tfoot><tr><th colspan="5">جمع</th><th class="pr-c-num"><?= $money($chSum) ?></th></tr></tfoot><?php endif; ?>
    </table>
    <?php if ($cl['total'] > count($cl['rows'])): ?><p class="pr-note">فهرست بریده شده است؛ با صافیِ وضعیت چاپ کنید.</p><?php endif; ?>
    <?php BizPrint::foot($userId, ['حسابدار', 'تأیید']);
    break;

case 'account':
    $as = BizReports::accountStatement($userId, $id, $rf, $rt);
    if (!$as) { Biz::notFound(); }
    $acc = $as['account'];
    BizPrint::head($userId, BizPrint::DOCS['account'] . ' — ' . $acc['name'], Biz::url('payments.php?acc=' . $id), [
        'حساب'  => (string)$acc['name'],
        'نوع'   => BizCash::KINDS[$acc['kind']] ?? '',
        'بازه'  => BizReports::rangeLabel($rf, $rt),
    ]); ?>
    <table class="pr-table">
        <thead><tr><th>تاریخ</th><th>شرح</th><th class="pr-c-num">ورود</th><th class="pr-c-num">خروج</th><th class="pr-c-num">مانده</th></tr></thead>
        <tbody>
            <tr class="pr-group"><td colspan="4">مانده‌ی ابتدای بازه</td><td class="pr-c-num"><?= $signed($as['opening']) ?></td></tr>
        <?php if (!$as['lines']): ?><tr><td colspan="5" class="pr-empty">در این بازه گردشی نیست.</td></tr><?php endif; ?>
        <?php foreach ($as['lines'] as $l): ?>
            <tr>
                <td><span class="pr-num"><?= h(toJalali($l['date'])) ?></span></td>
                <td><?= h($l['desc']) ?></td>
                <td class="pr-c-num"><?= $l['in'] ? $money($l['in']) : '' ?></td>
                <td class="pr-c-num"><?= $l['out'] ? $money($l['out']) : '' ?></td>
                <td class="pr-c-num"><?= $signed($l['balance']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="2">جمع</td><td class="pr-c-num"><?= $money($as['in']) ?></td><td class="pr-c-num"><?= $money($as['out']) ?></td><td class="pr-c-num"><?= $signed($as['closing']) ?></td></tr></tfoot>
    </table>
    <p class="pr-note">مبلغ‌ها به تومان؛ فقط دریافت و پرداخت‌های باطل‌نشده.</p>
    <?php if ($as['capped']): ?><p class="pr-note">فقط ردیف‌های آخر آمده‌اند؛ بازه را کوتاه‌تر کنید.</p><?php endif; ?>
    <?php BizPrint::foot($userId, ['صندوق‌دار', 'تأیید']);
    break;

case 'invoice':
    // ⛔ برگه‌ی فاکتور فقط از `BizInvoiceDesign::render()` — فاکتورِ واقعی، نمونه
    //    و پیش‌نمایشِ زنده‌ی طراح یک رندرکننده دارند.
    require_once __DIR__ . '/../includes/biz_docview.php';
    require_once __DIR__ . '/../includes/biz_invoice_design.php';
    // پیش‌نمایشِ طراح: گزینه‌های هنوز‌ذخیره‌نشده از همین آدرس (فقط خواندنی)
    $design = getParam('preview') === '1' ? BizInvoiceDesign::clean($_GET, true) : BizInvoiceDesign::get($userId);
    if (getParam('sample') === '1') {
        $inv  = BizInvoiceDesign::sample();
        $ba   = ['prev' => 2500000, 'doc' => (int)$inv['total'], 'paid' => -(int)$inv['paid'], 'after' => 2500000 + (int)$inv['total'] - (int)$inv['paid']];
        $back = Biz::url('invoice-design.php');
    } else {
        $inv = BizInvoices::get($userId, $id);
        // ⛔ پیش‌نویس چاپ نمی‌شود — جز پیش‌فاکتور (`markProforma()`) که برای همین ساخته شده
        if (!$inv || ($inv['status'] === 'draft' && empty($inv['proforma_no']))) { Biz::notFound(); }
        Biz::requireCap(BizRights::docCap((string)$inv['kind']));
        // ⛔ «مانده‌ی قبلی / کل» — کلیدش در طراحیِ فاکتور (`show_balance`)
        $ba = $design['show_balance'] && $inv['status'] === 'issued' && $inv['party_id'] !== null
            ? BizParties::balanceAround($userId, (int)$inv['party_id'], $id) : null;
        $back = getParam('back') === 'quick' ? Biz::url('quick-sale.php') : Biz::url('invoice.php?id=' . $id);
    }
    BizInvoiceDesign::render($userId, $inv, $design, [
        'back'    => $back,
        'embed'   => getParam('embed') === '1',
        'balance' => $ba,
        'logo'    => $design['show_logo'] ? BizInvoiceDesign::logo($userId) : null,
    ]);
    break;

case 'payment':
    $pay = BizPay::get($userId, $id);
    if (!$pay) { Biz::notFound(); }
    Biz::requireCap(BizRights::payCap((string)$pay['kind']));
    BizPrint::head($userId, (BizPay::KINDS[$pay['kind']] ?? '') . ' شماره‌ی ' . toPersianDigits((string)$pay['number']) . ($pay['status'] === 'void' ? ' (باطل)' : ''),
        Biz::url('payment.php?id=' . $id), [
        'تاریخ'     => toJalali((string)$pay['pay_date']),
        'طرف‌حساب' => (string)($pay['party_name'] ?? ''),
        'شرح'       => (string)($pay['title'] ?? ''),
        'صندوق'     => (string)$pay['account_name'] . ($pay['kind'] === 'transfer' ? ' ← ' . (string)$pay['to_account_name'] : ''),
        'روش'       => $pay['kind'] === 'transfer' ? '' : (BizPay::METHODS[$pay['method']] ?? ''),
    ]); ?>
    <div class="pr-totals"><div class="pr-grand"><span>مبلغ</span><span><?= $money($pay['amount']) ?> تومان</span></div></div>
    <?php if ($pay['allocations']): ?>
    <table class="pr-table">
        <thead><tr><th>بابتِ سند</th><th class="pr-c-num">مبلغ</th></tr></thead>
        <tbody><?php foreach ($pay['allocations'] as $al): ?><tr><td><?= h(BizInvoices::title($al)) ?></td><td class="pr-c-num"><?= $money($al['amount']) ?></td></tr><?php endforeach; ?></tbody>
    </table>
    <?php endif; ?>
    <?php if ((string)$pay['note'] !== ''): ?><p class="pr-note"><?= nl2br(h((string)$pay['note'])) ?></p><?php endif; ?>
    <?php BizPrint::foot($userId, ['امضای پرداخت‌کننده', 'امضای دریافت‌کننده']);
    break;

case 'repair':
    // ⛔ قبضِ پذیرش — هیچ مبلغی جز «برآورد» نیست؛ پول فقط در فاکتورِ تعمیر
    require_once __DIR__ . '/../includes/biz_repairs.php';
    $rep = BizRepairs::get($userId, $id);
    if (!$rep) { Biz::notFound(); }
    BizPrint::head($userId, 'قبضِ پذیرشِ تعمیر شماره‌ی ' . toPersianDigits((string)$rep['number']), Biz::url('repair.php?id=' . $id), [
        'تاریخِ پذیرش' => toJalali((string)$rep['received_on']),
        'قولِ تحویل'   => $rep['promised_on'] ? toJalali((string)$rep['promised_on']) : '',
        'مشتری'        => BizRepairs::customer($rep),
        'تلفن'         => (string)($rep['party_id'] !== null ? ($rep['party_phone'] ?? '') : ($rep['customer_phone'] ?? '')),
        'دستگاه'       => (string)$rep['device'],
        'IMEI'         => (string)($rep['imei'] ?? ''),
    ]); ?>
    <table class="pr-table">
        <tbody>
            <tr><td>ایراد</td><td><?= nl2br(h((string)$rep['problem'])) ?></td></tr>
            <?php if ((string)$rep['accessories'] !== ''): ?><tr><td>لوازمِ همراه</td><td><?= h((string)$rep['accessories']) ?></td></tr><?php endif; ?>
            <?php if ((int)$rep['estimate'] > 0): ?><tr><td>برآوردِ هزینه</td><td><?= $money($rep['estimate']) ?> تومان</td></tr><?php endif; ?>
        </tbody>
    </table>
    <p class="pr-note">لطفاً این قبض را هنگامِ تحویل همراه داشته باشید. فروشگاه مسئولِ اطلاعاتِ داخلِ دستگاه نیست؛ پیش از تحویل پشتیبان بگیرید.</p>
    <?php BizPrint::foot($userId, ['امضای تحویل‌گیرنده (فروشگاه)', 'امضای مشتری']);
    break;

case 'sales':
    $sa = BizReports::sales($userId, $rf, $rt);
    $ca = BizReports::cash($userId, $rf, $rt);
    BizPrint::head($userId, BizPrint::DOCS['sales'], $periodBack, [
        'بازه' => BizReports::rangeLabel($rf, $rt),
    ]); ?>
    <table class="pr-table">
        <tbody>
            <tr><td>فروش</td><td class="pr-c-num"><?= $money($sa['sales']) ?></td></tr>
            <tr><td>برگشت از فروش</td><td class="pr-c-num">−<?= $money($sa['returns']) ?></td></tr>
            <tr class="pr-group"><td>فروشِ خالص</td><td class="pr-c-num"><?= $money($sa['net']) ?></td></tr>
            <tr><td>بهای تمام‌شده‌ی کالای فروخته</td><td class="pr-c-num">−<?= $money($sa['cogs']) ?></td></tr>
            <tr class="pr-group"><td>سودِ ناخالص</td><td class="pr-c-num"><?= $sa['gross'] < 0 ? '−' : '' ?><?= $money(abs($sa['gross'])) ?></td></tr>
            <?php if ($sa['shrink'] !== 0): ?><tr><td><?= $sa['shrink'] > 0 ? 'کسریِ انبارگردانی' : 'اضافه‌ی انبارگردانی' ?></td><td class="pr-c-num"><?= $sa['shrink'] > 0 ? '−' : '' ?><?= $money(abs($sa['shrink'])) ?></td></tr><?php endif; ?>
            <?php if ($sa['nonstock'] !== 0): ?><tr><td>خریدِ بی‌انبار (خدمت و شرحِ آزاد)</td><td class="pr-c-num"><?= $sa['nonstock'] > 0 ? '−' : '' ?><?= $money(abs($sa['nonstock'])) ?></td></tr><?php endif; ?>
            <tr><td>هزینه‌های فروشگاه</td><td class="pr-c-num">−<?= $money($ca['expense']) ?></td></tr>
            <tr><td>درآمدِ متفرقه</td><td class="pr-c-num"><?= $money($ca['income']) ?></td></tr>
            <?php $adj = BizBooks::plAdjust($userId, $rf, $rt); $afterExp = BizReports::profit($sa['gross'], $sa['other'], $ca['income'], $ca['expense'], $adj); ?>
            <?php if ($adj !== 0): ?><tr><td>استهلاکِ دارایی‌ها و سندهای دستی</td><td class="pr-c-num"><?= $adj < 0 ? '−' : '' ?><?= $money(abs($adj)) ?></td></tr><?php endif; ?>
            <tr class="pr-group"><td>سودِ خالص</td><td class="pr-c-num"><?= $afterExp < 0 ? '−' : '' ?><?= $money(abs($afterExp)) ?></td></tr>
        </tbody>
    </table>
    <?php $ex = BizReports::expenses($userId, $rf, $rt, 100); if ($ex): ?>
    <table class="pr-table">
        <thead><tr><th>هزینه‌ها به تفکیکِ شرح</th><th class="pr-c-num pr-wide">دفعات</th><th class="pr-c-num">مبلغ</th></tr></thead>
        <tbody><?php foreach ($ex as $e): ?><tr><td><?= h((string)$e['title']) ?></td><td class="pr-c-num pr-wide"><span class="pr-num"><?= toPersianDigits((string)$e['n']) ?></span></td><td class="pr-c-num"><?= $money($e['s']) ?></td></tr><?php endforeach; ?></tbody>
        <tfoot><tr><td>جمعِ هزینه‌ها</td><td class="pr-wide"></td><td class="pr-c-num"><?= $money($ca['expense']) ?></td></tr></tfoot>
    </table>
    <?php endif; ?>
    <?php $tp = BizReports::topProducts($userId, $rf, $rt, 20); if ($tp): ?>
    <table class="pr-table">
        <thead><tr><th>کالا</th><th class="pr-c-num">تعداد</th><th class="pr-c-num">فروش</th><th class="pr-c-num pr-wide">سود</th></tr></thead>
        <tbody><?php foreach ($tp as $t): ?><tr><td><?= h((string)$t['name']) ?></td><td class="pr-c-num"><?= $qty($t['qty']) ?></td><td class="pr-c-num"><?= $money($t['rev']) ?></td><td class="pr-c-num pr-wide"><?= $money($t['profit']) ?></td></tr><?php endforeach; ?></tbody>
    </table>
    <?php endif; ?>
    <p class="pr-note">مبلغ‌ها به تومان؛ فقط اسنادِ صادرشده.</p>
    <?php BizPrint::foot($userId);
    break;

case 'party':
    $s = BizParties::statement($userId, $id);
    if (!$s) { Biz::notFound(); }
    $party = $s['party'];
    BizPrint::head($userId, BizPrint::DOCS['party'] . ' — ' . $party['name'], Biz::url('party.php?id=' . $id), [
        'نام'   => (string)$party['name'],
        'نوع'   => BizParties::KINDS[$party['kind']] ?? '',
        'تلفن'  => (string)($party['phone'] ?? ''),
        'نشانی' => (string)($party['address'] ?? ''),
    ]); ?>
    <table class="pr-table">
        <thead><tr><th>تاریخ</th><th>شرح</th><th class="pr-c-num">بدهکار</th><th class="pr-c-num">بستانکار</th><th class="pr-c-num">مانده</th></tr></thead>
        <tbody>
        <?php if (!$s['lines']): ?>
            <tr><td colspan="5" class="pr-empty">هیچ گردشی ثبت نشده است.</td></tr>
        <?php endif; ?>
        <?php foreach ($s['lines'] as $l): ?>
            <tr>
                <td><span class="pr-num"><?= h(toJalali($l['date'])) ?></span></td>
                <td><?= h($l['desc']) ?></td>
                <td class="pr-c-num"><?= $l['debit'] ? $money($l['debit']) : '' ?></td>
                <td class="pr-c-num"><?= $l['credit'] ? $money($l['credit']) : '' ?></td>
                <td class="pr-c-num"><?= $money(abs($l['balance'])) ?> <?= $l['balance'] > 0 ? 'بد' : ($l['balance'] < 0 ? 'بس' : '') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="2">جمع</td><td class="pr-c-num"><?= $money($s['debit']) ?></td><td class="pr-c-num"><?= $money($s['credit']) ?></td><td></td></tr></tfoot>
    </table>
    <div class="pr-total">
        <span><?= $s['balance'] > 0 ? 'مانده — بدهکار به فروشگاه' : ($s['balance'] < 0 ? 'مانده — طلبکار از فروشگاه' : 'مانده — تسویه') ?></span>
        <span><?= $money(abs($s['balance'])) ?> تومان</span>
    </div>
    <p class="pr-note">«بد» یعنی او به فروشگاه بدهکار است و «بس» یعنی فروشگاه به او بدهکار است. مبلغ‌ها به تومان.</p>
    <?php BizPrint::foot($userId, ['امضای فروشگاه', 'امضای طرف‌حساب']);
    break;

case 'kardex':
    $p = BizProducts::get($userId, $id);
    if (!$p) { Biz::notFound(); }
    $led = (int)$p['track_stock'] === 1 ? BizStock::ledger($userId, $id) : ['rows' => [], 'capped' => false];
    BizPrint::head($userId, BizPrint::DOCS['kardex'] . ' — ' . $p['name'], Biz::url('product.php?id=' . $id), [
        'کالا'            => (string)$p['name'],
        'کد'              => (string)$p['sku'],
        'واحد'            => (string)$p['unit'],
        'موجودیِ فعلی'    => formatQty($p['stock_qty']) . ' ' . $p['unit'],
        'میانگینِ بها'    => formatMoney((int)round((float)$p['avg_cost'])) . ' تومان',
    ]); ?>
    <table class="pr-table">
        <thead><tr><th>تاریخ</th><th>شرح</th><th class="pr-c-num">ورود</th><th class="pr-c-num">خروج</th><th class="pr-c-num">مانده</th><th class="pr-c-num pr-wide">بهای واحد</th></tr></thead>
        <tbody>
        <?php if (!$led['rows']): ?>
            <tr><td colspan="6" class="pr-empty"><?= (int)$p['track_stock'] === 1 ? 'هیچ حرکتی ثبت نشده است.' : 'این قلم خدمت است و موجودی ندارد.' ?></td></tr>
        <?php endif; ?>
        <?php foreach ($led['rows'] as $m): $q = (float)$m['qty']; ?>
            <tr>
                <td><span class="pr-num"><?= h(toJalali((string)$m['move_date'])) ?></span></td>
                <td><?= h(BizStock::KINDS[$m['kind']] ?? (string)$m['kind']) ?><?= (string)($m['note'] ?? '') !== '' ? ' — ' . h((string)$m['note']) : '' ?></td>
                <td class="pr-c-num"><?= $q > 0 ? $qty($q) : '' ?></td>
                <td class="pr-c-num"><?= $q < 0 ? $qty(-$q) : '' ?></td>
                <td class="pr-c-num"><?= $qty($m['balance']) ?></td>
                <td class="pr-c-num pr-wide"><?= $m['unit_cost'] !== null ? $money($m['unit_cost']) : '' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($led['capped']): ?><p class="pr-note">فهرست بریده شده است؛ فقط حرکت‌های اول آمده‌اند.</p><?php endif; ?>
    <?php BizPrint::foot($userId, ['انباردار', 'تأیید']);
    break;

case 'parties':
    $filter = isset(BizParties::FILTERS[$filter]) ? $filter : '';
    $all = BizParties::all($userId, $filter);
    BizPrint::head($userId, BizPrint::DOCS['parties'] . ($filter !== '' ? ' — ' . BizParties::FILTERS[$filter] : ''), Biz::url('parties.php'));
    $rec = 0; $pay = 0; ?>
    <table class="pr-table">
        <thead><tr><th>ردیف</th><th>نام</th><th class="pr-wide">تلفن</th><th class="pr-c-num">بدهکار</th><th class="pr-c-num">بستانکار</th></tr></thead>
        <tbody>
        <?php if (!$all['rows']): ?><tr><td colspan="5" class="pr-empty">طرف‌حسابی نیست.</td></tr><?php endif; ?>
        <?php foreach ($all['rows'] as $i => $r): $b = (int)$r['balance']; $rec += max($b, 0); $pay += max(-$b, 0); ?>
            <tr>
                <td><span class="pr-num"><?= toPersianDigits((string)($i + 1)) ?></span></td>
                <td><?= h((string)$r['name']) ?></td>
                <td class="pr-wide"><span class="pr-num"><?= h((string)($r['phone'] ?? '')) ?></span></td>
                <td class="pr-c-num"><?= $b > 0 ? $money($b) : '' ?></td>
                <td class="pr-c-num"><?= $b < 0 ? $money(-$b) : '' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="2">جمع</td><td class="pr-wide"></td><td class="pr-c-num"><?= $money($rec) ?></td><td class="pr-c-num"><?= $money($pay) ?></td></tr></tfoot>
    </table>
    <p class="pr-note">«بدهکار» یعنی به فروشگاه بدهکار است. مبلغ‌ها به تومان.</p>
    <?php if ($all['capped']): ?><p class="pr-note">فهرست بریده شده است.</p><?php endif; ?>
    <?php BizPrint::foot($userId);
    break;

default: // stock | prices
    $filter = isset(BizProducts::FILTERS[$filter]) ? $filter : '';
    $all = BizProducts::all($userId, $filter, $cat);
    $isPrice = $doc === 'prices';
    $title = BizPrint::DOCS[$doc] . ($filter !== '' ? ' — ' . BizProducts::FILTERS[$filter] : '') . ($cat !== '' ? ' — ' . $cat : '');
    BizPrint::head($userId, $title, Biz::url('products.php'));
    $sum = 0; $group = null; $cols = $isPrice ? 4 : 5; ?>
    <table class="pr-table">
        <thead><tr>
            <th>کالا</th><th class="pr-wide">کد</th>
            <?php if ($isPrice): ?>
                <th>واحد</th><th class="pr-c-num">قیمتِ فروش</th>
            <?php else: ?>
                <th class="pr-c-num">موجودی</th><th class="pr-c-num pr-wide">میانگینِ بها</th><th class="pr-c-num">ارزش</th>
            <?php endif; ?>
        </tr></thead>
        <tbody>
        <?php if (!$all['rows']): ?><tr><td colspan="<?= $cols ?>" class="pr-empty">کالایی نیست.</td></tr><?php endif; ?>
        <?php foreach ($all['rows'] as $r):
            $track = (int)$r['track_stock'] === 1;
            if (!$isPrice && !$track) { continue; }
            $g = (string)($r['category'] ?? '');
            if ($g !== $group): $group = $g; ?>
            <tr class="pr-group"><td colspan="<?= $cols ?>"><?= $g !== '' ? h($g) : 'بی‌دسته' ?></td></tr>
            <?php endif;
            $val = (int)round(max(0, (float)$r['stock_qty']) * (float)$r['avg_cost']); $sum += $val; ?>
            <tr>
                <td><?= h((string)$r['name']) ?></td>
                <td class="pr-wide"><span class="pr-num"><?= h((string)$r['sku']) ?></span></td>
                <?php if ($isPrice): ?>
                    <td><?= h((string)$r['unit']) ?></td>
                    <td class="pr-c-num"><?= $money($r['sell_price']) ?></td>
                <?php else: ?>
                    <td class="pr-c-num"><?= $qty($r['stock_qty']) ?> <?= h((string)$r['unit']) ?></td>
                    <td class="pr-c-num pr-wide"><?= $money(round((float)$r['avg_cost'])) ?></td>
                    <td class="pr-c-num"><?= $money($val) ?></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <?php if (!$isPrice): ?>
        <tfoot><tr><td>جمعِ ارزشِ انبار</td><td class="pr-wide"></td><td></td><td class="pr-wide"></td><td class="pr-c-num"><?= $money($sum) ?></td></tr></tfoot>
        <?php endif; ?>
    </table>
    <p class="pr-note">مبلغ‌ها به تومان<?= $isPrice ? '' : '؛ ارزش = موجودی × میانگینِ بهای خرید' ?>. خدمت‌ها <?= $isPrice ? 'هم آمده‌اند' : 'در این فهرست نیستند' ?>.</p>
    <?php if ($all['capped']): ?><p class="pr-note">فهرست بریده شده است؛ با صافیِ دسته چند برگه چاپ کنید.</p><?php endif; ?>
    <?php BizPrint::foot($userId, ['انباردار', 'تأیید']);
}
