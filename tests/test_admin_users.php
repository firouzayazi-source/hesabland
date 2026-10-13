<?php
/**
 * ⛔ مدیریت کاربران در مقیاس — `admin/users.php`
 *
 * **گزارشِ مالکِ نصب:** «مدیریت کاربران خیلی بزرگ هست، کارتهای تعداد
 * کاربران بشه ۱۰۰۰ تا من چطوری مدیریتش کنم؟ بهینه کن بدون کم کردن
 * قابلیتها.»
 *
 * صفحه **همه‌ی** ردیف‌های `users` را در یک جدول می‌ریخت. با سه کاربر
 * بی‌عیب بود؛ اندازه‌گیری شد که با هزار کاربر چه می‌شود:
 *
 *   | | پیش | پس |
 *   |---|---|---|
 *   | HTML | ۴٬۳۴۱٬۳۷۴ بایت | ۱۵۶٬۵۰۹ بایت |
 *   | رندرِ سمتِ سرور | ۱۹٫۲ ms | ۷٫۵ ms |
 *   | کوئری | ۲۱ | ۲۲ |
 *
 * یعنی چهار مگابایت HTML روی اینترنتِ موبایل — و آن پایین‌ترین هزینه
 * است؛ بالاترش این است که مدیر باید بینِ هزار ردیف دنبالِ یک نفر
 * بگردد.
 *
 * ⚠ **هیچ قابلیتی کم نشد و تست همین را هم می‌سنجد:** هر شش عملیاتِ
 *   ردیف (ویرایش، باز کردن قفل، فعال/غیرفعال، خروج از دستگاه‌ها، حذف)
 *   و «همه در یک فهرست» سرِ جایشان‌اند.
 *
 * ⛔ و مهم‌ترین بررسیِ این فایل «صفحه بیش از `USERS_PAGE_SIZE` ردیف
 *    رندر نمی‌کند» است: با برداشتنِ `LIMIT`، صفحه **درست کار می‌کند**
 *    و فقط دوباره چهار مگابایت می‌شود — خرابیِ کاملاً بی‌صدا.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/login_throttle.php';
require_once __DIR__ . '/../includes/user_data.php';

$root = dirname(__DIR__);

/**
 * چند کاربرِ آزمایشی ساخته می‌شود.
 *
 * ⚠ عمداً از **شش صفحه** بیشتر است، نه فقط از دو تا: نوارِ شماره‌ی
 *   صفحه تا وقتی که پرش واقعاً لازم نباشد رندر **نمی‌شود** (پنجره‌ی
 *   `PAGED_WINDOW`)، پس با ۶۱ کاربر آن بررسی روی کدِ سالم قرمز می‌شد —
 *   «هشدارِ الکی از نبودِ تست بدتر است».
 */
const SEED_USERS = 160;
const PREFIX     = '__auz_';

/**
 * ⛔ عددِ **ثابت**، نه `USERS_PAGE_SIZE` از خودِ صفحه.
 *
 * وگرنه تست مرزش را از کدِ زیرِ آزمون می‌گرفت و با عوض شدنِ ثابت،
 * مرزِ خودش هم جابه‌جا می‌شد — یعنی «صفحه بیش از یک صفحه رندر نمی‌کند»
 * روی هر عددی سبز می‌ماند. همان دامی که یک بار سرِ `BACKUP_AFTER_DAYS`
 * افتادیم. خودِ مقدارِ ثابت هم جداگانه سنجیده می‌شود (پایین‌تر).
 */
const USERS_PAGE_SIZE_EXPECTED = 25;

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::blocked('تست مدیریت کاربران', 'اتصال به دیتابیس برقرار نشد: ' . $e->getMessage());
    exit(T::report());
}

$ADMIN = [PREFIX . 'root', 'AuzRoot12345'];

