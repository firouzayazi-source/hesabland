<?php
/**
 * ⛔ فاکتور برای خودِ مشتری — لینکِ امضاشده، **بی‌ورود** (`BizShare`).
 *
 * تنها صفحه‌ی فروشگاه که `Biz::requirePage()` ندارد (کنارِ ورود و خروج): مشتری حسابی ندارد.
 * پس هر چیزی جز همین یک فاکتور بسته است:
 * - امضای HMAC (`BizShare::verify()`، مقایسه‌ی زمان‌ثابت) با نمکِ فروشگاه — «باطل کردنِ همه‌ی
 *   لینک‌ها» در تنظیمات فقط نمک را عوض می‌کند.
 * - فقط فاکتورِ **فروشِ صادرشده** (پیش‌نویس، خرید و باطل نه)؛ بی‌مانده‌ی حساب و بی‌سود.
 * - پاسخِ هر شکست یکی است (۴۰۴ِ خنثی) تا وجودِ شناسه‌ها لو نرود؛ `noindex`، `no-store`.
 * - نوارِ بالای برگه هیچ لینکی به داخلِ فروشگاه ندارد (`public`).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/biz_docs.php';
require_once __DIR__ . '/../includes/biz_docview.php';
require_once __DIR__ . '/../includes/biz_invoice_design.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$shopId = (int)getParam('s', '0');
$invId  = (int)getParam('i', '0');
$inv    = BizShare::verify($shopId, $invId, (string)getParam('t')) ? BizInvoices::get($shopId, $invId) : null;
if (!$inv || $inv['kind'] !== 'sale' || $inv['status'] !== 'issued') {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="robots" content="noindex"><title>پیدا نشد</title>'
       . '<body style="font-family:sans-serif;text-align:center;padding:3rem">این فاکتور پیدا نشد یا لینکش دیگر معتبر نیست.</body></html>';
    exit;
}
$design = BizInvoiceDesign::get($shopId);
BizInvoiceDesign::render($shopId, $inv, $design, [
    'back'    => '',
    'public'  => true,
    'balance' => null,
    'logo'    => $design['show_logo'] ? BizInvoiceDesign::logo($shopId) : null,
]);
