<?php
/**
 * ⛔ جستجوی سراسریِ فروشگاه — مشتری و تأمین‌کننده، محصول (نام، کد، IMEI)،
 *    فاکتور (شماره، طرف‌حساب)، دریافت و پرداخت، چک (شماره) و صندوق.
 *
 * **خواسته‌ی مالکِ نصب:** «جستجوی سراسری با Ctrl+K برای مشتری‌ها، محصولات،
 * فاکتورها، تراکنش‌ها، حساب‌ها و چک‌ها.»
 *
 * دو شکلِ خروجی و **یک** منطق:
 * - HTML — صفحه‌ی کاملِ نتیجه؛ همان فرمِ GETِ نوارِ بالا بی‌جاوااسکریپت
 *   به اینجا می‌آید.
 * - `?format=json` — برای فرمانِ سریعِ `store.js` (همان گروه‌ها، کوتاه‌تر).
 * ⛔ فقط خواندنی است (پس عمداً CSRF ندارد) و هر ردیف از همان `list()`های
 *    دامنه می‌آید که صافیِ `user_id` و فرارِ `%`/`_` را دارند — نسخه‌ی دومی
 *    از هیچ جست‌وجویی اینجا نوشته نشده، جز شماره‌ی چک که فهرستِ
 *    دریافت/پرداخت نمی‌گردد.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_docview.php';

$userId = Biz::ownerId();
$q      = mb_substr(trim(getParam('q')), 0, 80);
$json   = getParam('format') === 'json';
$cap    = $json ? 5 : 12;

/** @var array<int,array{label:string,items:array,more:string}> $groups */
$groups = [];
if (mb_strlen($q) >= 1) {
    // ---------- طرف‌حساب ----------
    $pr = BizParties::list($userId, $q);
    $items = [];
    foreach (array_slice($pr['rows'], 0, $cap) as $r) {
        $bal = (int)$r['balance'];
        $items[] = ['t' => (string)$r['name'], 's' => trim((BizParties::KINDS[$r['kind']] ?? '') . ((string)($r['phone'] ?? '') !== '' ? ' · ' . toPersianDigits((string)$r['phone']) : '')),
                    'e' => $bal !== 0 ? BizParties::sideLabel($bal) . ' ' . formatMoney(abs($bal)) : 'تسویه', 'u' => Biz::url('party.php?id=' . (int)$r['id']), 'i' => 'party'];
    }
    $groups[] = ['label' => 'مشتریان و تأمین‌کنندگان', 'items' => $items, 'total' => $pr['total'], 'more' => Biz::url('parties.php?q=' . rawurlencode($q))];

    // ---------- محصول ----------
    $pp = BizProducts::list($userId, $q);
    $items = [];
    foreach (array_slice($pp['rows'], 0, $cap) as $r) {
        $sub = ((string)($r['sku'] ?? '') !== '' ? toPersianDigits((string)$r['sku']) . ' · ' : '')
             . ((int)$r['track_stock'] === 1 ? 'موجودی ' . BizView::qty($r['stock_qty'], (string)$r['unit']) : 'خدمت');
        $items[] = ['t' => (string)$r['name'], 's' => $sub, 'e' => formatMoney((int)$r['sell_price']), 'u' => Biz::url('product.php?id=' . (int)$r['id']), 'i' => 'product'];
    }
    $groups[] = ['label' => 'محصولات', 'items' => $items, 'total' => $pp['total'], 'more' => Biz::url('products.php?q=' . rawurlencode($q))];

    // ---------- فاکتور ----------
    $pi = BizInvoices::list($userId, array_keys(BizInvoices::KINDS), $q);
    $items = [];
    foreach (array_slice($pi['rows'], 0, $cap) as $r) {
        $items[] = ['t' => BizInvoices::title($r), 's' => toJalali((string)$r['inv_date']) . ' · ' . ($r['party_name'] !== null ? (string)$r['party_name'] : 'گذری'),
                    'e' => formatMoney((int)$r['total']), 'u' => Biz::url(($r['status'] === 'draft' ? 'invoice-edit.php' : 'invoice.php') . '?id=' . (int)$r['id']), 'i' => 'invoice'];
    }
    $groups[] = ['label' => 'فاکتورها', 'items' => $items, 'total' => $pi['total'], 'more' => Biz::url('sales.php?q=' . rawurlencode($q))];

    // ---------- دریافت و پرداخت ----------
    $py = BizPay::list($userId, '', 0, $q);
    $items = [];
    foreach (array_slice($py['rows'], 0, $cap) as $r) {
        $items[] = ['t' => (BizPay::KINDS[$r['kind']] ?? '') . ' · ' . BizPay::label($r), 's' => toJalali((string)$r['pay_date']) . ((string)($r['account_name'] ?? '') !== '' ? ' · ' . $r['account_name'] : ''),
                    'e' => formatMoney((int)$r['amount']), 'u' => Biz::url('payment.php?id=' . (int)$r['id']), 'i' => 'payment'];
    }
    $groups[] = ['label' => 'دریافت و پرداخت', 'items' => $items, 'total' => $py['total'], 'more' => Biz::url('payments.php?q=' . rawurlencode($q))];

    // ---------- چک — با شماره‌ی چک (فهرستِ دریافت/پرداخت آن را نمی‌گردد) ----------
    $items = [];
    $qd = toLatinDigits($q);
    if (preg_match('/\d{3,}/', $qd)) {
        try {
            $st = Database::getConnection()->prepare(
                "SELECT y.id, y.kind, y.amount, y.cheque_no, y.cheque_due, p.name AS party_name
                 FROM biz_payments y LEFT JOIN biz_parties p ON p.id = y.party_id AND p.user_id = y.user_id
                 WHERE y.user_id = :u AND y.method = 'cheque' AND y.cheque_no LIKE :q ESCAPE '!'
                 ORDER BY y.id DESC LIMIT :lim"
            );
            $st->bindValue('u', $userId, PDO::PARAM_INT);
            $st->bindValue('q', BizCommon::like($qd));
            $st->bindValue('lim', $cap, PDO::PARAM_INT);
            $st->execute();
            foreach ($st->fetchAll() as $r) {
                $items[] = ['t' => 'چکِ ' . toPersianDigits((string)$r['cheque_no']) . ($r['kind'] === 'receipt' ? ' · دریافتی' : ' · پرداختی'),
                            's' => trim((string)($r['party_name'] ?? '') . ($r['cheque_due'] ? ' · سررسید ' . toJalali((string)$r['cheque_due']) : '')),
                            'e' => formatMoney((int)$r['amount']), 'u' => Biz::url('payment.php?id=' . (int)$r['id']), 'i' => 'cheque'];
            }
        } catch (PDOException $e) {
            if ((string)$e->getCode() !== '42S22') { throw $e; }   // ستونِ چک هنوز نیامده
        }
    }
    $groups[] = ['label' => 'چک‌ها', 'items' => $items, 'total' => count($items), 'more' => Biz::url('cheques.php?f=all')];

    // ---------- صندوق و بانک ----------
    $fq = BizCommon::fold($q);
    $items = [];
    foreach (BizCash::list($userId) as $a) {
        if ((int)$a['is_active'] !== 1 || !str_contains(BizCommon::fold((string)$a['name']), $fq)) { continue; }
        $items[] = ['t' => (string)$a['name'], 's' => BizCash::KINDS[$a['kind']] ?? '', 'e' => formatMoney((int)$a['balance']),
                    'u' => Biz::url('payments.php?acc=' . (int)$a['id']), 'i' => 'account'];
    }
    $groups[] = ['label' => 'صندوق و بانک', 'items' => array_slice($items, 0, $cap), 'total' => count($items), 'more' => Biz::url('accounts.php')];
}