$wipe = function () use ($pdo) {
    $ids = $pdo->query("SELECT id FROM users WHERE username LIKE '" . PREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        foreach (userDataTables() as $t) {
            try { $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]); }
            catch (PDOException $e) { /* جدولی که نیست */ }
        }
    }
    $pdo->exec("DELETE FROM users WHERE username LIKE '" . PREFIX . "%'");
    $pdo->exec("DELETE FROM login_attempts WHERE username_tried LIKE '" . PREFIX . "%'");
};

$cleanup = function () use ($wipe) { $wipe(); };
$wipe();

// ---------------------------------------------------------------
T::group('آماده‌سازی');

$hash = password_hash($ADMIN[1], PASSWORD_DEFAULT);
$pdo->prepare("INSERT INTO users (full_name, username, password_hash, role, is_active, email)
               VALUES ('مدیرِ آزمون', :u, :h, 'admin', 1, :e)")
    ->execute(['u' => $ADMIN[0], 'h' => $hash, 'e' => PREFIX . 'root@example.com']);
$adminId = (int)$pdo->lastInsertId();

/**
 * ⚠ داده‌ی fixture عمداً **ناهمگون** است: غیرفعال، مدیر، و یک نامِ
 *   یکتای قابل جست‌وجو. با ردیف‌های یک‌شکل، هر صافی روی همه می‌خورد و
 *   بررسی‌ها به هر خرابی یک جواب می‌دادند.
 */
$ins = $pdo->prepare("INSERT INTO users (full_name, username, password_hash, role, is_active, email, phone, created_at)
                      VALUES (:fn, :un, :h, :r, :a, :e, :p, DATE_SUB(NOW(), INTERVAL :d DAY))");
$hasPhone = tableHasColumn('users', 'phone');
$madeIds  = [];
for ($i = 1; $i <= SEED_USERS; $i++) {
    $ins->execute([
        'fn' => $i === 7 ? 'نیلوفرِ یکتا' : ('کاربر آزمایشی ' . $i),
        'un' => PREFIX . $i,
        'h'  => $hash,
        'r'  => $i === 3 ? 'admin' : 'user',
        'a'  => $i === 5 ? 0 : 1,
        'e'  => PREFIX . $i . '@example.com',
        'p'  => $hasPhone ? ('0912000' . str_pad((string)$i, 4, '0', STR_PAD_LEFT)) : null,
        'd'  => SEED_USERS - $i,      // ۱ قدیمی‌ترین، آخری تازه‌ترین
    ]);
    $madeIds[$i] = (int)$pdo->lastInsertId();
}
T::same(SEED_USERS, count($madeIds), 'کاربرانِ آزمایشی ساخته شدند');

// یک کاربرِ «قفل‌شده»: به اندازه‌ی سقف تلاشِ ناموفق ثبت می‌شود.
$lockedName = PREFIX . '9';
for ($i = 0; $i < LoginThrottle::MAX_PER_USER; $i++) {
    LoginThrottle::recordFailure($lockedName, '203.0.113.9');
}
$counts = LoginThrottle::failureCounts();
T::ok(($counts[LoginThrottle::key($lockedName)] ?? 0) >= LoginThrottle::MAX_PER_USER,
    'یک کاربر واقعاً قفل شد', 'بدونِ آن، صافیِ «قفلِ ورود» روی فهرستِ خالی سنجیده می‌شد');

// ---------- سرور ----------
$port = 0;
for ($p = 8901; $p <= 8939; $p++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
    if ($sock) { fclose($sock); $port = $p; break; }
}
if (!$port) {
    T::blocked('تست مدیریت کاربران', 'پورت آزاد پیدا نشد');
    $cleanup();
    exit(T::report());
}

$log = tempnam(sys_get_temp_dir(), 'auz');
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
    $cleanup();
    exit(T::report());
}

$jar = tempnam(sys_get_temp_dir(), 'auzjar');
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
    unset($ch);
    return [$code, substr($raw, $hlen), substr($raw, 0, $hlen)];
};

