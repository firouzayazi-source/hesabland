<?php
/**
 * ⛔ نقشِ محدود («پشتیبان») و نقشِ برچسبی («همکار»)
 *
 * **خواسته‌ی مالکِ نصب:** «امکان اضافه کردن ادمین با دسترسی محدود مثلاً
 * پشتیبانی هم اضافه بشه از خود یوزرهای قبلی قابل انتخاب باشه» و «الان
 * دونوع یوزر داریم مدیر و کاربر عادی یک گزینه همکار هم میخوام که همین
 * ۴ نفری که حسابداری به حسابشون وصل شده در اون دسته قرار می‌گیرن».
 *
 * ⛔ **خطرِ اصلیِ کلِ این کار یک چیز است: یک نقشِ «محدود» که محدود
 *    نباشد.** `Auth::isAdmin()` در بیش از بیست جا خوانده می‌شود —
 *    کاربران، اشتراک، دسته‌بندی، آمار، سهامداران، شاخه‌ی «پیوستِ هر
 *    کاربری را ببین» در `api/view_support_file.php`، و جزئیاتِ
 *    `health.php`. باز کردنِ آن یک تابع برای «پشتیبان»، ساده‌ترین راهِ
 *    رسیدن به صفحه‌ی پشتیبانی بود و **بی‌صدا** همه‌ی آن‌ها را هم باز
 *    می‌کرد. پس بیشترِ بررسی‌های این فایل «چه چیزی **نباید** باز باشد»
 *    را می‌سنجند، نه «چه چیزی باز است».
 *
 * ⚠ و «همکار» عمداً **هیچ** دسترسی‌ای نمی‌دهد: یک برچسب است. اگر روزی
 *   کسی به آن توانایی بدهد، بررسی‌های گروهِ ۴ قرمز می‌شوند — چون آن
 *   دیگر همان چیزی نیست که خواسته شده بود.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';

$root = dirname(__DIR__);

const RPREFIX = '__role_';

/**
 * ⛔ فهرستِ **بسته**ی صفحه‌های مدیر که «پشتیبان» نباید ببیند.
 *
 * صفحه‌ی تازه‌ی `admin/` که اینجا نباشد، بی‌صدا بیرونِ پوشش می‌ماند —
 * همان قاعده‌ی `EXPECT` و `BUDGET`. نبودنِ هر کدام روی دیسک هم خودش
 * یک خطاست، وگرنه با تغییرِ نام، بررسی **ناپدید** می‌شود و تست سبز
 * می‌ماند.
 */
const ADMIN_ONLY_PAGES = [
    'admin/users.php',
    'admin/access.php',
    'admin/categories.php',
    'admin/billing.php',
    'admin/insights.php',
    'admin/errors.php',
    'admin/store-share.php',
];

/** صفحه‌هایی که «پشتیبان» **باید** ببیند — وگرنه نقش بی‌مصرف است. */
const SUPPORT_PAGES = [
    'admin/support.php',
    'admin/support-content.php',
];

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::blocked('تست نقش‌ها', 'اتصال به دیتابیس برقرار نشد: ' . $e->getMessage());
    exit(T::report());
}

// ⛔ بدونِ migration، ستونِ `role` هنوز `ENUM('admin','user')` است و
//    درجِ `support` را **بی‌صدا** به رشته‌ی خالی تبدیل می‌کند (یا با
//    strict mode خطا می‌دهد). پس اینجا `blocked` است نه `skip`:
//    هیچ بررسی‌ای بدونِ آن معنا ندارد.
$roleCol = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
if (!$roleCol || stripos((string)$roleCol['Type'], 'varchar') === false) {
    T::blocked('تست نقش‌ها', 'ستونِ users.role هنوز VARCHAR نشده — اول: bash deploy/migrate.sh --apply');
    exit(T::report());
}

$wipe = function () use ($pdo) {
    $ids = $pdo->query("SELECT id FROM users WHERE username LIKE '" . RPREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        foreach (userDataTables() as $t) {
            try { $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]); }
            catch (PDOException $e) { /* جدولی که نیست */ }
        }
    }
    $pdo->exec("DELETE FROM users WHERE username LIKE '" . RPREFIX . "%'");
    $pdo->exec("DELETE FROM login_attempts WHERE username_tried LIKE '" . RPREFIX . "%'");
};
$wipe();

// =================================================================
T::group('۱ — فهرستِ نقش‌ها تنها مرجع است');

T::ok(array_key_exists('admin', Auth::ROLES) && array_key_exists('support', Auth::ROLES)
    && array_key_exists('colleague', Auth::ROLES) && array_key_exists('user', Auth::ROLES),
    'هر چهار نقش در `Auth::ROLES` هستند');

