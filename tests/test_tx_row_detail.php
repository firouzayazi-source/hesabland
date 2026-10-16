<?php
/**
 * ⛔ جزئیاتِ ردیفِ تراکنش (`renderTransactionRow()`): عنوانِ کامل و «زمانِ ثبت».
 *
 * **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵):** «شرح کامل معامله و نام کالا حتی وقتی باز می‌کنم
 * دیده نمیشه» و «تاریخ ثبت خرابه».
 *
 *   ۱. ردیفِ باز (`.tx-row.expanded`) عنوان را کامل و چندخطی نشان می‌دهد — بسته،
 *      `ellipsis` ابتدای نامِ لاتینِ کالا را می‌برید.
 *   ۲. «زمان ثبت» وقتی روزِ ثبت با «تاریخ»ِ تراکنش یکی نیست، تاریخ هم دارد
 *      (`txCreatedLabel()`)؛ وقتی یکی است فقط ساعت.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
$root = realpath(__DIR__ . '/..');

if (!file_exists($root . '/config/config.php')) {
    T::blocked('جزئیاتِ ردیفِ تراکنش', 'config/config.php وجود ندارد');
    exit(T::report());
}
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';

T::group('۱ — زمانِ ثبت');
T::same(toPersianDigits('20:18'), txCreatedLabel('2026-10-16 20:18:05', '2026-10-16'), 'همان روز: فقط ساعت');
T::same(toJalali('2026-10-17') . '، ساعت ' . toPersianDigits('00:05'),
    txCreatedLabel('2026-10-17 00:05:00', '2026-10-16'), 'روزِ دیگر: تاریخِ ثبت + ساعت');
T::same(toJalali('2026-10-16') . '، ساعت ' . toPersianDigits('09:00'),
    txCreatedLabel('2026-10-16 09:00:00', '2026-09-01'), 'تراکنشِ گذشته که امروز ثبت شده: تاریخِ امروز می‌آید');
T::same('', txCreatedLabel('نامعتبر', '2026-10-16'), 'زمانِ نامعتبر: خالی، نه ۱۳۴۸/۱۰/۱۱');

T::group('۲ — ردیفِ رندرشده');
$tx = [
    'id' => 1, 'type' => 'income', 'amount' => 9368423, 'title' => 'سود فروش iPhone 12 Pro 256GB Pacific Blue',
    'category_name' => 'سود سهام فروشگاه', 'cat_icon' => null, 'cat_color' => null,
    'transaction_date' => '2026-10-15', 'created_at' => '2026-10-16 20:18:00', 'note' => '',
];
ob_start(); renderTransactionRow($tx); $html = (string)ob_get_clean();
T::ok(str_contains($html, 'iPhone 12 Pro 256GB Pacific Blue'), 'عنوانِ کامل در HTML هست');
T::ok(str_contains($html, toJalali('2026-10-16') . '، ساعت ' . toPersianDigits('20:18')), 'زمانِ ثبت با تاریخ', $html);

T::group('۳ — ردیفِ باز عنوان را نمی‌بُرد');
$css = (string)file_get_contents($root . '/assets/css/style.css');
T::ok((bool)preg_match('/\.tx-row\.expanded \.tx-row-title\s*\{[^}]*white-space:\s*normal/', $css),
    '`.tx-row.expanded .tx-row-title` چندخطی است');
T::ok((bool)preg_match('/\.tx-row\.expanded \.tx-row-title\s*\{[^}]*overflow:\s*visible/', $css),
    'و `ellipsis` در حالتِ باز برداشته شده');

exit(T::report());