[, $html] = $req('login.php');
preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m);
$token = $m[1] ?? '';
[$c] = $req('login.php', ['csrf_token' => $token, 'username' => $ADMIN[0], 'password' => $ADMIN[1]]);
T::ok($c === 302 || $c === 303, 'ورودِ مدیر انجام شد');

/** شماره‌ی ردیف‌های جدولِ کاربران در HTML. */
$rowCount = function (string $body): int {
    // هر ردیفِ داده یک دکمه‌ی ویرایش دارد؛ سرآیندِ جدول ندارد.
    return substr_count($body, 'class="btn btn-secondary btn-sm js-edit-user"');
};
/** نامِ کاربری‌های دیده‌شده در همان صفحه. */
$namesIn = function (string $body): array {
    preg_match_all('/data-username="(' . PREFIX . '[^"]*)"/', $body, $mm);
    return $mm[1] ?? [];
};

// ---------------------------------------------------------------
T::group('⛔ صفحه دیگر همه‌ی کاربران را یک‌جا رندر نمی‌کند');

[$code, $body] = $req('admin/users.php');
T::same(200, $code, 'صفحه‌ی کاربران باز می‌شود');
$n = $rowCount($body);
T::ok($n === USERS_PAGE_SIZE_EXPECTED,
    '⛔ فقط یک صفحه ردیف رندر می‌شود',
    "رندرشده: {$n}، انتظار: " . USERS_PAGE_SIZE_EXPECTED . " (از " . SEED_USERS . " کاربرِ آزمایشی)");

T::ok(str_contains($body, 'class="pager"'), 'نوارِ صفحه‌بندی رندر می‌شود');
T::ok(str_contains($body, 'همه در یک فهرست'),
    '«همه در یک فهرست» سرِ جایش است',
    'خواسته‌ی صریحِ مالکِ نصب بود و نباید با صفحه‌بندی از بین برود');

// ⛔ و «همه» واقعاً همه را می‌دهد — وگرنه لینکی داریم که کارِ اسمش را نمی‌کند.
[, $all] = $req('admin/users.php?all=users');
T::ok($rowCount($all) >= SEED_USERS,
    '«همه» واقعاً همه‌ی ردیف‌ها را می‌آورد', 'رندرشده: ' . $rowCount($all));

// ---------------------------------------------------------------
T::group('⛔ صفحه‌بندی');

[, $p2] = $req('admin/users.php?pg_users=2');
$n2 = $rowCount($p2);
T::ok($n2 > 0 && $n2 <= USERS_PAGE_SIZE_EXPECTED, 'صفحه‌ی دوم هم ردیف دارد', "رندرشده: {$n2}");
T::ok(count(array_intersect($namesIn($body), $namesIn($p2))) === 0,
    '⛔ صفحه‌ی اول و دوم هیچ ردیفِ مشترکی ندارند',
    'با `OFFSET` غلط، ردیف‌ها تکرار می‌شوند و کاربر فکر می‌کند دو حساب دارد');

// ⛔ شماره‌ی بیرون از بازه بریده می‌شود، نه اینکه فهرستِ خالی بدهد:
//    صفحه‌ی خالیِ بی‌توضیح شبیهِ خرابی دیده می‌شود.
[, $far] = $req('admin/users.php?pg_users=9999');
T::ok($rowCount($far) > 0, '⛔ شماره‌ی بیرون از بازه به آخرین صفحه بریده می‌شود');

T::ok(str_contains($body, 'class="pager-num'),
    'شماره‌ی صفحه‌ها رندر می‌شود',
    'با سه صفحه و بیشتر، «قبلی/بعدی» تنها یعنی ورق زدنِ یکی‌یکی');

// ---------------------------------------------------------------
T::group('⛔ جست‌وجو');

$search = function (string $q) use ($req, $namesIn, $rowCount): array {
    [, $b] = $req('admin/users.php?q=' . urlencode($q));
    return [$rowCount($b), $namesIn($b), $b];
};

[$cName, $namesName] = $search('نیلوفرِ یکتا');
T::ok($cName === 1 && $namesName === [PREFIX . '7'], 'جست‌وجو روی نام کار می‌کند', "یافت: {$cName}");

