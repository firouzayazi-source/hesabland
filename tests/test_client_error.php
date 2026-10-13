<?php
/**
 * تستِ گزارش‌گرِ خطای مرورگر — رفتار، نه شکل.
 *
 * ⛔ چرا این تست وجود دارد: مالکِ نصب در `admin/errors.php` یک خطای
 *    **باز** دید که هیچ‌وقت بسته نمی‌شد — «مرورگر / Script error. /
 *    خط ۰ / ۱ بار». آن رشته را **خودِ مرورگر** می‌سازد، نه کدِ ما:
 *    وقتی اسکریپتی از مبدأ دیگری استثنا بدهد، پیام و فایل و خط و ردِ
 *    پشته پنهان می‌شوند و فقط همین می‌ماند. همه‌ی اسکریپت‌های این اپ
 *    هم‌مبدأ می‌آیند، پس خطای کدِ خودمان هرگز به این شکل نمی‌رسد —
 *    چیزی که به این شکل می‌رسد افزونه‌ی مرورگر یا میزبانِ وب‌ویو است.
 *
 * ⛔ و ثبتش همان «هشدارِ همیشگی» بود: ردیفی بی‌فایل و بی‌خط و بی‌ردِ
 *    پشته که نشانِ نوارِ مدیر را روشن می‌کند و «برطرف شد» هم بسته
 *    نگهش نمی‌دارد، چون رخدادِ بعدی دوباره بازش می‌کند. آن‌وقت مدیر
 *    عادت می‌کند نشان را نادیده بگیرد و خطای **واقعی** هم دیده
 *    نمی‌شود.
 *
 * ⚠ خطرِ خودِ این رفع، گشاد شدنش است: صافی باید **فقط** همان امضای
 *   کور را بیندازد. هر خطای هم‌مبدأ فایل و خط دارد و باید برسد. پس
 *   اینجا هم «می‌اندازد» سنجیده می‌شود هم «نمی‌اندازد» — و مسیرِ
 *   واقعیِ `report()` هم آتش زده می‌شود، نه فقط خودِ صافی. قاعده ۵۵
 *   در `test_api_contract.php` *شکل* را نگه می‌دارد.
 *
 * دیتابیس لازم ندارد؛ فقط node.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';

T::group('خطای کورِ مرورگر («Script error.»)');

exec('command -v node 2>/dev/null', $o, $rc);
if ($rc !== 0) {
    T::skip('صافیِ خطای کور', 'node نصب نیست');
    exit(T::report());
}

/** هر قلم: [برچسب، [پیام، فایل، خط، ردِ پشته]، انتظار] */
$cases = [
    // ⛔ خودِ امضای کور — هر چهار نشانه با هم.
    ['امضای کورِ کروم انداخته می‌شود',   ['Script error.', '', 0, ''],          true],
    ['بدونِ نقطه هم همان است',           ['Script error', '', 0, ''],           true],
    ['فاصله‌ی دور و بر مهم نیست',        ["  Script error.  ", '', 0, ''],      true],
    ['حروفِ بزرگ و کوچک مهم نیست',       ['script error.', '', 0, ''],          true],
    ['فایلِ null هم «خالی» است',          ['Script error.', null, null, null],   true],

    // ⛔ و این‌ها **نباید** بیفتند — خطای واقعیِ هم‌مبدأ همیشه یکی از
    //    این نشانه‌ها را دارد. اگر صافی گشاد شود، همین‌ها بی‌صدا گم
    //    می‌شوند و کلِ لایه بی‌فایده می‌شود.
    ['همان پیام ولی با فایل، نه',        ['Script error.', '/assets/js/app.js', 0, ''],  false],
    ['همان پیام ولی با خط، نه',          ['Script error.', '', 12, ''],         false],
    ['همان پیام ولی با ردِ پشته، نه',    ['Script error.', '', 0, 'at f (app.js:3:1)'],  false],
    ['خطای واقعیِ بی‌فایل هم نه',         ['x is not a function', '', 0, ''],    false],
    ['خطای واقعیِ کامل نه',              ['Cannot read properties of null', '/assets/js/app.js', 91, 'at n'], false],
    ['پیامی که فقط *شامل* آن است نه',    ['Script error. in plugin', '', 0, ''], false],
    ['پیامِ خالی نه',                     ['', '', 0, ''],                       false],
    ['پیامِ null نه (بدونِ خطا)',         [null, '', 0, ''],                     false],
];