if ($json) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['q' => $q, 'groups' => array_map(fn($g) => ['label' => $g['label'], 'items' => $g['items']], array_values(array_filter($groups, fn($g) => $g['items'])))],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$found = array_sum(array_map(fn($g) => count($g['items']), $groups));
$pageTitle = 'جستجوی سریع';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <div>
        <h1 class="st-h1">جستجوی سریع</h1>
        <p class="st-muted">مشتری، محصول (نام، کد یا IMEI)، شماره‌ی فاکتور، دریافت و پرداخت، شماره‌ی چک و صندوق — همه در یک جا. میان‌بُر: <span dir="ltr">Ctrl + K</span></p>
    </div>
</div>
<form class="st-filters" method="get" action="<?= h(Biz::url('search.php')) ?>" role="search">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="مثلاً نامِ مشتری، مدلِ گوشی یا شماره‌ی فاکتور" autofocus aria-label="عبارتِ جستجو">
    <button type="submit" class="st-btn">جستجو</button>
</form>

<?php if ($q === ''): ?>
<section class="st-card st-empty-card">
    <h2 class="st-h2">دنبالِ چه می‌گردید؟</h2>
    <p class="st-muted">چند حرف از نام، شماره‌ی تلفن، کدِ کالا، IMEI یا شماره‌ی فاکتور بنویسید.</p>
</section>
<?php elseif ($found === 0): ?>
<section class="st-card st-empty-card">
    <h2 class="st-h2">برای «<?= h($q) ?>» چیزی پیدا نشد</h2>
    <p class="st-muted">املای دیگری امتحان کنید، یا همین را تازه تعریف کنید.</p>
    <div class="st-head-actions" style="justify-content:center">
        <a class="st-btn st-btn-ghost" href="<?= h(Biz::url('party.php')) ?>">مشتریِ تازه</a>
        <a class="st-btn st-btn-ghost" href="<?= h(Biz::url('product.php')) ?>">محصولِ تازه</a>
    </div>
</section>
<?php else: ?>
<p class="st-muted"><?= toPersianDigits((string)$found) ?> نتیجه برای «<?= h($q) ?>»</p>
<div class="st-results">
    <?php foreach ($groups as $g): if (!$g['items']) { continue; } ?>
    <section class="st-card">
        <div class="st-card-head">
            <h2 class="st-h2"><?= h($g['label']) ?> <span class="st-muted-i">(<?= toPersianDigits((string)$g['total']) ?>)</span></h2>
            <?php if ($g['total'] > count($g['items'])): ?><a href="<?= h($g['more']) ?>">همه</a><?php endif; ?>
        </div>
        <ul class="st-list">
            <?php foreach ($g['items'] as $it): ?>
            <li class="st-list-row st-result-row">
                <a href="<?= h($it['u']) ?>"><?= h($it['t']) ?><?php if ($it['s'] !== ''): ?><span class="st-muted-i"><?= h($it['s']) ?></span><?php endif; ?></a>
                <span class="st-muted-i st-num"><?= h($it['e']) ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
