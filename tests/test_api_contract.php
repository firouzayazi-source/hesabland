<?php
/**
 * تست قرارداد اندپوینت‌های api/ — قواعدی که کل امنیت اپ رویشان بنا شده.
 *
 * این‌ها تا امروز فقط در CLAUDE.md نوشته شده بودند و هیچ چیز جز چشم آدم
 * تضمینشان نمی‌کرد. یک اندپوینت تازه که یادش برود بررسی ورود بگذارد،
 * بی‌سروصدا داده‌ی همه‌ی کاربران را باز می‌کند.
 *
 * این تست ساختار کد را می‌آزماید نه رفتار را، پس بدون دیتابیس و سرور
 * هم اجرا می‌شود. تست رفتاری در test_api_auth.php است.
 */

// ---------- نگهبان: فقط خط فرمان ----------
// این فایل داخل ریشه‌ی وب است و بدون این نگهبان، هر کسی می‌توانست با
// باز کردن آدرسش در مرورگر تست را روی دیتابیس واقعی اجرا کند — تست‌ها
// کاربر و رکورد می‌سازند و پاک می‌کنند.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}


require_once __DIR__ . '/lib/assert.php';

$apiDir = __DIR__ . '/../api';
$files  = glob($apiDir . '/*.php');
sort($files);

// اندپوینت‌های فقط-خواندنی که عمداً CSRF ندارند.
// اگر روزی یکی از این‌ها نویسنده شد، باید از این فهرست برداشته شود.
$csrfExempt = [
    'category_transactions.php',
    'cheque_value_preview.php',
    'day_detail.php',
    'home_calendar.php',
    'savings_history.php',
    'transaction_attachments.php',
    'view_attachment.php',
];

// ---------------------------------------------------------------
T::group('همه‌ی اندپوینت‌ها پیدا شدند');
T::ok(count($files) >= 40, 'تعداد اندپوینت‌های api/', 'پیدا شد: ' . count($files));

// ---------------------------------------------------------------
T::group('قاعده ۱ — هر اندپوینت باید ورود کاربر را بررسی کند');

$bad = [];
foreach ($files as $f) {
    $src  = file_get_contents($f);
    $name = basename($f);
    $hasAuthInclude = str_contains($src, "auth.php");
    $hasAuthCheck   = str_contains($src, 'Auth::isLoggedIn')
                   || str_contains($src, 'Auth::requireLogin')
                   || str_contains($src, 'Auth::requireAdmin');
    if (!$hasAuthInclude || !$hasAuthCheck) {
        $bad[] = $name . ($hasAuthInclude ? ' — auth.php لود شده ولی بررسی نمی‌کند' : ' — auth.php اصلاً لود نشده');
    }
}
T::bulk(count($files), $bad, 'هر اندپوینت auth.php را لود و ورود را بررسی می‌کند');

// ---------------------------------------------------------------
T::group('قاعده ۲ — هر اندپوینت نویسنده باید CSRF را بررسی کند');

$bad = [];
$writers = 0;
foreach ($files as $f) {
    $src  = file_get_contents($f);
    $name = basename($f);
    if (in_array($name, $csrfExempt, true)) { continue; }
    $writers++;
    if (!str_contains($src, 'Csrf::verifyOrFail')) {
        $bad[] = "$name — نه در فهرست معافیت است، نه Csrf::verifyOrFail دارد";
    }
}
T::bulk($writers, $bad, 'هر اندپوینت خارج از فهرست معافیت، CSRF را بررسی می‌کند');

// فهرست معافیت نباید بپوسد: فایلی که دیگر وجود ندارد باید حذف شود
$bad = [];
foreach ($csrfExempt as $name) {
    if (!file_exists($apiDir . '/' . $name)) {
        $bad[] = "$name در فهرست معافیت هست ولی فایلش وجود ندارد";
    }
}
T::bulk(count($csrfExempt), $bad, 'فهرست معافیت CSRF به‌روز است');

// معاف‌ها واقعاً باید فقط-خواندنی باشند
$bad = [];
foreach ($csrfExempt as $name) {
    $p = $apiDir . '/' . $name;
    if (!file_exists($p)) { continue; }
    $src = file_get_contents($p);
    if (preg_match('/->\s*(prepare|query)\s*\(\s*["\'][^"\']*\b(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|DROP|ALTER)\b/i', $src, $m)) {
        $bad[] = "$name معاف از CSRF است ولی کوئری نویسنده دارد ({$m[2]})";
    }
}
T::bulk(count($csrfExempt), $bad, 'اندپوینت‌های معاف واقعاً فقط-خواندنی‌اند');

// ---------------------------------------------------------------
T::group('قاعده ۳ — user_id هرگز از ورودی کاربر نمی‌آید');

$bad = [];
foreach ($files as $f) {
    $src  = file_get_contents($f);
    $name = basename($f);
    // گرفتن user_id از POST/GET یعنی هر کاربری می‌تواند خود را جای دیگری بزند
    if (preg_match('/(postParam|getParam)\s*\(\s*[\'"]user_id[\'"]/', $src)) {
        $bad[] = "$name — user_id را از ورودی کاربر می‌خواند";
    }
    if (preg_match('/\$_(POST|GET|REQUEST)\s*\[\s*[\'"]user_id[\'"]\s*\]/', $src)) {
        $bad[] = "$name — user_id را مستقیم از \$_POST/\$_GET می‌خواند";
    }
}
T::bulk(count($files), $bad, 'هیچ اندپوینتی user_id را از ورودی نمی‌گیرد');

// ---------------------------------------------------------------
T::group('قاعده ۴ — کوئری‌ها پارامتری‌اند');

$bad = [];
foreach ($files as $f) {
    $src  = file_get_contents($f);
    $name = basename($f);
    // درج مستقیم متغیر داخل رشته‌ی SQL
    if (preg_match_all('/->\s*(prepare|query)\s*\(\s*"[^"]*\$[A-Za-z_]/', $src, $m)) {
        foreach ($m[0] as $hit) {
            $bad[] = "$name — متغیر داخل رشته‌ی SQL: " . trim(substr($hit, 0, 60));
        }
    }
}
T::bulk(count($files), $bad, 'هیچ متغیری مستقیم داخل رشته‌ی SQL درج نشده');

// ---------------------------------------------------------------
T::group('قاعده ۵ — هر خواندنِ دسته‌بندی از categoryScopeSql رد می‌شود');

// چرا: `categories.user_id` سه‌حالتی است — NULL یعنی پیش‌فرضِ برنامه و
// عدد یعنی دسته‌ی شخصیِ همان کاربر. اگر کوئری‌ای این شرط را نگذارد،
// دسته‌های شخصیِ بقیه را هم برمی‌دارد. یک بار در `data.php` همین شد:
// فهرست کامل هم برای تطبیق نام استفاده می‌شد و هم با
// `window.IMPORT_CATEGORIES` به مرورگر می‌رفت، پس نامِ دسته‌های خصوصیِ
// همه‌ی کاربران در سورس صفحه دیده می‌شد.
//
// دو استثنا، هر دو عمدی:
//   admin/categories.php  — با `user_id IS NULL` فقط پیش‌فرض‌ها را می‌بیند
//   api/manage_reference.php — با `user_id = :u` فقط مالِ خودِ کاربر
$catFiles = array_merge(
    glob(__DIR__ . '/../*.php') ?: [],
    glob(__DIR__ . '/../api/*.php') ?: [],
    glob(__DIR__ . '/../includes/*.php') ?: []
);
$bad = [];
$checked = 0;
foreach ($catFiles as $f) {
    // کامنت‌ها کنار گذاشته می‌شوند، وگرنه همین توضیحی که بالای یک کوئریِ
    // ناایمن نوشته شده کافی است تا تست را گول بزند.
    $code = '';
    foreach (token_get_all((string)file_get_contents($f)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $code .= is_array($t) ? $t[1] : $t;
    }

    // هر «FROM categories» جداگانه سنجیده می‌شود، نه کلِ فایل — یک کوئریِ
    // امن در بالای فایل نباید کوئریِ ناایمنِ پایینش را بپوشاند.
    if (!preg_match_all('/FROM\s+categories\b/i', $code, $m, PREG_OFFSET_CAPTURE)) { continue; }
    foreach ($m[0] as [$hit, $at]) {
        $checked++;
        // پنجره تا پایان همان دستور PHP (اولین `;`) بریده می‌شود، نه تعداد
        // ثابتی کاراکتر. نسخه‌ی اول ۶۰۰ کاراکتر می‌گرفت و در `data.php` به
        // کوئریِ بعدی سرریز می‌کرد — آنجا `FROM wallets WHERE user_id = :u`
        // بود و تست، شرطِ *کیف پول* را به حساب دسته‌بندی می‌گذاشت و سبز
        // نشان می‌داد.
        $end    = strpos($code, ';', $at);
        $window = substr($code, $at, $end === false ? 600 : $end - $at);
        // شرط باید در WHERE/AND باشد. `ORDER BY (user_id IS NULL)` یک شرطِ
        // صافی نیست — فقط مرتب‌سازی است — ولی نسخه‌ی دومِ همین تست آن را
        // به‌جای شرط می‌گرفت و کوئریِ ناایمن را سبز نشان می‌داد.
        $ownership = '/\b(WHERE|AND)\s+\(?\s*[a-z_]*\.?user_id\s+(IS\s+NULL|=\s*:)/i';
        $scoped = strpos($window, 'categoryScopeSql') !== false
            || preg_match($ownership, $window);
        if (!$scoped) {
            $bad[] = basename($f) . ' — «FROM categories» بدون شرط مالکیت (بایت ' . $at . ')';
        }
    }
}
T::ok($checked >= 8, 'کوئری‌های دسته‌بندی پیدا شدند', 'بررسی‌شده: ' . $checked);
T::bulk($checked, $bad, 'هر خواندنِ دسته‌بندی شرط مالکیت دارد');

// ---------------------------------------------------------------
T::group('قاعده ۶ — سرویس‌ورکر هیچ HTML ای کش نمی‌کند');

// چرا: همه‌ی صفحه‌ها عمداً `Cache-Control: no-store, private` می‌گیرند،
// چون سافاری روی آیفون نسخه‌ی کش‌شده را نشان می‌داد و کاربر بعد از حذف
// یک تراکنش باز همان عدد قدیمی را می‌دید. اگر سرویس‌ورکر HTML را کش
// کند همان باگ برمی‌گردد — این بار بدتر، چون کشِ سرویس‌ورکر با
// تازه‌سازیِ صفحه هم پاک نمی‌شود.
$swFile = __DIR__ . '/../sw.js';
if (!file_exists($swFile)) {
    T::skip('تست سرویس‌ورکر', 'sw.js وجود ندارد');
} else {
    $sw = (string)file_get_contents($swFile);

    T::ok(file_exists(__DIR__ . '/../offline.html'), 'صفحه‌ی آفلاین وجود دارد');

    // فهرست پیش‌کش نباید هیچ فایل PHP ای داشته باشد
    $pre = [];
    if (preg_match('/const PRECACHE\s*=\s*\[(.*?)\];/s', $sw, $mm)) {
        preg_match_all("/rel\('([^']+)'\)/", $mm[1], $pp);
        $pre = $pp[1];
    }
    T::ok(count($pre) > 0, 'فهرست پیش‌کش خوانده شد', 'تعداد: ' . count($pre));
    $php = array_values(array_filter($pre, fn($u) => str_contains($u, '.php')));
    T::bulk(count($pre), array_map(fn($u) => "پیش‌کشِ PHP: $u", $php), 'هیچ فایل PHP ای پیش‌کش نمی‌شود');

    // بعد از هر deploy آدرس app.js عوض می‌شود، پس کش خطا می‌خورد و فایل
    // باید از شبکه بیاید. اگر آن یک درخواست روی اینترنت موبایل گیر کند،
    // صفحه کامل بالا می‌آید ولی هیچ دکمه‌ای کار نمی‌کند — بی‌هیچ نشانه‌ای.
    // پس نبودِ فایل در کش باید تورِ نجات داشته باشد: نسخه‌ی قبلیِ همان
    // فایل با ignoreSearch، و یک مهلت تا شبکه‌ی گیرکرده بی‌نهایت طول نکشد.
    T::ok(str_contains($sw, 'ignoreSearch: true'),
          'اگر نسخه‌ی تازه نرسید، نسخه‌ی قبلیِ همان فایل برگردانده می‌شود');
    T::ok((bool)preg_match('/NET_TIMEOUT|Promise\.race/', $sw),
          'انتظار برای شبکه مهلت دارد');
    T::ok(str_contains($sw, 'dropOtherVersions'),
          'نسخه‌های قدیمیِ هر فایل از کش پاک می‌شوند');

    // درخواست ناوبری باید شاخه‌ی جدا داشته باشد و از شبکه بیاید
    T::ok(str_contains($sw, "req.mode === 'navigate'"), 'ناوبری شاخه‌ی جداگانه دارد');
    T::ok(str_contains($sw, 'isStaticAsset'), 'کش فقط به دارایی‌های ثابت محدود شده');

    // دارایی کش‌شده نباید در پس‌زمینه دوباره گرفته شود.
    //
    // نسخه‌ی اول «stale-while-revalidate» بود و نتیجه‌اش بدتر از نداشتنِ
    // سرویس‌ورکر شد: در هر ناوبری ۶ فایل (حدود ۲۲۰ کیلوبایت) دوباره از
    // شبکه گرفته می‌شد، با اینکه همه کش بودند. اندازه‌گیری شد: ۶ درخواست
    // در هر جابه‌جایی، که با این اصلاح صفر شد.
    // نامِ متغیر مهم نیست، شکلِ رفتار مهم است: نتیجه‌ی cache.match(req)
    // اگر بود، همان‌جا برگردد — بدون هیچ fetch ای در میان.
    T::ok(
        (bool)preg_match('/const\s+(\w+)\s*=\s*await\s+cache\.match\(req\);\s*\n\s*if\s*\(\1\)\s*\{\s*return\s+\1;/', $sw),
        'دارایی کش‌شده بدون درخواست تازه برگردانده می‌شود'
    );
}

// ---------------------------------------------------------------
T::group('فایل‌های خط‌فرمانی از وب اجرا نشوند');

// چرا: tests/ و deploy/ داخل ریشه‌ی وب‌اند. سایت nginx این دو پوشه را
// می‌بندد، ولی آن یک لایه است و می‌تواند از دست برود (certbot فایل سایت
// را بازنویسی می‌کند، یا کسی روی هاست دیگری نصب می‌کند). تست‌ها کاربر و
// رکورد روی دیتابیس *واقعی* می‌سازند، پس نگهبان باید در خود فایل باشد.
$cliFiles = array_merge(
    glob(__DIR__ . '/test_*.php') ?: [],
    glob(__DIR__ . '/../deploy/*.php') ?: []
);
$bad = [];
foreach ($cliFiles as $f) {
    $src = (string)file_get_contents($f);
    if (strpos($src, "PHP_SAPI !== 'cli'") === false) {
        $bad[] = basename($f) . ' نگهبان «فقط خط فرمان» ندارد';
    }
}
T::bulk(count($cliFiles), $bad, 'هر فایل خط‌فرمانی نگهبان PHP_SAPI دارد');

// ---------------------------------------------------------------
T::group('مود فایل‌ها با گیت هم‌خوان است');

// چرا: deploy/vps-setup.sh هنگام نصب روی سرور، بیت اجرای همه‌ی .sh ها
// را روشن می‌کند. اگر گیت فایلی را ۶۴۴ ثبت کرده باشد، همان chmod آن را
// «تغییر محلی» می‌کند و git pull بعدی روی سرور شکست می‌خورد. یک بار
// دقیقاً همین اتفاق افتاد.
$root = realpath(__DIR__ . '/..');
$bad  = [];
$shFiles = [];
exec('cd ' . escapeshellarg($root) . ' && git ls-files -s -- "*.sh" 2>/dev/null', $lines, $rc);
if ($rc !== 0 || !$lines) {
    T::skip('مود اسکریپت‌های .sh', 'اطلاعات گیت در دسترس نیست');
} else {
    foreach ($lines as $line) {
        if (!preg_match('/^(\d{6})\s+\S+\s+\d+\s+(.+)$/', $line, $m)) { continue; }
        $shFiles[] = $m[2];
        if ($m[1] !== '100755') {
            $bad[] = "{$m[2]} در گیت با مود {$m[1]} ثبت شده — باید 100755 باشد";
        }
    }
    T::bulk(count($shFiles), $bad, 'همه‌ی اسکریپت‌های .sh در گیت اجرایی‌اند');
}

// ---------------------------------------------------------------
T::group('درج متغیر کنار گیومه‌ی فارسی');

// چرا: PHP بایت‌های ≥ 0x80 را جزء نام متغیر می‌داند. پس در رشته‌ی
// "کاربر «$username» فعال شد" نامِ متغیر «$username»» خوانده می‌شود —
// یعنی متغیری که وجود ندارد. نتیجه: هشدار «Undefined variable» و
// پیامی که وسطش خالی است. دقیقاً همین در user-admin.php افتاد و چون
// php -l آن را نمی‌گیرد، تا اجرای واقعی پیدا نشد.
//
// چاره: {$var} — آکولاد مرز نام را روشن می‌کند.
$phpFiles = [];
foreach (['api', 'includes', 'admin', 'store', 'deploy', 'config', 'tests', '.'] as $dir) {
    foreach (glob(__DIR__ . '/../' . $dir . '/*.php') as $p) { $phpFiles[realpath($p)] = true; }
}
// با توکنایزر خود PHP بررسی می‌شود، نه با regex روی متن خام: این‌طور
// توضیحات و کامنت‌ها (که خودشان نمونه‌ی خراب دارند) اشتباهاً گیر نمی‌افتند.
// اگر PHP نام متغیر را با بایت غیر-ASCII خوانده باشد، همان باگ است.
$bad = [];
foreach (array_keys($phpFiles) as $p) {
    foreach (token_get_all(file_get_contents($p)) as $tok) {
        if (!is_array($tok) || $tok[0] !== T_VARIABLE) { continue; }
        if (preg_match('/[\x80-\xFF]/', $tok[1])) {
            $bad[] = basename($p) . ':' . $tok[2] . ' — نام متغیر ' . $tok[1] . ' — باید {$...} شود';
        }
    }
}
T::bulk(count($phpFiles), $bad, 'هیچ متغیری بی‌آکولاد به کاراکتر فارسی نچسبیده');

// ---------------------------------------------------------------
T::group('قاعده ۷ — نشستِ منقضی نباید بن‌بست بسازد');

// چرا: `Csrf::verifyOrFail` قبلاً روی توکنِ نامعتبر فقط `die()` با یک
// رشته‌ی خام می‌زد. کاربر روی گوشی یک صفحه‌ی سفید با یک خط متنِ بی‌قالب
// و بدون هیچ دکمه‌ای می‌دید. شایع‌ترین علتش هم حمله نبود: فرمی که
// ساعت‌ها باز مانده و نشست در این فاصله منقضی شده.
$csrfSrc = (string)file_get_contents(__DIR__ . '/../includes/csrf.php');

T::ok(
    !preg_match('/\bdie\s*\(\s*[\'"]/', $csrfSrc),
    'خطای CSRF با die خام پاسخ نمی‌دهد'
);
T::ok(str_contains($csrfSrc, '<!DOCTYPE html>'), 'صفحه‌ی خطای CSRF قالب کامل دارد');
T::ok(str_contains($csrfSrc, 'history.back()'), 'صفحه‌ی خطای CSRF راه برگشت دارد');

// صفحه‌ی ورود باید توکنِ کهنه را ببخشد و فرم را دوباره نشان دهد،
// نه اینکه کاربر را در صفحه‌ی خطا حبس کند — همان‌جا که بیشتر از هر جای
// دیگری فرم ساعت‌ها باز می‌ماند.
$loginSrc = (string)file_get_contents(__DIR__ . '/../login.php');
T::ok(
    str_contains($loginSrc, 'Csrf::validate') && !str_contains($loginSrc, 'Csrf::verifyOrFail'),
    'صفحه‌ی ورود با توکن کهنه فرم را دوباره نشان می‌دهد'
);

// مهلت را اپ تعیین می‌کند، نه پیش‌فرضِ ۲۴ دقیقه‌ایِ PHP. بدون این،
// گزینه‌ی «تا یک هفته وارد بمانم» در پروفایل عملاً دروغ بود.
$authSrc = (string)file_get_contents(__DIR__ . '/../includes/auth.php');
T::ok(
    preg_match('/session\.gc_maxlifetime/', $authSrc) === 1,
    'مهلت جمع‌آوری نشست با سیاست خود اپ تنظیم می‌شود'
);

// ---------------------------------------------------------------
T::group('قاعده ۸ — deploy.sh پوشه‌های نوشتنی را به کاربر اپ برمی‌گرداند');

// چرا: deploy.sh عمداً `chown -R root:root .` می‌زند تا کد را از دست
// PHP دور نگه دارد. ولی دو پوشه باید نوشتنی بمانند و بعد از آن دوباره
// به کاربر اپ برگردند: `uploads` و `var/sessions`.
//
// یک بار `var` جا افتاده بود و باگی ساخت که هیچ ردی در لاگ نداشت:
// مسیر نشستِ این pool داخل خود پوشه‌ی اپ است، پس PHP دیگر نمی‌توانست
// فایل نشست بنویسد. هر درخواست یک نشستِ خالیِ تازه می‌گرفت، توکن CSRF
// هرگز نمی‌ماند، و صفحه‌ی ورود در حلقه‌ی «نشست شما منقضی شده بود» گیر
// می‌کرد — هیچ‌کس نمی‌توانست وارد شود.
// ⛔ منطقِ استقرار از `deploy.sh` به `hesabland` منتقل شد، پس این قاعده
//    هم باید همان‌جا را بسنجد. `deploy.sh` حالا فقط یک پوسته است و
//    **نباید** نسخه‌ی دومی از این منطق داشته باشد: با دو نسخه، اولین
//    اصلاحی که فقط به یکی برسد همان باگِ بی‌صدای بالا را برمی‌گرداند.
$deploySrc = (string)@file_get_contents(__DIR__ . '/../hesabland');
T::ok($deploySrc !== '', 'فایلِ hesabland پیدا شد');

// ⚠ کامنت‌های تمام‌خطی باید **پیش از** بررسی حذف شوند — همان دامی که
//    قاعده ۳۵ و ۱۹ و ۳۸ هم در آن افتادند. سرآیندِ همین فایل عبارتِ
//    `-H "Host:"` و واژه‌ی `--noproxy` را در توضیحاتش دارد، پس بررسی روی
//    سورسِ خام هم روی فایلِ **سالم** قرمز می‌شد هم جهشِ «--noproxy را
//    بردار» را زنده نگه می‌داشت. فقط خطِ کاملِ کامنت حذف می‌شود، نه هر
//    `#`، وگرنه `${url#http://}` هم قربانی می‌شد.
$deployCode = implode("\n", array_filter(
    explode("\n", $deploySrc),
    static fn($ln) => !preg_match('/^\s*#/', $ln)
));
if ($deploySrc !== '') {
    $chownAt = strpos($deploySrc, 'chown -R root:root');
    T::ok($chownAt !== false, 'hesabland مالکیت کد را به root می‌دهد');

    // هر دو باید *بعد از* آن خط دوباره به کاربر اپ برگردند
    foreach (['uploads', 'var'] as $dir) {
        $restoreAt = strpos($deploySrc, 'chown -R "$APP_USER":"$APP_USER" ' . $dir);
        T::ok(
            $restoreAt !== false && $chownAt !== false && $restoreAt > $chownAt,
            "«{$dir}» بعد از chown سراسری به کاربر اپ برمی‌گردد"
        );
    }

    T::ok(
        str_contains($deploySrc, 'test -w var/sessions'),
        'نوشتنی بودن var/sessions واقعاً آزموده می‌شود، نه فرض'
    );
    T::ok(
        str_contains($deploySrc, 'test -r config/config.php'),
        'خواندنی ماندنِ config.php هم واقعاً آزموده می‌شود'
    );

    // ⛔ سنجشِ سلامت باید به 127.0.0.1 بخورد و پراکسیِ محیط را دور بزند،
    //    وگرنه curl مقدارِ --resolve را نادیده می‌گیرد و پاسخ از جای
    //    دیگری می‌آید — سنجشی که درباره‌ی چیزِ دیگری حرف می‌زند.
    T::ok(
        str_contains($deployCode, '--resolve') && !str_contains($deployCode, '-H "Host:'),
        'سنجشِ سلامت با --resolve است نه -H "Host:"'
    );
    T::ok(
        str_contains($deployCode, "--noproxy"),
        'سنجشِ سلامت پراکسیِ محیط را دور می‌زند'
    );
    T::ok(
        str_contains($deployCode, '*"</html>"*'),
        'سنجه‌ی «رندر تمام شد» وجودِ </html> است، نه طولِ بدنه'
    );

    // ⛔ باگِ واقعی: `cmd_deploy` تابع را داخلِ `if` صدا می‌زند و bash
    //    آنجا کلِ بدنه را از `set -e` معاف می‌کند. بدونِ این `|| return`،
    //    لو رفتنِ سورسِ خامِ api/v1 گزارش می‌شد ولی استقرار **سبز** اعلام
    //    می‌شد. با سرورِ HTTPS واقعی بازتولید شد.
    T::ok(
        (bool)preg_match('/api_ping_check\s+"\$base"\s+"\$resolve"\s+"\$quiet"\s*\|\|\s*return\s+1/', $deployCode),
        '⛔ شکستِ api_ping_check واقعاً به health_check منتقل می‌شود'
    );

    // ⛔ کدِ ورودی پیش از `git reset --hard` سنجیده می‌شود، نه بعدش:
    //    با فرود آمدن، opcache همان لحظه می‌بیندش و سایت پیش از هر
    //    سنجشی خوابیده است.
    // ⚠ هدف از staging به بعد `$TARGET` است (پیش‌فرض origin/BRANCH، در release
    //   همان کامیتِ دیده‌شده) — ترتیب همان است: اول نحو، بعد فرود.
    $preflightAt = strpos($deployCode, 'preflight_syntax "$TARGET"');
    $landAt      = strpos($deployCode, 'git reset --hard "$TARGET"');
    T::ok(
        $preflightAt !== false && $landAt !== false && $preflightAt < $landAt,
        '⛔ نحوِ کدِ ورودی **پیش از** نشستنش روی دیسک سنجیده می‌شود'
    );

    // ⛔ برگشتِ خودکار وقتی migration اعمال شده ممنوع است: کدِ قدیمی روی
    //    ساختارِ تازه یک خرابیِ دیگر است، نه رفعِ خرابی.
    T::ok(
        str_contains($deployCode, 'if (( migrated )); then'),
        '⛔ بعد از اعمالِ migration برگشتِ خودکار انجام نمی‌شود'
    );

    // ⛔ بالا بردنِ VERSION در sw.js از ابزارِ استقرار ممنوع است: کلِ کشِ
    //    سرویس‌ورکر را پاک می‌کند و همان «تورِ نجاتِ نسخه‌ی قبلیِ فایل»
    //    را می‌برد که برای صفحه‌ی بی‌جان نوشته شد.
    T::ok(
        !preg_match('/sed[^\n]*sw\.js|VERSION\s*=\s*.daftar-v/', $deployCode),
        '⛔ ابزارِ استقرار به VERSION در sw.js دست نمی‌زند'
    );

    // ⛔ و بیتِ اجرای **خودش** را برمی‌گرداند — این یک خرابیِ واقعی روی
    //    سرور بود، نه یک احتیاط. تنظیمِ دسترسی‌ها همه‌ی فایل‌ها را ۶۴۴
    //    می‌کند و بعد فقط `*.sh` را ۷۵۵ برمی‌گرداند؛ `hesabland` پسوند
    //    ندارد، پس در استقرارِ گذار غیرِاجرایی روی دیسک نشست و
    //    `sudo ./hesabland` جواب داد **«command not found»** — یعنی
    //    پیامی که می‌گوید «فایل نیست» برای فایلی که هست. (sudo برای
    //    فایلِ غیرِاجرایی هم همین را می‌گوید.)
    //    ⚠ همان دامِ globِ `*.sh` در قاعده ۳۶: فایلِ بی‌پسوند بی‌صدا
    //      بیرونِ الگو می‌ماند.
    T::ok(
        (bool)preg_match('/chmod\s+755\s+hesabland/', $deployCode),
        '⛔ ابزارِ استقرار بیتِ اجرای خودش را برمی‌گرداند (glob `*.sh` آن را نمی‌گیرد)'
    );
}

// ⛔ و پوسته باید پوسته بماند.
$wrapSrc = (string)@file_get_contents(__DIR__ . '/../deploy.sh');
T::ok(
    str_contains($wrapSrc, 'hesabland'),
    'deploy.sh به hesabland واگذار می‌کند (بوکمارک و مستندات نمی‌شکنند)'
);
T::ok(
    !str_contains($wrapSrc, 'chown -R root:root'),
    '⛔ deploy.sh نسخه‌ی دومی از منطقِ استقرار ندارد'
);
// ⚠ استقرار خودِ deploy.sh را با git reset بازنویسی می‌کند و bash فایل را
//   تکه‌تکه می‌خواند؛ با بدنه‌ی تخت، ادامه‌ی اجرا از همان آفست در فایلِ
//   **تازه** خوانده می‌شود. پس بدنه باید داخلِ یک تابع باشد و فراخوانی‌اش
//   آخرین خط، تا bash پیش از اجرا کلِ فایل را خوانده باشد.
T::ok(
    (bool)preg_match('/^main\s+"\$@"\s*$/m', $wrapSrc),
    'deploy.sh بدنه‌اش را در تابع نگه می‌دارد و آخرِ فایل صدایش می‌زند'
);

// ---------------------------------------------------------------
T::group('قاعده ۹ — api/v1 هویت را از توکن می‌گیرد، نه از نشست');

// اپی که روی گوشی کاربر نصب شده را نمی‌شود مجبور به به‌روزرسانی کرد.
// اگر یک هندلر در v1 بررسی هویت را جا بیندازد، داده‌ی یک کاربر به دست
// دیگری می‌رسد و تا وقتی همه اپشان را عوض نکنند قابل جبران نیست.

$v1Files = glob($root . '/api/v1/routes/*.php');
T::ok(count($v1Files) > 0, 'فایل‌های مسیر v1 پیدا شدند', 'تعداد: ' . count($v1Files));

// فقط این سه عمداً بی‌نیاز از توکن‌اند: ping برای سنجش دسترسی، login که
// خودش توکن می‌سازد، و `sms/claim` که کلیدِ پیامک را فقط با کدِ یک‌بارمصرفی
// می‌دهد که صفحه‌ی **واردشده** به کاربرِ نشست بسته است (`SmsSync::claim`).
$openHandlers = ['v1Ping', 'v1AuthLogin', 'v1SmsClaim'];

$missing = [];
$handlers = 0;
foreach ($v1Files as $f) {
    $src = (string)file_get_contents($f);
    // بدنه‌ی هر تابع v1... را جدا می‌کنیم تا بررسی هر کدام مستقل باشد
    preg_match_all('/function\s+(v1\w+)\s*\([^)]*\)\s*:\s*void\s*\{/', $src, $m, PREG_OFFSET_CAPTURE);
    foreach ($m[1] as $i => $hit) {
        $name = $hit[0];
        $handlers++;
        if (in_array($name, $openHandlers, true)) { continue; }

        $start = $m[0][$i][1];
        $end   = isset($m[0][$i + 1]) ? $m[0][$i + 1][1] : strlen($src);
        $body  = substr($src, $start, $end - $start);

        // ⛔ `requireUser('sms')` فقط در `sms.php`: کلیدِ محدودِ اپ (`scope = sms`)
        //    همان مسیرها را باز می‌کند و هر جای دیگر `requireUser()`ِ بی‌آرگومان
        //    یعنی «فقط کلیدِ کامل» — مسیرِ تازه‌ای که دامنه را فراموش کند بسته است.
        $ok = str_contains($body, 'Api::requireUser()')
           || (basename($f) === 'sms.php' && str_contains($body, "Api::requireUser('sms')"));
        if (!$ok) {
            $missing[] = basename($f) . " › {$name} توکن را نمی‌سنجد";
        }
    }
}
T::bulk($handlers, $missing, 'هر هندلر v1 هویت را می‌سنجد');

// ⛔ کلیدِ محدود واقعاً محدود است: پیش‌فرضِ `requireUser()` «فقط کامل».
$apiSrc = (string)@file_get_contents($root . '/includes/api.php');
T::ok((bool)preg_match('~function requireUser\(\?string \$scope = null\)~', $apiSrc)
      && (bool)preg_match('~\$has !== null && \$has !== \$scope~', $apiSrc),
    '⛔ کلیدِ `sms` جز مسیرِ پیامک را باز نمی‌کند (پیش‌فرضِ requireUser = کلیدِ کامل)');

// شناسه‌ی کاربر باید از توکن بیاید. Auth::userId() نشست را می‌خواند و در
// درخواستِ API همیشه null است — استفاده‌اش یعنی باگِ خاموش.
// ⚠ کامنت‌ها با توکنایزر حذف می‌شوند. بدون این، همین قاعده روی
// توضیحی که *چرا نباید* از Auth::userId() استفاده کرد شکست می‌خورد —
// یک بار همین‌جا واقعاً اتفاق افتاد.
$stripComments = static function (string $file): string {
    $out = '';
    foreach (token_get_all((string)file_get_contents($file)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
};

$sessionUse = [];
foreach (array_merge($v1Files, [$root . '/api/v1/index.php']) as $f) {
    $src = $stripComments($f);
    if (str_contains($src, 'Auth::userId()')) {
        $sessionUse[] = basename($f) . ' از Auth::userId() استفاده می‌کند';
    }
    // cachedCategories() هم کاربر را از نشست می‌گیرد
    if (str_contains($src, 'cachedCategories(')) {
        $sessionUse[] = basename($f) . ' از cachedCategories() استفاده می‌کند (وابسته به نشست)';
    }
}
T::bulk(count($v1Files) + 1, $sessionUse, 'v1 به نشست وابسته نیست');

// پاکت پاسخ باید یک شکل بماند؛ مشتری روی همین حساب می‌کند.
$apiSrc = (string)file_get_contents($root . '/includes/api.php');
T::ok(str_contains($apiSrc, "'ok' => true") && str_contains($apiSrc, "'ok' => false"),
      'پاکت پاسخ کلید ok دارد');
T::ok(str_contains($apiSrc, "header('X-API-Version"), 'نسخه در سرآیند پاسخ می‌آید');

// ---------------------------------------------------------------
T::group('قاعده ۱۰ — قاعده‌ی nginx برای api/v1 هرگز ^~ ندارد');

// ⚠ این از یک خرابیِ واقعی روی سرور درآمد.
//
// `^~` در nginx یعنی «اگر این prefix برنده شد، دیگر location های regex را
// نگاه نکن». `location ~ \.php$` هم یک regex است — پس با ^~ هرگز اجرا
// نمی‌شد و nginx فایل PHP را به‌جای اجرا، خام تحویل می‌داد:
// /api/v1/ping متنِ کاملِ index.php را برمی‌گرداند و API اصلاً کار نمی‌کرد.
//
// `nginx -t` سبز بود و reload هم موفق — یعنی هیچ ابزاری جلویش را نگرفت.
// این قاعده جلویش را می‌گیرد.
$nginxSources = [
    $root . '/deploy/nginx-api.sh',
    $root . '/deploy/vps-setup.sh',
];
$offenders = [];
foreach ($nginxSources as $f) {
    if (!file_exists($f)) { continue; }
    foreach (explode("\n", (string)file_get_contents($f)) as $n => $line) {
        // خطِ کامنت به حساب نمی‌آید — توضیحِ «هرگز ^~ نگذارید» خودش
        // نباید قاعده را بشکند (همان تله‌ای که در قاعده ۹ خوردیم).
        $code = trim($line);
        if ($code === '' || str_starts_with($code, '#')) { continue; }
        // فقط خطی که **خودش یک directive است** شمرده می‌شود، نه هر
        // خطی که نامش را برده. یک `red "... location ^~ /api/v1/"` که
        // دارد همین ایراد را *گزارش* می‌کند، خودش ایراد نیست — بار سوم
        // بود که این تله را می‌خوردیم. گیومه‌ی ابتدای خط مجاز است چون
        // بلوک در پایتون به شکل '    location ...' نوشته می‌شود.
        if (preg_match('/^\s*[\x27"]?\s*location\s+\^~\s*\/api\/v1/', $line)) {
            $offenders[] = basename($f) . ' خط ' . ($n + 1) . ': ^~ روی /api/v1/';
        }
    }
}
T::bulk(count($nginxSources), $offenders, 'هیچ‌کدام از اسکریپت‌های nginx روی api/v1 از ^~ استفاده نمی‌کنند');

// و قاعده باید واقعاً وجود داشته باشد، وگرنه تست بالا الکی سبز می‌ماند
$apiSh = (string)@file_get_contents($root . '/deploy/nginx-api.sh');
T::ok((bool)preg_match('/location\s+\/api\/v1\/\s*\{/', $apiSh),
      'قاعده‌ی prefix ساده‌ی /api/v1/ در اسکریپت هست');
T::ok(str_contains($apiSh, '/api/v1/ping'),
      'اسکریپت بعد از اعمال، خودِ اندپوینت را می‌سنجد');

// اگر سروری قاعده‌ی معیوب داشته باشد، اسکریپت باید ترمیمش کند — نه
// اینکه «از قبل هست» ببیند و بی‌سروصدا رد شود. نسخه‌ی اول همین کار را
// می‌کرد و سرورِ خرابْ خراب می‌ماند.
T::ok(str_contains($apiSh, 'MODE="repair"'),
      'اسکریپت قاعده‌ی معیوبِ ^~ را ترمیم می‌کند، نه اینکه ردش کند');

// سنجش باید با --resolve باشد: با -H "Host: ..." روی https://127.0.0.1
// هیچ SNI ای نمی‌رود و nginx ممکن است سایتِ دیگری را جواب بدهد. یک بار
// همین باعث شد اسکریپت پیکربندیِ درست را برگرداند.
T::ok(str_contains($apiSh, '--resolve'),
      'سنجشِ اسکریپت با --resolve انجام می‌شود نه با Host دستی');

// reload غیرهمزمان است: کارگرهای قدیمی تا ~۱۵۰ms با پیکربندی قبلی جواب
// می‌دهند. سنجشِ فوری، پیکربندیِ درست را «خراب» می‌دید و برمی‌گرداند.
T::ok((bool)preg_match('/for\s+_?\w*\s+in\s+\$\(seq/', $apiSh),
      'سنجش بعد از reload تکرار می‌شود، نه یک بار');

// تورِ نجاتِ اپ: ?p= باید همیشه کار کند، حتی بدون قاعده‌ی nginx
$routerSrc = (string)@file_get_contents($root . '/api/v1/index.php');
T::ok(str_contains($routerSrc, "\$_GET['p']"),
      'روتر شکل ?p= را هم می‌فهمد (کار می‌کند حتی بدون قاعده‌ی nginx)');

// ---------------------------------------------------------------
T::group('قاعده ۱۱ — حاشیه‌ی امنِ آیفون بی‌اثر نماند');

// ⚠ یک باگِ خاموشِ واقعی: style.css در پنج جا از env(safe-area-inset-*)
// استفاده می‌کند، ولی iOS این مقادیر را **فقط** وقتی می‌دهد که متای
// viewport شامل `viewport-fit=cover` باشد. بدون آن همه‌ی این محاسبه‌ها
// صفر می‌شوند و هیچ خطایی هم دیده نمی‌شود — فقط نوار پایین زیر خطِ
// خانه می‌رود و عنوان صفحه زیر ناچ.
$css = (string)file_get_contents($root . '/assets/css/style.css');
$usesSafeArea = str_contains($css, 'env(safe-area-inset');

$pages = ['includes/header.php', 'login.php', 'setup.php',
          'forgot-password.php', 'reset-password.php',
          // محیطِ فروشگاهی سرآیندِ خودش را دارد (store.css هم env() دارد)
          'includes/biz_head.php', 'store/login.php'];
$missing = [];
foreach ($pages as $rel) {
    $f = $root . '/' . $rel;
    if (!file_exists($f)) { continue; }
    $src = (string)file_get_contents($f);
    if (!preg_match('/<meta\s+name="viewport"[^>]*>/i', $src, $m)) {
        $missing[] = "$rel متای viewport ندارد";
        continue;
    }
    if (!str_contains($m[0], 'viewport-fit=cover')) {
        $missing[] = "$rel متای viewport بدون viewport-fit=cover";
    }
}

if ($usesSafeArea) {
    T::bulk(count($pages), $missing,
            'هر صفحه‌ای که سرآیند خودش را دارد viewport-fit=cover دارد');
} else {
    T::pass('CSS از safe-area استفاده نمی‌کند — این قاعده موضوعیت ندارد');
}

// نوار بالا باید حاشیه‌ی امنِ بالا را حساب کند، وگرنه زیر Dynamic Island
// می‌رود. (status-bar-style=black-translucent عمدی است و وب‌ویو را تا
// بالای صفحه می‌کشد.)
T::ok(str_contains($css, 'env(safe-area-inset-top)'),
      'نوار بالای صفحه حاشیه‌ی امنِ بالا را حساب می‌کند');

// ---------------------------------------------------------------
T::group('قاعده ۱۲ — پیوندِ اپ اندروید با سایت نشکند');

// اپ اندروید یک پوسته‌ی TWA است و «تأیید»ش به سه چیزِ به‌هم‌گره‌خورده
// بند است: نامِ بسته، اثر انگشتِ کلید، و در دسترس بودنِ assetlinks.json
// روی دامنه. اگر یکی عوض شود و بقیه نه، اپ **نصب می‌شود ولی با نوار
// آدرس بالا** — یعنی خرابیِ کاملاً بی‌صدا: ساخت سبز است، نصب موفق است،
// و فقط کاربر می‌فهمد.
//
// اثر انگشت را خودِ workflow بعد از ساخت با APK می‌سنجد (اینجا APK ای
// نداریم). چیزی که اینجا سنجیده می‌شود هم‌خوانیِ خودِ فایل‌هاست.

$alPath = __DIR__ . '/../.well-known/assetlinks.json';
$gradlePath = __DIR__ . '/../mobile/app/build.gradle.kts';

if (!file_exists($gradlePath)) {
    T::pass('اپ اندروید در مخزن نیست — این قاعده موضوعیت ندارد');
} else {
    T::ok(file_exists($alPath), '.well-known/assetlinks.json وجود دارد');

    $al = json_decode((string)file_get_contents($alPath), true);
    T::ok(is_array($al) && isset($al[0]['target']), 'assetlinks.json ساختار درستی دارد');

    $gradle = (string)file_get_contents($gradlePath);
    preg_match('/applicationId\s*=\s*"([^"]+)"/', $gradle, $mApp);
    preg_match('/"hostName"\]\s*=\s*"([^"]+)"/', $gradle, $mHost);
    preg_match('/"launchUrl"\]\s*=\s*"([^"]+)"/', $gradle, $mUrl);

    T::ok(($mApp[1] ?? '') !== '' && ($mApp[1] ?? '') === ($al[0]['target']['package_name'] ?? ''),
          'نام بسته در gradle و assetlinks یکی است');

    // ⛔ و نامِ بسته در **پنج** جای دیگر هم تکرار شده. عوض کردنِ برند یک
    //    بار انجام می‌شود و هر جای جامانده یک خرابیِ **بی‌صدا**ی جدا
    //    می‌سازد:
    //      • پوشه‌ی سورس و خطِ `package` → گریدل ممکن است بسازد ولی
    //        `R` و کلاس‌ها سرِ جای اشتباه می‌نشینند.
    //      • `action` در manifest و لینکِ `intent://` در `profile.php` →
    //        دکمه‌ی «تنظیم در اپ اندروید» زده می‌شود و **هیچ اتفاقی
    //        نمی‌افتد**؛ نه خطایی، نه صفحه‌ای.
    //      • `package=` داخلِ همان لینک → اندروید اپ را پیدا نمی‌کند.
    //    هیچ‌کدام هنگام ساخت دیده نمی‌شوند، پس اینجا سنجیده می‌شوند.
    $appId  = $mApp[1] ?? '';
    $mobile = __DIR__ . '/../mobile/app/src/main/';
    $pkgBad = [];

    if ($appId !== '') {
        // ۱) `namespace` باید با `applicationId` یکی باشد.
        preg_match('/namespace\s*=\s*"([^"]+)"/', $gradle, $mNs);
        if (($mNs[1] ?? '') !== $appId) {
            $pkgBad[] = 'build.gradle.kts — namespace با applicationId یکی نیست';
        }

        // ۲) پوشه‌ی سورس و خطِ `package` هر فایلِ جاوا.
        $pkgDir = $mobile . 'java/' . str_replace('.', '/', $appId);
        if (!is_dir($pkgDir)) {
            $pkgBad[] = 'پوشه‌ی سورسِ جاوا با نامِ بسته نمی‌خواند: java/'
                      . str_replace('.', '/', $appId);
        }
        foreach (glob($mobile . 'java/*/*/*/*.java') ?: [] as $j) {
            if (!preg_match('/^\s*package\s+([A-Za-z0-9_.]+)\s*;/m',
                            (string)file_get_contents($j), $mPkg)
                || $mPkg[1] !== $appId) {
                $pkgBad[] = basename($j) . ' — خطِ package با نامِ بسته نمی‌خواند';
            }
        }

        // ۳) `action` صفحه‌ی تنظیمِ پیامک و لینکِ `intent://` در سایت.
        $mf   = (string)@file_get_contents($mobile . 'AndroidManifest.xml');
        $prof = (string)@file_get_contents(__DIR__ . '/../profile.php');
        $act  = $appId . '.SMS_SETUP';
        if ($mf !== '' && !str_contains($mf, $act)) {
            $pkgBad[] = "AndroidManifest — action «{$act}» نیست";
        }
        if ($prof !== '' && str_contains($prof, 'intent://')) {
            if (!str_contains($prof, 'action=' . $act)) {
                $pkgBad[] = 'profile.php — action لینکِ intent با نامِ بسته نمی‌خواند';
            }
            if (!str_contains($prof, 'package=' . $appId)) {
                $pkgBad[] = 'profile.php — package لینکِ intent با نامِ بسته نمی‌خواند';
            }
        }
    }
    T::bulk(6, $pkgBad, 'نامِ بسته در همه‌ی نقطه‌های وابسته یکی است');

    // ⛔ مسیرِ گرفتنِ مجوز — و هر چهار بندش یک خرابیِ **بی‌صدا** را
    //    می‌بندد. هیچ‌کدام هنگام ساخت یا نصب دیده نمی‌شوند؛ اپ بالا
    //    می‌آید و فقط «کار نمی‌کند».
    $permBad = [];
    $mf   = (string)@file_get_contents($mobile . 'AndroidManifest.xml');
    $setup = (string)@file_get_contents($mobile . 'java/'
                    . str_replace('.', '/', $appId) . '/SmsSetupActivity.java');

    // ۱) `POST_NOTIFICATIONS` هم باید اعلام شود. بدونش روی اندروید ۱۳
    //    به بالا پیامک خوانده می‌شود، `notify()` بی‌خطا اجرا می‌شود، و
    //    **هیچ اعلانی دیده نمی‌شود**.
    //
    // ⛔ و `READ_SMS` هم از امروز در همین فهرست است — این قاعده یک بار
    //    **برعکس** بود («READ_SMS لازم نیست و نباید خواسته شود») و عمداً
    //    برگشت. علتش این است که `RECEIVE_SMS` فقط پیامکِ **تازه‌رسیده**
    //    را می‌دهد: هر چیزی که پیش از نصبِ اپ، یا پشتِ محدودیتِ رام، یا
    //    وقتی کلید خاموش بود آمده باشد برای همیشه از دست می‌رفت. بدونِ
    //    این مجوز هیچ راهی برای جبرانش نبود.
    foreach (['android.permission.RECEIVE_SMS',
              'android.permission.READ_SMS',
              'android.permission.POST_NOTIFICATIONS'] as $p) {
        if (!str_contains($mf, $p)) { $permBad[] = "AndroidManifest — «{$p}» اعلام نشده"; }
    }

    // ۲) ⛔ و **هیچ مجوزِ خطرناکِ دیگری**. درخواستِ مالکِ نصب «تمام
    //    اجازه‌های سخت‌افزاری» بود، ولی هدفش را خودش گفت: «که اپ بتواند
    //    به پیامک‌ها دسترسی کامل داشته باشد». دوربین، موقعیت مکانی،
    //    میکروفون، مخاطبین، حافظه و تماس هیچ‌کدام به آن هدف ربطی ندارند
    //    و قاعده‌ی خودِ پروژه صریح است: «مجوزی که لازم نیست نباید خواسته
    //    شود».
    //
    //    این فهرست همان تصمیم را **پین** می‌کند، وگرنه اولین «فقط برای
    //    اینکه بعداً لازم نشود» آن را بی‌صدا باز می‌کرد — و هزینه‌اش روی
    //    کاربر می‌نشیند (دیالوگِ ترسناک) و روی فروشگاه (ردِ بازبینی).
    //    `SEND_SMS` جدای بقیه هم بد است: هزینه‌ی واقعیِ پول دارد.
    foreach (['CAMERA', 'RECORD_AUDIO', 'ACCESS_FINE_LOCATION',
              'ACCESS_COARSE_LOCATION', 'READ_CONTACTS', 'WRITE_CONTACTS',
              'READ_EXTERNAL_STORAGE', 'WRITE_EXTERNAL_STORAGE',
              'READ_MEDIA_', 'READ_CALL_LOG', 'READ_PHONE_STATE',
              'SEND_SMS', 'WRITE_SMS'] as $p) {
        if (str_contains($mf, 'android.permission.' . $p)) {
            $permBad[] = "AndroidManifest — «{$p}» به پیامک ربطی ندارد و نباید خواسته شود";
        }
    }

    // ۳) `MAIN`/`LAUNCHER` باید روی `LauncherActivity` باشد و روی
    //    `SmsSetupActivity` **نباشد**. یک بار جابه‌جا شد (برای پرسیدنِ
    //    مجوز در اولین اجرا) و پس داده شد: هزینه‌اش را **هر** بار باز
    //    کردنِ اپ می‌داد — یک پنجره‌ی اضافه پیش از کروم، و کاربر «چند
    //    بار رفرش شدن و صفحه‌ی سفید» می‌دید. مجوز یک بار پرسیده می‌شود،
    //    اپ هزار بار باز می‌شود.
    // ⚠ و آن `LauncherActivity` حالا زیرکلاسِ خودمان است
    //   (`.HesabLauncherActivity`)، نه کلاسِ خامِ کتابخانه — پایین‌تر.
    if (preg_match('~<activity\b[^>]*\.HesabLauncherActivity".*?</activity>~s', $mf, $mLau)) {
        if (!str_contains($mLau[0], 'android.intent.category.LAUNCHER')) {
            $permBad[] = 'AndroidManifest — LauncherActivity دیگر LAUNCHER نیست؛'
                       . ' اپ یک پنجره‌ی اضافه پیش از کروم باز می‌کند';
        }
    } else {
        $permBad[] = 'AndroidManifest — بلوکِ HesabLauncherActivity پیدا نشد';
    }
    if (preg_match('~<activity\b[^>]*\.SmsSetupActivity.*?</activity>~s', $mf, $mAct)) {
        if (str_contains($mAct[0], 'android.intent.category.LAUNCHER')) {
            $permBad[] = 'AndroidManifest — SmsSetupActivity نباید LAUNCHER باشد؛'
                       . ' درِ ورودی کردنش هر بار یک پنجره‌ی اضافه می‌سازد';
        }
        // ۴) و `noHistory` روی همان اکتیویتی یعنی هنگام بالا آمدنِ
        //    دیالوگِ مجوز `finish` می‌شود و `onRequestPermissionsResult`
        //    هرگز نمی‌رسد: کاربر «اجازه» را می‌زند و هیچ اتفاقی نمی‌افتد.
        if (str_contains($mAct[0], 'noHistory')) {
            $permBad[] = 'AndroidManifest — noHistory روی SmsSetupActivity'
                       . ' پاسخِ دیالوگِ مجوز را از بین می‌برد';
        }
    } else {
        $permBad[] = 'AndroidManifest — بلوکِ SmsSetupActivity پیدا نشد';
    }

    // ۵) و خودِ کد باید واقعاً هر دو را بخواهد. «در manifest نوشته شده»
    //    با «از کاربر خواسته می‌شود» یکی نیست — همان درسِ nginx.
    if ($setup === '') {
        $permBad[] = 'SmsSetupActivity.java پیدا نشد';
    } else {
        // ⚠ کامنت‌ها **پیش از** جست‌وجو حذف می‌شوند، وگرنه این بررسی
        //   پوچ است: توضیحاتِ همین فایل نامِ هر دو مجوز را دارند، پس با
        //   جست‌وجوی خام، برداشتنِ کاملِ درخواستِ مجوز هم سبز می‌ماند.
        //   با آزمونِ جهش دیده شد — نسخه‌ی اول دقیقاً همین‌طور بود.
        $setupCode = preg_replace('~/\*.*?\*/~s', '', $setup);
        // `//` فقط وقتی کامنت است که بخشی از `://` نباشد.
        $setupCode = preg_replace('~(?<!:)//[^\n]*~', '', (string)$setupCode);

        // ⚠ «نامِ مجوز جایی در فایل هست» **پوچ** است و جهش نشانش داد:
        //   هر سه نام در جاهای دیگرِ همین فایل هم می‌آیند (سنجشِ
        //   `checkSelfPermission`، دکمه‌ی گرفتنِ مجوز)، پس با برداشتنِ
        //   کاملِ یکی از آن‌ها از خودِ `requestPermissions(…)` هم تست
        //   سبز می‌ماند. پس فقط **آرگومان‌های همان فراخوانی** گشته
        //   می‌شوند.
        //   ⚠ و «جمعِ همه‌ی فراخوانی‌ها» هم **پوچ** بود، با جهش دیده شد:
        //     `askReadOrOpenSettings()` خودش یک `requestPermissions(…
        //     READ_SMS …, REQ_READ)` دارد، پس با برداشتنِ `READ_SMS` از
        //     مسیرِ **کلید** (`REQ_SMS`) باز هم سبز می‌ماند — یعنی کاربری
        //     که کلید را روشن می‌کند دو دیالوگِ جدا می‌گیرد یا اصلاً
        //     مجوزِ صندوق را نمی‌گیرد. پس هر فراخوانی با **کدِ خودش**
        //     شناخته می‌شود و مسیرِ کلید جدا سنجیده می‌شود.
        $reqByCode = [];
        if (preg_match_all(
            '~requestPermissions\s*\(\s*(.*?),\s*(REQ_[A-Za-z_]+)\s*\)\s*;~s',
            (string)$setupCode,
            $mReq,
            PREG_SET_ORDER
        )) {
            foreach ($mReq as $m) {
                $reqByCode[$m[2]] = ($reqByCode[$m[2]] ?? '') . ' ' . $m[1];
            }
        }
        if ($reqByCode === []) {
            $permBad[] = 'SmsSetupActivity — هیچ درخواستِ مجوزی در کار نیست';
        } else {
            // مسیرِ روشن کردنِ کلید باید **هر دو** مجوزِ پیامک را با هم
            // بخواهد: `RECEIVE_SMS` برای پیامکِ تازه و `READ_SMS` برای
            // صندوق.
            $toggle = $reqByCode['REQ_SMS'] ?? '';
            if ($toggle === '') {
                $permBad[] = 'SmsSetupActivity — درخواستِ مجوزِ مسیرِ کلید (REQ_SMS) پیدا نشد';
            } else {
                foreach (['RECEIVE_SMS', 'READ_SMS'] as $p) {
                    if (!str_contains($toggle, $p)) {
                        $permBad[] = "SmsSetupActivity — «{$p}» در مسیرِ روشن کردنِ کلید خواسته نمی‌شود";
                    }
                }
            }
            $anyAsk = implode(' | ', $reqByCode);
            if (!str_contains($anyAsk, 'POST_NOTIFICATIONS')) {
                $permBad[] = 'SmsSetupActivity — «POST_NOTIFICATIONS» در زمانِ اجرا خواسته نمی‌شود';
            }
        }

        // ⛔ و چون همین اکتیویتی **درِ ورودیِ اپ** است، دو چیز روی آن
        //    اجباری‌اند — هر دو با یک خرابیِ واقعی خریداری شده‌اند:
        //    اپ نصب شد و با زدنِ آیکون بسته می‌شد، بی‌هیچ توضیحی.
        //
        //    ۱. `AppCompatActivity` باشد نه `android.app.Activity`ِ خام:
        //       تمش `Theme.AppCompat` است و همه‌ی ویجت‌ها در کد ساخته
        //       می‌شوند، پس آن جفت باید همخوان بماند.
        //    ۲. `onCreate` یک گاردِ `Throwable` داشته باشد: بدونش،
        //       کاربری که «تنظیم در اپ اندروید» را می‌زند فقط یک اپِ
        //       بسته‌شده می‌بیند و هیچ راهی برای فهمیدنِ علتش ندارد.
        if (!str_contains((string)$setupCode, 'extends AppCompatActivity')) {
            $permBad[] = 'SmsSetupActivity — با تمِ AppCompat باید AppCompatActivity باشد';
        }
        // ⚠ گارد باید داخلِ **خودِ `onCreate`** باشد، نه هر جای فایل.
        //   نسخه‌ی اول فقط دنبالِ رشته‌ی `catch (Throwable` در کلِ فایل
        //   می‌گشت و یک `catch (Throwable ignored)` در جای دیگری سبزش
        //   می‌کرد — با آزمونِ جهش دیده شد.
        $oc = '';
        if (preg_match('~protected void onCreate\b.*?\n    \}~s', (string)$setupCode, $mOc)) {
            $oc = $mOc[0];
        }
        if ($oc === '') {
            $permBad[] = 'SmsSetupActivity — بدنه‌ی onCreate پیدا نشد';
        } else {
            // ⚠ و دقیقاً روی «`build()` داخلِ try است» می‌نشیند، نه «جایی
            //   در onCreate یک Throwable هست»: نسخه‌ی دوم هم پوچ بود،
            //   چون `catch (Throwable ignored)`ِ خودِ همان بلوک سبزش
            //   می‌کرد. دو جهش لازم شد تا این معلوم شود.
            //   راهِ فرار هم باید **داخلِ همان catch** باشد، نه هر جای
            //   onCreate: بدونِ `finish()` اکتیویتیِ نیمه‌ساخته روی صفحه
            //   می‌ماند و کاربر یک صفحه‌ی خالی می‌بیند.
            $hasTry = preg_match('~try\s*\{\s*build\(\);\s*\}\s*catch\s*\(\s*Throwable~',
                                 $oc, $mTry, PREG_OFFSET_CAPTURE);
            $escapes = $hasTry
                    && str_contains(substr($oc, (int)$mTry[0][1]), 'finish()');
            if (!$hasTry || !$escapes) {
                $permBad[] = 'SmsSetupActivity — onCreate گاردِ Throwable با راهِ فرار ندارد؛'
                           . ' یک خطا یعنی صفحه‌ی خالی، بی‌هیچ توضیحی';
            }
        }
    }
    T::bulk(13, $permBad, 'مجوزهای پیامک و اعلان اعلام و در زمانِ اجرا خواسته می‌شوند');

    // ⛔ پرسیدنِ مجوز در **اولین اجرا** — بدونِ پنجره‌ی اضافه.
    //
    //    گزارشِ مالکِ نصب: «اپ هنگام راه‌اندازی تأییدِ دسترسی نمی‌گیره،
    //    پیامِ برداشت یا واریز هم گوشی نمی‌گیره». هر دو یک علت داشتند:
    //    مجوز فقط از صفحه‌ی تنظیم خواسته می‌شد و کلید پیش‌فرض خاموش بود.
    //    رفعش زیرکلاسِ `LauncherActivity` است که با `shouldLaunchImmediately()`
    //    تا جوابِ دیالوگ صبر می‌کند — همان اکتیویتی، نه یکی دیگر پیش از
    //    کروم (آن نسخه یک بار پس گرفته شد). شش بند، و هر کدام بی‌صدا
    //    شکستنی:
    $lauBad = [];
    $lauSrc = (string)@file_get_contents($mobile . 'java/'
                    . str_replace('.', '/', $appId) . '/HesabLauncherActivity.java');
    $lauNC  = preg_replace('~/\*.*?\*/|(?<!:)//[^\n]*~s', '', $lauSrc) ?? $lauSrc;
    $recvNC = preg_replace('~/\*.*?\*/|(?<!:)//[^\n]*~s', '',
                (string)@file_get_contents($mobile . 'java/' . str_replace('.', '/', $appId)
                    . '/BankSmsReceiver.java')) ?? '';
    $setNC  = preg_replace('~/\*.*?\*/|(?<!:)//[^\n]*~s', '', $setup) ?? $setup;
    if ($lauSrc === '') {
        $lauBad[] = 'HesabLauncherActivity.java پیدا نشد — مجوز در اولین اجرا پرسیده نمی‌شود';
    } else {
        // ۱) زیرکلاسِ خودِ کتابخانه، نه یک اکتیویتیِ جدا.
        if (!preg_match('~extends\s+LauncherActivity\b~', $lauNC)) {
            $lauBad[] = 'HesabLauncherActivity — باید زیرکلاسِ LauncherActivity باشد، نه اکتیویتیِ جدا';
        }
        // ۲) مکث با قلابِ خودِ کتابخانه، و ادامه با `launchTwa()` **داخلِ
        //    جوابِ دیالوگ** — بدونش اپ روی صفحه‌ی تیره می‌ماند.
        if (!str_contains($lauNC, 'protected boolean shouldLaunchImmediately()')) {
            $lauBad[] = 'HesabLauncherActivity — shouldLaunchImmediately() بازنویسی نشده';
        }
        $orp = strpos($lauNC, 'onRequestPermissionsResult');
        if ($orp === false || !str_contains(substr($lauNC, $orp, 500), 'launchTwa();')) {
            $lauBad[] = 'HesabLauncherActivity — بعد از جوابِ دیالوگ launchTwa() صدا زده نمی‌شود؛ اپ باز نمی‌شود';
        }
        // ۳) هر خطا → باز کن، نه کرش.
        if (!preg_match('~catch\s*\(\s*Throwable[^)]*\)\s*\{[^}]*return true;~', $lauNC)) {
            $lauBad[] = 'HesabLauncherActivity — خطا در مسیرِ مجوز به «باز کن» نمی‌رسد';
        }
        // ۴) سقفِ پرسیدن — نه در هر اجرا.
        if (!preg_match('~MAX_ASKS\s*=\s*[12];~', $lauNC) || !str_contains($lauNC, '>= MAX_ASKS')) {
            $lauBad[] = 'HesabLauncherActivity — سقفِ پرسیدن (MAX_ASKS ≤ ۲) نیست؛ هر اجرا دیالوگ می‌دهد';
        }
        // ۵) هر سه مجوز — در **فهرستِ درخواست** (`out.add`)، نه هر جای
        //    فایل: همان نام در `has(...)` هم هست و جهشِ «از فهرست بینداز»
        //    با `str_contains` ساده زنده ماند.
        foreach (['RECEIVE_SMS', 'READ_SMS', 'POST_NOTIFICATIONS'] as $p) {
            if (!str_contains($lauNC, 'out.add(Manifest.permission.' . $p . ')')) {
                $lauBad[] = "HesabLauncherActivity — «{$p}» در اولین اجرا پرسیده نمی‌شود";
            }
        }
        // ۶) ⛔ آوردنِ صندوق — به خواستِ مالکِ نصب («تمام پیامک‌های حساب
        //    روی اپ بالا بیاد») جای «درِ ورودی صندوق را نمی‌خواند» را
        //    گرفت، با همان مرزها: فقط در `takeInbox()`، فقط با صافیِ
        //    مسیرِ واقعی، فقط با کلیدِ روشن و `READ_SMS`، و نشانه همان‌جا.
        $ti = strpos($lauNC, 'private ArrayList<String> takeInbox(');
        if ($ti === false) {
            $lauBad[] = 'HesabLauncherActivity — takeInbox() نیست؛ پیامکی که اعلانش زده نشده هرگز به دفتر نمی‌رسد';
        } else {
            $tiBody = substr($lauNC, $ti, 2600);
            foreach (['BankSmsReceiver.classify' => 'صافیِ مسیرِ واقعی',
                      'Manifest.permission.READ_SMS' => 'سنجشِ مجوزِ صندوق',
                      'BankSmsReceiver.DEFAULT_ON' => 'کلیدِ روشن/خاموش',
                      'putLong(BankSmsReceiver.PREF_SCAN_AT' => 'جلو بردنِ نشانه'] as $needle => $what) {
                if (!str_contains($tiBody, $needle)) {
                    $lauBad[] = "HesabLauncherActivity — takeInbox() {$what} را ندارد";
                }
            }
            // ⚠ فقط یک خواننده‌ی صندوق: `content://sms` جز داخلِ همین تابع نه.
            if (substr_count($lauNC, 'content://sms') !== 1 || strpos($lauNC, 'content://sms') < $ti) {
                $lauBad[] = 'HesabLauncherActivity — صندوق بیرون از takeInbox() خوانده می‌شود';
            }
        }
        // ⚠ پیش از `super.onCreate` (وگرنه با اپِ باز، آیکون چیزی نمی‌آورد)
        //   **و** در `getLaunchingUrl` (وگرنه اجرای اول، بعد از «اجازه»).
        $oc = strpos($lauNC, 'protected void onCreate(');
        $sp = $oc === false ? false : strpos($lauNC, 'super.onCreate(saved)', $oc);
        if ($oc === false || $sp === false
            || !str_contains(substr($lauNC, $oc, $sp - $oc), 'withPendingSms(')) {
            $lauBad[] = 'HesabLauncherActivity — پیامک‌ها پیش از super.onCreate به intent نمی‌نشینند';
        }
        if (!preg_match('~Uri\s+getLaunchingUrl\(\)\s*\{[^}]*withPendingSms\(~s', $lauNC)) {
            $lauBad[] = 'HesabLauncherActivity — getLaunchingUrl() پیامک‌ها را نمی‌آورد؛ اجرای اول خالی می‌ماند';
        }
        // ⚠ بدونِ مجوز نشانه‌ی «انجام شد» نخورد — وگرنه اجرای اول (پیش از
        //   دیالوگ) آوردن را برای همیشه‌ی همان اجرا خاموش می‌کرد.
        $wp = strpos($lauNC, 'private Uri withPendingSms(');
        if ($wp === false
            || !preg_match('~READ_SMS\)\)\s*\{\s*return null;\s*\}\s*pendingApplied\s*=\s*true;~', substr($lauNC, $wp, 900))) {
            $lauBad[] = 'HesabLauncherActivity — pendingApplied پیش از سنجشِ READ_SMS می‌خورد';
        }
    }
    // ⛔ زنجیره‌ی اولین اجرا (مهر ۱۴۰۵ — «نسخه‌ی آخر رو نصب کردم … درخواستِ
    //    دسترسی به باتری و اس‌ام‌اس نخواست»): شمارنده‌ها با هر نسخه صفر،
    //    پیامکِ بی‌اجازه (تنظیمِ محدود) → راهنما، بعد دیالوگِ باتری، بعد TWA.
    if ($lauSrc !== '') {
        if (!preg_match('~if \(sp\.getLong\(PREF_ASKS_VC, -1L\) != vc\)\s*\{\s*sp\.edit\(\)\.putLong\(PREF_ASKS_VC, vc\)\.putInt\(PREF_ASKS, 0\)~', $lauNC)) {
            $lauBad[] = 'HesabLauncherActivity — شمارنده‌ی پرسیدن با نسخه‌ی تازه صفر نمی‌شود؛ به‌روزرسانی دیگر هرگز نمی‌پرسد';
        }
        $sli = strpos($lauNC, 'protected boolean shouldLaunchImmediately()');
        if ($sli === false || !str_contains(substr($lauNC, $sli, 900), 'SharedPreferences sp = prefsForVersion();')) {
            $lauBad[] = 'HesabLauncherActivity — shouldLaunchImmediately() شمارنده‌ی نسخه را نمی‌خواند';
        }
        $ns = strpos($lauNC, 'private boolean nextStep()');
        $nsBody = $ns === false ? '' : substr($lauNC, $ns, 1800);
        if (!preg_match('~if \(!has\(Manifest\.permission\.RECEIVE_SMS\)\)\s*\{.*?SmsSetupActivity\.EXTRA_GUIDE.*?startActivityForResult\(i, REQ_GUIDE\)~s', $nsBody)
            || !preg_match('~isIgnoringBatteryOptimizations.*?ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS.*?REQ_BATTERY~s', $nsBody)
            || substr_count($nsBody, '>= MAX_ASKS') + substr_count($nsBody, 'if (b >= 1)') < 2) {
            $lauBad[] = 'HesabLauncherActivity — nextStep(): راهنمای پیامک یا دیالوگِ باتری (با سقف) نیست';
        }
        // ⛔ «اشتراک‌گذاری» از اپِ پیامک: در هر گوشی، بی‌مجوز — متن فقط در
        //    فرگمنت، intent دیگر SEND نیست، و پیش از super.onCreate
        if (!preg_match('~private void takeShared\(\).*?Intent\.ACTION_SEND\.equals.*?copy\.setAction\(Intent\.ACTION_VIEW\).*?encodedFragment\("sms=" \+ enc\)~s', $lauNC)
            || !preg_match('~protected void onCreate\(.*?takeShared\(\).*?super\.onCreate\(saved\)~s', $lauNC)
            || !preg_match('~\.HesabLauncherActivity".*?<action android:name="android\.intent\.action\.SEND"/>.*?android:mimeType="text/plain"~s', $mf)) {
            $lauBad[] = 'HesabLauncherActivity — «اشتراک‌گذاریِ پیامک» (SEND → #sms=) نیست یا متن بیرون از فرگمنت می‌رود';
        }
        $oar = strpos($lauNC, 'protected void onActivityResult(');
        if ($oar === false || !str_contains(substr($lauNC, $oar, 700), 'launchTwa();')) {
            $lauBad[] = 'HesabLauncherActivity — بعد از راهنما/باتری launchTwa() صدا زده نمی‌شود؛ اپ باز نمی‌شود';
        }
    }
    // ⛔ کلاسِ خامِ کتابخانه دیگر نباید به‌عنوان اکتیویتی اعلام شود، وگرنه
    //    دو درِ ورودی هست و یکی‌شان هرگز مجوز نمی‌پرسد.
    if (str_contains($mf, 'android:name="com.google.androidbrowserhelper.trusted.LauncherActivity"')) {
        $lauBad[] = 'AndroidManifest — LauncherActivityِ خامِ کتابخانه هنوز اعلام شده';
    }
    // ⛔ پیش‌فرضِ کلیدِ ثبتِ خودکار **روشن** و تنها مرجع `DEFAULT_ON`.
    if (!preg_match('~boolean\s+DEFAULT_ON\s*=\s*true\s*;~', $recvNC)) {
        $lauBad[] = 'BankSmsReceiver — DEFAULT_ON = true نیست؛ کسی که فقط اپ را نصب کند پیامکی نمی‌گیرد';
    }
    $syncNC = preg_replace('~/\*.*?\*/|(?<!:)//[^\n]*~s', '',
                (string)@file_get_contents($mobile . 'java/' . str_replace('.', '/', $appId) . '/SmsSync.java')) ?? '';
    foreach (['BankSmsReceiver' => $recvNC, 'SmsSetupActivity' => $setNC, 'HesabLauncherActivity' => $lauNC,
              'SmsSync' => $syncNC] as $who => $src) {
        if (preg_match('~getBoolean\(\s*(?:BankSmsReceiver\.)?PREF_ON\s*,(?!\s*(?:BankSmsReceiver\.)?DEFAULT_ON\b)~', $src)) {
            $lauBad[] = "{$who} — پیش‌فرضِ PREF_ON سخت‌کد است؛ باید از DEFAULT_ON بیاید";
        }
    }
    // ⚠ «مجوز نداد» کلید را خاموش نمی‌کند، وگرنه دیالوگِ اولین اجرا دیگر
    //   درباره‌ی پیامک نمی‌پرسید.
    //   تنها `false` مجاز همان دکمه‌ی «خاموش کردن» در `onToggle()` است.
    if (preg_match_all('~putBoolean\(\s*BankSmsReceiver\.PREF_ON\s*,\s*false\s*\)~', $setNC) > 1) {
        $lauBad[] = 'SmsSetupActivity — ردِ مجوز کلید را خاموش می‌کند';
    }
    T::bulk(19, $lauBad, 'مجوز در اولین اجرا پرسیده می‌شود و ثبتِ خودکار پیش‌فرض روشن است');

    // آدرسِ باز شونده باید روی همان دامنه‌ای باشد که intent-filter
    // تأییدش می‌کند؛ وگرنه اپ صفحه‌ای را باز می‌کند که برایش تأیید ندارد.
    T::ok(($mHost[1] ?? '') !== '' && str_contains($mUrl[1] ?? '', $mHost[1] ?? "\0"),
          'launchUrl روی همان دامنه‌ی hostName است');

    // اثر انگشت باید شکل درستِ SHA-256 داشته باشد (۳۲ بایتِ هگزِ
    // دونقطه‌دار). یک رشته‌ی کوتاه یا جاافتاده اینجا گرفته می‌شود، نه
    // بعد از نصب روی گوشی.
    $fp = $al[0]['target']['sha256_cert_fingerprints'][0] ?? '';
    T::ok((bool)preg_match('/^([0-9A-F]{2}:){31}[0-9A-F]{2}$/', $fp),
          'اثر انگشت شکل درستِ SHA-256 دارد');

    // ⚠ nginx فایل‌های نقطه‌دار را می‌بندد ولی .well-known را عمداً
    // استثنا کرده. اگر آن استثنا روزی برداشته شود، تأیید TWA بی‌سروصدا
    // می‌شکند و هیچ‌کس ربطش را پیدا نمی‌کند.
    $vps = (string)@file_get_contents(__DIR__ . '/../deploy/vps-setup.sh');
    T::ok(str_contains($vps, 'well-known'),
          'قاعده‌ی nginx مسیر .well-known را باز گذاشته است');

    // و mobile/ نباید هیچ کد برنامه‌ای داشته باشد: هر منطقی که آنجا
    // برود، نسخه‌ی دومِ همین سایت را شروع می‌کند.
    // ⚠ با glob نوشته نشود: `**` در glob پی‌اچ‌پی بازگشتی نیست و
    //     `mobile/**/*.kt` هیچ‌وقت فایلِ چند پوشه پایین‌تر را پیدا
    //     نمی‌کند — یعنی این بررسی همیشه سبز می‌ماند. یک بار همین شد و
    //     آزمون جهش گرفتش.
    // ⛔ فهرستِ **بسته**ی فایل‌های بومیِ مجاز.
    //
    //    قاعده‌ی اصلی هنوز پابرجاست: اپ یک پوسته است و منطقِ برنامه
    //    نباید آنجا برود، وگرنه نسخه‌ی دومِ همین سایت شروع می‌شود و هر
    //    قابلیت باید دو بار نوشته شود — دقیقاً همان چیزی که اپ فلاتر را
    //    کنار گذاشت.
    //
    // ⚠ این دو استثنا **ناچاری‌اند، نه انتخاب**: نه PWA و نه TWA به SMS
    //   دسترسی ندارند و هیچ API وبی هم برایش نیست، پس گرفتنِ خودکارِ
    //   پیامکِ بانک فقط از لایه‌ی بومی ممکن است. و هر دو عمداً **پارس
    //   نمی‌کنند**: متن را دست‌نخورده به `parseBankSms()` می‌سپارند.
    //
    // ⛔ فهرست بسته است تا فایلِ سومی بی‌سروصدا اضافه نشود. اگر روزی
    //    لازم شد، باید همین‌جا و آگاهانه باز شود.
    // ⚠ و `HesabLauncherActivity.java` سومی است، آگاهانه و همین‌جا: زیرکلاسِ
    //   `LauncherActivity` که مجوزِ پیامک و اعلان را در اولین اجرا می‌پرسد
    //   (مجوزِ زمانِ اجرا فقط از اکتیویتیِ خودمان خواسته می‌شود). آن هم هیچ
    //   منطقِ دامنه‌ای ندارد و هیچ پیامکی نمی‌خواند.
    // ⚠ و `UpdateActivity.java` چهارمی است، آگاهانه و همین‌جا: «روش بزنم و
    //   بلافاصله آپدیت بشه» — فایل را خودِ اپ می‌گیرد و پنجره‌ی نصبِ سیستم را
    //   باز می‌کند. هیچ پیامکی نمی‌خواند و آدرسش را فقط از دامنه‌ی خودِ اپ
    //   می‌سازد (قاعده ۶۴).
    // ⚠ و `SmsSync.java` پنجمی است، آگاهانه و همین‌جا (مهر ۱۴۰۵): «اپ هنوز
    //   نمی‌تونه اس‌ام‌اس‌ها رو بخونه … خودش بذاره روی حساب». کارگرِ پس‌زمینه
    //   (`JobService`) که پیامک را بی‌باز کردنِ اپ ثبت می‌کند — و باز هم
    //   **پارسر نیست**: صفحه‌ی `assets/sms-worker.html` را در WebViewِ
    //   نامرئی باز می‌کند و همان `smsWorker()`ِ سایت تصمیم می‌گیرد و می‌فرستد.
    //   هیچ کلاسِ شبکه‌ای ندارد (قاعده ۱۹، پایین‌تر).
    $allowedNative = ['BankSmsReceiver.java', 'SmsSetupActivity.java', 'HesabLauncherActivity.java',
                      'UpdateActivity.java', 'SmsSync.java'];

    $code = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__ . '/../mobile',
            FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if (!preg_match('/\.(java|kt|dart)$/i', $f->getFilename())) { continue; }
        if (!in_array($f->getFilename(), $allowedNative, true)) {
            $code[] = $f->getFilename() . ' — فایلِ بومیِ تازه‌ی ثبت‌نشده';
            continue;
        }
        // ⛔ و حتی همان دو تا نباید منطقِ دامنه داشته باشند: نه پارسِ
        //    مبلغ، نه تبدیلِ ریال به تومان، نه تقویمِ شمسی. هر کدامشان
        //    یعنی پیاده‌سازیِ دوم — و خرابیِ دو پیاده‌سازیِ ناهم‌خوان
        //    **بی‌صداست**: فرم پر می‌شود، کاربر ثبت می‌کند، و ماه بعد
        //    گزارش غلط است.
        $src = file_get_contents($f->getPathname());
        foreach (['ریال', 'تومان', 'jalali', 'Jalali', '/ 10', '/10'] as $logic) {
            if (str_contains($src, $logic)) {
                $code[] = $f->getFilename() . " — نشانه‌ی منطقِ دامنه («{$logic}»)";
                break;
            }
        }
    }
    T::bulk(count($allowedNative), $code,
        'پوشه‌ی mobile/ جز پلِ پیامک کد برنامه ندارد (پوسته می‌ماند)');
}

// ---------------------------------------------------------------
// قاعده ۱۳ — حاشیه‌ی امن: قاعده‌ای که **برنده می‌شود** باید آن را داشته باشد
//
// قاعده ۱۱ وجودِ `viewport-fit=cover` را می‌سنجد، یعنی اینکه iOS اصلاً
// این مقادیر را بدهد. ولی یک بار چیز دیگری شکست و آن قاعده ندیدش:
// مقدارها **داده می‌شدند** و CSS هم `env(...)` را داشت، ولی یک قاعده‌ی
// بعدی در همان فایل رویش می‌نوشت.
//
//   .topbar        قاعده‌ی پایه `calc(13px + env(safe-area-inset-top))` داشت،
//                  ولی داخل @media (max-width:900px) نوشته شده بود
//                  `padding: 12px 14px` — شورتهند، بعدتر در فایل، پس
//                  حاشیه‌ی امنِ بالا **کاملاً پاک می‌شد** و عنوان صفحه در
//                  اپِ نصب‌شده زیر Dynamic Island می‌رفت.
//   .page-content  فاصله‌ی کف ۹۶px ثابت بود. نوارِ شناور از کف پنجره
//                  14+62+env(bottom) بالا می‌ایستد: در مرورگر ۷۶px (پس
//                  کافی بود) ولی روی آیفون ۱۱۰px — یعنی ۱۴px از آخرین
//                  ردیف زیر نوار گیر می‌کرد.
//
// هر دو **فقط روی گوشیِ نصب‌شده** دیده می‌شدند و در مرورگر بی‌عیب بودند،
// چون آنجا env(...) صفر است. پس آزمونِ چشمی روی دسکتاپ هرگز نمی‌گرفتشان.
//
// آنچه سنجیده می‌شود ساده و مقاوم است: **آخرین** قاعده‌ای که این فاصله را
// تعیین می‌کند (یعنی همانی که واقعاً برنده می‌شود) باید `env(...)` داشته
// باشد. این‌طور اضافه شدنِ یک شورتهندِ بی‌خبر در آینده هم گرفته می‌شود.
T::group('قاعده ۱۳ — حاشیه‌ی امن با یک قاعده‌ی بعدی پاک نشود');

$css = file_get_contents(__DIR__ . '/../assets/css/style.css');
$css = preg_replace('!/\*.*?\*/!s', '', $css);          // کامنت‌ها حذف

/**
 * آخرین بلوکی که برای $selector این ویژگی را تعیین می‌کند برمی‌گرداند.
 * شورتهندِ `padding` هم شمرده می‌شود، چون padding-top/bottom را صفر می‌کند.
 */
$lastDecl = static function (string $css, string $selector, string $side): ?string {
    $winner = null;
    // هر بلوک: فهرست سلکتور + بدنه
    if (preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $m, PREG_SET_ORDER)) {
        foreach ($m as $blk) {
            $sels = $blk[1];
            $body = $blk[2];
            // سلکتور باید دقیقاً همین کلاس باشد، نه چیزی مثل .page-content-x
            if (!preg_match('/(^|[\s,>+~])' . preg_quote($selector, '/') . '(?![\w-])/', $sels)) {
                continue;
            }
            if (preg_match_all('/(?:^|;)\s*(padding(?:-' . $side . ')?)\s*:([^;]*)/i', $body, $d, PREG_SET_ORDER)) {
                $winner = end($d)[2];
            }
        }
    }
    return $winner;
};

$cases = [
    ['.topbar',       'top',    'safe-area-inset-top',
     'نوار بالا فاصله‌ی ناچ را نگه می‌دارد'],
    ['.page-content', 'bottom', 'safe-area-inset-bottom',
     'کف صفحه فاصله‌ی خطِ خانه را نگه می‌دارد'],
    // ⛔ مودال‌ها از این قاعده جا مانده بودند. شیت‌ها از اول
    //    `env(safe-area-inset-bottom)` داشتند، ولی `.modal-overlay` فقط
    //    `padding: 16px` بود — پس روی گوشیِ نصب‌شده مودالِ بلند سرآیندش
    //    زیرِ ناچ می‌رفت و دکمه‌ی بستن از دسترس خارج می‌شد. با اندازه‌گیری
    //    روی حاشیه‌های واقعیِ آیفون ۱۵ پرو دیده شد: لبه‌ی بالای مودال
    //    ۵۱px بود در حالی که ناچ تا ۵۹px می‌آید.
    ['.modal-overlay', 'top',    'safe-area-inset-top',
     'مودال از ناچ فاصله می‌گیرد'],
    ['.modal-overlay', 'bottom', 'safe-area-inset-bottom',
     'مودال از خطِ خانه فاصله می‌گیرد'],
];
foreach ($cases as [$sel, $side, $env, $what]) {
    $decl = $lastDecl($css, $sel, $side);
    T::ok(
        $decl !== null && str_contains($decl, $env),
        "قاعده ۱۳ — {$what}",
        $decl === null
            ? "هیچ قاعده‌ای padding-{$side} را برای {$sel} تعیین نمی‌کند"
            : "آخرین قاعده‌ی برنده «{$sel}» این است و env({$env}) ندارد:" .
              " padding…: " . trim($decl)
    );
}

// نوار پایین باید از خطِ خانه فاصله بگیرد، وگرنه روی همان می‌نشیند.
T::ok(
    (bool)preg_match('/\.bottom-nav\s*\{[^}]*safe-area-inset-bottom/s', $css),
    'قاعده ۱۳ — نوار پایین از خطِ خانه فاصله می‌گیرد'
);

// ---------------------------------------------------------------
// ⛔ قاعده ۱۴ — رمز که عوض شد، هر دسترسیِ قبلی باید باطل شود.
//
// عوض کردنِ رمز به‌تنهایی مهاجم را بیرون نمی‌کند: توکنِ api/v1 نه به
// رمز وابسته است نه به نشست، و ۹۰ روز زنده می‌ماند. کاربر رمز را عوض
// می‌کند، خیالش راحت می‌شود، و مهاجم همچنان داخل است — بی‌هیچ نشانه‌ای.
//
// چهار مسیر رمز می‌نویسند (پروفایل، بازیابی، پنل مدیر، خط فرمان) و
// هیچ‌کدام «یادش» نمی‌ماند مگر اینکه اینجا سنجیده شود.
T::group('قاعده ۱۴ — هر جا رمز نوشته می‌شود، دسترسی‌ها باطل شوند');

$pwWriters = [];
foreach (['api', 'includes', 'admin', 'store', 'deploy', '.'] as $dir) {
    foreach (glob(__DIR__ . '/../' . $dir . '/*.php') as $p) {
        $src = php_strip_whitespace($p);
        // فقط نوشتن، نه خواندن: `SET ... password_hash =`
        if (preg_match('/UPDATE\s+users\s+SET[^\'"]*password_hash\s*=/i', $src)) {
            $pwWriters[realpath($p)] = $src;
        }
    }
}
T::ok(count($pwWriters) >= 4, 'مسیرهای نوشتنِ رمز پیدا شدند',
    count($pwWriters) . ' فایل: ' . implode('، ', array_map('basename', array_keys($pwWriters))));

$noRevoke = [];
foreach ($pwWriters as $path => $src) {
    if (!str_contains($src, 'revokeAllAccessFor(')) {
        $noRevoke[] = basename($path);
    }
}
T::bulk(count($pwWriters), $noRevoke,
    'هر مسیرِ نوشتنِ رمز، revokeAllAccessFor() را صدا می‌زند');

// و خودِ آن تابع باید **هر دو** چیز را باطل کند — نه فقط یکی. باطل
// کردنِ دستگاه‌ها بدونِ توکن‌ها همان باگی است که این قاعده برایش نوشته
// شده، و از بیرون هیچ فرقی دیده نمی‌شود.
//
// ⚠ بدنه با شمردنِ آکولاد بریده می‌شود، نه با regex: کامنت‌ها با
//   php_strip_whitespace از قبل رفته‌اند، ولی `.*?\n\}` روی فایلی که
//   قالب‌بندی‌اش فشرده شده به هیچ چیز نمی‌خورد — یک بار همین‌طور شد و
//   تست به‌جای سنجیدنِ بدنه، «تابع پیدا نشد» داد.
$fn = php_strip_whitespace(__DIR__ . '/../includes/functions.php');
$at = strpos($fn, 'function revokeAllAccessFor');
$body = '';
if ($at !== false && ($open = strpos($fn, '{', $at)) !== false) {
    $depth = 0;
    for ($i = $open, $n = strlen($fn); $i < $n; $i++) {
        if ($fn[$i] === '{') { $depth++; }
        elseif ($fn[$i] === '}') { $depth--; if ($depth === 0) { $body = substr($fn, $open, $i - $open); break; } }
    }
}
T::ok($body !== '', 'تابع revokeAllAccessFor پیدا شد');
T::ok(str_contains($body, 'revokeAllDevices'),
    'revokeAllAccessFor دستگاه‌های مورد اعتماد را باطل می‌کند');
T::ok(str_contains($body, 'ApiAuth::revokeAllFor'),
    'revokeAllAccessFor توکن‌های api/v1 را هم باطل می‌کند');

// ---------------------------------------------------------------
// ⛔ قاعده ۱۵ — تگِ پایانِ PHP داخل کامنت، PHP را می‌بندد.
//
// یک کامنتِ `//` که تگِ پایانِ PHP را در متنش دارد بی‌ضرر به نظر
// می‌رسد، ولی همان‌جا PHP بسته
// می‌شود و بقیه‌ی فایل HTML حساب می‌شود. اینجا شانس آوردیم و خطای نحوی
// داد؛ در فایلی که آکولادهایش بالانس باشد **هیچ خطایی نمی‌دهد** و فقط
// نصفِ فایل بی‌صدا اجرا نمی‌شود — یا بدتر، سورس به مرورگر می‌رود.
T::group('قاعده ۱۵ — تگِ پایانِ PHP نباید داخل کامنت باشد');

$badTag = [];
$scanned = 0;
foreach (['api', 'includes', 'admin', 'store', 'deploy', 'tests', 'config', '.'] as $dir) {
    foreach (glob(__DIR__ . '/../' . $dir . '/*.php') as $p) {
        $scanned++;
        foreach (token_get_all(file_get_contents($p)) as $tk) {
            if (is_array($tk) && $tk[0] === T_COMMENT && str_contains($tk[1], '?' . '>')) {
                $badTag[] = basename($p) . ' خط ' . $tk[2];
            }
        }
    }
}
T::bulk($scanned, $badTag, 'هیچ کامنتی تگِ پایانِ PHP ندارد');

// ---------------------------------------------------------------
// ⛔ قاعده ۲۰ — منوی کناری باید اسکرول شود.
//
// `.sidebar` ارتفاعش به `100vh` قفل است، ولی فهرستش با آمدنِ هر بخشِ
// تازه بلندتر می‌شود. وقتی از ارتفاعِ پنجره رد شد، گزینه‌های آخر
// **ناپدید** می‌شوند: نه اسکرولی، نه بریدگی‌ای، نه خطایی — فقط نیستند.
// روی حسابِ مدیر محتوای منو به ۱۲۰۱ پیکسل رسیده بود، یعنی روی **هر**
// پنجره‌ی دسکتاپی «کاربران» و «ورود و پیامک» دست‌نیافتنی بودند.
//
// ⛔ و `overflow-y: auto` به‌تنهایی این را حل **نمی‌کند**: فرزندِ فلکس
//    پیش‌فرض `min-height: auto` دارد و حاضر نیست کوتاه‌تر از محتوایش
//    شود، پس `flex: 1` هرگز فشرده نمی‌شود و `overflow` هرگز فعال
//    نمی‌شود. هر دو با هم لازم‌اند — به همین دلیل هر دو سنجیده می‌شوند.
//
// ⚠ این خرابی روی موبایل اصلاً دیده نمی‌شود (آنجا `.sidebar` با
//   `display:none` کنار گذاشته می‌شود)، پس «روی گوشی درست است» اینجا
//   مدرک نیست — همان درسی که سرِ حاشیه‌ی امن هم گرفتیم، این بار برعکس.
T::group('قاعده ۲۰ — منوی کناری باید اسکرول شود');

// ⚠ کامنت‌ها **اول** حذف می‌شوند. بدونِ آن، الگوی «هر چیزی تا `{`» کلِ
//   بلوکِ توضیحِ بالای قاعده را هم جزوِ انتخابگر می‌گیرد و `trim($sel)`
//   دیگر برابرِ `.sidebar-nav` نیست — یعنی تست روی فایلِ **سالم** هم قرمز
//   می‌شد. اولین اجرا دقیقاً همین بود.
$navCss   = preg_replace('~/\*.*?\*/~s', '',
                @file_get_contents(__DIR__ . '/../assets/css/style.css') ?: '');
$navBad   = [];
$navHasOv = $navHasMin = false;

preg_match_all('~([^{}]+)\{([^{}]*)\}~', $navCss, $navRules, PREG_SET_ORDER);
foreach ($navRules as $r) {
    foreach (explode(',', $r[1]) as $sel) {
        // فقط قاعده‌ای که **خودِ** `.sidebar-nav` را هدف بگیرد، نه فرزندش:
        // `.sidebar-nav a { … }` هیچ ربطی به اسکرولِ خودِ فهرست ندارد.
        if (trim($sel) !== '.sidebar-nav') { continue; }
        if (preg_match('~overflow-y\s*:\s*(auto|scroll)~', $r[2])) { $navHasOv = true; }
        if (preg_match('~min-height\s*:\s*0~', $r[2]))             { $navHasMin = true; }
    }
}
if (!$navHasOv)  { $navBad[] = '.sidebar-nav — `overflow-y: auto` ندارد؛ گزینه‌های آخر دست‌نیافتنی می‌شوند'; }
if (!$navHasMin) { $navBad[] = '.sidebar-nav — `min-height: 0` ندارد؛ فرزندِ فلکس فشرده نمی‌شود و overflow بی‌اثر است'; }

T::bulk(2, $navBad, 'منوی کناری هم overflow دارد هم min-height صفر');

// ---------------------------------------------------------------
// ⛔ قاعده ۲۳ — اسلایدرِ خانه: اسلاید دقیقاً به اندازه‌ی نوارِ ماه.
//
// خواسته‌ی صریح این است که کارتِ حساب روی صفحه‌ی خانه **دقیقاً ابعادِ
// «مانده این ماه»** را داشته باشد و چیزی هم پایین‌تر نرود. سه چیز آن را
// نگه می‌دارند و هر سه بی‌صدا شکستنی‌اند:
//
//  ۱. `flex: 0 0 100%` روی اسلایدها — با هر عرضِ دیگری (`auto`, `63%`,
//     یک `max-width`) اسلاید دیگر هم‌اندازه‌ی نوار نیست و همان چیزی
//     می‌شود که قرار بود نباشد.
//  ۲. `margin-bottom: 0` روی نوار **داخلِ** اسلایدر — حاشیه‌ی یک آیتمِ
//     فلکس از ارتفاعِ جعبه‌اش کم می‌شود، پس با حاشیه‌ی ۱۲ پیکسلیِ خودش
//     نوار دوازده پیکسل **کوتاه‌تر** از کارت‌های کنارش می‌شد. هیچ خطایی
//     هم نمی‌داد.
//  ۳. `scroll-padding-inline` وقتی اسلایدر هم padding افقی دارد هم
//     `scroll-snap-type` — وگرنه اسنپ لبه‌ی اسلاید را به لبه‌ی جعبه‌ی
//     padding می‌چسباند و همان padding را خنثی می‌کند. یک بار روی نسخه‌ی
//     نواریِ همین قابلیت واقعاً همین شد و فقط با `scrollLeft` دیده شد.
//
// ⚠ هر سه فقط در مرورگر دیده می‌شوند و هیچ‌کدام خطا نمی‌دهند، پس
//   `php -l` و تستِ رندر هم نمی‌گیرندشان.
T::group('قاعده ۲۳ — اسلایدرِ خانه هم‌اندازه‌ی نوارِ ماه می‌ماند');

$carBad  = [];
$carFlex = $carRibbon = $carSnapPad = false;
$carHasPad = $carHasSnap = false;

preg_match_all('~([^{}]+)\{([^{}]*)\}~', $navCss, $carRules, PREG_SET_ORDER);
foreach ($carRules as $r) {
    foreach (explode(',', $r[1]) as $sel) {
        $sel = trim($sel);
        if ($sel === '.home-slides > *') {
            if (preg_match('~flex\s*:\s*0\s+0\s+100%~', $r[2])) { $carFlex = true; }
        } elseif ($sel === '.home-slides .balance-ribbon') {
            if (preg_match('~margin-bottom\s*:\s*0~', $r[2])) { $carRibbon = true; }
        } elseif ($sel === '.home-slides') {
            // ⚠ شورتهندِ `padding` هم شمرده می‌شود، نه فقط `padding-inline`:
            //   `padding: 0 14px 26px` همان ۱۴ پیکسل افقی را می‌دهد.
            if (preg_match('~padding(-inline|-left|-right)?\s*:\s*[^;]*\d~', $r[2])
                && !preg_match('~padding(-bottom|-top)\s*:~', $r[2])) { $carHasPad = true; }
            if (preg_match('~scroll-snap-type\s*:~', $r[2]))          { $carHasSnap = true; }
            if (preg_match('~scroll-padding(-inline|-left|-right)?\s*:~', $r[2])) { $carSnapPad = true; }
        }
    }
}
if (!$carFlex)   { $carBad[] = '`.home-slides > *` — `flex: 0 0 100%` ندارد؛ اسلاید دیگر هم‌اندازه‌ی نوارِ ماه نیست'; }
if (!$carRibbon) { $carBad[] = '`.home-slides .balance-ribbon` — `margin-bottom: 0` ندارد؛ نوار از کارت‌های کنارش کوتاه‌تر می‌شود'; }
if ($carHasPad && $carHasSnap && !$carSnapPad) {
    $carBad[] = '`.home-slides` — با padding افقی و scroll-snap، `scroll-padding-inline` هم لازم است';
}
T::bulk(3, $carBad, 'اسلایدهای خانه دقیقاً هم‌اندازه‌ی نوارِ ماه می‌مانند');

// ---------------------------------------------------------------
// ⛔ قاعده ۲۴ — هر `.js-show-card` همه‌ی `data-*`ی که `app.js` می‌خواند را دارد.
//
// نمای کارت از **دو** صفحه باز می‌شود: ردیفِ `wallets.php` و کارتِ
// پین‌شده‌ی `index.php`. یک شنونده‌ی مشترک در `app.js` مقدارها را از
// `data-*` همان عنصر می‌خواند، پس اگر یکی از دو صفحه کلیدی را جا
// بیندازد یا نامش را عوض کند، **هیچ خطایی رخ نمی‌دهد**: مودال باز
// می‌شود و همان یک خط خالی است — شماره‌ی کارت نیست، یا دکمه‌ی «تراکنش‌های
// این حساب» به `?wallet=` خالی می‌رود.
//
// ⛔ فهرستِ کلیدها از **خودِ `app.js`** کشف می‌شود، نه از یک آرایه‌ی
//    دستی اینجا — همان قاعده‌ی `userDataTables()` و آرایه‌ی `MIGRATIONS`.
//    با فهرستِ دستی، کلیدِ یازدهمی که فردا به هندلر اضافه شود بی‌صدا
//    بیرونِ پوشش می‌ماند و تست به‌مرور بی‌ارزش می‌شد.
T::group('قاعده ۲۴ — کارت‌های نمای بانکی همه‌ی data-* لازم را دارند');

$appJs   = @file_get_contents(__DIR__ . '/../assets/js/app.js') ?: '';
$cardBad = [];
$need    = [];

$hs = strpos($appJs, "querySelectorAll('.js-show-card')");
$he = $hs === false ? false : strpos($appJs, "openModal('bankCardModal')", $hs);
if ($hs === false || $he === false) {
    $cardBad[] = 'هندلرِ `.js-show-card` در app.js پیدا نشد — قاعده کور شده است';
} else {
    preg_match_all("~getAttribute\('data-([a-z0-9-]+)'\)~", substr($appJs, $hs, $he - $hs), $mm);
    $need = array_values(array_unique($mm[1]));
}

$cardsSeen = 0;
if ($need) {
    foreach (['.', 'includes', 'admin', 'store'] as $dir) {
        foreach (glob(__DIR__ . '/../' . $dir . '/*.php') as $p) {
            // ⛔ بلوک‌های PHP **اول** خالی می‌شوند و این نیمه‌ی لازمِ کار
            //    است، نه یک احتیاط: مقدارِ هر `data-*` با یک اکوی PHP
            //    نوشته می‌شود و تگِ پایانش یک `>`ِ واقعی دارد. بدونِ این خط،
            //    پنجره‌ی «تا اولین `>`» سرِ اولین مقدار بریده می‌شد و تست
            //    روی فایلِ **سالم** هم می‌گفت هیچ `data-*`ی نیست — دقیقاً
            //    همان چیزی که در اولین اجرا شد (۴۰ هشدارِ الکی).
            //    خالی کردنشان کامنت‌های PHP را هم می‌برد، پس یادداشتی که
            //    نامِ کلاس را در متنش دارد شمرده نمی‌شود.
            $src = preg_replace_callback(
                '~<\?(?:php|=)?.*?\?>~s',
                static fn(array $m): string => str_repeat(' ', strlen($m[0])),
                file_get_contents($p)
            );
            // ⚠ پنجره تا اولین `>` بریده می‌شود، یعنی همان تگِ باز — نه یک
            //   تعداد ثابت کاراکتر، وگرنه به عنصرِ بعدی سرریز می‌کرد و
            //   `data-*`ِ او را به حساب این می‌گذاشت (همان درسِ قاعده ۶).
            $off = 0;
            while (($i = strpos($src, 'js-show-card', $off)) !== false) {
                $off  = $i + 1;
                $open = strrpos(substr($src, 0, $i), '<');
                $shut = strpos($src, '>', $i);
                if ($open === false || $shut === false) { continue; }
                $tag = substr($src, $open, $shut - $open);
                $cardsSeen++;
                foreach ($need as $k) {
                    if (!str_contains($tag, 'data-' . $k . '=')) {
                        $cardBad[] = basename($p) . " — `.js-show-card` بدونِ `data-{$k}`";
                    }
                }
            }
        }
    }
    if ($cardsSeen === 0) {
        $cardBad[] = 'هیچ `.js-show-card` ای در صفحه‌ها پیدا نشد — قاعده کور شده است';
    }
}

T::bulk(max(1, $cardsSeen), $cardBad, 'هر کارتِ بازکننده‌ی نما، کلیدهای data-* را کامل دارد');

// ---------------------------------------------------------------
// ⛔ قاعده ۱۹ — متنِ پیامکِ بانک هرگز روی سیم نمی‌رود.
//
// این تنها تضمینِ حریمِ خصوصیِ کلِ این قابلیت است و تا امروز «رایگان»
// به دست می‌آمد: وب اصلاً به SMS دسترسی نداشت، پس کاربر خودش متن را
// می‌چسباند و چیزی به سرور نمی‌رفت. حالا که اپ اندروید پیامک را
// **خودکار** می‌گیرد، همان تضمین باید عمداً نگه داشته شود.
//
// راهش این است که متن در **فرگمنتِ** آدرس (`#sms=…`) برود: مرورگر
// فرگمنت را هرگز به سرور نمی‌فرستد — نه در خطِ درخواست، نه در
// `Referer`، نه در لاگِ دسترسیِ nginx. با `?sms=…` متنِ خامِ پیامکِ
// بانکِ کاربر روی دیسکِ سرور می‌نشست، و **هیچ چیزی خراب به نظر
// نمی‌رسید**: قابلیت دقیقاً همان‌طور کار می‌کرد.
//
// ⚠ تغییرِ یک `#` به `?` کلِ این تضمین را بی‌صدا می‌برد. به همین دلیل
//   شکلش سنجیده می‌شود، نه فقط رفتارش.
T::group('قاعده ۱۹ — پیامکِ بانک فقط در فرگمنت، نه در آدرسِ سرور');

$smsBad  = [];
$smsSeen = 0;

$appJs = @file_get_contents(__DIR__ . '/../assets/js/app.js') ?: '';
if ($appJs !== '') {
    $smsSeen++;
    // ⚠ «`location.hash` جایی در فایل هست» کافی نیست — این رشته در
    //   بخش‌های دیگرِ همان فایل هم هست، پس با آن شرط، عوض کردنِ خودِ
    //   خطِ خواندنِ پیامک بی‌صدا از تست رد می‌شد. (آزمونِ جهش نشانش
    //   داد.) پس باید در **همان چند خط** کنارِ `'#sms='` باشد.
    // ⚠ حالا دو شکل هست (`#sms=` و `#smsq=`) و رمزگشایی در
    //   `smsHashDecode()` است؛ پس خودِ `takeSmsFragment()` باید از
    //   `location.hash` بخواند و به همان بدهد.
    $pos = strpos($appJs, 'function takeSmsFragment()');
    if ($pos === false) {
        $smsBad[] = 'app.js — takeSmsFragment() پیدا نشد';
    } else {
        $tf = substr($appJs, $pos, 700);
        if (!preg_match('~location\.hash~', $tf) || !str_contains($tf, 'smsHashDecode(')) {
            $smsBad[] = 'app.js — پیامک از `location.hash` و smsHashDecode خوانده نمی‌شود';
        }
    }
    if (!str_contains($appJs, "'#smsq='") || !str_contains($appJs, "'#sms='")) {
        $smsBad[] = 'app.js — یکی از دو شکلِ فرگمنت (`#sms=` / `#smsq=`) شناخته نمی‌شود';
    }
    if (preg_match('~[?&]smsq?=~', $appJs)) {
        $smsBad[] = 'app.js — `?sms=` پیدا شد؛ متن به سرور می‌رود';
    }
}

// سمتِ اندروید: آدرس با `#sms=` ساخته شود، و هیچ درخواستِ شبکه‌ای در
// کارِ گیرنده نباشد.
// ⛔ مسیرِ پکیج **سخت‌کد نمی‌شود**. نسخه‌ی اول
//    `java/ir/stland/daftar/*.java` بود، و اولین باری که نامِ بسته عوض
//    شد آن glob هیچ فایلی برنمی‌گرداند — یعنی همه‌ی بررسی‌های زیر بی‌صدا
//    ناپدید می‌شدند و تست **سبز** می‌ماند. سنجشی که روی خرابی سبز
//    می‌شود از نبودِ سنجش بدتر است.
$nativeJava = [];
$javaRoot   = __DIR__ . '/../mobile/app/src/main/java';
if (is_dir($javaRoot)) {
    $itJ = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($javaRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($itJ as $f) {
        if (preg_match('/\.java$/i', $f->getFilename())) { $nativeJava[] = $f->getPathname(); }
    }
}
// و اگر روزی هیچ‌کدام پیدا نشدند، خودِ همین نبودن یک خطاست.
if (!$nativeJava && is_dir($javaRoot)) {
    $smsBad[] = 'هیچ فایلِ جاوایی زیر mobile/ پیدا نشد — پلِ پیامک گم شده';
    $smsSeen++;
}
foreach ($nativeJava as $j) {
    $smsSeen++;
    $src  = file_get_contents($j);
    $name = basename($j);

    // ⛔ تنها query مجاز `appv` (نسخه‌ی خودِ اپ، قاعده ۶۴) است، و فقط با
    //    همان یک شکل: کلیدِ ثابت و `String.valueOf(عدد)`. هر
    //    `appendQueryParameter`ِ دیگری یعنی چیزی روی سیم می‌رود.
    // ⚠ و `UpdateActivity` یک شکلِ دیگر دارد: `v` — شماره‌ی نسخه‌ی تازه،
    //   فقط رقم — تا کشِ CDN فایلِ کهنه را ندهد. آن هم چیزی از کاربر نیست.
    $qAll  = substr_count($src, 'appendQueryParameter');
    $qAppv = preg_match_all('~appendQueryParameter\(PARAM_APP_VERSION,\s*String\.valueOf\(vc\)\)~', $src);
    if ($name === 'UpdateActivity.java') {
        $qAppv += preg_match_all('~appendQueryParameter\("v",\s*v\)~', $src);
    }
    if (preg_match('~[?&]smsq?=~', $src) || $qAll !== $qAppv) {
        $smsBad[] = "$name — `?sms=` یا query پیدا شد؛ متنِ پیامک به سرور می‌رفت";
    }
    // ⛔ هیچ کلاسِ شبکه‌ای: اگر روزی کسی وسوسه شود متن را «برای پارسِ
    //    بهتر» به یک اندپوینت بفرستد، همین‌جا می‌ایستد.
    foreach (['HttpURLConnection', 'OkHttp', 'java.net.Socket', 'URLConnection'] as $net) {
        if (str_contains($src, $net)) {
            $smsBad[] = "$name — «{$net}» در لایه‌ی بومی؛ پیامک نباید فرستاده شود";
        }
    }

    if ($name === 'BankSmsReceiver.java') {
        // ⛔ **متنِ پیامک ذخیره نمی‌شود** — همان قاعده‌ای که آن را در
        //    فرگمنت نگه می‌دارد. ردِ تشخیص فقط زمان و فرستنده و نتیجه
        //    است؛ نوشتنِ خودِ متن در SharedPreferences یک نسخه‌ی
        //    دائمیِ تازه از پیامکِ بانک روی دیسکِ گوشی می‌ساخت، برای
        //    چیزی که اصلاً به متن نیاز ندارد.
        if (preg_match('~put[A-Za-z]*\([^)]*\btext\b~', $src)) {
            $smsBad[] = "$name — متنِ پیامک در prefs ذخیره می‌شود؛ فقط نتیجه باید ثبت شود";
        }

        // ⛔ ردِ تشخیص باید **پیش از** خروجِ زودهنگام نوشته شود. کلِ
        //    ارزشش همان حالتی است که اعلانی ساخته نمی‌شود؛ اگر به داخلِ
        //    شاخه‌ی موفق منتقل شود، دقیقاً در خرابی ساکت می‌ماند و
        //    صفحه‌ی تنظیم می‌گوید «هیچ پیامکی نرسیده» در حالی که رسیده.
        // ⚠ جای **نوشتن** سنجیده می‌شود نه جای تعریفِ ثابت: نسخه‌ی اول
        //   دنبالِ خودِ `PREF_LAST_WHY` می‌گشت و آن اولین بار بالای فایل
        //   به‌عنوان ثابت تعریف شده، پس همیشه از هر چیزی جلوتر بود و
        //   بررسی **پوچ** می‌شد. جهش نشانش داد.
        $wrote  = strpos($src, 'putString(PREF_LAST_WHY');
        $bailed = strpos($src, '!WHY_OK.equals');
        if ($wrote === false || $bailed === false) {
            $smsBad[] = "$name — ردِ «آخرین پیامک» پیدا نشد؛ خرابی دوباره بی‌صدا می‌شود";
        } elseif ($wrote > $bailed) {
            $smsBad[] = "$name — ردِ تشخیص بعد از خروجِ زودهنگام نوشته می‌شود؛"
                      . ' پیامکِ ردشده هیچ ردی نمی‌گذارد';
        }
    }

    // ⛔ اعلانِ آزمایشی باید از **همان** مسیرِ اعلانِ واقعی برود. با یک
    //    سازنده‌ی دومِ اعلان در صفحه‌ی تنظیم، دکمه‌ی آزمایش می‌توانست
    //    اعلان نشان بدهد در حالی که مسیرِ واقعیِ پیامک خراب است — یعنی
    //    همان «سنجشی که روی خرابی سبز می‌شود»، این بار در دستِ کاربر.
    if ($name === 'SmsSetupActivity.java') {
        foreach (['Notification.Builder', 'NotificationCompat.Builder', '.notify('] as $dup) {
            if (str_contains($src, $dup)) {
                $smsBad[] = "$name — «{$dup}» یعنی سازنده‌ی دومِ اعلان؛"
                          . ' آزمایش باید از postNotification برود';
            }
        }
        if (!str_contains($src, 'BankSmsReceiver.postNotification')) {
            $smsBad[] = "$name — آزمایشِ اعلان از مسیرِ واقعی نمی‌رود";
        }

        // ⛔ رفعِ بهینه‌سازیِ باتری — تنها راهِ حلِ «پیامک اصلاً به گیرنده
        //    نرسید»، که تا امروز فقط **تشخیص** داده می‌شد و هیچ دکمه‌ای
        //    نداشت. سه چیزش بی‌صدا شکستنی است:
        //
        //    ۱. ⛔ اکشنِ `…REQUEST_IGNORE_BATTERY_OPTIMIZATIONS` (و مجوزِ
        //       هم‌نامش) از **مجوزهای محدودشده‌ی گوگل‌پلی** است. یک تپ
        //       کمتر می‌گیرد و در عوض کلِ اپ را در معرضِ رد شدن
        //       می‌گذارد. اکشنِ درست `…IGNORE_BATTERY_OPTIMIZATION_SETTINGS`
        //       است که هیچ مجوزی نمی‌خواهد.
        //    ۲. دکمه باید **شرطی** باشد: روی گوشیِ از قبل معاف، یک
        //       دکمه‌ی بی‌کار فقط صفحه را شلوغ می‌کند.
        //    ۳. بعضی رام‌ها آن اکشن را ندارند؛ بدونِ `try` تپِ کاربر اپ
        //       را می‌بندد — همان کرشِ بی‌توضیحی که گاردِ `onCreate`
        //       برایش نوشته شد.
        // ⚠ روی سورسِ **بدونِ کامنت** سنجیده می‌شود. همین توضیح‌ها نامِ
        //   همان اکشنِ ممنوع را در متنشان دارند، پس با سورسِ خام تست روی
        //   فایلِ **سالم** قرمز می‌شد — همان دامی که قاعده ۳۰ و ۳۵ و ۳۸
        //   و ۴۴ هم در آن افتادند.
        $srcNC = preg_replace('~/\\*.*?\\*/|//[^\\n]*~s', '', $src) ?? $src;

        // ⛔ **برعکس شد (مهر ۱۴۰۵)** — گزارشِ مالکِ نصب: «اپ‌های دیگه
        //    دسترسی به باتری می‌گیرن، مالِ ما نه». اپ از پلی پخش نمی‌شود، پس
        //    هزینه‌ی «محدودشده‌ی پلی» این‌جا صفر است و هزینه‌ی نبودنش واقعی:
        //    کاربر در فهرستِ صدها اپ گم می‌شد و ثبتِ پس‌زمینه می‌خوابید. حالا
        //    دیالوگِ یک‌تپی **اول**، و فهرستِ `_SETTINGS` فقط پشتیبان — هر دو
        //    داخلِ `openBatterySettings()`.
        $obBody = '';
        if (($obAt = strpos($srcNC, 'private void openBatterySettings()')) !== false) {
            $obBody = substr($srcNC, $obAt, 900);
        }
        $reqAt = strpos($obBody, 'ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS');
        $setAt = strpos($obBody, 'ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS');
        if ($reqAt === false || $setAt === false || $reqAt > $setAt) {
            $smsBad[] = "$name — دیالوگِ یک‌تپیِ باتری (REQUEST_IGNORE_…) پیش از فهرستِ تنظیمات نیست";
        }
        if (!str_contains($obBody, '"package:" + getPackageName()')) {
            $smsBad[] = "$name — دیالوگِ باتری بی‌نامِ بسته است؛ اندروید آن را رد می‌کند";
        }
        if (!str_contains($srcNC, 'isIgnoringBatteryOptimizations')) {
            $smsBad[] = "$name — وضعیتِ بهینه‌سازیِ باتری سنجیده نمی‌شود؛"
                      . ' علتِ اولِ «هیچ پیامکی نرسید» بی‌راه‌حل می‌ماند';
        }
        if (!preg_match('~fixBattery\.setVisibility\([^;]*batteryOk\(\)~', $srcNC)) {
            $smsBad[] = "$name — دکمه‌ی باتری شرطی نیست؛ روی گوشیِ معاف"
                      . ' یک دکمه‌ی بی‌کار می‌ماند';
        }
        $ob = strpos($srcNC, 'private void openBatterySettings()');
        if ($ob === false) {
            $smsBad[] = "$name — openBatterySettings() پیدا نشد";
        } elseif (!preg_match('~try\s*\{.*?startActivity.*?catch~s', substr($srcNC, $ob, 600))) {
            $smsBad[] = "$name — openBatterySettings() گاردِ try ندارد؛"
                      . ' روی رامی که این صفحه را ندارد اپ بسته می‌شود';
        }

        // ⛔ «بررسی پیامک‌های قبلی» حالا اپ را با پنجره‌ی عمیق باز می‌کند
        //    (`EXTRA_DEEP`) و خودش صندوق را **نمی‌خواند**: تنها خواننده
        //    `HesabLauncherActivity.takeInbox()` است. با دو خواننده، پنجره و
        //    صافی دیر یا زود دو جواب می‌دادند.
        $si = strpos($srcNC, 'private void scanInbox()');
        if ($si === false) {
            $smsBad[] = "$name — scanInbox() پیدا نشد";
        } else {
            $scanBody = substr($srcNC, $si, 900);
            foreach (['HesabLauncherActivity.class' => 'باز کردنِ اپ',
                      'HesabLauncherActivity.EXTRA_DEEP' => 'پنجره‌ی عمیق'] as $needle => $what) {
                if (!str_contains($scanBody, $needle)) {
                    $smsBad[] = "$name — scanInbox() {$what} را ندارد";
                }
            }
            if (str_contains($srcNC, 'content://sms')) {
                $smsBad[] = "$name — صفحه‌ی تنظیم خودش صندوق را می‌خواند؛ خواننده فقط takeInbox است";
            }
            if (!preg_match('~onClick\([^)]*\)\s*\{\s*scanInbox\(\);~', $srcNC)) {
                $smsBad[] = "$name — scanInbox() به تپِ کاربر بسته نیست";
            }
            // ⚠ از چرخه‌ی عمر صدا زده نشود: خودش صفحه را می‌بندد و اپ را
            //   باز می‌کند، پس از `onResume` یعنی حلقه‌ی باز و بسته شدن.
            foreach (['onCreate', 'onResume', 'build'] as $auto) {
                if (preg_match('~' . $auto . '\b[^{]*\{(?:[^{}]|\{[^{}]*\})*scanInbox\(\)~s', $srcNC)) {
                    $smsBad[] = "$name — scanInbox() از {$auto}() صدا زده می‌شود";
                }
            }
        }
        // ⛔ و متنِ خوانده‌شده هیچ‌جا ذخیره نمی‌شود — همان قاعده‌ای که در
        //    گیرنده هم هست. صندوقِ پیامک از قبل روی گوشی هست؛ نسخه‌ی
        //    دومش در SharedPreferences فقط سطحِ افشا را بزرگ می‌کند.
        if (preg_match('~put[A-Za-z]*\([^)]*\b(body|txt|text)\b~', $srcNC)) {
            $smsBad[] = "$name — متنِ پیامک در prefs ذخیره می‌شود";
        }
    }

    // ⛔ و **گیرنده** هرگز صندوق را نمی‌خواند. کارش فقط تحویلِ پیامکِ
    //    رسیده است؛ اگر روزی `content://sms` را باز کند، خواندنِ صندوق
    //    از یک تصمیمِ آگاهانه‌ی کاربر به یک کارِ خودکارِ پس‌زمینه تبدیل
    //    می‌شود — بی‌آنکه هیچ‌جا دیده شود.
    // ⛔ و گیرنده نشانه را جلو **نمی‌برد**: اعلانی که زده نشده پیامکی نیست
    //    که به دفتر رسیده، و با جلو بردنش باز کردنِ اپ همان را نمی‌آورد.
    if ($name === 'BankSmsReceiver.java'
        && str_contains(preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $src) ?? $src, 'putLong(PREF_SCAN_AT')) {
        $smsBad[] = "$name — گیرنده PREF_SCAN_AT را جلو می‌برد؛ پیامکِ اعلانِ‌نزده با باز کردنِ اپ نمی‌آید";
    }
    // ⛔ کارگرِ پس‌زمینه (`SmsSync`) — پنج مرز، هر کدام بی‌صدا شکستنی:
    //    ۱. متن در prefs نه (فقط زمان‌ها و کلید). `JSONObject.put` مجاز است:
    //       متن فقط در حافظه به صفحه داده می‌شود.
    //    ۲. صندوق فقط در `readInbox()`، با صافیِ `classify` و سنجشِ مجوز.
    //    ۳. WebView فقط همان صفحه‌ی کارگر، بی‌هیچ ناوبری، بی‌کش.
    //    ۴. کلیدِ روشن/خاموش با `DEFAULT_ON` سنجیده شود.
    //    ۵. وصل‌نشده هیچ پیامکی را ثبت نمی‌کند (دو پردازنده نه).
    if ($name === 'SmsSync.java') {
        $ssNC = preg_replace('~/\*.*?\*/|(?<!:)//[^\n]*~s', '', $src) ?? $src;
        if (preg_match('~put(String|StringSet)\([^)]*\b(text|body|txt|items)\b~', $ssNC)) {
            $smsBad[] = "$name — متنِ پیامک در prefs ذخیره می‌شود";
        }
        $ri = strpos($ssNC, 'static ArrayList<Item> readInbox(');
        if ($ri === false || substr_count($ssNC, 'content://sms') !== 1 || strpos($ssNC, 'content://sms') < $ri) {
            $smsBad[] = "$name — صندوق بیرون از readInbox() خوانده می‌شود";
        } else {
            $riBody = substr($ssNC, $ri, 1200);
            foreach (['BankSmsReceiver.classify' => 'صافیِ مسیرِ واقعی', 'canRead(ctx)' => 'سنجشِ مجوزِ صندوق'] as $needle => $what) {
                if (!str_contains($riBody, $needle)) { $smsBad[] = "$name — readInbox() {$what} را ندارد"; }
            }
        }
        if (!str_contains($ssNC, '"/assets/sms-worker.html"')
            || !preg_match('~shouldOverrideUrlLoading\(WebView v, WebResourceRequest r\)\s*\{\s*return true;~', $ssNC)
            || !str_contains($ssNC, 'LOAD_NO_CACHE')) {
            $smsBad[] = "$name — WebViewِ کارگر به صفحه‌ی خودش بسته نیست (ناوبری/کش)";
        }
        if (!preg_match('~getBoolean\(BankSmsReceiver\.PREF_ON,\s*BankSmsReceiver\.DEFAULT_ON\)\)\s*\{\s*return false;~', $ssNC)) {
            $smsBad[] = "$name — کلیدِ «خاموش» کارگر را نمی‌ایستاند";
        }
        if (!preg_match('~if \(!hadToken && !items\.isEmpty\(\)\)\s*\{[^}]*items = new ArrayList~s', $ssNC)) {
            $smsBad[] = "$name — بی‌کلید هم پیامک می‌فرستد؛ دو پردازنده (اعلان + کارگر) یعنی تراکنشِ تکراری";
        }
    }
    // ⛔ و در حالتِ «وصل» نه اعلانِ تپی (گیرنده) و نه صندوق (درِ ورودی):
    //    همان پیامک از مسیرِ وب هم ثبت می‌شد.
    if ($name === 'BankSmsReceiver.java'
        && !preg_match('~if \(SmsSync\.linked\(ctx\)\)\s*\{\s*SmsSync\.offer\(ctx, text, sender\);\s*return;~', $src)) {
        $smsBad[] = "$name — در حالتِ وصل هم اعلانِ تپی می‌سازد؛ تپ روی آن تراکنشِ دوم است";
    }
    if ($name === 'HesabLauncherActivity.java'
        && !preg_match('~if \(SmsSync\.linked\(this\)\)\s*\{\s*return SmsSync\.takeReview\(this\);~', $src)) {
        $smsBad[] = "$name — در حالتِ وصل صندوق را به سایت می‌دهد؛ پیامکِ ثبت‌شده دوباره ثبت می‌شود";
    }

    // ⛔ متنِ صندوق در درِ ورودی هم ذخیره نمی‌شود.
    if ($name === 'HesabLauncherActivity.java'
        && preg_match('~put[A-Za-z]*\([^)]*\b(body|txt|text|items)\b~',
                      preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $src) ?? $src)) {
        $smsBad[] = "$name — متنِ پیامک در prefs ذخیره می‌شود";
    }
    if ($name === 'BankSmsReceiver.java' && str_contains($src, 'content://sms')) {
        $smsBad[] = "$name — گیرنده صندوقِ پیامک را می‌خواند؛"
                  . ' خواندنِ صندوق فقط با تپِ کاربر در صفحه‌ی تنظیم است';
    }
}

// ⛔ زنجیره‌ی جفت شدن (ثبتِ پس‌زمینه) — چهار حلقه، هر کدام بی‌صدا شکستنی:
//    اپ کد را در **فرگمنت** می‌گذارد → app.js آن را می‌خواند، پاک می‌کند و با
//    CSRF به `sms_link.php` می‌دهد → صفحه‌ی تنظیمِ اپ با `S.linked=1` باز
//    می‌شود → همان کلید (`EXTRA_LINKED`) آنجا خوانده می‌شود.
$smsSeen++;
$lauSrcL = (string)@file_get_contents($javaRoot . '/ir/stland/hesabland/HesabLauncherActivity.java');
$setSrcL = (string)@file_get_contents($javaRoot . '/ir/stland/hesabland/SmsSetupActivity.java');
$lp = strpos($appJs, 'function smsLinkFromFragment()');
$lb = $lp === false ? '' : substr($appJs, $lp, 1800);
if (!str_contains($lauSrcL, 'encodedFragment("smslink=" + SmsSync.nonce(this))')) {
    $smsBad[] = 'HesabLauncherActivity — کدِ جفت شدن در فرگمنت (`#smslink=`) نیست';
}
if ($lb === '' || !str_contains($lb, "#smslink=") || !str_contains($lb, 'history.replaceState')
    || !str_contains($lb, "apiUrl('sms_link.php')") || !str_contains($lb, "'csrf_token'")
    || strpos($lb, 'history.replaceState') > strpos($lb, 'fetch(')) {
    $smsBad[] = 'app.js — smsLinkFromFragment() کد را پیش از درخواست پاک نمی‌کند یا بی‌CSRF می‌فرستد';
}
if ($lb !== '' && (!str_contains($lb, ".SMS_SETUP;package=' + pkg + ';S.linked=1;end'")
    || !preg_match('~EXTRA_LINKED\s*=\s*"linked"~', $setSrcL))) {
    $smsBad[] = 'app.js/SmsSetupActivity — بعد از جفت شدن صفحه‌ی اپ با `S.linked=1` باز نمی‌شود';
}

// و خودِ گیرنده باید هنوز در manifest ثبت باشد — بدونِ آن اپ ساخته
// می‌شود، نصب می‌شود، و فقط هیچ پیامکی نمی‌گیرد.
$manifest = @file_get_contents(__DIR__ . '/../mobile/app/src/main/AndroidManifest.xml') ?: '';
if ($manifest !== '') {
    $smsSeen++;
    foreach (['BankSmsReceiver' => 'گیرنده‌ی پیامک',
              'android.permission.RECEIVE_SMS' => 'مجوز خواندن پیامک',
              'SmsSetupActivity' => 'صفحه‌ی روشن/خاموش'] as $needle => $what) {
        if (!str_contains($manifest, $needle)) {
            $smsBad[] = "AndroidManifest — {$what} ثبت نشده";
        }
    }
    // ⛔ و آن مجوز **باید** اعلام شود (برعکسِ پیش از مهر ۱۴۰۵) — بی‌آن دیالوگِ
    //    یک‌تپی بی‌صدا رد می‌شود و فقط فهرستِ تنظیمات می‌ماند.
    if (!str_contains($manifest, 'android.permission.REQUEST_IGNORE_BATTERY_OPTIMIZATIONS')) {
        $smsBad[] = 'AndroidManifest — مجوزِ REQUEST_IGNORE_BATTERY_OPTIMIZATIONS'
                  . ' اعلام نشده؛ دیالوگِ «اجرا در پس‌زمینه» باز نمی‌شود';
    }
}

T::bulk($smsSeen, $smsBad, 'متنِ پیامک فقط از راهِ فرگمنت جابه‌جا می‌شود');

// ---------------------------------------------------------------
// ⛔ قاعده ۱۸ — عنصری که `display` دارد، باید `[hidden]` خودش را هم داشته باشد.
//
// ویژگیِ `hidden` مرورگر با یک قاعده‌ی **UA** به‌شکلِ `display: none`
// پیاده می‌شود، و هر قاعده‌ی نویسنده — حتی یک `.foo { display: flex }`
// ساده — بر آن غلبه می‌کند. یعنی عنصری که در HTML `hidden` نوشته شده
// **دیده می‌شود**، بی‌هیچ خطایی.
//
// این تا امروز سه بار زده: `.sms-conn` (کارتِ پنهانِ پنلِ پیامک)،
// `.asset-item` / `.chart-container` (قلمِ خاموشِ دارایی)، و
// `.stay-chips` (چیپ‌های «مبلغ هر قسط / جمع کل» که فقط برای تعهدِ
// چندقسطی معنا دارند و همیشه دیده می‌شدند). هر سه بار فقط با چشم و
// روی مرورگر پیدا شد.
//
// ⚠ فقط کلاس‌هایی سنجیده می‌شوند که واقعاً در PHP با `hidden` نوشته
//   شده‌اند — وگرنه هر کلاسِ `display`داری هشدار می‌داد و هشدارِ الکی
//   از نبودِ تست بدتر است.
T::group('قاعده ۱۸ — عنصرِ پنهان‌شونده باید [hidden] خودش را داشته باشد');

$css = file_get_contents(__DIR__ . '/../assets/css/style.css');

// کلاس‌هایی که در HTML با ویژگیِ `hidden` رندر می‌شوند.
// ⚠ `includes/` هم باید اسکن شود: شیتِ ثبت تراکنش آنجاست و در **هر**
//   صفحه رندر می‌شود، پس یک `[hidden]`ِ جامانده آنجا همه‌جا دیده می‌شود.
$hiddenClasses = [];
foreach (['admin', 'store', 'includes', '.'] as $dir) {
    foreach (glob(__DIR__ . '/../' . $dir . '/*.php') as $p) {
        $src = file_get_contents($p);
        // تگی که هم `class="…"` دارد هم `hidden` تنها (نه `data-hidden`).
        if (!preg_match_all('~<[a-z]+[^>]*>~i', $src, $tags)) { continue; }
        foreach ($tags[0] as $tag) {
            if (!preg_match('~\shidden(?=[\s/>])~i', $tag)) { continue; }
            if (!preg_match('~\sclass="([^"]*)"~i', $tag, $m)) { continue; }
            foreach (preg_split('~\s+~', trim($m[1])) as $cls) {
                if ($cls !== '' && !str_contains($cls, '<')) { $hiddenClasses[$cls] = basename($p); }
            }
        }
    }
}

// ⚠ فقط قاعده‌ای می‌شمارد که **خودِ** آن کلاس را هدف بگیرد، نه یک
//   فرزندش: `.form-group label { display: … }` هیچ ربطی به پنهان شدنِ
//   `.form-group` ندارد و شمردنش یعنی چهار هشدارِ الکی. پس آخرین
//   «compound»ِ هر انتخابگر جدا می‌شود.
$lastCompound = function (string $sel): string {
    // ⚠ جداکننده `~` نیست: خودِ `~` یکی از ترکیب‌کننده‌های CSS است.
    $parts = preg_split('#\s*[>+~]\s*|\s+#', trim($sel));
    return $parts ? (string)end($parts) : '';
};

// قاعده‌های سطحِ بالای CSS (کافی است؛ داخلِ @media هم با همین الگو
// خوانده می‌شود چون فقط دنبالِ جفتِ «انتخابگر { بدنه }» است).
preg_match_all('~([^{}]+)\{([^{}]*)\}~', $css, $rules, PREG_SET_ORDER);

$hasDisplay = [];   // کلاس → قاعده‌ای که مستقیماً display می‌دهد
$hasHidden  = [];   // کلاس → قاعده‌ای با [hidden]
foreach ($rules as $r) {
    $body    = $r[2];
    $setsDsp = (bool)preg_match('~\bdisplay\s*:~', $body);
    foreach (explode(',', $r[1]) as $sel) {
        $comp = $lastCompound($sel);
        if ($comp === '') { continue; }
        if (!preg_match_all('~\.([A-Za-z0-9_-]+)~', $comp, $cm)) { continue; }
        $isHiddenRule = str_contains($comp, '[hidden]');
        foreach ($cm[1] as $c) {
            if ($isHiddenRule) { $hasHidden[$c] = true; }
            elseif ($setsDsp)  { $hasDisplay[$c] = true; }
        }
    }
}

$hiddenBad = [];
foreach ($hiddenClasses as $cls => $where) {
    if (empty($hasDisplay[$cls]) || !empty($hasHidden[$cls])) { continue; }
    $hiddenBad[] = ".$cls (در {$where}) — display دارد ولی .{$cls}[hidden] ندارد";
}
T::bulk(count($hiddenClasses), $hiddenBad,
    'هر کلاسِ پنهان‌شونده‌ای که display دارد، [hidden] خودش را هم دارد');

// ---------------------------------------------------------------
// ⛔ قاعده ۱۷ — کامنتِ پی‌اچ‌پی نباید داخلِ رشته‌ی SQL باشد.
//
// MySQL کامنتِ `//` نمی‌شناسد (فقط `--`، `#` و `/* */`). یک بار دو خط
// توضیح **داخلِ** کوتیشنِ یک `INSERT` نوشته شدند و نتیجه‌اش این بود که
// ثبتِ هر یادآورِ تازه با خطای نحویِ SQL رد می‌شد — در حالی که:
//   • `php -l` سبز بود (رشته از دیدِ PHP کاملاً درست است)،
//   • ویرایش و «انجام شد» کار می‌کردند (کوئریِ دیگری بودند)،
//   • و هیچ تستی آن مسیر را با پیلودِ واقعی صدا نمی‌زد.
// یعنی دقیقاً همان خانواده‌ی خرابیِ **بی‌صدا**ی این پروژه.
//
// ⚠ فقط داخلِ رشته سنجیده می‌شود، نه در کدِ عادی: `//` در خودِ PHP
//   کامنتِ درستی است و توکنایزر آن دو را از هم جدا می‌کند. و فقط
//   رشته‌هایی که واقعاً SQL به نظر می‌رسند، وگرنه هر رشته‌ی حاویِ یک
//   آدرسِ `https://` هشدارِ الکی می‌داد — و هشدارِ الکی از نبودِ تست
//   بدتر است.
T::group('قاعده ۱۷ — کامنتِ پی‌اچ‌پی داخلِ رشته‌ی SQL نباشد');

$badSql  = [];
$sqlScan = 0;
$sqlWord = '~\b(SELECT|INSERT\s+INTO|UPDATE|DELETE\s+FROM|REPLACE\s+INTO)\b~i';
foreach (['api', 'api/v1', 'includes', 'admin', 'store', 'deploy', 'tests', '.'] as $dir) {
    foreach (glob(__DIR__ . '/../' . $dir . '/*.php') as $p) {
        $sqlScan++;
        foreach (token_get_all(file_get_contents($p)) as $tk) {
            if (!is_array($tk)) { continue; }
            if ($tk[0] !== T_CONSTANT_ENCAPSED_STRING && $tk[0] !== T_ENCAPSED_AND_WHITESPACE
                && $tk[0] !== T_INLINE_HTML) { continue; }
            if ($tk[0] === T_INLINE_HTML) { continue; }
            if (!preg_match($sqlWord, $tk[1])) { continue; }
            // خطی که با `//` یا `#` شروع می‌شود — یعنی کامنتی که یادش
            // رفته بیرونِ رشته بماند. (`--` کامنتِ خودِ SQL است و مجاز.)
            foreach (explode("\n", $tk[1]) as $ln) {
                if (preg_match('~^\s*(//|#\s)~', $ln)) {
                    $badSql[] = basename($p) . ' خط ' . $tk[2] . ' → ' . trim(mb_substr($ln, 0, 60));
                    break;
                }
            }
        }
    }
}
T::bulk($sqlScan, $badSql, 'هیچ رشته‌ی SQL ای کامنتِ پی‌اچ‌پی داخلش ندارد');

// ---------------------------------------------------------------
// ⛔ قاعده ۱۶ — لینک یا ریدایرکتِ داخلی باید به فایلی برسد که هست.
//
// قاعده‌ی nginx این است: `location ~ \.php$` شاملِ
// `include snippets/fastcgi-php.conf` می‌شود که خودش `try_files $uri =404`
// دارد — یعنی آدرسی که فایلش روی دیسک نباشد اصلاً به PHP **نمی‌رسد**.
// نتیجه‌اش صفحه‌ی ۴۰۴ خودِ nginx است: بدون قالب، بدون منو، و بدون هیچ
// ردی در لاگِ PHP. یعنی این خرابی **بی‌صداست** و هیچ‌کدام از تست‌های
// دیگر نمی‌بیندش — نه `php -l` (فایل نحوش درست است)، نه تست‌های رفتاری
// (آن صفحه‌ها اصلاً صدا زده نمی‌شوند).
//
// دو راهِ افتادن در آن، و هر دو یک بار در همین پروژه ممکن بوده:
//   ۱. صفحه‌ای حذف شود ولی لینکش در منو بماند (مثل حذفِ
//      `admin/all-transactions.php`).
//   ۲. `redirectWithMessage()` بعد از ذخیره به صفحه‌ای برود که نیست —
//      آن‌وقت کاربر «ذخیره» می‌زند و ۴۰۴ می‌بیند، و چون خودِ ذخیره
//      انجام شده، هیچ خطایی هم در کار نیست که راهنمایی‌اش کند.
T::group('قاعده ۱۶ — هر لینک و ریدایرکتِ داخلی باید فایل داشته باشد');

$root      = realpath(__DIR__ . '/..');
$linkFiles = [];
foreach (['.', 'admin', 'store', 'includes'] as $d) {
    foreach (glob($root . '/' . $d . '/*.php') as $p) { $linkFiles[realpath($p)] = true; }
}
foreach (glob($root . '/assets/js/*.js') as $p) { $linkFiles[realpath($p)] = true; }

$deadLinks  = [];
$linkChecks = 0;
foreach (array_keys($linkFiles) as $p) {
    $src = file_get_contents($p);
    $targets = [];

    // href="…" / action="…" و ریدایرکت‌های سمت سرور و مرورگر
    foreach ([
        '~\b(?:href|action)\s*=\s*"([^"]*)"~',
        "~\\bredirectWithMessage\\(\\s*'([^']*)'~",
        "~\\bheader\\(\\s*'Location:\\s*([^']*)'~",
        "~\\blocation(?:\\.href)?\\s*=\\s*'([^']*)'~",
    ] as $re) {
        if (preg_match_all($re, $src, $m)) {
            foreach ($m[1] as $t) { $targets[] = $t; }
        }
    }

    foreach ($targets as $t) {
        // تنها مسیرِ ثابتِ APP_BASE_PATH شناخته می‌شود؛ بقیه‌ی مقدارهای
        // پویا (متغیر، الحاق رشته، اکوی PHP) عمداً سنجیده نمی‌شوند —
        // هشدارِ الکی از نبودِ تست بدتر است.
        $t = str_replace('<?= APP_BASE_PATH ?>', '', $t);
        $t = preg_replace('~[?#].*$~', '', $t);
        if ($t === '' || $t === '/') { continue; }
        if (str_contains($t, '<?') || str_contains($t, '$') || str_contains($t, '{')) { continue; }
        if (str_contains($t, "'") || str_contains($t, ' . ')) { continue; }  // الحاقِ رشته
        if (preg_match('~^(?:[a-z]+:|//|#)~i', $t)) { continue; }
        // فقط آدرسِ فایل؛ مسیرهای بدون پسوند کارِ nginx و try_files است.
        if (!preg_match('~\.[a-z0-9]{2,5}$~i', $t)) { continue; }

        $linkChecks++;

        // ⛔ فایلِ مشترک (includes/ و assets/js/) روی صفحه‌هایی با عمقِ
        // مختلف اجرا می‌شود و مرورگر مسیرِ نسبی را نسبت به **آدرسِ
        // صفحه** حل می‌کند، نه محلِ خودِ فایل. پس مسیرِ نسبی آنجا
        // «گاهی درست» است و همین بدترین حالت است: از ریشه کار می‌کند و
        // از `/admin/` نه. مطلق بنویسید (`APP_BASE_PATH` یا
        // `window.APP_BASE`). حل کردنش نسبت به ریشه در همین تست، خودِ
        // تست را نسبت به این باگ کور می‌کرد.
        $shared = str_starts_with($p, $root . '/includes/')
               || str_starts_with($p, $root . '/assets/');
        if ($shared && !str_starts_with($t, '/')) {
            $deadLinks[] = str_replace($root . '/', '', $p)
                         . ' → ' . $t . ' (مسیرِ نسبی در فایلِ مشترک)';
            continue;
        }

        $abs = str_starts_with($t, '/')
            ? $root . $t
            : dirname($p) . '/' . $t;

        if (!is_file($abs)) {
            $deadLinks[] = str_replace($root . '/', '', $p) . ' → ' . $t;
        }
    }
}
T::ok($linkChecks >= 30, 'لینک‌های ثابت پیدا شدند', 'سنجیده شد: ' . $linkChecks);
T::bulk($linkChecks, $deadLinks, 'هر لینک و ریدایرکتِ داخلی فایلِ موجود دارد');

// ---------------------------------------------------------------
// ⛔ قاعده ۲۱ — هر لینکِ `due.php?t=…` باید زبانه‌ی واقعی باشد.
//
// پنج مقصدِ جدا («آینده مالی»، «تقویم مالی»، «سررسیدها»، «یادآورها»)
// در یک صفحه با سه زبانه ادغام شدند و آدرس‌های قدیمی به همان زبانه
// هدایت می‌شوند. آن هدایت‌ها **جای بوک‌مارک، میان‌برِ PWA، اعلانِ داخلِ
// اپ، و ایمیل‌هایی که از قبل فرستاده شده‌اند** را می‌گیرند.
//
// ⛔ و خرابی‌اش کاملاً بی‌صداست: `due.php` هر `?t=` ناشناخته را به
//    زبانه‌ی پیش‌فرض می‌برد. پس عوض کردنِ نامِ یک زبانه هیچ خطایی
//    نمی‌دهد — فقط لینکِ ایمیلِ «یادآورها» از فردا کاربر را به فهرستِ
//    سررسید می‌برد و هیچ‌کس نمی‌فهمد چرا.
T::group('قاعده ۲۱ — زبانه‌های سررسید: لینک و فهرست یکی بمانند');

$dueSrc = @file_get_contents(__DIR__ . '/../due.php') ?: '';
$tabKeys = [];
if (preg_match('~const\s+DUE_TABS\s*=\s*\[(.*?)\];~s', $dueSrc, $m)) {
    preg_match_all("~'([a-z_]+)'\s*=>~", $m[1], $km);
    $tabKeys = $km[1];
}
T::ok(count($tabKeys) >= 2, 'فهرستِ DUE_TABS در due.php پیدا شد',
    'زبانه‌ها: ' . implode(', ', $tabKeys));

$tabBad = [];
$tabSeen = 0;
foreach (['*.php', 'includes/*.php', 'api/*.php', 'admin/*.php', 'store/*.php'] as $g) {
    foreach (glob(__DIR__ . '/../' . $g) as $p) {
        // ⚠ بی‌توضیح‌ها: توضیحِ «`due.php?t=calendar` هدایت می‌شود» لینک نیست.
        $src = '';
        foreach (token_get_all(file_get_contents($p)) as $tk) {
            if (is_array($tk) && in_array($tk[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
            $src .= is_array($tk) ? $tk[1] : $tk;
        }
        if (!preg_match_all("~due\.php\?t=([a-z_]+)~", $src, $mm)) { continue; }
        foreach ($mm[1] as $key) {
            $tabSeen++;
            if (!in_array($key, $tabKeys, true)) {
                $tabBad[] = str_replace(__DIR__ . '/../', '', $p) . " → t={$key} (زبانه‌ای به این نام نیست)";
            }
        }
    }
}
T::ok($tabSeen >= 3, 'لینک‌های زبانه‌دار پیدا شدند', 'سنجیده شد: ' . $tabSeen);
T::bulk($tabSeen, $tabBad, 'هر لینکِ `due.php?t=…` به زبانه‌ی موجود می‌رود');

// ⚠ و خودِ استاب‌ها: صفحه‌ای که ادغام شده باید **بماند** و هدایت کند.
//   حذفشان بوک‌مارک و ایمیل‌های فرستاده‌شده را می‌شکند، بی‌هیچ خطایی.
$stubBad = [];
// ⛔ `calendar.php` حالا به تقویمِ خانه می‌رود (`homeCalendarUrl()`)، نه
//    به زبانه‌ای که دیگر نیست — همان دو پرشِ بی‌صدا.
foreach (['upcoming.php' => 'due.php?t=list', 'calendar.php' => 'homeCalendarUrl(',
          'reminders.php' => 'due.php?t=reminders'] as $file => $target) {
    $p = __DIR__ . '/../' . $file;
    if (!is_file($p)) { $stubBad[] = "$file — فایل حذف شده؛ بوک‌مارک و ایمیل‌های قبلی می‌شکنند"; continue; }
    $s = file_get_contents($p);
    if (!str_contains($s, $target)) {
        $stubBad[] = "$file — به `{$target}` هدایت نمی‌کند";
    }
    if (!str_contains($s, 'Auth::requireLogin()')) {
        $stubBad[] = "$file — بدونِ ورود هم هدایت می‌کند (باید به صفحه‌ی ورود برود)";
    }
}
T::bulk(3, $stubBad, 'آدرس‌های قدیمی هنوز به زبانه‌ی درست هدایت می‌شوند');

// ⛔ تقویم از «سررسیدها» بیرون آمد و روی خانه است (خواسته‌ی مالکِ نصب:
//    «از جای قبلی پاکش کن»). زبانه‌ای که برگردد، تقویم را دو جا می‌گذارد؛
//    و `?t=calendar`ِ بوک‌مارک‌شده بی هدایت بی‌صدا به «سررسیدها» می‌رفت.
$calBad = [];
if (in_array('calendar', $tabKeys, true)) { $calBad[] = 'due.php — «تقویم» هنوز زبانه‌ی DUE_TABS است'; }
if (is_file(__DIR__ . '/../includes/due_tab_calendar.php')) { $calBad[] = 'includes/due_tab_calendar.php — رندرکننده‌ی دوم هنوز هست'; }
if (!preg_match('~if \(\$tab === \'calendar\'\) \{.*?homeCalendarUrl\(.*?exit;~s', $dueSrc)
    || strpos($dueSrc, "if (\$tab === 'calendar')") > strpos($dueSrc, 'if (!isset(DUE_TABS[$tab]))')) {
    $calBad[] = 'due.php — `?t=calendar` پیش از افتادن به زبانه‌ی پیش‌فرض به homeCalendarUrl() هدایت نمی‌شود';
}
T::bulk(3, $calBad, '⛔ تقویم فقط روی خانه است و آدرسِ قدیمیِ زبانه‌اش به آنجا می‌رود');

// ---------------------------------------------------------------
T::group('نحو — هر فایل PHP باید بدون خطا پارس شود');

$bad = [];
// ⛔ قاعده ۲۲ — نامِ برند فقط از `APP_NAME` می‌آید.
//
// `config.php` ثابتِ `APP_NAME` را دارد و بیشترِ جاها از همان می‌خوانند،
// ولی دو نقطه‌ی **دیده‌شدنی** سخت‌کد مانده بودند: موضوعِ ایمیلِ یادآوریِ
// روزانه، و فیلدِ `app` داخلِ خودِ فایلِ بکاپِ کاربر.
//
// ⛔ چرا مهم است: خرابی‌اش برای مالکِ نصب **بی‌صداست**. کسی که برند را
//    عوض می‌کند، اپ را باز می‌کند و همه‌چیز درست است — ولی ایمیل‌هایش با
//    نامِ ما می‌روند و فایل‌های بکاپش نامِ ما را دارند. و آن ایمیل با
//    cron می‌رود، پس خروجی‌اش را هیچ‌کس نمی‌بیند.
//
// ⚠ با توکنایزر است نه grep: همین توضیح، خودش کلمه‌ی برند را دارد و با
//   جست‌وجوی متنی تست روی فایلِ **سالم** هم قرمز می‌شد.
T::group('قاعده ۲۲ — نامِ برند فقط از APP_NAME');

$brand = 'حساب لند';
$brandFiles = [];
foreach (['.', 'includes', 'api', 'admin', 'store', 'deploy'] as $dir) {
    foreach (glob(__DIR__ . '/../' . $dir . '/*.php') as $p) { $brandFiles[realpath($p)] = true; }
}
// ⚠ متغیرِ جدا، نه `$bad`: بلوکِ بعدی (بررسیِ نحو) هم `$bad[]` می‌زند
//   بدونِ خالی کردنش، پس با نامِ مشترک، خطاهای این قاعده در گزارشِ آن
//   یکی هم تکرار می‌شدند — یعنی تست **دروغ** می‌گفت کدام بررسی شکسته.
//   در همان اولین اجرا دیده شد.
$badBrand = [];
$scanned = 0;
foreach (array_keys($brandFiles) as $path) {
    // `config/` استثناست: **جای تعریفِ** خودِ ثابت است.
    if (str_contains($path, '/config/')) { continue; }
    $src = (string)@file_get_contents($path);
    if (!str_contains($src, $brand)) { $scanned++; continue; }
    $scanned++;

    $lines = explode("\n", $src);
    foreach (token_get_all($src) as $tok) {
        if (!is_array($tok)) { continue; }
        // فقط رشته‌ی واقعی — کامنت و docblock اینجا اصلاً نمی‌آیند.
        if ($tok[0] !== T_CONSTANT_ENCAPSED_STRING && $tok[0] !== T_ENCAPSED_AND_WHITESPACE
            && $tok[0] !== T_INLINE_HTML) { continue; }
        if (!str_contains($tok[1], $brand)) { continue; }

        // ⚠ الگوی مجازِ برگشتی: `defined('APP_NAME') ? APP_NAME : 'حساب لند'`
        //   روی همان خط. نصبی که هنوز ثابت را ندارد نباید بشکند.
        $line = $lines[$tok[2] - 1] ?? '';
        if (str_contains($line, 'APP_NAME')) { continue; }

        $badBrand[] = basename(dirname($path)) . '/' . basename($path) . ':' . $tok[2]
               . ' — نامِ برند سخت‌کد شده؛ از APP_NAME بخوانید';
    }
}
T::bulk($scanned, $badBrand, 'هیچ متنِ دیده‌شدنی‌ای نامِ برند را سخت‌کد نکرده');

// ---------------------------------------------------------------
T::group('قاعده ۲۵ — قاعده‌ی نام کاربری فقط در usernameRuleError()');

/*
 * سه مسیر نام کاربری می‌نویسند (ثبت‌نام، پنل مدیر، پروفایل) و هر کدام
 * الگوی خودش را داشت. دو تا با هم نمی‌خواندند: یکی نقطه را می‌ساخت و
 * دیگری ردش می‌کرد — پس کاربری با نامِ `ali.k` **هرگز** نمی‌توانست
 * پروفایلش را ذخیره کند، و خطا درباره‌ی فیلدی بود که دست نزده بود.
 *
 * ⚠ با توکنایزر است نه grep: همین توضیح خودش الگو را در متن دارد و با
 *   جست‌وجوی متنی، تست روی فایلِ **سالم** هم قرمز می‌شد — همان درسی که
 *   قاعده ۲۲ داد.
 */
$badUser = [];
$userScanned = 0;
$userFiles = [];
foreach (['.', 'includes', 'api', 'admin', 'store'] as $dir) {
    foreach (glob(__DIR__ . '/../' . $dir . '/*.php') as $p) { $userFiles[realpath($p)] = true; }
}
foreach (array_keys($userFiles) as $path) {
    $userScanned++;
    // ⛔ تنها استثناء: خودِ جای تعریفِ قاعده.
    if (basename($path) === 'signup.php') { continue; }

    $src = (string)@file_get_contents($path);
    if (!str_contains($src, 'username')) { continue; }
    $lines = explode("\n", $src);

    foreach (token_get_all($src) as $tok) {
        if (!is_array($tok) || $tok[0] !== T_CONSTANT_ENCAPSED_STRING) { continue; }
        // الگویی که کلاسِ کاراکترِ نام کاربری را تعریف می‌کند: هم
        // `[a-zA-Z0-9_.]` هم `[A-Za-z0-9_]{3,50}` را می‌گیرد.
        if (!preg_match('/\[[aA]-[zZ][A-Za-z]*-?[A-Za-z]*0-9[_.\-]/', $tok[1])) { continue; }

        // ⚠ فقط وقتی که همان خط واقعاً روی **نام کاربری** اعمالش کند.
        //   بدونِ این شرط، توصیفِ شکلِ کلیدِ پنلِ پیامک در
        //   `includes/sms.php` هم قرمز می‌شد — یعنی هشدارِ الکی روی
        //   فایلِ سالم، که از نبودِ تست بدتر است. در همان اجرای اول شد.
        $line = $lines[$tok[2] - 1] ?? '';
        if (!str_contains($line, 'username')) { continue; }

        $badUser[] = basename(dirname($path)) . '/' . basename($path) . ':' . $tok[2]
            . ' — الگوی نام کاربری اینجا تکرار شده؛ از usernameRuleError() رد شوید';
    }
}
T::bulk($userScanned, $badUser, 'قاعده‌ی نام کاربری فقط یک جا تعریف شده');

// ---------------------------------------------------------------
// ⛔ قاعده ۲۶ — گیتِ اشتراک فقط روی مسیرِ **ساختِ رکوردِ تازه**.
//
// اشتراکِ تمام‌شده نباید دفترِ کاربر را قفل کند: ویرایش، حذف، تسویه و
// پرداختِ قسط باید کار کنند، وگرنه دفتر روی واقعیتِ ماه‌ها پیش یخ
// می‌زند — و دفترِ غلط از دفترِ نداشته بدتر است.
//
// فهرست **بسته** است و دو طرفه می‌سنجد، چون هر دو خرابی بی‌صدایند:
//   • گیت روی اندپوینتی که نباید → کاربر نمی‌تواند چکِ خودش را
//     «پاس شد» کند و فقط یک پیامِ «اشتراک لازم است» می‌بیند.
//   • نبودِ گیت روی اندپوینتِ ساخت → قفل با یک درخواستِ مستقیم دور
//     می‌خورد، دقیقاً مثل روزی که فقط صفحه گیت داشت.
T::group('قاعده ۲۶ — گیتِ اشتراک فقط روی ساختِ رکوردِ تازه');

// اندپوینت‌هایی که **باید** `apiRequirePlan()` داشته باشند.
// ⚠ `save_recurring` هم می‌سازد هم ویرایش می‌کند، پس گیتش شرطی است
//   (`$id === 0`) و همین‌جا هم شرطی سنجیده می‌شود.
const PLAN_GATE_REQUIRED = [
    'add_cheque.php'    => 'cheques',
    'add_debt.php'      => 'debts',
    'save_trade.php'    => 'trades',
    'save_recurring.php'=> 'recurring',
];

$planScanned = 0;
$badPlan     = [];

foreach (glob(__DIR__ . '/../api/*.php') as $path) {
    $planScanned++;
    $name = basename($path);
    $src  = (string)@file_get_contents($path);

    // با توکنایزر، نه با grep: همین توضیح خودش نامِ تابع را دارد و
    // جست‌وجوی متنی تست را روی فایلِ سالم قرمز می‌کرد.
    $calls = false;
    $toks  = token_get_all($src);
    foreach ($toks as $t) {
        if (is_array($t) && $t[0] === T_STRING && $t[1] === 'apiRequirePlan') { $calls = true; break; }
    }

    if (isset(PLAN_GATE_REQUIRED[$name])) {
        if (!$calls) {
            $badPlan[] = "api/{$name} — اندپوینتِ ساخت است ولی apiRequirePlan() ندارد؛ "
                       . 'قفل با یک درخواستِ مستقیم دور می‌خورد';
        } elseif ($name === 'save_recurring.php' && !str_contains($src, '$id === 0')) {
            // ⛔ گیتِ بی‌قید اینجا یعنی ویرایشِ قانونِ موجود هم بسته
            //    می‌شود: کاربرِ منقضی نمی‌تواند مبلغِ قبضِ همیشگی‌اش را
            //    هم درست کند.
            $badPlan[] = "api/{$name} — گیت باید فقط برای رکوردِ تازه باشد (\$id === 0)";
        }
    } elseif ($calls) {
        $badPlan[] = "api/{$name} — رکوردِ تازه نمی‌سازد ولی گیت دارد؛ "
                   . 'با اشتراکِ تمام‌شده کاربر نمی‌تواند داده‌ی خودش را ویرایش/تسویه کند';
    }
}
T::bulk($planScanned, $badPlan, 'گیتِ اشتراک فقط روی اندپوینت‌های ساخت است');

// و نیمه‌ی صفحه: هیچ صفحه‌ای نباید محتوا را با یک صفحه‌ی قفل عوض کند.
$badLock = [];
foreach (glob(__DIR__ . '/../*.php') as $path) {
    $src = (string)@file_get_contents($path);
    foreach (token_get_all($src) as $t) {
        if (is_array($t) && $t[0] === T_STRING && $t[1] === 'requirePlanOrLock') {
            $badLock[] = basename($path) . ' — صفحه نباید با قفل جایگزین شود؛ '
                       . 'از planReadOnly() + planReadOnlyNotice() رد شوید';
        }
    }
}
T::bulk(count(glob(__DIR__ . '/../*.php')), $badLock,
    '⛔ هیچ صفحه‌ای محتوای کاربر را پشتِ صفحه‌ی قفل پنهان نمی‌کند');

// ---------------------------------------------------------------
// ⛔ قاعده ۲۷ — سرآیندِ آی‌پیِ CDN هرگز در PHP خوانده نمی‌شود.
//
// وقتی سایت پشتِ کلادفلر می‌رود، `REMOTE_ADDR` آی‌پیِ لبه است و آی‌پیِ
// واقعی در `CF-Connecting-IP` می‌آید. وسوسه‌ی طبیعی این است که همان‌جا
// در PHP خوانده شود — و آن **یک آسیب‌پذیری است، نه یک میان‌بر**:
// سرآیند را هر کسی می‌تواند بفرستد، پس سدِ حدس رمز، سقفِ بازیابیِ رمز،
// سدِ ثبت‌نام و سقفِ پیامک همگی با یک خطِ curl دور می‌خورند.
//
// جایگزینی باید در nginx باشد (`deploy/nginx-realip.sh`)، چون آنجا فقط
// وقتی انجام می‌شود که **خودِ اتصال** از یکی از بازه‌های کلادفلر آمده
// باشد. آن‌وقت `REMOTE_ADDR` خودش درست است و هیچ‌جای PHP عوض نمی‌شود.
//
// ⚠ با توکنایزر است نه grep: همین توضیح و متنِ خودِ اسکریپت نامِ آن
//   سرآیندها را دارند و با جست‌وجوی متنی، تست روی فایلِ **سالم** هم
//   قرمز می‌شد — همان دامی که قاعده‌های ۹ و ۲۲ هم برایش نوشته شدند.
T::group('قاعده ۲۷ — آی‌پی فقط از REMOTE_ADDR');

$PROXY_KEYS = [
    'HTTP_CF_CONNECTING_IP',
    'HTTP_X_FORWARDED_FOR',
    'HTTP_X_REAL_IP',
    'HTTP_TRUE_CLIENT_IP',
    'HTTP_X_CLIENT_IP',
];
$badProxy = [];
$proxyScanned = 0;
foreach (['api', 'api/v1', 'includes', 'admin', 'store', '.'] as $dir) {
    foreach (glob(__DIR__ . '/../' . $dir . '/*.php') as $path) {
        $proxyScanned++;
        $src = (string)@file_get_contents($path);
        foreach (token_get_all($src) as $t) {
            // فقط رشته‌های واقعیِ کد، نه کامنت و نه HTML بیرونِ تگ.
            if (!is_array($t) || $t[0] !== T_CONSTANT_ENCAPSED_STRING) { continue; }
            $val = trim($t[1], "'\"");
            if (in_array($val, $PROXY_KEYS, true)) {
                $badProxy[] = basename($path) . " — «{$val}» جعل‌شدنی است؛ "
                            . 'جایگزینی باید در nginx باشد (deploy/nginx-realip.sh)';
            }
        }
    }
}
T::bulk($proxyScanned, $badProxy,
    '⛔ هیچ فایلی سرآیندِ آی‌پیِ CDN را باور نمی‌کند');

/*
 * ⛔ و نیمه‌ی دومِ همین قاعده: مسیرِ سنجشِ `/__realip` که همان اسکریپت
 *   می‌سازد باید واقعاً بسته باشد.
 *
 *   نسخه‌ی اول با `allow 127.0.0.1; deny all;` بسته **به نظر می‌رسید** و
 *   نبود: `return` در فازِ rewrite اجرا می‌شود و آن پیش از فازِ access
 *   است، پس آن دو خط هرگز نوبتشان نمی‌رسد. روی سرور واقعی دیده شد —
 *   درخواست از بیرون به‌جای ۴۰۳، **۲۰۰** گرفت — و با nginx 1.24 بازتولید
 *   شد. کامنتِ بالای همان بلوک هم ادعا می‌کرد «بیرون بسته است»، یعنی
 *   نگهبانی که کار نمی‌کند به‌علاوه‌ی یک ادعای نادرست کنارش.
 *
 *   گیتِ درست روی `$realip_remote_addr` است (آدرسِ واقعیِ اتصال، پیش از
 *   جایگزینی): بازدیدکننده‌ی بیرونی ۴۰۴ می‌گیرد ولی سنجشِ خودِ اسکریپت —
 *   که از 127.0.0.1 وصل می‌شود و سرآیند می‌فرستد — دست‌نخورده کار می‌کند.
 */
/*
 * ⚠ و بلوک **رندرشده** سنجیده می‌شود، نه متنِ اسکریپت. اسکریپت آن را از
 *   راهِ `awk -v` داخلِ فایلِ سایت می‌نویسد و awk هر دنباله‌ی escape را
 *   **بی‌صدا** می‌خورد: `\.` می‌شود `.`. یعنی چیزی که روی سرور می‌نشیند
 *   با چیزی که در سورس نوشته شده یکی نبود، و تستی که سورس را می‌سنجید
 *   چیزی را تأیید می‌کرد که اجرا نمی‌شود. روی سرور واقعی با همان هشدارِ
 *   awk دیده شد.
 */
$realipSh = __DIR__ . '/../deploy/nginx-realip.sh';
$realipSrc = (string)@file_get_contents($realipSh);
T::ok($realipSrc !== '', 'deploy/nginx-realip.sh وجود دارد');

$rendered = '';
if (preg_match('/^build_block\(\) \{.*?\n\}/ms', $realipSrc, $m)) {
    $tmp = sys_get_temp_dir() . '/realip_block_' . getmypid() . '.sh';
    file_put_contents($tmp, "BEGIN=''\nEND=''\n" . $m[0] . "\nbuild_block '127.0.0.1'\n");
    $rendered = (string)@shell_exec('bash ' . escapeshellarg($tmp) . ' 2>/dev/null');
    @unlink($tmp);
}
T::ok($rendered !== '', 'بلوکِ nginx رندر شد');
T::ok(str_contains($rendered, 'if ($realip_remote_addr !~')
      && str_contains($rendered, 'return 404;'),
    '⛔ گیتِ /__realip روی آدرسِ واقعیِ اتصال است');
T::ok(!preg_match('/awk\s+-v\s+block=/', $realipSrc),
    '⛔ بلوک با `awk -v` منتقل نمی‌شود — awk مقدارِ -v را تفسیر می‌کند و '
    . 'هر escape را بی‌صدا عوض می‌کند (`\\.`→`.`، `\\n`→خطِ واقعی)');
T::ok(!preg_match('/deny\s+all/', $rendered),
    '⛔ allow/deny کنارِ return برنگشته — آنجا اصلاً اجرا نمی‌شود '
    . 'و مسیر را برای کلِ اینترنت باز می‌گذارد');

// ---------------------------------------------------------------
// ⛔ قاعده ۲۸ — دو تصمیمِ تازه، هر کدام فقط یک جا.
//
// نیمه‌ی اول: **گیتِ ورودِ پیامکی**. `SmsLogin::loginAllowedFor()` تنها
// جایی است که `planAllows(..., 'sms_login')` خوانده می‌شود، چون آن گیت
// دو استثنای عمدی دارد (کلیدِ مدیر که از پرداخت هم بالاتر است، و
// حسابِ بی‌رمز که پیامک تنها درِ اوست). صفحه‌ای که مستقیم `planAllows`
// را بپرسد آن دو را نمی‌بیند و **بی‌صدا** کاربر را بیرونِ دفترِ خودش
// قفل می‌کند.
//
// نیمه‌ی دوم: **هیچ `password_verify()` بدونِ سنجشِ حسابِ بی‌رمز**.
// از وقتی `password_hash` می‌تواند `NULL` باشد، هر مسیرِ تازه‌ای که
// یادش برود این را بسنجد یک هشدارِ `Deprecated` در هر تلاش می‌سازد (و
// در PHP 9 یک ۵۰۰). همان شکلِ قاعده ۱۴: فایل باید **در همان فایل**
// نشانه‌ی سنجش را داشته باشد، تا مسیرِ پنجمیِ فردا جا نماند.
//
// ⚠ با توکنایزر است نه grep: همین توضیح هر دو نام را دارد و با
//   جست‌وجوی متنی، تست روی فایلِ سالم هم قرمز می‌شد.
T::group('قاعده ۲۸ — گیتِ پیامک و نگهبانِ حسابِ بی‌رمز');

$phpFiles = [];
foreach (['api', 'api/v1', 'includes', 'admin', 'store', 'deploy', '.'] as $dir) {
    foreach (glob(__DIR__ . '/../' . $dir . '/*.php') as $p) { $phpFiles[realpath($p)] = true; }
}
$phpFiles = array_keys($phpFiles);

// ⛔ فقط **کامنت‌ها** حذف می‌شوند، نه رشته‌ها.
//
// ⚠ نسخه‌ی اول رشته‌های متنی را هم دور می‌ریخت و روی فایلِ **سالم**
//   قرمز شد: نگهبانِ واقعی در `auth.php` به شکلِ
//   `$user['password_hash'] === null` نوشته شده و با حذفِ رشته‌ها به
//   `$user[] === null` تبدیل می‌شد. کامنت‌ها منشأ هشدارِ الکی‌اند (همین
//   توضیح خودش هر دو نام را دارد)؛ رشته‌ها بخشِ واقعیِ کدند.
$noComments = static function (string $src): string {
    $out = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t)) {
            if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)) {
                continue;
            }
            $out .= $t[1];
        } else {
            $out .= $t;
        }
    }
    return $out;
};

// ⚠ `plan.php` استثناست چون **تعریف** است نه فراخوان: فهرستِ Pro-only
//   خودش آنجاست. `sms_login.php` هم تنها فراخوانِ مجاز است.
$gateHome = ['plan.php', 'sms_login.php'];

$badGate = [];
$badNull = [];
foreach ($phpFiles as $p) {
    $code = $noComments((string)@file_get_contents($p));
    $rel  = basename(dirname($p)) . '/' . basename($p);

    if (str_contains($code, 'planAllows(') && str_contains($code, "'sms_login'")
        && !in_array(basename($p), $gateHome, true)) {
        $badGate[] = $rel;
    }

    if (str_contains($code, 'password_verify(')
        && !str_contains($code, 'userHasPassword(')
        && !preg_match("/'password_hash'\]\s*(===|!==)\s*null/", $code)
        && !str_contains($code, '$needsPassword')
        && !str_contains($code, '$hasPassword')) {
        $badNull[] = $rel;
    }
}

T::bulk(count($phpFiles), $badGate,
    "⛔ فقط `SmsLogin::loginAllowedFor()` گیتِ 'sms_login' را می‌خواند");
T::bulk(count($phpFiles), $badNull,
    '⛔ هر فایلی که `password_verify()` دارد، حسابِ بی‌رمز را هم می‌سنجد');

// ---------------------------------------------------------------
// ⛔ قاعده ۲۹ — موجودیِ حساب هرگز کش نمی‌شود.
//
// یک بار کشِ درخواستی روی `walletBalances()` گذاشته شد تا فراخوانیِ
// دوباره‌ی صفحه‌ی خانه ارزان شود. **پنج مجموعه‌ی تست همان‌جا قرمز شدند**:
// هر مسیری که پول می‌نویسد و بعد موجودی می‌خواند عددِ **پیش از** تغییر
// را می‌گرفت — نه خطایی، نه استثنایی، فقط یک عددِ غلط در پاسخ.
//
// راهِ درستِ ارزان کردنش این است که فراخواننده نتیجه را **یک بار بگیرد و
// پاس بدهد** (پارامترِ `$rows` در `totalBalance()` / `pinnedWallets()` و
// `$walletRows` در `safeToSpend()` / `financialHighlights()`)، نه اینکه
// حقیقتِ پول در حافظه بماند.
//
// ⚠ وسوسه‌ی برگرداندنش واقعی است، چون «فقط یک کوئری» به نظر می‌رسد. این
//   قاعده همان را می‌بندد تا تصمیم آگاهانه باشد، نه تصادفی.
T::group('قاعده ۲۹ — موجودیِ حساب کش نمی‌شود');

$balSrc  = php_strip_whitespace(__DIR__ . '/../includes/functions.php');
$badCache = [];

// ⚠ بدنه تا **تعریفِ تابعِ بعدی** بریده می‌شود، نه تا اولین `}`ی که
//   در متن پیدا شود: هر `if`ِ داخلِ تابع یکی از آن‌ها دارد، و نسخه‌ی اولِ
//   همین بررسی به همین دلیل تا نصفِ فایل جلو رفت و `static`ِ تابعِ
//   **دیگری** را پیدا کرد — یعنی روی فایلِ سالم قرمز شد.
$fnStart = strpos($balSrc, 'function walletBalances(');
if ($fnStart !== false) {
    $next = strpos($balSrc, 'function ', $fnStart + 10);
    $body = substr($balSrc, $fnStart, $next === false ? null : $next - $fnStart);

    if (preg_match('/\bstatic\s+\$/', $body)) {
        $badCache[] = 'walletBalances() یک `static` دارد — کشِ موجودی برگشته است';
    }
    if (preg_match('/\bwalletBalanceCache/i', $balSrc)) {
        $badCache[] = 'کشِ موجودی (walletBalanceCache…) دوباره ساخته شده است';
    }
} else {
    $badCache[] = 'بدنه‌ی walletBalances() پیدا نشد — قاعده ۲۹ کور شده';
}

// و راهِ ارزانش باید سرِ جایش بماند، وگرنه کسی که کش را برمی‌دارد
// جایگزینی هم ندارد و دوباره وسوسه می‌شود.
foreach ([
    'totalBalance'        => '/function\s+totalBalance\s*\(\s*int\s+\$userId\s*,\s*\?array\s+\$rows/',
    'pinnedWallets'       => '/function\s+pinnedWallets\s*\(\s*int\s+\$userId\s*,\s*\?array\s+\$rows/',
    'safeToSpend'         => '/function\s+safeToSpend\s*\([^)]*\?array\s+\$walletRows/',
    'financialHighlights' => '/function\s+financialHighlights\s*\([^)]*\?array\s+\$walletRows/',
] as $fn => $re) {
    if (!preg_match($re, $balSrc)) {
        $badCache[] = "{$fn}() دیگر ردیف‌های آماده را نمی‌پذیرد؛"
                    . ' بدونِ آن صفحه‌ی خانه سنگین‌ترین کوئری را دو بار می‌زند';
    }
}

T::bulk(5, $badCache, '⛔ موجودی کش نمی‌شود، بلکه یک بار خوانده و پاس داده می‌شود');

// ---------------------------------------------------------------
// ⛔ قاعده ۳۰ — ادعای «چیزی بیرون نمی‌رود» از پیکربندی خوانده می‌شود.
//
// `privacy.php` قرار است **هر ادعایش در کد نشان‌دادنی باشد**. یک بار
// همان صفحه با یک جمله‌ی ثابت می‌گفت «هیچ چیزی به سرویس بیرونی فرستاده
// نمی‌شود» — و بردنِ دامنه پشتِ کلادفلر آن را وارونه کرد: TLS روی لبه‌ی
// آن‌ها باز می‌شود، پس رمز و شماره کارتِ کاربر از دیدشان رد می‌شود.
// **هیچ خطایی هم نمی‌داد**؛ فقط صفحه‌ی حریم خصوصی دروغ می‌گفت، و
// `git pull` هم عوضش نمی‌کرد چون جمله ثابت بود.
//
// حالا `outboundDataFlows()` تنها جای این تصمیم است و از خودِ پیکربندی
// می‌خواند. سه چیز باید بسته بماند، و هر سه خرابیِ بی‌صدا می‌سازند:
//   ۱. صفحه باید واقعاً از همان تابع بخواند، نه از متنِ خودش.
//   ۲. ادعای مطلق فقط داخلِ شاخه‌ی «فهرست خالی است» گفته شود.
//   ۳. مصرف‌کننده باید `includes/sms.php` را لود کند — وگرنه
//      `class_exists('Sms')` نادرست می‌شود و قلمِ پیامک **بی‌صدا** از
//      فهرست می‌افتد، بی‌آنکه چیزی خطا بدهد.
T::group('قاعده ۳۰ — حریم خصوصی از روی پیکربندی');

$badPriv  = [];
$fnSrc    = (string)@file_get_contents(__DIR__ . '/../includes/functions.php');
$privPath = __DIR__ . '/../privacy.php';
$privRaw  = (string)@file_get_contents($privPath);

// تنها جای تعریف — نسخه‌ی دوم یعنی صفحه و واقعیت از هم دور می‌افتند.
foreach (['cdnInFront', 'outboundDataFlows'] as $fn) {
    $defs = 0;
    foreach (['api', 'api/v1', 'includes', 'admin', 'store', '.'] as $dir) {
        foreach (glob(__DIR__ . '/../' . $dir . '/*.php') as $p) {
            $defs += preg_match_all('/function\s+' . $fn . '\s*\(/', (string)@file_get_contents($p));
        }
    }
    if ($defs !== 1) {
        $badPriv[] = "{$fn}() باید دقیقاً یک بار تعریف شود (شمرده شد: {$defs})";
    }
}

// و باید هر سه منبع را بپرسد؛ افتادنِ یکی یعنی قلمی که واقعاً بیرون
// می‌رود در صفحه دیده نمی‌شود.
$flowStart = strpos($fnSrc, 'function outboundDataFlows(');
if ($flowStart === false) {
    $badPriv[] = 'outboundDataFlows() پیدا نشد — قاعده ۳۰ کور شده';
} else {
    $nextFn   = strpos($fnSrc, "\nfunction ", $flowStart + 10);
    $flowBody = substr($fnSrc, $flowStart, $nextFn === false ? null : $nextFn - $flowStart);
    foreach ([
        'cdnInFront('  => 'CDN',
        'MAIL_METHOD'  => 'ایمیل',
        'Sms::method(' => 'پیامک',
    ] as $needle => $label) {
        if (!str_contains($flowBody, $needle)) {
            $badPriv[] = "outboundDataFlows() دیگر «{$label}» را نمی‌سنجد؛"
                       . ' آن قلم بی‌صدا از صفحه‌ی حریم خصوصی می‌افتد';
        }
    }
}

// مصرف‌کننده‌ها: باید تابع را صدا بزنند و کلاسِ Sms را هم لود کنند.
//
// ⚠ کامنت‌ها **پیش از** بررسی حذف می‌شوند، وگرنه هر توضیحی که نامِ این
//   تابع را در متنش داشته باشد فایلِ **سالم** را قرمز می‌کند — همان
//   دامی که قاعده ۱۹ و ۳۵ و ۳۸ و ۴۴ هم در آن افتادند، و این بار یک
//   کامنت در `includes/signup.php` گرفتارش کرد.
$privConsumers = 0;
foreach (['api', 'includes', 'admin', 'store', '.'] as $dir) {
    foreach (glob(__DIR__ . '/../' . $dir . '/*.php') as $p) {
        $src = $stripComments($p);
        if (!str_contains($src, 'outboundDataFlows(')
            || preg_match('/function\s+outboundDataFlows\s*\(/', $src)) {
            continue;
        }
        $privConsumers++;
        if (!preg_match('#includes/sms\.php#', $src)) {
            $badPriv[] = basename($p) . ' — outboundDataFlows() را صدا می‌زند ولی'
                       . ' includes/sms.php را لود نمی‌کند؛ قلمِ پیامک بی‌صدا می‌افتد';
        }
    }
}
if ($privConsumers === 0) {
    $badPriv[] = 'هیچ صفحه‌ای outboundDataFlows() را صدا نمی‌زند —'
               . ' یعنی صفحه‌ی حریم خصوصی دوباره از متنِ ثابتِ خودش می‌گوید';
}

// ⚠ کامنت‌ها پیش از تجزیه خالی می‌شوند (با حفظِ شماره‌ی خط)، وگرنه
//   توضیحی که *بالای* همین شاخه نوشته شود تست را روی فایلِ سالم قرمز
//   می‌کند — همان دامی که قاعده‌های ۲۰ و ۲۴ هم برایش نوشته شدند.
$privLines = [];
if ($privRaw !== '') {
    $clean = '';
    foreach (token_get_all($privRaw) as $t) {
        if (is_array($t) && ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) {
            $clean .= str_repeat("\n", substr_count($t[1], "\n"));
        } else {
            $clean .= is_array($t) ? $t[1] : $t;
        }
    }
    $privLines = explode("\n", $clean);
}

$CLAIM   = 'هیچ چیزی به سرویس بیرونی';
$ifLine  = -1;
$elseLine = -1;
foreach ($privLines as $i => $line) {
    if ($ifLine < 0 && preg_match('/if\s*\(\s*\$flows\s*===\s*\[\s*\]\s*\)/', $line)) {
        $ifLine = $i;
    } elseif ($ifLine >= 0 && $elseLine < 0 && preg_match('/\belse\s*:/', $line)) {
        $elseLine = $i;
    }
}
if ($ifLine < 0 || $elseLine < 0) {
    $badPriv[] = 'privacy.php شاخه‌ی «فهرست خالی است» را ندارد؛'
               . ' ادعای مطلق آن‌وقت بی‌قید گفته می‌شود';
} else {
    foreach ($privLines as $i => $line) {
        if (!str_contains($line, $CLAIM)) { continue; }
        if ($i <= $ifLine || $i >= $elseLine) {
            $badPriv[] = 'privacy.php خطِ ' . ($i + 1) . ' — ادعای «چیزی بیرون'
                       . ' نمی‌رود» بیرونِ شاخه‌ی خالی بودنِ فهرست است';
        }
    }
    if (!preg_match('/foreach\s*\(\s*\$flows\s+as/', $clean)) {
        $badPriv[] = 'privacy.php فهرستِ خروجی‌ها را رندر نمی‌کند';
    }
}

// ⛔ ۴) صفحه باید بدونِ ورود هم باز شود.
//
//    سیاستِ حریم خصوصی چیزی است که آدم **پیش از** ساختنِ حساب می‌خواند،
//    و بازبینِ فروشگاه اندروید (مایکت) هم — برای اپی که مجوزِ خواندنِ
//    پیامک می‌خواهد — همین آدرس را باز می‌کند. با `requireLogin()` او
//    به‌جای سیاست یک فرمِ ورود می‌بیند: نه خطایی، نه نشانه‌ای، فقط یک
//    ردِ بازبینی که هیچ‌کس علتش را پیدا نمی‌کند.
//
//    ⚠ متنِ این صفحه درباره‌ی **خودِ برنامه** است نه یک کاربرِ مشخص، پس
//      باز بودنش چیزی لو نمی‌دهد. تنها بندی که به کاربر بند است
//      (دارایی در فروشگاه) باید پشتِ `isLoggedIn()` بماند — و همان هم
//      اینجا سنجیده می‌شود، وگرنه «عمومی کردن» به یک نشتی تبدیل می‌شود.
if (preg_match('/Auth::requireLogin\s*\(/', $clean)) {
    $badPriv[] = 'privacy.php — `Auth::requireLogin()` برگشته است؛'
               . ' بازبینِ فروشگاه به‌جای سیاست، فرمِ ورود می‌بیند';
}
if (preg_match('/StoreShare::linkFor\s*\(/', $clean)
    && !preg_match('/\$__loggedIn\s*&&\s*StoreShare::/', $clean)) {
    $badPriv[] = 'privacy.php — بندِ «دارایی در فروشگاه» پشتِ'
               . ' `$__loggedIn` نیست؛ روی بازدیدکننده‌ی واردنشده می‌شکند';
}

T::bulk(8, $badPriv, '⛔ صفحه‌ی حریم خصوصی از پیکربندیِ واقعی می‌خواند');

// ---------------------------------------------------------------
// ⛔ قاعده ۳۱ — برچسبِ کنترل نمی‌شکند، و ستونِ «عملیات» له نمی‌شود.
//
// **خرابیِ واقعی، و فقط روی دسکتاپ دیده می‌شد:** در `admin/users.php`
// ستونِ عملیات روی ۱۱۵ پیکسل بسته می‌شد، چهار دکمه زیرِ هم می‌افتادند و
// ارتفاعِ هر ردیف از ۶۱ به **۲۱۱** پیکسل می‌رسید — با برچسب‌هایی مثل
// «غیرفعال‌ساز» که نصفه دیده می‌شدند. علتش دو چیزِ به‌هم‌بافته بود:
//   ۱. فارسی **نیم‌فاصله** دارد و مرورگر آن را جای شکستن می‌شناسد، پس
//      کمترین عرضِ لازمِ یک دکمه به یک تکه‌ی کوچک می‌رسید؛
//   ۲. ردیفِ دکمه‌ها `flex-wrap: wrap` بود، پس کمترین عرضِ لازمِ کلِ
//      ستون فقط **یک** دکمه بود و جدول باقیِ عرض را به ستون‌های دیگر داد.
// روی گوشی بی‌عیب بود (جدول آنجا کارتی می‌شود)، پس **«روی گوشی درست
// است» اینجا مدرک نبود** — همان درسِ منوی کناری، آینه‌وار.
//
// ⚠ هر سه تکه لازم‌اند: `nowrap` روی برچسب، `nowrap` روی ردیفِ دکمه‌ها
//   در دسکتاپ، و `width: 1%` روی خودِ ستون. با افتادنِ هرکدام ستون دوباره
//   له می‌شود و هیچ خطایی هم داده نمی‌شود.
T::group('قاعده ۳۱ — برچسبِ کنترل نمی‌شکند');

$badWrap = [];
$cssRaw  = (string)@file_get_contents(__DIR__ . '/../assets/css/style.css');
// ⚠ کامنت‌های CSS پیش از تجزیه حذف می‌شوند، وگرنه همین توضیح‌ها بخشی از
//   بلوکِ قاعده خوانده می‌شوند — همان دامی که قاعده ۲۰ در آن افتاد.
$css = preg_replace('#/\*.*?\*/#s', '', $cssRaw);

/** بدنه‌ی یک قاعده‌ی CSS را برمی‌گرداند (اولین تطابقِ دقیقِ انتخابگر). */
$ruleBody = function (string $haystack, string $selector) {
    if (!preg_match('/(^|\})\s*' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/m', $haystack, $m)) {
        return null;
    }
    return $m[2];
};

foreach (['.btn', '.delete-btn'] as $sel) {
    $body = $ruleBody($css, $sel);
    if ($body === null) {
        $badWrap[] = "قاعده‌ی {$sel} پیدا نشد — قاعده ۳۱ کور شده";
    } elseif (!preg_match('/white-space\s*:\s*nowrap/', $body)) {
        $badWrap[] = "{$sel} دیگر `white-space: nowrap` ندارد؛ برچسبِ فارسی روی"
                   . ' نیم‌فاصله می‌شکند و ستونِ جدول له می‌شود';
    }
}

$cellBody = $ruleBody($css, '.data-table th.actions-cell, .data-table td.actions-cell');
if ($cellBody === null || !preg_match('/width\s*:\s*1%/', $cellBody)) {
    $badWrap[] = 'ستونِ .actions-cell دیگر `width: 1%` ندارد؛ جدول عرضش را'
               . ' به ستون‌های دیگر می‌دهد';
}

// و نیمه‌ی دسکتاپ: ردیفِ دکمه‌ها آنجا نباید بشکند.
// ⚠ بیش از **یک** بلوکِ `min-width: 901px` در فایل هست، پس گرفتنِ اولی
//   کافی نیست: نسخه‌ی اولِ همین بررسی دقیقاً به این دلیل روی فایلِ
//   **سالم** قرمز شد. همه‌ی بلوک‌ها با هم سنجیده می‌شوند.
$desktop = '';
$off = 0;
while (preg_match('/@media\s*\(\s*min-width:\s*901px\s*\)\s*\{/', $css, $m, PREG_OFFSET_CAPTURE, $off)) {
    $start = $m[0][1] + strlen($m[0][0]);
    $depth = 1; $len = strlen($css);
    for ($i = $start; $i < $len && $depth > 0; $i++) {
        if ($css[$i] === '{') { $depth++; }
        elseif ($css[$i] === '}') { $depth--; }
    }
    $desktop .= substr($css, $start, $i - $start - 1);
    $off = $i;
}
if ($desktop === '') {
    $badWrap[] = 'بلوکِ @media (min-width: 901px) پیدا نشد — قاعده ۳۱ کور شده';
} elseif (!preg_match('/\.table-actions\s*\{[^}]*flex-wrap\s*:\s*nowrap/', $desktop)) {
    $badWrap[] = '`.table-actions { flex-wrap: nowrap }` در بلوکِ دسکتاپ نیست؛'
               . ' بدونش `width: 1%` بی‌اثر است و دکمه‌ها دوباره زیرِ هم می‌افتند';
}

// و هر صفحه‌ای که ردیفِ عملیات دارد باید ستونش را هم علامت زده باشد —
// در **هر دو** سر (`th` و `td`)، وگرنه فقط نیمی از رفع اعمال می‌شود.
$actionPages = 0;
foreach (['admin', 'store', '.'] as $dir) {
    foreach (glob(__DIR__ . '/../' . $dir . '/*.php') as $p) {
        $src = (string)@file_get_contents($p);
        if (!str_contains($src, 'table-actions')) { continue; }
        $actionPages++;
        if (!preg_match('/<th[^>]*class="[^"]*actions-cell/', $src)) {
            $badWrap[] = basename($p) . ' — سرستونِ عملیات کلاسِ actions-cell ندارد';
        }
        if (!preg_match('/<td[^>]*class="[^"]*actions-cell/', $src)) {
            $badWrap[] = basename($p) . ' — سلولِ عملیات کلاسِ actions-cell ندارد';
        }
    }
}
if ($actionPages === 0) {
    $badWrap[] = 'هیچ صفحه‌ای `.table-actions` ندارد — یعنی ردیفِ دکمه‌ها دوباره'
               . ' با استایلِ درون‌خطی نوشته شده و از این قاعده بیرون است';
}

T::bulk(5 + $actionPages, $badWrap, '⛔ برچسبِ دکمه‌ها نمی‌شکند و ستونِ عملیات عرضِ خودش را دارد');

// ---------------------------------------------------------------
// ⛔ قاعده ۳۲ برداشته شد — انتخابگرِ خودمان پس گرفته شد
//
// آن قاعده چهار ستونِ `optionPicker()` را پین می‌کرد. خودِ آن تابع
// به خواستِ مالکِ نصب حذف شد (فرم‌ها روی گوشی شلوغ شده بودند)، پس
// قاعده دیگر چیزی برای سنجیدن نداشت. **قاعده‌ای که موضوعش رفته
// باید برداشته شود، نه خالی نگه داشته شود** — بررسیِ همیشه‌سبز همان
// «سنجشی که روی خرابی سبز می‌شود» است. تاریخچه‌اش در گیت هست.
//
// دو خطِ CSS که جهتِ خودِ کنترلِ بسته را راست‌به‌چپ می‌کند
// (`select, select option`) سرِ جایش ماند، چون هزینه‌اش صفر است.
$badSelDir = [];
if (!preg_match('/select\s*,\s*select\s+option\s*\{[^}]*direction:\s*rtl/s', $css)) {
    $badSelDir[] = '⛔ `select, select option { direction: rtl }` باید بماند: جهتِ'
                 . ' خودِ کنترلِ بسته را همین می‌بندد، و هزینه‌اش صفر است';
}
if (strpos($appJs, 'opt-pop') !== false) {
    $badSelDir[] = '⛔ `optionPicker()` پس گرفته شده و نباید بی‌سروصدا برگردد:'
                 . ' مالکِ نصب دو بار آن را رد کرده (شیتِ کف، و پاپ‌آپِ چسبیده به فیلد)';
}
T::group('⛔ جهتِ <select> از CSS می‌آید');
T::bulk(2, $badSelDir, '⛔ جهتِ <select> از CSS می‌آید و جعبهٔ خودمان برنمی‌گردد');

// ⛔ قاعده ۳۳ — کیبورد که باز است، نوارِ پایین کنار می‌رود
//
// خرابیِ گزارش‌شده: «گاهی منوی پایین می‌رود وسطِ صفحه». علتش CSS نیست،
// کیبوردِ مجازی است: `position: fixed` نسبت به viewport **چیدمان** جای
// می‌گیرد و کیبورد آن را کوچک نمی‌کند (فقط viewport دیداری کوچک می‌شود
// و صفحه اسکرول می‌شود تا فیلد دیده شود). پس کفِ چیدمان جایی وسطِ
// صفحه‌ی دیداری می‌افتد و نوار همان‌جا شناور می‌ماند. در مرورگرِ دسکتاپ
// اصلاً بازتولید نمی‌شود — همان درسِ حاشیه‌ی امن و منوی کناری.
//
// چهار ستونش پین می‌شود، چون افتادنِ هرکدام **بی‌صداست**:
//   ۱. خودِ قاعده‌ی CSS.
//   ۲. `focusin` — مرورگری که `visualViewport` ندارد باید هم کار کند،
//      و روی گوشی کیبورد چند صد میلی‌ثانیه بالا می‌آید: در همان فاصله
//      نوار باید رفته باشد، وگرنه دقیقاً همان باگ دیده می‌شود.
//   ۳. صافیِ نوعِ فیلد — چک‌باکس و دکمه و `type="color"` فوکوس می‌گیرند
//      بدونِ کیبورد؛ بدونِ صافی، تپ روی هر کلیدِ `.switch` نوار را
//      ناپدید می‌کرد.
//   ۴. تصحیح با `visualViewport` — با کیبوردِ سخت‌افزاری (آیپد، دسکتاپ)
//      هیچ چیزی کوچک نمی‌شود، پس نوار باید برگردد.
T::group('قاعده ۳۳ — نوارِ پایین و کیبوردِ مجازی');

$badKb = [];

if (!preg_match('/html\.kb-open\s+\.bottom-nav\s*\{[^}]*display\s*:\s*none/', $css)) {
    $badKb[] = 'قاعده‌ی `html.kb-open .bottom-nav { display: none }` در style.css نیست —'
             . ' نوار با کیبوردِ باز وسطِ صفحه شناور می‌ماند';
}

// ⚠ `$jsSrc` را قبلاً **قاعده ۳۲** تعریف می‌کرد و این قاعده بی‌سروصدا از
//    آن قرض می‌گرفت. با برداشتنِ قاعده ۳۲، متغیر تهی شد و هر پنج بررسیِ
//    اینجا روی فایلِ **سالم** قرمز شدند. قاعده‌ای که به همسایه‌اش بند
//    باشد، روزی که همسایه برود بی‌صدا چیزِ دیگری را می‌سنجد — پس هر
//    قاعده منبعِ خودش را می‌خواند.
$jsSrc    = (string)@file_get_contents(__DIR__ . '/../assets/js/app.js');
$kbAnchor = strpos($jsSrc, '(function keyboardAwareNav() {');
if ($kbAnchor === false) {
    $badKb[] = 'تابعِ `keyboardAwareNav()` در app.js نیست — هیچ‌چیز کلاسِ `kb-open` را نمی‌گذارد';
} else {
    $tail  = substr($jsSrc, $kbAnchor);
    $tail  = preg_replace('#//[^\n]*#', '', $tail);
    $tail  = preg_replace('#/\*.*?\*/#s', '', $tail);
    $depth = 0; $end = null;
    for ($i = 0, $n = strlen($tail); $i < $n; $i++) {
        if ($tail[$i] === '{') { $depth++; }
        elseif ($tail[$i] === '}') { $depth--; if ($depth === 0) { $end = $i; break; } }
    }
    $kbBody = $end === null ? $tail : substr($tail, 0, $end);

    if (!preg_match('/addEventListener\(\s*[\'"]focusin[\'"]/', $kbBody)) {
        $badKb[] = 'نوار باید با `focusin` پنهان شود، نه فقط با `visualViewport`:'
                 . ' کیبورد چند صد میلی‌ثانیه بالا می‌آید و مرورگرِ بدونِ آن API هم هست';
    }
    if (!preg_match('/visualViewport/', $kbBody)) {
        $badKb[] = '`visualViewport` باید تصمیمِ فوکوس را تصحیح کند، وگرنه با کیبوردِ'
                 . ' سخت‌افزاری نوار بی‌دلیل پنهان می‌ماند';
    }
    // ⛔ صافیِ نوع باید از `window.kbNeedsKeyboard` بیاید، نه یک کپیِ
    //    محلی: *رفتارش* در `tests/test_keyboard_nav.php` با node سنجیده
    //    می‌شود و با کپیِ دوم، آن تست چیزی را می‌آزماید که اجرا نمی‌شود.
    //    ⚠ نسخه‌ی اولِ همین بررسی دنبالِ رشته‌های `checkbox`/`color` داخلِ
    //    همین بدنه بود و جهشِ «همیشه true» از زیرش رد شد — فهرست سرِ
    //    جایش می‌ماند و فقط دیگر خوانده نمی‌شد.
    if (strpos($kbBody, 'window.kbNeedsKeyboard') === false) {
        $badKb[] = 'صافیِ نوعِ فیلد باید از `window.kbNeedsKeyboard()` بیاید — تنها'
                 . ' جای این تصمیم، و تنها چیزی که در node آزمودنی است';
    }
}

// و خودِ تابع باید **بیرون از** `DOMContentLoaded` باشد، وگرنه در node
// تعریف نمی‌شود و تستِ رفتاری بی‌صدا از پوشش می‌افتد — همان قاعده‌ی
// `parseBankSms()` و `smsAutoOk()`.
$domReady = strpos($jsSrc, "document.addEventListener('DOMContentLoaded'");
$kbDef    = strpos($jsSrc, 'window.kbNeedsKeyboard = function');
if ($kbDef === false) {
    $badKb[] = '`window.kbNeedsKeyboard` تعریف نشده است';
} elseif ($domReady !== false && $kbDef > $domReady) {
    $badKb[] = '`window.kbNeedsKeyboard` باید بیرون از `DOMContentLoaded` تعریف شود،'
             . ' وگرنه `tests/test_keyboard_nav.php` نمی‌تواند صدایش بزند';
}

T::bulk(5, $badKb, '⛔ نوارِ پایین با کیبوردِ باز کنار می‌رود');

// =====================================================================
// قاعده ۳۴ — تست‌رانر روی خرابیِ زیرساخت نباید سبز شود
// =====================================================================
/**
 * ⛔ چرا: یک بار MariaDB خوابیده بود و `bash tests/run.sh` نوشت
 *    «✅ هر ۴۲ مجموعه تست موفق بود» در حالی که حدود ۲۵ مجموعه اصلاً به
 *    دیتابیس نرسیده بودند. **دو** چیز با هم این را می‌ساختند:
 *    ۱. `Database::getConnection()` در شکست `die()` می‌کرد و `die()` کدِ
 *       خروجِ **صفر** دارد — پس `try/catch`ِ همه‌ی آن تست‌ها کدِ مرده بود.
 *    ۲. `T::skip()` هیچ شمارنده‌ای را بالا نمی‌برد و `report()` صفر
 *       برمی‌گرداند، پس «اجرا نشد» از «موفق شد» جدا نبود.
 *    هر دو نیمه اینجا پین می‌شوند، وگرنه برگشتِ هرکدام دوباره همان
 *    «سنجشی که روی خرابی سبز می‌شود» را می‌سازد.
 */
T::group('قاعده ۳۴ — «اجرا نشد» با «موفق شد» یکی نیست');

$badRun = [];

$assertSrc = (string)file_get_contents(__DIR__ . '/lib/assert.php');
$runSrc    = (string)file_get_contents(__DIR__ . '/run.sh');
$dbSrc     = (string)file_get_contents(__DIR__ . '/../includes/db.php');

// ۱. `T::blocked()` وجود دارد و ردش را نگه می‌دارد
if (!preg_match('/function\s+blocked\s*\(/', $assertSrc)
    || strpos($assertSrc, 'self::$blocked[]') === false) {
    $badRun[] = 'tests/lib/assert.php — `T::blocked()` باید وجود داشته باشد و مورد را ثبت کند';
}

// ۲. و `report()` برایش کدِ ناصفر برمی‌گرداند
if (strpos($assertSrc, 'return self::EXIT_BLOCKED;') === false
    || !preg_match('/EXIT_BLOCKED\s*=\s*([1-9])/', $assertSrc)) {
    $badRun[] = 'tests/lib/assert.php — `report()` برای موردِ بلوک‌شده باید کدِ ناصفر بدهد';
}

// ۳. `run.sh` آن کد را می‌شناسد و با ناصفر تمام می‌شود
if (strpos($runSrc, 'BLOCKED') === false || !preg_match('/^\s*2\)\s*BLOCKED/m', $runSrc)) {
    $badRun[] = 'tests/run.sh — کدِ خروجِ ۲ باید جدا از شکست شمرده شود';
}
if (!preg_match('/BLOCKED\[@\]\}\s*>\s*0\s*\)\)(?s:.{0,900}?)exit 1/', $runSrc)) {
    $badRun[] = 'tests/run.sh — وجودِ مجموعه‌ی اجرانشده باید کدِ خروجِ ناصفر بدهد';
}

// ۴. ⛔ نیمه‌ی دوم: اتصالِ ناموفق روی CLI باید **پرتاب** شود، نه `die()`.
//    بدونِ این، بند ۱ تا ۳ بی‌اثرند چون پروسه پیش از رسیدن به
//    `T::blocked()` با کدِ صفر می‌میرد.
if (!preg_match('/PHP_SAPI\s*===\s*[\'"]cli[\'"](?s:.{0,200}?)throw\s+\$e/', $dbSrc)) {
    $badRun[] = 'includes/db.php — شکستِ اتصال روی خط فرمان باید استثنا پرتاب کند، نه die()';
}

// ۵. و هیچ تستی نباید علتِ **زیرساختی** را ردِ نرم بشمارد
foreach (glob(__DIR__ . '/test_*.php') as $tf) {
    $src = (string)file_get_contents($tf);
    foreach (explode("\n", $src) as $n => $line) {
        if (strpos($line, 'T::skip(') === false) { continue; }
        if (strpos($line, 'اتصال به دیتابیس برقرار نشد') !== false
            || strpos($line, 'config/config.php وجود ندارد') !== false) {
            $badRun[] = basename($tf) . ':' . ($n + 1) . ' — باید `T::blocked()` باشد نه `T::skip()`';
        }
    }
}

T::bulk(5, $badRun, '⛔ خرابیِ زیرساخت، اجرا را سبز نمی‌کند');

// =====================================================================
// قاعده ۳۵ — سیاستِ CSP در دو جا نوشته شده و باید یکی بماند
// =====================================================================
/**
 * ⛔ `deploy/vps-setup.sh` سیاست را برای نصبِ **تازه** می‌نویسد و
 *    `deploy/nginx-csp.sh` همان را روی نصبِ **موجود** می‌گذارد. اگر یکی
 *    عوض شود و دیگری نه، دو نصب دو رفتارِ متفاوت می‌گیرند — و آن تفاوت
 *    فقط وقتی دیده می‌شود که صفحه‌ای روی یکی از آن دو بشکند، یعنی
 *    دیرترین و بدترین لحظه‌ی ممکن.
 */
T::group('قاعده ۳۵ — سیاستِ CSP یکی است');

$badCsp = [];

/**
 * ⚠ دو فایل سیاست را به دو **شکل** نگه می‌دارند و الگوی استخراج هم باید
 *   دو تا باشد: در `vps-setup.sh` سیاست مستقیم داخلِ `add_header` است،
 *   در `nginx-csp.sh` یک متغیرِ `CSP=` است (چون همان‌جا هم چاپ می‌شود هم
 *   نوشته). نسخه‌ی اولِ این قاعده هر دو را با یک الگو می‌خواند و از
 *   دومی مقدارِ `%s`ِ `printf` را برداشت — یعنی **روی فایلِ سالم قرمز
 *   شد**، همان اشتباهی که سرِ کامنت‌های CSS در قاعده ۲۰ هم شد.
 */
$src = (string)@file_get_contents(__DIR__ . '/../deploy/vps-setup.sh');
$cspSetup = preg_match('/add_header Content-Security-Policy\s+"([^"]+)"/', $src, $m)
    ? $m[1] : null;

$src = (string)@file_get_contents(__DIR__ . '/../deploy/nginx-csp.sh');
$cspApply = preg_match('/^CSP="([^"]+)"/m', $src, $m) ? $m[1] : null;

if ($cspSetup === null) { $badCsp[] = 'deploy/vps-setup.sh — هدر CSP ندارد'; }
if ($cspApply === null) { $badCsp[] = 'deploy/nginx-csp.sh — سیاستِ CSP ندارد'; }

if ($cspSetup !== null && $cspApply !== null && $cspSetup !== $cspApply) {
    $badCsp[] = 'سیاستِ دو فایل یکی نیست — نصبِ تازه و نصبِ موجود دو رفتار می‌گیرند';
}

// و سه دستوری که بدونِ آن‌ها این هدر بیشتر تزئین است تا محافظ.
foreach (['object-src', 'base-uri', 'form-action', 'frame-ancestors'] as $need) {
    if ($cspSetup !== null && strpos($cspSetup, $need) === false) {
        $badCsp[] = "سیاست `{$need}` ندارد";
    }
}

// ⛔ و سنجشِ بعد از اعمال اجباری است — «nginx -t سبز شد» چیزی را ثابت
//    نمی‌کند (همان درسِ `^~` در `nginx-api.sh`).
$applySrc = (string)@file_get_contents(__DIR__ . '/../deploy/nginx-csp.sh');

/**
 * ⚠ کامنت‌ها **پیش از** بررسی حذف می‌شوند، وگرنه این بررسی پوچ است:
 *   توضیحِ بالای همان بخش خودش کلمه‌ی `--resolve` را دارد، پس با
 *   برداشتنش از خودِ `curl` هم سبز می‌ماند. **جهشِ M3 دقیقاً همین را
 *   نشان داد و اول زنده ماند** — همان درسی که سرِ `PREF_LAST_WHY` در
 *   قاعده ۱۹ و `catch (Throwable` در قاعده ۱۲ هم تکرار شد.
 */
$applyCode = preg_replace('/^\s*#.*$/m', '', $applySrc) ?? $applySrc;
if (strpos($applyCode, '--resolve') === false) {
    $badCsp[] = 'deploy/nginx-csp.sh — سنجش باید با --resolve باشد، نه Host هدر';
}
if (preg_match('/-H\s+"Host:/i', $applyCode)) {
    $badCsp[] = 'deploy/nginx-csp.sh — سنجش با سرآیندِ Host است؛ روی چندسایتی پاسخِ سایتِ دیگر را می‌گیرد';
}
if (!preg_match('/ok\s*-ne\s*1(?s:.{0,500}?)cp -a "\$BACKUP"/', $applySrc)) {
    $badCsp[] = 'deploy/nginx-csp.sh — اگر سنجش رد شد باید پیکربندی را برگرداند';
}

T::bulk(8, $badCsp, '⛔ CSP در هر دو مسیر یکی و سنجیده است');

// =====================================================================
// قاعده ۳۶ — بک‌تیکِ فرارنداده داخلِ رشته‌ی دابل‌کوتِ bash
// =====================================================================
/**
 * ⛔ خرابیِ واقعی، و روی سرور دیده شد نه در بازبینی. `nginx-csp.sh` یک
 *    پیامِ هشدار داشت که برای زیبایی نامِ یک دستورِ CSP را داخلِ بک‌تیک
 *    گذاشته بود:
 *
 *        warn "… سیاست [بک‌تیک]'unsafe-inline'[بک‌تیک] دارد …"
 *
 *    داخلِ `"…"` بک‌تیک **جانشینیِ فرمان** است، نه یک کاراکترِ معمولی.
 *    پس bash سعی کرد `'unsafe-inline'` را **اجرا** کند و وسطِ خروجیِ
 *    `--apply` نوشت `line 170: unsafe-inline: command not found` — و
 *    همان جمله را با یک **حفره** چاپ کرد، یعنی دقیقاً آن جمله‌ای که
 *    «حدِ صادقانه»ی این محافظ را می‌گوید نصفه به مالکِ نصب رسید.
 *
 * ⚠ اینجا شانس آوردیم که آن رشته فرمانی نبود. همان شکل با یک نامِ
 *   فرمانِ واقعی (یا با `$(…)` که هم‌معناست) **اجرا می‌شد** — داخلِ
 *   اسکریپتی که با `sudo` اجرا می‌شود و پیکربندیِ nginx را می‌نویسد.
 *   همان جنسِ خطر که قاعده ۱۵ برای تگِ پایانِ PHP دارد.
 *
 * ⛔ بررسی با **پیمایشِ کاراکتری** است نه grep: بک‌تیک سه جای کاملاً
 *    بی‌خطر هم هست و هیچ‌کدام نباید قرمز شوند (وگرنه هشدارِ الکی روی
 *    فایلِ سالم، که از نبودِ تست بدتر است):
 *      ۱. `\[بک‌تیک]` ی که فرار داده شده (نامِ جدول در SQLِ `migrate.sh`)
 *      ۲. داخلِ رشته‌ی **تک‌کوتی** (`printf` در `nginx-realip.sh`)
 *      ۳. داخلِ کامنت — که در این مخزن **ده‌ها** بار تکرار شده
 *    پس وضعیتِ کوتیشن، فرار، کامنت و heredoc همه دنبال می‌شوند.
 */
T::group('قاعده ۳۶ — بک‌تیک داخلِ رشته‌ی دابل‌کوتِ bash');

/**
 * وضعیت بینِ خط‌ها حمل می‌شود، چون رشته‌ی دابل‌کوت می‌تواند چندخطی باشد.
 * heredoc کامل رد می‌شود: محتوایش داده است، نه کدِ همین اسکریپت.
 */
$shBacktickHits = static function (string $src): array {
    $hits = [];
    $lines = explode("\n", $src);
    $state = 0;                 // 0 = بیرونِ کوتیشن، 1 = تک‌کوتیشن، 2 = دابل‌کوت
    $heredoc = null;
    foreach ($lines as $i => $line) {
        if ($heredoc !== null) {
            if (trim($line) === $heredoc) { $heredoc = null; }
            continue;
        }
        $len = strlen($line);
        for ($p = 0; $p < $len; $p++) {
            $c = $line[$p];
            if ($state === 1) {                       // تک‌کوتیشن: هیچ چیزی معنا ندارد
                if ($c === "'") { $state = 0; }
                continue;
            }
            if ($state === 2) {                       // دابل‌کوت: اینجا بک‌تیک خطرناک است
                if ($c === '\\') { $p++; continue; }  // فرارداده‌شده، بی‌خطر
                if ($c === '"')  { $state = 0; continue; }
                if ($c === '`')  { $hits[] = $i + 1; }
                continue;
            }
            if ($c === '\\') { $p++; continue; }
            if ($c === "'")  { $state = 1; continue; }
            if ($c === '"')  { $state = 2; continue; }
            if ($c === '#' && ($p === 0 || $line[$p - 1] === ' ' || $line[$p - 1] === "\t")) {
                break;                                 // بقیه‌ی خط کامنت است
            }
            if ($c === '<' && isset($line[$p + 1]) && $line[$p + 1] === '<'
                && preg_match('/^<<-?\s*[\'"]?([A-Za-z_][A-Za-z0-9_]*)[\'"]?/', substr($line, $p), $m)) {
                $heredoc = $m[1];
                break;
            }
        }
    }
    return $hits;
};

$badTick = [];
// ⚠ `hesabland` پسوند ندارد (یک فرمان است، نه یک اسکریپتِ کمکی)، پس
//    هیچ‌کدام از الگوهای `*.sh` آن را برنمی‌دارند و بی‌صدا بیرونِ پوشش
//    می‌ماند — همان درسِ globِ سخت‌کدِ قاعده ۱۹. صریح اضافه می‌شود، و
//    نبودنش هم خطاست (پایین‌تر شمرده می‌شود).
$shFiles = array_merge(
    glob(__DIR__ . '/../deploy/*.sh') ?: [],
    glob(__DIR__ . '/../*.sh') ?: [],
    glob(__DIR__ . '/*.sh') ?: [],
    array_filter([__DIR__ . '/../hesabland'], 'is_file')
);
if (count($shFiles) < 5) {
    $badTick[] = 'هیچ اسکریپتِ پوسته‌ای پیدا نشد — الگوی جست‌وجو خراب است';
}
if (!is_file(__DIR__ . '/../hesabland')) {
    $badTick[] = 'hesabland پیدا نشد — اگر نامش عوض شده، همین‌جا هم به‌روز شود'
               . ' وگرنه بی‌صدا بیرونِ پوششِ این قاعده می‌ماند';
}
foreach ($shFiles as $sh) {
    foreach ($shBacktickHits((string)@file_get_contents($sh)) as $ln) {
        $badTick[] = basename($sh) . ':' . $ln
            . ' — بک‌تیکِ فرارنداده داخلِ "…"؛ bash آن را اجرا می‌کند. «…» بنویسید یا \\` کنید';
    }
}

T::bulk(count($shFiles), $badTick, '⛔ هیچ بک‌تیکِ فرارنداده‌ای داخلِ رشته‌ی دابل‌کوت نیست');

// ---------------------------------------------------------------------
// قاعده ۳۷ — تاگلِ نوع: دکمه‌ی فعال با مقدارِ ارسالی یکی باشد
//
// ⛔ چرا **اینجا** و نه در تستِ رفتاری: `setupTypeToggle()` هنگام
//    بارگذاری `activate(initialBtn)` را صدا می‌زند و مقدارِ فیلدِ پنهان
//    را از روی دکمه‌ی فعال **بازنویسی** می‌کند. پس در مرورگر این
//    ناهماهنگی خودبه‌خود ترمیم می‌شود و هیچ تستِ رفتاری‌ای نمی‌بیندش —
//    جهشش در `test_tap_budget` **زنده ماند** و همین نشان داد قاعده‌ی
//    شکل لازم است.
//    ولی بی‌ضرر نیست: اگر `app.js` نرسد (همان حالتی که `js-loading`
//    برایش ساخته شد — یک درخواستِ گیرکرده روی دیتای موبایل)، فرم با
//    مقدارِ خامِ HTML ارسال می‌شود در حالی که کاربر دکمه‌ی دیگری را
//    فعال می‌بیند: تراکنش **برعکس** ثبت می‌شود، بی‌هیچ خطایی.
//
// ⛔ و پیش‌فرضِ خودِ شیتِ ثبت باید «هزینه» بماند: روی همین دیتابیس
//    ۲۹٬۲۲۴ هزینه در برابر ۱۰٬۷۷۹ درآمد شمرده شد.
// ---------------------------------------------------------------------
T::group('قاعده ۳۷ — تاگلِ نوع و مقدارِ ارسالی');

$badToggle = [];
$toggleFiles = glob(__DIR__ . '/../includes/*.php') ?: [];
$toggleSeen = 0;

foreach ($toggleFiles as $f) {
    $src = (string)@file_get_contents($f);
    // ⚠ شرطِ ورود «`data-type="expense"` دارد» است، نه `class="type-toggle"`:
    //   `wallet_card_modals.php` هم آن کلاس را برای چیزِ دیگری دارد و
    //   نسخه‌ی اولِ این قاعده روی فایلِ **سالم** قرمز شد — همان
    //   «هشدارِ الکی از نبودِ تست بدتر است».
    if (strpos($src, 'data-type="expense"') === false) { continue; }
    $toggleSeen++;
    $base = basename($f);

    if (!preg_match('/class="type-btn[^"]*\bactive\b[^"]*"\s+data-type="(income|expense)"/', $src, $m)
        && !preg_match('/data-type="(income|expense)"[^>]*class="type-btn[^"]*\bactive\b/', $src, $m)) {
        $badToggle[] = "{$base} — دکمه‌ی `active` پیدا نشد";
        continue;
    }
    $activeType = $m[1];

    if (!preg_match('/<input[^>]*name="type"[^>]*value="(income|expense)"/', $src, $v)) {
        $badToggle[] = "{$base} — فیلدِ پنهانِ `name=\"type\"` با مقدار پیدا نشد";
        continue;
    }
    if ($v[1] !== $activeType) {
        $badToggle[] = "{$base} — دکمه‌ی فعال «{$activeType}» است ولی مقدارِ ارسالی «{$v[1]}»";
    }
    if ($base === 'add_tx_sheet.php' && $activeType !== 'expense') {
        $badToggle[] = "{$base} — پیش‌فرضِ شیتِ ثبت باید «هزینه» باشد، نه «{$activeType}»";
    }
}

if ($toggleSeen < 2) {
    $badToggle[] = 'کمتر از دو تاگلِ نوع پیدا شد — الگوی جست‌وجو خراب است';
}
T::bulk($toggleSeen, $badToggle, '⛔ دکمه‌ی فعالِ تاگل با مقدارِ ارسالی یکی است');

// ---------------------------------------------------------------------
// قاعده ۳۸ — شبکه‌ی دسته‌بندی و عنوانِ اختیاری
//
// ⛔ چهار تصمیم که خرابیِ هر کدام **بی‌صداست**:
//    ۱. نامِ دسته با `textContent` نوشته شود، نه `innerHTML` — نام را
//       خودِ کاربر می‌نویسد.
//    ۲. سقفِ چیپ‌ها از `CATEGORY_GRID_MAX`ِ سرور بیاید، نه یک عددِ
//       سخت‌کد در جاوااسکریپت (وگرنه دو مرجع، و یکی عقب می‌ماند).
//    ۳. چیپ مقدار را روی خودِ `<select>` بنویسد و `change` بدهد — تنها
//       چیزی که `optionPicker()` و `smsAutoOk()` و اعتبارسنجیِ فرم را
//       سرِ پا نگه می‌دارد.
//    ۴. عنوانِ خالی فقط از `fallbackTxTitle()` پر شود، و آن تابع
//       **بعد از** `txResolveCategory()` صدا زده شود — وگرنه نامِ
//       دسته‌ی کاربرِ دیگری داخلِ عنوانِ این کاربر می‌نشیند.
// ---------------------------------------------------------------------
T::group('قاعده ۳۸ — شبکه‌ی دسته‌بندی و عنوانِ اختیاری');

$badGrid = [];
$appJs = (string)@file_get_contents(__DIR__ . '/../assets/js/app.js');
// کامنت‌ها پیش از بررسی حذف می‌شوند — همان درسِ قاعده ۳۵: توضیحِ کنارِ
// کد نباید بررسی را سبز نگه دارد.
$appNoC = preg_replace('!/\*.*?\*/!s', '', $appJs);
$appNoC = preg_replace('!^\s*//.*$!m', '', (string)$appNoC);

if (!preg_match('/function\s+renderCategoryGrid\s*\(/', (string)$appNoC)) {
    $badGrid[] = 'app.js — تابع renderCategoryGrid پیدا نشد';
} else {
    $start = strpos((string)$appNoC, 'function renderCategoryGrid');
    $end   = strpos((string)$appNoC, 'function setupTypeToggle');
    $body  = ($end !== false && $end > $start)
        ? substr((string)$appNoC, $start, $end - $start)
        : substr((string)$appNoC, $start, 3000);

    if (strpos($body, '.textContent = cat.name') === false) {
        $badGrid[] = 'app.js — نامِ دسته باید با textContent نوشته شود (نامش را کاربر می‌نویسد)';
    }
    if (preg_match('/innerHTML\s*=\s*cat\.name/', $body)) {
        $badGrid[] = 'app.js — نامِ دسته با innerHTML نوشته شده است';
    }
    if (strpos($body, 'window.CATEGORY_GRID_MAX') === false) {
        $badGrid[] = 'app.js — سقفِ چیپ‌ها باید از window.CATEGORY_GRID_MAX بیاید، نه عددِ سخت‌کد';
    }
    if (strpos($body, 'selectEl.value') === false) {
        $badGrid[] = 'app.js — چیپ باید مقدار را روی خودِ <select> بنویسد';
    }
    if (strpos($body, "new Event('change'") === false) {
        $badGrid[] = 'app.js — چیپ باید رویدادِ change بدهد، وگرنه هیچ مصرف‌کننده‌ای خبردار نمی‌شود';
    }
}

// ⚠ کامنت‌ها **پیش از** بررسی حذف می‌شوند، وگرنه همین توضیحاتی که
//   کنارِ هر تصمیم نوشته شده‌اند خودشان بررسی را سبز نگه می‌دارند.
//   جهشِ «JSON_HEX_TAG را بردار» اول دقیقاً به همین دلیل **زنده ماند**:
//   نامش در کامنتِ بالای همان خط بود. همان دامِ قاعده ۳۵ و ۱۹.
$sheetSrc = $stripComments(__DIR__ . '/../includes/add_tx_sheet.php');
if (strpos($sheetSrc, 'CATEGORY_GRID_MAX') === false) {
    $badGrid[] = 'add_tx_sheet.php — سقف باید از ثابتِ سرور به مرورگر داده شود';
}
if (strpos($sheetSrc, 'JSON_HEX_TAG') === false) {
    $badGrid[] = 'add_tx_sheet.php — JSON_HEX_TAG لازم است: نامِ دسته داخلِ <script> می‌نشیند';
}
if (preg_match('/id="title"[^>]*\srequired/', $sheetSrc)) {
    $badGrid[] = 'add_tx_sheet.php — عنوان دوباره required شد؛ جایش fallbackTxTitle است';
}

// عنوانِ خالی: تنها مسیرِ پر شدنش، و ترتیبش نسبت به سنجشِ دسته
$txSrc = (string)@file_get_contents(__DIR__ . '/../includes/transactions.php');
foreach (['txCreate', 'txUpdate'] as $fn) {
    $s = strpos($txSrc, "function {$fn}(");
    if ($s === false) { $badGrid[] = "transactions.php — {$fn} پیدا نشد"; continue; }
    // تا تعریفِ تابعِ بعدی بریده می‌شود — همان درسِ قاعده ۲۹.
    $e = strpos($txSrc, "\nfunction ", $s + 10);
    $chunk = $e === false ? substr($txSrc, $s) : substr($txSrc, $s, $e - $s);

    $posCat   = strpos($chunk, 'txResolveCategory(');
    $posTitle = strpos($chunk, 'fallbackTxTitle(');
    if ($posTitle === false) {
        $badGrid[] = "transactions.php — {$fn} عنوانِ خالی را پر نمی‌کند";
    } elseif ($posCat === false || $posTitle < $posCat) {
        $badGrid[] = "transactions.php — {$fn} باید fallbackTxTitle را **بعد از** txResolveCategory صدا بزند";
    }
}
if (preg_match('/\$errors\[\]\s*=\s*.عنوان الزامی/u', $txSrc)) {
    $badGrid[] = 'transactions.php — «عنوان الزامی است» برگشت؛ عنوان دیگر اجباری نیست';
}

// ⛔ دو تصمیمِ تازهٔ همین ردیف، و افتادنِ هر دو **بی‌صداست**:
//   ۱. یک خطِ افقی، نه شبکهٔ چندردیفی (خواستهٔ صریحِ مالکِ نصب).
//   ۲. کدام دسته‌ها بیایند را **سرور** می‌گوید (`categoriesForGrid()`):
//      با `slice()`ِ محلی در مرورگر، پینی که کاربر در «فهرست‌های من»
//      زده نادیده می‌ماند و او فکر می‌کند دکمه کار نمی‌کند.
if (!preg_match('/\\.cat-grid\\s*\\{[^}]*flex-wrap:\\s*nowrap/s', $css)) {
    $badGrid[] = 'style.css — ردیفِ چیپ باید `flex-wrap: nowrap` باشد: با wrap هشت چیپ'
               . ' روی عرضِ گوشی سه ردیف می‌شد و همان شلوغی‌ای است که گزارش شد';
}
if (strpos($appJs, 'CATEGORY_GRID[') === false) {
    $badGrid[] = 'app.js — ردیفِ چیپ باید فهرستش را از `window.CATEGORY_GRID` بخواند،'
               . ' وگرنه انتخابِ کاربر در «فهرست‌های من» بی‌صدا نادیده می‌ماند';
}
if (strpos($sheetSrc, 'CATEGORY_GRID') === false
    || strpos($sheetSrc, 'categoriesForGrid(') === false) {
    $badGrid[] = 'add_tx_sheet.php — فهرستِ چیپ باید از `categoriesForGrid()` بیاید و به'
               . ' مرورگر داده شود؛ آن تنها جای این تصمیم است';
}

T::bulk(12, $badGrid, '⛔ شبکه‌ی دسته‌بندی و عنوانِ اختیاری سرِ جایشان‌اند');

// ---------------------------------------------------------------------
// قاعده ۳۹ — «آخرین استفاده» و آستانه‌ی «فعال»
//
// ⛔ **خرابیِ واقعی که این قاعده برایش نوشته شد:** `userActivity()` فقط
//    جدولِ `transactions` را می‌خواند، پس کاربری که چک و طلب ثبت کرده
//    بود روی صفحه‌ی مدیر «هرگز شروع نکرده» خوانده می‌شد. رفتارش را
//    `tests/test_admin_stats.php` می‌سنجد؛ اینجا **شکل** پین می‌شود تا
//    برنگردد:
//
//    ۱. فهرستِ جدول‌ها فقط یک جا تعریف شود (`ACTIVITY_TABLES`)، وگرنه
//       نسخه‌ی دومی می‌شود که دیر یا زود از این عقب می‌افتد.
//    ۲. فاصله‌ی روز را **دیتابیس** حساب کند (`DATEDIFF`)، نه PHP: با
//       `new DateTimeImmutable('today')` امروزِ PHP و `created_at`ِ
//       دیتابیس در پنجره‌ی بامدادی دو روزِ متفاوت می‌گفتند — همان درسِ
//       بخشِ «منطقه‌ی زمانی» و لینکِ بازیابیِ رمز.
//    ۳. آستانه‌ی «فعال» فقط `ACTIVE_DAYS` باشد؛ پیش از این عددِ ۱۴ در
//       سه جا سخت‌کد بود و عوض کردنِ یکی، دو تای دیگر را بی‌صدا
//       دروغ‌گو می‌کرد.
// ---------------------------------------------------------------------
T::group('قاعده ۳۹ — «آخرین استفاده» و آستانه‌ی «فعال»');

$badStat = [];
$aiPath  = __DIR__ . '/../includes/admin_insights.php';
// کامنت‌ها **پیش از** بررسی حذف می‌شوند: همین توضیحاتِ بالا نامِ
// `transactions` و عددِ ۱۴ را دارند و با سورسِ خام، بررسی روی فایلِ
// سالم هم قرمز (یا بدتر، بی‌جهت سبز) می‌شد — همان دامِ قاعده ۳۵ و ۳۸.
$aiSrc = $stripComments($aiPath);

// ⚠ هر دو شکلِ تعریف پذیرفته می‌شود. نسخه‌ی اول فقط `const` را قبول
//   می‌کرد و آن یک **هشدارِ الکی** بود: `define()` دقیقاً همان کار را
//   می‌کند و فایل خراب نیست. قاعده باید چیزی را ببندد که خراب است، نه
//   چیزی را که فقط شکلش فرق دارد.
if (strpos($aiSrc, 'const ACTIVE_DAYS') === false
    && strpos($aiSrc, "define('ACTIVE_DAYS'") === false) {
    $badStat[] = 'admin_insights.php — ثابتِ ACTIVE_DAYS تعریف نشده';
}
if (strpos($aiSrc, 'const ACTIVITY_TABLES') === false) {
    $badStat[] = 'admin_insights.php — فهرستِ ACTIVITY_TABLES تعریف نشده';
}
if (strpos($aiSrc, 'const NON_ACTIVITY_TABLES') === false) {
    $badStat[] = 'admin_insights.php — فهرستِ NON_ACTIVITY_TABLES تعریف نشده';
}

// بدنه‌ی `userActivity()` — تا **تعریفِ تابعِ بعدی** بریده می‌شود، نه تا
// اولین `}` (همان درسِ قاعده ۲۹).
$sU = strpos($aiSrc, 'function userActivity(');
if ($sU === false) {
    $badStat[] = 'admin_insights.php — userActivity پیدا نشد';
} else {
    $eU  = strpos($aiSrc, "\nfunction ", $sU + 10);
    $bod = $eU === false ? substr($aiSrc, $sU) : substr($aiSrc, $sU, $eU - $sU);

    if (strpos($bod, 'DATEDIFF(') === false) {
        $badStat[] = 'userActivity() — فاصله‌ی روز باید با DATEDIFF در دیتابیس حساب شود';
    }
    if (strpos($bod, 'DateTimeImmutable') !== false || strpos($bod, '->diff(') !== false) {
        $badStat[] = 'userActivity() — حسابِ تاریخ به PHP برگشت؛ روزِ PHP و روزِ دیتابیس یکی نیستند';
    }
    if (strpos($bod, 'ACTIVE_DAYS') === false) {
        $badStat[] = 'userActivity() — آستانه باید از ACTIVE_DAYS بیاید';
    }
    if (preg_match('/<=\s*14\b/', $bod)) {
        $badStat[] = 'userActivity() — عددِ ۱۴ دوباره سخت‌کد شد';
    }
    // ⛔ نامِ هیچ جدولی مستقیم داخلِ این تابع نباشد: فهرست فقط از
    //    `activityAggregateSql()` می‌آید که خودش `ACTIVITY_TABLES` را
    //    می‌خواند.
    foreach (['transactions', 'cheques', 'debts'] as $t) {
        if (strpos($bod, "`{$t}`") !== false || strpos($bod, "FROM {$t}") !== false) {
            $badStat[] = "userActivity() — نامِ جدولِ {$t} مستقیم نوشته شده؛ فهرست فقط ACTIVITY_TABLES است";
        }
    }
}

// فهرست فقط یک جا تعریف شود
foreach (glob(__DIR__ . '/../includes/*.php') as $p) {
    if (realpath($p) === realpath($aiPath)) { continue; }
    if (strpos((string)@file_get_contents($p), 'ACTIVITY_TABLES = [') !== false) {
        $badStat[] = basename($p) . ' — نسخه‌ی دومِ ACTIVITY_TABLES';
    }
}

// صفحه آستانه را از ثابت بخواند، نه عددِ خودش
$pgSrc = $stripComments(__DIR__ . '/../admin/insights.php');
if (strpos($pgSrc, 'ACTIVE_DAYS') === false) {
    $badStat[] = 'admin/insights.php — آستانه باید از ACTIVE_DAYS بیاید';
}
if (strpos($pgSrc, '۱۴ روز') !== false || preg_match('/بیش از\s*14\s*روز/u', $pgSrc)) {
    $badStat[] = 'admin/insights.php — عددِ آستانه سخت‌کد شد';
}

// ⛔ «تراکنشِ امروز» — خرابیِ گزارش‌شده برای بارِ دوم: عددِ کل روی صفحه
//    بود و مالکِ نصب آن را «امروز» خواند. سه چیز پین می‌شود:
//
//    ۱. مرزِ روز را **دیتابیس** بگذارد (`CURDATE()` داخلِ
//       `activityAggregateSql()`)، نه PHP — همان درسِ `DATEDIFF`.
//    ۲. صفحه هر دو عدد را **صریح** برچسب بزند («کل تراکنش» و «امروز»)؛
//       یک عددِ بی‌برچسب دقیقاً همان چیزی است که دو بار غلط خوانده شد.
//    ۳. «رکوردِ دیگر» برنگردد: آن عدد با `tx` هم‌پوشانی داشت و همان
//       سوءتفاهمِ اول بود.
$sAgg = strpos($aiSrc, 'function activityAggregateSql(');
if ($sAgg === false) {
    $badStat[] = 'admin_insights.php — activityAggregateSql پیدا نشد';
} else {
    $eAgg = strpos($aiSrc, "\nfunction ", $sAgg + 10);
    $bAgg = $eAgg === false ? substr($aiSrc, $sAgg) : substr($aiSrc, $sAgg, $eAgg - $sAgg);
    if (strpos($bAgg, 'today_tx') === false) {
        $badStat[] = 'activityAggregateSql() — تراکنشِ امروز باید در همین زیرکوئری شمرده شود، نه با کوئریِ دوم';
    }
    if (strpos($bAgg, 'CURDATE()') === false) {
        $badStat[] = 'activityAggregateSql() — مرزِ «امروز» باید با CURDATE() در دیتابیس باشد، نه در PHP';
    }
}
if (strpos($pgSrc, 'today_tx') === false) {
    $badStat[] = 'admin/insights.php — «تراکنشِ امروز» روی صفحه نیست';
}
if (strpos($pgSrc, 'کل تراکنش') === false) {
    $badStat[] = 'admin/insights.php — عددِ کل باید صریح «کل تراکنش» برچسب بخورد';
}
if (strpos($pgSrc, 'رکوردِ دیگر') !== false) {
    $badStat[] = 'admin/insights.php — «رکوردِ دیگر» برگشت؛ آن عدد با tx هم‌پوشانی داشت';
}

T::bulk(18, $badStat, '⛔ «آخرین استفاده»، آستانه‌ی «فعال»، و «تراکنشِ امروز»');

// ---------------------------------------------------------------------
// قاعده ۴۰ — «شخصی‌سازیِ» دسته‌بندی، و نگهبانِ حذف
//
// ⛔ **خرابیِ واقعیِ بی‌صدا که این قاعده برایش نوشته شد:** نگهبانِ حذف
//    در `admin/categories.php` فقط `transactions` را می‌شمرد، در حالی
//    که `budgets.category_id` کلیدِ خارجی با `ON DELETE CASCADE` دارد.
//    یعنی حذفِ دسته‌ای که هیچ تراکنشی نداشت ولی رویش بودجه بسته شده
//    بود، **بودجه‌ی کاربران را هم با خودش می‌برد** — نه خطایی، نه
//    هشداری، و مدیر پیامِ «دسته‌بندی حذف شد» می‌گرفت.
//
//    رفتارش را `tests/test_category_privatize.php` می‌سنجد؛ اینجا
//    **شکل** پین می‌شود تا برنگردد:
//
//    ۱. نگهبانِ حذف از `categoryUsage()` رد شود، نه یک شمارشِ محلی روی
//       `transactions` — وگرنه جدولِ ارجاع‌دهنده‌ی فردا هم جا می‌ماند.
//    ۲. فهرستِ جدول‌های ارجاع‌دهنده از **خودِ دیتابیس** کشف شود
//       (`schemaMap()`)، نه یک آرایه‌ی دستی: اینجا فهرست باید **کامل**
//       باشد نه گزینشی — همان قاعده‌ی `userDataTables()`، و برعکسِ
//       `ACTIVITY_TABLES` که عمداً بسته است.
//    ۳. جابه‌جا کردنِ `category_id` از یک دسته به دسته‌ی دیگر فقط در
//       `privatizeDefaultCategory()` باشد. نسخه‌ی دومِ آن یعنی مسیری که
//       شرطِ `user_id` را فراموش می‌کند و ردیفِ یک کاربر را به دسته‌ی
//       کاربرِ دیگری می‌چسباند — همان نشتی‌ای که `categoryScopeSql()`
//       برای نبودنش نوشته شد.
// ---------------------------------------------------------------------
T::group('قاعده ۴۰ — شخصی‌سازیِ دسته‌بندی و نگهبانِ حذف');

$badPriv = [];
// ⚠ کامنت‌ها **پیش از** بررسی حذف می‌شوند: همین توضیحاتِ بالا و
//   توضیحاتِ خودِ آن فایل‌ها نامِ `transactions` و `categoryUsage` را
//   دارند، پس با سورسِ خام بررسی روی فایلِ **سالم** هم جواب می‌داد —
//   همان دامِ قاعده ۳۵ و ۳۸.
$catAdmin = $stripComments(__DIR__ . '/../admin/categories.php');
$fnSrc    = $stripComments(__DIR__ . '/../includes/functions.php');

// ۱ — نگهبانِ حذف از categoryUsage() رد شود
if (strpos($catAdmin, 'categoryUsage(') === false) {
    $badPriv[] = 'admin/categories.php — نگهبانِ حذف باید از categoryUsage() رد شود';
}
if (preg_match('/FROM\s+transactions\s+WHERE\s+category_id/i', $catAdmin)) {
    $badPriv[] = 'admin/categories.php — شمارشِ محلی روی transactions برگشته؛ جدول‌های دیگر جا می‌مانند';
}

// ۲ — فهرستِ جدول‌های ارجاع‌دهنده کشف می‌شود، نه دستی
if (preg_match('/function\s+categoryRefTables\s*\([^)]*\)\s*:\s*array\s*\{(.*?)\n\}/s', $fnSrc, $m)) {
    $body = $m[1];
    if (strpos($body, 'schemaMap()') === false) {
        $badPriv[] = 'categoryRefTables() — باید از schemaMap() کشف کند، نه فهرستِ دستی';
    }
    foreach (['transactions', 'budgets', 'recurring_transactions', 'category_pins'] as $t) {
        if (strpos($body, "'{$t}'") !== false) {
            $badPriv[] = "categoryRefTables() — نامِ {$t} دستی نوشته شده؛ فهرست باید کامل و کشف‌شده باشد";
        }
    }
} else {
    $badPriv[] = 'categoryRefTables() پیدا نشد';
}

// ۳ — جابه‌جاییِ category_id فقط در privatizeDefaultCategory()
$movePat = '/SET\s+category_id\s*=\s*:\w+\s*\n?\s*WHERE\s+category_id/i';
foreach (['api', 'includes', 'admin', 'store', '.'] as $dir) {
    foreach (glob(__DIR__ . '/../' . $dir . '/*.php') as $p) {
        $src = $stripComments($p);
        if (!preg_match($movePat, $src)) { continue; }
        if (basename($p) === 'functions.php') { continue; }
        $badPriv[] = basename($p) . ' — جابه‌جاییِ category_id فقط در privatizeDefaultCategory() مجاز است';
    }
}
// و همان‌جا هم شرطِ user_id روی UPDATE باشد
if (preg_match('/function\s+privatizeDefaultCategory\s*\(.*?\n\}/s', $fnSrc, $m)) {
    if (!preg_match('/SET\s+category_id\s*=\s*:new\s*\n?\s*WHERE\s+category_id\s*=\s*:old\s+AND\s+user_id\s*=\s*:u/i', $m[0])) {
        $badPriv[] = 'privatizeDefaultCategory() — شرطِ user_id روی UPDATE برداشته شده (نشتی بینِ کاربران)';
    }
    if (strpos($m[0], 'user_id IS NULL') === false) {
        $badPriv[] = 'privatizeDefaultCategory() — نگهبانِ «فقط دسته‌ی پیش‌فرض» برداشته شده';
    }
    // ⚠ نسخه‌ی اول فقط دنبالِ `rollBack()` می‌گشت و **پوچ بود**: بلوکِ
    //   `catch` خودش یکی دارد، پس با برداشتنِ کاملِ سد هم سبز می‌ماند و
    //   جهشش زنده ماند. حالا خودِ شرطِ سد سنجیده می‌شود.
    if (!preg_match('/if\s*\(\s*\$left\s*!==\s*0\s*\|\|\s*\$moved\s*!==\s*\$usage\[.rows.\]\s*\)/', $m[0])) {
        $badPriv[] = 'privatizeDefaultCategory() — سدِ شمارشِ پیش از commit برداشته شده';
    }
} else {
    $badPriv[] = 'privatizeDefaultCategory() پیدا نشد';
}

T::bulk(9, $badPriv, '⛔ شخصی‌سازی و حذفِ دسته‌بندی یک مرجع دارند');

// ---------------------------------------------------------------
// قاعده ۴۱ — ادغامِ دسته‌بندی، و فهرستِ صفحه‌بندی‌شده
// ---------------------------------------------------------------
//
// دو تصمیم که هر دو خرابیِ بی‌صدا دارند:
//
// ۱. **ادغام یک عملیاتِ پشتیبانی است، نه یک گزینه در اپ.** گزینه‌اش
//    ساخته شد و به خواستِ صریحِ مالکِ نصب پس گرفته شد («دیگه گزینه
//    ادغام نمی‌خوام») — همان اتفاقی که دو بار سرِ `optionPicker()`
//    افتاد. ولی خودِ `mergeCategories()` ماند، چون **تنها جایی است
//    که برخوردِ کلیدِ یکتا (`budgets`, `category_pins`) و سدِ شمارشِ
//    پیش از `commit` در آن نوشته شده**؛ یک `UPDATE … SET category_id`
//    دستی روی نصبی که کسی هر دو دسته را پین کرده با «Duplicate entry»
//    می‌میرد و `ON DELETE CASCADE` بعدش بودجه‌ی کاربر را می‌برد.
//    پس این قاعده دو طرفه است: تابع و سه نگهبانش بمانند، و **در پشتی
//    تنها مصرف‌کننده‌اش باشد** (`deploy/user-admin.php --merge-categories`،
//    مثل `--unlock` و `--sms-check`). برگشتِ UI ممنوع است — وگرنه
//    قاعده‌ای که موضوعش رفته همیشه‌سبز می‌ماند و بی‌صدا برمی‌گردد.
//
// ۲. **صفحه‌بندی نباید `LIMIT` بشود و نباید سقفِ بی‌صدا بگذارد.** با
//    `LIMIT`، هر فهرست دو کوئریِ تازه به صفحه‌ای اضافه می‌کند که
//    بودجه‌اش ثبت است؛ و `array_slice($x, 0, N)` در صفحه یعنی همان
//    «بیست‌تای اول را نشان بده و نگو بقیه کجا رفتند».
T::group('قاعده ۴۱ — ادغامِ دسته‌بندی و فهرستِ صفحه‌بندی‌شده');

$badMerge = [];

// ⚠ کامنت‌ها **پیش از** بررسی حذف می‌شوند — همان دامی که قاعده‌های
//   ۱۹ و ۳۵ و ۳۸ هم در آن افتادند: توضیحِ همین بخش نامِ توابع و
//   رشته‌ها را در خودش دارد و بررسیِ متنی روی فایلِ سالم هم سبز
//   می‌ماند (یا بدتر، جهش را زنده نگه می‌دارد).
$fnSrc  = $stripComments(__DIR__ . '/../includes/functions.php');
$catAdm = $stripComments(__DIR__ . '/../admin/categories.php');
$refApi = $stripComments(__DIR__ . '/../api/manage_reference.php');
$refPg  = $stripComments(__DIR__ . '/../references.php');
$cliSrc = $stripComments(__DIR__ . '/../deploy/user-admin.php');
$pagSrc = $stripComments(__DIR__ . '/../includes/paged_list.php');
$insSrc = $stripComments(__DIR__ . '/../admin/insights.php');

// ⚠ `token_get_all()` روی فایلِ JS/CSS همه‌چیز را `T_INLINE_HTML` می‌بیند،
//   پس کامنت‌هایشان دست‌نخورده می‌مانند و بررسیِ «این نام برنگشته» روی
//   یک توضیح هم قرمز می‌شد — همان هشدارِ الکی که از نبودِ تست بدتر است.
$stripCLike = static function (string $file): string {
    $s = (string)file_get_contents($file);
    $s = preg_replace('#/\*.*?\*/#s', '', $s);
    return (string)preg_replace('#(^|\s)//[^\n]*#', '$1', (string)$s);
};
$jsSrcM  = $stripCLike(__DIR__ . '/../assets/js/app.js');
$cssSrcM = $stripCLike(__DIR__ . '/../assets/css/style.css');

if (!preg_match('/function\s+mergeCategories\s*\(.*?\n\}/s', $fnSrc, $mm)) {
    $badMerge[] = 'mergeCategories() پیدا نشد';
} else {
    $body = $mm[0];
    if (!preg_match('/\$from\[.type.\]\s*!==\s*\$into\[.type.\]/', $body)) {
        $badMerge[] = 'mergeCategories() — نگهبانِ هم‌نوع بودن برداشته شده';
    }
    if (strpos($body, 'categoryUniqueKeys()') === false) {
        $badMerge[] = 'mergeCategories() — برخوردِ کلیدِ یکتا سنجیده نمی‌شود';
    }
    // سدِ پیش از commit — خودِ شرط سنجیده می‌شود نه رشته‌ی rollBack()،
    // چون بلوکِ catch خودش یکی دارد و بررسی پوچ می‌شد (درسِ قاعده ۴۰).
    if (!preg_match('/if\s*\(\s*\$left\s*!==\s*0\s*\|\|\s*\$moved\s*\+\s*\$dropped\s*!==\s*\$before\s*\)/', $body)) {
        $badMerge[] = 'mergeCategories() — سدِ شمارشِ پیش از commit برداشته شده';
    }
}

// ⛔ کلیدهای یکتا از دیتابیس کشف می‌شوند، نه یک فهرستِ دستی — همان
//    قاعده‌ی `categoryRefTables()`. فهرستِ دستی با جدولِ فردا عقب
//    می‌افتد و برخورد دوباره بی‌صدا می‌شود.
if (strpos($fnSrc, 'information_schema.STATISTICS') === false) {
    $badMerge[] = 'categoryUniqueKeys() — کلیدها از دیتابیس کشف نمی‌شوند';
}

// ⛔ گزینه‌ی ادغام به اپ برنگردد. چهار فایلِ UI و `style.css` سنجیده
//    می‌شوند، چون خرابی‌اش همان «قابلیتی که بی‌صدا برمی‌گردد» است.
$uiFiles = [
    'admin/categories.php'     => $catAdm,
    'references.php'           => $refPg,
    'api/manage_reference.php' => $refApi,
    'assets/js/app.js'         => $jsSrcM,
    'assets/css/style.css'     => $cssSrcM,
];
foreach ($uiFiles as $f => $src) {
    if (strpos($src, 'mergeCategories(') !== false) {
        $badMerge[] = $f . ' — ادغام نباید از اپ در دسترس باشد (فقط user-admin.php)';
    }
    foreach (['catMerge', 'MERGE_CATS', 'js-cat-merge', 'ref-chip-merge'] as $marker) {
        if (strpos($src, $marker) !== false) {
            $badMerge[] = $f . ' — نشانه‌ی UI ادغام برگشته: ' . $marker;
        }
    }
    // ⛔ و همان قاعده‌ی قبلی سرِ جایش: جابه‌جاییِ `category_id` فقط در
    //    `mergeCategories()` و `privatizeDefaultCategory()` است.
    if (preg_match('/UPDATE\s+`?\w+`?\s+SET\s+category_id/i', $src)) {
        $badMerge[] = $f . ' — جابه‌جاییِ category_id باید فقط در mergeCategories() باشد';
    }
}
// ⛔ اندپوینتِ فهرست‌های کمکی هم نباید `action=merge` بشناسد: گیتِ
//    صفحه بدونِ گیتِ اندپوینت فقط تزئین است (همان درسِ قاعده ۲۶).
if (preg_match("/\\\$action\s*===\s*'merge'/", $refApi)) {
    $badMerge[] = 'api/manage_reference.php — action=merge باید برداشته شده باشد';
}

// ⛔ تنها مصرف‌کننده، در پشتیِ خط فرمان است — مثل `--unlock`.
if (strpos($cliSrc, 'mergeCategories(') === false) {
    $badMerge[] = 'deploy/user-admin.php — --merge-categories باید از mergeCategories() رد شود';
}
// ⛔ دامنه از **ردیفِ مبدأ** خوانده می‌شود، نه از آرگومان: با آرگومان،
//    یک اشتباهِ تایپی ردیف‌های همه‌ی کاربران را جابه‌جا می‌کرد.
if (!preg_match('/\$src\[.user_id.\]\s*===\s*null\s*\?\s*null\s*:/', $cliSrc)) {
    $badMerge[] = 'deploy/user-admin.php — دامنه باید از ردیفِ مبدأ بیاید، نه از ورودی';
}

// --- صفحه‌بندی ---
if (strpos($pagSrc, 'const PAGED_LIST_SIZE') === false) {
    $badMerge[] = 'paged_list.php — سقفِ صفحه باید یک ثابتِ واحد باشد';
}
// ⛔ آدرس باید از `$_GET` ساخته شود، وگرنه صفحه‌ی فهرستِ دیگر می‌پرد.
if (strpos($pagSrc, '$q = $_GET;') === false) {
    $badMerge[] = 'paged_list.php — pagedUrl() بقیه‌ی پارامترها را نگه نمی‌دارد';
}
// ⛔ «همه» با نامِ فهرست سنجیده شود، نه یک پرچمِ مشترک.
if (strpos($pagSrc, "getParam('all') === \$key") === false) {
    $badMerge[] = 'paged_list.php — «همه» باید فقط همان فهرست را باز کند';
}
// ⛔ برش در PHP است، نه LIMIT: صفحه بودجه‌ی کوئری دارد.
if (preg_match('/\bLIMIT\b/i', $pagSrc)) {
    $badMerge[] = 'paged_list.php — صفحه‌بندی نباید کوئریِ LIMIT بزند';
}
// ⛔ و سقفِ بی‌صدا در خودِ صفحه نماند.
if (preg_match('/array_slice\(\s*\$(never|stale|activity)\b/', $insSrc)) {
    $badMerge[] = 'admin/insights.php — سقفِ بی‌صدای array_slice برگشته';
}
foreach (['never', 'stale', 'users'] as $k) {
    if (!preg_match('/pagedSlice\([^)]*,\s*\'' . $k . '\'\s*\)/', $insSrc)) {
        $badMerge[] = 'admin/insights.php — فهرستِ ' . $k . ' صفحه‌بندی نشده';
    }
}

T::bulk(46, $badMerge, '⛔ ادغام فقط از خط فرمان، و صفحه‌بندی یک مرجع دارد');

// ---------------------------------------------------------------
// قاعده ۴۲ — بازگرداندن از فایلِ بکاپ
// ---------------------------------------------------------------
//
// فایلِ `.sthesab` را **خودِ کاربر در دست دارد** و می‌تواند هر خطش را
// عوض کند. پس هر چیزی که از آن خوانده شود داده است، نه دستور — همان
// قاعده‌ی «پیلود مرورگر داده است» در `data.php`، این بار روی کلِ دفتر.
// چهار خرابیِ این مسیر همه بی‌صدایند:
//
// ۱. **`profile` نوشته شود** → یک فایلِ دست‌ساز با `"role":"admin"`
//    ارتقای دسترسی می‌داد، بی‌هیچ خطایی.
// ۲. **`user_id` از فایل خوانده شود** → ردیف به نامِ کاربرِ دیگری
//    می‌نشست؛ دقیقاً همان نشتی که `categoryScopeSql()` برای نبودنش
//    نوشته شد.
// ۳. **فهرستِ کلیدهای خارجی دستی شود** → جدولِ فردا جا می‌ماند و
//    نگاشتِ شناسه برایش انجام نمی‌شود: ردیف به رکوردِ **کسِ دیگری**
//    وصل می‌شود، چون `transactions.id` سراسری است.
// ۴. **رمزنگاریِ دوباره برداشته شود** → خروجی شماره کارت را **باز**
//    می‌کند، پس بازگرداندنِ بی‌رمز یعنی رمزنگاری بی‌صدا به یک no-op
//    تبدیل می‌شود (چون `decrypt()` مقدارِ بی‌پیشوند را دست‌نخورده
//    برمی‌گرداند و هیچ‌کس متوجه نمی‌شود).
T::group('قاعده ۴۲ — بازگرداندن از فایلِ بکاپ');

$badImp = [];

// ⚠ کامنت‌ها پیش از بررسی حذف می‌شوند: توضیحِ خودِ آن فایل واژه‌ی
//   `profile` و نامِ همه‌ی این توابع را دارد و بررسیِ متنی روی فایلِ
//   **سالم** قرمز می‌شد — همان دامِ قاعده‌های ۱۹ و ۳۵ و ۳۸.
$impPath = __DIR__ . '/../includes/user_import.php';
if (!is_file($impPath)) {
    $badImp[] = 'includes/user_import.php پیدا نشد';
    $impSrc = '';
} else {
    $impSrc = $stripComments($impPath);
}
$impApi = $stripComments(__DIR__ . '/../api/import_data.php');

// ⛔ فهرستِ ردشده بسته است: اعتبارنامه‌ها، `payments`، و پیوندِ سهامدار
//    (هر سه «رکوردِ نصب درباره‌ی کاربر»اند، نه دفترِ او).
foreach (['api_tokens', 'password_resets', 'trusted_devices', 'sms_codes', 'payments',
          'store_shareholders'] as $t) {
    if (!preg_match('/const\s+USER_IMPORT_SKIP\s*=\s*\[[^\]]*\'' . $t . '\'/s', $impSrc)) {
        $badImp[] = 'USER_IMPORT_SKIP — جدولِ ' . $t . ' باید رد شود';
    }
}

if (!preg_match('/function\s+importUserData\s*\(.*?\n\}/s', $impSrc, $im)) {
    $badImp[] = 'importUserData() پیدا نشد';
} else {
    $body = $im[0];

    // ⛔ مالکیت از نشست، هرگز از فایل. شرطی بودنش («اگر نبود بگذار»)
    //    همان باگ است، پس انتساب باید بی‌قید باشد.
    // ⚠ الگو به **اولِ خط** بسته است: بدونِ آن، جهشِ
    //   `if (!isset($write['user_id'])) { $write['user_id'] = $userId; }`
    //   زنده می‌ماند چون همان متن هنوز در فایل هست. **جهشِ زنده‌مانده
    //   اول یعنی تست ناقص است، نه اینکه کد امن است.**
    if (!preg_match('/^\s*\$write\[\'user_id\'\]\s*=\s*\$userId\s*;/m', $body)) {
        $badImp[] = 'importUserData() — user_id باید بی‌قید از نشست نوشته شود';
    }
    // ⛔ `profile` هرگز نوشته نمی‌شود.
    if (strpos($body, 'profile') !== false) {
        $badImp[] = 'importUserData() — profile نباید از فایل نوشته شود';
    }
    // ⛔ شماره کارت و شبا دوباره رمز می‌شوند.
    if (strpos($body, 'Crypto::encryptRow(') === false) {
        $badImp[] = 'importUserData() — ستون‌های حساس دوباره رمز نمی‌شوند';
    }
    // ⛔ سدِ پیش از commit — **خودِ دو شرط** سنجیده می‌شود نه رشته‌ی
    //    `rollBack()`، چون بلوکِ catch یکی دارد و بررسی پوچ می‌شد
    //    (همان درسِ قاعده‌های ۴۰ و ۴۱).
    if (!preg_match('/if\s*\(\s*\$othersNow\s*!==\s*\$othersBefore\s*\)/', $body)) {
        $badImp[] = 'importUserData() — سدِ «ردیفِ بقیه دست نخورد» برداشته شده';
    }
    if (!preg_match('/if\s*\(\s*\$mineNow\s*!==\s*\(\s*\$inserted\[\$t\]\s*\?\?\s*0\s*\)\s*\)/', $body)) {
        $badImp[] = 'importUserData() — سدِ شمارشِ پیش از commit برداشته شده';
    }
    // ⛔ جدولِ ناشناخته صریح رد می‌شود، نه اینکه بی‌صدا جا بماند.
    if (strpos($body, '$unknown') === false) {
        $badImp[] = 'importUserData() — جدولِ ناشناخته باید صریح رد شود';
    }
}

// ⛔ پیوندها و ستون‌های الزامی از دیتابیس کشف می‌شوند، نه فهرستِ دستی
//    — همان قاعده‌ی `userDataTables()` و `categoryRefTables()`.
foreach (['importForeignKeys', 'importRequiredLinks'] as $fn) {
    if (!preg_match('/function\s+' . $fn . '\s*\(.*?\n\}/s', $impSrc, $fm)
        || strpos($fm[0], 'information_schema') === false) {
        $badImp[] = $fn . '() — باید از information_schema کشف کند';
    }
}

// ⛔ تنها یک درِ ورودی: اندپوینت، با CSRF و عبارتِ تایپ‌شده.
if (strpos($impApi, 'importUserData(') === false) {
    $badImp[] = 'api/import_data.php — بازگرداندن از importUserData() رد نمی‌شود';
}
if (strpos($impApi, 'Csrf::verifyOrFail(') === false) {
    $badImp[] = 'api/import_data.php — CSRF ندارد';
}
// ⚠ خودِ **مقایسه** سنجیده می‌شود، نه وجودِ نامِ ثابت: پیامِ خطا هم
//   همان نام را دارد، پس بررسیِ «نام هست» با برداشتنِ گیت هم سبز
//   می‌ماند — و جهش دقیقاً همین را نشان داد.
if (!preg_match('/!==\s*IMPORT_CONFIRM_PHRASE/', $impApi)) {
    $badImp[] = 'api/import_data.php — سدِ عبارتِ تایپ‌شده برداشته شده';
}
// و هیچ مصرف‌کننده‌ی دومی نباشد (همان قاعده‌ی «یک مرجع»).
foreach (glob(__DIR__ . '/../{api,includes,admin,store,.}/*.php', GLOB_BRACE) as $p) {
    $base = basename($p);
    if ($base === 'import_data.php' || $base === 'user_import.php') { continue; }
    if (strpos($stripComments($p), 'importUserData(') !== false) {
        $badImp[] = $base . ' — بازگرداندن باید فقط از api/import_data.php برود';
    }
}

T::bulk(16, $badImp, '⛔ بازگرداندن از فایل یک مرجع دارد و مالکیت از نشست می‌آید');

// ---------------------------------------------------------------
// قاعده ۴۳ — عدد چپ‌چین می‌ماند
// ---------------------------------------------------------------
//
// **گزارشِ مالکِ نصب:** «در کارت‌ها مانده‌ها اعداد … الان راست‌چین
// هستند. کلاً اعداد باید چپ‌چین باشن.» و او درست می‌گفت: عددِ درشتِ
// نوارِ مانده روی `gapL = ۱۶۶` و `gapR = ۰` می‌نشست.
//
// ⛔ **علتش در خودِ `.bv-num` نبود** — آن از قبل `direction: ltr` و
//    `text-align: left` داشت. پدرش `flex-direction: row-reverse` بود و
//    در محورِ راست‌به‌چپ، `justify-content: flex-start` یعنی لبه‌ی
//    **راست**. همان درسِ همیشگی: داشتنِ مقدار در فایل کافی نیست.
//
// ⛔ `tests/test_number_align.php` همین را در کرومیوم **اندازه
//    می‌گیرد**، ولی کرومیوم ابزارِ اختیاری است و نبودنش `T::skip` —
//    پس روی ماشینی که مرورگر ندارد، این قاعده تنها نگهبانِ باقی‌مانده
//    است. «دو تست و هر دو لازم».
//
// ⚠ کامنت‌های CSS پیش از بررسی حذف می‌شوند: توضیحِ بالای همان قاعده‌ها
//   واژه‌ی `row-reverse` و `direction: ltr` را دارد و بررسیِ متنی روی
//   فایلِ **سالم** قرمز می‌شد — همان دامِ قاعده‌های ۱۹ و ۲۰ و ۳۵ و ۳۸.
T::group('قاعده ۴۳ — عدد چپ‌چین می‌ماند');

$badAlign = [];
$cssPath  = __DIR__ . '/../assets/css/style.css';
$cssRaw   = is_file($cssPath) ? (string)file_get_contents($cssPath) : '';
if ($cssRaw === '') { $badAlign[] = 'assets/css/style.css پیدا نشد'; }
$cssSrcA = (string)preg_replace('#/\*.*?\*/#s', '', $cssRaw);

/** بدنه‌ی یک قاعده را با انتخابگرِ **دقیق** برمی‌دارد. */
$cssBlock = static function (string $src, string $selector): string {
    $re = '/(?:^|\})\s*' . preg_quote($selector, '/') . '\s*\{([^{}]*)\}/s';
    return preg_match($re, $src, $m) ? $m[1] : '';
};

// ۱) فهرستِ `.ltr-num` تنها مرجعِ «این یک عدد است» بماند، و `.bv-num`
//    داخلش باشد — وگرنه پوششِ تستِ رفتاری بی‌صدا از دستش می‌رود.
$groupSel = '';
if (preg_match('/(?:^|\})\s*(\.ltr-num\s*,[^{}]*?)\{([^{}]*)\}/s', $cssSrcA, $gm)) {
    if (strpos($gm[2], 'direction: ltr') !== false && strpos($gm[2], 'text-align: left') !== false) {
        $groupSel = $gm[1];
    }
}
if ($groupSel === '') {
    $badAlign[] = 'فهرستِ .ltr-num با direction/text-align پیدا نشد';
} else {
    foreach (['.bv-num', '.balance-value', '.wallet-bal', '.bank-card-balance'] as $need) {
        if (!preg_match('/' . preg_quote($need, '/') . '\s*[,{]/', $groupSel . '{')) {
            $badAlign[] = "«{$need}» باید در فهرستِ .ltr-num باشد";
        }
    }
}

// ۲) نوارِ مانده: `row-reverse` همان باگ بود.
$bvWrap = $cssBlock($cssSrcA, '.balance-value');
if ($bvWrap === '') {
    $badAlign[] = '.balance-value پیدا نشد';
} elseif (strpos($bvWrap, 'row-reverse') !== false) {
    $badAlign[] = '.balance-value — row-reverse عدد را به لبه‌ی راست می‌برد';
}

// ۳) `.bv-num` نباید `direction` خودش را داشته باشد: تنها مرجع همان
//    فهرست است، وگرنه نسخه‌ی دومی می‌شود که از آن عقب می‌افتد.
$bvNum = $cssBlock($cssSrcA, '.bv-num');
if ($bvNum !== '' && strpos($bvNum, 'direction') !== false) {
    $badAlign[] = '.bv-num — direction باید فقط از فهرستِ .ltr-num بیاید';
}

// ۴) مبلغِ ردیفِ سررسید: `.due-meta` سطرشکن است، پس مبلغ باید با یک
//    حاشیه‌ی **فیزیکی** به لبه‌ی چپ برود. `margin-inline-*` در آن ردیفِ
//    راست‌به‌چپ دوباره حل می‌شود و دقیقاً برعکس می‌نشیند.
$dueAmt = $cssBlock($cssSrcA, '.due-amount');
if ($dueAmt === '') {
    $badAlign[] = '.due-amount پیدا نشد';
} else {
    if (!preg_match('/margin-right\s*:\s*auto/', $dueAmt)) {
        $badAlign[] = '.due-amount — بدونِ margin-right:auto مبلغ به راست می‌چسبد';
    }
    if (strpos($dueAmt, 'margin-inline') !== false) {
        $badAlign[] = '.due-amount — margin-inline جهت‌وابسته است و برعکس می‌نشیند';
    }
}

T::bulk(9, $badAlign, '⛔ عددها چپ‌چین می‌مانند');

// ---------------------------------------------------------------
// قاعده ۴۴ — یک لاگر، یک دفترِ ممیزی، و مرزِ حریمِ خصوصی
// ---------------------------------------------------------------
//
// `Log` (includes/log.php) تنها راهِ نوشتنِ لاگ است و `Audit`
// (includes/audit.php) تنها دفترِ رویدادهای امنیتی. سه خرابیِ بی‌صدا که
// این قاعده می‌بندد:
//   ۱. یک `error_log()` تازه جای دیگری — خطایی که نه شناسه‌ی درخواست
//      دارد، نه در `app_errors` می‌نشیند، نه در `log-report` دیده می‌شود.
//   ۲. مسیرِ ورود/خروج/تغییر رمز/ساخت و حذفِ حسابی که از `Audit::log()`
//      رد نشود — «کی این کار را کرد؟» بی‌جواب می‌ماند، بی‌هیچ خطایی.
//   ۳. `admin/insights.php` که از `audit_log` آمار بسازد — همان «ردِ
//      رفتاری» که `privacy.php` قول داده وجود ندارد.
//
// ⛔ و یک مورد که **امروز واقعاً شکست**: `Audit::available()` فقط
//    `function_exists('tableExists')` را می‌پرسید و `logout.php` آن تابع
//    را لود نمی‌کند — پس خروج **هرگز** به جدول نمی‌رسید (خطِ فایل نوشته
//    می‌شد و `db.n = 0`). `AppErrors::available()` هم همان را داشت.
//    حالا هر دو بدونِ `functions.php` هم جدول را می‌پرسند.
//
// ⚠ کامنت‌ها با توکنایزر حذف می‌شوند: `admin/insights.php` در توضیحش
//   نامِ `error_log()` را دارد و همین قاعده روی فایلِ **سالم** قرمز می‌شد.
T::group('قاعده ۴۴ — یک لاگر، یک دفترِ ممیزی');

$badLog = [];

/** بدنه‌ی یک تابع/متد تا تعریفِ تابعِ بعدی — همان درسِ قاعده ۲۹. */
$fnChunk = static function (string $src, string $sig): string {
    $s = strpos($src, $sig);
    if ($s === false) { return ''; }
    $e = strpos($src, "function ", $s + strlen($sig));
    return $e === false ? substr($src, $s) : substr($src, $s, $e - $s);
};

// ۱) error_log فقط در دو فایلِ خودِ لایه‌ی لاگ.
$logAllowed = [realpath($root . '/includes/log.php'), realpath($root . '/includes/app_errors.php')];
$scanDirs   = ['.', 'api', 'api/v1', 'api/v1/routes', 'includes', 'admin', 'store', 'deploy', 'assets'];
$scanned    = 0;
foreach ($scanDirs as $d) {
    foreach (glob($root . '/' . $d . '/*.php') as $p) {
        $rp = realpath($p);
        if (in_array($rp, $logAllowed, true)) { continue; }
        $scanned++;
        if (preg_match('/\berror_log\s*\(/', $stripComments($p))) {
            $badLog[] = str_replace($root . '/', '', $rp) . ' — error_log() مستقیم؛ باید Log::error/warn باشد';
        }
    }
}
if ($scanned < 80) { $badLog[] = "فقط {$scanned} فایل پویش شد — glob شکسته است"; }

// ۲) db.php: لاگر بوت می‌شود و هر کوئری از DbStatement رد می‌شود (زمان و شمارش).
$dbSrc = $stripComments($root . '/includes/db.php');
foreach (['Log::boot()', 'PDO::ATTR_STATEMENT_CLASS', 'new DbConnection('] as $need) {
    if (strpos($dbSrc, $need) === false) { $badLog[] = "db.php — «{$need}» نیست"; }
}

// ۳) هر دو available() بدونِ functions.php هم جدول را می‌پرسند (باگِ امروز).
$auSrc = $stripComments($root . '/includes/audit.php');
$aeSrc = $stripComments($root . '/includes/app_errors.php');
if (strpos($fnChunk($auSrc, 'function available('), 'SELECT 1 FROM audit_log') === false) {
    $badLog[] = 'audit.php — available() بدونِ functions.php جدول را نمی‌پرسد (خروج به جدول نمی‌رسد)';
}
if (strpos($fnChunk($aeSrc, 'function available('), 'SELECT 1 FROM app_errors') === false) {
    $badLog[] = 'app_errors.php — available() بدونِ functions.php جدول را نمی‌پرسد';
}

// ۴) مسیرهای امنیتی از Audit::log رد می‌شوند.
$fnSrcC   = $stripComments($root . '/includes/functions.php');
$authSrcC = $stripComments($root . '/includes/auth.php');
$suSrcC   = $stripComments($root . '/includes/signup.php');
$udSrcC   = $stripComments($root . '/includes/user_data.php');
$hooks = [
    ['auth.php',      $authSrcC, 'function establishSession(', 'Audit::log(', "'auth.login'"],
    ['auth.php',      $authSrcC, 'function logout(',           'Audit::log(', "'auth.logout'"],
    ['auth.php',      $authSrcC, 'function verifyCredentials(', 'Audit::log(', "'auth.login_failed'"],
    ['functions.php', $fnSrcC,   'function revokeAllAccessFor(', 'Audit::log(', "'auth.access_revoked'"],
    ['functions.php', $fnSrcC,   'function setSetting(',        'Audit::setting(', ''],
    ['signup.php',    $suSrcC,   'function createUserAccount(', 'Audit::log(', "'user.created'"],
    ['user_data.php', $udSrcC,   'function deleteUserAccount(', 'Audit::log(', "'account.deleted'"],
];
foreach ($hooks as [$file, $src, $sig, $call, $action]) {
    $chunk = $fnChunk($src, $sig);
    if ($chunk === '') { $badLog[] = "{$file} — {$sig} پیدا نشد"; continue; }
    if (strpos($chunk, $call) === false || ($action !== '' && strpos($chunk, $action) === false)) {
        $badLog[] = "{$file} — {$sig} باید {$call}{$action} داشته باشد";
    }
}
// ⛔ ورودِ خودکار از کوکیِ دستگاهِ مورد اعتماد عمداً ممیزی نمی‌شود
//    (ادامه‌ی همان ورود است، نه اعتبارنامه‌ی تازه).
if (strpos($fnChunk($authSrcC, 'function loginFromTrustedDevice('), 'Audit::log(') !== false) {
    $badLog[] = 'auth.php — loginFromTrustedDevice نباید ممیزی شود (هر چند دقیقه یک ردیف)';
}

// ۵) پاکتِ خطا شناسه‌ی درخواست را می‌برد — در هر دو لایه.
//    ⚠ خودِ **انتساب** سنجیده می‌شود، نه وجودِ واژه: نگهبانِ
//      `array_key_exists('request_id', …)` همان واژه را دارد و جهشِ
//      «انتساب را بردار» با بررسیِ واژه‌ای **زنده ماند**.
if (!preg_match('/\$data\[\'request_id\'\]\s*=\s*Log::requestId\(\)/', $fnChunk($fnSrcC, 'function jsonResponse('))) {
    $badLog[] = 'functions.php — jsonResponse() در خطا request_id نمی‌فرستد';
}
$apiSrcC = $stripComments($root . '/includes/api.php');
if (!preg_match('/\'request_id\'\s*=>\s*Log::requestId\(\)/', $fnChunk($apiSrcC, 'function fail('))) {
    $badLog[] = 'api.php — Api::fail() request_id نمی‌فرستد';
}

// ۶) مرزِ حریمِ خصوصی: آمار از audit_log ساخته نمی‌شود؛ خودِ صفحه فقط
//    Audit::recent/countSince را برای کارتِ رویدادها صدا می‌زند.
foreach (['includes/admin_insights.php', 'admin/insights.php'] as $f) {
    if (preg_match('/\baudit_log\b/i', $stripComments($root . '/' . $f))) {
        $badLog[] = "{$f} — به جدولِ audit_log دست می‌زند (ردِ رفتاری)";
    }
}
if (strpos($stripComments($root . '/admin/insights.php'), 'Audit::recent(') === false) {
    $badLog[] = 'admin/insights.php — کارتِ رویدادهای امنیتی رفته است';
}

// ۷) privacy.php مهلت‌ها را از خودِ کد می‌خواند، نه عددِ سخت‌کد.
$prSrcC = $stripComments($root . '/privacy.php');
foreach (['Log::KEEP_DAYS', 'Audit::KEEP_DAYS'] as $need) {
    if (strpos($prSrcC, $need) === false) { $badLog[] = "privacy.php — «{$need}» نیست؛ عددِ نوشته‌شده دیر یا زود دروغ می‌شود"; }
}

// ۸) مرورگر: گزارشِ خطا و پیامِ خطای شناسه‌دار — و alertِ خامِ قدیمی برنگردد.
$jsSrcL = (string)preg_replace('#/\*.*?\*/|(?<![:\'"])//[^\n]*#s', '', (string)file_get_contents($root . '/assets/js/app.js'));
foreach (['window.netErr', 'X-Request-Id'] as $need) {
    if (strpos($jsSrcL, $need) === false) { $badLog[] = "app.js — «{$need}» نیست"; }
}
// ⛔ گزارش‌گر در `client-errors.js` است، مشترکِ دو پوسته — و هر دو پوسته لودش می‌کنند.
$ceSrcL = (string)preg_replace('#/\*.*?\*/|(?<![:\'"])//[^\n]*#s', '', (string)@file_get_contents($root . '/assets/js/client-errors.js'));
if (strpos($ceSrcL, 'log_client_error.php') === false) { $badLog[] = 'client-errors.js — «log_client_error.php» نیست'; }
foreach (['includes/header.php', 'includes/biz_head.php'] as $ceShell) {
    if (!preg_match("/assetUrls\(\[\s*'js\/client-errors\.js'/", (string)file_get_contents($root . '/' . $ceShell))) {
        $badLog[] = "{$ceShell} — `client-errors.js` اولین اسکریپتِ لودشده نیست (خطای پیش از آن گزارش نمی‌شود)";
    }
}
if (preg_match("/alert\\(\\s*['\"]خطا در ارتباط با سرور/u", $jsSrcL)) {
    $badLog[] = 'app.js — alert خامِ «خطا در ارتباط با سرور» برگشته؛ باید netErr() باشد تا کدِ پیگیری برود';
}

// ۹) migration در هر دو فهرستِ migrate.sh.
$migSrc = (string)file_get_contents($root . '/deploy/migrate.sh');
if (!preg_match('/^\s*migration_audit_log\.sql\s*$/m', $migSrc)) { $badLog[] = 'migrate.sh — migration_audit_log.sql در MIGRATIONS نیست'; }
if (!preg_match('/\[migration_audit_log\.sql\]="audit_log"/', $migSrc)) { $badLog[] = 'migrate.sh — شاهدِ migration_audit_log در SENTINEL نیست'; }

T::bulk(9, $badLog, '⛔ یک لاگر، یک دفترِ ممیزی، مرزِ حریمِ خصوصی');

// ---------------------------------------------------------------
// قاعده ۴۵ — «برطرف شد» نباید به «برای همیشه ساکت» بدل شود
// ---------------------------------------------------------------
//
// **خواسته‌ی مالکِ نصب:** «یک سیستم لاگ می‌خوام که توی بخش مدیریت
// اطلاع‌رسانی بشه و بتونم راحت برطرفش کنم، شلوغ نباشه و قابل مدیریت.»
//
// چهار خرابیِ بی‌صدا که این قاعده می‌بندد:
//   ۱. برداشتنِ `resolved_at = NULL` از `ON DUPLICATE KEY` در
//      `AppErrors::record()` — آن‌وقت یک خطای **زنده** که یک بار
//      «برطرف شد» خورده، برای همیشه از پنل ناپدید می‌شود. نه خطایی،
//      نه نشانی؛ فقط اپی که خراب است و پنل می‌گوید سالم.
//   ۲. هرسِ خودکاری که خطای **باز** را هم ببرد.
//   ۳. اعلانی که به کاربر عادی هم برود، یا از `openCount()` بیاید
//      به‌جای `openSince()` — یعنی هشدارِ همیشگی.
//   ۴. برگشتِ فهرستِ خطا به تهِ `admin/insights.php` — همان شلوغی‌ای
//      که این جابه‌جایی برای رفعش انجام شد.
T::group('قاعده ۴۵ — رسیدگی به خطا');

$badTri = [];
$aeSrcT = $stripComments($root . '/includes/app_errors.php');

// ۱) رخدادِ دوباره مهرِ «برطرف شد» را برمی‌دارد.
$recChunk = $fnChunk($aeSrcT, 'function record(');
if (strpos($recChunk, 'ON DUPLICATE KEY UPDATE') === false) {
    $badTri[] = 'app_errors.php — record() دیگر ON DUPLICATE KEY ندارد';
} elseif (!preg_match('/resolved_at\s*=\s*NULL/', $recChunk)) {
    $badTri[] = '⛔ app_errors.php — record() مهرِ «برطرف شد» را با رخدادِ دوباره برنمی‌دارد؛'
        . ' یک خطای زنده برای همیشه ساکت می‌شود';
}

// ۲) هرس فقط رسیدگی‌شده‌ها را می‌برد.
$prChunk = $fnChunk($aeSrcT, 'function prune(');
if (strpos($prChunk, 'resolved_at IS NOT NULL') === false) {
    $badTri[] = '⛔ app_errors.php — prune() شرطِ «فقط رسیدگی‌شده» را ندارد؛ خطای باز هم پاک می‌شود';
}
if (strpos($fnChunk($aeSrcT, 'function purgeResolved('), 'resolved_at IS NOT NULL') === false) {
    $badTri[] = '⛔ app_errors.php — purgeResolved() بی‌قید است؛ خطای دیده‌نشده را هم می‌برد';
}
// ⚠ عددِ نگه‌داری یک مرجع دارد و صفحه از همان می‌خواند.
if (strpos($aeSrcT, 'const KEEP_DAYS') === false) {
    $badTri[] = 'app_errors.php — KEEP_DAYS تنها مرجعِ مهلت است و نیست';
}

// ۳) اعلان: فقط مدیر، فقط خطای بازِ تازه، فقط یکی در روز.
$ntSrcT = $stripComments($root . '/includes/notify.php');
$adChunk = $fnChunk($ntSrcT, 'function generateAdminErrors(');
if ($adChunk === '') {
    $badTri[] = 'notify.php — generateAdminErrors() نیست؛ اطلاع‌رسانیِ خطا رفته';
} else {
    if (strpos($adChunk, "'admin'") === false) {
        $badTri[] = '⛔ notify.php — اعلانِ خطا به نقشِ مدیر بند نیست؛ کاربر عادی هم می‌گیرد';
    }
    if (strpos($adChunk, 'AppErrors::openSince(') === false) {
        $badTri[] = '⛔ notify.php — اعلان باید از openSince() بیاید نه openCount()؛'
            . ' وگرنه خطای ماه‌ها پیش هر روز اعلان می‌دهد (هشدارِ همیشگی)';
    }
    if (!preg_match("/'apperr:'\s*\.\s*\\\$today/", $adChunk)) {
        $badTri[] = '⛔ notify.php — dedup_key اعلانِ خطا روزانه نیست؛ یک روزِ بد ده‌ها اعلان می‌سازد';
    }
    if (strpos($adChunk, "'admin/errors.php'") === false) {
        $badTri[] = 'notify.php — اعلان به صفحه‌ی خطاها لینک نمی‌دهد';
    }
}
if (strpos($fnChunk($ntSrcT, 'function generateFor('), 'generateAdminErrors(') === false) {
    $badTri[] = 'notify.php — generateAdminErrors از generateFor صدا زده نمی‌شود';
}

// ۴) صفحه‌ی مدیر: نگهبان، CSRF، ریدایرکت پس از نوشتن، و یک مرجعِ صافی.
$erPath = $root . '/admin/errors.php';
if (!is_file($erPath)) {
    $badTri[] = 'admin/errors.php نیست — اطلاع‌رسانی و رسیدگی رفته';
} else {
    $erSrc = $stripComments($erPath);
    foreach (['Auth::requireAdmin()', 'Csrf::verifyOrFail(', 'AppErrors::FILTERS',
              // ⛔ دکمه‌ها فقط از `handleAction()` — مشترک با `store/errors.php` (قاعده ۷۵)
              'AppErrors::handleAction(', 'pagedSlice('] as $need) {
        if (strpos($erSrc, $need) === false) { $badTri[] = "admin/errors.php — «{$need}» نیست"; }
    }
    // ⛔ پس از POST ریدایرکت، نه رندرِ مستقیم: با رندر، تازه‌سازیِ صفحه
    //    همان «برطرف شد» را دوباره می‌فرستاد.
    if (strpos($erSrc, "header('Location: ") === false) {
        $badTri[] = '⛔ admin/errors.php — پس از نوشتن ریدایرکت نمی‌کند (تازه‌سازی عملیات را تکرار می‌کند)';
    }
    // ⛔ دکمه فقط وقتی رندر شود که ستونِ migration آمده باشد —
    //    «دکمه‌ی بی‌کار از نبودنش بدتر است».
    if (strpos($erSrc, 'triageAvailable()') === false) {
        $badTri[] = '⛔ admin/errors.php — دکمه‌ی رسیدگی به وجودِ ستون بند نیست';
    }
}

// ۵) نوارِ مدیر: زبانه‌ی خطاها + نشانی که با صفر رندر نمی‌شود.
$navSrc = $stripComments($root . '/admin/_nav.php');
// ⚠ خودِ **ردیفِ آرایه** سنجیده می‌شود، نه وجودِ رشته: شرطِ نشان هم
//   `$__file === 'errors.php'` را دارد، پس بررسیِ رشته‌ای با برداشتنِ
//   کاملِ زبانه هم سبز می‌ماند — یعنی پوچ بود. جهش نشانش داد.
if (!preg_match("/'errors\.php'\s*=>/", $navSrc)) {
    $badTri[] = 'admin/_nav.php — زبانه‌ی «خطاها» نیست؛ اطلاع‌رسانی فقط داخلِ همان صفحه می‌ماند';
}
if (strpos($navSrc, 'AppErrors::openCount()') === false) {
    $badTri[] = '⛔ admin/_nav.php — نشانِ تعدادِ خطای باز رفته است';
}
if (!preg_match('/\$__errOpen\s*>\s*0/', $navSrc)) {
    $badTri[] = '⛔ admin/_nav.php — نشان با صفر هم رندر می‌شود؛ نشانِ «۰» آدم را عادت می‌دهد نگاهش نکند';
}

// ۶) `insights` فهرستِ خطا را برنگرداند — فقط خلاصه و لینک.
$inSrcT = $stripComments($root . '/admin/insights.php');
if (strpos($inSrcT, 'AppErrors::recent(') !== false) {
    $badTri[] = '⛔ admin/insights.php — فهرستِ خطا برگشته؛ همان شلوغی‌ای که به admin/errors.php منتقل شد';
}
if (strpos($inSrcT, 'admin/errors.php') === false) {
    $badTri[] = 'admin/insights.php — لینکِ صفحه‌ی خطاها نیست';
}

// ۷) migration در هر دو فهرستِ migrate.sh.
$migSrc2 = (string)file_get_contents($root . '/deploy/migrate.sh');
if (!preg_match('/^\s*migration_error_triage\.sql\s*$/m', $migSrc2)) {
    $badTri[] = 'migrate.sh — migration_error_triage.sql در MIGRATIONS نیست';
}
if (!preg_match('/\[migration_error_triage\.sql\]="app_errors\.resolved_at"/', $migSrc2)) {
    $badTri[] = 'migrate.sh — شاهدِ migration_error_triage در SENTINEL نیست';
}

T::bulk(7, $badTri, '⛔ «برطرف شد» برگشت‌پذیر است و اطلاع‌رسانی سرِ جایش');


// ═══════════════════════════════════════════════════════════════
// قاعده ۴۶ — سهمِ سهامدارِ فروشگاه: خواندنی، بی‌حساب، و ایدمپوتنت
// ═══════════════════════════════════════════════════════════════
//
// چهار خرابیِ این قابلیت همه **بی‌صدا**اند و هیچ‌کدام را تستِ رفتاری
// روی نصبِ خاموش نمی‌بیند (پیش‌فرض `STORE_API_URL` خالی است، پس آن تست
// روی ماشینِ بی‌پیکربندی `T::blocked` می‌شود):
//
//  ۱. تراکنشِ سود با `wallet_id` → همان پول دو بار (یک بار در قلمِ
//     دارایی، یک بار در موجودیِ حساب).
//  ۲. نبودِ `store_share_ref` → هر همگام‌سازی درآمد را از نو ثبت می‌کند.
//  ۳. `forUser()` با یک شرط → یا مدیر پیوند را غیرفعال می‌کند و کاربر
//     باز هم می‌بیند، یا کاربری دفترِ سهامدارِ دیگری را می‌بیند.
//  ۴. محاسبه‌ی درصد/سود در این سمت → نسخه‌ی دومِ منطقِ پول بینِ دو برنامه.

T::group('قاعده ۴۶ — سهم سهامدار فروشگاه');

$badSS  = [];
$ssPath = $root . '/includes/store_share.php';
if (!is_file($ssPath)) {
    $badSS[] = 'includes/store_share.php پیدا نشد';
    $ssSrc   = '';
} else {
    $ssSrc = $stripComments($ssPath);
}

// ۱) تراکنشِ سود عمداً بدون حساب است.
if (preg_match('/INSERT\s+INTO\s+transactions(.*?)VALUES/is', $ssSrc, $mIns)) {
    if (stripos($mIns[1], 'wallet_id') !== false) {
        $badSS[] = '⛔ store_share — تراکنشِ سود wallet_id گرفته؛ پول دو بار شمرده می‌شود';
    }
    if (stripos($mIns[1], 'store_share_ref') === false) {
        $badSS[] = '⛔ store_share — ستونِ store_share_ref در INSERT نیست؛ همگام‌سازی تکراری می‌سازد';
    }
} else {
    $badSS[] = 'store_share — INSERT INTO transactions پیدا نشد';
}

// ۲) ایدمپوتنسی: هم `ON DUPLICATE KEY` هم کلیدِ یکتا در migration.
if (stripos($ssSrc, 'ON DUPLICATE KEY UPDATE') === false) {
    $badSS[] = '⛔ store_share — ON DUPLICATE KEY نیست؛ مبلغِ اصلاح‌شده در سمتِ فروشگاه اینجا کهنه می‌ماند';
}

// ۳) `forUser()` هر دو شرط را می‌خواهد: پیوندِ فعال **و** ردیفِ آینه.
if (preg_match('/function\s+forUser\s*\([^)]*\)\s*:\s*\??array\s*\{(.*?)\n    \}/s', $ssSrc, $mFu)) {
    $fu = $mFu[1];
    if (strpos($fu, 'linkFor(') === false) {
        $badSS[] = '⛔ forUser() — پیوندِ فعال را نمی‌سنجد';
    }
    if (strpos($fu, 'payload(') === false) {
        $badSS[] = '⛔ forUser() — آینه را نمی‌سنجد';
    }
    if (strpos($fu, 'store_contact_id') === false) {
        $badSS[] = '⛔ forUser() — شناسه‌ی سهامدار را با ردیفِ آینه تطبیق نمی‌دهد';
    }
} else {
    $badSS[] = 'forUser() پیدا نشد';
}

// ۴) هیچ محاسبه‌ی سهم/درصدی این سمت نیست — فقط خواندن.
foreach (['shareholderPhonePct', 'buyerPct', 'sellerPct', 'itemProfit'] as $needle) {
    if (stripos($ssSrc, $needle) !== false) {
        $badSS[] = '⛔ store_share — «' . $needle . '» اینجا نباید باشد؛ محاسبه کارِ حسابداری فروشگاه است';
    }
}

// ۵) سقفِ پنج نفر در **دو** جا: تابعِ خواندن و مسیرِ نوشتن.
// ⚠ مرزِ `;` اجباری است: بدونش `= 50` هم با `= 5` تطبیق می‌کرد و
//   جهشِ «سقف را ۵۰ کن» **زنده ماند**. جهشِ زنده‌مانده یعنی تست ناقص
//   است، نه اینکه کد امن است.
if (!preg_match('/const\s+MAX_LINKS\s*=\s*5\s*;/', $ssSrc)) {
    $badSS[] = '⛔ store_share — MAX_LINKS = 5 نیست (خواسته‌ی صریحِ مالکِ نصب)';
}
if (!preg_match('/function\s+link\s*\(.*?activeCount\(\)\s*>=\s*self::MAX_LINKS/s', $ssSrc)) {
    $badSS[] = '⛔ store_share — سقف در مسیرِ وصل کردن اعمال نمی‌شود (درسِ PINNED_WALLET_MAX)';
}

// ۶) پیش‌فرض خاموش: هم آدرس هم توکن لازم است.
if (!preg_match('/function\s+configured\s*\(\)\s*:\s*bool\s*\{(.*?)\n    \}/s', $ssSrc, $mCf)
    || strpos($mCf[1], "!== ''") === false
    || strpos($mCf[1], '&&') === false) {
    $badSS[] = '⛔ configured() — باید هر دوی آدرس و توکن را بخواهد، وگرنه قابلیت نیم‌بند روشن می‌شود';
}

// ۷) تنها درِ وصل کردن، صفحه‌ی مدیر است — هیچ اندپوینتِ `api/` ای نه.
foreach (glob($root . '/api/*.php') as $apiFile) {
    $src = $stripComments($apiFile);
    if (strpos($src, 'StoreShare::link(') !== false || strpos($src, 'StoreShare::unlink(') !== false) {
        $badSS[] = '⛔ ' . basename($apiFile) . ' — وصل/قطع کردنِ سهامدار فقط از admin/store-share.php';
    }
}
if (!is_file($root . '/admin/store-share.php')) {
    $badSS[] = 'admin/store-share.php پیدا نشد';
} else {
    $ssAdmin = $stripComments($root . '/admin/store-share.php');
    if (strpos($ssAdmin, 'Auth::requireAdmin()') === false) {
        $badSS[] = '⛔ admin/store-share.php — requireAdmin() ندارد';
    }
    if (strpos($ssAdmin, 'Csrf::verifyOrFail(') === false) {
        $badSS[] = '⛔ admin/store-share.php — CSRF ندارد (قاعده ۳)';
    }
}

// ۸) توگلِ «دارایی کل فروشگاه» فقط برای مدیر، و سطلِ عکسِ روزانه
//    دقیقاً هم‌نامِ `kind` — وگرنه اجزا بی‌صدا غلط می‌شوند.
//    ⚠ این منطق از `my-assets.php` به `netWorthPortfolio()` /
//    `netWorthSnapParts()` رفت (خانه‌ی دسکتاپ هم همان را می‌خواند)، پس
//    بدنه‌ی همان دو تابع سنجیده می‌شود، نه صفحه.
$maSrc   = $stripComments($root . '/my-assets.php');
$fnSrc46 = $stripComments($root . '/includes/functions.php');
$nwBody  = preg_match('/function netWorthPortfolio\(.*?\n\}/s', $fnSrc46, $mm46) ? $mm46[0] : '';
$snBody  = preg_match('/function netWorthSnapParts\(.*?\n\}/s', $fnSrc46, $mm46) ? $mm46[0] : '';
if (!preg_match('/if \(StoreShare::available\(\)\) \{.*?if \(\$isAdmin\) \{\s*\$storeNet\s*=\s*StoreShare::storeNetWorth\(\)/s', $nwBody)
    || !preg_match('/netWorthPortfolio\(\$userId, Auth::isAdmin\(\)/', $stripComments($root . '/my-assets.php'))) {
    $badSS[] = '⛔ netWorthPortfolio — «دارایی کل فروشگاه» پشتِ isAdmin نیست (یا صفحه نقشِ مدیر را پاس نمی‌دهد)';
}
foreach (['store_share', 'store_total', 'store_owed'] as $kind) {
    if (!preg_match("/'" . $kind . "'\s*=>\s*'" . $kind . "'/", $snBody)) {
        $badSS[] = '⛔ netWorthSnapParts — سطلِ ' . $kind . ' هم‌نامِ kind نیست';
    }
}

// ۸‑ب) «طلب سهامداران» هم پشتِ مدیر است، و سهمِ خودِ همان مدیر از آن کم
//      می‌شود — وگرنه با «دارایی من در فروشگاه» هم‌پوشانی دارد و مانده‌اش
//      **دو بار** در جمعِ صفحه می‌نشیند. هر دو عدد جداگانه درست‌اند، پس
//      این خرابی هیچ نشانه‌ای ندارد.
if (!preg_match('/StoreShare::storeOwed\(\s*\$userId\s*\)/', $nwBody)) {
    $badSS[] = '⛔ netWorthPortfolio — storeOwed() بدونِ $userId صدا زده شده؛ سهمِ خودِ مدیر دو بار شمرده می‌شود';
}
if (!preg_match('/\$storeOwed\s*!==\s*null/', $nwBody)) {
    $badSS[] = '⛔ netWorthPortfolio — قلمِ «طلب سهامداران» بدونِ نگهبانِ null ساخته می‌شود (صفرِ ساختگی)';
}
$ssOwed = $stripComments($root . '/includes/store_share.php');
if (!preg_match("/array_key_exists\('shareholders_owed'/", $ssOwed)) {
    $badSS[] = '⛔ store_share — storeOwed() وجودِ کلید را نمی‌سنجد؛ نصبِ عقب‌مانده صفر می‌گیرد';
}
if (!preg_match('/\$owed\s*-=/', $ssOwed)) {
    $badSS[] = '⛔ store_share — سهمِ خودِ کاربر از طلبِ سهامداران کم نمی‌شود';
}

// ۸‑ج) عکسِ روزانه هم باید سطلِ سوم را بنویسد و بخواند، وگرنه اجزای
//      روندِ خالص دارایی بی‌صدا غلط می‌شوند (همان درسِ store_share).
//      ⚠ **دو** جای فراخوانی لازم است — نوشتن و خواندن. نسخه‌ی اول
//        فقط «یک بار هست» را می‌سنجید و جهشِ «یکی را بردار» **زنده
//        ماند**: با از بین رفتنِ نوشتن، خواندن هنوز آن رشته را داشت.
//        جهشِ زنده‌مانده یعنی تست ناقص است، نه اینکه کد امن است.
$fnSS = $stripComments($root . '/includes/functions.php');
if (substr_count($fnSS, "tableHasColumn('net_worth_snapshots', 'store_owed')") < 2) {
    $badSS[] = '⛔ functions.php — سطلِ store_owed باید هم در نوشتنِ عکس باشد هم در خواندنش';
}
foreach (["'so' => (int)(\$parts['store_owed'] ?? 0)",
          "(int)(\$r['store_owed'] ?? 0)"] as $needle) {
    if (strpos($fnSS, $needle) === false) {
        $badSS[] = '⛔ functions.php — «' . $needle . '» در عکسِ روزانه نیست';
    }
}

// ۹) migration در هر دو فهرستِ migrate.sh (همان قاعده‌ی بسته‌ی MIGRATIONS).
$migSS = (string)file_get_contents($root . '/deploy/migrate.sh');
if (!preg_match('/^\s*migration_store_share\.sql\s*$/m', $migSS)) {
    $badSS[] = 'migrate.sh — migration_store_share.sql در MIGRATIONS نیست';
}
if (!preg_match('/\[migration_store_share\.sql\]=/', $migSS)) {
    $badSS[] = 'migrate.sh — شاهدش در SENTINEL نیست';
}
if (!preg_match('/^\s*migration_store_owed\.sql\s*$/m', $migSS)) {
    $badSS[] = 'migrate.sh — migration_store_owed.sql در MIGRATIONS نیست';
}
if (!preg_match('/\[migration_store_owed\.sql\]=/', $migSS)) {
    $badSS[] = 'migrate.sh — شاهدِ migration_store_owed در SENTINEL نیست';
}

// ۱۰) پیوندِ سهامدار وارد نمی‌شود (اجازه است، نه دفترِ کاربر) و
//     رویدادهایش ممیزی می‌شوند.
$audSS = $stripComments($root . '/includes/audit.php');
foreach (['store_share.linked', 'store_share.unlinked'] as $act) {
    if (strpos($audSS, $act) === false) {
        $badSS[] = '⛔ Audit::ACTIONS — «' . $act . '» نیست';
    }
}

// ۱۱) صفحه‌ی کالاهای فروشگاه — دامنه در یک جا، و بی‌محاسبه.
//
//     ⛔ سه خرابیِ بی‌صدا اینجا ممکن است:
//       الف) دامنه در خودِ صفحه تصمیم گرفته شود → دکمه‌ی ورود و صفحه دو
//            جواب می‌دهند، و بدترین حالتش این است که کاربری دفترِ
//            سهامدارِ دیگری را ببیند.
//       ب) دکمه‌ی ورود شرطِ محلیِ خودش را داشته باشد → دکمه‌ای که زده
//            می‌شود و صفحه‌ی خالی می‌دهد («دکمه‌ی بی‌کار»).
//       ج) دکمه‌ی خرید/فروش به این صفحه اضافه شود → نسخه‌ی دومِ منطقِ
//            پول، همان مرزی که بینِ `my-assets.php` و `trades.php` هم
//            عمداً کشیده شده.
$saPath = $root . '/store-assets.php';
if (!is_file($saPath)) {
    $badSS[] = 'store-assets.php پیدا نشد';
} else {
    $saSrc = $stripComments($saPath);
    if (!preg_match('/StoreShare::assetOwners\(\s*\$userId\s*,\s*\$isAdmin\s*\)/', $saSrc)) {
        $badSS[] = '⛔ store-assets.php — دامنه از StoreShare::assetOwners() نمی‌آید';
    }
    // هیچ نوشتنی: نه فرمِ POST، نه اندپوینتِ اقدام.
    if (preg_match('/method=("|\x27)POST/i', $saSrc)) {
        $badSS[] = '⛔ store-assets.php — فرمِ نویسنده دارد؛ این صفحه فقط نمایش است';
    }
    foreach (['sell_trade', 'save_trade', 'sell_asset'] as $ep) {
        if (strpos($saSrc, $ep) !== false) {
            $badSS[] = '⛔ store-assets.php — دکمه‌ی «' . $ep . '» اضافه شده؛ منطقِ پول آنجا نیست';
        }
    }
    // صافی‌ها یک مرجع دارند (درسِ DUE_TABS).
    if (!preg_match('/isset\(STORE_ASSET_FILTERS\[\$filter\]\)/', $saSrc)) {
        $badSS[] = '⛔ store-assets.php — «?f=» با STORE_ASSET_FILTERS سنجیده نمی‌شود';
    }
    // ⛔ «مانده‌ی حساب» و «اصل سرمایه» از این کارت **پس گرفته شدند** و
    //    نباید بی‌صدا برگردند. هر دو از دفترِ کلِ فروشگاه می‌آیند و روی
    //    نصبِ واقعی با کالای شمرده‌شده‌ی همین کارت نمی‌خواندند؛ عددی که
    //    از روی خودِ همین صفحه قابل تأیید نیست، اینجا جای نشستن ندارد.
    //    (`StoreShare::valueFor()` عمداً دست‌نخورده ماند — آن خالص دارایی
    //    را می‌سازد و موضوعش این صفحه نیست.)
    foreach (['balance', 'capital'] as $gone) {
        if (preg_match('/\$own\[[\x27"]' . $gone . '[\x27"]\]/', $saSrc)) {
            $badSS[] = '⛔ store-assets.php — «' . $gone . '» دوباره روی کارت آمده؛ آن عدد پس گرفته شده بود';
        }
    }
    // ⛔ و «فروخته‌شده» هرگز `sold_total` نیست: آن `SUM(salePrice)` است و
    //    سهمِ خودِ فروشگاه را هم در خود دارد، پس روی کارتِ یک سهامدار
    //    عددی بزرگ‌تر از سهمِ او نشان می‌داد (۱۴۵ به‌جای ۱۱۵ + ۱۲).
    if (strpos($saSrc, 'sold_total') !== false) {
        $badSS[] = '⛔ store-assets.php — sold_total (قیمت فروش) روی کارتِ سهامدار آمده؛ اصلِ پول لازم است';
    }
    // اصلِ پول از جمعِ `cost` همان ردیف‌های فروخته می‌آید، نه از یک فیلدِ آماده.
    if (!preg_match('/\$soldCost\s*\+=\s*\(int\)\$r\[[\x27"]cost[\x27"]\]/', $saSrc)) {
        $badSS[] = '⛔ store-assets.php — اصلِ پولِ فروخته‌شده از جمعِ cost ردیف‌ها ساخته نمی‌شود';
    }
    // ⛔ و «نمی‌دانیم» با «صفر» یکی نیست: با فهرستِ بریده یا ردیف‌های ناقص
    //    جمع کمتر از واقعیت درمی‌آید، پس مبلغ اصلاً نشان داده نمی‌شود.
    if (!preg_match('/\$soldKnown\s*=\s*!\$own\[[\x27"]capped[\x27"]\]\s*&&\s*\$soldRows\s*===\s*\$own\[[\x27"]sold_count[\x27"]\]/', $saSrc)) {
        $badSS[] = '⛔ store-assets.php — نگهبانِ «جمعِ ردیف‌های فروخته کامل است» برداشته شده';
    }
    // ⛔ «در انبار» یعنی state === 'active'، نه «فروخته نیست». گوشیِ
    //    ثبت‌شده‌ی بی‌خرید، بایگانی و مرجوع فهرست را «۲۱ قلم» می‌کردند در
    //    حالی که active_countِ همان کارت ۲۰ می‌گفت (گزارشِ مالکِ نصب).
    if (!preg_match('/\$filter\s*===\s*[\x27"]open[\x27"]\s*&&\s*\$r\[[\x27"]state[\x27"]\]\s*!==\s*[\x27"]active[\x27"]/', $saSrc)) {
        $badSS[] = '⛔ store-assets.php — صافیِ «در انبار» از state === active نمی‌آید (گوشیِ خارج از انبار دوباره در فهرست می‌نشیند)';
    }
    // ⛔ کارتِ «مانده نقدی» از عددِ خودِ فروشگاه، با نگهبانِ «کلید نبود»:
    //    صفرِ ساختگی روی نصبِ عقب‌مانده دروغ بود.
    if (!preg_match('/\$own\[[\x27"]cash_held[\x27"]\]\s*!==\s*null/', $saSrc)
        || strpos($saSrc, 'مانده نقدی در فروشگاه') === false) {
        $badSS[] = '⛔ store-assets.php — کارتِ «مانده نقدی در فروشگاه» یا نگهبانِ null اش نیست';
    }
}
// ⛔ سیستمِ یکدست: «دارایی من در فروشگاه» همان holding است (انبار + لوازم
//    + مانده‌ی نقدی)، و `storeOwed()` با مبنای دفتر («مانده»ی خودِ مدیر)
//    کم می‌کند نه با holding — وگرنه اختلافِ دفتر و کالا بی‌صدا به «طلب
//    سایر سهامداران» منتقل می‌شد.
if (!preg_match('/array_key_exists\(\x27holding\x27,\s*\$sh\)/', $ssSrc)) {
    $badSS[] = '⛔ store_share — valueFor() از holding نمی‌خواند';
}
if (!preg_match('/function\s+storeOwed[\s\S]*?\$owed\s*-=\s*\(int\)round\(\(float\)\(\$sh\[[\x27"]balance[\x27"]\]/', $ssSrc)) {
    $badSS[] = '⛔ store_share — storeOwed() سهمِ مدیر را با «مانده»ی دفتر کم نمی‌کند';
}
if (!preg_match('/\$state\s*=\s*self::deviceState\(\$d\)/', $ssSrc)) {
    $badSS[] = '⛔ store_share — ownerBlock() جای دستگاه را از deviceState() نمی‌گیرد';
}
// و کارتِ صفحه‌ی دارایی: تعداد از active_count، نه count(devices) که
// فروخته و خارج از انبار را هم دارد؛ و «اصل سرمایه»ی دفتر برنگردد.
if (preg_match('/count\(\$storeDevices\)|count\(\$shDevices\)/', $maSrc)) {
    $badSS[] = '⛔ my-assets.php — شمارِ دستگاه از count(devices) است، نه active_count';
}
if (preg_match('/\$storeShare\[[\x27"]capital[\x27"]\]/', $maSrc)) {
    $badSS[] = '⛔ my-assets.php — «اصل سرمایه»ی دفتر دوباره روی کارت آمده';
}
// دکمه‌ی ورود به **همان** تابع بند است، نه یک شرطِ محلیِ دوم.
if (!preg_match('/StoreShare::canViewAssets\(\s*\$userId\s*,\s*Auth::isAdmin\(\)\s*\)/', $maSrc)) {
    $badSS[] = '⛔ my-assets.php — دکمه‌ی ورود از canViewAssets() نمی‌آید';
}
if (strpos($maSrc, 'store-assets.php') === false) {
    $badSS[] = '⛔ my-assets.php — لینکِ صفحه‌ی کالاهای فروشگاه نیست';
}
// ⛔ و کارتِ صفحه‌ی دارایی باید **کوچک** بماند: فهرستِ دستگاه‌ها جایش
//    صفحه‌ی خودش است، وگرنه نمودار و توگل‌ها زیرِ یک فهرستِ صدتایی دفن
//    می‌شوند — همان «خزشِ بی‌صدا»یی که کلِ این کار برای رفعش انجام شد.
// ⚠ الگو روی **رندرِ** نامِ کالاست، نه روی هر حلقه‌ای: شمردنِ
//   فروش‌رفته‌ها هم یک `foreach` روی همان آرایه است و بررسیِ حلقه‌ای
//   روی فایلِ سالم هشدارِ الکی می‌داد — که از نبودِ تست بدتر است.
if (preg_match('/\$d\[[\x27"]product[\x27"]\]/', $maSrc)) {
    $badSS[] = '⛔ my-assets.php — نامِ دستگاه‌ها دوباره درجا رندر می‌شود؛ جایش صفحه‌ی خودش است';
}
// ⛔ و دامنه فقط در همان یک تابع: هیچ‌کس دیگری نباید مستقیم
//    `shareholders` را از payload بخواند.
if (!preg_match('/function\s+assetOwners\s*\(/', $ssSrc)) {
    $badSS[] = '⛔ store_share — assetOwners() نیست';
} else {
    foreach ([$saSrc ?? '', $maSrc] as $consumer) {
        if (preg_match("/payload\(\)\s*\[\s*'shareholders'/", $consumer)) {
            $badSS[] = '⛔ مصرف‌کننده مستقیم payload()[shareholders] را می‌خواند، نه assetOwners()';
        }
    }
}

// ۱۲) خرید اشتراک از بخشِ مدیریت — و ترتیبِ نوار با شیتِ فوتر یکی باشد.
//
//     ⚠ خرابی‌اش بی‌صدا نیست ولی دیده هم نمی‌شود: مالکِ نصب عنوانِ
//       «اشتراک و پرداخت» را می‌بیند، وارد می‌شود، و فقط رسیدگی به
//       پرداختِ **دیگران** را پیدا می‌کند — درِ پرداختِ خودش هیچ‌جای
//       بخشِ مدیریت نبود.
$blSrc = $stripComments($root . '/admin/billing.php');
if (strpos($blSrc, '/pro.php') === false) {
    $badSS[] = '⛔ admin/billing.php — راهی به صفحه‌ی پرداخت (pro.php) ندارد';
}
$navSrc = $stripComments($root . '/admin/_nav.php');
$posCat  = strpos($navSrc, "'categories.php'");
$posBill = strpos($navSrc, "'billing.php'");
if ($posCat === false || $posBill === false || $posBill < $posCat) {
    $badSS[] = '⛔ admin/_nav.php — «اشتراک و پرداخت» باید زیرِ «دسته‌بندی‌ها» باشد';
}
$ftSrc = $stripComments($root . '/includes/footer.php');
$fCat  = strpos($ftSrc, 'admin/categories.php');
$fBill = strpos($ftSrc, 'admin/billing.php');
if ($fCat === false || $fBill === false || $fBill < $fCat) {
    $badSS[] = '⛔ includes/footer.php — ترتیبِ شیتِ مدیریت با نوارِ مدیر نمی‌خواند';
}

// ۱۳) تسویه‌ی نقدی — **انتقال** است، نه درآمد.
//
//     ⛔ بدترین خرابیِ این مسیر یک خطِ ساده است: ثبتِ پولِ تسویه به
//        شکلِ یک ردیفِ `transactions`. سهمِ سود از قبل درآمد ثبت شده،
//        پس گزارشِ درآمدِ همان ماه به اندازه‌ی **کلِ پرداخت** باد
//        می‌کند — و هیچ خطایی هم نمی‌دهد، چون هر دو ردیف جداگانه
//        درست‌اند. تنها جای نشستنِ این پول `store_settlements` است،
//        یعنی هفتمین منبعِ `walletBalances()`.
//
//     ⚠ و چرا قاعده‌ی شکل هم لازم است: تستِ رفتاری بدونِ migration
//       تسویه `T::blocked` می‌شود، پس روی ماشینِ عقب‌مانده فقط همین
//       می‌ماند — همان استدلالِ قاعده ۴۸ و ۵۱.
if ($ssSrc !== '') {
    // الف) هیچ‌جای مسیرِ تسویه ردیفِ تراکنش ساخته نمی‌شود.
    if (preg_match('/function\s+applySettlements\s*\(.*?\n    \}/s', $ssSrc, $mSet)) {
        if (stripos($mSet[0], 'INSERT INTO transactions') !== false) {
            $badSS[] = '⛔ store_share — تسویه ردیفِ transactions می‌سازد؛ درآمدِ ماه دو بار شمرده می‌شود';
        }
        // ب) `resolveWalletId()` تنها مسیرِ انتخابِ حساب است — وگرنه
        //    «حسابِ پیش‌فرض» (صفر) یعنی «هیچ‌جا» و پول گم می‌شود.
        if (!preg_match('/resolveWalletId\(\s*\$userId\s*,\s*\$walletId\s*\)/', $mSet[0])) {
            $badSS[] = '⛔ store_share — تسویه از resolveWalletId() رد نمی‌شود؛ پول در هیچ حسابی نمی‌نشیند';
        }
        // ج) نصبِ عقب‌مانده‌ی فروشگاه کلیدِ `settlements` را نمی‌دهد؛
        //    خواندنش به‌عنوانِ «فهرستِ خالی» کیف پول را بی‌صدا صفر
        //    می‌کند — همان «آینه‌ی سالم با پاسخِ خراب پاک نمی‌شود».
        if (!preg_match("/array_key_exists\(\s*[\x27\"]settlements[\x27\"]\s*,\s*\\\$sh\s*\)/", $mSet[0])) {
            $badSS[] = '⛔ store_share — نبودِ کلیدِ settlements از «خالی بودن» جدا نمی‌شود';
        }
        // د) هم‌گام است نه افزودن: سندِ کنسل‌شده باید از کیف پول هم برود.
        if (stripos($mSet[0], 'DELETE FROM store_settlements') === false) {
            $badSS[] = '⛔ store_share — سطرِ بی‌مرجعِ تسویه پاک نمی‌شود؛ پولِ پرداخت‌نشده می‌ماند';
        }
        // هـ) و مبلغ/حسابِ اصلاح‌شده باید بنشیند، نه ردیفِ دوم بسازد.
        if (stripos($mSet[0], 'ON DUPLICATE KEY UPDATE') === false
            || stripos($mSet[0], 'wallet_id = VALUES(wallet_id)') === false) {
            $badSS[] = '⛔ store_share — تسویه ON DUPLICATE KEY با wallet_id ندارد؛ عوض کردنِ حساب بی‌اثر می‌ماند';
        }
    } else {
        $badSS[] = '⛔ store_share — applySettlements() پیدا نشد';
    }

    // و) انتخابِ حساب فقط حسابِ **همان** کاربر را می‌پذیرد.
    if (preg_match('/function\s+setWallet\s*\(.*?\n    \}/s', $ssSrc, $mSw)) {
        if (!preg_match('/FROM\s+wallets\s+WHERE\s+id\s*=\s*:w\s+AND\s+user_id\s*=\s*:u/i', $mSw[0])) {
            $badSS[] = '⛔ store_share — setWallet() مالکیتِ حساب را نمی‌سنجد';
        }
    } else {
        $badSS[] = '⛔ store_share — setWallet() پیدا نشد';
    }
}

// ز) و هفتمین منبعِ پول واقعاً در `walletBalances()` هست.
//    ⚠ بدونِ آن، ردیفِ تسویه ثبت می‌شود و موجودی تکان نمی‌خورد — یعنی
//      قابلیت «کار می‌کند» ولی هیچ اثری ندارد.
// ⚠ الگو با مرزِ واژه است، نه `strpos`: جهشِ اول `store_settlements_x`
//   گذاشت و همه‌ی این بررسی‌ها **زنده ماندند**، چون نامِ اصلی زیررشته‌ی
//   نامِ خراب است. همان «بررسیِ پوچ» که این پروژه بارها گرفته.
$fnSrc = $stripComments($root . '/includes/functions.php');
if (preg_match('/function\s+walletBalances\s*\(.*?\n(?=function )/s', $fnSrc, $mWb)) {
    if (!preg_match('/FROM\s+store_settlements\b/', $mWb[0])) {
        $badSS[] = '⛔ walletBalances() — تسویه‌ی فروشگاه منبعِ پول نیست؛ موجودی تکان نمی‌خورد';
    }
} else {
    $badSS[] = 'walletBalances() پیدا نشد';
}

// ح) migration در هر دو فهرستِ `migrate.sh` ثبت شده باشد — وگرنه
//    اسکریپت متوقف می‌شود یا `--verify` دروغ می‌گوید.
//    ⛔ و شاهدش از نوعِ **داده** است، نه «جدول هست»: فایلی که وسطِ
//       ساختِ کلیدِ خارجی مرده باشد هم جدول را دارد.
$mgSrc = (string)@file_get_contents($root . '/deploy/migrate.sh');
if (!preg_match('/^\s*migration_store_settlement\.sql\s*$/m', $mgSrc)) {
    $badSS[] = '⛔ migrate.sh — migration_store_settlement.sql در MIGRATIONS نیست';
}
if (!preg_match('/\[migration_store_settlement\.sql\]="app_settings~setting_key=store_settlement_seeded"/', $mgSrc)) {
    $badSS[] = '⛔ migrate.sh — شاهدِ داده‌ایِ migration تسویه در SENTINEL نیست';
}

// ط) فهرست‌های بسته: آینه‌ی تسویه نه فعالیتِ کاربر است، نه واردشدنی.
//    ⛔ دومی از جنسِ **پول** است: یک فایلِ دست‌ساز با یک `amount`
//       دلخواه، موجودیِ حساب را از هوا بالا می‌برد.
if (!preg_match("/'store_settlements'\s*=>/", $stripComments($root . '/includes/admin_insights.php'))) {
    $badSS[] = '⛔ NON_ACTIVITY_TABLES — store_settlements نیست؛ قیفِ شروع باد می‌کند';
}
if (!preg_match("/'store_settlements'\s*,/", $stripComments($root . '/includes/user_import.php'))) {
    $badSS[] = '⛔ USER_IMPORT_SKIP — store_settlements نیست؛ فایلِ دست‌ساز پول می‌سازد';
}

T::bulk(13, $badSS, '⛔ سهم سهامدار: خواندنی، بی‌حساب، ایدمپوتنت، و پشتِ تأییدِ مدیر');

// ═══════════════════════════════════════════════════════════════
// قاعده ۴۷ — معرفیِ اولیه: «حالت رد شدن» باید واقعاً رد کند
// ═══════════════════════════════════════════════════════════════
//
// ⛔ چرا قاعده‌ی شکل هم لازم است: تستِ رفتاریِ `tests/test_intro.php`
//    نیمه‌ی اصلی‌اش را در **کرومیوم** می‌سنجد و کرومیوم ابزارِ
//    **اختیاری** است (`T::skip`) — یعنی روی ماشینِ بی‌مرورگر هیچ‌کدام
//    از بررسی‌های «هر راهِ خروج نشانه می‌زند» اجرا نمی‌شوند. همان
//    استدلالی که قاعده ۴۳ را کنارِ `test_number_align` نشاند.
//
// سه خرابیِ بی‌صدا، و هر سه فقط روی دستگاهِ کاربر دیده می‌شوند:
//  ۱. `done()` بدونِ `introMarkSeen()` → معرفی هر بار باز می‌شود و
//     دقیقاً همان مزاحمی می‌شود که «حالت رد شدن» برای نبودنش خواسته شد.
//  ۲. نشانه‌گذاری فقط روی اسلایدِ آخر → کسی که وسطش می‌بندد هرگز خلاص
//     نمی‌شود.
//  ۳. `catch` ای که `false` برمی‌گرداند → در پنجره‌ی ناشناس، هر
//     بارگذاری یک لایه.

T::group('قاعده ۴۷ — معرفیِ اولیه');

$badIntro = [];

// ⚠ `token_get_all()` روی `app.js` همه‌چیز را `T_INLINE_HTML` می‌بیند و
//   کامنت‌ها دست‌نخورده می‌مانند، پس اینجا با حذفِ کامنتِ C-مانند خوانده
//   می‌شود — وگرنه همین توضیحاتِ کنارِ کد، بررسی‌ها را روی فایلِ **سالم**
//   سبز نگه می‌داشتند (همان دامِ قاعده ۳۸).
$introJs = preg_replace('~/\*.*?\*/|//[^\n]*~s', '',
    (string)file_get_contents($root . '/assets/js/app.js')) ?? '';

// ۱) دو تابعِ نشانه بیرون از `DOMContentLoaded` باشند تا در node
//    آزمودنی بمانند — همان قاعده‌ی `parseBankSms()` و `kbNeedsKeyboard()`.
$domReady = strpos($introJs, "addEventListener('DOMContentLoaded'");
$posSeen  = strpos($introJs, 'window.introSeen = function');
$posMark  = strpos($introJs, 'window.introMarkSeen = function');
if ($posSeen === false || $posMark === false) {
    $badIntro[] = '⛔ app.js — introSeen/introMarkSeen تعریف نشده‌اند';
} elseif ($domReady !== false && ($posSeen > $domReady || $posMark > $domReady)) {
    $badIntro[] = '⛔ app.js — نشانه‌ی معرفی داخلِ DOMContentLoaded رفته؛ در node آزمودنی نیست';
}

// ۲) شکستِ `localStorage` به سمتِ «نشان نده» می‌رود.
if (preg_match('~window\.introSeen\s*=\s*function\s*\([^)]*\)\s*\{(.*?)\n    \};~s', $introJs, $mSeen)) {
    if (!preg_match('~catch\s*\([^)]*\)\s*\{\s*return\s+true;~', $mSeen[1])) {
        $badIntro[] = '⛔ introSeen() — شکستِ localStorage باید true بدهد (پنجره‌ی ناشناس: نشان نده)';
    }
} else {
    $badIntro[] = '⛔ introSeen() — بدنه‌اش خوانده نشد';
}

// ۳) `done()` نشانه می‌زند، و **همه‌ی** راه‌های خروج از همان رد می‌شوند.
// ⚠ بررسی‌های زیر روی **بلوکِ خودِ معرفی** انجام می‌شوند، نه کلِ
//   `app.js`: آن فایل ده‌ها `close.addEventListener('click'` دیگر دارد
//   (هر مودال یکی) و با جست‌وجوی سراسری، اولینشان پیدا می‌شد و بررسی
//   درباره‌ی چیزِ دیگری حرف می‌زد — روی فایلِ سالم هم قرمز شد و همان
//   نشان داد که پنجره باید بسته باشد.
$introBlock = '';
$bStart = strpos($introJs, '(function intro()');
if ($bStart === false) {
    $badIntro[] = '⛔ app.js — بلوکِ intro() پیدا نشد';
} else {
    $bEnd = strpos($introJs, "\n    })();", $bStart);
    $introBlock = substr($introJs, $bStart, $bEnd === false ? 4000 : $bEnd - $bStart);
}

if (preg_match('~function\s+done\s*\(\)\s*\{(.*?)\n        \}~s', $introBlock, $mDone)) {
    if (strpos($mDone[1], 'introMarkSeen()') === false) {
        $badIntro[] = '⛔ intro done() — نشانه را نمی‌زند؛ معرفی هر بار باز می‌شود';
    }
} else {
    $badIntro[] = '⛔ intro — تابعِ done() پیدا نشد';
}
// ⛔ صریح و **هر چهار راه جدا**، نه «یکی از آن‌ها»: با نشانه‌گذاریِ فقط
//    در پایانِ اسلایدها، کسی که وسطِ کار می‌بندد دفعه‌ی بعد دوباره همین
//    را می‌بیند.
//    ⚠ پنجره‌ی هر کدام از **خودِ همان ثبتِ شنونده** شروع می‌شود، نه
//      جست‌وجوی `done()` در کلِ فایل: با جست‌وجوی سراسری، برداشتنِ سه
//      راه از چهار راه هم سبز می‌ماند — یعنی بررسی پوچ بود.
foreach ([
    "close.addEventListener('click'" => 'دکمه‌ی «×»',
    "next.addEventListener('click'"  => 'دکمه‌ی پایانی',
    "box.addEventListener('click'"   => 'تپ روی خودِ لایه',
    "e.key === 'Escape'"             => 'Escape',
] as $anchor => $label) {
    $at = strpos($introBlock, $anchor);
    if ($at === false) {
        $badIntro[] = '⛔ intro — راهِ خروجِ «' . $label . '» اصلاً وجود ندارد';
        continue;
    }
    // ⛔ پنجره تا **ثبتِ شنونده‌ی بعدی** بریده می‌شود، نه یک تعدادِ ثابت
    //    کاراکتر: با پنجره‌ی ۲۲۰ کاراکتری، جهشِ «دکمه‌ی × را از done()
    //    جدا کن» **زنده ماند** چون `done()`ِ شنونده‌ی بعدی داخلِ همان
    //    پنجره می‌افتاد. همان درسِ «تا اولین `;`» در قاعده ۶.
    $stop = strpos($introBlock, '.addEventListener(', $at + strlen($anchor));
    $win  = $stop === false ? substr($introBlock, $at) : substr($introBlock, $at, $stop - $at);
    if (strpos($win, 'done') === false) {
        $badIntro[] = '⛔ intro — ' . $label . ' از done() رد نمی‌شود';
    }
}

// ۴) تنها مرجعِ اسلایدها همان آرایه‌ی PHP است؛ فهرستِ دومِ جاوااسکریپتی
//    یعنی نقطه‌ها و اسلایدها دیر یا زود از هم می‌افتند.
$introPhp = $stripComments($root . '/includes/intro_sheet.php');
if (strpos($introPhp, 'const INTRO_SLIDES') === false) {
    $badIntro[] = '⛔ intro_sheet.php — INTRO_SLIDES تنها مرجع نیست';
}
if (strpos($introJs, 'INTRO_SLIDES') !== false) {
    $badIntro[] = '⛔ app.js — فهرستِ دومِ اسلایدها در جاوااسکریپت ساخته شده';
}

// ۵) فقط برای دفترِ خالی. بدونِ این شرط، معرفی به کسی که شش ماه از اپ
//    استفاده کرده هم نشان داده می‌شود.
$idxSrc = $stripComments($root . '/index.php');
if (!preg_match('~if\s*\(\s*empty\(\$recentTransactions\)\s*\)\s*:\s*\?>\s*<\?php\s+include[^;]*intro_sheet\.php~', $idxSrc)) {
    $badIntro[] = '⛔ index.php — معرفی پشتِ شرطِ «دفترِ خالی» نیست';
}

// ۶) نورافکن: هدف از **خودِ آرایه‌ی PHP** می‌آید و از DOM خوانده می‌شود.
//    با یک فهرستِ دومِ جاوااسکریپتی، اولین باری که یک انتخابگر عوض شود
//    نورافکن بی‌صدا روی هیچ می‌نشیند و کاربر یک حفره‌ی خالی وسطِ صفحه
//    می‌بیند — همان قاعده‌ی `DUE_TABS`.
// ⚠ **هر** اسلاید باید کلیدِ `target` داشته باشد، نه «دست‌کم یکی»:
//   جهشِ «کلید را از یک اسلاید بردار» با `strpos` زنده ماند، چون بقیه
//   هنوز آن رشته را داشتند — یعنی بررسی پوچ بود. حالا شمارش با تعدادِ
//   خودِ اسلایدها (`'title'`) سنجیده می‌شود.
$nTitle  = substr_count($introPhp, "'title'");
$nTarget = substr_count($introPhp, "'target'");
if ($nTitle < 2 || $nTarget !== $nTitle) {
    $badIntro[] = '⛔ INTRO_SLIDES — هر اسلاید کلیدِ target ندارد ('
        . $nTarget . ' از ' . $nTitle . ')';
}
if (strpos($introPhp, 'data-target="') === false) {
    $badIntro[] = '⛔ intro_sheet.php — هدف روی خودِ اسلاید رندر نمی‌شود';
}
if (strpos($introBlock, "getAttribute('data-target')") === false) {
    $badIntro[] = '⛔ intro — هدف از DOM خوانده نمی‌شود؛ فهرستِ دوم در جاوااسکریپت';
}

// ۷) ⛔ فهرستِ نامزدها **گروه‌به‌گروه** آزموده می‌شود.
//    `querySelectorAll('a, b, c')` نتیجه را به **ترتیبِ سند** می‌دهد نه
//    به ترتیبِ انتخابگرها؛ روی موبایل نوارِ کناری و متنِ خالیِ صفحه هر
//    دو جلوترند، پس دکمه‌ی نوارِ پایین هیچ‌وقت برنده نمی‌شد و نورافکن
//    روی عنصرِ نامرئی می‌نشست. با اندازه‌گیری پیدا شد، نه با خواندنِ کد.
if (!preg_match('~sel\.split\(\s*[\'"],[\'"]\s*\)~', $introBlock)) {
    $badIntro[] = '⛔ intro — نامزدها گروه‌به‌گروه آزموده نمی‌شوند؛ ترتیبِ سند برنده می‌شود';
}

// ۸) ⛔ جای کارت و حفره با مقدارِ **فیزیکی** نوشته می‌شود.
//    `getBoundingClientRect()` فیزیکی است و مقدارِ منطقی در صفحه‌ی
//    راست‌به‌چپ **دقیقاً برعکس** می‌نشیند — همان چیزی که یک بار
//    `optionPicker()` را به لبه‌ی پنجره چسباند.
//    ⚠ هر دو نگارش ممنوع است: `inset-inline-start` در CSS و
//      `insetInlineStart` در جاوااسکریپت. با یکی از آن دو، جهشِ
//      camelCase **زنده ماند** — یعنی بررسی نصفه بود.
if (preg_match('~inset-inline-(start|end)|insetInline~', $introBlock)) {
    $badIntro[] = '⛔ intro — مقدارِ منطقی برای جای کارت؛ در RTL برعکس می‌نشیند';
}

// ۹) ⛔ استایلِ درون‌خطیِ اسلایدِ قبلی پیش از چیدنِ تازه پاک می‌شود.
//    بدونِ آن، `card.style.top`ِ اسلایدِ قبلی بر `.is-full` — که فقط یک
//    قاعده‌ی CSS است — برنده می‌شود و کارتِ تمام‌صفحه سرِ جای قبلی
//    می‌ماند؛ خرابی‌ای که هیچ خطایی نمی‌دهد.
if (!preg_match('~card\.style\.top\s*=\s*card\.style\.bottom\s*=~', $introBlock)) {
    $badIntro[] = '⛔ intro — استایلِ درون‌خطیِ اسلایدِ قبلی پاک نمی‌شود';
}

// ۱۰) ⛔ کارتِ تمام‌صفحه باید **خودش** حاشیه‌ی امن داشته باشد.
//     `.intro-overlay` عمداً `padding`ِ `.modal-overlay` را صفر می‌کند
//     (تمام‌صفحه یعنی تمام‌صفحه)، پس تکیه بر آن یعنی همان خرابی‌ای که
//     قاعده ۱۳ برای گرفتنش نوشته شد — و آن قاعده این انتخابگرِ تازه را
//     نمی‌بیند.
$cssIntro = (string)preg_replace('~/\*.*?\*/~s', '',
    (string)file_get_contents($root . '/assets/css/style.css'));
if (!preg_match('~\.intro-overlay\s*\{[^}]*padding:\s*0~', $cssIntro)) {
    $badIntro[] = '⛔ .intro-overlay — padding را صفر نمی‌کند؛ «صفحه را در بر بگیرد» نیست';
}
if (!preg_match('~\.intro-card\.is-full\s*\{[^}]*env\(safe-area-inset-~', $cssIntro)) {
    $badIntro[] = '⛔ .intro-card.is-full — حاشیه‌ی امن ندارد؛ متن زیرِ ناچ می‌رود';
}

// ۱۱) ⛔ هیچ متغیرِ **تعریف‌نشده‌ای** در این بلوک به کار نرود.
//     خرابی‌اش بی‌صداست و یک بار واقعاً زد: `outline: 2px solid
//     var(--accent)` با متغیرِ نبوده یعنی مرورگر **کلِ اعلان** را دور
//     می‌ریزد، پس حلقه‌ی دورِ نورافکن هرگز کشیده نمی‌شد — نه خطایی، نه
//     تستِ قرمزی؛ فقط در اسکرین‌شات دیده شد.
if (preg_match('~/\* ---- بلوکِ معرفی|\.intro-overlay~', $cssIntro)) {
    $iFrom = (int)strpos($cssIntro, '.intro-overlay');
    $iTo   = strpos($cssIntro, '.intro-card.is-full { left: 0');
    $cssBlock = substr($cssIntro, $iFrom, $iTo === false ? 4000 : ($iTo - $iFrom + 60));
    preg_match_all('~var\(\s*(--[a-z0-9-]+)~i', $cssBlock, $mVars);
    foreach (array_unique($mVars[1] ?? []) as $v) {
        if (!preg_match('~^\s*' . preg_quote($v, '~') . '\s*:~m', $cssIntro)) {
            $badIntro[] = '⛔ style.css — متغیرِ تعریف‌نشده‌ی ' . $v
                . ' در بلوکِ معرفی؛ مرورگر کلِ آن اعلان را دور می‌ریزد';
        }
    }
    // و حلقه‌ی نورافکن روی سایه‌ی تیره می‌نشیند، پس رنگش نباید توکنِ تم باشد.
    if (!preg_match('~\.intro-spot\.has-target\s*\{[^}]*outline:[^;]*rgba\(~', $cssBlock)) {
        $badIntro[] = '⛔ .intro-spot.has-target — حلقه رنگِ ثابت ندارد؛ در حالت شب ناپدید می‌شود';
    }
} else {
    $badIntro[] = '⛔ style.css — بلوکِ معرفی پیدا نشد';
}

T::bulk(21, $badIntro, '⛔ معرفیِ اولیه: هر راهِ خروج نشانه می‌زند و فقط دفترِ خالی می‌بیندش');

// ═══════════════════════════════════════════════════════════════
// قاعده ۴۸ — مدیریت کاربران در مقیاس
// ═══════════════════════════════════════════════════════════════
//
// **گزارشِ مالکِ نصب:** «مدیریت کاربران خیلی بزرگ هست… بهینه کن بدون
// کم کردن قابلیتها.»
//
// صفحه همه‌ی ردیف‌های `users` را یک‌جا رندر می‌کرد. اندازه‌گیری شد با
// هزار کاربر: **۴٬۳۴۱٬۳۷۴ بایت** HTML و ۱۹٫۲ میلی‌ثانیه رندر؛ بعد از
// `LIMIT`: **۱۵۶٬۵۰۹ بایت** و ۷٫۵ میلی‌ثانیه.
//
// ⛔ چرا قاعده‌ی شکل هم لازم است: با برداشتنِ `LIMIT` صفحه **درست کار
//    می‌کند** و فقط دوباره چهار مگابایت می‌شود. تستِ رفتاری
//    (`tests/test_admin_users.php`) این را می‌گیرد ولی به دیتابیس
//    نیاز دارد (`T::blocked`)؛ روی ماشینی که دیتابیس ندارد فقط همین
//    قاعده می‌ماند.

T::group('قاعده ۴۸ — مدیریت کاربران در مقیاس');

$badUsers = [];
$usersSrc = $stripComments($root . '/admin/users.php');

// ۱) فهرستِ صافی‌ها تنها مرجع باشد — هم منو از آن رندر شود هم `$_GET`
//    با آن سنجیده شود. با فهرستِ دوم، گزینه‌ای که مدیر می‌بیند هنگام
//    اعمال بی‌صدا به پیش‌فرض برمی‌گردد (درسِ `DUE_TABS`).
foreach (['USER_ROLE_FILTERS', 'USER_STATE_FILTERS', 'USER_SORTS'] as $c) {
    if (!preg_match('~const\s+' . $c . '\s*=~', $usersSrc)) {
        $badUsers[] = "⛔ admin/users.php — ثابتِ {$c} تعریف نشده";
        continue;
    }
    if (strpos($usersSrc, 'isset(' . $c . '[') === false) {
        $badUsers[] = "⛔ admin/users.php — ورودی با {$c} سنجیده نمی‌شود";
    }
    if (strpos($usersSrc, 'foreach (' . $c . ' as') === false) {
        $badUsers[] = "⛔ admin/users.php — منو از {$c} رندر نمی‌شود (فهرستِ دوم)";
    }
}

// ۲) خودِ `LIMIT` — قلبِ این کار.
if (!preg_match('~\$limitSql\s*=\s*\$pg\[\x27all\x27\]\s*\?\s*\x27\x27\s*:\s*\x27 LIMIT ~', $usersSrc)) {
    $badUsers[] = '⛔ admin/users.php — فهرست دیگر با LIMIT بریده نمی‌شود (چهار مگابایت HTML برمی‌گردد)';
}
if (strpos($usersSrc, 'pagedWindow(') === false || strpos($usersSrc, 'pagedNav(') === false) {
    $badUsers[] = '⛔ admin/users.php — از `includes/paged_list.php` رد نمی‌شود';
}

// ۳) فرارِ وایلدکارت. بدونش تایپِ `%` کلِ فهرست را برمی‌گرداند و مدیر
//    نمی‌فهمد چرا — همان کاری که `includes/tx_query.php` هم می‌کند.
// ⚠ الگو هر دو شکل را می‌پذیرد: رشته‌ی PHP این را با بک‌اسلش می‌نویسد
//   (`ESCAPE \'!\'`) و توکنایزر متنِ خامِ همان را برمی‌گرداند. با
//   جست‌وجوی سرراستِ `ESCAPE '!'` تست روی فایلِ **سالم** قرمز می‌شد.
if (preg_match_all('~ESCAPE\s+\\\\?\x27!\\\\?\x27~', $usersSrc) < 2) {
    $badUsers[] = '⛔ admin/users.php — جست‌وجو `ESCAPE` با کاراکترِ فرار ندارد';
}
if (!preg_match('~str_replace\(\s*\[\x27!\x27,\s*\x27%\x27,\s*\x27_\x27\]~', $usersSrc)) {
    $badUsers[] = '⛔ admin/users.php — `%`/`_`/`!` پیش از LIKE فرار داده نمی‌شوند';
}

// ۴) ریدایرکتِ بعد از عملیات، نمای جاری را نگه دارد. با `'users.php'`
//    خام، مدیر از صفحه‌ی ۱۲ به صفحه‌ی ۱ پرت می‌شود — خرابی‌ای بی‌خطا.
if (strpos($usersSrc, "redirectWithMessage('users.php'") !== false) {
    $badUsers[] = '⛔ admin/users.php — ریدایرکتِ خام برگشت؛ صافی و صفحه از دست می‌رود';
}
if (!preg_match('~\$backTo\s*=~', $usersSrc) || substr_count($usersSrc, 'redirectWithMessage($backTo') < 10) {
    $badUsers[] = '⛔ admin/users.php — همه‌ی مسیرها از `$backTo` رد نمی‌شوند';
}

// ۵) ⛔ «بدون کم کردن قابلیت‌ها» — خواسته‌ی صریح. هر شش ورودیِ عملیات
//    باید سرِ جایش بماند؛ بهینه‌سازی‌ای که یک دکمه را ببرد، خواسته را
//    برآورده نکرده.
foreach ([
    "value=\"create\""        => 'ساخت کاربر',
    "js-edit-user"            => 'ویرایش',
    "value=\"toggle_status\"" => 'فعال/غیرفعال',
    "value=\"revoke_access\"" => 'خروج از دستگاه‌ها',
    "value=\"unlock_login\""  => 'باز کردن قفل',
    "value=\"delete\""        => 'حذف',
] as $needle => $what) {
    if (strpos($usersSrc, $needle) === false) {
        $badUsers[] = "⛔ admin/users.php — عملیاتِ «{$what}» از صفحه حذف شده";
    }
}

// ۶) و خودِ `pagedWindow()` نباید ردیف بخواند — اگر روزی کوئری داخلش
//    برود، همان «نسخه‌ی دوم» می‌شود که مرزِ بینِ برشِ PHP و `LIMIT` را
//    بی‌معنا می‌کند.
$pagedSrc = $stripComments($root . '/includes/paged_list.php');
if (strpos($pagedSrc, 'function pagedWindow(') === false) {
    $badUsers[] = '⛔ includes/paged_list.php — `pagedWindow()` نیست';
} elseif (preg_match('~function pagedWindow\(.*?\n\}~s', $pagedSrc, $mw)
          && preg_match('~Database::|->query\(|->prepare\(~', $mw[0])) {
    $badUsers[] = '⛔ pagedWindow() به دیتابیس دست می‌زند — باید فقط حساب کند';
}

T::bulk(18, $badUsers, '⛔ مدیریت کاربران: صافیِ یک‌مرجعی، برشِ SQL، و هیچ عملیاتِ کم‌نشده');

// ═══════════════════════════════════════════════════════════════
// قاعده ۴۹ — مرکز راهنما و تیکت
// ═══════════════════════════════════════════════════════════════
//
// **خواسته‌ی مالکِ نصب:** «پشتیبانی نباید به شکل چت مستقیم و آزاد با
// ادمین باشد… این بخش را به چت بی‌نهایت تبدیل نکن.»
//
// ⛔ چرا قاعده‌ی شکل هم لازم است: تستِ رفتاری
//    (`tests/test_support.php`) نیمی‌اش با HTTP و نشستِ واقعی کار
//    می‌کند و به دیتابیس نیاز دارد (`T::blocked`) — روی ماشینی که
//    دیتابیس ندارد فقط همین قاعده می‌ماند. و سه تا از خرابی‌های این
//    بخش **بی‌صدا** هستند: نشتیِ تیکتِ کاربرِ دیگر، از کار افتادنِ
//    نشانِ «پاسخ تازه»، و باز شدنِ دروازه‌ی «هنوز مشکل دارم».

T::group('قاعده ۴۹ — مرکز راهنما و تیکت');

$badSup   = [];
$supSrc   = $stripComments($root . '/includes/support.php');
$supPage  = $stripComments($root . '/support.php');
$supAdmin = $stripComments($root . '/admin/support.php');

// ۱) `ticketFor()` تنها مسیرِ خواندنِ یک تیکت است و **همیشه** با
//    `user_id` دامنه می‌گیرد. با یک `SELECT … FROM support_tickets
//    WHERE id = …` در صفحه، اولین مسیری که یادش برود تیکتِ کاربرِ
//    دیگری را نشان می‌دهد — همان قاعده‌ی «جداسازی کاربران».
if (strpos($supSrc, 'AND t.user_id = :u') === false) {
    $badSup[] = '⛔ Support::ticketFor() — دامنه‌ی user_id ندارد';
}
foreach (['support.php' => $supPage, 'admin/support.php' => $supAdmin] as $name => $src) {
    if (preg_match('~FROM\s+support_tickets~i', $src)) {
        $badSup[] = "⛔ {$name} — کوئریِ مستقیم روی support_tickets (باید از Support:: رد شود)";
    }
}

// ۲) `addMessage()` تنها جایی است که `last_sender`/`user_unread`/
//    `status` نوشته می‌شوند، و هر سه **با هم** در یک تراکنش. اگر یکی
//    جا بماند خرابی بی‌صداست: مدیر جواب می‌دهد و نشانِ «پاسخ تازه»
//    هرگز روشن نمی‌شود، یا تیکتِ بسته با پاسخِ تازه بسته می‌ماند.
if (!preg_match('~function addMessage\(.*?\n    \}~s', $supSrc, $mAdd)) {
    $badSup[] = '⛔ Support::addMessage() پیدا نشد';
} else {
    foreach (["last_sender = 'admin'", 'user_unread = 1', "status = 'answered'",
              "last_sender = 'user'", 'closed_at = NULL', 'last_activity_at = NOW()'] as $frag) {
        if (strpos($mAdd[0], $frag) === false) {
            $badSup[] = "⛔ addMessage() — «{$frag}» را نمی‌نویسد";
        }
    }
    if (strpos($mAdd[0], 'beginTransaction') === false || strpos($mAdd[0], 'commit') === false) {
        $badSup[] = '⛔ addMessage() — درجِ پیام و به‌روزرسانیِ تیکت زیرِ یک تراکنش نیستند';
    }
}
// و هیچ فایلِ دیگری این ستون‌ها را ننویسد.
foreach (glob($root . '/{,admin/,store/,api/,includes/}*.php', GLOB_BRACE) as $p) {
    if (realpath($p) === realpath($root . '/includes/support.php')) { continue; }
    $s = $stripComments($p);
    if (preg_match('~UPDATE\s+support_tickets\s+SET[^;]*(last_sender|user_unread)~i', $s)) {
        $badSup[] = '⛔ ' . basename($p) . ' — خودش last_sender/user_unread می‌نویسد (نسخه‌ی دوم)';
    }
}

// ۳) دروازه‌ی «هنوز مشکل دارم» یک **گامِ واقعی در آدرس** است: فرمِ ثبت
//    فقط در `?v=new` رندر می‌شود. اگر داخلِ `?v=ask` هم بیاید، کلِ
//    خواسته («اول مقاله، بعد تیکت») بی‌صدا از بین می‌رود و هیچ خطایی
//    هم نمی‌دهد.
if (!preg_match("~elseif\s*\(\s*\\\$view\s*===\s*'new'\s*\)~", $supPage)) {
    $badSup[] = '⛔ support.php — نمای «new» جدا از «ask» نیست';
}
if (preg_match("~\\\$view\s*===\s*'ask'.*?\\\$view\s*===\s*'new'~s", $supPage, $mAsk)
    && strpos($mAsk[0], 'name="subject"') !== false) {
    $badSup[] = '⛔ support.php — فرمِ ثبت داخلِ نمای «ask» رندر می‌شود (دروازه بی‌اثر)';
}

// ۴) سقفِ پیامِ کاربر — «چت بی‌نهایت نشود». تنها مرجعش همان ثابت است و
//    صفحه باید با همان بسنجد، نه یک عددِ سخت‌کد.
if (!preg_match('~const\s+MAX_USER_MESSAGES\s*=~', $supSrc)) {
    $badSup[] = '⛔ Support::MAX_USER_MESSAGES تعریف نشده';
}
if (substr_count($supPage, 'Support::MAX_USER_MESSAGES') < 2) {
    $badSup[] = '⛔ support.php — سقفِ پیام هم در مسیرِ POST و هم در رندر اعمال نمی‌شود';
}
if (!preg_match('~const\s+MAX_OPEN_TICKETS\s*=~', $supSrc)) {
    $badSup[] = '⛔ Support::MAX_OPEN_TICKETS تعریف نشده';
}

// ۵) فهرست‌های بسته تنها مرجع‌اند و ورودی با همان‌ها سنجیده می‌شود
//    (درسِ `DUE_TABS`): صافیِ ناشناخته باید به پیش‌فرض برگردد، نه
//    فهرستِ خالی بدهد.
foreach (['CATEGORIES', 'STATUSES', 'PRIORITIES', 'ADMIN_FILTERS'] as $c) {
    if (!preg_match('~const\s+' . $c . '\s*=~', $supSrc)) {
        $badSup[] = "⛔ Support::{$c} تعریف نشده";
    }
}
// `ADMIN_FILTERS` هم منو را می‌سازد هم `$_GET` را می‌سنجد، و
// `adminFilterSql()` برای صافیِ ناشناخته شاخه‌ی `default` دارد —
// وگرنه یک آدرسِ دست‌کاری‌شده فهرستِ خالی می‌داد و مدیر فکر می‌کرد
// تیکتی نیست.
if (strpos($supAdmin, 'isset(Support::ADMIN_FILTERS[') === false) {
    $badSup[] = '⛔ admin/support.php — ورودیِ صافی با ADMIN_FILTERS سنجیده نمی‌شود';
}
if (strpos($supAdmin, 'foreach (Support::ADMIN_FILTERS as') === false) {
    $badSup[] = '⛔ admin/support.php — منوی صافی از ADMIN_FILTERS رندر نمی‌شود (فهرستِ دوم)';
}
if (!preg_match('~function adminFilterSql\(.*?\n    \}~s', $supSrc, $mFilt)) {
    $badSup[] = '⛔ Support::adminFilterSql() پیدا نشد';
} elseif (strpos($mFilt[0], 'default:') === false
          || strpos($mFilt[0], 'self::STATUSES[$filter]') === false) {
    $badSup[] = '⛔ adminFilterSql() — صافیِ ناشناخته fallback ندارد یا با STATUSES سنجیده نمی‌شود';
} elseif (substr_count($mFilt[0], "t.last_sender = 'user' AND t.status <> 'closed'") < 2) {
    // ⚠ «`default:` وجود دارد» کافی نیست — جهش نشان داد که می‌شود شاخه‌ی
    //   پیش‌فرض را نگه داشت و مقدارش را `1 = 0` کرد: آن‌وقت یک آدرسِ
    //   دست‌کاری‌شده فهرستِ **خالی** می‌دهد و مدیر فکر می‌کند تیکتی
    //   نیست. پس همان SQLِ «منتظر پاسخ من» باید دو بار بیاید.
    $badSup[] = '⛔ adminFilterSql() — fallback همان نمای «منتظر پاسخ من» را برنمی‌گرداند';
}
foreach (['setStatus' => 'STATUSES', 'setPriority' => 'PRIORITIES'] as $fn => $list) {
    if (preg_match('~function ' . $fn . '\(.*?\n    \}~s', $supSrc, $mFn)
        && strpos($mFn[0], 'self::' . $list) === false) {
        $badSup[] = "⛔ Support::{$fn}() مقدار را با {$list} نمی‌سنجد";
    }
}

// ۶) هیچ اندپوینتِ **نویسنده‌ی** تازه‌ای در `api/` ساخته نشد: همه‌ی
//    نوشتن‌ها فرمِ POST با CSRF و بعد ریدایرکت‌اند (الگوی
//    `admin/errors.php`). پس بدونِ جاوااسکریپت هم کار می‌کند و
//    تازه‌سازیِ صفحه پیامِ تکراری نمی‌سازد.
foreach (glob($root . '/api/*support*.php') as $p) {
    if (basename($p) !== 'view_support_file.php') {
        $badSup[] = '⛔ api/' . basename($p) . ' — اندپوینتِ تازه‌ی پشتیبانی (باید POST→redirect باشد)';
    }
}
foreach (['support.php' => $supPage, 'admin/support.php' => $supAdmin] as $name => $src) {
    if (strpos($src, 'Csrf::verifyOrFail') === false) {
        $badSup[] = "⛔ {$name} — CSRF ندارد";
    }
    if (strpos($src, "header('Location: ") === false) {
        $badSup[] = "⛔ {$name} — بعد از POST ریدایرکت نمی‌کند";
    }
}
/*
 * ⚠ این دو بررسی **جایگزین** شدند، نه برداشته: تا دیروز
 *   `requireAdmin()` را می‌خواستند، ولی با آمدنِ نقشِ «پشتیبان» همان
 *   شرط دقیقاً چیزی را می‌بست که این نقش برایش ساخته شده. نگهبان سرِ
 *   جایش است و فقط **نامش** عوض شد — `requireCap('support')` که مدیر
 *   هم از آن رد می‌شود. قاعده‌ای که موضوعش عوض شده باید جایگزین شود،
 *   نه خالی بماند (درسِ قاعده ۳۲).
 */
if (strpos($supAdmin, "Auth::requireCap('support')") === false) {
    $badSup[] = '⛔ admin/support.php — نگهبانِ دسترسی ندارد';
}
if (strpos($stripComments($root . '/admin/support-content.php'), "Auth::requireCap('support')") === false) {
    $badSup[] = '⛔ admin/support-content.php — نگهبانِ دسترسی ندارد';
}

// ۷) تحویلِ پیوست فقط از راهی که مالکیت را می‌سنجد. بدونِ آن شرط، هر
//    کاربری با حدسِ شناسه فایلِ دیگری را می‌گرفت.
// ⚠ شرطِ معافیت از `isAdmin()` به `can('support')` رفت (قاعده ۵۱)؛
//   نکته‌ی این بررسی همان `user_id = :u` است که برای بقیه می‌ماند.
$supFile = $stripComments($root . '/api/view_support_file.php');
if (strpos($supFile, "Auth::can('support')") === false || strpos($supFile, 'user_id = :u') === false) {
    $badSup[] = '⛔ api/view_support_file.php — مالکیتِ پیوست سنجیده نمی‌شود';
}

// ۸) نامِ پاسخِ آماده را مدیر می‌نویسد و داخلِ `<script>` می‌نشیند —
//    `JSON_HEX_TAG` اجباری است، همان درسِ قاعده ۳۸.
if (preg_match('~window\.SUPPORT_CANNED\s*=\s*<\?=\s*json_encode\((.*?)\)\s*\?>~s', $supAdmin, $mJ)) {
    if (strpos($mJ[1], 'JSON_HEX_TAG') === false) {
        $badSup[] = '⛔ admin/support.php — SUPPORT_CANNED بدونِ JSON_HEX_TAG';
    }
} else {
    $badSup[] = '⛔ admin/support.php — window.SUPPORT_CANNED رندر نمی‌شود';
}

// ۹) هر سه جدول باید در فهرست‌های بسته ثبت شده باشند، وگرنه دو خرابیِ
//    بی‌صدا: فایلِ دست‌سازِ بازگرداندن می‌توانست پاسخِ جعلیِ مدیر
//    بسازد، و «آمار استفاده» گفت‌وگو را رکوردِ دفتر می‌شمرد.
$impSrc  = $stripComments($root . '/includes/user_import.php');
$statSrc = $stripComments($root . '/includes/admin_insights.php');
foreach (['support_tickets', 'support_messages', 'support_attachments'] as $t) {
    if (strpos($impSrc, "'{$t}'") === false) {
        $badSup[] = "⛔ {$t} در USER_IMPORT_SKIP نیست";
    }
    if (strpos($statSrc, "'{$t}'") === false) {
        $badSup[] = "⛔ {$t} در NON_ACTIVITY_TABLES نیست";
    }
}

// ۱۰) migration در **هر دو** فهرستِ `migrate.sh` ثبت شده باشد — بدونِ
//     شاهد، `--verify` همان دروغی را می‌گوید که برای گرفتنش ساخته شده.
$mig = file_get_contents($root . '/deploy/migrate.sh');
if (strpos($mig, 'migration_support.sql') === false) {
    $badSup[] = '⛔ migration_support.sql در MIGRATIONS ثبت نشده';
}
if (strpos($mig, '[migration_support.sql]=') === false) {
    $badSup[] = '⛔ migration_support.sql در SENTINEL شاهد ندارد';
}

// ۱۱) نشانِ «پاسخ تازه» از `Notify` می‌آید، نه یک کوئریِ تازه روی هر
//     صفحه: شمارشِ زنگِ سرآیند از قبل یک کوئری دارد و این قابلیت
//     هیچ هزینه‌ای به آن اضافه نکرد.
if (strpos($supSrc, 'Notify::push(') === false) {
    $badSup[] = '⛔ Support::notifyReply() از Notify::push استفاده نمی‌کند';
}
if (!preg_match("~'support:msg:'~", $supSrc)) {
    $badSup[] = '⛔ اعلانِ پاسخ dedup_key ندارد (فهرستِ اعلان پر از تکراری می‌شود)';
}

T::bulk(22, $badSup, '⛔ پشتیبانی: یک مرجع، دامنه‌ی کاربر، دروازه‌ی مقاله، و سقفِ گفت‌وگو');

// ═══════════════════════════════════════════════════════════════
// قاعده ۵۰ — قالبِ مشترک متغیرِ صفحه را بازنویسی نکند
// ═══════════════════════════════════════════════════════════════
//
// **خرابیِ واقعی، و مالکِ نصب از روی اسکرین‌شات گزارشش کرد:** بالای
// صفحه‌ی پشتیبانی یک **نوارِ سبزِ خالی** دیده می‌شد.
//
// علتش این بود که `includes/header.php` خطِ `$flash = getFlash();` را
// در **دامنه‌ی سراسریِ خودِ صفحه** اجرا می‌کند. `support.php` پیشتر
// `$flash = ''` گذاشته و بعد از `?done=created` پیامِ «درخواست شما ثبت
// شد» را در همان می‌نوشت؛ ولی `include header.php` **بعد از آن** و
// **پیش از رندر** است، پس مقدار به `null` تبدیل می‌شد. و چون شرطِ
// رندر `$flash !== ''` بود و `null !== ''` **درست** است، هر بارگذاری
// یک `<div class="sup-flash sup-flash-ok"></div>`ِ خالی می‌ساخت.
//
// ⛔ دو خرابی در یک خط، و دومی بدتر: نوارِ خالی دیده می‌شد (زشت، ولی
//    بی‌ضرر)، ولی **پیامِ تأییدِ ثبتِ تیکت هرگز نمایش داده نمی‌شد** —
//    کاربر درخواست می‌فرستاد و هیچ نشانه‌ای نمی‌گرفت که ثبت شده.
//    با A/B سنجیده شد: با نامِ `$flash` پیام **دیده نمی‌شد**، با
//    `$__flash` دیده می‌شود.
//
// ⛔ قاعده: هر متغیری که یک قالبِ مشترک در دامنه‌ی سراسری مقدار
//    می‌دهد باید با `__` شروع شود. استثناء فقط **ورودی‌های
//    مستندشده** است — نام‌هایی که *صفحه* پیش از include می‌گذارد و
//    قالب فقط می‌خواندشان.
//
// ⛔ **فهرستِ قالب‌ها از خودِ کد کشف می‌شود، نه از یک آرایه‌ی دستی** —
//    همان قاعده‌ی `categoryRefTables()` و `userDataTables()`. با
//    فهرستِ دستی، انداختنِ یک نام از آن، بررسی را **بی‌صدا** حذف
//    می‌کرد و تست سبز می‌ماند؛ همان جهش را اجرا کردیم و **زنده ماند**،
//    و همین قاعده را از فهرستِ دستی به کشف برد.
//
// ⚠ معیارِ «مشترک» **دسترسیِ گذرا از صفحه‌هاست**، نه تعدادِ
//   include‌کننده‌ی مستقیم: `sidebar.php` فقط یک include‌کننده دارد
//   (`header.php`) ولی روی **هر ۳۲ صفحه** اجرا می‌شود. و به همین
//   دلیل `includes/due_tab_*.php` بیرون می‌مانند: آن‌ها تکه‌های خودِ
//   `due.php` هستند و `$userId`/`$today` را عمداً با همان یک صفحه
//   شریک‌اند — اجبارِ `__` آنجا یک **هشدارِ الکی** بود.

T::group('قاعده ۵۰ — قالبِ مشترک متغیرِ صفحه را بازنویسی نکند');

/** نام‌هایی که در دامنه‌ی سراسریِ یک فایل مقدار می‌گیرند (بیرونِ هر تابع/کلاس). */
$globalAssigns = function (string $file): array {
    $t = token_get_all((string)file_get_contents($file));
    $n = count($t); $names = [];
    for ($i = 0; $i < $n; $i++) {
        $tk = $t[$i];
        // بدنه‌ی تابع/کلاس کاملاً رد می‌شود — متغیرِ محلی نشتی ندارد.
        if (is_array($tk) && in_array($tk[0], [T_FUNCTION, T_CLASS, T_TRAIT, T_INTERFACE], true)) {
            $d = 0;
            for ($j = $i + 1; $j < $n; $j++) {
                if ($t[$j] === '{') { $d++; }
                elseif ($t[$j] === '}') { $d--; if ($d === 0) { $i = $j; break; } }
                elseif ($t[$j] === ';' && $d === 0) { $i = $j; break; }
            }
            continue;
        }
        if (is_array($tk) && $tk[0] === T_VARIABLE) {
            for ($j = $i + 1; $j < $n; $j++) {
                if (is_array($t[$j]) && in_array($t[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { continue; }
                if ($t[$j] === '=') { $names[ltrim($tk[1], '$')] = true; }
                elseif (is_array($t[$j]) && in_array($t[$j][0],
                    [T_PLUS_EQUAL, T_CONCAT_EQUAL, T_MINUS_EQUAL, T_COALESCE_EQUAL], true)) {
                    $names[ltrim($tk[1], '$')] = true;
                }
                break;
            }
        }
    }
    foreach (['_SESSION', '_SERVER', '_GET', '_POST', '_FILES', '_COOKIE', 'this'] as $sup) { unset($names[$sup]); }
    return array_keys($names);
};

/* ⛔ ورودی‌های مستندشده — تنها نام‌هایی که صفحه و قالب عمداً شریک‌اند.
   `$pageTitle`/`$pageWide` را صفحه پیش از `header.php` می‌گذارد، و
   `$incomeCategories`/`$expenseCategories` را صفحه‌ای که از قبل
   دسته‌ها را خوانده به `add_tx_sheet`/`edit_tx_modal` می‌دهد (هر دو
   با `isset()` می‌سنجند و اگر نبود خودشان می‌سازند). */
$tplInputs = ['pageTitle', 'pageWide', 'pageDesk', 'incomeCategories', 'expenseCategories'];

/* کشفِ گرافِ include: هر فایل → فایل‌هایی که `include` می‌کند. */
$includesOf = function (string $file): array {
    $out = [];
    if (!is_file($file)) { return $out; }
    $src = (string)file_get_contents($file);
    if (preg_match_all("~\binclude(?:_once)?\s+__DIR__\s*\.\s*'([^']+)'~", $src, $m)) {
        foreach ($m[1] as $rel) {
            $t = realpath(dirname($file) . $rel);
            if ($t !== false) { $out[] = $t; }
        }
    }
    return $out;
};

/* صفحه‌ها = هر `*.php` در ریشه و `admin/`. از هر کدام گرافِ include را
   می‌پیماییم و می‌شماریم هر partial از چند **صفحه** قابل دسترس است. */
$reach = [];
foreach (array_merge(glob($root . '/*.php'), glob($root . '/admin/*.php'), glob($root . '/store/*.php')) as $page) {
    $seen = [];
    $stack = $includesOf($page);
    while ($stack) {
        $t = array_pop($stack);
        if (isset($seen[$t])) { continue; }
        $seen[$t] = true;
        foreach ($includesOf($t) as $next) { $stack[] = $next; }
    }
    foreach (array_keys($seen) as $t) { $reach[$t] = ($reach[$t] ?? 0) + 1; }
}
$sharedTpl = [];
foreach ($reach as $path => $n) {
    if ($n >= 2) { $sharedTpl[] = str_replace($root . '/', '', $path); }
}
sort($sharedTpl);

$badTpl = [];

/* ⚠ کشفی که چیزی پیدا نکند، بررسی را بی‌صدا خاموش می‌کند — همان
   «سنجشی که روی خرابی سبز می‌شود». پس خودِ کشف هم سنجیده می‌شود. */
foreach (['includes/header.php', 'includes/sidebar.php', 'includes/footer.php', 'includes/add_tx_sheet.php'] as $must) {
    if (!in_array($must, $sharedTpl, true)) {
        $badTpl[] = "⛔ کشفِ قالب‌های مشترک «{$must}» را پیدا نکرد — خودِ بررسی خراب است";
    }
}

foreach ($sharedTpl as $rel) {
    $path = $root . '/' . $rel;
    foreach ($globalAssigns($path) as $v) {
        if (in_array($v, $tplInputs, true)) { continue; }
        if (strncmp($v, '__', 2) !== 0) {
            $badTpl[] = "⛔ {$rel} متغیرِ سراسریِ \${$v} می‌سازد — با \$__{$v} عوضش کنید"
                . ' (متغیرِ هم‌نامِ صفحه بی‌صدا بازنویسی می‌شود)';
        }
    }
}

/* ⛔ و نیمه‌ی دوم: خودِ `support.php` هم باید از `$flash` فاصله گرفته
   باشد؟ نه — با قاعده‌ی بالا نامِ صفحه دیگر برخورد نمی‌کند و قاعده
   نباید چیزی را ببندد که خراب نیست (هشدارِ الکی از نبودِ تست بدتر
   است). ولی این یکی را صریح می‌سنجیم، چون خرابی‌اش دیده شد: شرطِ
   رندرِ نوارِ پیام باید با رشته بسنجد، نه با truthiness. */
$supPageRaw = $stripComments($root . '/support.php');
if (strpos($supPageRaw, "\$flash !== ''") === false) {
    $badTpl[] = '⛔ support.php شرطِ رندرِ نوارِ پیام را با مقایسه‌ی رشته‌ای نمی‌سنجد';
}

T::bulk(count($sharedTpl) + 5, $badTpl,
    '⛔ قالبِ مشترک فقط متغیرِ `__`دار می‌سازد (نوارِ سبزِ خالی از همین‌جا آمد)');

// -----------------------------------------------------------------
// ⛔ قاعده ۵۱ — نقشِ محدود محدود بماند
//
// **خواسته‌ی مالکِ نصب:** «امکان اضافه کردن ادمین با دسترسی محدود مثلاً
// پشتیبانی» و «یک گزینه همکار هم می‌خوام».
//
// ⛔ خطرِ کلِ این کار یک چیز است: باز کردنِ `Auth::isAdmin()` برای
//    «پشتیبان». آن تابع در بیش از بیست جا خوانده می‌شود (کاربران،
//    اشتراک، دسته‌بندی، آمار، سهامداران، پیوستِ هر کاربر، جزئیاتِ
//    `health.php`) و ساده‌ترین راهِ باز کردنِ صفحه‌ی پشتیبانی بود —
//    ولی **بی‌صدا** همه‌ی آن‌ها را هم باز می‌کرد.
//
// ⚠ تستِ رفتاری (`tests/test_roles.php`) این را با سه نشستِ واقعی
//   می‌سنجد، ولی به دیتابیس نیاز دارد (`T::blocked`)؛ روی ماشینی که
//   دیتابیس ندارد فقط همین قاعده می‌ماند — همان استدلالی که قاعده ۴۸
//   را کنارِ `test_admin_users` و قاعده ۴۳ را کنارِ `test_number_align`
//   نشاند.

T::group('قاعده ۵۱ — نقشِ محدود (پشتیبان) و نقشِ برچسبی (همکار)');

$badRole = [];
$authSrc = $stripComments($root . '/includes/auth.php');

// ۱) `isAdmin()` عمداً فقط `admin` است — و این مهم‌ترین بررسیِ قاعده.
if (!preg_match('~function\s+isAdmin\(\)[^{]*\{\s*return\s+self::isLoggedIn\(\)\s*&&\s*\(\$_SESSION\[\x27role\x27\]\s*\?\?\s*\x27\x27\)\s*===\s*\x27admin\x27;~', $authSrc)) {
    $badRole[] = '⛔ `Auth::isAdmin()` دیگر فقط `admin` نیست — بیست‌ویک گیتِ دیگر بی‌صدا باز می‌شود';
}

// ۲) فهرستِ بسته‌ی توانایی‌ها، و `can()` که پیش‌فرضش **بسته** است.
if (!preg_match('~const\s+CAPS\s*=\s*\[\x27admin\x27,\s*\x27support\x27\]~', $authSrc)) {
    $badRole[] = '⛔ `Auth::CAPS` فهرستِ بسته‌ی دو تاییِ خودش نیست';
}
if (strpos($authSrc, 'in_array($cap, self::CAPS, true)') === false) {
    $badRole[] = '⛔ `can()` تواناییِ بیرونِ `CAPS` را رد نمی‌کند (نامِ اشتباه‌تایپ‌شده صفحه را باز می‌کند)';
}

// ۳) `hasAnyCap()` روی `CAPS` حلقه می‌زند، نه یک `||` دستی — وگرنه
//    تواناییِ سومِ فردا در یکی از دو منو جا می‌ماند.
if (strpos($authSrc, 'foreach (self::CAPS as $cap)') === false) {
    $badRole[] = '⛔ `hasAnyCap()` از روی `CAPS` حلقه نمی‌زند (فهرستِ دوم)';
}

// ۴) صفحه‌های پشتیبانی از `requireCap('support')` می‌روند، نه
//    `requireAdmin()` — وگرنه نقش هیچ کاری نمی‌تواند بکند.
foreach (['admin/support.php', 'admin/support-content.php'] as $rel) {
    if (!is_file($root . '/' . $rel)) {
        $badRole[] = "⛔ {$rel} روی دیسک نیست — بررسی بی‌صدا ناپدید می‌شود";
        continue;
    }
    $s = $stripComments($root . '/' . $rel);
    if (strpos($s, "Auth::requireCap('support')") === false) {
        $badRole[] = "⛔ {$rel} از `requireCap('support')` رد نمی‌شود";
    }
    if (strpos($s, 'Auth::requireAdmin()') !== false) {
        $badRole[] = "⛔ {$rel} هنوز `requireAdmin()` دارد — نقشِ پشتیبان بی‌مصرف می‌شود";
    }
}

// ۵) و برعکس: هر صفحه‌ی **دیگرِ** `admin/` باید `requireAdmin()` بماند.
//    فهرست از خودِ پوشه کشف می‌شود، نه دستی — صفحه‌ی فردا خودبه‌خود
//    پوشش می‌گیرد (همان قاعده‌ی `EXPECT`).
$adminPages = glob($root . '/admin/*.php') ?: [];
if (count($adminPages) < 5) {
    $badRole[] = '⛔ کشفِ صفحه‌های admin/ چیزی برنگرداند — خودِ بررسی خراب است';
}
foreach ($adminPages as $p) {
    $base = basename($p);
    if ($base === '_nav.php' || $base === 'support.php' || $base === 'support-content.php') { continue; }
    $s = $stripComments($p);
    if (strpos($s, 'Auth::requireAdmin()') === false) {
        $badRole[] = "⛔ admin/{$base} دیگر `requireAdmin()` ندارد";
    }
    if (preg_match('~Auth::requireCap\(\x27support\x27\)~', $s)) {
        $badRole[] = "⛔ admin/{$base} با تواناییِ پشتیبانی باز شده — دسترسیِ محدود دیگر محدود نیست";
    }
}

// ۶) تحویلِ پیوستِ تیکت. با `isAdmin()` آنجا، پشتیبان تیکت را باز
//    می‌کرد ولی پیوستش ۴۰۴ می‌گرفت — کارِ اصلی‌اش انجام‌نشدنی می‌شد.
$vsf = $stripComments($root . '/api/view_support_file.php');
if (strpos($vsf, "Auth::can('support')") === false || strpos($vsf, 'Auth::isAdmin()') !== false) {
    $badRole[] = '⛔ api/view_support_file.php باید از `can(\x27support\x27)` برود، نه `isAdmin()`';
}

// ۷) ⛔ هیچ فهرستِ دستیِ نقش نماند. با `['admin','user']` محلی، نقشِ
//    انتخاب‌شده **بی‌صدا** به `user` برمی‌گردد و پیامِ سبز هم می‌آید.
foreach (['includes/signup.php', 'admin/users.php', 'api/update_profile.php', 'setup.php'] as $rel) {
    if (!is_file($root . '/' . $rel)) { continue; }
    $s = $stripComments($root . '/' . $rel);
    if (preg_match('~in_array\(\s*\$role\s*,\s*\[\x27admin\x27~', $s)) {
        $badRole[] = "⛔ {$rel} فهرستِ دستیِ نقش دارد — باید از `Auth::ROLES` برود";
    }
}

// ۸) صافیِ نقش در `admin/users.php` از `Auth::ROLES` ساخته شود.
$uSrc = $stripComments($root . '/admin/users.php');
if (strpos($uSrc, "+ Auth::ROLES") === false) {
    $badRole[] = '⛔ `USER_ROLE_FILTERS` از `Auth::ROLES` ساخته نمی‌شود — نقشِ تازه صافی نمی‌گیرد';
}

// ۹) نگهبانِ «آخرین مدیر» هر نقشِ غیرِ admin را بگیرد، نه فقط `user`.
//    با `=== 'user'` تنها، «مدیر → همکار» پنل را برای همیشه می‌بست.
if (substr_count($uSrc, "\$targetUser['role'] === 'admin' && \$role !== 'admin'") < 2) {
    $badRole[] = '⛔ admin/users.php — نگهبانِ «آخرین مدیر» فقط `user` را می‌گیرد';
}

// ۱۰) نشانِ نقش در سرآیند از `roleLabel()` بیاید، وگرنه «پشتیبان» و
//     «همکار» هر دو «کاربر» دیده می‌شوند و نقش هیچ‌جا پیدا نیست.
$hdrSrc = $stripComments($root . '/includes/header.php');
if (strpos($hdrSrc, 'Auth::roleLabel(Auth::role())') === false) {
    $badRole[] = '⛔ نشانِ نقش در header.php از `roleLabel()` نمی‌آید';
}

// ۱۱) هر دو منوی مدیریت از `hasAnyCap()` گیت شوند — یکی‌شان که جا
//     بماند، کاربر روی گوشی و روی دسکتاپ دو چیزِ متفاوت می‌بیند.
foreach (['includes/sidebar.php', 'includes/footer.php'] as $rel) {
    $s = $stripComments($root . '/' . $rel);
    if (strpos($s, 'Auth::hasAnyCap()') === false) {
        $badRole[] = "⛔ {$rel} سرتیترِ مدیریت را با `hasAnyCap()` گیت نمی‌کند";
    }
    if (strpos($s, "Auth::can('support')") === false) {
        $badRole[] = "⛔ {$rel} قلمِ پشتیبانی را برای نقشِ «پشتیبان» رندر نمی‌کند";
    }
}

// ۱۱-ب) درِ «مدیریت» (دکمه و زیرشیت) فقط برای مدیر — پشتیبان کارتِ
//       مستقیمِ «پاسخ به تیکت‌ها» را دارد. **گزارشِ مالکِ نصب:** پشتیبان
//       یک «مدیریت» با فقط یک قلم پشتش می‌دید.
$ftSrc = $stripComments($root . '/includes/footer.php');
foreach (['<button type="button" class="tool-card js-open-admin"', 'id="adminSheet"'] as $needle) {
    $at = strpos($ftSrc, $needle);
    $gate = $at === false ? false : strrpos(substr($ftSrc, 0, $at), '<?php if (');
    $gateLine = $gate === false ? '' : substr($ftSrc, $gate, 40);
    if ($at === false || strpos($gateLine, "Auth::can('admin')") === false) {
        $badRole[] = "⛔ includes/footer.php — «{$needle}» پشتِ `Auth::can('admin')` نیست";
    }
}

// ۱۲) نوارِ `admin/_nav.php` هر بخش را با تواناییِ خودش گیت کند، و
//     پیش‌فرضِ بخشِ تازه **بسته** بماند.
$navSrc = $stripComments($root . '/admin/_nav.php');
if (strpos($navSrc, 'Auth::can($__cap)') === false) {
    $badRole[] = '⛔ admin/_nav.php هر بخش را با تواناییِ خودش گیت نمی‌کند';
}
if (!preg_match("~\x27support\.php\x27\s*=>\s*\[\x27پشتیبانی\x27,\s*\x27support\x27\]~u", $navSrc)) {
    $badRole[] = '⛔ admin/_nav.php — بخشِ پشتیبانی تواناییِ `support` ندارد';
}
if (preg_match_all("~=>\s*\[\x27[^\x27]+\x27,\s*\x27support\x27\]~u", $navSrc) > 1) {
    $badRole[] = '⛔ admin/_nav.php — بیش از یک بخش با تواناییِ `support` باز شده';
}

// ۱۳) migration در هر دو فهرستِ `migrate.sh` ثبت شده باشد — بدونِ
//     شاهد، `--verify` همان دروغی را می‌گوید که برای گرفتنش ساخته شده.
// ⚠ الگو به **خطِ کامل** بسته است، نه `strpos`: با جست‌وجوی متنی،
//   کامنت کردنِ همان خط (`# migration_user_roles.sql`) بررسی را سبز
//   نگه می‌داشت — جهش نشانش داد، نه بازبینی.
$migSh = (string)@file_get_contents($root . '/deploy/migrate.sh');
if (!preg_match('~^\s*migration_user_roles\.sql\s*$~m', $migSh)) {
    $badRole[] = '⛔ migration_user_roles.sql در آرایه‌ی MIGRATIONS نیست';
}
if (strpos($migSh, '[migration_user_roles.sql]="users.role>=20"') === false) {
    $badRole[] = '⛔ شاهدِ migration_user_roles در SENTINEL نیست (یا روی طولِ ستون نیست)';
}

T::bulk(15 + count($adminPages), $badRole,
    '⛔ نقشِ «پشتیبان» فقط بخشِ پشتیبانی را باز می‌کند و «همکار» فقط یک برچسب است');

// ═══════════════════════════════════════════════════════════════
// ⛔ قاعده ۵۲ — کارت‌های تاشوی کاربران
// ═══════════════════════════════════════════════════════════════
//
// **گزارشِ مالکِ نصب:** «هر شخص رو کارتشو تاشو کن و فقط اسم پیدا باشه تا
// تعداد زیادی کارت کنار هم دیده بشن، پیش‌فرض همه تاشو. اگه کارتی نیاز
// به رفع مشکلی داره مثل رفع مشکل ورود روش نوتیف بخوره که متمایز بشه.»
//
// ⛔ چرا قاعده‌ی شکل لازم است: هر سه خرابیِ این کار **بی‌صدا**ست —
//    صفحه در هر حالت باز می‌شود و فقط خاصیتش را از دست می‌دهد:
//    ۱) یک `open` روی `<details>` و همه‌ی کارت‌ها از روزِ اول بازند؛
//    ۲) یک ردیفِ داده که به `<summary>` سُر بخورد و «فقط اسم» تمام است؛
//    ۳) نشانِ هشدار که به بدنه برود و کارتِ قفل‌شده زیرِ تاخوردگی گم شود.
//    تستِ رفتاری (`tests/test_admin_users.php`) دو تای اول را می‌گیرد
//    ولی به دیتابیس نیاز دارد (`T::blocked`)؛ روی ماشینِ بی‌دیتابیس فقط
//    همین قاعده می‌ماند — همان استدلالی که قاعده ۴۸ را ساخت.

T::group('قاعده ۵۲ — کارت‌های تاشوی کاربران');

$badFold  = [];
$foldSrc  = $stripComments($root . '/admin/users.php');
$foldCss  = preg_replace('#/\*.*?\*/#s', '', (string)@file_get_contents($root . '/assets/css/style.css'));

// ۱) عنصرِ بومی، نه کلیدِ جاوااسکریپتی. با `hidden` + یک شنونده، نرسیدنِ
//    `app.js` همه‌ی کارت‌ها را بسته نگه می‌دارد و مدیر به هیچ دکمه‌ای
//    نمی‌رسد — همان «دکمه‌ی بی‌کار» و همان درسِ «همه در یک فهرست».
if (!preg_match('~<details\s+class="ucard~', $foldSrc)) {
    $badFold[] = '⛔ admin/users.php — کارتِ تاشو با `<details class="ucard">` نیست';
}

// ۲) ⛔ «پیش‌فرض همه تاشو» — خواسته‌ی صریح. `open` یعنی همان فهرستِ
//    بلندِ قبلی، فقط با مرزِ گردتر.
if (preg_match('~<details[^>]*\sopen~', $foldSrc)) {
    $badFold[] = '⛔ admin/users.php — `<details>` با `open` رندر می‌شود؛ کارت‌ها تاشو نیستند';
}

// ۳) ⛔ «فقط اسم پیدا باشه» — هیچ ردیفِ داده‌ای داخلِ `<summary>` نیاید.
if (preg_match('~<summary\b.*?</summary>~s', $foldSrc, $mSum)) {
    foreach (['ucard-row', 'ucard-k', 'ucard-actions', 'status-badge'] as $leak) {
        if (strpos($mSum[0], $leak) !== false) {
            $badFold[] = "⛔ admin/users.php — «{$leak}» داخلِ <summary> است؛ کارتِ بسته دیگر فقط نام نیست";
        }
    }
    // ۴) ⛔ و نشان **باید** همان‌جا باشد، وگرنه کارتی که کار می‌خواهد
    //    زیرِ تاخوردگی گم می‌شود و کلِ نیمه‌ی دومِ خواسته از بین می‌رود.
    // ⚠ با `class="` می‌گردد نه فقط `lock-chip`: جهشِ اولِ همین بررسی
    //   نامِ کلاس را به `xlock-chip` عوض کرد و رشته‌ی خام هنوز پیدا
    //   می‌شد — یعنی بررسی **پوچ** بود.
    if (strpos($mSum[0], 'class="lock-chip') === false) {
        $badFold[] = '⛔ admin/users.php — نشانِ هشدار در <summary> نیست؛ کارتِ قفل‌شده از بیرون دیده نمی‌شود';
    }
} else {
    $badFold[] = '⛔ admin/users.php — هیچ <summary> ای پیدا نشد — قاعده ۵۲ کور شده';
}

// ۵) آستانه‌ی قفل از `LoginThrottle` بیاید نه یک عددِ سخت‌کد: با عددِ
//    دستی، عوض شدنِ سقف نشانِ کارت را بی‌صدا دروغ‌گو می‌کند (درسِ
//    `ACTIVE_DAYS` که در سه جا سخت‌کد بود).
// ⚠ بررسی به **بلوکِ خودِ تصمیم** بسته است، نه کلِ فایل: همان ثابت دو
//   جای دیگرِ این صفحه هم هست (شمارشِ `$lockedUsers` و صافیِ `locked`)،
//   پس جست‌وجوی سراسری **پوچ** بود و جهشِ «عدد را سخت‌کد کن» را زنده
//   می‌گذاشت. جهشِ زنده‌مانده یعنی تست ناقص است، نه اینکه کد امن است.
if (preg_match('~\$flags\s*=\s*\[\];(.*?)\?>~s', $foldSrc, $mFlags)) {
    if (strpos($mFlags[1], 'LoginThrottle::MAX_PER_USER') === false) {
        $badFold[] = '⛔ admin/users.php — آستانه‌ی «قفل شده» سخت‌کد شده، نه از LoginThrottle::MAX_PER_USER';
    }
} else {
    $badFold[] = '⛔ admin/users.php — بلوکِ ساختِ `$flags` پیدا نشد — قاعده ۵۲ کور شده';
}

// ۶) CSS — سه چیزی که نبودشان هیچ خطایی نمی‌دهد و فقط دیده می‌شود.
$ucards = $ruleBody($foldCss, '.ucards');
if ($ucards === null) {
    $badFold[] = '⛔ قاعده‌ی .ucards پیدا نشد — قاعده ۵۲ کور شده';
} else {
    if (!preg_match('~grid-template-columns~', $ucards)) {
        $badFold[] = '⛔ .ucards توری نیست؛ «تعدادِ زیادی کارت کنارِ هم» برآورده نمی‌شود';
    }
    // باز کردنِ یک کارت نباید هم‌ردیف‌هایش را هم به همان قد بکشد.
    if (!preg_match('~align-items\s*:\s*start~', $ucards)) {
        $badFold[] = '⛔ .ucards — `align-items: start` ندارد؛ کارتِ باز هم‌ردیف‌هایش را کش می‌دهد';
    }
}
$uhead = $ruleBody($foldCss, '.ucard-head');
if ($uhead === null) {
    $badFold[] = '⛔ قاعده‌ی .ucard-head پیدا نشد — قاعده ۵۲ کور شده';
} elseif (!preg_match('~list-style\s*:\s*none~', $uhead)) {
    $badFold[] = '⛔ .ucard-head — مثلثِ پیش‌فرضِ مرورگر برداشته نشده';
}
// و جایش یک نشانگرِ خودمان هست: `<summary>`ِ بی‌نشانه اصلاً شبیهِ چیزی
// که بشود بازش کرد دیده نمی‌شود.
if ($ruleBody($foldCss, '.ucard-head::after') === null) {
    $badFold[] = '⛔ .ucard-head::after نیست؛ کارتِ بسته هیچ نشانه‌ای از باز شدن ندارد';
}
// ۷) و کارتِ نشان‌دار از دور هم فرق داشته باشد.
if ($ruleBody($foldCss, '.ucard.is-flagged') === null) {
    $badFold[] = '⛔ .ucard.is-flagged نیست؛ کارتِ نیازمندِ رسیدگی از دور متمایز نمی‌شود';
}

T::bulk(11, $badFold, '⛔ کارت‌های کاربران تاشو، بسته‌به‌پیش‌فرض، و نشان‌دار می‌مانند');

// -------------------------------------------------------------------
// ⛔ قاعده ۵۳ — بخش معاملات پیش‌فرض روشن، و فقط یک بار
//
// دو خرابیِ کاملاً بی‌صدا را می‌بندد:
//  الف) نگهبانِ نشانه از migration برداشته شود → **هر** deploy کلیدی را
//       که کاربر عمداً خاموش کرده دوباره روشن می‌کند، بی‌هیچ خطایی.
//  ب) کسی `trades_enabled` را در `INSERT`ِ `createUserAccount()`
//       بنویسد → پیش‌فرض دو مرجع پیدا می‌کند و عوض کردنِ یکی، آن یکی را
//       بی‌صدا عقب می‌گذارد (همان درسِ `ACTIVE_DAYS`).
//
// تستِ رفتاری‌اش (`tests/test_trades.php`) به دیتابیس نیاز دارد و روی
// ماشینِ بی‌دیتابیس `T::blocked` می‌شود — پس این قاعده تنها چیزی است
// که آنجا می‌ماند (همان استدلالِ قاعده ۴۸ و ۵۱).
// -------------------------------------------------------------------
T::group('قاعده ۵۳ — پیش‌فرضِ روشنِ معاملات');

$badTd  = [];
$tdFile = $root . '/migration_trades_default.sql';
$tdSrc  = is_file($tdFile) ? (string)file_get_contents($tdFile) : '';
$mgSrc  = (string)file_get_contents($root . '/deploy/migrate.sh');

if ($tdSrc === '') {
    $badTd[] = '⛔ migration_trades_default.sql نیست — قاعده ۵۳ کور شده';
} else {
    // ۱) پیش‌فرضِ ستون برای کاربرِ تازه
    if (!preg_match('~ALTER\s+TABLE\s+`users`\s+ALTER\s+COLUMN\s+`trades_enabled`\s+SET\s+DEFAULT\s+1~i', $tdSrc)) {
        $badTd[] = '⛔ migration_trades_default — پیش‌فرضِ ستون روی ۱ گذاشته نمی‌شود';
    }
    // ۲) نگهبانِ نشانه: خودِ `UPDATE` باید پشتِ «نشانه نیست» باشد.
    //    ⚠ بررسی روی **همان بلوکِ IF** است نه کلِ فایل: نامِ نشانه در
    //    `INSERT` پایانی هم هست، پس جست‌وجوی سراسری پوچ می‌بود و جهشِ
    //    «نگهبان را بردار» را زنده می‌گذاشت.
    $guarded = false;
    if (preg_match_all('~SET\s+@sql\s*=\s*IF\((.*?)\);~s', $tdSrc, $mIf)) {
        foreach ($mIf[1] as $body) {
            if (!preg_match('~UPDATE\s+`users`\s+SET\s+`trades_enabled`~i', $body)) { continue; }
            if (preg_match('~@already_on\s*=\s*0~', $body)) { $guarded = true; }
        }
    }
    if (!$guarded) {
        $badTd[] = '⛔ migration_trades_default — روشن کردنِ کاربرانِ موجود بی‌قید است؛ هر deploy کارِ کاربر را پس می‌زند';
    }
    // ۳) و آن نشانه واقعاً از `app_settings` خوانده و بعد نوشته شود.
    if (!preg_match("~`setting_key`\s*=\s*'trades_default_on'~", $tdSrc)) {
        $badTd[] = '⛔ migration_trades_default — نشانه‌ی trades_default_on خوانده نمی‌شود';
    }
    if (!preg_match("~INSERT\s+INTO\s+`app_settings`.*?'trades_default_on'~s", $tdSrc)) {
        $badTd[] = '⛔ migration_trades_default — نشانه بعد از اجرا نوشته نمی‌شود';
    }
}

// ۴) ثبت در هر دو فهرستِ migrate.sh، و **بعد از** فایلی که ستون را
//    می‌سازد: با ترتیبِ برعکس، `ALTER` روی ستونی می‌افتد که هنوز نیست.
$posCol = strpos($mgSrc, "\n    migration_trades.sql\n");
$posDef = strpos($mgSrc, "\n    migration_trades_default.sql\n");
if ($posDef === false) {
    $badTd[] = '⛔ migration_trades_default.sql در آرایه‌ی MIGRATIONS نیست';
} elseif ($posCol === false || $posDef < $posCol) {
    $badTd[] = '⛔ migration_trades_default.sql پیش از migration_trades.sql آمده';
}
if (!str_contains($mgSrc, '[migration_trades_default.sql]=')) {
    $badTd[] = '⛔ migration_trades_default.sql شاهدی در SENTINEL ندارد';
}

// ۵) پیش‌فرض فقط یک مرجع دارد: خودِ ستون.
$signupNo = $stripComments($root . '/includes/signup.php');
if (str_contains($signupNo, 'trades_enabled')) {
    $badTd[] = '⛔ includes/signup.php — پیش‌فرضِ معاملات مرجعِ دومی پیدا کرده؛ فقط پیش‌فرضِ ستون';
}

// ۶) و خواندنش از همان ستون است، نه یک مقدارِ سخت‌کد.
$fnSrc = $stripComments($root . '/includes/functions.php');
if (preg_match('~function\s+tradesEnabled\s*\(.*?(?=\nfunction\s)~s', $fnSrc, $mTe)) {
    if (!preg_match('~SELECT\s+trades_enabled\s+FROM\s+users~i', $mTe[0])) {
        $badTd[] = '⛔ tradesEnabled() ستون را نمی‌خواند؛ پیش‌فرض سخت‌کد شده';
    }
} else {
    $badTd[] = '⛔ tradesEnabled() پیدا نشد — قاعده ۵۳ کور شده';
}

T::bulk(8, $badTd, '⛔ معاملات پیش‌فرض روشن است و انتخابِ کاربر فقط یک بار بازنویسی می‌شود');

// ---------------------------------------------------------------------------
// ⛔ قاعده ۵۴ — هر json_encode داخلِ <script> باید JSON_HEX_TAG داشته باشد
//
// قاعده ۳۸ همین را برای شبکه‌ی دسته‌بندی نوشته بود، ولی فقط برای **یک**
// فایل. بقیه‌ی ۲۳ فراخوانی از زیرش رد شده بودند — و در ۶ تای آن‌ها
// محتوایی می‌نشست که **خودِ کاربر** نوشته است: نامِ دسته
// (`category-report`, `recurring`, `data`)، نامِ بانک (`cheques`)، نامِ
// حساب و نوعِ دارایی (`data`, `my-assets`)، و بدتر از همه ردیف‌های
// **فایلِ CSVِ آپلودشده** (`data.php` → `window.IMPORT_ROWS`).
//
// ⛔ و خرابی‌اش آن چیزی نیست که به نظر می‌رسد: `json_encode` اسلش را
//    خودش فرار می‌دهد (`<\/script>`)، پس بستنِ مستقیمِ تگ ممکن نیست.
//    ولی `<!--<script>` **فرار داده نمی‌شود** و توکنایزرِ HTML را به
//    حالتِ «script data double escaped» می‌برد؛ آن‌وقت `</script>`ِ
//    واقعی دیگر تگ را نمی‌بندد و **بقیه‌ی صفحه بلعیده می‌شود**.
//    در کرومیوم اندازه‌گیری شد، نه استدلال: با آن مقدار،
//    `<div id="after">` اصلاً عنصر نبود و داخلِ متنِ اسکریپت می‌نشست.
//    روی `data.php` یعنی فوتر — و با آن `app.js` — هرگز اجرا نمی‌شود:
//    صفحه کامل بالا می‌آید و هیچ دکمه‌ای کار نمی‌کند، همان
//    «صفحه‌ی بی‌جان»ِ بخشِ PWA، این بار بدونِ هیچ راهِ تشخیصی.
//
// ⛔ شرط **بی‌قید** است، نه «هر جا محتوای کاربر هست»: قضاوتِ «این فیلد
//    را کاربر می‌نویسد یا نه» همان چیزی است که با اولین تغییرِ فیلد
//    کهنه می‌شود. و هزینه‌اش صفر است — روی آرایه‌ی عدد و تاریخ و رنگِ
//    هگز خروجی **بایت‌به‌بایت** همان است، چون هیچ `<`/`>`/`&` ای ندارد.
//
// ⚠ فهرستِ فراخوانی‌ها **کشف** می‌شود نه دستی (قاعده‌ی
//   `categoryRefTables()`)، و کامنت‌ها پیش از بررسی حذف می‌شوند —
//   وگرنه یک `/* JSON_HEX_TAG */` کنارِ فراخوانی، بررسی را پوچ می‌کرد
//   (همان دامی که قاعده ۱۹ و ۳۵ و ۳۸ هم در آن افتادند).
T::group('قاعده ۵۴ — JSON_HEX_TAG در هر <script>');

$badHex = [];
$hexFiles = array_merge(
    glob(__DIR__ . '/../*.php') ?: [],
    glob(__DIR__ . '/../admin/*.php') ?: [],
    glob(__DIR__ . '/../store/*.php') ?: [],
    glob(__DIR__ . '/../includes/*.php') ?: []
);
if (count($hexFiles) < 40) {
    $badHex[] = '⛔ فهرستِ فایل‌ها خالی/ناقص است — قاعده ۵۴ بی‌صدا کور شده';
}

$hexSites = 0;
foreach ($hexFiles as $hf) {
    $hTok = token_get_all(file_get_contents($hf));
    $hn = count($hTok);
    $inScript = false;
    for ($i = 0; $i < $hn; $i++) {
        $t = $hTok[$i];
        if (is_array($t) && $t[0] === T_INLINE_HTML) {
            $txt = $t[1]; $off = 0;
            while (true) {
                $o = $inScript ? stripos($txt, '</script', $off) : stripos($txt, '<script', $off);
                if ($o === false) { break; }
                $inScript = !$inScript;
                $off = $o + 7;
            }
            continue;
        }
        if (!$inScript) { continue; }
        if (!(is_array($t) && $t[0] === T_STRING && strcasecmp($t[1], 'json_encode') === 0)) { continue; }

        $depth = 0; $started = false; $buf = '';
        for ($j = $i + 1; $j < $hn; $j++) {
            $tk = $hTok[$j];
            $s  = is_array($tk) ? $tk[1] : $tk;
            if ($s === '(') { $depth++; $started = true; }
            elseif ($s === ')') { $depth--; }
            // کامنت‌ها شمرده نمی‌شوند، وگرنه بررسی پوچ می‌شود
            if (!(is_array($tk) && ($tk[0] === T_COMMENT || $tk[0] === T_DOC_COMMENT))) { $buf .= $s; }
            if ($started && $depth === 0) { break; }
        }
        $hexSites++;
        if (strpos($buf, 'JSON_HEX_TAG') === false) {
            $badHex[] = '⛔ ' . basename(dirname($hf)) . '/' . basename($hf) . ' خط ' . $t[2]
                . ' — json_encode داخلِ <script> بدونِ JSON_HEX_TAG';
        }
    }
}
// ⛔ اگر ردیابیِ <script> بشکند، هیچ فراخوانی‌ای دیده نمی‌شود و قاعده
//    روی هر خرابی‌ای سبز می‌ماند — همان «سنجشی که روی خرابی سبز می‌شود».
// ⚠ کف ۱۵ است نه ۲۰: شش بلوکِ نمودارِ `dashboard.php` در یک بلوکِ
//   `includes/dash_parts.php` یکی شدند (مشترک با خانه‌ی دسکتاپ)، پس شمار
//   پایین آمد بی‌آنکه چیزی کور شده باشد. کف فقط سدِ «ردیابی شکست» است.
if ($hexSites < 15) {
    $badHex[] = '⛔ فقط ' . $hexSites . ' فراخوانی پیدا شد — ردیابیِ <script> شکسته است';
}

T::bulk(count($hexFiles) + 1, $badHex, '⛔ هر json_encode داخلِ <script> با JSON_HEX_TAG می‌رود');

// ---------------------------------------------------------------------------
// ⛔ قاعده ۵۵ — «Script error.»ِ کور انداخته می‌شود، ولی **فقط همان**
//
// مالکِ نصب در `admin/errors.php` یک خطای **باز** دید که هیچ‌وقت بسته
// نمی‌شد: «مرورگر / Script error. / خط ۰ / ۱ بار». آن رشته را خودِ
// مرورگر می‌سازد وقتی اسکریپتی از **مبدأ دیگری** استثنا بدهد — پیام و
// فایل و خط و ردِ پشته عمداً پنهان می‌شوند. همه‌ی اسکریپت‌های این اپ
// هم‌مبدأ می‌آیند (`assetUrls()` مسیرِ نسبی می‌سازد و Chart.js داخلِ
// مخزن است)، پس خطای کدِ خودمان همیشه فایل و خط دارد و هرگز به این
// شکل نمی‌رسد. چیزی که به این شکل می‌رسد افزونه یا میزبانِ وب‌ویو است.
//
// ⛔ و ثبتش همان «هشدارِ همیشگی» بود: ردیفی بی‌هیچ اطلاعاتِ تشخیصی که
//    نشانِ نوارِ مدیر را روشن می‌کند و «برطرف شد» هم بسته نگهش
//    نمی‌دارد، چون `AppErrors::record()` با رخدادِ بعدی بازش می‌کند.
//
// ⚠ خطرِ خودِ رفع، گشاد شدنش است. صافی باید **هر چهار** نشانه را با هم
//   بخواهد و پیش از `sent++` بنشیند (وگرنه یک افزونه سهمیه‌ی دوتاییِ
//   صفحه را می‌خورد و خطای واقعی گزارش نمی‌شود). رفتارش در
//   `tests/test_client_error.php` با node روی خودِ `client-errors.js` سنجیده
//   می‌شود؛ اینجا فقط *شکل* است، چون آن تست به node نیاز دارد
//   (`T::skip`) و روی ماشینِ بی‌node فقط همین قاعده می‌ماند — همان
//   استدلالِ قاعده ۴۳ و ۴۷.
T::group('قاعده ۵۵ — صافیِ خطای کورِ مرورگر');

$badOpq = [];
// ⛔ گزارش‌گر در `client-errors.js` است (مشترکِ حساب‌لند و فروشگاه).
$opqSrc = @file_get_contents(__DIR__ . '/../assets/js/client-errors.js');
if ($opqSrc === false || $opqSrc === '') {
    $badOpq[] = '⛔ assets/js/client-errors.js خوانده نشد — قاعده ۵۵ کور شده';
    $opqSrc = '';
}
// کامنت‌های C-مانند حذف می‌شوند: همین توضیح نامِ همان رشته را دارد و
// بدونِ حذف، بررسی روی فایلِ **سالم** هم سبز می‌ماند (دامِ قاعده ۱۹/۳۵/۳۸).
$opqCode = preg_replace('#/\*.*?\*/#s', '', $opqSrc);
$opqCode = preg_replace('#^\s*//.*$#m', '', (string)$opqCode);

// بدنه‌ی خودِ صافی: از `function isOpaque(` تا تعریفِ بعدی.
$opqBody = '';
$opqAt = strpos((string)$opqCode, 'function isOpaque(');
if ($opqAt === false) {
    $badOpq[] = '⛔ `function isOpaque(` در client-errors.js نیست — صافی برداشته شده';
} else {
    $opqEnd = strpos((string)$opqCode, 'function report(', $opqAt);
    $opqBody = substr((string)$opqCode, $opqAt, ($opqEnd === false ? 1200 : $opqEnd - $opqAt));
}

if ($opqBody !== '') {
    // ⛔ هر چهار نشانه با هم — نه یکی کمتر.
    if (!preg_match('/\^script\s*error/i', $opqBody)) {
        $badOpq[] = '⛔ صافی پیام را با الگوی **لنگرزده** نمی‌سنجد — «Script error.» داخلِ یک پیامِ واقعی هم انداخته می‌شود';
    }
    if (strpos($opqBody, 'indexOf(') !== false || strpos($opqBody, '.includes(') !== false) {
        $badOpq[] = '⛔ تطابقِ «شامل» در صافی — با آن هر پیامی که این عبارت را داشته باشد بی‌صدا دور ریخته می‌شود';
    }
    if (!preg_match('/&&\s*!file/', $opqBody)) {
        $badOpq[] = '⛔ صافی خالی بودنِ `file` را نمی‌خواهد — خطای هم‌مبدأ هم دور ریخته می‌شود';
    }
    if (!preg_match('/&&\s*Number\(\s*line/', $opqBody)) {
        $badOpq[] = '⛔ صافی صفر بودنِ `line` را نمی‌خواهد';
    }
    if (!preg_match('/&&\s*!stack/', $opqBody)) {
        $badOpq[] = '⛔ صافی نبودنِ ردِ پشته را نمی‌خواهد';
    }
}

// ⛔ صافی باید **پیش از** `sent++` صدا زده شود، داخلِ `report()`.
$repAt = strpos((string)$opqCode, 'function report(');
if ($repAt === false) {
    $badOpq[] = '⛔ `function report(` پیدا نشد — گزارش‌گر عوض شده';
} else {
    $repBody = substr((string)$opqCode, $repAt, 600);
    $callAt  = strpos($repBody, 'isOpaque(message');
    $sentAt  = strpos($repBody, 'sent++');
    if ($callAt === false) {
        $badOpq[] = '⛔ `report()` صافی را صدا نمی‌زند — خطای کور دوباره ثبت می‌شود';
    } elseif ($sentAt === false) {
        $badOpq[] = '⛔ `sent++` در `report()` نیست — سقفِ دوتاییِ صفحه رفته';
    } elseif ($callAt > $sentAt) {
        $badOpq[] = '⛔ صافی **بعد از** `sent++` است — خطای کور سهمیه‌ی صفحه را می‌خورد و خطای واقعی گزارش نمی‌شود';
    }
}

// ⛔ و «همه‌ی خطاهای مرورگر را نفرست» بازنویسیِ ممنوعِ این تابع است:
//    هر دو شنونده باید همچنان `report(` را صدا بزنند.
// ⚠ پنجره تا **ثبتِ شنونده‌ی بعدی** بریده می‌شود، نه یک طولِ ثابت:
//   با ۲۶۰ کاراکتر، جهشِ «report را از شنونده‌ی error بردار» زنده ماند
//   چون `report(`ِ شنونده‌ی بعدی داخلِ همان پنجره می‌افتاد — همان درسِ
//   «تا اولین `;`» در قاعده ۶ و پنجره‌ی قاعده ۴۷.
foreach (["addEventListener('error'", "addEventListener('unhandledrejection'"] as $opqEv) {
    $evAt = strpos((string)$opqCode, $opqEv);
    if ($evAt === false) {
        $badOpq[] = '⛔ شنونده‌ی ' . $opqEv . ' برداشته شده';
        continue;
    }
    $nextAt = strpos((string)$opqCode, 'addEventListener(', $evAt + strlen($opqEv));
    $evWin  = substr((string)$opqCode, $evAt, ($nextAt === false ? 260 : $nextAt - $evAt));
    if (strpos($evWin, 'report(') === false) {
        $badOpq[] = '⛔ شنونده‌ی ' . $opqEv . ' دیگر `report(` را صدا نمی‌زند';
    }
}

// ⛔ و صافی روی `window` باشد تا در node آزمودنی بماند (قاعده‌ی
//    `parseBankSms()` و `kbNeedsKeyboard()`).
if (strpos((string)$opqCode, 'window.isOpaqueClientError') === false) {
    $badOpq[] = '⛔ `window.isOpaqueClientError` در معرض نیست — tests/test_client_error.php کور می‌شود';
}

T::bulk(11, $badOpq, '⛔ فقط امضای کورِ مرورگر انداخته می‌شود، و پیش از شمارشِ سهمیه');

// ============================================================
// قاعده ۵۶ — انتقالِ تراکنش‌های یک حساب به حسابِ دیگر
// ============================================================
// ⛔ «هیچ پولی بی‌حساب نمی‌ماند» — و این عملیات دقیقاً جایی است که
//    پول می‌تواند بی‌صدا گم شود: موجودیِ اولیه‌ای که منتقل نشود، ستونی
//    که از فهرست جا بماند، یا سدی که برداشته شود. رفتار را
//    `tests/test_wallet_merge.php` می‌سنجد؛ این قاعده شکل را، چون آن
//    تست بدونِ دیتابیس `T::blocked` می‌شود.
T::group('قاعده ۵۶ — انتقالِ تراکنش‌های حساب (mergeWallet)');

$badWm = [];
$wmCode = function (string $rel): string {
    $src = @file_get_contents(__DIR__ . '/../' . $rel);
    if ($src === false) { return ''; }
    $out = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
};
$wmFn = $wmCode('includes/functions.php');
$wmBody = function (string $name) use ($wmFn): string {
    $at = strpos($wmFn, 'function ' . $name . '(');
    if ($at === false) { return ''; }
    $end = strpos($wmFn, "\nfunction ", $at + 10);
    return substr($wmFn, $at, $end === false ? 6000 : $end - $at);
};

$refBody = $wmBody('walletRefColumns');
if ($refBody === '') {
    $badWm[] = '⛔ `walletRefColumns()` نیست';
} else {
    if (strpos($refBody, 'information_schema.KEY_COLUMN_USAGE') === false) {
        $badWm[] = '⛔ `walletRefColumns()` کلیدهای خارجی را از دیتابیس کشف نمی‌کند';
    }
    if (strpos($refBody, "wallet_id$/") === false) {
        $badWm[] = '⛔ `walletRefColumns()` ستون‌های `*wallet_id` بی‌کلیدِ خارجی را نمی‌بیند (یادآورها)';
    }
}

$mBody = $wmBody('mergeWallet');
if ($mBody === '') {
    $badWm[] = '⛔ `mergeWallet()` نیست';
} else {
    foreach ([
        'walletRefColumns()'                      => 'فهرستِ ستون‌ها از `walletRefColumns()` نمی‌آید',
        "\$from['initial_balance']"               => '⛔ موجودیِ اولیه‌ی حسابِ مبدأ منتقل نمی‌شود',
        'initial_balance = initial_balance + :add' => '⛔ موجودیِ اولیه روی مقصد نمی‌نشیند',
        'DELETE FROM transfers WHERE'             => '⛔ انتقال‌های بینِ همین دو حساب حذف نمی‌شوند (خودبه‌خودی می‌شوند)',
        '(int)$after[$intoId] !== $expect'        => '⛔ سدِ «موجودیِ مقصد = جمعِ هر دو» برداشته شده',
        '$left !== 0'                             => '⛔ سدِ «هیچ ردیفی روی مبدأ نماند» برداشته شده',
        "(int)\$into['is_active'] !== 1"          => 'مقصدِ غیرفعال پذیرفته می‌شود — پولش از جمعِ دارایی بیرون می‌افتد',
    ] as $needle => $why) {
        if (strpos($mBody, $needle) === false) { $badWm[] = $why; }
    }
    // ⛔ مالکیتِ هر دو حساب: همان SELECT با `user_id = :u` برای هر دو.
    if (!preg_match('/SELECT id, name, is_active, initial_balance FROM wallets WHERE id = :id AND user_id = :u/', $mBody)) {
        $badWm[] = '⛔ مالکیتِ حساب‌ها در `mergeWallet()` سنجیده نمی‌شود';
    }
    if (substr_count($mBody, 'AND user_id = :u") ;') > 0 || !preg_match('/SET `\{\$r\[\'col\'\]\}` = :i WHERE `\{\$r\[\'col\'\]\}` = :f AND user_id = :u/', $mBody)) {
        $badWm[] = '⛔ `UPDATE` ستون‌های ارجاع شرطِ `user_id` ندارد';
    }
}

$ep = $wmCode('api/merge_wallet.php');
if ($ep === '') {
    $badWm[] = '⛔ api/merge_wallet.php نیست';
} else {
    if (strpos($ep, 'Csrf::verifyOrFail(') === false) { $badWm[] = '⛔ api/merge_wallet.php بدونِ CSRF'; }
    if (strpos($ep, '$userId = Auth::userId();') === false) { $badWm[] = '⛔ کاربر از نشست نمی‌آید'; }
    if (strpos($ep, 'mergeWallet($userId,') === false) { $badWm[] = '⛔ اندپوینت از `mergeWallet()` رد نمی‌شود'; }
    if (preg_match('/\b(UPDATE\s+\S+\s+SET|DELETE\s+FROM|INSERT\s+INTO)\b/', $ep)) { $badWm[] = '⛔ اندپوینت SQLِ خودش را دارد — نسخه‌ی دومِ منطقِ پول'; }
}

// ⛔ تنها فراخوانِ `mergeWallet(` همان اندپوینت است.
foreach (array_merge(glob(__DIR__ . '/../*.php'), glob(__DIR__ . '/../api/*.php'),
                     glob(__DIR__ . '/../admin/*.php'), glob(__DIR__ . '/../store/*.php'), glob(__DIR__ . '/../includes/*.php')) as $f) {
    $rel = substr(realpath($f), strlen(realpath(__DIR__ . '/..')) + 1);
    if (in_array($rel, ['api/merge_wallet.php', 'includes/functions.php'], true)) { continue; }
    // ⚠ فراخوانیِ واقعی آرگومانِ `$` دارد؛ توضیحِ HTML ای مثل
    //   «`mergeWallet()`» در مودال فراخوانی نیست (هشدارِ الکی).
    if (preg_match('/mergeWallet\(\s*\$/', $wmCode($rel))) { $badWm[] = '⛔ ' . $rel . ' خودش `mergeWallet()` را صدا می‌زند'; }
}

$modals = (string)@file_get_contents(__DIR__ . '/../includes/wallet_card_modals.php');
if (strpos($modals, 'id="bcMergeBtn"') === false) { $badWm[] = '⛔ دکمه‌ی انتقال از نمای کارت رفته'; }
if (strpos($modals, 'id="mergeWalletModal"') === false) { $badWm[] = '⛔ مودالِ انتخابِ مقصد نیست'; }
if (strpos($modals, 'activeWallets(') === false) { $badWm[] = 'فهرستِ مقصد از `activeWallets()` نمی‌آید'; }
$wmJs = preg_replace('#/\*.*?\*/#s', '', (string)@file_get_contents(__DIR__ . '/../assets/js/app.js'));
if (strpos((string)$wmJs, "apiUrl('merge_wallet.php')") === false) { $badWm[] = '⛔ app.js اندپوینتِ انتقال را صدا نمی‌زند'; }
$delMsg = $wmCode('api/delete_wallet.php');
if (mb_strpos($delMsg, 'انتقال تراکنش‌ها به حساب دیگر') === false) {
    $badWm[] = 'پیامِ «قابل حذف نیست» راهِ انتقال را نشان نمی‌دهد — کاربر در بن‌بست می‌ماند';
}

T::bulk(22, $badWm, '⛔ انتقالِ حساب از یک تابع، با موجودیِ اولیه، سد، و مالکیت');

// ---------------------------------------------------------------
// قاعده ۵۷ — برندِ سبز، یک رنگ در چهار جا، و ظاهرِ تازه برنگردد
// ---------------------------------------------------------------
//
// ⛔ رنگِ برند در **چهار** جا نوشته می‌شود و هر کدام که جا بماند خرابی‌اش
//    فقط روی گوشی دیده می‌شود: نوارِ وضعیتِ اندروید (`colorPrimary`)،
//    رنگِ نوارِ مرورگر (`<meta name="theme-color">`)، صفحه‌ی راه‌اندازیِ
//    PWA (`theme_color` در مانیفست)، و خودِ `--brand` در CSS.
// ⛔ `var(--brand)` به‌عنوان **متن یا خط** ممنوع است: در شب سطحِ تیره است
//    و زبانه‌ی فعالِ «سررسیدها» عملاً نامرئی بود. `--brand-fg` جایش است.
// ⛔ و سه چیزِ ظاهری که هر کدام یک خرابیِ بی‌صدا را بسته‌اند:
//    رنگِ دسته با `--cat-bg` (سفیدیِ `!important`ِ Finmori رنگِ inline را
//    می‌کشت)، نوارِ ماه `direction: ltr` (مثل هر میله‌ی پیشرفت)، و کارتِ
//    خانه که مقایسه را **یک** بار می‌خواند و جمله‌ی تکراری نمی‌سازد.
T::group('قاعده ۵۷ — برندِ سبز و ظاهرِ تازه');

$badBr = [];
$brCss  = (string)preg_replace('#/\*.*?\*/#s', '', (string)@file_get_contents(__DIR__ . '/../assets/css/style.css'));

// ۱) آخرین `--brand`ِ سطحِ :root (نه شب) همان رنگِ برند است.
$brand = '';
if (preg_match_all('/(?:^|\})\s*:root\s*\{([^{}]*)\}/s', $brCss, $rm)) {
    foreach ($rm[1] as $body) {
        if (preg_match('/--brand:\s*(#[0-9a-fA-F]{6})/', $body, $bm)) { $brand = strtolower($bm[1]); }
    }
}
if ($brand === '') { $badBr[] = '--brand در :root پیدا نشد'; }

$mani = (string)@file_get_contents(__DIR__ . '/../assets/manifest.php');
if (!preg_match("/'theme_color'\s*=>\s*'(#[0-9a-fA-F]{6})'/", $mani, $tm)) {
    $badBr[] = 'theme_color در مانیفست پیدا نشد';
} elseif (strtolower($tm[1]) !== $brand) {
    $badBr[] = "theme_color مانیفست ({$tm[1]}) با --brand ({$brand}) یکی نیست";
}
$xml = (string)@file_get_contents(__DIR__ . '/../mobile/app/src/main/res/values/colors.xml');
if (!preg_match('#<color name="colorPrimary">(\#[0-9a-fA-F]{6})</color>#', $xml, $xm)) {
    $badBr[] = 'colorPrimary پیدا نشد';
} elseif (strtolower($xm[1]) !== $brand) {
    $badBr[] = "colorPrimary ({$xm[1]}) با --brand ({$brand}) یکی نیست — نوارِ وضعیتِ اندروید با اپ نمی‌خواند";
}
$metaFiles = array_merge(glob(__DIR__ . '/../*.php'), [__DIR__ . '/../includes/header.php']);
$metaSeen = 0;
foreach ($metaFiles as $f) {
    $src = (string)@file_get_contents($f);
    if (preg_match_all('/<meta name="theme-color" content="(#[0-9a-fA-F]{6})"/', $src, $mm)) {
        foreach ($mm[1] as $c) {
            $metaSeen++;
            if (strtolower($c) !== $brand) { $badBr[] = basename($f) . " — theme-color {$c} با --brand یکی نیست"; }
        }
    }
}
if ($metaSeen < 5) { $badBr[] = "فقط {$metaSeen} متای theme-color پیدا شد — کشف کور شده"; }

// ۲) `--brand-fg` در هر دو تم.
$lastRoot = ''; $lastDark = '';
foreach ($rm[1] ?? [] as $body) { if (strpos($body, '--brand:') !== false) { $lastRoot = $body; } }
if (preg_match_all('/(?:^|\})\s*html\[data-theme="dark"\]\s*\{([^{}]*)\}/s', $brCss, $dm)) {
    foreach ($dm[1] as $body) { if (strpos($body, '--brand:') !== false) { $lastDark = $body; } }
}
if (strpos($lastRoot, '--brand-fg:') === false) { $badBr[] = '--brand-fg در تمِ روز تعریف نشده'; }
if (strpos($lastDark, '--brand-fg:') === false) { $badBr[] = '--brand-fg در تمِ شب تعریف نشده — متنِ برند در شب نامرئی می‌شود'; }

// ۳) برند به‌عنوان متن/خط فقط از `--brand-fg`؛ دکمه‌ی + استثناست (متن
//    روی زمینه‌ی نعنایی، که در شب جدا بازنویسی می‌شود).
if (preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $brCss, $rules, PREG_SET_ORDER)) {
    foreach ($rules as $r) {
        if (!preg_match('/(?<![\w-])(color|border-color|border-bottom-color|outline)\s*:[^;]*var\(--brand\)/', $r[2])) { continue; }
        if (strpos($r[1], 'bottom-nav-fab') !== false) { continue; }
        // خطِ هم‌رنگِ زمینه‌ی خودش (چیپِ فعالِ پُر) متن نیست.
        if (preg_match('/(?<![\w-])background\s*:\s*var\(--brand\)/', $r[2])) { continue; }
        $badBr[] = trim(preg_replace('/\s+/', ' ', $r[1])) . ' — var(--brand) به‌عنوان متن/خط؛ --brand-fg بنویسید';
    }
}

// ۴) آیکونِ دسته: رنگ از `--cat-bg`، نه `background:` inline.
foreach (array_merge(glob(__DIR__ . '/../*.php'), glob(__DIR__ . '/../admin/*.php'), glob(__DIR__ . '/../store/*.php'),
                     glob(__DIR__ . '/../includes/*.php'), glob(__DIR__ . '/../api/*.php')) as $f) {
    if (preg_match('/class="cat-icon[^"]*"\s+style="background\s*:/', (string)@file_get_contents($f))) {
        $badBr[] = basename($f) . ' — cat-icon با background inline (زیرِ !important سفید می‌شود؛ --cat-bg بنویسید)';
    }
}
if (!preg_match('/\.cat-icon\[style\*="--cat-bg"\]\s*\{[^}]*var\(--cat-bg\)\s*!important/', $brCss)) {
    $badBr[] = 'قاعده‌ی .cat-icon[style*="--cat-bg"] رفته';
}

// ۵) نوارِ ماه از چپ پر می‌شود، و دریافتی/پرداختی ردیفِ کلید/مقدارند.
if (!preg_match('/\.month-meter-track\s*\{[^}]*direction:\s*ltr/', $brCss)) {
    $badBr[] = '.month-meter-track — direction: ltr ندارد (در RTL از راست پر می‌شد)';
}
if (!preg_match('/\.balance-split\s*\{[^}]*flex-direction:\s*column/', $brCss)) {
    $badBr[] = '.balance-split دوباره دوستونه شده — برچسب و عددش دور از هم می‌افتند';
}

// ۶) کارتِ خانه: یک مقایسه، بدونِ جمله‌ی تکراری.
$ix = '';
foreach (token_get_all((string)@file_get_contents(__DIR__ . '/../index.php')) as $tk) {
    if (is_array($tk) && in_array($tk[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
    $ix .= is_array($tk) ? $tk[1] : $tk;
}
if (substr_count($ix, 'monthComparison(') !== 1) { $badBr[] = 'index.php باید monthComparison را دقیقاً یک بار صدا بزند'; }
if (preg_match('/SUM\(CASE WHEN type\s*=\s*"income"/', $ix)) { $badBr[] = 'index.php جمعِ ماه را دوباره خودش می‌خواند'; }
if (!preg_match('/financialHighlights\(\$userId,\s*\$walletRows,\s*\$monthCmp,\s*true[,)]/', $ix)) {
    $badBr[] = 'financialHighlights روی خانه همان مقایسه را دوباره می‌گوید';
}
if (strpos($ix, 'monthMeter($monthCmp)') === false) { $badBr[] = 'نوارِ ماه از monthMeter() نمی‌آید'; }

// ۷) جای‌نگهدارِ بارگذاری فقط از `skeletonHtml`.
$brJs = (string)@file_get_contents(__DIR__ . '/../assets/js/app.js');
if (preg_match_all("/innerHTML\s*=\s*'[^']*در حال بارگذاری/u", $brJs, $jm)) {
    $badBr[] = 'app.js — ' . count($jm[0]) . ' جای‌نگهدارِ متنی به‌جای window.skeletonHtml()';
}
if (preg_match('/document\.addEventListener\(\'DOMContentLoaded\'/', $brJs, $dcl, PREG_OFFSET_CAPTURE)
    && (($sk = strpos($brJs, 'window.skeletonHtml = function')) === false || $sk > $dcl[0][1])) {
    $badBr[] = 'window.skeletonHtml باید بیرون (پیش) از DOMContentLoaded باشد';
}

T::bulk(16, $badBr, '⛔ رنگِ برند یکی، متنِ برند در شب دیده می‌شود، و ظاهرِ تازه سرِ جایش است');

// ---------------------------------------------------------------
// قاعده ۵۸ — پالتِ رنگ: یک فهرست، طیف از توکن، و خانه بی‌سرِ روز
// ---------------------------------------------------------------
// ⛔ رفتار (کاملیِ توکن‌ها، تضاد، برنده شدن در شب) را `test_palette.php`
//    می‌سنجد؛ ولی بخشِ مرورگرش به کرومیوم و دیتابیس بند است، پس *شکل*
//    اینجا جدا نگه داشته می‌شود — همان استدلالِ قاعده ۴۳ و ۴۷.
T::group('قاعده ۵۸ — پالتِ رنگ');
$badPl = [];
$plHead = (string)@file_get_contents(__DIR__ . '/../includes/header.php');
$plProf = (string)@file_get_contents(__DIR__ . '/../profile.php');
$plJs   = (string)@file_get_contents(__DIR__ . '/../assets/js/app.js');
$plIdx  = (string)@file_get_contents(__DIR__ . '/../index.php');
$plTx   = (string)@file_get_contents(__DIR__ . '/../transactions.php');
$plCss  = (string)preg_replace('#/\*.*?\*/#s', '', (string)@file_get_contents(__DIR__ . '/../assets/css/style.css'));

// ۱) فهرست فقط UI_PALETTES — سرآیند و انتخابگر هر دو از همان.
if (!preg_match("/const UI_PALETTES = \[\s*'indigo'\s*=>/", (string)@file_get_contents(__DIR__ . '/../includes/functions.php'))) {
    $badPl[] = 'UI_PALETTES در functions.php نیست یا نیلی (پیش‌فرضِ همه) اولینش نیست';
}
if (strpos($plHead, 'array_keys(UI_PALETTES)') === false) { $badPl[] = 'header.php فهرستِ پالت را از UI_PALETTES نمی‌سازد'; }
if (strpos($plHead, "localStorage.getItem('daftar_palette')") === false) { $badPl[] = 'header.php پالت را پیش از رندر نمی‌خواند'; }
if (preg_match("/\[\s*'(?:indigo|ocean|sunset|lilac|graphite)'/", $plHead)) { $badPl[] = 'header.php فهرستِ دستیِ پالت دارد'; }
if (!preg_match('/foreach\s*\(\s*UI_PALETTES\s+as/', $plProf)) { $badPl[] = 'profile.php انتخابگر را از UI_PALETTES رندر نمی‌کند'; }

// ۲) theme-color از --brand ِ محاسبه‌شده، در هر دو مسیر (حالت شب و پالت).
if (!preg_match("/function syncThemeColorMeta\(\)[\s\S]{0,400}getComputedStyle\(document\.documentElement\)\.getPropertyValue\('--brand'\)/", $plJs)) {
    $badPl[] = 'app.js — syncThemeColorMeta رنگ را از --brand ِ محاسبه‌شده نمی‌خواند';
}
if (substr_count($plJs, 'syncThemeColorMeta();') < 3) { $badPl[] = 'app.js — syncThemeColorMeta در حالت شب، تغییرِ پالت و بارگذاری صدا زده نمی‌شود'; }
if (!preg_match("/value === defPal\)\s*\{\s*document\.documentElement\.removeAttribute\('data-palette'\)/", $plJs)) {
    $badPl[] = 'app.js — پیش‌فرض ویژگیِ data-palette را برنمی‌دارد';
}
if (!preg_match("/defPal = palettePicker\.getAttribute\('data-default'\)/", $plJs)) { $badPl[] = 'app.js — نامِ پیش‌فرض از data-default ِ صفحه خوانده نمی‌شود'; }
if (preg_match("/value === '[a-z]+'\)\s*\{\s*document\.documentElement\.removeAttribute\('data-palette'\)/", $plJs)) { $badPl[] = 'app.js — نامِ پیش‌فرض سخت‌کد شده'; }
if (!preg_match('/data-default="<\?= h\(\$__palDefault\) \?>"/', $plProf) || strpos($plProf, 'array_key_first(UI_PALETTES)') === false) {
    $badPl[] = 'profile.php — پیش‌فرضِ انتخابگر از اولین کلیدِ UI_PALETTES نمی‌آید';
}

// ۳) طیفِ کارتِ ماه، دکمه‌ی + و سرِ پروفایل از توکن — در بخشِ ۴۰ هیچ
//    رنگِ سخت‌کدی روی این چهار نیست، و شب پس‌زمینه را **تکرار** می‌کند
//    (وگرنه قاعده‌ی بنفشِ بخشِ ۳۴ برنده است).
$pl40 = strpos($plCss, '--palette-default:');
$pl40 = $pl40 === false ? '' : substr($plCss, $pl40);
foreach (['.balance-ribbon', '.bottom-nav-fab', '.profile-head', '.profile-avatar',
          'html[data-theme="dark"] .balance-ribbon', 'html[data-theme="dark"] .bottom-nav-fab'] as $sel) {
    if (!preg_match('/(?:^|\})\s*' . preg_quote($sel, '/') . '\s*\{([^{}]*)\}/', $pl40, $bm)) { $badPl[] = "بخشِ ۴۰ قاعده‌ی {$sel} ندارد"; continue; }
    if (preg_match('/#[0-9a-f]{3,6}\b/i', $bm[1])) { $badPl[] = "بخشِ ۴۰ — {$sel} رنگِ سخت‌کد دارد (باید از توکنِ پالت بیاید)"; }
    if (strpos($sel, '.profile-avatar') === false && !preg_match('/background:\s*linear-gradient\([^;]*var\(--(?:rb|fab|ph)1\)/', $bm[1])) {
        $badPl[] = "بخشِ ۴۰ — {$sel} پس‌زمینه‌ی طیف‌دار را از توکن نمی‌گیرد";
    }
}

// ۴) نشانِ حالتِ خالی رنگ را از --gold می‌گیرد، نه از data URI ِ رنگی.
if (preg_match("/p\.empty-row[^{]*\{[^}]*stroke='%23(?!000')/", $plCss)) { $badPl[] = 'نشانِ حالتِ خالی رنگِ سخت‌کد در data URI دارد'; }
if (!preg_match('/p\.empty-row::after\s*\{[^}]*background:\s*var\(--gold\)[^}]*mask:/', $plCss)) { $badPl[] = 'نشانِ حالتِ خالی ماسک با --gold نیست'; }

// ۵) خانه بی‌سرِ روز، صفحه‌ی تراکنش‌ها با سرِ روز.
if (strpos($plIdx, 'renderTransactionsGrouped(') !== false) { $badPl[] = 'index.php — «آخرین تراکنش‌ها» دوباره سرِ روز گرفته'; }
if (!preg_match('/foreach\s*\(\s*\$recentTransactions\s+as\s+\$\w+\)\s*\{\s*renderTransactionRow\(/', $plIdx)) { $badPl[] = 'index.php — ردیف‌ها از renderTransactionRow() رندر نمی‌شوند'; }
if (strpos($plTx, 'renderTransactionsGrouped(') === false) { $badPl[] = 'transactions.php گروه‌بندیِ روزانه را از دست داده'; }

// ⛔ Ramp × Mercury × Linear (بخشِ ۴۲): پایه‌ی خنثی برای همه‌ی پالت‌ها، ولی
//    هرگز لهجه‌ی پالت — برند، کارتِ ماه، دکمه‌ی + — را بازتعریف نمی‌کند.
$css42 = (string)@file_get_contents(__DIR__ . '/../assets/css/style.css');
$sec42 = ($p42 = strpos($css42, '۴۲ — Ramp × Mercury × Linear')) !== false ? substr($css42, $p42) : '';
if ($sec42 === '') {
    $badPl[] = 'style.css — بخشِ «۴۲ — Ramp × Mercury × Linear» نیست';
} else {
    $body42 = preg_replace('#/\*.*?\*/#s', '', $sec42);
    if (preg_match('/--(brand|brand-fg|brand-deep|rb[0-9a-z-]*|fab[0-9a-z-]*|tint|nav-mark|gold[a-z-]*|in|out)\s*:/', $body42, $m42)) {
        $badPl[] = "بخشِ ۴۲ لهجه‌ی پالت را بازتعریف می‌کند: --{$m42[1]}";
    }
    if (!str_contains($body42, 'html[data-theme="dark"][data-theme] {') || !str_contains($body42, 'html:not([data-theme="dark"]) {')) {
        $badPl[] = 'بخشِ ۴۲ — پایه‌ی خنثی باید برای روز و شب هر دو باشد (با وزنِ درست)';
    }
}
T::bulk(24, $badPl, '⛔ پالت از یک فهرست، طیف از توکن، theme-color از --brand، و خانه بی‌سرِ روز');

// ---------------------------------------------------------------
// ⛔ قاعده ۵۹ — هیچ آدرسِ blob: ای برای تصویر، وقتی CSP آن را نمی‌پذیرد.
//    آپلودِ عکسِ پروفایل روی سرور برای **هر** عکسی «تصویر معتبری نیست»
//    می‌داد: `URL.createObjectURL` آدرسِ blob: می‌ساخت و
//    `img-src 'self' data:` آن را بی‌صدا می‌بست. روی ماشینِ توسعه (بی‌CSP)
//    سالم بود. رفتارش را `test_avatar_upload.php` زیرِ همان CSP می‌سنجد.
T::group('قاعده ۵۹ — تصویرِ blob: زیرِ CSP');
$badBlob = [];
$cspSrc = (string)@file_get_contents(__DIR__ . '/../deploy/nginx-csp.sh');
$imgSrc = preg_match('/^CSP="[^"]*img-src ([^;"]+)/m', $cspSrc, $im) ? $im[1] : '';
if ($imgSrc === '') { $badBlob[] = 'سیاستِ img-src در nginx-csp.sh پیدا نشد'; }
if ($imgSrc !== '' && strpos($imgSrc, 'blob:') === false) {
    foreach (glob(__DIR__ . '/../assets/js/*.js') ?: [] as $jf) {
        if (basename($jf) === 'chart.umd.js') { continue; }
        $js = (string)preg_replace(['#/\*.*?\*/#s', '#(?<![:"\'])//[^\n]*#'], '', (string)file_get_contents($jf));
        if (strpos($js, 'createObjectURL(') !== false) { $badBlob[] = basename($jf) . ' — URL.createObjectURL با CSPی که blob: ندارد'; }
    }
}
if (!preg_match('/id="avatarInput" accept="image\/\*"/', (string)@file_get_contents(__DIR__ . '/../profile.php'))) {
    $badBlob[] = 'profile.php — ورودیِ تصویرِ پروفایل «هر عکسی» (image/*) را نمی‌پذیرد';
}
if (strpos((string)@file_get_contents(__DIR__ . '/../assets/js/app.js'), 'reader.readAsDataURL(file)') === false) {
    $badBlob[] = 'app.js — قابِ برش تصویر را با FileReader (data:) نمی‌خواند';
}
T::bulk(3, $badBlob, '⛔ تصویرِ پروفایل از راهِ data: خوانده می‌شود، نه blob: ِ بسته در CSP');

// ---------------------------------------------------------------
// ⛔ قاعده ۶۰ — «بعد از ورود دیگه کاربر از اپ خارج نشه مگه خودش بخواد».
//    رفتارش را `test_stay_logged_in.php` با شبیه‌سازیِ بستنِ اپ می‌سنجد؛
//    اینجا شکل: هر ورود دستگاه را به خاطر می‌سپارد (بی‌کلید)، و خودابطالی
//    اعتمادِ همین دستگاه را دوباره می‌سازد.
T::group('قاعده ۶۰ — ماندن در حساب');
$badStay = [];
foreach (['login.php', 'sms-login.php'] as $lf) {
    $src = (string)@file_get_contents(__DIR__ . '/../' . $lf);
    if (strpos($src, "postParam('trust_device')") !== false) { $badStay[] = "{$lf} — «به خاطر سپردن» دوباره به یک کلید بند شده"; }
    if (strpos($src, 'name="trust_device"') !== false) { $badStay[] = "{$lf} — کلیدِ «این دستگاه را به خاطر بسپار» برگشته"; }
    if (strpos($src, 'Auth::trustThisDevice(') === false) { $badStay[] = "{$lf} — ورود دستگاه را به خاطر نمی‌سپارد"; }
}
$authSrc = (string)@file_get_contents(__DIR__ . '/../includes/auth.php');
if (!preg_match('/function renewCurrentSession\(\)([\s\S]*?)\n    \}/', $authSrc, $rcs) || strpos($rcs[1], 'self::trustThisDevice(') === false) {
    $badStay[] = 'auth.php — renewCurrentSession() اعتمادِ همین دستگاه را دوباره نمی‌سازد';
}
// ⛔ منطقِ تغییرِ رمز در `changeOwnPassword()` (signup.php) است — هم حساب لند و
//    هم صفحه‌ی حسابِ فروشگاه از آن می‌گذرند؛ سنجش روی همان تابع.
if (!preg_match('/function changeOwnPassword\([\s\S]*?if \(\$hasPassword\) \{\s*revokeAllAccessFor\(\$userId\);/', (string)@file_get_contents(__DIR__ . '/../includes/signup.php'))
    || strpos((string)@file_get_contents(__DIR__ . '/../api/change_password.php'), 'changeOwnPassword(') === false) {
    $badStay[] = 'changeOwnPassword() — تنظیمِ **اولین** رمز همه‌ی دستگاه‌ها را بیرون می‌اندازد (یا اندپوینت از آن نمی‌گذرد)';
}
T::bulk(8, $badStay, '⛔ هر ورود دستگاه را نگه می‌دارد و تغییرِ رمز همین دستگاه را بیرون نمی‌اندازد');

// ---------------------------------------------------------------
// ⛔ قاعده ۶۱ — اعلان روی گوشی (Web Push) و بستنِ var/ از وب.
//    رفتار در `test_push.php` (بردارِ RFC، سرویسِ پوشِ ساختگی، کرومیوم).
T::group('قاعده ۶۱ — اعلان روی گوشی');
$badPush = [];
$pushSrc = (string)@file_get_contents(__DIR__ . '/../includes/push.php');
$swSrc   = (string)@file_get_contents(__DIR__ . '/../sw.js');
if (!preg_match('/function subscribe\([^)]*\)[^{]*\{[\s\S]{0,400}self::endpointAllowed\(\$endpoint\)/', $pushSrc)) {
    $badPush[] = 'push.php — subscribe() آدرسِ اشتراک را با فهرستِ مجازِ سرویس‌های پوش نمی‌سنجد (SSRF)';
}
if (strpos($pushSrc, "'fcm.googleapis.com'") === false) { $badPush[] = 'push.php — فهرستِ HOSTS سرویسِ پوشِ کروم/اندروید را ندارد'; }
if (!preg_match("/addEventListener\('push'[\s\S]{0,1500}showNotification\(/", $swSrc)) { $badPush[] = 'sw.js — رویدادِ push اعلان نشان نمی‌دهد'; }
if (!preg_match("/addEventListener\('notificationclick'/", $swSrc)) { $badPush[] = 'sw.js — تپ روی اعلان هیچ‌جا نمی‌رود'; }
if (strpos($swSrc, 'setAppBadge') === false) { $badPush[] = 'sw.js — عدد روی آیکونِ اپ نمی‌نشیند'; }
if (strpos((string)@file_get_contents(__DIR__ . '/../assets/js/app.js'), 'navigator.setAppBadge(') === false) { $badPush[] = 'app.js — عددِ نخوانده روی آیکون نمی‌نشیند'; }
$cronPush = (string)@file_get_contents(__DIR__ . '/../deploy/push-send.php');
if (preg_match('/vapid\(true\)|publicKey\(\)/', (string)preg_replace('#/\*.*?\*/#s', '', $cronPush))) {
    $badPush[] = 'push-send.php — cron (root) کلیدِ VAPID را می‌سازد؛ فایل مالِ root می‌شد و FPM نمی‌خواندش';
}
if (!preg_match("/'push'\s*=>\s*\[[^\]]*'deploy\/push-send\.php'\]/", (string)@file_get_contents(__DIR__ . '/../includes/cron_health.php'))) {
    $badPush[] = 'cron_health.php — cronِ push در JOBS ثبت نشده';
}
$mig = (string)@file_get_contents(__DIR__ . '/../deploy/migrate.sh');
if (!preg_match('/^\s+migration_push\.sql$/m', $mig) || strpos($mig, '[migration_push.sql]=') === false) {
    $badPush[] = 'migrate.sh — migration_push در MIGRATIONS/SENTINEL نیست';
}
if (!preg_match('#location \^~ /var/\s+\{ deny all; return 404; \}#', (string)@file_get_contents(__DIR__ . '/../deploy/vps-setup.sh'))) {
    $badPush[] = 'vps-setup.sh — var/ (لاگ، نشست، کلیدِ VAPID) از وب بسته نیست';
}
if (!is_file(__DIR__ . '/../deploy/nginx-var.sh')) { $badPush[] = 'deploy/nginx-var.sh نیست (بستنِ var/ روی نصبِ موجود)'; }
$man = (string)@file_get_contents(__DIR__ . '/../mobile/app/src/main/AndroidManifest.xml');
if (strpos($man, 'trusted.NotificationPermissionRequestActivity') === false) { $badPush[] = 'AndroidManifest — اندروید ۱۳ مجوزِ اعلانِ اپ را نمی‌پرسد'; }
if (strpos($man, 'android.support.customtabs.trusted.SMALL_ICON') === false || !is_file(__DIR__ . '/../mobile/app/src/main/res/drawable/ic_notification_icon.xml')) {
    $badPush[] = 'AndroidManifest — آیکونِ کوچکِ اعلان (SMALL_ICON) نیست';
}
T::bulk(13, $badPush, '⛔ اعلان روی گوشی: فهرستِ مجاز، سرویس‌ورکر، badge، cron، migration، var/ بسته، و مجوزِ اندروید');

// ⛔ و **پیش‌فرض روشن** (خواستِ مالکِ نصب). رفتار را `test_sms_parse`
//    با node روی `pushAutoAction()` می‌سنجد؛ اینجا شکلِ اتصال:
//    ۱) اشتراکِ خودکار از همان تابع تصمیم می‌گیرد (نه شرطِ محلیِ دوم)؛
//    ۲) «خاموش کردن» در پروفایل نشانه‌ی صریح می‌زند، وگرنه صفحه‌ی بعد
//       دوباره روشنش می‌کرد — دکمه‌ای که کار نمی‌کند؛
//    ۳) «روشن کردن» آن نشانه را برمی‌دارد؛
//    ۴) کلید از `push_subscribe.php` می‌آید، نه یک `<meta>` روی هر صفحه
//       (هیچ صفحه‌ای خواندنِ فایل یا کوئریِ تازه نگیرد)؛
//    ۵) درخواستِ اجازه فقط با تپ است (`once`) — نه در بارگذاری.
$badAuto = [];
$js = (string)@file_get_contents(__DIR__ . '/../assets/js/app.js');
if (!preg_match("/var act = window\.pushAutoAction\(Notification\.permission/", $js)) {
    $badAuto[] = 'app.js — اشتراکِ خودکار از pushAutoAction() تصمیم نمی‌گیرد';
}
if (!preg_match("/pOff\.addEventListener\('click'[\s\S]{0,600}setItem\(PUSH_OFF_KEY, '1'\)/", $js)) {
    $badAuto[] = 'app.js — «خاموش کردن» نشانه‌ی صریح نمی‌زند؛ اشتراکِ خودکار دوباره روشنش می‌کند';
}
if (!preg_match("/pOn\.addEventListener\('click'[\s\S]{0,300}removeItem\(PUSH_OFF_KEY\)/", $js)) {
    $badAuto[] = 'app.js — «روشن کردن» نشانه‌ی خاموش را برنمی‌دارد';
}
if (!preg_match("/postParam\('action'\) === 'key'/", (string)@file_get_contents(__DIR__ . '/../api/push_subscribe.php'))) {
    $badAuto[] = 'push_subscribe.php — action=key نیست؛ اشتراکِ خودکار کلید ندارد';
}
if (!preg_match("/Notification\.requestPermission\(\)[\s\S]{0,200}\}, \{ once: true, capture: true \}\)/", $js)) {
    $badAuto[] = 'app.js — درخواستِ اجازه‌ی خودکار به اولین تپ بسته نیست';
}
T::bulk(5, $badAuto, '⛔ اعلان روی گوشی پیش‌فرض روشن: اشتراکِ خودکار، و «خاموش» محترم');

// ---------------------------------------------------------------
// ⛔ قاعده ۶۲ — nginx-var.sh جای درج را از ساختارِ فایل پیدا کند.
//    نسخه‌ی اول به خطِ `^~ /tests/` لنگر می‌انداخت و فایلِ سایتِ سرورِ
//    واقعی (ساخته‌شده با vps-setup.shِ قدیمی) آن خط را نداشت، پس var/
//    باز ماند. رفتار در tests/test_nginx_var.php سنجیده می‌شود ولی آن
//    تست root می‌خواهد؛ این قاعده شکل را روی هر ماشینی نگه می‌دارد.
// ---------------------------------------------------------------
T::group('قاعده ۶۲ — nginx-var.sh روی فایلِ سایتِ قدیمی');
$badVar = [];
$nv = (string)@file_get_contents(__DIR__ . '/../deploy/nginx-var.sh');
$nvCode = (string)preg_replace('/^\s*#.*$/m', '', $nv);
if (preg_match('#/\\\\/tests\\\\/#', $nvCode) || strpos($nvCode, '/tests\//') !== false) {
    $badVar[] = 'nginx-var.sh — دوباره به خطِ /tests/ لنگر انداخته (روی نصبِ قدیمی نیست)';
}
if (strpos($nvCode, 'depth == 1') === false) {
    $badVar[] = 'nginx-var.sh — عمقِ آکولاد سنجیده نمی‌شود؛ درج داخلِ location تودرتوی /uploads/ می‌افتاد';
}
if (!preg_match('/PREFIXES=\(([^)]*)\)/', $nvCode, $pm)) {
    $badVar[] = 'nginx-var.sh — فهرستِ PREFIXES نیست';
} else {
    $vps = (string)@file_get_contents(__DIR__ . '/../deploy/vps-setup.sh');
    foreach (preg_split('/\s+/', trim($pm[1])) as $px) {
        if (!preg_match('#location \^~ /' . preg_quote($px, '#') . '/\s#', $vps)) {
            $badVar[] = "vps-setup.sh — /{$px}/ در نصبِ تازه بسته نیست ولی nginx-var.sh آن را اضافه می‌کند";
        }
    }
    foreach (['var', 'deploy', 'tests', 'mobile'] as $px) {
        if (!preg_match('/\b' . $px . '\b/', $pm[1])) { $badVar[] = "nginx-var.sh — /{$px}/ در PREFIXES نیست"; }
    }
}
if (preg_match('/awk\s+-v\s+block=/', $nvCode)) {
    $badVar[] = 'nginx-var.sh — awk -v block= دنباله‌های escape را بی‌صدا عوض می‌کند (درسِ nginx-realip.sh)';
}
$posBak = strpos($nvCode, 'cp -a "$SITE_FILE" "$BACKUP"');
$posIns = strpos($nvCode, '"$INSERTED" == "0"');
if ($posBak === false || $posIns === false || $posBak < $posIns) {
    $badVar[] = 'nginx-var.sh — پشتیبان پیش از یافتنِ جای درج ساخته می‌شود';
}
T::bulk(6, $badVar, '⛔ nginx-var.sh: بی‌لنگرِ /tests/، عمقِ آکولاد، پیشوندهای هم‌سان با نصبِ تازه، و پشتیبان بعد از یافتنِ جای درج');

// ------------------------------------------------------------------
// ⛔ قاعده ۶۳ — قلم‌های صفحه‌ی خانه: یک فهرست، روشن به‌طور پیش‌فرض.
//
// خرابی‌های این قابلیت همه بی‌صدایند: قلمی که به `HOME_WIDGETS` اضافه
// شود ولی `index.php` از آن نپرسد، کلیدی می‌سازد که کاری نمی‌کند؛
// پنهان کردن با CSS به‌جای رندر نکردن، کارتِ ماه را کوچک نمی‌کرد و
// جمله‌ی خاموش هنوز جای جمله‌ی بعدی را در سقفِ سه‌تایی می‌گرفت؛ و
// نوشتنِ ستون از مسیرِ دوم، صافیِ کلیدهای ناشناخته را دور می‌زد.
// تستِ رفتاری (`test_home_widgets`) دیتابیس می‌خواهد (`T::blocked`)،
// پس روی ماشینِ بی‌دیتابیس فقط همین قاعده می‌ماند.
// ------------------------------------------------------------------
T::group('قاعده ۶۳ — قلم‌های صفحه‌ی خانه');
$badHome = [];
$hwCode  = static function (string $rel): string {
    $src = (string)@file_get_contents(__DIR__ . '/../' . $rel);
    $out = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
};
$idxSrc  = $hwCode('index.php');
$fnSrc   = $hwCode('includes/functions.php');
$profSrc = $hwCode('profile.php');
$apiSrc  = $hwCode('api/save_home_widgets.php');
require_once __DIR__ . '/../includes/functions.php';
$INSIGHT = ['overdue', 'budget', 'growth'];
foreach (array_keys(HOME_WIDGETS) as $k) {
    if (in_array($k, $INSIGHT, true)) {
        if (!preg_match('/if \(\$on\(\'' . $k . '\'\)\)/', $fnSrc)) {
            $badHome[] = "financialHighlights — جمله‌ی «{$k}» پشتِ \$on('{$k}') نیست (خاموش‌کردنش کاری نمی‌کند)";
        }
    } elseif (!preg_match('/\$hw\(\'' . $k . '\'\)/', $idxSrc)) {
        $badHome[] = "index.php — قلمِ «{$k}» از \$hw() نمی‌پرسد (کلیدی که کاری نمی‌کند)";
    }
}
if (!preg_match('/financialHighlights\([^;]*\$homeHidden[,)]/', $idxSrc)) {
    $badHome[] = 'index.php — financialHighlights() خاموش‌ها را نمی‌گیرد (جمله ساخته و بعد پنهان می‌شد)';
}
if (!preg_match('/foreach \(HOME_WIDGETS as/', $profSrc) || !preg_match('/foreach \(HOME_WIDGET_GROUPS as/', $profSrc)) {
    $badHome[] = 'profile.php — کارتِ «صفحه‌ی خانه» از HOME_WIDGETS/HOME_WIDGET_GROUPS رندر نمی‌شود (فهرستِ دوم)';
}
if (preg_match('/value="(date|meter|split|compare|overdue|budget|growth)"/', $profSrc)) {
    $badHome[] = 'profile.php — کلیدِ قلمِ خانه سخت‌کد شده';
}
if (strpos($apiSrc, 'Csrf::verifyOrFail(') === false || strpos($apiSrc, 'saveHomeHidden(') === false
    || !preg_match('/array_diff\(array_keys\(HOME_WIDGETS\)/', $apiSrc)) {
    $badHome[] = 'api/save_home_widgets.php — CSRF، saveHomeHidden()، یا حسابِ خاموش‌ها از HOME_WIDGETS نیست';
}
if (!preg_match('/function saveHomeHidden[\s\S]*?homeHiddenParse\(/', $fnSrc)) {
    $badHome[] = 'saveHomeHidden() — ورودی از homeHiddenParse() نمی‌گذرد (کلیدِ ناشناخته ذخیره می‌شد)';
}
foreach (array_merge(glob(__DIR__ . '/../*.php'), glob(__DIR__ . '/../api/*.php'), glob(__DIR__ . '/../includes/*.php'),
                     glob(__DIR__ . '/../admin/*.php'), glob(__DIR__ . '/../store/*.php'), glob(__DIR__ . '/../deploy/*.php')) as $p) {
    $rel = substr(realpath($p), strlen(realpath(__DIR__ . '/..')) + 1);
    if ($rel === 'includes/functions.php') { continue; }
    if (preg_match('/SET[^;]*\bhome_hidden\s*=/i', $hwCode($rel))) {
        $badHome[] = "{$rel} — users.home_hidden را خودش می‌نویسد؛ تنها مسیر saveHomeHidden() است";
    }
}
$cssHome = preg_replace('#/\*.*?\*/#s', '', (string)file_get_contents(__DIR__ . '/../assets/css/style.css'));
if (preg_match_all('/[^{}]*\.balance-ribbon\s*\{([^}]*)\}/', $cssHome, $rm)) {
    foreach ($rm[1] as $body) {
        if (preg_match('/(^|[;\s])(min-)?height\s*:/', $body)) {
            $badHome[] = 'style.css — .balance-ribbon ارتفاعِ ثابت گرفته (کارت با خاموش شدنِ قلم‌ها کوچک نمی‌شد)';
            break;
        }
    }
}
$jsHome = (string)file_get_contents(__DIR__ . '/../assets/js/app.js');
if (!preg_match("/apiUrl\('save_home_widgets\.php'\), \{ method: 'POST', body: new FormData\(hwForm\)/", $jsHome)
    || substr_count(substr($jsHome, (int)strpos($jsHome, 'var hwSave'), 900), 'undo()') < 2) {
    $badHome[] = 'app.js — ذخیره‌ی فوری با کلِ فرم نیست، یا در شکست کلید را برنمی‌گرداند';
}
$migHome = (string)file_get_contents(__DIR__ . '/../deploy/migrate.sh');
if (!preg_match('/^\s+migration_home_widgets\.sql$/m', $migHome) || strpos($migHome, '[migration_home_widgets.sql]="users.home_hidden"') === false) {
    $badHome[] = 'migrate.sh — migration_home_widgets.sql در MIGRATIONS یا SENTINEL نیست';
}
T::bulk(count(HOME_WIDGETS) + 10, $badHome, '⛔ قلم‌های خانه: هر کلید واقعاً چیزی را خاموش کند، یک فهرست، یک نویسنده، کارتِ بی‌ارتفاعِ ثابت');

$all = [];
foreach (['api', 'includes', 'admin', 'store', 'config', '.'] as $dir) {
    foreach (glob(__DIR__ . '/../' . $dir . '/*.php') as $p) { $all[realpath($p)] = true; }
}
foreach (array_keys($all) as $p) {
    exec('php -l ' . escapeshellarg($p) . ' 2>&1', $out, $rc);
    if ($rc !== 0) { $bad[] = basename($p) . ' — ' . implode(' ', $out); }
    $out = [];
}
T::bulk(count($all), $bad, 'همه‌ی فایل‌های PHP نحو درست دارند');

// =====================================================================
// ⛔ قاعده ۶۴ — به‌روزرسانیِ درون‌برنامه‌ای
//
// «وقتی آپدیت شد، برنامه رو باز کرد، پیام برای کاربر بره که آپدیت کنه.»
// سه تکه در سه زبان (جاوا، PHP، جاوااسکریپت) که باید با هم بخوانند؛
// `test_app_update.php` رفتار را می‌سنجد، اینجا شکل — چون آن تست به node
// و کرومیوم بند است و روی ماشینِ بی‌آن‌ها فقط همین می‌ماند.
T::group('قاعده ۶۴ — به‌روزرسانیِ درون‌برنامه‌ای');
$upBad = [];

$gradle = (string)@file_get_contents(__DIR__ . '/../mobile/app/build.gradle.kts');
$apkMeta = (string)@file_get_contents(__DIR__ . '/../includes/apk_meta.php');
preg_match('~applicationId\s*=\s*"([^"]+)"~', $gradle, $gm);
preg_match("~const\s+ANDROID_PACKAGE\s*=\s*'([^']+)'~", $apkMeta, $am);
// ⛔ مصرف‌کننده‌ی `applicationId`، نه مرجعِ دوم.
if (($gm[1] ?? 'x') !== ($am[1] ?? 'y')) {
    $upBad[] = 'ANDROID_PACKAGE در apk_meta.php با applicationId در build.gradle.kts نمی‌خواند؛'
             . ' نوارِ به‌روزرسانی هرگز نمی‌آید (بسته «دیگر» دیده می‌شود)';
}

// جاوا: نسخه روی آدرسِ باز شدن، از PackageManager، و در getLaunchingUrl.
$lau = (string)@file_get_contents(__DIR__ . '/../mobile/app/src/main/java/'
     . str_replace('.', '/', $gm[1] ?? 'ir.stland.hesabland') . '/HesabLauncherActivity.java');
$lauNC = preg_replace('~/\*.*?\*/|(?<!:)//[^\n]*~s', '', $lau) ?? $lau;
if (!preg_match('~PARAM_APP_VERSION\s*=\s*"appv"\s*;~', $lauNC)) {
    $upBad[] = 'HesabLauncherActivity — PARAM_APP_VERSION = "appv" نیست؛ سرور نسخه را نمی‌شناسد';
}
if (!preg_match('~Uri\s+getLaunchingUrl\(\)\s*\{.*?return\s+withAppVersion\(u\);~s', $lauNC)) {
    $upBad[] = 'HesabLauncherActivity — getLaunchingUrl() نسخه را روی آدرس نمی‌گذارد';
}
if (!str_contains($lauNC, 'getPackageManager().getPackageInfo(getPackageName()')) {
    $upBad[] = 'HesabLauncherActivity — نسخه از PackageManager خوانده نمی‌شود (BuildConfig در AGP 8 پیش‌فرض ساخته نمی‌شود)';
}

// PHP: کوکی در initSession (پیش از هر ریدایرکت)، httponly، فقط رقم.
$authSrc = (string)@file_get_contents(__DIR__ . '/../includes/auth.php');
$isPos = strpos($authSrc, 'public static function initSession()');
$isEnd = $isPos === false ? false : strpos($authSrc, 'public static function', $isPos + 10);
if ($isPos === false || $isEnd === false
    || !str_contains(substr($authSrc, $isPos, $isEnd - $isPos), 'self::captureAppVersion();')) {
    $upBad[] = 'auth.php — initSession() کوکیِ نسخه را نمی‌گذارد؛ پشتِ ریدایرکتِ ورود نسخه گم می‌شود';
}
$cav = strpos($authSrc, 'function captureAppVersion()');
$cavBody = $cav === false ? '' : substr($authSrc, $cav, 900);
if (!str_contains($cavBody, "'httponly' => true") || !str_contains($cavBody, '[1-9][0-9]{0,8}')) {
    $upBad[] = 'auth.php — کوکیِ نسخه httponly نیست یا ورودیِ غیرِعددی را می‌پذیرد';
}

// header.php: فقط برای اندروید و فقط از androidApkLatest().
$hdr = (string)@file_get_contents(__DIR__ . '/../includes/header.php');
if (!preg_match('~isAndroidRequest\(\)\s*\?\s*androidApkLatest\(\)~', $hdr)
    || !str_contains($hdr, 'name="apk-latest"') || !str_contains($hdr, 'Auth::appVersion()')) {
    $upBad[] = 'header.php — متای apk-latest از androidApkLatest() و فقط برای اندروید رندر نمی‌شود';
}

// functions.php: عدد از خودِ فایل، و نگهبانِ بسته.
$fnSrc = (string)@file_get_contents(__DIR__ . '/../includes/functions.php');
$alf = strpos($fnSrc, 'function apkLatestFrom(');
$alfBody = $alf === false ? '' : substr($fnSrc, $alf, 1800);
if (!str_contains($alfBody, 'apkManifestInfo($apk)') || !str_contains($alfBody, '!== ANDROID_PACKAGE')) {
    $upBad[] = 'functions.php — apkLatestFrom() نسخه را از خودِ APK نمی‌خواند یا بسته را نمی‌سنجد';
}

// app.js: تصمیم خالص و بیرون از DOMContentLoaded، نوار با textContent و شناور نه.
$js = (string)@file_get_contents(__DIR__ . '/../assets/js/app.js');
$dcl = strpos($js, "document.addEventListener('DOMContentLoaded'");
$due = strpos($js, 'window.appUpdateDue = function');
if ($due === false || $dcl === false || $due > $dcl) {
    $upBad[] = 'app.js — appUpdateDue بیرون از DOMContentLoaded نیست (در node آزمودنی نمی‌ماند)';
}
$bp = strpos($js, '(function appUpdateBar()');
$barBody = $bp === false ? '' : substr($js, $bp, (int)(strpos($js, '})();', $bp) - $bp));
if ($barBody === '' || !str_contains($barBody, 'window.appUpdateDue(')) {
    $upBad[] = 'app.js — نوار از appUpdateDue() تصمیم نمی‌گیرد';
}
if (preg_match('~(title|note|cta)\.innerHTML~', $barBody) || str_contains($barBody, 'position')) {
    $upBad[] = 'app.js — متنِ نوار با innerHTML نوشته می‌شود یا نوار شناور است';
}
if (!str_contains($barBody, 'location.hash')) {
    $upBad[] = 'app.js — پاک کردنِ appv فرگمنتِ پیامک را نگه نمی‌دارد';
}

// ساختِ گیت‌هاب: خواننده‌ی سایت روی APKِ واقعی با aapt2 مقایسه می‌شود.
$wf = (string)@file_get_contents(__DIR__ . '/../.github/workflows/android.yml');
if (!str_contains($wf, 'php ../deploy/apk-version.php --expect "$APK"') || !str_contains($wf, '"$SC" != "$VC"')) {
    $upBad[] = 'android.yml — خواننده‌ی نسخه‌ی سایت روی APKِ واقعی با aapt2 سنجیده نمی‌شود';
}
foreach (["- 'includes/apk_meta.php'", "- 'deploy/apk-version.php'"] as $pth) {
    if (!str_contains($wf, $pth)) { $upBad[] = "android.yml — «{$pth}» در paths نیست؛ تغییرِ تجزیه‌گر ساخت را راه نمی‌اندازد"; }
}

// apk-publish.sh: بسته‌ی دیگر منتشر نشود.
$pub = (string)@file_get_contents(__DIR__ . '/../deploy/apk-publish.sh');
if (!str_contains($pub, 'apk-version.php" --expect "$SRC"') || !preg_match('~VER_RC"\s*=\s*"2"\s*\]; then.*?exit 1~s', $pub)) {
    $upBad[] = 'apk-publish.sh — APKِ اپِ دیگر را منتشر می‌کند';
}

T::bulk(14, $upBad, 'زنجیره‌ی به‌روزرسانی با هم می‌خواند');

// ⛔ به‌روزرسانی با یک تپ — «روش بزنم و بلافاصله آپدیت بشه».
//    خرابی‌های این مسیر همه بی‌صدایند: دکمه‌ای که به صفحه‌ی بومیِ
//    ناموجود می‌رود (اپِ قدیمی) و هیچ اتفاقی نمی‌افتد؛ صفحه‌ای که آدرسِ
//    فایل را از لینک می‌گیرد و هر سایتی می‌تواند فایلِ دلخواهش را جلوی
//    پنجره‌ی نصب بگذارد؛ و پنجره‌ی نصبی که بدونِ مجوز اصلاً باز نمی‌شود.
$oneBad = [];
$ua = (string)@file_get_contents(__DIR__ . '/../mobile/app/src/main/java/ir/stland/hesabland/UpdateActivity.java');
$uaCode = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $ua);
if ($ua === '') {
    $oneBad[] = 'UpdateActivity.java نیست';
} else {
    if (!str_contains($uaCode, 'R.string.launch_url') || !str_contains($uaCode, 'APK_PATH')) {
        $oneBad[] = 'UpdateActivity — آدرسِ فایل از دامنه‌ی خودِ اپ ساخته نمی‌شود';
    }
    if (preg_match('~Uri\.parse\(\s*(getIntent|data)|getData\(\)\.toString|getStringExtra~', $uaCode)) {
        $oneBad[] = 'UpdateActivity — آدرس یا بخشی از آن از لینکِ ورودی خوانده می‌شود';
    }
    if (!str_contains($uaCode, 'q.matches("[0-9]{1,9}")')) {
        $oneBad[] = 'UpdateActivity — `v` بی‌سنجش (فقط رقم) پذیرفته می‌شود';
    }
    foreach (['getUriForDownloadedFile', 'FLAG_GRANT_READ_URI_PERMISSION', 'canRequestPackageInstalls',
              'ACTION_MANAGE_UNKNOWN_APP_SOURCES'] as $need) {
        if (!str_contains($uaCode, $need)) { $oneBad[] = "UpdateActivity — «{$need}» نیست"; }
    }
    // ⚠ پنجره تا تعریفِ متدِ بعدی بریده می‌شود؛ بدونِ آن، `.*?` تا خودِ
    //   تعریفِ `openInBrowser()` پایین‌تر می‌رفت و بررسی پوچ بود (جهش
    //   نشانش داد).
    preg_match('~private void failed\(\)\s*\{((?:(?!private void).)*)~s', $uaCode, $fb);
    if (!$fb || !str_contains($fb[1], 'openInBrowser()')) {
        $oneBad[] = 'UpdateActivity — شکستِ دریافت راهِ مرورگر را نمی‌دهد (دکمه‌ی بی‌کار)';
    }
    if (preg_match('~content://sms|Telephony|SmsMessage~', $uaCode)) {
        $oneBad[] = 'UpdateActivity — به پیامک دست می‌زند';
    }
    // ⛔ نصب با جلسه‌ی `PackageInstaller` و منبعِ «فروشگاه» — نصبِ «از فایل»
    //    اپ را «محدود» می‌کرد و مجوزِ پیامک بی‌صدا رد می‌شد (مهر ۱۴۰۵). و نتیجه‌ی
    //    نصب فقط برای جلسه‌ی **خودِ** اپ پذیرفته می‌شود (این صفحه exported است).
    $isb = strpos($uaCode, 'private boolean installSession()');
    $isBody = $isb === false ? '' : substr($uaCode, $isb, 2200);
    if (!str_contains($isBody, 'sp.setPackageSource(PackageInstaller.PACKAGE_SOURCE_STORE)')
        || !str_contains($isBody, 'session.commit(')) {
        $oneBad[] = 'UpdateActivity — نصب با جلسه و منبعِ «فروشگاه» نیست؛ اپ «محدود» می‌ماند';
    }
    if (!preg_match('~private void install\(\)\s*\{.*?if \(installSession\(\)\) \{ return; \}.*?ACTION_VIEW~s', $uaCode)) {
        $oneBad[] = 'UpdateActivity — اول جلسه‌ی نصب، بعد پنجره‌ی قدیمی نیست';
    }
    if (!preg_match('~boolean ours = info != null && getPackageName\(\)\.equals\(info\.getInstallerPackageName\(\)\).*?if \(st == PackageInstaller\.STATUS_PENDING_USER_ACTION && ours\)~s', $uaCode)) {
        $oneBad[] = 'UpdateActivity — نتیجه‌ی نصبِ جلسه‌ی دیگران پذیرفته می‌شود (هدایتِ intent)';
    }
}
$mf = (string)@file_get_contents(__DIR__ . '/../mobile/app/src/main/AndroidManifest.xml');
if (!str_contains($mf, 'android.permission.REQUEST_INSTALL_PACKAGES')) {
    $oneBad[] = 'manifest — REQUEST_INSTALL_PACKAGES نیست؛ پنجره‌ی نصب بی‌صدا باز نمی‌شود';
}
if (!preg_match('~android:name="\.UpdateActivity".*?ir\.stland\.hesabland\.UPDATE.*?android:scheme="hesabland"\s+android:host="update"~s', $mf)) {
    $oneBad[] = 'manifest — UpdateActivity با action/scheme ای که لینک می‌سازد ثبت نشده';
}
$ahp = strpos($js, 'window.appUpdateHref = function');
if ($ahp === false || $dcl === false || $ahp > $dcl) {
    $oneBad[] = 'app.js — appUpdateHref بیرون از DOMContentLoaded نیست';
} else {
    $ahBody = substr($js, $ahp, 900);
    if (!str_contains($ahBody, "'.UPDATE;package='") || !str_contains($ahBody, 'S.browser_fallback_url=')) {
        $oneBad[] = 'app.js — لینکِ intent یا بازگشتِ مرورگر ساخته نمی‌شود';
    }
}
if (!str_contains($barBody, 'window.appUpdateHref(')) {
    $oneBad[] = 'app.js — نوار از appUpdateHref() آدرس نمی‌گیرد';
}
$hdr = (string)@file_get_contents(__DIR__ . '/../includes/header.php');
if (!str_contains($hdr, 'data-package=')) {
    $oneBad[] = 'header.php — نامِ بسته به نوار نمی‌رسد';
}
// ⛔ کفِ «اپِ بومی‌دار» نباید از نسخه‌ی خودِ همین APK بالاتر باشد، وگرنه
//    اپِ تازه هم همان دانلودِ مرورگر را می‌گیرد.
$gr = (string)@file_get_contents(__DIR__ . '/../mobile/app/build.gradle.kts');
preg_match('~versionCode\s*=\s*(\d+)~', $gr, $gv);
preg_match('~window\.APP_UPDATE_NATIVE_MIN\s*=\s*(\d+)~', $js, $nm);
if (!$gv || !$nm || (int)$nm[1] > (int)$gv[1]) {
    $oneBad[] = 'APP_UPDATE_NATIVE_MIN از versionCodeِ اپ بالاتر است';
}
T::bulk(16, $oneBad, 'به‌روزرسانی با یک تپ: اپ خودش می‌گیرد و پنجره‌ی نصب را باز می‌کند');

// ---------------------------------------------------------------
// ⛔ قاعده ۶۵ — صفِ پیامکِ بانک هیچ چیزی را باز نمی‌کند
//
//    **گزارشِ مالکِ نصب:** «خواندن اس ام اس معضل شده، یکسره روی صفحه‌ست،
//    مخصوصاً وقتی بخش حساب‌ها می‌رم و هیچ کاری نمیشه کرد». صفِ قبلی
//    (`sessionStorage` + `smsQueueNext`) با هر بارگذاری شیتِ ثبت را باز
//    می‌کرد. `test_sms_batch.php` رفتار را در کرومیوم می‌سنجد؛ این قاعده
//    شکل را، چون کرومیوم ابزارِ اختیاری است (`T::skip`).
T::group('قاعده ۶۵ — صفِ پیامکِ بانک هیچ چیزی را باز نمی‌کند');
$qBad = [];
$js65 = (string)@file_get_contents(__DIR__ . '/../assets/js/app.js');
$dcl65 = strpos($js65, "document.addEventListener('DOMContentLoaded'");
$bp65 = strpos($js65, 'function smsProcessBatch(');
$batch65 = $bp65 === false ? '' : substr($js65, $bp65, (int)(strpos($js65, 'function takeSmsFragment(', $bp65) - $bp65));
if ($batch65 === '') {
    $qBad[] = 'app.js — smsProcessBatch() نیست';
} else {
    if (str_contains($batch65, 'openWithSms(') || str_contains($batch65, "classList.add('show')")) {
        $qBad[] = 'app.js — صف شیتِ ثبت را خودش باز می‌کند';
    }
    // ⛔ مانده فقط وقتی حساب با نشانه‌ی خودِ پیامک پیدا شده — نه «تک‌حساب».
    if (!str_contains($batch65, "window.SMS_BALANCE_HOW.indexOf(m.how) !== -1")) {
        $qBad[] = 'app.js — مانده‌ی پیامک بدونِ تطبیقِ واقعیِ حساب روی حساب می‌نشیند';
    }
    if (!str_contains($batch65, 'window.smsAutoOk(') || !str_contains($batch65, 'window.smsMatchWallet(')) {
        $qBad[] = 'app.js — صف از smsAutoOk/smsMatchWallet تصمیم نمی‌گیرد';
    }
    if (!str_contains($batch65, 'smsAlreadyAuto(fp)')) {
        $qBad[] = 'app.js — صف نگهبانِ تکراری ندارد';
    }
}
if (preg_match('~function smsQueueNext|sessionStorage\.setItem\(\s*SMS_Q_KEY~', $js65)) {
    $qBad[] = 'app.js — صفِ شیت‌به‌شیتِ قدیمی برگشته';
}
// کارتِ بررسی فقط روی خانه ماندگار است؛ جای دیگر فقط `flash`ِ همان نوبت.
$rp65 = strpos($js65, 'function renderSmsPending(');
$rpBody = $rp65 === false ? '' : substr($js65, $rp65, 700);
if (!str_contains($rpBody, "getElementById('smsPendingSlot')") || !str_contains($rpBody, 'if (!flash) { return; }')) {
    $qBad[] = 'app.js — کارتِ بررسی بیرون از خانه هم ماندگار است';
}
$idx65 = (string)@file_get_contents(__DIR__ . '/../index.php');
if (!str_contains($idx65, 'id="smsPendingSlot"')) {
    $qBad[] = 'index.php — جای کارتِ بررسی نیست';
}
// ⛔ پارسر در `sms-core.js` است (هسته‌ی مشترکِ سایت و کارگرِ اندروید) و
//    هیچ DOMContentLoaded ای ندارد — خالص، در node آزمودنی.
$core65 = (string)@file_get_contents(__DIR__ . '/../assets/js/sms-core.js');
$core65NC = (string)preg_replace('~/\*.*?\*/|(?<![:\'"])//[^\n]*~s', '', $core65);
if (!str_contains($core65, 'window.smsMatchWallet = function') || str_contains($core65NC, 'DOMContentLoaded')
    || str_contains($js65, 'window.smsMatchWallet = function')) {
    $qBad[] = 'sms-core.js — smsMatchWallet تنها در هسته‌ی خالص نیست (در node آزمودنی نمی‌ماند)';
}
// ⛔ فهرستِ «مانده روی حساب می‌نشیند» در هسته و سرور یکی است و `single` ندارد.
require_once __DIR__ . '/../includes/sms_sync.php';
$bh65 = preg_match("~window\.SMS_BALANCE_HOW = \[([^\]]*)\];~", $core65, $bhm)
    ? array_map(static fn($x) => trim($x, " '\""), explode(',', $bhm[1])) : [];
if ($bh65 !== SmsSync::BALANCE_HOW || in_array('single', $bh65, true)) {
    $qBad[] = 'sms-core.js/sms_sync.php — SMS_BALANCE_HOW با SmsSync::BALANCE_HOW یکی نیست (یا تک‌حساب مانده می‌گیرد)';
}
$sheet65 = (string)@file_get_contents(__DIR__ . '/../includes/add_tx_sheet.php');
if (!str_contains($sheet65, 'window.SMS_WALLETS = <?= json_encode($__smsWallets')) {
    $qBad[] = 'add_tx_sheet.php — نشانه‌های حساب (walletSmsKeys) به صفحه نمی‌رسد';
}
// ⛔ شماره‌ی کامل هرگز: فقط چهار رقمِ آخر.
$fn65 = (string)@file_get_contents(__DIR__ . '/../includes/functions.php');
$wk65 = strpos($fn65, 'function walletSmsKeys(');
$wkBody = $wk65 === false ? '' : substr($fn65, $wk65, 3000);
if ($wkBody === '' || !str_contains($wkBody, 'substr($digits, -4)')) {
    $qBad[] = 'functions.php — walletSmsKeys بیش از چهار رقمِ آخر بیرون می‌دهد';
}
T::bulk(12, $qBad, 'صف بی‌صدا ثبت می‌کند، نامطمئن را فقط روی خانه نگه می‌دارد');

// ⛔ «یک بار بپرس، بعد خودکار» (گزارشِ مالکِ نصب: «مگه نباید بپرسه از چه
//    حسابی و از دفعه بعد خودش وارد اون حساب کنه؟»). منطق در `SmsSync::learn()`
//    و `smsSourceKeys()` است و آنجا آزموده می‌شود؛ این‌جا فقط سیم‌کشیِ صفحه.
$lBad = [];
$goAt = strpos($js65, "go.addEventListener('click'");
$goEnd = $goAt === false ? false : strpos($js65, '// ---- انتخابِ یک عنوانِ قبلی', $goAt);
$goBody = $goAt === false || $goEnd === false ? '' : substr($js65, $goAt, $goEnd - $goAt);
// حساب واقعاً پرسیده می‌شود: گزینه‌ی خالیِ اجباری، نه «اولین حساب» بی‌صدا.
if (!str_contains($goBody, "walletEl.value = '';") || !str_contains($goBody, 'walletEl.required = true;')) {
    $lBad[] = 'app.js — پیامکِ بی‌حساب حساب را نمی‌پرسد (اولین حساب بی‌صدا یاد گرفته می‌شود)';
}
if (!str_contains($goBody, 'window.smsSourceKeys(ta.value, r)') || !str_contains($goBody, 'quickAddForm.dataset.smsLearn')) {
    $lBad[] = 'app.js — ثبتِ دستیِ پیامک نشانه‌ها را برای یادگیری نگه نمی‌دارد';
}
$subAt = strpos($js65, "quickAddForm.addEventListener('submit'");
$subBody = $subAt === false ? '' : substr($js65, $subAt, 16000);
if (!str_contains($subBody, 'if (auto) { smsLearn = null; }') || !str_contains($subBody, 'smsLearnWallet(chosen, smsLearn)')) {
    $lBad[] = 'app.js — بعد از ثبت یاد گرفته نمی‌شود (یا ثبتِ خودکار هم «یاد» می‌دهد)';
}
if (!preg_match("~function smsLearnWallet\(.*?apiUrl\('sms_learn\.php'\).*?smsPostBalance\(walletId~s", $js65)) {
    $lBad[] = 'app.js — smsLearnWallet به sms_learn.php نمی‌رود یا مانده را روی حسابِ انتخاب‌شده نمی‌گذارد';
}
$learnApi = (string)@file_get_contents(__DIR__ . '/../api/sms_learn.php');
if (!str_contains($learnApi, 'SmsSync::learn($userId, $walletId, $keys)') || str_contains($learnApi, "postParam('text')")) {
    $lBad[] = 'api/sms_learn.php — یادگیری از SmsSync::learn نیست (یا متن می‌گیرد)';
}
$sw65 = (string)@file_get_contents(__DIR__ . '/../api/save_wallet.php');
$wp65 = (string)@file_get_contents(__DIR__ . '/../wallets.php');
if (!str_contains($sw65, "if (postParam('sms_forget') === '1') { smsForgetWallet(\$userId, \$walletId); }")
    || !str_contains($wp65, 'name="sms_forget"') || !str_contains($wp65, 'data-sms-learned=')) {
    $lBad[] = 'wallets.php/save_wallet.php — راهِ «فراموش کن» برای انتخابِ اشتباه نیست';
}
// ⛔ دسته از انتخابِ قبلیِ کاربر برای همین پذیرنده — هر سه مسیر (صف، فرم، اپ).
if (!preg_match("~function smsPostTx\(.*?smsCategoryFor\(r\).*?fd\.set\('category_id', lc~s", $js65)
    || !str_contains($goBody, 'var learnedCat = smsCategoryFor(r);')
    || !str_contains($js65, 'return window.smsLearnedCategory(r.note, r.type, titles);')) {
    $lBad[] = 'app.js — دسته‌ی یادگرفته در صف یا فرمِ پیامک به کار نمی‌رود';
}
$ss65 = (string)@file_get_contents(__DIR__ . '/../includes/sms_sync.php');
if (!str_contains($ss65, "'category_id' => (string)(smsLearnedCategoryId(\$userId, \$type, \$note) ?? ''),")) {
    $lBad[] = 'sms_sync.php — کارگرِ اپ دسته‌ی یادگرفته را نمی‌گذارد';
}
T::bulk(8, $lBad, '«یک بار بپرس، بعد خودکار»: حساب پرسیده، یاد گرفته و قابلِ فراموشی است؛ دسته از پذیرنده');

// ---------------------------------------------------------------
// قاعده ۶۶ — خرید امانی/نسیه و فروش نسیه: پول تکان نمی‌خورد، طلب/بدهی
//             از یک جا ساخته می‌شود، و ردیفِ پرداخت‌دار پاک نمی‌شود.
//
//    **خواسته‌ی مالکِ نصب:** «از علی کالا خریدم ولی پول ندادم، تو طلب و
//    بدهی بهش بدهکار بشم؛ به محمد فروختم، طلبکار بشم». `test_trade_credit`
//    رفتار را با HTTP می‌سنجد؛ این قاعده شکل را، چون آن تست بدونِ دیتابیس
//    `T::blocked` است.
T::group('قاعده ۶۶ — معاملاتِ امانی/نسیه');
$cBad = [];
$rd66 = fn(string $f) => (string)@file_get_contents(__DIR__ . '/../' . $f);
$strip66 = function (string $src): string {
    $o = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t) && ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) { continue; }
        $o .= is_array($t) ? $t[1] : $t;
    }
    return $o;
};
$tc66 = $strip66($rd66('includes/trade_credit.php'));
if ($tc66 === '') {
    $cBad[] = 'includes/trade_credit.php نیست';
} else {
    // ⛔ هر کوئری شرطِ کاربر دارد (قانونِ جداسازی)
    // کلِ آرگومانِ prepare، نه فقط اولین رشته: بعضی کوئری‌ها نامِ ستون را
    // وسطش می‌چسبانند و شرطِ کاربر در تکه‌ی دوم است.
    preg_match_all('~prepare\((.*?)\)\s*(?:->|;)~s', $tc66, $qs66);
    $nq = 0;
    foreach ($qs66[1] as $q) {
        $nq++;
        if (!preg_match('~^\s*[\x27"]INSERT~i', $q) && !str_contains($q, 'user_id = :u')) {
            $cBad[] = 'trade_credit.php — کوئریِ بی‌شرطِ کاربر: ' . mb_substr(trim($q), 0, 60);
        }
    }
    if ($nq < 4) { $cBad[] = 'trade_credit.php — کوئری‌ها پیدا نشدند (بررسی کور است)'; }
    // ⛔ هیچ پولی جابه‌جا نمی‌شود: نه حساب، نه تراکنش
    if (preg_match('~wallet_id|INTO\s+transactions|debt_payments\s*\(~i', $tc66)) {
        $cBad[] = 'trade_credit.php — به حساب یا تراکنش دست می‌زند (پای نسیه پول ندارد)';
    }
    // ⛔ ردیفِ پرداخت‌دار فقط پیوندش برداشته می‌شود — شرط پیش از DELETE
    $rp = strpos($tc66, 'function tradeCreditRelease(');
    $rb = $rp === false ? '' : substr($tc66, $rp, (int)(strpos($tc66, 'function ', $rp + 10) - $rp));
    $pc = strpos($rb, 'FROM debt_payments');
    $dl = strpos($rb, 'DELETE FROM debts');
    // ⚠ «کوئری هست» کافی نیست — جوابش باید شرطِ نگه داشتن باشد؛ وگرنه
    //   جهشِ `if (false)` زنده می‌ماند (و ماند).
    $kp = strpos($rb, 'if ((int)$paid->fetchColumn() > 0');
    if ($pc === false || $dl === false || $pc > $dl || $kp === false || $kp > $dl
        || !str_contains($rb, "return 'kept'")) {
        $cBad[] = 'trade_credit.php — tradeCreditRelease پیش از حذف، پرداخت را نمی‌سنجد';
    }
    if (!str_contains($tc66, 'if ($amount < $paid)')) {
        $cBad[] = 'trade_credit.php — مبلغِ کمتر از پرداخت‌شده رد نمی‌شود';
    }
}
$sv66 = $strip66($rd66('api/save_trade.php'));
if (!str_contains($sv66, 'if ($onCredit) { $walletId = 0; }')) {
    $cBad[] = 'api/save_trade.php — خریدِ امانی هنوز از حساب برداشت می‌کند';
}
if (!str_contains($sv66, "tradeCreditUpsert(\$pdo, \$userId, 'buy'") || !str_contains($sv66, "tradeCreditRelease(\$pdo, \$userId, 'buy'")) {
    $cBad[] = 'api/save_trade.php — بدهیِ پیوندی ساخته/پس گرفته نمی‌شود';
}
$sl66 = $strip66($rd66('api/sell_trade.php'));
if (!str_contains($sl66, "'w' => \$onCredit ? null : resolveWalletId(")) {
    $cBad[] = 'api/sell_trade.php — فروشِ نسیه به کیف پول واریز می‌شود';
}
if (!str_contains($sl66, "tradeCreditUpsert(\$pdo, \$userId, 'sale'")) {
    $cBad[] = 'api/sell_trade.php — طلبِ فروشِ نسیه ساخته نمی‌شود';
}
foreach (['api/delete_trade.php' => ['tradeCreditReleaseTrade(', 'DELETE FROM trades'],
          'api/delete_trade_sale.php' => ["tradeCreditRelease(\$pdo, \$userId, 'sale'", 'DELETE FROM trade_sales']] as $f => [$call, $del]) {
    $src = $strip66($rd66($f));
    $a = strpos($src, $call); $b = strpos($src, $del);
    if ($a === false || $b === false || $a > $b) {
        $cBad[] = "{$f} — طلب/بدهیِ پیوندی پیش از حذف پس گرفته نمی‌شود (یتیم می‌ماند)";
    }
}
// ⛔ تنها سازنده‌ی ردیفِ پیوندی همین فایل است
foreach (array_merge(glob(__DIR__ . '/../api/*.php') ?: [], glob(__DIR__ . '/../includes/*.php') ?: []) as $f) {
    if (basename($f) === 'trade_credit.php') { continue; }
    if (preg_match('~INSERT\s+INTO\s+debts\s*\([^)]*\btrade_(sale_)?id\b~i', $strip66((string)file_get_contents($f)))) {
        $cBad[] = basename($f) . ' — ردیفِ پیوندیِ طلب/بدهی را خودش می‌سازد (نسخه‌ی دوم)';
    }
}
$mg66 = $rd66('migration_trade_credit.sql');
if (substr_count($mg66, 'FOREIGN KEY') < 2) {
    $cBad[] = 'migration_trade_credit.sql — پیوندها کلیدِ خارجی نیستند (بازگرداندنِ بکاپ نگاشتشان نمی‌کند)';
}
$ms66 = $rd66('deploy/migrate.sh');
if (!preg_match('~^\s+migration_trade_credit\.sql$~m', $ms66) || !str_contains($ms66, '[migration_trade_credit.sql]=')) {
    $cBad[] = 'deploy/migrate.sh — migration_trade_credit در MIGRATIONS/SENTINEL نیست';
}
$tp66 = $rd66('trades.php');
if (!preg_match('~<\?php if \(\$creditReady\): \?>\s*<div class="form-group">\s*<label for="trade_pay_mode"~', $tp66)
    || !preg_match('~<\?php if \(\$creditReady\): \?>\s*<div class="form-group">\s*<label for="sell_pay_mode"~', $tp66)) {
    $cBad[] = 'trades.php — گزینه‌ی نسیه بدونِ نگهبانِ migration رندر می‌شود';
}
T::bulk(14, $cBad, 'نسیه پول را تکان نمی‌دهد و طلب/بدهی از یک جا هم‌گام می‌شود');

// ---------------------------------------------------------------
// قاعده ۶۷ — حلقه‌ی پرداختِ جزئیِ طلب/بدهی، و برچسبِ امانی/نسیه
//
// ⛔ چرا شکل هم لازم است: `test_debt_ring` بدونِ دیتابیس `T::blocked`
//    است. این قاعده می‌بندد: نوارِ خطی به کارت برنگردد، حلقه و رنگش
//    فقط از `debtPaidRing()` بیاید، عددِ درشتِ شاخه‌ی جزئی باقیمانده
//    باشد، رنگ پیوسته از فام باشد (نه چند پله) و حلقه در RTL برعکس نشود.
T::group('قاعده ۶۷ — حلقه‌ی پرداختِ جزئی');
$rBad = [];
$db67 = (string)file_get_contents(__DIR__ . '/../debts.php');
$fn67 = '';
if (preg_match('~function renderDebtCard\(.*?\n\}\n~s', $db67, $m67)) { $fn67 = $m67[0]; }
if ($fn67 === '') { $rBad[] = 'debts.php — renderDebtCard() پیدا نشد (قاعده کور شد)'; }
if (str_contains($fn67, 'budget-bar')) {
    $rBad[] = 'debts.php — نوارِ خطی به کارتِ طلب/بدهی برگشت';
}
if (!preg_match('~\$ring\s*=\s*debtPaidRing\(\$paid,~', $fn67)) {
    $rBad[] = 'debts.php — درصد/رنگِ حلقه از debtPaidRing() نمی‌آید (نسخه‌ی دوم)';
}
if (!preg_match('~--ring-h:<\?= \(int\)\$ring\[\'hue\'\]~', $fn67)
    || !preg_match('~stroke-dasharray="<\?= \(int\)\$ring\[\'pct\'\] \?> 100"~', $fn67)) {
    $rBad[] = 'debts.php — حلقه رنگ یا پر شدنش را از $ring نمی‌گیرد';
}
// شاخه‌ی جزئی: عددِ درشت = باقیمانده، مبلغِ اولیه در debt-amount-was
if (!preg_match('~<\?php if \(\$partial\): \?>.*?class="debt-amount"><\?= formatMoney\(debtRemaining\(\$d\)\)~s', $fn67)
    || !preg_match('~class="debt-amount-was".*?formatMoney\(\$d\[\'amount\'\]\)~s', $fn67)) {
    $rBad[] = 'debts.php — در پرداختِ جزئی عددِ درشت باقیمانده نیست یا مبلغِ اولیه کوچک زیرش نیست';
}
if (!preg_match('~\$partial\s*=\s*\$paid > 0 && !\(int\)\$d\[\'is_settled\'\]~', $fn67)) {
    $rBad[] = 'debts.php — حلقه بدونِ شرطِ «پرداخت‌دار و تسویه‌نشده» رندر می‌شود';
}
$fx67 = (string)file_get_contents(__DIR__ . '/../includes/functions.php');
if (!preg_match('~function debtPaidRing\(.*?\n\}~s', $fx67, $rf67)
    || !preg_match('~\'hue\'\s*=>\s*\(int\)round\(\$pct \* 1\.2\)~', $rf67[0] ?? '')
    || !str_contains($rf67[0] ?? '', 'floor(')
    || !preg_match('~max\(1,\s*min\(99,~', $rf67[0] ?? '')) {
    $rBad[] = 'functions.php — debtPaidRing(): فامِ پیوسته (pct×۱٫۲)، floor، یا بازه‌ی ۱..۹۹ نیست';
}
$cs67 = preg_replace('~/\*.*?\*/~s', '', (string)file_get_contents(__DIR__ . '/../assets/css/style.css'));
if (!preg_match('~\.debt-ring\s*\{[^}]*direction:\s*ltr[^}]*--ring-c:\s*hsl\(var\(--ring-h~s', $cs67)) {
    $rBad[] = 'style.css — .debt-ring جهتِ ltr یا رنگِ hsl از --ring-h ندارد';
}
if (!preg_match('~html\[data-theme="dark"\] \.debt-ring\s*\{[^}]*--ring-c:~', $cs67)) {
    $rBad[] = 'style.css — حلقه در حالت شب روشناییِ جدا ندارد (روی زمینه‌ی تیره گم می‌شود)';
}
$tp67 = (string)file_get_contents(__DIR__ . '/../trades.php');
if (!str_contains($tp67, '<option value="credit">خرید امانی / نسیه</option>')
    || !str_contains($tp67, '<option value="credit">فروش امانی / نسیه</option>')) {
    $rBad[] = 'trades.php — برچسبِ گزینه‌ی نسیه «خرید/فروش امانی / نسیه» نیست';
}
T::bulk(10, $rBad, 'حلقه جای نوار، عددِ درشت = باقیمانده، رنگ از درصد');

// ---------------------------------------------------------------
// قاعده ۶۸ — سرعتِ تعویض صفحه: پیش‌گیری، کهنه نشدن، و Navigation Preload
//
// ⛔ چرا شکل هم لازم است: `test_page_speed` به کرومیوم و دیتابیس بند
//    است. این قاعده می‌بندد: prerender (که `app.js` صفحه‌ی باز‌نشده را
//    اجرا می‌کرد) و `eager` برنگردند؛ صفحه‌ای که با GET «خوانده» می‌زند
//    هرگز پیش‌گرفته نشود؛ پیش‌گرفته‌ها بعد از هر نوشتنِ موفق دور ریخته
//    شوند؛ و سرویس‌ورکر پیش‌بارگذاریِ ناوبری را روشن کند و مصرفش کند.
T::group('قاعده ۶۸ — سرعتِ تعویض صفحه');
$sBad = [];
$strip68 = function (string $php): string {
    $o = '';
    foreach (token_get_all($php) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $o .= is_array($t) ? $t[1] : $t;
    }
    return $o;
};
$hd68 = (string)file_get_contents(__DIR__ . '/../includes/header.php');
if (!str_contains($hd68, '<script type="speculationrules" id="specRules"')
    || !str_contains($hd68, "><?= speculationRulesJson((string)(\$_SERVER['SCRIPT_NAME'] ?? '')) ?></script>")) {
    $sBad[] = 'header.php — قاعده‌ی پیش‌گیری از speculationRulesJson() رندر نمی‌شود';
}
$fx68 = $strip68((string)file_get_contents(__DIR__ . '/../includes/functions.php'));
$sr68 = preg_match("~function speculationRulesJson\\(string \\\$currentScript = ''\\).*?\n\\}~s", $fx68, $m68) ? $m68[0] : '';
if ($sr68 === '') { $sBad[] = 'functions.php — speculationRulesJson() پیدا نشد (قاعده کور شد)'; }
if (!str_contains($sr68, "'eagerness' => 'moderate'")) {
    $sBad[] = 'speculationRulesJson() — eagerness «moderate» نیست (eager همه‌ی لینک‌ها را می‌گیرد)';
}
// ⛔ prerender فقط فهرستِ صریحِ PRERENDER_TABS، نه قاعده‌ی سندی (where) روی لینک‌ها.
if (preg_match("~'eager'~", $sr68)
    || !str_contains($sr68, '$rules[\'prerender\'] = [[\'source\' => \'list\', \'urls\' => [$tabs[0]], \'eagerness\' => \'immediate\']];')
    || !str_contains($sr68, 'foreach (PRERENDER_TABS as')) {
    $sBad[] = 'speculationRulesJson() — prerender جز فهرستِ صریحِ یک‌زبانه از PRERENDER_TABS شد، یا eager برگشت';
}
if (!preg_match("~const PRERENDER_TABS = \['index\.php', 'transactions\.php', 'dashboard\.php'\];~", $fx68)) {
    $sBad[] = 'PRERENDER_TABS — جز سه زبانه‌ی نوارِ پایین شد (هر prerender یک صفحه‌ی کامل در حافظه‌ی گوشی است)';
}
if (!str_contains($sr68, 'foreach (PREFETCH_SKIP as') || !str_contains($sr68, '[onclick]')) {
    $sBad[] = 'speculationRulesJson() — استثناهای PREFETCH_SKIP یا لینکِ onclick‌دار اعمال نمی‌شود';
}
$skip68 = preg_match("~const PREFETCH_SKIP = \[([^\]]*)\]~", $fx68, $k68) ? $k68[1] : '';
if (!str_contains($skip68, "'logout.php'")) { $sBad[] = 'PREFETCH_SKIP — logout.php در فهرست نیست'; }
// ⛔ هر صفحه‌ای که با GET «خوانده» ثبت می‌کند باید در فهرست باشد —
//    فهرست از خودِ صفحه‌ها کشف می‌شود، نه دستی.
$pages68 = array_merge(glob(__DIR__ . '/../*.php') ?: [], glob(__DIR__ . '/../admin/*.php') ?: [],
                       glob(__DIR__ . '/../store/*.php') ?: []);
$seen68 = 0;
foreach ($pages68 as $pf) {
    $src = $strip68((string)file_get_contents($pf));
    if (!preg_match('~::markRead\(~', $src)) { continue; }
    $seen68++;
    if (!str_contains($skip68, "'" . basename($pf) . "'")) {
        $sBad[] = basename($pf) . ' — با GET «خوانده» می‌زند ولی در PREFETCH_SKIP نیست';
    }
}
if ($seen68 < 2) { $sBad[] = 'کشفِ صفحه‌های markRead چیزی پیدا نکرد (قاعده کور شد)'; }
$js68 = (string)file_get_contents(__DIR__ . '/../assets/js/app.js');
$dcl68 = strpos($js68, "addEventListener('DOMContentLoaded'");
$rs68 = strpos($js68, 'window.resetSpeculation = reset');
if ($rs68 === false || ($dcl68 !== false && $rs68 > $dcl68)) {
    $sBad[] = 'app.js — resetSpeculation بیرون از DOMContentLoaded تعریف نشده';
}
// بلوکِ کاملِ IIFEِ پیش‌گیری: از تعریفِ reset تا پایانِ بسته‌بندیِ fetch.
$bs68 = strpos($js68, 'function reset() {');
$be68 = $bs68 !== false ? strpos($js68, "\n})();", $bs68) : false;
$blk68 = ($bs68 !== false && $be68 !== false) ? substr($js68, $bs68, $be68 - $bs68) : '';
if (!str_contains($blk68, 'parent.removeChild(old)') || !str_contains($blk68, 'setTimeout(')) {
    $sBad[] = 'app.js — قاعده برداشته و یک تیک بعد نشانده نمی‌شود (جایگزینیِ درجا پیش‌گرفته‌ی کهنه را نگه می‌دارد)';
}
if (!preg_match("~m !== 'GET' && m !== 'HEAD'\) \{\s*p\.then\(function \(r\) \{ if \(r && r\.ok\) \{ try \{ reset\(\);~", $blk68)) {
    $sBad[] = 'app.js — بعد از نوشتنِ موفق با fetch، پیش‌گرفته‌ها دور ریخته نمی‌شوند';
}
// ⛔ بدنه‌ی app.js در صفحه‌ی از-پیش-آماده تا فعال شدن اجرا نمی‌شود (می‌نویسد).
if (!preg_match("~document\.addEventListener\('DOMContentLoaded', function \(\) \{\s*if \(document\.prerendering\) \{\s*document\.addEventListener\('prerenderingchange', appMain, \{ once: true \}\);\s*\} else \{\s*appMain\(\);~", $js68)) {
    $sBad[] = 'app.js — بدنه (appMain) هنگامِ prerender هم اجرا می‌شود (صفحه‌ی باز‌نشده تراکنش می‌نوشت)';
}
if (!str_contains($blk68, 'function armNext()')
    || !preg_match("~urls\.forEach\(function \(u, i\) \{.*?prerender: \[\{ source: 'list', urls: \[u\], eagerness: 'immediate' \}\].*?NEXT_GAP_MS \* \(i \+ 1\)~s", $blk68)
    || !preg_match('~reset = function \(\) \{ baseReset2\(\); armNext\(\); \};~', $js68)) {
    $sBad[] = 'app.js — زبانه‌های بعدی با فاصله آماده نمی‌شوند یا reset آن‌ها را از نو نمی‌چیند';
}
if (!str_contains($hd68, 'data-next="<?= h(speculationNextTabs(')) {
    $sBad[] = 'header.php — فهرستِ زبانه‌های بعدی (data-next) رندر نمی‌شود';
}
$sw68 = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', (string)file_get_contents(__DIR__ . '/../sw.js'));
if (!preg_match("~addEventListener\('activate'.*?navigationPreload\.enable\(\)~s", $sw68)) {
    $sBad[] = 'sw.js — Navigation Preload در activate روشن نمی‌شود';
}
if (!preg_match("~req\.mode === 'navigate'.*?await event\.preloadResponse;\s*if \(pre\) \{ return pre; \}\s*return await fetch\(req\);~s", $sw68)) {
    $sBad[] = 'sw.js — شاخه‌ی ناوبری پاسخِ پیش‌بارگذاری را پیش از fetch مصرف نمی‌کند';
}
if (!str_contains((string)file_get_contents(__DIR__ . '/../includes/log.php'), "\$ctx['prefetch'] = true")) {
    $sBad[] = 'log.php — درخواستِ پیش‌گیری در خطِ request علامت نمی‌خورد';
}
T::bulk(16, $sBad, 'پیش‌گیریِ امن، زبانه‌های از-پیش-آماده، کهنه‌نشدن، و پیش‌بارگذاریِ ناوبری');

// ---------------------------------------------------------------
// قاعده ۶۹ — خانه‌ی دسکتاپ: داشبوردِ کلی بی‌هزینه برای گوشی، و «خالص
// دارایی» فقط از یک جا
//
// ⛔ رفتار را `test_desk_home.php` در مرورگر و با HTTP می‌سنجد؛ این قاعده
//    شکل را نگه می‌دارد برای ماشینی که دیتابیس یا کرومیوم ندارد.
// ---------------------------------------------------------------
T::group('قاعده ۶۹ — خانه‌ی دسکتاپ');
$dBad = [];
$strip69 = function (string $f): string {
    $out = '';
    foreach (token_get_all((string)file_get_contents(__DIR__ . '/../' . $f)) as $t) {
        if (is_array($t) && ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) { continue; }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
};
$idx69 = $strip69('index.php');
$ma69  = $strip69('my-assets.php');
$fn69  = $strip69('includes/functions.php');
if (!preg_match('/\$desk\s*=\s*deskView\(\);/', $idx69)) {
    $dBad[] = 'index.php — تصمیمِ نمای دسکتاپ از deskView() نمی‌آید';
}
// هر سه محاسبه‌ی سنگین فقط داخلِ if ($desk)
if (!preg_match('/if \(\$desk\) \{\s*try \{ \$deskSafe = safeToSpend\(.*?netWorthPortfolio\(\$userId, Auth::isAdmin\(\), \$walletRows\).*?recordNetWorthSnapshot\(\$userId, netWorthSnapParts\(/s', $idx69)) {
    $dBad[] = 'index.php — محاسبه‌های دسکتاپ پشتِ if ($desk) نیستند (گوشی هزینه‌شان را می‌داد)';
}
if (substr_count($idx69, 'netWorthPortfolio(') !== 1 || substr_count($idx69, 'safeToSpend(') !== 1) {
    $dBad[] = 'index.php — netWorthPortfolio/safeToSpend دقیقاً یک بار صدا زده نمی‌شوند';
}
if (!str_contains($idx69, 'financialHighlights($userId, $walletRows, $monthCmp, true, $homeHidden, $deskSafe)')) {
    $dBad[] = 'index.php — «پول قابل خرج» به financialHighlights پاس داده نمی‌شود (financialEvents دو بار)';
}
if (!preg_match('/<\?php if \(\$desk\):.*?<div class="desk-kpis desk-only">/s', $idx69)
    // ⚠ تقویمِ دسکتاپ آخرین چیزِ ستونِ اصلی است و همان‌جا، داخلِ همین if، می‌نشیند.
    || !preg_match('/<\?php if \(\$desk\): \?>\s*(?:<\?php\s*\?>\s*)?(?:<\?php renderHomeCalendar\(\$calJy, \$calJm, true\); \?>\s*)?<\/div>.*?<aside class="home-side desk-only">/s', $idx69)) {
    $dBad[] = 'index.php — ردیفِ شاخص یا ستونِ کناری بیرونِ if ($desk) رندر می‌شود';
}
if (!preg_match('/\$pageDesk\s*=\s*\$desk;/', $idx69)) {
    $dBad[] = 'index.php — $pageDesk از $desk نمی‌آید';
}
// ⛔ خالص دارایی فقط در netWorthPortfolio — my-assets کوئریِ اقلام را خودش نمی‌زند
if (!str_contains($ma69, 'netWorthPortfolio($userId, Auth::isAdmin(), null, $assetSummary)')
    || !str_contains($ma69, 'netWorthSnapParts($portfolio)')) {
    $dBad[] = 'my-assets.php — اقلامِ خالص دارایی از netWorthPortfolio/netWorthSnapParts نمی‌آیند';
}
if (preg_match('/FROM\s+(cheques|debts)\b/i', $ma69) || str_contains($ma69, 'tradesWithProgress(')) {
    $dBad[] = 'my-assets.php — نسخه‌ی دومِ محاسبه‌ی چک/طلب/معامله برگشته (دو عدد برای خالص دارایی)';
}
if (!preg_match('/function netWorthPortfolio\(.*?\n\}/s', $fn69, $nwm) || str_contains($nwm[0], 'refreshIfStale')) {
    $dBad[] = 'functions.php — netWorthPortfolio نباید درخواستِ شبکه (refreshIfStale) بزند';
}
if (!preg_match("/function deskView\(\): bool\s*\{\s*return \(\\\$_COOKIE\[DESK_COOKIE\] \?\? ''\) === '1';/", $fn69)) {
    $dBad[] = 'functions.php — deskView() فقط «۱»ِ کوکیِ DESK_COOKIE را نمی‌پذیرد';
}
$hd69 = (string)file_get_contents(__DIR__ . '/../includes/header.php');
if (!str_contains($hd69, '<?= deskCookieScript() ?>') || !str_contains((string)file_get_contents(__DIR__ . '/../login.php'), '<script><?= deskCookieScript() ?></script>')) {
    $dBad[] = 'header.php/login.php — اسکریپتِ کوکیِ نمای دسکتاپ چاپ نمی‌شود';
}
if (!str_contains($hd69, "<?= \$pageDesk ? ' is-desk' : '' ?>")) {
    $dBad[] = 'header.php — کلاسِ is-desk روی .page-content نمی‌نشیند';
}
$css69 = (string)file_get_contents(__DIR__ . '/../assets/css/style.css');
if (!preg_match('/@media \(min-width: 1100px\) \{\s*\.page-content\.is-desk \{ max-width: 1240px; \}\s*\.desk-only \{ display: block; \}/', $css69)
    || !str_contains($fn69, 'const DESK_MIN_PX = 1100;')) {
    $dBad[] = 'style.css — آستانه‌ی CSS با DESK_MIN_PX یکی نیست';
}
T::bulk(13, $dBad, 'داشبوردِ دسکتاپ پشتِ deskView، خالص دارایی از یک منبع، و آستانه‌ی یکسان');

// ---------------------------------------------------------------
// قاعده ۷۰ — محیطِ فروشگاهی (Business Mode): جداسازیِ دوطرفه
// ---------------------------------------------------------------
// ⛔ خواسته‌ی مالکِ نصب: «حسابداری شخصی تغییر نمی‌خوام، کاملاً جدا باشه…
//    آدرس‌ها هم با هم قاطی نشه.» تستِ رفتاری (`test_business_mode`) به
//    دیتابیس نیاز دارد؛ این قاعده *شکل* را روی ماشینِ بی‌دیتابیس هم نگه
//    می‌دارد — همان استدلالِ قاعده ۴۸ و ۵۱.
T::group('قاعده ۷۰ — محیطِ فروشگاهی جدا از حساب لندِ شخصی');

$bBad = [];
$strip70 = function (string $src): string {
    $out = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
};
$auth70 = $strip70((string)file_get_contents(__DIR__ . '/../includes/auth.php'));
$biz70  = $strip70((string)file_get_contents(__DIR__ . '/../includes/biz.php'));

// ۱. دروازه در initSession و بعد از ورودِ خودکار؛ نوعِ حساب در هر دو نویسنده‌ی نشست
if (!preg_match('/function initSession\(\).*?Biz::gate\(\);\s*\}/s', $auth70)) {
    $bBad[] = 'auth.php — initSession() دروازه‌ی Biz::gate() را صدا نمی‌زند';
}
if (!preg_match('/function loginFromTrustedDevice\(\).*?\$_SESSION\[\'account_type\'\]\s*=\s*Biz::typeFor\(.*?Biz::gate\(\);\s*return true;/s', $auth70)) {
    $bBad[] = 'auth.php — ورودِ خودکار با کوکیِ دستگاه نوعِ حساب را نمی‌نویسد یا بعدش دروازه را صدا نمی‌زند';
}
// ⚠ بدنه تا **تابعِ بعدی** بریده می‌شود؛ با `.*?` آزاد، الگو در
//   loginFromTrustedDevice پیدا می‌شد و جهشِ «نشست همیشه شخصی» زنده ماند.
if (!preg_match('/function establishSession\((?:(?!\bfunction\b).)*?\$_SESSION\[\'account_type\'\]\s*=\s*Biz::typeFor\(/s', $auth70)) {
    $bBad[] = 'auth.php — establishSession() نوعِ حساب را در نشست نمی‌نویسد';
}

// ۲. دروازه برای حسابِ شخصی بی‌کوئری است و پیش‌فرضش بسته
if (!preg_match('/function isStoreOnly\(\): bool\s*\{(.*?)\n    \}/s', $biz70, $ib) || preg_match('/Database|prepare|query\(/', $ib[1])
    || !str_contains($ib[1], "=== 'business'")) {
    $bBad[] = 'biz.php — isStoreOnly() باید فقط از نشست بخواند و فقط `business` را «فقط فروشگاه» بداند';
}
if (!preg_match("/function gate\(\): void\s*\{\s*if \(PHP_SAPI === 'cli' \|\| !self::isStoreOnly\(\)\) \{ return; \}/", $biz70)) {
    $bBad[] = 'biz.php — اولین خطِ gate() برگشتِ فوری برای حسابِ شخصی نیست';
}
if (!preg_match("/public const SHARED = \['logout\.php', 'health\.php', 'api\/log_client_error\.php'\];/", $biz70)) {
    $bBad[] = 'biz.php — فهرستِ بسته‌ی SHARED عوض شده؛ هر درِ تازه باید عمدی و با تست باشد';
}
if (!preg_match("~strncmp\(\\\$script, 'api/', 4\) === 0\).*?http_response_code\(403\)~s", $biz70)) {
    $bBad[] = 'biz.php — اندپوینت‌ها برای حسابِ فروشگاهی ۴۰۳ نمی‌گیرند';
}

// ⛔ نوع در صفحه‌های store/ از دیتابیس تازه می‌شود، نه از نشست — وگرنه روشن
//    و خاموش کردنِ فروشگاه برای `personal`↔`both` تا ورودِ دوباره اثر نداشت.
if (!preg_match('/function requirePage\(\): void\s*\{(?:(?!\bfunction\b).)*?if \(!in_array\(self::refreshType\(\), self::STORE_TYPES, true\)\) \{\s*self::notFound\(\);/s', $biz70)) {
    $bBad[] = 'biz.php — requirePage() نوع را از دیتابیس تازه نمی‌کند یا حسابِ بی‌فروشگاه را ۴۰۴ نمی‌کند';
}
if (!preg_match("/public const TYPES = \[\s*'personal'\s*=> [^\]]*'both'\s*=> [^\]]*'business'\s*=> [^\]]*\];/s", $biz70)
    || !str_contains($biz70, "public const STORE_TYPES = ['both', 'business'];")) {
    $bBad[] = 'biz.php — فهرستِ بسته‌ی نوع‌ها (personal/both/business) یا STORE_TYPES عوض شده';
}
// ⛔ فقط تغییرِ دروازه (به business یا از آن) ابطال می‌کند؛ personal↔both کاربر را بیرون نمی‌اندازد
if (!str_contains($biz70, "\$revoke = (\$from === 'business' || \$type === 'business');")) {
    $bBad[] = 'biz.php — setType() باید فقط برای تغییرِ دروازه (business) دسترسی‌ها را باطل کند';
}
if (!str_contains($biz70, "private const BUSINESS_ROLES = ['user', 'colleague'];")
    || !str_contains($biz70, "return \$type !== 'business' || in_array(\$role, self::BUSINESS_ROLES, true);")) {
    $bBad[] = 'biz.php — roleAllowed() دیگر مدیر و پشتیبان را از حسابِ فروشگاهی دور نگه نمی‌دارد';
}

// ۳. تنها نویسنده‌ی نوعِ حساب، با ابطال و ممیزی و قاعده‌ی نقش
$writers70 = [];
foreach (array_merge(glob(__DIR__ . '/../*.php') ?: [], glob(__DIR__ . '/../api/*.php') ?: [],
                     glob(__DIR__ . '/../includes/*.php') ?: [], glob(__DIR__ . '/../admin/*.php') ?: [],
                     glob(__DIR__ . '/../store/*.php') ?: [], glob(__DIR__ . '/../deploy/*.php') ?: []) as $f) {
    if (preg_match('/UPDATE\s+users\s+SET\s+account_type/i', $strip70((string)file_get_contents($f)))) {
        $writers70[] = basename(dirname($f)) . '/' . basename($f);
    }
}
if ($writers70 !== ['includes/biz.php']) {
    $bBad[] = 'نوعِ حساب جز در Biz::setType() نوشته می‌شود: ' . implode('، ', $writers70);
}
if (!preg_match('/function setType\(.*?roleAllowed\(.*?UPDATE users SET account_type.*?revokeAllAccessFor\(\$targetId\).*?Audit::log\(\'user\.account_type\'/s', $biz70)) {
    $bBad[] = 'biz.php — setType() باید نقش را بسنجد، دسترسی‌ها را باطل کند و ممیزی بنویسد';
}
$users70 = $strip70((string)file_get_contents(__DIR__ . '/../admin/users.php'));
if (!str_contains($users70, '!Biz::roleAllowed($role,') || !str_contains($users70, 'Biz::setType(')) {
    $bBad[] = 'admin/users.php — فرمِ ویرایش قاعده‌ی نقش را نمی‌سنجد یا تغییرِ نوع از Biz::setType نمی‌رود';
}
if (!str_contains($strip70((string)file_get_contents(__DIR__ . '/../deploy/user-admin.php')), '!Biz::roleAllowed($roleIn,')) {
    $bBad[] = 'deploy/user-admin.php — --set-role حسابِ فروشگاهی را مدیر می‌کند';
}
$v170 = $strip70((string)file_get_contents(__DIR__ . '/../api/v1/routes/auth.php'));
if (!preg_match("/Biz::typeFor\(.*?=== 'business'.*?Api::fail\(.*?ApiAuth::issue\(/s", $v170)) {
    $bBad[] = 'api/v1 — به حسابِ فروشگاهی پیش از صدورِ توکن امتناع نمی‌کند';
}

// ۴. هر صفحه‌ی store/ دروازه‌ی خودش را دارد و هیچ چیزی از پوسته‌ی شخصی لود نمی‌کند
$storeFiles70 = glob(__DIR__ . '/../store/*.php') ?: [];
foreach (['login.php', 'logout.php', 'index.php', 'settings.php', 'products.php', 'product.php', 'parties.php', 'party.php',
          'products-io.php', 'reports.php', 'print-settings.php', 'print.php', 'sales.php', 'purchases.php', 'quick-sale.php',
          'invoice.php', 'invoice-edit.php', 'return.php', 'payments.php', 'payment.php', 'accounts.php', 'cheques.php', 'categories.php'] as $must) {
    if (!is_file(__DIR__ . '/../store/' . $must)) { $bBad[] = "store/{$must} نیست"; }
}
foreach (array_merge($storeFiles70, [__DIR__ . '/../includes/biz_head.php', __DIR__ . '/../includes/biz_foot.php']) as $f) {
    $src = $strip70((string)file_get_contents($f));
    $rel = basename(dirname($f)) . '/' . basename($f);
    if (preg_match('~(header|sidebar|footer|add_tx_sheet|edit_tx_modal)\.php|js/app\.js|css/style\.css~', $src)) {
        $bBad[] = "{$rel} — چیزی از پوسته‌ی شخصی لود می‌کند";
    }
    if (str_starts_with($rel, 'store/') && !in_array(basename($f), ['login.php', 'logout.php'], true)
        && !preg_match('/Auth::initSession\(\);\s*Biz::requirePage\(\);/', $src)) {
        $bBad[] = "{$rel} — درست بعد از initSession() دروازه‌ی Biz::requirePage() ندارد";
    }
}
// ⛔ دفترِ فروشگاه هیچ جدول یا تابعِ پولِ شخصی را نمی‌خواند — حسابِ «شخصی +
//    فروشگاه» وگرنه پولِ خانه‌اش را روی داشبوردِ مغازه می‌دید.
foreach (array_merge($storeFiles70, [__DIR__ . '/../includes/biz_catalog.php', __DIR__ . '/../includes/biz_io.php',
                                     __DIR__ . '/../includes/biz_print.php', __DIR__ . '/../includes/biz_docs.php',
                                     __DIR__ . '/../includes/biz_docview.php', __DIR__ . '/../includes/biz_reports.php',
                                     __DIR__ . '/../includes/biz_dash.php', __DIR__ . '/../includes/biz_migrate.php']) as $f) {
    $src = $strip70((string)file_get_contents($f));
    if (preg_match('/walletBalances|totalBalance|activeWallets|\b(?:FROM|JOIN|INTO|UPDATE)\s+`?(?:transactions|wallets|transfers|debts|debt_payments|cheques|trades|trade_sales|assets)\b/i', $src, $pm)) {
        $bBad[] = basename(dirname($f)) . '/' . basename($f) . " — به دفترِ شخصی دست می‌زند ({$pm[0]})";
    }
}
// ⛔ تنها نویسنده‌ی موجودیِ مشتق: BizStock::recalc()
$stockWriters70 = [];
foreach (array_merge(glob(__DIR__ . '/../*.php') ?: [], glob(__DIR__ . '/../includes/*.php') ?: [],
                     glob(__DIR__ . '/../store/*.php') ?: [], glob(__DIR__ . '/../deploy/*.php') ?: []) as $f) {
    $n = preg_match_all('/UPDATE\s+biz_products\s+SET\s+[^;]*\b(?:stock_qty|avg_cost)\s*=/i', $strip70((string)file_get_contents($f)));
    if ($n) { $stockWriters70[basename($f)] = $n; }
}
if ($stockWriters70 !== ['biz_catalog.php' => 1]) {
    $bBad[] = 'stock_qty/avg_cost جز در BizStock::recalc() نوشته می‌شود: ' . json_encode($stockWriters70);
}
$cat70 = $strip70((string)file_get_contents(__DIR__ . '/../includes/biz_catalog.php'));
if (!preg_match('/if \(\$r\[\'min\'\] < -self::EPS\) \{\s*if \(\$ownTx\) \{ \$pdo->rollBack\(\); \}/', $cat70)) {
    $bBad[] = 'biz_catalog.php — سدِ «موجودی در هیچ لحظه‌ای منفی نشود» پیش از commit نیست';
}
if (!preg_match('/migration_biz_catalog\.sql/', $mig70ForCat = (string)file_get_contents(__DIR__ . '/../deploy/migrate.sh'))
    || !str_contains($mig70ForCat, '[migration_biz_catalog.sql]="biz_accounts"')) {
    $bBad[] = 'migrate.sh — migration_biz_catalog.sql در MIGRATIONS یا SENTINEL نیست';
}

foreach (['biz_head.php', 'biz_foot.php'] as $pt) {
    if (!str_contains((string)file_get_contents(__DIR__ . '/../includes/' . $pt), "if (!defined('APP_BASE_PATH')) { http_response_code(404); exit; }")) {
        $bBad[] = "includes/{$pt} — نگهبانِ partial ندارد";
    }
}

// ۵. طرفِ شخصی هیچ نشانی از فروشگاه ندارد
$personal70 = array_merge(glob(__DIR__ . '/../*.php') ?: [], [
    __DIR__ . '/../includes/header.php', __DIR__ . '/../includes/sidebar.php', __DIR__ . '/../includes/footer.php',
    __DIR__ . '/../assets/js/app.js', __DIR__ . '/../assets/css/style.css',
]);
foreach ($personal70 as $f) {
    $src = (string)file_get_contents($f);
    if (preg_match('~/store/|store\.css|Biz::url|\.st-[a-z]~', $src)) {
        $bBad[] = basename($f) . ' — نشانی از محیطِ فروشگاه در طرفِ شخصی';
    }
}
foreach (array_merge(glob(__DIR__ . '/../*.php') ?: [], glob(__DIR__ . '/../api/*.php') ?: [],
                     glob(__DIR__ . '/../admin/*.php') ?: [], glob(__DIR__ . '/../includes/*.php') ?: []) as $f) {
    $b = basename($f);
    if (str_starts_with($b, 'biz') || $b === 'admin_insights.php') { continue; }
    $src70 = $strip70((string)file_get_contents($f));
    // ⛔ تنها استثنا: صفحه‌ی مدیرِ نرخ‌ها لایه‌ی فروشگاهیِ نرخ را بار می‌کند تا
    //    «دریافتِ همین حالا» به قیمتِ کالای ارزی هم برسد (`Rates::listen()`).
    //    فقط **همین یک خط** برداشته می‌شود؛ هر نامِ جدولِ فروشگاهیِ دیگری در
    //    همان فایل هنوز گرفته می‌شود.
    if (str_ends_with(str_replace('\\', '/', $f), '/admin/rates.php')) {
        $src70 = str_replace("require_once __DIR__ . '/../includes/biz_rates.php';", '', $src70, $n70);
        if ($n70 !== 1) { $bBad[] = 'admin/rates.php — لایه‌ی فروشگاهیِ نرخ (biz_rates.php) بار نمی‌شود؛ نرخِ دستی به قیمتِ کالا نمی‌رسد'; }
    }
    if (preg_match('/\bbiz_[a-z_]+\b/', $src70, $bm)) {
        $bBad[] = "{$b} — جدولِ فروشگاهی ({$bm[0]}) را از طرفِ شخصی می‌خواند";
    }
}
// ⛔ و هسته‌ی نرخ (`includes/rates.php`) هیچ نشانی از فروشگاه ندارد — طرفِ
//    شخصی بارش می‌کند؛ قیمتِ کالا فقط در `biz_rates.php` (با `Rates::listen()`).
$rt70 = $strip70((string)@file_get_contents(__DIR__ . '/../includes/rates.php'));
if ($rt70 === '' || preg_match('/\bBiz[A-Z]\w*|\bbiz_\w+/', $rt70, $rm70)) {
    $bBad[] = 'includes/rates.php — نشانی از فروشگاه (' . ($rm70[0] ?? 'فایل نیست') . ')';
}
$br70 = $strip70((string)@file_get_contents(__DIR__ . '/../includes/biz_rates.php'));
if (!preg_match('/Rates::listen\(/', $br70) || !preg_match('/UPDATE biz_products SET sell_price = :sp WHERE id = :id AND user_id = :u/', $br70)) {
    $bBad[] = 'includes/biz_rates.php — خودش را با Rates::listen() ثبت نمی‌کند یا نوشتنِ قیمت بی user_id است';
}

// ۶. migration ثبت شده، و هیچ پوشه‌ی PHPِ تازه‌ای بیرونِ پوششِ تست‌ها نمانده
$mig70 = (string)file_get_contents(__DIR__ . '/../deploy/migrate.sh');
if (!preg_match('/^\s+migration_business_mode\.sql$/m', $mig70) || !str_contains($mig70, '[migration_business_mode.sql]="users.account_type"')) {
    $bBad[] = 'migrate.sh — migration_business_mode.sql در MIGRATIONS یا SENTINEL نیست';
}
$knownDirs70 = ['.', 'admin', 'api', 'api/v1', 'api/v1/routes', 'assets', 'config', 'deploy', 'includes', 'store', 'tests', 'tests/lib'];
$rootReal = realpath(__DIR__ . '/..');
$it70 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootReal, FilesystemIterator::SKIP_DOTS));
$dirs70 = [];
foreach ($it70 as $fi) {
    if ($fi->getExtension() !== 'php') { continue; }
    $rel = ltrim(substr($fi->getPath(), strlen($rootReal)), '/');
    $rel = $rel === '' ? '.' : $rel;
    if (preg_match('~^(\.git|mobile|uploads|var|node_modules)(/|$)~', $rel)) { continue; }
    $dirs70[$rel] = true;
}
foreach (array_keys($dirs70) as $d) {
    if (!in_array($d, $knownDirs70, true)) {
        $bBad[] = "پوشه‌ی {$d} فایلِ PHP دارد ولی هیچ اسکنرِ تستی آن را نمی‌بیند";
    }
}
// ۷. منوی کناری، ورود/خروجِ کالا، دریافت از سایت، چاپ
require_once __DIR__ . '/../includes/biz.php';
$flat70 = Biz::navFlat();
if (array_diff(Biz::TABBAR, array_keys($flat70))) {
    $bBad[] = 'Biz::TABBAR کلیدی دارد که در Biz::NAV نیست — برچسبِ منوی پایین از NAV می‌آید';
}
foreach (array_keys($flat70) as $nf) {
    if (!is_file(__DIR__ . '/../store/' . $nf)) { $bBad[] = "Biz::NAV به store/{$nf} اشاره می‌کند که نیست"; }
}
// ⛔ هر صفحه‌ی store/ یا در منوست یا عمداً بیرون (جزئیات، ورود، برگه‌ی چاپ) — وگرنه بی‌راه می‌ماند
foreach ($storeFiles70 as $f) {
    $bn = basename($f);
    // ⚠ `errors.php` عمداً بیرونِ منوی مشترک است: فقط مدیرِ نصب، با لینکِ خودش در `biz_head.php` (قاعده ۷۵)
    if (!isset($flat70[$bn]) && !isset(Biz::NAV_PARENT[$bn]) && !in_array($bn, ['login.php', 'logout.php', 'print.php', 'errors.php'], true)) {
        $bBad[] = "store/{$bn} در Biz::NAV نیست و هیچ راهی از منو ندارد";
    }
}
$head70 = $strip70((string)file_get_contents(__DIR__ . '/../includes/biz_head.php'));
$foot70 = $strip70((string)file_get_contents(__DIR__ . '/../includes/biz_foot.php'));
if (!str_contains($head70, 'foreach (Biz::NAV as $__group => $__items)') || !str_contains($head70, 'class="st-side"')
    || !str_contains($head70, 'id="stNavToggle"') || !str_contains($foot70, 'foreach (Biz::TABBAR as $__file)')
    || !str_contains($foot70, 'for="stNavToggle"')) {
    $bBad[] = 'پوسته‌ی فروشگاه — نوارِ کناری از Biz::NAV، کشوی بی‌جاوااسکریپت (stNavToggle) یا منوی پایین از TABBAR نیست';
}
$css70 = (string)file_get_contents(__DIR__ . '/../assets/css/store.css');
if (!preg_match('/\.st-side\s*\{[^}]*inset-inline-start:\s*0/', $css70) || !preg_match('/\.st-shell\s*\{\s*margin-inline-start:\s*248px/', $css70)) {
    $bBad[] = 'store.css — نوارِ کناری به لبه‌ی شروع (راست در rtl) نچسبیده یا بدنه کنارش جا باز نمی‌کند';
}
$io70 = $strip70((string)file_get_contents(__DIR__ . '/../includes/biz_io.php'));
if (preg_match('/\b(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+`?biz_(?:products|stock_moves)\b/i', $io70, $iom)) {
    $bBad[] = "biz_io.php — ورودِ کالا مستقیم می‌نویسد ({$iom[0]})؛ باید از BizProducts::save()/BizStock::adjustTo() برود";
}
if (!str_contains($io70, 'BizProducts::save($userId, $in)') || !str_contains($io70, 'BizStock::adjustTo($userId,')) {
    $bBad[] = 'biz_io.php — apply() از BizProducts::save() و BizStock::adjustTo() نمی‌گذرد';
}
if (!preg_match("/SELECT id, name, sku FROM biz_products WHERE user_id = :u'/", $io70)) {
    $bBad[] = 'biz_io.php — تطبیقِ پیش‌نمایش فقط میانِ کالاهای همین کاربر نیست';
}
// ⛔ سدِ SSRF: IPِ سنجیده سنجاق، ریدایرکت دستی و هر بار سنجیده، فقط http(s)، پرچمِ آزمون فقط در cli.
//    پیاده‌سازی در `safe_fetch.php` است (مشترک با نرخِ ارز و طلا) و `BizFetch` همان را ارث می‌برد.
$sf70 = $strip70((string)@file_get_contents(__DIR__ . '/../includes/safe_fetch.php'));
if (!preg_match('/for \(\$hop = 0;.*?\$c = self::check\(\$url\);.*?CURLOPT_RESOLVE\s*=> \[\$c\[\'host\'\]/s', $sf70)
    || !str_contains($sf70, 'CURLOPT_FOLLOWLOCATION => false') || !str_contains($sf70, 'CURLPROTO_HTTP | CURLPROTO_HTTPS')
    || !str_contains($sf70, 'return self::$allowLoopbackForTests && PHP_SAPI === \'cli\';')
    || !str_contains($sf70, 'FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE')
    || !str_contains($io70, 'final class BizFetch extends SafeFetch {}')) {
    $bBad[] = 'safe_fetch.php/biz_io.php — سدِ SSRF (سنجاقِ IP، سنجشِ هر ریدایرکت، فقط http(s)، پرچمِ آزمونِ فقط-cli) ناقص است';
}
foreach (array_merge(glob(__DIR__ . '/../*.php') ?: [], glob(__DIR__ . '/../store/*.php') ?: [], glob(__DIR__ . '/../includes/*.php') ?: [],
                     glob(__DIR__ . '/../api/*.php') ?: []) as $f) {
    if (preg_match('/allowLoopbackForTests\s*=\s*true/', $strip70((string)file_get_contents($f)))) {
        $bBad[] = basename($f) . ' — پرچمِ آزمونِ loopback را بیرون از tests/ روشن می‌کند';
    }
}
if (!str_contains($io70, "strpbrk(\$s[0], '=+-@') !== false ? \"'\" . \$s : \$s")) {
    $bBad[] = 'biz_io.php — CSVِ خروجی فرمولِ تزریقی (= + - @) را خنثی نمی‌کند';
}
// ⛔ ورود از نرم‌افزارِ دیگر (`BizMigrate`): هر نوشتن از راهِ همان فرم —
//    `BizParties::save()`، `BizCash::save()`، `BizPay::createTx()` و جبرانِ
//    `BizParties::shiftOpening()`؛ تنها UPDATEِ مستقیم پرچمِ `opening_import`
//    روی چکی است که همان‌جا ساخته شد. ثبتِ چک و جبرانش یک تراکنش‌اند، و مانده‌ی
//    شخصِ سنددار از فایل بازنویسی نمی‌شود.
$mg70 = $strip70((string)@file_get_contents(__DIR__ . '/../includes/biz_migrate.php'));
preg_match_all('/\b(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+`?(\w+)`?\s*(?:SET\s+(\w+))?/i', $mg70, $mgw, PREG_SET_ORDER);
$mgWrites = array_map(fn($m) => $m[1] . '.' . ($m[2] ?? ''), $mgw);
if ($mgWrites !== ['biz_payments.opening_import']) {
    $bBad[] = 'biz_migrate.php — جز پرچمِ opening_import مستقیم می‌نویسد: ' . json_encode($mgWrites);
}
foreach (['BizParties::save($userId, $in,', 'BizCash::save($userId, [', 'BizPay::createTx($pdo, $userId, [', 'BizParties::shiftOpening($pdo, $userId,',
          'BizImport::plan($userId, $rows, $opts)', 'BizImport::apply($userId, $plan, $opts)'] as $need) {
    if (!str_contains($mg70, $need)) { $bBad[] = "biz_migrate.php — «{$need}» نیست؛ نوشتن باید از راهِ همان فرم باشد"; }
}
if (!preg_match('/private static function applyCheque\(.*?beginTransaction\(\);\s*Biz::lockShop\(\$pdo, \$userId\);.*?createTx.*?shiftOpening.*?\$pdo->commit\(\);/s', $mg70)) {
    $bBad[] = 'biz_migrate.php — ثبتِ چک و جبرانِ اول دوره در یک تراکنشِ قفل‌دار نیستند';
}
if (substr_count($mg70, "isset(\$withDocs[\$id])") !== 2) {
    $bBad[] = 'biz_migrate.php — مانده‌ی شخص/صندوقِ سنددار از فایل بازنویسی می‌شود';
}
$imp70 = $strip70((string)@file_get_contents(__DIR__ . '/../store/import.php'));
if (substr_count($imp70, 'BizMigrate::apply(') !== 1 || !preg_match("/if \(\\\$action === 'apply'\) \{.*?BizMigrate::load\(\\\$userId\).*?BizMigrate::apply\(/s", $imp70)) {
    $bBad[] = 'import.php — ثبت باید فقط از گامِ «ثبت نهایی» و روی پیش‌نمایشِ ذخیره‌شده باشد';
}
$pio70 = $strip70((string)file_get_contents(__DIR__ . '/../store/products-io.php'));
if (substr_count($pio70, 'BizImport::apply(') !== 1 || !preg_match("/if \(\\\$action === 'apply'\) \{.*?BizImport::load\(\\\$userId\).*?BizImport::apply\(/s", $pio70)) {
    $bBad[] = 'products-io.php — ثبت باید فقط از گامِ «ثبت نهایی» و روی پیش‌نمایشِ ذخیره‌شده باشد';
}
if (preg_match('/REQUEST_METHOD|Csrf::verifyOrFail|\$_POST/', $strip70((string)file_get_contents(__DIR__ . '/../store/print.php')))) {
    $bBad[] = 'store/print.php — برگه‌ی چاپ فقط خواندنی است و نباید چیزی بنویسد';
}
if (!str_contains($mig70, "    migration_biz_print.sql\n") || !str_contains($mig70, '[migration_biz_print.sql]="biz_settings.print_prefs"')) {
    $bBad[] = 'migrate.sh — migration_biz_print.sql در MIGRATIONS یا SENTINEL نیست';
}

// ---------- مراحلِ ۳ تا ۵: سند، تخصیص، برگشت ----------
$docs70 = $strip70((string)file_get_contents(__DIR__ . '/../includes/biz_docs.php'));
$allFiles70 = array_merge(glob(__DIR__ . '/../*.php') ?: [], glob(__DIR__ . '/../includes/*.php') ?: [],
                          glob(__DIR__ . '/../store/*.php') ?: [], glob(__DIR__ . '/../deploy/*.php') ?: []);
// ⛔ تنها نویسنده‌ی تخصیص‌ها و کش‌های paid/allocated: BizPay::reallocateTx()
$allocW70 = [];
foreach ($allFiles70 as $f) {
    $n = preg_match_all('/INSERT\s+INTO\s+biz_allocations|DELETE\s+FROM\s+biz_allocations|UPDATE\s+biz_invoices\s+SET\s+paid\s*=|UPDATE\s+biz_payments\s+SET\s+allocated\s*=/i',
                        $strip70((string)file_get_contents($f)));
    if ($n) { $allocW70[basename($f)] = $n; }
}
if ($allocW70 !== ['biz_docs.php' => 4]) {
    $bBad[] = 'تخصیص یا paid/allocated جز در BizPay::reallocateTx() نوشته می‌شود: ' . json_encode($allocW70);
}
// ⚠ ترتیب آزاد است (از «فقط تغییر را بنویس» به بعد `allocated` پیش از تخصیص‌ها
//    نوشته می‌شود)؛ آنچه سنجیده می‌شود این است که هر چهار داخلِ **همان** بدنه‌اند.
$realloc70 = preg_match('/function reallocateTx\(.*?\n    \}\n/s', $docs70, $rm70) ? $rm70[0] : '';
if (preg_match_all('/INSERT\s+INTO\s+biz_allocations|DELETE\s+FROM\s+biz_allocations|UPDATE\s+biz_invoices\s+SET\s+paid\s*=|UPDATE\s+biz_payments\s+SET\s+allocated\s*=/i', $realloc70) !== 4) {
    $bBad[] = 'biz_docs.php — نوشتنِ تخصیص/paid/allocated بیرون از بدنه‌ی reallocateTx() است';
}
// ⛔ سند فقط از BizStock::postDoc() به موجودی می‌رسد
if (preg_match('/biz_stock_moves/i', preg_replace('/SELECT DISTINCT product_id FROM biz_stock_moves/', '', $docs70))
    || substr_count($docs70, 'BizStock::postDoc(') < 2) {
    $bBad[] = 'biz_docs.php — سند باید فقط با BizStock::postDoc() موجودی را عوض کند (صدور و ابطال)';
}
// ⛔ مشتریِ گذری فقط با تسویه‌ی کامل؛ برگشتِ نسیه اعتبار است؛ برگشت بیش از برگشت‌پذیر نه
if (!str_contains($docs70, "if (\$inv['party_id'] === null && \$payAmount !== (int)\$inv['total']) {")) {
    $bBad[] = 'biz_docs.php — سدِ «گذری فقط با تسویه‌ی کامل» نیست';
}
if (!str_contains($docs70, "array_search(\$rv['kind'], BizInvoices::RETURN_OF, true)")) {
    $bBad[] = 'biz_docs.php — اعتبارِ برگشتِ نسیه روی فاکتورِ اصلی نمی‌نشیند';
}
if (!str_contains($docs70, "if (\$q > \$left[\$lineId]['left'] + 0.0005) {")) {
    $bBad[] = 'biz_docs.php — برگشت به «برگشت‌پذیر» محدود نیست';
}
// ⛔ شماره فقط هنگامِ صدور و MAX+1 زیرِ قفل (COUNT با پیش‌نویسِ حذف‌شده یا باطل تکراری می‌ساخت)
if (!preg_match("/if \(\\\$inv\['number'\] === null\) \{\s*\\\$n = \\\$pdo->prepare\('SELECT COALESCE\(MAX\(number\), 0\) \+ 1 FROM biz_invoices WHERE user_id = :u AND kind = :k FOR UPDATE'\)/", $docs70)) {
    $bBad[] = 'biz_docs.php — شماره‌ی سند MAX+1 زیرِ قفل و فقط هنگامِ صدور نیست';
}
// فروشِ سریعِ ناموفق پیش‌نویسِ یتیم به جا نمی‌گذارد
if (!preg_match('/\$r = BizInvoices::issue\(.*?BizInvoices::deleteDraft\(\$userId, \$id\);/s', $strip70((string)file_get_contents(__DIR__ . '/../store/quick-sale.php')))) {
    $bBad[] = 'store/quick-sale.php — صدورِ ناموفق پیش‌نویسِ یتیم به جا می‌گذارد';
}
$mdoc70 = (string)file_get_contents(__DIR__ . '/../migration_biz_docs.sql');
if (!str_contains($mig70, "    migration_biz_docs.sql\n") || !str_contains($mig70, '[migration_biz_docs.sql]="app_settings~setting_key=biz_categories_linked"')) {
    $bBad[] = 'migrate.sh — migration_biz_docs.sql در MIGRATIONS یا SENTINEL نیست';
}
// ⛔ ارجاعِ «برگشت ← اصل» SET NULL — با RESTRICT حذفِ حسابِ دارای برگشت ناممکن بود
foreach (['fk_biz_invoices_ref', 'fk_biz_lines_ref'] as $fk) {
    if (!preg_match('/`' . $fk . '`[^,;]*?ON DELETE SET NULL/s', $mdoc70)) {
        $bBad[] = "migration_biz_docs.sql — {$fk} باید ON DELETE SET NULL باشد";
    }
}

// ---------- فروشگاهِ موبایل: IMEI، «+»ِ کالای تازه، فاکتورِ بی‌سررسید ----------
if (!str_contains($mig70, "    migration_biz_serials.sql\n") || !str_contains($mig70, '[migration_biz_serials.sql]="biz_invoice_lines:idx_biz_lines_imei2"')) {
    $bBad[] = 'migrate.sh — migration_biz_serials.sql در MIGRATIONS یا SENTINEL نیست';
}
// ⛔ وضعیتِ هر IMEI فقط از اسنادِ صادرشده‌ی همین فروشگاه — سندِ باطل خودبه‌خود بیرون است
if (!preg_match("/function states\(.*?WHERE l\.user_id = :u AND i\.status = 'issued' AND i\.id <> :x/s", $docs70)) {
    $bBad[] = 'biz_docs.php — BizSerial::states() باید فقط اسنادِ صادرشده‌ی همین کاربر را بخواند';
}
// ⛔ IMEI پس از قفلِ کالا (postDoc) و داخلِ همان تراکنشِ صدور سنجیده می‌شود
if (!preg_match('/function issueTx\(.*?BizStock::postDoc\(.*?BizSerial::check\(\$pdo, \$userId, \$kind, \$id, \$lines\)/s', $docs70)) {
    $bBad[] = 'biz_docs.php — issueTx() باید بعد از postDoc() سدِ IMEI (BizSerial::check) را بزند';
}
if (!str_contains($docs70, "'imei1' => \$o['imei1'] ?? null")) {
    $bBad[] = 'biz_docs.php — ردیفِ برگشت IMEIِ ردیفِ اصلی را نمی‌برد';
}
if (!preg_match('/if \(\$serial\) \{\s*if \(\$imei1 === \'\'\) \{/', $docs70)) {
    $bBad[] = 'biz_docs.php — parseLines() باید برای گوشی IMEI بخواهد';
}
// ⛔ سررسید از فاکتور رفت (خواسته‌ی مالکِ نصب)
$ie70 = $strip70((string)file_get_contents(__DIR__ . '/../store/invoice-edit.php'));
$state70 = preg_match('/function state\([^{]*\{(.*?)\n    \}/s', $docs70, $sm70) ? $sm70[1] : 'due_date';
// ⚠ فقط صافی‌های **فاکتور**: `BizCheques::FILTERS` صافیِ «سررسید گذشته»ی چک را عمداً دارد
$docsNoChq70 = (string)preg_replace('/final class BizCheques\s*\{.*?\n\}/s', '', $docs70);
if (str_contains($ie70, 'due_date') || preg_match("/'overdue'\s*=>/", $docsNoChq70) || str_contains($state70, 'due_date')) {
    $bBad[] = 'فاکتور نباید سررسید داشته باشد: فیلدِ ویرایشگر، صافیِ overdue یا state() با due_date';
}
// ⛔ «+» یک دکمه‌ی فرم است (بی‌جاوااسکریپت کار می‌کند) و جاوااسکریپت فرم را نمی‌فرستد
if (!str_contains($strip70((string)file_get_contents(__DIR__ . '/../includes/biz_docview.php')), '<button type="submit" name="np_open" value="\' . $i . \'" class="st-plus" formnovalidate data-np-open')) {
    $bBad[] = 'biz_docview.php — «+» باید دکمه‌ی submit با formnovalidate باشد';
}
if (!preg_match("/closest\('\[data-np-open\]'\);\s*if \(!btn\) \{ return; \}\s*e\.preventDefault\(\);/", (string)file_get_contents(__DIR__ . '/../assets/js/store.js'))) {
    $bBad[] = 'store.js — «+» باید با preventDefault پنل را درجا باز کند، نه فرم را بفرستد';
}
// ⛔ کالای تازه‌ی پنل فقط از BizProducts::save() — نسخه‌ی دومی از قاعده‌های کالا نیست
if (!str_contains($ie70, '$res = BizProducts::save($userId, [') || preg_match('/INSERT\s+INTO\s+biz_products/i', $ie70)) {
    $bBad[] = 'store/invoice-edit.php — کالای تازه باید از BizProducts::save() ساخته شود';
}

// ---------- جست‌وجوی کالا، «شرحِ کالا»، مانده‌ی قبلی (`migration_biz_search`) ----------
$js70  = (string)file_get_contents(__DIR__ . '/../assets/js/store.js');
$cat70 = $strip70((string)file_get_contents(__DIR__ . '/../includes/biz_catalog.php'));
$dv70  = $strip70((string)file_get_contents(__DIR__ . '/../includes/biz_docview.php'));
if (!str_contains($mig70, "    migration_biz_search.sql\n") || !str_contains($mig70, '[migration_biz_search.sql]="biz_invoice_lines.note"')) {
    $bBad[] = 'migrate.sh — migration_biz_search.sql در MIGRATIONS یا SENTINEL نیست';
}
// ⛔ پاپ‌آپِ datalist با جاوااسکریپت برداشته می‌شود (دو فهرست روی هم نیفتند)
if (!str_contains($js70, "inp.removeAttribute('list');")) {
    $bBad[] = 'store.js — list="bizProducts" باید با جاوااسکریپت برداشته شود';
}
// ⛔ fold مرورگر و سرور یکی‌اند: ي/ك عربی و «آ»
foreach (["replace(/[يى]/g, 'ی')", "replace(/ك/g, 'ک')", "replace(/[آأإٱ]/g, 'ا')"] as $f70) {
    if (!str_contains($js70, $f70)) { $bBad[] = "store.js — fold() باید {$f70} داشته باشد"; }
}
if (!str_contains($cat70, "'ي' => 'ی'") || !str_contains($cat70, "'ك' => 'ک'") || !str_contains($cat70, "'آ' => 'ا'")) {
    $bBad[] = 'biz_catalog.php — BizCommon::persian()/fold() حروفِ عربی و «آ» را یکی نمی‌کند';
}
// ⛔ نامِ کالا (نوشته‌ی کاربر) در فهرستِ پیشنهاد با textContent، هرگز innerHTML
if (!str_contains($js70, 'n.textContent = p.name;') || preg_match('/innerHTML\s*[+]?=\s*[^;]*p\.name/', $js70)) {
    $bBad[] = 'store.js — نامِ کالا در فهرستِ پیشنهاد باید با textContent نوشته شود';
}
// ⛔ تطبیقِ «از نظرِ آدم یکی» فقط یکتا، هرگز «شامل بودن»
if (!preg_match('/\$hit = \$folded\[BizCommon::fold\(\$item\)\] \?\? \[\];\s*if \(count\(\$hit\) === 1\)/', $docs70)) {
    $bBad[] = 'biz_docs.php — تطبیقِ fold در parseLines() باید فقط برابریِ یکتا باشد';
}
if (!str_contains($docs70, "tableHasColumn('biz_invoice_lines', 'note')")) {
    $bBad[] = 'biz_docs.php — writeLines() باید نبودِ ستونِ note را تحمل کند';
}
if (!str_contains($cat70, 'BizCommon::line(BizCommon::persian((string)($in[\'name\']')) {
    $bBad[] = 'biz_catalog.php — BizProducts::save() نام را با حروفِ فارسی ذخیره نمی‌کند';
}
// ⛔ «مانده‌ی قبلی» از خودِ statement()، نه حسابِ دوم
$ba70 = preg_match('/function balanceAround\(.*?\n    \}/s', $cat70, $bm70) ? $bm70[0] : '';
if ($ba70 === '' || !str_contains($ba70, 'self::statement(') || preg_match('/FROM\s+biz_(payments|invoices)/i', $ba70)) {
    $bBad[] = 'biz_catalog.php — balanceAround() باید فقط از statement() بسازد';
}
// ⛔ «تعداد» نه «مقدار»، و سرآیندِ جدول یک منبع دارد
foreach (['store/invoice-edit.php', 'store/quick-sale.php'] as $f70) {
    $src70 = (string)file_get_contents(__DIR__ . '/../' . $f70);
    if (!str_contains($src70, 'BizDocView::lineHead()') || str_contains($src70, '>مقدار<')) {
        $bBad[] = "{$f70} — سرآیندِ ردیف‌ها باید BizDocView::lineHead() باشد (با «تعداد»)";
    }
}
if (!str_contains($dv70, 'تعداد</th>') || !str_contains($dv70, "'<span class=\"' . h(\$class) . ' is-note\">' . h(\$n)")) {
    $bBad[] = 'biz_docview.php — lineHead() «تعداد» ندارد یا lineSub() شرح را فرار نمی‌دهد';
}
if (!str_contains((string)file_get_contents(__DIR__ . '/../store/print.php'), "\$design['show_balance'] && \$inv['status'] === 'issued'")) {
    $bBad[] = 'store/print.php — مانده‌ی قبلی باید پشتِ کلیدِ show_balance ِ طراحیِ فاکتور باشد';
}

T::bulk(86, $bBad, 'محیطِ فروشگاهی: دروازه‌ی بی‌کوئری، سه نوعِ حساب، تنها نویسنده، دفترِ جدا از پولِ شخصی، و طرفِ شخصیِ دست‌نخورده');

// =================================================================
// قاعده ۷۰ب — دفترِ چکِ فروشگاه (`BizCheques`). رفتار در test_store_cheques
// است و بی‌دیتابیس blocked می‌شود؛ این شکل را برای ماشینِ بی‌دیتابیس نگه می‌دارد.
// =================================================================
T::group('قاعده ۷۰ب — دفترِ چکِ فروشگاه');
$cBad = [];
$chqCls = preg_match('/final class BizCheques\s*\{.*?\n\}/s', $docs70, $cm70) ? $cm70[0] : '';
if ($chqCls === '') { $cBad[] = 'biz_docs.php — کلاسِ BizCheques نیست'; }
// ⛔ تنها نویسنده‌ی وضعیتِ چک: BizPay::createTx (pending) و BizCheques (بقیه)
foreach (array_merge(glob(__DIR__ . '/../store/*.php') ?: [], glob(__DIR__ . '/../includes/*.php') ?: []) as $f) {
    if (basename($f) === 'biz_docs.php') { continue; }
    if (preg_match('/SET[^;]*cheque_(status|settle_id)\s*=/i', (string)file_get_contents($f))) {
        $cBad[] = basename($f) . ' — وضعیتِ چک فقط در biz_docs.php نوشته می‌شود';
    }
}
if (!str_contains($docs70, "\$acc = BizCash::chequeAccount(\$pdo, \$userId, \$kind === 'receipt' ? 'in' : 'out');")) {
    $cBad[] = 'biz_docs.php — چک باید به صندوقِ «چک‌های در جریان» برود، نه صندوقِ فرم';
}
if (substr_count($docs70, '!$settle') < 2) {
    $cBad[] = 'biz_docs.php — createTx() باید دریافت/پرداخت و انتقالِ دستی به/از صندوقِ چک را رد کند';
}
if (!str_contains($docs70, "if (\$chq !== null && \$party === 0) {")) {
    $cBad[] = 'biz_docs.php — چک برای فاکتورِ گذری (بی‌طرف‌حساب) پذیرفته می‌شود';
}
if (!preg_match("/function bounce\(.*?cheque_status = 'bounced'.*?BizPay::reallocateTx\(/s", $chqCls)) {
    $cBad[] = 'BizCheques::bounce() — باید سند را باطل و تخصیص‌ها را از نو بسازد';
}
if (!preg_match("/function unclear\(.*?SET status = 'void'.*?cheque_status = 'pending'/s", $chqCls)) {
    $cBad[] = 'BizCheques::unclear() — باید انتقالِ وصول را باطل کند';
}
if (!preg_match("/function clear\(.*?BizCash::isCheque\(\\\$b\).*?BizPay::createTx\(/s", $chqCls)) {
    $cBad[] = 'BizCheques::clear() — بانکِ مقصد نباید صندوقِ چک باشد و وصول باید از createTx بگذرد';
}
if (!preg_match("/function lock\(.*?user_id = :u/s", $chqCls)) {
    $cBad[] = 'BizCheques — قفلِ چک باید شرطِ user_id داشته باشد';
}
if (!str_contains($cat70, "!self::isCheque(\$r)) { \$t += (int)\$r['balance']; }")) {
    $cBad[] = 'biz_catalog.php — BizCash::total() نباید چکِ در جریان را جزوِ نقد بشمارد';
}
foreach (['store/invoice-edit.php', 'store/quick-sale.php', 'store/return.php'] as $f70) {
    $src70 = (string)file_get_contents(__DIR__ . '/../' . $f70);
    if (str_contains($src70, 'BizPay::METHODS') || substr_count($src70, 'BizPay::quickMethods()') < 2) {
        $cBad[] = "{$f70} — روشِ پرداخت باید از BizPay::quickMethods() بیاید (بی‌چک)";
    }
}
$cp70 = (string)file_get_contents(__DIR__ . '/../store/cheques.php');
if (!str_contains($cp70, "Csrf::verifyOrFail(postParam('csrf_token'));") || !str_contains($cp70, 'redirectWithMessage(')) {
    $cBad[] = 'store/cheques.php — هر نوشتن POST + CSRF و بعد ریدایرکت';
}
if (!str_contains((string)file_get_contents(__DIR__ . '/../includes/biz.php'), "'cheques.php' => 'چک‌ها'")) {
    $cBad[] = 'biz.php — «چک‌ها» در منوی خزانه نیست';
}
$pay70 = (string)file_get_contents(__DIR__ . '/../store/payment.php');
if (!str_contains($pay70, 'data-cheque-fields') || preg_match('/data-cheque-fields[^>]*\bhidden\b/', $pay70)) {
    $cBad[] = 'store/payment.php — فیلدهای چک باید بی‌جاوااسکریپت دیده شوند (نه hidden)';
}
$mig70 = (string)file_get_contents(__DIR__ . '/../deploy/migrate.sh');
if (!preg_match('/^\s*migration_biz_cheques\.sql\s*$/m', $mig70) || !str_contains($mig70, '[migration_biz_cheques.sql]="biz_payments.cheque_status"')) {
    $cBad[] = 'migrate.sh — migration_biz_cheques.sql در MIGRATIONS یا SENTINEL نیست';
}
// ⛔ پیوندِ وصول رو به عقب و با کلیدِ خارجی — وگرنه بازگرداندنِ بکاپ آن را به ردیفِ دیگری وصل می‌کرد
$mc70 = (string)file_get_contents(__DIR__ . '/../migration_biz_cheques.sql');
if (!preg_match('/FOREIGN KEY IF NOT EXISTS \(`cheque_settle_id`\)\s*REFERENCES `biz_payments` \(`id`\) ON DELETE SET NULL/', $mc70)
    || !str_contains($chqCls, "SET cheque_settle_id = :c WHERE id = :t")) {
    $cBad[] = 'cheque_settle_id باید روی انتقالِ وصول، رو به چک، با کلیدِ خارجیِ SET NULL باشد';
}
T::bulk(17, $cBad, 'دفترِ چک: صندوقِ جدا از نقد، تنها نویسنده، وصول/برگشتِ سازگار، و فرم‌های سریعِ بی‌چک');

// =================================================================
// قاعده ۷۰ج — پاک‌سازیِ کالاهای استفاده‌نشده و طراحیِ فاکتور با لوگو.
// رفتار در test_store_design است و بی‌دیتابیس blocked می‌شود؛ این شکل را
// برای ماشینِ بی‌دیتابیس نگه می‌دارد.
// =================================================================
T::group('قاعده ۷۰ج — پاک‌سازیِ کالا و طراحیِ فاکتور');
$gBad = [];
$strip70 = function (string $src): string {
    $o = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $o .= is_array($t) ? $t[1] : $t;
    }
    return $o;
};
$cat70g = $strip70((string)file_get_contents(__DIR__ . '/../includes/biz_catalog.php'));
$des70  = $strip70((string)file_get_contents(__DIR__ . '/../includes/biz_invoice_design.php'));
$cln70  = $strip70((string)file_get_contents(__DIR__ . '/../store/products-cleanup.php'));
$idp70  = $strip70((string)file_get_contents(__DIR__ . '/../store/invoice-design.php'));
$prt70  = (string)file_get_contents(__DIR__ . '/../store/print.php');
$js70   = (string)file_get_contents(__DIR__ . '/../assets/js/store.js');

// ۱. «استفاده‌نشده» یک تعریف دارد و روی خودِ DELETE می‌نشیند
// ⛔ و انبارگردانی هم «استفاده» است (کسریِ شمارش زیانِ واقعی است — بازرسیِ مهر ۱۴۰۵)
if (!preg_match('/UNUSED_SQL\s*=\s*\'(.*?)\';/s', $cat70g, $us70)
    || !str_contains($us70[1], 'NOT EXISTS (SELECT 1 FROM biz_invoice_lines ul WHERE ul.product_id = p.id AND ul.user_id = :uu1)')
    || !str_contains($us70[1], 'NOT EXISTS (SELECT 1 FROM biz_stock_moves um WHERE um.product_id = p.id AND um.user_id = :uu2')
    || !preg_match('/um\.ref_type IS NOT NULL OR um\.kind = .{1,2}adjust/', $us70[1])) {
    $gBad[] = 'biz_catalog.php — UNUSED_SQL باید هر سه شرط (ردیفِ سند، حرکتِ سندداری، انبارگردانی) را با user_id داشته باشد';
}
if (!preg_match('/\$where\s*=\s*\[\'p\.user_id = :u\', self::UNUSED_SQL\]/', $cat70g)) {
    $gBad[] = 'biz_catalog.php — unusedWhere() باید با p.user_id و UNUSED_SQL شروع شود';
}
$du70 = preg_match('/function deleteUnused\(.*?\n    \}\n/s', $cat70g, $m70) ? $m70[0] : '';
if ($du70 === '' || substr_count($du70, 'self::unusedWhere(') < 2 || !str_contains($du70, 'DELETE p FROM biz_products p') || !str_contains($du70, 'WHERE {$w}')) {
    $gBad[] = 'biz_catalog.php — deleteUnused() باید در هر دو حالت (انتخاب و همه) شرطِ unusedWhere() را روی خودِ DELETE بگذارد';
}
if (preg_match('/DELETE\s+(p\s+)?FROM\s+biz_products/i', $cln70) || !str_contains($cln70, 'BizProducts::deleteUnused(')) {
    $gBad[] = 'store/products-cleanup.php — حذف فقط از BizProducts::deleteUnused()';
}
if (strpos($cln70, 'Csrf::verifyOrFail') === false || strpos($cln70, 'Csrf::verifyOrFail') > strpos($cln70, 'deleteUnused(')) {
    $gBad[] = 'store/products-cleanup.php — CSRF پیش از حذف';
}
if (!preg_match('/CLEANUP_LIST_MAX\s*=\s*(\d+);/', $cat70g, $mx70) || (int)$mx70[1] > 900) {
    $gBad[] = 'biz_catalog.php — CLEANUP_LIST_MAX باید زیرِ max_input_vars (۱۰۰۰) بماند';
}

// ۲. لوگو: نوع از محتوا، ابعاد پیش از باز کردن، بازسازی با GD، فهرستِ بسته
$sl70 = preg_match('/function saveLogoBytes\(.*?\n    \}\n/s', $des70, $m70) ? $m70[0] : '';
$pF = strpos($sl70, 'finfo(FILEINFO_MIME_TYPE)'); $pG = strpos($sl70, 'getimagesizefromstring('); $pC = strpos($sl70, 'imagecreatefromstring(');
if ($pF === false || $pG === false || $pC === false || !($pF < $pC && $pG < $pC) || !str_contains($sl70, 'LOGO_MAX_PIXELS')
    || !str_contains($sl70, 'if (!in_array($mime, self::LOGO_INPUT, true))')) {
    $gBad[] = 'biz_invoice_design.php — saveLogoBytes(): نوع (finfo) و ابعاد پیش از imagecreatefromstring';
}
if (!str_contains($sl70, 'imagecopyresampled(') || !preg_match('/\$b64\s*=\s*base64_encode\(\$out\)/', $sl70)) {
    $gBad[] = 'biz_invoice_design.php — ذخیره فقط خروجیِ بازسازی‌شده‌ی GD، نه بایت‌های کاربر';
}
if (!preg_match("/LOGO_MIMES\s*=\s*\['image\/png', 'image\/jpeg'\]/", $des70)) {
    $gBad[] = 'biz_invoice_design.php — LOGO_MIMES باید فهرستِ بسته‌ی png/jpeg باشد';
}
$lg70 = preg_match('/function logo\(int \$userId\).*?\n    \}\n/s', $des70, $m70) ? $m70[0] : '';
if (!str_contains($lg70, "in_array(\$r['mime'], self::LOGO_MIMES, true)") || !str_contains($lg70, "preg_match('/^[A-Za-z0-9+\\/]+={0,2}\$/'")
    || !str_contains($lg70, 'WHERE user_id = :u')) {
    $gBad[] = 'biz_invoice_design.php — logo() باید نوع و base64 را هنگامِ خواندن بسنجد و فقط لوگوی همین کاربر را بخواند';
}

// ۳. رنگ فقط #rrggbb و متن‌ها فرار داده شده
if (!str_contains($des70, "preg_match('/^#[0-9a-f]{6}\$/', \$v)") || !preg_match('/\$out\[\'accent\'\]\s*=\s*self::validAccent\(/', $des70)) {
    $gBad[] = 'biz_invoice_design.php — رنگ فقط از validAccent() (#rrggbb) — وگرنه از <style> بیرون می‌زند';
}
foreach (["h(\$title)", "nl2br(h(\$d['terms']))", "h(\$s)", "h(\$shop)"] as $n70) {
    if (!str_contains($des70, $n70)) { $gBad[] = "biz_invoice_design.php — {$n70} باید فرار داده شود"; }
}

// ۴. برگه‌ی فاکتور یک رندرکننده دارد؛ پیش‌نمایش ذخیره نمی‌کند
$ic70 = preg_match("/case 'invoice':.*?break;/s", $prt70, $m70) ? $m70[0] : '';
if (!str_contains($ic70, 'BizInvoiceDesign::render(') || str_contains($ic70, 'pr-table') || str_contains($ic70, 'BizPrint::head(')) {
    $gBad[] = 'store/print.php — فاکتور فقط از BizInvoiceDesign::render()';
}
if (!str_contains($ic70, "getParam('preview') === '1' ? BizInvoiceDesign::clean(\$_GET, true)") || str_contains($ic70, '::save(')) {
    $gBad[] = 'store/print.php — پیش‌نمایش گزینه‌ها را از آدرس پاک‌سازی می‌کند و هرگز ذخیره نمی‌کند';
}
if (preg_match('/REQUEST_METHOD|\$_POST/', $prt70)) {
    $gBad[] = 'store/print.php — فقط خواندنی';
}
if (strpos($idp70, 'Csrf::verifyOrFail') === false || !str_contains($idp70, 'enctype="multipart/form-data"') || preg_match('/INSERT|UPDATE\s+biz_|DELETE\s+FROM/i', $idp70)) {
    $gBad[] = 'store/invoice-design.php — CSRF، فرمِ multipart، و نوشتن فقط از BizInvoiceDesign';
}
if (!preg_match("/k !== 'csrf_token' && k !== 'action'/", $js70)) {
    $gBad[] = 'store.js — پیش‌نمایشِ زنده نباید توکنِ CSRF را در آدرس بگذارد';
}

// ۵. ثبت‌ها
$mig70g = (string)file_get_contents(__DIR__ . '/../deploy/migrate.sh');
if (!preg_match('/^\s+migration_biz_invoice_design\.sql$/m', $mig70g) || !str_contains($mig70g, '[migration_biz_invoice_design.sql]="biz_settings.invoice_design"')) {
    $gBad[] = 'deploy/migrate.sh — migration_biz_invoice_design باید در MIGRATIONS و SENTINEL باشد';
}
if (!str_contains((string)file_get_contents(__DIR__ . '/../includes/admin_insights.php'), "'biz_logos'")) {
    $gBad[] = 'admin_insights.php — biz_logos باید در NON_ACTIVITY_TABLES باشد';
}
T::bulk(20, $gBad, 'پاک‌سازی فقط کالای بی‌سندِ همین فروشگاه، لوگوی بازسازی‌شده و بسته، و فاکتور با یک رندرکننده');

// =================================================================
// قاعده ۷۰د — بازطراحیِ فروشگاه: سیستمِ طراحی، پوسته، فرمانِ سریع، داشبورد.
// رفتار در test_store_dash / test_store_cmd / test_store_theme است؛ این
// شکل را برای ماشینِ بی‌node و بی‌دیتابیس نگه می‌دارد.
// =================================================================
T::group('قاعده ۷۰د — سیستمِ طراحی، فرمانِ سریع و داشبوردِ فروشگاه');
$dBad = [];
$css70d  = preg_replace('~/\*.*?\*/~s', '', (string)file_get_contents(__DIR__ . '/../assets/css/store.css'));
$head70d = (string)file_get_contents(__DIR__ . '/../includes/biz_head.php');
$srch70d = $strip70((string)file_get_contents(__DIR__ . '/../store/search.php'));
$dash70d = $strip70((string)file_get_contents(__DIR__ . '/../includes/biz_dash.php'));
$pay70d  = $strip70((string)file_get_contents(__DIR__ . '/../store/payment.php'));
$js70d   = (string)file_get_contents(__DIR__ . '/../assets/js/store.js');
$biz70d  = (string)file_get_contents(__DIR__ . '/../includes/biz.php');

// ۱. پالت فقط توکن‌های --p-* را عوض می‌کند — نه قاعده‌ی جدا برای هر پالت
$nPal70d = preg_match_all('/html\[data-st-palette="([a-z]+)"\]\s*\{([^}]*)\}/', $css70d, $pm70d, PREG_SET_ORDER);
if ($nPal70d < 5) { $dBad[] = "store.css — بلوکِ پالت پیدا نشد ({$nPal70d})"; }
foreach ($pm70d as $p) {
    foreach (array_filter(array_map('trim', explode(';', $p[2]))) as $decl) {
        if (!preg_match('/^--p-[a-z0-9-]+\s*:/', $decl)) { $dBad[] = "store.css — پالتِ {$p[1]} چیزی جز --p-* می‌نویسد ({$decl})"; }
    }
}
if (preg_match('/html\[data-st-palette="[a-z]+"\][^{,]*\s[.#a-z]/', $css70d)) {
    $dBad[] = 'store.css — قاعده‌ی مخصوصِ یک پالت روی یک جزء (پالت فقط توکن است)';
}

// ۲. حالتِ شب و منوی کوچک پیش از CSS — بی‌چشمک
$pBoot = strpos($head70d, 'Biz::bootScript()'); $pCss = strpos($head70d, "css/store.css");
if ($pBoot === false || $pCss === false || $pBoot > $pCss) { $dBad[] = 'biz_head.php — bootScript باید پیش از store.css بیاید'; }
if (!preg_match('/id="stCmdData"><\?= json_encode\([^;]*JSON_HEX_TAG/', $head70d)) { $dBad[] = 'biz_head.php — stCmdData بی‌JSON_HEX_TAG'; }
foreach (['id="stNavToggle"', 'class="st-side"', 'data-cmd-open', 'foreach (Biz::NAV as'] as $n) {
    if (!str_contains($head70d, $n)) { $dBad[] = "biz_head.php — «{$n}» نیست (پوسته بی‌جاوااسکریپت کار نمی‌کند)"; }
}
if (!preg_match('/<form class="st-search"[^>]*method="get"[^>]*action="<\?= h\(Biz::url\(\'search\.php\'\)\)/', $head70d)) {
    $dBad[] = 'biz_head.php — جستجوی بالا باید فرمِ GET به search.php باشد (بی‌جاوااسکریپت هم)';
}
foreach (['اصلی', 'فروشگاه', 'مالی', 'گزارش‌ها', 'سیستم'] as $g) {
    if (!str_contains($biz70d, "'{$g}'")) { $dBad[] = "biz.php — گروهِ «{$g}» در NAV نیست"; }
}

// ۳. جستجو و داشبورد فقط می‌خوانند
foreach (['search.php' => $srch70d, 'biz_dash.php' => $dash70d] as $n => $src) {
    if (preg_match('/\b(INSERT\s+INTO|UPDATE\s+biz_|DELETE\s+FROM|REPLACE\s+INTO)\b|\$_POST|REQUEST_METHOD/i', $src)) {
        $dBad[] = "{$n} — فقط خواندنی است";
    }
}
if (!preg_match('/Auth::initSession\(\);\s*Biz::requirePage\(\);/', $srch70d)) { $dBad[] = 'store/search.php — دروازه‌ی Biz::requirePage()'; }
if (!str_contains($srch70d, 'y.user_id = :u')) { $dBad[] = 'store/search.php — کوئریِ چک بی‌user_id'; }
$qm70d = array_map(fn($c) => strstr($c, ');', true) ?: $c, array_slice(explode('->prepare(', $dash70d), 1));
if (count($qm70d) < 9) { $dBad[] = 'biz_dash.php — کوئری‌ها پیدا نشدند (' . count($qm70d) . ')'; }
foreach ($qm70d as $q) {
    if (!preg_match('/\b\w+\.user_id = :u\b|\buser_id = :u\b/', $q)) { $dBad[] = 'biz_dash.php — کوئری بی‌شرطِ user_id: ' . mb_substr(preg_replace('/\s+/', ' ', $q), 0, 70); }
}
// ⛔ چک تا وصول نقد نیست
if (!preg_match("/NOT_CHEQUE\s*=\s*\"NOT IN \('cheque_in','cheque_out'\)\"/", $dash70d) || substr_count($dash70d, '{$nc}') < 4) {
    $dBad[] = 'biz_dash.php — cashDaily() باید صندوقِ چک را از جریانِ نقد بیرون بگذارد';
}
// ⛔ درصدِ دروغ ممنوع
if (!preg_match("/if \(\\\$prev === 0\) \{ return \['pct' => null/", $dash70d)) { $dBad[] = 'biz_dash.php — change() روی دوره‌ی قبلِ صفر درصد نمی‌دهد'; }

// ۴. فرمانِ سریع فقط آدرس می‌سازد؛ پیش‌پرشدن را سرور می‌سنجد
$cmd70d = ($a = strpos($js70d, '/* @cmd-start')) !== false && ($b = strpos($js70d, '/* @cmd-end */')) !== false ? substr($js70d, $a, $b - $a) : '';
if ($cmd70d === '' || preg_match('/fetch\(|XMLHttpRequest|document\.|\.submit\(/', $cmd70d)) {
    $dBad[] = 'store.js — تکه‌ی @cmd باید خالص بماند (بی‌DOM، بی‌شبکه، بی‌ارسال)';
}
if (!preg_match('/fetch\([^)]*search\.php[^)]*format=json/', $js70d) && !preg_match("/format=json/", $js70d)) {
    $dBad[] = 'store.js — نتیجه‌ی زنده از search.php?format=json';
}
if (!str_contains($pay70d, "\$preAmt = sanitizeAmount(getParam('amount'))")
    || !preg_match('/\$preAcc = in_array\(\$preAcc, array_map\(fn\(\$a\) => \(int\)\$a\[\'id\'\], \$accounts\), true\)/', $pay70d)) {
    $dBad[] = 'store/payment.php — پیش‌پرشدن از آدرس: مبلغ با sanitizeAmount و صندوق فقط از صندوق‌های همین فروشگاه';
}

// ۵. ثبت‌ها
$bud70d = (string)file_get_contents(__DIR__ . '/test_business_mode.php');
if (!preg_match("/'store\/search\.php'\s*=>\s*\d+/", $bud70d) || !str_contains((string)file_get_contents(__DIR__ . '/test_page_render.php'), "'store/search.php'")) {
    $dBad[] = 'store/search.php — باید در STORE_BUDGET و EXPECT باشد';
}
T::bulk(24, $dBad, 'پالت فقط توکن، پوسته بی‌جاوااسکریپت، جستجو و داشبورد فقط‌خواندنی با user_id، چک بیرونِ نقد، و فرمان بی‌نوشتن');

// ---------------------------------------------------------------
// ⛔ قاعده ۷۰ه — سرعتِ فروشگاه و تصویرِ شخصی.
//    «کمی کند است»: اندازه‌گیری نشان داد مانده‌ی طرف‌حساب‌ها در یک SELECT
//    چهار بار و روی داشبورد سه بار حساب می‌شد، منوهایی که مانده نشان
//    نمی‌دهند آن را حساب می‌کردند، و اپتیمایزر برای هر ردیفِ فاکتور همه‌ی
//    کالاها را می‌پیمود (۱۴۳ ms → ۱۴). رفتار در `test_store_dash`،
//    `test_store_speed` و `test_store_avatar` است؛ این قاعده شکل را نگه
//    می‌دارد برای ماشینی که دیتابیس یا کرومیوم ندارد.
T::group('قاعده ۷۰ه — سرعتِ فروشگاه و تصویرِ شخصی');
$eBad = [];
$strip70e = function (string $src): string {
    $out = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
};
$head70e = $strip70e((string)file_get_contents(__DIR__ . '/../includes/biz_head.php'));
$biz70e  = $strip70e((string)file_get_contents(__DIR__ . '/../includes/biz.php'));
$cat70e  = $strip70e((string)file_get_contents(__DIR__ . '/../includes/biz_catalog.php'));
$dash70e = $strip70e((string)file_get_contents(__DIR__ . '/../includes/biz_dash.php'));
$rep70e  = $strip70e((string)file_get_contents(__DIR__ . '/../includes/biz_reports.php'));
$idx70e  = $strip70e((string)file_get_contents(__DIR__ . '/../store/index.php'));
$js70e   = (string)preg_replace(['#/\*.*?\*/#s', '#(?<![:"\'])//[^\n]*#'], '', (string)file_get_contents(__DIR__ . '/../assets/js/store.js'));

// ۱. پیش‌گیری و فونت در پوسته
if (!str_contains($head70e, '<script type="speculationrules" id="stSpecRules"><?= Biz::speculationRulesJson() ?></script>')) {
    $eBad[] = 'biz_head.php — قاعده‌ی پیش‌گیری (Biz::speculationRulesJson) رندر نمی‌شود';
}
if (!preg_match('#<link rel="preload" href="<\?= APP_BASE_PATH \?>/assets/fonts/Vazirmatn\.woff2" as="font" type="font/woff2" crossorigin>#', $head70e)) {
    $eBad[] = 'biz_head.php — preloadِ فونت (همان آدرسِ @font-face، بی‌?v=)';
}
$fnSpec = preg_match('/function speculationRulesJson\(\).*?\n    \}/s', $biz70e, $mS) ? $mS[0] : '';
if ($fnSpec === '' || substr_count($fnSpec, "'moderate'") < 2 || preg_match("/'(eager|immediate)'/", $fnSpec)) {
    $eBad[] = 'Biz::speculationRulesJson — prefetch و prerender هر دو «moderate»، هرگز eager/immediate';
}
if (!preg_match("/const SPEC_SKIP = \[[^\]]*'logout\.php'/", $biz70e)) { $eBad[] = 'Biz::SPEC_SKIP — «خروج» با GET است و نباید پیش‌گرفته شود'; }
// ⛔ کهنگی ممکن نیست فقط چون store.js هیچ درخواستِ نویسنده‌ای ندارد
if (preg_match("/method\s*:\s*['\"]POST['\"]/i", $js70e)) {
    $eBad[] = 'store.js — درخواستِ نویسنده با fetch؛ آن‌وقت پیش‌گرفته‌ها کهنه می‌مانند (resetSpeculation لازم می‌شود)';
}

// ۲. مانده‌ها یک بار
$fnSum = preg_match('/class BizParties.*?public static function summary\(int \$userId\): array\s*\{(.*?)\n    \}/s', $cat70e, $mU) ? $mU[1] : '';
if ($fnSum === '' || substr_count($fnSum, 'BALANCE_SQL') !== 1) { $eBad[] = 'BizParties::summary — BALANCE_SQL باید دقیقاً یک بار در کوئری باشد'; }
if (!str_contains($idx70e, 'BizDash::partyBook($userId)') || str_contains($idx70e, 'BizDash::balances(') || str_contains($idx70e, 'BizParties::summary(')) {
    $eBad[] = 'store/index.php — داشبورد مانده‌ها را فقط با BizDash::partyBook() (یک بار) می‌خواند';
}
if (str_contains($dash70e, 'function balances(')) { $eBad[] = 'biz_dash.php — balances() برگشته؛ مانده‌ها دوباره سه بار حساب می‌شوند'; }
$fnAll = preg_match('/public static function all\(int \$userId, string \$filter = \'\', int \$cap = 3000, bool \$withBalance = true\)/', $cat70e);
if (!$fnAll || !str_contains($cat70e, "\$bal = \$withBalance ? self::BALANCE_SQL : 'NULL';")) {
    $eBad[] = 'BizParties::all — منویی که مانده نشان نمی‌دهد باید بتواند BALANCE_SQL را نزند';
}
foreach (['store/quick-sale.php' => 'BizDocView::parties($userId, 2000, false)', 'store/settings.php' => "BizParties::all(\$userId, '', 500, false)",
          'store/reports.php' => "BizParties::all(\$userId, '', 500, false)"] as $f => $needle) {
    if (!str_contains((string)file_get_contents(__DIR__ . '/../' . $f), $needle)) { $eBad[] = "{$f} — منوی طرف‌حساب مانده نشان نمی‌دهد و نباید حسابش کند"; }
}

// ۳. اتصال به کالا با کلیدِ اصلی (اپتیمایزر همه‌ی کالاها را می‌پیمود)
foreach (['biz_reports.php' => $rep70e, 'biz_docs.php' => $strip70e((string)file_get_contents(__DIR__ . '/../includes/biz_docs.php'))] as $f => $src) {
    if (preg_match_all('/LEFT JOIN biz_products p(?! FORCE INDEX \(PRIMARY\)) ON p\.id = l\.product_id AND p\.user_id = l\.user_id/', $src, $mm)) {
        $eBad[] = "{$f} — " . count($mm[0]) . ' اتصالِ biz_products روی ردیف‌ها بی FORCE INDEX (PRIMARY)';
    }
}
if (!str_contains($rep70e, "GROUP BY l.product_id, CASE WHEN l.product_id IS NULL THEN l.description END, l.unit) g")) {
    $eBad[] = 'BizReports::topProducts — گروه‌بندی روی خودِ ردیف‌ها، نامِ کالا بعد از جمع';
}
$mig70e = (string)file_get_contents(__DIR__ . '/../deploy/migrate.sh');
if (!preg_match('/^\s+migration_biz_perf\.sql$/m', $mig70e) || !str_contains($mig70e, '[migration_biz_perf.sql]="biz_payments:idx_biz_payments_to_sum"')) {
    $eBad[] = 'migrate.sh — migration_biz_perf.sql باید در MIGRATIONS و SENTINEL باشد';
}

// ۴. تصویرِ شخصی: یک مسیرِ ذخیره، CSRF، و آدرس فقط از avatarUrl()
$writers = [];
foreach (array_merge(glob(__DIR__ . '/../*.php') ?: [], glob(__DIR__ . '/../{api,store,includes,admin,deploy}/*.php', GLOB_BRACE) ?: []) as $f) {
    if (preg_match('/UPDATE users SET avatar\b/', $strip70e((string)file_get_contents($f)))) { $writers[] = basename($f); }
}
if ($writers !== ['avatar.php']) { $eBad[] = '⛔ نوشتنِ users.avatar فقط در includes/avatar.php: ' . implode(', ', $writers); }
$set70e = $strip70e((string)file_get_contents(__DIR__ . '/../store/settings.php'));
if (!str_contains($set70e, "saveUserAvatar(\$userId, \$_FILES['avatar'] ?? null)") || !str_contains($set70e, 'deleteUserAvatar($userId)')
    || !str_contains((string)file_get_contents(__DIR__ . '/../api/upload_avatar.php'), 'saveUserAvatar(')
    || !str_contains((string)file_get_contents(__DIR__ . '/../api/delete_avatar.php'), 'deleteUserAvatar(')) {
    $eBad[] = 'تصویر: فروشگاه و پروفایلِ حساب لند هر دو از saveUserAvatar()/deleteUserAvatar()';
}
if (preg_match_all('/<\?= Csrf::field\(\) \?>\s*<input type="hidden" name="action" value="avatar(_delete)?">/', $set70e) < 2) {
    $eBad[] = 'store/settings.php — هر دو فرمِ تصویر (ذخیره و حذف) CSRF دارند';
}
if (!str_contains($head70e, "avatarUrl(\$_SESSION['avatar'] ?? null)") || preg_match('#uploads/avatars/\'\s*\.#', $head70e)) {
    $eBad[] = 'biz_head.php — آدرسِ تصویر فقط از avatarUrl() (نامِ خوانده‌شده خام در مسیر نمی‌نشیند)';
}
if (!preg_match("/SELECT account_type, avatar FROM users WHERE id = :id LIMIT 1/", $biz70e)) {
    $eBad[] = 'Biz::refreshType — نامِ تصویر در همان کوئریِ نوعِ حساب (صفر کوئریِ اضافه)';
}
if (!preg_match('/createImageBitmap\(f\)/', $js70e) || !str_contains($js70e, 'rd.readAsDataURL(f)')) {
    $eBad[] = 'store.js — کوچک‌سازیِ تصویر با createImageBitmap و FileReader (نه blob:)';
}
T::bulk(21, $eBad, 'پیش‌گیری و فونت در پوسته، مانده‌ها یک بار، اتصالِ کالا با کلیدِ اصلی، و تصویرِ شخصی از یک مسیر');

// ---------------------------------------------------------------------
// ⛔ قاعده ۷۰و — بهای تمام‌شده‌ی زنجیره‌ای و سودِ خالص.
//    رفتار (با برابریِ حسابداری و هفده جهش) در `test_store_cost`؛ اینجا
//    شکل: بهای فروش فقط از `recalc()`، سودِ خالص فقط از `BizReports::profit()`.
T::group('قاعده ۷۰و — بهای تمام‌شده و سودِ خالص');
$fBad = [];
$docs70f = $strip70e((string)file_get_contents(__DIR__ . '/../includes/biz_docs.php'));
$costWriters = [];
foreach (array_merge(glob(__DIR__ . '/../*.php') ?: [], glob(__DIR__ . '/../{api,store,includes,admin,deploy}/*.php', GLOB_BRACE) ?: []) as $f) {
    $n = preg_match_all('/UPDATE\s+biz_invoice_lines\s+SET\s+unit_cost\b/i', $strip70e((string)file_get_contents($f)));
    if ($n) { $costWriters[basename($f)] = $n; }
}
ksort($costWriters);
if ($costWriters !== ['biz_catalog.php' => 1, 'biz_docs.php' => 2]) {
    $fBad[] = '⛔ بهای ردیف فقط در recalc() (زنجیره)، issueTx (عددِ اولیه) و undo (پاک کردن): ' . json_encode($costWriters);
}
$posCost = strpos($docs70f, "elseif ((int)\$l['track_stock'] === 1) { \$c = (int)round((float)\$l['avg_cost']); }");
$posPost = strpos($docs70f, '$post = BizStock::postDoc($userId, $kind, $id, $moves);');
if ($posCost === false || $posPost === false || $posCost > $posPost) {
    $fBad[] = 'issueTx — بهای اولیه‌ی ردیف **پیش از** postDoc (وگرنه میانگینِ امروز روی بهای درستِ recalc() می‌نشست)';
}
if (!str_contains($cat70e, "if (\$kind === 'purchase_return' && \$m['unit_cost'] !== null) {")
    || !str_contains($cat70e, '$out = $unitCost[$unit];')
    || !str_contains($cat70e, "\$cost = (float)\$lineCost[(int)\$line['ref_line_id']];")) {
    $fBad[] = 'recalc — برگشت از خرید به بهای اصلی، گوشی به بهای خودش، برگشت از فروش به بهای فروشِ اصلی';
}
if (!str_contains($cat70e, 'if (count($ls) !== ($count[$rid] ?? 0)) { unset($out[$rid]); }')) {
    $fBad[] = 'docLines — سندی که شمارِ ردیف و حرکتش یکی نیست جفت نمی‌شود (حدس ممنوع)';
}
if (!str_contains($rep70e, 'GROUP BY i.kind" . self::OTHER_SQL') || !str_contains($rep70e, 'return $gross - $other + $income - $expense;')) {
    $fBad[] = 'BizReports — `other` در همان کوئریِ sales() و profit() تنها فرمول';
}
if (!preg_match_all("/m\.kind = 'adjust'|p\.track_stock = 0/", $dash70e) || substr_count($dash70e, "m.kind = 'adjust'") !== 1 || substr_count($dash70e, 'p.track_stock = 0') !== 1) {
    $fBad[] = 'BizDash::salesDaily — همان دو جزءِ `other` (کسری و خریدِ بی‌انبار)';
}
foreach (['store/index.php' => 3, 'store/reports.php' => 1, 'store/print.php' => 1] as $rel => $min) {
    $s = $strip70e((string)file_get_contents(__DIR__ . '/../' . $rel));
    if (substr_count($s, 'BizReports::profit(') < $min || preg_match("/\['gross'\]\s*[+-]\s*\\\$(?:ca|rCash|c)\[/", $s)) {
        $fBad[] = "{$rel} — سودِ خالص فقط از BizReports::profit()، نه فرمولِ دستی";
    }
}
$ua70f = $strip70e((string)file_get_contents(__DIR__ . '/../deploy/user-admin.php'));
if (!str_contains($ua70f, "\$cmd === '--store-recost'") || !str_contains($ua70f, 'BizStock::rebuild($uid)')) {
    $fBad[] = 'user-admin.php --store-recost فقط از BizStock::rebuild()';
}
if (!str_contains($docs70f, "? (int)\$o['net_total'] - \$left[\$lineId]['amount']")) {
    $fBad[] = 'createReturn — آخرین برگشتِ یک ردیف باقیمانده‌ی دقیق است';
}
if (!preg_match("/if \(\\\$to === 'draft' && \\\$inv\['party_id'\] === null\) \{/", $docs70f)) {
    $fBad[] = 'undo — فاکتورِ گذری با پولِ جدا به پیش‌نویس برنمی‌گردد';
}
T::bulk(10, $fBad, 'بهای فروش فقط از زنجیره‌ی recalc()، سودِ خالص از یک فرمول، و بازسازی از یک مسیر');

// ---------------------------------------------------------------------
// ⛔ قاعده ۷۰ز — بستنِ دوره و چکِ خرجی.
//    رفتار (با ۲۷ جهش، هر در جدا) در `test_store_lock`؛ اینجا شکل: هر مسیرِ
//    نوشتنِ تاریخ‌دار از `Biz::lockError()` می‌پرسد، `lock_date` فقط از
//    `Biz::saveLock()` نوشته می‌شود، و پرچمِ داخلیِ واگذاری از هیچ فرمی نمی‌رسد.
T::group('قاعده ۷۰ز — بستنِ دوره و چکِ خرجی');
$gBad = [];
$bodyOf = function (string $src, string $class, string $fn): string {
    $cls = preg_match('/final class ' . $class . '\b.*?(?=\nfinal class |\z)/s', $src, $cm) ? $cm[0] : '';
    return preg_match('/function ' . $fn . '\(.*?\n    \}\n/s', $cls, $fm) ? $fm[0] : '';
};
$need = [
    'biz_docs.php'    => ['BizInvoices' => ['saveDraft', 'issueTx', 'undo'], 'BizPay' => ['createTx', 'void'],
                          'BizCheques' => ['unclear', 'bounce', 'unendorse']],
    'biz_catalog.php' => ['BizStock' => ['setOpening', 'adjustTo', 'deleteMove'], 'BizParties' => ['save'], 'BizCash' => ['save']],
];
foreach ($need as $file => $classes) {
    $src = $strip70e((string)file_get_contents(__DIR__ . '/../includes/' . $file));
    foreach ($classes as $cls => $fns) {
        foreach ($fns as $fn) {
            $body = $bodyOf($src, $cls, $fn);
            if ($body === '' || !str_contains($body, 'Biz::lockError(')) { $gBad[] = "⛔ {$cls}::{$fn}() از Biz::lockError() نمی‌پرسد"; }
        }
    }
}
$lockW = [];
foreach (array_merge(glob(__DIR__ . '/../*.php') ?: [], glob(__DIR__ . '/../{api,store,includes,admin,deploy}/*.php', GLOB_BRACE) ?: []) as $f) {
    if (preg_match('/\block_date\s*=\s*VALUES|INSERT INTO biz_settings \([^)]*lock_date/', $strip70e((string)file_get_contents($f)))) { $lockW[] = basename($f); }
}
if ($lockW !== ['biz.php'] || !str_contains($bodyOf($biz70e, 'Biz', 'saveLock'), 'lock_date')) {
    $gBad[] = 'lock_date فقط از Biz::saveLock() نوشته می‌شود: ' . implode(', ', $lockW);
}
if (!str_contains($bodyOf($biz70e, 'Biz', 'saveLock'), 'if ($unlock && !$confirmUnlock) {')) {
    $gBad[] = 'saveLock — باز کردنِ دوره‌ی بسته تأییدِ صریح می‌خواهد';
}
$flagUse = 0;
foreach (array_merge(glob(__DIR__ . '/../*.php') ?: [], glob(__DIR__ . '/../{api,store,includes,admin,deploy}/*.php', GLOB_BRACE) ?: []) as $f) {
    $src = $strip70e((string)file_get_contents($f));
    $flagUse += substr_count($src, "'_cheque_endorse' => 1");
    $revUse   = ($revUse ?? 0) + substr_count($src, "'_cheque_reverse' => 1");
    if (str_starts_with(basename(dirname($f)), 'store') && preg_match('/BizPay::create\(\$userId,\s*\$_POST/', $src)) {
        $gBad[] = basename($f) . ' — $_POST خام به BizPay::create (پرچم‌های داخلی از فرم می‌رسیدند)';
    }
}
if ($flagUse !== 1 || !str_contains($bodyOf($docs70f, 'BizCheques', 'endorse'), "'_cheque_endorse' => 1")) {
    $gBad[] = "پرچمِ _cheque_endorse فقط در BizCheques::endorse() ({$flagUse} بار)";
}
// ⛔ پرچمِ سندِ معکوسِ برگشتی (`migration_biz_pair`) — همان قاعده: فقط از `bounce()`
if (($revUse ?? 0) !== 1 || !str_contains($bodyOf($docs70f, 'BizCheques', 'bounce'), "'_cheque_reverse' => 1")) {
    $gBad[] = 'پرچمِ _cheque_reverse فقط در BizCheques::bounce() (' . ($revUse ?? 0) . ' بار)';
}
// ⛔ و سندِ جفت با هم باطل می‌شود؛ چکِ برگشتیِ معکوس‌دار خودش نه
if (!str_contains($bodyOf($docs70f, 'BizPay', 'void'), "WHERE pair_id = :id AND user_id = :u AND status = 'ok'")
    || !str_contains($bodyOf($docs70f, 'BizPay', 'void'), "if ((\$p['cheque_status'] ?? null) === 'bounced') {")) {
    $gBad[] = 'BizPay::void — سندِ جفت (حقوق/کسرِ مساعده) با هم، و چکِ برگشتیِ معکوس‌دار نه';
}
if (!str_contains($docs70f, "if (\$p['kind'] === 'payment' && \$refSt === 'endorsed') {") || !str_contains($docs70f, "if ((\$p['cheque_status'] ?? null) === 'endorsed') {")) {
    $gBad[] = 'BizPay::void — نه دریافتِ چکِ واگذارشده نه پرداختِ واگذاری جدا باطل نمی‌شوند';
}
$mig70g = (string)file_get_contents(__DIR__ . '/../deploy/migrate.sh');
if (!preg_match('/^\s+migration_biz_lock\.sql$/m', $mig70g) || !str_contains($mig70g, '[migration_biz_lock.sql]="biz_settings.lock_date"')) {
    $gBad[] = 'migrate.sh — migration_biz_lock.sql باید در MIGRATIONS و SENTINEL باشد';
}
T::bulk(19, $gBad, 'هر مسیرِ نوشتنِ تاریخ‌دار از قفل می‌پرسد، قفل از یک مسیر نوشته می‌شود، و واگذاری درِ پشتی ندارد');

// ---------------------------------------------------------------------
// ⛔ قاعده ۷۰ح — درگاهِ «حسابداری فروشگاه» در طرفِ شخصی.
//    «فقط اونایی فروشگاه فعال دارن ببینن»: تنها روزنه‌ی قاعده‌ی «طرفِ شخصی
//    هیچ نشانی از فروشگاه ندارد» همین تابع است، با نگهبانِ `hasStore()`ِ
//    بی‌کوئری. رفتار (شخصی هیچ، «شخصی + فروشگاه» سه جا) در `test_business_mode`.
T::group('قاعده ۷۰ح — درگاهِ فروشگاه در حساب لندِ شخصی');
$hBad = [];
$gw = $bodyOf($biz70e, 'Biz', 'personalGateway');
if (!str_contains($gw, "if (!self::hasStore() || !in_array(\$spot, self::GATEWAY_SPOTS, true)) { return ''; }")) {
    $hBad[] = 'Biz::personalGateway — اولین خط باید نگهبانِ hasStore() و فهرستِ بسته‌ی جاها باشد';
}
if (preg_match('/Database::|->prepare\(|->query\(/', $gw)) { $hBad[] = 'Biz::personalGateway — صفر کوئری (نوع از نشست)'; }
$spots = [];
foreach ($personal70 as $f) {
    if (preg_match_all("/Biz::personalGateway\('([a-z]+)'\)/", (string)file_get_contents($f), $gm)) {
        foreach ($gm[1] as $sp) { $spots[] = basename($f) . ':' . $sp; }
    }
    if (preg_match('/Biz::(?!personalGateway\()[a-zA-Z]+\(/', $strip70e((string)file_get_contents($f)), $om)) {
        $hBad[] = basename($f) . " — طرفِ شخصی جز personalGateway() چیزی از Biz نمی‌گیرد ({$om[0]})";
    }
}
sort($spots);
if ($spots !== ['footer.php:sheet', 'sidebar.php:nav']) {
    $hBad[] = 'درگاه دقیقاً دو جا: پایینِ منوی کناری و شیتِ «بیشتر» (نه خانه) — ' . implode(', ', $spots);
}
T::bulk(4, $hBad, 'تنها راهِ طرفِ شخصی به فروشگاه یک تابعِ بی‌کوئری با نگهبانِ hasStore() است، در دو جای ثابت');

// ---------------------------------------------------------------------
// ⛔ قاعده ۷۱ — نسخه‌ی آزمایشی (staging) و انتشارِ «همان چیزی که دیده شد».
//    رفتار در tests/test_staging.php (با مخزن‌های واقعیِ git و nginxِ
//    واقعی)؛ اینجا شکل، برای ماشینی که آن تست بخشی‌اش را رد می‌کند.
//
//    و یک خرابیِ واقعی که همین کار پیدا کرد: قالبِ nginxِ vps-setup.sh یک
//    `heredoc`ِ بی‌کوتیشن است و داخلش یک بک‌تیکِ فرارنداده («`location ~
//    \.php$`» در یک کامنت) **اجرا** می‌شد — `location: command not found`
//    وسطِ نصب، و جای آن متن خالی. قاعده ۳۶ فقط رشته‌ی دابل‌کوت را می‌دید.
// ---------------------------------------------------------------------
T::group('قاعده ۷۱ — staging و انتشار');
$bad71 = [];
$hl71 = (string)file_get_contents(__DIR__ . '/../hesabland');
$vps71 = (string)file_get_contents(__DIR__ . '/../deploy/vps-setup.sh');

foreach ([
    'cmd_release() {'                                        => 'hesabland — فرمانِ release',
    'if cmd_deploy "--to=$sha"'                              => 'release فقط کامیتِ staging را می‌برد (--to)',
    'git merge-base --is-ancestor "$sha" "origin/${BRANCH}"' => 'release: کامیت باید روی شاخه‌ی سایتِ اصلی باشد',
    'if [[ "$now" != "$seen" ]]; then'                       => 'release: staging باید روی همان کامیتِ سبز باشد',
    'git reset --hard "$TARGET" --quiet'                     => 'deploy روی همان هدفی می‌نشیند که سنجیده شد',
    'preflight_syntax "$TARGET"'                             => 'نحو روی همان هدف سنجیده می‌شود',
    'در staging دیده نشده'                                   => 'deploy مستقیمِ نسخه‌ی دیده‌نشده می‌پرسد',
    'bash "$SELF" deploy --no-backup --migrate --force'      => 'staging همان deploy است، روی پوشه‌ی staging',
    'staging)  cmd_staging "$@" ;;'                          => 'فرمانِ staging',
    'release)  cmd_release "$@" ;;'                          => 'فرمانِ release',
    "printf 'hesab:%s\\nHesab:%s\\n'"                        => '--password: «Hesab»ِ بزرگ‌شده‌ی آیفون هم پذیرفته می‌شود',
] as $needle => $what) {
    if (!str_contains($hl71, $needle)) { $bad71[] = $what; }
}
if (!preg_match('/staging_guard\(\) \{.*?"\$sdb" == "\$pdb".*?"\$sh" == "\$ph".*?\n\}/s', $hl71)) {
    $bad71[] = 'staging_guard: دیتابیس و آدرسِ staging باید با سایتِ اصلی فرق کنند';
}
foreach (['satisfy any;', 'allow 127.0.0.1;', 'auth_basic_user_file /etc/nginx/${SITE_NAME}.htpasswd;',
          'X-Robots-Tag \\"noindex, nofollow\\"', 'GRANT ALL PRIVILEGES ON \\`$DB_NAME\\`.* TO',
          '"$APP_DIR/config/config.example.php" "$STG_CONF"', "printf 'hesab:%s\\nHesab:%s\\n'", "tr -dc 'a-km-np-z2-9'", '"APP_ENV" => "staging"', '"MAIL_METHOD" => ""'] as $n) {
    if (!str_contains($vps71, $n)) { $bad71[] = "vps-setup.sh --staging: {$n}"; }
}
if (preg_match('#PROD_DIR/config/config\.php#', $vps71)) {
    $bad71[] = '⛔ کانفیگِ staging از کانفیگِ سایتِ اصلی کپی نمی‌شود (کلیدها و رمزها)';
}
if (!preg_match('/"\$APP_DIR" == "\$PROD_DIR" \|\| "\$APP_USER" == "hesab"/', $vps71)) {
    $bad71[] = 'vps-setup.sh: نگهبانِ «staging روی نام‌های سایتِ اصلی نه»';
}
foreach (['includes/header.php', 'includes/biz_head.php', 'login.php', 'store/login.php'] as $f) {
    if (!preg_match('/^<body[^\n]*\n<\?= envBanner\(\) \?>/m', (string)file_get_contents(__DIR__ . '/../' . $f))) {
        $bad71[] = "{$f}: نوارِ «نسخه‌ی آزمایشی» درست بعد از <body>";
    }
}

// بک‌تیکِ فرارنداده داخلِ heredocِ بی‌کوتیشن = اجرای فرمان.
$shFiles71 = array_merge(glob(__DIR__ . '/../deploy/*.sh') ?: [], [__DIR__ . '/../hesabland', __DIR__ . '/../deploy.sh']);
$heredocs71 = 0;
foreach ($shFiles71 as $f) {
    $lines = explode("\n", (string)file_get_contents($f));
    $end = null;
    foreach ($lines as $i => $ln) {
        if ($end !== null) {
            if (rtrim($ln) === $end || ltrim($ln, "\t") === $end) { $end = null; continue; }
            if (preg_match('/(?<!\\\\)`/', $ln)) { $bad71[] = basename($f) . ':' . ($i + 1) . ' — بک‌تیکِ فرارنداده در heredoc اجرا می‌شود'; }
            continue;
        }
        if (preg_match('/<<-?\s*([A-Za-z_][A-Za-z0-9_]*)\b/', preg_replace('/#.*$/', '', $ln), $m)
            && !preg_match('/<<-?\s*[\'"]/', $ln)) {
            $end = $m[1]; $heredocs71++;
        }
    }
}
if ($heredocs71 < 5) { $bad71[] = "پیمایشِ heredoc فقط {$heredocs71} مورد یافت — خودِ سنجه کور شده"; }
T::bulk(29, $bad71, 'release فقط کامیتِ دیده‌شده، staging جدا و پشتِ رمز، و هیچ بک‌تیکی در heredoc اجرا نمی‌شود');

// ---------------------------------------------------------------
// قاعده ۷۲ — شیتِ روی گوشی: «برگشت» می‌بندد، و هر تازه‌سازی از `reloadPage()`
// ---------------------------------------------------------------
// ⛔ گزارشِ مالکِ نصب (اپ اندروید): «منو میاد روی صفحه قبلی … خیلی سخت از
//    اونجا میتونم بیام بیرون». هر لایه‌ی باز یک خانه در تاریخچه دارد؛ یک
//    `location.reload()`ِ خام با شیتِ باز آن خانه را جا می‌گذاشت و کاربر برای
//    بیرون رفتن دو بار «برگشت» می‌زد. رفتار در `test_sheet_mobile` سنجیده می‌شود؛
//    این‌جا فقط اینکه تازه‌سازیِ تازه‌ای از کنارش رد نشود.
T::group('قاعده ۷۲ — شیتِ روی گوشی: «برگشت» و تازه‌سازی');
$bad72 = [];
$app72 = (string)@file_get_contents(__DIR__ . '/../assets/js/app.js');
$rpAt = strpos($app72, 'window.reloadPage = function');
$rpEnd = $rpAt === false ? false : strpos($app72, "\n};", $rpAt);
$rest72 = $rpAt === false || $rpEnd === false ? $app72
    : substr($app72, 0, $rpAt) . substr($app72, $rpEnd);
$rest72 = (string)preg_replace('~/\*.*?\*/|(?<![:\'"])//[^\n]*~s', '', $rest72);
if ($rpAt === false) { $bad72[] = 'app.js — window.reloadPage نیست'; }
if (preg_match_all('~location\.reload\s*\(~', $rest72, $mm72)) {
    $bad72[] = 'app.js — ' . count($mm72[0]) . ' تازه‌سازیِ خام بیرون از reloadPage() (خانه‌ی شیت در تاریخچه جا می‌ماند)';
}
$css72 = (string)@file_get_contents(__DIR__ . '/../assets/css/style.css');
if (!str_contains($css72, '.sheet-overlay > .sheet { max-height: min(90dvh, 100%); }')) {
    $bad72[] = 'style.css — شیت به قدِ لایه (با کیبوردِ باز) محدود نیست';
}
T::bulk(3, $bad72, 'هر تازه‌سازی از reloadPage()؛ شیت با کیبورد در لایه جا می‌شود');

// ---------------------------------------------------------------
// قاعده ۷۳ — نوشتنِ هم‌زمانِ پول: نسبی یا قفل‌دار، و لنگرِ سری
// ---------------------------------------------------------------
// ⛔ بازبینیِ کلِ پروژه (مهر ۱۴۰۵). رفتارشان در `test_money_audit`/`test_schedule`
//    سنجیده می‌شود؛ مسابقه را اما تست تک‌رشته‌ای نمی‌بیند، پس شکلِ امنِ نوشتن
//    همین‌جا نگه داشته می‌شود:
//    - تعدیلِ موجودی نسبی (`+ :d`) — کارگرِ پیامکِ اپ هم‌زمان همان ستون را جابه‌جا می‌کند.
//    - پرداختِ بدهی ردیفِ بدهی را `FOR UPDATE` می‌خواند — وگرنه یکی از دو پرداختِ
//      هم‌زمان از `paid_amount` گم می‌شد.
//    - تراکنشِ دوره‌ایِ خودکار اول سررسید را **شرطی** جلو می‌برد — وگرنه دو دستگاه
//      هر کدام یک تکراری می‌ساختند.
//    - هر جلو بردنِ سری با لنگرِ تاریخِ شروع — وگرنه ۳۱ برای همیشه ۳۰ می‌شد.
T::group('قاعده ۷۳ — نوشتنِ هم‌زمانِ پول و لنگرِ سری');
$bad73 = [];
$adj73 = (string)@file_get_contents(__DIR__ . '/../api/adjust_wallet.php');
if (!str_contains($adj73, 'SET initial_balance = initial_balance + :d')) {
    $bad73[] = 'adjust_wallet.php — موجودیِ اولیه مطلق نوشته می‌شود (به‌روزرسانیِ هم‌زمانِ پیامک گم می‌شود)';
}
$pay73 = (string)@file_get_contents(__DIR__ . '/../api/add_debt_payment.php');
if (!preg_match('~beginTransaction\(\);.*?FROM debts WHERE id = :id AND user_id = :u FOR UPDATE~s', $pay73)) {
    $bad73[] = 'add_debt_payment.php — ردیفِ بدهی داخلِ تراکنش قفل نمی‌شود';
}
$fn73 = (string)@file_get_contents(__DIR__ . '/../includes/functions.php');
if (!str_contains($fn73, 'WHERE id = :id AND next_due_date = :old') || !str_contains($fn73, 'if ($upd->rowCount() !== 1) { $pdo->rollBack(); break; }')) {
    $bad73[] = 'functions.php — تراکنشِ دوره‌ای بی‌سدِ شرطی (دو دستگاه = تکراری)';
}
$anchorCalls = 0;
foreach (['includes/functions.php', 'api/confirm_recurring.php', 'api/skip_recurring.php'] as $f73) {
    $src = (string)@file_get_contents(__DIR__ . '/../' . $f73);
    $anchorCalls += preg_match_all("~advanceRecurringDate\([^;]*jalaliDayOfDate\(\\\$r\['start_date'\]~s", $src);
}
if ($anchorCalls < 4) {
    $bad73[] = "سری‌های دوره‌ای بی‌لنگرِ تاریخِ شروع جلو می‌روند ({$anchorCalls} از ۴)";
}
T::bulk(4, $bad73, 'تعدیل نسبی، پرداخت قفل‌دار، سررسید شرطی، و لنگرِ سری');

// ---------------------------------------------------------------
// قاعده ۷۴ — فهرستِ نوعِ دریافت/پرداختِ فروشگاه فقط در `BizPay`
// ---------------------------------------------------------------
// ⛔ بازبینیِ کلِ پروژه (مهر ۱۴۰۵): `('receipt','income','capital')` در شش جای SQL
//    دستی تکرار شده بود؛ نوعِ تازه‌ای که فقط به `IN_KINDS` اضافه می‌شد آن‌جاها جا
//    می‌ماند و مانده‌ی صندوق بی‌صدا غلط می‌شد. حالا همه از `BizPay::IN_KINDS`/
//    `OUT_KINDS` (`sqlList()`، `BizCash::balanceSql()`) ساخته می‌شوند.
T::group('قاعده ۷۴ — فهرستِ نوع‌های پرداختِ فروشگاه یک‌جا');
$bad74 = [];
$kindRe = "~'receipt'\s*,\s*'income'\s*,\s*'capital'|'payment'\s*,\s*'expense'\s*,\s*'drawing'~";
foreach (array_merge(glob(__DIR__ . '/../includes/biz*.php') ?: [], glob(__DIR__ . '/../store/*.php') ?: []) as $f74) {
    $src = (string)@file_get_contents($f74);
    $n = preg_match_all($kindRe, $src);
    $allowed = basename($f74) === 'biz_docs.php' ? 2 : 0;   // خودِ IN_KINDS و OUT_KINDS
    if ($n > $allowed) { $bad74[] = basename($f74) . " — فهرستِ دستیِ نوع‌ها ({$n})"; }
}
T::bulk(1, $bad74, 'نوع‌های دریافت/پرداخت فقط از BizPay::IN_KINDS/OUT_KINDS');

// ---------------------------------------------------------------
// قاعده ۷۵ — «خطاهای فروشگاه» فقط برای مدیرِ نصب، و فقط محیطِ فروشگاه
// ---------------------------------------------------------------
// ⛔ خواسته‌ی مالکِ نصب: «سیستم لاگ برای باگ در فروشگاه — فقط برای سوپر ادمین».
//    رفتار در `tests/test_store_errors.php` سنجیده می‌شود؛ اینجا *شکل*ی است که
//    شکستنش بی‌صدا نشت می‌دهد: صفحه‌ای که مالکِ فروشگاه هم ببیند، منویی که نامش
//    را لو بدهد، یا «پاک کردن همه»ای که خطاهای حساب‌لند را هم ببرد.
T::group('قاعده ۷۵ — خطاهای فروشگاه: فقط مدیر، فقط محیطِ فروشگاه');
$bad75 = [];
$strip75 = static fn(string $f): string => (string)preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', (string)@file_get_contents(__DIR__ . '/../' . $f));
$se75 = $strip75('store/errors.php');
// ۱) مدیر، پیش از هر نوشتن و رندر — و ۴۰۴ِ خنثی، نه ۴۰۳
$gateAt = strpos($se75, "if (!Auth::isAdmin()) { Biz::notFound(); }");
$postAt = strpos($se75, "REQUEST_METHOD");
if ($gateAt === false || strpos($se75, 'Biz::requirePage();') === false) {
    $bad75[] = 'store/errors.php — نگهبانِ `requirePage()` + «غیرِ مدیر ← Biz::notFound()» نیست';
} elseif ($postAt === false || $gateAt > $postAt) {
    $bad75[] = 'store/errors.php — نگهبانِ مدیر بعد از پردازشِ POST است';
}
// ۲) هر فراخوانیِ AppErrors در صفحه‌ی فروشگاه محیط دارد
if (!preg_match("/\\\$area\s*=\s*'store';/", $se75)) { $bad75[] = "store/errors.php — `\$area = 'store'` نیست"; }
foreach (['handleAction' => 3, 'browse' => 3, 'openCount' => 1] as $fn => $argc) {
    if (!preg_match_all('/AppErrors::' . $fn . '\(([^;]*)\)/', $se75, $mm)) { $bad75[] = "store/errors.php — AppErrors::{$fn} صدا زده نمی‌شود"; continue; }
    foreach ($mm[1] as $args) {
        if (!str_contains($args, '$area')) { $bad75[] = "store/errors.php — AppErrors::{$fn}() بی‌محیط (همه‌ی محیط‌ها را می‌بیند/می‌برد)"; }
    }
}
foreach (['resolve(', 'reopen(', 'purgeResolved(', 'clear('] as $raw) {
    if (str_contains($se75, 'AppErrors::' . $raw)) { $bad75[] = "store/errors.php — AppErrors::{$raw}) مستقیم؛ فقط handleAction با محیط"; }
}
// ۳) نه در منوی مشترک: NAV/NAV_PARENT/NEW_MENU/TABBAR از آن خبر ندارند
foreach (['NAV', 'NAV_PARENT', 'NEW_MENU'] as $c75) {
    $v = constant('Biz::' . $c75);
    $flat = $c75 === 'NAV' ? array_merge(...array_values(array_map('array_keys', $v))) : array_merge(array_keys($v), array_values($v));
    if (in_array('errors.php', $flat, true)) { $bad75[] = "Biz::{$c75} — errors.php در منوی مشترک است (مالکِ فروشگاه می‌بیندش)"; }
}
if (in_array('errors.php', Biz::TABBAR, true)) { $bad75[] = 'Biz::TABBAR — errors.php'; }
// ۴) شمارشِ نوارِ کناری فقط برای مدیر و فقط فروشگاه
$bh75 = $strip75('includes/biz_head.php');
if (!preg_match("/Auth::isAdmin\(\)\s*\?\s*AppErrors::openCount\('store'\)\s*:\s*null/", $bh75)) {
    $bad75[] = "biz_head.php — شمارشِ خطا مشروط به `Auth::isAdmin()` و محیطِ 'store' نیست";
}
// ۵) پیشوندِ مسیر = Biz::DIR، و محیطِ مرورگر از فهرستِ بسته
if (AppErrors::STORE_PREFIX !== Biz::DIR . '/') { $bad75[] = 'AppErrors::STORE_PREFIX با Biz::DIR یکی نیست'; }
if (!preg_match("/'area'\s*=>\s*isset\(AppErrors::AREAS\[\\\$area\]\)/", $strip75('api/log_client_error.php'))) {
    $bad75[] = 'api/log_client_error.php — محیطِ مرورگر با AppErrors::AREAS سنجیده نمی‌شود';
}
// ۶) migration در هر دو فهرستِ migrate.sh
$mig75 = (string)file_get_contents(__DIR__ . '/../deploy/migrate.sh');
if (!preg_match('/^\s*migration_error_area\.sql\s*$/m', $mig75) || !str_contains($mig75, '[migration_error_area.sql]=')) {
    $bad75[] = 'migrate.sh — migration_error_area.sql در MIGRATIONS یا SENTINEL نیست';
}
T::bulk(6, $bad75, 'صفحه فقط برای مدیر، هر کارش فقط روی فروشگاه، و پنهان از منوی مشترک');

// ---------------------------------------------------------------
// قاعده ۷۶ — «تأمینِ موجودی» یک فاکتورِ خریدِ واقعی است، نه نسخه‌ی دوم
// ---------------------------------------------------------------
// ⛔ خواسته‌ی مالکِ نصب: «همون‌جا توی فاکتور به کالا موجودی بدم — از چه کسی،
//    به چه قیمتی». خطرِ این قابلیت میان‌بُر است: موجودی با انبارگردانی (بی‌بها،
//    بی‌طلبکار) یا با INSERTِ مستقیم (منطقِ خریدِ دوم). رفتار در
//    `tests/test_store_quickbuy.php`؛ اینجا شکلی که شکستنش بی‌صدا حساب را دروغ می‌کند.
T::group('قاعده ۷۶ — تأمینِ موجودی فقط از راهِ فاکتورِ خرید');
$bad76 = [];
$qb76 = (string)preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', (string)@file_get_contents(__DIR__ . '/../includes/biz_quickbuy.php'));
foreach (['BizInvoices::saveDraft($userId, \'purchase\'', 'BizInvoices::issue(', 'BizInvoices::deleteDraft('] as $need) {
    if (!str_contains($qb76, $need)) { $bad76[] = "biz_quickbuy.php — «{$need}» نیست"; }
}
// ⚠ `postDoc()` مجاز است ولی فقط یک بار و داخلِ `linkLine()` (پایین‌تر سنجیده می‌شود)
if (preg_match('/INSERT\s+INTO\s+biz_|BizStock::(adjustTo|setOpening)|UPDATE\s+biz_products\s+SET\s+stock/i', $qb76)
    || substr_count($qb76, 'BizStock::postDoc(') !== 1) {
    $bad76[] = 'biz_quickbuy.php — موجودی/سند را مستقیم می‌نویسد (انبارگردانی یا INSERT)؛ فقط saveDraft + issue';
}
// قیمتِ فروشِ کالای وصل به نرخِ روز فقط با `BizRates::apply()` (قاعده ۷۰)
if (!preg_match("/rate_code[^;]*\)\s*===\s*''\)\s*\{\s*Database::getConnection\(\)->prepare\('UPDATE biz_products SET sell_price/s", $qb76)) {
    $bad76[] = 'biz_quickbuy.php — به‌روزرسانیِ قیمتِ فروش پشتِ شرطِ «وصل به نرخ نیست» نیست';
}
$ie76 = (string)@file_get_contents(__DIR__ . '/../store/invoice-edit.php');
if (!str_contains($ie76, "BizOnce::claim() !== null") || !str_contains($ie76, 'BizQuickBuy::run(')) {
    $bad76[] = 'invoice-edit.php — پنلِ تأمین بی‌`BizOnce` یا بی‌`BizQuickBuy::run()`';
}
if (!str_contains($ie76, "' data-sp-ok'") || !str_contains((string)@file_get_contents(__DIR__ . '/../assets/js/store.js'), "b.name = 'sp_open'")) {
    $bad76[] = 'store.js/invoice-edit.php — «+ موجودی»ِ زنده (بی‌بارگذاری) رفته';
}
// ⛔ «فقط موجودی بده»: وصلِ ردیف از راهِ `postDoc()` و در سرگذشت، و هیچ پرداخت/سندِ تازه‌ای
$lkAt = strpos($qb76, 'public static function linkLine(');
$lkBody = $lkAt === false ? '' : substr($qb76, $lkAt, (int)strpos($qb76, 'public static function issueDraft(', $lkAt) - $lkAt);
if ($lkBody === '' || !str_contains($lkBody, 'BizStock::postDoc(') || !str_contains($lkBody, "'stock_link'") || !str_contains($lkBody, 'Biz::lockShop(')) {
    $bad76[] = 'linkLine() — بی‌postDoc، بی‌lockShop یا بی‌سرگذشت';
}
if (preg_match('/BizPay::|saveDraft\(|issue\(|INSERT\s+INTO/i', $lkBody)) {
    $bad76[] = 'linkLine() — پول یا سندِ تازه می‌سازد؛ «فقط موجودی» یعنی هیچ‌کدام';
}
$pp76 = (string)@file_get_contents(__DIR__ . '/../store/product.php');
if (!str_contains($pp76, "sanitizeQty(postParam('actual_qty')) > (float)\$product['stock_qty'] + 0.0005 && trim(postParam('note')) === ''")) {
    $bad76[] = 'product.php — افزایشِ موجودی با انبارگردانی بی‌دلیل پذیرفته می‌شود';
}
T::bulk(7, $bad76, 'خرید با saveDraft + issue، «فقط موجودی» بی‌پول، بی‌انبارگردانیِ بی‌دلیل، قیمتِ نرخ‌دار دست‌نخورده، یک‌بارمصرف');

exit(T::report());
