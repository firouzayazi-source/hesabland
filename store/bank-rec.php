<?php
/**
 * مغایرت‌گیریِ بانکی — صورت‌حسابِ بانک (اکسل یا CSV) کنارِ اسنادِ همان حساب.
 *
 * منطق فقط `BizBankRec`. ⛔ جفت کردن فقط برچسب است: هیچ سندی ساخته یا عوض نمی‌شود. ردیفِ
 * بی‌جفت یا پولی است که ثبت نشده («ثبتِ سند» فرمِ همیشگی را پیش‌پر باز می‌کند)، یا سندی که
 * هنوز به بانک نرسیده.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_docview.php';
require_once __DIR__ . '/../includes/biz_io.php';

$userId   = Biz::ownerId();
$accounts = array_values(array_filter(BizDocView::accounts($userId), fn($a) => (string)$a['kind'] !== 'cash'))
         ?: BizDocView::accounts($userId);
$accId    = (int)getParam('acc', (string)(int)($accounts[0]['id'] ?? 0));
if (!in_array($accId, array_map(fn($a) => (int)$a['id'], $accounts), true)) { $accId = (int)($accounts[0]['id'] ?? 0); }
$open     = getParam('f', 'open') !== 'all';
$self     = Biz::url('bank-rec.php?acc=' . $accId . ($open ? '' : '&f=all'));

if (BizBankRec::ready() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    switch (postParam('action')) {
        case 'import':
            $acc = (int)postParam('account_id');
            $f = $_FILES['file'] ?? null;
            $bytes = is_array($f) && ($f['error'] ?? 1) === UPLOAD_ERR_OK && is_uploaded_file((string)$f['tmp_name'])
                ? (string)file_get_contents((string)$f['tmp_name'], false, null, 0, BizSheet::MAX_BYTES + 1) : '';
            $sheet = BizSheet::read($bytes);
            $p = $sheet['ok'] ? BizBankRec::parse($sheet['rows'], postParam('rial') === '1') : $sheet;
            $r = $p['ok'] ? BizBankRec::import($userId, $acc, $p['lines']) : $p;
            if ($r['ok'] && !empty($p['skipped'])) { $r['message'] .= ' ' . toPersianDigits((string)$p['skipped']) . ' ردیفِ بی‌تاریخ یا بی‌مبلغ کنار رفت.'; }
            $self = Biz::url('bank-rec.php?acc=' . $acc);
            break;
        case 'auto':
            $n = BizBankRec::autoMatch($userId, $accId);
            $r = ['ok' => true, 'message' => toPersianDigits((string)$n) . ' جفتِ تازه پیدا شد.'];
            break;
        case 'match':   $r = BizBankRec::match($userId, (int)postParam('line'), (int)postParam('payment')); break;
        case 'unmatch': $r = BizBankRec::unmatch($userId, (int)postParam('line')); break;
        case 'delete':  $r = BizBankRec::remove($userId, (int)postParam('line')); break;
        default: $r = ['ok' => false, 'message' => 'درخواست نامعتبر است.'];
    }
    redirectWithMessage($self, $r['ok'] ? 'success' : 'error', $r['message']);
}

$data  = $accId > 0 ? BizBankRec::lines($userId, $accId, $open) : ['rows' => [], 'open' => 0, 'open_sum' => 0, 'matched' => 0, 'from' => null, 'to' => null];
$pays  = $accId > 0 && $data['from'] !== null ? BizBankRec::openPayments($userId, $accId, $data['from'], $data['to']) : [];
$byAmt = [];
foreach ($pays as $p) { $byAmt[(int)$p['signed']][] = $p; }
$payLabel = fn(array $p): string => BizPay::KINDS[$p['kind']] . ' ' . toPersianDigits((string)$p['number']) . ' — ' . toJalali((string)$p['pay_date'])
    . ($p['party_name'] !== null ? ' — ' . $p['party_name'] : ($p['title'] !== null ? ' — ' . $p['title'] : ''));

$pageTitle = 'مغایرت‌گیریِ بانکی';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <div>
        <h1 class="st-h1">مغایرت‌گیریِ بانکی</h1>
        <p class="st-muted">صورت‌حسابِ بانک را وارد کنید؛ هر ردیف با سندِ هم‌مبلغِ چند روزِ نزدیک خودکار جفت می‌شود. آنچه می‌ماند یا پولی است که ثبت نکرده‌اید، یا سندی که هنوز به بانک نرسیده.</p>
    </div>
</div>

<?php if (!BizBankRec::ready()): ?>
    <div class="st-flash st-flash-err">این بخش هنوز راه نیفتاده است؛ مدیرِ نصب باید به‌روزرسانی را با migration اجرا کند.</div>
<?php else: ?>

<form method="post" action="<?= h($self) ?>" enctype="multipart/form-data" class="st-card st-form">
    <?= Csrf::field() ?><input type="hidden" name="action" value="import">
    <h2 class="st-h2">واردِ صورت‌حساب</h2>
    <div class="st-row3">
        <label class="st-field"><span>حساب</span>
            <select name="account_id"><?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id'] ?>"<?= (int)$a['id'] === $accId ? ' selected' : '' ?>><?= h((string)$a['name']) ?></option><?php endforeach; ?></select></label>
        <label class="st-field"><span>فایلِ اکسل (xlsx) یا CSV</span><input type="file" name="file" accept=".xlsx,.csv,text/csv" required></label>
        <label class="st-check"><input type="checkbox" name="rial" value="1" checked> مبلغ‌های فایل ریال است</label>
    </div>
    <p class="st-muted">ستون‌های «تاریخ» و «مبلغ» (یا «واریز/برداشت»، «بستانکار/بدهکار») و اگر بود «شرح» خودکار پیدا می‌شوند. ورودِ دوباره‌ی همان فایل ردیفِ تکراری نمی‌سازد.</p>
    <button type="submit" class="st-btn">ورود و جفت کردن</button>
</form>

<?php if ($accId > 0): ?>
<div class="st-kpis st-kpis-3">
    <div class="st-kpi-card"><span class="st-kpi-label">ردیفِ جفت‌شده</span><span class="st-kpi-value st-num"><?= toPersianDigits((string)$data['matched']) ?></span></div>
    <div class="st-kpi-card"><span class="st-kpi-label">ردیفِ بی‌سند</span><span class="st-kpi-value st-num<?= $data['open'] ? ' is-neg' : '' ?>"><?= toPersianDigits((string)$data['open']) ?></span><span class="st-kpi-sub">جمع <?= formatMoney($data['open_sum']) ?> تومان</span></div>
    <div class="st-kpi-card"><span class="st-kpi-label">سندِ بی‌ردیف (در بازه‌ی صورت‌حساب)</span><span class="st-kpi-value st-num"><?= toPersianDigits((string)count($pays)) ?></span></div>
</div>

<nav class="st-tabs" aria-label="نمایش">
    <a class="st-tab-chip<?= $open ? ' is-active' : '' ?>" href="<?= h(Biz::url('bank-rec.php?acc=' . $accId)) ?>">فقط بی‌سند</a>
    <a class="st-tab-chip<?= !$open ? ' is-active' : '' ?>" href="<?= h(Biz::url('bank-rec.php?acc=' . $accId . '&f=all')) ?>">همه‌ی ردیف‌ها</a>
    <form method="post" action="<?= h($self) ?>" class="st-inline"><?= Csrf::field() ?><button type="submit" name="action" value="auto" class="st-link-btn">جفت کردنِ دوباره</button></form>
</nav>

<?php if (!$data['rows']): ?>
    <div class="st-card st-empty-card"><p class="st-empty"><?= $open && $data['matched'] ? 'همه‌ی ردیف‌ها سند دارند.' : 'هنوز صورت‌حسابی برای این حساب وارد نشده است.' ?></p></div>
<?php else: ?>
<div class="st-table-wrap">
    <table class="st-table st-table-compact">
        <thead><tr><th>تاریخ</th><th>شرحِ بانک</th><th class="st-th-num">مبلغ</th><th>سند</th></tr></thead>
        <tbody>
        <?php foreach ($data['rows'] as $l): $amt = (int)$l['amount']; ?>
            <tr>
                <td><span class="st-num"><?= h(toJalali((string)$l['line_date'])) ?></span></td>
                <td><?= h((string)($l['descr'] ?? '')) ?></td>
                <td class="st-td-num"><span class="st-num<?= $amt < 0 ? ' is-neg' : '' ?>"><?= $amt < 0 ? '−' : '+' ?><?= formatMoney(abs($amt)) ?></span></td>
                <td>
                <?php if ($l['payment_id'] !== null): ?>
                    <a class="st-row-link" href="<?= h(Biz::url('payment.php?id=' . (int)$l['payment_id'])) ?>"><?= h((BizPay::KINDS[$l['pay_kind']] ?? 'سند') . ' ' . toPersianDigits((string)$l['pay_number'])) ?></a>
                    <small class="st-muted-i"><?= $l['matched_how'] === 'auto' ? 'خودکار' : 'دستی' ?></small>
                    <form method="post" action="<?= h($self) ?>" class="st-inline"><?= Csrf::field() ?><input type="hidden" name="line" value="<?= (int)$l['id'] ?>"><button type="submit" name="action" value="unmatch" class="st-link-btn">برداشتنِ جفت</button></form>
                <?php else: $cands = $byAmt[$amt] ?? []; ?>
                    <form method="post" action="<?= h($self) ?>" class="st-inline"><?= Csrf::field() ?><input type="hidden" name="line" value="<?= (int)$l['id'] ?>">
                        <?php if ($cands): ?>
                        <select name="payment" aria-label="سندِ هم‌مبلغ"><?php foreach ($cands as $p): ?><option value="<?= (int)$p['id'] ?>"><?= h($payLabel($p)) ?></option><?php endforeach; ?></select>
                        <button type="submit" name="action" value="match" class="st-btn st-btn-sm">جفت</button>
                        <?php endif; ?>
                        <a class="st-link-btn" href="<?= h(Biz::url('payment.php?k=' . ($amt > 0 ? 'receipt' : 'expense') . '&acc=' . $accId . '&amount=' . abs($amt)
                            . '&date=' . $l['line_date'] . '&title=' . rawurlencode(mb_substr((string)($l['descr'] ?? ''), 0, 100)))) ?>">ثبتِ سند</a>
                        <button type="submit" name="action" value="delete" class="st-link-btn" onclick="return confirm('این ردیف از صورت‌حساب حذف شود؟');">حذف</button>
                    </form>
                <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if ($pays): ?>
<section class="st-card">
    <h2 class="st-h2">سندهایی که در صورت‌حساب نیامده‌اند</h2>
    <p class="st-muted">از <?= h(toJalali((string)$data['from'])) ?> تا <?= h(toJalali((string)$data['to'])) ?> — شاید هنوز به بانک نرسیده‌اند، یا اشتباه به این حساب خورده‌اند.</p>
    <ul class="st-list">
    <?php foreach (array_slice($pays, 0, 100) as $p): ?>
        <li class="st-list-row"><a class="st-row-link" href="<?= h(Biz::url('payment.php?id=' . (int)$p['id'])) ?>"><?= h($payLabel($p)) ?></a>
            <span class="st-num<?= (int)$p['signed'] < 0 ? ' is-neg' : '' ?>"><?= (int)$p['signed'] < 0 ? '−' : '+' ?><?= formatMoney(abs((int)$p['signed'])) ?></span></li>
    <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
