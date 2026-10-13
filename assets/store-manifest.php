<?php
/**
 * ⛔ مانیفستِ «وب‌اپِ» فروشگاه — جدا از `assets/manifest.php`ِ حساب لند.
 *
 * **گزارشِ مالکِ نصب (با اسکرین‌شات):** فروشگاه را به صفحه‌ی اصلیِ آیفون
 * اضافه کرد؛ داشبورد تمام‌صفحه باز شد، ولی با زدنِ هر قلمِ منوی پایین صفحه
 * در یک **برگه‌ی مرورگر** (دکمه‌ی ×، نشانیِ دامنه، نوارِ ابزارِ سافاری) باز
 * می‌شد. علت: پوسته‌ی فروشگاه هیچ `<link rel="manifest">` ای نداشت، پس iOS
 * نمی‌دانست بقیه‌ی صفحه‌های `/store/` مالِ همان اپ‌اند و هر ناوبری را «بیرون
 * از دامنه‌ی اپ» می‌خواند. خرابی فقط روی گوشیِ نصب‌شده دیده می‌شد.
 *
 * - `scope` = `/store/` و `start_url` = `/store/`: هر صفحه‌ی فروشگاه (ورود و
 *   خروج هم) داخلِ اپ می‌ماند. «دفتر شخصی» (`/index.php`) عمداً بیرون است —
 *   آن اپِ خودش را دارد.
 * - ⛔ `id` جداست، پس روی یک گوشی هم حساب لند نصب می‌شود هم فروشگاه، و یکی
 *   دیگری را جایگزین نمی‌کند.
 * - نامِ فروشگاه اینجا نیست: مانیفست بی‌کوکی خوانده می‌شود و مالِ هیچ کاربری
 *   نیست؛ نامِ زیرِ آیکون را `apple-mobile-web-app-title` (نامِ فروشگاه) و
 *   خودِ کاربر هنگامِ افزودن می‌دهد.
 * - ⛔ آیکون‌ها لوگوی **فروشگاه** است (`Biz::icon()`)، نه آیکونِ حساب لند.
 * - ⚠ iOS مانیفست را **هنگامِ افزودن** می‌خواند: آیکونی که پیش از این نسخه
 *   ساخته شده باید یک بار حذف و دوباره اضافه شود.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/manifest+json; charset=utf-8');

$__scope = APP_BASE_PATH . '/' . Biz::DIR . '/';
$__theme = Biz::PALETTES[array_key_first(Biz::PALETTES)]['theme'];
$manifest = [
    'id'               => $__scope,
    'name'             => APP_NAME . ' — فروشگاه',
    'short_name'       => 'فروشگاه',
    'start_url'        => $__scope,
    'scope'            => $__scope,
    'display'          => 'standalone',
    'dir'              => 'rtl',
    'lang'             => 'fa',
    'background_color' => '#f6f7f9',
    'theme_color'      => $__theme,
    'orientation'      => 'portrait',
    'icons' => [
        ['src' => Biz::icon('192'), 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => Biz::icon('512'), 'sizes' => '512x512', 'type' => 'image/png'],
        ['src' => Biz::icon('512-maskable'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
];

$json = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$etag = '"' . md5($json) . '"';
// ⛔ `no-cache` + ETag، نه `max-age=86400`: با کشِ یک‌روزه، گوشی‌ای که همین دیروز
//    فروشگاه را باز کرده تا ۲۴ ساعت مانیفستِ **کهنه** (آیکونِ قبلی) را هنگامِ
//    «افزودن به صفحه‌ی اصلی» می‌خواند. بازسنجی یک ۳۰۴ِ چندبایتی است.
header('Cache-Control: no-cache');
header('ETag: ' . $etag);
if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}
echo $json;
