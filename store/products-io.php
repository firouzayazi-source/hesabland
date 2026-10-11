<?php
/**
 * ورود و خروجِ کالا — اکسل (xlsx)، CSV، و سایت (لینکِ فایل، گوگل‌شیت،
 * فروشگاهِ ووکامرس).
 *
 * **خواسته‌ی مالکِ نصب:** «امکان دریافت محصولات از اکسل یا سایت برام فراهم
 * کن، همچنین خروج محصولات.»
 *
 * سه گام، و گامِ دوم هیچ چیزی نمی‌نویسد:
 *   ۱. فایل یا آدرس → ردیف‌ها در یک فایلِ موقت (`BizImport::stash()`).
 *   ۲. پیش‌نمایش: هر ردیف «تازه / به‌روزرسانی / ردشده / خطا».
 *   ۳. «ثبت نهایی» → `BizImport::apply()`.
 * ⛔ پیش‌نمایشِ اجباری: فایلی که ستون‌هایش جابه‌جا باشد (قیمتِ فروش در
 *    ستونِ خرید) بی‌پیش‌نمایش صدها کالای غلط می‌ساخت.
 *
 * خروجی POST با CSRF است، نه لینکِ GET (همان استدلالِ `export_data.php`).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_io.php';

$userId = Biz::ownerId();
$self   = Biz::url('products-io.php');

/** فایل برای دانلود — پیش از هر خروجیِ HTML. */
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $action = postParam('action');

    if ($action === 'export' || $action === 'template') {
        $rows = $action === 'template' ? BizExport::template() : BizExport::rows($userId, postParam('inactive') === '1');
        // ⚠ ارقامِ لاتین در نامِ فایل (سرآیندِ `filename=`)
        $stamp = str_replace('/', '-', toLatinDigits(toJalali(date('Y-m-d'))));
        $base  = $action === 'template' ? 'products-template' : 'products-' . $stamp;
        if ($action === 'export') {
            Audit::log('data.exported', 'biz_products', null, ['kind' => postParam('format') === 'csv' ? 'csv' : 'xlsx', 'rows' => count($rows) - 1]);
        }
        if (postParam('format') === 'csv') {
            $send(BizSheet::writeCsv($rows), $base . '.csv', 'text/csv; charset=utf-8');
        }
        $send(BizSheet::writeXlsx($rows), $base . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
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
        $rows  = $sheet['ok'] ? BizImport::fromSheet($sheet['rows']) : $sheet;
        if (!$rows['ok']) { redirectWithMessage($self, 'error', $rows['message']); }
        $label = mb_substr(basename((string)$f['name']), 0, 80);
        if (!BizImport::stash($userId, $rows['rows'], ['source' => 'file', 'label' => $label, 'toman' => false])) {
            redirectWithMessage($self, 'error', 'پوشه‌ی موقتِ سرور نوشتنی نیست؛ به پشتیبانی خبر بدهید.');
        }
        redirectWithMessage($self . '#preview', 'success', count($rows['rows']) . ' ردیف خوانده شد؛ پیش از ثبت بررسی کنید.');
    }

    if ($action === 'url') {
        $url = mb_substr(trim(postParam('url')), 0, 500);
        if ($url === '') { redirectWithMessage($self, 'error', 'آدرس را بنویسید.'); }
        $rows = BizImport::fromUrl($url, $userId);
        if (!$rows['ok']) { redirectWithMessage($self, 'error', $rows['message']); }
        $host = (string)(parse_url($url, PHP_URL_HOST) ?: $url);
        if (!BizImport::stash($userId, $rows['rows'], ['source' => $rows['source'], 'label' => $host, 'toman' => !empty($rows['toman'])])) {
            redirectWithMessage($self, 'error', 'پوشه‌ی موقتِ سرور نوشتنی نیست؛ به پشتیبانی خبر بدهید.');
        }
        redirectWithMessage($self . '#preview', 'success', count($rows['rows']) . ' کالا از «' . $host . '» خوانده شد؛ پیش از ثبت بررسی کنید.');
    }

    if ($action === 'options') {
        BizImport::setOptions($_POST);
        header('Location: ' . $self . '#preview');
        exit;
    }

    if ($action === 'cancel') {
        BizImport::clear($userId);
        redirectWithMessage($self, 'success', 'ورودِ اطلاعات لغو شد؛ چیزی ثبت نشد.');
    }

    if ($action === 'apply') {
        $st = BizImport::load($userId);
        if (!$st) { redirectWithMessage($self, 'error', 'پیش‌نمایش پیدا نشد؛ فایل را دوباره بفرستید.'); }
        $opts = $st['opts'];
        if (!empty($st['meta']['toman'])) { $opts['rial'] = false; }
        $plan = BizImport::plan($userId, $st['rows'], $opts);
        $res  = BizImport::apply($userId, $plan, $opts);
        BizImport::clear($userId);
        Audit::log('data.imported', 'biz_products', null, [
            'source' => (string)($st['meta']['source'] ?? ''), 'created' => $res['created'], 'updated' => $res['updated'],
            'errors' => count($res['errors']),
        ]);
        $msg = $res['created'] . ' کالای تازه، ' . $res['updated'] . ' به‌روزرسانی'
             . ($res['stock'] ? '، ' . $res['stock'] . ' موجودیِ هم‌تراز‌شده' : '')
             . ($res['skipped'] ? '، ' . $res['skipped'] . ' ردشده' : '') . '.';
        if ($res['errors']) {
            $first = array_slice($res['errors'], 0, 3, true);
            $msg .= ' ' . count($res['errors']) . ' ردیف ثبت نشد — ' . implode('؛ ', array_map(
                fn($l, $m) => 'ردیفِ ' . $l . ': ' . $m, array_keys($first), $first)) . (count($res['errors']) > 3 ? '؛ …' : '');
        }
        redirectWithMessage(Biz::url('products.php'), $res['errors'] ? 'error' : 'success', $msg);
    }
    redirectWithMessage($self, 'error', 'درخواست نامعتبر است.');
}

