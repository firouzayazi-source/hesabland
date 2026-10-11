<?php
/**
 * طراحیِ فاکتور — قالب، رنگ، لوگو، ستون‌ها و بخش‌های برگه‌ی چاپیِ فاکتور،
 * با پیش‌نمایشِ زنده.
 *
 * ⛔ گزینه‌ها فقط `BizInvoiceDesign` است و ذخیره از `save()`/`saveLogo()`
 *    می‌گذرد؛ این صفحه فقط فرم است. هر نوشتن POST + CSRF و بعد ریدایرکت.
 * ⛔ پیش‌نمایش یک `<iframe>` از همان `print.php?doc=invoice` است (یک
 *    رندرکننده). بی‌جاوااسکریپت طراحیِ ذخیره‌شده را نشان می‌دهد؛ با
 *    `store.js` گزینه‌های هنوز‌ذخیره‌نشده از آدرس (`preview=1`) می‌آیند.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_docs.php';
require_once __DIR__ . '/../includes/biz_invoice_design.php';

$userId = Biz::ownerId();
$self   = Biz::url('invoice-design.php');
$ready  = BizInvoiceDesign::ready();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    switch (postParam('action')) {
        case 'save':        $r = BizInvoiceDesign::save($userId, $_POST); break;
        case 'reset':       $r = BizInvoiceDesign::reset($userId); break;
        case 'logo':        $r = BizInvoiceDesign::saveLogo($userId, $_FILES['logo'] ?? []); break;
        case 'logo_remove': $r = BizInvoiceDesign::removeLogo($userId); break;
        default:            $r = ['ok' => false, 'message' => 'درخواست نامعتبر است.'];
    }
    redirectWithMessage($self, $r['ok'] ? 'success' : 'error', $r['message']);
}

$d    = BizInvoiceDesign::get($userId);
$logo = $ready ? BizInvoiceDesign::logo($userId) : null;

// پیش‌نمایش با آخرین فاکتورِ فروشِ صادرشده، وگرنه با نمونه
$last = 0;
if (getParam('pv') !== 'sample') {
    $st = Database::getConnection()->prepare(
        "SELECT id FROM biz_invoices WHERE user_id = :u AND kind = 'sale' AND status = 'issued' ORDER BY id DESC LIMIT 1"
    );
    $st->execute(['u' => $userId]);
    $last = (int)$st->fetchColumn();
}
$pvBase = $last > 0 ? BizPrint::url('invoice', ['id' => $last, 'embed' => 1]) : BizPrint::url('invoice', ['sample' => 1, 'embed' => 1]);
$isCustom = !array_key_exists($d['accent'], BizInvoiceDesign::ACCENTS);

$pageTitle = 'طراحیِ فاکتور';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <div>
        <h1 class="st-h1">طراحیِ فاکتور</h1>
        <p class="st-muted">قالب، رنگ، لوگو و بخش‌های فاکتورِ چاپی را خودتان بچینید. اندازه‌ی کاغذ و حاشیه در <a href="<?= h(Biz::url('print-settings.php')) ?>">تنظیمات چاپ</a> است.</p>
    </div>
    <div class="st-head-actions">
        <a class="st-btn st-btn-ghost" href="<?= h(BizPrint::url('invoice', $last > 0 ? ['id' => $last] : ['sample' => 1])) ?>">نمایشِ برگه</a>
    </div>
</div>

<?php if (!$ready): ?>
<div class="st-card"><p class="st-flash st-flash-warn">طراحیِ فاکتور هنوز روی این نصب راه نیفتاده است (مهاجرتِ پایگاه‌داده اجرا نشده).</p></div>
<?php else: ?>
<div class="st-design">
    <div class="st-design-side">
        <section class="st-card st-form">
            <h2 class="st-h2">لوگو</h2>
            <?php if ($logo !== null): ?>
            <div class="st-logo-now"><img src="<?= h(BizInvoiceDesign::dataUri($logo)) ?>" alt="لوگوی فعلی"></div>
            <?php else: ?>
            <p class="st-muted">هنوز لوگویی نگذاشته‌اید. PNG با زمینه‌ی شفاف بهترین نتیجه را می‌دهد.</p>
            <?php endif; ?>
            <form method="post" action="<?= h($self) ?>" enctype="multipart/form-data" class="st-logo-form">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="logo">
                <input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/gif" required aria-label="فایلِ لوگو">
                <button type="submit" class="st-btn st-btn-sm"><?= $logo !== null ? 'جایگزینی' : 'بارگذاری' ?></button>
            </form>
            <p class="st-muted-i">PNG، JPG، WEBP یا GIF تا ۳ مگابایت. تصویر کوچک و از نو ساخته می‌شود؛ فایلِ اصلی نگه داشته نمی‌شود.</p>
            <?php if ($logo !== null): ?>
            <form method="post" action="<?= h($self) ?>" onsubmit="return confirm('لوگو برداشته شود؟');">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="logo_remove">
                <button type="submit" class="st-link-btn st-link-danger">برداشتنِ لوگو</button>
            </form>
            <?php endif; ?>
        </section>

        <form method="post" action="<?= h($self) ?>" class="st-card st-form st-design-form" data-design-form>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save">

            <fieldset class="st-fieldset">
                <legend>قالب</legend>
                <div class="st-tpl-grid">
                    <?php foreach (BizInvoiceDesign::TEMPLATES as $k => $l): ?>
                    <label class="st-tpl st-tpl-<?= h($k) ?>">
                        <input type="radio" name="template" value="<?= h($k) ?>"<?= $d['template'] === $k ? ' checked' : '' ?>>
                        <span class="st-tpl-thumb" aria-hidden="true"><i></i><i></i><i></i></span>
                        <span><?= h($l) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <fieldset class="st-fieldset">
                <legend>رنگِ اصلی</legend>
                <div class="st-accents">
                    <?php foreach (BizInvoiceDesign::ACCENTS as $hex => $l): ?>
                    <label class="st-accent" title="<?= h($l) ?>">
                        <input type="radio" name="accent" value="<?= h($hex) ?>"<?= $d['accent'] === $hex ? ' checked' : '' ?>>
                        <span style="background: <?= h($hex) ?>" aria-hidden="true"></span>
                        <span class="st-sr"><?= h($l) ?></span>
                    </label>
                    <?php endforeach; ?>
                    <label class="st-accent st-accent-custom" title="رنگِ دلخواه">
                        <input type="radio" name="accent" value="<?= h($isCustom ? $d['accent'] : '#334155') ?>"<?= $isCustom ? ' checked' : '' ?> data-accent-custom>
                        <input type="color" value="<?= h($isCustom ? $d['accent'] : '#334155') ?>" aria-label="رنگِ دلخواه" data-accent-picker>
                    </label>
                </div>
            </fieldset>

            <fieldset class="st-fieldset">
                <legend>لوگو روی برگه</legend>
                <?php foreach (['logo_pos' => 'جای لوگو', 'logo_size' => 'اندازه‌ی لوگو'] as $ok => $ol): ?>
                <p class="st-seg-label"><?= h($ol) ?></p>
                <div class="st-seg" role="radiogroup" aria-label="<?= h($ol) ?>">
                    <?php foreach (BizInvoiceDesign::OPTIONS[$ok] as $v => $l): ?>
                    <label class="st-seg-opt"><input type="radio" name="<?= h($ok) ?>" value="<?= h($v) ?>"<?= $d[$ok] === $v ? ' checked' : '' ?>><span><?= h($l) ?></span></label>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </fieldset>

            <fieldset class="st-fieldset">
                <legend>عنوان‌ها</legend>
                <label class="st-field"><span>عنوانِ فاکتورِ فروش</span>
                    <input type="text" name="title_sale" maxlength="<?= BizInvoiceDesign::TEXTS['title_sale'] ?>" value="<?= h($d['title_sale']) ?>" placeholder="فاکتور فروش — مثلاً «صورتحسابِ فروشِ کالا و خدمات»"></label>
                <label class="st-field"><span>عنوانِ فاکتورِ خرید</span>
                    <input type="text" name="title_purchase" maxlength="<?= BizInvoiceDesign::TEXTS['title_purchase'] ?>" value="<?= h($d['title_purchase']) ?>" placeholder="فاکتور خرید"></label>
            </fieldset>

            <?php foreach (BizInvoiceDesign::FLAGS as $group => $flags): ?>
            <fieldset class="st-fieldset">
                <legend><?= h($group) ?></legend>
                <?php foreach ($flags as $k => $l): ?>
                <label class="st-check"><input type="checkbox" name="<?= h($k) ?>" value="1"<?= $d[$k] ? ' checked' : '' ?>> <?= h($l) ?></label>
                <?php endforeach; ?>
                <?php if ($group === 'پایینِ برگه'): ?>
                <div class="st-row2">
                    <label class="st-field"><span>برچسبِ امضای فروشنده</span>
                        <input type="text" name="sign_seller" maxlength="<?= BizInvoiceDesign::TEXTS['sign_seller'] ?>" value="<?= h($d['sign_seller']) ?>" placeholder="امضای فروشنده"></label>
                    <label class="st-field"><span>برچسبِ امضای خریدار</span>
                        <input type="text" name="sign_buyer" maxlength="<?= BizInvoiceDesign::TEXTS['sign_buyer'] ?>" value="<?= h($d['sign_buyer']) ?>" placeholder="امضای خریدار"></label>
                </div>
                <label class="st-field"><span>شرایط و توضیحاتِ ثابت (زیرِ جدول)</span>
                    <textarea name="terms" rows="3" maxlength="<?= BizInvoiceDesign::TEXTS['terms'] ?>" placeholder="مثلاً: کالای فروخته‌شده تا ۷ روز با فاکتور تعویض می‌شود."><?= h($d['terms']) ?></textarea></label>
                <?php endif; ?>
            </fieldset>
            <?php endforeach; ?>

            <div class="st-danger-row">
                <button type="submit" class="st-btn">ذخیره‌ی طراحی</button>
            </div>
        </form>

        <form method="post" action="<?= h($self) ?>" onsubmit="return confirm('طراحی به حالتِ پیش‌فرض برگردد؟ لوگو می‌ماند.');">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="reset">
            <button type="submit" class="st-link-btn">برگشت به طراحیِ پیش‌فرض</button>
        </form>
    </div>

    <section class="st-card st-design-preview">
        <div class="st-card-head">
            <h2 class="st-h3">پیش‌نمایش</h2>
            <span class="st-muted-i">
                <?php if ($last > 0): ?>آخرین فاکتورِ فروش · <a href="<?= h($self . '?pv=sample') ?>">نمونه</a>
                <?php else: ?>فاکتورِ نمونه<?php endif; ?>
            </span>
        </div>
        <iframe src="<?= h($pvBase) ?>" data-design-preview data-base="<?= h($pvBase) ?>" title="پیش‌نمایشِ فاکتور" loading="lazy"></iframe>
        <p class="st-muted-i">بی‌جاوااسکریپت، پیش‌نمایش بعد از «ذخیره» به‌روز می‌شود.</p>
    </section>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
