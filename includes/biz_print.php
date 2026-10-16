<?php
/**
 * ⛔ پوسته‌ی چاپِ محیطِ فروشگاهی — «پرینت حساب» و «تنظیم پرینت».
 *
 * هندسه‌ی برگه (اندازه، جهت، حاشیه، اندازه‌ی قلم) فقط از
 * `Biz::printPrefs()` می‌آید و فقط اینجا به CSS تبدیل می‌شود؛ هر سندی
 * (`store/print.php`) فقط محتوای خودش را می‌نویسد. با دو جای محاسبه،
 * پیش‌نمایشِ صفحه‌ی تنظیمات با کاغذِ واقعی نمی‌خواند.
 *
 * روی صفحه: یک برگه‌ی سفید به اندازه‌ی کاغذ + نوارِ ابزار؛ هنگامِ چاپ نوار
 * پنهان و حاشیه را خودِ `@page` می‌دهد. نه منو، نه `store.css`: برگه‌ی
 * چاپی نباید به استایلِ صفحه‌ها بند باشد.
 */
if (!defined('APP_BASE_PATH')) { http_response_code(404); exit; }

final class BizPrint
{
    /** ⛔ تنها مرجعِ سندهای چاپی — صفحه‌ی «چاپ و گزارش» و `print.php` هر دو از همین. */
    public const DOCS = [
        'stock'   => 'موجودی انبار',
        'prices'  => 'فهرست قیمت',
        'parties' => 'مانده‌ی طرف‌حساب‌ها',
        'party'   => 'صورت‌حساب طرف‌حساب',
        'kardex'  => 'کاردکس کالا',
        'invoice' => 'فاکتور',
        'payment' => 'رسید',
        'sales'   => 'صورت سود و زیان',
        'by_product' => 'به تفکیکِ کالا',
        'by_invoice' => 'به تفکیکِ فاکتور',
        'by_party'   => 'خلاصه‌ی گردشِ اشخاص',
        'serials'    => 'گزارش IMEI گوشی‌ها',
        'partnos'    => 'گزارشِ پارت‌نامبر',
        'legal_journal' => 'دفترِ روزنامه',
        'legal_ledger'  => 'دفترِ کل',
        'account'    => 'گردشِ صندوق و بانک',
        'cheques'    => 'دفترِ چک',
        'repair'     => 'قبضِ پذیرشِ تعمیر',
        'commission' => 'پورسانتِ همکاران',
    ];

    /**
     * ⛔ دسترسیِ هر برگه برای همکاران (`Biz::can()`); `null` = هر عضوِ فروشگاه.
     *    فاکتور و رسید با نوعِ خودِ سند سنجیده می‌شوند (`BizRights`)، پس اینجا `null`.
     *    برگه‌ای که اینجا نیست «گزارش‌ها» می‌خواهد (بسته‌ی پیش‌فرض).
     */
    public const DOC_CAPS = [
        'prices' => null, 'invoice' => null, 'payment' => null, 'party' => 'receive',
        'stock' => 'reports', 'parties' => 'reports', 'kardex' => 'reports', 'sales' => 'reports',
        'by_product' => 'reports', 'by_invoice' => 'reports', 'by_party' => 'reports', 'serials' => 'reports', 'partnos' => 'reports',
        'legal_journal' => 'accounting', 'legal_ledger' => 'accounting',
        'account' => 'money', 'cheques' => 'money', 'repair' => 'repairs', 'commission' => 'reports',
    ];

    /** عنوانِ سندهای دوسویه — `?k=sale|purchase`. */
    public const SIDE_TITLES = [
        'by_product' => ['sale' => 'فروش به تفکیکِ کالا', 'purchase' => 'خرید به تفکیکِ کالا'],
        'by_invoice' => ['sale' => 'فروش به تفکیکِ فاکتور (با سود)', 'purchase' => 'خرید به تفکیکِ فاکتور'],
    ];

