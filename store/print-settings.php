<?php
/**
 * تنظیماتِ چاپ — «تنظیم پرینت».
 *
 * گزینه‌ها و پیش‌فرض‌ها فقط `Biz::PRINT_OPTIONS`/`PRINT_FLAGS`/
 * `PRINT_DEFAULTS` است و ذخیره از `Biz::savePrintPrefs()` می‌گذرد. فرمِ
 * POST با CSRF و بعد ریدایرکت.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_print.php';
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_invoice_design.php';

$userId = Biz::ownerId();
$self   = Biz::url('print-settings.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $res = Biz::savePrintPrefs($userId, $_POST);
    redirectWithMessage($self, $res['ok'] ? 'success' : 'error', $res['message']);
}

$p   = Biz::printPrefs($userId);
$geo = BizPrint::geometry($p);
$groupLabel = ['paper' => 'اندازه‌ی کاغذ', 'orient' => 'جهتِ برگه', 'font' => 'اندازه‌ی نوشته', 'margin' => 'حاشیه'];

$pageTitle = 'تنظیمات چاپ';
require __DIR__ . '/../includes/biz_head.php';
?>
<div class="st-page-head">
    <div>
        <h1 class="st-h1">تنظیمات چاپ</h1>
        <p class="st-muted">برای همه‌ی برگه‌های چاپی: صورت‌حساب، موجودی انبار، فهرست قیمت و کاردکس.</p>
    </div>
</div>

<div class="st-cols">
    <form method="post" class="st-card st-form" action="<?= h($self) ?>">
        <?= Csrf::field() ?>
        <?php foreach (Biz::PRINT_OPTIONS as $k => $opts): ?>
        <fieldset class="st-fieldset">
            <legend><?= h($groupLabel[$k] ?? $k) ?></legend>
            <div class="st-seg">
                <?php foreach ($opts as $v => $l): ?>
                <label class="st-seg-opt">
                    <input type="radio" name="<?= h($k) ?>" value="<?= h($v) ?>"<?= $p[$k] === $v ? ' checked' : '' ?>>
                    <?php /* «A4»/«A5» نامِ استاندارد است نه عدد — عمداً لاتین (`test_num_input`) */ ?><span<?= preg_match('/^[\x20-\x7E]+$/', (string)$l) ? ' data-latin' : '' ?>><?= h($l) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <?php if ($k === 'orient'): ?><p class="st-muted-i">برای رولِ فیش‌پرینتر بی‌اثر است.</p><?php endif; ?>
        </fieldset>
        <?php endforeach; ?>

        <fieldset class="st-fieldset">
            <legend>روی برگه بیاید</legend>
            <?php foreach (Biz::PRINT_FLAGS as $k => $l):
                // ⛔ «مانده‌ی قبلی» فقط مالِ فاکتور است و حالا در طراحیِ فاکتور است
                if ($k === 'show_balance') { continue; } ?>
            <label class="st-check"><input type="checkbox" name="<?= h($k) ?>" value="1"<?= $p[$k] ? ' checked' : '' ?>> <?= h($l) ?></label>
            <?php endforeach; ?>
        </fieldset>
        <p class="st-muted-i"><?= BizInvoiceDesign::saved($userId)
            ? 'فاکتور ظاهر و بخش‌هایش را از «طراحیِ فاکتور» می‌گیرد؛ این کلیدها برای گزارش‌ها و رسیدهاست.'
            : 'تا وقتی فاکتور را طراحی نکرده‌اید، این کلیدها روی فاکتور هم اثر دارند.' ?>
            <a href="<?= h(Biz::url('invoice-design.php')) ?>">طراحیِ فاکتور و لوگو</a></p>
        <button type="submit" class="st-btn">ذخیره‌ی تنظیمات</button>
    </form>

    <section class="st-card">
        <h2 class="st-h2">پیش‌نمایش</h2>
        <p class="st-muted">برگه‌ی فعلی: <span class="st-num"><?= toPersianDigits((string)$geo['w']) ?></span> میلی‌متر پهنا<?= $geo['h'] > 0 ? '، <span class="st-num">' . toPersianDigits((string)$geo['h']) . '</span> میلی‌متر بلندی' : ' (رول)' ?>،
            حاشیه‌ی <span class="st-num"><?= toPersianDigits((string)$geo['m']) ?></span> میلی‌متر.</p>
        <div class="st-quick">
            <?php foreach (['stock', 'prices', 'parties'] as $d): ?>
            <a class="st-quick-btn" href="<?= h(BizPrint::url($d)) ?>"><?= h(BizPrint::DOCS[$d]) ?></a>
            <?php endforeach; ?>
        </div>
        <p class="st-muted-i st-soon">نکته: در پنجره‌ی چاپِ مرورگر «سربرگ و پانویس» (Headers and footers) را خاموش کنید تا نشانیِ صفحه و تاریخِ مرورگر روی برگه نیاید؛ اندازه‌ی کاغذ را همین‌جا انتخاب کنید، نه آنجا.</p>
        <p class="st-muted-i">نام، تلفن، نشانی و متنِ پای برگه از <a href="<?= h(Biz::url('settings.php')) ?>">تنظیماتِ فروشگاه</a> می‌آید.</p>
    </section>
</div>
<?php require __DIR__ . '/../includes/biz_foot.php'; ?>