[$cUser, $namesUser] = $search(PREFIX . '42');
T::ok(in_array(PREFIX . '42', $namesUser, true), 'جست‌وجو روی نام کاربری کار می‌کند');

[$cMail, $namesMail] = $search(PREFIX . '13@example.com');
T::ok(in_array(PREFIX . '13', $namesMail, true), 'جست‌وجو روی ایمیل کار می‌کند');

if ($hasPhone) {
    [, $namesPhone] = $search('09120000021');
    T::ok(in_array(PREFIX . '21', $namesPhone, true), 'جست‌وجو روی شماره موبایل کار می‌کند');
}

[, $namesId] = $search((string)$madeIds[11]);
T::ok(in_array(PREFIX . '11', $namesId, true),
    'جست‌وجو روی شناسه کار می‌کند',
    'مدیر شناسه را از گزارشِ خطا یا از تیکت کپی می‌کند');

/**
 * ⛔ `%` و `_` فرار داده می‌شوند.
 *
 * بدونِ `ESCAPE '!'`، تایپِ `%` کلِ فهرست را برمی‌گرداند و مدیر
 * می‌بیند «جست‌وجو کار نمی‌کند» — نه یک رخنه (مقدار bind است) ولی
 * نتیجه‌ای که هیچ‌کس نمی‌فهمد از کجا آمده.
 */
[$cPct] = $search('%');
T::same(0, $cPct, '⛔ `%` وایلدکارت نیست و هیچ ردیفی برنمی‌گرداند');

/**
 * ⚠ برای `_` نمی‌شود از الگوی بالا استفاده کرد: **همه‌ی** نام‌های
 *   آزمایشی خودشان `_` دارند، پس چه فرار داده شود چه نشود ردیف
 *   برمی‌گردد و بررسی به هر دو حالت یک جواب می‌دهد — یعنی پوچ است.
 *
 *   الگوی زیر تفکیک می‌کند: `__auz_1_` هیچ نامِ کاربری‌ای نیست
 *   (نام‌ها `__auz_1`، `__auz_10`، … هستند)، ولی اگر `_` وایلدکارت
 *   بماند دقیقاً `__auz_10` تا `__auz_19` را می‌آورد.
 */
[$cUnd, $namesUnd] = $search(PREFIX . '1_');
T::same(0, $cUnd, '⛔ `_` هم وایلدکارتِ تک‌کاراکتری نیست',
    'یافت: ' . implode(', ', $namesUnd));

// ---------------------------------------------------------------
T::group('⛔ صافی‌ها');

[, $onlyAdmin] = $req('admin/users.php?role=admin');
$adminNames = $namesIn($onlyAdmin);
T::ok(in_array(PREFIX . '3', $adminNames, true) && !in_array(PREFIX . '4', $adminNames, true),
    'صافیِ نقش فقط مدیرها را می‌آورد');

[, $inactive] = $req('admin/users.php?state=inactive');
$inNames = $namesIn($inactive);
T::ok($inNames === [PREFIX . '5'], 'صافیِ «غیرفعال» فقط غیرفعال‌ها را می‌آورد',
    'یافت: ' . implode(', ', $inNames));

/**
 * ⛔ «قفلِ ورود» در جدولِ `users` نیست، در `login_attempts` است.
 *
 * تا امروز مدیر هیچ راهی نداشت کاربرِ قفل‌شده را **پیدا** کند؛ فقط
 * وقتی می‌دید که تصادفاً به ردیفش می‌رسید. با هزار کاربر یعنی هرگز.
 */
[, $locked] = $req('admin/users.php?state=locked');
$lkNames = $namesIn($locked);
T::ok($lkNames === [PREFIX . '9'], '⛔ صافیِ «قفلِ ورود» دقیقاً همان یک نفر را می‌آورد',
    'یافت: ' . implode(', ', $lkNames));