    /**
     * ⛔ «همه‌ی گزارش‌ها» — تنها مرجعِ فهرستِ گزارش‌ها، به گروه‌هایی مثلِ
     *    نرم‌افزارهای حسابداری (مالی، اشخاص، کالا، خرید و فروش، خزانه).
     *    `input` می‌گوید فرمِ هر قلم چه می‌خواهد: `period` بازه‌ی همان صفحه را
     *    پنهان می‌فرستد، `party`/`product`/`account` یک انتخاب، `pfilter`
     *    صافیِ کالا، `cfilter` صافیِ طرف‌حساب، `sfilter` صافیِ IMEI، `chfilter`
     *    صافیِ چک.
     *    تست ثابت می‌کند هر `doc` اینجا در `DOCS` هست و هر سندِ گزارشی اینجا.
     */
    public const HUB = [
        'مالی' => [
            ['doc' => 'sales', 'label' => 'صورت سود و زیان', 'input' => 'period',
             'desc' => 'فروشِ خالص، بهای تمام‌شده، سودِ ناخالص، هزینه‌ها به تفکیکِ شرح و سودِ خالص.'],
            ['doc' => 'legal_journal', 'label' => 'دفترِ روزنامه‌ی قانونی', 'input' => 'period',
             'desc' => 'سندِ افتتاحیه و یک سندِ خلاصه در هر روز به تفکیکِ حسابِ کل، با نقلِ جمع از صفحه‌ای به صفحه‌ی دیگر.'],
            ['doc' => 'legal_ledger', 'label' => 'دفترِ کلِ قانونی', 'input' => 'period',
             'desc' => 'هر حساب با مانده‌ی اول دوره، ردیف‌های روزنامه با شماره‌ی سند و مانده‌ی جاری (بد/بس).'],
        ],
        'اشخاص' => [
            ['doc' => 'parties', 'label' => 'بدهکاران و بستانکاران', 'input' => 'cfilter',
             'desc' => 'مانده‌ی بدهکار و بستانکارِ هر مشتری و تأمین‌کننده، با جمع.'],
            ['doc' => 'party', 'label' => 'کارتِ حسابِ اشخاص', 'input' => 'party',
             'desc' => 'گردش و مانده‌ی یک طرف‌حساب، با جای امضا.'],
            ['doc' => 'by_party', 'label' => 'خلاصه‌ی فاکتور به تفکیکِ شخص', 'input' => 'period',
             'desc' => 'فروش، خرید، برگشت‌ها، دریافت و پرداختِ هر شخص در بازه، با مانده‌ی امروز.'],
        ],
        'کالا و انبار' => [
            ['doc' => 'stock', 'label' => 'موجودی کالا', 'input' => 'pfilter',
             'desc' => 'موجودی، میانگینِ بهای خرید و ارزشِ هر کالا، به تفکیکِ دسته.'],
            ['doc' => 'prices', 'label' => 'فهرستِ قیمت', 'input' => 'pfilter',
             'desc' => 'نام، کد، واحد و قیمتِ فروشِ همه‌ی کالاها و خدمت‌ها.'],
            ['doc' => 'kardex', 'label' => 'کارتِ حسابِ کالا (کاردکس)', 'input' => 'product',
             'desc' => 'همه‌ی ورود و خروج‌های یک کالا با مانده‌ی بعد از هر حرکت.'],
            ['doc' => 'serials', 'label' => 'گزارشِ شماره سریال (IMEI)', 'input' => 'sfilter',
             'desc' => 'هر گوشی: از چه کسی و کِی خریده شد، کجاست، و به چه کسی فروخته شد.'],
            ['doc' => 'partnos', 'label' => 'گزارشِ پارت‌نامبر', 'input' => 'none',
             'desc' => 'گوشی‌ها به تفکیکِ پارت‌نامبر: منطقه، نو یا بازسازی‌شده، در انبار و فروخته، و میانگینِ بهای خرید و فروش.'],
        ],
        'خرید و فروش' => [
            ['doc' => 'by_product', 'k' => 'sale', 'label' => 'فروش به تفکیکِ کالا', 'input' => 'period',
             'desc' => 'تعداد، مبلغ، بهای تمام‌شده و سودِ هر کالا — خالصِ برگشت.'],
            ['doc' => 'by_product', 'k' => 'purchase', 'label' => 'خرید به تفکیکِ کالا', 'input' => 'period',
             'desc' => 'تعداد و مبلغِ خریدِ هر کالا — خالصِ برگشت از خرید.'],
            ['doc' => 'by_invoice', 'k' => 'sale', 'label' => 'فروش به تفکیکِ فاکتور و سودِ فاکتور', 'input' => 'period',
             'desc' => 'هر فاکتورِ فروش و برگشتی با مشتری، مبلغ، دریافت‌شده، مانده و سود.'],
            ['doc' => 'by_invoice', 'k' => 'purchase', 'label' => 'خرید به تفکیکِ فاکتور', 'input' => 'period',
             'desc' => 'هر فاکتورِ خرید و برگشتی با فروشنده، مبلغ، پرداخت‌شده و مانده.'],
            ['doc' => 'commission', 'label' => 'پورسانتِ همکاران', 'input' => 'period',
             'desc' => 'فروشِ خالصِ بی‌مالیاتِ هر همکار (منهای برگشتیِ فاکتورهای خودش) و پورسانتش.'],
        ],
        'خزانه' => [
            ['doc' => 'account', 'label' => 'گردشِ حسابِ صندوق و بانک', 'input' => 'account',
             'desc' => 'مانده‌ی ابتدای بازه، هر ورود و خروج با مانده‌ی جاری، و مانده‌ی پایان.'],
            ['doc' => 'cheques', 'label' => 'دفترِ چک‌های دریافتی و پرداختی', 'input' => 'chfilter',
             'desc' => 'چک‌های در جریان به ترتیبِ سررسید، یا وصول‌شده و برگشتی، با جمع.'],
        ],
    ];