/**
 * ⛔ مسیرِ واقعی، نه فقط صافی: سه رویداد آتش زده می‌شود و دیده می‌شود
 *    چه چیزی به سرور رفت. دو خطای کورِ اول **نباید** سهمیه‌ی دوتاییِ
 *    صفحه را بخورند، وگرنه یک افزونه می‌توانست جلوی گزارشِ خطای واقعی
 *    را بگیرد — به همین دلیل صافی پیش از `sent++` است.
 */
$events = [
    ['message' => 'Script error.', 'filename' => '', 'lineno' => 0, 'stack' => ''],
    ['message' => 'Script error.', 'filename' => '', 'lineno' => 0, 'stack' => ''],
    ['message' => 'REAL_BOOM', 'filename' => '/assets/js/app.js', 'lineno' => 42,
     'stack' => 'at boom (app.js:42:7)'],
];

$payload = json_encode([
    'predicate' => array_map(static fn($c) => $c[1], $cases),
    'events'    => $events,
], JSON_UNESCAPED_UNICODE);

$dump = __DIR__ . '/client_error_js_dump.js';
$desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open('node ' . escapeshellarg($dump), $desc, $pipes);

if (!is_resource($proc)) {
    T::skip('صافیِ خطای کور', 'اجرای node ممکن نشد');
    exit(T::report());
}

fwrite($pipes[0], $payload);
fclose($pipes[0]);
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$code = proc_close($proc);

if ($code !== 0) {
    T::fail('اجرای گزارش‌گر در node', trim($err) !== '' ? trim($err) : "کد خروج {$code}");
    exit(T::report());
}

$got = json_decode($out, true);
if (!is_array($got) || !isset($got['predicate'], $got['sent'])
    || count($got['predicate']) !== count($cases)) {
    T::fail('پاسخِ node', 'خروجی خوانده نشد: ' . substr((string)$out, 0, 200));
    exit(T::report());
}

foreach ($cases as $i => [$label, , $want]) {
    T::same($want, $got['predicate'][$i], $label);
}

T::group('مسیرِ واقعی: چه چیزی به سرور می‌رود');

T::same(['REAL_BOOM'], $got['sent'],
    '⛔ فقط خطای واقعی فرستاده می‌شود؛ دو خطای کور نه فرستاده می‌شوند نه سهمیه می‌خورند');
T::same([''], $got['areas'] ?? null, 'بی‌`data-area` (پوسته‌ی حساب‌لند) محیطِ خالی می‌رود — یعنی «از مسیر»');

// ⛔ پوسته‌ی فروشگاه: همان گزارش‌گر با `<html data-area="store">`. بی‌این، خطای
//    جاوااسکریپتِ فروشگاه «حساب‌لند» ثبت می‌شد و در `store/errors.php` نبود.
T::group('محیطِ فروشگاه: `data-area` همراهِ گزارش می‌رود');
$run = static function (string $area) use ($dump, $payload): ?array {
    $p = proc_open('node ' . escapeshellarg($dump), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pp, null, array_merge(getenv(), ['CE_AREA' => $area]));
    if (!is_resource($p)) { return null; }
    fwrite($pp[0], $payload); fclose($pp[0]);
    $o = stream_get_contents($pp[1]); fclose($pp[1]); fclose($pp[2]);
    proc_close($p);
    $j = json_decode((string)$o, true);
    return is_array($j) ? $j : null;
};
$st = $run('store');
T::same(['store'], $st['areas'] ?? null, '⛔ صفحه‌ی فروشگاه `area=store` می‌فرستد');
T::same(['REAL_BOOM'], $st['sent'] ?? null, 'و صافیِ خطای کور همان است (یک گزارش‌گر برای هر دو پوسته)');

exit(T::report());
