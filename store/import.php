<?php
/**
 * ورود و خروجِ اطلاعات — آمدن از نرم‌افزارِ حسابداریِ دیگر (اشخاص، کالا،
 * صندوق و بانک، چک‌های در جریان) و خروجیِ همان‌ها.
 *
 * **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵):** «اشخاص رو هم وارد کنیم … استاندارد کن که
 * اگه کسی خواست از برنامه حسابداری دیگه‌ای وارد بشه بتونه تمام دیتای خودش رو
 * بیاره.» منطق فقط در `includes/biz_migrate.php` (`BizMigrate`)؛ این‌جا پوسته.
 *
 * سه گام، و گامِ دوم هیچ چیزی نمی‌نویسد:
 *   ۱. فایل → جدولِ خام در یک فایلِ موقت (`BizMigrate::stash()`).
 *   ۲. پیش‌نمایش: بخش، **تطبیقِ هر ستون** (خودکار + دستی)، گزینه‌ها، و نتیجه‌ی
 *      هر ردیف «تازه / به‌روزرسانی / ردشده / خطا».
 *   ۳. «ثبت نهایی» → `BizMigrate::apply()`.
 *
 * دریافتِ کالا از سایت (ووکامرس، گوگل‌شیت) در `products-io.php` می‌ماند.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_migrate.php';

$userId = Biz::ownerId();
$self   = Biz::url('import.php');

$send = static function (string $bytes, string $name, string $type): void {
    // ⛔ گزیپِ `db.php` بسته می‌شود، وگرنه فایل دوبار فشرده است و باز نمی‌شود
    while (ob_get_level() > 0) { ob_end_clean(); }
    header_remove('Content-Encoding');
    header('Content-Type: ' . $type);
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: no-store, private');
    echo $bytes;
    exit;
};
// ⚠ نامِ فایل لاتین (سرآیندِ `filename=`)
const IMPORT_FILE_NAMES = ['parties' => 'parties', 'products' => 'products', 'accounts' => 'accounts', 'cheques' => 'cheques'];
const IMPORT_TABLES = ['parties' => 'biz_parties', 'products' => 'biz_products', 'accounts' => 'biz_accounts', 'cheques' => 'biz_payments'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $action = postParam('action');
    $entity = postParam('entity');

    if ($action === 'export' || $action === 'template') {
        if (!isset(BizMigrate::ENTITIES[$entity])) { redirectWithMessage($self, 'error', 'بخشِ نامعتبر.'); }
        $rows = $action === 'template' ? BizMigrate::template($entity) : BizMigrate::export($userId, $entity);
        $stamp = str_replace('/', '-', toLatinDigits(toJalali(date('Y-m-d'))));
        $base  = IMPORT_FILE_NAMES[$entity] . ($action === 'template' ? '-template' : '-' . $stamp);
        if ($action === 'export') {
            Audit::log('data.exported', IMPORT_TABLES[$entity], null, ['kind' => postParam('format') === 'csv' ? 'csv' : 'xlsx', 'rows' => count($rows) - 1]);
        }
        if (postParam('format') === 'csv') {
            $send(BizSheet::writeCsv($rows), $base . '.csv', 'text/csv; charset=utf-8');
        }
        $send(BizSheet::writeXlsx($rows, BizMigrate::ENTITIES[$entity]), $base . '.xlsx',
              'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    if ($action === 'upload') {
        $f = $_FILES['file'] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            redirectWithMessage($self, 'error', 'فایلی انتخاب نشد.');
        }
        if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || (int)$f['size'] > BizSheet::MAX_BYTES) {
            redirectWithMessage($self, 'error', 'فایل بیش از ۵ مگابایت است.');
        }
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
            redirectWithMessage($self, 'error', 'بارگذاری کامل نشد؛ دوباره امتحان کنید.');
        }
        $sheet = BizSheet::read((string)file_get_contents((string)$f['tmp_name']));
        if (!$sheet['ok']) { redirectWithMessage($self, 'error', $sheet['message']); }
        $entity = isset(BizMigrate::ENTITIES[$entity]) ? $entity : BizMigrate::detect($sheet['rows']);
        $res = BizMigrate::stash($userId, $entity, $sheet['rows'], mb_substr(basename((string)$f['name']), 0, 80));
        if (!$res['ok']) { redirectWithMessage($self, 'error', $res['message']); }
        $msg = toPersianDigits((string)$res['rows']) . ' ردیف خوانده شد (' . BizMigrate::ENTITIES[$entity] . ')؛ '
             . ($res['required'] ? 'ستون‌ها را یک نگاه بیندازید و بعد ثبت کنید.' : 'ستونِ اصلی شناخته نشد — در جدولِ پایین ستون‌ها را دستی تطبیق دهید.');
        redirectWithMessage($self . '#preview', $res['required'] ? 'success' : 'error', $msg);
    }

    if ($action === 'setup') {
        BizMigrate::setup($userId, $_POST);
        header('Location: ' . $self . '#preview');
        exit;
    }

    if ($action === 'cancel') {
        BizMigrate::clear($userId);
        redirectWithMessage($self, 'success', 'ورودِ اطلاعات لغو شد؛ چیزی ثبت نشد.');
    }

    if ($action === 'apply') {
        $st = BizMigrate::load($userId);
        if (!$st) { redirectWithMessage($self, 'error', 'پیش‌نمایش پیدا نشد؛ فایل را دوباره بفرستید.'); }
        $pv  = BizMigrate::preview($userId, $st);
        $res = BizMigrate::apply($userId, $st['entity'], $pv['plan'], $st['opts']);
        BizMigrate::clear($userId);
        Audit::log('data.imported', IMPORT_TABLES[$st['entity']], null, [
            'source' => 'migrate', 'created' => $res['created'], 'updated' => $res['updated'], 'errors' => count($res['errors']),
        ]);
        $msg = BizMigrate::ENTITIES[$st['entity']] . ': ' . toPersianDigits((string)$res['created']) . ' تازه، '
             . toPersianDigits((string)$res['updated']) . ' به‌روزرسانی'
             . (!empty($res['stock']) ? '، ' . toPersianDigits((string)$res['stock']) . ' موجودیِ هم‌تراز‌شده' : '')
             . ($res['skipped'] ? '، ' . toPersianDigits((string)$res['skipped']) . ' ردشده' : '') . '.';
        if ($res['errors']) {
            $first = array_slice($res['errors'], 0, 3, true);
            $msg .= ' ' . toPersianDigits((string)count($res['errors'])) . ' ردیف ثبت نشد — ' . implode('؛ ', array_map(
                fn($l, $m) => 'ردیفِ ' . toPersianDigits((string)$l) . ': ' . $m, array_keys($first), $first)) . (count($res['errors']) > 3 ? '؛ …' : '');
        }
        redirectWithMessage($self, $res['errors'] ? 'error' : 'success', $msg);
    }
    redirectWithMessage($self, 'error', 'درخواست نامعتبر است.');
}

$stash = BizMigrate::load($userId);
$pv = $stash ? BizMigrate::preview($userId, $stash) : null;
$actionLabel = ['create' => 'تازه', 'update' => 'به‌روزرسانی', 'skip' => 'ردشده', 'error' => 'خطا'];
const IMPORT_PREVIEW_ROWS = 200;

$pageTitle = 'ورود و خروجِ اطلاعات';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <div>
        <h1 class="st-h1">ورود و خروجِ اطلاعات</h1>
        <p class="st-muted">آمدن از نرم‌افزارِ حسابداریِ دیگر با فایلِ اکسل یا CSV — اطلاعاتِ پایه و مانده‌ی اول دوره.</p>
    </div>
</div>

<?php if ($stash && $pv):
    $entity = $stash['entity'];
    $fields = BizMigrate::fields($entity);
    $header = (array)($stash['raw'][$stash['hi']] ?? []);
    $plan   = $pv['plan'];
    $c      = $plan['counts'];
    $samples = [];
    foreach (array_slice($stash['raw'], $stash['hi'] + 1, 30) as $r) {
        foreach ((array)$r as $i => $v) {
            if ($i < BizMigrate::MAX_COLS && trim((string)$v) !== '' && count($samples[$i] ?? []) < 2) { $samples[$i][] = mb_substr((string)$v, 0, 30); }
        }
    }
?>
<section class="st-card" id="preview">
    <div class="st-card-head">
        <h2 class="st-h2">پیش‌نمایش — <?= h($stash['label']) ?></h2>
    </div>
    <p class="st-muted">هنوز هیچ چیزی ثبت نشده است. بخش و ستون‌ها را بررسی کنید، «به‌روزرسانیِ پیش‌نمایش» و بعد «ثبت نهایی».</p>

    <form method="post" action="<?= h($self) ?>" class="st-form st-io-opts">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="setup">
        <div class="st-seg-label">این فایل مالِ کدام بخش است؟</div>
        <div class="st-seg st-seg-sm">
            <?php foreach (BizMigrate::ENTITIES as $k => $l): ?>
            <label class="st-seg-opt"><input type="radio" name="entity" value="<?= h($k) ?>"<?= $k === $entity ? ' checked' : '' ?>><span><?= h($l) ?></span></label>
            <?php endforeach; ?>
        </div>

        <?php if (count($stash['raw']) > 2): ?>
        <label class="st-field st-field-inline">
            <span>ردیفِ نامِ ستون‌ها</span>
            <select name="hi">
                <?php for ($i = 0; $i < min(10, count($stash['raw']) - 1); $i++): ?>
                <option value="<?= $i ?>"<?= $i === $stash['hi'] ? ' selected' : '' ?>>ردیفِ <?= toPersianDigits((string)($i + 1)) ?></option>
                <?php endfor; ?>
            </select>
        </label>
        <?php endif; ?>

        <div class="st-table-wrap">
            <table class="st-table st-map-table">
                <thead><tr><th>ستونِ فایل</th><th class="st-hide-sm">نمونه</th><th>در برنامه</th></tr></thead>
                <tbody>
                <?php foreach ($header as $i => $hcell): if ($i >= BizMigrate::MAX_COLS) { break; } ?>
                    <tr>
                        <td><?= trim((string)$hcell) !== '' ? h((string)$hcell) : '<span class="st-muted-i">ستونِ ' . toPersianDigits((string)($i + 1)) . '</span>' ?></td>
                        <td class="st-hide-sm"><span class="st-muted"><?= h(implode(' · ', $samples[$i] ?? [])) ?></span></td>
                        <td>
                            <select name="map[<?= (int)$i ?>]" aria-label="ستونِ <?= h((string)$hcell) ?>">
                                <option value="">— نادیده —</option>
                                <?php foreach ($fields as $f => $label): ?>
                                <option value="<?= h($f) ?>"<?= ($stash['map'][$i] ?? '') === $f ? ' selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <label class="st-check"><input type="checkbox" name="rial" value="1"<?= !empty($stash['opts']['rial']) ? ' checked' : '' ?>> مبلغ‌های فایل به <b>ریال</b> است (بر ۱۰ تقسیم شود) — بیشترِ نرم‌افزارهای حسابداری ریالی‌اند</label>
        <?php if ($entity !== 'cheques'): ?>
        <label class="st-check"><input type="checkbox" name="update" value="1"<?= !empty($stash['opts']['update']) ? ' checked' : '' ?>> مواردِ موجود (همان کد یا همان نام) به‌روزرسانی شوند</label>
        <?php endif; ?>
        <?php if ($entity === 'parties'): ?>
        <label class="st-check"><input type="checkbox" name="flip" value="1"<?= !empty($stash['opts']['flip']) ? ' checked' : '' ?>> علامتِ ستونِ «مانده» برعکس است (در فایل، مثبت یعنی <b>بستانکار</b>)</label>
        <?php elseif ($entity === 'products'): ?>
        <label class="st-check"><input type="checkbox" name="sync_stock" value="1"<?= !empty($stash['opts']['sync_stock']) ? ' checked' : '' ?>> موجودیِ کالاهای موجود با ستونِ «موجودی» هم‌تراز شود (مثلِ انبارگردانی)</label>
        <?php elseif ($entity === 'cheques'): ?>
        <label class="st-check"><input type="checkbox" name="cheque_out" value="1"<?= !empty($stash['opts']['cheque_out']) ? ' checked' : '' ?>> اگر ستونِ «نوع چک» نیست، همه <b>پرداختی</b>‌اند (وگرنه دریافتی)</label>
        <?php endif; ?>
        <button type="submit" class="st-btn st-btn-ghost">به‌روزرسانیِ پیش‌نمایش</button>
    </form>

    <div class="st-chips-row">
        <span class="st-stat"><?= toPersianDigits((string)$c['create']) ?> تازه</span>
        <span class="st-stat"><?= toPersianDigits((string)$c['update']) ?> به‌روزرسانی</span>
        <?php if ($c['skip']): ?><span class="st-stat"><?= toPersianDigits((string)$c['skip']) ?> ردشده</span><?php endif; ?>
        <?php if ($c['error']): ?><span class="st-stat is-out"><?= toPersianDigits((string)$c['error']) ?> خطا</span><?php endif; ?>
        <?php if ($entity !== 'products' && $plan['total'] !== 0): ?><span class="st-stat">جمعِ مبلغ: <b class="st-num"><?= formatMoney(abs($plan['total'])) ?></b> تومان</span><?php endif; ?>
    </div>

    <div class="st-table-wrap">
        <table class="st-table">
            <thead><tr>
                <th class="st-th-num">ردیف</th>
                <?php if ($entity === 'parties'): ?><th>طرف‌حساب</th><th class="st-hide-sm">نوع / تلفن</th><th class="st-th-num">مانده‌ی اول دوره</th>
                <?php elseif ($entity === 'products'): ?><th>کالا</th><th class="st-hide-sm">دسته / واحد</th><th class="st-th-num">قیمتِ فروش</th>
                <?php elseif ($entity === 'accounts'): ?><th>صندوق یا حساب</th><th class="st-hide-sm">نوع</th><th class="st-th-num">موجودی</th>
                <?php else: ?><th>چک</th><th class="st-hide-sm">طرف‌حساب / سررسید</th><th class="st-th-num">مبلغ</th><?php endif; ?>
                <th>نتیجه</th>
            </tr></thead>
            <tbody>
            <?php foreach (array_slice($plan['rows'], 0, IMPORT_PREVIEW_ROWS) as $p): ?>
                <tr class="<?= $p['action'] === 'error' ? 'is-error' : '' ?>">
                    <td class="st-td-num"><span class="st-num"><?= toPersianDigits((string)$p['line']) ?></span></td>
                    <?php if ($entity === 'parties'): ?>
                    <td><?= $p['name'] !== '' ? h($p['name']) : '<span class="st-muted-i">—</span>' ?>
                        <?php if ($p['code'] !== ''): ?><span class="st-sku" dir="ltr"><?= h($p['code']) ?></span><?php endif; ?></td>
                    <td class="st-hide-sm"><?= h(implode(' · ', array_filter([BizParties::KINDS[$p['kind'] ?? ''] ?? '', (string)($p['phone'] ?? '')], 'strlen'))) ?: '<span class="st-muted-i">—</span>' ?></td>
                    <td class="st-td-num"><?php if ($p['balance'] === null): ?><span class="st-muted-i">—</span><?php else: ?>
                        <span class="st-num"><?= formatMoney(abs($p['balance'])) ?></span>
                        <span class="st-bal-side"><?= $p['balance'] > 0 ? 'بدهکار' : ($p['balance'] < 0 ? 'بستانکار' : '') ?></span><?php endif; ?></td>
                    <?php elseif ($entity === 'products'): ?>
                    <td><?= $p['name'] !== '' ? h($p['name']) : '<span class="st-muted-i">—</span>' ?>
                        <?php if ($p['sku'] !== ''): ?><span class="st-sku" dir="ltr"><?= h($p['sku']) ?></span><?php endif; ?></td>
                    <td class="st-hide-sm"><?= h(implode(' · ', array_filter([(string)($p['category'] ?? ''), (string)($p['unit'] ?? '')], 'strlen'))) ?: '<span class="st-muted-i">—</span>' ?></td>
                    <td class="st-td-num"><span class="st-num"><?= $p['sell_price'] === null ? '—' : formatMoney($p['sell_price']) ?></span></td>
                    <?php elseif ($entity === 'accounts'): ?>
                    <td><?= $p['name'] !== '' ? h($p['name']) : '<span class="st-muted-i">—</span>' ?></td>
                    <td class="st-hide-sm"><?= h(BizCash::KINDS[$p['kind']] ?? '') ?></td>
                    <td class="st-td-num"><span class="st-num"><?= $p['balance'] === null ? '—' : formatMoney($p['balance']) ?></span></td>
                    <?php else: ?>
                    <td><?= $p['dir'] === 'in' ? 'دریافتی' : 'پرداختی' ?>
                        <?php if ($p['cheque_no'] !== ''): ?><span class="st-sku" dir="ltr"><?= h($p['cheque_no']) ?></span><?php endif; ?></td>
                    <td class="st-hide-sm"><?= h($p['party_name']) ?><?= $p['due'] ? ' · ' . h(toJalali($p['due'])) : '' ?></td>
                    <td class="st-td-num"><span class="st-num"><?= $p['amount'] === null ? '—' : formatMoney($p['amount']) ?></span></td>
                    <?php endif; ?>
                    <td><span class="st-badge is-<?= h($p['action']) ?>"><?= h($actionLabel[$p['action']]) ?></span>
                        <?php if ($p['notes']): ?><span class="st-bal-side"><?= h(implode('؛ ', $p['notes'])) ?></span><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (count($plan['rows']) > IMPORT_PREVIEW_ROWS): ?>
    <p class="st-muted-i"><?= toPersianDigits((string)IMPORT_PREVIEW_ROWS) ?> ردیفِ اول نشان داده شد؛ همه‌ی <?= toPersianDigits((string)count($plan['rows'])) ?> ردیف ثبت می‌شوند.</p>
    <?php endif; ?>

    <div class="st-danger-row">
        <form method="post" action="<?= h($self) ?>">
            <?= Csrf::field() ?><input type="hidden" name="action" value="apply">
            <button type="submit" class="st-btn"<?= $c['create'] + $c['update'] === 0 ? ' disabled' : '' ?>>ثبت نهایی (<?= toPersianDigits((string)($c['create'] + $c['update'])) ?> ردیف)</button>
        </form>
        <form method="post" action="<?= h($self) ?>">
            <?= Csrf::field() ?><input type="hidden" name="action" value="cancel">
            <button type="submit" class="st-btn st-btn-ghost">انصراف</button>
        </form>
    </div>
</section>
<?php endif; ?>

<section class="st-card">
    <h2 class="st-h2">دریافت از فایل</h2>
    <p class="st-muted">خروجیِ اکسلِ نرم‌افزارِ قبلی (هر نرم‌افزاری) یا «فایلِ نمونه»ی پایین. ستون‌ها خودکار شناخته می‌شوند و
        در پیش‌نمایش هر کدام را می‌شود دستی عوض کرد. <b>ترتیبِ پیشنهادی:</b> اشخاص، کالا، صندوق و بانک، و آخر چک‌ها.</p>
    <form method="post" enctype="multipart/form-data" action="<?= h($self) ?>" class="st-form">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="upload">
        <div class="st-row2">
            <label class="st-field">
                <span>بخش</span>
                <select name="entity">
                    <option value="">تشخیصِ خودکار از ستون‌ها</option>
                    <?php foreach (BizMigrate::ENTITIES as $k => $l): ?><option value="<?= h($k) ?>"><?= h($l) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label class="st-field">
                <span>فایل (xlsx یا CSV، تا ۵ مگابایت)</span>
                <input type="file" name="file" required accept=".xlsx,.csv,.txt,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv">
            </label>
        </div>
        <button type="submit" class="st-btn">خواندن و پیش‌نمایش</button>
    </form>
    <p class="st-muted-i st-soon">کالا از سایت (ووکامرس، گوگل‌شیت): <a href="<?= h(Biz::url('products-io.php')) ?>">دریافتِ کالا از سایت</a>.
        فایلِ قدیمیِ <b>xls</b> را اول در اکسل با «Save As → xlsx» ذخیره کنید.</p>
</section>

<div class="st-cols">
<?php foreach (BizMigrate::ENTITIES as $k => $l): ?>
    <section class="st-card">
        <h2 class="st-h2"><?= h($l) ?></h2>
        <p class="st-muted"><?= h(BizMigrate::HINTS[$k]) ?></p>
        <form method="post" action="<?= h($self) ?>" class="st-form">
            <?= Csrf::field() ?>
            <input type="hidden" name="entity" value="<?= h($k) ?>">
            <div class="st-danger-row">
                <button type="submit" name="action" value="export" class="st-btn st-btn-ghost">خروجیِ اکسل</button>
                <button type="submit" name="action" value="template" class="st-link-btn">فایلِ نمونه</button>
            </div>
        </form>
    </section>
<?php endforeach; ?>
</div>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