    /** آدرسِ یک سند — تنها سازنده‌ی لینکِ چاپ. */
    public static function url(string $doc, array $params = []): string
    {
        return Biz::url('print.php') . '?' . http_build_query(['doc' => $doc] + array_filter($params, fn($v) => $v !== '' && $v !== null));
    }

    /** عرض و ارتفاعِ کاغذ به میلی‌متر؛ رول ارتفاع ندارد. */
    public const PAPER_MM = ['a4' => [210, 297], 'a5' => [148, 210], '80mm' => [80, 0], '58mm' => [58, 0]];
    private const MARGIN_MM = ['narrow' => 7, 'normal' => 12, 'wide' => 18];
    private const MARGIN_ROLL_MM = ['narrow' => 2, 'normal' => 3, 'wide' => 5];
    private const FONT_PX = ['sm' => 11, 'md' => 13, 'lg' => 15];
    private const FONT_ROLL_PX = ['sm' => 10, 'md' => 11, 'lg' => 13];

    public static function isRoll(array $p): bool
    {
        return (self::PAPER_MM[$p['paper']][1] ?? 1) === 0;
    }

    /**
     * @return array{w:int, h:int, m:int, font:int, css:string}
     */
    public static function geometry(array $p): array
    {
        [$w, $h] = self::PAPER_MM[$p['paper']] ?? self::PAPER_MM['a4'];
        $roll = $h === 0;
        if (!$roll && $p['orient'] === 'landscape') { [$w, $h] = [$h, $w]; }
        $m    = ($roll ? self::MARGIN_ROLL_MM : self::MARGIN_MM)[$p['margin']] ?? 12;
        $font = ($roll ? self::FONT_ROLL_PX : self::FONT_PX)[$p['font']] ?? 13;
        // ⚠ رول اندازه‌ی `@page` نمی‌گیرد: ارتفاعِ ثابت یعنی کاغذِ سفیدِ اضافه
        //   روی فیش‌پرینتر؛ عرض را خودِ برگه نگه می‌دارد.
        $page = $roll ? "@page { margin: {$m}mm; }" : "@page { size: {$w}mm {$h}mm; margin: {$m}mm; }";
        $inner = $w - 2 * $m;
        $css = $page . "\n:root { --pr-font: {$font}px; --pr-w: {$w}mm; --pr-m: {$m}mm; --pr-inner: {$inner}mm; }";
        return ['w' => $w, 'h' => $h, 'm' => $m, 'font' => $font, 'css' => $css];
    }

