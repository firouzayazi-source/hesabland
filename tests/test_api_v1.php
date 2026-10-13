<?php
/**
 * تست رفتاری api/v1 — واقعاً درخواست HTTP می‌فرستد.
 *
 * مهم‌ترین چیزی که می‌سنجد **جداسازی کاربران** است، و برای همین قربانی
 * را خودش می‌سازد. تکیه بر داده‌ی موجود یعنی روی یک دیتابیس خالی تست
 * بی‌سروصدا رد می‌شود و هیچ چیزی را نگه نمی‌دارد — همان درسی که در
 * test_api_auth گرفته شد.
 *
 * دومین چیز، **قرارداد نسخه** است: توکن باید لازم باشد، پاکت پاسخ باید
 * شکل ثابت داشته باشد، تاریخ باید هم میلادی و هم شمسی بیاید، و مبلغ
 * باید عدد صحیح باشد. اپی که روی گوشی نصب شده روی همین‌ها حساب می‌کند و
 * نمی‌شود مجبورش به به‌روزرسانی کرد.
 */

// ---------- نگهبان: فقط خط فرمان ----------
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

$root = realpath(__DIR__ . '/..');

T::group('آماده‌سازی');

if (!file_exists($root . '/config/config.php')) {
    T::blocked('تست api/v1', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once $root . '/config/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::blocked('تست api/v1', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

if (!tableExists('api_tokens')) {
    T::skip('تست api/v1', 'جدول api_tokens نیست — migration_api_tokens.sql اجرا نشده');
    exit(T::report());
}

// ---------- سرور آزمایشی ----------
$port = 0;
for ($p = 8941; $p <= 8969; $p++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$p", $errno, $errstr);
    if ($sock) { fclose($sock); $port = $p; break; }
}
if (!$port) { T::skip('تست api/v1', 'پورت آزاد پیدا نشد'); exit(T::report()); }

$log = tempnam(sys_get_temp_dir(), 'v1test');
$pid = (int)trim((string)shell_exec(sprintf(
    'php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
    $port, escapeshellarg($root), escapeshellarg($log)
)));

$up = false;
for ($i = 0; $i < 40; $i++) {
    usleep(150000);
    $s = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.3);
    if ($s) { fclose($s); $up = true; break; }
}
if (!$up) {
    T::ok(false, 'سرور آزمایشی بالا آمد', substr((string)@file_get_contents($log), 0, 300));
    if ($pid) { @exec("kill $pid 2>/dev/null"); }
    exit(T::report());
}
T::pass("سرور آزمایشی روی پورت $port بالا آمد");

// آدرس عمداً index.php دارد: سرور توسعه‌ی PHP قاعده‌ی rewrite ندارد و
// اپ واقعی هم باید بدون آن کار کند.
$base = "http://127.0.0.1:$port/api/v1/index.php";

/** یک درخواست به API. $token اگر باشد به شکل Bearer می‌رود. */
$api = function (string $method, string $path, ?array $body = null, ?string $token = null) use ($base): array {
    $headers = ['Accept: application/json'];
    if ($token !== null) { $headers[] = 'Authorization: Bearer ' . $token; }
    if ($body !== null)  { $headers[] = 'Content-Type: application/json'; }

    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_POSTFIELDS     => $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $raw  = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);

    return [$code, json_decode($raw, true), $raw];
};

// ---------- دو کاربر آزمایشی ----------
$UA = '__test_v1_alice';
$UB = '__test_v1_bob';
$PASS = 'ApiTest!2026';

$cleanup = function () use ($pdo, $UA, $UB) {
    foreach ([$UA, $UB] as $u) {
        $id = $pdo->prepare('SELECT id FROM users WHERE username = :u');
        $id->execute(['u' => $u]);
        $uid = (int)$id->fetchColumn();
        if ($uid) {
            $pdo->prepare('DELETE FROM transactions WHERE user_id = :u')->execute(['u' => $uid]);
            $pdo->prepare('DELETE FROM api_tokens   WHERE user_id = :u')->execute(['u' => $uid]);
            $pdo->prepare('DELETE FROM users WHERE id = :u')->execute(['u' => $uid]);
        }
    }
};
$cleanup();

$mk = $pdo->prepare(
    'INSERT INTO users (username, password_hash, full_name, role, is_active)
     VALUES (:u, :h, :n, "user", 1)'
);
foreach ([$UA => 'آلیس', $UB => 'باب'] as $u => $name) {
    $mk->execute(['u' => $u, 'h' => password_hash($PASS, PASSWORD_DEFAULT), 'n' => $name]);
}
$aliceId = (int)$pdo->query("SELECT id FROM users WHERE username = '$UA'")->fetchColumn();
$bobId   = (int)$pdo->query("SELECT id FROM users WHERE username = '$UB'")->fetchColumn();
T::ok($aliceId > 0 && $bobId > 0, 'دو کاربر آزمایشی ساخته شدند');

