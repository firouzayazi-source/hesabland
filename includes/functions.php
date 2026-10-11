<?php
// رمزنگاریِ ستون‌های حساسِ حساب. بدون کلید، همه‌ی توابعش عبورِ ساده
// می‌کنند، پس بارگذاری‌اش روی نصب‌های بدون کلید هم بی‌خطر است.
require_once __DIR__ . '/crypto.php';

function h(?string $string): string
{
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * نوارِ «نسخه‌ی آزمایشی» — فقط وقتی `APP_ENV` برابرِ `staging` است.
 *
 * ⛔ دلیلش «دیده نشد» است: روی گوشی، staging و سایتِ اصلی عینِ هم‌اند و
 *    آدم نمی‌فهمد کدام را باز کرده یا کدام کامیت را می‌بیند. این نوار
 *    شناسه‌ی همان کامیت را می‌گوید، پس «در staging دیدم» یعنی «این کامیت
 *    را دیدم» — همان چیزی که `hesabland release` منتشر می‌کند.
 *
 * بی‌کوئری (فقط `var/version.txt`) و با استایلِ درون‌خطی، تا به هیچ CSS
 * و هیچ صفحه‌ای وابسته نباشد؛ و `pointer-events: none` تا جلوی هیچ دکمه‌ای
 * را نگیرد. روی سایتِ اصلی رشته‌ی خالی است — یک بایت هم عوض نمی‌شود.
 */
function envBanner(): string
{
    if (!defined('APP_ENV') || strtolower(trim((string)APP_ENV)) !== 'staging') {
        return '';
    }
    $v = '';
    $f = __DIR__ . '/../var/version.txt';
    if (is_readable($f)) {
        $v = trim((string)strtok((string)file_get_contents($f), "\n"));
    }
    $label = 'نسخه‌ی آزمایشی' . ($v !== '' ? ' · ' . $v : '');
    return '<div class="env-banner" aria-hidden="true" style="position:fixed;z-index:2147483000;'
         . 'top:calc(2px + env(safe-area-inset-top));left:50%;transform:translateX(-50%);'
         . 'background:#b45309;color:#fff;font:600 11px/1.7 Vazirmatn,Tahoma,sans-serif;'
         . 'padding:0 10px;border-radius:999px;pointer-events:none;opacity:.92;direction:rtl;'
         . 'white-space:nowrap">' . h($label) . '</div>';
}

function formatMoney($amount): string
{
    // جداکننده‌ی هزارگان فارسی (٬) — کامای لاتین در متن فارسی بیگانه به نظر می‌رسد
    return toPersianDigits(number_format((float)$amount, 0, '.', '٬'));
}

function toPersianDigits($input): string
{
    $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $latin   = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    return str_replace($latin, $persian, (string)$input);
}

function toLatinDigits($input): string
{
    $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $arabic  = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    $latin   = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    $step1 = str_replace($persian, $latin, (string)$input);
    return str_replace($arabic, $latin, $step1);
}

function sanitizeAmount($input): int
{
    // ⛔ «٫» (U+066B) **فقط** ممیزِ فارسی است (جداکننده‌ی هزارگان «٬» است)، پس
    //    هر چه بعدش آمده کسر است و دور ریخته می‌شود. بی‌این، «۱٫۵» عدد ۱۵ می‌شد
    //    — ده‌برابر، بی‌هیچ هشداری. «.» و «,» عمداً دست نمی‌خورند: «۱.۲۵۰.۰۰۰»
    //    و «1,250,000» هر دو هزارگان‌اند.
    $input = explode('٫', (string)$input, 2)[0];
    $clean = toLatinDigits($input);
    $clean = preg_replace('/[^0-9]/', '', $clean);
    return (int)$clean;
}

/**
 * ⛔ ورودیِ مبلغی که `sanitizeAmount()` بی‌صدا عوضش می‌کند: علامتِ منفی («-250000»
 *    هزینه‌ی مثبت می‌شد، پرداختِ «-400» پرداختِ ۴۰۰) یا نمادِ علمی («1e6» ⇒ ۱۶). فرم‌ها
 *    مبلغ را بی‌علامت می‌خواهند؛ جهت را نوع (درآمد/هزینه، طلب/بدهی) می‌گوید — بازرسیِ مهر ۱۴۰۵.
 */
function amountInputError($raw): ?string
{
    $t = trim(toLatinDigits((string)$raw));
    if (preg_match('/^[\-\x{2212}\x{2013}]/u', $t)) { return 'مبلغ را بی‌علامتِ منفی بنویسید؛ جهت را نوعِ ثبت مشخص می‌کند.'; }
    if (preg_match('/\d\s*[eE]\s*[+\-]?\d/', $t)) { return 'مبلغ را به شکلِ عددِ کامل بنویسید.'; }
    return null;
}

function isValidDate(string $date): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    // ⛔ سالِ معقول: «1403-12-30» تاریخِ **شمسی**ای است که میلادی خوانده شده (۶۰۰ سال عقب)
    //    و `0001-01-01`/`9999-12-31` هم پذیرفته می‌شدند — تراکنشی که در هیچ گزارشی پیدا
    //    نمی‌شد ولی در موجودی بود (بازرسیِ مهر ۱۴۰۵).
    return $d && $d->format('Y-m-d') === $date && (int)$d->format('Y') >= 1900 && (int)$d->format('Y') <= 2200;
}

/**
 * تاریخِ شمسیِ تایپ‌شده (`۱۴۰۴/۱۲/۲۹` یا `1404-12-29`) → میلادیِ `Y-m-d`.
 *
 * ⛔ این یک تقویمِ تازه **نیست**: فقط رشته را به سه عدد می‌شکند و
 *    `jalaliToGregorian()` را صدا می‌زند — همان مبدأیی که کلِ اپ و
 *    `assets/js/jalali-datepicker.js` از آن می‌خوانند و
 *    `test_jalali_parity` هم‌خوانی‌شان را می‌سنجد. پیاده‌سازیِ دومِ
 *    تقویم دیر یا زود یک روز اختلاف پیدا می‌کند.
 *
 * ⚠ درستیِ تاریخ با **رفت‌وبرگشت** سنجیده می‌شود، نه با بازه‌ی عددی:
 *   «۳۱ آبان» هم ۱ تا ۱۲ است هم ۱ تا ۳۱، ولی وجود ندارد. اگر برگشت
 *   همان ورودی را ندهد، تاریخ ساختگی است.
 *
 * @return string|null null یعنی ورودی خالی یا تاریخِ ناموجود
 */
function jalaliStringToGregorian(string $input): ?string
{
    $s = trim(toLatinDigits($input));
    if ($s === '') { return null; }

    if (!preg_match('/^(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})$/', $s, $m)) {
        return null;
    }
    [$jy, $jm, $jd] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    if ($jm < 1 || $jm > 12 || $jd < 1 || $jd > 31) { return null; }

    [$gy, $gm, $gd] = jalaliToGregorian($jy, $jm, $jd);
    $greg = sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
    if (!isValidDate($greg)) { return null; }

    // رفت‌وبرگشت: تاریخِ ناموجود از اینجا رد نمی‌شود.
    $back = toLatinDigits(toJalali($greg));
    return $back === sprintf('%04d/%02d/%02d', $jy, $jm, $jd) ? $greg : null;
}

