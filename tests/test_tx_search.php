<?php
/**
 * جست‌وجوی تراکنش‌ها و خروجیِ CSV.
 *
 * پنج چیز که اگر بشکنند **هیچ خطایی نمی‌دهند**:
 *  ۱. جست‌وجو فقط عنوان را بگردد (یادداشت، دسته و مبلغ از قلم بیفتند) —
 *     کاربر نتیجه نمی‌گیرد و نتیجه می‌گیرد «چیزی ثبت نکرده‌ام».
 *  ۲. `%` یا `_` فرار داده نشود و جست‌وجو ردیف‌های بی‌ربط بیاورد.
 *  ۳. جست‌وجو از مرزِ کاربر رد شود — بدترین حالت.
 *  ۴. خروجیِ CSV با صافیِ صفحه نخواند (فایل را کسی با صفحه مقایسه
 *     نمی‌کند).
 *  ۵. مبلغ در CSV قالب‌بندی‌شده برود — اکسل آن را **متن** می‌گیرد و
 *     جمع‌زدنی نیست، یعنی تنها دلیلِ وجودِ فایل از بین می‌رود.
 *
 * برای اجرا به دیتابیس نیاز دارد؛ اگر نبود، رد می‌شود نه شکست.
 */

// ---------- نگهبان: فقط خط فرمان ----------
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

if (!file_exists(__DIR__ . '/../config/config.php')) {
    T::group('جست‌وجو و خروجی تراکنش');
    T::blocked('جست‌وجوی تراکنش‌ها', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tx_query.php';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::group('جست‌وجو و خروجی تراکنش');
    T::blocked('جست‌وجوی تراکنش‌ها', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

// ---------------------------------------------------------------
$U1 = '__test_txsearch_a';
$U2 = '__test_txsearch_b';

$dropUser = function (string $name) use ($pdo) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $name]);
    $id = $st->fetchColumn();
    if ($id) {
        $pdo->prepare('DELETE FROM transactions WHERE user_id = :u')->execute(['u' => $id]);
        $pdo->prepare('DELETE FROM categories   WHERE user_id = :u')->execute(['u' => $id]);
        if (tableExists('wallets')) {
            $pdo->prepare('DELETE FROM wallets WHERE user_id = :u')->execute(['u' => $id]);
        }
    }
    $pdo->prepare('DELETE FROM users WHERE username = :u')->execute(['u' => $name]);
};
$mkUser = function (string $name) use ($pdo, $dropUser): int {
    $dropUser($name);
    $pdo->prepare(
        "INSERT INTO users (username, password_hash, full_name, role)
         VALUES (:u, :p, 'کاربر تست جست‌وجو', 'user')"
    )->execute(['u' => $name, 'p' => password_hash('x', PASSWORD_DEFAULT)]);
    return (int)$pdo->lastInsertId();
};

$uidA = $mkUser($U1);
$uidB = $mkUser($U2);

// دسته‌ی شخصیِ کاربر A — تا «جست‌وجو روی نامِ دسته» واقعاً سنجیده شود
$pdo->prepare(
    "INSERT INTO categories (user_id, name, type) VALUES (:u, 'قبض برق', 'expense')"
)->execute(['u' => $uidA]);
$catId = (int)$pdo->lastInsertId();

$addTx = function (int $uid, string $title, ?string $note, int $amount,
                   string $type = 'expense', ?int $cat = null, string $date = '1404-01-01')
         use ($pdo): int {
    $pdo->prepare(
        'INSERT INTO transactions (user_id, category_id, type, amount, title, note, transaction_date)
         VALUES (:u, :c, :ty, :a, :ti, :n, :d)'
    )->execute([
        'u' => $uid, 'c' => $cat, 'ty' => $type, 'a' => $amount,
        'ti' => $title, 'n' => $note, 'd' => $date,
    ]);
    return (int)$pdo->lastInsertId();
};

// ⚠ تاریخ‌ها میلادی‌اند (قاعده‌ی همیشگی) و عمداً گذشته، تا صافیِ
//   «امروز/این ماه» ناخواسته چیزی را نگیرد.
$txTitle  = $addTx($uidA, 'خرید نان سنگک', null,            45000,  'expense', null,   '2025-03-21');
$txNote   = $addTx($uidA, 'متفرقه',        'بابت تعمیر پنکه', 120000, 'expense', null,   '2025-03-22');
$txCat    = $addTx($uidA, 'پرداختی',       null,            310000, 'expense', $catId, '2025-03-23');
$txAmount = $addTx($uidA, 'بی‌ربط',         null,            777000, 'income',  null,   '2025-03-24');
$txPct    = $addTx($uidA, '۵۰٪ تخفیف',     null,            10000,  'expense', null,   '2025-03-25');
$txUnders = $addTx($uidA, 'a_b قرارداد',    null,            20000,  'expense', null,   '2025-03-26');
$txAxb    = $addTx($uidA, 'axb قرارداد',    null,            30000,  'expense', null,   '2025-03-27');