$stop = function () use ($pid, $cleanup) {
    $cleanup();
    if ($pid) { @exec("kill $pid 2>/dev/null"); }
};

// ---------------------------------------------------------------
T::group('بدون توکن هیچ داده‌ای بیرون نمی‌رود');

$guarded = [
    ['GET',    '/me'],
    ['GET',    '/dashboard'],
    ['GET',    '/transactions'],
    ['POST',   '/transactions'],
    ['GET',    '/transactions/1'],
    ['PATCH',  '/transactions/1'],
    ['DELETE', '/transactions/1'],
    ['GET',    '/wallets'],
    ['GET',    '/categories'],
    ['POST',   '/auth/logout'],
];
$bad = [];
foreach ($guarded as [$m, $p]) {
    [$code, $json] = $api($m, $p);
    if ($code !== 401) { $bad[] = "$m $p → کد $code (انتظار ۴۰۱)"; continue; }
    if (($json['ok'] ?? null) !== false) { $bad[] = "$m $p → پاکت خطا شکل درست ندارد"; }
}
T::bulk(count($guarded), $bad, 'هر اندپوینت بدون توکن ۴۰۱ می‌دهد');

[$code, $json] = $api('GET', '/ping');
T::ok($code === 200 && ($json['ok'] ?? false), 'ping بدون توکن کار می‌کند');

[$code, $json] = $api('GET', '/transactions', null, 'aaaa.bbbb');
T::ok($code === 401, 'توکن ساختگی رد می‌شود');

// ---------------------------------------------------------------
T::group('ورود و توکن');

[$code, $json] = $api('POST', '/auth/login', ['username' => $UA, 'password' => 'رمزغلط']);
T::ok($code === 401, 'رمز غلط ۴۰۱ می‌گیرد');
T::ok(($json['error']['code'] ?? '') === 'invalid_credentials', 'کد خطای ماشین‌خوان دارد');

[$code, $json] = $api('POST', '/auth/login',
    ['username' => $UA, 'password' => $PASS, 'device_name' => 'Pixel', 'platform' => 'android']);
T::ok($code === 200 && !empty($json['data']['token']), 'ورود درست توکن می‌دهد');
$tokenA = $json['data']['token'] ?? '';
T::ok((bool)preg_match('/^[a-f0-9]{24}\.[a-f0-9]{64}$/', $tokenA), 'شکل توکن selector.validator است');

// توکن خام نباید در دیتابیس باشد
$stored = $pdo->query("SELECT token_hash FROM api_tokens WHERE user_id = $aliceId")->fetchColumn();
T::ok($stored && !str_contains($tokenA, (string)$stored), 'توکن خام در دیتابیس ذخیره نشده');

[$code, $json] = $api('POST', '/auth/login', ['username' => $UB, 'password' => $PASS]);
$tokenB = $json['data']['token'] ?? '';
T::ok($code === 200 && $tokenB !== '', 'کاربر دوم هم وارد شد');

[$code, $json] = $api('GET', '/me', null, $tokenA);
T::ok($code === 200 && (int)($json['data']['id'] ?? 0) === $aliceId, 'me کاربرِ همان توکن را می‌دهد');

// ---------------------------------------------------------------
T::group('مسیر واقعی پول');

[$code, $json] = $api('POST', '/transactions', [
    'type' => 'expense', 'amount' => '۱۲٬۵۰۰', 'title' => 'آزمون',
    'transaction_date' => date('Y-m-d'), 'note' => 'یادداشت',
], $tokenA);
T::ok($code === 201, 'ثبت تراکنش ۲۰۱ می‌دهد', "کد: $code");
$txId = (int)($json['data']['id'] ?? 0);
T::ok($txId > 0, 'شناسه‌ی تراکنش برگشت');

[$code, $json] = $api('GET', "/transactions/$txId", null, $tokenA);
$row = $json['data'] ?? [];
T::ok($code === 200, 'خواندن تراکنش کار می‌کند');
T::same(12500, $row['amount'] ?? null, 'ارقام فارسی و جداکننده درست خوانده شدند');
T::ok(is_int($row['amount'] ?? null), 'مبلغ عدد صحیح است، نه رشته یا اعشاری');

// تاریخ باید هر دو شکل را داشته باشد — وگرنه اپ مجبور می‌شود خودش تبدیل
// کند و می‌شود پیاده‌سازی سومِ تقویم شمسی، بیرون از پوشش test_jalali_parity
T::ok(isset($row['date']['iso'], $row['date']['jalali']), 'تاریخ هم میلادی و هم شمسی می‌آید');
T::same(date('Y-m-d'), $row['date']['iso'] ?? null, 'تاریخ میلادی درست است');