function jsonResponse(array $data, int $statusCode = 200): void
{
    // ⚠ `request_id` فقط روی خطا و فقط اگر نبوده باشد — کلیدِ افزوده، نه
    //   تغییر: کاربر همان کد را گزارش می‌کند و مالکِ نصب با grep پیدایش
    //   می‌کند. سرآیندِ `X-Request-Id` را `Log::boot()` روی همه‌ی پاسخ‌ها
    //   گذاشته است.
    if ($statusCode >= 400 && !array_key_exists('request_id', $data)) {
        $data['request_id'] = Log::requestId();
    }
    Log::stage('response');
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function postParam(string $key, string $default = ''): string
{
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

function getParam(string $key, string $default = ''): string
{
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
}

function typeLabel(string $type): string
{
    return $type === 'income' ? 'درآمد' : 'هزینه';
}

function toJalali(string $gregorianDate): string
{
    [$gy, $gm, $gd] = array_map('intval', explode('-', $gregorianDate));
    [$jy, $jm, $jd] = gregorianToJalali($gy, $gm, $gd);

    return toPersianDigits($jy) . '/' . toPersianDigits(sprintf('%02d', $jm)) . '/' . toPersianDigits(sprintf('%02d', $jd));
}

function gregorianToJalali(int $gy, int $gm, int $gd): array
{
    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100)
        + intdiv($gy2 + 399, 400) + $gd + $g_d_m[$gm - 1];

    $jy = -1595 + (33 * intdiv($days, 12053));
    $days %= 12053;
    $jy += 4 * intdiv($days, 1461);
    $days %= 1461;

    if ($days > 365) {
        $jy += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }

    if ($days < 186) {
        $jm = 1 + intdiv($days, 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + intdiv($days - 186, 30);
        $jd = 1 + (($days - 186) % 30);
    }

    return [$jy, $jm, $jd];
}

function today(): string
{
    return date('Y-m-d');
}

function startOfWeek(): string
{
    $dayOfWeek = (int)date('w');
    $diff = ($dayOfWeek == 6) ? 0 : ($dayOfWeek + 1);
    return date('Y-m-d', strtotime("-{$diff} days"));
}

function startOfJalaliMonth(): string
{
    $gy = (int)date('Y');
    $gm = (int)date('m');
    $gd = (int)date('d');
    [$jy, $jm, ] = gregorianToJalali($gy, $gm, $gd);
    [$gy2, $gm2, $gd2] = jalaliToGregorian($jy, $jm, 1);
    return sprintf('%04d-%02d-%02d', $gy2, $gm2, $gd2);
}

function startOfJalaliYear(): string
{
    $gy = (int)date('Y');
    $gm = (int)date('m');
    $gd = (int)date('d');
    [$jy, , ] = gregorianToJalali($gy, $gm, $gd);
    [$gy2, $gm2, $gd2] = jalaliToGregorian($jy, 1, 1);
    return sprintf('%04d-%02d-%02d', $gy2, $gm2, $gd2);
}

function jalaliToGregorian(int $jy, int $jm, int $jd): array
{
    $jy += 1595;
    $days = -355668 + (365 * $jy) + (intdiv($jy, 33) * 8) + intdiv(($jy % 33) + 3, 4)
        + $jd + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);

    $gy = 400 * intdiv($days, 146097);
    $days %= 146097;

    if ($days > 36524) {
        $gy += 100 * intdiv(--$days, 36524);
        $days %= 36524;
        if ($days >= 365) {
            $days++;
        }
    }

    $gy += 4 * intdiv($days, 1461);
    $days %= 1461;

    if ($days > 365) {
        $gy += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }

    $gd = $days + 1;
    $sal_a = [0, 31, ((($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0)) ? 29 : 28),
        31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

    $gm = 0;
    for ($i = 1; $i <= 12; $i++) {
        if ($gd <= $sal_a[$i]) {
            $gm = $i;
            break;
        }
        $gd -= $sal_a[$i];
    }

    return [$gy, $gm, $gd];
}

function redirectWithMessage(string $url, string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $url);
    exit;
}

/**
 * ⛔ شکستِ اندپوینتی که هم با فرمِ معمولی صدا زده می‌شود هم با fetch.
 *
 *    با فرم، `jsonResponse` کاربر را در یک صفحه‌ی سفیدِ یک‌خطیِ JSON رها
 *    می‌کرد (در اپِ نصب‌شده حتی بی‌دکمه‌ی بازگشت) — پس به `$page` با پیامِ
 *    روشن برمی‌گردد؛ با fetch همان JSON. تشخیص فقط `Csrf::isJsonRequest()`؛
 *    سه کپیِ دستیِ همین شرط (`exportFail`/`importFail`/`csvFail`) جایش را دادند.
 */
function failJsonOrRedirect(string $message, int $status, string $page): void
{
    if (Csrf::isJsonRequest()) {
        header('Content-Type: application/json; charset=utf-8');
        jsonResponse(['success' => false, 'message' => $message], $status);
    }
    redirectWithMessage($page, 'error', $message);
}

function getFlash(): ?array
{
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * حافظه‌ی کوتاه‌مدتِ `app_settings` — فقط همین درخواست.
 *
 * ⚠ `null` یعنی «ردیف نیست» و با «هنوز نخوانده‌ایم» فرق دارد؛ به همین
 *   دلیل `array_key_exists` می‌سنجد نه `isset`. اگر این دو یکی می‌شدند،
 *   کلیدِ نبوده در هر بار خواندن دوباره کوئری می‌خورد — یعنی دقیقاً همان
 *   چیزی که این کش برای رفعش نوشته شده.
 *
 * @return array<string, string|null>
 */
function &settingsCacheRef(): array
{
    static $cache = [];
    return $cache;
}

/**
 * ⛔ یک کلید در یک درخواست فقط **یک بار** از دیتابیس خوانده می‌شود.
 *
 * دلیلش یک کندیِ اندازه‌گیری‌شده است: `planReadOnly()` در نوارِ کناری و
 * شیتِ «بیشتر» **هفت** بار صدا زده می‌شود (هر نشانِ قفل یک بار) و هر
 * کدام `plan_enforced` را از نو می‌خواند. یعنی **هر صفحه‌ی اپ** شش
 * کوئریِ کاملاً تکراری داشت، و هر نشانِ قفلِ تازه‌ای که فردا اضافه شود
 * یکی هم به آن اضافه می‌کرد — رشدی که در هیچ صفحه‌ای دیده نمی‌شد.
 *
 * ⚠ عمداً بین درخواست‌ها کش نمی‌شود (همان قاعده‌ی `schemaMap()`): مدیر
 *   تنظیمی را عوض می‌کند و درخواستِ بعدی باید واقعیت را ببیند.
 *
 * ⚠ مقدارِ `$default` کش **نمی‌شود**، فقط نبودنِ ردیف. وگرنه
 *   `getSetting('x', '0')` و `getSetting('x', '1')` روی کلیدِ نبوده یک
 *   جواب می‌دادند — و آن یکی از دو تا حتماً غلط بود.
 */
function getSetting(string $key, string $default = ''): string
{
    // ⚠ «همه را خوانده‌ایم» جدا از خودِ نگاشت نگه داشته می‌شود، وگرنه
    //   **کلیدِ نبوده** هر بار کلِ جدول را دوباره می‌خواند — یعنی همان
    //   کوئریِ تکراری، فقط این بار گران‌تر.
    static $loadedAll = false;

    $cache = &settingsCacheRef();
    if (array_key_exists($key, $cache)) {
        return $cache[$key] ?? $default;
    }
    if ($loadedAll) {
        return $default;                 // ردیفی وجود ندارد
    }

    try {
        // ⛔ **کلِ جدول با یک کوئری**، نه یک کوئری برای هر کلید. یک صفحه
        //    سه تا پنج کلیدِ متفاوت می‌خواند (`plan_enforced`،
        //    `support_email`، `sms_method`، …) و هر کدام یک رفت‌وبرگشتِ
        //    جدا بود. اندازه‌گیری شد: کلِ جدول ۰٫۱۷۴ میلی‌ثانیه در برابر
        //    ۰٫۱۱۹ برای **یک** کلید — یعنی از کلیدِ دوم به بعد مجانی است.
        //
        // ⛔ و این تا دیروز عاقلانه **نبود**: `seedUserDefaults()` یک ردیف
        //    به‌ازای هر کاربر اینجا می‌گذاشت و جدول بی‌مرز بزرگ می‌شد.
        //    با `migration_seed_flag` آن ردیف‌ها رفتند و این جدول دوباره
        //    همان چند ده تنظیمِ نصب است. **اگر روزی وسوسه شدید کلیدِ
        //    per-user اینجا بنویسید، این خط همان لحظه غلط می‌شود.**
        //
        // ⚠ «در نگاشت نیست» یعنی ردیفش وجود ندارد — چون همه‌ی ردیف‌ها
        //   خوانده شده‌اند. پس `null` گذاشتن برای کلیدِ نبوده همان معنای
        //   قبلی را دارد و `$default` همچنان کش نمی‌شود.
        $rows = Database::getConnection()
            ->query('SELECT setting_key, setting_value FROM app_settings')
            ->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($rows as $k => $v) { $cache[$k] = (string)$v; }
        $loadedAll = true;
        return $cache[$key] ?? $default;
    } catch (PDOException $e) {
        // ⚠ خطا کش نمی‌شود: یک قطعیِ گذرا نباید تا آخرِ درخواست
        //   تنظیمات را «نبوده» نگه دارد.
        return $default;
    }
}

function setSetting(string $key, string $value): void
{
    // ⛔ مقدارِ قبلی پیش از نوشتن، برای دفترِ ممیزی. فقط تغییری ثبت
    //    می‌شود که (الف) واقعاً مقدار را عوض کند و (ب) کاربری پشتش باشد
    //    — نشانه‌های داخلیِ خودِ برنامه (`more_categories_seeded`، …) که
    //    از cron یا migration می‌آیند ردیفِ ممیزی نمی‌گیرند.
    $old = getSetting($key, "\0");
    $old = $old === "\0" ? null : $old;

    $pdo = Database::getConnection();
    $stmt = $pdo->prepare('
        INSERT INTO app_settings (setting_key, setting_value) VALUES (:key, :value)
        ON DUPLICATE KEY UPDATE setting_value = :value2
    ');
    $stmt->execute(['key' => $key, 'value' => $value, 'value2' => $value]);

    if ($old !== $value && Log::userId() !== null) {
        Audit::setting($key, $old, $value);
    }

    // ⛔ کش همین‌جا تازه می‌شود، وگرنه صفحه‌ای که تنظیم را ذخیره می‌کند و
    //    بعد خودش می‌خواندش، مقدارِ **قبلی** را می‌دید — و آن پیامِ
    //    «ذخیره شد» کنارِ مقدارِ قدیمی، بدترین شکلِ خرابیِ بی‌صداست.
    $cache = &settingsCacheRef();
    $cache[$key] = $value;
}

/**
 * برداشتنِ یک تنظیم — تنها مسیرِ حذف.
 *
 * ⛔ `DELETE FROM app_settings` دستی ننویسید. `getSetting()` در هر
 *    درخواست کش می‌کند و حذفِ خام آن را نمی‌بیند، پس مقدارِ برداشته‌شده
 *    تا آخرِ همان درخواست زنده می‌ماند — **بی‌هیچ خطایی**. همان قاعده‌ی
 *    `saveUserEmail()`: یک نوشتن، یک جا.
 */
function forgetSetting(string $key): void
{
    try {
        Database::getConnection()
            ->prepare('DELETE FROM app_settings WHERE setting_key = :key')
            ->execute(['key' => $key]);
    } catch (PDOException $e) {
        return;   // جدول نیامده — چیزی هم برای حذف نیست
    }

    $cache = &settingsCacheRef();
    $cache[$key] = null;
}

/**
 * نگاشت کد آیکن (که در دیتابیس ذخیره می‌شود) به ایموجی.
 * ایموجی عمداً در دیتابیس ذخیره نمی‌شود تا مشکل کاراکترست پیش نیاید.
 */
/**
 * پالت نمودارها.
 *
 * رنگ در این برنامه معنا دارد، تزئین نیست: دارایی و درآمد سبزند (پول
 * وارد می‌شود / داراییِ ماست) و هزینه گرم است (قرمز، نارنجی، زرد —
 * پول بیرون می‌رود). پس نمودار دسته‌بندی هم بسته به اینکه هزینه را
 * نشان می‌دهد یا درآمد، پالتش عوض می‌شود.
 *
 * ترتیب رنگ‌ها عمداً تیره و روشن یک‌درمیان است تا دو قاچِ کنار هم در
 * دونات به هم نچسبند.
 */
function chartPalette(string $tone, string $mode = 'light'): array
{
    // یک چرخه‌ی ثابت از هشت هیوی متمایز. ترتیبش دلخواه نیست: با
    // scripts/validate_palette.js سنجیده شده و هر جفتِ کنارِ هم، هم برای
    // چشم عادی و هم برای انواع کوررنگی، اختلاف کافی دارد — روی زمینه‌ی
    // روشن و شب، و حتی جفتِ اولی/آخری که در دونات به هم می‌رسند.
    //
    // پله‌های شب همان هشت هیو هستند ولی برای زمینه‌ی تیره دوباره چیده
    // شده‌اند؛ برگرداندن رنگ روشن روی زمینه‌ی تیره کار نمی‌کند (بنفش
    // گم می‌شد و زرد می‌زد توی چشم).
    $cycle = [
        'light' => ['#e34948', '#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#4a3aa7', '#e87ba4', '#008300'],
        'dark'  => ['#e66767', '#3987e5', '#d95926', '#199e70', '#c98500', '#9085e9', '#d55181', '#008300'],
    ];

    // فقط نقطه‌ی شروع فرق می‌کند، نه خود چرخه: رنگ اولِ نمودار باید با
    // موضوعش بخواند (هزینه قرمز، درآمد و دارایی سبز) ولی بقیه‌ی قاچ‌ها
    // باید از هم جدا باشند. پالت تک‌رنگ — همه سبز یا همه نارنجی — قاچ‌ها
    // را به هم می‌چسباند و خواندنش سخت می‌شود.
    $startAt = ['warm' => 0, 'green' => 3];
    $start   = $startAt[$tone] ?? $startAt['green'];

    $colors = $cycle[$mode] ?? $cycle['light'];
    $count  = count($colors);
    $out    = [];
    for ($i = 0; $i < $count; $i++) {
        $out[] = $colors[($start + $i) % $count];
    }

    return $out;
}



/* ============================================================
   کیف پول / حساب مالی
   ============================================================ */

/**
 * برچسب نوع حساب.
 *
 * «سایر» به‌تنهایی چیزی نمی‌گوید، پس اگر کاربر برایش اسم گذاشته باشد
 * (حساب ارزی، صندوق قرض‌الحسنه، …) همان اسم نشان داده می‌شود.
 * 'card' دیگر در فهرست انتخاب نیست ولی حساب‌های قدیمی هنوز دارندش.
 */
function walletKindLabel(string $kind, ?string $label = null): string
{
    if ($kind === 'other' && $label !== null && trim($label) !== '') {
        return trim($label);
    }

    return [
        'cash'  => 'نقدی',
        'bank'  => 'حساب بانکی',
        'card'  => 'کارت بانکی',
        'other' => 'سایر',
    ][$kind] ?? 'سایر';
}

/** نوع‌های دلخواهی که کاربر تا حالا ساخته — برای فهرست انتخاب. */
function walletKinds(int $userId): array
{
    static $cache = [];
    if (isset($cache[$userId])) { return $cache[$userId]; }
    if (!tableExists('wallet_kinds')) { return $cache[$userId] = []; }

    try {
        $st = Database::getConnection()->prepare(
            'SELECT id, name FROM wallet_kinds WHERE user_id = :u ORDER BY name'
        );
        $st->execute(['u' => $userId]);
        $cache[$userId] = $st->fetchAll();
    } catch (PDOException $e) {
        $cache[$userId] = [];
    }

    return $cache[$userId];
}

/**
 * فهرست اشخاصِ کاربر (طرف مقابلِ چک، طلب و بدهی، و معامله).
 *
 * مثل `walletKinds()` در همان درخواست کش می‌شود، چون چند فرم در یک
 * صفحه (افزودن و ویرایش) همین فهرست را می‌خواهند.
 *
 * ⚠ این فقط فهرستِ کمکیِ پر کردنِ فرم است. آنچه در چک و طلب ذخیره
 * می‌شود همچنان `counterparty_name` است، نه شناسه — پس حذف یک شخص از
 * این فهرست هیچ رکوردی را خراب نمی‌کند.
 */
function peopleList(int $userId): array
{
    static $cache = [];
    if (isset($cache[$userId])) { return $cache[$userId]; }
    if (!tableExists('people')) { return $cache[$userId] = []; }

    try {
        $st = Database::getConnection()->prepare(
            'SELECT id, name, role FROM people WHERE user_id = :u ORDER BY name'
        );
        $st->execute(['u' => $userId]);
        $cache[$userId] = $st->fetchAll();
    } catch (PDOException $e) {
        $cache[$userId] = [];
    }

    return $cache[$userId];
}

/**
 * یک `<datalist>` از اشخاص می‌سازد تا هر ورودیِ «نام طرف مقابل» با
 * `list="..."` به آن وصل شود.
 *
 * چرا datalist و نه select: ورودی آزاد باید همچنان کار کند. کاربر
 * ممکن است اسمی را یک بار وارد کند و نخواهد به فهرست اضافه‌اش کند، و
 * رکوردهای قدیمی هم نامی دارند که در فهرست نیست.
 */
/**
 * انتخابگر شخص: یک `<select>` از اشخاص ثبت‌شده به‌علاوه‌ی «سایر»، و
 * زیرش همان ورودیِ متنیِ همیشگی.
 *
 * چرا هر دو با هم: کاربر معمولاً می‌خواهد از فهرست انتخاب کند، ولی
 * باید بتواند اسمی را که در فهرست نیست هم بنویسد — و رکوردهای قدیمی
 * هم نامی دارند که ممکن است در فهرست نباشد. با زدنِ «سایر»، ورودیِ
 * متنی باز می‌شود و رفتار دقیقاً مثل قبل است.
 *
 * چیزی که به سرور می‌رود همچنان `counterparty_name` است — یعنی هیچ
 * اندپوینتی لازم نیست عوض شود.
 *
 * @param string $inputId   شناسه‌ی ورودیِ متنی که باید پر شود
 * @param string $extraAttr صفت‌های اضافه‌ی ورودی (مثل required)
 */
function personPicker(int $userId, string $inputId, string $label, string $placeholder = '', string $extraAttr = ''): string
{
    $people = peopleList($userId);
    $selId  = $inputId . '_select';

    $out = '<div class="form-group">';

    // سمت (همکار / مشتری / …) کنارِ *عنوان* می‌نشیند، نه داخل منو.
    //
    // داخل منو، «اصغر (همکار)» هم شلوغ بود و هم روی گوشی نامِ بلند را
    // می‌برید. حالا منو فقط نام است و سمتِ همان کسی که انتخاب شده،
    // به‌صورت یک نشان کنار عنوان دیده می‌شود.
    $out .= '<div class="person-label-row">';
    $out .= '<label for="' . h($people ? $selId : $inputId) . '">' . h($label) . '</label>';
    if (!empty($people)) {
        $out .= '<span class="person-role-badge js-person-role" hidden></span>';
    }
    $out .= '</div>';

    if (!empty($people)) {
        $out .= '<select id="' . h($selId) . '" class="js-person-select" data-target="' . h($inputId) . '">';
        $out .= '<option value="">— انتخاب کنید —</option>';
        foreach ($people as $p) {
            $role = trim((string)$p['role']);
            // سمت روی خودِ گزینه می‌ماند تا جاوااسکریپت بتواند کنار عنوان
            // نشانش دهد، ولی در متنِ گزینه دیده نمی‌شود.
            $out .= '<option value="' . h($p['name']) . '"'
                  . ($role !== '' ? ' data-role="' . h($role) . '"' : '') . '>'
                  . h($p['name']) . '</option>';
        }
        $out .= '<option value="__other__">سایر</option>';
        $out .= '</select>';
    }

    // بدون فهرست، ورودی از اول دیده می‌شود؛ با فهرست، تا زدنِ «سایر» پنهان است.
    $hidden = !empty($people) ? ' hidden' : '';
    $out .= '<input type="text" id="' . h($inputId) . '" name="counterparty_name" maxlength="150"'
          . ($placeholder !== '' ? ' placeholder="' . h($placeholder) . '"' : '')
          . ' list="peopleList" autocomplete="off" class="js-person-input"' . $hidden
          . ($extraAttr !== '' ? ' ' . $extraAttr : '') . '>';

    if (!empty($people)) {
        $out .= '<p class="hint js-person-hint">از فهرست انتخاب کنید، یا «سایر» را بزنید و نام را بنویسید.'
              . ' <a href="' . APP_BASE_PATH . '/references.php">افزودن شخص تازه</a></p>';
    }

    return $out . '</div>';
}

function peopleDatalist(int $userId, string $id = 'peopleList'): string
{
    $people = peopleList($userId);
    if (empty($people)) { return ''; }

    $out = '<datalist id="' . h($id) . '">';
    foreach ($people as $p) {
        $role = trim((string)$p['role']);
        $out .= '<option value="' . h($p['name']) . '"'
              . ($role !== '' && $role !== 'سایر' ? ' label="' . h($role) . '"' : '')
              . '></option>';
    }
    return $out . '</datalist>';
}

/**
 * موجودی هر کیف پول را برمی‌گرداند.
 *
 * موجودی = موجودی اولیه
 *        + درآمدهای ثبت‌شده روی آن حساب
 *        − هزینه‌های ثبت‌شده روی آن حساب
 *        + انتقال‌های واردشده
 *        − انتقال‌های خارج‌شده (به‌علاوه کارمزد)
 *        ± معامله‌های وصل‌شده به حساب (خرید کم، فروش زیاد)
 *        ± چک‌های پاس‌شده (دریافتی زیاد، صادره کم)
 *        ± پرداخت‌های طلب و بدهی (وصول طلب زیاد، پرداخت بدهی کم)
 *
 * همه‌چیز در یک کوئری محاسبه می‌شود تا گزارش و داشبورد
 * هیچ‌وقت دو عدد متفاوت نشان ندهند.
 */
/**
 * نقشه‌ی ساختار دیتابیس: کدام جدول‌ها هستند و هر کدام چه ستون‌هایی دارند.
 *
 * چند جای برنامه باید بدانند فلان migration اجرا شده یا نه. نسخه‌ی اول
 * برای هر پرسش یک کوئری جدا به information_schema می‌زد و فقط داشبورد
 * پنج‌تایش را می‌زد — و information_schema ارزان نیست. حالا یک بار همه‌ی
 * نقشه خوانده می‌شود و بقیه‌ی پرسش‌ها از حافظه جواب می‌گیرند.
 *
 * عمداً بین درخواست‌ها کش نمی‌شود: بعد از اجرای یک migration تازه، همان
 * درخواست بعدی باید ساختار واقعی را ببیند، نه نقشه‌ی کهنه را.
 */
function schemaMap(): array
{
    static $map = null;
    if ($map !== null) { return $map; }

    $map = [];
    try {
        $rows = Database::getConnection()->query(
            'SELECT table_name, column_name, is_nullable FROM information_schema.columns
             WHERE table_schema = DATABASE()'
        )->fetchAll(PDO::FETCH_NUM);
        // ⚠ مقدارِ هر ستون «NULL می‌پذیرد؟» است (bool، نه null)، پس همه‌ی
        //   مصرف‌کننده‌های `isset()` دست‌نخورده کار می‌کنند و
        //   `tableColumnNullable()` هم بدونِ کوئریِ دوم جواب می‌گیرد.
        foreach ($rows as [$table, $column, $nullable]) {
            $map[strtolower($table)][strtolower($column)] = strtoupper((string)$nullable) === 'YES';
        }
    } catch (PDOException $e) {
        $map = [];
    }

    return $map;
}

/** آیا این جدول ساخته شده؟ */
function tableExists(string $table): bool
{
    return isset(schemaMap()[strtolower($table)]);
}

/** آیا این ستون در این جدول هست؟ */
function tableHasColumn(string $table, string $column): bool
{
    return isset(schemaMap()[strtolower($table)][strtolower($column)]);
}

/** آیا این ستون NULL می‌پذیرد؟ ستونِ ناموجود = نه. */
function tableColumnNullable(string $table, string $column): bool
{
    return (schemaMap()[strtolower($table)][strtolower($column)] ?? false) === true;
}

/**
 * ⛔ آیا سررسیدِ طلب/بدهی اختیاری است؟ (`migration_debt_optional_due`)
 *
 * تنها جای این تصمیم: فرم (برچسبِ «اختیاری»)، `api/add_debt` و
 * `api/update_debt` همه از همین می‌پرسند. روی نصبی که migration نخورده
 * ستون `NOT NULL` است و درجِ `NULL` با خطای ۵۰۰ می‌مرد — پس آنجا
 * همان رفتارِ قبلی (الزامی) می‌ماند.
 */
function debtDueOptional(): bool
{
    return tableColumnNullable('debts', 'due_date');
}

/**
 * فهرست حساب‌های فعال کاربر — یک بار در هر درخواست خوانده می‌شود.
 *
 * چند جا همین فهرست را می‌خواهند (صفحه‌ی چک، طلب و بدهی، معاملات، و شیت
 * ثبت تراکنش که در فوتر هر صفحه است)، و بدون کش دو سه بار پشت سر هم
 * همان کوئری می‌رفت.
 */
/**
 * موجودیِ دارایی‌ها به تفکیک نوع، با **ارزشِ روز** و بهای تمام‌شده.
 *
 * ⛔ `assets.unit_price` بهای واحد **هنگام ثبت** است. صفحه‌ی دارایی تا
 *    پیش از این همان را جمع می‌زد و «ارزش کل دارایی‌ها» می‌نامیدش —
 *    که در اقتصادِ تورمی حرفِ بی‌معنایی است: ارزشِ سکه‌ی پارسال هیچ
 *    ربطی به قیمتِ پارسال ندارد. حالا اگر کاربر برای آن نوع نرخِ روز
 *    وارد کرده باشد از آن استفاده می‌شود، وگرنه همان بهای خرید می‌ماند
 *    (رفتارِ قبلی، نه صفر).
 *
 * خروجی هر ردیف: id, name, unit, total_qty, total_value (به نرخِ روز),
 * total_cost (بهای تمام‌شده), priced_value (ارزشِ فقط ثبت‌های بهادار — برای سودِ
 * محقق‌نشده), current_price, price_updated_at, cnt
 */
/**
 * عنوانِ یک ثبتِ دارایی — خطِ اولِ توضیحی که کاربر نوشته («گردن‌بندِ طلا»، «TON»).
 *
 * ⛔ خالی یعنی «عنوان ندارد» و صفحه نامِ نوع را نشان می‌دهد؛ کوتاه می‌شود تا ردیف
 *    نشکند، ولی متنِ کامل در جزئیاتِ همان ردیف می‌ماند.
 */
function assetEntryLabel(?string $note): string
{
    $first = trim((string)strtok(str_replace("\r", '', (string)$note), "\n"));
    return mb_strlen($first) > 60 ? rtrim(mb_substr($first, 0, 59)) . '…' : $first;
}

function assetSummaryRows(int $userId): array
{
    $pdo = Database::getConnection();
    $hasPrice = tableHasColumn('asset_types', 'current_price');

    // ⚠ دو کوئریِ جدا، نه یک رشته‌ی ساخته‌شده با متغیر: قاعده ۴ درجِ
    //   متغیر در SQL را ممنوع کرده و درست هم می‌گوید.
    if ($hasPrice) {
        $sql = 'SELECT at.id, at.name, at.unit, at.current_price, at.price_updated_at,
                       COALESCE(SUM(a.quantity), 0) AS total_qty,
                       COALESCE(SUM(a.quantity * COALESCE(at.current_price, a.unit_price, 0)), 0) AS total_value,
                       COALESCE(SUM(a.quantity * COALESCE(a.unit_price, 0)), 0) AS total_cost,
                       COALESCE(SUM(CASE WHEN a.unit_price IS NOT NULL THEN a.quantity * COALESCE(at.current_price, a.unit_price) END), 0) AS priced_value,
                       COUNT(a.id) AS cnt
                FROM asset_types at
                LEFT JOIN assets a ON a.asset_type_id = at.id AND a.user_id = :user_id
                WHERE at.user_id = :user_id2
                GROUP BY at.id, at.name, at.unit, at.current_price, at.price_updated_at
                HAVING cnt > 0
                ORDER BY total_value DESC, at.name';
    } else {
        $sql = 'SELECT at.id, at.name, at.unit,
                       NULL AS current_price, NULL AS price_updated_at,
                       COALESCE(SUM(a.quantity), 0) AS total_qty,
                       COALESCE(SUM(a.quantity * COALESCE(a.unit_price, 0)), 0) AS total_value,
                       COALESCE(SUM(a.quantity * COALESCE(a.unit_price, 0)), 0) AS total_cost,
                       COALESCE(SUM(a.quantity * COALESCE(a.unit_price, 0)), 0) AS priced_value,
                       COUNT(a.id) AS cnt
                FROM asset_types at
                LEFT JOIN assets a ON a.asset_type_id = at.id AND a.user_id = :user_id
                WHERE at.user_id = :user_id2
                GROUP BY at.id, at.name, at.unit
                HAVING cnt > 0
                ORDER BY total_value DESC, at.name';
    }

    try {
        $st = $pdo->prepare($sql);
        $st->execute(['user_id' => $userId, 'user_id2' => $userId]);
        return $st->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * اقلامِ «خالص دارایی» — تنها جای این محاسبه.
 *
 * ⛔ پیش از این همین منطق داخلِ خودِ `my-assets.php` بود. با آمدنِ
 *    خانه‌ی دسکتاپ (که عددِ «خالص دارایی» را کنارِ «مجموع حساب‌ها»
 *    نشان می‌دهد) باید بار دوم نوشته می‌شد، و دو نسخه از منطقِ پول
 *    دیر یا زود دو عدد می‌گویند — آن‌وقت کاربر روی خانه یک عدد می‌بیند
 *    و در صفحه‌ی دارایی عددِ دیگری، و به هیچ‌کدام اعتماد نمی‌کند.
 *
 * هر ردیف: name, qty, unit, value, kind. `kind` همان نامی است که
 * `netWorthSnapParts()` سطلِ عکسِ روزانه را از رویش پیدا می‌کند.
 *
 * ⛔ هیچ درخواستِ شبکه‌ای نمی‌زند: تازه کردنِ آینه‌ی فروشگاه کارِ
 *    فراخواننده است (فقط `my-assets.php`)، وگرنه خانه در هر بارگذاری
 *    یک درخواستِ HTTP به فروشگاه می‌زد. آینه را cronِ هر دقیقه تازه
 *    نگه می‌دارد.
 *
 * خروجی: ['rows' => مرتب از بزرگ به کوچک, 'total' => int,
 *          'trade_open_count', 'cheque_count', 'debt_count',
 *          'store_share' => ?array, 'owed_mine' => bool]
 */
function netWorthPortfolio(int $userId, bool $isAdmin, ?array $walletRows = null, ?array $assetSummary = null): array
{
    $pdo = Database::getConnection();
    $rows = [];

    // ⚠ صفحه‌ی دارایی همین ردیف‌ها را برای فهرستش هم لازم دارد؛ پاس دادنشان
    //   یک کوئریِ تکراری را برمی‌دارد.
    foreach ($assetSummary ?? assetSummaryRows($userId) as $s) {
        $rows[] = ['name' => $s['name'], 'qty' => (float)$s['total_qty'], 'unit' => $s['unit'],
                   'value' => (int)$s['total_value'], 'kind' => 'asset'];
    }

    // کالای بازِ بخش معاملات **یک قلمِ جمع‌شده** است، نه یکی به‌ازای هر کالا:
    // این نمای ترکیبِ دارایی است و نامِ تک‌تکِ معامله‌ها درصدها را خرد
    // می‌کرد؛ جای دیدنشان صفحه‌ی معاملات است.
    $tradeValue = 0;
    $tradeCount = 0;
    if (tradesTablesExist($pdo) && tradesEnabled($pdo, $userId)) {
        foreach (tradesWithProgress($userId) as $t) {
            if ($t['is_closed']) { continue; }
            $tradeValue += $t['open_cost'];
            $tradeCount++;
        }
    }
    if ($tradeCount > 0) {
        $rows[] = ['name' => 'مجموع دارایی‌های بخش معاملات', 'qty' => (float)$tradeCount,
                   'unit' => 'قلم', 'value' => (int)$tradeValue, 'kind' => 'trade'];
    }

    // ⛔ فقط چکِ **در جریان** — چکِ پاس‌شده پولش از قبل در موجودی حساب
    //    نشسته (walletBalances با settle_wallet_id)، پس آوردنش اینجا یعنی
    //    دوبار شمردن. دریافتی مثبت و صادره منفی.
    // ⛔ و به **ارزشِ امروز** (`chequePresentValue()`)، نه مبلغِ اسمی — چکِ شش‌ماهه‌ی
    //    ۷۰۰ میلیونی امروز ۷۰۰ میلیون ارزش ندارد (مالکِ نصب، مهر ۱۴۰۵). صادره هم همین:
    //    بدهیِ شش ماهِ بعد امروز سبک‌تر است. چکِ بی‌روش همان مبلغ.
    $chequeNet = 0;
    $chequeCount = 0;
    $chequeFace = 0;
    if (tableExists('cheques')) {
        try {
            $cols = tableHasColumn('cheques', 'value_method') ? ', value_method, value_rate, value_base, value_from' : '';
            $cq = $pdo->prepare(
                "SELECT direction, amount, due_date{$cols}
                 FROM cheques
                 WHERE user_id = :u AND " . chequeActiveSql()
            );
            $cq->execute(['u' => $userId]);
            $today = today();
            foreach ($cq->fetchAll() as $r) {
                $sign = $r['direction'] === 'received' ? 1 : -1;
                $chequeCount++;
                $chequeNet  += $sign * chequePresentValue($r, $today);
                $chequeFace += $sign * (int)$r['amount'];
            }
        } catch (PDOException $e) { $chequeCount = 0; $chequeNet = 0; $chequeFace = 0; }
    }
    if ($chequeCount > 0) {
        $rows[] = ['name' => 'خالص چک‌های در جریان', 'qty' => (float)$chequeCount,
                   'unit' => 'چک', 'value' => $chequeNet, 'kind' => 'cheques', 'face' => $chequeFace];
    }

    // ⛔ فقط **باقیمانده** — پرداخت‌های انجام‌شده از قبل در موجودیِ حساب‌ها
    //    هستند (debt_payments.wallet_id).
    $debtNet = 0;
    $debtCount = 0;
    if (tableExists('debts')) {
        try {
            $dq = $pdo->prepare(
                'SELECT direction, amount, paid_amount FROM debts
                 WHERE user_id = :u AND is_settled = 0'
            );
            $dq->execute(['u' => $userId]);
            foreach ($dq->fetchAll() as $d) {
                $remaining = debtRemaining($d);
                if ($remaining === 0) { continue; }
                $debtCount++;
                $debtNet += ($d['direction'] === 'receivable' ? 1 : -1) * $remaining;
            }
        } catch (PDOException $e) { $debtCount = 0; $debtNet = 0; }
    }
    if ($debtCount > 0) {
        $rows[] = ['name' => 'خالص طلب و بدهی', 'qty' => (float)$debtCount,
                   'unit' => 'مورد', 'value' => $debtNet, 'kind' => 'debts'];
    }

    // ⚠ موجودی را فراخواننده پاس می‌دهد، نه کش — درسِ قاعده ۲۹.
    $walletsTotal = 0;
    $walletsCount = 0;
    try {
        foreach ($walletRows ?? walletBalances($userId) as $w) {
            if ((int)$w['is_active'] !== 1) { continue; }
            $walletsTotal += (int)$w['balance'];
            $walletsCount++;
        }
    } catch (PDOException $e) { $walletsCount = 0; }
    if ($walletsCount > 0) {
        $rows[] = ['name' => 'مجموع حساب‌ها', 'qty' => (float)$walletsCount,
                   'unit' => 'حساب', 'value' => $walletsTotal, 'kind' => 'wallets'];
    }

    // ---------- فروشگاه (فقط خواندنِ آینه) ----------
    // ⛔ «دارایی من در فروشگاه» از `StoreShare::valueFor()` می‌آید و هیچ
    //    محاسبه‌ای اینجا نیست؛ دو قلمِ کلِ فروشگاه **فقط برای مدیر**، و
    //    سهمِ خودِ مدیر از «طلب سهامداران» کم می‌شود تا دو بار شمرده نشود.
    $storeShare = null;
    $owedMine   = false;
    require_once __DIR__ . '/store_share.php';
    if (StoreShare::available()) {
        $storeShare = StoreShare::forUser($userId);
        if ($storeShare !== null) {
            $rows[] = ['name' => 'دارایی من در فروشگاه',
                       'qty' => (float)($storeShare['active_count'] ?? 0),
                       'unit' => 'دستگاه', 'value' => (int)StoreShare::valueFor($userId),
                       'kind' => 'store_share'];
        }
        if ($isAdmin) {
            $storeNet  = StoreShare::storeNetWorth();
            $storeOwed = StoreShare::storeOwed($userId);
            $owedMine  = StoreShare::ownShareCounted($userId);
            if ($storeNet !== null) {
                $rows[] = ['name' => 'دارایی خودِ فروشگاه', 'qty' => 1.0, 'unit' => 'فروشگاه',
                           'value' => (int)$storeNet, 'kind' => 'store_total'];
            }
            if ($storeOwed !== null) {
                $rows[] = ['name' => $owedMine ? 'طلب سایر سهامداران فروشگاه' : 'طلب سهامداران فروشگاه',
                           'qty' => 1.0, 'unit' => 'فروشگاه', 'value' => (int)$storeOwed,
                           'kind' => 'store_owed'];
            }
        }
    }

    // بزرگ‌ترین‌ها اول — وقتی اقلام زیاد شوند، مهم‌ها بالا می‌مانند
    usort($rows, fn($a, $b) => $b['value'] <=> $a['value']);
    $total = 0;
    foreach ($rows as $r) { $total += $r['value']; }

    return [
        'rows'             => $rows,
        'total'            => $total,
        'trade_open_count' => $tradeCount,
        'cheque_count'     => $chequeCount,
        'debt_count'       => $debtCount,
        'store_share'      => $storeShare,
        'owed_mine'        => $owedMine,
    ];
}

/**
 * اقلامِ خالص دارایی → سطل‌های عکسِ روزانه.
 *
 * ⚠ نامِ کلیدها دقیقاً همان `kind`های `netWorthPortfolio()` است. اگر
 *   اینجا مفرد نوشته شود (wallet، cheque) همه به سطلِ «دارایی»
 *   می‌افتند — جمعِ کل درست می‌ماند ولی اجزا غلط، و **بی‌صدا**.
 */
function netWorthSnapParts(array $rows): array
{
    $parts = ['wallets' => 0, 'assets' => 0, 'trades_open' => 0,
              'cheques_net' => 0, 'debts_net' => 0,
              'store_share' => 0, 'store_total' => 0, 'store_owed' => 0];
    $map = ['wallets' => 'wallets', 'trade' => 'trades_open',
            'cheques' => 'cheques_net', 'debts' => 'debts_net',
            'store_share' => 'store_share', 'store_total' => 'store_total',
            'store_owed' => 'store_owed'];
    foreach ($rows as $row) {
        $parts[$map[$row['kind']] ?? 'assets'] += (int)$row['value'];
    }
    return $parts;
}

/**
 * عکسِ امروزِ خالص دارایی را می‌نویسد — بدونِ cron.
 *
 * ⚠ همان الگوی `processRecurringTransactions()`: اولین بازدیدِ روز
 *   ردیف را می‌سازد. `INSERT IGNORE` با کلیدِ (user_id, snap_date)
 *   یعنی بازدیدِ دومِ همان روز چیزی اضافه نمی‌کند.
 *
 * ⚠ **اجزا** ذخیره می‌شوند نه جمع، چون صفحه‌ی دارایی برای هر قلم یک
 *   کلیدِ روشن/خاموش دارد؛ با ذخیره‌ی جمع، روندِ «بدون طلا» ساختنی
 *   نبود.
 *
 * عمداً هیچ‌وقت استثنا پرتاب نمی‌کند: این یک کارِ جانبی است و نباید
 * بارگذاریِ صفحه را بشکند.
 */
function recordNetWorthSnapshot(int $userId, array $parts): void
{
    if (!tableExists('net_worth_snapshots')) { return; }

    // ⚠ دو سطلِ فروشگاه با `migration_store_share` می‌آیند؛ روی نصبِ
    //   migration‌نخورده کوئری بدونشان ساخته می‌شود، وگرنه کلِ عکسِ
    //   روزانه با «Unknown column» بی‌صدا از کار می‌افتاد.
    $store = tableHasColumn('net_worth_snapshots', 'store_share');
    // ⚠ سطلِ سومِ فروشگاه با `migration_store_owed` می‌آید و ممکن است
    //   هنوز نیامده باشد حتی وقتی دو تای اول آمده‌اند — پس جدا سنجیده
    //   می‌شود، نه با همان یک پرچم.
    $owed  = $store && tableHasColumn('net_worth_snapshots', 'store_owed');

    try {
        Database::getConnection()->prepare(
            'INSERT IGNORE INTO net_worth_snapshots
                (user_id, snap_date, wallets, assets, trades_open, cheques_net, debts_net'
            . ($store ? ', store_share, store_total' : '')
            . ($owed ? ', store_owed' : '') . ')
             VALUES (:u, :d, :w, :a, :t, :c, :b'
            . ($store ? ', :ss, :st' : '')
            . ($owed ? ', :so' : '') . ')'
        )->execute([
            'u' => $userId,
            'd' => today(),
            'w' => (int)($parts['wallets'] ?? 0),
            'a' => (int)($parts['assets'] ?? 0),
            't' => (int)($parts['trades_open'] ?? 0),
            'c' => (int)($parts['cheques_net'] ?? 0),
            'b' => (int)($parts['debts_net'] ?? 0),
        ] + ($store ? ['ss' => (int)($parts['store_share'] ?? 0),
                       'st' => (int)($parts['store_total'] ?? 0)] : [])
          + ($owed ? ['so' => (int)($parts['store_owed'] ?? 0)] : []));
    } catch (PDOException $e) { /* کارِ جانبی — صفحه نباید بشکند */ }
}

/**
 * تاریخچه‌ی خالص دارایی برای اسپارک‌لاین.
 * خروجی: [['date' => 'Y-m-d', 'total' => int], ...] از قدیم به جدید.
 */
function netWorthHistory(int $userId, int $limit = 12): array
{
    if (!tableExists('net_worth_snapshots')) { return []; }

    try {
        // ⚠ `LIMIT :n` با پارامترِ صحیح، نه درجِ متغیر در رشته — قاعده ۴
        //   درجِ متغیر را ممنوع کرده. با EMULATE_PREPARES=false باید
        //   صریحاً PARAM_INT باشد، وگرنه MySQL آن را رشته می‌بیند و
        //   کوئری با خطای نحوی می‌شکند.
        $store = tableHasColumn('net_worth_snapshots', 'store_share');
        $owed  = $store && tableHasColumn('net_worth_snapshots', 'store_owed');
        $st = Database::getConnection()->prepare(
            'SELECT snap_date, wallets, assets, trades_open, cheques_net, debts_net'
            . ($store ? ', store_share, store_total' : '')
            . ($owed ? ', store_owed' : '') . '
             FROM net_worth_snapshots WHERE user_id = :u
             ORDER BY snap_date DESC LIMIT :n'
        );
        $st->bindValue('u', $userId, PDO::PARAM_INT);
        $st->bindValue('n', max(1, min(400, $limit)), PDO::PARAM_INT);
        $st->execute();
        $rows = array_reverse($st->fetchAll());
    } catch (PDOException $e) {
        return [];
    }

    return array_map(fn($r) => [
        'date'  => $r['snap_date'],
        'total' => (int)$r['wallets'] + (int)$r['assets'] + (int)$r['trades_open']
                 + (int)$r['cheques_net'] + (int)$r['debts_net']
                 + (int)($r['store_share'] ?? 0) + (int)($r['store_total'] ?? 0)
                 + (int)($r['store_owed'] ?? 0),
    ], $rows);
}

/**
 * فهرستِ عنوان‌های اخیرِ کاربر برای پیشنهاد در فرمِ ثبت.
 *
 * ⛔ چرا: بیشترِ ثبت‌های یک دفترِ شخصی تکراری‌اند — «نان»، «تاکسی»،
 *    «قبض برق». کاربر هر بار نام را دوباره تایپ می‌کند، دسته را
 *    دوباره انتخاب می‌کند، و اگر یک بار «نان» و یک بار «نون» بنویسد
 *    گزارشِ دسته‌بندی‌اش دو ردیف می‌شود.
 *
 * ⚠ هیچ جدول یا اندپوینتِ تازه‌ای لازم ندارد: از خودِ `transactions`
 *   خوانده می‌شود. `<datalist>` هم یعنی ورودیِ آزاد سر جایش می‌ماند —
 *   همان دلیلی که `peopleDatalist()` هم datalist است نه select.
 *
 * @return array هر قلم: title, category_id, wallet_id, amount, type
 */
/**
 * پنجره‌ی «اخیر» برای پیشنهادِ عنوان — تنها مرجع.
 *
 * ⛔ عددِ ثابتِ داخلِ کوئری ننویسید: این تابع در فوترِ هر صفحه اجرا
 *    می‌شود و اندازه‌ی این پنجره تنها چیزی است که هزینه‌اش را مهار
 *    می‌کند.
 */
const RECENT_TITLE_SCAN = 400;

function recentTransactionTitles(int $userId, int $limit = 30): array
{
    try {
        // آخرین ثبت به‌ازای هر عنوان. `MAX(id)` یعنی تازه‌ترین، و
        // JOIN روی همان id مقدارهای همان ردیف را می‌آورد — نه ترکیبی
        // از چند ردیفِ مختلف که با GROUP BY خام پیش می‌آمد.
        //
        // ⛔ پنجره‌ی `SCAN_ROWS` اختیاری نیست. نسخه‌ی قبلی روی **کلِ
        //    تاریخچه‌ی کاربر** گروه‌بندی می‌کرد، و این تابع در فوترِ
        //    **هر صفحه** صدا زده می‌شود (شیتِ ثبت تراکنش آنجاست). یعنی
        //    هزینه‌اش با هر تراکنشی که کاربر ثبت می‌کند بالا می‌رفت و
        //    هیچ ایندکسی هم نجاتش نمی‌داد: `GROUP BY title` ناچار است
        //    هر ردیف را بخواند. اندازه‌گیری روی ۶٬۰۰۰ تراکنش:
        //    **۵.۵ms → ۱.۱ms**، و مهم‌تر از آن، دیگر رشد نمی‌کند.
        //
        // ⚠ و معنایش هم دقیق‌تر شد نه بدتر: این فهرست «عنوان‌هایی که
        //   اخیراً به کار برده‌ای» است. کشیدنش تا سه سال پیش کاری جز
        //   شلوغ کردنِ پیشنهادها نمی‌کرد.
        $st = Database::getConnection()->prepare(
            'SELECT t.title, t.category_id, t.wallet_id, t.amount, t.type
             FROM transactions t
             JOIN (
                 SELECT MAX(r.id) AS mx
                 FROM (
                     SELECT id, title
                     FROM transactions
                     WHERE user_id = :u AND title <> ""
                     ORDER BY id DESC
                     LIMIT ' . RECENT_TITLE_SCAN . '
                 ) r
                 GROUP BY r.title
             ) g ON g.mx = t.id
             ORDER BY t.id DESC
             LIMIT :n'
        );
        $st->bindValue('u', $userId, PDO::PARAM_INT);
        $st->bindValue('n', max(1, min(100, $limit)), PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * نشانه‌های هر حساب برای پیدا کردنِ حسابِ یک پیامکِ بانک:
 * چهار رقمِ آخرِ کارت، چهار رقمِ آخرِ شماره‌ی حساب، و کلیدِ نامِ بانک.
 *
 * ⚠ فقط چهار رقمِ آخر بیرون می‌رود، نه شماره‌ی کامل. شماره‌ی کارت و
 *   حساب حساس‌اند و در فهرست حساب‌ها هم نشان داده نمی‌شوند؛ اینجا هم
 *   نباید داخلِ سورسِ صفحه بنشینند.
 * ⚠ تطبیق خودش در `window.smsMatchWallet()` است (تنها جای آن تصمیم)؛
 *   اینجا فقط داده ساخته می‌شود.
 * ⛔ `learned`: نشانه‌هایی که کاربر با انتخابِ حساب برای یک پیامک یاد داد
 *   (`SmsSync::learn()`) — «یک بار بپرس، بعد خودکار».
 *
 * @return list<array{id:int, card4:?string, acct4:?string, banks:list<string>, learned:list<string>}>
 */
function walletSmsKeys(int $userId): array
{
    $cols = ['id'];
    foreach (['card_number', 'account_number', 'bank_code', 'bank_name', 'sms_keys'] as $c) {
        if (tableHasColumn('wallets', $c)) { $cols[] = $c; }
    }
    try {
        $st = Database::getConnection()->prepare(
            'SELECT ' . implode(', ', $cols) . ' FROM wallets
             WHERE user_id = :u AND is_active = 1
             ORDER BY sort_order, name'
        );
        $st->execute(['u' => $userId]);
    } catch (PDOException $e) {
        return [];
    }

    $tail = static function ($enc): ?string {
        // ⚠ رمزگشایی **پیش از** برداشتنِ ارقام. بدون آن، `preg_replace`
        //   روی متنِ base64 اجرا می‌شد و چهار رقمِ تصادفی بیرون می‌داد —
        //   یعنی پیامک حساب را اشتباه انتخاب می‌کرد، بی‌هیچ خطایی.
        if ($enc === null || $enc === '') { return null; }
        $digits = preg_replace('/\D/', '', (string)Crypto::decrypt((string)$enc));
        return strlen((string)$digits) >= 4 ? substr($digits, -4) : null;
    };

    $presets = bankPresets();
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $banks = [];
        $code  = (string)($r['bank_code'] ?? '');
        $name  = '';
        if ($code !== '' && $code !== 'other' && isset($presets[$code])) {
            $name = $presets[$code][0];
        } elseif (!empty($r['bank_name'])) {
            $name = (string)$r['bank_name'];
        }
        if ($code === 'blu') {
            // بلو خودش را «بلو» می‌نامد، نه «بانک بلو».
            $banks = ['!بلوبانک', '!بلو'];
        } elseif ($name !== '') {
            // ⛔ نامِ کامل (بی‌پیشوند) با کلمه‌ی تنها هم پذیرفته است
            //    (`!`)، ولی فقط اگر بلندتر از سه حرف باشد: «دی» در «ماه
            //    دی» و «ملت» در متنِ تبلیغ هم می‌آید. کلمه‌ی اولِ نامِ
            //    چندکلمه‌ای («ملی» از «ملی ایران»، «رفاه» از «رفاه
            //    کارگران») همیشه پیشوندِ «بانک» را می‌خواهد — پیامک
            //    معمولاً نامِ کوتاه را می‌نویسد.
            $key = trim((string)preg_replace('/^(مؤسسه اعتباری|موسسه اعتباری|بانک قرض‌الحسنه|بانک)\s+/u', '', $name));
            if ($key === 'پست بانک' || $key === 'پست') {
                $banks[] = '!پست بانک';
            } elseif ($key !== '') {
                $banks[] = (mb_strlen($key) > 3 ? '!' : '') . $key;
                $first = explode(' ', $key)[0];
                if ($first !== $key && $first !== 'توسعه') { $banks[] = $first; }
            }
        }
        $out[] = [
            'id'    => (int)$r['id'],
            'card4' => $tail($r['card_number'] ?? null),
            'acct4' => $tail($r['account_number'] ?? null),
            'banks' => $banks,
            'learned' => smsKeysParse($r['sms_keys'] ?? null),
        ];
    }
    return $out;
}

/** نشانه‌ی یادگرفته‌ی پیامک: چهار رقمِ کارت (`c`)، حساب (`a`) یا اثرِ انگشتِ فرستنده (`s`). */
const SMS_KEY_RE = '/^(c\d{4}|a\d{4}|s[0-9a-f]{8})$/';

/** حساب‌هایی که پیامکِ بانک را یاد گرفته‌اند (برای «فراموش کن» در ویرایشِ حساب). */
function smsLearnedWalletIds(int $userId): array
{
    if (!tableHasColumn('wallets', 'sms_keys')) { return []; }
    $st = Database::getConnection()->prepare(
        'SELECT id FROM wallets WHERE user_id = :u AND sms_keys IS NOT NULL'
    );
    $st->execute(['u' => $userId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * ⛔ «پیامک‌های یادگرفته‌ی این حساب را فراموش کن» — راهِ اصلاحِ انتخابِ اشتباه.
 *    بعدش پیامکِ همان بانک دوباره حساب را می‌پرسد.
 */
function smsForgetWallet(int $userId, int $walletId): bool
{
    if (!tableHasColumn('wallets', 'sms_keys')) { return false; }
    $st = Database::getConnection()->prepare(
        'UPDATE wallets SET sms_keys = NULL WHERE id = :id AND user_id = :u AND sms_keys IS NOT NULL'
    );
    $st->execute(['id' => $walletId, 'u' => $userId]);
    return $st->rowCount() === 1;
}

/** ستونِ `wallets.sms_keys` → فهرستِ پاک (هر چیزِ نامعتبر کنار می‌رود). */
function smsKeysParse($raw): array
{
    $out = [];
    foreach (explode(',', (string)$raw) as $k) {
        $k = trim($k);
        if ($k !== '' && preg_match(SMS_KEY_RE, $k) && !in_array($k, $out, true)) { $out[] = $k; }
    }
    return $out;
}

/**
 * ⛔ دسته‌ی پیامکِ بانک از انتخابِ قبلیِ کاربر برای همین پذیرنده — همتای
 *    `window.smsLearnedCategory()` (سایت) برای کارگرِ پس‌زمینه‌ی اپ
 *    (`SmsSync::post()`). همان داده (`recentTransactionTitles()`، آخرین ردیفِ هر
 *    عنوان) و همان قاعده: عنوانِ دقیق + همان نوع؛ پذیرنده‌ی خالی = هیچ.
 */
function smsLearnedCategoryId(int $userId, string $type, string $note): ?int
{
    // ⚠ پذیرنده‌ی خالی هیچ می‌دهد بی‌شرطِ جدا: `recentTransactionTitles()` عنوانِ خالی نمی‌آورد.
    $note = trim($note);
    foreach (recentTransactionTitles($userId) as $r) {
        if ((string)$r['title'] === $note && (string)$r['type'] === $type) {
            return (int)$r['category_id'] > 0 ? (int)$r['category_id'] : null;
        }
    }
    return null;
}

/** رندرِ datalist عنوان‌های اخیر — یک بار در فوتر، مثل peopleDatalist. */
function recentTitlesDatalist(int $userId): string
{
    $rows = recentTransactionTitles($userId);
    if (!$rows) { return ''; }

    $out = '<datalist id="recentTitles">';
    foreach ($rows as $r) {
        $out .= '<option value="' . h($r['title']) . '"'
             . ' data-category="' . (int)$r['category_id'] . '"'
             . ' data-wallet="' . (int)$r['wallet_id'] . '"'
             . ' data-amount="' . (int)$r['amount'] . '"'
             . ' data-type="' . h($r['type']) . '"></option>';
    }
    return $out . '</datalist>';
}

/**
 * ⛔ تنها جایی که «چکِ در جریان» تعریف می‌شود.
 *
 * مثل `categoryScopeSql()`: هر کوئری‌ای که چکِ باز می‌خواهد باید از
 * همین رد شود. اگر جایی جا بماند، همان یک صفحه عددِ دیگری نشان می‌دهد
 * و کسی ربطش را پیدا نمی‌کند.
 *
 * ⚠ چرا `is_settled = 0` به‌تنهایی غلط است: چکِ **برگشت‌خورده** و
 *   **خرج‌شده** هم `is_settled = 0` دارند (پاس نشده‌اند)، ولی دیگر در
 *   جریان نیستند. بدترین حالتش چکِ برگشتی است: به‌ازای آن یک ردیف
 *   `debts` ساخته می‌شود، پس اگر همچنان «چکِ در جریان» شمرده شود همان
 *   پول **دو بار** در دارایی و در آینده‌ی مالی می‌آید — دقیقاً همان
 *   دوبار شمردنی که CLAUDE.md درباره‌ی چکِ پاس‌شده هشدار می‌دهد.
 *
 * @param string $alias پیشوندِ جدول (خالی یا مثلاً 'c')
 */
/**
 * ⛔ طلبی که از چکِ برگشتی ساخته شد (`debts.cheque_id`) با خودِ چک همگام می‌ماند.
 *
 *    `toggle_cheque_settled` آن را می‌سازد و پس می‌گیرد، ولی ویرایش و حذفِ چک
 *    به آن دست نمی‌زدند (بازبینیِ کلِ پروژه، مهر ۱۴۰۵): مبلغِ عوض‌شده‌ی چک
 *    روی طلب نمی‌رسید، و چکِ حذف‌شده طلبی می‌گذاشت که پیوندش به شناسه‌ای مرده
 *    بود. این ستون کلیدِ خارجی ندارد، پس پایگاه‌داده خودش کاری نمی‌کند.
 *
 *    - ویرایش (`$cheque` داده شد): نام و مبلغ روی طلب، مگر پرداختیِ ثبت‌شده
 *      بیشتر از مبلغِ تازه باشد (کارِ خودِ کاربر پاک نمی‌شود).
 *    - حذف (`null`): طلب **می‌ماند** (طرف هنوز بدهکار است و «لغو»ِ حذف هم فقط
 *      چک را برمی‌گرداند) و فقط پیوندش برداشته می‌شود.
 */
function chequeLinkedDebtSync(PDO $pdo, int $userId, int $chequeId, ?array $cheque): void
{
    if (!tableHasColumn('debts', 'cheque_id')) { return; }
    if ($cheque === null) {
        $pdo->prepare('UPDATE debts SET cheque_id = NULL WHERE cheque_id = :c AND user_id = :u')
            ->execute(['c' => $chequeId, 'u' => $userId]);
        return;
    }
    $a = (int)$cheque['amount'];
    $pdo->prepare('
        UPDATE debts SET counterparty_name = :n, amount = :a, is_settled = (paid_amount >= :a2)
        WHERE cheque_id = :c AND user_id = :u AND paid_amount <= :a3
    ')->execute(['n' => (string)$cheque['counterparty_name'], 'a' => $a, 'a2' => $a, 'a3' => $a,
                 'c' => $chequeId, 'u' => $userId]);
}

function chequeActiveSql(string $alias = ''): string
{
    $p = $alias !== '' ? $alias . '.' : '';
    // روی نصبی که migration_cheque_status هنوز نیامده، رفتارِ قبلی.
    if (!tableHasColumn('cheques', 'status')) {
        return "{$p}is_settled = 0";
    }
    return "{$p}is_settled = 0 AND {$p}status = 'pending'";
}

/**
 * ⛔ روش‌های «این چک را چطور گرفتیم؟» — تنها فهرست (`migration_cheque_value`).
 *    کلیدِ خالی = بی‌سود (ستون `NULL`). `hint` همان جمله‌ای است که فرم و کارت می‌گویند.
 */
const CHEQUE_VALUE_METHODS = [
    ''         => ['label' => 'بی‌سود — همان مبلغِ چک',        'hint' => 'ارزشِ امروز همان مبلغِ چک است (مثلاً بابتِ فروش).'],
    'simple'   => ['label' => 'تنزیلِ ساده — مبلغ منهای درصد', 'hint' => 'ارزشِ امروز = مبلغ − مبلغ × درصدِ ماهانه × ماه‌های مانده.'],
    'compound' => ['label' => 'تنزیلِ مرکب',                     'hint' => 'درصدِ ماهانه روی ماه‌های مانده، مرکب حساب می‌شود.'],
    'loan'     => ['label' => 'اقساطِ وام — اصل + سودِ ماهانه',  'hint' => 'پولی که دادید (اصل) به‌علاوه‌ی سودی که تا امروز گذشته؛ روزِ سررسید همان مبلغِ چک.'],
];

/** سقفِ درصدِ ماهانه — بیشتر از این تقریباً همیشه اشتباهِ تایپی است. */
const CHEQUE_VALUE_RATE_MAX = 50;

/**
 * ⛔ «ارزشِ امروزِ» یک چکِ در جریان — **تنها جای این حساب** (صفحه‌ی چک، خالص دارایی، فرم).
 *
 * خواسته‌ی مالکِ نصب (مهر ۱۴۰۵): «۱۰ روز مونده یه مبلغ، ۴ روز مونده یه مبلغ، روزِ پاس
 * شدن خودِ مبلغِ چک — و هر چک درصدِ خودش.» ماه = ۳۰ روز (همان «درصد در ماه»ِ بازار).
 *
 *  - بی‌سود، بی‌سررسید، یا سررسید رسیده/گذشته ⇒ همان مبلغ.
 *  - `simple`:   مبلغ × (۱ − درصد × ماهِ مانده)، هرگز زیرِ صفر.
 *  - `compound`: مبلغ ÷ (۱ + درصد) ^ ماهِ مانده.
 *  - `loan`:     اصلِ این قسط + سودش به نسبتِ روزهای گذشته از شروعِ دوره‌اش تا سررسید —
 *                پس جمعِ سریِ «۱۰۰ میلیون، ۶٪، ۱۰ قسط» روزِ اول دقیقاً ۱۰۰ میلیون است.
 *
 * ⚠ فقط نمایش و جمع است: پاس شدن همان `amount` را به حساب می‌نشاند.
 */
function chequePresentValue(array $c, ?string $today = null): int
{
    $face   = (int)$c['amount'];
    $method = (string)($c['value_method'] ?? '');
    $due    = (string)($c['due_date'] ?? '');
    $today  = $today ?? today();
    if ($method === '' || !isset(CHEQUE_VALUE_METHODS[$method]) || $due === '' || $due <= $today) { return $face; }
    $days  = (int)round((strtotime($due . ' 12:00') - strtotime($today . ' 12:00')) / 86400);
    $rate  = max(0.0, (float)($c['value_rate'] ?? 0)) / 100;
    switch ($method) {
        case 'simple':
            return max(0, (int)round($face * (1 - $rate * $days / 30)));
        case 'compound':
            return (int)round($face / ((1 + $rate) ** ($days / 30)));
        case 'loan':
            $base = min($face, (int)($c['value_base'] ?? $face));
            $from = (string)($c['value_from'] ?? '');
            if ($from === '' || $from >= $due) { return $face; }
            if ($today <= $from) { return $base; }
            $span = (strtotime($due . ' 12:00') - strtotime($from . ' 12:00')) / 86400;
            $done = (strtotime($today . ' 12:00') - strtotime($from . ' 12:00')) / 86400;
            return (int)round($base + ($face - $base) * min(1.0, $done / $span));
    }
    return $face;
}

/** سقفِ تعدادِ چکِ یک سری. */
const CHEQUE_SERIES_MAX = 60;

/**
 * روش و درصدِ فرم → [method|null, rate|null, error|null]. ⛔ تنها سنجه‌ی هر دو اندپوینت.
 * درصد با ارقامِ فارسی و «٫» هم خوانده می‌شود؛ روشِ دار‌سود بی‌سررسید معنا ندارد.
 */
function chequeValueInput(string $method, string $rawRate, string $due): array
{
    $method = trim($method);
    if ($method === '' || $method === 'none') { return [null, null, null]; }
    if (!isset(CHEQUE_VALUE_METHODS[$method])) { return [null, null, 'روشِ دریافتِ چک نامعتبر است.']; }
    $rate = (float)str_replace(['٫', '/', ','], '.', toLatinDigits(trim($rawRate)));
    if ($rate <= 0 || $rate > CHEQUE_VALUE_RATE_MAX) {
        return [null, null, 'درصدِ ماهانه را بنویسید (بیشتر از صفر و تا ' . toPersianDigits((string)CHEQUE_VALUE_RATE_MAX) . ').'];
    }
    if ($due === '') { return [null, null, 'برای حسابِ ارزشِ امروز، تاریخِ سررسید لازم است.']; }
    return [$method, round($rate, 3), null];
}

/** سررسیدهای ماهانه‌ی یک سری از اولین سررسید، با لنگرِ روزِ همان (۳۱ می‌ماند ۳۱). */
function chequeSeriesDates(string $firstDue, int $count): array
{
    require_once __DIR__ . '/schedule.php';
    $anchor = jalaliDayOfDate($firstDue);
    $out = [];
    for ($i = 0; $i < $count; $i++) { $out[] = $i === 0 ? $firstDue : jalaliAddMonths($firstDue, $i, $anchor); }
    return $out;
}

/**
 * ⛔ اقساطِ وام — همان حسابِ مالکِ نصب: «۱۰۰ میلیون دادم، ۶٪ در ماه، ۱۰ ماه: سود ۶۰
 *    میلیون، کل ۱۶۰، تقسیم بر ۱۰ = هر چک ۱۶ میلیون». سود روی **کلِ اصل** و برای هر ماه
 *    (سری: N ماه؛ یک چک: ماه‌های تا سررسید، دست‌کم یک). باقیمانده‌ی تقسیم به قسطِ آخر.
 * @return list<array{due:string, amount:int, base:int, from:string}>
 */
function chequeLoanPlan(int $principal, float $ratePct, int $count, string $from, string $firstDue): array
{
    $dues   = chequeSeriesDates($firstDue, $count);
    $months = $count > 1 ? $count
            : max(1, (int)round((strtotime($firstDue . ' 12:00') - strtotime($from . ' 12:00')) / 86400 / 30));
    $interest = (int)round($principal * $ratePct / 100 * $months);
    $baseEach = intdiv($principal, $count);
    $intEach  = intdiv($interest, $count);
    $out = [];
    foreach ($dues as $i => $due) {
        $last = $i === $count - 1;
        $base = $last ? $principal - $baseEach * ($count - 1) : $baseEach;
        $int  = $last ? $interest - $intEach * ($count - 1) : $intEach;
        $out[] = ['due' => $due, 'amount' => $base + $int, 'base' => $base, 'from' => $i === 0 ? $from : $dues[$i - 1]];
    }
    return $out;
}

/** جملهٔ کوتاهِ روشِ یک چک برای کارت («تنزیلِ ساده ۵٪ ماهانه · ۱۲ روز مانده»)؛ بی‌سود = ''. */
function chequeValueCaption(array $c, ?string $today = null): string
{
    $method = (string)($c['value_method'] ?? '');
    if ($method === '' || !isset(CHEQUE_VALUE_METHODS[$method])) { return ''; }
    $rate = rtrim(rtrim(number_format((float)($c['value_rate'] ?? 0), 3, '.', ''), '0'), '.');
    $name = ['simple' => 'تنزیلِ ساده', 'compound' => 'تنزیلِ مرکب', 'loan' => 'قسطِ وام'][$method];
    $out  = $name . ' ' . toPersianDigits($rate) . '٪ ماهانه';
    if ($method === 'loan' && !empty($c['value_base'])) { $out .= ' · اصل ' . formatMoney((int)$c['value_base']); }
    $due = (string)($c['due_date'] ?? '');
    $today = $today ?? today();
    if ($due !== '' && $due > $today) {
        $days = (int)round((strtotime($due . ' 12:00') - strtotime($today . ' 12:00')) / 86400);
        $out .= ' · ' . toPersianDigits((string)$days) . ' روز مانده';
    }
    return $out;
}

/**
 * برچسبِ فارسیِ وضعیتِ چک، و کلاسِ نشانش.
 * یک جا تعریف می‌شود تا «برگشت خورد» در دو صفحه دو اسم نگیرد.
 */
function chequeStatusMeta(string $status): array
{
    return [
        'pending'  => ['label' => 'در انتظار',  'class' => 'debt-badge-pending'],
        'cleared'  => ['label' => 'پاس شد',     'class' => 'status-active'],
        'bounced'  => ['label' => 'برگشت خورد', 'class' => 'debt-badge-overdue'],
        'endorsed' => ['label' => 'خرج شد',     'class' => 'status-badge-muted'],
    ][$status] ?? ['label' => 'در انتظار', 'class' => 'debt-badge-pending'];
}

/**
 * ⚠ تنها مرجعِ «چند روز قبل یادآوری کن».
 *   مثل Auth::SESSION_WINDOWS: فهرستِ دوم نسازید — وگرنه گزینه‌ای که
 *   کاربر در پروفایل می‌بیند هنگام ذخیره بی‌سروصدا به پیش‌فرض برمی‌گردد.
 */
const REMINDER_DAYS = [1, 3, 7, 14];

/**
 * ⚠ بیشترین کارتِ حسابی که روی صفحه‌ی خانه می‌آید — تنها مرجع.
 *   نوارِ افقی با ۲۰ کارت دیگر «نگاهِ سریع» نیست، یک فهرستِ دوم است و
 *   صفحه‌ی حساب‌ها از قبل همان کار را بهتر می‌کند.
 */
const PINNED_WALLET_MAX = 8;

/** خواندنِ تنظیمِ یادآوریِ یک کاربر، با پیش‌فرضِ روشن. */
function reminderPrefs(int $userId): array
{
    // پیش‌فرض عمداً روشن است: این تنها قابلیتی است که ارزشش در نبودنش
    // دیده نمی‌شود — کاربری که نمی‌داند وجود دارد هرگز روشنش نمی‌کند و
    // بعد چکش برگشت می‌خورد.
    $out = ['email_on' => true, 'days_before' => 3];
    if (!tableExists('notification_prefs')) { return $out; }

    try {
        $st = Database::getConnection()->prepare(
            'SELECT email_on, days_before FROM notification_prefs WHERE user_id = :u'
        );
        $st->execute(['u' => $userId]);
        if ($row = $st->fetch()) {
            $out['email_on']    = (bool)$row['email_on'];
            $out['days_before'] = (int)$row['days_before'];
        }
    } catch (PDOException $e) { /* پیش‌فرض می‌ماند */ }

    return $out;
}

/**
 * دسته‌بندی‌های پیشنهادیِ یک خانوارِ ایرانی.
 *
 * ⚠ همین فهرست در سه جا لازم است: seedِ `schema.sql` برای نصبِ تازه،
 *   `migration_household_categories.sql` برای نصبِ موجود، و دکمه‌ی
 *   «افزودن دسته‌های پیشنهادی» در «فهرست‌های من» برای کاربری که
 *   فهرستش دستکاری شده و آن migration عمداً به آن دست نزده. اینجا
 *   مرجعِ سمتِ PHP است؛ اگر عوضش کردید، آن دو فایل SQL هم باید
 *   هم‌گام شوند (تستِ قرارداد این هم‌گامی را می‌سنجد).
 */
function suggestedHouseholdCategories(): array
{
    return [
        ['name' => 'حقوق',            'type' => 'income',  'icon' => 'salary',    'color' => '#059669'],
        ['name' => 'پاداش و عیدی',    'type' => 'income',  'icon' => 'gift',      'color' => '#a855f7'],
        ['name' => 'درآمد آزاد',      'type' => 'income',  'icon' => 'money',     'color' => '#10b981'],
        ['name' => 'سود سپرده',       'type' => 'income',  'icon' => 'profit',    'color' => '#16a34a'],
        ['name' => 'اجاره‌ی دریافتی', 'type' => 'income',  'icon' => 'home',      'color' => '#0d9488'],
        ['name' => 'خوراک',           'type' => 'expense', 'icon' => 'food',      'color' => '#f97316'],
        ['name' => 'مسکن و اجاره',    'type' => 'expense', 'icon' => 'home',      'color' => '#ef4444'],
        ['name' => 'قبوض',            'type' => 'expense', 'icon' => 'bill',      'color' => '#eab308'],
        ['name' => 'حمل‌ونقل',        'type' => 'expense', 'icon' => 'transport', 'color' => '#0ea5e9'],
        ['name' => 'موبایل و اینترنت','type' => 'expense', 'icon' => 'phone',     'color' => '#06b6d4'],
        ['name' => 'درمان',           'type' => 'expense', 'icon' => 'health',    'color' => '#ec4899'],
        ['name' => 'پوشاک',           'type' => 'expense', 'icon' => 'clothes',   'color' => '#f43f5e'],
        ['name' => 'آموزش',           'type' => 'expense', 'icon' => 'education', 'color' => '#6366f1'],
        ['name' => 'قسط و وام',       'type' => 'expense', 'icon' => 'money',     'color' => '#64748b'],
        ['name' => 'تفریح و سفر',     'type' => 'expense', 'icon' => 'fun',       'color' => '#d946ef'],
        ['name' => 'هدیه و مهمانی',   'type' => 'expense', 'icon' => 'gift',      'color' => '#a855f7'],
    ];
}

/**
 * ⛔ «کیف پول» پیش‌فرض را برای کاربری که هیچ حسابی ندارد می‌سازد.
 *
 * CLAUDE.md می‌گوید «برنامه برای هر کاربر یک حساب پیش‌فرض می‌سازد»، ولی
 * تنها جایی که این کار انجام می‌شد `migration_repair.sql:62` بود — یعنی
 * **یک بار**، برای کاربرانی که در همان لحظه وجود داشتند. نه `setup.php`
 * و نه `admin/users.php` هنگام ساخت کاربر حسابی نمی‌ساختند، پس هر
 * کاربرِ تازه‌ای (یعنی هر خریدارِ آینده) بدون حساب شروع می‌کرد و اولین
 * پولش با `wallet_id = NULL` ثبت می‌شد — دقیقاً خلافِ قاعده‌ی «هیچ پولی
 * بی‌حساب نمی‌ماند». خرابی‌اش بی‌صدا بود: تراکنش ثبت می‌شد، فقط در هیچ
 * حسابی نمی‌نشست و بعداً کسی نمی‌فهمید پول کجا رفت.
 *
 * برمی‌گرداند: شناسه‌ی حساب پیش‌فرض، یا null اگر جدول هنوز ساخته نشده.
 */
function ensureDefaultWallet(int $userId): ?int
{
    if ($userId <= 0) { return null; }

    try {
        $pdo = Database::getConnection();
        $st  = $pdo->prepare('SELECT id FROM wallets WHERE user_id = :u LIMIT 1');
        $st->execute(['u' => $userId]);
        if ($id = $st->fetchColumn()) { return (int)$id; }

        // نامش با migration_money_links یکی است تا کاربرِ تازه و قدیمی
        // یک چیز ببینند. sort_order = 0 یعنی پیش‌فرضِ فرم ثبت.
        $ins = $pdo->prepare(
            "INSERT INTO wallets (user_id, name, kind, color, sort_order)
             VALUES (:u, 'کیف پول', 'cash', '#16794f', 0)"
        );
        $ins->execute(['u' => $userId]);
        return (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        return null;   // جدول حساب‌ها هنوز با migration نیامده
    }
}

function activeWallets(int $userId): array
{
    static $cache = [];
    if (isset($cache[$userId])) { return $cache[$userId]; }

    try {
        $sql = "SELECT id, name, kind, sort_order FROM wallets
                WHERE user_id = :u AND is_active = 1
                ORDER BY sort_order, name";
        $st = Database::getConnection()->prepare($sql);
        $st->execute(['u' => $userId]);
        $rows = $st->fetchAll();

        // ⚠ ترمیمِ تنبل: کاربرانی که پیش از افزودن ensureDefaultWallet()
        //   ساخته شده‌اند هیچ حسابی ندارند. با اولین بارگذاری صفحه درست
        //   می‌شوند، بی‌آنکه کسی migration دستی اجرا کند.
        if (!$rows && ensureDefaultWallet($userId)) {
            $st->execute(['u' => $userId]);
            $rows = $st->fetchAll();
        }

        $cache[$userId] = $rows;
    } catch (PDOException $e) {
        $cache[$userId] = [];   // جدول حساب‌ها هنوز ساخته نشده
    }

    return $cache[$userId];
}

/**
 * حساب پیش‌فرض کاربر — همان «کیف پول» که برنامه خودش برای هر کاربر
 * می‌سازد. هر پولی که کاربر حسابش را مشخص نکند اینجا می‌نشیند، تا هیچ
 * مبلغی بی‌حساب نماند و بعداً بشود دستی به حساب درست منتقلش کرد.
 *
 * اگر کیف پول نبود (کاربر حذفش کرده)، اولین حساب فعال جایش را می‌گیرد.
 */
function defaultWalletId(int $userId): ?int
{
    $wallets = activeWallets($userId);
    if (!$wallets) { return null; }

    // کیف پول نقدی مقدم است؛ اگر کاربر حذفش کرده، اولین حساب فعال
    foreach ($wallets as $w) {
        if ($w['kind'] === 'cash') { return (int)$w['id']; }
    }

    return (int)$wallets[0]['id'];
}

/**
 * «دقیقه‌ی اول» — آیا هنوز باید موجودیِ واقعی را از کاربر بپرسیم؟
 *
 * ⛔ چرا این مهم‌ترین قدمِ کاربرِ تازه است: حسابِ پیش‌فرض با
 *    `initial_balance = 0` ساخته می‌شود، پس با اولین هزینه «مجموع
 *    حساب‌ها» منفی می‌شود و `safeToSpend()` تا ابد منفی می‌ماند.
 *    یعنی تنها عددی که به کارِ کاربر می‌آید از روزِ اول دروغ می‌گوید،
 *    **بی‌هیچ خطایی** — و کاربر نتیجه می‌گیرد اپ خراب است.
 *
 * ⛔ تنها جایی است که این تصمیم گرفته می‌شود (مثل `categoryScopeSql()`).
 *    خانه و `api/adjust_wallet.php` هر دو از همین رد می‌شوند؛ با دو
 *    نسخه، کارت پس از تنظیم کردن سرِ جایش می‌ماند یا برعکس.
 *
 * ⛔ دو شرط لازم است و هیچ‌کدام کافی نیست:
 *    ۱. `users.balance_setup_at` هنوز `NULL` باشد — کاربری که واقعاً
 *       موجودی‌اش صفر است باید بتواند «نیازی نیست» بزند و خلاص شود.
 *    ۲. هیچ حسابی موجودی اولیه نداشته باشد — کسی که از راهِ دیگری
 *       (فرمِ ساختِ حساب) عدد وارد کرده، دیگر نباید پرسیده شود.
 *
 * ⚠ اگر ستون هنوز با migration نیامده باشد، `null` برمی‌گردد و کارت
 *   بی‌سروصدا خاموش می‌ماند — همان قاعده‌ی `LoginThrottle::available()`.
 *
 * @return array{wallet_id:int, wallet_name:string, more:bool}|null
 */
function openingBalanceHint(int $userId): ?array
{
    if ($userId <= 0 || !tableHasColumn('users', 'balance_setup_at')) { return null; }

    try {
        $pdo = Database::getConnection();

        $st = $pdo->prepare('SELECT balance_setup_at FROM users WHERE id = :u');
        $st->execute(['u' => $userId]);
        $row = $st->fetch();
        if (!$row || $row['balance_setup_at'] !== null) { return null; }

        $st = $pdo->prepare('SELECT id, name, initial_balance FROM wallets
                             WHERE user_id = :u AND is_active = 1
                             ORDER BY sort_order, name');
        $st->execute(['u' => $userId]);
        $wallets = $st->fetchAll();
        if (!$wallets) { return null; }

        foreach ($wallets as $w) {
            if ((int)$w['initial_balance'] !== 0) { return null; }
        }
    } catch (PDOException $e) {
        return null;   // ⚠ کارِ جانبی است و هرگز نباید صفحه‌ی خانه را بشکند
    }

    $target = defaultWalletId($userId);
    if ($target === null) { return null; }

    $name = '';
    foreach ($wallets as $w) {
        if ((int)$w['id'] === $target) { $name = (string)$w['name']; break; }
    }

    return [
        'wallet_id'   => $target,
        'wallet_name' => $name,
        'more'        => count($wallets) > 1,
    ];
}

/**
 * نشانه‌ی «دیگر نپرس» را می‌گذارد — چه کاربر عدد وارد کرده باشد چه
 * گفته باشد نیازی نیست. تنها جای نوشتنِ `balance_setup_at`.
 */
function markBalanceSetup(int $userId): void
{
    if ($userId <= 0 || !tableHasColumn('users', 'balance_setup_at')) { return; }
    try {
        Database::getConnection()
            ->prepare('UPDATE users SET balance_setup_at = NOW() WHERE id = :u AND balance_setup_at IS NULL')
            ->execute(['u' => $userId]);
    } catch (PDOException $e) {
        // بی‌صدا: نشانه‌گذاری نباید جلوی کارِ اصلی (تعدیل موجودی) را بگیرد.
    }
}

/**
 * «کاربر همین حالا خروجیِ کامل گرفت.» تنها جای نوشتنِ `last_backup_at`.
 */
function markBackupTaken(int $userId): void
{
    if ($userId <= 0 || !tableHasColumn('users', 'last_backup_at')) { return; }
    try {
        Database::getConnection()
            ->prepare('UPDATE users SET last_backup_at = NOW() WHERE id = :u')
            ->execute(['u' => $userId]);
    } catch (PDOException $e) {
        // بی‌صدا: نشانه‌گذاری هرگز نباید جلوی خودِ دانلودِ فایل را بگیرد.
    }
}

/**
 * آخرین باری که کاربر خروجیِ کامل گرفت — یا `null` اگر هرگز.
 */
function lastBackupAt(int $userId): ?string
{
    if ($userId <= 0 || !tableHasColumn('users', 'last_backup_at')) { return null; }
    try {
        $st = Database::getConnection()->prepare('SELECT last_backup_at FROM users WHERE id = :u');
        $st->execute(['u' => $userId]);
        $row = $st->fetch();
        return ($row && $row['last_backup_at'] !== null) ? (string)$row['last_backup_at'] : null;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * حسابی که کاربر فرستاده را می‌سنجد؛ اگر نفرستاده یا مال او نیست،
 * حساب پیش‌فرض برمی‌گردد. نقطه‌ی واحدِ «هیچ پولی بی‌حساب نماند».
 */
function resolveWalletId(int $userId, $walletId): ?int
{
    $walletId = (int)$walletId;
    if ($walletId > 0) {
        $st = Database::getConnection()->prepare('SELECT id FROM wallets WHERE id = :id AND user_id = :u');
        $st->execute(['id' => $walletId, 'u' => $userId]);
        if ($st->fetchColumn()) { return $walletId; }
    }

    return defaultWalletId($userId);
}

/**
 * ⛔ تنها مرجعِ موجودی.
 *
 * ⚠ **و عمداً کش نمی‌شود.** یک بار کشِ درخواستی رویش گذاشته شد تا
 *   فراخوانیِ دوباره‌ی صفحه‌ی خانه ارزان شود، و تست‌ها همان‌جا قرمز شدند:
 *   هر مسیری که پول می‌نویسد و بعد موجودی می‌خواند، عددِ **پیش از**
 *   تغییر را می‌گرفت — بی‌هیچ خطایی، فقط یک عددِ غلط. راهِ درست این است
 *   که فراخواننده نتیجه را **یک بار بگیرد و پاس بدهد** (پارامترِ
 *   اختیاری `$rows` در `totalBalance()` و `pinnedWallets()`)، نه اینکه
 *   حقیقتِ پول در حافظه بماند.
 */
function walletBalances(int $userId): array
{
    $pdo = Database::getConnection();

    // معامله‌ها (بخش خرید و فروش) هم پول جابه‌جا می‌کنند: خرید از حساب
    // کم می‌کند و فروش به حساب برمی‌گرداند. عمداً ردیف تراکنش نمی‌سازند
    // (معامله نه هزینه است نه درآمد) پس باید همین‌جا جمع شوند.
    // جدول‌ها شاید هنوز با migration ساخته نشده باشند.
    $tradeJoins  = '';
    $tradeSelect = '';
    if (tradesTablesExist($pdo)) {
        $tradeSelect = '
              - COALESCE(tbuy.total, 0)
              + COALESCE(tsale.total, 0)';
        $tradeJoins = '
        LEFT JOIN (
            SELECT buy_wallet_id AS wid, SUM(buy_total) AS total
            FROM trades WHERE user_id = :u5 AND buy_wallet_id IS NOT NULL
            GROUP BY buy_wallet_id
        ) tbuy ON tbuy.wid = w.id
        LEFT JOIN (
            SELECT wallet_id AS wid, SUM(sale_total) AS total
            FROM trade_sales WHERE user_id = :u6 AND wallet_id IS NOT NULL
            GROUP BY wallet_id
        ) tsale ON tsale.wid = w.id';
    }

    // ستون‌های کارت با migration_wallet_cards می‌آیند؛ روی نصبی که هنوز
    // اجرا نشده، کوئری نباید بشکند.
    $cardCols = '';
    if (tableHasColumn('wallets', 'card_number')) {
        $cardCols = ' w.bank_code, w.card_number, w.account_number, w.iban,';
    }
    if (tableHasColumn('wallets', 'kind_label')) {
        $cardCols .= ' w.kind_label,';
    }
    // با `migration_wallet_pin` می‌آید؛ نصبی که هنوز اجرا نکرده نباید بشکند.
    if (tableHasColumn('wallets', 'pinned')) {
        $cardCols .= ' w.pinned,';
    }

    // چک پاس‌شده و پرداخت طلب/بدهی هم پول جابه‌جا می‌کنند. مثل معامله،
    // عمداً ردیف تراکنش نمی‌سازند (وصول طلب درآمد نیست) پس فقط روی
    // موجودی حساب اثر می‌گذارند. ستون‌ها با migration_money_links می‌آیند.
    $linkSelect = '';
    $linkJoins  = '';
    $linkParams = [];

    if (tableHasColumn('cheques', 'settle_wallet_id')) {
        $linkSelect .= '
              + COALESCE(chq.total, 0)';
        $linkJoins .= '
        LEFT JOIN (
            SELECT settle_wallet_id AS wid,
                   SUM(CASE WHEN direction = "received" THEN amount ELSE -amount END) AS total
            FROM cheques
            WHERE user_id = :u7 AND is_settled = 1 AND settle_wallet_id IS NOT NULL
            GROUP BY settle_wallet_id
        ) chq ON chq.wid = w.id';
        $linkParams['u7'] = $userId;
    }

    if (tableHasColumn('debt_payments', 'wallet_id')) {
        $linkSelect .= '
              + COALESCE(dbt.total, 0)';
        $linkJoins .= '
        LEFT JOIN (
            SELECT dp.wallet_id AS wid,
                   SUM(CASE WHEN d.direction = "receivable" THEN dp.amount ELSE -dp.amount END) AS total
            FROM debt_payments dp
            JOIN debts d ON d.id = dp.debt_id
            WHERE dp.user_id = :u8 AND dp.wallet_id IS NOT NULL
            GROUP BY dp.wallet_id
        ) dbt ON dbt.wid = w.id';
        $linkParams['u8'] = $userId;
    }

    // ⛔ هفتمین منبعِ پول: تسویه‌ی نقدیِ سهامدارِ فروشگاه.
    //
    //    مثل چکِ پاس‌شده و پرداختِ طلب، عمداً هیچ ردیفِ `transactions`
    //    نمی‌سازد: سهمِ سود **از قبل** به‌عنوان درآمد ثبت شده و ثبتِ
    //    دوباره‌اش گزارشِ درآمدِ ماه را به اندازه‌ی کلِ پرداخت باد
    //    می‌کرد — بی‌هیچ خطایی، چون هر دو عدد جداگانه درست‌اند.
    //
    //    و خالص دارایی هم تکان نمی‌خورد: با همان تسویه، «مانده»ی دفترِ
    //    فروشگاه به همان اندازه کم می‌شود و قلمِ «دارایی من در
    //    فروشگاه» پایین می‌آید. پول از یک سطل به سطلِ دیگر می‌رود.
    if (tableExists('store_settlements')) {
        $linkSelect .= '
              + COALESCE(sst.total, 0)';
        $linkJoins .= '
        LEFT JOIN (
            SELECT wallet_id AS wid, SUM(amount) AS total
            FROM store_settlements
            WHERE user_id = :u9 AND wallet_id IS NOT NULL
            GROUP BY wallet_id
        ) sst ON sst.wid = w.id';
        $linkParams['u9'] = $userId;
    }

    $stmt = $pdo->prepare('
        SELECT
            w.id, w.name, w.kind, w.bank_name, w.card_last4,' . $cardCols . '
            w.color, w.is_active, w.initial_balance,
            w.initial_balance
              + COALESCE(tx.income, 0)
              - COALESCE(tx.expense, 0)
              + COALESCE(tin.total, 0)
              - COALESCE(tout.total, 0)' . $tradeSelect . $linkSelect . ' AS balance
        FROM wallets w
        LEFT JOIN (
            SELECT wallet_id,
                   SUM(CASE WHEN type = "income"  THEN amount ELSE 0 END) AS income,
                   SUM(CASE WHEN type = "expense" THEN amount ELSE 0 END) AS expense
            FROM transactions WHERE user_id = :u1 GROUP BY wallet_id
        ) tx  ON tx.wallet_id = w.id
        LEFT JOIN (
            SELECT to_wallet_id AS wid, SUM(amount) AS total
            FROM transfers WHERE user_id = :u2 GROUP BY to_wallet_id
        ) tin ON tin.wid = w.id
        LEFT JOIN (
            SELECT from_wallet_id AS wid, SUM(amount + fee) AS total
            FROM transfers WHERE user_id = :u3 GROUP BY from_wallet_id
        ) tout ON tout.wid = w.id' . $tradeJoins . $linkJoins . '
        WHERE w.user_id = :u4
        ORDER BY w.is_active DESC, w.sort_order, w.name
    ');
    $params = ['u1' => $userId, 'u2' => $userId, 'u3' => $userId, 'u4' => $userId];
    if ($tradeJoins !== '') { $params['u5'] = $userId; $params['u6'] = $userId; }
    $stmt->execute($params + $linkParams);

    $rows = $stmt->fetchAll();

    // ⛔ ستون‌های حساس رمزشده‌اند و اینجا باز می‌شوند. `walletBalances()`
    //    تنها راهِ خواندنِ حساب‌هاست، پس همین یک جا کافی است — ولی هر
    //    کوئریِ تازه‌ای که این ستون‌ها را بیاورد هم باید از
    //    `Crypto::decryptRow()` رد شود، وگرنه کاربر رشته‌ی `enc:v1:…` را
    //    داخل فرمِ خودش می‌بیند و با ذخیره کردنش شماره‌اش را نابود می‌کند.
    if ($cardCols !== '' && Crypto::available()) {
        foreach ($rows as $i => $r) {
            $rows[$i] = Crypto::decryptRow($r, Crypto::WALLET_FIELDS);
        }
    }

    return $rows;
}

/**
 * حساب‌هایی که کاربر برای صفحه‌ی خانه پین کرده.
 *
 * ⛔ تنها جای این تصمیم است (مثل `chequeActiveSql()`). خانه و
 *    `wallets.php` هر دو از همین رد می‌شوند؛ با دو نسخه، حسابی که در
 *    فهرست «پین‌شده» دیده می‌شود روی خانه نمی‌آمد و برعکس.
 *
 * ⛔ سه شرط، و هیچ‌کدام اختیاری نیست:
 *    ۱. `pinned = 1`
 *    ۲. **حسابِ غیرفعال هرگز نمی‌آید** — کاربری که حسابی را می‌بندد
 *       انتظار ندارد موجودی‌اش هنوز روی خانه باشد، و پین کردنِ قبلی‌اش
 *       نباید آن را زنده نگه دارد.
 *    ۳. سقفِ `PINNED_WALLET_MAX` — نوارِ افقی با ۲۰ کارت دیگر «نگاهِ
 *       سریع» نیست، یک فهرستِ دوم است. صفحه‌ی حساب‌ها از قبل برای همان
 *       کار هست.
 *
 * ⚠ روی `walletBalances()` سوار است، نه یک کوئریِ تازه: موجودی شش منبع
 *   دارد (تراکنش، انتقال، معامله، چکِ پاس‌شده، پرداختِ بدهی، موجودی
 *   اولیه) و کوئریِ دومی دیر یا زود عددِ متفاوتی می‌گفت.
 *
 * @return array<int, array<string, mixed>>
 */
function pinnedWallets(int $userId, ?array $rows = null): array
{
    if ($userId <= 0 || !tableHasColumn('wallets', 'pinned')) { return []; }

    $out = [];
    foreach ($rows ?? walletBalances($userId) as $w) {
        if (empty($w['pinned']) || !(int)$w['is_active']) { continue; }
        $out[] = $w;
        if (count($out) >= PINNED_WALLET_MAX) { break; }
    }
    return $out;
}

/**
 * ⚠ `$rows` برای وقتی است که فراخواننده همین حالا `walletBalances()` را
 *   گرفته باشد — سنگین‌ترین کوئریِ اپ است و صفحه‌ی خانه دو بار می‌خواستش.
 *   پاس دادنِ نتیجه از کش کردنش امن‌تر است: حقیقتِ پول در حافظه نمی‌ماند.
 */
function totalBalance(int $userId, ?array $rows = null): int
{
    $sum = 0;
    foreach ($rows ?? walletBalances($userId) as $w) {
        if ((int)$w['is_active'] === 1) {
            $sum += (int)$w['balance'];
        }
    }
    return $sum;
}

/**
 * آیا این کیف پول رکورد وابسته دارد؟ (برای جلوگیری از حذفِ داده‌دار)
 *
 * ⛔ از **همه‌ی** ستون‌های ارجاع (`walletRefColumns()`)، نه فقط تراکنش و
 *    انتقال. نسخه‌ی قبلی پرداختِ بدهی، چکِ پاس‌شده، معامله، تسویه‌ی سهامدار و
 *    تراکنشِ دوره‌ای را نمی‌دید؛ حذفِ حساب با `ON DELETE SET NULL` آن پول را
 *    **بی‌صدا** از موجودی‌ها برمی‌داشت (بازبینیِ کلِ پروژه، مهر ۱۴۰۵) — و
 *    تراکنشِ دوره‌ایِ بعدی بی‌حساب ثبت می‌شد.
 */
function walletUsageCount(int $walletId, int $userId): int
{
    $pdo = Database::getConnection();
    $count = 0;
    foreach (walletRefColumns() as $ref) {
        // ⚠ نام‌ها از `information_schema`/`schemaMap()` آمده‌اند، نه از ورودی.
        $sql = "SELECT COUNT(*) FROM `{$ref['table']}` WHERE `{$ref['col']}` = :w"
             . ($ref['user'] ? ' AND user_id = :u' : '');
        $st = $pdo->prepare($sql);
        $st->execute($ref['user'] ? ['w' => $walletId, 'u' => $userId] : ['w' => $walletId]);
        $count += (int)$st->fetchColumn();
    }
    return $count;
}

/**
 * هر ستونی که به یک حساب (`wallets.id`) اشاره می‌کند.
 *
 * ⛔ از خودِ دیتابیس کشف می‌شود، نه از فهرستِ دستی — همان قاعده‌ی
 *    `categoryRefTables()`. دو منبع با هم: کلیدهای خارجیِ واقعی، و هر
 *    ستونی که نامش به `wallet_id` ختم می‌شود (یادآورها ستون دارند ولی
 *    کلیدِ خارجی ندارند). ستونی که جا بماند یعنی ردیف‌هایی که بعد از
 *    انتقال هنوز به حسابِ قدیمی اشاره می‌کنند، و حذفِ آن حساب با
 *    `ON DELETE SET NULL` آن‌ها را **بی‌صدا** بی‌حساب می‌کرد.
 *
 * @return array<int, array{table:string, col:string, user:bool}>
 */
function walletRefColumns(): array
{
    $map = schemaMap();
    $found = [];
    foreach ($map as $table => $cols) {
        if ($table === 'wallets') { continue; }
        foreach (array_keys($cols) as $col) {
            if (preg_match('/(^|_)wallet_id$/', $col)) { $found[$table . '.' . $col] = [$table, $col]; }
        }
    }
    try {
        $st = Database::getConnection()->query(
            "SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'wallets'
               AND REFERENCED_COLUMN_NAME = 'id'"
        );
        foreach ($st->fetchAll() as $r) {
            if ($r['t'] !== 'wallets') { $found[$r['t'] . '.' . $r['c']] = [$r['t'], $r['c']]; }
        }
    } catch (PDOException $e) {
        Log::error('wallet.ref_discovery', $e);
    }
    ksort($found);
    $out = [];
    foreach ($found as [$t, $c]) {
        $out[] = ['table' => $t, 'col' => $c, 'user' => isset($map[$t]['user_id'])];
    }
    return $out;
}

/**
 * همه‌ی پولِ یک حساب را به حسابِ دیگرِ همان کاربر منتقل می‌کند و (به
 * خواستِ کاربر) حسابِ قدیمی را حذف می‌کند.
 *
 * دلیلِ وجودش: حذفِ حسابِ داده‌دار ممنوع است (`walletUsageCount()`)،
 * پس کاربری که حسابِ دستیِ قدیمی را با یک حسابِ تازه (مثلاً بلوبانک)
 * جایگزین می‌کند راهی برای خلاص شدن از آن نداشت.
 *
 * ⛔ تنها جای این تصمیم است (مثل `mergeCategories()`)، و سه قاعده دارد:
 *   ۱. **موجودیِ اولیه هم می‌رود.** بدونش «کیف پول» با ۵ میلیون
 *      موجودیِ اولیه ادغام می‌شد و آن ۵ میلیون **بی‌صدا** از کلِ دارایی
 *      غیب می‌شد.
 *   ۲. **انتقال‌های بینِ همین دو حساب حذف می‌شوند**، نه جابه‌جا — وگرنه
 *      انتقالِ «از خودش به خودش» می‌شدند. کارمزدشان از موجودیِ اولیه‌ی
 *      مقصد کم می‌شود، چون آن کارمزد واقعاً پرداخت شده. (انتقال در
 *      گزارشِ درآمد/هزینه نیست، پس حذفش هیچ گزارشی را عوض نمی‌کند.)
 *   ۳. **سدِ پیش از `commit`:** هیچ ردیفی نباید روی حسابِ قدیمی مانده
 *      باشد، موجودیِ مقصد باید دقیقاً جمعِ هر دو باشد، و موجودیِ هیچ
 *      حسابِ دیگری تکان نخورده باشد؛ وگرنه rollback. «پول در هیچ‌کجا
 *      گم نشود» را خودِ کد تضمین می‌کند، نه یک تست.
 *
 * @return array{ok:bool, message:string, moved:int, dropped:int}
 */
function mergeWallet(int $userId, int $fromId, int $intoId, bool $deleteSource = true): array
{
    $fail = fn(string $m) => ['ok' => false, 'message' => $m, 'moved' => 0, 'dropped' => 0];
    if ($userId <= 0 || $fromId <= 0 || $intoId <= 0 || $fromId === $intoId) {
        return $fail('دو حسابِ متفاوت انتخاب کنید.');
    }
    $pdo = Database::getConnection();

    $st = $pdo->prepare('SELECT id, name, is_active, initial_balance FROM wallets WHERE id = :id AND user_id = :u');
    $st->execute(['id' => $fromId, 'u' => $userId]);
    $from = $st->fetch();
    $st->execute(['id' => $intoId, 'u' => $userId]);
    $into = $st->fetch();
    if (!$from || !$into) { return $fail('حساب یافت نشد.'); }
    if ((int)$into['is_active'] !== 1) {
        return $fail('حسابِ مقصد غیرفعال است؛ اول فعالش کنید.');
    }

    $refs = walletRefColumns();
    $bal  = fn() => array_column(walletBalances($userId), 'balance', 'id');

    try {
        $pdo->beginTransaction();
        $before = $bal();
        $expect = (int)$before[$fromId] + (int)$before[$intoId];

        // چند ردیف پیش از کار به مبدأ اشاره می‌کردند — زیرِ تراکنش.
        $count = 0;
        foreach ($refs as $r) {
            $q = $pdo->prepare("SELECT COUNT(*) AS n, SUM(" . ($r['user'] ? 'user_id <> :u' : '1') . ") AS alien
                                FROM `{$r['table']}` WHERE `{$r['col']}` = :f");
            $q->execute(['f' => $fromId] + ($r['user'] ? ['u' => $userId] : []));
            $row = $q->fetch();
            if ((int)$row['n'] > 0 && (!$r['user'] || (int)$row['alien'] > 0)) {
                $pdo->rollBack();
                return $fail('ردیف‌هایی در «' . $r['table'] . '» به این حساب اشاره می‌کنند که به شما منتسب نیستند. هیچ تغییری ذخیره نشد.');
            }
            $count += (int)$row['n'];
        }

        // انتقال‌های بینِ همین دو حساب: حذف، و کارمزدشان روی مقصد.
        $fee = 0;
        $dropped = 0;
        if (tableExists('transfers')) {
            $pairWhere = 'user_id = :u AND ((from_wallet_id = :a AND to_wallet_id = :b)
                                         OR (from_wallet_id = :c AND to_wallet_id = :d))';
            $pairArgs = ['u' => $userId, 'a' => $fromId, 'b' => $intoId, 'c' => $intoId, 'd' => $fromId];
            $q = $pdo->prepare("SELECT COALESCE(SUM(fee), 0) FROM transfers WHERE {$pairWhere}");
            $q->execute($pairArgs);
            $fee = (int)$q->fetchColumn();
            $d = $pdo->prepare("DELETE FROM transfers WHERE {$pairWhere}");
            $d->execute($pairArgs);
            $dropped = $d->rowCount();
        }

        $moved = 0;
        foreach ($refs as $r) {
            if (!$r['user']) { continue; }   // بالاتر سنجیده شد: ردیفی ندارد
            $up = $pdo->prepare("UPDATE `{$r['table']}` SET `{$r['col']}` = :i WHERE `{$r['col']}` = :f AND user_id = :u");
            $up->execute(['i' => $intoId, 'f' => $fromId, 'u' => $userId]);
            $moved += $up->rowCount();
        }

        $pdo->prepare('UPDATE wallets SET initial_balance = initial_balance + :add WHERE id = :id AND user_id = :u')
            ->execute(['add' => (int)$from['initial_balance'] - $fee, 'id' => $intoId, 'u' => $userId]);
        $pdo->prepare('UPDATE wallets SET initial_balance = 0 WHERE id = :id AND user_id = :u')
            ->execute(['id' => $fromId, 'u' => $userId]);

        // ⛔ سدِ پیش از commit.
        $left = 0;
        foreach ($refs as $r) {
            $q = $pdo->prepare("SELECT COUNT(*) FROM `{$r['table']}` WHERE `{$r['col']}` = :f");
            $q->execute(['f' => $fromId]);
            $left += (int)$q->fetchColumn();
        }
        $after = $bal();
        $others = true;
        foreach ($before as $id => $b) {
            if ($id !== $fromId && $id !== $intoId && (int)$after[$id] !== (int)$b) { $others = false; }
        }
        if ($left !== 0 || $moved + $dropped !== $count || (int)$after[$intoId] !== $expect
            || (int)$after[$fromId] !== 0 || !$others) {
            $pdo->rollBack();
            Log::warn('wallet.merge_barrier', ['left' => $left, 'moved' => $moved, 'dropped' => $dropped, 'count' => $count]);
            return $fail('شمارشِ ردیف‌ها یا موجودی‌ها نخواند؛ هیچ تغییری ذخیره نشد.');
        }

        if ($deleteSource) {
            $pdo->prepare('DELETE FROM wallets WHERE id = :id AND user_id = :u')
                ->execute(['id' => $fromId, 'u' => $userId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        Log::error('wallet.merge_failed', $e);
        return $fail('خطایی در انتقال رخ داد؛ هیچ تغییری ذخیره نشد.');
    }

    $msg = 'همه‌ی تراکنش‌ها و موجودیِ «' . $from['name'] . '» به «' . $into['name'] . '» منتقل شد'
        . ($deleteSource ? ' و حسابِ قدیمی حذف شد.' : '.');
    if ($dropped > 0) {
        $msg .= ' ' . toPersianDigits($dropped) . ' انتقالِ بینِ همین دو حساب حذف شد.';
    }
    return ['ok' => true, 'message' => $msg, 'moved' => $moved, 'dropped' => $dropped];
}

/* ============================================================
   بودجه‌بندی
   ============================================================ */

/**
 * بازه‌ی زمانی جاری یک بودجه را برمی‌گرداند (شروع، پایان، برچسب).
 * برای weekly/monthly/yearly همیشه بازه‌ی «جاری» را حساب می‌کند —
 * یعنی بودجه یک قانون تکرارشونده است، نه یک عدد یک‌بارمصرف.
 */
function budgetPeriodRange(string $periodType, ?string $customStart, ?string $customEnd): array
{
    $today = today();
    switch ($periodType) {
        case 'weekly':
            return [startOfWeek(), $today];
        case 'yearly':
            return [startOfJalaliYear(), $today];
        case 'custom':
            $from = $customStart && isValidDate($customStart) ? $customStart : $today;
            $to   = $customEnd && isValidDate($customEnd) ? min($customEnd, $today) : $today;
            return [$from, $to];
        case 'monthly':
        default:
            return [startOfJalaliMonth(), $today];
    }
}

/**
 * وضعیت همه‌ی بودجه‌های فعال کاربر را با مصرف واقعی محاسبه می‌کند.
 * چیزی ذخیره نمی‌شود — همیشه از روی تراکنش‌های واقعی است.
 */
function budgetStatuses(int $userId): array
{
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare('
        SELECT b.*, c.name AS category_name, c.icon AS cat_icon, c.color AS cat_color
        FROM budgets b
        JOIN categories c ON c.id = b.category_id
        WHERE b.user_id = :u AND b.is_active = 1
        ORDER BY c.name
    ');
    $stmt->execute(['u' => $userId]);
    $budgets = $stmt->fetchAll();
    if (!$budgets) { return []; }

    /**
     * ⛔ یک کوئری برای هر **بازه**، نه برای هر بودجه.
     *
     * نسخه‌ی قبلی داخلِ حلقه یک `SUM` جدا می‌زد، یعنی کاربری با ده بودجه
     * ده کوئریِ اضافه می‌داد — و این تابع هم در صفحه‌ی بودجه صدا زده
     * می‌شود هم در «پول قابل خرج» روی صفحه‌ی خانه. یعنی هزینه‌اش با
     * تعدادِ بودجه‌های کاربر خطی بالا می‌رفت، بی‌آنکه جایی دیده شود.
     *
     * گروه‌بندی روی بازه است نه روی «همه با هم»، چون بودجه‌ی هفتگی و
     * ماهانه و دلخواه بازه‌های متفاوتی دارند. در عمل تقریباً همه‌ی
     * بودجه‌ها ماهانه‌اند، پس یک کوئری می‌ماند.
     */
    $ranges = [];
    foreach ($budgets as $i => $b) {
        [$from, $to] = budgetPeriodRange($b['period_type'], $b['start_date'], $b['end_date']);
        $budgets[$i]['range_from'] = $from;
        $budgets[$i]['range_to']   = $to;
        $ranges["{$from}|{$to}"][] = (int)$b['category_id'];
    }

    $spentMap = [];
    foreach ($ranges as $key => $cats) {
        [$from, $to] = explode('|', $key);
        $cats = array_values(array_unique($cats));
        $holes = implode(',', array_fill(0, count($cats), '?'));

        $spentStmt = $pdo->prepare("
            SELECT category_id, COALESCE(SUM(amount), 0) AS spent
              FROM transactions
             WHERE user_id = ? AND type = 'expense'
               AND transaction_date BETWEEN ? AND ?
               AND category_id IN ({$holes})
             GROUP BY category_id
        ");
        $spentStmt->execute(array_merge([$userId, $from, $to], $cats));
        foreach ($spentStmt->fetchAll() as $r) {
            // ⚠ دسته‌ای که هیچ تراکنشی ندارد در نتیجه نمی‌آید، پس پایین‌تر
            //   با `?? 0` خوانده می‌شود — نه اینکه از فهرست بیفتد.
            $spentMap[$key][(int)$r['category_id']] = (int)$r['spent'];
        }
    }

    $result = [];
    foreach ($budgets as $b) {
        $from  = $b['range_from'];
        $to    = $b['range_to'];
        $spent = $spentMap["{$from}|{$to}"][(int)$b['category_id']] ?? 0;

        $amount = (int)$b['amount'];
        // ⛔ وضعیت از خودِ مبلغ‌ها، نه از درصدِ گردشده — بازرسیِ محاسباتی (مهر ۱۴۰۵):
        //    ۴٬۹۸۰٬۰۰۰ از ۵٬۰۰۰٬۰۰۰ با `round()` «۱۰۰٪ — بیشتر از بودجه» می‌شد در حالی که
        //    ۲۰٬۰۰۰ مانده بود. درصد هم `floor` (مثلِ `debtPaidRing()`): ۱۰۰ فقط وقتی تمام شده.
        $pct = $amount > 0 ? (int)floor(($spent / $amount) * 100) : 0;
        $status = $amount > 0 && $spent > $amount ? 'over' : ($pct >= 80 ? 'warn' : 'good');

        $b['spent'] = $spent;
        $b['remaining'] = $amount - $spent;
        $b['percent'] = min($pct, 999);
        $b['status'] = $status;
        $b['range_from'] = $from;
        $b['range_to'] = $to;
        $result[] = $b;
    }
    return $result;
}

function budgetPeriodLabel(string $periodType): string
{
    return [
        'weekly'  => 'هفتگی',
        'monthly' => 'ماهانه',
        'yearly'  => 'سالانه',
        'custom'  => 'بازه دلخواه',
    ][$periodType] ?? 'ماهانه';
}

/* ============================================================
   اهداف پس‌انداز
   ============================================================ */

function savingsGoalsWithProgress(int $userId, bool $includeArchived = false): array
{
    $pdo = Database::getConnection();

    // ⚠ ستونِ `wallet_id` ممکن است هنوز با migration نیامده باشد — همان
    //   قاعده‌ی `walletBalances()`: نبودنش نباید صفحه را بشکند.
    //
    // ⛔ و این `JOIN` فقط **نام** می‌آورد: هیچ مبلغی از حساب کم یا زیاد
    //    نمی‌شود. پس‌انداز پاکتی روی پولِ موجود است، نه یک خرج؛ اگر روزی
    //    در `walletBalances()` بیاید، موجودیِ هر کاربری که تا امروز
    //    پس‌انداز ثبت کرده بی‌صدا کم می‌شود.
    $hasWallet = tableHasColumn('savings_goals', 'wallet_id');

    $sql = '
        SELECT g.*, COALESCE(SUM(e.amount), 0) AS current_amount'
        . ($hasWallet ? ', w.name AS wallet_name' : ', NULL AS wallet_name') . '
        FROM savings_goals g
        LEFT JOIN savings_entries e ON e.goal_id = g.id'
        . ($hasWallet ? ' LEFT JOIN wallets w ON w.id = g.wallet_id AND w.user_id = g.user_id' : '') . '
        WHERE g.user_id = :u' . ($includeArchived ? '' : ' AND g.is_archived = 0') . '
        GROUP BY g.id
        ORDER BY g.is_archived, g.created_at DESC
    ';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['u' => $userId]);
    $goals = $stmt->fetchAll();

    foreach ($goals as &$g) {
        $current = (int)$g['current_amount'];
        $target = (int)$g['target_amount'];
        $g['current_amount'] = $current;
        $g['remaining'] = max(0, $target - $current);
        $g['percent'] = $target > 0 ? min(100, round(($current / $target) * 100)) : 0;
        $g['is_complete'] = $current >= $target && $target > 0;
    }
    return $goals;
}

/* ============================================================
   طلب/بدهی — پرداخت جزئی
   ============================================================ */

function debtRemaining(array $debt): int
{
    return max(0, (int)$debt['amount'] - (int)$debt['paid_amount']);
}

/**
 * حلقه‌ی پیشرفتِ پرداختِ یک طلب/بدهی — تنها جای حسابِ درصد و رنگ.
 *
 * ⛔ رنگ از خودِ درصد می‌آید، نه از چند پله‌ی ثابت: فامِ ۰ (قرمز) در ۰٪
 *    تا ۱۲۰ (سبز) در ۱۰۰٪، خطی. با پله‌ی ثابت، ۵۱٪ و ۹۹٪ یک رنگ می‌شدند
 *    و حلقه دیگر چیزی نمی‌گفت که عدد وسطش نگفته باشد.
 * ⛔ درصد رو به پایین گرد می‌شود (`floor`)، نه `round`: ۹۹٫۶٪ نباید
 *    «۱۰۰٪» خوانده شود وقتی هنوز چیزی باقی مانده؛ و هر پرداختِ ناصفر
 *    دست‌کم «۱٪» است، وگرنه حلقه‌ی خالی شبیهِ «هیچ پرداختی» دیده می‌شد.
 *
 * @return array{pct:int, hue:int}
 */
function debtPaidRing(int $paid, int $amount): array
{
    if ($paid <= 0 || $amount <= 0) { return ['pct' => 0, 'hue' => 0]; }
    if ($paid >= $amount)          { return ['pct' => 100, 'hue' => 120]; }
    $pct = (int)floor($paid * 100 / $amount);
    $pct = max(1, min(99, $pct));
    return ['pct' => $pct, 'hue' => (int)round($pct * 1.2)];
}

/* ============================================================
   تراکنش دوره‌ای — بدون نیاز به Cron
   ============================================================ */

function jalaliMonthLength(int $jy, int $jm): int
{
    $startG = jalaliToGregorian($jy, $jm, 1);
    $nextJy = $jm === 12 ? $jy + 1 : $jy;
    $nextJm = $jm === 12 ? 1 : $jm + 1;
    $endG = jalaliToGregorian($nextJy, $nextJm, 1);

    $start = new DateTime(sprintf('%04d-%02d-%02d', $startG[0], $startG[1], $startG[2]));
    $end = new DateTime(sprintf('%04d-%02d-%02d', $endG[0], $endG[1], $endG[2]));

    return (int)$start->diff($end)->days;
}

/* ============================================================
   گزارشِ سالانه‌ی چهارفصل (داشبورد)
   ============================================================ */

/**
 * ⛔ تنها مرجعِ فصل‌ها. کلید همان کلاسِ رنگِ کارت است (`.season-spring`…)
 * و ماه‌ها شمسی‌اند — فصل در تقویمِ شمسی دقیقاً سه ماه است.
 */
const SEASONS = [
    'spring' => ['بهار',   [1, 2, 3]],
    'summer' => ['تابستان', [4, 5, 6]],
    'autumn' => ['پاییز',  [7, 8, 9]],
    'winter' => ['زمستان', [10, 11, 12]],
];

/** تاریخِ میلادیِ «Y-m-d» برای یک روزِ شمسی. */
function jalaliDateToGregorianStr(int $jy, int $jm, int $jd): string
{
    [$gy, $gm, $gd] = jalaliToGregorian($jy, $jm, $jd);
    return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
}

/**
 * ⛔ گزارشِ یک سالِ شمسی به تفکیکِ فصل و ماه — تابعِ خالص، بی‌کوئری.
 *
 * ورودی همان جمعِ روزانه‌ای است که داشبورد از قبل **یک بار** می‌خواند
 * (`transaction_date`, `daily_income`, `daily_expense`)، پس این کارت هیچ
 * کوئریِ تازه‌ای به صفحه اضافه نمی‌کند. و چون از ردیف‌های `transactions`
 * ساخته می‌شود نه از عددِ دیگری، جمعِ ۱۲ ماه **دقیقاً** جمعِ سال است —
 * تست همین را می‌سنجد.
 *
 * ⚠ هر ماه مرزِ میلادیِ خودش را دارد (`from`/`to`) تا لینکِ «تراکنش‌های
 *   این ماه» دقیقاً همان ردیف‌هایی را نشان بدهد که عددش را ساخته‌اند.
 *
 * @param array  $dailyRows ردیف‌های جمعِ روزانه
 * @param int    $jy        سالِ شمسی
 * @param string $today     امروز (میلادی) — برای «ماهِ جاری» و «آینده»
 */
function seasonalYearReport(array $dailyRows, int $jy, string $today): array
{
    $monthNames = ['', 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
                   'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];

    $months = [];
    for ($jm = 1; $jm <= 12; $jm++) {
        $from = jalaliDateToGregorianStr($jy, $jm, 1);
        $to   = jalaliDateToGregorianStr($jy, $jm, jalaliMonthLength($jy, $jm));
        $months[$jm] = [
            'jm' => $jm, 'name' => $monthNames[$jm], 'from' => $from, 'to' => $to,
            'income' => 0, 'expense' => 0, 'net' => 0,
            'current' => $today >= $from && $today <= $to,
            'future'  => $from > $today,
        ];
    }
    $yearFrom = $months[1]['from'];
    $yearTo   = $months[12]['to'];

    foreach ($dailyRows as $row) {
        $d = (string)$row['transaction_date'];
        if ($d < $yearFrom || $d > $yearTo) { continue; }
        // جست‌وجوی خطیِ ۱۲تایی؛ ارزان‌تر و مطمئن‌تر از تبدیلِ تقویم برای هر روز.
        foreach ($months as $jm => $m) {
            if ($d >= $m['from'] && $d <= $m['to']) {
                $months[$jm]['income']  += (int)$row['daily_income'];
                $months[$jm]['expense'] += (int)$row['daily_expense'];
                break;
            }
        }
    }

    $seasons = [];
    $yIn = 0; $yOut = 0;
    foreach (SEASONS as $key => [$name, $jms]) {
        $in = 0; $out = 0; $list = [];
        foreach ($jms as $jm) {
            $months[$jm]['net'] = $months[$jm]['income'] - $months[$jm]['expense'];
            $in  += $months[$jm]['income'];
            $out += $months[$jm]['expense'];
            $list[] = $months[$jm];
        }
        $first = $months[$jms[0]]; $last = $months[$jms[2]];
        $seasons[] = [
            'key' => $key, 'name' => $name,
            'from' => $first['from'], 'to' => $last['to'],
            'income' => $in, 'expense' => $out, 'net' => $in - $out,
            'current' => $today >= $first['from'] && $today <= $last['to'],
            'future'  => $first['from'] > $today,
            'months' => $list,
        ];
        $yIn += $in; $yOut += $out;
    }

    return [
        'year' => $jy, 'from' => $yearFrom, 'to' => $yearTo,
        'income' => $yIn, 'expense' => $yOut, 'net' => $yIn - $yOut,
        'seasons' => $seasons,
    ];
}

/**
 * آدرسِ فهرستِ تراکنش‌های یک بازه — مقصدِ هر کلیکِ کارتِ سالانه.
 *
 * ⛔ `$backYear` یک **راهِ برگشت** به همان سالِ کارت است (`back=y1404`).
 * **گزارشِ مالکِ نصب:** «وقتی وارد ماه خاص میشیم دکمه برگشت نداره».
 * صفحه‌ی تراکنش‌ها خودش هیچ دکمه‌ی بازگشتی ندارد (از منوی اصلی باز
 * می‌شود)، پس کسی که از سالِ ۱۴۰۲ یک ماه را باز کرده بود فقط با دکمه‌ی
 * بازگشتِ مرورگر برمی‌گشت — که در اپِ نصب‌شده اصلاً نیست.
 * ⚠ فقط سال حمل می‌شود، نه یک آدرسِ دلخواه: `back` هرگز مستقیم در
 *   `href` نمی‌نشیند (`txBackYear()` آن را می‌سنجد) — وگرنه یک پیوندِ
 *   ساختگی کاربر را به هر جایی می‌برد.
 */
function txRangeUrl(string $from, string $to, ?int $backYear = null): string
{
    $q = ['period' => 'custom', 'from_date' => $from, 'to_date' => $to];
    if ($backYear !== null) { $q['back'] = 'y' . $backYear; }
    return APP_BASE_PATH . '/transactions.php?' . http_build_query($q);
}

/** سالِ شمسیِ داخلِ `back=y1404` اگر معتبر باشد، وگرنه `null`. */
function txBackYear($raw): ?int
{
    if (!is_string($raw) || !preg_match('/^y(1[3-4]\d\d)\z/', $raw, $m)) { return null; }
    return (int)$m[1];
}

/**
 * تاریخ سررسید بعدی را بر اساس تقویم شمسی محاسبه می‌کند (نه میلادی)،
 * چون کاربر «هر ماه روز فلان» را شمسی می‌فهمد، نه میلادی.
 * برای ماه/سال، اگر روز هدف در ماه مقصد وجود نداشت (مثلاً ۳۱ در مهر)
 * به آخرین روز معتبر همان ماه محدود می‌شود.
 *
 * ⛔ `$anchorDay` (روزِ شمسیِ تاریخِ شروعِ سری) — بی‌آن، یک بار افتادن در
 *    ماهِ کوتاه روزِ ۳۱ را **برای همیشه** ۳۰ می‌کرد: ۳۱ شهریور → ۳۰ مهر →
 *    ۳۰ آبان → … (همان خرابی‌ای که `jalaliAddMonths()` برای سررسیدها رفع کرد،
 *    ولی تراکنش‌های دوره‌ای و اقساط هنوز از این تابع می‌رفتند). لنگر فقط وقتی
 *    به کار می‌رود که تاریخِ فعلی واقعاً روی آن است (یا کوتاه‌شده‌اش در ماهِ
 *    کوتاه)؛ سری‌ای که کاربر روزش را دستی عوض کرده به روزِ قدیم برنمی‌گردد.
 */
function advanceRecurringDate(string $gregorianDate, string $frequency, int $intervalCount, int $anchorDay = 0): string
{
    if ($frequency === 'daily') {
        return date('Y-m-d', strtotime($gregorianDate . " +{$intervalCount} days"));
    }
    if ($frequency === 'weekly') {
        $days = $intervalCount * 7;
        return date('Y-m-d', strtotime($gregorianDate . " +{$days} days"));
    }

    [$gy, $gm, $gd] = array_map('intval', explode('-', $gregorianDate));
    [$jy, $jm, $jd] = gregorianToJalali($gy, $gm, $gd);

    if ($frequency === 'monthly') {
        $total = ($jm - 1) + $intervalCount;
        $newJy = $jy + intdiv($total, 12);
        $newJm = $total % 12;
        if ($newJm < 0) { $newJm += 12; $newJy--; }
        $newJm += 1;
    } else { // yearly
        $newJy = $jy + $intervalCount;
        $newJm = $jm;
    }

    $day = $jd;
    if ($anchorDay > $jd && $anchorDay <= 31 && $jd === jalaliMonthLength($jy, $jm)) {
        $day = $anchorDay;   // ماهِ کوتاه روز را بریده بود؛ لنگر برمی‌گردد
    }
    $newJd = min($day, jalaliMonthLength($newJy, $newJm));
    $g = jalaliToGregorian($newJy, $newJm, $newJd);
    return sprintf('%04d-%02d-%02d', $g[0], $g[1], $g[2]);
}

/** روزِ شمسیِ یک تاریخِ میلادی (لنگرِ سری)؛ تاریخِ خراب ۰ (= بی‌لنگر). */
function jalaliDayOfDate(?string $gregorian): int
{
    if (!is_string($gregorian) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $gregorian)) { return 0; }
    [$gy, $gm, $gd] = array_map('intval', explode('-', $gregorian));
    return gregorianToJalali($gy, $gm, $gd)[2];
}

/**
 * برنامه‌ی اقساطِ یک بدهی — **مجازی**، هیچ ردیفی ذخیره نمی‌شود.
 *
 * ⛔ چرا ردیف ذخیره نمی‌شود (مثل تراکنش‌های دوره‌ای):
 *    • ۳۶ ردیف به‌ازای هر وام یعنی جدولی که سریع بزرگ می‌شود
 *    • پرداختِ واقعی از قبل در `debt_payments` هست؛ ردیفِ قسط نسخه‌ی
 *      دومی از همان حقیقت می‌شد و دیر یا زود از آن دور می‌افتاد
 *    • عوض کردنِ تعداد اقساط یعنی بازسازیِ همه‌ی ردیف‌های پرداخت‌نشده
 *
 * ⚠ «کدام قسط پرداخت شده» از روی **جمعِ پرداختی** حساب می‌شود، نه از
 *   روی تاریخ. کاربر ممکن است دو قسط را یک‌جا بدهد یا زودتر بپردازد؛
 *   پول پول است و ترتیبش اهمیتی ندارد.
 *
 * ⚠ باقیمانده‌ی تقسیم به قسطِ **آخر** می‌رود، نه پخش‌شده. اگر
 *   ۱۰٬۰۰۰٬۰۰۰ به سه قسط بشکند، ۳٬۳۳۳٬۳۳۳ + ۳٬۳۳۳٬۳۳۳ + ۳٬۳۳۳٬۳۳۴
 *   می‌شود؛ وگرنه جمعِ اقساط با مبلغِ وام یکی نمی‌شد و کاربر یک ریال
 *   بدهیِ ابدی پیدا می‌کرد.
 *
 * @param array $debt ردیفِ debts با installment_count/every/first_installment_date
 * @return array هر قلم: seq, date, amount, paid (bool)
 */
function debtInstallments(array $debt): array
{
    $count = (int)($debt['installment_count'] ?? 0);
    if ($count < 2) { return []; }

    $total = (int)$debt['amount'];
    $paid  = (int)($debt['paid_amount'] ?? 0);
    $every = ($debt['installment_every'] ?? 'monthly') === 'weekly' ? 'weekly' : 'monthly';

    $start = $debt['first_installment_date'] ?? null;
    $anchor = null;
    if (!$start || !isValidDate($start)) {
        // اگر تاریخِ اولین قسط نیامده، از سررسید عقب می‌رویم تا آخرین
        // قسط روی همان سررسید بیفتد — که انتظارِ طبیعیِ کاربر است.
        $start = $debt['due_date'] ?? today();
        $anchor = jalaliDayOfDate($start);
        for ($i = 1; $i < $count; $i++) {
            $start = advanceRecurringDate($start, $every, -1, $anchor);
        }
    }

    $base = intdiv($total, $count);

    // ⚠ اگر مبلغ کمتر از تعدادِ اقساط باشد، تقسیم بی‌معناست و قسط‌های
    //   **صفر** می‌سازد — که هم در کارت «قسط ۱ از ۲ — ۰ تومان» نشان
    //   می‌داد هم به‌عنوان یک تعهدِ صفر وارد آینده‌ی مالی می‌شد. در آن
    //   حالت بدهی مثل یک بدهیِ ساده رفتار می‌کند. (تستِ خودکار این را
    //   با ۱ تومان در ۲ قسط پیدا کرد.)
    if ($base < 1) { return []; }

    $out  = [];
    $date = $start;
    $covered = 0;

    for ($i = 1; $i <= $count; $i++) {
        $amount = $i === $count ? ($total - $base * ($count - 1)) : $base;
        $out[] = [
            'seq'    => $i,
            'date'   => $date,
            'amount' => $amount,
            // قسط وقتی پرداخت‌شده است که جمعِ پرداختی تا اینجا را پوشش دهد
            'paid'   => ($covered + $amount) <= $paid,
            // ⛔ آنچه از **همین** قسط مانده — برای آینده‌ی مالی، «قابلِ خرج» و
            //    «قسطِ بعدی». با `amount`، قسطی که نیمه‌اش پرداخت شده بود کامل
            //    شمرده می‌شد: ۳ میلیون در ۳ قسط با ۱٫۵ پرداخت، ۲ میلیون «مانده»
            //    می‌گفت نه ۱٫۵ (بازبینیِ کلِ پروژه، مهر ۱۴۰۵).
            'remaining' => max(0, min($amount, $covered + $amount - $paid)),
        ];
        $covered += $amount;
        // ⛔ لنگرِ رو به جلو همان روزِ سررسید است، نه روزِ شروعِ عقب‌رفته — بازرسیِ
        //    محاسباتی (مهر ۱۴۰۵): سررسیدِ ۳۱ اردیبهشت از اسفندِ ۲۹روزه رد می‌شد، شروع ۲۹
        //    می‌شد و همه‌ی اقساط (حتی آخری) روی ۲۹ می‌ماندند؛ قسطِ آخر روی سررسید نمی‌افتاد.
        if ($i < $count) { $date = advanceRecurringDate($date, $every, 1, $anchor ?? jalaliDayOfDate($start)); }
    }

    return $out;
}

/**
 * قسطِ بعدیِ پرداخت‌نشده — برای پیش‌فرضِ فرمِ «ثبت پرداخت» و خطِ کارت.
 * اگر بدهی قسطی نباشد یا همه پرداخت شده باشند، null.
 */
function nextDebtInstallment(array $debt): ?array
{
    foreach (debtInstallments($debt) as $inst) {
        if (!$inst['paid']) { return $inst; }
    }
    return null;
}

/**
 * تراکنش‌های دوره‌ای سررسیدشده را پردازش می‌کند.
 * - mode=auto: تراکنش را خودش می‌سازد و سررسید را جلو می‌برد (حتی چند دوره‌ی عقب‌افتاده را جبران می‌کند)
 * - mode=remind/confirm: چیزی نمی‌سازد؛ فقط برای نمایش در داشبورد برگردانده می‌شود
 *
 * چون بدون Cron کار می‌کند، این تابع در بازدیدهای صفحه اصلی/داشبورد صدا زده می‌شود.
 */
function processRecurringTransactions(int $userId, bool $force = false): array
{
    // در هر نشست فقط یک‌بار در روز بررسی می‌شود.
    // بدون این محافظ، هر بار باز کردن خانه یا داشبورد یک کوئری اضافه می‌زد.
    $stamp = 'recur_checked_' . $userId;
    $todayKey = today();
    if (!$force && isset($_SESSION[$stamp]) && $_SESSION[$stamp] === $todayKey) {
        return $_SESSION['recur_pending_' . $userId] ?? [];
    }

    $pdo = Database::getConnection();
    $today = today();

    $stmt = $pdo->prepare('
        SELECT * FROM recurring_transactions
        WHERE user_id = :u AND is_active = 1 AND next_due_date <= :today
    ');
    $stmt->execute(['u' => $userId, 'today' => $today]);
    $due = $stmt->fetchAll();

    $needsAttention = [];

    foreach ($due as $r) {
        if ($r['mode'] !== 'auto') {
            $needsAttention[] = $r;
            continue;
        }

        $nextDue = $r['next_due_date'];
        $guard = 0;
        while ($nextDue <= $today && $guard < 60) {
            if ($r['end_date'] !== null && $nextDue > $r['end_date']) {
                break;
            }

            try {
                $pdo->beginTransaction();

                $dueNow  = $nextDue;
                $nextDue = advanceRecurringDate($nextDue, $r['frequency'], (int)$r['interval_count'],
                                                jalaliDayOfDate($r['start_date'] ?? null));

                // ⛔ اول سررسید **شرطی** جلو می‌رود، بعد تراکنش: دو دستگاه که هم‌زمان
                //    خانه را باز کنند هر دو همین ردیف را می‌خواندند و هر کدام یک
                //    تراکنشِ تکراری می‌ساختند (نگهبانِ نشست فقط همان نشست را
                //    می‌پاید). فقط یکی از این خط رد می‌شود.
                $upd = $pdo->prepare('UPDATE recurring_transactions SET next_due_date = :n
                                      WHERE id = :id AND next_due_date = :old');
                $upd->execute(['n' => $nextDue, 'id' => $r['id'], 'old' => $dueNow]);
                if ($upd->rowCount() !== 1) { $pdo->rollBack(); break; }

                // ⛔ حساب از `resolveWalletId()` — حسابِ حذف‌شده یعنی پیش‌فرض، نه
                //    تراکنشی که در گزارش هست و در هیچ موجودی‌ای نیست.
                $ins = $pdo->prepare('
                    INSERT INTO transactions (user_id, category_id, wallet_id, recurring_id, type, amount, title, note, transaction_date)
                    VALUES (:u, :cat, :wallet, :rid, :type, :amount, :title, :note, :date)
                ');
                $ins->execute([
                    'u' => $userId, 'cat' => $r['category_id'], 'wallet' => resolveWalletId($userId, $r['wallet_id']),
                    'rid' => $r['id'], 'type' => $r['type'], 'amount' => $r['amount'],
                    'title' => $r['title'], 'note' => $r['note'], 'date' => $dueNow,
                ]);

                $pdo->commit();
            } catch (PDOException $e) {
                $pdo->rollBack();
                Log::error('recurring.generate_failed', $e, ['recurring_id' => (int)$r['id']]);
                break;
            }

            $guard++;
        }

        // اگر تاریخ پایان رسیده، دیگر تکرار نکن
        if ($r['end_date'] !== null && $nextDue > $r['end_date']) {
            $deact = $pdo->prepare('UPDATE recurring_transactions SET is_active = 0 WHERE id = :id');
            $deact->execute(['id' => $r['id']]);
        }
    }

    // نتیجه تا پایان روز در نشست نگه داشته می‌شود
    $_SESSION[$stamp] = $todayKey;
    $_SESSION['recur_pending_' . $userId] = $needsAttention;

    return $needsAttention;
}

/**
 * کش بررسی تراکنش‌های دوره‌ای را باطل می‌کند.
 * بعد از هر تغییر (تأیید، رد، ساخت، حذف) صدا زده می‌شود تا
 * فهرست «در انتظار» بلافاصله به‌روز شود.
 */
function invalidateRecurringCache(int $userId): void
{
    unset($_SESSION['recur_checked_' . $userId], $_SESSION['recur_pending_' . $userId]);
}

function recurringFrequencyLabel(string $freq, int $interval): string
{
    $base = ['daily' => 'روز', 'weekly' => 'هفته', 'monthly' => 'ماه', 'yearly' => 'سال'][$freq] ?? 'ماه';
    return $interval > 1 ? 'هر ' . toPersianDigits($interval) . ' ' . $base . ' یک‌بار' : 'هر ' . $base;
}

function recurringModeLabel(string $mode): string
{
    return ['auto' => 'ثبت خودکار', 'remind' => 'فقط یادآوری', 'confirm' => 'نیازمند تأیید'][$mode] ?? 'یادآوری';
}

/* ============================================================
   آینده مالی — یک منبع داده واحد برای «تقویم» و «رویدادهای پیش‌رو»
   ============================================================ */

/**
 * همه‌ی رویدادهای مالی یک بازه را از منابع مختلف جمع می‌کند:
 * طلب، بدهی، چک دریافتی/صادره، و تراکنش‌های دوره‌ای.
 *
 * چون هر دو صفحه‌ی «تقویم» و «پیش‌رو» از همین تابع تغذیه می‌شوند،
 * هیچ‌وقت دو صفحه عدد متفاوت نشان نمی‌دهند.
 *
 * خروجی: آرایه‌ای از رویدادها، هرکدام با کلیدهای:
 *   date, kind, direction, title, amount, url, is_overdue
 */
function financialEvents(int $userId, string $fromDate, string $toDate): array
{
    $pdo = Database::getConnection();
    $today = today();
    $events = [];

    // ---------- طلب و بدهی ----------
    //
    // ⚠ بدهیِ قسطی **همه‌ی** اقساطِ داخل بازه را می‌سازد، نه یک رویداد
    //   در سررسیدِ آخر. بدون این، «آینده مالی» و «پول قابل خرج» قسطِ
    //   ماهِ بعد را اصلاً نمی‌دیدند و عددِ قابل خرج به‌شدت خوش‌بین
    //   می‌شد — یعنی همان جایی که کاربر بیشترین اعتماد را به عدد دارد.
    //
    // بدهیِ قسطی ممکن است سررسیدِ آخرش بیرونِ بازه باشد ولی قسطش داخل،
    // پس شرطِ `due_date BETWEEN` برای آن‌ها برداشته می‌شود و خودِ حلقه
    // بازه را می‌سنجد.
    try {
        $stmt = $pdo->prepare('
            SELECT id, direction, counterparty_name, amount, paid_amount, due_date
            FROM debts
            WHERE user_id = :u AND is_settled = 0 AND due_date BETWEEN :f AND :t
        ');
        $stmt->execute(['u' => $userId, 'f' => $fromDate, 't' => $toDate]);
        $plain = $stmt->fetchAll();

        $installmentRows = [];
        if (tableHasColumn('debts', 'installment_count')) {
            $ist = $pdo->prepare('
                SELECT id, direction, counterparty_name, amount, paid_amount, due_date,
                       installment_count, installment_every, first_installment_date
                FROM debts
                WHERE user_id = :u AND is_settled = 0
                  AND installment_count IS NOT NULL AND installment_count > 1
            ');
            $ist->execute(['u' => $userId]);
            $installmentRows = $ist->fetchAll();
        }
        $installmentIds = array_column($installmentRows, 'id');

        foreach ($plain as $d) {
            // بدهیِ قسطی از حلقه‌ی پایین می‌آید؛ اینجا نباید دوباره
            // به‌صورت یک مبلغِ درشت هم بیاید.
            if (in_array($d['id'], $installmentIds)) { continue; }
            $remaining = (int)$d['amount'] - (int)($d['paid_amount'] ?? 0);
            if ($remaining <= 0) { continue; }
            $events[] = [
                'date' => $d['due_date'],
                'kind' => 'debt',
                'direction' => $d['direction'] === 'receivable' ? 'in' : 'out',
                'title' => ($d['direction'] === 'receivable' ? 'طلب از ' : 'بدهی به ') . $d['counterparty_name'],
                'amount' => $remaining,
                'url' => 'debts.php',
                'is_overdue' => $d['due_date'] < $today,
            ];
        }

        foreach ($installmentRows as $d) {
            foreach (debtInstallments($d) as $inst) {
                if ($inst['paid']) { continue; }
                if ($inst['date'] < $fromDate || $inst['date'] > $toDate) { continue; }
                $events[] = [
                    'date' => $inst['date'],
                    'kind' => 'debt',
                    'direction' => $d['direction'] === 'receivable' ? 'in' : 'out',
                    'title' => ($d['direction'] === 'receivable' ? 'طلب از ' : 'بدهی به ')
                             . $d['counterparty_name']
                             . ' — قسط ' . toPersianDigits((string)$inst['seq'])
                             . ' از ' . toPersianDigits((string)$d['installment_count']),
                    'amount' => $inst['remaining'],
                    'url' => 'debts.php',
                    'is_overdue' => $inst['date'] < $today,
                ];
            }
        }
    } catch (PDOException $e) { /* جدول موجود نیست */ }

    // ---------- چک‌ها ----------
    try {
        $stmt = $pdo->prepare('
            SELECT id, direction, counterparty_name, amount, due_date
            FROM cheques
            WHERE user_id = :u AND ' . chequeActiveSql() . ' AND due_date IS NOT NULL AND due_date BETWEEN :f AND :t
        ');
        $stmt->execute(['u' => $userId, 'f' => $fromDate, 't' => $toDate]);
        foreach ($stmt->fetchAll() as $c) {
            $events[] = [
                'date' => $c['due_date'],
                'kind' => 'cheque',
                'direction' => $c['direction'] === 'received' ? 'in' : 'out',
                'title' => 'چک ' . ($c['direction'] === 'received' ? 'دریافتی از ' : 'صادره به ') . $c['counterparty_name'],
                'amount' => (int)$c['amount'],
                'url' => 'cheques.php',
                'is_overdue' => $c['due_date'] < $today,
            ];
        }
    } catch (PDOException $e) { /* جدول موجود نیست */ }

    // ---------- تراکنش‌های دوره‌ای ----------
    // سررسیدهای آینده را تا انتهای بازه شبیه‌سازی می‌کنیم (بدون ثبت چیزی)
    try {
        $stmt = $pdo->prepare('
            SELECT id, type, title, amount, frequency, interval_count, next_due_date, end_date, start_date
            FROM recurring_transactions
            WHERE user_id = :u AND is_active = 1 AND next_due_date <= :t
        ');
        $stmt->execute(['u' => $userId, 't' => $toDate]);
        foreach ($stmt->fetchAll() as $r) {
            $due = $r['next_due_date'];
            // ⛔ روزانه/هفتگی پیش از بازه یک‌جا تا شروعِ بازه جلو می‌رود، و سقفِ حلقه
            //    فقط ترمز است (۴۰۰۰ دور)، نه «۴۰ سررسید». بازرسیِ محاسباتی (مهر ۱۴۰۵):
            //    با سقفِ ۴۰ دور از `next_due_date`، تکرارِ روزانه در تقویمِ ماهِ بعد ۲۶
            //    روز (به‌جای ۳۰) و ماهِ پس از آن **صفر** روز داشت، و تعهدهای «پول قابل
            //    خرج» کم شمرده می‌شد. ماهانه/سالانه با لنگرِ روز (`jalaliDayOfDate`)
            //    نمی‌شود پرید و لازم هم نیست: ۴۰۰۰ ماه ۳۰۰ سال است.
            $step = $r['frequency'] === 'daily' ? max(1, (int)$r['interval_count'])
                  : ($r['frequency'] === 'weekly' ? 7 * max(1, (int)$r['interval_count']) : 0);
            if ($step > 0 && $due < $fromDate) {
                $gap  = intdiv((int)((strtotime($fromDate . ' 12:00') - strtotime($due . ' 12:00')) / 86400), $step) * $step;
                $due  = date('Y-m-d', strtotime($due . " +{$gap} days"));
            }
            $guard = 0;
            while ($due <= $toDate && $guard < 4000) {
                if ($r['end_date'] !== null && $due > $r['end_date']) { break; }
                if ($due >= $fromDate) {
                    $events[] = [
                        'date' => $due,
                        'kind' => 'recurring',
                        'direction' => $r['type'] === 'income' ? 'in' : 'out',
                        'title' => $r['title'],
                        'amount' => (int)$r['amount'],
                        'url' => 'recurring.php',
                        'is_overdue' => $due < $today,
                    ];
                }
                $due = advanceRecurringDate($due, $r['frequency'], (int)$r['interval_count'],
                                            jalaliDayOfDate($r['start_date'] ?? null));
                $guard++;
            }
        }
    } catch (PDOException $e) { /* جدول موجود نیست */ }

    usort($events, fn($a, $b) => $a['date'] <=> $b['date']);
    return $events;
}

/**
 * «پول قابل خرج» = موجودی فعلی − تعهدات قطعی نزدیک
 * این عدد نباید با موجودی بانکی اشتباه گرفته شود.
 */
function safeToSpend(int $userId, int $daysAhead = 30, ?array $walletRows = null): array
{
    $balance = 0;
    try {
        $balance = totalBalance($userId, $walletRows);
    } catch (PDOException $e) {
        return ['available' => null, 'balance' => 0, 'commitments' => 0];
    }

    $to = date('Y-m-d', strtotime("+{$daysAhead} days"));

    // ⛔ پنجره باید از **گذشته** شروع شود، نه از امروز.
    //
    //    نسخه‌ی قبلی از today() شروع می‌کرد، پس چکِ سررسیدگذشته و
    //    بدهیِ عقب‌افتاده در «تعهدها» شمرده نمی‌شدند. یعنی «پول قابل
    //    خرج» دقیقاً وقتی خوش‌بین بود که کاربر در دردسر است — و آن
    //    تعهدها قطعی‌ترین چیزی هستند که آدم دارد؛ پرداختشان دیر شده،
    //    نه اینکه منتفی شده باشد. روی داده‌ی دمو، ۴٬۰۰۰٬۰۰۰ بدهیِ
    //    سررسیدگذشته در همان صفحه به‌صورت کارتِ سرخ دیده می‌شد ولی از
    //    عددِ بالای صفحه کم نمی‌شد.
    //
    //    ۹۰ روز همان بازه‌ای است که زبانه‌ی سررسیدها برای فهرستش می‌گیرد،
    //    پس عددِ بالای صفحه و فهرستِ زیرش از یک چیز حرف می‌زنند.
    $from   = date('Y-m-d', strtotime('-90 days'));
    $events = financialEvents($userId, $from, $to);
    $today  = today();

    $commitments = 0;
    $overdue     = 0;
    foreach ($events as $e) {
        if ($e['direction'] !== 'out') { continue; }
        $commitments += $e['amount'];
        if ($e['date'] < $today) { $overdue += $e['amount']; }
    }

    return [
        'available' => $balance - $commitments,
        'balance' => $balance,
        'commitments' => $commitments,
        // سهمِ سررسیدگذشته جدا برمی‌گردد تا صفحه بتواند بگوید «از این
        // مبلغ، این‌قدر سررسیدش گذشته» — بدون آن، کاربر نمی‌فهمد چرا
        // عدد ناگهان کوچک شد.
        'overdue' => $overdue,
        // ⚠ کلیدِ افزوده: خودِ رویدادها (۹۰ روز عقب تا افق)، تا فهرستِ
        //   سررسیدِ خانه‌ی دسکتاپ از **همین** رویدادها ساخته شود، نه با
        //   `financialEvents()`ِ دوم — عددِ کارت و فهرستِ زیرش از یک چیز.
        'events' => $events,
    ];
}

function eventKindLabel(string $kind): string
{
    return ['debt' => 'طلب/بدهی', 'cheque' => 'چک', 'recurring' => 'دوره‌ای'][$kind] ?? '';
}

/**
 * بدنه‌ی ایمیلِ یادآوریِ سررسید — [html, text].
 *
 * ⚠ عمداً اینجاست نه داخل `deploy/reminders.php`: آن فایل با cron اجرا
 *   می‌شود و خروجی‌اش را کسی نمی‌بیند، پس اگر متن خراب شود بی‌صدا خراب
 *   می‌ماند. اینجا بدون فرستادنِ هیچ ایمیلی آزمودنی است.
 *
 * ⛔ نسخه‌ی متنی اختیاری نیست: بعضی کلاینت‌ها HTML را نشان نمی‌دهند و
 *   فیلترهای اسپم به ایمیلِ فقط-HTML سخت‌گیرترند.
 */
/**
 * یادآورهای شخصیِ کاربر، به همان **شکلِ رویدادِ** `financialEvents()`.
 *
 * ⛔ چرا هم‌شکل: ایمیلِ روزانه و «آینده مالی» هر دو روی همان آرایه کار
 *    می‌کنند. با شکلِ دومی، هر جای نمایش باید دو حالت را می‌شناخت و
 *    یادآور دیر یا زود از یکی جا می‌ماند.
 *
 * ⚠ `direction` اجباری است چون `reminderEmailBody()` آن را می‌خواند؛
 *   یادآور همیشه «پرداخت» فرض می‌شود (بیمه، عوارض، قسط) و مبلغِ
 *   نداشته صفر می‌رود.
 */
function customReminderEvents(int $userId, string $fromDate, string $toDate): array
{
    if (!tableExists('reminders')) { return []; }
    try {
        // ⛔ فقط یادآورِ دلخواهِ فعال — همان قاعده‌ی `Notify::generateReminders()`:
        //    ردیفِ پیوندی از `financialEvents()` می‌آید و اینجا تکراری و کهنه بود.
        $stmt = Database::getConnection()->prepare(tableHasColumn('reminders', 'source_type') ? "
            SELECT title, remind_date, amount FROM reminders
            WHERE user_id = :u AND is_done = 0 AND remind_date BETWEEN :f AND :t
              AND (source_type = 'custom' OR source_type IS NULL) AND status = 'active'
            ORDER BY remind_date ASC
        " : '
            SELECT title, remind_date, amount FROM reminders
            WHERE user_id = :u AND is_done = 0 AND remind_date BETWEEN :f AND :t
            ORDER BY remind_date ASC
        ');
        $stmt->execute(['u' => $userId, 'f' => $fromDate, 't' => $toDate]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'date'       => $r['remind_date'],
                'kind'       => 'reminder',
                'direction'  => 'out',
                'title'      => $r['title'],
                'amount'     => (int)($r['amount'] ?? 0),
                'url'        => 'due.php?t=reminders',
                'is_overdue' => $r['remind_date'] < today(),
            ];
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

function reminderEmailBody(string $name, array $overdue, array $soon, int $days): array
{
    $money = fn(int $a): string => formatMoney($a) . ' تومان';
    $line  = function (array $e): array {
        $when = toJalali($e['date']);
        $dir  = $e['direction'] === 'in' ? 'دریافت' : 'پرداخت';
        return [$when, $e['title'], $dir, $e['amount']];
    };

    $htmlRows = function (array $events, string $accent) use ($line, $money): string {
        $out = '';
        foreach ($events as $e) {
            [$when, $title, $dir, $amount] = $line($e);
            $out .= '<tr>'
                . '<td style="padding:8px 10px;border-bottom:1px solid #eceef3;white-space:nowrap;color:#6b7280;font-size:13px;">' . h($when) . '</td>'
                . '<td style="padding:8px 10px;border-bottom:1px solid #eceef3;font-size:14px;">' . h($title) . '</td>'
                . '<td style="padding:8px 10px;border-bottom:1px solid #eceef3;white-space:nowrap;font-size:14px;font-weight:700;color:' . $accent . ';">'
                . h($money($amount)) . ' <span style="font-weight:400;color:#9aa1ad;">(' . h($dir) . ')</span></td>'
                . '</tr>';
        }
        return $out;
    };

    $html = '<div dir="rtl" style="font-family:Tahoma,Arial,sans-serif;background:#f6f7fb;padding:20px;">'
        . '<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:14px;padding:22px;">'
        . '<h2 style="margin:0 0 4px;font-size:17px;color:#2a3563;">سلام ' . h($name) . '</h2>'
        . '<p style="margin:0 0 18px;font-size:13.5px;color:#6b7280;line-height:1.9;">'
        . 'این خلاصه‌ی سررسیدهای مالیِ شماست.</p>';

    $text = "سلام {$name}\nخلاصه‌ی سررسیدهای مالی شما:\n";

    if ($overdue) {
        $html .= '<h3 style="margin:16px 0 6px;font-size:14px;color:#d03a40;">سررسید گذشته</h3>'
            . '<table style="width:100%;border-collapse:collapse;">' . $htmlRows($overdue, '#d03a40') . '</table>';
        $text .= "\n— سررسید گذشته —\n";
        foreach ($overdue as $e) {
            [$when, $title, $dir, $amount] = $line($e);
            $text .= "  {$when}  {$title}  {$money($amount)} ({$dir})\n";
        }
    }

    if ($soon) {
        $html .= '<h3 style="margin:18px 0 6px;font-size:14px;color:#2a3563;">تا '
            . h(toPersianDigits((string)$days)) . ' روز آینده</h3>'
            . '<table style="width:100%;border-collapse:collapse;">' . $htmlRows($soon, '#2a3563') . '</table>';
        $text .= "\n— تا {$days} روز آینده —\n";
        foreach ($soon as $e) {
            [$when, $title, $dir, $amount] = $line($e);
            $text .= "  {$when}  {$title}  {$money($amount)} ({$dir})\n";
        }
    }

    // لینک از APP_URL ساخته می‌شود نه از HTTP_HOST — همان قاعده‌ای که
    // برای لینکِ بازیابیِ رمز هست، وگرنه با Host جعلی می‌شد کاربر را
    // به سایتِ مهاجم برد.
    $url = appBaseUrl() . '/due.php?t=list';
    $html .= '<p style="margin:22px 0 0;"><a href="' . h($url) . '" '
        . 'style="display:inline-block;background:#2a3563;color:#fff;text-decoration:none;'
        . 'padding:10px 18px;border-radius:10px;font-size:14px;">دیدن آینده مالی</a></p>'
        . '<p style="margin:16px 0 0;font-size:11.5px;color:#9aa1ad;line-height:1.9;">'
        . 'اگر این یادآوری را نمی‌خواهید، از «حساب کاربری من» در برنامه خاموشش کنید.</p>'
        . '</div></div>';

    $text .= "\nدیدن آینده مالی: {$url}\n"
        . "اگر این یادآوری را نمی‌خواهید، از «حساب کاربری من» خاموشش کنید.\n";

    return [$html, $text];
}

/**
 * فاصله‌ی روز تا یک تاریخ را به زبان آدمیزاد برمی‌گرداند.
 */
function humanDaysUntil(string $date): string
{
    $today = new DateTime(today());
    $target = new DateTime($date);
    $diff = (int)$today->diff($target)->format('%r%a');

    if ($diff < 0)  { return toPersianDigits(abs($diff)) . ' روز گذشته'; }
    if ($diff === 0) { return 'امروز'; }
    if ($diff === 1) { return 'فردا'; }
    return toPersianDigits($diff) . ' روز دیگر';
}

/* ============================================================
   گزارش‌های مقایسه‌ای
   ============================================================ */

/**
 * مقایسه‌ی ماه جاری با ماه قبل (شمسی) — درآمد، هزینه، و درصد تغییر.
 */
/**
 * پنجره‌ی مقایسه‌ی ماه — **هم‌روز**، نه ناتمام در برابر کامل.
 *
 * ⛔ نسخه‌ی قبلیِ monthComparison() ماهِ جاری را از اولِ ماه تا *امروز*
 *    می‌خواند ولی ماهِ قبل را **کامل**. یعنی روزِ ۱۱ ام، ۱۱ روز با ۳۱
 *    روز مقایسه می‌شد و اپ می‌گفت «هزینه ۵۹٪ کم شده» در حالی که آهنگِ
 *    خرج دقیقاً همان بود. تقریباً ۲۹ روز از هر ۳۱ روز این عدد دروغ
 *    می‌گفت — و بدترین حالتش این است که کاربر به آن اعتماد کند و
 *    خیالش راحت شود.
 *
 * ⚠ ماهِ قبل ممکن است کوتاه‌تر از روزِ امروز باشد (اسفندِ غیرکبیسه ۲۹
 *   روز است و امروز می‌تواند فروردینِ ۳۰ باشد). پس روزِ پایانی با طولِ
 *   همان ماه بریده می‌شود، وگرنه بازه به ماهِ بعدش سرریز می‌کرد و
 *   خرجِ فروردین جزوِ اسفند شمرده می‌شد.
 *
 * عمداً **تابعِ خالص** است و به دیتابیس یا «امروز» وابسته نیست: شاخه‌ی
 * ماهِ کوتاه فقط چند روز در سال رخ می‌دهد و اگر داخلِ کوئری می‌ماند،
 * تست فقط در همان چند روز می‌توانست بگیردش.
 */
function monthComparisonWindow(int $jy, int $jm, int $jd): array
{
    $pJy = $jy; $pJm = $jm - 1;
    if ($pJm < 1) { $pJm = 12; $pJy--; }

    $prevLen = jalaliMonthLength($pJy, $pJm);
    $prevDay = min($jd, $prevLen);

    $g = jalaliToGregorian($pJy, $pJm, 1);
    $prevStart = sprintf('%04d-%02d-%02d', $g[0], $g[1], $g[2]);
    $g = jalaliToGregorian($pJy, $pJm, $prevDay);
    $prevEnd = sprintf('%04d-%02d-%02d', $g[0], $g[1], $g[2]);
    $g = jalaliToGregorian($jy, $jm, 1);
    $curStart = sprintf('%04d-%02d-%02d', $g[0], $g[1], $g[2]);

    return [
        'cur_start'  => $curStart,
        'prev_start' => $prevStart,
        'prev_end'   => $prevEnd,
        'prev_year'  => $pJy,
        'prev_month' => $pJm,
        'prev_day'   => $prevDay,
        'prev_len'   => $prevLen,
    ];
}

/**
 * نوارِ کارتِ «مانده این ماه»: چند درصد از دریافتیِ ماه خرج شده، و خطِ
 * مقایسه با همین روزِ ماهِ قبل.
 *
 * ⛔ تابعِ خالص است و هیچ کوئری‌ای ندارد — ورودی همان `monthComparison()`
 *    است که صفحه یک بار خوانده.
 * ⛔ بدونِ دریافتی، درصد بی‌معناست (تقسیم بر صفر، یا «۰٪» که دروغ است)،
 *    پس `pct = null` و نوار اصلاً رندر نمی‌شود.
 * ⛔ خطِ مقایسه همان نگهبانِ «داده‌ی نازک» جمله‌های بینش را دارد: بدونِ
 *    خرجِ واقعی در **هر دو** ماه هیچ درصدی گفته نمی‌شود.
 * ⚠ `fill` به ۱۰۰ بریده می‌شود ولی `pct` نه — «۱۳۰٪ خرج شده» حرفِ
 *   درستی است که نوار نمی‌تواند نشانش بدهد.
 *
 * @return array{pct:?int, fill:int, tone:string, caption:string, compare:?array{dir:string, text:string}}
 */
function monthMeter(array $mc): array
{
    $in  = (int)($mc['current_income'] ?? 0);
    $out = (int)($mc['current_expense'] ?? 0);

    $pct = null; $fill = 0; $tone = ''; $caption = '';
    if ($in > 0) {
        $pct  = (int)round($out / $in * 100);
        $fill = max(0, min(100, $pct));
        $tone = $pct > 100 ? 'over' : ($pct >= 80 ? 'warn' : '');
        $caption = $pct > 100
            ? 'خرجِ این ماه ' . toPersianDigits((string)($pct - 100)) . '٪ بیشتر از دریافتی است'
            : toPersianDigits((string)$pct) . '٪ از دریافتیِ این ماه خرج شده';
    }

    $compare = null;
    if ((int)($mc['prev_expense'] ?? 0) > 0 && $out > 0) {
        $ch   = (int)($mc['expense_change'] ?? 0);
        $prev = (string)($mc['prev_label'] ?? '');
        if (abs($ch) >= 10) {
            $compare = [
                'dir'  => $ch > 0 ? 'up' : 'down',
                'text' => toPersianDigits((string)abs($ch)) . '٪ ' . ($ch > 0 ? 'بیشتر' : 'کمتر')
                          . ' از همین روزِ ' . $prev . ' خرج کرده‌اید',
            ];
        } else {
            $compare = ['dir' => 'flat', 'text' => 'خرجِ این ماه تقریباً هم‌اندازه‌ی ' . $prev . ' است'];
        }
    }

    return ['pct' => $pct, 'fill' => $fill, 'tone' => $tone, 'caption' => $caption, 'compare' => $compare];
}

function monthComparison(int $userId): array
{
    $pdo = Database::getConnection();
    $today = today();

    [$jy, $jm, $jd] = gregorianToJalali((int)date('Y'), (int)date('m'), (int)date('d'));

    $curStartG = jalaliToGregorian($jy, $jm, 1);
    $curStart = sprintf('%04d-%02d-%02d', $curStartG[0], $curStartG[1], $curStartG[2]);

    $w = monthComparisonWindow($jy, $jm, $jd);
    $pJy = $w['prev_year']; $pJm = $w['prev_month'];
    $prevStart = $w['prev_start'];
    $prevEnd   = $w['prev_end'];
    $prevDay   = $w['prev_day'];

    $q = $pdo->prepare('
        SELECT
            COALESCE(SUM(CASE WHEN type = "income"  THEN amount ELSE 0 END), 0) AS income,
            COALESCE(SUM(CASE WHEN type = "expense" THEN amount ELSE 0 END), 0) AS expense
        FROM transactions
        WHERE user_id = :u AND transaction_date BETWEEN :f AND :t
    ');

    $q->execute(['u' => $userId, 'f' => $curStart, 't' => $today]);
    $cur = $q->fetch();

    $q->execute(['u' => $userId, 'f' => $prevStart, 't' => $prevEnd]);
    $prev = $q->fetch();

    $pct = function ($now, $before) {
        $now = (int)$now; $before = (int)$before;
        if ($before === 0) { return $now > 0 ? 100 : 0; }
        return (int)round((($now - $before) / $before) * 100);
    };

    $monthNames = ['', 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
                   'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];

    return [
        'current_label' => $monthNames[$jm],
        'prev_label' => $monthNames[$pJm],
        'current_income' => (int)$cur['income'],
        'current_expense' => (int)$cur['expense'],
        'prev_income' => (int)$prev['income'],
        'prev_expense' => (int)$prev['expense'],
        'income_change' => $pct($cur['income'], $prev['income']),
        'expense_change' => $pct($cur['expense'], $prev['expense']),
        // برای اینکه صفحه بتواند صادقانه بگوید مقایسه با چه چیزی است.
        // بدون این، «۷٪ بیشتر» معلوم نیست نسبت به چه بازه‌ای است.
        'elapsed_days'  => $jd,
        'total_days'    => jalaliMonthLength($jy, $jm),
        'prev_day'      => $prevDay,
    ];
}

/**
 * ⛔ جمله‌های بینش — تنها جایی که اپ **حرف می‌زند**، نه عدد نشان می‌دهد.
 *
 * دلیل وجودش: همه‌ی این اعداد از قبل بودند (`monthComparison`,
 * `safeToSpend`, `budgetStatuses`, `spendingInsights`) ولی به‌شکلِ عدد،
 * پراکنده در چهار صفحه. کاربر باید خودش نگاه می‌کرد، مقایسه می‌کرد و
 * نتیجه می‌گرفت — یعنی کاری که اپ باید برایش بکند. یک دفترِ خوبِ ثبت،
 * تا وقتی چیزی **نگوید**، فقط یک دفتر است.
 *
 * ⛔ حداکثر سه جمله، و این سقف جدی است. با ده جمله هیچ‌کدام خوانده
 *    نمی‌شوند و کارت به یک دیوارِ متن تبدیل می‌شود — همان چیزی که
 *    صفحه‌ی «فهرست‌های من» برای حذفش ساخته شد.
 *
 * ⛔ و **هیچ جمله‌ای با داده‌ی نازک ساخته نمی‌شود.** درصدِ رشد وقتی ماهِ
 *    قبل صفر بوده بی‌معناست (`monthComparison` در آن حالت ۱۰۰ برمی‌گرداند
 *    که یک عددِ فنی است، نه یک حقیقت). جمله‌ای که بگوید «۱۰۰٪ بیشتر از
 *    ماه قبل» به کاربری که ماه قبل تازه ثبت‌نام کرده، دروغ است — و
 *    اولین جمله‌ی دروغ یعنی کاربر بقیه را هم باور نمی‌کند.
 *
 * @return array<int, array{kind:string, tone:string, text:string, link:?string}>
 */
/**
 * ⚠ `$walletRows` همان نتیجه‌ی `walletBalances()` است، اگر فراخواننده
 *   از قبل گرفته باشدش. صفحه‌ی خانه هم این را صدا می‌زند هم
 *   `pinnedWallets()` را، و بدونِ پاس دادن، سنگین‌ترین کوئریِ اپ **دو
 *   بار** در یک بارگذاری اجرا می‌شد.
 */
function financialHighlights(int $userId, ?array $walletRows = null,
                             ?array $monthCmp = null, bool $monthShownElsewhere = false,
                             array $skip = [], ?array $safe = null): array
{
    $out = [];
    // ⛔ `$skip` کلیدهای `HOME_WIDGETS`ی است که کاربر خاموش کرده. جمله‌ی
    //    خاموش **اصلاً ساخته نمی‌شود** (کوئری‌اش هم زده نمی‌شود)، نه اینکه
    //    ساخته و بعد پنهان شود — پس جمله‌ی بعدی جای آن را در سقفِ سه‌تایی
    //    می‌گیرد. هر جمله `kind` خودش را هم دارد تا تست بتواند بسنجدش.
    $on = fn(string $k): bool => !in_array($k, $skip, true);

    // ---------- ۱. سررسیدِ عقب‌افتاده، مقدم بر همه ----------
    // ⛔ اول از همه، چون تنها چیزی است که **همین حالا** هزینه دارد:
    //    چکِ برگشتی عارضه‌ی حقوقی دارد و بدهیِ دیرکرد رابطه را خراب
    //    می‌کند. مقایسه‌ی هزینه‌ی ماه در برابرش تزئین است.
    if ($on('overdue')) try {
        // ⚠ خانه‌ی دسکتاپ همین عدد را برای کارتِ «پول قابل خرج» از قبل
        //   حساب کرده و پاس می‌دهد — `financialEvents()` چند کوئری است.
        $safe = $safe ?? safeToSpend($userId, 30, $walletRows);
        if (!empty($safe['overdue']) && (int)$safe['overdue'] > 0) {
            $out[] = [
                'kind' => 'overdue',
                'tone' => 'warn',
                'text' => 'سررسیدِ گذشته دارید: ' . formatMoney((int)$safe['overdue']) . ' تومان.',
                'link' => 'due.php?t=list',
            ];
        }
    } catch (Throwable $e) { /* بینش نباید صفحه را بشکند */ }

    // ---------- ۲. بودجه‌ای که رد شده ----------
    if ($on('budget')) try {
        $worst = null;
        foreach (budgetStatuses($userId) as $b) {
            $pct = (int)($b['percent'] ?? 0);
            if ($pct > 100 && ($worst === null || $pct > (int)$worst['percent'])) { $worst = $b; }
        }
        if ($worst !== null) {
            $out[] = [
                'kind' => 'budget',
                'tone' => 'warn',
                'text' => 'بودجه‌ی «' . $worst['category_name'] . '» رد شده — '
                          . toPersianDigits((string)(int)$worst['percent']) . '٪ مصرف شده.',
                'link' => 'budget.php',
            ];
        }
    } catch (Throwable $e) { /* جدول بودجه شاید نیامده باشد */ }

    // ---------- ۳. دسته‌ای که بیشترین رشد را داشته ----------
    // ⛔ این جمله «چرا» را جواب می‌دهد، و بقیه فقط «چقدر». دیدنِ
    //    «هزینه‌ات ۴۰٪ بیشتر شده» بدونِ اینکه بدانی **کجا**، هیچ رفتاری
    //    را عوض نمی‌کند.
    if ($on('growth')) try {
        $grew = topGrowingCategory($userId);
        if ($grew !== null) {
            $out[] = [
                'kind' => 'growth',
                'tone' => 'up',
                'text' => 'این ماه ' . toPersianDigits((string)$grew['pct']) . '٪ بیشتر از ماه قبل خرجِ «'
                          . $grew['name'] . '» شده — ' . formatMoney($grew['now']) . ' تومان.',
                'link' => 'category-report.php?type=expense&preset=this_month',
            ];
        }
    } catch (Throwable $e) { /* ignore */ }

    // ---------- ۴. حالِ کلیِ ماه، فقط اگر جای دیگری پر نشده ----------
    // ⛔ `$monthShownElsewhere`: صفحه‌ی خانه همین مقایسه را روی کارتِ ماه
    //    نوشته، پس اینجا تکرار نمی‌شود. `$monthCmp` هم همان نتیجه‌ای است
    //    که فراخواننده خوانده — دو بار خواندنِ یک جمع بی‌فایده بود.
    if (count($out) < 3 && !$monthShownElsewhere) {
        try {
            $mc = $monthCmp ?? monthComparison($userId);
            // ⛔ نگهبانِ داده‌ی نازک: بدونِ ماهِ قبلِ واقعی، درصد دروغ است.
            if ((int)$mc['prev_expense'] > 0 && (int)$mc['current_expense'] > 0) {
                $ch = (int)$mc['expense_change'];
                if (abs($ch) >= 10) {
                    $out[] = [
                        'kind' => 'month',
                        'tone' => $ch > 0 ? 'up' : 'down',
                        'text' => 'تا امروز ' . toPersianDigits((string)abs($ch)) . '٪ '
                                  . ($ch > 0 ? 'بیشتر' : 'کمتر') . ' از همین روزِ ' . $mc['prev_label'] . ' خرج کرده‌اید.',
                        'link' => 'dashboard.php',
                    ];
                } elseif ($ch === 0 || abs($ch) < 10) {
                    $out[] = [
                        'kind' => 'month',
                        'tone' => 'good',
                        'text' => 'خرجِ این ماه تقریباً هم‌اندازه‌ی ' . $mc['prev_label'] . ' است.',
                        'link' => 'dashboard.php',
                    ];
                }
            }
        } catch (Throwable $e) { /* ignore */ }
    }

    return array_slice($out, 0, 3);
}

/**
 * دسته‌ای که نسبت به همین بازه از ماهِ قبل بیشترین **رشد** را داشته.
 *
 * ⚠ پنجره از `monthComparisonWindow()` می‌آید، نه یک حسابِ تازه: مقایسه
 *   باید «تا همین روزِ ماه» باشد. با کلِ ماهِ قبل، روزِ پنجمِ هر ماه
 *   همیشه «کاهشِ چشمگیر» نشان می‌داد — یک تعریفِ الکی که کاربر خیلی زود
 *   می‌فهمید بی‌معناست.
 *
 * @return array{name:string, now:int, before:int, pct:int}|null
 */
function topGrowingCategory(int $userId): ?array
{
    [$jy, $jm, $jd] = gregorianToJalali((int)date('Y'), (int)date('m'), (int)date('d'));
    $w = monthComparisonWindow($jy, $jm, $jd);

    $sql = '
        SELECT c.name,
               COALESCE(SUM(CASE WHEN t.transaction_date BETWEEN :cf AND :ct THEN t.amount ELSE 0 END), 0) AS now_sum,
               COALESCE(SUM(CASE WHEN t.transaction_date BETWEEN :pf AND :pt THEN t.amount ELSE 0 END), 0) AS before_sum
        FROM transactions t
        JOIN categories c ON c.id = t.category_id
        WHERE t.user_id = :u AND t.type = "expense"
          AND t.transaction_date BETWEEN :lo AND :hi
        GROUP BY c.id, c.name
    ';
    $st = Database::getConnection()->prepare($sql);
    $st->execute([
        'u'  => $userId,
        'cf' => $w['cur_start'], 'ct' => today(),
        'pf' => $w['prev_start'], 'pt' => $w['prev_end'],
        'lo' => $w['prev_start'], 'hi' => today(),
    ]);

    $best = null;
    foreach ($st->fetchAll() as $r) {
        $now    = (int)$r['now_sum'];
        $before = (int)$r['before_sum'];

        // ⛔ نگهبانِ داده‌ی نازک، دو نیمه دارد و هر دو لازم‌اند:
        //    بدونِ ماهِ قبلِ **واقعی** درصد بی‌معناست (هر خریدِ تازه
        //    «۱۰۰٪ رشد» می‌شد)، و بدونِ یک کفِ مبلغ، ۵۰۰ تومان که ۲۰۰۰
        //    تومان شود «۳۰۰٪ رشد» اعلام می‌شد — از نظر ریاضی درست و از
        //    نظر معنا آشغال.
        if ($before < 100000 || $now <= $before) { continue; }
        $pct = (int)round((($now - $before) / $before) * 100);
        if ($pct < 25) { continue; }

        if ($best === null || $pct > $best['pct']) {
            $best = ['name' => (string)$r['name'], 'now' => $now, 'before' => $before, 'pct' => $pct];
        }
    }
    return $best;
}

/**
 * بیشترین هزینه‌ها و میانگین روزانه در یک بازه.
 */
function spendingInsights(int $userId, string $fromDate, string $toDate): array
{
    $pdo = Database::getConnection();

    $topStmt = $pdo->prepare('
        SELECT t.title, t.amount, t.transaction_date, c.name AS category_name
        FROM transactions t
        LEFT JOIN categories c ON c.id = t.category_id
        WHERE t.user_id = :u AND t.type = "expense" AND t.transaction_date BETWEEN :f AND :t
        ORDER BY t.amount DESC LIMIT 5
    ');
    $topStmt->execute(['u' => $userId, 'f' => $fromDate, 't' => $toDate]);
    $topExpenses = $topStmt->fetchAll();

    $sumStmt = $pdo->prepare('
        SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS cnt
        FROM transactions
        WHERE user_id = :u AND type = "expense" AND transaction_date BETWEEN :f AND :t
    ');
    $sumStmt->execute(['u' => $userId, 'f' => $fromDate, 't' => $toDate]);
    $sum = $sumStmt->fetch();

    $days = max(1, (int)((strtotime($toDate) - strtotime($fromDate)) / 86400) + 1);

    return [
        'top_expenses' => $topExpenses,
        'total' => (int)$sum['total'],
        'count' => (int)$sum['cnt'],
        'daily_average' => (int)round((int)$sum['total'] / $days),
        'days' => $days,
    ];
}

/**
 * شماره‌ی نسخه‌ی فایل‌های ثابت برای کش مرورگر.
 *
 * نکته‌ی مهم: هرگز از time() استفاده نمی‌کند. اگر از time() استفاده شود،
 * آدرس فایل در هر ثانیه عوض می‌شود و مرورگر مجبور است CSS و JS را
 * در «هر بار بارگذاری صفحه» دوباره دانلود کند — که کندی شدید ایجاد می‌کند.
 * اگر خواندن زمان فایل ممکن نبود، یک مقدار ثابت برمی‌گرداند.
 */
/**
 * دسته‌بندی‌ها در طول یک درخواست فقط یک‌بار از دیتابیس خوانده می‌شوند.
 * چند بخش صفحه (فرم ثبت، مودال ویرایش، …) به همین فهرست نیاز دارند.
 */
/**
 * شرط SQL برای «دسته‌هایی که این کاربر می‌بیند».
 *
 * `categories.user_id` سه حالت دارد و این تابع تنها جایی است که معنایش
 * تعریف می‌شود — هر کوئری روی دسته‌ها باید از همین رد شود، وگرنه یک
 * صفحه دسته‌های شخصی را می‌بیند و صفحه‌ی بغلی نه:
 *
 *   NULL   → دسته‌ی پیش‌فرضِ برنامه، مال همه
 *   عددِ X → دسته‌ی شخصیِ کاربر X، فقط خودش
 *
 * روی نصبی که migration_user_categories هنوز اجرا نشده، ستون نیست و
 * شرط خالی برمی‌گردد — یعنی همان رفتار قبلی.
 *
 * @param string $alias پیشوند جدول در کوئری (مثلاً 'c.')
 * @return string قطعه‌ی SQL آماده‌ی چسباندن با AND — پارامتر :cat_uid
 */
function categoryScopeSql(string $alias = ''): string
{
    if (!tableHasColumn('categories', 'user_id')) { return '1=1'; }

    return "({$alias}user_id IS NULL OR {$alias}user_id = :cat_uid)";
}

/** پارامترهای همراهِ categoryScopeSql — اگر ستون نباشد، خالی. */
function categoryScopeParams(int $userId): array
{
    return tableHasColumn('categories', 'user_id') ? ['cat_uid' => $userId] : [];
}

/**
 * ⛔ جدول‌هایی که به `categories.id` اشاره می‌کنند — **کشف از خودِ
 *    دیتابیس، نه فهرستِ دستی** (همان قاعده‌ی `userDataTables()`).
 *
 * اینجا فهرست باید **کامل** باشد، نه گزینشی: هر جدولی که جا بماند
 * یعنی ردیف‌هایی که به دسته اشاره می‌کنند دیده نمی‌شوند، و آن‌وقت
 * حذف یا شخصی‌سازی بی‌صدا داده را می‌برد. امروز چهار تاست
 * (`transactions`, `budgets`, `recurring_transactions`, `category_pins`)
 * و جدولِ فردا هم خودبه‌خود می‌آید.
 *
 * @return array<string, bool> نامِ جدول => آیا ستونِ `user_id` هم دارد
 */
function categoryRefTables(): array
{
    // ⛔ `category_id`ی که کلیدِ خارجی‌اش به **جدولِ دیگری** است مالِ دسته‌بندیِ شخصی
    //    نیست: `biz_products.category_id` → `biz_categories` و `biz_payments.category_id`
    //    → `biz_expense_cats`. بازرسیِ مهر ۱۴۰۵: ادغام و شخصی‌سازیِ دسته‌بندی آن‌ها را هم
    //    `UPDATE` می‌کرد — دسته‌ی کالا و سرفصلِ هزینه‌ی فروشگاهی که شناسه‌اش هم‌عدد بود
    //    بی‌صدا عوض می‌شد، و حذفِ دسته با «در حال استفاده» بی‌دلیل رد می‌شد.
    $foreign = [];
    try {
        $st = Database::getConnection()->query(
            "SELECT TABLE_NAME AS t FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'category_id'
               AND REFERENCED_TABLE_NAME IS NOT NULL AND REFERENCED_TABLE_NAME <> 'categories'"
        );
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $t) { $foreign[$t] = true; }
    } catch (PDOException $e) {
        Log::error('category.ref_discovery', $e);
    }
    $out = [];
    foreach (schemaMap() as $table => $cols) {
        if ($table === 'categories' || !isset($cols['category_id'])) { continue; }
        // ⚠ و جدول‌های فروشگاه هرگز به دسته‌ی شخصی اشاره نمی‌کنند (قاعده ۷۰) — حتی اگر
        //   روی نصبی کلیدِ خارجی‌شان هنوز ساخته نشده باشد.
        if (isset($foreign[$table]) || str_starts_with($table, 'biz_')) { continue; }
        $out[$table] = isset($cols['user_id']);
    }
    ksort($out);
    return $out;
}

/**
 * چه کسانی از این دسته‌بندی استفاده کرده‌اند و در چند ردیف.
 *
 * ⛔ این **پیش‌نمایشِ دامنه‌ی تخریب** است و اختیاری نیست: بدونِ آن،
 *    مدیر دکمه‌ای می‌زند که نمی‌داند به چند نفر دست می‌زند — و
 *    «شخصی‌سازی» عملیاتی است که چند جدول و چند کاربر را با هم عوض
 *    می‌کند.
 *
 * `blocked` جدول‌هایی است که `category_id` دارند ولی `user_id` ندارند،
 * پس ردیفشان به هیچ کاربری منتسب نمی‌شود. امروز خالی است؛ اگر روزی
 * پر شود، `privatizeDefaultCategory()` صریح امتناع می‌کند — **نه اینکه
 * آن ردیف‌ها را بی‌صدا جا بگذارد**.
 *
 * @return array{users:int[], rows:int, per:array<string,int>, blocked:string[]}
 */
function categoryUsage(int $catId): array
{
    $pdo   = Database::getConnection();
    $users = [];
    $per   = [];
    $rows  = 0;
    $blocked = [];

    foreach (categoryRefTables() as $table => $hasUser) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE category_id = :c");
        $st->execute(['c' => $catId]);
        $n = (int)$st->fetchColumn();
        if ($n === 0) { continue; }

        $per[$table] = $n;
        $rows += $n;

        if (!$hasUser) { $blocked[] = $table; continue; }

        $st = $pdo->prepare("SELECT DISTINCT user_id FROM `{$table}`
                             WHERE category_id = :c AND user_id IS NOT NULL");
        $st->execute(['c' => $catId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $uid) { $users[(int)$uid] = true; }

        // ردیفی که `user_id` اش خالی است هم به کسی منتسب نمی‌شود.
        $st = $pdo->prepare("SELECT COUNT(*) FROM `{$table}`
                             WHERE category_id = :c AND user_id IS NULL");
        $st->execute(['c' => $catId]);
        if ((int)$st->fetchColumn() > 0) { $blocked[] = $table; }
    }

    $ids = array_keys($users);
    sort($ids);
    return ['users' => $ids, 'rows' => $rows, 'per' => $per, 'blocked' => array_values(array_unique($blocked))];
}

/**
 * همان شمارش، ولی برای **کلِ فهرست** و با چهار کوئری، نه یکی به‌ازای
 * هر دسته — همان قاعده‌ی N+1 که در «سرعت» بارها گرفته شده.
 *
 * ⚠ این فقط برای **نمایش** است. خودِ عملیات همیشه از `categoryUsage()`
 *   و **زیرِ تراکنش** دوباره می‌شمارد: بین دیدنِ صفحه و زدنِ دکمه
 *   ممکن است کاربری تراکنشِ تازه‌ای ثبت کرده باشد (همان استدلالِ کدِ
 *   تخفیف که فرم فقط حمل می‌کند و سرور زیرِ قفل می‌سنجد).
 *
 * @return array<int, array{users:int, rows:int}>
 */
function categoryUsageMap(): array
{
    $pdo  = Database::getConnection();
    $seen = [];   // catId => [userId => true]
    $rows = [];   // catId => int

    // ⛔ یک `UNION ALL`، نه یک کوئری به‌ازای هر جدول. این تابع در
    //    `admin/categories.php` صدا زده می‌شود و نسخه‌ی حلقه‌ای آن صفحه
    //    را از ۱۳ به ۱۷ کوئری برد — یعنی از بودجه‌اش در
    //    `test_query_budget` رد شد و **خودِ تست گرفتش**. جدولِ ارجاعِ
    //    فردا هم فقط یک `SELECT` به همین رشته اضافه می‌کند، نه یک
    //    رفت‌وبرگشتِ تازه.
    // ⚠ نامِ جدول‌ها از `schemaMap()` می‌آید (نه ورودیِ کاربر)، پس درجِ
    //   مستقیمشان همان کاری است که حلقه‌ی قبلی هم می‌کرد.
    $parts = [];
    foreach (categoryRefTables() as $table => $hasUser) {
        if (!$hasUser) { continue; }
        $parts[] = "SELECT category_id AS cid, user_id AS uid, COUNT(*) AS n
                    FROM `{$table}` WHERE category_id IS NOT NULL
                    GROUP BY category_id, user_id";
    }
    if (!$parts) { return []; }

    try {
        $q = $pdo->query(implode("\nUNION ALL\n", $parts));
    } catch (PDOException $e) {
        // فقط نمایش است؛ ستونِ «استفاده» خالی می‌ماند و صفحه می‌آید.
        Log::error('category.usage_map_failed', $e);
        return [];
    }
    foreach ($q as $r) {
        $cid = (int)$r['cid'];
        $rows[$cid] = ($rows[$cid] ?? 0) + (int)$r['n'];
        if ($r['uid'] !== null) { $seen[$cid][(int)$r['uid']] = true; }
    }

    $out = [];
    foreach ($rows as $cid => $n) {
        $out[$cid] = ['users' => count($seen[$cid] ?? []), 'rows' => $n];
    }
    return $out;
}

/**
 * ⛔ «شخصی‌سازیِ» یک دسته‌بندیِ پیش‌فرض — تنها جای این تصمیم.
 *
 * **مسئله‌ی واقعی:** دسته‌های پیش‌فرض بین همه‌ی کاربران مشترک‌اند
 * (`user_id IS NULL`)، پس یک دسته‌ی خاصِ کارِ یک نفر — «فروش لوازم»،
 * «گوشی» — در فرمِ ثبتِ **همه** دیده می‌شود و شلوغش می‌کند. ولی
 * حذفش هم ممکن نیست، چون رویش تراکنش ثبت شده.
 *
 * **کاری که می‌کند:** برای **هر کاربری که واقعاً از آن استفاده کرده**
 * یک نسخه‌ی شخصی می‌سازد، ردیف‌های خودِ همان کاربر را به نسخه‌ی خودش
 * می‌برد، و بعد ردیفِ پیش‌فرض را حذف می‌کند. نتیجه: از فهرستِ کسانی
 * که استفاده‌اش نمی‌کردند می‌رود، و برای کسانی که می‌کردند **دقیقاً
 * سرِ جایش می‌ماند** — نه تراکنشی بی‌دسته می‌شود نه تاریخچه‌ای گم.
 *
 * **⛔ چرا نسخه‌ی جدا به‌ازای هر کاربر، نه یک نسخه برای مدیر:** تراکنشِ
 * کاربر A را نمی‌شود به دسته‌ی کاربر B چسباند — آن دقیقاً همان نشتیِ
 * بینِ کاربران است که `categoryScopeSql()` برای نبودنش نوشته شد. پس
 * هر کس نسخه‌ی خودش را می‌گیرد.
 *
 * **⛔ و یک سدِ پیش از `commit`، مثل `deleteUserAccount()`:** جمعِ
 * ردیف‌هایی که جابه‌جا شدند باید **دقیقاً** برابرِ جمعِ ردیف‌هایی باشد
 * که پیش از کار به دسته اشاره می‌کردند، و هیچ ردیفی هم نباید روی
 * شناسه‌ی قدیمی مانده باشد. یک تستِ خوب جلوی `commit` را نمی‌گیرد؛
 * خودِ کد باید بگیرد.
 *
 * @return array{ok:bool, message:string, users:int, rows:int}
 */
function privatizeDefaultCategory(int $catId): array
{
    $pdo = Database::getConnection();

    if (!tableHasColumn('categories', 'user_id')) {
        return ['ok' => false, 'message' => 'ستون user_id روی دسته‌بندی‌ها نیامده است.', 'users' => 0, 'rows' => 0];
    }

    $st = $pdo->prepare('SELECT * FROM categories WHERE id = :id AND user_id IS NULL');
    $st->execute(['id' => $catId]);
    $cat = $st->fetch();
    if (!$cat) {
        return ['ok' => false, 'message' => 'دسته‌بندی پیش‌فرض با این شناسه پیدا نشد.', 'users' => 0, 'rows' => 0];
    }

    $usage = categoryUsage($catId);
    if ($usage['blocked']) {
        return [
            'ok' => false,
            'users' => 0, 'rows' => 0,
            'message' => 'این دسته ردیف‌هایی دارد که به هیچ کاربری منتسب نمی‌شوند ('
                . implode('، ', $usage['blocked']) . '). شخصی‌سازی انجام نشد.',
        ];
    }

    $hasIcon  = tableHasColumn('categories', 'icon');
    $hasColor = tableHasColumn('categories', 'color');
    $refs     = categoryRefTables();
    $moved    = 0;

    try {
        $pdo->beginTransaction();

        foreach ($usage['users'] as $uid) {
            // نسخه‌ی شخصیِ موجود با همان نام و نوع دوباره ساخته نمی‌شود،
            // وگرنه کاربر دو قلمِ هم‌نام در فهرستش می‌دید.
            // ⚠ مقایسه‌ی نام با پارامترِ bind‌شده است نه ستون‌به‌ستون، پس
            //   «Illegal mix of collations» ممکن نیست.
            $find = $pdo->prepare('SELECT id FROM categories
                                   WHERE user_id = :u AND name = :n AND type = :t LIMIT 1');
            $find->execute(['u' => $uid, 'n' => $cat['name'], 't' => $cat['type']]);
            $newId = (int)$find->fetchColumn();

            if ($newId === 0) {
                $cols = ['user_id', 'name', 'type', 'is_active'];
                $vals = [':u', ':n', ':t', ':a'];
                $args = ['u' => $uid, 'n' => $cat['name'], 't' => $cat['type'], 'a' => (int)$cat['is_active']];
                if ($hasIcon)  { $cols[] = 'icon';  $vals[] = ':i'; $args['i'] = $cat['icon']; }
                if ($hasColor) { $cols[] = 'color'; $vals[] = ':c'; $args['c'] = $cat['color']; }

                $ins = $pdo->prepare('INSERT INTO categories (' . implode(', ', $cols) . ')
                                      VALUES (' . implode(', ', $vals) . ')');
                $ins->execute($args);
                $newId = (int)$pdo->lastInsertId();
            }

            foreach ($refs as $table => $hasUser) {
                if (!$hasUser) { continue; }
                // ⛔ شرطِ `user_id` روی خودِ UPDATE اجباری است: بدونش
                //    ردیفِ کاربرِ دیگری به دسته‌ی این کاربر می‌چسبید.
                $up = $pdo->prepare("UPDATE `{$table}` SET category_id = :new
                                     WHERE category_id = :old AND user_id = :u");
                $up->execute(['new' => $newId, 'old' => $catId, 'u' => $uid]);
                $moved += $up->rowCount();
            }
        }

        // سدِ پیش از commit — هیچ ردیفی نباید روی شناسه‌ی قدیمی مانده باشد.
        $left = 0;
        foreach ($refs as $table => $hasUser) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE category_id = :c");
            $st->execute(['c' => $catId]);
            $left += (int)$st->fetchColumn();
        }
        if ($left !== 0 || $moved !== $usage['rows']) {
            $pdo->rollBack();
            return [
                'ok' => false, 'users' => 0, 'rows' => 0,
                'message' => 'شمارشِ ردیف‌ها نخواند (منتقل‌شده ' . $moved . ' از ' . $usage['rows']
                    . '، باقی‌مانده ' . $left . '). هیچ تغییری ذخیره نشد.',
            ];
        }

        $pdo->prepare('DELETE FROM categories WHERE id = :id AND user_id IS NULL')
            ->execute(['id' => $catId]);

        $pdo->commit();
        Audit::log('category.privatized', 'category', $catId,
            ['users' => (int)$usage['users'], 'rows' => (int)$usage['rows']]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        Log::error('category.privatize_failed', $e);
        return ['ok' => false, 'message' => 'خطایی در شخصی‌سازی رخ داد؛ هیچ تغییری ذخیره نشد.', 'users' => 0, 'rows' => 0];
    }

    return [
        'ok'      => true,
        'users'   => count($usage['users']),
        'rows'    => $moved,
        'message' => 'دسته‌بندی «' . $cat['name'] . '» از فهرست عمومی برداشته شد و برای '
            . count($usage['users']) . ' کاربر به دسته‌بندی شخصی تبدیل شد.',
    ];
}

/**
 * ⛔ کلیدهای **یکتای** هر جدولِ ارجاع‌دهنده که `category_id` در آن‌ها
 *    هست — کشف از خودِ دیتابیس، نه فهرستِ دستی (همان قاعده‌ی
 *    `categoryRefTables()`).
 *
 * **چرا لازم است، و بدونش خرابی چه شکلی بود:** `category_pins` کلیدِ
 * اصلی‌اش `(user_id, category_id)` است و `budgets` کلیدِ یکتای
 * `(user_id, category_id, period_type)` دارد. پس اگر کاربری **هر دو**
 * دسته را پین کرده باشد (یا روی هر دو بودجه بسته باشد)، یک
 * `UPDATE … SET category_id = :into` با **خطای کلیدِ تکراری** می‌میرد
 * و کلِ ادغام برمی‌گردد — با یک پیامِ عمومیِ «خطایی رخ داد» که هیچ
 * نمی‌گوید چرا. بدتر: روی نصبی که کسی این کار را نکرده **بی‌عیب**
 * اجرا می‌شود، پس در آزمایش سالم به نظر می‌رسد و فقط روی دیتابیسِ
 * واقعی می‌ترکد.
 *
 * @return array<string, list<list<string>>> جدول => فهرستِ کلیدها، هر کلید = ستون‌های **دیگرش**
 */
function categoryUniqueKeys(): array
{
    static $out = null;
    if ($out !== null) { return $out; }
    $out = [];

    try {
        $q = Database::getConnection()->query(
            "SELECT TABLE_NAME AS t, INDEX_NAME AS k, COLUMN_NAME AS c
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND NON_UNIQUE = 0
             ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX"
        );
    } catch (PDOException $e) {
        Log::error('category.unique_keys_failed', $e);
        return $out;
    }

    $idx = [];
    foreach ($q as $r) { $idx[$r['t']][$r['k']][] = $r['c']; }

    foreach ($idx as $table => $keys) {
        foreach ($keys as $cols) {
            if (!in_array('category_id', $cols, true)) { continue; }
            // ⚠ **همه‌ی** کلیدهای یکتا نگه داشته می‌شوند، نه فقط یکی:
            //   یک جدول می‌تواند دو کلید داشته باشد و رد شدن از یکی
            //   چیزی درباره‌ی آن یکی نمی‌گوید.
            $out[$table][] = array_values(array_diff($cols, ['category_id']));
        }
    }
    return $out;
}

/**
 * ⛔ ادغامِ دو دسته‌بندیِ هم‌معنا — تنها جای این تصمیم.
 *
 * **مسئله‌ی واقعی، و مالکِ نصب گزارشش کرد:** «حمل و نقل» و «حمل‌ونقل»
 * هر دو در فهرست بودند. برای کاربر **یک چیزند** و فقط نیم‌فاصله
 * فرقشان است، ولی برای دیتابیس دو ردیفِ جدا با دو تاریخچه — یعنی
 * گزارشِ دسته‌بندی همان خرج را **دو تکه** نشان می‌داد و هیچ‌کدام
 * عددِ درست نبودند. حذفِ یکی هم ممکن نبود، چون رویش تراکنش ثبت شده.
 *
 * **کاری که می‌کند:** هر ردیفی که به `from` اشاره می‌کند به `into`
 * منتقل می‌شود و بعد `from` حذف می‌شود. هیچ تراکنشی بی‌دسته نمی‌شود و
 * هیچ عددی عوض نمی‌شود — فقط دو ستون به یکی می‌رسند.
 *
 * **⛔ دو دامنه، و مرزشان همان `categoryScopeSql()` است:**
 *  - `$scopeUserId === null` → دامنه‌ی **مدیر**: هر دو باید پیش‌فرضِ
 *    برنامه باشند (`user_id IS NULL`) و ردیف‌های **همه‌ی** کاربران
 *    جابه‌جا می‌شوند. نشتی ممکن نیست چون مقصد هم برای همه است.
 *  - عدد → دامنه‌ی **کاربر**: مبدأ باید دسته‌ی **شخصیِ خودش** باشد
 *    (حذفِ پیش‌فرض از اینجا یعنی دست زدن به فهرستِ بقیه) و مقصد باید
 *    برایش دیدنی باشد. فقط ردیف‌های خودش جابه‌جا می‌شوند.
 *
 * **⛔ نوع باید یکی باشد.** ادغامِ یک دسته‌ی هزینه در یک دسته‌ی درآمد
 * هیچ خطایی نمی‌دهد و فقط گزارشِ درآمد را با خرج باد می‌کند — همان
 * خرابیِ بی‌صدا که این اپ همه‌جا برای نبودنش تست نوشته.
 *
 * **⛔ و یک سدِ پیش از `commit`، مثل `privatizeDefaultCategory()`:** هیچ
 * ردیفی نباید روی شناسه‌ی قدیمی مانده باشد، وگرنه `DELETE` بعدی با
 * `ON DELETE CASCADE` بودجه‌ی کسی را با خودش می‌برد. یک تستِ خوب جلوی
 * `commit` را نمی‌گیرد؛ خودِ کد باید بگیرد.
 *
 * @return array{ok:bool, message:string, moved:int, dropped:int}
 */
function mergeCategories(int $fromId, int $intoId, ?int $scopeUserId): array
{
    $fail = fn(string $m) => ['ok' => false, 'message' => $m, 'moved' => 0, 'dropped' => 0];
    $pdo  = Database::getConnection();

    if (!tableHasColumn('categories', 'user_id')) {
        return $fail('ستون user_id روی دسته‌بندی‌ها نیامده است.');
    }
    if ($fromId === $intoId || $fromId <= 0 || $intoId <= 0) {
        return $fail('دو دسته‌بندیِ متفاوت انتخاب کنید.');
    }

    // مبدأ: در دامنه‌ی مدیر فقط پیش‌فرض، در دامنه‌ی کاربر فقط شخصیِ خودش.
    $srcSql = $scopeUserId === null
        ? 'SELECT * FROM categories WHERE id = :id AND user_id IS NULL'
        : 'SELECT * FROM categories WHERE id = :id AND user_id = :u';
    $st = $pdo->prepare($srcSql);
    $st->execute($scopeUserId === null ? ['id' => $fromId] : ['id' => $fromId, 'u' => $scopeUserId]);
    $from = $st->fetch();
    if (!$from) {
        return $fail($scopeUserId === null
            ? 'دسته‌بندیِ مبدأ در فهرستِ پیش‌فرض پیدا نشد.'
            : 'دسته‌بندیِ مبدأ باید یکی از دسته‌بندی‌های شخصیِ خودتان باشد.');
    }

    // مقصد: مدیر فقط پیش‌فرض؛ کاربر، شخصیِ خودش یا پیش‌فرضِ برنامه.
    $dstSql = $scopeUserId === null
        ? 'SELECT * FROM categories WHERE id = :id AND user_id IS NULL'
        : 'SELECT * FROM categories WHERE id = :id AND (user_id = :u OR user_id IS NULL)';
    $st = $pdo->prepare($dstSql);
    $st->execute($scopeUserId === null ? ['id' => $intoId] : ['id' => $intoId, 'u' => $scopeUserId]);
    $into = $st->fetch();
    if (!$into) { return $fail('دسته‌بندیِ مقصد پیدا نشد.'); }

    if ($from['type'] !== $into['type']) {
        return $fail('نوعِ دو دسته‌بندی یکی نیست (یکی درآمد و دیگری هزینه). ادغام انجام نشد.');
    }

    $refs   = categoryRefTables();
    $uniq   = categoryUniqueKeys();
    $moved  = 0;
    $dropped = 0;

    // شرطِ دامنه — در دامنه‌ی کاربر روی خودِ `UPDATE` هم می‌آید. افزونه
    // است (مالکیتِ مبدأ بالاتر سنجیده شد) ولی آخرین سد همین است.
    $scopeWhere = $scopeUserId === null ? '' : ' AND user_id = :u';
    $scopeArg   = $scopeUserId === null ? [] : ['u' => $scopeUserId];

    try {
        $pdo->beginTransaction();

        // چند ردیف پیش از کار به مبدأ اشاره می‌کردند (زیرِ تراکنش، نه
        // از روی صفحه‌ای که ممکن است کهنه باشد).
        $before = 0;
        foreach ($refs as $table => $hasUser) {
            $sw = ($hasUser && $scopeUserId !== null) ? $scopeWhere : '';
            $q  = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE category_id = :c{$sw}");
            $q->execute(['c' => $fromId] + ($sw !== '' ? $scopeArg : []));
            $before += (int)$q->fetchColumn();
        }

        foreach ($refs as $table => $hasUser) {
            $sw   = ($hasUser && $scopeUserId !== null) ? $scopeWhere : '';
            $args = ['c' => $fromId] + ($sw !== '' ? $scopeArg : []);

            foreach ($uniq[$table] ?? [] as $keyCols) {
                // ستون‌های دیگرِ کلید با `<=>` مقایسه می‌شوند نه `=`:
                // مقدارِ NULL با `=` هرگز برابر نمی‌شود و برخوردِ واقعی
                // **دیده نمی‌شد**.
                $on = $keyCols
                    ? implode(' AND ', array_map(fn($c) => "a.`{$c}` <=> b.`{$c}`", $keyCols))
                    : '1=1';
                $cnt = $pdo->prepare(
                    "SELECT COUNT(*) FROM `{$table}` a JOIN `{$table}` b ON {$on}
                     WHERE a.category_id = :c AND b.category_id = :into"
                    . ($sw !== '' ? ' AND a.user_id = :u' : '')
                );
                $cnt->execute($args + ['into' => $intoId]);
                $clash = (int)$cnt->fetchColumn();
                if ($clash === 0) { continue; }

                /*
                 * ⛔ `category_pins` تنها استثناست و فهرستش عمداً
                 *    تک‌قلمی و بسته است: ردیفش **هیچ اطلاعاتی جز
                 *    «این جفت وجود دارد»** ندارد، پس دو پینِ هم‌کاربر
                 *    که به یک دسته می‌رسند فقط یک پین‌اند و انداختنِ
                 *    تکراری تنها معنای ممکن است.
                 *
                 * ⛔ هر جدولِ دیگری صریح **امتناع** می‌کند، نه اینکه
                 *    بی‌صدا یکی را بیندازد: ردیفِ `budgets` عددی است
                 *    که کاربر خودش تایپ کرده و انداختنش یعنی از بین
                 *    بردنِ کارِ او بی‌آنکه بفهمد.
                 */
                if ($table !== 'category_pins') {
                    $pdo->rollBack();
                    return $fail(
                        'روی جدول «' . $table . '» ' . toPersianDigits($clash)
                        . ' ردیف روی هر دو دسته‌بندی ثبت شده و با ادغام تکراری می‌شوند. '
                        . 'اول یکی از آن دو را پاک کنید. هیچ تغییری ذخیره نشد.'
                    );
                }

                $del = $pdo->prepare(
                    "DELETE a FROM `{$table}` a JOIN `{$table}` b ON {$on}
                     WHERE a.category_id = :c AND b.category_id = :into"
                    . ($sw !== '' ? ' AND a.user_id = :u' : '')
                );
                $del->execute($args + ['into' => $intoId]);
                $dropped += $del->rowCount();
            }

            $up = $pdo->prepare("UPDATE `{$table}` SET category_id = :into WHERE category_id = :c{$sw}");
            $up->execute($args + ['into' => $intoId]);
            $moved += $up->rowCount();
        }

        // ⛔ سدِ پیش از commit: هیچ ردیفی نباید روی شناسه‌ی قدیمی مانده
        //    باشد. اگر مانده بود و باز هم حذف می‌کردیم، `ON DELETE
        //    CASCADE` روی `budgets` بودجه‌ی کسی را با خودش می‌برد.
        $left = 0;
        foreach ($refs as $table => $hasUser) {
            $q = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE category_id = :c");
            $q->execute(['c' => $fromId]);
            $left += (int)$q->fetchColumn();
        }
        if ($left !== 0 || $moved + $dropped !== $before) {
            $pdo->rollBack();
            return $fail('شمارشِ ردیف‌ها نخواند (منتقل‌شده ' . toPersianDigits($moved)
                . ' از ' . toPersianDigits($before) . '، باقی‌مانده ' . toPersianDigits($left)
                . '). هیچ تغییری ذخیره نشد.');
        }

        $delSql = $scopeUserId === null
            ? 'DELETE FROM categories WHERE id = :id AND user_id IS NULL'
            : 'DELETE FROM categories WHERE id = :id AND user_id = :u';
        $pdo->prepare($delSql)
            ->execute($scopeUserId === null ? ['id' => $fromId] : ['id' => $fromId, 'u' => $scopeUserId]);

        $pdo->commit();
        Audit::log('category.merged', 'category', $intoId,
            ['from' => $fromId, 'scope' => $scopeUserId === null ? 'admin' : 'user', 'rows' => $moved]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        Log::error('category.merge_failed', $e);
        return $fail('خطایی در ادغام رخ داد؛ هیچ تغییری ذخیره نشد.');
    }

    return [
        'ok'      => true,
        'moved'   => $moved,
        'dropped' => $dropped,
        'message' => 'دسته‌بندی «' . $from['name'] . '» در «' . $into['name'] . '» ادغام شد — '
            . toPersianDigits($moved) . ' ردیف منتقل شد.',
    ];
}

/**
 * دسته‌بندی‌های قابل استفاده‌ی کاربر جاری — پیش‌فرض‌ها به‌علاوه‌ی شخصی‌ها.
 * در همان درخواست کش می‌شود چون چند جا لازم است (فرم ثبت، شیت فوتر، …).
 */
function cachedCategories(): array
{
    static $cats = null;
    if ($cats !== null) { return $cats; }

    // functions.php عمداً auth.php را require نمی‌کند (تست‌ها بدون آن
    // لودش می‌کنند)، پس وجود کلاس سنجیده می‌شود.
    $userId = class_exists('Auth') ? (int)Auth::userId() : 0;

    // ⚠ `icon` و `color` را `migration_category_icons` می‌سازد، پس روی
    //   نصبِ migration‌نخورده وجود ندارند و «Unknown column» کلِ فرمِ ثبت
    //   را می‌خواباند. `tableHasColumn()` از `schemaMap()` می‌خواند که در
    //   همان درخواست کش است، پس این شرط کوئریِ تازه‌ای نمی‌زند.
    $extra = '';
    if (tableHasColumn('categories', 'icon'))  { $extra .= ', c.icon'; }
    if (tableHasColumn('categories', 'color')) { $extra .= ', c.color'; }

    // ⛔ پینِ کاربر با **JOIN** به همین کوئری می‌آید، نه با یک کوئریِ
    //    دوم. شیتِ ثبت در فوترِ **هر** صفحه رندر می‌شود، پس کوئریِ جدا
    //    یعنی یکی به بودجه‌ی هر ۲۷ صفحه اضافه شود — همان «خزشِ بی‌صدا»
    //    که کلِ `test_query_budget` برای گرفتنش نوشته شد. اینجا پین یک
    //    ویژگیِ خودِ ردیف است، پس از همان‌جا که دسته‌ها خوانده می‌شوند
    //    می‌آید و هزینه‌اش صفر است.
    $pinSel  = '0 AS pinned';
    $pinJoin = '';
    $pinArgs = [];
    if ($userId > 0 && tableExists('category_pins')) {
        $pinSel  = '(p.category_id IS NOT NULL) AS pinned';
        $pinJoin = ' LEFT JOIN category_pins p
                       ON p.category_id = c.id AND p.user_id = :pin_uid';
        $pinArgs = ['pin_uid' => $userId];
    }

    try {
        $st = Database::getConnection()->prepare(
            'SELECT c.id, c.name, c.type, c.user_id' . $extra . ', ' . $pinSel . '
             FROM categories c' . $pinJoin . '
             WHERE c.is_active = 1 AND ' . categoryScopeSql('c.') . '
             ORDER BY c.type, c.name'
        );
        $st->execute(categoryScopeParams($userId) + $pinArgs);
        $cats = $st->fetchAll();
    } catch (PDOException $e) {
        $cats = [];
    }

    return $cats;
}

/**
 * ⛔ تنها جایی که «کدام دسته‌ها روی فرمِ ثبت چیپ می‌گیرند» تعریف می‌شود
 * (مثل `pinnedWallets()` و `chequeActiveSql()`).
 *
 * دو حالت، و ترتیبشان کلِ نکته است:
 *   ۱. اگر کاربر برای این **نوع** چیزی پین کرده باشد، دقیقاً همان‌ها —
 *      انتخابِ صریحِ کاربر همیشه برنده است.
 *   ۲. وگرنه پرکاربردترین‌ها از `categoriesByUse()`.
 *
 * ⛔ نبودِ حالتِ دوم یعنی کاربری که هنوز چیزی پین نکرده (یعنی **همه‌ی
 *    نصب‌های امروز**) ردیفِ چیپ را از دست می‌داد و بودجه‌ی «سه تپ» بی‌صدا
 *    به چهار برمی‌گشت. هیچ نصبی با `git pull` چیزی از دست نمی‌دهد.
 *
 * ⚠ سقف در **هر دو** حالت اعمال می‌شود: ردیف یک خطِ افقیِ اسکرول‌شونده
 *   است و کاربری که ۲۰ دسته پین کند، عملاً همان منوی شلوغ را ساخته.
 */
function categoriesForGrid(array $cats, array $useCounts, string $type): array
{
    $ofType = array_values(array_filter($cats, fn($c) => $c['type'] === $type));

    $pinned = array_values(array_filter($ofType, fn($c) => !empty($c['pinned'])));
    $rows   = $pinned ?: $ofType;

    return array_slice(categoriesByUse($rows, $useCounts), 0, CATEGORY_GRID_MAX);
}

/**
 * پنجره‌ی «اخیر» برای ترتیبِ شبکه‌ی دسته‌بندی — تنها مرجع.
 *
 * ⛔ عددِ ثابتِ داخلِ کوئری ننویسید، به همان دلیلِ `RECENT_TITLE_SCAN`:
 *    این کوئری در فوترِ **هر صفحه** اجرا می‌شود (شیتِ ثبت آنجاست) و
 *    اندازه‌ی این پنجره تنها چیزی است که هزینه‌اش را مهار می‌کند.
 *
 * ⚠ نصفِ `RECENT_TITLE_SCAN` است و عمداً: شبکه فقط
 *   `CATEGORY_GRID_MAX` چیپ نشان می‌دهد، پس ۲۰۰ ردیفِ آخر برای
 *   رتبه‌بندیِ هشت‌تای اول کاملاً کافی است و ۴۰۰ فقط هزینه را دو برابر
 *   می‌کرد. اندازه‌گیری روی کاربرِ ۲۰٬۰۰۰ تراکنشی: ۲۰۰ ردیف →
 *   ۶۲۸ ردیفِ خوانده‌شده و ۰٫۶۸ms، ۴۰۰ ردیف → ۱۲۲۸ و ۰٫۸۴ms.
 */
const CATEGORY_USE_SCAN = 200;

/** بیشترین تعداد چیپِ شبکه‌ی دسته‌بندی — تنها مرجع (سرور و مرورگر). */
const CATEGORY_GRID_MAX = 8;

/**
 * «چند بار از هر دسته استفاده کرده‌ای» — در پنجره‌ی اخیر.
 *
 * @return array<int,int> شناسه‌ی دسته → تعداد
 */
function categoryUseCounts(int $userId): array
{
    try {
        $st = Database::getConnection()->prepare(
            'SELECT r.category_id AS cid, COUNT(*) AS c
             FROM (
                 SELECT category_id
                 FROM transactions
                 WHERE user_id = :u AND category_id IS NOT NULL
                 ORDER BY id DESC
                 LIMIT ' . CATEGORY_USE_SCAN . '
             ) r
             GROUP BY r.category_id'
        );
        $st->execute(['u' => $userId]);
    } catch (PDOException $e) {
        return [];
    }

    $out = [];
    foreach ($st->fetchAll() as $r) { $out[(int)$r['cid']] = (int)$r['c']; }
    return $out;
}

/**
 * ⛔ تنها جایی که ترتیبِ انتخابگرِ دسته‌بندی تعریف می‌شود.
 *
 * هم شبکه‌ی چیپ‌ها از این رد می‌شود هم خودِ `<select>` — و همین کلِ
 * نکته است: با دو ترتیبِ متفاوت، چیپِ سومِ بالا با گزینه‌ی سومِ منو یکی
 * نمی‌بود و کاربر هر بار باید کلِ فهرست را می‌خواند.
 *
 * ⚠ مرتب‌سازی **پایدار** لازم است (PHP ≥ ۸٫۰ تضمینش می‌کند): دسته‌های
 *   بی‌استفاده باید ترتیبِ الفباییِ `cachedCategories()` را نگه دارند،
 *   وگرنه فهرست بین دو بارگذاری بی‌دلیل جابه‌جا می‌شد.
 */
function categoriesByUse(array $cats, array $useCounts): array
{
    usort($cats, static function ($a, $b) use ($useCounts) {
        return ($useCounts[(int)$b['id']] ?? 0) <=> ($useCounts[(int)$a['id']] ?? 0);
    });
    return $cats;
}

/**
 * ⛔ تنها جای «عنوانِ خالی یعنی چه» — سرور، نه مرورگر.
 *
 * فیلدِ عنوان از `required` درآمد (پرسشی که کاربر در لحظه‌ی ثبت
 * جوابش را ندارد و فقط جلوی ثبت را می‌گیرد)، ولی ستون در گزارش‌ها و
 * فهرست‌ها نمایش داده می‌شود و ردیفِ بی‌عنوان یک خطِ خالی است. پس
 * نامِ دسته جایش را می‌گیرد — همان چیزی که کاربر خودش می‌نوشت.
 *
 * ⚠ **بعد از** `txResolveCategory()` صدا زده می‌شود، وگرنه نامِ دسته‌ی
 *   کاربرِ دیگری می‌توانست داخلِ عنوانِ این کاربر بنشیند.
 */
function fallbackTxTitle(int $userId, ?int $categoryId, string $type): string
{
    if ($categoryId !== null) {
        try {
            // ⚠ `categoryScopeSql()` اینجا **افزونه** است (فراخواننده از
            //   `txResolveCategory()` رد شده)، ولی قاعده ۶ استثنا ندارد و
            //   درست هم هست: اولین مسیری که فردا این تابع را بدونِ آن
            //   سنجش صدا بزند، نامِ دسته‌ی کاربرِ دیگری را داخلِ عنوانِ
            //   این کاربر می‌نشاند. **همین را خودِ قاعده ۶ گرفت**، نه
            //   بازبینیِ چشمی.
            $st = Database::getConnection()->prepare(
                'SELECT name FROM categories WHERE id = :id AND ' . categoryScopeSql()
            );
            $st->execute(['id' => $categoryId] + categoryScopeParams($userId));
            $name = (string)$st->fetchColumn();
            if ($name !== '') { return $name; }
        } catch (PDOException $e) {
            // پایین می‌افتد به برچسبِ عمومی
        }
    }

    return $type === 'income' ? 'درآمد' : 'هزینه';
}

/**
 * آدرس‌های یک دسته فایل ثابت (CSS یا JS).
 *
 * مرورگر خودِ فایل را مستقیم از وب‌سرور می‌گیرد و nginx گزیپ و کش
 * یک‌ساله را می‌دهد. **هیچ فایل ثابتی از PHP رد نمی‌شود** و این عمدی
 * است: یک بار روی نسخه‌ای که همه را از یک اسکریپت PHP تحویل می‌داد،
 * هر بارگذاری صفحه چهار پروسه‌ی PHP-FPM می‌گرفت (HTML + CSS + JS +
 * chart.js) از pool ای که فقط چند پروسه دارد، و سایت وسط کار قفل
 * می‌کرد. اگر روزی وسوسه شدید دوباره از PHP تحویلشان بدهید، همین.
 *
 * `?v=` از زمان تغییر فایل ساخته می‌شود، پس کش یک‌ساله امن است.
 */
/**
 * ⛔ آدرسِ آیکون هم باید `?v=` بگیرد — وگرنه آیکونِ تازه هرگز نمی‌رسد.
 *
 * سایتِ nginx به همه‌ی `.png` کشِ «یک سال، immutable» می‌دهد. برای
 * فایلی که آدرسش نسخه دارد درست است، ولی آیکون‌ها با آدرسِ ثابت صدا
 * زده می‌شدند: با عوض کردنِ فایل، مرورگرِ کسی که یک بار سایت را باز
 * کرده تا **یک سال** همان آیکونِ قدیمی را نشان می‌داد — بی‌هیچ خطایی،
 * و بدونِ اینکه تازه‌سازیِ صفحه کاری بکند. `immutable` یعنی مرورگر
 * حتی درخواستِ شرطی هم نمی‌فرستد.
 */
function iconUrl(string $file): string
{
    return APP_BASE_PATH . '/assets/icons/' . $file . '?v=' . assetVersion(['icons/' . $file]);
}

/**
 * ⛔ صفحه‌هایی که **با GET چیزی را عوض می‌کنند** — پیش‌گیری (prefetch)
 * هرگز سراغشان نمی‌رود. `notifications.php` همه را «خوانده» می‌کند و
 * `support.php` تیکتِ باز شده را؛ اگر مرورگر آن‌ها را فقط به‌خاطرِ نگه
 * داشتنِ نشانگر روی لینک بگیرد و کاربر بعد منصرف شود، اعلانی که هرگز
 * دیده نشده «خوانده» ثبت می‌شد. `logout.php` هم که روشن است.
 * قاعده ۶۸ هر صفحه‌ای را که `markRead(` دارد با همین فهرست می‌سنجد.
 */
const PREFETCH_SKIP = ['logout.php', 'notifications.php', 'support.php'];

/**
 * ⛔ سه زبانه‌ی نوارِ پایین — تنها صفحه‌هایی که **کامل از پیش آماده**
 * می‌شوند (prerender)، نه فقط گرفته. گزارشِ مالکِ نصب: «بیشتر» آنی باز
 * می‌شود (شیت است، ناوبری نیست) ولی خانه/تراکنش‌ها/گزارش حدود یک ثانیه.
 * اندازه‌گیری شد: روی گوشیِ کُند فقط کارِ خودِ مرورگر (تجزیه، استایل،
 * چیدمان، نمودار) ۲۵۰ تا ۵۰۰ms است و شبکه جدا — پیش‌گیریِ لحظه‌ی لمس
 * فقط ~۱۰۰ms جلو می‌افتاد. با prerender هر دو پیش از لمس تمام شده‌اند.
 *
 * ⛔ فقط همین سه، نه هر لینکی: هر prerender یک صفحه‌ی کامل در حافظه‌ی
 *    گوشی و یک درخواست روی سرور است. این سه مقصدِ همیشگیِ نوارِ پایین‌اند.
 * ⛔ و `app.js` بدنه‌اش را تا `prerenderingchange` اجرا نمی‌کند — بارگذاریِ
 *    آن بدنه **می‌نویسد** (صفِ پیامکِ بانک، اشتراکِ اعلان).
 */
const PRERENDER_TABS = ['index.php', 'transactions.php', 'dashboard.php'];

/*
 * ⛔ نمای دسکتاپ — تنها جای تصمیمِ «آیا این مرورگر پنجره‌ی بزرگ دارد؟».
 *
 * سرور عرضِ پنجره را نمی‌داند. اسکریپتِ درون‌خطیِ سرآیند (و صفحه‌ی ورود)
 * با همان `DESK_MIN_PX`ی که CSS می‌خواند یک کوکیِ نمایشی می‌گذارد یا
 * برمی‌دارد، و صفحه‌ی بعد از روی آن می‌فهمد.
 *
 * ⛔ چرا کوکی و نه «همیشه رندر کن و با CSS پنهان کن»: بلوک‌های دسکتاپِ
 *    خانه (خالص دارایی، پول قابل خرج، سررسیدها) چند کوئری‌اند. با رندرِ
 *    همیشگی، **گوشی** — که این‌ها را نمی‌بیند — هزینه‌شان را در هر
 *    بارگذاری می‌داد. خواسته‌ی مالکِ نصب این بود که گوشی دست نخورد.
 * ⚠ بدترین حالتش بی‌خطر است: اولین بارگذاری پس از پاک شدنِ کوکی، نمای
 *   گوشی را در پنجره‌ی بزرگ نشان می‌دهد و صفحه‌ی بعد درست است. و اگر
 *   کوکی هست ولی پنجره کوچک شده، همان بلوک‌ها با CSS پنهان‌اند.
 * ⚠ یک ترجیحِ نمایشی است نه ردیابی: فقط «۱» یا هیچ، `SameSite=Lax`، و
 *   هیچ‌جا خوانده نمی‌شود جز همین تابع.
 */
const DESK_COOKIE = 'daftar_desk';
const DESK_MIN_PX = 1100;

function deskView(): bool
{
    return ($_COOKIE[DESK_COOKIE] ?? '') === '1';
}

/** اسکریپتِ درون‌خطیِ کوکیِ نمای دسکتاپ — سرآیند و صفحه‌ی ورود هر دو همین را چاپ می‌کنند. */
function deskCookieScript(): string
{
    return "(function(){try{var d=window.matchMedia&&window.matchMedia('(min-width: " . DESK_MIN_PX . "px)').matches;"
         . "document.cookie='" . DESK_COOKIE . "='+(d?'1':'')+';path=/;SameSite=Lax;max-age='+(d?31536000:0);}catch(e){}})();";
}

/**
 * قاعده‌ی «پیش‌گیریِ صفحه‌ی بعدی» (Speculation Rules) — تنها جای این تصمیم.
 *
 * مرورگر وقتی انگشت/نشانگر روی لینکِ داخلی می‌نشیند، همان لحظه صفحه‌ی
 * بعد را می‌گیرد؛ تا کاربر انگشت را بردارد (یا روی دسکتاپ کلیک کند)
 * یک رفت‌وبرگشتِ شبکه تمام شده است. اندازه‌گیری شد (کرومیوم، ۱۵۰ms
 * تأخیر): رسیدنِ صفحه از ~۱۶۵ به ~۶۵ میلی‌ثانیه.
 *
 * ⛔ برای لینک‌های دلخواه فقط prefetch؛ prerender فقط برای `PRERENDER_TABS`
 *    است و `app.js` بدنه‌اش را تا فعال شدنِ صفحه نگه می‌دارد — وگرنه
 *    صفحه‌ای که کاربر هرگز باز نکرد می‌توانست تراکنش بنویسد (صفِ پیامک).
 * ⛔ `moderate` نه `eager`: فقط وقتی کاربر واقعاً سراغِ لینک رفته
 *    (نشانگرِ ۲۰۰ms روی لینک یا لمس). با `eager` همه‌ی لینک‌های صفحه
 *    گرفته می‌شد — چند برابر بارِ سرور، و پاسخ‌هایی که تا پنج دقیقه
 *    کهنه می‌مانند. کهنگیِ همین پنجره‌ی کوتاه را هم `app.js` با
 *    `resetSpeculation()` بعد از هر نوشتنِ موفق می‌بندد.
 * ⚠ HTML همچنان `no-store` است؛ کشِ پیش‌گیری جدا از کشِ HTTP است و
 *    فقط برای **همان یک ناوبری** مصرف می‌شود (در کرومیوم ۱۴۱ سنجیده شد،
 *    حتی با سرویس‌ورکرِ فعال).
 */
function speculationRulesJson(string $currentScript = ''): string
{
    $b = APP_BASE_PATH;
    $not = [];
    foreach (PREFETCH_SKIP as $page) {
        $not[] = ['href_matches' => $b . '/' . $page . '*'];
        $not[] = ['href_matches' => $b . '/*/' . $page . '*'];
    }
    foreach (['api', 'download', 'uploads', 'assets'] as $dir) {
        $not[] = ['href_matches' => $b . '/' . $dir . '/*'];
    }
    // لینکی که `onclick` دارد (مثلاً `confirm()`) ممکن است لغو شود.
    $not[] = ['selector_matches' => '[download], [target], [onclick], [data-no-prefetch]'];

    $rules = ['prefetch' => [[
        'where' => ['and' => [
            ['href_matches' => $b . '/*'],
            ['not' => ['or' => $not]],
        ]],
        'eagerness' => 'moderate',
    ]]];

    // زبانه‌ای که همین حالا باز است دوباره آماده نمی‌شود.
    $tabs = [];
    foreach (PRERENDER_TABS as $tab) {
        if ($currentScript !== $b . '/' . $tab) { $tabs[] = $b . '/' . $tab; }
    }
    // ⚠ فقط **اولی** اینجا؛ بقیه را `app.js` با فاصله اضافه می‌کند
    //   (`speculationNextTabs()`). دو prerenderِ هم‌زمانِ همین صفحه‌ها در
    //   کرومیوم ۱۴۱ دومی را با `PrerenderFailedDuringPrefetch` لغو کرد —
    //   هر کدام تنها، و با صفحه‌های سبک هر دو با هم، موفق بودند.
    if ($tabs !== []) {
        $rules['prerender'] = [['source' => 'list', 'urls' => [$tabs[0]], 'eagerness' => 'immediate']];
    }
    return json_encode($rules, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

/** زبانه‌هایی که بعد از اولی، یکی‌یکی آماده می‌شوند — برای `data-next`ِ همان اسکریپت. */
function speculationNextTabs(string $currentScript = ''): string
{
    $tabs = [];
    foreach (PRERENDER_TABS as $tab) {
        if ($currentScript !== APP_BASE_PATH . '/' . $tab) { $tabs[] = APP_BASE_PATH . '/' . $tab; }
    }
    return implode(' ', array_slice($tabs, 1));
}

function assetUrls(array $relativePaths): array
{
    $ver  = assetVersion($relativePaths);

    $urls = [];
    foreach ($relativePaths as $rel) {
        $urls[] = APP_BASE_PATH . '/assets/' . $rel . '?v=' . $ver;
    }

    return $urls;
}

function assetVersion(array $relativePaths): string
{
    static $cache = [];
    $key = implode('|', $relativePaths);
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $base = __DIR__ . '/../assets/';
    $latest = 0;
    foreach ($relativePaths as $rel) {
        $full = $base . $rel;
        $mtime = @filemtime($full);
        if ($mtime !== false && $mtime > $latest) {
            $latest = (int)$mtime;
        }
    }

    // اگر هیچ زمانی خوانده نشد، ثابت بماند تا کش مرورگر نشکند
    $cache[$key] = $latest > 0 ? (string)$latest : 'v1';
    return $cache[$key];
}

function categoryIconMap(): array
{
    // آیکن‌های خطی یکدست (stroke) — نه ایموجی، تا ظاهر حرفه‌ای و یکنواخت بماند
    return [
        'food'      => ['label' => 'غذا و خوراک',     'path' => '<path d="M4 3v8a3 3 0 003 3v7M7 3v6M10 3v6M17 3c-1.5 2-2 4-2 6 0 2 .7 3 2 3h2V3h-2z"/><path d="M19 12v9"/>'],
        'transport' => ['label' => 'حمل و نقل',        'path' => '<path d="M5 17h14M6 17V9l1.6-4h8.8L18 9v8"/><circle cx="8" cy="17.5" r="1.6"/><circle cx="16" cy="17.5" r="1.6"/><path d="M6 12h12"/>'],
        'shopping'  => ['label' => 'خرید',              'path' => '<path d="M4 7h16l-1.3 12.2A2 2 0 0116.7 21H7.3a2 2 0 01-2-1.8L4 7z"/><path d="M9 10V6a3 3 0 016 0v4"/>'],
        'home'      => ['label' => 'خانه و اجاره',      'path' => '<path d="M3 10.5L12 3l9 7.5"/><path d="M5.5 9.5V20h13V9.5"/><path d="M10 20v-6h4v6"/>'],
        'health'    => ['label' => 'سلامت',             'path' => '<path d="M12 5.5C10.5 3 7 3 5.4 5A4.6 4.6 0 005 10.5L12 19l7-8.5a4.6 4.6 0 00-.4-5.5C17 3 13.5 3 12 5.5z"/>'],
        'bill'      => ['label' => 'قبوض',              'path' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3z"/><path d="M9.5 8h5M9.5 12h5"/>'],
        'phone'     => ['label' => 'موبایل و اینترنت',  'path' => '<rect x="6.5" y="2.5" width="11" height="19" rx="2.5"/><path d="M10.5 18.5h3"/>'],
        'education' => ['label' => 'آموزش',             'path' => '<path d="M12 4L2.5 9 12 14l9.5-5L12 4z"/><path d="M6.5 11.3V16c0 1.4 2.5 2.6 5.5 2.6s5.5-1.2 5.5-2.6v-4.7"/>'],
        'fun'       => ['label' => 'تفریح و سفر',       'path' => '<path d="M2.5 12.5l19-7-7 19-2.6-7.4-9.4-4.6z"/>'],
        'clothes'   => ['label' => 'پوشاک',             'path' => '<path d="M9 3l3 2 3-2 5 3-2.2 3.4-1.8-1V21H8V8.4l-1.8 1L4 6l5-3z"/>'],
        // ⚠ برای «بیمه» اضافه شد. وسوسه این بود که `health` را قرض
        //   بدهیم، ولی بیمه‌ی ثالث و بدنه ربطی به سلامت ندارد و دو
        //   دسته‌ی متفاوت با یک آیکون در فهرست از هم تشخیص داده
        //   نمی‌شوند. سپر معنیِ درستِ هر چهار نوعِ بیمه است.
        'shield'    => ['label' => 'بیمه',              'path' => '<path d="M12 2.8l7.5 2.7v6c0 4.6-3.1 8.4-7.5 9.7-4.4-1.3-7.5-5.1-7.5-9.7v-6L12 2.8z"/>'],
        'gift'      => ['label' => 'هدیه',              'path' => '<rect x="3.5" y="9" width="17" height="4"/><path d="M5 13v8h14v-8"/><path d="M12 9v12"/><path d="M12 9S10 3 7.5 4.5 12 9 12 9zM12 9s2-6 4.5-4.5S12 9 12 9z"/>'],
        'repair'    => ['label' => 'تعمیر و خدمات',     'path' => '<path d="M14.5 6.5a3.8 3.8 0 005 5l-8 8a2.5 2.5 0 01-3.5-3.5l8-8z"/><path d="M14.5 6.5L11 3a3.8 3.8 0 00-5 5l3.5 3.5"/>'],
        'car'       => ['label' => 'خودرو',             'path' => '<path d="M5 17h14M6 17V9l1.6-4h8.8L18 9v8"/><circle cx="8" cy="17.5" r="1.6"/><circle cx="16" cy="17.5" r="1.6"/>'],
        'pet'       => ['label' => 'حیوان خانگی',       'path' => '<ellipse cx="12" cy="16" rx="4" ry="3.2"/><circle cx="6.5" cy="10" r="2"/><circle cx="17.5" cy="10" r="2"/><circle cx="9.5" cy="6" r="1.9"/><circle cx="14.5" cy="6" r="1.9"/>'],
        'sport'     => ['label' => 'ورزش',              'path' => '<circle cx="12" cy="12" r="9"/><path d="M12 3v18M3 12h18" opacity=".55"/>'],
        'coffee'    => ['label' => 'کافه',              'path' => '<path d="M4 8h13v6a4 4 0 01-4 4H8a4 4 0 01-4-4V8z"/><path d="M17 9.5h1.8a2.2 2.2 0 010 4.4H17"/><path d="M4 21h13"/>'],
        'salary'    => ['label' => 'حقوق',              'path' => '<rect x="2.5" y="6.5" width="19" height="12" rx="2"/><circle cx="12" cy="12.5" r="2.6"/><path d="M6 10v5M18 10v5"/>'],
        'profit'    => ['label' => 'سود و سرمایه',      'path' => '<path d="M3.5 16.5l5-5 3.5 3.5 7-7.5"/><path d="M15 7.5h4.5V12"/>'],
        'sale'      => ['label' => 'فروش',              'path' => '<path d="M11.5 2.5H20a1.5 1.5 0 011.5 1.5v8.5L12 21.5 2.5 12 11.5 2.5z"/><circle cx="17" cy="7" r="1.4"/>'],
        'money'     => ['label' => 'درآمد عمومی',       'path' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v10M14.8 9.4c0-1.2-1.2-1.9-2.8-1.9s-2.8.7-2.8 2 1 1.7 2.8 2.2 3 .9 3 2.3-1.4 2-3 2-3-.7-3-2"/>'],
        'default'   => ['label' => 'سایر',              'path' => '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="2.6"/>'],
    ];
}

/**
 * آیکن دسته‌بندی را به‌صورت SVG خطی برمی‌گرداند.
 */
function categoryIconSvg(?string $key, int $size = 20): string
{
    $map = categoryIconMap();
    $item = $map[$key ?? 'default'] ?? $map['default'];

    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" '
         . 'stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" '
         . 'aria-hidden="true">' . $item['path'] . '</svg>';
}

/**
 * پالت‌های رنگِ برنامه (پروفایل → «نمایش»). کلید همان مقدارِ
 * `data-palette` روی `<html>` است و CSSِ هر پالت در بخشِ ۴۱ِ
 * `style.css` نشسته.
 *
 * ⛔ تنها مرجعِ فهرست همین است: انتخابگرِ پروفایل از آن رندر می‌شود،
 *    و `includes/header.php` الگوی مجاز را از روی همین کلیدها می‌سازد.
 *    با فهرستِ دوم، پالتی که در یکی هست و در دیگری نه، یا در انتخابگر
 *    دیده می‌شد و هنگامِ بارگذاری بی‌صدا به زمرد برمی‌گشت، یا برعکس.
 * ⛔ کلیدِ اول (`emerald`) پیش‌فرض است و **هیچ ویژگی‌ای** نمی‌گیرد.
 * ⚠ انتخاب در `localStorage` همان دستگاه می‌ماند (`daftar_palette`)، مثل
 *   حالت شب (`daftar_theme`): یک **نگاه** است نه داده، و بدونِ کوئری
 *   پیش از رندرِ صفحه اعمال می‌شود — پس هیچ چشمکِ رنگِ پیش‌فرض نیست.
 */
const UI_PALETTES = [
    'indigo'   => 'نیلی',
    'emerald'  => 'زمرد',
    'ocean'    => 'اقیانوس',
    'sunset'   => 'غروب',
    'lilac'    => 'یاس',
    'graphite' => 'شب',
];

/**
 * ⛔ قلم‌های صفحه‌ی خانه که کاربر می‌تواند خاموش کند — **تنها مرجع**.
 *
 * هم کارتِ «صفحه‌ی خانه» در پروفایل از همین رندر می‌شود، هم
 * `api/save_home_widgets.php` ورودی را با همین می‌سنجد، هم `index.php`
 * و `financialHighlights()` از همین می‌پرسند. با فهرستِ دوم، کلیدی که
 * کاربر می‌بیند هنگامِ ذخیره بی‌صدا دور ریخته می‌شد (درسِ
 * `Auth::SESSION_WINDOWS`).
 *
 * ⛔ پیش‌فرضِ همه **روشن** است و ستونِ `users.home_hidden` فقط
 *    خاموش‌ها را نگه می‌دارد — پس قلمِ تازه‌ی فردا برای همه روشن می‌آید.
 *
 * `group` فقط برای چیدمانِ کارتِ تنظیمات است (`HOME_WIDGET_GROUPS`).
 */
const HOME_WIDGETS = [
    'date'    => ['group' => 'top',     'label' => 'تاریخِ امروز',
                  'hint'  => 'خطِ «پنج‌شنبه ۲ مهر ۱۴۰۵» بالای صفحه'],
    'meter'   => ['group' => 'month',   'label' => 'درصدِ خرج از دریافتی',
                  'hint'  => 'نوارِ «۸۱٪ از دریافتیِ این ماه خرج شده»'],
    'split'   => ['group' => 'month',   'label' => 'دریافتی و پرداختی',
                  'hint'  => 'دو عددِ زیرِ مانده‌ی ماه'],
    'compare' => ['group' => 'month',   'label' => 'مقایسه با ماهِ قبل',
                  'hint'  => '«۵۲٪ کمتر از همین روزِ شهریور خرج کرده‌اید»'],
    'overdue' => ['group' => 'insight', 'label' => 'سررسیدِ گذشته',
                  'hint'  => '«سررسیدِ گذشته دارید: … تومان»'],
    'budget'  => ['group' => 'insight', 'label' => 'بودجه‌ی ردشده',
                  'hint'  => '«بودجه‌ی خوراکی رد شده — ۱۲۰٪ مصرف شده»'],
    'growth'  => ['group' => 'insight', 'label' => 'رشدِ خرجِ یک دسته',
                  'hint'  => '«این ماه ۱۰۰٪ بیشتر از ماه قبل خرجِ … شده»'],
];

const HOME_WIDGET_GROUPS = [
    'top'     => 'بالای صفحه',
    'month'   => 'کارتِ «مانده این ماه»',
    'insight' => 'جمله‌های زیرِ کارت',
];

/**
 * رشته‌ی ذخیره‌شده → فهرستِ کلیدهای خاموش. تابعِ خالص است.
 *
 * ⚠ کلیدِ ناشناخته دور ریخته می‌شود و ترتیب همان ترتیبِ `HOME_WIDGETS`
 *   است — پس یک مقدارِ دست‌کاری‌شده یا کلیدی که روزی حذف شده، هیچ
 *   چیزی را نمی‌شکند.
 *
 * @return string[]
 */
function homeHiddenParse(?string $raw): array
{
    if ($raw === null || trim($raw) === '') { return []; }
    $want = array_flip(array_map('trim', explode(',', $raw)));
    return array_values(array_filter(array_keys(HOME_WIDGETS), fn($k) => isset($want[$k])));
}

/**
 * قلم‌های خاموشِ خانه برای این کاربر.
 *
 * ⚠ ستون نیامده (migration اجرا نشده) یعنی «همه روشن» — همان رفتارِ
 *   پیش از این قابلیت، نه یک صفحه‌ی شکسته.
 * ⚠ در همان درخواست کش می‌شود و `saveHomeHidden()` کش را هم‌زمان
 *   به‌روز می‌کند، پس مقدارِ کهنه ممکن نیست (برخلافِ درسِ قاعده ۲۹:
 *   اینجا تنها نویسنده همان تابعِ کنارِ کش است).
 *
 * @return string[]
 */
function homeHiddenWidgets(int $userId, bool $forget = false): array
{
    static $cache = [];
    if ($forget) { unset($cache[$userId]); return []; }
    if (isset($cache[$userId])) { return $cache[$userId]; }
    if ($userId <= 0 || !tableHasColumn('users', 'home_hidden')) { return []; }

    try {
        $st = Database::getConnection()->prepare('SELECT home_hidden FROM users WHERE id = :u');
        $st->execute(['u' => $userId]);
        $raw = $st->fetchColumn();
    } catch (PDOException $e) {
        return [];   // ⚠ کارِ جانبی است و هرگز نباید صفحه‌ی خانه را بشکند
    }
    return $cache[$userId] = homeHiddenParse($raw === false ? null : $raw);
}

function homeWidgetOn(array $hidden, string $key): bool
{
    return !in_array($key, $hidden, true);
}

/**
 * ⛔ تنها مسیرِ نوشتنِ `users.home_hidden`. فهرستِ خالی `NULL` می‌نویسد
 *    (یعنی «همه روشن»)، نه رشته‌ی خالی — یک معنا، یک شکل.
 */
function saveHomeHidden(int $userId, array $hidden): bool
{
    if ($userId <= 0 || !tableHasColumn('users', 'home_hidden')) { return false; }
    $clean = homeHiddenParse(implode(',', array_map('strval', $hidden)));
    Database::getConnection()
        ->prepare('UPDATE users SET home_hidden = :h WHERE id = :u')
        ->execute(['h' => $clean ? implode(',', $clean) : null, 'u' => $userId]);
    homeHiddenWidgets($userId, true);
    return true;
}

/**
 * ستون‌های «سودِ فروشگاه» برای کوئری‌هایی که `renderTransactionRow()` را پر
 * می‌کنند — یا رشته‌ی خالی روی نصبی که `migration_store_share_edit` ندارد.
 *
 * ⛔ یک جا، نه چهار نسخه: هر صفحه‌ای که ردیفِ تراکنش می‌کشد (خانه، فهرست،
 *    جست‌وجو، جزئیاتِ روز) باید همین را بخواند، وگرنه نشانِ «از فروشگاه» و
 *    دکمه‌ی «برگرداندن» در یکی هست و در دیگری نه.
 */
function txStoreShareCols(string $alias = 't'): string
{
    if (!tableHasColumn('transactions', 'store_share_edited')) { return ''; }
    return ", {$alias}.store_share_ref, {$alias}.store_share_origin, {$alias}.store_share_edited";
}

/**
 * ⛔ نشانِ «از سهامِ فروشگاه» — لوگوی استوک‌لند (SL)، بازکشیده به‌صورتِ برداری
 *    و روی همان دایره‌ی آیکنِ دسته. **خواسته‌ی مالکِ نصب:** «برای حساب‌هایی که
 *    از حسابداری میاد این لوگو بخوره که معلوم باشه این معامله حسابداری فروشگاه
 *    هست.» یک منبع برای هر سه جا (ردیف، سطرِ «منبع»، پنجره‌ی ویرایش).
 *    ⚠ متنش «سهامِ فروشگاه» است نه «حسابداری فروشگاه» (`test_business_mode`).
 */
function storeShareMarkSvg(int $size = 22): string
{
    return '<svg class="store-mark-svg" width="' . $size . '" height="' . $size . '" viewBox="124 124 290 256" aria-hidden="true" focusable="false"><g fill="currentColor">'
         . '<path d="M333 137L301 168H208C196 168 190 176 190 188V204C190 212 193 217 199 222L248 266H203L163 231C156 225 152 216 152 206V182C152 156 172 137 205 137Z"/>'
         . '<path d="M229 225H265C287 237 305 252 305 276V316C305 324 300 330 293 334L247 366H137L174 329H253C266 329 273 320 273 306V280C273 262 256 247 229 225Z"/>'
         . '<path d="M299 338H402L374 366H262Z"/></g></svg>';
}

function renderTransactionRow(array $tx): void
{
    $icon  = categoryIconSvg($tx['cat_icon'] ?? null);
    $color = !empty($tx['cat_color']) ? $tx['cat_color'] : '#64748b';
    $__ss  = !empty($tx['store_share_ref']) && array_key_exists('store_share_edited', $tx);
    ?>
    <div class="tx-row<?= $__ss ? ' is-store-share' : '' ?>" data-id="<?= (int)$tx['id'] ?>">
        <div class="tx-row-summary">
            <span class="tx-row-icon-wrap">
                <?php if ($__ss): /* سودِ سهامِ فروشگاه: لوگو جای آیکنِ دسته */ ?>
                <span class="cat-icon cat-icon-store" title="سهامِ فروشگاه" role="img" aria-label="سهامِ فروشگاه"><?= storeShareMarkSvg(24) ?></span>
                <?php else: ?>
                <span class="cat-icon" style="--cat-bg: <?= h($color) ?>22; color: <?= h($color) ?>;"><?= $icon ?></span>
                <?php endif; ?>
                <span class="tx-row-texts">
                    <span class="tx-row-title"><?= h($tx['title']) ?></span>
                    <span class="tx-row-cat"><?= $__ss ? '<span class="tx-store-tag">سهامِ فروشگاه</span> · ' : '' ?><?= $tx['category_name'] ? h($tx['category_name']) : 'بدون دسته‌بندی' ?></span>
                </span>
            </span>
            <span class="tx-row-amount amount-<?= h($tx['type']) ?>"><?= ($tx['type'] === 'expense' ? '−' : '+') ?><?= formatMoney($tx['amount']) ?></span>
            <span class="tx-row-chevron">▾</span>
        </div>
        <div class="tx-row-details">
            <div class="tx-row-details-line"><span>نوع</span><span class="type-tag type-tag-<?= h($tx['type']) ?>"><?= typeLabel($tx['type']) ?></span></div>
            <div class="tx-row-details-line"><span>تاریخ</span><span><?= toJalali($tx['transaction_date']) ?></span></div>
            <?php if (isset($tx['created_at'])): ?>
                <div class="tx-row-details-line"><span>زمان ثبت</span><span><?= toPersianDigits(date('H:i', strtotime($tx['created_at']))) ?></span></div>
            <?php endif; ?>
            <?php if (!empty($tx['note'])): ?>
                <div class="tx-row-details-line"><span>توضیح</span><span><?= h($tx['note']) ?></span></div>
            <?php endif; ?>
            <?php
            // ⛔ سودِ سهامِ فروشگاه: اصلاح‌پذیر، و عددِ فروشگاه کنارِ عددِ
            //    اصلاح‌شده (`migration_store_share_edit`).
            $__ssEdit  = $__ss && (int)$tx['store_share_edited'] === 1;
            $__ssGone  = $__ss && $tx['store_share_origin'] === null;
            ?>
            <?php if ($__ss): ?>
                <?php /* ⚠ «سهامِ فروشگاه» نه «حسابداری فروشگاه»: صفحه‌های شخصی
                         نباید هیچ نشانی از محیطِ فروشگاه داشته باشند
                         (`test_business_mode`) و این سطر سودِ سهام است. */ ?>
                <div class="tx-row-details-line"><span>منبع</span><span class="tx-store-src">
                    <span class="store-mark-chip"><?= storeShareMarkSvg(14) ?></span> سهامِ فروشگاه
                    <?php if ($__ssEdit && $__ssGone): ?>
                        — اصلاح‌شده، و در فروشگاه دیگر نیست
                    <?php elseif ($__ssEdit): ?>
                        — اصلاح‌شده؛ عددِ فروشگاه
                        <span class="ltr-num"><?= ((int)$tx['store_share_origin'] < 0 ? '−' : '') . formatMoney(abs((int)$tx['store_share_origin'])) ?></span>
                    <?php endif; ?>
                </span></div>
            <?php endif; ?>
            <div class="tx-row-actions">
                <button type="button" class="btn btn-secondary btn-sm js-load-attachments" data-tx-id="<?= (int)$tx['id'] ?>">پیوست</button>
                <button type="button" class="btn btn-secondary btn-sm js-edit-tx"
                    data-id="<?= (int)$tx['id'] ?>"
                    data-type="<?= h($tx['type']) ?>"
                    data-amount="<?= (int)$tx['amount'] ?>"
                    data-title="<?= h($tx['title']) ?>"
                    data-note="<?= h($tx['note'] ?? '') ?>"
                    data-date="<?= h($tx['transaction_date']) ?>"
                    data-category-id="<?= (int)($tx['category_id'] ?? 0) ?>"
                    data-store="<?= $__ss ? '1' : '0' ?>">ویرایش</button>
                <?php if ($__ssEdit && !$__ssGone): ?>
                    <button type="button" class="btn btn-secondary btn-sm js-store-revert" data-id="<?= (int)$tx['id'] ?>">عددِ فروشگاه</button>
                <?php endif; ?>
                <?php // ⛔ سودی که فروشگاه هنوز دارد حذف نمی‌شود (برمی‌گشت) — `StoreShare::deleteBlock()` ?>
                <?php if (!$__ss || $__ssGone): ?>
                    <button class="delete-btn js-delete-tx" data-id="<?= (int)$tx['id'] ?>">حذف</button>
                <?php endif; ?>
            </div>

            <?php /* باکس پیوست هنگام کلیک با جاوااسکریپت ساخته می‌شود —
                     ساختن آن برای تک‌تک ردیف‌ها، حجم صفحه را بی‌دلیل چند برابر می‌کرد */ ?>
        </div>
    </div>
    <?php
}

/**
 * لیست تراکنش‌ها را بر اساس روز گروه‌بندی و رندر می‌کند،
 * با نمایش تاریخ شمسی و جمع خالص همان روز در سربرگ هر گروه.
 */
function renderTransactionsGrouped(array $transactions): void
{
    if (empty($transactions)) {
        return;
    }

    $groups = [];
    foreach ($transactions as $tx) {
        $groups[$tx['transaction_date']][] = $tx;
    }

    foreach ($groups as $date => $rows) {
        $dayNet = 0;
        foreach ($rows as $r) {
            $dayNet += ($r['type'] === 'income' ? (int)$r['amount'] : -(int)$r['amount']);
        }
        ?>
        <div class="tx-day-group">
            <div class="tx-day-header">
                <?php $__dl = txDayLabel($date); ?>
                <span class="tx-day-date"><?php if ($__dl['rel'] !== null): ?><span class="tx-day-rel"><?= $__dl['rel'] ?></span><?php endif; ?><?= $__dl['date'] ?></span>
                <span class="tx-day-total <?= $dayNet >= 0 ? 'amount-income' : 'amount-expense' ?>">
                    <?= $dayNet >= 0 ? '+' : '−' ?><?= formatMoney(abs($dayNet)) ?>
                </span>
            </div>
            <?php foreach ($rows as $tx) { renderTransactionRow($tx); } ?>
        </div>
        <?php
    }
}

/**
 * برچسبِ سرِ روز در فهرستِ تراکنش‌ها: «امروز · پنج‌شنبه ۲ مهر»،
 * «دیروز · …»، و برای بقیه «دوشنبه ۳۰ شهریور».
 *
 * ⚠ سال فقط وقتی می‌آید که با سالِ جاری فرق کند — «۱۴۰۵» روی هر ردیفِ
 *   امسال فقط عرض می‌گرفت. ⚠ «امروز» از `today()` می‌آید (منطقه‌ی زمانیِ
 *   اپ)، نه `date()` خام؛ همان درسِ پنجره‌ی بامدادی.
 * ⛔ هر دو قطعه متنِ ثابتِ خودمان‌اند (نامِ روز و ماه و رقم)، پس بی‌فرار
 *    چاپ می‌شوند — ولی هیچ ورودیِ کاربری به این تابع نمی‌رسد.
 *
 * @return array{rel:?string, date:string}
 */
function txDayLabel(string $gregorianDate): array
{
    $d     = substr($gregorianDate, 0, 10);
    $today = today();
    $rel   = null;
    if ($d === $today) {
        $rel = 'امروز';
    } elseif ($d === date('Y-m-d', strtotime($today . ' -1 day'))) {
        $rel = 'دیروز';
    }
    $long = jalaliLongDate($d);                       // «دوشنبه ۳۰ شهریور ۱۴۰۵»
    [$ty] = gregorianToJalali(...array_map('intval', explode('-', $today)));
    $year = ' ' . toPersianDigits((string)$ty);
    if (substr($long, -strlen($year)) === $year) {
        $long = substr($long, 0, -strlen($year));
    }
    return ['rel' => $rel, 'date' => $long];
}

function jalaliWithWeekday(string $gregorianDate): string
{
    $weekdays = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه'];
    $ts = strtotime($gregorianDate);
    $idx = ((int)date('w', $ts) + 1) % 7; // تبدیل یکشنبه‌محور به شنبه‌محور
    return $weekdays[$idx] . ' ' . toJalali($gregorianDate);
}
/**
 * تاریخِ بلندِ شمسی برای خواندن: «چهارشنبه ۲ مهر ۱۴۰۵».
 *
 * **گزارشِ مالکِ نصب:** «تاریخ روز به شمسی در صفحه اصلی هنوز اضافه
 * نشده». ⚠ روزِ هفته از همان `jalaliWithWeekday()` می‌آید، نه یک
 * فهرستِ دوم؛ و تبدیل از `gregorianToJalali()` — تقویمِ سومی ساخته نشد.
 */
function jalaliLongDate(string $gregorianDate): string
{
    $months = ['', 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
               'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    [$gy, $gm, $gd] = array_map('intval', explode('-', substr($gregorianDate, 0, 10)));
    [$jy, $jm, $jd] = gregorianToJalali($gy, $gm, $gd);
    $weekday = explode(' ', jalaliWithWeekday($gregorianDate))[0];
    return $weekday . ' ' . toPersianDigits((string)$jd) . ' ' . $months[$jm] . ' ' . toPersianDigits((string)$jy);
}

/**
 * بانک‌های پیش‌فرض ایران — فقط برای لیست «بانک طرف مقابل» (چک‌های دریافتی)
 * لیست «بانک‌های خودم» عمداً خالی می‌ماند تا شلوغ نشود؛ کاربر خودش اضافه می‌کند.
 */
function defaultExternalBanks(): array
{
    return ['ملی', 'ملت', 'صادرات', 'تجارت', 'سپه', 'کشاورزی', 'مسکن', 'رفاه',
            'پارسیان', 'پاسارگاد', 'سامان', 'اقتصاد نوین', 'سینا', 'شهر', 'دی',
            'آینده', 'گردشگری', 'قرض‌الحسنه مهر ایران', 'پست بانک', 'بانک صنعت و معدن'];
}

function defaultAssetTypes(): array
{
    return [
        // ⛔ `rate`: از همان ابتدا به نرخِ روز وصل (`includes/rates.php`) — ارزش به
        //    تومان خودش به‌روز می‌شود. نقره نرخِ خودکار ندارد.
        ['name' => 'دلار',          'unit' => 'دلار', 'rate' => 'usd'],
        ['name' => 'طلا (۱۸ عیار)', 'unit' => 'گرم',  'rate' => 'gold18'],
        ['name' => 'نقره',          'unit' => 'گرم',  'rate' => null],
        ['name' => 'سکه تمام',      'unit' => 'عدد',  'rate' => 'coin_emami'],
    ];
}

/**
 * اولین بار که کاربر وارد بخش چک یا دارایی می‌شود، مقادیر پیش‌فرض ساخته می‌شوند.
 *
 * ⛔ نشانه لازم است و حذف‌شدنی نیست: بدونِ آن، کاربری که بانک‌های
 *    پیش‌فرض را **عمداً** پاک کرده با بازدیدِ بعدی همه را پس می‌گیرد —
 *    همان استدلالِ `more_categories_seeded`.
 *
 * ⛔ ولی جایش `users.defaults_seeded_at` است، نه `app_settings`
 *    (`migration_seed_flag`). نسخه‌ی قبلی به‌ازای **هر کاربر** یک ردیف
 *    در جدولِ تنظیماتِ **نصب** می‌گذاشت، و چون آن جدول ستونِ `user_id`
 *    ندارد `deleteUserAccount()` هرگز پیدایش نمی‌کرد: کاربر حذف می‌شد و
 *    نشانه‌اش تا ابد می‌ماند.
 *
 * ⚠ اگر migration هنوز نیامده باشد، کارِ قبلی انجام می‌شود — نصبِ
 *   عقب‌مانده نباید بشکند، و نباید هم پیش‌فرض‌های پاک‌شده را پس بدهد.
 */
function seedUserDefaults(int $userId): void
{
    $pdo = Database::getConnection();

    $hasColumn = tableHasColumn('users', 'defaults_seeded_at');
    $flagKey   = 'seeded_user_' . $userId;

    if ($hasColumn) {
        $st = $pdo->prepare('SELECT defaults_seeded_at FROM users WHERE id = :id');
        $st->execute(['id' => $userId]);
        // ⚠ `false` یعنی کاربری نیست و `null` یعنی هنوز seed نشده — هر
        //   دو باید ادامه بدهند. فقط یک تاریخِ واقعی «انجام شده» است.
        if ((string)$st->fetchColumn() !== '') { return; }
    } elseif (getSetting($flagKey, '0') === '1') {
        return;
    }

    try {
        $bankStmt = $pdo->prepare('INSERT IGNORE INTO banks (user_id, scope, name) VALUES (:user_id, "external", :name)');
        foreach (defaultExternalBanks() as $bankName) {
            $bankStmt->execute(['user_id' => $userId, 'name' => $bankName]);
        }

        $withRate  = tableHasColumn('asset_types', 'rate_code');
        $assetStmt = $pdo->prepare($withRate
            ? 'INSERT IGNORE INTO asset_types (user_id, name, unit, rate_code) VALUES (:user_id, :name, :unit, :rc)'
            : 'INSERT IGNORE INTO asset_types (user_id, name, unit) VALUES (:user_id, :name, :unit)');
        foreach (defaultAssetTypes() as $at) {
            $assetStmt->execute(['user_id' => $userId, 'name' => $at['name'], 'unit' => $at['unit']]
                + ($withRate ? ['rc' => $at['rate'] ?? null] : []));
        }
        if ($withRate) {
            require_once __DIR__ . '/rates.php';
            Rates::applyToAssets($userId);
        }

        if ($hasColumn) {
            $pdo->prepare('UPDATE users SET defaults_seeded_at = NOW() WHERE id = :id')
                ->execute(['id' => $userId]);
        } else {
            setSetting($flagKey, '1');
        }
    } catch (PDOException $e) {
        Log::error('signup.seed_failed', $e, ['uid' => $userId]);
    }
}

/**
 * بررسی می‌کند آیا می‌توان یک ردیف مرجع (بانک، نوع دارایی، دسته‌بندی) را حذف کرد.
 * فقط وقتی هیچ رکوردی به آن وابسته نباشد قابل حذف است.
 */
function referenceInUseCount(string $table, string $column, int $id, int $userId): int
{
    $allowed = [
        'cheques'      => 'bank_id',
        'assets'       => 'asset_type_id',
        'transactions' => 'category_id',
    ];

    if (!isset($allowed[$table]) || $allowed[$table] !== $column) {
        return 1; // ناشناخته → محافظه‌کارانه، اجازه حذف نده
    }

    $stmt = Database::getConnection()->prepare(
        "SELECT COUNT(*) AS cnt FROM `$table` WHERE `$column` = :id AND user_id = :user_id"
    );
    $stmt->execute(['id' => $id, 'user_id' => $userId]);
    return (int)$stmt->fetch()['cnt'];
}

/**
 * تعدادِ دارایی/معامله تا ۴ رقمِ اعشار (گرمِ طلا، کسرِ سکه یا رمزارز).
 *
 * ⛔ همان `formatQty()` است با دقتِ بیشتر — تا مهر ۱۴۰۵ قالبِ خودش را داشت
 *    («۱,۲۵۰.۵» با جداکننده‌ی لاتین) در حالی که همان معامله در `trades.php`
 *    با `formatQty()` «۱۲۵۰٫۵» دیده می‌شد: یک عدد، دو شکل.
 */
function formatQuantity($qty): string
{
    return formatQty($qty, 4);
}

/**
 * آدرس پایه‌ی مطلق اپ — برای ساختن لینک‌هایی که در ایمیل می‌روند.
 *
 * ⚠️ چرا APP_URL بر Host مرورگر اولویت دارد:
 * سرآیند Host را خود درخواست‌کننده تعیین می‌کند. در بازیابی رمز این یک
 * حمله‌ی شناخته‌شده است: مهاجم برای حساب قربانی درخواست بازیابی می‌دهد
 * ولی Host را evil.com می‌گذارد؛ ایمیل با لینکِ evil.com به قربانی
 * می‌رسد و اگر رویش کلیک کند، توکن به دست مهاجم می‌افتد.
 *
 * پس اگر APP_URL در config تعریف شده باشد، همان ملاک است و Host
 * درخواست اصلاً خوانده نمی‌شود.
 */
function appBaseUrl(): string
{
    if (defined('APP_URL') && APP_URL !== '') {
        return rtrim(APP_URL, '/');
    }

    // برگشت به Host درخواست — فقط وقتی APP_URL تنظیم نشده باشد
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (defined('APP_FORCE_HTTPS') && APP_FORCE_HTTPS)
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');

    // حداقل پاکسازی: فقط کاراکترهای مجاز یک نام میزبان
    $host = preg_replace('/[^A-Za-z0-9\.\-:\[\]]/', '', $host);

    return ($https ? 'https://' : 'http://') . $host
        . (defined('APP_BASE_PATH') ? rtrim(APP_BASE_PATH, '/') : '');
}

/**
 * ⛔ آیا ترافیکِ کاربر پیش از رسیدن به این سرور از یک CDN رد می‌شود؟
 *
 * تنها جای این تصمیم همین‌جاست (مثل `categoryScopeSql()`)، و تنها
 * مصرف‌کننده‌اش `privacy.php` است. آن صفحه قرار است **هر ادعایش در کد
 * نشان‌دادنی باشد**، و بردنِ دامنه پشتِ کلادفلر یکی از ادعاهایش را
 * وارونه کرد: TLS روی لبه‌ی آن‌ها باز می‌شود، پس رمز و شماره کارتِ کاربر
 * از دیدشان رد می‌شود. یعنی «هیچ چیزی به سرویس بیرونی نمی‌رود» دیگر
 * راست نبود — و آن صفحه با یک `git pull` هم عوض نمی‌شد، چون جمله‌اش
 * ثابت بود.
 *
 * ⛔ **این تابع فقط می‌تواند «بله» بگوید، نه «نه».** ثابتِ `APP_BEHIND_CDN`
 *    روشنش می‌کند ولی هیچ‌وقت خاموشش نمی‌کند، و نبودنش هم چیزی را خاموش
 *    نمی‌کند. علتش نامتقارن بودنِ هزینه‌ی اشتباه است:
 *      - «CDN هست» گفتن وقتی نیست → افشای بیش از حد، بی‌ضرر.
 *      - «CDN نیست» گفتن وقتی هست → صفحه‌ی حریم خصوصی دروغ می‌گوید.
 *    پس نبودِ ثابت به سنجشِ سرآیند برمی‌گردد و یک `false`ِ صریح هم
 *    نمی‌تواند سنجشِ واقعی را ساکت کند.
 *
 * ⛔ **هیچ سرآیندِ آی‌پی‌ای خوانده نمی‌شود** — نه اینجا و نه هیچ‌جای دیگر
 *    (قاعده ۲۷). `CF-Ray` و `CDN-Loop` نشانی از هیچ‌کس نیستند و هیچ
 *    تصمیمِ اعتمادی رویشان سوار نمی‌شود؛ جعلشان فقط باعث می‌شود همان
 *    بازدیدکننده متنِ محافظه‌کارانه‌تری ببیند.
 */
function cdnInFront(): bool
{
    if (defined('APP_BEHIND_CDN') && APP_BEHIND_CDN) {
        return true;
    }
    foreach (['HTTP_CF_RAY', 'HTTP_CDN_LOOP'] as $key) {
        if (!empty($_SERVER[$key])) {
            return true;
        }
    }
    return false;
}

/**
 * ⛔ چه چیزی از این سرور بیرون می‌رود — از روی خودِ پیکربندی، نه از یک
 * جمله‌ی ثابت.
 *
 * هر قلم یک کلید دارد تا تست بتواند بسنجدش، و `privacy.php` همین را
 * فهرست می‌کند. فهرستِ خالی یعنی واقعاً هیچ چیزی بیرون نمی‌رود — و آن
 * جمله‌ی قدیمی فقط در همان حالت گفته می‌شود.
 */
function outboundDataFlows(): array
{
    $out = [];

    if (cdnInFront()) {
        $out[] = [
            'key'  => 'cdn',
            'text' => 'این سایت پشتِ یک شبکه‌ی توزیع محتوا (CDN) است تا از '
                    . 'داخل ایران باز شود. یعنی ارتباط رمزنگاری‌شده‌ی شما '
                    . 'اول روی سرورهای آن شبکه باز می‌شود و بعد به این '
                    . 'سرور می‌رسد — پس آنچه می‌فرستید، از جمله رمز و '
                    . 'شماره کارت، از دیدِ آن شبکه هم رد می‌شود.',
        ];
    }

    $mail = defined('MAIL_METHOD') ? (string)MAIL_METHOD : '';
    if ($mail === 'smtp') {
        $out[] = [
            'key'  => 'mail',
            'text' => 'ایمیل‌های برنامه (بازیابی رمز و یادآوری سررسید) از '
                    . 'یک سرور ایمیلِ بیرونی فرستاده می‌شوند که مدیرِ این '
                    . 'نصب تنظیمش کرده. آدرس ایمیل و متنِ همان یادآوری‌ها '
                    . 'از آنجا رد می‌شود.',
        ];
    } elseif ($mail !== '') {
        $out[] = [
            'key'  => 'mail',
            'text' => 'ایمیل‌های برنامه با سرویسِ ایمیلِ خودِ همین سرور '
                    . 'فرستاده می‌شوند.',
        ];
    }

    if (class_exists('Sms') && Sms::method() !== '' && Sms::method() !== 'log') {
        $out[] = [
            'key'  => 'sms',
            'text' => 'اگر با کد پیامکی وارد شوید، شماره‌ی موبایل و همان کد '
                    . 'به پنلِ پیامکی می‌رود که مدیرِ این نصب انتخاب کرده. '
                    . 'هیچ چیزِ دیگری از دفترِ شما آنجا نمی‌رود.',
        ];
    }

    // ⚠ مصرف‌کننده باید `includes/push.php` را لود کند (همان قاعده‌ی Sms).
    if (class_exists('Push') && Push::available()) {
        $out[] = [
            'key'  => 'push',
            'text' => 'اگر «اعلان روی گوشی» را روشن کنید، متنِ اعلان از راهِ سرویسِ '
                    . 'اعلانِ خودِ مرورگر یا گوشی‌تان (گوگل، اپل یا موزیلا) به گوشی '
                    . 'می‌رسد. متن پیش از رفتن رمزنگاری می‌شود و آن سرویس آن را '
                    . 'نمی‌بیند؛ فقط می‌داند یک اعلان به این دستگاه رفته.',
        ];
    }

    return $out;
}

/**
 * آیا ستون email روی جدول users هست؟
 *
 * با migration_password_reset اضافه شده. نصب‌هایی که هنوز migration را
 * اجرا نکرده‌اند باید بدون خطا کار کنند، پس همه جا قبل از دست زدن به
 * ایمیل این را می‌پرسیم. جواب از نقشه‌ی schemaMap() می‌آید که یک بار در
 * هر درخواست خوانده می‌شود.
 */
function usersHaveColumn(PDO $pdo, string $column): bool
{
    return tableHasColumn('users', $column);
}

function usersHaveEmailColumn(PDO $pdo): bool
{
    return usersHaveColumn($pdo, 'email');
}

/**
 * چند مدیرِ فعالِ **دیگر** وجود دارد؟
 *
 * ⛔ قاعده‌ی دامنه است نه جزئیاتِ یک صفحه: نصب هرگز نباید بی‌مدیر
 *    بماند، وگرنه تنها راهِ برگشت خط فرمان است. پیش از این فقط داخل
 *    `admin/users.php` تعریف شده بود و `api/delete_account.php`
 *    نمی‌توانست از آن استفاده کند — یعنی همان قاعده باید بار دوم
 *    نوشته می‌شد و دو نسخه‌اش دیر یا زود از هم دور می‌افتادند.
 */
function countOtherActiveAdmins(PDO $pdo, int $excludeUserId): int
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS cnt FROM users
         WHERE role = "admin" AND is_active = 1 AND id != :id'
    );
    $stmt->execute(['id' => $excludeUserId]);
    return (int)$stmt->fetch()['cnt'];
}

/**
 * ⛔ تنها جایی که «دسترسیِ این کاربر باطل شود» تعریف می‌شود.
 *
 * رمز عوض شدن یعنی یکی از دو چیز: یا کاربر خودش خواسته، یا حسابش لو
 * رفته و دارد پسش می‌گیرد. در حالت دوم، عوض کردنِ رمز به‌تنهایی **کافی
 * نیست** — مهاجم ممکن است پیش از آن یک توکنِ API گرفته باشد، و توکنِ
 * API نه به رمز وابسته است نه به نشست: ۹۰ روز زنده می‌ماند و رمزِ تازه
 * هیچ اثری رویش ندارد. یعنی کاربر رمز را عوض می‌کند، خیالش راحت
 * می‌شود، و مهاجم همچنان داخل است — **بی‌هیچ نشانه‌ای**.
 *
 * سه چیز جدا باید باطل شوند و یادِ هر سه در یک جا می‌ماند:
 *   ۱. دستگاه‌های مورد اعتماد (کوکیِ مرورگر)
 *   ۲. توکن‌های api/v1 (اپ موبایل و هر مشتری غیرمرورگری)
 *   ۳. ⛔ نشست‌های وبِ **زنده** — با `users.access_revoked_at`. نشستِ PHP
 *      فقط یک فایل روی دیسک است و به هیچ چیزی در دیتابیس بند نیست؛ تا
 *      پیش از این، مرورگری که همان لحظه وارد بود با وجودِ تغییرِ رمز یا
 *      غیرفعال شدن تا ابد وارد می‌ماند. `Auth::isLoggedIn()` این مهر را
 *      با `login_time`ِ نشست می‌سنجد و نشستِ قدیمی‌تر را خالی می‌کند.
 *
 * ⚠ هر جایی که `users.password_hash` را می‌نویسد باید این را صدا بزند.
 *   قاعده ۱۴ در `test_api_contract.php` همین را می‌سنجد، وگرنه مسیرِ
 *   چهارمی که فردا اضافه شود بی‌صدا از قلم می‌افتد.
 *
 * ⚠ اگر کاربر **خودش** صدا می‌زند (تغییر رمز در پروفایل)، نشستِ جاری‌اش
 *   هم قدیمی‌تر از مهر است و تا یک دقیقه‌ی بعد بیرون می‌افتد. فراخواننده
 *   باید بعدش `Auth::renewCurrentSession()` را صدا بزند تا خودش نماند
 *   بیرون (`api/change_password.php` می‌زند).
 */
function revokeAllAccessFor(int $userId): void
{
    // ⚠ require **پیش از** هر دو فراخوانی، و داخل تابع نه بالای فایل:
    //   `api_auth.php` خودش `auth.php` را لازم دارد و بارگذاریِ
    //   همیشگی‌اش یک وابستگیِ حلقوی می‌ساخت.
    //
    //   ⛔ ترتیب اهمیت دارد و یک بار واقعاً شکست: نسخه‌ی اول اول
    //   `Auth::revokeAllDevices()` را صدا می‌زد و بعد require می‌کرد.
    //   `password_reset.php` تنها `db/mailer/functions` را لود می‌کند،
    //   پس آنجا کلاس `Auth` هنوز وجود نداشت و فراخوانی خطای کشنده
    //   می‌داد — که در همان فایل با `catch (Throwable)` بلعیده می‌شد.
    //   نتیجه: بازیابیِ رمز دیگر **هیچ** دسترسی‌ای را باطل نمی‌کرد،
    //   بی‌هیچ خطایی. تست `test_password_reset` گرفتش.
    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/api_auth.php';

    Auth::revokeAllDevices($userId);
    ApiAuth::revokeAllFor($userId);
    Audit::log('auth.access_revoked', 'user', $userId, [], null, $userId);

    // ستون با migration_access_revoke می‌آید؛ نصبِ عقب‌مانده نباید بشکند.
    if (tableHasColumn('users', 'access_revoked_at')) {
        try {
            Database::getConnection()
                ->prepare('UPDATE users SET access_revoked_at = NOW() WHERE id = :id')
                ->execute(['id' => $userId]);
        } catch (PDOException $e) { /* بقیه‌ی ابطال انجام شده؛ این یکی نباید مسیر را بشکند */ }
    }
}

/**
 * تنها مسیر نوشتن ایمیل کاربر. برمی‌گرداند: '' یعنی موفق، وگرنه متن خطا.
 *
 * ⚠️ رشته‌ی خالی یعنی «دست نزن»، نه «پاک کن».
 *
 * چرا این‌قدر مهم است: ایمیل حالا هم راه ورود است هم تنها راه بازیابی
 * رمز. یک بار پاک شدنش یعنی کاربر بی‌سروصدا از بازیابی محروم می‌شود و
 * تا وقتی رمزش را فراموش نکرده، هیچ‌کس نمی‌فهمد. فرم ویرایش کاربر در
 * پنل مدیر مقدار فعلی را با جاوااسکریپت پر می‌کند؛ اگر آن اسکریپت اجرا
 * نشود فیلد خالی می‌ماند و ذخیره‌ی ساده، ایمیل ثبت‌شده را پاک می‌کرد.
 * دقیقاً همین اتفاق افتاد. پاک کردن حالا باید صریح خواسته شود.
 *
 * این تابع سه قاعده را یک‌جا نگه می‌دارد تا در سه فایل تکرار نشوند:
 * وجود ستون، معتبر بودن آدرس، و یکتا بودنش بین کاربران.
 */
/**
 * ⛔ تنها مسیرِ نوشتنِ شماره موبایل — مثل `saveUserEmail()`.
 *
 * دو قاعده‌ی همان تابع اینجا هم هست و هر دو از یک باگِ واقعی آمده‌اند:
 *   - **رشته‌ی خالی یعنی «دست نزن»، نه «پاک کن».** پاک کردن فقط با
 *     `$allowClear` صریح. فرمی که مقدارِ فعلی را رندر می‌کند، اگر یک بار
 *     خالی برسد نباید شماره را بی‌صدا NULL کند.
 *   - **یکتایی سنجیده می‌شود و پیامِ روشن می‌دهد**، وگرنه کاربر فقط
 *     «خطای دیتابیس» می‌دید. `uniq_users_phone` در دیتابیس هم هست، ولی
 *     تکیه بر خطای آن یعنی پیامِ نامفهوم.
 *
 * نرمال‌سازی از `SmsLogin::normalizePhone()` می‌گذرد، نه از یک تبدیلِ
 * تازه — وگرنه شماره‌ای که اینجا ثبت می‌شود با شماره‌ای که در ورود
 * جست‌وجو می‌شود یکی نمی‌ماند و تطبیق **بی‌صدا** شکست می‌خورد.
 */
/**
 * ⛔ آیا این حساب اصلاً رمزی دارد؟ — تنها جای این پرسش.
 *
 * حسابی که با شماره موبایل ساخته شده `password_hash = NULL` دارد و این
 * **تنها** معنای «رمز ندارد» است (ستونِ بولینِ دومی وجود ندارد؛ دلیلش
 * در `migration_phone_signup.sql` نوشته شده).
 *
 * ⚠ هر جایی که بخواهد `password_verify()` صدا بزند باید اول از اینجا —
 *   یا از خودِ همان ردیف — نبودنِ رمز را بسنجد. آن تابع با `null` امروز
 *   خطای کشنده نمی‌دهد (فقط `Deprecated` و `false`) ولی در PHP 9 می‌دهد،
 *   و آن هشدار در هر تلاشِ ورود تکرار می‌شود.
 *
 * ⚠ خطای دیتابیس یا کاربرِ ناموجود «رمز دارد» جواب می‌گیرد، نه «ندارد».
 *   شکست باید به سمتِ سخت‌گیرانه بیفتد: با `false`، هر خرابیِ گذرا یک
 *   حسابِ رمزدار را «بی‌رمز» نشان می‌داد و مسیرهای سهل‌گیرانه باز می‌شدند.
 */
function userHasPassword(int $userId): bool
{
    try {
        $st = Database::getConnection()->prepare(
            'SELECT password_hash FROM users WHERE id = :i LIMIT 1'
        );
        $st->execute(['i' => $userId]);
        $row = $st->fetch();
    } catch (PDOException $e) {
        return true;
    }
    if (!$row) { return true; }
    return ($row['password_hash'] ?? '') !== '' && $row['password_hash'] !== null;
}

/**
 * شماره‌ی ثبت‌شده‌ی یک کاربر، یا `null`.
 *
 * ⚠ نصبی که هنوز `migration_sms_login` را نخورده ستونِ `phone` را ندارد؛
 *   آنجا `null` برمی‌گردد و قاعده‌ی «دست‌کم یک راهِ بازگشت» دقیقاً مثل
 *   قبل ایمیل را الزامی می‌کند.
 */
function userPhone(int $userId): ?string
{
    if (!tableHasColumn('users', 'phone')) { return null; }
    try {
        $st = Database::getConnection()->prepare('SELECT phone FROM users WHERE id = :i LIMIT 1');
        $st->execute(['i' => $userId]);
        $v = $st->fetchColumn();
    } catch (PDOException $e) {
        return null;
    }
    return ($v === false || $v === null || $v === '') ? null : (string)$v;
}

function saveUserPhone(PDO $pdo, int $userId, string $phone, bool $allowClear = false): string
{
    if (!tableHasColumn('users', 'phone')) {
        return 'ستون شماره موبایل هنوز ساخته نشده — migration را اجرا کنید (bash deploy/migrate.sh --apply).';
    }

    require_once __DIR__ . '/sms_login.php';
    $phone = trim($phone);

    if ($phone === '') {
        if (!$allowClear) { return ''; }
        $pdo->prepare('UPDATE users SET phone = NULL WHERE id = :id')->execute(['id' => $userId]);
        return '';
    }

    $norm = SmsLogin::normalizePhone($phone);
    if ($norm === null) {
        return 'شماره موبایل معتبر نیست (مثل ۰۹۱۲۳۴۵۶۷۸۹).';
    }

    $dup = $pdo->prepare('SELECT username FROM users WHERE phone = :p AND id <> :id');
    $dup->execute(['p' => $norm, 'id' => $userId]);
    if ($other = $dup->fetchColumn()) {
        return 'این شماره برای کاربر «' . $other . '» ثبت شده است.';
    }

    $pdo->prepare('UPDATE users SET phone = :p WHERE id = :id')
        ->execute(['p' => $norm, 'id' => $userId]);
    return '';
}

function saveUserEmail(PDO $pdo, int $userId, string $email, bool $allowClear = false): string
{
    if (!usersHaveEmailColumn($pdo)) {
        return 'ستون ایمیل هنوز ساخته نشده — migration را اجرا کنید (bash deploy/migrate.sh --apply).';
    }

    $email = trim($email);

    if ($email === '') {
        if (!$allowClear) { return ''; }   // دست‌نخورده می‌ماند
        $pdo->prepare('UPDATE users SET email = NULL WHERE id = :id')->execute(['id' => $userId]);
        return '';
    }

    if (mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'ایمیل معتبر نیست.';
    }

    $dup = $pdo->prepare('SELECT username FROM users WHERE email = :e AND id <> :id');
    $dup->execute(['e' => $email, 'id' => $userId]);
    if ($other = $dup->fetchColumn()) {
        return 'این ایمیل برای کاربر «' . $other . '» ثبت شده است.';
    }

    $pdo->prepare('UPDATE users SET email = :e WHERE id = :id')
        ->execute(['e' => $email, 'id' => $userId]);
    return '';
}

/* ============================================================
   بخش معاملات (خرید و فروش)
   ============================================================ */

/** آیا جدول‌های معاملات ساخته شده‌اند؟ (migration_trades) */
function tradesTablesExist(PDO $pdo): bool
{
    return tableExists('trades');
}

/**
 * آیا این کاربر بخش معاملات را می‌بیند؟
 *
 * ⛔ تنها مرجعِ «پیش‌فرض» خودِ ستون است (`users.trades_enabled`, پیش‌فرضِ
 *    ۱ از `migration_trades_default.sql`) — نه یک `?:` در این تابع و نه
 *    مقداری در `INSERT`ِ `createUserAccount()`. با نسخه‌ی دوم، عوض کردنِ
 *    پیش‌فرض یک جا اثر می‌کرد و جای دیگر نه.
 *
 * ⚠ نبودِ ستون `false` می‌دهد و این درست است: ستون را همان
 *   `migration_trades.sql` می‌سازد که جدول‌های `trades` را هم می‌سازد،
 *   پس بی‌ستون اصلاً بخشی برای دیدن وجود ندارد.
 */
function tradesEnabled(PDO $pdo, int $userId): bool
{
    static $cache = [];
    if (isset($cache[$userId])) { return $cache[$userId]; }
    if (!usersHaveColumn($pdo, 'trades_enabled')) { return $cache[$userId] = false; }
    $st = $pdo->prepare('SELECT trades_enabled FROM users WHERE id = :id');
    $st->execute(['id' => $userId]);
    return $cache[$userId] = ((int)$st->fetchColumn() === 1);
}

/**
 * مقدار اعشاری (تعداد/گرم) از ورودی کاربر — با ارقام فارسی و ممیز فارسی.
 * sanitizeAmount اینجا به کار نمی‌آید چون نقطه‌ی اعشار را هم می‌اندازد.
 */
function sanitizeQty($input, int $decimals = 3): float
{
    $clean = trim(toLatinDigits((string)$input));
    // ⛔ «1,000» یعنی هزار، نه یک: الگوی جداکننده‌ی هزارگان (۱ تا ۳ رقم، بعد
    //    گروه‌های سه‌رقمی) پیش از «ویرگول = ممیز» کنار گذاشته می‌شود. پیش از
    //    این «1,000» یک خوانده می‌شد و فقط در جمعِ زنده دیده می‌شد (بازرسیِ مهر ۱۴۰۵).
    if (preg_match('/^\d{1,3}(?:[,،]\d{3})+(?:[.٫]\d+)?$/u', $clean)) {
        $clean = str_replace([',', '،'], '', $clean);
    }
    // ⛔ «۲/۵» (نوشتنِ رایجِ اعشار در فارسی) دو و نیم است — همان که `qty()`ِ store.js
    //    و پیش‌نمایشِ زنده می‌خوانند. بازرسیِ محاسباتی (مهر ۱۴۰۵): سرور «/» را دور
    //    می‌انداخت و «۲/۵ گرم» **۲۵ گرم** ذخیره می‌شد، در حالی که صفحه ۲٫۵ نشان داده بود.
    $clean = str_replace(['٫', '،', ',', '/'], '.', $clean);
    $clean = preg_replace('/[^0-9.]/', '', $clean);
    return round((float)$clean, $decimals);
}

/**
 * معامله‌های کاربر همراه با جمع فروش‌ها و سود.
 *
 * منطق سود این‌جاست و فقط این‌جا، تا صفحه و تست و هر مصرف‌کننده‌ی
 * دیگری یک جواب بگیرند:
 *
 *   بهای هر واحد   = (خرید + هزینه‌های جانبی) / تعداد
 *   سود قطعی       = جمع فروش‌ها − بهای واحد × تعداد فروخته‌شده
 *
 * یعنی هزینه‌ی جانبی به نسبتِ مقدارِ فروخته‌شده در سود اثر می‌گذارد؛
 * تا وقتی چیزی نفروخته‌اید، سودی هم قطعی نشده.
 */
function tradesWithProgress(int $userId): array
{
    $pdo = Database::getConnection();
    if (!tradesTablesExist($pdo)) { return []; }

    $st = $pdo->prepare(
        'SELECT t.*, w.name AS wallet_name,
                COALESCE(s.sold_qty, 0)   AS sold_qty,
                COALESCE(s.sold_total, 0) AS sold_total,
                COALESCE(s.sales_count, 0) AS sales_count
         FROM trades t
         LEFT JOIN wallets w ON w.id = t.buy_wallet_id
         LEFT JOIN (
             SELECT trade_id, SUM(qty) AS sold_qty, SUM(sale_total) AS sold_total,
                    COUNT(*) AS sales_count
             FROM trade_sales WHERE user_id = :u1 GROUP BY trade_id
         ) s ON s.trade_id = t.id
         WHERE t.user_id = :u2
         ORDER BY (COALESCE(s.sold_qty,0) >= t.qty), t.buy_date DESC, t.id DESC'
    );
    $st->execute(['u1' => $userId, 'u2' => $userId]);

    $rows = [];
    foreach ($st->fetchAll() as $t) {
        $qty      = (float)$t['qty'];
        $soldQty  = min((float)$t['sold_qty'], $qty);
        $unitCost = $qty > 0 ? ((int)$t['buy_total'] + (int)$t['side_costs']) / $qty : 0;

        $t['remaining_qty']   = round($qty - $soldQty, 3);
        $t['is_closed']       = $t['remaining_qty'] <= 0;
        $t['realized_profit'] = (int)round((int)$t['sold_total'] - $unitCost * $soldQty);
        // سرمایه‌ای که هنوز در جنس مانده — به بهای خرید
        $t['open_cost']       = (int)round($unitCost * $t['remaining_qty']);
        $rows[] = $t;
    }
    return $rows;
}

/** فروش‌های یک معامله، نو به کهنه. مالکیت را همین‌جا چک می‌کند. */
function tradeSales(int $userId, int $tradeId): array
{
    $pdo = Database::getConnection();
    $st = $pdo->prepare(
        'SELECT s.*, w.name AS wallet_name
         FROM trade_sales s
         LEFT JOIN wallets w ON w.id = s.wallet_id
         WHERE s.user_id = :u AND s.trade_id = :t
         ORDER BY s.sale_date DESC, s.id DESC'
    );
    $st->execute(['u' => $userId, 't' => $tradeId]);
    return $st->fetchAll();
}

/** جمع کل صفحه‌ی معاملات: سود قطعی، سرمایه‌ی درگیر، تعداد باز. */
function tradesSummary(array $trades): array
{
    $sum = ['realized_profit' => 0, 'open_cost' => 0, 'open_count' => 0, 'closed_count' => 0];
    foreach ($trades as $t) {
        $sum['realized_profit'] += $t['realized_profit'];
        $sum['open_cost']       += $t['open_cost'];
        $t['is_closed'] ? $sum['closed_count']++ : $sum['open_count']++;
    }
    return $sum;
}

/**
 * نمایش تعداد: ۲٫۵ به‌جای 2.500، با ارقامِ فارسی — ممیزِ «٫» و هزارگانِ «٬»
 * (همان جداکننده‌ی `formatMoney()`). تنها قالب‌دهنده‌ی تعداد در اپ.
 */
function formatQty($qty, int $places = 3): string
{
    $s = rtrim(rtrim(number_format((float)$qty, $places, '.', ','), '0'), '.');
    return toPersianDigits(strtr($s, ['.' => '٫', ',' => '٬']));
}

/**
 * دسته‌بندی سیستمی سود/زیان معامله — اگر نبود ساخته می‌شود.
 * دسته‌ها در این اپ سراسری‌اند، پس یک بار برای همه ساخته می‌شود.
 */
function tradeProfitCategoryId(PDO $pdo, string $type): ?int
{
    static $cache = [];
    if (isset($cache[$type])) { return $cache[$type]; }

    $name = $type === 'income' ? 'سود معاملات' : 'زیان معاملات';
    try {
        // فقط دسته‌ی پیش‌فرض (user_id IS NULL) — این دسته مال برنامه است
        // نه یک کاربر خاص، و اگر شخصیِ کسی می‌شد بقیه دوباره‌اش را
        // می‌ساختند و چند «سود معاملات» تکراری درست می‌شد.
        $st = $pdo->prepare(
            'SELECT id FROM categories
             WHERE name = :n AND type = :t' .
            (tableHasColumn('categories', 'user_id') ? ' AND user_id IS NULL' : '') .
            ' LIMIT 1'
        );
        $st->execute(['n' => $name, 't' => $type]);
        $id = $st->fetchColumn();
        if (!$id) {
            $pdo->prepare('INSERT INTO categories (name, type, is_active) VALUES (:n, :t, 1)')
                ->execute(['n' => $name, 't' => $type]);
            $id = $pdo->lastInsertId();
        }
        return $cache[$type] = (int)$id;
    } catch (PDOException $e) {
        return $cache[$type] = null;
    }
}

/**
 * تراکنش‌های سودِ یک معامله را با فروش‌هایش هم‌گام می‌کند.
 *
 * چرا لازم است: بخش معامله جداست، ولی سود و زیانش باید در حسابداری
 * دیده شود — در آخرین تراکنش‌ها و جمع روز/هفته/ماه. پس به ازای هر
 * فروش، یک تراکنش برای «سهم سود همان فروش» ساخته می‌شود (نه کل مبلغ
 * فروش، که پول است نه سود).
 *
 * دو نکته که اگر رعایت نشوند اعداد غلط می‌شوند:
 * - تراکنش سود عمداً بدون حساب (wallet) است: جابه‌جایی پول را خودِ
 *   فروش در walletBalances حساب می‌کند؛ اگر تراکنش سود هم به حساب
 *   می‌خورد، سود دوبار جمع می‌شد.
 * - این تابع «هم‌گام‌سازی» است نه «افزودن»: بعد از ویرایش خرید
 *   (تغییر مبلغ/تعداد/هزینه‌ی جانبی) سودِ فروش‌های قبلی عوض می‌شود و
 *   تراکنش‌هایشان باید بازنویسی شوند، نه اینکه ردیف تازه اضافه شود.
 *
 * سود صفر = بدون تراکنش. زیان = تراکنش هزینه (amount در دیتابیس
 * بدون علامت است).
 */
function syncTradeProfitTransactions(int $userId, int $tradeId): void
{
    $pdo = Database::getConnection();
    if (!tradesTablesExist($pdo)) { return; }

    // ستون پیوند با migration_trades2 می‌آید؛ بدون آن کاری نمی‌کنیم
    if (!tableHasColumn('trade_sales', 'profit_tx_id')) { return; }

    $st = $pdo->prepare('SELECT title, qty, buy_total, side_costs FROM trades WHERE id = :id AND user_id = :u');
    $st->execute(['id' => $tradeId, 'u' => $userId]);
    $trade = $st->fetch();
    if (!$trade) { return; }

    $qty = (float)$trade['qty'];
    $unitCost = $qty > 0 ? ((int)$trade['buy_total'] + (int)$trade['side_costs']) / $qty : 0;

    $sales = $pdo->prepare('SELECT id, qty, sale_total, sale_date, profit_tx_id FROM trade_sales WHERE trade_id = :t AND user_id = :u ORDER BY sale_date, id');
    $sales->execute(['t' => $tradeId, 'u' => $userId]);

    // ⛔ گردِ تجمعی، نه گردِ هر فروش جدا: جمعِ سودهای ثبت‌شده باید همان «سود قطعی»
    //    ِ `tradesWithProgress()` باشد که یک‌جا گرد می‌شود. بازرسیِ محاسباتی (مهر ۱۴۰۵):
    //    ۳ واحد به ۱۰۰۰، سه فروشِ ۵۰۰ ⇒ صفحه «۵۰۰ سود» و دفتر ۵۰۱ درآمد.
    $cumSale = 0; $cumQty = 0.0; $booked = 0;
    foreach ($sales->fetchAll() as $s) {
        $cumSale += (int)$s['sale_total'];
        $cumQty  += (float)$s['qty'];
        $upTo     = (int)round($cumSale - $unitCost * $cumQty);
        $profit   = $upTo - $booked;
        $booked   = $upTo;
        $txId   = $s['profit_tx_id'] ? (int)$s['profit_tx_id'] : null;

        if ($profit === 0) {
            if ($txId) {
                $pdo->prepare('DELETE FROM transactions WHERE id = :id AND user_id = :u')
                    ->execute(['id' => $txId, 'u' => $userId]);
                $pdo->prepare('UPDATE trade_sales SET profit_tx_id = NULL WHERE id = :id')
                    ->execute(['id' => $s['id']]);
            }
            continue;
        }

        $type   = $profit > 0 ? 'income' : 'expense';
        $title  = ($profit > 0 ? 'سود معامله: ' : 'زیان معامله: ') . $trade['title'];
        $catId  = tradeProfitCategoryId($pdo, $type);
        $params = [
            'c' => $catId, 't' => $type, 'a' => abs($profit),
            'ti' => mb_substr($title, 0, 255), 'd' => $s['sale_date'], 'u' => $userId,
        ];

        if ($txId) {
            $upd = $pdo->prepare(
                'UPDATE transactions SET category_id = :c, type = :t, amount = :a,
                        title = :ti, transaction_date = :d
                 WHERE id = :id AND user_id = :u'
            );
            $upd->execute($params + ['id' => $txId]);
            if ($upd->rowCount() > 0 || tradeProfitTxExists($pdo, $txId, $userId)) { continue; }
            // تراکنش پیوندی دستی حذف شده — یکی تازه می‌سازیم
        }

        $pdo->prepare(
            'INSERT INTO transactions (user_id, category_id, type, amount, title, note, transaction_date)
             VALUES (:u, :c, :t, :a, :ti, "ثبت خودکار از بخش معاملات", :d)'
        )->execute($params);
        $pdo->prepare('UPDATE trade_sales SET profit_tx_id = :tx WHERE id = :id')
            ->execute(['tx' => $pdo->lastInsertId(), 'id' => $s['id']]);
    }
}

function tradeProfitTxExists(PDO $pdo, int $txId, int $userId): bool
{
    $st = $pdo->prepare('SELECT 1 FROM transactions WHERE id = :id AND user_id = :u');
    $st->execute(['id' => $txId, 'u' => $userId]);
    return (bool)$st->fetchColumn();
}

/** تراکنش‌های سودِ همه‌ی فروش‌های یک معامله را حذف می‌کند (پیش از حذف معامله). */
function deleteTradeProfitTransactions(int $userId, int $tradeId): void
{
    $pdo = Database::getConnection();
    try {
        $pdo->prepare(
            'DELETE tx FROM transactions tx
             JOIN trade_sales s ON s.profit_tx_id = tx.id
             WHERE s.trade_id = :t AND s.user_id = :u AND tx.user_id = :u2'
        )->execute(['t' => $tradeId, 'u' => $userId, 'u2' => $userId]);
    } catch (PDOException $e) {
        // ستون پیوند هنوز نیست — چیزی برای حذف نیست
    }
}

/* ============================================================
   بانک‌ها و نمای کارت
   ============================================================ */

/**
 * فهرست بانک‌های رایج با رنگ هویتی‌شان.
 *
 * ⚠️ رنگ‌ها تقریبی‌اند و از روی هویت بصری شناخته‌شده‌ی هر بانک انتخاب
 * شده‌اند. کاربر رنگ را می‌تواند دستی هم عوض کند.
 *
 * ⛔ لوگوی رسمیِ هر بانک در `assets/banks/{کد}.svg` است (`bankLogoUrl()`).
 * **خواسته‌ی مالکِ نصب:** «لوگوی رسمی بانک‌ها منعی نداره، همه رو با
 * لوگوی رسمی بیار». قاعده‌ی قبلی («لوگو علامتِ تجاری است») به تصمیمِ
 * صریحِ مالکِ نصب کنار رفت. منبع: بسته‌ی MIT
 * `@snapp-store/iranian-banks-react-icons` (۱٫۱٫۱)، به SVG تبدیل و با
 * svgo کوچک شده. هر کدِ تازه‌ای که اینجا اضافه شود **باید** فایلش را هم
 * داشته باشد — `test_bank_presets` می‌سنجد، جز «سایر».
 *
 * ساختار: کد => [نام، رنگ اصلی، رنگ دوم گرادیان]
 */
function bankPresets(): array
{
    return [
        'melli'      => ['بانک ملی ایران',      '#1b3f8f', '#12295e'],
        'mellat'     => ['بانک ملت',            '#c0392b', '#8e2420'],
        'saderat'    => ['بانک صادرات',         '#0d6fb8', '#084a7c'],
        'tejarat'    => ['بانک تجارت',          '#1e88a8', '#125b73'],
        'sepah'      => ['بانک سپه',            '#1f4e9c', '#14356b'],
        'refah'      => ['بانک رفاه کارگران',   '#00695c', '#00443c'],
        'keshavarzi' => ['بانک کشاورزی',        '#2e7d32', '#1b5220'],
        'maskan'     => ['بانک مسکن',           '#b71c1c', '#7f1414'],
        'post'       => ['پست بانک',            '#00838f', '#005b63'],
        'parsian'    => ['بانک پارسیان',        '#8e1537', '#5e0e24'],
        'pasargad'   => ['بانک پاسارگاد',       '#b8862f', '#7d5b1f'],
        'saman'      => ['بانک سامان',          '#0f4c81', '#0a3357'],
        'eghtesad'   => ['بانک اقتصاد نوین',    '#5b2d8e', '#3d1e60'],
        'ayandeh'    => ['بانک آینده',          '#6a1b9a', '#471268'],
        'shahr'      => ['بانک شهر',            '#c62828', '#8c1c1c'],
        'sina'       => ['بانک سینا',           '#00695f', '#004841'],
        'dey'        => ['بانک دی',             '#00838a', '#005a5f'],
        'karafarin'  => ['بانک کارآفرین',       '#1565c0', '#0e4585'],
        'gardeshgari'=> ['بانک گردشگری',        '#00897b', '#005f55'],
        'resalat'    => ['بانک قرض‌الحسنه رسالت','#2e7d5b', '#1d5340'],
        'mehriran'   => ['بانک قرض‌الحسنه مهر ایران', '#5e9e2c', '#3f6c1d'],
        'sanatmadan' => ['بانک صنعت و معدن',   '#8a7142', '#5e4c2c'],
        'tosee_saderat' => ['بانک توسعه صادرات', '#006d08', '#004a05'],
        'tosee_taavon'  => ['بانک توسعه تعاون',  '#3a9fbc', '#276e82'],
        'iranzamin'  => ['بانک ایران زمین',     '#4c2a93', '#331c63'],
        'sarmayeh'   => ['بانک سرمایه',         '#1f1f48', '#141430'],
        'khavarmianeh' => ['بانک خاورمیانه',    '#e08600', '#9c5d00'],
        'melal'      => ['مؤسسه اعتباری ملل',   '#37389a', '#25266a'],
        // بانکِ دیجیتالِ بانک سامان. کدِ جدا دارد چون کاربر آن را «بلو»
        // می‌شناسد نه «سامان»، و کارتش هم رنگِ خودش را دارد.
        'blu'        => ['بلوبانک',              '#1f5bff', '#133a9e'],
        'other'      => ['سایر / بانک دیگر',    '#475569', '#2f3b4a'],
    ];
}

/**
 * آدرسِ لوگوی رسمیِ یک بانک، یا `null` اگر کدی نیست یا فایلی ندارد.
 *
 * ⚠ `?v=` اجباری است، همان درسِ `iconUrl()`: nginx به هر `.svg` کشِ
 *   «یک سال، immutable» می‌دهد و بدونِ نسخه، لوگوی عوض‌شده برای کسی که
 *   یک بار دیده تا یک سال عوض نمی‌شد.
 * ⚠ کد از `bankPresets()` سنجیده می‌شود پیش از ساختنِ مسیر — `bank_code`
 *   از دیتابیس می‌آید و نباید هرگز خام در مسیرِ فایل بنشیند.
 */
function bankLogoUrl(?string $code): ?string
{
    if ($code === null || $code === '' || !isset(bankPresets()[$code])) { return null; }
    $rel = 'banks/' . $code . '.svg';
    if (!is_file(__DIR__ . '/../assets/' . $rel)) { return null; }
    return APP_BASE_PATH . '/assets/' . $rel . '?v=' . assetVersion([$rel]);
}

/** رنگ و نام یک بانک از روی کدش؛ اگر نبود، مقدار پیش‌فرض. */
function bankPreset(?string $code): ?array
{
    if ($code === null || $code === '') { return null; }
    $all = bankPresets();
    if (!isset($all[$code])) { return null; }
    return ['name' => $all[$code][0], 'c1' => $all[$code][1], 'c2' => $all[$code][2]];
}

/**
 * تیره کردن یک رنگ hex — برای ساختن پایه‌ی گرادیان کارت.
 *
 * کارت با دو رنگ ساخته می‌شود. رنگ اول همانی است که کاربر انتخاب کرده
 * و رنگ دوم نسخه‌ی تیره‌ترش. پیش از این اگر بانک از فهرست انتخاب شده
 * بود، رنگ‌های همان بانک بر انتخاب کاربر غلبه می‌کردند و کاربر رنگ
 * عوض می‌کرد ولی هیچ اتفاقی نمی‌افتاد.
 */
function shadeColor(string $hex, float $factor = 0.62): string
{
    $hex = ltrim(trim($hex), '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) { return '#2f3b4a'; }

    $out = '#';
    foreach ([0, 2, 4] as $i) {
        $v = (int)round(hexdec(substr($hex, $i, 2)) * $factor);
        $out .= str_pad(dechex(max(0, min(255, $v))), 2, '0', STR_PAD_LEFT);
    }

    return $out;
}

/**
 * فقط رقم‌ها را نگه می‌دارد (ارقام فارسی و عربی را هم می‌فهمد).
 * برای شماره‌ی کارت، شماره‌ی حساب و شبا.
 */
function digitsOnly($input, int $max = 40): string
{
    $clean = preg_replace('/[^0-9]/', '', toLatinDigits((string)$input));
    return mb_substr($clean, 0, $max);
}

/** ۶۰۳۷۹۹۱۱۱۱۱۱۱۱۱۱ ← ۶۰۳۷ ۹۹۱۱ ۱۱۱۱ ۱۱۱۱ */
function formatCardNumber(?string $card): string
{
    $d = digitsOnly($card ?? '', 19);
    if ($d === '') { return ''; }
    return toPersianDigits(trim(chunk_split($d, 4, ' ')));
}

/** ۱۲۳... ← IR12 3456 7890 ... (شبا همیشه با IR نمایش داده می‌شود) */
function formatIban(?string $iban): string
{
    $d = digitsOnly($iban ?? '', 24);
    if ($d === '') { return ''; }
    return 'IR' . toPersianDigits(trim(chunk_split($d, 4, ' ')));
}

// ---------------------------------------------------------------
// پیشنهادِ نصبِ اپ اندروید
// ---------------------------------------------------------------

/**
 * آدرسِ دانلودِ APK — یا رشته‌ی خالی اگر هنوز چیزی برای دانلود نیست.
 *
 * ⛔ **تنها جای این تصمیم** (مثل `categoryScopeSql()`): نوارِ پیشنهاد،
 *    لینکِ صفحه‌ی ورود و لینکِ پروفایل هر سه از همین می‌پرسند. با دو
 *    نسخه، دیر یا زود یکی لینک نشان می‌داد که فایلش نیست.
 *
 * ⛔ **لینکِ GitHub Release عمداً اینجا نیست.** مخزن خصوصی است و
 *    دارایی‌های Release یک مخزنِ خصوصی برای کاربرِ ناشناس ۴۰۴ می‌دهند —
 *    یعنی دکمه‌ای که فقط برای *ما* کار می‌کند و برای کاربر نه. APK باید
 *    روی خودِ دامنه بنشیند: `deploy/apk-publish.sh` می‌گذاردش.
 *
 * ترتیب: ثابتِ `config.php` (برای میزبانیِ جای دیگر، مثلاً بازار) و بعد
 * فایلِ محلی. هیچ‌کدام نبود → رشته‌ی خالی و **هیچ چیزی رندر نمی‌شود**؛
 * دکمه‌ای که کار نمی‌کند بدتر از نبودنش است.
 */
function androidApkUrl(): string
{
    if (defined('ANDROID_APK_URL') && trim((string)ANDROID_APK_URL) !== '') {
        return trim((string)ANDROID_APK_URL);
    }

    $path = __DIR__ . '/../download/hesabland.apk';
    if (is_file($path) && filesize($path) > 0) {
        // ⚠ `?v=` از زمانِ فایل می‌آید تا نسخه‌ی تازه واقعاً دانلود شود.
        //   بدونش، مرورگرِ کسی که یک بار دانلود کرده ممکن است همان
        //   فایلِ قدیمی را بدهد و کاربر فکر کند به‌روزرسانی نشده.
        return APP_BASE_PATH . '/download/hesabland.apk?v=' . (int)filemtime($path);
    }

    return '';
}

/**
 * آخرین نسخه‌ی اپ که روی سرور دانلودشدنی است — یا `null`.
 *
 * ⛔ **تنها جای این تصمیم** (مثل `androidApkUrl()`): نوارِ «نسخه‌ی تازه
 *    آماده است» فقط از همین می‌پرسد.
 *
 * ⛔ عدد از **خودِ فایل** خوانده می‌شود (`apkManifestInfo()`)، نه از یک
 *    فایلِ کناری یا متنِ Release: با مرجعِ دوم، دیر یا زود عدد با فایلی
 *    که واقعاً دانلود می‌شود نمی‌خواند و کاربرِ به‌روز هر روز «به‌روزرسانی
 *    کن» می‌گرفت — حتی بلافاصله بعد از نصب.
 *
 * ⚠ نتیجه در `var/apk-meta.json` کش می‌شود با کلیدِ اندازه + زمان + inode
 *   فایل؛ جابه‌جاییِ اتمیِ `apk-publish.sh` هر سه را عوض می‌کند، پس فایلِ
 *   تازه همان درخواستِ بعدی دیده می‌شود. نتیجه‌ی **ناموفق** هم کش می‌شود
 *   تا فایلِ خراب در هر بارگذاری دوباره تجزیه نشود.
 *
 * ⚠ با `ANDROID_APK_URL` (میزبانیِ بیرونی) فایل اینجا نیست، پس عدد فقط از
 *   `ANDROID_APK_VERSION_CODE` می‌آید؛ نبودش یعنی «نوار نیاید» — سمتِ
 *   امنِ خطا.
 *
 * @return array{code:int, name:string, url:string}|null
 */
function androidApkLatest(): ?array
{
    $url = androidApkUrl();
    if ($url === '') { return null; }

    if (defined('ANDROID_APK_URL') && trim((string)ANDROID_APK_URL) !== '') {
        $code = defined('ANDROID_APK_VERSION_CODE') ? (int)ANDROID_APK_VERSION_CODE : 0;
        if ($code <= 0) { return null; }
        $name = defined('ANDROID_APK_VERSION_NAME') ? (string)ANDROID_APK_VERSION_NAME : '';
        return ['code' => $code, 'name' => $name, 'url' => $url];
    }

    $info = apkLatestFrom(__DIR__ . '/../download/hesabland.apk', __DIR__ . '/../var/apk-meta.json');
    return $info === null ? null : $info + ['url' => $url];
}

/**
 * نسخه‌ی فایلِ `$apk` با کشِ `$cache` — جدا از `androidApkLatest()` تا
 * بی‌دست زدن به `download/` آزمودنی باشد.
 *
 * @return array{code:int, name:string}|null
 */
function apkLatestFrom(string $apk, string $cache): ?array
{
    require_once __DIR__ . '/apk_meta.php';

    clearstatcache(true, $apk);
    $st = @stat($apk);
    if (!$st || (int)$st['size'] <= 0) { return null; }
    $key = $st['size'] . ':' . $st['mtime'] . ':' . $st['ino'];

    $c = is_file($cache) ? json_decode((string)@file_get_contents($cache), true) : null;
    if (is_array($c) && ($c['key'] ?? null) === $key && array_key_exists('info', $c)) {
        $info = is_array($c['info']) ? $c['info'] : null;
    } else {
        $info = apkManifestInfo($apk);
        // ⚠ نوشتنِ کش اختیاری است: `var/` نوشتنی نباشد، هر درخواست
        //   دوباره تجزیه می‌کند (حدود یک میلی‌ثانیه) و چیزی نمی‌شکند.
        $tmp = $cache . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode(['key' => $key, 'info' => $info])) !== false) {
            @rename($tmp, $cache) || @unlink($tmp);
        }
    }

    // ⛔ بسته‌ی دیگر یعنی نوار نیاید: «به‌روزرسانی» یک اپِ دیگر نصب می‌کرد.
    if (!is_array($info) || ($info['package'] ?? '') !== ANDROID_PACKAGE
        || (int)($info['version_code'] ?? 0) <= 0) {
        return null;
    }
    return ['code' => (int)$info['version_code'], 'name' => (string)($info['version_name'] ?? '')];
}

/** آیا این درخواست از یک دستگاهِ اندرویدی آمده؟ */
function isAndroidRequest(): bool
{
    return stripos((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 'android') !== false;
}

/**
 * نوارِ «اپ اندروید را نصب کنید» — یا رشته‌ی خالی.
 *
 * ⛔ **هیچ `position: fixed` ای ندارد و نباید داشته باشد.** نوارِ شناور
 *    با `.bottom-nav` و حاشیه‌ی امن درگیر می‌شد — همان دسته خرابی که
 *    بارها در این پروژه فقط روی گوشیِ نصب‌شده دیده شد. این یک کارتِ
 *    معمولیِ بالای محتواست، پس هیچ چیزی نمی‌تواند رویش بیفتد.
 *
 * ⛔ **گیتِ نهایی سمتِ مرورگر است، نه اینجا.** سرور نمی‌تواند بفهمد
 *    کاربر همین حالا *داخلِ خودِ اپ* است: TWA با کرومِ معمولی رندر
 *    می‌شود و User-Agent اش هیچ فرقی ندارد. تنها نشانه‌ی قابل اتکا
 *    `document.referrer` است که در اولین ناوبریِ TWA با
 *    `android-app://` شروع می‌شود.
 */
function androidInstallBanner(): string
{
    $url = androidApkUrl();
    if ($url === '' || !isAndroidRequest()) { return ''; }

    $app = defined('APP_NAME') ? APP_NAME : 'حساب لند';

    ob_start(); ?>
<div class="apk-bar" id="apkBar" hidden>
    <span class="apk-bar-icon" aria-hidden="true">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="M7.5 10.5L12 15l4.5-4.5"/><path d="M4 18.5h16"/></svg>
    </span>
    <div class="apk-bar-body">
        <strong>«<?= h($app) ?>» را روی گوشی نصب کنید.</strong>
        همین برنامه، تمام‌صفحه و بدون نوار آدرس — با یک آیکون روی صفحه‌ی گوشی.
    </div>
    <a class="btn btn-primary btn-sm apk-bar-cta" href="<?= h($url) ?>" download>دریافت اپ</a>
    <button type="button" class="apk-bar-x" id="apkBarNo" aria-label="بستن">&times;</button>
</div>
<script>
(function () {
    var bar = document.getElementById('apkBar');
    if (!bar) { return; }

    /* ⛔ پیشوندِ `daftar_` عمداً با برند عوض **نشد** — نه اینجا و نه در
       هیچ کلیدِ `localStorage` یا کوکیِ دیگری. این‌ها نامِ ما نیستند،
       نشانیِ چیزی هستند که همین حالا روی مرورگرِ کاربر نشسته: عوض
       کردنشان یعنی حالت شب، کارت‌های پین‌شده، فیلترِ دارایی و «دستگاه
       مورد اعتماد» همه از نو صفر می‌شوند و کاربر دوباره رمز می‌خواهد —
       بی‌هیچ خطایی و بدونِ اینکه کسی ربطش را به تغییرِ نام پیدا کند. */
    var HAS = 'daftar_has_apk';     // یک بار از داخلِ اپ باز شده؟
    var NO  = 'daftar_apk_hide';    // کاربر خودش بست؟

    function get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
    function set(k) { try { localStorage.setItem(k, '1'); } catch (e) {} }

    /* ⛔ نشانه‌ی «اپ نصب است» فقط در **اولین** ناوبریِ TWA می‌آید؛
       ناوبری‌های بعدی referrer هم‌ریشه دارند. پس همان یک بار ثبت
       می‌شود و برای همیشه می‌ماند — وگرنه کاربری که اپ را نصب کرده
       از صفحه‌ی دوم به بعد دوباره پیشنهادِ نصب می‌گرفت. */
    if (document.referrer && document.referrer.indexOf('android-app://') === 0) { set(HAS); }

    /* حالتِ نصب‌شده‌ی PWA هم یعنی از صفحه‌ی گوشی باز شده و پیشنهاد
       بی‌معناست. */
    var standalone = window.matchMedia && window.matchMedia('(display-mode: standalone)').matches;

    if (get(HAS) || get(NO) || standalone) { return; }

    bar.hidden = false;
    var no = document.getElementById('apkBarNo');
    if (no) { no.addEventListener('click', function () { set(NO); bar.hidden = true; }); }
})();
</script>
<?php
    return (string)ob_get_clean();
}
