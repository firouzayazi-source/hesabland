<?php
/**
 * تست رفتاری اندپوینت‌ها — واقعاً درخواست می‌فرستد.
 *
 * تست قرارداد (test_api_contract.php) شکل کد را می‌آزماید؛ این یکی رفتار
 * واقعی را. اگر کسی بررسی ورود را بگذارد ولی جایش اشتباه باشد (مثلاً بعد
 * از کوئری)، آن تست موفق می‌شود و این یکی شکست می‌خورد.
 *
 * ایمنی: فقط درخواست‌های بدون ورود می‌فرستد. چنین درخواستی طبق تعریف
 * نباید چیزی بنویسد، پس اجرای این تست حتی روی دیتابیس واقعی هم داده را
 * تغییر نمی‌دهد. اگر تغییری بدهد، خودش همان باگی است که دنبالش هستیم.
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

$root = realpath(__DIR__ . '/..');

T::group('آماده‌سازی سرور آزمایشی');

if (!file_exists($root . '/config/config.php')) {
    T::blocked('تست رفتاری اندپوینت‌ها', 'config/config.php وجود ندارد');
    exit(T::report());
}

// پورت آزاد پیدا کن
$port = 0;
for ($p = 8971; $p <= 8999; $p++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$p", $errno, $errstr);
    if ($sock) { fclose($sock); $port = $p; break; }
}
if (!$port) {
    T::skip('تست رفتاری اندپوینت‌ها', 'پورت آزاد پیدا نشد');
    exit(T::report());
}

$log = tempnam(sys_get_temp_dir(), 'apitest');
$cmd = sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
    $port, escapeshellarg($root), escapeshellarg($log));
$pid = (int)trim((string)shell_exec($cmd));

// صبر تا بالا آمدن
$up = false;
for ($i = 0; $i < 40; $i++) {
    usleep(150000);
    $s = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.3);
    if ($s) { fclose($s); $up = true; break; }
}
if (!$up) {
    T::ok(false, 'سرور آزمایشی بالا آمد', 'لاگ: ' . substr((string)@file_get_contents($log), 0, 300));
    if ($pid) { @exec("kill $pid 2>/dev/null"); }
    exit(T::report());
}
T::pass("سرور آزمایشی روی پورت $port بالا آمد");

/** یک درخواست بدون کوکی و بدون نشست */
$req = function (string $path, string $method = 'POST') use ($port): array {
    $ch = curl_init("http://127.0.0.1:$port$path");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_POSTFIELDS     => $method === 'POST' ? 'x=1' : null,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
};

// ---------------------------------------------------------------
T::group('بدون ورود، هیچ اندپوینتی نباید داده بدهد');

$files = glob($root . '/api/*.php');
sort($files);
$bad = [];
foreach ($files as $f) {
    $name = basename($f);
    [$code, $body] = $req("/api/$name");

    // ۴۰۱ پاسخ درست است. ۴۰۵ هم پذیرفتنی است اگر متد را زودتر رد کند،
    // ولی فقط وقتی بدنه هیچ داده‌ای لو نداده باشد.
    if ($code === 401) { continue; }
    if ($code === 403) { continue; }   // CSRF زودتر رد کرده — هنوز امن است

    if ($code === 200) {
        $bad[] = "$name → ۲۰۰ برگرداند! بدنه: " . substr(preg_replace('/\s+/', ' ', $body), 0, 90);
    } elseif ($code === 500) {
        $bad[] = "$name → خطای ۵۰۰ (به‌جای رد کردن مؤدبانه): "
               . substr(preg_replace('/\s+/', ' ', $body), 0, 90);
    } else {
        $bad[] = "$name → کد $code (انتظار ۴۰۱)";
    }
}
T::bulk(count($files), $bad, 'هر اندپوینت بدون ورود، دسترسی را رد می‌کند');

// ---------------------------------------------------------------
T::group('صفحات کاربر بدون ورود به صفحه‌ی ورود می‌روند');

$pages = ['dashboard.php', 'transactions.php', 'wallets.php', 'budget.php',
          'debts.php', 'cheques.php', 'savings.php', 'my-assets.php',
          'recurring.php', 'search.php', 'calendar.php', 'profile.php',
          // محیطِ فروشگاهی → ورودِ **فروشگاه**
          'store/index.php', 'store/settings.php', 'store/products.php', 'store/product.php',
          'store/parties.php', 'store/party.php', 'store/products-io.php', 'store/reports.php',
          'store/print-settings.php', 'store/print.php'];