T::ok(str_contains($locked, 'باز کردن قفل'), 'دکمه‌ی «باز کردن قفل» کنارِ همان ردیف هست');

// ---------------------------------------------------------------
/**
 * ⛔ کارت‌های تاشو — خواسته‌ی صریحِ مالکِ نصب: «هر شخص رو کارتشو تاشو
 * کن و فقط اسم پیدا باشه… پیش‌فرض همه تاشو… اگه کارتی نیاز به رفع
 * مشکلی داره روش نوتیف بخوره که متمایز بشه.»
 *
 * هر سه خرابیِ این کار بی‌صداست: صفحه در هر حالت باز می‌شود و فقط
 * خاصیتش را از دست می‌دهد. قاعده ۵۲ *شکل* را می‌سنجد، اینجا *رفتار*.
 */
T::group('⛔ کارت‌های تاشوی کاربران');

/** بخشِ `<summary>`ِ هر کارتِ یک صفحه — یعنی همان چیزی که بسته دیده می‌شود. */
$summaries = function (string $b): array {
    preg_match_all('~<summary\b[^>]*>(.*?)</summary>~s', $b, $mm);
    return $mm[1] ?? [];
};

$cards = substr_count($body, '<details class="ucard');
T::same(USERS_PAGE_SIZE_EXPECTED, $cards, 'هر کاربر یک کارتِ <details> دارد');
T::ok(!preg_match('~<details[^>]*\sopen~', $body),
    '⛔ هیچ کارتی با `open` رندر نمی‌شود',
    '«پیش‌فرض همه تاشو» — با `open` همان فهرستِ بلندِ قبلی برمی‌گردد');

$sums = $summaries($body);
T::same($cards, count($sums), 'هر کارت یک <summary> دارد');
$leak = 0;
foreach ($sums as $s) { if (str_contains($s, '@example.com')) { $leak++; } }
T::same(0, $leak, '⛔ «فقط اسم پیدا باشه» — ایمیل داخلِ بخشِ بسته نیست');

// ⛔ نیمه‌ی دومِ خواسته: کارتی که کار می‌خواهد باید بسته هم دیده شود،
//    وگرنه تاشو کردن دقیقاً همان کاربری را پنهان می‌کند که باید پیدا شود.
$lkSums = $summaries($locked);
T::ok(count($lkSums) === 1 && str_contains($lkSums[0], 'class="lock-chip'),
    '⛔ کارتِ قفل‌شده روی بخشِ بسته نشان دارد');
T::ok(str_contains($locked, 'is-flagged'), 'کارتِ نشان‌دار کلاسِ `is-flagged` می‌گیرد');

$inSums = $summaries($inactive);
T::ok(count($inSums) === 1 && str_contains($inSums[0], 'غیرفعال'),
    '⛔ حسابِ غیرفعال هم روی کارتِ بسته نشان می‌گیرد');

// و کارتِ سالم هیچ نشانی نمی‌گیرد — نشانی که همه‌جا باشد همان «هشدارِ
// همیشگی» است که آدم را عادت می‌دهد نگاهش نکند.
[, $healthy] = $req('admin/users.php?q=' . urlencode(PREFIX . '17'));
$hSums = $summaries($healthy);
T::ok(count($hSums) === 1 && !str_contains($hSums[0], 'lock-chip'),
    '⛔ کارتِ سالم هیچ نشانی نمی‌گیرد');

// ---------------------------------------------------------------
T::group('⛔ ترتیب');

[, $newest] = $req('admin/users.php?sort=new');
[, $oldest] = $req('admin/users.php?sort=old');
$firstNew = $namesIn($newest)[0] ?? '';
$firstOld = $namesIn($oldest)[0] ?? '';
T::same(PREFIX . SEED_USERS, $firstNew, '⛔ پیش‌فرض «تازه‌ترین» است');
T::same(PREFIX . '1', $firstOld, '«قدیمی‌ترین» هم سرِ جایش است');
T::ok($firstNew !== $firstOld, 'ترتیب واقعاً اثر دارد');