// همان عنوان، ولی مالِ کاربرِ دیگر
$addTx($uidB, 'خرید نان سنگک', null, 45000, 'expense', null, '2025-03-21');

/** جست‌وجو را اجرا می‌کند و شناسه‌های پیدا‌شده را برمی‌گرداند. */
$find = function (int $uid, string $term, array $extra = []) use ($pdo): array {
    $f = buildTransactionFilter($uid, ['search' => $term] + $extra, []);
    $st = $pdo->prepare(
        "SELECT t.id FROM transactions t
         LEFT JOIN categories c ON c.id = t.category_id
         {$f['where']} ORDER BY t.id"
    );
    $st->execute($f['params']);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
};

// ---------------------------------------------------------------
T::group('جست‌وجو: چهار جا، نه فقط عنوان');

T::ok(in_array($txTitle, $find($uidA, 'سنگک'), true), 'عنوان پیدا می‌شود');
T::ok(in_array($txNote, $find($uidA, 'پنکه'), true),
      '⛔ یادداشت هم گشته می‌شود', 'پیش از این فقط `t.title` بود');
T::ok(in_array($txCat, $find($uidA, 'قبض برق'), true),
      '⛔ نامِ دسته‌بندی هم گشته می‌شود');

T::group('جست‌وجو: مبلغ');

T::ok(in_array($txAmount, $find($uidA, '777000'), true), 'مبلغِ دقیق پیدا می‌شود');
T::ok(in_array($txAmount, $find($uidA, '۷۷۷۰۰۰'), true),
      '⛔ ارقامِ فارسی هم همان عدد است', 'کیبوردِ فارسی «۷۷۷۰۰۰» می‌دهد');
T::ok(in_array($txAmount, $find($uidA, '۷۷۷٬۰۰۰'), true),
      'جداکننده‌ی سه‌رقمی نادیده گرفته می‌شود');
T::same(777000, txSearchAmount('۷۷۷٬۰۰۰'), 'نرمال‌سازیِ مبلغ');
T::same(null, txSearchAmount('قبض ۵۰۰'), 'متنِ دارای عدد، عدد نیست');
T::same(null, txSearchAmount('1404/05'), 'تاریخ عدد نیست');

// ⛔ تطابقِ مبلغ **دقیق** است نه `LIKE`: با `LIKE`، «۷۷» هم ۷۷۷٬۰۰۰ را
//    می‌آورد هم هر مبلغِ دیگری که ۷۷ در آن باشد — نتیجه‌ای که کاربر
//    نمی‌تواند پیش‌بینی کند.
T::ok(!in_array($txAmount, $find($uidA, '77'), true),
      '⛔ «۷۷» مبلغِ ۷۷۷٬۰۰۰ را نمی‌آورد (تطابق دقیق است، نه LIKE)');

T::group('⛔ وایلدکاردِ LIKE فرار داده می‌شود');

$pctHits = $find($uidA, '%');
T::same([], $pctHits, '⛔ تایپِ «%» کلِ جدول را برنمی‌گرداند');

$underHits = $find($uidA, 'a_b');
T::ok(in_array($txUnders, $underHits, true), '«a_b» خودش پیدا می‌شود');
T::ok(!in_array($txAxb, $underHits, true),
      '⛔ «a_b» عبارتِ «axb» را نمی‌آورد (`_` وایلدکارد نیست)');

T::group('⛔ جست‌وجو از مرزِ کاربر رد نمی‌شود');

$aHits = $find($uidA, 'سنگک');
$bHits = $find($uidB, 'سنگک');
T::same(1, count($aHits), 'کاربر A فقط ردیفِ خودش را می‌بیند');
T::same(1, count($bHits), 'کاربر B فقط ردیفِ خودش را می‌بیند');
T::ok($aHits !== $bHits, '⛔ و آن دو یکی نیستند');

T::group('صافی‌ها کنارِ جست‌وجو کار می‌کنند');

T::ok(in_array($txAmount, $find($uidA, '777000', ['type' => 'income']), true),
      'صافیِ نوع، درآمد را نگه می‌دارد');
T::same([], $find($uidA, '777000', ['type' => 'expense']),
      'صافیِ نوع، همان ردیف را با نوعِ دیگر رد می‌کند');