$bad = [];
foreach ($pages as $p) {
    [$code, $body] = $req("/$p", 'GET');
    // انتظار: ریدایرکت به login (۳۰۲) — نه نمایش محتوا و نه خطای ۵۰۰
    if ($code === 302 || $code === 303) { continue; }
    $bad[] = "$p → کد $code (انتظار ۳۰۲ به صفحه‌ی ورود)";
}
T::bulk(count($pages), $bad, 'صفحات محافظت‌شده بدون ورود محتوا نشان نمی‌دهند');

// ---------------------------------------------------------------
T::group('فایل‌های حساس از راه وب در دسترس نیستند');

$secret = [
    '/config/config.php'    => 'تنظیمات دیتابیس',
    '/schema.sql'           => 'ساختار دیتابیس',
    '/migration_p4.sql'     => 'فایل migration',
    '/deploy.sh'            => 'اسکریپت استقرار',
    '/CLAUDE.md'            => 'مستندات داخلی',
];
$bad = [];
foreach ($secret as $path => $what) {
    [$code, $body] = $req($path, 'GET');
    // config.php وقتی اجرا شود بدنه‌ی خالی و ۲۰۰ می‌دهد — لو نمی‌رود.
    // بقیه نباید محتوایشان برگردد.
    if ($path === '/config/config.php') {
        if (trim($body) !== '') { $bad[] = "$path محتوا برگرداند ($what)"; }
        continue;
    }
    if ($code === 200 && trim($body) !== '') {
        $bad[] = "$path با کد ۲۰۰ محتوا برگرداند ($what) — روی سرور واقعی nginx مسدودش می‌کند";
    }
}
// این تست روی سرور داخلی PHP اجرا می‌شود که قواعد nginx را ندارد،
// پس نتیجه‌اش فقط اطلاع‌رسانی است نه شکست.
if ($bad) {
    T::skip('مسدود بودن فایل‌های حساس', count($bad) . ' مورد — سرور داخلی PHP قواعد nginx را ندارد');
    foreach ($bad as $b) { printf("        \033[0;90m· %s\033[0m\n", $b); }
} else {
    T::pass('فایل‌های حساس محتوا برنمی‌گردانند');
}

// ---------------------------------------------------------------
T::group('با ورود، هیچ اندپوینتی نباید ۵۰۰ بدهد');

// چرا این گروه لازم شد: همه‌ی بررسی‌های بالا *بدون ورود* اند، پس روی
// خطِ «ابتدا وارد شوید» متوقف می‌شوند و هرگز به بدنه‌ی اندپوینت
// نمی‌رسند. یک بار دقیقاً همین‌جا یک باگ رد شد:
// `api/add_transaction.php` و `api/update_transaction.php` تابع
// `categoryScopeParams($userId)` را صدا می‌زدند در حالی که `$userId`
// در آن دو فایل هرگز تعریف نشده بود. نتیجه: خطای کشنده‌ی PHP، پاسخ
// ۵۰۰ با بدنه‌ی خالی، و در مرورگر فقط «خطا در ارتباط با سرور» —
// یعنی ثبت و ویرایش تراکنشِ دسته‌دار اصلاً کار نمی‌کرد.
//
// `php -l` متغیر تعریف‌نشده را نمی‌گیرد و تست قرارداد هم شکل کد را
// می‌بیند نه اجرایش. تنها چیزی که این را می‌گیرد، همین است: واقعاً
// صدا زدنِ اندپوینت با یک نشستِ معتبر.
//
// ایمنی: کاربر تازه‌ای ساخته می‌شود که هیچ داده‌ای ندارد، و ورودی‌ها
// عمداً ناقص‌اند تا اعتبارسنجی ردشان کند. انتظار ما ۴xx است، نه ۲۰۰.
$smokeUser = '__test_api_smoke';
$smokePass = 'SmokePass12345';

/**
 * ⛔ پاک کردنِ کاربرِ تست با فهرستی که از **خودِ دیتابیس** کشف می‌شود،
 *    نه یک فهرستِ دستی.
 *
 *    نسخه‌ی قبلی سه جدول را دستی نام می‌برد (`transactions`, `wallets`,
 *    `categories`). با آمدنِ هر جدولِ تازه، یک ردیفِ جامانده کلیدِ خارجی
 *    را نگه می‌داشت و `DELETE FROM users` شکست می‌خورد — و آن‌وقت
 *    **کلِ لایه‌ی «با ورود»** با پیامِ «اتصال به دیتابیس برقرار نشد»
 *    رد می‌شد. یعنی مهم‌ترین بخشِ این تست ماه‌ها اجرا نمی‌شد و مجموعه
 *    سبز می‌ماند: دقیقاً همان خرابیِ بی‌صدایی که این فایل برای گرفتنش
 *    نوشته شده.
 */
