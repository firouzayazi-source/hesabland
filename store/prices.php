<?php
/**
 * ⛔ لیستِ قیمت برای همکاران و مشتری — لینکِ امضاشده، **بی‌ورود** (`BizPriceList`).
 *
 * کنارِ `view.php` تنها صفحه‌ی بی‌ورودِ فروشگاه (جز ورود/خروج و برگشت از درگاه). پس:
 * - امضای HMAC به‌ازای **هر سطحِ قیمت** با نمکِ فروشگاه (`BizPriceList::verify()`، زمان‌ثابت)؛ سطحی که
 *   روشن نشده باز نمی‌شود؛ صافی و عنوان از تنظیمِ ذخیره‌شده، نه از آدرس.
 * - فقط نام، دسته، قیمتِ همان سطح و «موجود» — نه قیمتِ خرید، نه شمارِ موجودی.
 * - هر شکست یک ۴۰۴ِ خنثی؛ `noindex`، `no-store`، بی‌لینک به داخلِ فروشگاه.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/biz_pricelist.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$shopId = (int)getParam('s', '0');
$level  = (string)getParam('l', '');
$cfg    = BizPriceList::verify($shopId, $level, (string)getParam('t'));
if ($cfg === null) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="robots" content="noindex"><title>پیدا نشد</title>'
       . '<body style="font-family:sans-serif;text-align:center;padding:3rem">این لیست پیدا نشد یا لینکش دیگر معتبر نیست.</body></html>';
    exit;
}
$shop  = Biz::settings($shopId);
$rows  = BizPriceList::rows($shopId, $level, $cfg);
$title = $cfg['title'] !== '' ? $cfg['title'] : 'لیستِ قیمت';
$groups = [];
foreach ($rows as $r) { $groups[$r['category'] !== '' ? $r['category'] : 'سایر'][] = $r; }
$font = assetUrls(['fonts/Vazirmatn.woff2'])[0] ?? '';
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title><?= h($title . ' · ' . ($shop['shop_name'] ?: '')) ?></title>
<style>
@font-face { font-family: 'Vazirmatn'; src: url('<?= h($font) ?>') format('woff2'); font-weight: 100 900; font-display: swap; }
:root { --ink: #111827; --muted: #6b7280; --line: #e5e7eb; --bg: #f8fafc; --card: #fff; --accent: #4f46e5; }
@media (prefers-color-scheme: dark) { :root { --ink: #f3f4f6; --muted: #9ca3af; --line: #374151; --bg: #0b1020; --card: #111827; --accent: #818cf8; } }
* { box-sizing: border-box; }
body { margin: 0; font-family: 'Vazirmatn', Tahoma, sans-serif; background: var(--bg); color: var(--ink); line-height: 1.7; }
.pl { max-width: 720px; margin: 0 auto; padding: 16px; }
.pl-head { text-align: center; padding: 8px 0 16px; }
.pl-head h1 { margin: 0; font-size: 20px; }
.pl-head p { margin: 2px 0; color: var(--muted); font-size: 13px; }
.pl-group { background: var(--card); border: 1px solid var(--line); border-radius: 14px; margin-bottom: 12px; overflow: hidden; }
.pl-group h2 { margin: 0; font-size: 14px; padding: 10px 14px; background: color-mix(in srgb, var(--accent) 10%, transparent); color: var(--accent); }
.pl-row { display: flex; justify-content: space-between; gap: 12px; padding: 9px 14px; border-top: 1px solid var(--line); font-size: 14px; }
.pl-row:first-of-type { border-top: 0; }
.pl-price { font-weight: 700; white-space: nowrap; }
.pl-out { color: var(--muted); font-size: 12px; margin-inline-start: 6px; }
.pl-foot { text-align: center; color: var(--muted); font-size: 12px; padding: 8px 0 24px; }
@media print { body { background: #fff; color: #000; } .pl-group { break-inside: avoid; border-color: #ccc; } }
</style>
</head>
<body>
<main class="pl">
    <header class="pl-head">
        <h1><?= h($shop['shop_name'] !== '' ? $shop['shop_name'] : $title) ?></h1>
        <p><?= h($title) ?> · <?= h(BizProducts::LEVELS[$level]['label']) ?> · <?= h(jalaliLongDate(today())) ?></p>
        <?php if ($shop['phone'] !== ''): ?><p>تلفن: <span dir="ltr"><?= h(toPersianDigits($shop['phone'])) ?></span></p><?php endif; ?>
    </header>
    <?php if (!$rows): ?><p class="pl-foot">فعلاً کالایی در این لیست نیست.</p><?php endif; ?>
    <?php foreach ($groups as $g => $items): ?>
    <section class="pl-group">
        <h2><?= h($g) ?></h2>
        <?php foreach ($items as $r): ?>
        <div class="pl-row"><span><?= h($r['name']) ?><?= $r['available'] ? '' : '<span class="pl-out">ناموجود</span>' ?></span>
            <span class="pl-price"><?= formatMoney($r['price']) ?> تومان</span></div>
        <?php endforeach; ?>
    </section>
    <?php endforeach; ?>
    <p class="pl-foot">قیمت‌ها لحظه‌ای از دفترِ فروشگاه خوانده می‌شوند و ممکن است تغییر کنند.</p>
</main>
</body>
</html>