// ⚠ JOIN فقط با جست‌وجو می‌آید — بازدیدِ عادی نباید هزینه‌ی قابلیتی را
//   بدهد که از آن استفاده نمی‌کند (سقفِ ردیفِ خوانده‌شده در
//   `test_query_budget`).
$plain  = buildTransactionFilter($uidA, [], []);
$search = buildTransactionFilter($uidA, ['search' => 'x'], []);
T::same('', $plain['count_join'], '⛔ بدونِ جست‌وجو، کوئریِ شمارش JOIN ندارد');
T::ok($search['count_join'] !== '', 'با جست‌وجو، JOIN اضافه می‌شود');

T::group('⛔ صافیِ حساب: مالکیت همین‌جا سنجیده می‌شود');

$f = buildTransactionFilter($uidA, ['wallet' => '999999'], [['id' => 5, 'name' => 'مالِ من']]);
T::same(0, $f['wallet_id'], '⛔ حسابی که مالِ کاربر نیست نادیده گرفته می‌شود');
T::ok(!str_contains($f['where'], 'wallet_id'), 'و شرطش هم به کوئری نمی‌رود');

$f2 = buildTransactionFilter($uidA, ['wallet' => '5'], [['id' => 5, 'name' => 'مالِ من']]);
T::same(5, $f2['wallet_id'], 'حسابِ خودی پذیرفته می‌شود');
T::ok(str_contains($f2['where'], 'wallet_id'), 'و شرطش به کوئری می‌رود');

T::group('سقفِ طولِ عبارت');

T::same(TX_SEARCH_MAX, mb_strlen(txSearchTerm(str_repeat('ا', 500)), 'UTF-8'),
        'عبارتِ بلند بریده می‌شود');
T::same('', txSearchTerm('   '), 'فاصله‌ی خالی یعنی بدونِ جست‌وجو');

// ---------------------------------------------------------------
// خروجیِ CSV — از **خودِ اندپوینت**، با HTTP و نشستِ واقعی
//
// ⛔ نسخه‌ی اولِ این بخش منطقِ CSV را در خودِ تست **کپی** می‌کرد و
//    خروجیِ کپی را می‌سنجید — یعنی تستی که خودش را می‌سنجد و با
//    خرابیِ اندپوینت هم سبز می‌ماند. همان «آزمونِ همیشه‌سبز».
// ---------------------------------------------------------------
$PASS = 'Search!Test#' . bin2hex(random_bytes(4));
$pdo->prepare('UPDATE users SET password_hash = :p WHERE id = :i')
    ->execute(['p' => password_hash($PASS, PASSWORD_DEFAULT), 'i' => $uidA]);

$root = realpath(__DIR__ . '/..');
$port = 0;
for ($p = 8941; $p <= 8969; $p++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
    if ($sock) { fclose($sock); $port = $p; break; }
}

if (!$port || !function_exists('curl_init')) {
    T::group('خروجیِ CSV');
    T::skip('خروجیِ CSV', $port ? 'افزونه‌ی curl نیست' : 'پورت آزاد پیدا نشد');
    $dropUser($U1); $dropUser($U2);
    exit(T::report());
}

$log = tempnam(sys_get_temp_dir(), 'txcsv');
$pid = (int)trim((string)shell_exec(sprintf(
    'php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
    $port, escapeshellarg($root), escapeshellarg($log))));

$up = false;
for ($i = 0; $i < 40; $i++) {
    usleep(150000);
    $s = @fsockopen('127.0.0.1', $port, $a, $b, 0.3);
    if ($s) { fclose($s); $up = true; break; }
}

$stop = function () use ($pid, $dropUser, $U1, $U2) {
    if ($pid > 0) { @shell_exec('kill ' . $pid . ' 2>/dev/null'); }
    $dropUser($U1);
    $dropUser($U2);
};

if (!$up) {
    T::group('خروجیِ CSV');
    T::ok(false, 'سرور آزمایشی بالا آمد', substr((string)@file_get_contents($log), 0, 300));
    $stop();
    exit(T::report());
}

$jar = tempnam(sys_get_temp_dir(), 'txcsvjar');
$req = function (string $path, ?array $post = null, bool $ajax = true) use ($port, $jar): array {
    $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 25,
    ];
    if ($ajax) { $opts[CURLOPT_HTTPHEADER] = ['X-Requested-With: XMLHttpRequest']; }
    curl_setopt_array($ch, $opts);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);
    return [$code, $body];
};

T::group('⛔ اندپوینت بدونِ ورود داده نمی‌دهد');

[$anonCode, $anonBody] = $req('api/export_transactions.php', ['x' => '1']);
T::same(401, $anonCode, '⛔ بدونِ ورود ۴۰۱ می‌دهد');
T::ok(!str_contains($anonBody, 'سنگک'), '⛔ و هیچ ردیفی هم لو نمی‌رود');