$purgeUser = function (string $username) use ($root) {
    require_once $root . '/includes/functions.php';
    require_once $root . '/includes/user_data.php';
    $pdo = Database::getConnection();
    $st  = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $username]);
    $id = $st->fetchColumn();
    if (!$id) { return; }

    // چند دور، چون ترتیبِ کلیدهای خارجی از قبل معلوم نیست — همان
    // الگوی `deleteUserAccount()`.
    $tables = userDataTables();
    for ($pass = 0; $pass < 4 && $tables; $pass++) {
        $left = [];
        foreach ($tables as $t) {
            try { $pdo->prepare("DELETE FROM `$t` WHERE user_id = :u")->execute(['u' => $id]); }
            catch (PDOException $e) { $left[] = $t; }
        }
        if (count($left) === count($tables)) { break; }
        $tables = $left;
    }
    $pdo->prepare('DELETE FROM users WHERE id = :i')->execute(['i' => $id]);
};

$pdoOk = false;
try {
    require_once $root . '/includes/db.php';
    $pdo = Database::getConnection();
    $purgeUser($smokeUser);
    $pdo->prepare(
        "INSERT INTO users (username, password_hash, full_name, role, is_active)
         VALUES (:u, :p, 'کاربر تست اندپوینت', 'user', 1)"
    )->execute(['u' => $smokeUser, 'p' => password_hash($smokePass, PASSWORD_DEFAULT)]);
    $smokeUserId = (int)$pdo->lastInsertId();
    $pdoOk = true;
} catch (Throwable $e) {
    T::blocked('تست اندپوینت‌ها با ورود', 'اتصال به دیتابیس برقرار نشد');
}