T::same(['admin', 'support'], Auth::CAPS,
    '⛔ فهرستِ توانایی‌ها بسته است',
    'تواناییِ تازه یعنی یک گیتِ تازه؛ باید عمدی باشد نه تصادفی');

// ⛔ `createUserAccount()` باید هر چهار را بپذیرد. تا دیروز آرایه‌ی
//    محلی‌اش `['admin','user']` بود و نقشِ انتخاب‌شده را **بی‌صدا** به
//    `user` برمی‌گرداند: صفحه می‌گفت «ساخته شد» و نقش نمی‌نشست.
foreach (array_keys(Auth::ROLES) as $r) {
    $res = createUserAccount($pdo, 'نقشِ ' . $r, RPREFIX . $r, RPREFIX . $r . '@example.com', 'Rol12345', $r);
    T::ok($res['ok'] ?? false, "کاربر با نقشِ «{$r}» ساخته شد", $res['error'] ?? '');
    $got = $pdo->prepare('SELECT role FROM users WHERE username = :u');
    $got->execute(['u' => RPREFIX . $r]);
    T::same($r, (string)$got->fetchColumn(), "⛔ نقشِ «{$r}» واقعاً ذخیره شد");
}

// و نقشِ ناشناخته به سمتِ **بسته** می‌افتد، نه به خطا.
$res = createUserAccount($pdo, 'نقشِ جعلی', RPREFIX . 'bogus', RPREFIX . 'bogus@example.com', 'Rol12345', 'superadmin');
T::ok($res['ok'] ?? false, 'کاربر با نقشِ ناشناخته هم ساخته می‌شود');
$got = $pdo->prepare('SELECT role FROM users WHERE username = :u');
$got->execute(['u' => RPREFIX . 'bogus']);
T::same('user', (string)$got->fetchColumn(),
    '⛔ نقشِ ناشناخته به `user` می‌افتد، نه به چیزی بازتر');

// =================================================================
T::group('۲ — `can()` و `isAdmin()` در سطحِ تابع');

/** نشستِ ساختگی، بدونِ HTTP — برای سنجشِ خودِ منطق. */
$asRole = function (string $role) {
    $_SESSION['user_id']    = 1;
    $_SESSION['role']       = $role;
    $_SESSION['login_time'] = time();
    $_SESSION['last_seen']  = time();
};
if (session_status() === PHP_SESSION_NONE) { @session_start(); }

$asRole('admin');
T::ok(Auth::isAdmin(), 'مدیر: `isAdmin()` درست است');
T::ok(Auth::can('admin') && Auth::can('support'), 'مدیر همه‌ی توانایی‌ها را دارد');
T::ok(Auth::hasAnyCap(), 'مدیر بخشِ مدیریت را می‌بیند');

$asRole('support');
T::ok(!Auth::isAdmin(),
    '⛔ پشتیبان `isAdmin()` **نیست**',
    'باز کردنِ این تابع، بیست‌ویک گیتِ دیگر را هم بی‌صدا باز می‌کرد');
T::ok(!Auth::can('admin'), '⛔ پشتیبان تواناییِ `admin` ندارد');
T::ok(Auth::can('support'), 'پشتیبان تواناییِ `support` دارد');
T::ok(Auth::hasAnyCap(), 'پشتیبان بخشِ مدیریت را می‌بیند');

foreach (['colleague', 'user'] as $r) {
    $asRole($r);
    T::ok(!Auth::isAdmin() && !Auth::can('admin') && !Auth::can('support'),
        "⛔ «{$r}» هیچ تواناییِ مدیریتی ندارد");
    T::ok(!Auth::hasAnyCap(), "«{$r}» بخشِ مدیریت را نمی‌بیند");
}

// ⛔ تواناییِ بیرونِ `CAPS` همیشه `false` است — حتی برای مدیر. با
//    پیش‌فرضِ باز، یک نامِ اشتباه‌تایپ‌شده صفحه را برای همه باز می‌کرد.
$asRole('admin');
T::ok(!Auth::can('superuser') && !Auth::can(''),
    '⛔ تواناییِ ناشناخته برای مدیر هم `false` است');

$_SESSION = [];
T::ok(!Auth::isAdmin() && !Auth::can('support') && !Auth::hasAnyCap(),
    'بدونِ ورود هیچ تواناییِ‌ای نیست');

// =================================================================
T::group('۳ — «همکار» فقط یک برچسب است');

