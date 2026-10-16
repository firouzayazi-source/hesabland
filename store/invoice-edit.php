<?php
/**
 * ویرایشگرِ فاکتورِ فروش و خرید — ساختِ تازه (`?k=sale|purchase`) یا ادامه‌ی
 * پیش‌نویس (`?id=`). سندِ صادرشده اینجا باز نمی‌شود: به صفحه‌ی خودش می‌رود.
 *
 * سه دکمه: «ذخیره‌ی پیش‌نویس» (هیچ اثری ندارد)، «صدور» و «صدور و چاپ»
 * (موجودی، مانده و دریافت/پرداختِ همزمان در یک تراکنش — `BizInvoices::issue`).
 *
 * ⛔ فرمِ سادهٔ HTML است و بی‌جاوااسکریپت کار می‌کند: «افزودنِ ردیف» یک
 *    دکمه‌ی فرم است که فقط دوباره رندر می‌کند، و جمع‌ها را همیشه سرور حساب
 *    می‌کند؛ `store.js` فقط جمعِ زنده و پر کردنِ قیمت را اضافه می‌کند.
 *
 * فروشگاهِ موبایل و لوازم جانبی:
 * - ⛔ سررسید ندارد (خواسته‌ی مالکِ نصب) — فیلدش رفت و `BizInvoices::state()`
 *   هم دیگر «سررسید گذشته» نمی‌سازد.
 * - «+» کنارِ خانه‌ی کالا پنلِ «کالای تازه» را باز می‌کند (نوع: کالا/گوشی/
 *   خدمت، قیمت، و برای گوشی IMEI ۱ و ۲). ثبتش از همان `BizProducts::save()`
 *   است و ردیف را پر می‌کند؛ پیش‌نویس ذخیره نمی‌شود و چیزی از فاکتورِ
 *   نیمه‌کاره گم نمی‌شود، چون پنل داخلِ همین فرم است.
 * - IMEI در خانه‌ی کالا (تایپ، اسکن یا انتخاب از فهرست) گوشی را پیدا می‌کند.
 * - ⛔ «+ موجودی» زیرِ ردیفی که بیش از موجودی است (فقط فاکتورِ فروش): پنلِ
 *   «تأمینِ موجودی» در همین فرم — از چه کسی، چند، به چه قیمتی خریدید و به چه
 *   قیمتی می‌فروشید. یک **فاکتورِ خریدِ واقعی** صادر می‌شود (`BizQuickBuy`)،
 *   فروشنده طلبکار می‌شود (یا نقد پرداخت)، و فاکتورِ فروشِ نیمه‌کاره دست‌نخورده
 *   می‌ماند. خواسته‌ی مالکِ نصب: «همون‌جا توی فاکتور بتونم موجودی بدم و
 *   تمامِ دیتای قبلی سرِ جاش باشه».
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_docview.php';
require_once __DIR__ . '/../includes/biz_quickbuy.php';

$userId = Biz::ownerId();
$id     = (int)getParam('id', '0');
$inv    = $id > 0 ? BizInvoices::get($userId, $id) : null;
if ($id > 0 && !$inv) {
    redirectWithMessage(Biz::url('sales.php'), 'error', 'سند پیدا نشد.');
}
// ⛔ پیش‌نویسِ برگشت اینجا باز نمی‌شود: این ویرایشگر پیوندِ ردیف‌ها به فاکتورِ
//    اصلی را نمی‌شناسد و «فروش»ش می‌کرد (`BizInvoices::undo()`).
if ($inv && ($inv['status'] !== 'draft' || !isset(BizInvoices::RETURN_OF[(string)$inv['kind']]))) {
    header('Location: ' . Biz::url('invoice.php?id=' . $id));
    exit;
}
$kind = $inv ? (string)$inv['kind'] : (getParam('k') === 'purchase' ? 'purchase' : 'sale');
if (!isset(BizInvoices::RETURN_OF[$kind])) { $kind = 'sale'; }
Biz::requireCap(BizRights::docCap($kind));
$side = BizDocView::sideOf($kind);
Biz::$navActive = BizDocView::SIDES[$side]['page'];
$self = Biz::url('invoice-edit.php' . ($id > 0 ? '?id=' . $id : '?k=' . $kind));

$accounts = BizDocView::accounts($userId);
$form = [
    // مشتریِ پیش‌فرض (گزینه‌ی فاکتور) فقط برای فاکتورِ فروشِ تازه‌ای که طرفی نگرفته
    'party_id' => $inv ? (int)$inv['party_id']
        : ((int)getParam('party', '0') ?: ($kind === 'sale' ? Biz::invoicePrefs($userId)['default_party'] : 0)),
    'inv_date' => BizDocView::jDate($inv ? (string)$inv['inv_date'] : date('Y-m-d')),
    'discount' => $inv && (int)$inv['discount'] > 0 ? (string)(int)$inv['discount'] : '',
    'extra'    => $inv && (int)$inv['extra'] > 0 ? (string)(int)$inv['extra'] : '',
    'note'     => (string)($inv['note'] ?? ''),
    // ⛔ نرخِ مالیات: خودِ پیش‌نویس، وگرنه نرخِ امروزِ فروشگاه (`saveDraft()` همین را می‌گیرد)
    'vat_rate' => BizInvoices::rateText($inv ? (float)($inv['vat_rate'] ?? 0) : Biz::vatRate($userId)),
    'lines'    => $inv ? $inv['lines'] : [],
    'pay_mode' => 'none', 'pay_amount' => '', 'account_id' => (int)($accounts[0]['id'] ?? 0), 'method' => 'cash',
    'warehouse_id' => (int)($inv['warehouse_id'] ?? 0),
    'loyalty_points' => (string)(int)($inv['loyalty_points'] ?? 0),
    // ⛔ نرخِ ارزِ سند (طرف‌حسابِ ارزی، `BizFx`) — خالی = نرخِ خودِ پیش‌نویس یا نرخِ روز
    'fx_rate' => isset($inv['fx_rate']) && $inv['fx_rate'] !== null ? rtrim(rtrim((string)$inv['fx_rate'], '0'), '.') : '',
];
// ⛔ امتیازِ وفاداری فقط روی فروش و فقط وقتی باشگاهِ مشتری روشن است (`BizLoyalty`)
$loyaltyOn = $kind === 'sale' && BizComms::ready() && BizComms::settings($userId)['loyalty_on'];
// ⛔ انتخابِ انبار فقط در فروشگاهِ چندانباره (`BizWarehouses::any()`)؛ تک‌انبار هیچ چیزِ تازه‌ای نمی‌بیند
$warehouses = BizWarehouses::any($userId) ? BizWarehouses::list($userId, true) : [];
$blank = $inv ? 2 : 4;
$error = '';
$notice = '';
// پنلِ «کالای تازه» — `row` اندیسِ ردیف در فهرستِ پُرشده، یا -1 = ردیفِ تازه
$np = ['open' => false, 'row' => -1, 'type' => 'goods', 'name' => '', 'sku' => '', 'buy' => '', 'sell' => '', 'imei1' => '', 'imei2' => '', 'error' => ''];
// پنلِ «تأمینِ موجودی» (`BizQuickBuy`) — فقط فاکتورِ فروش
$spEmpty = ['open' => false, 'row' => -1, 'product_id' => 0, 'name' => '', 'unit' => '', 'serial' => false, 'party_id' => 0,
            'party_name' => '', 'qty' => '', 'buy' => '', 'sell' => '', 'date' => '', 'pay' => 'credit',
            'account_id' => (int)($accounts[0]['id'] ?? 0), 'imeis' => '', 'error' => ''];
$sp = $spEmpty;
$spDone = ''; $spDoneUrl = '';   // پیامِ موفقیت با لینکِ فاکتورِ خرید

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $action = postParam('action');
    if ($inv && $action === 'delete') {
        $res = BizInvoices::deleteDraft($userId, $id);
        redirectWithMessage(Biz::url(BizDocView::SIDES[$side]['page']), $res['ok'] ? 'success' : 'error', $res['message']);
    }
    $in = [
        'party_id' => (int)postParam('party_id'),
        'inv_date' => BizDocView::gDate(postParam('inv_date')),
        'discount' => postParam('discount'), 'extra' => postParam('extra'), 'note' => postParam('note'),
        'lines'    => is_array($_POST['lines'] ?? null) ? $_POST['lines'] : [],
        'vat_rate' => isset($_POST['vat_rate']) ? postParam('vat_rate') : null,
    ];
    if ($warehouses && isset($_POST['warehouse_id'])) { $in['warehouse_id'] = (int)postParam('warehouse_id'); }
    if (isset($_POST['fx_rate'])) { $in['fx_rate'] = postParam('fx_rate'); }
    if ($loyaltyOn && isset($_POST['loyalty_points'])) { $in['loyalty_points'] = trim(postParam('loyalty_points')) === '' ? '0' : postParam('loyalty_points'); }
    $rawLines = array_values(array_filter($in['lines'], 'is_array'));
    $filled   = fn($l) => trim((string)($l['item'] ?? '')) !== '' || trim((string)($l['price'] ?? '')) !== ''
                          || trim((string)($l['imei1'] ?? '')) !== '';
    // اندیسِ ردیفِ جدولِ فرستاده‌شده → اندیس در فهرستِ پُرشده (ردیفِ خالی = ردیفِ تازه)
    $rowAt = function (int $i) use ($rawLines, $filled): int {
        if (!isset($rawLines[$i]) || !$filled($rawLines[$i])) { return -1; }
        $n = 0;
        for ($k = 0; $k < $i; $k++) { if ($filled($rawLines[$k])) { $n++; } }
        return $n;
    };
    $form = array_merge($form, [
        'party_id' => $in['party_id'], 'inv_date' => postParam('inv_date'),
        'discount' => $in['discount'], 'extra' => $in['extra'], 'note' => $in['note'], 'lines' => array_values($in['lines']),
        'vat_rate' => $in['vat_rate'] ?? $form['vat_rate'],
        'warehouse_id' => (int)($in['warehouse_id'] ?? $form['warehouse_id']),
        'fx_rate' => (string)($in['fx_rate'] ?? $form['fx_rate']),
        'loyalty_points' => (string)($in['loyalty_points'] ?? $form['loyalty_points']),
        'pay_mode' => in_array(postParam('pay_mode'), ['none', 'full', 'part'], true) ? postParam('pay_mode') : 'none',
        'pay_amount' => postParam('pay_amount'), 'account_id' => (int)postParam('account_id'),
        'method' => isset(BizPay::quickMethods()[postParam('method')]) ? postParam('method') : 'cash',
    ]);
    // ردیف‌های خالیِ فرم دوباره نشان داده نمی‌شوند؛ «افزودنِ ردیف» پنج تای تازه می‌گذارد
    $form['lines'] = array_values(array_filter($rawLines, $filled));

    if ((isset($_POST['np_open']) || $action === 'np_save') && !Biz::can('products')) {
        Biz::denied('products');   // کالای تازه از داخلِ فاکتور = تعریفِ کالا
    }
    if (isset($_POST['np_open'])) {
        // «+» بی‌جاوااسکریپت: همان صفحه با پنلِ باز. متنِ ردیف نامِ پیشنهادی
        // است؛ اگر خودش IMEI بود، گوشی با همان IMEI.
        $ri    = (int)postParam('np_open');
        $typed = trim((string)($rawLines[$ri]['item'] ?? ''));
        $asImei = BizSerial::valid(BizSerial::norm($typed));
        $np = array_merge($np, ['open' => true, 'row' => $rowAt($ri), 'type' => $asImei ? 'phone' : 'goods',
                                'name' => $asImei ? '' : $typed, 'imei1' => $asImei ? BizSerial::norm($typed) : '']);
    } elseif ($action === 'np_save') {
        $raw = is_array($_POST['np'] ?? null) ? $_POST['np'] : [];
        $np  = array_merge($np, ['open' => true, 'row' => $rowAt((int)($raw['row'] ?? -1)),
            'type' => isset(BizProducts::TYPES[$raw['type'] ?? '']) ? (string)$raw['type'] : 'goods',
            'name' => (string)($raw['name'] ?? ''), 'sku' => (string)($raw['sku'] ?? ''),
            'buy' => (string)($raw['buy'] ?? ''), 'sell' => (string)($raw['sell'] ?? ''),
            'imei1' => BizSerial::norm((string)($raw['imei1'] ?? '')), 'imei2' => BizSerial::norm((string)($raw['imei2'] ?? ''))]);
        if ($np['type'] !== 'phone') { $np['imei1'] = $np['imei2'] = ''; }
        // IMEI پیش از ساختِ کالا سنجیده می‌شود، وگرنه کالا ساخته می‌شد و ردیف نه
        foreach (['imei1', 'imei2'] as $c) {
            if ($np[$c] !== '' && !BizSerial::valid($np[$c])) { $np['error'] = 'IMEI «' . $np[$c] . '» معتبر نیست (۱۴ تا ۱۷ رقم).'; }
        }
        if ($np['error'] === '') {
            $res = BizProducts::save($userId, [
                'type' => $np['type'], 'name' => $np['name'], 'sku' => $np['sku'],
                'unit' => $np['type'] === 'phone' ? 'دستگاه' : 'عدد',
                'buy_price' => $np['buy'], 'sell_price' => $np['sell'],
            ]);
            if (!$res['ok']) {
                $np['error'] = $res['message'];
            } else {
                $prod = BizProducts::get($userId, (int)$res['id']);
                $line = ['item' => (string)$prod['name'], 'product_id' => (int)$prod['id'], 'qty' => '1',
                         'price' => (string)(int)($kind === 'sale' ? $prod['sell_price'] : $prod['buy_price']),
                         'imei1' => $np['imei1'], 'imei2' => $np['imei2'], 'serial' => $np['type'] === 'phone'];
                if ($np['row'] >= 0 && isset($form['lines'][$np['row']])) {
                    $form['lines'][$np['row']] = $line;
                    $at = $np['row'];
                } else {
                    $form['lines'][] = $line;
                    $at = count($form['lines']) - 1;
                }
                $notice = '«' . $prod['name'] . '» به فهرستِ کالا اضافه شد و در ردیفِ ' . toPersianDigits((string)($at + 1)) . ' نشست.';
                $np = ['open' => false, 'row' => -1, 'type' => 'goods', 'name' => '', 'sku' => '', 'buy' => '', 'sell' => '', 'imei1' => '', 'imei2' => '', 'error' => ''];
            }
        }
    } elseif ($kind === 'sale' && (isset($_POST['sp_open']) || isset($_POST['sp_link']) || isset($_POST['sp_issue']) || $action === 'sp_save') && !Biz::can('buy')) {
        // ⛔ «+ موجودی» یک فاکتورِ خریدِ واقعی است (`BizQuickBuy`) — بی‌دسترسیِ خرید نه
        Biz::denied('buy');
    } elseif ($kind === 'sale' && isset($_POST['sp_open'])) {
        // «+ موجودی» زیرِ ردیف: پنل با پیش‌فرض‌ها — کسریِ همین کالا، آخرین
        // فروشنده‌اش، قیمت‌های خودِ کالا، و برای گوشی IMEIِ همان ردیف.
        $ri   = (int)postParam('sp_open');
        $metaAll = BizInvoices::parseLines($userId, $rawLines, 'sell_price')['meta'];
        $pm   = $metaAll[$ri] ?? null;
        $prod = ($pm['product_id'] ?? null) !== null ? BizProducts::get($userId, (int)$pm['product_id']) : null;
        if (!$prod || (int)$prod['track_stock'] !== 1) {
            $error = 'این ردیف کالای انبارداری نیست؛ «+ موجودی» فقط برای کالای ثبت‌شده است.';
        } else {
            $need = 0.0;
            foreach ($rawLines as $k => $l) {
                if (($metaAll[$k]['product_id'] ?? null) !== (int)$prod['id']) { continue; }
                $need += trim((string)($l['qty'] ?? '')) === '' ? 1.0 : sanitizeQty((string)$l['qty']);
            }
            $serial = (int)$prod['has_serial'] === 1;
            $row    = $rawLines[$ri] ?? [];
            $sp = array_merge($spEmpty, [
                'open' => true, 'row' => $rowAt($ri), 'product_id' => (int)$prod['id'], 'name' => (string)$prod['name'],
                'unit' => (string)$prod['unit'], 'serial' => $serial,
                'party_id' => BizQuickBuy::lastSupplier($userId, (int)$prod['id']),
                'qty' => formatQty(max(1.0, $need - (float)$prod['stock_qty'])),
                'buy' => (int)$prod['buy_price'] > 0 ? (string)(int)$prod['buy_price'] : '',
                'sell' => trim((string)($row['price'] ?? '')) !== '' ? (string)$row['price'] : (string)(int)$prod['sell_price'],
                'date' => (string)$form['inv_date'],
                'imeis' => $serial ? trim(($pm['imei1'] ?? '') . (($pm['imei2'] ?? '') !== '' ? ' / ' . $pm['imei2'] : '')) : '',
            ]);
        }
    } elseif ($kind === 'sale' && (isset($_POST['sp_link']) || isset($_POST['sp_issue']))) {
        // ⛔ «خریدش قبلاً ثبت شده — فقط موجودی بده»: وصلِ ردیفِ آزادِ خرید یا صدورِ
        //    پیش‌نویسِ خرید. هیچ خریدِ دومی ساخته نمی‌شود (`BizQuickBuy::linkLine()`).
        $raw  = is_array($_POST['sp'] ?? null) ? $_POST['sp'] : [];
        $pid  = (int)($raw['product_id'] ?? 0);
        $prod = BizProducts::get($userId, $pid);
        if (BizOnce::claim() !== null) {
            $notice = 'این کار یک بار انجام شده بود؛ دوباره انجام نشد.';
        } else {
            $r = isset($_POST['sp_link']) ? BizQuickBuy::linkLine($userId, (int)postParam('sp_link'), $pid)
                                          : BizQuickBuy::issueDraft($userId, (int)postParam('sp_issue'));
            if ($r['ok']) {
                BizOnce::done($self);
                $spDone = $r['message'];
                $spDoneUrl = Biz::url('invoice.php?id=' . (int)$r['invoice_id']);
            } else {
                BizOnce::release();
                // پنل با همان پیش‌فرض‌ها باز می‌ماند تا خطا کنارِ همان دکمه دیده شود
                $sp = array_merge($spEmpty, ['open' => true, 'row' => (int)($raw['row'] ?? -1), 'product_id' => $pid,
                    'name' => (string)($prod['name'] ?? ''), 'unit' => (string)($prod['unit'] ?? ''),
                    'serial' => $prod && (int)$prod['has_serial'] === 1, 'qty' => (string)($raw['qty'] ?? ''),
                    'buy' => (string)($raw['buy'] ?? ''), 'sell' => (string)($raw['sell'] ?? ''), 'date' => (string)($raw['date'] ?? ''),
                    'party_id' => (int)($raw['party_id'] ?? 0), 'error' => $r['message']]);
            }
        }
    } elseif ($kind === 'sale' && $action === 'sp_save') {
        $raw  = is_array($_POST['sp'] ?? null) ? $_POST['sp'] : [];
        $prod = BizProducts::get($userId, (int)($raw['product_id'] ?? 0));
        $sp = array_merge($spEmpty, [
            'open' => true, 'row' => (int)($raw['row'] ?? -1), 'product_id' => (int)($prod['id'] ?? 0),
            'name' => (string)($prod['name'] ?? ''), 'unit' => (string)($prod['unit'] ?? ''),
            'serial' => $prod && (int)$prod['has_serial'] === 1,
            'party_id' => (int)($raw['party_id'] ?? 0), 'party_name' => (string)($raw['party_name'] ?? ''),
            'qty' => (string)($raw['qty'] ?? ''), 'buy' => (string)($raw['buy'] ?? ''), 'sell' => (string)($raw['sell'] ?? ''),
            'date' => (string)($raw['date'] ?? ''), 'pay' => ($raw['pay'] ?? '') === 'cash' ? 'cash' : 'credit',
            'account_id' => (int)($raw['account_id'] ?? 0), 'imeis' => (string)($raw['imeis'] ?? ''),
        ]);
        $dd = BizDocView::docDate($sp['date'], 'تاریخِ خرید');
        if (!$dd['ok']) {
            $sp['error'] = $dd['message'];
        } elseif (BizOnce::claim() !== null) {
            // ⛔ دو بار زدنِ «ثبتِ خرید» دو فاکتورِ خرید نمی‌سازد — ولی فاکتورِ
            //    فروشِ نیمه‌کاره را هم دور نمی‌ریزد (ریدایرکت نه؛ همین صفحه با همان داده).
            $sp = $spEmpty;
            $notice = 'این خرید یک بار ثبت شده بود؛ دوباره ثبت نشد.';
        } else {
            $r = BizQuickBuy::run($userId, ['product_id' => $sp['product_id'], 'party_id' => $sp['party_id'],
                'party_name' => $sp['party_id'] > 0 ? '' : $sp['party_name'], 'qty' => $sp['qty'], 'buy' => $sp['buy'],
                'sell' => $sp['sell'], 'date' => $dd['date'], 'pay' => $sp['pay'], 'account_id' => $sp['account_id'],
                'imeis' => $sp['imeis']]);
            if (!$r['ok']) {
                BizOnce::release();
                $sp['error'] = $r['message'];
            } else {
                BizOnce::done($self);
                // ردیفِ فروش (اندیس در فهرستِ پُرشده): قیمتِ تازه‌ی فروش اگر خانه خالی یا
                // همان قیمتِ قبلیِ کالا بود، و برای گوشیِ تک‌دستگاه IMEIِ همان گوشی.
                $ri = $sp['row'];
                if ($ri >= 0 && isset($form['lines'][$ri])) {
                    $cur = trim((string)($form['lines'][$ri]['price'] ?? ''));
                    if ($cur === '' || sanitizeAmount($cur) === (int)$prod['sell_price']) {
                        $form['lines'][$ri]['price'] = (string)$r['sell'];
                    }
                    $units = $sp['serial'] ? BizQuickBuy::parseImeis($sp['imeis']) : [];
                    if (count($units) === 1 && trim((string)($form['lines'][$ri]['imei1'] ?? '')) === '') {
                        [$form['lines'][$ri]['imei1'], $form['lines'][$ri]['imei2']] = $units[0];
                    }
                }
                $spDone    = $r['message'];
                $spDoneUrl = Biz::url('invoice.php?id=' . (int)$r['invoice_id']);
                $sp = $spEmpty;
            }
        }
    } elseif ($action === 'sp_cancel') {
        // فقط بستنِ پنلِ تأمین — فرم همان‌طور که بود
    } elseif ($action === 'np_cancel') {
        // فقط بستنِ پنل — فرم همان‌طور که بود دوباره نشان داده می‌شود
    } elseif ($action === 'addrows') {
        $blank = 6;
    } elseif ($action === 'loyalty_apply' && $loyaltyOn) {
        // «اعمالِ امتیاز»: ارزشِ امتیاز به تخفیفِ فرم اضافه می‌شود (بی‌ذخیره) — مصرف = همان تخفیف
        $pts = max(0, (int)toLatinDigits((string)$form['loyalty_points']));
        $form['discount'] = (string)(sanitizeAmount((string)$form['discount']) + $pts * (int)BizComms::settings($userId)['point_value']);
        $notice = toPersianDigits((string)$pts) . ' امتیاز به تخفیف اضافه شد؛ با صدور مصرف می‌شود.';
    } elseif (!($dd = BizDocView::docDate((string)postParam('inv_date'), 'تاریخِ فاکتور'))['ok']) {
        // ⛔ تاریخِ نامعتبر «امروز» نمی‌شود — همان‌جا گفته می‌شود
        $error = $dd['message'];
    } else {
        $in['inv_date'] = $dd['date'];
        // ⛔ دو بار زدنِ «صدور» دو فاکتور نمی‌سازد (`BizOnce`)
        if (($dup = BizOnce::claim()) !== null) { BizOnce::redirectDuplicate($dup, Biz::url(BizDocView::SIDES[$side]['page'])); }
        $res = BizInvoices::saveDraft($userId, $kind, $in, $id);
        if (!$res['ok']) {
            BizOnce::release();
            $error = $res['message'];
        } else {
            $id = (int)$res['id'];
            if ($action === 'proforma' && $kind === 'sale') {
                // ⛔ پیش‌فاکتور = همین پیش‌نویس با شماره‌ی خودش؛ اثری ندارد (`markProforma()`)
                $pf = BizInvoices::markProforma($userId, $id, (int)toLatinDigits((string)postParam('proforma_days', (string)BizInvoices::PROFORMA_DAYS)));
                BizOnce::done(Biz::url('invoice-edit.php?id=' . $id));
                if (!$pf['ok']) { redirectWithMessage(Biz::url('invoice-edit.php?id=' . $id), 'error', $pf['message']); }
                header('Location: ' . Biz::url('print.php?doc=invoice&id=' . $id));
                exit;
            }
            if ($action === 'save') {
                BizOnce::done(Biz::url('invoice-edit.php?id=' . $id));
                redirectWithMessage(Biz::url('invoice-edit.php?id=' . $id), 'success', $res['message']);
            }
            $r = BizInvoices::issue($userId, $id, [
                'account_id' => $form['account_id'], 'method' => $form['method'],
                'full'       => $form['pay_mode'] === 'full',
                'amount'     => $form['pay_mode'] === 'part' ? $form['pay_amount'] : '0',
                'part'       => $form['pay_mode'] === 'part',
                // ⛔ گذر از سقفِ اعتبار فقط با دسترسیِ مالی و تیکِ صریح
                'over_credit' => postParam('over_credit') === '1' && Biz::can('money'),
            ]);
            // پیش‌نویس در هر حال ساخته شده: ارسالِ دوباره همان را باز می‌کند، نه سندِ دوم
            BizOnce::done(Biz::url(($r['ok'] ? 'invoice.php?id=' : 'invoice-edit.php?id=') . $id));
            if ($r['ok']) {
                if ($action === 'issue_print') {
                    // برگه‌ی چاپ پیام نشان نمی‌دهد؛ پیامِ «صادر شد» نباید روی صفحه‌ی بعدی بماند
                    header('Location: ' . Biz::url('print.php?doc=invoice&id=' . $id));
                    exit;
                }
                redirectWithMessage(Biz::url('invoice.php?id=' . $id), ($r['imei_warn'] ?? '') !== '' ? 'warning' : 'success', $r['message']);
            }
            // ⚠ پیش‌نویس ذخیره شده؛ فقط صدور انجام نشد — کاربر روی همان پیش‌نویس می‌ماند
            redirectWithMessage(Biz::url('invoice-edit.php?id=' . $id), 'error', $r['message'] . ' (پیش‌نویس ذخیره شد.)');
        }
    }
}

// جمع‌ها برای نمایش — همیشه از سرور (همان توابعِ ذخیره)
$parsed = BizInvoices::parseLines($userId, array_map(fn($l) => [
    'item' => $l['item'] ?? $l['description'] ?? '', 'product_id' => $l['product_id'] ?? '',
    'qty' => isset($l['qty']) ? (string)$l['qty'] : '', 'price' => $l['price'] ?? (isset($l['unit_price']) ? (string)$l['unit_price'] : ''),
    'disc' => $l['disc'] ?? (isset($l['line_discount']) ? (string)$l['line_discount'] : ''),
    'imei1' => (string)($l['imei1'] ?? ''), 'imei2' => (string)($l['imei2'] ?? ''), 'note' => (string)($l['note'] ?? ''),
    'part_no' => (string)($l['part_no'] ?? ''),
], $form['lines']));
$form['lines'] = BizDocView::mergeMeta($form['lines'], $parsed['meta']);
$vatShow = Biz::accReady() && (Biz::vatRate($userId) > 0 || (float)($inv['vat_rate'] ?? 0) > 0);
$vatNow  = BizInvoices::vatFromForm($userId, $form['vat_rate'], 0.0);
$tot = BizInvoices::totals($parsed['lines'], sanitizeAmount($form['discount']), sanitizeAmount($form['extra']),
                           $vatNow['ok'] ? (float)$vatNow['rate'] : 0.0);
// جمعِ هر ردیف کنارِ خودش (فرمِ ردشده جمع ندارد)
foreach ($form['lines'] as $k => $l) {
    if (!isset($l['line_total']) && isset($parsed['lines'][$k]) && count($parsed['lines']) === count($form['lines'])) {
        $form['lines'][$k]['line_total'] = $parsed['lines'][$k]['line_total'];
    }
}
$parties  = BizDocView::parties($userId);
$partyLbl = BizDocView::SIDES[$side]['party'];
$isSale   = $kind === 'sale';

// ⚠ کسریِ موجودی — فقط نمایش؛ سدِ واقعی هنگامِ صدور در BizStock است. حالا برای
//   فاکتورِ **تازه** هم (پیش از این فقط پیش‌نویسِ ذخیره‌شده هشدار داشت، یعنی کسری
//   را تازه بعد از زدنِ «صدور» می‌فهمیدید)، و زیرِ هر ردیف دکمه‌ی «+ موجودی».
$stockWarn = [];
$short = [];   // اندیسِ ردیف → ['stock' => …, 'unit' => …]
if ($isSale) {
    $need = []; $rowsOf = [];
    foreach ($form['lines'] as $k => $l) {
        $pid = (int)($l['product_id'] ?? 0);
        if ($pid <= 0) { continue; }
        $need[$pid] = ($need[$pid] ?? 0.0) + (trim((string)($l['qty'] ?? '')) === '' ? 1.0 : sanitizeQty((string)$l['qty']));
        $rowsOf[$pid][] = $k;
    }
    if ($need) {
        $ids  = array_keys($need);
        $ph   = implode(',', array_map(fn($i) => ':p' . $i, array_keys($ids)));
        $st   = Database::getConnection()->prepare("SELECT id, name, unit, stock_qty FROM biz_products
                                                    WHERE user_id = :u AND track_stock = 1 AND id IN ({$ph})");
        $bind = ['u' => $userId];
        foreach ($ids as $i => $pid) { $bind['p' . $i] = $pid; }
        $st->execute($bind);
        foreach ($st->fetchAll() as $p) {
            $pid = (int)$p['id'];
            if ($need[$pid] <= (float)$p['stock_qty'] + 0.0005) { continue; }
            $stockWarn[] = '«' . $p['name'] . '»: موجودی ' . formatQty($p['stock_qty']) . ' ' . $p['unit'];
            foreach ($rowsOf[$pid] as $k) { $short[$k] = ['stock' => (float)$p['stock_qty'], 'unit' => (string)$p['unit']]; }
        }
    }
}
$suppliers = $sp['open'] ? BizQuickBuy::suppliers($userId) : [];
// «خرید خورده، موجودی نخورده» و «از چه کسی خریدم» — فقط وقتی پنل باز است
$spPending = $sp['open'] && $sp['product_id'] > 0 ? BizQuickBuy::pending($userId, $sp['product_id']) : ['drafts' => [], 'loose' => []];
$spLast    = $sp['open'] && $sp['product_id'] > 0 ? (BizQuickBuy::history($userId, $sp['product_id'], 1)[0] ?? null) : null;

// ⛔ گزینه‌ی «هشدار زیرِ بهای خرید» — فقط هشدار؛ صدور را نمی‌بندد
$invPrefs = Biz::invoicePrefs($userId);
$costWarn = $inv && $invPrefs['warn_below_cost'] ? BizInvoices::belowCost($inv) : [];

$pageTitle = $inv ? BizInvoices::title($inv) : BizInvoices::KINDS[$kind] . 'ِ تازه';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <div>
        <a class="st-back" href="<?= h(Biz::url(BizDocView::SIDES[$side]['page'])) ?>">‹ <?= h(BizDocView::SIDES[$side]['title']) ?></a>
        <h1 class="st-h1"><?= h($inv ? BizInvoices::title($inv) : BizInvoices::KINDS[$kind] . 'ِ تازه') ?></h1>
    </div>
    <?php if ($inv && !empty($inv['proforma_no'])): ?>
        <span class="st-pill is-draft">پیش‌فاکتورِ <?= toPersianDigits((string)$inv['proforma_no']) ?><?= !empty($inv['proforma_until']) ? ' · اعتبار تا ' . toJalali((string)$inv['proforma_until']) : '' ?></span>
        <a class="st-btn st-btn-ghost st-btn-sm" href="<?= h(Biz::url('print.php?doc=invoice&id=' . (int)$inv['id'])) ?>">چاپِ پیش‌فاکتور</a>
    <?php elseif ($inv): ?><span class="st-pill is-draft">پیش‌نویس — هنوز اثری ندارد</span><?php endif; ?>
</div>

<?php if ($error !== ''): ?><div class="st-flash st-flash-err" role="alert"><?= h($error) ?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="st-flash st-flash-ok" role="status"><?= h($notice) ?></div><?php endif; ?>
<?php if ($spDone !== ''): ?>
<div class="st-flash st-flash-ok" role="status" data-sp-done><?= h($spDone) ?> <a href="<?= h($spDoneUrl) ?>" target="_blank" rel="noopener">دیدنِ فاکتورِ خرید ›</a></div>
<?php endif; ?>
<?php if ($stockWarn): ?>
<div class="st-flash st-flash-warn" role="status" data-stock-warn>بیش از موجودی — صدور انجام نمی‌شود تا موجودی برسد: <?= h(implode('، ', $stockWarn)) ?>. با «+ موجودی» زیرِ همان ردیف خریدش را همین‌جا ثبت کنید.</div>
<?php endif; ?>
<?php if (($lw = BizSerial::luhnMessage(BizSerial::luhnWarnings($parsed['lines']))) !== ''): /* فقط هشدار — صدور بسته نیست */ ?>
<div class="st-flash st-flash-warn" role="status" data-imei-luhn><?= h($lw) ?></div>
<?php endif; ?>
<?php if (($tw = BizSerial::tacMessage(BizSerial::tacWarnings(Database::getConnection(), $userId, $parsed['lines'], $id))) !== ''): /* TAC: فقط هشدار */ ?>
<div class="st-flash st-flash-warn" role="status" data-imei-tac><?= h($tw) ?></div>
<?php endif; ?>
<?php if ($costWarn): ?>
<div class="st-flash st-flash-warn" role="status">زیرِ بهای خرید: <?= h(implode('، ', array_map(fn($w) => '«' . $w['desc'] . '» ' . formatMoney($w['per']) . ' (بها ' . formatMoney($w['cost']) . ')', $costWarn))) ?></div>
<?php endif; ?>