if ($pdoOk) {
    $jar = tempnam(sys_get_temp_dir(), 'apijar');

    /** درخواست با کوکی — نشست بین فراخوانی‌ها می‌ماند. */
    $sess = function (string $path, ?array $fields = null, string $method = 'GET') use ($port, $jar): array {
        $ch = curl_init("http://127.0.0.1:$port$path");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_COOKIEJAR      => $jar,
            CURLOPT_COOKIEFILE     => $jar,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 10,
        ]);
        if ($fields !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields)); }
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $body];
    };

    // ورود واقعی: توکن CSRF از خودِ صفحه گرفته می‌شود
    [, $loginHtml] = $sess('/login.php');
    preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $loginHtml, $m);
    $token = $m[1] ?? '';
    T::ok($token !== '', 'توکن CSRF از صفحه‌ی ورود گرفته شد');

    [$lc] = $sess('/login.php', [
        'csrf_token' => $token, 'username' => $smokeUser, 'password' => $smokePass,
    ], 'POST');
    T::same(302, $lc, 'ورود کاربر آزمایشی انجام شد');

    // توکن بعد از ورود عوض می‌شود (session_regenerate_id)، پس دوباره می‌گیریم
    [, $home] = $sess('/index.php');
    preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $home, $m2);
    $token = $m2[1] ?? $token;

    $files = glob($root . '/api/*.php');
    sort($files);
    $bad = [];
    foreach ($files as $f) {
        $name = basename($f);
        [$code, $body] = $sess("/api/$name", ['csrf_token' => $token], 'POST');

        if ($code >= 500) {
            $snippet = trim(preg_replace('/\s+/', ' ', $body));
            $bad[] = "$name → کد $code" . ($snippet === '' ? ' با بدنه‌ی خالی (خطای کشنده‌ی PHP)' : ': ' . substr($snippet, 0, 80));
            continue;
        }
        // پاسخ باید JSON باشد. اندپوینتی که فایل می‌دهد (مثل دانلود پیوست)
        // استثناست و فقط وقتی شمرده می‌شود که JSON اعلام کرده باشد.
        if ($body !== '' && $body[0] === '{' && json_decode($body) === null) {
            $bad[] = "$name → بدنه شبیه JSON است ولی پارس نمی‌شود";
        }
    }
    T::bulk(count($files), $bad, 'هیچ اندپوینتی با نشستِ معتبر خطای ۵۰۰ نمی‌دهد');

    // -----------------------------------------------------------
    T::group('مسیر واقعیِ پول: ثبت و ویرایش تراکنشِ دسته‌دار');

    // بررسی بالا با ورودیِ خالی کافی نیست و این را به سختی یاد گرفتیم:
    // خطِ باگ‌دار داخل `if ($categoryIdValue !== null)` بود، پس بدون
    // فرستادنِ `category_id` هرگز اجرا نمی‌شد و تست سبز می‌ماند.
    // این بخش عمداً یک پیلود کامل و واقعی می‌فرستد.
    $catId = null;
    try {
        $cs = $pdo->query("SELECT id FROM categories WHERE type = 'expense' AND is_active = 1 LIMIT 1");
        $catId = $cs ? $cs->fetchColumn() : null;
    } catch (PDOException $e) { /* ignore */ }

    if (!$catId) {
        T::skip('مسیر واقعیِ پول', 'هیچ دسته‌بندیِ هزینه‌ای در دیتابیس نیست');
    } else {
        $payload = [
            'csrf_token'       => $token,
            'type'             => 'expense',
            'amount'           => '125000',
            'title'            => 'تست دود اندپوینت',
            'note'             => '',
            'transaction_date' => date('Y-m-d'),
            'category_id'      => (string)$catId,
        ];

        [$c1, $b1] = $sess('/api/add_transaction.php', $payload, 'POST');
        $j1 = json_decode($b1, true);
        T::same(200, $c1, 'ثبت تراکنشِ دسته‌دار پاسخ ۲۰۰ می‌دهد',
            $c1 >= 500 ? 'بدنه: ' . substr(trim($b1), 0, 120) . ' (بدنه‌ی خالی = خطای کشنده‌ی PHP)' : '');
        T::ok(is_array($j1), 'پاسخ ثبت، JSON معتبر است');
        T::ok(!empty($j1['success']), 'ثبت تراکنش موفق بود', $j1['message'] ?? '');

        // حالا همان تراکنش را ویرایش می‌کنیم — مسیر دومی که شکسته بود
        $txId = null;
        try {
            $st = $pdo->prepare(
                'SELECT t.id FROM transactions t JOIN users u ON u.id = t.user_id
                 WHERE u.username = :u ORDER BY t.id DESC LIMIT 1'
            );
            $st->execute(['u' => $smokeUser]);
            $txId = $st->fetchColumn();
        } catch (PDOException $e) { /* ignore */ }

        if (!$txId) {
            T::ok(false, 'تراکنش تازه در دیتابیس پیدا شد');
        } else {
            $payload['transaction_id'] = (string)$txId;
            $payload['amount']         = '175000';
            [$c2, $b2] = $sess('/api/update_transaction.php', $payload, 'POST');
            $j2 = json_decode($b2, true);
            T::same(200, $c2, 'ویرایش تراکنشِ دسته‌دار پاسخ ۲۰۰ می‌دهد',
                $c2 >= 500 ? 'بدنه: ' . substr(trim($b2), 0, 120) . ' (بدنه‌ی خالی = خطای کشنده‌ی PHP)' : '');
            T::ok(is_array($j2), 'پاسخ ویرایش، JSON معتبر است');
            T::ok(!empty($j2['success']), 'ویرایش تراکنش موفق بود', $j2['message'] ?? '');
        }
    }

    // -----------------------------------------------------------
    // ⛔ همان درسِ بالا، یک بارِ دیگر و روی مسیرِ دیگری: بررسیِ «۵۰۰
    //    نمی‌دهد» با ورودیِ خالی، `INSERT` این اندپوینت را **اصلاً**
    //    اجرا نمی‌کند (عنوانِ خالی همان اول با ۴۲۲ برمی‌گردد). پس یک
    //    کامنتِ پی‌اچ‌پی که داخلِ رشته‌ی SQL جا مانده بود ماه‌ها زنده
    //    ماند: `php -l` سبز، تستِ قرارداد سبز، ویرایش و «انجام شد» هم
    //    سالم — و ثبتِ هر یادآورِ تازه بی‌صدا شکست می‌خورد.
    T::group('مسیر واقعیِ یادآور: ثبت با پیلودِ کامل');

    // ⚠ این تست `functions.php` را لود نمی‌کند (عمداً: می‌خواهد اپ را از
    //   بیرون و با HTTP ببیند)، پس `tableExists()` اینجا نیست.
    $hasReminders = false;
    try { $hasReminders = (bool)$pdo->query("SHOW TABLES LIKE 'reminders'")->fetchColumn(); }
    catch (PDOException $e) { /* ignore */ }

    if (!$hasReminders) {
        T::skip('مسیر واقعیِ یادآور', 'migration_reminders.sql هنوز اجرا نشده');
    } else {
        $rmPayload = [
            'csrf_token'      => $token,
            'title'           => 'تست دود یادآور',
            'remind_date'     => date('Y-m-d'),
            'note'            => 'یادداشتِ تست',
            'amount'          => '12000000',
            'recurrence_type' => 'every_n_months',
            'recurrence_n'    => '3',
            'total_count'     => '12',
            'amount_mode'     => 'total',
            'notify_days'     => '[7,1]',
        ];

        [$c3, $b3] = $sess('/api/save_reminder.php', $rmPayload, 'POST');
        $j3 = json_decode($b3, true);
        T::same(200, $c3, 'ثبت یادآورِ چنددوره‌ای پاسخ ۲۰۰ می‌دهد',
            $c3 >= 500 ? 'بدنه: ' . substr(trim($b3), 0, 120) . ' (بدنه‌ی خالی = خطای کشنده‌ی PHP)' : '');
        T::ok(is_array($j3), 'پاسخ ثبتِ یادآور، JSON معتبر است');
        T::ok(!empty($j3['success']), '⛔ ثبتِ یادآور واقعاً موفق بود', $j3['message'] ?? substr(trim($b3), 0, 160));

        // و ردیف واقعاً نوشته شده باشد — «۲۰۰ گرفت» با «ذخیره شد» یکی نیست.
        $rmRow = null;
        try {
            $st = $pdo->prepare(
                'SELECT r.* FROM reminders r JOIN users u ON u.id = r.user_id
                 WHERE u.username = :u AND r.title = :t ORDER BY r.id DESC LIMIT 1'
            );
            $st->execute(['u' => $smokeUser, 't' => 'تست دود یادآور']);
            $rmRow = $st->fetch();
        } catch (PDOException $e) { /* ignore */ }

        T::ok(is_array($rmRow), '⛔ ردیفِ یادآور واقعاً در جدول نشست');
        if (is_array($rmRow)) {
            T::same(12, (int)($rmRow['total_count'] ?? 0), 'تعداد اقساط ذخیره شد');
            // ۱۲٬۰۰۰٬۰۰۰ در ۱۲ قسط = ۱٬۰۰۰٬۰۰۰ برای هر قسط.
            T::same(1000000, (int)($rmRow['amount'] ?? 0),
                '⛔ «جمع کل» به مبلغِ هر قسط تقسیم شد، نه اینکه خام بماند');
            T::same(12000000, (int)($rmRow['total_amount'] ?? 0), 'جمعِ کل هم جدا نگه داشته شد');
            T::same('every_n_months', (string)($rmRow['recurrence_type'] ?? ''), 'دوره‌ی تکرار ذخیره شد');
            $nd = json_decode((string)($rmRow['notify_days_before'] ?? '[]'), true);
            T::same([7, 1], is_array($nd) ? $nd : [], '⛔ روزهای اعلان از رشته‌ی JSON خوانده شدند');
            $pdo->prepare('DELETE FROM reminders WHERE id = :i')->execute(['i' => $rmRow['id']]);
        }
    }

    // -----------------------------------------------------------
    T::group('ورود اطلاعات: شناسه‌ی کاربر دیگر پذیرفته نشود');

    // `data.php` مرحله‌ی ثبت را از روی پیلودِ JSON مرورگر انجام می‌دهد.
    // یک بار `category_id` و `wallet_id` را همان‌طور که آمده بودند
    // می‌نوشت — یعنی هر کاربری می‌توانست پیلود را دست‌کاری کند و
    // تراکنشش را به دسته یا حسابِ *کاربر دیگری* بچسباند. آزموده و
    // تأیید شده بود؛ این تست جلوی برگشتش را می‌گیرد.
    // قربانی را خودِ تست می‌سازد. تکیه بر داده‌ی موجود یعنی تست روی یک
    // دیتابیس خالی بی‌سروصدا رد می‌شود و هیچ چیزی را نگه نمی‌دارد.
    $victimUser = '__test_api_victim';
    $victimId = $foreignCat = $foreignWallet = null;
    try {
        $pdo->prepare('DELETE FROM users WHERE username = :u')->execute(['u' => $victimUser]);
        $pdo->prepare(
            "INSERT INTO users (username, password_hash, full_name, role, is_active)
             VALUES (:u, :p, 'قربانی تست', 'user', 1)"
        )->execute(['u' => $victimUser, 'p' => password_hash('x', PASSWORD_DEFAULT)]);
        $victimId = (int)$pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO categories (user_id, name, type, is_active)
             VALUES (:u, '__test_victim_cat', 'expense', 1)"
        )->execute(['u' => $victimId]);
        $foreignCat = (int)$pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO wallets (user_id, name, kind, is_active, sort_order)
             VALUES (:u, '__test_victim_wallet', 'cash', 1, 50)"
        )->execute(['u' => $victimId]);
        $foreignWallet = (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        $foreignCat = $foreignWallet = null;
    }

    // ⚠ ستونِ حسابِ هدف ممکن است هنوز با migration نیامده باشد.
    $hasGoalWallet = false;
    try {
        $hasGoalWallet = (bool)$pdo->query("SHOW COLUMNS FROM savings_goals LIKE 'wallet_id'")->fetchColumn();
    } catch (PDOException $e) { /* ignore */ }

    if (!$foreignCat && !$foreignWallet) {
        T::skip('ورود اطلاعات', 'ساخت دسته/حسابِ قربانی ممکن نشد');
    } else {
        [, $dataPage] = $sess('/data.php');
        preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $dataPage, $m3);
        $dtok = $m3[1] ?? $token;

        $marker = '__test_import_' . bin2hex(random_bytes(4));
        $row = [
            'amount' => 4321, 'type' => 'expense', 'transaction_date' => date('Y-m-d'),
            'title' => $marker,
        ];
        if ($foreignCat)    { $row['category_id'] = (int)$foreignCat; }
        if ($foreignWallet) { $row['wallet_id']   = (int)$foreignWallet; }

        $sess('/data.php', [
            'csrf_token' => $dtok, 'action' => 'commit',
            'payload' => json_encode([$row], JSON_UNESCAPED_UNICODE),
        ], 'POST');

        $chk = $pdo->prepare('SELECT category_id, wallet_id FROM transactions WHERE title = :t');
        $chk->execute(['t' => $marker]);
        $stored = $chk->fetch();

        if (!$stored) {
            T::pass('ردیفِ دست‌کاری‌شده اصلاً ثبت نشد');
        } else {
            T::ok($foreignCat === null || (int)$stored['category_id'] !== (int)$foreignCat,
                'دسته‌ی کاربر دیگر روی تراکنش ننشست');
            T::ok($foreignWallet === null || (int)$stored['wallet_id'] !== (int)$foreignWallet,
                'حساب کاربر دیگر روی تراکنش ننشست');
        }
        try { $pdo->prepare('DELETE FROM transactions WHERE title = :t')->execute(['t' => $marker]); }
        catch (PDOException $e) { /* ignore */ }

        // -------------------------------------------------------
        // ⛔ همان قاعده روی هدفِ پس‌انداز: «در کدام حساب؟» یک شناسه‌ی
        //    خام از فرم می‌گیرد، پس بدونِ بررسیِ مالکیت هر کاربری
        //    می‌توانست هدفش را به حسابِ کاربرِ دیگری بچسباند و نامِ آن
        //    حساب را روی کارتِ خودش ببیند — همان نشتی‌ای که یک بار در
        //    `data.php` رخ داد، این بار روی یک فیلدِ تازه.
        if ($foreignWallet && $hasGoalWallet) {
            $gMarker = '__test_goal_' . bin2hex(random_bytes(4));
            [, $savPage] = $sess('/savings.php');
            preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $savPage, $m4);

            $sess('/api/save_goal.php', [
                'csrf_token'    => $m4[1] ?? $token,
                'title'         => $gMarker,
                'target_amount' => '1000000',
                'color'         => '#c2410c',
                'wallet_id'     => (string)$foreignWallet,
            ], 'POST');

            $gq = $pdo->prepare('SELECT wallet_id, color FROM savings_goals WHERE title = :t');
            $gq->execute(['t' => $gMarker]);
            $goal = $gq->fetch();

            T::ok(is_array($goal), 'هدفِ پس‌انداز با پیلودِ کامل واقعاً ثبت شد');
            if (is_array($goal)) {
                T::same(null, $goal['wallet_id'],
                    '⛔ حسابِ کاربرِ دیگر روی هدفِ پس‌انداز ننشست');
                T::same('#c2410c', (string)$goal['color'],
                    'رنگِ انتخابیِ کاربر واقعاً ذخیره شد');
            }
            try { $pdo->prepare('DELETE FROM savings_goals WHERE title = :t')->execute(['t' => $gMarker]); }
            catch (PDOException $e) { /* ignore */ }
        }
    }
    try {
        if ($victimId) {
            $pdo->prepare('DELETE FROM categories WHERE user_id = :u')->execute(['u' => $victimId]);
            $pdo->prepare('DELETE FROM wallets WHERE user_id = :u')->execute(['u' => $victimId]);
        }
        $pdo->prepare('DELETE FROM users WHERE username = :u')->execute(['u' => $victimUser]);
    } catch (PDOException $e) { /* ignore */ }

    @unlink($jar);
    // همان تابعِ بالا، تا فهرستِ جدول‌ها یک جا باشد نه دو جا.
    try { $purgeUser($smokeUser); } catch (Throwable $e) { /* ignore */ }
}