// ورود
[, $loginHtml] = $req('login.php', null, false);
preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $loginHtml, $m);
[$lc] = $req('login.php', [
    'csrf_token' => $m[1] ?? '', 'username' => $U1, 'password' => $PASS,
], false);

T::group('خروجیِ CSV');
T::ok($lc === 302 || $lc === 303, 'کاربرِ آزمایشی وارد شد', "کد {$lc}");
if (!($lc === 302 || $lc === 303)) { $stop(); exit(T::report()); }

$freshToken = function () use ($req): string {
    [, $html] = $req('transactions.php', null, false);
    preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $mm);
    return $mm[1] ?? '';
};

// ⛔ بدونِ CSRF رد می‌شود — لینکِ GET را می‌شد در `<img src>` جاسازی کرد
[$noCsrf] = $req('api/export_transactions.php', ['period' => 'all']);
T::ok($noCsrf >= 400, '⛔ بدونِ توکنِ CSRF رد می‌شود', "کد {$noCsrf}");

// GET هم رد می‌شود
[$getCode] = $req('api/export_transactions.php');
T::ok($getCode >= 400, '⛔ GET پذیرفته نمی‌شود', "کد {$getCode}");

// ---------- خروجیِ کامل ----------
[$csvCode, $csv] = $req('api/export_transactions.php', [
    'csrf_token' => $freshToken(), 'period' => 'all', 'type' => 'all',
], false);

T::same(200, $csvCode, 'خروجی گرفته شد');
T::ok(str_starts_with($csv, "\xEF\xBB\xBF"),
      '⛔ فایل با BOM شروع می‌شود',
      'بدونِ آن اکسلِ ویندوز کلِ متنِ فارسی را درهم نشان می‌دهد');

$body  = substr($csv, 3);
$lines = array_values(array_filter(explode("\n", str_replace("\r", '', $body)), fn($l) => trim($l) !== ''));

T::ok(str_contains($lines[0] ?? '', 'مبلغ'), 'سرستون‌ها نوشته می‌شوند');
T::same(8, count($lines), 'یک سرستون + هفت ردیفِ کاربر');

T::ok(str_contains($body, ',777000'),
      '⛔ مبلغ عددِ خامِ لاتین است، نه `formatMoney()`',
      'با جداکننده و ارقامِ فارسی اکسل ستون را متن می‌گیرد و جمع نمی‌زند');
T::ok(!str_contains($body, '۷۷۷'), 'ارقامِ فارسی در فایل نیست');
T::ok(str_contains($body, '1404-01-01,2025-03-21'),
      '⛔ تاریخِ شمسی با ارقامِ لاتین، کنارِ میلادی');
T::ok(str_contains($body, 'قبض برق'), 'نامِ دسته‌بندی در فایل هست');
T::ok(str_contains($body, 'بابت تعمیر پنکه'), 'یادداشت در فایل هست');

// ---------- ⛔ فایل همان چیزی است که روی صفحه دیده شد ----------
[, $csvSearch] = $req('api/export_transactions.php', [
    'csrf_token' => $freshToken(), 'period' => 'all', 'type' => 'all',
    'search' => 'پنکه',
], false);
$sBody  = substr($csvSearch, 3);
$sLines = array_values(array_filter(explode("\n", str_replace("\r", '', $sBody)), fn($l) => trim($l) !== ''));

T::same(2, count($sLines), '⛔ خروجی هم جست‌وجو را اعمال می‌کند (سرستون + یک ردیف)');
T::ok(str_contains($sBody, 'تعمیر پنکه'), 'و همان ردیفِ درست است');
T::ok(!str_contains($sBody, 'سنگک'), 'ردیفِ بی‌ربط در فایل نیست');

[, $csvType] = $req('api/export_transactions.php', [
    'csrf_token' => $freshToken(), 'period' => 'all', 'type' => 'income',
], false);
$tLines = array_values(array_filter(explode("\n", str_replace("\r", '', substr($csvType, 3))), fn($l) => trim($l) !== ''));
T::same(2, count($tLines), '⛔ صافیِ نوع هم در خروجی اعمال می‌شود');

// ---------- ⛔ خروجی از مرزِ کاربر رد نمی‌شود ----------
T::ok(!str_contains($body, (string)$uidB), 'شناسه‌ی کاربرِ دیگر در فایل نیست');
$countB = $pdo->prepare('SELECT COUNT(*) FROM transactions WHERE user_id = :u');
$countB->execute(['u' => $uidB]);
T::same(1, (int)$countB->fetchColumn(), 'کاربر B هنوز ردیفِ خودش را دارد');
T::same(7, count($lines) - 1, '⛔ و خروجیِ A فقط ۷ ردیفِ خودش است، نه ۸');

$stop();
exit(T::report());