<form method="post" class="st-docform" action="<?= h($self) ?>" data-invoice data-price="<?= $isSale ? 'sell' : 'buy' ?>"<?= $isSale ? ' data-sp-ok' : '' ?>>
    <?= Csrf::field() ?><?= BizOnce::field() ?>
    <section class="st-card st-doc-head">
        <div class="st-row2">
            <label class="st-field">
                <span><?= h($partyLbl) ?></span>
                <select name="party_id" data-party>
                    <option value="0"><?= $isSale ? 'مشتریِ گذری (نقدی)' : 'فروشنده‌ی گذری (نقدی)' ?></option>
                    <?php foreach ($parties as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" data-balance="<?= (int)$p['balance'] ?>" data-level="<?= h((string)($p['price_level'] ?? 'retail')) ?>"<?= !empty($p['fx_code']) ? ' data-fx="' . h(BizFx::label((string)$p['fx_code'])) . '"' : '' ?><?= (int)$p['id'] === (int)$form['party_id'] ? ' selected' : '' ?>><?= h($p['name']) ?><?= !empty($p['fx_code']) ? ' (' . h(BizFx::label((string)$p['fx_code'])) . ')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
                <a class="st-field-link" href="<?= h(Biz::url('party.php?kind=' . ($isSale ? 'customer' : 'supplier'))) ?>">+ <?= h($partyLbl) ?>ِ تازه</a>
            </label>
            <label class="st-field">
                <span>تاریخ</span>
                <input data-num type="text" name="inv_date" value="<?= h((string)$form['inv_date']) ?>" dir="ltr" placeholder="۱۴۰۵/۰۷/۰۵" inputmode="numeric">
            </label>
        </div>
        <?php if (array_filter($parties, fn($p) => !empty($p['fx_code']))): /* ⛔ فقط وقتی طرف‌حسابِ ارزی هست؛ store.js برای طرف‌حسابِ تومانی پنهانش می‌کند */ ?>
        <label class="st-field" data-fx-field>
            <span>نرخِ ارز <small class="st-muted">(فقط طرف‌حسابِ ارزی — تومان برای یک واحد؛ خالی = نرخِ روز)</small></span>
            <input data-money type="text" name="fx_rate" inputmode="decimal" dir="ltr" value="<?= h((string)$form['fx_rate']) ?>">
        </label>
        <?php endif; ?>
        <?php if ($warehouses): ?>
        <label class="st-field">
            <span>انبار</span>
            <select name="warehouse_id">
                <option value="0"><?= h(BizWarehouses::MAIN) ?></option>
                <?php foreach ($warehouses as $w): ?><option value="<?= (int)$w['id'] ?>"<?= (int)$w['id'] === (int)$form['warehouse_id'] ? ' selected' : '' ?>><?= h((string)$w['name']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>
    </section>

    <section class="st-card st-lines-card">
        <div class="st-table-wrap st-lines-wrap">
            <table class="st-table st-lines">
                <?= BizDocView::lineHead() ?>
                <tbody data-lines><?= BizDocView::lineRows($form['lines'], $blank, true, $short) ?></tbody>
            </table>
        </div>
        <div class="st-lines-tools">
            <button type="submit" name="action" value="addrows" class="st-link-btn" formnovalidate data-add-rows>+ ردیفِ بیشتر</button>
            <span class="st-muted-i">چند حرف از نام، کد یا IMEI را بنویسید و از فهرست انتخاب کنید؛ کالای تازه با «+». گوشی: هر ردیف یک دستگاه با IMEIِ خودش. چیزی که در فهرستِ کالا نیست «شرحِ آزاد» می‌شود و به موجودی دست نمی‌زند.</span>
        </div>
    </section>

    <section class="st-card st-np" id="np" data-np<?= $np['open'] ? '' : ' hidden' ?> aria-labelledby="npTitle">
        <div class="st-np-head">
            <h2 class="st-h3" id="npTitle">تعریفِ کالای تازه</h2>
            <button type="submit" name="action" value="np_cancel" class="st-link-btn" formnovalidate data-np-close>بستن</button>
        </div>
        <?php if ($np['error'] !== ''): ?><div class="st-flash st-flash-err" role="alert"><?= h($np['error']) ?></div><?php endif; ?>
        <input type="hidden" name="np[row]" value="<?= (int)$np['row'] ?>" data-np-row>
        <div class="st-seg st-seg-sm st-np-types" role="radiogroup" aria-label="نوعِ کالا">
            <?php foreach (BizProducts::TYPES as $tk => $tl): ?>
            <label class="st-seg-opt"><input type="radio" name="np[type]" value="<?= h($tk) ?>"<?= $np['type'] === $tk ? ' checked' : '' ?> data-np-type><span><?= h($tl) ?></span></label>
            <?php endforeach; ?>
        </div>
        <div class="st-row2">
            <label class="st-field"><span>نام <small class="st-muted">(مثلاً «آیفون ۱۵ — ۱۲۸ گیگ»)</small></span><input type="text" name="np[name]" value="<?= h($np['name']) ?>" maxlength="<?= BizProducts::LIMITS['name'] ?>" data-np-name></label>
            <label class="st-field"><span>کد یا بارکد <small class="st-muted">(اختیاری)</small></span><input type="text" name="np[sku]" value="<?= h($np['sku']) ?>" dir="ltr" maxlength="<?= BizProducts::LIMITS['sku'] ?>"></label>
        </div>
        <div class="st-row2">
            <label class="st-field"><span>قیمتِ خرید</span><input data-money type="text" name="np[buy]" value="<?= h($np['buy']) ?>" inputmode="numeric" dir="ltr"></label>
            <label class="st-field"><span>قیمتِ فروش</span><input data-money type="text" name="np[sell]" value="<?= h($np['sell']) ?>" inputmode="numeric" dir="ltr"></label>
        </div>
        <div class="st-row2" data-np-imei>
            <label class="st-field"><span>IMEI ۱ <small class="st-muted">(فقط گوشی)</small></span><input type="text" name="np[imei1]" value="<?= h($np['imei1']) ?>" inputmode="numeric" dir="ltr" autocomplete="off"></label>
            <label class="st-field"><span>IMEI ۲ <small class="st-muted">(گوشیِ دوسیم‌کارت)</small></span><input type="text" name="np[imei2]" value="<?= h($np['imei2']) ?>" inputmode="numeric" dir="ltr" autocomplete="off"></label>
        </div>
        <p class="st-muted">کالا در فهرستِ کالاها ثبت می‌شود و همین‌جا در ردیفِ فاکتور می‌نشیند (قیمتِ <?= $isSale ? 'فروش' : 'خرید' ?>، تعدادِ ۱). موجودی فقط با صدورِ فاکتور عوض می‌شود.</p>
        <button type="submit" name="action" value="np_save" class="st-btn" formnovalidate>ثبتِ کالا و افزودن به فاکتور</button>
    </section>

    <?php if ($sp['open']): ?>
    <section class="st-card st-np st-sp" id="sp" data-sp aria-labelledby="spTitle">
        <div class="st-np-head">
            <h2 class="st-h3" id="spTitle">تأمینِ موجودیِ «<?= h($sp['name']) ?>»</h2>
            <button type="submit" name="action" value="sp_cancel" class="st-link-btn" formnovalidate>بستن</button>
        </div>
        <?php if ($sp['error'] !== ''): ?><div class="st-flash st-flash-err" role="alert"><?= h($sp['error']) ?></div><?php endif; ?>
        <input type="hidden" name="sp[row]" value="<?= (int)$sp['row'] ?>">
        <input type="hidden" name="sp[product_id]" value="<?= (int)$sp['product_id'] ?>">
        <p class="st-muted st-sp-meta">
            <?php if ($spLast): ?>آخرین خرید: <?= h(toPersianDigits(formatQty($spLast['qty']))) ?> <?= h($sp['unit']) ?> از <b><?= h($spLast['party']) ?></b>
                به <?= h(formatMoney($spLast['price'])) ?> در <?= h(toPersianDigits(toJalali($spLast['date']))) ?> ·
            <?php else: ?>این کالا هنوز هیچ خریدِ ثبت‌شده‌ای ندارد ·
            <?php endif; ?>
            <?php /* ⛔ برگه‌ی تازه: فاکتورِ نیمه‌کاره‌ی این برگه دست نمی‌خورد */ ?>
            <a href="<?= h(Biz::url('product.php?id=' . (int)$sp['product_id'])) ?>" target="_blank" rel="noopener">ویرایشِ مشخصاتِ کالا ↗</a>
        </p>
        <?php if ($spPending['drafts'] || $spPending['loose']): ?>
        <div class="st-sp-pending">
            <p class="st-sp-pending-title"><b>خریدش قبلاً ثبت شده؟</b> فقط موجودی بدهید — خریدِ دوم ساخته نمی‌شود:</p>
            <?php foreach ($spPending['drafts'] as $d): ?>
            <div class="st-sp-pending-row">
                <span><?= h($d['title']) ?> از <?= h($d['party']) ?> · <?= h(toPersianDigits(formatQty($d['qty']))) ?> <?= h($sp['unit']) ?> · <span class="st-muted-i">هنوز صادر نشده</span></span>
                <button type="submit" name="sp_issue" value="<?= (int)$d['invoice_id'] ?>" class="st-btn st-btn-ghost st-btn-sm" formnovalidate>صدورِ همین پیش‌نویس</button>
            </div>
            <?php endforeach; ?>
            <?php foreach ($spPending['loose'] as $l): ?>
            <div class="st-sp-pending-row<?= $l['same'] ? ' is-same' : '' ?>">
                <span>«<?= h($l['desc']) ?>» در <?= h($l['title']) ?> از <?= h($l['party']) ?> · <?= h(toPersianDigits(formatQty($l['qty']))) ?> × <?= h(formatMoney($l['price'])) ?> · <?= h(toPersianDigits(toJalali($l['date']))) ?>
                    <span class="st-muted-i">— شرحِ آزاد، به انبار نرفته</span></span>
                <button type="submit" name="sp_link" value="<?= (int)$l['line_id'] ?>" class="st-btn st-btn-ghost st-btn-sm" formnovalidate
                        onclick="return confirm('این ردیف به همین کالا وصل شود؟ موجودی‌اش به انبار می‌آید؛ مبلغ و فروشنده همان می‌ماند.');">فقط موجودی بده</button>
            </div>
            <?php endforeach; ?>
        </div>
        <p class="st-muted st-sp-or">خریدِ تازه است؟</p>
        <?php endif; ?>
        <div class="st-row2">
            <label class="st-field"><span>از چه کسی خریدید؟</span>
                <select name="sp[party_id]" autofocus>
                    <option value="0">— فروشنده‌ی تازه یا گذری —</option>
                    <?php foreach ($suppliers as $p): ?>
                    <option value="<?= (int)$p['id'] ?>"<?= (int)$p['id'] === (int)$sp['party_id'] ? ' selected' : '' ?>><?= h($p['name']) ?><?= $p['supplier'] ? '' : ' (مشتری)' ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="st-field"><span>یا نامِ فروشنده‌ی تازه <small class="st-muted">(تأمین‌کننده ساخته می‌شود)</small></span>
                <input type="text" name="sp[party_name]" value="<?= h($sp['party_name']) ?>" maxlength="150" autocomplete="off">
            </label>
        </div>
        <?php if ($sp['serial']): ?>
        <label class="st-field"><span>IMEIِ گوشی‌های خریده‌شده <small class="st-muted">(هر خط یک گوشی؛ دوسیم‌کارت: «IMEI۱ / IMEI۲»)</small></span>
            <textarea name="sp[imeis]" rows="3" dir="ltr" inputmode="numeric" autocomplete="off"><?= h($sp['imeis']) ?></textarea>
        </label>
        <?php endif; ?>
        <div class="st-row2">
            <?php if (!$sp['serial']): ?>
            <label class="st-field"><span>تعداد (<?= h($sp['unit']) ?>)</span><input data-num type="text" name="sp[qty]" value="<?= h($sp['qty']) ?>" inputmode="decimal" dir="ltr"></label>
            <?php endif; ?>
            <label class="st-field"><span>قیمتِ خریدِ هر <?= h($sp['unit'] !== '' ? $sp['unit'] : 'واحد') ?></span><input data-money type="text" name="sp[buy]" value="<?= h($sp['buy']) ?>" inputmode="numeric" dir="ltr"></label>
        </div>
        <div class="st-row2">
            <label class="st-field"><span>قیمتِ فروش</span><input data-money type="text" name="sp[sell]" value="<?= h($sp['sell']) ?>" inputmode="numeric" dir="ltr"></label>
            <label class="st-field"><span>تاریخِ خرید</span><input data-num type="text" name="sp[date]" value="<?= h($sp['date']) ?>" inputmode="numeric" dir="ltr"></label>
        </div>
        <div class="st-row2">
            <div class="st-seg st-seg-sm" role="radiogroup" aria-label="پرداخت">
                <label class="st-seg-opt"><input type="radio" name="sp[pay]" value="credit"<?= $sp['pay'] === 'credit' ? ' checked' : '' ?>><span>نسیه — طلبِ فروشنده</span></label>
                <label class="st-seg-opt"><input type="radio" name="sp[pay]" value="cash"<?= $sp['pay'] === 'cash' ? ' checked' : '' ?>><span>نقد — پرداخت شد</span></label>
            </div>
            <label class="st-field"><span>صندوق <small class="st-muted">(برای نقد)</small></span>
                <select name="sp[account_id]"><?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id'] ?>"<?= (int)$a['id'] === (int)$sp['account_id'] ? ' selected' : '' ?>><?= h($a['name']) ?></option><?php endforeach; ?></select>
            </label>
        </div>
        <p class="st-muted">یک <b>فاکتورِ خریدِ صادرشده</b> ساخته می‌شود: موجودی و بهای خرید درست می‌شود و نسیه به حسابِ فروشنده می‌رود. این فاکتورِ فروش دست نمی‌خورد؛ بعد از خرید، صدورش را بزنید.</p>
        <button type="submit" name="action" value="sp_save" class="st-btn" formnovalidate>ثبتِ خرید و افزودنِ موجودی</button>
    </section>
    <?php endif; ?>

    <div class="st-doc-foot">
        <section class="st-card st-sumbox">
            <div class="st-sum-line"><span>جمعِ ردیف‌ها</span><b class="st-num" data-subtotal><?= formatMoney($tot['subtotal']) ?></b></div>
            <label class="st-sum-line"><span>تخفیفِ فاکتور</span><input data-money type="text" name="discount" value="<?= h((string)$form['discount']) ?>" inputmode="numeric" dir="ltr" data-discount></label>
            <?php if ($loyaltyOn): $lb = (int)$form['party_id'] > 0 ? BizLoyalty::balance($userId, (int)$form['party_id'], $inv ? (int)$id : 0) : null; ?>
            <div class="st-sum-line" data-loyalty-box><span>امتیازِ باشگاه<?= $lb !== null ? ' <small class="st-muted-i">(موجود ' . toPersianDigits((string)$lb['balance']) . ')</small>' : '' ?></span>
                <span><input data-num type="text" name="loyalty_points" value="<?= h((string)$form['loyalty_points']) ?>" inputmode="numeric" dir="ltr" size="5">
                <button type="submit" name="action" value="loyalty_apply" class="st-link-btn" formnovalidate>به تخفیف</button></span></div>
            <?php endif; ?>
            <label class="st-sum-line"><span>حمل و هزینه‌ی دیگر</span><input data-money type="text" name="extra" value="<?= h((string)$form['extra']) ?>" inputmode="numeric" dir="ltr" data-extra></label>
            <?php if ($vatShow): /* ⛔ مالیات بر ارزش افزوده — نرخِ همین سند؛ کالای معاف صفر */ ?>
            <label class="st-sum-line"><span>مالیات بر ارزش افزوده (٪)</span><input data-num type="text" name="vat_rate" value="<?= h((string)$form['vat_rate']) ?>" inputmode="decimal" dir="ltr" data-vat-rate></label>
            <div class="st-sum-line"><span>مبلغِ مالیات</span><b class="st-num" data-tax><?= formatMoney($tot['tax']) ?></b></div>
            <?php endif; ?>
            <div class="st-sum-line st-sum-total"><span>مبلغِ فاکتور</span><b class="st-num" data-total><?= formatMoney($tot['total']) ?></b></div>
            <?php
            // ⛔ مانده‌ی قبلی = مانده‌ی امروزِ طرف‌حساب (پیش‌نویس هنوز اثری ندارد) —
            //    همان `BALANCE_SQL` که `parties()` خوانده، پس کوئریِ تازه‌ای نیست.
            //    «پس از این فاکتور» فقط پیش‌نمایش است؛ عددِ واقعی را صدور می‌سازد.
            $prevBal = 0;
            foreach ($parties as $p) { if ((int)$p['id'] === (int)$form['party_id']) { $prevBal = (int)$p['balance']; } }
            $payNow  = $form['pay_mode'] === 'full' ? $tot['total'] : ($form['pay_mode'] === 'part' ? min(sanitizeAmount($form['pay_amount']), $tot['total']) : 0);
            $afterBal = $prevBal + ($isSale ? 1 : -1) * ($tot['total'] - $payNow);
            ?>
            <div class="st-balbox" data-balbox data-sign="<?= $isSale ? 1 : -1 ?>"<?= (int)$form['party_id'] > 0 ? '' : ' hidden' ?>>
                <div class="st-sum-line is-prev"><span>مانده‌ی قبلی <small data-bal-side><?= h(BizParties::sideLabel($prevBal)) ?></small></span><b class="st-num" data-bal-prev><?= formatMoney(abs($prevBal)) ?></b></div>
                <div class="st-sum-line is-after"><span>مانده‌ی کل پس از این فاکتور <small data-after-side><?= h(BizParties::sideLabel($afterBal)) ?></small></span><b class="st-num" data-bal-after><?= formatMoney(abs($afterBal)) ?></b></div>
                <a class="st-field-link" data-bal-link href="<?= h(Biz::url('party.php' . ((int)$form['party_id'] > 0 ? '?id=' . (int)$form['party_id'] : ''))) ?>">صورت‌حساب و اصلاحِ مانده‌ی اول دوره ›</a>
            </div>
            <p class="st-muted st-unit-note">مبلغ‌ها به تومان.</p>
        </section>
        <section class="st-card st-paybox">
            <h2 class="st-h3"><?= $isSale ? 'دریافت همزمان' : 'پرداخت همزمان' ?></h2>
            <div class="st-seg st-seg-sm">
                <?php foreach (['none' => $isSale ? 'نسیه' : 'نسیه', 'full' => 'کامل', 'part' => 'بخشی'] as $pm => $pl): ?>
                <label class="st-seg-opt"><input type="radio" name="pay_mode" value="<?= $pm ?>"<?= $form['pay_mode'] === $pm ? ' checked' : '' ?> data-paymode><span><?= h($pl) ?></span></label>
                <?php endforeach; ?>
            </div>
            <div class="st-row2">
                <label class="st-field"><span>مبلغ (برای «بخشی»)</span><input data-money type="text" name="pay_amount" value="<?= h((string)$form['pay_amount']) ?>" inputmode="numeric" dir="ltr" data-payamount></label>
                <label class="st-field"><span>روش</span>
                    <select name="method"><?php foreach (BizPay::quickMethods() as $mk => $ml): ?><option value="<?= h($mk) ?>"<?= $mk === $form['method'] ? ' selected' : '' ?>><?= h($ml) ?></option><?php endforeach; ?></select>
                </label>
            </div>
            <label class="st-field"><span>صندوق</span>
                <select name="account_id" data-acc-auto><?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id'] ?>" data-kind="<?= h((string)($a['kind'] ?? '')) ?>"<?= (int)$a['id'] === (int)$form['account_id'] ? ' selected' : '' ?>><?= h($a['name']) ?></option><?php endforeach; ?></select>
            </label>
            <p class="st-muted">بی‌<?= h($partyLbl) ?> (گذری) فقط با تسویه‌ی کامل صادر می‌شود.</p>
        </section>
    </div>

    <section class="st-card">
        <label class="st-field"><span>توضیح <small class="st-muted">(اختیاری — روی فاکتور چاپ می‌شود)</small></span>
            <textarea name="note" rows="2" maxlength="<?= BizInvoices::NOTE_MAX ?>"><?= h((string)$form['note']) ?></textarea>
        </label>
    </section>

    <div class="st-actionbar">
        <?php if ($isSale && Biz::can('money') && BizParties::termsReady()): ?>
        <label class="st-check st-over-credit"><input type="checkbox" name="over_credit" value="1"> گذر از سقفِ اعتبار</label>
        <?php endif; ?>
        <button type="submit" name="action" value="issue_print" class="st-btn">صدور و چاپ</button>
        <button type="submit" name="action" value="issue" class="st-btn st-btn-ghost">صدور</button>
        <button type="submit" name="action" value="save" class="st-btn st-btn-ghost" formnovalidate>ذخیره‌ی پیش‌نویس</button>
        <?php if ($isSale && BizInvoices::proformaReady()): ?>
        <span class="st-proforma-act">
            <button type="submit" name="action" value="proforma" class="st-btn st-btn-ghost" formnovalidate><?= !empty($inv['proforma_no']) ? 'به‌روزرسانی و چاپِ پیش‌فاکتور' : 'پیش‌فاکتور و چاپ' ?></button>
            <label class="st-inline-label">اعتبار <input data-num type="text" name="proforma_days" inputmode="numeric" dir="ltr" size="3" value="<?= (int)BizInvoices::PROFORMA_DAYS ?>" aria-label="اعتبارِ پیش‌فاکتور (روز)"> روز</label>
        </span>
        <?php endif; ?>
    </div>
</form>

<?php if ($inv): ?>
<form method="post" action="<?= h($self) ?>" class="st-danger-row st-after" onsubmit="return confirm('این پیش‌نویس حذف شود؟');">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="delete">
    <button type="submit" class="st-link-btn st-link-danger">حذفِ پیش‌نویس</button>
</form>
<?php endif; ?>
<?= BizDocView::productDatalist($userId, 'bizProducts', 2000, $isSale) ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
