<?php
/**
 * چاپِ برچسبِ کالا و IMEI — بارکدِ Code 128 (`BizBarcode`) با نام و قیمت.
 *
 * `?ids=1,2,3` کالاها، `?copies=` نسخه‌ی هر کالا، `?layout=a4|roll`، و `?units=1` برای گوشی:
 * یک برچسب برای **هر دستگاهِ در انبار** با IMEIِ خودش (`BizSerial::inStock()` — تنها تعریفِ «در انبار»).
 * ⛔ بارکد از کدِ کالا (SKU) یا IMEI است — همان که جست‌وجوی فاکتور و فروشِ سریع می‌خوانند؛ کالای
 *    بی‌کد برچسبِ بی‌بارکد می‌گیرد و صفحه می‌گوید.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
Biz::requirePage();
require_once __DIR__ . '/../includes/biz_docview.php';
require_once __DIR__ . '/../includes/biz_insight.php';

$userId = Biz::ownerId();
$ids    = array_values(array_unique(array_filter(array_map('intval', explode(',', toLatinDigits(getParam('ids')))))));
$ids    = array_slice($ids, 0, 300);
$copies = max(1, min(50, (int)toLatinDigits(getParam('copies', '1'))));
$layout = getParam('layout') === 'roll' ? 'roll' : 'a4';
$units  = getParam('units') === '1';
$price  = getParam('price', '1') === '1';
$set    = Biz::settings($userId);
$shop   = $set['shop_name'] !== '' ? $set['shop_name'] : Auth::fullName();

$labels = []; $noCode = [];
foreach ($ids as $pid) {
    $p = BizProducts::get($userId, $pid);
    if (!$p) { continue; }
    $base = ['name' => (string)$p['name'], 'price' => (int)$p['sell_price']];
    if ($units && (int)$p['has_serial'] === 1) {
        foreach (BizSerial::inStock($userId, $pid)['rows'] as $u) {
            $labels[] = $base + ['code' => (string)$u['imei1'], 'sub' => 'IMEI'];
        }
        continue;
    }
    $code = toLatinDigits(trim((string)($p['sku'] ?? '')));
    if (!BizBarcode::encodable($code)) { $noCode[] = (string)$p['name']; $code = ''; }
    for ($i = 0; $i < $copies; $i++) { $labels[] = $base + ['code' => $code, 'sub' => '']; }
}
$back = count($ids) === 1 ? Biz::url('product.php?id=' . $ids[0]) : Biz::url('products.php');
$q = fn(array $over) => Biz::url('labels.php?' . http_build_query(array_merge(
    ['ids' => implode(',', $ids), 'copies' => $copies, 'layout' => $layout, 'units' => $units ? '1' : '0', 'price' => $price ? '1' : '0'], $over)));
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <title>برچسبِ کالا · <?= h($shop) ?></title>
    <?php foreach (assetUrls(['css/store-print.css']) as $u): ?>
    <link rel="stylesheet" href="<?= h($u) ?>">
    <?php endforeach; ?>
    <style>
        .lb-sheet { display: grid; gap: 0; margin: 0 auto; background: #fff; }
        .lb-a4 .lb-sheet { grid-template-columns: repeat(3, 70mm); grid-auto-rows: 37mm; width: 210mm; }
        .lb-roll .lb-sheet { grid-template-columns: 50mm; grid-auto-rows: 30mm; width: 50mm; }
        .lb { box-sizing: border-box; padding: 2mm 3mm; overflow: hidden; display: flex; flex-direction: column; justify-content: space-between;
              border: 0.2mm dashed #ccc; text-align: center; font-family: Vazirmatn, Tahoma, sans-serif; color: #000; }
        .lb-shop { font-size: 7pt; color: #555; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .lb-name { font-size: 8.5pt; font-weight: 700; line-height: 1.25; max-height: 2.5em; overflow: hidden; }
        .lb-bar svg { width: 100%; height: 9mm; display: block; }
        .lb-code { font-size: 7pt; letter-spacing: .5px; direction: ltr; }
        .lb-price { font-size: 10pt; font-weight: 800; }
        .lb-roll .lb-bar svg { height: 8mm; }
        @media print {
            .pr-bar, .lb-note { display: none !important; }
            .lb { border: 0; }
            .lb-roll .lb { break-after: page; }
            body { background: #fff; margin: 0; }
        }
        @page { margin: <?= $layout === 'roll' ? '0' : '10mm 0' ?>; <?= $layout === 'roll' ? 'size: 50mm 30mm;' : 'size: A4 portrait;' ?> }
    </style>
</head>
<body class="pr-body lb-<?= h($layout) ?>">
<div class="pr-bar">
    <button type="button" class="pr-btn pr-btn-main" onclick="window.print()">چاپ</button>
    <a class="pr-btn" href="<?= h($back) ?>">بازگشت</a>
    <a class="pr-btn<?= $layout === 'a4' ? ' is-on' : '' ?>" href="<?= h($q(['layout' => 'a4'])) ?>">برگه‌ی A4 (۲۴تایی)</a>
    <a class="pr-btn<?= $layout === 'roll' ? ' is-on' : '' ?>" href="<?= h($q(['layout' => 'roll'])) ?>">رولِ برچسب ۵۰×۳۰</a>
    <a class="pr-btn" href="<?= h($q(['price' => $price ? '0' : '1'])) ?>"><?= $price ? 'بی‌قیمت' : 'با قیمت' ?></a>
    <?php if (!$units): ?><a class="pr-btn" href="<?= h($q(['copies' => min(50, $copies + 1)])) ?>">+ یک نسخه (<?= toPersianDigits((string)$copies) ?>)</a><?php endif; ?>
</div>
<?php if ($noCode): ?><p class="lb-note pr-bar-note">بی‌بارکد (کدِ کالا ندارند): <?= h(implode('، ', array_slice($noCode, 0, 10))) ?> — در صفحه‌ی کالا «کد» بگذارید.</p><?php endif; ?>
<?php if (!$labels): ?><p class="lb-note pr-bar-note">برچسبی برای چاپ نیست<?= $units ? ' (گوشیِ در انباری با IMEI پیدا نشد)' : '' ?>.</p><?php endif; ?>
<div class="lb-sheet">
    <?php foreach ($labels as $l): ?>
    <div class="lb">
        <div class="lb-shop"><?= h($shop) ?></div>
        <div class="lb-name"><?= h($l['name']) ?></div>
        <?php if ($l['code'] !== ''): ?>
        <div class="lb-bar"><?= BizBarcode::svg($l['code']) ?></div>
        <div class="lb-code" data-latin><?= $l['sub'] !== '' ? h($l['sub']) . ' ' : '' ?><?= h($l['code']) ?></div>
        <?php endif; ?>
        <?php if ($price && $l['price'] > 0): ?><div class="lb-price"><?= formatMoney($l['price']) ?> تومان</div><?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
</body>
</html>