T::same('همکار', Auth::roleLabel('colleague'), 'برچسبِ فارسیِ «همکار»');
T::same('پشتیبان', Auth::roleLabel('support'), 'برچسبِ فارسیِ «پشتیبان»');
T::same('کاربر', Auth::roleLabel('nope'),
    'نقشِ ناشناخته «کاربر» خوانده می‌شود، نه رشته‌ی خام');

// ⛔ آخرین مدیر نباید بتواند نقشش را از دست بدهد — با هیچ نقشی.
//    شرطِ قدیمی `$role === 'user'` بود و «مدیر → همکار» از کنارش رد
//    می‌شد: پنل برای همیشه بسته می‌شد، بی‌هیچ خطایی.
$usersSrc = (string)file_get_contents($root . '/admin/users.php');
T::ok(substr_count($usersSrc, "\$targetUser['role'] === 'admin' && \$role !== 'admin'") >= 2,
    '⛔ نگهبانِ «آخرین مدیر» هر نقشِ غیرِ admin را می‌گیرد',
    "با `=== 'user'` تنها، «مدیر → همکار» پنل را می‌بست");

// =================================================================
T::group('۴ — رفتارِ واقعی روی HTTP');

$port = 0;
for ($p = 8960; $p <= 8995; $p++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
    if ($sock) { fclose($sock); $port = $p; break; }
}
if (!$port) {
    T::blocked('تست نقش‌ها (HTTP)', 'پورت آزاد پیدا نشد');
    $wipe();
    exit(T::report());
}

$log = tempnam(sys_get_temp_dir(), 'rol');
$pid = (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
    $port, escapeshellarg($root), escapeshellarg($log))));

$up = false;
for ($i = 0; $i < 40; $i++) {
    usleep(150000);
    $s = @fsockopen('127.0.0.1', $port, $a, $b, 0.3);
    if ($s) { fclose($s); $up = true; break; }
}
if (!$up) {
    T::ok(false, 'سرور آزمایشی بالا آمد', 'لاگ: ' . substr((string)@file_get_contents($log), 0, 300));
    exec("kill $pid 2>/dev/null");
    $wipe();
    exit(T::report());
}

/** یک نشستِ curl مستقل به‌ازای هر نقش. */
$session = function (string $username, string $password) use ($port): callable {
    $jar = tempnam(sys_get_temp_dir(), 'roljar');
    $req = function (string $path, ?array $post = null) use ($port, $jar): array {
        $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 40,
        ]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $raw  = (string)curl_exec($ch);
        $hlen = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, substr($raw, $hlen)];
    };
    [, $html] = $req('login.php');
    preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m);
    $req('login.php', ['csrf_token' => $m[1] ?? '', 'username' => $username, 'password' => $password]);
    return $req;
};

// رمزِ کاربرانِ ساخته‌شده در گروهِ ۱ همان `Rol12345` است.
$asAdmin     = $session(RPREFIX . 'admin', 'Rol12345');
$asSupport   = $session(RPREFIX . 'support', 'Rol12345');
$asColleague = $session(RPREFIX . 'colleague', 'Rol12345');
$asUser      = $session(RPREFIX . 'user', 'Rol12345');

// ⛔ اول ثابت می‌کنیم نشست‌ها واقعاً برقرارند — وگرنه «۴۰۳ گرفت» ممکن
//    است فقط یعنی «اصلاً وارد نشده» و همه‌ی بررسی‌های زیر **پوچ**اند.
foreach ([['مدیر', $asAdmin], ['پشتیبان', $asSupport], ['همکار', $asColleague]] as [$who, $rq]) {
    [$c, $b] = $rq('index.php');
    T::ok($c === 200 && str_contains($b, '</html>'), "نشستِ «{$who}» برقرار است", "کد: {$c}");
}

// --- ۴.۱ صفحه‌های مدیر ---
foreach (ADMIN_ONLY_PAGES as $page) {
    T::ok(is_file($root . '/' . $page), "فایلِ {$page} روی دیسک هست",
        'نبودنش یعنی بررسیِ زیرش بی‌صدا ناپدید می‌شود');

    [$ca] = $asAdmin($page);
    T::same(200, $ca, "مدیر {$page} را می‌بیند");

    [$cs] = $asSupport($page);
    T::same(403, $cs, "⛔ پشتیبان {$page} را **نمی‌بیند**");

    [$cc] = $asColleague($page);
    T::same(403, $cc, "⛔ همکار {$page} را **نمی‌بیند**");
}