// ---------------------------------------------------------------
T::group('⛔ هیچ عملیاتی کم نشد');

foreach ([
    'ویرایش'              => 'js-edit-user',
    'فعال/غیرفعال'        => 'value="toggle_status"',
    'خروج از دستگاه‌ها'   => 'value="revoke_access"',
    'حذف'                 => 'value="delete"',
    'کاربر جدید'          => 'data-modal-open="addUserModal"',
] as $what => $needle) {
    T::ok(str_contains($body, $needle), "«{$what}» سرِ جایش است");
}

// ---------------------------------------------------------------
T::group('⛔ بعد از عملیات، به همان نما برمی‌گردیم');

/**
 * با هزار کاربر، «غیرفعال‌سازی» روی صفحه‌ی ۱۲ کاربر را به صفحه‌ی اول
 * برمی‌گرداند و او باید دوباره دوازده بار ورق بزند — همان خرابیِ
 * بی‌صدایی که `pagedUrl()` برای نبودنش نوشته شد، این بار در ریدایرکت.
 */
[, $view] = $req('admin/users.php?q=' . urlencode(PREFIX . '17') . '&sort=name');
preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $view, $mv);
[$pc, , $hdr] = $req('admin/users.php?q=' . urlencode(PREFIX . '17') . '&sort=name', [
    'csrf_token' => $mv[1] ?? '',
    'action'     => 'toggle_status',
    'user_id'    => $madeIds[17],
]);
T::ok($pc === 302, 'عملیاتِ ردیف انجام شد', "کد: {$pc}");
preg_match('/^Location:\s*(.+)$/mi', $hdr, $ml);
$loc = trim($ml[1] ?? '');
T::ok(str_contains($loc, 'q=') && str_contains($loc, 'sort=name'),
    '⛔ ریدایرکت صافی و ترتیب را نگه می‌دارد', "Location: {$loc}");

// ⛔ و فقط کلیدهای شناخته‌شده حمل می‌شوند — `$_GET` دلخواه نباید وارد
//    سرآیندِ `Location` شود.
[, $view2] = $req('admin/users.php?q=' . urlencode(PREFIX . '17') . '&evil=zzz');
preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $view2, $mv2);
[, , $hdr2] = $req('admin/users.php?q=' . urlencode(PREFIX . '17') . '&evil=zzz', [
    'csrf_token' => $mv2[1] ?? '',
    'action'     => 'toggle_status',
    'user_id'    => $madeIds[17],
]);
preg_match('/^Location:\s*(.+)$/mi', $hdr2, $ml2);
T::ok(!str_contains((string)($ml2[1] ?? ''), 'evil'),
    '⛔ پارامترِ ناشناخته وارد ریدایرکت نمی‌شود');

// ---------------------------------------------------------------
T::group('کارتِ خلاصه');

T::ok(str_contains($body, 'class="user-stats"'), 'کارتِ خلاصه رندر می‌شود');
T::ok(str_contains($body, 'قفلِ ورود'), 'شمارِ «قفلِ ورود» روی خلاصه هست',
    'با هزار کاربر، این سؤال پیش از باز کردنِ فهرست پرسیده می‌شود');

// ⚠ و خودِ مقدارِ ثابت — چون بررسیِ بالا عمداً عددِ ثابت دارد، اگر
//   `USERS_PAGE_SIZE` عوض شود باید **همین‌جا** قرمز شود، نه اینکه
//   بی‌صدا با هم بخوانند.
$src = (string)file_get_contents($root . '/admin/users.php');
T::ok((bool)preg_match('/const\s+USERS_PAGE_SIZE\s*=\s*' . USERS_PAGE_SIZE_EXPECTED . '\s*;/', $src),
    '⛔ `USERS_PAGE_SIZE` همان عددی است که تست با آن می‌سنجد');

exec("kill $pid 2>/dev/null");
@unlink($jar);
@unlink($log);
$cleanup();
exit(T::report());