// ---------------------------------------------------------------
// ⛔ مانیفستِ PWA هم باید واقعاً جواب بدهد، نه فقط «نحوش درست باشد».
//
// یک بار `assets/manifest.php` تابعی از `functions.php` را صدا زد در
// حالی که فقط `auth.php` را لود می‌کرد (و آن `functions.php` را
// نمی‌آورد). نتیجه ۵۰۰ بود و **هیچ ردی روی صفحه نداشت**: سایت درست
// بالا می‌آمد و فقط «افزودن به صفحه‌ی اصلی» بی‌سروصدا از کار می‌افتاد.
// `php -l` این را نمی‌گیرد و هیچ تستِ دیگری این فایل را صدا نمی‌زد.
T::group('مانیفستِ PWA و آیکون‌هایش');

[$mCode, $mBody] = $req('/assets/manifest.php', 'GET');
T::same(200, $mCode, 'assets/manifest.php پاسخ ۲۰۰ می‌دهد');

$manifest = json_decode($mBody, true);
T::ok(is_array($manifest) && isset($manifest['icons']), 'و JSON معتبر با فهرست آیکون برمی‌گرداند');

if (is_array($manifest) && !empty($manifest['icons'])) {
    $missing = [];
    $maskable = 0;
    foreach ($manifest['icons'] as $ic) {
        $src = (string)($ic['src'] ?? '');
        if (($ic['purpose'] ?? '') === 'maskable') { $maskable++; }
        // ⚠ `?v=` را باید برداشت: آدرسِ نسخه‌دار است، فایل نه.
        $path = parse_url($src, PHP_URL_PATH) ?? '';
        if (!is_file($root . $path)) { $missing[] = $src; }
    }
    T::bulk(count($manifest['icons']), $missing, 'فایلِ هر آیکونِ مانیفست روی دیسک هست');
    T::same(1, $maskable, 'دقیقاً یک آیکونِ maskable معرفی شده');

    // ⛔ آدرسِ آیکون باید `?v=` داشته باشد، وگرنه کشِ «یک سال،
    //    immutable» ِ nginx آیکونِ تازه را تا یک سال به کاربرِ فعلی
    //    نمی‌رساند — بی‌هیچ خطایی و بی‌آنکه تازه‌سازی کمکی کند.
    $unversioned = [];
    foreach ($manifest['icons'] as $ic) {
        if (!str_contains((string)($ic['src'] ?? ''), '?v=')) { $unversioned[] = $ic['src'] ?? '?'; }
    }
    T::bulk(count($manifest['icons']), $unversioned, 'آدرسِ هر آیکون نسخه‌دار است');

    // ⛔ و مهم‌تر از **نامِ** فایلِ maskable، این است که واقعاً maskable
    //    باشد: اندروید آن را داخل شکلِ خودش می‌برد و فقط دایره‌ی مرکزی
    //    با شعاعِ ۴۰٪ تضمین‌شده است. آیکونِ عادی این شرط را ندارد
    //    (دورترین گوشه‌ی نشان روی ۴۳٫۳٪) و اگر روزی کسی این ورودی را
    //    دوباره به آن برگرداند، لبه‌ی نشان بریده می‌شود — بی‌آنکه هیچ
    //    فایلی گم شده باشد یا تستی قرمز شود. پس خودِ **تصویر** سنجیده
    //    می‌شود، نه نامش.
    //
    // ⛔ و «نشان» با **فاصله از رنگِ خودِ صفحه** پیدا می‌شود، نه با یک
    //    رنگِ سخت‌کد. نسخه‌ی اول دنبالِ پیکسلِ **طلایی** می‌گشت و با
    //    عوض شدنِ برند (نشانِ سفید روی سبز) هیچ پیکسلی پیدا نمی‌کرد —
    //    یعنی همان بررسی روی آیکونِ تازه **خودش** قرمز می‌شد. رنگِ صفحه
    //    از چهار گوشه‌ی خودِ تصویر خوانده می‌شود (آیکون تمام‌بلید است،
    //    پس گوشه همیشه صفحه است) و برندِ فردا هم خودبه‌خود پوشش دارد.
    $maskSrc = '';
    foreach ($manifest['icons'] as $ic) {
        if (($ic['purpose'] ?? '') === 'maskable') { $maskSrc = (string)($ic['src'] ?? ''); }
    }
    $maskFile = $root . (parse_url($maskSrc, PHP_URL_PATH) ?? '');
    if (function_exists('imagecreatefrompng') && is_file($maskFile)) {
        $img = @imagecreatefrompng($maskFile);
        if ($img) {
            $w = imagesx($img); $h = imagesy($img);
            // رنگِ مرجعِ صفحه: میانگینِ چهار بلوکِ ۱۰×۱۰ گوشه.
            $sr = 0; $sg = 0; $sb = 0; $sn = 0;
            foreach ([[0, 0], [$w - 10, 0], [0, $h - 10], [$w - 10, $h - 10]] as $o) {
                for ($y = $o[1]; $y < $o[1] + 10; $y++) {
                    for ($x = $o[0]; $x < $o[0] + 10; $x++) {
                        $c = imagecolorat($img, $x, $y);
                        $sr += ($c >> 16) & 255; $sg += ($c >> 8) & 255; $sb += $c & 255;
                        $sn++;
                    }
                }
            }
            $rr = $sr / $sn; $rg = $sg / $sn; $rb = $sb / $sn;
            $x0 = $w; $y0 = $h; $x1 = -1; $y1 = -1;
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $c  = imagecolorat($img, $x, $y);
                    $dr = (($c >> 16) & 255) - $rr;
                    $dg = (($c >> 8) & 255) - $rg;
                    $db = ($c & 255) - $rb;
                    // ⚠ آستانه ۱۰۰ است و اندازه‌گیری شده: گرادیانِ خودِ
                    //    صفحه حداکثر ~۴۰ نوسان دارد و کم‌رنگ‌ترین تکه‌ی
                    //    نشان (کارت‌های سبزِ روشن) ۱۸۰ فاصله می‌گیرد.
                    if ($dr * $dr + $dg * $dg + $db * $db > 100 * 100) {
                        if ($x < $x0) { $x0 = $x; }
                        if ($x > $x1) { $x1 = $x; }
                        if ($y < $y0) { $y0 = $y; }
                        if ($y > $y1) { $y1 = $y; }
                    }
                }
            }
            imagedestroy($img);
            $far = 0.0;
            foreach ([[$x0, $y0], [$x1, $y0], [$x0, $y1], [$x1, $y1]] as $c) {
                $dx  = $c[0] / $w - 0.5;
                $dy  = $c[1] / $h - 0.5;
                $far = max($far, sqrt($dx * $dx + $dy * $dy));
            }
            T::ok($x1 > 0, 'نشان داخلِ آیکونِ maskable پیدا شد');
            T::ok($far <= 0.40,
                '⛔ نشانِ maskable داخلِ ناحیه‌ی امنِ ۸۰٪ می‌ماند',
                sprintf('دورترین گوشه: %.1f%% (سقف: ۴۰٪)', $far * 100));
        }
    }
}

// و همان قاعده برای صفحه‌هایی که سرآیندِ خودشان را دارند.
$pageIcons = [];
foreach (['/login.php', '/forgot-password.php'] as $p) {
    [, $html] = $req($p, 'GET');
    if (preg_match_all('~assets/icons/[A-Za-z0-9._?=-]+~', $html, $m)) {
        foreach ($m[0] as $u) { $pageIcons[$u] = true; }
    }
}
$badIcons = [];
foreach (array_keys($pageIcons) as $u) {
    if (!str_contains($u, '?v=')) { $badIcons[] = $u . ' (بدون نسخه)'; }
    $f = $root . '/' . explode('?', $u)[0];
    if (!is_file($f)) { $badIcons[] = $u . ' (فایل نیست)'; }
}
T::ok(count($pageIcons) >= 2, 'آیکون‌های صفحه‌ی ورود پیدا شدند',
    'پیدا شد: ' . count($pageIcons));
T::bulk(count($pageIcons), $badIcons, 'آیکون‌های داخلِ صفحه هم نسخه‌دار و موجودند');

// ---------------------------------------------------------------
if ($pid) { @exec("kill $pid 2>/dev/null"); }
@unlink($log);

exit(T::report());