// --- ۴.۲ صفحه‌های پشتیبانی ---
foreach (SUPPORT_PAGES as $page) {
    T::ok(is_file($root . '/' . $page), "فایلِ {$page} روی دیسک هست");

    [$cs] = $asSupport($page);
    T::same(200, $cs, "پشتیبان {$page} را می‌بیند",
        'بدونِ آن، این نقش هیچ کاری نمی‌تواند بکند');

    [$ca] = $asAdmin($page);
    T::same(200, $ca, "مدیر هم {$page} را می‌بیند");

    [$cc] = $asColleague($page);
    T::same(403, $cc, "⛔ همکار {$page} را نمی‌بیند");
}

// --- ۴.۳ اندپوینتِ پیوست ---
// ⛔ با `isAdmin()` در آن فایل، پشتیبان تیکت را باز می‌کرد ولی پیوستش
//    **۴۰۴** می‌گرفت — یعنی دقیقاً همان کاری که برایش استخدام شده
//    انجام‌نشدنی می‌شد، بی‌آنکه هیچ‌جا بگوید چرا.
$viewSrc = (string)file_get_contents($root . '/api/view_support_file.php');
T::ok(str_contains($viewSrc, "Auth::can('support')") && !str_contains($viewSrc, 'Auth::isAdmin()'),
    '⛔ تحویلِ پیوست از `can(\'support\')` می‌رود، نه `isAdmin()`');

// --- ۴.۴ منوها ---
/**
 * ⛔ هر دو سطح **جدا** سنجیده می‌شوند — نوارِ کناریِ دسکتاپ و شیتِ
 *    ابزارهای موبایل.
 *
 * نسخه‌ی اولِ این بررسی کلِ HTML را می‌گشت و **هر دو جهشِ مربوطه را
 * زنده گذاشت**: با بستنِ نوارِ کناری، همان لینک از شیتِ فوتر پیدا
 * می‌شد و برعکس. یعنی دقیقاً همان باگی که یک بار سرِ «سررسیدها» افتاد
 * («روی گوشی درست است، پس مدرک نیست») از زیرِ تست رد می‌شد.
 */