$stash = BizImport::load($userId);
$plan  = null;
if ($stash) {
    $opts = $stash['opts'];
    if (!empty($stash['meta']['toman'])) { $opts['rial'] = false; }
    $plan = BizImport::plan($userId, $stash['rows'], $opts);
}
$actionLabel = ['create' => 'تازه', 'update' => 'به‌روزرسانی', 'skip' => 'ردشده', 'error' => 'خطا'];
const PREVIEW_ROWS = 200;

$pageTitle = 'ورود و خروجِ کالا';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <div>
        <a class="st-back" href="<?= h(Biz::url('products.php')) ?>">‹ کالاها</a>
        <h1 class="st-h1">ورود و خروجِ کالا</h1>
    </div>
</div>

<?php if ($plan): $c = $plan['counts']; ?>
<section class="st-card" id="preview">
    <div class="st-card-head">
        <h2 class="st-h2">پیش‌نمایش — <?= h((string)($stash['meta']['label'] ?? '')) ?></h2>
    </div>
    <p class="st-muted">هنوز هیچ چیزی ثبت نشده است. ردیف‌ها را ببینید، گزینه‌ها را درست کنید و بعد «ثبت نهایی» را بزنید.</p>
    <div class="st-chips-row">
        <span class="st-stat"><?= toPersianDigits((string)$c['create']) ?> کالای تازه</span>
        <span class="st-stat"><?= toPersianDigits((string)$c['update']) ?> به‌روزرسانی</span>
        <?php if ($c['skip']): ?><span class="st-stat"><?= toPersianDigits((string)$c['skip']) ?> ردشده (از قبل هست)</span><?php endif; ?>
        <?php if ($c['error']): ?><span class="st-stat is-out"><?= toPersianDigits((string)$c['error']) ?> خطا</span><?php endif; ?>
    </div>

    <form method="post" action="<?= h($self) ?>" class="st-form st-io-opts">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="options">
        <?php if (empty($stash['meta']['toman'])): ?>
        <label class="st-check"><input type="checkbox" name="rial" value="1"<?= !empty($stash['opts']['rial']) ? ' checked' : '' ?>> قیمت‌های فایل به <b>ریال</b> است (بر ۱۰ تقسیم شود)</label>
        <?php else: ?>
        <input type="hidden" name="rial" value="">
        <p class="st-muted-i">قیمت‌ها از خودِ سایت به تومان تبدیل شده‌اند.</p>
        <?php endif; ?>
        <label class="st-check"><input type="checkbox" name="update" value="1"<?= !empty($stash['opts']['update']) ? ' checked' : '' ?>> کالاهای موجود (همان کد یا همان نام) به‌روزرسانی شوند</label>
        <label class="st-check"><input type="checkbox" name="sync_stock" value="1"<?= !empty($stash['opts']['sync_stock']) ? ' checked' : '' ?>> موجودیِ کالاهای موجود با ستونِ «موجودی» هم‌تراز شود (مثلِ انبارگردانی)</label>
        <button type="submit" class="st-btn st-btn-ghost">به‌روزرسانیِ پیش‌نمایش</button>
    </form>

    <div class="st-table-wrap">
        <table class="st-table">
            <thead><tr>
                <th class="st-th-num">ردیف</th><th>کالا</th><th class="st-hide-sm">دسته / واحد</th>
                <th class="st-th-num">قیمتِ فروش</th><th class="st-th-num st-hide-sm">موجودی</th><th>نتیجه</th>
            </tr></thead>
            <tbody>
            <?php foreach (array_slice($plan['rows'], 0, PREVIEW_ROWS) as $p): ?>
                <tr class="<?= $p['action'] === 'error' ? 'is-error' : '' ?>">
                    <td class="st-td-num"><span class="st-num"><?= toPersianDigits((string)$p['line']) ?></span></td>
                    <td><?= $p['name'] !== '' ? h($p['name']) : '<span class="st-muted-i">—</span>' ?>
                        <?php if ($p['sku'] !== ''): ?><span class="st-sku" dir="ltr"><?= h($p['sku']) ?></span><?php endif; ?></td>
                    <td class="st-hide-sm"><?php $__cu = implode(' · ', array_filter([(string)($p['category'] ?? ''), (string)($p['unit'] ?? '')], 'strlen')); ?>
                        <?= $__cu !== '' ? h($__cu) : '<span class="st-muted-i">—</span>' ?></td>
                    <td class="st-td-num"><span class="st-num"><?= $p['sell_price'] === null ? '—' : formatMoney($p['sell_price']) ?></span></td>
                    <td class="st-td-num st-hide-sm"><span class="st-num"><?= $p['qty'] === null ? '—' : h(formatQty($p['qty'])) ?></span></td>
                    <td><span class="st-badge is-<?= h($p['action']) ?>"><?= h($actionLabel[$p['action']]) ?></span>
                        <?php if ($p['notes']): ?><span class="st-bal-side"><?= h(implode('؛ ', $p['notes'])) ?></span><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (count($plan['rows']) > PREVIEW_ROWS): ?>
    <p class="st-muted-i"><?= toPersianDigits((string)PREVIEW_ROWS) ?> ردیفِ اول نشان داده شد؛ همه‌ی <?= toPersianDigits((string)count($plan['rows'])) ?> ردیف ثبت می‌شوند.</p>
    <?php endif; ?>

    <div class="st-danger-row">
        <form method="post" action="<?= h($self) ?>">
            <?= Csrf::field() ?><input type="hidden" name="action" value="apply">
            <button type="submit" class="st-btn"<?= $c['create'] + $c['update'] === 0 ? ' disabled' : '' ?>>ثبت نهایی (<?= toPersianDigits((string)($c['create'] + $c['update'])) ?> کالا)</button>
        </form>
        <form method="post" action="<?= h($self) ?>">
            <?= Csrf::field() ?><input type="hidden" name="action" value="cancel">
            <button type="submit" class="st-btn st-btn-ghost">انصراف</button>
        </form>
    </div>