    /** سرآیندِ برگه — `$back` جایی است که «بازگشت» می‌برد. */
    public static function head(int $userId, string $title, string $back, array $meta = []): void
    {
        $p    = Biz::printPrefs($userId);
        $set  = Biz::settings($userId);
        $geo  = self::geometry($p);
        $shop = $set['shop_name'] !== '' ? $set['shop_name'] : Auth::fullName();
        ?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <title><?= h($title . ' · ' . $shop) ?></title>
    <?php foreach (assetUrls(['css/store-print.css']) as $u): ?>
    <link rel="stylesheet" href="<?= h($u) ?>">
    <?php endforeach; ?>
    <style><?= $geo['css'] ?></style>
</head>
<body class="pr-body<?= self::isRoll($p) ? ' pr-roll' : '' ?>" data-paper="<?= h((string)$p['paper']) ?>">
<div class="pr-bar">
    <button type="button" class="pr-btn pr-btn-main" onclick="window.print()">چاپ</button>
    <a class="pr-btn" href="<?= h($back) ?>">بازگشت</a>
    <a class="pr-btn" href="<?= h(Biz::url('print-settings.php')) ?>">تنظیماتِ چاپ</a>
    <span class="pr-bar-note"><?= h(Biz::PRINT_OPTIONS['paper'][$p['paper']]) ?><?= self::isRoll($p) ? '' : ' · ' . h(Biz::PRINT_OPTIONS['orient'][$p['orient']]) ?></span>
</div>
<article class="pr-sheet">
    <?php if ($p['show_header']): ?>
    <header class="pr-head">
        <h1 class="pr-shop"><?= h($shop) ?></h1>
        <?php if ($p['show_contact'] && ($set['phone'] !== '' || $set['address'] !== '')): ?>
        <p class="pr-contact">
            <?php if ($set['phone'] !== ''): ?><span>تلفن: <span class="pr-num" dir="ltr"><?= h($set['phone']) ?></span></span><?php endif; ?>
            <?php if ($set['address'] !== ''): ?><span><?= h($set['address']) ?></span><?php endif; ?>
        </p>
        <?php endif; ?>
        <?php // ⛔ کدهای رسمیِ فروشگاه — فقط آن‌که پر شده؛ برگه‌ی فروشگاهِ بی‌کد عوض نمی‌شود
        $codes = array_filter(array_intersect_key($set, array_flip(Biz::SETTING_CODES)), fn($v) => $v !== '');
        if ($p['show_contact'] && $codes): ?>
        <p class="pr-contact pr-codes">
            <?php foreach ($codes as $ck => $cv): ?><span><?= h(BizCommon::CODES[$ck][2] ?? $ck) ?>: <span class="pr-num" dir="ltr"><?= h($cv) ?></span></span><?php endforeach; ?>
        </p>
        <?php endif; ?>
    </header>
    <?php endif; ?>
    <div class="pr-titlebar">
        <h2 class="pr-title"><?= h($title) ?></h2>
        <?php if ($p['show_date']): ?>
        <span class="pr-date"><?= h(toJalali(date('Y-m-d'))) ?> · <?= h(toPersianDigits(date('H:i'))) ?></span>
        <?php endif; ?>
    </div>
    <?php if ($meta): ?>
    <dl class="pr-meta">
        <?php foreach ($meta as $k => $v): if ($v === '' || $v === null) { continue; } ?>
        <div><dt><?= h($k) ?></dt><dd><?= h((string)$v) ?></dd></div>
        <?php endforeach; ?>
    </dl>
    <?php endif; ?>
        <?php
    }

    /** پایینِ برگه — متنِ پای فاکتور و جای امضا، به انتخابِ تنظیمات. */
    public static function foot(int $userId, array $signs = ['امضای فروشگاه']): void
    {
        $p   = Biz::printPrefs($userId);
        $set = Biz::settings($userId);
        ?>
    <?php if ($p['show_sign'] && $signs): ?>
    <div class="pr-signs">
        <?php foreach ($signs as $s): ?><div class="pr-sign"><?= h($s) ?></div><?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if ($p['show_footer'] && $set['invoice_footer'] !== ''): ?>
    <footer class="pr-foot"><?= nl2br(h($set['invoice_footer'])) ?></footer>
    <?php endif; ?>
</article>
</body>
</html>
        <?php
    }
}