$slice = function (string $html, string $from, string $to): string {
    $i = strpos($html, $from);
    if ($i === false) { return ''; }
    $j = strpos($html, $to, $i);
    return $j === false ? substr($html, $i) : substr($html, $i, $j - $i);
};
$sidebarOf = fn(string $h) => $slice($h, '<nav class="sidebar"', '</nav>');
$adminSheetOf = fn(string $h) => $slice($h, 'id="adminSheet"', '</div>
</div>');

[, $supHome] = $asSupport('index.php');
$supSide  = $sidebarOf($supHome);
$supSheet = $adminSheetOf($supHome);

T::ok($supSide !== '' , 'نوارِ کناری در HTML پیدا شد', 'وگرنه بررسی‌های زیرش پوچ‌اند');
T::ok(str_contains($supSide, 'admin/support.php'),
    '⛔ پشتیبان لینکِ پنلِ تیکت را در **نوارِ کناری** دارد');
$toolsOf = fn(string $h) => $slice($h, 'id="moreSheet"', 'id="adminSheet"') ?: $slice($h, 'id="moreSheet"', '</body>');
$supTools = $toolsOf($supHome);
T::ok($supTools !== '' && str_contains($supTools, 'admin/support.php'),
    '⛔ و در **شیتِ ابزارهای موبایل** یک کارتِ مستقیم دارد',
    'با گیتِ `isAdmin()` روی شیت، کاربرِ گوشی هیچ راهی نداشت');
/**
 * ⛔ **گزارشِ مالکِ نصب (با اسکرین‌شات):** پشتیبان یک درِ «مدیریت» با
 *    فقط یک قلمِ «پشتیبانی» پشتش می‌دید، کنارِ «پشتیبانی و راهنما».
 *    «مدیریت» فقط مالِ مدیر است.
 */
T::ok(!str_contains($supHome, 'js-open-admin') && $supSheet === '',
    '⛔ دکمه و شیتِ «مدیریت» برای پشتیبان رندر **نمی‌شود**');
T::ok(!str_contains($supSide, '>مدیریت<'),
    '⛔ و سرتیترِ «مدیریت» در نوارِ کناری هم برایش نیست');

T::ok(!str_contains($supHome, 'admin/users.php') && !str_contains($supHome, 'admin/billing.php'),
    '⛔ و لینکِ هیچ صفحه‌ی مدیرِ دیگری را ندارد');
T::ok(str_contains($supHome, 'پشتیبان'),
    '⛔ نشانِ نقش «پشتیبان» را نشان می‌دهد، نه «کاربر»',
    'با سه‌گانه‌ی قبلی، نقشی که مدیر داده هیچ‌جا دیده نمی‌شد');

[, $colHome] = $asColleague('index.php');
T::ok(!str_contains($colHome, 'admin/users.php') && !str_contains($colHome, 'admin/support.php'),
    '⛔ همکار هیچ لینکِ مدیریتی ندارد');
T::ok(!str_contains($colHome, 'js-open-admin'),
    '⛔ و دکمه‌ی «مدیریت» هم برایش رندر نمی‌شود',
    'سرتیترِ مدیریت بدونِ هیچ قلمی، همان «دکمه‌ی بی‌کار» است');
T::ok(str_contains($colHome, 'همکار'), 'نشانِ نقش «همکار» دیده می‌شود');

// ⛔ **گزارشِ مالکِ نصب:** «هیچ چیز مدیریتی نباید برای کاربر عادی بیاد».
//    کاربرِ عادی جدا سنجیده می‌شود، نه فقط از راهِ «همکار مثلِ کاربر است».
[, $usrHome] = $asUser('index.php');
T::ok($usrHome !== '' && str_contains($usrHome, '</html>'), 'خانه‌ی کاربرِ عادی رندر شد (پیش‌شرط)');
T::ok(!str_contains($usrHome, 'admin/') && !str_contains($usrHome, 'پاسخ به تیکت') && !str_contains($usrHome, 'js-open-admin'),
    '⛔ کاربرِ عادی هیچ لینک، کارت یا سرتیترِ مدیریتی ندارد');

[, $admHome] = $asAdmin('index.php');
$admSide = $sidebarOf($admHome);
T::ok(str_contains($admSide, 'admin/users.php') && str_contains($admSide, 'admin/support.php'),
    'مدیر هر دو دسته لینک را در نوارِ کناری دارد');
T::ok(str_contains($adminSheetOf($admHome), 'admin/users.php'),
    'و شیتِ مدیریتش هم کامل است');

// --- ۴.۵ فرمِ ساختِ کاربر ---
[, $usersPage] = $asAdmin('admin/users.php');
foreach (Auth::ROLES as $rk => $rlabel) {
    T::ok(str_contains($usersPage, 'value="' . $rk . '"'),
        "گزینه‌ی نقشِ «{$rlabel}» در فرم هست");
}
/*
 * ⛔ پیش‌فرضِ منو `user` است، نه اولین کلیدِ `Auth::ROLES` (که `admin`
 *    است). با تکیه بر ترتیبِ گزینه‌ها، هر کاربری که مدیر نقش را دست
 *    نمی‌زد **مدیر** ساخته می‌شد — بی‌هیچ خطایی.
 *
 * ⚠ شمارش `>= 2` است نه «وجود دارد»: صفحه **دو** منوی نقش دارد
 *   (ساخت و ویرایش) و با بررسیِ تک‌موردی، جهشِ «پیش‌فرض را بردار» روی
 *   یکی از آن دو **زنده می‌ماند** — چون آن یکی همان رشته را می‌سازد.
 */
T::ok(substr_count($usersPage, '<option value="user" selected>') >= 2,
    '⛔ پیش‌فرضِ **هر دو** منوی نقش صریحاً `user` است',
    'شمارشِ واقعی: ' . substr_count($usersPage, '<option value="user" selected>'));

/*
 * ⛔ و مسیرِ واقعیِ ساخت — نه فقط شکلِ فرم.
 *
 * `admin/users.php` فهرستِ نقشِ **خودش** را هم دارد (پیش از رسیدن به
 * `createUserAccount()`). با آرایه‌ی دستیِ `['admin','user']` آنجا،
 * نقشِ «پشتیبان» بی‌صدا به `user` برمی‌گشت و پیامِ سبزِ «کاربر ساخته
 * شد» هم می‌آمد — همان خرابیِ بی‌صدا، یک لایه بالاتر از گروهِ ۱.
 */
preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $usersPage, $mt);
$newName = RPREFIX . 'made';
$asAdmin('admin/users.php', [
    'csrf_token'       => $mt[1] ?? '',
    'action'           => 'create',
    'full_name'        => 'ساخته‌شده با نقش',
    'username'         => $newName,
    'email'            => $newName . '@example.com',
    'password'         => 'Rol12345',
    'password_confirm' => 'Rol12345',
    'role'             => 'support',
]);
$chk = $pdo->prepare('SELECT role FROM users WHERE username = :u');
$chk->execute(['u' => $newName]);
T::same('support', (string)$chk->fetchColumn(),
    '⛔ نقشِ انتخاب‌شده در فرمِ مدیر واقعاً ثبت می‌شود');

exec("kill $pid 2>/dev/null");
@unlink($log);
$wipe();
exit(T::report());