</section>
<?php endif; ?>

<div class="st-cols">
    <section class="st-card">
        <h2 class="st-h2">دریافت از فایلِ اکسل</h2>
        <p class="st-muted">فایلِ <b>xlsx</b> یا <b>CSV</b>. ردیفِ اول نامِ ستون‌هاست؛ فقط «نام کالا» الزامی است:
            <?= h(implode('، ', BizImport::FIELDS)) ?>.</p>
        <form method="post" enctype="multipart/form-data" action="<?= h($self) ?>" class="st-form">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="upload">
            <label class="st-field">
                <span>فایل</span>
                <input type="file" name="file" required accept=".xlsx,.csv,.txt,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv">
            </label>
            <button type="submit" class="st-btn">خواندن و پیش‌نمایش</button>
        </form>
        <form method="post" action="<?= h($self) ?>" class="st-subform">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="template">
            <button type="submit" class="st-link-btn">دریافتِ فایلِ نمونه (xlsx)</button>
        </form>
    </section>

    <section class="st-card">
        <h2 class="st-h2">دریافت از سایت</h2>
        <p class="st-muted">یکی از این‌ها: آدرسِ فروشگاهِ <b>ووکامرسی</b> (مثل <span dir="ltr">https://shop.ir</span>)، لینکِ <b>گوگل‌شیت</b>، یا لینکِ مستقیمِ یک فایلِ xlsx/CSV.</p>
        <form method="post" action="<?= h($self) ?>" class="st-form">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="url">
            <label class="st-field">
                <span>آدرس</span>
                <input type="url" name="url" dir="ltr" required maxlength="500" placeholder="https://">
            </label>
            <button type="submit" class="st-btn">خواندن و پیش‌نمایش</button>
        </form>
        <p class="st-muted-i st-soon">از ووکامرس نام، کد، دسته و قیمتِ فروش می‌آید؛ موجودی و قیمتِ خرید را سایت عمومی نمی‌کند.</p>
    </section>
</div>

<section class="st-card">
    <h2 class="st-h2">خروجیِ کالاها</h2>
    <p class="st-muted">همه‌ی کالاها با قیمت، موجودی و ارزشِ انبار. همین فایل را می‌شود ویرایش کرد و دوباره از بالا وارد کرد.</p>
    <form method="post" action="<?= h($self) ?>" class="st-form">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="export">
        <label class="st-check"><input type="checkbox" name="inactive" value="1"> کالاهای غیرفعال هم بیاید</label>
        <div class="st-danger-row">
            <button type="submit" name="format" value="xlsx" class="st-btn">خروجیِ اکسل (xlsx)</button>
            <button type="submit" name="format" value="csv" class="st-btn st-btn-ghost">خروجیِ CSV</button>
        </div>
    </form>
</section>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