[$code, $json] = $api('PATCH', "/transactions/$txId", [
    'type' => 'income', 'amount' => 4000, 'title' => 'ویرایش‌شده',
    'transaction_date' => date('Y-m-d'),
], $tokenA);
T::ok($code === 200, 'ویرایش کار می‌کند');

[$code, $json] = $api('GET', "/transactions/$txId", null, $tokenA);
T::same('income', $json['data']['type'] ?? null, 'ویرایش واقعاً نوشته شد');
T::same(4000, $json['data']['amount'] ?? null, 'مبلغ ویرایش‌شده درست است');

[$code, $json] = $api('GET', '/transactions?per_page=1', null, $tokenA);
T::ok(($json['data']['page']['per_page'] ?? 0) === 1, 'صفحه‌بندی اعمال می‌شود');
T::ok(count($json['data']['items'] ?? []) <= 1, 'تعداد ردیف از per_page بیشتر نیست');

// ---------------------------------------------------------------
T::group('جداسازی کاربران — مهم‌ترین بخش');

[$code, $json] = $api('GET', "/transactions/$txId", null, $tokenB);
T::ok($code === 404, 'باب تراکنش آلیس را نمی‌بیند', "کد: $code");

[$code, $json] = $api('PATCH', "/transactions/$txId", [
    'type' => 'expense', 'amount' => 1, 'title' => 'دستکاری',
    'transaction_date' => date('Y-m-d'),
], $tokenB);
T::ok(in_array($code, [403, 404], true), 'باب تراکنش آلیس را ویرایش نمی‌کند', "کد: $code");

[$code, $json] = $api('DELETE', "/transactions/$txId", null, $tokenB);
T::ok(in_array($code, [403, 404], true), 'باب تراکنش آلیس را حذف نمی‌کند', "کد: $code");

// و واقعاً دست نخورده مانده باشد
[$code, $json] = $api('GET', "/transactions/$txId", null, $tokenA);
T::same('ویرایش‌شده', $json['data']['title'] ?? null, 'تراکنش آلیس دست‌نخورده ماند');

[$code, $json] = $api('GET', '/transactions', null, $tokenB);
$ids = array_column($json['data']['items'] ?? [], 'id');
T::ok(!in_array($txId, $ids, true), 'تراکنش آلیس در فهرست باب نیست');

// ---------------------------------------------------------------
T::group('خروج توکن را باطل می‌کند');

[$code, $json] = $api('POST', '/auth/logout', null, $tokenB);
T::ok($code === 200, 'خروج انجام شد');

[$code, $json] = $api('GET', '/me', null, $tokenB);
T::ok($code === 401, 'توکنِ باطل‌شده دیگر کار نمی‌کند');

[$code, $json] = $api('GET', '/me', null, $tokenA);
T::ok($code === 200, 'خروجِ یک دستگاه به دستگاه دیگر کاری ندارد');

// ---------------------------------------------------------------
T::group('قرارداد و خطاها');

[$code, $json] = $api('GET', '/چنین-چیزی-نیست', null, $tokenA);
T::ok($code === 404 && ($json['error']['code'] ?? '') === 'not_found', 'آدرس ناشناخته ۴۰۴ می‌دهد');

[$code, $json] = $api('DELETE', '/wallets', null, $tokenA);
T::ok($code === 405, 'متد نادرست ۴۰۵ می‌دهد', "کد: $code");

[$code, $json] = $api('POST', '/transactions', [
    'type' => 'expense', 'amount' => 0, 'title' => '', 'transaction_date' => 'نامعتبر',
], $tokenA);
T::ok($code === 422 && ($json['error']['code'] ?? '') === 'validation', 'ورودی نامعتبر ۴۲۲ می‌دهد');

[$code, $json] = $api('GET', '/dashboard', null, $tokenA);
$d = $json['data'] ?? [];
T::ok(isset($d['income'], $d['expense'], $d['net'], $d['total_balance']), 'داشبورد کلیدهای لازم را دارد');
T::ok(is_int($d['income'] ?? null) && is_int($d['total_balance'] ?? null), 'اعداد داشبورد صحیح‌اند');

[$code, $json] = $api('GET', '/wallets', null, $tokenA);
T::ok($code === 200, 'حساب‌ها خوانده می‌شوند');
$w = $json['data']['items'][0] ?? null;
if ($w) {
    // شماره‌ی کارت و شبا حساس‌اند و نباید در این اندپوینت بیایند
    T::ok(!isset($w['card_number'], $w['iban']), 'شماره‌ی کارت و شبا در فهرست حساب‌ها نمی‌آیند');
}

[$code, $json] = $api('GET', '/categories', null, $tokenA);
T::ok($code === 200 && isset($json['data']['items']), 'دسته‌بندی‌ها خوانده می‌شوند');

$stop();
exit(T::report());
